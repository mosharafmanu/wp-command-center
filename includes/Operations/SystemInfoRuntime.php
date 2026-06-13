<?php
/**
 * Step 81 — System Info Runtime.
 *
 * Returns site, WordPress, PHP, MySQL, and environment metadata using only
 * native WordPress and PHP APIs. No WP-CLI, exec(), shell_exec(), or
 * proc_open() — works on all hosting environments including managed hosts
 * that restrict shell execution.
 *
 * Risk tier: diagnostic — never gated in any Security Mode.
 */

namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class SystemInfoRuntime {

	public function run( array $params, array $context = [] ): array|\WP_Error {
		global $wpdb;

		// ── WordPress ──────────────────────────────────────────────────
		$site_url    = (string) get_site_url();
		$home_url    = (string) get_home_url();
		$wp_version  = (string) get_bloginfo( 'version' );
		$locale      = (string) get_locale();
		$timezone    = (string) ( function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : get_option( 'timezone_string', 'UTC' ) );
		$is_multisite = is_multisite();
		$debug_mode  = defined( 'WP_DEBUG' ) && WP_DEBUG;
		$env_type    = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';

		// ── Active theme ───────────────────────────────────────────────
		$theme        = wp_get_theme();
		$active_theme = [
			'name'    => $theme->get( 'Name' ),
			'version' => $theme->get( 'Version' ),
			'author'  => wp_strip_all_tags( (string) $theme->get( 'Author' ) ),
			'slug'    => $theme->get_stylesheet(),
		];

		// ── Active plugins ─────────────────────────────────────────────
		$active_slugs         = (array) get_option( 'active_plugins', [] );
		$active_plugins_count = count( $active_slugs );

		// ── PHP ────────────────────────────────────────────────────────
		$php_version  = PHP_VERSION;
		$memory_limit = (string) ini_get( 'memory_limit' );
		$max_exec     = (string) ini_get( 'max_execution_time' );
		$upload_max   = (string) ini_get( 'upload_max_filesize' );

		// ── MySQL ──────────────────────────────────────────────────────
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$mysql_version = (string) ( $wpdb->get_var( 'SELECT VERSION()' ) ?: 'unknown' );

		// ── Shell / WP-CLI availability (informational) ────────────────
		$disabled        = array_filter( array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) ) );
		$proc_open_ok    = function_exists( 'proc_open' ) && ! in_array( 'proc_open', $disabled, true );
		$shell_exec_ok   = function_exists( 'shell_exec' ) && ! in_array( 'shell_exec', $disabled, true );
		$wp_cli_available = ( new WpCliBridge() )->is_available();

		return [
			'site_url'             => $site_url,
			'home_url'             => $home_url,
			'wordpress_version'    => $wp_version,
			'php_version'          => $php_version,
			'mysql_version'        => $mysql_version,
			'active_theme'         => $active_theme,
			'active_plugins_count' => $active_plugins_count,
			'multisite'            => $is_multisite,
			'memory_limit'         => $memory_limit,
			'max_execution_time'   => $max_exec,
			'upload_max_filesize'  => $upload_max,
			'debug_mode'           => $debug_mode,
			'environment_type'     => $env_type,
			'locale'               => $locale,
			'timezone'             => $timezone,
			'shell_capabilities'   => [
				'proc_open_enabled'  => $proc_open_ok,
				'shell_exec_enabled' => $shell_exec_ok,
				'wp_cli_available'   => $wp_cli_available,
			],
		];
	}
}
