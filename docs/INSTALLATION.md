# Installation

## Requirements

| | |
|---|---|
| WordPress | 6.4 or newer (tested to 7.1) |
| PHP | 8.0 or newer |
| Site type | Single site. Network activation is refused — see [ARCHITECTURE.md](ARCHITECTURE.md#multisite) |
| For MCP clients | Node.js on the machine running the assistant — **only for connector clients** (Claude Desktop, Continue for VS Code). Direct-HTTP clients (GitHub Copilot in VS Code, Claude Code, Codex CLI, Codex in ChatGPT Desktop, Gemini CLI, Antigravity CLI (`agy`), Cursor, OpenCode, Command Code and Muse Code) need nothing installed. Muse Code remains in the Experimental product group, but its actual read-only Action Steward connection is tested. |

Optional integrations, detected automatically when present: WooCommerce, Advanced Custom
Fields, Elementor, Contact Form 7, Rank Math or Yoast SEO.

## Install

**Plugins → Add New → Upload Plugin**, choose `action-steward-1.0.2.zip`, install,
activate.

Activation:

- creates 15 database tables (`DB_VERSION` 2.6.0)
- sets protection mode to **Standard protection**
- schedules the queue worker (`wpcc_process_operation_queue`, every five minutes)
- creates protected upload directories

Nothing is enabled that changes your site, and no outbound request is made.

## Verify

**Action Steward → Home** should show the site as protected. Then:

```bash
curl -s https://example.com/wp-json/wp-command-center/v1/health \
  -H "Authorization: Bearer YOUR_TOKEN"
```

Expect `{"status":"ok","plugin_version":"1.0.2", …}`.

> **Plain permalinks.** On a site using WordPress's default plain permalinks,
> `/wp-json/` does not resolve. The plugin advertises its endpoint through `rest_url()`,
> which correctly emits the `?rest_route=/wp-command-center/v1` form, so generated
> configurations work either way. If you are constructing URLs by hand, use whichever form
> `rest_url()` returns for your site.

## Upgrade

Upload the new ZIP over the existing install, or use your normal update flow. The plugin
stays active, the schema is migrated in place, and existing tokens, approvals and history
are preserved.

## Deactivate

Deactivating stops the worker and the REST/MCP surface. **No data is deleted.**
Reactivating picks up exactly where you left off.

## Uninstall

By default, uninstalling **keeps your data** — tables, options and history survive, so an
accidental uninstall is recoverable.

To remove everything, opt in **before** uninstalling:

```php
update_option( 'wpcc_delete_data_on_uninstall', 1 );
```

With that set, uninstall drops all 16 tables (the 15 schema tables plus the lazily
created `wpcc_telemetry`), deletes every `wpcc` option and post-meta key, and removes the
plugin's upload directories.

## Reinstall

Reinstalling over retained data adopts it: the schema version is recognised, and existing
tokens, approvals and history reappear.
