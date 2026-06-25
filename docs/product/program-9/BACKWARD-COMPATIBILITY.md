# PROGRAM-9 — Backward Compatibility

## What changed
**One additive line** in `Plugin::boot()` registering `EventBridge`, plus the new `includes/Events/` classes. Nothing else.

## What did NOT change
- **Runtime / executor** — untouched (no new emission point; the bus reuses P8's existing `wpcc_audit_recorded` hook).
- **AuditLog** — untouched this program; still authoritative; the P8 emission fires exactly as before.
- **Telemetry** — untouched; `TelemetrySubscriber` still listens directly to `wpcc_audit_recorded` and records identically.
- **Approvals / rollback / security / MCP / capability / operation registry / Schema / DB_VERSION** — untouched (DB_VERSION 2.5.0 preserved).

## Existing subscribers keep working (verified)
- The `wpcc_audit_recorded` hook now has **two independent listeners** — `TelemetrySubscriber` and `EventBridge`. WordPress dispatches both; neither affects the other.
- `test-ai-assist.sh` **92/0**, `test-telemetry-8.sh` **21/0**, `test-change-history-admin.sh` **119/0**, and all runtime/security/MCP/capability suites pass with the bridge active.

## Zero duplicate recording
- The **bus records nothing** (it only delivers events to its subscribers).
- **One bridge → one publish** per audit record.
- Telemetry records once (as before); audit records once (as before). The bus adds no second recording path.

## Failure isolation
- `EventBus::publish` is a **no-op when there are no subscribers** (the production default today) → effectively zero added work.
- Every subscriber + the bridge are `\Throwable`-guarded → a future faulty subscriber cannot break audit, telemetry, the bus, or the runtime.

## Rollback of this program
Trivially reversible: revert the one `Plugin::boot` line and delete `includes/Events/`. No data, schema, or behavior to unwind.
