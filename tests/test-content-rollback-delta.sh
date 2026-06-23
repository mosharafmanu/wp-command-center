#!/usr/bin/env bash
#
# PROGRAM-4 / P4.3 — Field-scoped, drift-aware Content delta rollback.
#
# Asserts the content_update rollback record is a field-scoped DELTA (version 2,
# `fields` map of only touched columns — title/status/content/excerpt — each with
# post-write `after` + prior value) and that restore is field-scoped, drift-aware,
# idempotent, legacy-compatible (incl. delete records), and history-honest.

set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_ROOT="$(cd "$PLUGIN_DIR/../../.." && pwd)"
cd "$PLUGIN_DIR"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
has()  { grep -qF -- "$2" "$3" && pass "$1" || fail "$1 (missing '$2')"; }

SRC="$PLUGIN_DIR/includes/Operations/ContentManager.php"
CFA="$PLUGIN_DIR/includes/Rollback/ContentFieldAccessor.php"
RBD="$PLUGIN_DIR/includes/Rollback/RollbackDelta.php"  # PROGRAM-4B: record/envelope core

echo "PROGRAM-4 / P4.3 — Field-scoped, drift-aware Content delta rollback"

echo
echo "== 1. Source =="
has  "ContentFieldAccessor implements FieldAccessor" "class ContentFieldAccessor implements FieldAccessor" "$CFA"
has  "accessor writes post columns"                 "wp_update_post( [ 'ID' => (int) \$entity_id, \$key => \$value ] )" "$CFA"
has  "update captures touched fields via core"      "RollbackDelta::capture( \$accessor, \$id, \$touched )" "$SRC"
has  "store builds v2 record via core"              "RollbackDelta::build_record( \$touched, \$prior, \$after, \$context," "$SRC"
has  "store persists via keyed RollbackStore"       "OptionKeyedRollbackStore( 'wpcc_content_rollbacks' ) )->persist" "$SRC"
has  "v2 record shape in core"                       "'version'          => 2," "$RBD"
has  "rollback restores via core"                   "RollbackDelta::restore( new ContentFieldAccessor(), \$id, \$record['fields'] )" "$SRC"
has  "legacy before_state branch retained"          "\$before = \$record['before_state'];" "$SRC"
has  "complete-only terminal"                       "if ( 'complete' === \$o['status'] ) {" "$SRC"
has  "envelope via core result()"                   "RollbackDelta::result(" "$SRC"
has  "conflict code (core)"                          "wpcc_rollback_conflict" "$RBD"
has  "partial code (core)"                           "wpcc_rollback_partial" "$RBD"

echo
echo "== 2. Functional =="
if ! command -v wp >/dev/null 2>&1; then echo "  SKIP: wp-cli unavailable"; else
	RES="$(wp --path="$WP_ROOT" eval '
		$u=get_users(["role"=>"administrator","number"=>1]); wp_set_current_user($u?$u[0]->ID:1);
		$M="\WPCommandCenter\Operations\ContentManager"; $mgr=new $M();
		$P=0;$F=0; $ok=function($d,$c)use(&$P,&$F){if($c){$P++;echo "  PASS: $d\n";}else{$F++;echo "  FAIL: $d\n";}};
		$mk=function($t="ORIG_T",$c="ORIG_C",$e="ORIG_E",$s="publish"){ return wp_insert_post(["post_title"=>$t,"post_content"=>$c,"post_excerpt"=>$e,"post_status"=>$s,"post_type"=>"post"]); };
		$tt=function($id){ return get_post_field("post_title",$id,"raw"); };
		$cc=function($id){ return get_post_field("post_content",$id,"raw"); };
		$ee=function($id){ return get_post_field("post_excerpt",$id,"raw"); };
		$upd=function($id,$a)use($mgr){ return $mgr->run(array_merge(["action"=>"content_update","content_id"=>$id],$a),[]); };
		$rb=function($rid)use($mgr){ return $mgr->run(["action"=>"content_rollback","rollback_id"=>$rid],[]); };

		/* S1 value-prior */
		$id=$mk(); $r=$upd($id,["title"=>"NEW_T"]);
		$ok("S1 rollback_id surfaced", !empty($r["rollback_id"]));
		$rb($r["rollback_id"]); $ok("S1 title restored", "ORIG_T"===$tt($id)); wp_delete_post($id,true);

		/* S2 empty-but-existing excerpt */
		$id=$mk("ORIG_T","ORIG_C",""); $r=$upd($id,["excerpt"=>"HAS"]); $rb($r["rollback_id"]);
		$ok("S2 empty excerpt restored", ""===$ee($id)); wp_delete_post($id,true);

		/* S3 sibling + drift (partial) */
		$id=$mk();
		$A=$upd($id,["title"=>"A_T","content"=>"A_C"]);
		$B=$upd($id,["content"=>"B_C"]);
		$res=$rb($A["rollback_id"]);
		$ok("S3 rollback A partial", ($res["status"]??"")==="partial");
		$ok("S3 title restored", "ORIG_T"===$tt($id));
		$ok("S3 sibling content (B) survives", "B_C"===$cc($id));
		$ok("S3 content reported skipped", in_array("content",$res["skipped_fields"]??[],true));
		wp_delete_post($id,true);

		/* S4 same-field conflict */
		$id=$mk(); $A=$upd($id,["title"=>"A_T"]); $Bx=$upd($id,["title"=>"B_T"]);
		$res=$rb($A["rollback_id"]);
		$ok("S4 conflict", ($res["status"]??"")==="conflict");
		$ok("S4 newer title kept", "B_T"===$tt($id));
		$ok("S4 conflict not clean success", empty($res["restored"]) || ($res["error"]??false)===true);
		wp_delete_post($id,true);

		/* S5 out-of-order */
		$id=$mk(); $A=$upd($id,["title"=>"A_T"]); $Bx=$upd($id,["title"=>"B_T"]);
		$rb($Bx["rollback_id"]); $ok("S5 after B rollback title=A_T", "A_T"===$tt($id));
		$rb($A["rollback_id"]);  $ok("S5 after A rollback title=ORIG (no resurrection)", "ORIG_T"===$tt($id));
		wp_delete_post($id,true);

		/* S6 legacy before_state update record */
		$id=$mk();
		$recs=get_option("wpcc_content_rollbacks",[]);
		$recs["legacy-content-1"]=["id"=>"legacy-content-1","content_id"=>$id,"action"=>"update","before_state"=>["title"=>"LEG_T","status"=>"publish","content"=>"LEG_C","excerpt"=>"LEG_E"],"rollback_applied"=>false,"created_at"=>time()];
		update_option("wpcc_content_rollbacks",$recs);
		wp_update_post(["ID"=>$id,"post_title"=>"CHANGED"]);
		$rb("legacy-content-1");
		$ok("S6 legacy restore title", "LEG_T"===$tt($id));
		$ok("S6 legacy restore content", "LEG_C"===$cc($id));
		wp_delete_post($id,true);

		/* S7 delete record still reverts via legacy path */
		$id=$mk("DEL_T","DEL_C","DEL_E","publish");
		$dr=$mgr->run(["action"=>"content_delete","content_id"=>$id],[]);
		$ok("S7 delete returns rollback_id", !empty($dr["rollback_id"]));
		$rr=$mgr->run(["action"=>"content_rollback","rollback_id"=>$dr["rollback_id"]],[]);
		$ok("S7 delete rollback no error + status restored", !is_wp_error($rr) && "trash"!==get_post_status($id));
		wp_delete_post($id,true);

		/* S8 repeated rollback guarded */
		$id=$mk(); $r=$upd($id,["title"=>"X_T"]); $rb($r["rollback_id"]); $again=$rb($r["rollback_id"]);
		$ok("S8 repeated rollback guarded", is_wp_error($again) && $again->get_error_code()==="wpcc_rollback_already_applied");
		wp_delete_post($id,true);

		/* S10 untouched column not in record */
		$id=$mk(); $r=$upd($id,["title"=>"ONLY_T"]);
		$rec=get_option("wpcc_content_rollbacks",[])[$r["rollback_id"]]??null;
		$ok("S10 v2 fields, only title", isset($rec["fields"]["title"]) && !isset($rec["fields"]["content"]) && !isset($rec["fields"]["status"]));
		wp_delete_post($id,true);

		echo "FUNC: PASS=$P FAIL=$F\n";
	' 2>/dev/null)"
	echo "$RES" | grep -E "PASS:|FAIL:" | grep -v "PASS=" || true
	fp="$(echo "$RES" | sed -n "s/.*FUNC: PASS=\([0-9]*\) FAIL=\([0-9]*\).*/\1 \2/p")"
	PASS=$((PASS + ${fp% *})); FAIL=$((FAIL + ${fp#* }))
fi

echo
echo "Content delta-rollback: PASS=$PASS FAIL=$FAIL"
[ "$FAIL" -eq 0 ]
