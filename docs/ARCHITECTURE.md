# Architecture

```
  AI client (Claude Desktop, Cursor, …)
        │  stdio (JSON-RPC)
        ▼
  wpcc-mcp-relay.mjs                     ← shipped in the plugin, run by the client
        │  HTTPS + bearer token
        ▼
  POST /wp-json/wp-command-center/v1/mcp ← McpServerRuntime
        │
        ├─ scope check            (read-only tokens stop here)
        ├─ capability check       (23 capabilities)
        ▼
  OperationExecutor                      ← the single gate every change passes through
        ├─ parameter normalisation + validation  (pre-approval)
        ├─ risk assessment → SecurityModeManager
        │     └─ needs approval? → wpcc_operation_requests → queue → worker
        ├─ destructive guard
        ├─ dispatch → one of 42 runtimes
        ├─ ChangeRecorder  → wpcc_change_log
        └─ AuditLog        → uploads/wpcc-audit/audit.log
```

---

## Components

### The relay (`sdk/javascript/wpcc-mcp-relay.mjs`)

MCP clients speak JSON-RPC over stdio; WordPress speaks HTTP. The relay bridges the two.
It is **shipped inside the plugin** and served from your own site, so a customer needs
Node but nothing from npm. The build refuses to produce a release ZIP without it.

### `McpServerRuntime`

Implements `initialize`, `tools/list`, `tools/call`, and `resources/read`. Exposes 42
tools — one per catalogue operation — plus discovery resources (`wpcc://manifest`,
`wpcc://operations`, and others). Enforces token scope before dispatch, and returns
failures as `isError` tool results carrying a structured code.

### `OperationRegistry`

The catalogue: 42 operations, each with a title, description, risk level, parameter
schema (including the `action` enum), per-action risk overrides, and an agent note.
This is what discovery serves, and it is the single source of truth for what exists.

### `OperationExecutor`

Every change goes through here, whether it arrived over MCP, REST, or the admin UI. In
order: parameter vocabulary normalisation → required-parameter and action validation →
capability check → risk assessment → approval gate → destructive guard → dispatch →
result normalisation → change record → audit record → rollback decoration.

Validation happens **before** the approval gate deliberately: a request that cannot
possibly execute must not spend a human decision.

### Runtimes

42 runtime managers, each owning one domain (content, media, users, WooCommerce, ACF,
Elementor, SEO, menus, widgets, CPTs, files, patches, snapshots, reporting, …). Each
answers an unknown action with its own self-describing error code — `wpcc_invalid_acf_action`,
`wpcc_invalid_woo_action` — listing every valid action in the message. `InvalidActionContract`
keeps the pre-approval guard speaking in the same voice.

### Governance

- `SecurityModeManager` — the three protection modes and the risk→approval table.
- `OperationManager` — request lifecycle: pending → approved/rejected → queued → executed.
- `OperationQueue` + `OperationWorker` — executes approved work on WP-Cron
  (`wpcc_process_operation_queue`, every five minutes).

### History and undo

- `ChangeRecorder` writes every attempt to `wpcc_change_log` with an honest status —
  `applied`, `rejected`, `failed`, `rolled_back` — never "applied" for something that
  did not happen.
- `RollbackDelta` implements field-scoped, drift-aware undo: it restores the fields it
  recorded, and **skips** any field changed since, rather than clobbering newer work. A
  partial undo reports `restored_fields`, `skipped_fields` and per-field `conflicts`.
- Patches use a separate engine: a per-file snapshot taken before the write, restored
  atomically on rollback.

---

## Data

15 tables created by `Schema` at activation (prefix `wpcc_`):

```
agent_actions      agent_plan_steps   agent_plans        agent_sessions
agent_tasks        change_log         health_verifications
idempotency        operation_queue    operation_requests operation_results
patches            proposals          recommendations    snapshots
```

`wpcc_telemetry` is a sixteenth table created **lazily** by `TelemetryStore` on its first
write, deliberately decoupled from `DB_VERSION`. Every read guards on its existence and
returns an honest empty result when it is absent.

**`DB_VERSION` is 2.6.0.**

Upload directories: `wpcc-tokens`, `wpcc-patches`, `wpcc-audit`, `wpcc-snapshots`,
`wpcc-media-snapshots`, `wpcc-plugin-backups` — each protected from direct web access.

---

## Multisite

Network activation is **refused**. `Activator` calls `wp_die()` with an explanation, and
an admin notice repeats it.

This is a deliberate limit rather than a bug. WPCC's governance model is per-site: tokens,
protection mode, approvals and change history all assume one site's `options` table and
one set of `wpcc_` tables. A network activation would create ambiguity about which site an
approval belongs to. Per-site activation inside a network works normally.

---

## Extension points

- `wpcc_audit_recorded` — fires after an audit record is durably written. Read-only
  observers only; subscribers must self-guard and must not affect execution. Telemetry
  uses it.
- `wpcc_alt_text_provider` — force a specific alt-text provider by id.
- `wpcc_telemetry_prices` — supply per-model prices for cost roll-ups.
- `wpcc_enforce_capabilities` (option) — capability enforcement, on by default.
- Dev-surface flags: `WPCC_PROPOSALS_DEV_UI`, `WPCC_ALT_TEXT_UI`, `WPCC_SEO_META_UI`,
  `WPCC_AI_CONTENT_UI` — constants or matching filters. All ship **off**.
