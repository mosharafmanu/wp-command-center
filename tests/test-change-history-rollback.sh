#!/usr/bin/env bash
#
# STEP 104.3 — Rollback Target Runtime & Historical Backfill acceptance suite.
#
# Proves the write side of the Change History system:
#
#   - rollback_discover: by change_id / change_set_id / target; missing selector;
#     each result carries the exact rollback_target params
#   - rollback_target routing: runtime_option (OperationExecutor::rollback) and
#     patch (PatchApproval::rollback, hash-verified); original stamped
#     rolled_back, one new rolled_back row recorded
#   - failure handling: not found, already rolled back, not reversible
#   - approval-aware: client mode -> pending_approval (no reversal)
#   - DestructiveGuard: reversing a high-risk-file patch -> confirmation_required,
#     then succeeds with confirmation
#   - read-only token denial (REST 403 + MCP scope error)
#   - one-time idempotent backfill from wpcc_patches + wpcc_operation_results
#   - MCP/REST parity
#
# Routes ONLY to the existing rollback engines; no new restore logic.
#
# Requires: curl, jq, wp-cli, wpcc-env.sh (full-scope $WPCC_TOKEN).
# Usage: bash tests/test-change-history-rollback.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_ROOT="$(cd "$PLUGIN_DIR/../../.." && pwd)"
PLUGINS_DIR="$WP_ROOT/wp-content/plugins"

# shellcheck source=/dev/null
source "$PLUGIN_DIR/wpcc-env.sh"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }
assert_true() { local d="$1" a="$2"; [ "$a" = "true" ] && pass "$d" || fail "$d (expected true, got '$a')"; }
assert_nonempty() { local d="$1" a="$2"; { [ -n "$a" ] && [ "$a" != "null" ]; } && pass "$d" || fail "$d (empty/null)"; }

pj()   { printf '%s' "$1" | jq -r "$2"; }
rest() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$2" "$WPCC_BASE/operations/$1/run"; }
ch()   { rest change_history "$1"; }
mcp()  { curl -s -X POST -H "Authorization: Bearer ${2:-$WPCC_TOKEN}" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/mcp"; }
mcp_text() { mcp "$1" "${2:-$WPCC_TOKEN}" | jq -r '.result.content[0].text // empty'; }
wpe()  { wp --path="$WP_ROOT" eval "$1" 2>/dev/null; }

SAVED_TITLE=""
RO_ID=""
SB="$PLUGINS_DIR/wpcc-ch-rb-sb"
DG="$PLUGINS_DIR/wpcc-ch-rb-dg"
cleanup() {
	wpe "update_option('wpcc_security_mode','developer');"
	[ -n "$SAVED_TITLE" ] && wpe "update_option('blogname', '$(printf '%s' "$SAVED_TITLE" | sed "s/'/\\\\'/g")');"
	[ -n "$RO_ID" ] && wpe "(new \WPCommandCenter\Security\AuthTokens())->revoke('$RO_ID');"
	rm -rf "$SB" "$DG"
}
trap cleanup EXIT

wpe "update_option('wpcc_security_mode','developer');"
SAVED_TITLE="$(wpe 'echo get_option("blogname");')"

echo "== 0. One-time idempotent backfill =="
BF=$(wpe '
global $wpdb; $t = $wpdb->prefix."wpcc_change_log";
delete_option("wpcc_changelog_backfilled");
\WPCommandCenter\Core\Schema::maybe_backfill_change_log();
$c1 = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t");
$f1 = (int) (get_option("wpcc_changelog_backfilled") ? 1 : 0);
// flag-guarded no-op
\WPCommandCenter\Core\Schema::maybe_backfill_change_log();
$c2 = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t");
// forced re-run with flag cleared -> deterministic ids + live-dedup => stable
delete_option("wpcc_changelog_backfilled");
\WPCommandCenter\Core\Schema::maybe_backfill_change_log();
$c3 = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t");
$bp = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t WHERE rollback_kind=\"patch\"");
echo wp_json_encode(["c1"=>$c1,"flag"=>$f1,"c2"=>$c2,"c3"=>$c3,"patch_rows"=>$bp]);
')
assert_true "backfill: flag set after run" "$(pj "$BF" '.flag == 1')"
assert_true "backfill: seeded change rows" "$(pj "$BF" '.c1 > 0')"
assert_eq "backfill: flag-guarded re-run is a no-op" "$(pj "$BF" '.c1')" "$(pj "$BF" '.c2')"
assert_eq "backfill: forced re-run inserts no duplicates" "$(pj "$BF" '.c1')" "$(pj "$BF" '.c3')"
assert_true "backfill: patch rows present (from wpcc_patches)" "$(pj "$BF" '.patch_rows > 0')"

echo
echo "== 1. Seed a reversible runtime change + a reversible patch change =="
SESS="wpcc-104-3-$(date +%s)"
rest option_manage "{\"action\":\"option_update\",\"option_id\":\"site_title\",\"value\":\"WPCC 104.3 rollback test\",\"session_id\":\"$SESS\"}" >/dev/null
LIST=$(ch "{\"action\":\"history_list\",\"runtime\":\"option\",\"status\":\"applied\",\"session_id\":\"$SESS\",\"limit\":1}")
OPT_CID=$(pj "$LIST" '.changes[0].change_id')
assert_nonempty "seed: option change_id" "$OPT_CID"

rm -rf "$SB"; mkdir -p "$SB"
printf '<?php\n$ch = "before";\n' > "$SB/f.php"
PPATH="plugins/wpcc-ch-rb-sb/f.php"
FILES=$(jq -nc --arg p "$PPATH" '[{path:$p,mode:"replace_text",find:"\"before\"",replace:"\"after\""}]')
CREATE=$(rest patch_manage "$(jq -nc --argjson f "$FILES" '{action:"patch_create",files:$f,explanation:"104.3 rb"}')")
PID=$(pj "$CREATE" '.change_set_id')
rest patch_manage "$(jq -nc --arg id "$PID" '{action:"patch_apply",patch_id:$id}')" >/dev/null
assert_eq "seed: patch applied to file" '<?php$ch = "after";' "$(tr -d '\n' < "$SB/f.php")"
PLIST=$(ch "{\"action\":\"history_list\",\"change_set_id\":\"$PID\",\"status\":\"applied\",\"limit\":1}")
PATCH_CID=$(pj "$PLIST" '.changes[0].change_id')
assert_nonempty "seed: patch change_id" "$PATCH_CID"

echo
echo "== 2. rollback_discover =="
D1=$(ch "{\"action\":\"rollback_discover\",\"change_id\":\"$OPT_CID\"}")
assert_eq "discover: by change_id action" "rollback_discover" "$(pj "$D1" '.action')"
assert_true "discover: finds the runtime change" "$(pj "$D1" '.total_count >= 1')"
assert_eq "discover: kind runtime_option" "runtime_option" "$(pj "$D1" '.reversible_changes[0].rollback.kind')"
assert_eq "discover: provides rollback_target params" "$OPT_CID" "$(pj "$D1" '.reversible_changes[0].rollback_target.parameters.change_id')"
assert_eq "discover: rollback_target operation" "change_history" "$(pj "$D1" '.reversible_changes[0].rollback_target.operation')"

D2=$(ch "{\"action\":\"rollback_discover\",\"change_set_id\":\"$PID\"}")
assert_true "discover: by change_set_id finds the patch" "$(pj "$D2" '.total_count >= 1')"
assert_eq "discover: patch kind" "patch" "$(pj "$D2" '.reversible_changes[0].rollback.kind')"

D3=$(ch "{\"action\":\"rollback_discover\",\"target\":\"$PPATH\"}")
assert_true "discover: by target path finds the patch" "$(pj "$D3" '.total_count >= 1')"

D4=$(ch '{"action":"rollback_discover"}')
assert_eq "discover: missing selector error" "wpcc_missing_rollback_selector" "$(pj "$D4" '.code')"

echo
echo "== 3. rollback_target — runtime_option =="
RT1=$(ch "{\"action\":\"rollback_target\",\"change_id\":\"$OPT_CID\"}")
assert_true "rt runtime: success" "$(pj "$RT1" '.success')"
assert_eq "rt runtime: kind" "runtime_option" "$(pj "$RT1" '.rollback_kind')"
assert_eq "rt runtime: option value restored" "$SAVED_TITLE" "$(wpe 'echo get_option("blogname");')"
NEW1=$(pj "$RT1" '.rolled_back_by')
assert_nonempty "rt runtime: reversal change_id" "$NEW1"
G1=$(ch "{\"action\":\"history_get\",\"change_id\":\"$OPT_CID\"}")
assert_eq "rt runtime: original stamped rolled_back" "rolled_back" "$(pj "$G1" '.change.status')"
assert_eq "rt runtime: original links to reverser" "$NEW1" "$(pj "$G1" '.change.rollback.rolled_back_by_change_id')"
GN=$(ch "{\"action\":\"history_get\",\"change_id\":\"$NEW1\"}")
assert_eq "rt runtime: reversal row status" "rolled_back" "$(pj "$GN" '.change.status')"
assert_eq "rt runtime: reversal row action" "rollback_target" "$(pj "$GN" '.change.action')"
assert_eq "rt runtime: reversal reverts original" "$OPT_CID" "$(pj "$GN" '.change.target_summary.reverts_change_id')"

echo
echo "== 4. rollback_target — patch (hash-verified) =="
RT2=$(ch "{\"action\":\"rollback_target\",\"change_id\":\"$PATCH_CID\"}")
assert_true "rt patch: success" "$(pj "$RT2" '.success')"
assert_eq "rt patch: kind" "patch" "$(pj "$RT2" '.rollback_kind')"
assert_eq "rt patch: file restored byte-for-byte" '<?php$ch = "before";' "$(tr -d '\n' < "$SB/f.php")"
assert_true "rt patch: snapshot hash verified" "$(pj "$RT2" '[.engine_result.rollback_results[]?.checks.restored_hash_matches] | all')"
GP=$(ch "{\"action\":\"history_get\",\"change_id\":\"$PATCH_CID\"}")
assert_eq "rt patch: original stamped rolled_back" "rolled_back" "$(pj "$GP" '.change.status')"

echo
echo "== 5. Failure handling =="
F1=$(ch '{"action":"rollback_target","change_id":"00000000-0000-0000-0000-000000000000"}')
assert_eq "fail: unknown change_id" "wpcc_change_not_found" "$(pj "$F1" '.code')"
F2=$(ch "{\"action\":\"rollback_target\",\"change_id\":\"$OPT_CID\"}")
assert_eq "fail: already rolled back" "wpcc_already_rolled_back" "$(pj "$F2" '.code')"
# A failed (non-reversible) change.
rest content_manage '{"action":"content_update","content_id":999999321,"title":"x"}' >/dev/null
FAILED_CID=$(pj "$(ch '{"action":"history_list","operation_id":"content_manage","status":"failed","limit":1}')" '.changes[0].change_id')
F3=$(ch "{\"action\":\"rollback_target\",\"change_id\":\"$FAILED_CID\"}")
assert_eq "fail: not reversible" "wpcc_not_reversible" "$(pj "$F3" '.code')"

echo
echo "== 6. Approval-aware (client mode) =="
rest option_manage "{\"action\":\"option_update\",\"option_id\":\"site_title\",\"value\":\"WPCC approval seed\"}" >/dev/null
AP_CID=$(pj "$(ch '{"action":"history_list","runtime":"option","status":"applied","limit":1}')" '.changes[0].change_id')
wpe 'update_option("wpcc_security_mode","client");'
AP=$(ch "{\"action\":\"rollback_target\",\"change_id\":\"$AP_CID\"}")
assert_eq "approval: client mode returns pending_approval" "pending_approval" "$(pj "$AP" '.status')"
assert_eq "approval: high risk" "high" "$(pj "$AP" '.risk_level')"
assert_nonempty "approval: request_id issued" "$(pj "$AP" '.request_id')"
assert_eq "approval: not yet reverted" "WPCC approval seed" "$(wpe 'echo get_option("blogname");')"
wpe 'update_option("wpcc_security_mode","developer");'
# Clean up that seeded change (developer-mode rollback).
ch "{\"action\":\"rollback_target\",\"change_id\":\"$AP_CID\"}" >/dev/null

echo
echo "== 7. DestructiveGuard — reversing a high-risk-file patch =="
rm -rf "$DG"; mkdir -p "$DG"
printf '<?php\n/**\n * Plugin Name: WPCC CH RB DG\n */\n$x = "v1";\n' > "$DG/wpcc-ch-rb-dg.php"
DGPATH="plugins/wpcc-ch-rb-dg/wpcc-ch-rb-dg.php"
DGFILES=$(jq -nc --arg p "$DGPATH" '[{path:$p,mode:"replace_text",find:"\"v1\"",replace:"\"v2\""}]')
DGPID=$(pj "$(rest patch_manage "$(jq -nc --argjson f "$DGFILES" '{action:"patch_create",files:$f,explanation:"dg"}')")" '.change_set_id')
rest patch_manage "$(jq -nc --arg id "$DGPID" '{action:"patch_apply",patch_id:$id,confirm:true,confirmation_phrase:"APPLY_PATCH",reason:"seed"}')" >/dev/null
DG_LIST=$(ch "{\"action\":\"history_list\",\"change_set_id\":\"$DGPID\",\"status\":\"applied\",\"limit\":1}")
DG_CID=$(pj "$DG_LIST" '.changes[0].change_id')
DGR1=$(ch "{\"action\":\"rollback_target\",\"change_id\":\"$DG_CID\"}")
assert_eq "guard: high-risk rollback requires confirmation" "confirmation_required" "$(pj "$DGR1" '.status')"
assert_eq "guard: confirmation phrase ROLLBACK_CHANGE" "ROLLBACK_CHANGE" "$(pj "$DGR1" '.confirmation_phrase')"
assert_eq "guard: file NOT yet reverted" '"v2"' "$(grep -o '"v[0-9]"' "$DG/wpcc-ch-rb-dg.php")"
DGR2=$(ch "{\"action\":\"rollback_target\",\"change_id\":\"$DG_CID\",\"confirm\":true,\"confirmation_phrase\":\"ROLLBACK_CHANGE\",\"reason\":\"revert\"}")
assert_true "guard: succeeds with confirmation" "$(pj "$DGR2" '.success')"
assert_eq "guard: file reverted after confirmation" '"v1"' "$(grep -o '"v[0-9]"' "$DG/wpcc-ch-rb-dg.php")"

echo
echo "== 8. Read-only token denial =="
RES=$(wpe '
$auth = new \WPCommandCenter\Security\AuthTokens();
$r = $auth->create( "104.3 RO", \WPCommandCenter\Security\AuthTokens::SCOPE_READ_ONLY, null, 1 );
echo is_wp_error($r) ? "" : $r["token"]." ".$r["record"]["id"];
')
RO_TOKEN="$(echo "$RES" | cut -d' ' -f1)"; RO_ID="$(echo "$RES" | cut -d' ' -f2)"
assert_nonempty "ro: token created" "$RO_TOKEN"
# RO can discover (read) ...
RO_DISC=$(curl -s -X POST -H "Authorization: Bearer $RO_TOKEN" -H "Content-Type: application/json" -d "{\"action\":\"rollback_discover\",\"change_set_id\":\"$PID\"}" "$WPCC_BASE/operations/change_history/run")
assert_eq "ro: can rollback_discover" "rollback_discover" "$(pj "$RO_DISC" '.action')"
# ... but cannot rollback_target (REST 403)
RO_RT=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Bearer $RO_TOKEN" -H "Content-Type: application/json" -d "{\"action\":\"rollback_target\",\"change_id\":\"$PATCH_CID\"}" "$WPCC_BASE/operations/change_history/run")
assert_eq "ro: rollback_target denied (REST 403)" "403" "$RO_RT"
# MCP scope error
RO_MCP=$(mcp_text "{\"jsonrpc\":\"2.0\",\"id\":9,\"method\":\"tools/call\",\"params\":{\"name\":\"change_history\",\"arguments\":{\"action\":\"rollback_target\",\"change_id\":\"$PATCH_CID\"}}}" "$RO_TOKEN")
assert_eq "ro: rollback_target denied (MCP scope)" "wpcc_token_read_only" "$(pj "$RO_MCP" '.code')"

echo
echo "== 9. MCP / REST parity (rollback_discover) =="
RR=$(ch "{\"action\":\"rollback_discover\",\"change_set_id\":\"$PID\"}")
RM=$(mcp_text "{\"jsonrpc\":\"2.0\",\"id\":10,\"method\":\"tools/call\",\"params\":{\"name\":\"change_history\",\"arguments\":{\"action\":\"rollback_discover\",\"change_set_id\":\"$PID\",\"context_mode\":\"verbose\"}}}")
assert_eq "parity: REST total_count == MCP total_count" "$(pj "$RR" '.total_count')" "$(pj "$RM" '.total_count')"

echo
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"

[ "$FAIL" -eq 0 ]
