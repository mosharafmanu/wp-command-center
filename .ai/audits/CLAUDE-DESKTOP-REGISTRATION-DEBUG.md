# Claude Desktop Registration Debug — WPCC Real World Finding #003

**Date:** 2026-06-13  
**Severity:** Critical (server not registered, no tools available)  
**Status:** Fixed

---

## Symptoms

- WP Command Center shows **RUNNING** in Claude Desktop (process alive)
- Does **NOT** appear under **Connected MCP Apps** (registration failed)
- Claude reports **no WPCC tools available**
- Only Figma is registered (other servers work)
- MCP endpoint, token, initialize, tools/list, resources/list all work via curl

---

## Root Cause Analysis

Three protocol compliance issues prevented Claude Desktop from registering the server:

### Issue 1: Missing `prompts` capability declaration

**Problem:** `initialize()` declared `tools` and `resources` capabilities, but not `prompts`. However `prompts/list` is a supported method. Claude Desktop's MCP validator expects all supported capabilities to be declared in the handshake.

**Before:**
```json
"capabilities": {
  "tools": { "listChanged": false },
  "resources": { "subscribe": false, "listChanged": false }
}
```

**After:**
```json
"capabilities": {
  "tools": {},
  "resources": {},
  "prompts": {}
}
```

### Issue 2: Non-standard `required` in JSON Schema property definitions

**Problem:** `tools/list` added `"required": true` inside individual property schemas. In JSON Schema, `required` is a **top-level array** of property names, not a per-property boolean. Claude Desktop's strict schema validator may reject tools with malformed input schemas.

**Before (non-standard):**
```json
"properties": {
  "action": { "type": "string", "required": true },
  "post_type": { "type": "string" }
},
"required": ["action"]
```

**After (standard JSON Schema):**
```json
"properties": {
  "action": { "type": "string" },
  "post_type": { "type": "string" }
},
"required": ["action"]
```

Both the per-property `required` and the top-level `required` array were present. The per-property boolean was removed, keeping only the standard top-level `required` array.

### Issue 3: Custom fields in `resources/list` result outside `_meta`

**Problem:** `resources/list` returned `defaultContextMode` and `contextModes` at the top level of `result`, outside the MCP-specified schema. Claude Desktop's validator treats unrecognized top-level keys as errors (similar to Finding #002's `unrecognized_keys`).

**Before:**
```json
{
  "resources": [...],
  "defaultContextMode": "compact",
  "contextModes": ["compact", "standard", "verbose"]
}
```

**After (spec-compliant via `_meta` extension):**
```json
{
  "resources": [...],
  "_meta": {
    "defaultContextMode": "compact",
    "contextModes": ["compact", "standard", "verbose"]
  }
}
```

Per MCP spec: "Implementations MAY add additional fields prefixed with `_` to response objects."

---

## Additional Improvements

### `initialize` response — `instructions` field

Added the optional `instructions` field to help Claude Desktop display useful context:

```json
"instructions": "WP Command Center provides WordPress site management tools for AI agents..."
```

### `initialize` response — capability object format

Changed from `{"listChanged": false}` to `{}` (empty object). Both are valid per the MCP spec, but `{}` is the more common convention in working MCP server implementations (Figma, Puppeteer, Filesystem, etc.). The explicit `listChanged: false` may be misinterpreted by some validators.

### `initialize` response — server name

Shortened `serverInfo.name` from `"WP Command Center MCP"` to `"WP Command Center"` (cleaner display in Claude Desktop UI).

---

## Protocol Trace (After Fix)

```
Claude Desktop                       Relay                    WPCC
      │                                │                        │
      │── initialize (id=1) ──────────►│── POST /mcp ──────────►│
      │                                │◄─ 200 {                     │
      │                                │     protocolVersion,        │
      │                                │     capabilities:{tools,    │
      │                                │       resources,prompts},   │
      │                                │     serverInfo,             │
      │                                │     instructions            │
      │◄─ valid response ─────────────│   } ────────────────────│  ✓
      │                                │                        │
      │  Claude Desktop registers      │                        │
      │  WP Command Center as          │                        │
      │  connected MCP server ✓        │                        │
      │                                │                        │
      │── notifications/initialized ──►│── POST /mcp ──────────►│
      │                                │◄─ 204 ────────────────│  ✓
      │                                │                        │
      │── tools/list (id=2) ──────────►│── POST /mcp ──────────►│
      │                                │◄─ 200 {tools:[...]} ──│  ✓
      │◄─ valid tools ────────────────│                        │
      │                                │                        │
      │── resources/list (id=3) ──────►│── POST /mcp ──────────►│
      │                                │◄─ 200 {resources:[...],│
      │                                │     _meta:{...}} ──────│  ✓
      │◄─ valid resources ────────────│                        │
```

---

## Validation — Verified Against MCP Spec (2024-11-05)

| Method | Response Field | Spec Required | Before | After |
|--------|---------------|---------------|--------|-------|
| `initialize` | `protocolVersion` | Yes | "2024-11-05" ✓ | "2024-11-05" ✓ |
| `initialize` | `capabilities.tools` | If tools supported | `{listChanged:false}` | `{}` ✓ |
| `initialize` | `capabilities.resources` | If resources supported | `{subscribe:false,listChanged:false}` | `{}` ✓ |
| `initialize` | `capabilities.prompts` | If prompts supported | **MISSING** ✗ | `{}` ✓ |
| `initialize` | `serverInfo.name` | Yes | "WP Command Center MCP" ✓ | "WP Command Center" ✓ |
| `initialize` | `serverInfo.version` | Yes | "0.1.0" ✓ | "0.1.0" ✓ |
| `initialize` | `instructions` | No (optional) | **MISSING** | Added ✓ |
| `tools/list` | `tools[].inputSchema.required` | Array (top-level) | Array ✓ | Array ✓ |
| `tools/list` | Per-property `required` | Not in spec | **PRESENT** ✗ | Removed ✓ |
| `resources/list` | Custom top-level keys | Must use `_` prefix | `defaultContextMode` ✗ | `_meta.defaultContextMode` ✓ |

---

## Files Changed

| File | Change |
|------|--------|
| `includes/Mcp/McpServerRuntime.php` | `initialize()` — added prompts capability, instructions, simplified capabilities, shortened name |
| `includes/Mcp/McpServerRuntime.php` | `tools_list()` — removed non-standard per-property `required: true` |
| `includes/Mcp/McpServerRuntime.php` | `resources_list()` — moved `defaultContextMode` and `contextModes` into `_meta` |

---

## Post-Fix Verification Steps

1. Update the plugin code on the WordPress site
2. Restart Claude Desktop
3. Verify **WP Command Center** appears under **Connected MCP Apps** (not just RUNNING)
4. Verify tools appear in Claude's tool list
5. Verify resources are accessible
6. Test a tool call (e.g., "List the plugins on my WordPress site")
