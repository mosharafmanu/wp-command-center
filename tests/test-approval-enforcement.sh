#!/usr/bin/env bash
# Remediation S-1 — Approval Enforcement test suite.
#
# Exercises the wpcc_enforce_approval=true code path, which previously had
# zero test coverage even though /agent/manifest unconditionally claimed
# security.human_approval_required = true. Verifies:
#   - /agent/manifest "security.human_approval_required" reflects the live
#     wpcc_enforce_approval option (both OFF and ON)
#   - a requires_approval=true operation (content_seed) is blocked via both
#     MCP tools/call (-32000) and REST /operations/{id}/run (400) when ON
#   - a requires_approval=false operation (database_inspect) is unaffected
#   - the request -> approve -> execute workflow still completes when ON
#     (context.request_id bypasses the gate, as designed)
#   - enforcement is restored to its original value afterwards
set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../wpcc-env.sh"
WP_PATH="$SCRIPT_DIR/../../../.."
PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; if [ "$e" = "$a" ]; then pass "$d"; else fail "$d (expected '$e', got '$a')"; fi; }
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
set_enforce() { wp eval "update_option('wpcc_enforce_approval', $1); echo 'ok';" --path="$WP_PATH" >/dev/null 2>&1; }

echo "Approval Enforcement (Remediation S-1) — $(date)"
echo ""

# ===================================================================
echo "== 1. Baseline — enforcement OFF (default) =="
ORIGINAL=$(wp eval 'echo get_option("wpcc_enforce_approval", false) ? "true" : "false";' --path="$WP_PATH" 2>/dev/null)
MANIFEST=$(api GET /agent/manifest)
assert_eq "baseline: manifest security.human_approval_required is false" "false" "$(echo "$MANIFEST" | jq -r '.security.human_approval_required')"

# ===================================================================
echo "== 2. Enable enforcement =="
set_enforce true
MANIFEST=$(api GET /agent/manifest)
assert_eq "enabled: manifest security.human_approval_required is true" "true" "$(echo "$MANIFEST" | jq -r '.security.human_approval_required')"

# ===================================================================
echo "== 3. requires_approval=true operation blocked via MCP =="
RESP=$(mcp '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"content_seed","arguments":{"type":"post","count":1,"status":"draft","title_pattern":"WPCC S-1 Blocked {n}"}},"id":1}')
assert_eq "blocked: content_seed returns -32000 via MCP" "-32000" "$(echo "$RESP" | jq -r '.error.code // empty')"
assert_true "blocked: error message mentions approval" "$(echo "$RESP" | jq -r '(.error.message // "") | test("approval"; "i")')"

# ===================================================================
echo "== 4. requires_approval=true operation blocked via REST =="
RESP_FILE=$(mktemp)
HTTP_CODE=$(curl -s -o "$RESP_FILE" -w "%{http_code}" -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d '{"type":"post","count":1,"status":"draft","title_pattern":"WPCC S-1 REST {n}"}' "$WPCC_BASE/operations/content_seed/run")
assert_eq "blocked: content_seed REST returns 400" "400" "$HTTP_CODE"
assert_eq "blocked: content_seed REST error code is wpcc_approval_required" "wpcc_approval_required" "$(jq -r '.code // empty' "$RESP_FILE")"
rm -f "$RESP_FILE"

# ===================================================================
echo "== 5. requires_approval=false operation unaffected via MCP =="
RESP=$(mcp '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"database_inspect","arguments":{"action":"db_table_list"}},"id":2}')
assert_true "unaffected: database_inspect still succeeds with enforcement on" "$(echo "$RESP" | jq -r 'if .result then "true" else "false" end')"

# ===================================================================
echo "== 6. Request -> approve -> execute workflow still works with enforcement on =="
REQ_CREATE=$(api POST /operations/requests '{"operation_id":"content_seed","payload":{"type":"post","count":1,"status":"draft","title_pattern":"WPCC S-1 Workflow {n}"}}')
REQ_ID=$(echo "$REQ_CREATE" | jq -r '.request_id // empty')
assert_true "workflow: request created" "$( [ -n "$REQ_ID" ] && echo true || echo false )"

APP_RESP=$(api POST "/operations/requests/$REQ_ID/approve")
assert_eq "workflow: request approved" "approved" "$(echo "$APP_RESP" | jq -r '.status // empty')"

EXEC_RESP=$(api POST "/operations/requests/$REQ_ID/execute")
assert_eq "workflow: request executed despite enforcement being on" "executed" "$(echo "$EXEC_RESP" | jq -r '.status // empty')"
WORKFLOW_ID=$(echo "$EXEC_RESP" | jq -r '.result.result.created_ids[0] // empty')
assert_true "workflow: content actually created" "$( [ -n "$WORKFLOW_ID" ] && echo true || echo false )"

# ===================================================================
echo "== 7. Restore enforcement to original value =="
set_enforce "$ORIGINAL"
MANIFEST=$(api GET /agent/manifest)
assert_eq "restored: manifest security.human_approval_required matches original" "$ORIGINAL" "$(echo "$MANIFEST" | jq -r '.security.human_approval_required')"

RESP=$(mcp '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"content_seed","arguments":{"type":"post","count":1,"status":"draft","title_pattern":"WPCC S-1 Restored {n}"}},"id":3}')
RESTORED_ID=$(echo "$RESP" | jq -r '.result.content[0].text' | jq -r '.created_ids[0] // empty')
assert_true "restored: content_seed succeeds again with enforcement off" "$( [ -n "$RESTORED_ID" ] && echo true || echo false )"

echo ""
echo "== Cleanup =="
for id in "$WORKFLOW_ID" "$RESTORED_ID"; do
	if [ -n "$id" ]; then wp post delete "$id" --force --path="$WP_PATH" > /dev/null 2>&1; fi
done
echo "  done"

echo ""
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
