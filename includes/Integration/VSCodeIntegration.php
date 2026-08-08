<?php
/**
 * VS Code / GitHub Copilot MCP Integration.
 *
 * GitHub Copilot is the most widely adopted AI coding assistant in the market (29% of
 * workplace adoption as of 2026) and the plugin did not offer it at all.
 *
 * Its config root key is `servers` — NOT `mcpServers`. This is the single most common way
 * a VS Code MCP config silently fails: the file parses, VS Code finds no `servers` key,
 * and nothing appears, with no error. Every other client in this registry uses
 * `mcpServers`, which is exactly why it is easy to get wrong.
 *
 * VS Code speaks HTTP MCP directly, so no relay and no Node.js are involved.
 */

namespace WPCommandCenter\Integration;

defined( 'ABSPATH' ) || exit;

final class VSCodeIntegration extends BaseClientIntegration {

	protected static string $client_name = 'GitHub Copilot / VS Code';

	protected static array $config_paths = [
		'macos'   => '.vscode/mcp.json (workspace) or the user mcp.json',
		'windows' => '.vscode\\mcp.json (workspace) or the user mcp.json',
		'linux'   => '.vscode/mcp.json (workspace) or the user mcp.json',
	];

	public static function transport(): string {
		return 'http';
	}

	/** VS Code's root key. Getting this wrong is a silent no-op, not an error. */
	public static function root_key(): string {
		return 'servers';
	}

	public static function generate_mcp_config(): array {
		$ep = self::http_endpoint();

		return [
			self::root_key() => [
				self::server_key() => [
					'type'    => 'http',
					'url'     => $ep['url'],
					'headers' => $ep['headers'],
				],
			],
		];
	}
}
