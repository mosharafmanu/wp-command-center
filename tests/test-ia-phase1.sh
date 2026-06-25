#!/usr/bin/env bash
#
# Phase 1 — Narrative + Information Architecture acceptance suite.
#
# Validates the migration from the 5-C IA (Overview · Operate · Audit · Access ·
# Connect) to the product-language "Three Doors, One Engine" IA from the canonical
# UX Master Blueprint (§2): SIX sections — Home · Built-in AI · Connect · Activity ·
# History · Settings — with full backward-compatible legacy redirects, the new
# API & Integrations (Door 3) landing, the first-run door fork, and the renamed
# labels — WITHOUT any new REST route, operation, capability, MCP tool, or schema.
#
#   - PHP lint of every changed/new admin file
#   - AppShell: six section slug constants + tree; tab→view mapping; tab-aware
#     resolve_legacy() covering retired 5-C section slugs AND standalone slugs
#   - AdminMenu: six product-language submenus (no architecture words); admin-bar
#     badge → new Activity › Approvals; redirect on admin_menu priority 0
#   - Built-in AI / Connect terminology (Providers, AI Clients); honest Door-3 landing
#   - Home first-run door fork ("How do you want to use AI here?")
#   - No stale internal section-slug URLs left in the views
#   - Drift: shell layer adds NO REST route / engine dispatch; invariants 34/23/40/40/2.5.0
#
# Requires: php, rg; wp-cli optional (invariant check). Usage: bash tests/test-ia-phase1.sh

set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$DIR/.." && pwd)"
WP_ROOT="$(cd "$ROOT/../../.." && pwd)"
[ -f "$ROOT/wpcc-env.sh" ] && source "$ROOT/wpcc-env.sh"

SHELL_PHP="$ROOT/includes/Admin/AppShell.php"
MENU="$ROOT/includes/Admin/AdminMenu.php"
HOME_VIEW="$ROOT/includes/Admin/views/command-home.php"
API_VIEW="$ROOT/includes/Admin/views/api-integrations.php"
CLIENTS_VIEW="$ROOT/includes/Admin/views/ai-integrations.php"
PROVIDERS_VIEW="$ROOT/includes/Admin/views/ai-setup.php"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }
has()  { if rg -q -e "$2" "$3"; then pass "$1"; else fail "$1"; fi; }
lacks(){ if rg -q -e "$2" "$3"; then fail "$1"; else pass "$1"; fi; }
lint() { if php -l "$2" >/dev/null 2>&1; then pass "$1"; else fail "$1"; fi; }
wpe()  { wp --path="$WP_ROOT" eval "$1" 2>/dev/null; }

echo "== 1. Lint =="
for f in "$SHELL_PHP" "$MENU" "$HOME_VIEW" "$API_VIEW" "$CLIENTS_VIEW" "$PROVIDERS_VIEW"; do
	lint "lint $(basename "$f")" "$f"
done

echo
echo "== 2. AppShell — six product-language sections =="
has "slug: Home (top-level)"   "HOME_SLUG\s*=\s*'wp-command-center'" "$SHELL_PHP"
has "slug: Built-in AI"        "BUILTIN_SLUG\s*=\s*'wpcc-built-in-ai'"  "$SHELL_PHP"
has "slug: Connect"            "CONNECT_SLUG\s*=\s*'wpcc-connect'"      "$SHELL_PHP"
has "slug: Activity"           "ACTIVITY_SLUG\s*=\s*'wpcc-activity'"     "$SHELL_PHP"
has "slug: History"            "HISTORY_SLUG\s*=\s*'wpcc-history'"      "$SHELL_PHP"
has "slug: Settings"           "SETTINGS_SLUG\s*=\s*'wpcc-settings'"     "$SHELL_PHP"
has "section label: Built-in AI" "'Built-in AI'" "$SHELL_PHP"
has "section label: Activity"    "'Activity'"    "$SHELL_PHP"
has "section label: History"     "'History'"     "$SHELL_PHP"
# Tab → existing view mapping (re-homing, not rebuilding).
has "Built-in AI › Providers → ai-setup"        "'view' => 'ai-setup'"          "$SHELL_PHP"
has "Connect › AI Clients → ai-integrations"     "'view' => 'ai-integrations'"   "$SHELL_PHP"
has "Connect › API & Integrations → api-integrations" "'view' => 'api-integrations'" "$SHELL_PHP"
has "Activity › Live → operations-center"        "'view' => 'operations-center'" "$SHELL_PHP"
has "Activity › Approvals → approval-center"      "'view' => 'approval-center'"   "$SHELL_PHP"
has "History › Changes → change-history"          "'view' => 'change-history'"    "$SHELL_PHP"
has "Settings › Access → token-capability-manager" "'view' => 'token-capability-manager'" "$SHELL_PHP"
has "Settings › Capabilities → operations-explorer" "'view' => 'operations-explorer'"     "$SHELL_PHP"
has "Settings › Runtime → dashboard"              "'view' => 'dashboard'"         "$SHELL_PHP"
# FeatureGate preserved on moved gated tabs.
has "FeatureGate preserved (approval_center)"  "'approval_center'"          "$SHELL_PHP"
has "FeatureGate preserved (change_history)"   "'change_history'"           "$SHELL_PHP"
has "FeatureGate preserved (token cap mgr)"    "'token_capability_manager'" "$SHELL_PHP"
has "FeatureGate preserved (operations expl)"  "'operations_explorer'"      "$SHELL_PHP"
# Selector + chrome retained.
has "namespaced wpcc_tab selector"  "wpcc_tab" "$SHELL_PHP"
has "nav map exposed for palette"   "function nav_map" "$SHELL_PHP"
has "graceful empty section state"  "render_empty_section" "$SHELL_PHP"

echo
echo "== 3. AppShell — backward-compatible legacy resolution =="
has "resolve_legacy() present"            "function resolve_legacy" "$SHELL_PHP"
has "tab map: operate/approvals → activity"  "'approvals'  => \[ self::ACTIVITY_SLUG, 'approvals' \]" "$SHELL_PHP"
has "tab map: operate/center → activity/live" "'center'     => \[ self::ACTIVITY_SLUG, 'live' \]" "$SHELL_PHP"
has "tab map: operate/operations → settings/capabilities" "'operations' => \[ self::SETTINGS_SLUG, 'capabilities' \]" "$SHELL_PHP"
has "tab map: operate/runtime → settings/runtime" "'runtime'    => \[ self::SETTINGS_SLUG, 'runtime' \]" "$SHELL_PHP"
has "tab map: audit/changes → history"     "'changes'      => \[ self::HISTORY_SLUG, 'changes' \]" "$SHELL_PHP"
has "tab map: audit/patches → settings"    "'patches'      => \[ self::SETTINGS_SLUG, 'patches' \]" "$SHELL_PHP"
has "tab map: access/tokens → settings/access" "'tokens'   => \[ self::SETTINGS_SLUG, 'access' \]" "$SHELL_PHP"
has "tab map: access/security → settings/security" "'security' => \[ self::SETTINGS_SLUG, 'security' \]" "$SHELL_PHP"
has "tab map: connect/integrations → connect/clients" "'integrations' => \[ self::CONNECT_SLUG, 'clients' \]" "$SHELL_PHP"
has "tab map: connect/setup → built-in-ai/providers"  "'setup'        => \[ self::BUILTIN_SLUG, 'providers' \]" "$SHELL_PHP"
has "standalone: ai-setup → built-in-ai"   "'wpcc-ai-setup'\s*=> \[ self::BUILTIN_SLUG, 'providers' \]" "$SHELL_PHP"
has "standalone: change-history → history"  "'wpcc-change-history'\s*=> \[ self::HISTORY_SLUG, 'changes' \]" "$SHELL_PHP"
has "standalone: tokens → settings/access"  "'wpcc-tokens'\s*=> \[ self::SETTINGS_SLUG, 'access' \]" "$SHELL_PHP"

echo
echo "== 4. AdminMenu — six submenus, no architecture words, badge =="
has "menu: Built-in AI"  "render_builtin"  "$MENU"
has "menu: Connect"      "render_connect"  "$MENU"
has "menu: Activity"     "render_activity" "$MENU"
has "menu: History"      "render_history"  "$MENU"
has "menu: Settings"     "render_settings" "$MENU"
has "label: Built-in AI" "'Built-in AI'"   "$MENU"
has "label: Activity"    "'Activity'"      "$MENU"
has "label: History"     "'History'"       "$MENU"
# Retired 5-C section words are gone from the menu labels.
lacks "no 'Operate' menu label" "__\( 'Operate'"  "$MENU"
lacks "no 'Audit' menu label"   "__\( 'Audit'"    "$MENU"
lacks "no 'Access' menu label"  "__\( 'Access'"   "$MENU"
has "admin-bar badge → Activity › Approvals" "ACTIVITY_SLUG \. '&wpcc_tab=approvals'" "$MENU"
has "redirect uses resolve_legacy"          "AppShell::resolve_legacy" "$MENU"
has "redirect hooked on admin_menu (pre-403)" "add_action\( 'admin_menu', \[ \\\$this, 'redirect_legacy_slugs' \], 0 \)" "$MENU"
lacks "redirect NOT on admin_init"          "add_action\( 'admin_init', \[ \\\$this, 'redirect_legacy_slugs'" "$MENU"

echo
echo "== 5. Door terminology + honest API landing + first-run fork =="
has "AI Clients H1"            "esc_html_e\( 'AI Clients'" "$CLIENTS_VIEW"
has "explains a client (no MCP assumed)" "An AI client is an assistant" "$CLIENTS_VIEW"
has "Providers H1"            "esc_html_e\( 'Providers'"  "$PROVIDERS_VIEW"
has "API landing H1"          "esc_html_e\( 'API & Integrations'" "$API_VIEW"
has "API landing: real base URL" "rest_url\( 'wp-command-center/v1'" "$API_VIEW"
has "API landing: Bearer auth"   "Authorization: Bearer" "$API_VIEW"
has "API landing routes token creation to Settings › Access" "page=wpcc-settings&wpcc_tab=access" "$API_VIEW"
lacks "API landing adds NO REST route" "register_rest_route" "$API_VIEW"
lacks "API landing dispatches NO engine" "OperationExecutor" "$API_VIEW"
has "Home door fork question"  "How do you want to use AI here" "$HOME_VIEW"
has "fork → Built-in AI"       "page=wpcc-built-in-ai&wpcc_tab=providers" "$HOME_VIEW"
has "fork → AI Clients"        "page=wpcc-connect&wpcc_tab=clients" "$HOME_VIEW"
has "fork → API"               "page=wpcc-connect&wpcc_tab=api" "$HOME_VIEW"

echo
echo "== 6. No stale internal section-slug URLs in views =="
if rg -n -e "page=wpcc-operate|page=wpcc-audit|page=wpcc-access\b|wpcc_tab=integrations|page=wpcc-ai-integrations" "$ROOT/includes/Admin/views" >/dev/null 2>&1; then
	fail "no retired section-slug URLs remain in views"
	rg -n -e "page=wpcc-operate|page=wpcc-audit|page=wpcc-access\b|wpcc_tab=integrations|page=wpcc-ai-integrations" "$ROOT/includes/Admin/views"
else
	pass "no retired section-slug URLs remain in views"
fi

echo
echo "== 7. Drift guard + invariants =="
lacks "AppShell adds no REST route"   "register_rest_route" "$SHELL_PHP"
lacks "AppShell never dispatches engine" "OperationExecutor|->run\(|->execute\(" "$SHELL_PHP"
lacks "AdminMenu adds no REST route"  "register_rest_route" "$MENU"
if ! command -v wp >/dev/null 2>&1; then
	echo "  SKIP: wp-cli unavailable — invariant check skipped."
else
	INV="$(wpe '$r = ( new \WPCommandCenter\Admin\DashboardAdminQuery() )->overview(); $i = $r["invariants"]; echo $i["operation_map"].",".$i["capabilities"].",".$i["catalogue"].",".$i["mcp_tools"].",".$i["db_version"];')"
	assert_eq "OPERATION_MAP stays 34"       "34"    "$(echo "$INV" | cut -d, -f1)"
	assert_eq "ALL_CAPABILITIES stays 23"    "23"    "$(echo "$INV" | cut -d, -f2)"
	assert_eq "operation catalogue stays 40" "40"    "$(echo "$INV" | cut -d, -f3)"
	assert_eq "MCP tools stay 40"            "40"    "$(echo "$INV" | cut -d, -f4)"
	assert_eq "DB_VERSION stays 2.5.0"       "2.5.0" "$(echo "$INV" | cut -d, -f5)"
fi

echo
echo "== RESULT =="
echo "  PASS=$PASS FAIL=$FAIL"
[ "$FAIL" -eq 0 ]
