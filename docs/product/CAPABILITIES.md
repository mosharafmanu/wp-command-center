# Capabilities Reference

WP Command Center uses a fine-grained capability system to control which tokens, roles, and integrations may execute which operations. Capabilities are defined in `includes/Operations/CapabilityRegistry.php` and enforced via `includes/Operations/CapabilityManager.php`.

---

## Capability Definitions

All 9 capabilities as defined in `CapabilityRegistry::ALL_CAPABILITIES`:

| Capability | Constant | Description |
|---|---|---|
| `content.manage` | `CAP_CONTENT_MANAGE` | Controls content_manage and media_import operations |
| `database.inspect` | `CAP_DATABASE_INSPECT` | Controls database_inspect operation (read-only) |
| `plugin.manage` | `CAP_PLUGIN_MANAGE` | Controls plugin_manage and safe_updates operations |
| `theme.manage` | `CAP_THEME_MANAGE` | Controls theme_manage operation |
| `option.manage` | `CAP_OPTION_MANAGE` | Controls option_manage operation |
| `snapshot.manage` | `CAP_SNAPSHOT_MANAGE` | Controls snapshot_manage operation |
| `wpcli.execute` | `CAP_WPCLI_EXECUTE` | Controls wp_cli_bridge and safe_search_replace operations |
| `capability.admin` | `CAP_CAPABILITY_ADMIN` | Controls capability_manage operation (prevents privilege escalation) |
| `system.admin` | `CAP_SYSTEM_ADMIN` | Master key — all operations allowed. Can only be assigned via direct configuration. |

---

## Operation-to-Capability Mapping

The `CapabilityRegistry::OPERATION_MAP` defines which capability is required for each operation:

| Operation | Required Capability |
|---|---|
| `content_manage` | `content.manage` |
| `database_inspect` | `database.inspect` |
| `plugin_manage` | `plugin.manage` |
| `theme_manage` | `theme.manage` |
| `option_manage` | `option.manage` |
| `snapshot_manage` | `snapshot.manage` |
| `wp_cli_bridge` | `wpcli.execute` |
| `safe_search_replace` | `wpcli.execute` |
| `safe_updates` | `plugin.manage` |
| `media_import` | `content.manage` |
| `capability_manage` | `capability.admin` |

**Unrestricted operations** (no capability required — these are intentionally unmapped in `OPERATION_MAP`):
- `content_seed`
- `acf_seed`
- `cf7_seed`
- `woo_product_seed`

When `CapabilityRegistry::validate()` encounters an unmapped operation, it returns `{ allowed: true, reason: "unrestricted" }`.

---

## Enforcement Mechanism

Capability enforcement is controlled by the WordPress option `wpcc_enforce_capabilities`:

- **Default value:** `false` (enforcement OFF)
- **Set to** `true` (string `"1"`) to enable enforcement
- Stored in the `wp_options` table as `wpcc_enforce_capabilities`

When enforcement is ON, every operation execution validates the calling token's assigned capabilities against the `OPERATION_MAP`. Operations not in the map are allowed regardless of enforcement status (seed operations).

When enforcement is OFF, all operations are allowed (subject to approval workflow and token scope).

**Checking current enforcement status (from code):**
```php
$response['capability_enforcement'] = (bool) get_option( 'wpcc_enforce_capabilities', false );
```

This value is exposed in `GET /agent/context` under `capability_enforcement`.

---

## Viewing Current Assignments

### Via REST API

Use the `capability_manage` operation:

```json
POST /wp-command-center/v1/operations/capability_manage/run
{
  "action": "capability_list",
  "subject": "token",
  "subject_id": "tk_a1b2c3d4e5f6"
}
```

Response:
```json
{
  "action": "capability_list",
  "summary": {
    "capabilities": ["content.manage", "database.inspect", "..."],
    "operation_map": { "content_manage": "content.manage", "..." },
    "assignment_count": 3,
    "subject_counts": { "token": 5, "role": 1 }
  }
}
```

### Via GET /agent/context

Call `GET /agent/context` with a valid token. The response includes:
- `capability_management_available: true`
- `assigned_capabilities: [...]` — capabilities assigned to the calling token
- `capability_enforcement: true/false`

**Get capabilities for a specific subject:**
```json
POST /wp-command-center/v1/operations/capability_manage/run
{
  "action": "capability_get",
  "subject": "token",
  "subject_id": "tk_a1b2c3d4e5f6"
}
```

Response:
```json
{
  "action": "capability_get",
  "subject": "token",
  "subject_id": "tk_a1b2c3d4e5f6",
  "capabilities": ["content.manage", "plugin.manage", "theme.manage"]
}
```

**Validate whether a subject can run a specific operation:**
```json
POST /wp-command-center/v1/operations/capability_manage/run
{
  "action": "capability_validate",
  "operation": "plugin_manage",
  "subject": "token",
  "subject_id": "tk_a1b2c3d4e5f6"
}
```

Response (allowed):
```json
{
  "action": "capability_validate",
  "allowed": true,
  "required_capability": "plugin.manage",
  "assigned": ["content.manage", "plugin.manage", "theme.manage"]
}
```

Response (denied):
```json
{
  "action": "capability_validate",
  "allowed": false,
  "required_capability": "plugin.manage",
  "assigned": ["content.manage"],
  "reason": "missing_capability"
}
```

---

## Assigning & Removing Capabilities

Capability assignments are stored in the WordPress option `wpcc_capability_assignments` as a key-value map:
```
"token:tk_abc123" => ["content.manage", "plugin.manage"]
"role:editor"     => ["database.inspect"]
```

### Assign a capability to a subject

```json
POST /wp-command-center/v1/operations/capability_manage/run
{
  "action": "capability_assign",
  "subject": "token",
  "subject_id": "tk_a1b2c3d4e5f6",
  "capability": "plugin.manage"
}
```

Response:
```json
{
  "action": "capability_assign",
  "subject": "token",
  "subject_id": "tk_a1b2c3d4e5f6",
  "capability": "plugin.manage",
  "assigned": true
}
```

This operation requires `capability.admin`. All assignments are audited (see Audit Log).

### Remove a capability from a subject

```json
POST /wp-command-center/v1/operations/capability_manage/run
{
  "action": "capability_remove",
  "subject": "token",
  "subject_id": "tk_a1b2c3d4e5f6",
  "capability": "plugin.manage"
}
```

Response:
```json
{
  "action": "capability_remove",
  "subject": "token",
  "subject_id": "tk_a1b2c3d4e5f6",
  "capability": "plugin.manage",
  "removed": true
}
```

---

## system.admin — Master Key

The `system.admin` capability acts as a wildcard. Any subject holding `system.admin` is implicitly granted all capabilities without needing individual assignments:

```php
// From CapabilityRegistry::validate():
$has_admin = in_array( self::CAP_SYSTEM_ADMIN, $assigned, true );
if ( $has_admin || in_array( $required, $assigned, true ) ) {
    return [ 'allowed' => true, ... ];
}
```

**system.admin cannot be assigned via the API.** Any attempt to assign it via `capability_assign` returns:

```json
{
  "code": "wpcc_cannot_assign_admin",
  "message": "system.admin can only be assigned via direct configuration."
}
```

To grant `system.admin`, manually add it to the `wpcc_capability_assignments` option:
```php
// Direct database or wp-cli only:
$assignments = get_option( 'wpcc_capability_assignments', [] );
$assignments['token:tk_abc123'] = [ 'system.admin' ];
update_option( 'wpcc_capability_assignments', $assignments, false );
```

---

## Token-Based vs Role-Based

The capability system supports two subject types:

### Token-Based (`subject: "token"`)
Capabilities are assigned to specific API tokens (created via Settings → API Tokens). The token's UUID (`subject_id`) is used as the key. On each request, the validated token's capabilities are checked against the operation being attempted.

### Role-Based (`subject: "role"`)
Capabilities can also be assigned to WordPress user roles (e.g., `role:administrator`, `role:editor`). When a token is linked to a WordPress user (via `user_id`), the user's roles may determine capability grants.

**Default inference:** The validation function in `CapabilityRegistry::validate()` currently supports `subject: "token"` as the primary path. The `subject` parameter defaults to `"token"` in `CapabilityManager`.

---

## Sub-Action Risk Levels

The `capability_manage` operation itself has per-sub-action risk:

| Sub-action | Risk | Description |
|---|---|---|
| `capability_list` | low | Read-only listing of all capabilities and assignments |
| `capability_get` | low | Read-only lookup for a specific subject |
| `capability_validate` | low | Read-only check of whether a subject can run an operation |
| `capability_assign` | high | Grants a capability (requires `capability.admin`) |
| `capability_remove` | high | Revokes a capability (requires `capability.admin`) |

All sub-actions are audited with the following events:
- `capability.assigned`
- `capability.removed`
- `capability.validated`
- `capability.denied`

---

## Audit Trail

Every capability change is recorded in the system audit log (`AuditLog`). Events include:
- `capability.assigned` — Capability granted to a subject (records subject, subject_id, capability, actor)
- `capability.removed` — Capability revoked from a subject
- `capability.validated` — Capability check performed (records allowed/denied, required capability)
- `capability.denied` — Operation denied due to missing capability

View audit entries via `GET /agent/context` (includes `recent_audit_entries`).

---

## Related Files

| File | Purpose |
|---|---|
| `includes/Operations/CapabilityRegistry.php` | Defines all capabilities, the OPERATION_MAP, assignment storage, and the validate() logic |
| `includes/Operations/CapabilityManager.php` | CRUD operations for capability assignments with audit logging |
| `includes/Operations/OperationExecutor.php` | Enforces capabilities during operation execution (checks `wpcc_enforce_capabilities`) |
| `includes/Security/AuditLog.php` | Records all capability changes as auditable events |
