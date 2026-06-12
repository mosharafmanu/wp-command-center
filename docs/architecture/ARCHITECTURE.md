# WP Command Center — Architecture Guide

**Version:** 0.1.0 · **DB Schema Version:** 2.2.0 · **REST Namespace:** `wp-command-center/v1`

---

## 1. Runtime Hierarchy

```
Session → Task → Action → Plan → Plan Steps
                └──► Plan ──► Patch (file changes)
```

### 1.1 Sessions (`wpcc_agent_sessions`)

A session represents one block of agent work. Every other record hangs off a session.

| Field | Description |
|---|---|
| `session_id` | UUID v4 |
| `source` | `claude`, `codex`, `gpt`, `api`, `manual` |
| `label` | Human-readable label |
| `status` | `active` → `closed` / `expired` |
| `expires_at` | Lazy 24-hour expiry, checked on read/list/close |

**Defined in:** `AiAgent/RestApi.php` (constants `SESSION_STATUS_ACTIVE`, `SESSION_STATUS_CLOSED`, `SESSION_STATUS_EXPIRED`)

### 1.2 Tasks (`wpcc_agent_tasks`)

A task belongs to a session and records the user prompt.

| Field | Description |
|---|---|
| `task_id` | UUID v4 |
| `session_id` | FK to `wpcc_agent_sessions` |
| `user_prompt` | What was asked |
| `status` | `draft` → `analyzing` → `patch_proposed` → `completed` / `failed` / `cancelled` |

**Defined in:** `AiAgent/RestApi.php` (constants `TASK_STATUS_DRAFT`, array `TASK_STATUSES`)

### 1.3 Actions (`wpcc_agent_actions`)

A lightweight metadata-only record of something the agent investigated, found, or proposed.

| Field | Description |
|---|---|
| `action_id` | UUID v4 |
| `session_id`, `task_id` | FKs |
| `type` | `investigate`, `recommendation`, `diagnosis`, `code_change`, `configuration_change`, `maintenance` |
| `status` | `proposed` → `accepted` / `rejected` / `cancelled` / `completed` |

**Table:** `wpcc_agent_actions`

### 1.4 Plans (`wpcc_agent_plans` + `wpcc_agent_plan_steps`)

A plan is a titled objective with an ordered list of steps, optionally linked to an Action.

| Table | Purpose |
|---|---|
| `wpcc_agent_plans` | `plan_id`, `title`, `objective`, `status`, FKs |
| `wpcc_agent_plan_steps` | `plan_id`, `step_order`, `title`, `description`, `status` |

**Statuses:** `draft` → `pending_review` → `approved` / `rejected` / `cancelled` (plus `superseded`, defined but currently unused — reserved for future use).

**Defined in:** `AiAgent/RestApi.php` (constants `PLAN_STATUS_DRAFT`, `PLAN_STATUS_PENDING_REVIEW`, `PLAN_STATUS_APPROVED`, `PLAN_STATUS_REJECTED`, `PLAN_STATUS_SUPERSEDED`, `PLAN_STATUS_CANCELLED`)

### 1.5 Patch (`wpcc_patches`)

File changes proposed by an AI agent. Stored as full JSON records under `wp-content/uploads/wpcc-patches/{uuid}.json` (source of truth) with a lightweight index row in `wpcc_patches` for fast listing.

**Status machine:**
```
draft / pending_approval → approved → applied → rolled_back
        │                    │
        └──► rejected        └──► failed (auto-revert)
```

**Risk levels:** `low`, `medium`, `high`

**Sources:** `claude`, `codex`, `manual`, `api`

**Defined in:** `PatchSystem/PatchManager.php` — all status/risk/source constants

### 1.6 Rollback

Per-patch rollback via file snapshots. See §7 below.

---

## 2. Agent Runtime

The agent runtime stores the metadata hierarchy (Sessions, Tasks, Actions, Plans) in MySQL tables. All tables are created by `Core/Schema.php` using `dbDelta()`. The REST API in `AiAgent/RestApi.php` (3,698 lines, 58 routes) exposes CRUD and status-transition endpoints for every runtime entity.

### 2.1 Database Tables

| Table | Key columns |
|---|---|
| `wpcc_agent_sessions` | `session_id`, `source`, `label`, `status`, `created_at`, `expires_at` |
| `wpcc_agent_tasks` | `task_id`, `session_id`, `source`, `user_prompt`, `status` |
| `wpcc_agent_actions` | `action_id`, `session_id`, `task_id`, `type`, `title`, `status` |
| `wpcc_agent_plans` | `plan_id`, `session_id`, `task_id`, `action_id`, `title`, `objective`, `status` |
| `wpcc_agent_plan_steps` | `plan_id`, `step_order`, `title`, `description`, `status` |

### 2.2 Statuses Reference

| Entity | Valid statuses |
|---|---|
| Session | `active`, `closed`, `expired` |
| Task | `draft`, `analyzing`, `patch_proposed`, `completed`, `failed`, `cancelled` |
| Action | `proposed`, `accepted`, `rejected`, `cancelled`, `completed` |
| Plan | `draft`, `pending_review`, `approved`, `rejected`, `superseded`, `cancelled` |
| Plan Step | Per-step status (`pending`, `completed`, `skipped`) |
| Patch | `draft`, `pending_approval`, `approved`, `rejected`, `applied`, `failed`, `rolled_back` |

### 2.3 REST API Gateway

Base URL: `https://yoursite.com/wp-json/wp-command-center/v1/`

Auth: Every request requires `Authorization: Bearer <token>`. Tokens are validated by `Security/AuthTokens.php` with constant-time hash comparison.

The gateway is implemented by `AiAgent/RestApi.php` which is initialized in `Core/Plugin::run()`. It always runs (not just in wp-admin) because AI agents call the REST API directly.

---

## 3. Operation Runtime

### 3.1 Operation Registry

`Operations/OperationRegistry.php` — defines 15 operation families as a discoverable array of metadata (never executes). Each operation has:

- `id` — unique operation identifier
- `title`, `description` — human-readable
- `risk_level` — `low`, `medium`, `high`, `variable`
- `requires_approval` — boolean
- `parameters` — typed parameter definitions (name, type, enum, required, default, min/max, description)
- `available` — runtime check (e.g., whether WooCommerce/ACF/CF7 is active, or whether WP-CLI is reachable)

**15 Operation Families:**

| ID | Title | Risk | Approval | Availability |
|---|---|---|---|---|
| `content_seed` | Content Seeding | medium | yes | Always |
| `acf_seed` | Seed ACF Fields | medium | yes | ACF active |
| `cf7_seed` | CF7 Seeding | low | yes | CF7 active |
| `woo_product_seed` | WooCommerce Product Seeder | medium | yes | WooCommerce active |
| `safe_search_replace` | Safe Search & Replace | high | yes | Always |
| `media_import` | Media Library Import | medium | yes | Always |
| `safe_updates` | Safe WordPress Updates | high | yes | Always |
| `capability_manage` | Capability Management | variable | yes | Always |
| `database_inspect` | Database Inspection | low | no | Always |
| `content_manage` | Content Management | variable | yes | Always |
| `snapshot_manage` | Snapshot Management | variable | yes | Always |
| `theme_manage` | Theme Management | variable | yes | Always |
| `plugin_manage` | Plugin Management | variable | yes | Always |
| `option_manage` | Option Management | variable | yes | Always |
| `wp_cli_bridge` | WP-CLI Bridge | variable | yes | `shell_exec` + `proc_open` + WP-CLI all present |

### 3.2 Operation Executor

`Operations/OperationExecutor.php` — the unified dispatch engine. On `run($operation_id, $payload, $context)`:

1. **Validation** — Operation exists in registry.
2. **Capability gate** — If `wpcc_enforce_capabilities` is enabled (default: `true`), validates the token has the required capability via `CapabilityRegistry::validate()`.
3. **Approval gate** — If `wpcc_enforce_approval` is enabled (default: `false`), blocks mutation ops that require approval unless arriving via the request/approval workflow path (has `queue_id` or `request_id` in context).
4. **Availability check** — Operation must be available in current environment.
5. **Dispatch** — Resolves the correct handler class via `resolve_handler()` switch statement (15 cases, one per operation family).
6. **Audit** — Records `operation.{id}.started`, `operation.execution.started` before; `operation.{id}.completed/failed`, `operation.execution.completed/failed`, `operation.result.completed/failed` after.
7. **Results persistence** — Writes to `wpcc_operation_results` via `OperationResults::create()`.

**Handler classes resolved by `resolve_handler()`:**

| Operation ID | Handler Class |
|---|---|
| `content_seed` | `ContentSeed` |
| `acf_seed` | `AcfSeed` |
| `cf7_seed` | `Cf7Seed` |
| `woo_product_seed` | `WooProductSeed` |
| `safe_search_replace` | `SearchReplace` |
| `media_import` | `MediaImport` |
| `safe_updates` | `SafeUpdates` |
| `wp_cli_bridge` | `WpCliBridge` |
| `theme_manage` | `ThemeManager` |
| `snapshot_manage` | `SnapshotManager` |
| `content_manage` | `ContentManager` |
| `database_inspect` | `DatabaseInspector` |
| `capability_manage` | `CapabilityManager` |
| `plugin_manage` | `PluginManager` |
| `option_manage` | `OptionManager` |

### 3.3 Operation Manager

`Operations/OperationManager.php` — the request/approval layer. Manages the lifecycle:

| Status | Meaning |
|---|---|
| `pending_review` | Created, awaiting human review |
| `approved` | Human approved; auto-enqueued |
| `rejected` | Declined |
| `executed` | Ran successfully |
| `failed` | Execution failed |
| `cancelled` | Cancelled before execution |

`approve_request()` automatically calls `OperationQueue::enqueue()` to queue the approved request for background execution. Stored in `wpcc_operation_requests` table.

---

## 4. Queue System

### 4.1 Operation Queue

`Operations/OperationQueue.php` — manages the lifecycle of queued jobs. Five statuses:

| Constant | Value | Description |
|---|---|---|
| `STATUS_QUEUED` | `queued` | Awaiting execution |
| `STATUS_RUNNING` | `running` | Currently being processed |
| `STATUS_COMPLETED` | `completed` | Finished successfully |
| `STATUS_FAILED` | `failed` | Attempted, but failed |
| `STATUS_CANCELLED` | `cancelled` | Manually cancelled |

**Table:** `wpcc_operation_queue` — fields: `queue_id`, `request_id`, `operation_id`, `status`, `priority`, `attempts`, `max_attempts` (default 3), `payload`, `result`, `error_message`, `created_at`, `started_at`, `completed_at`, `failed_at`.

**Key methods:**
- `enqueue($request_id, $priority, $context)` — Only approved requests can be queued. If an existing queued/running item exists for the same request, returns it (no duplicates).
- `run_item($queue_id, $context)` — Only `queued` or `failed` items can run. Transitions to `running`, increments `attempts`, runs via `OperationExecutor`, sets `completed`/`failed` on result. Syncs plan status via `RecommendationEngine::sync_plan_status()`.
- `cancel_item($queue_id)` — Only `queued` or `failed` items can be cancelled.
- `retry_item($queue_id)` — Only `failed` items can be retried, respecting `max_attempts`. Resets status to `queued`.

### 4.2 Operation Worker

`Operations/OperationWorker.php` — background processor using WP-Cron.

- **Cron hook:** `wpcc_process_operation_queue` — runs every ~5 minutes (custom `wpcc_five_minutes` schedule defined in `Core/Plugin.php`).
- **Process method:** `process($limit = 5, $context)` — batch limit 1–20 (default 5), processes queued items.
- **Transient locking:** Before running each item, acquires a transient lock (`wpcc_queue_lock_{queue_id}`) with 5-minute expiry via `set_transient()`. If lock cannot be acquired (another cron run is processing it), skips with an `operation.worker.locked` audit event. Releases the lock after processing via `delete_transient()`.
- **Stats:** `get_stats()` returns `pending_queue_count`, `running_queue_count`, `failed_queue_count`, and `queue_worker_status` (`active`/`inactive` based on `wp_next_scheduled()`).

---

## 5. Results Store

### 5.1 Operation Results

`Operations/OperationResults.php` — persistent storage for execution outcomes.

**Table:** `wpcc_operation_results`

| Column | Description |
|---|---|
| `result_id` | UUID v4 |
| `queue_id` | FK to queue item (nullable) |
| `request_id` | FK to request (nullable) |
| `operation_id` | Operation identifier |
| `status` | `completed` or `failed` |
| `execution_time_ms` | Duration in milliseconds |
| `created_count` | Number of items created |
| `updated_count` | Number of items updated |
| `skipped_count` | Number of items skipped |
| `error_count` | Number of errors |
| `result_json` | Full normalized result |
| `error_json` | Error details (code + message) |
| `started_at`, `completed_at`, `created_at` | Timestamps |

**Key methods:**
- `create($data)` — Inserts a result record, fires `operation.result.created` audit event.
- `get_result($result_id)` — Single result lookup.
- `list_results($filters)` — Filterable (by `operation_id`, `queue_id`, `request_id`, `status`), paginated (default limit 50).

Results are created by `OperationExecutor::run()` for both successes and failures, with the full normalized result shape (`operation_id`, `success`, `result`, `errors`, `created`, `updated`, `skipped`).

---

## 6. Timeline

### 6.1 Timeline Builder

`AiAgent/TimelineBuilder.php` — aggregates lifecycle events from two sources into a unified, filterable timeline:

1. **Audit Log** — Reads up to 2,000 entries from `AuditLog::tail()` and normalizes them via a comprehensive action-to-label mapping (100+ mapped audit actions).
2. **DB baseline events** — Queries `wpcc_agent_sessions`, `wpcc_agent_tasks`, and `wpcc_patches` directly to catch legacy records created before logging was active.

**Event structure:**
```php
[
  'timestamp'  => int,
  'type'       => string,     // Top-level domain: session, task, action, plan, patch, operation, etc.
  'label'      => string,     // Human-readable label
  'status'     => string,     // Associated status
  'actor'      => ?array,     // Who did it
  'session_id' => ?string,
  'task_id'    => ?string,
  'action_id'  => ?string,
  'plan_id'    => ?string,
  'patch_id'   => ?string,
  'summary'    => string,     // Context-rich summary, secrets redacted
]
```

**Processing pipeline:**
1. Gather audit log entries + DB baseline events.
2. Apply filters (`session_id`, `task_id`, `action_id`, `plan_id`, `patch_id`).
3. Sort newest first.
4. Redact summaries via `Redactor::redact()`.
5. Paginate (default limit 100, offset-based).

**Duplicate detection:** Events of the same type/label/entity within a 5-second window are deduplicated (compensates for `time()` drift between audit log and DB sources).

---

## 7. Audit

### 7.1 Audit Log

`Security/AuditLog.php` — append-only JSONL audit trail.

- **Storage:** `wp-content/uploads/wpcc-audit/audit.log` (newline-delimited JSON)
- **Directory protection:** `.htaccess` with `Require all denied` / `Deny from all`, plus `index.php` silent file
- **Record format:** `{ "timestamp": int, "action": string, "context": { ... } }`
- **Actor resolution:** `resolve_actor()` returns `{ type: 'admin', user_id }`, `{ type: 'token', id, label }`, or `{ type: 'unknown' }`
- **Tail:** `tail($limit = 200)` reads the last N entries, newest first
- **Fail-silent:** If the directory is unwritable, `record()` silently returns — auditing must never break the operation it's recording

**Audit events cover:** session/task/action/plan/patch lifecycle transitions, operation started/completed/failed, queue events, worker events, health verification, security blocks/redactions, capability denials, MCP requests/denials, system cleanup/environment changes, recommendation lifecycle.

---

## 8. Rollback

### 8.1 Per-Manager Option-Based Rollback

Each operational manager class implements its own rollback pattern:

- **`PluginManager`** — Before install/update/delete, snapshots the plugin directory. Rollback restores from snapshot, using `Rollback/RollbackManager.php` for verified restore.
- **`ThemeManager`** — Same pattern: snapshot before mutation, restore on rollback.
- **`OptionManager`** — Before update, saves the previous value. Rollback restores the previous value. Audit trail records `option.update.rolled_back`.

### 8.2 File-Based Snapshots (Patch Pipeline)

`Rollback/SnapshotManager.php` — creates byte-for-byte file backups.

- **Storage:** `wp-content/uploads/wpcc-snapshots/{uuid}.snapshot`
- **Index table:** `wpcc_snapshots` — `snapshot_id`, `patch_id`, `file_path`, `backup_path`, `label`, `size` (bytes), `hash` (MD5)
- **Path security:** All snapshots go through `PathGuard::resolve()` — only allowed files under `themes/`, `plugins/`, `mu-plugins/`

`Rollback/RollbackManager.php` — three-stage verified rollback:

1. **Pre-check (snapshot integrity):** Read stored snapshot, hash it, compare to recorded hash. If mismatch → abort, don't touch live file.
2. **Safety backup:** Take a new snapshot of current file contents before restoring (so the rollback itself can be undone).
3. **Post-check (restore verification):** Write snapshot contents to live file (atomic, `LOCK_EX`), read it back, hash it, confirm match.

Result includes `verified` (true only if both checks pass), individual `checks`, and the `safety_snapshot`.

---

## 9. MCP Runtime

### 9.1 McpServerRuntime

`Mcp/McpServerRuntime.php` — standards-based MCP adapter implementing JSON-RPC 2.0. Thin wrapper — all logic delegates to existing components.

**Protocol:** JSON-RPC 2.0 with MCP protocol version `2024-11-05`.

**REST endpoint:** `POST /wp-json/wp-command-center/v1/mcp` — registered by `Mcp/McpRestApi.php`. Auth via `Authorization: Bearer <token>` header. Permission callback validates token and requires at minimum read scope.

**Message dispatch (`handle()`):**
```
initialize → capabilities + serverInfo
resources/list → 7 resource URIs
resources/read → delegates to REST API / CapabilityRegistry / OperationRegistry / OperationQueue / OperationResults / RecommendationEngine
tools/list → all 15 operations from OperationRegistry, with JSON Schema parameter definitions
tools/call → CapabilityRegistry::validate() → OperationExecutor::run() → Redactor::redact_recursive()
prompts/list → 6 informational prompts
default → error -32601 "Method not found"
```

All responses are redacted via `Redactor::redact_recursive()` before return.

**7 Resources:**

| URI | Name | Source |
|---|---|---|
| `wpcc://manifest` | Agent Manifest | `GET /agent/manifest` (REST) |
| `wpcc://context` | Agent Context | `GET /agent/context` (REST) |
| `wpcc://capabilities` | Capabilities | `CapabilityRegistry::get_summary()` |
| `wpcc://operations` | Operations | `OperationRegistry::get_operations()` |
| `wpcc://queue` | Queue Status | `OperationQueue` counts by status |
| `wpcc://results` | Results | `OperationResults::list_results(10)` |
| `wpcc://recommendations` | Recommendations | `RecommendationEngine::list(status: open, 20)` |

**15 Tools:** All 15 operation families from `OperationRegistry` exposed as MCP tools with JSON Schema input schemas. Parameter types are mapped: `integer` → `number`, `string`/`array`/`object`/`boolean` → MCP-native types. Required parameters are listed in the schema's `required` array.

**6 Prompts (informational):**
- `inspect_site` — Inspect site health and configuration
- `manage_content` — Manage WordPress content safely
- `manage_plugins` — Manage WordPress plugins safely
- `manage_themes` — Manage WordPress themes safely
- `manage_options` — Manage WordPress options safely
- `inspect_database` — Inspect database health

---

## 10. AI Client Layer

### 10.1 AIClientRegistry

`Integration/AIClientRegistry.php` — single source of truth for all supported AI clients. All clients connect through the existing MCP Server Runtime — no per-client runtimes.

**9 Registered Clients:**

| ID | Name | Type | Vendor | Status |
|---|---|---|---|---|
| `claude` | Claude Desktop | desktop | Anthropic | **active** |
| `codex` | Codex | desktop | OpenAI | planned |
| `gemini` | Gemini | desktop | Google | planned |
| `cursor` | Cursor | ide | Anysphere | planned |
| `continue` | Continue | ide_plugin | Continue Dev | planned |
| `opencode` | OpenCode | cli | Anomaly | planned |
| `aider` | Aider | cli | Aider AI | planned |
| `roo_code` | Roo Code | ide_plugin | Roo | planned |
| `windsurf` | Windsurf | ide | Codeium | planned |

Each client definition includes: metadata (name, vendor, type, description, website), status, compatibility flags, config generator/function references, per-OS config paths, and MCP support flags.

**Key methods:**
- `get_clients()` — All 9 clients
- `get_active_clients()` — Only `active`-status clients (currently: Claude Desktop)
- `get_client($client_id)` — Single client lookup
- `generate_config($client_id)` — Call the client's config generator function
- `get_discovery($client_id)` — Call the client's discovery metadata function
- `get_counts()` — `total`, `active`, `configured`, `connected`, `planned`
- `get_compatibility_matrix()` — Array of client compatibility data for admin display

---

## 11. Storage Architecture

The plugin separates **queryable indexes** from **bulk content:**

| What | Where | Why |
|---|---|---|
| Runtime metadata (sessions, tasks, actions, plans, patches, snapshots, requests, queue, results, recommendations, health) | MySQL tables (all `wpcc_*` prefixed) | Fast filtering/listing, joins across hierarchy |
| Patch content (diffs, file contents, status history, verification) | JSON files in `wp-content/uploads/wpcc-patches/` | Large/variable-size data doesn't bloat DB |
| File snapshots | Raw `.snapshot` files in `wp-content/uploads/wpcc-snapshots/` | Byte-for-byte restore source |
| API tokens | `wp-content/uploads/wpcc-tokens/manifest.json` | Outside DB, protected directory |
| Audit log | `wp-content/uploads/wpcc-audit/audit.log` (JSONL) | Append-only, tamper-evident |

All `wpcc-*` upload directories are protected with `.htaccess` (`Require all denied` / `Deny from all`) and `index.php` (`<?php // Silence is golden.`).

---

## 12. Module Map

| Directory | Responsibility |
|---|---|
| `includes/Core/` | Bootstrap (`Plugin.php`), autoloader, activation/deactivation, database schema |
| `includes/Security/` | API tokens, path allow/deny rules, audit log, secret redaction, WordPress capability checks |
| `includes/AiAgent/` | REST API gateway (58 routes), file access, code search, context bundling, timeline |
| `includes/SiteIntelligence/` | Site scanner — raw facts about the install (1-hour cached) |
| `includes/Diagnostics/` | Performance/Security/WooCommerce diagnostics, debug log viewer |
| `includes/Recommendations/` | Deterministic recommendation engine |
| `includes/PatchSystem/` | Patch state machine, diff generation, approve/apply/rollback |
| `includes/Rollback/` | Snapshot creation and verified restore |
| `includes/Operations/` | 15 operation families, registry, executor, request/queue/worker/results pipeline |
| `includes/Health/` | Health Verification Engine (7 checks) |
| `includes/System/` | Environment mode (development/staging/production), guarded data cleanup |
| `includes/Admin/` | wp-admin UI: menu, assets, 7 view pages |
| `includes/Mcp/` | MCP JSON-RPC 2.0 server runtime and REST endpoint |
| `includes/Integration/` | AI Client Registry (9 clients), Claude integration |
