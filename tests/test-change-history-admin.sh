#!/usr/bin/env bash
#
# STEP 105.1 — Change History admin UI (read surface) acceptance suite.
#
# Validates the read-only wp-admin layer over the STEP 104 change-history
# backend WITHOUT introducing any runtime/MCP/storage changes:
#
#   - PHP lint of every new/changed admin file
#   - Admin REST read routes registered (history / timeline / sessions / get),
#     reads delegating to the STEP 104 runtime manager, sessions to the thin
#     presentation-layer aggregation
#   - Menu: "Change History" added; legacy "Rollback" RETAINED (removal is
#     deferred to STEP 105.3, by design — no admin-restore capability gap)
#   - View: three URL-driven tabs, session drill, minimal detail panel, and
#     output rendered exclusively through an HTML-escaper (XSS discipline)
#   - Functional aggregation via wp-cli: ChangeHistoryAdminQuery session counts
#     match the table exactly, and session-less rows are excluded from Sessions
#     while remaining visible to the flat history list (Timeline)
#   - Invariants: operation_map stays 34, capabilities stay 23 (this step adds
#     no runtime op, MCP tool, or capability)
#
# Requires: curl, jq, wp-cli, php, rg, wpcc-env.sh (full-scope $WPCC_TOKEN).
# Usage: bash tests/test-change-history-admin.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_ROOT="$(cd "$PLUGIN_DIR/../../.." && pwd)"

# shellcheck source=/dev/null
source "$PLUGIN_DIR/wpcc-env.sh"

VIEW="$PLUGIN_DIR/includes/Admin/views/change-history.php"
RESTAPI="$PLUGIN_DIR/includes/Admin/AdminRestApi.php"
QUERY="$PLUGIN_DIR/includes/Admin/ChangeHistoryAdminQuery.php"
MENU="$PLUGIN_DIR/includes/Admin/AdminMenu.php"
DIFFR="$PLUGIN_DIR/includes/Admin/DiffRenderer.php"
PATCHES="$PLUGIN_DIR/includes/Admin/views/patches.php"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq()  { local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }
assert_true(){ local d="$1" a="$2"; [ "$a" = "true" ] && pass "$d" || fail "$d (got '$a')"; }
assert_ge()  { local d="$1" a="$2" b="$3"; [ "$a" -ge "$b" ] 2>/dev/null && pass "$d" || fail "$d ($a < $b)"; }
assert_nonempty() { local d="$1" a="$2"; { [ -n "$a" ] && [ "$a" != "null" ]; } && pass "$d" || fail "$d (empty/null)"; }
has()  { if rg -q "$2" "$3"; then pass "$1"; else fail "$1"; fi; }
lacks(){ if rg -q "$2" "$3"; then fail "$1"; else pass "$1"; fi; }
lint() { if php -l "$2" >/dev/null 2>&1; then pass "$1"; else fail "$1"; fi; }

pj()   { printf '%s' "$1" | jq -r "$2"; }
api()  { curl -s -X "$1" -H "Authorization: Bearer $WPCC_TOKEN" ${3:+-H "Content-Type: application/json"} ${3:+-d "$3"} "$WPCC_BASE$2"; }
rest() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$2" "$WPCC_BASE/operations/$1/run"; }
wpe()  { wp --path="$WP_ROOT" eval "$1" 2>/dev/null; }

SAVED_TITLE=""
cleanup() { [ -n "$SAVED_TITLE" ] && wpe "update_option('blogname', '$(printf '%s' "$SAVED_TITLE" | sed "s/'/\\\\'/g")');"; }
trap cleanup EXIT
SAVED_TITLE="$(wpe 'echo get_option("blogname");')"

echo "== 1. PHP lint =="
lint "view lints"                  "$VIEW"
lint "AdminRestApi lints"          "$RESTAPI"
lint "ChangeHistoryAdminQuery lints" "$QUERY"
lint "AdminMenu lints"             "$MENU"

echo
echo "== 2. Admin REST routes registered =="
has "route: /admin/history"          "'/admin/history'"          "$RESTAPI"
has "route: /admin/history/timeline" "/admin/history/timeline"   "$RESTAPI"
has "route: /admin/history/sessions" "/admin/history/sessions"   "$RESTAPI"
has "route: /admin/history/{id}"     "admin/history/\(\?P<change_id>" "$RESTAPI"
has "routes are READABLE only"       "WP_REST_Server::READABLE"  "$RESTAPI"
has "routes gated by check_permission" "check_permission"        "$RESTAPI"

echo
echo "== 3. Reads delegate to STEP 104 backend (no new read logic) =="
has "list/timeline/get delegate to runtime manager" "ChangeHistoryRuntimeManager" "$RESTAPI"
has "sessions uses presentation aggregation"        "ChangeHistoryAdminQuery"     "$RESTAPI"
# The view never executes rollback itself — it only POSTs to the admin endpoint,
# which routes through OperationExecutor (asserted in section 12).
lacks "view does not reference the engine action directly" "rollback_target" "$VIEW"

echo
echo "== 4. Aggregation is presentation-layer only =="
has "query is in the Admin namespace"     "namespace WPCommandCenter.Admin" "$QUERY"
has "query scopes out session-less rows"  "session_id IS NOT NULL"          "$QUERY"
has "query is read-only (no INSERT/UPDATE/DELETE)" "GROUP BY session_id"     "$QUERY"
lacks "query performs no writes"          "INSERT INTO|UPDATE .* SET|DELETE FROM" "$QUERY"

echo
echo "== 5. Menu: Change History present; legacy Rollback merged (105.3 swap) =="
has "menu: Change History submenu"  "wpcc-change-history" "$MENU"
has "menu: render_change_history"   "render_change_history" "$MENU"
lacks "menu: Rollback submenu removed" "add_submenu_page.*wpcc-rollback" "$MENU"
lacks "menu: render_rollback removed"  "function render_rollback" "$MENU"
has "menu: legacy rollback redirect present" "redirect_legacy_rollback" "$MENU"
has "menu: redirect targets Change History"  "page=wpcc-change-history" "$MENU"
[ -f "$PLUGIN_DIR/includes/Admin/views/rollback.php" ] && fail "legacy rollback view deleted" || pass "legacy rollback view deleted"

echo
echo "== 6. View structure: tabs, drill, detail, escaping =="
has "view: Timeline tab"            "'timeline'"     "$VIEW"
has "view: Sessions tab"            "'sessions'"     "$VIEW"
has "view: Reversible tab"          "'reversible'"   "$VIEW"
has "view: session drill param"     "session_id"     "$VIEW"
has "view: detail panel"            "wpcc-history-detail" "$VIEW"
has "view: uses nonce"              "wp_create_nonce" "$VIEW"
has "view: sends X-WP-Nonce"        "X-WP-Nonce"     "$VIEW"
has "view: HTML escaper present"    "function escHtml" "$VIEW"
has "view: empty state"             "wpcc-empty"     "$VIEW"
has "view: reversible badge present" "wpcc-badge-rev" "$VIEW"

echo
echo "== 7. Functional: seed a session and aggregate it =="
SEED="wpcc-105-1-$(date +%s)"
for i in 1 2 3; do
	rest option_manage "{\"action\":\"option_update\",\"option_id\":\"site_title\",\"value\":\"WPCC 105.1 seed $i\",\"session_id\":\"$SEED\"}" >/dev/null
done
# One session-less write — must appear in Timeline but NEVER in Sessions.
rest option_manage '{"action":"option_update","option_id":"site_title","value":"WPCC 105.1 orphan"}' >/dev/null
pass "seeded 3 sessioned writes + 1 session-less write"

AGG=$(wpe "
\$q = new \WPCommandCenter\Admin\ChangeHistoryAdminQuery();
\$r = \$q->sessions( [], 100, 0 );
global \$wpdb; \$t = \$wpdb->prefix . 'wpcc_change_log';
\$direct_total = (int) \$wpdb->get_var( \"SELECT COUNT(DISTINCT session_id) FROM {\$t} WHERE session_id IS NOT NULL AND session_id <> ''\" );
\$direct_seed  = (int) \$wpdb->get_var( \$wpdb->prepare( \"SELECT COUNT(*) FROM {\$t} WHERE session_id = %s\", '$SEED' ) );
\$direct_rev   = (int) \$wpdb->get_var( \$wpdb->prepare( \"SELECT COUNT(*) FROM {\$t} WHERE session_id = %s AND reversible = 1\", '$SEED' ) );
\$seed = null; foreach ( \$r['sessions'] as \$s ) { if ( \$s['session_id'] === '$SEED' ) { \$seed = \$s; break; } }
echo wp_json_encode( [
	'total_count'  => \$r['total_count'],
	'direct_total' => \$direct_total,
	'found_seed'   => \$seed ? true : false,
	'seed_count'   => \$seed ? \$seed['change_count'] : 0,
	'direct_seed'  => \$direct_seed,
	'seed_rev'     => \$seed ? \$seed['reversible_count'] : -1,
	'direct_rev'   => \$direct_rev,
	'actor'        => \$seed ? \$seed['actor_summary'] : '',
	'runtimes'     => \$seed ? \$seed['runtimes'] : [],
] );
")

assert_eq   "agg: total_count matches DISTINCT session_id (NULL excluded)" "$(pj "$AGG" '.direct_total')" "$(pj "$AGG" '.total_count')"
assert_true "agg: seeded session present in roll-up"  "$(pj "$AGG" '.found_seed')"
assert_eq   "agg: seeded change_count matches table"  "$(pj "$AGG" '.direct_seed')" "$(pj "$AGG" '.seed_count')"
assert_ge   "agg: seeded change_count >= 3"           "$(pj "$AGG" '.seed_count')" "3"
assert_eq   "agg: reversible_count matches table"     "$(pj "$AGG" '.direct_rev')" "$(pj "$AGG" '.seed_rev')"
assert_true "agg: actor_summary populated"            "$([ -n "$(pj "$AGG" '.actor')" ] && [ "$(pj "$AGG" '.actor')" != 'null' ] && echo true || echo false)"
assert_true "agg: runtimes populated"                 "$([ "$(pj "$AGG" '.runtimes | length')" -ge 1 ] && echo true || echo false)"

echo
echo "== 8. Functional: session-less write is visible in flat history (Timeline) =="
LIST=$(rest change_history '{"action":"history_list","runtime":"option","limit":50}')
assert_ge "history_list returns option changes" "$(pj "$LIST" '.changes | length')" "1"
# The orphan write has no session_id but is a normal change row.
ORPHAN=$(rest change_history '{"action":"history_list","operation_id":"option_manage","limit":50}')
assert_true "timeline: at least one session-less option change present" \
	"$(printf '%s' "$ORPHAN" | jq -r 'any(.changes[]; (.links.session_id == null) or (.links.session_id == ""))')"

echo
echo "== 9. Invariants: no runtime/MCP/capability additions =="
MANIFEST=$(api GET /agent/manifest)
assert_eq "operation_map stays 34" "34" "$(pj "$MANIFEST" '.capability_management.operation_map | keys | length')"
assert_eq "capabilities stay 23"   "23" "$(pj "$MANIFEST" '.capability_management.capabilities | length')"

echo
echo "== 10. STEP 105.2: shared DiffRenderer (one renderer, no fork) =="
lint "DiffRenderer lints"                "$DIFFR"
has  "DiffRenderer in Admin namespace"   "namespace WPCommandCenter.Admin" "$DIFFR"
has  "patches view uses shared renderer" "DiffRenderer::render_accordion"  "$PATCHES"
lacks "patches view has no forked diff closure" 'render_diff = static function' "$PATCHES"
lacks "no inline <pre class=.wpcc-diff. building outside the renderer" 'echo .<pre class="wpcc-diff"' "$PATCHES"

DR=$(wpe "
\$files = [ [ 'path' => 'a.php', 'diff' => \"--- a/a.php\n+++ b/a.php\n@@ -1,2 +1,2 @@\n-old line\n+new line\n+<script>x</script>\" ] ];
\$sum  = \WPCommandCenter\Admin\DiffRenderer::summarize( \$files );
\$html = \WPCommandCenter\Admin\DiffRenderer::render_accordion( \$files, false );
\$big  = implode( \"\n\", array_map( function( \$i ){ return '+line' . \$i; }, range( 1, 700 ) ) );
\$bightml = \WPCommandCenter\Admin\DiffRenderer::render_file_diff( \$big );
echo wp_json_encode( [
	'add'          => \$sum['additions'],
	'del'          => \$sum['deletions'],
	'fc'           => \$sum['files_changed'],
	'has_summary'  => str_contains( \$html, 'wpcc-diff-summary' ),
	'has_details'  => str_contains( \$html, '<details' ),
	'escaped'      => ( ! str_contains( \$html, '<script>x</script>' ) && str_contains( \$html, '&lt;script&gt;' ) ),
	'truncated'    => ( str_contains( \$bightml, 'not shown' ) ),
] );
")
assert_eq   "renderer: additions counted (headers excluded)" "2" "$(pj "$DR" '.add')"
assert_eq   "renderer: deletions counted (headers excluded)" "1" "$(pj "$DR" '.del')"
assert_eq   "renderer: files_changed"                        "1" "$(pj "$DR" '.fc')"
assert_true "renderer: summary header present"               "$(pj "$DR" '.has_summary')"
assert_true "renderer: per-file accordion present"           "$(pj "$DR" '.has_details')"
assert_true "renderer: untrusted diff content escaped"       "$(pj "$DR" '.escaped')"
assert_true "renderer: large diff truncated with notice"     "$(pj "$DR" '.truncated')"

echo
echo "== 11. STEP 105.2: diff endpoint (read-only, server-rendered) =="
has  "route: /admin/history/{id}/diff" "change_id>.*\)/diff'" "$RESTAPI"
has  "diff endpoint delegates to history_get" "history_get" "$RESTAPI"
has  "diff endpoint reuses shared renderer"   "DiffRenderer::render_accordion" "$RESTAPI"
lacks "view does not parse diffs client-side"  "diff_kind ===|parseDiff|splitDiff" "$VIEW"
has  "view injects server html only"           "data.html"  "$VIEW"

# Metadata path: a seeded runtime_option change yields a "what changed" summary
# (no synthesized before/after diff), available=false.
# Resolve the fixture change_id straight from the table (deterministic — avoids
# any intermittent REST/jq response noise in this lookup).
CID=$(wpe "global \$wpdb; echo (string) \$wpdb->get_var( \$wpdb->prepare( \"SELECT change_id FROM {\$wpdb->prefix}wpcc_change_log WHERE session_id = %s ORDER BY id DESC LIMIT 1\", '$SEED' ) );")
assert_true "diff: resolved a seeded change_id" "$([ -n "$CID" ] && [ "$CID" != 'null' ] && echo true || echo false)"

DIFF=$(wpe "
\$req = new WP_REST_Request( 'GET', '/' );
\$req->set_param( 'change_id', '$CID' );
\$r = ( new \WPCommandCenter\Admin\AdminRestApi() )->history_diff( \$req );
\$d = \$r->get_data();
echo wp_json_encode( [ 'kind' => \$d['diff_kind'] ?? '', 'available' => \$d['available'] ?? null, 'html_len' => strlen( \$d['html'] ?? '' ), 'status' => \$r->get_status() ] );
")
assert_eq   "diff: runtime_option change -> metadata kind"   "metadata" "$(pj "$DIFF" '.kind')"
assert_eq   "diff: metadata is not a textual diff (available=false)" "false" "$(pj "$DIFF" '.available')"
assert_ge   "diff: server-rendered html present"            "$(pj "$DIFF" '.html_len')" "1"
assert_eq   "diff: metadata path returns 200"               "200" "$(pj "$DIFF" '.status')"

# Unknown change -> 404, never a diff.
NF=$(wpe "
\$req = new WP_REST_Request( 'GET', '/' );
\$req->set_param( 'change_id', 'nonexistent-change-xyz' );
\$r = ( new \WPCommandCenter\Admin\AdminRestApi() )->history_diff( \$req );
echo wp_json_encode( [ 'status' => \$r->get_status(), 'success' => \$r->get_data()['success'] ?? null ] );
")
assert_eq   "diff: unknown change_id -> 404" "404" "$(pj "$NF" '.status')"
assert_eq   "diff: unknown change_id -> success=false" "false" "$(pj "$NF" '.success')"

echo
echo "== 12. STEP 105.3: rollback endpoint (engine reuse, no bypass) =="
has  "route: POST /admin/history/{id}/rollback" "change_id>.*\)/rollback'" "$RESTAPI"
has  "rollback route is CREATABLE"              "CREATABLE"                  "$RESTAPI"
has  "rollback routes THROUGH OperationExecutor" "OperationExecutor.*->run\( 'change_history'" "$RESTAPI"
has  "rollback builds rollback_target payload"  "'action' => 'rollback_target'" "$RESTAPI"
lacks "rollback does NOT call the manager directly (no bypass)" 'ChangeHistoryRuntimeManager\(\)\s*\)->rollback_target' "$RESTAPI"
lacks "rollback does NOT assign a token_scope in context" "'token_scope' =>" "$RESTAPI"
has  "view: Restore control present"            "wpcc-restore-link"          "$VIEW"
has  "view: confirmation modal present"         "wpcc-restore-modal"         "$VIEW"
has  "view: high-risk phrase flow wired"        "confirmation_required"      "$VIEW"
has  "view: pending_approval handled"           "pending_approval"           "$VIEW"
has  "view: required phrase constant"           "ROLLBACK_CHANGE"            "$VIEW"

echo
echo "== 13. STEP 105.3 functional: developer-mode restore round-trip (via the endpoint) =="
SAVED_BLOG="$(wpe 'echo get_option("blogname");')"
RB_SESSION="wpcc-105-3-$(date +%s)"
rest option_manage "{\"action\":\"option_update\",\"option_id\":\"site_title\",\"value\":\"WPCC-105-3-ROLLBACK\",\"session_id\":\"$RB_SESSION\"}" >/dev/null
assert_eq "seed: blogname changed" "WPCC-105-3-ROLLBACK" "$(wpe 'echo get_option("blogname");')"

RB=$(wpe "
global \$wpdb; \$t = \$wpdb->prefix . 'wpcc_change_log';
\$cid = \$wpdb->get_var( \$wpdb->prepare( \"SELECT change_id FROM {\$t} WHERE session_id = %s ORDER BY id DESC LIMIT 1\", '$RB_SESSION' ) );
\$req = new WP_REST_Request( 'POST', '/' );
\$req->set_param( 'change_id', \$cid );
\$d = ( new \WPCommandCenter\Admin\AdminRestApi() )->history_rollback( \$req )->get_data();
\$rev_rows = (int) \$wpdb->get_var( \$wpdb->prepare( \"SELECT COUNT(*) FROM {\$t} WHERE rolled_back_by_change_id IS NOT NULL AND change_id = %s\", \$cid ) );
\$orig_status = \$wpdb->get_var( \$wpdb->prepare( \"SELECT status FROM {\$t} WHERE change_id = %s\", \$cid ) );
echo wp_json_encode( [
	'success'      => \$d['success'] ?? null,
	'result_ok'    => ( \$d['result']['success'] ?? null ),
	'rolled_back_by' => \$d['result']['rolled_back_by'] ?? '',
	'orig_status'  => \$orig_status,
	'blogname'     => get_option('blogname'),
] );
")
assert_true "restore: endpoint success"               "$(pj "$RB" '.success')"
assert_true "restore: engine reported success"        "$(pj "$RB" '.result_ok')"
assert_nonempty "restore: reversal change_id recorded" "$(pj "$RB" '.rolled_back_by')"
assert_eq   "restore: original row stamped rolled_back" "rolled_back" "$(pj "$RB" '.orig_status')"
assert_eq   "restore: value reverted to pre-change"    "$SAVED_BLOG" "$(pj "$RB" '.blogname')"

# Idempotency guard: rolling back an already-rolled-back change is refused with
# an in-band wpcc_already_rolled_back error (the engine never re-runs it).
DUP=$(wpe "
global \$wpdb; \$t = \$wpdb->prefix . 'wpcc_change_log';
\$cid = \$wpdb->get_var( \$wpdb->prepare( \"SELECT change_id FROM {\$t} WHERE session_id = %s AND status = 'rolled_back' ORDER BY id DESC LIMIT 1\", '$RB_SESSION' ) );
\$req = new WP_REST_Request( 'POST', '/' );
\$req->set_param( 'change_id', \$cid );
\$d = ( new \WPCommandCenter\Admin\AdminRestApi() )->history_rollback( \$req )->get_data();
echo wp_json_encode( [ 'code' => \$d['result']['code'] ?? '', 'is_error' => ( \$d['result']['error'] ?? false ) ] );
")
assert_eq "restore: double-rollback refused (wpcc_already_rolled_back)" "wpcc_already_rolled_back" "$(pj "$DUP" '.code')"

echo
echo "== 14. STEP 105.3 security: non-developer mode routes rollback to approval (no execution) =="
# Seed in DEVELOPER mode (so the seed applies), THEN switch to client and attempt
# the rollback — only the rollback should be gated.
rest option_manage "{\"action\":\"option_update\",\"option_id\":\"site_title\",\"value\":\"WPCC-105-3-CLIENT\",\"session_id\":\"r33c\"}" >/dev/null
APPR=$(wpe "
global \$wpdb; \$t = \$wpdb->prefix . 'wpcc_change_log';
\$cid = \$wpdb->get_var( \"SELECT change_id FROM {\$t} WHERE session_id = 'r33c' ORDER BY id DESC LIMIT 1\" );
\$orig_mode = get_option( 'wpcc_security_mode', '' );
update_option( 'wpcc_security_mode', 'client' );
\$req = new WP_REST_Request( 'POST', '/' );
\$req->set_param( 'change_id', \$cid );
\$d = ( new \WPCommandCenter\Admin\AdminRestApi() )->history_rollback( \$req )->get_data();
\$status = \$d['result']['status'] ?? '(none)';
\$rid = \$d['result']['request_id'] ?? '';
\$value_after_attempt = get_option('blogname');
\$orig_status = \$wpdb->get_var( \$wpdb->prepare( \"SELECT status FROM {\$t} WHERE change_id = %s\", \$cid ) );
// Cleanup: reject the queued request, restore mode + blogname.
if ( \$rid ) { ( new \WPCommandCenter\Operations\OperationManager() )->reject_request( \$rid ); }
update_option( 'wpcc_security_mode', \$orig_mode === '' ? 'developer' : \$orig_mode );
update_option( 'blogname', '$SAVED_BLOG' );
echo wp_json_encode( [
	'status'        => \$status,
	'has_request'   => \$rid !== '',
	'not_executed'  => ( \$value_after_attempt === 'WPCC-105-3-CLIENT' && \$orig_status !== 'rolled_back' ),
	'mode_after'    => get_option('wpcc_security_mode'),
] );
")
assert_eq   "approval: client mode returns pending_approval"        "pending_approval" "$(pj "$APPR" '.status')"
assert_true "approval: an approval request was created"             "$(pj "$APPR" '.has_request')"
assert_true "approval: rollback did NOT execute (value + status unchanged)" "$(pj "$APPR" '.not_executed')"
assert_eq   "approval: security mode restored to developer"         "developer" "$(pj "$APPR" '.mode_after')"

echo
echo "== 15. STEP 105.3 DestructiveGuard: ordinary (non-high-risk) reversal takes the fast path =="
GUARD=$(wpe "
\$d = \WPCommandCenter\Operations\DestructiveGuard::classify( 'change_history', [ 'action' => 'rollback_target', 'change_id' => 'whatever-not-a-patch' ] );
echo wp_json_encode( [ 'is_null' => ( null === \$d ) ] );
")
assert_true "guard: non-high-risk rollback_target needs no ROLLBACK_CHANGE phrase" "$(pj "$GUARD" '.is_null')"

echo
echo "== 16. STEP 105.4: feature-gate seam (ungated; single switch point) =="
GATE="$PLUGIN_DIR/includes/Admin/FeatureGate.php"
lint "FeatureGate lints"                 "$GATE"
has  "FeatureGate in Admin namespace"    "namespace WPCommandCenter.Admin" "$GATE"
has  "FeatureGate exposes wpcc_feature_allowed filter" "wpcc_feature_allowed" "$GATE"
has  "REST history routes use the feature-gated permission" "check_history_permission" "$RESTAPI"
has  "history permission combines manage_options + FeatureGate" "FeatureGate::allows\( 'change_history' \)" "$RESTAPI"
has  "menu gates Change History via FeatureGate" "FeatureGate::allows\( 'change_history' \)" "$MENU"

GATEFN=$(wpe "
\$on  = \WPCommandCenter\Admin\FeatureGate::allows( 'change_history' );
\$cb  = function(){ return false; };
add_filter( 'wpcc_feature_allowed', \$cb );
\$off = \WPCommandCenter\Admin\FeatureGate::allows( 'change_history' );
remove_filter( 'wpcc_feature_allowed', \$cb );
echo wp_json_encode( [ 'ungated' => \$on, 'filterable' => ( \$off === false ) ] );
")
assert_true "gate: ungated today (allows -> true)"       "$(pj "$GATEFN" '.ungated')"
assert_true "gate: future switch works (filter can flip)" "$(pj "$GATEFN" '.filterable')"

echo
echo "== 17. STEP 105.4: accessibility markers =="
has "a11y: modal is role=dialog + aria-modal" "role=\"dialog\" aria-modal=\"true\"" "$VIEW"
has "a11y: modal aria-describedby"            "aria-describedby=\"wpcc-restore-msg\"" "$VIEW"
has "a11y: result is a live region"           "role=\"status\" aria-live=\"polite\"" "$VIEW"
has "a11y: high-risk warning is role=alert"   "role=\"alert\"" "$VIEW"
has "a11y: focus trap implemented"            "trapRestoreFocus" "$VIEW"
has "a11y: focus returns to trigger on close" "restoreTrigger" "$VIEW"
has "a11y: detail header cells use scope=row" 'scope="row"' "$VIEW"
has "a11y: restore controls carry aria-label" "aria-label=" "$VIEW"

echo
echo "== 18. STEP 105.4: i18n coverage (formerly-raw strings now localized) =="
has "i18n: detail labels localized"   "lChangeId:" "$VIEW"
has "i18n: counts format localized"   "countsFmt:" "$VIEW"
has "i18n: session columns localized" "colRuntimes:" "$VIEW"
has "i18n: empty-reversible message"  "emptyRev:" "$VIEW"
# Render sites must use the localized keys, not bare literals (the English source
# text legitimately appears once inside each __() definition).
has   "i18n: detail render uses localized label"   "i18n.lChangeId" "$VIEW"
has   "i18n: sessions header uses localized label" "i18n.colLastAct" "$VIEW"
lacks "i18n: detail label not a raw JS literal"    "'Change ID', change" "$VIEW"
lacks "i18n: session header not a raw JS literal"  "Last activity</th>" "$VIEW"
lacks "i18n: no raw Runtimes <th> literal"         "<th>Runtimes</th>" "$VIEW"

echo
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
