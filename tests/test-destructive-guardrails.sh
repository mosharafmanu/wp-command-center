#!/usr/bin/env bash
#
# STEP 84 — Destructive Operation Guardrails test suite.
#
# Verifies:
#   1. plugin_delete without confirmation is blocked (confirmation_required)
#   2. plugin_delete with the wrong confirmation phrase is blocked
#   3. active plugin delete is blocked (even with full confirmation)
#   4. inactive plugin delete with confirmation is allowed + verified removed
#   5. a pre-delete backup / snapshot reference is created
#   6. the audit trail records the destructive lifecycle
#
# Assumes Developer security mode (the default), so a confirmed destructive op
# executes immediately rather than routing to approval. The confirmation gate
# itself (tests 1–2) is mode-independent.
#
# Requires: curl, jq, and wpcc-env.sh. Has filesystem access to wp-content.
# Usage: bash tests/test-destructive-guardrails.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
# shellcheck source=/dev/null
source "$PLUGIN_DIR/wpcc-env.sh"

WP_ROOT="$(cd "$SCRIPT_DIR/../../../.." && pwd)"
PLUGINS_DIR="$WP_ROOT/wp-content/plugins"
AUDIT_LOG="$WP_ROOT/wp-content/uploads/wpcc-audit/audit.log"
BACKUP_DIR="$WP_ROOT/wp-content/uploads/wpcc-plugin-backups"
DUMMY_SLUG="wpcc-test-dummy-delete"
DUMMY_PATH="$PLUGINS_DIR/$DUMMY_SLUG"

PASS=0
FAIL=0

pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }

assert_eq() {
	local desc="$1" expected="$2" actual="$3"
	if [ "$expected" = "$actual" ]; then pass "$desc"; else fail "$desc (expected '$expected', got '$actual')"; fi
}
assert_nonempty() {
	local desc="$1" actual="$2"
	if [ -n "$actual" ] && [ "$actual" != "null" ]; then pass "$desc"; else fail "$desc (empty/null)"; fi
}

api() {
	local method="$1" path="$2" body="${3:-}"
	if [ -n "$body" ]; then
		curl -s -X "$method" -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$body" "$WPCC_BASE$path"
	else
		curl -s -X "$method" -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE$path"
	fi
}

cleanup() {
	rm -rf "$DUMMY_PATH" 2>/dev/null
}
trap cleanup EXIT

echo "== 1. plugin_delete without confirmation is blocked =="
R=$(api POST /operations/plugin_manage/run '{"action":"plugin_delete","slug":"some-plugin"}')
assert_eq "no confirmation: status confirmation_required" "confirmation_required" "$(echo "$R" | jq -r '.status // "none"')"
assert_eq "no confirmation: destructive true" "true" "$(echo "$R" | jq -r '.destructive // false')"
assert_eq "no confirmation: risk critical" "critical" "$(echo "$R" | jq -r '.risk_level // "none"')"
assert_eq "no confirmation: phrase advertised" "DELETE_PLUGIN" "$(echo "$R" | jq -r '.confirmation_phrase // "none"')"
assert_eq "no confirmation: confirm listed as missing" "true" "$(echo "$R" | jq -r '[.missing[]? | select(. == "confirm")] | length > 0')"

echo
echo "== 2. Wrong confirmation phrase is blocked =="
R=$(api POST /operations/plugin_manage/run '{"action":"plugin_delete","slug":"some-plugin","confirm":true,"confirmation_phrase":"DELETE","reason":"x"}')
assert_eq "wrong phrase: still confirmation_required" "confirmation_required" "$(echo "$R" | jq -r '.status // "none"')"
assert_eq "wrong phrase: confirmation_phrase listed as missing" "true" "$(echo "$R" | jq -r '[.missing[]? | select(. == "confirmation_phrase")] | length > 0')"

echo
echo "== 3. Active plugin delete is blocked even with confirmation =="
PL_LIST=$(api POST /operations/plugin_manage/run '{"action":"plugin_list"}')
ACTIVE_SLUG=$(echo "$PL_LIST" | jq -r '.plugins.plugins[] | select(.active == true) | .slug' | head -1)
if [ -n "$ACTIVE_SLUG" ] && [ "$ACTIVE_SLUG" != "null" ]; then
	R=$(api POST /operations/plugin_manage/run "{\"action\":\"plugin_delete\",\"slug\":\"$ACTIVE_SLUG\",\"confirm\":true,\"confirmation_phrase\":\"DELETE_PLUGIN\",\"reason\":\"test active block\"}")
	assert_eq "active delete: rejected with delete_active code" "wpcc_plugin_delete_active" "$(echo "$R" | jq -r '.code // "none"')"
else
	pass "active delete: no active plugin available, skipped gracefully"
fi

echo
echo "== 4-6. Inactive plugin delete with confirmation =="
# Create a throwaway inactive plugin on disk.
mkdir -p "$DUMMY_PATH"
cat > "$DUMMY_PATH/$DUMMY_SLUG.php" <<PHP
<?php
/**
 * Plugin Name: WPCC Test Dummy (delete me)
 * Description: Disposable fixture for STEP 84 destructive guardrail tests.
 * Version: 1.0.0
 */
PHP

# Confirm WP now sees it as installed + inactive.
PL_LIST=$(api POST /operations/plugin_manage/run '{"action":"plugin_list"}')
DUMMY_PRESENT=$(echo "$PL_LIST" | jq -r "[.plugins.plugins[] | select(.slug == \"$DUMMY_SLUG\")] | length")
assert_eq "fixture: dummy plugin is installed" "1" "$DUMMY_PRESENT"

AUDIT_BEFORE=0
[ -f "$AUDIT_LOG" ] && AUDIT_BEFORE=$(wc -l < "$AUDIT_LOG" | tr -d ' ')

R=$(api POST /operations/plugin_manage/run "{\"action\":\"plugin_delete\",\"slug\":\"$DUMMY_SLUG\",\"confirm\":true,\"confirmation_phrase\":\"DELETE_PLUGIN\",\"reason\":\"STEP 84 automated test\"}")
assert_eq "inactive delete: deleted true"          "true"     "$(echo "$R" | jq -r '.deleted // false')"
assert_eq "inactive delete: verified_removed true" "true"     "$(echo "$R" | jq -r '.verified_removed // false')"
assert_eq "inactive delete: destructive true"      "true"     "$(echo "$R" | jq -r '.destructive // false')"
assert_eq "inactive delete: risk critical"         "critical" "$(echo "$R" | jq -r '.risk_level // "none"')"
assert_eq "inactive delete: rollback_available"    "true"     "$(echo "$R" | jq -r '.rollback_available // false')"
BACKUP_ID=$(echo "$R" | jq -r '.backup_id // empty')
assert_nonempty "inactive delete: backup_id present"   "$BACKUP_ID"
assert_nonempty "inactive delete: snapshot_id present" "$(echo "$R" | jq -r '.snapshot_id // empty')"

# Backup archive physically exists.
if [ -n "$BACKUP_ID" ] && [ -f "$BACKUP_DIR/$BACKUP_ID.zip" ]; then
	pass "backup: archive written to disk"
else
	fail "backup: archive $BACKUP_ID.zip not found in $BACKUP_DIR"
fi

# Plugin folder is actually gone.
if [ ! -d "$DUMMY_PATH" ]; then
	pass "removal: plugin folder no longer on disk"
else
	fail "removal: plugin folder still present"
fi

echo
echo "== Audit trail =="
if [ -f "$AUDIT_LOG" ]; then
	NEW_LINES=$(tail -n "+$((AUDIT_BEFORE+1))" "$AUDIT_LOG")
	echo "$NEW_LINES" | grep -q "operation.destructive.confirmed" && pass "audit: destructive.confirmed recorded" || fail "audit: destructive.confirmed missing"
	echo "$NEW_LINES" | grep -q "plugin.delete.backup"            && pass "audit: plugin.delete.backup recorded" || fail "audit: plugin.delete.backup missing"
	echo "$NEW_LINES" | grep -q "\"plugin.delete\""               && pass "audit: plugin.delete recorded"        || fail "audit: plugin.delete missing"
else
	fail "audit: log file not found at $AUDIT_LOG"
fi

echo
echo "================================================"
echo "  Destructive Guardrails: $PASS passed, $FAIL failed"
echo "================================================"
[ "$FAIL" -eq 0 ]
