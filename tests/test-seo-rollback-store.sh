#!/usr/bin/env bash
#
# GA#2 — Slice 4c: SEO Rollback Store Hardening (Option B).
#
# Asserts SEO rollback snapshots are stored as dedicated protected post-meta rows
# (`_wpcc_seo_rb_{rollback_id}`), one row per rollback — no global cap, no FIFO
# eviction, not autoloaded, and no growth of the legacy `wpcc_seo_rollbacks` option
# for new writes. seo_restore resolves by rollback_id ALONE (the dispatch contract
# passes no post_id), restores before_state, marks rollback_applied=true (record
# kept), and falls back to the legacy option for pre-4c records. The rollback_id
# contract and the Change History rollback path are unchanged. Invariants frozen.
#
# Backend-only hardening: the ONLY production file touched is SeoRuntimeManager.php.
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

echo "GA#2 — Slice 4c: SEO Rollback Store Hardening"

echo
echo "== 1. Source: per-post-meta store, no cap, no new option writes =="
has  "protected meta-key prefix constant"   "ROLLBACK_META_PREFIX  = '_wpcc_seo_rb_'" "$SRC"
has  "store writes post meta"               "add_post_meta( \$post_id, self::ROLLBACK_META_PREFIX" "$SRC"
has  "restore resolves by indexed meta_key" "WHERE meta_key = %s LIMIT 1" "$SRC"
has  "restore reads via get_post_meta"      "get_post_meta( \$post_id, \$meta_key, true )" "$SRC"
has  "restore marks applied in meta"        "update_post_meta( \$post_id, \$meta_key, \$record )" "$SRC"
has  "legacy fallback method present"       "function seo_restore_legacy" "$SRC"
has  "legacy fallback still marks option"   "update_option( self::LEGACY_ROLLBACK_OPTION" "$SRC"
lacks "no global FIFO cap (array_slice -100)" "array_slice( \$rollbacks, -100 )" "$SRC"
lacks "store_rollback no longer writes the option literal" "update_option( 'wpcc_seo_rollbacks'" "$SRC"

echo
echo "== 2. Functional: write / restore-by-id / idempotency / fallback / no-evict =="
if ! command -v wp >/dev/null 2>&1; then
	echo "  SKIP: wp-cli not available — static checks only."
else
	RES="$(wpe '
		global $wpdb;
		$a=get_users(["role"=>"administrator","number"=>1]); wp_set_current_user($a?$a[0]->ID:1);
		$out = [];
		$mgr = new \WPCommandCenter\Operations\SeoRuntimeManager();

		if ( \WPCommandCenter\Operations\SeoProvider::NONE === \WPCommandCenter\Operations\SeoProvider::detect() ) { $out["skip"]="no_seo_plugin"; echo wp_json_encode($out); return; }
		$prov = \WPCommandCenter\Operations\SeoProvider::detect();

		$prefix = "_wpcc_seo_rb_";
		$meta_cnt = function() use ($wpdb,$prefix){ return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key LIKE \"".$wpdb->esc_like($prefix)."%\"" ); };

		// --- baselines ---
		$opt_before      = get_option("wpcc_seo_rollbacks", []);
		$opt_cnt_before  = is_array($opt_before) ? count($opt_before) : 0;

		// === 2a. seo_update creates a per-post meta row; option does not grow ===
		$pid = wp_insert_post(["post_title"=>"WPCC 4c store test","post_status"=>"publish","post_type"=>"post","post_content"=>"x"]);
		\WPCommandCenter\Operations\SeoProvider::write($pid, ["title"=>"Original Title"], $prov);

		$u = $mgr->run(["action"=>"seo_update","content_id"=>$pid,"seo"=>["title"=>"Applied Title","description"=>str_repeat("desc sentence. ",10)]], []);
		$rid = (string)($u["rollback_id"] ?? "");
		$out["got_rollback_id"]   = ( "" !== $rid ) ? 1 : 0;
		$rec = get_post_meta($pid, $prefix.$rid, true);
		// Phase 3 (F-1): record is now a field-scoped delta (version 2, `fields` map) —
		// no full `before_state`. The touched title field stores its backing-key prior.
		$tkey = \WPCommandCenter\Operations\SeoProvider::meta_key("title", $prov);
		$out["meta_row_created"]  = ( is_array($rec) && ($rec["id"]??"")===$rid && (int)($rec["version"]??0)===2 && (($rec["fields"]["title"]["keys"][$tkey]["prior"]??"")==="Original Title") ) ? 1 : 0;
		$out["meta_applied_false"]= ( empty($rec["rollback_applied"]) ) ? 1 : 0;
		$out["option_not_grown"]  = ( count(get_option("wpcc_seo_rollbacks", [])) === $opt_cnt_before ) ? 1 : 0;

		// === 2b. restore by rollback_id ALONE (no post_id passed) ===
		$r = $mgr->run(["action"=>"seo_restore","rollback_id"=>$rid], []);
		$out["restore_ok"]        = ( !empty($r["restored"]) ) ? 1 : 0;
		$out["restore_post_id"]   = ( (int)($r["post_id"]??0) === $pid ) ? 1 : 0;
		$seo = \WPCommandCenter\Operations\SeoProvider::read($pid, $prov);
		$out["before_state_restored"] = ( ($seo["title"]??"") === "Original Title" ) ? 1 : 0;

		// === 2c. idempotency: second restore fails safe; record kept applied=true ===
		$r2 = $mgr->run(["action"=>"seo_restore","rollback_id"=>$rid], []);
		$out["second_restore_blocked"] = ( !empty($r2["error"]) && ($r2["code"]??"")==="wpcc_rollback_already_applied" ) ? 1 : 0;
		$rec2 = get_post_meta($pid, $prefix.$rid, true);
		$out["record_kept_applied"] = ( is_array($rec2) && !empty($rec2["rollback_applied"]) ) ? 1 : 0;

		// === 2d. legacy option fallback: seed an old-style record, restore it ===
		$lpid = wp_insert_post(["post_title"=>"WPCC 4c legacy test","post_status"=>"publish","post_type"=>"post","post_content"=>"x"]);
		\WPCommandCenter\Operations\SeoProvider::write($lpid, ["title"=>"Legacy Current"], $prov);
		$lrid = wp_generate_uuid4();
		$legacy = get_option("wpcc_seo_rollbacks", []);
		$legacy[] = ["id"=>$lrid,"post_id"=>$lpid,"provider"=>$prov,"before_state"=>["title"=>"Legacy Original"],"rollback_applied"=>false,"created_at"=>time(),"session_id"=>null,"task_id"=>null];
		update_option("wpcc_seo_rollbacks", $legacy);
		$lr = $mgr->run(["action"=>"seo_restore","rollback_id"=>$lrid], []);
		$out["legacy_restore_ok"] = ( !empty($lr["restored"]) ) ? 1 : 0;
		$lseo = \WPCommandCenter\Operations\SeoProvider::read($lpid, $prov);
		$out["legacy_before_restored"] = ( ($lseo["title"]??"") === "Legacy Original" ) ? 1 : 0;
		$lopt = get_option("wpcc_seo_rollbacks", []);
		$lmark = 0; foreach ($lopt as $row){ if (($row["id"]??"")===$lrid){ $lmark = !empty($row["rollback_applied"]) ? 1 : 0; } }
		$out["legacy_marked_applied"] = $lmark;

		// === 2e. no cap / no eviction: cross the old 100 boundary on one post ===
		$cpid = wp_insert_post(["post_title"=>"WPCC 4c nocap test","post_status"=>"publish","post_type"=>"post","post_content"=>"x"]);
		$first_rid = "";
		for ($i=0; $i<103; $i++){
			$cu = $mgr->run(["action"=>"seo_update","content_id"=>$cpid,"seo"=>["title"=>"T".$i]], []);
			if ($i===0){ $first_rid = (string)($cu["rollback_id"] ?? ""); }
		}
		$cnt_on_post = (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key LIKE %s", $cpid, $wpdb->esc_like($prefix)."%") );
		$out["no_evict_103_rows"] = ( $cnt_on_post === 103 ) ? 1 : 0;     // legacy store would cap at 100
		$rfirst = $mgr->run(["action"=>"seo_restore","rollback_id"=>$first_rid], []);
		// Phase 3 (F-1): the oldest record is NOT evicted, so it still RESOLVES. Because
		// 102 newer changes touched the same (title) field, a field-scoped restore now
		// correctly reports drift (conflict) instead of clobbering — a not-found code
		// would mean eviction. Resolvability (not clean restore) is the no-evict proof.
		$out["oldest_still_resolvable"] = ( ($rfirst["code"]??"") !== "wpcc_rollback_not_found" ) ? 1 : 0;

		// === 2f. no autoload-option dependency: option count unchanged by new writes ===
		// (2d added exactly one legacy seed; new 4c writes must not have grown it further.)
		$out["option_only_seed_growth"] = ( count(get_option("wpcc_seo_rollbacks", [])) === ($opt_cnt_before + 1) ) ? 1 : 0;

		// cleanup
		foreach ([$pid,$lpid,$cpid] as $id){ wp_delete_post($id, true); }
		// remove our legacy seed to leave the option as we found it
		$clean = array_values( array_filter( get_option("wpcc_seo_rollbacks", []), fn($r)=>($r["id"]??"")!==$lrid ) );
		update_option("wpcc_seo_rollbacks", $clean);

		echo wp_json_encode($out);
	')"
	gj() { printf '%s' "$RES" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["'"$1"'"] ?? "";' 2>/dev/null; }
	if [ "$(gj skip)" = "no_seo_plugin" ]; then
		echo "  NOTE: no SEO plugin active on this env — store functional path skipped."
	else
		assert_eq "seo_update returns rollback_id"               "1" "$(gj got_rollback_id)"
		assert_eq "per-post meta row created (v2 delta, field prior)" "1" "$(gj meta_row_created)"
		assert_eq "new record rollback_applied=false"            "1" "$(gj meta_applied_false)"
		assert_eq "legacy option NOT grown by new write"         "1" "$(gj option_not_grown)"
		assert_eq "restore by rollback_id alone succeeds"        "1" "$(gj restore_ok)"
		assert_eq "restore resolved correct post_id"             "1" "$(gj restore_post_id)"
		assert_eq "before_state restored to post"                "1" "$(gj before_state_restored)"
		assert_eq "second restore blocked (already_applied)"     "1" "$(gj second_restore_blocked)"
		assert_eq "record kept with rollback_applied=true"       "1" "$(gj record_kept_applied)"
		assert_eq "legacy option fallback restore succeeds"      "1" "$(gj legacy_restore_ok)"
		assert_eq "legacy before_state restored"                 "1" "$(gj legacy_before_restored)"
		assert_eq "legacy record marked applied in option"       "1" "$(gj legacy_marked_applied)"
		assert_eq "no eviction: 103 meta rows on one post"       "1" "$(gj no_evict_103_rows)"
		assert_eq "oldest (1st) rollback still resolvable (no evict)" "1" "$(gj oldest_still_resolvable)"
		assert_eq "new writes never grow the autoloaded option"  "1" "$(gj option_only_seed_growth)"
	fi
fi

echo
echo "== 3. Invariants unchanged =="
assert_eq "OPERATION_MAP == 34" "34" "$(wpe 'echo count(\WPCommandCenter\Operations\CapabilityRegistry::OPERATION_MAP);')"
assert_eq "capabilities == 23"  "23" "$(wpe 'echo count(\WPCommandCenter\Operations\CapabilityRegistry::ALL_CAPABILITIES);')"
assert_eq "catalogue == 40"     "40" "$(wpe 'echo count((new \WPCommandCenter\Operations\OperationRegistry())->get_operations());')"
assert_eq "DB_VERSION 2.5.0"    "2.5.0" "$(wpe 'echo \WPCommandCenter\Core\Schema::DB_VERSION;')"

echo ""
echo "RESULT: ${PASS} passed, ${FAIL} failed"
[ "$FAIL" -eq 0 ]
