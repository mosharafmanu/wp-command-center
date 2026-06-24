# PROGRAM-4 — Adversarial Review (Phase E)

> **Type:** audit-only adversarial review. Assumed Program-4 is wrong and attacked it; verified independently at HEAD `c23fc19` (prior reports NOT trusted).

---

## Attack results

### AR-HIGH-1 — Program-level consolidation was never verified (the deepest miss)
Every per-phase report (P4.7→P4.10) states its base "carries P4C.0a hotfix + keystone + …" — each locally true — **but none verified that the chain they built on (P4C.0a, forked from P4B) excluded P4.6.** Independently verified: **P4.6 is not an ancestor of HEAD**; at the tip, `WooProductAccessor.php` is absent and `product_update` uses `snapshot_product` (F-1). The individual phase audits all passed, yet **the program as a whole does not exist as one artifact, and the consolidation tip is F-1-vulnerable for Woo products.** Per-phase GO ≠ program GO.

### AR-HIGH-2 — "GO" reports can be misread as production-safe
All FINAL-REPORT verdicts are **GO-for-branch-commit**, and each correctly says "no deploy." But the cumulative framing risks an over-claim: **nothing is deployed; production `a41a9d7` still runs the pre-Program-4 F-1 rollbacks on every surface.** A certification claim made from the reports alone, without noting the deploy reality, would be false. (Honesty defect in how the body of work could be *read*, not in the code.)

### AR-MED-1 — `BulkAcfAccessor` was not back-ported with P4.9's fixes (latent corruption path)
Verified: `BulkAcfAccessor` (P4.8) checks existence via `metadata_exists('post',$id,$field_key)` (no key→name resolution) and reads `get_field($field_key,$id)` **formatted** (no `false`). P4.9's `AcfValueAccessor` fixed *both* (resolves `acf_get_field(selector)['name']`; reads raw `get_field(...,false)`). Consequences for `bulk_acf` rollback:
- **(i)** Called with a field **key** selector → existence resolves false → on a clean (non-drift) rollback, `key_delete`→`update_field(null)` **clears a field whose prior value should have been restored** (existence-fidelity corruption). Latent: only triggers with key selectors (P4.8's test used names).
- **(ii)** For formatted-return fields (relationship/image) → captures/restores the formatted (non-storable) value.
This is a genuine **divergence-induced defect** the per-phase audits missed because P4.8 predated P4.9's insight and was not revisited. Severity MED (selector/field-type-dependent; bulk_acf less common than value_update).

### AR-MED-2 — Durability ≠ correctness for the option-tier migrated runtimes
Settings/Media/Comments/User/Content are drift-correct but still persist to **FIFO-capped, autoloaded options** (verified `array_slice` cap in Comments/Media/User; store-cap in Settings). A surfaced `rollback_id` can be **silently evicted** on a busy store → an honest-but-unresolvable rollback. The per-phase reports framed these as "closed"; they are **correctness-closed, durability-partial** (the very weakness P4.7's keystone was built to remove, adopted only by Bulk/ACF/Elementor).

### AR-MED-3 — Silent irreversibility persists on real surfaces
Verified at HEAD: `variation_update`/`coupon_update` have **0** `store_rollback` calls (Woo price/discount edits irreversible); plugin/theme **update** capture nothing yet are approved as reversible (false contract). These were named in PROGRAM-4C but never addressed; a deployed-but-unconsolidated build would ship them as-is.

### AR-LOW-1 — Conflict-envelope inconsistency
ACF/Elementor hand-roll the conflict envelope (omit `RollbackDelta::result()`'s `conflicts`/`message`). Behaviorally correct; cosmetic divergence.

### AR-LOW-2 — Duplicate storage path (SEO) + uncapped options (CPT/OptionManager)
SEO reimplements the postmeta store the keystone now provides; CPT/OptionManager options are uncapped (unbounded growth). Operational, not corrupting.

### AR-INFO — Things attacked that held up
- **No missed mutation surface:** ChangeHistory/Workflow `rollback()` are dispatchers/orchestrators, not mutation surfaces; `RollbackManager` is the Patch/file Pattern-C engine. All real surfaces are inventoried.
- **Honesty of the 9 migrated runtimes:** all mark applied **only on `complete`** (verified Settings/Media/Comments/User + the postmeta trio) → no false clean-success on partial/conflict.
- **Drift-awareness is genuine:** all 9 call `RollbackDelta::restore` (not just capture).
- **Invariants:** 34·23·40·40·2.5.0 held at HEAD; no schema/registry/MCP/REST/capability/security drift.
- **P4.6 merge cleanliness:** the P4.10 lineage never touched `WooCommerceRuntimeManager` and `merge-tree` shows no conflict markers → consolidation is low-risk.
- **Only one stranded branch** (P4.6); P4.1–P4.5 are correctly folded via the P4B octopus.

## Did any previously-completed phase prove flawed?
- **P4.6** — implementation correct; **flaw is integration** (stranded). Fix = consolidate, not rework. (AR-HIGH-1)
- **P4.8 `BulkAcfAccessor`** — **genuinely flawed** for key selectors / formatted fields; not corrected when P4.9 discovered the issue. This is the one concrete code defect this review surfaces. (AR-MED-1)
- All other migrated phases held up under independent attack.

## Net adversarial verdict
The core engineering is sound and honest **per surface**, but the **program-level** picture has one real code defect (AR-MED-1), one integration gap that leaves the tip F-1-vulnerable (AR-HIGH-1), a durability shortfall on 5 runtimes (AR-MED-2), unaddressed silent-irreversibility surfaces (AR-MED-3), and a zero-deploy reality (AR-HIGH-2). None invalidate the approach; all are addressable in a consolidation/certification pass without new architecture.
