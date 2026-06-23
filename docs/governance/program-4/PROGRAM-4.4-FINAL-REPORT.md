# PROGRAM-4.4 — Comments Rollback Integrity · Final Report

> **Branch:** `program-4.4-comments` (from P4.0 `2234dcc`; P4.1/P4.2/P4.3 excluded). No merge/push/deploy.
> Companion: [Design](PROGRAM-4.4-DESIGN.md) · [Validation](PROGRAM-4.4-VALIDATION-REPORT.md) · [Independent Audit](PROGRAM-4.4-INDEPENDENT-AUDIT.md).

## Branch base
HEAD parent = P4.0 `2234dcc`; `0788720`/`8982e6c`/`dbc7c47` NOT ancestors; sibling accessors absent; `main` unchanged.

## Finding
Comments has **no F-1 full-object over-reach**: approve/unapprove/spam captured nothing and were **not reversible**. P4.4 closes that reversibility gap with field-scoped, drift-aware status rollback via the P4.0 core. trash (untrash) and delete (irreversible) unchanged.

## Changed files / diff stat
- **New:** `includes/Rollback/CommentFieldAccessor.php`, `tests/test-comment-rollback-delta.sh`, reports.
- **Modified:** `includes/Operations/CommentsRuntimeManager.php` (`+68/−3`).

## Tests / pass-fail
| Suite | Result |
|---|---|
| **test-comment-rollback-delta** (new) | **24 / 0** |
| test-comments-runtime | **44 / 0** |
| rollback-delta-core / seo-rollback-delta | 25/0 · 56/0 |
| operations-registry / capability-runtime / mcp-error-surface | 18/0 · 61/0 · 18/0 |
| change-history-rollback | confirmatory (alone) |

## Attributable failures
**None.** (One authoring-time test-expectation error on S8 — `comment_delete` stores no record by design — corrected; not a product defect.)

## Invariants
**34 / 23 / 40 / 40 / 2.5.0** — held. `CommentsRegistry` unchanged.

## Residual risks
Status-delta targets approve/unapprove/spam (hold/approved/spam transitions, proven); trash reversed structurally; pre-deploy gates deploy-coupled.

## GO / NO-GO
**GO for commit** (branch only; no merge/push/deploy).

## Suggested commit message
```
feat(rollback): field-scoped drift-aware Comment status rollback via RollbackDelta (P4.4)

Comments had no full-object over-reach to migrate: approve/unapprove/spam captured
nothing and were not reversible. P4.4 closes that gap by capturing the comment
moderation status (comment_approved) before the change and restoring it drift-aware
via the P4.0 RollbackDelta core + a new CommentFieldAccessor. approve/unapprove/spam
now surface a rollback_id and are reversible (skip+conflict if the status drifted);
trash (untrash) and delete (irreversible) are unchanged.

New CommentFieldAccessor + test-comment-rollback-delta (24/0). No regression:
comments-runtime 44/0, seo-rollback-delta 56/0, rollback-delta-core 25/0. Invariants
34/23/40/40/2.5.0 held; CommentsRegistry unchanged; no schema/op/cap/MCP/REST/UI
change; no other runtime touched.

PROGRAM-4 / P4.4. Branched from P4.0 2234dcc; P4.1/P4.2/P4.3 excluded.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
```
