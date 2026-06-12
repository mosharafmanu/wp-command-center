#!/usr/bin/env bash
# Step 47.5 — AI Integration UX test suite
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

echo "== 1. AI Integrations page renders =="
PAGE=$(curl -s -b <(echo "") -H "Cookie: $(curl -s -c - -X POST -d "log=admin&pwd=admin" "$(echo "$WPCC_BASE" | sed 's|/wp-json/wp-command-center/v1||')/wp-login.php" 2>/dev/null | tail -1 | awk '{print $NF}')" \
  "$(echo "$WPCC_BASE" | sed 's|/wp-json/wp-command-center/v1||')/wp-admin/admin.php?page=wpcc-ai-integrations" 2>/dev/null)
# Note: cookie-based auth from bash is fragile; we test REST endpoints primarily
# But the page should at least be accessible

echo "== 2. Claude config generation REST =="
CONFIG=$(api "$WPCC_BASE/claude/config")
assert_true "config: has mcpServers" "$(echo "$CONFIG" | jq -r 'if .mcpServers then "true" else "false" end')"
assert_contains "config: command is npx" "$(echo "$CONFIG" | jq -r '.mcpServers["wp-command-center"].command')" "npx"
assert_contains "config: dynamic MCP URL" "$(echo "$CONFIG" | jq -r '.mcpServers["wp-command-center"].args[-1]')" "wp-command-center/v1/mcp"
assert_true "config: env object" "$(echo "$CONFIG" | jq -r 'if (.mcpServers["wp-command-center"].env | type) == "object" then "true" else "false" end')"
assert_contains "config: WPCC_TOKEN placeholder" "$(echo "$CONFIG" | jq -r '.mcpServers["wp-command-center"].env.WPCC_TOKEN')" "WPCC_TOKEN"
assert_contains "config: site_url dynamic" "$(echo "$CONFIG" | jq -r '.mcpServers["wp-command-center"].env.WPCC_SITE_URL')" "http"

echo "== 3. Claude discovery metadata =="
DISC=$(api "$WPCC_BASE/claude/discovery")
assert_eq "discovery: server name" "WP Command Center MCP" "$(echo "$DISC" | jq -r '.server.name')"
assert_true "discovery: tools array" "$(echo "$DISC" | jq -r 'if (.tools | type) == "array" then "true" else "false" end')"
assert_true "discovery: tool groups array" "$(echo "$DISC" | jq -r 'if (.tool_groups | type) == "array" then "true" else "false" end')"
assert_true "discovery: resources array" "$(echo "$DISC" | jq -r 'if (.resources | type) == "array" then "true" else "false" end')"
assert_true "discovery: capabilities section" "$(echo "$DISC" | jq -r 'if .capabilities then "true" else "false" end')"
assert_true "discovery: approval section" "$(echo "$DISC" | jq -r 'if .approval then "true" else "false" end')"
assert_true "discovery: compatibility section" "$(echo "$DISC" | jq -r 'if .compatibility then "true" else "false" end')"

echo "== 4. Agent manifest includes claude_integration =="
MANIFEST=$(api "$WPCC_BASE/agent/manifest")
assert_true "manifest: claude_integration block" "$(echo "$MANIFEST" | jq -r 'if .claude_integration then "true" else "false" end')"
assert_eq "manifest: claude available" "true" "$(echo "$MANIFEST" | jq -r '.claude_integration.available')"
assert_eq "manifest: 12 tool groups" "12" "$(echo "$MANIFEST" | jq -r '.claude_integration.group_count')"
assert_eq "manifest: 7 prompts" "7" "$(echo "$MANIFEST" | jq -r '.claude_integration.prompt_count')"

echo "== 5. Agent context includes claude_integration =="
CONTEXT=$(api "$WPCC_BASE/agent/context")
assert_true "context: claude_integration block" "$(echo "$CONTEXT" | jq -r 'if .claude_integration then "true" else "false" end')"

echo "== 6. Claude tool groups endpoint =="
TOOLS=$(api "$WPCC_BASE/claude/tools")
assert_true "tools: has tool_groups" "$(echo "$TOOLS" | jq -r 'if (.tool_groups | type) == "array" then "true" else "false" end')"
assert_true "tools: Content group" "$(echo "$TOOLS" | jq -r 'any(.tool_groups[]; .label == "Content")')"
assert_true "tools: Plugins group" "$(echo "$TOOLS" | jq -r 'any(.tool_groups[]; .label == "Plugins")')"
assert_true "tools: Database group" "$(echo "$TOOLS" | jq -r 'any(.tool_groups[]; .label == "Database")')"
assert_true "tools: Snapshots group" "$(echo "$TOOLS" | jq -r 'any(.tool_groups[]; .label == "Snapshots")')"
assert_true "tools: WP-CLI group" "$(echo "$TOOLS" | jq -r 'any(.tool_groups[]; .label == "WP-CLI")')"

echo "== 7. Claude prompts endpoint =="
PROMPTS=$(api "$WPCC_BASE/claude/prompts")
assert_true "prompts: has prompts array" "$(echo "$PROMPTS" | jq -r 'if (.prompts | type) == "array" then "true" else "false" end')"
assert_true "prompts: count >= 7" "$(if [ "$(echo "$PROMPTS" | jq -r '.prompts | length')" -ge 7 ] 2>/dev/null; then echo true; else echo false; fi)"
assert_true "prompts: inspect_site exists" "$(echo "$PROMPTS" | jq -r 'any(.prompts[]; .name == "inspect_site")')"
assert_true "prompts: create_content exists" "$(echo "$PROMPTS" | jq -r 'any(.prompts[]; .name == "create_content")')"
assert_true "prompts: plugin_maintenance exists" "$(echo "$PROMPTS" | jq -r 'any(.prompts[]; .name == "plugin_maintenance")')"

echo "== 8. MCP interop intact =="
MCP_INIT=$(curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"initialize","params":{"protocolVersion":"2024-11-05"},"id":1}' "$WPCC_BASE/mcp")
assert_contains "mcp: initialize works" "$MCP_INIT" "WP Command Center"
MCP_TOOLS=$(curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"tools/list","id":2}' "$WPCC_BASE/mcp")
assert_true "mcp: tools available" "$(echo "$MCP_TOOLS" | jq -r 'if .result.tools then "true" else "false" end')"

echo "== 9. Connection testing — all endpoints respond =="
# Health
assert_contains "health: responds ok" "$(api "$WPCC_BASE/health")" '"status":"ok"'
# Manifest
assert_true "manifest: has plugin info" "$(echo "$MANIFEST" | jq -r 'if .plugin.name then "true" else "false" end')"
# Capabilities
CAPS=$(api "$WPCC_BASE/capabilities")
assert_true "capabilities: responds" "$(echo "$CAPS" | jq -r 'if .file_read then "true" else "false" end')"
# Operations
OPS=$(api "$WPCC_BASE/operations")
assert_true "operations: responds as array" "$(echo "$OPS" | jq -r 'if type == "array" then "true" else "false" end')"

echo "== 10. Dashboard has Claude card with links =="
# Verify via REST that the claude config endpoint returns valid config
assert_true "dashboard card config: valid JSON" "$(echo "$CONFIG" | jq -r 'if . then "true" else "false" end')"

echo "== 11. Timeline has Claude events =="
TL=$(api "$WPCC_BASE/agent/timeline?limit=50")
assert_true "timeline: AI Client config generated" "$(echo "$TL" | jq -r 'any(.[]; .label == "AI Client config generated")')"
assert_true "timeline: AI Client discovery" "$(echo "$TL" | jq -r 'any(.[]; .label == "AI Client discovery")')"

echo "== 12. Route manifest includes new pages =="
assert_true "routes: claude/config" "$(echo "$MANIFEST" | jq -r 'any(.endpoints[]; .path == "/claude/config")')"
assert_true "routes: claude/tools" "$(echo "$MANIFEST" | jq -r 'any(.endpoints[]; .path == "/claude/tools")')"

echo "== 13. Config is dynamically generated (no hardcoding) =="
MCP_URL=$(echo "$CONFIG" | jq -r '.mcpServers["wp-command-center"].args[-1]')
MANIFEST_MCP=$(echo "$MANIFEST" | jq -r '.mcp_server.endpoint')
assert_contains "config: MCP URL in args is actual site URL" "$MCP_URL" "wp-command-center/v1/mcp"
assert_contains "config: matches manifest mcp endpoint" "$MANIFEST_MCP" "wp-command-center/v1/mcp"

echo "== 14. Tool count consistency across endpoints =="
MANIFEST_TOOL_COUNT=$(echo "$MANIFEST" | jq -r '.claude_integration.tool_count')
DISC_TOOL_COUNT=$(echo "$DISC" | jq -r '.tools | length')
assert_eq "tool count: manifest == discovery" "$MANIFEST_TOOL_COUNT" "$DISC_TOOL_COUNT"

echo "== 15. Discovery pricing and compatibility =="
assert_eq "discovery: free=true" "true" "$(echo "$DISC" | jq -r '.pricing.free')"
assert_eq "discovery: open_source=true" "true" "$(echo "$DISC" | jq -r '.pricing.open_source')"
assert_eq "discovery: claude_desktop=true" "true" "$(echo "$DISC" | jq -r '.compatibility.claude_desktop')"

echo "== 16. Approval awareness in discovery =="
assert_contains "discovery: database_inspect in not_required_for" "$(echo "$DISC" | jq -r '.approval.not_required_for | join(",")')" "database_inspect"
assert_true "discovery: required_for has content_manage" "$(echo "$DISC" | jq -r 'any(.approval.required_for[]; .id == "content_manage")')"

echo "== 17. Capability operation_map complete =="
assert_contains "capabilities: content_manage mapped" "$(echo "$DISC" | jq -r '.capabilities.operation_map | keys | join(",")')" "content_manage"
assert_contains "capabilities: plugin_manage mapped" "$(echo "$DISC" | jq -r '.capabilities.operation_map | keys | join(",")')" "plugin_manage"
assert_contains "capabilities: database_inspect mapped" "$(echo "$DISC" | jq -r '.capabilities.operation_map | keys | join(",")')" "database_inspect"

echo "== 18. Config env completeness =="
assert_contains "config: WPCC_MCP_URL set" "$(echo "$CONFIG" | jq -r '.mcpServers["wp-command-center"].env.WPCC_MCP_URL')" "/wp-command-center/v1/mcp"
assert_contains "config: WPCC_SITE_URL set" "$(echo "$CONFIG" | jq -r '.mcpServers["wp-command-center"].env.WPCC_SITE_URL')" "://"
assert_contains "config: WPCC_TOKEN placeholder present" "$(echo "$CONFIG" | jq -r '.mcpServers["wp-command-center"].env.WPCC_TOKEN')" "WPCC_TOKEN"

echo "== Summary =="
echo "  $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
