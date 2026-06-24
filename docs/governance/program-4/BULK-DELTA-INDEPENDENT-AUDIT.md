# PROGRAM-4.8 — Bulk Delta Independent Audit (Phase E)

> **Type:** independent adversarial audit (read-only; no code changes). Report-only.
> **Mandate:** assume the implementation is wrong; attack wrong-field restore, title/status mixup, item-order mismatch, missing item record, partial failure, repeated rollback, drifted item, malformed batch index, legacy record, batch>max, history truthfulness. **Auditor:** fresh agent, separate from the implementer.

---

## VERDICT: **GO**

No corruption, no clobber, no lost retryability, no fatal path, no scope violation found across 15 attacked vectors. Both suites pass against a live WP+WC+ACF install (hotfix 35/35, delta 53/53). One MEDIUM truthfulness defect (D-1) was reported and **fixed**.

## Checks

| # | Attack | Result | Evidence |
|---|---|---|---|
| 1 | Title/status mixup regression | **PASS** | bulk_status uses `ContentFieldAccessor` field `status`→`post_status` only; B2/D1 prove title never becomes a status word |
| 2 | Wrong-field restore / sibling clobber | **PASS** | capture/restore iterate only touched fields/`record['fields']`; D3/B3b sibling preserved |
| 3 | Drifted item skip+report | **PASS** | `RollbackDelta::restore` compares live vs `after`, skips on drift; runtime marks applied only on `complete` (partial/conflict retryable) |
| 4 | Batch index / wrong post_id | **PASS** | record + membership written on the same `$post_id`; resolve returns the row's own post_id; runtime prefers `entity_id` — always consistent |
| 5 | Missing item record | **PASS** | resolve null → `missing++` + continue; D7 proves; not fatal, not falsely restored |
| 6 | Partial failure isolation | **PASS** | per-item `try/catch(\Throwable)`; failed item `errored`, NOT marked applied; others continue |
| 7 | Idempotency / partial re-run | **PASS** | per-item `rollback_applied`; `err('done')` only when `already===total` & nothing skipped/missing/errored; drifted items re-attempted |
| 8 | Malformed / empty members | **PASS** | empty members → legacy fallback; non-array/empty record → missing; no fatal |
| 9 | Legacy P4C.0a record | **PASS** | legacy path restores status+title, scalar normalization, woo/acf-inactive unsupported, `done` idempotency; B7/D13 |
| 10 | MAX_ITEMS cap / no FIFO | **PASS** | 200 cap per op; zero `array_slice`; per-item postmeta = no eviction; D14 |
| 11 | Dependency gates | **PASS** | accessor null → batch-level `unsupported` (reversible:false), not marked applied → retryable |
| 12 | History truthfulness | **PASS (was CONCERN → fixed)** | see D-1 below |
| 13 | Accessor correctness | **PASS** | BulkWooAccessor WC-CRUD-only + decimal-normalized drift; BulkAcfAccessor get/update_field + metadata_exists existence; D10/D11 |
| 14 | Scope / invariants | **PASS** | only `BulkRuntimeManager.php` + test changed + 2 new accessors; RollbackDelta/PostMetaRollbackStore/ContentFieldAccessor byte-identical; no DB_VERSION/registry/schema/capability/MCP/REST/security change |
| 15 | Test rigor | **PASS** | functional oracle exercises drift-skip, sibling preservation, out-of-order (D5 no resurrection), partial, missing-item, no-FIFO with live-state assertions — not tautological |

## Defects

**D-1 — MEDIUM (truthfulness/consistency) → FIXED.** A `partial`/`conflict` batch rollback returned an envelope **without** `error=true`, so `OperationExecutor::rollback` (`$ok=empty($result['error'])`) reported `success=true` even when zero fields were restored — diverging from `RollbackDelta::result()` (which sets `error=true` + `code=wpcc_rollback_conflict` for that case). Not NO-GO (no corruption, no clobber, the conflict item is **not** marked applied so retryability is intact, and the detailed `status`/`skipped`/`per_item` fields were already honest — only the aggregate boolean was misleading).
- **Fix applied:** `rollback_batch` now returns `error:true` + `code` (`wpcc_rollback_partial`/`wpcc_rollback_conflict`) + `reversible:false` whenever `status !== 'complete'`, matching the single-item convention. Restored items stay applied; skipped/missing/errored stay retryable. Re-validated: bulk-delta 53/0, hotfix 35/0 — no regression.

## Conclusion
The Bulk delta redesign closes F-1 (per-item, field-scoped, drift-aware, sibling-preserving, out-of-order-safe, no-FIFO), preserves the corruption fix and every hotfix behavior, isolates per-item failures, reports honest partial/conflict/missing aggregates (post-fix), and stays legacy-compatible — all within Bulk scope with no contract/schema change. **GO for branch commit (Phase F).**
