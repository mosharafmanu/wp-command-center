<?php
/**
 * Step 40 — Theme Management Runtime.
 *
 * Safely inspects and manages WordPress themes through the Operations
 * framework. Registry-driven, approval-aware, health-verified, auditable,
 * and rollback-capable. Theme activation is the highest-risk operation.
 */

namespace WPCommandCenter\Operations;

use WPCommandCenter\Health\HealthVerificationEngine;
use WPCommandCenter\Security\AuditLog;

defined( 'ABSPATH' ) || exit;

final class ThemeManager {

	private ThemeRegistry $registry;

	public function __construct() {
		$this->registry = new ThemeRegistry();
	}

	public function run( array $params, array $context = [] ): array|\WP_Error {
		$action = sanitize_key( $params['action'] ?? '' );
		$slug   = sanitize_text_field( $params['slug'] ?? '' );

		if ( ! in_array( $action, ThemeRegistry::ACTIONS, true ) ) {
			return new \WP_Error( 'wpcc_invalid_theme_action', sprintf( __( 'Invalid action: %s. Use theme_list, theme_install, theme_activate, theme_update, or theme_delete.', 'wp-command-center' ), esc_html( $action ) ) );
		}

		if ( ThemeRegistry::ACTION_LIST !== $action && 'theme_rollback' !== $action && '' === $slug ) {
			return new \WP_Error( 'wpcc_missing_theme_slug', __( 'Theme slug is required for this action.', 'wp-command-center' ) );
		}

		if ( '' !== $slug ) {
			$v = $this->registry->validate_slug( $slug );
			if ( $v instanceof \WP_Error ) {
				return $v;
			}
		}

		return match ( $action ) {
			ThemeRegistry::ACTION_LIST    => $this->theme_list(),
			ThemeRegistry::ACTION_INSTALL => $this->theme_install( $slug, $context ),
			ThemeRegistry::ACTION_ACTIVATE => $this->theme_activate( $slug, $context ),
			ThemeRegistry::ACTION_UPDATE  => $this->theme_update( $slug, $context ),
			ThemeRegistry::ACTION_DELETE  => $this->theme_delete( $slug, $context ),
			'theme_rollback'               => $this->theme_rollback_action( $params, $context ),
			default => new \WP_Error( 'wpcc_invalid_theme_action', __( 'Unknown theme action.', 'wp-command-center' ) ),
		};
	}

	// ── Rollback action ──

	private function theme_rollback_action( array $params, array $context ): array|\WP_Error {
		$rid = sanitize_text_field( $params['rollback_id'] ?? '' );
		if ( '' === $rid ) {
			return new \WP_Error( 'wpcc_missing_rollback_id', __( 'rollback_id is required.', 'wp-command-center' ) );
		}
		$records = get_option( 'wpcc_theme_rollbacks', [] );
		if ( ! isset( $records[ $rid ] ) ) {
			return new \WP_Error( 'wpcc_rollback_not_found', __( 'Rollback record not found.', 'wp-command-center' ) );
		}
		$r = $records[ $rid ];
		if ( ! empty( $r['rollback_applied'] ) ) {
			return new \WP_Error( 'wpcc_rollback_already_applied', __( 'Rollback already applied.', 'wp-command-center' ) );
		}
		$slug   = $r['theme_slug'];
		$before = $r['before_state'];
		// Restore previous theme
		if ( 'activate' === $r['theme_action'] && ! empty( $before['previous_slug'] ) ) {
			switch_theme( $before['previous_slug'] );
		}
		$records[ $rid ]['rollback_applied'] = true;
		update_option( 'wpcc_theme_rollbacks', $records );
		$this->audit( 'theme.rollback', [ 'slug' => $slug, 'rollback_id' => $rid ], $context );
		return [ 'action' => 'theme_rollback', 'slug' => $slug, 'rollback_id' => $rid, 'restored' => true ];
	}

	// ── List ──

	private function theme_list(): array {
		$this->audit( 'theme.list', [] );
		return [ 'action' => 'theme_list', 'themes' => $this->registry->get_summary() ];
	}

	// ── Install ──

	private function theme_install( string $slug, array $context ): array|\WP_Error {
		if ( $this->registry->is_installed( $slug ) ) {
			return new \WP_Error( 'wpcc_theme_already_installed', __( 'Theme is already installed.', 'wp-command-center' ) );
		}

		if ( ! function_exists( 'themes_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/theme.php';
		}
		if ( ! class_exists( 'Theme_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}

		$this->audit( 'theme.install.started', [ 'slug' => $slug ], $context );

		$api = themes_api( 'theme_information', [ 'slug' => $slug ] );
		if ( is_wp_error( $api ) ) {
			$this->audit( 'theme.install.failed', [ 'slug' => $slug, 'error' => $api->get_error_message() ], $context );
			return new \WP_Error( 'wpcc_theme_api_error', $api->get_error_message() );
		}

		$upgrader = new \Theme_Upgrader( new \WP_Ajax_Upgrader_Skin() );
		$result   = $upgrader->install( $api->download_link );

		if ( is_wp_error( $result ) ) {
			$this->audit( 'theme.install.failed', [ 'slug' => $slug ], $context );
			return new \WP_Error( 'wpcc_theme_install_failed', $result->get_error_message() );
		}

		$this->audit( 'theme.install', [ 'slug' => $slug ], $context );

		$health = $this->registry->requires_health_check( ThemeRegistry::ACTION_INSTALL )
			? $this->run_health( $slug, $context ) : null;

		return [
			'action'          => 'theme_install',
			'slug'            => $slug,
			'installed'       => true,
			'health_check'    => $health['status'] ?? 'skipped',
			'health_required' => $this->registry->requires_health_check( ThemeRegistry::ACTION_INSTALL ),
		];
	}

	// ── Activate (highest risk) ──

	private function theme_activate( string $slug, array $context ): array|\WP_Error {
		$target = $this->registry->get_theme( $slug );
		if ( null === $target ) {
			return new \WP_Error( 'wpcc_theme_not_found', __( 'Theme not found.', 'wp-command-center' ) );
		}
		if ( $target['active'] ) {
			return new \WP_Error( 'wpcc_theme_already_active', __( 'Theme is already active.', 'wp-command-center' ) );
		}

		$previous = $this->registry->get_active_theme();
		$prev_slug   = $previous['slug'] ?? 'unknown';
		$prev_name   = $previous['name'] ?? 'unknown';
		$prev_ver    = $previous['version'] ?? '0';

		$this->audit( 'theme.activate.started', [
			'slug'           => $slug,
			'previous_slug'  => $prev_slug,
			'previous_name'  => $prev_name,
		], $context );

		$rollback_id = $this->store_rollback( $slug, 'activate', [
			'previous_slug' => $prev_slug,
			'previous_name' => $prev_name,
		], $context );

		switch_theme( $slug );

		$this->audit( 'theme.activate', [
			'slug'           => $slug,
			'previous_slug'  => $prev_slug,
			'previous_name'  => $prev_name,
			'rollback_id'    => $rollback_id,
		], $context );

		$health = $this->run_health( $slug, $context );

		return [
			'action'          => 'theme_activate',
			'slug'            => $slug,
			'previous_slug'   => $prev_slug,
			'previous_name'   => $prev_name,
			'active'          => true,
			'rollback_id'     => $rollback_id,
			'health_check'    => $health['status'] ?? 'skipped',
			'health_required' => true,
		];
	}

	// ── Update ──

	private function theme_update( string $slug, array $context ): array|\WP_Error {
		$info = $this->registry->get_theme( $slug );
		if ( null === $info ) {
			return new \WP_Error( 'wpcc_theme_not_found', __( 'Theme not found.', 'wp-command-center' ) );
		}
		if ( ! $info['update_available'] ) {
			return new \WP_Error( 'wpcc_theme_no_update', __( 'No update available for this theme.', 'wp-command-center' ) );
		}

		$old = $info['version'];

		$this->audit( 'theme.update.started', [ 'slug' => $slug, 'old_version' => $old ], $context );

		if ( ! class_exists( 'Theme_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}

		$upgrader = new \Theme_Upgrader( new \WP_Ajax_Upgrader_Skin() );
		$result   = $upgrader->upgrade( $slug );

		if ( is_wp_error( $result ) ) {
			$this->audit( 'theme.update.failed', [ 'slug' => $slug ], $context );
			return new \WP_Error( 'wpcc_theme_update_failed', $result->get_error_message() );
		}

		$updated = $this->registry->get_theme( $slug );
		$new = $updated['version'] ?? $info['new_version'] ?? 'unknown';

		$this->audit( 'theme.update', [ 'slug' => $slug, 'old_version' => $old, 'new_version' => $new ], $context );

		$health = $this->run_health( $slug, $context );

		return [
			'action'          => 'theme_update',
			'slug'            => $slug,
			'old_version'     => $old,
			'new_version'     => $new,
			'health_check'    => $health['status'] ?? 'skipped',
			'health_required' => true,
		];
	}

	// ── Delete ──

	private function theme_delete( string $slug, array $context ): array|\WP_Error {
		$info = $this->registry->get_theme( $slug );
		if ( null === $info ) {
			return new \WP_Error( 'wpcc_theme_not_found', __( 'Theme not found.', 'wp-command-center' ) );
		}
		if ( $info['active'] ) {
			return new \WP_Error( 'wpcc_theme_delete_active', __( 'Cannot delete the active theme. Activate another theme first.', 'wp-command-center' ) );
		}

		$before = [ 'slug' => $slug, 'version' => $info['version'], 'name' => $info['name'] ];
		$rollback_id = $this->store_rollback( $slug, 'delete', $before, $context );

		$this->audit( 'theme.delete.started', [ 'slug' => $slug, 'version' => $info['version'] ], $context );

		$result = delete_theme( $slug );

		if ( is_wp_error( $result ) ) {
			$this->audit( 'theme.delete.failed', [ 'slug' => $slug ], $context );
			return new \WP_Error( 'wpcc_theme_delete_failed', $result->get_error_message() );
		}

		$this->audit( 'theme.delete', [ 'slug' => $slug, 'rollback_id' => $rollback_id ], $context );

		$health = $this->run_health( $slug, $context );

		return [
			'action'          => 'theme_delete',
			'slug'            => $slug,
			'deleted'         => true,
			'rollback_id'     => $rollback_id,
			'health_check'    => $health['status'] ?? 'skipped',
			'health_required' => true,
		];
	}

	// ── Helpers ──

	private function store_rollback( string $slug, string $action, array $before, array $context ): string {
		$id             = wp_generate_uuid4();
		$records        = get_option( 'wpcc_theme_rollbacks', [] );
		$records[ $id ] = [
			'id'               => $id,
			'theme_slug'       => $slug,
			'theme_action'     => $action,
			'before_state'     => $before,
			'rollback_applied' => false,
			'created_at'       => time(),
			'session_id'       => $context['session_id'] ?? '',
			'task_id'          => $context['task_id'] ?? '',
		];
		update_option( 'wpcc_theme_rollbacks', $records );
		return $id;
	}

	private function run_health( string $slug, array $context ): array {
		try {
			$health = new HealthVerificationEngine();
			$actor  = $context['actor'] ?? [];
			$result = $health->verify( $actor );
			if ( is_wp_error( $result ) ) {
				$this->audit( 'theme.health.failed', [ 'slug' => $slug, 'error' => $result->get_error_message() ], $context );
				return [ 'status' => 'failed', 'error' => $result->get_error_message() ];
			}
			$status = $result['status'] ?? 'unknown';
			if ( 'failed' === $status ) {
				$this->audit( 'theme.health.warning', [ 'slug' => $slug, 'status' => $status ], $context );
			}
			return [ 'status' => $status ];
		} catch ( \Throwable $e ) {
			return [ 'status' => 'error', 'error' => $e->getMessage() ];
		}
	}

	private function audit( string $event, array $data, array $context = [] ): void {
		$audit = new AuditLog();
		$risk  = ThemeRegistry::RISK_MEDIUM;
		if ( isset( $data['slug'] ) ) {
			$parts = explode( '.', $event );
			$act   = $parts[1] ?? '';
			if ( in_array( $act, [ 'list', 'install', 'activate', 'update', 'delete' ], true ) ) {
				$risk = $this->registry->action_risk( 'theme_' . $act );
			}
		}
		$actor = isset( $context['actor'] ) ? AuditLog::resolve_actor( $context['actor'] ) : null;
		$audit->record( $event, array_merge( [ 'risk_level' => $risk, 'actor' => $actor ], $data ) );
	}

	public function get_registry(): ThemeRegistry {
		return $this->registry;
	}
}
