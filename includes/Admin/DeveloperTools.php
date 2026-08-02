<?php
/**
 * V1 refinement — the developer-tools disclosure gate.
 *
 * Some WP Command Center surfaces are genuinely developer tools: a file browser,
 * the patch review/apply UI, and the search-and-replace runner. They are real,
 * they work, and they stay — but they are the wrong first impression for a site
 * owner who installed this to connect Claude to their blog, and a file manager
 * sitting in the default admin UI is exactly what the WordPress.org plugin
 * guidelines single out as a review risk.
 *
 * So they are HIDDEN, not removed, and the hiding is UI-only:
 *
 *   • The operations themselves (`patch_manage`, `file_manage`, `code_search`,
 *     `safe_search_replace`, `wp_cli_bridge`) are untouched and remain fully
 *     available over REST and MCP under the same capability scoping and
 *     approval policy as before. No capability is lost by this gate.
 *   • Only the admin *screens* are gated, and only by default.
 *
 * Turn them on for a site by defining the constant in wp-config.php:
 *
 *     define( 'WPCC_DEVELOPER_TOOLS', true );
 *
 * or programmatically via the `wpcc_developer_tools` filter. Development mode
 * (the security mode that runs changes without approval) also implies them:
 * a site already declared a development site has nothing to gain from the
 * hiding, and it keeps the developer workflow one setting away rather than two.
 */

namespace WPCommandCenter\Admin;

use WPCommandCenter\Operations\SecurityModeManager;

defined( 'ABSPATH' ) || exit;

final class DeveloperTools {

	public const CONSTANT = 'WPCC_DEVELOPER_TOOLS';
	public const FILTER   = 'wpcc_developer_tools';

	/**
	 * Whether developer-only admin surfaces should be shown.
	 *
	 * Precedence mirrors AppShell::flag(): a DEFINED constant is site
	 * configuration and wins outright (on or off), so a site can force these
	 * OFF even in development mode. Otherwise a truthy filter opts in.
	 * Failing closed is the default.
	 */
	public static function enabled(): bool {
		if ( defined( self::CONSTANT ) ) {
			return (bool) constant( self::CONSTANT );
		}

		/**
		 * Filter whether developer-only admin surfaces (file browser, patches,
		 * search & replace) are visible.
		 *
		 * @param bool $enabled Default: true only in development security mode.
		 */
		return (bool) apply_filters(
			self::FILTER,
			SecurityModeManager::MODE_DEVELOPER === SecurityModeManager::current()
		);
	}
}
