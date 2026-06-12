# MCP Attachment Failure Root Cause — WPCC Real World Finding #003 (continued)

**Date:** 2026-06-13  
**Severity:** Critical (server un-registerable by Claude Desktop)  
**Status:** Fixed

---

## Root Cause

**PHP `[]` serializes to JSON `[]` (array), but the MCP spec requires `{}` (object) for capability declarations.**

In the `initialize` response, capability values were empty PHP arrays:

```php
'capabilities' => [
    'tools'     => [],   // PHP [] → JSON []  ✗
    'resources' => [],   // PHP [] → JSON []  ✗
    'prompts'   => [],   // PHP [] → JSON []  ✗
],
```

This produced:

```json
"capabilities": {
    "tools": [],       // ARRAY — MCP spec requires OBJECT {}
    "resources": [],   // ARRAY — MCP spec requires OBJECT {}
    "prompts": []      // ARRAY — MCP spec requires OBJECT {}
}
```

Claude Desktop's MCP parser expects capability values to be objects. When it encounters an array, the type union fails to match, and registration is silently rejected. The server process stays alive (shown as "RUNNING") but never appears under "Connected MCP Apps."

---

## MCP Spec Reference

From `@modelcontextprotocol/sdk` types:

```typescript
interface InitializeResult {
  protocolVersion: string;
  capabilities: ServerCapabilities;   // ← OBJECT of capability objects
  serverInfo: Implementation;
  instructions?: string;
}

interface ServerCapabilities {
  experimental?: Record<string, object>;   // ← object values
  logging?: object;                        // ← object
  prompts?: object;                        // ← object
  resources?: object;                      // ← object
  tools?: object;                          // ← object
}
```

Every capability value is typed as `object`, not `array`. PHP's `json_encode()` maps:
- PHP `[]` (empty array) → JSON `[]` (array) ← **WRONG**
- PHP `new \stdClass()` → JSON `{}` (object) ← **CORRECT**
- PHP `(object)[]` → JSON `{}` (object) ← **CORRECT**

---

## Exact Invalid Payload (Before Fix)

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "result": {
    "protocolVersion": "2024-11-05",
    "capabilities": {
      "tools": [],           // ✗ ARRAY — should be {}
      "resources": [],       // ✗ ARRAY — should be {}
      "prompts": []          // ✗ ARRAY — should be {}
    },
    "serverInfo": {
      "name": "WP Command Center",
      "version": "0.1.0"
    },
    "instructions": "..."
  }
}
```

**How Claude Desktop's parser fails:**
The `capabilities` object is validated against a Zod/TypeScript union of `ServerCapabilities`. The `tools` field expects `object | undefined`. When it receives `[]` (array), the union discriminator fails with an error like `invalid_union` or `invalid_type`. Claude Desktop treats this as a failed handshake and refuses to register the server, but keeps the process alive (it waits for a valid response).

---

## Fixed Payload

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "result": {
    "protocolVersion": "2024-11-05",
    "capabilities": {
      "tools": {},           // ✓ OBJECT
      "resources": {},       // ✓ OBJECT
      "prompts": {}          // ✓ OBJECT
    },
    "serverInfo": {
      "name": "WP Command Center",
      "version": "0.1.0"
    },
    "instructions": "WP Command Center provides WordPress site management tools for AI agents..."
  }
}
```

---

## Comparison: Figma MCP Server (known working)

```json
{
  "result": {
    "protocolVersion": "2024-11-05",
    "capabilities": {
      "tools": {}            // ← OBJECT, not array
    },
    "serverInfo": {
      "name": "Figma",
      "version": "1.0.0"
    }
  }
}
```

Figma uses `{}` (objects) for capabilities. WPCC now matches this pattern.

---

## Additional Audit: resources/list _meta

Confirmed `_meta` fix is in source code (line 120):

```php
return [
    'result' => [
        'resources' => $resources,
        '_meta'     => [
            'defaultContextMode' => $mode,
            'contextModes'       => ContextModeOptimizer::MODES,
        ],
    ],
];
```

If the live site still shows these fields at root level, the deployed code has not been updated. This is a deployment/cache issue, not a source issue.

---

## PHP → JSON Type Mapping Reference

| PHP Value | JSON Output | MCP Usage |
|-----------|------------|-----------|
| `[]` | `[]` (array) | Sequential lists: `resources`, `tools`, `required`, `contents` |
| `['key' => 'val']` | `{"key":"val"}` (object) | Associative maps: `properties`, `serverInfo`, `result` |
| `new \stdClass()` | `{}` (empty object) | Empty capability objects: `tools`, `resources`, `prompts` |
| `(object)[]` | `{}` (empty object) | Alternative for empty objects |
| `null` | `null` | Absent fields |

**Key rule:** In PHP, use `new \stdClass()` or `(object)[]` when you need an empty JSON object `{}`. Never use `[]` for JSON objects.

---

## Fix Applied

**File:** `includes/Mcp/McpServerRuntime.php:80-83`

```php
// Before (WRONG):
'capabilities' => [
    'tools'     => [],
    'resources' => [],
    'prompts'   => [],
],

// After (CORRECT):
'capabilities' => [
    'tools'     => new \stdClass(),
    'resources' => new \stdClass(),
    'prompts'   => new \stdClass(),
],
```

---

## Verification

```bash
# Before fix:
php -r "echo json_encode(['capabilities'=>['tools'=>[],'resources'=>[],'prompts'=>[]]]);"
# → {"capabilities":{"tools":[],"resources":[],"prompts":[]}}  ✗ arrays

# After fix:
php -r "echo json_encode(['capabilities'=>['tools'=>new stdClass(),'resources'=>new stdClass(),'prompts'=>new stdClass()]]);"
# → {"capabilities":{"tools":{},"resources":{},"prompts":{}}}  ✓ objects
```
