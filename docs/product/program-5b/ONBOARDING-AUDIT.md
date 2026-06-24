# PROGRAM-5B — Phase C: Onboarding Audit + First-Run

## Existing onboarding (pre-5B)
- 5A added a server-rendered first-run checklist (safety mode → optional AI key → token → review/undo) + a "does/doesn't" disclosure. Good base.
- **Gaps found:**
  - No plain-language explanation of *how* the safety loop works (AI → approve → record → undo).
  - Two surfaces actively **mis-stated** capability: Changes ("restore arrives later") and Tokens ("create/revoke arrives later") — onboarding-killing inaccuracies (fixed in Phase B/§P0).
  - Some jargon ("operations", "runtime", "MCP endpoint", "snapshot for AI agents").

## The 7 understanding points — where each is now answered
| # | A new user should understand… | Where |
|---|---|---|
| 1 | **What WPCC is** | First-run intro + "does/doesn't" ("lets an AI agent operate *this* site under your control"). |
| 2 | **What it does** | Checklist + "How WPCC keeps you in control" 4-step strip. |
| 3 | **What it does NOT do** | "does/doesn't" block: not a backup tool, not a fleet manager, not undo-everywhere. |
| 4 | **How AI works** | New 4-step strip step 1 ("AI proposes — nothing happens yet") + AI Setup ("AI is optional and off until you add a key"). |
| 5 | **How approvals work** | Step 2 ("In Client mode, every change waits for your OK") + Security Mode copy. |
| 6 | **How rollback works** | Step 4 ("Reversible changes have a one-click Restore") + corrected Changes copy. |
| 7 | **How to safely start** | Checklist ordering (choose Client mode first) + links to each surface. |

## Changes implemented
1. **"How WPCC keeps you in control"** 4-step strip in the first-run panel: AI proposes → You approve → It is recorded → You can undo. Plain language, no jargon.
2. **Corrected the two stale-capability lines** (Phase B/§P0) so onboarding no longer hides shipped Restore + token create/revoke.
3. Retained 5A's honest "does/doesn't" disclosure (already covers limits without overclaim).

## Jargon reduction
- "AI Integrations" → "Connect an AI Agent"; "Runtime" → "Runtime (advanced)" (Phase B).
- First-run copy uses "change", "approve", "undo", "record" — not "operation", "capability", "snapshot".
- Remaining engineer surfaces (Operations Explorer, Site Intelligence, File Access, Patches) are de-emphasized but not rewritten — they are advanced/optional and out of the onboarding path.

## Validation
- `php -l` clean.
- `test-usability-5b.sh` §6 → how-it-works strip + all 4 steps green.
- `test-adoption-readiness.sh` (5A) still 44/0 (first-run integrity preserved).

**Phase C: GREEN.**
