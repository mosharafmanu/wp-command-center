# PROGRAM-7 — AI Workflow Design

## The workflow contract (already the product's spine)
Every AI workflow is the governed pipeline that Programs 4–6 already enforce:

```
Generate (AI proposes)  →  Review (drafts)  →  Approve (human, mode-aware)
   →  Apply (single executor chokepoint)  →  Audit (recorded)  →  Rollback (where supported)
```

This is **not new architecture** — it is the existing ProposalStore → OperationExecutor → ChangeRecorder/AuditLog → rollback path. Program-7's contribution is to make these stages **visible and navigable as one workflow** (Mission Control activity + links to Approvals/Changes), not to rebuild them.

## Per-domain workflows (designed; substrate exists, generation gated)
| Domain | Steps | Substrate today | Gate |
|---|---|---|---|
| **SEO meta** | generate → review → approve → apply → audit → rollback | SEO runtime + certified delta rollback (P4) + proposals + approval | generation flag-OFF; key unset |
| **Alt text** | generate → review → approve → apply → rollback | Media metadata runtime (certified) + alt-text generator (dormant) | flag-OFF |
| **AI content** (title/excerpt) | generate → review → approve → publish → undo | Content runtime (certified) + content generator (dormant) | flag-OFF |
| **WooCommerce** (descriptions/short/SEO/attributes) | generate → review → approve → apply → rollback | Woo Products runtime (certified, **dormant on prod**) | flag-OFF + Woo inactive |
| **Media** (captions/alt/titles/descriptions) | generate → review → approve → apply → rollback | Media metadata + enhancement runtimes | flag-OFF |

## What Program-7 did NOT do (honest, and why)
- **No live generation built/enabled.** Enabling AI (key + `WPCC_*_UI` flags) is an owner/product decision the prior programs deliberately left OFF; flipping it is not an "experience" change and every prior program refused to.
- **No new generation runtime.** Wiring generation for OpenAI/Gemini/Woo etc. is runtime work (STOP boundary). The 6R dialect architecture makes it a localized future addition.
- **No faked workflow runs.** The Mission Control feed shows only real events.

## The honest "effortless SEO for my whole site" answer
The *shape* is ready and governed; *effortlessness* requires: AI enabled + a connection (6R/6S) + either an MCP agent (Connect) or the governed-drafts UI (flag-OFF) + the bulk/selection primitives (already present, STEP 111/112). The remaining gap is **enablement + a human bulk-review surface** (REVIEW-CENTER design), not the workflow architecture.
