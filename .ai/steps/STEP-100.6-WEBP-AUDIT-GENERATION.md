# STEP 100.6 — WebP Audit & Generation Runtime

**Priority 3** of STEP 100 (first sub-step). Builds on the 100.3 capability probe
and the 100.5 snapshot/rollback machinery to **additively** generate WebP
derivatives. Standalone, tested, locally committed. Does **not** start 100.7.

## Capability gap addressed

The runtime could detect WebP encode support (100.3) but could not *produce* WebP.
100.6 adds safe, additive WebP generation — `.webp` sidecars written **beside**
each image file, originals never touched — plus the audits to drive it. GD/Imagick
only (no shell binaries); WebP first per the owner-locked constraints.

## Actions added to `media_enhance` (no new operation; operation_map stays 33)

| Action | Class | Behavior |
|--------|-------|----------|
| `webp_audit` | R (diagnostic) | Library-wide coverage: per-image-file `.webp` presence, fully/partially/not-covered counts, `coverage_percent`, plus the capability probe (gd_webp / imagick_webp / webp_encode). |
| `webp_verify` | R (diagnostic) | One attachment: each image file's `.webp` presence + smaller-or-equal-than-source, supported-mime flag, coverage. |
| `webp_generate` | RW (medium) | One attachment: generate `.webp` for the original + each size that lacks one. Capability-gated, snapshot-backed, skips existing, reversible. |
| `webp_generate_batch` | RW (medium) | Cursor batch with **partial-success reporting** (generated / no_action / unsupported / failed counts + per-item results); shared `batch_id`. |

Reversal: `POST /operations/media_enhance/rollback { rollback_id }` (reuses the
100.5 rollback machinery — deletes the generated `.webp`, then restores the
snapshot as a defensive byte-integrity check).

## Safety model (all requirements satisfied)

- **Additive only**: writes `<file>.webp` beside each source; **never modifies,
  replaces, or deletes** originals or size files (asserted by md5 in tests).
- **Capability-gated, fail closed**: `webp_generate`/`_batch` abort with
  `wpcc_image_lib_unavailable` when WebP encoding is unavailable. Detection is
  filterable via `wpcc_media_webp_encode_available` (operator/test kill-switch).
- **Snapshot before any write**: `MediaSnapshot::capture()` runs before generating;
  capture failure → `wpcc_webp_snapshot_failed`, no write.
- **No duplicates**: files that already have a `.webp` are skipped; an attachment
  fully covered returns `no_action:true` (no snapshot, no write, no rollback).
- **Skip unsupported mimes**: only `image/jpeg` / `image/png` sources; a single
  call on another type → `wpcc_webp_unsupported_mime`; in batch it's counted as
  `unsupported` without aborting.
- **Missing source handled**: missing original → `wpcc_media_no_files`.
- **Verify + reversible**: each generated file confirmed on disk (`verified`);
  rollback deletes exactly the generated `.webp` (originals untouched). If nothing
  could be generated, state is restored and `wpcc_webp_generate_failed` returned.
- **Partial success** in batch; **structured errors** throughout.

## Wiring

- `MediaEnhancementRuntimeManager`: 4 actions + `do_webp_generate`,
  `webp_encode_available` (filtered), `webp_capability`, `attachment_image_files`,
  `all_files_exist`; `WEBP_SOURCE_MIMES`; reuses `store_rollback`/`rollback` from
  100.5 (rollback record mode `webp_generate`, `created_files` = generated `.webp`).
- `OperationRegistry`: action_risks (audit/verify diagnostic; generate/batch
  medium), enum, description.
- `RestApi`: `require_media_enhance` write-action list += `webp_generate`/
  `webp_generate_batch`; ROUTE_MANIFEST description; error catalog +
  `wpcc_image_lib_unavailable`, `wpcc_webp_unsupported_mime`,
  `wpcc_webp_snapshot_failed`, `wpcc_webp_generate_failed`.
- MCP parity automatic. WebP files use the `<file>.webp` sidecar convention
  (e.g. `image-300x225.jpg.webp`), additive and reference-safe.

## Tests

`tests/test-media-enhance-webp-step100-6.sh` — **37/37 PASS**: capability
available + unavailable (filter), successful generation, existing-webp no-op,
verification + originals-untouched (md5), rollback restores state, unsupported
mime (GIF), missing source file, batch partial-success (jpeg generated + gif
unsupported), structured errors, REST + MCP parity, wiring. 100.3/100.4/100.5
suites re-run green (33/33/39).

Regression: T1 `--changed` — see commit.

## Notes / gotcha

- **PSR-4 multi-class-per-file (again):** a `const` intended for the manager was
  first placed in the `MediaEnhancementRegistry` class block and referenced via
  `self::` from the manager → fatal `Undefined constant`. Manager-only constants
  must live in the manager class. (Same family of trap as the 100.5 permission
  callback.)

## Next

**STEP 100.7** — image optimization (re-encode at a quality target,
snapshot-backed, `keep_original=true` default; `keep_original=false` is destructive
→ DestructiveGuard). **Not started** — per owner, stop after 100.6.
