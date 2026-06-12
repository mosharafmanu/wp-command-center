<?php
/**
 * Base MCP client integration — shared config generation for all AI clients.
 * Each client extends this and provides its own name and config paths.
 * No per-client execution logic.
 *
 * Generates a working config that bridges the client's stdio MCP transport
 * to WPCC's HTTP MCP endpoint via the built-in wpcc-mcp-relay.mjs script.
 */

namespace WPCommandCenter\Integration;

use WPCommandCenter\Mcp\McpServerRuntime;

defined( 'ABSPATH' ) || exit;

abstract class BaseClientIntegration {

	protected static string $client_name  = '';
	protected static array  $config_paths = [];

	/**
	 * Generate an MCP configuration block that works with any stdio-based
	 * MCP client (Claude Desktop, Cursor, Continue, etc.).
	 *
	 * Downloads the latest WPCC MCP relay script on every startup and runs it.
	 * The relay bridges stdio ↔ HTTP so clients can reach the WPCC MCP endpoint.
	 */
	public static function generate_mcp_config(): array {
		$mcp_url    = rest_url( McpServerRuntime::NAMESPACE . '/mcp' );
		$site_url   = get_site_url();
		$relay_url  = WPCC_PLUGIN_URL . 'sdk/javascript/wpcc-mcp-relay.mjs';
		$relay_path = '/tmp/wpcc-mcp-relay.mjs';

		$bootstrap = sprintf(
			'RELAY=%s; curl -fsSL -o "$RELAY" %s; node "$RELAY"',
			escapeshellarg( $relay_path ),
			escapeshellarg( $relay_url . '?v=' . WPCC_VERSION )
		);

		return [
			'mcpServers' => [
				'wp-command-center' => [
					'command' => 'bash',
					'args'    => [ '-c', $bootstrap ],
					'env'     => [
						'WPCC_MCP_URL'      => $mcp_url,
						'WPCC_SITE_URL'     => $site_url,
						'WPCC_TOKEN'        => '${WPCC_TOKEN}',
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
