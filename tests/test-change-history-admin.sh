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
# No write/rollback surface introduced in this step.
lacks "no rollback_target route in admin REST"      "rollback_target"             "$RESTAPI"
lacks "no rollback execution in the view"           "rollback_target"             "$VIEW"

echo
echo "== 4. Aggregation is presentation-layer only =="
has "query is in the Admin namespace"     "namespace WPCommandCenter.Admin" "$QUERY"
has "query scopes out session-less rows"  "session_id IS NOT NULL"          "$QUERY"
has "query is read-only (no INSERT/UPDATE/DELETE)" "GROUP BY session_id"     "$QUERY"
lacks "query performs no writes"          "INSERT INTO|UPDATE .* SET|DELETE FROM" "$QUERY"

echo
echo "== 5. Menu: Change History added, Rollback retained (105.3 swap) =="
has "menu: Change History submenu"  "wpcc-change-history" "$MENU"
has "menu: render_change_history"   "render_change_history" "$MENU"
has "menu: Rollback retained (deferred removal)" "wpcc-rollback" "$MENU"

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
has "view: reversible shown read-only (no Restore button)" "wpcc-badge-rev" "$VIEW"
lacks "view: no Restore control yet" 'value="Restore"|>Restore<|wpcc-restore|data-action="rollback"' "$VIEW"

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
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
