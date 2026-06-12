# WP Command Center — MCP Integration Guide

## Overview

WP Command Center exposes a standards-based **Model Context Protocol (MCP)** server via a single REST endpoint. Any MCP-compatible AI client can connect to it using a bearer token. All operations go through the same capability → approval → queue → execute pipeline as the REST API.

---

## 1. Architecture

```
AI Client (Claude Desktop, Codex, Gemini, Cursor, etc.)
    │
    │  JSON-RPC 2.0 over HTTPS
    ▼
POST /wp-json/wp-command-center/v1/mcp
Authorization: Bearer <token>
    │
    ▼
McpRestApi.php ──► AuthTokens::validate()
    │
    ▼
McpServerRuntime.php
    │
    ├── resources/* ──► REST API / CapabilityRegistry / OperationRegistry / OperationQueue / OperationResults / RecommendationEngine
    ├── tools/*      ──► CapabilityRegistry::validate() → OperationExecutor::run()
    └── prompts/*    ──► Static informational prompts
```

**Protocol:** JSON-RPC 2.0  
**MCP Version:** `2024-11-05`  
**Endpoint:** `POST /wp-json/wp-command-center/v1/mcp`  
**Auth:** `Authorization: Bearer <token>` header (read-only or full scope)

### Context Modes

All MCP resources and tools support `context_mode`:

| Mode | Behavior |
|---|---|
| `compact` | Default. Counts, summaries, top findings, and at most five preview items from large collections. |
| `standard` | Backward-compatible full response shape. |
| `verbose` | Full response, reserved for explicit deep inspection. |

Use compact first and request standard or verbose only when the summary shows that more detail is needed.

---

## 2. Endpoint

### Request

```bash
curl -X POST https://yoursite.com/wp-json/wp-command-center/v1/mcp \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"initialize","params":{},"id":1}'
```

### Response (success)

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "result": {
    "protocolVersion": "2024-11-05",
    "capabilities": {
      "tools": { "listChanged": false },
      "resources": { "subscribe": false, "listChanged": false }
    },
    "serverInfo": {
      "name": "WP Command Center MCP",
      "version": "0.1.0"
    }
  }
}
```

### Response (error)

```json
{
  "jsonrpc": "2.0",
  "error": {
    "code": -32601,
    "message": "Method not found"
  },
  "id": 1
}
```

### Standard JSON-RPC Error Codes

| Code | Meaning |
|---|---|
| `-32700` | Parse error (invalid JSON) |
| `-32601` | Method not found |
| `-32603` | Internal error (uncaught exception) |
| `-32000` | Tool execution failed |
| `-32001` | Operation denied (missing capability) |
| `-32002` | Resource not found |

---

## 3. Authentication

Every MCP request must include an `Authorization: Bearer <token>` header. The token is validated by `AuthTokens::validate()`:

1. Parse the `Authorization` header.
2. Validate the token (reject if revoked, expired, or invalid).
3. Extract the `token_id` for capability checks and the `actor` for audit logging.

MCP requests use the same token pool as the REST API — tokens created in **Command Center → Settings → API Tokens** work for both.

The permission callback (`McpRestApi::require_read()`) requires the token to have at least `read_only` scope. The actual operation capabilities are checked at tool invocation time via `CapabilityRegistry::validate()`.

---

## 4. Resources

### 4.1 Listing Resources

**Method:** `resources/list`

Returns 7 MCP resources:

| URI | Name | Description | Source |
|---|---|---|---|
| `wpcc://manifest` | Agent Manifest | Full self-describing API manifest | `GET /agent/manifest` (REST) |
| `wpcc://context` | Agent Context | Runtime context snapshot | `GET /agent/context` (REST) |
| `wpcc://capabilities` | Capabilities | Platform capabilities and assignments | `CapabilityRegistry::get_summary()` |
| `wpcc://operations` | Operations | Available operations with parameters | `OperationRegistry::get_operations()` |
| `wpcc://queue` | Queue Status | Operation queue state (pending/running/failed counts) | `OperationQueue` |
| `wpcc://results` | Results | Recent operation results (last 10) | `OperationResults::list_results()` |
| `wpcc://recommendations` | Recommendations | Open recommendations (last 20) | `RecommendationEngine::list()` |

All resource content is redacted via `Redactor::redact_recursive()` before being returned.

### 4.2 Reading a Resource

**Method:** `resources/read`  
**Params:** `{ "uri": "wpcc://capabilities", "context_mode": "compact" }`

Requests with unknown URIs return `-32002` (resource not found).

---

## 5. Tools

### 5.1 Listing Tools

**Method:** `tools/list`

Returns all 15 operation families from `OperationRegistry::get_operations()` as MCP tools. Each tool includes:

```json
{
  "name": "plugin_manage",
  "description": "Plugin Management: Safely inspect and manage WordPress plugins...",
  "inputSchema": {
    "type": "object",
    "properties": {
      "action": {
        "type": "string",
        "description": "The plugin action to perform."
      },
      "slug": {
        "type": "string",
        "description": "The plugin slug (required for all actions except plugin_list)."
      },
      "context_mode": {
        "type": "string",
        "enum": ["compact", "standard", "verbose"],
        "default": "compact"
      }
    },
    "required": ["action"]
  }
}
```

**Parameter type mapping:**
- Operation `string` → MCP `string`
- Operation `integer` → MCP `number`
- Operation `boolean` → MCP `boolean`
- Operation `array` → MCP `array`
- Operation `object` → MCP `object`

Required parameters are collected into the schema's `required` array.

### 5.2 Calling a Tool

**Method:** `tools/call`  
**Params:** `{ "name": "plugin_manage", "arguments": { "action": "plugin_list", "context_mode": "compact" } }`

Large list responses in compact mode contain the collection count and up to five preview records. Repeat the call with `context_mode: "standard"` or `verbose` for the full page.

The `search_manage` tool additionally supports `max_results` (default 20, maximum 50) and an opaque `cursor` returned by the previous page.

**Execution flow:**

1. Audit: `mcp.tool.invoke` event.
2. **Capability check** — If `wpcc_enforce_capabilities` is enabled, validates the token has the required capability via `CapabilityRegistry::validate()`. If missing, returns `-32001` with "Operation denied: missing capability X".
3. **Execution** — Delegates to `OperationExecutor::run($tool_name, $args, $context)`.
4. **Redaction** — Results are scanned by `Redactor::redact_recursive()` before returning.
5. **Response** — Success returns `{ content: [{ type: 'text', text: '<json>' }] }`. Failure returns error code `-32000`.

### 5.3 Complete Tool Catalog

| Tool Name | Operation | Risk | Capability Required |
|---|---|---|---|
| `content_seed` | Content Seeding | medium | none |
| `acf_seed` | Seed ACF Fields | medium | none |
| `cf7_seed` | CF7 Seeding | low | none |
| `woo_product_seed` | WooCommerce Product Seeder | medium | none |
| `safe_search_replace` | Safe Search & Replace | high | `wpcli.execute` |
| `media_import` | Media Library Import | medium | `content.manage` |
| `safe_updates` | Safe WordPress Updates | high | `plugin.manage` |
| `capability_manage` | Capability Management | variable | `capability.admin` |
| `database_inspect` | Database Inspection | low | `database.inspect` |
| `content_manage` | Content Management | variable | `content.manage` |
| `snapshot_manage` | Snapshot Management | variable | `snapshot.manage` |
| `theme_manage` | Theme Management | variable | `theme.manage` |
| `plugin_manage` | Plugin Management | variable | `plugin.manage` |
| `option_manage` | Option Management | variable | `option.manage` |
| `wp_cli_bridge` | WP-CLI Bridge | variable | `wpcli.execute` |

---

## 6. Prompts

### 6.1 Listing Prompts

**Method:** `prompts/list`

Returns 6 informational prompts:

| Prompt Name | Description |
|---|---|
| `inspect_site` | Inspect site health and configuration |
| `manage_content` | Manage WordPress content safely |
| `manage_plugins` | Manage WordPress plugins safely |
| `manage_themes` | Manage WordPress themes safely |
| `manage_options` | Manage WordPress options safely |
| `inspect_database` | Inspect database health |

> **Note:** Prompts are currently informational metadata only. They do not accept parameters or return content — they describe what the agent can do, to guide AI client behavior.

---

## 7. Discovery Flow

A typical MCP client follows this discovery sequence:

```
1. initialize          → Get protocol version + server capabilities
2. resources/list      → Discover available resources
3. tools/list          → Discover available tools with input schemas
4. prompts/list        → Discover available prompts
5. resources/read      → Read specific resources as needed
6. tools/call          → Call specific tools as needed
```

**Example discovery sequence:**

```bash
# Step 1: Initialize
curl -X POST https://yoursite.com/wp-json/wp-command-center/v1/mcp \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"initialize","params":{},"id":1}'

# Step 2: List resources
curl -X POST https://yoursite.com/wp-json/wp-command-center/v1/mcp \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"resources/list","id":2}'

# Step 3: List tools
curl -X POST https://yoursite.com/wp-json/wp-command-center/v1/mcp \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"tools/list","id":3}'

# Step 4: List prompts
curl -X POST https://yoursite.com/wp-json/wp-command-center/v1/mcp \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"prompts/list","id":4}'

# Step 5: Read a resource
curl -X POST https://yoursite.com/wp-json/wp-command-center/v1/mcp \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"resources/read","params":{"uri":"wpcc://capabilities"},"id":5}'

# Step 6: Call a tool
curl -X POST https://yoursite.com/wp-json/wp-command-center/v1/mcp \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"plugin_manage","arguments":{"action":"plugin_list"}},"id":6}'
```

---

## 8. Claude Desktop Setup

### 8.1 Generate Configuration

1. In WordPress Admin, go to **Command Center → AI Integrations**.
2. Click on **Claude Desktop** (the active client).
3. Click **Generate Config**.
4. Copy the generated JSON configuration block.

Alternatively, generate it via the REST API:

```bash
curl -H "Authorization: Bearer TOKEN" \
  https://yoursite.com/wp-json/wp-command-center/v1/ai/clients/claude/config
```

### 8.2 Install Configuration

Paste the generated configuration into Claude Desktop's config file:

| OS | Config Path |
|---|---|
| macOS | `~/Library/Application Support/Claude/claude_desktop_config.json` |
| Windows | `%APPDATA%\Claude\claude_desktop_config.json` |
| Linux | `~/.config/Claude/claude_desktop_config.json` |

### 8.3 Restart Claude Desktop

After updating the config file, restart Claude Desktop. The WP Command Center MCP server should appear as a connected MCP server in Claude's settings.

---

## 9. Compatible Clients

All clients connect through the same MCP endpoint. The `Integration/AIClientRegistry.php` defines 9 clients:

| Status | Clients |
|---|---|
| **Active** (fully implemented, with config generators) | Claude Desktop (Anthropic) |
| **Planned** (MCP-compatible, coming soon) | Codex (OpenAI), Gemini (Google), Cursor (Anysphere), Continue (Continue Dev), OpenCode (Anomaly), Aider (Aider AI), Roo Code (Roo), Windsurf (Codeium) |

Each planned client is pre-registered in the AI Client Registry with metadata, compatibility flags, and per-OS config paths. Configuration generators will be added as those clients' MCP support matures.

---

## 10. Security in MCP Context

All MCP interactions go through the same security layers as the REST API:

1. **Token authentication** — Every request validates the bearer token.
2. **Capability enforcement** — Tool invocations check capability assignments.
3. **Approval gating** — When `wpcc_enforce_approval` is on, mutation operations require the request/approval workflow.
4. **Secret redaction** — All resource and tool responses are recursively scanned and redacted.
5. **Audit logging** — Every MCP request, resource read, tool invocation, and denial is logged with the token's identity as the actor.

### Audit Events for MCP

| Event | When |
|---|---|
| `mcp.request` | Every MCP JSON-RPC request received |
| `mcp.resource.read` | Every resource read |
| `mcp.tool.invoke` | Every tool invocation |
| `mcp.tool.list` | Tool listing requested |
| `mcp.denied` | Capability check failed or operation returned an error |
