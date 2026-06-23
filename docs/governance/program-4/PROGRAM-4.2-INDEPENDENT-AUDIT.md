# PROGRAM-4.2 — Media Metadata Rollback · Independent Diff Audit

> **Date:** 2026-06-23 · **Posture:** adversarial; verified against the actual `git diff 2234dcc` + live runs.

## 1. Scope audit — only Media metadata + generic accessor
**Modified:** `includes/Operations/MediaRuntimeManager.php` (+115/−12).
**New:** `includes/Rollback/MediaFieldAccessor.php`, `tests/test-media-metadata-rollback-delta.sh`, `docs/governance/program-4/PROGRAM-4.2-*.md`.
**Forbidden surfaces — UNTOUCHED (diff-verified):** no SEO/Settings/Woo/ACF/Content/User/Comments/Bulk runtime; no Plugin/Theme rollback; no OperationRegistry/CapabilityRegistry/McpServerRuntime/Schema/REST/UI/`*.css`/`*.js`; OperationExecutor unchanged (dispatch via the existing public `rollback()` signature); P4.0 core + `MediaSnapshot` unchanged.

## 2. Non-`update` paths untouched (the file/snapshot boundary)
The removed lines are **only** the old `update` wiring (`format_media`+`store_rollback('update')`, the `case 'update'` in `rollback()`'s switch, the `if('update')` in `restore_media()`, and a `$before` line replaced by a `?? []` guard). The diff touches **no** `MediaSnapshot`/`replace_media`/`upload_media`/`delete_media`/`featured_assign` logic. Confirmed by suites: `media-snapshot-step100-1` 23/0, `media-replace-step100-2` 20/0, `media-runtime` 80/0.

## 3. Behavioural correctness (audited against the diff + live tests)
| Property | Mechanism in diff | Evidence |
|---|---|---|
| Field-scoped, not full-object | `update_media` captures only payload-present fields; v2 `fields` map | S10 (alt-only record has no title) |
| Drift prevents clobber | `RollbackDelta::restore` skip-on-drift via `MediaFieldAccessor::equals` | S4 partial, S5 conflict |
| Sibling preservation | per-field restore; record holds only touched fields | S4 (title B kept), S10 |
| Existence fidelity (alt) | `MediaFieldAccessor::key_exists` via `metadata_exists`; restore `existed?set:delete` | S1 delete, S3 restore-empty |
| Columns handled | column primitives via `wp_update_post`/`get_post_field('raw')` | S2/S4/S5/S6 title round-trips |
| Partial/conflict ≠ clean success | mark-applied only on `complete`; error envelope otherwise | S4/S5 `restored:false` |
| Idempotent | `rollback_applied` guard | S8 |
| Legacy compatible | `restore_metadata_record` falls back to `restore_metadata(before_state)` | S7 |
| **Both** restore paths | shared helper in `rollback()` **and** `restore_media()` | S12 (media_restore) + S1–S8 (rollback) |
| File bytes/sizes untouched | metadata path never calls MediaSnapshot | S11 |

## 4. Adversarial checks
- **v2 record via `media_restore` (the second path):** had this path not been updated, a v2 record would write empty strings (data loss). Diff shows `restore_media()` now uses the shared helper → S12 confirms correct restore. **No latent data-loss path.**
- **Column raw read:** `get_post_field(...,'raw')` avoids display filtering → fidelity on title/caption/description. Verified by exact-string round-trips.
- **`store_rollback` still lists `update` in ROLLBACKABLE** but `update_media` no longer calls it → dead-but-harmless; no behaviour change for other actions.
- **alt-text 4 reds:** clean-room proven pre-existing (dormant AI, key unset) — identical 4 on the P4.0 base with the change stashed. Not attributable.

## 5. Residual risks (non-blocking)
- **R6 per-field column writes:** restoring title+caption+description issues up to 3 `wp_update_post` calls (vs 1 originally) → multiple `post_modified` bumps. Correctness unaffected; rollback is a cold path. Documented.
- **NB (non-`update` actions still full-object):** `upload`/`delete`/`featured`/`replace` keep action-based/snapshot reversal — out of P4.2 scope (metadata only) and correct as-is.
- Pre-deploy gates (full T2, prod verify) remain deploy-coupled.

## 6. Invariants & verdict
Invariants **34/23/40/40/2.5.0** verified live. **Audit verdict: PASS.** Scope is exactly P4.2 (Media metadata `update` + a generic column+meta accessor); no forbidden surface; file-byte/snapshot and non-`update` paths byte-identical; all 12 required behaviours proven across both restore paths; zero attributable failures. Clears for FINAL GO.
