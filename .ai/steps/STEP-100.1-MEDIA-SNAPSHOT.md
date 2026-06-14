# STEP 100.1 — File-level Media Snapshot Service

**Priority 1** of the STEP 100 Media Enhancement Runtime. Standalone, tested,
locally committed. Does **not** start 100.2.

## Goal

A reusable service that captures an attachment's **bytes** — original file +
every generated size file + `_wp_attachment_metadata` — and restores them
**byte-for-byte**. This is the safety primitive that makes the later
file-mutating media operations (replace, thumbnail regenerate, optimize,
cleanup) genuinely reversible.

## Why a dedicated store (not the core Snapshot engine)

Media files live under `uploads/`. The existing code-oriented Snapshot engine
(`Rollback\SnapshotManager`) is intentionally constrained by `PathGuard` to
`themes/plugins/mu-plugins` and rejects `uploads` paths
(`wpcc_path_not_allowed`). So `MediaSnapshot` keeps its **own** byte store under
`uploads/wpcc-media-snapshots/<snapshot_id>/` (directory listing denied via a
silent `index.php`). Media-file snapshots and code-file snapshots stay cleanly
separated.

## Component

`includes/Operations/MediaSnapshot.php`:

| Method | Behavior |
|--------|----------|
| `capture( attachment_id, label='' )` | Copies original + all existing size files into the store, records each file's wp-content-relative path + md5 + size, and snapshots the metadata array + `_wp_attached_file`. Returns `{ id, attachment_id, files }`. Never mutates live files. Partial-failure safe (cleans up). |
| `restore( snapshot_id )` | Rewrites every captured file to its stored bytes (recreating deleted size files), restores metadata. Returns `{ restored, files_restored, verified }` where `verified` is a per-file md5 re-check. |
| `verify( snapshot_id )` | Reports per file whether the stored copy still matches its recorded hash (`snapshot_intact`) and whether the live file still matches (`matches_current`). |
| `list( attachment_id=0 )` / `delete( snapshot_id )` | Enumerate / purge (removes stored bytes too). Store capped at 200, oldest evicted. |

Storage record: `wpcc_media_file_snapshots` option — `{ id, attachment_id,
label, files:[{rel_path,store_name,hash,size}], metadata, attached_file,
created_at }`.

## REST + MCP surface (parity)

Exposed as `media_manage` actions (no new operation → `operation_map` unchanged
at 32; capability stays `media.manage`):

| Action | Risk | Approval |
|--------|------|----------|
| `media_snapshot_create` | low | no |
| `media_snapshot_verify` | diagnostic | no |
| `media_snapshot_list` | diagnostic | no |
| `media_snapshot_restore` | medium | no (mode-gated) |

Wired in `MediaRegistry` (actions/risk/approval), `MediaRuntimeManager::run()`
(+ 4 handlers), and the `OperationRegistry` `media_manage` def (action_risks +
`snapshot_id`/`label` params + description). REST via the existing
`media_manage/run`; MCP via enumeration — no transport-specific logic.

## Safety / rollback

- Capture is read+copy only (no live mutation). Restore is the reversal
  primitive itself.
- `media_snapshot_restore` is `medium` (writes files) → Client/Enterprise modes
  gate it; Developer auto-runs.
- All actions audited (`media.snapshot.captured` / `.restored`).
- Note: this step ships the **service**. Wiring it into `media_replace`'s
  rollback (fixing the audited no-op bug) is **STEP 100.2** and is deliberately
  not started here.

## Tests

`tests/test-media-snapshot-step100-1.sh` — **23/23 PASS**: seed attachment with
generated sizes → create snapshot (REST) → verify matches disk → corrupt
original + delete a size file → verify reflects drift while snapshot stays intact
→ **restore (MCP) → original restored byte-for-byte + deleted size file
recreated** → verify all match again → list parity (REST+MCP) → structured
errors (missing/unknown snapshot_id, non-attachment target). Existing media
suites unaffected (`test-media-runtime` 80/80, `step90` 25/25, `media-import`
9/9, `capability-runtime` 61/61). Full bash regression: 0 net-new failures
(24 baseline).

## Next

**STEP 100.2** — consume this service to fix `media_replace` rollback (capture
before sideload; add the missing `replace` rollback case; add
`media_replace_verify`). Begin only after this step is verified green.
