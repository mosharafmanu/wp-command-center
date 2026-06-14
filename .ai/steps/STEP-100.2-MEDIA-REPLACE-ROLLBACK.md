# STEP 100.2 — Fix `media_replace` rollback (+ `media_replace_verify`)

**Priority 1** of STEP 100. Consumes the 100.1 `MediaSnapshot` service. Standalone,
tested, locally committed. Does **not** start 100.3.

## Bugs fixed

The original `media_replace` had **two** defects — one caught by the static
design audit, one only surfaced by runtime testing in this step:

1. **Rollback was a silent no-op.** It returned a `rollback_id`, but `rollback()`
   had no `replace` case and the snapshot held only metadata, not file bytes.
2. **Replace didn't replace.** `media_handle_sideload( $file, 0, '', [ 'ID' =>
   $media_id ] )` **ignores the `ID`** in `post_data` and creates a *new orphan
   attachment*, leaving the original file and `_wp_attached_file` untouched. So
   the operation silently did nothing to the target attachment.

## Fix

`MediaRuntimeManager::replace_media()` rewritten:

1. Guard missing `source_url` (`wpcc_missing_url`).
2. **Capture a `MediaSnapshot` before** the destructive write (original bytes +
   all size files + metadata). If capture fails, **abort** — never perform an
   irreversible replace. The `snapshot_id` is stored on the rollback record's
   `before_state`.
3. Download the source; reject non-images (`getimagesize` → `wpcc_replace_not_image`).
4. **Replace in place**: write the new bytes to the *existing* file path
   (preserving the attachment ID and URL), delete the old generated size files,
   `wp_generate_attachment_metadata()` from the new bytes, correct the recorded
   mime type, bust caches.
5. On any abort path, `discard_replace_snapshot()` removes the snapshot + its
   rollback record so nothing leaks.

Rollback (both `rollback()` and the `media_restore` action) gains a `replace`
case that calls `MediaSnapshot::restore( snapshot_id )` — restoring original
bytes, recreating deleted size files, and restoring metadata + `_wp_attached_file`
byte-for-byte.

**New action `media_replace_verify`** (diagnostic, read-only): reports the live
file's basename, existence, size, **md5 hash**, mime, dimensions, size list, and
URL — used to confirm a replace took effect and that a rollback restored the
original.

## Wiring

`MediaRegistry` (`ACTION_REPLACE_VERIFY` + risk `low`/approval `false`),
`MediaRuntimeManager::run()` match arm + handler, `OperationRegistry` media_manage
`action_risks` (`media_replace_verify` → diagnostic). No new operation →
`operation_map` unchanged at 32; capability stays `media.manage`. REST via existing
`media_manage/run` + `/rollback`; MCP via enumeration.

## Known limitation (documented, deferred)

In-place replace keeps the **original filename/extension**. Replacing across
formats (e.g. a `.jpg` slot with PNG bytes) keeps the `.jpg` name while updating
the recorded mime type. A format-aware replace (new filename + reference rewrite)
is a later enhancement. Orphan size files whose names differ from the originals
are left on disk after rollback (P4 cleanup territory).

## Tests

`tests/test-media-replace-step100-2.sh` — **20/20 PASS**: replace in place (REST)
preserves the URL → **no orphan attachment created** (count guard) →
`media_replace_verify` shows the new bytes → rollback restores the original
**byte-for-byte** → MCP replace+verify+rollback cycle → structured errors
(missing URL, non-attachment, non-image source) → a rejected replace leaves the
original intact. Existing suites unaffected (`media-runtime` 80, `step90` 25,
`100.1` 23, capability 61). Full bash regression: 0 net-new (24 baseline).

## Next

**STEP 100.2 is the last Priority-1 item.** Priority 2 begins at **STEP 100.3**
(`media_enhance` op + capability probe + image-size audit) — **not started**;
awaiting owner go-ahead.
