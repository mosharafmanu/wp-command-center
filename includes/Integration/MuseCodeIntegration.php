<?php
/**
 * Meta Muse Code MCP Integration.
 *
 * Muse Code is Meta's standalone terminal coding agent, launched by the `muse`
 * executable. It is a host application, distinct from the Muse Spark model/API, and it
 * ships MCP support, so it qualifies for a real integration rather than a provider card.
 *
 * EVIDENCE STANDARD — read this before trusting the shape below.
 *
 * The shape comes from Meta's Muse Code documentation and was verified again with the
 * official Muse Code 1.0.3 binary. An isolated actual `muse` process parsed the settings,
 * authenticated to WPCC, initialized MCP, and requested tools, resources, templates and
 * prompts. The owner subsequently authenticated Muse Code and completed a read-only
 * `system_info` call against the local WPCC site, so the registry now records Pass-level
 * connection evidence while retaining the Experimental product-positioning tier.
 *
 * THREE DETAILS THAT DECIDE WHETHER THIS WORKS AT ALL
 *
 *   1. `mcp_servers`, snake_case — not `mcpServers`. It is JSON, but it does not use the
 *      key every other JSON client here uses. A config with the wrong key is ignored
 *      rather than rejected, which is the failure mode that leaves someone certain the
 *      server is broken.
 *
 *   2. `transport`, not `type`. Meta's configuration page renders an example using
 *      `type`; the MCP page — the one actually about MCP — uses `transport` in every
 *      example, for both stdio and streamable_http. `transport` is used here.
 *
 *   3. `"schema_version": 1` is MANDATORY, and this is the dangerous one. Meta's docs:
 *      a settings.json that omits it "fails every command at startup with `malformed
 *      settings file`". A missing file is fine — defaults apply — but a file that exists
 *      without that key breaks the whole tool, not just MCP. So the generated
 *      whole-file configuration always carries it, and anyone merging into an existing
 *      file is given the entry alone (which cannot disturb the key they already have).
 */

namespace WPCommandCenter\Integration;

defined( 'ABSPATH' ) || exit;

final class MuseCodeIntegration extends BaseClientIntegration {

	protected static string $client_name = 'Muse Code';

	protected static array $config_paths = [
		'macos'   => '~/.config/muse/settings.json',
		'linux'   => '~/.config/muse/settings.json ($XDG_CONFIG_HOME/muse/settings.json)',
	];

	public static function transport(): string {
		return 'http';
	}

	/** Credential travels in a request header; the docs describe no env-var indirection. */
	public static function credential_mode(): string {
		return 'inline';
	}

	/** snake_case, and NOT the `mcpServers` every other JSON client here uses. */
	public static function root_key(): string {
		return 'mcp_servers';
	}

	public static function generate_mcp_config(): array {
		$ep = self::http_endpoint();

		return [
			// Mandatory. Emitted first so it is visible in the copy box rather than
			// buried — someone who deletes it to "tidy up" breaks every muse command.
			'schema_version' => 1,
			self::root_key() => [
				self::server_key() => [
					'transport' => 'streamable_http',
					'url'       => $ep['url'],
					'headers'   => $ep['headers'],
				],
			],
		];
	}

	/** A keyed server entry to merge inside an existing `mcp_servers` object. */
	public static function primary_config( string $token = '' ): string {
		$config = self::generate_mcp_config();
		$entry  = $config[ self::root_key() ][ self::server_key() ] ?? [];
		$json   = sprintf(
			'"%s": %s',
			self::server_key(),
			(string) wp_json_encode( $entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
		);

		return '' !== $token
			? str_replace( AIClientRegistry::TOKEN_PLACEHOLDER, $token, $json )
			: $json;
	}

	/**
	 * Prepare the documented user settings path without replacing an existing file.
	 * The grouped shell expression ensures a failed mkdir cannot fall through to printf.
	 */
	public static function prepare_config_command(): string {
		return 'mkdir -p "$HOME/.config/muse" && { [ -f "$HOME/.config/muse/settings.json" ] || printf \'{}\\n\' > "$HOME/.config/muse/settings.json"; }';
	}

	public static function post_setup_notes(): array {
		return [
			__( 'Keep the "schema_version": 1 line. Muse Code refuses to start with a settings file that is missing it, and the error mentions a malformed settings file rather than anything about this site.', 'action-steward' ),
			__( 'Meta notes that MCP tools run outside Muse Code’s own filesystem and network sandbox. That does not weaken this site’s protection — approvals, scope limits and the change log still apply to everything this token can do — but it does mean Muse Code’s sandbox is not what is protecting your site. Your token’s scope and your approval settings are.', 'action-steward' ),
		];
	}
}
