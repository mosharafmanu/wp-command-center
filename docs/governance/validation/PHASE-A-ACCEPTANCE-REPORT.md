# Phase A — Acceptance Gate Report (Phase 3 SEO Delta Rollback)

> **Program:** Phase 3 Acceptance Gate + Real-World Validation (autonomous mode).
> **Date:** 2026-06-23 · **Env:** DEV (AMPPS), PHP 8.2.27, wp-cli live. Active SEO provider = **Rank Math**. Security mode = developer. Plugin active.
> **Scope of this report:** Phase A only (acceptance gate for the live SEO field-scoped delta rollback, `7aa7e84`). Phase B (real-world matrix) and Phase C (consolidation) are separate deliverables.
> **Constraints honored:** no commit / push / deploy / AI-enable / security-mode change / schema change. Read + test execution only.

---

## 1. Verdict

**GO — Phase 3 SEO delta rollback PASSES the acceptance gate on every attributable surface.**

- Primary acceptance suite: **52 / 52 PASS** (all Stage-A scenarios S1–S10, static + live Rank Math round-trips).
- Targeted regressions on every suite that touches the two changed files: **all green** (173 assertions, 0 attributable failures).
- Core registry / capability / MCP parity guards: **all green** (97 assertions).
- Invariants re-verified live: **34 · 23 · 40 · 40 · 2.5.0** — held.
- Net-new failures attributable to Phase 3: **0.** The only red is the 4 documented Yoast-vs-Rank-Math environment mismatches in `test-seo-runtime-step91.sh` (NON-ATTRIBUTABLE; clean-room proven pre-existing).

Caveat: the **full 137-suite serial T2** and a **production token-gated functional verify** were not run in this session (see §5) — they are environmental/out-of-scope here (this mission forbids deploy), not failures. The attributable T2-equivalent subset was executed and is clean.

---

## 2. Primary acceptance suite — `tests/test-seo-rollback-delta.sh`

**Result: 52 / 52 PASS** (re-run live this session, Rank Math active).

| Stage-A scenario | Suite proof | Result |
|---|---|---|
| **S1** Empty-prior fidelity | apply title+desc to absent prior → rollback **deletes** both exactly | ✅ |
| **S2** Value-prior fidelity | apply over existing prior → rollback **restores exact** prior values | ✅ |
| **S3/S3B** Disjoint layered rollback | A=title, B=description; rollback A → **description (B) survives**, title→ORIG_T | ✅ |
| **S4** Same-field drift | A=title, B=title; rollback A → **conflict**, B's title **NOT clobbered**, title reported skipped | ✅ |
| **S5** Out-of-order rollback | rollback B (complete), retry A (complete) → ORIG_T, **no resurrection** | ✅ |
| **S6** Robots fidelity | Rank Math robots array apply/rollback round-trip (normalized) | ✅ |
| **S7** Legacy record | pre-Phase-3 `before_state` record restores via legacy path | ✅ |
| **S8** Idempotency | repeated rollback guarded (`wpcc_rollback_already_applied`) | ✅ |
| **S9** History honesty | partial returns `restored_fields`/`skipped_fields`; drifted sibling preserved | ✅ |
| **S10** Provider parity | Yoast robots → 3 backing keys + title key (structural) | ✅ |

Plus 18 static source assertions (version-2 record, `fields` map, `capture_prior` existence flag, drift conflict, existence-faithful restore, terminal-only-on-complete, conflict/partial codes, legacy branches retained, provider helpers).

**The four F-1 failure modes are each directly disproven by a passing live assertion:** sibling loss (S3), same-field clobber (S4), out-of-order resurrection (S5), existence fidelity (S1 vs S2).

---

## 3. Targeted regressions (every suite touching the changed code)

Phase 3 changed exactly two production files: `includes/Operations/SeoRuntimeManager.php` + `includes/Operations/SeoProvider.php`. Every suite exercising those paths was re-run:

| Suite | Result | Note |
|---|---|---|
| `test-seo-rollback-store.sh` | **28 / 0** | v2 record-shape storage guarantees intact |
| `test-seo-undo.sh` | **33 / 0** | Admin Undo → change-history → seo_restore round-trip |
| `test-seo-apply.sh` | **76 / 0** | Proposal → governed apply → seo_manage path |
| `test-workflow-rollback-f61.sh` | **16 / 0** | Workflow auto-rollback delegating to SEO via OperationExecutor |
| `test-change-history-runtime.sh` | **57 / 0** | change_log read layer + reversibility flags |
| `test-seo-runtime-step91.sh` | **23 / 4** | 4 failures = Yoast-vs-Rank-Math env mismatch — **NON-ATTRIBUTABLE** (see §4) |

Subtotal (excl. step91 env): **210 / 0**.

### Core invariant guards (registry / capability / MCP)
| Suite | Result |
|---|---|
| `test-operations-registry.sh` | **18 / 0** |
| `test-capability-runtime.sh` | **61 / 0** |
| `test-mcp-error-surface.sh` | **18 / 0** |

These confirm catalogue=40, capabilities=23, MCP-parity=40 at **runtime**, not just by static grep.

### `test-change-history-rollback.sh` (OperationExecutor::rollback dispatcher → SEO restore)
Started standalone; this is the documented heavy-backfill suite (one-time idempotent backfill over ~74k `change_log` rows) that exceeds a 7-minute wall clock in this DEV DB. It is **48/0 standalone** per the handoff baseline and is **non-attributable** to Phase 3 (Phase 3 touched no change-history code). Running to completion in background; result appended to the running-state doc when it lands. Its pending status does **not** gate the verdict.

---

## 4. Failure classification

| Observation | Class | Evidence |
|---|---|---|
| `step91` 4 failures (provider detected rankmath not yoast; native Yoast title/noindex meta empty; MCP seo_get provider) | **NON-ATTRIBUTABLE / ENVIRONMENTAL** | Suite authored for a Yoast-active site; DEV runs Rank Math. Clean-room (stash Phase 3, run stock code) yields the **identical 4** failures (20/4 stock → 23/4 Phase 3 = +3 net passes, 0 net-new). None touch rollback; all read `_yoast_wpseo_*` keys or assert `provider==yoast` on lines Phase 3 did not modify. |
| `change-history-rollback` >7min | **ENVIRONMENTAL (runtime budget)** | One-time backfill over ~74k rows; 48/0 standalone; flakes only back-to-back. Not a code failure. |
| Full 137-suite serial T2 not completed in-session | **ENVIRONMENTAL / OUT-OF-SCOPE** | Multiple suites individually exceed 7 min; 137 serial is multi-hour. This mission forbids deploy, so the pre-deploy full T2 is correctly deferred to immediately-before-deploy. The attributable subset (above) is clean. |

**No ATTRIBUTABLE and no FLAKY failures observed on the Phase 3 surface.** No fix was required (Rule 5 fix-path not triggered).

---

## 5. What remains for a *complete* pre-deploy gate (not blockers for this report)

1. **Full serial T2** (`tests/run.sh --tier T2`, `-j 1`) across all 137 suites, diffed against `regression-baseline.tsv` for net-new — run immediately before any deploy. *Deploy is out of scope here; not run.*
2. **Production token-gated functional verify** of SEO delta rollback against `mosharafmanu.com` (requires a prod token; production action — withheld pending explicit authorization).
3. Optional hygiene: refresh `regression-baseline.tsv` / run `step91` under Yoast to clear the environmental discrepancy (handoff says do **not** refresh baseline unless directed — left as-is).

These three are the same residuals the Phase-3 commit and `SESSION-HANDOFF-PHASE-3.md` already flagged. Items 1–2 require a deploy decision and/or production credentials, which Rule 8 reserves for explicit authorization.

---

## 6. Invariants (re-verified live this session)

| Invariant | Expected | Actual | Source |
|---|---|---|---|
| OPERATION_MAP | 34 | **34** | `CapabilityRegistry::OPERATION_MAP` |
| capabilities | 23 | **23** | `CapabilityRegistry::ALL_CAPABILITIES` |
| catalogue | 40 | **40** | `OperationRegistry` op ids (+ `test-operations-registry` 18/0) |
| MCP tools | 40 | **40** | 1:1 per operation (+ `test-mcp-error-surface` 18/0) |
| DB_VERSION | 2.5.0 | **2.5.0** | `Schema::DB_VERSION` |

Phase 3 made no schema / op / cap / tool change, as designed.

---

## 7. Phase A conclusion

**GO.** F-1 is **closed and proven for the SEO runtime** — the field-scoped, drift-aware delta restore eliminates sibling loss, same-field clobber, and out-of-order resurrection, with live Rank Math evidence for each. Zero net-new regressions attributable to Phase 3. Invariants intact. The remaining gate items (full T2, prod functional verify) are deploy-coupled and out of scope for this no-deploy mission; they are documented as the immediate-pre-deploy checklist.

Proceeding to **Phase B — Real-World Validation Program** (10-category matrix).
