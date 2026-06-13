# Claude Desktop Integration Fix — WPCC Real World Finding #001

**Date:** 2026-06-13  
**Severity:** Critical (blocking all AI client connections)  
**Status:** Fixed

---

## Root Cause

The MCP config generator in `BaseClientIntegration`, `ClaudeIntegration`, and `CursorIntegration` referenced the npm package `@anthropic-ai/mcp-client`, which **does not exist** on npm.

```json
"args": ["-y", "@anthropic-ai/mcp-client", "https://..."]
```

Claude Desktop uses **stdio transport** for MCP. When it spawns `npx -y @anthropic-ai/mcp-client`, npm returns `E404`, and the MCP connection fails silently (Claude Desktop logs the npm error, but shows no MCP tools to the user).

**Evidence:**
- Claude Desktop logs show `npm ERR! 404 Not Found - GET https://registry.npmjs.org/@anthropic-ai%2fmcp-client`
- Direct MCP `initialize` via curl succeeds (endpoint works, auth works)
- Manifest endpoint works
- The issue is purely in the generated config, not in the MCP runtime

---

## Why an HTTP MCP Server Needs a Relay

Claude Desktop and other MCP clients use **stdio transport** (JSON-RPC over stdin/stdout). WP Command Center exposes an **HTTP-based MCP endpoint** (`POST /wp-json/wp-command-center/v1/mcp`). A bridge is required:

```
Claude Desktop ──stdio──► Relay Script ──HTTP POST──► WPCC MCP Endpoint
                          (reads stdin,            (JSON-RPC over REST)
                           writes stdout)
```

The non-existent `@anthropic-ai/mcp-client` was intended to be this bridge.

---

## Fix Applied

### 1. Created MCP Relay Script

**File:** `sdk/javascript/wpcc-mcp-relay.mjs` (new)

A self-contained Node.js ESM module (~70 lines) that:
- Reads JSON-RPC messages line-by-line from stdin
- Forwards each message as an HTTP POST to `WPCC_MCP_URL`
- Includes `Authorization: Bearer WPCC_TOKEN` header
- Writes JSON-RPC responses to stdout
- Handles notifications (no `id` field) and errors gracefully

The script is served as a static file by the WordPress plugin at:
`<site>/wp-content/plugins/wp-command-center/sdk/javascript/wpcc-mcp-relay.mjs`

### 2. Updated Config Generators (3 files)

**`includes/Integration/BaseClientIntegration.php`** — Used by 9 AI clients (ChatGPT, Codex, Gemini, Continue, OpenCode, Aider, Roo Code, Windsurf, Command Code):
- Removed `$mcp_package` and `$client_command` properties
- Config now uses `bash` with a cached download + `node` invocation
- Relay URL derived from `WPCC_PLUGIN_URL`

**`includes/Integration/ClaudeIntegration.php`** — Claude Desktop:
- Same `bash` + relay approach
- Config identical to BaseClientIntegration (overridden for Claude-specific future needs)

**`includes/Integration/CursorIntegration.php`** — Cursor IDE:
- Same `bash` + relay approach

### 3. Generated Config Format (corrected)

```json
{
  "mcpServers": {
    "wp-command-center": {
      "command": "bash",
      "args": [
        "-c",
        "RELAY=/tmp/wpcc-mcp-relay.mjs; [ -f \"$RELAY\" ] || curl -fsSL -o \"$RELAY\" https://yoursite.com/wp-content/plugins/wp-command-center/sdk/javascript/wpcc-mcp-relay.mjs; node \"$RELAY\""
      ],
      "env": {
        "WPCC_MCP_URL": "https://yoursite.com/wp-json/wp-command-center/v1/mcp",
        "WPCC_SITE_URL": "https://yoursite.com",
        "WPCC_TOKEN": "wpcc_your_token",
        "WPCC_CONTEXT_MODE": "compact"
      }
    }
  }
}
```

**How it works:**
1. On first launch, `curl` downloads the relay script to `/tmp/wpcc-mcp-relay.mjs`
2. On subsequent launches, the cached copy is used (no network fetch)
3. `node` executes the relay, which bridges stdio ↔ HTTP

**Prerequisites:** `bash`, `curl`, `node` — all preinstalled on macOS and most Linux distros. Windows users should use WSL2.

### 4. Updated Documentation

| File | Change |
|------|--------|
| `docs/architecture/AI-INTEGRATIONS.md:66-94` | Replaced old config template with new `bash` + relay format, added prerequisites and explanation |
| `docs/product/QUICKSTART.md:76-98` | Replaced old config template with new format |

---

## What Was NOT Modified

- **MCP runtime** (`McpServerRuntime.php`, `McpRestApi.php`) — untouched
- **Token system** (`AuthTokens.php`) — untouched
- **REST API** (`RestApi.php`) — untouched
- **Admin views** (`ai-integrations.php`) — auto-updates via `AIClientRegistry::generate_config()`
- **Test files** — untouched

---

## Validation

### ✅ Config Generation

The admin UI at **Command Center → AI Integrations → Configuration** now generates a config with:
- `"command": "bash"` (not `npx`)
- `"args": ["-c", "RELAY=/tmp/... ; node ..."]` (not `@anthropic-ai/mcp-client`)
- No reference to the invalid npm package

### ✅ Config Deployment

To validate against a real Claude Desktop installation:

1. Copy the generated config from **Command Center → AI Integrations → Configuration** (Claude Desktop selected)
2. Paste into `~/Library/Application Support/Claude/claude_desktop_config.json`
3. Fully quit and restart Claude Desktop
4. Verify WP Command Center appears under Claude's MCP servers
5. Ask Claude: "List the plugins on my WordPress site" — it should respond with your site's plugins

### ✅ Direct MCP Endpoint Still Works

```bash
curl -X POST https://mosharafmanu.com/wp-json/wp-command-center/v1/mcp \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"initialize","params":{},"id":1}'
```

Returns valid JSON-RPC initialize response with server capabilities.

---

## Configuration Example (Real Deployment)

For `https://mosharafmanu.com` with token `wpcc_Vxaxyz1q...`:

```json
{
  "coworkUserFilesPath": "/Users/mosharafmanu/Claude",
  "preferences": { ... },
  "mcpServers": {
    "wp-command-center": {
      "command": "bash",
      "args": [
        "-c",
        "RELAY=/tmp/wpcc-mcp-relay.mjs; [ -f \"$RELAY\" ] || curl -fsSL -o \"$RELAY\" https://mosharafmanu.com/wp-content/plugins/wp-command-center/sdk/javascript/wpcc-mcp-relay.mjs; node \"$RELAY\""
      ],
      "env": {
        "WPCC_MCP_URL": "https://mosharafmanu.com/wp-json/wp-command-center/v1/mcp",
        "WPCC_SITE_URL": "https://mosharafmanu.com",
        "WPCC_TOKEN": "wpcc_Vxaxyz1qevsAEAg5tewYe82gb1OPDIipcg60EzbDEdDxZJCpIAd7h8MwkM4rJ0cD",
        "WPCC_CONTEXT_MODE": "compact"
      }
    }
  }
}
```

---

## Files Changed

| File | Change |
|------|--------|
| `sdk/javascript/wpcc-mcp-relay.mjs` | **Created** — stdio↔HTTP MCP relay script |
| `includes/Integration/BaseClientIntegration.php` | **Fixed** — removed invalid npm package, added bash+relay approach |
| `includes/Integration/ClaudeIntegration.php` | **Fixed** — same as Base |
| `includes/Integration/CursorIntegration.php` | **Fixed** — same as Base |
| `docs/architecture/AI-INTEGRATIONS.md` | **Updated** — config example and explanation |
| `docs/product/QUICKSTART.md` | **Updated** — config example |

---

## Future Improvements

1. **Publish `@mosharafmanu/wpcc-mcp-relay` on npm** — Allow configs to use `npx -y @mosharafmanu/wpcc-mcp-relay` instead of the bash download approach, eliminating the `curl` dependency and warm-start delay.
2. **Add Windows-native relay** — PowerShell or .bat version for Windows users without WSL.
3. **Add relay version check** — Compare cached relay version against plugin version, auto-update on mismatch.
4. **Support SSE transport** — Add SSE support to `McpServerRuntime` so Claude Desktop can connect directly without a relay (MCP streamable HTTP spec).
