#!/usr/bin/env bash
#
# PROGRAM-4.9 — ACF rollback integrity (value drift-delta + definition drift-guard + honesty).
#
# Proves: flat-value restore, empty-prior clear, empty-but-existing restore, sibling field
# preservation, same-field drift skip/report, out-of-order no-resurrection, repeated-rollback
# safety, legacy option record restore, partial/conflict NOT clean success, nested values
# treated ATOMICALLY (whole restore + drift refuse), ACF _field key-reference preserved,
# json_import honest-unsupported, definition fingerprint drift-guard (refuse) + clean restore,
# key-vs-name selector resolution.
#
# Backend-only: exercises WPCommandCenter\Operations\ACFRuntimeManager directly. Requires
# wp-cli + ACF active; self-skips functional section when ACF is inactive.

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
wpe() { wp --path="$WP_ROOT" eval "$1" 2>/dev/null; }

SRC="$PLUGIN_DIR/includes/Operations/ACFRuntimeManager.php"
ACC="$PLUGIN_DIR/includes/Rollback/AcfValueAccessor.php"

echo "PROGRAM-4.9 — ACF rollback integrity"

echo
echo "== 1. Source: value delta + drift guard + honest reporting =="
has  "value_update uses RollbackDelta capture"     "RollbackDelta::capture( \$acc, \$post_id, [ 'value' ]" "$SRC"
has  "value records in PostMetaRollbackStore"      "PostMetaRollbackStore( self::VALUE_RB_PREFIX )" "$SRC"
has  "value rollback drift-aware via core"         "RollbackDelta::restore( new AcfValueAccessor" "$SRC"
has  "json_import honest unsupported"              "rollback_unsupported(" "$SRC"
has  "definition fingerprint drift guard"          "definition_fingerprint( (string) \$eid, \$act )" "$SRC"
has  "guard refuses on drift (conflict)"           "rollback_conflict(" "$SRC"
has  "fingerprint drops volatile keys"             "'ID', 'id', 'menu_order', 'modified', '_valid'" "$SRC"
has  "guard is new-records-only"                   "isset( \$before['__after_fp'] )" "$SRC"
has  "fp marker never fed to acf_update"           "unset( \$before['__after_fp'] )" "$SRC"
has  "accessor whole-value normalized compare"     "wp_json_encode( \$current ) === wp_json_encode( \$after )" "$ACC"
has  "accessor existence via field name"           "metadata_exists( 'post', (int) \$entity_id, \$this->name )" "$ACC"

echo
echo "== 2. Functional: value fidelity, drift, out-of-order, nested-atomic, legacy, guard =="
if ! command -v wp >/dev/null 2>&1 || [ "$(wpe 'echo function_exists("acf_get_field_groups")?1:0;')" != "1" ]; then
	echo "  SKIP: wp-cli or ACF not available — static checks only."
else
	RES="$(wpe '
		$a=get_users(["role"=>"administrator","number"=>1]); wp_set_current_user($a?$a[0]->ID:1);
		$M="WPCommandCenter\\Operations\\ACFRuntimeManager"; $mgr=new $M(); $out=[];
		// real field group + two text fields
		$gk="group_p49test";
		acf_update_field_group(["key"=>$gk,"title"=>"P49 Test","fields"=>[],"location"=>[[["param"=>"post_type","operator"=>"==","value"=>"post"]]],"active"=>true]);
		acf_update_field(["key"=>"field_p49text","label"=>"P49 Text","name"=>"p49_text","type"=>"text","parent"=>$gk]);
		acf_update_field(["key"=>"field_p49other","label"=>"P49 Other","name"=>"p49_other","type"=>"text","parent"=>$gk]);
		// post_object field: default return_format is the formatted WP_Post object — exercises the
		// raw-vs-formatted round-trip (capture/after/restore must use the raw stored id).
		acf_update_field(["key"=>"field_p49rel","label"=>"P49 Rel","name"=>"p49_rel","type"=>"post_object","parent"=>$gk]);
		$pid=wp_insert_post(["post_title"=>"p49","post_status"=>"draft","post_type"=>"post"]);
		$tgt1=wp_insert_post(["post_title"=>"tgt1","post_status"=>"publish","post_type"=>"post"]);
		$tgt2=wp_insert_post(["post_title"=>"tgt2","post_status"=>"publish","post_type"=>"post"]);
		$vu=function($sel,$val) use($mgr,$pid){ return (string)($mgr->run(["action"=>"acf_value_update","post_id"=>$pid,"field_key"=>$sel,"value"=>$val])["rollback_id"]??""); };
		$rb=function($rid) use($mgr){ return $mgr->rollback(["rollback_id"=>$rid]); };
		$gf=function($sel) use($pid){ return get_field($sel,$pid); };

		// A1 value-prior fidelity (name selector)
		update_field("p49_text","OLD",$pid);
		$r=$vu("p49_text","NEW"); $out["a1_applied"]=$gf("p49_text"); $x=$rb($r);
		$out["a1_status"]=$x["status"]??""; $out["a1_val"]=$gf("p49_text");

		// A1b key selector resolves to name for existence
		update_field("field_p49text","K_OLD",$pid);
		$r=$vu("field_p49text","K_NEW"); $rb($r); $out["a1b_val"]=$gf("p49_text");

		// A2 empty-prior restore: field absent before → rollback clears it
		update_field("p49_text",null,$pid);
		$out["a2_pre_exists"]=metadata_exists("post",$pid,"p49_text")?1:0;   // 0
		$r=$vu("p49_text","SET"); $x=$rb($r);
		$out["a2_status"]=$x["status"]??"";
		$out["a2_cleared"]=metadata_exists("post",$pid,"p49_text")?0:1;       // 1 (cleared)

		// A3 empty-but-existing restore
		update_field("p49_text","",$pid);
		$out["a3_pre_exists"]=metadata_exists("post",$pid,"p49_text")?1:0;
		$r=$vu("p49_text","Z"); $rb($r);
		$out["a3_val"]=(string)$gf("p49_text");                               // ""

		// A4 sibling preservation
		update_field("p49_text","T0",$pid); update_field("p49_other","SIB",$pid);
		$r=$vu("p49_text","T1"); $rb($r);
		$out["a4_self"]=$gf("p49_text"); $out["a4_sibling"]=$gf("p49_other"); // T0 / SIB

		// A5 same-field drift → conflict, not clobbered
		update_field("p49_text","D0",$pid);
		$rA=$vu("p49_text","DA"); $rB=$vu("p49_text","DB");
		$x=$rb($rA);
		$out["a5_status"]=$x["status"]??""; $out["a5_error"]=!empty($x["error"])?1:0; $out["a5_code"]=$x["code"]??"";
		$out["a5_val"]=$gf("p49_text");                                       // DB (preserved)

		// A6 out-of-order: rollback B then A → no resurrection (back to D0)
		$x=$rb($rB); $out["a6_b"]=$x["status"]??""; $out["a6_after_b"]=$gf("p49_text"); // complete / DA
		$x=$rb($rA); $out["a6_a"]=$x["status"]??""; $out["a6_after_a"]=$gf("p49_text"); // complete / D0

		// A7 repeated rollback safe
		update_field("p49_text","R0",$pid); $r=$vu("p49_text","R1");
		$x1=$rb($r); $x2=$rb($r);
		$out["a7_first"]=$x1["status"]??""; $out["a7_second_code"]=$x2["code"]??""; // complete / already_applied

		// A8 legacy option record restore
		update_field("p49_text","CUR",$pid);
		$lid=wp_generate_uuid4();
		$opt=get_option("wpcc_acf_rollbacks",[]);
		$opt[]=["id"=>$lid,"entity_id"=>$pid."_p49_text","action"=>"value_update","before_state"=>["post_id"=>$pid,"key"=>"p49_text","value"=>"LEGACY"],"rollback_applied"=>false,"created_at"=>time()];
		update_option("wpcc_acf_rollbacks",$opt);
		$rb($lid); $out["a8_val"]=$gf("p49_text");                            // LEGACY

		// A9 json_import honest unsupported
		$jid=wp_generate_uuid4();
		$opt=get_option("wpcc_acf_rollbacks",[]);
		$opt[]=["id"=>$jid,"entity_id"=>"group_x","action"=>"json_import","before_state"=>["key"=>"group_x"],"rollback_applied"=>false,"created_at"=>time()];
		update_option("wpcc_acf_rollbacks",$opt);
		$x=$rb($jid); $out["a9_code"]=$x["code"]??""; $out["a9_reversible"]=isset($x["reversible"])?($x["reversible"]?1:0):-1;

		// A10 nested value treated atomically (array) + drift refuse
		update_field("p49_text",["x"=>1],$pid);
		$rN=$vu("p49_text",["a"=>1,"b"=>2]);
		$out["a10_applied"]=wp_json_encode($gf("p49_text"));
		$vu("p49_text",["a"=>1,"b"=>2,"c"=>3]);  // drift the whole value
		$x=$rb($rN);
		$out["a10_status"]=$x["status"]??""; $out["a10_after"]=wp_json_encode($gf("p49_text")); // conflict / drifted value kept

		// A11 ACF _field key-reference preserved after a complete restore
		update_field("p49_text","REF0",$pid); $r=$vu("p49_text","REF1"); $rb($r);
		$out["a11_ref"]=metadata_exists("post",$pid,"_p49_text")?1:0;          // 1

		// A12 definition fingerprint guard: drift → refuse
		$mgr->run(["action"=>"acf_group_update","group_id"=>$gk,"title"=>"Title A"]);
		$opt=get_option("wpcc_acf_rollbacks",[]); $gu_rid="";
		foreach(array_reverse($opt) as $rr){ if(($rr["action"]??"")==="group_update"){ $gu_rid=$rr["id"]; break; } }
		$g=acf_get_field_group($gk); $g["title"]="Title B EXTERNAL"; acf_update_field_group($g); // external drift
		$x=$rb($gu_rid);
		$out["a12_drift_code"]=$x["code"]??""; $out["a12_drift_err"]=!empty($x["error"])?1:0;
		$out["a12_title_kept"]=acf_get_field_group($gk)["title"];             // Title B EXTERNAL (not clobbered)

		// A12b definition guard: clean (no external edit) → restore proceeds
		$mgr->run(["action"=>"acf_group_update","group_id"=>$gk,"title"=>"Clean Update"]);
		$opt=get_option("wpcc_acf_rollbacks",[]); $gu2="";
		foreach(array_reverse($opt) as $rr){ if(($rr["action"]??"")==="group_update"){ $gu2=$rr["id"]; break; } }
		$x=$rb($gu2);
		$out["a12b_err"]=!empty($x["error"])?1:0;                             // 0 (clean restore)
		$out["a12b_title"]=acf_get_field_group($gk)["title"];                // restored prior (Title B EXTERNAL)

		// A13 post_object (formatted return) raw round-trip: prior id restored exactly
		update_field("p49_rel",$tgt1,$pid);
		$r=$vu("p49_rel",$tgt2);
		$out["a13_applied"]=(string)get_field("p49_rel",$pid,false);          // tgt2 id (raw)
		$x=$rb($r);
		$out["a13_status"]=$x["status"]??""; $out["a13_restored"]=(string)get_field("p49_rel",$pid,false); // tgt1 id
		$out["a13_tgt1"]=(string)$tgt1;

		// cleanup
		acf_delete_field("field_p49text"); acf_delete_field("field_p49other"); acf_delete_field("field_p49rel"); acf_delete_field_group($gk);
		wp_delete_post($pid,true); wp_delete_post($tgt1,true); wp_delete_post($tgt2,true);
		delete_option("wpcc_acf_rollbacks");
		echo wp_json_encode($out);
	')"
	gj() { printf '%s' "$RES" | php -r '$d=json_decode(stream_get_contents(STDIN),true); $v=$d["'"$1"'"]??""; echo is_scalar($v)?$v:json_encode($v);' 2>/dev/null; }
	# A1 value fidelity
	assert_eq "A1 value applied (NEW)"               "NEW" "$(gj a1_applied)"
	assert_eq "A1 rollback complete"                 "complete" "$(gj a1_status)"
	assert_eq "A1 prior value restored (OLD)"        "OLD" "$(gj a1_val)"
	assert_eq "A1b key selector restores to name"    "K_OLD" "$(gj a1b_val)"
	# A2 empty-prior clear
	assert_eq "A2 pre-state absent"                  "0" "$(gj a2_pre_exists)"
	assert_eq "A2 rollback complete"                 "complete" "$(gj a2_status)"
	assert_eq "A2 absent-prior cleared on rollback"  "1" "$(gj a2_cleared)"
	# A3 empty-but-existing
	assert_eq "A3 empty-prior existed"               "1" "$(gj a3_pre_exists)"
	assert_eq "A3 empty value restored"              "" "$(gj a3_val)"
	# A4 sibling
	assert_eq "A4 self restored"                     "T0" "$(gj a4_self)"
	assert_eq "A4 sibling field preserved"           "SIB" "$(gj a4_sibling)"
	# A5 drift
	assert_eq "A5 rollback A conflict"               "conflict" "$(gj a5_status)"
	assert_eq "A5 conflict is error"                 "1" "$(gj a5_error)"
	assert_eq "A5 conflict code"                     "wpcc_rollback_conflict" "$(gj a5_code)"
	assert_eq "A5 drifted value NOT clobbered"       "DB" "$(gj a5_val)"
	# A6 out-of-order
	assert_eq "A6 rollback B complete"               "complete" "$(gj a6_b)"
	assert_eq "A6 value back to DA after B"          "DA" "$(gj a6_after_b)"
	assert_eq "A6 retry A complete"                  "complete" "$(gj a6_a)"
	assert_eq "A6 value back to D0 (no resurrection)" "D0" "$(gj a6_after_a)"
	# A7 idempotency
	assert_eq "A7 first complete"                    "complete" "$(gj a7_first)"
	assert_eq "A7 second guarded"                    "wpcc_rollback_already_applied" "$(gj a7_second_code)"
	# A8 legacy
	assert_eq "A8 legacy record restores value"      "LEGACY" "$(gj a8_val)"
	# A9 json_import honesty
	assert_eq "A9 json_import unsupported code"      "wpcc_rollback_unsupported" "$(gj a9_code)"
	assert_eq "A9 json_import reversible:false"      "0" "$(gj a9_reversible)"
	# A10 nested atomic + drift
	assert_eq "A10 nested applied (atomic array)"    '{"a":1,"b":2}' "$(gj a10_applied)"
	assert_eq "A10 nested drift → conflict"          "conflict" "$(gj a10_status)"
	assert_eq "A10 drifted nested value kept"        '{"a":1,"b":2,"c":3}' "$(gj a10_after)"
	# A11 ref preserved
	assert_eq "A11 ACF _field reference preserved"   "1" "$(gj a11_ref)"
	# A12 definition guard
	assert_eq "A12 def drift refused (conflict)"     "wpcc_rollback_conflict" "$(gj a12_drift_code)"
	assert_eq "A12 def drift is error"               "1" "$(gj a12_drift_err)"
	assert_eq "A12 external def edit NOT clobbered"  "Title B EXTERNAL" "$(gj a12_title_kept)"
	assert_eq "A12b clean def rollback restores"     "0" "$(gj a12b_err)"
	assert_eq "A12b clean rollback restored prior title" "Title B EXTERNAL" "$(gj a12b_title)"
	# A13 post_object formatted-return raw round-trip (capture/restore use raw stored id)
	[ -n "$(gj a13_applied)" ] && [ "$(gj a13_applied)" != "$(gj a13_tgt1)" ] && pass "A13 post_object update moved state (raw id)" || fail "A13 post_object update moved state"
	assert_eq "A13 post_object rollback complete"    "complete" "$(gj a13_status)"
	assert_eq "A13 prior post_object id restored"    "$(gj a13_tgt1)" "$(gj a13_restored)"
fi

echo
echo "ACF rollback integrity: PASS=$PASS FAIL=$FAIL"
[ "$FAIL" -eq 0 ]
