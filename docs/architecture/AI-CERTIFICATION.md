# AI Client Certification Framework

## Overview

All AI clients that connect to WP Command Center go through a unified certification framework. This ensures consistent validation of MCP compatibility, security posture, and operational reliability — regardless of which vendor produced the client.

## Certification Levels

| Level | Name | Requirements | Who Has It |
|---|---|---|---|
| 0 | **Planned** | Not yet validated. Registered in the AIClientRegistry with compatibility metadata. | — |
| 1 | **Compatible** | Uses the shared MCP runtime and has a generated configuration, but has not been individually certified end-to-end. | 9 clients |
| 2 | **Active** | Compatible + discovers tools and resources via `tools/list` and `resources/list`. | — |
| 3 | **Certified Bronze** | Active + discovery validated end-to-end (7 resources, 15 tools). | — |
| 4 | **Certified Silver** | Bronze + capabilities, approvals, and queue workflow validated. | — |
| 5 | **Certified Gold** | Silver + rollback, audit, timeline, security (protected files, token auth, redaction), and stress testing (30 rapid requests with 0 failures). | Claude Desktop, Cursor |

## Certification Process

To certify a new AI client:

### Phase 1: Bronze (Discovery)
1. Verify the client can `initialize` via MCP
2. Verify `resources/list` returns 7 resources
3. Verify `tools/list` returns 15 tools
4. Verify `resources/read` works for each resource URI

### Phase 2: Silver (Operations)
5. Verify capability enforcement works (token without required capability is denied)
6. Verify approval workflow (request → approve → execute)
7. Verify queue lifecycle (queued → running → completed)
8. Verify results retrieval

### Phase 3: Gold (Full Platform)
9. Verify rollback (patch create → approve → apply → rollback → verify)
10. Verify audit events in timeline
11. Verify security (no-token blocked, protected files blocked, redaction active)
12. Verify performance (30 rapid MCP requests with 0 failures)
13. Verify backward compatibility (all legacy endpoints preserved)

## Client-Specific vs Model-Agnostic

WP Command Center certifies **AI clients** (applications that connect via MCP), not AI models. A single client may use different AI models:

| Client | Models It Can Use |
|---|---|
| Claude Desktop | Claude models |
| ChatGPT | OpenAI models |
| Codex | OpenAI models |
| Gemini | Google models |
| Cursor | Any (Claude, OpenAI, Gemini, etc.) |
| Continue | Any (DeepSeek, Qwen, Llama, etc.) |
| OpenCode | Any (DeepSeek, Qwen, Llama, etc.) |
| Aider | Any (DeepSeek, Qwen, Llama, etc.) |

This means: certifying "Cursor + Qwen" is the same as certifying "Cursor" — the client handles model selection. WP Command Center interacts with the client, not the model.

## Current Certification Status

| Client | Certification | Last Validated |
|---|---|---|
| Claude Desktop | **Certified Gold** | 2026-06-12 |
| ChatGPT | Compatible | 2026-06-12 |
| Codex | Compatible | 2026-06-12 |
| Gemini | Compatible | 2026-06-12 |
| Cursor | **Certified Gold** | 2026-06-12 |
| Continue | Compatible | 2026-06-12 |
| OpenCode | Compatible | 2026-06-12 |
| Aider | Compatible | 2026-06-12 |
| Roo Code | Compatible | 2026-06-12 |
| Windsurf | Compatible | 2026-06-12 |
| Command Code | Compatible | 2026-06-12 |

## Architecture

```
AI Client → MCP → WP Command Center → Capability → Approval → Queue → Execute → Verify → Audit → Rollback
```

All clients use the same MCP endpoint (`POST /wp-json/wp-command-center/v1/mcp`). No per-client runtimes exist. Shared-runtime compatibility does not by itself constitute client-specific Gold certification.
