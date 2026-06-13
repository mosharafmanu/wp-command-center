# STEP 87 — REST + MCP File/Patch Capability Audit and Bridge

## Context

WP Command Center has supported file editing via REST API + token for a long
time, but Claude Desktop (MCP) could not find a safe, exposed capability for
file read/write, code search, or patch create/apply/rollback. The root cause was
architectural, not missing functionality: **file access, code search, and the
patch engine were exposed only as bespoke REST routes** (`/files/*`, `/search`,
`/patches/*`), which the MCP server never sees. MCP enumerates its tools from
`OperationRegistry::get_operations()` and dispatches them through
`OperationExecutor` — so only *operations* are MCP-reachable. The snapshot system
was already an operation (`snapshot_manage`); the other four were not.

This step audits all five systems and bridges them to MCP **by registering new
operations that delegate to the existing service classes** — no logic was
rebuilt or duplicated.

---

## Current architecture map

```
            REST transport                         MCP transport
  /files/*  /search  /patches/*  (bespoke)   tools/list + tools/call
        │                                              │
        │                                   OperationRegistry (tool list)
        │                                   OperationExecutor (dispatch)
        │                                              │
        └──────────────┬───────────────────────────────┘
                        ▼   shared service layer (single implementation)
   FileAccessApi · CodeSearch · PatchManager · PatchApproval
   SnapshotManager (Rollback) · RollbackManager · PathGuard · Redactor · AuditLog
                        ▼
              snapshots / patches / rollback / audit log
```

Before STEP 87 the **left arm (REST)** reached the services directly; the
**right arm (MCP)** could only reach `snapshot_manage`. STEP 87 adds four
operations (`file_manage`, `code_search`, `patch_manage`, `rollback_manage`) so
both arms reach the *same* services through `OperationExecutor`.

---

## Audit results

| System | Impl? | REST? | MCP (before) | MCP (after) | Token auth | Capability | Security modes | Audit | Rollback | Tests | No SSH/WP‑CLI |
|--------|:----:|:----:|:-----------:|:----------:|:---------:|:----------:|:--------------:|:----:|:-------:|:----:|:------------:|
| File Access | ✅ `FileAccessApi` | ✅ `/files/*` | ❌ | ✅ `file_manage` | ✅ | ✅ search.manage | ✅ diagnostic (direct all modes) | ✅ | n/a (read) | ✅ | ✅ |
| Code Search | ✅ `CodeSearch` | ✅ `/search` | ❌ | ✅ `code_search` | ✅ | ✅ search.manage | ✅ diagnostic | ✅ | n/a | ✅ | ✅ |
| Patch Engine | ✅ `PatchManager`/`PatchApproval` | ✅ `/patches/*` | ❌ | ✅ `patch_manage` | ✅ | ✅ snapshot.manage | ✅ create=low, apply=high | ✅ | ✅ snapshots | ✅ | ✅ (tokenizer fallback) |
| Snapshot | ✅ `SnapshotManager` | ✅ `snapshot_manage` | ✅ already | ✅ (unchanged) | ✅ | ✅ snapshot.manage | ✅ | ✅ | ✅ | ✅ | ✅ |
| Rollback | ✅ `RollbackManager`/`PatchApproval::rollback` | ✅ `/patches/{id}/rollback` | ❌ | ✅ `rollback_manage` | ✅ | ✅ snapshot.manage | ✅ apply=high | ✅ | ✅ | ✅ | ✅ |

**Identified gaps (now closed):**
1. File access / code search / patch / rollback were not operations → invisible to MCP. → 4 new operations.
2. `PatchApproval::verify_file()` **skipped** PHP syntax validation when shell was unavailable (returned `passed:true`). A broken patch could apply on a no-shell host. → tokenizer (`token_get_all(..., TOKEN_PARSE)`) fallback added; apply is now blocked on syntax errors with or without shell.
3. No explicit confirmation requirement for editing high-risk files. → `DangerousFiles` + `DestructiveGuard` integration for `patch_apply`.

---

## REST endpoint map

Existing (unchanged, already used the services):

| Method | Path | Service |
|--------|------|---------|
| GET | `/files`, `/files/content`, `/files/meta` | `FileAccessApi` |
| GET | `/search` | `CodeSearch` |
| GET/POST | `/patches`, `/patches/{id}`, `/patches/{id}/approve\|reject\|apply\|rollback` | `PatchManager` + `PatchApproval` |
| POST | `/operations/snapshot_manage/run` | `SnapshotManager` |

Added in STEP 87 (same services, via `OperationExecutor`, same token/scope rules):

| Method | Path | Scope |
|--------|------|-------|
| POST | `/operations/file_manage/run` | read_only |
| POST | `/operations/code_search/run` | read_only |
| POST | `/operations/patch_manage/run` | full |
| POST | `/operations/rollback_manage/run` | full |

## MCP tool map (after)

| Tool | Actions | Capability | Scope |
|------|---------|-----------|-------|
| `file_manage` | `file_read`, `file_tree`, `file_metadata` | search.manage | read_only OK |
| `code_search` | `search_text`, `search_symbol`, `search_file` | search.manage | read_only OK |
| `patch_manage` | `patch_preview`, `patch_create`, `patch_apply`, `patch_verify`, `patch_status` | snapshot.manage | full |
| `rollback_manage` | `rollback_list`, `rollback_get`, `rollback_apply`, `rollback_verify` | snapshot.manage | full |
| `snapshot_manage` | `snapshot_create`, `snapshot_list`, `snapshot_details`, `snapshot_restore`, `snapshot_verify` | snapshot.manage | full |

---

## Security model

The **Security Mode operation gate is the human-approval layer** — it is not
duplicated with the patch record's own approval. `patch_apply` first promotes a
pending patch record to *approved*, then applies; whether a human must intervene
is decided once, by the mode gate, via action risk:

| Action | Risk | Developer | Client | Enterprise |
|--------|------|-----------|--------|------------|
| `file_read`/`file_tree`/`file_metadata` | diagnostic | direct | direct | direct |
| `search_*` | diagnostic | direct | direct | direct |
| `patch_preview` / `patch_status` / `patch_verify` | diagnostic | direct | direct | direct |
| `patch_create` | low | direct | direct | **approval** |
| `patch_apply` | high | direct¹ | **approval** | **approval** |
| `rollback_*` (read) | diagnostic | direct | direct | direct |
| `rollback_apply` | high | direct | **approval** | **approval** |

¹ In **every** mode, applying a patch that touches a **high-risk file**
(`DangerousFiles`: theme `functions.php`, any active-theme file, or a plugin main
file `plugins/{slug}/{slug}.php`) requires explicit confirmation —
`confirm:true` + `confirmation_phrase:"APPLY_PATCH"` + `reason` — enforced by
`DestructiveGuard` (STEP 84 mechanism) ahead of the mode gate. `wp-config.php`
and `.htaccess` remain blocked outright by `PathGuard` and can never reach the
engine.

**Safety guarantees preserved on apply** (`PatchApproval::apply`, unchanged
except for the syntax fallback): snapshot every target file → write → verify PHP
syntax (`php -l`, else tokenizer) → **auto-revert on failure** → audit. Secrets
are redacted in `file_read`/`code_search` results on both transports (handler +
MCP redact pass).

---

## Files changed

**New**
- `includes/Operations/FileManager.php` — `file_manage` handler → `FileAccessApi`
- `includes/Operations/CodeSearchOperation.php` — `code_search` handler → `CodeSearch`
- `includes/Operations/PatchOperation.php` — `patch_manage` handler → `PatchManager`/`PatchApproval`/`DiffGenerator`
- `includes/Operations/RollbackOperation.php` — `rollback_manage` handler → `PatchApproval`/`SnapshotManager`
- `includes/PatchSystem/DangerousFiles.php` — high-risk file classifier
- `tests/test-file-patch-bridge.sh` — 32 assertions (15 acceptance tests + dangerous-file bonus)

**Modified**
- `includes/PatchSystem/PatchApproval.php` — tokenizer syntax fallback; `verify_file()`/`tokenizer_check()` made public for reuse
- `includes/AiAgent/CodeSearch.php` — `find_files()` for `search_file`
- `includes/Operations/OperationRegistry.php` — register the 4 operations (+ risk maps)
- `includes/Operations/OperationExecutor.php` — `resolve_handler()` for the 4 operations
- `includes/Operations/CapabilityRegistry.php` — `OPERATION_MAP` + `READ_ONLY_SCOPE_OPERATIONS`
- `includes/Operations/DestructiveGuard.php` — `patch_apply` dangerous-file classification (`APPLY_PATCH`)
- `includes/AiAgent/RestApi.php` — 4 REST run routes (`run_bridge_operation` shared dispatcher) + `ROUTE_MANIFEST` entries

No new storage, no schema change. The bridge reuses existing patch/snapshot
storage and the `wpcc_capability_assignments` model.

---

## Validation results

`tests/test-file-patch-bridge.sh` — **32/32 PASS**, mapping to the 15 acceptance tests:

| # | Acceptance test | Result |
|---|-----------------|--------|
| 1 | REST file read works with token | ✅ `/files/content` |
| 2 | MCP file read works with token | ✅ `file_manage/file_read` |
| 3 | REST code search works | ✅ `/search` |
| 4 | MCP code search works | ✅ `code_search/search_text` |
| 5 | REST patch preview works | ✅ |
| 6 | MCP patch preview works | ✅ |
| 7 | REST patch apply → patch_id + rollback_id | ✅ |
| 8 | MCP patch apply → patch_id + rollback_id | ✅ |
| 9 | File change verified | ✅ on-disk v2 + `patch_verify` |
| 10 | Rollback restores previous file | ✅ back to v1 |
| 11 | PHP syntax error blocks apply | ✅ status `failed`, auto-reverted |
| 12 | Client Mode → pending approval for apply | ✅ |
| 13 | Enterprise Mode → pending approval for apply | ✅ |
| 14 | Audit records all file ops | ✅ `file.read`, `code.search`, `patch.applied`, `patch.rolled_back` |
| 15 | No SSH/WP-CLI required | ✅ tokenizer-based syntax validation over HTTP |

Regression (affected suites): `test-patch-lifecycle` 116/116, `test-capability-bootstrap`
21/21, `test-agent-manifest` 43/43, `test-destructive-guardrails` 21/21,
`test-security-modes` 28/28, `test-e2e-runtime` 49/49.

---

## Remaining risks

1. **Capability granularity.** The bridge reuses `search.manage` (read) and
   `snapshot.manage` (patch/rollback) rather than introducing dedicated
   `file.access` / `patch.manage` capabilities, to avoid touching the capability
   bootstrap and existing assignments. A site that grants `snapshot.manage` to a
   token implicitly grants patch apply/rollback. Future refinement: dedicated caps.
2. **Manual `/operations/requests` path.** A destructive/dangerous request can be
   *created* through the manual request API without confirmation; it still
   requires explicit human approval before executing (its existing safeguard).
   Inherited from STEP 84.
3. **Plugin-main-file heuristic.** `DangerousFiles` flags `plugins/{slug}/{slug}.php`
   (the standard convention). A plugin whose main file is named differently would
   not be flagged as dangerous (it is still snapshot-protected and syntax-checked
   on apply; it just would not require the extra confirmation phrase).
4. **Tokenizer vs. runtime errors.** `token_get_all(TOKEN_PARSE)` catches *syntax*
   errors, not runtime/fatal errors (e.g. calling an undefined function). The
   existing post-write loopback health check (patch lifecycle) remains the
   backstop for runtime breakage; auto-revert covers syntax breakage.

## Recommendation for production readiness

**Ready to ship.** The bridge is additive, reuses audited services, duplicates no
logic, and strengthens safety (no-shell syntax validation now blocks broken
applies; dangerous-file edits require confirmation). It does not weaken any
existing security control or approval mode. Recommended follow-ups before a
broad GA, in priority order: (a) dedicated `file.access`/`patch.manage`
capabilities; (b) extend the confirmation gate to the manual request-create path;
(c) optional content-based plugin-main-file detection. None are launch-blocking.
