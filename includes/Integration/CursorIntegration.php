<?php
/**
 * Cursor MCP Integration.
 *
 * Config lives in ~/.cursor/mcp.json under `mcpServers`. That file is the one with real
 * end-to-end verification behind it (REAL_TEST_FINDINGS.md #4): 42 tools, 6 prompts,
 * 7 resources and a real read-only system_info call, executed from Cursor.
 *
 * TRANSPORT — moved from relay to direct HTTP, 2026-08-11.
 *
 * This class did not extend BaseClientIntegration at all, so it inherited nothing and
 * the registry's transport lookup fell through to its 'stdio' default. Cursor was
 * therefore advertised as "Relay / Node.js required" and handed a config that downloads
 * and runs a connector script. Cursor does not need any of that. Three independent
 * confirmations that it speaks remote HTTP MCP directly:
 *
 *   1. Cursor's documentation gives `{"url": ..., "headers": {...}}` as the remote
 *      server form.
 *   2. Cursor's own CLI writes exactly that shape — `cursor --add-mcp '{"name":...,
 *      "url":..., "headers":...}'` was run here and produced a `url` + `headers` server
 *      definition.
 *   3. The WPCC endpoint itself was driven over plain HTTP with a bearer token (initialize
 *      + tools/list, all 42 tools) using nothing but curl.
 *
 * The practical gain is not tidiness. The relay block is fourteen lines containing an
 * embedded shell command, and the reported defect in this finding is that people merge
 * this block into a file that already holds their other MCP servers. A four-line entry
 * is dramatically safer to merge by hand, and it drops the Node.js requirement that the
 * screen was (correctly, for a relay) warning about.
 *
 * NOTE ON `cursor --add-mcp`: it exists, but it writes to the VS Code-inherited
 * `mcp.servers` block in Cursor's User/settings.json — a DIFFERENT surface from
 * ~/.cursor/mcp.json. Whether Cursor's agent honours servers registered there was not
 * verified here, so it is deliberately not offered as a setup command. Advertising an
 * unverified registration path is exactly the failure this remediation exists to remove.
 */

namespace WPCommandCenter\Integration;

defined( 'ABSPATH' ) || exit;

final class CursorIntegration extends BaseClientIntegration {

	protected static string $client_name = 'Cursor';

	protected static array $config_paths = [
		'macos'   => '~/.cursor/mcp.json',
		'windows' => '%APPDATA%\\Cursor\\mcp.json',
		'linux'   => '~/.config/Cursor/mcp.json',
	];

	public static function transport(): string {
		return 'http';
	}

	/** Cursor carries the credential in a request header; no env-var indirection. */
	public static function credential_mode(): string {
		return 'inline';
	}

	/**
	 * Cursor's documented install URI opens the app and asks the user to review the
	 * server before saving it. The token is base64 encoded for transport, not encrypted;
	 * the UI therefore creates/opens this URI locally and labels it as sensitive.
	 */
	public static function setup_link( string $token = '' ): string {
		$ep    = self::http_endpoint();
		$value = '' !== $token ? $token : AIClientRegistry::TOKEN_PLACEHOLDER;
		$config = [
			'url'     => $ep['url'],
			'headers' => [ 'Authorization' => 'Bearer ' . $value ],
		];

		return 'cursor://anysphere.cursor-deeplink/mcp/install?name='
			. rawurlencode( self::server_key() )
			. '&config=' . rawurlencode( base64_encode( (string) wp_json_encode( $config, JSON_UNESCAPED_SLASHES ) ) );
	}

	public static function generate_mcp_config(): array {
		$ep = self::http_endpoint();

		return [
			self::root_key() => [
				self::server_key() => [
					'url'     => $ep['url'],
					'headers' => $ep['headers'],
				],
			],
		];
	}

	public static function post_setup_notes(): array {
		return [
			__( 'Restart Cursor after saving the file. Then open its MCP settings — WP Command Center should be listed as connected, with its tools, prompts and resources counted. If Cursor reports a connection error on every request, including ones that do not touch this site, that is Cursor’s own model connection and not your WordPress setup.', 'ai-command-center' ),
		];
	}
}
