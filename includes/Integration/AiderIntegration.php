<?php
/**
 * Aider MCP Integration.
 *
 * Connects through the shared MCP Server Runtime. No execution logic.
 */

namespace WPCommandCenter\Integration;

defined( 'ABSPATH' ) || exit;

final class AiderIntegration extends BaseClientIntegration {

	protected static string $client_name  = 'Aider';

	protected static array $config_paths = [
		'macos' => '~/.aider/mcp.json',
		'linux' => '~/.aider/mcp.json',
	];
}
