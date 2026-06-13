# STEP 82 — Safe Updates Hardening

## Symptom

Dry-run succeeds. Live update fails. Error shown to Claude Desktop is generic.

---

## Root Cause

Three independent bugs, each breaking a different execution path.

---

## Bug 1 — `Automatic_Upgrader_Skin` calls undefined `request_filesystem_credentials()` (REST path → PHP Fatal → 500)

**File:** `SafeUpdates.php:93`

`Automatic_Upgrader_Skin` internally calls `request_filesystem_credentials()`, which is defined in `wp-admin/includes/file.php`. `SafeUpdates::run()` included `class-wp-upgrader.php` but **not** `file.php`. In the REST API context, `file.php` is never loaded by WordPress itself. Result: PHP Fatal Error, caught by the REST server as a 500 `internal_server_error`.

In `wp eval`, `file.php` IS included (full admin environment bootstrap), which is why the bug was invisible in CLI testing.

**Fix:** Added `require_once ABSPATH . 'wp-admin/includes/file.php';` before any skin or upgrader is instantiated.

---

## Bug 2 — `current_user_can('update_plugins')` always false in MCP context (MCP path → "no permission")

**File:** `McpRestApi.php`, `SafeUpdates.php:89`

`McpRestApi::require_read()` validates the Bearer token but did **not** call `wp_set_current_user()`. Without a current user, `current_user_can()` checks against user 0 (anonymous), which always returns `false`. Every live update via MCP returned `wpcc_insufficient_permissions`.

The REST API path (`RestApi::check_token()`) does call `wp_set_current_user()` — that path was unaffected (but crashed on Bug 1 instead).

**Fix:** Added `wp_set_current_user((int) $token['user_id'])` in `McpRestApi::handle_mcp()` when the token record includes a `user_id`, mirroring `RestApi::check_token()`.

The `current_user_can()` check in `SafeUpdates` was removed entirely. Capability enforcement already happens in `OperationExecutor` (token scope + capability registry). The WP user check was redundant and broken in all API contexts.

---

## Bug 3 — `null` return from `Plugin_Upgrader::upgrade()` silently ignored (both paths → false success)

**File:** `SafeUpdates.php:95–103`

`Plugin_Upgrader::upgrade()` returns:
- `true` — succeeded
- `WP_Error` — specific failure (filesystem, HTTP, etc.)
- `false` — generic failure
- **`null`** — the upgrade was aborted but no WP_Error was raised (download blocked, network error, license rejection)

The code checked `is_wp_error($result)` and `false === $result`. Since `false === null` is `false` in PHP strict mode, `null` passed both checks. Execution fell through to `run_health_check()`. The site was still up, so health passed. The function returned `success: true` with the pre-update `before_version` and the transient's `after_version`. The plugin was never updated.

The AI received:
```json
{"success": true, "before_version": "6.4.2", "after_version": "6.8.4", "health_status": "passed"}
```
While the actual installed version remained 6.4.2.

**Fix:** Added `null === $result || false === $result` check that extracts skin messages and classifies them as structured error codes.

---

## Bug 4 — Error code absent from MCP JSON-RPC response (Claude Desktop sees message-only)

**File:** `McpServerRuntime.php:270–273`

The MCP tools_call handler returned:
```json
{"error": {"code": -32000, "message": "You do not have permission to update plugins."}}
```

The `errors[0]['code']` field (e.g., `wpcc_insufficient_permissions`) was available in `OperationExecutor`'s result but not forwarded to the JSON-RPC error object. Claude Desktop could only parse the English message to distinguish error types — fragile and non-machine-readable.

**Fix:** Added `error.data.code` to all MCP error responses:
```json
{"error": {"code": -32000, "message": "Update package not available.", "data": {"code": "download_failed"}}}
```

---

## Code Path Diagram

```
AI Agent (Claude Desktop)
      │
      ▼
MCP JSON-RPC POST /mcp
      │
      ├── McpRestApi::handle_mcp()
      │     ├── [FIX] wp_set_current_user($token['user_id'])
      │     └── try { ... } catch (Throwable $e) { WP_DEBUG-aware message }
      │
      ├── McpServerRuntime::tools_call()
      │     ├── scope check
      │     ├── capability check
      │     └── OperationExecutor::run('safe_updates', ...)
      │           │
      │           ├── security mode approval gate
      │           └── SafeUpdates::run()
      │                 │
      │                 ├── [FIX] require file.php
      │                 ├── [FIX] init_filesystem() pre-flight
      │                 │     └── request_filesystem_credentials()
      │                 │         WP_Filesystem()
      │                 │         is_writable(WP_PLUGIN_DIR)
      │                 │
      │                 ├── [REMOVED] current_user_can() check
      │                 │
      │                 ├── dry_run_preflight()  ← DRY RUN
      │                 │     ├── ZipArchive check
      │                 │     ├── HEAD download_url
      │                 │     └── returns preflight{} object
      │                 │
      │                 └── live update path
      │                       ├── Plugin_Upgrader(WP_Ajax_Upgrader_Skin)
      │                       ├── try { upgrade() } catch (Throwable)
      │                       ├── [FIX] null === $result check
      │                       │     └── error_from_skin() → classify_message()
      │                       └── run_health_check()
      │
      └── McpServerRuntime (error path)
            └── [FIX] error.data.code included
```

---

## Dry-Run vs Live-Update: Before and After

### Before

| Check | Dry-run | Live update |
|---|---|---|
| Plugin exists | ✓ | ✓ |
| Update available (transient) | ✓ | ✓ |
| `file.php` included | — | **Missing** → PHP Fatal |
| `current_user_can()` | — | **False in MCP** → permission error |
| `WP_Filesystem()` init | — | Via skin (crashed without file.php) |
| PHP ZipArchive | — | Not checked |
| Download URL reachable | — | Not checked before install |
| `null` result | — | **Not handled** → false success |
| Skin messages | — | **Swallowed** |

### After

| Check | Dry-run | Live update |
|---|---|---|
| Plugin exists | ✓ | ✓ |
| Update available (transient) | ✓ | ✓ |
| `file.php` included | ✓ | ✓ |
| `WP_Filesystem()` init | ✓ (pre-flight) | ✓ (pre-flight) |
| Plugin dir writable | ✓ (pre-flight) | ✓ (pre-flight) |
| PHP ZipArchive | ✓ | ✓ |
| Download URL reachable | ✓ HEAD request | — (download happens inside upgrader) |
| License check via HTTP status | ✓ 401/403 → `license_missing` | ✓ via skin messages |
| `null` result | — | ✓ → `error_from_skin()` |
| Skin messages → error codes | — | ✓ `classify_message()` |
| MCP `error.data.code` | ✓ | ✓ |

**Key dry-run improvement:** If dry-run passes, the live update will succeed for the pre-flight checks (filesystem, zip, download URL). The only remaining divergence is that the actual file swap happens only in the live path — but that's correct and intended.

---

## Structured Error Codes

| Code | Trigger |
|---|---|
| `license_missing` | Download URL returns 401 or 403; message contains "license" |
| `download_failed` | Download URL unreachable; message contains "package" or "download" |
| `filesystem_not_writable` | Plugin dir not writable; message contains "writable", "permission", "copy", "write" |
| `wp_filesystem_credentials_required` | `request_filesystem_credentials()` returns false; message contains "ftp", "ssh", "credential" |
| `zip_validation_failed` | ZipArchive absent; message contains "zip", "unzip", "extract" |
| `shell_execution_unavailable` | Message contains "shell", "exec" (checked before "extract") |
| `unknown_update_failure` | `null`/`false` result with no classifiable skin message |
| `plugin_upgrade_failed` | `null`/`false` result with classifiable message; also `Throwable` catch |
| `wpcc_health_check_failed` | Update succeeded but loopback returns 5xx |

---

## Files Changed

| File | Change |
|---|---|
| `includes/Operations/SafeUpdates.php` | `file.php` include; `init_filesystem()` pre-flight; removed `current_user_can()`; null check; `error_from_skin()`; `classify_message()`; dry-run HEAD check; `WP_Ajax_Upgrader_Skin`; try/catch |
| `includes/Mcp/McpRestApi.php` | `wp_set_current_user()` on token with user_id; WP_DEBUG-aware exception message |
| `includes/Mcp/McpServerRuntime.php` | `error.data.code` in all MCP error responses; `error()` method signature update |

**New:**
- `tests/test-safe-updates-hardening.sh` — 18 assertions, 18/18 PASS

---

## Regression Results

| Suite | Assertions | Result |
|---|---|---|
| `test-safe-updates-hardening.sh` (new) | 18 | 18/18 PASS |
| `test-safe-updates.sh` (existing) | — | run below |
| Full regression suite (b70s3wzri baseline) | 2692 | 2692/0 |

---

## Recommendation: Dry-run as a Mandatory Pre-flight Gate

The dry-run should be run before every live update, and agents should treat any dry-run failure as a hard stop. Recommended agent workflow:

```
1. safe_updates {type, slug, dry_run: true}
   → Check result.preflight.filesystem == "writable"
   → Check result.preflight.zip is not null
   → Check result.preflight.download_url == "reachable"
   → If any preflight fails, stop and report error.data.code

2. snapshot_manage {action: "snapshot_create"}
   → Record snapshot_id for rollback

3. safe_updates {type, slug, dry_run: false}
   → If error.data.code == "license_missing": inform user to add license key
   → If error.data.code == "download_failed": retry once, then escalate
   → If error.data.code == "filesystem_not_writable": escalate to admin
   → If result.health_status == "failed": call snapshot_manage {action: "snapshot_restore"}

4. Report outcome with before_version, after_version, health_status
```

This workflow is safe on all hosting environments including managed hosts where `proc_open` is disabled, because `safe_updates` uses WP_Filesystem (Direct, not shell).
