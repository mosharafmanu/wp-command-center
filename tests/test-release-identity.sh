#!/usr/bin/env bash
# Action Steward public-release identity contract. Internal WPCC and
# wp-command-center protocol identifiers are intentionally preserved.

set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }

echo "Release identity — Action Steward v1.0.2"

assert_eq "new main plugin file exists" "yes" "$( [ -f action-steward.php ] && echo yes || echo no )"
assert_eq "legacy main plugin file is absent" "no" "$( [ -e ai-command-center.php ] && echo yes || echo no )"
assert_eq "plugin display name" "Action Steward" "$(sed -n 's/^ \* Plugin Name:[[:space:]]*//p' action-steward.php | head -1)"
assert_eq "plugin version" "1.0.2" "$(sed -n 's/^ \* Version:[[:space:]]*//p' action-steward.php | head -1)"
assert_eq "plugin text domain" "action-steward" "$(sed -n 's/^ \* Text Domain:[[:space:]]*//p' action-steward.php | head -1)"
assert_eq "runtime version constant" "1.0.2" "$(sed -n "s/^define( 'WPCC_VERSION', '\([^']*\)' );/\1/p" action-steward.php)"
assert_eq "readme display name" "=== Action Steward ===" "$(head -1 readme.txt)"
assert_eq "readme stable tag" "1.0.2" "$(sed -n 's/^Stable tag:[[:space:]]*//p' readme.txt)"
assert_eq "readme tested through WordPress 7.1" "7.1" "$(sed -n 's/^Tested up to:[[:space:]]*//p' readme.txt)"
assert_eq "readme minimum WordPress" "6.4" "$(sed -n 's/^Requires at least:[[:space:]]*//p' readme.txt)"
assert_eq "readme minimum PHP" "8.0" "$(sed -n 's/^Requires PHP:[[:space:]]*//p' readme.txt)"
assert_eq "database schema version remains compatible" "2.6.0" "$(sed -n "s/.*DB_VERSION = '\([^']*\)'.*/\1/p" includes/Core/Schema.php)"
assert_eq "build slug is the public slug" "action-steward" "$(sed -n 's/^SLUG="\([^"]*\)"/\1/p' scripts/build-release.sh)"
assert_eq "Composer package identity" "mosharafmanu/action-steward" "$(php -r '$j=json_decode(file_get_contents("composer.json"),true); echo $j["name"] ?? "";')"

SHIPPING=(action-steward.php uninstall.php readme.txt includes assets sdk/javascript/wpcc-mcp-relay.mjs)
assert_eq "old display name absent from shipping source" "0" "$(rg -i -F -l 'WP Command Center' "${SHIPPING[@]}" 2>/dev/null | wc -l | tr -d ' ')"
assert_eq "old text domain absent from shipping source" "0" "$(rg -F -l "'ai-command-center'" action-steward.php uninstall.php includes 2>/dev/null | wc -l | tr -d ' ')"
assert_eq "new text domain is used" "yes" "$(rg -q -F "'action-steward'" includes && echo yes || echo no)"
assert_eq "MCP server key remains compatible" "yes" "$(rg -q "return 'wp-command-center';" includes/Integration/BaseClientIntegration.php && echo yes || echo no)"
assert_eq "REST namespace remains compatible" "yes" "$(rg -q "NAMESPACE = 'wp-command-center/v1'" includes/Mcp/McpServerRuntime.php includes/AiAgent/RestApi.php && echo yes || echo no)"
assert_eq "internal WPCC prefix remains available" "yes" "$(rg -q "define\( 'WPCC_VERSION'" action-steward.php && echo yes || echo no)"

echo
echo "RESULT: ${PASS} passed, ${FAIL} failed"
[ "$FAIL" -eq 0 ]
