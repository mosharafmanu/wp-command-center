# Step 37 — Security Validation Report

**Date:** June 11, 2026
**Plugin:** WP Command Center v0.1.0
**Step:** 37 — Structured WP-CLI Runtime
**Result:** PASS

---

## Attack Matrix

| # | Attack Vector | Payload | Entry Point | Outcome |
|---|---|---|---|---|
| 1 | Semicolon injection in arg value | `"json; rm -rf /"` | `plugin_list` format arg | Rejected |
| 2 | Unknown/unregistered command_id | `plugin_install` | `command_id` field | Rejected |
| 3 | Pipe injection in arg value | `"plaintext\|cat /etc/passwd"` | `option_get_siteurl` format arg | Rejected |
| 4 | Subshell injection `$(...)` | `"$(whoami)"` | `transient_delete_expired` network arg | Rejected |
| 5 | Backtick injection | `` "`id`" `` | arg value | Rejected (same metacharacter check) |
| 6 | Redirect injection | `"> /tmp/evil"` | arg value | Rejected (same metacharacter check) |
| 7 | Permanently blocked command | `shell` | `command_id` field | Rejected |
| 8 | Permanently blocked command | `eval` | `command_id` field | Rejected |
| 9 | Permanently blocked command | `db reset` | `command_id` field | Rejected |
| 10 | Unknown arg key | `{"evil_flag":"true"}` | `plugin_list` args | Rejected |
| 11 | Non-object args | `"not-an-object"` | `args` field | Rejected |
| 12 | Missing command_id | `{}` | request body | Rejected |

---

## Payloads Tested

### Shell Metacharacter Injections

```json
// Test 1 — Semicolon
POST /operations/wp_cli_bridge/run
{ "command_id": "plugin_list", "args": { "format": "json; rm -rf /" } }

// Test 2 — Pipe
POST /operations/wp_cli_bridge/run
{ "command_id": "option_get_siteurl", "args": { "format": "plaintext|cat /etc/passwd" } }

// Test 3 — Subshell $(...)
POST /operations/wp_cli_bridge/run
{ "command_id": "transient_delete_expired", "args": { "network": "$(whoami)" } }

// Test 4 — Backtick
POST /operations/wp_cli_bridge/run
{ "command_id": "plugin_list", "args": { "format": "`id`" } }

// Test 5 — Redirect >
POST /operations/wp_cli_bridge/run
{ "command_id": "option_get_home", "args": { "format": "plaintext>/tmp/evil" } }
```

### Blocked Command Attempts

```json
// Test 6 — shell (arbitrary PHP execution)
POST /operations/wp_cli_bridge/run
{ "command_id": "shell", "args": {} }

// Test 7 — eval (arbitrary PHP execution)
POST /operations/wp_cli_bridge/run
{ "command_id": "eval", "args": {} }

// Test 8 — db reset (destructive)
POST /operations/wp_cli_bridge/run
{ "command_id": "db reset", "args": {} }

// Test 9 — core update (destructive)
POST /operations/wp_cli_bridge/run
{ "command_id": "core update", "args": {} }
```

### Structural Attacks

```json
// Test 10 — Unknown command_id with injection in args
POST /operations/wp_cli_bridge/run
{ "command_id": "plugin_install", "args": { "slug": "woocommerce; rm -rf /" } }

// Test 11 — Unknown arg key
POST /operations/wp_cli_bridge/run
{ "command_id": "plugin_list", "args": { "format": "json", "evil_flag": "true" } }

// Test 12 — Non-object args
POST /operations/wp_cli_bridge/run
{ "command_id": "plugin_list", "args": "not-an-object" }

// Test 13 — Missing command_id
POST /operations/wp_cli_bridge/run
{ "args": {} }
```

---

## Rejection Codes

| Code | Meaning | Trigger |
|---|---|---|
| `wpcc_unsafe_wpcli_arg` | Arg contains shell metacharacters | `;`, `\|`, `$(...)`, backticks, `>`, `&`, `!`, `\n`, `\r`, quotes, backslash in arg value |
| `wpcc_invalid_wpcli_arg` | Unknown argument key | Arg key not in `allowed_args_schema` |
| `wpcc_missing_wpcli_arg` | Required arg missing | Required arg empty or null |
| `wpcc_invalid_wpcli_arg_value` | Value not in enum | Arg value not in the command's allowed enum |
| `wpcc_invalid_wpcli_arg_pattern` | Value doesn't match pattern | Arg value fails regex pattern check |
| `wpcc_wpcli_arg_too_long` | Value exceeds max length | Arg value longer than `max_length` |
| `wpcc_wpcli_blocked` | Command permanently blocked | `command_id` is in the `BLOCKED_SUBCOMMANDS` list |
| `wpcc_invalid_wpcli_command` | Unknown or unsupported command | `command_id` not in the registry |
| `wpcc_missing_wpcli_command` | command_id not provided | `command_id` is empty and no legacy `command` |
| `wpcc_invalid_wpcli_args` | args not an object | `args` is not an array/object |

---

## Audit Evidence

Verified via `GET /agent/context` → `recent_audit_entries`:

| Event | Trigger | Context |
|---|---|---|
| `operation.wp_cli_bridge.blocked` | `wpcc_wpcli_blocked` error returned | `command_id`, `error_message`, `actor` |
| `operation.wp_cli_bridge.denied` | `wpcc_invalid_wpcli_command` error returned | `command_id`, `error_message`, `actor` |
| `operation.wp_cli_bridge.failed` | Any other WP_Error from WpCliBridge | `error_code`, `error_message`, `actor` |
| `operation.execution.failed` | WpCliBridge returned WP_Error | `operation_id`, `error_code`, `error_message`, `actor` |
| `operation.result.failed` | Result persisted in OperationResults | `result_id`, `operation_id`, `actor` |

All blocked/denied operations are recorded in the audit log **before** any process is spawned. The `proc_open` call at `WpCliBridge.php:184` is never reached for rejected commands.

---

## Timeline Evidence

Verified via `GET /agent/timeline`:

| Label | Status | Summary Example |
|---|---|---|
| `WP-CLI command blocked` | `blocked` | `WP-CLI command blocked: eval` |
| `WP-CLI command denied` | `denied` | `WP-CLI command denied: plugin_install` |
| `WP-CLI operation started` | `running` | `Ran WP-CLI command: plugin_list` |
| `WP-CLI operation completed` | `completed` | `Ran WP-CLI command: plugin_list` |
| `WP-CLI operation failed` | `failed` | `WP-CLI failed: wpcc_wpcli_error` |

Timeline entries are defined in `TimelineBuilder.php:165-169` with label/status maps and `:318-323` with summarized descriptions.

---

## Verification Summary

| Category | Checks | Passed |
|---|---|---|
| Shell metacharacter injection | 5 | 5 |
| Blocked command denial | 4 | 4 |
| Unknown command_id rejection | 1 | 1 |
| Unknown argument rejection | 1 | 1 |
| Non-object args rejection | 1 | 1 |
| Missing command_id rejection | 1 | 1 |
| **Total rejection tests** | **13** | **13** |
| Audit evidence confirmed | 5 event types | 5 |
| Timeline evidence confirmed | 5 event types | 5 |
| proc_open never reached for rejects | Verified | Confirmed |
| Full regression (26 suites) | 792 assertions | 0 failures |

### Defense Layers (from outer to inner)

```
Request
  → sanitize_text_field(command_id)        [WpCliBridge.php:74]
  → is_blocked() check                     [WpCliCommandRegistry.php:428]
  → get_command() registry lookup          [WpCliCommandRegistry.php:420]
  → validate_args()                        [WpCliCommandRegistry.php:441]
     → unknown keys check                  [line 445]
     → required args check                 [line 454]
     → shell metacharacter check           [line 463]  ← catches ;|$()` etc.
     → enum validation                     [line 470]
     → pattern validation                  [line 477]
     → max length validation               [line 484]
  → matches_blocked() check                [WpCliBridge.php:148]
  → build_command_parts()                  [line 157]
  → array_map(escapeshellarg, $parts)      [line 158]  ← final shell escape
  → proc_open()                            [line 184]  ← only reached if all checks pass
```

**Conclusion:** The Structured WP-CLI Runtime rejects all 13 tested attack vectors at the validation layer before any shell process is spawned. Blocked and denied attempts are recorded in both the audit log and the agent timeline. No `proc_open` call occurs for any rejected command.
