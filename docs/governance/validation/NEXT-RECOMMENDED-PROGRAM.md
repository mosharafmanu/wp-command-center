# Next Recommended Program — PROGRAM-4: Rollback Integrity

> **Date:** 2026-06-23 · **Basis:** Phase 3 acceptance gate (PASS) + Phase B 10-category real-world validation (this session).
> **Type:** recommendation (no implementation). Supersedes the pre-validation sketch in `../PROGRAM-RECOMMENDATION-POST-PHASE-3.md` by folding in validation findings G1 + G2.

---

## Thesis

Phase 3 proved a field-scoped, drift-aware delta rollback for **one** runtime (SEO) and validation confirms it works. Validation also confirmed, by source, that **nine other runtimes still use the full-object snapshot** that F-1 proved is corrupting, and that **plugin/theme update is silently irreversible**. The single highest-leverage program is to make the **Rollback Guarantee true platform-wide** — extend the proven pattern systemically (G1) and close the update-reversibility gap (G2).

It is the dominant open risk, it is design-proven (low uncertainty), and it is the precondition for Phase B certification and every value phase after it (a cockpit/console built over a latently-corrupting rollback inherits the defect).

---

## Scope

**In:**
- **G1 — systemic F-1 delta rollout** to the full-object runtimes: Content, Woo, Settings, ACF, User, Forms, Comments, Bulk, and **Media-metadata**. Reuse the SEO delta contract (versioned field map + prior value + existed flag + apply-time after-value + drift-skip + honest restored/skipped/conflict reporting) and the File/Patch verify discipline.
- **G2 — plugin/theme update reversibility/visibility:** either capture a pre-update artifact (version/ZIP) as a rollback handle, or mark the action **visibly irreversible** in the result + require acknowledgement. Either satisfies the Four Guarantees' "reversible **or visibly** irreversible" clause.

**Out (schedule separately):**
- **A2-1 reaper** — needs a `claimed_at` column (schema migration + DB_VERSION bump = Rule-7 check-in). Independent of F-1.
- **Prod token-gated functional verify** + **full serial T2** — deploy-coupled; run at the deploy decision.
- Test hygiene (step91 provider, backfill count fragility) — optional ride-along, not directed.

---

## Phases (each = design report → implement → self-audit → validate → report, per program rules)

> **Sequencing rule:** order by *layered-edit-proneness* (how often the same object's distinct fields are edited across separate changes — the exact condition that triggers F-1), validating each runtime before the next.

1. **Phase 4-Gate (verification, no code):** the deploy-coupled residuals of Phase 3 — full serial T2 + prod token-gated SEO verify — when a deploy decision is authorized. Confirms the SEO pattern in prod before replicating it.
2. **Phase 4a — ACF** (field groups are the most layered-edit-prone; highest F-1 exposure).
3. **Phase 4b — Woo** (products edited field-by-field over time).
4. **Phase 4c — Content + Settings** (post meta / option groups).
5. **Phase 4d — Media-metadata** (alt/caption/title/description; keep the strong file-byte snapshot path as-is).
6. **Phase 4e — User / Forms / Comments / Bulk** (lower layering frequency; batch last).
7. **Phase 4f — G2 plugin/theme update** reversibility/visibility fix (independent; can run in parallel with any of the above).

Each phase: confirm the delta fits the runtime's existing rollback storage **without a schema change** (R2 below); if a column is unavoidable, that runtime escalates to a Rule-7 schema check-in *before* code.

---

## Risks of the program

- **R1 — invisible-work perception:** hardens a guarantee, ships no feature. Mitigation: frame as the gate that lets visible phases (C/D) be built honestly.
- **R2 — hidden schema dependency:** a runtime may not store the delta in its existing record. Mitigation: storage-fit check first in each phase's design report; escalate if a column is needed.
- **R3 — per-runtime divergence:** Media-bytes (snapshot) and Woo (object graph) differ from SEO meta. Mitigation: ACF/Woo first stress-tests the abstraction early; Media-bytes path untouched.
- **R4 — regression drift across nine paths:** Mitigation: serial-T2 net-new-0 discipline per phase; do not refresh the baseline unless directed.
- **R5 — Phase 4-Gate uncovers a real prod SEO issue:** Mitigation: that's why it runs before replication; a failure stops and fixes SEO first (Rule 4/5).

---

## Rule-7 check-in gates

| Trigger | Expected? |
|---|---|
| Schema change | Not intended for G1/G2 — **confirm per runtime** (R2); A2-1 (out of scope) **does** need one. |
| Security-model change | No. |
| Capability expansion | No — invariants frozen 34/23/40/40/2.5.0. |
| MCP contract change | No — rollback semantics are internal; tool/op parity unchanged. |
| **Production deployment** | **Yes, every deploy** — implement-locally-then-request-deploy. |

---

## Recommendation

**Approve PROGRAM-4 (Rollback Integrity).** Immediate next step on approval: produce the **Phase 4a (ACF) design report** — risks, affected files, validation plan — per the program's "design report before every phase" rule. Run the deploy-coupled Phase 4-Gate (prod SEO verify + full T2) whenever a deploy decision is separately authorized.

Standing constraints remain (Rule 8): no commit/push/deploy, no AI-enable, no security-mode change, no schema migration, no baseline refresh without explicit authorization.
