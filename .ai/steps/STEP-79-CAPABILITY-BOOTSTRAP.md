# STEP 79 — Token Capability Auto-Bootstrap & Self-Healing

**Status:** Implemented and verified locally.

## 1. Problem

Finding F (the production capability lockout on `mosharafmanu.com`) recurred
for a second, unrelated reason after STEP 78 was deployed: `tools/call
approval_manage` — the very escape hatch STEP 78 introduced — itself returned

```json
{"error":{"code":-32001,"message":"Operation denied: missing capability system.admin"}}
```

`resources/read wpcc://capabilities` showed `assignment_count: 1`, but the
single `token:<id>` entry holding `system.admin` belonged to a *different,
now-invalid* token than the one currently configured in
`claude_desktop_config.json`. Root cause:

- `AuthTokens::create()` writes a new record to
  `wp-content/uploads/wpcc-tokens/manifest.json` with a fresh
  `wp_generate_uuid4()` id, but **never touches
  `wpcc_capability_assignments`**.
- With `wpcc_enforce_capabilities` defaulting to `true`,
  `CapabilityRegistry::validate()` denies **every** mapped operation
  (22 of 25 entries in `OPERATION_MAP`, including `approval_manage` itself)
  for a token with no assignment.
- The only fix is a direct `wp option update wpcc_capability_assignments
  '{"token:<id>":["system.admin"]}'` — i.e. WP-CLI/SSH/DB access. Every
  token rotation (revoke + recreate) reintroduces the lockout, and
  `capability_manage` (which could otherwise fix this via MCP) is itself
  gated to `capability.admin`, which the new token also lacks — the same
  chicken-and-egg as the original Finding F.

This is unacceptable for client sites that provide no SSH access. STEP 79
closes the gap permanently: token creation, rotation, and revocation now
keep `wpcc_capability_assignments` correct automatically, and every MCP
request self-heals a missing assignment as a backstop.

## 2. Architecture

```
                      ┌─────────────────────────────────────┐
  AuthTokens::create()│ -> CapabilityRegistry::bootstrap_token │  Provision
                      │      (profile_for_scope(scope))        │
                      └─────────────────────────────────────┘

                      ┌─────────────────────────────────────┐
  AuthTokens::revoke()│ -> CapabilityRegistry::deprovision_token│ Rotation
  AuthTokens::delete()│      (removes "token:<id>" entry)       │
                      └─────────────────────────────────────┘

                      ┌─────────────────────────────────────────────┐
  McpServerRuntime    │ -> CapabilityRegistry::ensure_token_capabilities│ Self-heal
  ::handle()          │      (no-op if non-empty; else bootstrap,       │ (backstop)
  (every MCP request) │       reason="self_healed")                     │
                      └─────────────────────────────────────────────┘

wpcc_capability_assignments["token:<id>"]  — single source of truth,
read by CapabilityRegistry::validate() (capability gate, step 1b) on
every tools/call.

Every provision/deprovision/self-heal writes to
wp-content/uploads/wpcc-audit/audit.log (capability.bootstrap /
capability.deprovisioned).                                         Audit
```

No new MCP-side code, no new DB tables/options, no Activator changes. The
existing `wpcc_capability_assignments` option (read by
`CapabilityRegistry::get_assignments()`/`validate()`/`get_summary()`/the
`wpcc://capabilities` resource) remains the single source of truth — it is
now kept correct *by construction* instead of by hand.

## 3. Capability profiles

`includes/Operations/CapabilityRegistry.php` gains three named profiles —
the single source of truth for "what capabilities does a token of scope X
get":

| Profile | Capabilities | Used for |
|---|---|---|
| `read_only` (`PROFILE_READ_ONLY`) | `database.inspect`, `search.manage` | `AuthTokens::SCOPE_READ_ONLY` tokens |
| `full_access` (`PROFILE_FULL_ACCESS`) | `system.admin` | `AuthTokens::SCOPE_FULL` tokens |
| `system_admin` (`PROFILE_SYSTEM_ADMIN`) | `system.admin` | reserved for future manual/explicit use |

```php
const PROFILES = [
    self::PROFILE_READ_ONLY    => [ self::CAP_DATABASE_INSPECT, self::CAP_SEARCH_MANAGE ],
    self::PROFILE_FULL_ACCESS  => [ self::CAP_SYSTEM_ADMIN ],
    self::PROFILE_SYSTEM_ADMIN => [ self::CAP_SYSTEM_ADMIN ],
];

public static function profile_for_scope( string $scope ): string {
    return AuthTokens::SCOPE_FULL === $scope ? self::PROFILE_FULL_ACCESS : self::PROFILE_READ_ONLY;
}
```

`profile_for_scope()` is binary and fails *safe*: anything that isn't
`AuthTokens::SCOPE_FULL` (today only `SCOPE_READ_ONLY` exists) maps to
`read_only`. `PROFILE_SYSTEM_ADMIN` is not reachable via the automatic path —
it exists so a future manual/admin-only provisioning flow has a named target
that isn't just "the full_access set, but on purpose."

### Why `full_access = [system.admin]`

This is the key design decision, traced through both states of
`wpcc_enforce_approval`:

- **`wpcc_enforce_approval = 0`** — `plugin_manage` passes the capability
  check via the `$has_admin` shortcut in `CapabilityRegistry::validate()`
  (`system.admin` bypasses every other capability check), and the approval
  gate is off → executes immediately.
- **`wpcc_enforce_approval = 1`** — `plugin_manage` is blocked
  (`-32000 ... requires approval`). The agent calls `approval_manage`
  (gated to `system.admin`, `requires_approval => false` per STEP 78) →
  passes the capability check → runs the full
  `request_create → request_approve → queue_run` cycle, which executes
  `plugin_manage` with `queue_id` set, satisfying the approval gate.

Both states work end-to-end with **zero manual steps**. The alternative
("all 21 non-admin capabilities") would leave `approval_manage` itself
`-32001`'d under `wpcc_enforce_approval = 1` — no escape hatch, and the
exact incident this Step fixes recurs for every future token. This is the
v1 design call, consistent with the "future Step 79: dedicated
`approval.admin` capability" note already in
`STEP-78-MCP-APPROVAL-RUNTIME.md` §6 — narrowing `full_access` is possible
later if/when that capability exists, without changing this Step's
mechanism (profiles + hooks).

### Why `read_only = [database.inspect, search.manage]`

This is an exact mirror of `CapabilityRegistry::READ_ONLY_SCOPE_OPERATIONS`
— the set of operations the **scope** gate (step 1a, in
`McpServerRuntime::tools_call()`) already allows a `read_only` token to call.
Before this Step, `wpcc_capability_assignments` had no entry for a read-only
token, so the **capability** gate (step 1b) denied both operations anyway
when `wpcc_enforce_capabilities = 1` — net usable read operations: **0**.
After this Step: **2**. This is a strict improvement with no new ceiling;
write operations remain blocked by the unchanged scope gate regardless of
any capability assignment.

## 4. New `CapabilityRegistry` methods

All three are internal-only — never exposed as MCP tools or REST actions —
and write via `save_assignments()` directly, **bypassing** `assign()`'s
`wpcc_cannot_assign_admin` guard. That guard (used by the MCP-facing
`capability_manage`/`capability_assign` action) is unchanged and still
applies to agent-driven capability changes.

| Method | Behavior |
|---|---|
| `bootstrap_token( $token_id, $scope, $reason = 'token_created' )` | Overwrites `wpcc_capability_assignments["token:$token_id"]` with `capabilities_for_profile(profile_for_scope($scope))`. Records `capability.bootstrap` (context: `token_id`, `scope`, `profile`, `capabilities`, `reason`). Returns the assigned capabilities. |
| `deprovision_token( $token_id )` | Removes `"token:$token_id"` from `wpcc_capability_assignments` if present. Records `capability.deprovisioned` (context: `token_id`). No-op (no write, no audit) if the key doesn't exist. |
| `ensure_token_capabilities( $token_id, $scope )` | Idempotent self-heal. Empty `$token_id` → `[]`, no-op. Non-empty existing assignment → returned unchanged, **no write**. Empty existing assignment → `bootstrap_token($token_id, $scope, 'self_healed')`. |

`bootstrap_token()` **overwrites**, but is only ever called when that's
correct: at token creation (key doesn't exist yet) or via
`ensure_token_capabilities()` when the existing assignment is **fully
empty**. A manually customized non-empty assignment (e.g. an admin who ran
`capability_manage`/`capability_assign` to add `media.manage` on top of the
profile) is never touched by either path.

## 5. Hook points

### `includes/Operations/CapabilityRegistry.php`
- New `use WPCommandCenter\Security\AuditLog;` and
  `use WPCommandCenter\Security\AuthTokens;`.
- `PROFILE_*` / `PROFILES` constants + `profile_for_scope()` /
  `capabilities_for_profile()`, placed after `READ_ONLY_SCOPE_OPERATIONS`.
- `bootstrap_token()` / `deprovision_token()` / `ensure_token_capabilities()`,
  placed after `get_for_subject()`, before `validate()`.

### `includes/Security/AuthTokens.php`
- New `use WPCommandCenter\Operations\CapabilityRegistry;`.
- `create()` — after `write_manifest()`:
  ```php
  ( new CapabilityRegistry() )->bootstrap_token( $record['id'], $scope, 'token_created' );
  ```
- `revoke()` — now calls `deprovision_token()` only if the underlying
  `update()` succeeded:
  ```php
  public function revoke( string $id ): bool|\WP_Error {
      $result = $this->update( $id, [ 'status' => self::STATUS_REVOKED ] );
      if ( true === $result ) {
          ( new CapabilityRegistry() )->deprovision_token( $id );
      }
      return $result;
  }
  ```
- `delete()` — after `write_manifest()`, before `return true`:
  ```php
  ( new CapabilityRegistry() )->deprovision_token( $id );
  ```

Both UI call sites (`includes/Admin/views/settings.php` and
`includes/Admin/views/ai-integrations.php`) call `AuthTokens::create()` /
`revoke()` / `delete()` directly with no wrapper — hooking `AuthTokens`
itself is the single chokepoint, so **no view changes were needed**.

### `includes/Mcp/McpServerRuntime.php`
- `handle()` — immediately after the existing `mcp.request` audit call,
  before the notification check (runs for **every** authenticated MCP
  method, not just `tools/call`):
  ```php
  if ( ! empty( $context['token_id'] ) ) {
      ( new CapabilityRegistry() )->ensure_token_capabilities( $context['token_id'], $context['token_scope'] ?? '' );
  }
  ```
  `CapabilityRegistry` was already imported in this file.

## 6. Security review

1. **No new escalation path.** `profile_for_scope()` derives a token's
   capabilities only from **that token's own immutable `scope`**
   (`read_only` or `full`, set at creation and never changed by these
   methods). A token cannot influence its own or any other token's profile.
   `full_access = [system.admin]` is exactly the grant the *manual* Finding F
   fix already gave the equivalent token — this Step makes that grant
   automatic and tied to the token's existing scope, not a new privilege
   tier.
2. **`assign()`'s anti-escalation guard is untouched.** `wpcc_cannot_assign_admin`
   still rejects any `capability_assign` call (MCP/REST,
   `capability_manage`) attempting to grant `system.admin` — that remains
   "direct configuration only." The new methods bypass `assign()` because
   they *are* the direct configuration, invoked from trusted, non-agent-
   reachable code paths (`AuthTokens` lifecycle methods, `McpServerRuntime::handle()`).
3. **Approval gate, `OperationExecutor`, and `ApprovalRuntimeManager` are
   completely unmodified** — zero edits to any of `OperationExecutor.php`,
   `ApprovalRuntimeManager.php`, `ApprovalRegistry.php`, or `Activator.php`.
   `wpcc_enforce_approval` and `wpcc_enforce_capabilities` behave exactly as
   before; this Step only ensures the data those checks read is always
   populated correctly.
4. **"Repair only when empty" semantics.** `ensure_token_capabilities()`
   only writes when `get_for_subject()` returns `[]` — a non-empty
   assignment (default profile, or admin-customized) is read-only from this
   path's perspective. Self-healing cannot silently widen an
   intentionally-narrowed assignment.
5. **Self-heal runs even when `wpcc_enforce_capabilities = 0`.** This is
   intentional: the *check* (step 1b) stays gated on the option as before,
   but the *repair* is cheap (one `get_option`, no write in the common case)
   and keeps the assignment correct so that flipping enforcement on later
   doesn't immediately re-trigger the lockout.
6. **Revoked tokens never reach `ensure_token_capabilities()`.**
   `AuthTokens::validate()` rejects a revoked token (`wpcc_token_revoked`)
   before `McpRestApi::handle_mcp()` builds `$context`, so `token_id` is
   never populated for a revoked token — and `deprovision_token()` already
   ran at revoke time regardless.

## 7. Migration strategy

- **No schema or `Activator.php` change.** Existing installs (including
  `mosharafmanu.com`) self-heal: the very next MCP request from the
  currently-configured (full-scope) token has an empty
  `wpcc_capability_assignments["token:<id>"]`, so
  `ensure_token_capabilities()` bootstraps it to `["system.admin"]`
  (`profile_for_scope('full') === 'full_access'`) — fixing the Finding-F
  recurrence the moment this build is deployed, with **zero manual steps**.
- **Stale orphaned entries** (e.g. the earlier manually-fixed token's
  `token:<old-id> => ["system.admin"]`, now pointing at a revoked/rotated
  id) are inert — `get_for_subject()` only ever looks up the *current*
  token's id. An optional, **not automated**, pruning snippet:
  ```php
  wp eval '
  $reg  = new \WPCommandCenter\Operations\CapabilityRegistry();
  $auth = new \WPCommandCenter\Security\AuthTokens();
  $live_ids = array_column( $auth->list(), "id" );
  $all = $reg->get_assignments();
  $pruned = 0;
  foreach ( array_keys( $all ) as $key ) {
      if ( str_starts_with( $key, "token:" ) ) {
          $tid = substr( $key, 6 );
          if ( ! in_array( $tid, $live_ids, true ) ) { unset( $all[ $key ] ); $pruned++; }
      }
  }
  $reg->save_assignments( $all );
  echo "pruned: $pruned";
  ' --path=<WP_PATH>
  ```

## 8. Worked examples (captured live, local AMPPS)

**Scenario A — token creation bootstraps `system.admin` immediately**

`AuthTokens::create('S-79 Doc Example', SCOPE_FULL, null, 1)` → assignment
written before `create()` even returns:

```json
{
  "token:690117c4-d4b0-4837-bdbd-508440d567fa": [ "system.admin" ]
}
```

Audit entry (`wp-content/uploads/wpcc-audit/audit.log`):

```json
{
  "timestamp": 1781329558,
  "action": "capability.bootstrap",
  "context": {
    "token_id": "690117c4-d4b0-4837-bdbd-508440d567fa",
    "scope": "full",
    "profile": "full_access",
    "capabilities": [ "system.admin" ],
    "reason": "token_created"
  }
}
```

`tools/call plugin_manage {action: plugin_list}` with this token: `result`
present, no `-32001` — works on the very first call.

**Scenario C — self-heal after an assignment is accidentally cleared**

A token's `wpcc_capability_assignments["token:<id>"]` is manually set to
`[]` (simulating accidental removal via `capability_manage`/DB edit). The
next MCP request (any method — `tools/list` here) triggers
`ensure_token_capabilities()`:

```json
{
  "timestamp": 1781329577,
  "action": "capability.bootstrap",
  "context": {
    "token_id": "4a4bf41f-a512-4a80-a645-101f94f72051",
    "scope": "full",
    "profile": "full_access",
    "capabilities": [ "system.admin" ],
    "reason": "self_healed"
  }
}
```

**Scenario B — revoke deprovisions**

`AuthTokens::revoke('4a4bf41f-...')`:

```json
{
  "timestamp": 1781329579,
  "action": "capability.deprovisioned",
  "context": { "token_id": "4a4bf41f-a512-4a80-a645-101f94f72051" }
}
```

`wpcc_capability_assignments["token:4a4bf41f-..."]` no longer exists. A
newly created replacement token gets its own fresh `bootstrap_token()` entry
immediately (Scenario B in the test suite).

**Scenario D — read-only profile**

`AuthTokens::create('S-79 Read Only D', SCOPE_READ_ONLY, null, 1)` →
`wpcc_capability_assignments["token:<id>"] = ["database.inspect", "search.manage"]`.
`tools/call database_inspect {action: db_table_list}` → `result` present.
`tools/call plugin_manage {action: plugin_list}` →
`{"error":{"code":-32001,"message":"... read-only ..."}}` (unchanged scope
gate, step 1a).

## 9. Test results

- `php -l` clean on all 3 edited files
  (`CapabilityRegistry.php`, `AuthTokens.php`, `McpServerRuntime.php`).
- `tests/test-capability-bootstrap.sh`: **21/21 passing** — Scenarios A–D
  plus self-heal audit verification and cleanup, entirely via `wp eval`
  (token lifecycle) + MCP `tools/call`/`tools/list` (no DB/SSH shortcuts
  beyond the test harness's existing `wp eval` convention).
- Full local regression suite (all 62 `tests/test-*.sh` files, including the
  new suite): **2930 passed, 24 failed** (up from the STEP 78 baseline of
  2909/24 — the +21 are this suite's new assertions, all passing). Failures
  are limited to the same pre-existing, unrelated suites as STEP 78
  (`test-ai-client-layer.sh`(1), `test-ai-integration-ux.sh`(3),
  `test-claude-integration.sh`(4), `test-cursor-certification.sh`(2),
  `test-documentation-consistency.sh`(11), `test-security-redaction.sh`(3)).
  STEP 79 introduces **zero new failures**.

## 10. Final recommendation

**Keep `wpcc_capability_assignments` as a separate enforcement store, made
self-maintaining via profiles + lifecycle hooks — do not collapse it into a
pure runtime derivation from token scope.**

1. The store already supports **per-token customization beyond the
   profile** via the existing `capability_manage`/`capability_assign` CRUD
   (Step 44). Pure derivation from `scope` would silently discard any such
   customization on every request.
2. `CapabilityRegistry::validate()`, `get_summary()`, and the
   `wpcc://capabilities` MCP resource already operate on the assignments map
   as a queryable, auditable artifact. Re-deriving on every read would mean
   reimplementing all of that against a virtual/in-memory structure for no
   benefit.
3. Bootstrap-on-create + deprovision-on-revoke/delete +
   self-heal-on-every-request gives the property actually wanted — *a
   token's capability assignment is always present and correct by
   construction* — at near-zero cost (one `get_option` read in the common,
   already-healthy case). This **is** "derived from scope", just materialized
   as data with room to diverge intentionally.

## 11. Files

**Edited:**
- `includes/Operations/CapabilityRegistry.php` — 2 new `use` imports;
  `PROFILE_*`/`PROFILES` constants + `profile_for_scope()`/
  `capabilities_for_profile()`; `bootstrap_token()`/`deprovision_token()`/
  `ensure_token_capabilities()`.
- `includes/Security/AuthTokens.php` — 1 new `use` import; hooks in
  `create()`, `revoke()`, `delete()`.
- `includes/Mcp/McpServerRuntime.php` — 1 new self-heal call in `handle()`.

**New:**
- `tests/test-capability-bootstrap.sh` — 21 assertions (Scenarios A–D +
  self-heal audit + cleanup).
- `STEP-79-CAPABILITY-BOOTSTRAP.md` — this report.

**Unmodified (by design):** `OperationExecutor.php`,
`ApprovalRuntimeManager.php`, `ApprovalRegistry.php`, `Activator.php`,
`includes/Admin/views/settings.php`, `includes/Admin/views/ai-integrations.php`.

## 12. Production follow-up

`mosharafmanu.com` is not touched by this implementation pass. Once this
build is deployed there, the currently-configured Claude Desktop token's
first MCP request (e.g. `initialize` or `tools/list`) will self-heal its
empty `wpcc_capability_assignments` entry to `["system.admin"]` — resolving
the Finding-F recurrence with no further action. The previously-fixed but
now-stale `token:<old-id> => ["system.admin"]` entry from the original
Finding F fix becomes an inert orphan; pruning it (§7) is optional and
purely cosmetic.
