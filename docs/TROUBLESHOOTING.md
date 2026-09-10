# Troubleshooting

---

## Missing API token

**Symptom:** every request returns "Missing API token" even though the token is valid.

**Cause:** some server configurations (commonly Apache with CGI/FastCGI) never populate
`$_SERVER['HTTP_AUTHORIZATION']`, so WordPress's own header lookup sees nothing.

Action Steward already checks the alternative sources core and the ecosystem use, so this usually
resolves itself. If it persists, add to `.htaccess`:

```apache
RewriteEngine On
RewriteCond %{HTTP:Authorization} ^(.*)
RewriteRule .* - [E=HTTP_AUTHORIZATION:%1]
```

Or on nginx/FastCGI:

```nginx
fastcgi_param HTTP_AUTHORIZATION $http_authorization;
```

---

## 404 on every REST call

**Cause:** the site uses WordPress's default **plain permalinks**, where `/wp-json/` does
not resolve.

**Fix:** either switch to pretty permalinks (Settings → Permalinks), or use the
`?rest_route=` form:

```
https://example.com/index.php?rest_route=/wp-command-center/v1/health
```

Configurations Action Steward generates already use whichever form is correct for your site.

---

## The client connects but shows no tools

Work through these in order:

1. **Is Node installed** on the machine running the client? `node --version`.
2. **Is the relay reachable?**
   ```bash
   curl -sI https://example.com/wp-content/plugins/action-steward/sdk/javascript/wpcc-mcp-relay.mjs
   ```
   Expect `200`. A `404` means the plugin files are not where the config expects.
3. **Does the endpoint answer?**
   ```bash
   curl -s -X POST https://example.com/wp-json/wp-command-center/v1/mcp \
     -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
     -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | head -c 200
   ```
   Expect a tool list. `401` means the token is wrong, expired or revoked.
4. **Did you restart the client** after editing its config? Most cache it at startup.

---

## Everything says "waiting for approval"

Working as designed. Standard protection gates every medium-risk or higher change.

- Approve at **Action Steward → Approvals**.
- To let low-risk writes through automatically, that is already Standard protection's
  behaviour; Strict approval gates those too.
- Development mode removes approval entirely — **local and staging only**.

Approved work runs on WP-Cron every five minutes; approving from the admin runs it at once.
If approvals sit in the queue on a site with `DISABLE_WP_CRON`, trigger the worker:

```bash
wp cron event run wpcc_process_operation_queue
```

---

## "This token is read-only and cannot perform this action"

The token's scope is `read_only`, which is enforced before the operation is reached.
Create a full-access token in **Settings → Connections → Tokens**. Changes will still wait
for your approval.

---

## "Missing capability: …"

The token exists and has the right scope, but not that capability. Edit the token's
capabilities in **Settings → Connections → Tokens**, or use a token that has it.

---

## An unknown-action error

```
Invalid content action "create". Valid actions: content_list, content_get, content_create, …
```

The message always lists every valid action for that operation. Use one of them. Actions
are namespaced (`content_create`, not `create`).

---

## An undo only partly worked

```
wpcc_rollback_partial — Partial rollback: restored description; skipped title because
they changed since this update was applied (drift).
```

This is deliberate. Fields changed since the original write are **skipped rather than
clobbered**, so an undo never destroys newer work. The response carries
`data.restored_fields`, `data.skipped_fields` and `data.conflicts` (with expected vs
current values) so you can decide what to do about each.

`wpcc_rollback_conflict` means *every* targeted field had drifted, so nothing was restored.

---

## Network activation is refused

Intentional. Action Steward 1.0.2 is single-site: tokens, protection mode, approvals and history are
per-site. Activate it on individual sites within the network instead. See
[ARCHITECTURE.md](ARCHITECTURE.md#multisite).

---

## Patches cannot create files

Also intentional. The patch engine modifies existing files, snapshotting each one first so
the change can be reversed. Creating or deleting files is outside its contract, and the
error says so rather than blaming a missing path.

---

## The Drafts (Dev) screen is missing

It ships **off**. It is a developer surface, enabled by the `WPCC_PROPOSALS_DEV_UI`
constant or the `wpcc_proposals_dev_ui` filter.

---

## Collecting diagnostics

**Settings → Advanced → Diagnostics**, or:

```bash
curl -s "$BASE/health" -H "Authorization: Bearer $TOKEN"
curl -s "$BASE/capabilities" -H "Authorization: Bearer $TOKEN"   # environment probe
```

The audit log is at `wp-content/uploads/wpcc-audit/audit.log` (JSONL, secret-redacted).
