# Assistant Certification Sprint — WP Command Center 1.0.0

**Prepared:** 2026-08-04 · **Method:** human-in-the-loop · **Standard:** not lowered.

Claude prepares setup, configuration and the verification checklist.
The owner executes each checklist in their own authenticated accounts.
Claude records the results and assigns the final status.

> **No assistant in this document is "Officially Certified" yet.** Every status below is
> either `Server-verified` (proven by Claude against the MCP server), `Config-prepared`
> (configuration corrected and ready for you to execute), or `Not supported`. Certification
> is awarded only from executed checklist results.

---

## 1. What Claude certified without any third-party account

This is the part that does not depend on any assistant. It was driven through the **shipped
relay** (`sdk/javascript/wpcc-mcp-relay.mjs`) over stdio JSON-RPC — byte-for-byte the same
path every stdio assistant uses — and separately over **direct HTTP**.

| Check | Result | Evidence |
|---|---|---|
| `initialize` handshake | **PASS** | protocol `2024-11-05`, serverInfo `WP Command Center 1.0.0`, caps `tools`/`resources`/`prompts` |
| Tool discovery | **PASS** | `tools/list` → **42 tools** |
| Resource discovery | **PASS** | `resources/list` → **7 resources** |
| JSON-RPC notification handling | **PASS** | `notifications/initialized` drew **no** response (4 messages in, 3 replies out) |
| Authentication | **PASS** | Bearer token accepted; requests without it rejected |
| Real read operation | **PASS** | `system_info` returned live site data (WP 7.0.2, PHP 8.2.27) |
| Governance gate | **PASS** | Standard protection → `pending_approval`, **wrote nothing** (0 posts created, draft count unchanged at 16,227) |
| Agent cannot self-approve | **PASS** | `approval_manage/request_approve` → `wpcc_approval_requires_human` |
| Human approval → execute | **PASS** | approve → worker → `completed` → post created |
| Undo is itself governed | **PASS** | `rollback_target` returned `pending_approval`, risk `high` |
| Per-runtime error contracts | **PASS** | `wpcc_invalid_approval_action`, `wpcc_invalid_history_action` |
| Idempotency key injection | **PASS** | relay stamps every `tools/call` with a UUID key |

**Conclusion: the MCP server and relay are sound.** Every assistant shares this engine, so
assistant-level failures from here are *transport and configuration* problems, not engine
problems. That is what the checklists below test.

---

## 2. Two transports — and why this matters a lot

The UI and `readme.txt` describe only one connection path (Node relay). **A second, simpler
path exists and is fully working but is undocumented:**

**A. stdio via the Node relay** — for clients that only speak stdio.
Requires Node.js on the client machine.

**B. Direct HTTP — no Node, no relay, no local process.**
Verified by Claude with plain `curl`:

```bash
curl -s -X POST "https://YOUR-SITE/wp-json/wp-command-center/v1/mcp" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_WPCC_TOKEN" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"x","version":"1"}}}'
```

Returned a valid `initialize` result; `tools/list` returned **42 tools**.

This removes the "Node.js required on the client machine" limitation for every assistant
that supports remote/HTTP MCP — Codex CLI, ChatGPT, VS Code / GitHub Copilot, Gemini CLI,
Claude Code, Cursor. **Transport B should be the recommended path for those clients.**

> **Requirement for transport B:** the site must be reachable over **HTTPS** from the client
> machine. `localhost` dev sites and internal staging will not work for hosted assistants
> (ChatGPT web in particular). Transport A remains correct for local development.

---

## 3. Research summary — real adoption, August 2026

| Assistant | Adoption signal | MCP | Notes |
|---|---|---|---|
| **GitHub Copilot** | **29% workplace adoption — #1** | Yes | Absent from WPCC's list entirely |
| **Cursor** | 18% workplace; ~$2B ARR Feb 2026 | Yes | In list |
| **Claude Code** | 18% workplace; **46% most-loved** (JetBrains Apr 2026) | Native | Not listed separately from Claude Desktop |
| **Claude Desktop** | Reference MCP client | Native | In list |
| **VS Code** | Native MCP client | Yes | Absent |
| **Cline** | **5M+ installs, 62.8k stars** | Yes | Absent |
| **Kilo Code** | 1.5M+ users; default Roo migration path | Yes | Absent |
| **Codex CLI / ChatGPT** | OpenAI ecosystem | Yes | In list, **misconfigured** |
| **Gemini CLI** | Google ecosystem | Yes | In list, wrong path |
| **Windsurf** | Codeium | Yes | In list, wrong path |
| **Qoder** (ex-Tongyi Lingma) | **5M+ users**, fastest-growing agentic tool | Verify | Absent — largest China gap |
| **Tencent CodeBuddy** | Hunyuan + DeepSeek, 200+ languages | Verify | Absent |
| **Trae** (ByteDance) | Growing | Verify | Absent |
| **Roo Code** | **ARCHIVED 15 May 2026** | n/a | **Still shipping in WPCC** |
| **Aider** | Declining; MCP support **disputed** | Disputed | One source: configs claiming MCP are fabricated |

Context: 84% of developers use AI coding tools; **70% run 2–4 tools simultaneously**, so
breadth genuinely matters — but only if each entry actually works.

---

## 4. Defects found in the current assistant layer

These are why the existing registry statuses cannot be trusted.

### 4.1 All 11 "per-assistant integrations" emit one identical config — CONFIRMED

Every `config_generator` was hashed. Result: **1 unique config hash across 11 clients**,
569 bytes each. The eleven `*Integration.php` classes never override
`BaseClientIntegration::generate_mcp_config()`. Only the *displayed file path* differs.

### 4.2 The single config is wrong for most of them

| Assistant | WPCC currently emits | Reality |
|---|---|---|
| Codex CLI | JSON `mcpServers` at `~/Library/Application Support/Codex/codex_config.json` | **TOML** `[mcp_servers.x]` at `~/.codex/config.toml` — wrong format *and* path |
| ChatGPT | JSON at `~/Library/Application Support/ChatGPT/mcp.json` | Desktop **shares Codex's config**; that path does not exist |
| Gemini CLI | `~/Library/Application Support/Gemini/mcp.json` | `~/.gemini/settings.json` |
| Windsurf | `~/Library/Application Support/Windsurf/mcp.json` | `~/.codeium/windsurf/mcp_config.json` |
| VS Code / Copilot | not offered | root key is **`servers`**, not `mcpServers` |
| Continue | JSON `~/.continue/mcp.json` | YAML `config.yaml` |

Claude Desktop and Cursor are the two that happen to be correct — which is consistent with
them being the only two the registry claims as Gold.

### 4.3 The generated config is macOS/Linux only

The bootstrap is `bash -c 'RELAY=…; curl -fsSL -o "$RELAY" …; node "$RELAY"'`.
Windows has no `bash` by default. Yet the registry advertises a Windows path for **every**
client. Windows users following the UI cannot connect via transport A.

### 4.4 Config downloads and executes a remote script on every start

`curl`s the relay into `/tmp` and `node`-executes it at each launch. Functional, but it is a
fetch-and-execute pattern over the network on every startup, and on a plain-HTTP site it is
unauthenticated plaintext. Transport B avoids this entirely.

### 4.5 Malformed tool calls consume a human approval

Observed three times: a call with wrong parameter names was accepted, queued, and presented
to a human for approval, then failed at execution (`wpcc_missing_content_title`,
`content_id is required`). Validation happens *after* approval. A correct assistant reading
the published tool schema sends correct names, so impact is limited — but weaker models
produce malformed calls more often, which makes this an assistant-quality issue.

### 4.6 `rollback_available: true` on changes recorded non-reversible

`content_create` / `content_update` responses advertise `"rollback_available":true`, but the
resulting `wpcc_change_log` rows record `reversible = 0` and `rollback_target` correctly
refuses with `wpcc_not_reversible`. The envelope promises what the change log denies.
**Engine-level; out of scope for this sprint. Logged for the overnight gate.**

---

## 5. Recommended assistant list

### Ship in 1.0 — after checklist execution
Highest adoption **and** a config that can be made correct today.

| Assistant | Transport | Why |
|---|---|---|
| Claude Desktop | A (stdio) | Reference MCP client; config already correct |
| Claude Code | B (HTTP) | 46% most-loved; `claude mcp add --transport http` |
| Cursor | A or B | 18% workplace; config already correct |
| GitHub Copilot / VS Code | B (HTTP) | **#1 adoption at 29%** — biggest current gap |
| Codex CLI | B (HTTP) | Needs the TOML fix |
| Gemini CLI | B (HTTP) | Needs the path fix |

### Experimental in 1.0
Real adoption, config prepared, but lower confidence or unverified vendor specifics.

| Assistant | Reason |
|---|---|
| Windsurf | Path corrected but unverified against current build |
| ChatGPT (desktop) | Works only via the shared Codex config; web is remote-only + needs public HTTPS |
| Continue | YAML schema differs; lower adoption |
| OpenCode | Distinct schema; smaller base |
| Command Code | Exists and supports MCP, but niche |

### Defer to 1.1
| Assistant | Reason |
|---|---|
| Qoder (ex-Tongyi Lingma) | **5M+ users — largest single gap**; needs vendor-doc verification |
| Tencent CodeBuddy | Significant China adoption; MCP specifics unverified |
| Trae (ByteDance) | Growing; unverified |
| Qwen Code | Ecosystem large; client MCP support unverified |
| Kilo Code | Roo's successor; natural migration target |
| Cline | 5M+ installs, and the research below stands — but it is **not in the shipped registry**, and adding a client at release candidate that nobody can run the checklist against would ship an untested claim. §6.7 keeps the prepared config for 1.1. |

### Remove from 1.0 — do not present as supported
| Assistant | Reason |
|---|---|
| **Roo Code** | **Repository archived 15 May 2026**; services offline. Shipping it points users at a dead tool. |
| **Aider** | Native MCP support disputed; one source states MCP configs for it are fabricated. Cannot be presented as supported without proof. |

---

## 6. Corrected configurations

Replace `YOUR-SITE`, and paste your token where shown. Create the token at
**WP Command Center → Settings → Connections → Access tokens**.

### 6.1 Claude Desktop — transport A
`~/Library/Application Support/Claude/claude_desktop_config.json`
(Windows `%APPDATA%\Claude\claude_desktop_config.json`)
```json
{
  "mcpServers": {
    "wp-command-center": {
      "command": "node",
      "args": ["/absolute/path/to/wpcc-mcp-relay.mjs"],
      "env": {
        "WPCC_MCP_URL": "https://YOUR-SITE/wp-json/wp-command-center/v1/mcp",
        "WPCC_TOKEN": "wpcc_YOUR_TOKEN"
      }
    }
  }
}
```

### 6.2 Claude Code — transport B
```bash
claude mcp add wp-command-center \
  --transport http \
  --url https://YOUR-SITE/wp-json/wp-command-center/v1/mcp \
  --header "Authorization: Bearer wpcc_YOUR_TOKEN"
```

### 6.3 Cursor — transport A
`~/.cursor/mcp.json` — same `mcpServers` block as 6.1.

### 6.4 GitHub Copilot / VS Code — transport B
`.vscode/mcp.json` — **root key is `servers`:**
```json
{
  "servers": {
    "wp-command-center": {
      "type": "http",
      "url": "https://YOUR-SITE/wp-json/wp-command-center/v1/mcp",
      "headers": { "Authorization": "Bearer wpcc_YOUR_TOKEN" }
    }
  }
}
```

### 6.5 Codex CLI (and ChatGPT desktop) — transport B
`~/.codex/config.toml` — **TOML, not JSON:**
```toml
[mcp_servers.wp-command-center]
url = "https://YOUR-SITE/wp-json/wp-command-center/v1/mcp"
bearer_token = "wpcc_YOUR_TOKEN"
```
Or `codex mcp add`. ChatGPT desktop reads this same file.

### 6.6 Gemini CLI — transport B
`~/.gemini/settings.json`
```json
{
  "mcpServers": {
    "wp-command-center": {
      "httpUrl": "https://YOUR-SITE/wp-json/wp-command-center/v1/mcp",
      "headers": { "Authorization": "Bearer wpcc_YOUR_TOKEN" }
    }
  }
}
```

### 6.7 Cline — transport A *(prepared for 1.1; not in the 1.0 registry)*
`~/Library/Application Support/Code/User/globalStorage/saoudrizwan.claude-dev/settings/cline_mcp_settings.json`
— same `mcpServers` block as 6.1.

### 6.8 Windsurf — transport A
`~/.codeium/windsurf/mcp_config.json` — same `mcpServers` block as 6.1.

---

## 7. The verification checklist — run this per assistant

Identical for every assistant, so results are comparable. **Set the site to Standard
protection before starting** (Settings → Protection).

| # | Step | Ask the assistant | Pass criteria |
|---|---|---|---|
| 1 | **Connect** | — | Assistant lists `wp-command-center` as connected |
| 2 | **Tool discovery** | "What WordPress tools do you have?" | **42 tools** visible |
| 3 | **Resource discovery** | "List available resources." | **7 resources** |
| 4 | **Read** | "What plugins are installed on my site?" | Real plugin list, no approval prompt |
| 5 | **Proposal** | "Change my site tagline to 'Certified via <assistant>'." | Returns `pending_approval` + request ID |
| 6 | **Nothing applied** | — | Tagline in Settings → General **unchanged** |
| 7 | **Self-approve refused** | "Approve that request yourself." | `wpcc_approval_requires_human` |
| 8 | **Approve** | — (admin UI) | Approvals screen shows it; approve; tagline changes |
| 9 | **Audit** | — | Changes screen shows it, attributed, with a timestamp |
| 10 | **Rollback** | "Undo that change." | Gated, then applied; tagline reverts |
| 11 | **Double undo refused** | "Undo it again." | `wpcc_already_rolled_back` |
| 12 | **Reconnect** | Restart the assistant | Reconnects; 42 tools still present |

### Scoring
- **Officially Certified** — 12/12.
- **Experimental** — connects and reads (1–4) but any of 5–12 fails or is unverified.
- **Not Supported** — cannot connect or the governance chain (5–8, 10–11) fails.

### Record results here

The eleven clients the registry actually ships — no more, so a blank row always means
"not yet run" rather than "not offered".

| Assistant | 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 | 9 | 10 | 11 | 12 | Status |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Claude Code | | | | | | | | | | | | | |
| Claude Desktop | | | | | | | | | | | | | |
| Cursor | | | | | | | | | | | | | |
| GitHub Copilot / VS Code | | | | | | | | | | | | | |
| Codex CLI | | | | | | | | | | | | | |
| Gemini CLI | | | | | | | | | | | | | |
| ChatGPT (Desktop) | | | | | | | | | | | | | |
| Windsurf | | | | | | | | | | | | | |
| Continue | | | | | | | | | | | | | |
| OpenCode | | | | | | | | | | | | | |
| Command Code | | | | | | | | | | | | | |

---

## 8. Current compatibility matrix

Claude-verified only. **Nothing is Certified until section 7 is executed.**

| Assistant | Connection | Certification | Notes |
|---|---|---|---|
| *(MCP server itself)* | ✅ | ✅ **Server-verified** | 42 tools, 7 resources, full governance chain — §1 |
| Claude Desktop | ✅ config correct | ⏳ Awaiting checklist | Only client whose shipped config was already right |
| Cursor | ✅ config correct | ⏳ Awaiting checklist | Shipped config correct |
| Claude Code | ✅ config prepared | ⏳ Awaiting checklist | Transport B; not previously offered |
| GitHub Copilot / VS Code | ✅ config prepared | ⏳ Awaiting checklist | **#1 adoption; not previously offered** |
| Codex CLI | ⚠️ was broken | ⏳ Awaiting checklist | Shipped JSON; needs TOML — could not have worked |
| Gemini CLI | ⚠️ was broken | ⏳ Awaiting checklist | Wrong config path |
| Windsurf | ⚠️ was broken | ⏳ Awaiting checklist | Wrong config path |
| ChatGPT | ⚠️ was broken | ⏳ Awaiting checklist | Path fictional; desktop shares Codex config |
| Cline | — | ➖ **Not in 1.0** | Config prepared for 1.1; not in the shipped registry |
| Continue | ⚠️ was broken | ⏳ Experimental | Needs YAML |
| OpenCode | ⚠️ was broken | ⏳ Experimental | Distinct schema |
| Command Code | ⚠️ unverified | ⏳ Experimental | Niche |
| **Roo Code** | ❌ | ❌ **Not Supported** | **Archived 15 May 2026** |
| **Aider** | ❌ | ❌ **Not Supported** | Native MCP disputed |

---

## 9. WPCC changes

### Implemented in this sprint

| # | Change | Status |
|---|---|---|
| 1 | Per-assistant config generation — was 1 identical config for 11 clients, now **5 distinct formats** correctly grouped | ✅ Done |
| 2 | Corrected paths/formats: Codex → TOML `~/.codex/config.toml`; ChatGPT → shares Codex file; Gemini → `~/.gemini/settings.json`; Windsurf → `~/.codeium/windsurf/mcp_config.json` | ✅ Done |
| 3 | Transport B (direct HTTP) offered for Codex, ChatGPT, Gemini, VS Code/Copilot, Claude Code — no relay, no Node.js | ✅ Done |
| 4 | Added **GitHub Copilot / VS Code** (root key `servers`) and **Claude Code** (`claude mcp add`) | ✅ Done |
| 5 | Removed **Roo Code** (archived) and **Aider** (MCP disputed) | ✅ Done |
| 6 | Reset registry honesty: 5 unearned Gold markers downgraded; 7 transitive-certification claims rewritten | ✅ Done |
| 7 | `render_config()` — token substitution now works for JSON, TOML and shell alike; the old code reached into a fixed array path that silently missed any client not shaped like Claude Desktop | ✅ Done |
| 8 | Setup copy is transport-aware — HTTP clients no longer told a script runs on their machine | ✅ Done |

### Still outstanding

| # | Change | Blocked on |
|---|---|---|
| 9 | Certified / Experimental labels in the UI | **Your checklist results** — labels must reflect executed runs, not predictions |
| 10 | Windows relay bootstrap (`bash`/`curl` unavailable) — §4.3 | Affects transport A only; transport B is the Windows-safe path today |
| 11 | Continue (YAML) and OpenCode (distinct schema) still emit generic JSON | Ship as Experimental, or fix in 1.1 |

**Not in scope:** §4.5 and §4.6 are engine behaviours — logged for the overnight gate, no
governance/rollback changes made.

**Not in scope:** §4.5 and §4.6 are engine behaviours — logged for the overnight gate, no
governance/rollback changes made.

---

## 10. Sources

- [AI coding market share 2026](https://baeseokjae.github.io/posts/ai-coding-market-share-adoption-2026/) · [Cursor vs Copilot market share](https://www.ideaplan.io/blog/ai-coding-assistant-market-share-2026)
- [Kilo Code vs Roo Code — Roo archives 15 May 2026](https://kilo.ai/kilo-code/vs/roo-code) · [Roo Code vs Kilo Code](https://theaiagentindex.com/compare/roo-code-vs-kilo-code)
- [Cline vs Kilo Code](https://kilo.ai/kilo-code/vs/cline)
- [Codex MCP docs](https://developers.openai.com/codex/mcp) · [Codex CLI MCP setup](https://agentpatch.ai/blog/codex-cli-mcp-setup/)
- [Gemini CLI MCP servers](https://google-gemini.github.io/gemini-cli/docs/tools/mcp-server.html)
- [VS Code MCP servers](https://code.visualstudio.com/docs/agent-customization/mcp-servers) · [Copilot CLI MCP](https://docs.github.com/en/copilot/how-tos/copilot-cli/customize-copilot/add-mcp-servers)
- [ChatGPT MCP compatibility](https://resolvemesh.com/guides/chatgpt-mcp-compatibility)
- [Aider MCP — no native support](https://www.wearewarp.com/agents/mcp/aider)
- [Chinese AI coding agents](https://www.recodechinaai.com/p/chinese-ai-companies-are-building) · [Lingma/Qoder](https://www.alibabacloud.com/help/en/lingma/product-overview/introduction-of-lingma)
