#!/usr/bin/env bash
#
# End-to-end runtime validation suite for WP Command Center (Step 9).
#
# Walks the full agent runtime chain in a single continuous run:
#
#   Session -> Task -> Plan -> Plan Approval -> Patch Creation
#     -> Patch Approval -> Patch Apply -> Patch Rollback
#
# and verifies:
#
#   - session_id / task_id / plan_id / patch_id propagation at each stage
#   - audit log coverage for task.created, plan.created, plan.approved,
#     patch.created, patch.approved, patch.applied, patch.rolled_back,
#     including ID linkage where the audit context carries it
#   - rollback restores the patched file's exact original content
#   - all relationship links (session/task/plan/patch) remain valid
#     after rollback
#
# Requires: curl, jq, and wpcc-env.sh (sourced from this plugin's root)
# providing $WPCC_BASE and a *full*-scope $WPCC_TOKEN.
#
# Usage: bash tests/test-e2e-runtime.sh

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

FIXTURE_REL="mu-plugins/wpcc-test-e2e/fixture.txt"
FIXTURE_DIR="$WP_CONTENT_DIR/mu-plugins/wpcc-test-e2e"
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
echo "== 1. Session =="

SESSION_CREATE=$(api POST /agent/sessions '{"source":"codex","label":"E2E runtime validation session"}')
SESSION_ID=$(echo "$SESSION_CREATE" | jq -r '.session_id // empty')

assert_true "session: created with UUID" "$(echo "$SESSION_CREATE" | jq -r '(.session_id // "") | test("^[a-f0-9-]{36}$")')"
assert_eq "session: status is active" "active" "$(echo "$SESSION_CREATE" | jq -r '.status // empty')"

echo
echo "== 2. Task =="

TASK_BODY=$(jq -n --arg session_id "$SESSION_ID" \
	'{session_id:$session_id,source:"codex",user_prompt:"E2E: update fixture.txt via the patch engine"}')
TASK_CREATE=$(api POST /agent/tasks "$TASK_BODY")
TASK_ID=$(echo "$TASK_CREATE" | jq -r '.task_id // empty')

assert_true "task: created with UUID" "$(echo "$TASK_CREATE" | jq -r '(.task_id // "") | test("^[a-f0-9-]{36}$")')"
assert_eq "task: session_id propagation" "$SESSION_ID" "$(echo "$TASK_CREATE" | jq -r '.session_id // empty')"
assert_eq "task: initial status is draft" "draft" "$(echo "$TASK_CREATE" | jq -r '.status // empty')"

echo
echo "== 3. Plan =="

PLAN_BODY=$(jq -n --arg session_id "$SESSION_ID" --arg task_id "$TASK_ID" \
	'{session_id:$session_id,task_id:$task_id,title:"E2E fixture update",objective:"Update fixture.txt from v1 to v2 via the patch engine",steps:[{title:"Update fixture",description:"Change fixture.txt content from v1 to v2."}]}')
PLAN_CREATE=$(api POST /agent/plans "$PLAN_BODY")
PLAN_ID=$(echo "$PLAN_CREATE" | jq -r '.plan_id // empty')

assert_true "plan: created with UUID" "$(echo "$PLAN_CREATE" | jq -r '(.plan_id // "") | test("^[a-f0-9-]{36}$")')"
assert_eq "plan: session_id propagation" "$SESSION_ID" "$(echo "$PLAN_CREATE" | jq -r '.session_id // empty')"
assert_eq "plan: task_id propagation" "$TASK_ID" "$(echo "$PLAN_CREATE" | jq -r '.task_id // empty')"
assert_eq "plan: default status is pending_review" "pending_review" "$(echo "$PLAN_CREATE" | jq -r '.status // empty')"

echo
echo "== 4. Plan approval =="

PLAN_APPROVE=$(api POST "/agent/plans/$PLAN_ID/approve")
assert_eq "plan approval: status is approved" "approved" "$(echo "$PLAN_APPROVE" | jq -r '.status // empty')"

echo
echo "== 5. Patch creation =="

PATCH_BODY=$(jq -n --arg path "$FIXTURE_REL" --arg modified $'v2\n' --arg plan_id "$PLAN_ID" \
	'{files:[{path:$path,modified:$modified}],explanation:"E2E runtime validation patch",risk_level:"low",source:"codex",plan_id:$plan_id}')
PATCH_CREATE=$(api POST /patches "$PATCH_BODY")
PATCH_ID=$(echo "$PATCH_CREATE" | jq -r '.id // empty')

assert_eq "patch creation: status is pending_approval" "pending_approval" "$(echo "$PATCH_CREATE" | jq -r '.status // empty')"
assert_eq "patch creation: plan_id propagation" "$PLAN_ID" "$(echo "$PATCH_CREATE" | jq -r '.plan_id // empty')"
assert_eq "patch creation: session_id propagation" "$SESSION_ID" "$(echo "$PATCH_CREATE" | jq -r '.session_id // empty')"
assert_eq "patch creation: task_id propagation" "$TASK_ID" "$(echo "$PATCH_CREATE" | jq -r '.task_id // empty')"

if [ -z "$PATCH_ID" ]; then
	fail "patch creation: no patch id returned, aborting downstream checks"
	echo "$PATCH_CREATE"
else
	echo "  patch id: $PATCH_ID"

	echo
	echo "== 6. Patch approval =="

	PATCH_APPROVE=$(api POST "/patches/$PATCH_ID/approve")
	assert_eq "patch approval: status is approved" "approved" "$(echo "$PATCH_APPROVE" | jq -r '.status // empty')"

	echo
	echo "== 7. Patch apply =="

	PATCH_APPLY=$(api POST "/patches/$PATCH_ID/apply")
	assert_eq "patch apply: status is applied" "applied" "$(echo "$PATCH_APPLY" | jq -r '.status // empty')"
	assert_true "patch apply: verification passed" "$(echo "$PATCH_APPLY" | jq -r '.verification.passed')"
	assert_eq "patch apply: file on disk is v2" "v2" "$(cat "$FIXTURE_FILE")"

	echo
	echo "== 8. Patch rollback =="

	PATCH_ROLLBACK=$(api POST "/patches/$PATCH_ID/rollback")
	assert_eq "patch rollback: status is rolled_back" "rolled_back" "$(echo "$PATCH_ROLLBACK" | jq -r '.status // empty')"
	assert_true "patch rollback: all files verified" "$(echo "$PATCH_ROLLBACK" | jq -r '.rollback_results | all(.verified == true)')"
	assert_eq "patch rollback: file on disk restored to v1" "v1" "$(cat "$FIXTURE_FILE")"

	echo
	echo "== 9. Relationship links after rollback =="

	PATCH_DETAIL=$(api GET "/patches/$PATCH_ID")
	assert_eq "post-rollback: patch status is rolled_back" "rolled_back" "$(echo "$PATCH_DETAIL" | jq -r '.status // empty')"
	assert_eq "post-rollback: patch session_id intact" "$SESSION_ID" "$(echo "$PATCH_DETAIL" | jq -r '.session_id // empty')"
	assert_eq "post-rollback: patch task_id intact" "$TASK_ID" "$(echo "$PATCH_DETAIL" | jq -r '.task_id // empty')"
	assert_eq "post-rollback: patch plan_id intact" "$PLAN_ID" "$(echo "$PATCH_DETAIL" | jq -r '.plan_id // empty')"

	PLAN_DETAIL=$(api GET "/agent/plans/$PLAN_ID")
	assert_eq "post-rollback: plan status remains approved" "approved" "$(echo "$PLAN_DETAIL" | jq -r '.status // empty')"
	assert_eq "post-rollback: plan session_id intact" "$SESSION_ID" "$(echo "$PLAN_DETAIL" | jq -r '.session_id // empty')"
	assert_eq "post-rollback: plan task_id intact" "$TASK_ID" "$(echo "$PLAN_DETAIL" | jq -r '.task_id // empty')"

	TASK_DETAIL=$(api GET "/agent/tasks/$TASK_ID")
	assert_eq "post-rollback: task session_id intact" "$SESSION_ID" "$(echo "$TASK_DETAIL" | jq -r '.session_id // empty')"
	assert_true "post-rollback: task plans includes plan" "$(echo "$TASK_DETAIL" | jq -r --arg pid "$PLAN_ID" 'any(.plans[]; .plan_id == $pid)')"

	SESSION_DETAIL=$(api GET "/agent/sessions/$SESSION_ID")
	assert_eq "post-rollback: session still resolvable" "$SESSION_ID" "$(echo "$SESSION_DETAIL" | jq -r '.session_id // empty')"

	AGENT_CONTEXT=$(api GET "/agent/context?session_id=$SESSION_ID")
	assert_true "post-rollback: agent context session_tasks contains task" "$(echo "$AGENT_CONTEXT" | jq -r --arg tid "$TASK_ID" 'any(.session_tasks[]; .task_id == $tid)')"
	assert_true "post-rollback: agent context session_plans contains plan" "$(echo "$AGENT_CONTEXT" | jq -r --arg pid "$PLAN_ID" 'any(.session_plans[]; .plan_id == $pid)')"
	assert_true "post-rollback: agent context session_patches contains patch" "$(echo "$AGENT_CONTEXT" | jq -r --arg pid "$PATCH_ID" 'any(.session_patches[]; .id == $pid)')"
fi

echo
echo "== 10. Audit log =="

AUDIT_LOG="$WP_CONTENT_DIR/uploads/wpcc-audit/audit.log"

if [ -z "$PATCH_ID" ]; then
	fail "audit log: skipped, no patch id"
elif [ ! -r "$AUDIT_LOG" ]; then
	fail "audit log: file not readable at $AUDIT_LOG"
else
	TASK_CREATED=$(jq -c --arg tid "$TASK_ID" \
		'select(.action == "task.created" and .context.task_id == $tid)' "$AUDIT_LOG" | tail -1)
	assert_true "audit log: task.created recorded" "$(present "$TASK_CREATED")"
	assert_eq "audit log: task.created links session_id" "$SESSION_ID" "$(echo "$TASK_CREATED" | jq -r '.context.session_id // empty')"

	PLAN_CREATED=$(jq -c --arg pid "$PLAN_ID" \
		'select(.action == "plan.created" and .context.plan_id == $pid)' "$AUDIT_LOG" | tail -1)
	assert_true "audit log: plan.created recorded" "$(present "$PLAN_CREATED")"
	assert_eq "audit log: plan.created links session_id" "$SESSION_ID" "$(echo "$PLAN_CREATED" | jq -r '.context.session_id // empty')"
	assert_eq "audit log: plan.created links task_id" "$TASK_ID" "$(echo "$PLAN_CREATED" | jq -r '.context.task_id // empty')"

	PLAN_APPROVED=$(jq -c --arg pid "$PLAN_ID" \
		'select(.action == "plan.approved" and .context.plan_id == $pid)' "$AUDIT_LOG" | tail -1)
	assert_true "audit log: plan.approved recorded" "$(present "$PLAN_APPROVED")"
	assert_eq "audit log: plan.approved status is approved" "approved" "$(echo "$PLAN_APPROVED" | jq -r '.context.status // empty')"

	PATCH_CREATED=$(jq -c --arg pid "$PATCH_ID" \
		'select(.action == "patch.created" and .context.patch_id == $pid)' "$AUDIT_LOG" | tail -1)
	assert_true "audit log: patch.created recorded" "$(present "$PATCH_CREATED")"
	assert_eq "audit log: patch.created links session_id" "$SESSION_ID" "$(echo "$PATCH_CREATED" | jq -r '.context.session_id // empty')"
	assert_eq "audit log: patch.created links task_id" "$TASK_ID" "$(echo "$PATCH_CREATED" | jq -r '.context.task_id // empty')"
	assert_eq "audit log: patch.created links plan_id" "$PLAN_ID" "$(echo "$PATCH_CREATED" | jq -r '.context.plan_id // empty')"

	PATCH_APPROVED=$(jq -c --arg pid "$PATCH_ID" \
		'select(.action == "patch.approved" and .context.patch_id == $pid)' "$AUDIT_LOG" | tail -1)
	assert_true "audit log: patch.approved recorded" "$(present "$PATCH_APPROVED")"

	PATCH_APPLIED=$(jq -c --arg pid "$PATCH_ID" \
		'select(.action == "patch.applied" and .context.patch_id == $pid)' "$AUDIT_LOG" | tail -1)
	assert_true "audit log: patch.applied recorded" "$(present "$PATCH_APPLIED")"

	PATCH_ROLLED_BACK=$(jq -c --arg pid "$PATCH_ID" \
		'select(.action == "patch.rolled_back" and .context.patch_id == $pid)' "$AUDIT_LOG" | tail -1)
	assert_true "audit log: patch.rolled_back recorded" "$(present "$PATCH_ROLLED_BACK")"
fi

echo
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"

[ "$FAIL" -eq 0 ]
