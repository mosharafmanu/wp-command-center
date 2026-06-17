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
use WPCommandCenter\Operations\ChangeHistoryRuntimeManager;
use WPCommandCenter\Operations\OperationExecutor;
use WPCommandCenter\PatchSystem\PatchManager;
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

		// STEP 105.1 — Change History admin read surface (cookie + nonce only).
		// All read-only. List/timeline/get delegate to the STEP 104 runtime
		// manager (identical envelope as token REST/MCP); sessions is a thin
		// presentation-layer aggregation. No write/rollback routes here — the
		// Restore action lands in STEP 105.3.
		register_rest_route( self::NS, '/admin/history', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'history_list' ],
			'permission_callback' => [ $this, 'check_history_permission' ],
		] );

		register_rest_route( self::NS, '/admin/history/timeline', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'history_timeline' ],
			'permission_callback' => [ $this, 'check_history_permission' ],
		] );

		register_rest_route( self::NS, '/admin/history/sessions', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'history_sessions' ],
			'permission_callback' => [ $this, 'check_history_permission' ],
		] );

		// STEP 105.2 — server-rendered diff for one change (read-only). Registered
		// before the bare /{change_id} route so the /diff suffix matches first.
		register_rest_route( self::NS, '/admin/history/(?P<change_id>[A-Za-z0-9\-]{1,64})/diff', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'history_diff' ],
			'permission_callback' => [ $this, 'check_history_permission' ],
		] );

		// STEP 105.3 — reverse one change from wp-admin. The ONLY write route in
		// the Change History admin surface. It does NOT roll back itself: it
		// routes change_history/rollback_target through OperationExecutor::run,
		// inheriting capability, DestructiveGuard (ROLLBACK_CHANGE handshake),
		// security-mode approval, AuditLog, and ChangeRecorder — no bypass.
		register_rest_route( self::NS, '/admin/history/(?P<change_id>[A-Za-z0-9\-]{1,64})/rollback', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'history_rollback' ],
			'permission_callback' => [ $this, 'check_history_permission' ],
		] );

		register_rest_route( self::NS, '/admin/history/(?P<change_id>[A-Za-z0-9\-]{1,64})', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'history_get' ],
			'permission_callback' => [ $this, 'check_history_permission' ],
		] );
	}

	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * STEP 105.4 — permission gate for the Change History admin surface:
	 * manage_options AND the (currently ungated) feature seam. This is the single
	 * REST switch point a future Free/Pro layer flips via FeatureGate.
	 */
	public function check_history_permission(): bool {
		return current_user_can( 'manage_options' ) && FeatureGate::allows( 'change_history' );
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

	// ── STEP 105.1 — Change History read handlers ───────────────────────

	/** GET /admin/history — flat, filtered, cursor-paginated change list. */
	public function history_list( \WP_REST_Request $request ): \WP_REST_Response {
		$params           = $this->history_filters( $request );
		$params['action'] = 'history_list';
		return $this->history_response( $params );
	}

	/** GET /admin/history/timeline — chronological, time-windowed. */
	public function history_timeline( \WP_REST_Request $request ): \WP_REST_Response {
		$params           = $this->history_filters( $request );
		$params['action'] = 'history_timeline';
		return $this->history_response( $params );
	}

	/** GET /admin/history/{change_id} — single change (metadata; diff arrives in 105.2). */
	public function history_get( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->history_response( [
			'action'    => 'history_get',
			'change_id' => sanitize_text_field( (string) $request->get_param( 'change_id' ) ),
		] );
	}

	/**
	 * GET /admin/history/{change_id}/diff — server-rendered, escaped diff/what-
	 * changed HTML for one change. Read-only. Strategy is honest about the data
	 * we actually store:
	 *   - patch            → real unified diff via the shared DiffRenderer;
	 *   - patch_unavailable → snapshot rotated/missing → metadata note (degrade);
	 *   - metadata          → runtime/option change → "what changed" summary
	 *                          (no synthesized before/after diff);
	 *   - none              → not reversible / no diff.
	 */
	public function history_diff( \WP_REST_Request $request ): \WP_REST_Response {
		$change_id = sanitize_text_field( (string) $request->get_param( 'change_id' ) );

		$got = ( new ChangeHistoryRuntimeManager() )->run( [ 'action' => 'history_get', 'change_id' => $change_id ] );
		if ( is_array( $got ) && ! empty( $got['error'] ) ) {
			return new \WP_REST_Response(
				[ 'success' => false, 'code' => (string) ( $got['code'] ?? 'wpcc_change_not_found' ), 'message' => (string) ( $got['message'] ?? '' ) ],
				'wpcc_change_not_found' === ( $got['code'] ?? '' ) ? 404 : 400
			);
		}

		$change   = is_array( $got['change'] ?? null ) ? $got['change'] : [];
		$rollback = is_array( $change['rollback'] ?? null ) ? $change['rollback'] : [];
		$kind     = (string) ( $rollback['kind'] ?? 'none' );

		if ( 'patch' === $kind ) {
			$patch_id = (string) ( $rollback['rollback_id'] ?? '' );
			if ( '' === $patch_id ) {
				$patch_id = (string) ( $rollback['change_set_id'] ?? '' );
			}

			$patch = '' !== $patch_id ? ( new PatchManager() )->get( $patch_id ) : new \WP_Error( 'wpcc_no_patch', '' );
			if ( is_wp_error( $patch ) || empty( $patch['files'] ) ) {
				// Snapshot rotated/cleaned or no file records — degrade, never error.
				return $this->diff_payload( $change_id, 'patch_unavailable', false, null,
					'<p class="description">' . esc_html__( 'The diff for this change is no longer available (its snapshot has been cleaned up). The change metadata is shown above.', 'wp-command-center' ) . '</p>',
					__( 'Diff snapshot unavailable.', 'wp-command-center' )
				);
			}

			$files   = array_map(
				static fn( $f ): array => [ 'path' => (string) ( $f['path'] ?? '' ), 'diff' => (string) ( $f['diff'] ?? '' ) ],
				$patch['files']
			);
			$summary = DiffRenderer::summarize( $files );
			$html    = DiffRenderer::render_accordion( $files, false );

			return $this->diff_payload( $change_id, 'patch', true, $summary, $html, '' );
		}

		if ( 'none' === $kind ) {
			return $this->diff_payload( $change_id, 'none', false, null,
				'<p class="description">' . esc_html__( 'This change is not reversible and has no recorded diff.', 'wp-command-center' ) . '</p>',
				__( 'No diff for this change.', 'wp-command-center' )
			);
		}

		// runtime_option (and any other reversible non-patch kind): we do not store
		// before/after content, so present a structured "what changed" summary
		// rather than a synthesized diff.
		return $this->diff_payload( $change_id, 'metadata', false, null, $this->render_change_metadata( $change ),
			__( 'Field-level change — previous value is restorable, but no textual diff is stored.', 'wp-command-center' )
		);
	}

	/**
	 * Build the "what changed" HTML for a non-patch change from its stored
	 * target summary + counts. All values are escaped.
	 *
	 * @param array<string,mixed> $change
	 */
	private function render_change_metadata( array $change ): string {
		$html   = '<div class="wpcc-change-meta-summary">';
		$counts = is_array( $change['counts'] ?? null ) ? $change['counts'] : [];
		$html  .= '<p>' . esc_html( sprintf(
			/* translators: 1: created, 2: updated, 3: skipped, 4: errors */
			__( 'Created %1$d · Updated %2$d · Skipped %3$d · Errors %4$d', 'wp-command-center' ),
			(int) ( $counts['created'] ?? 0 ),
			(int) ( $counts['updated'] ?? 0 ),
			(int) ( $counts['skipped'] ?? 0 ),
			(int) ( $counts['error'] ?? 0 )
		) ) . '</p>';

		$summary = $change['target_summary'] ?? null;
		if ( is_array( $summary ) && ! empty( $summary ) ) {
			$html .= '<table class="widefat striped"><tbody>';
			foreach ( $summary as $key => $value ) {
				$rendered = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
				$html    .= '<tr><th>' . esc_html( (string) $key ) . '</th><td>' . esc_html( $rendered ) . '</td></tr>';
			}
			$html .= '</tbody></table>';
		} else {
			$html .= '<p class="description">' . esc_html__( 'No field-level detail was recorded for this change.', 'wp-command-center' ) . '</p>';
		}

		$html .= '</div>';
		return $html;
	}

	/**
	 * @param array<string,mixed>|null $summary
	 */
	private function diff_payload( string $change_id, string $diff_kind, bool $available, ?array $summary, string $html, string $note ): \WP_REST_Response {
		return new \WP_REST_Response( [
			'change_id' => $change_id,
			'diff_kind' => $diff_kind,
			'available' => $available,
			'summary'   => $summary,
			'html'      => $html,
			'note'      => $note,
		], 200 );
	}

	/**
	 * POST /admin/history/{change_id}/rollback — reverse one change.
	 *
	 * Pure reuse: builds the rollback_target payload + an admin actor context
	 * and hands off to OperationExecutor::run( 'change_history', … ). That is the
	 * same chokepoint the token/MCP path uses, so capability, DestructiveGuard
	 * (the ROLLBACK_CHANGE handshake on high-risk-file patch reversals),
	 * security-mode approval, AuditLog and ChangeRecorder all apply unchanged.
	 *
	 * No token_scope/token_id is set: this is a cookie + manage_options request,
	 * the route is the gate, and omitting token_scope keeps the engine's
	 * read-only-token guard inapplicable (it is not a read-only token).
	 *
	 * The structured result is returned verbatim (HTTP 200) so the UI can branch
	 * on result.status — success | pending_approval | confirmation_required —
	 * exactly like the agent-facing surfaces.
	 */
	public function history_rollback( \WP_REST_Request $request ): \WP_REST_Response {
		$change_id = sanitize_text_field( (string) $request->get_param( 'change_id' ) );

		$payload = [ 'action' => 'rollback_target', 'change_id' => $change_id ];

		if ( null !== $request->get_param( 'confirm' ) ) {
			$payload['confirm'] = rest_sanitize_boolean( $request->get_param( 'confirm' ) );
		}
		$phrase = (string) $request->get_param( 'confirmation_phrase' );
		if ( '' !== $phrase ) {
			$payload['confirmation_phrase'] = sanitize_text_field( $phrase );
		}
		$reason = (string) $request->get_param( 'reason' );
		if ( '' !== $reason ) {
			$payload['reason'] = sanitize_textarea_field( $reason );
		}

		$user    = wp_get_current_user();
		$context = [
			'actor'  => AuditLog::resolve_actor( [
				'type'       => 'admin',
				'wp_user_id' => get_current_user_id(),
				'user_login' => $user ? $user->user_login : '',
				'source'     => 'admin_ui',
			] ),
			'source' => 'admin_ui',
		];

		$result = ( new OperationExecutor() )->run( 'change_history', $payload, $context );

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * GET /admin/history/sessions — session-grouped roll-up. Presentation-layer
	 * aggregation only (ChangeHistoryAdminQuery); not a runtime/MCP surface.
	 */
	public function history_sessions( \WP_REST_Request $request ): \WP_REST_Response {
		$filters = [
			'runtime' => sanitize_text_field( (string) $request->get_param( 'runtime' ) ),
			'status'  => sanitize_text_field( (string) $request->get_param( 'status' ) ),
			'since'   => $request->get_param( 'since' ),
			'until'   => $request->get_param( 'until' ),
		];

		[ $limit, $offset ] = $this->history_paging( $request );

		$result = ( new ChangeHistoryAdminQuery() )->sessions( $filters, $limit, $offset );

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * Collect the supported read filters from the request, dropping empties so
	 * the runtime manager only applies what was actually sent.
	 *
	 * @return array<string,mixed>
	 */
	private function history_filters( \WP_REST_Request $request ): array {
		$out = [];

		foreach ( [ 'runtime', 'operation_id', 'status', 'target', 'change_set_id', 'session_id', 'task_id', 'plan_id', 'cursor' ] as $key ) {
			$value = (string) $request->get_param( $key );
			if ( '' !== $value ) {
				$out[ $key ] = sanitize_text_field( $value );
			}
		}

		foreach ( [ 'since', 'until', 'limit' ] as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value && '' !== (string) $value ) {
				$out[ $key ] = (int) $value;
			}
		}

		if ( null !== $request->get_param( 'reversible_only' ) && '' !== (string) $request->get_param( 'reversible_only' ) ) {
			$out['reversible_only'] = rest_sanitize_boolean( $request->get_param( 'reversible_only' ) );
		}

		return $out;
	}

	/**
	 * @return array{0:int,1:int} [limit, offset]
	 */
	private function history_paging( \WP_REST_Request $request ): array {
		$limit  = (int) ( $request->get_param( 'limit' ) ?: 20 );
		$offset = 0;

		$cursor = (string) $request->get_param( 'cursor' );
		if ( '' !== $cursor ) {
			$decoded = json_decode( (string) base64_decode( $cursor, true ), true );
			if ( is_array( $decoded ) && isset( $decoded['offset'] ) ) {
				$offset = max( 0, (int) $decoded['offset'] );
			}
		}

		return [ $limit, $offset ];
	}

	/**
	 * Run a read action on the STEP 104 runtime manager and map its in-band
	 * { error: true, code, message } shape to an HTTP status.
	 *
	 * @param array<string,mixed> $params
	 */
	private function history_response( array $params ): \WP_REST_Response {
		$result = ( new ChangeHistoryRuntimeManager() )->run( $params );

		if ( is_array( $result ) && ! empty( $result['error'] ) ) {
			$code   = (string) ( $result['code'] ?? 'wpcc_history_error' );
			$status = str_contains( $code, 'not_found' ) ? 404 : 400;
			return new \WP_REST_Response(
				[ 'success' => false, 'code' => $code, 'message' => (string) ( $result['message'] ?? '' ) ],
				$status
			);
		}

		return new \WP_REST_Response( $result, 200 );
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
