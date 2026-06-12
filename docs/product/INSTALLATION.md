# WP Command Center — Installation Guide

## Requirements

| Requirement | Minimum |
|---|---|
| PHP | 8.0+ |
| WordPress | 6.4+ |
| MySQL | 5.7+ (or MariaDB 10.3+) |
| Filesystem | Writable `wp-content/uploads/` directory |
| PHP Extensions | `json`, `mysqli` or `pdo_mysql`, `mbstring`, `curl` (recommended for full functionality) |
| WP-CLI (optional) | Required only for `wp_cli_bridge` operation; needs `shell_exec` + `proc_open` available |

> **Note:** The plugin header specifies `Requires PHP: 8.0` and `Requires at least: 6.4`.

---

## Installation Methods

### Method 1: Upload Plugin Zip

1. Download the plugin zip file.
2. In WordPress Admin, go to **Plugins → Add New → Upload Plugin**.
3. Choose the zip file and click **Install Now**.
4. Click **Activate**.

### Method 2: Manual Installation

1. Extract the plugin archive.
2. Upload the `wp-command-center` folder to `/wp-content/plugins/`.
3. In WordPress Admin, go to **Plugins**.
4. Find "WP Command Center" and click **Activate**.

### Method 3: CLI Installation (if WP-CLI is available)

```bash
wp plugin install wp-command-center.zip --activate
```

Or manually:

```bash
cp -r wp-command-center /path/to/wp-content/plugins/
wp plugin activate wp-command-center
```

---

## Activation

When the plugin is activated, `Core/Activator::activate()` runs:

1. **Database schema installation** — `Core/Schema::install()` creates/upgrades the following tables using `dbDelta()`:

   - `wpcc_patches` — Patch metadata index
   - `wpcc_snapshots` — Snapshot metadata index
   - `wpcc_agent_sessions` — Agent sessions
   - `wpcc_agent_tasks` — Agent tasks
   - `wpcc_agent_plans` — Agent plans
   - `wpcc_agent_plan_steps` — Plan steps
   - `wpcc_agent_actions` — Agent actions
   - `wpcc_operation_requests` — Operation requests
   - `wpcc_operation_queue` — Operation queue
   - `wpcc_operation_results` — Operation results
   - `wpcc_recommendations` — Recommendations
   - `wpcc_health_verifications` — Health verification results

2. **Legacy data migration** — One-time, idempotent migration of any existing JSON manifest data into the new index tables (guarded by `wpcc_migrated_v1` option).

3. **Storage directory creation** — Protected directories under `wp-content/uploads/`:

   - `wpcc-tokens/` — API token hashes
   - `wpcc-audit/` — Audit log (JSONL)
   - `wpcc-patches/` — Patch content (JSON files)
   - `wpcc-snapshots/` — File snapshots

   Each directory is protected with `.htaccess` (`Require all denied`) and a silent `index.php`.

4. **WP-Cron scheduling** — The `wpcc_process_operation_queue` cron hook is scheduled.

**Schema upgrades** happen automatically on plugin updates via `Schema::maybe_upgrade()` — no reactivation needed.

---

## Initial Setup

### 1. Navigate to Command Center

After activation, a **"Command Center"** menu appears in the WordPress admin sidebar (visible to Administrators only, requiring `manage_options` capability).

### 2. Access Settings

Go to **Command Center → Settings**.

### 3. Create Your First API Token

1. Click **Add Token**.
2. Enter a label (e.g., "Claude Desktop").
3. Choose the scope:
   - **Read-only** — Can call GET endpoints (inspection only, no mutations).
   - **Full access** — Can call all endpoints including patches, operations, and system changes.
4. Optionally set an expiration date.
5. Click **Create**.

**Save the raw token immediately** — it is shown only once. The token is stored as a salted SHA-256 HMAC hash (`hash_hmac('sha256', $raw_token, wp_salt('auth'))`). After creation, only the first 12 characters are visible as a preview.

Token format: `wpcc_` + 64 random alphanumeric characters.

### 4. Configure Environment Mode

Set the environment mode via **Command Center → Settings** or via the API:

```
POST /wp-json/wp-command-center/v1/system/environment
{ "mode": "development" | "staging" | "production" }
```

This affects the Cleanup Manager's safety guards. Defaults to `production` if unset or undetectable.

---

## Verification

### API Health Check

```bash
curl -H "Authorization: Bearer YOUR_TOKEN" \
  https://yoursite.com/wp-json/wp-command-center/v1/health
```

**Expected response (200 OK):**
```json
{
  "status": "healthy",
  "version": "0.1.0",
  "timestamp": 1234567890
}
```

### Test Connection in AI Integrations

1. Go to **Command Center → AI Integrations**.
2. Click **Test Connection**.
3. The system verifies the MCP endpoint is reachable and the token is valid.

### Verify from a Client

For Claude Desktop, generate configuration from **Command Center → AI Integrations → Claude → Generate Config**, paste into `claude_desktop_config.json`, and verify Claude can connect.

### Verify Operation Queue Worker

Check that the WP-Cron hook is scheduled:

```bash
wp cron event list | grep wpcc
```

Expected: `wpcc_process_operation_queue` scheduled at 5-minute intervals.

---

## Environment Modes

Three environment modes control how cautious the system is:

| Mode | Cleanup Safety | Behavior |
|---|---|---|
| `development` | Requires `confirm: "CLEANUP"` | Relaxed, allows live cleanup with confirmation |
| `staging` | Requires `confirm: "CLEANUP"` | Same as development |
| `production` | Requires `confirm: "DELETE PRODUCTION DATA"` AND `allow_production: true` | Maximum safety guards |

The current mode is visible as a color-coded banner on the Command Center dashboard. It can be changed via the admin UI or REST API.

---

## Upgrading

When the plugin code is updated (via WordPress admin update or manual file replacement):

1. `Core/Schema::maybe_upgrade()` compares the stored `wpcc_db_version` option against `Schema::DB_VERSION` (currently `2.2.0`).
2. If they differ, `Schema::install()` runs again — creating any new tables and migrating legacy data. Existing data is never lost.
3. No reactivation or manual intervention is needed.

---

## Deactivation

On deactivation (`Core/Deactivator::deactivate()`):

- The cron hook (`wpcc_process_operation_queue`) is unscheduled.
- **No data is deleted.** All database tables, stored files, tokens, and audit logs persist — reactivating restores full functionality with all history intact.

---

## Uninstallation

To completely remove the plugin and all its data:

1. Deactivate the plugin.
2. (Optional) Run `POST /system/cleanup` to prune terminal-state runtime records first.
3. Delete the plugin directory.
4. To remove stored data, delete these directories manually:
   ```
   wp-content/uploads/wpcc-tokens/
   wp-content/uploads/wpcc-audit/
   wp-content/uploads/wpcc-patches/
   wp-content/uploads/wpcc-snapshots/
   ```
5. To remove all database tables, drop all tables with the `wpcc_` prefix.
6. Delete these options from `wp_options`: `wpcc_db_version`, `wpcc_migrated_v1`, `wpcc_environment_mode`, `wpcc_enforce_capabilities`, `wpcc_enforce_approval`, `wpcc_capability_assignments`.
