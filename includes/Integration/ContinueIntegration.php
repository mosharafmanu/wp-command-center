<?php
/**
 * Continue MCP Integration.
 *
 * Connects through the shared MCP Server Runtime. No execution logic.
 */

namespace WPCommandCenter\Integration;

defined( 'ABSPATH' ) || exit;

final class ContinueIntegration extends BaseClientIntegration {

	protected static string $client_name  = 'Continue';

	protected static array $config_paths = [
		'macos'   => '~/.continue/mcp.json',
		'windows' => '%USERPROFILE%\\.continue\\mcp.json',
		'linux'   => '~/.continue/mcp.json',
	];
}
