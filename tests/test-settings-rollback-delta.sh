#!/usr/bin/env bash
#
# PROGRAM-4 / P4.1 — Field-scoped, drift-aware Settings delta rollback.
#
# Asserts the Settings rollback record is a field-scoped DELTA (version 2, `fields`
# map of only the options the call touched, each with post-write `after` + prior
# value/existence) and that restore is field-scoped, drift-aware, existence-faithful,
# idempotent, legacy-compatible, and history-honest — and that the prior state is
# captured BEFORE the write (the pre-P4.1 capture-after-write no-op defect is fixed).
#
# Functional section drives SettingsRuntimeManager directly via wp eval (no token).
# Static section runs regardless.

set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_ROOT="$(cd "$PLUGIN_DIR/../../.." && pwd)"
cd "$PLUGIN_DIR"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
has()  { grep -qF -- "$2" "$3" && pass "$1" || fail "$1 (missing '$2')"; }

SRC="$PLUGIN_DIR/includes/Operations/SettingsRuntimeManager.php"
OPA="$PLUGIN_DIR/includes/Rollback/OptionAccessor.php"

echo "PROGRAM-4 / P4.1 — Field-scoped, drift-aware Settings delta rollback"

echo
echo "== 1. Source: OptionAccessor + capture-before-write + v2 delta =="
has  "OptionAccessor implements FieldAccessor"   "class OptionAccessor implements FieldAccessor" "$OPA"
has  "OptionAccessor existence via sentinel"     "get_option( \$key, self::ABSENT ) !== self::ABSENT" "$OPA"
has  "OptionAccessor key_set = update_option"    "update_option( \$key, \$value )" "$OPA"
has  "OptionAccessor key_delete = delete_option" "delete_option( \$key )" "$OPA"
has  "Settings captures prior via core"          "RollbackDelta::capture(new OptionAccessor(),0,\$touched)" "$SRC"
has  "Settings restores via core"                "RollbackDelta::restore(new OptionAccessor(),0,\$rec['fields'])" "$SRC"
has  "capture happens BEFORE the write"          "\$prior=\$is_mutation?RollbackDelta::capture" "$SRC"
has  "store writes version 2 delta record"       "'version'=>2,'action'=>\$action,'fields'=>\$fields" "$SRC"
has  "touched-option map present"                "private function option_field_map" "$SRC"
has  "legacy before_state branch retained"       "(\$rec['before_state']??[])" "$SRC"
has  "conflict error code"                        "wpcc_rollback_conflict" "$SRC"
has  "partial error code"                         "wpcc_rollback_partial" "$SRC"

echo
echo "== 2. Functional: defect fix, fidelity, layering, drift, out-of-order, legacy, idempotency =="
if ! command -v wp >/dev/null 2>&1; then
	echo "  SKIP: wp-cli not available — static checks only."
else
	RES="$(wp --path="$WP_ROOT" eval '
		$a=get_users(["role"=>"administrator","number"=>1]); wp_set_current_user($a?$a[0]->ID:1);
		$M="\WPCommandCenter\Operations\SettingsRuntimeManager";
		$mgr=new $M();
		$P=0;$F=0;
		$ok=function($d,$c)use(&$P,&$F){if($c){$P++;echo "  PASS: $d\n";}else{$F++;echo "  FAIL: $d\n";}};

		// snapshot real options to restore at the end (no dev-site pollution)
		$save=["blogname"=>get_option("blogname"),"blogdescription"=>get_option("blogdescription"),
		       "posts_per_page"=>get_option("posts_per_page"),"blog_public"=>get_option("blog_public"),
		       "rb"=>get_option("wpcc_settings_rollbacks")];
		update_option("wpcc_settings_rollbacks",[]);
		$gen=function($args)use($mgr){return $mgr->run(array_merge(["action"=>"settings_general_update"],$args),[]);};

		/* S0 — capture-before-write + value-prior restore (the defect fix) */
		update_option("blogname","ORIG_T");
		$r=$gen(["site_title"=>"NEW_T"]);
		$ok("S0 update applied (blogname=NEW_T)", "NEW_T"===get_option("blogname"));
		$ok("S0 rollback_id surfaced", !empty($r["rollback_id"]));
		$rb=$mgr->rollback(["rollback_id"=>$r["rollback_id"]]);
		$ok("S0 rollback complete", ($rb["status"]??"")==="complete");
		$ok("S0 prior restored (NOT post-write) — defect fixed", "ORIG_T"===get_option("blogname"));

		/* S1 — empty-prior fidelity: absent option set then deleted on rollback.
		   Uses blogdescription (a normal, non-special option) made absent first. */
		delete_option("blogdescription");
		$r=$gen(["tagline"=>"FROM_ABSENT"]);
		$ok("S1 absent option created", "FROM_ABSENT"===get_option("blogdescription"));
		$mgr->rollback(["rollback_id"=>$r["rollback_id"]]);
		$ok("S1 absent-prior deleted on rollback", get_option("blogdescription","__A__")==="__A__");

		/* S2 — empty-but-existing prior restored as empty (not deleted) */
		update_option("blogdescription","");
		$r=$gen(["tagline"=>"HAS_VALUE"]);
		$mgr->rollback(["rollback_id"=>$r["rollback_id"]]);
		$ok("S2 empty-but-existing restored as empty row", ""===get_option("blogdescription") && get_option("blogdescription","__A__")!=="__A__");

		/* S3 — disjoint sibling preservation + drift (partial) */
		update_option("blogname","ORIG_T"); update_option("blogdescription","ORIG_D");
		$A=$gen(["site_title"=>"A_T","tagline"=>"A_D"]);      // touches blogname + blogdescription
		$B=$gen(["tagline"=>"B_D"]);                           // later change to blogdescription (sibling)
		$rb=$mgr->rollback(["rollback_id"=>$A["rollback_id"]]);
		$ok("S3 rollback A is partial", ($rb["status"]??"")==="partial");
		$ok("S3 blogname restored to ORIG_T", "ORIG_T"===get_option("blogname"));
		$ok("S3 sibling blogdescription (B) survives", "B_D"===get_option("blogdescription"));
		$ok("S3 blogdescription reported skipped", in_array("blogdescription",$rb["skipped_fields"]??[],true));

		/* S4 — same-field drift conflict (no clobber) */
		update_option("blogname","ORIG_T");
		$A=$gen(["site_title"=>"A_T"]);
		$Bz=$gen(["site_title"=>"B_T"]);
		$rb=$mgr->rollback(["rollback_id"=>$A["rollback_id"]]);
		$ok("S4 rollback A is conflict", ($rb["status"]??"")==="conflict");
		$ok("S4 newer B value NOT clobbered", "B_T"===get_option("blogname"));
		$ok("S4 conflict not a clean success", empty($rb["restored"]) || $rb["restored"]===false);

		/* S5 — out-of-order rollback, no resurrection */
		update_option("blogname","ORIG_T");
		$A=$gen(["site_title"=>"A_T"]);
		$Bz=$gen(["site_title"=>"B_T"]);
		$rbB=$mgr->rollback(["rollback_id"=>$Bz["rollback_id"]]);
		$ok("S5 rollback B complete", ($rbB["status"]??"")==="complete");
		$ok("S5 blogname back to A_T after B rollback", "A_T"===get_option("blogname"));
		$rbA=$mgr->rollback(["rollback_id"=>$A["rollback_id"]]);
		$ok("S5 retry A complete", ($rbA["status"]??"")==="complete");
		$ok("S5 blogname back to ORIG_T (no resurrection)", "ORIG_T"===get_option("blogname"));

		/* S6 — repeated rollback guarded (idempotent) */
		update_option("blogname","ORIG_T");
		$r=$gen(["site_title"=>"X_T"]);
		$mgr->rollback(["rollback_id"=>$r["rollback_id"]]);
		$again=$mgr->rollback(["rollback_id"=>$r["rollback_id"]]);
		$ok("S6 second rollback guarded", ($again["code"]??"")==="wpcc_rb_done");

		/* S7 — cross-action field-scoping (a general rollback must not touch reading options) */
		update_option("blogname","ORIG_T");
		$g=$gen(["site_title"=>"G_T"]);
		$mgr->run(["action"=>"settings_reading_update","search_visibility"=>0],[]);  // sibling action → blog_public
		$sib=get_option("blog_public");                                              // whatever reading_update left
		$mgr->rollback(["rollback_id"=>$g["rollback_id"]]);
		$ok("S7 general rollback restored blogname", "ORIG_T"===get_option("blogname"));
		$ok("S7 reading sibling (blog_public) untouched by general rollback", get_option("blog_public")===$sib);

		/* S8 — legacy before_state record still restores */
		update_option("blogname","CHANGED_BY_LEGACY_TEST");
		$rbs=get_option("wpcc_settings_rollbacks",[]);
		$rbs[]=["id"=>"legacy-p41-1","action"=>"settings_general_update","before_state"=>["blogname"=>"LEGACY_ORIG"],"rollback_applied"=>false,"created_at"=>time()];
		update_option("wpcc_settings_rollbacks",$rbs);
		$lr=$mgr->rollback(["rollback_id"=>"legacy-p41-1"]);
		$ok("S8 legacy record uses legacy path", ($lr["path"]??"")==="legacy");
		$ok("S8 legacy full restore", "LEGACY_ORIG"===get_option("blogname"));

		// restore real options
		foreach(["blogname","blogdescription","posts_per_page","blog_public"] as $k){ if(false!==$save[$k]) update_option($k,$save[$k]); }
		update_option("wpcc_settings_rollbacks", false!==$save["rb"]?$save["rb"]:[]);

		echo "FUNC: PASS=$P FAIL=$F\n";
	' 2>/dev/null)"
	echo "$RES" | grep -E "PASS:|FAIL:" | grep -v "PASS=" || true
	fp="$(echo "$RES" | sed -n 's/.*FUNC: PASS=\([0-9]*\) FAIL=\([0-9]*\).*/\1 \2/p')"
	fpp="${fp% *}"; ffp="${fp#* }"
	PASS=$((PASS + ${fpp:-0})); FAIL=$((FAIL + ${ffp:-0}))
fi

echo
echo "Settings delta-rollback: PASS=$PASS FAIL=$FAIL"
[ "$FAIL" -eq 0 ]
