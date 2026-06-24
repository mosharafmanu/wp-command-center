#!/usr/bin/env bash
#
# PROGRAM-4.8 — Bulk per-item, field-scoped, drift-aware rollback.
#
# Proves the delta redesign: per-item PostMetaRollbackStore records addressed by a batch
# membership index; drift-aware restore (skip+report, never clobber); sibling preservation;
# out-of-order safety; per-item failure isolation; partial/conflict truthfulness; idempotency;
# legacy P4C.0a record compatibility; no FIFO eviction; rollback_id surfacing.
#
# Backend-only: exercises WPCommandCenter\Operations\BulkRuntimeManager directly. Requires
# wp-cli; Woo/ACF branches self-skip when inactive.

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
WOO="$PLUGIN_DIR/includes/Rollback/BulkWooAccessor.php"
ACF="$PLUGIN_DIR/includes/Rollback/BulkAcfAccessor.php"

echo "PROGRAM-4.8 — Bulk per-item delta rollback"

echo
echo "== 1. Source: per-item delta, batch index, drift-aware, no FIFO =="
has  "per-item PostMetaRollbackStore"          "PostMetaRollbackStore(self::ITEM_PREFIX)" "$SRC"
has  "field-scoped capture via core"           "RollbackDelta::capture" "$SRC"
has  "build_record per item"                   "RollbackDelta::build_record" "$SRC"
has  "drift-aware restore via core"            "RollbackDelta::restore" "$SRC"
has  "batch membership meta index"             "self::BATCH_PREFIX.\$batch" "$SRC"
has  "batch resolved by indexed meta_key"      "WHERE meta_key = %s" "$SRC"
has  "post ops reuse ContentFieldAccessor"     "new ContentFieldAccessor()" "$SRC"
has  "woo uses BulkWooAccessor"                "new BulkWooAccessor()" "$SRC"
has  "acf uses BulkAcfAccessor"                "new BulkAcfAccessor(" "$SRC"
has  "per-item failure isolation (try/catch)"  "catch(\\Throwable \$e)" "$SRC"
has  "legacy P4C.0a path retained"             "function legacy_rollback" "$SRC"
has  "idempotent fully-applied → done"         "Already applied." "$SRC"
lacks "no FIFO array_slice cap anywhere"        "array_slice" "$SRC"
has  "woo drift normalizes decimals"           "(float) \$current" "$WOO"
has  "acf existence via metadata_exists"       "metadata_exists( 'post'" "$ACF"

echo
echo "== 2. Functional: drift, sibling, out-of-order, partial, isolation, idempotency, legacy =="
if ! command -v wp >/dev/null 2>&1; then
	echo "  SKIP: wp-cli not available — static checks only."
else
	RES="$(wpe '
		$a=get_users(["role"=>"administrator","number"=>1]); wp_set_current_user($a?$a[0]->ID:1);
		$M="WPCommandCenter\\Operations\\BulkRuntimeManager";
		$ST="WPCommandCenter\\Rollback\\PostMetaRollbackStore";
		$mgr=new $M(); $out=[];
		global $wpdb;
		$mk=function($t,$s="draft",$c="BODY"){ return wp_insert_post(["post_title"=>$t,"post_status"=>$s,"post_type"=>"post","post_content"=>$c]); };
		$st=function($id){ return get_post_status($id); };
		$ti=function($id){ return get_post_field("post_title",$id,"raw"); };
		$co=function($id){ return get_post_field("post_content",$id,"raw"); };
		$rb=function($rid) use($mgr){ return $mgr->rollback(["rollback_id"=>$rid]); };

		// D1/D2 — status restore, not title (publish + unpublish).
		$p1=$mk("T1","draft"); $p2=$mk("T2","draft");
		$r=$mgr->run(["action"=>"bulk_publish","ids"=>[$p1,$p2]]);
		$out["d1_applied"]=$st($p1);                       // publish
		$x=$rb($r["rollback_id"]);
		$out["d1_status"]=$st($p1); $out["d1_title"]=$ti($p1); // draft / T1
		$out["d1_batch_status"]=$x["status"]??"";          // complete
		$pu=$mk("U1","publish");
		$ru=$mgr->run(["action"=>"bulk_unpublish","ids"=>[$pu]]);
		$out["d2_applied"]=$st($pu);                       // draft
		$rb($ru["rollback_id"]);
		$out["d2_status"]=$st($pu);                        // publish (restored)

		// D3 — sibling preservation: title-only rollback leaves a drifted sibling content alone.
		$ps=$mk("S_OT","draft","S_OC");
		$rs=$mgr->run(["action"=>"bulk_content","ids"=>[$ps],"fields"=>["post_title"=>"S_NT"]]);
		wp_update_post(["ID"=>$ps,"post_content"=>"S_SIBLING"]);   // drift the sibling
		$rb($rs["rollback_id"]);
		$out["d3_title"]=$ti($ps);                         // S_OT
		$out["d3_sibling"]=$co($ps);                       // S_SIBLING (preserved)

		// D4 — drift conflict: layered same-field; rollback A is conflict, B not clobbered.
		$pd=$mk("OT","draft");
		$rA=$mgr->run(["action"=>"bulk_content","ids"=>[$pd],"fields"=>["post_title"=>"NT"]]);
		$rB=$mgr->run(["action"=>"bulk_content","ids"=>[$pd],"fields"=>["post_title"=>"NT2"]]);
		$x=$rb($rA["rollback_id"]);
		$out["d4_status"]=$x["status"]??"";                // conflict
		$out["d4_skipped"]=$x["skipped"]??-1;              // 1
		$out["d4_title"]=$ti($pd);                         // NT2 (not clobbered)

		// D5 — out-of-order: rollback B (complete), then retry A (now complete, no resurrection).
		$x=$rb($rB["rollback_id"]);
		$out["d5_b_status"]=$x["status"]??""; $out["d5_after_b"]=$ti($pd);  // complete / NT
		$x=$rb($rA["rollback_id"]);
		$out["d5_a_status"]=$x["status"]??""; $out["d5_after_a"]=$ti($pd);  // complete / OT

		// D6 — partial across items: P1 restores, P2 drifted → skipped; status partial.
		$q1=$mk("O1","draft"); $q2=$mk("O2","draft");
		$rp=$mgr->run(["action"=>"bulk_content","ids"=>[$q1,$q2],"fields"=>["post_title"=>"N"]]);
		wp_update_post(["ID"=>$q2,"post_title"=>"DRIFT2"]);   // drift P2
		$x=$rb($rp["rollback_id"]);
		$out["d6_status"]=$x["status"]??"";                // partial
		$out["d6_restored"]=$x["restored"]??-1;            // 1
		$out["d6_skipped"]=$x["skipped"]??-1;              // 1
		$out["d6_p1"]=$ti($q1); $out["d6_p2"]=$ti($q2);    // O1 / DRIFT2

		// D7 — missing item record handled honestly + per-item isolation: leave m2''s batch
		// membership dangling but delete its item record only; m1 still restores, m2 → missing.
		$m1=$mk("M1","draft"); $m2=$mk("M2","draft");
		$rm=$mgr->run(["action"=>"bulk_publish","ids"=>[$m1,$m2]]); $bid2=$rm["rollback_id"];
		$mem=$wpdb->get_results($wpdb->prepare("SELECT post_id,meta_value FROM {$wpdb->postmeta} WHERE meta_key=%s","_wpcc_bulk_b_".$bid2));
		foreach($mem as $mm){ if((int)$mm->post_id===$m2){ delete_post_meta($m2,"_wpcc_bulk_rb_".(string)$mm->meta_value); } }
		$x=$rb($bid2);
		$out["d7_status"]=$x["status"]??"";                // partial
		$out["d7_restored"]=$x["restored"]??-1;            // 1
		$out["d7_missing"]=$x["missing"]??-1;              // 1
		$out["d7_m1"]=$st($m1);                            // draft (restored)

		// D8 — idempotency: repeat rollback → guarded done.
		$pi=$mk("IDEMP","draft");
		$ri=$mgr->run(["action"=>"bulk_publish","ids"=>[$pi]]);
		$x1=$rb($ri["rollback_id"]); $x2=$rb($ri["rollback_id"]);
		$out["d8_first"]=$x1["status"]??""; $out["d8_second_code"]=$x2["code"]??""; // complete / done

		// D9 — media per-item.
		$att=wp_insert_post(["post_title"=>"MO","post_status"=>"inherit","post_type"=>"attachment","post_mime_type"=>"image/png"]);
		$rmd=$mgr->run(["action"=>"bulk_media","ids"=>[$att],"title"=>"MN"]);
		$out["d9_applied"]=$ti($att); $rb($rmd["rollback_id"]); $out["d9_restored"]=$ti($att); // MN / MO

		// D10 — woo per-item (skip if inactive).
		if(class_exists("WooCommerce")&&function_exists("wc_get_product")){
			$pp=new \WC_Product_Simple(); $pp->set_name("BW"); $pp->set_status("publish"); $pp->set_regular_price("10"); $wid=$pp->save();
			$rw=$mgr->run(["action"=>"bulk_woocommerce","ids"=>[$wid],"regular_price"=>"99"]);
			$out["d10_applied"]=(string)wc_get_product($wid)->get_regular_price();
			$rb($rw["rollback_id"]);
			$out["d10_restored"]=(string)wc_get_product($wid)->get_regular_price();
			$out["d10_run"]=1; wp_delete_post($wid,true);
		} else { $out["d10_run"]=0; }

		// D11 — acf per-item (skip if inactive).
		if(function_exists("update_field")&&function_exists("get_field")){
			$pa=$mk("ACFP","draft");
			$ra=$mgr->run(["action"=>"bulk_acf","post_ids"=>[$pa],"field_key"=>"wpcc_bulk_d_test","value"=>"AV"]);
			$out["d11_applied"]=(string)get_field("wpcc_bulk_d_test",$pa);
			$rb($ra["rollback_id"]);
			$out["d11_restored"]=(string)get_field("wpcc_bulk_d_test",$pa);
			$out["d11_run"]=1; wp_delete_post($pa,true);
		} else { $out["d11_run"]=0; }

		// D11b — AR-MED-1 regression: bulk_acf via a real field KEY selector must RESTORE the
		// prior value (not clear it). Pre-fix, existence resolved on the key → false → rollback
		// cleared instead of restoring.
		if(function_exists("acf_update_field_group")&&function_exists("update_field")){
			acf_update_field_group(["key"=>"group_bulkkey","title"=>"BK","fields"=>[],"location"=>[[["param"=>"post_type","operator"=>"==","value"=>"post"]]],"active"=>true]);
			acf_update_field(["key"=>"field_bulkkey","label"=>"BK","name"=>"bk_field","type"=>"text","parent"=>"group_bulkkey"]);
			$pk=$mk("BKP","draft");
			update_field("field_bulkkey","PRIOR",$pk);            // prior value (exists under name bk_field)
			$rk=$mgr->run(["action"=>"bulk_acf","post_ids"=>[$pk],"field_key"=>"field_bulkkey","value"=>"NEWV"]);
			$out["d11b_applied"]=(string)get_field("field_bulkkey",$pk);  // NEWV
			$rb($rk["rollback_id"]);
			$out["d11b_restored"]=(string)get_field("field_bulkkey",$pk); // PRIOR (must NOT be cleared)
			$out["d11b_run"]=1;
			acf_delete_field("field_bulkkey"); acf_delete_field_group("group_bulkkey"); wp_delete_post($pk,true);
		} else { $out["d11b_run"]=0; }

		// D12 — batch index resolves item records (postmeta membership, indexed).
		$pb1=$mk("BI1","draft"); $pb2=$mk("BI2","draft");
		$rbi=$mgr->run(["action"=>"bulk_publish","ids"=>[$pb1,$pb2]]); $bid=$rbi["rollback_id"];
		$members=$wpdb->get_results($wpdb->prepare("SELECT post_id,meta_value FROM {$wpdb->postmeta} WHERE meta_key=%s","_wpcc_bulk_b_".$bid));
		$out["d12_members"]=count($members);               // 2
		$store=new $ST("_wpcc_bulk_rb_");
		$out["d12_resolves"]=($members&&$store->resolve((string)$members[0]->meta_value)!==null)?1:0;
		$rb($bid);

		// D13 — legacy P4C.0a option record still restores via legacy path.
		$pl=$mk("LEG","draft"); wp_update_post(["ID"=>$pl,"post_status"=>"publish"]);
		$lid=wp_generate_uuid4();
		$opt=get_option("wpcc_bulk_rollbacks",[]);
		$opt[]=["id"=>$lid,"entity_id"=>"bulk_publish","action"=>"bulk_publish","before_state"=>["ids"=>[$pl],"before"=>[$pl=>"draft"]],"rollback_applied"=>false,"created_at"=>time()];
		update_option("wpcc_bulk_rollbacks",$opt);
		$x=$rb($lid);
		$out["d13_path"]=$x["path"]??""; $out["d13_status"]=$st($pl);  // legacy / draft

		// D14 — no FIFO eviction: an early batch still resolves after later batches.
		$e1=$mk("E1","draft"); $rf=$mgr->run(["action"=>"bulk_publish","ids"=>[$e1]]); $first=$rf["rollback_id"];
		$e2=$mk("E2","draft"); $mgr->run(["action"=>"bulk_publish","ids"=>[$e2]]);
		$e3=$mk("E3","draft"); $mgr->run(["action"=>"bulk_publish","ids"=>[$e3]]);
		$mem=$wpdb->get_results($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s","_wpcc_bulk_b_".$first));
		$out["d14_first_survives"]=count($mem)>0?1:0;      // 1 (no eviction)

		// D15 — rollback_id surfaced for content op.
		$pr=$mk("RID","draft");
		$out["d15_rid"]=$mgr->run(["action"=>"bulk_content","ids"=>[$pr],"fields"=>["post_title"=>"R2"]])["rollback_id"]??"";

		foreach([$p1,$p2,$pu,$ps,$pd,$q1,$q2,$m1,$m2,$att,$pi,$pb1,$pb2,$pl,$e1,$e2,$e3,$pr] as $d){ if(get_post($d))wp_delete_post($d,true); }
		echo wp_json_encode($out);
	')"
	gj() { printf '%s' "$RES" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["'"$1"'"]??"";' 2>/dev/null; }
	# 1+2 status restore not title
	assert_eq "D1 status applied (publish)"           "publish" "$(gj d1_applied)"
	assert_eq "D1 status restored (draft)"            "draft" "$(gj d1_status)"
	assert_eq "D1 title NOT corrupted"                "T1" "$(gj d1_title)"
	assert_eq "D1 batch status complete"              "complete" "$(gj d1_batch_status)"
	assert_eq "D2 unpublish applied (draft)"          "draft" "$(gj d2_applied)"
	assert_eq "D2 unpublish restored (publish)"       "publish" "$(gj d2_status)"
	# 3 sibling preservation
	assert_eq "D3 title restored"                     "S_OT" "$(gj d3_title)"
	assert_eq "D3 drifted sibling content preserved"  "S_SIBLING" "$(gj d3_sibling)"
	# 10 drift conflict
	assert_eq "D4 rollback A is conflict"             "conflict" "$(gj d4_status)"
	assert_eq "D4 one field skipped"                  "1" "$(gj d4_skipped)"
	assert_eq "D4 drifted title not clobbered"        "NT2" "$(gj d4_title)"
	# out-of-order safety
	assert_eq "D5 rollback B complete"                "complete" "$(gj d5_b_status)"
	assert_eq "D5 title back to NT after B"           "NT" "$(gj d5_after_b)"
	assert_eq "D5 retry A now complete"               "complete" "$(gj d5_a_status)"
	assert_eq "D5 title back to OT (no resurrection)" "OT" "$(gj d5_after_a)"
	# 8 partial honesty across items
	assert_eq "D6 batch status partial"               "partial" "$(gj d6_status)"
	assert_eq "D6 restored 1"                         "1" "$(gj d6_restored)"
	assert_eq "D6 skipped 1"                          "1" "$(gj d6_skipped)"
	assert_eq "D6 P1 restored (O1)"                   "O1" "$(gj d6_p1)"
	assert_eq "D6 P2 drift preserved (DRIFT2)"        "DRIFT2" "$(gj d6_p2)"
	# 7 + 13 missing-item isolation/honesty
	assert_eq "D7 batch status partial"               "partial" "$(gj d7_status)"
	assert_eq "D7 restored 1 (other item)"            "1" "$(gj d7_restored)"
	assert_eq "D7 missing 1 (deleted item)"           "1" "$(gj d7_missing)"
	assert_eq "D7 surviving item restored"            "draft" "$(gj d7_m1)"
	# 9 idempotency
	assert_eq "D8 first complete"                     "complete" "$(gj d8_first)"
	assert_eq "D8 second guarded (done)"              "done" "$(gj d8_second_code)"
	# 4 media
	assert_eq "D9 media applied"                      "MN" "$(gj d9_applied)"
	assert_eq "D9 media restored"                     "MO" "$(gj d9_restored)"
	# 5 woo
	if [ "$(gj d10_run)" = "1" ]; then
		assert_eq "D10 woo price applied"             "99" "$(gj d10_applied)"
		assert_eq "D10 woo price restored"            "10" "$(gj d10_restored)"
	else echo "  NOTE: WooCommerce inactive — D10 skipped"; fi
	# 6 acf
	if [ "$(gj d11_run)" = "1" ]; then
		assert_eq "D11 acf applied"                   "AV" "$(gj d11_applied)"
		assert_eq "D11 acf restored (prior empty)"    "" "$(gj d11_restored)"
	else echo "  NOTE: ACF inactive — D11 skipped"; fi
	if [ "$(gj d11b_run)" = "1" ]; then
		assert_eq "D11b acf KEY-selector applied"     "NEWV" "$(gj d11b_applied)"
		assert_eq "D11b acf KEY-selector RESTORES prior (AR-MED-1)" "PRIOR" "$(gj d11b_restored)"
	else echo "  NOTE: ACF inactive — D11b skipped"; fi
	# 12 batch index
	assert_eq "D12 batch membership rows = item count" "2" "$(gj d12_members)"
	assert_eq "D12 item record resolves via store"     "1" "$(gj d12_resolves)"
	# 11 legacy
	assert_eq "D13 legacy path used"                  "legacy" "$(gj d13_path)"
	assert_eq "D13 legacy record restores status"     "draft" "$(gj d13_status)"
	# 16 no FIFO
	assert_eq "D14 early batch survives later batches" "1" "$(gj d14_first_survives)"
	# 14 rollback_id surfaced
	[ -n "$(gj d15_rid)" ] && pass "D15 content op surfaces rollback_id" || fail "D15 rollback_id"
fi

echo
echo "Bulk delta-rollback: PASS=$PASS FAIL=$FAIL"
[ "$FAIL" -eq 0 ]
