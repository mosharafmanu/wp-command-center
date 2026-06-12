<?php
/**
 * Step 54 — Cursor MCP Integration.
 *
 * Cursor IDE connects as a standard MCP client through the shared
 * MCP Server Runtime. This class provides Cursor-specific config
 * generation. No execution logic — consumes the existing MCP
 * Server Runtime exclusively.
 */

namespace WPCommandCenter\Integration;

use WPCommandCenter\Mcp\McpServerRuntime;

defined( 'ABSPATH' ) || exit;

final class CursorIntegration {

	/**
	 * Generate a Cursor MCP configuration block dynamically.
	 */
	public static function generate_mcp_config(): array {
		$mcp_url  = rest_url( McpServerRuntime::NAMESPACE . '/mcp' );
		$site_url = get_site_url();

		return [
			'mcpServers' => [
				'wp-command-center' => [
					'command' => 'npx',
					'args'    => [
						'-y',
						'@anthropic-ai/mcp-client',
						$mcp_url,
					],
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

	/**
	 * Get discovery metadata (platform-level, shared across all MCP clients).
	 */
	public static function get_discovery_metadata(): array {
		return ClaudeIntegration::get_discovery_metadata();
	}
}
