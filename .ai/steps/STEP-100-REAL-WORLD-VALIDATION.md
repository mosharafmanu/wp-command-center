# STEP 100 — Real-World Validation Plan (Media Subsystem)

**Mode:** Stabilization & real-world validation. **No new features. No deploy. No
destructive cleanup execution yet.**

This plan validates the *complete* STEP 100 media subsystem end-to-end against a
realistic, multi-plugin WordPress install. It is written to be executed by a human
operator (or a supervised AI agent) on a **staging** site. It exists to answer one
question before any production exposure:

> **Can WPCC ever (a) mark a genuinely-used image as `unused`, or (b) fail to
> reverse a cleanup, thumbnail, WebP, or optimize operation byte-for-byte?**

If the answer is provably "no" within the tested surface, the subsystem is
**SAFE FOR STAGING**. The risk verdict is at the end of this document.

---

## 0. Subsystem under test (reconstructed STEP 100 state)

Core roadmap **100.1 → 100.9 COMPLETE locally, NOT deployed.** `operation_map`
stays **33** (all media-enhancement work is *actions* on the `media_enhance`
operation; `media_manage` carries the snapshot actions).

| Sub-step | Component / surface | Class | Reversible |
|---|---|---|---|
| 100.1 | `MediaSnapshot` (byte store under `uploads/wpcc-media-snapshots/`) + `media_snapshot_create/verify/list/restore` | safety primitive | n/a (it *is* the reversal) |
| 100.2 | `media_replace` rollback (snapshot before sideload) | RW | yes |
| 100.3 | `media_enhance` foundation + capability probe + `size_verify` | R | n/a |
| 100.4 | `srcset_verify`, `responsive_image_audit`, `missing_sizes_audit`, `image_size_context_audit` | R | n/a |
| 100.5 | `thumbnail_regenerate[_attachment]`, `thumbnail_regenerate_batch`, `thumbnail_verify` | RW | yes (snapshot) |
| 100.6 | `webp_audit`, `webp_verify`, `webp_generate`, `webp_generate_batch` | RW (additive) | yes (deletes sidecar) |
| 100.7 | `image_optimize_audit`, `image_optimize_verify`, `image_optimize`, `image_optimize_batch` | RW | yes (snapshot) |
| 100.8 | `MediaUsageResolver`; `media_usage_scan`, `media_usage_report`, `unused_media_find`, `orphaned_media_find` | R | n/a |
| 100.9 | `unused_media_cleanup` (DestructiveGuard `CLEANUP_MEDIA`; Snapshot→Trash→Verify; **never permanent**) | RW destructive | yes (untrash + bytes) |

**Reversal route (all RW media-enhance actions):**
`POST /operations/media_enhance/rollback { rollback_id }` (also via the unified
`OperationExecutor::rollback` dispatcher and Workflow `on_failure:rollback`).

**Classification contract (the agent-facing safety contract):**
`active` (live host) → `indirect` (draft/pending/trash/revision/loose URL) →
`unused` (zero references; `cleanup_candidate:true`) → `orphaned` (file missing on
disk; independent flag). **Conservative by design: when in doubt, referenced.**
**Only `status==unused` is ever a cleanup target.** Draft-only and revision-only
are `indirect` → never trashed.

---

## 1. Code-grounded reference map (what the resolver actually scans)

This is the spine of every false-positive test below. `MediaUsageResolver::references()`
detects a reference **only** through these channels:

| # | Channel | Matches | Status assigned |
|---|---|---|---|
| 1 | `_thumbnail_id` postmeta (core + Woo + variations) | exact ID | host post_status |
| 2 | `_product_image_gallery` CSV (`FIND_IN_SET`) | exact ID | host post_status |
| 3 | `_elementor_data` postmeta | `"id":N,` or `"id":N}` | host post_status |
| 4 | `post_content` (any non-`inherit`/`auto-draft` post) | `wp-image-N`, `"id":N`, or any **basename** | host post_status |
| 4b | revisions (`post_type=revision`) | same patterns | **always `indirect`** |
| 5 | ACF postmeta with `_{key}`→`field_*` companion | `= ID` or `LIKE "%\"ID\"%"` | host post_status |
| 6 | ACF options (`options_*` with `_options_*`→`field_*`) | `= ID` or `"%\"ID\"%"` | **active** |
| 7 | `site_icon`, `site_logo` options | exact ID | active |
| 8 | `theme_mods_{stylesheet}` | `custom_logo` ID + basename in blob | active |
| 9 | generic `options` value | **basename** (excludes `_transient*`, `wpcc_*`, already-seen) | indirect |

**Two consequences that drive the test design:**

1. **ID-based detection is exact and narrow** (channels 1,2,3,5,6,7,8-logo). If an
   integration stores an attachment ID *somewhere other than these specific
   keys/patterns*, the ID is invisible.
2. **The basename scan (channels 4 and 9) is the broad safety net.** It over-matches
   (a short/generic basename like `logo.png` or `1.jpg` can match unrelated
   content/options) → that bias is **toward over-protection (false negatives for
   `unused`)**, which is *safe*. The dangerous case is when **neither the ID nor the
   basename appears in any scanned location.**

### 1.1 Confirmed structural blind spots (must be stress-tested for FALSE POSITIVES)

These are places a real, in-use image can have **zero** detected references and be
classified `unused` → become a cleanup candidate. The reversibility net (trash, not
delete + snapshot) is the last line of defense, but each must be surfaced.

| Blind spot | Why invisible | Requested area |
|---|---|---|
| **Plugin/option ID storage** e.g. `woocommerce_placeholder_image` (stores an **ID**, not a URL) | option value is the bare ID; channel 9 only matches **basenames**, not IDs | WooCommerce |
| **ACF blocks (Gutenberg)** image/gallery fields | data lives in block JSON in `post_content` as `"fieldname":id`, not `"id":id`; channel 5 only sees postmeta, channel 4 only matches `"id":N` / `wp-image-N` / basename | ACF |
| **Elementor kit / global settings / theme-builder global assets** stored in `_elementor_page_settings` (and kit post `_elementor_data` only if that post is publish/private) | channel 3 reads `_elementor_data` **only**; `_elementor_page_settings` is not scanned | Elementor |
| **Hardcoded uploads URLs in theme/child-theme PHP, JS, or template files** | resolver never reads files — only DB | Theme |
| **CSS background images cached in page-builder CSS files** (`uploads/elementor/css/…`) | file, not DB (source is `_elementor_data`, so usually OK — but verify) | Elementor/Theme |
| **basename collisions making cleanup *useless* (safe) but worth noting** | generic basename matches unrelated rows → item stays `indirect`/`active` forever | all |

> The cleanup operation **re-runs the same resolver** at execution time, so a
> resolver blind spot is a real cleanup hazard — mitigated only by trash-not-delete
> + snapshot. Every blind-spot test below therefore **also** verifies rollback.

---

## 2. Required staging site setup

**Do NOT validate on production. Do NOT validate on the dev site in-place without a
snapshot.** Stand up a dedicated staging clone.

### 2.1 Environment
- WordPress current stable, separate DB, separate `uploads/`.
- PHP with **GD or Imagick** compiled **with WebP** support (verify via
  `media_enhance { action:"webp_audit" }` → `capability.webp_encode == true`).
- `WP_DEBUG=true`, `WP_DEBUG_LOG=true`. `SCRIPT_DEBUG=true`.
- Confirm `big_image_size_threshold` behavior is default (2560px) unless a test
  overrides it via mu-plugin.

### 2.2 Plugins
- **WooCommerce** (active, sample products incl. variable product + variations).
- **ACF Pro** (field groups: image, gallery, repeater-with-image,
  flexible-content-with-image, options page with image; **plus one ACF Gutenberg
  block** with an image field).
- **Elementor** (free at minimum; Pro if available for theme-builder + global kit).
- **Yoast or Rank Math** present (already used in prior steps) — not central here.
- A block-capable theme **and** a classic theme available to switch between (the dev
  rule expects `hello-elementor` for the Elementor path).

### 2.3 Seed content matrix (create BEFORE any audit)
Tag every seeded image with a recognizable filename prefix so audits are readable,
e.g. `wpcc-core-featured.jpg`, `wpcc-acf-block.jpg`, `wpcc-woo-placeholder.jpg`.

Create at minimum **one image per row** of sections 4.A–4.E below, plus:
- **3 genuinely-orphaned DB rows** (attachment row exists, file deleted on disk).
- **3 genuinely-unused uploads** (uploaded, referenced nowhere) — the *only*
  legitimate cleanup targets.
- **1 image referenced ONLY in a draft**, **1 ONLY in a revision**, **1 ONLY in a
  pending post**, **1 ONLY in a trashed post**.

### 2.4 Tokens / mode
- A **full token** (write) and a **read-only token** (to prove write actions reject
  read tokens — per `require_media_enhance`).
- Test in **developer** mode (auto-run) **and** **client/enterprise** mode (approval
  gate). Cleanup must require the DestructiveGuard handshake in **all** modes.

---

## 3. Data backup requirements (before ANY write or cleanup test)

1. **Full DB dump** (`wp db export` or equivalent) — labeled pre-validation.
2. **Full `uploads/` archive** (tar/zip) — labeled pre-validation.
3. Confirm `uploads/wpcc-media-snapshots/` is writable and has free space ≥ size of
   the largest attachment set you will touch (snapshots copy bytes).
4. Record `wp_options` rows: `wpcc_media_file_snapshots`,
   `wpcc_media_enhance_rollbacks` (the snapshot + rollback stores; capped at 200/100).
5. Snapshot the staging VM/container if available (out-of-band rollback path).

> Rationale: every RW action is internally snapshot-backed, but the validation must
> not *depend* on the feature under test for its own recovery. The DB+uploads backup
> is the independent safety net while we are still proving the feature.

---

## 4. Validation scenarios

Format for every scenario:
**Objective · Setup · Operation · Expected · Failure criteria · Rollback
verification · Cleanup-safety expectation.**

Conventions:
- "scan" = `media_enhance { action:"media_usage_scan", media_id:N }`.
- "rollback" = `POST /operations/media_enhance/rollback { rollback_id }`.
- "byte-for-byte" = pre-op md5 of original file == post-rollback md5; metadata array
  restored; no stray temp / `.wpccopt` / orphan `.webp` files.
- Run **REST and MCP** for at least one scenario per action to confirm parity.

---

### 4.A WordPress Core

#### A1 — Normal upload, full audit & reversible writes
- **Objective:** A standard uploaded image audits correctly and survives
  regenerate/webp/optimize byte-for-byte.
- **Setup:** Upload a 3000px JPEG; let WP generate all sizes.
- **Operation:** `responsive_image_audit` → `thumbnail_verify` → `webp_verify` →
  `image_optimize_verify` → `thumbnail_regenerate(mode=all)` → `webp_generate` →
  `image_optimize(quality=82)`.
- **Expected:** `responsive_ready:true`; regenerate returns `verified:true` with a
  `rollback_id`; webp creates `<file>.webp` sidecars (`verified`); optimize reports
  `bytes_saved>0`, **dimensions unchanged**.
- **Failure criteria:** any dimension change; missing `rollback_id`; originals
  modified by `webp_generate` (md5 must be unchanged); optimize upscales or changes
  mime.
- **Rollback verification:** rollback each `rollback_id` → original md5 restored;
  generated `.webp` deleted; no temp files.
- **Cleanup safety:** scan → `active` (it's referenced once placed in a published
  post); `cleanup_candidate:false`.

#### A2 — Featured image
- **Objective:** A `_thumbnail_id` reference is detected as `active` and protects
  the image from cleanup.
- **Setup:** Set the A1 image as featured on a **published** post.
- **Operation:** scan; then `unused_media_cleanup` (expect refusal).
- **Expected:** scan `by_source.featured_image >= 1`, `status:active`.
- **Failure criteria:** status `unused`/`indirect`; cleanup proceeds.
- **Rollback verification:** n/a (cleanup must refuse → nothing to roll back).
- **Cleanup safety:** cleanup refused `wpcc_media_cleanup_refused` ("still
  referenced (active)").

#### A3 — Gutenberg image block + gallery block
- **Objective:** Block `attrs.id` and `attrs.ids[]` are detected.
- **Setup:** Published page with one `core/image` (id=X) and one `core/gallery`
  (ids include Y).
- **Operation:** scan X and Y.
- **Expected:** source `block`, `status:active` for both.
- **Failure criteria:** either resolves `unused`.
- **Cleanup safety:** both refused.

#### A4 — Classic editor content (`wp-image-N` + bare URL)
- **Objective:** `wp-image-N` class **and** raw `<img src>` basename both detected.
- **Setup:** Classic-editor published post with `<img class="wp-image-Z" src=".../wpcc-core-classic.jpg">`.
- **Operation:** scan Z.
- **Expected:** source `content`, `status:active`.
- **Failure criteria:** `unused`.

#### A5 — Draft-only reference (FALSE-POSITIVE GUARD)
- **Objective:** An image used **only** in a draft is `indirect`, never `unused`.
- **Setup:** Image placed in a **draft** post only.
- **Operation:** scan; `unused_media_find`; attempt `unused_media_cleanup`.
- **Expected:** `status:indirect`, `cleanup_candidate:false`; **not** in
  `unused_media_find`; cleanup refused.
- **Failure criteria:** appears as `unused` / in `unused_media_find` / cleanup
  proceeds → **CRITICAL FAIL (false positive).**
- **Cleanup safety:** must refuse.

#### A6 — Revision-only reference (FALSE-POSITIVE GUARD — known 100.9 fix)
- **Objective:** An image referenced **only** in a past revision is protected.
- **Setup:** Publish a post with image R in content; edit the post to remove R and
  update (R now lives only in the revision). Confirm R is in no live content.
- **Operation:** scan R; `unused_media_find`; attempt cleanup.
- **Expected:** source `revision`, `status:indirect`; not a cleanup candidate;
  cleanup refused.
- **Failure criteria:** `unused` / cleanup proceeds → **CRITICAL FAIL** (this was
  the explicit 100.9 blind-spot fix; a regression here is release-blocking).

#### A7 — Pending / future / private statuses
- **Objective:** Status mapping is correct: `publish/private/future`→`active`;
  `pending/draft/trash`→`indirect`.
- **Setup:** Same image referenced from a `private` post and (separately) a
  `pending` post.
- **Operation:** scan each case.
- **Expected:** private→`active`; pending→`indirect`.
- **Failure criteria:** private resolves `indirect` (would wrongly allow cleanup of
  a live private-post image); pending resolves `unused`.

#### A8 — Genuinely unused image (the ONLY legitimate cleanup)
- **Objective:** A truly-unreferenced upload is correctly identified AND reversibly
  cleaned.
- **Setup:** Upload `wpcc-core-unused.jpg`, reference nowhere.
- **Operation:** scan (`unused`) → `unused_media_find` (present) →
  `unused_media_cleanup` with full DestructiveGuard handshake (confirm + phrase
  `CLEANUP_MEDIA` + reason + media_id).
- **Expected:** `{ action:"trashed", reversible:true, permanently_deleted:false,
  prior_status:"inherit", snapshot_id, rollback_id, verified:true }`. Attachment row
  survives (status `trash`); file bytes survive on disk (or restorable from
  snapshot).
- **Failure criteria:** `permanently_deleted:true`; file gone with no snapshot; row
  hard-deleted; handshake bypassable.
- **Rollback verification:** rollback `rollback_id` → status back to `inherit`,
  parent restored, trash meta cleared, file md5 identical, metadata restored.
  **Idempotency:** rollback again → `rollback_applied` no-op (no error, no
  double-restore).

#### A9 — Orphaned (file missing on disk)
- **Objective:** `orphaned` is reported independently of usage.
- **Setup:** Delete the file of an attachment row from disk (keep the DB row).
- **Operation:** scan; `orphaned_media_find`.
- **Expected:** `orphaned:true`; appears in `orphaned_media_find`.
- **Failure criteria:** not flagged orphaned; or snapshot/cleanup of an orphan
  crashes instead of returning a structured error.
- **Cleanup safety:** if also `unused`, cleanup must still snapshot what exists and
  trash reversibly (note: snapshot of a missing original → expect
  `wpcc_media_cleanup_snapshot_failed`, **no trash performed**).

#### A10 — Manual restore from Media Trash mid-lifecycle (reconciliation)
- **Objective:** Rollback reconciles cleanly if a human untrashed the item in
  WP-Admin first.
- **Setup:** Run A8 cleanup; then **manually** Restore the item from Media → Trash.
- **Operation:** Now call rollback with the stored `rollback_id`.
- **Expected:** rollback detects the already-restored state and reconciles (no
  error, no re-trash, no double byte-write); final state = restored.
- **Failure criteria:** rollback errors, re-trashes, or corrupts bytes.

---

### 4.B WooCommerce

#### B1 — Product featured image (published / draft / private)
- **Objective:** Product `_thumbnail_id` detected as `woocommerce_featured`; status
  follows product status; draft/private behave correctly.
- **Setup:** Same image as featured on a published product, then repeat with a draft
  product and a private product (3 products, distinct images).
- **Operation:** scan each.
- **Expected:** source `woocommerce_featured`; published→`active`, private→`active`,
  draft→`indirect`.
- **Failure criteria:** published/private product image resolves `unused`/`indirect`
  → **CRITICAL** (would expose a live shop image to cleanup).
- **Cleanup safety:** published/private refused. **Additionally**, the 100.9
  belt-and-suspenders `cleanup_exclusion` refuses any image whose `post_parent` is a
  `product`/`product_variation` even if it *were* mis-classified.

#### B2 — Product gallery images
- **Objective:** `_product_image_gallery` CSV membership detected via `FIND_IN_SET`.
- **Setup:** Published product with a 3-image gallery.
- **Operation:** scan each gallery image.
- **Expected:** source `woocommerce_gallery`, `status:active`.
- **Failure criteria:** any gallery image `unused`.

#### B3 — Variation images
- **Objective:** Per-variation `_thumbnail_id` (on `product_variation` posts)
  detected.
- **Setup:** Variable product with ≥2 variations, each with a distinct variation
  image.
- **Operation:** scan each variation image.
- **Expected:** detected via `_thumbnail_id` (source `featured_image`, since the
  host post_type is `product_variation`, not `product`); status follows the
  variation's post_status (typically `publish`→`active`).
- **Failure criteria:** variation image `unused` → **CRITICAL.**
- **Cleanup safety:** refused (reference) AND parent exclusion (`product_variation`).
- **Note:** variations whose post_status is unusual (e.g. `private`) should still
  resolve `active`. Record the actual status seen.

#### B4 — Product category images (term meta) — KNOWN-RISK FALSE POSITIVE
- **Objective:** Determine whether a **product category thumbnail** (stored as
  `thumbnail_id` in **term meta**, not postmeta) is detected.
- **Setup:** Assign an image to a product category (`thumbnail_id` term meta).
- **Operation:** scan that image.
- **Expected (per current code):** the resolver does **NOT** scan term meta →
  likely `unused` unless the image's basename happens to appear in a scanned option.
- **Failure criteria:** N/A as a code bug *per se* (term meta is out of the 100.8
  scanned sources), **but this is a documented FALSE-POSITIVE blind spot.** Record
  the result. If `unused`: the image is a cleanup candidate that is actually in use.
- **Cleanup safety:** **This is the highest-value finding to surface.** If it
  resolves `unused`, cleanup would trash a live category image (reversibly). Add the
  category-image ID to `wpcc_media_cleanup_protected` as the documented mitigation,
  and flag term-meta scanning as a backlog gap (do **not** implement now).

#### B5 — WooCommerce placeholder image option — KNOWN-RISK FALSE POSITIVE
- **Objective:** Determine whether `woocommerce_placeholder_image` (an **ID** in an
  option) is protected.
- **Setup:** Set a custom placeholder image in Woo settings.
- **Operation:** scan that image.
- **Expected (per current code):** channel 9 matches option **basenames**, not IDs;
  `woocommerce_placeholder_image` stores an ID → likely `unused`.
- **Failure criteria:** documented blind spot; record result.
- **Cleanup safety:** if `unused`, mitigate via `wpcc_media_cleanup_protected`; log
  as backlog (option-ID scanning).

---

### 4.C ACF Pro

#### C1 — Image field (postmeta)
- **Objective:** ACF image field detected via `_{key}`→`field_*` companion.
- **Setup:** CPT/post with an ACF image field set, post **published**.
- **Operation:** scan.
- **Expected:** source `acf_field`, `status:active`.
- **Failure criteria:** `unused`.

#### C2 — Gallery field (serialized IDs)
- **Objective:** ACF gallery (serialized array) detected via `LIKE "%\"ID\"%"`.
- **Setup:** Published post, ACF gallery with ≥2 images.
- **Operation:** scan each.
- **Expected:** `acf_field`, `active`.
- **Failure criteria:** any `unused` (verify serialized `s:3:"123"` matches the
  `"123"` pattern).

#### C3 — Repeater image sub-field
- **Objective:** Repeater rows (`{repeater}_{row}_{img}` + `_{...}`→`field_*`
  companion) detected.
- **Setup:** Published post, repeater with 2 rows each holding an image.
- **Operation:** scan each.
- **Expected:** `acf_field`, `active`.
- **Failure criteria:** any `unused`.

#### C4 — Flexible content image
- **Objective:** Flexible-content layout sub-field images detected (same companion
  pattern).
- **Setup:** Published post, flexible content with an image-bearing layout.
- **Operation:** scan.
- **Expected:** `acf_field`, `active`.
- **Failure criteria:** `unused`.

#### C5 — Options page image (FALSE-POSITIVE GUARD — must be `active`)
- **Objective:** ACF options-page image (`options_*` with `_options_*`→`field_*`) is
  treated as **active** site config.
- **Setup:** ACF options page with an image field set.
- **Operation:** scan; attempt cleanup.
- **Expected:** source `acf_options`, `status:active`; cleanup refused.
- **Failure criteria:** `unused`/`indirect` → **CRITICAL** (global site asset).

#### C6 — ACF Gutenberg block image field — KNOWN-RISK FALSE POSITIVE
- **Objective:** Determine whether an image set in an **ACF block** (data in
  `post_content` block JSON as `"image":id`, not postmeta, not `"id":id`) is
  detected.
- **Setup:** Published page using an ACF block whose image field = some attachment.
- **Operation:** scan that image.
- **Expected (per current code):** ACF-block field data is `"fieldname":id` inside
  the block comment in `post_content`; channels match only `wp-image-N`, `"id":N`,
  or **basename**. Detection succeeds **only** if the rendered/saved block contains
  the basename (e.g. ACF block that outputs the `<img src>` into saved content). If
  the block stores only the ID and renders dynamically, → likely `unused`.
- **Failure criteria:** documented blind spot; record result precisely (does the
  block save the URL into content or not?).
- **Cleanup safety:** if `unused`, mitigate via filter; log backlog (ACF-block JSON
  scanning).

---

### 4.D Elementor

#### D1 — Image widget
- **Objective:** Elementor image widget (`"id":N` in `_elementor_data`) detected.
- **Setup:** Published Elementor page with an Image widget (id=X).
- **Operation:** scan X.
- **Expected:** source `elementor`, `status:active`.
- **Failure criteria:** `unused`.

#### D2 — Background image (section/column)
- **Objective:** Section/column background image (`"background_image":{"id":N,...}`)
  detected — verify the `"id":N` substring match catches it.
- **Setup:** Published page with a section background image.
- **Operation:** scan that image.
- **Expected:** `elementor`, `active` (the `"id":N,` / `"id":N}` LIKE pattern should
  match the nested background-image object).
- **Failure criteria:** `unused` → blind spot; record exact JSON shape (e.g. if the
  id is followed by other chars so neither `"id":N,` nor `"id":N}` matches).

#### D3 — Elementor template / theme-builder template
- **Objective:** Templates stored as `elementor_library` posts with `_elementor_data`
  are scanned, and their status gates correctly.
- **Setup:** A saved Elementor **template** (published) and, if Pro, a **theme
  builder** template (header/footer/single) referencing an image.
- **Operation:** scan that image.
- **Expected:** `elementor`, `active` if the template post is publish/private.
- **Failure criteria:** `unused`; OR theme-builder template with non-standard status
  resolves wrong.

#### D4 — Global kit assets / global settings — KNOWN-RISK FALSE POSITIVE
- **Objective:** Determine whether images set in the **Elementor global kit / site
  settings** (`_elementor_page_settings` on the kit post, or kit defaults) are
  detected.
- **Setup:** Set a global background / site logo via Elementor Site Settings.
- **Operation:** scan that image.
- **Expected (per current code):** only `_elementor_data` is scanned;
  `_elementor_page_settings` is **not** → likely `unused` unless basename appears in
  the kit's `_elementor_data` or a scanned option.
- **Failure criteria:** documented blind spot; record result.
- **Cleanup safety:** mitigate via filter; log backlog (`_elementor_page_settings`
  scanning).

---

### 4.E Theme / Customizer Layer

#### E1 — Custom logo
- **Objective:** `custom_logo` theme_mod detected AND hard-excluded from cleanup.
- **Setup:** Set a Customizer custom logo.
- **Operation:** scan; attempt cleanup.
- **Expected:** source `theme_mods` key `custom_logo`, `status:active`; cleanup
  refused both by reference AND by `cleanup_exclusion` ("theme asset (custom_logo)").
- **Failure criteria:** `unused` or cleanup proceeds → **CRITICAL.**

#### E2 — Site icon
- **Objective:** `site_icon` option detected + excluded.
- **Setup:** Set a site icon.
- **Operation:** scan; attempt cleanup.
- **Expected:** source `option` `site_icon`, `active`; cleanup refused (reference +
  exclusion "theme asset (site_icon)").
- **Failure criteria:** `unused`/proceeds → **CRITICAL.**

#### E3 — site_logo (block themes)
- **Objective:** `site_logo` option detected + excluded.
- **Setup:** Block theme with a Site Logo block / `site_logo` option set.
- **Operation:** scan; attempt cleanup.
- **Expected:** `option` `site_logo`, `active`; cleanup refused + exclusion.
- **Failure criteria:** `unused`/proceeds → **CRITICAL.**

#### E4 — theme_mod URL images (header / background)
- **Objective:** Header/background images stored as **URLs** in
  `theme_mods_{stylesheet}` detected via basename-in-blob.
- **Setup:** Set a Customizer header image and/or background image.
- **Operation:** scan.
- **Expected:** source `theme_mods` key `url_reference`, `active`.
- **Failure criteria:** `unused`.

#### E5 — Customizer-stored URLs in plugin/widget options
- **Objective:** Image URLs in arbitrary options (block widgets, plugin settings)
  detected via the channel-9 basename scan.
- **Setup:** Add a Media Image widget (block-based widget → `widget_block` option)
  referencing an upload; and a Custom HTML widget with a hardcoded `<img src>`.
- **Operation:** scan each image.
- **Expected:** source `option` `match:url`, `status:indirect` → protected from
  cleanup (indirect is not a cleanup candidate).
- **Failure criteria:** `unused`.

#### E6 — Hardcoded CSS / file URL blind spot (FALSE-POSITIVE GUARD)
- **Objective:** Surface the file-scan blind spot explicitly.
- **Setup:** (a) Add an uploads image URL in **Additional CSS** (Customizer →
  `custom_css` post). (b) Hardcode an uploads image URL in a **child-theme PHP/CSS
  file**.
- **Operation:** scan each image.
- **Expected (per current code):** (a) **detected** — `custom_css` is a `publish`
  post and its `post_content` is scanned by basename (channel 4) → `content`,
  `active`/`indirect`. (b) **NOT detected** — files are never read → likely `unused`.
- **Failure criteria:** documented blind spot for (b); record. (a) must be detected
  — if (a) is missed, that's a real regression.
- **Cleanup safety:** for (b), mitigate via `wpcc_media_cleanup_protected`; log
  backlog (no file scanning — by design).

---

## 5. Targeted action stress tests (cross-cutting)

Beyond per-area scenarios, exercise each named action under adversarial conditions.

| Action | Stress conditions |
|---|---|
| `media_usage_scan` | non-attachment ID (→ `wpcc_media_not_found`/empty); ID 0 / negative; attachment with extremely generic basename (`1.jpg`) → confirm over-match → `indirect`/`active`, never `unused`. |
| `media_usage_report` | run on a library > the 150 default and at `limit:1000`; confirm it is **bounded** and documents the sample (a large library is *not* fully classified — this is a scope caveat, not unsafe, because single-item cleanup re-verifies). |
| `unused_media_find` | confirm **none** of the draft-only / revision-only / pending / private / Woo / ACF-options / theme-asset items from §4 appear. Any appearance of a §4.A5/A6/B1/C5/E1-E4 item = **CRITICAL false positive.** |
| `orphaned_media_find` | confirm the 3 seeded orphans appear and no on-disk file is flagged. |
| `unused_media_cleanup` | (1) missing confirm → refused; (2) wrong phrase (≠`CLEANUP_MEDIA`) → refused; (3) read token → 403; (4) on a still-referenced item → `wpcc_media_cleanup_refused`; (5) on each blind-spot item → confirm it trashes **reversibly** and prove rollback; (6) **idempotency:** run cleanup twice on the same item (2nd after rollback) — must succeed (this is the exact path that exposed the `wpcc_*` self-reference bug). |
| `rollback` | run for thumbnail, webp, optimize, **and** cleanup record types; verify byte-for-byte + no leftover temp/`.webp`; double-rollback = `rollback_applied` no-op; rollback after manual untrash (§A10). |
| `webp_generate` | unsupported mime (GIF) → `wpcc_webp_unsupported_mime`; capability off (filter `wpcc_media_webp_encode_available`=false) → `wpcc_image_lib_unavailable`, no write; already-covered → `no_action`; **assert originals md5 unchanged**; rollback deletes only generated sidecars. |
| `image_optimize` | quality clamp (0/101); re-encode at higher quality → `no_action` (insignificant savings, no snapshot); GIF/SVG → unsupported; **dimensions preserved**; rollback byte-for-byte; no `.wpccopt` temp left. |
| `regenerate_thumbnail` | `mode=missing` no-op when complete; `mode=all` byte-for-byte rollback; oversized/not-applicable sizes never generated (no upscale); editor disabled → `wpcc_thumbnail_regenerate_failed` + no partial write. |

### 5.1 Cleanup idempotency matrix (explicit)
1. Item U is `unused` → cleanup → trashed (rollback_id R1).
2. rollback R1 → restored to `inherit`.
3. scan U again → must be `unused` again (the `wpcc_*` self-reference exclusion must
   keep U from looking referenced via its own snapshot store). **If U now looks
   `indirect`, the snapshot-store false-positive has regressed — release-blocking.**
4. cleanup U again → trashed (R2).
5. rollback R2 → restored. rollback R2 again → `rollback_applied` no-op.

---

## 6. Highest-risk failure scenarios (ranked)

1. **False positive → live image trashed.** A genuinely-used image classified
   `unused`. Highest-likelihood vectors (from §1.1): **B4 product-category term
   meta, B5 Woo placeholder option-ID, C6 ACF-block JSON, D4 Elementor global kit,
   E6(b) file-hardcoded URLs.** Mitigation today: trash-not-delete + snapshot +
   `wpcc_media_cleanup_protected`. **These are reversible, not prevented** — treat
   as the #1 risk.
2. **Revision/draft regression** (§A5/A6). The 100.9 fix specifically closed the
   revision blind spot; a regression re-opens silent live-content breakage.
3. **Snapshot-store self-reference regression** (§5.1 step 3). Would break cleanup
   idempotency and skew usage reports library-wide.
4. **Irreversibility:** any path that permanently deletes, fails to snapshot before
   write, or leaves partial state (failed regenerate/optimize without restore).
5. **DestructiveGuard bypass:** cleanup running without the full handshake in *any*
   mode, or accepting a read token.
6. **Snapshot store exhaustion:** stores capped (200 snapshots / 100 rollbacks,
   FIFO). A large batch could **evict the rollback record needed to reverse an
   earlier item.** Validate batch sizes against the cap (see §7).
7. **WebP/optimize corrupting originals** (md5 drift) or changing dimensions/mime.
8. **Large-library under-classification** (`media_usage_report` bounded) misread as
   "fully audited" → operator trusts an incomplete unused list. Process risk, not a
   code bug.

---

## 7. Safety gates that MUST pass before production deployment

All gates are **hard blockers**. Any ❌ ⇒ NOT SAFE FOR PRODUCTION (this plan only
clears *staging*).

- [ ] **G1 — Zero false positives on §4 protected items.** None of
  A5/A6/A7(private)/B1(pub+priv)/B2/B3/C1-C5/D1-D3/E1-E5 ever appears in
  `unused_media_find` or is trashable.
- [ ] **G2 — Documented blind spots enumerated, not surprising.** B4, B5, C6, D4,
  E6(b) results recorded; each mitigated by `wpcc_media_cleanup_protected` and filed
  as backlog. No *undocumented* false positive discovered.
- [ ] **G3 — Every RW action is byte-for-byte reversible** (thumbnail, webp,
  optimize, cleanup), verified by md5 + metadata + no leftover temp/sidecar.
- [ ] **G4 — Cleanup is never permanent.** `permanently_deleted:false` always; row +
  bytes always recoverable; no force-delete path reachable.
- [ ] **G5 — DestructiveGuard enforced in developer AND client/enterprise.** Missing
  confirm / wrong phrase / read token all rejected.
- [ ] **G6 — Idempotency + reconciliation** (§5.1, §A10) pass, including the
  snapshot-store self-reference guard.
- [ ] **G7 — Snapshot/rollback store capacity** validated against batch sizes: a
  single batch never evicts a still-needed rollback record (keep batches well under
  the 100-rollback cap; document max safe batch).
- [ ] **G8 — Capability fail-closed:** webp/optimize abort cleanly when GD/Imagick
  WebP support is absent (no half-writes).
- [ ] **G9 — REST + MCP parity** confirmed for each action class.
- [ ] **G10 — Regression suites green:** 100.3–100.9 suites + T1 `--changed`; 0
  net-new failures vs the documented baseline.
- [ ] **G11 — Independent backups verified restorable** (DB + uploads) — the recovery
  path that does not depend on the feature under test.

---

## 8. Final deployment-readiness checklist (post-staging, pre-prod — NOT part of this run)

> This run STOPS at staging validation. The following is the gate for a *later*
> production decision and is recorded here for completeness only.

- [ ] All §7 gates ✅ on staging with a production-representative dataset.
- [ ] Production full backup (DB + uploads) immediately before deploy.
- [ ] Deploy as a **batch** of 100.1–100.9 commits (currently unpushed);
  `operation_map` confirmed **33**, MCP tool count unchanged.
- [ ] Post-deploy smoke: `webp_audit` capability probe; one `media_usage_scan`; one
  read-only `media_usage_report` — **no cleanup on production until a human reviews
  the unused list** and applies `wpcc_media_cleanup_protected` for known blind-spot
  IDs.
- [ ] Security mode confirmed (production currently `developer`); decide whether
  cleanup should require client/enterprise approval on prod.
- [ ] Rollback-on-prod rehearsal: trash one truly-unused item and reverse it before
  trusting the path at scale.

---

## 9. Risk status

### **SAFE FOR STAGING** — with mandatory conditions.

**Rationale.** The destructive surface (100.9 cleanup) is architecturally
conservative and reversible: it only ever targets `status==unused`, re-verifies at
execution time, requires a DestructiveGuard handshake in all modes, snapshots bytes
before acting, **trashes rather than deletes**, and has a proven untrash+byte
rollback with idempotency and manual-restore reconciliation. Draft-only and
revision-only references are `indirect` and protected; the revision blind spot and
the snapshot-store self-reference bug were already found and fixed in 100.9. Every
write action (thumbnail, webp, optimize) is snapshot-backed and byte-for-byte
reversible. That safety envelope makes controlled staging exercise low-risk.

**Why not yet "safe for production":** confirmed *structural* false-positive blind
spots exist where a genuinely-used image can read as `unused` — **WooCommerce
category term-meta images (B4), the `woocommerce_placeholder_image` option-ID (B5),
ACF Gutenberg-block image fields (C6), Elementor global-kit assets (D4), and
file-hardcoded uploads URLs (E6b).** These are *reversible* (trash + snapshot) but
not *prevented*. Staging validation must (1) confirm no *undocumented* false
positives beyond this list, (2) confirm every reversal path is byte-for-byte, and
(3) establish the `wpcc_media_cleanup_protected` mitigation workflow for the known
blind spots. Treat term-meta / option-ID / ACF-block / kit-settings scanning as
**backlog** (do not implement during stabilization).

**Mandatory conditions before running destructive cleanup even on staging:**
independent DB + uploads backup taken (§3); cleanup exercised **first on the seeded
genuinely-unused items (A8)** and reversed before touching any real content; all §7
hard gates tracked.

---

## 10. Notes for the executor

- **Do not deploy. Do not run destructive cleanup on real content until A8 + the
  rollback path pass on seeded throwaway uploads.**
- Run audits (`media_usage_report`, `unused_media_find`, `orphaned_media_find`)
  **before** any write, and re-run after, to detect drift.
- Capture raw JSON of every `media_usage_scan` for the §4 items into
  `artifacts/step-100-validation/` for evidence.
- No code changes were required to produce this plan: the STEP docs (100.1–100.9)
  match the implementation in `MediaUsageResolver.php` and
  `MediaEnhancementRuntimeManager.php`. The blind spots in §1.1 are **inherent scope
  boundaries of the 100.8 source list**, accurately described in the docs — not code
  defects.
