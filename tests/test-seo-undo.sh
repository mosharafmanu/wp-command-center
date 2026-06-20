#!/usr/bin/env bash
#
# GA#2 — Slice 4b: SEO Applied tab per-item Undo.
#
# Asserts the Applied tab exposes a per-item Undo control ONLY on applied +
# reversible (not yet rolled back) rows that carry a change_id, and that Undo reuses
# the EXISTING governed change-history rollback route:
#   POST /admin/history/{change_id}/rollback
#     → change_history operation → seo_restore (the same governed chokepoint as apply).
# Developer → immediate revert; client/enterprise → pending_approval. NO bulk undo, NO
# SelectionResolver, NO direct executor/seo_manage call, NO new route/op/cap/tool/schema.
# Invariants frozen. Includes a live developer apply → undo → meta-restored round-trip.
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

echo "GA#2 — Slice 4b: SEO Applied tab per-item Undo"

echo
echo "== 1. View: Undo control reuses the EXISTING governed rollback route =="
has  "Undo control present"                 "wpcc-seo-undo"        "$VIEW"
has  "Undo label string"                    "undo:"                "$VIEW"
has  "Undo posts to history rollback route" "/history/"            "$VIEW"
has  "rollback route suffix"                "/rollback"            "$VIEW"
has  "Undo handler scoped to Applied rows"  "#wpcc-seo-ap-rows tr[data-cid]" "$VIEW"
has  "Applied tab has Actions column"       "colActions"           "$VIEW"

echo
echo "== 2. View: visibility rules (applied + reversible + change_id only) =="
has  "reversible derived from applied+change_id" "reversible = ( p.status === 'applied' && !! p.change_id )" "$VIEW"
has  "rolled_back rows excluded (no Undo)"  "p.change_status === 'rolled_back'" "$VIEW"
has  "data-cid only when reversible"        "reversible ? ' data-cid=\"'" "$VIEW"
has  "Reverted badge (post-undo state)"     "stReverted"           "$VIEW"

echo
echo "== 3. View: mode-aware + non-fatal handling, disabled-in-flight =="
has  "pending_approval → Undo sent"         "undoSent"             "$VIEW"
has  "reads pending_approval status"        "pending_approval"     "$VIEW"
has  "non-fatal failure message"            "cantUndo"             "$VIEW"
has  "button disabled while in flight"      "t.disabled = true"    "$VIEW"
has  "success reloads Applied tab"          "loadApplied()"        "$VIEW"

echo
echo "== 4. View: boundaries — no bulk/selection/links/direct write =="
lacks "no bulk undo"                        "bulkUndo"             "$VIEW"
lacks "no bulk apply"                       "bulkApply"            "$VIEW"
lacks "no SelectionResolver"                "SelectionResolver"    "$VIEW"
lacks "no direct OperationExecutor"         "OperationExecutor"    "$VIEW"
lacks "no direct seo_manage call (quoted)"  "seo_manage'"          "$VIEW"
lacks "no Approval Center link"             "wpcc-approval-center" "$VIEW"
lacks "no Change History link"              "wpcc-change-history"  "$VIEW"
lacks "no direct SEO meta write in view"    "SeoProvider::write"   "$VIEW"
# No NEW history/rollback route was added — Undo reuses the deployed one.
HRB="$(grep -cF -- "register_rest_route( self::NS, '/admin/history/(?P<change_id>" "$RESTAPI")"
assert_eq "history routes unchanged (no new rollback route)" "3" "$HRB"

echo
echo "== 5. Functional: developer apply → undo → meta restored round-trip =="
if ! command -v wp >/dev/null 2>&1; then
	echo "  SKIP: wp-cli not available — static checks only."
else
	RES="$(wpe '
		$a=get_users(["role"=>"administrator","number"=>1]); wp_set_current_user($a?$a[0]->ID:1);
		$prev = get_option("wpcc_security_mode","developer"); update_option("wpcc_security_mode","developer");
		$store = new WPCommandCenter\Proposals\ProposalStore();
		$out = [];

		if ( \WPCommandCenter\Operations\SeoProvider::NONE === \WPCommandCenter\Operations\SeoProvider::detect() ) { $out["skip"]="no_seo_plugin"; echo wp_json_encode($out); return; }
		$prov = \WPCommandCenter\Operations\SeoProvider::detect();

		$pid = wp_insert_post(["post_title"=>"WPCC SEO undo test","post_status"=>"publish","post_type"=>"post","post_content"=>"x"]);
		// Seed a known ORIGINAL SEO title so restoration can be asserted exactly.
		\WPCommandCenter\Operations\SeoProvider::write($pid, ["title"=>"Original SEO Title"], $prov);

		$p = $store->create([
			"operation_id"=>"seo_manage","action"=>"seo_update","target_type"=>"post","target_id"=>(string)$pid,
			"payload"=>["action"=>"seo_update","content_id"=>$pid,"seo"=>["title"=>"Applied SEO Title","description"=>"Applied meta description long enough for a realistic test."]],
			"prior"=>["title"=>"Original SEO Title","description"=>""],"provider"=>"anthropic","model"=>"m","batch_id"=>wp_generate_uuid4(),
		]);
		$pidp = $p["proposal_id"];

		// Apply via the EXISTING proposal route (developer → applied).
		$areq = new WP_REST_Request("POST","/wp-command-center/v1/admin/proposals/".$pidp."/apply");
		rest_do_request($areq);
		$row = $store->get($pidp);
		$cid = (string)($row["change_id"] ?? "");
		$out["has_change_id"] = ( "" !== $cid ) ? 1 : 0;
		$seo1 = \WPCommandCenter\Operations\SeoProvider::read($pid, $prov);
		$out["meta_applied"] = ( ($seo1["title"]??"") === "Applied SEO Title" ) ? 1 : 0;

		// UNDO via the EXISTING governed change-history rollback route.
		$rreq = new WP_REST_Request("POST","/wp-command-center/v1/admin/history/".$cid."/rollback");
		$rd = rest_do_request($rreq)->get_data();
		$out["rollback_success"] = ( !empty($rd["success"]) ) ? 1 : 0;

		// Meta restored to the captured ORIGINAL (proves seo_restore ran end-to-end).
		$seo2 = \WPCommandCenter\Operations\SeoProvider::read($pid, $prov);
		$out["meta_restored"] = ( ($seo2["title"]??"") === "Original SEO Title" ) ? 1 : 0;

		// ProposalAdminQuery rollback-aware presentation flips to rolled_back.
		$q = new \WPCommandCenter\Admin\ProposalAdminQuery();
		$shaped = $q->get($pidp);
		$out["change_status_rolled_back"] = ( ($shaped["change_status"]??"") === "rolled_back" ) ? 1 : 0;

		update_option("wpcc_security_mode",$prev);
		wp_delete_post($pid, true);
		echo wp_json_encode($out);
	')"
	gj() { printf '%s' "$RES" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["'"$1"'"] ?? "";' 2>/dev/null; }
	if [ "$(gj skip)" = "no_seo_plugin" ]; then
		echo "  NOTE: no SEO plugin active on this env — undo functional path skipped."
	else
		assert_eq "apply recorded a change_id"                 "1" "$(gj has_change_id)"
		assert_eq "apply wrote the new SEO title"              "1" "$(gj meta_applied)"
		assert_eq "undo via history rollback route succeeds"   "1" "$(gj rollback_success)"
		assert_eq "undo restored the ORIGINAL SEO title (e2e)" "1" "$(gj meta_restored)"
		assert_eq "proposal change_status flips to rolled_back" "1" "$(gj change_status_rolled_back)"
	fi
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
