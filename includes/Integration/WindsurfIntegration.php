<?php
/**
 * Windsurf MCP Integration.
 *
 * Connects through the shared MCP Server Runtime. No execution logic.
 */

namespace WPCommandCenter\Integration;

defined( 'ABSPATH' ) || exit;

final class WindsurfIntegration extends BaseClientIntegration {

	protected static string $client_name  = 'Windsurf';

	protected static array $config_paths = [
		'macos'   => '~/Library/Application Support/Windsurf/mcp.json',
		'windows' => '%APPDATA%\\Windsurf\\mcp.json',
		'linux'   => '~/.config/Windsurf/mcp.json',
	];
}
