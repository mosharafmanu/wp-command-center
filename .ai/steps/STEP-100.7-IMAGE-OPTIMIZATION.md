# STEP 100.7 — Image Optimization Runtime

**Priority 3** of STEP 100 (second sub-step). Re-encodes image files at a quality
target to reduce bytes **without changing dimensions**, reusing the 100.1 snapshot
service and the 100.5 rollback machinery. Standalone, tested, locally committed.
Does **not** start 100.8.

## Architecture summary

- **No new operation.** Four actions added to `media_enhance`; `operation_map`
  stays **33**; capability `media.manage`; MCP parity automatic.
- **GD/Imagick only** via `wp_get_image_editor()` — no shell/`exec`/`system`.
- **Re-encode, never resize.** Each image file (original + every generated size)
  is re-saved at the requested quality; dimensions are preserved.
- **Safe-commit pattern.** Phase 1 re-encodes each file to a **temp** (originals
  untouched) and measures; Phase 2 captures a `MediaSnapshot` then commits only
  files with significant savings (atomic `rename`). Nothing meaningful → `no_action`
  (no snapshot, no write). This is how "skip if savings insignificant" and
  "snapshot before any write" are both honored.
- **Reversible.** Reuses `store_rollback`/`rollback` (mode `image_optimize`);
  `/operations/media_enhance/rollback { rollback_id }` restores original bytes +
  metadata byte-for-byte via `MediaSnapshot::restore()`.
- **Capability-gated, fail closed** (`wpcc_image_lib_unavailable`), filterable via
  `wpcc_media_optimize_available` (operator/test kill-switch).

## Actions added

| Action | Class | Behavior |
|--------|-------|----------|
| `image_optimize_audit` | R (diagnostic) | Library stats: total / supported / unsupported / candidates (≥50KB) / oversized (>2500px) / already-optimized + heuristic estimated savings + capability. Read-only, no re-encoding. |
| `image_optimize_verify` | R (diagnostic) | One attachment: current size, dimensions, mime, eligibility, estimated savings, capability checks. |
| `image_optimize` | RW (medium) | Re-encode one attachment at `quality` (default 82, clamp 1–100). Snapshot-backed, skips insignificant savings, reversible. Returns before/after bytes, bytes_saved, percent_saved, rollback_id. |
| `image_optimize_batch` | RW (medium) | Cursor batch with partial-success reporting (optimized / no_action / unsupported / failed + `batch_id`); resume-safe; continues past per-item failures. |

## Optimization rules

- **Supported:** `image/jpeg`, `image/png`, `image/webp`.
- **Unsupported:** GIF / SVG / AVIF → `wpcc_optimize_unsupported_mime` (single) or
  counted `unsupported` (batch); SVG isn't a WP image type → `wpcc_not_an_image`.
- **Never upscales / never changes dimensions** (re-encode only).
- **Significance threshold:** commit only if savings ≥ 5% **and** ≥ 1KB per file;
  otherwise that file is skipped (so re-encoding at a higher quality is a no-op).

## Rollback validation

Reuses the existing architecture (no custom rollback): every committed optimization
captures a snapshot, registers a rollback record (`wpcc_media_enhance_rollbacks`),
and is restored through `/operations/media_enhance/rollback` (and the unified
`OperationExecutor::rollback` dispatcher). Verified in tests: after rollback the
original file md5 equals the pre-optimization md5 (byte-for-byte), and no temp
files are left on disk.

## Files changed

- `includes/Operations/MediaEnhancementRuntimeManager.php` — 4 actions +
  `do_optimize`, `optimize_available` (filtered), `clamp_quality`,
  `refresh_filesize_metadata`; `OPTIMIZE_*` constants; reuses `store_rollback`/
  `rollback`/`attachment_image_files`.
- `includes/Operations/OperationRegistry.php` — action_risks (audit/verify
  diagnostic; optimize/batch medium), enum, `quality` param, description.
- `includes/AiAgent/RestApi.php` — `require_media_enhance` write-list += optimize/
  batch; ROUTE_MANIFEST description; error catalog +
  `wpcc_optimize_unsupported_mime`, `wpcc_optimize_snapshot_failed`,
  `wpcc_optimize_failed`.

## Implementation note (bug found & fixed during build)

`WP_Image_Editor::save( $path, $mime )` derives the output filename from the
**mime**, not from the path's extension — passing `file.jpg.wpccopt` produced
`file.jpg.jpg`. The temp now carries the correct extension and the code uses
`$saved['path']` (the real written file) rather than the path it passed in.

## Tests

`tests/test-media-enhance-optimize-step100-7.sh` — **45/45 PASS**: audit + verify
paths; supported JPEG/PNG/WebP; unsupported GIF/SVG; capability unavailable (filter,
fail-closed); success (JPEG q50 ~60% saved, dimensions preserved); skipped
(re-encode at higher quality → no_action); rollback byte-for-byte + no temp leftovers;
batch partial success; REST + MCP parity; structured errors; wiring. 100.3–100.6
suites re-run green (33/33/39/37).

Regression: T1 `--changed` — see commit.

## Scope note

This step is **reversible-only** (always snapshot-backed) per the prompt's safety
requirements — it does not implement the plan doc's optional `keep_original=false`
destructive path (that would drop the snapshot and require DestructiveGuard);
deferred unless explicitly requested.

## Next

**STEP 100.8** — usage analysis (read-only: where each attachment is used; find
unused/orphaned media), ahead of any destructive cleanup (100.9). **Not started.**
