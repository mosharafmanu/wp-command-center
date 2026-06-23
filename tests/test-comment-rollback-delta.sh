#!/usr/bin/env bash
#
# PROGRAM-4 / P4.4 — Field-scoped, drift-aware Comment status delta rollback.
#
# Comments had NO reversibility for approve/unapprove/spam (silent gap). P4.4 makes
# the moderation status (comment_approved) reversible via a field-scoped v2 delta:
# drift-aware (skip+conflict on later change), idempotent, history-honest. trash
# (untrash) and delete (unsupported) are unchanged.

set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_ROOT="$(cd "$PLUGIN_DIR/../../.." && pwd)"
cd "$PLUGIN_DIR"

PASS=0; FAIL=0
has()  { grep -qF -- "$2" "$3" && { PASS=$((PASS+1)); echo "  PASS: $1"; } || { FAIL=$((FAIL+1)); echo "  FAIL: $1 (missing '$2')"; }; }

SRC="$PLUGIN_DIR/includes/Operations/CommentsRuntimeManager.php"
CFA="$PLUGIN_DIR/includes/Rollback/CommentFieldAccessor.php"

echo "PROGRAM-4 / P4.4 — Field-scoped, drift-aware Comment status delta rollback"
echo
echo "== 1. Source =="
has  "CommentFieldAccessor implements FieldAccessor" "class CommentFieldAccessor implements FieldAccessor" "$CFA"
has  "accessor writes via wp_update_comment"        "wp_update_comment( [ 'comment_ID' => (int) \$entity_id, \$key => (string) \$value ], true )" "$CFA"
has  "approve captures status before change"         "RollbackDelta::capture( \$accessor, \$comment_id, [ 'status' ] )" "$SRC"
has  "store writes v2 status delta"                  "'version'          => 2," "$SRC"
has  "rollback restores via core"                    "RollbackDelta::restore( new CommentFieldAccessor(), \$comment_id, \$record['fields'] )" "$SRC"
has  "trash untrash retained"                         "wp_untrash_comment( \$comment_id )" "$SRC"
has  "delete unsupported retained"                    "wpcc_rollback_unsupported" "$SRC"
has  "complete-only terminal"                         "if ( 'complete' === \$o['status'] ) {" "$SRC"

echo
echo "== 2. Functional =="
if ! command -v wp >/dev/null 2>&1; then echo "  SKIP: wp-cli unavailable"; else
	RES="$(wp --path="$WP_ROOT" eval '
		$u=get_users(["role"=>"administrator","number"=>1]); wp_set_current_user($u?$u[0]->ID:1);
		$M="\WPCommandCenter\Operations\CommentsRuntimeManager"; $mgr=new $M();
		$P=0;$F=0; $ok=function($d,$c)use(&$P,&$F){if($c){$P++;echo "  PASS: $d\n";}else{$F++;echo "  FAIL: $d\n";}};
		$post=wp_insert_post(["post_title"=>"C-host","post_status"=>"publish","post_type"=>"post"]);
		$mkc=function($approved="0")use($post){ return wp_insert_comment(["comment_post_ID"=>$post,"comment_content"=>"hi","comment_author"=>"A","comment_author_email"=>"a@e.com","comment_approved"=>$approved]); };
		$st=function($id){ $c=get_comment($id); return $c?(string)$c->comment_approved:"?"; };
		$run=function($a,$id)use($mgr){ return $mgr->run(["action"=>$a,"comment_id"=>$id],[]); };
		$rb=function($rid)use($mgr){ return $mgr->rollback(["rollback_id"=>$rid]); };

		/* S1 approve -> rollback restores hold */
		$c=$mkc("0"); $r=$run("comment_approve",$c);
		$ok("S1 approved (status 1)", "1"===$st($c));
		$ok("S1 rollback_id surfaced", !empty($r["rollback_id"]));
		$rb($r["rollback_id"]); $ok("S1 rollback restored hold (0)", "0"===$st($c));
		wp_delete_comment($c,true);

		/* S2 unapprove -> rollback restores approved */
		$c=$mkc("1"); $r=$run("comment_unapprove",$c);
		$ok("S2 unapproved (status 0)", "0"===$st($c));
		$rb($r["rollback_id"]); $ok("S2 rollback restored approved (1)", "1"===$st($c));
		wp_delete_comment($c,true);

		/* S3 spam -> rollback restores prior approval */
		$c=$mkc("1"); $r=$run("comment_spam",$c);
		$ok("S3 spammed", "spam"===$st($c));
		$rb($r["rollback_id"]); $ok("S3 rollback restored approved (1)", "1"===$st($c));
		wp_delete_comment($c,true);

		/* S4 same-field drift -> conflict (status changed again after) */
		$c=$mkc("0"); $r=$run("comment_approve",$c);    // prior 0, after 1
		wp_set_comment_status($c,"spam");               // drift
		$res=$rb($r["rollback_id"]);
		$ok("S4 drift conflict", ($res["status"]??"")==="conflict");
		$ok("S4 drifted status not clobbered (still spam)", "spam"===$st($c));
		$ok("S4 conflict not clean success", ($res["error"]??false)===true);
		wp_delete_comment($c,true);

		/* S5 out-of-order: approve then unapprove, rollback newer then older */
		$c=$mkc("0");
		$A=$run("comment_approve",$c);     // 0 -> 1
		$B=$run("comment_unapprove",$c);   // 1 -> 0
		$rbB=$rb($B["rollback_id"]); $ok("S5 rollback B -> approved (1)", "1"===$st($c) && ($rbB["status"]??"")==="complete");
		$rbA=$rb($A["rollback_id"]); $ok("S5 rollback A -> hold (0), no resurrection", "0"===$st($c) && ($rbA["status"]??"")==="complete");
		wp_delete_comment($c,true);

		/* S6 repeated rollback guarded */
		$c=$mkc("0"); $r=$run("comment_approve",$c); $rb($r["rollback_id"]); $again=$rb($r["rollback_id"]);
		$ok("S6 repeated rollback guarded", ($again["code"]??"")==="wpcc_rollback_already_applied");
		wp_delete_comment($c,true);

		/* S7 trash record still untrashes (unchanged path) */
		$c=$mkc("1"); $tr=$run("comment_trash",$c);
		$ok("S7 trash returns rollback_id", !empty(get_option("wpcc_comments_rollbacks")));
		// find the trash record id
		$tid=""; foreach(get_option("wpcc_comments_rollbacks",[]) as $x){ if(($x["comment_id"]??0)===$c && ($x["action"]??"")==="trash"){$tid=$x["id"];break;} }
		$rr=$rb($tid);
		$ok("S7 trash rollback untrashed (not trash)", "trash"!==$st($c) && empty($rr["error"]));
		wp_delete_comment($c,true);

		/* S8 delete remains irreversible (no rollback record — unchanged behaviour) */
		$c=$mkc("1"); $nb=count(get_option("wpcc_comments_rollbacks",[]));
		$run("comment_delete",$c);
		$na=count(get_option("wpcc_comments_rollbacks",[]));
		$ok("S8 delete creates no rollback record (irreversible, unchanged)", $na===$nb);

		wp_delete_post($post,true);
		echo "FUNC: PASS=$P FAIL=$F\n";
	' 2>/dev/null)"
	echo "$RES" | grep -E "PASS:|FAIL:" | grep -v "PASS=" || true
	fp="$(echo "$RES" | sed -n "s/.*FUNC: PASS=\([0-9]*\) FAIL=\([0-9]*\).*/\1 \2/p")"
	PASS=$((PASS + ${fp% *})); FAIL=$((FAIL + ${fp#* }))
fi

echo
echo "Comment status delta-rollback: PASS=$PASS FAIL=$FAIL"
[ "$FAIL" -eq 0 ]
