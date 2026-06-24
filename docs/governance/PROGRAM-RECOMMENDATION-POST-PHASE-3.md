# Program Recommendation — The Next Highest-Leverage Program After Phase 3

> **Type:** Recommendation report (no code, no implementation). Produced under autonomous program mode.
> **Date:** 2026-06-23 · **Author pass:** analysis only — no code/commit/push/deploy.
> **Authority docs:** [`SESSION-HANDOFF-PHASE-3.md`](../product/SESSION-HANDOFF-PHASE-3.md) · [`PRODUCT-MASTER-PLAN.md`](../product/PRODUCT-MASTER-PLAN.md) · [`UX-AUDIT-AND-DESIGN-SYSTEM.md`](../product/UX-AUDIT-AND-DESIGN-SYSTEM.md)
> **Decision requested:** approve the recommended program so a Phase design report (rule 2) can be produced for its first phase.

---

## 0. TL;DR

**Recommended next program: `PROGRAM-4 — Rollback Integrity` (close F-1 everywhere).**

Finish what Phase 3 started. F-1 — rollback snapshot over-reach — is CLOSED for SEO but **OPEN systemically** for Media, ACF, Woo, and Settings/Options, and it is **HIGH severity, data-corrupting, and live in production today**. It is the single open defect that directly falsifies the product's core promise (audited reversibility / the Four Guarantees). Every higher-value phase (B certification, C cockpit, D governed-action console, E workflows) inherits and *amplifies* this defect until it is closed. Closing it is both the largest risk-reduction and the precondition that lets the business-value phases be built on honest ground.

The program runs in two ordered phases:
- **Phase 3-Gate (verification, no code):** acceptance-gate the already-live SEO delta fix before replicating its pattern.
- **Phase 4 (implementation):** extend the proven field-scoped delta pattern, runtime-by-runtime, to the four sibling runtimes that still over-reach.

---

## 1. What "after Phase 3" actually means

Phase 3 (`7aa7e84`) shipped a field-scoped delta rollback for the **SEO** runtime and pull-deployed it. Two things remain undone from Phase 3 itself, per the handoff:

1. **The acceptance gate was never run.** The live SEO fix has not cleared serial T2 + Stage-A (S3B/S4/S5 + B3/B4) + a prod token-gated functional verify. *Deployed ≠ acceptance-gated.*
2. **F-1 is a pattern, not a single bug.** Only the SEO instance is fixed. The full-object-snapshot over-reach still lives in Media (`SnapshotManager`), ACF, Woo, and Settings/Options.

So "after Phase 3" is not a clean greenfield — Phase 3 left a verification gap and an explicitly-scoped systemic continuation. The highest-leverage move is to *finish the thread that is already half-pulled*, not to open a new one.

---

## 2. The candidates considered

| # | Candidate program | What it buys | Why not first |
|---|---|---|---|
| **A** | **Rollback Integrity** — gate SEO fix, then systemic F-1 closure (Media/ACF/Woo/Settings) | Removes the one HIGH, live, data-corrupting defect; makes the Rollback Guarantee true everywhere; de-risks every later phase | — **(recommended)** |
| B | **Phase B — Platform Hardening & Certification** (S1 caching, S2/S3 pagination, W1 catalogue-as-registry, W3 least-privilege) | Certification readiness; 200-op scalability | Phase B's own success criteria require "the Four Guarantees held." You **cannot certify** with F-1 open. B is gated *behind* Rollback Integrity. Several P0 items (W2, C1) already landed. |
| C | **Phase C — UX & CDS** (branded shell, 5-C IA, design system) | Product identity, sellability | Highest business upside but depends on B; building a cockpit over a rollback that silently corrupts data ships a trust facade. Wrong order. |
| D | **Phase D — Governed Action console** (read-only → act) | Turns the product from viewer into tool | *Increases* mutation volume — directly multiplies F-1's blast radius. Strictly worse to do before F-1 closure. |
| — | **A2-1 uncatchable-fatal reaper** | Closes the strand-in-`executing` edge | Requires a `claimed_at` column = **schema migration + DB_VERSION bump** (rule-7 check-in). Keep it OUT of this program; schedule independently. |

---

## 3. Why Rollback Integrity is the highest leverage

**1. It is the only HIGH-severity defect that is both live and data-corrupting.** F-1 was escalated MEDIUM→HIGH precisely because a broken rollback does not fail loudly — it silently wipes sibling fields, clobbers same-field values, resurrects reverted changes out of order, and *leaves a misleading "applied" history*. A wrong undo is worse than no undo: it lies in the audit trail, which is the product's whole reason to exist.

**2. It is the precondition for everything else, not a parallel track.** All three authority docs make the Four Guarantees inviolable ("the moat," "AI power with a seatbelt," "non-negotiable for every capability"). A guarantee that is provably broken in four of the mutating runtimes is an existential contradiction of the entire AIOps-for-WP positioning. Phase B certification *checks* this; Phase C builds a cockpit *over* it; Phase D *multiplies* the mutations that exercise it; Phase E composes multi-step *rollback-of-a-sequence* out of this very primitive. Every later phase inherits the defect and widens its blast radius. Closing it first is the only sequencing that doesn't compound risk.

**3. It is de-risked and bounded.** The hard part — the design — is done and proven for SEO: versioned field-scoped delta (prior value + prior-existed flag + apply-time after-value + provider + content identity), field-scoped restore, drift-skip, existed-vs-empty fidelity, truthful restored/skipped/conflict reporting, legacy-compatible. Phase 4 is *replication of a proven pattern*, not invention. That is the rare combination of highest-risk-to-close and lowest-uncertainty-to-execute.

**4. It likely holds all five invariants and trips few check-in gates.** The SEO fix required **no schema / op / cap / tool change** (invariants stayed 34 / 23 / 40 / 40 / 2.5.0). The systemic rollout should aim for the same — the delta rides inside existing rollback records. *This must be confirmed per runtime in the Phase 4 design report* (see §6 risk R2): if any sibling's rollback storage cannot carry the delta without a column, that runtime trips a schema-change check-in.

---

## 4. Proposed program shape (for approval — not yet a design report)

> Per rule 2, a full design report (risks · affected files · validation plan) is produced **before each phase**, after this program is approved. The outline below is scope, not that report.

### Phase 3-Gate — Acceptance-gate the live SEO fix *(do first; verification, no code)*
- Re-run Stage-A **S3B / S4 / S5** (field-fidelity preserved) and **B3 / B4** (drift handled, no sibling loss, no resurrection).
- Targeted regression on execution-lifecycle + SEO suites; **serial T2** with net-new attributable = 0.
- **Prod token-gated functional verify** of SEO delta rollback (the live-but-ungated gap).
- Exit: SEO F-1 marked *truly closed* (deployed **and** gated). No replication until this passes — replicating an unverified pattern would 5× the unverified surface.

### Phase 4 — Systemic F-1 closure *(implementation; one sub-phase per runtime)*
Ordered by blast radius / reversibility-criticality: **Media (`SnapshotManager`) → ACF → Woo → Settings/Options.**
Each runtime is its own design-report → implement → self-audit → validate → report cycle (rules 2–4), because each runtime's snapshot shape and write path differ. Per runtime:
- Convert full-object snapshot → versioned field-scoped delta (mirror the SEO contract).
- Field-scoped restore + drift-skip + existed-vs-empty fidelity + truthful reporting + legacy-record compatibility.
- Validate against that runtime's rollback suite + serial T2 (net-new 0) before requesting deploy.

---

## 5. Rule-7 check-in gates this program will trip

| Trigger | Expected in this program? |
|---|---|
| Schema change | **Not intended** — delta rides existing rollback records. **Must be confirmed per runtime** in each Phase 4 design report (R2). If a column is needed → check-in. |
| Security-model change | No. |
| Capability expansion | No — invariants frozen at 34 / 23 / 40 / 40 / 2.5.0. |
| MCP contract change | No — no new tools; rollback semantics are internal. |
| **Production deployment decision** | **Yes, every deploy.** Each runtime fix is implement-locally-then-request-deploy. Pull-based deploy makes a push live in ~1 min, so each deploy is an explicit gated decision. |

So: **no autonomous schema/security/cap/MCP changes anticipated; every production push is an explicit check-in.** A2-1's reaper is deliberately excluded precisely because it *does* require a schema bump.

---

## 6. Risks of choosing (and running) this program

- **R1 — "Boring" risk perception.** This program produces no new user-visible features; it hardens an invisible guarantee. Mitigation: it is the gate that lets the *visible* phases (C/D) be built honestly — frame it as foundation, not detour.
- **R2 — Hidden schema dependency.** A sibling runtime may not be able to store the delta in its existing rollback record without a column. Mitigation: each Phase 4 design report verifies storage-fit first; if a bump is required, that runtime escalates to a rule-7 check-in before any code.
- **R3 — Per-runtime divergence.** Media is byte/file-oriented (`SnapshotManager`), not field/meta-oriented like SEO — the delta abstraction may not map 1:1. Mitigation: Media is sequenced first *because* it is the least SEO-like, so the abstraction is stress-tested early, not last.
- **R4 — Regression baseline drift.** Touching four rollback paths risks net-new failures. Mitigation: serial T2 net-new-0 discipline per runtime; do not refresh the baseline as part of this program unless explicitly directed.
- **R5 — Phase 3-Gate uncovers a real SEO defect.** Possible — the fix is unverified. Mitigation: that is exactly why the gate runs *before* replication; a failure stops the program (rule 4), fixes SEO, re-validates, then proceeds.

---

## 7. Recommendation & immediate next step

**Approve `PROGRAM-4 — Rollback Integrity`.** It closes the highest open risk, makes the Rollback Guarantee true across the platform, and is the precondition that unblocks Phase B certification and the Phase C/D value phases.

**Immediate next step on approval:** produce the Phase design report for **Phase 3-Gate** (acceptance-gate the live SEO fix) — risks, affected files/suites, and a concrete validation plan — per rule 2. No implementation until that report is approved.

> Reminder of standing constraints (handoff §8): do not enable AI flags, set keys, change security mode, or refresh the regression baseline; any new code or deploy requires explicit owner authorization.
