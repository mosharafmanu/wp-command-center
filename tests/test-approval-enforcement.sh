#!/usr/bin/env bash
# Remediation S-1 — Approval Enforcement test suite (updated for Step 80A Security Modes).
#
# Step 80A replaced the binary wpcc_enforce_approval flag with three named Security Modes
# (developer / client / enterprise). This suite verifies:
#   - Developer mode: all ops execute immediately via both MCP and REST
#   - Client mode: medium/high/critical ops return pending_approval (not -32000) via MCP
#   - Client mode: diagnostic ops (database_inspect) still execute freely
#   - Client mode: write ops via REST also return 200 with pending_approval body
#   - The request -> approve -> execute workflow works (in Developer mode via REST)
#   - Manifest security.human_approval_required reflects the active Security Mode
#   - Security mode is restored to developer after the test
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
api() {
	local method="$1" path="$2" body="${3:-}"
	if [ -n "$body" ]; then
		curl -s -X "$method" -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$body" "$WPCC_BASE$path"
	else
		curl -s -X "$method" -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE$path"
	fi
}
set_mode() { wp eval "update_option('wpcc_security_mode', '$1'); echo 'ok';" --path="$WP_PATH" >/dev/null 2>&1; }

echo "Approval Enforcement (Step 80A Security Modes) — $(date)"
echo ""

# ===================================================================
echo "== 1. Developer Mode — baseline, no approval gate =="
set_mode "developer"
MANIFEST=$(api GET /agent/manifest)
assert_eq "developer: manifest security.human_approval_required is false" "false" "$(echo "$MANIFEST" | jq -r '.security.human_approval_required')"

# In Developer mode content_seed should execute immediately
RESP=$(mcp '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"content_seed","arguments":{"type":"post","count":1,"status":"draft","title_pattern":"WPCC S-1 Dev {n}"}},"id":1}')
ERR=$(echo "$RESP" | jq -r '.error.code // "none"')
DATA=$(echo "$RESP" | jq -r '.result.content[0].text // empty')
STATUS=$(echo "$DATA" | jq -r '.status // "none"')
assert_ne "developer: content_seed not blocked" "-32000" "$ERR"
assert_ne "developer: content_seed result is not pending_approval" "pending_approval" "$STATUS"
DEV_SEED_ID=$(echo "$DATA" | jq -r '.created_ids[0] // empty')

# ===================================================================
echo "== 2. Client Mode — manifest reflects gating =="
set_mode "client"
MANIFEST=$(api GET /agent/manifest)
assert_eq "client: manifest security.human_approval_required is true" "true" "$(echo "$MANIFEST" | jq -r '.security.human_approval_required')"

# ===================================================================
echo "== 3. Client Mode — medium-risk op returns pending_approval via MCP (not -32000) =="
# content_seed is medium risk → gated in Client mode
RESP=$(mcp '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"content_seed","arguments":{"type":"post","count":1,"status":"draft","title_pattern":"WPCC S-1 Client {n}"}},"id":2}')
assert_eq "client: MCP returns no JSON-RPC error" "null" "$(echo "$RESP" | jq -r '.error // "null"')"
DATA=$(echo "$RESP" | jq -r '.result.content[0].text // empty')
STATUS=$(echo "$DATA" | jq -r '.status // empty')
assert_eq "client: content_seed returns pending_approval via MCP" "pending_approval" "$STATUS"
REQUEST_ID=$(echo "$DATA" | jq -r '.request_id // empty')
assert_true "client: pending_approval has request_id" "$( [ -n "$REQUEST_ID" ] && [ "$REQUEST_ID" != "null" ] && echo true || echo false )"
# Cancel the auto-created request
BODY=$(jq -n --arg rid "$REQUEST_ID" '{jsonrpc:"2.0",method:"tools/call",params:{name:"approval_manage",arguments:{action:"request_cancel",request_id:$rid}},id:98}')
mcp "$BODY" >/dev/null

# ===================================================================
echo "== 4. Client Mode — medium-risk op returns 200 + pending_approval via REST =="
RESP_FILE=$(mktemp)
HTTP_CODE=$(curl -s -o "$RESP_FILE" -w "%{http_code}" -X POST \
  -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" \
  -d '{"type":"post","count":1,"status":"draft","title_pattern":"WPCC S-1 REST {n}"}' \
  "$WPCC_BASE/operations/content_seed/run")
REST_DATA=$(cat "$RESP_FILE"); rm -f "$RESP_FILE"
assert_eq "client: REST returns 200 (not 400) for pending_approval" "200" "$HTTP_CODE"
REST_STATUS=$(echo "$REST_DATA" | jq -r '.status // empty')
assert_eq "client: REST body contains pending_approval" "pending_approval" "$REST_STATUS"
REST_REQUEST_ID=$(echo "$REST_DATA" | jq -r '.result.request_id // empty')
# Cancel REST-created request too
if [ -n "$REST_REQUEST_ID" ] && [ "$REST_REQUEST_ID" != "null" ]; then
    BODY=$(jq -n --arg rid "$REST_REQUEST_ID" '{jsonrpc:"2.0",method:"tools/call",params:{name:"approval_manage",arguments:{action:"request_cancel",request_id:$rid}},id:97}')
    mcp "$BODY" >/dev/null
fi

# ===================================================================
echo "== 5. Client Mode — diagnostic op unaffected =="
RESP=$(mcp '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"database_inspect","arguments":{"action":"db_table_list"}},"id":3}')
assert_true "client: database_inspect (diagnostic) still succeeds" "$(echo "$RESP" | jq -r 'if .result then "true" else "false" end')"

# ===================================================================
echo "== 6. Developer Mode — Request -> approve -> execute workflow via REST =="
set_mode "developer"
REQ_CREATE=$(api POST /operations/requests '{"operation_id":"content_seed","payload":{"type":"post","count":1,"status":"draft","title_pattern":"WPCC S-1 Workflow {n}"}}')
REQ_ID=$(echo "$REQ_CREATE" | jq -r '.request_id // empty')
assert_true "workflow: request created" "$( [ -n "$REQ_ID" ] && echo true || echo false )"

APP_RESP=$(api POST "/operations/requests/$REQ_ID/approve")
assert_eq "workflow: request approved" "approved" "$(echo "$APP_RESP" | jq -r '.status // empty')"

EXEC_RESP=$(api POST "/operations/requests/$REQ_ID/execute")
assert_eq "workflow: request executed" "executed" "$(echo "$EXEC_RESP" | jq -r '.status // empty')"
WORKFLOW_ID=$(echo "$EXEC_RESP" | jq -r '.result.result.created_ids[0] // empty')
assert_true "workflow: content actually created" "$( [ -n "$WORKFLOW_ID" ] && echo true || echo false )"

# ===================================================================
echo "== 7. Restore developer mode =="
set_mode "developer"
MANIFEST=$(api GET /agent/manifest)
assert_eq "restored: manifest security.human_approval_required is false" "false" "$(echo "$MANIFEST" | jq -r '.security.human_approval_required')"

RESP=$(mcp '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"content_seed","arguments":{"type":"post","count":1,"status":"draft","title_pattern":"WPCC S-1 Restored {n}"}},"id":5}')
DATA=$(echo "$RESP" | jq -r '.result.content[0].text // empty')
RESTORED_ID=$(echo "$DATA" | jq -r '.created_ids[0] // empty')
assert_true "restored: content_seed executes in developer mode" "$( [ -n "$RESTORED_ID" ] && echo true || echo false )"

echo ""
echo "== Cleanup =="
for id in "$DEV_SEED_ID" "$WORKFLOW_ID" "$RESTORED_ID"; do
	if [ -n "$id" ] && [ "$id" != "null" ]; then wp post delete "$id" --force --path="$WP_PATH" > /dev/null 2>&1; fi
done
echo "  done"

echo ""
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
