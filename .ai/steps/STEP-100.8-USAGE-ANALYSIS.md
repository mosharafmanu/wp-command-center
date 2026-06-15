# STEP 100.8 — Media Usage Analysis Runtime

**Priority 4** of STEP 100 (first sub-step). The strategic, control-plane step:
**cleanup intelligence, not optimization.** Answers "where is this media actually
used?" across the whole install so a (future) AI agent can decide whether an item
is actively used, indirectly referenced, orphaned, or a safe cleanup candidate.
Read-only, audit-first, reversible-compatible. Standalone, tested, locally
committed. Does **not** start 100.9.

## Capability gap addressed

WPCC could enhance media but had no way to reason about *whether media is safe to
touch/remove*. Generic optimizer plugins don't answer this; the differentiator is
WPCC's **cross-runtime** reach (core + blocks + Woo + ACF + Elementor + site
config) behind one agent-readable, structured operation. This is the prerequisite
for any cleanup (100.9), which must re-verify at execution time.

## New component

`includes/Operations/MediaUsageResolver.php` (own file — no PSR-4 multi-class
trap). `references( $id )` returns every reference with a `source` + `status`;
`classify( $id )` derives `status` / `orphaned` / `cleanup_candidate`;
`attachment_ids( $limit )` enumerates any-mime attachments for library scans.

### Sources scanned
| Source | How |
|--------|-----|
| WordPress core — featured | `_thumbnail_id` postmeta |
| WordPress core — content | post_content `wp-image-N` + referenced file basenames |
| Gutenberg blocks | `parse_blocks` → `attrs.id` / `attrs.ids[]` / `attrs.mediaId` |
| WooCommerce | featured (product `_thumbnail_id`) + `_product_image_gallery` (CSV via `FIND_IN_SET`) |
| ACF fields | postmeta value = ID / serialized gallery, identified by the `_{key}` → `field_…` companion |
| ACF options pages | `options_…` values with `_options_…` → `field_…` companion |
| Elementor | `_elementor_data` JSON (`"id":N`) |
| theme_mods | `custom_logo` ID + URL basenames in `theme_mods_{stylesheet}` |
| Common options | `site_icon`, `site_logo`, and any option value containing a file basename |

### Classification (the agent-facing contract)
- **active** — referenced from a live host (published/private/future post, or live
  site config).
- **indirect** — referenced only from non-published content (draft/pending/trash)
  or a loose URL/meta string.
- **unused** — no reference anywhere → `cleanup_candidate: true`.
- **orphaned** — the underlying file is missing on disk (data-integrity flag,
  independent of usage).

**Conservative by design:** when in doubt an item is treated as referenced, never
as unused — so cleanup stays safe (no false "unused").

## Actions added to `media_enhance` (read-only; no new operation; operation_map stays 33)

| Action | Returns |
|--------|---------|
| `media_usage_scan` | One item (any mime): full `references[]` + `by_source` + `status` / `orphaned` / `cleanup_candidate` + `file_exists`. |
| `media_usage_report` | Library aggregate (bounded): active / indirect / unused / orphaned / cleanup_candidate counts + ID samples. |
| `unused_media_find` | Cleanup candidates (no references) with title/url/mime/orphaned. |
| `orphaned_media_find` | DB rows whose file is missing on disk. |

All `diagnostic` risk → reads via the existing `require_media_enhance` read branch
(no permission change). MCP parity automatic.

## Files changed

- `includes/Operations/MediaUsageResolver.php` — **new** resolver.
- `includes/Operations/MediaEnhancementRuntimeManager.php` — 4 actions +
  `resolve_attachment_id` (any-mime) + `usage_limit`; `USAGE_*` bounds.
- `includes/Operations/OperationRegistry.php` — action_risks (4 diagnostic), enum,
  description.
- `includes/AiAgent/RestApi.php` — ROUTE_MANIFEST read-action list.

No new error codes (`wpcc_media_not_found` reused). No write paths, no rollback
records — purely read.

## Bug found & fixed during build

The post-content query referenced alias `p.` while the `FROM` had no alias
(`Unknown column 'p.post_content'`) → content/block/draft references silently
missed. Fixed to `FROM {$wpdb->posts} p`. (Caught by the cross-source smoke test
before the suite — exactly the kind of false-negative that would make cleanup
unsafe.)

## Tests

`tests/test-media-enhance-usage-step100-8.sh` — **40/40 PASS**: detection across
all nine sources (featured, block, classic content, Woo gallery, Elementor, ACF
field, ACF options, theme_mods, site_icon); classification (active / indirect via
draft-only / unused / orphaned / cleanup_candidate); `media_usage_report`
aggregate; `unused_media_find` + `orphaned_media_find` membership (positive and
negative); REST + MCP parity; structured errors; wiring. 100.3–100.7 suites
re-run green.

Regression: T1 `--changed` — see commit.

## Scope / performance note

Library scans (`report`/`find`) classify per attachment (multiple targeted
queries each) and are **bounded** (`limit` default 150, max 1000) — accurate and
safe over correctness-by-aggregation. 100.9 will re-verify candidates at execution
time regardless.

## Next

**STEP 100.9** — unused/orphaned cleanup (destructive, DestructiveGuard, trash-not-
force, re-verify-then-snapshot-then-trash, untrash rollback). **Not started.**
