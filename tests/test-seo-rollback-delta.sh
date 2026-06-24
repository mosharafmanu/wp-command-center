#!/usr/bin/env bash
#
# Phase 3 (F-1) — Field-scoped, drift-aware SEO delta rollback.
#
# Asserts the SEO rollback record is a field-scoped DELTA (version 2, `fields` map —
# only touched fields, each with post-write `after` + backing-key prior value/existence)
# and that restore is field-scoped, drift-aware, existence-faithful, idempotent, legacy-
# compatible, and history-honest. Replaces the full-object `before_state` snapshot that
# caused layered-rollback corruption (sibling loss / out-of-order resurrection).
#
# Backend-only: production files touched are SeoProvider.php + SeoRuntimeManager.php.
# Requires: wp-cli (functional section); static checks run regardless.

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

SRC="$PLUGIN_DIR/includes/Operations/SeoRuntimeManager.php"
PROV="$PLUGIN_DIR/includes/Operations/SeoProvider.php"
# PROGRAM-4 / P4.0 — the field-scoped, drift-aware capture/restore loop was extracted
# (behaviour-preserving) into the runtime-agnostic RollbackDelta core + post-meta accessor.
# Static structural guards below follow the code to its new home; every FUNCTIONAL
# round-trip assertion (== 2 onward) is unchanged and remains the behaviour oracle.
RBD="$PLUGIN_DIR/includes/Rollback/RollbackDelta.php"
PMA="$PLUGIN_DIR/includes/Rollback/PostMetaAccessor.php"
SFA="$PLUGIN_DIR/includes/Rollback/SeoFieldAccessor.php"

echo "Phase 3 (F-1) — Field-scoped, drift-aware SEO delta rollback"

echo
echo "== 1. Source: delta record + field-scoped drift-aware restore =="
has  "store record carries version 2"          "'version'          => 2," "$SRC"
has  "store record carries fields map"          "'fields'           => \$fields," "$SRC"
has  "store no longer keeps full before_state"  "after_all" "$SRC"
lacks "store no longer writes full before_state record" "'before_state'     => \$before," "$SRC"
has  "capture records existence flag (core)"    "metadata_exists( 'post', (int) \$entity_id, \$key )" "$PMA"
has  "SEO delegates capture to core"            "RollbackDelta::capture( new SeoFieldAccessor" "$SRC"
has  "delta restore branch present"             "function restore_delta" "$SRC"
has  "SEO delegates restore to core"            "RollbackDelta::restore( new SeoFieldAccessor" "$SRC"
has  "drift compare helper present (accessor)"  "public function equals" "$SFA"
has  "robots set-compare retained"              "sort( \$c )" "$SFA"
has  "drift skips + records conflict (core)"    "'reason' => 'drift'" "$RBD"
has  "existed=true restores prior (even '') (core)" "\$accessor->key_set( \$entity_id, \$key, \$meta['prior'] )" "$RBD"
has  "existed=false deletes on rollback (core)" "\$accessor->key_delete( \$entity_id, \$key )" "$RBD"
has  "accessor key_delete = delete_post_meta"   "delete_post_meta( (int) \$entity_id, \$key )" "$PMA"
has  "only complete is terminal"                "if ( 'complete' === \$status ) {" "$SRC"
has  "conflict error code"                      "wpcc_rollback_conflict" "$SRC"
has  "partial error code"                       "wpcc_rollback_partial" "$SRC"
has  "legacy meta restore retained"             "function restore_legacy_meta" "$SRC"
has  "legacy option fallback retained"          "function seo_restore_legacy" "$SRC"
has  "provider backing_keys helper"             "function backing_keys" "$PROV"
has  "provider read_field helper"               "function read_field" "$PROV"
has  "yoast robots splits to 3 keys"            "_yoast_wpseo_meta-robots-adv" "$PROV"

echo
echo "== 2. Functional: fidelity, layering, drift, out-of-order, robots, legacy, idempotency =="
if ! command -v wp >/dev/null 2>&1; then
	echo "  SKIP: wp-cli not available — static checks only."
else
	RES="$(wpe '
		$a=get_users(["role"=>"administrator","number"=>1]); wp_set_current_user($a?$a[0]->ID:1);
		$P="WPCommandCenter\\Operations\\SeoProvider";
		$M="WPCommandCenter\\Operations\\SeoRuntimeManager";
		$out=[];
		if ( $P::NONE === $P::detect() ) { $out["skip"]="no_seo_plugin"; echo wp_json_encode($out); return; }
		$prov=$P::detect();
		$mgr=new $M();
		$tk=$P::meta_key("title",$prov); $dk=$P::meta_key("description",$prov);
		$pid=wp_insert_post(["post_title"=>"WPCC P3 delta","post_status"=>"draft","post_type"=>"post","post_content"=>"x"]);
		$clear=function() use($pid,$prov,$P){ foreach($P::ALL_FIELDS as $f){ foreach($P::backing_keys($f,$prov) as $k){ delete_post_meta($pid,$k);} } };
		$up=function($seo) use($mgr,$pid){ return (string)($mgr->run(["action"=>"seo_update","content_id"=>$pid,"seo"=>$seo])["rollback_id"]??""); };
		$rb=function($rid) use($mgr){ return $mgr->run(["action"=>"seo_restore","rollback_id"=>$rid]); };

		// S1 — empty-prior fidelity: apply to absent prior, rollback deletes exactly.
		$clear();
		$r1=$up(["title"=>"T1","description"=>"D1"]);
		$out["s1_applied"]=( get_post_meta($pid,$tk,true)==="T1" )?1:0;
		$x=$rb($r1);
		$out["s1_status"]=$x["status"]??"";
		$out["s1_title_absent"]=metadata_exists("post",$pid,$tk)?0:1;
		$out["s1_desc_absent"]=metadata_exists("post",$pid,$dk)?0:1;

		// S2 — value-prior fidelity: apply over existing prior, rollback restores exact.
		$clear(); update_post_meta($pid,$tk,"OLD_T"); update_post_meta($pid,$dk,"OLD_D");
		$r2=$up(["title"=>"NEW_T","description"=>"NEW_D"]);
		$x=$rb($r2);
		$out["s2_status"]=$x["status"]??"";
		$out["s2_title"]=get_post_meta($pid,$tk,true);
		$out["s2_desc"]=get_post_meta($pid,$dk,true);

		// S3 — disjoint layered: A=title, B=description, rollback A; B must survive.
		$clear(); update_post_meta($pid,$tk,"ORIG_T"); update_post_meta($pid,$dk,"ORIG_D");
		$rA=$up(["title"=>"A_T"]); $rB=$up(["description"=>"B_D"]);
		$x=$rb($rA);
		$out["s3_status"]=$x["status"]??"";
		$out["s3_title"]=get_post_meta($pid,$tk,true);   // ORIG_T
		$out["s3_desc"]=get_post_meta($pid,$dk,true);    // B_D survived

		// S4 — same-field drift: A=title, B=title, rollback A; drift, B preserved.
		$clear(); update_post_meta($pid,$tk,"ORIG_T");
		$rA=$up(["title"=>"A_T"]); $rB=$up(["title"=>"B_T"]);
		$x=$rb($rA);
		$out["s4_status"]=$x["status"]??"";          // conflict
		$out["s4_error"]=!empty($x["error"])?1:0;
		$out["s4_code"]=$x["code"]??"";
		$out["s4_title"]=get_post_meta($pid,$tk,true); // B_T preserved
		$out["s4_skipped"]=implode(",",$x["skipped_fields"]??[]);

		// S5 — out-of-order recovery: rollback B (complete), retry A (now complete).
		$x=$rb($rB);
		$out["s5_b_status"]=$x["status"]??"";          // complete
		$out["s5_after_b_title"]=get_post_meta($pid,$tk,true); // A_T (prior of B)
		$x=$rb($rA);
		$out["s5_a_status"]=$x["status"]??"";          // complete (no longer drifted)
		$out["s5_after_a_title"]=metadata_exists("post",$pid,$tk)?get_post_meta($pid,$tk,true):"__ABSENT__"; // ORIG_T

		// S6 — robots fidelity (active provider): apply array, rollback to absent.
		$clear();
		$r6=$up(["robots"=>["noindex","nofollow"]]);
		$lr=$P::read_field($pid,"robots",$prov); sort($lr);
		$out["s6_applied_robots"]=implode(",",$lr);    // noindex,nofollow
		$x=$rb($r6);
		$out["s6_status"]=$x["status"]??"";            // complete
		$rr=$P::read_field($pid,"robots",$prov);
		$out["s6_robots_empty_after"]=empty($rr)?1:0;  // restored to pre-apply (absent)

		// S7 — legacy full-snapshot record still restores via legacy path.
		$clear(); update_post_meta($pid,$tk,"LEG_PRIOR");
		$lid=wp_generate_uuid4();
		add_post_meta($pid,"_wpcc_seo_rb_".$lid,["id"=>$lid,"post_id"=>$pid,"provider"=>$prov,"before_state"=>["title"=>"LEG_PRIOR","description"=>""],"rollback_applied"=>false,"created_at"=>time()],true);
		update_post_meta($pid,$tk,"CHANGED");
		$x=$rb($lid);
		$out["s7_path"]=$x["path"]??"";                // legacy
		$out["s7_title"]=get_post_meta($pid,$tk,true); // LEG_PRIOR (full restore)

		// S8 — repeated rollback: complete then guarded already_applied.
		$clear(); update_post_meta($pid,$tk,"R_PRIOR");
		$r8=$up(["title"=>"R_NEW"]);
		$x1=$rb($r8); $x2=$rb($r8);
		$out["s8_first_status"]=$x1["status"]??"";     // complete
		$out["s8_second_code"]=$x2["code"]??"";        // wpcc_rollback_already_applied

		// S9 — partial rollback: result lists restored + skipped; sibling drift preserved.
		$clear(); update_post_meta($pid,$tk,"P_OT"); update_post_meta($pid,$dk,"P_OD");
		$r9=$up(["title"=>"P_AT","description"=>"P_AD"]);
		$up(["description"=>"P_DRIFT"]);               // drift the description
		$x=$rb($r9);
		$out["s9_status"]=$x["status"]??"";            // partial
		$out["s9_code"]=$x["code"]??"";                // wpcc_rollback_partial
		$out["s9_restored"]=implode(",",$x["restored_fields"]??[]);  // title
		$out["s9_skipped"]=implode(",",$x["skipped_fields"]??[]);    // description
		$out["s9_title"]=get_post_meta($pid,$tk,true); // P_OT restored
		$out["s9_desc"]=get_post_meta($pid,$dk,true);  // P_DRIFT preserved

		// S10 — provider parity (Yoast structural backing-key shape).
		$out["s10_yoast_robots_keys"]=count($P::backing_keys("robots","yoast"));   // 3
		$out["s10_yoast_title_key"]=implode(",",$P::backing_keys("title","yoast")); // _yoast_wpseo_title

		wp_delete_post($pid,true);
		echo wp_json_encode($out);
	')"
	gj() { printf '%s' "$RES" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["'"$1"'"] ?? "";' 2>/dev/null; }
	if [ "$(gj skip)" = "no_seo_plugin" ]; then
		echo "  NOTE: no SEO plugin active — functional path skipped."
	else
		# S1 empty-prior fidelity
		assert_eq "S1 update applied title"                  "1" "$(gj s1_applied)"
		assert_eq "S1 restore complete"                      "complete" "$(gj s1_status)"
		assert_eq "S1 absent-prior title deleted on rollback" "1" "$(gj s1_title_absent)"
		assert_eq "S1 absent-prior description deleted"      "1" "$(gj s1_desc_absent)"
		# S2 value-prior fidelity
		assert_eq "S2 restore complete"                      "complete" "$(gj s2_status)"
		assert_eq "S2 prior title restored exactly"          "OLD_T" "$(gj s2_title)"
		assert_eq "S2 prior description restored exactly"    "OLD_D" "$(gj s2_desc)"
		# S3 disjoint layered — sibling survives
		assert_eq "S3 rollback A complete"                   "complete" "$(gj s3_status)"
		assert_eq "S3 title restored to ORIG_T"              "ORIG_T" "$(gj s3_title)"
		assert_eq "S3 sibling description (B) survives"      "B_D" "$(gj s3_desc)"
		# S4 same-field drift
		assert_eq "S4 rollback A is conflict"                "conflict" "$(gj s4_status)"
		assert_eq "S4 conflict returned as error"            "1" "$(gj s4_error)"
		assert_eq "S4 conflict code"                         "wpcc_rollback_conflict" "$(gj s4_code)"
		assert_eq "S4 B's title NOT clobbered"               "B_T" "$(gj s4_title)"
		assert_eq "S4 title reported skipped"                "title" "$(gj s4_skipped)"
		# S5 out-of-order recovery
		assert_eq "S5 rollback B complete"                   "complete" "$(gj s5_b_status)"
		assert_eq "S5 title back to A_T after B rollback"    "A_T" "$(gj s5_after_b_title)"
		assert_eq "S5 retry A now complete"                  "complete" "$(gj s5_a_status)"
		assert_eq "S5 title back to ORIG_T (no resurrection)" "ORIG_T" "$(gj s5_after_a_title)"
		# S6 robots fidelity
		assert_eq "S6 robots applied (normalized sort)"      "nofollow,noindex" "$(gj s6_applied_robots)"
		assert_eq "S6 robots rollback complete"              "complete" "$(gj s6_status)"
		assert_eq "S6 robots restored to pre-apply (empty)"  "1" "$(gj s6_robots_empty_after)"
		# S7 legacy
		assert_eq "S7 legacy record uses legacy path"        "legacy" "$(gj s7_path)"
		assert_eq "S7 legacy full restore"                   "LEG_PRIOR" "$(gj s7_title)"
		# S8 repeated rollback
		assert_eq "S8 first restore complete"                "complete" "$(gj s8_first_status)"
		assert_eq "S8 second restore guarded"                "wpcc_rollback_already_applied" "$(gj s8_second_code)"
		# S9 partial
		assert_eq "S9 status partial"                        "partial" "$(gj s9_status)"
		assert_eq "S9 partial code"                          "wpcc_rollback_partial" "$(gj s9_code)"
		assert_eq "S9 restored = title"                      "title" "$(gj s9_restored)"
		assert_eq "S9 skipped = description"                 "description" "$(gj s9_skipped)"
		assert_eq "S9 title restored"                        "P_OT" "$(gj s9_title)"
		assert_eq "S9 drifted description preserved"         "P_DRIFT" "$(gj s9_desc)"
		# S10 provider parity (Yoast)
		assert_eq "S10 Yoast robots → 3 backing keys"        "3" "$(gj s10_yoast_robots_keys)"
		assert_eq "S10 Yoast title backing key"              "_yoast_wpseo_title" "$(gj s10_yoast_title_key)"
	fi
fi

echo
echo "Phase 3 delta-rollback: PASS=$PASS FAIL=$FAIL"
[ "$FAIL" -eq 0 ]
