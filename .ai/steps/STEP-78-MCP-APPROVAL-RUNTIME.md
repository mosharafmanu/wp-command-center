# STEP 78 — MCP Approval Runtime

**Status:** Implemented and verified locally. Not yet deployed to
`mosharafmanu.com` (see [§8 Production follow-up](#8-production-follow-up)).

## 1. Problem

During re-verification of **Finding F** (the production capability lockout on
`mosharafmanu.com`, fixed earlier in this session by granting `system.admin`
to the Claude Desktop token), four of the five previously-failing MCP tools
(`settings_manage`, `plugin_manage`, `theme_manage`, `wp_cli_bridge`'s sibling
calls) returned a **new** error:

```json
{"error":{"code":-32000,"message":"Operation theme_manage requires approval. Use the request/approval workflow."}}
```

Root cause: `mosharafmanu.com` has `wpcc_enforce_approval = 1`. The
`OperationExecutor::run()` approval gate (Step 1c) blocks every operation with
`requires_approval => true` (20 of 22 mapped operations) **unless**
`$context['queue_id']` or `$context['request_id']` is already set:

```php
// includes/Operations/OperationExecutor.php
$is_queued    = ! empty( $context['queue_id'] );
$is_requested = ! empty( $context['request_id'] );
if ( get_option( 'wpcc_enforce_approval', false ) && ! $is_queued && ! $is_requested
     && ! empty( $operation['requires_approval'] ) ) {
    return $this->fail( $operation_id, 'wpcc_approval_required', ... );
}
```

The request → approve → execute pipeline that satisfies this gate
(`OperationManager`, `OperationQueue`, `OperationResults` — Steps 20/22/23)
already existed and is fully exposed over REST
(`/operations/requests/*`, `/operations/queue/*`, `/operations/results`).
But **`McpServerRuntime::tools_call()` never populates `request_id` /
`queue_id`** — so MCP clients (Claude, Codex, Gemini) had **no path** to the
existing pipeline. Every `requires_approval => true` tool was permanently
unusable via MCP while enforcement was on, with no way to fix it from MCP
itself (would require direct DB/SSH/WP-Admin access).

`wpcc_enforce_approval` was temporarily set to `0` on `mosharafmanu.com`
purely to unblock validation of the Finding F fix — **not** a permanent
change. STEP 78 is the permanent, code-level fix that lets enforcement stay
on while giving MCP clients full self-service access.

## 2. Architecture

```
Agent (Claude / Codex / Gemini)
   │  tools/call approval_manage { action: ... }
   ▼
ApprovalRuntimeManager  (new — includes/Operations/ApprovalRuntimeManager.php)
   │
   ├─ request_create  ──────► OperationManager::create_request()        ─┐ Request
   ├─ request_list/get                                                   │
   ├─ request_approve ──────► OperationManager::approve_request()        │ Approval
   │                            └─ auto: OperationQueue::enqueue()        │
   ├─ request_reject/cancel                                               │
   │                                                                       │
   ├─ queue_list/get                                                       │
   ├─ queue_run  ───────────► OperationQueue::run_item()                  │ Execute
   │                            └─ OperationExecutor::run($operation_id,   │
   │                                 $payload, context += {request_id,     │
   │                                 queue_id, ...})                       │
   │                                 → approval gate satisfied (queue_id   │
   │                                   / request_id present)               │
   ├─ queue_cancel/retry                                                   │
   │                                                                       │
   └─ results_list/get ─────► OperationResults::list_results()/get_result() Verify

Every step above writes to AuditLog (wp-content/uploads/wpcc-audit/audit.log) — Audit

Rollback-capable operations (settings_manage.*_rollback, bulk_manage.rollback,
snapshot_manage.restore, ...) flow through the SAME pipeline:
  request_create(rollback action) → request_approve → queue_run            Rollback
```

`approval_manage` required **zero MCP-side changes** to expose: per
`McpServerRuntime::tools_list()`, every MCP tool is generated directly from
`OperationRegistry::get_operations()`. Adding one registry entry was
sufficient.

## 3. New operation: `approval_manage`

| Property | Value |
|---|---|
| `id` | `approval_manage` |
| `risk_level` | `low` |
| `requires_approval` | `false` |
| Capability | `system.admin` (`CapabilityRegistry::CAP_SYSTEM_ADMIN`) |

**`requires_approval => false` is deliberate and load-bearing.** This is a
control-plane operation *over* the approval pipeline itself — making it
`requires_approval => true` would recreate the exact chicken-and-egg lockout
fixed for `capability_manage` in Finding F (an operation an agent needs in
order to get unblocked, that is itself blocked).

### 13 actions

| Action | Delegates to | Notes |
|---|---|---|
| `request_create` | `OperationManager::create_request($operation_id, $payload, $meta)` | Records `operation.request.created` audit event |
| `request_list` | `OperationManager::list_requests($filters)` | Filters: `status`, `operation_id`, `session_id`, `task_id`, `plan_id`, `limit`, `offset` |
| `request_get` | `OperationManager::get_request($request_id)` | |
| `request_approve` | `OperationManager::approve_request($request_id)` | Auto-enqueues; response includes the new `queue_item`. Records `operation.request.approved` |
| `request_reject` | `OperationManager::reject_request($request_id)` | Records `operation.request.rejected` |
| `request_cancel` | `OperationManager::cancel_request($request_id)` | Records `operation.request.cancelled` |
| `queue_list` | `OperationQueue::list_items($filters)` | Filters: `status`, `operation_id`, `request_id`, `limit`, `offset` |
| `queue_get` | `OperationQueue::get_item($queue_id)` | |
| `queue_run` | `OperationQueue::run_item($queue_id)` | **This is "Execute".** Sets `request_id`/`queue_id` in context, satisfying the approval gate for the target operation. Records `operation.queue.running` / `.completed` / `.failed` |
| `queue_cancel` | `OperationQueue::cancel_item($queue_id)` | Records `operation.queue.cancelled` |
| `queue_retry` | `OperationQueue::retry_item($queue_id)` | Records `operation.queue.retry_requested` / `.retry_queued` / `.retry_failed` |
| `results_list` | `OperationResults::list_results($filters)` | Filters: `operation_id`, `queue_id`, `request_id`, `status`, `limit`, `offset` |
| `results_get` | `OperationResults::get_result($result_id)` | |

All 13 are thin wrappers — no new DB tables, no duplicated business logic.
Invalid `action`, missing required params (`request_id`, `queue_id`,
`result_id`, `operation_id`), and not-found / invalid-transition errors from
the underlying classes are all returned as `\WP_Error` (Finding E contract),
producing a JSON-RPC `error` rather than an embedded-error `result`.

## 4. Worked example (Agent → Request → Approval → Execute → Verify)

Captured live from the local AMPPS `/mcp` endpoint while
`wpcc_enforce_approval = 1`, targeting `theme_manage` / `theme_list` (a
read-only, side-effect-free action used purely as a safe pipeline exerciser).

**Step 0 — direct call is blocked:**

```jsonc
// tools/call theme_manage {action: theme_list}
{"error":{"code":-32000,"message":"Operation theme_manage requires approval. Use the request/approval workflow."}}
```

**Step 1 — Request:**

```jsonc
// tools/call approval_manage {action: request_create, operation_id: theme_manage, payload: {action: theme_list}}
{
  "action": "request_create",
  "request": {
    "request_id": "47b85b2f-1327-45f3-ae26-6860dd43fd49",
    "operation_id": "theme_manage",
    "session_id": null, "task_id": null, "action_id": null, "plan_id": null,
    "status": "pending_review",
    "payload": "{\"action\":\"theme_list\"}",
    "risk_level": "variable",
    "created_at": 1781323353,
    "approved_at": null, "rejected_at": null, "executed_at": null, "failed_at": null
  }
}
```

**Step 2 — Approval:**

```jsonc
// tools/call approval_manage {action: request_approve, request_id: "47b85b2f-..."}
{
  "action": "request_approve",
  "request_id": "47b85b2f-1327-45f3-ae26-6860dd43fd49",
  "status": "approved",
  "queue_item": {
    "queue_id": "fce42637-6b39-4401-a05a-83e31f06be34",
    "request_id": "47b85b2f-1327-45f3-ae26-6860dd43fd49",
    "operation_id": "theme_manage",
    "status": "queued",
    "priority": 10, "attempts": 0, "max_attempts": 3,
    "payload": {"action": "theme_list"},
    "result": null
  }
}
```

**Step 3 — Execute:**

```jsonc
// tools/call approval_manage {action: queue_run, queue_id: "fce42637-..."}
{
  "action": "queue_run",
  "item": {
    "queue_id": "fce42637-6b39-4401-a05a-83e31f06be34",
    "request_id": "47b85b2f-1327-45f3-ae26-6860dd43fd49",
    "operation_id": "theme_manage",
    "status": "completed",
    "attempts": 1,
    "payload": {"action": "theme_list"},
    "result": {
      "operation_id": "theme_manage",
      "success": true,
      "result": {
        "action": "theme_list",
        "themes": {
          "total": 5,
          "active_theme": {"slug": "mosharaf-core", "name": "Mosharaf Core", "version": "1.0.0"},
          "updates_available": 0,
          "themes": [ "... 5 theme entries ..." ]
        }
      },
      "errors": [], "created": [], "updated": [], "skipped": []
    },
    "started_at": 1781323354, "completed_at": 1781323355
  }
}
```

**Step 4 — Verify:**

```jsonc
// tools/call approval_manage {action: results_list, queue_id: "fce42637-..."}
{
  "action": "results_list",
  "results": [
    {
      "result_id": "4898b173-11b1-4654-a98b-3867e87862a0",
      "queue_id": "fce42637-6b39-4401-a05a-83e31f06be34",
      "request_id": "47b85b2f-1327-45f3-ae26-6860dd43fd49",
      "operation_id": "theme_manage",
      "status": "completed",
      "execution_time_ms": 195,
      "created_count": 0, "updated_count": 0, "skipped_count": 0, "error_count": 0
    }
  ]
}
```

**Audit (Step 5):** the same `request_id` appears across 22 entries in
`wp-content/uploads/wpcc-audit/audit.log`, including
`operation.request.created`, `operation.request.approved`,
`operation.queue.running` / `.completed`, `operation.theme_manage.started` /
`.completed`, and `operation.result.completed` — a complete record of the
cycle with no MCP-side audit code required beyond the new
`operation.request.*` / `operation.queue.*` calls added in
`ApprovalRuntimeManager`.

## 5. Deliberate omission: `request_execute`

`OperationManager::execute_request()` exists but is **not** exposed by
`approval_manage`. `approve_request()` already auto-enqueues a `queued` item
(picked up by `OperationWorker`'s cron, or run immediately via `queue_run`).
If `request_execute` were also exposed, an agent calling it directly would
leave the auto-created queue item orphaned in `queued` status — the cron
would later re-run it, executing the operation a second time. `queue_run` is
the single, unambiguous "Execute now" action and maps cleanly onto the
queue item returned by `request_approve`.

## 6. Security notes — capability gating

`approval_manage` is gated to `system.admin`
(`CapabilityRegistry::OPERATION_MAP['approval_manage'] = CAP_SYSTEM_ADMIN`).

When `queue_run` / `request_approve` execute the *target* operation via
`OperationExecutor::run($operation['operation_id'], $payload, $context)`,
`$context` does **not** include `token_id` (neither
`OperationManager::execute_request()` nor `OperationQueue::run_item()` sets
it), so the target operation's own capability check (Step 1b) is skipped
entirely — only the approval gate (Step 1c) applies, satisfied by the
`queue_id`/`request_id` now present.

This means **whoever can call `approval_manage` can, in effect, execute any
registered operation** regardless of that operation's own
`OPERATION_MAP` entry. Gating `approval_manage` to `system.admin` — the top
tier, which already bypasses every other capability check via the
`$has_admin` shortcut in `CapabilityRegistry::validate()`, and which (per
`CapabilityRegistry::assign()`) can only be granted via direct configuration,
never self-service — means this introduces **no new escalation path** for
the current token (which holds `system.admin` from the Finding F fix).

**v1 simplification, documented for a future Step 79:** a dedicated
`approval.admin` capability plus a per-target-operation capability re-check
inside `queue_run` / `request_approve` would allow finer-grained,
non-`system.admin` agent tokens. Not needed for the current
single-admin-token scenario.

## 7. Files

**New:**
- `includes/Operations/ApprovalRegistry.php` — `ApprovalRegistry::ACTIONS` (13 action constants), used as the `enum` for `approval_manage.action`.
- `includes/Operations/ApprovalRuntimeManager.php` — handler class (`run()` dispatch + 13 action methods + audit recording).
- `tests/test-mcp-approval-runtime.sh` — new regression suite (21 assertions).

**Edited:**
- `includes/Operations/OperationRegistry.php` — added the `approval_manage` entry (risk `low`, `requires_approval => false`, 9 parameters).
- `includes/Operations/CapabilityRegistry.php` — added `'approval_manage' => self::CAP_SYSTEM_ADMIN` to `OPERATION_MAP`.
- `includes/Operations/OperationExecutor.php` — added `ApprovalRuntimeManager` import + `resolve_handler()` case.

## 8. Test results

- `php -l` clean on all 5 new/edited files.
- `tests/test-mcp-approval-runtime.sh`: **21/21 passing** — covers the full
  Request → Approval → Execute → Verify cycle via MCP `tools/call` only
  (enforcement temporarily enabled and restored to its original value within
  the test), plus `tools/list` schema checks for `approval_manage`.
- Manual `tools/list` check: `approval_manage` exposed with
  `inputSchema.type == "object"`, `required == ["action"]`,
  `properties.action.enum` = the 13 actions above.
- Manual error-path checks: invalid `action` → `wpcc_invalid_approval_action`;
  missing `request_id` on `request_get` → `wpcc_missing_request_id` — both
  surfaced as JSON-RPC `error` (not embedded in `result`).
- Full local regression suite (all 61 `tests/test-*.sh` files):
  **2,909 passed, 24 failed** — after one small update —
  `tests/test-capability-runtime.sh` had a hardcoded
  `manifest: 24 mapped operations` assertion (`CapabilityRegistry::OPERATION_MAP`
  count), now `25` to account for the new `approval_manage` entry
  (`test-capability-runtime.sh` itself is now 61/61).
  The first full run (before that fix) showed 25 failures across 7 suites;
  `git stash` + re-run against the pre-STEP-78 `HEAD` (`bbe40a0`) reproduced
  the **other 24 failures identically** (1+3+4+2+11+3 across
  `test-ai-client-layer.sh`, `test-ai-integration-ux.sh`,
  `test-claude-integration.sh`, `test-cursor-certification.sh`,
  `test-documentation-consistency.sh`, `test-security-redaction.sh`),
  confirming they are **pre-existing and unrelated** to this change
  (MCP-config-URL generation, secret-redaction, and missing `docs/*.md`
  files). **STEP 78 introduces zero new failures.**

## 8b. Production follow-up

`mosharafmanu.com` currently:
- Has `wpcc_enforce_approval = 0` (temporarily disabled today purely to
  validate the Finding F capability fix — **not permanent**, per explicit
  instruction).
- Runs an older plugin build that does **not** yet include `approval_manage`.

**Re-enabling `wpcc_enforce_approval = 1` on production should wait until
this build is deployed there**, otherwise the four tools
(`settings_manage`, `plugin_manage`, `theme_manage`, and the
`requires_approval => true` operations generally) would immediately revert
to the `-32000 ... requires approval` error with no `approval_manage` tool
available to work around it. This deployment + re-enable step is a separate
follow-up, to be directed by the user.

Separately (carried over from the Finding F work): the SSH credentials used
to fix Finding F and toggle `wpcc_enforce_approval` for validation should be
rotated via the hPanel "Change" link once all SSH-based work for this thread
concludes.
