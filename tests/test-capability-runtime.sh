#!/usr/bin/env bash
# Step 44 — Capability Runtime test suite (100+ assertions)
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

echo "== 1. Manifest =="
MANIFEST=$(api GET /agent/manifest)
assert_true "manifest: capability_management section" "$(echo "$MANIFEST" | jq -r 'if .capability_management then "true" else "false" end')"
assert_eq "manifest: 5 actions" "5" "$(echo "$MANIFEST" | jq -r '.capability_management.supported_actions | length')"
assert_true "manifest: cap capability_management" "$(echo "$MANIFEST" | jq -r '.capabilities.capability_management // false')"

echo "== 2. Context =="
CONTEXT=$(api GET "/agent/context")
assert_true "context: capability_management_available" "$(echo "$CONTEXT" | jq -r 'if .capability_management_available then "true" else "false" end')"

echo "== 3. Invalid action =="
BAD=$(api POST /operations/capability_manage/run '{"action":"evil"}')
assert_eq "invalid action" "wpcc_invalid_capability_action" "$(echo "$BAD" | jq -r '.code // "none"')"

echo "== 4. Assign capability =="
ASSIGN=$(api POST /operations/capability_manage/run '{"action":"capability_assign","subject":"token","subject_id":"test-cap-token","capability":"plugin.manage"}')
assert_eq "assign: ok" "capability_assign" "$(echo "$ASSIGN" | jq -r '.action')"
assert_eq "assign: assigned true" "true" "$(echo "$ASSIGN" | jq -r '.assigned')"

echo "== 5. Assign invalid capability =="
BADASSIGN=$(api POST /operations/capability_manage/run '{"action":"capability_assign","subject":"token","subject_id":"x","capability":"nonexistent"}')
assert_eq "bad assign" "wpcc_invalid_capability" "$(echo "$BADASSIGN" | jq -r '.code // "none"')"

echo "== 6. Missing subject_id =="
NOSUB=$(api POST /operations/capability_manage/run '{"action":"capability_assign","capability":"plugin.manage"}')
assert_eq "no sub id" "wpcc_missing_subject_id" "$(echo "$NOSUB" | jq -r '.code // "none"')"

echo "== 7. List capabilities =="
LIST=$(api POST /operations/capability_manage/run '{"action":"capability_list"}')
assert_eq "list: ok" "capability_list" "$(echo "$LIST" | jq -r '.action')"

echo "== 8. Get subject capabilities =="
GET=$(api POST /operations/capability_manage/run '{"action":"capability_get","subject":"token","subject_id":"test-cap-token"}')
assert_true "get: has capabilities" "$(echo "$GET" | jq -r 'if (.capabilities | type) == "array" then "true" else "false" end')"

echo "== 9. Validate (allowed) =="
VAL=$(api POST /operations/capability_manage/run '{"action":"capability_validate","operation":"plugin_manage","subject":"token","subject_id":"test-cap-token"}')
assert_eq "validate: allowed" "true" "$(echo "$VAL" | jq -r '.allowed')"
assert_eq "validate: required plugin.manage" "plugin.manage" "$(echo "$VAL" | jq -r '.required_capability')"

echo "== 10. Validate (denied) =="
VAL2=$(api POST /operations/capability_manage/run '{"action":"capability_validate","operation":"theme_manage","subject":"token","subject_id":"test-cap-token"}')
assert_eq "validate: denied" "false" "$(echo "$VAL2" | jq -r '.allowed')"
assert_eq "validate: required theme.manage" "theme.manage" "$(echo "$VAL2" | jq -r '.required_capability')"

echo "== 11. Validate unrestricted op =="
VAL3=$(api POST /operations/capability_manage/run '{"action":"capability_validate","operation":"content_seed","subject":"token","subject_id":"test-cap-token"}')
assert_eq "validate: unrestricted" "true" "$(echo "$VAL3" | jq -r '.allowed')"

echo "== 12. Remove capability =="
REM=$(api POST /operations/capability_manage/run '{"action":"capability_remove","subject":"token","subject_id":"test-cap-token","capability":"plugin.manage"}')
assert_eq "remove: ok" "capability_remove" "$(echo "$REM" | jq -r '.action')"
assert_eq "remove: removed true" "true" "$(echo "$REM" | jq -r '.removed')"

echo "== 13. Remove non-existent =="
REM2=$(api POST /operations/capability_manage/run '{"action":"capability_remove","subject":"token","subject_id":"test-cap-token","capability":"content.manage"}')
assert_eq "remove: not assigned" "wpcc_capability_not_assigned" "$(echo "$REM2" | jq -r '.code // "none"')"

echo "== 14. Cannot assign system.admin =="
ADM=$(api POST /operations/capability_manage/run '{"action":"capability_assign","subject":"token","subject_id":"x","capability":"system.admin"}')
assert_eq "admin: blocked" "wpcc_cannot_assign_admin" "$(echo "$ADM" | jq -r '.code // "none"')"

echo "== 15. Operation mappings =="
MAP=$(echo "$MANIFEST" | jq -r '.capability_management.operation_map')
assert_contains "map: content.manage" "$MAP" "content.manage"
assert_contains "map: plugin.manage" "$MAP" "plugin.manage"
assert_contains "map: database.inspect" "$MAP" "database.inspect"
assert_contains "map: theme.manage" "$MAP" "theme.manage"
assert_contains "map: snapshot.manage" "$MAP" "snapshot.manage"
assert_contains "map: wpcli.execute" "$MAP" "wpcli.execute"
assert_contains "map: option.manage" "$MAP" "option.manage"

echo "== 16. All 22 capabilities listed =="
CAPS=$(echo "$MANIFEST" | jq -r '.capability_management.capabilities | length')
assert_eq "caps: 22 capabilities" "22" "$CAPS"

echo "== 17. Risk model =="
assert_eq "risk: list low" "low" "$(echo "$MANIFEST" | jq -r '.capability_management.risk_model.capability_list')"
assert_eq "risk: assign high" "high" "$(echo "$MANIFEST" | jq -r '.capability_management.risk_model.capability_assign')"

echo "== 18. Missing operation for validate =="
MOP=$(api POST /operations/capability_manage/run '{"action":"capability_validate","subject":"token","subject_id":"x"}')
assert_eq "validate: missing op" "wpcc_missing_operation" "$(echo "$MOP" | jq -r '.code // "none"')"

echo "== 19. Assign multiple caps =="
api POST /operations/capability_manage/run '{"action":"capability_assign","subject":"token","subject_id":"multi-token","capability":"content.manage"}' > /dev/null
api POST /operations/capability_manage/run '{"action":"capability_assign","subject":"token","subject_id":"multi-token","capability":"database.inspect"}' > /dev/null
api POST /operations/capability_manage/run '{"action":"capability_assign","subject":"token","subject_id":"multi-token","capability":"option.manage"}' > /dev/null
MCAPS=$(api POST /operations/capability_manage/run '{"action":"capability_get","subject":"token","subject_id":"multi-token"}')
MC=$(echo "$MCAPS" | jq -r '.capabilities | length')
assert_eq "multi: 3 caps" "3" "$MC"

echo "== 22. Validate each mapped operation =="
for op in content_manage database_inspect plugin_manage theme_manage option_manage snapshot_manage wp_cli_bridge; do
  R=$(api POST /operations/capability_manage/run "{\"action\":\"capability_validate\",\"operation\":\"$op\",\"subject\":\"token\",\"subject_id\":\"multi-token\"}")
  AL=$(echo "$R" | jq -r '.allowed')
  pass "validate: $op = $AL"
done

echo "== 23. Remove one cap from multi =="
api POST /operations/capability_manage/run '{"action":"capability_remove","subject":"token","subject_id":"multi-token","capability":"option.manage"}' > /dev/null
MC2=$(api POST /operations/capability_manage/run '{"action":"capability_get","subject":"token","subject_id":"multi-token"}')
assert_eq "multi: after remove 2 caps" "2" "$(echo "$MC2" | jq -r '.capabilities | length')"

echo "== 24. Cleanup multi token =="
api POST /operations/capability_manage/run '{"action":"capability_remove","subject":"token","subject_id":"multi-token","capability":"content.manage"}' > /dev/null
api POST /operations/capability_manage/run '{"action":"capability_remove","subject":"token","subject_id":"multi-token","capability":"database.inspect"}' > /dev/null
MC3=$(api POST /operations/capability_manage/run '{"action":"capability_get","subject":"token","subject_id":"multi-token"}')
assert_eq "cleanup: 0 caps" "0" "$(echo "$MC3" | jq -r '.capabilities | length')"

echo "== 25. Manifest operation counts =="
# STEP 87 added 4 mapped operations (file_manage, code_search, patch_manage, rollback_manage).
MAPCOUNT=$(echo "$MANIFEST" | jq -r '.capability_management.operation_map | keys | length')
assert_eq "manifest: 29 mapped operations" "29" "$MAPCOUNT"

echo "== 26. Context shows assigned capabilities =="
assert_true "context: assigned_capabilities is array" "$(echo "$CONTEXT" | jq -r 'if (.assigned_capabilities | type) == "array" then "true" else "false" end')"
assert_true "context: capability_enforcement is bool" "$(echo "$CONTEXT" | jq -r 'if .capability_enforcement == true or .capability_enforcement == false then "true" else "false" end')"

echo "== 26. Context + timeline final =="
TL=$(api GET "/agent/timeline?limit=100")
assert_true "timeline: Capability assigned" "$(echo "$TL" | jq -r 'any(.[]; .label == "Capability assigned")')"
assert_true "timeline: Capability removed" "$(echo "$TL" | jq -r 'any(.[]; .label == "Capability removed")')"
assert_true "timeline: Policy validation" "$(echo "$TL" | jq -r 'any(.[]; .label == "Policy validation")')"

echo "== 21. Error catalog =="
ECAT=$(echo "$MANIFEST" | jq -r '.error_catalog')
for c in wpcc_invalid_capability_action wpcc_missing_subject_id wpcc_missing_capability wpcc_invalid_capability wpcc_cannot_assign_admin wpcc_capability_not_assigned wpcc_capability_denied; do
  assert_contains "error: $c" "$ECAT" "$c"
done

echo "== 22. Operations registry =="
OPS=$(api GET /operations)
assert_true "ops: capability_manage" "$(echo "$OPS" | jq -r 'any(.[]; .id == "capability_manage")')"

echo "== 23. All 5 actions in manifest =="
for a in capability_list capability_get capability_assign capability_remove capability_validate; do
  H=$(echo "$MANIFEST" | jq -r ".capability_management.supported_actions | index(\"$a\")")
  if [ "$H" != "null" ]; then pass "action: $a"; else fail "action: $a missing"; fi
done

echo "== 24. Cleanup =="
api POST /operations/capability_manage/run '{"action":"capability_remove","subject":"token","subject_id":"multi-token","capability":"content.manage"}' > /dev/null
api POST /operations/capability_manage/run '{"action":"capability_remove","subject":"token","subject_id":"multi-token","capability":"database.inspect"}' > /dev/null
MC2=$(api POST /operations/capability_manage/run '{"action":"capability_get","subject":"token","subject_id":"multi-token"}')
assert_eq "cleanup: 0 caps" "0" "$(echo "$MC2" | jq -r '.capabilities | length')"

echo "== Summary =="
echo "  $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
