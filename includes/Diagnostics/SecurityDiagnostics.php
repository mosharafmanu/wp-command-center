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
			__( 'Debug Display', 'action-steward' ),
			$enabled ? self::STATUS_CRITICAL : self::STATUS_GOOD,
			$enabled
				? __( 'WP_DEBUG_DISPLAY is enabled — PHP errors may be shown to site visitors, leaking file paths and code details.', 'action-steward' )
				: __( 'PHP errors are not displayed to visitors.', 'action-steward' )
		);
	}

	private function check_file_edit(): array {
		$disabled = defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT;

		return $this->check(
			'file_edit',
			__( 'Theme/Plugin File Editor', 'action-steward' ),
			$disabled ? self::STATUS_GOOD : self::STATUS_RECOMMENDED,
			$disabled
				? __( 'The built-in theme and plugin file editor is disabled.', 'action-steward' )
				: __( 'The built-in theme/plugin file editor is enabled. Consider setting DISALLOW_FILE_EDIT to true.', 'action-steward' )
		);
	}

	private function check_wp_config_permissions( array $file_permissions ): array {
		$info = $file_permissions['wp-config.php'];

		if ( ! $info['exists'] ) {
			return $this->check(
				'wp_config_permissions',
				__( 'wp-config.php Permissions', 'action-steward' ),
				self::STATUS_INFO,
				__( 'wp-config.php was not found at the expected location.', 'action-steward' )
			);
		}

		$world_writable = in_array( substr( $info['permissions'], -1 ), [ '2', '3', '6', '7' ], true );

		return $this->check(
			'wp_config_permissions',
			__( 'wp-config.php Permissions', 'action-steward' ),
			$world_writable ? self::STATUS_CRITICAL : self::STATUS_GOOD,
			$world_writable
				? sprintf(
					/* translators: %s: file permission octal value */
					__( 'wp-config.php is world-writable (%s). Restrict permissions to 600 or 644.', 'action-steward' ),
					$info['permissions']
				)
				: sprintf(
					/* translators: %s: file permission octal value */
					__( 'wp-config.php permissions look reasonable (%s).', 'action-steward' ),
					$info['permissions']
				)
		);
	}

	private function check_ssl( array $wordpress ): array {
		$enabled = $wordpress['is_ssl'];

		return $this->check(
			'ssl',
			__( 'SSL (HTTPS)', 'action-steward' ),
			$enabled ? self::STATUS_GOOD : self::STATUS_RECOMMENDED,
			$enabled
				? __( 'The site is being served over HTTPS.', 'action-steward' )
				: __( 'The site is not using HTTPS. An SSL certificate is strongly recommended.', 'action-steward' )
		);
	}

	private function check_default_admin_account(): array {
		$user = get_user_by( 'login', 'admin' );

		if ( ! $user ) {
			return $this->check(
				'default_admin_account',
				__( 'Default "admin" Account', 'action-steward' ),
				self::STATUS_GOOD,
				__( 'No user with the username "admin" exists.', 'action-steward' )
			);
		}

		$is_administrator = in_array( 'administrator', (array) $user->roles, true );

		return $this->check(
			'default_admin_account',
			__( 'Default "admin" Account', 'action-steward' ),
			$is_administrator ? self::STATUS_RECOMMENDED : self::STATUS_INFO,
			$is_administrator
				? __( 'A user with the username "admin" has the Administrator role — a common brute-force target. Consider renaming or removing it.', 'action-steward' )
				: __( 'A user with the username "admin" exists but is not an administrator.', 'action-steward' )
		);
	}

	private function check_directory_listing(): array {
		$upload_dir = wp_upload_dir();
		$basedir    = trailingslashit( $upload_dir['basedir'] );
		$protected  = file_exists( $basedir . 'index.php' ) || file_exists( $basedir . 'index.html' );

		return $this->check(
			'directory_listing',
			__( 'Uploads Directory Listing', 'action-steward' ),
			$protected ? self::STATUS_GOOD : self::STATUS_RECOMMENDED,
			$protected
				? __( 'The uploads directory contains an index file that prevents directory listing.', 'action-steward' )
				: __( 'The uploads directory has no index.php/index.html — directory listing may be possible if the server allows it.', 'action-steward' )
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
				__( 'WordPress Core Updates', 'action-steward' ),
				self::STATUS_INFO,
				__( 'Update status is unknown — WordPress has not checked for updates yet.', 'action-steward' )
			);
		}

		$latest = $updates[0];

		if ( 'latest' === $latest->response || 'development' === $latest->response ) {
			return $this->check(
				'core_update',
				__( 'WordPress Core Updates', 'action-steward' ),
				self::STATUS_GOOD,
				__( 'WordPress is up to date.', 'action-steward' )
			);
		}

		return $this->check(
			'core_update',
			__( 'WordPress Core Updates', 'action-steward' ),
			self::STATUS_RECOMMENDED,
			sprintf(
				/* translators: %s: available WordPress version */
				__( 'A WordPress core update is available (%s).', 'action-steward' ),
				$latest->version ?? ''
			)
		);
	}
}
