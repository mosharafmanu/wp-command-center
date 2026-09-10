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

	/**
	 * How this client receives the access token. This is NOT cosmetic: it decides
	 * whether the token belongs in the configuration text at all.
	 *
	 * 'inline'  — the token is written into the configuration itself, so
	 *             AIClientRegistry::render_config() substitutes TOKEN_PLACEHOLDER and
	 *             "Copy configuration" yields something complete.
	 * 'env_var' — the configuration names an ENVIRONMENT VARIABLE and the token is
	 *             never written into it. Substituting the placeholder here would
	 *             produce a config that silently fails, because the client would look
	 *             up an environment variable whose name is the token.
	 *
	 * Real-world origin: Codex and ChatGPT Desktop expose a field labelled
	 * "Bearer token env var". During live testing a valid token pasted into that field
	 * produced a 401 from a completely healthy server, because the client dutifully
	 * looked for an environment variable named `wpcc_jkSf...`. The credential mode is
	 * what lets the screen explain that difference instead of leaving the user to
	 * discover it from a failed connection.
	 */
	public static function credential_mode(): string {
		return 'inline';
	}

	/**
	 * The environment variable a credential_mode() === 'env_var' client should read.
	 * Deliberately the same name as the placeholder token so the two never diverge.
	 */
	public static function credential_env_var(): string {
		return 'WPCC_TOKEN';
	}

	/**
	 * A native, ready-to-run command that registers this server with the client, or ''
	 * when the client has no such mechanism and the user must edit a file.
	 *
	 * Preferred over hand-edited configuration wherever a client provides it: it cannot
	 * be pasted into the wrong file, cannot be malformed, and — for env_var clients —
	 * never contains the token.
	 *
	 * @param string $token Real token, or '' to keep the placeholder.
	 */
	public static function setup_command( string $token = '' ): string {
		return '';
	}

	/**
	 * Optional app-native install link. Kept separate from setup_command() because a
	 * custom-protocol URL must be opened, never pasted into Terminal.
	 */
	public static function setup_link( string $token = '' ): string {
		return '';
	}

	/**
	 * The smallest copyable fragment used by the recommended setup. Most clients use
	 * render_entry_config(); YAML clients may override this with their native list item.
	 */
	public static function primary_config( string $token = '' ): string {
		return '';
	}

	/**
	 * Optional token-free helper that safely prepares a client's configuration file.
	 * File-owning integrations provide this only when a missing file is a normal
	 * first-run state and the command can preserve an existing file byte-for-byte.
	 */
	public static function prepare_config_command(): string {
		return '';
	}

	/**
	 * The command that puts the token where a credential_mode() === 'env_var' client
	 * will find it, or '' when this client does not use an environment variable.
	 *
	 * Returned per-OS because the mechanism is genuinely different, and because a GUI
	 * application launched from Finder or the Start menu does NOT inherit the shell
	 * profile — `export` in .zshrc reaches a terminal and nothing else. That single
	 * fact is why a correct-looking setup fails for desktop clients.
	 *
	 * @param string $token Real token, or '' to keep a safe placeholder.
	 * @return array<string,string> OS key => command.
	 */
	public static function credential_commands( string $token = '' ): array {
		return [];
	}

	public static function get_discovery_metadata(): array {
		return ClaudeIntegration::get_discovery_metadata();
	}
}
