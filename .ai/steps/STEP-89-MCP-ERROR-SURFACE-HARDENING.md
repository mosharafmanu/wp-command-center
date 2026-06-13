# STEP 89 — MCP Error Surface Hardening

## Goal

Replace opaque MCP tool failures ("Failed to call tool …", with no detail
reaching the model) with structured, AI-readable errors:

```json
{ "isError": true, "code": "wpcc_patch_breaks_header", "message": "Patch would invalidate plugin header" }
```

## Context / why

During the STEP-88 header-guard test, Claude Desktop reported that
`patch_create`/`patch_apply` returned a bare "Tool execution failed" with no
code or message — even though the HTTP wire response carried a full
`error.message` and `data.code`. Root cause: WPCC returned tool-execution
failures as **JSON-RPC protocol errors** (`-32000`/`-32001`). MCP clients render
protocol errors as a generic banner and do **not** feed the message/code into
the model's context. Per the MCP spec, *tool execution* errors should be
returned as a **result with `isError: true`** so the model can read and recover
from them; JSON-RPC errors are reserved for *transport/protocol* failures.

A second gap surfaced during acceptance testing: several runtime managers
(media, and the other `*RuntimeManager` classes) report failures **in-band** as
a success-shaped result `{ error:true, code, message }` via their own `error()`
helper, rather than a `WP_Error`. Those never reached the error path at all.

## Implementation

All changes are in `includes/Mcp/McpServerRuntime.php::tools_call()` plus one new
helper — no operation/handler logic changed, so backward compatibility, security
modes, approval workflow, rollback, and REST parity are all preserved.

### 1. `tool_error()` helper

```php
private function tool_error( string $code, string $message ): array {
    return [ 'result' => [
        'content' => [ [ 'type' => 'text', 'text' => wp_json_encode( [
            'isError' => true, 'code' => $code, 'message' => $message,
        ] ) ] ],
        'isError' => true,
    ] ];
}
```

The MCP `result.isError` flag is set, and the same `{isError,code,message}` shape
is embedded in the tool content so it is legible whether or not a client honours
`result.isError`.

### 2. Failures routed through `tool_error()`

| Failure class | Before | After |
|---|---|---|
| Operation execution failure (`!success`) | JSON-RPC `-32000` + `data.code` | `isError` result + `code` |
| Read-only scope denial | JSON-RPC `-32001` | `isError` result, code `wpcc_token_read_only` |
| Missing-capability denial | JSON-RPC `-32001` | `isError` result, code `wpcc_capability_denied` |
| In-band manager error `{error:true,code}` (e.g. media) | success result, no `isError` | `isError` result + `code` |

### 3. Transport failures preserved

Genuine JSON-RPC protocol errors stay as `-326xx` (via the untouched `error()`
helper):

- Unknown method → `-32601`
- Unknown resource (`resources/read`) → `-32002`

These are transport-level and not actionable by the model as tool output.

## Acceptance tests — `tests/test-mcp-error-surface.sh` (18/18)

| Scenario | Result |
|---|---|
| Header-breaking patch | `isError`, `wpcc_patch_breaks_header` ✅ |
| Invalid rollback | `isError`, `wpcc_patch_not_found` ✅ |
| Missing plugin | `isError`, `wpcc_plugin_not_found` ✅ |
| Missing media (in-band error) | `isError`, `wpcc_media_not_found` ✅ |
| Permission-denied patch (read-only token) | `isError`, `wpcc_token_read_only` ✅ |
| Transport failure (unknown method) | JSON-RPC `-32601`, no `isError` ✅ |
| Success path | unchanged, no `isError` ✅ |
| REST parity | same `wpcc_patch_breaks_header` over REST ✅ |

## Regression / test maintenance

The MCP error contract changed from `error.{code,data.code,message}` to
`result.content[].text → {isError,code,message}`, so three suites that asserted
on the old shape were updated (mechanical reads, same assertions):

- `tests/test-mcp-scope-enforcement.sh` — scope denial now `wpcc_token_read_only` isError.
- `tests/test-capability-bootstrap.sh` — read-only denial now isError.
- `tests/test-safe-updates-hardening.sh` — added shape-agnostic `err_code`/`err_msg` helpers.

## Files changed

- `includes/Mcp/McpServerRuntime.php` — `tool_error()`; route operation/scope/
  capability/in-band failures through it; preserve protocol errors.
- `tests/test-mcp-error-surface.sh` — **new**, 18 acceptance assertions.
- `tests/test-mcp-scope-enforcement.sh`, `tests/test-capability-bootstrap.sh`,
  `tests/test-safe-updates-hardening.sh` — updated to the new error shape.

## Backward compatibility & preserved guarantees

- REST responses are unchanged (still `{code,message}` / in-band `{error,code}`),
  so REST agents are unaffected.
- Security modes, approval workflow (pending_approval is a success result, not an
  error — untouched), rollback, and capability enforcement are unchanged — only
  the *representation* of a denial/failure changed, never the decision.
- Success tool results are byte-for-byte unchanged.

## Remaining risks / follow-ups

- The in-band `{error:true,code}` managers still return that shape over REST. A
  later cleanup could migrate the `*RuntimeManager::error()` helpers to `WP_Error`
  for one uniform internal convention, but that is deferred (STEP 90+ touches the
  media runtime directly and can start it).
