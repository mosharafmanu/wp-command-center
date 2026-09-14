# SiteRadian public identity policy

SiteRadian is the product name. New customer setup uses the `siteradian` local MCP server alias, the `SITERADIAN_TOKEN` environment variable, `siteradian_` credentials, and `siteradian-mcp-relay.mjs`. The plugin slug, folder, main file, and text domain are all `siteradian`.

## New configuration versus existing configuration

Generated configuration for every supported client uses SiteRadian-native names. The MCP alias is a key chosen in the customer's local client configuration; it is not sent to or validated by the server. Changing the generated key therefore does not change the certified MCP runtime.

Existing client files are never edited. A configuration whose local key is `wp-command-center` continues to call the same URL and authenticate normally. The legacy `wpcc-mcp-relay.mjs` file remains in the package, and both relays accept legacy `WPCC_MCP_URL`, `WPCC_TOKEN`, and `WPCC_RELAY_TIMEOUT_MS` inputs as fallbacks. New setup never requires both old and new variable names.

## Deliberate compatibility exceptions

These identifiers remain because they are protocol, storage, or code contracts rather than customer branding:

- REST namespace `wp-command-center/v1`, including URLs generated from it. Renaming or dual-registering it would expand the protocol surface and require broad recertification without improving ordinary setup.
- MCP resource URIs under `wpcc://`. Resource URIs are machine-facing protocol identities and may already be stored or referenced by clients.
- PHP namespace `WPCommandCenter\\`, `WPCC_*` constants, hooks, capabilities, error codes, and internal HTML/CSS/JavaScript hooks.
- Database tables, WordPress options, private-storage directory names, migrations, rollback records, and governance identifiers beginning with `wpcc_` or `wpcc-`.
- `wpcc-mcp-relay.mjs` and legacy relay environment inputs, shipped only so previously generated configurations continue to work.
- Historical validation evidence, archived reports, immutable Git tags, and test-harness variable names that truthfully describe pre-public development.

The current UI, plugin metadata, listing copy, generated client aliases, credential instructions, and new-token presentation do not expose the former product names. Tests may name forbidden strings only in negative assertions or compatibility fixtures.
