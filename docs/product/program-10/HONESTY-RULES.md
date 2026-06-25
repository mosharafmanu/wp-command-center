# PROGRAM-10 — Honesty Rules

The Operations Center makes the product feel alive **without faking liveness**. These rules are enforced in code and asserted by tests.

## Rules
1. **No fabricated cost.** Cost is always "Not tracked yet" (per-token cost is uninstrumented — the P8 boundary). The test asserts no `$`-figure appears for cost.
2. **No fabricated tokens.** Token coverage is "Not tracked yet" unless telemetry actually recorded tokens (`tokens_known > 0` → "Partly tracked"). Never a made-up count.
3. **No invented running states.** `running` is read straight from telemetry rows whose status is literally `running`. Today the observation path records terminal events, so `running` is honestly usually 0 — not faked to look busy.
4. **Unknown durations say "unknown."** Telemetry duration is NULL until measured; the UI prints "unknown", never 0 or an estimate. The audit-fallback timeline explicitly states duration isn't measured for those events.
5. **No fake jobs.** Every timeline/failure row is a real recorded telemetry job or a real audit event. When there are none, the UI shows a teaching empty state ("No operations recorded yet"), not placeholder rows.
6. **No fake liveness.** No polling, no auto-refresh, no spinner pretending work is happening. The page shows the freshest real data on load.
7. **Honest empty/all-clear.** "All clear" only when pending = 0 AND no recent failures. Empty sections explain what *would* appear, not fabricate it.
8. **Data coverage is explicit.** A dedicated "Data coverage" panel tells the user exactly which signals are measured (telemetry) vs not yet (tokens/cost) — turning "unknown" into a transparent, trust-building statement.

## Why this matters
Trust is the product's moat. An ops center full of invented durations/costs/running counts would look impressive and be worthless. By showing only real data and naming the gaps, the Operations Center is credible to the agencies and operators who would stake client sites on it.
