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

	/**
	 * Direct-HTTP MCP configuration — no relay, no Node.js, no local process.
	 *
	 * The WPCC MCP endpoint is a plain JSON-RPC-over-HTTP MCP server: POST a JSON-RPC
	 * envelope with a Bearer token and it answers. That was verified with nothing but
	 * curl — `initialize` returned protocol 2024-11-05 and `tools/list` returned all 42
	 * tools with no relay in the picture at all.
	 *
	 * This matters because the relay was the only path the product offered, and it made
	 * Node.js a hard client-side requirement (documented as a limitation in readme.txt).
	 * Every client that speaks remote/HTTP MCP — Codex, ChatGPT, VS Code/Copilot, Gemini
	 * CLI, Claude Code, Cursor — can skip the relay entirely.
	 *
	 * Requires the site to be reachable over HTTPS from the client machine, so it is not
	 * a replacement for the relay on localhost development sites.
	 *
	 * @return array{url:string,headers:array<string,string>}
	 */
	protected static function http_endpoint(): array {
		return [
			'url'     => rest_url( McpServerRuntime::NAMESPACE . '/mcp' ),
			'headers' => [ 'Authorization' => 'Bearer ${WPCC_TOKEN}' ],
		];
	}

	/**
	 * How this client should be configured. Overridden per client.
	 *
	 * `stdio` = relay via BaseClientIntegration::generate_mcp_config().
	 * `http`  = direct HTTP, no Node.
	 */
	public static function transport(): string {
		return 'stdio';
	}

	/**
	 * The config file's root key. NOT universal: VS Code and GitHub Copilot use
	 * `servers`, everyone else in this registry uses `mcpServers`. Getting this wrong
	 * produces a config the client silently ignores.
	 */
	public static function root_key(): string {
		return 'mcpServers';
	}

	/** The server key the client will show the user. */
	public static function server_key(): string {
		return 'wp-command-center';
	}

	public static function get_discovery_metadata(): array {
		return ClaudeIntegration::get_discovery_metadata();
	}
}
