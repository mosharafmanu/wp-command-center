<?php
/**
 * Antigravity CLI (agy) MCP integration, verified against installed 1.1.27.
 * Native add merges one named server into ~/.gemini/config/mcp_config.json.
 * Remote MCP uses serverUrl and inline Authorization headers. This is distinct
 * from Gemini CLI (gemini), which uses ~/.gemini/settings.json and url/type.
 */

namespace WPCommandCenter\Integration;

defined( 'ABSPATH' ) || exit;

final class AntigravityIntegration extends BaseClientIntegration {

	protected static string $client_name = 'Antigravity CLI';

	protected static array $config_paths = [
		'macos'   => '~/.gemini/config/mcp_config.json',
		'windows' => '%USERPROFILE%\\.gemini\\config\\mcp_config.json',
		'linux'   => '~/.gemini/config/mcp_config.json',
	];

	public static function transport(): string {
		return 'http';
	}

	/** The credential travels in a request header; there is no env-var indirection. */
	public static function credential_mode(): string {
		return 'inline';
	}

	/** Flags must precede positionals in agy 1.1.27. The header is sensitive. */
	public static function setup_command( string $token = '' ): string {
		$ep = self::http_endpoint();
		$value = '' !== $token ? $token : AIClientRegistry::TOKEN_PLACEHOLDER;
		return sprintf(
			"agy mcp add --header %s %s %s",
			escapeshellarg( 'Authorization: Bearer ' . $value ),
			escapeshellarg( self::server_key() ),
			escapeshellarg( $ep['url'] )
		);
	}

	/**
	 * `serverUrl`, not `httpUrl` and not `url`.
	 *
	 * This is the single field name that distinguishes a working Antigravity config from
	 * one it ignores, and it is the reason this client cannot share Gemini CLI's
	 * generator. Confirmed against a working ~/.gemini/config/mcp_config.json.
	 */
	public static function generate_mcp_config(): array {
		$ep = self::http_endpoint();

		return [
			self::root_key() => [
				self::server_key() => [
					'serverUrl' => $ep['url'],
					'headers'   => $ep['headers'],
				],
			],
		];
	}

	public static function post_setup_notes(): array {
		return [
			__( 'This is Antigravity CLI (agy), not Gemini CLI (gemini) or an IDE setup. agy stores the bearer token in ~/.gemini/config/mcp_config.json. Do not commit or share this file, paste the command into shared chats, or save the token in a shell profile. Revoke the token in Action Steward to remove access.', 'action-steward' ),
			__( 'Exit any running agy session, then run agy again. Use /mcp to verify wp-command-center is connected and lists 42 tools; ask for system_info and report_manage with action report_site_health. agy mcp list confirms registration only. The Action Steward browser test checks the endpoint, not whether agy connected.', 'action-steward' ),
			__( 'Antigravity asks your permission the first time it uses a Action Steward tool. That prompt is Antigravity’s own safety check and is separate from this site’s approval rules — approving it lets the assistant ask, it does not bypass this site’s protection mode or any required human approval.', 'action-steward' ),
		];
	}
}
