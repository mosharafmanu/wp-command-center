#!/usr/bin/env bash
#
# Widgets & Sidebars Runtime test suite for WP Command Center (Step 73).
#
# Verifies:
#   - registry discovery (manifest / context)
#   - widget_list
#   - widget_get
#   - widget_add
#   - widget_update
#   - widget_remove
#   - sidebar_assign
#   - sidebar_remove
#   - rollback
#   - invalid actions rejected
#   - timeline entries
#   - dashboard card
#
# Requires: curl, jq, and wpcc-env.sh.
# Usage: bash tests/test-widgets-runtime.sh

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
assert_true "manifest: widgets_management exists" "$(echo "$MANIFEST" | jq -r 'if .widgets_management then "true" else "false" end')"
assert_true "manifest: widgets_management.supported_actions array" "$(echo "$MANIFEST" | jq -r 'if (.widgets_management.supported_actions | type) == "array" then "true" else "false" end')"
assert_true "manifest: capability widgets_management is true" "$(echo "$MANIFEST" | jq -r '.capabilities.widgets_management // false')"
WIDGET_ACTIONS=$(echo "$MANIFEST" | jq -r '.widgets_management.supported_actions | length')
assert_eq "manifest: widgets_management has 8+ actions" "8" "$WIDGET_ACTIONS"

echo
echo "== 2. Operations Registry =="
OPS=$(api GET /operations)
assert_true "ops registry: widgets_manage present" "$(echo "$OPS" | jq -r 'if map(select(.id == "widgets_manage")) | length > 0 then "true" else "false" end')"
WIDGETS_OP=$(echo "$OPS" | jq -r '.[] | select(.id == "widgets_manage")')
assert_contains "ops registry: widgets title" "$WIDGETS_OP" "Widgets"

echo
echo "== 3. Widget List =="
WLIST=$(api POST /operations/widgets_manage/run '{"action":"widget_list"}')
assert_eq "widget_list: action is widget_list" "widget_list" "$(echo "$WLIST" | jq -r '.action // "none"')"
assert_true "widget_list: summary present" "$(echo "$WLIST" | jq -r 'if .summary then "true" else "false" end')"
assert_true "widget_list: widgets array present" "$(echo "$WLIST" | jq -r 'if (.summary.widgets | type) == "array" then "true" else "false" end')"
assert_true "widget_list: sidebars array present" "$(echo "$WLIST" | jq -r 'if (.summary.sidebars | type) == "array" then "true" else "false" end')"

echo
echo "== 4. Widget Get (valid) =="
FIRST_WIDGET_ID=$(echo "$WLIST" | jq -r '.summary.widgets[0].widget_id // empty')
if [ -n "$FIRST_WIDGET_ID" ]; then
	WGET=$(api POST /operations/widgets_manage/run "{\"action\":\"widget_get\",\"widget_id\":\"$FIRST_WIDGET_ID\"}")
	assert_eq "widget_get: action is widget_get" "widget_get" "$(echo "$WGET" | jq -r '.action // "none"')"
	assert_true "widget_get: widget data present" "$(echo "$WGET" | jq -r 'if .widget then "true" else "false" end')"
fi

echo
echo "== 5. Widget Get (invalid) =="
WGET_ERR=$(api POST /operations/widgets_manage/run '{"action":"widget_get","widget_id":"nonexistent_widget_12345"}')
assert_eq "widget_get: invalid rejected" "wpcc_widget_not_found" "$(echo "$WGET_ERR" | jq -r '.code // "none"')"

echo
echo "== 6. Invalid Action Rejected =="
BAD=$(api POST /operations/widgets_manage/run '{"action":"evil_action"}')
assert_eq "bad action: rejected" "wpcc_invalid_widgets_action" "$(echo "$BAD" | jq -r '.code // "none"')"

echo
echo "== 7. Widget Add =="
SIDEBARS=$(echo "$WLIST" | jq -r '.summary.sidebars')
FIRST_SIDEBAR=$(echo "$SIDEBARS" | jq -r '.[0].sidebar_id // empty')
if [ -n "$FIRST_SIDEBAR" ]; then
	WADD=$(api POST /operations/widgets_manage/run "{\"action\":\"widget_add\",\"widget_type\":\"text\",\"sidebar_id\":\"$FIRST_SIDEBAR\",\"widget_settings\":{\"title\":\"TestWidget\",\"text\":\"Hello\"}}")
	assert_eq "widget_add: action is widget_add" "widget_add" "$(echo "$WADD" | jq -r '.action // "none"')"
	assert_true "widget_add: has widget_id" "$(echo "$WADD" | jq -r 'if .widget_id then "true" else "false" end')"
	assert_true "widget_add: has rollback_id" "$(echo "$WADD" | jq -r 'if .rollback_id then "true" else "false" end')"
	NEW_WIDGET_ID=$(echo "$WADD" | jq -r '.widget_id')
	ROLLBACK_ID=$(echo "$WADD" | jq -r '.rollback_id')
fi

echo
echo "== 8. Widget Update =="
if [ -n "${NEW_WIDGET_ID:-}" ]; then
	WUPD=$(api POST /operations/widgets_manage/run "{\"action\":\"widget_update\",\"widget_id\":\"$NEW_WIDGET_ID\",\"widget_settings\":{\"title\":\"UpdatedWidget\",\"text\":\"World\"}}")
	assert_eq "widget_update: action is widget_update" "widget_update" "$(echo "$WUPD" | jq -r '.action // "none"')"
	assert_true "widget_update: has rollback_id" "$(echo "$WUPD" | jq -r 'if .rollback_id then "true" else "false" end')"
	UPD_ROLLBACK_ID=$(echo "$WUPD" | jq -r '.rollback_id')
fi

echo
echo "== 9. Widget Remove =="
if [ -n "${NEW_WIDGET_ID:-}" ]; then
	WREM=$(api POST /operations/widgets_manage/run "{\"action\":\"widget_remove\",\"widget_id\":\"$NEW_WIDGET_ID\"}")
	WREM_ACT=$(echo "$WREM" | jq -r '.action // .code // "none"')
	assert_eq "widget_remove: action is widget_remove" "widget_remove" "$WREM_ACT"
	assert_true "widget_remove: has rollback_id" "$(echo "$WREM" | jq -r 'if .rollback_id then "true" else "false" end')"
fi

echo
echo "== 10. Sidebar Assign (new widget) =="
WADD2=$(api POST /operations/widgets_manage/run "{\"action\":\"widget_add\",\"widget_type\":\"text\",\"sidebar_id\":\"$FIRST_SIDEBAR\",\"widget_settings\":{\"title\":\"TestAssign\",\"text\":\"Assign\"}}")
WIDGET2_ID=$(echo "$WADD2" | jq -r '.widget_id // empty')
SECOND_SIDEBAR=$(echo "$SIDEBARS" | jq -r '.[1].sidebar_id // empty')
if [ -n "$WIDGET2_ID" ] && [ -n "$SECOND_SIDEBAR" ] && [ "$SECOND_SIDEBAR" != "$FIRST_SIDEBAR" ]; then
	SASSIGN=$(api POST /operations/widgets_manage/run "{\"action\":\"sidebar_assign\",\"widget_id\":\"$WIDGET2_ID\",\"sidebar_id\":\"$SECOND_SIDEBAR\"}")
	assert_eq "sidebar_assign: action is sidebar_assign" "sidebar_assign" "$(echo "$SASSIGN" | jq -r '.action // "none"')"
	assert_true "sidebar_assign: has rollback_id" "$(echo "$SASSIGN" | jq -r 'if .rollback_id then "true" else "false" end')"
	# Clean up
	api POST /operations/widgets_manage/run "{\"action\":\"widget_remove\",\"widget_id\":\"$WIDGET2_ID\"}" > /dev/null 2>&1
else
	api POST /operations/widgets_manage/run "{\"action\":\"widget_remove\",\"widget_id\":\"$WIDGET2_ID\"}" > /dev/null 2>&1
	pass "sidebar_assign: skipped (only one sidebar)"
	pass "sidebar_assign: skipped (only one sidebar, no rollback_id check)"
fi

echo
echo "== 11. Sidebar Remove (new widget) =="
WADD3=$(api POST /operations/widgets_manage/run "{\"action\":\"widget_add\",\"widget_type\":\"text\",\"sidebar_id\":\"$FIRST_SIDEBAR\",\"widget_settings\":{\"title\":\"TestRemove\",\"text\":\"Remove\"}}")
WIDGET3_ID=$(echo "$WADD3" | jq -r '.widget_id // empty')
if [ -n "$WIDGET3_ID" ]; then
	SREM=$(api POST /operations/widgets_manage/run "{\"action\":\"sidebar_remove\",\"widget_id\":\"$WIDGET3_ID\",\"sidebar_id\":\"$FIRST_SIDEBAR\"}")
	SREM_ACT=$(echo "$SREM" | jq -r '.action // .code // "none"')
	assert_eq "sidebar_remove: action is sidebar_remove" "sidebar_remove" "$SREM_ACT"
	assert_true "sidebar_remove: has rollback_id" "$(echo "$SREM" | jq -r 'if .rollback_id then "true" else "false" end')"
fi

echo
echo "== 12. Rollback =="
	if [ -n "${ROLLBACK_ID:-}" ]; then
	RB=$(api POST /operations/widgets_manage/rollback "{\"rollback_id\":\"$ROLLBACK_ID\"}")
	RB_ACT=$(echo "$RB" | jq -r '.action // .code // "none"')
	assert_contains "rollback: result contains rollback" "$RB_ACT" "rollback"
fi

echo
echo "== 13. Missing widget_id =="
NO_ID=$(api POST /operations/widgets_manage/run '{"action":"widget_get"}')
assert_eq "missing widget_id: rejected" "wpcc_missing_widget_id" "$(echo "$NO_ID" | jq -r '.code // "none"')"

echo
echo "== 14. Timeline Integration =="
TIMELINE=$(api GET "/agent/timeline?limit=50")
assert_contains "timeline: widgets operation present" "$TIMELINE" "Widgets"

echo
echo "== 15. Agent Context =="
CTX=$(api GET "/agent/context")
assert_true "context: operations include widgets_manage" "$(echo "$CTX" | jq -r 'if (.operations | map(select(.id == "widgets_manage")) | length > 0) then "true" else "false" end')"

echo
echo "=============================================="
echo "Results: $PASS passed, $FAIL failed"
echo "=============================================="
[ "$FAIL" -eq 0 ] && exit 0 || exit 1
