# REST API

**Namespace:** `wp-command-center/v1`
**Base:** `https://example.com/wp-json/wp-command-center/v1`
**Auth:** `Authorization: Bearer <token>` on every route

> On a site using plain permalinks, `/wp-json/` does not resolve; use the
> `?rest_route=/wp-command-center/v1/…` form. `rest_url()` returns the correct form for
> your site, and every URL the plugin generates already uses it.

---

## Health

```bash
curl -s "$BASE/health" -H "Authorization: Bearer $TOKEN"
```

```json
{"status":"ok","plugin_version":"1.0.0", …}
```

## Discovery

| Route | Returns |
|---|---|
| `GET /agent/manifest` | Endpoints, operations, MCP endpoint, capability map |
| `GET /agent/context` | Site context for an assistant |
| `GET /operations` | The 42-operation catalogue |
| `GET /operations/<id>` | One operation, enriched with per-action risk, aliases, undo route, requirements |
| `GET /capabilities` | **Environment probe** — booleans for `file_read`, `file_write`, `patch_apply`, `rollback`, `shell_exec`, `proc_open`, `wp_cli`, `wp_cli_operations` |
| `GET /claude/discovery` | Capability registry, MCP resources, client list |
| `GET /ai-clients` | Supported AI clients |
| `GET /ai-clients/<client>/config` | Generated configuration for that client |
| `GET /claude/config` | Generated Claude Desktop configuration |

> `GET /capabilities` reports what this **server environment** can do. It is not the
> 23-entry token capability registry — that is in `/claude/discovery` and
> [CAPABILITIES.md](CAPABILITIES.md).

## Running an operation

```bash
curl -s -X POST "$BASE/operations/content_manage/run" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"action":"content_create","post_type":"post","title":"Hello","status":"draft"}'
```

Three possible outcomes:

**Executed** — the result object.

**Waiting for approval** — HTTP 200 with:

```json
{"status":"pending_approval","request_id":"…","approval_url":"…"}
```

**Refused** — a `WP_Error` response:

```json
{"code":"wpcc_invalid_content_action",
 "message":"Invalid content action \"create\". Valid actions: …",
 "data":{"status":400}}
```

`data` also carries structured detail where the runtime provides it. A partial undo, for
example, returns `wpcc_rollback_partial` with `data.restored_fields`,
`data.skipped_fields` and `data.conflicts`.

## Undo

```bash
# General entry point
curl -s -X POST "$BASE/operations/change_history/run" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"action":"rollback_target","rollback_id":"…"}'

# Per-operation route
curl -s -X POST "$BASE/operations/<id>/rollback" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"rollback_id":"…"}'
```

## Approvals

| Route | Purpose |
|---|---|
| `POST /operations/approval_manage/run` | `request_list`, `request_get`, `request_approve`, `request_reject` |

Approving enqueues the request; the worker executes it on WP-Cron
(`wpcc_process_operation_queue`, every five minutes). Approving from the admin executes
immediately.

## Common error codes

| Code | Meaning |
|---|---|
| `wpcc_token_read_only` | A read-only token attempted a write |
| `wpcc_capability_denied` | Token lacks the required capability |
| `wpcc_invalid_<runtime>_action` | Unknown action; the message lists the valid ones |
| `wpcc_missing_action` | No action supplied where one is required |
| `wpcc_missing_parameters` | A required parameter was absent (not merely empty) |
| `wpcc_conflicting_parameters` | A canonical name and an alias disagreed |
| `wpcc_rollback_partial` | Some fields restored, others skipped as drifted |
| `wpcc_rollback_conflict` | Every targeted field had drifted; nothing restored |
| `wpcc_already_rolled_back` | This change was already undone |

## SDKs

`sdk/php/Client.php` and `sdk/javascript/client.js` are thin illustrative wrappers over
these routes.
