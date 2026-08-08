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
	// PHASE 2 (B2-1 / A-1) — transient claim state held by the single execution
	// winner between the atomic claim and finalization. Not a terminal state;
	// ProposalSync treats it as in-flight.
	public const STATUS_EXECUTING      = 'executing';

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
			return new \WP_Error( 'wpcc_operation_not_found', __( 'Operation not found in registry.', 'ai-command-center' ) );
		}

		$request_id = wp_generate_uuid4();
		$created_at = time();

		/*
		 * Record the risk of the ACTION, not the operation's headline tier.
		 *
		 * This stored `$operation['risk_level']` — the operation-wide default — while
		 * every other reader of risk in the product resolves it through
		 * SecurityModeManager::effective_risk(), which prefers the per-action entry
		 * in `action_risks`. On any operation where the two differ the row disagreed
		 * with the whole rest of the system.
		 *
		 * The clearest case is undo. `change_history` is a read operation whose tier
		 * is 'diagnostic'; its one write, `rollback_target`, declares 'high'. The gate
		 * saw 'high' and correctly demanded approval — and then the row it created
		 * said "diagnostic". MCP's approval_manage read that column back and told the
		 * assistant a HIGH-risk undo was diagnostic, while the Approvals screen, which
		 * recomputes from the registry, showed HIGH RISK on the same request. The
		 * record contradicted both the decision that produced it and the screen the
		 * customer approves from.
		 *
		 * The gate is unaffected: it computed the effective risk before calling here
		 * and gates on that value, not on this column. This only stops the stored
		 * record from misreporting what was already decided. The fallback follows
		 * effective_risk() — unknown risk is treated as high, never as safe.
		 */
		$risk_level = SecurityModeManager::effective_risk( $operation, (string) ( $payload['action'] ?? '' ) );

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
				'risk_level'   => $risk_level,
				'created_at'   => $created_at,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ]
		);

		if ( false === $inserted ) {
			return new \WP_Error( 'wpcc_request_create_failed', __( 'Failed to create operation request.', 'ai-command-center' ) );
		}

		return $this->get_request( $request_id );
	}

	/**
	 * Approve a pending request and automatically queue it.
	 */
	public function approve_request( string $request_id, array $context = [] ): bool|\WP_Error {
		$approved = $this->update_status( $request_id, self::STATUS_APPROVED, 'approved_at', $this->attribution_columns( $context ) );
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
	public function reject_request( string $request_id, array $context = [] ): bool|\WP_Error {
		return $this->update_status( $request_id, self::STATUS_REJECTED, 'rejected_at', $this->attribution_columns( $context ) );
	}

	/**
	 * Cancel a request.
	 */
	public function cancel_request( string $request_id, array $context = [] ): bool|\WP_Error {
		return $this->update_status( $request_id, self::STATUS_CANCELLED, 'cancelled_at', $this->attribution_columns( $context ) );
	}

	/**
	 * STEP 106.1 — derive the forward-only approver-attribution columns from the
	 * resolver's context. The same actor an admin approval (WP_User) or an MCP
	 * approval (token) already carries is recorded onto the request row so the
	 * Approval Center History can answer "who resolved this?" without scanning the
	 * audit JSONL. Returns an empty array when no actor is present (the columns
	 * stay NULL — forward-only, no synthetic attribution).
	 *
	 * @param array<string,mixed> $context Resolver context; reads $context['actor'].
	 * @return array<string,mixed> Subset of { resolved_by_label, resolved_by_type, resolved_by_user_id }.
	 */
	private function attribution_columns( array $context ): array {
		$actor = isset( $context['actor'] ) && is_array( $context['actor'] ) ? $context['actor'] : [];
		if ( empty( $actor ) ) {
			return [];
		}

		// User id (admin actors carry wp_user_id; resolved/system actors use user_id).
		$user_id = 0;
		foreach ( [ 'wp_user_id', 'user_id' ] as $key ) {
			if ( ! empty( $actor[ $key ] ) ) {
				$user_id = (int) $actor[ $key ];
				break;
			}
		}

		$raw_type = isset( $actor['type'] ) && is_string( $actor['type'] ) ? $actor['type'] : '';
		if ( $user_id > 0 || 'admin' === $raw_type ) {
			$type = 'wp_user';
		} elseif ( 'system' === $raw_type ) {
			$type = 'system';
		} elseif ( in_array( $raw_type, [ 'mcp', 'token' ], true ) || ! empty( $actor['token_id'] ) ) {
			$type = 'token';
		} else {
			$type = $raw_type;
		}

		// Human label, mirroring the Change History admin precedence.
		$label = '';
		foreach ( [ 'label', 'user_login', 'name', 'agent' ] as $key ) {
			if ( ! empty( $actor[ $key ] ) && is_string( $actor[ $key ] ) ) {
				$label = $actor[ $key ];
				break;
			}
		}
		if ( '' === $label ) {
			if ( $user_id > 0 ) {
				$label = 'Admin #' . $user_id;
			} elseif ( ! empty( $actor['token_id'] ) ) {
				$label = 'Token ' . substr( (string) $actor['token_id'], 0, 8 );
			} elseif ( '' !== $type ) {
				$label = $type;
			}
		}

		$columns = [];
		if ( '' !== $label ) {
			$columns['resolved_by_label'] = substr( $label, 0, 191 );
		}
		if ( '' !== $type ) {
			$columns['resolved_by_type'] = substr( $type, 0, 20 );
		}
		if ( $user_id > 0 ) {
			$columns['resolved_by_user_id'] = $user_id;
		}

		return $columns;
	}

	/**
	 * Execute an approved request.
	 */
	public function execute_request( string $request_id, array $actor = [] ): array|\WP_Error {
		global $wpdb;

		$request = $this->get_request( $request_id );
		if ( ! $request ) {
			return new \WP_Error( 'wpcc_request_not_found', __( 'Operation request not found.', 'ai-command-center' ) );
		}

		if ( self::STATUS_APPROVED !== $request['status'] ) {
			return new \WP_Error( 'wpcc_request_not_approved', __( 'Only approved requests can be executed.', 'ai-command-center' ) );
		}

		// B2-2 execute-once (synchronous path). The status check above blocks a
		// second execute_request once a request is EXECUTED, but the queue worker
		// (run_item) executes without finalizing request.status — so a worker-run
		// request stays 'approved'. Guard against the synchronous path re-running an
		// operation the worker already executed by consulting the queue ledger.
		if ( $this->request_already_executed( $request_id ) ) {
			( new AuditLog() )->record( 'operation.execution.duplicate_suppressed', [
				'request_id'   => $request_id,
				'operation_id' => $request['operation_id'],
				'path'         => 'synchronous',
				'reason'       => 'already_executed',
			] );
			return new \WP_Error( 'wpcc_request_already_executed', __( 'This request has already been executed.', 'ai-command-center' ) );
		}

		$payload = json_decode( $request['payload'], true ) ?: [];
		$context = [
			'session_id' => $request['session_id'],
			'task_id'    => $request['task_id'],
			'action_id'  => $request['action_id'],
			'plan_id'    => $request['plan_id'],
			'request_id' => $request_id,
		];

		// STEP 105.5 — carry the approver/executor actor into the execution
		// context so the change log attributes it correctly (an admin approval
		// passes the admin actor). When executed with no actor (headless), tag it
		// so the change log records "System (Headless Request)" instead of "unknown".
		if ( ! empty( $actor ) ) {
			$context['actor'] = $actor;
		} else {
			$context['system_via'] = 'request';
		}

		$executor = new OperationExecutor();
		if ( ! empty( $request['plan_id'] ) ) {
			( new RecommendationEngine() )->sync_plan_status( $request['plan_id'], 'executing', $actor );
		}
		// PHASE 2 (B2-1) — request status is now finalized inside OperationExecutor::run
		// (the shared finalizer) for every request-bound path, so this method no longer
		// writes executed/failed itself. The executor's atomic claim also makes this
		// the single execution authority.
		$result   = $executor->run( $request['operation_id'], $payload, $context );

		if ( ! $result['success'] ) {
			$error = $result['errors'][0] ?? [ 'code' => 'execution_failed', 'message' => 'Unknown error' ];
			return new \WP_Error( $error['code'], $error['message'] );
		}

		// B2-2 queue supersession. The synchronous path bypassed the queue, so the
		// item auto-enqueued by approve_request is still runnable; cancel it so the
		// background worker cannot execute this request a second time. cancel_item
		// only acts on queued/failed items (running/completed are left untouched),
		// so this is safe and idempotent.
		$this->supersede_pending_queue_items( $request_id );

		if ( ! empty( $request['plan_id'] ) ) {
			( new RecommendationEngine() )->sync_plan_status( $request['plan_id'], 'resolved', $actor );
		}

		return $result;
	}

	/**
	 * B2-2 execute-once ledger. True when this request has already been executed by
	 * EITHER path — synchronously (request.status === EXECUTED) or by the queue
	 * worker (a queue item for the request reached running/completed). A `failed`
	 * request and `queued`/`failed` queue items are NOT "already executed", so
	 * legitimate failed → retry flows are unaffected.
	 */
	public function request_already_executed( string $request_id ): bool {
		$request = $this->get_request( $request_id );
		if ( $request && self::STATUS_EXECUTED === $request['status'] ) {
			return true;
		}
		foreach ( ( new OperationQueue() )->list_items( [ 'request_id' => $request_id ] ) as $item ) {
			if ( in_array( $item['status'], [ OperationQueue::STATUS_RUNNING, OperationQueue::STATUS_COMPLETED ], true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * PHASE 2 (A-1) — atomic execution claim. Exactly one caller can transition a
	 * request from a runnable state (approved, or failed for retry) into the
	 * transient `executing` state via a single conditional UPDATE; the row-count
	 * decides the winner. Returns 'claimed' (winner), 'already' (lost — another
	 * path is executing/has executed, or the request is terminal/pending), or
	 * 'not_found'. This is the cross-path execute-once authority that closes the
	 * A-1 concurrency residual left by the Phase 1 check-then-act guard.
	 */
	public function claim_for_execution( string $request_id ): string {
		global $wpdb;
		$affected = $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}wpcc_operation_requests SET status = %s WHERE request_id = %s AND status IN ( %s, %s )",
			self::STATUS_EXECUTING,
			$request_id,
			self::STATUS_APPROVED,
			self::STATUS_FAILED
		) );
		if ( 1 === (int) $affected ) {
			return 'claimed';
		}
		return $this->get_request( $request_id ) ? 'already' : 'not_found';
	}

	/**
	 * PHASE 2 (B2-1) — shared request finalizer. The single execution winner records
	 * the durable outcome onto the request: `executed` on success, `failed` on
	 * failure. CAS from `executing` so only the active winner finalizes. Used by
	 * every request-bound execution path (admin synchronous, queue worker, MCP),
	 * so request.status is a faithful projection of the durable result regardless
	 * of which path executed.
	 */
	public function finalize_execution( string $request_id, bool $success ): void {
		global $wpdb;
		$status = $success ? self::STATUS_EXECUTED : self::STATUS_FAILED;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery -- The interpolated column is chosen in-file from a two-value ternary, never from input; all values are bound.
		$field  = $success ? 'executed_at' : 'failed_at';
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}wpcc_operation_requests SET status = %s, {$field} = %d WHERE request_id = %s AND status = %s",
			$status,
			time(),
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery
			$request_id,
			self::STATUS_EXECUTING
		) );
	}

	/**
	 * B2-2 — cancel any still-runnable (queued/failed) queue item for a request once
	 * it has been executed synchronously, so the worker will not run it again.
	 */
	private function supersede_pending_queue_items( string $request_id ): void {
		$queue = new OperationQueue();
		foreach ( $queue->list_items( [ 'request_id' => $request_id ] ) as $item ) {
			if ( in_array( $item['status'], [ OperationQueue::STATUS_QUEUED, OperationQueue::STATUS_FAILED ], true ) ) {
				$queue->cancel_item( $item['queue_id'] );
			}
		}
	}

	/**
	 * The one risk level a request row reports, wherever it is read.
	 *
	 * Rows written before create_request() resolved the per-action risk still hold
	 * the operation-wide tier — a stored 'diagnostic' on an undo that the gate had
	 * already judged 'high'. Those rows are not rewritten (an approval record is
	 * history, and a migration that edits risk on decided requests would be worse
	 * than the mislabel); they are normalised as they are read, through exactly
	 * the resolver the Approvals screen uses. MCP and the UI therefore cannot
	 * disagree about a request's risk, on old rows or new ones.
	 *
	 * An operation no longer in the registry — a runtime whose plugin has since
	 * been deactivated — keeps whatever the row recorded, and an unrecognised or
	 * missing value reads as 'high'. Unknown risk is never presented as safe.
	 *
	 * @param array<string,mixed> $row Raw request row.
	 */
	private function canonical_risk( array $row ): string {
		$operation = ( new OperationRegistry() )->get_operation( (string) ( $row['operation_id'] ?? '' ) );

		if ( null === $operation ) {
			$stored = (string) ( $row['risk_level'] ?? '' );
			return in_array( $stored, SecurityModeManager::RISK_LEVELS, true )
				? $stored
				: SecurityModeManager::RISK_HIGH;
		}

		$payload = json_decode( (string) ( $row['payload'] ?? '{}' ), true );
		$action  = is_array( $payload ) ? (string) ( $payload['action'] ?? '' ) : '';

		return SecurityModeManager::effective_risk( $operation, $action );
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function normalise_row( array $row ): array {
		$row['risk_level'] = $this->canonical_risk( $row );
		return $row;
	}

	public function get_request( string $request_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcc_operation_requests WHERE request_id = %s", $request_id ), ARRAY_A );
		return $row ? $this->normalise_row( $row ) : null;
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
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery -- $wpdb->prepare() is applied into $sql above; the sniffer cannot follow prepare-into-variable.
		$offset = isset( $filters['offset'] ) ? max( 0, (int) $filters['offset'] ) : 0;
		$sql   .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $limit, $offset );

		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, ...$params );
		}

		return array_map( [ $this, 'normalise_row' ], $wpdb->get_results( $sql, ARRAY_A ) ?: [] );
	}
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery

	/**
	 * F-01 — canonical count of operation requests. Read-only; never mutates.
	 *
	 * Every surface that answers "how many changes are waiting for you" routes
	 * here: the admin-bar badge, the Approval Center header, the mission-control
	 * counters and the MCP reports. Before this existed each of them wrote its
	 * own COUNT, and the two report fields wrote it against the status literal
	 * `'pending'` — a value this table never stores — so they answered 0 on a
	 * site with 112 waiting requests. One counter, one status constant, no
	 * literal left to drift.
	 *
	 * Prefer this over counting rows from list_requests(): that method is a
	 * paginated fetch (LIMIT defaults to 50, callers were passing 1000) and its
	 * length silently becomes a cap, not a count.
	 *
	 * @param array{status?:string,risk_level?:string,operation_id?:string,session_id?:string,task_id?:string,plan_id?:string} $filters Equality filters; omit for every row.
	 */
	public function count_requests( array $filters = [] ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcc_operation_requests';
		if ( ! $this->requests_table_exists() ) {
			return 0;
		}

		$where  = [];
		$params = [];
		foreach ( [ 'session_id', 'task_id', 'plan_id', 'status', 'operation_id', 'risk_level' ] as $key ) {
			if ( ! empty( $filters[ $key ] ) ) {
				$where[]  = "{$key} = %s";
				$params[] = $filters[ $key ];
			}
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a trusted prefix concat; every filter value goes through prepare() below.
		$sql = "SELECT COUNT(*) FROM {$table}";
		if ( $where ) {
			$sql = $wpdb->prepare( $sql . ' WHERE ' . implode( ' AND ', $where ), ...$params );
		}
		return (int) $wpdb->get_var( $sql );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Requests currently awaiting a human decision — the one canonical pending count. */
	public function count_pending_review(): int {
		return $this->count_requests( [ 'status' => self::STATUS_PENDING_REVIEW ] );
	}

	/**
	 * Whole-table status histogram, grouped in SQL. Read-only.
	 *
	 * The report breakdown used to tally a fetched page of rows, so past ~1000
	 * requests it would have started under-reporting the very statuses it
	 * summarises. GROUP BY has no page to fall off.
	 *
	 * @return array<string,int> status => count, for statuses present in the table.
	 */
	public function count_requests_by_status(): array {
		global $wpdb;
		if ( ! $this->requests_table_exists() ) {
			return [];
		}
		$table = $wpdb->prefix . 'wpcc_operation_requests';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- no user input; table name is a trusted prefix concat.
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A ) ?: [];
		$out  = [];
		foreach ( $rows as $row ) {
			$out[ (string) ( $row['status'] ?? 'unknown' ) ] = (int) ( $row['total'] ?? 0 );
		}
		return $out;
	}

	/** Guard: the requests table is absent on a fresh install until migrations run. */
	private function requests_table_exists(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcc_operation_requests';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	private function update_status( string $request_id, string $status, ?string $timestamp_field = null, array $extra = [] ): bool|\WP_Error {
		global $wpdb;

		$data    = [ 'status' => $status ];
		$formats = [ '%s' ];

		if ( $timestamp_field ) {
			$data[ $timestamp_field ] = time();
			$formats[]                = '%d';
		}

		// STEP 106.1 — forward-only approver-attribution columns (and any other
		// caller-supplied row fields). Integers use %d; everything else %s.
		foreach ( $extra as $column => $value ) {
			$data[ $column ] = $value;
			$formats[]       = is_int( $value ) ? '%d' : '%s';
		}

		$updated = $wpdb->update(
			$wpdb->prefix . 'wpcc_operation_requests',
			$data,
			[ 'request_id' => $request_id, 'status' => self::STATUS_PENDING_REVIEW ],
			$formats,
			[ '%s', '%s' ]
		);

		if ( 0 === $updated ) {
			// Check if it already has the status or if it wasn't pending
			$request = $this->get_request( $request_id );
			if ( ! $request ) {
				return new \WP_Error( 'wpcc_request_not_found', __( 'Operation request not found.', 'ai-command-center' ) );
			}
			if ( $request['status'] === $status ) {
				return true;
			}
			return new \WP_Error( 'wpcc_invalid_transition', sprintf( /* translators: 1: current status, 2: requested status */ __( 'Cannot transition request from %1$s to %2$s.', 'ai-command-center' ), $request['status'], $status ) );
		}

		return true;
	}
}
