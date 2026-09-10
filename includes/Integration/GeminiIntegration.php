<?php
/**
 * Gemini CLI MCP Integration.
 *
 * Config lives in ~/.gemini/settings.json (or .gemini/settings.json per project) — not
 * in an Application Support directory, which is where the plugin used to point people.
 * Gemini CLI speaks remote HTTP MCP, so it needs no relay and no Node.
 *
 * THIS IS THE CLI, NOT "GEMINI". Google ships several things called Gemini — web, mobile,
 * the CLI, and the Antigravity tooling — and they do not share this configuration. The
 * card used to be labelled just "Gemini", which invited someone using Gemini on the web
 * to follow instructions that cannot apply to it. See AntigravityIntegration for the
 * other Google surface that WPCC supports.
 *
 * CONFIGURATION SHAPE — corrected 2026-08-11 against the real client.
 *
 * This class emitted `httpUrl`. That is a legacy alias which older builds accept, but it
 * is NOT what the tool itself writes. Running the official command on Gemini CLI 0.46.0:
 *
 *     gemini mcp add <name> <url> --transport http --scope user --header "..."
 *
 * produces `{"url": ..., "type": "http", "headers": {...}}`. Generating the same shape
 * the vendor's own command generates is the only version of this that cannot drift: when
 * a hand-written config and the official command disagree, the hand-written one is the
 * one that eventually stops working.
 */

namespace WPCommandCenter\Integration;

defined( 'ABSPATH' ) || exit;

final class GeminiIntegration extends BaseClientIntegration {

	protected static string $client_name = 'Gemini CLI';

	protected static array $config_paths = [
		'macos'   => '~/.gemini/settings.json',
		'windows' => '%USERPROFILE%\\.gemini\\settings.json',
		'linux'   => '~/.gemini/settings.json',
	];

	public static function transport(): string {
		return 'http';
	}

	/**
	 * Gemini CLI carries the credential in a request header and offers no environment
	 * variable indirection for it, so the token really does live in the configuration.
	 * Declared honestly rather than pretending otherwise: the setup screen marks the
	 * command as sensitive on the strength of this.
	 */
	public static function credential_mode(): string {
		return 'inline';
	}

	/**
	 * The native registration command — verified by running it (Gemini CLI 0.46.0).
	 *
	 * This is the answer to the merge hazard in this finding. Gemini CLI users already
	 * have a settings.json holding authentication, IDE and UI preferences; during real
	 * testing, hand-merging Action Steward's block into it produced a JSON syntax error. The
	 * command edits the file correctly and leaves every unrelated key untouched — which
	 * was confirmed here, not assumed.
	 *
	 * `--scope user` is deliberate. The default scope is `project`, which would write
	 * into whatever directory the user happened to be standing in and leave the server
	 * invisible everywhere else.
	 *
	 * Unlike Codex, the token IS in this command, because the client has nowhere else to
	 * put it. Treated as sensitive on screen accordingly.
	 */
	public static function setup_command( string $token = '' ): string {
		$ep    = self::http_endpoint();
		$value = '' !== $token ? $token : AIClientRegistry::TOKEN_PLACEHOLDER;

		return sprintf(
			"gemini mcp add %s %s \\\n  --transport http \\\n  --scope user \\\n  --header %s",
			escapeshellarg( self::server_key() ),
			escapeshellarg( $ep['url'] ),
			escapeshellarg( 'Authorization: Bearer ' . $value )
		);
	}

	/**
	 * Manual fallback, in the shape the official command produces.
	 */
	public static function generate_mcp_config(): array {
		$ep = self::http_endpoint();

		return [
			self::root_key() => [
				self::server_key() => [
					'url'     => $ep['url'],
					'type'    => 'http',
					'headers' => $ep['headers'],
				],
			],
		];
	}

	/**
	 * Client behaviour a correctly configured user will otherwise read as a WPCC failure.
	 *
	 * Gemini CLI disables MCP servers in a folder it does not trust, and reports them as
	 * "Disabled" rather than as untrusted. Someone who has just finished the setup sees
	 * their new server switched off and reasonably concludes the connection did not work.
	 * Stated here so the screen can say it before they go looking for a bug in WPCC.
	 *
	 * Deliberately does NOT tell anyone to turn the protection off.
	 */
	public static function post_setup_notes(): array {
		return [
			__( 'Use the native gemini mcp add command above when possible. It is the recommended path because it adds only Action Steward and preserves every existing Gemini setting.', 'action-steward' ),
			__( 'Gemini CLI stores the bearer token inline in ~/.gemini/settings.json for both native and manual setup. Do not commit or share that file, and keep the token-bearing setup command out of terminal history and shared logs. Revoke the token in Action Steward to remove access.', 'action-steward' ),
			__( 'Gemini CLI turns MCP servers off in a folder it has not been told to trust. If it lists Action Steward as “Disabled”, that is the folder’s trust setting, not a failed connection — trust the folder in Gemini CLI and it will come back. Do not turn the trust check off globally.', 'action-steward' ),
		];
	}
}
