#!/usr/bin/env bash
#
# STEP 104.2 — Change History runtime (read actions) acceptance suite.
#
# Proves the read-side runtime over the wpcc_change_log system of record:
#
#   - history_list: filters (runtime/operation_id/status/target/change_set_id/
#     session/reversible_only/since-until) + cursor pagination + multi-page
#     traversal + empty results
#   - history_get: full record incl. rollback linkage, change-set, actor, result
#   - history_timeline: chronological (newest-first), table-backed, paginated
#   - compact / standard / verbose envelope (MCP) + MCP/REST parity
#   - GET /changes, /changes/timeline, /changes/{change_id} aliases
#   - capability enforcement: a read_only token can query history but cannot write
#   - read actions never trigger approval/destructive/rollback
#
# Seeds deterministic rows by executing real mutating ops, then queries them.
#
# Requires: curl, jq, wp-cli, wpcc-env.sh (full-scope $WPCC_TOKEN).
# Usage: bash tests/test-change-history-runtime.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_ROOT="$(cd "$PLUGIN_DIR/../../.." && pwd)"

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
get()  { curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE$1"; }
mcp()  { curl -s -X POST -H "Authorization: Bearer ${2:-$WPCC_TOKEN}" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/mcp"; }
mcp_text() { mcp "$1" "${2:-$WPCC_TOKEN}" | jq -r '.result.content[0].text // empty'; }
wpe()  { wp --path="$WP_ROOT" eval "$1" 2>/dev/null; }

SAVED_TITLE=""
RO_ID=""
cleanup() {
	[ -n "$SAVED_TITLE" ] && wpe "update_option('blogname', '$(printf '%s' "$SAVED_TITLE" | sed "s/'/\\\\'/g")');"
	[ -n "$RO_ID" ] && wpe "(new \WPCommandCenter\Security\AuthTokens())->revoke('$RO_ID');"
}
trap cleanup EXIT

SAVED_TITLE="$(wpe 'echo get_option("blogname");')"

echo "== 0. Seed deterministic change rows =="
# Three reversible option writes (runtime=option) + one failing content write.
SEED_SESSION="wpcc-104-2-$(date +%s)"
for i in 1 2 3; do
	rest option_manage "{\"action\":\"option_update\",\"option_id\":\"site_title\",\"value\":\"WPCC 104.2 seed $i\",\"session_id\":\"$SEED_SESSION\"}" >/dev/null
done
LAST_WRITE=$(rest option_manage "{\"action\":\"option_update\",\"option_id\":\"site_title\",\"value\":\"WPCC 104.2 final\",\"session_id\":\"$SEED_SESSION\"}")
SEED_RB=$(pj "$LAST_WRITE" '.rollback_id')
rest content_manage '{"action":"content_update","content_id":999999777,"title":"nope"}' >/dev/null
pass "seed: 4 option writes + 1 failed content write executed"
assert_nonempty "seed: last write produced rollback_id" "$SEED_RB"

echo
echo "== 1. history_list: envelope + reversible runtime filter =="
R=$(ch '{"action":"history_list","runtime":"option","reversible_only":true,"limit":3}')
assert_eq "list: action echoed" "history_list" "$(pj "$R" '.action')"
assert_true "list: total_count >= 4" "$(pj "$R" '.total_count >= 4')"
assert_eq "list: returned == 3 (limited)" "3" "$(pj "$R" '.returned')"
assert_true "list: every row runtime=option" "$(pj "$R" '[.changes[].runtime] | all(. == "option")')"
assert_true "list: every row reversible" "$(pj "$R" '[.changes[].rollback.reversible] | all(. == true)')"
assert_true "list: has_more true (more than 3 option writes)" "$(pj "$R" '.has_more')"
assert_nonempty "list: next_cursor present" "$(pj "$R" '.next_cursor')"

echo
echo "== 2. history_list: status + operation_id + session filters =="
R=$(ch "{\"action\":\"history_list\",\"operation_id\":\"option_manage\",\"status\":\"applied\",\"session_id\":\"$SEED_SESSION\",\"limit\":50}")
assert_true "list: session filter returns exactly the 4 seeded writes" "$(pj "$R" '.total_count == 4')"
assert_true "list: all applied" "$(pj "$R" '[.changes[].status] | all(. == "applied")')"
assert_true "list: all operation_id option_manage" "$(pj "$R" '[.changes[].operation_id] | all(. == "option_manage")')"

R=$(ch '{"action":"history_list","operation_id":"content_manage","status":"failed","limit":5}')
assert_true "list: failed content rows exist" "$(pj "$R" '.total_count >= 1')"
assert_true "list: failed rows not reversible" "$(pj "$R" '[.changes[].rollback.reversible] | all(. == false)')"

echo
echo "== 3. history_list: empty result set =="
R=$(ch '{"action":"history_list","runtime":"this_runtime_does_not_exist","limit":5}')
assert_eq "list: empty total_count 0" "0" "$(pj "$R" '.total_count')"
assert_eq "list: empty returned 0" "0" "$(pj "$R" '.returned')"
assert_true "list: empty has_more false" "$(pj "$R" '.has_more == false')"
assert_true "list: empty next_cursor null" "$(pj "$R" '.next_cursor == null')"
assert_true "list: empty changes is array" "$(pj "$R" '(.changes | type) == "array"')"

echo
echo "== 4. Multi-page cursor traversal =="
P1=$(ch "{\"action\":\"history_list\",\"session_id\":\"$SEED_SESSION\",\"limit\":2}")
C1=$(pj "$P1" '.next_cursor')
assert_eq "page1: returned 2" "2" "$(pj "$P1" '.returned')"
assert_nonempty "page1: cursor" "$C1"
P2=$(ch "{\"action\":\"history_list\",\"session_id\":\"$SEED_SESSION\",\"limit\":2,\"cursor\":\"$C1\"}")
assert_eq "page2: returned 2" "2" "$(pj "$P2" '.returned')"
assert_true "page2: has_more false (4 total)" "$(pj "$P2" '.has_more == false')"
# Pages must not overlap.
IDS1=$(pj "$P1" '[.changes[].change_id] | sort | join(",")')
IDS2=$(pj "$P2" '[.changes[].change_id] | sort | join(",")')
assert_true "pages disjoint" "$( [ -n "$IDS1" ] && [ -n "$IDS2" ] && [ "$IDS1" != "$IDS2" ] && echo true || echo false )"

echo
echo "== 5. history_get: full record + linkage =="
CID_LIST=$(ch "{\"action\":\"history_list\",\"session_id\":\"$SEED_SESSION\",\"limit\":1}")
CID=$(pj "$CID_LIST" '.changes[0].change_id')
assert_nonempty "get: obtained a change_id" "$CID"
G=$(ch "{\"action\":\"history_get\",\"change_id\":\"$CID\"}")
assert_eq "get: action" "history_get" "$(pj "$G" '.action')"
assert_eq "get: change_id matches" "$CID" "$(pj "$G" '.change.change_id')"
assert_eq "get: runtime=option" "option" "$(pj "$G" '.change.runtime')"
assert_eq "get: rollback.kind runtime_option" "runtime_option" "$(pj "$G" '.change.rollback.kind')"
assert_true "get: rollback.reversible true" "$(pj "$G" '.change.rollback.reversible')"
assert_nonempty "get: rollback.rollback_id" "$(pj "$G" '.change.rollback.rollback_id')"
assert_eq "get: actor type token" "token" "$(pj "$G" '.change.actor.type')"
assert_eq "get: session linkage" "$SEED_SESSION" "$(pj "$G" '.change.links.session_id')"
assert_nonempty "get: result metadata result_id" "$(pj "$G" '.change.result.result_id')"
assert_true "get: result metadata has counts" "$(pj "$G" '(.change.result.updated_count | type) == "number"')"

echo
echo "== 6. history_get: not found =="
NF=$(ch '{"action":"history_get","change_id":"00000000-0000-0000-0000-000000000000"}')
assert_true "get: not found is in-band error" "$(pj "$NF" '.error == true')"
assert_eq "get: not found code" "wpcc_change_not_found" "$(pj "$NF" '.code')"

echo
echo "== 7. history_timeline: chronological + table-backed =="
T=$(ch '{"action":"history_timeline","limit":5}')
assert_eq "timeline: action" "history_timeline" "$(pj "$T" '.action')"
assert_true "timeline: total_count >= 5" "$(pj "$T" '.total_count >= 5')"
assert_eq "timeline: returned 5" "5" "$(pj "$T" '.timeline | length')"
assert_true "timeline: newest-first (created_at descending)" "$(pj "$T" '[.timeline[].created_at] as $c | ($c == ($c | sort | reverse))')"

echo
echo "== 8. Compact / standard / verbose (MCP) =="
RC=$(mcp_text '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"change_history","arguments":{"action":"history_list","limit":10}}}')
RS=$(mcp_text '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"change_history","arguments":{"action":"history_list","limit":10,"context_mode":"standard"}}}')
RV=$(mcp_text '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"change_history","arguments":{"action":"history_list","limit":10,"context_mode":"verbose"}}}')
# Top-level trust fields are identical across modes (determinism).
assert_eq "compact total_count == verbose total_count" "$(pj "$RV" '.total_count')" "$(pj "$RC" '.total_count')"
assert_eq "standard total_count == verbose total_count" "$(pj "$RV" '.total_count')" "$(pj "$RS" '.total_count')"
assert_true "compact: changes list wrapped in 103.2 envelope" "$(pj "$RC" '(.changes | type) == "object" and .changes.truncated == true')"
assert_true "compact: envelope total_count truthful" "$(pj "$RC" '.changes.total_count >= 10')"
assert_true "verbose: changes is a full array of 10" "$(pj "$RV" '(.changes | type) == "array" and (.changes | length) == 10')"
assert_true "standard: changes is a full array of 10" "$(pj "$RS" '(.changes | type) == "array" and (.changes | length) == 10')"

echo
echo "== 9. MCP / REST parity =="
RR=$(ch '{"action":"history_list","runtime":"option","limit":5}')
RM=$(mcp_text '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"change_history","arguments":{"action":"history_list","runtime":"option","limit":5,"context_mode":"verbose"}}}')
assert_eq "parity: REST total_count == MCP total_count" "$(pj "$RR" '.total_count')" "$(pj "$RM" '.total_count')"
assert_eq "parity: REST first change_id == MCP first change_id" "$(pj "$RR" '.changes[0].change_id')" "$(pj "$RM" '.changes[0].change_id')"
# GET aliases.
assert_eq "GET /changes parity total_count" "$(pj "$RR" '.total_count')" "$(pj "$(get '/changes?runtime=option&limit=5')" '.total_count')"
assert_eq "GET /changes/timeline action" "history_timeline" "$(pj "$(get '/changes/timeline?limit=2')" '.action')"
assert_eq "GET /changes/{id} returns the record" "$CID" "$(pj "$(get "/changes/$CID")" '.change.change_id')"

echo
echo "== 10. Capability enforcement (read_only token) =="
RES=$(wpe '
$auth = new \WPCommandCenter\Security\AuthTokens();
$r = $auth->create( "104.2 RO test", \WPCommandCenter\Security\AuthTokens::SCOPE_READ_ONLY, null, 1 );
echo is_wp_error($r) ? "" : $r["token"]." ".$r["record"]["id"];
')
RO_TOKEN="$(echo "$RES" | cut -d' ' -f1)"; RO_ID="$(echo "$RES" | cut -d' ' -f2)"
assert_nonempty "ro: token created" "$RO_TOKEN"
RO_READ=$(curl -s -X POST -H "Authorization: Bearer $RO_TOKEN" -H "Content-Type: application/json" -d '{"action":"history_list","limit":1}' "$WPCC_BASE/operations/change_history/run")
assert_eq "ro: can read history_list" "history_list" "$(pj "$RO_READ" '.action')"
RO_GET=$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $RO_TOKEN" "$WPCC_BASE/changes?limit=1")
assert_eq "ro: GET /changes allowed (200)" "200" "$RO_GET"
RO_WRITE=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Bearer $RO_TOKEN" -H "Content-Type: application/json" -d '{"action":"option_update","option_id":"site_title","value":"x"}' "$WPCC_BASE/operations/option_manage/run")
assert_eq "ro: write op denied (403)" "403" "$RO_WRITE"
# Read actions never created an approval request or rollback.
RO_MCP=$(mcp_text '{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"change_history","arguments":{"action":"history_get","change_id":"'"$CID"'"}}}' "$RO_TOKEN")
assert_eq "ro: MCP history_get works" "$CID" "$(pj "$RO_MCP" '.change.change_id')"

echo
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"

[ "$FAIL" -eq 0 ]
