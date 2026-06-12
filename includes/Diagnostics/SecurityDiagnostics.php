<?php
/**
 * Layer 2 — security diagnostics: file permissions, debug status,
 * and configuration checks, built on top of the Site Intelligence
 * snapshot (Layer 1).
 */

namespace WPCommandCenter\Diagnostics;

use WPCommandCenter\SiteIntelligence\SiteScanner;

defined( 'ABSPATH' ) || exit;

final class SecurityDiagnostics extends AbstractDiagnostics {

	public function analyze( ?array $site_data = null ): array {
		$site_data ??= ( new SiteScanner() )->scan();

		return [
			$this->check_debug_display( $site_data['debug'] ),
			$this->check_file_edit(),
			$this->check_wp_config_permissions( $site_data['file_permissions'] ),
			$this->check_ssl( $site_data['wordpress'] ),
			$this->check_default_admin_account(),
			$this->check_directory_listing(),
			$this->check_core_update(),
		];
	}

	private function check_debug_display( array $debug ): array {
		$enabled = $debug['wp_debug_display'];

		return $this->check(
			'wp_debug_display',
			__( 'Debug Display', 'wp-command-center' ),
			$enabled ? self::STATUS_CRITICAL : self::STATUS_GOOD,
			$enabled
				? __( 'WP_DEBUG_DISPLAY is enabled — PHP errors may be shown to site visitors, leaking file paths and code details.', 'wp-command-center' )
				: __( 'PHP errors are not displayed to visitors.', 'wp-command-center' )
		);
	}

	private function check_file_edit(): array {
		$disabled = defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT;

		return $this->check(
			'file_edit',
			__( 'Theme/Plugin File Editor', 'wp-command-center' ),
			$disabled ? self::STATUS_GOOD : self::STATUS_RECOMMENDED,
			$disabled
				? __( 'The built-in theme and plugin file editor is disabled.', 'wp-command-center' )
				: __( 'The built-in theme/plugin file editor is enabled. Consider setting DISALLOW_FILE_EDIT to true.', 'wp-command-center' )
		);
	}

	private function check_wp_config_permissions( array $file_permissions ): array {
		$info = $file_permissions['wp-config.php'];

		if ( ! $info['exists'] ) {
			return $this->check(
				'wp_config_permissions',
				__( 'wp-config.php Permissions', 'wp-command-center' ),
				self::STATUS_INFO,
				__( 'wp-config.php was not found at the expected location.', 'wp-command-center' )
			);
		}

		$world_writable = in_array( substr( $info['permissions'], -1 ), [ '2', '3', '6', '7' ], true );

		return $this->check(
			'wp_config_permissions',
			__( 'wp-config.php Permissions', 'wp-command-center' ),
			$world_writable ? self::STATUS_CRITICAL : self::STATUS_GOOD,
			$world_writable
				? sprintf(
					/* translators: %s: file permission octal value */
					__( 'wp-config.php is world-writable (%s). Restrict permissions to 600 or 644.', 'wp-command-center' ),
					$info['permissions']
				)
				: sprintf(
					/* translators: %s: file permission octal value */
					__( 'wp-config.php permissions look reasonable (%s).', 'wp-command-center' ),
					$info['permissions']
				)
		);
	}

	private function check_ssl( array $wordpress ): array {
		$enabled = $wordpress['is_ssl'];

		return $this->check(
			'ssl',
			__( 'SSL (HTTPS)', 'wp-command-center' ),
			$enabled ? self::STATUS_GOOD : self::STATUS_RECOMMENDED,
			$enabled
				? __( 'The site is being served over HTTPS.', 'wp-command-center' )
				: __( 'The site is not using HTTPS. An SSL certificate is strongly recommended.', 'wp-command-center' )
		);
	}

	private function check_default_admin_account(): array {
		$user = get_user_by( 'login', 'admin' );

		if ( ! $user ) {
			return $this->check(
				'default_admin_account',
				__( 'Default "admin" Account', 'wp-command-center' ),
				self::STATUS_GOOD,
				__( 'No user with the username "admin" exists.', 'wp-command-center' )
			);
		}

		$is_administrator = in_array( 'administrator', (array) $user->roles, true );

		return $this->check(
			'default_admin_account',
			__( 'Default "admin" Account', 'wp-command-center' ),
			$is_administrator ? self::STATUS_RECOMMENDED : self::STATUS_INFO,
			$is_administrator
				? __( 'A user with the username "admin" has the Administrator role — a common brute-force target. Consider renaming or removing it.', 'wp-command-center' )
				: __( 'A user with the username "admin" exists but is not an administrator.', 'wp-command-center' )
		);
	}

	private function check_directory_listing(): array {
		$upload_dir = wp_upload_dir();
		$basedir    = trailingslashit( $upload_dir['basedir'] );
		$protected  = file_exists( $basedir . 'index.php' ) || file_exists( $basedir . 'index.html' );

		return $this->check(
			'directory_listing',
			__( 'Uploads Directory Listing', 'wp-command-center' ),
			$protected ? self::STATUS_GOOD : self::STATUS_RECOMMENDED,
			$protected
				? __( 'The uploads directory contains an index file that prevents directory listing.', 'wp-command-center' )
				: __( 'The uploads directory has no index.php/index.html — directory listing may be possible if the server allows it.', 'wp-command-center' )
		);
	}

	private function check_core_update(): array {
		if ( ! function_exists( 'get_core_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		$updates = get_core_updates();

		if ( ! is_array( $updates ) || empty( $updates ) || ! isset( $updates[0]->response ) ) {
			return $this->check(
				'core_update',
				__( 'WordPress Core Updates', 'wp-command-center' ),
				self::STATUS_INFO,
				__( 'Update status is unknown — WordPress has not checked for updates yet.', 'wp-command-center' )
			);
		}

		$latest = $updates[0];

		if ( 'latest' === $latest->response || 'development' === $latest->response ) {
			return $this->check(
				'core_update',
				__( 'WordPress Core Updates', 'wp-command-center' ),
				self::STATUS_GOOD,
				__( 'WordPress is up to date.', 'wp-command-center' )
			);
		}

		return $this->check(
			'core_update',
			__( 'WordPress Core Updates', 'wp-command-center' ),
			self::STATUS_RECOMMENDED,
			sprintf(
				/* translators: %s: available WordPress version */
				__( 'A WordPress core update is available (%s).', 'wp-command-center' ),
				$latest->version ?? ''
			)
		);
	}
}
