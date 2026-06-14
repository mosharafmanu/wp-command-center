# STEP 100.5 — Thumbnail Regeneration Runtime

**Priority 2** of STEP 100 (third sub-step) and the **first intentional write**
in the media-enhancement runtime. Snapshot-backed and fully reversible. Standalone,
tested, locally committed. Does **not** start 100.6.

## Capability gap addressed

100.3/100.4 could *detect* missing intermediate sizes but could not *fix* them.
100.5 adds the real `wp media regenerate` capability — recreate resized image
**files** on disk — done safely: a byte-for-byte snapshot (STEP 100.1
`MediaSnapshot`) is captured **before** any write, the result is verified, and the
whole operation is reversible. It is **audit-first**: it prefers regenerating only
the missing/needed sizes, respects WordPress's no-upscale rule, never generates
impossible sizes, and is a no-op when nothing is required.

## Actions added to `media_enhance` (no new operation; operation_map stays 33)

| Action | Class | Behavior |
|--------|-------|----------|
| `thumbnail_regenerate` | RW (medium) | One attachment. `mode=missing` (default) generates only missing **applicable** sizes; `mode=all` rebuilds every registered size from the original. |
| `thumbnail_regenerate_attachment` | RW (medium) | Alias of `thumbnail_regenerate`. |
| `thumbnail_regenerate_batch` | RW (medium) | Cursor batch over the image library (or an explicit `media_ids` list); `limit` default 20 / max 50; per-item snapshot; shared `batch_id`; returns `next_cursor`. |
| `thumbnail_verify` | R | Post-regen check: are all applicable sizes present + metadata complete? |

Reversal is a route, not an action: `POST /operations/media_enhance/rollback
{ rollback_id }` (also reachable via the unified `OperationExecutor::rollback`
dispatcher and the Workflow on_failure:rollback path, since the manager now
exposes a public `rollback()`).

## Safety model (all requirements satisfied)

- **Snapshot before write**: `MediaSnapshot::capture()` runs before any mutation.
- **Abort if snapshot fails**: capture error → `wpcc_thumbnail_snapshot_failed`,
  no write performed.
- **No-op when nothing to do**: `mode=missing` with no missing applicable sizes
  returns `no_action:true` — **no snapshot, no write, no rollback record**.
- **No impossible sizes**: targets exclude `not_applicable` (larger-than-original)
  sizes; `mode=all` relies on WP's own no-upscale.
- **Verify after regen**: every targeted size must be present afterward; if not,
  the snapshot is restored, created files deleted, and
  `wpcc_thumbnail_regenerate_failed` returned (**no partial state**).
- **Byte-for-byte rollback**: rollback deletes files created by the regeneration,
  then `MediaSnapshot::restore()` rewrites the original + prior size files +
  metadata exactly. (A regenerated size that shares a filename with an existing
  size — e.g. a custom 300×225 vs core `medium` on a 4:3 image — creates no new
  file and is restored via the snapshot; only genuinely-new files are deleted.)
- **Structured errors only**; capped FIFO rollback store (`wpcc_media_enhance_rollbacks`, 100).

## Security / wiring

- Per-action risk: regen actions = `medium`, others `diagnostic`
  (`MediaEnhancementRegistry::get_risk` / `OperationRegistry` action_risks). In
  Client/Enterprise mode the medium-risk writes flow through the normal approval
  gate; Developer mode executes directly.
- REST route `/operations/media_enhance/run` now uses a per-action permission
  callback `require_media_enhance`: **write actions require a full token**, reads a
  read token. New `/operations/media_enhance/rollback` route (`require_write`).
- `operation_map` unchanged (33); capability stays `media.manage`; MCP parity
  automatic (regen actions callable as `media_enhance` tool args).
- New error codes cataloged: `wpcc_thumbnail_snapshot_failed`,
  `wpcc_thumbnail_regenerate_failed`, `wpcc_media_no_files`.

## Files changed

- `includes/Operations/MediaEnhancementRuntimeManager.php` — 4 actions +
  `do_regenerate`/`rollback`/`store_rollback`/`size_files_abs`/
  `delete_created_files`/`error` + `WRITE_ACTIONS`, `get_risk` map, rollback-store
  & batch constants.
- `includes/Operations/OperationRegistry.php` — `media_enhance` def: action_risks
  (3 medium + thumbnail_verify diagnostic), enum, params (mode/media_ids/cursor/
  rollback_id), description.
- `includes/AiAgent/RestApi.php` — `require_media_enhance` per-action permission,
  rollback route + `run_media_enhance_rollback`, ROUTE_MANIFEST entries, 3 catalog
  codes.

## Tests

`tests/test-media-enhance-regenerate-step100-5.sh` — **39/39 PASS**: successful
missing-size regeneration, already-complete no-op, post-regen metadata verify,
rollback (created file removed), mode=all **byte-for-byte** snapshot restore,
oversized/not-applicable handling (impossible sizes never generated), failed
regeneration (editors disabled) → structured error + no partial write, batch
cursor, structured errors, REST + MCP parity, wiring. 100.3 (33/33) and 100.4
(33/33) suites re-run green.

Regression: T1 `--changed` — see commit.

## Next

**STEP 100.6** — WebP audit + generate (additive; GD/Imagick). **Not started** —
per owner, stop after 100.5.
