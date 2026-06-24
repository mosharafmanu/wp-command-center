# Risks & Gaps Register — Phase 3 Gate + Real-World Validation

> **Date:** 2026-06-23 · **Source:** Phase A acceptance gate + Phase B 10-category matrix (this session).
> **Scope:** open risks/gaps observed in the *deployed* code (`a41a9d7` / code-effective `7aa7e84`). DEV-verified; no code changed.
> Severity = blast radius × likelihood under realistic operation. Each item notes whether closing it trips a **Rule-7 check-in** (schema / security / capability / MCP / deploy).

---

## Open gaps (ranked)

### G1 — F-1 systemic: full-object snapshot over-reach (HIGH)
- **What:** rollback for **Content, Woo, Settings, ACF, User, Forms, Comments, Bulk, and Media-metadata** stores a **full-object `before_state`** and restores the whole object. SEO is the **only** runtime converted to a field-scoped delta (Phase 3). Verified by source (`before_state` present in each manager; SEO uses `version 2` `fields` delta).
- **Why it matters:** the exact failure SEO just fixed — layered/independent field edits between two rollbacks cause **sibling-field loss, same-field clobber, and out-of-order resurrection**, and leave a **misleading "applied" history**. A rollback that silently corrupts data is worse than none.
- **Likelihood:** scales with field-wise editing of the same object over time (Woo products, ACF field groups, post meta). Single-change rollback is unaffected.
- **Status:** OPEN systemically; design proven (SEO). **Closing it = the recommended next program.**
- **Check-in:** intended to be schema-free (delta rides existing rollback records) — **must be confirmed per runtime**; if any needs a column → schema check-in. Each deploy → deploy check-in.

### G2 — `plugin_update` / `theme_update` have no rollback and aren't flagged irreversible (MEDIUM-HIGH)
- **What:** verified at `PluginManager.php:243–297` and `ThemeManager.php:185+` — the update paths capture active-state for reactivation and audit start/fail, but store **no version snapshot, no rollback_id**, and return **no "irreversible" signal**.
- **Why it matters:** a governed update that breaks a site **cannot be undone through WPCC**, and nothing tells the operator/agent that up front. This is a silent breach of the Four Guarantees' "reversible **or visibly** irreversible with a guard" clause.
- **Likelihood:** moderate (updates are routine; bad updates happen).
- **Status:** OPEN.
- **Check-in:** none for a response-flag/guard fix; capturing the pre-update artifact is additive (no schema). Deploy → deploy check-in.

### G3 — A2-1 uncatchable-fatal reaper (MEDIUM, narrow)
- **What:** OOM / `max_execution_time` / process-kill can strand a request in `executing` (uncatchable by try/catch). Needs a stale-`executing` reaper distinguishing a dead process from a slow handler.
- **Why it matters:** a stranded request blocks re-claim; rare but real under resource pressure.
- **Status:** DEFERRED (known from Phase 2).
- **Check-in:** **YES — schema.** Requires a `claimed_at` column + DB_VERSION bump. Keep **out** of the F-1 program; schedule independently.

### G4 — Production token-gated functional verify outstanding (MEDIUM, deploy-coupled)
- **What:** SEO delta rollback (and the broader token/agent path) has not been exercised against production with a real token.
- **Why it matters:** DEV-green ≠ prod-verified; the Phase 3 commit itself flags this as residual.
- **Status:** OPEN, **out of scope here** (requires prod credentials + a deploy decision — Rule 8).
- **Check-in:** **YES — production action.**

### G5 — Full 137-suite serial T2 not completed in-session (LOW, process)
- **What:** the attributable subset (SEO + lifecycle + categories) ran clean; the full pre-deploy T2 across 137 suites is multi-hour and was not run.
- **Why it matters:** the formal pre-deploy net-new-0 stamp needs the full T2.
- **Status:** OPEN, deferred to immediately-before-deploy (mission forbids deploy).
- **Check-in:** none to run; informs the deploy check-in.

### G6 — Test hygiene: step91 provider mismatch + backfill count fragility (LOW)
- **What:** (a) `test-seo-runtime-step91.sh` hardcodes Yoast on a Rank-Math env → 4 perpetual reds; (b) `test-change-history-rollback.sh` Section-0 backfill uses exact row counts that break under concurrent inserts.
- **Why it matters:** noise that masks signal; both are NON-ATTRIBUTABLE but recur.
- **Status:** OPEN (test-only). Handoff says **do not refresh baseline unless directed** — left as-is.
- **Check-in:** none.

---

## Risk posture summary
| Risk | Severity | Trend | Owner action |
|---|---|---|---|
| G1 F-1 systemic | **HIGH** | contained to non-SEO runtimes; design proven | next program |
| G2 plugin/theme update no rollback | MED-HIGH | static | flag irreversible + (opt) snapshot artifact |
| G3 A2-1 reaper | MED | deferred | schedule (schema check-in) |
| G4 prod token verify | MED | deploy-coupled | run on deploy authorization |
| G5 full T2 | LOW | deferred | run pre-deploy |
| G6 test hygiene | LOW | recurring | optional cleanup (not directed) |

## What is NOT at risk (validated strengths)
- Single governed execution chokepoint holds across all 10 categories (no second path).
- Execution lifecycle (execute-once B2-2, execution-truth B2-1, atomic claim A-1, exception hardening A2-1) — green.
- SEO field-delta rollback — proven against all four F-1 failure modes.
- File/Patch snapshot+verify — reference-grade reversibility, no gaps.
- Approval gating, human-approver guard, capability scoping, token request-not-approve — green.
- MCP error surface + tool/op parity (40), audit append-only + change history — green.
- Invariants 34 / 23 / 40 / 40 / 2.5.0 — held and runtime-verified.
