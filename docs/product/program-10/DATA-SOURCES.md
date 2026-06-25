# PROGRAM-10 — Data Sources

All sources are **existing + read-only**. Nothing is created, instrumented, or mutated.

| Section | Source | Read API | Notes |
|---|---|---|---|
| Needs attention — pending | approval queue (`wpcc_operation_requests`) | `AiActivity::pending_approvals()` | guarded `SHOW TABLES LIKE`; same source as the admin-bar badge |
| Needs attention — failures | telemetry (`wpcc_telemetry`) | `TelemetryQuery::recent()` filtered `status='failed'` | real recorded failures only |
| Operations timeline | telemetry | `TelemetryQuery::recent($limit)` | status/provider/model/duration; duration NULL = unknown |
| Operations timeline (fallback) | audit log (JSONL) | `AiActivity::feed($limit)` | when telemetry empty; duration not measured (stated) |
| System activity | telemetry | `TelemetryQuery::summary($days)` | completed/failed/running/cancelled/avg; `running` real-only |
| Review & undo | change log (`wpcc_change_log`) | `ChangeHistoryAdminQuery::sessions()` | FeatureGate `change_history` + try/catch guard |
| Data coverage | telemetry | `TelemetryQuery::summary()` | tokens coverage; cost always "not tracked yet" |

## Guards (no fatals, no fabrication)
- Every telemetry read goes through `TelemetryQuery`, which returns empty/zero when the table doesn't exist → **honest empty states**, never an error.
- `AiActivity::pending_approvals()` checks the requests table exists first.
- `reversible()` is gated by `FeatureGate::allows('change_history')` and wrapped in `try/catch` → `[]` when the source is gated/missing.
- Cost is hard-coded to "not tracked yet"; tokens reflect real coverage (`tokens_known`).

## Explicitly NOT used / NOT created
- No new queue scan that alters state; no new runtime emission; no EventBus subscriber (the bus stays a foundation); no telemetry writes; no schema. The Operations Center is purely a *reader* of what Programs 4–9 already produce.
