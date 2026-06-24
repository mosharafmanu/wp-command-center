#!/usr/bin/env bash
#
# PROGRAM-4C.0a — Bulk rollback corruption remediation.
#
# Proves: (B1/B2) status rollback restores post_status and does NOT corrupt post_title;
# (B3) content rollback restores touched fields and preserves siblings; (B4/B5/B6) media/
# woo/acf bulk ops are now reversible; (B7) legacy scalar records restore the correct field;
# (B8) idempotency; (B9) honest envelope + structured unsupported; (B10) rollback_id surfaced.
#
# Backend-only: exercises WPCommandCenter\Operations\BulkRuntimeManager directly (mirrors the
# SEO/Woo delta suites). Requires wp-cli; Woo/ACF branches self-skip when inactive.

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

SRC="$PLUGIN_DIR/includes/Operations/BulkRuntimeManager.php"

echo "PROGRAM-4C.0a — Bulk rollback corruption remediation"

echo
echo "== 1. Source: action-dispatched, field-scoped, honest restore =="
has  "store_rollback returns the rollback id"      "private function store_rollback(string \$id,string \$action,array \$before,array \$cx):string" "$SRC"
has  "status capture is a field map (post_status)" "\$before[\$id]=['post_status'=>\$post->post_status]" "$SRC"
has  "rollback dispatches on record action"        "if(in_array(\$action,['bulk_content','bulk_publish','bulk_draft','bulk_media']" "$SRC"
has  "woo branch reversible"                        "'bulk_woocommerce'===\$action" "$SRC"
has  "acf branch reversible"                        "'bulk_acf'===\$action" "$SRC"
has  "legacy scalar normalized to primary field"   "private function normalize_snap" "$SRC"
has  "status legacy maps to post_status"           "return['post_status'=>\$snap]" "$SRC"
has  "unsupported is reversible:false, not success" "'reversible'=>false" "$SRC"
has  "honest envelope reports restored + fields"   "'restored'=>\$restored,'fields'=>array_keys(\$fields_set)" "$SRC"
lacks "no status-into-title write remains"          "wp_update_post(['ID'=>(int)\$id,'post_title'=>\$old_title])" "$SRC"

echo
echo "== 2. Functional: corruption fixed, coverage closed, legacy + idempotency =="
if ! command -v wp >/dev/null 2>&1; then
	echo "  SKIP: wp-cli not available — static checks only."
else
	RES="$(wpe '
		$a=get_users(["role"=>"administrator","number"=>1]); wp_set_current_user($a?$a[0]->ID:1);
		$M="WPCommandCenter\\Operations\\BulkRuntimeManager";
		$mgr=new $M();
		$out=[];
		$mk=function($t,$s="draft",$c="BODY"){ return wp_insert_post(["post_title"=>$t,"post_status"=>$s,"post_type"=>"post","post_content"=>$c]); };
		$st=function($id){ return get_post_status($id); };
		$ti=function($id){ return get_post_field("post_title",$id,"raw"); };
		$co=function($id){ return get_post_field("post_content",$id,"raw"); };
		$rb=function($rid) use($mgr){ return $mgr->rollback(["rollback_id"=>$rid]); };

		// B1/B2 — status rollback restores status and does NOT corrupt title.
		$p1=$mk("ORIG_T1","draft"); $p2=$mk("ORIG_T2","draft");
		$r=$mgr->run(["action"=>"bulk_publish","ids"=>[$p1,$p2]]);
		$out["b10_pub_rid"]=$r["rollback_id"]??"";
		$out["b1_applied_status"]=$st($p1);                       // publish
		$x=$rb($r["rollback_id"]);
		$out["b1_rollback_status"]=$st($p1);                      // draft (restored)
		$out["b2_title_uncorrupted"]=$ti($p1);                    // ORIG_T1 (NOT "draft"/"publish")
		$out["b2_title_is_status"]=( in_array($ti($p1),["draft","publish"],true) )?1:0; // must be 0

		// B3 — content rollback restores touched fields; sibling preserved.
		$p3=$mk("C_OLD_T","draft","C_OLD_BODY");
		$r3=$mgr->run(["action"=>"bulk_content","ids"=>[$p3],"fields"=>["post_title"=>"C_NEW_T","post_content"=>"C_NEW_BODY"]]);
		$x=$rb($r3["rollback_id"]);
		$out["b3_title"]=$ti($p3); $out["b3_body"]=$co($p3);      // C_OLD_T / C_OLD_BODY

		// B3b — title-only edit; externally drift content; rollback must NOT touch content (sibling).
		$p3b=$mk("S_OLD_T","draft","S_BODY");
		$r3b=$mgr->run(["action"=>"bulk_content","ids"=>[$p3b],"fields"=>["post_title"=>"S_NEW_T"]]);
		wp_update_post(["ID"=>$p3b,"post_content"=>"S_SIBLING_EDIT"]);
		$x=$rb($r3b["rollback_id"]);
		$out["b3b_title"]=$ti($p3b);                              // S_OLD_T restored
		$out["b3b_sibling_body"]=$co($p3b);                       // S_SIBLING_EDIT preserved

		// B4 — media reversible.
		$att=wp_insert_post(["post_title"=>"M_OLD","post_status"=>"inherit","post_type"=>"attachment","post_mime_type"=>"image/png","post_content"=>""]);
		$r4=$mgr->run(["action"=>"bulk_media","ids"=>[$att],"title"=>"M_NEW"]);
		$out["b10_media_rid"]=$r4["rollback_id"]??"";
		$out["b4_applied"]=$ti($att);                             // M_NEW
		$x=$rb($r4["rollback_id"]);
		$out["b4_restored"]=$ti($att);                            // M_OLD

		// B5 — woo reversible (skip if inactive).
		if(class_exists("WooCommerce")&&function_exists("wc_get_product")){
			$pp=new \WC_Product_Simple(); $pp->set_name("BW"); $pp->set_status("publish"); $pp->set_regular_price("10"); $wid=$pp->save();
			$r5=$mgr->run(["action"=>"bulk_woocommerce","ids"=>[$wid],"regular_price"=>"99"]);
			$out["b10_woo_rid"]=$r5["rollback_id"]??"";
			$out["b5_applied"]=(string)wc_get_product($wid)->get_regular_price();   // 99
			$x=$rb($r5["rollback_id"]);
			$out["b5_restored"]=(string)wc_get_product($wid)->get_regular_price();  // 10
			$out["b5_run"]=1; wp_delete_post($wid,true);
		} else { $out["b5_run"]=0; }

		// B6 — acf reversible (skip if inactive). Uses a plain meta-backed field name.
		if(function_exists("update_field")&&function_exists("get_field")){
			$p6=$mk("ACFP","draft");
			$r6=$mgr->run(["action"=>"bulk_acf","post_ids"=>[$p6],"field_key"=>"wpcc_bulk_test","value"=>"ACF_NEW"]);
			$out["b10_acf_rid"]=$r6["rollback_id"]??"";
			$out["b6_applied"]=(string)get_field("wpcc_bulk_test",$p6);   // ACF_NEW
			$x=$rb($r6["rollback_id"]);
			$out["b6_restored"]=(string)get_field("wpcc_bulk_test",$p6);  // "" (prior null)
			$out["b6_run"]=1; wp_delete_post($p6,true);
		} else { $out["b6_run"]=0; }

		// B7 — legacy scalar record restores the correct field (status), title untouched.
		$p7=$mk("LEG_T","draft");
		wp_update_post(["ID"=>$p7,"post_status"=>"publish"]);     // simulate the applied state
		$lid=wp_generate_uuid4();
		$opt=get_option("wpcc_bulk_rollbacks",[]);
		$opt[]=["id"=>$lid,"entity_id"=>"bulk_publish","action"=>"bulk_publish","before_state"=>["ids"=>[$p7],"before"=>[$p7=>"draft"]],"rollback_applied"=>false,"created_at"=>time()];
		update_option("wpcc_bulk_rollbacks",$opt);
		$x=$rb($lid);
		$out["b7_status"]=$st($p7);                               // draft (restored from legacy scalar)
		$out["b7_title"]=$ti($p7);                                // LEG_T (untouched)

		// B8 — idempotency.
		$p8=$mk("IDEMP","draft");
		$r8=$mgr->run(["action"=>"bulk_publish","ids"=>[$p8]]);
		$x1=$rb($r8["rollback_id"]); $x2=$rb($r8["rollback_id"]);
		$out["b8_first_restored"]=$x1["restored"]??-1;            // 1
		$out["b8_second_code"]=$x2["code"]??"";                   // done

		// B9 — honest envelope + structured unsupported for unknown record type.
		$out["b9_restored_count"]=$x1["restored"]??-1;
		$out["b9_reversible"]=$x1["reversible"]??null;            // true
		$bogus=wp_generate_uuid4();
		$opt=get_option("wpcc_bulk_rollbacks",[]);
		$opt[]=["id"=>$bogus,"entity_id"=>"x","action"=>"bulk_bogus","before_state"=>["before"=>[]],"rollback_applied"=>false,"created_at"=>time()];
		update_option("wpcc_bulk_rollbacks",$opt);
		$xb=$rb($bogus);
		$out["b9_unsupported_code"]=$xb["code"]??"";              // wpcc_bulk_rollback_unsupported
		$out["b9_unsupported_reversible"]=$xb["reversible"]??null;// false

		// B10 — rollback_id surfaced on content op too.
		$p10=$mk("RID","draft");
		$r10=$mgr->run(["action"=>"bulk_content","ids"=>[$p10],"fields"=>["post_title"=>"RID2"]]);
		$out["b10_content_rid"]=$r10["rollback_id"]??"";

		foreach([$p1,$p2,$p3,$p3b,$att,$p7,$p8,$p10] as $d){ wp_delete_post($d,true); }
		echo wp_json_encode($out);
	')"
	gj() { printf '%s' "$RES" | php -r '$d=json_decode(stream_get_contents(STDIN),true); $v=$d["'"$1"'"]??""; echo is_bool($v)?($v?"true":"false"):$v;' 2>/dev/null; }
	# B1/B2 — the corruption fix
	assert_eq "B1 status applied (publish)"              "publish" "$(gj b1_applied_status)"
	assert_eq "B1 status restored (draft)"               "draft" "$(gj b1_rollback_status)"
	assert_eq "B2 title NOT corrupted (ORIG_T1)"         "ORIG_T1" "$(gj b2_title_uncorrupted)"
	assert_eq "B2 title is not a status word"            "0" "$(gj b2_title_is_status)"
	# B3 — content correctness + sibling preservation
	assert_eq "B3 title restored"                        "C_OLD_T" "$(gj b3_title)"
	assert_eq "B3 content restored"                      "C_OLD_BODY" "$(gj b3_body)"
	assert_eq "B3b title restored"                       "S_OLD_T" "$(gj b3b_title)"
	assert_eq "B3b sibling content preserved"            "S_SIBLING_EDIT" "$(gj b3b_sibling_body)"
	# B4 — media reversible
	assert_eq "B4 media title applied"                   "M_NEW" "$(gj b4_applied)"
	assert_eq "B4 media title restored"                  "M_OLD" "$(gj b4_restored)"
	# B5 — woo reversible
	if [ "$(gj b5_run)" = "1" ]; then
		assert_eq "B5 woo price applied"                 "99" "$(gj b5_applied)"
		assert_eq "B5 woo price restored"                "10" "$(gj b5_restored)"
	else echo "  NOTE: WooCommerce inactive — B5 skipped"; fi
	# B6 — acf reversible
	if [ "$(gj b6_run)" = "1" ]; then
		assert_eq "B6 acf value applied"                 "ACF_NEW" "$(gj b6_applied)"
		assert_eq "B6 acf value restored (empty prior)"  "" "$(gj b6_restored)"
	else echo "  NOTE: ACF inactive — B6 skipped"; fi
	# B7 — legacy compat (the corrected legacy reader)
	assert_eq "B7 legacy record restores status"         "draft" "$(gj b7_status)"
	assert_eq "B7 legacy record leaves title intact"     "LEG_T" "$(gj b7_title)"
	# B8 — idempotency
	assert_eq "B8 first rollback restored 1"             "1" "$(gj b8_first_restored)"
	assert_eq "B8 second rollback guarded"               "done" "$(gj b8_second_code)"
	# B9 — honesty
	assert_eq "B9 envelope reports restored count"       "1" "$(gj b9_restored_count)"
	assert_eq "B9 envelope reversible:true"              "true" "$(gj b9_reversible)"
	assert_eq "B9 unknown type → unsupported code"       "wpcc_bulk_rollback_unsupported" "$(gj b9_unsupported_code)"
	assert_eq "B9 unsupported → reversible:false"        "false" "$(gj b9_unsupported_reversible)"
	# B10 — rollback_id surfaced
	[ -n "$(gj b10_pub_rid)" ] && pass "B10 publish surfaces rollback_id" || fail "B10 publish rollback_id"
	[ -n "$(gj b10_media_rid)" ] && pass "B10 media surfaces rollback_id" || fail "B10 media rollback_id"
	[ -n "$(gj b10_content_rid)" ] && pass "B10 content surfaces rollback_id" || fail "B10 content rollback_id"
fi

echo
echo "Bulk rollback remediation: PASS=$PASS FAIL=$FAIL"
[ "$FAIL" -eq 0 ]
