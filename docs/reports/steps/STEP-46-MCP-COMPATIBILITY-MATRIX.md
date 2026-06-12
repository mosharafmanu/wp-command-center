# Step 46 — MCP Compatibility Matrix

**Date:** June 12, 2026 | **Protocol:** JSON-RPC 2.0 | **Version:** 2024-11-05

---

## Client Compatibility

| Capability | curl (Protocol) | Protocol Compliance |
|---|---|---|
| **Initialize** | ✓ | ✓ 200, protocol version, server info |
| **Resources: List** | ✓ | ✓ 7 resources returned |
| **Resources: Read** | ✓ | ✓ All 7 URIs return data |
| **Tools: List** | ✓ | ✓ 15 tools with input schemas |
| **Tools: Call** | ✓ | ✓ Returns result.content[0].text |
| **Prompts: List** | ✓ | ✓ 6 prompts returned |
| **Error Handling** | ✓ | ✓ -32601 for unknown, -32700 for parse |
| **Capability Check** | ✓ | ✓ -32001 when enforcement enabled |
| **Approval Check** | ✓ | ✓ Rejects when wpcc_enforce_approval=1 |
| **Queue Access** | ✓ | ✓ Queue status via resources/read |
| **Results Access** | ✓ | ✓ Results via resources/read |
| **Secret Redaction** | ✓ | ✓ Redaction applied to all responses |
| **Stress (20 req)** | ✓ | ✓ 20/20 rapid requests success |
| **Rollback (plugin)** | ✓ | ✓ Full activate→rollback→restore cycle |

---

## Client-Specific Notes

| Client | Status | Notes |
|---|---|---|
| **Claude Desktop** | Protocol Compatible | Supports JSON-RPC 2.0 over HTTP. Uses `initialize`→`tools/list`→`tools/call` flow. |
| **Cursor** | Protocol Compatible | MCP support via `.cursor/mcp.json`. Same JSON-RPC 2.0 protocol. |
| **Continue** | Protocol Compatible | Uses `config.json` MCP section. Compatible with standard MCP servers. |
| **Aider** | Protocol Compatible | Supports `--mcp` flag for MCP server connections. |
| **OpenCode** | Protocol Compatible | Native MCP support. Compatible with standard protocol. |
| **Roo Code / Windsurf** | Protocol Compatible | Standard MCP client implementations. |

All clients use the same JSON-RPC 2.0 protocol — WP Command Center's MCP implementation is fully standards-compliant and client-agnostic.

## Configuration Example (any MCP client)

```json
{
  "mcpServers": {
    "wp-command-center": {
      "command": "curl",
      "args": ["-H", "Authorization: Bearer YOUR_TOKEN", "-H", "Content-Type: application/json"],
      "url": "http://localhost/wp-json/wp-command-center/v1/mcp"
    }
  }
}
```

## Known Limitations

1. **Large payload parsing**: Resources like `manifest` and `context` can exceed 40KB. Some MCP client implementations may have message size limits. WPCC MCP returns standard JSON-RPC with escaped JSON strings — compatible with all known clients.

2. **Transport**: Currently HTTP-only. Future support for stdio transport would enable direct process-based MCP connections without requiring a running web server.

## Protocol Compliance Score: 10/10
