<?php
/**
 * VS Code / GitHub Copilot MCP Integration.
 *
 * GitHub Copilot is the most widely adopted AI coding assistant in the market (29% of
 * workplace adoption as of 2026) and the plugin did not offer it at all.
 *
 * Its config root key is `servers` — NOT `mcpServers`. This is the single most common way
 * a VS Code MCP config silently fails: the file parses, VS Code finds no `servers` key,
 * and nothing appears, with no error. Every other client in this registry uses
 * `mcpServers`, which is exactly why it is easy to get wrong.
 *
 * VS Code speaks HTTP MCP directly, so no relay and no Node.js are involved.
 */

namespace WPCommandCenter\Integration;

defined( 'ABSPATH' ) || exit;

final class VSCodeIntegration extends BaseClientIntegration {

	protected static string $client_name = 'GitHub Copilot in VS Code';

	protected static array $config_paths = [
		'macos'   => '.vscode/mcp.json (workspace) or the user mcp.json',
		'windows' => '.vscode\\mcp.json (workspace) or the user mcp.json',
		'linux'   => '.vscode/mcp.json (workspace) or the user mcp.json',
	];

	public static function transport(): string {
		return 'http';
	}

	/** VS Code's root key. Getting this wrong is a silent no-op, not an error. */
	public static function root_key(): string {
		return 'servers';
	}

	/**
	 * The token is PROMPTED FOR, not written into the file.
	 *
	 * VS Code supports an `inputs` block: a server can reference `${input:<id>}` and VS
	 * Code asks for the value on first use, masking it (`"password": true`) and keeping it
	 * in its own secret storage rather than in the JSON. This is the structure that was
	 * verified working against GitHub Copilot (REAL_TEST_FINDINGS.md #8) — connection
	 * Running, all 42 tools discovered.
	 *
	 * It is also the best credential handling any client in this registry offers, which is
	 * why it is used here in preference to the plain header WPCC emitted before. A
	 * .vscode/mcp.json is very often committed to a repository; a config that carries a
	 * live site token into version control is a bad default even when it works. This one
	 * can be committed safely — it contains a prompt, not a secret.
	 *
	 * Consequence worth noting: for this client the "Copy configuration" output is
	 * complete WITHOUT a token, so the token-fill box has nothing to substitute. That is
	 * correct rather than broken — see credential_mode().
	 */
	public static function credential_mode(): string {
		return 'prompt';
	}

	/**
	 * Build the documented VS Code HTTP + input-variable shape.
	 *
	 * The input id is scoped to the site for API callers and, in the guided UI, to the
	 * selected token record. VS Code deliberately remembers password inputs in secure
	 * storage, so a new token id must mean a new secure-input slot. That prevents silent
	 * reuse of an older value, but it does not make the value correct: a missing or pasted
	 * setup block still produces an invalid Bearer header. Current VS Code turns that
	 * ordinary MCP 401 into OAuth discovery and then DCR even when the server advertises no
	 * OAuth. The guided setup therefore restores the freshly created token to the clipboard
	 * immediately before the server is started.
	 *
	 * Only a one-way digest of the non-secret token record id is exposed. The raw token is
	 * still entered directly into VS Code and never appears in this configuration.
	 */
	private static function config_for_credential( string $credential_id = '' ): array {
		$ep = self::http_endpoint();
		$seed = '' !== $credential_id ? $credential_id : $ep['url'];
		$input_id = 'wpcc-token-' . substr( hash( 'sha256', $ep['url'] . '|' . $seed ), 0, 12 );
		$site_name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		return [
			self::root_key() => [
				self::server_key() => [
					'type'    => 'http',
					'url'     => $ep['url'],
					'headers' => [ 'Authorization' => 'Bearer ${input:' . $input_id . '}' ],
				],
			],
			'inputs' => [
				[
					'type'        => 'promptString',
					'id'          => $input_id,
					'description' => sprintf( 'Action Steward token for %s', $site_name ),
					'password'    => true,
				],
			],
		];
	}

	public static function generate_mcp_config(): array {
		return self::config_for_credential();
	}

	/** Complete recommended config, keyed to the token record selected in WPCC. */
	public static function primary_config_for_credential( string $token = '', string $credential_id = '' ): string {
		return (string) wp_json_encode(
			self::config_for_credential( $credential_id ),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
	}

	public static function post_setup_notes(): array {
		return [
			__( 'VS Code asks for your access token the first time it uses this connection and stores it in its own secure storage — it is not saved in the file. That means this file is safe to commit to a repository, and each person who uses it supplies their own token.', 'action-steward' ),
			__( 'If VS Code opens an OAuth or client-ID screen, cancel it. Action Steward does not use OAuth here. It means the saved Action Steward token was missing or invalid: choose Edit Stored Input beside the token reference, paste a current token from this site, and restart wp-command-center.', 'action-steward' ),
			__( 'The key must be “servers”. Every other assistant uses “mcpServers”, and VS Code simply ignores a file with the wrong key instead of reporting an error — so a config that looks right can do nothing at all.', 'action-steward' ),
		];
	}
}
