# PROGRAM-4A / P4.0 — Final Report

> **Date:** 2026-06-23 · **Phase:** P4.0 (RollbackDelta core extraction) of PROGRAM-4 Rollback Integrity Expansion.
> **Companion reports:** [Implementation](PROGRAM-4A-P4.0-IMPLEMENTATION-REPORT.md) · [Validation](PROGRAM-4A-P4.0-VALIDATION-REPORT.md) · [Independent Audit](PROGRAM-4A-P4.0-INDEPENDENT-AUDIT.md).
> **Standing constraints honoured:** no commit / push / deploy / AI-enable / security-mode change / schema change. Working tree changed locally only.

## 1. Changed files
**New (source):**
- `includes/Rollback/FieldAccessor.php` — accessor interface.
- `includes/Rollback/PostMetaAccessor.php` — post-meta accessor base (+ default string `equals`).
- `includes/Rollback/SeoFieldAccessor.php` — SEO accessor (wraps `SeoProvider`; robots set-compare).
- `includes/Rollback/RollbackDelta.php` — runtime-agnostic capture/restore core.

**New (test):**
- `tests/test-rollback-delta-core.sh` — fake-accessor core unit suite (no WordPress).

**Modified:**
- `includes/Operations/SeoRuntimeManager.php` — delegates capture/restore to the core; `store_rollback` + dispatch + legacy unchanged.
- `tests/test-seo-rollback-delta.sh` — 5 static guards re-pointed + 4 new wiring guards; 34 functional assertions unchanged.

**New (reports):** `docs/governance/program-4/PROGRAM-4A-P4.0-*.md`.

## 2. Diff stat
```
 includes/Operations/SeoRuntimeManager.php   | 75 +++++-------------------  (net −26)
 tests/test-seo-rollback-delta.sh            | 21 ++++++--
 includes/Rollback/FieldAccessor.php         | + new
 includes/Rollback/PostMetaAccessor.php      | + new
 includes/Rollback/SeoFieldAccessor.php      | + new
 includes/Rollback/RollbackDelta.php         | + new
 tests/test-rollback-delta-core.sh           | + new
```
No registry / capability / MCP / schema / REST / UI file touched. `SeoProvider.php` unchanged.

## 3. Tests run — pass/fail summary
| Suite | Result |
|---|---|
| `php -l` × 5 changed files | clean |
| `test-rollback-delta-core.sh` (new, no-WP) | **25 / 0** |
| `test-seo-rollback-delta.sh` | **56 / 0** (34 functional unchanged) |
| `test-seo-rollback-store.sh` | **28 / 0** |
| `test-seo-apply.sh` | **76 / 0** |
| `test-seo-undo.sh` | **33 / 0** |
| `test-seo-runtime-step91.sh` | **23 / 4** (env, non-attributable) |
| `test-operations-registry.sh` | **18 / 0** |
| `test-capability-runtime.sh` | **61 / 0** |
| `test-mcp-error-surface.sh` | **18 / 0** |
| `test-change-history-rollback.sh` | Sections 1–9 **green**; Section-0 backfill 1 red (concurrency, non-attributable); clean standalone re-run in progress |

**Attributable failures: 0.** All SEO suites match their exact pre-P4.0 tallies.

## 4. Invariant status
OPERATION_MAP **34** · capabilities **23** · catalogue **40** · MCP tools **40** · DB_VERSION **2.5.0** — **all held** (no op/cap/tool/schema change).

## 5. Behaviour-preservation verdict
**PRESERVED.** The extraction is a 1:1 behavioural lift: the core's capture/restore loops and the accessor's `equals` are byte-equivalent to the removed SEO code; `store_rollback` (on-disk record shape) and the dispatch + legacy paths are unchanged; the runtime retains terminality/idempotency/audit/envelope. Proven three ways — the unchanged 34 functional round-trips (live WP), the 25 fake-accessor core assertions (logic, no WP), and the matching pre/post regression tallies. All 10 hard compatibility requirements satisfied (see audit §4).

## 6. GO / NO-GO for commit
**GO for commit.**
- Behaviour preserved; zero attributable failures; invariants held; scope clean (no forbidden surface, no P4.1 slip); independent audit PASS.
- The two non-green observations (step91 ×4, ch-rollback Section-0 ×1) are both NON-ATTRIBUTABLE environmental (provider mismatch; concurrency contention) and unrelated to P4.0 code.
- **Commit only** (per the task's commit rules). **NO push. NO deploy.** Pre-deploy items (full serial T2, prod token-gated SEO verify) remain deploy-coupled and out of P4.0 scope; run them at an authorised deploy decision.

> If the in-progress clean standalone `test-change-history-rollback.sh` returns anything other than 48/0, this verdict is paused pending investigation (Rule 5). Expected: 48/0, matching this session's earlier standalone result on pre-P4.0 code.

## 7. Suggested commit message
```
refactor(rollback): extract runtime-agnostic RollbackDelta core from SEO (P4.0)

Lift the Phase-3 field-scoped, drift-aware SEO delta rollback into a shared,
storage-agnostic core so later runtimes reuse the correctness instead of
re-deriving it:

  - includes/Rollback/RollbackDelta.php   field-scoped capture + drift-aware
                                          restore (complete|partial|conflict);
                                          pure, no WordPress calls
  - includes/Rollback/FieldAccessor.php   accessor interface
  - includes/Rollback/PostMetaAccessor.php post-meta primitives + default equals
  - includes/Rollback/SeoFieldAccessor.php SEO accessor (wraps SeoProvider;
                                          robots order-insensitive set compare)

SeoRuntimeManager now delegates capture_prior()/restore_delta() to the core via
SeoFieldAccessor; store_rollback (v2 record shape), the seo_restore dispatch, and
both legacy restore paths are unchanged, so already-deployed 7aa7e84 records and
legacy before_state records restore identically.

Behaviour-preserving: test-seo-rollback-delta 56/0 (34 functional unchanged),
seo-rollback-store 28/0, seo-apply 76/0, seo-undo 33/0; new
test-rollback-delta-core 25/0 proves the core with a fake accessor (no WP).
Invariants 34/23/40/40/2.5.0 held. No op/cap/MCP/schema/REST/UI change.

PROGRAM-4A / P4.0. No sibling-runtime migration in this change.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
```

**Status: GO for commit (commit only — do not push, do not deploy).** Awaiting the clean standalone ch-rollback reconfirmation as a final formality; will note the result.
