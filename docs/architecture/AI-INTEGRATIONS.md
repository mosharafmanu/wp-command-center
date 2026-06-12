# AI Integrations — WP Command Center

Connect external AI tools to your WordPress site through the Model Context
Protocol (MCP). All eleven supported clients share the same MCP endpoint,
security model, capability enforcement, approval gates, queue pipeline,
audit trail, and rollback protection — no per-client runtimes exist.

## Quick reference

| # | Client | Vendor | Type | Status |
|---|--------|--------|------|--------|
| 1 | Claude Desktop | Anthropic | desktop | **Certified Gold** |
| 2 | ChatGPT | OpenAI | desktop | Compatible |
| 3 | Codex | OpenAI | desktop | Compatible |
| 4 | Gemini | Google | desktop | Compatible |
| 5 | Cursor | Anysphere | ide | **Certified Gold** |
| 6 | Continue | Continue Dev | ide_plugin | Compatible |
| 7 | OpenCode | Anomaly | cli | Compatible |
| 8 | Aider | Aider AI | cli | Compatible |
| 9 | Roo Code | Roo | ide_plugin | Compatible |
| 10 | Windsurf | Codeium | ide | Compatible |
| 11 | Command Code | Command Code | cli | Compatible |

---

## 1. Claude Desktop (Active)

Claude Desktop by Anthropic is the first fully supported MCP-native client.

### Step-by-step setup

#### 1. Install the plugin
Upload and activate WP Command Center on your WordPress site.

#### 2. Create an API token
Navigate to **Command Center → AI Integrations → Configuration** tab.

Choose one:
- **Generate Full Access Token** — for read + write operations (patching,
  plugin/theme changes, content creation).
- **Generate Read-Only Token** — for inspection only (site intelligence,
  diagnostics, code search).

The token is shown once — copy it immediately and store it securely.

#### 3. Copy the MCP configuration
On the Configuration tab, with **Claude Desktop** selected, click **Copy Config**
to copy the JSON configuration block to your clipboard. If you generated a
token on this page, the config already contains your token. Otherwise,
replace `${WPCC_TOKEN}` in the copied config with your real token.

#### 4. Paste into claude_desktop_config.json

| OS | Config file path |
|----|------------------|
| macOS | `~/Library/Application Support/Claude/claude_desktop_config.json` |
| Windows | `%APPDATA%\Claude\claude_desktop_config.json` |
| Linux | `~/.config/Claude/claude_desktop_config.json` |

If the file doesn't exist, create it. Paste the config inside the top-level
JSON object. If there are already `mcpServers` entries, merge the
`wp-command-center` entry under the existing `mcpServers` key.

**Full template config:**

```json
{
  "mcpServers": {
    "wp-command-center": {
      "command": "npx",
      "args": [
        "-y",
        "@anthropic-ai/mcp-client",
        "https://yoursite.com/wp-json/wp-command-center/v1/mcp"
      ],
      "env": {
        "WPCC_MCP_URL": "https://yoursite.com/wp-json/wp-command-center/v1/mcp",
        "WPCC_SITE_URL": "https://yoursite.com",
        "WPCC_TOKEN": "wpcc_your_64_char_token_here"
      }
    }
  }
}
```

Adjust the URLs to match your WordPress site. The MCP endpoint is always
`<site>/wp-json/wp-command-center/v1/mcp`.

#### 5. Restart Claude Desktop
Fully quit Claude Desktop and reopen it. The WP Command Center MCP server
should appear with tools, resources, and prompts available.

#### 6. Verify the connection
Restart Claude and ask:
> "List the plugins on my WordPress site"

Claude should respond with a list of installed plugins from your site
via the MCP tools. You can also verify from the admin UI: go to
**AI Integrations → Configuration → Test Connection** to check each layer
(health endpoint, agent manifest, discovery metadata, MCP initialize,
resources, tools).

---

## Other Clients

Cursor is individually Certified Gold. ChatGPT, Codex, Gemini, Continue,
OpenCode, Aider, Roo Code, Windsurf, and Command Code are listed as
Compatible: each has generated configuration for the shared MCP runtime,
but has not been individually certified end-to-end. The live registry and
AI Integrations page are the source of truth for client-specific config paths.

---

## The AI Integrations admin page

Access via **Command Center → AI Integrations** in the WordPress admin
sidebar. Four tabs provide everything you need:

### Clients tab
- Displays counts: Active Clients, Total Supported, MCP Tools, MCP Resources,
  MCP Server status.
- **Compatibility matrix**: table of all nine clients with vendor, type,
  certification status, and MCP support check.
- **Active client cards**: Claude Desktop and Cursor, with a
  `Configure` button linking to the Configuration tab.

### Configuration tab
- **Client selector**: buttons for configured clients. Selecting a client
  updates the config display.
- **API Tokens panel** (left column):
  - Generate Read-Only Token — creates a token with `read_only` scope.
  - Generate Full Access Token — creates a token with `full` scope.
  - Existing tokens table: label, scope, status, and a `Use` button that
    sets that token as the one shown in the config block.
  - Tokens are shown once at creation — copy them immediately.
- **Configuration panel** (right column):
  - Dynamically generated JSON config for the selected client.
  - **Copy Config** button copies the entire JSON block to clipboard.
  - The config includes the MCP endpoint URL, site URL, and token
    environment variable.
- **Where to paste this config** panel: OS-specific file paths where the
  configuration should be placed (for clients with known config paths).
- **Connection Test**: button that runs a multi-step verification:
  1. Health endpoint (`/health`)
  2. Agent manifest (`/agent/manifest`)
  3. Discovery metadata (`/claude/discovery`)
  4. MCP initialize (JSON-RPC 2.0)
  5. MCP resources list
  6. MCP tools list
  Each check reports pass/fail.

### Activity tab
Last 15 AI client audit events (claude.*, ai_client.*, mcp.* actions)
with timestamps. Empty state message when no AI activity has occurred.

### Security tab
Documents the shared security model:
- **Capabilities**: mapped tools require their assigned token capability when capability enforcement is enabled.
- **Approvals**: when approval enforcement is enabled in Settings, operations marked as requiring approval must go through request → approve → execute. Enforcement is off by default.
- **Queue**: all operations follow the same queuing and execution flow.
- **Audit**: every action logged with client source, actor context, and timestamp.
- **Rollback**: every modification is snapshotted before execution.

Architecture diagram:
```
AI Client → MCP → WP Command Center → Capability Runtime → Approval Runtime
→ Queue Runtime → OperationExecutor → Verification → Audit → Rollback
```

---

## Token creation workflow

### From AI Integrations page
1. Go to **AI Integrations → Configuration**.
2. Click **Generate Full Access Token** (or Read-Only).
3. The token appears in a notice — copy it now.
4. The config block on the right auto-populates with the new token.
5. Click **Copy Config** to get the full JSON.

### From Settings page
1. Go to **Command Center → Settings → API Tokens → Create New Token**.
2. Enter a label (e.g. "Claude Desktop").
3. Select scope: Read-only or Full access.
4. Optionally set an expiration (30 days, 90 days, 1 year, or never).
5. Click Create Token.
6. Copy the token — it will not be shown again.

---

## Config viewer and Copy Config button

The Configuration tab renders a dark-themed `<pre>` block with the
generated JSON. The **Copy Config** button uses the Clipboard API
(fallback: `document.execCommand('copy')`) to copy the config to the
system clipboard. A green checkmark with "Copied!" appears briefly.

After copying, paste the config into the appropriate file for your AI
client and OS:

| Client | macOS | Linux | Windows |
|--------|-------|-------|---------|
| Claude Desktop | `~/Library/Application Support/Claude/claude_desktop_config.json` | `~/.config/Claude/claude_desktop_config.json` | `%APPDATA%\Claude\claude_desktop_config.json` |

For future clients, config paths will be populated in the same format
once their integrations are active.

---

## Connection test

The **Test Connection** button on the Configuration tab runs six checks
via the REST API (no token required — the checks probe endpoint
availability):

1. **Health endpoint** — `GET /wp-json/wp-command-center/v1/health` returns `{status: "ok"}`
2. **Agent manifest** — `GET /wp-json/wp-command-center/v1/agent/manifest` returns plugin metadata
3. **Discovery metadata** — `GET /wp-json/wp-command-center/v1/claude/discovery` returns tools and resources
4. **MCP initialize** — `POST /wp-json/wp-command-center/v1/mcp` with `{jsonrpc: "2.0", method: "initialize"}` returns server info
5. **MCP resources** — `POST .../mcp` with `{method: "resources/list"}` returns 7+ resources
6. **MCP tools** — `POST .../mcp` with `{method: "tools/list"}` returns available tools

All six must pass for a successful connection. Failures indicate the
endpoint is unreachable, the REST API is disabled, or a firewall is
blocking `/wp-json/`.
