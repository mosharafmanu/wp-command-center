<?php
/**
 * ChatGPT Desktop MCP Integration.
 *
 * ChatGPT Desktop shares MCP configuration with Codex CLI on the same host, so it reads
 * ~/.codex/config.toml. It has no config file of its own; the plugin previously invented
 * ~/Library/Application Support/ChatGPT/mcp.json, a path nothing reads.
 *
 * Verified 2026-08-11 on ChatGPT.app 26.803.41515 (macOS): the app bundles a full `codex`
 * binary at Contents/Resources/codex and a "Codex Framework" — its Settings -> Plugins ->
 * MCPs panel is a GUI editor over ~/.codex/config.toml, and servers added there appear as
 * [mcp_servers.*] blocks in that file and in `codex mcp list`.
 *
 * WHICH SURFACE THIS COVERS. The MCP tools reach the CODEX surface of the desktop app —
 * Codex threads. An ordinary ChatGPT conversation resolves its connectors server-side in
 * the user's OpenAI account and never reads this file, which is also why ChatGPT on the
 * WEB is remote-only: it needs a publicly reachable HTTPS endpoint and cannot see a
 * localhost or private staging install at all. That is a property of the client, not a
 * WPCC defect.
 *
 * The configuration and registration are shared with Codex CLI. Credential bootstrap
 * is independent: a Dock/Finder-launched app needs the launchd session environment.
 */

namespace WPCommandCenter\Integration;

defined( 'ABSPATH' ) || exit;

final class ChatGPTIntegration extends BaseClientIntegration {

	protected static string $client_name = 'Codex in ChatGPT Desktop';

	protected static array $config_paths = [
		'macos'   => '~/.codex/config.toml (shared with Codex CLI)',
		'windows' => '%USERPROFILE%\\.codex\\config.toml (shared with Codex CLI)',
		'linux'   => '~/.codex/config.toml (shared with Codex CLI)',
	];

	public static function transport(): string {
		return 'http';
	}

	/**
	 * Same trap as Codex, and the one this finding came from: the desktop app's MCP form
	 * labels its credential field "Bearer token env var" and means the NAME of an
	 * environment variable. A real token pasted there yields 401 from a healthy server.
	 */
	public static function credential_mode(): string {
		return 'env_var';
	}

	public static function setup_command( string $token = '' ): string {
		return CodexIntegration::setup_command( $token );
	}

	public static function credential_commands( string $token = '' ): array {
		$value = '' !== $token ? $token : AIClientRegistry::TOKEN_PLACEHOLDER;
		$var   = self::credential_env_var();

		return [
			'macos'   => sprintf( 'launchctl setenv %s %s', $var, escapeshellarg( $value ) ),
			'linux'   => sprintf( 'export %s=%s', $var, escapeshellarg( $value ) ),
			'windows' => sprintf( 'setx %s "%s"', $var, $value ),
		];
	}

	public static function generate_mcp_config(): array {
		return CodexIntegration::generate_mcp_config();
	}
}
