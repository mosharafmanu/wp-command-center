<?php
/**
 * Step 40 — Theme Registry.
 *
 * Discovers installed themes, provides metadata, risk classification,
 * and allowed operation definitions. No direct filesystem manipulation.
 */

namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class ThemeRegistry {

	const RISK_LOW      = 'low';
	const RISK_MEDIUM   = 'medium';
	const RISK_HIGH     = 'high';
	const RISK_CRITICAL = 'critical';

	const ACTION_LIST   = 'theme_list';
	const ACTION_INSTALL = 'theme_install';
	const ACTION_ACTIVATE = 'theme_activate';
	const ACTION_UPDATE  = 'theme_update';
	const ACTION_DELETE  = 'theme_delete';

	const ACTIONS = [ 'theme_list', 'theme_install', 'theme_activate', 'theme_update', 'theme_delete', 'theme_rollback' ];

	public function action_risk( string $action ): string {
		return match ( $action ) {
			self::ACTION_LIST   => self::RISK_LOW,
			self::ACTION_INSTALL => self::RISK_MEDIUM,
			self::ACTION_UPDATE  => self::RISK_HIGH,
			self::ACTION_ACTIVATE => self::RISK_CRITICAL,
			self::ACTION_DELETE  => self::RISK_CRITICAL,
			default               => self::RISK_HIGH,
		};
	}

	public function requires_approval( string $action ): bool {
		return $action !== self::ACTION_LIST;
	}

	public function requires_health_check( string $action ): bool {
		return in_array( $action, [ self::ACTION_INSTALL, self::ACTION_ACTIVATE, self::ACTION_UPDATE, self::ACTION_DELETE ], true );
	}

	/**
	 * Get all installed themes with metadata.
	 */
	public function get_themes(): array {
		$all_themes    = wp_get_themes();
		$active_slug   = get_option( 'stylesheet' );
		$updates       = get_site_transient( 'update_themes' );
		$update_data   = is_object( $updates ) && isset( $updates->response ) ? $updates->response : [];
		$result        = [];

		foreach ( $all_themes as $slug => $theme ) {
			$is_active     = $slug === $active_slug;
			$new_version   = null;
			$update_avail  = false;

			if ( isset( $update_data[ $slug ] ) && is_array( $update_data[ $slug ] ) ) {
				$new_version  = $update_data[ $slug ]['new_version'] ?? null;
				$update_avail = true;
			}

			$result[] = [
				'slug'             => $slug,
				'name'             => $theme->get( 'Name' ) ?: $slug,
				'version'          => $theme->get( 'Version' ) ?: '0.0.0',
				'active'           => $is_active,
				'parent'           => $theme->get( 'Template' ) ?: null,
				'update_available' => $update_avail,
				'new_version'      => $new_version,
				'description'      => $theme->get( 'Description' ) ?: '',
				'author'           => $theme->get( 'Author' ) ?: '',
			];
		}

		return $result;
	}

	public function get_theme( string $slug ): ?array {
		foreach ( $this->get_themes() as $theme ) {
			if ( $theme['slug'] === $slug ) {
				return $theme;
			}
		}
		return null;
	}

	public function get_active_theme(): ?array {
		$active_slug = get_option( 'stylesheet' );
		return $this->get_theme( $active_slug );
	}

	public function validate_slug( string $slug ): ?\WP_Error {
		if ( '' === $slug ) {
			return new \WP_Error( 'wpcc_missing_theme_slug', __( 'Theme slug is required.', 'wp-command-center' ) );
		}
		if ( ! preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9._\-]*$/', $slug ) ) {
			return new \WP_Error( 'wpcc_invalid_theme_slug', __( 'Invalid theme slug format.', 'wp-command-center' ) );
		}
		return null;
	}

	public function is_installed( string $slug ): bool {
		return null !== $this->get_theme( $slug );
	}

	public function is_active( string $slug ): bool {
		$theme = $this->get_theme( $slug );
		return null !== $theme && $theme['active'];
	}

	public function get_summary(): array {
		$themes  = $this->get_themes();
		$active  = $this->get_active_theme();
		$updates = count( array_filter( $themes, static fn( $t ) => $t['update_available'] ) );

		return [
			'total'             => count( $themes ),
			'active_theme'      => $active ? [ 'slug' => $active['slug'], 'name' => $active['name'], 'version' => $active['version'] ] : null,
			'updates_available' => $updates,
			'themes'            => array_map( static fn( $t ) => [
				'slug'             => $t['slug'],
				'name'             => $t['name'],
				'version'          => $t['version'],
				'active'           => $t['active'],
				'parent'           => $t['parent'],
				'update_available' => $t['update_available'],
			], $themes ),
		];
	}

	public function count_by_state(): array {
		$themes = $this->get_themes();
		return [
			'total'   => count( $themes ),
			'active'  => count( array_filter( $themes, static fn( $t ) => $t['active'] ) ),
			'updates' => count( array_filter( $themes, static fn( $t ) => $t['update_available'] ) ),
		];
	}
}
