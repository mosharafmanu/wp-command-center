#!/usr/bin/env bash
#
# PROGRAM-4.10 — Elementor rollback integrity (whole-document drift-aware delta).
#
# Proves: _elementor_data whole-document restore, sibling-widget preservation (refuse-on-drift),
# same-field drift skip/report, out-of-order no-resurrection, repeated-rollback safety, legacy
# option record restore, partial/conflict NOT clean success, malformed-JSON handled honestly,
# missing record reported honestly. The whole document is treated ATOMICALLY (no widget-level
# decomposition).
#
# Backend-only: exercises WPCommandCenter\Operations\ElementorRuntimeManager directly. Requires
# wp-cli + Elementor active; self-skips functional section otherwise.

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

SRC="$PLUGIN_DIR/includes/Operations/ElementorRuntimeManager.php"
ACC="$PLUGIN_DIR/includes/Rollback/ElementorDataAccessor.php"

echo "PROGRAM-4.10 — Elementor rollback integrity"

echo
echo "== 1. Source: whole-document delta + drift guard + honest reporting =="
has  "edit captures whole-doc via core"        "RollbackDelta::capture( \$acc, \$id, [ 'data' ]" "$SRC"
has  "records in PostMetaRollbackStore"        "PostMetaRollbackStore( self::RB_PREFIX )" "$SRC"
has  "rollback drift-aware via core"           "RollbackDelta::restore( new ElementorDataAccessor" "$SRC"
has  "drift conflict honest (error)"           "'code' => 'wpcc_rollback_conflict'" "$SRC"
has  "legacy option path retained"             "get_option( 'wpcc_elementor_rollbacks', [] )" "$SRC"
has  "complete marks applied only"             "'complete' === \$o['status']" "$SRC"
has  "accessor whole-doc normalized compare"   "private function normalize" "$ACC"
has  "accessor key is _elementor_data"         "private const META_KEY = '_elementor_data';" "$ACC"
has  "accessor wp_slash on restore"            "wp_slash( (string) \$value )" "$ACC"

echo
echo "== 2. Functional: doc fidelity, sibling preserve, drift, out-of-order, legacy, malformed =="
if ! command -v wp >/dev/null 2>&1 || [ "$(wpe 'echo defined("ELEMENTOR_VERSION")?1:0;')" != "1" ]; then
	echo "  SKIP: wp-cli or Elementor not available — static checks only."
else
	RES="$(wpe '
		$a=get_users(["role"=>"administrator","number"=>1]); wp_set_current_user($a?$a[0]->ID:1);
		$M="WPCommandCenter\\Operations\\ElementorRuntimeManager"; $mgr=new $M(); $out=[];
		$doc=function($h="OLD",$b="BTN"){ return [["id"=>"sec1","elType"=>"section","elements"=>[["id"=>"col1","elType"=>"column","elements"=>[
			["id"=>"h1","elType"=>"widget","widgetType"=>"heading","settings"=>["title"=>$h]],
			["id"=>"b1","elType"=>"widget","widgetType"=>"button","settings"=>["text"=>$b,"link"=>["url"=>"http://x"]]],
		]]]]]; };
		$pid=wp_insert_post(["post_title"=>"el","post_status"=>"publish","post_type"=>"page"]);
		update_post_meta($pid,"_elementor_edit_mode","builder");
		$seed=function($h="OLD",$b="BTN") use($pid,$doc){ update_post_meta($pid,"_elementor_data",wp_slash(wp_json_encode($doc($h,$b)))); };
		$h1=function() use($pid){ $d=json_decode(get_post_meta($pid,"_elementor_data",true),true); return is_array($d)?($d[0]["elements"][0]["elements"][0]["settings"]["title"]??""):"__CORRUPT__"; };
		$b1=function() use($pid){ $d=json_decode(get_post_meta($pid,"_elementor_data",true),true); return is_array($d)?($d[0]["elements"][0]["elements"][1]["settings"]["text"]??""):"__CORRUPT__"; };
		$setb1=function($t) use($pid){ $d=json_decode(get_post_meta($pid,"_elementor_data",true),true); $d[0]["elements"][0]["elements"][1]["settings"]["text"]=$t; update_post_meta($pid,"_elementor_data",wp_slash(wp_json_encode($d))); };
		$ut=function($t) use($mgr,$pid){ return (string)($mgr->run(["action"=>"elementor_update_text","page_id"=>$pid,"widget_id"=>"h1","text"=>$t])["rollback_id"]??""); };
		$rb=function($rid) use($mgr){ return $mgr->rollback(["rollback_id"=>$rid]); };

		// E1 whole-document value fidelity
		$seed("OLD","BTN"); $r=$ut("NEW");
		$out["e1_applied"]=$h1(); $out["e1_rid"]=$r;
		$x=$rb($r); $out["e1_status"]=$x["status"]??""; $out["e1_h1"]=$h1();

		// E2 rollback_id surfaced (already e1_rid) + reversible:true on complete
		$out["e2_reversible"]=isset($x["reversible"])?($x["reversible"]?1:0):-1;

		// E_sibling — edit h1, externally edit DIFFERENT widget b1, rollback h1 → refuse (drift),
		// b1''s external change preserved (no whole-doc clobber).
		$seed("S_OLD","S_BTN"); $r=$ut("S_NEW"); $setb1("B_EXTERNAL");
		$x=$rb($r);
		$out["es_status"]=$x["status"]??""; $out["es_error"]=!empty($x["error"])?1:0;
		$out["es_b1"]=$b1();                               // B_EXTERNAL preserved

		// E_drift — same widget layered; rollback first → conflict, not clobbered
		$seed("D0","BTN"); $rA=$ut("DA"); $rB=$ut("DB");
		$x=$rb($rA);
		$out["ed_status"]=$x["status"]??""; $out["ed_code"]=$x["code"]??""; $out["ed_h1"]=$h1(); // conflict / DB

		// E_ooo — rollback B then A → no resurrection
		$x=$rb($rB); $out["eo_b"]=$x["status"]??""; $out["eo_after_b"]=$h1();   // complete / DA
		$x=$rb($rA); $out["eo_a"]=$x["status"]??""; $out["eo_after_a"]=$h1();   // complete / D0

		// E_idemp — repeat rollback guarded
		$seed("R0","BTN"); $r=$ut("R1"); $x1=$rb($r); $x2=$rb($r);
		$out["ei_first"]=$x1["status"]??""; $out["ei_second_code"]=$x2["code"]??"";

		// E_legacy — pre-P4.10 option record restores whole prior doc via legacy path
		$seed("CUR","BTN");
		$legjson=wp_json_encode($doc("LEG","LEGBTN"));
		$lid=wp_generate_uuid4();
		$opt=get_option("wpcc_elementor_rollbacks",[]);
		$opt[]=["id"=>$lid,"entity_id"=>$pid,"action"=>"elementor_update_text","before_state"=>["data"=>$legjson],"rollback_applied"=>false,"created_at"=>time()];
		update_option("wpcc_elementor_rollbacks",$opt);
		$x=$rb($lid);
		$out["el_path"]=$x["path"]??""; $out["el_h1"]=$h1();                    // legacy / LEG

		// E_malformed — corrupt live JSON after edit → rollback refuses (drift), no fatal, no clobber
		$seed("M0","BTN"); $r=$ut("M1");
		update_post_meta($pid,"_elementor_data",wp_slash("NOTJSON{"));
		$x=$rb($r);
		$out["em_status"]=$x["status"]??""; $out["em_error"]=!empty($x["error"])?1:0;
		$out["em_live"]=get_post_meta($pid,"_elementor_data",true);            // still NOTJSON{ (not clobbered)

		// E_reorder — structural drift: widgets reordered after edit → rollback refuses (order-
		// sensitive), reorder preserved (not clobbered).
		$seed("RO0","RB"); $r=$ut("RO1");
		$d=json_decode(get_post_meta($pid,"_elementor_data",true),true);
		$d[0]["elements"][0]["elements"]=array_reverse($d[0]["elements"][0]["elements"]);
		update_post_meta($pid,"_elementor_data",wp_slash(wp_json_encode($d)));
		$x=$rb($r);
		$out["er_status"]=$x["status"]??"";
		$d=json_decode(get_post_meta($pid,"_elementor_data",true),true);
		$out["er_first_widget"]=$d[0]["elements"][0]["elements"][0]["id"]??"";   // b1 (reorder kept)

		// E_missing — bogus id honest not-found
		$x=$rb(wp_generate_uuid4()); $out["en_code"]=$x["code"]??"";

		wp_delete_post($pid,true); delete_option("wpcc_elementor_rollbacks");
		echo wp_json_encode($out);
	')"
	gj() { printf '%s' "$RES" | php -r '$d=json_decode(stream_get_contents(STDIN),true); $v=$d["'"$1"'"]??""; echo is_scalar($v)?$v:json_encode($v);' 2>/dev/null; }
	# E1 doc fidelity
	assert_eq "E1 update applied (NEW)"              "NEW" "$(gj e1_applied)"
	[ -n "$(gj e1_rid)" ] && pass "E1 rollback_id surfaced" || fail "E1 rollback_id surfaced"
	assert_eq "E1 rollback complete"                 "complete" "$(gj e1_status)"
	assert_eq "E1 prior document restored (OLD)"     "OLD" "$(gj e1_h1)"
	assert_eq "E2 complete is reversible:true"       "1" "$(gj e2_reversible)"
	# sibling preservation via refuse-on-drift
	assert_eq "Esib rollback refused (conflict)"     "conflict" "$(gj es_status)"
	assert_eq "Esib conflict is error"               "1" "$(gj es_error)"
	assert_eq "Esib other widget NOT clobbered"      "B_EXTERNAL" "$(gj es_b1)"
	# same-field drift
	assert_eq "Edrift rollback A conflict"           "conflict" "$(gj ed_status)"
	assert_eq "Edrift conflict code"                 "wpcc_rollback_conflict" "$(gj ed_code)"
	assert_eq "Edrift drifted value NOT clobbered"   "DB" "$(gj ed_h1)"
	# out-of-order
	assert_eq "Eooo rollback B complete"             "complete" "$(gj eo_b)"
	assert_eq "Eooo value back to DA after B"        "DA" "$(gj eo_after_b)"
	assert_eq "Eooo retry A complete"                "complete" "$(gj eo_a)"
	assert_eq "Eooo value back to D0 (no resurrection)" "D0" "$(gj eo_after_a)"
	# idempotency
	assert_eq "Eidemp first complete"                "complete" "$(gj ei_first)"
	assert_eq "Eidemp second guarded"                "wpcc_rollback_already_applied" "$(gj ei_second_code)"
	# legacy
	assert_eq "Elegacy uses legacy path"             "legacy" "$(gj el_path)"
	assert_eq "Elegacy restores whole prior doc"     "LEG" "$(gj el_h1)"
	# malformed honest
	assert_eq "Emalformed → conflict (no fatal)"     "conflict" "$(gj em_status)"
	assert_eq "Emalformed is error"                  "1" "$(gj em_error)"
	assert_eq "Emalformed live not clobbered"        "NOTJSON{" "$(gj em_live)"
	# reorder = order-sensitive structural drift
	assert_eq "Ereorder rollback refused (conflict)" "conflict" "$(gj er_status)"
	assert_eq "Ereorder structural change preserved" "b1" "$(gj er_first_widget)"
	# missing honest
	assert_eq "Emissing → not found"                 "wpcc_rollback_not_found" "$(gj en_code)"
fi

echo
echo "Elementor rollback integrity: PASS=$PASS FAIL=$FAIL"
[ "$FAIL" -eq 0 ]
