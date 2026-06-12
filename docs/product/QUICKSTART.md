# Quickstart — WP Command Center

Get WP Command Center running and connected to an AI client in ~10 minutes.

## Prerequisites

- A running WordPress site with admin access.
- Node.js installed (required for MCP client via npx).
- curl (preinstalled on macOS/Linux).

---

## Step 1: Install the plugin (1 min)

Upload the `wp-command-center` folder to `wp-content/plugins/` and activate
it from **Plugins → Installed Plugins** in the WordPress admin.

After activation, a new **Command Center** menu appears in the admin sidebar.

---

## Step 2: Create an API token (2 min)

1. Go to **Command Center → Settings → API Tokens**.
2. Fill in the "Create New Token" form:
   - **Label**: e.g. `Claude Desktop`
   - **Scope**: `Full access` (for read + write; choose `Read-only` if you
     only want AI inspection capabilities)
   - **Expires**: `Never` (or choose a duration for production tokens)
3. Click **Create Token**.
4. Copy the generated token (starts with `wpcc_`) — it will not be shown again.

**Alternative (AI Integrations page):**
Go to **Command Center → AI Integrations → Configuration** and click
**Generate Full Access Token**. This also auto-populates the MCP config.

---

## Step 3: Verify the connection (1 min)

Confirm the REST API is reachable and your token works:

```bash
curl -s -H "Authorization: Bearer wpcc_YOUR_TOKEN" \
  "https://yoursite.com/wp-json/wp-command-center/v1/site-intelligence" | jq .
```

Expected response: a JSON object with WordPress version, PHP version,
active theme, plugin list, cache status, and debug log status.

If you get `401`, double-check the token is copied correctly and the
Authorization header format is `Bearer wpcc_...` (capital B, space after
Bearer).

---

## Step 4: Connect Claude Desktop (3 min)

### 4a. Open the AI Integrations page
Go to **Command Center → AI Integrations → Configuration**.

### 4b. Generate a token and copy config
If you haven't already, click **Generate Full Access Token**. The config
block on the right updates automatically. Click **Copy Config**.

### 4c. Find your claude_desktop_config.json

| OS | Path |
|----|------|
| macOS | `~/Library/Application Support/Claude/claude_desktop_config.json` |
| Windows | `%APPDATA%\Claude\claude_desktop_config.json` |
| Linux | `~/.config/Claude/claude_desktop_config.json` |

If the file doesn't exist, create it with this structure:

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
        "WPCC_TOKEN": "wpcc_YOUR_ACTUAL_TOKEN"
      }
    }
  }
}
```

Replace `https://yoursite.com` with your actual site URL and
`wpcc_YOUR_ACTUAL_TOKEN` with the token from step 2.

### 4d. Restart Claude Desktop
Fully quit Claude Desktop and reopen it. The WP Command Center MCP tools
should appear.

### 4e. Verify Claude can talk to your site
Ask Claude in a new conversation:
> "List the plugins on my WordPress site using the WP Command Center tools."

Claude should respond with a list of installed plugins retrieved from your site.

---

## Step 5: Run your first read operation (1 min)

### Via Claude Desktop
Ask Claude:
> "Run a site intelligence scan and tell me the PHP version, WordPress
> version, and active theme."

### Via curl (operation endpoint)
```bash
# List plugins
curl -s -X POST \
  -H "Authorization: Bearer wpcc_YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"action":"plugin_list"}' \
  "https://yoursite.com/wp-json/wp-command-center/v1/operations/plugin_manage/run" | jq .

# Get site health
curl -s -X POST \
  -H "Authorization: Bearer wpcc_YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{}' \
  "https://yoursite.com/wp-json/wp-command-center/v1/operations/database_inspect/run" | jq .
```

---

## Step 6: Create and execute an approved operation (2 min)

Operations that modify your site require the approval workflow when
`wpcc_enforce_approval` is enabled.

### Via curl — full request → approve → execute cycle

```bash
BASE="https://yoursite.com/wp-json/wp-command-center/v1"
TOKEN="Authorization: Bearer wpcc_YOUR_TOKEN"

# 1. Create a content creation request
curl -s -X POST \
  -H "$TOKEN" -H "Content-Type: application/json" \
  -d '{
    "action": "content_create",
    "type": "post",
    "title": "Hello from WP Command Center",
    "content": "This post was created via the REST API.",
    "status": "draft"
  }' \
  "$BASE/operations/content_manage/run" | jq .

# If approval is required, the response will include a request_id.
# Approve it (using the returned request_id):
curl -s -X POST \
  -H "$TOKEN" -H "Content-Type: application/json" \
  -d '{"action":"content_approve"}' \
  "$BASE/operations/content_manage/run" | jq .

# The operation executes after approval. Check results:
curl -s -H "$TOKEN" "$BASE/site-intelligence" | jq .
```

### Via Claude Desktop
Claude handles the request → approve → execute workflow through the MCP
tools. You confirm the approval when Claude asks.

---

## Step 7: Review activity (1 min)

### AI Integrations Activity tab
Go to **Command Center → AI Integrations → Activity** to see the last 15
AI client events with timestamps (claude.*, ai_client.*, mcp.* actions).

### Timeline / audit log
```bash
# Fetch recent audit entries
curl -s -H "Authorization: Bearer wpcc_YOUR_TOKEN" \
  "$BASE/site-intelligence" | jq '.recent_activity'
```

---

## Common next steps

- **Patch files safely:** `POST /wp-json/wp-command-center/v1/patches`
  with `{files: [{path, modified}], explanation, risk_level}`. Approve
  and apply, with automatic snapshots for rollback.
- **Run diagnostics:** `GET /wp-json/wp-command-center/v1/diagnostics?type=performance`
- **Manage plugins:** `plugin_manage` operation — list, install,
  activate, deactivate, update, delete with health verification.
- **Inspect database:** `database_inspect` operation — health summary,
  table stats, autoload analysis, index analysis, orphan detection.
- **Connect additional AI clients:** All future clients (Codex, Gemini,
  Cursor, Continue, OpenCode, Aider, Roo Code, Windsurf) will use the
  same AI Integrations page and MCP endpoint.

---

## Troubleshooting quick reference

| Symptom | Check |
|---------|-------|
| 401 Unauthorized | Token is valid, `Authorization: Bearer` header is correct |
| MCP connection failed | Site URL is correct, REST API is accessible, `/wp-json/` not blocked |
| Operation stuck | Wait 5 min for lock expiry, check PHP error logs |
| Missing capability | Assign the required capability to your token |
| Patch rollback failed | Restore from manual backup |

See [TROUBLESHOOTING.md](TROUBLESHOOTING.md) for detailed solutions.
