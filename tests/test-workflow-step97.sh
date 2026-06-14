#!/usr/bin/env bash
#
# STEP 97 — Workflow Runtime acceptance suite.
#
# Multi-step execution plans run as one unit: single approval (within_workflow
# gate skip), per-step execution timeline, rollback awareness (captured
# rollback_ids), failure recovery (on_failure: stop|continue|rollback), and
# workflow_rollback to reverse a past execution. Steps use site_builder_manage
# page_update (rollback-capable, verifiable via the page title).
#
# Requires: curl, jq, wp, wpcc-env.sh.
# Usage: bash tests/test-workflow-step97.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
# shellcheck source=/dev/null
source "$PLUGIN_DIR/wpcc-env.sh"
WP_PATH="$SCRIPT_DIR/../../../.."

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }
assert_ne() { local d="$1" e="$2" a="$3"; [ "$e" != "$a" ] && pass "$d" || fail "$d (got disallowed '$a')"; }
assert_nonempty() { local d="$1" a="$2"; { [ -n "$a" ] && [ "$a" != "null" ]; } && pass "$d" || fail "$d (empty/null)"; }
wf() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/operations/workflow_manage/run"; }
wfmcp() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/mcp" | jq -r '.result.content[0].text // empty'; }
wpe() { wp eval "$1" --path="$WP_PATH" 2>/dev/null; }

PGID=""; WID=""
ORIG_TITLE="S97 Original Title"
cleanup() {
  wpe 'update_option("wpcc_security_mode","developer");' >/dev/null 2>&1
  [ -n "$PGID" ] && wpe 'wp_delete_post('"$PGID"',true);'
  [ -n "$WID" ] && wf "$(jq -n --arg w "$WID" '{action:"workflow_delete",workflow_id:$w}')" >/dev/null
}
trap cleanup EXIT

echo "== 0. Seed a page to drive rollback-capable steps =="
PGID=$(wpe '$id=wp_insert_post(["post_title"=>"'"$ORIG_TITLE"'","post_type"=>"page","post_status"=>"publish"]); echo $id;')
assert_nonempty "seed page" "$PGID"

mkstep() { jq -n --argjson p "$PGID" --arg t "$1" '{operation_id:"site_builder_manage",payload:{action:"page_update",page_id:$p,title:$t}}'; }
mkfail() { jq -n '{operation_id:"site_builder_manage",payload:{action:"page_get",page_id:99999999}}'; }

echo "== 1. Create a workflow (3 successful rollback-capable steps) =="
STEPS=$(jq -n --argjson a "$(mkstep 'S97 Step A')" --argjson b "$(mkstep 'S97 Step B')" --argjson c "$(mkstep 'S97 Step C')" '[$a,$b,$c]')
C=$(wf "$(jq -n --argjson s "$STEPS" '{action:"workflow_create",name:"S97 Happy Path",steps:$s}')")
WID=$(echo "$C" | jq -r '.workflow_id')
assert_nonempty "workflow created" "$WID"
assert_eq "step count" "3" "$(echo "$C" | jq -r '.step_count')"

echo "== 2. Execute → timeline + rollback awareness =="
E=$(wf "$(jq -n --arg w "$WID" '{action:"workflow_execute",workflow_id:$w}')")
assert_eq "status completed" "completed" "$(echo "$E" | jq -r '.status')"
assert_eq "steps executed" "3" "$(echo "$E" | jq -r '.steps_executed')"
assert_nonempty "execution_id" "$(echo "$E" | jq -r '.execution_id')"
assert_eq "all steps success" "true" "$(echo "$E" | jq -r '[.results[].success] | all')"
assert_eq "timeline duration present" "true" "$(echo "$E" | jq -r '.results[0] | has("duration_ms")')"
assert_eq "timeline started_at present" "true" "$(echo "$E" | jq -r '(.results[0].started_at // 0) > 0')"
assert_eq "rollback_id captured per step" "true" "$(echo "$E" | jq -r '[.results[].rollback_id != null] | all')"
assert_eq "final title is last step" "S97 Step C" "$(wpe 'echo get_the_title('"$PGID"');')"
EXEC1=$(echo "$E" | jq -r '.execution_id')

echo "== 3. workflow_rollback reverses the whole execution =="
RB=$(wf "$(jq -n --arg e "$EXEC1" '{action:"workflow_rollback",execution_id:$e}')")
assert_eq "steps rolled back = 3" "3" "$(echo "$RB" | jq -r '.steps_rolled_back')"
assert_eq "all rollbacks succeeded" "true" "$(echo "$RB" | jq -r '[.rolled_back[].success] | all')"
assert_eq "title restored to original" "$ORIG_TITLE" "$(wpe 'echo get_the_title('"$PGID"');')"
assert_eq "double rollback rejected" "already_rolled_back" "$(wf "$(jq -n --arg e "$EXEC1" '{action:"workflow_rollback",execution_id:$e}')" | jq -r '.code')"

echo "== 4. on_failure=stop → failing step halts, rest skipped =="
wpe 'wp_update_post(["ID"=>'"$PGID"',"post_title"=>"'"$ORIG_TITLE"'"]);' >/dev/null
SS=$(jq -n --argjson a "$(mkstep 'S97 Stop A')" --argjson f "$(mkfail)" --argjson c "$(mkstep 'S97 Stop C')" '[$a,$f,$c]')
WSTOP=$(wf "$(jq -n --argjson s "$SS" '{action:"workflow_create",name:"S97 Stop",steps:$s}')" | jq -r '.workflow_id')
ES=$(wf "$(jq -n --arg w "$WSTOP" '{action:"workflow_execute",workflow_id:$w,on_failure:"stop"}')")
assert_eq "stop: status failed" "failed" "$(echo "$ES" | jq -r '.status')"
assert_eq "stop: step2 failed" "false" "$(echo "$ES" | jq -r '.results[1].success')"
assert_eq "stop: step3 skipped" "true" "$(echo "$ES" | jq -r '.results[2].skipped')"
assert_eq "stop: title stays at step A (step C skipped)" "S97 Stop A" "$(wpe 'echo get_the_title('"$PGID"');')"
wf "$(jq -n --arg w "$WSTOP" '{action:"workflow_delete",workflow_id:$w}')" >/dev/null

echo "== 5. on_failure=continue → failing step ignored, later steps run =="
wpe 'wp_update_post(["ID"=>'"$PGID"',"post_title"=>"'"$ORIG_TITLE"'"]);' >/dev/null
SC=$(jq -n --argjson a "$(mkstep 'S97 Cont A')" --argjson f "$(mkfail)" --argjson c "$(mkstep 'S97 Cont C')" '[$a,$f,$c]')
WCONT=$(wf "$(jq -n --argjson s "$SC" '{action:"workflow_create",name:"S97 Continue",steps:$s}')" | jq -r '.workflow_id')
EC=$(wf "$(jq -n --arg w "$WCONT" '{action:"workflow_execute",workflow_id:$w,on_failure:"continue"}')")
assert_eq "continue: status failed" "failed" "$(echo "$EC" | jq -r '.status')"
assert_eq "continue: step3 ran" "true" "$(echo "$EC" | jq -r '.results[2].success')"
assert_eq "continue: title is step C" "S97 Cont C" "$(wpe 'echo get_the_title('"$PGID"');')"
wf "$(jq -n --arg w "$WCONT" '{action:"workflow_delete",workflow_id:$w}')" >/dev/null

echo "== 6. on_failure=rollback → completed steps auto-reverse =="
wpe 'wp_update_post(["ID"=>'"$PGID"',"post_title"=>"'"$ORIG_TITLE"'"]);' >/dev/null
SR=$(jq -n --argjson a "$(mkstep 'S97 RB A')" --argjson b "$(mkstep 'S97 RB B')" --argjson f "$(mkfail)" '[$a,$b,$f]')
WRB=$(wf "$(jq -n --argjson s "$SR" '{action:"workflow_create",name:"S97 Rollback",steps:$s}')" | jq -r '.workflow_id')
ER=$(wf "$(jq -n --arg w "$WRB" '{action:"workflow_execute",workflow_id:$w,on_failure:"rollback"}')")
assert_eq "rollback: status rolled_back" "rolled_back" "$(echo "$ER" | jq -r '.status')"
assert_eq "rollback: 2 steps reversed" "2" "$(echo "$ER" | jq -r '.rolled_back | length')"
assert_eq "rollback: title restored to original" "$ORIG_TITLE" "$(wpe 'echo get_the_title('"$PGID"');')"
wf "$(jq -n --arg w "$WRB" '{action:"workflow_delete",workflow_id:$w}')" >/dev/null

echo "== 7. Single approval — within_workflow skips the per-step approval gate =="
# In Client mode a medium op normally pends; inside an approved workflow it must not.
wpe 'update_option("wpcc_security_mode","client");' >/dev/null
PLAIN=$(wpe '$r=(new \WPCommandCenter\Operations\OperationExecutor())->run("site_builder_manage",["action"=>"page_update","page_id"=>'"$PGID"',"title"=>"Gate Probe"],[]); echo $r["result"]["status"] ?? ($r["success"]?"ok":"fail");')
INWF=$(wpe '$r=(new \WPCommandCenter\Operations\OperationExecutor())->run("site_builder_manage",["action"=>"page_update","page_id"=>'"$PGID"',"title"=>"In WF"],["within_workflow"=>true]); echo $r["result"]["status"] ?? ($r["success"]?"ok":"fail");')
wpe 'update_option("wpcc_security_mode","developer");' >/dev/null
assert_eq "client mode: plain medium op pends" "pending_approval" "$PLAIN"
assert_ne "within_workflow: does NOT pend" "pending_approval" "$INWF"
assert_eq "within_workflow: executed" "In WF" "$(wpe 'echo get_the_title('"$PGID"');')"

echo "== 8. MCP parity (workflow_execute over MCP) =="
wpe 'wp_update_post(["ID"=>'"$PGID"',"post_title"=>"'"$ORIG_TITLE"'"]);' >/dev/null
MR=$(wfmcp "$(jq -n --arg w "$WID" '{jsonrpc:"2.0",id:1,method:"tools/call",params:{name:"workflow_manage",arguments:{action:"workflow_execute",workflow_id:$w}}}')")
assert_eq "MCP execute completed" "completed" "$(echo "$MR" | jq -r '.status')"
assert_nonempty "MCP execution_id" "$(echo "$MR" | jq -r '.execution_id')"

echo "== 9. History records executions with timeline =="
H=$(wf "$(jq -n --arg w "$WID" '{action:"workflow_history",workflow_id:$w}')")
assert_eq "history scoped to workflow" "true" "$(echo "$H" | jq -r '[.history[].workflow_id == "'"$WID"'"] | all')"
assert_eq "history entry has duration_ms" "true" "$(echo "$H" | jq -r '.history[0] | has("duration_ms")')"

echo "== 10. Structured errors =="
assert_eq "execute missing workflow" "nf" "$(wf '{"action":"workflow_execute","workflow_id":"does_not_exist"}' | jq -r '.code')"
assert_eq "rollback missing execution_id" "missing_execution_id" "$(wf '{"action":"workflow_rollback"}' | jq -r '.code')"
assert_eq "rollback unknown execution" "execution_not_found" "$(wf '{"action":"workflow_rollback","execution_id":"nope-123"}' | jq -r '.code')"
assert_eq "invalid action" "invalid" "$(wf '{"action":"workflow_bogus"}' | jq -r '.code')"

echo
echo "================================================"
echo "  Workflow (STEP 97): $PASS passed, $FAIL failed"
echo "================================================"
[ "$FAIL" -eq 0 ]
