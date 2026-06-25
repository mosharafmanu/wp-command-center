# PROGRAM-8 — Event Lifecycle

## Lifecycle states
`running → completed | failed | cancelled` (with `retry_count`, `unknown` as the safe default).

## Push path (TelemetryRecorder — sanctioned future insertion point)
```
start(job_id, kind, {operation, provider, model, capability, started_at, queue_ms…})
   → row inserted: status=running, started_at=now
complete(job_id, {tokens_input, tokens_output, completed_at, exec_ms…})
   → status=completed; duration_ms = (completed-started)*1000 (derived);
     estimated_cost_micros = CostModel(model, tokens) or NULL (unknown);
     model/started_at/tokens BACKFILLED from the start row if not re-passed.
fail(job_id, error_code, …)   → status=failed, error_code recorded
cancel(job_id)                → status=cancelled, cancelled=1
retry(job_id, count)          → retry_count updated
```
Every method is `\Throwable`-guarded: a telemetry failure can never change an operation's outcome.

## Observation path (TelemetrySubscriber — used now)
The audit engine fires `wpcc_audit_recorded($action, $context, $timestamp)` **after** its durable write. The subscriber records ONE row per **terminal** event (filtered to avoid excessive/duplicate writes):

| Audit action pattern | Telemetry kind | Status | Fields captured |
|---|---|---|---|
| `*.completed` | operation / ai_generation / … | completed | operation, provider, model, **duration_ms** (if present), actor |
| `*.failed`, `*exception*` | (same) | failed | + error_code |
| `ai.connection.test` | connection_test | from `result` | provider, model, result→error_code |
| `change.recorded` | change | completed | operation, actor |
| `*rollback*` (dispatched/applied/completed) | rollback | completed | operation, actor |

**Honest:** tokens/cost are NOT in audit context → stored NULL. Duration is captured only when the runtime already records `duration_ms` (e.g. diagnostics) — otherwise NULL.

## Field provenance (measured vs derived vs unknown)
- **Measured (from runtime/audit):** status, started_at/completed_at, duration_ms (where recorded), provider, model, operation, capability, actor, error_code, result.
- **Derived:** duration_ms (from timestamps), estimated_cost_micros (from tokens × price).
- **Unknown until push instrumentation (NULL):** tokens_input/output, cost (when unpriced/untokened), queue_ms/approval_wait_ms (unless provided), exec_ms.

No field is ever fabricated; "unknown" is a first-class NULL.
