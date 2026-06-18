#!/usr/bin/env bash
#
# STEP 109.1 — Dashboard Overview (read surface) acceptance suite.
#
# Validates the additive, read-only wp-admin Dashboard Overview that aggregates
# the existing admin surfaces (Approval Center / Change History / Tokens &
# Capabilities / Operations Explorer) plus the live security posture and the
# platform invariants — WITHOUT introducing any runtime/MCP/storage/policy change
# and WITHOUT touching the legacy operational Dashboard:
#
#   - PHP lint of every new/changed admin file
#   - Admin REST read route registered (/admin/dashboard) behind a
#     manage_options + FeatureGate permission gate; NO write/execute route
#   - The aggregation class is a thin read-only fan-out: it never writes, never
#     executes an operation, never dispatches the engine, never invokes the MCP
#     runtime (whose tools/list audits), and adds no new source of truth — it
#     composes the existing per-surface AdminQuery summaries
#   - Menu: "Dashboard Overview" submenu added, FeatureGate-gated; the legacy
#     "Dashboard" submenu + its render method are untouched
#   - View: posture strip + invariant strip + summary cards, escaped output, NO
#     write/run controls
#   - Functional (wp-cli, real bootstrap path): the overview envelope carries the
#     posture, the invariants (op map 34 / caps 23 / catalogue 40 / mcp 40 /
#     db 2.4.0), and each subsystem summary, and the numbers match the surfaces
#     that own them (no drift, no new source of truth)
#   - Invariants: operation_map stays 34, capabilities stay 23, catalogue stays 40,
#     MCP tools stay 40, DB_VERSION stays 2.4.0 (this step adds no runtime op, MCP
#     tool, capability, or schema)
#
# Requires: php, rg, wp-cli, wpcc-env.sh. (Admin routes are cookie+nonce, so the
# functional checks exercise the aggregation class directly via wp-cli.)
# Usage: bash tests/test-dashboard.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_ROOT="$(cd "$PLUGIN_DIR/../../.." && pwd)"

# shellcheck source=/dev/null
[ -f "$PLUGIN_DIR/wpcc-env.sh" ] && source "$PLUGIN_DIR/wpcc-env.sh"

VIEW="$PLUGIN_DIR/includes/Admin/views/dashboard-overview.php"
RESTAPI="$PLUGIN_DIR/includes/Admin/AdminRestApi.php"
QUERY="$PLUGIN_DIR/includes/Admin/DashboardAdminQuery.php"
MENU="$PLUGIN_DIR/includes/Admin/AdminMenu.php"
LEGACY="$PLUGIN_DIR/includes/Admin/views/dashboard.php"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq()  { local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }
has()  { if rg -q -e "$2" "$3"; then pass "$1"; else fail "$1"; fi; }
lacks(){ if rg -q -e "$2" "$3"; then fail "$1"; else pass "$1"; fi; }
lint() { if php -l "$2" >/dev/null 2>&1; then pass "$1"; else fail "$1"; fi; }
wpe()  { wp --path="$WP_ROOT" eval "$1" 2>/dev/null; }

echo "== 1. PHP lint =="
lint "view lints"                  "$VIEW"
lint "AdminRestApi lints"          "$RESTAPI"
lint "DashboardAdminQuery lints"   "$QUERY"
lint "AdminMenu lints"             "$MENU"

echo
echo "== 2. Admin REST route registered (read-only) =="
has "route: /admin/dashboard"            "'/admin/dashboard'"          "$RESTAPI"
has "permission gate helper present"     "function check_dashboard_permission" "$RESTAPI"
has "gate = manage_options + FeatureGate" "FeatureGate::allows\( 'dashboard_overview' \)" "$RESTAPI"
has "overview handler present"           "function dashboard_overview" "$RESTAPI"
has "handler delegates to query"         "new DashboardAdminQuery\(\)" "$RESTAPI"
# The dashboard route is READABLE only — no write method on the registration.
DASH_ROUTE="$(awk '/register_rest_route\( self::NS, .\/admin\/dashboard./{f=1} f{print} f && /\] \);/{exit}' "$RESTAPI")"
if printf '%s' "$DASH_ROUTE" | rg -q -e "WP_REST_Server::READABLE"; then pass "dashboard route is READABLE"; else fail "dashboard route is READABLE"; fi
if printf '%s' "$DASH_ROUTE" | rg -q -e "CREATABLE|DELETABLE|EDITABLE"; then fail "dashboard route is read-only (no write methods)"; else pass "dashboard route is read-only (no write methods)"; fi
# The handler never dispatches the engine.
DASH_HANDLER="$(awk '/function dashboard_overview/,/^\t}$/{print}' "$RESTAPI")"
if printf '%s' "$DASH_HANDLER" | rg -q -e "OperationExecutor|->run\(|->execute\("; then fail "dashboard handler never executes (no engine dispatch)"; else pass "dashboard handler never executes (no engine dispatch)"; fi

echo
echo "== 3. Aggregation class is a thin read-only fan-out (no writes, no execution, no new truth) =="
has "reuses ApprovalAdminQuery"          "new ApprovalAdminQuery\(\)"          "$QUERY"
has "reuses OperationExplorerAdminQuery"  "new OperationExplorerAdminQuery\(\)" "$QUERY"
has "reuses TokenCapabilityAdminQuery"    "new TokenCapabilityAdminQuery\(\)"   "$QUERY"
has "reuses ChangeHistoryAdminQuery"      "new ChangeHistoryAdminQuery\(\)"     "$QUERY"
has "reads security mode"                "SecurityModeManager"                 "$QUERY"
has "reads invariant constants"          "CapabilityRegistry::OPERATION_MAP"   "$QUERY"
has "reads DB version constant"          "Schema::DB_VERSION"                  "$QUERY"
lacks "no raw DB query (thin fan-out)"   "\\\$wpdb|->get_var\(|->get_results\(" "$QUERY"
lacks "no writes (update_option/insert/update)" "update_option|->update\(|->insert\(" "$QUERY"
lacks "no engine dispatch"               "OperationExecutor"                   "$QUERY"
lacks "no operation execution"           "->run\(|->execute\(|->dispatch\("    "$QUERY"
lacks "no audit/change writes"           "->record\(|ChangeRecorder|->audit\(" "$QUERY"
# The aggregator must never INVOKE the MCP runtime: McpServerRuntime::tools/list
# audits every call (a write side effect). The class docblock may reference it to
# explain why it derives the tool count from the catalogue instead — so match an
# actual instantiation/call (a paren), not a mere mention.
lacks "no MCP runtime usage (tools/list audits)" "McpServerRuntime\("           "$QUERY"
lacks "no policy mutation"               "->assign\(|->remove\(|->create\(|->revoke\(|->delete\(" "$QUERY"

echo
echo "== 3b. STEP 109.2 — recent activity feed + per-surface depth (read-only) =="
has "recent_activity in envelope"        "'recent_activity'"                   "$QUERY"
has "recent_activity projection method"  "function recent_activity"            "$QUERY"
# The session roll-up is fetched ONCE and reused for BOTH the change-history count
# and the recent activity feed — no second query, no new source of truth.
SESS_CALLS="$(rg -c -e "->sessions\(" "$QUERY" 2>/dev/null || echo 0)"
assert_eq "sessions() roll-up fetched once (reused)" "1" "$SESS_CALLS"
has "feed bounded by RECENT_LIMIT"       "RECENT_LIMIT"                        "$QUERY"

echo
echo "== 4. Menu: gated submenu added; legacy Dashboard untouched =="
has "submenu: Dashboard Overview"        "Dashboard Overview"                  "$MENU"
has "menu slug wpcc-dashboard-overview"  "'wpcc-dashboard-overview'"           "$MENU"
has "menu FeatureGate-gated"             "FeatureGate::allows\( 'dashboard_overview' \)" "$MENU"
has "render method present"              "function render_dashboard_overview"  "$MENU"
has "legacy Dashboard submenu retained"  "function render_dashboard\b"         "$MENU"
has "legacy dashboard view untouched"    "wpcc_action"                         "$LEGACY"

echo
echo "== 5. View is read-only + escaped + aggregating =="
has "HTML escaper present"               "function escHtml"                    "$VIEW"
has "uses REST nonce"                     "X-WP-Nonce"                          "$VIEW"
has "fetches /dashboard"                  "/dashboard'"                         "$VIEW"
has "renders security posture"            "renderPosture"                       "$VIEW"
has "renders invariants strip"            "renderInvariants"                    "$VIEW"
has "renders subsystem cards"             "renderCards"                         "$VIEW"
has "card: Approval Center"               "cardApprovals"                       "$VIEW"
has "card: Operations Explorer"           "cardOps"                             "$VIEW"
has "card: Tokens & Capabilities"         "cardTokens"                          "$VIEW"
has "card: Change History"                "cardHistory"                         "$VIEW"
has "cards drill out to surfaces"         "wpcc-approval-center"                "$VIEW"
has "role=status live region"             "role=\"status\""                     "$VIEW"
has "aria-live polite on live regions"    "aria-live=\"polite\""                "$VIEW"
# No execution / write affordance on this surface.
lacks "no run/execute control"            "wpcc_action|Execute|execute_request|method: 'POST'|method: 'DELETE'" "$VIEW"

echo
echo "== 5d. STEP 109.2 — recent activity feed + depth + deep links (view) =="
has "recent activity section present"     "wpcc-dash-activity"                  "$VIEW"
has "recent activity renderer"            "function renderActivity"             "$VIEW"
has "activity timestamp formatter"        "function fmtTime"                    "$VIEW"
has "activity rows deep-link to session"  "function sessionUrl"                 "$VIEW"
has "session deep link targets timeline"  "tab=timeline&session_id="            "$VIEW"
has "operations risk distribution"        "function riskDist"                   "$VIEW"
has "risk dist consumes by_risk"          "op.by_risk"                          "$VIEW"
has "approvals deep-link to queue tab"    "approvals_queue"                     "$VIEW"
has "deep link: approvals pending tab"    "tab=pending"                         "$VIEW"
has "deep link: tokens tab"               "tab=tokens"                          "$VIEW"
has "deep link: change history sessions"  "tab=sessions"                        "$VIEW"
has "activity empty state"                "i18n.actEmpty"                       "$VIEW"
has "activity table uses scope=col"       "scope=\"col\""                       "$VIEW"
has "activity row uses scope=row"         "scope=\"row\">' \+ escHtml\( fmtTime" "$VIEW"

echo
echo "== 5e. STEP 109.3 — filter + accessibility + states polish (view) =="
# Filter: a client-side "reversible only" toggle over the cached feed (read-only,
# no new route, no new source of truth — it filters already-fetched rows).
has "reversible-only filter control"      "id=\"wpcc-dash-reversible\""          "$VIEW"
has "filter has a visible label"          "Reversible only"                     "$VIEW"
has "filter references the panel (aria-controls)" "aria-controls=\"wpcc-dash-activity\"" "$VIEW"
has "filter apply function"               "function applyActivityFilter"        "$VIEW"
has "filter is client-side (cached rows)"  "allActivity.filter"                  "$VIEW"
has "filter wired to change event"        "addEventListener\( 'change', applyActivityFilter" "$VIEW"
# Live count region for the filter (a11y).
has "filter count live region (role=status)" "id=\"wpcc-dash-activity-count\" class=\"wpcc-dash-activity-count\" role=\"status\"" "$VIEW"
has "filter count format string"          "actCountFmt"                         "$VIEW"
# Heading hierarchy h1 -> h2 -> h3 (page / sections / cards), no skips.
has "page heading h1"                     "<h1>"                                "$VIEW"
has "section headings h2"                 "<h2>"                                "$VIEW"
has "card headings h3 (under section h2)"  "<h3>' \+ escHtml\( title"            "$VIEW"
lacks "no heading-level skip (no h4+)"     "<h4|<h5|<h6"                         "$VIEW"
# Live regions across the surface.
has "posture is a live region"            "id=\"wpcc-dash-posture\".*role=\"status\"" "$VIEW"
has "invariants is a live region"         "id=\"wpcc-dash-invariants\".*role=\"status\"" "$VIEW"
# States: distinct empty (nothing recorded) vs filter-no-match vs load failure.
has "activity empty (nothing recorded)"   "i18n.actEmpty"                       "$VIEW"
has "activity filter-no-match state"      "i18n.actNoMatch"                     "$VIEW"
has "unified load-failure path"           "function showLoadFail"               "$VIEW"

echo
echo "== 5b. i18n completeness (no raw user-facing JS strings) =="
RAW_STRINGS="$(awk '/<script>/,/<\/script>/' "$VIEW" \
	| rg -v "wp-command-center'" \
	| rg -n "'[A-Za-z]+ [A-Za-z]+ [A-Za-z]+" \
	| rg -v "wpcc-|aria-|scope=|class=|widefat|encodeURIComponent" || true)"
if [ -z "$RAW_STRINGS" ]; then pass "no un-localized user-facing JS string literals"; else fail "un-localized strings: $RAW_STRINGS"; fi

echo
echo "== 5c. empty / error states present =="
has "load failure state"                  "i18n.loadFail"                       "$VIEW"
has "fetch failure is caught"             "\.catch\( showLoadFail \)"           "$VIEW"

echo
echo "== 6. Functional: overview envelope over the real surfaces =="
if ! command -v wp >/dev/null 2>&1; then
	echo "  SKIP: wp-cli not available — static checks only."
else
	ACTION="$(wpe '$q = new \WPCommandCenter\Admin\DashboardAdminQuery(); $r = $q->overview(); echo (string) $r["action"];')"
	assert_eq "overview() action envelope" "dashboard_overview" "$ACTION"

	# Security posture present (mode + label).
	POSTURE="$(wpe '$q = new \WPCommandCenter\Admin\DashboardAdminQuery(); $r = $q->overview(); echo ( isset($r["security"]["mode"]) && isset($r["security"]["label"]) ) ? "yes" : "no";')"
	assert_eq "overview() carries security posture" "yes" "$POSTURE"

	# Posture mirrors SecurityModeManager (no new source of truth).
	PMATCH="$(wpe '$q = new \WPCommandCenter\Admin\DashboardAdminQuery(); $r = $q->overview(); echo ( $r["security"]["mode"] === \WPCommandCenter\Operations\SecurityModeManager::current() ) ? "match" : "drift";')"
	assert_eq "posture mirrors SecurityModeManager" "match" "$PMATCH"

	# Invariants block.
	INV_OPMAP="$(wpe '$q = new \WPCommandCenter\Admin\DashboardAdminQuery(); $r = $q->overview(); echo (int) $r["invariants"]["operation_map"];')"
	assert_eq "invariants.operation_map = 34" "34" "$INV_OPMAP"
	INV_CAPS="$(wpe '$q = new \WPCommandCenter\Admin\DashboardAdminQuery(); $r = $q->overview(); echo (int) $r["invariants"]["capabilities"];')"
	assert_eq "invariants.capabilities = 23" "23" "$INV_CAPS"
	INV_CAT="$(wpe '$q = new \WPCommandCenter\Admin\DashboardAdminQuery(); $r = $q->overview(); echo (int) $r["invariants"]["catalogue"];')"
	assert_eq "invariants.catalogue = 40" "40" "$INV_CAT"
	INV_MCP="$(wpe '$q = new \WPCommandCenter\Admin\DashboardAdminQuery(); $r = $q->overview(); echo (int) $r["invariants"]["mcp_tools"];')"
	assert_eq "invariants.mcp_tools = 40 (one per operation)" "40" "$INV_MCP"
	INV_DB="$(wpe '$q = new \WPCommandCenter\Admin\DashboardAdminQuery(); $r = $q->overview(); echo (string) $r["invariants"]["db_version"];')"
	assert_eq "invariants.db_version = 2.4.0" "2.4.0" "$INV_DB"

	# catalogue invariant mirrors OperationRegistry exactly (no drift).
	CATMATCH="$(wpe '$reg = new \WPCommandCenter\Operations\OperationRegistry(); $base = count( $reg->get_operations() ); $q = new \WPCommandCenter\Admin\DashboardAdminQuery(); $r = $q->overview(); echo ( (int) $r["invariants"]["catalogue"] === $base ) ? "match" : "drift";')"
	assert_eq "catalogue mirrors OperationRegistry" "match" "$CATMATCH"

	# Subsystem summaries present + shaped.
	AP_OK="$(wpe '$q = new \WPCommandCenter\Admin\DashboardAdminQuery(); $r = $q->overview(); $a = $r["approvals"]; echo ( isset($a["pending"]) && isset($a["pending_critical"]) && isset($a["resolved"]) && isset($a["queue_failed"]) ) ? "ok" : "bad";')"
	assert_eq "approvals summary shape" "ok" "$AP_OK"
	OP_OK="$(wpe '$q = new \WPCommandCenter\Admin\DashboardAdminQuery(); $r = $q->overview(); $o = $r["operations"]; echo ( isset($o["total"]) && isset($o["available"]) && isset($o["requires_approval"]) && isset($o["unrestricted"]) && is_array($o["by_risk"]) ) ? "ok" : "bad";')"
	assert_eq "operations summary shape" "ok" "$OP_OK"
	TK_OK="$(wpe '$q = new \WPCommandCenter\Admin\DashboardAdminQuery(); $r = $q->overview(); $t = $r["tokens"]; echo ( isset($t["total"]) && isset($t["capabilities"]) ) ? "ok" : "bad";')"
	assert_eq "tokens summary shape" "ok" "$TK_OK"
	CH_OK="$(wpe '$q = new \WPCommandCenter\Admin\DashboardAdminQuery(); $r = $q->overview(); echo ( isset($r["change_history"]["sessions"]) ) ? "ok" : "bad";')"
	assert_eq "change_history summary shape" "ok" "$CH_OK"

	# STEP 109.2 — recent activity feed: present, an array, bounded to <= 5.
	RA_ARR="$(wpe '$q = new \WPCommandCenter\Admin\DashboardAdminQuery(); $r = $q->overview(); echo is_array($r["recent_activity"] ?? null) ? "yes" : "no";')"
	assert_eq "recent_activity is an array" "yes" "$RA_ARR"
	RA_BOUND="$(wpe '$q = new \WPCommandCenter\Admin\DashboardAdminQuery(); $r = $q->overview(); echo ( count($r["recent_activity"]) <= 5 ) ? "ok" : "over";')"
	assert_eq "recent_activity bounded to <= 5" "ok" "$RA_BOUND"
	# If any session exists, the row carries the fields the feed renders.
	RA_SHAPE="$(wpe '$q = new \WPCommandCenter\Admin\DashboardAdminQuery(); $r = $q->overview(); $rows = $r["recent_activity"]; if ( empty($rows) ) { echo "ok"; } else { $row = $rows[0]; echo ( array_key_exists("session_id",$row) && array_key_exists("last_at",$row) && array_key_exists("change_count",$row) && array_key_exists("reversible_count",$row) && array_key_exists("actor_summary",$row) && is_array($row["runtimes"]) ) ? "ok" : "bad"; }')"
	assert_eq "recent_activity row shape" "ok" "$RA_SHAPE"
	# The feed and the change-history count come from the SAME roll-up (no drift):
	# the feed length never exceeds the distinct-session total.
	RA_MIRROR="$(wpe '$q = new \WPCommandCenter\Admin\DashboardAdminQuery(); $r = $q->overview(); echo ( count($r["recent_activity"]) <= (int) $r["change_history"]["sessions"] ) ? "ok" : "drift";')"
	assert_eq "recent_activity rows <= change_history.sessions (same roll-up)" "ok" "$RA_MIRROR"

	# Numbers mirror the surfaces that own them (no new source of truth).
	OPMATCH="$(wpe '$ops = ( new \WPCommandCenter\Admin\OperationExplorerAdminQuery() )->summary(); $q = new \WPCommandCenter\Admin\DashboardAdminQuery(); $r = $q->overview(); echo ( (int) $r["operations"]["total"] === (int) $ops["total"] && (int) $r["operations"]["unrestricted"] === (int) $ops["unmapped_count"] ) ? "match" : "drift";')"
	assert_eq "operations card mirrors Operations Explorer" "match" "$OPMATCH"
	APMATCH="$(wpe '$ap = ( new \WPCommandCenter\Admin\ApprovalAdminQuery() )->summary(); $q = new \WPCommandCenter\Admin\DashboardAdminQuery(); $r = $q->overview(); echo ( (int) $r["approvals"]["pending"] === (int) $ap["pending"] ) ? "match" : "drift";')"
	assert_eq "approvals card mirrors Approval Center" "match" "$APMATCH"
	TKMATCH="$(wpe '$caps = ( new \WPCommandCenter\Admin\TokenCapabilityAdminQuery() )->capabilities(); $q = new \WPCommandCenter\Admin\DashboardAdminQuery(); $r = $q->overview(); echo ( (int) $r["tokens"]["capabilities"] === (int) $caps["total"] ) ? "match" : "drift";')"
	assert_eq "tokens card mirrors capability catalogue (23)" "match" "$TKMATCH"

	# capabilities catalogue total equals the invariant (23).
	CAPTOTAL="$(wpe '$q = new \WPCommandCenter\Admin\DashboardAdminQuery(); $r = $q->overview(); echo (int) $r["tokens"]["capabilities"];')"
	assert_eq "tokens.capabilities = 23" "23" "$CAPTOTAL"

	# Read does not mutate state: a second call yields the same catalogue count.
	STABLE="$(wpe '$q = new \WPCommandCenter\Admin\DashboardAdminQuery(); $q->overview(); $r = $q->overview(); echo (int) $r["invariants"]["catalogue"];')"
	assert_eq "repeat read is stable (no mutation)" "40" "$STABLE"

	echo
	echo "== 6b. FeatureGate seam (ungated today; per-key) =="
	FG_DEFAULT="$(wpe 'echo \WPCommandCenter\Admin\FeatureGate::allows("dashboard_overview") ? "yes" : "no";')"
	assert_eq "FeatureGate allows dashboard_overview by default" "yes" "$FG_DEFAULT"
	FG_OFF="$(wpe 'add_filter("wpcc_feature_allowed", function($a,$f){ return ("dashboard_overview"===$f) ? false : $a; }, 10, 2); echo \WPCommandCenter\Admin\FeatureGate::allows("dashboard_overview") ? "yes" : "no";')"
	assert_eq "filter can gate dashboard_overview off" "no" "$FG_OFF"
	FG_OTHER="$(wpe 'add_filter("wpcc_feature_allowed", function($a,$f){ return ("dashboard_overview"===$f) ? false : $a; }, 10, 2); echo \WPCommandCenter\Admin\FeatureGate::allows("operations_explorer") ? "yes" : "no";')"
	assert_eq "gating is per-key (operations_explorer unaffected)" "yes" "$FG_OTHER"

	echo
	echo "== 7. Invariants unchanged (34 ops mapped / 23 caps / 40 catalogue / 40 MCP / 2.4.0) =="
	OPMAP="$(wpe 'echo count( \WPCommandCenter\Operations\CapabilityRegistry::OPERATION_MAP );')"
	assert_eq "OPERATION_MAP stays 34" "34" "$OPMAP"
	CAPS="$(wpe 'echo count( \WPCommandCenter\Operations\CapabilityRegistry::ALL_CAPABILITIES );')"
	assert_eq "ALL_CAPABILITIES stays 23" "23" "$CAPS"
	CAT="$(wpe '$reg = new \WPCommandCenter\Operations\OperationRegistry(); echo count( $reg->get_operations() );')"
	assert_eq "operation catalogue stays 40" "40" "$CAT"
	MCP="$(wpe '$r = ( new \WPCommandCenter\Mcp\McpServerRuntime() )->handle( [ "jsonrpc" => "2.0", "id" => 1, "method" => "tools/list" ], [] ); echo isset( $r["result"]["tools"] ) ? count( $r["result"]["tools"] ) : -1;')"
	assert_eq "MCP tools stay 40" "40" "$MCP"
	DBV="$(wpe 'echo get_option("wpcc_db_version");')"
	assert_eq "DB_VERSION stays 2.4.0" "2.4.0" "$DBV"
fi

echo
echo "== RESULT =="
echo "  PASS=$PASS FAIL=$FAIL"
[ "$FAIL" -eq 0 ]
