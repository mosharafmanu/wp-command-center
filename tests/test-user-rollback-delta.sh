#!/usr/bin/env bash
#
# PROGRAM-4 / P4.5 — Field-scoped, drift-aware User profile delta rollback.
#
# Asserts the user_update rollback record is a field-scoped DELTA (version 2, `fields`
# map of only touched profile fields — email/display_name/first_name/last_name) and
# that restore is field-scoped, drift-aware, idempotent, legacy-compatible, and
# history-honest — AND that email is now restored (the pre-P4.5 update-rollback bug
# where `email` vs `user_email` silently dropped the email is fixed).

set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_ROOT="$(cd "$PLUGIN_DIR/../../.." && pwd)"
cd "$PLUGIN_DIR"

PASS=0; FAIL=0
has()  { grep -qF -- "$2" "$3" && { PASS=$((PASS+1)); echo "  PASS: $1"; } || { FAIL=$((FAIL+1)); echo "  FAIL: $1 (missing '$2')"; }; }

SRC="$PLUGIN_DIR/includes/Operations/UserManager.php"
UFA="$PLUGIN_DIR/includes/Rollback/UserFieldAccessor.php"
RBD="$PLUGIN_DIR/includes/Rollback/RollbackDelta.php"  # PROGRAM-4B: record/envelope core

echo "PROGRAM-4 / P4.5 — Field-scoped, drift-aware User profile delta rollback"
echo
echo "== 1. Source =="
has  "UserFieldAccessor implements FieldAccessor" "class UserFieldAccessor implements FieldAccessor" "$UFA"
has  "accessor maps email -> user_email"          "'email'        => 'user_email'," "$UFA"
has  "accessor writes via wp_update_user"         "wp_update_user( [ 'ID' => (int) \$entity_id, \$key => (string) \$value ] )" "$UFA"
has  "update captures touched fields via core"    "RollbackDelta::capture( \$accessor, \$user_id, \$touched )" "$SRC"
has  "store builds v2 record via core"            "RollbackDelta::build_record( \$touched, \$prior, \$after, \$context," "$SRC"
has  "store persists via RollbackStore"           "OptionListRollbackStore( 'wpcc_user_rollbacks', 100 ) )->persist" "$SRC"
has  "v2 record shape in core"                     "'version'          => 2," "$RBD"
has  "rollback restores via core"                 "RollbackDelta::restore( new UserFieldAccessor(), \$user_id, \$record['fields'] )" "$SRC"
has  "envelope via core result()"                 "RollbackDelta::result(" "$SRC"
has  "legacy before_state branch retained"        "case 'update':" "$SRC"
has  "complete-only terminal"                     "if ( 'complete' === \$o['status'] ) {" "$SRC"
has  "conflict code (core)"                       "wpcc_rollback_conflict" "$RBD"

echo
echo "== 2. Functional =="
if ! command -v wp >/dev/null 2>&1; then echo "  SKIP: wp-cli unavailable"; else
	RES="$(wp --path="$WP_ROOT" eval '
		$admins=get_users(["role"=>"administrator","number"=>1]); wp_set_current_user($admins?$admins[0]->ID:1);
		$M="\WPCommandCenter\Operations\UserManager"; $mgr=new $M();
		$P=0;$F=0; $ok=function($d,$c)use(&$P,&$F){if($c){$P++;echo "  PASS: $d\n";}else{$F++;echo "  FAIL: $d\n";}};
		$n=0;
		$mk=function()use(&$n){ $n++; $id=wp_insert_user(["user_login"=>"p45u$n","user_email"=>"orig$n@e.com","user_pass"=>"x","display_name"=>"ORIG_DN","first_name"=>"ORIG_FN","last_name"=>"ORIG_LN","role"=>"subscriber"]); return is_wp_error($id)?0:$id; };
		$em=function($id){ $u=get_userdata($id); return $u?$u->user_email:"?"; };
		$dn=function($id){ $u=get_userdata($id); return $u?$u->display_name:"?"; };
		$upd=function($id,$a)use($mgr){ return $mgr->run(array_merge(["action"=>"user_update","user_id"=>$id],$a),[]); };
		$rb=function($rid)use($mgr){ return $mgr->rollback(["rollback_id"=>$rid]); };

		/* S1 email value-prior — proves DEF-U1 (email restore) fixed */
		$id=$mk(); $r=$upd($id,["email"=>"new1@e.com"]);
		$ok("S1 email applied", "new1@e.com"===$em($id));
		$ok("S1 rollback_id surfaced", !empty($r["rollback_id"]));
		$rb($r["rollback_id"]);
		$ok("S1 EMAIL restored (DEF-U1 fixed)", "orig1@e.com"===$em($id));
		wp_delete_user($id);

		/* S2 display_name restore */
		$id=$mk(); $r=$upd($id,["display_name"=>"NEW_DN"]); $rb($r["rollback_id"]);
		$ok("S2 display_name restored", "ORIG_DN"===$dn($id)); wp_delete_user($id);

		/* S3 sibling + drift (partial): update email+display_name; later display_name change */
		$id=$mk();
		$A=$upd($id,["email"=>"a3@e.com","display_name"=>"A_DN"]);
		$B=$upd($id,["display_name"=>"B_DN"]);
		$res=$rb($A["rollback_id"]);
		$ok("S3 rollback A partial", ($res["status"]??"")==="partial");
		$ok("S3 email restored", "orig3@e.com"===$em($id));
		$ok("S3 sibling display_name (B) survives", "B_DN"===$dn($id));
		$ok("S3 display_name reported skipped", in_array("display_name",$res["skipped_fields"]??[],true));
		wp_delete_user($id);

		/* S4 same-field conflict (email) */
		$id=$mk(); $A=$upd($id,["email"=>"a4@e.com"]); $Bx=$upd($id,["email"=>"b4@e.com"]);
		$res=$rb($A["rollback_id"]);
		$ok("S4 conflict", ($res["status"]??"")==="conflict");
		$ok("S4 newer email kept", "b4@e.com"===$em($id));
		$ok("S4 conflict not clean success", ($res["error"]??false)===true);
		wp_delete_user($id);

		/* S5 out-of-order */
		$id=$mk(); $A=$upd($id,["email"=>"a5@e.com"]); $Bx=$upd($id,["email"=>"b5@e.com"]);
		$rb($Bx["rollback_id"]); $ok("S5 after B rollback email=a5", "a5@e.com"===$em($id));
		$rb($A["rollback_id"]);  $ok("S5 after A rollback email=orig5 (no resurrection)", "orig5@e.com"===$em($id));
		wp_delete_user($id);

		/* S6 legacy before_state record still restores (display_name via legacy path) */
		$id=$mk();
		$rbs=get_option("wpcc_user_rollbacks",[]);
		$lid="legacy-user-".$id;
		$rbs[]=["id"=>$lid,"user_id"=>$id,"action"=>"update","before_state"=>["email"=>"orig6@e.com","display_name"=>"LEG_DN","first_name"=>"LEG_FN","last_name"=>"LEG_LN"],"rollback_applied"=>false,"created_at"=>time()];
		update_option("wpcc_user_rollbacks",$rbs);
		wp_update_user(["ID"=>$id,"display_name"=>"CHANGED_DN"]);
		$lr=$rb($lid);
		$ok("S6 legacy restore display_name", "LEG_DN"===$dn($id) && empty($lr["error"]));
		wp_delete_user($id);

		/* S7 repeated rollback guarded */
		$id=$mk(); $r=$upd($id,["email"=>"x7@e.com"]); $rb($r["rollback_id"]); $again=$rb($r["rollback_id"]);
		$ok("S7 repeated rollback guarded", ($again["code"]??"")==="wpcc_rollback_already_applied");
		wp_delete_user($id);

		/* S8 untouched field not in record (email-only update) */
		$id=$mk(); $r=$upd($id,["email"=>"only8@e.com"]);
		$rec=null; foreach(get_option("wpcc_user_rollbacks",[]) as $x){ if(($x["id"]??"")===$r["rollback_id"]){$rec=$x;break;} }
		$ok("S8 v2 fields, only email", isset($rec["fields"]["email"]) && !isset($rec["fields"]["display_name"]));
		wp_delete_user($id);

		echo "FUNC: PASS=$P FAIL=$F\n";
	' 2>/dev/null)"
	echo "$RES" | grep -E "PASS:|FAIL:" | grep -v "PASS=" || true
	fp="$(echo "$RES" | sed -n "s/.*FUNC: PASS=\([0-9]*\) FAIL=\([0-9]*\).*/\1 \2/p")"
	PASS=$((PASS + ${fp% *})); FAIL=$((FAIL + ${fp#* }))
fi

echo
echo "User profile delta-rollback: PASS=$PASS FAIL=$FAIL"
[ "$FAIL" -eq 0 ]
