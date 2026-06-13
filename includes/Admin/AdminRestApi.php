<?php
/**
 * Admin-only REST endpoints for the approval workflow.
 *
 * These routes use WP cookie authentication (X-WP-Nonce header) and are
 * intended exclusively for the WP Admin approval UI. API token requests
 * cannot reach these endpoints because they do not set a WP session cookie,
 * so current_user_can() returns false for guest/token-only callers.
 *
 * Namespace: wp-command-center/v1/admin/...
 */

namespace WPCommandCenter\Admin;

use WPCommandCenter\Operations\OperationManager;
use WPCommandCenter\Operations\OperationRegistry;
use WPCommandCenter\Operations\SecurityModeManager;
use WPCommandCenter\Operations\DestructiveGuard;
use WPCommandCenter\Security\AuditLog;

defined( 'ABSPATH' ) || exit;

final class AdminRestApi {

	private const NS = 'wp-command-center/v1';

	public function init(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NS, '/admin/approvals', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'list_pending' ],
			'permission_callback' => [ $this, 'check_permission' ],
		] );

		register_rest_route( self::NS, '/admin/approvals/count', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'count_pending' ],
			'permission_callback' => [ $this, 'check_permission' ],
		] );

		foreach ( [ 'approve', 'reject' ] as $action ) {
			register_rest_route( self::NS, '/admin/approvals/(?P<id>[a-f0-9-]{36})/' . $action, [
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => function ( \WP_REST_Request $r ) use ( $action ) {
					return $this->handle_action( $r, $action );
				},
				'permission_callback' => [ $this, 'check_permission' ],
			] );
		}
	}

	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	public function list_pending( \WP_REST_Request $request ): \WP_REST_Response {
		$manager  = new OperationManager();
		$requests = $manager->list_requests( [
			'status' => OperationManager::STATUS_PENDING_REVIEW,
			'limit'  => 100,
		] );

		$registry = new OperationRegistry();
		$data     = array_map( fn( $r ) => $this->format_request( $r, $registry ), $requests );

		return new \WP_REST_Response( [ 'success' => true, 'requests' => $data, 'total' => count( $data ) ], 200 );
	}

	public function count_pending(): \WP_REST_Response {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcc_operation_requests';
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE status = %s",
			OperationManager::STATUS_PENDING_REVIEW
		) );
		return new \WP_REST_Response( [ 'count' => $count ], 200 );
	}

	public function handle_action( \WP_REST_Request $request, string $action ): \WP_REST_Response {
		$request_id = sanitize_text_field( $request->get_param( 'id' ) );
		$manager    = new OperationManager();
		$actor      = [
			'wp_user_id' => get_current_user_id(),
			'user_login' => wp_get_current_user()->user_login,
			'source'     => 'admin_ui',
		];

		if ( 'approve' === $action ) {
			$approved = $manager->approve_request( $request_id, [ 'actor' => $actor ] );
			if ( is_wp_error( $approved ) ) {
				return new \WP_REST_Response( [ 'success' => false, 'error' => $approved->get_error_message() ], 400 );
			}

			( new AuditLog() )->record( 'admin.approval.approved', [
				'request_id' => $request_id,
				'actor'      => AuditLog::resolve_actor( $actor ),
			] );

			// Execute immediately (admin approve = approve + run in one step).
			$exec = $manager->execute_request( $request_id, $actor );
			if ( is_wp_error( $exec ) ) {
				return new \WP_REST_Response( [
					'success'  => true,
					'approved' => true,
					'executed' => false,
					'error'    => $exec->get_error_message(),
				], 200 );
			}

			return new \WP_REST_Response( [
				'success'  => true,
				'approved' => true,
				'executed' => true,
				'result'   => $exec['result'] ?? null,
			], 200 );
		}

		// reject
		$rejected = $manager->reject_request( $request_id );
		if ( is_wp_error( $rejected ) ) {
			return new \WP_REST_Response( [ 'success' => false, 'error' => $rejected->get_error_message() ], 400 );
		}

		( new AuditLog() )->record( 'admin.approval.rejected', [
			'request_id' => $request_id,
			'actor'      => AuditLog::resolve_actor( $actor ),
		] );

		return new \WP_REST_Response( [ 'success' => true, 'rejected' => true ], 200 );
	}

	private function format_request( array $r, OperationRegistry $registry ): array {
		$operation = $registry->get_operation( $r['operation_id'] ) ?? [];
		$payload   = json_decode( $r['payload'] ?? '{}', true ) ?: [];
		$action    = $payload['action'] ?? '';
		$risk      = SecurityModeManager::effective_risk( $operation, $action );

		// STEP 84 — flag destructive requests so the approval card can render the
		// irreversible-deletion warning prominently.
		$destructive = DestructiveGuard::classify( $r['operation_id'], $payload );

		return [
			'request_id'          => $r['request_id'],
			'operation_id'        => $r['operation_id'],
			'operation'           => $operation['title'] ?? $r['operation_id'],
			'action'              => $action,
			'risk_level'          => $risk,
			'status'              => $r['status'],
			'reason'              => $payload['reason'] ?? '',
			'destructive'         => null !== $destructive,
			'destructive_warning' => null !== $destructive ? $destructive['warning'] : '',
			'payload'             => $payload,
			'session_id'   => $r['session_id'],
			'plan_id'      => $r['plan_id'],
			'created_at'   => (int) $r['created_at'],
			'created_ago'  => human_time_diff( (int) $r['created_at'], time() ) . ' ago',
		];
	}
}
