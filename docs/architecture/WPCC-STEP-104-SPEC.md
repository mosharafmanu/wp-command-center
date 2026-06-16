# STEP 104 — Change History & Rollback Runtime: Implementation Specification

> Status: specification only (no code). Authored after the STEP 103 batch was
> deployed to production at commit `2420d1a`. Preserves the core WPCC principles:
> capability-scoped, approval-aware, reversible, auditable, rollbackable, agent-safe.
> WPCC is an **AI Agent Operations Platform for WordPress**, not an AI content plugin.

---

## Part 1 — Repository audit (verified against the codebase)

**Production parity confirmed:** local HEAD = remote `origin/main` = **`2420d1a`**; working
tree clean except untracked strategy/handoff docs. Production (mosharafmanu.com) verified
healthy at this commit (39 tools, schemas live).

**Architecture present (verified in code):**
- **Schema** (`Core/Schema.php`, `DB_VERSION 2.2.0`): 12 tables. Relevant: `wpcc_patches`
  (index), `wpcc_snapshots` (snapshot_id, patch_id, file_path, backup_path, hash, size,
  created_at), `wpcc_operation_requests` (approvals), `wpcc_operation_results`
  (per-execution results), agent `sessions/tasks/plans/plan_steps/actions`,
  `recommendations`. Content stores: per-patch JSON in `uploads/wpcc-patches/`, snapshot
  bytes in `uploads/wpcc-snapshots/`.
- **Rollback (fragmented across 3 mechanisms):**
  1. **Patch rollback** — snapshot-backed, hash-verified (`PatchApproval::rollback` +
     `wpcc_snapshots` + per-patch JSON `snapshot_ids`).
  2. **Runtime rollback** — **19** separate WP options `wpcc_<runtime>_rollbacks`
     (content, media, seo, acf, woo, menu, user, settings, widgets, cpt, comments, forms,
     plugin, theme, option, bulk, elementor, sitebuilder, media_enhance).
  3. **Cross-runtime surfacing** — `RollbackContext` (STEP 102) opportunistically captures
     *the last* rollback id per run by diffing `wpcc_*_rollbacks` option writes;
     `OperationExecutor::rollback()` dispatches `{operation_id, rollback_id}` to a manager's
     `rollback()`.
- **History today** — `TimelineBuilder` aggregates by reading **`AuditLog::tail(2000)`**
  (last 2000 JSONL lines) + a DB baseline, filtered/sorted in PHP. Exposed via
  `/agent/timeline`.
- **Audit** — `AuditLog` JSONL, append-only, **no rotation, no size cap, no index**.

## Part 2 — Hidden risks / gaps before STEP 104
1. **Audit log is unbounded and tail-only.** `TimelineBuilder` only sees the last 2000
   entries; older history is invisible, and in STEP 103.1 a 294 MB `audit.log` dropped
   writes under `LOCK_EX` contention. **STEP 104 history must NOT be built on the JSONL
   tail.**
2. **No unified, queryable change index.** `operation_results` records executions but isn't
   normalized for change history or linked to rollback handles; `agent_actions` are
   *proposals*, not executed changes.
3. **Rollback discovery is patch-only.** The 19 runtime rollback stores have no
   listing/discovery API and `RollbackContext` captures only the *last* id per run
   (multi-rollback runs can be missed). Cross-runtime "what can I undo for this object/path?"
   is not answerable.
4. **Retention is unmanaged** for runtime rollback options and snapshots (unbounded growth).
5. **`RollbackContext` is heuristic** (option-write diff hook) — fine as a surfacing aid,
   not a system of record.

**Conclusion:** STEP 104 introduces a durable, normalized, queryable **change-log table** as
the system of record (recorded at the single existing chokepoint), and a **change_history
runtime** that unifies discovery + rollback targeting over all three mechanisms — without
weakening any existing safety path.

## Part 3 — Specification

### 3.1 Architecture
Three additions, all leveraging existing chokepoints (no per-runtime edits):
1. **`wpcc_change_log` table** — the queryable system of record for every *executed
   mutating* operation, with rollback linkage.
2. **`ChangeRecorder`** (service) — invoked once inside `OperationExecutor::run()` at the
   post-normalize point (where `operation_id`, action, links, result counts, and
   `RollbackContext::last()` are all known). Records exactly one row per mutating execution
   (success **or** failure); **skips read-only/diagnostic ops** (determined from
   `OperationRegistry` effective risk). Reuses the existing `RollbackContext::boot/reset`
   lifecycle.
3. **`change_history` runtime** (new operation → MCP tool + REST) — read actions for
   query/timeline/discovery and one write action (`rollback_target`) that **routes to the
   existing rollback engines** (patch → `PatchApproval::rollback`; runtime →
   `OperationExecutor::rollback`). It is an *index and router*, never a new rollback path.

Principle preservation: capability-scoped (new `history.read`; rollback reuses existing
write/rollback caps), approval-aware (`rollback_target` flows through the Security-Mode gate
+ DestructiveGuard like any mutation), reversible/rollbackable (it *drives* the proven
rollback engines), auditable (writes both the table **and** the JSONL audit event),
agent-safe (no raw writes; compact-envelope + pagination from STEP 103.2).

### 3.2 Storage model
New table (additive; `DB_VERSION` → `2.3.0`, applied via existing `Schema::maybe_upgrade()`
— no destructive migration):

`wpcc_change_log`
- `id` BIGINT PK, `change_id` VARCHAR(36) UNIQUE
- `operation_id` VARCHAR(50), `action` VARCHAR(64), `runtime` VARCHAR(40)
- `status` VARCHAR(24) — `applied | failed | transactional_apply_failed | rolled_back`
- `reversible` TINYINT, `rollback_kind` VARCHAR(20) — `patch | runtime_option | none`
- `rollback_id` VARCHAR(36) NULL, `rolled_back_by_change_id` VARCHAR(36) NULL
- `change_set_id` VARCHAR(36) NULL (links atomic multi-file patch sets, STEP 103)
- linkage: `request_id`, `session_id`, `task_id`, `plan_id`, `action_id` (all NULL-able)
- `actor_json` TEXT, `risk_level` VARCHAR(20), `source` VARCHAR(20)
- `target_summary` TEXT (affected paths / object ids / counts), `target_key` VARCHAR(190)
  NULL (one indexable primary target, e.g. a relative file path or `post:123`)
- counts: `created_count`, `updated_count`, `skipped_count`, `error_count`
- `result_ref` VARCHAR(36) NULL (FK-by-value to `wpcc_operation_results.result_id`)
- timestamps: `created_at`, `rolled_back_at` NULL
- Indexes: `change_id` (unique), `operation_id`, `runtime`, `status`, `change_set_id`,
  `rollback_id`, `target_key`, `session_id`, `created_at`.

Notes: no new content blobs — full diffs/snapshots stay in their existing stores; this table
is a metadata index that *points* to them (mirrors the established
`wpcc_patches`/`wpcc_snapshots` pattern). `target_summary` is redaction-safe (no secrets;
reuses `strip_for_storage`).

**Audit-log hardening (prerequisite, in-scope):** add size-based rotation to `AuditLog`
(rotate `audit.log` → `audit-<ts>.log` past a cap, keep N segments). This removes the
294 MB write-drop risk and gives history a bounded, reliable secondary source. Small,
isolated change; no contract change.

### 3.3 MCP contract — `change_history` tool
Registered in `OperationRegistry` (so it appears in `tools/list` with schema + compact-mode
handling). Read actions are `diagnostic` (no approval); `rollback_target` is `high`
(approval-aware).

Actions:
- `history_list` — params: `runtime?`, `operation_id?`, `status?`, `target?` (path/object),
  `change_set_id?`, `session_id?/task_id?/plan_id?`, `since?/until?`, `reversible_only?`,
  `limit?`, `cursor?`. Returns rows + `total_count`, `has_more`, `next_cursor` (the STEP
  103.2 compact envelope / trust fields).
- `history_get` — `change_id` → full record incl. rollback availability + linkage.
- `history_timeline` — chronological, cursor-paginated (table-backed, replaces tail-only
  reliance).
- `rollback_discover` — `target?` / `change_set_id?` / `change_id?` → reversible changes
  affecting that target, each with `rollback_id`, `rollback_kind`, `reversible`, and the
  exact `rollback_target` params to call.
- `rollback_target` — `change_id` → routes to the owning rollback engine; records a new
  `rolled_back` change-log row and stamps `rolled_back_at`/`rolled_back_by_change_id` on the
  original. Requires write scope + passes Security-Mode + DestructiveGuard gates unchanged.

Determinism: identical behavior across `compact|standard|verbose` (only list shaping differs,
per STEP 103.2).

### 3.4 REST contract (parity)
Same engine via `OperationExecutor`; routes mirror the existing bridge pattern:
- `GET /changes` (read_only) → history_list (query params), envelope identical to MCP.
- `GET /changes/{change_id}` (read_only) → history_get.
- `GET /changes/timeline` (read_only) → history_timeline.
- `POST /operations/change_history/run` (write) → all actions incl. `rollback_target`.

`/agent/timeline` is retained but re-backed by the table (union with JSONL optional);
documented as the legacy alias.

### 3.5 Rollback integration
`rollback_target(change_id)` resolves `rollback_kind`:
- `patch` → `PatchApproval::rollback(rollback_id)` (snapshot + hash verification preserved;
  multi-file change sets reverse as one unit via the STEP 103 combined id).
- `runtime_option` → `OperationExecutor::rollback(operation_id, {rollback_id})` (existing
  dispatcher → manager `rollback()`).

No new restore logic; all hash/verification guarantees are inherited. A rollback that fails
verification surfaces the existing error and does **not** mark the row `rolled_back`.

### 3.6 Approval integration
`history_*` = `require_read` / diagnostic. `rollback_target` = high-risk write → auto-creates
an approval request in client/enterprise modes (`pending_approval` response with the change
summary, mirroring the STEP 103 pattern), and triggers DestructiveGuard confirmation when the
underlying change touches a high-risk file. Read-only tokens may query history but cannot
`rollback_target` (scope enforced in `McpServerRuntime`/`require_write`).

### 3.7 Audit integration
`ChangeRecorder` writes the table row **and** emits a `change.recorded` JSONL audit event
(dual-write). The table is the queryable source of truth; the rotated JSONL remains the
immutable append-only trail. `rollback_target` emits `change.rolled_back`. No change to
existing audit events (backward compatible).

### 3.8 Migration strategy
- `DB_VERSION` bump → `Schema::install()` runs `dbDelta` for `wpcc_change_log` (idempotent,
  additive; auto-applied by `maybe_upgrade()` on next load — same as every prior schema
  upgrade).
- **One-time idempotent backfill** (guarded by a `wpcc_changelog_backfilled` option): seed
  historical rows from `wpcc_patches` (applied/rolled_back, with snapshot linkage) and
  `wpcc_operation_results` (mutating ops) so production has history from day one without
  touching any runtime. Backfill is read-only over existing tables; safe to re-run (no-op
  after flag).
- No data loss, no destructive change, fully reversible by dropping the table on
  deactivation cleanup (existing pattern).

### 3.9 Testing strategy
New `tests/test-change-history.sh` (registered in `regression-map.tsv` under a new `history`
group + `mcp`):
1. Mutating op records exactly one change row; read-only op records none.
2. `history_list` filters (runtime/status/target/change_set_id/session) + pagination +
   compact envelope (`total_count`/`has_more`/`next_cursor`).
3. `history_get` returns rollback availability + linkage.
4. `rollback_discover` finds reversible changes for a path and for a change_set_id.
5. `rollback_target` reverses a **patch** change (hash-exact) and a **runtime** change;
   original row stamped `rolled_back`, new `rolled_back` row created.
6. Atomic multi-file change set recorded with one `change_set_id`; one `rollback_target`
   reverses all files.
7. Approval gating: `rollback_target` → `pending_approval` in client/enterprise; blocked for
   read-only token; DestructiveGuard on high-risk target.
8. Backfill idempotency (run twice → stable counts).
9. REST/MCP parity (`/changes` vs tool).
10. No weakening: PatchGuard, syntax verify, snapshot rollback, security modes all still
    pass (full T1/T2).

Workflow: T0 during dev, T1 before commit, **T2 before deploy** (single run — guard against
the parallel-run issue seen previously).

### 3.10 Deployment strategy
- Local-only commits per step; **pull-cron** deploy (`git push origin main` → mosharafmanu.com
  ~1 min) only after T2 net-new 0.
- Post-deploy verify (with production token): `wpcc_change_log` exists (DB version 2.3.0),
  `change_history` in `tools/list`, schema shows the read+rollback actions, a read smoke
  (`history_list`), and **one reversible `rollback_target` round-trip on a safe markdown
  file** with hash-exact restoration (the STEP 103.1 method).
- Backfill runs automatically on first load; verify a non-zero historical count post-deploy.

## Recommended build order (sub-steps)
- **104.0** — AuditLog rotation hardening (prerequisite; removes the unbounded-log risk).
- **104.1** — `wpcc_change_log` schema + `ChangeRecorder` at the OperationExecutor chokepoint
  + dual-write audit (record-only; no new API).
- **104.2** — `change_history` runtime read actions (list/get/timeline/discover) + REST parity
  + capability `history.read` + compact envelope.
- **104.3** — `rollback_target` (router to existing engines) + approval/DestructiveGuard
  integration + backfill migration.
- **104.4** — full test suite, T2, deploy + production verification.
