<?php
/**
 * Roo Code MCP Integration.
 *
 * Connects through the shared MCP Server Runtime. No execution logic.
 */

namespace WPCommandCenter\Integration;

defined( 'ABSPATH' ) || exit;

final class RooCodeIntegration extends BaseClientIntegration {

	protected static string $client_name  = 'Roo Code';

	protected static array $config_paths = [
		'macos'   => '~/Library/Application Support/Roo Code/mcp.json',
		'windows' => '%APPDATA%\\Roo Code\\mcp.json',
		'linux'   => '~/.config/Roo Code/mcp.json',
	];
}
