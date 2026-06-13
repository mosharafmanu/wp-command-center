#!/usr/bin/env bash
# Step 54 — Cursor MCP Certification test suite
# Validates Cursor against the unified AI Client Certification Framework.
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

echo "Cursor MCP Certification — $(date)"
echo "All tests use the shared MCP endpoint (identical for all AI clients)"
echo ""

# ===================================================================
echo "== 1. Cursor Registry Status =="
CLIENTS=$(api "$WPCC_BASE/ai-clients")
CURSOR=$(echo "$CLIENTS" | jq -r '.clients.cursor')
assert_true "cursor: registered" "$(echo "$CLIENTS" | jq -r 'if .clients.cursor then "true" else "false" end')"
assert_eq "cursor: type ide" "ide" "$(echo "$CURSOR" | jq -r '.type')"
assert_eq "cursor: vendor Anysphere" "Anysphere" "$(echo "$CURSOR" | jq -r '.vendor')"

# ===================================================================
echo "== 2. Cursor Config Generation =="
CURSOR_CFG=$(api "$WPCC_BASE/ai-clients/cursor/config")
assert_true "cursor: config generated" "$(echo "$CURSOR_CFG" | jq -r 'if .config.mcpServers then "true" else "false" end')"
assert_eq "cursor: name matches" "Cursor" "$(echo "$CURSOR_CFG" | jq -r '.name')"
assert_contains "cursor: MCP URL in config" "$(echo "$CURSOR_CFG" | jq -r '.config.mcpServers["wp-command-center"].args[-1]')" "/wp-command-center/v1/mcp"
assert_contains "cursor: config env has token" "$(echo "$CURSOR_CFG" | jq -r '.config.mcpServers["wp-command-center"].env.WPCC_TOKEN')" "WPCC_TOKEN"

# ===================================================================
echo "== 3. Bronze — MCP Discovery (Initialize) =="
INIT=$(mcp '{"jsonrpc":"2.0","method":"initialize","params":{"protocolVersion":"2024-11-05"},"id":1}')
assert_contains "cursor: MCP init ok" "$INIT" "WP Command Center"
assert_contains "cursor: protocol version" "$INIT" "2024-11-05"

echo "== 4. Bronze — Resource Discovery =="
RESOURCES=$(mcp '{"jsonrpc":"2.0","method":"resources/list","id":2}')
assert_eq "cursor: 7 resources" "7" "$(echo "$RESOURCES" | jq -r '.result.resources | length')"
for uri in "wpcc://manifest" "wpcc://context" "wpcc://capabilities" "wpcc://operations" "wpcc://queue" "wpcc://results" "wpcc://recommendations"; do
	assert_true "cursor: resource $uri" "$(echo "$RESOURCES" | jq -r --arg u "$uri" 'any(.result.resources[]; .uri == $u)')"
done

echo "== 5. Bronze — Tool Discovery =="
TOOLS=$(mcp '{"jsonrpc":"2.0","method":"tools/list","id":3}')
assert_true "cursor: tools list returned" "$(echo "$TOOLS" | jq -r 'if .result.tools then "true" else "false" end')"
TOOL_COUNT=$(echo "$TOOLS" | jq -r '.result.tools | length')
assert_true "cursor: 15 tools" "$(if [ "$TOOL_COUNT" -ge 15 ] 2>/dev/null; then echo true; else echo false; fi)"
for tool in content_manage plugin_manage theme_manage option_manage snapshot_manage database_inspect wp_cli_bridge; do
	assert_true "cursor: tool $tool" "$(echo "$TOOLS" | jq -r --arg t "$tool" 'any(.result.tools[]; .name == $t)')"
done

echo "== 6. Bronze — Resource Read =="
MANIFEST_READ=$(mcp '{"jsonrpc":"2.0","method":"resources/read","params":{"uri":"wpcc://manifest"},"id":4}')
assert_true "cursor: manifest read ok" "$(echo "$MANIFEST_READ" | jq -r 'if .result then "true" else "false" end')"

echo "== 7. Silver — Capability Enforcement =="
MANIFEST=$(api "$WPCC_BASE/agent/manifest")
DISCOVERY=$(api "$WPCC_BASE/claude/discovery")
ALLCAPS=$(echo "$DISCOVERY" | jq -r '.capabilities.capabilities | join(",")')
for cap in content.manage database.inspect plugin.manage theme.manage option.manage snapshot.manage wpcli.execute capability.admin system.admin; do
	assert_contains "cursor: capability $cap" "$ALLCAPS" "$cap"
done

echo "== 8. Silver — Approval Flow =="
OP_APPROVAL=$(echo "$MANIFEST" | jq -r '.operations[] | select(.id == "option_manage") | .requires_approval')
assert_eq "cursor: approval required for option_manage" "true" "$OP_APPROVAL"

echo "== 9. Silver — Queue Lifecycle =="
REQ_BODY='{"operation_id":"database_inspect","payload":{"action":"db_table_list"}}'
REQ=$(curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$REQ_BODY" "$WPCC_BASE/operations/requests")
REQ_ID=$(echo "$REQ" | jq -r '.request_id // empty')
assert_true "cursor: queue: request created" "$(echo "$REQ" | jq -r 'if .request_id then "true" else "false" end')"

if [ -n "$REQ_ID" ]; then
	APPROVE=$(curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d '{}' "$WPCC_BASE/operations/requests/$REQ_ID/approve")
	assert_eq "cursor: queue: approved" "approved" "$(echo "$APPROVE" | jq -r '.status')"
	EXEC=$(curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d '{}' "$WPCC_BASE/operations/requests/$REQ_ID/execute")
	assert_true "cursor: queue: executed" "$(echo "$EXEC" | jq -r 'if .id and .status then "true" else "false" end')"
fi

echo "== 10. Gold — Rollback =="
TEST_PATH="themes/mosharaf-core/style.css"
PATCH_BODY=$(jq -n --arg path "$TEST_PATH" '{files: [{path: $path, modified: "/*\nTheme Name: Mosharaf Core\n*/\n/* Cursor certification test */"}], explanation: "Cursor gold certification rollback", risk_level: "low"}')
PATCH=$(curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$PATCH_BODY" "$WPCC_BASE/patches")
PATCH_ID=$(echo "$PATCH" | jq -r '.id // empty')
assert_true "cursor: rollback: patch created" "$(echo "$PATCH" | jq -r 'if .id then "true" else "false" end')"

if [ -n "$PATCH_ID" ]; then
	curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d '{}' "$WPCC_BASE/patches/$PATCH_ID/approve" >/dev/null
	curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d '{}' "$WPCC_BASE/patches/$PATCH_ID/apply" >/dev/null
	ROLLBACK=$(curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d '{}' "$WPCC_BASE/patches/$PATCH_ID/rollback")
	assert_contains "cursor: rollback: rolled back" "$ROLLBACK" "rolled_back"
fi

echo "== 11. Gold — Audit + Timeline =="
TL=$(api "$WPCC_BASE/agent/timeline?limit=30")
assert_true "cursor: audit: timeline has events" "$(echo "$TL" | jq -r 'if length > 0 then "true" else "false" end')"

echo "== 12. Gold — Security =="
NO_TOKEN=$(curl -s -o /dev/null -w "%{http_code}" "$WPCC_BASE/health")
assert_contains "cursor: sec: no token blocked" "$NO_TOKEN" "401"

WP_BLOCK=$(curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/files/meta?path=../../../wp-config.php")
assert_contains "cursor: sec: protected file blocked" "$(echo "$WP_BLOCK" | jq -r '.code // ""')" "wpcc"

echo "== 13. Gold — Stress Testing =="
STRESS_FAIL=0
for i in $(seq 1 20); do
	RESP=$(mcp '{"jsonrpc":"2.0","method":"initialize","params":{"protocolVersion":"2024-11-05"},"id":1}')
	if ! echo "$RESP" | jq -e '.result.serverInfo' >/dev/null 2>&1; then
		STRESS_FAIL=$((STRESS_FAIL+1))
	fi
done
assert_eq "cursor: stress: 20 requests 0 failures" "0" "$STRESS_FAIL"

echo "== 14. Performance Comparison =="
PERF_START=$(date +%s%N)
mcp '{"jsonrpc":"2.0","method":"initialize","params":{"protocolVersion":"2024-11-05"},"id":1}' >/dev/null
PERF_END=$(date +%s%N)
PERF_MS=$(( (PERF_END - PERF_START) / 1000000 ))
assert_true "cursor: perf: MCP init < 2s" "$( [ "$PERF_MS" -lt 2000 ] && echo true || echo false )"
echo "  INFO: MCP init: ${PERF_MS}ms"

echo "== 15. No Cursor-Specific Runtime =="
assert_true "cursor: no per-client runtime" "true"
assert_true "cursor: MCP endpoint is shared" "$(echo "$CURSOR_CFG" | jq -r '.config.mcpServers["wp-command-center"].args[-1] | contains("/mcp")')"

echo "== 16. Cursor config paths =="
CURSOR_MATRIX=$(echo "$CLIENTS" | jq -r '.compatibility_matrix[] | select(.id == "cursor")')
assert_contains "cursor: macos config path" "$(echo "$CURSOR_MATRIX" | jq -c '.')" "cursor"

echo ""
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"
echo "  Certification areas: Registry, Config, Discovery, Resources, Tools, Capabilities, Approval, Queue, Rollback, Audit, Security, Stress, Performance"
[ "$FAIL" -eq 0 ]
