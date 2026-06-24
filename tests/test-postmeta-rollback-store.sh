#!/usr/bin/env bash
#
# PROGRAM-4.7 — PostMetaRollbackStore keystone.
#
# Proves the new RollbackStore implementation: persist/resolve/mark_applied round-trip,
# O(1) resolve by rollback_id alone, no-FIFO independence, GC-with-post, malformed-record
# safety, unique id-collision rejection, protected-meta prefix, and coexistence with the
# option stores (no cross-talk). Interface-compliance is asserted statically.
#
# Backend-only: exercises WPCommandCenter\Rollback\PostMetaRollbackStore directly (mirrors
# the rollback-delta-core suite). Requires wp-cli.

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

SRC="$PLUGIN_DIR/includes/Rollback/PostMetaRollbackStore.php"
IFACE="$PLUGIN_DIR/includes/Rollback/RollbackStore.php"

echo "PROGRAM-4.7 — PostMetaRollbackStore keystone"

echo
echo "== 1. Source: interface-compliant, postmeta-backed, id-addressable =="
has  "implements RollbackStore"                 "implements RollbackStore" "$SRC"
has  "persist signature matches interface"      "public function persist( \$entity_id, string \$rollback_id, array \$record ): void" "$SRC"
has  "resolve signature matches interface"      "public function resolve( string \$rollback_id ): ?array" "$SRC"
has  "mark_applied signature matches interface" "public function mark_applied( \$entity_id, string \$rollback_id, array \$record ): void" "$SRC"
has  "persist = unique add_post_meta"           "add_post_meta( \$post_id, \$this->meta_key( \$rollback_id ), \$record, true )" "$SRC"
has  "resolve = O(1) indexed meta_key lookup"   "SELECT post_id FROM {\$wpdb->postmeta} WHERE meta_key = %s LIMIT 1" "$SRC"
has  "mark_applied = update_post_meta"          "update_post_meta( \$post_id, \$this->meta_key( \$rollback_id ), \$record )" "$SRC"
has  "meta_key encodes rollback_id"             "return \$this->prefix . \$rollback_id;" "$SRC"
has  "protected-meta prefix enforced"           "'_' . \$prefix" "$SRC"
has  "malformed record → null (defensive)"      "if ( ! is_array( \$record ) ) {" "$SRC"
has  "interface unchanged (3 methods)"          "public function mark_applied( \$entity_id, string \$rollback_id, array \$record ): void" "$IFACE"

echo
echo "== 2. Functional: round-trip, O(1) resolve, independence, GC, safety, coexistence =="
if ! command -v wp >/dev/null 2>&1; then
	echo "  SKIP: wp-cli not available — static checks only."
else
	RES="$(wpe '
		$S="WPCommandCenter\\Rollback\\PostMetaRollbackStore";
		$store=new $S("_wpcc_test_rb_");
		$out=[];
		$pid=wp_insert_post(["post_title"=>"P47 store","post_status"=>"draft","post_type"=>"post","post_content"=>"x"]);

		// T1 — persist then resolve by id alone (no post id passed to resolve).
		$rid=wp_generate_uuid4();
		$rec=["id"=>$rid,"post_id"=>$pid,"version"=>2,"fields"=>["a"=>1],"rollback_applied"=>false];
		$store->persist($pid,$rid,$rec);
		$r=$store->resolve($rid);
		$out["t1_resolved"]=($r&&is_array($r))?1:0;
		$out["t1_entity_match"]=(($r["entity_id"]??0)===$pid)?1:0;  // resolved entity_id == the post id
		$out["t1_record_id"]=$r["record"]["id"]??"";        // == rid
		$out["t1_applied"]=$r["record"]["rollback_applied"]?1:0; // 0

		// T2 — meta row is a single protected postmeta keyed by the id (encoded).
		$out["t2_meta_present"]=metadata_exists("post",$pid,"_wpcc_test_rb_".$rid)?1:0;
		$out["t2_protected"]=is_protected_meta("_wpcc_test_rb_".$rid,"post")?1:0;

		// T3 — mark_applied flips the flag in place (same id, same row).
		$rec2=$r["record"]; $rec2["rollback_applied"]=true;
		$store->mark_applied($pid,$rid,$rec2);
		$r3=$store->resolve($rid);
		$out["t3_applied"]=$r3["record"]["rollback_applied"]?1:0; // 1

		// T4 — absent id resolves to null (not fatal).
		$out["t4_absent_null"]=(null===$store->resolve(wp_generate_uuid4()))?1:0;

		// T5 — two records on the SAME post are independent (no FIFO, no clobber).
		$ridA=wp_generate_uuid4(); $ridB=wp_generate_uuid4();
		$store->persist($pid,$ridA,["id"=>$ridA,"post_id"=>$pid,"v"=>"A","rollback_applied"=>false]);
		$store->persist($pid,$ridB,["id"=>$ridB,"post_id"=>$pid,"v"=>"B","rollback_applied"=>false]);
		$out["t5_a"]=$store->resolve($ridA)["record"]["v"]??"";  // A
		$out["t5_b"]=$store->resolve($ridB)["record"]["v"]??"";  // B (both survive)

		// T6 — unique: re-persisting the same id does NOT overwrite the original record.
		$store->persist($pid,$ridA,["id"=>$ridA,"post_id"=>$pid,"v"=>"A_DUP","rollback_applied"=>false]);
		$out["t6_unchanged"]=($store->resolve($ridA)["record"]["v"]??"")==="A"?1:0; // 1 (original intact)

		// T7 — GC: deleting the post removes the meta; resolve → null.
		$pid2=wp_insert_post(["post_title"=>"GC","post_status"=>"draft","post_type"=>"post"]);
		$ridG=wp_generate_uuid4();
		$store->persist($pid2,$ridG,["id"=>$ridG,"post_id"=>$pid2,"rollback_applied"=>false]);
		wp_delete_post($pid2,true);
		$out["t7_gc_null"]=(null===$store->resolve($ridG))?1:0; // 1

		// T8 — malformed (non-array) meta value → resolve null, no fatal.
		$ridM=wp_generate_uuid4();
		add_post_meta($pid,"_wpcc_test_rb_".$ridM,"NOT_AN_ARRAY",true);
		$out["t8_malformed_null"]=(null===$store->resolve($ridM))?1:0; // 1

		// T9 — coexistence with an option store of the SAME id (no cross-talk).
		$ridC=wp_generate_uuid4();
		update_option("wpcc_test_opt_rb",[$ridC=>["id"=>$ridC,"source"=>"option"]]);
		$store->persist($pid,$ridC,["id"=>$ridC,"post_id"=>$pid,"source"=>"postmeta","rollback_applied"=>false]);
		$out["t9_postmeta_src"]=$store->resolve($ridC)["record"]["source"]??"";   // postmeta
		$out["t9_option_intact"]=(get_option("wpcc_test_opt_rb")[$ridC]["source"]??"")==="option"?1:0; // 1
		delete_option("wpcc_test_opt_rb");

		// T10 — prefix without leading underscore is forced protected.
		$store2=new $S("wpcc_nounderscore_");
		$ridP=wp_generate_uuid4();
		$store2->persist($pid,$ridP,["id"=>$ridP,"post_id"=>$pid,"rollback_applied"=>false]);
		$out["t10_forced_protected"]=metadata_exists("post",$pid,"_wpcc_nounderscore_".$ridP)?1:0; // 1
		$out["t10_resolves"]=($store2->resolve($ridP)!==null)?1:0; // 1

		// T11 — guard rails: empty id / non-positive entity are no-ops.
		$store->persist(0,"x",["id"=>"x"]);
		$out["t11_zero_entity_noop"]=(null===$store->resolve("x"))?1:0; // 1 (nothing written under id x for pid)
		$out["t11_empty_id_null"]=(null===$store->resolve(""))?1:0;      // 1

		wp_delete_post($pid,true);
		echo wp_json_encode($out);
	')"
	gj() { printf '%s' "$RES" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["'"$1"'"]??"";' 2>/dev/null; }
	assert_eq "T1 resolve by id alone"                  "1" "$(gj t1_resolved)"
	assert_eq "T1 entity_id == the post id"             "1" "$(gj t1_entity_match)"
	[ -n "$(gj t1_record_id)" ] && pass "T1 record id round-trips" || fail "T1 record id round-trips"
	assert_eq "T1 record not yet applied"               "0" "$(gj t1_applied)"
	assert_eq "T2 single protected meta row present"    "1" "$(gj t2_meta_present)"
	assert_eq "T2 meta is protected (leading _)"        "1" "$(gj t2_protected)"
	assert_eq "T3 mark_applied flips flag in place"     "1" "$(gj t3_applied)"
	assert_eq "T4 absent id → null"                     "1" "$(gj t4_absent_null)"
	assert_eq "T5 record A survives (no FIFO)"          "A" "$(gj t5_a)"
	assert_eq "T5 record B survives (independent)"      "B" "$(gj t5_b)"
	assert_eq "T6 unique: original not overwritten"     "1" "$(gj t6_unchanged)"
	assert_eq "T7 GC: deleted post → resolve null"      "1" "$(gj t7_gc_null)"
	assert_eq "T8 malformed meta → null (no fatal)"     "1" "$(gj t8_malformed_null)"
	assert_eq "T9 postmeta record resolves (own data)"  "postmeta" "$(gj t9_postmeta_src)"
	assert_eq "T9 coexisting option record untouched"   "1" "$(gj t9_option_intact)"
	assert_eq "T10 missing underscore forced protected" "1" "$(gj t10_forced_protected)"
	assert_eq "T10 forced-prefix record resolves"       "1" "$(gj t10_resolves)"
	assert_eq "T11 zero entity id is a no-op"           "1" "$(gj t11_zero_entity_noop)"
	assert_eq "T11 empty rollback id → null"            "1" "$(gj t11_empty_id_null)"
fi

echo
echo "PostMetaRollbackStore keystone: PASS=$PASS FAIL=$FAIL"
[ "$FAIL" -eq 0 ]
