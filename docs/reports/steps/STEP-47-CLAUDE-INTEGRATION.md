# Step 47 — Claude Desktop Integration

## Summary

Production-ready Claude Desktop MCP integration for WP Command Center. Claude Desktop connects as a standard MCP client — no second runtime, no Claude-specific execution paths. All existing gates (capability enforcement, approval enforcement, queue, audit, rollback) apply identically to Claude as to any MCP client.

## Architecture

```
Claude Desktop
  → MCP Client
    → MCP JSON-RPC 2.0
      → WP Command Center
        → Capability Runtime
        → Approval Runtime
        → Queue Runtime
        → OperationExecutor
        → Verification → Audit → Rollback
```

Claude Desktop uses the existing MCP endpoint (`/wp-command-center/v1/mcp`). The Claude integration layer provides discovery, metadata, and configuration — not execution.

---

## Setup Instructions

### 1. Source credentials

```bash
cd wp-command-center
source wpcc-env.sh
```

### 2. Generate Claude Desktop MCP configuration

```bash
curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/claude/config"
```

This returns dynamic MCP configuration:

```json
{
  "mcpServers": {
    "wp-command-center": {
      "command": "npx",
      "args": [
        "-y",
        "@anthropic-ai/mcp-client",
        "https://your-site.com/wp-json/wp-command-center/v1/mcp"
      ],
      "env": {
        "WPCC_MCP_URL": "https://your-site.com/wp-json/wp-command-center/v1/mcp",
        "WPCC_SITE_URL": "https://your-site.com",
        "WPCC_TOKEN": "${WPCC_TOKEN}"
      }
    }
  }
}
```

### 3. Add to Claude Desktop

Copy the generated configuration into your `claude_desktop_config.json`:

**macOS:** `~/Library/Application Support/Claude/claude_desktop_config.json`

**Windows:** `%APPDATA%\Claude\claude_desktop_config.json`

**Linux:** `~/.config/Claude/claude_desktop_config.json`

Replace `${WPCC_TOKEN}` with your actual WP Command Center API token (obtained from Settings → API Tokens in the WordPress admin).

### 4. Restart Claude Desktop

### 5. Verify connection

```
Ask Claude: "What operations are available on my WordPress site?"
```

Claude discovers all available MCP tools and resources automatically.

---

## Supported Tools (15 tools across 12 groups)

| Group | Tools | Risk | Approval | Capability |
|---|---|---|---|---|
| **Content** | `content_manage` | variable | yes | `content.manage` |
| **Plugins** | `plugin_manage` | variable | yes | `plugin.manage` |
| **Themes** | `theme_manage` | variable | yes | `theme.manage` |
| **Database** | `database_inspect` | low | no | `database.inspect` |
| **Snapshots** | `snapshot_manage` | variable | yes | `snapshot.manage` |
| **WP-CLI** | `wp_cli_bridge` | variable | yes | `wpcli.execute` |
| **Options** | `option_manage` | variable | yes | `option.manage` |
| **Seeding** | `content_seed`, `acf_seed`, `cf7_seed`, `woo_product_seed` | low–medium | yes | varies |
| **Media** | `media_import` | medium | yes | `content.manage` |
| **Updates** | `safe_updates` | high | yes | `plugin.manage` |
| **Search & Replace** | `safe_search_replace` | high | yes | `wpcli.execute` |
| **Capabilities** | `capability_manage` | variable | yes | (system-level) |

---

## Supported Resources (7 resources)

| URI | Name | Description |
|---|---|---|
| `wpcc://manifest` | Agent Manifest | Full agent manifest |
| `wpcc://context` | Agent Context | Runtime context snapshot |
| `wpcc://capabilities` | Capabilities | Platform capabilities |
| `wpcc://operations` | Operations | Available operations |
| `wpcc://queue` | Queue Status | Operation queue state |
| `wpcc://results` | Results | Recent operation results |
| `wpcc://recommendations` | Recommendations | Open recommendations |

---

## Approval Workflow

Approval enforcement is controlled by `wpcc_enforce_approval` (off by default, opt-in).

When enforcement is ON, any tool with `requires_approval: true` must go through:

1. Create operation request
2. Human approves request (admin dashboard or REST API)
3. Approved request auto-queues
4. Background worker or manual trigger executes
5. Result stored and audit logged

Claude receives the same approval gates as any MCP client. It cannot bypass approval.

### Checking approval requirements

```bash
curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/claude/discovery" | jq '.approval'
```

---

## Capability Workflow

Capability enforcement is ON by default (`wpcc_enforce_capabilities=1`).

Each tool maps to a required capability:

| Tool | Required Capability |
|---|---|
| `content_manage` | `content.manage` |
| `database_inspect` | `database.inspect` |
| `plugin_manage` | `plugin.manage` |
| `theme_manage` | `theme.manage` |
| `option_manage` | `option.manage` |
| `snapshot_manage` | `snapshot.manage` |
| `wp_cli_bridge` | `wpcli.execute` |
| `safe_search_replace` | `wpcli.execute` |
| `safe_updates` | `plugin.manage` |
| `media_import` | `content.manage` |

### Checking required capabilities

```bash
curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/claude/discovery" | jq '.capabilities.operation_map'
```

### Assigning capabilities to a token

```bash
curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" \
  -d '{"action":"capability_assign","subject":"token","subject_id":"<token_id>","capability":"content.manage"}' \
  "$WPCC_BASE/operations/capability_manage/run"
```

---

## Prompt Templates (7 prompts)

Claude-friendly helper prompts accessible via `GET /claude/prompts`:

| Prompt | Description |
|---|---|
| `inspect_site` | Inspect site health, diagnostics, and configuration |
| `review_recommendations` | Review and act on deterministic recommendations |
| `create_content` | Create a new post or page |
| `plugin_maintenance` | Review and maintain WordPress plugins |
| `theme_maintenance` | Review and maintain WordPress themes |
| `database_health_review` | Inspect database health, sizes, and structure |
| `manage_options` | Inspect and update WordPress options safely |

---

## Security Model

Claude Desktop receives exactly the same permissions as any MCP client:

- **Never bypasses capabilities** — Claude requires the same capability assignments as any other MCP client
- **Never bypasses approvals** — Claude cannot auto-apply patches or mutations requiring human approval
- **Never bypasses queue** — All operations go through the standard request → approve → queue → execute flow
- **Never bypasses audit** — All Claude-initiated actions are logged with `source: claude`
- **Never bypasses rollback** — All patches/snapshots/rollbacks follow the same lifecycle

---

## REST API Endpoints

| Method | Path | Scope | Description |
|---|---|---|---|
| GET | `/claude/config` | read_only | Generate dynamic Claude Desktop MCP configuration |
| GET | `/claude/discovery` | read_only | Claude discovery metadata (server, tools, resources, capabilities, approval) |
| GET | `/claude/tools` | read_only | Claude-friendly tool grouping with approval and capability metadata per tool |
| GET | `/claude/prompts` | read_only | Claude-specific helper prompt templates |

All endpoints are read-only, require a valid API token, and pass through the existing redaction layer.

---

## Audit Events

| Event | Trigger |
|---|---|
| `claude.config.generated` | MCP configuration is requested |
| `claude.discovery` | Discovery metadata is requested |
| `claude.tool.invoked` | A tool is called through Claude's MCP connection |

Claude-initiated MCP requests create `mcp.request` events (existing), while direct Claude integration endpoint calls create the above events. All events include `source: claude` and the actor context.

---

## Troubleshooting

### Claude doesn't discover tools

1. Verify the MCP endpoint is accessible:
   ```bash
   curl -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/agent/manifest" | jq '.mcp_server'
   ```

2. Verify MCP JSON-RPC works:
   ```bash
   curl -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" \
     -d '{"jsonrpc":"2.0","method":"initialize","params":{"protocolVersion":"2024-11-05"},"id":1}' \
     "$WPCC_BASE/mcp"
   ```

3. Check `claude_desktop_config.json` has the correct MCP URL and token.

### "Missing capability" errors

Run discovery to see which capabilities your token requires:
```bash
curl -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/claude/discovery" | jq '.capabilities.operation_map'
```

Assign missing capabilities via the admin dashboard or REST API.

### "Approval required" errors

Approval enforcement is opt-in. If you want Claude to execute operations without approval:
- Set `wpcc_enforce_approval=0` in WordPress options, **or**
- Use the operation request workflow (recommended for production)

---

## Files Changed

- `includes/Integration/ClaudeIntegration.php` (new)
- `includes/AiAgent/RestApi.php` (import + 4 routes + 4 handlers + manifest block)
- `includes/AiAgent/TimelineBuilder.php` (3 new timeline labels)
- `includes/Admin/views/dashboard.php` (Claude Integration card)
- `tests/test-agent-manifest.sh` (added `claude_integration` to expected capabilities)
- `tests/test-claude-integration.sh` (new, 100 assertions)
