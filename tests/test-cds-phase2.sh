#!/usr/bin/env bash
#
# CDS Phase 2 — read-only utility view adoption (UI-only).
#
# Validates the Phase 2 component-kit top-up (info pill + inline notice) and the
# migration of three low-risk, read-only server-rendered views onto CDS classes,
# WITHOUT any route/op/cap/MCP/schema change and WITHOUT touching the legacy
# .wpcc-badge CSS that the not-yet-migrated views still depend on:
#
#   - Component kit: .wpcc-cds-pill--info + .wpcc-cds-notice(+variants) added;
#     existing components preserved.
#   - diagnostics.php / site-intelligence.php / file-access.php: legacy
#     .wpcc-badge / WP .notice / bare .wpcc-table replaced by CDS pill / notice /
#     empty / table classes. Behavior-preserving (tabs, sections, tables intact).
#   - Views remain read-only presentation: no OperationExecutor, no REST route,
#     no engine dispatch introduced.
#   - admin.css legacy .wpcc-badge preserved; the 4 unmigrated views still use it.
#   - Invariants unchanged: 34 / 23 / 40 / 40 / 2.5.0.
#
# Requires: php, rg, wp-cli. Usage: bash tests/test-cds-phase2.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_ROOT="$(cd "$PLUGIN_DIR/../../.." && pwd)"

# shellcheck source=/dev/null
[ -f "$PLUGIN_DIR/wpcc-env.sh" ] && source "$PLUGIN_DIR/wpcc-env.sh"

VIEWS_DIR="$PLUGIN_DIR/includes/Admin/views"
DIAG="$VIEWS_DIR/diagnostics.php"
SITE="$VIEWS_DIR/site-intelligence.php"
FILE="$VIEWS_DIR/file-access.php"
CDS_CSS="$PLUGIN_DIR/assets/css/wpcc-cds.css"
ADMIN_CSS="$PLUGIN_DIR/assets/css/admin.css"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }
has()  { if rg -q -e "$2" "$3"; then pass "$1"; else fail "$1"; fi; }
lacks(){ if rg -q -e "$2" "$3"; then fail "$1"; else pass "$1"; fi; }
lint() { if php -l "$2" >/dev/null 2>&1; then pass "$1"; else fail "$1"; fi; }
wpe()  { wp --path="$WP_ROOT" eval "$1" 2>/dev/null; }

echo "== 1. PHP lint =="
lint "diagnostics.php lints"       "$DIAG"
lint "site-intelligence.php lints" "$SITE"
lint "file-access.php lints"       "$FILE"

echo
echo "== 2. Component kit top-up (additive) =="
has "info status pill added"       "\.wpcc-cds-pill--info"   "$CDS_CSS"
has "inline notice component added" "\.wpcc-cds-notice"      "$CDS_CSS"
has "notice success variant"       "\.wpcc-cds-notice--success" "$CDS_CSS"
has "notice danger variant"        "\.wpcc-cds-notice--danger"  "$CDS_CSS"
# Existing components preserved.
has "status pills preserved"       "\.wpcc-cds-pill--success" "$CDS_CSS"
has "table treatment preserved"    "\.wpcc-cds-table"        "$CDS_CSS"
has "empty state preserved"        "\.wpcc-cds-empty"        "$CDS_CSS"

echo
echo "== 3. diagnostics.php migrated to CDS =="
has "status badge -> CDS pill"     "wpcc-cds-pill--%s"       "$DIAG"
has "semantic variant map present" "'critical'\s+=> 'danger'" "$DIAG"
has "table -> CDS table"           "wpcc-cds-table"          "$DIAG"
has "notice -> CDS notice"         "wpcc-cds-notice--"       "$DIAG"
has "empty -> CDS empty"           "wpcc-cds-empty"          "$DIAG"
lacks "no legacy .wpcc-badge"      "wpcc-badge"              "$DIAG"
lacks "no legacy WP notice class"  "notice notice-"         "$DIAG"
# Behavior preserved: tabs + check rendering intact.
has "tabs preserved"               "nav-tab-wrapper"         "$DIAG"
has "status labels preserved"      "status_labels"          "$DIAG"
has "debug-log clear nonce intact" "wpcc_clear_debug_log"   "$DIAG"

echo
echo "== 4. site-intelligence.php migrated to CDS =="
has "boolean badge -> CDS pill"    "wpcc-cds-pill--%s"       "$SITE"
has "yes=success / no=neutral map" "'success' : 'neutral'"   "$SITE"
has "tables -> CDS table"          "wpcc-cds-table"          "$SITE"
lacks "no legacy .wpcc-badge"      "wpcc-badge"              "$SITE"
# Behavior preserved: all sections + scan refresh intact.
has "WordPress section preserved"  "WordPress Environment"   "$SITE"
has "permissions table preserved"  "File & Directory Permissions" "$SITE"
has "scan refresh nonce intact"    "wpcc_site_intelligence"  "$SITE"

echo
echo "== 5. file-access.php migrated to CDS =="
has "listing table -> CDS table"   "wpcc-cds-table"          "$FILE"
has "error notices -> CDS notice"  "wpcc-cds-notice--danger" "$FILE"
has "no-match -> CDS empty"        "wpcc-cds-empty"          "$FILE"
lacks "no legacy WP notice class"  "notice notice-"         "$FILE"
# Behavior preserved: read-only browser + code search intact.
has "breadcrumbs preserved"        "wpcc-breadcrumbs"        "$FILE"
has "code search form preserved"   "wpcc-search-form"        "$FILE"
has "read-only intent stated"      "Read-only file browser"  "$FILE"

echo
echo "== 6. Views stay read-only presentation (no engine / route / write path) =="
for v in "$DIAG" "$SITE" "$FILE"; do
	b="$(basename "$v")"
	lacks "no OperationExecutor in $b"  "OperationExecutor"   "$v"
	lacks "no REST route in $b"         "register_rest_route" "$v"
	lacks "no ProposalApplyService in $b" "ProposalApplyService" "$v"
done

echo
echo "== 7. Legacy .wpcc-badge preserved for unmigrated views =="
has "admin.css keeps .wpcc-badge"  "\.wpcc-badge"           "$ADMIN_CSS"
# The migrated three no longer reference it; other views may still.
UNMIG="$(rg -l "wpcc-badge" "$VIEWS_DIR"/*.php 2>/dev/null | grep -cE 'diagnostics|site-intelligence|file-access')"
assert_eq "migrated views dropped .wpcc-badge" "0" "$UNMIG"

echo
echo "== 8. Invariants unchanged (34 / 23 / 40 / 40 / 2.5.0) =="
if ! command -v wp >/dev/null 2>&1; then
	echo "  SKIP: wp-cli not available."
else
	assert_eq "OPERATION_MAP stays 34" "34" "$(wpe 'echo count( \WPCommandCenter\Operations\CapabilityRegistry::OPERATION_MAP );')"
	assert_eq "ALL_CAPABILITIES stays 23" "23" "$(wpe 'echo count( \WPCommandCenter\Operations\CapabilityRegistry::ALL_CAPABILITIES );')"
	assert_eq "catalogue stays 40" "40" "$(wpe '$r = new \WPCommandCenter\Operations\OperationRegistry(); echo count( $r->get_operations() );')"
	assert_eq "MCP tools stay 40" "40" "$(wpe '$r = ( new \WPCommandCenter\Mcp\McpServerRuntime() )->handle( [ "jsonrpc" => "2.0", "id" => 1, "method" => "tools/list" ], [] ); echo isset( $r["result"]["tools"] ) ? count( $r["result"]["tools"] ) : -1;')"
	assert_eq "DB_VERSION stays 2.5.0" "2.5.0" "$(wpe 'echo get_option("wpcc_db_version");')"
fi

echo
echo "== RESULT =="
echo "  PASS=$PASS FAIL=$FAIL"
[ "$FAIL" -eq 0 ]
