# WP Command Center — Security Guide

## Overview

WP Command Center is designed on the assumption that an external AI agent with an API token is inherently untrusted. Every security layer — capability gates, approval gates, path guards, secret redaction, audit trails, and rollback — exists to contain and audit that untrusted principal.

---

## 1. Capability Model

### 1.1 Architecture

Capabilities are managed by `Operations/CapabilityRegistry.php`. Each operation maps to a required capability. API tokens are assigned capabilities individually. When `wpcc_enforce_capabilities` is enabled (default: `true`), every operation execution validates the token has the required capability before allowing it to proceed.

### 1.2 Nine Capabilities

| Capability ID | Controls |
|---|---|
| `content.manage` | Content creation, updates, deletion, publishing, scheduling, taxonomy, featured images |
| `database.inspect` | Read-only database inspection (health, size, tables, indexes, orphans) |
| `plugin.manage` | Plugin listing, installation, activation, deactivation, updates, deletion |
| `theme.manage` | Theme listing, installation, activation, updates, deletion |
| `option.manage` | Reading and updating registered WordPress options |
| `snapshot.manage` | Creating, listing, verifying, and restoring file snapshots |
| `wpcli.execute` | Executing WP-CLI commands and safe search/replace |
| `capability.admin` | Managing capability assignments for other tokens |
| `system.admin` | Full platform access — can only be assigned via direct configuration, never via the capability manager |

### 1.3 Operation-to-Capability Mapping

Defined in `CapabilityRegistry::OPERATION_MAP`:

| Operation | Required Capability |
|---|---|
| `content_manage` | `content.manage` |
| `media_import` | `content.manage` |
| `database_inspect` | `database.inspect` |
| `plugin_manage` | `plugin.manage` |
| `safe_updates` | `plugin.manage` |
| `theme_manage` | `theme.manage` |
| `option_manage` | `option.manage` |
| `snapshot_manage` | `snapshot.manage` |
| `wp_cli_bridge` | `wpcli.execute` |
| `safe_search_replace` | `wpcli.execute` |
| `capability_manage` | `capability.admin` |
| `content_seed` | *none* (unrestricted) |
| `acf_seed` | *none* (unrestricted) |
| `cf7_seed` | *none* (unrestricted) |
| `woo_product_seed` | *none* (unrestricted) |

Seed operations are read-only/low-risk and do not require explicit capability assignment.

### 1.4 Validation Flow

`CapabilityRegistry::validate($operation_id, $subject, $subject_id)`:

1. Look up the required capability for the operation.
2. If unmapped → `allowed: true, reason: 'unrestricted'` (read-only/seed operations).
3. If `system.admin` is assigned to the token → `allowed: true` (superuser override).
4. If the required capability is assigned → `allowed: true`.
5. Otherwise → `allowed: false, reason: 'missing_capability'`.

Assignments are stored in the `wpcc_capability_assignments` WordPress option, keyed by `"subject:subject_id"`.

---

## 2. Approval Model

### 2.1 Overview

The approval gate is controlled by the `wpcc_enforce_approval` option (default: `false`, opt-in). When active, mutation operations that have `requires_approval: true` in their registry definition must go through the request → review → approval → queue pipeline. Direct execution via `OperationExecutor::run()` is blocked unless the context includes a valid `queue_id` or `request_id` indicating it arrived through the proper workflow.

### 2.2 Implementation

In `OperationExecutor::run()`:
```php
if (get_option('wpcc_enforce_approval', false) && !$is_queued && !$is_requested && !empty($operation['requires_approval'])) {
    return fail(...); // Returns wpcc_approval_required
}
```

### 2.3 Workflow

1. **Request** — Created via `OperationManager::create_request()`. Status: `pending_review`. Stored in `wpcc_operation_requests`.
2. **Approve** — `OperationManager::approve_request()` transitions to `approved` and automatically enqueues via `OperationQueue::enqueue()`.
3. **Reject** — `OperationManager::reject_request()` transitions to `rejected`.
4. **Queue** — Approved requests are automatically enqueued. They can also be manually queued via `POST /operations/requests/{id}/queue`.
5. **Execute** — The `OperationWorker` (WP-Cron) or manual `POST /operations/queue/{id}/run` runs the queued item through `OperationExecutor`.
6. **Result** — Outcome is persisted in `wpcc_operation_results`.

### 2.4 When Approval Is NOT Required

- `wpcc_enforce_approval` is disabled (default).
- The operation has `requires_approval: false` (e.g., `database_inspect`).
- The operation arrives via the request/approval workflow path (has `queue_id` or `request_id` in context) — the approval already happened upstream.

---

## 3. Audit Model

### 3.1 Append-Only Log

`Security/AuditLog.php` — all security-relevant actions are logged to `wp-content/uploads/wpcc-audit/audit.log` as newline-delimited JSON (JSONL).

**Format:**
```json
{"timestamp": 1234567890, "action": "patch.created", "context": {"patch_id": "...", "actor": {"type": "token", "id": "...", "label": "Claude Desktop"}}}
```

**Design properties:**
- Append-only — `FILE_APPEND | LOCK_EX`, never overwritten.
- Fail-silent — if the directory is unwritable, `record()` silently returns; auditing never breaks the operation.
- Protected directory — `.htaccess` + `index.php` prevent web access.

### 3.2 What Is Logged

Every operation execution records both start and end events:

| Event Pattern | When |
|---|---|
| `operation.{id}.started` | Before execution |
| `operation.{id}.completed` / `.failed` | After execution |
| `operation.execution.started` / `.completed` / `.failed` | Generic execution lifecycle |
| `operation.result.created` / `.completed` / `.failed` | Result persistence |
| `operation.queue.created` / `.running` / `.completed` / `.failed` / `.cancelled` | Queue lifecycle |
| `operation.worker.started` / `.completed` / `.locked` | Worker batch processing |
| `capability.denied` | Capability gate blocks an operation |
| `operation.approval.required` | Approval gate blocks an operation |
| `patch.created` / `.approved` / `.applied` / `.failed` / `.rolled_back` | Patch lifecycle |
| `session.created` / `.status_updated` | Agent sessions |
| `task.created` / `.status_updated` | Agent tasks |
| `mcp.request` / `.tool.invoke` / `.resource.read` / `.denied` | MCP activity |
| `health.verification.started` / `.completed` / `.failed` | Health checks |
| `system.environment.updated` | Environment mode changes |
| `security.content_redacted` | Secret redaction events |

### 3.3 Actor Resolution

Each audit entry identifies who performed the action via `AuditLog::resolve_actor()`:

- **Token:** `{ type: 'token', id, label }` — from API token validation
- **Admin:** `{ type: 'admin', user_id }` — from `get_current_user_id()`
- **Unknown:** `{ type: 'unknown' }` — fallback when neither is available

---

## 4. Rollback Model

### 4.1 Options-Based Per-Manager Rollback

Each manager class implements its own rollback:

- **`OptionManager`** — Before updating an option, saves the previous value. Rollback restores it. Audit trail records `option.update.rolled_back`.
- **`PluginManager`** — Before install/update/delete, takes a file snapshot of the plugin directory. Rollback restores from snapshot.
- **`ThemeManager`** — Same pattern: snapshot before mutation, restore on rollback.

### 4.2 File-Based Snapshots (Patch Pipeline)

For patch-based file changes, every `apply` action:

1. Takes a snapshot of every affected file before writing (`SnapshotManager::create()`).
2. If syntax verification fails, the in-memory original content is immediately restored — the file is never left broken.
3. Rollback (`RollbackManager::rollback()`) goes through three-stage verified restore:
   - **Pre-check:** Verify snapshot content matches its recorded hash before touching the live file.
   - **Safety backup:** Take a new snapshot of current (post-patch) contents.
   - **Post-check:** Verify restored file's hash matches the snapshot's hash.

---

## 5. Secret Redaction

### 5.1 Redactor Class

`Security/Redactor.php` — scans all text output (file contents, search results, context bundles, MCP responses) and replaces detected secrets with `[REDACTED_SECRET]`.

### 5.2 Detection Patterns (applied in order)

| Pattern | Examples Matched |
|---|---|
| PEM private key blocks | `-----BEGIN PRIVATE KEY-----` through `-----END PRIVATE KEY-----` |
| JWTs | `eyJ...` header.payload.signature (base64url pattern) |
| AWS access keys | `AKIA...` (16 uppercase alphanumeric chars) |
| Anthropic API keys | `sk-ant-...` (20+ chars) |
| OpenAI API keys | `sk-...` (excluding `sk-ant-` prefix) |
| Stripe keys | `sk_live_...`, `pk_live_...`, `rk_live_...` / `_test_` variants |
| Authorization headers | `Authorization: Bearer/Scheme ...` |
| Standalone bearer tokens | `Bearer ...` (8+ chars) |
| Basic-auth URLs | `://user:password@host` |
| Generic key/value assignments | `password=`, `secret=`, `api_key=`, `access_token=`, `auth_token=`, `client_secret=`, `private_key=` followed by value |

### 5.3 Where Redaction Runs

- `GET /files/content` — file contents
- `GET /search` — code search results
- `GET /diagnostics/debug-log` — debug log tail
- `GET /context` — composite context bundle
- `GET /agent/context` — agent context
- `GET /agent/timeline` — timeline summaries
- All MCP `resources/read` responses
- All MCP `tools/call` responses

### 5.4 Recursive Redaction

`redact_recursive()` traverses arrays recursively — every string value, at any depth, is scanned. Array keys are preserved. Each redaction is counted and logged as a `security.content_redacted` audit event.

---

## 6. Protected Files

### 6.1 PathGuard

`Security/PathGuard.php` — every file path the API touches goes through `PathGuard::resolve()`. No file operation bypasses this gate.

### 6.2 Allowed Roots

Only files under these three directories are ever accessible:
- `wp-content/themes/`
- `wp-content/plugins/`
- `wp-content/mu-plugins/`

Path traversal (`..`) is rejected outright.

### 6.3 Always Denied (even inside allowed roots)

| Pattern | Rationale |
|---|---|
| `wp-config.php`, `wp-config-sample.php` | Database credentials |
| `.htaccess` | Server configuration |
| `.env`, `.env.*` | Environment variable files with secrets |
| `.git/`, `.svn/` | Version control metadata |
| `node_modules/`, `vendor/` | Third-party dependency directories |
| `*.pem`, `*.key`, `*.p12`, `*.pfx`, `*.crt`, `*.cer` | Cryptographic keys and certificates |
| `id_rsa`, `id_ed25519` | SSH private keys |
| `credentials` (anywhere in path) | Credential files |
| `secrets.` (anywhere in path) | Secret files |
| `auth.json` | Authentication configuration |
| `service-account.json` | Cloud service account keys |

A blocked path returns `wpcc_file_blocked` (HTTP 403). A path that doesn't exist returns `wpcc_not_found`.

---

## 7. Token Security

### 7.1 Storage

`Security/AuthTokens.php` — tokens are stored as salted SHA-256 HMAC hashes at `wp-content/uploads/wpcc-tokens/manifest.json`.

**Hashing:**
```php
hash_hmac('sha256', $raw_token, wp_salt('auth'))
```

### 7.2 Token Format

`wpcc_` + 64 random alphanumeric characters generated via `wp_generate_password(64, false)`.

### 7.3 One-Time Display

The raw token is returned once at creation time. After that, only the first 12 characters are visible in the admin UI as a preview (`TOKEN_PREVIEW = 12`). The stored record contains only `token_hash` and `token_preview` — the raw token is never recoverable.

### 7.4 Directory Protection

`wp-content/uploads/wpcc-tokens/` is protected:
- `.htaccess` — `Require all denied` / `Deny from all`
- `index.php` — `<?php // Silence is golden.`

### 7.5 Validation (constant-time)

`validate($raw_token)` on every request:
1. Hash incoming token with the same algorithm.
2. Compare using `hash_equals()` — constant-time to prevent timing attacks.
3. Reject if `revoked` → `wpcc_token_revoked` (401).
4. Reject if past `expires_at` → `wpcc_token_expired` (401).
5. Reject if no match → `wpcc_invalid_token` (401).
6. On success, update `last_used_at`.

### 7.6 Token Scopes

| Scope | Access |
|---|---|
| `read_only` | GET endpoints only (inspection, context, diagnostics) |
| `full` | All endpoints (POST operations, patches, system changes) |

### 7.7 Token Lifecycle

- **Active** — usable
- **Revoked** — permanently disabled (status `revoked`)
- **Expired** — automatically expired if past `expires_at`
- **Deleted** — removed from manifest entirely

---

## 8. WordPress-Level Security

### 8.1 Admin UI Access

`Security/Capabilities.php` requires the `manage_options` WordPress capability for all admin UI interactions — only Administrators can access the Command Center interface.

### 8.2 REST API Authentication

Every REST endpoint enforces token validation via `AuthTokens::validate()`. The permission callback is the first thing that runs — an invalid/expired/revoked token never reaches the route handler.

### 8.3 Database Security

All database queries use `$wpdb->prepare()` with proper placeholders (e.g., `%s`, `%d`). No raw user input is interpolated into SQL strings. The `SearchReplace` operation is restricted to tables using the WordPress database prefix and uses WordPress's serialization-aware replacement.

### 8.4 File Size Limits

The Patch Engine enforces a 2MB maximum file size (`MAX_FILE_BYTES = 2 * MB_IN_BYTES`). The File Access API caps returned content at 1MB. The WP-CLI bridge caps command output at 1MB with a 30-second timeout.

### 8.5 Cleanup Manager Guards

`System/CleanupManager.php` implements environment-aware safety guards:

| Environment | Live cleanup requirements |
|---|---|
| `development` / `staging` | `confirm: "CLEANUP"` |
| `production` | `confirm: "DELETE PRODUCTION DATA"` AND `allow_production: true` |

`dry_run: true` (the default) counts what would be deleted without touching anything, requiring no confirmation.
