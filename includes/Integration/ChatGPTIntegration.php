<?php
/**
 * ChatGPT MCP Integration.
 *
 * Connects through the shared MCP Server Runtime. No execution logic.
 */

namespace WPCommandCenter\Integration;

defined( 'ABSPATH' ) || exit;

final class ChatGPTIntegration extends BaseClientIntegration {

	protected static string $client_name  = 'ChatGPT';

	protected static array $config_paths = [
		'macos'   => '~/Library/Application Support/ChatGPT/mcp.json',
		'windows' => '%APPDATA%\\ChatGPT\\mcp.json',
		'linux'   => '~/.config/ChatGPT/mcp.json',
	];
}
