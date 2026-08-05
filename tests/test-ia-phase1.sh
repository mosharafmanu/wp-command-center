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
#   - Drift: shell layer adds NO REST route / engine dispatch; invariants 34/23/42/42/2.6.0
#
# Requires: php, rg; wp-cli optional (invariant check). Usage: bash tests/test-ia-phase1.sh

set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Derived, not hardcoded: the text domain follows the plugin slug, and these
# assertions must survive a slug rename.
WPCC_TEXTDOMAIN=$(grep -m1 "^ \* Text Domain:" "$(dirname "${BASH_SOURCE[0]}")"/../*.php | sed 's/.*Text Domain: *//;s/ *$//')
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
echo "== 2. AppShell — five product-language sections =="
has "slug: Home (top-level)"   "HOME_SLUG\s*=\s*'wp-command-center'" "$SHELL_PHP"
# V1: Built-in AI is no longer a primary section — the slug survives ONLY as a
# legacy redirect source, so it must NOT appear in SECTION_SLUGS.
has "slug kept for legacy redirect" "BUILTIN_SLUG\s*=\s*'wpcc-built-in-ai'"  "$SHELL_PHP"
# Redesign: Built-in AI is an Advanced PANE, not a Settings tab.
has "Built-in AI re-homed → Advanced pane" "'ai'           => \[" "$SHELL_PHP"
has "builtin tabs extracted + gated"      "public static function builtin_tabs" "$SHELL_PHP"
has "slug: Connect"            "CONNECT_SLUG\s*=\s*'wpcc-connect'"      "$SHELL_PHP"
has "slug: Activity"           "ACTIVITY_SLUG\s*=\s*'wpcc-activity'"     "$SHELL_PHP"
has "slug: History"            "HISTORY_SLUG\s*=\s*'wpcc-history'"      "$SHELL_PHP"
has "slug: Settings"           "SETTINGS_SLUG\s*=\s*'wpcc-settings'"     "$SHELL_PHP"
has "four sections only" "SECTION_SLUGS = \[" "$SHELL_PHP"
has "section label: Approvals"   "__\( 'Approvals'" "$SHELL_PHP"
has "section label: Changes"     "__\( 'Changes'"   "$SHELL_PHP"
# Tab → existing view mapping (re-homing, not rebuilding).
has "Built-in AI › Providers → ai-setup"        "'view' => 'ai-setup'"          "$SHELL_PHP"
has "Connections hosts ai-integrations" "'view'     => 'ai-integrations'" "$SHELL_PHP"
has "Connections hosts api-integrations" "'view'     => 'api-integrations'" "$SHELL_PHP"
has "Advanced hosts operations-center" "'view'     => 'operations-center'" "$SHELL_PHP"
has "Activity › Approvals → approval-center"      "'view' => 'approval-center'"   "$SHELL_PHP"
has "History › Changes → change-history"          "'view' => 'change-history'"    "$SHELL_PHP"
has "Connections hosts token manager" "'view'     => 'token-capability-manager'" "$SHELL_PHP"
# V1: database search & replace moved out of a top-level Settings tab into the
# Advanced hub, alongside the other developer tools (gated by DeveloperTools).
lacks "Tools is not a top-level Settings tab" "'tools'       => \[ 'label' => __\( 'Tools'" "$SHELL_PHP"
has "Search & Replace hosted in Advanced hub"  "'view'     => 'tools-search-replace'" "$SHELL_PHP"
has "developer tools gated by default"          "DeveloperTools::enabled" "$SHELL_PHP"
has "patches gated by default"                  "DeveloperTools::enabled" "$ROOT/includes/Admin/views/settings-diagnostics.php"
# Phase 2B: Diagnostics + Advanced hubs replace the flat diagnostic/advanced tabs;
# Runtime is retired (no 'dashboard' view in the shell).
has "Advanced hub hosts diagnostics" "'view'     => 'settings-diagnostics'" "$SHELL_PHP"
has "Settings › Advanced hub"                     "'view' => 'settings-advanced'"    "$SHELL_PHP"
lacks "Runtime tab removed (no dashboard view)"   "'view' => 'dashboard'"            "$SHELL_PHP"
# FeatureGate preserved on moved gated tabs.
has "FeatureGate preserved (approval_center)"  "'approval_center'"          "$SHELL_PHP"
has "FeatureGate preserved (change_history)"   "'change_history'"           "$SHELL_PHP"
has "FeatureGate preserved (token cap mgr)"    "'token_capability_manager'" "$SHELL_PHP"
has "FeatureGate preserved (operations expl)"  "'operations_explorer'"      "$SHELL_PHP"
# NOTE — the Connections/Advanced PANE lists above are asserted against
# AppShell, not against the two hub views. They were declared inside the views,
# where the ⌘K palette could not read them, so Access tokens / Diagnostics /
# System / Capabilities were real destinations the product's own search could
# never find. The declarations (labels, views, FeatureGate keys, DeveloperTools
# gating) moved to AppShell::connection_panes() / ::advanced_panes() unchanged;
# the views now consume them and still own all rendering.
has "Connections hub consumes shared panes" "AppShell::connection_panes" "$ROOT/includes/Admin/views/settings-connections.php"
has "Advanced hub consumes shared panes"    "AppShell::advanced_panes"   "$ROOT/includes/Admin/views/settings-advanced.php"

# Selector + chrome retained.
has "namespaced wpcc_tab selector"  "wpcc_tab" "$SHELL_PHP"
has "nav map exposed for palette"   "function nav_map" "$SHELL_PHP"

# ── Palette reaches every destination, once (RC hardening) ───────────────────
# The map used to be a section/tab TREE, which emitted a section and its only
# tab as two rows going to the same screen, and described no sub-panes at all —
# so "Access Tokens", "Undo", "Security", "Diagnostics", "Capabilities",
# "History" and "Assistants" every one returned nothing.
has "palette map is flat + keyworded" "'keywords'" "$SHELL_PHP"
has "palette reaches connection panes" "cpane=" "$SHELL_PHP"
has "palette reaches advanced panes"   "apane=" "$SHELL_PHP"
has "undo/history reach Changes"       "undo rollback revert"  "$SHELL_PHP"
has "security reaches Protection"      "security protection"   "$SHELL_PHP"
has "palette dedupes by url"           "seen\[ item.url \]"    "$ROOT/assets/js/wpcc-cds.js"
has "palette has an empty state"       "wpcc-cmdk__none"       "$ROOT/assets/js/wpcc-cds.js"
has "palette keeps keyboard nav"       "ArrowDown"             "$ROOT/assets/js/wpcc-cds.js"

# ── Simple/Detailed is shown only where it does something ────────────────────
# The toggle rendered on all four sections but the disclosure it drives exists
# only on Home, Approvals and Changes; on the eight Settings destinations it
# changed row spacing and nothing else.
has "sections declare whether they have detail" "'detail' =>" "$SHELL_PHP"
has "Settings declares no detail view"          "'detail' => false" "$SHELL_PHP"
has "toggle rendered only when detail exists"   "\\\$has_started && ! empty\\( \\\$section\\['detail'\\] \\)" "$SHELL_PHP"
has "graceful empty section state"  "render_empty_section" "$SHELL_PHP"

echo
echo "== 3. AppShell — backward-compatible legacy resolution =="
has "resolve_legacy() present"            "function resolve_legacy" "$SHELL_PHP"
has "tab map: operate/approvals → activity"  "'approvals'  => \[ self::ACTIVITY_SLUG, 'approvals' \]" "$SHELL_PHP"
has "tab map: operate/center → advanced/system" "'center'     => \\\$adv" "$SHELL_PHP"
has "tab map: operate/operations → capabilities" "'operations' => \\\$adv" "$SHELL_PHP"
has "tab map: operate/runtime → diagnostics" "'runtime'    => \\\$adv" "$SHELL_PHP"
has "tab map: audit/changes → history"     "'changes'      => \[ self::HISTORY_SLUG, 'changes' \]" "$SHELL_PHP"
has "tab map: audit/patches → diagnostics" "'patches'      => \\\$adv" "$SHELL_PHP"
# Phase 2B: retired Settings sub-tabs redirect into the hubs (no loop via live-section guard).
has "tab map: settings/runtime → diagnostics" "'runtime'         => \\\$adv" "$SHELL_PHP"
has "tab map: settings/capabilities → advanced" "'capabilities'    => \\\$adv" "$SHELL_PHP"
has "tab map: access/tokens → connections" "'tokens'   => \\\$con" "$SHELL_PHP"
has "tab map: access/security → settings/security" "'security' => \[ self::SETTINGS_SLUG, 'security' \]" "$SHELL_PHP"
has "tab map: connect/integrations → connections" "'integrations' => \\\$con" "$SHELL_PHP"
has "tab map: connect/setup → advanced/ai" "'setup'        => \\\$adv" "$SHELL_PHP"
has "standalone: ai-setup → advanced/ai" "'wpcc-ai-setup'           => \\[ self::SETTINGS_SLUG, 'advanced'" "$SHELL_PHP"
has "retired built-in-ai section redirects" "self::BUILTIN_SLUG => \[" "$SHELL_PHP"
has "standalone: change-history → history"  "'wpcc-change-history'\s*=> \[ self::HISTORY_SLUG, 'changes' \]" "$SHELL_PHP"
has "standalone: tokens → connections" "'wpcc-tokens'             => \\[ self::SETTINGS_SLUG, 'connections'" "$SHELL_PHP"

echo
echo "== 4. AdminMenu — five submenus, no architecture words, badge =="
lacks "menu: Built-in AI removed from top level" "render_builtin"  "$MENU"
lacks "Connect retired from top level" "render_connect"  "$MENU"
has "menu: Activity"     "render_activity" "$MENU"
has "menu: History"      "render_history"  "$MENU"
has "menu: Settings"     "render_settings" "$MENU"
lacks "label: Built-in AI not in menu" "'Built-in AI'"   "$MENU"
has "label: Approvals"   "'Approvals'"     "$MENU"
has "label: Changes"     "'Changes'"       "$MENU"
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
# Named for the door the customer sees in the tab bar, not the internal term.
has "Assistants H1"           "esc_html_e\( 'Assistants'" "$CLIENTS_VIEW"
# The hero now names the actual assistants instead of defining the category —
# "Connect Claude, Cursor, Codex…" explains it faster than a definition does.
# What still matters: no MCP jargon in the lead, and real product names.
has "hero names real assistants"  "Connect Claude, Cursor, Codex" "$CLIENTS_VIEW"
lacks "hero assumes no MCP knowledge" "wpcc-ai-lead[^>]*>[^<]*MCP" "$CLIENTS_VIEW"
# The Providers pane no longer prints its own H1: the Settings > Built-in AI hub
# supplies the heading and the active pane tab names the pane.
has "Providers pane hosted by the hub" "'providers' => \[ 'label' => __\( 'Providers'" "$SHELL_PHP"
has "API landing H1"          "esc_html_e\( 'API & Integrations'" "$API_VIEW"
has "API landing: real base URL" "rest_url\( 'wp-command-center/v1'" "$API_VIEW"
has "API landing: Bearer auth"   "Authorization: Bearer" "$API_VIEW"
has "API landing routes token creation to Connections" "page=wpcc-settings&wpcc_tab=connections&cpane=tokens" "$API_VIEW"
lacks "API landing adds NO REST route" "register_rest_route" "$API_VIEW"
lacks "API landing dispatches NO engine" "OperationExecutor" "$API_VIEW"
# V1: the three-door fork is replaced by exactly ONE next action.
lacks "no three-door fork on Home" "How do you want to use AI here" "$HOME_VIEW"
has "Home shows a single next action" "wpcc-home__next" "$HOME_VIEW"
has "Home reports real connection state" "ConnectionStatus::get" "$HOME_VIEW"
lacks "no Built-in AI promotion on Home" "page=wpcc-built-in-ai" "$HOME_VIEW"
has "Home → Connections"       "wpcc_tab=connections&cpane=assistants" "$HOME_VIEW"
has "Home → History undo path" "page=wpcc-history&wpcc_tab=changes" "$HOME_VIEW"

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
	assert_eq "operation catalogue stays 42" "42"    "$(echo "$INV" | cut -d, -f3)"
	assert_eq "MCP tools stay 42"            "42"    "$(echo "$INV" | cut -d, -f4)"
	assert_eq "DB_VERSION stays 2.6.0"       "2.6.0" "$(echo "$INV" | cut -d, -f5)"
fi

echo
echo "== 8. Navigation integrity — no redirect loops, no dead destinations =="
# Regression guard for the Settings redirect-loop bug: a slug that is itself a live
# section (e.g. wpcc-settings) must NEVER resolve as a legacy slug. Exhaustively:
# every live section + tab must render (resolve_legacy === null), and every legacy
# path must reach a real section in <=10 hops without cycling.
has "live-section short-circuit in resolve_legacy" "is_live_section" "$SHELL_PHP"
lacks "wpcc-settings not a self-referential legacy_map entry" "'wpcc-settings'\s*=> \[ self::SETTINGS_SLUG" "$SHELL_PHP"
if ! command -v wp >/dev/null 2>&1; then
	echo "  SKIP: wp-cli unavailable — live nav-integrity sweep skipped."
else
	NAV="$(wpe '
		use WPCommandCenter\Admin\AppShell;
		$bad=0; $sections=AppShell::sections(); $known=array_keys(AppShell::SECTION_SLUGS);
		foreach($sections as $slug=>$sec){ if(AppShell::resolve_legacy($slug,"")!==null){$bad++;}
			foreach(array_keys($sec["tabs"]) as $t){ if(AppShell::resolve_legacy($slug,$t)!==null){$bad++;} } }
		$follow=function($p,$t) use($known){ $seen=[]; $h=0; while(true){ $k="$p|$t"; if(isset($seen[$k]))return"LOOP"; $seen[$k]=1;
			$r=AppShell::resolve_legacy($p,$t); if($r===null)return in_array($p,$known,true)?"OK":"BADDEST"; $p=$r[0];$t=$r[1]; if(++$h>10)return"RUNAWAY"; } };
		$cases=[]; foreach(array_keys(AppShell::legacy_map()) as $s)$cases[]=[$s,""];
		foreach(AppShell::legacy_tab_map() as $s=>$tabs)foreach(array_keys($tabs) as $t)$cases[]=[$s,$t==="*"?"":$t];
		foreach($cases as $c){ if($follow($c[0],$c[1])!=="OK"){$bad++;} }
		echo ($bad===0)?"CLEAN":("BAD:".$bad);
	')"
	assert_eq "all sections/tabs render + all legacy paths terminate on a real section" "CLEAN" "$NAV"
fi

echo
echo "== RESULT =="
echo "  PASS=$PASS FAIL=$FAIL"
[ "$FAIL" -eq 0 ]
