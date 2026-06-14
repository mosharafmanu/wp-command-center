#!/usr/bin/env bash
#
# F3.1 — acf_group_delete false-success remediation.
#
# Report: acf_group_delete returned success but the group persisted (3x verified).
# Root cause: handler returned success unconditionally; on acf-json-synced installs
# the deleted DB post was re-registered from the JSON file on the next request.
#
# Fix verified here: delete now (a) removes the runtime-owned acf-json file in
# ACF's save path so it can't resurrect, (b) reports failure when the group would
# persist from a read-only local source, and (c) never reports success unless a
# follow-up read confirms the group is gone. Rollback restores the group.
#
# Requires: curl, jq, wp, wpcc-env.sh, ACF.
# Usage: bash tests/test-acf-group-delete-f31.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
# shellcheck source=/dev/null
source "$PLUGIN_DIR/wpcc-env.sh"
WP_PATH="$(cd "$SCRIPT_DIR/../../../.." && pwd)"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }
assert_nonempty() { local d="$1" a="$2"; { [ -n "$a" ] && [ "$a" != "null" ]; } && pass "$d" || fail "$d (empty/null)"; }
acf() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/operations/acf_manage/run"; }
acfmcp() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/mcp" | jq -r '.result.content[0].text // empty'; }
acfrb() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/operations/acf_manage/rollback"; }
wpe() { wp eval "$1" --path="$WP_PATH" 2>/dev/null; }

GIDS=""
SAVE_DIR=$(wpe '$s=acf_get_setting("save_json"); echo is_string($s)?untrailingslashit($s):"";')
cleanup() {
  for g in $GIDS; do
    wpe '$x=acf_get_field_group("'"$g"'"); if($x && !empty($x["ID"])) wp_delete_post($x["ID"],true);'
    [ -n "$SAVE_DIR" ] && rm -f "$SAVE_DIR/$g.json"
  done
  wpe 'acf_remove_local_field_group("group_f31_local");'
  # Safety net: if Test 3 left the acf-json dir, remove it to keep sync OFF.
  [ -n "$SAVE_DIR" ] && rm -f "$SAVE_DIR"/group_*.json 2>/dev/null && rmdir "$SAVE_DIR" 2>/dev/null
  true
}
trap cleanup EXIT

echo "== 1. Normal delete (REST) verifies the group is actually gone =="
C=$(acf '{"action":"acf_group_create","title":"F31 Normal"}')
G1=$(echo "$C" | jq -r '.group_id'); GIDS="$GIDS $G1"
assert_nonempty "group created" "$G1"
assert_eq "group exists before delete" "$G1" "$(acf "$(jq -n --arg g "$G1" '{action:"acf_group_get",group_id:$g}')" | jq -r '.group.key // "none"')"
D=$(acf "$(jq -n --arg g "$G1" '{action:"acf_group_delete",group_id:$g}')")
assert_eq "delete reports deleted:true" "true" "$(echo "$D" | jq -r '.deleted')"
assert_nonempty "delete returns rollback_id (was missing)" "$(echo "$D" | jq -r '.rollback_id')"
assert_eq "FOLLOW-UP READ confirms gone" "wpcc_acf_group_not_found" "$(acf "$(jq -n --arg g "$G1" '{action:"acf_group_get",group_id:$g}')" | jq -r '.code // "none"')"

echo "== 2. Rollback restores the deleted group =="
RID=$(echo "$D" | jq -r '.rollback_id')
acfrb "$(jq -n --arg r "$RID" '{rollback_id:$r}')" >/dev/null
assert_eq "group restored after rollback" "F31 Normal" "$(acf "$(jq -n --arg g "$G1" '{action:"acf_group_get",group_id:$g}')" | jq -r '.group.title // "none"')"

echo "== 3. Production scenario: acf-json-synced group is truly removed (the fix) =="
# Hermetic: the whole acf-json simulation lives in ONE process (create group BEFORE
# the dir exists so auto-sync stays off; write the JSON by hand to simulate sync;
# delete; then remove the dir) so it never leaks acf-json auto-sync into the REST
# tests. Asserts the handler purges the owned JSON so the group cannot resurrect.
T3=$(wpe '
$mgr=new \WPCommandCenter\Operations\ACFRuntimeManager();
$c=$mgr->run(["action"=>"acf_group_create","title"=>"F31 Synced"]); $gid=$c["group_id"];
$save=untrailingslashit(acf_get_setting("save_json")); if(!is_dir($save)) wp_mkdir_p($save);
$jf=$save."/".$gid.".json"; file_put_contents($jf, wp_json_encode(acf_get_field_group($gid)));
$before=is_file($jf)?"yes":"no";
$d=$mgr->run(["action"=>"acf_group_delete","group_id"=>$gid]);
$deleted=($d["deleted"]??false)?"true":"false";
$after=is_file($jf)?"yes":"no";
$gone=acf_get_field_group($gid)?"no":"yes";
if(empty($d["deleted"])){ $x=acf_get_field_group($gid); if($x && !empty($x["ID"])) wp_delete_post($x["ID"],true); }
@unlink($jf); @rmdir($save);
echo $before."|".$deleted."|".$after."|".$gone;')
assert_eq "acf-json file present before delete" "yes" "$(echo "$T3" | cut -d'|' -f1)"
assert_eq "synced group delete reports deleted:true" "true" "$(echo "$T3" | cut -d'|' -f2)"
assert_eq "owned acf-json file removed (no resurrection)" "no" "$(echo "$T3" | cut -d'|' -f3)"
assert_eq "group gone on follow-up read" "yes" "$(echo "$T3" | cut -d'|' -f4)"

echo "== 4. False-success guard: undeletable (read-only local) group reports FAILURE, not success =="
# A purely-local group (no DB post) cannot be deleted; the OLD handler returned
# success while it persisted. Registration + delete + recheck run in ONE process
# (acf_add_local_field_group is process-local). The exact documented bug class.
GUARD=$(wpe '
acf_add_local_field_group(["key"=>"group_f31_local","title"=>"F31 Local RO","fields"=>[],"location"=>[]]);
$r=(new \WPCommandCenter\Operations\ACFRuntimeManager())->run(["action"=>"acf_group_delete","group_id"=>"group_f31_local"]);
$code = $r["code"] ?? ((($r["deleted"]??false)) ? "FALSE_SUCCESS" : "ok");
$still = acf_get_field_group("group_f31_local") ? "present" : "absent";
echo $code."|".$still;')
assert_eq "undeletable group returns failure code" "wpcc_acf_group_delete_failed" "$(echo "$GUARD" | cut -d'|' -f1)"
assert_eq "group indeed still present (no data leak masked)" "present" "$(echo "$GUARD" | cut -d'|' -f2)"

echo "== 5. MCP parity — delete + verify gone over MCP =="
C3=$(acf '{"action":"acf_group_create","title":"F31 MCP"}')
G3=$(echo "$C3" | jq -r '.group_id'); GIDS="$GIDS $G3"
DM=$(acfmcp "$(jq -n --arg g "$G3" '{jsonrpc:"2.0",id:1,method:"tools/call",params:{name:"acf_manage",arguments:{action:"acf_group_delete",group_id:$g}}}')")
assert_eq "MCP delete reports deleted:true" "true" "$(echo "$DM" | jq -r '.deleted')"
assert_eq "MCP follow-up read confirms gone" "wpcc_acf_group_not_found" "$(acfmcp "$(jq -n --arg g "$G3" '{jsonrpc:"2.0",id:2,method:"tools/call",params:{name:"acf_manage",arguments:{action:"acf_group_get",group_id:$g}}}')" | jq -r '.code // "none"')"

echo "== 6. Structured error — delete a non-existent group =="
assert_eq "delete unknown group" "wpcc_acf_group_not_found" "$(acf '{"action":"acf_group_delete","group_id":"group_does_not_exist"}' | jq -r '.code // "none"')"

echo
echo "================================================"
echo "  ACF group_delete F3.1: $PASS passed, $FAIL failed"
echo "================================================"
[ "$FAIL" -eq 0 ]
