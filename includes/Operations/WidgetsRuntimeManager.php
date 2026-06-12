<?php
/**
 * Step 73 — Widgets Runtime Manager.
 *
 * Safely manages WordPress widgets and sidebar assignments.
 * Operations: widget_list, widget_get, widget_add, widget_update,
 *             widget_remove, sidebar_assign, sidebar_remove.
 * Registry-driven, rollback-capable.
 */

namespace WPCommandCenter\Operations;

use WPCommandCenter\Security\AuditLog;

defined( 'ABSPATH' ) || exit;

final class WidgetsRuntimeManager {

	private WidgetsRegistry $registry;

	public function __construct() {
		$this->registry = new WidgetsRegistry();
	}

	private function audit( string $event, array $data, array $context = [] ): void {
		$audit = new AuditLog();
		$actor = isset( $context['actor'] ) ? AuditLog::resolve_actor( $context['actor'] ) : null;
		$audit->record( $event, array_merge( [ 'actor' => $actor ], $data ) );
	}

	/**
	 * @param array{action: string, widget_id?: string, sidebar_id?: string, widget_type?: string, widget_settings?: array} $params
	 * @return array|\WP_Error
	 */
	public function run( array $params, array $context = [] ): array|\WP_Error {
		$action = sanitize_key( $params['action'] ?? '' );

		if ( ! in_array( $action, WidgetsRegistry::ACTIONS, true ) ) {
			return new \WP_Error( 'wpcc_invalid_widgets_action', __( 'Invalid widgets action.', 'wp-command-center' ) );
		}

		return match ( $action ) {
			WidgetsRegistry::ACTION_WIDGET_LIST    => $this->widget_list(),
			WidgetsRegistry::ACTION_WIDGET_GET     => $this->widget_get( $params ),
			WidgetsRegistry::ACTION_WIDGET_ADD     => $this->widget_add( $params, $context ),
			WidgetsRegistry::ACTION_WIDGET_UPDATE  => $this->widget_update( $params, $context ),
			WidgetsRegistry::ACTION_WIDGET_REMOVE  => $this->widget_remove( $params, $context ),
			WidgetsRegistry::ACTION_SIDEBAR_ASSIGN => $this->sidebar_assign( $params, $context ),
			WidgetsRegistry::ACTION_SIDEBAR_REMOVE => $this->sidebar_remove( $params, $context ),
			'widgets_rollback'                      => $this->widgets_rollback( $params, $context ),
			default                                 => new \WP_Error( 'wpcc_invalid_widgets_action', __( 'Unknown widgets action.', 'wp-command-center' ) ),
		};
	}

	private function widget_list(): array {
		$this->audit( 'widgets.list', [] );
		return [
			'action'  => 'widget_list',
			'summary' => $this->registry->get_summary(),
		];
	}

	private function widget_get( array $params ): array|\WP_Error {
		$widget_id = sanitize_text_field( $params['widget_id'] ?? '' );
		if ( '' === $widget_id ) {
			return new \WP_Error( 'wpcc_missing_widget_id', __( 'widget_id is required.', 'wp-command-center' ) );
		}

		$widget = $this->registry->get_widget( $widget_id );
		if ( null === $widget ) {
			return new \WP_Error( 'wpcc_widget_not_found', __( 'Widget not found.', 'wp-command-center' ) );
		}

		$this->audit( 'widgets.get', [ 'widget_id' => $widget_id ] );
		return [
			'action'  => 'widget_get',
			'widget'  => $widget,
		];
	}

	private function widget_add( array $params, array $context ): array|\WP_Error {
		$widget_type = sanitize_text_field( $params['widget_type'] ?? '' );
		$sidebar_id  = sanitize_text_field( $params['sidebar_id'] ?? '' );
		$settings    = $params['widget_settings'] ?? [];

		if ( '' === $widget_type ) {
			return new \WP_Error( 'wpcc_missing_widget_type', __( 'widget_type is required.', 'wp-command-center' ) );
		}
		if ( '' === $sidebar_id ) {
			return new \WP_Error( 'wpcc_missing_sidebar_id', __( 'sidebar_id is required.', 'wp-command-center' ) );
		}

		global $wp_registered_widgets;
		if ( ! isset( $wp_registered_widgets[ $widget_type ] ) ) {
			$wp_registered_widgets[ $widget_type ] = [
				'name'     => $widget_type,
				'id'       => $widget_type,
				'callback' => [ $this, 'render_widget' ],
				'params'   => [],
			];
		}

		$sidebars_widgets = wp_get_sidebars_widgets();
		if ( ! isset( $sidebars_widgets[ $sidebar_id ] ) ) {
			$sidebars_widgets[ $sidebar_id ] = [];
		}

		$new_widget_id = $widget_type . '-' . ( count( $sidebars_widgets[ $sidebar_id ] ) + 1 );
		$sidebars_widgets[ $sidebar_id ][] = $new_widget_id;

		// Store settings
		$widget_settings_all = get_option( 'widget_' . $widget_type, [] );
		$instance_num = count( $widget_settings_all ) + 1;
		$widget_settings_all[ $instance_num ] = $settings;
		update_option( 'widget_' . $widget_type, $widget_settings_all );

		// Store rollback
		$rollback_id = $this->store_rollback( 'widget_add', [
			'widget_id'   => $new_widget_id,
			'widget_type' => $widget_type,
			'sidebar_id'  => $sidebar_id,
			'instance'    => $instance_num,
		], $context );

		update_option( 'sidebars_widgets', $sidebars_widgets );

		$this->audit( 'widgets.added', [
			'widget_id'   => $new_widget_id,
			'sidebar_id'  => $sidebar_id,
			'rollback_id' => $rollback_id,
		], $context );

		return [
			'action'      => 'widget_add',
			'widget_id'   => $new_widget_id,
			'sidebar_id'  => $sidebar_id,
			'rollback_id' => $rollback_id,
		];
	}

	private function widget_update( array $params, array $context ): array|\WP_Error {
		$widget_id = sanitize_text_field( $params['widget_id'] ?? '' );
		$settings  = $params['widget_settings'] ?? [];

		if ( '' === $widget_id ) {
			return new \WP_Error( 'wpcc_missing_widget_id', __( 'widget_id is required.', 'wp-command-center' ) );
		}

		// Find widget in sidebars to verify existence
		$sidebars_widgets = wp_get_sidebars_widgets();
		$found = false;
		foreach ( $sidebars_widgets as $widgets ) {
			if ( is_array( $widgets ) && in_array( $widget_id, $widgets, true ) ) {
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			return new \WP_Error( 'wpcc_widget_not_found', __( 'Widget not found in any sidebar.', 'wp-command-center' ) );
		}

		// Determine type from widget ID
		preg_match( '/^(.+)-\d+$/', $widget_id, $matches );
		$base_type = $matches[1] ?? $widget_id;

		$old_settings = get_option( 'widget_' . $base_type, [] );

		// Find instance number
		preg_match( '/-(\d+)$/', $widget_id, $num_matches );
		$instance = (int) ( $num_matches[1] ?? 1 );

		$rollback_id = $this->store_rollback( 'widget_update', [
			'widget_id'    => $widget_id,
			'base_type'    => $base_type,
			'instance'     => $instance,
			'old_settings' => $old_settings[ $instance ] ?? [],
		], $context );

		$widget_settings_all = get_option( 'widget_' . $base_type, [] );
		$widget_settings_all[ $instance ] = $settings;
		update_option( 'widget_' . $base_type, $widget_settings_all );

		$this->audit( 'widgets.updated', [
			'widget_id'   => $widget_id,
			'rollback_id' => $rollback_id,
		], $context );

		return [
			'action'      => 'widget_update',
			'widget_id'   => $widget_id,
			'rollback_id' => $rollback_id,
		];
	}

	private function widget_remove( array $params, array $context ): array|\WP_Error {
		$widget_id = sanitize_text_field( $params['widget_id'] ?? '' );

		if ( '' === $widget_id ) {
			return new \WP_Error( 'wpcc_missing_widget_id', __( 'widget_id is required.', 'wp-command-center' ) );
		}

		$sidebars_widgets = wp_get_sidebars_widgets();
		$found_sidebar = null;
		foreach ( $sidebars_widgets as $sid => $widgets ) {
			if ( is_array( $widgets ) && in_array( $widget_id, $widgets, true ) ) {
				$found_sidebar = $sid;
				break;
			}
		}

		if ( null === $found_sidebar ) {
			return new \WP_Error( 'wpcc_widget_not_found', __( 'Widget not found in any sidebar.', 'wp-command-center' ) );
		}

		$rollback_id = $this->store_rollback( 'widget_remove', [
			'widget_id'  => $widget_id,
			'sidebar_id' => $found_sidebar,
		], $context );

		$sidebars_widgets[ $found_sidebar ] = array_values( array_filter(
			$sidebars_widgets[ $found_sidebar ],
			fn( $w ) => $w !== $widget_id
		) );
		update_option( 'sidebars_widgets', $sidebars_widgets );

		$this->audit( 'widgets.removed', [
			'widget_id'   => $widget_id,
			'sidebar_id'  => $found_sidebar,
			'rollback_id' => $rollback_id,
		], $context );

		return [
			'action'      => 'widget_remove',
			'widget_id'   => $widget_id,
			'sidebar_id'  => $found_sidebar,
			'rollback_id' => $rollback_id,
		];
	}

	private function sidebar_assign( array $params, array $context ): array|\WP_Error {
		$widget_id  = sanitize_text_field( $params['widget_id'] ?? '' );
		$sidebar_id = sanitize_text_field( $params['sidebar_id'] ?? '' );

		if ( '' === $widget_id ) {
			return new \WP_Error( 'wpcc_missing_widget_id', __( 'widget_id is required.', 'wp-command-center' ) );
		}
		if ( '' === $sidebar_id ) {
			return new \WP_Error( 'wpcc_missing_sidebar_id', __( 'sidebar_id is required.', 'wp-command-center' ) );
		}

		$sidebars_widgets = wp_get_sidebars_widgets();

		// Remove from current sidebar
		$previous_sidebar = null;
		foreach ( $sidebars_widgets as $sid => $widgets ) {
			if ( is_array( $widgets ) && in_array( $widget_id, $widgets, true ) ) {
				$previous_sidebar = $sid;
				$sidebars_widgets[ $sid ] = array_values( array_filter( $widgets, fn( $w ) => $w !== $widget_id ) );
				break;
			}
		}

		if ( ! isset( $sidebars_widgets[ $sidebar_id ] ) ) {
			$sidebars_widgets[ $sidebar_id ] = [];
		}

		$sidebars_widgets[ $sidebar_id ][] = $widget_id;

		$rollback_id = $this->store_rollback( 'sidebar_assign', [
			'widget_id'         => $widget_id,
			'sidebar_id'        => $sidebar_id,
			'previous_sidebar'  => $previous_sidebar,
		], $context );

		update_option( 'sidebars_widgets', $sidebars_widgets );

		$this->audit( 'widgets.sidebar_assigned', [
			'widget_id'   => $widget_id,
			'sidebar_id'  => $sidebar_id,
			'rollback_id' => $rollback_id,
		], $context );

		return [
			'action'      => 'sidebar_assign',
			'widget_id'   => $widget_id,
			'sidebar_id'  => $sidebar_id,
			'rollback_id' => $rollback_id,
		];
	}

	private function sidebar_remove( array $params, array $context ): array|\WP_Error {
		$widget_id  = sanitize_text_field( $params['widget_id'] ?? '' );
		$sidebar_id = sanitize_text_field( $params['sidebar_id'] ?? '' );

		if ( '' === $widget_id || '' === $sidebar_id ) {
			return new \WP_Error( 'wpcc_missing_params', __( 'widget_id and sidebar_id are required.', 'wp-command-center' ) );
		}

		$sidebars_widgets = wp_get_sidebars_widgets();
		if ( ! isset( $sidebars_widgets[ $sidebar_id ] ) ) {
			return new \WP_Error( 'wpcc_sidebar_not_found', __( 'Sidebar not found.', 'wp-command-center' ) );
		}

		if ( ! in_array( $widget_id, $sidebars_widgets[ $sidebar_id ], true ) ) {
			return new \WP_Error( 'wpcc_widget_not_in_sidebar', __( 'Widget not found in this sidebar.', 'wp-command-center' ) );
		}

		$rollback_id = $this->store_rollback( 'sidebar_remove', [
			'widget_id'  => $widget_id,
			'sidebar_id' => $sidebar_id,
		], $context );

		$sidebars_widgets[ $sidebar_id ] = array_values( array_filter(
			$sidebars_widgets[ $sidebar_id ],
			fn( $w ) => $w !== $widget_id
		) );
		update_option( 'sidebars_widgets', $sidebars_widgets );

		$this->audit( 'widgets.sidebar_removed', [
			'widget_id'   => $widget_id,
			'sidebar_id'  => $sidebar_id,
			'rollback_id' => $rollback_id,
		], $context );

		return [
			'action'      => 'sidebar_remove',
			'widget_id'   => $widget_id,
			'sidebar_id'  => $sidebar_id,
			'rollback_id' => $rollback_id,
		];
	}

	private function widgets_rollback( array $params, array $context ): array|\WP_Error {
		$rollback_id = sanitize_text_field( $params['rollback_id'] ?? '' );
		if ( '' === $rollback_id ) {
			return new \WP_Error( 'wpcc_missing_rollback_id', __( 'rollback_id is required.', 'wp-command-center' ) );
		}

		$records = get_option( 'wpcc_widgets_rollbacks', [] );
		if ( ! isset( $records[ $rollback_id ] ) ) {
			return new \WP_Error( 'wpcc_rollback_not_found', __( 'Rollback record not found.', 'wp-command-center' ) );
		}

		$record = $records[ $rollback_id ];
		if ( ! empty( $record['rollback_applied'] ) ) {
			return new \WP_Error( 'wpcc_rollback_already_applied', __( 'Rollback already applied.', 'wp-command-center' ) );
		}

		$action = $record['action'];
		$sidebars_widgets = wp_get_sidebars_widgets();

		switch ( $action ) {
			case 'widget_add':
				// Remove the widget
				foreach ( $sidebars_widgets as $sid => $widgets ) {
					if ( is_array( $widgets ) && in_array( $record['widget_id'], $widgets, true ) ) {
						$sidebars_widgets[ $sid ] = array_values( array_filter( $widgets, fn( $w ) => $w !== $record['widget_id'] ) );
					}
				}
				update_option( 'sidebars_widgets', $sidebars_widgets );
				break;

			case 'widget_update':
				$widget_settings_all = get_option( 'widget_' . $record['base_type'], [] );
				$widget_settings_all[ $record['instance'] ] = $record['old_settings'];
				update_option( 'widget_' . $record['base_type'], $widget_settings_all );
				break;

			case 'widget_remove':
				if ( ! isset( $sidebars_widgets[ $record['sidebar_id'] ] ) ) {
					$sidebars_widgets[ $record['sidebar_id'] ] = [];
				}
				$sidebars_widgets[ $record['sidebar_id'] ][] = $record['widget_id'];
				update_option( 'sidebars_widgets', $sidebars_widgets );
				break;

			case 'sidebar_assign':
				// Remove from target, restore to previous
				foreach ( $sidebars_widgets as $sid => $widgets ) {
					if ( is_array( $widgets ) && in_array( $record['widget_id'], $widgets, true ) ) {
						$sidebars_widgets[ $sid ] = array_values( array_filter( $widgets, fn( $w ) => $w !== $record['widget_id'] ) );
					}
				}
				if ( $record['previous_sidebar'] ) {
					if ( ! isset( $sidebars_widgets[ $record['previous_sidebar'] ] ) ) {
						$sidebars_widgets[ $record['previous_sidebar'] ] = [];
					}
					$sidebars_widgets[ $record['previous_sidebar'] ][] = $record['widget_id'];
				}
				update_option( 'sidebars_widgets', $sidebars_widgets );
				break;

			case 'sidebar_remove':
				if ( ! isset( $sidebars_widgets[ $record['sidebar_id'] ] ) ) {
					$sidebars_widgets[ $record['sidebar_id'] ] = [];
				}
				$sidebars_widgets[ $record['sidebar_id'] ][] = $record['widget_id'];
				update_option( 'sidebars_widgets', $sidebars_widgets );
				break;

			default:
				return new \WP_Error( 'wpcc_rollback_unsupported', __( 'Rollback not supported for this action.', 'wp-command-center' ) );
		}

		$records[ $rollback_id ]['rollback_applied'] = true;
		update_option( 'wpcc_widgets_rollbacks', $records );

		$this->audit( 'widgets.rollback.applied', [
			'rollback_id' => $rollback_id,
			'action'      => $action,
		], $context );

		return [
			'action'      => 'widgets_rollback',
			'rollback_id' => $rollback_id,
			'restored'    => true,
		];
	}

	public function render_widget(): void {
		echo '';
	}

	private function store_rollback( string $action, array $data, array $context ): string {
		$rid = wp_generate_uuid4();
		$records = get_option( 'wpcc_widgets_rollbacks', [] );
		$records[ $rid ] = array_merge( $data, [
			'id'               => $rid,
			'action'           => $action,
			'rollback_applied' => false,
			'created_at'       => time(),
			'session_id'       => $context['session_id'] ?? '',
			'task_id'          => $context['task_id'] ?? '',
		] );
		update_option( 'wpcc_widgets_rollbacks', $records );
		return $rid;
	}

	public function get_registry(): WidgetsRegistry {
		return $this->registry;
	}
}
