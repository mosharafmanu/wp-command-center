#!/usr/bin/env bash
# STEP 83 — Plugin stays active after update
#
# Root cause: Plugin_Upgrader::deactivate_plugin_before_upgrade() removes the
# plugin from active_plugins in non-cron context. active_after() is a no-op
# outside wp_doing_cron(), so without an explicit reactivation the plugin
# remains deactivated after a successful update.
#
# Fix: capture is_plugin_active() before upgrade; call activate_plugin() after
# a successful upgrade if the plugin was active.
#
# Verifies:
#   1. SafeUpdates: $was_active captured before upgrade call
#   2. SafeUpdates: activate_plugin() called after successful upgrade
#   3. SafeUpdates: was_active=false → activate_plugin() NOT called
#   4. SafeUpdates: return array includes reactivated key
#   5. PluginManager: same fix applied (was_active captured + reactivated)
#   6. PluginManager: file.php included before Plugin_Upgrader in plugin_update
#   7. PluginManager: plugin.php included before is_plugin_active in plugin_update
#   8. SafeUpdates: update failure does not trigger reactivation (error path)

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WP_PATH="$SCRIPT_DIR/../../../.."

PASS=0; FAIL=0

pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_contains() { local d="$1" needle="$2" haystack="$3"; echo "$haystack" | grep -q "$needle" && pass "$d" || fail "$d (expected '$needle' in output)"; }
assert_not_contains() { local d="$1" needle="$2" haystack="$3"; echo "$haystack" | grep -q "$needle" && fail "$d (should NOT contain '$needle')" || pass "$d"; }

SAFE="$WP_PATH/wp-content/plugins/wp-command-center/includes/Operations/SafeUpdates.php"
PM="$WP_PATH/wp-content/plugins/wp-command-center/includes/Operations/PluginManager.php"

# ===================================================================
echo "== 1. SafeUpdates: was_active captured before upgrade =="

SAFE_CONTENT=$(cat "$SAFE")

assert_contains \
    "SafeUpdates captures was_active before upgrade" \
    'is_plugin_active( \$plugin_file )' \
    "$SAFE_CONTENT"

assert_contains \
    "SafeUpdates stores result in \$was_active" \
    '\$was_active = is_plugin_active' \
    "$SAFE_CONTENT"

# ===================================================================
echo "== 2. SafeUpdates: activate_plugin called after successful upgrade =="

assert_contains \
    "SafeUpdates calls activate_plugin after upgrade" \
    'activate_plugin( \$plugin_file' \
    "$SAFE_CONTENT"

assert_contains \
    "SafeUpdates guards reactivation with was_active check" \
    'if ( \$was_active )' \
    "$SAFE_CONTENT"

# ===================================================================
echo "== 3. SafeUpdates: activate_plugin is after null/false error guards =="

# The activate_plugin call must appear AFTER the null/false result checks so
# that a failed upgrade does not trigger reactivation.
NULL_POS=$(grep -n 'null === \$result || false === \$result' "$SAFE" | head -1 | cut -d: -f1)
ACTIVATE_POS=$(grep -n 'activate_plugin( \$plugin_file' "$SAFE" | head -1 | cut -d: -f1)

if [ -n "$NULL_POS" ] && [ -n "$ACTIVATE_POS" ] && [ "$ACTIVATE_POS" -gt "$NULL_POS" ]; then
    pass "SafeUpdates: activate_plugin is after null/false guard (line $ACTIVATE_POS > $NULL_POS)"
else
    fail "SafeUpdates: activate_plugin position wrong (null_guard=$NULL_POS, activate=$ACTIVATE_POS)"
fi

# ===================================================================
echo "== 4. SafeUpdates: return array includes reactivated key =="

assert_contains \
    "SafeUpdates return array has reactivated key" \
    "'reactivated'" \
    "$SAFE_CONTENT"

assert_contains \
    "SafeUpdates reactivated value is \$was_active" \
    "'reactivated'    => \$was_active" \
    "$SAFE_CONTENT"

# ===================================================================
echo "== 5. PluginManager: same fix applied =="

PM_CONTENT=$(cat "$PM")

assert_contains \
    "PluginManager captures was_active before upgrade" \
    '\$was_active = is_plugin_active' \
    "$PM_CONTENT"

assert_contains \
    "PluginManager calls activate_plugin after upgrade" \
    'activate_plugin( \$plugin_info\[.plugin_file.\]' \
    "$PM_CONTENT"

assert_contains \
    "PluginManager guards reactivation with was_active check" \
    'if ( \$was_active )' \
    "$PM_CONTENT"

# ===================================================================
echo "== 6. PluginManager: file.php included before Plugin_Upgrader =="

# The fix added file.php to the PluginManager plugin_update path
assert_contains \
    "PluginManager includes file.php in plugin_update" \
    "wp-admin/includes/file.php" \
    "$PM_CONTENT"

# file.php must appear in close proximity to class-wp-upgrader.php require
FILE_PHP_LINE=$(grep -n "wp-admin/includes/file.php" "$PM" | head -1 | cut -d: -f1)
UPGRADER_LINE=$(grep -n "wp-admin/includes/class-wp-upgrader.php" "$PM" | tail -1 | cut -d: -f1)

if [ -n "$FILE_PHP_LINE" ] && [ -n "$UPGRADER_LINE" ] && [ "$FILE_PHP_LINE" -lt "$UPGRADER_LINE" ]; then
    pass "PluginManager: file.php included before class-wp-upgrader.php"
else
    fail "PluginManager: file.php/upgrader order wrong (file.php=$FILE_PHP_LINE, upgrader=$UPGRADER_LINE)"
fi

# ===================================================================
echo "== 7. PluginManager: plugin.php included before is_plugin_active =="

assert_contains \
    "PluginManager includes plugin.php guard for is_plugin_active" \
    "wp-admin/includes/plugin.php" \
    "$PM_CONTENT"

# ===================================================================
echo "== 8. SafeUpdates: error returns exit before activate_plugin =="

# After null/false guard: error_from_skin returns WP_Error, so activate_plugin
# is never reached. Verify the structure: error_from_skin return is BEFORE
# the if ($was_active) block.
ERR_SKIN_POS=$(grep -n 'return \$this->error_from_skin' "$SAFE" | head -1 | cut -d: -f1)

if [ -n "$ERR_SKIN_POS" ] && [ -n "$ACTIVATE_POS" ] && [ "$ERR_SKIN_POS" -lt "$ACTIVATE_POS" ]; then
    pass "SafeUpdates: error_from_skin (return) is before activate_plugin (line $ERR_SKIN_POS < $ACTIVATE_POS)"
else
    fail "SafeUpdates: error path order wrong (error_from_skin=$ERR_SKIN_POS, activate=$ACTIVATE_POS)"
fi

# ===================================================================
echo ""
echo "Results: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ] && exit 0 || exit 1
