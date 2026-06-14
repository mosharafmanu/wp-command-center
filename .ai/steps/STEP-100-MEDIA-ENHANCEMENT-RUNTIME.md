# STEP 100 — Media Enhancement Runtime (Design)

**Status:** Design & planning only. No code in this step.
**Author role:** Lead architect, WP Command Center.
**Goal:** Close the gap between what an expert WordPress developer does for media
work over SSH/WP-CLI/SFTP and what an AI agent can safely do through WP Command
Center — with full REST + MCP parity, one shared runtime, and the existing
safety stack (Security Modes, Approval, Rollback, Snapshot, Audit).

---

## 1. Current Capability Audit

### 1.1 What exists today

Two operations cover media:

| Operation | Handler | Purpose |
|-----------|---------|---------|
| `media_manage` | `MediaRuntimeManager` (`includes/Operations/MediaRuntimeManager.php`, 525 lines) | List/get/search, upload, update metadata, replace, delete, restore, featured image set/remove, regenerate attachment metadata. |
| `media_import` | `MediaImport` | Import a single remote image (legacy, predates `media_manage`; overlaps `media_upload`). |

`MediaRegistry` (`includes/Operations/MediaRegistry.php`) declares action
constants, `ACTION_RISK`, and `ACTION_APPROVAL`.

### 1.2 Existing operations (actions of `media_manage`)

| Action | Aliases | Reads/Writes | Rollback record action |
|--------|---------|--------------|------------------------|
| `media_list` | — | read | — |
| `media_get` | — | read | — |
| `media_search` | — | read | — |
| `media_upload` | — | write (sideload) | `upload` |
| `media_update` | — | write (title/alt/caption/description) | `update` |
| `media_replace` | — | write (sideload over existing ID) | `replace` ⚠️ |
| `media_delete` | — | write (trash or force) | `delete` (trash only) |
| `media_restore` | — | write (applies a rollback record) | — |
| `featured_image_assign` | `media_set_featured` | write (`set_post_thumbnail`) | `featured_image_assign` |
| `featured_image_remove` | `media_remove_featured` | write (`delete_post_thumbnail`) | `featured_image_remove` |
| `media_regenerate_metadata` | — | write (`wp_generate_attachment_metadata`) | none |

### 1.3 REST routes

- `POST /operations/media_manage/run` — scope `full`, `require_write`.
- `POST /operations/media_manage/rollback` — scope `full`, `require_write`.
- `POST /operations/media_import/run` — scope `full` (legacy).

### 1.4 MCP operations

`media_manage` is enumerated by the MCP server from `OperationRegistry`
(`tools/list` / `tools/call`) — full parity, no MCP-specific code. `media_import`
likewise. Errors surface as MCP `isError` (STEP 89).

### 1.5 Rollback support

- Storage: `wpcc_media_rollbacks` option, capped at 100, one-shot
  (`rollback_applied` guard). Carries `session_id`/`task_id`.
- Reversible: `upload` (delete the new attachment), `update` (restore
  title/alt/caption/description from snapshot), `delete` (untrash — soft delete
  only), `featured_image_assign`/`featured_image_remove` (restore prior
  thumbnail).
- Dispatched via the dedicated `media_manage/rollback` route **and** (since
  STEP 97) the unified `OperationExecutor::rollback()` dispatcher.

### 1.6 Approval support

`media_upload`, `media_replace`, `media_delete`, `media_restore` →
`requires_approval`. `media_delete` with `force:true` is additionally classified
`DELETE_MEDIA` by `DestructiveGuard` (STEP 84) and needs the
phrase+reason+target confirmation handshake in **every** mode.

### 1.7 Security model

- Capability: `media_manage → media.manage` (`CapabilityRegistry::OPERATION_MAP`).
  `media_import → content.manage`.
- Risk gating via `OperationRegistry.action_risks` × `SecurityModeManager`
  (Developer auto-runs; Client/Enterprise gate medium/high).
- Inputs sanitised (`esc_url_raw`, `sanitize_text_field`, `sanitize_textarea_field`).
- Uploads via `download_url` + `media_handle_sideload` (HTTP(S) source URLs only;
  no arbitrary local-path ingestion).
- All actions audited (`media.*`, `featured_image.*`).

### 1.8 Capability matrix (current)

| Capability | Status | Notes |
|------------|--------|-------|
| List / get / search media | ✅ | Paged list, mime filter, keyword search. |
| Upload from URL | ✅ | `media_upload` + legacy `media_import`. |
| Upload from local file / base64 | ❌ | Only remote URL sideload. |
| Update alt / caption / title / description | ✅ | `media_update`, rollback-capable. |
| Replace file | ⚠️ Partial | `media_replace` runs, but **rollback is a no-op** — see bug below. |
| Delete (trash) | ✅ | Rollback = untrash. |
| Delete (force) | ✅ | DestructiveGuard-gated; not rollbackable. |
| Set / remove featured image | ✅ | Rollback-capable. |
| Replace featured image | ⚠️ Implicit | Achieved by `featured_image_assign` over an existing thumbnail; no first-class verify. |
| Regenerate **attachment metadata** | ✅ | `media_regenerate_metadata` (rebuilds `_wp_attachment_metadata`). |
| Regenerate **thumbnail files** | ❌ | Does **not** recreate the resized image files on disk. |
| Bulk / batch any of the above | ❌ | All actions are single-attachment. |
| Image-size inventory / audit | ❌ | — |
| srcset / responsive verification | ❌ | — |
| Optimization / compression | ❌ | — |
| WebP / AVIF generation | ❌ | — |
| Usage scan / unused / orphaned detection | ❌ | — |
| Duplicate detection / dedup | ❌ | — |
| WooCommerce gallery / product image | ⚠️ Elsewhere | Via `woocommerce_manage` (product images), not media runtime. |
| ACF image / gallery field | ⚠️ Elsewhere | Via `acf_manage`. |
| Elementor image replace | ✅ Elsewhere | `elementor_manage / elementor_update_image` (STEP 96). |

### 1.9 Bugs / limitations found during audit

1. **`media_replace` rollback is a silent no-op.** `store_rollback()` accepts
   `replace` (it is in `ROLLBACKABLE`) and returns a `rollback_id`, but **neither
   `rollback()` nor `restore_media()` has a `replace` case**. The agent is told
   the replace is reversible; it is not. Worse, even a `replace` case could not
   restore the file: `before_state` snapshots only `format_media()` metadata, not
   the **original file bytes**, which `media_handle_sideload` has already
   overwritten. → True replace rollback requires **file-level snapshot**
   (Snapshot System), a core driver for STEP 100.
2. **No thumbnail file regeneration.** `media_regenerate_metadata` rebuilds the
   metadata array but does not write resized files. After changing image sizes a
   developer runs `wp media regenerate`; the agent cannot.
3. **No batch.** Every action is one attachment. Agencies operate on hundreds.
4. **`media_import` duplicates `media_upload`.** Two upload paths; should
   converge (deprecate `media_import` in favour of `media_upload`).
5. **`file_size`/`filesize()` on missing files** can warn; enhancement runtime
   should null-guard file stats.
6. **No capability detection.** Nothing reports whether GD/Imagick, WebP, or
   resize support is present — required before offering optimization/WebP.

---

## 2. Gap Analysis vs Real Agency Workflows

| Agency workflow | Current | Gap |
|-----------------|---------|-----|
| Create featured image | ✅ upload + assign | First-class `featured_image_replace`/`_verify` missing. |
| Replace featured image | ⚠️ | Works via assign; no verify/rollback story for the file. |
| Bulk image updates (alt, etc.) | ❌ | No batch; agencies bulk-fix alt text for SEO. |
| Alt-text management | ⚠️ | Single update only; no **audit of missing alt** across library. |
| Caption / title / description | ✅ single | No batch. |
| Image SEO | ❌ | No missing-alt audit, filename/title hygiene, or size-vs-usage report. |
| Image audits | ❌ | No library-wide health/inventory. |
| WooCommerce image management | ⚠️ | Lives in `woocommerce_manage`; no unified media-side audit. |
| ACF image management | ⚠️ | Lives in `acf_manage`. |
| Responsive image verification | ❌ | No srcset / missing-size audit. |
| Regenerate thumbnails | ❌ | Metadata only, no files. |
| WebP generation | ❌ | None. |
| Image optimization | ❌ | None. |
| Image cleanup | ❌ | None. |
| Unused media detection | ❌ | None. |

**Net:** the runtime is strong on **metadata + single-item CRUD + featured
images**, and absent on **files-on-disk operations, library-wide analysis, and
batch** — exactly the work that today still forces SSH/WP-CLI.

---

## 3. SSH / WP-CLI Capability Comparison

Classification key: **AS** already supported · **PS** partially supported ·
**NS** not supported · **SBS** should be supported (target of STEP 100) ·
**SNS** should *not* be supported (out of scope, with reason).

| Developer action (SSH/WP-CLI/SFTP/Admin) | Today | Target | Why |
|------------------------------------------|-------|--------|-----|
| `wp media regenerate` (thumbnail files) | NS | **SBS** | Core agency need after theme/size changes. Reversible via snapshot of size files. |
| Rebuild attachment metadata (`wp media regenerate --only-missing` metadata) | AS | AS | `media_regenerate_metadata`. |
| Image metadata fixes (alt/caption/title) | PS | **SBS** (add batch + audit) | Single-item exists; add bulk + missing-alt audit. |
| WebP generation (`imagify`, `webp express`, cwebp) | NS | **SBS** (GD/Imagick) | Additive, low-risk; classify SNS for external binaries. |
| Image compression (jpegoptim/optipng/`imagick`) | NS | **PS→SBS** | In-PHP re-encode (GD/Imagick quality) **SBS**; binary-tool compression **SNS** (needs shell). |
| Remove unused media | NS | **SBS** (destructive) | High value; gate behind usage scan + DestructiveGuard. |
| Audit image sizes (`wp media image-size`) | NS | **SBS** | Pure read; inventory of registered sizes + on-disk presence. |
| Verify srcset generation | NS | **SBS** | Pure read; compute `wp_get_attachment_image_srcset`. |
| Inspect image usage | NS | **SBS** | Read scan across post content, meta, featured, Woo gallery, ACF, Elementor, term meta. |
| Bulk image updates | NS | **SBS** | Batch executor with cursor + per-item rollback. |
| Media library cleanup | NS | **SBS** (destructive) | Orphaned/unused removal behind confirmation. |
| Upload from local server path / SFTP drop | NS | **SNS** | Arbitrary local-path ingestion is a traversal/security risk; keep to vetted URL sideload + (optional) uploads-dir-scoped intake. |
| Arbitrary shell image tooling (ImageMagick CLI flags) | NS | **SNS** | Shell exec out of scope; runtime stays WordPress-API/GD/Imagick-bound. |
| Edit raw files via SFTP | NS | **SNS** | That is the File/Patch runtime's job, not media. |
| Change `uploads` directory structure / `ms-files` | NS | **SNS** | Infra-level; high blast radius, no safe rollback. |

---

## 4. Runtime Architecture

### 4.1 Principles

- **One shared runtime per concern; zero transport-specific business logic.**
  Handlers return plain arrays; REST and MCP are thin adapters (as today).
- **Single source of truth via delegation, not duplication.** Cross-runtime
  image ops call the owning runtime (the STEP 95 menu-delegation pattern):
  product images → `woocommerce_manage`, ACF image/gallery → `acf_manage`,
  Elementor images → `elementor_manage`. The media runtime *audits* them but does
  not re-implement their writes.
- **Files-on-disk mutations are snapshot-backed.** Any op that rewrites or
  deletes files first captures a **file snapshot** (Snapshot System) so rollback
  restores bytes, not just metadata — fixing the audited `media_replace` gap.
- **Batch = cursor + chunk, never unbounded.** Library-wide ops execute in
  bounded chunks and return a continuation cursor, so a single REST/MCP call
  never risks a PHP timeout. Long jobs compose with the Workflow runtime.
- **Capability detection before capability offering.** The runtime probes
  GD/Imagick/WebP/AVIF support and returns it (`media_enhance_capabilities`);
  unsupported ops fail closed with a clear code, never a fatal.

### 4.2 Operation decomposition (which runtime owns what)

Rather than one mega-operation, STEP 100 adds **two** operations and **extends**
one, and **delegates** the cross-runtime image ops:

| Concern | Operation | Disposition |
|---------|-----------|-------------|
| Core media CRUD, featured, metadata | `media_manage` | **Extend** (add `media_duplicate`; fix `media_replace` rollback via snapshot; add `media_replace_verify`). |
| Thumbnails, sizes, responsive, optimization, WebP, usage/cleanup, audits | `media_enhance` | **New** runtime `MediaEnhancementRuntimeManager` + `MediaEnhancementRegistry`. |
| Batch wrapper for any per-item action | `media_enhance` (`*_batch` actions) | New, cursor-based. |
| Product / gallery images | `woocommerce_manage` | **Delegate** (audit-only mirror in `media_enhance`). |
| ACF image / gallery fields | `acf_manage` | **Delegate** (audit-only mirror). |
| Elementor images | `elementor_manage` | **Delegate** (already `elementor_update_image`). |

> Rationale for splitting `media_enhance` out of `media_manage`: enhancement ops
> are heavier (filesystem, image libraries, library-wide scans), batch-oriented,
> and analytically distinct. Keeping CRUD lean preserves the existing contract
> and lets `media_enhance` carry the capability-detection + snapshot machinery
> without bloating the hot CRUD path.

### 4.3 Shared services consumed

- `Snapshot System` (`snapshot_manage`) — file snapshots before file mutations.
- `Rollback Engine` — `wpcc_media_rollbacks` + unified `OperationExecutor::rollback()`.
- `DestructiveGuard` — force-delete / cleanup confirmation.
- `SecurityModeManager` — risk gating.
- `AuditLog` — `media.*` / `media_enhance.*` events.
- `Reporting Runtime` — `media_enhance` audits feed `report_content` / a future
  `report_media`.

### 4.4 Usage-scan resolver (core new component)

A `MediaUsageResolver` answers "where is attachment N used?" by scanning, in one
pass: post content (`<img>`, `[gallery]`, block attrs), `_thumbnail_id` meta,
Woo `_product_image_gallery`, ACF image/gallery field values, Elementor
`_elementor_data`, term meta, and option-stored IDs (logo/custom_header). This
resolver underpins `media_usage_scan`, `unused_media_find`, `orphaned_media_find`,
and the cleanup safety checks. Read-only; cache per request.

---

## 5. Proposed Operation Registry

All operations carry REST + MCP parity. Safety class: **R** read-only ·
**RW** reversible write · **D** destructive. "Rollback" = restore mechanism.

### 5.1 `media_manage` (extended)

| Action | Class | Rollback |
|--------|-------|----------|
| `media_upload` *(existing)* | RW | delete new attachment |
| `media_update` *(existing)* | RW | metadata snapshot |
| `media_delete` *(existing)* | RW/D | untrash (soft) / DestructiveGuard (force) |
| `media_replace` *(fix)* | RW | **file snapshot** + metadata (new) |
| `media_replace_verify` *(new)* | R | — |
| `media_duplicate` *(new)* | RW | delete the duplicate |
| `media_search` / `media_list` / `media_get` *(existing)* | R | — |
| `featured_image_set` / `_remove` *(existing)* | RW | restore prior thumbnail |
| `featured_image_replace` *(new, = set over existing + verify)* | RW | restore prior thumbnail |
| `featured_image_verify` *(new)* | R | — |
| `alt_text_get` / `alt_text_update` / `caption_update` / `title_update` / `description_update` *(new thin aliases of media_update)* | R / RW | metadata snapshot |

### 5.2 `media_enhance` (new)

**Image size management**

| Action | Class | Rollback |
|--------|-------|----------|
| `image_sizes_list` | R | — |
| `image_size_usage_audit` | R | — |
| `image_size_recommendations` | R | — |
| `image_size_verify` | R | — |

**Thumbnail runtime**

| Action | Class | Rollback |
|--------|-------|----------|
| `thumbnail_verify` | R | — |
| `thumbnail_regenerate_attachment` | RW | snapshot generated size files for the attachment |
| `thumbnail_regenerate` *(alias of _attachment)* | RW | as above |
| `thumbnail_regenerate_batch` | RW (batch) | per-attachment size-file snapshots; cursor |

**Responsive images**

| Action | Class | Rollback |
|--------|-------|----------|
| `srcset_verify` | R | — |
| `responsive_image_audit` | R | — |
| `missing_sizes_audit` | R | — |

**Optimization runtime**

| Action | Class | Rollback |
|--------|-------|----------|
| `image_compression_audit` | R | — |
| `image_quality_audit` | R | — |
| `image_optimize` | RW | **file snapshot of original + sizes** (re-encode in place) |
| `image_optimize_batch` | RW (batch) | per-item snapshot; cursor |

**WebP runtime** (additive → cheap rollback = delete generated files)

| Action | Class | Rollback |
|--------|-------|----------|
| `webp_audit` | R | — |
| `webp_verify` | R | — |
| `webp_generate` | RW | delete generated `.webp` |
| `webp_generate_batch` | RW (batch) | delete generated `.webp`; cursor |

**Usage analysis** (all read-only)

| Action | Class | Rollback |
|--------|-------|----------|
| `media_usage_scan` | R | — |
| `media_usage_report` | R | — |
| `unused_media_find` | R | — |
| `orphaned_media_find` | R | — |

**Cleanup** (destructive — guarded)

| Action | Class | Rollback |
|--------|-------|----------|
| `unused_media_cleanup` | D | snapshot + trash (not force) → untrash |

**Capability + cross-runtime audits**

| Action | Class | Rollback | Notes |
|--------|-------|----------|-------|
| `media_enhance_capabilities` | R | — | GD/Imagick/WebP/AVIF probe |
| `product_image_audit` | R | — | reads Woo; **set/gallery ops delegate to `woocommerce_manage`** |
| `acf_image_audit` | R | — | reads ACF; **writes delegate to `acf_manage`** |
| `elementor_image_audit` | R | — | reads Elementor; **writes delegate to `elementor_manage`** |

### 5.3 Cross-runtime write delegation (no duplication)

`product_image_set`, `gallery_image_add/remove`, `acf_image_field_set`,
`acf_gallery_field_update`, `elementor_image_replace` are **not** re-implemented.
They are exposed (if desired) as thin delegators that forward to the owning
runtime and tag `delegated_from: media_enhance` — mirroring how
`site_builder_manage` delegates menus to `menu_manage`. This keeps Woo/ACF/
Elementor as the single source of truth for their own writes + rollback.

---

## 6. Security Model

| Layer | Rule for STEP 100 |
|-------|-------------------|
| Capability | `media_enhance → media.manage` (reuse existing cap; no new operation_map churn beyond +1). Audit-only cross-runtime reads require `media.manage`; delegated writes additionally require the target runtime's cap (woocommerce/acf/content) — enforced because delegation routes through `OperationExecutor` with the caller's token. |
| Risk × Security Mode | Reads → `diagnostic` (no approval). Reversible writes → `medium` (Client/Enterprise gate). In-place optimize + cleanup → `high`. Batch inherits the max risk of its item action. |
| DestructiveGuard | `unused_media_cleanup` and any `force` delete require phrase+reason+target in every mode. `image_optimize` in place is **reversible** (snapshot) so it is `high` + approval but **not** DestructiveGuard — unless `keep_original:false` drops the snapshot, which escalates it to destructive. |
| Capability detection | Optimize/WebP probe GD/Imagick first; missing support → `wpcc_image_lib_unavailable` (fail closed, never fatal). |
| Input safety | URL sideload only (no local paths); attachment IDs validated to `attachment` post type; batch sizes clamped; cursors are server-issued opaque tokens. |
| Audit | Every action emits `media_enhance.<action>` with target IDs, counts, snapshot/rollback IDs, and (for batch) cursor + processed/total. |
| Parity | One handler; REST (`require_read` for read actions via a read route, `require_write` for write route) and MCP both call it. No transport-specific logic. |

---

## 7. Rollback Model

| Op group | Reversibility | Mechanism |
|----------|---------------|-----------|
| Metadata (alt/caption/title/description, update) | Full | metadata snapshot in `wpcc_media_rollbacks` (existing). |
| Featured image set/remove/replace | Full | prior `_thumbnail_id` snapshot (existing). |
| Upload / duplicate | Full | delete the created attachment. |
| **Replace** | Full (**new**) | **file snapshot** of original + size files before sideload; rollback restores bytes + metadata. *(Fixes audited no-op.)* |
| Thumbnail regenerate | Full | snapshot the attachment's generated size files (and `_wp_attachment_metadata`) before regen; rollback restores them. |
| WebP generate | Full (cheap) | record generated `.webp` paths; rollback deletes them (additive op, original untouched). |
| Image optimize (in place) | Full *if* `keep_original` | snapshot original + sizes; rollback restores. With `keep_original:false` → **destructive**, no rollback, DestructiveGuard required. |
| Unused/orphaned cleanup | Full (soft) | snapshot + **trash** (never force); rollback untrashes. Force purge is a separate destructive, non-rollbackable, DestructiveGuard-gated path. |
| Batch ops | Per-item | each item writes its own rollback record; a `batch_id` groups them so the whole batch can be reversed (reverse order) via the Workflow `on_failure: rollback` pattern or a `media_enhance_batch_rollback` action. |
| All read/audit ops | N/A | no writes. |

**New storage:** extend `wpcc_media_rollbacks` records with `snapshot_id`
(Snapshot System reference) and optional `batch_id`. Keep the 100-cap +
one-shot + unified-dispatcher contract. File snapshots live in the Snapshot
System; the rollback record only references them.

---

## 8. Acceptance Test Plan

A new `tests/test-media-enhancement-step100.sh` (REST + MCP parity, mirroring the
STEP 90–98 harness). Coverage groups:

**A. Capability + sizes (read)**
1. `media_enhance_capabilities` reports GD/Imagick/WebP booleans.
2. `image_sizes_list` returns all registered sizes (incl. theme `add_image_size`).
3. `image_size_usage_audit` counts attachments per size present on disk.
4. `image_size_verify` flags an attachment missing a registered size.

**B. Upload → featured → frontend (lifecycle, reuses media_manage)**
5. Upload an image (`media_upload`) → attachment created.
6. `featured_image_set` on a test post → `get_post_thumbnail_id` matches.
7. Front-end of the post returns 200 and references the image.
8. `featured_image_verify` reports present + correct size.

**C. Replace + true rollback (the audited fix)**
9. `media_replace` with a new source → file bytes + dimensions change.
10. `media_replace_verify` confirms new file.
11. Rollback → **original file bytes restored** (hash equals pre-replace),
    metadata restored. *(Asserts the no-op bug is fixed.)*

**D. Thumbnails**
12. Register a new image size, then `thumbnail_verify` → reports the size missing
    on existing attachment.
13. `thumbnail_regenerate_attachment` → size file now exists on disk.
14. Rollback → regenerated size files reverted to snapshot.
15. `thumbnail_regenerate_batch` over N attachments → cursor completes; all sized.

**E. Responsive**
16. `srcset_verify` returns a non-empty srcset for a multi-size image.
17. `responsive_image_audit` flags an image with no generated sizes.
18. `missing_sizes_audit` lists attachments missing ≥1 registered size.

**F. WebP**
19. `webp_generate` (skip with clear code if no WebP support) → `.webp` exists.
20. `webp_verify` confirms presence + smaller-or-equal size.
21. Rollback → generated `.webp` removed; original untouched.
22. `webp_audit` reports coverage % across the library.

**G. Optimization**
23. `image_compression_audit` reports oversized originals.
24. `image_optimize` (keep_original) → file size reduced, dimensions unchanged.
25. Rollback → original bytes restored.
26. `image_optimize` with `keep_original:false` → requires DestructiveGuard
    confirmation; without it, returns confirmation-required (not executed).

**H. Usage & cleanup**
27. `media_usage_scan` for an attachment used as featured + in content →
    reports both usage sites.
28. `unused_media_find` includes a freshly uploaded, unreferenced image.
29. `orphaned_media_find` includes an attachment whose file is missing on disk.
30. `unused_media_cleanup` without confirmation → confirmation-required.
31. With confirmation → image trashed (not force); rollback untrashes it.

**I. Cross-runtime audits (delegation, no duplication)**
32. `product_image_audit` flags a Woo product with no image (read-only).
33. `acf_image_audit` flags an empty ACF image field.
34. `elementor_image_audit` flags an Elementor image widget with a broken URL.
35. A delegated write (e.g. `product_image_set`) tags `delegated_from:
    media_enhance` and the actual write/rollback is owned by `woocommerce_manage`.

**J. Safety & parity**
36. Each read action callable over **MCP** with identical output (parity).
37. Each write action respects Security Mode (medium/high pend in Client mode).
38. Structured errors: invalid action, missing attachment, lib-unavailable,
    bad cursor.
39. Regression: full bash suite shows **0 net-new** failures (24 baseline).

Target: **~39 assertions, all green**, plus 0 net-new regression — matching the
STEP 89–98 bar.

---

## 9. Deliverables Recap & Build Sequencing (for the implementation step)

1. **Capability audit** — §1 (incl. the `media_replace` rollback no-op bug).
2. **Gap analysis** — §2.
3. **SSH/WP-CLI comparison** — §3 (AS/PS/NS/SBS/SNS).
4. **Runtime architecture** — §4 (shared runtime, delegation, snapshot-backed
   files, cursor batch, capability detection, `MediaUsageResolver`).
5. **Operation registry** — §5 (`media_manage` extended + new `media_enhance`).
6. **Security model** — §6.
7. **Rollback model** — §7 (snapshot integration; `snapshot_id`/`batch_id`).
8. **Acceptance test suite** — §8.
9. **This document** — `STEP-100-MEDIA-ENHANCEMENT-RUNTIME.md`.

**Suggested implementation order when the build is approved** (each its own
tested, locally-committed step, per the roadmap discipline):

1. Fix `media_replace` rollback via Snapshot System (closes a correctness bug;
   small, high-value).
2. `media_enhance` read/audit core + `MediaUsageResolver` + capability probe
   (all read-only, zero blast radius).
3. Thumbnail regenerate (single → batch) with size-file snapshots.
4. WebP generate (additive, cheap rollback).
5. Image optimize (snapshot-backed; destructive variant guarded).
6. Unused/orphaned cleanup (DestructiveGuard).
7. Cross-runtime delegating writes + audits.

### Open decisions for the owner before implementation

- **Optimization scope:** GD/Imagick in-PHP re-encode only (no shell binaries)?
  Recommended yes (SNS for binaries) to keep the no-SSH guarantee.
- **AVIF:** include alongside WebP where Imagick supports it, or defer? Recommend
  defer to a follow-up; WebP first.
- **Batch ceiling:** default chunk size and max attachments per call (recommend
  chunk 20, hard cap via cursor; long runs compose with Workflow).
- **`keep_original` default for optimize:** recommend `true` (reversible) by
  default; `false` requires explicit DestructiveGuard confirmation.
- **Expose delegating writes** (`product_image_set`, etc.) now, or audit-only
  first and add delegation later? Recommend audit-only first.

---

*No code was written in STEP 100. This is the architecture and plan; the build
proceeds only on your go-ahead, in the sequenced sub-steps above.*
