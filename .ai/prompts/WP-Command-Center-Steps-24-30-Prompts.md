# WP Command Center — Steps 24–30 Implementation Prompts

Use this file to command Claude, Codex, Gemini, or another AI coding agent one step at a time.

Important workflow:

1. Copy only one step prompt at a time.
2. Let the AI agent implement that step.
3. Ask it to provide a verification report.
4. Do not move to the next step until tests pass.
5. Each step must update `resume.md` with:
   - What was done
   - Files changed
   - Endpoints added
   - Tests added
   - Verification result
   - Next recommended step

Global constraints for all steps:

- No AI chat unless explicitly requested later.
- No MCP integration yet.
- No Claude/Codex direct integration yet.
- No UI unless the step specifically asks for UI.
- Preserve all existing tests.
- Preserve backward compatibility.
- Keep security, redaction, audit logging, and approval gates intact.

---

# Step 24 — Operation Retry Engine

## Goal

Add retry support for failed operation queue items.

Current state:

Operation Queue exists:

Operation Request → Approval → Queue → Manual Run → Completed / Failed

Target:

Failed queue item → Retry → New attempt → Completed / Failed

## Prompt

```text
Step 24 — Operation Retry Engine

Goal:
Add retry support for failed operation queue items.

Do NOT add UI.
Do NOT add cron/background worker yet.
Do NOT add AI chat.
Do NOT add MCP.
Do NOT add new operation types.

Requirements:

1. Add endpoint:

POST /operations/queue/{id}/retry

Full access required.

2. Retry rules:
- Only failed queue items can be retried.
- Completed queue items cannot be retried.
- Cancelled queue items cannot be retried.
- Running queue items cannot be retried.
- Queued items cannot be retried.
- Respect max_attempts.
- If attempts >= max_attempts, return error.

3. Attempt tracking:
- increment attempts on retry
- status becomes queued
- previous error remains stored
- retry metadata is stored

4. Audit events:
- operation.queue.retry_requested
- operation.queue.retry_queued
- operation.queue.retry_failed

5. Timeline integration:
Retry events must appear in GET /agent/timeline.

6. Agent context:
GET /agent/context should include:
- retryable_queue_items
- failed_queue_items

7. Manifest:
GET /agent/manifest should include the retry endpoint.

8. Security:
- Full access required.
- Read-only tokens cannot retry.
- Payload must remain redacted.
- No secrets exposed.

9. Tests:
Create tests/test-operation-retry.sh

Test:
- failed item can retry
- completed item cannot retry
- cancelled item cannot retry
- running item cannot retry
- queued item cannot retry
- max attempts respected
- audit entries created
- timeline entries created
- agent context includes retryable failures
- full regression passes

10. Verification report:
Provide files changed, endpoints added, schema changes if any, example retry request, example retry response, test count, and full suite result.
```

---

# Step 25 — Background Worker Using WP-Cron

## Goal

Allow queued operations to run automatically using WordPress cron.

Current state:

Approved Request → Queue → Manual Run

Target:

Approved Request → Queue → WP-Cron Worker → Execute → Complete / Fail

## Prompt

```text
Step 25 — Background Worker Using WP-Cron

Goal:
Allow queued operations to run automatically using WordPress cron.

Do NOT add UI.
Do NOT add new operation types.
Do NOT add AI chat.
Do NOT add MCP.
Use WordPress-native cron only.

Requirements:

1. Create worker class:

includes/Operations/OperationWorker.php

Responsibilities:
- find queued items
- claim one item safely
- mark as running
- execute through OperationExecutor
- mark completed or failed
- respect attempts/max_attempts
- avoid duplicate processing

2. Register WP-Cron hook:

wpcc_process_operation_queue

Run every 1–5 minutes.

3. Manual trigger endpoint:

POST /operations/queue/process

Full access required.

4. Batch limit:
- default limit = 5
- maximum limit = 20

5. Locking:
Prevent multiple workers from processing the same queue item using transient or option-based locking.

6. Audit events:
- operation.worker.started
- operation.worker.completed
- operation.worker.failed
- operation.worker.locked

7. Timeline integration:
Worker events must appear in GET /agent/timeline.

8. Agent context:
Include:
- queue_worker_status
- pending_queue_count
- running_queue_count
- failed_queue_count

9. Manifest:
Include worker/process endpoint and cron capability.

10. Tests:
Create tests/test-operation-worker.sh

Test:
- worker processes queued item
- worker respects batch limit
- worker does not process completed item
- worker does not process cancelled item
- locking works
- process endpoint works
- audit entries exist
- timeline entries exist
- full regression passes

11. Verification report:
Provide files changed, cron hook registered, endpoint added, example process request, example worker result, test count, and full suite result.
```

---

# Step 26 — Safe Search & Replace Operation

## Goal

Add a safe database search and replace operation through the Operations framework.

This is high-value and high-risk.

## Prompt

```text
Step 26 — Safe Search & Replace Operation

Goal:
Add a safe database search and replace operation through the Operations framework.

Operation ID:
safe_search_replace

Risk level:
high

Requires approval:
true

Endpoint:
POST /operations/safe_search_replace/run

Full access only.

Compatible with:
- operation request
- approval gate
- queue
- executor
- worker

Input schema:
{
  "search": "old-domain.com",
  "replace": "new-domain.com",
  "dry_run": true,
  "tables": ["wp_posts", "wp_postmeta"],
  "case_sensitive": false
}

V1 scope:
- dry run
- real run
- selected tables
- serialized data safe replacement where possible
- reporting affected rows

Do NOT support:
- regex
- full multisite network replace
- binary blobs
- arbitrary SQL input

Safety:
- dry_run=true by default
- real run requires explicit dry_run=false
- block empty search string
- block replacement if search equals replace
- validate table names
- only allow tables with current WordPress DB prefix
- redact sensitive values from audit summaries
- include strong warning metadata

Backup strategy:
V1 does not create full DB backups.
Operation must warn that external backup is recommended.

Result shape:
{
  "operation_id": "safe_search_replace",
  "success": true,
  "result": {
    "dry_run": true,
    "tables_checked": 2,
    "matches_found": 15,
    "rows_affected": 0
  },
  "errors": [],
  "created": [],
  "updated": [],
  "skipped": []
}

Audit events:
- operation.safe_search_replace.started
- operation.safe_search_replace.completed
- operation.safe_search_replace.failed

Timeline integration:
Show dry run and real run summaries.

Tests:
Create tests/test-safe-search-replace.sh

Test:
- dry run works
- real run works
- empty search blocked
- same search/replace blocked
- invalid table blocked
- non-prefixed table blocked
- serialized data safety basic case
- audit entries
- timeline entries
- queue execution
- full regression passes

Verification report:
Provide files changed, operation registered, endpoint added, example dry run, example real run, test count, and full suite result.
```

---

# Step 27 — Media Import Operation

## Goal

Add a safe Media Library import operation using WordPress native media APIs.

## Prompt

```text
Step 27 — Media Import Operation

Goal:
Add a safe Media Library import operation using WordPress native media APIs.

Operation ID:
media_import

Risk level:
medium

Requires approval:
true

Endpoint:
POST /operations/media_import/run

Full access only.

Compatible with:
- operation request
- queue
- executor
- worker

Input schema:
{
  "source_url": "https://example.com/image.jpg",
  "title": "Example Image",
  "alt": "Example image alt text",
  "caption": "Optional caption",
  "description": "Optional description",
  "attach_to_post_id": 123
}

V1 scope:
- remote image URL import
- jpg
- jpeg
- png
- webp
- gif
- pdf optional if safe
- alt text
- caption
- description
- optional post attachment

Do NOT support:
- bulk ZIP imports
- video import
- SVG
- private authenticated URLs
- external cloud storage

Safety:
- validate URL
- only allow http/https
- check file extension
- check MIME type after download
- enforce max file size, e.g. 10MB
- block SVG by default
- sanitize metadata
- respect WordPress upload permissions

Execution:
Use native WordPress functions:
- download_url()
- media_handle_sideload()
- wp_update_attachment_metadata()
- update_post_meta() for alt text

Audit events:
- operation.media_import.started
- operation.media_import.completed
- operation.media_import.failed

Timeline integration:
Example: Imported media attachment ID 123

Tests:
Create tests/test-media-import.sh

Test:
- valid image import
- invalid URL blocked
- unsupported extension blocked
- SVG blocked
- oversized file blocked if possible
- attach to post
- alt text saved
- audit entries
- timeline entries
- queue execution
- regression passes

Verification report:
Provide files changed, endpoint added, example request, example response, test count, and full suite result.
```

---

# Step 28 — Safe Updates Operation

## Goal

Add a safe plugin/theme update operation with snapshot and health verification.

## Prompt

```text
Step 28 — Safe Updates Operation

Goal:
Add a safe plugin/theme update operation with snapshot and health verification.

Operation ID:
safe_updates

Risk level:
high

Requires approval:
true

Endpoint:
POST /operations/safe_updates/run

Full access only.

Compatible with:
- operation request
- queue
- executor
- worker

Input schema:
{
  "type": "plugin",
  "slug": "woocommerce",
  "dry_run": true
}

Supported type:
- plugin
- theme

V1 scope:
- dry run
- update one plugin
- update one theme
- pre-update snapshot metadata
- update via WordPress upgrader APIs
- post-update health check

Do NOT support:
- bulk updates
- WordPress core updates
- database migration rollback
- composer/npm builds
- multisite network updates

Safety:
- dry_run=true by default
- real update requires dry_run=false
- validate plugin/theme exists
- verify update available
- capture before version
- capture after version
- run health check after update
- if health check fails, flag failed and recommend restore

Backup strategy:
V1 captures plugin/theme directory metadata.
No full file backup unless the existing snapshot system supports it safely.
Warn if rollback snapshot is incomplete.

Execution APIs:
Use WordPress native APIs:
- Plugin_Upgrader
- Theme_Upgrader
- wp_update_plugins()
- wp_update_themes()

Health verification:
Run:
- site health check
- admin loopback check if available
- fatal error scan / debug log scan
- plugin active check for plugin updates

Audit events:
- operation.safe_updates.started
- operation.safe_updates.completed
- operation.safe_updates.failed

Timeline integration:
Example: Updated plugin WooCommerce from 10.8.1 to 10.8.2

Tests:
Create tests/test-safe-updates.sh

Test:
- dry run
- invalid plugin blocked
- invalid theme blocked
- real update path mocked or safely simulated
- health check result recorded
- audit entries
- timeline entries
- queue execution
- regression passes

Verification report:
Provide files changed, endpoint added, example dry run, example update result, test count, and full suite result.
```

---

# Step 29 — WP-CLI Operation Runtime

## Goal

Expose a safe, limited WP-CLI operation through the Operations framework when WP-CLI is available.

WP-CLI is optional and must never be required.

## Prompt

```text
Step 29 — WP-CLI Operation Runtime

Goal:
Expose a safe, limited WP-CLI operation through the Operations framework when WP-CLI is available.

Operation ID:
wp_cli_bridge

Risk level:
high

Requires approval:
true

Available only when:
- shell_exec enabled
- proc_open enabled
- WP-CLI available

Endpoint:
POST /operations/wp_cli_bridge/run

Full access only.

Compatible with:
- operation request
- queue
- executor
- worker

Input schema:
{
  "command": "cache_flush"
}

V1 allowed commands only:
- plugin_list
- theme_list
- cache_flush
- cron_event_list
- option_get_siteurl
- db_size_check

Do NOT allow arbitrary commands.

Command mapping:
Internally map command IDs to safe WP-CLI commands.

Examples:
cache_flush -> wp cache flush
plugin_list -> wp plugin list --format=json

Safety:
- no arbitrary shell input
- no raw command strings
- escape all arguments
- timeout required
- output size limit required
- redact output
- disable automatically when unavailable

Audit events:
- operation.wp_cli_bridge.started
- operation.wp_cli_bridge.completed
- operation.wp_cli_bridge.failed

Timeline integration:
Example: Ran WP-CLI command: cache_flush

Tests:
Create tests/test-wp-cli-bridge.sh

Test:
- unavailable environment returns unavailable
- arbitrary command blocked
- allowed command mapping works
- timeout handling
- redaction
- audit entries
- timeline entries
- manifest availability
- context availability
- regression passes

Verification report:
Provide files changed, endpoint added, allowed command list, example request, example response, test count, and full suite result.
```

---

# Step 30 — Agent Runtime Dashboard UI

## Goal

Add the first meaningful admin UI for the Agent Runtime.

This is not chat. This is human visibility and control.

## Prompt

```text
Step 30 — Agent Runtime Dashboard UI

Goal:
Add the first meaningful admin UI for the Agent Runtime.

This is not chat.
This is not AI integration.
This is human visibility and control.

Admin page:
Command Center → Agent Runtime

Display overview cards:
- Active Sessions
- Open Tasks
- Proposed Actions
- Pending Plans
- Pending Operation Requests
- Queued Operations
- Applied Patches
- Failed Queue Items

Runtime tree:
Show hierarchy:
Session
 └ Task
    └ Action
       └ Plan
          └ Patch

Use existing:
GET /agent/tree

Timeline panel:
Show recent timeline events using:
GET /agent/timeline

Pending reviews panel:
Show:
- pending plans
- pending operation requests
- pending patches

Actions:
For V1 UI, allow:
- approve plan
- reject plan
- approve operation request
- reject operation request
- run queued operation manually
- view patch

Do NOT allow:
- direct file editing
- AI chat
- MCP
- automatic execution
- arbitrary operations

Security:
- admin only
- nonce protection
- capability checks
- no secrets
- redaction applied
- confirmation dialogs for mutation actions

UI quality:
Keep simple WordPress admin UI.
No React required.
Use existing admin CSS patterns.

Tests:
Add basic admin route/render tests if possible.

Manual verification required:
- dashboard loads
- counts render
- timeline renders
- pending reviews render
- approval buttons work
- no secrets exposed

Verification report:
Provide files changed, screenshots, manual verification steps, automated tests if any, and full regression result.
```

---

# Reporting Format For Every Step

After each step, ask the AI agent to save a report using this format:

```markdown
# Step XX Report — Step Name

## Summary

What was implemented.

## Files Changed

- file 1
- file 2

## Endpoints Added

- endpoint 1
- endpoint 2

## Database Changes

- table/column changes

## Security Notes

- permissions
- redaction
- approval gates

## Tests Added

- test file name
- assertion count

## Verification Result

- passed
- failed
- issues found

## Example Request

JSON example here.

## Example Response

JSON example here.

## Next Recommended Step

Short recommendation.
```

---

# Recommended Execution Order

1. Step 24 — Operation Retry Engine
2. Step 25 — Background Worker Using WP-Cron
3. Step 26 — Safe Search & Replace Operation
4. Step 27 — Media Import Operation
5. Step 28 — Safe Updates Operation
6. Step 29 — WP-CLI Operation Runtime
7. Step 30 — Agent Runtime Dashboard UI
