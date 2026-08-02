# Notes for the WordPress.org Plugin Review Team

WP Command Center 1.0.0. These are the four things in this plugin that reasonably prompt a
reviewer question, stated plainly with where to look.

---

## 1. The plugin generates a configuration that downloads and runs a script

**What it looks like.** Settings → Connections produces a configuration block for Claude
Desktop, Cursor, Codex and similar tools. It contains:

```
bash -c "RELAY='/tmp/wpcc-mcp-relay.mjs'; curl -fsSL -o \"$RELAY\" \
  'https://example.com/wp-content/plugins/wp-command-center/sdk/javascript/wpcc-mcp-relay.mjs?v=1.0.0'; node \"$RELAY\""
```

**What it is.** A stdio↔HTTP bridge that runs on the **site owner's own computer**, under
their own account, downloaded from **their own domain**. It never runs on the web server.
It exists because MCP clients speak stdio while WordPress speaks HTTP.

**Why it is not a remote-code-execution vector for the site.** The file is served from the
plugin's own directory (`sdk/javascript/wpcc-mcp-relay.mjs`, shipped in the package, 5.6 KB,
no build step, human-readable). The server does not fetch or execute anything. Removing the
configuration removes the connector.

**Where to look.** `includes/Integration/BaseClientIntegration.php::generate_mcp_config()`
and the shipped `sdk/javascript/wpcc-mcp-relay.mjs`. The UI states this in plain language
next to the configuration block, and `readme.txt` discloses it.

---

## 2. `proc_open()` — two call sites

`Generic.PHP.ForbiddenFunctions.Found` × 2.

| Site | Purpose |
|---|---|
| `includes/PatchSystem/PhpBinary.php:200` | Runs `php -l` on a file **after** it is patched and **before** the change is accepted. If the file does not parse, the pre-apply snapshot is restored automatically. |
| `includes/Operations/WpCliBridge.php:229` | Runs structured WP-CLI commands, itself an approval-gated operation. |

**Both degrade rather than fail.** On a host that disables process execution the
operation reports `operation_not_available`; there is no fatal and every other operation
continues to work. This was verified on a live host that disables `exec`, `shell_exec` and
`popen` — `wp_cli_bridge` reported unavailable and the other 41 tools were unaffected.

**Why the syntax check is not simply removed.** It is what stops a bad edit taking a site
down. Measured: a patch introducing a syntax error into a live theme's `functions.php` was
caught, reverted from the snapshot, and the site stayed up. A tokenizer fallback exists for
hosts without a PHP binary, but `php -l` is the stronger check and is used when available.
Removing it would make the plugin less safe, not more compliant.

---

## 3. Direct filesystem calls — 27 remaining

`unlink()` (22) and `is_writable()` (7) were converted to `wp_delete_file()` and
`wp_is_writable()`. The 27 that remain are not oversights:

* **`fclose()` / `fread()` on `proc_open` pipes** — these are process streams, not files.
  `WP_Filesystem` has no equivalent; the sniffer matches on the function name.
* **`rename()` in `SnapshotManager` and `AuditLog`** — the temp-write-then-atomic-replace
  that snapshot and audit integrity depend on. `WP_Filesystem::move()` provides no
  atomicity guarantee and is not atomic at all over its FTP and SSH transports. Using it
  would introduce a window in which a snapshot is half-written.
* **`fopen( $file, 'c' )` with `flock`** in `AuditLog` — append-only audit logging under
  concurrent requests. `WP_Filesystem` has no locking primitive.

This plugin's whole promise is that a change can be undone and that the record of it is
trustworthy. These three cases are where that promise is implemented.

---

## 4. Direct database queries on plugin-owned tables

The plugin creates and owns 17 `wp_wpcc_*` tables (approval queue, change log, snapshots,
patches, audit index). Queries against them are direct and prepared. `$wpdb->prepare()` is
frequently applied **into a variable** which is then executed, and SQL identifiers
(table and column names) are interpolated from `$wpdb->prefix` or from hard-coded
allowlists — neither can be a placeholder.

Representative sites, each verifiable:

* `includes/Health/HealthVerificationEngine.php:85-87` — prepared into `$sql`, then run.
* `includes/PatchSystem/PatchManager.php:464` — `$field` validated against
  `['session_id','task_id','plan_id']` on the preceding line.
* `includes/Operations/MediaUsageResolver.php:92` and `:318` — the helper returns
  placeholder-only clauses; values bind through `prepare()`, with `esc_like()` on filenames.

`docs/WORDPRESS-ORG-COMPLIANCE-REPORT.md` classifies all 124 remaining findings.

---

## Also worth knowing

* **Single site only.** Network activation is refused with an explanation rather than
  half-performed, because it would create tables for one site while showing the plugin's
  menu on all of them.
* **No AI runs on the site by default.** No provider key is required to connect an
  assistant; the built-in AI features are off until switched on, and adding a key alone
  turns nothing on.
* **Uninstall retains data by default**, with an explicit opt-in checkbox to purge.
* **Tokens are hashed at rest** and shown in full exactly once, at creation.
