<?php
/**
 * Codex MCP Integration — Codex CLI and the Codex engine inside ChatGPT Desktop.
 *
 * Codex is the one client in this registry that does NOT read JSON. It reads TOML from
 * ~/.codex/config.toml, keyed [mcp_servers.<name>] — note the underscore, where every
 * JSON client uses `mcpServers`. The plugin previously handed Codex users a JSON blob
 * for ~/Library/Application Support/Codex/codex_config.json: wrong format, wrong path,
 * and a file Codex never reads.
 *
 * ChatGPT Desktop reads this same file. That is not an assumption: /Applications/
 * ChatGPT.app ships a full `codex` binary at Contents/Resources/codex whose config
 * struct references config.toml, CODEX_HOME and mcp_servers, and the app's own
 * Settings -> Plugins -> MCPs panel writes [mcp_servers.*] blocks into
 * ~/.codex/config.toml. So ChatGPTIntegration defers here rather than inventing a path.
 *
 * Codex speaks Streamable HTTP with a bearer token, so it uses the relay-free transport.
 *
 * CREDENTIAL SHAPE — corrected 2026-08-11 from a live client test.
 *
 * This class used to emit:
 *
 *     bearer_token = "${WPCC_TOKEN}"
 *
 * TOML has no string interpolation, and Codex performs none. That configuration sent
 * the literal 14 characters `${WPCC_TOKEN}` as the bearer token, so a valid token and a
 * healthy server still produced 401. Codex's own config struct carries a separate key
 * for exactly this reason, and `codex mcp add --help` offers only:
 *
 *     --bearer-token-env-var <ENV_VAR>
 *
 * There is no flag for a literal token. The env-var form is therefore both the correct
 * one AND the more secure one — the token never lands in a plaintext config file.
 */

namespace WPCommandCenter\Integration;

defined( 'ABSPATH' ) || exit;

final class CodexIntegration extends BaseClientIntegration {

	protected static string $client_name = 'Codex CLI';

	protected static array $config_paths = [
		'macos'   => '~/.codex/config.toml',
		'windows' => '%USERPROFILE%\\.codex\\config.toml',
		'linux'   => '~/.codex/config.toml',
	];

	public static function transport(): string {
		return 'http';
	}

	public static function credential_mode(): string {
		return 'env_var';
	}

	/**
	 * The primary artifact for Codex is the native registration command, not a file to
	 * edit. Verified against `codex mcp add --help` (codex-cli 0.146.0) and by running
	 * it: it writes precisely the [mcp_servers.<name>] block below into
	 * ~/.codex/config.toml, and `codex mcp list` then reports the server as
	 * "enabled / Bearer token".
	 *
	 * The command contains no secret — only the NAME of the environment variable — so it
	 * is safe to display, copy, screenshot and paste into a shared terminal.
	 */
	public static function setup_command( string $token = '' ): string {
		$ep = self::http_endpoint();

		return sprintf(
			"codex mcp add %s \\\n  --url %s \\\n  --bearer-token-env-var %s",
			escapeshellarg( self::server_key() ),
			escapeshellarg( $ep['url'] ),
			escapeshellarg( self::credential_env_var() )
		);
	}

	/** Shell-launched Codex inherits credentials from the terminal that starts it. */
	public static function credential_commands( string $token = '' ): array {
		// The shared placeholder, so the browser-side token fill that completes the
		// configuration block completes these commands too. A second placeholder string
		// would be a second thing to keep in sync, and the last time this screen had two
		// they drifted apart and the fill silently stopped working.
		$value = '' !== $token ? $token : AIClientRegistry::TOKEN_PLACEHOLDER;
		$var   = self::credential_env_var();

		return [
			'macos'   => sprintf( 'export %s=%s', $var, escapeshellarg( $value ) ),
			'linux'   => sprintf( 'export %s=%s', $var, escapeshellarg( $value ) ),
			'windows' => sprintf( 'setx %s "%s"', $var, $value ),
		];
	}

	/**
	 * Manual fallback for someone who would rather edit the file than run the command.
	 *
	 * TOML cannot be expressed as a PHP array the view can json_encode, so this returns
	 * a raw block. `__format` tells the renderer to print `__raw` verbatim instead of
	 * encoding it as JSON.
	 *
	 * Note there is no token in here by design — see the credential-shape note above.
	 */
	public static function generate_mcp_config(): array {
		$ep = self::http_endpoint();

		return [
			'__format' => 'toml',
			'__raw'    => sprintf(
				"[mcp_servers.%s]\nurl = \"%s\"\nbearer_token_env_var = \"%s\"\ndefault_tools_approval_mode = \"writes\"\n",
				self::server_key(),
				$ep['url'],
				self::credential_env_var()
			),
		];
	}

	public static function post_setup_notes(): array {
		return [
			__( 'Why the launch command: the current codex mcp add command can register the URL and token-variable name, but it cannot safely set or merge Codex’s server-specific approval mode. The copy-ready on-request launch keeps client approvals available without changing your global Codex settings.', 'action-steward' ),
			__( 'Advanced persistent option: Codex supports default_tools_approval_mode = "writes" inside [mcp_servers.wp-command-center]. It skips the client prompt only for tools Action Steward truthfully marks read-only and prompts for other Action Steward tools. Add it manually only if you are comfortable merging TOML; Action Steward never edits the file itself.', 'action-steward' ),
		];
	}
}
