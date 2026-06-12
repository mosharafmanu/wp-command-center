#!/usr/bin/env bash
#
# Agent Timeline test suite for WP Command Center (Step 13).
#
# Verifies the unified traceable timeline:
#   Session -> Task -> Action -> Plan -> Patch -> Apply -> Rollback
#
# Requires: curl, jq, and wpcc-env.sh.
# Usage: bash tests/test-agent-timeline.sh

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
	if [ -n "$body" ]; then
		curl -s -X "$method" -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$body" "$WPCC_BASE$path"
	else
		curl -s -X "$method" -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE$path"
	fi
}

echo "== 1. Create full runtime chain =="

# 1. Session
SESSION_CREATE=$(api POST /agent/sessions '{"source":"claude","label":"Timeline test session"}')
SESSION_ID=$(echo "$SESSION_CREATE" | jq -r '.session_id // empty')
assert_true "session created" "$(echo "$SESSION_ID" | test "^[a-f0-9-]{36}$" && echo true)"

# 2. Task
TASK_BODY=$(jq -n --arg sid "$SESSION_ID" '{session_id:$sid,source:"claude",user_prompt:"Test the timeline visibility"}')
TASK_CREATE=$(api POST /agent/tasks "$TASK_BODY")
TASK_ID=$(echo "$TASK_CREATE" | jq -r '.task_id // empty')
assert_true "task created" "$(echo "$TASK_ID" | test "^[a-f0-9-]{36}$" && echo true)"

# 3. Action
ACTION_BODY=$(jq -n --arg sid "$SESSION_ID" --arg tid "$TASK_ID" '{session_id:$sid,task_id:$tid,type:"code_change",title:"Proposed change"}')
ACTION_CREATE=$(api POST /agent/actions "$ACTION_BODY")
ACTION_ID=$(echo "$ACTION_CREATE" | jq -r '.action_id // empty')
assert_true "action created" "$(echo "$ACTION_ID" | test "^[a-f0-9-]{36}$" && echo true)"

# 4. Accept Action
api POST "/agent/actions/$ACTION_ID/accept" > /dev/null
pass "action accepted"

# 5. Plan
PLAN_BODY=$(jq -n --arg sid "$SESSION_ID" --arg tid "$TASK_ID" --arg aid "$ACTION_ID" \
	'{session_id:$sid,task_id:$tid,action_id:$aid,title:"Test Plan",objective:"Apply a test patch",steps:[{title:"Apply patch",status:"pending"}]}')
PLAN_CREATE=$(api POST /agent/plans "$PLAN_BODY")
PLAN_ID=$(echo "$PLAN_CREATE" | jq -r '.plan_id // empty')
assert_true "plan created" "$(echo "$PLAN_ID" | test "^[a-f0-9-]{36}$" && echo true)"

# 6. Approve Plan
api POST "/agent/plans/$PLAN_ID/approve" > /dev/null
pass "plan approved"

# 7. Patch
# We need a file to patch. Let's use readme.txt which always exists in the plugin.
FILE_PATH="plugins/wp-command-center/readme.txt"
# Read original content
GET_FILE_RESP=$(api GET "/files/content?path=$FILE_PATH")
ORIGINAL_CONTENT=$(echo "$GET_FILE_RESP" | jq -r '.contents // empty')
if [ -z "$ORIGINAL_CONTENT" ]; then
    echo "DEBUG: Failed to read $FILE_PATH. Response: $GET_FILE_RESP"
    fail "could not read $FILE_PATH"
    exit 1
fi
MODIFIED_CONTENT="$ORIGINAL_CONTENT\n\nTimeline test."

PATCH_BODY=$(jq -n --arg sid "$SESSION_ID" --arg tid "$TASK_ID" --arg pid "$PLAN_ID" --arg path "$FILE_PATH" --arg mod "$MODIFIED_CONTENT" \
	'{session_id:$sid,task_id:$tid,plan_id:$pid,explanation:"Test patch for timeline",files:[{path:$path,modified:$mod}]}')
PATCH_CREATE=$(api POST /patches "$PATCH_BODY")
PATCH_ID=$(echo "$PATCH_CREATE" | jq -r '.id // empty')
assert_true "patch created" "$(echo "$PATCH_ID" | test "^[a-f0-9-]{36}$" && echo true)"

# 8. Approve Patch
api POST "/patches/$PATCH_ID/approve" > /dev/null
pass "patch approved"

# 9. Apply Patch
api POST "/patches/$PATCH_ID/apply" > /dev/null
pass "patch applied"

# 10. Rollback Patch
api POST "/patches/$PATCH_ID/rollback" > /dev/null
pass "patch rolled back"

echo
echo "== 2. Verify /agent/timeline =="

TIMELINE=$(api GET "/agent/timeline?session_id=$SESSION_ID")

assert_true "timeline is an array" "$(echo "$TIMELINE" | jq -r 'type == "array"')"

# Check for specific labels in order (newest first in timeline, but we can just check existence)
for label in "Patch rolled back" "Patch applied" "Patch approved" "Patch created" "Plan approved" "Plan created" "Action accepted" "Action proposed" "Task created" "Session created"; do
	assert_true "timeline contains label: $label" "$(echo "$TIMELINE" | jq -r --arg l "$label" 'any(.[]; .label == $l)')"
done

# Verify IDs are correctly populated for a specific event
TASK_EVENT=$(echo "$TIMELINE" | jq -c --arg tid "$TASK_ID" '.[] | select(.task_id == $tid and .label == "Task created")' | head -1)
assert_eq "task event links session_id" "$SESSION_ID" "$(echo "$TASK_EVENT" | jq -r '.session_id // empty')"
assert_eq "task event links task_id" "$TASK_ID" "$(echo "$TASK_EVENT" | jq -r '.task_id // empty')"

# Verify summary redaction (simulate secret in user prompt)
SECRET_TASK_BODY=$(jq -n --arg sid "$SESSION_ID" '{session_id:$sid,source:"claude",user_prompt:"My secret key is sk-ant-12345678901234567890"}')
SECRET_TASK_CREATE=$(api POST /agent/tasks "$SECRET_TASK_BODY")
SECRET_TASK_ID=$(echo "$SECRET_TASK_CREATE" | jq -r '.task_id // empty')

TIMELINE_REDACTED=$(api GET "/agent/timeline?task_id=$SECRET_TASK_ID")
SUMMARY=$(echo "$TIMELINE_REDACTED" | jq -r '.[0].summary')
assert_true "timeline summary is redacted" "$(echo "$SUMMARY" | grep -q "\[REDACTED_SECRET\]" && echo true)"

echo
echo "== 3. Filtering =="

FILTERED_TASK=$(api GET "/agent/timeline?task_id=$TASK_ID")
assert_true "filtered by task_id: all items have correct task_id" "$(echo "$FILTERED_TASK" | jq -r --arg tid "$TASK_ID" 'all(.[]; .task_id == $tid)')"

FILTERED_PATCH=$(api GET "/agent/timeline?patch_id=$PATCH_ID")
assert_true "filtered by patch_id: contains patch events" "$(echo "$FILTERED_PATCH" | jq -r 'length > 0')"

echo
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"

[ "$FAIL" -eq 0 ]
