# STEP 80 — Product Security Modes & Client Approval Experience

**Status:** Design complete. Implementation split into STEP 80A (foundation),
STEP 80B (approval UX overhaul), STEP 80C (admin UI).

---

## 1. Strategic Context

The current plugin has binary enforcement flags:
`wpcc_enforce_approval` (on/off) and `wpcc_enforce_capabilities` (on/off).
These are developer-facing options with no clear client story. They do not
communicate **why** the gate exists, **who** should approve, or **what risk
level** a given operation carries.

STEP 80 replaces those flags with a first-class product concept: **Security
Mode**. A single `wpcc_security_mode` option drives a coherent, named bundle
of enforcement behaviors that clients and agencies can reason about without
reading documentation.

**Commercial goal:** Make WP Command Center a commercially viable AI
Operations Platform — not merely a working MCP server. A client who sees
"Client Mode — AI requires your approval for all changes" understands the
product immediately. A client who sees "Approval Enforcement: 1" does not.

---

## 2. Three Security Modes

### Mode 1 — Developer Mode

**Target:** Developers, agencies, staging environments, personal sites.

**Promise:** Maximum AI productivity. Every operation executes immediately
after the capability check.

**Enforcement bundle:**
| Setting | Value |
|---|---|
| Approval gate | OFF — all operations execute immediately |
| Capability enforcement | ON — token scope and assignment still checked |
| Audit logging | ON — cannot be disabled |
| Rollback index | ON — every write records a patch entry |
| Capabilities whitelist | Full token scope applies |

**Protections that remain in Developer Mode (answer to the design question):**
1. **Token capability gate** — a read-only token cannot call write operations;
   a full-access token still requires `system.admin` assignment. Scope
   enforcement never relaxes.
2. **Audit logging** — every operation is logged to `wpcc-audit/audit.log`.
   Developers still want a trail when something breaks on staging.
3. **Rollback availability** — the patch system records diffs so any operation
   can be reversed. Developer Mode does not *require* rollback confirmation
   before execution, but the rollback index is always built.
4. **`safe_search_replace` dry-run preview** — CRITICAL-risk operations that
   produce a diff preview (like global search/replace) should still require a
   confirmation step (structured: return `{dry_run: true, preview: [...]}` on
   first call; execute on second call with `confirm: true`). This is a
   per-operation safeguard, not an approval gate.

---

### Mode 2 — Client Mode

**Target:** Freelance clients, small businesses, agency-managed production
sites.

**Promise:** "AI can look at anything and propose anything, but it cannot
change your site without your approval."

**Enforcement bundle:**
| Setting | Value |
|---|---|
| Approval gate | ON — MEDIUM, HIGH, CRITICAL operations require approval |
| Read operations | Execute immediately (LOW / DIAGNOSTIC) |
| Capability enforcement | ON |
| Audit logging | ON |
| Rollback index | ON |
| Approver | WordPress admin user via WP Admin UI |
| AI can self-approve | NO — approval requires a human WP_User actor |

**What this feels like from the AI side:**
- AI calls `plugin_manage {action: deactivate}` → receives a structured
  `pending_approval` response (not a `-32000` error) with a `request_id`,
  risk level, rollback flag, and an explanation string.
- AI says to the user: "I've submitted a request to deactivate WooCommerce.
  Please approve it in your WordPress admin — I'll resume once it's approved."
- AI can poll `approval_manage {action: request_get}` to check status.
- After the client approves in WP Admin, the queue item auto-runs
  (no separate `queue_run` call needed from AI).
- AI eventually polls and sees `status: completed` → gets the result.

**What this feels like from the client side:**
- WP Admin shows a persistent notification: "1 pending AI request"
- Clicking it shows the approval card (see §6).
- [Approve] → operation runs immediately. [Reject] → request closed, AI
  notified. [View Details] → full context, diff preview, audit log link.
- No CLI, no terminal, no SSH.

---

### Mode 3 — Enterprise Mode

**Target:** Teams, agencies, compliance-sensitive organizations.

**Promise:** Full audit trail, multi-step approval for all changes, permanent
rollback record, designed to grow into multi-approver and RBAC without
architecture changes.

**Enforcement bundle:**
| Setting | Value |
|---|---|
| Approval gate | ON — ALL non-read operations require approval |
| Read operations | Execute immediately (DIAGNOSTIC only) |
| Capability enforcement | ON (strict) |
| Audit logging | ON — immutable, append-only, cannot be disabled |
| Rollback index | ON — required before HIGH/CRITICAL ops |
| Approver | WP admin user (now); future: named approver role |
| AI can self-approve | NO |
| Auto-run after approval | Optional (can require separate `queue_run`) |

**Extensibility designed now for future growth (no architecture changes needed
later):**
1. **Approval record has an `approver_id` field** — currently populated by the
   approving WP_User's ID. Future: matched against an RBAC role table.
2. **`required_approvals` field on requests** — currently hardcoded to `1`.
   Future: set to `2` or `3` for multi-approver workflows.
3. **`policy_id` field** — currently `null`. Future: a `wpcc_approval_policies`
   table maps `{operation_id, risk_level, min_approvals, timeout_hours,
   auto_reject_after}` rows. Enterprise Mode activates the policy engine;
   the columns exist from day one.
4. **`team_id` / `site_group_id`** — reserved on the request record for future
   multi-site agency dashboards.
5. **Immutable audit log** — `audit.log` is already append-only JSONL. Wrap
   it in a `readonly` filesystem flag or a signed-hash chain in a later step.

---

## 3. Security Mode Architecture

### 3.1 New option: `wpcc_security_mode`

```
'developer'  — Mode 1 (default for fresh installs)
'client'     — Mode 2
'enterprise' — Mode 3
```

This option replaces (but coexists with for backwards compat) the existing
`wpcc_enforce_approval` flag. On the first save of `wpcc_security_mode`, the
old flags are derived from it and kept in sync so any existing code reading
`get_option('wpcc_enforce_approval')` continues to work during the migration.

### 3.2 New class: `SecurityModeManager`

`includes/Operations/SecurityModeManager.php`

```php
final class SecurityModeManager {
    const MODE_DEVELOPER  = 'developer';
    const MODE_CLIENT     = 'client';
    const MODE_ENTERPRISE = 'enterprise';
    const MODES           = [ self::MODE_DEVELOPER, self::MODE_CLIENT, self::MODE_ENTERPRISE ];
    const DEFAULT_MODE    = self::MODE_DEVELOPER;

    public static function current(): string {
        $mode = get_option( 'wpcc_security_mode', self::DEFAULT_MODE );
        return in_array( $mode, self::MODES, true ) ? $mode : self::DEFAULT_MODE;
    }

    // Returns true if an approval request should be created instead of
    // executing immediately for the given operation + risk level.
    public static function requires_approval( string $op_id, string $risk_level ): bool {
        return match ( self::current() ) {
            self::MODE_DEVELOPER  => false,
            self::MODE_CLIENT     => in_array( $risk_level, [ 'medium', 'high', 'critical', 'variable' ], true ),
            self::MODE_ENTERPRISE => $risk_level !== 'diagnostic',
        };
    }

    // Returns true if the approval can be granted by an AI agent token.
    // Enterprise and Client modes require a human WP_User actor.
    public static function requires_human_approver(): bool {
        return self::current() !== self::MODE_DEVELOPER;
    }
}
```

### 3.3 Execution path with modes

```
tools/call <op> {args}
  │
  ├─ Capability check (ALL modes) — return -32001 if missing
  │
  ├─ Scope check (ALL modes) — return -32001 if read-only token + write op
  │
  ├─ SecurityModeManager::requires_approval($op_id, $risk_level)?
  │    │
  │    ├─ NO (Developer Mode, or low/diagnostic op) → execute immediately
  │    │
  │    └─ YES → OperationExecutor auto-creates approval request
  │               returns: { status: "pending_approval",
  │                          request_id: "...",
  │                          operation: "...",
  │                          risk_level: "high",
  │                          rollback_available: true,
  │                          message: "Approval required. Use approval_manage
  │                                    {action:request_get, request_id:...}
  │                                    to poll status." }
  │
  └─ Human approves in WP Admin → auto queue_run → result stored
     AI polls request_get → sees status: completed → calls results_get
```

---

## 4. Risk Classification Matrix

All 29 operations, with recommended risk tier assignments and per-mode
approval behavior. Risk levels with `*` are currently labeled `variable` in
the registry and should be refined to specific tiers.

### Legend
- **DIAG** — Diagnostic/read-only, never gated in any mode
- **LOW** — Gated only in Enterprise Mode
- **MED** — Gated in Client + Enterprise Modes
- **HIGH** — Gated in Client + Enterprise Modes
- **CRIT** — Gated in Client + Enterprise Modes; dry-run preview in Developer

| Operation | Title | Risk Tier | Dev | Client | Enterprise | Notes |
|---|---|---|---|---|---|---|
| `database_inspect` | Database Inspection | DIAG | ✓ | ✓ | ✓ | Read-only queries only |
| `search_manage` | Search & Reports | DIAG | ✓ | ✓ | ✓ | Read-only search |
| `approval_manage` | Approval Runtime | DIAG | ✓ | ✓ | ✓ | System tool, always available |
| `cf7_seed` | CF7 Seeding | LOW | ✓ | ✓ | Gate | Form template creation |
| `content_seed` | Content Seeding | MED | ✓ | Gate | Gate | Post/page bulk creation |
| `acf_seed` | ACF Field Seeding | MED | ✓ | Gate | Gate | Field group config |
| `woo_product_seed` | WooCommerce Seeder | MED | ✓ | Gate | Gate | Product bulk creation |
| `media_import` | Media Import | MED | ✓ | Gate | Gate | External media fetch |
| `media_manage` | Media Management | MED | ✓ | Gate | Gate | Library edits |
| `content_manage` | Content Management | MED | ✓ | Gate | Gate | Post/page edits |
| `comments_manage` | Comments Management | MED | ✓ | Gate | Gate | Moderation actions |
| `forms_manage` | Forms Management | MED | ✓ | Gate | Gate | Form config |
| `menu_manage` | Menu Management | MED | ✓ | Gate | Gate | Navigation changes |
| `widgets_manage` | Widgets & Sidebars | MED | ✓ | Gate | Gate | Sidebar config |
| `woocommerce_manage` | WooCommerce Management | MED | ✓ | Gate | Gate | Store config |
| `acf_manage` | ACF Management | MED | ✓ | Gate | Gate | Field group edits |
| `cpt_manage` | Custom Post Types | MED | ✓ | Gate | Gate | CPT registration |
| `snapshot_manage` | Snapshot Management | MED/HIGH | ✓ | Gate | Gate | Write actions gated; reads free |
| `plugin_manage` | Plugin Management | HIGH | ✓ | Gate | Gate | Install/activate/deactivate |
| `theme_manage` | Theme Management | HIGH | ✓ | Gate | Gate | Activation/edits |
| `safe_updates` | Safe WP Updates | HIGH | ✓ | Gate | Gate | Core + plugin updates |
| `settings_manage` | Site Settings | HIGH | ✓ | Gate | Gate | General/reading/writing settings |
| `option_manage` | Option Management | HIGH | ✓ | Gate | Gate | wp_options writes |
| `user_manage` | User Management | HIGH | ✓ | Gate | Gate | Create/edit/delete users |
| `bulk_manage` | Bulk Operations | HIGH | ✓ | Gate | Gate | Multi-item writes |
| `workflow_manage` | Workflow Runtime | HIGH | ✓ | Gate | Gate | Chained executions |
| `capability_manage` | Capability Management | CRIT | ✓* | Gate | Gate | *dry-run preview in Dev |
| `safe_search_replace` | Safe Search & Replace | CRIT | ✓* | Gate | Gate | *dry-run preview in Dev |
| `wp_cli_bridge` | WP-CLI Bridge | CRIT | ✓* | Gate | Gate | *command allowlist in Dev |

### Risk tier by action within `variable` operations

Many operations are labeled `variable` because they have both read and write
sub-actions. The per-action risk should be encoded in `OperationRegistry` as
`'action_risks'` map:

**Example — `plugin_manage`:**
```php
'action_risks' => [
    'plugin_list'        => 'diagnostic',
    'plugin_get'         => 'diagnostic',
    'plugin_install'     => 'high',
    'plugin_activate'    => 'high',
    'plugin_deactivate'  => 'high',
    'plugin_delete'      => 'critical',
    'plugin_update'      => 'high',
]
```

`SecurityModeManager::requires_approval()` should resolve the per-action risk
when available, falling back to the operation-level `risk_level`. This allows
`plugin_manage {action: plugin_list}` to execute immediately in Client Mode
while `plugin_manage {action: plugin_deactivate}` triggers the approval gate.

---

## 5. Approval Workflow Redesign

### 5.1 Analysis of the current STEP 78 workflow

```
Current (problematic):
tools/call plugin_manage {action: deactivate}
  → -32000 error "Operation requires approval"
  → AI: call approval_manage {action: request_create}
  → AI: call approval_manage {action: request_approve}  ← AI APPROVES ITSELF
  → AI: call approval_manage {action: queue_run}
  → result
```

**Three problems:**

1. **AI can self-approve.** `request_approve` is available to any
   `system.admin` token, including the very AI agent that created the request.
   In Client Mode, this makes the approval gate meaningless — the AI can
   always approve its own requests. This is the most serious design flaw.

2. **Friction is in the wrong place.** The AI receives a `-32000` *error*
   before it even knows approval is needed. This breaks AI continuity: the
   model must catch the error, understand it means "approval required",
   construct an entirely separate tool call, and then re-sequence everything.
   The error is also invisible to the client — there is no notification until
   the AI explicitly calls `request_create`.

3. **`queue_run` is an AI-callable trigger.** If the AI can call `queue_run`,
   it can bypass the wait entirely (create → approve → run, all in one turn).
   For Client and Enterprise Mode, only WP Admin should trigger execution.

### 5.2 Recommended architecture

**Key change 1 — Auto-create on block (no `-32000` error):**

`OperationExecutor::run()` detects that `SecurityModeManager::requires_approval()`
returns `true` and, instead of returning a failure, automatically creates an
approval request via `OperationManager::create_request()` and returns a
structured `pending_approval` result:

```json
{
  "status": "pending_approval",
  "request_id": "req_abc123",
  "operation": "plugin_manage",
  "action": "plugin_deactivate",
  "risk_level": "high",
  "rollback_available": true,
  "approval_url": "https://example.com/wp-admin/admin.php?page=wpcc-approvals&id=req_abc123",
  "message": "This operation requires client approval. Direct the client to approve in WordPress admin, then use approval_manage {action: request_get, request_id: \"req_abc123\"} to poll for completion."
}
```

The AI receives a *success result* (not an error) with a clear next action.
It can immediately communicate this to the user in a natural way.

**Key change 2 — Restrict `request_approve` and `queue_run` to human actors:**

In Client and Enterprise modes, `ApprovalRuntimeManager::request_approve()`
should verify that the calling actor is a WP_User with `manage_options`
capability, not just any valid API token. Token-authenticated requests should
receive a `wpcc_approval_requires_human` error.

```php
// In ApprovalRuntimeManager::request_approve()
if ( SecurityModeManager::requires_human_approver() ) {
    $actor = $cx['actor'] ?? [];
    if ( empty( $actor['wp_user_id'] ) ) {
        return new \WP_Error(
            'wpcc_approval_requires_human',
            __( 'Approvals must be granted by a WordPress administrator in Client and Enterprise modes.', 'wp-command-center' )
        );
    }
}
```

`queue_run` gets the same guard: in Client/Enterprise Mode, only WP_User
actors (i.e. WP Admin UI interactions via the REST endpoint, not MCP token
calls) can trigger execution.

**Key change 3 — Auto-run after approval (WP Admin flow):**

When the client clicks [Approve] in WP Admin:
1. WP Admin REST call: `POST /wp-json/wp-command-center/v1/approvals/{id}/approve`
   (WP cookie-auth, not token-auth — this is a WP_User action).
2. Handler approves the request AND immediately enqueues + runs the queue item.
3. Result is stored in `OperationResults`.
4. AI polling `approval_manage {action: request_get}` sees `status: completed`
   and the `queue_id` → then calls `approval_manage {action: results_get}` to
   get the actual output.

This removes `queue_run` from the AI's required workflow entirely in Client
and Enterprise modes. The AI's post-approval path becomes:

```
[pending_approval result] → AI tells user to approve in WP Admin
  → user approves → auto-runs → result stored
  → AI polls: approval_manage {action: request_get, request_id: "req_abc123"}
  → sees {status: "completed", queue_id: "q_xyz", result_id: "r_789"}
  → calls: approval_manage {action: results_get, result_id: "r_789"}
  → gets the operation output
```

**Revised AI workflow (Client/Enterprise Mode):**
```
tools/call plugin_manage {action: plugin_deactivate, name: "woocommerce/..."}
  → {status: "pending_approval", request_id: "req_abc123", ...}
  → AI: "I've submitted a deactivation request. Please approve it in your
         WordPress admin. I'll check back shortly."
  → [client approves in WP Admin]
  → AI polls: approval_manage {action: request_get, request_id: "req_abc123"}
  → {status: "completed", result_id: "r_789"}
  → AI: approval_manage {action: results_get, result_id: "r_789"}
  → {success: true, plugin: "WooCommerce", action: "deactivated"}
  → AI: "WooCommerce has been deactivated."
```

---

## 6. Client UX Design — WP Admin Approval Experience

### 6.1 Notification entry point

A persistent WP Admin bar badge: **"AI Requests (N)"** (red badge when > 0)
linking to `wp-admin → WP Command Center → Pending Approvals`.

On the WP Admin dashboard: a dismissible notice widget listing pending
requests with one-click Approve/Reject inline.

### 6.2 Approval Card

Each pending request renders as a structured card:

```
┌────────────────────────────────────────────────────────────┐
│  🔌  Plugin Management                          ● HIGH RISK │
│                                                             │
│  Action:     Deactivate Plugin                              │
│  Target:     WooCommerce PDF Invoices & Packing Slips       │
│  Requested:  2 minutes ago by Claude (AI Full Access)       │
│                                                             │
│  Reason:     Plugin conflict investigation. Investigating   │
│              checkout errors reported by customers.         │
│                                                             │
│  Rollback:   ✓ Available — can be reversed instantly        │
│  Audit:      This action will be logged                     │
│                                                             │
│  ┌──────────┐  ┌──────────┐  ┌──────────────────────────┐  │
│  │ ✓ Approve│  │ ✗ Reject │  │  View Details / Diff  ↗  │  │
│  └──────────┘  └──────────┘  └──────────────────────────┘  │
└────────────────────────────────────────────────────────────┘
```

**Card fields:**
- **Icon + operation title** (from `OperationRegistry::title`)
- **Risk badge** (color-coded: LOW=green, MED=yellow, HIGH=orange, CRIT=red)
- **Action** — the specific sub-action (`plugin_deactivate`, not just
  `plugin_manage`)
- **Target** — the human-readable object affected (plugin name, post title,
  setting label)
- **Requested** — relative timestamp + token label (from `AuthTokens::list()`)
- **Reason** — free-text AI explanation (the AI should include a `reason`
  param in all write calls; the product should prompt for it)
- **Rollback flag** — whether the patch system can undo this
- **Audit statement** — always shown (builds client trust)
- **Actions** — Approve (primary, green), Reject (secondary, red),
  View Details (opens a panel with full args + diff preview if available)

### 6.3 Risk color semantics

| Risk | Color | Label | Client reads as |
|---|---|---|---|
| DIAGNOSTIC | grey | "Read Only" | "AI is just looking" |
| LOW | green | "Low Risk" | "Minor, easily reversed" |
| MEDIUM | yellow | "Medium Risk" | "Meaningful change, reversible" |
| HIGH | orange | "High Risk" | "Significant change — review carefully" |
| CRITICAL | red | "Critical" | "Irreversible or site-wide impact" |

### 6.4 Bulk approval view

For AI plans that generate many requests (e.g. "update all plugins"), the
Pending Approvals page should support:
- Group view (requests grouped by `plan_id` or `session_id`)
- "Approve All in this Plan" (single confirm dialog listing all targets)
- "Reject All" (closes the plan)
- Estimated total risk (sum of risk levels)

### 6.5 Approval email / notification (future Step)

Client Mode should eventually send an email when an approval request is
created: "Your AI assistant is requesting a change to your WordPress site."
Not in STEP 80 — flagged as STEP 82 placeholder.

---

## 7. AI Continuity Design

### 7.1 Core principle

An AI agent should never reach a dead end because of an approval gate. The
plugin must give the AI enough information to either wait intelligently or
resume naturally after a disconnect.

### 7.2 Metadata on every approval request

Every `OperationManager::create_request()` call should accept (and store) an
optional `context` block from the AI:

```json
{
  "plan_id":    "plan_abc",
  "session_id": "sess_xyz",
  "task_id":    "task_007",
  "step":       3,
  "total_steps": 8,
  "reason":     "Plugin conflict investigation — step 3 of 8"
}
```

The AI should include these on every `tools/call` that reaches an approval
gate. OperationExecutor forwards them to `create_request()`.

### 7.3 Polling (Claude, Codex, Gemini — synchronous MCP clients)

For clients that do not support async callbacks:

```
1. tools/call plugin_manage → {status: "pending_approval", request_id: "..."}
2. AI tells user: "I need approval. I'll check every 30 seconds."
3. AI calls: approval_manage {action: request_get, request_id: "..."} in loop
4. When status == "completed" → calls results_get → continues plan
5. When status == "rejected" → AI says "The request was rejected. Pausing."
```

`request_get` is cheap (one DB read) and safe to call frequently.

### 7.4 Multi-step plan continuity

When a plan has 5 steps and step 3 requires approval:

1. AI executes steps 1–2 immediately (Developer Mode) or with their own
   approval gates.
2. Step 3 returns `pending_approval`. AI records `{request_id, step: 3}`.
3. AI **does not abandon the plan** — it continues describing the remaining
   steps to the user ("While we wait for approval on step 3, here's what
   I'll do next...") and optionally pre-creates requests for steps 4–5
   (if their content is determined) so the client can bulk-approve.
4. After approval + execution of step 3, AI polls `results_get`, then
   proceeds with steps 4–5.

### 7.5 Agent disconnect / reconnect

A session disconnect does not lose pending requests — they persist in
`OperationManager` (wp_options or custom table). When the AI reconnects:

1. AI calls: `approval_manage {action: request_list, status: "pending",
   session_id: "sess_xyz"}` to find any requests it submitted before the
   disconnect.
2. AI polls each `request_get` to see what was approved/rejected/pending.
3. AI resumes the plan from the last completed step.

For this to work, `session_id` must be stored on the request and filterable
in `request_list`. This is already in the existing `list_filters()` support.

### 7.6 Approval timeout (Enterprise Mode)

Enterprise Mode should eventually support an `auto_reject_after` policy
(e.g. 48 hours). A WordPress cron job scans pending requests older than the
policy threshold and auto-rejects them, recording the reason as
`"approval_timeout"`. The AI polling `request_get` sees `status: rejected,
reason: "approval_timeout"` and can notify the user. This is a future Step.

---

## 8. Required Code Changes

### Phase A — Foundation (STEP 80A, implement now)

| # | File | Change |
|---|---|---|
| A1 | NEW `includes/Operations/SecurityModeManager.php` | `MODE_*` constants, `current()`, `requires_approval()`, `requires_human_approver()` |
| A2 | `includes/Operations/OperationRegistry.php` | Add `action_risks` map to all 29 operations (per-action risk tier) |
| A3 | `includes/Operations/OperationExecutor.php` | Replace `-32000` error with auto-create approval request + `pending_approval` structured result |
| A4 | `includes/Operations/ApprovalRuntimeManager.php` | Guard `request_approve` and `queue_run` with `requires_human_approver()` check |
| A5 | `includes/Core/Activator.php` | Seed `wpcc_security_mode = 'developer'` on fresh install |
| A6 | `includes/Operations/OperationRegistry.php` | Refine all `'variable'` risk_levels to accurate tiers using `action_risks` |

### Phase B — Approval UX (STEP 80B, implement now)

| # | File | Change |
|---|---|---|
| B1 | NEW `includes/Admin/views/approvals.php` | Approval card UI (§6.2) — risk badge, target, reason, Approve/Reject |
| B2 | `includes/Admin/AdminPage.php` (or equivalent) | Register "Pending Approvals" menu item, admin bar badge |
| B3 | NEW REST endpoint `POST /approvals/{id}/approve` | WP cookie-auth only; approves + auto-runs the queue item |
| B4 | NEW REST endpoint `POST /approvals/{id}/reject` | WP cookie-auth only |
| B5 | `includes/Mcp/McpServerRuntime.php` | Update `tools_call()` to pass `reason`, `plan_id`, `session_id`, `task_id`, `step` from args to executor context |
| B6 | `includes/Operations/OperationRegistry.php` | Add `reason` parameter to all write operations (optional, free-text) |

### Phase C — Future Steps (design now, implement later)

| # | Feature | Step |
|---|---|---|
| C1 | Approval email notification to admin | STEP 82 |
| C2 | Approval timeout + cron auto-reject | STEP 83 |
| C3 | Multi-approver policy engine | STEP 85 |
| C4 | RBAC (named approver roles beyond WP admin) | STEP 86 |
| C5 | AI plan continuity (bulk pre-approve) | STEP 81 |
| C6 | Approval URL in structured response | STEP 80B |
| C7 | Team / multi-site approval dashboard | STEP 90+ |

---

## 9. Migration Strategy

### Existing installs

1. `wpcc_security_mode` does not exist → defaults to `'developer'`.
   `SecurityModeManager::current()` returns `'developer'`. All operations
   execute immediately — no change in behaviour from a pre-STEP-80 install.
2. `wpcc_enforce_approval` (the old binary flag) is no longer read by
   `OperationExecutor`. Sites that had it set to `1` manually will see
   operations execute immediately after upgrading because the Security Mode
   defaults to `developer`. The admin should go to:
   **WP Admin → WP Command Center → Settings → Security Mode → Client Mode → Save**
   to restore gating with the full approval UX.
3. No database migration, no WP-CLI, no SSH access required.

### Fresh installs

- `wpcc_security_mode = 'developer'` seeded by `Activator.php`.
- Admin selects their mode via **WP Admin → WP Command Center → Settings → Security Mode**.

### Deploying to a production site (step-by-step, no CLI required)

1. **Package**: zip the plugin locally (excluding `tests/`).
2. **Upload**: WP Admin → Plugins → Add New → Upload Plugin → select ZIP → Replace current version.
3. **Set mode**: WP Admin → WP Command Center → Settings → Security Mode → select **Client Mode** → **Save Security Mode**.
4. **Test**: Ask Claude (via MCP) to perform a write operation. It should return a `pending_approval` response with a link to the approvals page.
5. **Approve**: WP Admin → WP Command Center → Pending Approvals → click **Approve**.

---

## 10. Final Recommendations

### Which mode for which use case?

| Use Case | Recommended Mode | Rationale |
|---|---|---|
| Developer / personal site | **Developer Mode** | Zero friction. Full audit + rollback available. |
| Agency staging site | **Developer Mode** | Maximum productivity during build phase. Switch before handoff. |
| Client handoff | **Client Mode** | Client gets approval control. AI stays productive for read ops. Switch at go-live. |
| Freelance managed site | **Client Mode** | Agency retains AI access; client approves writes. Clear accountability. |
| SaaS hosted WordPress | **Client Mode** | Default for any multi-tenant environment. |
| Enterprise / compliance | **Enterprise Mode** | Full gate + audit + eventual multi-approver. |
| Agency running WP Command Center for clients | **Client Mode per site** | Mode is per-site option, not global. |

### Default for new installations

**Developer Mode.** Reasons:
1. The fastest path to a working product. An agency setting up a new site
   should see AI work immediately, not explain approval workflows.
2. Trust is built through the audit log and rollback, not the approval gate.
3. Switching to Client Mode before handoff is a natural, clearly-prompted
   event (the UI makes it a one-click decision).
4. New users who hit an approval gate on their first operation will abandon
   the product.

### UI entry point recommendation

The **Security Mode switcher should be the first prominent setting** shown
when the admin opens the AI Integrations page — three cards, side by side,
with a brief description and a recommended badge on "Client Mode" for
agencies:

```
┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐
│  Developer Mode │  │  Client Mode    │  │ Enterprise Mode │
│                 │  │  ★ Recommended  │  │                 │
│  For: Dev,      │  │  For: Client    │  │  For: Teams,    │
│  staging, you   │  │  handoffs,      │  │  compliance,    │
│                 │  │  managed sites  │  │  large orgs     │
│  AI executes    │  │  AI asks first  │  │  Full approval  │
│  immediately    │  │  Client approves│  │  chain required │
│                 │  │                 │  │                 │
│  [ Select ]     │  │  [ Select ]     │  │  [ Select ]     │
└─────────────────┘  └─────────────────┘  └─────────────────┘
```

The "★ Recommended" badge appears on Client Mode for agencies (not for
developers configuring their own sites). The product can detect context
(number of active tokens, multi-user WP site, etc.) to personalize the
recommendation.

---

## 11. Files

**New (Phase A):**
- `includes/Operations/SecurityModeManager.php`

**New (Phase B):**
- `includes/Admin/views/approvals.php`
- REST endpoints for `/approvals/{id}/approve` and `/approvals/{id}/reject`

**Edited (Phase A):**
- `includes/Operations/OperationRegistry.php` — `action_risks` per operation
- `includes/Operations/OperationExecutor.php` — auto-create approval on block
- `includes/Operations/ApprovalRuntimeManager.php` — human-approver guard
- `includes/Core/Activator.php` — seed `wpcc_security_mode`

**Edited (Phase B):**
- `includes/Admin/AdminPage.php` — approval menu + admin bar badge
- `includes/Mcp/McpServerRuntime.php` — pass plan context to executor

**Unmodified by design:**
- `includes/Security/AuditLog.php`
- `includes/Security/AuthTokens.php`
- `includes/Operations/CapabilityRegistry.php`
- `includes/PatchSystem/` — rollback system already correct
