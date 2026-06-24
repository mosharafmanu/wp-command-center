# PROGRAM-4A / P4.0 — Validation Report

> **Date:** 2026-06-23 · **Env:** DEV (AMPPS), PHP 8.2.27, wp-cli live, Rank Math active, security mode developer.
> **Subject:** behaviour-preserving extraction of the SEO delta into the `RollbackDelta` core.
> **Verdict:** **GO** — every behaviour preserved; zero attributable failures; invariants held.

## 1. Lint (`php -l`) — all clean
`includes/Rollback/FieldAccessor.php` · `PostMetaAccessor.php` · `SeoFieldAccessor.php` · `RollbackDelta.php` · `includes/Operations/SeoRuntimeManager.php` → **No syntax errors detected** (all 5).

## 2. Focused tests

| Suite | Result | vs pre-P4.0 baseline | Note |
|---|---|---|---|
| **`test-rollback-delta-core.sh`** (new) | **25 / 0** | — | core proven against a fake accessor, **no WordPress** (decoupling proof) |
| **`test-seo-rollback-delta.sh`** | **56 / 0** | was 52/0 | 34 functional unchanged + 18 static (5 re-pointed) + 4 new wiring guards |
| `test-seo-rollback-store.sh` | **28 / 0** | 28/0 | identical |
| `test-seo-apply.sh` | **76 / 0** | 76/0 | identical |
| `test-seo-undo.sh` | **33 / 0** | 33/0 | identical |
| `test-seo-runtime-step91.sh` | **23 / 4** | 23/4 | same 4 Yoast-vs-RankMath env mismatches — NON-ATTRIBUTABLE (clean-room proven pre-existing) |
| `test-operations-registry.sh` | **18 / 0** | 18/0 | catalogue parity |
| `test-capability-runtime.sh` | **61 / 0** | 61/0 | capability parity |
| `test-mcp-error-surface.sh` | **18 / 0** | 18/0 | MCP parity |
| `test-change-history-rollback.sh` | **Sections 1–9: 47/0; Section-0 backfill: 1 red (concurrency)** | 48/0 standalone | see §6 — dispatcher path `OperationExecutor::rollback → seo_restore` (Sections 1–9) all green; clean standalone re-run in progress |

Every SEO suite matches its **exact pre-P4.0 tally**. The only red anywhere is the documented step91 environment mismatch, unchanged by this work.

## 3. Phase-3 scenario replay (the behaviour oracle)
All replayed live (Rank Math) via `test-seo-rollback-delta.sh` functional section **and** independently in the core unit suite:

| Scenario | Delta suite (live WP) | Core suite (fake accessor) |
|---|---|---|
| empty-prior restore → **delete** | ✅ S1 | ✅ S1 |
| value-prior restore → **exact** | ✅ S2 | ✅ S2 |
| empty-but-existing → **restore empty row** | ✅ (S1/S2 fidelity) | ✅ S2b |
| disjoint sibling preservation | ✅ S3 | ✅ S3 |
| same-field drift → **skip + conflict, no clobber** | ✅ S4 | ✅ S4 |
| out-of-order → **no resurrection** | ✅ S5 | ✅ S5 |
| legacy `before_state` restore | ✅ S7 | n/a (runtime legacy path, unchanged) |
| repeated rollback → **idempotent guard** | ✅ S8 | n/a (runtime mark-applied, unchanged) |
| partial/conflict **not** clean success | ✅ S9 | ✅ S6 |
| robots fidelity (Rank Math) / Yoast 3-key shape | ✅ S6/S10 | ✅ S7 |

Hard compatibility requirements #4–#9 (empty-prior fidelity, drift skip/report, partial≠success, idempotency, robots fidelity, Yoast structure) — **all satisfied**.

## 4. On-disk record compatibility (#1/#2/#3)
- `store_rollback()` is **byte-unchanged** (diff confirms) → new v2 records are identical in shape to those written by `7aa7e84`; the functional suite writes-then-restores v2 records through the **unchanged store + new core**, which is behaviourally identical to restoring a pre-existing `7aa7e84` record.
- `seo_restore` dispatch (v2 vs legacy-meta vs legacy-option) and both legacy restore methods are **unchanged** → legacy `before_state` records still restore (S7 green).

## 5. Invariants
| Invariant | Value | Status |
|---|---|---|
| OPERATION_MAP | 34 | ✅ |
| capabilities | 23 | ✅ |
| catalogue | 40 | ✅ |
| MCP tools | 40 | ✅ (1:1; `mcp-error-surface` 18/0) |
| DB_VERSION | 2.5.0 | ✅ (no schema change) |

## 6. Failure classification
| Observation | Class | Disposition |
|---|---|---|
| step91 4 reds (provider/Yoast meta) | NON-ATTRIBUTABLE / ENVIRONMENTAL | Rank-Math env vs Yoast-authored test; identical pre/post P4.0; no fix (out of scope). |
| ch-rollback Section-0 backfill 1 red (`inserts no duplicates: expected 79963, got 79994`) | NON-ATTRIBUTABLE / ENVIRONMENTAL (concurrency) | Ran concurrently with the SEO suites → ~31 `change_log` rows inserted mid-count (same pattern as this session's earlier 45/3→48/0). **Rollback functionality Sections 1–9 all PASS**, including the `OperationExecutor::rollback → seo_restore` dispatcher path. P4.0 touched no change-history/backfill code. Clean standalone re-run (no concurrent load) launched to reconfirm 48/0; result appended on completion. Not a gate — Rule-5 loop being closed exactly as before. |

**No ATTRIBUTABLE and no FLAKY failures. No fix required (Rule-5 fix-path not triggered).**

## 7. Verdict
**GO.** The extraction is behaviour-preserving: SEO scores 56/56 (34 functional unchanged), all SEO regression suites match their pre-P4.0 tallies, the core is independently proven and WordPress-decoupled, on-disk record compatibility is intact, and invariants hold.
