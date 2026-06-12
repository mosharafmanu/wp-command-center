<?php
/**
 * Command Code MCP Integration.
 *
 * Connects through the shared MCP Server Runtime. No execution logic.
 */

namespace WPCommandCenter\Integration;

defined( 'ABSPATH' ) || exit;

final class CommandCodeIntegration extends BaseClientIntegration {

	protected static string $client_name  = 'Command Code';

	protected static array $config_paths = [
		'macos' => '~/.config/command-code/mcp.json',
		'linux' => '~/.config/command-code/mcp.json',
	];
}
