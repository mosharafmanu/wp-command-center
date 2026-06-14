#!/usr/bin/env bash
#
# F6.1 — Workflow on_failure:rollback does not roll back completed steps.
#
# Report: a workflow created a post (step 1, rollback_id:null / rollbackable:false),
# step 2 failed, on_failure:rollback was set, yet step 1 was NOT rolled back →
# orphaned post left on production.
#
# Root cause: the engine only recorded steps that returned a rollback_id as
# reversible; content_create returns none, so the create step was treated as
# non-reversible and skipped on rollback. Fix: capture each step's created
# resources and reverse them (delete) on rollback, then verify they are gone.
#
# Requires: curl, jq, wp, wpcc-env.sh.
# Usage: bash tests/test-workflow-rollback-f61.sh

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
wf() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/operations/workflow_manage/run"; }
wfmcp() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/mcp" | jq -r '.result.content[0].text // empty'; }
wpe() { wp eval "$1" --path="$WP_PATH" 2>/dev/null; }
poststate() { wpe 'echo get_post('"$1"')?"exists":"gone";'; }  # independent follow-up read

WIDS=""; STRAY=""
cleanup() {
  for w in $WIDS; do wf "$(jq -n --arg w "$w" '{action:"workflow_delete",workflow_id:$w}')" >/dev/null; done
  for p in $STRAY; do wpe 'wp_delete_post('"$p"',true);'; done
}
trap cleanup EXIT

# A 2-step workflow: step 1 creates a post (succeeds, no rollback_id); step 2
# content_update with no content_id (fails) — exactly the documented scenario.
mkwf() {
  local name="$1"
  jq -n --arg n "$name" '{action:"workflow_create",name:$n,steps:[
    {operation_id:"content_manage",payload:{action:"content_create",type:"post",title:"F61 Orphan Probe",status:"draft"}},
    {operation_id:"content_manage",payload:{action:"content_update"}}
  ]}'
}

echo "== 1. on_failure:rollback reverses the completed create step (the fix) =="
W1=$(wf "$(mkwf 'F61 Rollback')" | jq -r '.workflow_id'); WIDS="$WIDS $W1"
E1=$(wf "$(jq -n --arg w "$W1" '{action:"workflow_execute",workflow_id:$w,on_failure:"rollback"}')")
assert_eq "status rolled_back" "rolled_back" "$(echo "$E1" | jq -r '.status')"
assert_eq "step1 succeeded" "true" "$(echo "$E1" | jq -r '.results[0].success')"
assert_eq "step1 now marked rollbackable (was false)" "true" "$(echo "$E1" | jq -r '.results[0].rollbackable')"
assert_eq "step2 failed" "false" "$(echo "$E1" | jq -r '.results[1].success')"
PID1=$(echo "$E1" | jq -r '.results[0].created[0]')
assert_nonempty "step1 created post id captured" "$PID1"
assert_eq "rollback verified gone" "true" "$(echo "$E1" | jq -r '.rolled_back[0].verified')"
assert_eq "ORPHAN post removed (independent read)" "gone" "$(poststate "$PID1")"

echo "== 2. on_failure:stop leaves the post (contrast — proves rollback did the work) =="
W2=$(wf "$(mkwf 'F61 Stop')" | jq -r '.workflow_id'); WIDS="$WIDS $W2"
E2=$(wf "$(jq -n --arg w "$W2" '{action:"workflow_execute",workflow_id:$w,on_failure:"stop"}')")
assert_eq "status failed" "failed" "$(echo "$E2" | jq -r '.status')"
PID2=$(echo "$E2" | jq -r '.results[0].created[0]'); STRAY="$STRAY $PID2"
assert_eq "post left in place under stop" "exists" "$(poststate "$PID2")"

echo "== 3. workflow_rollback on a fully-successful create execution removes + verifies =="
W3=$(wf "$(jq -n '{action:"workflow_create",name:"F61 Success",steps:[{operation_id:"content_manage",payload:{action:"content_create",type:"post",title:"F61 Success Post",status:"draft"}}]}')" | jq -r '.workflow_id'); WIDS="$WIDS $W3"
E3=$(wf "$(jq -n --arg w "$W3" '{action:"workflow_execute",workflow_id:$w}')")
assert_eq "execution completed" "completed" "$(echo "$E3" | jq -r '.status')"
PID3=$(echo "$E3" | jq -r '.results[0].created[0]')
assert_eq "post exists after run" "exists" "$(poststate "$PID3")"
EXEC3=$(echo "$E3" | jq -r '.execution_id')
RB3=$(wf "$(jq -n --arg e "$EXEC3" '{action:"workflow_rollback",execution_id:$e}')")
assert_eq "workflow_rollback verified" "true" "$(echo "$RB3" | jq -r '.verified')"
assert_eq "post removed by workflow_rollback" "gone" "$(poststate "$PID3")"
assert_eq "double rollback rejected" "already_rolled_back" "$(wf "$(jq -n --arg e "$EXEC3" '{action:"workflow_rollback",execution_id:$e}')" | jq -r '.code')"

echo "== 4. MCP parity — on_failure:rollback removes the orphan over MCP =="
W4=$(wf "$(mkwf 'F61 MCP')" | jq -r '.workflow_id'); WIDS="$WIDS $W4"
E4=$(wfmcp "$(jq -n --arg w "$W4" '{jsonrpc:"2.0",id:1,method:"tools/call",params:{name:"workflow_manage",arguments:{action:"workflow_execute",workflow_id:$w,on_failure:"rollback"}}}')")
assert_eq "MCP status rolled_back" "rolled_back" "$(echo "$E4" | jq -r '.status')"
PID4=$(echo "$E4" | jq -r '.results[0].created[0]')
assert_eq "MCP orphan removed" "gone" "$(poststate "$PID4")"

echo
echo "================================================"
echo "  Workflow rollback F6.1: $PASS passed, $FAIL failed"
echo "================================================"
[ "$FAIL" -eq 0 ]
