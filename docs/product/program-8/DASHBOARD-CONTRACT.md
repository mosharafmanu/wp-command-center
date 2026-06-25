# PROGRAM-8 — Dashboard Contract

> Future dashboards **consume telemetry only** via `TelemetryQuery`. They render shapes; they never compute business logic in a view.

## Read API (`Telemetry\TelemetryQuery`)

### `summary(int $days = 30): array`
```
{
  total, completed, failed, running, cancelled,
  avg_duration_ms | null,  duration_known,
  tokens_input, tokens_output, tokens_known,
  cost_micros, cost_known,            // sums cover only measured rows
  window_days
}
```
`*_known` counts expose **coverage** so a dashboard can honestly show "estimated where known" rather than implying full data.

### `recent(int $limit = 25): array<row>`
Newest-first job rows (full columns) — for a **Job Center / Operations Timeline** (status chips, duration, provider/model, error_code, retry_count, cancelled).

### `by_provider(int $days = 30): array<row>`
Per-provider roll-up: `{provider, jobs, tokens_input, tokens_output, cost_micros, cost_known, avg_duration_ms}` — for **Usage & Cost** and **provider comparison**.

## Cost formatting
`CostModel::format_micros(?int) → "$0.0000" | "—"` ("—" for unknown). Dashboards must render "—"/"estimated" honestly — never a fabricated number.

## Mapping to the planned surfaces
| Surface | Consumes |
|---|---|
| **Mission Control** | `summary()` + `recent()` (a richer, measured replacement for the audit-derived feed once push instrumentation lands). |
| **Job Center** | `recent()` (+ filters/search over the same indexed columns). |
| **Usage & Cost** | `by_provider()` + `summary()` (tokens/cost where measured; "—" elsewhere). |
| **Operations Timeline** | `recent()` ordered by created_at. |
| **Enterprise reporting / provider comparison / diagnostics** | `summary(window)` + `by_provider(window)`. |

## Contract rules for view authors
1. Read via `TelemetryQuery` only; do not query the table directly from a view.
2. Treat NULL as "unknown" and render "—"/"estimated", never 0 or a guess.
3. Use the `*_known` coverage counts to qualify aggregates.
4. No business logic in the view — aggregation lives in `TelemetryQuery`.
