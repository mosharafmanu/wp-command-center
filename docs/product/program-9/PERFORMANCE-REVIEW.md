# PROGRAM-9 — Performance Review

## Added cost: negligible, and zero today
On each `wpcc_audit_recorded` fire (already low-frequency — terminal/lifecycle events, not per-request), the bridge:
1. builds one immutable `RuntimeEvent` (pure in-memory string parsing — no I/O), and
2. calls `EventBus::publish()`.

`publish()` is a **no-op when there are no subscribers** (the production default in this program) — it returns immediately. So today the bridge adds **one cheap object construction per audit record and nothing else**.

## When subscribers exist (future)
- `publish()` filters subscribers by pattern (in-memory array_filter), sorts the matches by priority, and calls each — all in memory.
- **No I/O in the bus itself** — any storage/network is a subscriber's own concern (e.g. a webhook), which a well-behaved subscriber should defer/queue.
- Each handler is guarded; a slow subscriber affects only itself, not the runtime (which has already completed — the hook fires post-write).

## Memory / lifecycle
- Subscribers are a small static array for the request; events are short-lived immutable objects (no retention).
- No new tables, options, autoloaded data, or background jobs.

## Hot-path safety
The bus is **not** on any request hot path beyond the existing audit emission, which only fires on meaningful runtime events. There is no per-HTTP-request or per-query overhead.

## Validation
`ai-assist` 92/0, telemetry 21/0, change-history 119/0, and all runtime suites pass with the bridge registered → **no measurable performance impact**.

## Recommendations (future)
- Webhook/notification subscribers should perform network I/O asynchronously (enqueue, not inline) — the bus delivers synchronously, so heavy subscribers should hand off to wp-cron/a queue. Documented for subscriber authors; not needed for the bus itself.
