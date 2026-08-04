# AI client integrations

Any client that speaks MCP can connect. WPCC generates a ready-to-paste configuration for
each of the clients below, in that client's own format.

**Settings → Connections → Assistants** → pick your client → copy the configuration.

---

## Supported clients

There are two ways to connect, and the setup screen states which one your client uses.

| Client | Type | Transport | Config format |
|---|---|---|---|
| Claude Desktop | desktop | Connector | JSON `mcpServers` |
| Cursor | IDE | Connector | JSON `mcpServers` |
| Continue | IDE plugin | Connector | JSON `mcpServers` |
| OpenCode | CLI | Connector | JSON `mcpServers` |
| Windsurf | IDE | Connector | JSON `mcpServers` |
| Command Code | CLI | Connector | JSON `mcpServers` |
| GitHub Copilot / VS Code | IDE | Direct HTTP | JSON **`servers`** |
| Claude Code | CLI | Direct HTTP | `claude mcp add` command |
| Codex CLI | CLI | Direct HTTP | **TOML** `[mcp_servers.…]` |
| ChatGPT (Desktop) | desktop | Direct HTTP | TOML (shares Codex's file) |
| Gemini CLI | CLI | Direct HTTP | JSON `httpUrl` |

**Direct HTTP** talks straight to the site. Nothing is installed or run on your computer,
and Node.js is not involved. It needs the site reachable over HTTPS from the machine
running the client, so it is not an option for a localhost-only development site.

**Connector** runs a small script on your computer, served from your own site (never from
npm). **This path needs Node.js**; Direct HTTP does not.

### Certification

No client is marked Certified in this release. Certification is awarded only from a
recorded end-to-end run against a real site — see
[ASSISTANT-CERTIFICATION.md](ASSISTANT-CERTIFICATION.md) for what such a run must cover
and which runs have been executed. Earlier revisions of this file claimed Gold
certification for Claude Desktop and Cursor on the strength of the shared endpoint having
been exercised; that is evidence about the endpoint, not about a client, and the claim was
withdrawn.

There is **no per-client runtime**. Every client reaches the same MCP endpoint with the
same 42 tools; only the transport and the configuration format differ.

---

## The generated configuration

Connector clients (Claude Desktop, Cursor, Continue, OpenCode, Windsurf, Command Code):

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

Direct HTTP, VS Code / GitHub Copilot — note the root key is `servers`, **not**
`mcpServers`. A config using the wrong key is silently ignored rather than rejected:

```json
{
  "servers": {
    "wp-command-center": {
      "type": "http",
      "url": "https://example.com/wp-json/wp-command-center/v1/mcp",
      "headers": { "Authorization": "Bearer ${WPCC_TOKEN}" }
    }
  }
}
```

Direct HTTP, Codex CLI and ChatGPT Desktop — TOML, and the key is `mcp_servers` with an
underscore:

```toml
[mcp_servers.wp-command-center]
url = "https://example.com/wp-json/wp-command-center/v1/mcp"
bearer_token = "${WPCC_TOKEN}"
```

Direct HTTP, Claude Code — a command, not a file. The URL is positional:

```bash
claude mcp add --transport http wp-command-center https://example.com/wp-json/wp-command-center/v1/mcp \
  --header "Authorization: Bearer ${WPCC_TOKEN}"
```

- **The connector comes from your site**, not npm. It ships inside the plugin.
- **`${WPCC_TOKEN}` is a placeholder.** The generated config never contains a live token.
  Paste yours into the field on the setup screen and it is substituted in your browser, or
  replace the placeholder yourself.
- **`WPCC_CONTEXT_MODE`** is `compact` (the default) or `full`. It affects only how verbose
  tool *descriptions* are, never the data returned. In `compact` a tool's description is
  its title; the action enum, parameter names and parameter descriptions are exposed in
  full in both modes, which is what a client needs to call an operation correctly.

## Where each client keeps its config

| Client | Location |
|---|---|
| Claude Desktop (macOS) | `~/Library/Application Support/Claude/claude_desktop_config.json` |
| Claude Desktop (Windows) | `%APPDATA%\Claude\claude_desktop_config.json` |
| Cursor | `~/.cursor/mcp.json` |
| Windsurf | `~/.codeium/windsurf/mcp_config.json` |
| Codex CLI · ChatGPT Desktop | `~/.codex/config.toml` |
| Gemini CLI | `~/.gemini/settings.json` |
| GitHub Copilot / VS Code | `.vscode/mcp.json` (workspace) or the user `mcp.json` |
| Claude Code | no file — registered with `claude mcp add` |
| Others | See that client's own MCP documentation |

Restart the client after editing.

## Verifying

The setup screen has a **Test the connection safely** panel: paste a token and it runs a
read-only check of the endpoint, the handshake, the resources and the tools. It checks the
connector script too, but only for clients that actually use one.

Or ask the assistant a read-only question — *"What WordPress version is this site
running?"* It should call `system_info` and answer immediately. If it reports no tools on a
connector client, a missing Node.js is the usual cause; see
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
features (alt text, SEO drafts, draft content). Those are configured separately under
**Settings → Advanced → Built-in AI**, and **stay off until a key is added and the
individual tool is switched on**. WPCC makes no outbound AI call without one.
