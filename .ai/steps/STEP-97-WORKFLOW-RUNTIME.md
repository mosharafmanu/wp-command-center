# STEP 97 — Workflow Runtime

## Goal

Let AI agents define and run **multi-step execution plans** as a single unit —
one approval, a recorded timeline, failure recovery, and rollback awareness.

## Architecture

The `workflow_manage` operation already existed (CRUD + naive sequential
execute). STEP 97 hardens it: `WorkflowRuntimeManager::execute()` is rewritten
and a unified rollback dispatcher is added to `OperationExecutor`. No new
operation is registered (workflow steps reference existing operations by
`operation_id`).

A workflow is `{ id, name, description, steps: [ { operation_id, payload } ] }`,
stored in the `wpcc_workflows` option. Executions are recorded in
`wpcc_workflow_history` (capped at 200).

## What changed

### 1. Single approval (`within_workflow`)

Steps run through `OperationExecutor::run()` with `context['within_workflow'] =
true`. The executor's Security-Mode approval gate (STEP 80) now **skips** when
that flag is set: the whole plan was reviewed and approved as one unit via the
`workflow_execute` approval, so individual steps don't each pend in
Client/Enterprise mode. Defense in depth is preserved — the destructive-operation
guard (STEP 84) and token-capability checks **still run for every step**.

### 2. Execution timeline

Each step records `started_at`, `finished_at`, `duration_ms`, plus the execution
has its own `execution_id` and overall `started_at`/`finished_at`/`duration_ms`.

### 3. Rollback awareness

Each step captures the `rollback_id` its sub-operation returned (`rollbackable`
flag set accordingly). These are stored on the execution record so the plan can
be reversed later.

### 4. Failure recovery (`on_failure`)

`workflow_execute` accepts `on_failure`:

| Policy | Behavior on a failing step |
|--------|----------------------------|
| `stop` (default) | Halt; remaining steps marked `skipped`; status `failed`. |
| `continue` | Ignore the failure; keep running; status `failed`. |
| `rollback` | Auto-reverse all completed steps (latest first); status `rolled_back`; remaining `skipped`. |

A step "fails" when the executor reports `success !== true` **or** the
manager returned an in-band `{ error: true }` result.

### 5. Unified rollback dispatcher

`OperationExecutor::rollback( operation_id, { rollback_id }, context )` resolves
the operation's runtime manager (reusing `resolve_handler`) and calls its public
`rollback()`. Operations whose manager has no `rollback()` return
`wpcc_rollback_unsupported`. This is the cross-cutting piece that lets a workflow
reverse heterogeneous steps without knowing each manager.

### 6. `workflow_rollback` action

`workflow_manage { action: workflow_rollback, execution_id }` reverses a past
execution: every successful, rollbackable step is reversed (latest first) via the
dispatcher. One-shot (`already_rolled_back` guard); `nothing_to_rollback` when no
step produced a rollback_id.

## Operations (REST `/operations/workflow_manage/run`, MCP)

`workflow_list`, `workflow_get`, `workflow_create`, `workflow_update`,
`workflow_delete`, `workflow_execute`, `workflow_import`, `workflow_export`,
`workflow_history` (scopable by `workflow_id`), `workflow_rollback`. Risk: reads
diagnostic, mutations high. `workflow_create/update/delete/execute/import/
rollback` require approval.

## Structured error codes

`invalid`, `nf` (workflow not found), `missing_name`, `missing` /
`invalid_json` (import), `missing_execution_id`, `execution_not_found`,
`already_rolled_back`, `nothing_to_rollback`; dispatcher
`wpcc_rollback_unsupported`.

## Security / safety

- Single-approval skips **only** the approval gate, never the destructive guard
  or capability checks.
- `workflow_execute`/`workflow_rollback` are `high` risk → the plan gets one
  approval card in Client/Enterprise mode; the workflow definition is retrievable
  by id for the reviewer.
- All executions are audited (`workflow.*`, `operation.rollback.dispatched`).

## Tests

`tests/test-workflow-step97.sh` — **36/36 PASS**: timeline + rollback-awareness on
a happy path → `workflow_rollback` reverses all steps → `on_failure`
stop/continue/rollback → single-approval gate-skip (plain medium op pends in
Client mode, `within_workflow` does not) → MCP parity → scoped history →
structured errors. Pre-existing `test-workflow-runtime.sh` updated (9→10 actions),
**53/53**. Full bash regression: 0 net-new failures (24 pre-existing baseline).
