#!/usr/bin/env bash
# Workflow Runtime test suite (25+ assertions)
set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../wpcc-env.sh"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; if [ "$e" = "$a" ]; then pass "$d"; else fail "$d (expected '$e', got '$a')"; fi; }
assert_true() { local d="$1" a="$2"; if [ "$a" = "true" ]; then pass "$d"; else fail "$d"; fi; }
assert_contains() { local d="$1" h="$2" n="$3"; if [[ "$h" == *"$n"* ]]; then pass "$d"; else fail "$d"; fi; }
api() { local m="$1" p="$2" b="${3:-}"; if [ -n "$b" ]; then curl -s -X "$m" -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$b" "$WPCC_BASE$p"; else curl -s -X "$m" -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE$p"; fi; }

echo "Workflow Runtime Test — $(date)"

MANIFEST=$(api GET /agent/manifest)
CONTEXT=$(api GET /agent/context)
OPS=$(api GET /operations)

# ── 1. Registration ──
echo "== 1. Registration =="
assert_true "reg: workflow_manage in operations" "$(echo "$OPS" | jq -r 'any(.[]; .id == "workflow_manage")')"
assert_eq "reg: risk_level high" "high" "$(echo "$OPS" | jq -r '.[]|select(.id=="workflow_manage")|.risk_level')"
assert_eq "reg: requires_approval" "true" "$(echo "$OPS" | jq -r '.[]|select(.id=="workflow_manage")|.requires_approval')"
assert_true "reg: has action param" "$(echo "$OPS" | jq -r '.[]|select(.id=="workflow_manage")|.parameters[]|select(.name=="action")|.required')"
assert_contains "reg: action param desc" "$(echo "$OPS" | jq -r '.[]|select(.id=="workflow_manage")|.parameters[]|select(.name=="action")|.description')" "Workflow action"

# ── 2. Capability ──
echo "== 2. Capability =="
assert_contains "cap: workflow.manage in capabilities" "$(echo "$MANIFEST" | jq -r '.capability_management.capabilities|join(",")')" "workflow.manage"
assert_contains "cap: workflow_manage in operation_map" "$(echo "$MANIFEST" | jq -r '.capability_management.operation_map|keys|join(",")')" "workflow_manage"

# ── 3. Routes ──
echo "== 3. Routes =="
assert_true "route: /workflow_manage/run exists" "$(echo "$MANIFEST" | jq -r 'any(.endpoints[]; .path == "/operations/workflow_manage/run")')"
assert_eq "route: scope full" "full" "$(echo "$MANIFEST" | jq -r '.endpoints[]|select(.path=="/operations/workflow_manage/run")|.scope')"

# ── 4. Workflow Create ──
echo "== 4. Workflow Create =="
CREATE=$(api POST /operations/workflow_manage/run '{"action":"workflow_create","name":"Test Inspection Flow","description":"A test workflow that inspects the database","steps":[{"operation_id":"database_inspect","payload":{"action":"db_table_list"}},{"operation_id":"database_inspect","payload":{"action":"db_row_counts"}}]}')
assert_eq "create: action" "workflow_create" "$(echo "$CREATE" | jq -r '.action')"
assert_true "create: has workflow_id" "$(echo "$CREATE" | jq -r 'if .workflow_id then "true" else "false" end')"
assert_eq "create: name correct" "Test Inspection Flow" "$(echo "$CREATE" | jq -r '.name')"
assert_eq "create: step_count 2" "2" "$(echo "$CREATE" | jq -r '.step_count')"
WF_ID=$(echo "$CREATE" | jq -r '.workflow_id')
assert_true "create: workflow_id not empty" "$(if [ -n "$WF_ID" ] && [ "$WF_ID" != "null" ]; then echo true; else echo false; fi)"

# ── 5. Workflow List ──
echo "== 5. Workflow List =="
LIST=$(api POST /operations/workflow_manage/run '{"action":"workflow_list"}')
assert_eq "list: action" "workflow_list" "$(echo "$LIST" | jq -r '.action')"
assert_true "list: total > 0" "$(echo "$LIST" | jq -r 'if .total > 0 then "true" else "false" end')"
assert_true "list: has workflows array" "$(echo "$LIST" | jq -r 'if (.workflows | type) == "array" then "true" else "false" end')"

# ── 6. Workflow Get ──
echo "== 6. Workflow Get =="
GET=$(api POST /operations/workflow_manage/run "{\"action\":\"workflow_get\",\"workflow_id\":\"$WF_ID\"}")
assert_eq "get: action" "workflow_get" "$(echo "$GET" | jq -r '.action')"
assert_eq "get: name correct" "Test Inspection Flow" "$(echo "$GET" | jq -r '.workflow.name')"
assert_eq "get: step_count 2" "2" "$(echo "$GET" | jq -r '.workflow.steps | length')"

# ── 7. Workflow Update ──
echo "== 7. Workflow Update =="
UPDATE=$(api POST /operations/workflow_manage/run "{\"action\":\"workflow_update\",\"workflow_id\":\"$WF_ID\",\"name\":\"Updated Inspection Flow\"}")
assert_eq "update: action" "workflow_update" "$(echo "$UPDATE" | jq -r '.action')"
assert_eq "update: updated true" "true" "$(echo "$UPDATE" | jq -r '.updated')"
# Verify update
GET2=$(api POST /operations/workflow_manage/run "{\"action\":\"workflow_get\",\"workflow_id\":\"$WF_ID\"}")
assert_eq "update: name changed" "Updated Inspection Flow" "$(echo "$GET2" | jq -r '.workflow.name')"

# ── 8. Workflow Execute (with database_inspect operation) ──
echo "== 8. Workflow Execute =="
EXEC=$(api POST /operations/workflow_manage/run "{\"action\":\"workflow_execute\",\"workflow_id\":\"$WF_ID\"}")
assert_eq "exec: action" "workflow_execute" "$(echo "$EXEC" | jq -r '.action')"
assert_eq "exec: steps_executed 2" "2" "$(echo "$EXEC" | jq -r '.steps_executed')"
assert_true "exec: has results array" "$(echo "$EXEC" | jq -r 'if (.results | type) == "array" then "true" else "false" end')"

# ── 9. Workflow Import ──
echo "== 9. Workflow Import =="
IMPORT=$(api POST /operations/workflow_manage/run '{"action":"workflow_import","json":"{\"name\":\"Imported Flow\",\"description\":\"An imported workflow\",\"steps\":[{\"operation_id\":\"database_inspect\",\"payload\":{\"action\":\"db_health_summary\"}}]}"}')
assert_eq "import: action" "workflow_import" "$(echo "$IMPORT" | jq -r '.action')"
assert_eq "import: name correct" "Imported Flow" "$(echo "$IMPORT" | jq -r '.name')"
assert_eq "import: imported true" "true" "$(echo "$IMPORT" | jq -r '.imported')"
IMP_ID=$(echo "$IMPORT" | jq -r '.workflow_id')

# ── 10. Workflow Export ──
echo "== 10. Workflow Export =="
EXPORT=$(api POST /operations/workflow_manage/run "{\"action\":\"workflow_export\",\"workflow_id\":\"$IMP_ID\"}")
assert_eq "export: action" "workflow_export" "$(echo "$EXPORT" | jq -r '.action')"
assert_contains "export: has json" "$(echo "$EXPORT" | jq -r '.json')" "Imported Flow"

# ── 11. Workflow History ──
echo "== 11. Workflow History =="
HISTORY=$(api POST /operations/workflow_manage/run '{"action":"workflow_history"}')
assert_eq "history: action" "workflow_history" "$(echo "$HISTORY" | jq -r '.action')"
assert_true "history: has history array" "$(echo "$HISTORY" | jq -r 'if (.history | type) == "array" then "true" else "false" end')"
assert_true "history: total > 0" "$(echo "$HISTORY" | jq -r 'if .total > 0 then "true" else "false" end')"

# ── 12. Validation: invalid action ──
echo "== 12. Validation =="
BAD=$(api POST /operations/workflow_manage/run '{"action":"invalid_action"}')
assert_contains "val: invalid action rejected" "$(echo "$BAD" | jq -r '.code // ""')" "invalid"

# ── 13. Validation: missing workflow_id ──
NF=$(api POST /operations/workflow_manage/run '{"action":"workflow_get"}')
assert_contains "val: missing id get returns error" "$(echo "$NF" | jq -r '.code // ""')" "nf"

# ── 14. Validation: nonexistent workflow_id ──
NEX=$(api POST /operations/workflow_manage/run '{"action":"workflow_get","workflow_id":"nonexistent_1234567890"}')
assert_contains "val: nonexistent returns error" "$(echo "$NEX" | jq -r '.code // ""')" "nf"

# ── 15. Validation: missing name for create ──
MISS_NAME=$(api POST /operations/workflow_manage/run '{"action":"workflow_create"}')
assert_contains "val: missing name rejected" "$(echo "$MISS_NAME" | jq -r '.code // ""')" "missing"

# ── 16. MCP Discovery ──
echo "== 16. MCP Discovery =="
DISC=$(api GET /claude/discovery)
assert_contains "mcp: workflow_manage in tools" "$(echo "$DISC" | jq -r '[.tools[].name]|join(",")')" "workflow_manage"

# ── 17. Timeline ──
echo "== 17. Timeline =="
TL=$(api GET "/agent/timeline?limit=80")
assert_true "timeline: workflow created" "$(echo "$TL" | jq -r 'any(.[]; .label == "Workflow created")')"
assert_true "timeline: workflow executed" "$(echo "$TL" | jq -r 'any(.[]; .label == "Workflow executed")')"
assert_true "timeline: workflow operation started" "$(echo "$TL" | jq -r 'any(.[]; .label == "Workflow operation started")')"
assert_true "timeline: workflow operation completed" "$(echo "$TL" | jq -r 'any(.[]; .label == "Workflow operation completed")')"

# ── 18. Manifest operations list ──
echo "== 18. Manifest Operations =="
assert_true "manifest: workflow_manage in ops" "$(echo "$MANIFEST" | jq -r 'any(.operations[]; .id == "workflow_manage")')"
assert_eq "manifest: 10 supported actions" "10" "$(echo "$MANIFEST" | jq -r '.operations[]|select(.id=="workflow_manage")|.parameters[]|select(.name=="action")|.description' | grep -o 'workflow_[a-z]*' | wc -l | tr -d ' ')"

# ── 19. Error catalog ──
echo "== 19. Error Catalog =="
ECAT=$(echo "$MANIFEST" | jq -r '.error_catalog')
assert_contains "error: wpcc_operation_not_found" "$ECAT" "wpcc_operation_not_found"

# ── 20. No token access ──
echo "== 20. No Token Access =="
NOTOKEN=$(curl -s -o /dev/null -w "%{http_code}" -X POST -H "Content-Type: application/json" -d '{"action":"workflow_list"}' "$WPCC_BASE/operations/workflow_manage/run")
assert_eq "no_token: 401" "401" "$NOTOKEN"

# ── 21. Workflow Delete ──
echo "== 21. Workflow Delete =="
DELETE=$(api POST /operations/workflow_manage/run "{\"action\":\"workflow_delete\",\"workflow_id\":\"$IMP_ID\"}")
assert_eq "delete: action" "workflow_delete" "$(echo "$DELETE" | jq -r '.action')"
assert_eq "delete: deleted true" "true" "$(echo "$DELETE" | jq -r '.deleted')"
# Verify gone
GONE=$(api POST /operations/workflow_manage/run "{\"action\":\"workflow_get\",\"workflow_id\":\"$IMP_ID\"}")
assert_contains "delete: gone" "$(echo "$GONE" | jq -r '.code // ""')" "nf"

# ── 22. End-to-end: create + execute another workflow ──
echo "== 22. End-to-end =="
E2E=$(api POST /operations/workflow_manage/run '{"action":"workflow_create","name":"E2E Test","steps":[{"operation_id":"database_inspect","payload":{"action":"db_health_summary"}}]}')
E2E_ID=$(echo "$E2E" | jq -r '.workflow_id')
assert_true "e2e: created" "$(if [ -n "$E2E_ID" ] && [ "$E2E_ID" != "null" ]; then echo true; else echo false; fi)"
E2E_EXEC=$(api POST /operations/workflow_manage/run "{\"action\":\"workflow_execute\",\"workflow_id\":\"$E2E_ID\"}")
assert_eq "e2e: steps_executed 1" "1" "$(echo "$E2E_EXEC" | jq -r '.steps_executed')"
assert_contains "e2e: success" "$(echo "$E2E_EXEC" | jq -r '.results[0].success')" "true"

# Cleanup the test workflow
api POST /operations/workflow_manage/run "{\"action\":\"workflow_delete\",\"workflow_id\":\"$WF_ID\"}" > /dev/null 2>&1
api POST /operations/workflow_manage/run "{\"action\":\"workflow_delete\",\"workflow_id\":\"$E2E_ID\"}" > /dev/null 2>&1

echo ""
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
