#!/usr/bin/env bash
#
# PROGRAM-4 / P4.6 (F-1) — Field-scoped, drift-aware WooCommerce product delta rollback.
#
# Asserts product_update rollback is a field-scoped DELTA (version 2, `fields` map — only
# touched fields, each with post-write `after` + prior value) driven by the RollbackDelta
# core + WooProductAccessor (WC public CRUD only), and that restore is field-scoped,
# drift-aware, idempotent, legacy-compatible, and history-honest. Replaces the full
# 16-field snapshot/restore that clobbered siblings on layered edits.
#
# Backend-only: production files touched are WooProductAccessor.php +
# WooCommerceRuntimeManager.php. Requires WooCommerce active + wp-cli for the functional
# section; static checks run regardless.

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

SRC="$PLUGIN_DIR/includes/Operations/WooCommerceRuntimeManager.php"
ACC="$PLUGIN_DIR/includes/Rollback/WooProductAccessor.php"
RBD="$PLUGIN_DIR/includes/Rollback/RollbackDelta.php"

echo "PROGRAM-4 / P4.6 (F-1) — Field-scoped, drift-aware Woo product delta rollback"

echo
echo "== 1. Source: delta record + field-scoped drift-aware restore =="
has  "product_update captures touched fields"     "RollbackDelta::capture( \$accessor, \$id, \$touched )" "$SRC"
has  "touched-field derivation present"           "function product_touched_fields" "$SRC"
has  "store builds v2 delta record"               "RollbackDelta::build_record" "$SRC"
has  "delta persists to shared woo option"        "OptionListRollbackStore( 'wpcc_woo_rollbacks', 200 )" "$SRC"
has  "rollback branches v2 product_update first"  "function rollback_product_delta" "$SRC"
has  "rollback delegates restore to core"         "RollbackDelta::restore( new WooProductAccessor" "$SRC"
has  "rollback builds result envelope via core"   "RollbackDelta::result(" "$SRC"
has  "only complete is terminal"                  "'complete' === \$o['status']" "$SRC"
has  "legacy before_state restore retained"       "function restore_product" "$SRC"
has  "legacy switch still reads before_state"     "\$before = \$rec['before_state'];" "$SRC"
has  "accessor drift comparator present"          "public function equals" "$ACC"
has  "accessor id-set order-insensitive compare"  "self::ID_SETS" "$ACC"
has  "accessor writes via WC setter + save"       "\$p->{\$setter}( \$value )" "$ACC"
lacks "accessor never writes raw post meta"       "update_post_meta" "$ACC"
lacks "accessor never reads raw post meta"        "get_post_meta" "$ACC"
has  "core drift skips + records conflict"        "'reason' => 'drift'" "$RBD"

echo
echo "== 2. Functional: fidelity, layering, drift, out-of-order, structured, legacy, idempotency =="
if ! command -v wp >/dev/null 2>&1; then
	echo "  SKIP: wp-cli not available — static checks only."
else
	RES="$(wpe '
		$a=get_users(["role"=>"administrator","number"=>1]); wp_set_current_user($a?$a[0]->ID:1);
		$out=[];
		if ( ! class_exists("WooCommerce") || ! function_exists("wc_get_product") ) { $out["skip"]="no_woo"; echo wp_json_encode($out); return; }
		$M="WPCommandCenter\\Operations\\WooCommerceRuntimeManager";
		$mgr=new $M();
		$mk=function($name,$price) { $p=new \WC_Product_Simple(); $p->set_name($name); $p->set_status("publish"); $p->set_regular_price($price); return $p->save(); };
		$up=function($pid,$fields) use($mgr){ $r=$mgr->run(array_merge(["action"=>"product_update","product_id"=>$pid],$fields)); return (string)($r["rollback_id"]??""); };
		$rb=function($rid) use($mgr){ return $mgr->rollback(["rollback_id"=>$rid]); };
		$reg=function($pid){ return (string) wc_get_product($pid)->get_regular_price(); };
		$nm=function($pid){ return (string) wc_get_product($pid)->get_name(); };
		$sale=function($pid){ return (string) wc_get_product($pid)->get_sale_price(); };

		// S1 — clearable-prior fidelity: prior sale_price empty, set then rollback clears.
		// (regular_price 10 so the sale_price 5 is a valid WC sale.)
		$p1=$mk("S1","10");
		$r1=$up($p1,["sale_price"=>"5"]);
		$out["s1_applied"]=($sale($p1)==="5")?1:0;
		$x=$rb($r1);
		$out["s1_status"]=$x["status"]??"";
		$out["s1_sale_cleared"]=($sale($p1)==="")?1:0;

		// S2 — value-prior fidelity: regular 10 -> 20 -> rollback 10.
		$p2=$mk("S2","10");
		$r2=$up($p2,["regular_price"=>"20"]);
		$out["s2_applied"]=$reg($p2);
		$x=$rb($r2);
		$out["s2_status"]=$x["status"]??"";
		$out["s2_reg"]=$reg($p2);

		// S3 — disjoint layered: A=regular_price, B=name; rollback A -> B(name) survives.
		$p3=$mk("ORIG_N","10");
		$rA=$up($p3,["regular_price"=>"20"]); $rB=$up($p3,["name"=>"B_N"]);
		$x=$rb($rA);
		$out["s3_status"]=$x["status"]??"";
		$out["s3_reg"]=$reg($p3);     // 10 restored
		$out["s3_name"]=$nm($p3);     // B_N survives

		// S4 — same-field drift: A,B both regular_price; rollback A -> conflict, B preserved.
		$p4=$mk("S4","10");
		$rA=$up($p4,["regular_price"=>"20"]); $rB=$up($p4,["regular_price"=>"30"]);
		$x=$rb($rA);
		$out["s4_status"]=$x["status"]??"";       // conflict
		$out["s4_error"]=!empty($x["error"])?1:0;
		$out["s4_code"]=$x["code"]??"";
		$out["s4_reg"]=$reg($p4);                 // 30 preserved
		$out["s4_skipped"]=implode(",",$x["skipped_fields"]??[]);

		// S5 — out-of-order: rollback B (complete) then retry A (complete, no resurrection).
		$x=$rb($rB);
		$out["s5_b_status"]=$x["status"]??"";      // complete
		$out["s5_after_b"]=$reg($p4);              // 20 (prior of B)
		$x=$rb($rA);
		$out["s5_a_status"]=$x["status"]??"";      // complete (no longer drifted)
		$out["s5_after_a"]=$reg($p4);              // 10 (no resurrection)

		// S6 — structured set fidelity: category_ids restored order-insensitively.
		$t1=wp_insert_term("WPCC P46 A","product_cat"); $t2=wp_insert_term("WPCC P46 B","product_cat");
		$c1=is_wp_error($t1)?0:(int)$t1["term_id"]; $c2=is_wp_error($t2)?0:(int)$t2["term_id"];
		$p6=$mk("S6","10"); $pp=wc_get_product($p6); $pp->set_category_ids([$c1]); $pp->save();
		$r6=$up($p6,["categories"=>[$c1,$c2]]);
		$applied=wc_get_product($p6)->get_category_ids(); sort($applied);
		$out["s6_applied"]=implode(",",$applied);  // c1,c2
		$x=$rb($r6);
		$out["s6_status"]=$x["status"]??"";        // complete
		$restored=wc_get_product($p6)->get_category_ids();
		$out["s6_restored"]=implode(",",$restored); // c1
		$out["s6_expected"]=(string)$c1;

		// S7 — legacy before_state record still restores via restore_product.
		$p7=$mk("LEG_N","10");
		$lid=wp_generate_uuid4();
		$opt=get_option("wpcc_woo_rollbacks",[]);
		$opt[]=["id"=>$lid,"entity_id"=>$p7,"entity_type"=>"product","action"=>"product_update","before_state"=>["name"=>"LEG_N","regular_price"=>"10"],"rollback_applied"=>false,"created_at"=>time()];
		update_option("wpcc_woo_rollbacks",$opt);
		$pp=wc_get_product($p7); $pp->set_name("CHANGED"); $pp->set_regular_price("99"); $pp->save();
		$x=$rb($lid);
		$out["s7_name"]=$nm($p7);   // LEG_N
		$out["s7_reg"]=$reg($p7);   // 10

		// S8 — idempotency: complete then guarded already_applied.
		$p8=$mk("S8","10");
		$r8=$up($p8,["regular_price"=>"20"]);
		$x1=$rb($r8); $x2=$rb($r8);
		$out["s8_first"]=$x1["status"]??"";
		$out["s8_second_code"]=$x2["code"]??"";

		// S9 — partial: A=name+regular_price, drift regular_price; rollback A partial.
		$p9=$mk("P_ON","10");
		$r9=$up($p9,["name"=>"P_AN","regular_price"=>"20"]);
		$up($p9,["regular_price"=>"33"]);   // drift the price
		$x=$rb($r9);
		$out["s9_status"]=$x["status"]??"";          // partial
		$out["s9_code"]=$x["code"]??"";              // wpcc_rollback_partial
		$out["s9_restored"]=implode(",",$x["restored_fields"]??[]);
		$out["s9_skipped"]=implode(",",$x["skipped_fields"]??[]);
		$out["s9_name"]=$nm($p9);   // P_ON restored
		$out["s9_reg"]=$reg($p9);   // 33 preserved (drift)

		foreach([$p1,$p2,$p3,$p4,$p6,$p7,$p8,$p9] as $d){ $dp=wc_get_product($d); if($dp) $dp->delete(true); }
		if($c1) wp_delete_term($c1,"product_cat"); if($c2) wp_delete_term($c2,"product_cat");
		echo wp_json_encode($out);
	')"
	gj() { printf '%s' "$RES" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["'"$1"'"] ?? "";' 2>/dev/null; }
	if [ "$(gj skip)" = "no_woo" ]; then
		echo "  NOTE: WooCommerce not active — functional path skipped."
	else
		# S1 clearable-prior fidelity
		assert_eq "S1 update applied sale_price"            "1" "$(gj s1_applied)"
		assert_eq "S1 restore complete"                     "complete" "$(gj s1_status)"
		assert_eq "S1 sale_price cleared to prior empty"    "1" "$(gj s1_sale_cleared)"
		# S2 value-prior fidelity
		assert_eq "S2 update applied 20"                    "20" "$(gj s2_applied)"
		assert_eq "S2 restore complete"                     "complete" "$(gj s2_status)"
		assert_eq "S2 prior regular_price restored"         "10" "$(gj s2_reg)"
		# S3 disjoint layered — sibling survives
		assert_eq "S3 rollback A complete"                  "complete" "$(gj s3_status)"
		assert_eq "S3 regular_price restored to 10"         "10" "$(gj s3_reg)"
		assert_eq "S3 sibling name (B) survives"            "B_N" "$(gj s3_name)"
		# S4 same-field drift
		assert_eq "S4 rollback A is conflict"               "conflict" "$(gj s4_status)"
		assert_eq "S4 conflict returned as error"           "1" "$(gj s4_error)"
		assert_eq "S4 conflict code"                        "wpcc_rollback_conflict" "$(gj s4_code)"
		assert_eq "S4 B's price NOT clobbered"              "30" "$(gj s4_reg)"
		assert_eq "S4 price reported skipped"               "regular_price" "$(gj s4_skipped)"
		# S5 out-of-order recovery
		assert_eq "S5 rollback B complete"                  "complete" "$(gj s5_b_status)"
		assert_eq "S5 price back to 20 after B rollback"    "20" "$(gj s5_after_b)"
		assert_eq "S5 retry A now complete"                 "complete" "$(gj s5_a_status)"
		assert_eq "S5 price back to 10 (no resurrection)"   "10" "$(gj s5_after_a)"
		# S6 structured set fidelity
		[ "$(gj s6_applied)" != "$(gj s6_expected)" ] && pass "S6 update added second category" || fail "S6 update added second category"
		assert_eq "S6 categories rollback complete"         "complete" "$(gj s6_status)"
		assert_eq "S6 categories restored to prior set"     "$(gj s6_expected)" "$(gj s6_restored)"
		# S7 legacy
		assert_eq "S7 legacy full restore name"             "LEG_N" "$(gj s7_name)"
		assert_eq "S7 legacy full restore price"            "10" "$(gj s7_reg)"
		# S8 idempotency
		assert_eq "S8 first restore complete"               "complete" "$(gj s8_first)"
		assert_eq "S8 second restore guarded"               "wpcc_rollback_already_applied" "$(gj s8_second_code)"
		# S9 partial
		assert_eq "S9 status partial"                       "partial" "$(gj s9_status)"
		assert_eq "S9 partial code"                         "wpcc_rollback_partial" "$(gj s9_code)"
		assert_eq "S9 restored = name"                      "name" "$(gj s9_restored)"
		assert_eq "S9 skipped = regular_price"              "regular_price" "$(gj s9_skipped)"
		assert_eq "S9 name restored"                        "P_ON" "$(gj s9_name)"
		assert_eq "S9 drifted price preserved"              "33" "$(gj s9_reg)"
	fi
fi

echo
echo "P4.6 Woo product delta-rollback: PASS=$PASS FAIL=$FAIL"
[ "$FAIL" -eq 0 ]
