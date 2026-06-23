# Phase 3 — Validation Report (F-1 SEO Delta Rollback)

**Environment:** DEV, active SEO provider = **Rank Math** (live round-trips); Yoast paths
covered structurally. **Invariants:** OPERATION_MAP 34 · capabilities 23 · catalogue 40 ·
MCP 40 · DB_VERSION 2.5.0 (verified).

---

## 1. Focused validation suite — `tests/test-seo-rollback-delta.sh`

**Result: 52 / 52 PASS.** Maps 1:1 to the required Phase 3 scenarios.

| # | Required scenario | Suite coverage | Result |
|---|---|---|---|
| 1 | Empty-prior fidelity | S1 — apply title+desc to absent prior; rollback deletes both exactly | ✅ |
| 2 | Value-prior fidelity | S2 — apply over existing prior; rollback restores exact prior values | ✅ |
| 3 | Disjoint layered rollback | S3 — A=title, B=description, rollback A → description (B) survives, title restored | ✅ |
| 4 | Same-field drift | S4 — A=title, B=title, rollback A → conflict, B NOT destroyed, title reported skipped | ✅ |
| 5 | Out-of-order rollback | S5 — rollback B (complete), retry A (complete) → ORIG_T, no resurrection | ✅ |
| 6 | Robots fidelity | S6 — Rank Math robots array apply/rollback round-trip; S10 Yoast 3-key shape | ✅ |
| 7 | Legacy rollback record | S7 — old `before_state` record restores via legacy path (`path=legacy`) | ✅ |
| 8 | Repeated rollback | S8 — complete then guarded `wpcc_rollback_already_applied` | ✅ |
| 9 | Audit & change history | S9 — partial returns `restored_fields`/`skipped_fields`; sibling drift preserved | ✅ |
| 10 | Provider parity | S6 Rank Math live; S10 Yoast `backing_keys` (3 robots keys, title key) | ✅ |
| 11 | Regression | §3 below | ✅ |

Plus 18 static source assertions (version-2 record, fields map, capture_prior existence
flag, drift conflict, existence-faithful restore, terminal-only-on-complete, conflict/
partial codes, legacy branches retained, provider helpers).

### Key F-1 proofs (live, Rank Math)
- **Sibling preservation (S3):** rollback A restores `title=ORIG_T` while `description=B_D`
  (set by the later change B) **survives** — the exact failure the old full-snapshot path
  caused, now fixed.
- **No same-field clobber (S4):** rolling back the older title change is a **conflict**;
  the newer `title=B_T` is **not** overwritten.
- **No resurrection (S5):** out-of-order recovery reaches `ORIG_T` only via the correct
  order (roll back newer, then older); nothing pre-A is resurrected.
- **Existence fidelity (S1 vs S2):** absent prior → field **deleted** on rollback; present
  prior → exact value **restored** (including the empty-but-existing distinction).

---

## 2. Lint

`php -l` clean: `includes/Operations/SeoProvider.php`,
`includes/Operations/SeoRuntimeManager.php`.

---

## 3. Regression

### Targeted SEO + Change-History suites
| Suite | Result | Note |
|---|---|---|
| `test-seo-rollback-store.sh` | **28 / 0** | Slice 4c storage guarantees intact under v2 record shape (2 assertions updated to delta shape; no behavior change to storage). |
| `test-seo-undo.sh` | **33 / 0** | Admin Undo → change-history → seo_restore round-trip unaffected. |
| `test-seo-apply.sh` | **76 / 0** | Proposal → governed apply → seo_manage path unaffected. |
| `test-workflow-rollback-f61.sh` | **16 / 0** | Workflow auto-rollback (delegates to seo via OperationExecutor) unaffected. |
| `test-seo-runtime-step91.sh` | **23 / 4** | 4 failures are pre-existing Yoast-vs-Rank-Math env mismatches (see §4). Rollback section now passes fully under field-scoped semantics. |

### Tiered runner — `tests/run.sh --tier T1 --changed` (11 suites)
```
T1 result: 470 passed, 4 failed  |  net-new: 4  |  605s
failing suites:
  test-seo-runtime-step91.sh: 4 (baseline 0, net-new 4)
```
Suites covered: actor-attribution, capability-runtime, change-history-admin,
change-history-rollback, change-history-runtime, change-history, mcp-error-surface,
operations-registry, seo-rollback-store, seo-runtime-step91, seo-undo. **All green except
the 4 step91 env mismatches.**

### Invariants
`OPERATION_MAP=34 · capabilities=23 · DB_VERSION=2.5.0` verified live; catalogue/MCP =40
unchanged (no registry/MCP edits).

---

## 4. The 4 "net-new" step91 failures are environmental, not a regression

`test-seo-runtime-step91.sh` was authored for a **Yoast-active** site (its header says it
"installs/uses Yoast on the dev site"), and the runner's `regression-baseline.tsv` records
its baseline as 0 — also captured under Yoast. DEV **currently runs Rank Math**, so four
assertions that hardcode `provider == "yoast"` or read `_yoast_wpseo_*` meta keys fail
regardless of any code change.

**Proof (clean-room):** stashing all Phase 3 changes and running the **original** suite
against the **original** code in this same Rank-Math env yields **20 passed, 4 failed** —
the *identical* four failures:
```
FAIL seo_get: provider detected (expected 'yoast', got 'rankmath')
FAIL verify: native Yoast title meta
FAIL verify: native Yoast noindex meta
FAIL MCP seo_get: provider (expected 'yoast', got 'rankmath')
```
The Phase 3 version scores 23/4 (same 4) — i.e. **+3 net passes, 0 net-new failures
attributable to Phase 3 code**. None of the four touch rollback; all are
provider/meta-key environment expectations on lines this change did not modify.

---

## 5. T2 recommendation

A full serial **T2 is required before deploy** (per the standing runner policy and these
being governance-critical paths). It was **not** run in this phase because (a) commit is
gated on owner authorization and (b) the focused + T1 evidence above is sufficient for the
GO/NO-GO commit decision. Recommendation: run `tests/run.sh --tier T2` (serial) immediately
before any deploy, and refresh `regression-baseline.tsv` / run step91 under Yoast to clear
the environmental discrepancy.

**Validation verdict: GO** — F-1 fixed and proven; zero net-new regressions attributable to
Phase 3.
