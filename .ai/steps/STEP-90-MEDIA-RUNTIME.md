# STEP 90 — Media Runtime

## Goal

Complete WordPress media management through REST and MCP, with full metadata
control and rollback on every write.

## Audit (do not rebuild what exists)

`MediaRuntimeManager` / `MediaRegistry` already implemented: `media_list`,
`media_get`, `media_search`, `media_upload`, `media_replace`, `media_delete`,
`media_restore`, `featured_image_assign`, `featured_image_remove`,
`media_regenerate_metadata`. Gaps closed in this step:

1. **`media_update`** (NEW) — edit `title` / `alt` / `caption` / `description`
   on an existing attachment, rollback-capable.
2. **`media_set_featured` / `media_remove_featured`** — the spec-named aliases of
   `featured_image_assign` / `featured_image_remove` (legacy names kept).
3. **`description` metadata** — added to `media_upload`, `media_update`, and the
   `media_get` response (`post_content`).
4. **Rollback actually works for all writes** — fixed a latent bug (below) and
   exposed `rollback_id` on every write response.

## Operations (REST `/operations/media_manage/run` + MCP `media_manage`)

| Action | Risk | Write | Rollback |
|---|---|---|---|
| media_list / media_get / media_search | diagnostic | no | — |
| media_update | medium | yes | metadata snapshot |
| media_upload | medium | yes | delete attachment |
| media_replace | medium | yes | prior-state snapshot |
| media_delete | medium¹ | yes | untrash (needs MEDIA_TRASH) |
| media_set_featured / featured_image_assign | medium | yes | prior thumbnail |
| media_remove_featured / featured_image_remove | medium | yes | prior thumbnail |
| media_regenerate_metadata | low | yes | — |
| media_restore | — | — | applies a rollback record |

¹ A **force** delete is additionally gated by the STEP-84 DestructiveGuard
(`DELETE_MEDIA` confirmation), since it bypasses the trash permanently.

## `media_update`

```json
{ "action": "media_update", "media_id": 123,
  "title": "…", "alt": "…", "caption": "…", "description": "…" }
```

- At least one field required, else `wpcc_media_no_fields`.
- Unknown attachment → `wpcc_media_not_found`.
- Snapshots the full prior metadata before writing; returns `rollback_id`.
- `media_restore { rollback_id }` restores title/alt/caption/description.
- Audit: `media.updated` with the changed field list.

## Bug fixed: rollback was silently disabled for upload/replace/delete/update

`store_rollback()` is called with short internal action names (`'upload'`,
`'delete'`, …) but `MediaRegistry::supports_rollback()` keyed on the public
operation names (`'media_upload'`, …). The mismatch made `supports_rollback()`
return false, so **no rollback record was stored** for upload/replace/delete
(only the featured actions, whose names happened to match, worked). Fixed with a
correct internal `ROLLBACKABLE` set and `store_rollback()` now returns the
`rollback_id`, which every write response surfaces so callers can actually roll
back (rule 6).

## Two WordPress behaviors made explicit

- `set_post_thumbnail()` only applies when the attachment is a real image
  (`wp_get_attachment_image()` non-empty); a metadata-only attachment can't be a
  featured image. The acceptance test uses a real uploaded image for the featured
  workflow.
- WordPress does **not** trash media unless `MEDIA_TRASH` is defined — so
  `media_delete` is permanent by default and the untrash-based delete rollback is
  effective only when `MEDIA_TRASH` is enabled. Documented; not changed.

## Acceptance tests — `tests/test-media-runtime-step90.sh` (25/25)

Full workflow (upload → set featured → verify → replace → remove featured →
delete → verify deletion) plus: `media_update` of all four fields, metadata
rollback restoring every field, structured errors (`wpcc_media_no_fields`,
`wpcc_media_not_found`), both featured aliases + legacy backward-compat,
`rollback_id` on every write, REST + MCP parity, and the `DELETE_MEDIA`
destructive confirmation on force delete.

## Files changed

- `includes/Operations/MediaRegistry.php` — `media_update` + `media_set_featured`
  / `media_remove_featured` constants, risk/approval/rollback maps.
- `includes/Operations/MediaRuntimeManager.php` — `update_media()`,
  `restore_metadata()`, dispatch + aliases, `description` in upload/format/update,
  `update` rollback handling, `store_rollback()` correctness + returns id,
  `rollback_id` on every write response.
- `includes/Operations/OperationRegistry.php` — `media_manage` action_risks,
  description, and parameter schema for the new actions/metadata fields.

## Preserved guarantees

Backward compatible (legacy `featured_image_*` names kept; only additive params);
security modes (media_update gated medium in client/enterprise); approval
workflow, rollback, and REST/MCP parity all intact. Force delete still requires
the STEP-84 destructive confirmation.
