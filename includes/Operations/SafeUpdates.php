<?php
/**
 * Step 28 — Safe Updates Operation.
 *
 * Safe plugin/theme update operation with health verification.
 *
 * Step 82 hardening:
 * - file.php included so request_filesystem_credentials() is always defined
 * - current_user_can() check removed (capability enforced by OperationExecutor)
 * - WP_Filesystem initialised as a pre-flight check
 * - null result from upgrader no longer silently ignored
 * - Skin error messages extracted and mapped to structured error codes
 * - Dry-run validates filesystem + download URL, not just the transient
 * - All upgrader calls wrapped in try/catch to prevent fatal propagation
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

		// file.php defines request_filesystem_credentials(), required by WP_Upgrader_Skin.
		// Must be included before any Upgrader class is instantiated.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';

		// Pre-flight: initialise WP_Filesystem. In the REST/MCP context this uses
		// Direct access (same-user PHP process) which works on most hosts. If the
		// host requires FTP credentials, catch that here rather than deep inside
		// the upgrader where the error surfaces as a cryptic WP_Error or NULL.
		$fs_error = $this->init_filesystem( 'plugin' === $type ? WP_PLUGIN_DIR : get_theme_root() );
		if ( is_wp_error( $fs_error ) ) {
			return $fs_error;
		}

		if ( 'plugin' === $type ) {
			return $this->update_plugin( $slug, $dry_run );
		}

		return $this->update_theme( $slug, $dry_run );
	}

	// ── Filesystem init ────────────────────────────────────────────────────

	private function init_filesystem( string $target_dir ): true|\WP_Error {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			return new \WP_Error( 'filesystem_not_writable', __( 'WP_Filesystem function unavailable.', 'wp-command-center' ) );
		}

		// request_filesystem_credentials() with false as the URL tries direct
		// access without prompting for FTP credentials — correct for API context.
		$creds = request_filesystem_credentials( '', '', false, false, null );

		if ( false === $creds ) {
			return new \WP_Error(
				'wp_filesystem_credentials_required',
				__( 'WP Filesystem requires FTP/SSH credentials. Set FS_METHOD=\'direct\' in wp-config.php or provide credentials.', 'wp-command-center' )
			);
		}

		if ( ! WP_Filesystem( $creds ) ) {
			return new \WP_Error(
				'filesystem_not_writable',
				__( 'Could not initialise WP Filesystem. Check file permissions.', 'wp-command-center' )
			);
		}

		if ( $wp_filesystem && ! $wp_filesystem->is_writable( $target_dir ) ) {
			return new \WP_Error(
				'filesystem_not_writable',
				sprintf(
					/* translators: %s: directory path */
					__( 'Directory is not writable: %s', 'wp-command-center' ),
					$target_dir
				)
			);
		}

		return true;
	}

	// ── Plugin update ──────────────────────────────────────────────────────

	private function update_plugin( string $slug, bool $dry_run ): array|\WP_Error {
		wp_update_plugins();
		$current = get_site_transient( 'update_plugins' );
		$plugins = get_plugins();

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

		$after_version  = $current->response[ $plugin_file ]->new_version ?? 'unknown';
		$download_url   = $current->response[ $plugin_file ]->package ?? '';

		if ( $dry_run ) {
			return $this->dry_run_preflight( 'plugin', $plugin_file, $before_version, $after_version, $download_url );
		}

		if ( ! class_exists( 'ZipArchive' ) && ! function_exists( 'unzip_file' ) ) {
			return new \WP_Error( 'zip_validation_failed', __( 'PHP ZipArchive extension is required for plugin updates.', 'wp-command-center' ) );
		}

		// Capture active state before upgrade.
		// Plugin_Upgrader::deactivate_plugin_before_upgrade() removes the plugin
		// from active_plugins in non-cron context; active_after() is a no-op
		// outside wp_doing_cron(), so reactivation must be explicit.
		$was_active = is_plugin_active( $plugin_file );

		$skin = new \WP_Ajax_Upgrader_Skin();
		try {
			$upgrader = new \Plugin_Upgrader( $skin );
			$result   = $upgrader->upgrade( $plugin_file );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'plugin_upgrade_failed', $e->getMessage() );
		}

		if ( is_wp_error( $result ) ) {
			return new \WP_Error( $this->classify_wp_error( $result ), $result->get_error_message() );
		}

		if ( null === $result || false === $result ) {
			return $this->error_from_skin( $skin, 'plugin_upgrade_failed' );
		}

		if ( $was_active ) {
			activate_plugin( $plugin_file, '', false, true );
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
			'reactivated'    => $was_active,
		];
	}

	// ── Theme update ───────────────────────────────────────────────────────

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
		$download_url  = $current->response[ $slug ]['package'] ?? '';

		if ( $dry_run ) {
			return $this->dry_run_preflight( 'theme', $slug, $before_version, $after_version, $download_url );
		}

		if ( ! class_exists( 'ZipArchive' ) && ! function_exists( 'unzip_file' ) ) {
			return new \WP_Error( 'zip_validation_failed', __( 'PHP ZipArchive extension is required for theme updates.', 'wp-command-center' ) );
		}

		$skin = new \WP_Ajax_Upgrader_Skin();
		try {
			$upgrader = new \Theme_Upgrader( $skin );
			$result   = $upgrader->upgrade( $slug );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'plugin_upgrade_failed', $e->getMessage() );
		}

		if ( is_wp_error( $result ) ) {
			return new \WP_Error( $this->classify_wp_error( $result ), $result->get_error_message() );
		}

		if ( null === $result || false === $result ) {
			return $this->error_from_skin( $skin, 'plugin_upgrade_failed' );
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

	// ── Dry-run preflight ──────────────────────────────────────────────────

	/**
	 * Dry-run now validates the same execution path as the live update (minus
	 * file writes): filesystem writability, PHP zip availability, download URL
	 * reachability. If any check fails, the dry-run returns the structured
	 * error code that the live update would have returned.
	 */
	private function dry_run_preflight(
		string $type,
		string $slug,
		string $before_version,
		string $after_version,
		string $download_url
	): array|\WP_Error {
		// Zip extension (same check as live)
		if ( ! class_exists( 'ZipArchive' ) && ! function_exists( 'unzip_file' ) ) {
			return new \WP_Error( 'zip_validation_failed', __( 'PHP ZipArchive extension is required for updates.', 'wp-command-center' ) );
		}

		// Download URL reachability (HEAD request, no file download)
		if ( ! empty( $download_url ) ) {
			$head = wp_remote_head( $download_url, [ 'timeout' => 8, 'redirection' => 3 ] );
			if ( is_wp_error( $head ) ) {
				return new \WP_Error(
					'download_failed',
					sprintf( __( 'Dry-run: update package unreachable: %s', 'wp-command-center' ), $head->get_error_message() )
				);
			}
			$status = wp_remote_retrieve_response_code( $head );
			if ( $status >= 400 ) {
				$code = ( 401 === $status || 403 === $status ) ? 'license_missing' : 'download_failed';
				return new \WP_Error(
					$code,
					sprintf(
						/* translators: 1: HTTP status code, 2: download URL */
						__( 'Dry-run: update server returned HTTP %1$d for package URL. %2$s', 'wp-command-center' ),
						$status,
						$download_url
					)
				);
			}
		}

		return [
			'type'           => $type,
			'slug'           => $slug,
			'dry_run'        => true,
			'before_version' => $before_version,
			'after_version'  => $after_version,
			'health_status'  => 'skipped',
			'preflight'      => [
				'filesystem'   => 'writable',
				'zip'          => class_exists( 'ZipArchive' ) ? 'ZipArchive' : 'unzip_file',
				'download_url' => empty( $download_url ) ? 'not_provided' : 'reachable',
			],
		];
	}

	// ── Error helpers ──────────────────────────────────────────────────────

	/**
	 * Extract the first skin message and map it to a structured error code.
	 * Called when the upgrader returns null or false (no WP_Error raised).
	 */
	private function error_from_skin( \WP_Ajax_Upgrader_Skin $skin, string $fallback_code ): \WP_Error {
		$messages = method_exists( $skin, 'get_upgrade_messages' ) ? $skin->get_upgrade_messages() : [];
		$message  = ! empty( $messages ) ? implode( ' ', array_map( 'wp_strip_all_tags', $messages ) ) : '';
		$code     = $this->classify_message( $message ) ?: $fallback_code;
		$text     = $message ?: __( 'Update failed with no specific error message. Check file permissions and disk space.', 'wp-command-center' );
		return new \WP_Error( $code, $text );
	}

	/**
	 * Map a WP_Error from the upgrader to a structured WPCC error code.
	 * Preserves the original WP error message for context.
	 */
	private function classify_wp_error( \WP_Error $error ): string {
		return $this->classify_message( $error->get_error_message() )
			?: $this->classify_code( $error->get_error_code() );
	}

	private function classify_message( string $message ): string {
		$message = strtolower( $message );

		// Shell/exec check before zip because "extract via shell" must not match zip.
		if ( str_contains( $message, 'shell' ) || str_contains( $message, 'exec' ) ) {
			return 'shell_execution_unavailable';
		}
		if ( str_contains( $message, 'ftp' ) || str_contains( $message, 'ssh' ) || str_contains( $message, 'credential' ) ) {
			return 'wp_filesystem_credentials_required';
		}
		if ( str_contains( $message, 'license' ) || str_contains( $message, '401' ) || str_contains( $message, '403' ) ) {
			return 'license_missing';
		}
		if ( str_contains( $message, 'package' ) || str_contains( $message, 'download' ) ) {
			return 'download_failed';
		}
		if ( str_contains( $message, 'zip' ) || str_contains( $message, 'unzip' ) || str_contains( $message, 'extract' ) ) {
			return 'zip_validation_failed';
		}
		if ( str_contains( $message, 'writable' ) || str_contains( $message, 'permission' ) || str_contains( $message, 'copy' ) || str_contains( $message, 'write' ) ) {
			return 'filesystem_not_writable';
		}

		return '';
	}

	private function classify_code( string $code ): string {
		return match ( $code ) {
			'fs_unavailable', 'fs_error'       => 'filesystem_not_writable',
			'download_failed', 'http_request_failed' => 'download_failed',
			'incompatible_archive', 'bad_request'    => 'zip_validation_failed',
			default                                   => 'unknown_update_failure',
		};
	}

	// ── Health check ───────────────────────────────────────────────────────

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
