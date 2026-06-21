#!/usr/bin/env bash
#
# STEP 111 — GA#2 Slice 4a: SEO Apply + Applied tab.
#
# Asserts the Suggestions Apply action + the read-only Applied tab reuse the
# EXISTING proposal apply route and proposal list query (no SEO-specific apply, no
# new route/executor/approval path). Mode-aware (developer applies; client/
# enterprise pend). Applied tab shows applied / pending_approval / failed. NO Undo /
# rollback / Change-History / Approval-Center controls. Invariants frozen.
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

echo "STEP 111 — GA#2 Slice 4a: SEO Apply + Applied tab"

echo
echo "== 1. View: Apply action (mode-aware) reuses proposal apply route =="
has  "apply control present"               "wpcc-seo-apply"      "$VIEW"
has  "apply posts to existing /apply route" "/apply"             "$VIEW"
has  "developer label"                      "Approve & Apply"    "$VIEW"
has  "gated label"                          "Submit for approval" "$VIEW"
has  "mode-aware (MODE const)"              "const MODE"          "$VIEW"
has  "outcome driven by response status"   "pending_approval"    "$VIEW"
# Apply uses the proposal route only — never seo_manage / OperationExecutor directly.
lacks "no direct seo_manage call in view"  "seo_manage'"         "$VIEW"
lacks "no OperationExecutor in view"       "OperationExecutor"   "$VIEW"

echo
echo "== 2. View: Applied tab = segmented single-status paginated list =="
has  "Applied tab present"                  "wpcc-seo-tab-applied" "$VIEW"
has  "Applied panel present"                "wpcc-seo-panel-applied" "$VIEW"
# Segmented control (Applied default / Awaiting approval / Failed).
has  "segment control present"              "wpcc-seo-ap-segbar"   "$VIEW"
has  "Applied segment"                      'data-seg="applied"'   "$VIEW"
has  "Awaiting approval segment"            'data-seg="pending_approval"' "$VIEW"
has  "Failed segment"                       'data-seg="failed"'    "$VIEW"
has  "default segment = applied"            "apSeg = 'applied'"    "$VIEW"
has  "tab entry resets to Applied segment"  "switchApSeg( 'applied' )" "$VIEW"
has  "segment switch resets offset"         "apSeg = seg; apOffset = 0" "$VIEW"
# Single-status paginated read over the existing route (no 3-read merge, no limit=50).
has  "single-status paginated read"         "status=' + encodeURIComponent( apSeg ) + '&operation_id=seo_manage&limit='" "$VIEW"
has  "page size 20 (AP_LIMIT=LIMIT)"        "AP_LIMIT = LIMIT"     "$VIEW"
has  "consumes canonical total_count"       "d.total_count"        "$VIEW"
has  "consumes has_more"                    "d.has_more"           "$VIEW"
has  "Showing X-Y of N status"              "STR.pageInfo.replace" "$VIEW"
has  "Prev/Next pager"                      "wpcc-seo-ap-pager"    "$VIEW"
has  "Next advances offset by AP_LIMIT"     "apOffset += AP_LIMIT" "$VIEW"
has  "Prev decrements offset"               "apOffset - AP_LIMIT"  "$VIEW"
lacks "no old 3-read merge (limit=50)"      "&operation_id=seo_manage&limit=50" "$VIEW"
lacks "no merged concat"                    "pending.concat( applied, failed )" "$VIEW"
has  "Applied state badge"                  "stApplied"           "$VIEW"
has  "Awaiting approval state"              "Awaiting approval"   "$VIEW"
has  "Failed state"                         "stFailed"            "$VIEW"
has  "rollback-aware Reverted state"        "change_status"       "$VIEW"

echo
echo "== 3. View: per-item Undo (Slice 4b) reuses governed rollback; NO bulk/links =="
# Slice 4b legitimately introduces per-item Undo into the shared view: an Undo
# control on Applied-tab reversible rows that reuses POST /admin/history/{cid}/rollback.
has  "Undo control present (Slice 4b)"     "wpcc-seo-undo"        "$VIEW"
has  "Undo reuses history rollback route"  "/history/"           "$VIEW"
# Page-scoped bulk Apply (Slice 5a) now lives on the Suggestions tab — covered by
# test-seo-bulk.sh. Still NO bulk UNDO, NO cross-page selection, NO nav links, NO write.
lacks "no Approval Center link"            "wpcc-approval-center" "$VIEW"
lacks "no Change History link"            "wpcc-change-history"  "$VIEW"
lacks "no bulk undo"                      "bulkUndo"             "$VIEW"
lacks "no cross-page selection resolver" "SelectionResolver"    "$VIEW"
lacks "no SEO meta write in view"        "SeoProvider::write"    "$VIEW"

echo
echo "== 4. Functional: apply reuse + Applied data layer =="
if ! command -v wp >/dev/null 2>&1; then
	echo "  SKIP: wp-cli not available — static checks only."
else
	# 4a. Mode gating sanity (no special-case SEO logic).
	GATE="$(wpe '
		$prev = get_option("wpcc_security_mode","developer");
		$out = [];
		update_option("wpcc_security_mode","developer"); $out["dev"] = \WPCommandCenter\Operations\SecurityModeManager::requires_approval("medium") ? 1 : 0;
		update_option("wpcc_security_mode","client");    $out["cli"] = \WPCommandCenter\Operations\SecurityModeManager::requires_approval("medium") ? 1 : 0;
		update_option("wpcc_security_mode","enterprise"); $out["ent"] = \WPCommandCenter\Operations\SecurityModeManager::requires_approval("medium") ? 1 : 0;
		update_option("wpcc_security_mode",$prev);
		echo wp_json_encode($out);
	')"
	gg() { printf '%s' "$GATE" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["'"$1"'"] ?? "";' 2>/dev/null; }
	assert_eq "developer does NOT gate seo_update (medium)" "0" "$(gg dev)"
	assert_eq "client gates seo_update (pending_approval)"  "1" "$(gg cli)"
	assert_eq "enterprise gates seo_update (pending_approval)" "1" "$(gg ent)"

	# 4b. Real developer apply via the EXISTING route writes SEO meta + records change_id.
	RES="$(wpe '
		$a=get_users(["role"=>"administrator","number"=>1]); wp_set_current_user($a?$a[0]->ID:1);
		$prev = get_option("wpcc_security_mode","developer"); update_option("wpcc_security_mode","developer");
		$store = new WPCommandCenter\Proposals\ProposalStore();
		$out = [];

		if ( \WPCommandCenter\Operations\SeoProvider::NONE === \WPCommandCenter\Operations\SeoProvider::detect() ) { $out["skip"]="no_seo_plugin"; echo wp_json_encode($out); return; }

		$pid = wp_insert_post(["post_title"=>"WPCC SEO apply test","post_status"=>"publish","post_type"=>"post","post_content"=>"x"]);
		$p = $store->create([
			"operation_id"=>"seo_manage","action"=>"seo_update","target_type"=>"post","target_id"=>(string)$pid,
			"payload"=>["action"=>"seo_update","content_id"=>$pid,"seo"=>["title"=>"Applied SEO Title","description"=>"Applied meta description long enough for a realistic test."]],
			"prior"=>["title"=>"","description"=>""],"provider"=>"anthropic","model"=>"m","batch_id"=>wp_generate_uuid4(),
		]);
		$pidp = $p["proposal_id"];

		// Apply via the EXISTING proposal route (rest_do_request as admin).
		$req = new WP_REST_Request("POST","/wp-command-center/v1/admin/proposals/".$pidp."/apply");
		$resp = rest_do_request($req); $d = $resp->get_data();
		$out["resp_status"] = (string)($d["status"] ?? "");
		$row = $store->get($pidp);
		$out["proposal_applied"] = ( ($row["status"]??"") === "applied" ) ? 1 : 0;
		$out["has_change_id"] = ( !empty($row["change_id"]) ) ? 1 : 0;
		// seo_update actually ran (active provider meta written) — proves reuse end-to-end.
		$prov = \WPCommandCenter\Operations\SeoProvider::detect();
		$seo = \WPCommandCenter\Operations\SeoProvider::read($pid, $prov);
		$out["meta_written"] = ( ($seo["title"]??"") === "Applied SEO Title" ) ? 1 : 0;

		// Applied-tab data layer: list filter returns the applied proposal.
		$lreq = new WP_REST_Request("GET","/wp-command-center/v1/admin/proposals");
		$lreq->set_param("operation_id","seo_manage"); $lreq->set_param("status","applied"); $lreq->set_param("limit",50);
		$ld = rest_do_request($lreq)->get_data();
		$out["in_applied"] = count(array_filter(($ld["proposals"]??[]), fn($r)=>($r["proposal_id"]??"")===$pidp)) > 0 ? 1 : 0;

		// Applied-tab data layer: seed pending_approval + failed and confirm filters return them.
		$pp = $store->create(["operation_id"=>"seo_manage","action"=>"seo_update","target_type"=>"post","target_id"=>(string)$pid,"payload"=>["action"=>"seo_update","content_id"=>$pid,"seo"=>["title"=>"P","description"=>"d"]],"prior"=>["title"=>"","description"=>""]]);
		$store->mark_pending_approval($pp["proposal_id"], wp_generate_uuid4());
		$pf = $store->create(["operation_id"=>"seo_manage","action"=>"seo_update","target_type"=>"post","target_id"=>(string)$pid,"payload"=>["action"=>"seo_update","content_id"=>$pid,"seo"=>["title"=>"F","description"=>"d"]],"prior"=>["title"=>"","description"=>""]]);
		$store->mark_failed($pf["proposal_id"], ["code"=>"x","message"=>"y"]);
		$out["pending_listable"] = ( $store->count(["operation_id"=>"seo_manage","status"=>"pending_approval","target_id"=>(string)$pid]) > 0 ) ? 1 : 0;
		$out["failed_listable"]  = ( $store->count(["operation_id"=>"seo_manage","status"=>"failed","target_id"=>(string)$pid]) > 0 ) ? 1 : 0;

		update_option("wpcc_security_mode",$prev);
		wp_delete_post($pid, true);
		echo wp_json_encode($out);
	')"
	gj() { printf '%s' "$RES" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["'"$1"'"] ?? "";' 2>/dev/null; }
	if [ "$(gj skip)" = "no_seo_plugin" ]; then
		echo "  NOTE: no SEO plugin active on this env — apply functional path skipped."
	else
		assert_eq "developer apply -> response status applied" "applied" "$(gj resp_status)"
		assert_eq "proposal marked applied"                   "1" "$(gj proposal_applied)"
		assert_eq "change_id recorded on apply"               "1" "$(gj has_change_id)"
		assert_eq "seo_update actually wrote meta (reuse e2e)" "1" "$(gj meta_written)"
		assert_eq "applied proposal appears in Applied list"  "1" "$(gj in_applied)"
		assert_eq "pending_approval listable for Applied tab" "1" "$(gj pending_listable)"
		assert_eq "failed listable for Applied tab"           "1" "$(gj failed_listable)"
	fi

	# 4c. Pagination: >20 applied records page correctly (no silent truncation).
	PG="$(wpe '
		$a=get_users(["role"=>"administrator","number"=>1]); wp_set_current_user($a?$a[0]->ID:1);
		$store = new WPCommandCenter\Proposals\ProposalStore();
		$out = [];
		// Seed 22 applied proposals (status set directly via the store transitions).
		$ids = [];
		for ($i=0;$i<22;$i++){
			$p = $store->create(["operation_id"=>"seo_manage","action"=>"seo_update","target_type"=>"post","target_id"=>"99000".$i,
				"payload"=>["action"=>"seo_update","content_id"=>0,"seo"=>["title"=>"PgT".$i,"description"=>"d"]],"prior"=>["title"=>"","description"=>""]]);
			$store->mark_pending_approval($p["proposal_id"], wp_generate_uuid4());
			$store->mark_applied($p["proposal_id"], wp_generate_uuid4());
			$ids[] = $p["proposal_id"];
		}
		// Page 1: limit=20, offset=0 → 20 rows + has_more + total>=22.
		$r1 = new WP_REST_Request("GET","/wp-command-center/v1/admin/proposals");
		$r1->set_param("operation_id","seo_manage"); $r1->set_param("status","applied"); $r1->set_param("limit",20); $r1->set_param("offset",0);
		$d1 = rest_do_request($r1)->get_data();
		$out["p1_returned"] = (int)($d1["returned"] ?? -1);
		$out["p1_has_more"] = !empty($d1["has_more"]) ? 1 : 0;
		$out["total_ge_22"] = ((int)($d1["total_count"] ?? 0) >= 22) ? 1 : 0;
		// Page 2: offset=20 → at least 2 more rows reachable (NOT truncated).
		$r2 = new WP_REST_Request("GET","/wp-command-center/v1/admin/proposals");
		$r2->set_param("operation_id","seo_manage"); $r2->set_param("status","applied"); $r2->set_param("limit",20); $r2->set_param("offset",20);
		$d2 = rest_do_request($r2)->get_data();
		$out["p2_reachable"] = ((int)($d2["returned"] ?? 0) >= 2) ? 1 : 0;
		// cleanup our seeded rows
		global $wpdb; $t=$wpdb->prefix."wpcc_proposals";
		$in = implode(",", array_map(fn($p)=>"\"".esc_sql($p)."\"", $ids));
		$wpdb->query("DELETE FROM $t WHERE proposal_id IN ($in)");
		echo wp_json_encode($out);
	')"
	pj() { printf '%s' "$PG" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["'"$1"'"] ?? "";' 2>/dev/null; }
	assert_eq "page 1 returns 20 (limit honored)"        "20" "$(pj p1_returned)"
	assert_eq "page 1 has_more (not truncated at 20)"    "1"  "$(pj p1_has_more)"
	assert_eq "total_count >= 22 (full count)"           "1"  "$(pj total_ge_22)"
	assert_eq "page 2 reachable via offset (no truncation)" "1" "$(pj p2_reachable)"
fi

echo
echo "== 4d. Trust fix A — Apply persists VISIBLE field values before applying =="
# A freshly created draft is applied AFTER its final_payload is overwritten via the
# EXISTING PUT route with NEW values — proving Apply uses the persisted current values,
# not the stale original AI payload. This mirrors the UI flow: edit field → Apply (no
# manual Save) → persistRow() PUT → /apply.
if command -v wp >/dev/null 2>&1; then
	UE="$(wpe '
		$a=get_users(["role"=>"administrator","number"=>1]); wp_set_current_user($a?$a[0]->ID:1);
		$prev = get_option("wpcc_security_mode","developer"); update_option("wpcc_security_mode","developer");
		$store = new WPCommandCenter\Proposals\ProposalStore();
		$out = [];
		if ( \WPCommandCenter\Operations\SeoProvider::NONE === \WPCommandCenter\Operations\SeoProvider::detect() ) { $out["skip"]="no_seo_plugin"; echo wp_json_encode($out); return; }
		$pid = wp_insert_post(["post_title"=>"WPCC unsaved-edit test","post_status"=>"publish","post_type"=>"post","post_content"=>"x"]);
		// Original AI draft (what an unfixed Apply would have written).
		$p = $store->create([
			"operation_id"=>"seo_manage","action"=>"seo_update","target_type"=>"post","target_id"=>(string)$pid,
			"payload"=>["action"=>"seo_update","content_id"=>$pid,"seo"=>["title"=>"ORIGINAL AI Title","description"=>"Original AI meta description that is plenty long for the test."]],
			"prior"=>["title"=>"","description"=>""],"provider"=>"anthropic","model"=>"m","batch_id"=>wp_generate_uuid4(),
		]);
		$pidp = $p["proposal_id"];
		// UI persistRow() — PUT the EDITED (visible) values as final_payload (no manual Save step).
		$put = new WP_REST_Request("PUT","/wp-command-center/v1/admin/proposals/".$pidp);
		$put->set_header("content-type","application/json");
		$put->set_body(wp_json_encode(["final_payload"=>["action"=>"seo_update","content_id"=>$pid,"seo"=>["title"=>"EDITED Title","description"=>"Edited meta description that is also plenty long enough for the test."]]]));
		rest_do_request($put);
		// Apply via the EXISTING route.
		$ap = new WP_REST_Request("POST","/wp-command-center/v1/admin/proposals/".$pidp."/apply");
		$ad = rest_do_request($ap)->get_data();
		$out["resp_status"] = (string)($ad["status"] ?? "");
		$out["change_id_present"] = !empty($ad["change_id"]) ? 1 : 0; // toast Undo needs this
		$prov = \WPCommandCenter\Operations\SeoProvider::detect();
		$seo = \WPCommandCenter\Operations\SeoProvider::read($pid, $prov);
		$out["edited_applied"]  = ( ($seo["title"]??"") === "EDITED Title" ) ? 1 : 0;
		$out["original_avoided"]= ( ($seo["title"]??"") !== "ORIGINAL AI Title" ) ? 1 : 0;
		update_option("wpcc_security_mode",$prev);
		wp_delete_post($pid, true);
		echo wp_json_encode($out);
	')"
	uj() { printf '%s' "$UE" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["'"$1"'"] ?? "";' 2>/dev/null; }
	if [ "$(uj skip)" = "no_seo_plugin" ]; then
		echo "  NOTE: no SEO plugin active — unsaved-edit functional path skipped."
	else
		assert_eq "apply still succeeds after persist"           "applied" "$(uj resp_status)"
		assert_eq "EDITED (visible) values are applied"          "1" "$(uj edited_applied)"
		assert_eq "stale ORIGINAL AI values are NOT applied"     "1" "$(uj original_avoided)"
		assert_eq "apply response carries change_id (toast Undo)" "1" "$(uj change_id_present)"
	fi
else
	echo "  SKIP: wp-cli not available — static checks only."
fi

echo
echo "== 4e. Trust fix A/B — view wiring (persist-before-apply + toast + single rollback) =="
# Fix A: a shared persistRow() PUTs final_payload, used by BOTH Save and Apply.
has  "persistRow helper present"             "function persistRow"        "$VIEW"
has  "persistRow uses governed PUT route"    "method: 'PUT'"              "$VIEW"
has  "Apply persists before applying"        "persistRow( id, row, tid )" "$VIEW"
has  "Apply still uses existing /apply route" "/apply'"                   "$VIEW"
has  "no apply on persist failure"           "do NOT apply stale data"    "$VIEW"
# Fix B: post-apply toast with reversibility + audit + Undo via the SHARED rollback path.
has  "toast region present"                  "wpcc-seo-toast"             "$VIEW"
has  "showApplyToast called after apply"     "showApplyToast( st, cid )"  "$VIEW"
has  "toast Applied label"                   "toastApplied"               "$VIEW"
has  "toast Reversible chip"                 "chipReversible"             "$VIEW"
has  "toast Audited chip"                    "chipAudited"                "$VIEW"
has  "toast Undo reuses shared rollback"     "function rollbackChange"    "$VIEW"
has  "shared rollback outcome parser"        "function rollbackOutcome"   "$VIEW"
# Exactly ONE rollback fetch (single governed path; no second rollback path added).
assert_eq "exactly one rollback fetch in view" "1" "$(grep -c "/history/' + encodeURIComponent( cid ) + '/rollback'" "$VIEW")"
lacks "no direct seo_manage call in view (still)" "seo_manage'"           "$VIEW"
lacks "no OperationExecutor in view (still)"      "OperationExecutor"      "$VIEW"
lacks "no SeoProvider::write in view (still)"     "SeoProvider::write"     "$VIEW"

echo
echo "== 5. Invariants unchanged =="
assert_eq "OPERATION_MAP == 34" "34" "$(wpe 'echo count(\WPCommandCenter\Operations\CapabilityRegistry::OPERATION_MAP);')"
assert_eq "capabilities == 23"  "23" "$(wpe 'echo count(\WPCommandCenter\Operations\CapabilityRegistry::ALL_CAPABILITIES);')"
assert_eq "catalogue == 40"     "40" "$(wpe 'echo count((new \WPCommandCenter\Operations\OperationRegistry())->get_operations());')"
assert_eq "DB_VERSION 2.5.0"    "2.5.0" "$(wpe 'echo \WPCommandCenter\Core\Schema::DB_VERSION;')"

echo ""
echo "RESULT: ${PASS} passed, ${FAIL} failed"
[ "$FAIL" -eq 0 ]
