# PROGRAM-4 — Architecture Consistency Audit (Phase B)

> **Type:** audit-only (no code changes). Verified at HEAD `c23fc19`.

---

## 1. Is RollbackDelta the canonical pattern?
**Yes, for named-field and atomic whole-field/whole-document surfaces.** 9 runtimes (SEO, Settings, Media-meta, Content, Comments, User, ACF, Bulk, Elementor) all route reversal through `RollbackDelta::restore` (verified: 1 call each) with a `FieldAccessor`. The original design's "typed convergence" holds: named-field → Pattern B (RollbackDelta); byte/file → Pattern C (snapshot+verify, Patch/Media-bytes/MediaEnhancement — intentionally separate and correct); atomic blobs (ACF nested values, Elementor document) → Pattern B with a whole-value/whole-document comparator; non-decomposable definitions (ACF config) → whole-def + fingerprint drift-guard. The abstraction (`FieldAccessor` + `RollbackDelta` + `RollbackStore` {OptionList, OptionKeyed, PostMeta}) is **coherent and essentially complete** for the surfaces addressed.

## 2. Findings

### HIGH
**A-H1 — Canonical pattern not applied at the consolidation tip for Woo, and the Woo accessor is divergent/stranded.** At HEAD, `WooCommerceRuntimeManager::product_update` still uses the legacy `snapshot_product` full-object snapshot (F-1) because **P4.6 is not in the lineage**. Meanwhile two Woo accessors exist conceptually: `WooProductAccessor` (P4.6, **absent at HEAD**) and `BulkWooAccessor` (P4.8, present, used only by `bulk_woocommerce`). The canonical product-rollback accessor is stranded on an unmerged branch. → Consolidate P4.6 (then product rollback joins the canonical set).

### MEDIUM
**A-M1 — Storage-tier inconsistency among migrated runtimes.** Settings, Media-meta, Comments, User, Content are drift-aware (Pattern B) but still persist to **FIFO-capped, autoloaded `wpcc_*_rollbacks` options** (caps 100–200), whereas Bulk, ACF-values, Elementor, and SEO use **postmeta-per-record** (`PostMetaRollbackStore` or equivalent: O(1), no FIFO, not autoloaded, GC-with-entity). The option-tier runtimes therefore retain the **silent FIFO-eviction** weakness (a surfaced `rollback_id` can vanish on a busy store) and per-request autoload cost that PROGRAM-4C's R-5 flagged and that P4.7 was built to eliminate. Correctness (drift) is equal; **durability/scalability is not**. The keystone exists but was only adopted by the 3 newest runtimes.

**A-M2 — Accessor duplication / divergence (ACF).** Two single-ACF-field accessors coexist: `BulkAcfAccessor` (P4.8 — name-based existence via `metadata_exists($selector)`) and `AcfValueAccessor` (P4.9 — resolves field **key→name**, reads **raw** `get_field(...,false)`). `bulk_acf` and `acf value_update` thus reverse "the same kind of thing" through **different** existence/format semantics. Both are individually validated, but the logic is duplicated and can drift apart. Same shape risk on the Woo side (`BulkWooAccessor` vs the stranded `WooProductAccessor`).

**A-M3 — Legacy runtimes remain off the canonical pattern.** Woo orders, CPT, Forms, Menu, Widgets, SiteBuilder, and OptionManager still use unconditional full-blob / inverse-action / full-value option restores with **no drift awareness**. They are weaker than the migrated set (F-1 / coverage-gap exposure persists). This is expected (out of the phases run) but is the bulk of the remaining surface and bears on certification scope.

### LOW
**A-L1 — Conflict-envelope inconsistency.** Settings/Content/Bulk build error envelopes via `RollbackDelta::result()`; ACF and Elementor hand-roll the conflict envelope (`error:true, code, status, restored:false`) and omit `result()`'s `conflicts`/`message`. Behaviorally correct and consistent *with each other*, but not unified with the core helper.

**A-L2 — SEO storage duplicates PostMetaRollbackStore semantics.** SEO (the reference) persists via direct `add_post_meta('_wpcc_seo_rb_{id}')` and resolves by an indexed `meta_key` query — exactly what `PostMetaRollbackStore` now generalizes — but predates the keystone and was not refactored onto it. Equivalent behavior; a duplicate storage path.

### INFO
**A-I1 — Two storage families coexist by design** (option-list/keyed vs postmeta). Acceptable; A-M1 is the actionable part.
**A-I2 — Pattern C (byte snapshot) is intentionally separate** and correct for Patch / Media-bytes / Media-Enhancement; not a divergence.
**A-I3 — `OptionManager` (`wpcc_option_rollbacks`) and `CPT` (`wpcc_cpt_rollbacks`) have no FIFO cap** → unbounded option growth (operational, carried from PROGRAM-4C).

## 3. Are there hidden divergence points?
- The **drift comparator** differs by accessor (string / sorted-int-set / normalized-JSON / fingerprint). This is intended per-type variety, not divergence — but it means correctness is per-accessor, so each accessor needs its own comparator test (they have them).
- The **"mark applied only on complete"** terminality rule is reimplemented per runtime rather than enforced by the core. All 9 implement it correctly, but it is a convention, not a guardrail (a future runtime could get it wrong). INFO.

## 4. Is the abstraction complete?
For the addressed surfaces, **yes**. Gaps are adoption, not abstraction: (a) option-tier runtimes haven't moved to the postmeta keystone (A-M1); (b) legacy runtimes haven't adopted Pattern B at all (A-M3); (c) accessor logic is duplicated where bulk and single-entity paths overlap (A-M2). None require a new abstraction — only consolidation and further adoption.

## 5. Should any completed phase be revised?
- **P4.6 is correct but stranded** — the revision needed is **integration**, not rework (A-H1).
- No migrated runtime's logic is flawed. The architecture-level debt (A-M1/A-M2/A-L2) is **consolidation/uniformity debt**, not correctness debt — appropriate to address in a consolidation/certification phase, not by reopening individual phases.
