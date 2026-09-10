<?php
/**
 * OpenCode MCP Integration.
 *
 * CORRECTED 2026-08-11. OpenCode was represented as a relay client needing Node.js and a
 * connector script, configured through a standalone ~/.config/opencode/mcp.json. All
 * three were wrong: OpenCode has native remote MCP support, a native command for
 * registering it, and keeps MCP configuration in its normal config file.
 *
 * VERIFIED AGAINST THE INSTALLED CLIENT (opencode 1.18.15), not documentation alone —
 * OpenCode's own MCP docs do not currently mention the `mcp add` command at all, so the
 * CLI was interrogated and then run:
 *
 *   opencode mcp add <name> --url <url> --header "Authorization=Bearer <token>"
 *
 * `--header` takes KEY=VALUE, NOT the "Key: Value" wire form. That single detail is the
 * difference between a working registration and a header named "Authorization: Bearer
 * wpcc_..." with an empty value. Running the command wrote exactly:
 *
 *   "wp-command-center": {
 *     "type": "remote",
 *     "url": "...",
 *     "headers": { "Authorization": "Bearer ..." }
 *   }
 *
 * into ~/.config/opencode/opencode.jsonc, leaving the pre-existing server untouched.
 *
 * NOT `opencode mcp auth`. That command starts an OAuth browser flow, and WPCC
 * authenticates with its own bearer tokens. Real testing hit this: registering without a
 * header produced "wp-command-center needs authentication", and `mcp auth` then failed
 * because it was looking for an OAuth provider that does not exist here. The header is
 * the whole authentication story for this client.
 *
 * CERTIFIED END TO END (REAL_TEST_FINDINGS.md #7): OpenCode -> native remote MCP ->
 * direct HTTP -> bearer -> WPCC, with system_info executed and returning WordPress/PHP/
 * theme/plugin/environment/WP-CLI values consistent with other clients.
 */

namespace WPCommandCenter\Integration;

defined( 'ABSPATH' ) || exit;

final class OpenCodeIntegration extends BaseClientIntegration {

	protected static string $client_name = 'OpenCode';

	protected static array $config_paths = [
		'macos'   => '~/.config/opencode/opencode.jsonc',
		'windows' => '%USERPROFILE%\\.config\\opencode\\opencode.jsonc',
		'linux'   => '~/.config/opencode/opencode.jsonc',
	];

	public static function transport(): string {
		return 'http';
	}

	public static function credential_mode(): string {
		return 'inline';
	}

	/** OpenCode's block is `mcp`, not `mcpServers`. */
	public static function root_key(): string {
		return 'mcp';
	}

	/**
	 * The native registration command — the primary path, because it edits the config
	 * file correctly and cannot disturb the servers already in it (confirmed by running
	 * it against a config that already had one).
	 *
	 * Note the KEY=VALUE header form; see the class note above for why that matters.
	 */
	public static function setup_command( string $token = '' ): string {
		$ep    = self::http_endpoint();
		$value = '' !== $token ? $token : AIClientRegistry::TOKEN_PLACEHOLDER;

		return sprintf(
			"opencode mcp add %s \\\n  --url %s \\\n  --header %s",
			escapeshellarg( self::server_key() ),
			escapeshellarg( $ep['url'] ),
			escapeshellarg( 'Authorization=Bearer ' . $value )
		);
	}

	/** Manual fallback, in the shape the command itself writes. */
	public static function generate_mcp_config(): array {
		$ep = self::http_endpoint();

		return [
			'$schema'        => 'https://opencode.ai/config.json',
			self::root_key() => [
				self::server_key() => [
					'type'    => 'remote',
					'url'     => $ep['url'],
					'headers' => $ep['headers'],
				],
			],
		];
	}

	public static function post_setup_notes(): array {
		return [
			__( 'Do not run “opencode mcp auth” for this connection. That command starts an OAuth sign-in, which this site does not use — your access token in the Authorization header is the whole of the authentication. If OpenCode says the server “needs authentication”, the header is missing rather than the token being wrong.', 'ai-command-center' ),
		];
	}
}
