# STEP 100.3 — `media_enhance` foundation + capability probe + image-size audit

**Priority 2** of STEP 100 (first sub-step). Stands up the new `media_enhance`
operation with read-only diagnostics only. Standalone, tested, locally committed.
Does **not** start 100.4.

## Goal

Create the `media_enhance` runtime and ship its first five **read-only** actions —
the capability signal and size inventory that the later write/destructive
sub-steps (thumbnail regenerate, WebP generate, optimize, cleanup) depend on. No
writes, no rollback, no approval (all `diagnostic` risk).

## New component

`includes/Operations/MediaEnhancementRuntimeManager.php` — defines both
`MediaEnhancementRegistry` (action constants + risk) and
`MediaEnhancementRuntimeManager` (handler), mirroring the
`ReportingRegistry`/`ReportingRuntimeManager` single-file pattern. `run( $p, $cx )`
dispatches on `action` and returns a plain array (or `WP_Error`); the executor
normalizes and audits (`media_enhance.<action>`).

## Actions (all read-only)

| Action | Returns |
|--------|---------|
| `media_enhance_capabilities` | GD + Imagick availability/version, per-library WebP/AVIF encode support, chosen `image_library`, `resize`, `webp_encode`, `avif_encode`, `wp_supports_webp`. Probes GD (`gd_info()`, `imagewebp`/`imageavif`) and Imagick (`queryFormats()`) — **GD/Imagick only, no shell**. Fail-closed booleans for later ops. |
| `image_sizes_list` | Every registered intermediate size (`wp_get_registered_image_subsizes()`), incl. theme `add_image_size`, with width/height/crop and `source` (`core` vs `additional`). |
| `image_size_usage_audit` | Per-size `in_metadata` + `on_disk` counts across image attachments, bounded by `limit` (default 500, max 5000); reports `scanned`, `with_sizes`, `truncated`. |
| `image_size_recommendations` | Flags registered sizes that are `unused` (no scanned attachment carries them) or `oversized` (largest edge > 2048px). Reuses the usage audit so the call is data-backed. |
| `image_size_verify` | For one attachment (`media_id`): classifies every registered size as `present` / `missing` / `not_applicable`. **Sizes larger than the original are `not_applicable`** (WordPress never upscales) so the audit doesn't false-positive a "missing" size. |

## Wiring (REST + MCP parity, capability)

- `OperationExecutor::resolve_handler()` — `case 'media_enhance'`.
- `OperationRegistry` — `media_enhance` op def: `risk_level` diagnostic, all
  `action_risks` diagnostic, `requires_approval` false, params `action` (enum),
  `media_id`, `limit`.
- `CapabilityRegistry::OPERATION_MAP` — `media_enhance → media.manage`
  (**operation_map 32 → 33**; capability test count updated accordingly). Not in
  `READ_ONLY_SCOPE_OPERATIONS` (full-scope token required, like media writes).
- `RestApi` — read route `POST /operations/media_enhance/run`
  (`require_read`, model of `report_manage`), `run_media_enhance()` handler,
  `ROUTE_MANIFEST` entry, error-catalog `wpcc_invalid_media_enhance_action`
  (`wpcc_not_an_image`, `wpcc_media_not_found` already cataloged).
- **MCP parity automatic** — `media_enhance` is enumerated as a tool from the
  registry; verified present in `tools/list` and callable via `tools/call`.

## Error codes

`wpcc_invalid_media_enhance_action` (bad action), `wpcc_media_not_found`
(missing/non-attachment `media_id`), `wpcc_not_an_image` (non-image attachment).

## Tests

`tests/test-media-enhance-step100-3.sh` — **33/33 PASS**: capability probe shape
(REST + MCP), size inventory (core/additional tagging, dimensions, MCP parity),
usage audit (scanned/registered/on_disk coverage), verify present/missing/
not_applicable accuracy proven by registering test sizes via a temporary
mu-plugin (small=missing, big/huge=not_applicable, huge=oversized), recommendations
(unused + oversized), structured errors, and wiring (operation_map=33,
media.manage mapping, MCP tools/list membership). Live dev probe: GD present,
`webp_encode:true`, Imagick absent.

Regression: T1 `--changed` — see commit. Full bash regression deferred to T2
before any deploy (0 net-new expected; 24 baseline).

## Scope boundary

Read-only only. No image is written, resized, regenerated, or deleted in this
sub-step. Thumbnail regeneration (100.5), WebP generation (100.6), optimization
(100.7), usage/cleanup (100.8–100.9) are later sub-steps that consume this
capability probe and the `MediaSnapshot` service from 100.1.

## Next

**STEP 100.4** — responsive image audit (read-only: `srcset_verify`,
`responsive_image_audit`, `missing_sizes_audit`). **Not started** — per owner,
stop after 100.3.
