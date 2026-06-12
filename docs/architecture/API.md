# REST API Reference

All endpoints are under the base namespace `wp-command-center/v1`. The full base URL is `<site>/wp-json/wp-command-center/v1`.

**Authentication:** Every request requires an `Authorization: Bearer <token>` header. Tokens are managed via Settings → API Tokens in the WordPress admin.

**Token scopes:**
- `read_only` — Can call GET endpoints and `POST /operations/database_inspect/run`
- `full` — Can call all endpoints, including write operations

**Error responses** follow the WordPress REST API convention:
```json
{
  "code": "wpcc_error_code",
  "message": "Human-readable error message.",
  "data": { "status": 400 }
}
```

---

## Health & System

### `GET /health`
**Scope:** read_only  
**Description:** Health check for the API gateway. Returns plugin version and timestamp.

**Response:**
```json
{
  "status": "ok",
  "plugin_version": "1.0.0",
  "api_version": "v1",
  "timestamp": 1718236800
}
```

### `POST /health/verify`
**Scope:** full  
**Description:** Run read-only frontend, admin, REST, WPCC, WooCommerce, plugin, and theme health checks.

**Request body:** None (empty body accepted)

**Response:**
```json
{
  "checks": {
    "frontend": { "status": "pass", "http_code": 200, "duration_ms": 145 },
    "admin_ajax": { "status": "pass", "http_code": 200, "duration_ms": 89 },
    "rest_api": { "status": "pass", "http_code": 200, "duration_ms": 56 },
    "wpcc_health": { "status": "pass" },
    "woocommerce": { "status": "pass", "version": "8.9.0" },
    "plugins_active": { "status": "pass", "count": 12 },
    "theme": { "status": "pass", "active": "twentytwentyfive" }
  },
  "overall": "pass",
  "verified_at": "2026-06-12T10:30:00Z"
}
```

**Error codes:** `wpcc_health_verification_failed`

### `GET /health/results`
**Scope:** read_only  
**Description:** List persisted health verification results.

**Query parameters:**
- `status` (string, optional) — Filter by status
- `limit` (integer, optional) — Max results
- `offset` (integer, optional) — Pagination offset

**Response:** Array of health result records.

### `GET /system/environment`
**Scope:** read_only  
**Description:** Get the current WP Command Center environment mode.

**Response:**
```json
{
  "mode": "development",
  "supported_modes": ["development", "staging", "production"]
}
```

### `POST /system/environment`
**Scope:** full  
**Description:** Set environment mode: `development`, `staging`, or `production`.

**Request body:**
```json
{
  "mode": "production"
}
```

**Response:**
```json
{
  "mode": "production",
  "previous_mode": "development"
}
```

**Error codes:** `wpcc_invalid_environment_mode`

### `POST /system/cleanup`
**Scope:** full  
**Description:** Dry-run or delete age-qualified terminal runtime records with environment-aware safeguards. Production cleanup requires explicit override and confirmation.

**Request body:**
```json
{
  "resources": ["sessions", "tasks", "queue_items"],
  "dry_run": true,
  "older_than_days": 30
}
```

**Error codes:** `wpcc_invalid_cleanup_resources`, `wpcc_cleanup_confirmation_required`, `wpcc_production_cleanup_blocked`

---

## Discovery

### `GET /manifest`
**Scope:** read_only  
**Description:** Machine-readable description of this API for agent discovery. Returns all endpoints, namespace, and version.

**Response:**
```json
{
  "name": "WP Command Center",
  "version": "1.0.0",
  "api_version": "v1",
  "namespace": "wp-command-center/v1",
  "base_url": "https://example.com/wp-json/wp-command-center/v1",
  "endpoints": [...]
}
```

### `GET /agent/manifest`
**Scope:** read_only  
**Description:** Agent discovery manifest: capabilities, security posture, workflow, endpoint catalog, error catalog, capability negotiation, operations list, option registry, plugin/theme state, database info, WP-CLI bridge status, MCP server status, AI client registry, and version info. Read-only; contains no file contents, secrets, tokens, or customer data.

**Response:** Large manifest object including `plugin`, `capabilities`, `security`, `workflow`, `operations`, `wp_cli_bridge`, `plugin_management`, `theme_management`, `snapshot_management`, `content_management`, `database_inspection`, `capability_management`, `mcp_server`, `claude_integration`, `ai_clients`, `option_management`, `endpoints`, `error_catalog`, `capability_negotiation`, `versions`, `manifest_version`, `manifest_hash`.

### `GET /context`
**Scope:** read_only  
**Description:** Composite context bundle: site info, diagnostics, server capabilities, and file access map. Secrets are redacted as `[REDACTED_SECRET]`.

**Response:** Large context object built by `ContextBuilder`.

### `GET /agent/context`
**Scope:** read_only  
**Description:** Metadata-only agent runtime context. Includes session-scoped data when `session_id` is provided. Secrets are redacted as `[REDACTED_SECRET]`.

**Query parameters:**
- `session_id` (string, optional) — Scope context to a specific session
- `include_files` (boolean, optional, default: false) — Include file listing
- `include_diagnostics` (boolean, optional, default: true) — Include diagnostics

**Response:** Comprehensive context including health, capabilities, site summary, recent patches, recent actions, recent audit entries, session details (if `session_id` provided), operations, pending/recent operation requests, queue items, worker stats, recent results, recommendations, health verifications, environment mode, WP-CLI status, option management summary, plugin state, theme state, snapshot state, content counts, database info, capability assignments, MCP status, AI integration info, `manifest_version`, `manifest_hash`.

For AI workflows, prefer MCP `wpcc://context` in its default `compact` mode. The REST endpoint preserves its standard response for backward compatibility.

### `GET /capabilities`
**Scope:** read_only  
**Description:** Capabilities of this API and the current token (file/patch/rollback access, server execution features).

**Response:**
```json
{
  "file_read": true,
  "file_write": false,
  "patch_apply": true,
  "rollback": true,
  "shell_exec": true,
  "proc_open": true,
  "wp_cli": true,
  "wp_cli_operations": true
}
```

---

## Agent Runtime

### `POST /agent/sessions`
**Scope:** full  
**Description:** Create an agent session. Sessions expire after 24 hours by default.

**Request body:**
```json
{
  "source": "claude",
  "label": "Fix homepage layout",
  "expires_at": 1718323200
}
```
**Valid sources:** `claude`, `codex`, `gpt`, `api`, `manual`

**Response:**
```json
{
  "session_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "source": "claude",
  "label": "Fix homepage layout",
  "status": "active",
  "created_at": "2026-06-12T10:30:00Z",
  "expires_at": "2026-06-13T10:30:00Z"
}
```

**Error codes:** `wpcc_session_create_failed`, `wpcc_invalid_session_source`, `wpcc_invalid_session_expiry`, `wpcc_missing_session_label`

### `GET /agent/sessions`
**Scope:** read_only  
**Description:** List agent sessions, newest first.

**Response:** Array of session objects.

### `GET /agent/sessions/{id}`
**Scope:** read_only  
**Description:** Get an agent session by UUID (`[a-f0-9-]{36}`).

**Error codes:** `wpcc_session_not_found`

### `POST /agent/sessions/{id}/close`
**Scope:** full  
**Description:** Close an active agent session.

**Error codes:** `wpcc_session_not_found`, `wpcc_session_close_failed`, `wpcc_invalid_session_status`

### `POST /agent/tasks`
**Scope:** full  
**Description:** Create an agent task under an existing session.

**Request body:**
```json
{
  "session_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "source": "claude",
  "user_prompt": "Please fix the homepage hero section layout"
}
```

**Response:**
```json
{
  "task_id": "b2c3d4e5-f6a7-8901-bcde-f12345678901",
  "session_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "source": "claude",
  "user_prompt": "Please fix the homepage hero section layout",
  "status": "draft",
  "created_at": "2026-06-12T10:30:00Z"
}
```

**Error codes:** `wpcc_task_create_failed`, `wpcc_session_not_found`, `wpcc_missing_user_prompt`, `wpcc_invalid_task_source`

### `GET /agent/tasks`
**Scope:** read_only  
**Description:** List agent tasks, newest first.

### `GET /agent/tasks/{id}`
**Scope:** read_only  
**Description:** Get an agent task by UUID.

**Error codes:** `wpcc_task_not_found`

### `POST /agent/tasks/{id}/status`
**Scope:** full  
**Description:** Update an agent task status.

**Request body:**
```json
{
  "status": "analyzing"
}
```
**Valid statuses:** `draft`, `analyzing`, `patch_proposed`, `completed`, `failed`, `cancelled`

**Error codes:** `wpcc_task_not_found`, `wpcc_task_update_failed`, `wpcc_invalid_task_status`

### `POST /agent/actions`
**Scope:** full  
**Description:** Record an agent action under an existing session and task. Always created with `status: proposed`. Metadata only; does not execute or create patches.

**Request body:**
```json
{
  "session_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "task_id": "b2c3d4e5-f6a7-8901-bcde-f12345678901",
  "type": "code_change",
  "title": "Fix hero section CSS grid",
  "description": "The hero section grid is broken on mobile. Need to update the CSS grid-template-columns."
}
```
**Valid types:** `investigate`, `recommendation`, `diagnosis`, `code_change`, `configuration_change`, `maintenance`

**Response:**
```json
{
  "action_id": "c3d4e5f6-a7b8-9012-cdef-123456789012",
  "session_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "task_id": "b2c3d4e5-f6a7-8901-bcde-f12345678901",
  "type": "code_change",
  "title": "Fix hero section CSS grid",
  "status": "proposed",
  "created_at": "2026-06-12T10:30:00Z"
}
```

**Error codes:** `wpcc_action_create_failed`, `wpcc_missing_action_title`, `wpcc_invalid_action_type`

### `GET /agent/actions`
**Scope:** read_only  
**Description:** List agent actions, newest first.

### `GET /agent/actions/{id}`
**Scope:** read_only  
**Description:** Get an agent action by UUID.

**Error codes:** `wpcc_action_not_found`

### `POST /agent/actions/{id}/accept`
**Scope:** full  
**Description:** Accept a proposed action (status: `proposed` → `accepted`).

**Error codes:** `wpcc_action_not_found`, `wpcc_invalid_action_status`

### `POST /agent/actions/{id}/reject`
**Scope:** full  
**Description:** Reject a proposed action (status: `proposed` → `rejected`).

### `POST /agent/actions/{id}/cancel`
**Scope:** full  
**Description:** Cancel a proposed or accepted action (status: `proposed`|`accepted` → `cancelled`).

### `POST /agent/actions/{id}/complete`
**Scope:** full  
**Description:** Mark an accepted action as completed (status: `accepted` → `completed`).

### `POST /agent/plans`
**Scope:** full  
**Description:** Create a plan under an existing session and task. Optionally linked to an existing action via `action_id`.

**Request body:**
```json
{
  "session_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "task_id": "b2c3d4e5-f6a7-8901-bcde-f12345678901",
  "title": "Fix Hero Section",
  "objective": "Update the homepage hero section to use CSS Grid properly on mobile.",
  "status": "pending_review",
  "action_id": "c3d4e5f6-a7b8-9012-cdef-123456789012",
  "steps": [
    { "title": "Read current hero CSS", "status": "pending" },
    { "title": "Create patch with grid fix", "status": "pending" },
    { "title": "Verify on mobile viewport", "status": "pending" }
  ]
}
```
**Valid statuses:** `draft`, `pending_review`

**Response:** Plan object with `plan_id`, ordered `steps`, and metadata.

**Error codes:** `wpcc_plan_create_failed`, `wpcc_invalid_plan`, `wpcc_invalid_plan_steps`, `wpcc_invalid_plan_step`

### `GET /agent/plans`
**Scope:** read_only  
**Description:** List agent plans with ordered steps, newest first.

### `GET /agent/plans/{id}`
**Scope:** read_only  
**Description:** Get an agent plan and its ordered steps by UUID.

**Error codes:** `wpcc_plan_not_found`

### `POST /agent/plans/{id}/approve`
**Scope:** full  
**Description:** Approve a `pending_review` or `draft` plan.

### `POST /agent/plans/{id}/reject`
**Scope:** full  
**Description:** Reject a `pending_review` or `draft` plan.

### `POST /agent/plans/{id}/cancel`
**Scope:** full  
**Description:** Cancel a `draft`, `pending_review`, or `approved` plan.

---

## Site Intelligence

### `GET /site-intelligence`
**Scope:** read_only  
**Description:** WordPress, PHP, theme, plugin, cache, and server information. Results from `SiteScanner::scan()`.

**Response:**
```json
{
  "wordpress": { "version": "6.5", "multisite": false, "debug_enabled": true },
  "php": { "version": "8.2.10", "memory_limit": "256M", "max_execution_time": 300 },
  "theme": { "name": "Twenty Twenty-Five", "slug": "twentytwentyfive", "version": "1.0" },
  "plugins": [...],
  "woocommerce": { "active": true, "version": "8.9.0" },
  "server": {
    "shell_exec_enabled": true,
    "proc_open_enabled": true,
    "wp_cli_available": true
  },
  "cache": { "object_cache": false, "page_cache": false }
}
```

### `GET /diagnostics`
**Scope:** read_only  
**Description:** Performance, security, or WooCommerce diagnostics. Use `?type=performance|security|woocommerce`.

### `GET /diagnostics/debug-log`
**Scope:** read_only  
**Description:** Tail of `wp-content/debug.log`. Use `?lines=N`. Secrets in log lines are redacted as `[REDACTED_SECRET]`.

**Error codes:** `wpcc_no_debug_log`, `wpcc_unreadable_debug_log`

---

## Files & Search

### `GET /files`
**Scope:** read_only  
**Description:** List files and directories under `themes/`, `plugins/`, or `mu-plugins/`. Use `?path=`. Blocked paths (`.env`, `vendor/`, `.git/`, keys, etc.) are omitted.

### `GET /files/meta`
**Scope:** read_only  
**Description:** Metadata (size, modified, hash, writable) for a single file. Use `?path=`. Blocked paths return `wpcc_file_blocked`.

### `GET /files/content`
**Scope:** read_only  
**Description:** Read a file's contents (capped at 1 MB). Use `?path=`. Blocked paths return `wpcc_file_blocked`; secrets are redacted as `[REDACTED_SECRET]`.

**Error codes:** `wpcc_not_found`, `wpcc_file_blocked`, `wpcc_binary_file`, `wpcc_file_too_large`

### `GET /search`
**Scope:** read_only  
**Description:** Search code by text, function, class, or hook name. Use `?q=&path=&type=text|function|class|hook`. Blocked files are skipped and secrets in matches are redacted as `[REDACTED_SECRET]`.

**Error codes:** `wpcc_empty_query`

---

## Patches

### `GET /patches`
**Scope:** read_only  
**Description:** List all patches (summary records).

### `POST /patches`
**Scope:** full  
**Description:** Create a patch proposal. If `plan_id` is supplied, the plan must exist and be approved.

**Request body:**
```json
{
  "files": [
    {
      "path": "themes/twentytwentyfive/style.css",
      "modified": ".hero-section {\n  display: grid;\n  grid-template-columns: 1fr 1fr;\n}"
    }
  ],
  "explanation": "Fix hero section CSS grid for mobile responsiveness",
  "risk_level": "low",
  "source": "claude",
  "session_id": "a1b2c3d4-...",
  "task_id": "b2c3d4e5-...",
  "plan_id": "d4e5f6a7-..."
}
```

**Response:** Patch record including `patch_id`, diff, and status.

**Error codes:** `wpcc_no_files`, `wpcc_no_changes`, `wpcc_path_not_allowed`, `wpcc_invalid_risk_level`, `wpcc_plan_not_found`, `wpcc_plan_not_approved`

### `GET /patches/{id}`
**Scope:** read_only  
**Description:** Get a single patch record, including diff and status history.

**Error codes:** `wpcc_patch_not_found`, `wpcc_patch_corrupt`

### `POST /patches/{id}/approve`
**Scope:** full  
**Description:** Approve a pending patch.

**Error codes:** `wpcc_patch_not_found`, `wpcc_invalid_status`

### `POST /patches/{id}/reject`
**Scope:** full  
**Description:** Reject a pending or approved patch.

### `POST /patches/{id}/apply`
**Scope:** full  
**Description:** Apply an approved patch (auto-snapshots affected files, verifies, auto-reverts on failure).

**Error codes:** `wpcc_file_changed`, `wpcc_not_writable`

### `POST /patches/{id}/rollback`
**Scope:** full  
**Description:** Roll back an applied patch using its snapshot(s), with hash verification.

**Error codes:** `wpcc_no_snapshots`, `wpcc_snapshot_not_found`, `wpcc_snapshot_missing`, `wpcc_restore_failed`, `wpcc_rollback_verification_failed`

---

## Recommendations

### `GET /recommendations`
**Scope:** read_only  
**Description:** List deterministic recommendations. Filters: `type`, `severity`, `status`, `source`, `limit`, `offset`.

### `GET /recommendations/{id}`
**Scope:** read_only  
**Description:** Get a recommendation by UUID.

**Error codes:** `wpcc_recommendation_not_found`

### `POST /recommendations/scan`
**Scope:** full  
**Description:** Run a deterministic recommendation scan. Does not patch content or execute operations.

**Error codes:** `wpcc_recommendation_scan_failed`

### `POST /recommendations/{id}/dismiss`
**Scope:** full  
**Description:** Dismiss an open recommendation.

### `POST /recommendations/{id}/resolve`
**Scope:** full  
**Description:** Resolve an open recommendation.

### `POST /recommendations/{id}/convert-to-action`
**Scope:** full  
**Description:** Convert an open recommendation to a proposed action.

**Request body:**
```json
{
  "session_id": "a1b2c3d4-...",
  "task_id": "b2c3d4e5-..."
}
```

### `POST /recommendations/{id}/create-plan`
**Scope:** full  
**Description:** Create a `pending_review` plan for a recommendation that has been converted to an action.

---

## Operations

### `GET /operations`
**Scope:** read_only  
**Description:** List all supported WordPress operations (metadata only). Returns the `OperationRegistry::get_operations()` array.

### `GET /operations/{id}`
**Scope:** read_only  
**Description:** Get detailed metadata for a specific operation by ID (e.g., `content_manage`).

**Error codes:** `wpcc_operation_not_found`

### `POST /operations/{operation_id}/run`
**Scope:** full (except `database_inspect`, which accepts read_only)  
**Description:** Execute an operation directly. See [OPERATIONS.md](OPERATIONS.md) for per-operation payload formats.

Run endpoints:
- `POST /operations/content_seed/run`
- `POST /operations/acf_seed/run`
- `POST /operations/cf7_seed/run`
- `POST /operations/woo_product_seed/run`
- `POST /operations/safe_search_replace/run`
- `POST /operations/media_import/run`
- `POST /operations/safe_updates/run`
- `POST /operations/capability_manage/run`
- `POST /operations/database_inspect/run` (read_only tokens OK)
- `POST /operations/content_manage/run`
- `POST /operations/snapshot_manage/run`
- `POST /operations/theme_manage/run`
- `POST /operations/plugin_manage/run`
- `POST /operations/option_manage/run`
- `POST /operations/wp_cli_bridge/run`

---

## Operation Workflow

### `POST /operations/requests`
**Scope:** full  
**Description:** Create an operation request for human review.

**Request body:**
```json
{
  "operation_id": "plugin_manage",
  "payload": {
    "action": "plugin_install",
    "slug": "wordpress-seo"
  },
  "session_id": "a1b2c3d4-...",
  "task_id": "b2c3d4e5-...",
  "action_id": "c3d4e5f6-...",
  "plan_id": "d4e5f6a7-..."
}
```

**Response (201):**
```json
{
  "request_id": "e5f6a7b8-...",
  "operation_id": "plugin_manage",
  "status": "pending_review",
  "risk_level": "medium",
  "created_at": "2026-06-12T10:30:00Z"
}
```

**Error codes:** `wpcc_request_create_failed`, `wpcc_operation_not_found`

### `GET /operations/requests`
**Scope:** read_only  
**Description:** List operation requests. Filters: `status`, `operation_id`, `session_id`, `task_id`, `plan_id`, `limit`, `offset`.

### `GET /operations/requests/{id}`
**Scope:** read_only  
**Description:** Get detailed metadata for a specific operation request.

**Error codes:** `wpcc_request_not_found`

### `POST /operations/requests/{id}/approve`
**Scope:** full  
**Description:** Approve a pending operation request.

**Error codes:** `wpcc_request_not_found`

### `POST /operations/requests/{id}/reject`
**Scope:** full  
**Description:** Reject a pending operation request.

### `POST /operations/requests/{id}/execute`
**Scope:** full  
**Description:** Execute an approved operation request.

**Error codes:** `wpcc_request_not_found`, `wpcc_request_not_approved`

### `POST /operations/requests/{id}/queue`
**Scope:** full  
**Description:** Queue an approved operation request for later execution. Accepts optional `priority` (default: 10).

**Request body:**
```json
{
  "priority": 5
}
```

### `GET /operations/queue`
**Scope:** read_only  
**Description:** List queued operations. Filters: `status`, `operation_id`, `request_id`, `limit`, `offset`.

### `GET /operations/queue/{id}`
**Scope:** read_only  
**Description:** Get detailed metadata for a specific queue item.

### `POST /operations/queue/{id}/run`
**Scope:** full  
**Description:** Manually execute a queued operation.

### `POST /operations/queue/{id}/cancel`
**Scope:** full  
**Description:** Cancel a queued operation.

### `POST /operations/queue/{id}/retry`
**Scope:** full  
**Description:** Retry a failed queued operation (up to `max_attempts`).

### `POST /operations/queue/process`
**Scope:** full  
**Description:** Manually trigger the background worker to process pending queue items.

**Request body:**
```json
{
  "limit": 5
}
```

### `GET /operations/results`
**Scope:** read_only  
**Description:** List operation execution results. Filters: `operation_id`, `queue_id`, `request_id`, `status`, `limit`, `offset`.

### `GET /operations/results/{id}`
**Scope:** read_only  
**Description:** Get detailed execution history for a specific operation result.

**Error codes:** `wpcc_result_not_found`

---

## AI Integration

### `GET /ai-clients`
**Scope:** read_only  
**Description:** List all registered AI clients with compatibility, status, and configuration support.

### `GET /ai-clients/{client}/config`
**Scope:** read_only  
**Description:** Generate MCP configuration for a specific AI client (`claude`, `codex`, `gemini`, etc.).

### `GET /claude/config`
**Scope:** read_only  
**Description:** Generate a dynamic Claude Desktop MCP configuration block.

### `GET /claude/discovery`
**Scope:** read_only  
**Description:** Claude discovery metadata: server info, tools, resources, capabilities, approval awareness, and WP-CLI status.

### `GET /claude/tools`
**Scope:** read_only  
**Description:** Claude-friendly tool grouping with approval and capability metadata per tool.

### `GET /claude/prompts`
**Scope:** read_only  
**Description:** Claude-specific helper prompt templates: inspect site, review recommendations, create content, maintenance, database review.

---

## MCP Server

### `POST /mcp`
**Scope:** read_only  
**Description:** MCP JSON-RPC 2.0 endpoint. Accepts standard JSON-RPC requests (initialize, tools/list, tools/call, resources/list, resources/read).

**Request body (JSON-RPC):**
```json
{
  "jsonrpc": "2.0",
  "method": "tools/list",
  "id": 1
}
```

**Error codes:** `wpcc_missing_token`

---

## Additional Agent Endpoints

### `GET /agent/timeline`
**Scope:** read_only  
**Description:** Unified traceable timeline of the agent lifecycle. Supports filters: `session_id`, `task_id`, `action_id`, `plan_id`, `patch_id`, `limit`, `offset`.

### `GET /agent/tree`
**Scope:** read_only  
**Description:** Hierarchical agent runtime tree (Session → Task → Action → Plan → Patch). Supports filters: `session_id`, `task_id`, `plan_id`.

---

## Common Error Codes

| Code | HTTP Status | Description |
|---|---|---|
| `wpcc_missing_token` | 401 | Missing `Authorization: Bearer <token>` header |
| `wpcc_invalid_token` | 401 | API token is invalid |
| `wpcc_token_expired` | 401 | API token has expired |
| `wpcc_token_revoked` | 401 | API token has been revoked |
| `wpcc_insufficient_scope` | 403 | Read-only token used for a write endpoint |
| `wpcc_not_found` | 404 | Requested resource not found |
| `wpcc_file_blocked` | 403 | Requested path is blocked for security |
| `wpcc_approval_required` | 403 | Operation requires approval via the request workflow |
| `wpcc_capability_denied` | 403 | Operation denied due to missing capability |
