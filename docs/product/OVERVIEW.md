# WP Command Center — Overview

## What is WP Command Center?

WP Command Center is a WordPress plugin that gives AI agents (Claude Desktop, Codex, Gemini, Cursor, and others) safe, audited, and reversible control over WordPress sites through the Model Context Protocol (MCP).

Think of it as an **operations platform** for WordPress — not just an API wrapper. Every action an AI agent takes goes through the same pipeline:

```
Inspect → Recommend → Approve → Queue → Execute → Verify → Audit → Rollback
```

## Core Philosophy

### 1. Human-in-the-Loop
AI agents can inspect, recommend, and propose — but destructive operations require human approval. No operation that modifies your site executes without your consent.

### 2. Always Audited
Every action — from reading a file to applying a patch — is logged with timestamps, actor identity, and context. You always know who did what and when.

### 3. Always Reversible
Before any file change, a snapshot is taken. Before any option change, the previous value is saved. If something goes wrong, you can roll back with one click.

### 4. Vendor-Neutral
Claude Desktop, Codex, Gemini, Cursor, Continue, OpenCode, Aider, Roo Code, Windsurf — they all connect through the same MCP endpoint. No vendor-specific runtimes. No special privileges.

### 5. Capability-Based Security
Every tool requires a specific capability assigned to the API token. Read operations need read tokens. Write operations need write tokens with the right capabilities. You control exactly what each AI client can do.

## The Runtime Lifecycle

### Session → Task → Action → Plan → Patch → Rollback

1. **Session** — An AI agent opens a session (e.g., "Claude — Site Maintenance")
2. **Task** — A specific task is created (e.g., "Update WooCommerce")
3. **Action** — The agent records what it intends to do (e.g., "Investigate: check plugin versions")
4. **Plan** — A structured plan with ordered steps is created
5. **Approval** — A human reviews and approves the plan
6. **Execution** — Operations are queued and executed
7. **Verification** — Post-execution health checks
8. **Audit** — Everything is logged
9. **Rollback** — If needed, everything can be reversed

## Platform Capabilities

| Area | Operations |
|---|---|
| Content | Create, read, update, delete, publish, schedule posts and pages |
| Plugins | List, install, activate, deactivate, update, delete plugins |
| Themes | List, install, activate, update, delete themes |
| Options | Read and update registered WordPress options |
| Database | Read-only inspection: health, size, tables, indexes, orphans |
| Snapshots | Create, list, verify, restore file snapshots |
| WP-CLI | Structured, safe WP-CLI commands with approval gating |
| Search & Replace | Safe database search/replace with dry-run preview |
| Media | Import images to the Media Library |
| Updates | Safe plugin/theme updates with health verification |
| Capabilities | Manage which tokens can access which operations |

## Quick Architecture

```
AI Client (Claude, Codex, Gemini...)
  ↓ MCP (JSON-RPC 2.0)
WP Command Center
  ↓
Capability Check → Approval Check → Queue → Execute → Verify → Audit → Rollback
  ↓
WordPress (via native APIs)
```

## Who Is This For?

- **Agencies** managing multiple client sites with AI assistance
- **Enterprise** WordPress deployments requiring audited automation
- **Developers** who want to build AI-powered WordPress tooling
- **MCP client vendors** who want to support WordPress operations
- **Site owners** who want AI help without giving up control

## Next Steps

1. [Installation Guide](INSTALLATION.md)
2. [Quick Start](QUICKSTART.md)
3. [Security Model](../architecture/SECURITY.md)
4. [MCP Integration Guide](../architecture/MCP.md)
5. [API Reference](../architecture/API.md)
