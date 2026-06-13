# Finding F — Production Capability Lockout Report

Date: 2026-06-13
Status: **Diagnosed, not yet fixed** (requires access to production site `mosharafmanu.com`)

## 1. Executive Summary

After Findings A/C/C2/C3/D/E were fixed and validated on the local AMPPS dev
site (see `MCP-TOOL-EXECUTION-FAILURE-REPORT.md`, 311/311 passing), the user
reported that Claude Desktop *still* fails to call `database_inspect`, with
the message:

> "Still the same error. The connection between the MCP server and your
> WordPress site isn't established yet."

Investigation found this is a **completely separate, pre-existing issue on
production** (`https://mosharafmanu.com`), unrelated to anything fixed today:

| | |
|---|---|
| Affected site | `https://mosharafmanu.com` (production — **not** the local dev site fixed today) |
| Symptom | Claude Desktop UI says "connection... isn't established" |
| Actual JSON-RPC error | `{"code":-32001,"message":"Operation denied: missing capability database.inspect"}` |
| Root cause | `wpcc_capability_assignments` option is completely empty (`assignment_count: 0`) AND `wpcc_enforce_capabilities` defaults to `true` → **every** capability-gated operation is denied for **every** token |
| Scope of impact | All 22 operations in `CapabilityRegistry::OPERATION_MAP`, including `database_inspect`, `settings_manage`, `wp_cli_bridge`, `plugin_manage`, `theme_manage` — i.e. **both** of the user's screenshots, one root cause |
| Self-fix possible via MCP? | **No** — chicken-and-egg (see §4) |
| Admin UI exists to fix it? | **No** (`includes/Admin/views/*` has no capability-assignment screen) |
| Fix location | Must be applied on `mosharafmanu.com` via WP-CLI or direct `wp_options` edit — no access from this environment |

## 2. Background — Claude Desktop connects to production, not local dev

Today's Findings A/C/C2/C3/D/E were all fixed and validated against the
**local AMPPS dev site**:

```
http://localhost/ClientProjects/WordPress/2026/plugins-dev/wp-json/wp-command-center/v1/mcp
```

However, `~/Library/Application Support/Claude/claude_desktop_config.json`
configures the `wp-command-center` MCP server entry as:

```json
{
  "mcpServers": {
    "wp-command-center": {
      "command": "bash",
      "args": [
        "-c",
        "RELAY='/tmp/wpcc-mcp-relay.mjs'; curl -fsSL -o \"$RELAY\" 'https://mosharafmanu.com/wp-content/plugins/wp-command-center/sdk/javascript/wpcc-mcp-relay.mjs?v=0.1.0'; node \"$RELAY\""
      ],
      "env": {
        "WPCC_MCP_URL": "https://mosharafmanu.com/wp-json/wp-command-center/v1/mcp",
        "WPCC_SITE_URL": "https://mosharafmanu.com",
        "WPCC_TOKEN": "wpcc_V7nkukpsvwZrkDsFisSYSvw4VMY64dHZqGFF7ef1VZlIw180GHCkGAdsHjZmK8qi",
        "WPCC_CONTEXT_MODE": "compact"
      }
    }
  }
}
```

This means **every fix made today is invisible to Claude Desktop** until it
is deployed to `mosharafmanu.com` — Claude Desktop has never been talking to
the local dev site.

(The `WPCC_TOKEN` here is a real token string, not the literal `${WPCC_TOKEN}`
placeholder from the previously-OPEN "Finding B" — so Finding B as originally
defined is not the active blocker for *this* config, though
`ClaudeIntegration::generate_mcp_config()` may still emit that placeholder for
newly-generated configs and is worth re-checking separately.)

## 3. Diagnostic evidence against `https://mosharafmanu.com/wp-json/wp-command-center/v1/mcp`

### 3.1 `initialize` — succeeds (connection IS established)

```bash
curl -s -m 15 "https://mosharafmanu.com/wp-json/wp-command-center/v1/mcp" \
  -X POST -H "Content-Type: application/json" \
  -H "Authorization: Bearer wpcc_V7nkukpsvwZrkDsFisSYSvw4VMY64dHZqGFF7ef1VZlIw180GHCkGAdsHjZmK8qi" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}'
```

Result: `serverInfo.version: "0.1.0"` — same version string as the local
plugin (`wp-command-center.php` `Version: 0.1.0`). The token is valid and
accepted. **Claude Desktop's "connection isn't established" message is a
misleading paraphrase of a tool-call error, not a transport/connection
failure.**

### 3.2 `tools/call database_inspect` — fails with `-32001`

```bash
curl -s -m 15 "https://mosharafmanu.com/wp-json/wp-command-center/v1/mcp" \
  -X POST -H "Content-Type: application/json" \
  -H "Authorization: Bearer wpcc_V7nkukpsvwZrkDsFisSYSvw4VMY64dHZqGFF7ef1VZlIw180GHCkGAdsHjZmK8qi" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"database_inspect","arguments":{"action":"db_health_summary"}}}'
```

Result:
```json
{"jsonrpc":"2.0","id":1,"error":{"code":-32001,"message":"Operation denied: missing capability database.inspect"}}
```

### 3.3 `resources/read wpcc://capabilities` — reveals the root cause

```bash
curl -s -m 15 "https://mosharafmanu.com/wp-json/wp-command-center/v1/mcp" \
  -X POST -H "Content-Type: application/json" \
  -H "Authorization: Bearer wpcc_V7nkukpsvwZrkDsFisSYSvw4VMY64dHZqGFF7ef1VZlIw180GHCkGAdsHjZmK8qi" \
  -d '{"jsonrpc":"2.0","id":1,"method":"resources/read","params":{"uri":"wpcc://capabilities"}}'
```

Result (abbreviated):
```json
{
  "capabilities": { "count": 22, "preview": [...], "truncated": true },
  "operation_map": { "...": "..." },
  "assignment_count": 0,
  "subject_counts": []
}
```

`assignment_count: 0` and `subject_counts: []` mean the
`wpcc_capability_assignments` option (`CapabilityRegistry::get_storage_key()`)
is **completely empty** — no subject (token, user, role, etc.) has ever been
granted any capability on this site.

## 4. Root Cause Analysis

### 4.1 The capability gate

`includes/Operations/CapabilityRegistry.php:195-210`:

```php
public function validate( string $operation_id, string $subject = 'token', string $subject_id = '' ): array {
    $required = $this->get_required_capability( $operation_id );
    // Unmapped operations are allowed (read-only or seed operations).
    if ( null === $required ) {
        return [ 'allowed' => true, 'required_capability' => null, 'reason' => 'unrestricted' ];
    }
    if ( '' === $subject_id ) {
        return [ 'allowed' => false, 'required_capability' => $required, 'reason' => 'no_subject' ];
    }
    $assigned = $this->get_for_subject( $subject, $subject_id );
    $has_admin = in_array( self::CAP_SYSTEM_ADMIN, $assigned, true );
    if ( $has_admin || in_array( $required, $assigned, true ) ) {
        return [ 'allowed' => true, 'required_capability' => $required, 'assigned' => $assigned ];
    }
    return [ 'allowed' => false, 'required_capability' => $required, 'assigned' => $assigned, 'reason' => 'missing_capability' ];
}
```

`OPERATION_MAP` (`CapabilityRegistry.php:47-90`) maps **22 of the 28**
operation families to a required capability — including `database_inspect` →
`database.inspect`, `settings_manage` → `settings.manage`, `wp_cli_bridge` →
`wpcli.execute`, `plugin_manage` → `plugin.manage`, `theme_manage` →
`theme.manage`. With `$assigned = []` for every token (because
`wpcc_capability_assignments` is empty), `validate()` returns
`allowed: false, reason: 'missing_capability'` for **all 22** of these, for
**any** token.

### 4.2 Enforcement defaults to ON, with no override on this site

Both `OperationExecutor::run()` (line 74) and `McpServerRuntime::tools_call()`
(line 241) gate on:

```php
if ( get_option( 'wpcc_enforce_capabilities', true ) ) { ... }
```

`McpServerRuntime.php:239-247`:

```php
// Capability check
$token_id = $context['token_id'] ?? '';
if ( '' !== $token_id && get_option( 'wpcc_enforce_capabilities', true ) ) {
    $validation = $cap_reg->validate( $tool_name, 'token', $token_id );
    if ( ! $validation['allowed'] ) {
        $this->audit( 'mcp.denied', [ 'tool' => $tool_name, 'reason' => 'missing_capability', 'required' => $validation['required_capability'] ], $context );
        return $this->error( -32001, 'Operation denied: missing capability ' . $validation['required_capability'], null );
    }
}
```

Since `wpcc_enforce_capabilities` was never set on `mosharafmanu.com`, this
defaults to `true`, and the gate above fires for every capability-gated tool
call.

### 4.3 No bootstrap path — chicken-and-egg

The operation that *would* fix this — `capability_manage` (action
`capability_assign`) — is **itself** in `OPERATION_MAP`, requiring
`capability.admin`:

```php
'capability_manage'   => self::CAP_CAPABILITY_ADMIN,
```

Since `capability.admin` is also unassigned to every token, a call to
`capability_manage`/`capability_assign` via MCP or REST is **itself denied**
with the same `-32001`. There is no way for any token to grant itself (or
anything else) a capability through the AI Agent Gateway.

There is also **no WP-Admin UI** for capability management — confirmed via
`grep -rln "wpcc_enforce_capabilities\|Capabilit" includes/Admin/views/`
(no matches). The only "capability" reference outside the operations layer is
the `Capabilities` MCP resource label in `ClaudeIntegration.php`.

### 4.4 No activation-time seeding

`includes/Core/Activator.php` (full file, 15 lines):

```php
final class Activator {
    public static function activate(): void {
        Schema::install();
        if ( ! wp_next_scheduled( \WPCommandCenter\Operations\OperationWorker::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'wpcc_five_minutes', \WPCommandCenter\Operations\OperationWorker::CRON_HOOK );
        }
    }
}
```

No call to `CapabilityRegistry::assign()`, and no `add_option()`/
`update_option()` for `wpcc_capability_assignments` or
`wpcc_enforce_capabilities` anywhere in the codebase (confirmed by grep — the
only references to `wpcc_enforce_capabilities` are the five `get_option(...,
true)` reads).

**Net effect: every fresh production install of this plugin ships
deny-by-default for 22 operation families, with zero self-service bootstrap
path (no admin UI, and the bootstrap operation is itself gated by the same
lock).**

## 5. Why the local AMPPS dev site doesn't show this

```bash
$ wp eval 'echo get_option("wpcc_enforce_capabilities", "DEFAULT_TRUE");'
0
$ wp eval 'echo wp_json_encode(get_option("wpcc_capability_assignments", "EMPTY"));'
{"token:fab2991a-00be-4af8-a2c8-17860fff32e0":["system.admin"],"token:mcp-cap-test-token":["content.manage"]}
```

- `wpcc_enforce_capabilities` is explicitly stored as the string `'0'`
  (falsy in PHP) — a leftover dev/test setting from Step 44 development. The
  gate at `McpServerRuntime.php:241` and `OperationExecutor.php:74` is
  therefore **skipped entirely** on local dev.
- The local `wpcc_capability_assignments` option also already has a
  `system.admin` grant for one test token, from earlier Step 44 testing.

This is why all 311 local assertions from Part 1 (and all 2,839+ regression
assertions in `resume.md`) never exercised this gate — and why the same
`database_inspect` call that returns real data locally returns `-32001` on
`mosharafmanu.com`.

## 6. Remediation Options for `mosharafmanu.com`

**Requires WP-CLI/SSH or direct database access to `mosharafmanu.com` — not
available from this environment.**

### Option A — Quick unblock (matches current local dev state)

```bash
wp option update wpcc_enforce_capabilities 0
```

Disables the Step 44 capability gate entirely; falls back to the pre-Step-44
behavior of read-only/full token-scope checks only (`AuthTokens` +
`CapabilityRegistry::requires_full_scope()` / `READ_ONLY_SCOPE_OPERATIONS`,
which are unaffected by this option). Fast, but removes a security layer
site-wide for all tokens.

### Option B — Proper fix: grant the configured token `system.admin`

1. Find the token's UUID — `token_preview` is the first 12 characters of the
   raw token (`AuthTokens::PREVIEW_LENGTH = 12`), i.e. `wpcc_V7nkukp` for the
   token in `claude_desktop_config.json`:
   ```bash
   wp option get wpcc_api_tokens --format=json
   # find the record where token_preview == "wpcc_V7nkukp", note its "id"
   ```
2. Grant `system.admin` (bypasses all 22 individual capability checks per
   `CapabilityRegistry::validate()`'s `$has_admin` check):
   ```bash
   wp option update wpcc_capability_assignments '{"token:<TOKEN_ID>":["system.admin"]}' --format=json
   ```
   (If `wpcc_capability_assignments` already has other entries on this site,
   merge rather than overwrite.)

Option B is scoped to one token and preserves enforcement for everyone else.

## 7. Recommended Long-Term Product Fix (needs design confirmation)

This is a usability/onboarding bug, not just a configuration gap: a fresh
install of WP Command Center is **unusable via the AI Agent Gateway for 22 of
28 operation families** until someone manually edits `wp_options` — a step
documented nowhere in `docs/QUICKSTART.md` or `docs/INSTALLATION.md` (not
verified in this session, but `Activator.php` confirms no seeding occurs).

Candidate fixes (pick one or combine — confirm with user before implementing):

1. **Exempt self-service capability actions from their own gate.** Allow a
   `full`-scope token to always call `capability_manage` with actions
   `capability_list` / `capability_get` / `capability_assign` for **its own**
   `token_id`, regardless of `capability.admin` assignment. This gives every
   full-scope token a guaranteed bootstrap path without weakening protection
   for *other* subjects' capabilities.
2. **Seed a default assignment on activation.** `Activator::activate()` could
   assign `system.admin` to a well-known bootstrap subject (e.g. the token
   created during initial AI Integrations setup), via
   `(new CapabilityRegistry())->assign('token', $id, CapabilityRegistry::CAP_SYSTEM_ADMIN)`.
   Tricky because tokens are typically created later, via the AI Integrations
   admin page — may need to hook token creation instead of/in addition to
   activation.
3. **Add a WP-Admin "Capabilities" screen** under `includes/Admin/views/`,
   using normal `manage_options` + nonce auth (like the existing dashboard),
   calling `CapabilityRegistry::assign()`/`remove()` directly — sidesteps the
   AI Agent Gateway gate entirely for human admins.
4. **Default `wpcc_enforce_capabilities` to `false`** until an admin
   explicitly enables it via the (new) Capabilities screen — softer
   deny-by-default, but reduces the blast radius of this exact lockout for new
   installs.

(1) + (3) together seem the most robust: (1) unblocks AI-driven setups
immediately and safely; (3) gives human admins a durable management surface.
(2) and (4) are situational alternatives. **Do not implement without
confirming direction with the user**, per project workflow.

## 8. Files Referenced (no files modified in this report)

| File | Relevance |
|---|---|
| `includes/Operations/CapabilityRegistry.php` | `OPERATION_MAP`, `ALL_CAPABILITIES`, `validate()`, `get_summary()`, storage key `wpcc_capability_assignments` |
| `includes/Operations/OperationExecutor.php` | Capability gate at line 74 (REST/queue path) |
| `includes/Mcp/McpServerRuntime.php` | Capability gate at lines 239-247 (`tools/call` path) |
| `includes/Core/Activator.php` | Confirmed: no capability seeding on activation |
| `includes/Admin/views/*` | Confirmed: no capability-management UI |
| `~/Library/Application Support/Claude/claude_desktop_config.json` | Proves Claude Desktop targets `mosharafmanu.com`, not local dev (read-only, not part of this plugin) |

## 9. Bottom Line

"This is expected now?" — **No, in the sense that nothing is working as
intended; but yes, in the sense that this is a real, reproducible,
pre-existing condition on `mosharafmanu.com`, independent of (and not caused
or fixed by) today's Findings A/C/C2/C3/D/E.** Both of the user's screenshots
(`settings_manage`/`wp_cli_bridge`/`plugin_manage`/`theme_manage`, and
`database_inspect`) share this single root cause: zero capability assignments
+ enforcement-on-by-default + no bootstrap path on production. Fixing it
requires WP-CLI/SSH or DB access to `mosharafmanu.com` (§6), and ideally a
product fix to prevent this for all future installs (§7).
