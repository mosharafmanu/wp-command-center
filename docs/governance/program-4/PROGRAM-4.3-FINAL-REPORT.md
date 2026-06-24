# PROGRAM-4.3 — Content Rollback Integrity · Final Report

> **Branch:** `program-4.3-content` (from P4.0 `2234dcc`; P4.1/P4.2 excluded). No merge/push/deploy.
> Companion: [Design](PROGRAM-4.3-DESIGN.md) · [Validation](PROGRAM-4.3-VALIDATION-REPORT.md) · [Independent Audit](PROGRAM-4.3-INDEPENDENT-AUDIT.md).

## Branch base
HEAD parent = P4.0 `2234dcc`; `0788720`(P4.1)/`8982e6c`(P4.2) NOT ancestors; OptionAccessor/MediaFieldAccessor absent; `main` unchanged.

## Changed files / diff stat
- **New:** `includes/Rollback/ContentFieldAccessor.php`, `tests/test-content-rollback-delta.sh`, `docs/governance/program-4/PROGRAM-4.3-*.md`.
- **Modified:** `includes/Operations/ContentManager.php` (`+76/−7`).

## Tests / pass-fail
| Suite | Result |
|---|---|
| **test-content-rollback-delta** (new) | **27 / 0** |
| test-content-runtime | **98 / 0** |
| rollback-delta-core / seo-rollback-delta | 25/0 · 56/0 |
| operations-registry / capability-runtime / mcp-error-surface | 18/0 · 61/0 · 18/0 |
| change-history-rollback | confirmatory (alone); rollback dispatcher path also covered by content-runtime 98/0 |

## Attributable failures
**None.**

## Invariants
**34 / 23 / 40 / 40 / 2.5.0** — held.

## Residual risks
Per-field column writes (extra `post_modified`, cold path); `delete`-record rollback retains pre-existing behaviour (out of scope); pre-deploy gates deploy-coupled.

## GO / NO-GO
**GO for commit** (branch only; no merge/push/deploy).

## Suggested commit message
```
feat(rollback): field-scoped drift-aware Content rollback via RollbackDelta (P4.3)

Migrate the Content update (content_update) rollback off the full-object
{title,status,content,excerpt} snapshot onto the P4.0 RollbackDelta core via a new
ContentFieldAccessor (pure post columns). Rollback is now field-scoped (only touched
fields), drift-aware (skip+report instead of clobber), sibling-preserving, out-of-
order safe, and partial/conflict-honest; legacy before_state update records AND
action-based delete records still restore unchanged (rollback_content branches on
the v2 `fields` shape).

New ContentFieldAccessor + test-content-rollback-delta (27/0). No regression:
content-runtime 98/0, seo-rollback-delta 56/0, rollback-delta-core 25/0. Invariants
34/23/40/40/2.5.0 held. No schema/op/cap/MCP/REST/UI change; no other runtime touched.

PROGRAM-4 / P4.3. Branched from P4.0 2234dcc; P4.1/P4.2 excluded.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
```
