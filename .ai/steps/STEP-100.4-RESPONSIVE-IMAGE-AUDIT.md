# STEP 100.4 — Responsive Image Audit

**Priority 2** of STEP 100 (second sub-step). Builds on the 100.3 `media_enhance`
foundation to inspect and report responsive-image behavior. **Read-only** — no
regeneration, optimization, WebP, or deletion. Standalone, tested, locally
committed. Does **not** start 100.5.

## Capability gap addressed

After 100.3 the runtime could inventory registered sizes and verify which sizes
exist for an attachment, but it could **not** report responsive-delivery health:
whether WordPress actually produced a usable `srcset`, which attachments are
missing regenerable sizes, whether an original is too large (wasted bytes) or too
small for its display contexts (can't fill larger layouts; WP never upscales).
100.4 adds those audits so a later thumbnail-regenerate step (100.5) and image-size
decisions have data to act on — **audit-first**.

## Actions added to `media_enhance` (all read-only, no new operation)

`operation_map` stays **33**; capability stays `media.manage`; MCP parity automatic.

| Action | Scope | Reports |
|--------|-------|---------|
| `srcset_verify` | one attachment | WordPress `srcset` string + candidate count, `sizes` attribute, `has_srcset` (≥2 candidates = responsive-capable). |
| `responsive_image_audit` | one attachment **or** library-wide | With `media_id`: size classification + srcset + metadata completeness + `responsive_ready` verdict + structured recommendations. Without it: aggregate counts (`responsive_ready` / `not_ready` / `without_srcset` / `with_missing_sizes` / `incomplete_metadata`) + a sample of not-ready attachments. |
| `missing_sizes_audit` | library-wide | Image attachments missing one or more **applicable** registered sizes (regenerable), with the per-attachment missing list. Bounded by `limit`. |
| `image_size_context_audit` | one attachment | Original dimensions vs registered display sizes: `oversized` (original ≥ 1.5× the largest registered size → wasted resolution), `undersized` (smaller than registered sizes it cannot fill, since WP won't upscale), or `adequate`, with recommendations. |

Coverage of the brief: srcset availability (`srcset_verify`), missing generated
sizes (`missing_sizes_audit` / per-attachment `missing`), oversized & undersized
usage (`image_size_context_audit`), registered sizes vs actual files (shared
`classify_sizes` → present/missing/not_applicable), dimensions vs display context
(`image_size_context_audit`), WP responsive metadata (`srcset_verify`), attachment
metadata completeness (`metadata_complete` in `responsive_image_audit`), and
regeneration / image-size-change recommendations (structured `recommendations` on
the per-attachment audit and context audit).

## Implementation notes

- `size_verify` (100.3) refactored to share a new `classify_sizes( $id )` helper;
  `resolve_image_id()` centralizes the attachment/image validation. No behavior
  change to 100.3 (its suite still 33/33).
- `srcset_info()` uses `wp_get_attachment_image_srcset()` /
  `wp_get_attachment_image_sizes()` against the full size; a meaningful responsive
  srcset requires ≥2 candidates (single-size/tiny images report `has_srcset:false`).
- Oversized threshold `OVERSIZE_FACTOR = 1.5` × the largest registered width.
  Note: WordPress's own `big_image_size_threshold` (default 2560px) caps most
  uploads, so genuinely oversized originals are those above that cap or far above
  a theme's largest registered size.
- No new error codes — `wpcc_media_not_found` / `wpcc_not_an_image` reused.

## Files changed

- `includes/Operations/MediaEnhancementRuntimeManager.php` — 4 new actions +
  `classify_sizes`/`resolve_image_id`/`srcset_info`/`metadata_complete`/
  `responsive_report` helpers + `OVERSIZE_FACTOR`; `size_verify` refactored.
- `includes/Operations/OperationRegistry.php` — `media_enhance` def: 4 actions
  added to `action_risks` (diagnostic) + `action` enum + param docs + description.
- `includes/AiAgent/RestApi.php` — ROUTE_MANIFEST description updated.

## Tests

`tests/test-media-enhance-responsive-step100-4.sh` — **33/33 PASS**: complete
sizes → `responsive_ready`; a missing size (registered post-upload via mu-plugin) →
`missing` + `missing_sizes_audit` + `missing_sizes` recommendation; oversized
original (6144px with `big_image_size_threshold` disabled) → `oversized`;
undersized original (250px) → `undersized` + unfillable (upscale-skipped) sizes;
srcset present (multi-size) vs absent (tiny); library-wide aggregate; REST + MCP
parity; structured errors; wiring (op_map still 33, MCP tool present). 100.3 suite
re-run 33/33 (refactor safe).

Regression: T1 `--changed` — see commit.

## Next

**STEP 100.5** — thumbnail regeneration (reversible write, snapshot-backed via the
100.1 `MediaSnapshot` service). **Not started** — per owner, stop after 100.4.
