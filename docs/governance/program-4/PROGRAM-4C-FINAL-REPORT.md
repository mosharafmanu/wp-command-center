# PROGRAM-4C — Rollback Integrity Final Architecture · Executive Report

> **Type:** executive summary of the PROGRAM-4C architecture/audit/planning program. **Report-only — no code, no branches, no commits, no merge, no push, no deploy.**
> **Date:** 2026-06-24 · **Production HEAD:** `a41a9d7` (unchanged). All Program-4 work is branch-only.
> **Companion deliverables:** [Inventory](PROGRAM-4C-ROLLBACK-INVENTORY.md) · [ACF](PROGRAM-4C-ACF-ARCHITECTURE.md) · [Bulk](PROGRAM-4C-BULK-ARCHITECTURE.md) · [G2](PROGRAM-4C-G2-REVERSIBILITY.md) · [Risk Matrix](PROGRAM-4C-RISK-MATRIX.md) · [Roadmap](PROGRAM-4C-ROADMAP.md) · [Adversarial Review](PROGRAM-4C-ADVERSARIAL-REVIEW.md).

---

## 1. Remaining rollback inventory (at a glance)
**Completed (Pattern B delta):** SEO · Settings · Media-metadata · Content · Comments · User · Woo Products · core hardening. **Correct (Pattern C byte):** Patch · Media bytes.

**Remaining unmigrated surfaces (12), by severity:**
1. **Bulk** — *active corruption* (status rollback writes the status string into `post_title` and never restores status) + 3 sub-actions with **no** rollback. **CRITICAL.**
2. **ACF** — whole-definition + serialized-value blobs; `json_import` lossy/unrestorable; option-page values unsupported. **HIGH.**
3. **Elementor** — full `_elementor_data` tree snapshot per edit; layered widget edits clobber. **HIGH.**
4. **Woo `variation_update` / `coupon_update`** — no `store_rollback` (F-3 silent irreversibility). **MED-HIGH.**
5. **Plugin / Theme update (G2)** — capture nothing yet approved as reversible high-risk (false contract). **MED-HIGH.**
6. **Woo orders** — relational; status-rollback re-emails; high gateway drift. **MED (defer).**
7. **OptionManager** — drift-unaware + **uncapped** option growth. **MED.**
8. **Forms update** — no-op rollback. **LOW-MED.**
9. **Menu `item_reorder`** — no rollback. **LOW-MED.**
10–12. **Widgets / CPT / SiteBuilder** — mostly correct; missing F-1 tests; minor housekeeping. **LOW.**
Plus residual: **Settings** permalink `rewrite_rules` not captured (LOW).

**Cross-cutting:** shared-option **FIFO eviction** silently drops `rollback_id`s on busy stores (Woo/ACF/Elementor); **uncapped** options (`wpcc_option_rollbacks`, `wpcc_cpt_rollbacks`); **untested rollback paths** (which is how the Bulk corruption stayed hidden).

## 2. Highest-risk unresolved area
**Bulk operations** — the only surface with a **live, untested data-corruption bug** *and* the **largest blast radius** (N items/op) *and* three silently-irreversible sub-actions. It is the program's top priority and the clearest argument that the Rollback guarantee is not yet platform-true.

## 3. Highest-leverage next phase
**`PostMetaRollbackStore` (P4.7) — the new keystone.** A per-entity, schema-free, self-GCing `RollbackStore` (built during the Bulk redesign) that simultaneously: closes Bulk F-1 (per-item delta), **eliminates the shared-option FIFO-eviction weakness** for Woo/ACF/Elementor (the adversarial review's most consequential finding, R-5), and reuses the single-entity accessors already proven in P4.1–P4.6. One store unlocks four surfaces. Paired with a **whole-definition + drift-guard** core mode for non-decomposable blobs (ACF config, Elementor fallback), these two additions are the leverage points — exactly as `RollbackDelta` (P4.0) was for the first program.

## 4. Recommended implementation order
`P4C.0a Bulk corruption hotfix` → `P4C.0b Woo F-3 closure` → `P4C.0c Menu/Option/CPT housekeeping` → **`P4.7 PostMetaRollbackStore + whole-def+drift-guard`** → `P4.8 Bulk delta` → `P4.9 ACF` → `P4.10 Elementor` → `P4.11 G2 honest` → `P4.12 G2 plugin artifact` → `P4.13 Forms + low-risk` → **deploy decision** → Wave 3 (Woo orders, A2-1 reaper) under separate designs.
**Hard constraints:** Wave 0 precedes all; P4.7 precedes P4.8/4.9/4.10; P4.11 precedes P4.12.

## 5. Rule-7 check-in points (owner decision before code)
1. **A2-1 stale-`executing` reaper** — needs a `claimed_at` column ⇒ **schema + DB_VERSION**. Deferred (Wave 3); explicit check-in.
2. **Dedicated bulk table** — only if per-item postmeta is rejected ⇒ schema. **Not recommended** (postmeta suffices at N≤100).
3. **Woo orders relational store** — confirm at its sub-design that HPOS/custom-table reversal needs no new column (expected none).
4. **Any per-entity discovery index** ⇒ schema check-in (not anticipated).
Everything in **Waves 0–2 (P4C.0a–P4.13) is schema-free** — postmeta/usermeta/commentmeta/option storage, plain-array records, new classes behind existing interfaces, new option keys (which do not bump DB_VERSION), response/guard changes. **No Wave 0–2 phase trips a STOP condition.**

## 6. Validation requirements (per phase — the SEO gate)
- Adapted **S1–S9** + **drift-injection** (the F-1 reproduction proving sibling survival) + **coverage-gap negative tests** (status actually restored; reversible:false where irreversible; missing-capture closed).
- **Behavior-preserving proof:** core **25/0** and SEO **56/0** unchanged after any core touch.
- Existing runtime suite green at current tally; **net-new attributable = 0** vs `regression-baseline.tsv`.
- Invariants **34 · 23 · 40 · 40 · 2.5.0** re-verified each phase.
- **New mandatory tests from the adversarial pass:** malformed-record (R-1), out-of-order blob (R-3), partial-restore structured-partial (R-7), oversize-artifact honest-flag (R-4), per-type comparator (R-8), FIFO-eviction observability (R-5), batch-index-derivable (R-10), honesty-across-paths (R-11).

## 7. Deployment requirements
- Deploy is **decoupled** from each phase and is a separate Rule-7 decision (production stays `a41a9d7` until then).
- Pre-deploy: **full serial T2** (net-new attributable 0) + **Stage-A acceptance** + **prod token-gated functional verify** of the migrated surfaces — the same gate still **outstanding** for the already-deployed SEO Phase-3 fix (close it at the next deploy).
- Pull-based deploy (`git push origin main` ⇒ live ~1 min); AI flags stay OFF, key unset, security mode `developer` unchanged.

## 8. Certification readiness
**Not yet platform-certifiable** for "audited reversibility." Today it is true **only** for the migrated set (SEO, Settings, Media-metadata, Content, Comments, User, Woo products) + Pattern-C (Patch, Media bytes). Platform certification requires, in order: Bulk corruption fixed + delta; ACF + Elementor migrated (delta / whole-def+drift-guard); Woo F-3 gaps closed; G2 made honest; Forms/Menu gaps closed; Woo orders + A2-1 reaper handled under their deferred designs; and an F-1 + drift test on **every** migrated surface (no assertion-free rollback path — the gap that hid the Bulk corruption).

**Interim honesty:** until then, market "audited reversibility" for the migrated/Pattern-C set only, and surface `reversible:false` everywhere reversal is not real (G2, json_import, Forms update) — never silent.

---

## 9. Program disposition
- **Deliverables produced (8):** inventory, ACF architecture, Bulk architecture, G2 reversibility, risk matrix, roadmap, adversarial review, this report — all under `docs/governance/program-4/`.
- **Code changed:** none. **Branches created:** none. **Commits / merge / push / deploy:** none. **Production:** `a41a9d7`, untouched.
- **STOP conditions:** none triggered — the entire Waves 0–2 plan is schema-/contract-/security-neutral; the only schema-bearing items are explicitly deferred to Wave 3 with named Rule-7 check-ins.
- **Recommended first executable action (on a future, separately-authorized implementation program):** **P4C.0a — the Bulk corruption hotfix**, because it stops live data loss with a small, well-tested change, before any architectural migration begins.
