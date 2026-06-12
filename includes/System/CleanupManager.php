<?php
/** Step 34 - guarded cleanup of terminal runtime records. */
namespace WPCommandCenter\System;

use WPCommandCenter\Security\AuditLog;

defined( 'ABSPATH' ) || exit;

final class CleanupManager {
	public const RESOURCES = [ 'sessions', 'tasks', 'actions', 'plans', 'queue_items', 'recommendations' ];

	public function cleanup( array $params, array $actor = [] ): array|\WP_Error {
		$mode      = ( new EnvironmentManager() )->get();
		$dry_run   = ! isset( $params['dry_run'] ) || rest_sanitize_boolean( $params['dry_run'] );
		$days      = max( 1, min( 3650, (int) ( $params['older_than_days'] ?? 30 ) ) );
		$resources = array_values( array_intersect( self::RESOURCES, array_map( 'sanitize_key', (array) ( $params['resources'] ?? self::RESOURCES ) ) ) );
		$confirm   = (string) ( $params['confirm'] ?? '' );
		$audit     = new AuditLog();

		if ( empty( $resources ) ) {
			return new \WP_Error( 'wpcc_invalid_cleanup_resources', __( 'Select at least one cleanup resource.', 'wp-command-center' ) );
		}
		if ( ! $dry_run && 'production' === $mode && ( empty( $params['allow_production'] ) || 'DELETE PRODUCTION DATA' !== $confirm ) ) {
			$audit->record( 'system.cleanup.blocked', [ 'environment' => $mode, 'reason' => 'production_confirmation_required', 'actor' => AuditLog::resolve_actor( $actor ) ] );
			return new \WP_Error( 'wpcc_production_cleanup_blocked', __( 'Production cleanup requires allow_production=true and the exact confirmation phrase.', 'wp-command-center' ) );
		}
		if ( ! $dry_run && 'production' !== $mode && 'CLEANUP' !== $confirm ) {
			return new \WP_Error( 'wpcc_cleanup_confirmation_required', __( 'Live cleanup requires the confirmation phrase CLEANUP.', 'wp-command-center' ) );
		}

		$cutoff = time() - ( $days * DAY_IN_SECONDS );
		$audit->record( 'system.cleanup.started', [ 'environment' => $mode, 'dry_run' => $dry_run, 'older_than_days' => $days, 'resources' => $resources, 'actor' => AuditLog::resolve_actor( $actor ) ] );
		$results = [];
		foreach ( $resources as $resource ) {
			$results[ $resource ] = $this->process_resource( $resource, $cutoff, $dry_run );
		}
		$total = array_sum( array_column( $results, $dry_run ? 'eligible' : 'deleted' ) );
		$response = [ 'environment' => $mode, 'dry_run' => $dry_run, 'older_than_days' => $days, 'cutoff' => $cutoff, 'resources' => $results, $dry_run ? 'total_eligible' : 'total_deleted' => $total ];
		$audit->record( 'system.cleanup.completed', $response + [ 'actor' => AuditLog::resolve_actor( $actor ) ] );
		return $response;
	}

	private function process_resource( string $resource, int $cutoff, bool $dry_run ): array {
		global $wpdb;
		$config = [
			'sessions'       => [ "{$wpdb->prefix}wpcc_agent_sessions", 'updated_at', [ 'closed', 'expired' ] ],
			'tasks'          => [ "{$wpdb->prefix}wpcc_agent_tasks", 'updated_at', [ 'completed', 'failed', 'cancelled' ] ],
			'actions'        => [ "{$wpdb->prefix}wpcc_agent_actions", 'updated_at', [ 'rejected', 'completed', 'cancelled' ] ],
			'plans'          => [ "{$wpdb->prefix}wpcc_agent_plans", 'updated_at', [ 'rejected', 'superseded', 'cancelled' ] ],
			'queue_items'    => [ "{$wpdb->prefix}wpcc_operation_queue", 'created_at', [ 'completed', 'failed', 'cancelled' ] ],
			'recommendations'=> [ "{$wpdb->prefix}wpcc_recommendations", 'updated_at', [ 'resolved', 'dismissed' ] ],
		];
		[ $table, $time_field, $statuses ] = $config[ $resource ];
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status IN ({$placeholders}) AND {$time_field} < %d", ...array_merge( $statuses, [ $cutoff ] ) );
		$eligible = (int) $wpdb->get_var( $sql );
		if ( $dry_run || 0 === $eligible ) {
			return [ 'eligible' => $eligible, 'deleted' => 0 ];
		}
		$delete = $wpdb->prepare( "DELETE FROM {$table} WHERE status IN ({$placeholders}) AND {$time_field} < %d", ...array_merge( $statuses, [ $cutoff ] ) );
		$deleted = (int) $wpdb->query( $delete );
		return [ 'eligible' => $eligible, 'deleted' => max( 0, $deleted ) ];
	}
}
