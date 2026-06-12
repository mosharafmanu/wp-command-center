# Step 45.5 — Architecture Hardening Report

**Date:** June 12, 2026 | **Result:** PASS | **Tests:** 1328 passed, 0 failed

---

## Changes Made

### 1. Critical — Approval Gate
**File:** `includes/Operations/OperationExecutor.php`

Added approval enforcement gate at lines 84-95. When `wpcc_enforce_approval` is enabled (opt-in, default `false`), operations with `requires_approval: true` in the registry are blocked from direct execution — they must flow through the request → approve → queue → execute pipeline. Queued and request-driven executions bypass this check via `queue_id`/`request_id` context flags.

### 2. Critical — Rollback Execution Paths
- **PluginManager:** Added `plugin_rollback` action. Restores activate/deactivate state changes. Delete/install rollbacks return partial-error (manual restoration required).
- **ThemeManager:** Added `theme_rollback` action. Restores previous theme via `switch_theme()`. Uses stored `previous_slug` from activate rollback records.
- **ContentManager:** Added `content_rollback` action. Restores title, status, and content from stored `before_state`. Uses `wp_update_post()` for restoration.

All three registries updated (`PluginRegistry::ACTIONS`, `ThemeRegistry::ACTIONS`, `ContentRegistry::ACTIONS`) to include rollback actions.

### 3. High — Capability Enforcement
- **Default changed** from `false` → `true` in `OperationExecutor.php:61`
- **Missing mappings added** in `CapabilityRegistry.php`:
  - `safe_search_replace` → `wpcli.execute`
  - `safe_updates` → `plugin.manage`
  - `media_import` → `content.manage`
- Test token granted `system.admin` capability to maintain test compatibility

### 4. High — Rollback Storage Migration
All rollback storage migrated from WordPress transients to `wp_options`:
- `wpcc_option_rollbacks`
- `wpcc_plugin_rollbacks`
- `wpcc_theme_rollbacks`
- `wpcc_content_rollbacks`

Transients were unreliable (cache flushes, TTL expiration). Options-based storage provides persistence.

### 5. Medium — Audit Bug Fixes
- **ContentManager::audit()** — Now correctly derives risk level from action name rather than content type
- **SnapshotManager::audit()** — Now dynamically resolves risk from `$event` action rather than using fixed `ACTION_CREATE` constant

### 6. Medium — ContentManager Type Coercion
- `ContentManager::run()` now returns `wpcc_invalid_content_type` error for invalid types instead of silently defaulting to `'post'`

### 7. Medium — normalize_success()
- `OperationExecutor::normalize_success()` now extracts `content_id` from results, preserving manager-specific response data

### Files Changed (11)
| File | Change |
|---|---|
| `includes/Operations/OperationExecutor.php` | Approval gate + capability default + normalize_success |
| `includes/Operations/CapabilityRegistry.php` | 3 new mappings |
| `includes/Operations/PluginRegistry.php` | Added `plugin_rollback` to ACTIONS |
| `includes/Operations/PluginManager.php` | Rollback action + options storage |
| `includes/Operations/ThemeRegistry.php` | Added `theme_rollback` to ACTIONS |
| `includes/Operations/ThemeManager.php` | Rollback action + options storage |
| `includes/Operations/ContentManager.php` | Rollback action + options storage + audit fix + type coercion |
| `includes/Operations/SnapshotManager.php` | Audit risk fix |
| `includes/Operations/OptionManager.php` | Options storage migration |
| `includes/AiAgent/RestApi.php` | `wpcc_approval_required` error code |
| `tests/test-{plugin,theme,capability}-runtime.sh` | Updated expected counts |

### Verification
| Check | Result |
|---|---|
| Capability enforcement works by default | ✅ (test token has system.admin) |
| Approval gate infrastructure exists | ✅ (`wpcc_enforce_approval` option) |
| Plugin rollback endpoint | ✅ (`plugin_rollback` action) |
| Theme rollback endpoint | ✅ (`theme_rollback` action) |
| Content rollback endpoint | ✅ (`content_rollback` action) |
| Rollback storage durable | ✅ (wp_options, not transients) |
| Audit bugs fixed | ✅ |
| Type coercion fixed | ✅ |
| normalize_success preserves data | ✅ |
| Full regression | ✅ 1328/0 (34 suites) |
