<?php
/**
 * Base MCP client integration — shared config generation for all AI clients.
 * Each client extends this and provides its own name, config paths, and
 * MCP client package. No per-client execution logic.
 */

namespace WPCommandCenter\Integration;

use WPCommandCenter\Mcp\McpServerRuntime;

defined( 'ABSPATH' ) || exit;

abstract class BaseClientIntegration {

	protected static string $client_name    = '';
	protected static string $mcp_package    = '@anthropic-ai/mcp-client';
	protected static array  $config_paths   = [];
	protected static string $client_command = 'npx';

	public static function generate_mcp_config(): array {
		$mcp_url  = rest_url( McpServerRuntime::NAMESPACE . '/mcp' );
		$site_url = get_site_url();

		$args = [ '-y', static::$mcp_package, $mcp_url ];

		return [
			'mcpServers' => [
				'wp-command-center' => [
					'command' => static::$client_command,
					'args'    => $args,
					'env'     => [
						'WPCC_MCP_URL'   => $mcp_url,
						'WPCC_SITE_URL'  => $site_url,
						'WPCC_TOKEN'     => '${WPCC_TOKEN}',
						'WPCC_CONTEXT_MODE' => 'compact',
					],
				],
			],
		];
	}

	public static function get_discovery_metadata(): array {
		return ClaudeIntegration::get_discovery_metadata();
	}
}
