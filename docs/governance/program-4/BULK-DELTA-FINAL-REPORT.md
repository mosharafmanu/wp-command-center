# PROGRAM-4.8 — Bulk Delta Redesign · Final Report

> **Branch:** `program-4.8-bulk-delta-redesign` (from `program-4.7-postmeta-rollback-store` @ `97e9ccd`, which carries P4C.0a hotfix + P4.7 keystone). **No merge / push / deploy.**
> Companion: [Forensic](BULK-DELTA-FORENSIC-REPORT.md) · [Design](BULK-DELTA-DESIGN.md) · [Validation](BULK-DELTA-VALIDATION-REPORT.md) · [Independent Audit](BULK-DELTA-INDEPENDENT-AUDIT.md).

## 1. Outcome
Bulk rollback is now **per-item, field-scoped, drift-aware**, closing the residual F-1 gaps left after the P4C.0a corruption hotfix. Each mutated item gets its own `RollbackDelta` v2 record in `PostMetaRollbackStore` (`_wpcc_bulk_rb_{itemRid}`), addressed by an indexed batch membership index (`_wpcc_bulk_b_{batchId}`). Restore is drift-aware (skip+report, never clobber), per-item isolated, honestly partial/conflict-reported, idempotent, and legacy-compatible — **no option, no FIFO eviction, no autoload, GC-with-post, schema-free.**

## 2. Phase A — forensic (post-hotfix residual gaps confirmed)
The hotfix fixed the corruption but left: G1 no drift detection (unconditional clobber), G2 one option-blob record per op (FIFO 200, eviction), G3 autoloaded/O(n), G4 no per-item isolation, G5 no partial truthfulness, G6 no out-of-order safety. P4.8 closes all six.

## 3. Phases B–C — design + implementation
- **`BulkRuntimeManager`** rewritten internals: each op captures touched fields (`RollbackDelta::capture`) → writes → reads after → `build_record` → `PostMetaRollbackStore::persist` + a batch membership meta row, **per item inside the loop** (so a mid-batch apply failure still leaves completed items reversible). `rollback()` resolves the batch by one indexed `meta_key` query, restores each item drift-aware with per-item `try/catch`, aggregates honest `complete/partial/conflict`, and falls back to the **unchanged legacy P4C.0a option path** for pre-P4.8 records.
- **New minimal Bulk-scoped accessors:** `BulkWooAccessor` (WC CRUD only, decimal-normalized drift) and `BulkAcfAccessor` (get_field/update_field, existence via metadata_exists). Post-column ops (content/status/media) **reuse `ContentFieldAccessor`**.
- Reuses `RollbackDelta` + `PostMetaRollbackStore` unchanged. All five bulk entities are post-bound → all records are postmeta.

## 4. Phases D–E — validation + independent audit
- **New delta suite: 53/0** — covers all 17 mission points incl. status-not-title, sibling preservation, drift conflict, out-of-order (no resurrection), partial across items, missing-item honesty, per-item isolation, idempotency, batch-index resolution, legacy record, no-FIFO, id surfacing, truthful aggregate.
- **Hotfix compat: 35/0** — the full P4C.0a behavior preserved against the new implementation.
- **Regression all green:** bulk-runtime 41/0, rollback-delta-core 25/0, PostMetaRollbackStore 30/0, SEO 56/0, Settings 38/0, Media 41/0, Content 30/0, Comments 27/0, User 28/0, Woo runtime 117/0, operations-registry 18/0, capability-runtime 61/0, mcp-error-surface 18/0, change-history-rollback 48/0 (standalone). **Net-new attributable failures: 0.**
- **Invariants:** 34 · 23 · 40 · 40 · 2.5.0 — held.
- **Independent audit: GO.** No GO-blocking defects across 15 vectors. One MEDIUM truthfulness defect (D-1: non-complete batch lacked `error=true`, so the executor read `success=true` on a zero-restored conflict) — **fixed** (partial/conflict now return `error:true`+`code`+`reversible:false`, matching `RollbackDelta::result()`; restored items stay applied, skipped/missing retryable). Re-validated green.

## 5. Scope / STOP
- Files: `BulkRuntimeManager.php` (internals) + new `BulkWooAccessor.php`, `BulkAcfAccessor.php` + new `test-bulk-delta-rollback.sh` + 2 retargeted static checks in `test-bulk-rollback-fix.sh`. `RollbackDelta`/`PostMetaRollbackStore`/`ContentFieldAccessor` byte-unchanged.
- **No** operation-registry / capability / MCP / REST-route / UI / schema / DB_VERSION / security change. Action set, routes, capability, MCP tool unchanged; response fields additive; new meta keys don't bump DB_VERSION. **No STOP condition triggered; no product-owner decision required** (per-item drift-aware reversal follows directly from the program goal).

## 6. GO / NO-GO
**GO** — F-1 closed for Bulk (drift-aware, per-item, sibling-preserving, out-of-order-safe, no-FIFO); corruption stays fixed; honest partial/conflict/missing reporting; legacy-compatible; invariants frozen; independent audit GO; attributable failures 0. **Committed on `program-4.8-bulk-delta-redesign` only — no merge / push / deploy.**

## 7. Next (per PROGRAM-4C roadmap)
P4.9 ACF and P4.10 Elementor reuse the same `PostMetaRollbackStore` keystone + (for non-decomposable blobs) the whole-def+drift-guard mode. The Bulk `BulkAcfAccessor` here covers only the single bulk_acf value; full nested-ACF fidelity remains P4.9.
