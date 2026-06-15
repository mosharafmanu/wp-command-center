# STEP 101.3 — Rollback & Snapshot Validation Report

DEV only. Every reversal observed live; on-disk file state verified for Snapshot/Patch.

## Rollback success rate: 6 / 12 (50%)

| Runtime | Rollback mechanism | Result | Evidence |
|---|---|---|---|
| Option | `option_rollback` action (rollback_id) | ✅ PASS | posts_per_page 15→11→**15** restored |
| SEO | `seo_restore` action (rollback_id) | ✅ PASS | description set→restored to empty |
| Media | REST `/media_manage/rollback` (rollback_id) | ✅ PASS | alt set→restored to "" |
| Snapshot | `snapshot_restore` (snapshot_id) | ✅ PASS | `hash_matches:true`, file byte-identical |
| Patch | `rollback_manage rollback_apply` (patch_id) | ✅ PASS | file on disk: "PATCHED line"→"Original line" |
| Workflow | `workflow_rollback` (execution_id) | ✅ PASS | per-step rollback_id reversed via STEP 97 dispatcher |
| Content | `content_rollback` action | ❌ FAIL | blocked action (F-1) |
| Menu | REST `/menu_manage/rollback` | ❌ FAIL | rollback_id not surfaced (F-2) |
| ACF | REST `/acf_manage/rollback` | ❌ FAIL | rollback_id not surfaced (F-3) |
| User | REST `/user_manage/rollback` | ❌ FAIL | update rollback_id not surfaced (F-3) |
| WooCommerce | REST `/woocommerce_manage/rollback` | ❌ FAIL | price_update rollback_id not surfaced (F-3) |
| Settings | REST `/settings_manage/rollback` | ❌ FAIL | rollback_id not surfaced; manual restore (F-3) |

> The 6 failures are **not corruption** — the write succeeded and state was either restored by a working alternate (manual update / delete) or is trivially restorable. The failure is that the *documented rollback path* could not be driven (F-1 hard defect; F-2/F-3 missing `rollback_id` surfacing).

## Snapshot lifecycle (PASS)

```
snapshot_create  → snapshot_id bc78a58d-…, path themes/twentytwentyfour/wpcc-validation-asset.txt
snapshot_verify  → valid:true, hash_matches:true, "Snapshot is intact."
snapshot_details → metadata returned
snapshot_restore → file restored byte-for-byte
```
No corruption; no orphaned records.

## Patch + Rollback Engine lifecycle (PASS)

```
patch_create  → patch_id 0dc3312f-…, risk_level low, status pending_approval
patch_apply   → status applied, applied:true, rollback_id returned
file_read     → "WPCC validation asset. PATCHED line."   (change verified on disk)
rollback_get  → status applied, snapshot_ids present
rollback_apply→ status rolled_back, restored:true
file_read     → "WPCC validation asset. Original line."  (restoration verified on disk + via shell cat)
```

**Error paths (PASS):**
- Duplicate rollback → `wpcc_invalid_status` "Only applied patches can be rolled back."
- Invalid patch id → `wpcc_patch_not_found` "Patch not found."

## State-restoration integrity

- All 6 proven rollbacks restored the exact prior value (string/int/bytes), verified by a follow-up read.
- Patch/Snapshot restorations verified at the filesystem level (`cat`), not just via API.
- No orphaned rollback records observed; applied rollbacks are marked `rollback_applied` and correctly reject re-application.
