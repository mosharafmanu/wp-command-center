#!/usr/bin/env bash
#
# Experience Layer — acceptance suite.
#
# Validates the WPCC Experience Layer: the unified Command Center Home (resolving
# the two-dashboard conflict), the 5-C App Shell + navigation (Overview · Operate ·
# Audit · Access · Connect) with legacy-slug redirects, the Command Design System
# (CDS) token/component substrate, the relocated + trimmed legacy operational
# dashboard (Operate › Runtime), Builder/Engineer mode, and the ⌘K palette —
# WITHOUT any new REST route, operation, capability, MCP tool, or schema change.
#
#   - PHP lint of every new/changed admin file; JS parse of the CDS runtime
#   - AppShell: the 5-C section/tab tree + legacy migration map; uses the namespaced
#     ?wpcc_tab= selector (never collides with a hosted view's own ?tab=); renders
#     the shell chrome; never executes an operation
#   - AdminMenu: five sections only; one consolidated legacy redirect; admin-bar
#     badge points at the new Approvals location; no stale per-view submenus
#   - Home (command-home.php): read-only, escaped, CDS-built, with Needs Attention,
#     readiness, subsystem cards, AI workflow summary, and an actor/trust-chipped
#     activity timeline; a11y (role=status/aria-live) + Builder/Engineer disclosure
#   - Trimmed Runtime (dashboard.php): op-request approve/reject + manual queue-run
#     removed (now owned by the Approval Center); S&R + agent plans + hierarchy kept;
#     self-links/forms retarget the new Runtime location
#   - CDS: tokens (risk tiers / actor hues / density), components, responsive + a11y;
#     enqueued + localized (nav map) on every WPCC admin page
#   - Drift: NO new register_rest_route / OperationExecutor / capability in the shell
#     layer; invariants stay 34 / 23 / 40 / 40 / 2.5.0
#
# Requires: php, rg, wp-cli, wpcc-env.sh; node optional (JS parse).
# Usage: bash tests/test-experience-layer.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_ROOT="$(cd "$PLUGIN_DIR/../../.." && pwd)"

# shellcheck source=/dev/null
[ -f "$PLUGIN_DIR/wpcc-env.sh" ] && source "$PLUGIN_DIR/wpcc-env.sh"

SHELL_PHP="$PLUGIN_DIR/includes/Admin/AppShell.php"
MENU="$PLUGIN_DIR/includes/Admin/AdminMenu.php"
ASSETS="$PLUGIN_DIR/includes/Admin/Assets.php"
HOME_VIEW="$PLUGIN_DIR/includes/Admin/views/command-home.php"
RUNTIME="$PLUGIN_DIR/includes/Admin/views/dashboard.php"
TOKENS="$PLUGIN_DIR/assets/css/wpcc-tokens.css"
CDS_CSS="$PLUGIN_DIR/assets/css/wpcc-cds.css"
CDS_JS="$PLUGIN_DIR/assets/js/wpcc-cds.js"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }
has()  { if rg -q -e "$2" "$3"; then pass "$1"; else fail "$1"; fi; }
lacks(){ if rg -q -e "$2" "$3"; then fail "$1"; else pass "$1"; fi; }
lint() { if php -l "$2" >/dev/null 2>&1; then pass "$1"; else fail "$1"; fi; }
exists(){ if [ -f "$2" ]; then pass "$1"; else fail "$1"; fi; }
wpe()  { wp --path="$WP_ROOT" eval "$1" 2>/dev/null; }

echo "== 1. Files present + lint/parse =="
exists "AppShell present"       "$SHELL_PHP"
exists "Home view present"      "$HOME_VIEW"
exists "CDS stylesheet present" "$CDS_CSS"
exists "CDS runtime present"    "$CDS_JS"
lint "AppShell lints"          "$SHELL_PHP"
lint "AdminMenu lints"         "$MENU"
lint "Assets lints"            "$ASSETS"
lint "Home view lints"         "$HOME_VIEW"
lint "Runtime view lints"      "$RUNTIME"
if command -v node >/dev/null 2>&1; then
	if node -c "$CDS_JS" >/dev/null 2>&1; then pass "CDS runtime parses (node -c)"; else fail "CDS runtime parses (node -c)"; fi
else
	echo "  SKIP: node unavailable — CDS JS parse skipped"
fi
# The Dashboard Overview view was folded into the home — its dead file is gone.
if [ -f "$PLUGIN_DIR/includes/Admin/views/dashboard-overview.php" ]; then fail "legacy dashboard-overview.php removed"; else pass "legacy dashboard-overview.php removed"; fi

echo
echo "== 2. AppShell — 5-C IA + migration map + selector =="
has "section: Overview (home slug)"  "HOME_SLUG = 'wp-command-center'" "$SHELL_PHP"
has "section: Operate"               "'wpcc-operate'"  "$SHELL_PHP"
has "section: Audit"                 "'wpcc-audit'"    "$SHELL_PHP"
has "section: Access"                "'wpcc-access'"   "$SHELL_PHP"
has "section: Connect"               "'wpcc-connect'"  "$SHELL_PHP"
has "selector is namespaced wpcc_tab (no ?tab= collision)" "wpcc_tab" "$SHELL_PHP"
lacks "shell does not read raw \$_GET['tab'] for selection" "\\\$_GET\['tab'\]" "$SHELL_PHP"
# Legacy migration map covers every former Phase A surface.
has "map: dashboard-overview -> home"     "'wpcc-dashboard-overview' => \[ self::HOME_SLUG" "$SHELL_PHP"
has "map: approval-center -> operate"     "'wpcc-approval-center'    => \[ 'wpcc-operate', 'approvals' \]" "$SHELL_PHP"
has "map: operations -> operate"          "'wpcc-operations'         => \[ 'wpcc-operate', 'operations' \]" "$SHELL_PHP"
has "map: change-history -> audit"        "'wpcc-change-history'     => \[ 'wpcc-audit', 'changes' \]" "$SHELL_PHP"
has "map: patches -> audit"               "'wpcc-patches'            => \[ 'wpcc-audit', 'patches' \]" "$SHELL_PHP"
has "map: diagnostics -> audit"           "'wpcc-diagnostics'        => \[ 'wpcc-audit', 'diagnostics' \]" "$SHELL_PHP"
has "map: site-intelligence -> audit"     "'wpcc-site-intelligence'  => \[ 'wpcc-audit', 'intelligence' \]" "$SHELL_PHP"
has "map: tokens -> access"               "'wpcc-tokens'             => \[ 'wpcc-access', 'tokens' \]" "$SHELL_PHP"
has "map: settings -> access (security)"  "'wpcc-settings'           => \[ 'wpcc-access', 'security' \]" "$SHELL_PHP"
has "map: ai-integrations -> connect"     "'wpcc-ai-integrations'    => \[ 'wpcc-connect', 'integrations' \]" "$SHELL_PHP"
has "map: file-access -> connect"         "'wpcc-file-access'        => \[ 'wpcc-connect', 'files' \]" "$SHELL_PHP"
# Build-flagged AI surfaces fold under Operate.
has "map: alt-text -> operate"            "'wpcc-alt-text'" "$SHELL_PHP"
has "map: seo -> operate"                 "'wpcc-seo'" "$SHELL_PHP"
has "map: ai-content -> operate"          "'wpcc-ai-content'" "$SHELL_PHP"
# Tabs are FeatureGate-filtered (licensing seam honored).
has "tabs filtered by FeatureGate"        "FeatureGate::allows" "$SHELL_PHP"
# Shell chrome.
has "renders shell bar"                   "wpcc-shell__bar"   "$SHELL_PHP"
has "renders mode toggle"                 "wpcc-shell__mode"  "$SHELL_PHP"
has "renders command palette trigger"     "wpcc-shell__cmdk"  "$SHELL_PHP"
has "renders security posture pill"       "wpcc-shell__posture" "$SHELL_PHP"
has "sub-tabs use aria-current"           "aria-current" "$SHELL_PHP"
has "nav map exposed for palette"         "function nav_map" "$SHELL_PHP"
# The shell NEVER executes an operation (read/navigation only).
lacks "shell adds no REST route"          "register_rest_route" "$SHELL_PHP"
lacks "shell never dispatches engine"     "OperationExecutor|->run\(|->execute\(" "$SHELL_PHP"

echo
echo "== 3. AdminMenu — five sections + one redirect + badge =="
has "menu: Overview section"  "render_overview"  "$MENU"
has "menu: Operate section"   "'wpcc-operate'"   "$MENU"
has "menu: Audit section"     "'wpcc-audit'"     "$MENU"
has "menu: Access section"    "'wpcc-access'"    "$MENU"
has "menu: Connect section"   "'wpcc-connect'"   "$MENU"
has "single consolidated legacy redirect" "function redirect_legacy_slugs" "$MENU"
has "redirect carries wpcc_tab"           "wpcc_tab"            "$MENU"
# Redirect MUST run on admin_menu (before core's user_can_access_admin_page() 403
# in menu.php), NOT admin_init — unregistered legacy slugs 403 before admin_init.
has "redirect hooked on admin_menu (pre-403)" "add_action\( 'admin_menu', \[ \\\$this, 'redirect_legacy_slugs' \], 0 \)" "$MENU"
lacks "redirect NOT on admin_init (too late)"  "add_action\( 'admin_init', \[ \\\$this, 'redirect_legacy_slugs'" "$MENU"
has "admin-bar badge -> new Approvals"    "page=wpcc-operate&wpcc_tab=approvals" "$MENU"
# No stale standalone submenus for surfaces that are now tabs.
lacks "no stale change-history submenu"   "add_submenu_page.*wpcc-change-history" "$MENU"
lacks "no stale tokens submenu"           "add_submenu_page.*wpcc-tokens"        "$MENU"
lacks "no stale operations submenu"       "add_submenu_page.*wpcc-operations"    "$MENU"
lacks "no stale approval-center submenu"  "add_submenu_page.*wpcc-approval-center" "$MENU"
# Navigation only — no routes/engine in the menu layer.
lacks "menu adds no REST route"           "register_rest_route" "$MENU"

echo
echo "== 4. Unified Home — read-only, CDS, trust signals, a11y =="
has "fetches /dashboard (read)"           "/dashboard'"   "$HOME_VIEW"
has "fetches /proposals (AI summary)"     "/proposals"    "$HOME_VIEW"
has "uses REST nonce"                      "wp_rest"       "$HOME_VIEW"
has "Needs Attention hero"                "wpcc-cds-attn|renderAttn" "$HOME_VIEW"
has "readiness / onboarding"              "renderReadiness" "$HOME_VIEW"
has "subsystem cards"                     "renderCards"   "$HOME_VIEW"
has "AI workflow summary"                 "renderAi"      "$HOME_VIEW"
has "activity timeline"                   "renderActivity" "$HOME_VIEW"
has "actor provenance chip"               "actorChip"     "$HOME_VIEW"
has "trust chip: reversible"              "chip\( 'reversible'" "$HOME_VIEW"
has "trust chip: audited"                 "chip\( 'audited'"    "$HOME_VIEW"
has "uses CDS render helpers"             "WPCC.cds"      "$HOME_VIEW"
has "escapes output"                      "escHtml"       "$HOME_VIEW"
has "engineer-only disclosure"            "wpcc-engineer-only" "$HOME_VIEW"
has "role=status live region"             "role=\"status\"" "$HOME_VIEW"
has "aria-live polite"                    "aria-live=\"polite\"" "$HOME_VIEW"
has "cross-links to Change History session" "session_id=" "$HOME_VIEW"
has "deep links use new IA (wpcc_tab)"    "wpcc_tab=" "$HOME_VIEW"
# Read-only: no write verbs, no engine, no run controls.
lacks "no write fetch (POST/PUT/DELETE)"  "'POST'|'PUT'|'DELETE'|method: 'POST'" "$HOME_VIEW"
lacks "no engine dispatch"                "OperationExecutor|wpcc_action" "$HOME_VIEW"

echo
echo "== 5. Trimmed Runtime (relocated legacy dashboard) =="
# Removed: duplicated op-request + queue WRITE panels (now in the Approval Center).
lacks "no approve_request control"        "value=\"approve_request\"" "$RUNTIME"
lacks "no reject_request control"         "value=\"reject_request\""  "$RUNTIME"
lacks "no manual run_queue control"       "value=\"run_queue\""       "$RUNTIME"
lacks "no Pending Operation Requests panel" "Pending Operation Requests" "$RUNTIME"
lacks "no Queued Operations panel"        ">Queued Operations<"       "$RUNTIME"
# Kept: the controls unique to Runtime.
has "keeps Safe Search & Replace"         "wpcc_sr_action"            "$RUNTIME"
has "keeps agent plan approval"           "value=\"approve_plan\""    "$RUNTIME"
has "keeps runtime hierarchy"             "Runtime Hierarchy"         "$RUNTIME"
has "points operators to Approval Center" "page=wpcc-operate&wpcc_tab=approvals" "$RUNTIME"
# Self-links/forms retarget the new Runtime location (not the old top-level slug).
has "timeline form targets new Runtime"   "name=\"wpcc_tab\" value=\"runtime\"" "$RUNTIME"
lacks "no self-link to old top-level slug" "'page' => 'wp-command-center'" "$RUNTIME"

echo
echo "== 6. CDS tokens + components + enqueue =="
has "token: risk tiers"      "wpcc-risk-critical-bg" "$TOKENS"
has "token: actor hues"      "wpcc-actor-agent-fg"   "$TOKENS"
has "token: density binding"  "data-wpcc-density"     "$TOKENS"
has "component: card"        "wpcc-cds-card"         "$CDS_CSS"
has "component: trust chip"  "wpcc-cds-chip--reversible" "$CDS_CSS"
has "component: risk pill"   "wpcc-cds-pill--critical"   "$CDS_CSS"
has "component: actor chip"  "wpcc-cds-actor--agent"     "$CDS_CSS"
has "component: timeline"    "wpcc-cds-timeline"     "$CDS_CSS"
has "component: empty state"  "wpcc-cds-empty"        "$CDS_CSS"
has "responsive @media"      "@media"                "$CDS_CSS"
has "reduced-motion honored"  "prefers-reduced-motion" "$CDS_CSS"
has "builder/engineer disclosure" "data-wpcc-mode=\"builder\"" "$CDS_CSS"
has "CDS runtime: mode persistence" "localStorage"   "$CDS_JS"
has "CDS runtime: command palette"  "openPalette"    "$CDS_JS"
has "CDS runtime: render helpers"   "WPCC.cds"       "$CDS_JS"
# Enqueued + localized on WPCC admin pages.
has "enqueues tokens stylesheet"   "wpcc-tokens"     "$ASSETS"
has "enqueues CDS stylesheet"      "wpcc-cds"        "$ASSETS"
has "enqueues CDS runtime"         "wpcc-cds.js"     "$ASSETS"
has "localizes nav map for palette" "AppShell::nav_map" "$ASSETS"
# The runtime + CDS load in the HEAD (in_footer = false), so body-embedded view
# scripts (the Home) can use window.WPCC at parse time and never hang on Loading.
has "runtime loads in HEAD (not footer)" "wpcc-admin-runtime.js', \[\], WPCC_VERSION, false" "$ASSETS"
has "CDS loads in HEAD (not footer)"     "wpcc-cds.js', \[ 'wpcc-admin-runtime' \], WPCC_VERSION, false" "$ASSETS"
# The Home re-resolves window.WPCC at run time (defensive against load-order).
has "home re-resolves WPCC at run time"  "WPCC = window.WPCC || WPCC" "$HOME_VIEW"

echo
echo "== 7. Invariants unchanged + no shell-layer drift =="
if ! command -v wp >/dev/null 2>&1; then
	echo "  SKIP: wp-cli unavailable — static checks only."
else
	# Read invariants through the proven overview() envelope (same source the
	# dashboard suite validates) to avoid coupling to internal namespaces.
	INV="$(wpe '$r = ( new \WPCommandCenter\Admin\DashboardAdminQuery() )->overview(); $i = $r["invariants"]; echo $i["operation_map"].",".$i["capabilities"].",".$i["catalogue"].",".$i["mcp_tools"].",".$i["db_version"];')"
	assert_eq "OPERATION_MAP stays 34"        "34"    "$(echo "$INV" | cut -d, -f1)"
	assert_eq "ALL_CAPABILITIES stays 23"     "23"    "$(echo "$INV" | cut -d, -f2)"
	assert_eq "operation catalogue stays 40"  "40"    "$(echo "$INV" | cut -d, -f3)"
	assert_eq "MCP tools stay 40"             "40"    "$(echo "$INV" | cut -d, -f4)"
	assert_eq "DB_VERSION stays 2.5.0"        "2.5.0" "$(echo "$INV" | cut -d, -f5)"
fi

echo
echo "== RESULT =="
echo "  PASS=$PASS FAIL=$FAIL"
[ "$FAIL" -eq 0 ]
