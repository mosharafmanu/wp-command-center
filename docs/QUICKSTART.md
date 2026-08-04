# Quickstart — connect an assistant in about five minutes

Assumes WordPress 6.4+ and PHP 8.0+ on a single site (not multisite).

---

## 1. Install and activate

Upload `ai-command-center-1.0.0.zip` through **Plugins → Add New → Upload Plugin**, then
activate it.

On activation the plugin creates its tables and sets protection to **Standard
protection** — changes will wait for your approval from the first minute. You do not have
to configure anything to be protected.

## 2. Create a token

**WP Command Center → Settings → Connections → Tokens → Add token.**

Choose a scope:

| Scope | What it can do |
|---|---|
| **Read-only** | Search, read files, read history. No writes, ever. |
| **Full access** | Everything the capabilities allow — still subject to approval. |

The token is shown **once**. Copy it now; it is stored only as a hash.

> A full-access token does not bypass approval. In Standard protection every medium-risk
> or higher change still waits for you.

## 3. Generate the client configuration

**Settings → Connections → Assistants**, pick your client, and copy the generated
configuration. It looks like this:

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

Replace `${WPCC_TOKEN}` with the token from step 2. The config never contains a live
token — you paste it in yourself.

The relay is a small stdio↔HTTP bridge shipped **inside the plugin**. It is downloaded
from your own site, not from npm, so nothing external needs to be trusted or installed
beyond Node.

**Requires Node.js on the machine running the assistant** — but only if your assistant
uses the connector. GitHub Copilot / VS Code, Claude Code, Codex CLI, ChatGPT and Gemini
CLI connect straight over HTTP and install nothing; the setup screen states which path
yours uses. See [AI-INTEGRATIONS.md](AI-INTEGRATIONS.md) for each client's format.

## 4. Restart your client and check the connection

After restarting, ask the assistant something harmless:

> "What WordPress version is this site running?"

It should call `system_info` and answer immediately — reads are never gated.

## 5. Make your first change

> "Create a draft post titled 'Hello from my assistant'."

In Standard protection the assistant will report that the change is **waiting for
approval** and give you a link. Open **WP Command Center → Approvals**, review exactly
what will change, and approve it.

The queue worker runs on WP-Cron every five minutes; approving from the admin executes
the change immediately.

## 6. Undo it

Open **WP Command Center → Changes**, find the entry, and choose **Undo**. An undo is
itself a governed change and follows the same approval rules.

---

## If something does not work

The two most common issues:

- **"Missing API token"** — some servers do not pass the `Authorization` header to PHP.
  See [TROUBLESHOOTING.md](TROUBLESHOOTING.md#missing-api-token).
- **The client shows zero tools** — on a connector client, usually Node is missing, or the relay URL is not
  reachable. See [TROUBLESHOOTING.md](TROUBLESHOOTING.md#the-client-connects-but-shows-no-tools).
