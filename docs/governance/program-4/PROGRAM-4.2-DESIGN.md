# PROGRAM-4.2 — Media Metadata Rollback Integrity · Design Report

> **Type:** design-first report. Autonomous mode. **Branch:** `program-4.2-media-metadata` (from P4.0 `2234dcc`; P4.1 `0788720` excluded — confirmed).
> **Goal:** replace the Media **metadata** (`update` action) full-object rollback with field-scoped, drift-aware `RollbackDelta` behaviour, reusing the P4.0 core. **Media file/byte rollback (replace/MediaSnapshot), thumbnails, upload/delete/featured actions are out of scope and unchanged.**
> **Constraints honoured:** only `MediaRuntimeManager` + a new `MediaFieldAccessor`. No Settings/SEO(except regression)/Woo/ACF/Content/User/Comments/Bulk/Plugin/Theme; no op/cap/MCP/REST/UI/schema/DB_VERSION change; no merge/push/deploy.

---

## 1. Audit of current Media metadata rollback (verified in source)
- **Files/classes:** `includes/Operations/MediaRuntimeManager.php` (674 lines); `MediaSnapshot` (file-byte snapshots, separate); `RollbackDelta`/`FieldAccessor`/`PostMetaAccessor` (P4.0 core, present).
- **Metadata action:** `update_media()` (`:254`) — public action `media_update`. Fields: **title→`post_title`, caption→`post_excerpt`, description→`post_content`** (post columns), **alt→`_wp_attachment_image_alt`** (post meta).
- **Storage:** option `wpcc_media_rollbacks` (cap 100); record `{id, media_id, action, before_state, rollback_applied, …}`.
- **Capture timing:** `$before = format_media($post)` at `:274` **BEFORE** the writes (`:277–295`). *(No DEF-1-style ordering bug, unlike Settings.)*
- **Restore primitive:** `restore_metadata()` (`:600`) — unconditionally rewrites **all four** fields via one `wp_update_post` + `update_post_meta`, from `before_state`.
- **Two restore entry points (both must change):**
  1. `rollback()` (`:527`, public; dispatched by `OperationExecutor::rollback` / change_history) → case `update` → `restore_metadata`.
  2. `restore_media()` (`:410`, the `media_restore` action) → `if 'update'` → `restore_metadata`.
- **Full-object over-reach (the F-1 target):** `update_media` snapshots all four fields even when one changed; `restore_metadata` restores all four. So rolling back an alt-only change **also reverts a later title change** (sibling clobber / out-of-order resurrection). No drift detection. Alt existence fidelity is lost (absent alt restored as `''`).

## 2. Classification of media rollback surfaces
| Surface | In P4.2? | Handling |
|---|---|---|
| Media **metadata fields** (title/caption/description/alt) | **YES** | field-scoped delta |
| Attachment **post fields** (post_title/excerpt/content) | YES (as the column backing of title/caption/description) | via `MediaFieldAccessor` column primitives |
| Attachment **meta** (alt) | YES | via `MediaFieldAccessor` meta primitives |
| Generated **image sizes** / thumbnails | **NO** | never touched by the metadata path |
| **File/byte snapshots** (`MediaSnapshot`, replace) | **NO** | unchanged; `replace` action keeps `before_state['snapshot_id']` |
| `upload` / `delete` / `featured_image_*` actions | **NO** | unchanged (action-based reversal) |

## 3. What P4.2 fixes (scope)
Media **metadata** (`update`) rollback only: field-scoped capture of **only touched** fields; drift-aware restore (skip+report, never clobber); sibling preservation; existence fidelity (alt absent→delete, empty→restore-empty; columns always exist); legacy `before_state` records still restore; truthful restored/skipped/conflict; `complete`-only idempotency.

## 4. What P4.2 does NOT fix
Media file/byte rollback (handled by `MediaSnapshot`, untouched); regenerated thumbnails; image editing/cropping binary changes; content/post rollback; plugin/theme/bulk rollback; the non-`update` media actions.

## 5. Design — reuse the P4.0 core
**New `includes/Rollback/MediaFieldAccessor.php` (`FieldAccessor`):** handles the **column + meta mix** so `RollbackDelta` stays unchanged.
```
KEYS    = title→post_title, caption→post_excerpt, description→post_content, alt→_wp_attachment_image_alt
COLUMNS = {post_title, post_excerpt, post_content}     // vs post meta (alt)
backing_keys(field) = [KEYS[field]]
read_field(id,field)= key_get(id, KEYS[field])
key_exists(id,key)  = COLUMN ? (post exists ⇒ true) : metadata_exists('post',id,key)
key_get(id,key)     = COLUMN ? get_post_field(key,id,'raw') : get_post_meta(id,key,true)
key_set(id,key,v)   = COLUMN ? wp_update_post([ID,key=>v]) : update_post_meta(id,key,v)
key_delete(id,key)  = COLUMN ? wp_update_post([ID,key=>'']) : delete_post_meta(id,key)
equals(field,a,b)   = (string)a === (string)b
```
Columns always "exist" (a post has them); existence-vs-absence fidelity is meaningful only for `alt` (meta).

**`MediaRuntimeManager` changes (metadata/update only):**
- `update_media()`: compute `$touched` = the four fields present in payload; `$prior = RollbackDelta::capture(new MediaFieldAccessor(), $media_id, $touched)` **before** the writes; run the existing writes unchanged; read `$after` per touched field; persist a **v2 delta** record via a new `store_metadata_delta()` (same `wpcc_media_rollbacks` option, adds `version:2`+`fields`). `rollback_id` semantics unchanged.
- New shared `restore_metadata_record($media_id, $record)`: v2 (`fields`) → `RollbackDelta::restore(new MediaFieldAccessor(), …)`; legacy (`before_state`) → `restore_metadata()` (unchanged) and report `complete`.
- `rollback()` + `restore_media()`: add an **early branch for the `update` action** that calls the shared helper, marks `rollback_applied` **only on `complete`**, audits truthful status/restored/skipped, and returns success or `wpcc_rollback_conflict|partial`. The `update` case is removed from each method's downstream switch/if-chain to avoid dead code; **all non-`update` actions remain byte-identical**.
- `store_rollback()` (shared persister) stays **unchanged** for `upload/replace/delete/featured` (the `update` action simply stops calling it).

## 6. Affected files
**New:** `includes/Rollback/MediaFieldAccessor.php`, `tests/test-media-metadata-rollback-delta.sh`.
**Modified:** `includes/Operations/MediaRuntimeManager.php`.
**Unchanged (asserted by audit):** P4.0 core, `MediaSnapshot`, SEO/Settings/all other runtimes, OperationExecutor, registries, Schema, REST, UI.

## 7. Risks
| # | Risk | Sev | Mitigation |
|---|---|---|---|
| R1 | Two restore paths (`rollback`, `restore_media`) — v2 record corrupts data if one is missed | HIGH | shared helper used by **both**; test both paths |
| R2 | Column vs meta mix mishandled (alt fidelity / column write) | MED | `MediaFieldAccessor` dispatches per key; S1/S2/S3 fidelity tests |
| R3 | Non-`update` actions (replace/upload/delete/featured) regressed | MED | those paths untouched; media-snapshot/replace/runtime suites must stay green |
| R4 | Legacy `before_state` update records stop restoring | MED | legacy branch + legacy-record test |
| R5 | SEO/core regression | MED | additive accessor; core untouched; SEO 56/0 + core 25/0 |
| R6 | Per-field column writes = multiple `wp_update_post` (extra `post_modified` bumps) | LOW | correctness over micro-efficiency; documented residual |
| R7 | `get_post_field` filtering | LOW | use `'raw'` context for fidelity |
| R8 | Invariant drift | LOW | no op/cap/tool/schema touched; re-verify 34/23/40/40/2.5.0 |

## 8. Validation plan (new `tests/test-media-metadata-rollback-delta.sh`, wp-eval, throwaway attachment)
S1 empty-prior alt → delete · S2 value-prior restore · S3 empty-but-existing alt → restore '' · S4 sibling preservation (alt-only update; later title change; rollback alt → title preserved) · S5 same-field drift → conflict · S6 out-of-order → no resurrection · S7 legacy `before_state` record restores · S8 repeated rollback guarded · S9 partial/conflict ≠ clean success · S10 untouched field (post_title) not in record when only alt changed · S11 generated sizes/file bytes untouched by metadata rollback · S12 via both `rollback()` and `media_restore` paths. Plus static guards.
**Regression:** `test-rollback-delta-core` (25/0), `test-seo-rollback-delta` (56/0), `test-media-runtime-step90`, `test-media-runtime`, `test-media-snapshot-step100-1`, `test-media-replace-step100-2`, `test-alt-text`, `test-change-history-rollback` (standalone), registry/cap/MCP parity, invariants.

## 9. Decision
Proceed to implement on `program-4.2-media-metadata`. The change is scoped to the metadata `update` action, reuses the P4.0 core via a column+meta accessor, leaves the file-byte/snapshot path and all other actions untouched, and is schema-free and invariant-frozen.
