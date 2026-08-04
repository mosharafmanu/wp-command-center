<?php
/**
 * Claude Code (CLI) MCP Integration.
 *
 * Distinct from Claude Desktop: same vendor, different client, different setup. Claude
 * Code is registered with a single `claude mcp add` command rather than a hand-edited
 * config file, so the useful thing to hand a user is the command, not JSON.
 *
 * Highest satisfaction of any assistant measured in 2026 (46% "most loved", JetBrains
 * April 2026) and 18% workplace adoption, yet the plugin only ever offered Claude Desktop.
 */

namespace WPCommandCenter\Integration;

defined( 'ABSPATH' ) || exit;

final class ClaudeCodeIntegration extends BaseClientIntegration {

	protected static string $client_name = 'Claude Code';

	protected static array $config_paths = [
		'macos'   => 'registered via `claude mcp add` (no file to edit)',
		'windows' => 'registered via `claude mcp add` (no file to edit)',
		'linux'   => 'registered via `claude mcp add` (no file to edit)',
	];

	public static function transport(): string {
		return 'http';
	}

	/**
	 * The URL is a POSITIONAL argument, not an option.
	 *
	 *   claude mcp add [options] <name> <commandOrUrl> [args...]
	 *
	 * An earlier draft emitted `--url <url>`, which `claude mcp add` rejects as an
	 * unknown option — the command we handed people could not have run. Verified
	 * against `claude mcp add --help`, whose own example is:
	 *
	 *   claude mcp add --transport http sentry https://mcp.sentry.dev/mcp
	 *
	 * `--transport http` is placed before the name so it reads as that documented
	 * example rather than as something invented here.
	 */
	public static function generate_mcp_config(): array {
		$ep = self::http_endpoint();

		return [
			'__format' => 'shell',
			'__raw'    => sprintf(
				"claude mcp add --transport http %s %s \\\n  --header \"Authorization: Bearer %s\"\n",
				self::server_key(),
				$ep['url'],
				'${WPCC_TOKEN}'
			),
		];
	}
}
