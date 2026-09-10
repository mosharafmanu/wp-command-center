<?php
/**
 * Continue (VS Code / JetBrains extension) MCP Integration.
 *
 * CORRECTED 2026-08-11 against a working installation. Three things were wrong, and each
 * one alone was enough to make the generated configuration useless:
 *
 *   1. WRONG FILE. WPCC told people to create ~/.continue/mcp.json. A current Continue
 *      install has no such file — `cat` returns "No such file or directory" — and the
 *      extension's own "Main Config" action opens ~/.continue/config.yaml.
 *
 *   2. WRONG FORMAT. That file is YAML. WPCC emitted JSON.
 *
 *   3. WRONG STRUCTURE, which is the subtle one. Every other client in this registry
 *      keys servers by name inside an `mcpServers` OBJECT. Continue's `mcpServers` is a
 *      LIST, and the server's name is a `name:` field on each item:
 *
 *          mcpServers:
 *            - name: wp-command-center
 *              command: bash
 *
 *      A config translated faithfully from WPCC's JSON into YAML would still have been
 *      the wrong shape. Confirmed against a config.yaml that Continue was actually
 *      reading, not from a translation of our own output.
 *
 * TRANSPORT. The relay stays. Continue's MCP tool execution was verified end to end
 * through it (system_info returning WordPress/PHP/MySQL/theme/plugin values consistent
 * with other clients), and this finding explicitly warns against replacing a working
 * backend to fix an onboarding defect. Unlike Cursor, no independent confirmation of a
 * remote-HTTP form was obtained for Continue, so switching it would be trading a verified
 * path for an assumed one.
 */

namespace WPCommandCenter\Integration;

use WPCommandCenter\Mcp\McpServerRuntime;

defined( 'ABSPATH' ) || exit;

final class ContinueIntegration extends BaseClientIntegration {

	protected static string $client_name = 'Continue for VS Code';

	protected static array $config_paths = [
		'macos'   => '~/.continue/config.yaml',
		'windows' => '%USERPROFILE%\\.continue\\config.yaml',
		'linux'   => '~/.continue/config.yaml',
	];

	/** Relay: Continue launches the connector over stdio. */
	public static function transport(): string {
		return 'stdio';
	}

	public static function credential_mode(): string {
		return 'inline';
	}

	/**
	 * YAML, in Continue's list shape.
	 *
	 * Emitted as a raw block rather than built as an array and encoded, for the same
	 * reason Codex's TOML is: the view's renderer produces JSON from arrays, and this
	 * file is not JSON. Indentation is significant here, so the block is written exactly
	 * as it must appear.
	 *
	 * The relay bootstrap is a single long command. It is emitted as a YAML double-quoted
	 * scalar on one line: Continue's own editor may re-wrap it when saving, but a folded
	 * or literal block written by hand is where indentation mistakes come from, and this
	 * text is going to be copied and pasted by people who are not editing YAML daily.
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

		// Escape for a YAML double-quoted scalar: backslashes first, then quotes.
		$bootstrap_yaml = str_replace( [ '\\', '"' ], [ '\\\\', '\\"' ], $bootstrap );

		$raw  = "mcpServers:\n";
		$raw .= '  - name: ' . self::server_key() . "\n";
		$raw .= "    command: bash\n";
		$raw .= "    args:\n";
		$raw .= "      - -c\n";
		$raw .= '      - "' . $bootstrap_yaml . "\"\n";
		$raw .= "    env:\n";
		$raw .= '      WPCC_MCP_URL: ' . $mcp_url . "\n";
		$raw .= '      WPCC_SITE_URL: ' . $site_url . "\n";
		$raw .= '      WPCC_TOKEN: ' . AIClientRegistry::TOKEN_PLACEHOLDER . "\n";
		$raw .= "      WPCC_CONTEXT_MODE: compact\n";

		return [
			'__format' => 'yaml',
			'__raw'    => $raw,
		];
	}

	/**
	 * A single, correctly indented YAML list item for an existing `mcpServers:` key.
	 * Keep the two leading spaces: pasting a column-zero dash beneath a top-level key
	 * makes it a new document item instead of a child of mcpServers.
	 */
	public static function primary_config( string $token = '' ): string {
		$full = (string) ( self::generate_mcp_config()['__raw'] ?? '' );
		$lines = preg_split( '/\R/', rtrim( $full ) ) ?: [];
		array_shift( $lines );
		$snippet = implode( "\n", $lines ) . "\n";

		if ( '' !== $token ) {
			$snippet = str_replace( AIClientRegistry::TOKEN_PLACEHOLDER, $token, $snippet );
		}

		return $snippet;
	}

	/**
	 * The two things that made a correct configuration look broken during real testing.
	 *
	 * The first is not WPCC's problem to solve but is absolutely WPCC's problem to state:
	 * Continue cannot invoke ANY MCP tool until a tool-capable chat model is configured
	 * and selected, and until then the agent simply refuses to act. Someone who has just
	 * pasted this configuration will read that as "the WordPress connection failed".
	 */
	public static function post_setup_notes(): array {
		return [
			__( 'Continue needs its own AI model set up before it can use any of these tools — that is separate from connecting to this site. If Continue shows “Select model” or “Setup Chat model”, finish that first, and choose a model that supports tool calling. Connecting WP Command Center does not give Continue a model.', 'ai-command-center' ),
			__( 'Use the complete MCP block when mcpServers is missing. If it already exists, add only the indented WP Command Center list item beneath it and do not create a second mcpServers key.', 'ai-command-center' ),
		];
	}
}
