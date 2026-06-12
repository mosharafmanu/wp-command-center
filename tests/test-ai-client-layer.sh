#!/usr/bin/env bash
# Step 48 — AI Client Integration Layer test suite
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

echo "== 1. AI Clients list endpoint =="
CLIENTS=$(api "$WPCC_BASE/ai-clients")
assert_true "ai-clients: has clients" "$(echo "$CLIENTS" | jq -r 'if .clients then "true" else "false" end')"
assert_true "ai-clients: clients is object" "$(echo "$CLIENTS" | jq -r 'if (.clients | type) == "object" then "true" else "false" end')"
assert_true "ai-clients: has compatibility_matrix" "$(echo "$CLIENTS" | jq -r 'if (.compatibility_matrix | type) == "array" then "true" else "false" end')"
assert_true "ai-clients: has counts" "$(echo "$CLIENTS" | jq -r 'if .counts then "true" else "false" end')"
assert_true "ai-clients: note present" "$(echo "$CLIENTS" | jq -r 'if .note then "true" else "false" end')"

echo "== 2. Client count accuracy =="
assert_eq "counts: total 11" "11" "$(echo "$CLIENTS" | jq -r '.counts.total')"
assert_eq "counts: active 2" "2" "$(echo "$CLIENTS" | jq -r '.counts.active')"
assert_eq "counts: configured 11" "11" "$(echo "$CLIENTS" | jq -r '.counts.configured')"
assert_eq "counts: connected 11" "11" "$(echo "$CLIENTS" | jq -r '.counts.connected')"
assert_eq "counts: planned 0" "0" "$(echo "$CLIENTS" | jq -r '.counts.planned')"

echo "== 3. All registered clients present =="
for client_id in claude chatgpt codex gemini cursor continue opencode aider roo_code windsurf command_code; do
	assert_true "client: $client_id exists" "$(echo "$CLIENTS" | jq -r --arg id "$client_id" 'if .clients[$id] then "true" else "false" end')"
done

echo "== 4. Active client metadata =="
assert_eq "claude: name" "Claude Desktop" "$(echo "$CLIENTS" | jq -r '.clients.claude.name')"
assert_eq "claude: vendor" "Anthropic" "$(echo "$CLIENTS" | jq -r '.clients.claude.vendor')"
assert_eq "claude: type desktop" "desktop" "$(echo "$CLIENTS" | jq -r '.clients.claude.type')"
assert_eq "claude: status gold" "gold" "$(echo "$CLIENTS" | jq -r '.clients.claude.status')"
assert_eq "claude: compatible true" "true" "$(echo "$CLIENTS" | jq -r '.clients.claude.compatible')"
assert_eq "claude: mcp_support true" "true" "$(echo "$CLIENTS" | jq -r '.clients.claude.mcp_support')"

echo "== 5. Other client certification metadata =="
assert_eq "codex: status compatible" "compatible" "$(echo "$CLIENTS" | jq -r '.clients.codex.status')"
assert_eq "gemini: status compatible" "compatible" "$(echo "$CLIENTS" | jq -r '.clients.gemini.status')"
assert_eq "cursor: status gold" "gold" "$(echo "$CLIENTS" | jq -r '.clients.cursor.status')"
assert_eq "windsurf: vendor Codeium" "Codeium" "$(echo "$CLIENTS" | jq -r '.clients.windsurf.vendor')"

echo "== 6. Compatibility matrix =="
assert_eq "matrix: 11 entries" "11" "$(echo "$CLIENTS" | jq -r '.compatibility_matrix | length')"
assert_true "matrix: claude is compatible" "$(echo "$CLIENTS" | jq -r '.compatibility_matrix[] | select(.id == "claude") | .compatible')"
assert_true "matrix: claude is configured" "$(echo "$CLIENTS" | jq -r '.compatibility_matrix[] | select(.id == "claude") | .configured')"

echo "== 7. Generic AI client config endpoint =="
CLAUDE_CFG=$(api "$WPCC_BASE/ai-clients/claude/config")
assert_true "ai-client config: has config" "$(echo "$CLAUDE_CFG" | jq -r 'if .config then "true" else "false" end')"
assert_eq "ai-client config: client=claude" "claude" "$(echo "$CLAUDE_CFG" | jq -r '.client')"
assert_eq "ai-client config: name" "Claude Desktop" "$(echo "$CLAUDE_CFG" | jq -r '.name')"
assert_true "ai-client config: mcpServers in config" "$(echo "$CLAUDE_CFG" | jq -r 'if .config.mcpServers then "true" else "false" end')"
assert_contains "ai-client config: MCP URL" "$(echo "$CLAUDE_CFG" | jq -r '.config.mcpServers["wp-command-center"].args[-1]')" "wp-command-center/v1/mcp"

echo "== 8. Unknown client returns 404 =="
UNK=$(curl -s -w "\n%{http_code}" -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/ai-clients/nonexistent/config")
HTTP_CODE=$(echo "$UNK" | tail -1)
assert_contains "unknown: 404 or 400" "$HTTP_CODE" "4"

echo "== 9. Codex client config endpoint =="
CODEX_CFG=$(curl -s -w "\n%{http_code}" -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/ai-clients/codex/config")
CODEX_CODE=$(echo "$CODEX_CFG" | tail -1)
CODEX_BODY=$(echo "$CODEX_CFG" | sed '$d')
assert_eq "codex: config returns 200" "200" "$CODEX_CODE"
assert_eq "codex: config client=codex" "codex" "$(echo "$CODEX_BODY" | jq -r '.client')"
assert_true "codex: config has mcpServers" "$(echo "$CODEX_BODY" | jq -r 'if .config.mcpServers then "true" else "false" end')"

echo "== 10. Manifest has ai_clients section =="
MANIFEST=$(api "$WPCC_BASE/agent/manifest")
assert_true "manifest: ai_clients block" "$(echo "$MANIFEST" | jq -r 'if .ai_clients then "true" else "false" end')"
assert_true "manifest: ai_clients available" "$(echo "$MANIFEST" | jq -r '.ai_clients.available')"
assert_true "manifest: has client_count" "$(echo "$MANIFEST" | jq -r 'if .ai_clients.client_count then "true" else "false" end')"
assert_true "manifest: has active_count" "$(echo "$MANIFEST" | jq -r 'if .ai_clients.active_count then "true" else "false" end')"
assert_true "manifest: clients array" "$(echo "$MANIFEST" | jq -r 'if (.ai_clients.clients | type) == "array" then "true" else "false" end')"
assert_eq "manifest: 11 clients in array" "11" "$(echo "$MANIFEST" | jq -r '.ai_clients.clients | length')"

echo "== 11. Manifest backward compat — claude_integration still present =="
assert_true "manifest: claude_integration still exists" "$(echo "$MANIFEST" | jq -r 'if .claude_integration then "true" else "false" end')"
assert_eq "manifest: ai_clients cap" "true" "$(echo "$MANIFEST" | jq -r '.capabilities.ai_clients')"

echo "== 12. Agent context has ai_clients =="
CONTEXT=$(api "$WPCC_BASE/agent/context")
assert_true "context: ai_clients block" "$(echo "$CONTEXT" | jq -r 'if .ai_clients then "true" else "false" end')"
assert_true "context: active_clients count" "$(echo "$CONTEXT" | jq -r 'if .ai_clients.active_clients then "true" else "false" end')"
assert_true "context: total_clients count" "$(echo "$CONTEXT" | jq -r 'if .ai_clients.total_clients then "true" else "false" end')"

echo "== 13. Legacy /claude/config still works =="
OLD_CFG=$(api "$WPCC_BASE/claude/config")
assert_true "legacy config: mcpServers exists" "$(echo "$OLD_CFG" | jq -r 'if .mcpServers then "true" else "false" end')"

echo "== 14. Legacy /claude/discovery still works =="
OLD_DISC=$(api "$WPCC_BASE/claude/discovery")
assert_true "legacy discovery: server exists" "$(echo "$OLD_DISC" | jq -r 'if .server then "true" else "false" end')"

echo "== 15. Legacy /claude/tools still works =="
OLD_TOOLS=$(api "$WPCC_BASE/claude/tools")
assert_true "legacy tools: has tool_groups" "$(echo "$OLD_TOOLS" | jq -r 'if (.tool_groups | type) == "array" then "true" else "false" end')"

echo "== 16. Legacy /claude/prompts still works =="
OLD_PROMPTS=$(api "$WPCC_BASE/claude/prompts")
assert_true "legacy prompts: has prompts" "$(echo "$OLD_PROMPTS" | jq -r 'if (.prompts | type) == "array" then "true" else "false" end')"

echo "== 17. Route manifest has new endpoints =="
assert_true "routes: /ai-clients exists" "$(echo "$MANIFEST" | jq -r 'any(.endpoints[]; .path == "/ai-clients")')"
assert_true "routes: /ai-clients/{client}/config exists" "$(echo "$MANIFEST" | jq -r 'any(.endpoints[]; .path == "/ai-clients/{client}/config")')"
assert_true "routes: legacy /claude/config exists" "$(echo "$MANIFEST" | jq -r 'any(.endpoints[]; .path == "/claude/config")')"

echo "== 18. MCP interop preserved =="
MCP_INIT=$(curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"initialize","params":{"protocolVersion":"2024-11-05"},"id":1}' "$WPCC_BASE/mcp")
assert_contains "mcp: still works" "$MCP_INIT" "WP Command Center"
assert_contains "mcp: protocol version" "$MCP_INIT" "2024-11-05"

echo "== 19. Timeline has AI Client events =="
TL=$(api "$WPCC_BASE/agent/timeline?limit=50")
assert_true "timeline: has AI Client config generated" "$(echo "$TL" | jq -r 'any(.[]; .label == "AI Client config generated")')"

echo "== 20. Dashboard REST equivalent validates =="
# Verify the config from the generic endpoint matches the legacy endpoint
GEN_CFG_JSON=$(echo "$CLAUDE_CFG" | jq -c '.config')
LEGACY_CFG_JSON=$(echo "$OLD_CFG" | jq -c '.')
assert_eq "config: generic matches legacy" "$GEN_CFG_JSON" "$LEGACY_CFG_JSON"

echo "== 21. Additional client detail checks =="
assert_eq "codex: name" "Codex" "$(echo "$CLIENTS" | jq -r '.clients.codex.name')"
assert_eq "gemini: vendor" "Google" "$(echo "$CLIENTS" | jq -r '.clients.gemini.vendor')"
assert_eq "cursor: type ide" "ide" "$(echo "$CLIENTS" | jq -r '.clients.cursor.type')"
assert_eq "continue: type ide_plugin" "ide_plugin" "$(echo "$CLIENTS" | jq -r '.clients.continue.type')"
assert_eq "opencode: vendor Anomaly" "Anomaly" "$(echo "$CLIENTS" | jq -r '.clients.opencode.vendor')"
assert_eq "aider: type cli" "cli" "$(echo "$CLIENTS" | jq -r '.clients.aider.type')"
assert_eq "roo_code: name" "Roo Code" "$(echo "$CLIENTS" | jq -r '.clients.roo_code.name')"
assert_true "codex/gemini/cursor/continue compatible" "$(echo "$CLIENTS" | jq -r '[.clients.codex.compatible, .clients.gemini.compatible, .clients.cursor.compatible, .clients.continue.compatible] | all')"
assert_true "codex/gemini/cursor/continue mcp_support" "$(echo "$CLIENTS" | jq -r '[.clients.codex.mcp_support, .clients.gemini.mcp_support, .clients.cursor.mcp_support, .clients.continue.mcp_support] | all')"

echo "== 22. Config env completeness =="
assert_contains "config: WPCC_TOKEN placeholder" "$(echo "$CLAUDE_CFG" | jq -r '.config.mcpServers["wp-command-center"].env.WPCC_TOKEN')" "WPCC_TOKEN"
assert_contains "config: WPCC_SITE_URL present" "$(echo "$CLAUDE_CFG" | jq -r '.config.mcpServers["wp-command-center"].env.WPCC_SITE_URL')" "://"
assert_contains "config: WPCC_MCP_URL present" "$(echo "$CLAUDE_CFG" | jq -r '.config.mcpServers["wp-command-center"].env.WPCC_MCP_URL')" "/mcp"

echo "== 23. Claude discovery still works through AI client layer =="
assert_eq "claude: config client name Claude Desktop" "Claude Desktop" "$(echo "$CLAUDE_CFG" | jq -r '.name')"

echo "== 24. MCP architecture preserved ==="
# Verify /ai-clients note mentions MCP
assert_contains "ai-clients: note mentions MCP" "$(echo "$CLIENTS" | jq -r '.note')" "MCP"
assert_contains "ai-clients: note mentions no per-client runtimes" "$(echo "$CLIENTS" | jq -r '.note')" "No per-client"

echo "== Summary =="
echo "  $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
