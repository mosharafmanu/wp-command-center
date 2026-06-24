# PROGRAM-4 — Certification GO / NO-GO (Phase G)

> **Branch:** `program-4-certification` @ `af6500d`. **No merge to main · no push · no deploy** (per rules; honored). Production `a41a9d7`.

---

## Definitive certification verdict

### Consolidation & code certification: **GO**
A single artifact (`program-4-certification`) now contains **all** intended Program-4 work — P4.0–P4.5, P4B, **P4.6**, P4C.0a, P4.7, P4.8, P4.9, P4.10 — plus the two bounded certification fixes. Consolidation merge was conflict-free; the two remediations are committed and validated; the full battery is green; invariants held; independent adversarial audit returned **GO**.

### Deploy execution: **NO-GO within this program (by rule), GO for deploy *review***
Deploy/push/merge-to-main are forbidden here and were not performed. The only un-closable blocker, **BLK-2's prod token-gated functional verify, is deploy-coupled** and therefore out of scope. The branch is **deploy-review-ready**; actual deploy + that final verify are a separate Rule-7 decision.

---

## The five required answers

**1. What Program-4 surfaces are certified?**
Ten F-1-closed surfaces (field-scoped/atomic-whole, drift-aware, sibling-safe, out-of-order-safe, honest history, legacy-compatible): **SEO, Settings, Media metadata, Content, Comments, Users, Woo Products, Bulk, ACF (value_update), Elementor.** Plus **Pattern C** (byte snapshot+verify): Patch/File, Media bytes, Media Enhancement.

**2. What rollback surfaces remain uncertified?**
- ACF **definition** ops (group/field/location/layout) — whole-definition + fingerprint drift-guard (safe/honest, not field-scoped).
- Woo **orders**, **variation_update**, **coupon_update** — not migrated (orders relational; variation/coupon updates lack rollback).
- **CPT, Forms, Menu, Widgets, SiteBuilder, OptionManager** — legacy unconditional/inverse restores, not drift-aware.
- **Plugin/Theme update** — now **honestly `reversible:false`** (irreversible by nature).
- **Non-field reversals** (media upload/replace/delete/featured, content delete, comment trash/delete, user create/role/suspend) — inverse-action/byte-snapshot, out of F-1 field-delta scope by design.

**3. Is Program-4 merge-ready?**
**YES** — into an integration branch / release candidate. It is a clean, conflict-free, fully-validated consolidation with all blockers closed (BLK-1, BLK-3, AR-MED-1). Per the rules, it was **not** merged to `main`. (Recommendation: keep `program-4-certification` as the release candidate; merge to `main` only at the deploy decision.)

**4. Is Program-4 deploy-review-ready?**
**YES.** The artifact is consistent, validated, invariant-preserving, and audited GO. The remaining deploy-coupled step (serial T2 on the deploy host + prod token-gated functional verify of the live rollbacks) is the standard pre-deploy gate and is the only thing between this branch and production — explicitly outside this no-deploy program.

**5. What is the single highest-risk remaining issue?**
**Silent rollback-id eviction on the option-tier certified runtimes (Settings, Media-metadata, Comments, Users) — and shared-option Woo/ACF-definition.** These are drift-correct but still persist to **FIFO-capped, autoloaded `wpcc_*_rollbacks` options**; on a busy store a surfaced `rollback_id` can be silently evicted (the durability weakness P4.7's keystone removed only for Bulk/ACF-value/Elementor/SEO). It does not corrupt data and does not block certification of *correctness*, but it is the highest residual **reliability** risk for the certified set. Recommended (non-blocking) follow-up: migrate those runtimes onto `PostMetaRollbackStore` for eviction-free parity.

---

## Risk summary

### HIGH
- *(closed)* Consolidation gap (BLK-1) — **RESOLVED** (P4.6 integrated; Woo F-1 closed at tip).
- *(closed)* `BulkAcfAccessor` clear-instead-of-restore (AR-MED-1) — **RESOLVED** + regression test.

### MEDIUM (non-blocking debt)
- Option-tier FIFO eviction on certified runtimes (Settings/Media/Comments/User + shared Woo/ACF-def) — **highest residual risk** (durability).
- Uncertified surfaces with real exposure: Woo variation/coupon updates (silent irreversibility), Woo orders, OptionManager (unbounded + unconditional).
- Accessor duplication remains (BulkAcfAccessor vs AcfValueAccessor; BulkWooAccessor vs WooProductAccessor) — now behavior-consistent, but duplicated.

### LOW (non-blocking)
- **D2:** `ContentManager.php:281` undefined `$before` in content.update audit (PHP notice + `old_status=null`; apply-path audit only). Reported; not fixed (bounded scope).
- Conflict-envelope inconsistency (ACF/Elementor hand-rolled vs `RollbackDelta::result`); CPT/OptionManager uncapped options; Settings permalink `rewrite_rules` uncaptured; "mark-applied-only-on-complete" is convention not core-enforced.

## Certification blockers — final status
| Blocker | Status |
|---|---|
| **BLK-1** P4.6 not integrated | **CLOSED** |
| **BLK-3** plugin/theme false reversibility | **CLOSED** |
| **AR-MED-1** BulkAcfAccessor defect | **CLOSED** |
| **BLK-2** acceptance gate | **PARTIAL** — local full battery GREEN; **prod token-gated functional verify is deploy-coupled** (deferred to the Rule-7 deploy decision; out of this program) |

## STOP-condition check
None triggered. No schema / DB_VERSION / capability / operation-registry / MCP contract / REST contract / security-model change. All edits were additive (response fields) or behavior-preserving fixes within existing accessors/runtimes. Invariants **34 · 23 · 40 · 40 · 2.5.0** held.

---

## FINAL VERDICT
**GO — Program-4 is certified (code-complete, consolidated, validated, audited) and merge / deploy-review ready.** The single remaining gate is the deploy-coupled prod functional verify (Rule-7), which this no-deploy program correctly does not perform. Highest residual risk = option-tier rollback-id eviction (reliability, non-blocking); recommended as the first item of any follow-up.
