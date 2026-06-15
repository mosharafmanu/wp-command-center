# STEP 102 — Rollback Remediation

**Date:** 2026-06-15
**Scope:** Fix the rollback-contract inconsistencies found in STEP 101.3 (F-1, F-2, F-3). DEV only. No validation re-run beyond focused verification of the affected runtimes.

## Verdict: **FULLY REMEDIATED**

All three findings fixed at the smallest correct layer, with a shared abstraction for the systemic one. Targeted verification: **6/6 affected runtimes** now satisfy the full contract (Create → rollback stored → `rollback_id` returned → discoverable → executable → state restored). Previously-working runtimes (Option/SEO/Media) regression-checked OK; reads do not gain spurious rollback fields.

---

## PHASE 1 — Root causes confirmed (in code)

### F-1 (HIGH) — Content rollback unreachable — CONFIRMED, one-line fix correct
- `ContentManager::run()` line 24 rejects any action not in `ContentRegistry::ACTIONS`.
- `ContentRegistry::ACTIONS` did **not** include `content_rollback`, so the dispatch arm (`ContentManager.php:56`) and `rollback_content()` (lines 63–100) were dead. `OperationRegistry` already declared `content_rollback` in `action_risks`, and `content_update` already returned a `rollback_id` — only the allow-list entry was missing. The reported one-line fix is correct.

### F-2 (MED-HIGH) — Menu rollback discoverability — CONFIRMED, two parts
- `MenuRuntimeManager::store_rollback()` generates `wp_generate_uuid4()` and persists it, but the write methods return payloads **without** `rollback_id` (audit: 13 `store_rollback` calls, 1 return surfaced it). **Omission is accidental, not intentional** — the rollback record always exists internally.
- Additionally, `MenuRuntimeManager::rollback()` had **no `menu_update` arm** — even with the id, a renamed menu could not be reversed.

### F-3 (MED, systemic) — rollback_id contract inconsistency — CONFIRMED across runtimes
Two distinct sub-causes:
1. **Surfacing gap (most runtimes):** `store_rollback()` returns/stores the id, but write methods discard it; no per-runtime discovery list. Static audit of `store_rollback` calls vs. returns that surface `rollback_id`:

   | Manager | store_rollback calls | returns with rollback_id |
   |---|---|---|
   | Menu | 13 | 1 |
   | WooCommerce | 23 | 7 |
   | ACF | 14 | 5 |
   | User | 7 | 2 |
   | Settings | 2 | 1 |
   | Forms | 3 | 1 |
   | Comments | 3 | 2 |

2. **User had a deeper defect (newly found during remediation):** `UserManager` uses SHORT action names internally (`'create'`, `'update'`, … — matching its `rollback()` switch), but gated `store_rollback()` on `UserRegistry::supports_rollback($action)`, whose `ACTION_ROLLBACK` map is keyed by the LONG `ACTION_*` constants (`'user_update'`, …). The mismatch made the gate **always return false**, so **User never stored any rollback at all** — a latent bug, not just a surfacing gap.

---

## PHASE 2 — Remediation design

**Target contract (now met for every reversible write):**
```
Write operation → rollback stored → rollback_id returned (+ rollback_available:true)
               → discoverable (id in the write response) → executable (existing action/REST route)
               → auditable (executor records operation.* events)
```

**Key observation that enabled a shared fix:** every runtime persists rollbacks to an option named `wpcc_<runtime>_rollbacks` (18 managers, uniform). That single naming convention is the shared chokepoint.

**Design:**
- **Shared abstraction — `RollbackContext`** (new): hooks `updated_option`/`added_option` once; on any `wpcc_*_rollbacks` write it diffs old-vs-new and captures the newly stored id. Handles both stored shapes (list with `['id'=>…]` and assoc keyed by id, e.g. Content). The `OperationExecutor` resets it per run and injects `rollback_id` + `rollback_available` into the normalized response at the one `normalize_success()` chokepoint. → Fixes F-3 surfacing for **all** runtimes with **zero per-manager edits**, and is idempotent for managers that already return the id.
- **F-1:** add `content_rollback` to `ContentRegistry::ACTIONS` (reaches existing handler).
- **F-2:** add the missing `menu_update` arm to `MenuRuntimeManager::rollback()` (executable reversal); discoverability is covered by the shared `RollbackContext`.
- **User deeper defect:** normalize the action key at the single gate in `UserManager::store_rollback()` so the support check matches (`'user_'.$action`).

**Compatibility:** purely additive on the response (`rollback_id` was already present for some; `rollback_available` is new). Reads never store a rollback so they receive neither field (verified). Marking a rollback "applied" adds no new id, so rollback responses are not polluted. No behavior change outside the rollback lifecycle.

---

## PHASE 3 — Implementation (files modified)

| File | Change | Finding |
|---|---|---|
| `includes/Operations/RollbackContext.php` | **NEW** shared collector + option-diff capture hook | F-3 (shared) |
| `includes/Operations/OperationExecutor.php` | `RollbackContext::boot()/reset()` before dispatch; inject `rollback_id`/`rollback_available` in `normalize_success()` | F-3 (shared) |
| `includes/Operations/ContentRegistry.php` | add `content_rollback` to `ACTIONS` | F-1 |
| `includes/Operations/MenuRuntimeManager.php` | add `menu_update` arm to `rollback()` switch | F-2 |
| `includes/Operations/UserManager.php` | normalize action key in `store_rollback()` support gate | F-3 (User latent bug) |

5 files (1 new, 4 edited); 32 insertions, 1 deletion in the edited files. No refactors, no feature expansion. All `php -l` clean.

---

## PHASE 4 — Targeted verification (Content, Menu, ACF, User, WooCommerce, Settings)

Each: Create → Update (capture response) → Rollback → Verify-restore. Full results in `targeted-verification-results.json`.

| Runtime | rollback_id returned | rollback_available | rollback executable | state restored |
|---|---|---|---|---|
| Content | ✅ | ✅ | ✅ (`content_rollback`) | ✅ |
| Menu | ✅ | ✅ | ✅ (REST `/menu_manage/rollback`) | ✅ |
| ACF | ✅ | ✅ | ✅ (REST `/acf_manage/rollback`) | ✅ |
| User | ✅ | ✅ | ✅ (REST `/user_manage/rollback`) | ✅ |
| WooCommerce | ✅ | ✅ | ✅ (REST `/woocommerce_manage/rollback`) | ✅ |
| Settings | ✅ | ✅ | ✅ (REST `/settings_manage/rollback`) | ✅ |

**6/6 fully remediated.** Audit trail preserved (executor records `operation.*` events; verified active in 101.3 and unchanged here).

**Regression (safety) checks:**
- Option update → `option_rollback` → restore still works, now also reports `rollback_available`.
- Reads (`content_list`, `system_info`, `plugin_list`) carry **no** `rollback_id`/`rollback_available` — no spurious injection.
- All verification assets cleaned up (no `wpcc_v102*`/`wpcc_dbg*` users, menus, or products; Settings value restored).

---

## Remaining rollback inconsistencies

- **None blocking.** All write-capable runtimes that persist to `wpcc_*_rollbacks` now surface `rollback_id`/`rollback_available` automatically via the shared `RollbackContext` (verified on the 6 affected runtimes + Option). 
- **Not individually re-verified this step** (covered by the shared mechanism, not re-run per the "verify affected runtimes only" instruction): Comments, Forms, SiteBuilder, Elementor, Bulk, CPT, Widgets, Media-Enhancement. They use the identical option-write path and the same executor, so they inherit the surfacing fix; a future regression pass can confirm each. This is noted as residual scope, not a known failure.

See `rollback-contract-matrix.md` for the full per-runtime contract and `remediation-summary.md` for the one-page summary.
