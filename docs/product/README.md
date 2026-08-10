# Product & programme records — historical

> **Nothing in this directory describes the current product.**
> For that, read [`../../PROJECT_STATUS.md`](../../PROJECT_STATUS.md), then
> [`../../RELEASE_HANDOFF.md`](../../RELEASE_HANDOFF.md).

These are the working records of how WP Command Center was built: per-programme designs,
implementation reports, validation runs, session handoffs, audits and strategy notes. Each
was accurate on the date it was written. None has been updated since.

## Read them with two things in mind

**Every invariant is stale.** Files here routinely quote counts like `MCP tools 40`,
`catalogue 40`, `DB_VERSION 2.5.0` or `WPCC_VERSION 0.2.0-rc.2`. The shipped product is
**1.0.0, DB 2.6.0, 42 operations, 42 MCP tools, 23 capabilities**. Do not carry a number
out of this directory.

**Several files claim to be the entry point.** `CURRENT-PRODUCT-STATUS.md`, the
`SESSION-HANDOFF-*.md` series and `PRODUCT-MASTER-PLAN.md` all say some version of "start
here". They were each true in their week. They are not now.

## What they are genuinely good for

- **Why a decision was made.** The programme reports (`program-4` through `program-10`,
  `phase-*`) carry the design reasoning, the adversarial reviews and the rejected
  alternatives behind the rollback engine, the AI platform seam, telemetry and the event
  bus. That reasoning is not repeated anywhere else.
- **What was tried and abandoned**, which is the expensive knowledge to rediscover.
- **The product framing.** `CURRENT-PRODUCT-STATUS.md`'s "Three Doors, One Engine"
  description is still the clearest short statement of what the product is for, even
  though its numbers are wrong.

Formally superseded documents — handoffs, roadmaps, submission notes and certifications —
live in [`../archive/`](../archive/README.md) with individual banners.
