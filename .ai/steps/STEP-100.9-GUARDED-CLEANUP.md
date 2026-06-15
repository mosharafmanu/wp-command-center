# STEP 100.9 — Guarded, Reversible Media Cleanup (final core media step)

**Priority 4** of STEP 100, second sub-step, and the **final planned core media
step**. Turns 100.8's usage intelligence into action — safely. Standalone, tested,
locally committed.

## Mandate honored

| Requirement | How |
|---|---|
| **Fix the revision blind spot first** | `MediaUsageResolver` now scans `post_type='revision'` content (excluded by the main query, which drops `post_status='inherit'`). Revision references → `indirect` → protected. |
| **Cleanup = Snapshot → Trash → Verify, never permanent** | `unused_media_cleanup`: `MediaSnapshot::capture` → emulate WP trash (`post_status='trash'` + `_wp_trash_meta_*`, independent of `MEDIA_TRASH`) → verify. **No permanent-delete path exists.** |
| **Re-run resolver immediately before every cleanup** | `classify()` is called at execution time inside the action; stale audit input is never trusted. |
| **Hard-exclude protected categories** | `cleanup_exclusion()`: still-referenced (active **or** indirect — covers draft-only & revision), WooCommerce product images (parent `product`/`product_variation`), theme assets (`custom_logo`/`site_icon`/`site_logo`), and a `wpcc_media_cleanup_protected` filter for unknown/code/CSS references. |
| **Extend rollback** | `rollback()` cleanup branch restores **file bytes + metadata + `_wp_attached_file`** (via `MediaSnapshot::restore`) **+ `post_status` + `post_parent`** and clears trash meta. |
| **Fully reversible** | Snapshot guarantees byte restore even without `MEDIA_TRASH`; rollback record stores `prior_status`/`prior_parent`. |
| **No permanent-delete in 100.9** | Confirmed — the runtime cannot force-delete. A future purge must be a **separate operation** with its own DestructiveGuard + approval flow. |

## Action added: `unused_media_cleanup` (media_enhance; no new op; operation_map stays 33)

- **Risk `high`** + **DestructiveGuard `CLEANUP_MEDIA`** (confirm + confirmation_phrase + reason + media_id) enforced by `OperationExecutor` in **every** security mode before the handler runs (added a `media_enhance` case to `DestructiveGuard::classify`).
- **Full-token only** (added to `require_media_enhance` write list).
- **Flow:** resolve attachment → re-`classify()` → `cleanup_exclusion()` (refuse with `wpcc_media_cleanup_refused`) → `MediaSnapshot::capture` (abort `wpcc_media_cleanup_snapshot_failed`) → record `prior_status`/`prior_parent` → trash + trash meta → verify trash + snapshot (`wpcc_media_cleanup_failed` + auto-restore on failure) → store rollback → return `{action:trashed, reversible:true, permanently_deleted:false, prior_status, prior_parent, snapshot_id, rollback_id, verified, classification}`.
- Reversal via the existing `POST /operations/media_enhance/rollback` (and the unified `OperationExecutor::rollback`).

## Rollback (extended)

`rollback()` mode `unused_media_cleanup`: `MediaSnapshot::restore` (bytes + metadata + `_wp_attached_file`) **then** `wp_update_post` restoring `post_status` + `post_parent` and `delete_post_meta` of `_wp_trash_meta_status`/`_wp_trash_meta_time`; verifies the raw status returned to its prior value. Idempotent (`rollback_applied` guard) and reconciles cleanly if a human already restored from WP-Admin trash. `store_rollback()` gained an `$extra` param to persist `prior_status`/`prior_parent`.

## Files changed

- `includes/Operations/MediaUsageResolver.php` — revision scan (source `revision`); **excluded WPCC's own options** (`wpcc_*`) from the generic URL scan.
- `includes/Operations/MediaEnhancementRuntimeManager.php` — `unused_media_cleanup` + `cleanup_exclusion`; `rollback()` cleanup branch; `store_rollback($extra)`; `get_risk` (cleanup→high); WRITE_ACTIONS/ACTIONS.
- `includes/Operations/DestructiveGuard.php` — `PHRASE_CLEANUP = CLEANUP_MEDIA` + `media_enhance` case.
- `includes/Operations/OperationRegistry.php` — action_risks (`unused_media_cleanup`→high), enum, confirm/phrase/reason params, description.
- `includes/AiAgent/RestApi.php` — write-list, ROUTE_MANIFEST, 3 error codes.

## Bug found & fixed during build (important)

The resolver's generic-option URL scan matched **WPCC's own snapshot store**
(`wpcc_media_file_snapshots`, whose value embeds file basenames). After any
snapshot (replace/regenerate/webp/optimize/cleanup), the attachment looked
"referenced" → could never be classified `unused`. This silently broke cleanup
(2nd cleanup after a rollback was refused "still referenced (indirect)") **and**
would have skewed 100.8 usage analysis library-wide. Fixed by excluding `wpcc_*`
options from the URL scan. (Caught because the cleanup→rollback→cleanup lifecycle
test exercised a snapshotted item — a fresh item never hit it.)

## Tests

`tests/test-media-enhance-cleanup-step100-9.sh` — **31/31 PASS**: confirmation gate
(missing + wrong phrase), all six exclusions (active / draft-only / revision-only /
WooCommerce / theme asset / protected-filter), Snapshot→Trash→Verify, no-permanent-
delete (file + row survive), rollback (byte-for-byte + status + parent + untrash),
rollback idempotency, REST + MCP parity, structured errors, wiring. 100.3–100.8
suites re-run green.

Regression: T1 `--changed` — see commit.

## STEP 100 roadmap status

**Core roadmap 100.1 → 100.9 COMPLETE** (all local). A permanent purge of trashed
media is intentionally **out of scope** and, if ever added, must be a separate
operation with its own DestructiveGuard + approval. Strategic backlog (from the
roadmap review): pull forward `report_media` + cross-runtime image audits +
optimizer-interop detection; defer AVIF / delegating-writes / media_duplicate.
