#!/usr/bin/env bash
# Step 80 — Security Mode test suite.
#
# Verifies SecurityModeManager's three modes (developer / client / enterprise)
# gate operations correctly, that human-approver enforcement prevents AI
# self-approval in Client/Enterprise modes, and that diagnostic operations
# (database_inspect, search_manage, approval_manage) are never gated in any mode.
#
# Scenarios:
#   A. Developer Mode — all ops execute immediately, no pending_approval.
#   B. Client Mode — diagnostic ops free, medium/high/critical auto-create pending_approval.
#   C. Client Mode — token actor cannot request_approve its own requests.
#   D. Enterprise Mode — low-risk ops gated; diagnostic ops still free.
#   E. Action-level risk: plugin_list (diagnostic) executes in Client Mode.
#   F. Action-level risk: plugin_delete (critical) gated in Client Mode.
#   G. SecurityModeManager::current() returns the correct mode via wp eval.
#   Cleanup: restore original mode, cancel any created approval requests.
set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../wpcc-env.sh"
WP_PATH="$SCRIPT_DIR/../../../.."
PASS=0; FAIL=0

pass()       { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail()       { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq()  { local d="$1" e="$2" a="$3"; if [ "$e" = "$a" ]; then pass "$d"; else fail "$d (expected '$e', got '$a')"; fi; }
assert_ne()  { local d="$1" e="$2" a="$3"; if [ "$e" != "$a" ]; then pass "$d"; else fail "$d (expected not '$e', got '$a')"; fi; }
assert_true() { local d="$1" a="$2"; if [ "$a" = "true" ]; then pass "$d"; else fail "$d (expected true, got '$a')"; fi; }

mcp() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/mcp"; }

set_mode() {
    wp eval "update_option('wpcc_security_mode', '$1'); echo 'ok';" --path="$WP_PATH" >/dev/null 2>&1
}
get_mode() {
    wp eval "echo get_option('wpcc_security_mode', '(unset)');'" --path="$WP_PATH" 2>/dev/null
}
cancel_request() {
    local rid="$1"
    [ -z "$rid" ] || [ "$rid" = "null" ] && return
    BODY=$(jq -n --arg rid "$rid" '{jsonrpc:"2.0",method:"tools/call",params:{name:"approval_manage",arguments:{action:"request_cancel",request_id:$rid}},id:99}')
    mcp "$BODY" >/dev/null
}

echo "Security Modes (Step 80) — $(date)"
echo ""

# Save original mode and clear any old enforce flag interference.
ORIGINAL_MODE=$(wp eval "echo get_option('wpcc_security_mode', 'developer');" --path="$WP_PATH" 2>/dev/null)
ORIGINAL_MODE=${ORIGINAL_MODE:-developer}

# ===================================================================
echo "== A. Developer Mode — ops execute immediately =="
set_mode "developer"

CURRENT=$(wp eval "echo \WPCommandCenter\Operations\SecurityModeManager::current();" --path="$WP_PATH" 2>/dev/null)
assert_eq "SecurityModeManager::current() == developer" "developer" "$CURRENT"

# theme_manage / theme_list should succeed (no gating, no pending_approval)
RESP=$(mcp '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"theme_manage","arguments":{"action":"theme_list"}},"id":10}')
STATUS=$(echo "$RESP" | jq -r '.result.content[0].text // empty' | jq -r '.status // "none"')
ERR=$(echo "$RESP" | jq -r '.error.code // "none"')
assert_ne "Developer: theme_manage not a -32000 error" "-32000" "$ERR"
assert_ne "Developer: theme_manage result is not pending_approval" "pending_approval" "$STATUS"

# plugin_manage / plugin_list (diagnostic action_risk) also runs freely
RESP=$(mcp '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"plugin_manage","arguments":{"action":"plugin_list"}},"id":11}')
ERR=$(echo "$RESP" | jq -r '.error.code // "none"')
assert_ne "Developer: plugin_list not a -32000 error" "-32000" "$ERR"

# ===================================================================
echo "== B. Client Mode — diagnostic free, high-risk -> pending_approval =="
set_mode "client"

CURRENT=$(wp eval "echo \WPCommandCenter\Operations\SecurityModeManager::current();" --path="$WP_PATH" 2>/dev/null)
assert_eq "SecurityModeManager::current() == client" "client" "$CURRENT"

# database_inspect is diagnostic — never gated
RESP=$(mcp '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"database_inspect","arguments":{"action":"db_table_list"}},"id":20}')
ERR=$(echo "$RESP" | jq -r '.error.code // "none"')
assert_ne "Client: database_inspect not blocked" "-32000" "$ERR"

# search_manage is diagnostic — never gated
RESP=$(mcp '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"search_manage","arguments":{"action":"site_summary"}},"id":21}')
ERR=$(echo "$RESP" | jq -r '.error.code // "none"')
assert_ne "Client: search_manage not blocked" "-32000" "$ERR"

# theme_manage (high risk) is gated — expect pending_approval
# content[0].text contains $result['result'] directly (not the outer envelope)
RESP=$(mcp '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"theme_manage","arguments":{"action":"theme_install","slug":"twentytwentyone"}},"id":22}')
DATA=$(echo "$RESP" | jq -r '.result.content[0].text // empty')
STATUS=$(echo "$DATA" | jq -r '.status // empty')
assert_eq "Client: theme_install returns pending_approval" "pending_approval" "$STATUS"
assert_true "Client: pending_approval has no JSON-RPC error" "$(echo "$RESP" | jq -r '.error == null')"
THEME_REQUEST_ID=$(echo "$DATA" | jq -r '.request_id // empty')
assert_true "Client: pending_approval has request_id" "$( [ -n "$THEME_REQUEST_ID" ] && [ "$THEME_REQUEST_ID" != "null" ] && echo true || echo false )"

# The message field must contain the polling instruction
MSG=$(echo "$DATA" | jq -r '.message // empty')
assert_true "Client: message contains request_id" "$(echo "$MSG" | grep -q "$THEME_REQUEST_ID" && echo true || echo false)"

# ===================================================================
echo "== C. Client Mode — token actor blocked from request_approve =="
# attempt request_approve via token (AI actor) — must get wpcc_approval_requires_human
BODY=$(jq -n --arg rid "$THEME_REQUEST_ID" '{jsonrpc:"2.0",method:"tools/call",params:{name:"approval_manage",arguments:{action:"request_approve",request_id:$rid}},id:30}')
RESP=$(mcp "$BODY")
DATA=$(echo "$RESP" | jq -r '.result.content[0].text // empty')

# The handler returns WP_Error which McpServerRuntime converts to a JSON-RPC error
ERRC=$(echo "$RESP" | jq -r '.error.code // empty')
ERRM=$(echo "$RESP" | jq -r '.error.message // empty')

# Check for the WP_Error code in message (some MCP runtimes surface it there)
GOT_ERRC=$(echo "$DATA" | jq -r '.code // empty')
if [ "$ERRC" = "-32000" ] || [ "$GOT_ERRC" = "wpcc_approval_requires_human" ]; then
    pass "Client: token actor blocked from request_approve"
else
    fail "Client: token actor blocked from request_approve (error.code='$ERRC', data.code='$GOT_ERRC')"
fi

# Also check that request_reject is blocked
BODY=$(jq -n --arg rid "$THEME_REQUEST_ID" '{jsonrpc:"2.0",method:"tools/call",params:{name:"approval_manage",arguments:{action:"request_reject",request_id:$rid}},id:31}')
RESP=$(mcp "$BODY")
ERRC=$(echo "$RESP" | jq -r '.error.code // empty')
DATA=$(echo "$RESP" | jq -r '.result.content[0].text // empty')
GOT_ERRC=$(echo "$DATA" | jq -r '.code // empty')
if [ "$ERRC" = "-32000" ] || [ "$GOT_ERRC" = "wpcc_approval_requires_human" ]; then
    pass "Client: token actor blocked from request_reject"
else
    fail "Client: token actor blocked from request_reject (error.code='$ERRC', data.code='$GOT_ERRC')"
fi

# request_cancel (NOT human-only) should not be blocked — AI can cancel its own request
BODY=$(jq -n --arg rid "$THEME_REQUEST_ID" '{jsonrpc:"2.0",method:"tools/call",params:{name:"approval_manage",arguments:{action:"request_cancel",request_id:$rid}},id:32}')
RESP=$(mcp "$BODY")
DATA=$(echo "$RESP" | jq -r '.result.content[0].text // empty')
CANCELLED_STATUS=$(echo "$DATA" | jq -r '.status // empty')
assert_eq "Client: AI can cancel its own request (request_cancel not human-only)" "cancelled" "$CANCELLED_STATUS"
THEME_REQUEST_ID=""  # already cancelled

# ===================================================================
echo "== D. Enterprise Mode — low-risk gated; diagnostic free =="
set_mode "enterprise"

CURRENT=$(wp eval "echo \WPCommandCenter\Operations\SecurityModeManager::current();" --path="$WP_PATH" 2>/dev/null)
assert_eq "SecurityModeManager::current() == enterprise" "enterprise" "$CURRENT"

# database_inspect (diagnostic) must remain free even in Enterprise mode
RESP=$(mcp '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"database_inspect","arguments":{"action":"db_table_list"}},"id":40}')
ERR=$(echo "$RESP" | jq -r '.error.code // "none"')
assert_ne "Enterprise: database_inspect not blocked" "-32000" "$ERR"

# approval_manage (diagnostic) must remain free even in Enterprise mode
RESP=$(mcp '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"approval_manage","arguments":{"action":"request_list","limit":1}},"id":41}')
ERR=$(echo "$RESP" | jq -r '.error.code // "none"')
assert_ne "Enterprise: approval_manage not blocked" "-32000" "$ERR"

# snapshot_manage / snapshot_create is medium-risk — gated in Enterprise
RESP=$(mcp '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"snapshot_manage","arguments":{"action":"snapshot_create","label":"S80-enterprise-test"}},"id":42}')
DATA=$(echo "$RESP" | jq -r '.result.content[0].text // empty')
STATUS=$(echo "$DATA" | jq -r '.status // empty')
assert_eq "Enterprise: snapshot_create returns pending_approval" "pending_approval" "$STATUS"
SNAP_REQUEST_ID=$(echo "$DATA" | jq -r '.request_id // empty')
cancel_request "$SNAP_REQUEST_ID"

# ===================================================================
echo "== E. Action-level risk: plugin_list (diagnostic) free in Client Mode =="
set_mode "client"

RESP=$(mcp '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"plugin_manage","arguments":{"action":"plugin_list"}},"id":50}')
DATA=$(echo "$RESP" | jq -r '.result.content[0].text // empty')
STATUS=$(echo "$DATA" | jq -r '.status // "none"')
ERR=$(echo "$RESP" | jq -r '.error.code // "none"')
assert_ne "Client: plugin_list (diagnostic action) not blocked" "-32000" "$ERR"
assert_ne "Client: plugin_list not pending_approval" "pending_approval" "$STATUS"

# ===================================================================
echo "== F. Action-level risk: plugin_delete (critical) gated in Client Mode =="
# STEP 84: plugin_delete is destructive-gated. Supply confirmation so the request
# passes the confirmation guard and reaches the Client-mode approval gate.
RESP=$(mcp '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"plugin_manage","arguments":{"action":"plugin_delete","slug":"hello-dolly","confirm":true,"confirmation_phrase":"DELETE_PLUGIN","reason":"security mode gating test"}},"id":51}')
DATA=$(echo "$RESP" | jq -r '.result.content[0].text // empty')
STATUS=$(echo "$DATA" | jq -r '.status // empty')
assert_eq "Client: plugin_delete (critical) returns pending_approval" "pending_approval" "$STATUS"
DEL_REQUEST_ID=$(echo "$DATA" | jq -r '.request_id // empty')
cancel_request "$DEL_REQUEST_ID"

# risk_level in the pending_approval response must say 'critical'
RISK=$(echo "$DATA" | jq -r '.risk_level // empty')
assert_eq "Client: plugin_delete pending_approval.risk_level is critical" "critical" "$RISK"

# security_mode in the pending_approval response must say 'client'
SEC_MODE=$(echo "$DATA" | jq -r '.security_mode // empty')
assert_eq "Client: plugin_delete pending_approval.security_mode is client" "client" "$SEC_MODE"

# ===================================================================
echo "== G. SecurityModeManager::current() via wp eval =="
set_mode "developer"
CURRENT=$(wp eval "echo \WPCommandCenter\Operations\SecurityModeManager::current();" --path="$WP_PATH" 2>/dev/null)
assert_eq "wp eval: developer mode returns developer" "developer" "$CURRENT"

set_mode "client"
CURRENT=$(wp eval "echo \WPCommandCenter\Operations\SecurityModeManager::current();" --path="$WP_PATH" 2>/dev/null)
assert_eq "wp eval: client mode returns client" "client" "$CURRENT"

set_mode "enterprise"
CURRENT=$(wp eval "echo \WPCommandCenter\Operations\SecurityModeManager::current();" --path="$WP_PATH" 2>/dev/null)
assert_eq "wp eval: enterprise mode returns enterprise" "enterprise" "$CURRENT"

# Unknown value falls back to developer
wp eval "update_option('wpcc_security_mode', 'bogus'); echo 'ok';" --path="$WP_PATH" >/dev/null 2>&1
CURRENT=$(wp eval "echo \WPCommandCenter\Operations\SecurityModeManager::current();" --path="$WP_PATH" 2>/dev/null)
assert_eq "wp eval: invalid value falls back to developer" "developer" "$CURRENT"

# ===================================================================
# Always restore to developer to prevent mode state from leaking into other tests.
echo "== Cleanup: restore developer mode =="
set_mode "developer"
RESTORED=$(wp eval "echo get_option('wpcc_security_mode', 'developer');" --path="$WP_PATH" 2>/dev/null)
assert_eq "Mode restored to developer" "developer" "$RESTORED"

echo ""
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
