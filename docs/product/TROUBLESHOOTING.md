# Troubleshooting — WP Command Center

Common issues, error messages, and their solutions.

---

## 1. "Missing capability: content.manage"

**Symptom:** An operation returns an error containing `Missing capability`
or a specific capability name like `content.manage`, `plugin.manage`, etc.

**Cause:** The API token used for the request does not have the required
capability assigned. Every tool in the platform requires a specific
capability — the read-only/full scope on the token is separate from
capability assignments.

**Solution:**
Assign the missing capability to the token:
- Via the REST API: `POST /wp-json/wp-command-center/v1/operations/capability_manage/run` with action `capability_assign`.
- Via the WordPress admin: an admin can manage capabilities through the
  platform's capability management interface.

---

## 2. "Operation requires approval"

**Symptom:** An operation is queued but does not execute. The status
remains `pending_approval`.

**Cause:** The `wpcc_enforce_approval` option is enabled, and this
operation is flagged as `requires_approval: true` (typical for medium
and high risk operations like content creation, plugin changes, option
updates, search-and-replace).

**Solution:**
**Option A — Follow the approval workflow:**
1. Create the operation request (this queues it as `pending_approval`).
2. Approve the request explicitly (via admin or API).
3. The operation then executes automatically.

**Option B — Disable approval enforcement (not recommended):**
Disable `wpcc_enforce_approval` in the platform options. This removes
the human-in-the-loop check and allows operations to execute directly
after creation. Use only in development/staging environments.

---

## 3. "MCP connection failed"

**Symptom:** The AI client (Claude Desktop) fails to connect or the
connection test in AI Integrations → Configuration reports failures.

**Checklist:**

1. **MCP URL is correct.** Verify the URL in your config matches:
   `https://yoursite.com/wp-json/wp-command-center/v1/mcp`
   If using localhost, ensure the port and path are correct.

2. **WordPress REST API is accessible.** Test directly:
   ```bash
   curl -s https://yoursite.com/wp-json/wp-command-center/v1/health
   ```
   Should return `{"status":"ok"}`. If not, check that:
   - WordPress permalinks are set to anything except "Plain".
   - No security plugin is blocking the REST API.
   - The WP Command Center plugin is activated.

3. **API token is valid and active.** Check in **Settings → API Tokens**
   that the token is Active (not Revoked, not Expired). If in doubt,
   generate a new token and update the config.

4. **No firewall/CDN blocking /wp-json/.** Some firewalls (Cloudflare
   WAF, ModSecurity, Wordfence) block JSON-RPC POST requests. Check
   server logs for 403/406 responses on `/wp-json/wp-command-center/v1/mcp`.
   Whitelist the endpoint if necessary.

5. **HTTPS/SSL issues.** If your site uses HTTPS but has a self-signed or
   invalid certificate, npx may reject the connection. Use a valid
   certificate or configure `NODE_TLS_REJECT_UNAUTHORIZED=0` in the
   MCP config env (development only).

6. **npx not installed.** MCP uses `npx` to run the client. Install
   Node.js (which includes npx) from https://nodejs.org.

---

## 4. "Invalid API token"

**Symptom:** REST API returns `401` with `wpcc_invalid_token`,
`wpcc_token_revoked`, or `wpcc_token_expired`.

**Cause:** The token used in the `Authorization: Bearer` header does not
match any stored token, has been revoked, or has passed its expiration date.

**Solution:**
1. Go to **Command Center → Settings → API Tokens**.
2. Check the status of your existing tokens (Active / Expired / Revoked).
3. If the token is revoked or expired, delete it and create a new one.
4. Update your AI client configuration with the new token.
5. Verify the token works:
   ```bash
   curl -s -H "Authorization: Bearer wpcc_YOUR_NEW_TOKEN" \
     "https://yoursite.com/wp-json/wp-command-center/v1/site-intelligence"
   ```

---

## 5. "Access to this path is blocked"

**Symptom:** File access operation returns an error about the path being
blocked or denied.

**Cause:** The requested file path is in the PathGuard deny list.
PathGuard restricts file access to allowed roots (`themes/`, `plugins/`,
`mu-plugins/`) relative to `wp-content/`. Files outside these directories
cannot be accessed via the API for security reasons.

**Solution:**
The file cannot be accessed through the API. PathGuard restrictions are
intentional security boundaries and cannot be bypassed. If you need to
edit a file outside the allowed directories (e.g. `wp-config.php`), you
must do so through direct filesystem access (SSH, FTP, or hosting file
manager).

---

## 6. "Patch rollback failed"

**Symptom:** Rolling back an applied patch returns an error.

**Cause:** The pre-apply snapshot for the affected file(s) is corrupted
or missing. This can happen if the snapshot storage directory was
manually deleted, file permissions changed, or the snapshot file was
truncated.

**Solution:**
1. Check if a manual backup of the file exists (hosting backup, version
   control, or manual copy).
2. Restore the file from the backup.
3. If no backup exists, review the current file contents and manually
   correct any issues caused by the patch.
4. To prevent this in the future, ensure the `wp-content/uploads/wpcc-tokens/`
   directory has write permissions for the web server and is included
   in your backup strategy.

---

## 7. "Queue item stuck in running"

**Symptom:** An operation in the queue shows status `running` for longer
than expected (more than 5 minutes) and does not complete.

**Cause:** The worker process that picked up the operation crashed mid-
execution (PHP timeout, memory limit, fatal error, or server restart).
The transient lock held by the worker prevents other workers from
picking it up.

**Solution:**
1. **Wait 5 minutes.** The transient lock automatically expires after 5
   minutes. After that, the item will be picked up by the next worker.
2. **Cancel and retry.** If you have access to the queue management,
   cancel the stuck item and retry the operation.
3. **Check server logs.** Look for PHP fatal errors or memory exhaustion
   messages around the time the operation was queued. Increase
   `memory_limit` or `max_execution_time` if necessary.
4. **Restart the queue.** If many items are stuck, there may be a PHP
   process stuck. Restarting PHP-FPM or the web server will clear all
   worker processes and release locks.

---

## 8. "Database size growing"

**Symptom:** The WordPress database is growing unexpectedly large.

**Cause:** The `wpcc_operation_results` and `wpcc_operation_queue`
tables accumulate historical data over time. Every operation execution
stores results, and queue items are retained after completion for audit
purposes.

**Solution:**
Use the system cleanup endpoint to prune old records:
```bash
curl -s -X POST \
  -H "Authorization: Bearer wpcc_YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "resources": ["queue_items", "results"],
    "older_than_days": 30,
    "dry_run": false,
    "confirm": "CLEANUP"
  }' \
  "https://yoursite.com/wp-json/wp-command-center/v1/system/cleanup"
```

This removes queue items and operation results older than 30 days.
Adjust `older_than_days` as needed. Run with `dry_run: true` first
to preview what would be deleted.

On production sites, the confirmation phrase must be `DELETE PRODUCTION DATA`
and `allow_production` must be set to `true`.

---

## 9. "WP-CLI command not available"

**Symptom:** The `wp_cli_bridge` operation reports that WP-CLI is not
available or commands cannot be executed.

**Cause:** Either the `wp` binary is not found on the server or
`proc_open` is disabled in the PHP configuration.

**Solution:**
1. **Install WP-CLI.** If `wp` is not installed, follow the installation
   guide at https://wp-cli.org/#installing. Verify with:
   ```bash
   which wp
   wp --info
   ```
2. **Check proc_open.** Some hosts disable `proc_open` for security.
   Check `phpinfo()` or run:
   ```bash
   php -r "echo function_exists('proc_open') ? 'enabled' : 'disabled';"
   ```
   If disabled, contact your hosting provider or use alternative
   operations (plugin_manage, option_manage, etc.) that do not
   require WP-CLI.

---

## 10. "401 Unauthorized"

**Symptom:** Every REST API request returns `401` with
`wpcc_missing_token`.

**Cause:** The request does not include an `Authorization` header,
or the header format is incorrect.

**Solution:**
Ensure every request includes a properly formatted bearer token header:
```
Authorization: Bearer wpcc_YOUR_TOKEN_HERE
```

Common mistakes:
- Using `Bearer:` instead of `Bearer` (no colon after Bearer).
- Using `bearer` (lowercase) — the server expects `Bearer` with capital B.
- Missing the space between `Bearer` and the token.
- Placing the token in the wrong header (e.g. `X-API-Key`).
- Using Basic auth instead of Bearer.

Verify with curl:
```bash
curl -v -H "Authorization: Bearer wpcc_YOUR_TOKEN" \
  "https://yoursite.com/wp-json/wp-command-center/v1/site-intelligence"
```

The `-v` flag shows the full request/response including the
`Authorization` header being sent.
