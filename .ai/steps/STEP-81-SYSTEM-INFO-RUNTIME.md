# STEP 81 — System Info Runtime, Security Mode Validation, and Product Review

## Goals

| Goal | Description |
|---|---|
| A | Validate all three Security Modes and document exact gating behaviour |
| B | Build a pure-PHP `system_info` operation — no WP-CLI, no shell |
| C | Improve `wp_cli_bridge` error diagnostics with structured error codes |
| D | Product review: agency-readiness, commercial gaps, recommendations |

---

## A. Security Mode Validation

### A.1 Gating Matrix

Each cell shows the outcome when an AI agent calls the operation in that mode without an existing `request_id` or `queue_id`.

**PASS** = executes immediately  
**GATE** = returns `{status:"pending_approval", request_id:"…"}`  
**GUARD** = `request_approve`/`request_reject`/`queue_run`/`queue_retry` blocked for token actors (human-approver required)

| Operation | Risk Tier | Developer | Client | Enterprise |
|---|---|---|---|---|
| `system_info` | diagnostic | PASS | PASS | PASS |
| `database_inspect` | diagnostic | PASS | PASS | PASS |
| `search_manage` | diagnostic | PASS | PASS | PASS |
| `approval_manage/request_list` | diagnostic | PASS | PASS | PASS |
| `plugin_manage/plugin_list` | diagnostic (action) | PASS | PASS | PASS |
| `user_manage/user_list` | diagnostic (action) | PASS | PASS | PASS |
| `theme_manage/theme_list` | diagnostic (action) | PASS | PASS | PASS |
| `snapshot_manage/snapshot_list` | diagnostic (action) | PASS | PASS | PASS |
| `content_manage/content_list` | low | PASS | PASS | GATE |
| `content_manage/content_get` | low | PASS | PASS | GATE |
| `content_manage/content_create` | medium | PASS | GATE | GATE |
| `content_manage/content_update` | medium | PASS | GATE | GATE |
| `content_manage/content_delete` | medium | PASS | GATE | GATE |
| `plugin_manage/plugin_install` | high | PASS | GATE | GATE |
| `plugin_manage/plugin_activate` | high | PASS | GATE | GATE |
| `theme_manage/theme_install` | high | PASS | GATE | GATE |
| `user_manage/user_create` | high | PASS | GATE | GATE |
| `snapshot_manage/snapshot_create` | medium | PASS | GATE | GATE |
| `snapshot_manage/snapshot_restore` | high | PASS | GATE | GATE |
| `plugin_manage/plugin_delete` | critical | PASS | GATE | GATE |
| `user_manage/user_delete` | critical | PASS | GATE | GATE |
| `user_manage/user_assign_role` | critical | PASS | GATE | GATE |
| `safe_search_replace` | critical | PASS | GATE | GATE |
| `wp_cli_bridge` | critical | PASS | GATE | GATE |
| `request_approve` (token actor) | — | allowed | GUARD | GUARD |
| `request_approve` (WP admin) | — | allowed | allowed | allowed |

### A.2 Gating Rules (SecurityModeManager)

```
Developer:   requires_approval() → always false
Client:      requires_approval() → true if risk ∈ {medium, high, critical}
Enterprise:  requires_approval() → true if risk ≠ diagnostic
Human guard: requires_human_approver() → true if mode ≠ developer
             Applies to: request_approve, request_reject, queue_run, queue_retry
             Does NOT apply to: request_cancel (AI may cancel its own requests)
```

### A.3 Action-Level Risk Resolution

`SecurityModeManager::effective_risk(operation, action)` checks
`operation['action_risks'][action]` first; falls back to `operation['risk_level']`.

This means a single tool call like `plugin_manage {action:"plugin_list"}` is
**diagnostic** (executes freely in all modes), while `plugin_manage {action:"plugin_delete"}`
is **critical** (gated in Client and Enterprise).

### A.4 Test Results

| Suite | Assertions | Result |
|---|---|---|
| `test-security-modes.sh` | 28 | 28/28 PASS |
| `test-security-mode-validation.sh` (new, STEP 81) | 27 | 27/27 PASS |
| `test-system-info.sh` (new, STEP 81) | 24 | 24/24 PASS |
| `test-approval-enforcement.sh` | 16 | 16/16 PASS |
| `test-mcp-approval-runtime.sh` | 25 | 25/25 PASS |
| **Full regression suite (all test-*.sh)** | **2692** | **2692/0 PASS** |

---

## B. System Info Runtime

### B.1 Why this exists

Real-world testing on `mosharafmanu.com` showed that basic site information
(site URL, WordPress version, PHP version) depended on `wp_cli_bridge`.
On managed hosting, `proc_open` and `shell_exec` are both commonly disabled,
making `wp_cli_bridge` unavailable.

`system_info` returns the same data using only native WordPress and PHP APIs.
It works on every hosting environment with no configuration.

### B.2 File

`includes/Operations/SystemInfoRuntime.php`

### B.3 Fields returned

| Field | Source | Example |
|---|---|---|
| `site_url` | `get_site_url()` | `https://mosharafmanu.com` |
| `home_url` | `get_home_url()` | `https://mosharafmanu.com` |
| `wordpress_version` | `get_bloginfo('version')` | `7.0` |
| `php_version` | `PHP_VERSION` | `8.2.27` |
| `mysql_version` | `$wpdb->get_var('SELECT VERSION()')` | `8.0.39` |
| `active_theme.name` | `wp_get_theme()->get('Name')` | `Mosharaf Core` |
| `active_theme.version` | `wp_get_theme()->get('Version')` | `1.0.0` |
| `active_theme.slug` | `wp_get_theme()->get_stylesheet()` | `mosharaf-core` |
| `active_plugins_count` | `count(get_option('active_plugins'))` | `11` |
| `multisite` | `is_multisite()` | `false` |
| `memory_limit` | `ini_get('memory_limit')` | `256M` |
| `max_execution_time` | `ini_get('max_execution_time')` | `300` |
| `upload_max_filesize` | `ini_get('upload_max_filesize')` | `64M` |
| `debug_mode` | `WP_DEBUG` | `false` |
| `environment_type` | `wp_get_environment_type()` | `production` |
| `locale` | `get_locale()` | `en_US` |
| `timezone` | `wp_timezone_string()` | `America/Chicago` |
| `shell_capabilities.proc_open_enabled` | `function_exists('proc_open')` | `true` |
| `shell_capabilities.shell_exec_enabled` | `function_exists('shell_exec')` | `true` |
| `shell_capabilities.wp_cli_available` | `WpCliBridge::is_available()` | `true` |

### B.4 Registry entry

```
id:                system_info
risk_level:        diagnostic
requires_approval: false
available:         always (pure PHP)
gated in:          never (diagnostic ops are free in all modes)
```

### B.5 Bug fixed: content_manage action_risks used short names

During STEP 81 testing, `content_manage` action_risks were discovered to use
short keys (`list`, `get`) that didn't match the actual handler action names
(`content_list`, `content_get`). This caused the effective risk to fall back
to the operation-level `medium`, incorrectly gating list/get in Client Mode.

Fixed in `OperationRegistry.php`: updated to full names (`content_list`,
`content_get`, `content_create`, …) matching `ContentRegistry::ACTIONS`.
Risk level for list/get updated from `diagnostic` → `low` to match
`ContentRegistry::action_risk()`.

---

## C. WP-CLI Bridge Diagnostics

### C.1 Before (STEP 81)

```
"WP-CLI bridge is not available on this server."
"Tool execution failed"
```

No indication of WHY it failed. AI agents and administrators had no
actionable path to diagnose or fix the issue.

### C.2 After: structured error codes

| Error code | When returned | Hint included |
|---|---|---|
| `exec_disabled` | Both `proc_open` and `shell_exec` disabled | Use `system_info` instead |
| `proc_open_disabled` | `proc_open` disabled, `shell_exec` available | Contact host to enable proc_open |
| `wp_cli_not_found` | Functions available but binary not in PATH | Install WP-CLI, check searched paths |
| `permission_denied` | Exit stderr contains "Permission denied" | chmod +x $(which wp) |
| `timeout` | Exit code 124 or stderr contains "timed out" | Narrower query or higher server limit |
| `command_failed` | Non-zero exit, unrecognised stderr | Check stderr field |

Each error includes a `data` object with:
- `diagnostic` — machine-readable code (same as error code)
- `hint` — human-readable fix
- `searched_paths` (for `wp_cli_not_found`) — exactly where WP-CLI was searched
- `exitcode` + `stderr` (for `command_failed`) — raw WP-CLI output
- `disabled_functions_sample` (for `exec_disabled`) — first 10 disabled functions

### C.3 Example: exec_disabled (common on managed hosts)

```json
{
  "error_code": "exec_disabled",
  "error_message": "WP-CLI is unavailable: proc_open and shell_exec are both disabled on this server. This is common on managed and shared hosting. Use system_info for read-only site information instead.",
  "diagnostic": "exec_disabled",
  "proc_open": false,
  "shell_exec": false,
  "disabled_functions_sample": ["exec", "shell_exec", "proc_open", "passthru", "system"],
  "hint": "Use system_info to retrieve site URL, PHP version, WordPress version, and environment details without shell access."
}
```

---

## D. Product Review — Agency Readiness

### D.1 What works well

| Feature | Assessment |
|---|---|
| Developer Mode | Zero friction. Audit + rollback active. No configuration needed. |
| Client Mode | Core approval workflow is solid. pending_approval returns approval_url. AI cannot self-approve. |
| Approval UI | Cards show operation, action, risk badge, reason, Approve/Reject. Admin bar badge with count. |
| Action-level risk | plugin_list free, plugin_delete gated — right granularity for real workflows. |
| system_info | Works on all hosts including managed shared hosting. No WP-CLI needed for basics. |
| Audit trail | All operations logged. Immutable append-only JSONL. |
| Rollback | Patch-based rollback available for all write operations. |

### D.2 Gaps before wider rollout

#### Gap 1: `reason` field is optional — approval cards lack context

The AI is allowed to include a `reason` field in every write call. In practice,
Claude does not always include it without explicit prompting. When `reason` is
absent, the approval card shows no explanation and the admin must infer intent
from the raw action + args.

**Recommendation:** Add a system prompt instruction or MCP prompt resource
that tells the AI: "Always include a `reason` field explaining why you are
making each write request." This is a configuration change, not a code change.
A future step could make `reason` required in Client/Enterprise mode at the
schema level.

#### Gap 2: No email notification on new approval requests

Admins who aren't actively watching WP Admin will miss approval requests.
The admin bar badge is only visible when already logged in.

**Recommendation:** Implement STEP 82 (approval email notification) as high
priority before agency client handoff. The email should include the operation
name, risk level, reason, and a direct link to the approvals page.

#### Gap 3: Approval timeout — requests can sit indefinitely

A pending request with no timeout creates operational debt. If a client
ignores an approval request, the AI agent stalls and the operation never runs.

**Recommendation:** Implement STEP 83 (approval timeout + auto-reject) with a
configurable timeout (default 24h for Client Mode). Expired requests should
auto-reject with an audit entry, and the AI should be notified on next poll.

#### Gap 4: No bulk approval — high-volume AI plans create friction

An AI plan that updates 20 plugins generates 20 individual approval cards.
Approving them one-by-one is unacceptable for a client handoff scenario.

**Recommendation:** Add plan-level bulk approve to the Pending Approvals UI.
Group cards by `plan_id`. Show "Approve all 20 in this plan" with a confirm
dialog listing all targets and an aggregate risk summary. This is high priority
for agency workflows.

#### Gap 5: Enterprise Mode is too aggressive for most agency use

Enterprise Mode gates ALL non-diagnostic operations — including `content_list`
(listing posts), `snapshot_list` (listing snapshots), and other read-adjacent
operations with `low` risk. This creates constant approval friction for
genuinely harmless operations.

**Recommendation:** Consider adding a `low` risk tier exception to Enterprise
Mode, or introduce a 4th mode ("Strict Client") between Client and Enterprise
that gates `high` and `critical` only. The current Enterprise Mode is most
appropriate for compliance-heavy environments (HIPAA, legal, finance) rather
than typical agency-managed WordPress sites.

#### Gap 6: Managed hosting — wp_cli_bridge unavailable on most shared hosts

On managed hosting platforms (WP Engine, Kinsta, Flywheel, Cloudways),
`proc_open` is commonly disabled. `wp_cli_bridge` returns `exec_disabled`.
This affects:
- Cache flush (`wp cache flush`)
- DB size check (`wp db size`)
- Cron management (`wp cron event list`)
- Any WP-CLI-only operation

`system_info` (STEP 81) covers basic reads. All other operations use native
WordPress APIs and work fine. The practical impact is narrow.

**Recommendation:** Document explicitly in the manifest which operations
require shell access vs. those that work on managed hosting. Add a
`requires_shell` flag to `wp_cli_bridge`'s manifest entry.

#### Gap 7: No licensing / Free-Pro gating

There is no licence check anywhere. Security Modes, the approval UI, and the
full operation catalog are fully available on every install regardless of
whether the site has a commercial licence.

**Recommendation:** Implement STEP 84 (licensing) before public launch.
Suggested split: Developer Mode = Free; Client/Enterprise Mode = Pro.
The approval UI (Pending Approvals page) = Pro. This creates a natural
upgrade path that aligns with the agency handoff use case.

### D.3 Priority ranking for next steps

| Priority | Step | Description |
|---|---|---|
| P0 | STEP 82 | Approval email notification |
| P0 | Bulk approve UI | Group approval by plan_id |
| P1 | STEP 83 | Approval timeout + auto-reject |
| P1 | `reason` enforcement | System prompt or schema-level |
| P2 | STEP 84 | Licensing / Free-Pro split |
| P2 | `requires_shell` flag | Manifest transparency for managed hosts |
| P3 | Enterprise Mode tuning | Consider `low` risk exception |
| P3 | STEP 85 | Multi-approver workflow |

---

## Files

**New:**
- `includes/Operations/SystemInfoRuntime.php` — system_info handler (pure PHP)
- `tests/test-system-info.sh` — 24-assertion test suite
- `tests/test-security-mode-validation.sh` — 27-assertion gating matrix test
- `STEP-81-SYSTEM-INFO-RUNTIME.md` — this document

**Edited:**
- `includes/Operations/OperationRegistry.php` — added `system_info` operation; fixed `content_manage` action_risks (full action names, corrected low risk for list/get)
- `includes/Operations/OperationExecutor.php` — added `system_info` dispatch case
- `includes/Operations/WpCliBridge.php` — structured unavailable/exit error codes with diagnostic data and hints
