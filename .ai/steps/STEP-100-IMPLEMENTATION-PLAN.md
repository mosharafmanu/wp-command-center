# STEP 100 — Prioritized Implementation Plan

**Status:** Plan only — **do not implement yet.** Awaiting owner review.
**Companion to:** `STEP-100-MEDIA-ENHANCEMENT-RUNTIME.md` (architecture).
**Design doc committed:** `b77d1c5`.

---

## Locked constraints (owner-confirmed)

1. **GD / Imagick only.** No shell binaries (no `cwebp`, `jpegoptim`, `optipng`,
   ImageMagick CLI). Preserves the no-SSH guarantee.
2. **WebP first.** AVIF deferred to a follow-up.
3. **`keep_original = true` by default** for optimization (reversible). Setting
   `false` is a separate destructive path requiring DestructiveGuard.
4. **Audit-first.** Every destructive or file-mutating capability ships its
   read/audit action **before** (or in the same sub-step as) the write, and the
   write is snapshot-backed.
5. **One shared runtime, REST + MCP parity, no transport-specific business
   logic** (existing convention).

## Per-sub-step engineering contract (roadmap discipline)

Each sub-step is independently: **implemented → unit/REST/MCP/acceptance/
regression tested → documented → committed locally** (not auto-deployed; owner
deploys the batch). Definition of Done for every sub-step:

- Acceptance suite green; full bash regression **0 net-new** failures (24 baseline).
- REST route + MCP enumeration both verified (parity).
- Capability + Security-Mode + Audit wired; rollback verified where applicable.
- Step doc updated; `resume.md` + memory updated.

---

## Priority 1 — Correctness & Safety Foundation

> The file-snapshot service is the enabler for **all** later file-mutating ops
> (replace, regenerate, optimize, cleanup). Build it first, then use it to fix
> the known bug.

### 100.1 — File-level media snapshot service

- **Goal:** A reusable service that snapshots an attachment's **bytes** — the
  original file plus every generated size file plus `_wp_attachment_metadata` —
  and restores them exactly. Integrates with the existing Snapshot System.
- **New:** `MediaSnapshot` service (`includes/Operations/MediaSnapshot.php`):
  `capture( attachment_id ): snapshot_id` and `restore( snapshot_id ): bool`.
  Stores file copies via the Snapshot System; returns a reference id.
- **Rollback record change:** extend `wpcc_media_rollbacks` entries with
  `snapshot_id` (and reserve `batch_id`). Keep the 100-cap, one-shot guard, and
  unified `OperationExecutor::rollback()` contract.
- **Safety class:** internal service (no public action). Read+copy only.
- **Acceptance:** capture an attachment → mutate its file on disk → restore →
  file hash + metadata identical to pre-mutation. Snapshot of an attachment with
  N sizes restores all N. Missing-file attachment handled gracefully.
- **Depends on:** Snapshot System (`snapshot_manage`). No new operation.

### 100.2 — Fix `media_replace` rollback (consume 100.1)

- **Goal:** Make `media_replace` truly reversible (today it returns a
  `rollback_id` but `rollback()` has **no `replace` case**, and the snapshot
  omits file bytes — silent no-op).
- **Change:** in `MediaRuntimeManager`, before sideload call
  `MediaSnapshot::capture()`; store `snapshot_id` on the rollback record; add the
  missing `replace` case to `rollback()`/`restore_media()` that calls
  `MediaSnapshot::restore()` + restores metadata.
- **New action:** `media_replace_verify` (read-only) — confirms the live file
  matches the last replace (size/dimensions/mime).
- **Safety class:** `media_replace` = reversible write (was falsely advertised
  as such; now real). `media_replace_verify` = read-only.
- **Acceptance:** replace an image → bytes/dimensions change → `media_replace_verify`
  reflects new file → **rollback restores original bytes (hash match)** +
  metadata. *(Directly asserts the audited bug is fixed.)*
- **Depends on:** 100.1.

---

## Priority 2 — Inspection & Thumbnails (audit-first, all reversible)

### 100.3 — `media_enhance` foundation + capability probe + image-size audit

- **Goal:** Stand up the new operation and ship the first (read-only) reports.
- **New:** `MediaEnhancementRuntimeManager` + `MediaEnhancementRegistry`; new
  operation `media_enhance`. Wiring: `OperationExecutor::resolve_handler`,
  `OperationRegistry` op def (action_risks), `CapabilityRegistry`
  `media_enhance → media.manage` (operation_map +1, update capability test
  count), `RestApi` run route (read actions via a read-permission route) +
  manifest. MCP parity automatic.
- **Actions (all read-only):**
  - `media_enhance_capabilities` — GD/Imagick present? WebP/AVIF encode support?
    fail-closed signal for later ops.
  - `image_sizes_list` — all registered sizes incl. theme `add_image_size`.
  - `image_size_usage_audit` — per-size on-disk presence across the library.
  - `image_size_recommendations` — flag unused/oversized registered sizes.
  - `image_size_verify` — for one attachment, which registered sizes exist.
- **Safety class:** all read-only (diagnostic, no approval).
- **Acceptance:** capability booleans returned; sizes list includes a
  theme-registered size; usage audit counts; verify flags a missing size.
- **Depends on:** nothing (read-only); foundation for 100.4–100.9.

### 100.4 — Responsive image audit (read-only)

- **Goal:** Verify srcset / responsive coverage.
- **Actions (read-only):** `srcset_verify`, `responsive_image_audit`,
  `missing_sizes_audit`, `thumbnail_verify`.
- **Safety class:** read-only.
- **Acceptance:** `srcset_verify` returns non-empty srcset for a multi-size
  image; `responsive_image_audit` flags an image with no sizes;
  `missing_sizes_audit` lists attachments missing ≥1 size.
- **Depends on:** 100.3.

### 100.5 — Thumbnail regeneration (reversible write)

- **Goal:** Recreate resized image **files** on disk (the real `wp media
  regenerate`), snapshot-backed.
- **Actions:**
  - `thumbnail_regenerate_attachment` (RW) — snapshot size files → regenerate →
    rollback restores via 100.1.
  - `thumbnail_regenerate` — alias of `_attachment`.
  - `thumbnail_regenerate_batch` (RW, **cursor**) — bounded chunks (default 20),
    per-attachment snapshots, returns continuation cursor; groups under a
    `batch_id`.
- **Safety class:** reversible write; `medium` risk.
- **Rollback:** `MediaSnapshot::restore()` per attachment; batch reversible via
  `batch_id`.
- **Acceptance:** register a new size → `thumbnail_verify` reports it missing →
  regenerate → file exists on disk → rollback reverts → batch over N completes
  via cursor.
- **Depends on:** 100.1, 100.3.

---

## Priority 3 — Generation & Optimization (GD/Imagick, WebP first)

### 100.6 — WebP audit + generate (additive; cheap rollback)

- **Goal:** Generate `.webp` alongside originals using GD/Imagick (no binaries).
- **Actions:**
  - `webp_audit` (R) — library WebP coverage %.
  - `webp_verify` (R) — for one attachment, `.webp` present + ≤ original size.
  - `webp_generate` (RW) — create `.webp` for an attachment's sizes; rollback =
    delete generated files (original untouched).
  - `webp_generate_batch` (RW, cursor) — chunked; `batch_id`.
- **Capability gate:** requires WebP encode support (from 100.3); else
  `wpcc_image_lib_unavailable` (fail closed, never fatal).
- **Safety class:** reversible write, `medium`.
- **Acceptance:** generate → `.webp` exists; verify smaller-or-equal; rollback
  removes generated files, original intact; audit reports coverage; unsupported
  env returns the clean capability error.
- **Depends on:** 100.3.

### 100.7 — Image optimization (snapshot-backed; `keep_original=true` default)

- **Goal:** Re-encode images at a quality target via GD/Imagick to reduce file
  size **without** changing dimensions.
- **Actions:**
  - `image_compression_audit` (R) — oversized originals / savings estimate.
  - `image_quality_audit` (R) — quality/format hygiene.
  - `image_optimize` (RW) — `keep_original=true` (default) snapshots original +
    sizes first → reversible. `keep_original=false` → **destructive**, requires
    DestructiveGuard (phrase+reason+target).
  - `image_optimize_batch` (RW/D, cursor) — inherits the item's class; `batch_id`.
- **Safety class:** `high`; reversible when `keep_original=true`, destructive
  otherwise.
- **Acceptance:** audit reports candidates; optimize (keep_original) reduces size,
  dimensions unchanged; rollback restores bytes; `keep_original=false` without
  confirmation returns confirmation-required (not executed).
- **Depends on:** 100.1, 100.3.

---

## Priority 4 — Usage & Cleanup (audit-first; destructive last)

### 100.8 — Usage analysis (read-only)

- **Goal:** Answer "where is this attachment used?" and find dead media.
- **New component:** `MediaUsageResolver` — single-pass scan of post content
  (`<img>`, `[gallery]`, block attrs), `_thumbnail_id`, Woo
  `_product_image_gallery`, ACF image/gallery values, Elementor `_elementor_data`,
  term meta, and option-stored IDs (site logo/custom header).
- **Actions (all read-only):** `media_usage_scan`, `media_usage_report`,
  `unused_media_find`, `orphaned_media_find` (file missing on disk).
- **Safety class:** read-only.
- **Acceptance:** an attachment used as featured + in content reports both sites;
  a fresh unreferenced upload appears in `unused_media_find`; an attachment with
  a deleted file appears in `orphaned_media_find`.
- **Depends on:** 100.3. **Ships before any cleanup (audit-first).**

### 100.9 — Unused/orphaned cleanup (destructive; guarded)

- **Goal:** Remove dead media safely.
- **Action:** `unused_media_cleanup` (D) — re-runs the 100.8 usage check, snapshots,
  then **trashes** (never force) candidates; DestructiveGuard
  (phrase+reason+target) required in every mode; rollback = untrash.
- **Safety class:** destructive; `high` + DestructiveGuard.
- **Acceptance:** cleanup without confirmation → confirmation-required; with
  confirmation → candidates trashed (not force-deleted); rollback untrashes;
  cleanup refuses to touch any attachment the usage resolver still finds in use.
- **Depends on:** 100.8 (must confirm "unused" at execution time, not stale input).

---

## Deferred / backlog (not in P1–P4; explicit follow-ups)

- **AVIF** generation (after WebP proven).
- **Cross-runtime delegating writes** — `product_image_set`, `gallery_image_*`,
  `acf_image_field_set`, `acf_gallery_field_update`, `elementor_image_replace`:
  audit-only mirrors first (`product_image_audit`, `acf_image_audit`,
  `elementor_image_audit`), delegation to the owning runtimes later.
- **`media_duplicate`** and metadata thin-aliases (`alt_text_update`, etc.) —
  convenience wrappers over existing `media_manage`.
- **`report_media`** Reporting-runtime report fed by the 100.x audits.
- Converge legacy `media_import` into `media_upload`.

---

## Build order (summary)

```
P1  100.1 MediaSnapshot service ──► 100.2 fix media_replace
P2  100.3 media_enhance + capabilities + size audit
        ├► 100.4 responsive audit (read)
        └► 100.5 thumbnail regenerate (snapshot-backed write)
P3  100.6 WebP audit+generate (additive) ──► 100.7 optimize (snapshot/keep_original)
P4  100.8 usage analysis (read) ──► 100.9 cleanup (destructive, guarded)
```

Sequencing honors **audit-first** (reads before writes, writes before
destructive) and **dependency-first** (snapshot service before any file
mutation; usage resolver before cleanup). Each box is a standalone tested commit.

---

*No code written. Awaiting review before starting 100.1.*
