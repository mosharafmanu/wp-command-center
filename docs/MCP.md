# MCP interface

Action Steward implements a Model Context Protocol server over HTTP, plus a stdio relay so
stdio-only clients can reach it.

**Endpoint:** `POST /wp-json/wp-command-center/v1/mcp`
**Auth:** `Authorization: Bearer <token>`
**Protocol version:** `2024-11-05`

---

## Handshake

```bash
curl -s -X POST https://example.com/wp-json/wp-command-center/v1/mcp \
  -H "Authorization: Bearer $WPCC_TOKEN" -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize",
       "params":{"protocolVersion":"2024-11-05","capabilities":{},
                 "clientInfo":{"name":"my-client","version":"1"}}}'
```

```json
{"jsonrpc":"2.0","id":1,"result":{
  "protocolVersion":"2024-11-05",
  "serverInfo":{"name":"Action Steward","version":"1.0.2"}}}
```

## Methods

| Method | Purpose |
|---|---|
| `initialize` | Handshake. |
| `tools/list` | All 42 tools with input schemas. |
| `tools/call` | Invoke a tool. |
| `resources/read` | Discovery resources (below). |

### Resources

`wpcc://manifest` · `wpcc://context` · `wpcc://capabilities` · `wpcc://operations` ·
`wpcc://queue` · `wpcc://results` · `wpcc://recommendations`

`wpcc://operations` is the richest: per-action risk and read/write kind, whether an action
will stop for approval **in this site's current mode**, accepted parameter aliases, how to
undo the result, and whether the integration an operation needs is present on this site.

## Tools

One tool per catalogue operation — 42, listed in [OPERATIONS.md](OPERATIONS.md). Each
takes an `action` plus that action's parameters:

```json
{"jsonrpc":"2.0","id":2,"method":"tools/call",
 "params":{"name":"content_manage",
           "arguments":{"action":"content_create","post_type":"post","title":"Hello"}}}
```

## Three kinds of response

**1. Success.** `content[0].text` holds the JSON result.

**2. Waiting for approval.** Still a success result — the call did not fail, it is
pending:

```json
{"status":"pending_approval",
 "request_id":"…",
 "approval_url":"https://example.com/wp-admin/admin.php?page=wpcc-activity&wpcc_tab=approvals&view=…",
 "approvals_list_url":"…"}
```

Relay this to the user with the link. Do not retry — retrying files a second request.

**3. Failure.** `isError: true`, with a structured code:

```json
{"isError":true,"code":"wpcc_invalid_content_action",
 "message":"Invalid content action \"create\". Valid actions: content_list, content_get, content_create, …"}
```

Every runtime uses its own code (`wpcc_invalid_acf_action`, `wpcc_invalid_woo_action`, …)
and **always lists the valid actions in the message**, because the MCP envelope only
surfaces `{isError, code, message}` — structured extras do not reach the client.

## Context modes

`WPCC_CONTEXT_MODE` controls how much detail tool descriptions carry: `compact` (default)
or `full`. Compact trims descriptions and examples. It **never truncates returned data** —
an earlier version did, and reported `truncated:false` while doing it, which made
read-then-patch cycles unsafe. Compact mode now only affects descriptive text.

## Undo

Reversible results carry a `rollback` block naming the tool, the arguments, and whether
the undo will itself need approval:

```json
{"rollback":{
  "reversible":true,
  "rollback_id":"…",
  "undo_with":{"tool":"change_history",
               "arguments":{"action":"rollback_target","rollback_id":"…"}},
  "approval_required":true}}
```

`change_history` is the general entry point; some operations also accept a direct action
(`content_rollback`, `option_rollback`, `seo_restore`). Patches are reversed through
`patch_manage`.

A partial undo — where some fields changed since the original write — returns
`wpcc_rollback_partial` and carries the detail in the error's `data`:
`restored_fields`, `skipped_fields`, and `conflicts` with expected vs current values.
Drifted fields are **skipped, never clobbered**.

## The relay

`sdk/javascript/wpcc-mcp-relay.mjs`, shipped in the plugin and served from your own site.
The generated client config downloads it and runs it with Node, passing the endpoint and
token through the environment. Nothing is fetched from npm.
