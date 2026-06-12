<?php
/**
 * Step 20 — Operation Approval Gate.
 *
 * Manages the lifecycle of operation requests: creation, review, and execution.
 */

namespace WPCommandCenter\Operations;

use WPCommandCenter\Security\AuditLog;
use WPCommandCenter\Operations\OperationExecutor;
use WPCommandCenter\Recommendations\RecommendationEngine;

defined( 'ABSPATH' ) || exit;

final class OperationManager {

	public const STATUS_PENDING_REVIEW = 'pending_review';
	public const STATUS_APPROVED       = 'approved';
	public const STATUS_REJECTED       = 'rejected';
	public const STATUS_EXECUTED       = 'executed';
	public const STATUS_FAILED         = 'failed';
	public const STATUS_CANCELLED      = 'cancelled';

	/**
	 * Create a new operation request.
	 *
	 * @param string $operation_id
	 * @param array  $payload
	 * @param array  $meta {session_id, task_id, action_id, plan_id, actor}
	 * @return array|\WP_Error
	 */
	public function create_request( string $operation_id, array $payload, array $meta ): array|\WP_Error {
		global $wpdb;

		$registry  = new OperationRegistry();
		$operation = $registry->get_operation( $operation_id );

		if ( ! $operation ) {
			return new \WP_Error( 'wpcc_operation_not_found', __( 'Operation not found in registry.', 'wp-command-center' ) );
		}

		$request_id = wp_generate_uuid4();
		$created_at = time();

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'wpcc_operation_requests',
			[
				'request_id'   => $request_id,
				'operation_id' => $operation_id,
				'session_id'   => $meta['session_id'] ?? null,
				'task_id'      => $meta['task_id'] ?? null,
				'action_id'    => $meta['action_id'] ?? null,
				'plan_id'      => $meta['plan_id'] ?? null,
				'status'       => self::STATUS_PENDING_REVIEW,
				'payload'      => wp_json_encode( $payload ),
				'risk_level'   => $operation['risk_level'] ?? 'medium',
				'created_at'   => $created_at,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ]
		);

		if ( false === $inserted ) {
			return new \WP_Error( 'wpcc_request_create_failed', __( 'Failed to create operation request.', 'wp-command-center' ) );
		}

		return $this->get_request( $request_id );
	}

	/**
	 * Approve a pending request and automatically queue it.
	 */
	public function approve_request( string $request_id, array $context = [] ): bool|\WP_Error {
		$approved = $this->update_status( $request_id, self::STATUS_APPROVED, 'approved_at' );
		if ( is_wp_error( $approved ) || ! $approved ) {
			return $approved;
		}

		// Automatically move to queue
		$queue = new OperationQueue();
		$queue->enqueue( $request_id, 10, $context );

		return true;
	}

	/**
	 * Reject a pending request.
	 */
	public function reject_request( string $request_id ): bool|\WP_Error {
		return $this->update_status( $request_id, self::STATUS_REJECTED, 'rejected_at' );
	}

	/**
	 * Cancel a request.
	 */
	public function cancel_request( string $request_id ): bool|\WP_Error {
		return $this->update_status( $request_id, self::STATUS_CANCELLED );
	}

	/**
	 * Execute an approved request.
	 */
	public function execute_request( string $request_id, array $actor = [] ): array|\WP_Error {
		global $wpdb;

		$request = $this->get_request( $request_id );
		if ( ! $request ) {
			return new \WP_Error( 'wpcc_request_not_found', __( 'Operation request not found.', 'wp-command-center' ) );
		}

		if ( self::STATUS_APPROVED !== $request['status'] ) {
			return new \WP_Error( 'wpcc_request_not_approved', __( 'Only approved requests can be executed.', 'wp-command-center' ) );
		}

		$payload = json_decode( $request['payload'], true ) ?: [];
		$context = [
			'session_id' => $request['session_id'],
			'task_id'    => $request['task_id'],
			'action_id'  => $request['action_id'],
			'plan_id'    => $request['plan_id'],
			'request_id' => $request_id,
		];

		$executor = new OperationExecutor();
		if ( ! empty( $request['plan_id'] ) ) {
			( new RecommendationEngine() )->sync_plan_status( $request['plan_id'], 'executing', $actor );
		}
		$result   = $executor->run( $request['operation_id'], $payload, $context );

		if ( ! $result['success'] ) {
			$error = $result['errors'][0] ?? [ 'code' => 'execution_failed', 'message' => 'Unknown error' ];
			$wpdb->update(
				$wpdb->prefix . 'wpcc_operation_requests',
				[ 'status' => self::STATUS_FAILED, 'failed_at' => time() ],
				[ 'request_id' => $request_id ],
				[ '%s', '%d' ],
				[ '%s' ]
			);
			return new \WP_Error( $error['code'], $error['message'] );
		}

		$wpdb->update(
			$wpdb->prefix . 'wpcc_operation_requests',
			[ 'status' => self::STATUS_EXECUTED, 'executed_at' => time() ],
			[ 'request_id' => $request_id ],
			[ '%s', '%d' ],
			[ '%s' ]
		);

		if ( ! empty( $request['plan_id'] ) ) {
			( new RecommendationEngine() )->sync_plan_status( $request['plan_id'], 'resolved', $actor );
		}

		return $result;
	}

	public function get_request( string $request_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcc_operation_requests WHERE request_id = %s", $request_id ), ARRAY_A );
		return $row ?: null;
	}

	public function list_requests( array $filters = [] ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcc_operation_requests';
		$sql   = "SELECT * FROM {$table}";
		$where = [];
		$params = [];

		foreach ( [ 'session_id', 'task_id', 'plan_id', 'status', 'operation_id' ] as $key ) {
			if ( ! empty( $filters[ $key ] ) ) {
				$where[]  = "{$key} = %s";
				$params[] = $filters[ $key ];
			}
		}

		if ( ! empty( $where ) ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}

		$sql .= ' ORDER BY id DESC';

		$limit  = isset( $filters['limit'] ) ? max( 1, (int) $filters['limit'] ) : 50;
		$offset = isset( $filters['offset'] ) ? max( 0, (int) $filters['offset'] ) : 0;
		$sql   .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $limit, $offset );

		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, ...$params );
		}

		return $wpdb->get_results( $sql, ARRAY_A ) ?: [];
	}

	private function update_status( string $request_id, string $status, ?string $timestamp_field = null ): bool|\WP_Error {
		global $wpdb;

		$data = [ 'status' => $status ];
		if ( $timestamp_field ) {
			$data[ $timestamp_field ] = time();
		}

		$updated = $wpdb->update(
			$wpdb->prefix . 'wpcc_operation_requests',
			$data,
			[ 'request_id' => $request_id, 'status' => self::STATUS_PENDING_REVIEW ],
			[ '%s', '%d' ],
			[ '%s', '%s' ]
		);

		if ( 0 === $updated ) {
			// Check if it already has the status or if it wasn't pending
			$request = $this->get_request( $request_id );
			if ( ! $request ) {
				return new \WP_Error( 'wpcc_request_not_found', __( 'Operation request not found.', 'wp-command-center' ) );
			}
			if ( $request['status'] === $status ) {
				return true;
			}
			return new \WP_Error( 'wpcc_invalid_transition', sprintf( __( 'Cannot transition request from %s to %s.', 'wp-command-center' ), $request['status'], $status ) );
		}

		return true;
	}
}
