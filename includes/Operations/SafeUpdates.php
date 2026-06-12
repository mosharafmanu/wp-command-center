<?php
/**
 * Step 28 — Safe Updates Operation.
 *
 * Safe plugin/theme update operation with health verification.
 */

namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class SafeUpdates {

	/**
	 * Run the safe updates operation.
	 *
	 * @param array{
	 *     type: string,
	 *     slug: string,
	 *     dry_run?: bool
	 * } $params
	 * @param array $context
	 *
	 * @return array|\WP_Error Result summary or error.
	 */
	public function run( array $params, array $context = [] ): array|\WP_Error {
		$type    = sanitize_key( $params['type'] ?? '' );
		$slug    = sanitize_text_field( $params['slug'] ?? '' );
		$dry_run = filter_var( $params['dry_run'] ?? true, FILTER_VALIDATE_BOOLEAN );

		if ( empty( $type ) || ! in_array( $type, [ 'plugin', 'theme' ], true ) ) {
			return new \WP_Error( 'wpcc_invalid_update_type', __( 'Invalid update type. Supported: plugin, theme.', 'wp-command-center' ) );
		}

		if ( empty( $slug ) ) {
			return new \WP_Error( 'wpcc_missing_slug', __( 'Plugin or theme slug is required.', 'wp-command-center' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';

		if ( 'plugin' === $type ) {
			return $this->update_plugin( $slug, $dry_run );
		}

		return $this->update_theme( $slug, $dry_run );
	}

	private function update_plugin( string $slug, bool $dry_run ): array|\WP_Error {
		wp_update_plugins();
		$current = get_site_transient( 'update_plugins' );
		$plugins = get_plugins();

		// Try to match slug to a plugin file if the user just provided the directory slug
		$plugin_file = $slug;
		if ( ! isset( $plugins[ $plugin_file ] ) ) {
			foreach ( $plugins as $file => $data ) {
				if ( str_starts_with( $file, $slug . '/' ) || $file === $slug . '.php' ) {
					$plugin_file = $file;
					break;
				}
			}
		}

		if ( ! isset( $plugins[ $plugin_file ] ) ) {
			return new \WP_Error( 'wpcc_plugin_not_found', __( 'Plugin not found.', 'wp-command-center' ) );
		}

		$before_version = $plugins[ $plugin_file ]['Version'] ?? 'unknown';

		if ( ! isset( $current->response[ $plugin_file ] ) ) {
			return new \WP_Error( 'wpcc_no_update_available', __( 'No update available for this plugin.', 'wp-command-center' ) );
		}

		$after_version = $current->response[ $plugin_file ]->new_version ?? 'unknown';

		if ( $dry_run ) {
			return [
				'type'           => 'plugin',
				'slug'           => $plugin_file,
				'dry_run'        => true,
				'before_version' => $before_version,
				'after_version'  => $after_version,
				'health_status'  => 'skipped',
			];
		}

		if ( ! current_user_can( 'update_plugins' ) ) {
			return new \WP_Error( 'wpcc_insufficient_permissions', __( 'You do not have permission to update plugins.', 'wp-command-center' ) );
		}

		$skin     = new \Automatic_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( $plugin_file );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( false === $result ) {
			return new \WP_Error( 'wpcc_update_failed', __( 'Plugin update failed.', 'wp-command-center' ) );
		}

		$health = $this->run_health_check();
		if ( is_wp_error( $health ) ) {
			return new \WP_Error( 'wpcc_health_check_failed', sprintf( __( 'Update succeeded, but health check failed: %s. Rollback recommended.', 'wp-command-center' ), $health->get_error_message() ) );
		}

		return [
			'type'           => 'plugin',
			'slug'           => $plugin_file,
			'dry_run'        => false,
			'before_version' => $before_version,
			'after_version'  => $after_version,
			'health_status'  => 'passed',
		];
	}

	private function update_theme( string $slug, bool $dry_run ): array|\WP_Error {
		wp_update_themes();
		$current = get_site_transient( 'update_themes' );
		$theme   = wp_get_theme( $slug );

		if ( ! $theme->exists() ) {
			return new \WP_Error( 'wpcc_theme_not_found', __( 'Theme not found.', 'wp-command-center' ) );
		}

		$before_version = $theme->get( 'Version' ) ?: 'unknown';

		if ( ! isset( $current->response[ $slug ] ) ) {
			return new \WP_Error( 'wpcc_no_update_available', __( 'No update available for this theme.', 'wp-command-center' ) );
		}

		$after_version = $current->response[ $slug ]['new_version'] ?? 'unknown';

		if ( $dry_run ) {
			return [
				'type'           => 'theme',
				'slug'           => $slug,
				'dry_run'        => true,
				'before_version' => $before_version,
				'after_version'  => $after_version,
				'health_status'  => 'skipped',
			];
		}

		if ( ! current_user_can( 'update_themes' ) ) {
			return new \WP_Error( 'wpcc_insufficient_permissions', __( 'You do not have permission to update themes.', 'wp-command-center' ) );
		}

		$skin     = new \Automatic_Upgrader_Skin();
		$upgrader = new \Theme_Upgrader( $skin );
		$result   = $upgrader->upgrade( $slug );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( false === $result ) {
			return new \WP_Error( 'wpcc_update_failed', __( 'Theme update failed.', 'wp-command-center' ) );
		}

		$health = $this->run_health_check();
		if ( is_wp_error( $health ) ) {
			return new \WP_Error( 'wpcc_health_check_failed', sprintf( __( 'Update succeeded, but health check failed: %s. Rollback recommended.', 'wp-command-center' ), $health->get_error_message() ) );
		}

		return [
			'type'           => 'theme',
			'slug'           => $slug,
			'dry_run'        => false,
			'before_version' => $before_version,
			'after_version'  => $after_version,
			'health_status'  => 'passed',
		];
	}

	private function run_health_check(): bool|\WP_Error {
		$response = wp_remote_get( home_url(), [ 'timeout' => 10 ] );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'wpcc_loopback_failed', __( 'Loopback check failed: ', 'wp-command-center' ) . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 500 ) {
			return new \WP_Error( 'wpcc_fatal_error', sprintf( __( 'Site returned a %d error after update.', 'wp-command-center' ), $code ) );
		}

		return true;
	}
}
