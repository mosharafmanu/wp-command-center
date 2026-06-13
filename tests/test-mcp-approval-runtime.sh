#!/usr/bin/env bash
# Step 78 + 80A — MCP Approval Runtime test suite.
#
# STEP 78: approval_manage MCP tool exposes the full request→approve→queue pipeline.
# STEP 80A: approval gate replaced binary wpcc_enforce_approval with Security Modes.
#
# Flow (all via MCP tools/call):
#   Developer mode:
#     1. theme_manage(theme_list) executes immediately — no gate, no pending_approval.
#     2. approval_manage/request_create -> pending_review request (manual creation).
#     3. approval_manage/request_approve -> approved + auto-queued item.
#        (Allowed in Developer mode: no human-approver requirement.)
#     4. approval_manage/queue_run -> completed, with theme_list result.
#     5. approval_manage/results_list(queue_id) -> matching completed result.
#   Client mode:
#     6. theme_manage(theme_install) returns pending_approval (auto-created, not -32000).
#     7. approval_manage/request_approve blocked for token actor (human-only in Client mode).
#     8. approval_manage/request_cancel succeeds (cancel is not human-only).
#   Common:
#     9. tools/list -> approval_manage exposed with a valid inputSchema.
#    10. Restore developer mode.
set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../wpcc-env.sh"
WP_PATH="$SCRIPT_DIR/../../../.."
PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; if [ "$e" = "$a" ]; then pass "$d"; else fail "$d (expected '$e', got '$a')"; fi; }
assert_ne() { local d="$1" e="$2" a="$3"; if [ "$e" != "$a" ]; then pass "$d"; else fail "$d (expected not '$e')"; fi; }
assert_true() { local d="$1" a="$2"; if [ "$a" = "true" ]; then pass "$d"; else fail "$d"; fi; }
mcp() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/mcp"; }
set_mode() { wp eval "update_option('wpcc_security_mode', '$1'); echo 'ok';" --path="$WP_PATH" >/dev/null 2>&1; }

echo "MCP Approval Runtime (Step 78 + 80A) — $(date)"
echo ""

# ===================================================================
echo "== Developer Mode — ops execute without approval =="
set_mode "developer"

# ===================================================================
echo "== 1. theme_manage(theme_list) executes immediately in Developer mode =="
RESP=$(mcp '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"theme_manage","arguments":{"action":"theme_list"}},"id":1}')
ERR=$(echo "$RESP" | jq -r '.error.code // "none"')
DATA=$(echo "$RESP" | jq -r '.result.content[0].text // empty')
STATUS=$(echo "$DATA" | jq -r '.status // "none"')
assert_ne "developer: theme_manage not blocked (-32000)" "-32000" "$ERR"
assert_ne "developer: theme_manage not pending_approval" "pending_approval" "$STATUS"

# ===================================================================
echo "== 2. approval_manage/request_create -> pending_review =="
RESP=$(mcp '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"approval_manage","arguments":{"action":"request_create","operation_id":"theme_manage","payload":{"action":"theme_list"}}},"id":2}')
DATA=$(echo "$RESP" | jq -r '.result.content[0].text // empty')
assert_eq "request_create: action echoed" "request_create" "$(echo "$DATA" | jq -r '.action // empty')"
assert_eq "request_create: status is pending_review" "pending_review" "$(echo "$DATA" | jq -r '.request.status // empty')"
assert_eq "request_create: operation_id is theme_manage" "theme_manage" "$(echo "$DATA" | jq -r '.request.operation_id // empty')"
REQUEST_ID=$(echo "$DATA" | jq -r '.request.request_id // empty')
assert_true "request_create: request_id captured" "$( [ -n "$REQUEST_ID" ] && [ "$REQUEST_ID" != "null" ] && echo true || echo false )"

# ===================================================================
echo "== 3. approval_manage/request_approve -> approved + auto-queued (Developer mode: token can approve) =="
BODY=$(jq -n --arg rid "$REQUEST_ID" '{jsonrpc:"2.0",method:"tools/call",params:{name:"approval_manage",arguments:{action:"request_approve",request_id:$rid}},id:3}')
RESP=$(mcp "$BODY")
DATA=$(echo "$RESP" | jq -r '.result.content[0].text // empty')
assert_eq "request_approve: status is approved" "approved" "$(echo "$DATA" | jq -r '.status // empty')"
assert_eq "request_approve: queue item is queued" "queued" "$(echo "$DATA" | jq -r '.queue_item.status // empty')"
assert_eq "request_approve: queue item targets theme_manage" "theme_manage" "$(echo "$DATA" | jq -r '.queue_item.operation_id // empty')"
QUEUE_ID=$(echo "$DATA" | jq -r '.queue_item.queue_id // empty')
assert_true "request_approve: queue_id captured" "$( [ -n "$QUEUE_ID" ] && [ "$QUEUE_ID" != "null" ] && echo true || echo false )"

# ===================================================================
echo "== 4. approval_manage/queue_run -> completed (Execute) =="
BODY=$(jq -n --arg qid "$QUEUE_ID" '{jsonrpc:"2.0",method:"tools/call",params:{name:"approval_manage",arguments:{action:"queue_run",queue_id:$qid}},id:4}')
RESP=$(mcp "$BODY")
DATA=$(echo "$RESP" | jq -r '.result.content[0].text // empty')
assert_eq "queue_run: item status is completed" "completed" "$(echo "$DATA" | jq -r '.item.status // empty')"
assert_true "queue_run: underlying theme_manage call succeeded" "$(echo "$DATA" | jq -r '.item.result.success // false')"
THEME_COUNT=$(echo "$DATA" | jq -r '.item.result.result.themes | length // 0')
assert_true "queue_run: theme_list returned themes" "$( [ "${THEME_COUNT:-0}" -gt 0 ] 2>/dev/null && echo true || echo false )"

# ===================================================================
echo "== 5. approval_manage/results_list(queue_id) -> Verify =="
BODY=$(jq -n --arg qid "$QUEUE_ID" '{jsonrpc:"2.0",method:"tools/call",params:{name:"approval_manage",arguments:{action:"results_list",queue_id:$qid}},id:5}')
RESP=$(mcp "$BODY")
DATA=$(echo "$RESP" | jq -r '.result.content[0].text // empty')
assert_eq "results_list: matching result is completed" "completed" "$(echo "$DATA" | jq -r '.results[0].status // empty')"
assert_eq "results_list: matching result targets theme_manage" "theme_manage" "$(echo "$DATA" | jq -r '.results[0].operation_id // empty')"

# ===================================================================
echo "== Client Mode — gate + human-approver guard =="
set_mode "client"

# ===================================================================
echo "== 6. theme_manage(theme_install) returns pending_approval in Client mode =="
# theme_install has action_risk 'high' in theme_manage — gated in Client mode
RESP=$(mcp '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"theme_manage","arguments":{"action":"theme_install","slug":"twentytwentyone"}},"id":6}')
DATA=$(echo "$RESP" | jq -r '.result.content[0].text // empty')
STATUS=$(echo "$DATA" | jq -r '.status // empty')
assert_eq "client: theme_install returns pending_approval" "pending_approval" "$STATUS"
assert_eq "client: no JSON-RPC error (success=true response)" "null" "$(echo "$RESP" | jq -r '.error // "null"')"
AUTO_REQUEST_ID=$(echo "$DATA" | jq -r '.request_id // empty')
assert_true "client: pending_approval has request_id" "$( [ -n "$AUTO_REQUEST_ID" ] && [ "$AUTO_REQUEST_ID" != "null" ] && echo true || echo false )"

# ===================================================================
echo "== 7. approval_manage/request_approve blocked for token actor in Client mode =="
BODY=$(jq -n --arg rid "$AUTO_REQUEST_ID" '{jsonrpc:"2.0",method:"tools/call",params:{name:"approval_manage",arguments:{action:"request_approve",request_id:$rid}},id:7}')
RESP=$(mcp "$BODY")
ERRC=$(echo "$RESP" | jq -r '.error.code // empty')
DATA=$(echo "$RESP" | jq -r '.result.content[0].text // empty')
GOT_ERRC=$(echo "$DATA" | jq -r '.code // empty')
if [ "$ERRC" = "-32000" ] || [ "$GOT_ERRC" = "wpcc_approval_requires_human" ]; then
    pass "client: token actor blocked from request_approve"
else
    fail "client: token actor blocked from request_approve (error.code='$ERRC', data.code='$GOT_ERRC')"
fi

# ===================================================================
echo "== 8. approval_manage/request_cancel succeeds (not human-only) =="
BODY=$(jq -n --arg rid "$AUTO_REQUEST_ID" '{jsonrpc:"2.0",method:"tools/call",params:{name:"approval_manage",arguments:{action:"request_cancel",request_id:$rid}},id:8}')
RESP=$(mcp "$BODY")
DATA=$(echo "$RESP" | jq -r '.result.content[0].text // empty')
CANCELLED_STATUS=$(echo "$DATA" | jq -r '.status // empty')
assert_eq "client: AI can cancel its own request" "cancelled" "$CANCELLED_STATUS"

# ===================================================================
echo "== 9. tools/list -> approval_manage exposed with a valid inputSchema =="
set_mode "developer"
TOOLS=$(mcp '{"jsonrpc":"2.0","method":"tools/list","id":9}')
TOOL=$(echo "$TOOLS" | jq -c '.result.tools[] | select(.name == "approval_manage")')
assert_true "tools/list: approval_manage present" "$( [ -n "$TOOL" ] && echo true || echo false )"
assert_eq "approval_manage: inputSchema.type is object" "object" "$(echo "$TOOL" | jq -r '.inputSchema.type // empty')"
assert_true "approval_manage: required includes action" "$(echo "$TOOL" | jq -r '(.inputSchema.required // []) | index("action") != null')"
ENUM_COUNT=$(echo "$TOOL" | jq -r '.inputSchema.properties.action.enum | length // 0')
assert_true "approval_manage: action enum is non-empty" "$( [ "${ENUM_COUNT:-0}" -gt 0 ] 2>/dev/null && echo true || echo false )"

# ===================================================================
echo "== 10. Restore developer mode =="
RESTORED=$(wp eval 'echo get_option("wpcc_security_mode", "developer");' --path="$WP_PATH" 2>/dev/null)
assert_eq "mode is developer" "developer" "$RESTORED"

echo ""
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
