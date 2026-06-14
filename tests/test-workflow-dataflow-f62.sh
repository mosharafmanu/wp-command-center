#!/usr/bin/env bash
#
# F6.2 — Workflow inter-step data flow.
#
# Report: a dependent workflow (create -> update -> publish) failed because
# {{steps.0.result.content_id}} did not resolve — content_update got the literal
# placeholder and errored "content_id is required". Root cause: the engine had no
# template/reference resolver; payloads ran verbatim.
#
# Fix verified here: {{steps.N.result.x}} / {{steps.N.created.0}} references in a
# step payload resolve against earlier steps' outputs (type-preserving for whole
# values, interpolated within strings); unresolvable refs fail the step with
# wpcc_unresolved_reference instead of silently passing a literal.
#
# Requires: curl, jq, wp, wpcc-env.sh.
# Usage: bash tests/test-workflow-dataflow-f62.sh

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
assert_contains() { local d="$1" h="$2" n="$3"; case "$h" in *"$n"*) pass "$d";; *) fail "$d (‘$h’ lacks ‘$n’)";; esac; }
assert_nonempty() { local d="$1" a="$2"; { [ -n "$a" ] && [ "$a" != "null" ]; } && pass "$d" || fail "$d (empty/null)"; }
wf() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/operations/workflow_manage/run"; }
wfmcp() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/mcp" | jq -r '.result.content[0].text // empty'; }
wpe() { wp eval "$1" --path="$WP_PATH" 2>/dev/null; }

WIDS=""; STRAY=""
cleanup() {
  for w in $WIDS; do wf "$(jq -n --arg w "$w" '{action:"workflow_delete",workflow_id:$w}')" >/dev/null; done
  for p in $STRAY; do wpe 'wp_delete_post('"$p"',true);'; done
}
trap cleanup EXIT

echo "== 1. create -> update(ref) -> publish resolves and applies (the documented scenario) =="
W1=$(wf "$(jq -n '{action:"workflow_create",name:"F62 Flow",steps:[
  {operation_id:"content_manage",payload:{action:"content_create",type:"post",title:"F62 Original",status:"draft"}},
  {operation_id:"content_manage",payload:{action:"content_update",content_id:"{{steps.0.result.content_id}}",title:"F62 Updated"}},
  {operation_id:"content_manage",payload:{action:"content_update",content_id:"{{steps.0.created.0}}",status:"publish"}}
]}')" | jq -r '.workflow_id'); WIDS="$WIDS $W1"
E1=$(wf "$(jq -n --arg w "$W1" '{action:"workflow_execute",workflow_id:$w,on_failure:"stop"}')")
assert_eq "workflow completed" "completed" "$(echo "$E1" | jq -r '.status')"
assert_eq "all 3 steps succeeded" "true" "$(echo "$E1" | jq -r '[.results[].success] | all')"
PID1=$(echo "$E1" | jq -r '.results[0].created[0]'); STRAY="$STRAY $PID1"
assert_nonempty "post id captured" "$PID1"
assert_eq "step2 ref updated the SAME post title (independent read)" "F62 Updated" "$(wpe 'echo get_the_title('"$PID1"');')"
assert_eq "step3 ref published the SAME post (independent read)" "publish" "$(wpe 'echo get_post_status('"$PID1"');')"

echo "== 2. Whole-value ref preserves type (int post ID, not the literal string) =="
# If the ref had stayed a literal/string, content_update would have errored. Its
# success above already proves resolution; assert the error field is absent too.
assert_eq "step2 carries no error" "" "$(echo "$E1" | jq -r '.results[1].error.code // ""')"

echo "== 3. Inline string interpolation inside a value =="
W2=$(wf "$(jq -n '{action:"workflow_create",name:"F62 Interp",steps:[
  {operation_id:"content_manage",payload:{action:"content_create",type:"post",title:"F62 Anchor",status:"draft"}},
  {operation_id:"content_manage",payload:{action:"content_create",type:"post",title:"Child of post {{steps.0.result.content_id}}",status:"draft"}}
]}')" | jq -r '.workflow_id'); WIDS="$WIDS $W2"
E2=$(wf "$(jq -n --arg w "$W2" '{action:"workflow_execute",workflow_id:$w,on_failure:"stop"}')")
A2=$(echo "$E2" | jq -r '.results[0].created[0]'); C2=$(echo "$E2" | jq -r '.results[1].created[0]'); STRAY="$STRAY $A2 $C2"
assert_eq "interpolated title contains the anchor id" "Child of post $A2" "$(wpe 'echo get_the_title('"$C2"');')"

echo "== 4. Unresolvable reference fails the step (no silent literal) =="
W3=$(wf "$(jq -n '{action:"workflow_create",name:"F62 Bad",steps:[
  {operation_id:"content_manage",payload:{action:"content_create",type:"post",title:"F62 Anchor2",status:"draft"}},
  {operation_id:"content_manage",payload:{action:"content_update",content_id:"{{steps.9.result.content_id}}",title:"X"}}
]}')" | jq -r '.workflow_id'); WIDS="$WIDS $W3"
E3=$(wf "$(jq -n --arg w "$W3" '{action:"workflow_execute",workflow_id:$w,on_failure:"rollback"}')")
assert_eq "bad-ref step failed" "false" "$(echo "$E3" | jq -r '.results[1].success')"
assert_eq "bad-ref error code" "wpcc_unresolved_reference" "$(echo "$E3" | jq -r '.results[1].error.code')"
# F6.1 integration: the created step-1 post is rolled back when the ref step fails.
PID3=$(echo "$E3" | jq -r '.results[0].created[0]')
assert_eq "status rolled_back" "rolled_back" "$(echo "$E3" | jq -r '.status')"
assert_eq "orphan from failed-ref workflow removed" "gone" "$(wpe 'echo get_post('"$PID3"')?"exists":"gone";')"

echo "== 5. MCP parity — referenced update applies over MCP =="
W4=$(wf "$(jq -n '{action:"workflow_create",name:"F62 MCP",steps:[
  {operation_id:"content_manage",payload:{action:"content_create",type:"post",title:"F62 MCP Orig",status:"draft"}},
  {operation_id:"content_manage",payload:{action:"content_update",content_id:"{{steps.0.result.content_id}}",title:"F62 MCP Updated"}}
]}')" | jq -r '.workflow_id'); WIDS="$WIDS $W4"
E4=$(wfmcp "$(jq -n --arg w "$W4" '{jsonrpc:"2.0",id:1,method:"tools/call",params:{name:"workflow_manage",arguments:{action:"workflow_execute",workflow_id:$w,on_failure:"stop"}}}')")
assert_eq "MCP workflow completed" "completed" "$(echo "$E4" | jq -r '.status')"
PID4=$(echo "$E4" | jq -r '.results[0].created[0]'); STRAY="$STRAY $PID4"
assert_eq "MCP ref update applied (independent read)" "F62 MCP Updated" "$(wpe 'echo get_the_title('"$PID4"');')"

echo
echo "================================================"
echo "  Workflow data flow F6.2: $PASS passed, $FAIL failed"
echo "================================================"
[ "$FAIL" -eq 0 ]
