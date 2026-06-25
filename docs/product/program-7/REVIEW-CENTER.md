# PROGRAM-7 — Review Center

## What exists today (the real substrate)
The product already has the two halves of a Review Center:
- **Proposals / Governed Drafts** (ProposalStore + the build-flagged Proposals/AI-Alt-Text/SEO-Meta UIs): AI suggestions staged for review, edit, dismiss, and **governed apply** (through the executor — never a bypass).
- **Approval Center** (operation requests/queue): human approve/reject of gated operations.
- **Change History** (change_log): before/after + **Restore (undo)** for reversible changes.

Program-7 **links these into the AI Mission Control** (Review changes & undo · Approvals · pending count) so the review loop is discoverable from the AI page.

## Designed (unified Review Center — gated)
A single surface to review ALL AI work (SEO / Alt / Content / Woo / Media) with **individual + bulk approve, reject, before/after compare, preview, rollback** requires:
1. **AI enabled** (key + `WPCC_*_UI` flags) so drafts actually exist to review — owner decision, deliberately OFF.
2. A cross-domain proposal list view (the Proposals UI is per-feature + flag-gated today). Unifying it is additive admin UX, but only meaningful once #1 is on.

The governed primitives for every requirement already exist:
- **Individual review/apply/dismiss** → ProposalStore + ProposalApplyService (governed).
- **Bulk** → STEP 111 bulk + STEP 112 SelectionResolver (bounded, capability-scoped).
- **Before/after compare + preview** → Change History DiffRenderer (server-rendered diff).
- **Rollback** → certified delta rollback (Program-4).

## Honest gap
The missing piece is a **single cross-feature review queue UI**, and it is gated behind AI enablement — building it now would render an empty surface (no drafts, because AI is off). Program-7 therefore **designs** it and surfaces the existing review entry points from Mission Control, rather than shipping an empty Review Center.
