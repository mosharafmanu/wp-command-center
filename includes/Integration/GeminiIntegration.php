<?php
/**
 * Gemini CLI MCP Integration.
 *
 * Config lives in ~/.gemini/settings.json (or .gemini/settings.json per project) — not
 * in an Application Support directory, which is where the plugin used to point people.
 * Gemini CLI accepts a remote server via `httpUrl`, so it needs no relay and no Node.
 */

namespace WPCommandCenter\Integration;

defined( 'ABSPATH' ) || exit;

final class GeminiIntegration extends BaseClientIntegration {

	protected static string $client_name = 'Gemini CLI';

	protected static array $config_paths = [
		'macos'   => '~/.gemini/settings.json',
		'windows' => '%USERPROFILE%\\.gemini\\settings.json',
		'linux'   => '~/.gemini/settings.json',
	];

	public static function transport(): string {
		return 'http';
	}

	public static function generate_mcp_config(): array {
		$ep = self::http_endpoint();

		return [
			self::root_key() => [
				self::server_key() => [
					'httpUrl' => $ep['url'],
					'headers' => $ep['headers'],
				],
			],
		];
	}
}
