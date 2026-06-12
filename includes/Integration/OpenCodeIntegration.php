<?php
/**
 * OpenCode MCP Integration.
 *
 * Connects through the shared MCP Server Runtime. No execution logic.
 */

namespace WPCommandCenter\Integration;

defined( 'ABSPATH' ) || exit;

final class OpenCodeIntegration extends BaseClientIntegration {

	protected static string $client_name  = 'OpenCode';

	protected static array $config_paths = [
		'macos'   => '~/.config/opencode/mcp.json',
		'windows' => '%APPDATA%\\opencode\\mcp.json',
		'linux'   => '~/.config/opencode/mcp.json',
	];
}
