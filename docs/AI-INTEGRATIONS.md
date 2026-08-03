# AI client integrations

Any client that speaks MCP can connect. WPCC generates a ready-to-paste configuration for
each of the clients below.

**Settings → Connections → Assistants** → pick your client → copy the configuration.

---

## Supported clients

| Client | Type | Certification |
|---|---|---|
| Claude Desktop | desktop | **Certified Gold** |
| Cursor | IDE | **Certified Gold** |
| ChatGPT | desktop | Compatible |
| Codex | desktop | Compatible |
| Gemini | desktop | Compatible |
| Continue | IDE plugin | Compatible |
| Roo Code | IDE plugin | Compatible |
| Windsurf | IDE | Compatible |
| OpenCode | CLI | Compatible |
| Aider | CLI | Compatible |
| Command Code | CLI | Compatible |

"Certified Gold" means the full connect-and-operate path was exercised end to end against
a real site. "Compatible" means the client implements MCP and the generated configuration
follows its documented format.

There is **no per-client runtime**. Every client reaches the same MCP endpoint with the
same 42 tools; only the configuration file format differs.

---

## The generated configuration

```json
{
  "mcpServers": {
    "wp-command-center": {
      "command": "bash",
      "args": ["-c", "RELAY='/tmp/wpcc-mcp-relay.mjs'; curl -fsSL -o \"$RELAY\" 'https://example.com/wp-content/plugins/ai-command-center/sdk/javascript/wpcc-mcp-relay.mjs?v=1.0.0'; node \"$RELAY\""],
      "env": {
        "WPCC_MCP_URL": "https://example.com/wp-json/wp-command-center/v1/mcp",
        "WPCC_SITE_URL": "https://example.com",
        "WPCC_TOKEN": "${WPCC_TOKEN}",
        "WPCC_CONTEXT_MODE": "compact"
      }
    }
  }
}
```

- **The relay comes from your site**, not npm. It ships inside the plugin.
- **`WPCC_TOKEN` is a placeholder.** The generated config never contains a live token —
  paste yours in.
- **`WPCC_CONTEXT_MODE`** is `compact` or `full`; it affects only how verbose tool
  descriptions are, never the data returned.
- **Node.js is required** on the machine running the client.

## Where each client keeps its config

| Client | Location |
|---|---|
| Claude Desktop (macOS) | `~/Library/Application Support/Claude/claude_desktop_config.json` |
| Claude Desktop (Windows) | `%APPDATA%\Claude\claude_desktop_config.json` |
| Cursor | `~/.cursor/mcp.json` |
| Others | See that client's own MCP documentation |

Restart the client after editing.

## Verifying

Ask the assistant a read-only question — *"What WordPress version is this site running?"*
It should call `system_info` and answer immediately. If it reports no tools, see
[TROUBLESHOOTING.md](TROUBLESHOOTING.md#the-client-connects-but-shows-no-tools).

## What a well-behaved assistant does

- Treats `pending_approval` as a **normal outcome**, relays the approval link, and does
  **not** retry — retrying files a duplicate request.
- Reads `wpcc://operations` before guessing parameters. It states, per action, the risk,
  whether it will stop for approval in this site's mode, accepted aliases, and how to
  undo the result.
- Uses the valid-action list in an error message rather than guessing again.

## AI provider keys are separate

The key an assistant uses to talk to *you* is not the key WPCC uses for its own AI
features (alt text, SEO drafts). Those are configured separately in
**Settings → Connections → Assistants**, and **stay off until a key is added**. WPCC makes
no outbound AI call without one.
