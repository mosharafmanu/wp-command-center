#!/usr/bin/env bash
#
# STEP 98 — Reporting Runtime acceptance suite.
#
# Read-only operational reports over REST + MCP: Site Health, Plugin Health,
# Security, Content, WooCommerce, and Agent / Approval / Patch activity. Verifies
# each report's shape, MCP parity, graceful degradation, and structured errors.
# No writes — reports never mutate state.
#
# Requires: curl, jq, wpcc-env.sh.
# Usage: bash tests/test-reporting-step98.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
# shellcheck source=/dev/null
source "$PLUGIN_DIR/wpcc-env.sh"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }
assert_num() { local d="$1" a="$2"; [[ "$a" =~ ^[0-9]+$ ]] && pass "$d" || fail "$d (not a number: '$a')"; }
assert_nonempty() { local d="$1" a="$2"; { [ -n "$a" ] && [ "$a" != "null" ]; } && pass "$d" || fail "$d (empty/null)"; }
rp() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/operations/report_manage/run"; }
rpmcp() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/mcp" | jq -r '.result.content[0].text // empty'; }

echo "== 1. report_list enumerates the catalogue =="
L=$(rp '{"action":"report_list"}')
assert_eq "report_list total" "8" "$(echo "$L" | jq -r '.total')"
assert_eq "site_health listed" "true" "$(echo "$L" | jq -r '[.reports[].id] | index("report_site_health") != null')"

echo "== 2. report_site_health =="
SH=$(rp '{"action":"report_site_health"}')
assert_nonempty "php_version" "$(echo "$SH" | jq -r '.site_health.php_version')"
assert_nonempty "wp_version" "$(echo "$SH" | jq -r '.site_health.wp_version')"
assert_num "updates.total numeric" "$(echo "$SH" | jq -r '.site_health.updates.total')"
assert_nonempty "status" "$(echo "$SH" | jq -r '.site_health.status')"

echo "== 3. report_plugin_health =="
PH=$(rp '{"action":"report_plugin_health"}')
assert_num "plugins total" "$(echo "$PH" | jq -r '.plugin_health.total')"
assert_num "plugins active" "$(echo "$PH" | jq -r '.plugin_health.active')"
assert_num "updates_available" "$(echo "$PH" | jq -r '.plugin_health.updates_available')"

echo "== 4. report_security =="
SEC=$(rp '{"action":"report_security"}')
assert_nonempty "security_mode" "$(echo "$SEC" | jq -r '.security.security_mode')"
assert_num "tokens total" "$(echo "$SEC" | jq -r '.security.tokens.total')"
assert_num "pending_approvals" "$(echo "$SEC" | jq -r '.security.pending_approvals')"
assert_eq "capability_enforcement bool" "true" "$(echo "$SEC" | jq -r '.security.capability_enforcement | type == "boolean"')"

echo "== 5. report_content =="
CO=$(rp '{"action":"report_content"}')
assert_num "page publish count" "$(echo "$CO" | jq -r '.content.post_types.page.publish')"
assert_num "users count" "$(echo "$CO" | jq -r '.content.users')"
assert_num "categories" "$(echo "$CO" | jq -r '.content.taxonomies.categories')"

echo "== 6. report_woocommerce (active on dev) =="
WC=$(rp '{"action":"report_woocommerce"}')
assert_eq "woocommerce available" "true" "$(echo "$WC" | jq -r '.woocommerce.available')"
assert_num "products total" "$(echo "$WC" | jq -r '.woocommerce.products.total')"

echo "== 7. report_agent_activity (audit-derived) =="
AA=$(rp '{"action":"report_agent_activity","limit":500}')
assert_num "operations started" "$(echo "$AA" | jq -r '.agent_activity.operations.started')"
assert_num "window_entries" "$(echo "$AA" | jq -r '.agent_activity.window_entries')"
assert_eq "by_operation is object" "true" "$(echo "$AA" | jq -r '.agent_activity.by_operation | type == "object"')"

echo "== 8. report_approval_activity =="
AP=$(rp '{"action":"report_approval_activity"}')
assert_num "approval pending" "$(echo "$AP" | jq -r '.approval_activity.pending')"
assert_eq "requests_by_status object" "true" "$(echo "$AP" | jq -r '.approval_activity.requests_by_status | type == "object"')"

echo "== 9. report_patch_activity =="
PA=$(rp '{"action":"report_patch_activity"}')
assert_num "total patches" "$(echo "$PA" | jq -r '.patch_activity.total_patches')"
assert_eq "audit object" "true" "$(echo "$PA" | jq -r '.patch_activity.audit | type == "object"')"

echo "== 10. MCP parity =="
M=$(rpmcp '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"report_manage","arguments":{"action":"report_site_health"}}}')
assert_nonempty "MCP site_health php_version" "$(echo "$M" | jq -r '.site_health.php_version')"
M2=$(rpmcp '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"report_manage","arguments":{"action":"report_security"}}}')
assert_nonempty "MCP security mode" "$(echo "$M2" | jq -r '.security.security_mode')"

echo "== 11. Reports are read-only — content unchanged after running them =="
BEFORE=$(curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d '{"action":"report_content"}' "$WPCC_BASE/operations/report_manage/run" | jq -r '.content.post_types.page.publish')
rp '{"action":"report_site_health"}' >/dev/null; rp '{"action":"report_security"}' >/dev/null
AFTER=$(curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d '{"action":"report_content"}' "$WPCC_BASE/operations/report_manage/run" | jq -r '.content.post_types.page.publish')
assert_eq "page count stable across reports" "$BEFORE" "$AFTER"

echo "== 12. Structured errors =="
assert_eq "invalid report action" "wpcc_invalid_report_action" "$(rp '{"action":"report_bogus"}' | jq -r '.code')"

echo
echo "================================================"
echo "  Reporting (STEP 98): $PASS passed, $FAIL failed"
echo "================================================"
[ "$FAIL" -eq 0 ]
