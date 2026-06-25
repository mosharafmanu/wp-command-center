# UX Recommendation

## Issue 1 — make discovered models available (YES)
- After a successful test, persist the real model IDs the provider returned.
- In the Edit selector, present:
  1. **Recommended** (curated) — grouped first, default selected.
  2. **Discovered (N)** — the real models from the last test, grouped below recommended (deduped against recommended).
  3. **"Custom model ID…"** — always available, reveals a free-text field.
- Never invent models; only show what the provider actually returned.
- The new-connection wizard keeps the curated list (no test has run yet → nothing discovered); discovery surfaces on the existing connection after its test.

## Issue 2 — explain routing eligibility (clarity only)
- State plainly in the routing intro that the runtime **executes through Anthropic (Claude) only**, so only Anthropic connections are selectable.
- Show healthy-but-ineligible connections (e.g. a working OpenAI) **as disabled options with a reason** ("… — not usable by the runtime yet") so their absence is never a mystery.
- Add a one-line note: they become selectable when runtime support for their provider ships — **no provider support is faked**.
- Improve the empty state: if the only healthy connections are ineligible, say so explicitly ("You have N healthy connection(s) … but the runtime executes through Anthropic only").

## Principle
Both fixes follow the product's honesty rule: **use real data, explain real limits, fake nothing.** Discovered models are real; the Anthropic-only runtime limit is real and now visible.
