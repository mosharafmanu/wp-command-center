# PROGRAM-4.5 — User Rollback · Validation Report

> **Date:** 2026-06-23 · DEV, PHP 8.2.27, wp-cli. **Verdict: GO** — behaviours proven (incl. DEF-U1 fix); no regression; invariants held; zero attributable failures.

## Lint — clean
`php -l`: `includes/Rollback/UserFieldAccessor.php`, `includes/Operations/UserManager.php`.

## User delta acceptance — `tests/test-user-rollback-delta.sh` → **25 / 0**
S1 email value-prior → **EMAIL restored (DEF-U1 fixed)** · S2 display_name restore · S3 sibling preservation + drift (email restored, later display_name survives, partial) · S4 same-field conflict (newer email kept) · S5 out-of-order (no resurrection) · S6 legacy `before_state` update record restores (legacy path) · S7 repeated rollback guarded · S8 untouched field not in record · 9 static guards.

## Regression
| Suite | Result |
|---|---|
| `test-user-runtime.sh` | **75 / 0** (update + rollback + role actions) |
| `test-rollback-delta-core.sh` | **25 / 0** |
| `test-seo-rollback-delta.sh` | **56 / 0** |
| `test-operations-registry` / `capability-runtime` / `mcp-error-surface` | 18/0 · 61/0 · 18/0 |
| `test-change-history-rollback.sh` | standalone/alone — confirmatory |

## Invariants
**34 / 23 / 40 / 40 / 2.5.0** — held (v2 record reuses `wpcc_user_rollbacks` option; **`UserRegistry` unchanged**; no schema).

## Failure classification
No attributable failures. (DEF-U1 — email not restored on update rollback — was a **pre-existing bug**, now **fixed** for new v2 records; legacy records retain prior behaviour via the unchanged switch.)

## Verdict
**GO.** User `update` rollback is field-scoped, drift-aware, sibling-preserving, out-of-order-safe, and partial/conflict-honest, **and now restores the email** (DEF-U1). Roles (assign/remove/suspend), create, and delete are untouched; no registry/schema change.
