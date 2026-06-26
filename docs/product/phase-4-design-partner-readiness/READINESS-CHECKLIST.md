# Design Partner Readiness Checklist

> The product computes this live (`DesignPartnerReadiness`) and shows it on **Home** (one next action + collapsible full list). This doc is the human-readable reference. **All states are real — nothing is fabricated.**

## "Can I run the first governed AI change now?"

| # | Item | Pass when | Status if not | Next action |
|---|---|---|---|---|
| 1 | **Approvals on (Client-safe mode)** | Security mode requires human approval | ⚠️ warning (Developer mode self-approves) | Set Client-safe mode (Settings › Security & Approvals) |
| 2 | **AI provider connected** | A provider key is configured | ⛔ blocked | Connect a provider (Built-in AI › Providers) |
| 3 | **Provider tested** | Last connection test passed | ⚠️ warning (or ⛔ if no key) | Test the connection |
| 4 | **Generation supported** | A provider is connected | ⛔ blocked | — (honest note: *generation runs on Anthropic today*) |
| 5 | **A built-in AI tool is on** | SEO, Alt Text, or Content enabled | ⛔ blocked | Turn on a tool (Built-in AI › Providers) |
| 6 | **Test content available** | A post **and** an image exist | ⚠️ warning | Add a post or image |
| 7 | **Approvals ready** | Always (the approval engine) | — | Open Approvals |
| 8 | **History & undo ready** | Change History available | ⚠️ warning | Open History |

**`can_run_first_workflow()` = true** when **no item is blocked** (warnings are advisory). Items 2, 4, and 5 are the hard gates; 1 and 3 are strong warnings the founder should clear before a real-site demo.

## Founder pre-demo setup (the manual bits)
- [ ] Paste a **real Anthropic key** on Built-in AI › Providers and **Test** it (items 2–4).
- [ ] Confirm **Client-safe mode** (item 1) — fresh installs seed it; flip it on existing installs.
- [ ] **Turn on one tool** (Alt Text or SEO) from the enablement card (item 5).
- [ ] Ensure a **draft post + an image** exist (item 6).
- [ ] Open **Approvals** and **History** tabs so they're ready to show (items 7–8).

When all eight are green (or the three hard gates are), the Home panel shows **"You're ready to run your first governed AI change."**
