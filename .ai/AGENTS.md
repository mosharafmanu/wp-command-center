# Agent instructions — wp-command-center

This project includes a REST API for managing the WordPress site it's
installed on (Site Intelligence, Diagnostics, File Access, and a
Patch Engine for proposing/approving/applying/rolling back file changes).

## Connecting

1. Source the local credentials (gitignored, not in version control):

   ```bash
   source wpcc-env.sh
   ```

   This exports `$WPCC_BASE` (REST API base URL) and `$WPCC_TOKEN`
   (bearer token, full access).

2. See `CONNECTING.md` for the full endpoint reference and example
   `curl` commands (read site info, run diagnostics, list/read files,
   search code, and the patch create→approve→apply→rollback lifecycle).

## Making file changes on the live site

Do **not** edit theme/plugin files directly with the filesystem tools.
Instead, use the Patch Engine via the REST API:

1. `POST $WPCC_BASE/patches` with `{files: [{path, modified}], explanation, risk_level}`
   — `path` is relative to `wp-content/`, must be an existing file under
   `themes/`, `plugins/`, or `mu-plugins/`.
2. Review the returned `diff`.
3. `POST $WPCC_BASE/patches/<id>/approve`
4. `POST $WPCC_BASE/patches/<id>/apply` — auto-snapshots the file(s) first.
5. If needed, `POST $WPCC_BASE/patches/<id>/rollback` to restore.

`wp-config.php` is intentionally off-limits to this API (it holds DB
credentials) — it must be edited directly on disk if ever needed, with a
manual backup first.
