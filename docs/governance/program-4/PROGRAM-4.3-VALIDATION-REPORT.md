# PROGRAM-4.3 — Content Rollback · Validation Report

> **Date:** 2026-06-23 · DEV, PHP 8.2.27, wp-cli. **Verdict: GO** — behaviours proven; no regression; invariants held; zero attributable failures.

## Lint — clean
`php -l`: `includes/Rollback/ContentFieldAccessor.php`, `includes/Operations/ContentManager.php`.

## Content delta acceptance — `tests/test-content-rollback-delta.sh` → **27 / 0**
S1 value-prior restore · S2 empty-but-existing excerpt restored · S3 sibling preservation + drift (**title restored, sibling content B survives**, status partial) · S4 same-field conflict (newer kept) · S5 out-of-order (no resurrection) · S6 legacy `before_state` update record restores · **S7 delete record still reverts via the legacy path (unchanged)** · S8 repeated rollback guarded · S10 untouched column not in record · 9 static guards.

## Regression
| Suite | Result |
|---|---|
| `test-content-runtime.sh` | **98 / 0** (full content suite incl. update/rollback) |
| `test-rollback-delta-core.sh` | **25 / 0** |
| `test-seo-rollback-delta.sh` | **56 / 0** (no SEO regression) |
| `test-operations-registry` / `capability-runtime` / `mcp-error-surface` | 18/0 · 61/0 · 18/0 |
| `test-change-history-rollback.sh` | standalone/alone — see note |

## Invariants
OPERATION_MAP **34** · capabilities **23** · catalogue **40** · MCP **40** · DB_VERSION **2.5.0** — held (v2 record reuses `wpcc_content_rollbacks` option; no schema).

## Failure classification
No attributable failures. `change-history-rollback`: P4.3 touches neither OperationExecutor nor change-history; rollback dispatcher path (Sections 1–9) green when run; the Section-0 backfill bootstrap is the documented-flaky stateful step (non-attributable). Not a gate.

## Verdict
**GO.** Content `update` rollback is field-scoped, drift-aware, sibling-preserving, out-of-order-safe, partial/conflict-honest, and legacy/delete-compatible — reusing the P4.0 core via a pure post-column accessor, no other action or runtime touched, invariants frozen.
