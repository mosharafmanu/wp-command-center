# STEP 84 — Destructive Operation Guardrails

## Context

Claude Desktop refused to delete the Query Monitor plugin through WP Command
Center because `plugin_delete` is permanent and, at the time, exposed no
`rollback_id` or recovery guarantee. The refusal was correct safety behaviour,
but it exposed a product gap: WPCC had no first-class workflow for irreversible
operations. A permanent delete was treated like any other write — gated only by
the Security Mode approval flow, and in Developer Mode not gated at all.

This step adds a **product-level destructive-operation workflow**: a central
classifier, a mandatory confirmation handshake that fires in *every* security
mode, pre-delete backups where possible, post-delete verification, structured
responses, and an audit trail of the full lifecycle.

## Goal

Make destructive operations safe and self-describing: an AI agent (or admin)
can never silently destroy data. Every destructive call must be explicitly
confirmed with a reason and a target, is backed up where technically possible,
and returns a structured response the AI can reason about.

---

## Design

### 1. Central classifier — `DestructiveGuard`

New class `includes/Operations/DestructiveGuard.php`. It is pure (no side
effects) and answers two questions:

- `classify( $operation_id, $payload ): ?array` — returns a **descriptor**
  when the (operation, payload) pair is destructive, else `null`.
- `missing_confirmation( $descriptor, $payload ): string[]` — returns the
  confirmation requirements not yet satisfied.

A descriptor is:

```php
[
    'phrase'         => 'DELETE_PLUGIN',   // confirmation phrase to echo back
    'target_key'     => 'slug',            // payload key naming the target
    'backup_capable' => true,              // can a pre-delete backup be taken?
    'warning'        => 'Permanently deletes the plugin files from disk…',
]
```

#### Classification matrix

| Operation / action                       | Destructive when…           | Phrase               | Target       | Backup |
|------------------------------------------|-----------------------------|----------------------|--------------|--------|
| `plugin_manage` / `plugin_delete`        | always                      | `DELETE_PLUGIN`      | `slug`       | yes (folder zip) |
| `theme_manage` / `theme_delete`          | always                      | `DELETE_THEME`       | `slug`       | no¹    |
| `user_manage` / `user_delete`            | always                      | `DELETE_USER`        | `user_id`    | no²    |
| `media_manage` / `media_delete`          | `force` truthy (permanent)  | `DELETE_MEDIA`       | `media_id`   | no²    |
| `content_manage` / `content_delete`      | `force`/`permanent` truthy³ | `DELETE_CONTENT`     | `content_id` | no²    |
| `safe_search_replace`                    | `dry_run` **explicitly** false⁴ | `RUN_DESTRUCTIVE_DB` | `search`     | no     |

¹ Theme folder backup is a natural follow-up but not wired in this step.
² These targets are recoverable through their existing manager rollback paths or
WordPress trash, so `rollback_available` is reported `false` for the *permanent*
variant (no automatic snapshot) and the warning makes the irreversibility clear.
³ `content_delete` is **trash-only (recoverable) today**; the guard only engages
if a caller requests a `force`/`permanent` variant, so it is future-proofed for
when permanent content deletion is added.
⁴ `SearchReplace` defaults `dry_run` to `true`, so a **missing** `dry_run` is a
safe dry run and is *not* gated — only an explicit `dry_run: false` is. This keeps
the guard's default aligned with the handler's.

Every destructive call is reported at `risk_level: critical`
(`DestructiveGuard::RISK_LEVEL`).

### 2. Enforcement point — `OperationExecutor::run()`

The guard runs as **step 1c, before the Security Mode approval gate (1d)**, on
the fresh-call path only (`! queue_id && ! request_id`). This ordering is the
core design decision:

```
capability check (1b)
  → destructive confirmation gate (1c)   ← STEP 84, all modes
     → security mode approval gate (1d)  ← STEP 80
        → handler dispatch (3)
```

- If the call is destructive and confirmation is incomplete →
  `confirmation_required` structured response (success=true, so the AI gets an
  actionable instruction rather than a `-32000` error). Nothing is queued.
- If confirmation is complete → audit `operation.destructive.confirmed`, attach
  the descriptor to `$context['destructive']`, and fall through to the approval
  gate.

Consequences by mode:

- **Developer Mode** — confirmation is still required (no silent deletes), then
  the operation executes immediately.
- **Client / Enterprise Mode** — confirmation is required first, *then* the
  request routes through the approval workflow. Because confirmation is captured
  up front, the stored request payload carries the `reason` and target, so the
  human approver sees *why* and *what* on the approval card.
- **Approved/queued runs** carry the confirmation in their stored payload and
  skip the gate (no double-prompt).

### 3. Confirmation handshake

A destructive call must include all of:

| Parameter             | Requirement                                            |
|-----------------------|--------------------------------------------------------|
| `confirm`             | truthy (`true`, `"1"`, `"yes"`, `"on"`)                |
| `confirmation_phrase` | exact match to the descriptor phrase (`hash_equals`)   |
| `reason`              | non-empty                                              |
| `<target_key>`        | non-empty (e.g. `slug` for plugins)                    |

### 4. `plugin_delete` — full worked example

`PluginManager::plugin_delete()` now:

1. Rejects active plugins (`wpcc_plugin_delete_active`) — unchanged guard, only
   inactive plugins can be deleted.
2. Loads `wp-admin/includes/file.php` and initialises `WP_Filesystem()` —
   required for `delete_plugins()` in a REST/MCP request (previously missing;
   real deletes fatally errored before this step).
3. **Creates a pre-delete backup**: zips the plugin folder into the protected
   `uploads/wpcc-plugin-backups/` directory, recording metadata (id, slug,
   archive, size, md5, timestamp, session/task) in the `wpcc_plugin_backups`
   option. Best-effort: if `ZipArchive` is unavailable it sets
   `backup_available=false` with a note and does **not** block the delete.
4. Stores a rollback record (now including the `backup_id`).
5. Deletes via `delete_plugins()`.
6. **Verifies removal**: clears the `plugins` object-cache group and re-scans
   disk → `verified_removed`.
7. Audits every step.

### 5. Structured responses

`confirmation_required` (gate, before execution):

```json
{
  "status": "confirmation_required",
  "operation": "plugin_manage",
  "action": "plugin_delete",
  "destructive": true,
  "risk_level": "critical",
  "rollback_available": true,
  "confirmation_required": true,
  "confirmation_phrase": "DELETE_PLUGIN",
  "target_parameter": "slug",
  "missing": ["confirm", "confirmation_phrase", "reason"],
  "warning": "Permanently deletes the plugin files from disk…",
  "required_parameters": {
    "confirm": true,
    "confirmation_phrase": "DELETE_PLUGIN",
    "reason": "a human-readable reason for the deletion",
    "slug": "the identifier of the target to delete"
  },
  "message": "This is a CRITICAL destructive operation. To proceed, resend …"
}
```

Successful `plugin_delete`:

```json
{
  "action": "plugin_delete",
  "slug": "query-monitor",
  "deleted": true,
  "verified_removed": true,
  "destructive": true,
  "risk_level": "critical",
  "rollback_available": true,
  "backup_id": "daa5366c-…",
  "snapshot_id": "daa5366c-…",
  "rollback_id": "…",
  "health_check": "passed"
}
```

`pending_approval` (Client/Enterprise) gains `destructive: true` and `warning`,
and `rollback_available` reflects the descriptor's `backup_capable`.

### 6. Approval card warning (Client/Enterprise UI)

`AdminRestApi::format_request()` runs `DestructiveGuard::classify()` on the
stored payload and adds `destructive` + `destructive_warning` to each pending
request. `includes/Admin/views/approvals.php` renders a red **“⚠ DESTRUCTIVE —
this permanently deletes data and cannot be undone”** banner above the request
metadata when `destructive` is true.

### 7. Discoverability

`plugin_manage` and `theme_manage` operation definitions in `OperationRegistry`
now advertise the `confirm`, `confirmation_phrase`, and `reason` parameters and
describe the destructive contract in their `description`, so the MCP tool schema
tells the AI exactly how to delete safely. `theme_delete` risk was raised from
`high` to `critical` to match `plugin_delete`.

---

## Files changed

| File | Change |
|------|--------|
| `includes/Operations/DestructiveGuard.php` | **New.** Central classifier + confirmation checker. |
| `includes/Operations/OperationExecutor.php` | Destructive gate (1c) before approval gate; `confirmation_required()`; destructive metadata on `pending_approval()`. |
| `includes/Operations/PluginManager.php` | Pre-delete folder backup, `WP_Filesystem()` init, removal verification, destructive response fields, `destructive_reason` in audit. |
| `includes/Operations/OperationRegistry.php` | `confirm`/`confirmation_phrase`/`reason` params on `plugin_manage` & `theme_manage`; `theme_delete` → critical; updated descriptions. |
| `includes/Admin/AdminRestApi.php` | `destructive` + `destructive_warning` on pending-approval payloads. |
| `includes/Admin/views/approvals.php` | Destructive warning banner on the approval card. |
| `tests/test-destructive-guardrails.sh` | **New.** 21 assertions. |
| `tests/test-plugin-runtime.sh` | Delete-validation calls now supply confirmation to reach the existing guards. |
| `tests/test-security-modes.sh`, `tests/test-security-mode-validation.sh` | `plugin_delete` gating calls now supply confirmation + `slug`. |

## New storage

- Option `wpcc_plugin_backups` — keyed by backup id, metadata for each pre-delete
  plugin archive.
- Directory `uploads/wpcc-plugin-backups/` — protected (`.htaccess` deny +
  `index.php`), holds `{uuid}.zip` archives.

## New audit events

- `operation.destructive.confirmation_required`
- `operation.destructive.confirmed`
- `plugin.delete.backup`
- `plugin.delete.started` / `plugin.delete` now include `reason` and
  `verified_removed`.

## Tests

`tests/test-destructive-guardrails.sh` — **21/21 PASS**:

1. `plugin_delete` without confirmation → `confirmation_required`, `destructive:true`, `risk_level:critical`, phrase advertised, `confirm` listed missing.
2. Wrong confirmation phrase → still blocked, `confirmation_phrase` listed missing.
3. Active plugin delete (with full confirmation) → `wpcc_plugin_delete_active`.
4. Inactive plugin delete with confirmation → `deleted:true`, `verified_removed:true`, `destructive:true`, `rollback_available:true`, `backup_id`/`snapshot_id` present.
5. Backup archive physically written to `uploads/wpcc-plugin-backups/`; plugin folder gone from disk.
6. Audit trail records `operation.destructive.confirmed`, `plugin.delete.backup`, `plugin.delete`.

Updated suites pass: `test-plugin-runtime` (58/58), `test-security-modes`
(28/28), `test-security-mode-validation` (27/27), `test-approval-enforcement`
(16/16), `test-mcp-approval-runtime` (25/25).

## Known boundary

The guard lives in `OperationExecutor::run()`, so it covers the **direct run** path
and the **MCP/AI auto-approval** path. The explicit manual `/operations/requests`
create → approve → queue path does **not** run the executor at create time, so a
destructive request can be *created* there without confirmation — but it still
requires an explicit human approval before it executes, which is that path's
existing safeguard. Adding confirmation validation to `OperationManager::create_request`
is a possible future hardening.

## Constraints preserved

- Only inactive plugins/themes can be deleted.
- Human approval still mandatory in Client/Enterprise mode (and now preceded by
  destructive confirmation).
- Backups are best-effort and never block a delete when unavailable, but the
  response truthfully reports `rollback_available`.
- The guard is pure: no execution, no writes, no audit inside `DestructiveGuard`.

## Follow-ups (not in this step)

- Wire folder backups for `theme_delete` (descriptor already `backup_capable`-ready).
- A `plugin_restore` action that unzips a `wpcc_plugin_backups` archive.
- A backup retention/cleanup cron for `uploads/wpcc-plugin-backups/`.
- Extend permanent-variant handling to `content_delete` once a permanent content
  delete is offered.
