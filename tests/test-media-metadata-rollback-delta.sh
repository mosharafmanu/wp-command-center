#!/usr/bin/env bash
#
# PROGRAM-4 / P4.2 — Field-scoped, drift-aware Media METADATA delta rollback.
#
# Asserts the media_update rollback record is a field-scoped DELTA (version 2,
# `fields` map of only touched metadata fields — title/caption/description/alt — each
# with post-write `after` + prior value/existence) and that restore is field-scoped,
# drift-aware, existence-faithful (alt), idempotent, legacy-compatible, and history-
# honest, via BOTH restore paths (rollback() and the media_restore action). File bytes
# and generated sizes are never touched by the metadata path.
#
# Functional section drives MediaRuntimeManager via wp eval on a throwaway attachment.

set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_ROOT="$(cd "$PLUGIN_DIR/../../.." && pwd)"
cd "$PLUGIN_DIR"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
has()  { grep -qF -- "$2" "$3" && pass "$1" || fail "$1 (missing '$2')"; }

SRC="$PLUGIN_DIR/includes/Operations/MediaRuntimeManager.php"
MFA="$PLUGIN_DIR/includes/Rollback/MediaFieldAccessor.php"
RBD="$PLUGIN_DIR/includes/Rollback/RollbackDelta.php"  # PROGRAM-4B: record/envelope core

echo "PROGRAM-4 / P4.2 — Field-scoped, drift-aware Media metadata delta rollback"

echo
echo "== 1. Source: MediaFieldAccessor + field-scoped capture + v2 delta + shared restore =="
has  "MediaFieldAccessor implements FieldAccessor" "class MediaFieldAccessor implements FieldAccessor" "$MFA"
has  "accessor dispatches post columns"           "wp_update_post( [ 'ID' => (int) \$entity_id, \$key => \$value ] )" "$MFA"
has  "accessor meta path"                          "update_post_meta( (int) \$entity_id, \$key, \$value )" "$MFA"
has  "accessor existence for alt (meta)"           "metadata_exists( 'post', (int) \$entity_id, \$key )" "$MFA"
has  "update captures touched fields via core"     "RollbackDelta::capture( \$accessor, \$media_id, \$touched )" "$SRC"
has  "store builds v2 record via core"             "RollbackDelta::build_record( \$touched, \$prior, \$after, \$context," "$SRC"
has  "store persists via RollbackStore"            "OptionListRollbackStore( 'wpcc_media_rollbacks', 100 ) )->persist" "$SRC"
has  "v2 record shape in core"                      "'version'          => 2," "$RBD"
has  "restore via core in shared helper"           "RollbackDelta::restore( new MediaFieldAccessor(), \$media_id, \$record['fields'] )" "$SRC"
has  "rollback() update early branch"              "restore_metadata_record( \$media_id, \$record )" "$SRC"
has  "legacy restore_metadata retained"            "function restore_metadata(" "$SRC"
has  "complete-only terminal"                      "if ( 'complete' === \$o['status'] ) {" "$SRC"
has  "envelope via core result()"                  "RollbackDelta::result(" "$SRC"
has  "conflict code (core)"                         "wpcc_rollback_conflict" "$RBD"
has  "partial code (core)"                          "wpcc_rollback_partial" "$RBD"

echo
echo "== 2. Functional: fidelity, sibling, drift, out-of-order, legacy, idempotency, both paths =="
if ! command -v wp >/dev/null 2>&1; then
	echo "  SKIP: wp-cli not available — static checks only."
else
	RES="$(wp --path="$WP_ROOT" eval '
		$u=get_users(["role"=>"administrator","number"=>1]); wp_set_current_user($u?$u[0]->ID:1);
		$M="\WPCommandCenter\Operations\MediaRuntimeManager"; $mgr=new $M();
		$P=0;$F=0; $ok=function($d,$c)use(&$P,&$F){if($c){$P++;echo "  PASS: $d\n";}else{$F++;echo "  FAIL: $d\n";}};
		$mk=function($alt=null){ $id=wp_insert_post(["post_type"=>"attachment","post_status"=>"inherit","post_title"=>"ORIG_T","post_excerpt"=>"ORIG_C","post_content"=>"ORIG_D","post_mime_type"=>"image/jpeg"]); if(null!==$alt)update_post_meta($id,"_wp_attachment_image_alt",$alt); return $id; };
		$alt=function($id){ return get_post_meta($id,"_wp_attachment_image_alt",true); };
		$altx=function($id){ return metadata_exists("post",$id,"_wp_attachment_image_alt"); };
		$upd=function($id,$a)use($mgr){ return $mgr->run(array_merge(["action"=>"media_update","media_id"=>$id],$a),[]); };
		$rb=function($rid)use($mgr){ return $mgr->rollback(["rollback_id"=>$rid]); };

		/* S1 — empty-prior alt → deleted on rollback */
		$id=$mk(null);                                  // no alt meta
		$r=$upd($id,["alt"=>"NEW_A"]);
		$ok("S1 alt applied", "NEW_A"===$alt($id));
		$ok("S1 rollback_id surfaced", !empty($r["rollback_id"]));
		$rb($r["rollback_id"]);
		$ok("S1 absent-prior alt deleted on rollback", !$altx($id));
		wp_delete_post($id,true);

		/* S2 — value-prior alt → restored exactly */
		$id=$mk("ORIG_A");
		$r=$upd($id,["alt"=>"NEW_A"]);
		$rb($r["rollback_id"]);
		$ok("S2 prior alt restored exactly", "ORIG_A"===$alt($id));
		wp_delete_post($id,true);

		/* S3 — empty-but-existing alt → restored as empty row (not deleted) */
		$id=$mk(""); // alt exists as empty
		$ok("S3 alt exists empty pre", $altx($id) && ""===$alt($id));
		$r=$upd($id,["alt"=>"NEW_A"]);
		$rb($r["rollback_id"]);
		$ok("S3 empty-but-existing alt restored (exists, empty)", $altx($id) && ""===$alt($id));
		wp_delete_post($id,true);

		/* S4 — sibling preservation + drift (partial): record={title,alt}; later title change */
		$id=$mk("ORIG_A");
		$A=$upd($id,["title"=>"A_T","alt"=>"A_A"]);     // touches title+alt
		$B=$upd($id,["title"=>"B_T"]);                  // later: title only
		$res=$rb($A["rollback_id"]);
		$ok("S4 rollback A is partial", ($res["status"]??"")==="partial");
		$ok("S4 title (B) NOT clobbered", "B_T"===get_post_field("post_title",$id,"raw"));
		$ok("S4 alt restored to prior", "ORIG_A"===$alt($id));
		$ok("S4 title reported skipped", in_array("title",$res["skipped_fields"]??[],true));
		wp_delete_post($id,true);

		/* S5 — same-field drift → conflict, no clobber */
		$id=$mk();
		$A=$upd($id,["title"=>"A_T"]);
		$Bx=$upd($id,["title"=>"B_T"]);
		$res=$rb($A["rollback_id"]);
		$ok("S5 rollback A is conflict", ($res["status"]??"")==="conflict");
		$ok("S5 newer title NOT clobbered", "B_T"===get_post_field("post_title",$id,"raw"));
		$ok("S5 conflict not clean success", empty($res["restored"]) || $res["restored"]===false);
		wp_delete_post($id,true);

		/* S6 — out-of-order rollback, no resurrection */
		$id=$mk();
		$A=$upd($id,["title"=>"A_T"]);
		$Bx=$upd($id,["title"=>"B_T"]);
		$rbB=$rb($Bx["rollback_id"]);
		$ok("S6 rollback B complete", ($rbB["status"]??"")==="complete");
		$ok("S6 title back to A_T after B rollback", "A_T"===get_post_field("post_title",$id,"raw"));
		$rbA=$rb($A["rollback_id"]);
		$ok("S6 retry A complete", ($rbA["status"]??"")==="complete");
		$ok("S6 title back to ORIG_T (no resurrection)", "ORIG_T"===get_post_field("post_title",$id,"raw"));
		wp_delete_post($id,true);

		/* S7 — legacy before_state record restores via legacy path (unique id => idempotent) */
		$id=$mk("LEG_A");
		$lid="legacy-media-".$id;
		$rbs=get_option("wpcc_media_rollbacks",[]);
		$rbs[]=["id"=>$lid,"media_id"=>$id,"action"=>"update","before_state"=>["title"=>"LEG_T","caption"=>"LEG_C","description"=>"LEG_D","alt"=>"LEG_A"],"rollback_applied"=>false,"created_at"=>time()];
		update_option("wpcc_media_rollbacks",$rbs);
		wp_update_post(["ID"=>$id,"post_title"=>"CHANGED"]); update_post_meta($id,"_wp_attachment_image_alt","CHANGED");
		$lr=$rb($lid);
		$ok("S7 legacy full restore (title)", "LEG_T"===get_post_field("post_title",$id,"raw"));
		$ok("S7 legacy full restore (alt)", "LEG_A"===$alt($id));
		wp_delete_post($id,true);

		/* S8 — repeated rollback guarded */
		$id=$mk();
		$r=$upd($id,["title"=>"X_T"]);
		$rb($r["rollback_id"]);
		$again=$rb($r["rollback_id"]);
		$ok("S8 second rollback guarded", ($again["code"]??"")==="wpcc_rollback_already_applied");
		wp_delete_post($id,true);

		/* S10 — untouched field not in record (alt-only update) */
		$id=$mk();
		$r=$upd($id,["alt"=>"ONLY_A"]);
		$rec=null; foreach(get_option("wpcc_media_rollbacks",[]) as $x){ if(($x["id"]??"")===$r["rollback_id"]){$rec=$x;break;} }
		$ok("S10 record is v2 fields", isset($rec["fields"]));
		$ok("S10 only alt in fields (title absent)", isset($rec["fields"]["alt"]) && !isset($rec["fields"]["title"]));
		wp_delete_post($id,true);

		/* S11 — generated sizes / file bytes untouched by metadata rollback */
		$id=$mk("ORIG_A");
		update_post_meta($id,"_wp_attachment_metadata",["width"=>800,"height"=>600,"sizes"=>["thumbnail"=>["file"=>"x-150x150.jpg"]]]);
		update_post_meta($id,"_wp_attached_file","2026/06/x.jpg");
		$metaBefore=get_post_meta($id,"_wp_attachment_metadata",true); $fileBefore=get_post_meta($id,"_wp_attached_file",true);
		$r=$upd($id,["alt"=>"NEW_A"]); $rb($r["rollback_id"]);
		$ok("S11 attachment metadata (sizes) untouched", get_post_meta($id,"_wp_attachment_metadata",true)===$metaBefore);
		$ok("S11 attached file path untouched", get_post_meta($id,"_wp_attached_file",true)===$fileBefore);
		wp_delete_post($id,true);

		/* S12 — media_restore action path also field-scoped (v2) */
		$id=$mk("ORIG_A");
		$r=$upd($id,["alt"=>"NEW_A"]);
		$mr=$mgr->run(["action"=>"media_restore","rollback_id"=>$r["rollback_id"]],[]);
		$ok("S12 media_restore restored prior alt", "ORIG_A"===$alt($id));
		$ok("S12 media_restore returns complete", ($mr["status"]??"")==="complete" || ($mr["restored"]??false)===true);
		wp_delete_post($id,true);

		echo "FUNC: PASS=$P FAIL=$F\n";
	' 2>/dev/null)"
	echo "$RES" | grep -E "PASS:|FAIL:" | grep -v "PASS=" || true
	fp="$(echo "$RES" | sed -n 's/.*FUNC: PASS=\([0-9]*\) FAIL=\([0-9]*\).*/\1 \2/p')"
	fpp="${fp% *}"; ffp="${fp#* }"
	PASS=$((PASS + ${fpp:-0})); FAIL=$((FAIL + ${ffp:-0}))
fi

echo
echo "Media metadata delta-rollback: PASS=$PASS FAIL=$FAIL"
[ "$FAIL" -eq 0 ]
