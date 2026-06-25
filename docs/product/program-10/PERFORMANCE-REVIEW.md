# PROGRAM-10 — Performance Review

## Bounded by design (server-rendered, on demand)
The Operations Center renders only when its tab is opened, and every read is bounded:

| Read | Cost |
|---|---|
| `pending_approvals()` | one guarded `COUNT(*)` on `wpcc_operation_requests` (same as the admin-bar badge) |
| timeline | `TelemetryQuery::recent(20)` — one indexed `SELECT … ORDER BY id DESC LIMIT 20` |
| failures | filtered from one `recent(100)` read (no extra query) — capped at 5 shown |
| status roll-up | `TelemetryQuery::summary(30)` — one indexed aggregate over a 30-day window |
| telemetry_active | one `summary()` count read |
| reversible | `ChangeHistoryAdminQuery::sessions([],8,0)` — the existing, paginated query (bounded) |

≈ a handful of bounded, indexed queries per page load. **No N+1**, no per-row queries, no unbounded scans.

## No new load elsewhere
- **No writes**, no new tables, no autoloaded options, no background jobs, no polling/auto-refresh (the page is static on load).
- The aggregator is read-only and instantiates lightweight query objects.

## Large sites / deep history
- Telemetry reads use the P8 indexes (`status`, `created_at`, `id`); change-history `sessions()` is the existing bounded/paginated query.
- The audit fallback (`AiActivity::feed`) reads a **capped audit tail** (P7), not the whole log.
- Render size is constant (fixed row caps) regardless of how much history exists.

## Validation
All runtime/admin suites pass with the new tab active; `ai-assist` 92/0; no measurable impact (the surface is opt-in and read-only).

## Recommendation
If the Operations Center later gains real-time updates, do it via an explicit, user-initiated refresh or a bounded polling interval with backoff — never an always-on poll. Out of scope here (this program is server-rendered on load).
