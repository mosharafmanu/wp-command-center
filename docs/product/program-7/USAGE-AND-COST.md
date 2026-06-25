# PROGRAM-7 — Usage & Cost

## The honest answer (and why it matters most here)
Users will ask: *how much AI did I use, which provider/model, how much did it cost, how many tokens, which feature?* **Today WP Command Center does not track any of this** — the runtime does not record per-call token counts or cost. **Program-7 shows "Token usage & cost: Not tracked yet" with a tooltip explaining why — and refuses to display a fabricated estimate.**

This is the single most important integrity decision in the program: a usage/cost dashboard full of invented numbers is worse than none. Trust is the product's moat; faking metering would spend it.

## What CAN be shown honestly today (and is)
- **Connection-test latency + discovered-model count** (6S) — real, per test.
- **Activity volume** — count of recent recorded events / generations / changes / rollbacks (Mission Control).
- **Which provider/connection is default and per feature** (routing, 6R/6S).

## Designed (real metering — gated on runtime instrumentation)
Per-token usage and cost require the runtime to:
1. Capture provider `usage` (tokens in/out) from each AI response (the transports return it).
2. Record it per job with model + connection + feature + a cost estimate (price table × tokens).
3. Aggregate into a Usage & Cost view (by day / provider / model / feature) with budgets/alerts.

**All of step 1–2 touch the AI runtime/execution contracts → STOP boundary → out of this experience program.** The 6R connection model already carries the provider/model/connection identity those records would reference, so the data model is ready; only the runtime capture is missing.

## Recommended sequencing
Usage/cost metering belongs to a **runtime-scoped program** (with owner authorization to instrument execution), not an experience program. Until then, "Not tracked yet" is the honest, trust-preserving state — and it is shown, not hidden.
