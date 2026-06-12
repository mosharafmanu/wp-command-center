<?php
/**
 * Codex MCP Integration.
 *
 * Connects through the shared MCP Server Runtime. No execution logic.
 */

namespace WPCommandCenter\Integration;

defined( 'ABSPATH' ) || exit;

final class CodexIntegration extends BaseClientIntegration {

	protected static string $client_name  = 'Codex';

	protected static array $config_paths = [
		'macos'   => '~/Library/Application Support/Codex/codex_config.json',
		'windows' => '%APPDATA%\\Codex\\codex_config.json',
		'linux'   => '~/.config/Codex/codex_config.json',
	];
}
