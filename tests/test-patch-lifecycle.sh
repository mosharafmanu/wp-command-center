#!/usr/bin/env bash
#
# End-to-end integration test for the WP Command Center REST API:
#
#   - the full patch lifecycle (create -> approve -> apply -> rollback),
#     including status_history growth and rollback verification
#   - spot checks of /health, /capabilities, /files/meta, /context,
#     /manifest, and /search?type=
#   - audit log entries for the patch lifecycle above
#
# Requires: curl, jq, and wpcc-env.sh (sourced from this plugin's root)
# providing $WPCC_BASE and a *full*-scope $WPCC_TOKEN.
#
# Usage: bash tests/test-patch-lifecycle.sh

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

api() {
	# api METHOD PATH [JSON_BODY]
	local method="$1" path="$2" body="${3:-}"

	if [ -n "$body" ]; then
		curl -s -X "$method" -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$body" "$WPCC_BASE$path"
	else
		curl -s -X "$method" -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE$path"
	fi
}

FIXTURE_REL="mu-plugins/wpcc-test/fixture.txt"
FIXTURE_DIR="$WP_CONTENT_DIR/mu-plugins/wpcc-test"
FIXTURE_FILE="$FIXTURE_DIR/fixture.txt"

cleanup() {
	rm -rf "$FIXTURE_DIR"
}
trap cleanup EXIT

echo "== Setup =="
mkdir -p "$FIXTURE_DIR"
printf 'v1\n' > "$FIXTURE_FILE"
pass "fixture file created at wp-content/$FIXTURE_REL"

echo
echo "== Patch lifecycle =="

CREATE_BODY=$(jq -n --arg path "$FIXTURE_REL" --arg modified $'v2\n' \
	'{files: [{path: $path, modified: $modified}], explanation: "Integration test patch", risk_level: "low"}')

CREATE_RESP=$(api POST /patches "$CREATE_BODY")
PATCH_ID=$(echo "$CREATE_RESP" | jq -r '.id // empty')

assert_eq "create: status is pending_approval" "pending_approval" "$(echo "$CREATE_RESP" | jq -r '.status // empty')"
assert_true "create: diff is non-empty" "$(echo "$CREATE_RESP" | jq -r '(.files[0].diff // "") | length > 0')"
assert_eq "create: status_history has 1 entry" "1" "$(echo "$CREATE_RESP" | jq -r '.status_history | length')"
assert_eq "create: legacy patch session_id is null" "null" "$(echo "$CREATE_RESP" | jq -r '.session_id')"
assert_eq "create: legacy patch task_id is null" "null" "$(echo "$CREATE_RESP" | jq -r '.task_id')"

if [ -z "$PATCH_ID" ]; then
	fail "create: no patch id returned, aborting lifecycle checks"
	echo "$CREATE_RESP"
else
	echo "  patch id: $PATCH_ID"

	APPROVE_RESP=$(api POST "/patches/$PATCH_ID/approve")
	assert_eq "approve: status is approved" "approved" "$(echo "$APPROVE_RESP" | jq -r '.status // empty')"
	assert_eq "approve: status_history has 2 entries" "2" "$(echo "$APPROVE_RESP" | jq -r '.status_history | length')"

	APPLY_RESP=$(api POST "/patches/$PATCH_ID/apply")
	assert_eq "apply: status is applied" "applied" "$(echo "$APPLY_RESP" | jq -r '.status // empty')"
	assert_true "apply: verification.passed is true" "$(echo "$APPLY_RESP" | jq -r '.verification.passed')"
	assert_true "apply: snapshot_ids non-empty" "$(echo "$APPLY_RESP" | jq -r '.snapshot_ids | length > 0')"
	assert_eq "apply: file on disk is v2" "v2" "$(cat "$FIXTURE_FILE")"

	ROLLBACK_RESP=$(api POST "/patches/$PATCH_ID/rollback")
	assert_eq "rollback: status is rolled_back" "rolled_back" "$(echo "$ROLLBACK_RESP" | jq -r '.status // empty')"
	assert_true "rollback: all files verified" "$(echo "$ROLLBACK_RESP" | jq -r '.rollback_results | all(.verified == true)')"
	assert_eq "rollback: file on disk is back to v1" "v1" "$(cat "$FIXTURE_FILE")"

	LEGACY_DETAIL=$(api GET "/patches/$PATCH_ID")
	assert_eq "patch detail: legacy session_id is null" "null" "$(echo "$LEGACY_DETAIL" | jq -r '.session_id')"
	assert_eq "patch detail: legacy task_id is null" "null" "$(echo "$LEGACY_DETAIL" | jq -r '.task_id')"

	LEGACY_LIST=$(api GET /patches)
	assert_eq "patch list: legacy session_id is null" "null" "$(echo "$LEGACY_LIST" | jq -r --arg pid "$PATCH_ID" '.[] | select(.id == $pid) | .session_id')"
	assert_eq "patch list: legacy task_id is null" "null" "$(echo "$LEGACY_LIST" | jq -r --arg pid "$PATCH_ID" '.[] | select(.id == $pid) | .task_id')"
fi

echo
echo "== Foundation endpoints =="

HEALTH=$(api GET /health)
assert_eq "health: status is ok" "ok" "$(echo "$HEALTH" | jq -r '.status')"
assert_eq "health: api_version is v1" "v1" "$(echo "$HEALTH" | jq -r '.api_version')"

CAPS=$(api GET /capabilities)
assert_true "capabilities: has the 7-key shape" "$(echo "$CAPS" | jq -r '[has("file_read"),has("file_write"),has("patch_apply"),has("rollback"),has("shell_exec"),has("proc_open"),has("wp_cli")] | all')"
assert_eq "capabilities: file_read is true" "true" "$(echo "$CAPS" | jq -r '.file_read')"
assert_eq "capabilities: file_write is false" "false" "$(echo "$CAPS" | jq -r '.file_write')"
assert_eq "capabilities: patch_apply is true (full token)" "true" "$(echo "$CAPS" | jq -r '.patch_apply')"
assert_eq "capabilities: rollback is true (full token)" "true" "$(echo "$CAPS" | jq -r '.rollback')"

META=$(api GET "/files/meta?path=$FIXTURE_REL")
assert_eq "files/meta: path matches" "$FIXTURE_REL" "$(echo "$META" | jq -r '.path')"
assert_true "files/meta: has a hash" "$(echo "$META" | jq -r '(.hash // "") | length > 0')"

CONTEXT=$(api GET /context)
assert_true "context: has file_access/diagnostics/server_capabilities" "$(echo "$CONTEXT" | jq -r '[has("file_access"),has("diagnostics"),has("server_capabilities")] | all')"

MANIFEST=$(api GET /manifest)
assert_eq "manifest: namespace is wp-command-center/v1" "wp-command-center/v1" "$(echo "$MANIFEST" | jq -r '.namespace')"
assert_true "manifest: endpoints non-empty" "$(echo "$MANIFEST" | jq -r '.endpoints | length > 0')"

SEARCH=$(api GET "/search?q=wp_enqueue_script&type=function")
assert_eq "search: type is echoed back" "function" "$(echo "$SEARCH" | jq -r '.type')"
assert_true "search: has match_count" "$(echo "$SEARCH" | jq -r 'has("match_count")')"

echo
echo "== Agent sessions =="

SESSION_EXPIRES=$(( $(date +%s) + 3600 ))
SESSION_BODY=$(jq -n --arg source "codex" --arg label "Integration test session" --argjson expires_at "$SESSION_EXPIRES" \
	'{source: $source, label: $label, expires_at: $expires_at}')
SESSION_CREATE=$(api POST /agent/sessions "$SESSION_BODY")
SESSION_ID=$(echo "$SESSION_CREATE" | jq -r '.session_id // empty')

assert_true "agent sessions: create returns a UUID" "$(echo "$SESSION_CREATE" | jq -r '(.session_id // "") | test("^[a-f0-9-]{36}$")')"
assert_eq "agent sessions: create status is active" "active" "$(echo "$SESSION_CREATE" | jq -r '.status // empty')"
assert_eq "agent sessions: create source is codex" "codex" "$(echo "$SESSION_CREATE" | jq -r '.source // empty')"
assert_eq "agent sessions: create label matches" "Integration test session" "$(echo "$SESSION_CREATE" | jq -r '.label // empty')"
assert_eq "agent sessions: create expiry matches" "$SESSION_EXPIRES" "$(echo "$SESSION_CREATE" | jq -r '.expires_at // empty')"

SESSION_LIST=$(api GET /agent/sessions)
assert_true "agent sessions: list contains created session" "$(echo "$SESSION_LIST" | jq -r --arg sid "$SESSION_ID" 'any(.session_id == $sid)')"

SESSION_GET=$(api GET "/agent/sessions/$SESSION_ID")
assert_eq "agent sessions: get returns created session" "$SESSION_ID" "$(echo "$SESSION_GET" | jq -r '.session_id // empty')"

echo
echo "== Agent tasks =="

TASK_BODY=$(jq -n --arg session_id "$SESSION_ID" --arg source "codex" --arg user_prompt "Inspect the current plugin foundation" \
	'{session_id: $session_id, source: $source, user_prompt: $user_prompt}')
TASK_CREATE=$(api POST /agent/tasks "$TASK_BODY")
TASK_ID=$(echo "$TASK_CREATE" | jq -r '.task_id // empty')

assert_true "agent tasks: create returns a UUID" "$(echo "$TASK_CREATE" | jq -r '(.task_id // "") | test("^[a-f0-9-]{36}$")')"
assert_eq "agent tasks: belongs to session" "$SESSION_ID" "$(echo "$TASK_CREATE" | jq -r '.session_id // empty')"
assert_eq "agent tasks: initial status is draft" "draft" "$(echo "$TASK_CREATE" | jq -r '.status // empty')"

TASK_GET=$(api GET "/agent/tasks/$TASK_ID")
assert_eq "agent tasks: get returns created task" "$TASK_ID" "$(echo "$TASK_GET" | jq -r '.task_id // empty')"

TASK_LIST=$(api GET /agent/tasks)
assert_true "agent tasks: list contains created task" "$(echo "$TASK_LIST" | jq -r --arg tid "$TASK_ID" 'any(.task_id == $tid)')"

TASK_STATUS=$(api POST "/agent/tasks/$TASK_ID/status" '{"status":"analyzing"}')
assert_eq "agent tasks: status updates to analyzing" "analyzing" "$(echo "$TASK_STATUS" | jq -r '.status // empty')"

INVALID_TASK=$(api POST /agent/tasks '{"session_id":"00000000-0000-4000-8000-000000000000","source":"api","user_prompt":"Invalid session test"}')
assert_eq "agent tasks: invalid session returns WP_Error" "wpcc_session_not_found" "$(echo "$INVALID_TASK" | jq -r '.code // empty')"

echo
echo "== Agent plans =="

PATCH_COUNT_BEFORE_PLAN=$(api GET /patches | jq 'length')
PLAN_BODY=$(jq -n --arg session_id "$SESSION_ID" --arg task_id "$TASK_ID" \
	'{session_id:$session_id,task_id:$task_id,title:"Foundation verification plan",objective:"Verify the runtime before patch generation",steps:[{title:"Inspect context",description:"Review metadata and diagnostics."},{title:"Prepare proposal",description:"Document the intended change."}]}')
PLAN_CREATE=$(api POST /agent/plans "$PLAN_BODY")
PLAN_ID=$(echo "$PLAN_CREATE" | jq -r '.plan_id // empty')

assert_true "agent plans: create returns a UUID" "$(echo "$PLAN_CREATE" | jq -r '(.plan_id // "") | test("^[a-f0-9-]{36}$")')"
assert_eq "agent plans: belongs to session" "$SESSION_ID" "$(echo "$PLAN_CREATE" | jq -r '.session_id // empty')"
assert_eq "agent plans: belongs to task" "$TASK_ID" "$(echo "$PLAN_CREATE" | jq -r '.task_id // empty')"
assert_eq "agent plans: default status is pending_review" "pending_review" "$(echo "$PLAN_CREATE" | jq -r '.status // empty')"
assert_eq "agent plans: creates two steps" "2" "$(echo "$PLAN_CREATE" | jq -r '.steps | length')"
assert_eq "agent plans: steps are ordered" "1,2" "$(echo "$PLAN_CREATE" | jq -r '[.steps[].step_order] | join(",")')"
assert_true "agent plans: steps start pending" "$(echo "$PLAN_CREATE" | jq -r 'all(.steps[]; .status == "pending")')"

PLAN_GET=$(api GET "/agent/plans/$PLAN_ID")
assert_eq "agent plans: get returns created plan" "$PLAN_ID" "$(echo "$PLAN_GET" | jq -r '.plan_id // empty')"

PLAN_LIST=$(api GET /agent/plans)
assert_true "agent plans: list contains created plan" "$(echo "$PLAN_LIST" | jq -r --arg pid "$PLAN_ID" 'any(.plan_id == $pid)')"

PLAN_APPROVE=$(api POST "/agent/plans/$PLAN_ID/approve")
assert_eq "agent plans: approve updates status" "approved" "$(echo "$PLAN_APPROVE" | jq -r '.status // empty')"

TASK_WITH_PLANS=$(api GET "/agent/tasks/$TASK_ID")
assert_true "agent plans: task context contains plan" "$(echo "$TASK_WITH_PLANS" | jq -r --arg pid "$PLAN_ID" 'any(.plans[]; .plan_id == $pid)')"

PATCH_COUNT_AFTER_PLAN=$(api GET /patches | jq 'length')
assert_eq "agent plans: creation does not create a patch" "$PATCH_COUNT_BEFORE_PLAN" "$PATCH_COUNT_AFTER_PLAN"

REJECT_PLAN_BODY=$(jq -n --arg session_id "$SESSION_ID" --arg task_id "$TASK_ID" \
	'{session_id:$session_id,task_id:$task_id,title:"Rejectable plan",objective:"Verify rejection",status:"pending_review",steps:[{title:"Single step",description:"No patch generation."}]}')
REJECT_PLAN=$(api POST /agent/plans "$REJECT_PLAN_BODY")
REJECT_PLAN_ID=$(echo "$REJECT_PLAN" | jq -r '.plan_id // empty')
assert_eq "agent plans: explicit pending_review status" "pending_review" "$(echo "$REJECT_PLAN" | jq -r '.status // empty')"

REJECT_PLAN_RESP=$(api POST "/agent/plans/$REJECT_PLAN_ID/reject")
assert_eq "agent plans: reject updates status" "rejected" "$(echo "$REJECT_PLAN_RESP" | jq -r '.status // empty')"

CANCEL_PLAN_BODY=$(jq -n --arg session_id "$SESSION_ID" --arg task_id "$TASK_ID" \
	'{session_id:$session_id,task_id:$task_id,title:"Cancellable plan",objective:"Verify cancellation",status:"draft",steps:[{title:"Single step",description:"No patch generation."}]}')
CANCEL_PLAN=$(api POST /agent/plans "$CANCEL_PLAN_BODY")
CANCEL_PLAN_ID=$(echo "$CANCEL_PLAN" | jq -r '.plan_id // empty')
assert_eq "agent plans: explicit draft status" "draft" "$(echo "$CANCEL_PLAN" | jq -r '.status // empty')"

CANCEL_PLAN_RESP=$(api POST "/agent/plans/$CANCEL_PLAN_ID/cancel")
assert_eq "agent plans: cancel updates status" "cancelled" "$(echo "$CANCEL_PLAN_RESP" | jq -r '.status // empty')"

INVALID_TRANSITION_RESP=$(api POST "/agent/plans/$PLAN_ID/reject")
assert_eq "agent plans: invalid status transition fails" "wpcc_invalid_plan_status" "$(echo "$INVALID_TRANSITION_RESP" | jq -r '.code // empty')"

INVALID_SESSION_PLAN=$(api POST /agent/plans '{"session_id":"00000000-0000-4000-8000-000000000000","task_id":"00000000-0000-4000-8000-000000000000","title":"Invalid","objective":"Invalid","steps":[{"title":"Invalid"}]}')
assert_eq "agent plans: invalid session fails" "wpcc_session_not_found" "$(echo "$INVALID_SESSION_PLAN" | jq -r '.code // empty')"

INVALID_TASK_PLAN=$(jq -n --arg session_id "$SESSION_ID" '{session_id:$session_id,task_id:"00000000-0000-4000-8000-000000000000",title:"Invalid",objective:"Invalid",steps:[{title:"Invalid"}]}')
INVALID_TASK_PLAN_RESP=$(api POST /agent/plans "$INVALID_TASK_PLAN")
assert_eq "agent plans: invalid task fails" "wpcc_task_not_found" "$(echo "$INVALID_TASK_PLAN_RESP" | jq -r '.code // empty')"

PLAN_SECOND_SESSION=$(api POST /agent/sessions '{"source":"api","label":"Plan mismatch session"}')
PLAN_SECOND_SESSION_ID=$(echo "$PLAN_SECOND_SESSION" | jq -r '.session_id // empty')
MISMATCH_PLAN=$(jq -n --arg session_id "$PLAN_SECOND_SESSION_ID" --arg task_id "$TASK_ID" '{session_id:$session_id,task_id:$task_id,title:"Mismatch",objective:"Mismatch",steps:[{title:"Mismatch"}]}')
MISMATCH_PLAN_RESP=$(api POST /agent/plans "$MISMATCH_PLAN")
assert_eq "agent plans: task/session mismatch fails" "wpcc_task_session_mismatch" "$(echo "$MISMATCH_PLAN_RESP" | jq -r '.code // empty')"

echo
echo "== Patch relationships =="

LINKED_PATCH_BODY=$(jq -n --arg path "$FIXTURE_REL" --arg modified $'v3\n' --arg session_id "$SESSION_ID" --arg task_id "$TASK_ID" \
	'{files:[{path:$path,modified:$modified}],explanation:"Linked integration test patch",risk_level:"low",source:"codex",session_id:$session_id,task_id:$task_id}')
LINKED_PATCH=$(api POST /patches "$LINKED_PATCH_BODY")
LINKED_PATCH_ID=$(echo "$LINKED_PATCH" | jq -r '.id // empty')

assert_eq "patch relationships: create exposes session_id" "$SESSION_ID" "$(echo "$LINKED_PATCH" | jq -r '.session_id // empty')"
assert_eq "patch relationships: create exposes task_id" "$TASK_ID" "$(echo "$LINKED_PATCH" | jq -r '.task_id // empty')"

LINKED_DETAIL=$(api GET "/patches/$LINKED_PATCH_ID")
assert_eq "patch relationships: detail exposes session_id" "$SESSION_ID" "$(echo "$LINKED_DETAIL" | jq -r '.session_id // empty')"
assert_eq "patch relationships: detail exposes task_id" "$TASK_ID" "$(echo "$LINKED_DETAIL" | jq -r '.task_id // empty')"

PATCH_LIST=$(api GET /patches)
assert_eq "patch relationships: list exposes session_id" "$SESSION_ID" "$(echo "$PATCH_LIST" | jq -r --arg pid "$LINKED_PATCH_ID" '.[] | select(.id == $pid) | .session_id')"
assert_eq "patch relationships: list exposes task_id" "$TASK_ID" "$(echo "$PATCH_LIST" | jq -r --arg pid "$LINKED_PATCH_ID" '.[] | select(.id == $pid) | .task_id')"

INVALID_SESSION_PATCH=$(jq -n --arg path "$FIXTURE_REL" --arg modified $'v4\n' '{files:[{path:$path,modified:$modified}],session_id:"00000000-0000-4000-8000-000000000000"}')
INVALID_SESSION_RESP=$(api POST /patches "$INVALID_SESSION_PATCH")
assert_eq "patch relationships: invalid session fails" "wpcc_session_not_found" "$(echo "$INVALID_SESSION_RESP" | jq -r '.code // empty')"

INVALID_TASK_PATCH=$(jq -n --arg path "$FIXTURE_REL" --arg modified $'v4\n' --arg session_id "$SESSION_ID" '{files:[{path:$path,modified:$modified}],session_id:$session_id,task_id:"00000000-0000-4000-8000-000000000000"}')
INVALID_TASK_RESP=$(api POST /patches "$INVALID_TASK_PATCH")
assert_eq "patch relationships: invalid task fails" "wpcc_task_not_found" "$(echo "$INVALID_TASK_RESP" | jq -r '.code // empty')"

SECOND_SESSION=$(api POST /agent/sessions '{"source":"api","label":"Relationship mismatch session"}')
SECOND_SESSION_ID=$(echo "$SECOND_SESSION" | jq -r '.session_id // empty')
MISMATCH_PATCH=$(jq -n --arg path "$FIXTURE_REL" --arg modified $'v4\n' --arg session_id "$SECOND_SESSION_ID" --arg task_id "$TASK_ID" '{files:[{path:$path,modified:$modified}],session_id:$session_id,task_id:$task_id}')
MISMATCH_RESP=$(api POST /patches "$MISMATCH_PATCH")
assert_eq "patch relationships: task/session mismatch fails" "wpcc_task_session_mismatch" "$(echo "$MISMATCH_RESP" | jq -r '.code // empty')"

echo
echo "== Plan-linked patches =="

PLAN_PATCH_BODY=$(jq -n --arg path "$FIXTURE_REL" --arg modified $'v5\n' --arg plan_id "$PLAN_ID" \
	'{files:[{path:$path,modified:$modified}],explanation:"Plan-linked integration test patch",risk_level:"low",source:"codex",plan_id:$plan_id}')
PLAN_PATCH=$(api POST /patches "$PLAN_PATCH_BODY")
PLAN_PATCH_ID=$(echo "$PLAN_PATCH" | jq -r '.id // empty')

assert_eq "plan-linked patches: create exposes plan_id" "$PLAN_ID" "$(echo "$PLAN_PATCH" | jq -r '.plan_id // empty')"
assert_eq "plan-linked patches: session_id derived from plan" "$SESSION_ID" "$(echo "$PLAN_PATCH" | jq -r '.session_id // empty')"
assert_eq "plan-linked patches: task_id derived from plan" "$TASK_ID" "$(echo "$PLAN_PATCH" | jq -r '.task_id // empty')"

PLAN_PATCH_DETAIL=$(api GET "/patches/$PLAN_PATCH_ID")
assert_eq "plan-linked patches: detail exposes plan_id" "$PLAN_ID" "$(echo "$PLAN_PATCH_DETAIL" | jq -r '.plan_id // empty')"

PLAN_PATCH_LIST=$(api GET /patches)
assert_eq "plan-linked patches: list exposes plan_id" "$PLAN_ID" "$(echo "$PLAN_PATCH_LIST" | jq -r --arg pid "$PLAN_PATCH_ID" '.[] | select(.id == $pid) | .plan_id')"

UNKNOWN_PLAN_PATCH=$(jq -n --arg path "$FIXTURE_REL" --arg modified $'v5\n' \
	'{files:[{path:$path,modified:$modified}],plan_id:"00000000-0000-4000-8000-000000000000"}')
UNKNOWN_PLAN_RESP=$(api POST /patches "$UNKNOWN_PLAN_PATCH")
assert_eq "plan-linked patches: unknown plan fails" "wpcc_plan_not_found" "$(echo "$UNKNOWN_PLAN_RESP" | jq -r '.code // empty')"

REJECTED_PLAN_PATCH=$(jq -n --arg path "$FIXTURE_REL" --arg modified $'v5\n' --arg plan_id "$REJECT_PLAN_ID" \
	'{files:[{path:$path,modified:$modified}],plan_id:$plan_id}')
REJECTED_PLAN_RESP=$(api POST /patches "$REJECTED_PLAN_PATCH")
assert_eq "plan-linked patches: rejected plan fails" "wpcc_plan_not_approved" "$(echo "$REJECTED_PLAN_RESP" | jq -r '.code // empty')"

CANCELLED_PLAN_PATCH=$(jq -n --arg path "$FIXTURE_REL" --arg modified $'v5\n' --arg plan_id "$CANCEL_PLAN_ID" \
	'{files:[{path:$path,modified:$modified}],plan_id:$plan_id}')
CANCELLED_PLAN_RESP=$(api POST /patches "$CANCELLED_PLAN_PATCH")
assert_eq "plan-linked patches: cancelled plan fails" "wpcc_plan_not_approved" "$(echo "$CANCELLED_PLAN_RESP" | jq -r '.code // empty')"

DRAFT_PLAN_BODY=$(jq -n --arg session_id "$SESSION_ID" --arg task_id "$TASK_ID" \
	'{session_id:$session_id,task_id:$task_id,title:"Draft plan for patch link test",objective:"Verify draft plans cannot be linked",status:"draft",steps:[{title:"Single step",description:"No patch generation."}]}')
DRAFT_PLAN=$(api POST /agent/plans "$DRAFT_PLAN_BODY")
DRAFT_PLAN_ID=$(echo "$DRAFT_PLAN" | jq -r '.plan_id // empty')

DRAFT_PLAN_PATCH=$(jq -n --arg path "$FIXTURE_REL" --arg modified $'v5\n' --arg plan_id "$DRAFT_PLAN_ID" \
	'{files:[{path:$path,modified:$modified}],plan_id:$plan_id}')
DRAFT_PLAN_RESP=$(api POST /patches "$DRAFT_PLAN_PATCH")
assert_eq "plan-linked patches: draft plan fails" "wpcc_plan_not_approved" "$(echo "$DRAFT_PLAN_RESP" | jq -r '.code // empty')"

PENDING_PLAN_BODY=$(jq -n --arg session_id "$SESSION_ID" --arg task_id "$TASK_ID" \
	'{session_id:$session_id,task_id:$task_id,title:"Pending review plan for patch link test",objective:"Verify pending_review plans cannot be linked",steps:[{title:"Single step",description:"No patch generation."}]}')
PENDING_PLAN=$(api POST /agent/plans "$PENDING_PLAN_BODY")
PENDING_PLAN_ID=$(echo "$PENDING_PLAN" | jq -r '.plan_id // empty')

PENDING_PLAN_PATCH=$(jq -n --arg path "$FIXTURE_REL" --arg modified $'v5\n' --arg plan_id "$PENDING_PLAN_ID" \
	'{files:[{path:$path,modified:$modified}],plan_id:$plan_id}')
PENDING_PLAN_RESP=$(api POST /patches "$PENDING_PLAN_PATCH")
assert_eq "plan-linked patches: pending_review plan fails" "wpcc_plan_not_approved" "$(echo "$PENDING_PLAN_RESP" | jq -r '.code // empty')"

echo
echo "== Agent context =="

AGENT_CONTEXT=$(api GET "/agent/context?session_id=$SESSION_ID")
assert_true "agent context: has required top-level fields" "$(echo "$AGENT_CONTEXT" | jq -r '[has("health"),has("capabilities"),has("site_summary"),has("context"),has("recent_patches"),has("recent_audit_entries")] | all')"
assert_eq "agent context: session matches" "$SESSION_ID" "$(echo "$AGENT_CONTEXT" | jq -r '.session.session_id // empty')"
assert_true "agent context: session_tasks contains task" "$(echo "$AGENT_CONTEXT" | jq -r --arg tid "$TASK_ID" 'any(.session_tasks[]; .task_id == $tid)')"
assert_true "agent context: session_plans contains plan" "$(echo "$AGENT_CONTEXT" | jq -r --arg pid "$PLAN_ID" 'any(.session_plans[]; .plan_id == $pid)')"
assert_true "agent context: session_patches contains linked patch" "$(echo "$AGENT_CONTEXT" | jq -r --arg pid "$LINKED_PATCH_ID" 'any(.session_patches[]; .id == $pid)')"
assert_true "agent context: recent patches capped at 10" "$(echo "$AGENT_CONTEXT" | jq -r '.recent_patches | length <= 10')"
assert_true "agent context: recent audit entries capped at 20" "$(echo "$AGENT_CONTEXT" | jq -r '.recent_audit_entries | length <= 20')"
assert_eq "agent context: files excluded by default" "false" "$(echo "$AGENT_CONTEXT" | jq -r '.context | has("file_access")')"
assert_eq "agent context: diagnostics included by default" "true" "$(echo "$AGENT_CONTEXT" | jq -r '.context | has("diagnostics")')"
assert_eq "agent context: no file contents exposed" "0" "$(echo "$AGENT_CONTEXT" | jq '[.. | objects | select(has("contents"))] | length')"

AGENT_CONTEXT_OPTIONS=$(api GET "/agent/context?include_files=true&include_diagnostics=false")
assert_eq "agent context: include_files adds metadata" "true" "$(echo "$AGENT_CONTEXT_OPTIONS" | jq -r '.context | has("file_access")')"
assert_eq "agent context: diagnostics can be excluded" "false" "$(echo "$AGENT_CONTEXT_OPTIONS" | jq -r '.context | has("diagnostics")')"
assert_eq "agent context: file option still exposes no contents" "0" "$(echo "$AGENT_CONTEXT_OPTIONS" | jq '[.. | objects | select(has("contents"))] | length')"

INVALID_AGENT_CONTEXT=$(api GET "/agent/context?session_id=00000000-0000-4000-8000-000000000000")
assert_eq "agent context: invalid session fails" "wpcc_session_not_found" "$(echo "$INVALID_AGENT_CONTEXT" | jq -r '.code // empty')"

api POST "/patches/$LINKED_PATCH_ID/reject" > /dev/null

SESSION_CLOSE=$(api POST "/agent/sessions/$SESSION_ID/close")
assert_eq "agent sessions: close status is closed" "closed" "$(echo "$SESSION_CLOSE" | jq -r '.status // empty')"

SESSION_GET_CLOSED=$(api GET "/agent/sessions/$SESSION_ID")
assert_eq "agent sessions: closed status persists" "closed" "$(echo "$SESSION_GET_CLOSED" | jq -r '.status // empty')"

EXPIRING_BODY=$(jq -n --arg source "api" --arg label "Expiring integration test session" --argjson expires_at "$(( $(date +%s) + 5 ))" \
	'{source: $source, label: $label, expires_at: $expires_at}')
EXPIRING_CREATE=$(api POST /agent/sessions "$EXPIRING_BODY")
EXPIRING_ID=$(echo "$EXPIRING_CREATE" | jq -r '.session_id // empty')
sleep 6
EXPIRING_GET=$(api GET "/agent/sessions/$EXPIRING_ID")
assert_eq "agent sessions: elapsed session becomes expired" "expired" "$(echo "$EXPIRING_GET" | jq -r '.status // empty')"

echo
echo "== Audit log =="

AUDIT_LOG="$WP_CONTENT_DIR/uploads/wpcc-audit/audit.log"

if [ -z "$PATCH_ID" ]; then
	fail "audit log: skipped, no patch id"
elif [ ! -r "$AUDIT_LOG" ]; then
	fail "audit log: file not readable at $AUDIT_LOG"
else
	for action in patch.created patch.approved patch.applied patch.rolled_back; do
		FOUND=$(jq -c --arg pid "$PATCH_ID" --arg action "$action" \
			'select(.action == $action and .context.patch_id == $pid)' "$AUDIT_LOG" | tail -1)

		if [ -n "$FOUND" ]; then
			pass "audit log: $action recorded for patch $PATCH_ID"
		else
			fail "audit log: $action NOT found for patch $PATCH_ID"
		fi
	done

	for action in task.created task.status_updated; do
		FOUND=$(jq -c --arg tid "$TASK_ID" --arg action "$action" \
			'select(.action == $action and .context.task_id == $tid)' "$AUDIT_LOG" | tail -1)

		if [ -n "$FOUND" ]; then
			pass "audit log: $action recorded for task $TASK_ID"
		else
			fail "audit log: $action NOT found for task $TASK_ID"
		fi
	done

	for action in plan.created plan.approved plan.rejected plan.cancelled; do
		FOUND=$(jq -c --arg pid "$PLAN_ID" --arg cpid "$CANCEL_PLAN_ID" --arg rpid "$REJECT_PLAN_ID" --arg action "$action" \
			'select(.action == $action and (.context.plan_id == $pid or .context.plan_id == $cpid or .context.plan_id == $rpid))' "$AUDIT_LOG" | tail -1)

		if [ -n "$FOUND" ]; then
			pass "audit log: $action recorded"
		else
			fail "audit log: $action NOT found"
		fi
	done

	PLAN_PATCH_AUDIT=$(jq -c --arg pid "$PLAN_PATCH_ID" \
		'select(.action == "patch.created" and .context.patch_id == $pid)' "$AUDIT_LOG" | tail -1)
	assert_eq "audit log: plan-linked patch.created has plan_id" "$PLAN_ID" "$(echo "$PLAN_PATCH_AUDIT" | jq -r '.context.plan_id // empty')"
	assert_eq "audit log: plan-linked patch.created has session_id" "$SESSION_ID" "$(echo "$PLAN_PATCH_AUDIT" | jq -r '.context.session_id // empty')"
	assert_eq "audit log: plan-linked patch.created has task_id" "$TASK_ID" "$(echo "$PLAN_PATCH_AUDIT" | jq -r '.context.task_id // empty')"
fi

echo
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"

[ "$FAIL" -eq 0 ]
