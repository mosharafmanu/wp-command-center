<?php
/**
 * Step 78 — MCP Approval Runtime.
 *
 * Thin handler exposing the existing request -> approve -> execute -> queue
 * pipeline (OperationManager, OperationQueue, OperationResults) as a single
 * MCP tool, so MCP clients can satisfy the wpcc_enforce_approval gate
 * (context.request_id / context.queue_id) without REST/DB/SSH access.
 */

namespace WPCommandCenter\Operations;

use WPCommandCenter\Security\AuditLog;

defined( 'ABSPATH' ) || exit;

final class ApprovalRuntimeManager {

	private AuditLog $audit;

	public function __construct() {
		$this->audit = new AuditLog();
	}

	public function run( array $p, array $cx = [] ): array|\WP_Error {
		$action = (string) ( $p['action'] ?? '' );
		if ( ! in_array( $action, ApprovalRegistry::ACTIONS, true ) ) {
			return new \WP_Error( 'wpcc_invalid_approval_action', __( 'Invalid approval action.', 'wp-command-center' ) );
		}

		$actor = $cx['actor'] ?? [];

		// Step 80 — Human-approver guard. In Client and Enterprise modes, approvals,
		// rejections, and queue execution must come from a WordPress administrator
		// (WP_User actor via WP Admin REST), not from an API token. This prevents
		// an AI agent from self-approving its own operation requests.
		$human_only = [
			ApprovalRegistry::A_REQUEST_APPROVE,
			ApprovalRegistry::A_REQUEST_REJECT,
			ApprovalRegistry::A_QUEUE_RUN,
			ApprovalRegistry::A_QUEUE_RETRY,
		];
		if ( in_array( $action, $human_only, true ) && SecurityModeManager::requires_human_approver() ) {
			$wp_user_id = (int) ( $actor['wp_user_id'] ?? 0 );
			if ( $wp_user_id <= 0 ) {
				return new \WP_Error(
					'wpcc_approval_requires_human',
					sprintf(
						/* translators: %s: security mode label */
						__( 'Approvals must be granted by a WordPress administrator in %s. Use the WordPress admin approval interface to approve or reject this request.', 'wp-command-center' ),
						SecurityModeManager::label()
					)
				);
			}
		}

		return match ( $action ) {
			ApprovalRegistry::A_REQUEST_CREATE  => $this->request_create( $p, $actor ),
			ApprovalRegistry::A_REQUEST_LIST    => [
				'action'   => $action,
				'requests' => ( new OperationManager() )->list_requests( $this->list_filters( $p, [ 'status', 'operation_id', 'session_id', 'task_id', 'plan_id', 'limit', 'offset' ] ) ),
			],
			ApprovalRegistry::A_REQUEST_GET     => $this->request_get( $p ),
			ApprovalRegistry::A_REQUEST_APPROVE => $this->request_approve( $p, $actor ),
			ApprovalRegistry::A_REQUEST_REJECT  => $this->request_reject( $p, $actor ),
			ApprovalRegistry::A_REQUEST_CANCEL  => $this->request_cancel( $p, $actor ),
			ApprovalRegistry::A_QUEUE_LIST      => [
				'action' => $action,
				'items'  => ( new OperationQueue() )->list_items( $this->list_filters( $p, [ 'status', 'operation_id', 'request_id', 'limit', 'offset' ] ) ),
			],
			ApprovalRegistry::A_QUEUE_GET       => $this->queue_get( $p ),
			ApprovalRegistry::A_QUEUE_RUN       => $this->queue_run( $p, $actor ),
			ApprovalRegistry::A_QUEUE_CANCEL    => $this->queue_cancel( $p, $actor ),
			ApprovalRegistry::A_QUEUE_RETRY     => $this->queue_retry( $p, $actor ),
			ApprovalRegistry::A_RESULTS_LIST    => [
				'action'  => $action,
				'results' => ( new OperationResults() )->list_results( $this->list_filters( $p, [ 'operation_id', 'queue_id', 'request_id', 'status', 'limit', 'offset' ] ) ),
			],
			ApprovalRegistry::A_RESULTS_GET     => $this->results_get( $p ),
		};
	}

	// ── Requests ──

	private function request_create( array $p, array $actor ): array|\WP_Error {
		$operation_id = sanitize_key( (string) ( $p['operation_id'] ?? '' ) );
		if ( '' === $operation_id ) {
			return new \WP_Error( 'wpcc_missing_operation_id', __( 'operation_id is required.', 'wp-command-center' ) );
		}

		$payload = (array) ( $p['payload'] ?? [] );
		$meta    = [
			'session_id' => null,
			'task_id'    => null,
			'action_id'  => null,
			'plan_id'    => null,
			'actor'      => $actor,
		];

		$request = ( new OperationManager() )->create_request( $operation_id, $payload, $meta );
		if ( is_wp_error( $request ) ) {
			return $request;
		}

		$this->audit->record( 'operation.request.created', [
			'request_id'   => $request['request_id'],
			'operation_id' => $operation_id,
			'risk_level'   => $request['risk_level'],
			'actor'        => AuditLog::resolve_actor( $actor ),
		] );

		return [ 'action' => ApprovalRegistry::A_REQUEST_CREATE, 'request' => $request ];
	}

	private function request_get( array $p ): array|\WP_Error {
		$id  = (string) ( $p['request_id'] ?? '' );
		$row = $this->require_request( $id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		return [ 'action' => ApprovalRegistry::A_REQUEST_GET, 'request' => $row ];
	}

	private function request_approve( array $p, array $actor ): array|\WP_Error {
		$id  = (string) ( $p['request_id'] ?? '' );
		$row = $this->require_request( $id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$manager = new OperationManager();
		$result  = $manager->approve_request( $id, [ 'actor' => $actor ] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->audit->record( 'operation.request.approved', [
			'request_id'   => $id,
			'operation_id' => $row['operation_id'],
			'actor'        => AuditLog::resolve_actor( $actor ),
		] );

		// approve_request() auto-enqueues; surface the resulting queue item
		// so the agent can pass its queue_id straight to queue_run.
		$queue_items = ( new OperationQueue() )->list_items( [ 'request_id' => $id, 'limit' => 1 ] );

		return [
			'action'     => ApprovalRegistry::A_REQUEST_APPROVE,
			'request_id' => $id,
			'status'     => OperationManager::STATUS_APPROVED,
			'queue_item' => $queue_items[0] ?? null,
		];
	}

	private function request_reject( array $p, array $actor ): array|\WP_Error {
		$id  = (string) ( $p['request_id'] ?? '' );
		$row = $this->require_request( $id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$result = ( new OperationManager() )->reject_request( $id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->audit->record( 'operation.request.rejected', [
			'request_id'   => $id,
			'operation_id' => $row['operation_id'],
			'actor'        => AuditLog::resolve_actor( $actor ),
		] );

		return [ 'action' => ApprovalRegistry::A_REQUEST_REJECT, 'request_id' => $id, 'status' => OperationManager::STATUS_REJECTED ];
	}

	private function request_cancel( array $p, array $actor ): array|\WP_Error {
		$id  = (string) ( $p['request_id'] ?? '' );
		$row = $this->require_request( $id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$result = ( new OperationManager() )->cancel_request( $id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->audit->record( 'operation.request.cancelled', [
			'request_id'   => $id,
			'operation_id' => $row['operation_id'],
			'actor'        => AuditLog::resolve_actor( $actor ),
		] );

		return [ 'action' => ApprovalRegistry::A_REQUEST_CANCEL, 'request_id' => $id, 'status' => OperationManager::STATUS_CANCELLED ];
	}

	private function require_request( string $id ): array|\WP_Error {
		if ( '' === $id ) {
			return new \WP_Error( 'wpcc_missing_request_id', __( 'request_id is required.', 'wp-command-center' ) );
		}
		$row = ( new OperationManager() )->get_request( $id );
		if ( ! $row ) {
			return new \WP_Error( 'wpcc_request_not_found', __( 'Operation request not found.', 'wp-command-center' ) );
		}
		return $row;
	}

	// ── Queue ──

	private function queue_get( array $p ): array|\WP_Error {
		$id   = (string) ( $p['queue_id'] ?? '' );
		$item = $this->require_queue_item( $id );
		if ( is_wp_error( $item ) ) {
			return $item;
		}
		return [ 'action' => ApprovalRegistry::A_QUEUE_GET, 'item' => $item ];
	}

	private function queue_run( array $p, array $actor ): array|\WP_Error {
		$id   = (string) ( $p['queue_id'] ?? '' );
		$item = $this->require_queue_item( $id );
		if ( is_wp_error( $item ) ) {
			return $item;
		}

		$this->audit->record( 'operation.queue.running', [
			'queue_id'     => $id,
			'request_id'   => $item['request_id'],
			'operation_id' => $item['operation_id'],
			'actor'        => AuditLog::resolve_actor( $actor ),
		] );

		$result = ( new OperationQueue() )->run_item( $id, [ 'actor' => $actor ] );
		if ( is_wp_error( $result ) ) {
			$this->audit->record( 'operation.queue.failed', [
				'queue_id'   => $id,
				'request_id' => $item['request_id'],
				'error'      => $result->get_error_code(),
				'actor'      => AuditLog::resolve_actor( $actor ),
			] );
			return $result;
		}

		$this->audit->record( 'operation.queue.completed', [
			'queue_id'     => $id,
			'request_id'   => $item['request_id'],
			'operation_id' => $item['operation_id'],
			'actor'        => AuditLog::resolve_actor( $actor ),
		] );

		return [ 'action' => ApprovalRegistry::A_QUEUE_RUN, 'item' => $result ];
	}

	private function queue_cancel( array $p, array $actor ): array|\WP_Error {
		$id   = (string) ( $p['queue_id'] ?? '' );
		$item = $this->require_queue_item( $id );
		if ( is_wp_error( $item ) ) {
			return $item;
		}

		$result = ( new OperationQueue() )->cancel_item( $id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->audit->record( 'operation.queue.cancelled', [
			'queue_id'   => $id,
			'request_id' => $item['request_id'],
			'actor'      => AuditLog::resolve_actor( $actor ),
		] );

		return [ 'action' => ApprovalRegistry::A_QUEUE_CANCEL, 'queue_id' => $id, 'status' => OperationQueue::STATUS_CANCELLED ];
	}

	private function queue_retry( array $p, array $actor ): array|\WP_Error {
		$id   = (string) ( $p['queue_id'] ?? '' );
		$item = $this->require_queue_item( $id );
		if ( is_wp_error( $item ) ) {
			return $item;
		}

		$this->audit->record( 'operation.queue.retry_requested', [
			'queue_id'     => $id,
			'request_id'   => $item['request_id'],
			'operation_id' => $item['operation_id'],
			'actor'        => AuditLog::resolve_actor( $actor ),
		] );

		$result = ( new OperationQueue() )->retry_item( $id );
		if ( is_wp_error( $result ) ) {
			$this->audit->record( 'operation.queue.retry_failed', [
				'queue_id' => $id,
				'request_id' => $item['request_id'],
				'error'    => $result->get_error_code(),
				'actor'    => AuditLog::resolve_actor( $actor ),
			] );
			return $result;
		}

		$this->audit->record( 'operation.queue.retry_queued', [
			'queue_id'     => $id,
			'request_id'   => $item['request_id'],
			'operation_id' => $item['operation_id'],
			'actor'        => AuditLog::resolve_actor( $actor ),
		] );

		return [ 'action' => ApprovalRegistry::A_QUEUE_RETRY, 'item' => $result ];
	}

	private function require_queue_item( string $id ): array|\WP_Error {
		if ( '' === $id ) {
			return new \WP_Error( 'wpcc_missing_queue_id', __( 'queue_id is required.', 'wp-command-center' ) );
		}
		$item = ( new OperationQueue() )->get_item( $id );
		if ( ! $item ) {
			return new \WP_Error( 'wpcc_queue_item_not_found', __( 'Queue item not found.', 'wp-command-center' ) );
		}
		return $item;
	}

	// ── Results ──

	private function results_get( array $p ): array|\WP_Error {
		$id = (string) ( $p['result_id'] ?? '' );
		if ( '' === $id ) {
			return new \WP_Error( 'wpcc_missing_result_id', __( 'result_id is required.', 'wp-command-center' ) );
		}
		$row = ( new OperationResults() )->get_result( $id );
		if ( ! $row ) {
			return new \WP_Error( 'wpcc_result_not_found', __( 'Operation result not found.', 'wp-command-center' ) );
		}
		return [ 'action' => ApprovalRegistry::A_RESULTS_GET, 'result' => $row ];
	}

	// ── Helpers ──

	/**
	 * Build a list-filter array from request params, restricted to $keys.
	 * Empty/unset values are dropped (matching RestApi's array_filter behavior).
	 */
	private function list_filters( array $p, array $keys ): array {
		$filters = [];
		foreach ( $keys as $key ) {
			if ( ! isset( $p[ $key ] ) || '' === $p[ $key ] ) {
				continue;
			}
			$filters[ $key ] = in_array( $key, [ 'limit', 'offset' ], true ) ? (int) $p[ $key ] : sanitize_text_field( (string) $p[ $key ] );
		}
		return $filters;
	}
}
