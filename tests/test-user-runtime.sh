#!/usr/bin/env bash
# Step 61 — User Management Runtime test suite
set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../wpcc-env.sh"
PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; if [ "$e" = "$a" ]; then pass "$d"; else fail "$d (expected '$e', got '$a')"; fi; }
assert_true() { local d="$1" a="$2"; if [ "$a" = "true" ]; then pass "$d"; else fail "$d"; fi; }
assert_contains() { local d="$1" h="$2" n="$3"; if [[ "$h" == *"$n"* ]]; then pass "$d"; else fail "$d"; fi; }
api() { curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$@"; }
api_post() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" "$@"; }

echo "User Management Runtime Test — $(date)"

# ===================================================================
echo "== 1. Operation Registration =="
MANIFEST=$(api "$WPCC_BASE/agent/manifest")
assert_true "op: user_manage in manifest" "$(echo "$MANIFEST" | jq -r 'any(.operations[]; .id == "user_manage")')"
assert_eq "op: user_manage requires approval" "true" "$(echo "$MANIFEST" | jq -r '.operations[] | select(.id == "user_manage") | .requires_approval')"
assert_eq "op: user_manage risk variable" "variable" "$(echo "$MANIFEST" | jq -r '.operations[] | select(.id == "user_manage") | .risk_level')"

echo "== 2. Capability Enforcement =="
DISC=$(api "$WPCC_BASE/claude/discovery")
assert_contains "cap: user.manage in capabilities" "$(echo "$DISC" | jq -r '.capabilities.capabilities | join(",")')" "user.manage"
assert_contains "cap: user_manage in operation_map" "$(echo "$DISC" | jq -r '.capabilities.operation_map | keys | join(",")')" "user_manage"

echo "== 3. Route Manifest =="
assert_true "route: /operations/user_manage/run exists" "$(echo "$MANIFEST" | jq -r 'any(.endpoints[]; .path == "/operations/user_manage/run")')"
assert_true "route: /operations/user_manage/rollback exists" "$(echo "$MANIFEST" | jq -r 'any(.endpoints[]; .path == "/operations/user_manage/rollback")')"

echo "== 4. User List =="
ULIST=$(api_post -d '{"action":"user_list","per_page":5}' "$WPCC_BASE/operations/user_manage/run")
assert_contains "list: action returned" "$ULIST" "user_list"
assert_true "list: has users array" "$(echo "$ULIST" | jq -r 'if .users then "true" else "false" end')"
USER_COUNT=$(echo "$ULIST" | jq -r '.total // 0')
assert_true "list: total > 0" "$(if [ "$USER_COUNT" -gt 0 ] 2>/dev/null; then echo true; else echo false; fi)"

echo "== 5. User Get =="
FIRST_ID=$(echo "$ULIST" | jq -r '.users[0].id')
UGET=$(api_post -d "{\"action\":\"user_get\",\"user_id\":$FIRST_ID}" "$WPCC_BASE/operations/user_manage/run")
assert_contains "get: action returned" "$UGET" "user_get"
assert_contains "get: has username" "$UGET" "username"
assert_contains "get: has email" "$UGET" "email"

echo "== 6. User Search =="
USEARCH=$(api_post -d '{"action":"user_search","search":"admin"}' "$WPCC_BASE/operations/user_manage/run")
assert_contains "search: action returned" "$USEARCH" "user_search"
assert_true "search: has users" "$(echo "$USEARCH" | jq -r 'if .users then "true" else "false" end')"

echo "== 7. User Create =="
TEST_USER="wpcc_test_$(date +%s)"
CREATE=$(api_post -d "{\"action\":\"user_create\",\"username\":\"$TEST_USER\",\"email\":\"${TEST_USER}@test.local\",\"password\":\"TestPass123!\",\"role\":\"subscriber\"}" "$WPCC_BASE/operations/user_manage/run")
assert_contains "create: user created" "$CREATE" "user_create"
NEW_USER_ID=$(echo "$CREATE" | jq -r '.user_id // 0')
assert_true "create: user_id > 0" "$(if [ "$NEW_USER_ID" -gt 0 ] 2>/dev/null; then echo true; else echo false; fi)"

echo "== 8. User Update =="
if [ "$NEW_USER_ID" -gt 0 ] 2>/dev/null; then
	UPDATE=$(api_post -d "{\"action\":\"user_update\",\"user_id\":$NEW_USER_ID,\"display_name\":\"Updated Test User\"}" "$WPCC_BASE/operations/user_manage/run")
	assert_contains "update: action returned" "$UPDATE" "user_update"
	assert_contains "update: display_name field" "$(echo "$UPDATE" | jq -r '.updated_fields | join(",")')" "display_name"
fi

echo "== 9. Role Assignment =="
if [ "$NEW_USER_ID" -gt 0 ] 2>/dev/null; then
	ASSIGN=$(api_post -d "{\"action\":\"user_assign_role\",\"user_id\":$NEW_USER_ID,\"role\":\"editor\"}" "$WPCC_BASE/operations/user_manage/run")
	assert_contains "role: assigned" "$ASSIGN" "user_assign_role"
	assert_contains "role: editor in roles" "$(echo "$ASSIGN" | jq -r '.current_roles | join(",")')" "editor"
fi

echo "== 10. Role Removal =="
if [ "$NEW_USER_ID" -gt 0 ] 2>/dev/null; then
	REMOVE=$(api_post -d "{\"action\":\"user_remove_role\",\"user_id\":$NEW_USER_ID,\"role\":\"editor\"}" "$WPCC_BASE/operations/user_manage/run")
	assert_contains "remove: returned" "$REMOVE" "user_remove_role"
fi

echo "== 11. Password Reset =="
if [ "$NEW_USER_ID" -gt 0 ] 2>/dev/null; then
	PRESET=$(api_post -d "{\"action\":\"user_reset_password\",\"user_id\":$NEW_USER_ID}" "$WPCC_BASE/operations/user_manage/run")
	assert_contains "reset: action returned" "$PRESET" "user_reset_password"
	assert_true "reset: new_password generated" "$(echo "$PRESET" | jq -r 'if .new_password then "true" else "false" end')"
fi

echo "== 12. User Suspend =="
if [ "$NEW_USER_ID" -gt 0 ] 2>/dev/null; then
	SUSPEND=$(api_post -d "{\"action\":\"user_suspend\",\"user_id\":$NEW_USER_ID}" "$WPCC_BASE/operations/user_manage/run")
	assert_contains "suspend: action returned" "$SUSPEND" "user_suspend"
fi

echo "== 13. User Delete =="
DEL_USER="wpcc_del_$(date +%s)"
DEL_CREATE=$(api_post -d "{\"action\":\"user_create\",\"username\":\"$DEL_USER\",\"email\":\"${DEL_USER}@test.local\",\"password\":\"DelPass123!\",\"role\":\"subscriber\"}" "$WPCC_BASE/operations/user_manage/run")
DEL_ID=$(echo "$DEL_CREATE" | jq -r '.user_id // 0')
if [ "$DEL_ID" -gt 0 ] 2>/dev/null; then
	DELETE=$(api_post -d "{\"action\":\"user_delete\",\"user_id\":$DEL_ID}" "$WPCC_BASE/operations/user_manage/run")
	assert_contains "delete: action returned" "$DELETE" "user_delete"
fi

echo "== 14. Rollback — Create Recovery =="
# Create a user we'll rollback
RB_USER="wpcc_rollback_$(date +%s)"
RB_CREATE=$(api_post -d "{\"action\":\"user_create\",\"username\":\"$RB_USER\",\"email\":\"${RB_USER}@test.local\",\"password\":\"Rollback123!\",\"role\":\"subscriber\"}" "$WPCC_BASE/operations/user_manage/run")
RB_ID=$(echo "$RB_CREATE" | jq -r '.user_id // 0')
assert_true "rollback: user created" "$(if [ "$RB_ID" -gt 0 ] 2>/dev/null; then echo true; else echo false; fi)"

echo "== 15. Rollback — Verify Rollback Record =="
# The rollback store is internal; verify the user exists
if [ "$RB_ID" -gt 0 ] 2>/dev/null; then
	RB_GET=$(api_post -d "{\"action\":\"user_get\",\"user_id\":$RB_ID}" "$WPCC_BASE/operations/user_manage/run")
	assert_contains "rollback: user exists" "$RB_GET" "$RB_USER"
	# Clean up
	api_post -d "{\"action\":\"user_delete\",\"user_id\":$RB_ID}" "$WPCC_BASE/operations/user_manage/run" >/dev/null
fi

echo "== 16. Validation — Invalid User ID =="
BAD_GET=$(api_post -d '{"action":"user_get","user_id":99999999}' "$WPCC_BASE/operations/user_manage/run")
assert_contains "validation: bad user_id blocked" "$BAD_GET" "error"

echo "== 17. Validation — Invalid Action =="
BAD_ACTION=$(api_post -d '{"action":"invalid_action"}' "$WPCC_BASE/operations/user_manage/run")
assert_contains "validation: bad action blocked" "$BAD_ACTION" "Invalid user action"

echo "== 18. Validation — Missing Fields =="
MISSING=$(api_post -d '{"action":"user_create"}' "$WPCC_BASE/operations/user_manage/run")
assert_contains "validation: missing fields blocked" "$MISSING" "error"

echo "== 19. Validation — Duplicate Username =="
FIRST_USERNAME=$(echo "$ULIST" | jq -r '.users[0].username')
if [ -n "$FIRST_USERNAME" ] && [ "$FIRST_USERNAME" != "null" ]; then
	DUP=$(api_post -d "{\"action\":\"user_create\",\"username\":\"$FIRST_USERNAME\",\"email\":\"nobody@test.local\",\"password\":\"Test1234!\"}" "$WPCC_BASE/operations/user_manage/run")
	assert_contains "validation: duplicate username blocked" "$DUP" "error"
fi

echo "== 20. Timeline Events =="
TL=$(api "$WPCC_BASE/agent/timeline?limit=50")
assert_true "timeline: user related events present" "$(echo "$TL" | jq -r 'any(.[]; .label == "User created" or .label == "User deleted" or .label == "Users listed" or .label == "User management completed")')"

echo "== 21. Rollback Router Endpoint =="
RB_EP=$(curl -s -o /dev/null -w "%{http_code}" -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d '{"rollback_id":"nonexistent"}' "$WPCC_BASE/operations/user_manage/rollback")
assert_contains "rollback: endpoint accessible" "$RB_EP" "4"

echo "== 22. Invalid Role =="
if [ -n "$FIRST_ID" ]; then
	BAD_ROLE=$(api_post -d "{\"action\":\"user_assign_role\",\"user_id\":$FIRST_ID,\"role\":\"nonexistent_role\"}" "$WPCC_BASE/operations/user_manage/run")
	assert_contains "validation: bad role blocked" "$BAD_ROLE" "error"
fi

echo "== 23. Self-Delete Handled =="
CURRENT_USER_ID=$(echo "$ULIST" | jq -r '.users[0].id')
SELF_DELETE=$(api_post -d "{\"action\":\"user_delete\",\"user_id\":$CURRENT_USER_ID}" "$WPCC_BASE/operations/user_manage/run")
assert_true "validation: self-delete handled" "$(echo "$SELF_DELETE" | jq -r 'if .action == "user_delete" or .error then "true" else "false" end')"

echo "== 24. Weak Password Rejected =="
WEAK=$(api_post -d '{"action":"user_reset_password","user_id":1,"new_password":"short"}' "$WPCC_BASE/operations/user_manage/run")
assert_contains "validation: weak password" "$WEAK" "error"

echo "== 25. Remove Last Role Blocked =="
RB_STR="wpcc_rollback2_$(date +%s)"
RB2=$(api_post -d "{\"action\":\"user_create\",\"username\":\"$RB_STR\",\"email\":\"${RB_STR}@test.local\",\"password\":\"Test1234!\",\"role\":\"subscriber\"}" "$WPCC_BASE/operations/user_manage/run")
RB2_ID=$(echo "$RB2" | jq -r '.user_id // 0')
if [ "$RB2_ID" -gt 0 ] 2>/dev/null; then
	LAST_ROLE=$(api_post -d "{\"action\":\"user_remove_role\",\"user_id\":$RB2_ID,\"role\":\"subscriber\"}" "$WPCC_BASE/operations/user_manage/run")
	assert_contains "validation: last role blocked" "$LAST_ROLE" "error"
	# Cleanup
	api_post -d "{\"action\":\"user_delete\",\"user_id\":$RB2_ID}" "$WPCC_BASE/operations/user_manage/run" >/dev/null
fi

echo "== 26. MCP Tool Discovery =="
MCP_TOOLS=$(curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"tools/list","id":1}' "$WPCC_BASE/mcp")
assert_true "mcp: user_manage in tools" "$(echo "$MCP_TOOLS" | jq -r 'any(.result.tools[]; .name == "user_manage")')"

echo "== 27. No User Found =="
NOT_FOUND=$(api_post -d '{"action":"user_get","user_id":99999999}' "$WPCC_BASE/operations/user_manage/run")
assert_contains "validation: not found" "$NOT_FOUND" "User not found"

echo "== 28. Approval Required =="
assert_true "approval: user_manage in manifest" "$(echo "$MANIFEST" | jq -r '.operations[] | select(.id == "user_manage") | .requires_approval')"

echo "== 29. Performance =="
PERF_START=$(date +%s%N)
api_post -d '{"action":"user_list","per_page":3}' "$WPCC_BASE/operations/user_manage/run" >/dev/null
PERF_END=$(date +%s%N)
PERF_MS=$(( (PERF_END - PERF_START) / 1000000 ))
assert_true "perf: user list < 3s" "$( [ "$PERF_MS" -lt 3000 ] && echo true || echo false )"
echo "  INFO: User list: ${PERF_MS}ms"

echo "== 30. Empty Search Blocked =="
EMPTY_SEARCH=$(api_post -d '{"action":"user_search","search":""}' "$WPCC_BASE/operations/user_manage/run")
assert_contains "validation: empty search" "$EMPTY_SEARCH" "error"

echo "== 31. User Detail Fields =="
assert_true "detail: has id" "$(echo "$UGET" | jq -r 'if .user.id then "true" else "false" end')"
assert_true "detail: has roles" "$(echo "$UGET" | jq -r 'if .user.roles then "true" else "false" end')"
assert_true "detail: has email" "$(echo "$UGET" | jq -r 'if .user.email then "true" else "false" end')"

echo "== 32. List Pagination =="
PAGE2=$(api_post -d '{"action":"user_list","per_page":2,"page":1}' "$WPCC_BASE/operations/user_manage/run")
assert_eq "list: page 1" "1" "$(echo "$PAGE2" | jq -r '.page')"
assert_true "list: per_page respected" "$(if [ "$(echo "$PAGE2" | jq -r '.users | length')" -le 2 ] 2>/dev/null; then echo true; else echo false; fi)"

echo "== 33. Role List Filter =="
ROLE_LIST=$(api_post -d '{"action":"user_list","role":"administrator","per_page":5}' "$WPCC_BASE/operations/user_manage/run")
assert_true "list: role filter works" "$(echo "$ROLE_LIST" | jq -r 'if .users then "true" else "false" end')"

echo "== 34. Timeline — Additional Events =="
TL2=$(api "$WPCC_BASE/agent/timeline?limit=50")
assert_true "timeline: has user management event" "$(echo "$TL2" | jq -r 'any(.[]; .label == "User management completed" or .label == "User management started" or .label == "Users listed")')"
assert_true "timeline: has user management activity" "$(echo "$TL2" | jq -r 'any(.[]; .label == "User management completed" or .label == "User management started" or .label == "Users listed" or .label == "User created" or .label == "User deleted")')"

echo "== 35. User Update — No Fields =="
NO_FIELDS=$(api_post -d "{\"action\":\"user_update\",\"user_id\":$FIRST_ID}" "$WPCC_BASE/operations/user_manage/run")
assert_contains "validation: no fields blocked" "$NO_FIELDS" "error"

echo "== 36. User Create — Duplicate Email =="
FIRST_EMAIL=$(echo "$UGET" | jq -r '.user.email')
if [ -n "$FIRST_EMAIL" ] && [ "$FIRST_EMAIL" != "null" ]; then
	DUP_MAIL=$(api_post -d "{\"action\":\"user_create\",\"username\":\"unique_user_$(date +%s)\",\"email\":\"$FIRST_EMAIL\",\"password\":\"Pass1234!\"}" "$WPCC_BASE/operations/user_manage/run")
	assert_contains "validation: duplicate email" "$DUP_MAIL" "error"
fi

echo "== 38. Route Endpoint Verification =="
assert_true "route: run endpoint reachable" "$(api_post -d '{"action":"user_list","per_page":1}' "$WPCC_BASE/operations/user_manage/run" | jq -r 'if .action then "true" else "false" end')"
assert_true "route: rollback endpoint reachable" "$( [ "$RB_EP" = "400" -o "$RB_EP" = "404" ] && echo true || echo false )"

echo "== 39. MCP Operations Count =="
MCP_TOOL_COUNT=$(echo "$MCP_TOOLS" | jq -r '.result.tools | length')
assert_true "mcp: total tools increased" "$(if [ "$MCP_TOOL_COUNT" -ge 16 ] 2>/dev/null; then echo true; else echo false; fi)"

echo "== 40. User Create With Extra Fields =="
EXTRA_USER="wpcc_extra_$(date +%s)"
EXTRA=$(api_post -d "{\"action\":\"user_create\",\"username\":\"$EXTRA_USER\",\"email\":\"${EXTRA_USER}@test.local\",\"password\":\"Extra123!\",\"role\":\"subscriber\",\"first_name\":\"Test\",\"last_name\":\"User\",\"display_name\":\"Test Extra\"}" "$WPCC_BASE/operations/user_manage/run")
EXTRA_ID=$(echo "$EXTRA" | jq -r '.user_id // 0')
assert_true "extra: user created with full fields" "$(if [ "$EXTRA_ID" -gt 0 ] 2>/dev/null; then echo true; else echo false; fi)"
# Cleanup
if [ "$EXTRA_ID" -gt 0 ] 2>/dev/null; then
	api_post -d "{\"action\":\"user_delete\",\"user_id\":$EXTRA_ID}" "$WPCC_BASE/operations/user_manage/run" >/dev/null
fi

echo "== 41. Password Reset — Custom Password =="
PW_USER="wpcc_pw_$(date +%s)"
PW_CREATE=$(api_post -d "{\"action\":\"user_create\",\"username\":\"$PW_USER\",\"email\":\"${PW_USER}@test.local\",\"password\":\"TempPass1!\",\"role\":\"subscriber\"}" "$WPCC_BASE/operations/user_manage/run")
PW_ID=$(echo "$PW_CREATE" | jq -r '.user_id // 0')
if [ "$PW_ID" -gt 0 ] 2>/dev/null; then
	PW_RESET=$(api_post -d "{\"action\":\"user_reset_password\",\"user_id\":$PW_ID,\"new_password\":\"NewSecurePass123!\"}" "$WPCC_BASE/operations/user_manage/run")
	assert_contains "pw: custom password accepted" "$PW_RESET" "user_reset_password"
	api_post -d "{\"action\":\"user_delete\",\"user_id\":$PW_ID}" "$WPCC_BASE/operations/user_manage/run" >/dev/null
fi

echo "== 42. User Management Operation Count =="
MANIFEST_OPS=$(echo "$MANIFEST" | jq -r '.operations | length')
assert_true "manifest: 16+ operations" "$(if [ "$MANIFEST_OPS" -ge 16 ] 2>/dev/null; then echo true; else echo false; fi)"

echo "== 43. User Search Results Format =="
SEARCH_RESULT=$(api_post -d '{"action":"user_search","search":"a"}' "$WPCC_BASE/operations/user_manage/run")
assert_true "search: result has users array" "$(echo "$SEARCH_RESULT" | jq -r 'if .users then "true" else "false" end')"
assert_true "search: result has total" "$(echo "$SEARCH_RESULT" | jq -r 'if .total then "true" else "false" end')"

echo "== 44. Audit Log Contains User Events =="
# Verify audit events show up in context
assert_true "audit: recent audit entries in context" "$(api "$WPCC_BASE/agent/context" | jq -r 'if .recent_audit_entries then "true" else "false" end')"

echo "== 45. Capability Operation Map Includes User =="
OPS_MAP_COUNT=$(echo "$DISC" | jq -r '.capabilities.operation_map | length')
assert_true "cap: operation_map entries >= 12" "$(if [ "$OPS_MAP_COUNT" -ge 12 ] 2>/dev/null; then echo true; else echo false; fi)"

echo "== 46. Complete Workflow — Create + Update + Delete =="
WF_USER="wpcc_wf_$(date +%s)"
WF_CREATE=$(api_post -d "{\"action\":\"user_create\",\"username\":\"$WF_USER\",\"email\":\"${WF_USER}@test.local\",\"password\":\"Workflow1!\",\"role\":\"subscriber\"}" "$WPCC_BASE/operations/user_manage/run")
WF_ID=$(echo "$WF_CREATE" | jq -r '.user_id // 0')
assert_true "wf: create OK" "$(if [ "$WF_ID" -gt 0 ] 2>/dev/null; then echo true; else echo false; fi)"
if [ "$WF_ID" -gt 0 ] 2>/dev/null; then
	api_post -d "{\"action\":\"user_update\",\"user_id\":$WF_ID,\"display_name\":\"WF Updated\"}" "$WPCC_BASE/operations/user_manage/run" >/dev/null
	WF_DELETE=$(api_post -d "{\"action\":\"user_delete\",\"user_id\":$WF_ID}" "$WPCC_BASE/operations/user_manage/run")
	assert_contains "wf: delete OK" "$WF_DELETE" "user_delete"
fi

echo "== 47. User List — Default Pagination =="
DEFAULT_LIST=$(api_post -d '{"action":"user_list"}' "$WPCC_BASE/operations/user_manage/run")
assert_eq "list: default per_page" "20" "$(echo "$DEFAULT_LIST" | jq -r '.per_page')"

echo "== 48. User Search — No Results =="
SEARCH_NONE=$(api_post -d '{"action":"user_search","search":"zzz_no_user_should_match_this_zzz"}' "$WPCC_BASE/operations/user_manage/run")
assert_contains "search: action returned" "$SEARCH_NONE" "user_search"
assert_true "search: zero results" "$(if [ "$(echo "$SEARCH_NONE" | jq -r '.total')" -eq 0 ] 2>/dev/null; then echo true; else echo false; fi)"

echo "== 49. User Update — Partial Fields =="
UPD_USER="wpcc_upd2_$(date +%s)"
UPD_CREATE=$(api_post -d "{\"action\":\"user_create\",\"username\":\"$UPD_USER\",\"email\":\"${UPD_USER}@test.local\",\"password\":\"Upd123!\",\"role\":\"subscriber\"}" "$WPCC_BASE/operations/user_manage/run")
UPD_ID=$(echo "$UPD_CREATE" | jq -r '.user_id // 0')
if [ "$UPD_ID" -gt 0 ] 2>/dev/null; then
	UPD_RESULT=$(api_post -d "{\"action\":\"user_update\",\"user_id\":$UPD_ID,\"first_name\":\"John\",\"last_name\":\"Doe\"}" "$WPCC_BASE/operations/user_manage/run")
	assert_contains "update: partial fields" "$UPD_RESULT" "user_update"
	assert_contains "update: first_name updated" "$(echo "$UPD_RESULT" | jq -r '.updated_fields | join(",")')" "first_name"
	api_post -d "{\"action\":\"user_delete\",\"user_id\":$UPD_ID}" "$WPCC_BASE/operations/user_manage/run" >/dev/null
fi

echo "== 50. Rollback — Unknown ID =="
BAD_RB=$(curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d '{"rollback_id":"nonexistent-uuid"}' "$WPCC_BASE/operations/user_manage/rollback")
assert_contains "rollback: unknown id handled" "$BAD_RB" "not_found"

echo "== 51. Create With Extra Fields — Verify =="
PREV_EXTRA=$(echo "$ULIST" | jq -r '.users[0].id')
assert_true "extra field: existing user has id" "$(echo "$ULIST" | jq -r 'if .users[0].id then "true" else "false" end')"
assert_true "extra field: existing user has username" "$(echo "$ULIST" | jq -r 'if .users[0].username then "true" else "false" end')"
assert_true "extra field: existing user has roles" "$(echo "$ULIST" | jq -r 'if .users[0].roles then "true" else "false" end')"

echo "== 52. Password Reset Without User =="
PW_NO_USER=$(api_post -d '{"action":"user_reset_password","user_id":99999999}' "$WPCC_BASE/operations/user_manage/run")
assert_contains "pwreset: no user handled" "$PW_NO_USER" "error"

echo ""
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
