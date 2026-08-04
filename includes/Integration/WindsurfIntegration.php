<?php
/**
 * Windsurf MCP Integration.
 *
 * Windsurf keeps MCP config at ~/.codeium/windsurf/mcp_config.json — the Codeium
 * directory, not a Windsurf one. The plugin previously pointed at
 * ~/Library/Application Support/Windsurf/mcp.json, which Windsurf does not read.
 * Format is the standard stdio `mcpServers` block, so it inherits the relay config.
 */

namespace WPCommandCenter\Integration;

defined( 'ABSPATH' ) || exit;

final class WindsurfIntegration extends BaseClientIntegration {

	protected static string $client_name = 'Windsurf';

	protected static array $config_paths = [
		'macos'   => '~/.codeium/windsurf/mcp_config.json',
		'windows' => '%USERPROFILE%\\.codeium\\windsurf\\mcp_config.json',
		'linux'   => '~/.codeium/windsurf/mcp_config.json',
	];
}
