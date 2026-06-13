# Admin Connection Test Fix — WPCC Real World Finding #006

**Date:** 2026-06-13  
**Severity:** High (connection test always fails, misleading users)  
**Status:** Fixed

---

## Root Cause

The Connection Test JavaScript (`includes/Admin/views/ai-integrations.php`) made `fetch()` calls to REST API endpoints **without an `Authorization` header**. All WPCC REST endpoints require a bearer token:

```
GET  /wp-json/wp-command-center/v1/health        → require_read → 401 Missing API token
GET  /wp-json/wp-command-center/v1/agent/manifest → require_read → 401 Missing API token
POST /wp-json/wp-command-center/v1/mcp            → require_read → 401 Missing API token
```

Every test call received HTTP 401, and the test displayed all checks as failed — but gave **no indication why** (no HTTP status, no error message).

### Before (broken fetch chain — no auth headers at all):

```javascript
fetch(baseUrl + '/health')                                        // ← no Authorization header
  .then(function(r) { ... })
  .then(function(r) {
    checks.push({ name: 'Health endpoint', pass: r.ok && ... });  // ← 401 → pass=false
    return fetch(baseUrl + '/agent/manifest')                     // ← no Authorization header
      .then(...);
  })
  .then(function(r) {
    checks.push({ name: 'Agent manifest', pass: r.ok && ... });   // ← 401 → pass=false
    return fetch(baseUrl + '/claude/discovery')                   // ← no Authorization header
  ...
```

### Comparison with direct curl (which works):

```bash
curl -H "Authorization: Bearer TOKEN" https://.../mcp -d '{"jsonrpc":"2.0","method":"initialize","params":{},"id":1}'
# → {"jsonrpc":"2.0","id":1,"result":{...}}  ← WORKS
```

The direct curl includes `Authorization: Bearer TOKEN` — the JS test did not.

---

## Fix Applied

### 1. Added token input field

The Connection Test panel now includes a text input for the API token:

```html
<input type="text" id="wpcc-test-token" placeholder="wpcc_..."
       value="<?php echo esc_attr( $wpcc_new_token ); ?>">
```

- Pre-filled when a token was just generated
- User can paste an existing token
- Clear error message shown when no token is provided

### 2. Added Authorization header to all fetches

Every fetch now sends `Authorization: Bearer <token>`:

```javascript
var authHeader = { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json' };
var authHeaderGet = { 'Authorization': 'Bearer ' + token };

fetch(baseUrl + '/health', { headers: authHeaderGet })        // ← now has auth
fetch(baseUrl + '/agent/manifest', { headers: authHeaderGet }) // ← now has auth
fetch(baseUrl + '/mcp', { method: 'POST', headers: authHeader, body: ... }) // ← now has auth
```

### 3. Detailed failure output per check

Each failed check now shows the HTTP status code and error message:

```
✗ Health endpoint
  HTTP 401: Missing API token. Provide an "Authorization: Bearer <token>" header.

✗ MCP initialize
  HTTP 401: wpcc_invalid_token — Token not found or revoked.
```

### 4. No-token guard

If the user clicks "Test Connection" without providing a token:

```
✗ No token: Paste an API token above or generate one in the Configuration tab.
```

### 5. Streamlined test flow

Removed the redundant `/claude/discovery` check (duplicates MCP initialize/tools functionality). Current test sequence:

| # | Check | Endpoint | Auth Required |
|---|-------|----------|---------------|
| 1 | Health endpoint | `GET /health` | Yes (read) |
| 2 | Agent manifest | `GET /agent/manifest` | Yes (read) |
| 3 | MCP initialize | `POST /mcp` | Yes (read) |
| 4 | MCP resources | `POST /mcp` (resources/list) | Yes (read) |
| 5 | MCP tools | `POST /mcp` (tools/list) | Yes (read) |

---

## Test Cases

### Manual verification in admin UI:

1. **No token** → Click "Test Connection" with empty input → shows "No token" error
2. **Invalid token** → Paste `wpcc_fake_token` → all checks fail with HTTP 401 details
3. **Valid token** → Paste a real token → all checks pass (5/5 green checkmarks)
4. **Newly generated token** → Generate token → token auto-fills → click test → all pass

### Automated test script (`tests/test-admin-connection.sh`):

Tests the REST API directly (simulates what the admin JS does):

```bash
# Test 1: No token → expect 401
curl -s -o /dev/null -w "%{http_code}" "$WPCC_BASE/health"
# → 401

# Test 2: Invalid token → expect 401
curl -s -H "Authorization: Bearer wpcc_fake_token" "$WPCC_BASE/health"
# → 401

# Test 3: Valid token → expect 200
curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/health"
# → 200 {"status":"ok"}

# Test 4: MCP initialize
curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"initialize","params":{"protocolVersion":"2024-11-05"},"id":1}' \
  "$WPCC_BASE/mcp"
# → {"jsonrpc":"2.0","id":1,"result":{"serverInfo":{"name":"WP Command Center",...}}}

# Test 5: MCP resources/list
curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"resources/list","id":2}' \
  "$WPCC_BASE/mcp"
# → {"jsonrpc":"2.0","id":2,"result":{"resources":[...]}}  (7 resources)

# Test 6: MCP tools/list
curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"tools/list","id":3}' \
  "$WPCC_BASE/mcp"
# → {"jsonrpc":"2.0","id":3,"result":{"tools":[...]}}  (>0 tools)
```

---

## What Was NOT Modified

- MCP runtime (McpServerRuntime.php) — untouched
- REST API (RestApi.php) — untouched
- Token system (AuthTokens.php) — untouched
- No backend changes at all — this was purely an admin UI test harness bug

---

## Files Changed

| File | Change |
|------|--------|
| `includes/Admin/views/ai-integrations.php` | Added token input field, Authorization header to all fetches, detailed failure output, no-token guard, streamlined test flow |
