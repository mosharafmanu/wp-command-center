# MCP Relay Protocol Fix — WPCC Real World Finding #002

**Date:** 2026-06-13  
**Severity:** High (protocol violations prevent clean Claude Desktop handshake)  
**Status:** Fixed

---

## Root Cause

The MCP relay wrote JSON-RPC error responses for `notifications/initialized` to stdout,
violating JSON-RPC 2.0 §4.1: "The Server MUST NOT reply to a Notification."

**Protocol trace (before fix):**

```
Claude Desktop                       Relay                        WPCC
      │                                │                           │
      │── initialize (id=1) ──────────►│── POST /mcp ─────────────►│
      │                                │◄─ 200 {result:{...}} ────│
      │◄─ {result:{...}} ─────────────│                           │  ✓ valid
      │                                │                           │
      │── notifications/initialized ──►│── POST /mcp ─────────────►│
      │  (no id)                       │◄─ 202 {error:{...},id:null}│
      │◄─ {error:{...},id:null} ──────│                           │  ✗ UNEXPECTED
      │                                │                           │
      │  Claude Desktop MCP parser:                                 │
      │  invalid_union                                               │
      │  unrecognized_keys ["error"]                                 │
```

**Why it happened:**

1. `McpServerRuntime::handle()` had no handling for `notifications/initialized`, so it fell to the default `error(-32601, 'Method not found', null)` path.
2. The `error()` method built `{error: {code: -32601, ...}, id: null}`.
3. `handle()` wrapped it as `{jsonrpc: "2.0", id: null, error: {...}}`.
4. `McpRestApi::handle_mcp()` returned this as HTTP 202.
5. The relay parsed the response and wrote it to stdout.
6. Claude Desktop's MCP parser rejected the unexpected error response with `invalid_union` and `unrecognized_keys ["error"]`.

---

## Fix Applied

### 1. `McpServerRuntime::handle()` — Notification handling (JSON-RPC 2.0 §4.1)

Added early return for notifications (requests without an `id`):

```php
// Notifications — no response expected (JSON-RPC 2.0 §4.1)
if ( null === $id ) {
    if ( 'notifications/initialized' === $method ) {
        $this->audit( 'mcp.initialized', [], $context );
    }
    return [ '_skip' => true, '_notification' => $method ];
}
```

- `notifications/initialized` is now explicitly handled (audited, no response)
- All other notifications are silently skipped (sentinel returned)

### 2. `McpRestApi::handle_mcp()` — HTTP 204 for notifications

```php
// Notifications (JSON-RPC 2.0 §4.1): no response body expected.
if ( isset( $result['_skip'] ) && $result['_skip'] ) {
    return new \WP_REST_Response( null, 204 );
}
```

Returns HTTP 204 No Content for notifications — no JSON body, no error.

### 3. `wpcc-mcp-relay.mjs` — Defense in depth (3 layers)

**Layer 1 — HTTP 204 handling:**
```javascript
if (response.status === 204) {
    return null;  // notification processed, no body
}
```

**Layer 2 — Empty/null body handling:**
```javascript
if (!text || text === 'null') {
    return null;  // no content to forward
}
```

**Layer 3 — JSON-RPC response validation guard:**
```javascript
if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed) || parsed.id == null) {
    process.stderr.write(`relay: non-RPC response for ${method}, dropped\n`);
    return null;  // only valid JSON-RPC response objects with id reach stdout
}
```

Additionally, all diagnostic output uses `process.stderr.write()` — nothing but valid JSON-RPC responses ever reaches stdout.

---

## Protocol Trace (after fix)

```
Claude Desktop                       Relay                        WPCC
      │                                │                           │
      │── initialize (id=1) ──────────►│── POST /mcp ─────────────►│
      │                                │◄─ 200 {result:{...}} ────│
      │◄─ {result:{...}} ─────────────│                           │  ✓
      │                                │                           │
      │── notifications/initialized ──►│── POST /mcp ─────────────►│
      │  (no id)                       │◄─ 204 (no body) ─────────│
      │  (nothing sent to stdout)      │                           │  ✓ silent
      │                                │                           │
      │── tools/list (id=2) ──────────►│── POST /mcp ─────────────►│
      │                                │◄─ 200 {result:{tools}} ──│
      │◄─ {result:{tools}} ───────────│                           │  ✓
      │                                │                           │
      │── resources/list (id=3) ──────►│── POST /mcp ─────────────►│
      │                                │◄─ 200 {result:{...}} ────│
      │◄─ {result:{...}} ─────────────│                           │  ✓
```

No `invalid_union`, no `unrecognized_keys`. Clean handshake.

---

## End-to-End Validation

### Simulated MCP handshake via direct curl

```bash
# 1. Initialize
curl -s -X POST https://mosharafmanu.com/wp-json/wp-command-center/v1/mcp \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"test","version":"1.0"}},"id":1}'
# → {"jsonrpc":"2.0","id":1,"result":{"protocolVersion":"2024-11-05","capabilities":{...},"serverInfo":{...}}}

# 2. Notification (should return HTTP 204, no body)
curl -s -o /dev/null -w "%{http_code}" -X POST .../mcp \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"notifications/initialized","params":{}}'
# → 204

# 3. tools/list
curl -s -X POST .../mcp \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"tools/list","params":{},"id":2}'
# → {"jsonrpc":"2.0","id":2,"result":{"tools":[...]}}

# 4. resources/list
curl -s -X POST .../mcp \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"resources/list","params":{},"id":3}'
# → {"jsonrpc":"2.0","id":3,"result":{"resources":[...]}}

# 5. resources/read
curl -s -X POST .../mcp \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"resources/read","params":{"uri":"wpcc://manifest"},"id":4}'
# → {"jsonrpc":"2.0","id":4,"result":{"contents":[{...}]}}
```

### Claude Desktop verification

1. Update `claude_desktop_config.json` with the generated config
2. Restart Claude Desktop
3. Verify WP Command Center appears as a connected MCP server
4. Verify no `invalid_union` or `unrecognized_keys` errors in Claude Desktop logs
5. Verify tools, resources, and prompts are all discoverable

---

## Files Changed

| File | Change |
|------|--------|
| `includes/Mcp/McpServerRuntime.php` | Added notification handling — skip response for all methods without `id`, handle `notifications/initialized` explicitly |
| `includes/Mcp/McpRestApi.php` | Added HTTP 204 response for notifications |
| `sdk/javascript/wpcc-mcp-relay.mjs` | 3-layer defense: HTTP 204, empty body, JSON-RPC response validation; all diagnostics to stderr |

---

## What Was NOT Modified

- Token system (`AuthTokens.php`) — untouched
- REST API (`RestApi.php`) — untouched
- Integration generators (`ClaudeIntegration.php`, etc.) — untouched
- Operation pipeline — untouched
- Test files — untouched
