# PROGRAM-5C — Phase E: Approval & Undo Discoverability

## The Reality-Audit finding
Users did not know approvals / audit history / rollback / restore existed. Two causes: (1) **stale copy hid them** (Changes said Restore "arrives later"; Tokens said create "arrives later") and (2) **no signposting** from the places a user looks first.

## Would a new user discover them naturally? (after fixes)
| Trust feature | Discoverable via |
|---|---|
| **Approvals** | Overview "Needs attention" hero; admin-bar **"AI Requests"** badge (pending count, all pages); Operate section description "Review and approve the work AI wants to do"; **5C: "Approvals →" link in the how-it-works strip.** ✅ |
| **Audit history** | Audit section description "See every change, and undo the ones that can be reversed"; Overview recent-activity timeline cross-links. ✅ |
| **Rollback / Restore** | **5B: corrected Changes copy** ("Reversible changes show a Restore button…"); **5C: "Changes →" link in how-it-works step 4** ("You can undo"). ✅ |

## Changes implemented (5C)
1. **"Approvals →" link** added to how-it-works step 2 ("You approve").
2. **"Changes →" link** added to how-it-works step 4 ("You can undo").
   Both put the trust surfaces one click from the first screen a user sees, in the context that explains them.

## Prior-program contributions (still in force)
- 5B: section descriptions (Operate/Audit) + corrected stale copy that had *hidden* Restore and token-create.
- 5A: first-run checklist step "Know where to review & undo changes."
- Existing: admin-bar pending-approvals badge (`AdminMenu::admin_bar_badge`), shown whenever a human approver is required.

## Verdict
A new user now encounters the safety net in **three** places without searching: the first-run checklist, the how-it-works strip (with direct links), and the persistent admin-bar badge. Trust features are visible by default.

## Validation
`test-first-value-5c.sh` §4 → Approvals + Changes links present in the how-it-works strip.

**Phase E: GREEN.**
