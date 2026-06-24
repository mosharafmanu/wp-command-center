# PROGRAM-4 — Final Recommendation (Phase F)

> **Type:** audit-only decision. No code/commit/merge/deploy. Verified at HEAD `c23fc19`; production `a41a9d7`.

---

## Decision: **C) Begin a (bounded) Certification Program**

**NOT B (Merge & Deploy)** — three certification blockers + one code defect make immediate deploy unsafe.
**NOT open-ended A** — the program's core goal (F-1 on the major mutation surfaces) is substantially achieved; continuing it as "migrate everything" would be roadmap expansion (out of bounds).
**NOT D** — the work is near-complete and high-value; abandoning it now is unjustified.

A **Certification Program** is the precise next step: it consumes the blockers, fixes the one real defect, runs the gate, and produces an **honestly-scoped, shippable** reversibility claim — without expanding scope to the legacy surfaces (which become a clearly-separate, optional follow-on).

### Why C, with evidence
- The engineering is sound and honest **per surface**: 9 runtimes drift-aware/honest/validated (re-verified green at HEAD: SEO 56, Settings 38, Media 41, Content 30, Comments 27, User 28, Bulk 53, ACF 47, Elementor 34; core 25; keystone 30), invariants 34·23·40·40·2.5.0 held, no schema/contract drift, an active Bulk corruption bug found-and-fixed.
- But the **program-level** state is not shippable as-is: not consolidated (P4.6 stranded → tip is F-1-vulnerable for Woo), not deployed, acceptance gate open, one latent code defect (`BulkAcfAccessor`), and ~10 surfaces still uncovered.
- These are **integration + validation + one back-port + one honesty flag** — no new architecture. That is exactly a certification effort, not more Program-4 feature work.

### Bounded entry criteria for the Certification Program (consume the blockers, in order)
1. **Consolidate** P4.6 + the P4.10 lineage into one branch (merge is clean — verified: disjoint runtime files, no conflict markers). Re-run the full battery + **serial T2**.
2. **Fix AR-MED-1**: back-port P4.9's key→name + raw-read fixes into `BulkAcfAccessor` (or route `bulk_acf` through `AcfValueAccessor`); add a key-selector test.
3. **BLK-3**: plugin/theme **update** → `reversible:false` honesty flag (no false contract).
4. **Acceptance gate**: serial T2 (net-new attributable 0) + prod token-gated functional verify → **Rule-7 deploy decision**.
5. **Certify the defined set** — {SEO, Settings, Media-meta, Content, Comments, Users, Woo Products, Bulk, ACF, Elementor} + Pattern-C (Patch, Media-bytes, Media-Enhancement) — and **honestly mark every other surface as not-yet-covered.**
> Durability (AR-MED-2: option-tier → postmeta) and legacy-surface migration (H-2) are **explicitly outside** the certification claim → a separate optional program.

---

## OUTPUT SUMMARY

### HIGH risks
- **H-1 Consolidation gap** — P4.6 stranded; tip (P4.10) is **F-1-vulnerable for Woo products** (`snapshot_product`, `WooProductAccessor` absent). No single branch holds all completed work.
- **H-2 Zero-deploy reality** — production runs the pre-Program-4 F-1 rollbacks on every surface; the guarantee is built, not shipped.
- **H-3 `BulkAcfAccessor` latent defect** — key-selector existence + formatted-read not back-ported from P4.9 → `bulk_acf` rollback can clear-instead-of-restore for key selectors (the one concrete code defect).

### MEDIUM risks
- **M-1** Option-tier migrated runtimes (Settings/Media/Comments/User/Content) retain FIFO-capped autoloaded storage → silent `rollback_id` eviction (durability ≠ correctness).
- **M-2** Silent irreversibility on real surfaces: Woo `variation_update`/`coupon_update` (0 `store_rollback`), plugin/theme update (false reversibility contract).
- **M-3** ~7 legacy surfaces non-drift-aware: Woo orders, CPT, Forms, Menu, Widgets, SiteBuilder, OptionManager.
- **M-4** Accessor duplication/divergence (ACF: Bulk vs Value; Woo: Bulk vs Product).

### LOW risks
- **L-1** Conflict-envelope inconsistency (ACF/Elementor hand-rolled vs `RollbackDelta::result`).
- **L-2** SEO storage duplicates the keystone; CPT/OptionManager uncapped option growth.
- **L-3** Settings permalink `rewrite_rules` not captured; "mark-applied-only-on-complete" is a convention, not a core guardrail.

### Certification blockers
- **BLK-1** Consolidate P4.6 + lineage (re-validate).
- **BLK-2** Acceptance gate (serial T2 + prod functional verify) + deploy.
- **BLK-3** Plugin/theme update `reversible:false` honesty.
- (**+ AR-MED-1** `BulkAcfAccessor` fix — promote to blocker for the ACF certification line.)

### Remaining rollback surfaces (not yet covered)
Woo orders, Woo variation/coupon updates, Forms (update), Menu (item_reorder), CPT, Widgets, SiteBuilder, OptionManager, Plugin update, Theme update. (Media-Enhancement/Patch/Media-bytes already correct via Pattern C.)

### Recommended next program
**Program-4 Certification & Consolidation** — bounded: clear BLK-1/2/3 + AR-MED-1, then certify the defined set and honestly scope out the rest. The legacy-surface migration is a **separate, optional** future program (not required for a scoped, honest certification).

### GO / NO-GO
- **Merge & Deploy now: NO-GO** (BLK-1/2/3 + AR-MED-1 open).
- **Begin the Certification Program (Option C): GO** — entry criteria above; no new architecture required; all blockers are small-to-medium and addressable.

### Should any completed phase be revised?
- **P4.6** — integrate (stranded), do not rework.
- **P4.8 `BulkAcfAccessor`** — **revise** (back-port P4.9 fixes); the only completed phase with a genuine residual code defect.
- All other phases stand.
