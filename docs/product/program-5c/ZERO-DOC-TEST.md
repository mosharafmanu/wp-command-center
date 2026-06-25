# PROGRAM-5C — Phase G: Zero-Doc Test

> No docs, no videos, no onboarding call. Can the user, from the UI alone…?

| Task | Zero-doc outcome | Why |
|---|---|---|
| **See WPCC do something** | ✅ **Yes** | Overview leads with "Run a site report — no AI or setup needed." Real result in ~2 min. |
| **Understand what WPCC is/does** | ✅ **Yes** | First-run intro + "does/doesn't" + how-it-works loop, all plain language. |
| **Connect AI (conceptually)** | ✅ **Yes** | "New to AI assistants?" explainer answers what/why/token/what-talks-to-what + flow line + setup order. |
| **Add an AI key** | ✅ **Yes** | Connect → AI Setup; masked, with a "what happens next" guide. |
| **Create a token** | ✅ **Yes** | Tokens screen copy now explains create + revoke (5B fix) + the token-as-password framing (5C). |
| **Actually connect the agent app** | ⚠️ **Partial** | The UI explains the concept + provides the config to paste, but operating the external assistant app (e.g., Claude desktop) is outside WPCC. Inherent; concierge bridges it. |
| **Understand the workflow** | ✅ **Yes** | how-it-works strip: propose → approve → record → undo. |
| **Review work** | ✅ **Yes** | Overview "Needs attention" + admin-bar badge + Operate→Approvals + "Approvals →" link. |
| **Approve work** | ✅ **Yes** | Approvals screen; human-approver requirement is explicit. |
| **Undo work** | ✅ **Yes** | Audit→Changes Restore (copy corrected) + "Changes →" link in the loop. |
| **Actually run an AI workflow (SEO/alt-text)** | ❌ **No** | AI screens flag-OFF; needs enabling + an agent. **F1 — structural, out of scope.** |

## Score
**8 of 11 tasks fully zero-doc; 1 partial (external app); 2 blocked by the structural F1 gate.**

## Conclusion
Without any documentation, a user can now: get a real first result, understand the product and the safety loop, set up keys/tokens, grasp what an agent is, and find review/approve/undo. The only zero-doc failures are (a) operating a third-party assistant app (inherent) and (b) running the AI workflows themselves (flag-off — product decision). Both are honestly signposted rather than silently broken.

**Phase G: GREEN (zero-doc comprehension achieved for everything in scope).**
