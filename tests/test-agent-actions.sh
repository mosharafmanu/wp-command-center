#!/usr/bin/env bash
#
# Agent Actions Runtime Layer test suite for WP Command Center (Step 12).
#
# Verifies the new Action layer inserted between Task and Plan:
#
#   Session -> Task -> Action -> Plan -> Plan Approval -> Patch -> ...
#
#   - wpcc_agent_actions table via POST/GET /agent/actions and
#     GET /agent/actions/{id}
#   - all 6 action types: investigate, recommendation, diagnosis,
#     code_change, configuration_change, maintenance
#   - new actions always start in status=proposed
#   - validation: session not found, task not found, task/session
#     mismatch, invalid type, missing title
#   - 404 (wpcc_action_not_found) for an unknown action id
#   - status transitions: accept, reject, cancel, complete, including
#     invalid-status-guard failures (wpcc_invalid_action_status)
#   - plans may optionally reference action_id (valid and
#     wpcc_action_not_found cases)
#   - GET /agent/context exposes recent_actions and, for a given
#     session_id, session_actions
#   - audit log coverage for action.created, action.accepted,
#     action.rejected, action.cancelled, action.completed
#   - actions are metadata only: creating/transitioning actions does not
#     create or change any patch
#
# Requires: curl, jq, and wpcc-env.sh (sourced from this plugin's root)
# providing $WPCC_BASE and a *full*-scope $WPCC_TOKEN.
#
# Usage: bash tests/test-agent-actions.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_CONTENT_DIR="$(cd "$PLUGIN_DIR/../.." && pwd)"

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

present() {
	if [ -n "$1" ]; then echo "true"; else echo "false"; fi
}

api() {
	# api METHOD PATH [JSON_BODY]
	local method="$1" path="$2" body="${3:-}"

	if [ -n "$body" ]; then
		curl -s -X "$method" -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$body" "$WPCC_BASE$path"
	else
		curl -s -X "$method" -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE$path"
	fi
}

api_status() {
	# api_status METHOD PATH [JSON_BODY]
	local method="$1" path="$2" body="${3:-}"

	if [ -n "$body" ]; then
		curl -s -o /dev/null -w '%{http_code}' -X "$method" -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$body" "$WPCC_BASE$path"
	else
		curl -s -o /dev/null -w '%{http_code}' -X "$method" -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE$path"
	fi
}

UNKNOWN_ID="00000000-0000-4000-8000-000000000000"

echo "== Setup =="

SESSION_CREATE=$(api POST /agent/sessions '{"source":"codex","label":"Agent actions test session"}')
SESSION_ID=$(echo "$SESSION_CREATE" | jq -r '.session_id // empty')
assert_true "setup: session created with UUID" "$(echo "$SESSION_CREATE" | jq -r '(.session_id // "") | test("^[a-f0-9-]{36}$")')"

TASK_BODY=$(jq -n --arg session_id "$SESSION_ID" \
	'{session_id:$session_id,source:"codex",user_prompt:"Investigate the agent actions runtime layer"}')
TASK_CREATE=$(api POST /agent/tasks "$TASK_BODY")
TASK_ID=$(echo "$TASK_CREATE" | jq -r '.task_id // empty')
assert_true "setup: task created with UUID" "$(echo "$TASK_CREATE" | jq -r '(.task_id // "") | test("^[a-f0-9-]{36}$")')"

OTHER_SESSION_CREATE=$(api POST /agent/sessions '{"source":"api","label":"Agent actions mismatch session"}')
OTHER_SESSION_ID=$(echo "$OTHER_SESSION_CREATE" | jq -r '.session_id // empty')

PATCH_COUNT_BEFORE=$(api GET /patches | jq 'length')

echo
echo "== 1. Action creation: all action types =="

# bash 3.2 (macOS default) has no associative arrays, so action ids for
# each type are stored in ACTION_ID_<type> variables and read back via
# indirect expansion (${!varname}).
ACTION_ID_investigate=""
ACTION_ID_recommendation=""
ACTION_ID_diagnosis=""
ACTION_ID_code_change=""
ACTION_ID_configuration_change=""
ACTION_ID_maintenance=""

for type in investigate recommendation diagnosis code_change configuration_change maintenance; do
	BODY=$(jq -n --arg session_id "$SESSION_ID" --arg task_id "$TASK_ID" --arg type "$type" --arg title "Action of type $type" --arg description "Description for $type action." \
		'{session_id:$session_id,task_id:$task_id,type:$type,title:$title,description:$description}')
	CREATE=$(api POST /agent/actions "$BODY")
	ACTION_ID=$(echo "$CREATE" | jq -r '.action_id // empty')
	eval "ACTION_ID_${type}=\"\$ACTION_ID\""

	assert_true "action create ($type): returns a UUID" "$(echo "$CREATE" | jq -r '(.action_id // "") | test("^[a-f0-9-]{36}$")')"
	assert_eq "action create ($type): type matches" "$type" "$(echo "$CREATE" | jq -r '.type // empty')"
	assert_eq "action create ($type): session_id propagation" "$SESSION_ID" "$(echo "$CREATE" | jq -r '.session_id // empty')"
	assert_eq "action create ($type): task_id propagation" "$TASK_ID" "$(echo "$CREATE" | jq -r '.task_id // empty')"
	assert_eq "action create ($type): initial status is proposed" "proposed" "$(echo "$CREATE" | jq -r '.status // empty')"
	assert_eq "action create ($type): description stored" "Description for $type action." "$(echo "$CREATE" | jq -r '.description // empty')"
done

echo
echo "== 2. Validation =="

INVALID_SESSION_ACTION=$(jq -n --arg task_id "$TASK_ID" --arg uid "$UNKNOWN_ID" \
	'{session_id:$uid,task_id:$task_id,type:"investigate",title:"Invalid session"}')
INVALID_SESSION_RESP=$(api POST /agent/actions "$INVALID_SESSION_ACTION")
assert_eq "validation: unknown session_id fails" "wpcc_session_not_found" "$(echo "$INVALID_SESSION_RESP" | jq -r '.code // empty')"

INVALID_TASK_ACTION=$(jq -n --arg session_id "$SESSION_ID" --arg uid "$UNKNOWN_ID" \
	'{session_id:$session_id,task_id:$uid,type:"investigate",title:"Invalid task"}')
INVALID_TASK_RESP=$(api POST /agent/actions "$INVALID_TASK_ACTION")
assert_eq "validation: unknown task_id fails" "wpcc_task_not_found" "$(echo "$INVALID_TASK_RESP" | jq -r '.code // empty')"

MISMATCH_ACTION=$(jq -n --arg session_id "$OTHER_SESSION_ID" --arg task_id "$TASK_ID" \
	'{session_id:$session_id,task_id:$task_id,type:"investigate",title:"Mismatched session/task"}')
MISMATCH_RESP=$(api POST /agent/actions "$MISMATCH_ACTION")
assert_eq "validation: task/session mismatch fails" "wpcc_task_session_mismatch" "$(echo "$MISMATCH_RESP" | jq -r '.code // empty')"

INVALID_TYPE_ACTION=$(jq -n --arg session_id "$SESSION_ID" --arg task_id "$TASK_ID" \
	'{session_id:$session_id,task_id:$task_id,type:"not_a_real_type",title:"Invalid type"}')
INVALID_TYPE_RESP=$(api POST /agent/actions "$INVALID_TYPE_ACTION")
assert_eq "validation: invalid type fails" "wpcc_invalid_action_type" "$(echo "$INVALID_TYPE_RESP" | jq -r '.code // empty')"

MISSING_TITLE_ACTION=$(jq -n --arg session_id "$SESSION_ID" --arg task_id "$TASK_ID" \
	'{session_id:$session_id,task_id:$task_id,type:"investigate",title:""}')
MISSING_TITLE_RESP=$(api POST /agent/actions "$MISSING_TITLE_ACTION")
assert_eq "validation: missing title fails" "wpcc_missing_action_title" "$(echo "$MISSING_TITLE_RESP" | jq -r '.code // empty')"

echo
echo "== 3. List & Get =="

ACTION_LIST=$(api GET /agent/actions)
assert_true "list: contains the investigate action" "$(echo "$ACTION_LIST" | jq -r --arg aid "$ACTION_ID_investigate" 'any(.action_id == $aid)')"

ACTION_GET=$(api GET "/agent/actions/$ACTION_ID_investigate")
assert_eq "get: returns the created action" "$ACTION_ID_investigate" "$(echo "$ACTION_GET" | jq -r '.action_id // empty')"

UNKNOWN_GET=$(api GET "/agent/actions/$UNKNOWN_ID")
UNKNOWN_GET_STATUS=$(api_status GET "/agent/actions/$UNKNOWN_ID")
assert_eq "get: unknown action returns wpcc_action_not_found" "wpcc_action_not_found" "$(echo "$UNKNOWN_GET" | jq -r '.code // empty')"
assert_eq "get: unknown action returns HTTP 404" "404" "$UNKNOWN_GET_STATUS"

echo
echo "== 4. Transitions: accept =="

ACCEPT_RESP=$(api POST "/agent/actions/$ACTION_ID_investigate/accept")
assert_eq "accept: status becomes accepted" "accepted" "$(echo "$ACCEPT_RESP" | jq -r '.status // empty')"

ACCEPT_AGAIN_RESP=$(api POST "/agent/actions/$ACTION_ID_investigate/accept")
assert_eq "accept: re-accepting an accepted action fails" "wpcc_invalid_action_status" "$(echo "$ACCEPT_AGAIN_RESP" | jq -r '.code // empty')"

echo
echo "== 5. Transitions: reject =="

REJECT_RESP=$(api POST "/agent/actions/$ACTION_ID_recommendation/reject")
assert_eq "reject: status becomes rejected" "rejected" "$(echo "$REJECT_RESP" | jq -r '.status // empty')"

REJECT_AGAIN_RESP=$(api POST "/agent/actions/$ACTION_ID_recommendation/reject")
assert_eq "reject: re-rejecting a rejected action fails" "wpcc_invalid_action_status" "$(echo "$REJECT_AGAIN_RESP" | jq -r '.code // empty')"

echo
echo "== 6. Transitions: cancel =="

CANCEL_PROPOSED_RESP=$(api POST "/agent/actions/$ACTION_ID_diagnosis/cancel")
assert_eq "cancel: proposed action becomes cancelled" "cancelled" "$(echo "$CANCEL_PROPOSED_RESP" | jq -r '.status // empty')"

ACCEPT_FOR_CANCEL=$(api POST "/agent/actions/$ACTION_ID_code_change/accept")
assert_eq "cancel setup: code_change action accepted" "accepted" "$(echo "$ACCEPT_FOR_CANCEL" | jq -r '.status // empty')"

CANCEL_ACCEPTED_RESP=$(api POST "/agent/actions/$ACTION_ID_code_change/cancel")
assert_eq "cancel: accepted action becomes cancelled" "cancelled" "$(echo "$CANCEL_ACCEPTED_RESP" | jq -r '.status // empty')"

CANCEL_REJECTED_RESP=$(api POST "/agent/actions/$ACTION_ID_recommendation/cancel")
assert_eq "cancel: rejected action cannot be cancelled" "wpcc_invalid_action_status" "$(echo "$CANCEL_REJECTED_RESP" | jq -r '.code // empty')"

echo
echo "== 7. Transitions: complete =="

COMPLETE_PROPOSED_RESP=$(api POST "/agent/actions/$ACTION_ID_configuration_change/complete")
assert_eq "complete: proposed action cannot be completed directly" "wpcc_invalid_action_status" "$(echo "$COMPLETE_PROPOSED_RESP" | jq -r '.code // empty')"

ACCEPT_FOR_COMPLETE=$(api POST "/agent/actions/$ACTION_ID_maintenance/accept")
assert_eq "complete setup: maintenance action accepted" "accepted" "$(echo "$ACCEPT_FOR_COMPLETE" | jq -r '.status // empty')"

COMPLETE_RESP=$(api POST "/agent/actions/$ACTION_ID_maintenance/complete")
assert_eq "complete: accepted action becomes completed" "completed" "$(echo "$COMPLETE_RESP" | jq -r '.status // empty')"

COMPLETE_AGAIN_RESP=$(api POST "/agent/actions/$ACTION_ID_maintenance/complete")
assert_eq "complete: re-completing a completed action fails" "wpcc_invalid_action_status" "$(echo "$COMPLETE_AGAIN_RESP" | jq -r '.code // empty')"

echo
echo "== 8. Plan action_id linkage =="

LINK_ACTION_BODY=$(jq -n --arg session_id "$SESSION_ID" --arg task_id "$TASK_ID" \
	'{session_id:$session_id,task_id:$task_id,type:"recommendation",title:"Recommend a config change",description:"Linked to a downstream plan."}')
LINK_ACTION=$(api POST /agent/actions "$LINK_ACTION_BODY")
LINK_ACTION_ID=$(echo "$LINK_ACTION" | jq -r '.action_id // empty')
assert_true "plan linkage setup: action created" "$(present "$LINK_ACTION_ID")"

LINKED_PLAN_BODY=$(jq -n --arg session_id "$SESSION_ID" --arg task_id "$TASK_ID" --arg action_id "$LINK_ACTION_ID" \
	'{session_id:$session_id,task_id:$task_id,action_id:$action_id,title:"Plan from recommendation",objective:"Implement the recommended action",steps:[{title:"Implement",description:"Carry out the recommendation."}]}')
LINKED_PLAN=$(api POST /agent/plans "$LINKED_PLAN_BODY")
LINKED_PLAN_ID=$(echo "$LINKED_PLAN" | jq -r '.plan_id // empty')
assert_eq "plan linkage: plan stores action_id" "$LINK_ACTION_ID" "$(echo "$LINKED_PLAN" | jq -r '.action_id // empty')"

PLAN_GET=$(api GET "/agent/plans/$LINKED_PLAN_ID")
assert_eq "plan linkage: get plan returns action_id" "$LINK_ACTION_ID" "$(echo "$PLAN_GET" | jq -r '.action_id // empty')"

INVALID_ACTION_PLAN_BODY=$(jq -n --arg session_id "$SESSION_ID" --arg task_id "$TASK_ID" --arg uid "$UNKNOWN_ID" \
	'{session_id:$session_id,task_id:$task_id,action_id:$uid,title:"Plan with bad action",objective:"Should fail",steps:[{title:"Step"}]}')
INVALID_ACTION_PLAN_RESP=$(api POST /agent/plans "$INVALID_ACTION_PLAN_BODY")
assert_eq "plan linkage: unknown action_id fails" "wpcc_action_not_found" "$(echo "$INVALID_ACTION_PLAN_RESP" | jq -r '.code // empty')"

echo
echo "== 9. Agent context integration =="

AGENT_CONTEXT=$(api GET /agent/context)
assert_true "agent context: recent_actions is an array" "$(echo "$AGENT_CONTEXT" | jq -r '(.recent_actions | type) == "array"')"
assert_true "agent context: recent_actions contains the investigate action" "$(echo "$AGENT_CONTEXT" | jq -r --arg aid "$ACTION_ID_investigate" 'any(.recent_actions[]; .action_id == $aid)')"

SESSION_CONTEXT=$(api GET "/agent/context?session_id=$SESSION_ID")
assert_true "agent context: session_actions is an array" "$(echo "$SESSION_CONTEXT" | jq -r '(.session_actions | type) == "array"')"
for type in investigate recommendation diagnosis code_change configuration_change maintenance; do
	varname="ACTION_ID_${type}"
	aid="${!varname}"
	assert_true "agent context: session_actions contains $type action" "$(echo "$SESSION_CONTEXT" | jq -r --arg aid "$aid" 'any(.session_actions[]; .action_id == $aid)')"
done

echo
echo "== 10. Audit log =="

AUDIT_LOG="$WP_CONTENT_DIR/uploads/wpcc-audit/audit.log"

if [ ! -r "$AUDIT_LOG" ]; then
	fail "audit log: file not readable at $AUDIT_LOG"
else
	ACTION_CREATED=$(jq -c --arg aid "$ACTION_ID_investigate" \
		'select(.action == "action.created" and .context.action_id == $aid)' "$AUDIT_LOG" | tail -1)
	assert_true "audit log: action.created recorded" "$(present "$ACTION_CREATED")"
	assert_eq "audit log: action.created links session_id" "$SESSION_ID" "$(echo "$ACTION_CREATED" | jq -r '.context.session_id // empty')"
	assert_eq "audit log: action.created links task_id" "$TASK_ID" "$(echo "$ACTION_CREATED" | jq -r '.context.task_id // empty')"
	assert_eq "audit log: action.created records type" "investigate" "$(echo "$ACTION_CREATED" | jq -r '.context.type // empty')"

	ACTION_ACCEPTED=$(jq -c --arg aid "$ACTION_ID_investigate" \
		'select(.action == "action.accepted" and .context.action_id == $aid)' "$AUDIT_LOG" | tail -1)
	assert_true "audit log: action.accepted recorded" "$(present "$ACTION_ACCEPTED")"
	assert_eq "audit log: action.accepted status is accepted" "accepted" "$(echo "$ACTION_ACCEPTED" | jq -r '.context.status // empty')"

	ACTION_REJECTED=$(jq -c --arg aid "$ACTION_ID_recommendation" \
		'select(.action == "action.rejected" and .context.action_id == $aid)' "$AUDIT_LOG" | tail -1)
	assert_true "audit log: action.rejected recorded" "$(present "$ACTION_REJECTED")"
	assert_eq "audit log: action.rejected status is rejected" "rejected" "$(echo "$ACTION_REJECTED" | jq -r '.context.status // empty')"

	ACTION_CANCELLED=$(jq -c --arg aid "$ACTION_ID_diagnosis" \
		'select(.action == "action.cancelled" and .context.action_id == $aid)' "$AUDIT_LOG" | tail -1)
	assert_true "audit log: action.cancelled recorded" "$(present "$ACTION_CANCELLED")"
	assert_eq "audit log: action.cancelled status is cancelled" "cancelled" "$(echo "$ACTION_CANCELLED" | jq -r '.context.status // empty')"

	ACTION_COMPLETED=$(jq -c --arg aid "$ACTION_ID_maintenance" \
		'select(.action == "action.completed" and .context.action_id == $aid)' "$AUDIT_LOG" | tail -1)
	assert_true "audit log: action.completed recorded" "$(present "$ACTION_COMPLETED")"
	assert_eq "audit log: action.completed status is completed" "completed" "$(echo "$ACTION_COMPLETED" | jq -r '.context.status // empty')"
fi

echo
echo "== 11. Actions are metadata only (no patch side effects) =="

PATCH_COUNT_AFTER=$(api GET /patches | jq 'length')
assert_eq "metadata only: action lifecycle creates no patches" "$PATCH_COUNT_BEFORE" "$PATCH_COUNT_AFTER"

echo
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"

[ "$FAIL" -eq 0 ]
