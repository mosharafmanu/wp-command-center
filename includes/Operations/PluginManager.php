<?php
/**
 * Step 39 — Plugin Management Runtime.
 *
 * Safely inspects and manages WordPress plugins through the Operations
 * framework. Registry-driven, approval-aware, queue-integrated, auditable,
 * rollback-capable, and health-verified.
 *
 * Operations: plugin_list, plugin_install, plugin_activate,
 *             plugin_deactivate, plugin_update, plugin_delete
 */

namespace WPCommandCenter\Operations;

use WPCommandCenter\Health\HealthVerificationEngine;
use WPCommandCenter\Security\AuditLog;

defined( 'ABSPATH' ) || exit;

final class PluginManager {

	private PluginRegistry $registry;

	public function __construct() {
		$this->registry = new PluginRegistry();
	}

	/**
	 * Run a plugin management operation.
	 *
	 * @param array{
	 *     action: string,
	 *     slug?: string
	 * } $params
	 * @param array $context
	 * @return array|\WP_Error
	 */
	public function run( array $params, array $context = [] ): array|\WP_Error {
		$action = sanitize_key( $params['action'] ?? '' );
		$slug   = sanitize_text_field( $params['slug'] ?? '' );

		if ( ! in_array( $action, PluginRegistry::ACTIONS, true ) ) {
			return new \WP_Error( 'wpcc_invalid_plugin_action', sprintf( __( 'Invalid action: %s. Use plugin_list, plugin_install, plugin_activate, plugin_deactivate, plugin_update, or plugin_delete.', 'wp-command-center' ), esc_html( $action ) ) );
		}

		if ( PluginRegistry::ACTION_LIST !== $action && 'plugin_rollback' !== $action && '' === $slug ) {
			return new \WP_Error( 'wpcc_missing_plugin_slug', __( 'Plugin slug is required for this action.', 'wp-command-center' ) );
		}

		if ( '' !== $slug ) {
			$validation = $this->registry->validate_slug( $slug );
			if ( $validation instanceof \WP_Error ) {
				return $validation;
			}
		}

		$risk = $this->registry->action_risk( $action );

		return match ( $action ) {
			PluginRegistry::ACTION_LIST       => $this->plugin_list(),
			PluginRegistry::ACTION_INSTALL    => $this->plugin_install( $slug, $context ),
			PluginRegistry::ACTION_ACTIVATE   => $this->plugin_activate( $slug, $context ),
			PluginRegistry::ACTION_DEACTIVATE => $this->plugin_deactivate( $slug, $context ),
			PluginRegistry::ACTION_UPDATE     => $this->plugin_update( $slug, $context ),
			PluginRegistry::ACTION_DELETE     => $this->plugin_delete( $slug, $context ),
			'plugin_rollback'                  => $this->plugin_rollback_action( $params, $context ),
			default                            => new \WP_Error( 'wpcc_invalid_plugin_action', __( 'Unknown plugin action.', 'wp-command-center' ) ),
		};
	}

	// ── List ──────────────────────────────────────────────────────

	private function plugin_list(): array {
		$this->audit( 'plugin.list', [ 'slug' => 'all' ] );

		return [
			'action'  => 'plugin_list',
			'plugins' => $this->registry->get_summary(),
		];
	}

	// ── Install ───────────────────────────────────────────────────

	private function plugin_install( string $slug, array $context ): array|\WP_Error {
		if ( $this->registry->is_installed( $slug ) ) {
			return new \WP_Error( 'wpcc_plugin_already_installed', __( 'Plugin is already installed.', 'wp-command-center' ) );
		}

		$this->audit( 'plugin.install.started', [ 'slug' => $slug ], $context );

		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}
		if ( ! class_exists( 'Plugin_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}

		$api = plugins_api( 'plugin_information', [
			'slug'   => $slug,
			'fields' => [ 'short_description' => false, 'sections' => false ],
		] );

		if ( is_wp_error( $api ) ) {
			$this->audit( 'plugin.install.failed', [ 'slug' => $slug, 'error' => $api->get_error_message() ], $context );
			return new \WP_Error( 'wpcc_plugin_api_error', $api->get_error_message() );
		}

		$upgrader = new \Plugin_Upgrader( new \WP_Ajax_Upgrader_Skin() );
		$result   = $upgrader->install( $api->download_link );

		if ( is_wp_error( $result ) ) {
			$this->audit( 'plugin.install.failed', [ 'slug' => $slug, 'error' => $result->get_error_message() ], $context );
			return new \WP_Error( 'wpcc_plugin_install_failed', $result->get_error_message() );
		}

		if ( ! $result ) {
			$this->audit( 'plugin.install.failed', [ 'slug' => $slug ], $context );
			return new \WP_Error( 'wpcc_plugin_install_failed', __( 'Plugin installation failed.', 'wp-command-center' ) );
		}

		$this->audit( 'plugin.install.completed', [ 'slug' => $slug ], $context );

		$health = null;
		if ( $this->registry->requires_health_check( PluginRegistry::ACTION_INSTALL ) ) {
			$health = $this->run_health_check( $slug, $context );
		}

		$plugin_info = $this->registry->get_plugin( $slug );

		return [
			'action'           => 'plugin_install',
			'slug'             => $slug,
			'installed'        => true,
			'version'          => $plugin_info['version'] ?? 'unknown',
			'health_check'     => $health ? $health['status'] : 'skipped',
			'health_required'  => $this->registry->requires_health_check( PluginRegistry::ACTION_INSTALL ),
		];
	}

	// ── Activate ──────────────────────────────────────────────────

	private function plugin_activate( string $slug, array $context ): array|\WP_Error {
		$plugin_info = $this->registry->get_plugin( $slug );

		if ( null === $plugin_info ) {
			return new \WP_Error( 'wpcc_plugin_not_found', __( 'Plugin not found.', 'wp-command-center' ) );
		}

		if ( $plugin_info['active'] ) {
			return new \WP_Error( 'wpcc_plugin_already_active', __( 'Plugin is already active.', 'wp-command-center' ) );
		}

		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$before = [ 'active' => false, 'version' => $plugin_info['version'] ];

		// Store rollback data before activation.
		$rollback_id = $this->store_rollback( $slug, 'activate', $before, $context );

		$result = activate_plugin( $plugin_info['plugin_file'] );

		if ( is_wp_error( $result ) ) {
			$this->audit( 'plugin.activate.failed', [ 'slug' => $slug, 'error' => $result->get_error_message() ], $context );
			return new \WP_Error( 'wpcc_plugin_activate_failed', $result->get_error_message() );
		}

		$this->audit( 'plugin.activate', [
			'slug'     => $slug,
			'version'  => $plugin_info['version'],
			'rollback_id' => $rollback_id,
		], $context );

		$health = null;
		if ( $this->registry->requires_health_check( PluginRegistry::ACTION_ACTIVATE ) ) {
			$health = $this->run_health_check( $slug, $context );
		}

		return [
			'action'          => 'plugin_activate',
			'slug'            => $slug,
			'active'          => true,
			'version'         => $plugin_info['version'],
			'rollback_id'     => $rollback_id,
			'health_check'    => $health ? $health['status'] : 'skipped',
			'health_required' => $this->registry->requires_health_check( PluginRegistry::ACTION_ACTIVATE ),
		];
	}

	// ── Deactivate ────────────────────────────────────────────────

	private function plugin_deactivate( string $slug, array $context ): array|\WP_Error {
		$plugin_info = $this->registry->get_plugin( $slug );

		if ( null === $plugin_info ) {
			return new \WP_Error( 'wpcc_plugin_not_found', __( 'Plugin not found.', 'wp-command-center' ) );
		}

		if ( ! $plugin_info['active'] ) {
			return new \WP_Error( 'wpcc_plugin_already_inactive', __( 'Plugin is already inactive.', 'wp-command-center' ) );
		}

		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$before = [ 'active' => true, 'version' => $plugin_info['version'] ];
		$rollback_id = $this->store_rollback( $slug, 'deactivate', $before, $context );

		deactivate_plugins( $plugin_info['plugin_file'], true );

		$this->audit( 'plugin.deactivate', [
			'slug'     => $slug,
			'version'  => $plugin_info['version'],
			'rollback_id' => $rollback_id,
		], $context );

		$health = null;
		if ( $this->registry->requires_health_check( PluginRegistry::ACTION_DEACTIVATE ) ) {
			$health = $this->run_health_check( $slug, $context );
		}

		return [
			'action'          => 'plugin_deactivate',
			'slug'            => $slug,
			'active'          => false,
			'rollback_id'     => $rollback_id,
			'health_check'    => $health ? $health['status'] : 'skipped',
			'health_required' => $this->registry->requires_health_check( PluginRegistry::ACTION_DEACTIVATE ),
		];
	}

	// ── Update ────────────────────────────────────────────────────

	private function plugin_update( string $slug, array $context ): array|\WP_Error {
		$plugin_info = $this->registry->get_plugin( $slug );

		if ( null === $plugin_info ) {
			return new \WP_Error( 'wpcc_plugin_not_found', __( 'Plugin not found.', 'wp-command-center' ) );
		}

		if ( ! $plugin_info['update_available'] ) {
			return new \WP_Error( 'wpcc_plugin_no_update', __( 'No update available for this plugin.', 'wp-command-center' ) );
		}

		$old_version  = $plugin_info['version'];
		$new_expected = $plugin_info['new_version'] ?? 'unknown';

		if ( ! class_exists( 'Plugin_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}

		$this->audit( 'plugin.update.started', [
			'slug'        => $slug,
			'old_version' => $old_version,
			'new_version' => $new_expected,
		], $context );

		$upgrader = new \Plugin_Upgrader( new \WP_Ajax_Upgrader_Skin() );
		$result   = $upgrader->upgrade( $plugin_info['plugin_file'] );

		if ( is_wp_error( $result ) ) {
			$this->audit( 'plugin.update.failed', [
				'slug'  => $slug,
				'error' => $result->get_error_message(),
			], $context );
			return new \WP_Error( 'wpcc_plugin_update_failed', $result->get_error_message() );
		}

		if ( ! $result ) {
			$this->audit( 'plugin.update.failed', [ 'slug' => $slug ], $context );
			return new \WP_Error( 'wpcc_plugin_update_failed', __( 'Plugin update failed.', 'wp-command-center' ) );
		}

		// Re-read version after update.
		$updated_info = $this->registry->get_plugin( $slug );
		$new_version  = $updated_info['version'] ?? $new_expected;

		$this->audit( 'plugin.update', [
			'slug'        => $slug,
			'old_version' => $old_version,
			'new_version' => $new_version,
		], $context );

		$health = null;
		if ( $this->registry->requires_health_check( PluginRegistry::ACTION_UPDATE ) ) {
			$health = $this->run_health_check( $slug, $context );
		}

		return [
			'action'          => 'plugin_update',
			'slug'            => $slug,
			'old_version'     => $old_version,
			'new_version'     => $new_version,
			'health_check'    => $health ? $health['status'] : 'skipped',
			'health_required' => $this->registry->requires_health_check( PluginRegistry::ACTION_UPDATE ),
		];
	}

	// ── Delete ────────────────────────────────────────────────────

	private function plugin_delete( string $slug, array $context ): array|\WP_Error {
		$plugin_info = $this->registry->get_plugin( $slug );

		if ( null === $plugin_info ) {
			return new \WP_Error( 'wpcc_plugin_not_found', __( 'Plugin not found.', 'wp-command-center' ) );
		}

		if ( $plugin_info['active'] ) {
			return new \WP_Error( 'wpcc_plugin_delete_active', __( 'Cannot delete an active plugin. Deactivate it first.', 'wp-command-center' ) );
		}

		if ( ! function_exists( 'delete_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Store rollback metadata before deletion.
		$before = [
			'active'  => false,
			'version' => $plugin_info['version'],
			'slug'    => $slug,
		];
		$rollback_id = $this->store_rollback( $slug, 'delete', $before, $context );

		$this->audit( 'plugin.delete.started', [
			'slug'    => $slug,
			'version' => $plugin_info['version'],
		], $context );

		$result = delete_plugins( [ $plugin_info['plugin_file'] ] );

		if ( is_wp_error( $result ) ) {
			$this->audit( 'plugin.delete.failed', [ 'slug' => $slug, 'error' => $result->get_error_message() ], $context );
			return new \WP_Error( 'wpcc_plugin_delete_failed', $result->get_error_message() );
		}

		$this->audit( 'plugin.delete', [
			'slug'        => $slug,
			'version'     => $plugin_info['version'],
			'rollback_id' => $rollback_id,
		], $context );

		$health = null;
		if ( $this->registry->requires_health_check( PluginRegistry::ACTION_DELETE ) ) {
			$health = $this->run_health_check( $slug, $context );
		}

		return [
			'action'           => 'plugin_delete',
			'slug'             => $slug,
			'deleted'          => true,
			'rollback_id'      => $rollback_id,
			'health_check'     => $health ? $health['status'] : 'skipped',
			'health_required'  => $this->registry->requires_health_check( PluginRegistry::ACTION_DELETE ),
		];
	}

	// ── Rollback action ──

	private function plugin_rollback_action( array $params, array $context ): array|\WP_Error {
		$rid = sanitize_text_field( $params['rollback_id'] ?? '' );
		if ( '' === $rid ) {
			return new \WP_Error( 'wpcc_missing_rollback_id', __( 'rollback_id is required.', 'wp-command-center' ) );
		}
		$records = get_option( 'wpcc_plugin_rollbacks', [] );
		if ( ! isset( $records[ $rid ] ) ) {
			return new \WP_Error( 'wpcc_rollback_not_found', __( 'Rollback record not found.', 'wp-command-center' ) );
		}
		$r = $records[ $rid ];
		if ( ! empty( $r['rollback_applied'] ) ) {
			return new \WP_Error( 'wpcc_rollback_already_applied', __( 'Rollback already applied.', 'wp-command-center' ) );
		}
		$slug   = $r['plugin_slug'];
		$action = $r['plugin_action'];

		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Restore based on original action
		if ( 'activate' === $action ) {
			deactivate_plugins( $this->registry->get_plugin( $slug )['plugin_file'] ?? '', true );
		} elseif ( 'deactivate' === $action || 'delete' === $action ) {
			// Cannot truly undo delete/install, but we log the attempt
			return new \WP_Error( 'wpcc_rollback_partial', __( 'Rollback for this action is limited. Manual restoration may be required.', 'wp-command-center' ) );
		}

		$records[ $rid ]['rollback_applied'] = true;
		update_option( 'wpcc_plugin_rollbacks', $records );

		$this->audit( 'plugin.rollback', [ 'slug' => $slug, 'rollback_id' => $rid ], $context );

		return [ 'action' => 'plugin_rollback', 'slug' => $slug, 'rollback_id' => $rid, 'restored' => true ];
	}

	// ── Rollback storage (wp_options-based) ──

	// ── Rollback storage (wp_options-based) ──

	private function store_rollback( string $slug, string $action, array $before, array $context ): string {
		$rid           = wp_generate_uuid4();
		$records       = get_option( 'wpcc_plugin_rollbacks', [] );
		$records[ $rid ] = [
			'id'              => $rid,
			'plugin_slug'     => $slug,
			'plugin_action'   => $action,
			'before_state'    => $before,
			'rollback_applied'=> false,
			'created_at'      => time(),
			'session_id'      => $context['session_id'] ?? '',
			'task_id'         => $context['task_id'] ?? '',
		];
		update_option( 'wpcc_plugin_rollbacks', $records );
		return $rid;
	}

	// ── Health check ──────────────────────────────────────────────

	private function run_health_check( string $slug, array $context ): ?array {
		try {
			$health  = new HealthVerificationEngine();
			$actor   = isset( $context['actor'] ) ? $context['actor'] : [];
			$result  = $health->verify( $actor );

			if ( is_wp_error( $result ) ) {
				$this->audit( 'plugin.health.failed', [
					'slug'  => $slug,
					'error' => $result->get_error_message(),
				], $context );
				return [ 'status' => 'failed', 'error' => $result->get_error_message() ];
			}

			$status = $result['status'] ?? 'unknown';

			if ( 'failed' === $status ) {
				$this->audit( 'plugin.health.warning', [
					'slug'    => $slug,
					'status'  => $status,
					'summary' => $result['checks_summary'] ?? [],
				], $context );
			}

			return [ 'status' => $status, 'summary' => $result['checks_summary'] ?? [] ];
		} catch ( \Throwable $e ) {
			$this->audit( 'plugin.health.error', [
				'slug'  => $slug,
				'error' => $e->getMessage(),
			], $context );
			return [ 'status' => 'error', 'error' => $e->getMessage() ];
		}
	}

	// ── Audit ─────────────────────────────────────────────────────

	private function audit( string $event, array $data, array $context = [] ): void {
		$audit = new AuditLog();
		$risk  = PluginRegistry::RISK_MEDIUM;

		if ( isset( $data['slug'] ) ) {
			$action = explode( '.', $event )[1] ?? '';
			if ( in_array( $action, [ 'list', 'install', 'activate', 'deactivate', 'update', 'delete' ], true ) ) {
				$full_action = 'plugin_' . $action;
				$risk = $this->registry->action_risk( $full_action );
			}
		}

		$actor = isset( $context['actor'] ) ? AuditLog::resolve_actor( $context['actor'] ) : null;

		$audit->record( $event, array_merge( [ 'risk_level' => $risk, 'actor' => $actor ], $data ) );
	}

	public function get_registry(): PluginRegistry {
		return $this->registry;
	}
}
