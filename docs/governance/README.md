# Governance programme records — historical

> **Nothing in this directory describes the current product.**
> For that, read [`../../PROJECT_STATUS.md`](../../PROJECT_STATUS.md), then
> [`../../RELEASE_HANDOFF.md`](../../RELEASE_HANDOFF.md).

The design, audit and validation record of the governance and rollback programmes —
Phase 3 and Programs 4 through 4.10. Each document was accurate on its date; none has been
updated since.

**Every invariant here is stale.** Counts such as `catalogue 40`, `MCP tools 40` or
`DB_VERSION 2.5.0` describe the product mid-programme. The shipped product is **1.0.0,
DB 2.6.0, 42 operations, 42 MCP tools, 23 capabilities**.

## Why this directory is worth keeping

This is where the **rollback engine was designed, attacked and fixed**, and it is the only
place that record exists:

- `phase-3/` — the delta-rollback design, plus its adversarial, architecture, external and
  independent audits. Read `PHASE-3-ADVERSARIAL-REVIEW.md` before changing `RollbackDelta`.
- `program-4/` — field-scoped drift-aware rollback extended across settings, media,
  content, comments, users, WooCommerce, ACF and Elementor, one subsystem at a time, each
  with a design, a forensic report and an independent audit. `PROGRAM-4C-ROLLBACK-INVENTORY.md`
  and the `BULK-DELTA-*` series document a real data-corruption bug and its remediation.

If you are about to modify undo behaviour, the reasoning you need is here — the current
code tells you what it does, these files tell you what it must never do again.
