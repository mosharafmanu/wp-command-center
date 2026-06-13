# STEP 78 — User Flow: MCP Approval Runtime

This document describes the **end-to-end operational view** of STEP 78 for a
site running with `wpcc_enforce_approval = 1` (the recommended/default
production posture). It complements
[`STEP-78-MCP-APPROVAL-RUNTIME.md`](STEP-78-MCP-APPROVAL-RUNTIME.md), which
covers the internal architecture, the 13 `approval_manage` actions, and the
captured request/response JSON.

Everything here is written from the perspective of someone with the plugin
open in WP Admin in one tab and Claude (or another MCP client) connected in
another.

---

## 1. What Claude does

When Claude calls a tool whose underlying operation is marked
`requires_approval => true` (e.g. `theme_manage`, `plugin_manage`,
`settings_manage`, `wp_cli_bridge`, `user_manage`, ...) **directly**, the call
is blocked by the approval gate — even for read-only actions like
`theme_list`:

```jsonc
// tools/call theme_manage {action: theme_list}
{"error":{"code":-32000,"message":"Operation theme_manage requires approval. Use the request/approval workflow."}}
```

Claude then drives the **same `approval_manage` tool** through four steps,
with no DB, SSH, or WP-Admin access:

1. **Request** — `approval_manage { action: request_create, operation_id: "theme_manage", payload: {action: "theme_list"} }`
   → returns a `request` object, `status: "pending_review"`, with a
   `request_id`.
2. **Approve** — `approval_manage { action: request_approve, request_id: "<id>" }`
   → transitions the request to `status: "approved"` and **auto-creates a
   queue item** (`status: "queued"`), returned inline as `queue_item`.
3. **Execute** — `approval_manage { action: queue_run, queue_id: "<id>" }`
   → runs the original operation (`theme_manage` / `theme_list`) with
   `request_id`/`queue_id` in context, which satisfies the approval gate.
   Returns the queue item with `status: "completed"` and the operation's
   normal result embedded under `item.result`.
4. **Verify** — `approval_manage { action: results_list, queue_id: "<id>" }`
   → confirms an `OperationResults` row exists with `status: "completed"`.

Every one of these calls is a single MCP `tools/call approval_manage`
invocation — Claude does not need a second tool, a different token, or any
manual step in between. `approval_manage` itself is `requires_approval =>
false`, so steps 1–4 are never themselves blocked by the gate they exist to
satisfy.

---

## 2. What user sees

In the chat transcript, the user sees Claude narrate each step in plain
language, e.g.:

> "Listing themes directly is blocked because approval enforcement is on for
> this site. I'll request approval, approve it, run it, and verify the
> result."
> *(request created → approved → executed → verified)*
> "Done — here are the 5 installed themes: ..."

Because the connected token holds `system.admin` (the capability
`approval_manage` requires), **the whole cycle is self-service and typically
completes in under a second** — the user does not need to click anything in
WP Admin for it to work. The user is not blocked waiting on a human approver;
Claude *is* the approver, acting under the authority already granted to its
token.

If the user has the WP Admin **Dashboard** open at the same time, they will
see the request and queue item appear and disappear from the "Pending
Operation Requests" / "Queued Operations" panels in near-real-time (on the
next page load), and the completed result appear under "Recent Operation
Results" and in the "Recent Agent Activity" timeline — giving the user a live,
independent view of exactly what Claude just did, with no extra effort on
Claude's part (every step is already written to `OperationResults` and the
audit log).

---

## 3. What appears in WP Admin

Menu: **Command Center** (top-level menu, `dashicons-admin-generic`) with
sub-pages **Dashboard**, **Site Intelligence**, **Diagnostics**, **File
Access**, **Patches**, **Rollback**, **Settings**, **AI Integrations**.

On the **Dashboard** page (`wp-command-center`):

| Panel | What it shows | Actions |
|---|---|---|
| **Pending Operation Requests** | Table: *Operation*, *Risk*, *Actions*. One row per `pending_review` request (up to 5). | **Approve** / **Reject** buttons (submit `wpcc_action=approve_request` / `reject_request`) |
| **Queued Operations** | Table: *Operation*, *Status*, *Actions*. One row per `queued` queue item (up to 5). | **Run Manually** button (submit `wpcc_action=run_queue`) |
| **Recent Operation Results** | Table: *Operation*, *Status*, *Result* (link to view JSON). | View result |
| Stat cards | "Queued Ops", "Failed Queue Items" (counts only — see [§7](#7-recovery-cases)) | — |
| **Recent Agent Activity** | Timeline of audit events (`operation.request.*`, `operation.queue.*`, `operation.<tool>.*`, `operation.result.*`, ...), filterable by type/status | — |

If a request/queue item is empty, the panel shows an empty-state message
("No operation requests are awaiting review." / "The operation queue is
empty.") instead of an empty table.

On the **Settings** page (`wpcc-settings`):

- A checkbox: *"Require approval for operations marked 'requires approval'
  before they can be executed."* — this is `wpcc_enforce_approval`.
- Explanatory copy: *"A subset of operations (plugin/theme management,
  settings changes, user management, database writes, and similar
  higher-risk actions) are marked 'requires approval' in the operation
  catalog."*
- Toggling it ON shows: *"Approval enforcement enabled. Operations marked
  'requires approval' will now be blocked unless requested through the
  request → approve → execute workflow."*
- Toggling it OFF shows: *"Approval enforcement disabled. Operations marked
  'requires approval' will execute immediately when called directly."*

On the **Rollback** page (`wpcc-rollback`) — see [§8](#8-rollback-flow).

---

## 4. What must be approved

An operation is gated **only if its `OperationRegistry` entry has
`requires_approval => true`** — this covers plugin/theme management, settings
changes, user management, database writes, bulk operations, search &
replace, and similar higher-risk operations.

Two important details:

- **The gate applies to the whole operation, not individual actions.** A
  `requires_approval => true` operation is blocked for *every* `action`
  value, including read-only ones (the worked example in
  `STEP-78-MCP-APPROVAL-RUNTIME.md` uses `theme_manage` / `theme_list` — a
  pure read — purely as a safe pipeline exerciser, and it is still blocked
  without going through the request/approval cycle).
- **`approval_manage` is deliberately exempt** (`requires_approval => false`).
  This is the one operation that is *always* directly callable — it is the
  control plane *for* the gate, and exempting it is what makes the gate
  escapable via MCP at all.

The agent-facing "Pending Plans" workflow (`wpcc_agent_plans`,
`approve_plan` / `reject_plan` on the Dashboard) is a separate, higher-level
gate for multi-step agent *plans* and is unaffected by STEP 78 — it sits in
front of the per-operation approval gate described here.

---

## 5. What executes automatically

**Nothing executes just from `request_create` or `request_approve`.**
Approval only changes a request's status and creates a `queued` queue item —
it does not run the operation.

Actual execution happens in exactly two ways:

1. **Manually / on-demand** — `approval_manage { action: queue_run, ... }`
   (Claude, via MCP) or the **"Run Manually"** button (human, via WP Admin).
   Both call the same `OperationQueue::run_item()` and execute immediately.
2. **Automatically via cron** — `OperationWorker::handle_cron()` runs on the
   `wpcc_process_operation_queue` hook, scheduled every 5 minutes
   (`wpcc_five_minutes`). Each run picks up to **5** items with
   `status: queued` and calls `run_item()` on each.

This means: **once a request reaches `approved` status — whether approved by
Claude (`request_approve`) or by a human clicking "Approve" in WP Admin — its
queue item will execute within 5 minutes even if no one ever calls
`queue_run` / "Run Manually."** A 5-minute transient lock per `queue_id`
prevents the cron and a manual run from executing the same item twice.

---

## 6. Failure cases

Failures occur at different layers and surface differently:

| Layer | When | Error |
|---|---|---|
| **Read-only scope** | Token has `scope: read_only` and the tool isn't in the read-only allow-list | `-32001 This API token is read-only and cannot perform this action.` |
| **Capability gate** (Step 1b, before the operation runs) | `wpcc_enforce_capabilities = 1` and the token lacks the capability mapped to the tool (e.g. `approval_manage` requires `system.admin`) | `-32001 Operation denied: missing capability <cap>` |
| **Approval gate** (Step 1c, inside `OperationExecutor::run()`) | `wpcc_enforce_approval = 1`, operation has `requires_approval => true`, and neither `request_id` nor `queue_id` is in context | `-32000 Operation <id> requires approval. Use the request/approval workflow.` |
| **`approval_manage` validation** | Bad input to `approval_manage` itself — all returned as a JSON-RPC `error`, never embedded in `result` | `wpcc_invalid_approval_action`, `wpcc_missing_operation_id`, `wpcc_missing_request_id`, `wpcc_missing_queue_id`, `wpcc_missing_result_id`, `wpcc_request_not_found`, `wpcc_queue_item_not_found`, `wpcc_result_not_found` |
| **Invalid state transition** | e.g. approving an already-`approved`/`rejected` request, or `queue_run` on an item that is `running`/`completed`/`cancelled` | `wpcc_invalid_transition`, `wpcc_invalid_queue_status` |
| **Cancel/retry guards** | `queue_cancel` on a non-`queued`/`failed` item; `queue_retry` on a non-`failed` item; retry after `attempts >= max_attempts` (3) | `wpcc_cannot_cancel`, `wpcc_cannot_retry`, `wpcc_max_attempts_reached` |
| **Target operation execution failure** | The underlying operation itself errors during `queue_run` (e.g. a plugin install fails) | **Not** a JSON-RPC error — `queue_run` succeeds and returns `item.status: "failed"` with `item.result.errors` populated; a matching `OperationResults` row is written with `status: "failed"`. This is the entry point to [§7 Recovery](#7-recovery-cases). |

Capability checks happen **before** the approval gate, so a token missing
`system.admin` will get `-32001 missing capability system.admin` on its very
first `approval_manage` call and never reach the approval-gate logic at all.

---

## 7. Recovery cases

- **Failed queue item, attempts remaining** — `approval_manage { action:
  queue_retry, queue_id: "<id>" }` moves the item from `status: failed` back
  to `status: queued` (audit: `operation.queue.retry_requested` →
  `operation.queue.retry_queued`). It will then run again via `queue_run` or
  the next cron pass.
- **Failed queue item, max attempts (3) reached** — `queue_retry` returns
  `wpcc_max_attempts_reached` (audit: `operation.queue.retry_failed`). There
  is no further retry path for that queue item; recovery means creating a
  **new** request (`request_create` → `request_approve` → `queue_run`) for
  the same operation.
- **Unwanted pending request** — `approval_manage { action: request_reject,
  ... }` (→ `status: rejected`, no queue item ever created) or `request_cancel`
  (→ `status: cancelled`). The human-equivalent of `request_reject` is the
  **"Reject"** button in the "Pending Operation Requests" panel.
- **Unwanted queued item (not yet run)** — `approval_manage { action:
  queue_cancel, ... }` (→ `status: cancelled`). There is no WP Admin button
  for this; it is MCP/REST-only.
- **WP Admin asymmetry** — the Dashboard's "Failed Queue Items" stat card
  shows a **count only**; failed items do not get their own panel or a
  Retry/"Run Manually" button (the "Queued Operations" panel only lists
  `status: queued` items). Retrying a failed item currently requires
  `approval_manage { action: queue_retry, ... }` via MCP (or the equivalent
  REST call) — there is no purely-WP-Admin recovery path for a failed item
  today.

---

## 8. Rollback flow

There are **two independent rollback mechanisms**:

### A. Patch Engine rollback (file-level, WP Admin only)

The **Rollback** page (`wpcc-rollback`) lists every applied/rolled-back/failed
patch. Each `applied` patch has a **"Restore"** button
(`wpcc_action=restore_patch` → `PatchApproval::rollback()`), which
immediately reverts the affected file(s) to their pre-patch contents. This is
a direct, admin-only (`manage_options`) action — it does **not** go through
`OperationManager`/`OperationQueue` and is **not** affected by
`wpcc_enforce_approval`. It is the mechanism described in memory as "Rollback
is a consequence of patches" — every applied patch is automatically
snapshotted, and Restore is just applying that snapshot in reverse.

### B. Operation-level rollback/restore actions (same pipeline as everything else)

Some operations expose their own rollback/restore *actions*
(e.g. `settings_manage`'s `*_rollback` actions, `bulk_manage.rollback`,
`snapshot_manage.restore`). Because these operations are themselves marked
`requires_approval => true`, a rollback/restore action is gated **exactly
like any other gated operation** — it flows through the identical
`approval_manage` cycle:

```
request_create { operation_id: "settings_manage", payload: { action: "<...>_rollback", ... } }
  → request_approve   (auto-enqueues)
  → queue_run          (executes the rollback)
  → results_get/list   (verify status: completed)
```

There is no separate "rollback API" for these — from `approval_manage`'s
point of view, a rollback request is just a request whose `payload.action`
happens to be a rollback/restore action. The same Failure/Recovery rules in
§6/§7 apply (e.g. a failed rollback queue item can be retried with
`queue_retry`).

### Audit trail (both paths)

Every step of both rollback paths — Patch Engine restore and
operation-level rollback via `approval_manage` — is written to
`wp-content/uploads/wpcc-audit/audit.log`, giving a complete, append-only
record of what was rolled back, by whom (or by which agent/token), and when.
