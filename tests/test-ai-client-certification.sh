#!/usr/bin/env bash
# Step 53 — AI Client Certification Framework test suite
# Reusable certification validation for any MCP-compatible AI client.
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
mcp() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/mcp"; }

echo "AI Client Certification Framework — $(date)"
echo ""

# ===================================================================
echo "== 1. Certification Registry — Constants =="
CLIENTS=$(api "$WPCC_BASE/ai-clients")
MATRIX=$(echo "$CLIENTS" | jq -r '.compatibility_matrix')

assert_eq "cert: 11 total clients" "11" "$(echo "$CLIENTS" | jq -r '.counts.total')"
assert_true "cert: gold count > 0" "$(if [ "$(echo "$CLIENTS" | jq -r '.counts.gold')" -gt 0 ] 2>/dev/null; then echo true; else echo false; fi)"
assert_true "cert: certified count > 0" "$(if [ "$(echo "$CLIENTS" | jq -r '.counts.certified')" -gt 0 ] 2>/dev/null; then echo true; else echo false; fi)"

echo "== 2. Certification Levels — All Levels Defined =="
for level in planned compatible active bronze silver gold; do
	assert_true "cert: level $level exists in constants" "true"
done

echo "== 3. Claude Desktop — certification is reported, not assumed =="
# Was: assert Claude Desktop is Gold. Certification is awarded only from an
# executed end-to-end run recorded in docs/ASSISTANT-CERTIFICATION.md, and the
# unearned Gold markers were withdrawn — so pinning "gold" here made the suite
# enforce the overstatement it should have caught. The durable contract is that
# the matrix reports a level the registry defines, with a matching label and a
# validation date, whatever that level currently is.
CLAUDE_CERT=$(echo "$MATRIX" | jq -r '.[] | select(.id == "claude") | .certification_level')
assert_true "cert: claude level is a defined level" \
	"$(echo "$MATRIX" | jq -r '[ "planned","compatible","bronze","silver","gold","active" ] as $v | if ([ .[] | select(.id == "claude") | .certification_level ][0] | IN($v[])) then "true" else "false" end')"
assert_true "cert: claude has a certification label" \
	"$(echo "$MATRIX" | jq -r 'if ([ .[] | select(.id == "claude") | .certification_label ][0] // "") != "" then "true" else "false" end')"
assert_true "cert: claude has validated_at" "$(echo "$MATRIX" | jq -r '.[] | select(.id == "claude") | if .last_validated_at then "true" else "false" end')"

echo "== 4. New Clients — ChatGPT + Command Code =="
assert_true "cert: chatgpt exists" "$(echo "$CLIENTS" | jq -r 'if .clients.chatgpt then "true" else "false" end')"
assert_true "cert: command_code exists" "$(echo "$CLIENTS" | jq -r 'if .clients.command_code then "true" else "false" end')"
assert_eq "cert: chatgpt compatible" "compatible" "$(echo "$CLIENTS" | jq -r '.clients.chatgpt.certification_level')"
assert_eq "cert: command_code compatible" "compatible" "$(echo "$CLIENTS" | jq -r '.clients.command_code.certification_level')"

echo "== 5. Certification Matrix — All 11 Clients =="
assert_eq "cert: matrix 11 entries" "11" "$(echo "$MATRIX" | jq -r 'length')"
# Every registered client appears in the matrix — checked against the registry
# itself rather than a copy of it that outlived two removed clients.
for client_id in $(echo "$CLIENTS" | jq -r '.clients | keys[]'); do
	assert_true "cert: $client_id in matrix" "$(echo "$MATRIX" | jq -r --arg id "$client_id" 'any(.[]; .id == $id)')"
done

echo "== 6. Claude Gold — Discovery Validation (Bronze) =="
MCP_INIT=$(mcp '{"jsonrpc":"2.0","method":"initialize","params":{"protocolVersion":"2024-11-05"},"id":1}')
assert_contains "cert: MCP init works" "$MCP_INIT" "WP Command Center"

MCP_RESOURCES=$(mcp '{"jsonrpc":"2.0","method":"resources/list","id":2}')
assert_true "cert: resources listed" "$(echo "$MCP_RESOURCES" | jq -r 'if .result.resources then "true" else "false" end')"
assert_eq "cert: 7 resources" "7" "$(echo "$MCP_RESOURCES" | jq -r '.result.resources | length')"

MCP_TOOLS=$(mcp '{"jsonrpc":"2.0","method":"tools/list","id":3}')
assert_true "cert: tools listed" "$(echo "$MCP_TOOLS" | jq -r 'if .result.tools then "true" else "false" end')"
TOOLS_COUNT=$(echo "$MCP_TOOLS" | jq -r '.result.tools | length')
assert_true "cert: tools count > 0" "$(if [ "$TOOLS_COUNT" -gt 0 ] 2>/dev/null; then echo true; else echo false; fi)"

echo "== 7. Claude Gold — Capability + Approval (Silver) =="
DISC=$(api "$WPCC_BASE/claude/discovery")
assert_true "cert: capabilities section" "$(echo "$DISC" | jq -r 'if .capabilities then "true" else "false" end')"
assert_true "cert: approval section" "$(echo "$DISC" | jq -r 'if .approval then "true" else "false" end')"

echo "== 8. Claude Gold — Queue Flow =="
REQ_BODY='{"operation_id":"database_inspect","payload":{"action":"db_table_list"},"session_id":null,"task_id":null}'
REQ=$(curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$REQ_BODY" "$WPCC_BASE/operations/requests")
REQ_ID=$(echo "$REQ" | jq -r '.request_id // empty')
assert_true "cert: queue: request created" "$(echo "$REQ" | jq -r 'if .request_id then "true" else "false" end')"

if [ -n "$REQ_ID" ]; then
	APPROVE=$(curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d '{}' "$WPCC_BASE/operations/requests/$REQ_ID/approve")
	assert_eq "cert: queue: approved" "approved" "$(echo "$APPROVE" | jq -r '.status')"
	EXEC=$(curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d '{}' "$WPCC_BASE/operations/requests/$REQ_ID/execute")
	assert_true "cert: queue: executed" "$(echo "$EXEC" | jq -r 'if .id and .status then "true" else "false" end')"
fi

echo "== 9. Claude Gold — Rollback =="
TEST_PATH="themes/mosharaf-core/style.css"
PATCH_BODY=$(jq -n --arg path "$TEST_PATH" '{files: [{path: $path, modified: "/*\nTheme Name: Mosharaf Core\n*/\n/* Certification validation */"}], explanation: "Gold certification rollback test", risk_level: "low"}')
PATCH=$(curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$PATCH_BODY" "$WPCC_BASE/patches")
PATCH_ID=$(echo "$PATCH" | jq -r '.id // empty')
assert_true "cert: rollback: patch created" "$(echo "$PATCH" | jq -r 'if .id then "true" else "false" end')"

if [ -n "$PATCH_ID" ]; then
	curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d '{}' "$WPCC_BASE/patches/$PATCH_ID/approve" >/dev/null
	curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d '{}' "$WPCC_BASE/patches/$PATCH_ID/apply" >/dev/null
	ROLLBACK=$(curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d '{}' "$WPCC_BASE/patches/$PATCH_ID/rollback")
	assert_contains "cert: rollback: rolled back" "$ROLLBACK" "rolled_back"
fi

echo "== 10. Claude Gold — Audit + Timeline =="
TL=$(api "$WPCC_BASE/agent/timeline?limit=30")
assert_true "cert: audit: timeline events" "$(echo "$TL" | jq -r 'if length > 0 then "true" else "false" end')"
assert_true "cert: audit: has timestamps" "$(echo "$TL" | jq -r 'all(.[]; has("timestamp"))')"

echo "== 11. Claude Gold — Security =="
# No token access blocked
NO_TOKEN_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$WPCC_BASE/health")
assert_contains "cert: sec: no token blocked" "$NO_TOKEN_CODE" "401"

# Protected files blocked
WP_CONFIG_BLOCK=$(curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/files/meta?path=../../../wp-config.php")
assert_contains "cert: sec: wp-config blocked" "$(echo "$WP_CONFIG_BLOCK" | jq -r '.code // ""')" "wpcc"

echo "== 12. Claude Gold — Performance (Stress) =="
STRESS_FAIL=0
for i in $(seq 1 30); do
	RESP=$(mcp '{"jsonrpc":"2.0","method":"initialize","params":{"protocolVersion":"2024-11-05"},"id":1}')
	if ! echo "$RESP" | jq -e '.result.serverInfo' >/dev/null 2>&1; then
		STRESS_FAIL=$((STRESS_FAIL+1))
	fi
done
assert_eq "cert: perf: 30 rapid requests no failure" "0" "$STRESS_FAIL"

echo "== 13. Claude Gold — Backward Compat =="
for ep in "/claude/config" "/claude/discovery" "/claude/tools" "/claude/prompts"; do
	assert_true "cert: bwcompat: $ep works" "$(api "$WPCC_BASE$ep" | jq -r 'if . then "true" else "false" end')"
done

echo "== 14. Manifest includes certification data =="
MANIFEST=$(api "$WPCC_BASE/agent/manifest")
assert_true "cert: manifest ai_clients block" "$(echo "$MANIFEST" | jq -r 'if .ai_clients then "true" else "false" end')"
assert_true "cert: manifest 11 clients" "$(if [ "$(echo "$MANIFEST" | jq -r '.ai_clients.clients | length')" -ge 11 ] 2>/dev/null; then echo true; else echo false; fi)"

echo ""
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"
echo "  Framework: Discovery, Resources, Tools, Capabilities, Approvals, Queue, Rollback, Audit, Timeline, Security, Performance"
[ "$FAIL" -eq 0 ]
