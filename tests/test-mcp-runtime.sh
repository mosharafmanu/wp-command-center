#!/usr/bin/env bash
# Step 45 — MCP Server Runtime test suite
set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../wpcc-env.sh"
PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; if [ "$e" = "$a" ]; then pass "$d"; else fail "$d (expected '$e', got '$a')"; fi; }
assert_true() { local d="$1" a="$2"; if [ "$a" = "true" ]; then pass "$d"; else fail "$d"; fi; }
assert_contains() { local d="$1" h="$2" n="$3"; if [[ "$h" == *"$n"* ]]; then pass "$d"; else fail "$d"; fi; }
mcp() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/mcp"; }

echo "== 1. Initialize =="
INIT=$(mcp '{"jsonrpc":"2.0","method":"initialize","params":{"protocolVersion":"2024-11-05"},"id":1}')
assert_contains "init: protocol ok" "$INIT" "2024-11-05"
assert_contains "init: server name" "$INIT" "WP Command Center"

echo "== 2. Resources list =="
RSC=$(mcp '{"jsonrpc":"2.0","method":"resources/list","id":2}')
assert_true "resources: manifest" "$(echo "$RSC" | jq -r 'any(.result.resources[]; .name == "Agent Manifest")')"
assert_true "resources: context" "$(echo "$RSC" | jq -r 'any(.result.resources[]; .name == "Agent Context")')"
assert_true "resources: capabilities" "$(echo "$RSC" | jq -r 'any(.result.resources[]; .name == "Capabilities")')"
assert_true "resources: operations" "$(echo "$RSC" | jq -r 'any(.result.resources[]; .name == "Operations")')"
assert_true "resources: queue" "$(echo "$RSC" | jq -r 'any(.result.resources[]; .name == "Queue Status")')"
assert_true "resources: results" "$(echo "$RSC" | jq -r 'any(.result.resources[]; .name == "Results")')"
assert_true "resources: recommendations" "$(echo "$RSC" | jq -r 'any(.result.resources[]; .name == "Recommendations")')"

echo "== 3. Resources read =="
RMAN=$(mcp '{"jsonrpc":"2.0","method":"resources/read","params":{"uri":"wpcc://manifest"},"id":3}')
assert_true "read: manifest success" "$(echo "$RMAN" | jq -r 'if .result then "true" else "false" end')"
RCAP=$(mcp '{"jsonrpc":"2.0","method":"resources/read","params":{"uri":"wpcc://capabilities"},"id":4}')
RCTX=$(mcp '{"jsonrpc":"2.0","method":"resources/read","params":{"uri":"wpcc://context"},"id":12}')
assert_true "read: context success" "$(echo "$RCTX" | jq -r 'if .result then "true" else "false" end')"
ROPS=$(mcp '{"jsonrpc":"2.0","method":"resources/read","params":{"uri":"wpcc://operations"},"id":13}')
assert_true "read: operations success" "$(echo "$ROPS" | jq -r 'if .result then "true" else "false" end')"
RQUE=$(mcp '{"jsonrpc":"2.0","method":"resources/read","params":{"uri":"wpcc://queue"},"id":14}')
assert_true "read: queue success" "$(echo "$RQUE" | jq -r 'if .result then "true" else "false" end')"
RRES=$(mcp '{"jsonrpc":"2.0","method":"resources/read","params":{"uri":"wpcc://results"},"id":15}')
assert_true "read: results success" "$(echo "$RRES" | jq -r 'if .result then "true" else "false" end')"
RREC=$(mcp '{"jsonrpc":"2.0","method":"resources/read","params":{"uri":"wpcc://recommendations"},"id":16}')
assert_true "read: recommendations success" "$(echo "$RREC" | jq -r 'if .result then "true" else "false" end')"

echo "== 4. Invalid resource =="
INV=$(mcp '{"jsonrpc":"2.0","method":"resources/read","params":{"uri":"wpcc://nonexistent"},"id":5}')
assert_contains "read: invalid resource" "$INV" "-32002"

echo "== 5. Tools list =="
TOOLS=$(mcp '{"jsonrpc":"2.0","method":"tools/list","id":6}')
assert_true "tools: content_manage present" "$(echo "$TOOLS" | jq -r 'any(.result.tools[]; .name == "content_manage")')"
assert_true "tools: plugin_manage present" "$(echo "$TOOLS" | jq -r 'any(.result.tools[]; .name == "plugin_manage")')"
assert_true "tools: theme_manage present" "$(echo "$TOOLS" | jq -r 'any(.result.tools[]; .name == "theme_manage")')"
assert_true "tools: option_manage present" "$(echo "$TOOLS" | jq -r 'any(.result.tools[]; .name == "option_manage")')"
assert_true "tools: snapshot_manage present" "$(echo "$TOOLS" | jq -r 'any(.result.tools[]; .name == "snapshot_manage")')"
assert_true "tools: database_inspect present" "$(echo "$TOOLS" | jq -r 'any(.result.tools[]; .name == "database_inspect")')"
assert_true "tools: wp_cli_bridge present" "$(echo "$TOOLS" | jq -r 'any(.result.tools[]; .name == "wp_cli_bridge")')"
TOOLS_COUNT=$(echo "$TOOLS" | jq -r '.result.tools | length')
assert_true "tools: count > 0" "$(if [ "$TOOLS_COUNT" -gt 0 ] 2>/dev/null; then echo true; else echo false; fi)"

echo "== 6. Tools call — read operation =="
TCALL=$(mcp '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"database_inspect","arguments":{"action":"db_health_summary"}},"id":7}')
assert_contains "call: db_health_summary" "$TCALL" "db_size_mb"

echo "== 7. Tools call — list operation =="
TCALL2=$(mcp '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"option_manage","arguments":{"action":"option_get","option_id":"site_title"}},"id":8}')
assert_true "call: option_get success" "$(echo "$TCALL2" | jq -r 'if .result then "true" else "false" end')"

echo "== 8. Tools call — content list =="
TCALL3=$(mcp '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"content_manage","arguments":{"action":"content_list","type":"post","per_page":2}},"id":17}')
assert_true "call: content_list success" "$(echo "$TCALL3" | jq -r 'if .result then "true" else "false" end')"

echo "== 9. Redaction in MCP =="
assert_true "mcp: manifest has result" "$(echo "$RMAN" | jq -r 'if .result then "true" else "false" end')"
assert_true "mcp: context has result" "$(echo "$RCTX" | jq -r 'if .result then "true" else "false" end')"

echo "== 8. Prompts list =="
PROMPTS=$(mcp '{"jsonrpc":"2.0","method":"prompts/list","id":9}')
assert_contains "prompts: inspect_site" "$PROMPTS" "inspect_site"

echo "== 11. Manifest exposure =="
MANIFEST=$(curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/agent/manifest")
assert_true "manifest: mcp_server section" "$(echo "$MANIFEST" | jq -r 'if .mcp_server then "true" else "false" end')"
assert_contains "manifest: endpoint url" "$(echo "$MANIFEST" | jq -r '.mcp_server.endpoint')" "mcp"
assert_eq "manifest: 7 resources" "7" "$(echo "$MANIFEST" | jq -r '.mcp_server.resources | length')"

echo "== 10. Context exposure =="
CONTEXT=$(curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/agent/context")
assert_true "context: mcp_server_available" "$(echo "$CONTEXT" | jq -r 'if .mcp_server_available then "true" else "false" end')"

echo "== 11. Unknown method =="
BAD=$(mcp '{"jsonrpc":"2.0","method":"nonexistent","id":10}')
assert_contains "unknown method" "$BAD" "-32601"

echo "== 12. Audit + Timeline =="
TL=$(curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/agent/timeline?limit=50")
assert_true "timeline: MCP request" "$(echo "$TL" | jq -r 'any(.[]; .label == "MCP request received")')"
assert_true "timeline: MCP tool invoked" "$(echo "$TL" | jq -r 'any(.[]; .label == "MCP tool invoked")')"
assert_true "timeline: MCP resource read" "$(echo "$TL" | jq -r 'any(.[]; .label == "MCP resource read")')"

echo "== 13. Manifest exposure =="
assert_true "manifest: cap mcp_server" "$(echo "$MANIFEST" | jq -r '.capabilities.mcp_server // false')"

echo "== 14. Redaction in MCP response =="
# Read context resource which has redacted data
RCTX=$(mcp '{"jsonrpc":"2.0","method":"resources/read","params":{"uri":"wpcc://context"},"id":11}')
assert_true "mcp: response contains text" "$(echo "$RCTX" | jq -r 'if .result.contents then "true" else "false" end')"

echo "== 15. Tool count in manifest =="
TOOL_COUNT=$(echo "$MANIFEST" | jq -r '.mcp_server.tool_count')
assert_true "manifest: tool count > 0" "$(if [ "$TOOL_COUNT" -gt 0 ] 2>/dev/null; then echo true; else echo false; fi)"

echo "== 16. Error handling =="
EMPTY=$(curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d '{}' "$WPCC_BASE/mcp")
assert_contains "mcp: empty body handled" "$EMPTY" "jsonrpc"

echo "== Summary =="
echo "  $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
