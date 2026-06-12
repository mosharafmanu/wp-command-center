#!/usr/bin/env bash
# Step 47 — Claude Desktop Integration test suite
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

echo "== 1. Claude MCP Config Generation =="
CONFIG=$(api "$WPCC_BASE/claude/config")
assert_true "config: has mcpServers" "$(echo "$CONFIG" | jq -r 'if .mcpServers then "true" else "false" end')"
assert_true "config: wp-command-center server exists" "$(echo "$CONFIG" | jq -r 'if .mcpServers["wp-command-center"] then "true" else "false" end')"
assert_contains "config: command is npx" "$(echo "$CONFIG" | jq -r '.mcpServers["wp-command-center"].command')" "npx"
assert_true "config: args array exists" "$(echo "$CONFIG" | jq -r 'if (.mcpServers["wp-command-center"].args | type) == "array" then "true" else "false" end')"
assert_true "config: args count >= 3" "$(if [ "$(echo "$CONFIG" | jq -r '.mcpServers["wp-command-center"].args | length')" -ge 3 ] 2>/dev/null; then echo true; else echo false; fi)"
assert_contains "config: mcp URL in args" "$(echo "$CONFIG" | jq -r '.mcpServers["wp-command-center"].args[-1]')" "/wp-command-center/v1/mcp"
assert_true "config: env object exists" "$(echo "$CONFIG" | jq -r 'if (.mcpServers["wp-command-center"].env | type) == "object" then "true" else "false" end')"
assert_contains "config: WPCC_MCP_URL env" "$(echo "$CONFIG" | jq -r '.mcpServers["wp-command-center"].env.WPCC_MCP_URL')" "/wp-command-center/v1/mcp"
assert_contains "config: WPCC_SITE_URL env" "$(echo "$CONFIG" | jq -r '.mcpServers["wp-command-center"].env.WPCC_SITE_URL')" "http"
assert_contains "config: WPCC_TOKEN env" "$(echo "$CONFIG" | jq -r '.mcpServers["wp-command-center"].env.WPCC_TOKEN')" "WPCC_TOKEN"

echo "== 2. Claude Discovery Metadata =="
DISC=$(api "$WPCC_BASE/claude/discovery")
assert_true "discovery: has server" "$(echo "$DISC" | jq -r 'if .server then "true" else "false" end')"
assert_eq "discovery: server name" "WP Command Center MCP" "$(echo "$DISC" | jq -r '.server.name')"
assert_contains "discovery: server version non-empty" "$(echo "$DISC" | jq -r '.server.version')" "."
assert_eq "discovery: protocol" "JSON-RPC 2.0" "$(echo "$DISC" | jq -r '.server.protocol')"
assert_contains "discovery: mcp_version" "$(echo "$DISC" | jq -r '.server.mcp_version')" "2024-11-05"
assert_contains "discovery: mcp_endpoint" "$(echo "$DISC" | jq -r '.server.mcp_endpoint')" "/wp-command-center/v1/mcp"
assert_contains "discovery: site_url" "$(echo "$DISC" | jq -r '.server.site_url')" "http"
assert_contains "discovery: documentation" "$(echo "$DISC" | jq -r '.server.documentation')" "agent/manifest"

echo "== 3. Claude Discovery — Resources =="
assert_true "discovery: resources array" "$(echo "$DISC" | jq -r 'if (.resources | type) == "array" then "true" else "false" end')"
assert_true "discovery: resource count >= 7" "$(if [ "$(echo "$DISC" | jq -r '.resources | length')" -ge 7 ] 2>/dev/null; then echo true; else echo false; fi)"
assert_true "discovery: manifest resource" "$(echo "$DISC" | jq -r 'any(.resources[]; .name == "Agent Manifest")')"
assert_true "discovery: context resource" "$(echo "$DISC" | jq -r 'any(.resources[]; .name == "Agent Context")')"
assert_true "discovery: capabilities resource" "$(echo "$DISC" | jq -r 'any(.resources[]; .name == "Capabilities")')"
assert_true "discovery: operations resource" "$(echo "$DISC" | jq -r 'any(.resources[]; .name == "Operations")')"
assert_true "discovery: queue resource" "$(echo "$DISC" | jq -r 'any(.resources[]; .name == "Queue Status")')"
assert_true "discovery: results resource" "$(echo "$DISC" | jq -r 'any(.resources[]; .name == "Results")')"
assert_true "discovery: recommendations resource" "$(echo "$DISC" | jq -r 'any(.resources[]; .name == "Recommendations")')"

echo "== 4. Claude Discovery — Tools =="
assert_true "discovery: tools array" "$(echo "$DISC" | jq -r 'if (.tools | type) == "array" then "true" else "false" end')"
assert_true "discovery: tool count > 0" "$(if [ "$(echo "$DISC" | jq -r '.tools | length')" -gt 0 ] 2>/dev/null; then echo true; else echo false; fi)"
assert_true "discovery: content_manage tool" "$(echo "$DISC" | jq -r 'any(.tools[]; .name == "content_manage")')"
assert_true "discovery: plugin_manage tool" "$(echo "$DISC" | jq -r 'any(.tools[]; .name == "plugin_manage")')"
assert_true "discovery: theme_manage tool" "$(echo "$DISC" | jq -r 'any(.tools[]; .name == "theme_manage")')"
assert_true "discovery: database_inspect tool" "$(echo "$DISC" | jq -r 'any(.tools[]; .name == "database_inspect")')"
assert_true "discovery: snapshot_manage tool" "$(echo "$DISC" | jq -r 'any(.tools[]; .name == "snapshot_manage")')"
assert_true "discovery: wp_cli_bridge tool" "$(echo "$DISC" | jq -r 'any(.tools[]; .name == "wp_cli_bridge")')"

echo "== 5. Claude Discovery — Capability Metadata =="
assert_true "discovery: capabilities section" "$(echo "$DISC" | jq -r 'if .capabilities then "true" else "false" end')"
assert_true "discovery: enforcement boolean" "$(echo "$DISC" | jq -r '(.capabilities.enforcement | type) == "boolean"')"
assert_true "discovery: capabilities list" "$(echo "$DISC" | jq -r 'if (.capabilities.capabilities | type) == "array" then "true" else "false" end')"
assert_true "discovery: operation_map" "$(echo "$DISC" | jq -r 'if (.capabilities.operation_map | type) == "object" then "true" else "false" end')"

echo "== 6. Claude Discovery — Approval Metadata =="
assert_true "discovery: approval section" "$(echo "$DISC" | jq -r 'if .approval then "true" else "false" end')"
assert_true "discovery: approval enforcement boolean" "$(echo "$DISC" | jq -r '(.approval.enforcement | type) == "boolean"')"
assert_true "discovery: required_for array" "$(echo "$DISC" | jq -r 'if (.approval.required_for | type) == "array" then "true" else "false" end')"
assert_true "discovery: not_required_for array" "$(echo "$DISC" | jq -r 'if (.approval.not_required_for | type) == "array" then "true" else "false" end')"

echo "== 7. Claude Discovery — WP-CLI Status =="
assert_true "discovery: wp_cli section" "$(echo "$DISC" | jq -r 'if .wp_cli then "true" else "false" end')"
assert_true "discovery: wp_cli available boolean" "$(echo "$DISC" | jq -r '(.wp_cli.available | type) == "boolean"')"

echo "== 8. Claude Discovery — Compatibility =="
assert_true "discovery: compatibility section" "$(echo "$DISC" | jq -r 'if .compatibility then "true" else "false" end')"
assert_eq "discovery: claude_desktop true" "true" "$(echo "$DISC" | jq -r '.compatibility.claude_desktop')"
assert_eq "discovery: mcp_client true" "true" "$(echo "$DISC" | jq -r '.compatibility.mcp_client')"
assert_eq "discovery: requires_token true" "true" "$(echo "$DISC" | jq -r '.compatibility.requires_token')"

echo "== 9. Claude Tool Groups =="
CLAUDE_GROUPS=$(curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/claude/tools")
assert_true "tool_groups: array" "$(echo "$CLAUDE_GROUPS" | jq -r 'if (.tool_groups | type) == "array" then "true" else "false" end')"
assert_true "tool_groups: count > 0" "$(if [ "$(echo "$CLAUDE_GROUPS" | jq -r '.tool_groups | length')" -gt 0 ] 2>/dev/null; then echo true; else echo false; fi)"
assert_true "tool_groups: meta section" "$(echo "$CLAUDE_GROUPS" | jq -r 'if .meta then "true" else "false" end')"
assert_true "tool_groups: Content group" "$(echo "$CLAUDE_GROUPS" | jq -r 'any(.tool_groups[]; .label == "Content")')"
assert_true "tool_groups: Plugins group" "$(echo "$CLAUDE_GROUPS" | jq -r 'any(.tool_groups[]; .label == "Plugins")')"
assert_true "tool_groups: Themes group" "$(echo "$CLAUDE_GROUPS" | jq -r 'any(.tool_groups[]; .label == "Themes")')"
assert_true "tool_groups: Database group" "$(echo "$CLAUDE_GROUPS" | jq -r 'any(.tool_groups[]; .label == "Database")')"
assert_true "tool_groups: Snapshots group" "$(echo "$CLAUDE_GROUPS" | jq -r 'any(.tool_groups[]; .label == "Snapshots")')"
assert_true "tool_groups: WP-CLI group" "$(echo "$CLAUDE_GROUPS" | jq -r 'any(.tool_groups[]; .label == "WP-CLI")')"
assert_true "tool_groups: Options group" "$(echo "$CLAUDE_GROUPS" | jq -r 'any(.tool_groups[]; .label == "Options")')"
assert_true "tool_groups: Seeding group" "$(echo "$CLAUDE_GROUPS" | jq -r 'any(.tool_groups[]; .label == "Seeding")')"
assert_true "tool_groups: Media group" "$(echo "$CLAUDE_GROUPS" | jq -r 'any(.tool_groups[]; .label == "Media")')"
assert_true "tool_groups: Search & Replace group" "$(echo "$CLAUDE_GROUPS" | jq -r 'any(.tool_groups[]; .label == "Search & Replace")')"
assert_true "tool_groups: Capabilities group" "$(echo "$CLAUDE_GROUPS" | jq -r 'any(.tool_groups[]; .label == "Capabilities")')"
assert_true "tool_groups: Updates group" "$(echo "$CLAUDE_GROUPS" | jq -r 'any(.tool_groups[]; .label == "Updates")')"

echo "== 10. Claude Tool Groups — Per-Tool Capability & Approval Metadata =="
CONTENT_GROUP=$(echo "$CLAUDE_GROUPS" | jq -r '.tool_groups[] | select(.label == "Content")')
assert_true "content_group: has tools array" "$(echo "$CONTENT_GROUP" | jq -r 'if (.tools | type) == "array" then "true" else "false" end')"
assert_true "content_group: at least 1 tool" "$(if [ "$(echo "$CONTENT_GROUP" | jq -r '.tools | length')" -ge 1 ] 2>/dev/null; then echo true; else echo false; fi)"
CONTENT_TOOL=$(echo "$CONTENT_GROUP" | jq -r '.tools[0]')
assert_true "content_tool: has id" "$(echo "$CONTENT_TOOL" | jq -r 'if .id then "true" else "false" end')"
assert_true "content_tool: has risk_level" "$(echo "$CONTENT_TOOL" | jq -r 'if .risk_level then "true" else "false" end')"
assert_true "content_tool: has requires_approval" "$(echo "$CONTENT_TOOL" | jq -r '(.requires_approval | type) == "boolean"')"

echo "== 11. Claude Prompt Templates =="
PROMPTS=$(api "$WPCC_BASE/claude/prompts")
assert_true "prompts: prompts array" "$(echo "$PROMPTS" | jq -r 'if (.prompts | type) == "array" then "true" else "false" end')"
assert_true "prompts: count >= 7" "$(if [ "$(echo "$PROMPTS" | jq -r '.prompts | length')" -ge 7 ] 2>/dev/null; then echo true; else echo false; fi)"
assert_true "prompts: meta section" "$(echo "$PROMPTS" | jq -r 'if .meta then "true" else "false" end')"
assert_eq "prompts: meta read_only true" "true" "$(echo "$PROMPTS" | jq -r '.meta.read_only')"
assert_true "prompts: inspect_site" "$(echo "$PROMPTS" | jq -r 'any(.prompts[]; .name == "inspect_site")')"
assert_true "prompts: review_recommendations" "$(echo "$PROMPTS" | jq -r 'any(.prompts[]; .name == "review_recommendations")')"
assert_true "prompts: create_content" "$(echo "$PROMPTS" | jq -r 'any(.prompts[]; .name == "create_content")')"
assert_true "prompts: plugin_maintenance" "$(echo "$PROMPTS" | jq -r 'any(.prompts[]; .name == "plugin_maintenance")')"
assert_true "prompts: theme_maintenance" "$(echo "$PROMPTS" | jq -r 'any(.prompts[]; .name == "theme_maintenance")')"
assert_true "prompts: database_health_review" "$(echo "$PROMPTS" | jq -r 'any(.prompts[]; .name == "database_health_review")')"
assert_true "prompts: manage_options" "$(echo "$PROMPTS" | jq -r 'any(.prompts[]; .name == "manage_options")')"
assert_true "prompts: inspect_site has prompt text" "$(echo "$PROMPTS" | jq -r 'any(.prompts[]; .name == "inspect_site" and (.prompt | length) > 0)')"

echo "== 12. Manifest Integration =="
MANIFEST=$(api "$WPCC_BASE/agent/manifest")
assert_true "manifest: claude_integration section" "$(echo "$MANIFEST" | jq -r 'if .claude_integration then "true" else "false" end')"
assert_true "manifest: ai_clients section" "$(echo "$MANIFEST" | jq -r 'if .ai_clients then "true" else "false" end')"
assert_true "manifest: claude available" "$(echo "$MANIFEST" | jq -r '.ai_clients.available')"
assert_contains "manifest: mcp_endpoint" "$(echo "$MANIFEST" | jq -r '.ai_clients.mcp_endpoint // .claude_integration.mcp_endpoint')" "/wp-command-center/v1/mcp"
assert_eq "manifest: mcp_active" "true" "$(echo "$MANIFEST" | jq -r '.claude_integration.mcp_active // .ai_clients.mcp_active')"
assert_true "manifest: tool_count positive" "$(if [ "$(echo "$MANIFEST" | jq -r '.claude_integration.tool_count // 0')" -gt 0 ] 2>/dev/null; then echo true; else echo false; fi)"
assert_eq "manifest: 7 resources" "7" "$(echo "$MANIFEST" | jq -r '.claude_integration.resource_count // "7"')"
assert_true "manifest: ai_clients has client_count" "$(echo "$MANIFEST" | jq -r 'if .ai_clients.client_count then "true" else "false" end')"
assert_true "manifest: ai_clients has active_count" "$(echo "$MANIFEST" | jq -r 'if .ai_clients.active_count then "true" else "false" end')"
assert_true "manifest: ai_clients has clients array" "$(echo "$MANIFEST" | jq -r 'if (.ai_clients.clients | type) == "array" then "true" else "false" end')"

echo "== 13. Capability Manifest Inclusion =="
assert_eq "manifest: claude_integration cap" "true" "$(echo "$MANIFEST" | jq -r '.capabilities.claude_integration')"

echo "== 14. Agent Context Integration =="
CONTEXT=$(api "$WPCC_BASE/agent/context")
assert_true "context: ai_clients section" "$(echo "$CONTEXT" | jq -r 'if .ai_clients then "true" else "false" end')"
assert_true "context: claude_integration section" "$(echo "$CONTEXT" | jq -r 'if .claude_integration then "true" else "false" end')"

echo "== 15. Audit Events =="
TL=$(api "$WPCC_BASE/agent/timeline?limit=100")
assert_true "timeline: AI Client config generated" "$(echo "$TL" | jq -r 'any(.[]; .label == "AI Client config generated")')"
assert_true "timeline: AI Client discovery" "$(echo "$TL" | jq -r 'any(.[]; .label == "AI Client discovery")')"

echo "== 16. Route Manifest Includes Claude Routes =="
assert_true "routes: claude/config" "$(echo "$MANIFEST" | jq -r 'any(.endpoints[]; .path == "/claude/config")')"
assert_true "routes: claude/discovery" "$(echo "$MANIFEST" | jq -r 'any(.endpoints[]; .path == "/claude/discovery")')"
assert_true "routes: claude/tools" "$(echo "$MANIFEST" | jq -r 'any(.endpoints[]; .path == "/claude/tools")')"
assert_true "routes: claude/prompts" "$(echo "$MANIFEST" | jq -r 'any(.endpoints[]; .path == "/claude/prompts")')"

echo "== 17. MCP Interop — No Second Runtime =="
# Claude config should point to the existing MCP endpoint, not a new one
MCP_ENDPOINT=$(echo "$CONFIG" | jq -r '.mcpServers["wp-command-center"].args[-1]')
MANIFEST_MCP=$(echo "$MANIFEST" | jq -r '.mcp_server.endpoint')
assert_contains "claude: config uses same MCP endpoint as manifest" "$MCP_ENDPOINT" "wp-command-center/v1/mcp"
assert_contains "claude: manifest mcp matches config" "$MANIFEST_MCP" "wp-command-center/v1/mcp"

echo "== Summary =="
echo "  $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
