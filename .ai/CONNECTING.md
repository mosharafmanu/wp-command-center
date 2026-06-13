# Connecting to the WP Command Center REST API from a terminal

This guide walks through calling your site's WP Command Center REST API
(`wp-command-center/v1`) directly from the terminal using `curl`. This is
the same API that AI agents (Claude, Codex, etc.) use — connecting yourself
this way is useful for testing, scripting, or driving the Patch Engine
without the admin UI.

## 1. Prerequisites

- An API token from **WP Command Center → Settings → API Tokens**.
  Tokens are shown only once at creation — if you lost yours, revoke it
  and create a new one.
- `curl` (preinstalled on macOS/Linux). Optional: `jq` for pretty-printing
  JSON responses (`brew install jq`).

## 2. Set up your credentials

Your base URL and token are stored in `wpcc-env.sh` in this plugin
directory (gitignored — never commit it). Source it in your shell:

```bash
cd /Applications/AMPPS/www/ClientProjects/WordPress/2026/plugins-dev/wp-content/plugins/wp-command-center
source wpcc-env.sh
```

This exports two variables for the rest of this session:

- `$WPCC_BASE` — the REST API base URL
- `$WPCC_TOKEN` — your bearer token

Every request below sends the token as:

```
Authorization: Bearer $WPCC_TOKEN
```

## 3. Test the connection

```bash
curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/site-intelligence" | jq .
```

A `200` response with a JSON object (WordPress version, PHP version, theme,
plugins, etc.) means you're connected. Common failures:

| Response | Meaning |
| --- | --- |
| `401 wpcc_missing_token` | No `Authorization` header sent. |
| `401 wpcc_invalid_token` | Token doesn't match any stored token. |
| `401 wpcc_token_revoked` | Token was revoked in Settings. |
| `401 wpcc_token_expired` | Token's expiry date has passed. |
| `403 wpcc_insufficient_scope` | Token is `read_only` but the route needs `full`. |

## 4. Read-only endpoints (work with any token)

**Site Intelligence** — full site scan:

```bash
curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/site-intelligence" | jq .
```

**Diagnostics** — `type` is `performance`, `security`, or `woocommerce`:

```bash
curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/diagnostics?type=performance" | jq .
```

**Debug log tail** — last N lines of `wp-content/debug.log`:

```bash
curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/diagnostics/debug-log?lines=100" | jq .
```

**List a directory** (`path` is relative to `wp-content/`, allowed roots:
`themes`, `plugins`, `mu-plugins`):

```bash
curl -s -H "Authorization: Bearer $WPCC_TOKEN" -G "$WPCC_BASE/files" \
  --data-urlencode "path=themes/mosharaf-core" | jq .
```

**Read a file's contents:**

```bash
curl -s -H "Authorization: Bearer $WPCC_TOKEN" -G "$WPCC_BASE/files/content" \
  --data-urlencode "path=themes/mosharaf-core/functions.php" | jq -r .contents
```

**Search code:**

```bash
curl -s -H "Authorization: Bearer $WPCC_TOKEN" -G "$WPCC_BASE/search" \
  --data-urlencode "q=add_action" \
  --data-urlencode "path=themes/mosharaf-core" | jq .
```

**List patches:**

```bash
curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/patches" | jq .
```

**Get a single patch:**

```bash
curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/patches/<patch-id>" | jq .
```

## 5. Write endpoints (require a `full` scope token)

**Create a patch.** `files` is an array of `{path, modified}` — `path`
must point to an existing file, `modified` is its proposed new full
content. The diff against the current file is generated automatically.

```bash
curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "files": [
      {
        "path": "themes/mosharaf-core/functions.php",
        "modified": "<full new file contents here>"
      }
    ],
    "explanation": "Describe what this change does and why",
    "risk_level": "low"
  }' \
  "$WPCC_BASE/patches" | jq .
```

This returns the new patch record with `status: "pending_approval"` and
an `id`. Save that `id` for the next steps.

**Approve a patch:**

```bash
curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/patches/<patch-id>/approve" | jq .
```

**Apply an approved patch** (auto-snapshots the current file(s) first,
writes the new content, then runs verification):

```bash
curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/patches/<patch-id>/apply" | jq .
```

**Reject a pending/approved patch** (no file changes are made):

```bash
curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/patches/<patch-id>/reject" | jq .
```

**Roll back an applied patch** (restores the file(s) from their
pre-apply snapshot):

```bash
curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/patches/<patch-id>/rollback" | jq .
```

## 6. Full example workflow

```bash
source wpcc-env.sh

# 1. Read the current file
curl -s -H "Authorization: Bearer $WPCC_TOKEN" -G "$WPCC_BASE/files/content" \
  --data-urlencode "path=themes/mosharaf-core/404.php" | jq -r .contents > /tmp/404-current.php

# 2. Edit /tmp/404-current.php locally, then create a patch from it
NEW_CONTENT=$(cat /tmp/404-current.php)
PATCH=$(curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" \
  -d "$(jq -n --arg path "themes/mosharaf-core/404.php" --arg modified "$NEW_CONTENT" \
        '{files: [{path: $path, modified: $modified}], explanation: "Manual edit via terminal", risk_level: "low"}')" \
  "$WPCC_BASE/patches")
PATCH_ID=$(echo "$PATCH" | jq -r .id)
echo "Created patch $PATCH_ID"

# 3. Review the diff
echo "$PATCH" | jq -r '.files[0].diff'

# 4. Approve, apply
curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/patches/$PATCH_ID/approve" | jq -r .status
curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/patches/$PATCH_ID/apply" | jq -r '.status, .verification'

# 5. If something's wrong, roll back
curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/patches/$PATCH_ID/rollback" | jq -r .status
```

You can also do steps 3–5 from the **Patches** admin page
(`wp-admin/admin.php?page=wpcc-patches`) — the REST API and the admin UI
operate on the same patch records.

## 7. Token scopes and management

- **`read_only`** tokens can call every `GET` route above.
- **`full`** tokens can additionally call every `POST /patches*` route.
- Manage tokens (create, revoke, delete, set expiry) from
  **Settings → API Tokens**. Revoking a token takes effect immediately —
  the next request with that token returns `401 wpcc_token_revoked`.

## 8. Security notes

- `wpcc-env.sh` and `.env*` files are gitignored — never commit them.
- Treat the raw token like a password: anyone with it has the same
  access as the scope it was created with (a `full` token can modify
  site files).
- If a token is ever exposed (e.g., pasted into a chat, shared log,
  screen recording), revoke it from Settings and issue a new one.
- The base URL `http://localhost/...` only works for clients running on
  this machine. To use the API from another machine, the site needs to
  be reachable at a different (and ideally HTTPS) URL.
