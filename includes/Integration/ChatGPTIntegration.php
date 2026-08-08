<?php
/**
 * ChatGPT MCP Integration (Desktop).
 *
 * ChatGPT Desktop shares MCP configuration with Codex CLI on the same host, so it reads
 * ~/.codex/config.toml. It has no config file of its own; the plugin previously invented
 * ~/Library/Application Support/ChatGPT/mcp.json, a path nothing reads.
 *
 * ChatGPT on the WEB is remote-only — it accepts an HTTPS MCP endpoint but never a local
 * stdio process — so it requires a publicly reachable HTTPS site. A localhost or private
 * staging install cannot be reached by it at all. That is a property of ChatGPT, not a
 * WPCC defect, and it is why this client is not a candidate for local-dev certification.
 */

namespace WPCommandCenter\Integration;

defined( 'ABSPATH' ) || exit;

final class ChatGPTIntegration extends BaseClientIntegration {

	protected static string $client_name = 'ChatGPT (Desktop)';

	protected static array $config_paths = [
		'macos'   => '~/.codex/config.toml (shared with Codex CLI)',
		'windows' => '%USERPROFILE%\\.codex\\config.toml (shared with Codex CLI)',
		'linux'   => '~/.codex/config.toml (shared with Codex CLI)',
	];

	public static function transport(): string {
		return 'http';
	}

	public static function generate_mcp_config(): array {
		return CodexIntegration::generate_mcp_config();
	}
}
