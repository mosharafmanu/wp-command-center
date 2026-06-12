#!/usr/bin/env bash
#
# Human Review Layer test suite for WP Command Center (Step 14).
#
# Verifies the full hierarchy visibility:
#   Session -> Task -> Action -> Plan -> Patch
#
# Requires: curl, jq, and wpcc-env.sh.
# Usage: bash tests/test-agent-review.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

# shellcheck source=/dev/null
source "$PLUGIN_DIR/wpcc-env.sh"

PASS=0
FAIL=0

pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }

assert_eq() {
	local desc="$1" expected="$2" actual="$3"
	if [ "$expected" = "$actual" ]; then
		pass "$desc"
	else
		fail "$desc (expected '$expected', got '$actual')"
	fi
}

assert_true() {
	local desc="$1" actual="$2"
	if [ "$actual" = "true" ]; then
		pass "$desc"
	else
		fail "$desc (expected 'true', got '$actual')"
	fi
}

api() {
	local method="$1" path="$2" body="${3:-}"
	local resp
	# echo "DEBUG: $method $WPCC_BASE$path" >&2
	if [ -n "$body" ]; then
		resp=$(curl -s -X "$method" -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$body" "$WPCC_BASE$path")
	else
		resp=$(curl -s -X "$method" -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE$path")
	fi
	if echo "$resp" | grep -q "wp-die-message"; then
		echo "CRITICAL ERROR from API: $resp" >&2
		exit 1
	fi
	echo "$resp"
}

echo "== 1. Setup Hierarchy =="

SESSION_CREATE=$(api POST /agent/sessions '{"source":"gpt","label":"Review Layer Session"}')
SESSION_ID=$(echo "$SESSION_CREATE" | jq -r '.session_id // empty')
assert_true "setup: session created" "$([[ -n \"$SESSION_ID\" ]] && echo true || echo false)"

TASK_BODY=$(jq -n --arg sid "$SESSION_ID" '{session_id:$sid,source:"gpt",user_prompt:"Review my secrets: password=1234"}')
TASK_CREATE=$(api POST /agent/tasks "$TASK_BODY")
TASK_ID=$(echo "$TASK_CREATE" | jq -r '.task_id // empty')
assert_true "setup: task created" "$([[ -n \"$TASK_ID\" ]] && echo true || echo false)"

ACTION_BODY=$(jq -n --arg sid "$SESSION_ID" --arg tid "$TASK_ID" '{session_id:$sid,task_id:$tid,type:"code_change",title:"Review Action"}')
ACTION_CREATE=$(api POST /agent/actions "$ACTION_BODY")
ACTION_ID=$(echo "$ACTION_CREATE" | jq -r '.action_id // empty')
api POST "/agent/actions/$ACTION_ID/accept" > /dev/null

PLAN_BODY=$(jq -n --arg sid "$SESSION_ID" --arg tid "$TASK_ID" --arg aid "$ACTION_ID" \
	'{session_id:$sid,task_id:$tid,action_id:$aid,title:"Review Plan",objective:"Review stuff",steps:[{title:"Step 1",status:"pending"}]}')
PLAN_CREATE=$(api POST /agent/plans "$PLAN_BODY")
PLAN_ID=$(echo "$PLAN_CREATE" | jq -r '.plan_id // empty')
api POST "/agent/plans/$PLAN_ID/approve" > /dev/null

FILE_PATH="plugins/wp-command-center/readme.txt"
ORIGINAL=$(api GET "/files/content?path=$FILE_PATH" | jq -r '.contents // empty')
if [ -z "$ORIGINAL" ]; then
	fail "could not read $FILE_PATH"
	exit 1
fi
PATCH_BODY=$(jq -n --arg sid "$SESSION_ID" --arg tid "$TASK_ID" --arg pid "$PLAN_ID" --arg path "$FILE_PATH" --arg mod "$ORIGINAL\nReview." \
	'{session_id:$sid,task_id:$tid,plan_id:$pid,explanation:"Review Patch",files:[{path:$path,modified:$mod}]}')
PATCH_CREATE=$(api POST /patches "$PATCH_BODY")
PATCH_ID=$(echo "$PATCH_CREATE" | jq -r '.id // empty')
assert_true "setup: patch created" "$([[ -n \"$PATCH_ID\" ]] && echo true || echo false)"

echo
echo "== 2. Session Detail (GET /agent/sessions/{id}) =="
S_DETAIL=$(api GET "/agent/sessions/$SESSION_ID")
assert_true "session detail: has tasks" "$(echo "$S_DETAIL" | jq -r '.tasks | type == "array"')"
assert_true "session detail: has actions" "$(echo "$S_DETAIL" | jq -r '.actions | type == "array"')"
assert_true "session detail: has plans" "$(echo "$S_DETAIL" | jq -r '.plans | type == "array"')"
assert_true "session detail: has patches" "$(echo "$S_DETAIL" | jq -r '.patches | type == "array"')"
assert_true "session detail: patches are summaries (no files)" "$(echo "$S_DETAIL" | jq -r '.patches[0] | has("files") | not')"

echo
echo "== 3. Task Detail (GET /agent/tasks/{id}) =="
T_DETAIL=$(api GET "/agent/tasks/$TASK_ID")
assert_eq "task detail: session_id matches" "$SESSION_ID" "$(echo "$T_DETAIL" | jq -r '.session.session_id')"
assert_true "task detail: has actions" "$(echo "$T_DETAIL" | jq -r '.actions | length > 0')"
assert_true "task detail: has plans" "$(echo "$T_DETAIL" | jq -r '.plans | length > 0')"
assert_true "task detail: has patches" "$(echo "$T_DETAIL" | jq -r '.patches | length > 0')"

echo
echo "== 4. Action Detail (GET /agent/actions/{id}) =="
A_DETAIL=$(api GET "/agent/actions/$ACTION_ID")
assert_eq "action detail: task_id matches" "$TASK_ID" "$(echo "$A_DETAIL" | jq -r '.task.task_id')"
assert_true "action detail: linked plans found" "$(echo "$A_DETAIL" | jq -r '.plans | length > 0')"
assert_true "action detail: linked patches found" "$(echo "$A_DETAIL" | jq -r '.patches | length > 0')"

echo
echo "== 5. Plan Detail (GET /agent/plans/{id}) =="
P_DETAIL=$(api GET "/agent/plans/$PLAN_ID")
assert_eq "plan detail: session_id matches" "$SESSION_ID" "$(echo "$P_DETAIL" | jq -r '.session.session_id')"
assert_eq "plan detail: task_id matches" "$TASK_ID" "$(echo "$P_DETAIL" | jq -r '.task.task_id')"
assert_eq "plan detail: action_id matches" "$ACTION_ID" "$(echo "$P_DETAIL" | jq -r '.action.action_id')"
assert_true "plan detail: patches found" "$(echo "$P_DETAIL" | jq -r '.patches | length > 0')"

echo
echo "== 6. Patch Detail (GET /patches/{id}) =="
PA_DETAIL=$(api GET "/patches/$PATCH_ID")
assert_eq "patch detail: session_id matches" "$SESSION_ID" "$(echo "$PA_DETAIL" | jq -r '.session_id')"
assert_eq "patch detail: task_id matches" "$TASK_ID" "$(echo "$PA_DETAIL" | jq -r '.task_id')"
assert_eq "patch detail: plan_id matches" "$PLAN_ID" "$(echo "$PA_DETAIL" | jq -r '.plan_id')"
assert_eq "patch detail: action_id matches" "$ACTION_ID" "$(echo "$PA_DETAIL" | jq -r '.action_id')"

echo
echo "== 7. Tree Endpoint (GET /agent/tree) =="
TREE_S=$(api GET "/agent/tree?session_id=$SESSION_ID")
assert_eq "tree session: count is 1" "1" "$(echo "$TREE_S" | jq '.sessions | length')"
assert_eq "tree session: id matches" "$SESSION_ID" "$(echo "$TREE_S" | jq -r '.sessions[0].session_id')"
assert_true "tree session: has tasks" "$(echo "$TREE_S" | jq -r '.sessions[0].tasks | length > 0')"
assert_true "tree session: task has action" "$(echo "$TREE_S" | jq -r '.sessions[0].tasks[0].actions | length > 0')"
assert_true "tree session: action has plan" "$(echo "$TREE_S" | jq -r '.sessions[0].tasks[0].actions[0].plans | length > 0')"
assert_true "tree session: plan has patch" "$(echo "$TREE_S" | jq -r '.sessions[0].tasks[0].actions[0].plans[0].patches | length > 0')"

TREE_T=$(api GET "/agent/tree?task_id=$TASK_ID")
assert_eq "tree task: count is 1" "1" "$(echo "$TREE_T" | jq '.sessions | length')"
assert_eq "tree task: task count is 1" "1" "$(echo "$TREE_T" | jq '.sessions[0].tasks | length')"
assert_eq "tree task: id matches" "$TASK_ID" "$(echo "$TREE_T" | jq -r '.sessions[0].tasks[0].task_id')"

echo
echo "== 8. Pagination Verification =="
TREE_PAGED=$(api GET "/agent/tree?limit=2")
assert_eq "tree pagination: limit works" "2" "$(echo "$TREE_PAGED" | jq '.sessions | length')"

# Create another session to test offset
api POST /agent/sessions '{"source":"api","label":"Offset Test Session"}' > /dev/null
S1_ID=$(api GET "/agent/tree?limit=1" | jq -r '.sessions[0].session_id')
S2_ID=$(api GET "/agent/tree?limit=1&offset=1" | jq -r '.sessions[0].session_id')
assert_true "tree pagination: offset works (ids differ)" "$([[ \"$S1_ID\" != \"$S2_ID\" ]] && echo true || echo false)"

echo
echo "== 9. Security & Redaction =="
assert_true "task detail: user_prompt is redacted" "$(echo "$T_DETAIL" | jq -r '.user_prompt' | grep -q "\[REDACTED_SECRET\]" && echo true)"

echo
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"

[ "$FAIL" -eq 0 ]
