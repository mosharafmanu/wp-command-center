<?php
/**
 * Command Code MCP Integration.
 *
 * CONVERTED FROM RELAY TO NATIVE DIRECT HTTP, 2026-08-11.
 *
 * This class inherited BaseClientIntegration's relay generator, so Command Code was
 * advertised as "Connector script / Node.js required" and handed a configuration that
 * downloads wpcc-mcp-relay.mjs and runs it under bash. Current Command Code needs none of
 * it: it has native MCP with stdio AND http transports and native custom headers.
 *
 * VERIFIED AGAINST THE INSTALLED CLIENT, by running it:
 *
 *   cmd mcp add --transport http --scope user \
 *     --header "Authorization: Bearer <token>" <name> <url>
 *
 * `cmd mcp list` then reported the server as TYPE http, SCOPE user, AUTH ✔, STATUS
 * enabled, and `cmd mcp get` showed the Authorization header stored and masked.
 *
 * HEADER FORMAT — and note it is the OPPOSITE of OpenCode's.
 *
 *   Command Code   --header "Authorization: Bearer <token>"    (Header: value)
 *   OpenCode       --header "Authorization=Bearer <token>"     (KEY=VALUE)
 *
 * Both were confirmed by running them. Command Code rejects the KEY=VALUE form outright:
 *
 *   Error: Invalid header format 'Authorization=Bearer ...', expected 'Header: value'
 *
 * which is at least a loud failure. The reverse mistake in OpenCode is the dangerous one,
 * because it produces a header named "Authorization: Bearer ..." with an empty value and
 * registers happily. Two clients, two syntaxes, one shared registry — so each integration
 * spells out its own and a test asserts both, in both directions.
 *
 * `--scope user` is passed deliberately: the CLI defaults to `local`, which would tie the
 * server to whatever directory the user happened to be standing in.
 *
 * OAUTH PROBE. On registration Command Code inspects the endpoint and reports "Server
 * requires OAuth authentication" — it reads Action Steward's 401 as an OAuth challenge. The server
 * is still added and the bearer header still authenticates it, but the message invites
 * someone to go looking for an OAuth flow that does not exist here, so post_setup_notes()
 * says so plainly.
 */

namespace WPCommandCenter\Integration;

defined( 'ABSPATH' ) || exit;

final class CommandCodeIntegration extends BaseClientIntegration {

	protected static string $client_name = 'Command Code';

	protected static array $config_paths = [
		'macos'   => '~/.commandcode/mcp.json',
		'linux'   => '~/.commandcode/mcp.json',
	];

	public static function transport(): string {
		return 'http';
	}

	public static function credential_mode(): string {
		return 'inline';
	}

	public static function setup_command( string $token = '' ): string {
		$ep    = self::http_endpoint();
		$value = '' !== $token ? $token : AIClientRegistry::TOKEN_PLACEHOLDER;

		return sprintf(
			"cmd mcp add \\\n  --transport http \\\n  --scope user \\\n  --header %s \\\n  %s %s",
			escapeshellarg( 'Authorization: Bearer ' . $value ),
			escapeshellarg( self::server_key() ),
			escapeshellarg( $ep['url'] )
		);
	}

	/**
	 * Manual fallback matching the file written by Command Code 1.51.0.
	 *
	 * Native `cmd mcp add` remains the supported and recommended path. This shape exists
	 * only for someone who deliberately opens ~/.commandcode/mcp.json; the setup screen
	 * tells them to merge this server into the existing mcpServers object.
	 */
	public static function generate_mcp_config(): array {
		$ep = self::http_endpoint();

		return [
			self::root_key() => [
				self::server_key() => [
					'transport' => 'http',
					'enabled'   => true,
					'url'       => $ep['url'],
					'headers'   => $ep['headers'],
				],
			],
		];
	}

	public static function post_setup_notes(): array {
		return [
			__( 'When you add the server, Command Code may say “Server requires OAuth authentication”. It is reading this site’s refusal of an unauthenticated request as an OAuth challenge. There is no OAuth sign-in to complete — the access token in the header above is the whole of it, and the server is added and enabled regardless.', 'action-steward' ),
		];
	}
}
