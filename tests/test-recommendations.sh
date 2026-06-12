#!/usr/bin/env bash
# Step 31 - Recommendation Engine integration suite.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
source "$PLUGIN_DIR/wpcc-env.sh"

PASS=0
FAIL=0
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

echo "== 1. Schema & Scan =="
wp eval 'global $wpdb; $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}wpcc_recommendations");' >/dev/null

SCAN1=$(api POST /recommendations/scan '{}')
SCAN_COUNT=$(echo "$SCAN1" | jq -r '.recommendations | length')
assert_true "scan creates recommendations" "$([ "$SCAN_COUNT" -gt 0 ] && echo true || echo false)"
assert_eq "scan created count matches response" "$SCAN_COUNT" "$(echo "$SCAN1" | jq -r '.created')"
assert_true "recommendation shape is complete" "$(echo "$SCAN1" | jq -r '[.recommendations[] | has("recommendation_id") and has("type") and has("severity") and has("title") and has("description") and has("impact") and has("suggested_action") and has("source") and has("status") and has("created_at") and has("updated_at")] | all')"
assert_true "types are valid" "$(echo "$SCAN1" | jq -r '[.recommendations[].type | IN("security","performance","woocommerce","operations","developer_experience","maintenance")] | all')"
assert_true "severities are valid" "$(echo "$SCAN1" | jq -r '[.recommendations[].severity | IN("info","low","medium","high","critical")] | all')"
assert_true "initial statuses are open" "$(echo "$SCAN1" | jq -r '[.recommendations[].status == "open"] | all')"

echo "== 2. Duplicate Prevention =="
SCAN2=$(api POST /recommendations/scan '{}')
assert_eq "duplicate scan creates no records" "0" "$(echo "$SCAN2" | jq -r '.created')"
assert_eq "duplicate scan preserves recommendation count" "$SCAN_COUNT" "$(api GET '/recommendations?status=open&limit=200' | jq -r 'length')"

RID_UPDATE=$(echo "$SCAN1" | jq -r '.recommendations[0].recommendation_id')
wp eval "global \$wpdb; \$wpdb->update(\"{\$wpdb->prefix}wpcc_recommendations\", ['severity' => 'info'], ['recommendation_id' => '$RID_UPDATE']);" >/dev/null
SCAN3=$(api POST /recommendations/scan '{}')
assert_true "scan updates changed matching recommendation" "$(echo "$SCAN3" | jq -r '.updated >= 1')"

echo "== 3. Read & Validation =="
RID_DISMISS=$(echo "$SCAN1" | jq -r '.recommendations[0].recommendation_id')
RID_RESOLVE=$(echo "$SCAN1" | jq -r '.recommendations[1].recommendation_id')
RID_CONVERT=$(echo "$SCAN1" | jq -r '.recommendations[2].recommendation_id')
DETAIL=$(api GET "/recommendations/$RID_DISMISS")
assert_eq "detail returns requested recommendation" "$RID_DISMISS" "$(echo "$DETAIL" | jq -r '.recommendation_id')"
assert_eq "invalid severity is rejected" "wpcc_invalid_recommendation_severity" "$(api GET '/recommendations?severity=urgent' | jq -r '.code')"
assert_eq "invalid status is rejected" "wpcc_invalid_recommendation_status" "$(api GET '/recommendations?status=unknown' | jq -r '.code')"

echo "== 4. Status Transitions =="
DISMISSED=$(api POST "/recommendations/$RID_DISMISS/dismiss" '{}')
assert_eq "dismiss recommendation" "dismissed" "$(echo "$DISMISSED" | jq -r '.status')"
assert_true "dismissed_at recorded" "$(echo "$DISMISSED" | jq -r '.dismissed_at != null')"
assert_eq "dismiss is strict" "wpcc_invalid_recommendation_status" "$(api POST "/recommendations/$RID_DISMISS/dismiss" '{}' | jq -r '.code')"

RESOLVED=$(api POST "/recommendations/$RID_RESOLVE/resolve" '{}')
assert_eq "resolve recommendation" "resolved" "$(echo "$RESOLVED" | jq -r '.status')"
assert_true "resolved_at recorded" "$(echo "$RESOLVED" | jq -r '.resolved_at != null')"

echo "== 5. Convert To Action =="
SESSION=$(api POST /agent/sessions '{"source":"api","label":"Recommendation test"}')
SID=$(echo "$SESSION" | jq -r '.session_id')
TASK=$(api POST /agent/tasks "{\"session_id\":\"$SID\",\"source\":\"api\",\"user_prompt\":\"Review recommendation\"}")
TID=$(echo "$TASK" | jq -r '.task_id')
CONVERTED=$(api POST "/recommendations/$RID_CONVERT/convert-to-action" "{\"session_id\":\"$SID\",\"task_id\":\"$TID\"}")
ACTION_ID=$(echo "$CONVERTED" | jq -r '.action.action_id')
assert_eq "recommendation converted status" "converted_to_action" "$(echo "$CONVERTED" | jq -r '.recommendation.status')"
assert_eq "converted action type" "recommendation" "$(echo "$CONVERTED" | jq -r '.action.type')"
assert_eq "converted action status" "proposed" "$(echo "$CONVERTED" | jq -r '.action.status')"
assert_eq "recommendation context links action" "$ACTION_ID" "$(echo "$CONVERTED" | jq -r '.recommendation.context.action_id')"
assert_eq "converted action links session" "$SID" "$(echo "$CONVERTED" | jq -r '.action.session_id')"
assert_eq "converted action links task" "$TID" "$(echo "$CONVERTED" | jq -r '.action.task_id')"

echo "== 6. Context, Manifest & Dashboard =="
CONTEXT=$(api GET /agent/context)
assert_true "context has open recommendations" "$(echo "$CONTEXT" | jq -r 'has("open_recommendations")')"
assert_true "context has critical recommendations" "$(echo "$CONTEXT" | jq -r 'has("critical_recommendations")')"
assert_true "context has recent recommendations" "$(echo "$CONTEXT" | jq -r 'has("recent_recommendations")')"
MANIFEST=$(api GET /agent/manifest)
assert_eq "manifest recommendation capability" "true" "$(echo "$MANIFEST" | jq -r '.capabilities.recommendations')"
assert_true "manifest recommendation statuses" "$(echo "$MANIFEST" | jq -r '.recommendation_statuses == ["open","converted_to_action","plan_created","approved","executing","resolved","dismissed"]')"
assert_true "manifest recommendation severities" "$(echo "$MANIFEST" | jq -r '.recommendation_severities == ["info","low","medium","high","critical"]')"
assert_eq "manifest has seven recommendation endpoints" "7" "$(echo "$MANIFEST" | jq -r '[.endpoints[] | select(.path | startswith("/recommendations"))] | length')"
assert_true "dashboard has open count card" "$(grep -q 'Open Recommendations' "$PLUGIN_DIR/includes/Admin/views/dashboard.php" && echo true || echo false)"
assert_true "dashboard has critical count card" "$(grep -q 'Critical Recommendations' "$PLUGIN_DIR/includes/Admin/views/dashboard.php" && echo true || echo false)"
assert_true "dashboard has recent panel" "$(grep -q 'Recent Recommendations' "$PLUGIN_DIR/includes/Admin/views/dashboard.php" && echo true || echo false)"

echo "== 7. Timeline & Audit =="
TIMELINE=$(api GET '/agent/timeline?limit=200')
assert_true "timeline has recommendation.created" "$(echo "$TIMELINE" | jq -r 'any(.[]; .label == "Recommendation created")')"
assert_true "timeline has recommendation.updated" "$(echo "$TIMELINE" | jq -r 'any(.[]; .label == "Recommendation updated")')"
assert_true "timeline has recommendation.dismissed" "$(echo "$TIMELINE" | jq -r 'any(.[]; .label == "Recommendation dismissed")')"
assert_true "timeline has recommendation.resolved" "$(echo "$TIMELINE" | jq -r 'any(.[]; .label == "Recommendation resolved")')"
assert_true "timeline has recommendation.converted_to_action" "$(echo "$TIMELINE" | jq -r 'any(.[]; .label == "Recommendation converted to action")')"

AUDIT_FILE=$(wp eval '$u=wp_upload_dir(); echo trailingslashit($u["basedir"])."wpcc-audit/audit.log";' 2>/dev/null)
assert_true "audit has scan started" "$(grep -q 'recommendation.scan.started' "$AUDIT_FILE" && echo true || echo false)"
assert_true "audit has scan completed" "$(grep -q 'recommendation.scan.completed' "$AUDIT_FILE" && echo true || echo false)"
assert_true "audit has recommendation.created" "$(grep -q 'recommendation.created' "$AUDIT_FILE" && echo true || echo false)"
assert_true "audit has recommendation.updated" "$(grep -q 'recommendation.updated' "$AUDIT_FILE" && echo true || echo false)"
assert_true "audit has recommendation.dismissed" "$(grep -q 'recommendation.dismissed' "$AUDIT_FILE" && echo true || echo false)"
assert_true "audit has recommendation.resolved" "$(grep -q 'recommendation.resolved' "$AUDIT_FILE" && echo true || echo false)"
assert_true "audit has recommendation.converted_to_action" "$(grep -q 'recommendation.converted_to_action' "$AUDIT_FILE" && echo true || echo false)"

echo
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
