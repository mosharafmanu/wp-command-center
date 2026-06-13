# STEP 91 — SEO Runtime

## Goal

Unified SEO management over REST + MCP, independent of which SEO plugin is
active (Rank Math or Yoast SEO).

## Architecture

A meta-key mapping layer — no dependency on a plugin's PHP API beyond detection,
so it is stable across plugin versions.

- **`SeoProvider`** — detects the active provider (`WPSEO_VERSION`/`WPSEO_Meta`
  → Yoast; `RankMath`/`rank_math()` → Rank Math) and maps each unified field to
  that provider's post meta. `read()` / `write()` handle the scalar fields plus
  the provider-specific **robots** encoding (Yoast splits noindex/nofollow/adv
  across meta keys; Rank Math stores an array).
- **`SeoRegistry`** — actions, risk tiers, rollback support.
- **`SeoRuntimeManager`** — the `seo_manage` operation handler.

## Unified fields

`title`, `description`, `focus_keyword`, `canonical`, `og_title`,
`og_description`, `og_image`, `twitter_title`, `twitter_description`,
`twitter_image`, `robots` (array of directives: noindex, nofollow, noarchive,
nosnippet, noimageindex — normalized + de-duped).

| Field | Yoast meta | Rank Math meta |
|---|---|---|
| title | `_yoast_wpseo_title` | `rank_math_title` |
| description | `_yoast_wpseo_metadesc` | `rank_math_description` |
| focus_keyword | `_yoast_wpseo_focuskw` | `rank_math_focus_keyword` |
| canonical | `_yoast_wpseo_canonical` | `rank_math_canonical_url` |
| og_* | `_yoast_wpseo_opengraph-*` | `rank_math_facebook_*` |
| twitter_* | `_yoast_wpseo_twitter-*` | `rank_math_twitter_*` |
| robots | `meta-robots-noindex/-nofollow/-adv` | `rank_math_robots[]` |

## Operations — REST `/operations/seo_manage/run` + MCP `seo_manage`

| Action | Risk | Description |
|---|---|---|
| `seo_get` | diagnostic | Read all unified SEO fields for a post. |
| `seo_update` | medium | Write supplied fields. Rollback-capable, audited. |
| `seo_validate` | diagnostic | Structural validation of supplied fields (or a post). |
| `seo_analyze` | diagnostic | Deterministic SEO scoring (0–100) over 10 checks. |
| `seo_restore` | medium | Re-apply a pre-update snapshot (rollback). |

- `seo_update` snapshots the full prior SEO state, returns `rollback_id`, and
  `seo_restore` re-applies it. Audit: `seo.get`, `seo.updated`, `seo.analyzed`,
  `seo.restored`.
- `seo_analyze` checks: title present/length (≤60), description present/length
  (120–160), focus keyword present + in title/description/content, canonical set,
  Open Graph set.
- `seo_validate` flags: title/description length (warning/info), invalid
  canonical URL (error), unknown robots directive (error). `valid` is false only
  when an `error`-severity issue exists; `seo_update` rejects errors with
  `wpcc_seo_invalid_field`.

## Structured errors

`wpcc_seo_no_provider`, `wpcc_seo_missing_content_id`, `wpcc_seo_post_not_found`,
`wpcc_seo_no_fields`, `wpcc_seo_invalid_field`, `wpcc_invalid_seo_action`,
`wpcc_missing_rollback_id`, `wpcc_rollback_not_found`,
`wpcc_rollback_already_applied`.

## Acceptance tests — `tests/test-seo-runtime-step91.sh` (24/24)

Workflow (create content → generate SEO → save → verify metadata incl. native
Yoast meta → update → verify changes) plus provider detection, robots round-trip
(unified ↔ Yoast split meta), `seo_validate` (good + bad), `seo_analyze` scoring,
structured errors, REST + MCP parity, and rollback with the already-applied
guard. The suite skips cleanly if no SEO plugin is active.

## Files changed

- `includes/Operations/SeoProvider.php` — **new** provider abstraction.
- `includes/Operations/SeoRegistry.php` — **new** registry.
- `includes/Operations/SeoRuntimeManager.php` — **new** `seo_manage` handler.
- `includes/Operations/OperationExecutor.php` — dispatch `seo_manage`.
- `includes/Operations/OperationRegistry.php` — `seo_manage` operation (gated on
  `SeoProvider::is_available()`).
- `includes/Operations/CapabilityRegistry.php` — `seo_manage` → `content.manage`.
- `includes/AiAgent/RestApi.php` — REST route + manifest entry.

## Preserved guarantees

Backward compatible (additive operation). `seo_update`/`seo_restore` are `medium`
risk → gated in Client/Enterprise modes; rollback + audit + structured errors per
rule 6; REST ↔ MCP parity via the shared `OperationExecutor`. Read/validate/
analyze are diagnostic and run directly.

## Test-environment note

Yoast SEO was installed on the dev site to enable real-site acceptance testing.
The Rank Math meta mapping is implemented and unit-covered by `SeoProvider`'s
static map; switching providers requires no code change (detection is automatic).
