# PROGRAM-4.2 — Media Metadata Rollback · Implementation Report

> **Date:** 2026-06-23 · **Branch:** `program-4.2-media-metadata` (from P4.0 `2234dcc`; P4.1 excluded). **No commit yet / no push / no deploy.**
> **Design:** [`PROGRAM-4.2-DESIGN.md`](PROGRAM-4.2-DESIGN.md).

## What was built
**New `includes/Rollback/MediaFieldAccessor.php` (`FieldAccessor`):** drives the P4.0 core over attachment metadata, which is a **mix of post columns and post meta**:
- title→`post_title`, caption→`post_excerpt`, description→`post_content` (columns); alt→`_wp_attachment_image_alt` (meta).
- Dispatches each backing key to the right primitive: column writes via `wp_update_post`, meta via `*_post_meta`; column reads via `get_post_field(...,'raw')`. Columns always "exist" (the post does), so existence fidelity (absent→delete / empty→restore-empty) is meaningful only for `alt`.

**Refactored `includes/Operations/MediaRuntimeManager.php` (metadata `update` action only):**
- `update_media()`: captures **only touched** fields (those present in payload among title/caption/description/alt) via `RollbackDelta::capture(...)` **before** the writes (capture was already pre-write here — no DEF-1), runs the existing writes unchanged, reads post-write `after`, and persists a **v2 delta** via new `store_metadata_delta()` (same `wpcc_media_rollbacks` option; adds `version:2`+`fields`). `rollback_id` semantics unchanged.
- New shared `restore_metadata_record()`: v2 (`fields`) → `RollbackDelta::restore(new MediaFieldAccessor(), …)`; legacy (`before_state`) → existing `restore_metadata()` and report `complete`.
- New `metadata_rollback_result()`: builds success / `wpcc_rollback_conflict|partial` envelopes (complete-only is a clean success).
- **Both** restore entry points updated to an early `update` branch using the shared helper, with **complete-only** mark-applied + truthful `status/restored_fields/skipped_fields` audit:
  - `rollback()` (public; `OperationExecutor`/change_history dispatch) → returns `media_rollback` envelope.
  - `restore_media()` (the `media_restore` action) → returns `media_restore` envelope.
- The old `update` cases were removed from `rollback()`'s switch and `restore_media()`'s if-chain (now handled by the early branch). `restore_metadata()` is **retained** for legacy records.

## Scope fidelity (what was NOT touched)
- `store_rollback()` and the `upload`/`replace`/`delete`/`featured_image_*` actions — **byte-identical** (the `update` action simply stops calling `store_rollback`). Confirmed by diff: no `MediaSnapshot`/replace/upload/delete/featured logic changed (only a `$before` null-guard added).
- `MediaSnapshot` (file-byte path), generated sizes, thumbnails — untouched.
- No SEO/Settings/other runtime, no registry/MCP/Schema/REST/UI.

## Why both restore paths had to change (correctness)
A v2 record has no `before_state`; the **pre-existing `restore_media()` path** would have written empty strings for all fields on a v2 record → data loss. Both `rollback()` and `restore_media()` now share `restore_metadata_record()`, which handles v2 and legacy. (No DEF-style new defects were discovered in the metadata path; capture was already pre-write.)

## Diff stat (vs P4.0 base)
```
 includes/Operations/MediaRuntimeManager.php | 127 +++++++++++++++--- (+115 / −12)
 includes/Rollback/MediaFieldAccessor.php    | new
 tests/test-media-metadata-rollback-delta.sh | new
```
