# PROGRAM-7 — Job Center

## Implemented precursor (honest, read-only)
The Mission Control **activity feed** is the read-only precursor to a Job Center: it lists real recorded events (newest first), classified by category, with actor + relative time, and the live **pending-approvals** count linking to the Approval Center (which already has queued/running/completed/failed states for governed operations).

So today a user *can* answer "what has happened and what's waiting on me" from the AI page — using the existing approval queue + audit log, surfaced honestly.

## Designed (full Job Center — gated on runtime instrumentation)
A dedicated Jobs surface with **Queued / Running / Completed / Failed / Cancelled**, retry, history, filters, search, and per-job **details (logs, tokens, duration, model, connection)** requires the runtime to **emit job records** with those fields. Today:
- The **approval queue** (`wpcc_operation_requests`/queue) provides queued/running/completed/failed for *governed operations* — a real Job Center spine already exists for those (Approval Center).
- **Per-job tokens/duration/model** are **not recorded** by the runtime → cannot be shown without instrumenting the runtime (a STOP-boundary change to runtime/execution contracts). Program-7 does **not** fabricate them.

## The honest design (for the next, runtime-scoped program)
1. Runtime records a `job` row per AI execution: `{id, feature, connection, model, tokens_in/out, duration_ms, status, started/finished, change_id}` — additive, but it touches execution/runtime contracts ⇒ **out of this experience program**.
2. A Job Center view groups those by status with filters/search/retry, reusing the Approval Center's queue UI patterns.
3. Until then, the **activity feed + Approval Center** are the honest Job Center: governed jobs are visible; AI-token detail is labelled "not tracked yet."

## Why not fake it now
A Job Center showing invented durations/tokens/costs would be the worst kind of demo-ware — it would erode the trust the product is built on. The honest precursor (real events + real approval queue + "not tracked yet" for token detail) is shipped instead.
