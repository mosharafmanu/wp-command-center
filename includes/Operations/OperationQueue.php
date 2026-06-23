<?php
/**
 * Step 22 — Operation Queue.
 *
 * Manages the lifecycle of queued operation tasks: enqueuing, execution, and monitoring.
 */

namespace WPCommandCenter\Operations;

use WPCommandCenter\Security\AuditLog;
use WPCommandCenter\Recommendations\RecommendationEngine;

defined( 'ABSPATH' ) || exit;

final class OperationQueue {

	public const STATUS_QUEUED    = 'queued';
	public const STATUS_RUNNING   = 'running';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_FAILED    = 'failed';
	public const STATUS_CANCELLED = 'cancelled';

	/**
	 * Enqueue an approved operation request.
	 *
	 * @param string $request_id
	 * @param int    $priority
	 * @return array|\WP_Error
	 */
	public function enqueue( string $request_id, int $priority = 10, array $context = [] ): array|\WP_Error {
		global $wpdb;

		$manager = new OperationManager();
		$request = $manager->get_request( $request_id );

		if ( ! $request ) {
			return new \WP_Error( 'wpcc_request_not_found', __( 'Operation request not found.', 'wp-command-center' ) );
		}

		if ( OperationManager::STATUS_APPROVED !== $request['status'] ) {
			return new \WP_Error( 'wpcc_request_not_approved', __( 'Only approved requests can be queued.', 'wp-command-center' ) );
		}

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wpcc_operation_queue WHERE request_id = %s AND status IN (%s, %s) ORDER BY id DESC LIMIT 1",
				$request_id,
				self::STATUS_QUEUED,
				self::STATUS_RUNNING
			),
			ARRAY_A
		);

		if ( $existing ) {
			return $this->normalize_item( $existing );
		}

		$queue_id   = wp_generate_uuid4();
		$created_at = time();

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'wpcc_operation_queue',
			[
				'queue_id'     => $queue_id,
				'request_id'   => $request_id,
				'operation_id' => $request['operation_id'],
				'status'       => self::STATUS_QUEUED,
				'priority'     => $priority,
				'payload'      => $request['payload'],
				'created_at'   => $created_at,
			],
			[ '%s', '%s', '%s', '%s', '%d', '%s', '%d' ]
		);

		if ( false === $inserted ) {
			return new \WP_Error( 'wpcc_queue_create_failed', __( 'Failed to create queue item.', 'wp-command-center' ) );
		}

		( new AuditLog() )->record( 'operation.queue.created', [
			'queue_id'     => $queue_id,
			'request_id'   => $request_id,
			'operation_id' => $request['operation_id'],
			'session_id'   => $request['session_id'] ?? null,
			'task_id'      => $request['task_id'] ?? null,
			'action_id'    => $request['action_id'] ?? null,
			'plan_id'      => $request['plan_id'] ?? null,
			'actor'        => ! empty( $context['actor'] ) ? AuditLog::resolve_actor( $context['actor'] ) : null,
		] );

		return $this->get_item( $queue_id );
	}

	/**
	 * Run a queued operation manually.
	 */
	public function run_item( string $queue_id, array $context = [] ): array|\WP_Error {
		global $wpdb;

		$item = $this->get_item( $queue_id );
		if ( ! $item ) {
			return new \WP_Error( 'wpcc_queue_item_not_found', __( 'Queue item not found.', 'wp-command-center' ) );
		}

		if ( self::STATUS_QUEUED !== $item['status'] && self::STATUS_FAILED !== $item['status'] ) {
			return new \WP_Error( 'wpcc_invalid_queue_status', sprintf( __( 'Cannot run queue item in status %s.', 'wp-command-center' ), $item['status'] ) );
		}

		// B2-2 execute-once (queue path). Do not run a queue item whose request was
		// already executed synchronously (request EXECUTED) or is no longer eligible
		// (REJECTED/CANCELLED). This is the leftover item the admin approve+execute
		// path enqueues but never runs. Supersede it so the worker cannot duplicate
		// the execution. A request in 'approved' (normal worker path) or 'failed'
		// (retry) state is still allowed to run.
		$request = ( new OperationManager() )->get_request( $item['request_id'] );
		if ( $request && in_array( $request['status'], [ OperationManager::STATUS_EXECUTED, OperationManager::STATUS_REJECTED, OperationManager::STATUS_CANCELLED ], true ) ) {
			$wpdb->update(
				$wpdb->prefix . 'wpcc_operation_queue',
				[ 'status' => self::STATUS_CANCELLED ],
				[ 'queue_id' => $queue_id ],
				[ '%s' ],
				[ '%s' ]
			);
			( new AuditLog() )->record( 'operation.execution.duplicate_suppressed', [
				'request_id'   => $item['request_id'],
				'operation_id' => $item['operation_id'],
				'queue_id'     => $queue_id,
				'path'         => 'queue',
				'reason'       => 'request_' . $request['status'],
			] );
			return new \WP_Error( 'wpcc_request_already_terminal', sprintf( __( 'Skipped queue item: request is already %s.', 'wp-command-center' ), $request['status'] ) );
		}

		// Mark as running
		$wpdb->update(
			$wpdb->prefix . 'wpcc_operation_queue',
			[ 'status' => self::STATUS_RUNNING, 'started_at' => time(), 'attempts' => (int) $item['attempts'] + 1 ],
			[ 'queue_id' => $queue_id ],
			[ '%s', '%d', '%d' ],
			[ '%s' ]
		);

		// OperationExecutor needs encoded payload if we are using standardized handlers, 
		// but handlers expect array. normalize_item decoded it.
		$payload = $item['payload']; 
		$request = ( new OperationManager() )->get_request( $item['request_id'] );
		$context = array_merge( $context, [
			'queue_id'   => $queue_id,
			'request_id' => $item['request_id'],
			'session_id' => $request['session_id'] ?? null,
			'task_id'    => $request['task_id'] ?? null,
			'action_id'  => $request['action_id'] ?? null,
			'plan_id'    => $request['plan_id'] ?? null,
		] );

		// STEP 105.5 — queued items run with no human/token actor. Tag the
		// execution so the change log records a descriptive system actor (a cron
		// run already set system_via='cron'; a direct run defaults to 'queue')
		// rather than "unknown".
		if ( empty( $context['actor'] ) && empty( $context['system_via'] ) ) {
			$context['system_via'] = 'queue';
		}

		if ( ! empty( $request['plan_id'] ) ) {
			( new RecommendationEngine() )->sync_plan_status( $request['plan_id'], 'executing', $context['actor'] ?? [] );
		}

		$executor = new OperationExecutor();
		$result   = $executor->run( $item['operation_id'], $payload, $context );

		$data = [
			'result' => wp_json_encode( $result ),
		];

		if ( $result['success'] ) {
			$data['status']       = self::STATUS_COMPLETED;
			$data['completed_at'] = time();
		} else {
			$data['status']        = self::STATUS_FAILED;
			$data['failed_at']     = time();
			$data['error_message'] = $result['errors'][0]['message'] ?? 'Unknown error';
		}

		$wpdb->update(
			$wpdb->prefix . 'wpcc_operation_queue',
			$data,
			[ 'queue_id' => $queue_id ],
			[ '%s', '%s', '%d', '%s' ],
			[ '%s' ]
		);

		if ( $result['success'] && ! empty( $request['plan_id'] ) ) {
			( new RecommendationEngine() )->sync_plan_status( $request['plan_id'], 'resolved', $context['actor'] ?? [] );
		}

		return $this->get_item( $queue_id );
	}

	/**
	 * Cancel a queued item.
	 */
	public function cancel_item( string $queue_id ): bool|\WP_Error {
		global $wpdb;

		$item = $this->get_item( $queue_id );
		if ( ! $item ) {
			return new \WP_Error( 'wpcc_queue_item_not_found', __( 'Queue item not found.', 'wp-command-center' ) );
		}

		if ( ! in_array( $item['status'], [ self::STATUS_QUEUED, self::STATUS_FAILED ], true ) ) {
			return new \WP_Error( 'wpcc_cannot_cancel', sprintf( __( 'Cannot cancel queue item in status %s.', 'wp-command-center' ), $item['status'] ) );
		}

		$updated = $wpdb->update(
			$wpdb->prefix . 'wpcc_operation_queue',
			[ 'status' => self::STATUS_CANCELLED ],
			[ 'queue_id' => $queue_id ],
			[ '%s' ],
			[ '%s' ]
		);

		return $updated > 0;
	}

	/**
	 * Retry a failed queue item.
	 */
	public function retry_item( string $queue_id ): array|\WP_Error {
		global $wpdb;

		$item = $this->get_item( $queue_id );
		if ( ! $item ) {
			return new \WP_Error( 'wpcc_queue_item_not_found', __( 'Queue item not found.', 'wp-command-center' ) );
		}

		if ( self::STATUS_FAILED !== $item['status'] ) {
			return new \WP_Error( 'wpcc_cannot_retry', sprintf( __( 'Cannot retry queue item in status %s.', 'wp-command-center' ), $item['status'] ) );
		}

		if ( (int) $item['attempts'] >= (int) $item['max_attempts'] ) {
			return new \WP_Error( 'wpcc_max_attempts_reached', __( 'Maximum retry attempts reached.', 'wp-command-center' ) );
		}

		$updated = $wpdb->update(
			$wpdb->prefix . 'wpcc_operation_queue',
			[ 'status' => self::STATUS_QUEUED ],
			[ 'queue_id' => $queue_id ],
			[ '%s' ],
			[ '%s' ]
		);

		if ( false === $updated ) {
			return new \WP_Error( 'wpcc_queue_update_failed', __( 'Failed to queue the retry.', 'wp-command-center' ) );
		}

		return $this->get_item( $queue_id );
	}

	public function get_item( string $queue_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcc_operation_queue WHERE queue_id = %s", $queue_id ), ARRAY_A );
		return $row ? $this->normalize_item( $row ) : null;
	}

	public function list_items( array $filters = [] ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcc_operation_queue';
		$sql   = "SELECT * FROM {$table}";
		$where = [];
		$params = [];

		foreach ( [ 'status', 'operation_id', 'request_id' ] as $key ) {
			if ( ! empty( $filters[ $key ] ) ) {
				$where[]  = "{$key} = %s";
				$params[] = $filters[ $key ];
			}
		}

		if ( ! empty( $where ) ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}

		$sql .= ' ORDER BY priority ASC, created_at DESC';

		$limit  = isset( $filters['limit'] ) ? max( 1, (int) $filters['limit'] ) : 50;
		$offset = isset( $filters['offset'] ) ? max( 0, (int) $filters['offset'] ) : 0;
		$sql   .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $limit, $offset );

		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, ...$params );
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return array_map( [ $this, 'normalize_item' ], $rows ?: [] );
	}

	private function normalize_item( array $item ): array {
		$item['payload'] = json_decode( $item['payload'], true ) ?: [];
		$item['result']  = isset( $item['result'] ) ? json_decode( $item['result'], true ) : null;
		$item['priority'] = (int) $item['priority'];
		$item['attempts'] = (int) $item['attempts'];
		$item['max_attempts'] = (int) $item['max_attempts'];
		$item['created_at'] = (int) $item['created_at'];
		$item['started_at'] = $item['started_at'] ? (int) $item['started_at'] : null;
		$item['completed_at'] = $item['completed_at'] ? (int) $item['completed_at'] : null;
		$item['failed_at'] = $item['failed_at'] ? (int) $item['failed_at'] : null;

		return $item;
	}
}
