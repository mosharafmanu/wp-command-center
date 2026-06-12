#!/usr/bin/env bash
# Step 32 - Recommendation Workflow Engine integration suite.

set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
source "$PLUGIN_DIR/wpcc-env.sh"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { if [ "$2" = "$3" ]; then pass "$1"; else fail "$1 (expected '$2', got '$3')"; fi; }
assert_true() { if [ "$2" = "true" ]; then pass "$1"; else fail "$1"; fi; }
api() {
	local method="$1" path="$2" body="${3:-}"
	if [ -n "$body" ]; then
		curl -sS -X "$method" -H "Authorization: Bearer $WPCC_TOKEN" -H 'Content-Type: application/json' -d "$body" "$WPCC_BASE$path"
	else
		curl -sS -X "$method" -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE$path"
	fi
}

echo "== 1. Setup Recommendation, Session & Task =="
wp eval 'global $wpdb; $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}wpcc_recommendations");' >/dev/null
PATCH_COUNT_BEFORE=$(api GET /patches | jq -r 'length')
SCAN=$(api POST /recommendations/scan '{}')
RID=$(echo "$SCAN" | jq -r '.recommendations[0].recommendation_id')
SESSION=$(api POST /agent/sessions '{"source":"api","label":"Step 32 workflow"}')
SID=$(echo "$SESSION" | jq -r '.session_id')
TASK=$(api POST /agent/tasks "{\"session_id\":\"$SID\",\"source\":\"api\",\"user_prompt\":\"Execute recommendation workflow\"}")
TID=$(echo "$TASK" | jq -r '.task_id')
assert_true "scan produced recommendation" "$([ -n "$RID" ] && [ "$RID" != null ] && echo true || echo false)"
assert_true "session and task created" "$([ -n "$SID" ] && [ -n "$TID" ] && echo true || echo false)"

echo "== 2. Recommendation To Action =="
CONVERTED=$(api POST "/recommendations/$RID/convert-to-action" "{\"session_id\":\"$SID\",\"task_id\":\"$TID\"}")
AID=$(echo "$CONVERTED" | jq -r '.action.action_id')
assert_eq "status converted_to_action" "converted_to_action" "$(echo "$CONVERTED" | jq -r '.recommendation.status')"
assert_eq "recommendation action_id column exposed" "$AID" "$(echo "$CONVERTED" | jq -r '.recommendation.action_id')"
assert_eq "action is proposed" "proposed" "$(echo "$CONVERTED" | jq -r '.action.status')"
assert_eq "action type is recommendation" "recommendation" "$(echo "$CONVERTED" | jq -r '.action.type')"

echo "== 3. Create Plan =="
PLAN_BODY='{"title":"Resolve recommendation safely","objective":"Apply a reviewed deterministic remediation","steps":[{"title":"Review current condition","description":"Confirm the recommendation still applies."},{"title":"Execute approved remediation","description":"Use an approved operation only."}]}'
CREATED_PLAN=$(api POST "/recommendations/$RID/create-plan" "$PLAN_BODY")
PID=$(echo "$CREATED_PLAN" | jq -r '.plan.plan_id')
assert_eq "recommendation status plan_created" "plan_created" "$(echo "$CREATED_PLAN" | jq -r '.recommendation.status')"
assert_eq "recommendation plan_id column exposed" "$PID" "$(echo "$CREATED_PLAN" | jq -r '.recommendation.plan_id')"
assert_eq "plan links action" "$AID" "$(echo "$CREATED_PLAN" | jq -r '.plan.action_id')"
assert_eq "plan links session" "$SID" "$(echo "$CREATED_PLAN" | jq -r '.plan.session_id')"
assert_eq "plan links task" "$TID" "$(echo "$CREATED_PLAN" | jq -r '.plan.task_id')"
assert_eq "plan awaits human review" "pending_review" "$(echo "$CREATED_PLAN" | jq -r '.plan.status')"
assert_eq "plan has two steps" "2" "$(echo "$CREATED_PLAN" | jq -r '.plan.steps | length')"
assert_eq "duplicate plan creation blocked" "wpcc_invalid_recommendation_status" "$(api POST "/recommendations/$RID/create-plan" "$PLAN_BODY" | jq -r '.code')"

echo "== 4. Human Approval =="
APPROVED=$(api POST "/agent/plans/$PID/approve" '{}')
assert_eq "plan approved" "approved" "$(echo "$APPROVED" | jq -r '.status')"
REC_APPROVED=$(api GET "/recommendations/$RID")
assert_eq "recommendation follows plan approval" "approved" "$(echo "$REC_APPROVED" | jq -r '.status')"

echo "== 5. Approved Execution & Resolution =="
REQUEST_BODY=$(jq -nc --arg sid "$SID" --arg tid "$TID" --arg aid "$AID" --arg pid "$PID" '{operation_id:"content_seed",payload:{type:"post",count:1,status:"draft",title_pattern:"Recommendation Workflow {n}"},session_id:$sid,task_id:$tid,action_id:$aid,plan_id:$pid}')
REQUEST=$(api POST /operations/requests "$REQUEST_BODY")
REQ_ID=$(echo "$REQUEST" | jq -r '.request_id')
assert_eq "operation request links plan" "$PID" "$(echo "$REQUEST" | jq -r '.plan_id')"
api POST "/operations/requests/$REQ_ID/approve" '{}' >/dev/null
QUEUE=$(api GET "/operations/queue?request_id=$REQ_ID")
QID=$(echo "$QUEUE" | jq -r '.[0].queue_id')
assert_true "approval queued operation" "$([ -n "$QID" ] && [ "$QID" != null ] && echo true || echo false)"
RUN=$(api POST "/operations/queue/$QID/run" '{}')
assert_eq "queue execution completed" "completed" "$(echo "$RUN" | jq -r '.status')"
FINAL=$(api GET "/recommendations/$RID")
assert_eq "recommendation resolved after successful execution" "resolved" "$(echo "$FINAL" | jq -r '.status')"
assert_true "resolved_at recorded" "$(echo "$FINAL" | jq -r '.resolved_at != null')"

echo "== 6. Context, Dashboard & Safety =="
CONTEXT=$(api GET /agent/context)
assert_true "context has recommendation summary" "$(echo "$CONTEXT" | jq -r 'has("recommendation_summary")')"
assert_true "context summary has workflow buckets" "$(echo "$CONTEXT" | jq -r '.recommendation_summary | has("open") and has("awaiting_plan") and has("awaiting_approval") and has("in_progress") and has("resolved")')"
assert_true "resolved summary includes workflow record" "$(echo "$CONTEXT" | jq -r '.recommendation_summary.resolved >= 1')"
assert_true "dashboard has Awaiting Plan card" "$(grep -q 'Awaiting Plan' "$PLUGIN_DIR/includes/Admin/views/dashboard.php" && echo true || echo false)"
assert_true "dashboard has Awaiting Approval card" "$(grep -q 'Awaiting Approval' "$PLUGIN_DIR/includes/Admin/views/dashboard.php" && echo true || echo false)"
assert_true "dashboard has In Progress card" "$(grep -q 'In Progress' "$PLUGIN_DIR/includes/Admin/views/dashboard.php" && echo true || echo false)"
assert_true "dashboard has Resolved card" "$(grep -q '>Resolved<' "$PLUGIN_DIR/includes/Admin/views/dashboard.php" && echo true || echo false)"
PATCH_COUNT_AFTER=$(api GET /patches | jq -r 'length')
assert_eq "workflow created no patches" "$PATCH_COUNT_BEFORE" "$PATCH_COUNT_AFTER"

echo "== 7. Timeline & Audit =="
TIMELINE=$(api GET '/agent/timeline?limit=300')
assert_true "timeline action_created" "$(echo "$TIMELINE" | jq -r 'any(.[]; .label == "Recommendation action created")')"
assert_true "timeline plan_created" "$(echo "$TIMELINE" | jq -r 'any(.[]; .label == "Recommendation plan created")')"
assert_true "timeline approved" "$(echo "$TIMELINE" | jq -r 'any(.[]; .label == "Recommendation approved")')"
assert_true "timeline executing" "$(echo "$TIMELINE" | jq -r 'any(.[]; .label == "Recommendation executing")')"
assert_true "timeline resolved" "$(echo "$TIMELINE" | jq -r 'any(.[]; .label == "Recommendation resolved")')"
AUDIT_FILE=$(wp eval '$u=wp_upload_dir(); echo trailingslashit($u["basedir"])."wpcc-audit/audit.log";' 2>/dev/null)
for event in recommendation.action_created recommendation.plan_created recommendation.approved recommendation.executing recommendation.resolved; do
	assert_true "audit has $event" "$(grep -q "$event" "$AUDIT_FILE" && echo true || echo false)"
done

echo
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
