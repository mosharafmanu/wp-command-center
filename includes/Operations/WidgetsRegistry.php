<?php
/**
 * Step 73 — Widgets Registry.
 *
 * Discovers registered widgets and sidebars, provides metadata, risk
 * classification, and allowed operation definitions.
 */

namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class WidgetsRegistry {

	const RISK_LOW      = 'low';
	const RISK_MEDIUM   = 'medium';
	const RISK_HIGH     = 'high';
	const RISK_CRITICAL = 'critical';

	const RISK_LEVELS = [ 'low', 'medium', 'high', 'critical' ];

	const ACTION_WIDGET_LIST      = 'widget_list';
	const ACTION_WIDGET_GET       = 'widget_get';
	const ACTION_WIDGET_ADD       = 'widget_add';
	const ACTION_WIDGET_UPDATE    = 'widget_update';
	const ACTION_WIDGET_REMOVE    = 'widget_remove';
	const ACTION_SIDEBAR_ASSIGN   = 'sidebar_assign';
	const ACTION_SIDEBAR_REMOVE   = 'sidebar_remove';

	const ACTIONS = [ 'widget_list', 'widget_get', 'widget_add', 'widget_update', 'widget_remove', 'sidebar_assign', 'sidebar_remove', 'widgets_rollback' ];

	public function action_risk( string $action ): string {
		return match ( $action ) {
			self::ACTION_WIDGET_LIST    => self::RISK_LOW,
			self::ACTION_WIDGET_GET     => self::RISK_LOW,
			self::ACTION_WIDGET_ADD     => self::RISK_HIGH,
			self::ACTION_WIDGET_UPDATE  => self::RISK_HIGH,
			self::ACTION_WIDGET_REMOVE  => self::RISK_HIGH,
			self::ACTION_SIDEBAR_ASSIGN => self::RISK_HIGH,
			self::ACTION_SIDEBAR_REMOVE => self::RISK_HIGH,
			default                      => self::RISK_HIGH,
		};
	}

	public function requires_approval( string $action ): bool {
		return ! in_array( $action, [ self::ACTION_WIDGET_LIST, self::ACTION_WIDGET_GET ], true );
	}

	public function get_widgets(): array {
		global $wp_registered_widgets;

		$sidebars_widgets = wp_get_sidebars_widgets();
		$result = [];

		foreach ( $wp_registered_widgets as $widget_id => $widget ) {
			$sidebar = null;
			foreach ( $sidebars_widgets as $sidebar_id => $widgets ) {
				if ( is_array( $widgets ) && in_array( $widget_id, $widgets, true ) ) {
					$sidebar = $sidebar_id;
					break;
				}
			}

			$result[] = [
				'widget_id'   => $widget_id,
				'name'        => $widget['name'] ?? $widget_id,
				'class'       => $widget['callback'][0] ? get_class( $widget['callback'][0] ) : null,
				'sidebar'     => $sidebar,
				'params'      => $widget['params'] ?? [],
			];
		}

		return $result;
	}

	public function get_widget( string $widget_id ): ?array {
		foreach ( $this->get_widgets() as $w ) {
			if ( $w['widget_id'] === $widget_id ) {
				return $w;
			}
		}
		return null;
	}

	public function get_sidebars(): array {
		global $wp_registered_sidebars;
		$sidebars_widgets = wp_get_sidebars_widgets();
		$result = [];

		foreach ( $wp_registered_sidebars as $sidebar_id => $sidebar ) {
			$result[] = [
				'sidebar_id' => $sidebar_id,
				'name'       => $sidebar['name'] ?? $sidebar_id,
				'widgets'    => $sidebars_widgets[ $sidebar_id ] ?? [],
				'widget_count' => count( $sidebars_widgets[ $sidebar_id ] ?? [] ),
			];
		}

		// Include inactive widgets
		if ( ! empty( $sidebars_widgets['wp_inactive_widgets'] ) ) {
			$result[] = [
				'sidebar_id'   => 'wp_inactive_widgets',
				'name'         => __( 'Inactive Widgets', 'wp-command-center' ),
				'widgets'      => $sidebars_widgets['wp_inactive_widgets'],
				'widget_count' => count( $sidebars_widgets['wp_inactive_widgets'] ),
			];
		}

		return $result;
	}

	public function get_sidebar( string $sidebar_id ): ?array {
		foreach ( $this->get_sidebars() as $s ) {
			if ( $s['sidebar_id'] === $sidebar_id ) {
				return $s;
			}
		}
		return null;
	}

	public function get_summary(): array {
		return [
			'widgets'  => $this->get_widgets(),
			'sidebars' => $this->get_sidebars(),
			'total_widgets'   => count( $this->get_widgets() ),
			'total_sidebars'  => count( $this->get_sidebars() ),
		];
	}

	public function count_by_risk(): array {
		$counts = [ 'low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0 ];
		foreach ( self::ACTIONS as $action ) {
			if ( 'widgets_rollback' === $action ) {
				continue;
			}
			$risk = $this->action_risk( $action );
			if ( isset( $counts[ $risk ] ) ) {
				$counts[ $risk ]++;
			}
		}
		return $counts;
	}

	public function get_operations_definition(): array {
		return [
			self::ACTION_WIDGET_LIST    => [ 'name' => 'List Widgets', 'description' => 'List all registered widgets and their sidebar assignments.' ],
			self::ACTION_WIDGET_GET     => [ 'name' => 'Get Widget', 'description' => 'Get details for a specific widget.' ],
			self::ACTION_WIDGET_ADD     => [ 'name' => 'Add Widget', 'description' => 'Add a new widget instance to a sidebar.' ],
			self::ACTION_WIDGET_UPDATE  => [ 'name' => 'Update Widget', 'description' => 'Update a widget instance settings.' ],
			self::ACTION_WIDGET_REMOVE  => [ 'name' => 'Remove Widget', 'description' => 'Remove a widget instance from a sidebar.' ],
			self::ACTION_SIDEBAR_ASSIGN => [ 'name' => 'Assign to Sidebar', 'description' => 'Assign a widget to a sidebar.' ],
			self::ACTION_SIDEBAR_REMOVE => [ 'name' => 'Remove from Sidebar', 'description' => 'Remove a widget from its sidebar.' ],
		];
	}
}
