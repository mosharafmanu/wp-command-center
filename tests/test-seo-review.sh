#!/usr/bin/env bash
#
# STEP 111 — GA#2 Slice 3: SEO Suggestions tab (review / edit / dismiss).
#
# Asserts the Suggestions tab reuses ONLY the existing proposal routes
# (list/PATCH/dismiss) + WP core REST post enrichment, edits final_payload (draft
# stays draft), dismisses drafts, shows current-vs-suggested + char counts +
# provider attribution + edited indicator, and contains NO apply/approval/undo/
# rollback/bulk/selection controls and NO SEO meta write. Invariants frozen.
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

VIEW="$PLUGIN_DIR/includes/Admin/views/seo-meta.php"
RESTAPI="$PLUGIN_DIR/includes/Admin/AdminRestApi.php"

echo "STEP 111 — GA#2 Slice 3: SEO Suggestions (review/edit/dismiss)"

echo
echo "== 1. View: Suggestions tab reuses existing proposal routes + core REST =="
has  "Suggestions tab present"             "wpcc-seo-tab-suggestions" "$VIEW"
has  "Suggestions panel present"           "wpcc-seo-panel-suggestions" "$VIEW"
has  "loads seo_manage drafts"             "status=draft&operation_id=seo_manage" "$VIEW"
has  "edit via PATCH final_payload"        "final_payload"        "$VIEW"
has  "edit targets /proposals/{id} (PUT)"  "method: 'PUT'"        "$VIEW"
has  "dismiss via /dismiss"                "/dismiss"             "$VIEW"
has  "core REST posts enrichment"          "/posts?include="      "$VIEW"
has  "core REST pages enrichment"          "/pages?include="      "$VIEW"

echo
echo "== 2. View: editable fields + char counts + attribution + edited indicator =="
has  "editable title input"                "wpcc-seo-et"          "$VIEW"
has  "editable description textarea"        "wpcc-seo-ed"          "$VIEW"
has  "char count elements"                 "wpcc-seo-cc"          "$VIEW"
has  "title target 60"                      "TITLE_MAX = 60"      "$VIEW"
has  "description target 120-160"           "DESC_MIN = 120, DESC_MAX = 160" "$VIEW"
has  "provider attribution"                 "Suggested by AI"     "$VIEW"
has  "edited indicator"                     "wpcc-seo-edited"     "$VIEW"
has  "current vs suggested (prior)"         "p.prior"             "$VIEW"
has  "save control"                         "wpcc-seo-save"       "$VIEW"
has  "dismiss control"                      "wpcc-seo-dismiss"    "$VIEW"
has  "empty state"                          "No suggestions yet"  "$VIEW"

echo
echo "== 3. View: NO apply/approval/undo/rollback/bulk/selection controls =="
# (Apply control + Applied tab arrive in Slice 4a — covered by test-seo-apply.sh.)
lacks "no Approval Center link"            "wpcc-approval-center" "$VIEW"
lacks "no Change History link"            "wpcc-change-history"  "$VIEW"
lacks "no history rollback route"         "/history/"           "$VIEW"
lacks "no SelectionResolver"              "SelectionResolver"    "$VIEW"
lacks "no select-all-matching"           "matchall"             "$VIEW"
lacks "no OperationExecutor"             "OperationExecutor"     "$VIEW"
lacks "no SEO meta write"               "SeoProvider::write"     "$VIEW"
# No NEW proposal routes were added for Slice 3 (the 4 proposal routes pre-exist).
PROP_ROUTES="$(grep -c "register_rest_route( self::NS, '/admin/proposals" "$RESTAPI")"
assert_eq "proposal routes unchanged (4)" "4" "$PROP_ROUTES"

echo
echo "== 4. Functional: existing proposal routes support seo_manage drafts =="
if ! command -v wp >/dev/null 2>&1; then
	echo "  SKIP: wp-cli not available — static checks only."
else
	RES="$(wpe '
		$a=get_users(["role"=>"administrator","number"=>1]); wp_set_current_user($a?$a[0]->ID:1);
		$store = new WPCommandCenter\Proposals\ProposalStore();
		$pid = wp_insert_post(["post_title"=>"WPCC SEO review test","post_status"=>"publish","post_type"=>"post","post_content"=>"x"]);
		$meta_before = get_post_meta($pid, "_yoast_wpseo_title", true) . "|" . get_post_meta($pid, "rank_math_title", true);

		// Seed a seo_manage draft (as Slice 2b would).
		$p = $store->create([
			"operation_id"=>"seo_manage","action"=>"seo_update","target_type"=>"post","target_id"=>(string)$pid,
			"payload"=>["action"=>"seo_update","content_id"=>$pid,"seo"=>["title"=>"Suggested T","description"=>"Suggested D long enough for a meta description test."]],
			"prior"=>["title"=>"","description"=>""],
			"provider"=>"anthropic","model"=>"claude-sonnet-4-6","batch_id"=>wp_generate_uuid4(),
		]);
		$pidp = $p["proposal_id"];
		$out = [];

		// LIST: GET /admin/proposals?operation_id=seo_manage&status=draft includes it + shape.
		$lreq = new WP_REST_Request("GET","/wp-command-center/v1/admin/proposals");
		$lreq->set_param("operation_id","seo_manage"); $lreq->set_param("status","draft"); $lreq->set_param("limit",100);
		$ld = rest_do_request($lreq)->get_data();
		$found = null; foreach(($ld["proposals"]??[]) as $row){ if(($row["proposal_id"]??"")===$pidp){ $found=$row; } }
		$out["in_list"] = $found ? 1 : 0;
		$out["shape"] = ($found && array_key_exists("payload",$found) && array_key_exists("prior",$found) && array_key_exists("final_payload",$found) && array_key_exists("provider",$found) && array_key_exists("model",$found) && array_key_exists("target_id",$found) && array_key_exists("status",$found)) ? 1 : 0;

		// PATCH: edit final_payload (draft stays draft).
		$ureq = new WP_REST_Request("PUT","/wp-command-center/v1/admin/proposals/".$pidp);
		$ureq->set_param("final_payload",["action"=>"seo_update","content_id"=>$pid,"seo"=>["title"=>"Edited T","description"=>"Edited D long enough for a valid meta description here."]]);
		$ud = rest_do_request($ureq)->get_data();
		$after = $store->get($pidp); $fp = json_decode($after["final_payload_json"]??"null", true);
		$out["patched"] = ( ($fp["seo"]["title"]??"") === "Edited T" ) ? 1 : 0;
		$out["still_draft"] = ( ($after["status"]??"") === "draft" ) ? 1 : 0;

		// DISMISS: terminal.
		$dreq = new WP_REST_Request("POST","/wp-command-center/v1/admin/proposals/".$pidp."/dismiss");
		$dd = rest_do_request($dreq)->get_data();
		$after2 = $store->get($pidp);
		$out["dismissed"] = ( ($after2["status"]??"") === "dismissed" ) ? 1 : 0;

		// READ-ONLY: site SEO meta unchanged across list/patch/dismiss.
		$meta_after = get_post_meta($pid, "_yoast_wpseo_title", true) . "|" . get_post_meta($pid, "rank_math_title", true);
		$out["no_site_write"] = ( $meta_before === $meta_after ) ? 1 : 0;

		wp_delete_post($pid, true);
		echo wp_json_encode($out);
	')"
	getj() { printf '%s' "$RES" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["'"$1"'"] ?? "";' 2>/dev/null; }

	assert_eq "draft appears in seo_manage draft list" "1" "$(getj in_list)"
	assert_eq "list row carries payload/prior/final_payload/provider/model/target/status" "1" "$(getj shape)"
	assert_eq "PATCH updates final_payload"            "1" "$(getj patched)"
	assert_eq "proposal stays draft after edit"        "1" "$(getj still_draft)"
	assert_eq "dismiss makes it terminal (dismissed)"  "1" "$(getj dismissed)"
	assert_eq "READ-ONLY: site SEO meta unchanged"     "1" "$(getj no_site_write)"
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
