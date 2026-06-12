#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────────────────────────
# WP Command Center — MCP Discovery Example
# ──────────────────────────────────────────────────────────────────────────────
# Demonstrates the MCP JSON-RPC protocol: initialize, resources/list,
# tools/list, and resources/read (manifest).
#
# Prerequisites:
#   - curl installed
#   - wpcc-env.sh sourced (exports $WPCC_BASE and $WPCC_TOKEN)
#   - Any valid token (read_only or full)
# ──────────────────────────────────────────────────────────────────────────────
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(dirname "$SCRIPT_DIR")"

# ── 1. Source credentials ──
if [ -f "$PLUGIN_DIR/wpcc-env.sh" ]; then
  # shellcheck source=/dev/null
  source "$PLUGIN_DIR/wpcc-env.sh"
else
  echo "ERROR: wpcc-env.sh not found at $PLUGIN_DIR/wpcc-env.sh"
  exit 1
fi

echo "=== WP Command Center — MCP Discovery ==="
echo ""

# ── Helper: send an MCP JSON-RPC request ──
mcp() {
  local method="$1"
  local params="${2:-{}}"
  local id="${3:-1}"

  curl -s -X POST \
    -H "Authorization: Bearer $WPCC_TOKEN" \
    -H "Content-Type: application/json" \
    -d "{\"jsonrpc\":\"2.0\",\"method\":\"$method\",\"params\":$params,\"id\":$id}" \
    "$WPCC_BASE/mcp"
}

# ── 2. MCP Initialize ──
echo ">>> Step 1: MCP initialize..."
INIT=$(mcp "initialize" '{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"bash-example","version":"1.0"}}')

PROTO_VERSION=$(echo "$INIT" | jq -r '.result.protocolVersion')
SERVER_NAME=$(echo "$INIT" | jq -r '.result.serverInfo.name')
SERVER_VERSION=$(echo "$INIT" | jq -r '.result.serverInfo.version')

echo "   Protocol version: $PROTO_VERSION"
echo "   Server: $SERVER_NAME v$SERVER_VERSION"
echo ""

# ── 3. MCP resources/list ──
echo ">>> Step 2: MCP resources/list..."
RESOURCES=$(mcp "resources/list")

echo "   Available resources:"
echo "$RESOURCES" | jq -r '.result.resources[] | "     - \(.uri) (\(.name)): \(.description)"'
echo ""

# ── 4. MCP tools/list ──
echo ">>> Step 3: MCP tools/list..."
TOOLS=$(mcp "tools/list")

TOOL_COUNT=$(echo "$TOOLS" | jq '.result.tools | length')
echo "   Tools available: $TOOL_COUNT"
echo ""
echo "   Tool list:"
echo "$TOOLS" | jq -r '.result.tools[] | "     - \(.name) [\(.inputSchema.required // [] | join(", "))]"'
echo ""

# ── 5. MCP resources/read (manifest) ──
echo ">>> Step 4: MCP resources/read (manifest)..."
MANIFEST=$(mcp "resources/read" '{"uri":"wpcc://manifest"}')

echo "   Manifest contents (top-level keys):"
echo "$MANIFEST" | jq -r '.result.contents | fromjson | keys[]' | while read -r key; do
  echo "     - $key"
done

echo ""
echo "=== MCP Discovery complete ==="
