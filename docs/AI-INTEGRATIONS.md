# AI client integrations

Connect from **Settings → Connections → Assistants**: choose the app you use, create an
access token, follow its single Recommended setup, restart/open the app, and run the
provided read-only verification prompt. Protocol details and manual files are under the
collapsed **Advanced** section.

---

## Supported clients

The customer-facing selector is app-first and grouped by product family. The exact surface
still appears on every card so desktop, terminal and editor consumers are not conflated.

| Client | Type | Transport | Config format |
|---|---|---|---|
| Codex in ChatGPT Desktop | desktop · Codex mode | Direct HTTP | Native Codex registration; shared TOML |
| Codex CLI | CLI | Direct HTTP | Native registration; **TOML** `[mcp_servers.…]` |
| Claude Desktop | desktop | Connector | JSON `mcpServers` |
| Claude Code | CLI | Direct HTTP | `claude mcp add` command |
| Antigravity CLI (`agy`) | CLI | Direct HTTP | Native `agy mcp add`; JSON `serverUrl` in `~/.gemini/config/mcp_config.json` |
| Gemini CLI (`gemini`) | CLI | Direct HTTP | Native `gemini mcp add`; JSON `url` + `type: http` in `~/.gemini/settings.json` |
| Cursor | IDE | Direct HTTP | Official reviewed `cursor://` MCP install; JSON fallback under Advanced |
| Continue for VS Code | VS Code extension | Connector | Complete `mcpServers:` YAML block for a first server, or one indented list entry for an existing list (`~/.continue/config.yaml`) |
| GitHub Copilot in VS Code | Copilot Agent mode | Direct HTTP | VS Code user MCP configuration, JSON **`servers`** |
| OpenCode | CLI | Direct HTTP | Native `opencode mcp add`; JSONC remote-server fallback |
| Command Code | CLI | Direct HTTP | Native `cmd mcp add` |
| Muse Code | Standalone `muse` CLI · experimental | Direct HTTP | `~/.config/muse/settings.json` (`mcp_servers`); actual read-only `system_info` verified |

**Direct HTTP** talks straight to the site. Nothing is installed or run on your computer,
and Node.js is not involved. It needs the site reachable over HTTPS from the machine
running the client, so it is not an option for a localhost-only development site.

**Connector** runs a small script on your computer, served from your own site (never from
npm). **This path needs Node.js**; Direct HTTP does not.

### Certification

The final v1 closeout statuses below come from retained actual-client evidence, not from
shared endpoint tests or configuration inspection. In the backward-compatible registry,
`active` is the internal value for **CERT_PASS**, `gold` is **CERT_GOLD**, and clients that
are blocked, not testable or failed remain at the narrower `compatible` value with their
exact verdict in `validation_notes`.

| Client | Final v1 status | Retained evidence / limitation |
|---|---|---|
| Codex in ChatGPT Desktop | **CERT_PASS** | Actual Codex surface in the ChatGPT desktop app: 42 tools and `system_info`. Normal ChatGPT conversations do not use this local MCP connection. Not Gold. |
| Codex CLI 0.153.4 | **CERT_PASS** | Actual owner retest passed with the approval-aware launch: native registration, 42 tools, 7 resources and `system_info`. No governance bypass. |
| Claude Code | **CERT_GOLD** | Actual client completed the formal 12-step lifecycle. |
| Claude Desktop | **CERT_PASS** | Actual desktop client loaded 42 tools and completed `system_info`; no Gold/full-lifecycle claim. |
| Gemini CLI 0.46.0 | **BLOCKED — EXTERNAL ACCOUNT/PROVIDER** | Setup contract verified; Google rejects this individual Gemini Code Assist client/account and directs the owner to Antigravity. This is external to WPCC. |
| Antigravity CLI (`agy`) 1.1.27 | **CERT_PASS** | Actual client: auth/init, 42 tools, 7 resources, two benign reads, Read-only write denial and reconnect. |
| Cursor | **CERT_PASS** | Retained actual client: auth/init, 42 tools, 7 resources, `system_info` and reload/reconnect. The latest attempt loaded MCP but model High Load/account availability prevented completion; that is external, not a WPCC defect. Never Gold. |
| Continue for VS Code | **CERT_PASS** | Actual extension client executed `system_info` and discovered 42 tools; no Gold/full-lifecycle claim. |
| OpenCode | **CERT_PASS** | Actual native remote-HTTP client executed `system_info`; exact client-side counts were not retained. |
| Command Code 1.51.0 | **CERT_PASS** | Owner manual test: the actual client connected and completed read-only `system_info`; no governed write/full lifecycle was run. |
| GitHub Copilot in VS Code | **CERT_PASS** | Fresh actual VS Code 1.136.2 / built-in Copilot 0.64.1 test reproduced the 401→OAuth/DCR failure with a missing/invalid secure input. With the correct fresh token, the same documented header config exposed 42 tools and 6 prompts, ran `system_info` exactly once without OAuth/DCR, and reconnected. No content, approval, queue or change-history mutation. |
| Muse Code 1.0.3 | **CERT_PASS** | Owner manual test after Meta authentication and plan activation: actual `muse` completed read-only `system_info` with credible local site, WordPress, PHP, MySQL, theme, plugin and environment data. Experimental positioning remains; not Gold. |

[Gemini Spark](https://support.google.com/gemini/answer/17209137) is a genuine hosted MCP
host, but it is not in the v1 selector. Google’s current custom-app flow is limited by
Spark eligibility and Google Account linking, asks for registration credentials when DCR
is unavailable, and documents no arbitrary static Authorization-header input. A hosted
Gemini surface also cannot reach this development site’s localhost URL. Supporting it
would require public HTTPS plus an approved OAuth/account-linking product contract, so it
is not compatible with WPCC’s current local access-token onboarding. Gemini CLI remains
the supported independent Google client; ordinary Gemini chats are not represented by
that card. Muse Code remains the standalone experimental host; Muse Spark is its
model/API, not another client card.

Windsurf was removed and is not a supported current client. See
[ASSISTANT-CERTIFICATION.md](ASSISTANT-CERTIFICATION.md) for the closeout matrix and the
preserved formal lifecycle criteria.

There is **no per-client runtime**. Every client reaches the same MCP endpoint with the
same 42 tools; only the transport and the configuration format differ.

---

## The generated configuration

Connector clients (Claude Desktop, Continue):

```json
{
  "mcpServers": {
    "wp-command-center": {
      "command": "bash",
      "args": ["-c", "RELAY='/tmp/wpcc-mcp-relay.mjs'; curl -fsSL -o \"$RELAY\" 'https://example.com/wp-content/plugins/ai-command-center/sdk/javascript/wpcc-mcp-relay.mjs?v=1.0.1'; node \"$RELAY\""],
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
`mcpServers`. The token input id is unique to the access-token record so VS Code cannot
silently reuse a revoked value from secure storage. A config using the wrong root key is
silently ignored rather than rejected:

```json
{
  "servers": {
    "wp-command-center": {
      "type": "http",
      "url": "https://example.com/wp-json/wp-command-center/v1/mcp",
      "headers": { "Authorization": "Bearer ${input:wpcc-token-EXAMPLE}" }
    }
  },
  "inputs": [{
    "type": "promptString",
    "id": "wpcc-token-EXAMPLE",
    "description": "WP Command Center token for Example Site",
    "password": true
  }]
}
```

VS Code prompts for that value and stores it securely. WPCC does not use OAuth for this
connection. If VS Code shows an OAuth/client-registration screen, cancel it and update
the stored WPCC input with a current token.

Direct HTTP, Codex CLI and Codex in ChatGPT Desktop — TOML, and the key is `mcp_servers` with an
underscore:

```toml
[mcp_servers.wp-command-center]
url = "https://example.com/wp-json/wp-command-center/v1/mcp"
bearer_token_env_var = "WPCC_TOKEN"
default_tools_approval_mode = "writes"
```

For Codex CLI on macOS/Linux, run `export WPCC_TOKEN='YOUR_TOKEN'`, then the displayed
`codex mcp add` command, then `codex --ask-for-approval on-request` in the **same terminal**. A new terminal needs
the export again. Do not save the token in shell profiles by default. The optional
`printenv WPCC_TOKEN >/dev/null && echo "WPCC_TOKEN is ready" || echo "WPCC_TOKEN is missing"`
check reports presence without printing the secret. The config keeps the variable name;
never paste the token into `bearer_token_env_var`.

Codex in ChatGPT Desktop on macOS uses `launchctl setenv WPCC_TOKEN 'YOUR_TOKEN'` instead:
Dock/Finder-launched apps do not reliably inherit shell-local exports. Fully quit and
reopen Desktop afterward, then switch the product selector from ChatGPT to Codex. Normal
ChatGPT chats do not use this local connection. Shared TOML does not mean shared credential bootstrap.

Direct HTTP, Claude Code — a command, not a file. The URL is positional:

```bash
claude mcp add --transport http wp-command-center https://example.com/wp-json/wp-command-center/v1/mcp \
  --header "Authorization: Bearer ${WPCC_TOKEN}"
```

- **The connector comes from your site**, not npm. It ships inside the plugin.
- **`${WPCC_TOKEN}` is a placeholder for inline-header clients.** Their setup field
  substitutes it in the browser. Codex CLI and Codex in ChatGPT Desktop instead keep the variable
  name `WPCC_TOKEN` in their config; only their credential bootstrap command gets the token.
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
| Codex CLI · Codex in ChatGPT Desktop | `~/.codex/config.toml` |
| Gemini CLI | `~/.gemini/settings.json` |
| Continue for VS Code | `~/.continue/config.yaml` — use the complete block if `mcpServers` is missing, or the indented entry-only copy beneath an existing key |
| GitHub Copilot in VS Code | Command Palette → `MCP: Open User Configuration` |
| Muse Code | `~/.config/muse/settings.json` — the setup helper creates it only when missing; use the complete new-file structure or merge the entry inside existing `mcp_servers` |
| Claude Code | no file — registered with `claude mcp add` |
| Others | See that client's own MCP documentation |

Restart the client after editing.

## Verifying

The setup screen ends every path with **Verify in &lt;app&gt;**. First use that app's MCP
inspection surface where available, then send the copyable prompt asking it to run
`system_info` once, read-only. Registration or a browser endpoint check alone is not a
working-client certification. If it reports no tools on a
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

## Antigravity CLI (`agy`) — native setup

Verified against installed `agy 1.1.27` on 2026-09-06. Select **Antigravity CLI**
in Settings → Connections → Assistants, create a **Read-only** token, then use
**Next: set up Antigravity CLI**. Follow the displayed file-permission preparation
and copy the generated `agy mcp add --header …` command. Flags precede the server
name. Native add merges the named server and preserves unrelated registrations.

The native client stores the bearer header in `~/.gemini/config/mcp_config.json`.
This integration does not claim environment-variable substitution for that header.
The guided macOS/Linux preparation uses `umask 077` and protects an existing file
with `chmod 600`. Keep the command out of shell history (`unset HISTFILE` in a
fresh setup terminal) and never commit/share the configuration or save the token
in shell profiles. On Windows, restrict the file to your user account. Revoke the
token in WPCC when it is no longer required.

JSON remains the **manual/advanced** fallback: add only `wp-command-center` inside
existing `mcpServers`, with `serverUrl` and `headers.Authorization`; never replace
an existing file wholesale. The placeholder is replaced by WPCC's browser UI,
not by an assumed `agy` credential environment abstraction.

Exit a running `agy`, start a fresh process, and check `/mcp` for the connected
WPCC server and 42 tools. Ask for `system_info` and
`report_manage` / `report_site_health`. `agy mcp list` proves registration only;
WPCC's browser endpoint test proves server/token behavior, not native connectivity.
Gemini CLI remains a separate `gemini` client with its own native command and file.

Read-only tokens allow the audited information/list/get/diagnostic actions in
[the complete 42-operation contract](reports/validation/WPCC-READONLY-42-AUDIT.md).
They cannot mutate data, submit mutation proposals, approve/reject, execute queues,
or upgrade their capabilities. Action-level scope restrictions apply in Standard,
Strict and Development modes, independently of approval settings.
