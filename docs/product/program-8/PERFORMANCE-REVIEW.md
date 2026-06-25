# PROGRAM-8 — Performance Review

## Write path (the only new runtime cost)
- **One INSERT per terminal event** (operation completed/failed, connection test, change, rollback) via the subscriber. These are **user/agent-paced**, not per-request — low frequency.
- **No write for non-terminal/noise events** (the subscriber filters; e.g. `mcp.request` is ignored) → avoids excessive/duplicate writes.
- **`ensure_table()` is per-request guarded** (static flag) → `CREATE TABLE IF NOT EXISTS` runs at most once per request, and is a no-op when the table exists.
- **Small rows** — facts only, no payloads/blobs.
- **Guarded** — the recorder/subscriber are `\Throwable`-wrapped; a slow/failed telemetry write cannot stall or break the request, and fires **after** the audit's durable write.

## Read path
- `TelemetryQuery` uses indexed, windowed, aggregate SELECTs (`created_at`, `provider`, `status` indexes). `recent()` is bounded (≤200). No N+1.

## Storage growth
- Bounded by event volume; one row per terminal job. `prune($days)` controls retention (owner-schedulable). Indexes keep queries flat as the table grows.

## Behavior-neutrality (validated)
The observation hook fires after `file_put_contents` of the audit line, so audit + execution behavior are unchanged. **`test-ai-assist.sh` 92/0** and all runtime/security/MCP/capability suites pass with the subscriber active → no measurable runtime impact or regression.

## Recommendations (future, optional)
- Wire `prune()` to a daily wp-cron event (a background job — intentionally NOT added here, to honor "no new background jobs" without owner sign-off).
- If event volume ever becomes high, batch inserts or move to a queue; not needed at current operation rates.

**Verdict:** lightweight, bounded, guarded, behavior-neutral.
