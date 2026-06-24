# PROGRAM-4.5 — User Rollback Integrity · Final Report

> **Branch:** `program-4.5-user` (from P4.0 `2234dcc`; P4.1–P4.4 excluded). No merge/push/deploy.
> Companion: [Design](PROGRAM-4.5-DESIGN.md) · [Validation](PROGRAM-4.5-VALIDATION-REPORT.md) · [Independent Audit](PROGRAM-4.5-INDEPENDENT-AUDIT.md).

## Branch base
HEAD parent = P4.0 `2234dcc`; `0788720`/`8982e6c`/`dbc7c47`/`4ccf18b` NOT ancestors; sibling accessors absent; `main` unchanged.

## Changed files / diff stat
- **New:** `includes/Rollback/UserFieldAccessor.php`, `tests/test-user-rollback-delta.sh`, reports.
- **Modified:** `includes/Operations/UserManager.php` (`+77/−9`).

## Tests / pass-fail
| Suite | Result |
|---|---|
| **test-user-rollback-delta** (new) | **25 / 0** |
| test-user-runtime | **75 / 0** |
| rollback-delta-core / seo-rollback-delta | 25/0 · 56/0 |
| operations-registry / capability-runtime / mcp-error-surface | 18/0 · 61/0 · 18/0 |
| change-history-rollback | confirmatory (alone) |

## Attributable failures
**None.** Also fixed a pre-existing bug (DEF-U1: update rollback never restored the email) for new v2 records.

## Invariants
**34 / 23 / 40 / 40 / 2.5.0** — held. `UserRegistry` unchanged.

## Residual risks
Roles remain a separate (already-reversible) concern, untouched; usermeta existence fidelity restores '' for previously-absent first/last name (matches prior behaviour); pre-deploy gates deploy-coupled.

## GO / NO-GO
**GO for commit** (branch only; no merge/push/deploy).

## Suggested commit message
```
feat(rollback): field-scoped drift-aware User rollback via RollbackDelta (P4.5)

Migrate the User update (user_update) rollback off the full-object
{email,display_name,first_name,last_name} snapshot onto the P4.0 RollbackDelta core
via a new UserFieldAccessor (user_email/display_name columns + first/last_name
usermeta, read via get_userdata, written via wp_update_user). Rollback is now field-
scoped (only touched fields), drift-aware (skip+report instead of clobber), sibling-
preserving, out-of-order safe, and partial/conflict-honest; legacy before_state update
records and the create/delete/role actions are unchanged.

Also fixes a pre-existing bug (DEF-U1): the legacy update rollback wrote key `email`
instead of `user_email`, so the email was silently never restored — v2 records now
restore it correctly.

New UserFieldAccessor + test-user-rollback-delta (25/0). No regression: user-runtime
75/0, seo-rollback-delta 56/0, rollback-delta-core 25/0. Invariants 34/23/40/40/2.5.0
held; UserRegistry unchanged; no schema/op/cap/MCP/REST/UI change; no other runtime
touched.

PROGRAM-4 / P4.5. Branched from P4.0 2234dcc; P4.1-P4.4 excluded.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
```
