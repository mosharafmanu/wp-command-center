<?php
/**
 * Step 39 — Plugin Registry.
 *
 * Discovers installed plugins, provides metadata, risk classification,
 * and allowed operation definitions. No direct filesystem manipulation.
 */

namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class PluginRegistry {

	const RISK_LOW      = 'low';
	const RISK_MEDIUM   = 'medium';
	const RISK_HIGH     = 'high';
	const RISK_CRITICAL = 'critical';

	const RISK_LEVELS = [ 'low', 'medium', 'high', 'critical' ];

	const ACTION_LIST       = 'plugin_list';
	const ACTION_INSTALL    = 'plugin_install';
	const ACTION_ACTIVATE   = 'plugin_activate';
	const ACTION_DEACTIVATE = 'plugin_deactivate';
	const ACTION_UPDATE     = 'plugin_update';
	const ACTION_DELETE     = 'plugin_delete';

	const ACTIONS = [ 'plugin_list', 'plugin_install', 'plugin_activate', 'plugin_deactivate', 'plugin_update', 'plugin_delete', 'plugin_rollback' ];

	/**
	 * Risk level for each operation type.
	 */
	public function action_risk( string $action ): string {
		return match ( $action ) {
			self::ACTION_LIST       => self::RISK_LOW,
			self::ACTION_INSTALL    => self::RISK_MEDIUM,
			self::ACTION_ACTIVATE   => self::RISK_MEDIUM,
			self::ACTION_DEACTIVATE => self::RISK_MEDIUM,
			self::ACTION_UPDATE     => self::RISK_HIGH,
			self::ACTION_DELETE     => self::RISK_CRITICAL,
			default                  => self::RISK_HIGH,
		};
	}

	/**
	 * Whether the operation requires approval.
	 */
	public function requires_approval( string $action ): bool {
		return $action !== self::ACTION_LIST;
	}

	/**
	 * Whether health verification should run after this action.
	 */
	public function requires_health_check( string $action ): bool {
		return in_array( $action, [ self::ACTION_INSTALL, self::ACTION_ACTIVATE, self::ACTION_UPDATE, self::ACTION_DELETE ], true );
	}

	/**
	 * Get all installed plugins with metadata.
	 *
	 * @return array<int, array>
	 */
	public function get_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins   = get_plugins();
		$active        = get_option( 'active_plugins', [] );
		$active_set    = array_flip( $active );
		$updates       = get_site_transient( 'update_plugins' );
		$update_data   = is_object( $updates ) && isset( $updates->response ) ? $updates->response : [];
		$result        = [];

		foreach ( $all_plugins as $plugin_file => $plugin_data ) {
			$slug       = dirname( $plugin_file );
			$is_active  = isset( $active_set[ $plugin_file ] );
			$new_version = null;
			$update_avail = false;

			if ( isset( $update_data[ $plugin_file ] ) && is_object( $update_data[ $plugin_file ] ) ) {
				$new_version  = $update_data[ $plugin_file ]->new_version ?? null;
				$update_avail = true;
			}

			$result[] = [
				'slug'             => $slug,
				'plugin_file'      => $plugin_file,
				'name'             => $plugin_data['Name'] ?? $slug,
				'version'          => $plugin_data['Version'] ?? '0.0.0',
				'active'           => $is_active,
				'update_available' => $update_avail,
				'new_version'      => $new_version,
				'description'      => $plugin_data['Description'] ?? '',
				'author'           => $plugin_data['Author'] ?? '',
				'requires_php'     => $plugin_data['RequiresPHP'] ?? '',
				'requires_wp'      => $plugin_data['RequiresWP'] ?? '',
			];
		}

		return $result;
	}

	/**
	 * Get a single plugin by slug.
	 */
	public function get_plugin( string $slug ): ?array {
		foreach ( $this->get_plugins() as $plugin ) {
			if ( $plugin['slug'] === $slug ) {
				return $plugin;
			}
		}
		return null;
	}

	/**
	 * Validate a plugin slug (safety check — no path traversal).
	 */
	public function validate_slug( string $slug ): ?\WP_Error {
		if ( '' === $slug ) {
			return new \WP_Error( 'wpcc_missing_plugin_slug', __( 'Plugin slug is required.', 'wp-command-center' ) );
		}

		if ( ! preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9._\-]*$/', $slug ) ) {
			return new \WP_Error( 'wpcc_invalid_plugin_slug', __( 'Invalid plugin slug format.', 'wp-command-center' ) );
		}

		return null;
	}

	/**
	 * Check if a plugin is installed.
	 */
	public function is_installed( string $slug ): bool {
		return null !== $this->get_plugin( $slug );
	}

	/**
	 * Check if a plugin is active.
	 */
	public function is_active( string $slug ): bool {
		$plugin = $this->get_plugin( $slug );
		return null !== $plugin && $plugin['active'];
	}

	/**
	 * Get plugin summary for manifest/context.
	 */
	public function get_summary(): array {
		$plugins = $this->get_plugins();
		$active  = count( array_filter( $plugins, static fn( $p ) => $p['active'] ) );
		$updates = count( array_filter( $plugins, static fn( $p ) => $p['update_available'] ) );

		return [
			'total'              => count( $plugins ),
			'active'             => $active,
			'inactive'           => count( $plugins ) - $active,
			'updates_available'  => $updates,
			'plugins'            => array_map( static fn( $p ) => [
				'slug'             => $p['slug'],
				'name'             => $p['name'],
				'version'          => $p['version'],
				'active'           => $p['active'],
				'update_available' => $p['update_available'],
			], $plugins ),
		];
	}

	/**
	 * Operations definition for the operation registry.
	 */
	public function get_operations_definition(): array {
		return [
			self::ACTION_LIST       => [ 'name' => 'List Plugins', 'description' => 'List all installed plugins with status and version.' ],
			self::ACTION_INSTALL    => [ 'name' => 'Install Plugin', 'description' => 'Install a plugin from WordPress.org.' ],
			self::ACTION_ACTIVATE   => [ 'name' => 'Activate Plugin', 'description' => 'Activate an installed plugin.' ],
			self::ACTION_DEACTIVATE => [ 'name' => 'Deactivate Plugin', 'description' => 'Deactivate an active plugin.' ],
			self::ACTION_UPDATE     => [ 'name' => 'Update Plugin', 'description' => 'Update a plugin to the latest version.' ],
			self::ACTION_DELETE     => [ 'name' => 'Delete Plugin', 'description' => 'Delete an inactive plugin.' ],
		];
	}

	/**
	 * Count plugins by state.
	 */
	public function count_by_state(): array {
		$plugins = $this->get_plugins();
		return [
			'total'    => count( $plugins ),
			'active'   => count( array_filter( $plugins, static fn( $p ) => $p['active'] ) ),
			'inactive' => count( array_filter( $plugins, static fn( $p ) => ! $p['active'] ) ),
			'updates'  => count( array_filter( $plugins, static fn( $p ) => $p['update_available'] ) ),
		];
	}
}
