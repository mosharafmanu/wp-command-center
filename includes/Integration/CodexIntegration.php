<?php
/**
 * Codex CLI MCP Integration.
 *
 * Codex is the one client in this registry that does NOT read JSON. It reads TOML from
 * ~/.codex/config.toml, keyed [mcp_servers.<name>] — note the underscore, where every
 * JSON client uses `mcpServers`. The plugin previously handed Codex users a JSON blob
 * for ~/Library/Application Support/Codex/codex_config.json: wrong format, wrong path,
 * and a file Codex never reads. That configuration could not have worked, which makes
 * the registry's previous "compatible" status for Codex impossible.
 *
 * ChatGPT Desktop reads this same file — it shares MCP configuration with Codex CLI on
 * the same host — so ChatGPTIntegration defers here rather than inventing a path.
 *
 * Codex speaks Streamable HTTP with a bearer token, so it uses the relay-free transport.
 */

namespace WPCommandCenter\Integration;

defined( 'ABSPATH' ) || exit;

final class CodexIntegration extends BaseClientIntegration {

	protected static string $client_name = 'Codex CLI';

	protected static array $config_paths = [
		'macos'   => '~/.codex/config.toml',
		'windows' => '%USERPROFILE%\\.codex\\config.toml',
		'linux'   => '~/.codex/config.toml',
	];

	public static function transport(): string {
		return 'http';
	}

	/**
	 * TOML cannot be expressed as a PHP array the view can json_encode, so this returns
	 * a raw block. `__format` tells the renderer to print `__raw` verbatim instead of
	 * encoding it as JSON.
	 */
	public static function generate_mcp_config(): array {
		$ep = self::http_endpoint();

		return [
			'__format' => 'toml',
			'__raw'    => sprintf(
				"[mcp_servers.%s]\nurl = \"%s\"\nbearer_token = \"%s\"\n",
				self::server_key(),
				$ep['url'],
				'${WPCC_TOKEN}'
			),
		];
	}
}
