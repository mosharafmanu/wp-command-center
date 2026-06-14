#!/usr/bin/env bash
#
# F2.2 — MCP/PHP execution-time mismatch (silent partial-write risk).
#
# Acceptance report finding: the MCP client abandons a request at ~240s while
# PHP max_execution_time is far higher (480s on prod). With no server-side
# budget, an op running in the 240–480s window keeps executing — and writing —
# after the client has already reported failure (silent partial write), and
# shows as "stuck started" forever (no terminal event; see F7.3).
#
# Fix under test: McpServerRuntime caps synchronous MCP execution below the
# client timeout (time_budget / apply_time_budget), emits a structured
# wpcc_operation_timeout, and records a terminal audit event. apply_time_budget
# is wired into the MCP transport (McpRestApi::handle_mcp).
#
# This suite verifies the gap is closed deterministically (the exact 4–8 min
# hang is environmental and not synthesizable here): budget computation, that
# the request limit is actually lowered (and never raised), the structured
# timeout shape, error-catalog discoverability, and that the transport still
# works end-to-end with the budget wired in.
#
# Requires: curl, jq, wp, wpcc-env.sh.
# Usage: bash tests/test-mcp-timeout-budget-f22.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
# shellcheck source=/dev/null
source "$PLUGIN_DIR/wpcc-env.sh"
WP_PATH="$SCRIPT_DIR/../../../.."

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }

RT='\WPCommandCenter\Mcp\McpServerRuntime'
ev() { wp eval "$1" --path="$WP_PATH" 2>/dev/null; }
mcp() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/mcp"; }

echo "== 1. Default budget is below the MCP client timeout =="
BUDGET=$(ev "echo $RT::time_budget();")
assert_eq "time_budget() defaults to 200" "200" "$BUDGET"
CLIENT=$(ev "echo $RT::MCP_CLIENT_TIMEOUT;")
assert_eq "client-timeout reference is 240" "240" "$CLIENT"
[ "$BUDGET" -lt "$CLIENT" ] && pass "budget < client timeout (no silent-write window)" || fail "budget ($BUDGET) >= client timeout ($CLIENT)"

echo "== 2. Budget is clamped strictly below the client timeout =="
CLAMP=$(ev "add_filter('wpcc_mcp_time_budget', fn()=>999); echo $RT::time_budget();")
assert_eq "filter above ceiling clamps to client_timeout-1" "239" "$CLAMP"

echo "== 3. Capping can be disabled (escape hatch) =="
OFF=$(ev "add_filter('wpcc_mcp_time_budget', fn()=>0); echo $RT::time_budget();")
assert_eq "filter 0 disables capping" "0" "$OFF"

echo "== 4. apply_time_budget LOWERS an unlimited/high PHP limit (closes the window) =="
FROM_UNLIMITED=$(ev "@ini_set('max_execution_time','0'); $RT::apply_time_budget(1); echo ini_get('max_execution_time');")
assert_eq "unlimited (0) -> capped to 200" "200" "$FROM_UNLIMITED"
FROM_480=$(ev "@ini_set('max_execution_time','480'); $RT::apply_time_budget(1); echo ini_get('max_execution_time');")
assert_eq "480 (prod) -> lowered to 200" "200" "$FROM_480"

echo "== 5. apply_time_budget NEVER raises a host's lower limit =="
FROM_30=$(ev "@ini_set('max_execution_time','30'); $RT::apply_time_budget(1); echo ini_get('max_execution_time');")
assert_eq "30 (shared host) left untouched" "30" "$FROM_30"

echo "== 6. apply_time_budget honors the disable filter (no capping) =="
DISABLED=$(ev "@ini_set('max_execution_time','480'); add_filter('wpcc_mcp_time_budget', fn()=>0); $RT::apply_time_budget(1); echo ini_get('max_execution_time');")
assert_eq "disabled filter leaves PHP limit at 480" "480" "$DISABLED"

echo "== 7. Structured timeout response is AI-readable (isError tool shape) =="
ISERR=$(ev "\$r=$RT::timeout_response(7); echo !empty(\$r['result']['isError']) ? '1':'0';")
assert_eq "result.isError true" "1" "$ISERR"
TCODE=$(ev "\$r=$RT::timeout_response(7); \$t=json_decode(\$r['result']['content'][0]['text'],true); echo \$t['code'];")
assert_eq "tool content code is wpcc_operation_timeout" "wpcc_operation_timeout" "$TCODE"
TID=$(ev "\$r=$RT::timeout_response(7); echo \$r['id'];")
assert_eq "echoes the JSON-RPC request id" "7" "$TID"
TVER=$(ev "\$r=$RT::timeout_response(7); echo \$r['jsonrpc'];")
assert_eq "jsonrpc 2.0 envelope" "2.0" "$TVER"

echo "== 8. Shutdown handler no-ops when the request did not time out =="
NOOP=$(ev "$RT::handle_timeout_shutdown(1); echo 'ok';")
assert_eq "handle_timeout_shutdown safe with no timeout fatal" "ok" "$NOOP"

echo "== 9. wpcc_operation_timeout is discoverable in the manifest error catalog =="
CAT=$(curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/agent/manifest" | jq -r '.error_catalog.wpcc_operation_timeout // empty')
[ -n "$CAT" ] && pass "manifest error_catalog documents wpcc_operation_timeout" || fail "wpcc_operation_timeout missing from error_catalog"

echo "== 10. Transport still works end-to-end with the budget wired in =="
INIT=$(mcp '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' | jq -r '.result.protocolVersion // empty')
[ -n "$INIT" ] && pass "initialize returns protocolVersion ($INIT)" || fail "initialize broken after budget wiring"
NTOOLS=$(mcp '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}' | jq -r '.result.tools | length')
[ "${NTOOLS:-0}" -gt 0 ] 2>/dev/null && pass "tools/list returns $NTOOLS tools" || fail "tools/list broken after budget wiring"
CALLOK=$(mcp '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"system_info","arguments":{}}}' | jq -r 'if .result and (.result.isError // false | not) then "ok" else "err" end')
assert_eq "tools/call system_info succeeds (no isError)" "ok" "$CALLOK"

echo
echo "===================================="
echo "  F2.2 MCP timeout budget: $PASS passed, $FAIL failed"
echo "===================================="
[ "$FAIL" -eq 0 ]
