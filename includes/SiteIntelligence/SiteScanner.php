<?php
/**
 * Layer 1 — Site Intelligence Engine. Collects WP/PHP versions, active
 * theme/plugins, WooCommerce status, cache configuration, server
 * capabilities, debug status, and file permissions (§7, §8.1).
 */

namespace WPCommandCenter\SiteIntelligence;

defined( 'ABSPATH' ) || exit;

final class SiteScanner {

	private const CACHE_KEY = 'wpcc_site_intelligence_scan';
	private const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Run (or return a cached) Site Intelligence scan.
	 */
	public function scan( bool $force_refresh = false ): array {
		if ( ! $force_refresh ) {
			$cached = get_transient( self::CACHE_KEY );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$data = [
			'generated_at'     => time(),
			'wordpress'        => $this->get_wordpress_info(),
			'php'              => $this->get_php_info(),
			'theme'            => $this->get_theme_info(),
			'plugins'          => $this->get_active_plugins(),
			'woocommerce'      => $this->get_woocommerce_info(),
			'cache'            => $this->get_cache_info(),
			'server'           => $this->get_server_info(),
			'debug'            => $this->get_debug_info(),
			'file_permissions' => $this->get_file_permissions(),
		];

		set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );

		return $data;
	}

	private function get_wordpress_info(): array {
		global $wp_version;

		return [
			'version'             => $wp_version,
			'site_url'            => get_site_url(),
			'home_url'            => get_home_url(),
			'is_multisite'        => is_multisite(),
			'locale'              => get_locale(),
			'timezone'            => wp_timezone_string(),
			'permalink_structure' => get_option( 'permalink_structure' ) ?: __( 'Plain', 'wp-command-center' ),
			'is_ssl'              => is_ssl(),
		];
	}

	private function get_php_info(): array {
		$extensions = [ 'curl', 'mbstring', 'gd', 'imagick', 'zip', 'xml', 'json', 'mysqli', 'opcache', 'intl' ];

		$loaded  = [];
		$missing = [];

		foreach ( $extensions as $extension ) {
			if ( extension_loaded( $extension ) ) {
				$loaded[] = $extension;
			} else {
				$missing[] = $extension;
			}
		}

		return [
			'version'             => PHP_VERSION,
			'memory_limit'        => ini_get( 'memory_limit' ),
			'max_execution_time'  => ini_get( 'max_execution_time' ),
			'upload_max_filesize' => ini_get( 'upload_max_filesize' ),
			'post_max_size'       => ini_get( 'post_max_size' ),
			'loaded_extensions'   => $loaded,
			'missing_extensions'  => $missing,
		];
	}

	private function get_theme_info(): array {
		$theme = wp_get_theme();

		$info = [
			'name'           => $theme->get( 'Name' ),
			'version'        => $theme->get( 'Version' ),
			'author'         => wp_strip_all_tags( $theme->get( 'Author' ) ),
			'template'       => $theme->get_template(),
			'stylesheet'     => $theme->get_stylesheet(),
			'is_child_theme' => is_child_theme(),
			'parent'         => null,
		];

		if ( $info['is_child_theme'] ) {
			$parent = $theme->parent();

			if ( $parent ) {
				$info['parent'] = [
					'name'    => $parent->get( 'Name' ),
					'version' => $parent->get( 'Version' ),
				];
			}
		}

		return $info;
	}

	private function get_active_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins    = get_plugins();
		$active_plugins = (array) get_option( 'active_plugins', [] );

		if ( is_multisite() ) {
			$network_active = array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) );
			$active_plugins = array_unique( array_merge( $active_plugins, $network_active ) );
		}

		$plugins = [];

		foreach ( $active_plugins as $plugin_file ) {
			if ( ! isset( $all_plugins[ $plugin_file ] ) ) {
				continue;
			}

			$plugin_data = $all_plugins[ $plugin_file ];

			$plugins[] = [
				'name'    => $plugin_data['Name'],
				'version' => $plugin_data['Version'],
				'author'  => wp_strip_all_tags( $plugin_data['Author'] ),
				'file'    => $plugin_file,
			];
		}

		return $plugins;
	}

	private function get_woocommerce_info(): array {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return [ 'active' => false ];
		}

		return [
			'active'        => true,
			'version'       => defined( 'WC_VERSION' ) ? WC_VERSION : '',
			'currency'      => get_option( 'woocommerce_currency' ),
			'base_location' => get_option( 'woocommerce_default_country' ),
		];
	}

	private function get_cache_info(): array {
		return [
			'object_cache_enabled' => (bool) wp_using_ext_object_cache(),
			'object_cache_dropin'  => file_exists( WP_CONTENT_DIR . '/object-cache.php' ),
			'page_cache_dropin'    => file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ),
			'opcache_enabled'      => function_exists( 'opcache_get_status' ) && (bool) ini_get( 'opcache.enable' ),
			'caching_plugins'      => $this->detect_caching_plugins(),
		];
	}

	private function detect_caching_plugins(): array {
		$known = [
			'wp-rocket/wp-rocket.php'             => 'WP Rocket',
			'w3-total-cache/w3-total-cache.php'   => 'W3 Total Cache',
			'wp-super-cache/wp-cache.php'         => 'WP Super Cache',
			'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
			'wp-fastest-cache/wpFastestCache.php' => 'WP Fastest Cache',
			'sg-cachepress/sg-cachepress.php'     => 'SiteGround Optimizer',
			'autoptimize/autoptimize.php'         => 'Autoptimize',
		];

		$active_plugins = (array) get_option( 'active_plugins', [] );
		$found          = [];

		foreach ( $known as $plugin_file => $name ) {
			if ( in_array( $plugin_file, $active_plugins, true ) ) {
				$found[] = $name;
			}
		}

		return $found;
	}

	private function get_server_info(): array {
		$disabled_functions = array_filter( array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) ) );

		$shell_exec_enabled = function_exists( 'shell_exec' ) && ! in_array( 'shell_exec', $disabled_functions, true );
		$proc_open_enabled  = function_exists( 'proc_open' ) && ! in_array( 'proc_open', $disabled_functions, true );

		return [
			'software'           => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
			'os'                 => PHP_OS,
			'shell_exec_enabled' => $shell_exec_enabled,
			'proc_open_enabled'  => $proc_open_enabled,
			'wp_cli_available'   => $this->detect_wp_cli( $shell_exec_enabled ),
			'disabled_functions' => array_values( $disabled_functions ),
		];
	}

	/**
	 * Best-effort, read-only probe for a WP-CLI binary on PATH (§9).
	 */
	private function detect_wp_cli( bool $shell_exec_enabled ): bool {
		if ( ! $shell_exec_enabled ) {
			return false;
		}

		$output = @shell_exec( 'wp --version 2>/dev/null' );

		return ! empty( $output ) && stripos( (string) $output, 'wp-cli' ) !== false;
	}

	private function get_debug_info(): array {
		$log_file = WP_CONTENT_DIR . '/debug.log';
		$exists   = file_exists( $log_file );

		return [
			'wp_debug'         => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'wp_debug_log'     => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
			'wp_debug_display' => defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY,
			'script_debug'     => defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG,
			'log_exists'       => $exists,
			'log_size'         => $exists ? filesize( $log_file ) : 0,
		];
	}

	private function get_file_permissions(): array {
		$upload_dir = wp_upload_dir();

		$paths = [
			'wp-config.php' => ABSPATH . 'wp-config.php',
			'.htaccess'     => ABSPATH . '.htaccess',
			'wp-content'    => WP_CONTENT_DIR,
			'uploads'       => $upload_dir['basedir'],
			'plugins'       => WP_PLUGIN_DIR,
			'themes'        => get_theme_root(),
		];

		$result = [];

		foreach ( $paths as $label => $path ) {
			if ( ! file_exists( $path ) ) {
				$result[ $label ] = [
					'exists'      => false,
					'permissions' => '',
					'writable'    => false,
				];

				continue;
			}

			$result[ $label ] = [
				'exists'      => true,
				'permissions' => substr( sprintf( '%o', fileperms( $path ) ), -4 ),
				'writable'    => is_writable( $path ),
			];
		}

		return $result;
	}
}
