#!/usr/bin/env bash
#
# STEP 108.1 — Operations Explorer (read surface) acceptance suite.
#
# Validates the read-only wp-admin discovery surface over the operation catalogue
# (OperationRegistry, STEP 15/80) joined with the authorization map
# (CapabilityRegistry, STEP 38/44/79) and the security mode (SecurityModeManager,
# STEP 80) WITHOUT introducing any runtime/MCP/storage/policy changes:
#
#   - PHP lint of every new/changed admin file
#   - Admin REST read routes registered (operations / operations/summary) behind a
#     manage_options + FeatureGate permission gate; NO write/execute routes
#   - The aggregation class is read-only: it never writes, never executes an
#     operation, never dispatches the engine, and re-derives the required
#     capability straight from CapabilityRegistry::OPERATION_MAP
#   - Menu: "Operations Explorer" submenu added, FeatureGate-gated
#   - View: filterable catalogue table, escaped output, NO write/run controls
#   - Functional (wp-cli, real bootstrap path): catalogue = 40 operations, exactly
#     34 carry a required capability (LEFT JOIN over OPERATION_MAP), 6 unrestricted,
#     the 5 read-only-scope operations are flagged, per-action risk preserved, and
#     availability mirrors OperationRegistry::get_operations()
#   - Invariants: operation_map stays 34, capabilities stay 23, catalogue stays 40
#     (this step adds no runtime op, MCP tool, or capability)
#
# Requires: php, rg, wp-cli, wpcc-env.sh. (Admin routes are cookie+nonce, so the
# functional checks exercise the aggregation class directly via wp-cli.)
# Usage: bash tests/test-operations-explorer.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_ROOT="$(cd "$PLUGIN_DIR/../../.." && pwd)"

# shellcheck source=/dev/null
[ -f "$PLUGIN_DIR/wpcc-env.sh" ] && source "$PLUGIN_DIR/wpcc-env.sh"

VIEW="$PLUGIN_DIR/includes/Admin/views/operations-explorer.php"
RESTAPI="$PLUGIN_DIR/includes/Admin/AdminRestApi.php"
QUERY="$PLUGIN_DIR/includes/Admin/OperationExplorerAdminQuery.php"
MENU="$PLUGIN_DIR/includes/Admin/AdminMenu.php"
SHELL="$PLUGIN_DIR/includes/Admin/AppShell.php"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq()  { local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }
has()  { if rg -q -e "$2" "$3"; then pass "$1"; else fail "$1"; fi; }
lacks(){ if rg -q -e "$2" "$3"; then fail "$1"; else pass "$1"; fi; }
lint() { if php -l "$2" >/dev/null 2>&1; then pass "$1"; else fail "$1"; fi; }
wpe()  { wp --path="$WP_ROOT" eval "$1" 2>/dev/null; }

echo "== 1. PHP lint =="
lint "view lints"                        "$VIEW"
lint "AdminRestApi lints"                "$RESTAPI"
lint "OperationExplorerAdminQuery lints" "$QUERY"
lint "AdminMenu lints"                   "$MENU"

echo
echo "== 2. Admin REST routes registered (read-only) =="
has "route: /admin/operations"           "'/admin/operations'"          "$RESTAPI"
has "route: /admin/operations/summary"   "'/admin/operations/summary'"  "$RESTAPI"
has "permission gate helper present"     "function check_operations_permission" "$RESTAPI"
has "operations surface maps to FeatureGate key (C1 consolidated gate)" "'operations'\s*=> 'operations_explorer'" "$RESTAPI"
has "list handler present"               "function operations_list"     "$RESTAPI"
has "summary handler present"            "function operations_summary"  "$RESTAPI"
has "handlers delegate to query"         "new OperationExplorerAdminQuery\(\)" "$RESTAPI"
# Literal /summary route registered before the bare /operations/{id} wildcard
# (the detail route arrives in 108.2; today there is no wildcard, so /summary must
# at minimum come AFTER the bare /operations collection route).
COLL=$(rg -n "'/admin/operations'" "$RESTAPI" | head -1 | cut -d: -f1)
SUMM=$(rg -n "'/admin/operations/summary'" "$RESTAPI" | head -1 | cut -d: -f1)
if [ -n "$COLL" ] && [ -n "$SUMM" ] && [ "$COLL" -lt "$SUMM" ]; then pass "collection route before /summary"; else fail "route ordering ($COLL vs $SUMM)"; fi

echo
echo "== 2a. STEP 108.2 — operation detail route (read-only) =="
has "route: /admin/operations/{id}"      "/admin/operations/\(\?P<id>" "$RESTAPI"
has "detail handler present"             "function operation_detail"   "$RESTAPI"
DETAIL_ROUTE="$(awk '/register_rest_route\( self::NS, .\/admin\/operations\/\(\?P<id>/{f=1} f{print} f && /\] \);/{exit}' "$RESTAPI")"
if printf '%s' "$DETAIL_ROUTE" | rg -q -e "WP_REST_Server::READABLE" && printf '%s' "$DETAIL_ROUTE" | rg -q -e "operation_detail"; then pass "detail route is READABLE"; else fail "detail route is READABLE"; fi
has "detail 404s unknown operation"      "'operation_not_found'"       "$RESTAPI"
# /summary literal must be registered BEFORE the bare /operations/{id} wildcard.
SUMM2=$(rg -n "'/admin/operations/summary'" "$RESTAPI" | head -1 | cut -d: -f1)
WILD=$(rg -n "/admin/operations/\(\?P<id>" "$RESTAPI" | head -1 | cut -d: -f1)
if [ -n "$SUMM2" ] && [ -n "$WILD" ] && [ "$SUMM2" -lt "$WILD" ]; then pass "/summary before /operations/{id} wildcard"; else fail "literal-before-wildcard ordering ($SUMM2 vs $WILD)"; fi

echo
echo "== 2b. Surface is execution-free (no write/run routes, no engine) =="
# All operations routes (list / summary / detail) are reads — no CREATABLE/
# DELETABLE methods anywhere in the operations route-registration block.
OPS_ROUTES="$(awk '/STEP 108.1 — Operations Explorer read/,/STEP 108.2 — single-operation detail/{print} /register_rest_route\( self::NS, .\/admin\/operations\/\(\?P<id>/{print; found=1} found && /\] \);/{print; exit}' "$RESTAPI")"
if printf '%s' "$OPS_ROUTES" | rg -q -e "CREATABLE|DELETABLE|EDITABLE"; then fail "operations routes are read-only (no write methods)"; else pass "operations routes are read-only (no write methods)"; fi
# The three operations handlers never dispatch the engine.
OPS_HANDLERS="$(awk '/function operations_list/,/^\t}$/{print} /function operations_summary/,/^\t}$/{print} /function operation_detail/,/^\t}$/{print}' "$RESTAPI")"
if printf '%s' "$OPS_HANDLERS" | rg -q -e "OperationExecutor|->run\(|->execute\("; then fail "operations handlers never execute (no engine dispatch)"; else pass "operations handlers never execute (no engine dispatch)"; fi

echo
echo "== 3. Aggregation class is read-only (no writes, no execution, no policy) =="
has "reads operation registry"           "OperationRegistry"            "$QUERY"
has "reads capability registry"          "CapabilityRegistry"           "$QUERY"
has "reads security mode"                "SecurityModeManager"          "$QUERY"
has "required cap derived from OPERATION_MAP" "OPERATION_MAP\[ \\\$id \]" "$QUERY"
lacks "no writes (update_option/wpdb)"   "update_option|->update\(|->insert\(|\\\$wpdb" "$QUERY"
lacks "no engine dispatch"               "OperationExecutor"            "$QUERY"
lacks "no operation execution"           "->run\(|->execute\(|->dispatch\(" "$QUERY"
lacks "no audit/change writes"           "->record\(|ChangeRecorder"    "$QUERY"
lacks "no policy mutation"               "->assign\(|->remove\(|->create\(|->revoke\(|->delete\(" "$QUERY"

echo
echo "== 4. App Shell hosts Operations Explorer as Operate › Operations =="
# Phase 1 IA: the catalogue became Settings › Capabilities (the read-only contract),
# routed by the App Shell via ?wpcc_tab=capabilities; legacy slugs redirect in.
has "Capabilities tab labeled in shell"    "__\( 'Capabilities'"          "$SHELL"
has "Capabilities tab renders explorer view" "'view' => 'operations-explorer'" "$SHELL"
has "Capabilities tab gated by operations_explorer feature" "'feature' => 'operations_explorer'" "$SHELL"
has "FeatureGate gates the Capabilities tab" "FeatureGate::allows"        "$SHELL"
has "legacy operations slug redirects (map)" "'wpcc-operations'         => \[ self::SETTINGS_SLUG, 'capabilities' \]" "$SHELL"
has "Settings section registered"        "render_settings"              "$MENU"

echo
echo "== 5. View is read-only + escaped + filterable =="
has "escapes via shared WPCC.escHtml runtime" "escHtml = WPCC.escHtml"   "$VIEW"
lacks "no per-view escHtml definition (D1)" "function escHtml"           "$VIEW"
has "mints + passes REST nonce to WPCC.api" "apiBase \+ path, nonce"      "$VIEW"
has "fetches paginated /operations"       "/operations?"                 "$VIEW"
has "fetches /operations/summary"         "/operations/summary'"         "$VIEW"
has "text filter present"                 "wpcc-ops-search"              "$VIEW"
has "risk filter present"                 "wpcc-ops-risk"                "$VIEW"
has "available-only filter present"       "wpcc-ops-available"           "$VIEW"
has "role=status live region"             "role=\"status\""              "$VIEW"
# S2.1 — server-side pagination: query carries limit/offset, Prev/Next pager, no client load-all.
has "query sends limit"                   "limit="                       "$VIEW"
has "query sends offset"                  "offset="                      "$VIEW"
has "consumes canonical items[]"          "body.items"                   "$VIEW"
has "consumes total_count"                "total_count"                  "$VIEW"
has "consumes has_more"                   "has_more"                     "$VIEW"
has "Prev/Next pager present"             "wpcc-ops-pager"               "$VIEW"
has "server-side reload on filter"        "function reload"              "$VIEW"
lacks "no client-side load-all filtering" "allOps.filter"               "$VIEW"
has "renders required capability"         "required_capability"          "$VIEW"
has "renders availability"                "availBadge"                   "$VIEW"
# No execution / write affordance on this surface.
lacks "no run/execute control"            "wpcc-op-run|Execute|execute_request|apiFetch\( apiBase, \{ method" "$VIEW"
lacks "no write method in view fetch"     "method: 'POST'|method: 'DELETE'|CREATABLE" "$VIEW"

echo
echo "== 5b. STEP 108.2 — detail panel rendering =="
has "detail drill via ?view=<id>"        "wpcc-op-detail"               "$VIEW"
has "rows link to detail"                 "function detailUrl"          "$VIEW"
has "fetches /operations/{id}"            "/operations/' \+ encodeURIComponent" "$VIEW"
has "detail render function"              "function renderDetail"       "$VIEW"
has "section: Parameters"                 "secParams"                   "$VIEW"
has "parameters table rendered"           "op.parameters"               "$VIEW"
has "section: Action risk breakdown"      "secActions"                  "$VIEW"
has "action_risks rendered"               "op.action_risks"             "$VIEW"
has "section: Authorization"              "secAuth"                     "$VIEW"
has "required capability rendered"        "auth.required_capability"    "$VIEW"
has "system.admin unlock surfaced"        "unlocked_by_admin|adminUnlocks" "$VIEW"
has "section: Approval"                   "secApproval"                 "$VIEW"
has "approval reflects security mode"     "sec.requires_approval"       "$VIEW"
has "section: Availability"               "secAvail"                    "$VIEW"
has "availability explanation"            "availYes|availNo"            "$VIEW"
has "detail 404 handled"                  "i18n.notFound"               "$VIEW"
# Detail panel is still read-only — no run affordance.
lacks "detail has no execute control"     "wpcc-op-run|runOperation|method: 'POST'" "$VIEW"

echo
echo "== 5c. STEP 108.3 — accessibility sweep =="
has "filter search has a label"           "for=\"wpcc-ops-search\""      "$VIEW"
has "filter risk has a label"             "for=\"wpcc-ops-risk\""        "$VIEW"
has "table column headers use scope=col"  "<th scope=\"col\">"           "$VIEW"
has "row header uses scope=row"           "<th scope=\"row\">"           "$VIEW"
# Risk/availability pills pass an accessible label into the CDS helper (3rd arg).
has "risk pill carries aria context"      "i18n.colRisk \+ ': '"         "$VIEW"
has "availability pill carries aria context" "i18n.colAvail \+ ': '"     "$VIEW"
has "live region: filter count (role=status)" "id=\"wpcc-ops-count\" class=\"wpcc-ops-count\" role=\"status\"" "$VIEW"
has "live region: summary (role=status)"  "id=\"wpcc-ops-summary\".*role=\"status\"" "$VIEW"
has "aria-live polite on live regions"    "aria-live=\"polite\""         "$VIEW"
has "detail sections are h3 (under page h1/op h2)" "<h3>' \+ escHtml\( title" "$VIEW"
has "filters reference panel (aria-controls)" "aria-controls=\"wpcc-ops-panel\"" "$VIEW"

echo
echo "== 5d. STEP 108.3 — i18n completeness (no raw user-facing JS strings) =="
# Extract the <script> region, drop every PHP-localized line (lines containing a
# __( … 'wp-command-center' ) call), then look for any remaining quoted 3+ word
# English sentence — a sign of an un-localized literal. None expected.
RAW_STRINGS="$(awk '/<script>/,/<\/script>/' "$VIEW" \
	| rg -v "wp-command-center'" \
	| rg -n "'[A-Za-z]+ [A-Za-z]+ [A-Za-z]+" \
	| rg -v "wpcc-|aria-|scope=|class=|widefat|encodeURIComponent" || true)"
if [ -z "$RAW_STRINGS" ]; then pass "no un-localized user-facing JS string literals"; else fail "un-localized strings: $RAW_STRINGS"; fi

echo
echo "== 5e. STEP 108.3 — empty / error states present =="
has "list empty state"                    "i18n.empty"                   "$VIEW"
has "list/load failure state"             "i18n.loadFail"                "$VIEW"
has "detail not-found (404) state"         "i18n.notFound"                "$VIEW"
has "no-parameters empty state"            "i18n.noParams"                "$VIEW"
has "no-action-breakdown empty state"      "i18n.noActions"               "$VIEW"
has "fetch failure is caught"              "\.catch\( function\(\)"       "$VIEW"

echo
echo "== 5f. CDS Phase 1 adoption — shared runtime + CDS helpers (D1/M2 closure) =="
# Runtime: the view consumes window.WPCC + WPCC.api instead of re-declaring helpers.
has "uses window.WPCC runtime"            "window.WPCC"                  "$VIEW"
has "uses WPCC.api for reads"             "WPCC.api"                     "$VIEW"
lacks "no raw fetch() in the view (M2)"   "fetch\("                      "$VIEW"
lacks "no duplicated escHtml DOM builder" "createTextNode"               "$VIEW"
# Render: badges/status/tags/states come from the shared CDS helpers, not per-view HTML.
has "risk via CDS risk pill"              "cds\.riskPill"                "$VIEW"
has "status via CDS status pill"          "cds\.statusPill"              "$VIEW"
has "capability via CDS tag"              "cds\.tag"                     "$VIEW"
has "summary via CDS kpi"                 "cds\.kpi"                     "$VIEW"
has "empty state via CDS helper"          "cds\.empty"                   "$VIEW"
has "error state via CDS helper"          "cds\.error"                   "$VIEW"
has "pager via CDS button helper"         "cds\.button"                  "$VIEW"
# Styling: CDS classes + tokens replace the local badge/chip/empty CSS.
has "table adopts CDS table treatment"    "wpcc-cds-table"               "$VIEW"
has "fields adopt CDS focus-ring class"   "wpcc-cds-field"               "$VIEW"
has "loading state via CDS class"         "wpcc-cds-loading"             "$VIEW"
has "view styles bind to CDS tokens"      "var\(--wpcc-"                 "$VIEW"
lacks "no hardcoded hex colors in view"   "#[0-9a-fA-F]{6}"              "$VIEW"
lacks "legacy local badge class removed"  "wpcc-badge"                   "$VIEW"
lacks "legacy local chip class removed"   "class=\"wpcc-chip"            "$VIEW"

echo
echo "== 6. Functional: catalogue join over the real registries =="
if ! command -v wp >/dev/null 2>&1; then
	echo "  SKIP: wp-cli not available — static checks only."
else
	# Catalogue total + availability + security mode envelope.
	TOTAL="$(wpe '$q = new \WPCommandCenter\Admin\OperationExplorerAdminQuery(); $r = $q->operations(); echo (int) $r["total_count"];')"
	assert_eq "operations() total_count = 40" "40" "$TOTAL"

	ACTION="$(wpe '$q = new \WPCommandCenter\Admin\OperationExplorerAdminQuery(); $r = $q->operations(); echo (string) $r["action"];')"
	assert_eq "operations() action envelope" "operations_list" "$ACTION"

	MODE_OK="$(wpe '$q = new \WPCommandCenter\Admin\OperationExplorerAdminQuery(); $r = $q->operations(); echo ( isset($r["security_mode"]["mode"]) && isset($r["security_mode"]["label"]) ) ? "yes" : "no";')"
	assert_eq "operations() carries security mode" "yes" "$MODE_OK"

	# ── S2.1 — canonical pagination envelope (items/total_count/returned/has_more/next_cursor/limit/offset/filters) ──
	SHAPE_OK="$(wpe '$q = new \WPCommandCenter\Admin\OperationExplorerAdminQuery(); $r = $q->operations(); $keys = ["items","total_count","returned","has_more","next_cursor","limit","offset","filters"]; $ok = "yes"; foreach ($keys as $k) { if ( ! array_key_exists($k, $r) ) { $ok = "no"; } } echo $ok;')"
	assert_eq "operations() returns the canonical envelope keys" "yes" "$SHAPE_OK"

	# Default page is bounded (limit 20), total_count is the full catalogue.
	PAGE1="$(wpe '$q = new \WPCommandCenter\Admin\OperationExplorerAdminQuery(); $r = $q->operations([], 20, 0); echo count($r["items"]) . "/" . (int)$r["returned"] . "/" . (int)$r["limit"] . "/" . ( $r["has_more"] ? "more" : "end" );')"
	assert_eq "operations() page 1 of 20 (items/returned/limit/has_more)" "20/20/20/more" "$PAGE1"

	# limit/offset walk: page 2 returns the remaining 20, has_more=false.
	PAGE2="$(wpe '$q = new \WPCommandCenter\Admin\OperationExplorerAdminQuery(); $r = $q->operations([], 20, 20); echo count($r["items"]) . "/" . (int)$r["offset"] . "/" . ( $r["has_more"] ? "more" : "end" );')"
	assert_eq "operations() page 2 (offset 20) ends the list" "20/20/end" "$PAGE2"

	# next_cursor on page 1 decodes to the next offset; null at the end.
	CURSOR="$(wpe '$q = new \WPCommandCenter\Admin\OperationExplorerAdminQuery(); $r = $q->operations([], 20, 0); $d = json_decode( base64_decode( (string) $r["next_cursor"] ), true ); echo (int) ($d["offset"] ?? -1);')"
	assert_eq "operations() next_cursor encodes offset 20" "20" "$CURSOR"
	ENDCURSOR="$(wpe '$q = new \WPCommandCenter\Admin\OperationExplorerAdminQuery(); $r = $q->operations([], 20, 20); echo ( null === $r["next_cursor"] ) ? "null" : "set";')"
	assert_eq "operations() next_cursor is null at the end" "null" "$ENDCURSOR"

	# No pages dropped or duplicated across the full walk.
	WALK="$(wpe '$q = new \WPCommandCenter\Admin\OperationExplorerAdminQuery(); $seen = []; $off = 0; do { $r = $q->operations([], 7, $off); foreach ($r["items"] as $o) { $seen[$o["id"]] = true; } $off += 7; } while ( $r["has_more"] && $off < 500 ); echo count($seen);')"
	assert_eq "paged walk (limit 7) visits all 40 unique ops" "40" "$WALK"

	# ── S2.1 — server-side filtering (replaces in-JS filtering) ──
	RISKF="$(wpe '$q = new \WPCommandCenter\Admin\OperationExplorerAdminQuery(); $r = $q->operations(["risk" => "diagnostic"], 100, 0); $ok = "yes"; foreach ($r["items"] as $o) { if ($o["risk_level"] !== "diagnostic") { $ok = "no"; } } echo ( $r["total_count"] === count($r["items"]) && $ok === "yes" && $r["total_count"] > 0 ) ? "yes" : "no";')"
	assert_eq "operations() server-side risk filter returns only that tier" "yes" "$RISKF"

	SEARCHF="$(wpe '$q = new \WPCommandCenter\Admin\OperationExplorerAdminQuery(); $r = $q->operations(["search" => "plugin_manage"], 100, 0); $found = "no"; foreach ($r["items"] as $o) { if ($o["id"] === "plugin_manage") { $found = "yes"; } } echo $found;')"
	assert_eq "operations() server-side search finds plugin_manage" "yes" "$SEARCHF"

	SEARCHNONE="$(wpe '$q = new \WPCommandCenter\Admin\OperationExplorerAdminQuery(); $r = $q->operations(["search" => "zzz_no_such_op_zzz"], 100, 0); echo (int) $r["total_count"];')"
	assert_eq "operations() search with no match returns 0" "0" "$SEARCHNONE"

	AVAILF="$(wpe '$q = new \WPCommandCenter\Admin\OperationExplorerAdminQuery(); $r = $q->operations(["available" => true], 100, 0); $ok = "yes"; foreach ($r["items"] as $o) { if ( empty($o["available"]) ) { $ok = "no"; } } echo $ok;')"
	assert_eq "operations() available-only filter returns only available" "yes" "$AVAILF"

	FILTERS_ECHO="$(wpe '$q = new \WPCommandCenter\Admin\OperationExplorerAdminQuery(); $r = $q->operations(["risk" => "low"], 100, 0); $f = (array) $r["filters"]; echo (string) ($f["risk"] ?? "");')"
	assert_eq "operations() echoes applied filters" "low" "$FILTERS_ECHO"

	# LEFT JOIN correctness over the FULL catalogue (fetch all via a wide page).
	MAPPED="$(wpe '$q = new \WPCommandCenter\Admin\OperationExplorerAdminQuery(); $r = $q->operations([], 100, 0); $n = 0; foreach ( $r["items"] as $o ) { if ( ! empty( $o["required_capability"] ) ) { $n++; } } echo $n;')"
	assert_eq "exactly 34 operations carry a required capability" "34" "$MAPPED"

	UNMAPPED="$(wpe '$q = new \WPCommandCenter\Admin\OperationExplorerAdminQuery(); $r = $q->summary(); echo (int) $r["unmapped_count"];')"
	assert_eq "summary unmapped_count = 6" "6" "$UNMAPPED"

	SUM_TOTAL="$(wpe '$q = new \WPCommandCenter\Admin\OperationExplorerAdminQuery(); $r = $q->summary(); echo (int) $r["total"];')"
	assert_eq "summary total = 40" "40" "$SUM_TOTAL"

	# Required capability matches OPERATION_MAP for a representative mapped op.
	PLUGCAP="$(wpe '$q = new \WPCommandCenter\Admin\OperationExplorerAdminQuery(); $r = $q->operations([], 100, 0); foreach ( $r["items"] as $o ) { if ( $o["id"] === "plugin_manage" ) { echo (string) $o["required_capability"]; break; } }')"
	assert_eq "plugin_manage required capability = plugin.manage" "plugin.manage" "$PLUGCAP"

	# Unrestricted operation surfaced honestly (system_info has no capability).
	SYSCAP="$(wpe '$q = new \WPCommandCenter\Admin\OperationExplorerAdminQuery(); $r = $q->operations([], 100, 0); foreach ( $r["items"] as $o ) { if ( $o["id"] === "system_info" ) { echo ( null === $o["required_capability"] ) ? "null" : (string) $o["required_capability"]; break; } }')"
	assert_eq "system_info is unrestricted (null capability)" "null" "$SYSCAP"

	# read-only-scope flag matches READ_ONLY_SCOPE_OPERATIONS (5).
	ROCOUNT="$(wpe '$q = new \WPCommandCenter\Admin\OperationExplorerAdminQuery(); $r = $q->operations([], 100, 0); $n = 0; foreach ( $r["items"] as $o ) { if ( ! empty( $o["read_only_scope"] ) ) { $n++; } } echo $n;')"
	assert_eq "exactly 5 read-only-scope operations flagged" "5" "$ROCOUNT"

	# Per-action risk preserved in the action_count (plugin_manage has 6).
	PLUGACTIONS="$(wpe '$q = new \WPCommandCenter\Admin\OperationExplorerAdminQuery(); $r = $q->operations([], 100, 0); foreach ( $r["items"] as $o ) { if ( $o["id"] === "plugin_manage" ) { echo (int) $o["action_count"]; break; } }')"
	assert_eq "plugin_manage action_count = 6" "6" "$PLUGACTIONS"

	# Availability mirrors OperationRegistry::get_operations() exactly (no drift).
	AVAIL_MATCH="$(wpe '$reg = new \WPCommandCenter\Operations\OperationRegistry(); $base = []; foreach ( $reg->get_operations() as $o ) { $base[ $o["id"] ] = (bool) $o["available"]; } $q = new \WPCommandCenter\Admin\OperationExplorerAdminQuery(); $r = $q->operations([], 100, 0); $ok = "yes"; foreach ( $r["items"] as $o ) { if ( ( $base[ $o["id"] ] ?? null ) !== (bool) $o["available"] ) { $ok = "no"; break; } } echo $ok;')"
	assert_eq "availability mirrors the registry (no drift)" "yes" "$AVAIL_MATCH"

	# Read does not mutate state: a second call yields the same total.
	TOTAL2="$(wpe '$q = new \WPCommandCenter\Admin\OperationExplorerAdminQuery(); $q->operations(); $r = $q->summary(); echo (int) $r["total"];')"
	assert_eq "repeat read is stable (no mutation)" "40" "$TOTAL2"

	echo
	echo "== 6b. Functional: STEP 108.2 operation detail =="
	# Unknown operation returns null (route → 404).
	NULLDET="$(wpe '$q = new \WPCommandCenter\Admin\OperationExplorerAdminQuery(); echo ( null === $q->operation("does_not_exist") ) ? "null" : "set";')"
	assert_eq "unknown operation detail is null (404)" "null" "$NULLDET"

	# Detail envelope shape.
	DACTION="$(wpe '$q = new \WPCommandCenter\Admin\OperationExplorerAdminQuery(); $d = $q->operation("plugin_manage"); echo (string) $d["action"];')"
	assert_eq "operation() action envelope" "operation_detail" "$DACTION"

	# Authorization block: required capability + read-only-scope + admin unlock.
	DCAP="$(wpe '$q = new \WPCommandCenter\Admin\OperationExplorerAdminQuery(); $d = $q->operation("plugin_manage"); echo (string) $d["authorization"]["required_capability"];')"
	assert_eq "detail required capability = plugin.manage" "plugin.manage" "$DCAP"
	DADMIN="$(wpe '$q = new \WPCommandCenter\Admin\OperationExplorerAdminQuery(); $d = $q->operation("plugin_manage"); echo $d["authorization"]["unlocked_by_admin"] ? "yes" : "no";')"
	assert_eq "detail surfaces system.admin unlock" "yes" "$DADMIN"

	# Unrestricted operation detail: null capability, honestly surfaced.
	DSYS="$(wpe '$q = new \WPCommandCenter\Admin\OperationExplorerAdminQuery(); $d = $q->operation("system_info"); echo ( null === $d["authorization"]["required_capability"] ) ? "null" : "set";')"
	assert_eq "system_info detail is unrestricted (null capability)" "null" "$DSYS"

	# Parameters table source: plugin_manage declares parameters (incl. injected reason).
	DPARAMS="$(wpe '$q = new \WPCommandCenter\Admin\OperationExplorerAdminQuery(); $d = $q->operation("plugin_manage"); echo ( is_array($d["operation"]["parameters"]) && count($d["operation"]["parameters"]) > 0 ) ? "yes" : "no";')"
	assert_eq "detail carries a parameters list" "yes" "$DPARAMS"
	DREASON="$(wpe '$q = new \WPCommandCenter\Admin\OperationExplorerAdminQuery(); $d = $q->operation("plugin_manage"); $has = "no"; foreach ( $d["operation"]["parameters"] as $p ) { if ( $p["name"] === "reason" ) { $has = "yes"; break; } } echo $has;')"
	assert_eq "non-diagnostic op exposes injected reason param" "yes" "$DREASON"

	# Action risk breakdown: each entry carries action + risk + per-action approval.
	DACTIONS="$(wpe '$q = new \WPCommandCenter\Admin\OperationExplorerAdminQuery(); $d = $q->operation("plugin_manage"); echo count( $d["operation"]["action_risks"] );')"
	assert_eq "detail action_risks count = 6" "6" "$DACTIONS"
	DACTSHAPE="$(wpe '$q = new \WPCommandCenter\Admin\OperationExplorerAdminQuery(); $d = $q->operation("plugin_manage"); $a = $d["operation"]["action_risks"][0]; echo ( isset($a["action"]) && isset($a["risk_level"]) && array_key_exists("requires_approval", $a) ) ? "ok" : "bad";')"
	assert_eq "action_risks entry shape (action/risk/approval)" "ok" "$DACTSHAPE"

	# plugin_delete is critical in the breakdown (per-action risk preserved).
	DCRIT="$(wpe '$q = new \WPCommandCenter\Admin\OperationExplorerAdminQuery(); $d = $q->operation("plugin_manage"); $r = ""; foreach ( $d["operation"]["action_risks"] as $a ) { if ( $a["action"] === "plugin_delete" ) { $r = $a["risk_level"]; break; } } echo $r;')"
	assert_eq "plugin_delete action risk = critical" "critical" "$DCRIT"

	# Approval posture mirrors SecurityModeManager in the CURRENT mode (no new policy).
	DAPPROVE="$(wpe '$q = new \WPCommandCenter\Admin\OperationExplorerAdminQuery(); $d = $q->operation("plugin_manage"); $exp = \WPCommandCenter\Operations\SecurityModeManager::requires_approval( $d["operation"]["risk_level"] ); echo ( $d["security"]["requires_approval"] === $exp ) ? "match" : "drift";')"
	assert_eq "approval display matches SecurityModeManager" "match" "$DAPPROVE"

	# Detail availability mirrors the catalogue (no drift, no re-implementation).
	DAVAIL="$(wpe '$reg = new \WPCommandCenter\Operations\OperationRegistry(); $base = $reg->get_operation("acf_manage"); $q = new \WPCommandCenter\Admin\OperationExplorerAdminQuery(); $d = $q->operation("acf_manage"); echo ( (bool) $base["available"] === (bool) $d["operation"]["available"] ) ? "match" : "drift";')"
	assert_eq "detail availability mirrors the registry" "match" "$DAVAIL"

	echo
	echo "== 6c. STEP 108.3 — FeatureGate seam (ungated today; filterable) =="
	# Default: the Operations Explorer feature is allowed (ungated).
	FG_DEFAULT="$(wpe 'echo \WPCommandCenter\Admin\FeatureGate::allows("operations_explorer") ? "yes" : "no";')"
	assert_eq "FeatureGate allows operations_explorer by default" "yes" "$FG_DEFAULT"

	# A future Free/Pro layer flips it via wpcc_feature_allowed WITHOUT touching any
	# call site — gating this single key returns false, and unrelated keys stay true.
	FG_OFF="$(wpe 'add_filter("wpcc_feature_allowed", function($a,$f){ return ("operations_explorer"===$f) ? false : $a; }, 10, 2); echo \WPCommandCenter\Admin\FeatureGate::allows("operations_explorer") ? "yes" : "no";')"
	assert_eq "filter can gate operations_explorer off" "no" "$FG_OFF"

	FG_OTHER="$(wpe 'add_filter("wpcc_feature_allowed", function($a,$f){ return ("operations_explorer"===$f) ? false : $a; }, 10, 2); echo \WPCommandCenter\Admin\FeatureGate::allows("change_history") ? "yes" : "no";')"
	assert_eq "gating is per-key (change_history unaffected)" "yes" "$FG_OTHER"

	echo
	echo "== 7. Invariants unchanged (34 ops mapped / 23 caps / 40 catalogue) =="
	OPMAP="$(wpe 'echo count( \WPCommandCenter\Operations\CapabilityRegistry::OPERATION_MAP );')"
	assert_eq "OPERATION_MAP stays 34" "34" "$OPMAP"
	CAPS="$(wpe 'echo count( \WPCommandCenter\Operations\CapabilityRegistry::ALL_CAPABILITIES );')"
	assert_eq "ALL_CAPABILITIES stays 23" "23" "$CAPS"
	CAT="$(wpe '$reg = new \WPCommandCenter\Operations\OperationRegistry(); echo count( $reg->get_operations() );')"
	assert_eq "operation catalogue stays 40" "40" "$CAT"

	# MCP tools = one per catalogue operation (McpServerRuntime tools/list). Assert
	# via the runtime handle (no token needed at this layer) so the 40-tool invariant
	# is proven, not merely inferred from the catalogue count.
	MCP="$(wpe '$r = ( new \WPCommandCenter\Mcp\McpServerRuntime() )->handle( [ "jsonrpc" => "2.0", "id" => 1, "method" => "tools/list" ], [] ); echo isset( $r["result"]["tools"] ) ? count( $r["result"]["tools"] ) : -1;')"
	assert_eq "MCP tools stay 40" "40" "$MCP"

	# DB schema version is untouched by this admin-only step.
	DBV="$(wpe 'echo get_option("wpcc_db_version");')"
	assert_eq "DB_VERSION stays 2.5.0" "2.5.0" "$DBV"
fi

echo
echo "== RESULT =="
echo "  PASS=$PASS FAIL=$FAIL"
[ "$FAIL" -eq 0 ]
