#!/usr/bin/env bash
#
# GA#2 — Slice 5a: page-scoped Bulk Apply + Bulk Dismiss (SEO Suggestions tab).
#
# Asserts the Suggestions tab gains a page-scoped bulk action bar (row checkboxes,
# select-all-on-page, Apply selected, Dismiss selected, progress region) that runs
# SEQUENTIAL loops over the EXISTING per-proposal routes (/proposals/{id}/apply,
# /proposals/{id}/dismiss). Each item is governed individually (own change_id +
# Slice-4c per-post rollback snapshot); per-item failure never aborts the run.
# NO cross-page selection, NO SelectionResolver, NO /admin/seo/selection, NO bulk
# Undo, NO batch approval/rollback, NO new route/op/cap/MCP/schema. Invariants frozen.
#
# Requires: wp-cli (functional section); static checks run regardless.

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

VIEW="$PLUGIN_DIR/includes/Admin/views/seo-meta.php"
RESTAPI="$PLUGIN_DIR/includes/Admin/AdminRestApi.php"

echo "GA#2 — Slice 5a: SEO Suggestions bulk Apply + Dismiss"

echo
echo "== 1. View: page-scoped bulk controls present =="
has  "per-row checkbox class"               "wpcc-seo-sg-cb"       "$VIEW"
has  "select-all-on-page checkbox"          "wpcc-seo-sg-selectall" "$VIEW"
has  "select-all-on-page label"             "Select all on this page" "$VIEW"
has  "bulk Apply button"                     "wpcc-seo-sg-apply"    "$VIEW"
has  "bulk Dismiss button"                   "wpcc-seo-sg-dismiss"  "$VIEW"
has  "progress region id"                    "wpcc-seo-sg-progress" "$VIEW"
has  "progress region is a live status"      'id="wpcc-seo-sg-progress" role="status" aria-live="polite"' "$VIEW"
has  "sequential per-item loop helper"       "sgRunSeq"             "$VIEW"
has  "selection reads only checked page rows" "#wpcc-seo-sg-rows .wpcc-seo-sg-cb:checked" "$VIEW"

echo
echo "== 2. View: bulk loops reuse EXISTING per-proposal routes only =="
has  "bulk apply uses /proposals/{id}/apply"   "/proposals/' + encodeURIComponent( id ) + '/apply'"   "$VIEW"
has  "bulk dismiss uses /proposals/{id}/dismiss" "/proposals/' + encodeURIComponent( id ) + '/dismiss'" "$VIEW"
has  "mode-aware (MODE const)"               "const MODE"           "$VIEW"
has  "gated confirm = own approval request"  "its own approval request" "$VIEW"
has  "outcome read from response status"      "res.data && res.data.status" "$VIEW"
# Per-item failure isolation: success removes row, else keep + message; never abort.
has  "applied status branch"                 "outcome.st === 'applied'"        "$VIEW"
has  "pending_approval status branch"        "outcome.st === 'pending_approval'" "$VIEW"

echo
echo "== 3. View: boundaries (absent) =="
lacks "no SelectionResolver"                 "SelectionResolver"    "$VIEW"
lacks "no select-all-matching (matchall)"    "matchall"             "$VIEW"
lacks "no /admin/seo/selection route use"    "/admin/seo/selection" "$VIEW"
lacks "no bulk Undo"                         "bulkUndo"             "$VIEW"
lacks "no direct OperationExecutor"          "OperationExecutor"    "$VIEW"
lacks "no direct SeoProvider write"          "SeoProvider::write"   "$VIEW"
lacks "no direct seo_manage call (quoted)"   "seo_manage'"          "$VIEW"
lacks "no REST route registration in view"   "register_rest_route"  "$VIEW"
# No NEW proposal routes were added for Slice 5a (the 4 proposal routes pre-exist).
PROP_ROUTES="$(grep -c "register_rest_route( self::NS, '/admin/proposals" "$RESTAPI")"
assert_eq "proposal routes unchanged (4)" "4" "$PROP_ROUTES"
# No new SEO selection route exists.
SEL="$(grep -c "register_rest_route( self::NS, '/admin/seo/selection'" "$RESTAPI")"
assert_eq "no /admin/seo/selection route" "0" "$SEL"

echo
echo "== 4. Functional: sequential bulk apply/dismiss over existing routes =="
if ! command -v wp >/dev/null 2>&1; then
	echo "  SKIP: wp-cli not available — static checks only."
else
	RES="$(wpe '
		global $wpdb;
		$a=get_users(["role"=>"administrator","number"=>1]); wp_set_current_user($a?$a[0]->ID:1);
		$prev = get_option("wpcc_security_mode","developer"); update_option("wpcc_security_mode","developer");
		$store = new WPCommandCenter\Proposals\ProposalStore();
		$out = [];

		if ( \WPCommandCenter\Operations\SeoProvider::NONE === \WPCommandCenter\Operations\SeoProvider::detect() ) { $out["skip"]="no_seo_plugin"; echo wp_json_encode($out); return; }

		$mkdraft = function() use ($store) {
			$pid = wp_insert_post(["post_title"=>"WPCC 5a bulk","post_status"=>"publish","post_type"=>"post","post_content"=>"x"]);
			$p = $store->create([
				"operation_id"=>"seo_manage","action"=>"seo_update","target_type"=>"post","target_id"=>(string)$pid,
				"payload"=>["action"=>"seo_update","content_id"=>$pid,"seo"=>["title"=>"Bulk Title","description"=>str_repeat("desc sentence. ",10)]],
				"prior"=>["title"=>"","description"=>""],"provider"=>"anthropic","model"=>"m","batch_id"=>wp_generate_uuid4(),
			]);
			return [$pid, $p["proposal_id"]];
		};
		$apply = function($pidp){ $r=new WP_REST_Request("POST","/wp-command-center/v1/admin/proposals/".$pidp."/apply"); return rest_do_request($r)->get_data(); };
		$dismiss = function($pidp){ $r=new WP_REST_Request("POST","/wp-command-center/v1/admin/proposals/".$pidp."/dismiss"); return rest_do_request($r)->get_data(); };

		// 4a. Bulk apply N=3 sequentially (what the UI loop does, one /apply per item).
		$posts=[]; $props=[];
		for ($i=0;$i<3;$i++){ [$pid,$pp]=$mkdraft(); $posts[]=$pid; $props[]=$pp; }
		$applied=0; $cids=[];
		foreach ($props as $pp){ $d=$apply($pp); if (($d["status"]??"")==="applied"){ $applied++; } $row=$store->get($pp); if (!empty($row["change_id"])){ $cids[]=$row["change_id"]; } }
		$out["all_applied"]      = ($applied===3) ? 1 : 0;
		$out["distinct_cids"]    = (count(array_unique($cids))===3) ? 1 : 0;
		// Independent Slice-4c rollback snapshots — one _wpcc_seo_rb_ row per applied post.
		$snap=0; foreach ($posts as $pid){ $n=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key LIKE %s",$pid,$wpdb->esc_like("_wpcc_seo_rb_")."%")); if ($n===1){$snap++;} }
		$out["independent_snapshots"] = ($snap===3) ? 1 : 0;

		// 4b. Bulk dismiss M=2 sequentially.
		$dposts=[]; $dprops=[];
		for ($i=0;$i<2;$i++){ [$pid,$pp]=$mkdraft(); $dposts[]=$pid; $dprops[]=$pp; }
		$dismissed=0; foreach ($dprops as $pp){ $dismiss($pp); if (($store->get($pp)["status"]??"")==="dismissed"){ $dismissed++; } }
		$out["all_dismissed"] = ($dismissed===2) ? 1 : 0;

		// 4c. Partial failure isolation: a bad id in the set must not block valid ones.
		[$gpid,$gpp]=$mkdraft();
		$bad = $apply("does-not-exist-proposal-id");           // expected: error, NOT applied
		$good = $apply($gpp);                                  // expected: applied
		$out["bad_id_not_applied"] = ( ($bad["status"]??"") !== "applied" ) ? 1 : 0;
		$out["good_after_bad_applied"] = ( ($good["status"]??"")==="applied" && ($store->get($gpp)["status"]??"")==="applied" ) ? 1 : 0;

		update_option("wpcc_security_mode",$prev);
		foreach (array_merge($posts,$dposts,[$gpid]) as $pid){ wp_delete_post($pid,true); }
		echo wp_json_encode($out);
	')"
	gj() { printf '%s' "$RES" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["'"$1"'"] ?? "";' 2>/dev/null; }
	if [ "$(gj skip)" = "no_seo_plugin" ]; then
		echo "  NOTE: no SEO plugin active on this env — bulk functional path skipped."
	else
		assert_eq "bulk apply: all 3 applied"                    "1" "$(gj all_applied)"
		assert_eq "bulk apply: 3 distinct change_ids"            "1" "$(gj distinct_cids)"
		assert_eq "bulk apply: 3 independent rollback snapshots" "1" "$(gj independent_snapshots)"
		assert_eq "bulk dismiss: all 2 dismissed"                "1" "$(gj all_dismissed)"
		assert_eq "partial failure: bad id not applied"          "1" "$(gj bad_id_not_applied)"
		assert_eq "partial failure: valid item still applied"    "1" "$(gj good_after_bad_applied)"
	fi
fi

echo
echo "== 5. Invariants unchanged =="
assert_eq "OPERATION_MAP == 34" "34" "$(wpe 'echo count(\WPCommandCenter\Operations\CapabilityRegistry::OPERATION_MAP);')"
assert_eq "capabilities == 23"  "23" "$(wpe 'echo count(\WPCommandCenter\Operations\CapabilityRegistry::ALL_CAPABILITIES);')"
assert_eq "catalogue == 40"     "40" "$(wpe 'echo count((new \WPCommandCenter\Operations\OperationRegistry())->get_operations());')"
assert_eq "DB_VERSION 2.5.0"    "2.5.0" "$(wpe 'echo \WPCommandCenter\Core\Schema::DB_VERSION;')"

echo ""
echo "RESULT: ${PASS} passed, ${FAIL} failed"
[ "$FAIL" -eq 0 ]
