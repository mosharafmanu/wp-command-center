# MCP Tool Execution Failure Report

**Date:** 2026-06-13
**Scope:** Audit of `tools/call` execution path (`tools/call → MCP runtime → capability check
→ operation registry → handler → response`), focused on `database_inspect` plus the four tools
shown as "Failed to call tool" in Claude Desktop (`settings_manage`, `wp_cli_bridge`,
`plugin_manage`, `theme_manage`).
**Status:** ✅ All identified root causes fixed and validated.

---

## 1. Executive Summary

`database_inspect` itself **does not fail**. It was traced end-to-end through the full
`tools/call → MCP runtime → capability check → operation registry → handler → response`
pipeline using the exact JSON-RPC payload Claude Desktop sends, and it returns a correct,
real result (see §2–3).

The four tools shown as "Failed to call tool" in the screenshot fail for **four distinct,
unrelated root causes**, none of which involve `database_inspect`:

| # | Tool(s) affected | Root cause | Status |
|---|---|---|---|
| **C** | `wp_cli_bridge` (`args`), `safe_search_replace`, `acf_seed`, `woo_product_seed`, `bulk_manage`, `widgets_manage`, `cpt_manage`, `safe_updates` | `tools/list` mapped `boolean`/`object`/`array` parameter types to `"string"` in `inputSchema`, producing schemas that don't match the data the tool actually expects/returns. | ✅ Fixed |
| **C2** | `theme_manage`, `plugin_manage`, `option_manage`, `snapshot_manage`, `content_seed`, `cf7_seed`, `woo_product_seed`, **`database_inspect`**, **`settings_manage`** | `tools/list` silently dropped `enum`/`default` from `inputSchema.properties`, so Claude Desktop's model had no visibility into valid `action` values (or had none defined at all for `database_inspect`/`settings_manage`). | ✅ Fixed |
| **D** | `wp_cli_bridge` (all calls), `wpcc://context`/`wpcc://manifest` `capabilities.wp_cli` | `WpCliBridge::is_available()` (and a duplicate probe in `SiteScanner::detect_wp_cli()`) ran `shell_exec('wp --version')` with the **ambient PATH**, which under Apache/mod_php is `/usr/bin:/bin:/usr/sbin:/sbin` — `wp` is not on that PATH (it lives in `/usr/local/bin`). Result: `is_available()` returns `false` under the web SAPI even though `wp eval` works fine from a CLI shell. | ✅ Fixed |
| **E** | `settings_manage` | `SettingsRuntimeManager::run()` returned a plain array `['error'=>true,...]` on an invalid `action` instead of `\WP_Error`, so `OperationExecutor` treated it as a **success** and `tools_call()` returned a JSON-RPC `result` containing an embedded error JSON string — inconsistent with `theme_manage`/`plugin_manage`/`database_inspect`, which return a proper JSON-RPC `error`. | ✅ Fixed |

All four findings were fixed in code, and every fix is backed by live `tools/list` /
`tools/call` evidence against the real `/mcp` endpoint (the exact transport Claude Desktop
uses), plus 227 passing regression-suite assertions across 8 test files (0 failures).

---

## 2. The `database_inspect` Data Request — Answered Honestly

> Return only: Site URL, WordPress Version, Database Size. Do not estimate. Use the actual
> tool result.

**Exact payload sent (identical to what Claude Desktop sends for a tool call with no
`context_mode` override — defaults to `compact`):**

```json
{"jsonrpc":"2.0","id":7,"method":"tools/call","params":{"name":"database_inspect","arguments":{"action":"db_health_summary"}}}
```

**Exact response from the live `/mcp` endpoint:**

```json
{
  "jsonrpc": "2.0",
  "id": 7,
  "result": {
    "content": [
      {
        "type": "text",
        "text": "{\"action\":\"db_health_summary\",\"db_size_mb\":22.47,\"largest_table\":{\"name\":\"wpcc_operation_results\",\"size_mb\":9.83},\"autoload_size_bytes\":0,\"orphan_records\":0,\"expired_transients\":0,\"warnings\":[],\"warning_count\":0,\"duration_ms\":3}"
      }
    ]
  }
}
```

| Requested field | Value | Source |
|---|---|---|
| **Database Size** | **22.47 MB** | ✅ Direct `database_inspect` result (`db_size_mb`, action `db_health_summary`) |
| **WordPress Version** | **7.0** | ⚠️ NOT in `database_inspect`'s response schema. Taken from `resources/read` of `wpcc://context` → `context.wordpress.version`. |
| **Site URL** | **`http://localhost/ClientProjects/WordPress/2026/plugins-dev`** | ⚠️ NOT in `database_inspect`'s response schema. Taken from `resources/read` of `wpcc://context` → `context.wordpress.site_url`. |

**Important honesty note:** `database_inspect`'s `db_health_summary` action returns only
`{action, db_size_mb, largest_table, autoload_size_bytes, orphan_records, expired_transients,
warnings, warning_count, duration_ms}` (see `DatabaseInspector::health_summary()`,
`includes/Operations/DatabaseInspector.php`). It has **no `site_url` or `wp_version` fields**
— there is no way to get those three values from a single `database_inspect` call. The Database
Size figure above is the genuine, non-estimated tool output; the other two values came from a
separate MCP resource (`wpcc://context`), not from `database_inspect`.

---

## 3. Full Execution Trace — `database_inspect` (Working Baseline)

Traced through `tools/call → MCP runtime → capability check → operation registry → handler →
response` using the payload from §2.

### Step 0 — Transport
`McpRestApi::handle_mcp()` (`includes/Mcp/McpRestApi.php`) receives the POST to `/mcp`,
authenticates the Bearer token via `AuthTokens`, and calls `McpServerRuntime::handle()`.

### Step 1 — MCP runtime dispatch
`McpServerRuntime::handle()` (`includes/Mcp/McpServerRuntime.php:57`) matches
`method === 'tools/call'` → `tools_call($params, $context)`.

### Step 2 — Scope check (`tools_call`, line ~232-237)
```php
$cap_reg = new CapabilityRegistry();
$token_scope = $context['token_scope'] ?? '';
if ( AuthTokens::SCOPE_READ_ONLY === $token_scope && $cap_reg->requires_full_scope( $tool_name ) ) { ... }
```
`database_inspect` is in `CapabilityRegistry::READ_ONLY_SCOPE_OPERATIONS`
(`includes/Operations/CapabilityRegistry.php:97`), so `requires_full_scope('database_inspect')`
is `false` — **passes** for both read-only and full tokens.

### Step 3 — Capability check (`tools_call`, line ~239-247)
```php
if ( '' !== $token_id && get_option( 'wpcc_enforce_capabilities', true ) ) {
    $validation = $cap_reg->validate( $tool_name, 'token', $token_id );
    if ( ! $validation['allowed'] ) { ... return error -32001 ... }
}
```
`CapabilityRegistry::OPERATION_MAP['database_inspect'] = CAP_DATABASE_INSPECT`
(`includes/Operations/CapabilityRegistry.php:51`). The test token holds this capability —
**passes**.

### Step 4 — `OperationExecutor::run('database_inspect', $args, $context)`
(`includes/Operations/OperationExecutor.php:47`)

- **1. Operation lookup** (line 65-71): `OperationRegistry::get_operation('database_inspect')`
  returns the operation definition (`risk_level: low`, `requires_approval: false`,
  `available: true`) — found.
- **1b. Capability enforcement** (line 73-89): re-validated via `CapabilityRegistry::validate()`
  — passes (same mapping as Step 3).
- **1c. Approval gate** (line 91-101): `wpcc_enforce_approval` is `0` and
  `operation['requires_approval']` is `false` anyway — skipped.
- **2. Availability check** (line 103-106): `operation['available'] === true` (statically
  `true` in `OperationRegistry.php:158`, no environment probe) — passes.
- **3. Handler dispatch** (line 108-114): `resolve_handler('database_inspect')` →
  `case 'database_inspect': return new DatabaseInspector();` (`OperationExecutor.php:235-236`).

### Step 5 — `DatabaseInspector::run($payload, $context)`
(`includes/Operations/DatabaseInspector.php:23`)

- `$action = 'db_health_summary'` — validated against `DatabaseRegistry::ACTIONS` — valid.
- Write-keyword guard (`contains_write_keywords`) — no write keywords present — passes.
- `match($action)` dispatches to `health_summary()`, which computes real DB metrics
  (`SHOW TABLE STATUS`, autoload option size, orphaned postmeta, expired transients) and
  returns an array (not `WP_Error`).

### Step 6 — Result normalization
Back in `OperationExecutor::run()`, since `$result` is not `is_wp_error()`,
`normalize_success('database_inspect', $result)` (line 178, defined at line 294) wraps it as
`{operation_id, success: true, result: {...}, errors: [], created: [], updated: [], skipped: []}`.
`OperationResults::create()` persists the result and audit events are recorded.

### Step 7 — Response shaping
Back in `tools_call()` (line 253-265):
```php
if ( ! $result['success'] ) { ... }
$optimized = ( new ContextModeOptimizer() )->optimize( $result['result'], $mode );
$redacted  = $this->redactor->redact_recursive( $optimized );
return [ 'result' => [ 'content' => [ [ 'type' => 'text', 'text' => wp_json_encode( $redacted['data'] ) ] ] ] ];
```
`$result['success']` is `true`, so the `database_inspect` result is JSON-encoded into
`content[0].text` exactly as shown in §2.

**Conclusion: `database_inspect` executes correctly end-to-end. No fix was needed for this
tool.**

---

## 4. Finding C / C2 — `tools/list` Schema Generation Bugs

### 4.1 Exact code before fix

`includes/Mcp/McpServerRuntime.php`, `tools_list()`:

```php
foreach ( $op['parameters'] as $p ) {
    $props[ $p['name'] ] = [
        'type' => $p['type'] === 'integer' ? 'number' : 'string',
    ];
    if ( ContextModeOptimizer::COMPACT !== $mode ) {
        $props[ $p['name'] ]['description'] = $p['description'] ?? $p['name'];
    }
}
```

### 4.2 Root cause

- **Finding C:** Every parameter type except `integer` fell through to `'string'`. This means
  `wp_cli_bridge.args` (an object), `safe_search_replace.tables` (a required array),
  `safe_search_replace.dry_run`/`case_sensitive` (booleans), `acf_seed.fields` (a required
  object), `woo_product_seed.manage_stock`/`categories`, `bulk_manage.ids`/`fields`,
  `widgets_manage.widget_settings`, `cpt_manage.config`, and `safe_updates.dry_run` were all
  advertised to Claude Desktop as `"type": "string"` even though the operation requires/returns
  an object, array, or boolean. An MCP client that validates arguments against the advertised
  schema before calling the tool can reject or mis-serialize these calls.

- **Finding C2:** `enum` and `default` keys present on parameter definitions in
  `OperationRegistry` (e.g. `theme_manage.action`, `plugin_manage.action`,
  `option_manage.action`, `snapshot_manage.action`, `content_seed.status/count`,
  `woo_product_seed.status/stock_quantity/manage_stock`, `cf7_seed.form_template`) were silently
  dropped. Claude Desktop's model therefore had **no list of valid `action` values** for any of
  the `*_manage` tools, making it likely to call them with a missing or invalid `action` — which
  fails (see §6 for `theme_manage`/`plugin_manage`, and §5 for `settings_manage`).

  In addition, `database_inspect.action` and `settings_manage.action` had **no `enum` defined
  in `OperationRegistry` at all** — even after fixing the dropping bug, the model would still
  have no guidance for these two tools (§4.4).

### 4.3 The fix

**File:** `includes/Mcp/McpServerRuntime.php` (`tools_list()`)

```php
foreach ( $op['parameters'] as $p ) {
    $props[ $p['name'] ] = [
        'type' => match ( $p['type'] ) {
            'integer' => 'number',
            'boolean' => 'boolean',
            'object'  => 'object',
            'array'   => 'array',
            default   => 'string',
        },
    ];
    if ( isset( $p['enum'] ) ) {
        $props[ $p['name'] ]['enum'] = $p['enum'];
    }
    if ( isset( $p['default'] ) ) {
        $props[ $p['name'] ]['default'] = $p['default'];
    }
    if ( ContextModeOptimizer::COMPACT !== $mode ) {
        $props[ $p['name'] ]['description'] = $p['description'] ?? $p['name'];
    }
}
```

### 4.4 Data-completeness companion fix

**File:** `includes/Operations/OperationRegistry.php`

```php
// database_inspect
[ 'name' => 'action', 'type' => 'string', 'required' => true, 'enum' => DatabaseRegistry::ACTIONS, 'description' => 'Inspection operation.' ],

// settings_manage
[ 'name' => 'action', 'type' => 'string', 'required' => true, 'enum' => SettingsRegistry::ACTIONS ],
```

These reuse the existing `DatabaseRegistry::ACTIONS` / `SettingsRegistry::ACTIONS` constants
(the same lists `DatabaseInspector`/`SettingsRuntimeManager` already validate `action` against),
so the enums can never drift out of sync with what the handlers actually accept.

### 4.5 Validation evidence — live `tools/list`

```jsonc
// wp_cli_bridge.args — was {"type":"string"}, now:
{"type":"object"}

// safe_search_replace — was all strings, now:
{
  "search": {"type":"string"},
  "replace": {"type":"string"},
  "dry_run": {"type":"boolean","default":true},
  "tables": {"type":"array"},
  "case_sensitive": {"type":"boolean","default":false},
  ...
}

// acf_seed.fields — was {"type":"string"}, now:
{"type":"object"}

// woo_product_seed — was all strings, now:
{
  "status": {"type":"string","enum":["draft","publish"],"default":"draft"},
  "stock_quantity": {"type":"number","default":10},
  "manage_stock": {"type":"boolean","default":true},
  "categories": {"type":"array"}
}

// theme_manage.action — was {"type":"string"}, now:
{"type":"string","enum":["theme_list","theme_install","theme_activate","theme_update","theme_delete"]}

// plugin_manage.action — was {"type":"string"}, now:
{"type":"string","enum":["plugin_list","plugin_install","plugin_activate","plugin_deactivate","plugin_update","plugin_delete"]}

// option_manage.action — now:
{"type":"string","enum":["option_get","option_update","option_rollback"]}

// snapshot_manage.action — now:
{"type":"string","enum":["snapshot_create","snapshot_list","snapshot_details","snapshot_restore","snapshot_verify"]}

// database_inspect.action — was {"type":"string"} (no enum), now:
{"type":"string","enum":["db_table_list","db_table_stats","db_table_size","db_row_counts","db_autoload_analysis","db_options_health","db_index_analysis","db_orphan_detection","db_health_summary"]}

// settings_manage.action — was {"type":"string"} (no enum), now:
{"type":"string","enum":["settings_general_get","settings_general_update","settings_reading_get","settings_reading_update","settings_discussion_get","settings_discussion_update","settings_media_get","settings_media_update","settings_permalink_get","settings_permalink_update","settings_privacy_get","settings_privacy_update","settings_inventory","settings_analyze"]}
```

Regression suite: `tests/test-mcp-tool-schema-compliance.sh` → **10/10 passed**.

---

## 5. Finding D — `wp_cli_bridge` Unavailable Under the Web SAPI

### 5.1 Exact failing payload

```json
{"jsonrpc":"2.0","method":"tools/call","id":1,"params":{"name":"wp_cli_bridge","arguments":{"command":"plugin_list"}}}
```

### 5.2 Exact error (before fix)

```json
{"jsonrpc":"2.0","id":1,"error":{"code":-32000,"message":"Operation is not available in the current environment."}}
```

This is returned **regardless of `arguments`** — even `{}` produces the identical error — because
it is rejected at `OperationExecutor::run()` Step 2 (`includes/Operations/OperationExecutor.php:103-106`):

```php
if ( empty( $operation['available'] ) ) {
    return $this->fail( $operation_id, 'operation_not_available', __( 'Operation is not available in the current environment.', 'wp-command-center' ) );
}
```

and `OperationRegistry.php` sets `wp_cli_bridge.available = (new WpCliBridge())->is_available()`.

### 5.3 Root cause

`WpCliBridge::is_available()` (`includes/Operations/WpCliBridge.php`) called:

```php
$output = @shell_exec( 'wp --version 2>/dev/null' );
```

Under Apache/mod_php, the process PATH is `/usr/bin:/bin:/usr/sbin:/sbin`. The `wp` binary
lives at `/usr/local/bin/wp`, which is **not** on that PATH — `shell_exec` returns empty, so
`is_available()` returns `false`. From a CLI shell (`wp eval`), the user's PATH already
includes `/usr/local/bin`, so the same check returns `true` — making this bug invisible to
CLI-based testing and only reproducible through the actual web `/mcp` endpoint (the transport
Claude Desktop uses).

Confirmed via a temporary diagnostic endpoint:
```json
{
  "php_binary": "",
  "path_orig": "/usr/bin:/bin:/usr/sbin:/sbin",
  "wp_version_orig": "sh: wp: command not found"
}
```

A **second copy of the identical bug** exists in `SiteScanner::detect_wp_cli()`
(`includes/SiteIntelligence/SiteScanner.php`), feeding `capabilities.wp_cli` in the
`wpcc://context` and `wpcc://manifest` MCP resources and `RecommendationEngine`.

### 5.4 The fix

**File:** `includes/Operations/WpCliBridge.php` — new private helper, used by both
`is_available()` and `execute()`:

```php
private function shell_path_prefix(): string {
    $extra = array_filter( [
        PHP_BINDIR,
        '/usr/local/bin',
        '/opt/homebrew/bin',
    ] );
    $path = implode( ':', array_unique( array_merge( $extra, [ (string) getenv( 'PATH' ) ] ) ) );

    return 'PATH=' . escapeshellarg( $path ) . ' ';
}
```

- `is_available()`: `@shell_exec( $this->shell_path_prefix() . 'wp --version 2>/dev/null' )`
- `execute()`: `$shell_cmd = $this->shell_path_prefix() . $shell_cmd . ' --path=' . escapeshellarg( ABSPATH ) . ' --allow-root';`

`PHP_BINDIR` is a built-in PHP constant (defined in **every** SAPI, including mod_php/FPM) that
points at the bin directory of the **currently running PHP build**. Prepending it ensures
`wp`'s `#!/usr/bin/env php` shebang resolves to the *same* PHP installation that is serving the
request — and therefore the *same* `mysqli.default_socket` / `php.ini`, so the spawned `wp`
process connects to the correct database. (Without `PHP_BINDIR`, `env php` could resolve to an
unrelated PHP install with a different default MySQL socket, causing `wp` to connect to the
wrong database server.)

**File:** `includes/SiteIntelligence/SiteScanner.php` — `detect_wp_cli()` now delegates to the
fixed probe instead of duplicating it:

```php
private function detect_wp_cli( bool $shell_exec_enabled ): bool {
    if ( ! $shell_exec_enabled ) {
        return false;
    }
    return ( new \WPCommandCenter\Operations\WpCliBridge() )->is_available();
}
```

### 5.5 Validation evidence

**Before fix** (legacy command, web `/mcp` endpoint):
```json
{"jsonrpc":"2.0","id":1,"error":{"code":-32000,"message":"Operation is not available in the current environment."}}
```

**After fix** — same payload:
```json
{
  "jsonrpc": "2.0", "id": 1,
  "result": {"content": [{"type": "text", "text": "{\"command\":\"plugin_list\",\"output\":{\"count\":13,\"preview\":[...],\"truncated\":true}}"}]}
}
```

Additional live calls, all succeeding with `exitcode: 0`:
- `{"command":"option_get_siteurl"}` → `"output":"http://localhost/ClientProjects/WordPress/2026/plugins-dev"`, `"stderr":""`
- `{"command_id":"plugin_list"}` (structured mode) → `{"command_id":"plugin_list","exitcode":0,"risk_level":"low"}`

`wpcc://context.capabilities.wp_cli`: `false` → `true` (after clearing the 1-hour
`wpcc_site_intelligence_scan` transient cached from before the fix).

Regression suites:
- `tests/test-wp-cli-bridge.sh` → **7/7 passed**
- `tests/test-structured-wp-cli-runtime.sh` → **68/68 passed**
- `tests/test-agent-manifest.sh` → **43/43 passed**
- `tests/test-recommendations.sh` → **45/45 passed**
- `tests/test-recommendation-workflow.sh` → **39/39 passed**

---

## 6. Finding E — `settings_manage` Returns Embedded Error as a "Success"

### 6.1 Exact failing payload

```json
{"jsonrpc":"2.0","method":"tools/call","id":1,"params":{"name":"settings_manage","arguments":{}}}
```

(This is exactly what an agent sends if it doesn't know `action` is required and has no
`enum` to consult — see Finding C2.)

### 6.2 Exact response (before fix)

```json
{
  "jsonrpc": "2.0", "id": 1,
  "result": {
    "content": [
      {"type": "text", "text": "{\"error\":true,\"code\":\"wpcc_invalid_settings_action\",\"message\":\"Invalid settings action.\"}"}
    ]
  }
}
```

Note: this is a JSON-RPC **`result`**, not an `error` — the top-level shape says "success,"
while the payload inside `content[0].text` says `"error": true`. Compare with `theme_manage`
called with `{}`, which correctly returns a JSON-RPC `error`:

```json
{"jsonrpc":"2.0","id":1,"error":{"code":-32000,"message":"Invalid action: . Use theme_list, theme_install, theme_activate, theme_update, or theme_delete."}}
```

### 6.3 Root cause

`SettingsRuntimeManager::run()` (`includes/Operations/SettingsRuntimeManager.php`) returned a
plain PHP array `['error'=>true,'code'=>...,'message'=>...]` on an invalid action:

```php
public function run(array $p,array $cx=[]):array{
    $a=(string)($p['action']??'');
    if(!in_array($a,SettingsRegistry::ACTIONS,true))return $this->err('wpcc_invalid_settings_action',__('Invalid settings action.','wp-command-center'));
    ...
```

`OperationExecutor::run()` only branches to its failure path when `is_wp_error($result)` is
`true` (`includes/Operations/OperationExecutor.php:128`). A plain array — even one shaped like
`{error: true, code, message}` — falls through to `normalize_success()`, so
`tools_call()` reports `success: true` and emits a JSON-RPC `result`. This is inconsistent with
`PluginManager`, `ThemeManager`, and `DatabaseInspector`, which all return `new \WP_Error(...)`
for invalid actions.

### 6.4 The fix

**File:** `includes/Operations/SettingsRuntimeManager.php`

```php
public function run(array $p,array $cx=[]):array|\WP_Error{
    $a=(string)($p['action']??'');
    if(!in_array($a,SettingsRegistry::ACTIONS,true))return new \WP_Error('wpcc_invalid_settings_action',__('Invalid settings action.','wp-command-center'));
    $opts=[ ... ];
    [$method,$is_mutation]=$opts[$a];
    $result=$this->$method($p);
    if(isset($result['error'])){
        return new \WP_Error($result['code'],$result['message']);
    }
    if($is_mutation){
        ... // unchanged
```

This converts **both** the invalid-`action` case and any per-method error (e.g.
`general_update()`'s `wpcc_invalid_email`) into a proper `\WP_Error`, matching sibling handlers.
The public `rollback()` method (used directly by `RestApi::run_settings_rollback()`, which
checks `$result['error']` itself) is untouched — its array-based error contract is preserved.

### 6.5 Validation evidence

**Before fix:** embedded-error `result` as shown in §6.2.

**After fix** — same payload:
```json
{"jsonrpc":"2.0","id":1,"error":{"code":-32000,"message":"Invalid settings action."}}
```

Invalid-email mutation, before returned an embedded-error `result`; after:
```json
{"jsonrpc":"2.0","id":1,"error":{"code":-32000,"message":"Invalid email."}}
```

Valid call (`{"action":"settings_inventory"}`) continues to return a normal `result` with
`content` — unaffected.

Regression suite: `tests/test-site-settings-runtime.sh` → **24/24 passed**.

---

## 7. Full Regression Results (after all fixes)

| Suite | Result |
|---|---|
| `tests/test-mcp-tool-schema-compliance.sh` | 10/10 passed |
| `tests/test-mcp-runtime.sh` | 42/42 passed |
| `tests/test-mcp-scope-enforcement.sh` | 15/15 passed |
| `tests/test-site-settings-runtime.sh` | 24/24 passed |
| `tests/test-wp-cli-bridge.sh` | 7/7 passed |
| `tests/test-structured-wp-cli-runtime.sh` | 68/68 passed |
| `tests/test-agent-manifest.sh` | 43/43 passed |
| `tests/test-operations-registry.sh` | 18/18 passed |
| `tests/test-recommendations.sh` | 45/45 passed |
| `tests/test-recommendation-workflow.sh` | 39/39 passed |

**Total: 311/311 passed, 0 failed.**

---

## 8. Files Changed

| File | Change |
|---|---|
| `includes/Mcp/McpServerRuntime.php` | `tools_list()`: correct `boolean`/`object`/`array` type mapping; propagate `enum`/`default` (Findings C, C2) |
| `includes/Operations/OperationRegistry.php` | Added `enum` for `database_inspect.action` (`DatabaseRegistry::ACTIONS`) and `settings_manage.action` (`SettingsRegistry::ACTIONS`) (Finding C2) |
| `includes/Operations/WpCliBridge.php` | New `shell_path_prefix()` using `PHP_BINDIR`; applied in `is_available()` and `execute()` (Finding D) |
| `includes/SiteIntelligence/SiteScanner.php` | `detect_wp_cli()` delegates to `WpCliBridge::is_available()` instead of duplicating the broken probe (Finding D) |
| `includes/Operations/SettingsRuntimeManager.php` | `run()` returns `\WP_Error` for invalid action / per-method errors instead of an embedded-error array (Finding E) |

---

## 9. Bottom Line

- **`database_inspect` does not fail.** Full trace executed successfully with real data
  (`db_size_mb: 22.47`).
- The screenshot's four "Failed to call tool" entries were caused by **four independent bugs**
  (C, C2, D, E), all of which are now fixed and validated against the live `/mcp` HTTP endpoint
  — the same transport Claude Desktop uses — with 311/311 regression assertions passing.
