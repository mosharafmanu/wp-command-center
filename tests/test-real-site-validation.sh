#!/usr/bin/env bash
# Step 36 - real-site beta validation across diagnostics, workflow, operations,
# results, timeline, rollback, and health verification.
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$DIR/.." && pwd)"
WP_CONTENT="$(cd "$ROOT/../.." && pwd)"
EVIDENCE_DIR="$ROOT/artifacts/step-36-validation"
source "$ROOT/wpcc-env.sh"

P=0; F=0; CREATED_POST_ID=""; FIXTURE_DIR="$WP_CONTENT/mu-plugins/wpcc-step36"; FIXTURE_REL="mu-plugins/wpcc-step36/fixture.txt"
pass(){ P=$((P+1)); echo "  PASS: $1"; }
fail(){ F=$((F+1)); echo "  FAIL: $1"; }
eq(){ if [ "$2" = "$3" ]; then pass "$1"; else fail "$1 (expected '$2', got '$3')"; fi; }
ok(){ if [ "$2" = true ]; then pass "$1"; else fail "$1"; fi; }
api(){ local m="$1" p="$2" b="${3:-}"; if [ -n "$b" ]; then curl -sS -X "$m" -H "Authorization: Bearer $WPCC_TOKEN" -H 'Content-Type: application/json' -d "$b" "$WPCC_BASE$p"; else curl -sS -X "$m" -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE$p"; fi; }
cleanup(){ if [ -n "$CREATED_POST_ID" ]; then wp eval "wp_delete_post((int)$CREATED_POST_ID,true);" >/dev/null 2>&1 || true; fi; rm -rf "$FIXTURE_DIR"; }
trap cleanup EXIT

mkdir -p "$FIXTURE_DIR" "$EVIDENCE_DIR"
printf 'step36-v1\n' > "$FIXTURE_DIR/fixture.txt"

echo "== 1. Real Site Stack & Diagnostics =="
SITE=$(api GET /site-intelligence)
ok "WordPress detected" "$(echo "$SITE" | jq -r '.wordpress.version != null')"
ok "WooCommerce active" "$(echo "$SITE" | jq -r '.woocommerce.active == true')"
OPS=$(api GET /operations)
for operation in acf_seed cf7_seed woo_product_seed; do ok "$operation available" "$(echo "$OPS" | jq -r --arg id "$operation" 'any(.[]; .id==$id and .available==true)')"; done
PERF=$(api GET '/diagnostics?type=performance'); SEC=$(api GET '/diagnostics?type=security'); WOO=$(api GET '/diagnostics?type=woocommerce')
for pair in "performance:$PERF" "security:$SEC" "woocommerce:$WOO"; do name=${pair%%:*}; data=${pair#*:}; ok "$name diagnostics returned checks" "$(echo "$data" | jq -r '.checks|length > 0')"; done

echo "== 2. Recommendations, Action & Plan =="
SCAN=$(api POST /recommendations/scan '{}')
ok "recommendation scan completed" "$(echo "$SCAN" | jq -r 'has("recommendations") and has("created") and has("updated")')"
SESSION=$(api POST /agent/sessions '{"source":"api","label":"Step 36 real-site validation"}'); SID=$(echo "$SESSION" | jq -r '.session_id // empty')
TASK=$(api POST /agent/tasks "$(jq -nc --arg sid "$SID" '{session_id:$sid,source:"api",user_prompt:"Validate the WP Command Center V1 beta workflow"}')"); TID=$(echo "$TASK" | jq -r '.task_id // empty')
ACTION=$(api POST /agent/actions "$(jq -nc --arg sid "$SID" --arg tid "$TID" '{session_id:$sid,task_id:$tid,type:"maintenance",title:"Run reversible beta validation",description:"Execute a reviewed draft-content operation and verify all runtime layers."}')"); AID=$(echo "$ACTION" | jq -r '.action_id // empty')
ok "session created" "$([ -n "$SID" ] && echo true || echo false)"; ok "task created" "$([ -n "$TID" ] && echo true || echo false)"; eq "action proposed" proposed "$(echo "$ACTION" | jq -r '.status')"
api POST "/agent/actions/$AID/accept" '{}' >/dev/null
PLAN=$(api POST /agent/plans "$(jq -nc --arg sid "$SID" --arg tid "$TID" --arg aid "$AID" '{session_id:$sid,task_id:$tid,action_id:$aid,title:"Step 36 validation plan",objective:"Prove the reviewed operation and rollback paths on the real site",steps:[{title:"Execute draft operation"},{title:"Verify runtime records"},{title:"Apply and roll back fixture patch"}]}')"); PID=$(echo "$PLAN" | jq -r '.plan_id // empty')
eq "plan awaits review" pending_review "$(echo "$PLAN" | jq -r '.status')"; APPROVED=$(api POST "/agent/plans/$PID/approve" '{}'); eq "plan approved" approved "$(echo "$APPROVED" | jq -r '.status')"

echo "== 3. Approved Operation, Queue & Result =="
REQ_BODY=$(jq -nc --arg sid "$SID" --arg tid "$TID" --arg aid "$AID" --arg pid "$PID" '{operation_id:"content_seed",payload:{type:"post",count:1,status:"draft",title_pattern:"WPCC Step 36 Validation"},session_id:$sid,task_id:$tid,action_id:$aid,plan_id:$pid}')
REQUEST=$(api POST /operations/requests "$REQ_BODY"); REQ_ID=$(echo "$REQUEST" | jq -r '.request_id // empty')
eq "operation request pending review" pending_review "$(echo "$REQUEST" | jq -r '.status')"
api POST "/operations/requests/$REQ_ID/approve" '{}' >/dev/null
QUEUE=$(api GET "/operations/queue?request_id=$REQ_ID"); QID=$(echo "$QUEUE" | jq -r '.[0].queue_id // empty')
eq "queue item created" queued "$(echo "$QUEUE" | jq -r '.[0].status')"
RUN=$(api POST "/operations/queue/$QID/run" '{}'); eq "queued operation completed" completed "$(echo "$RUN" | jq -r '.status')"
RESULTS=$(api GET "/operations/results?queue_id=$QID&limit=1"); RESULT_ID=$(echo "$RESULTS" | jq -r '.[0].result_id // empty')
eq "operation result completed" completed "$(echo "$RESULTS" | jq -r '.[0].status')"
CREATED_POST_ID=$(echo "$RESULTS" | jq -r '.[0].result_json.result.created_ids[0] // .[0].result_json.created_ids[0] // empty')
ok "draft post created" "$([ -n "$CREATED_POST_ID" ] && wp post get "$CREATED_POST_ID" --field=post_status 2>/dev/null | grep -q '^draft$' && echo true || echo false)"

echo "== 4. Timeline & Runtime Links =="
TIMELINE=$(api GET "/agent/timeline?session_id=$SID&limit=300")
for label in "Session created" "Task created" "Action proposed" "Plan created" "Plan approved" "Operation request created" "Operation request approved" "Operation queued" "Operation queue running" "Operation queue completed" "Operation result recorded"; do ok "timeline contains $label" "$(echo "$TIMELINE" | jq -r --arg label "$label" 'any(.[]; .label==$label)')"; done
CONTEXT=$(api GET "/agent/context?session_id=$SID")
ok "context links task" "$(echo "$CONTEXT" | jq -r --arg id "$TID" 'any(.session_tasks[]; .task_id==$id)')"
ok "context links action" "$(echo "$CONTEXT" | jq -r --arg id "$AID" 'any(.session_actions[]; .action_id==$id)')"
ok "context links plan" "$(echo "$CONTEXT" | jq -r --arg id "$PID" 'any(.session_plans[]; .plan_id==$id)')"

echo "== 5. Patch Apply & Rollback =="
PATCH=$(api POST /patches "$(jq -nc --arg path "$FIXTURE_REL" --arg modified $'step36-v2\n' --arg pid "$PID" '{files:[{path:$path,modified:$modified}],explanation:"Step 36 reversible validation fixture",risk_level:"low",source:"api",plan_id:$pid}')"); PATCH_ID=$(echo "$PATCH" | jq -r '.id // empty')
eq "patch pending approval" pending_approval "$(echo "$PATCH" | jq -r '.status')"
api POST "/patches/$PATCH_ID/approve" '{}' >/dev/null
APPLY=$(api POST "/patches/$PATCH_ID/apply" '{}'); eq "patch applied" applied "$(echo "$APPLY" | jq -r '.status')"; eq "fixture changed" step36-v2 "$(cat "$FIXTURE_DIR/fixture.txt")"
ROLLBACK=$(api POST "/patches/$PATCH_ID/rollback" '{}'); eq "patch rolled back" rolled_back "$(echo "$ROLLBACK" | jq -r '.status')"; eq "fixture restored" step36-v1 "$(cat "$FIXTURE_DIR/fixture.txt")"

echo "== 6. Health & Evidence =="
HEALTH=$(api POST /health/verify '{}'); ok "health verification returned seven checks" "$(echo "$HEALTH" | jq -r '.checks|length==7')"; ok "health verification has no failed checks" "$(echo "$HEALTH" | jq -r '.summary.failed==0')"
AUDIT="$WP_CONTENT/uploads/wpcc-audit/audit.log"
for event in recommendation.scan.completed operation.request.created operation.queue.created operation.queue.running operation.queue.completed operation.result.created patch.rolled_back health.verification.completed; do ok "audit contains $event" "$(rg -q "\"action\":\"$event\"" "$AUDIT" && echo true || echo false)"; done

jq -n --arg generated_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" --arg session_id "$SID" --arg task_id "$TID" --arg action_id "$AID" --arg plan_id "$PID" --arg request_id "$REQ_ID" --arg queue_id "$QID" --arg result_id "$RESULT_ID" --arg patch_id "$PATCH_ID" --arg verification_id "$(echo "$HEALTH" | jq -r '.verification_id')" --argjson diagnostics "$(jq -nc --argjson performance "$PERF" --argjson security "$SEC" --argjson woocommerce "$WOO" '{performance:$performance,security:$security,woocommerce:$woocommerce}')" --argjson recommendation_scan "$SCAN" --argjson health "$HEALTH" '{generated_at:$generated_at,ids:{session_id:$session_id,task_id:$task_id,action_id:$action_id,plan_id:$plan_id,request_id:$request_id,queue_id:$queue_id,result_id:$result_id,patch_id:$patch_id,verification_id:$verification_id},diagnostics:$diagnostics,recommendation_scan:$recommendation_scan,health:$health}' > "$EVIDENCE_DIR/validation-evidence.json"
ok "machine-readable evidence written" "$([ -s "$EVIDENCE_DIR/validation-evidence.json" ] && echo true || echo false)"

echo; echo "== Summary =="; echo "  $P passed, $F failed"; [ "$F" -eq 0 ]
