#!/usr/bin/env bash
#
# STEP 111 — Governed Action #2 (SEO Meta Generator), Slice 1: read-only SEO audit.
#
# Asserts the read-only audit surface: canonical pagination envelope, plugin
# detection (Rank Math / Yoast / NONE) + empty-state, missing/weak/ok
# classification, NO writes / NO generation / NO proposal / NO apply, the
# build-flag-OFF gated menu, and frozen invariants.
#
# Requires: wp-cli.

set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_ROOT="$(cd "$PLUGIN_DIR/../../.." && pwd)"
cd "$PLUGIN_DIR"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }
has()  { grep -qF -- "$2" "$3" && pass "$1" || fail "$1 (missing '$2')"; }
lacks(){ grep -qF -- "$2" "$3" && fail "$1 (found '$2')" || pass "$1"; }
wpe() { wp --path="$WP_ROOT" eval "$1" 2>/dev/null; }

RESTAPI="$PLUGIN_DIR/includes/Admin/AdminRestApi.php"
QUERY="$PLUGIN_DIR/includes/Seo/SeoAuditQuery.php"
MENU="$PLUGIN_DIR/includes/Admin/AdminMenu.php"
SHELL="$PLUGIN_DIR/includes/Admin/AppShell.php"
VIEW="$PLUGIN_DIR/includes/Admin/views/seo-meta.php"

echo "STEP 111 — GA#2 Slice 1: read-only SEO audit"

echo
echo "== 1. Query is read-only (no writes, no engine, no provider call, no proposal) =="
has  "reuses SeoProvider abstraction"      "SeoProvider"        "$QUERY"
has  "canonical envelope (items)"          "'items'"            "$QUERY"
has  "canonical envelope (total_count)"    "'total_count'"      "$QUERY"
has  "canonical envelope (next_cursor)"    "'next_cursor'"      "$QUERY"
lacks "no option writes"                   "update_option"      "$QUERY"
lacks "no post meta writes"                "update_post_meta"   "$QUERY"
lacks "no post meta deletes"               "delete_post_meta"   "$QUERY"
lacks "no SeoProvider::write (no SEO write)" "SeoProvider::write" "$QUERY"
lacks "no engine dispatch"                 "OperationExecutor"  "$QUERY"
lacks "no seo_update action dispatch"       "'seo_update'"       "$QUERY"
lacks "no proposal creation"               "ProposalStore"      "$QUERY"
lacks "no outbound HTTP"                    "wp_remote_"         "$QUERY"

echo
echo "== 2. Route: READABLE-only, FeatureGate-gated =="
has  "route: /admin/seo/audit"             "'/admin/seo/audit'"          "$RESTAPI"
has  "audit handler present"               "function seo_audit"          "$RESTAPI"
has  "seo permission callback"             "function check_seo_permission" "$RESTAPI"
has  "surface maps to FeatureGate key"     "'seo'            => 'seo_meta_generator'" "$RESTAPI"
SEO_ROUTE="$(awk '/admin\/seo\/audit/{f=1} f{print} f&&/\] \);/{exit}' "$RESTAPI")"
if printf '%s' "$SEO_ROUTE" | grep -qE "CREATABLE|EDITABLE|DELETABLE"; then fail "seo audit route is read-only"; else pass "seo audit route is read-only (READABLE)"; fi
# Handler never dispatches the engine / never writes.
SEO_HANDLER="$(awk '/function seo_audit/{f=1} f{print} f&&/^\t}/{exit}' "$RESTAPI")"
if printf '%s' "$SEO_HANDLER" | grep -qE "OperationExecutor|->run\(|->generate\(|->apply\("; then fail "seo_audit handler never executes"; else pass "seo_audit handler never executes (no engine dispatch)"; fi

echo
echo "== 3. App Shell: SEO Meta is a build-flagged Operate tab + FeatureGate =="
# Experience Layer: the build-flagged SEO surface became the Operate › SEO Meta tab,
# added to the shell only when the build flag is on AND the FeatureGate allows.
has  "build flag honored (constant + filter)"  "WPCC_SEO_META_UI"   "$SHELL"
has  "build flag name referenced"          "wpcc_seo_meta_ui"   "$SHELL"
has  "tab gated by seo_meta_generator FeatureGate" "FeatureGate::allows( 'seo_meta_generator' )" "$SHELL"
has  "SEO tab renders seo-meta view"       "'view' => 'seo-meta'" "$SHELL"
has  "legacy seo slug redirects (map)"     "'wpcc-seo'" "$SHELL"

echo
echo "== 4. View: escaped, paginated; generation = drafts only (Slice 2b) =="
has  "HTML escaper present"                "const esc"           "$VIEW"
has  "uses REST nonce"                     "X-WP-Nonce"          "$VIEW"
has  "fetches /seo/audit"                  "/seo/audit"          "$VIEW"
has  "filter (missing/weak/all)"           "wpcc-seo-filter"     "$VIEW"
has  "Prev/Next pager"                     "wpcc-seo-pager"      "$VIEW"
has  "no-plugin empty-state"               "provider_available"  "$VIEW"
has  "consumes canonical items[]"          "d.items"             "$VIEW"
# Slice 2b: generation control creates DRAFTS via /seo/generate.
has  "generate control (drafts) present"   "wpcc-seo-generate"   "$VIEW"
has  "generation posts to /seo/generate"   "/seo/generate"       "$VIEW"
# (Apply arrives in Slice 4a; per-item Undo in Slice 4b — both covered by
# test-seo-apply.sh / test-seo-undo.sh. The shared view now legitimately contains the
# /history/ rollback route for the Applied-tab Undo, so that absence guard is dropped.)
lacks "no OperationExecutor in view"       "OperationExecutor"  "$VIEW"

echo
echo "== 4b. UX polish (U1.1 intro, U1.3 tab badges, U3 dashboard) =="
# U1.1 — the stale "read-only" intro is gone; intro now states reversibility.
lacks "no stale read-only intro"           "this page does not change anything" "$VIEW"
# Phase 2.5A: the reversibility/approval/audit message moved from the intro sentence into
# the shared Built-in AI trust strip (Reviewed · Requires approval · Audited · Reversible).
has  "surfaces the trust strip"            "builtin-ai-trust"  "$VIEW"
# U1.3 — tab count badges.
has  "Review tab count badge"              "wpcc-seo-tabcount-review"      "$VIEW"
has  "Suggestions tab count badge"         "wpcc-seo-tabcount-suggestions" "$VIEW"
has  "Applied tab count badge"             "wpcc-seo-tabcount-applied"     "$VIEW"
has  "tab counts reuse proposal list"      "status=draft&operation_id=seo_manage&limit=1" "$VIEW"
# U3 — action-first dashboard: progress bar + clickable filters + tab deep-links.
has  "dashboard container"                 "wpcc-seo-dash"          "$VIEW"
has  "progress bar fill"                   "wpcc-seo-dash-fill"     "$VIEW"
has  "clickable filter stats (data-filter)" "data-filter"           "$VIEW"
has  "needs-you label"                     "Needs you"              "$VIEW"
has  "Needs work label"                    "Needs work"             "$VIEW"
has  "dashboard deep-links (data-go)"      "data-go"                "$VIEW"
has  "suggestions-ready metric"            "suggestions ready"      "$VIEW"
has  "applied-reversible metric"           "applied (reversible)"   "$VIEW"

echo
echo "== 5. Functional: audit over the real registry =="
if ! command -v wp >/dev/null 2>&1; then
	echo "  SKIP: wp-cli not available — static checks only."
else
	RES="$(wpe '
		$a=get_users(["role"=>"administrator","number"=>1]); wp_set_current_user($a?$a[0]->ID:1);
		$Q = new \WPCommandCenter\Seo\SeoAuditQuery();
		$provider = \WPCommandCenter\Operations\SeoProvider::detect();
		$out = [ "provider" => $provider ];

		$r = $Q->audit( [ "state" => "all" ], 5, 0 );
		$keys = ["action","provider","provider_available","summary","items","total_count","returned","has_more","next_cursor","limit","offset","filters"];
		$ok = 1; foreach ($keys as $k) { if (!array_key_exists($k,$r)) $ok = 0; }
		$out["env_shape"] = $ok;
		$out["action"]    = (string) $r["action"];
		$out["limit5"]    = ( (int) $r["limit"] === 5 ) ? 1 : 0;

		if ( "none" === $provider ) {
			$out["none_empty"] = ( ! $r["provider_available"] && 0 === count($r["items"]) ) ? 1 : 0;
			echo wp_json_encode($out); return;
		}

		// Provider present: create known-state posts, classify, verify read-only.
		global $wpdb;
		$prop_before   = ( new \WPCommandCenter\Proposals\ProposalStore() )->count([]);
		$rb_before     = get_option( "wpcc_seo_rollbacks", [] );                                         // legacy option store
		$rbmeta_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_wpcc\_seo\_rb\_%'" ); // Slice 4c meta store

		$mk = function( $meta ) {
			$id = wp_insert_post([ "post_title"=>"WPCC SEO selftest", "post_status"=>"publish", "post_type"=>"post", "post_content"=>"x" ]);
			foreach ( $meta as $k=>$v ) { update_post_meta( $id, $k, $v ); }
			return $id;
		};
		$good_desc = str_repeat( "good description sentence. ", 6 ); // ~150 chars (120-160)
		$id_missing = $mk( [] ); // no title/desc -> missing
		$id_weak    = $mk( [ "_yoast_wpseo_title"=>"A fine title", "_yoast_wpseo_metadesc"=>"too short", "_yoast_wpseo_focuskw"=>"kw" ] ); // desc<120 -> weak
		$id_ok      = $mk( [ "_yoast_wpseo_title"=>"A fine SEO title", "_yoast_wpseo_metadesc"=>substr($good_desc,0,150), "_yoast_wpseo_focuskw"=>"keyword" ] ); // -> ok

		// Newest posts (highest IDs) appear first under ORDER BY ID DESC.
		$page = $Q->audit( [ "state" => "all" ], 100, 0 );
		$by = [];
		foreach ( $page["items"] as $it ) { $by[ (int)$it["post_id"] ] = (string)$it["state"]; }
		$out["cls_missing"] = ( ( $by[$id_missing] ?? "" ) === "missing" ) ? 1 : 0;
		$out["cls_weak"]    = ( ( $by[$id_weak] ?? "" ) === "weak" ) ? 1 : 0;
		$out["cls_ok"]      = ( ( $by[$id_ok] ?? "" ) === "ok" ) ? 1 : 0;

		// State filter narrows.
		$miss = $Q->audit( [ "state" => "missing" ], 100, 0 );
		$only_missing = 1; foreach ( $miss["items"] as $it ) { if ( $it["state"] !== "missing" ) $only_missing = 0; }
		$out["filter_missing_pure"] = $only_missing;
		$out["filter_has_ours"]     = isset( array_flip( array_map( fn($i)=>(int)$i["post_id"], $miss["items"]) )[$id_missing] ) ? 1 : 0;

		// Pagination: total stable, limit honored, cursor decodes when more remain.
		$p1 = $Q->audit( [ "state" => "all" ], 2, 0 );
		$out["page_limit"] = ( count($p1["items"]) <= 2 ) ? 1 : 0;
		$out["cursor_ok"]  = ( $p1["has_more"] ) ? ( ( (int) ( json_decode( base64_decode( (string)$p1["next_cursor"] ), true )["offset"] ?? -1 ) === 2 ) ? 1 : 0 ) : 1;

		// Read-only: our ok post meta unchanged; no proposals created; rollbacks
		// unchanged in BOTH stores (legacy option AND Slice 4c per-post meta).
		$rbmeta_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_wpcc\_seo\_rb\_%'" );
		$out["meta_intact"]       = ( get_post_meta( $id_ok, "_yoast_wpseo_title", true ) === "A fine SEO title" ) ? 1 : 0;
		$out["no_proposals"]      = ( ( new \WPCommandCenter\Proposals\ProposalStore() )->count([]) === $prop_before ) ? 1 : 0;
		$out["no_rollbacks"]      = ( get_option( "wpcc_seo_rollbacks", [] ) === $rb_before ) ? 1 : 0;
		$out["no_rollback_meta"]  = ( $rbmeta_after === $rbmeta_before ) ? 1 : 0;

		// cleanup
		foreach ( [ $id_missing, $id_weak, $id_ok ] as $id ) { wp_delete_post( $id, true ); }
		echo wp_json_encode($out);
	')"
	getj() { printf '%s' "$RES" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["'"$1"'"] ?? "";' 2>/dev/null; }

	assert_eq "audit returns canonical envelope keys" "1" "$(getj env_shape)"
	assert_eq "envelope action = seo_audit" "seo_audit" "$(getj action)"
	assert_eq "limit honored (5)" "1" "$(getj limit5)"
	PROV="$(getj provider)"
	if [ "$PROV" = "none" ]; then
		assert_eq "NONE provider -> empty-state" "1" "$(getj none_empty)"
		echo "  NOTE: no SEO plugin active — classification tests skipped."
	else
		echo "  (SEO provider: $PROV)"
		assert_eq "classify missing"            "1" "$(getj cls_missing)"
		assert_eq "classify weak (short desc)"  "1" "$(getj cls_weak)"
		assert_eq "classify ok"                 "1" "$(getj cls_ok)"
		assert_eq "missing filter is pure"      "1" "$(getj filter_missing_pure)"
		assert_eq "missing filter includes ours" "1" "$(getj filter_has_ours)"
		assert_eq "pagination limit honored"    "1" "$(getj page_limit)"
		assert_eq "next_cursor decodes to offset" "1" "$(getj cursor_ok)"
		assert_eq "read-only: post meta intact" "1" "$(getj meta_intact)"
		assert_eq "read-only: no proposals created" "1" "$(getj no_proposals)"
		assert_eq "read-only: rollbacks option unchanged" "1" "$(getj no_rollbacks)"
		assert_eq "read-only: no rollback meta created (Slice 4c store)" "1" "$(getj no_rollback_meta)"
	fi

	echo
	echo "== 5b. Tab gating (functional, via AppShell::sections) =="
	# Experience Layer: SEO Meta is the Operate › SEO Meta tab; it appears in the
	# shell only when the build flag is on AND the FeatureGate allows.
	TAB_OFF="$(wpe 'remove_all_filters("wpcc_seo_meta_ui"); $s=\WPCommandCenter\Admin\AppShell::sections(); echo isset($s["wpcc-built-in-ai"]["tabs"]["seo"])?"shown":"hidden";')"
	assert_eq "tab hidden by default" "hidden" "$TAB_OFF"

	TAB_ON="$(wpe 'add_filter("wpcc_seo_meta_ui","__return_true"); $s=\WPCommandCenter\Admin\AppShell::sections(); remove_all_filters("wpcc_seo_meta_ui"); echo isset($s["wpcc-built-in-ai"]["tabs"]["seo"])?"shown":"hidden";')"
	assert_eq "tab shown when build flag on + FeatureGate allows" "shown" "$TAB_ON"

	TAB_DENY="$(wpe 'add_filter("wpcc_seo_meta_ui","__return_true"); $d=function($allow,$f){ return $f==="seo_meta_generator"?false:$allow; }; add_filter("wpcc_feature_allowed",$d,10,2); $s=\WPCommandCenter\Admin\AppShell::sections(); remove_filter("wpcc_feature_allowed",$d,10); remove_all_filters("wpcc_seo_meta_ui"); echo isset($s["wpcc-built-in-ai"]["tabs"]["seo"])?"shown":"hidden";')"
	assert_eq "tab hidden when FeatureGate denies" "hidden" "$TAB_DENY"
fi

echo
echo "== 6. Invariants unchanged =="
assert_eq "OPERATION_MAP == 34" "34" "$(wpe 'echo count(\WPCommandCenter\Operations\CapabilityRegistry::OPERATION_MAP);')"
assert_eq "capabilities == 23"  "23" "$(wpe 'echo count(\WPCommandCenter\Operations\CapabilityRegistry::ALL_CAPABILITIES);')"
assert_eq "catalogue == 40"     "40" "$(wpe 'echo count((new \WPCommandCenter\Operations\OperationRegistry())->get_operations());')"
assert_eq "DB_VERSION 2.5.0"    "2.5.0" "$(wpe 'echo \WPCommandCenter\Core\Schema::DB_VERSION;')"

echo ""
echo "RESULT: ${PASS} passed, ${FAIL} failed"
[ "$FAIL" -eq 0 ]
