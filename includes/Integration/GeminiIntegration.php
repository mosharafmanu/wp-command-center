<?php
/**
 * Gemini MCP Integration.
 *
 * Connects through the shared MCP Server Runtime. No execution logic.
 */

namespace WPCommandCenter\Integration;

defined( 'ABSPATH' ) || exit;

final class GeminiIntegration extends BaseClientIntegration {

	protected static string $client_name  = 'Gemini';

	protected static array $config_paths = [
		'macos'   => '~/Library/Application Support/Gemini/mcp.json',
		'windows' => '%APPDATA%\\Gemini\\mcp.json',
		'linux'   => '~/.config/Gemini/mcp.json',
	];
}
