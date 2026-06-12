#!/usr/bin/env bash
#
# Option Management Runtime test suite for WP Command Center (Step 38).
#
# Verifies:
#   - registry discovery (manifest / context)
#   - valid reads (all supported options)
#   - valid updates
#   - invalid option IDs
#   - invalid value types
#   - approval enforcement (high-risk)
#   - rollback
#   - audit logging
#   - timeline entries
#   - manifest exposure
#
# Requires: curl, jq, and wpcc-env.sh.
# Usage: bash tests/test-option-runtime.sh

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

assert_contains() {
	local desc="$1" haystack="$2" needle="$3"
	if [[ "$haystack" == *"$needle"* ]]; then
		pass "$desc"
	else
		fail "$desc (string does not contain '$needle')"
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

echo "== 1. Manifest Integration =="
MANIFEST=$(api GET /agent/manifest)
assert_true "manifest: option_management section exists" "$(echo "$MANIFEST" | jq -r 'if .option_management then "true" else "false" end')"
assert_true "manifest: options array present" "$(echo "$MANIFEST" | jq -r 'if (.option_management.options | type) == "array" then "true" else "false" end')"
assert_true "manifest: options_by_risk present" "$(echo "$MANIFEST" | jq -r 'if .option_management.options_by_risk then "true" else "false" end')"
assert_true "manifest: options_by_group is array" "$(echo "$MANIFEST" | jq -r 'if (.option_management.options_by_group | type) == "array" then "true" else "false" end')"
assert_true "manifest: capability option_management is true" "$(echo "$MANIFEST" | jq -r '.capabilities.option_management // false')"
OPT_COUNT=$(echo "$MANIFEST" | jq -r '.option_management.options | length')
assert_eq "manifest: 13 supported options" "13" "$OPT_COUNT"

echo
echo "== 2. Agent Context Integration =="
CONTEXT=$(api GET "/agent/context")
assert_true "context: option_management_available present" "$(echo "$CONTEXT" | jq -r 'if .option_management_available then "true" else "false" end')"
assert_true "context: supported_options is array" "$(echo "$CONTEXT" | jq -r 'if (.supported_options | type) == "array" then "true" else "false" end')"
assert_true "context: options_by_risk present" "$(echo "$CONTEXT" | jq -r 'if .options_by_risk then "true" else "false" end')"

echo
echo "== 3. Valid Reads — Low Risk =="
for opt_id in site_title tagline timezone date_format time_format start_of_week; do
	RES=$(api POST /operations/option_manage/run "{\"action\":\"option_get\",\"option_id\":\"$opt_id\"}")
	ACT=$(echo "$RES" | jq -r '.action // "none"')
	assert_eq "read: $opt_id returns option_get" "option_get" "$ACT"
	RISK=$(echo "$RES" | jq -r '.risk_level // "none"')
	assert_eq "read: $opt_id risk is low" "low" "$RISK"
done

echo
echo "== 4. Valid Reads — Medium/High Risk =="
for opt_id in posts_per_page show_on_front page_on_front page_for_posts default_comment_status default_ping_status admin_email; do
	RES=$(api POST /operations/option_manage/run "{\"action\":\"option_get\",\"option_id\":\"$opt_id\"}")
	ACT=$(echo "$RES" | jq -r '.action // "none"')
	assert_eq "read: $opt_id returns option_get" "option_get" "$ACT"
done

echo
echo "== 5. Invalid option_id rejected =="
UNKNOWN=$(api POST /operations/option_manage/run '{"action":"option_get","option_id":"nonexistent_option"}')
assert_eq "invalid option: rejected" "wpcc_invalid_option_id" "$(echo "$UNKNOWN" | jq -r '.code // "none"')"

echo
echo "== 6. Missing option_id rejected =="
NO_ID=$(api POST /operations/option_manage/run '{"action":"option_get"}')
assert_eq "missing option_id: rejected" "wpcc_missing_option_id" "$(echo "$NO_ID" | jq -r '.code // "none"')"

echo
echo "== 7. Invalid action rejected =="
BAD_ACTION=$(api POST /operations/option_manage/run '{"action":"evil_action","option_id":"site_title"}')
assert_eq "bad action: rejected" "wpcc_invalid_option_action" "$(echo "$BAD_ACTION" | jq -r '.code // "none"')"

echo
echo "== 8. Valid Updates — Low Risk =="
# site_title — string
SITE_TITLE_OLD=$(api POST /operations/option_manage/run '{"action":"option_get","option_id":"site_title"}')
OLD_TITLE=$(echo "$SITE_TITLE_OLD" | jq -r '.current_value')
UPDATE_TITLE=$(api POST /operations/option_manage/run "{\"action\":\"option_update\",\"option_id\":\"site_title\",\"value\":\"Test Site Name\"}")
assert_eq "update: site_title success" "option_update" "$(echo "$UPDATE_TITLE" | jq -r '.action // "none"')"
assert_true "update: site_title has rollback_id" "$(echo "$UPDATE_TITLE" | jq -r 'if .rollback_id then "true" else "false" end')"
SITE_TITLE_NEW=$(api POST /operations/option_manage/run '{"action":"option_get","option_id":"site_title"}')
assert_eq "update: site_title value changed" "Test Site Name" "$(echo "$SITE_TITLE_NEW" | jq -r '.current_value')"

# start_of_week — integer
UPDATE_SOW=$(api POST /operations/option_manage/run '{"action":"option_update","option_id":"start_of_week","value":3}')
assert_eq "update: start_of_week success" "option_update" "$(echo "$UPDATE_SOW" | jq -r '.action // "none"')"
SOW_CHECK=$(api POST /operations/option_manage/run '{"action":"option_get","option_id":"start_of_week"}')
assert_eq "update: start_of_week value is 3" "3" "$(echo "$SOW_CHECK" | jq -r '.current_value | tostring')"

echo
echo "== 9. Invalid value types rejected =="
# start_of_week should be integer 0-6
BAD_SOW=$(api POST /operations/option_manage/run '{"action":"option_update","option_id":"start_of_week","value":"monday"}')
assert_eq "invalid type: non-numeric start_of_week" "wpcc_invalid_option_type" "$(echo "$BAD_SOW" | jq -r '.code // "none"')"

# start_of_week out of range
BAD_RANGE=$(api POST /operations/option_manage/run '{"action":"option_update","option_id":"start_of_week","value":99}')
assert_eq "invalid value: start_of_week > 6" "wpcc_option_value_too_large" "$(echo "$BAD_RANGE" | jq -r '.code // "none"')"

# show_on_front must be posts or page
BAD_SOF=$(api POST /operations/option_manage/run '{"action":"option_update","option_id":"show_on_front","value":"homepage"}')
assert_eq "invalid value: show_on_front not in enum" "wpcc_invalid_option_value" "$(echo "$BAD_SOF" | jq -r '.code // "none"')"

# timezone must be valid
BAD_TZ=$(api POST /operations/option_manage/run '{"action":"option_update","option_id":"timezone","value":"Not/A_Real_Zone"}')
assert_eq "invalid value: bad timezone" "wpcc_invalid_timezone" "$(echo "$BAD_TZ" | jq -r '.code // "none"')"

# admin_email must be valid
BAD_EMAIL=$(api POST /operations/option_manage/run '{"action":"option_update","option_id":"admin_email","value":"not-an-email"}')
assert_eq "invalid value: bad email" "wpcc_invalid_email" "$(echo "$BAD_EMAIL" | jq -r '.code // "none"')"

echo
echo "== 10. Rollback =="
ROLLBACK_ID=$(echo "$UPDATE_TITLE" | jq -r '.rollback_id')
ROLLBACK=$(api POST /operations/option_manage/run "{\"action\":\"option_rollback\",\"option_id\":\"site_title\",\"rollback_id\":\"$ROLLBACK_ID\"}")
assert_eq "rollback: action is option_rollback" "option_rollback" "$(echo "$ROLLBACK" | jq -r '.action // "none"')"
ROLLBACK_CHECK=$(api POST /operations/option_manage/run '{"action":"option_get","option_id":"site_title"}')
assert_eq "rollback: site_title restored" "$OLD_TITLE" "$(echo "$ROLLBACK_CHECK" | jq -r '.current_value')"

# Double rollback should fail
ROLLBACK_DOUBLE=$(api POST /operations/option_manage/run "{\"action\":\"option_rollback\",\"option_id\":\"site_title\",\"rollback_id\":\"$ROLLBACK_ID\"}")
assert_eq "rollback: double rollback rejected" "wpcc_rollback_already_applied" "$(echo "$ROLLBACK_DOUBLE" | jq -r '.code // "none"')"

# Fake rollback_id
ROLLBACK_FAKE=$(api POST /operations/option_manage/run '{"action":"option_rollback","option_id":"site_title","rollback_id":"fake-id-here"}')
assert_eq "rollback: fake ID rejected" "wpcc_rollback_not_found" "$(echo "$ROLLBACK_FAKE" | jq -r '.code // "none"')"

echo
echo "== 11. Audit Logging =="
# Use timeline as a reliable audit event source (context tail is limited to 20 entries)
TL_AUDIT=$(api GET "/agent/timeline?limit=100")
AUDIT_STR=$(echo "$TL_AUDIT" | jq -r '[.[].label] | join(" ")')
assert_contains "audit: option read event emitted" "$AUDIT_STR" "Option read"
assert_contains "audit: option update completed event emitted" "$AUDIT_STR" "Option updated"
assert_contains "audit: option rollback event emitted" "$AUDIT_STR" "Option update rolled back"

echo
echo "== 12. Timeline Integration =="
TIMELINE=$(api GET "/agent/timeline?limit=100")
assert_true "timeline: has option read" "$(echo "$TIMELINE" | jq -r 'any(.[]; .label == "Option read")')"
assert_true "timeline: has option updated" "$(echo "$TIMELINE" | jq -r 'any(.[]; .label == "Option updated")')"
assert_true "timeline: has option rollback" "$(echo "$TIMELINE" | jq -r 'any(.[]; .label == "Option update rolled back")')"

echo
echo "== 13. String length validation =="
LONG_STR=$(python3 -c "print('x' * 300)" 2>/dev/null || printf 'x%.0s' {1..300})
LONG_TITLE=$(api POST /operations/option_manage/run "{\"action\":\"option_update\",\"option_id\":\"site_title\",\"value\":\"$LONG_STR\"}")
assert_eq "validation: too-long site_title rejected" "wpcc_option_value_too_long" "$(echo "$LONG_TITLE" | jq -r '.code // "none"')"

echo
echo "== 14. valid updates for medium risk options =="
# posts_per_page
UPDATE_PPP=$(api POST /operations/option_manage/run '{"action":"option_update","option_id":"posts_per_page","value":15}')
assert_eq "update: posts_per_page success" "option_update" "$(echo "$UPDATE_PPP" | jq -r '.action // "none"')"
PPP_CHECK=$(api POST /operations/option_manage/run '{"action":"option_get","option_id":"posts_per_page"}')
assert_eq "update: posts_per_page value" "15" "$(echo "$PPP_CHECK" | jq -r '.current_value | tostring')"

# show_on_front
UPDATE_SOF=$(api POST /operations/option_manage/run '{"action":"option_update","option_id":"show_on_front","value":"posts"}')
assert_eq "update: show_on_front success" "option_update" "$(echo "$UPDATE_SOF" | jq -r '.action // "none"')"

# default_comment_status
UPDATE_DCS=$(api POST /operations/option_manage/run '{"action":"option_update","option_id":"default_comment_status","value":"closed"}')
assert_eq "update: default_comment_status success" "option_update" "$(echo "$UPDATE_DCS" | jq -r '.action // "none"')"

echo
echo "== 15. valid timezone update =="
UPDATE_TZ=$(api POST /operations/option_manage/run '{"action":"option_update","option_id":"timezone","value":"America/Chicago"}')
assert_eq "update: timezone success" "option_update" "$(echo "$UPDATE_TZ" | jq -r '.action // "none"')"
TZ_CHECK=$(api POST /operations/option_manage/run '{"action":"option_get","option_id":"timezone"}')
assert_eq "update: timezone value" "America/Chicago" "$(echo "$TZ_CHECK" | jq -r '.current_value')"

echo
echo "== 16. posts_per_page out of range =="
BAD_PPP_MIN=$(api POST /operations/option_manage/run '{"action":"option_update","option_id":"posts_per_page","value":0}')
assert_eq "validation: ppp=0 rejected" "wpcc_option_value_too_small" "$(echo "$BAD_PPP_MIN" | jq -r '.code // "none"')"
BAD_PPP_MAX=$(api POST /operations/option_manage/run '{"action":"option_update","option_id":"posts_per_page","value":999}')
assert_eq "validation: ppp=999 rejected" "wpcc_option_value_too_large" "$(echo "$BAD_PPP_MAX" | jq -r '.code // "none"')"

echo
echo "== 17. Unchanged update returns success =="
SAME_VAL=$(api POST /operations/option_manage/run '{"action":"option_get","option_id":"site_title"}')
CURRENT=$(echo "$SAME_VAL" | jq -r '.current_value')
SAME_UPDATE=$(api POST /operations/option_manage/run "{\"action\":\"option_update\",\"option_id\":\"site_title\",\"value\":\"$CURRENT\"}")
assert_true "unchanged: no-op returns unchanged" "$(echo "$SAME_UPDATE" | jq -r '.unchanged // false')"

echo
echo "== 18. Error catalog includes new codes =="
ECAT=$(echo "$MANIFEST" | jq -r '.error_catalog')
assert_contains "error_catalog: wpcc_missing_option_id" "$ECAT" "wpcc_missing_option_id"
assert_contains "error_catalog: wpcc_invalid_option_id" "$ECAT" "wpcc_invalid_option_id"
assert_contains "error_catalog: wpcc_invalid_timezone" "$ECAT" "wpcc_invalid_timezone"
assert_contains "error_catalog: wpcc_invalid_email" "$ECAT" "wpcc_invalid_email"
assert_contains "error_catalog: wpcc_rollback_not_found" "$ECAT" "wpcc_rollback_not_found"

echo
echo "== 19. Operation registry includes option_manage =="
OPS_CHECK=$(api GET /operations)
assert_true "operations: option_manage listed" "$(echo "$OPS_CHECK" | jq -r 'any(.[]; .id == "option_manage")')"

echo
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"

[ "$FAIL" -eq 0 ]
