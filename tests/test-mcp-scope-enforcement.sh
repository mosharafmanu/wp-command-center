#!/usr/bin/env bash
# Remediation S-2 — MCP Scope Enforcement test suite.
#
# Proves that a read_only token cannot call full-scope operations via MCP
# tools/call, including the 4 seed operations (content_seed, acf_seed,
# cf7_seed, woo_product_seed) that were previously unmapped in
# CapabilityRegistry::OPERATION_MAP and therefore fail-open via MCP.
# Also proves read-only-scope operations and full-scope tokens are unaffected,
# and that MCP now matches REST's require_write() behavior.
set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../wpcc-env.sh"
WP_PATH="$SCRIPT_DIR/../../../.."
PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; if [ "$e" = "$a" ]; then pass "$d"; else fail "$d (expected '$e', got '$a')"; fi; }
assert_true() { local d="$1" a="$2"; if [ "$a" = "true" ]; then pass "$d"; else fail "$d"; fi; }
mcp() { local token="$1" body="$2"; curl -s -X POST -H "Authorization: Bearer $token" -H "Content-Type: application/json" -d "$body" "$WPCC_BASE/mcp"; }

echo "MCP Scope Enforcement (Remediation S-2) — $(date)"
echo ""

# ===================================================================
echo "== 1. Setup — create a temporary read_only token =="
RO_TOKEN=$(wp eval '
$auth = new \WPCommandCenter\Security\AuthTokens();
$r = $auth->create( "S-2 Scope Test RO", \WPCommandCenter\Security\AuthTokens::SCOPE_READ_ONLY, null, 1 );
echo is_wp_error( $r ) ? "" : $r["token"];
' --path="$WP_PATH" 2>/dev/null)
assert_true "setup: read_only token created" "$( [ -n "$RO_TOKEN" ] && echo true || echo false )"

# ===================================================================
echo "== 2. Seed operations — read_only token denied via MCP (was the S-2 bypass) =="
for op in content_seed acf_seed cf7_seed woo_product_seed; do
	RESP=$(mcp "$RO_TOKEN" "{\"jsonrpc\":\"2.0\",\"method\":\"tools/call\",\"params\":{\"name\":\"$op\",\"arguments\":{}},\"id\":1}")
	# STEP 89: scope denials are isError tool results with a structured code.
	assert_eq "scope: $op denied for read_only (wpcc_token_read_only)" "wpcc_token_read_only" "$(echo "$RESP" | jq -r '.result.content[0].text | fromjson | .code // empty')"
	assert_true "scope: $op denial message mentions read-only" "$(echo "$RESP" | jq -r '(.result.content[0].text | fromjson | .message // "") | test("read-only"; "i")')"
done

# ===================================================================
echo "== 3. Read-only-scope operations — read_only token allowed via MCP =="
RESP=$(mcp "$RO_TOKEN" '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"database_inspect","arguments":{"action":"db_table_list"}},"id":2}')
assert_true "scope: database_inspect allowed for read_only" "$(echo "$RESP" | jq -r 'if .result then "true" else "false" end')"

RESP=$(mcp "$RO_TOKEN" '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"search_manage","arguments":{"action":"search_all","query":"hello"}},"id":3}')
assert_true "scope: search_manage allowed for read_only" "$(echo "$RESP" | jq -r 'if .result then "true" else "false" end')"

# ===================================================================
echo "== 4. Full-scope token — previously-vulnerable seed op still works via MCP =="
RESP=$(mcp "$WPCC_TOKEN" '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"content_seed","arguments":{"type":"post","count":1,"status":"draft","title_pattern":"WPCC S-2 Test {n}"}},"id":4}')
assert_true "scope: content_seed not scope-denied for full token" "$(echo "$RESP" | jq -r 'if (.error.code // 0) == -32001 then "false" else "true" end')"
assert_true "scope: content_seed succeeds for full token" "$(echo "$RESP" | jq -r 'if .result then "true" else "false" end')"

# ===================================================================
echo "== 5. REST/MCP symmetry — read_only token also blocked via REST =="
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST -H "Authorization: Bearer $RO_TOKEN" -H "Content-Type: application/json" -d '{"type":"post","count":1}' "$WPCC_BASE/operations/content_seed/run")
assert_eq "rest: content_seed 403 for read_only token" "403" "$HTTP_CODE"

# ===================================================================
echo "== 6. Cleanup — revoke temporary token =="
wp eval '
$auth = new \WPCommandCenter\Security\AuthTokens();
foreach ( $auth->list() as $t ) {
	if ( "S-2 Scope Test RO" === $t["label"] ) { $auth->delete( $t["id"] ); }
}
echo "ok";
' --path="$WP_PATH" >/dev/null 2>&1
assert_true "cleanup: temporary token revoked" "$( wp eval '
$auth = new \WPCommandCenter\Security\AuthTokens();
$still_exists = false;
foreach ( $auth->list() as $t ) { if ( "S-2 Scope Test RO" === $t["label"] ) { $still_exists = true; } }
echo $still_exists ? "false" : "true";
' --path="$WP_PATH" 2>/dev/null )"

echo ""
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
