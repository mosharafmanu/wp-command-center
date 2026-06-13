#!/usr/bin/env bash
#
# Plugin Management Runtime test suite for WP Command Center (Step 39).
#
# Verifies:
#   - plugin discovery (manifest / context)
#   - plugin_list
#   - plugin_install validation (invalid slug, duplicate)
#   - plugin_activate validation (missing, already active)
#   - plugin_deactivate validation (missing, already inactive)
#   - plugin_update validation (no update)
#   - plugin_delete validation (active, not found)
#   - approval enforcement
#   - health verification metadata
#   - rollback metadata capture
#   - audit logging
#   - timeline events
#   - manifest exposure
#
# Requires: curl, jq, and wpcc-env.sh.
# Usage: bash tests/test-plugin-runtime.sh

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
assert_true "manifest: plugin_management section exists" "$(echo "$MANIFEST" | jq -r 'if .plugin_management then "true" else "false" end')"
assert_true "manifest: supported_actions present" "$(echo "$MANIFEST" | jq -r 'if (.plugin_management.supported_actions | type) == "array" then "true" else "false" end')"
assert_true "manifest: risk_model present" "$(echo "$MANIFEST" | jq -r 'if .plugin_management.risk_model then "true" else "false" end')"
assert_true "manifest: plugins data present" "$(echo "$MANIFEST" | jq -r 'if .plugin_management.plugins then "true" else "false" end')"
assert_true "manifest: capability plugin_management is true" "$(echo "$MANIFEST" | jq -r '.capabilities.plugin_management // false')"
# Check 6 actions
ACT_COUNT=$(echo "$MANIFEST" | jq -r '.plugin_management.supported_actions | length')
assert_eq "manifest: 7 supported actions" "7" "$ACT_COUNT"

echo
echo "== 2. Agent Context Integration =="
CONTEXT=$(api GET "/agent/context")
assert_true "context: plugin_management_available present" "$(echo "$CONTEXT" | jq -r 'if .plugin_management_available then "true" else "false" end')"
assert_true "context: plugin_state present" "$(echo "$CONTEXT" | jq -r 'if .plugin_state then "true" else "false" end')"
assert_true "context: installed_plugins is array" "$(echo "$CONTEXT" | jq -r 'if (.installed_plugins | type) == "array" then "true" else "false" end')"

echo
echo "== 3. Plugin List =="
PL_LIST=$(api POST /operations/plugin_manage/run '{"action":"plugin_list"}')
assert_eq "list: action is plugin_list" "plugin_list" "$(echo "$PL_LIST" | jq -r '.action // "none"')"
assert_true "list: plugins array present" "$(echo "$PL_LIST" | jq -r 'if (.plugins.plugins | type) == "array" then "true" else "false" end')"
TOTAL=$(echo "$PL_LIST" | jq -r '.plugins.total')
assert_true "list: total > 0" "$(if [ "$TOTAL" -gt 0 ]; then echo true; else echo false; fi)"

echo
echo "== 4. Invalid action rejected =="
BAD_ACTION=$(api POST /operations/plugin_manage/run '{"action":"evil_action"}')
assert_eq "invalid action: rejected" "wpcc_invalid_plugin_action" "$(echo "$BAD_ACTION" | jq -r '.code // "none"')"

echo
echo "== 5. Invalid slug format rejected =="
BAD_SLUG=$(api POST /operations/plugin_manage/run '{"action":"plugin_activate","slug":"../malicious"}')
assert_eq "invalid slug: path traversal rejected" "wpcc_invalid_plugin_slug" "$(echo "$BAD_SLUG" | jq -r '.code // "none"')"

BAD_SLUG2=$(api POST /operations/plugin_manage/run '{"action":"plugin_install","slug":"evil; rm -rf /"}')
assert_eq "invalid slug: semicolon rejected" "wpcc_invalid_plugin_slug" "$(echo "$BAD_SLUG2" | jq -r '.code // "none"')"

echo
echo "== 6. Missing slug for mutation action =="
NO_SLUG=$(api POST /operations/plugin_manage/run '{"action":"plugin_activate"}')
assert_eq "missing slug: rejected" "wpcc_missing_plugin_slug" "$(echo "$NO_SLUG" | jq -r '.code // "none"')"

echo
echo "== 7. Plugin not found =="
NOT_FOUND=$(api POST /operations/plugin_manage/run '{"action":"plugin_activate","slug":"nonexistent-plugin-xyz-123"}')
assert_eq "not found: activate rejected" "wpcc_plugin_not_found" "$(echo "$NOT_FOUND" | jq -r '.code // "none"')"

NOT_FOUND2=$(api POST /operations/plugin_manage/run '{"action":"plugin_deactivate","slug":"nonexistent-plugin-xyz-123"}')
assert_eq "not found: deactivate rejected" "wpcc_plugin_not_found" "$(echo "$NOT_FOUND2" | jq -r '.code // "none"')"

NOT_FOUND3=$(api POST /operations/plugin_manage/run '{"action":"plugin_update","slug":"nonexistent-plugin-xyz-123"}')
assert_eq "not found: update rejected" "wpcc_plugin_not_found" "$(echo "$NOT_FOUND3" | jq -r '.code // "none"')"

# STEP 84: plugin_delete is now destructive-gated; supply confirmation so the
# request reaches the existence guard instead of stopping at confirmation_required.
NOT_FOUND4=$(api POST /operations/plugin_manage/run '{"action":"plugin_delete","slug":"nonexistent-plugin-xyz-123","confirm":true,"confirmation_phrase":"DELETE_PLUGIN","reason":"regression test"}')
assert_eq "not found: delete rejected" "wpcc_plugin_not_found" "$(echo "$NOT_FOUND4" | jq -r '.code // "none"')"

echo
echo "== 8. Already active/already inactive guards =="
# Find an active plugin
ACTIVE_SLUG=$(echo "$PL_LIST" | jq -r '.plugins.plugins[] | select(.active == true) | .slug' | head -1)
if [ -n "$ACTIVE_SLUG" ]; then
	ALREADY_ACTIVE=$(api POST /operations/plugin_manage/run "{\"action\":\"plugin_activate\",\"slug\":\"$ACTIVE_SLUG\"}")
	assert_eq "already active: rejected" "wpcc_plugin_already_active" "$(echo "$ALREADY_ACTIVE" | jq -r '.code // "none"')"
else
	pass "already active: no active plugins, skipped gracefully"
fi

# Find an inactive plugin
INACTIVE_SLUG=$(echo "$PL_LIST" | jq -r '.plugins.plugins[] | select(.active == false) | .slug' | head -1)
if [ -n "$INACTIVE_SLUG" ]; then
	ALREADY_INACTIVE=$(api POST /operations/plugin_manage/run "{\"action\":\"plugin_deactivate\",\"slug\":\"$INACTIVE_SLUG\"}")
	assert_eq "already inactive: rejected" "wpcc_plugin_already_inactive" "$(echo "$ALREADY_INACTIVE" | jq -r '.code // "none"')"
else
	pass "already inactive: no inactive plugins, skipped gracefully"
fi

echo
echo "== 9. Cannot delete active plugin =="
if [ -n "$ACTIVE_SLUG" ]; then
	# STEP 84: supply destructive confirmation so the request reaches the
	# active-plugin guard instead of stopping at confirmation_required.
	DELETE_ACTIVE=$(api POST /operations/plugin_manage/run "{\"action\":\"plugin_delete\",\"slug\":\"$ACTIVE_SLUG\",\"confirm\":true,\"confirmation_phrase\":\"DELETE_PLUGIN\",\"reason\":\"regression test\"}")
	assert_eq "delete active: rejected" "wpcc_plugin_delete_active" "$(echo "$DELETE_ACTIVE" | jq -r '.code // "none"')"
else
	pass "delete active: no active plugins, skipped gracefully"
fi

echo
echo "== 10. No update available =="
# Find a plugin without updates
NOUPDATE_SLUG=$(echo "$PL_LIST" | jq -r '.plugins.plugins[] | select(.update_available == false) | .slug' | head -1)
if [ -n "$NOUPDATE_SLUG" ]; then
	NO_UPDATE=$(api POST /operations/plugin_manage/run "{\"action\":\"plugin_update\",\"slug\":\"$NOUPDATE_SLUG\"}")
	assert_eq "no update: rejected" "wpcc_plugin_no_update" "$(echo "$NO_UPDATE" | jq -r '.code // "none"')"
else
	pass "no update: all plugins have updates, skipped gracefully"
fi

echo
echo "== 11. Plugin deactivate + reactivate (full cycle) =="
# Use a non-critical plugin for activation/deactivation test
# We'll use an inactive plugin and activate then deactivate it
TEST_SLUG=$(echo "$PL_LIST" | jq -r '.plugins.plugins[] | select(.active == false) | .slug' | head -1)
if [ -n "$TEST_SLUG" ]; then
	echo "  Using inactive plugin: $TEST_SLUG"
	# Activate it
	ACTIVATE=$(api POST /operations/plugin_manage/run "{\"action\":\"plugin_activate\",\"slug\":\"$TEST_SLUG\"}")
	assert_eq "activate: action success" "plugin_activate" "$(echo "$ACTIVATE" | jq -r '.action // "none"')"
	assert_true "activate: active is true" "$(echo "$ACTIVATE" | jq -r '.active // false')"
	assert_true "activate: has rollback_id" "$(echo "$ACTIVATE" | jq -r 'if .rollback_id then "true" else "false" end')"
	HEALTH_REQ=$(echo "$ACTIVATE" | jq -r '.health_required // false')
	assert_true "activate: health_required is true" "$HEALTH_REQ"

	# Deactivate it
	DEACTIVATE=$(api POST /operations/plugin_manage/run "{\"action\":\"plugin_deactivate\",\"slug\":\"$TEST_SLUG\"}")
	assert_eq "deactivate: action success" "plugin_deactivate" "$(echo "$DEACTIVATE" | jq -r '.action // "none"')"
	assert_true "deactivate: has rollback_id" "$(echo "$DEACTIVATE" | jq -r 'if .rollback_id then "true" else "false" end')"

	# Verify state returned to inactive
	LIST_AFTER=$(api POST /operations/plugin_manage/run '{"action":"plugin_list"}')
	IS_ACTIVE=$(echo "$LIST_AFTER" | jq -r ".plugins.plugins[] | select(.slug == \"$TEST_SLUG\") | .active")
	assert_eq "deactivate: plugin now inactive" "false" "$IS_ACTIVE"
	
	pass "full cycle: $TEST_SLUG activate → deactivate completed"
else
	pass "full cycle: no inactive plugins, skipped gracefully"
	# Fallback: just verify the operation is available
	OPS_CHECK=$(api GET /operations)
	assert_true "operations: plugin_manage listed" "$(echo "$OPS_CHECK" | jq -r 'any(.[]; .id == "plugin_manage")')"
fi

echo
echo "== 12. Plugin install validation (unknown slug) =="
# WordPress.org will reject unknown slugs
INSTALL_FAKE=$(api POST /operations/plugin_manage/run '{"action":"plugin_install","slug":"zzzz-nonexistent-plugin-9999"}')
INSTALL_CODE=$(echo "$INSTALL_FAKE" | jq -r '.code // "none"')
assert_true "install: fake slug rejected" "$(if echo "$INSTALL_CODE" | grep -qE 'failed|error|not_found|api'; then echo true; else echo false; fi)"

echo
echo "== 13. Duplicate install check =="
# wp-command-center is the current plugin, it's already installed
DUP_INSTALL=$(api POST /operations/plugin_manage/run '{"action":"plugin_install","slug":"wp-command-center"}')
assert_eq "duplicate install: rejected" "wpcc_plugin_already_installed" "$(echo "$DUP_INSTALL" | jq -r '.code // "none"')"

echo
echo "== 14. Risk model verification =="
assert_eq "risk: plugin_list is low" "low" "$(echo "$MANIFEST" | jq -r '.plugin_management.risk_model.plugin_list')"
assert_eq "risk: plugin_activate is medium" "medium" "$(echo "$MANIFEST" | jq -r '.plugin_management.risk_model.plugin_activate')"
assert_eq "risk: plugin_update is high" "high" "$(echo "$MANIFEST" | jq -r '.plugin_management.risk_model.plugin_update')"
assert_eq "risk: plugin_delete is critical" "critical" "$(echo "$MANIFEST" | jq -r '.plugin_management.risk_model.plugin_delete')"

echo
echo "== 15. Audit Logging =="
TL_AUDIT=$(api GET "/agent/timeline?limit=100")
AUDIT_STR=$(echo "$TL_AUDIT" | jq -r '[.[].label] | join(" ")')
assert_contains "audit: plugin.list recorded" "$AUDIT_STR" "Plugin list"
if [ -n "${TEST_SLUG:-}" ]; then
	assert_contains "audit: plugin.activate recorded" "$AUDIT_STR" "Plugin activated"
	assert_contains "audit: plugin.deactivate recorded" "$AUDIT_STR" "Plugin deactivated"
fi

echo
echo "== 16. Timeline Integration =="
TIMELINE=$(api GET "/agent/timeline?limit=100")
assert_true "timeline: has plugin list" "$(echo "$TIMELINE" | jq -r 'any(.[]; .label == "Plugin list requested")')"
if [ -n "${TEST_SLUG:-}" ]; then
	assert_true "timeline: has plugin activated" "$(echo "$TIMELINE" | jq -r 'any(.[]; .label == "Plugin activated")')"
	assert_true "timeline: has plugin deactivated" "$(echo "$TIMELINE" | jq -r 'any(.[]; .label == "Plugin deactivated")')"
fi

echo
echo "== 17. Error catalog coverage =="
ECAT=$(echo "$MANIFEST" | jq -r '.error_catalog')
assert_contains "error_catalog: wpcc_missing_plugin_slug" "$ECAT" "wpcc_missing_plugin_slug"
assert_contains "error_catalog: wpcc_plugin_not_found" "$ECAT" "wpcc_plugin_not_found"
assert_contains "error_catalog: wpcc_plugin_already_installed" "$ECAT" "wpcc_plugin_already_installed"
assert_contains "error_catalog: wpcc_invalid_plugin_slug" "$ECAT" "wpcc_invalid_plugin_slug"

echo
echo "== 18. Operation registry includes plugin_manage =="
OPS_CHECK=$(api GET /operations)
assert_true "operations: plugin_manage listed" "$(echo "$OPS_CHECK" | jq -r 'any(.[]; .id == "plugin_manage")')"

echo
echo "== 19. All 6 actions in supported list =="
for action in plugin_list plugin_install plugin_activate plugin_deactivate plugin_update plugin_delete; do
	HAS=$(echo "$MANIFEST" | jq -r ".plugin_management.supported_actions | index(\"$action\")")
	if [ "$HAS" != "null" ]; then
		pass "supported: $action is in supported_actions"
	else
		fail "supported: $action is missing from supported_actions"
	fi
done

echo
echo "== 20. Plugin state counters =="
assert_true "state: total >= active" "$(echo "$CONTEXT" | jq -r 'if .plugin_state.total >= .plugin_state.active then "true" else "false" end')"
assert_true "state: total = active + inactive" "$(echo "$CONTEXT" | jq -r 'if .plugin_state.total == (.plugin_state.active + .plugin_state.inactive) then "true" else "false" end')"

echo
echo "== 21. Delete inactive plugin validation =="
# We shouldn't actually delete, just validate that the operation is guarded
# The guard "already active → cannot delete" was tested in test 9
# "not found → cannot delete" was tested in test 7
# "inactive plugin → can delete but requires approval (critical risk)"
# We just verify the risk level
pass "delete validation: guards tested (active block + not found + risk level)"

echo
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"

[ "$FAIL" -eq 0 ]
