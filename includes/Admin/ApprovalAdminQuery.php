<?php
/**
 * STEP 106.1 — Approval Center admin aggregation read (presentation layer).
 *
 * Powers the wp-admin "Approval Center" History / Queue surfaces and the
 * summary header: filtered, paginated roll-ups over the existing approval
 * tables (`wpcc_operation_requests`, `wpcc_operation_queue`,
 * `wpcc_operation_results`). This is a READ-ONLY presentation-layer helper —
 * NOT a runtime API, NOT MCP-exposed, and NOT a new source of truth. It never
 * writes, and it must never grow runtime/business logic: it only reads rows the
 * STEP 20 / 78 / 80 approval engine already records.
 *
 * Cheap by design: one filtered SELECT + one COUNT for pagination, and a single
 * grouped COUNT for the header. No per-row N+1, no caching, no persistence.
 *
 * Approver attribution (resolved_by_*) is forward-only from STEP 106: rows
 * resolved before STEP 106 have NULL attribution and surface as "unavailable".
 */

namespace WPCommandCenter\Admin;

use WPCommandCenter\Operations\OperationManager;
use WPCommandCenter\Operations\OperationQueue;
use WPCommandCenter\Operations\OperationResults;
use WPCommandCenter\Operations\OperationRegistry;
use WPCommandCenter\Operations\SecurityModeManager;
use WPCommandCenter\Operations\PatchOperation;
use WPCommandCenter\Security\AuditLog;

defined( 'ABSPATH' ) || exit;

final class ApprovalAdminQuery {

	private const DEFAULT_LIMIT = 20;
	private const MAX_LIMIT     = 100;

	/** Statuses considered "resolved" (lifecycle complete). */
	private const RESOLVED_STATUSES = [
		OperationManager::STATUS_APPROVED,
		OperationManager::STATUS_REJECTED,
		OperationManager::STATUS_EXECUTED,
		OperationManager::STATUS_FAILED,
		OperationManager::STATUS_CANCELLED,
	];

	private OperationRegistry $registry;

	public function __construct() {
		$this->registry = new OperationRegistry();
	}

	/**
	 * Header counts for the Approval Center landing: how many pending, how many
	 * of those are critical, how many requests have been resolved (all-time),
	 * and how many queue items are in a failed state.
	 *
	 * @return array<string,int>
	 */
	public function summary(): array {
		global $wpdb;
		$requests = $wpdb->prefix . 'wpcc_operation_requests';
		$queue    = $wpdb->prefix . 'wpcc_operation_queue';

		$pending = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$requests} WHERE status = %s",
			OperationManager::STATUS_PENDING_REVIEW
		) );

		$pending_critical = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$requests} WHERE status = %s AND risk_level = %s",
			OperationManager::STATUS_PENDING_REVIEW,
			SecurityModeManager::RISK_CRITICAL
		) );

		$resolved = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$requests} WHERE status != %s",
			OperationManager::STATUS_PENDING_REVIEW
		) );

		$queue_failed = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$queue} WHERE status = %s",
			OperationQueue::STATUS_FAILED
		) );

		return [
			'pending'          => $pending,
			'pending_critical' => $pending_critical,
			'resolved'         => $resolved,
			'queue_failed'     => $queue_failed,
		];
	}

	/**
	 * Filtered, paginated request history across ALL statuses (D2: includes every
	 * resolved status, not just recent items).
	 *
	 * @param array<string,mixed> $filters status[]|status, risk, operation_id, actor, from, to.
	 * @return array<string,mixed> Envelope: { action, requests[], total_count, returned,
	 *                             has_more, next_cursor, limit, offset, filters }.
	 */
	public function history( array $filters, int $limit, int $offset ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcc_operation_requests';

		$limit  = min( self::MAX_LIMIT, max( 1, $limit ) );
		$offset = max( 0, $offset );

		[ $where, $params, $applied ] = $this->build_where( $filters );
		$where_sql = $where ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '';

		$count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
		$total     = (int) $wpdb->get_var(
			$params ? $wpdb->prepare( $count_sql, $params ) : $count_sql // phpcs:ignore WordPress.DB.PreparedSQL
		);

		$list_sql      = "SELECT * FROM {$table} {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$list_params   = array_merge( $params, [ $limit, $offset ] );
		$rows          = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL
		$formatted     = array_map( [ $this, 'format_row' ], $rows );
		$returned      = count( $formatted );
		$has_more      = ( $offset + $returned ) < $total;

		return [
			'action'      => 'approvals_history',
			'requests'    => $formatted,
			'total_count' => $total,
			'returned'    => $returned,
			'has_more'    => $has_more,
			'next_cursor' => $has_more ? $this->encode_cursor( $offset + $returned ) : null,
			'limit'       => $limit,
			'offset'      => $offset,
			'filters'     => $applied,
		];
	}

	/**
	 * Queue visibility (read-only). Delegates to OperationQueue for the rows so
	 * there is one source of truth for queue shape, and adds a bounded total for
	 * pagination.
	 *
	 * @param array<string,mixed> $filters status, operation_id, request_id.
	 * @return array<string,mixed>
	 */
	public function queue( array $filters, int $limit, int $offset ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcc_operation_queue';

		$limit  = min( self::MAX_LIMIT, max( 1, $limit ) );
		$offset = max( 0, $offset );

		$where  = [];
		$params = [];
		foreach ( [ 'status', 'operation_id', 'request_id' ] as $key ) {
			if ( ! empty( $filters[ $key ] ) ) {
				$where[]  = "{$key} = %s";
				$params[] = (string) $filters[ $key ];
			}
		}
		$where_sql = $where ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '';
		$count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
		$total     = (int) $wpdb->get_var(
			$params ? $wpdb->prepare( $count_sql, $params ) : $count_sql // phpcs:ignore WordPress.DB.PreparedSQL
		);

		$items     = ( new OperationQueue() )->list_items( array_merge( $filters, [ 'limit' => $limit, 'offset' => $offset ] ) );
		$items     = array_map( [ $this, 'format_queue_item' ], $items );
		$returned  = count( $items );
		$has_more  = ( $offset + $returned ) < $total;

		return [
			'action'      => 'approvals_queue',
			'items'       => $items,
			'total_count' => $total,
			'returned'    => $returned,
			'has_more'    => $has_more,
			'next_cursor' => $has_more ? $this->encode_cursor( $offset + $returned ) : null,
			'limit'       => $limit,
			'offset'      => $offset,
		];
	}

	/**
	 * Full detail for one request: the formatted row, decoded payload, linked
	 * queue item(s) and execution result(s), and a patch change-set summary when
	 * applicable. Read-only join over the existing managers.
	 *
	 * @return array<string,mixed>|null Null when the request id is unknown.
	 */
	public function detail( string $request_id ): ?array {
		$row = ( new OperationManager() )->get_request( $request_id );
		if ( ! $row ) {
			return null;
		}

		$formatted = $this->format_row( $row );
		$payload   = json_decode( (string) ( $row['payload'] ?? '{}' ), true ) ?: [];

		$queue_items = array_map(
			[ $this, 'format_queue_item' ],
			( new OperationQueue() )->list_items( [ 'request_id' => $request_id, 'limit' => 20 ] )
		);

		$results = array_map(
			[ $this, 'format_result' ],
			( new OperationResults() )->list_results( [ 'request_id' => $request_id, 'limit' => 20 ] )
		);

		$change_set = null;
		if ( 'patch_manage' === ( $row['operation_id'] ?? '' ) && 'patch_apply' === ( $payload['action'] ?? '' ) ) {
			$pid = isset( $payload['patch_id'] ) ? (string) $payload['patch_id'] : '';
			if ( '' !== $pid ) {
				$summary = PatchOperation::summarize_change_set( $pid );
				if ( is_array( $summary ) ) {
					$change_set = $summary;
				}
			}
		}

		return [
			'action'      => 'approval_detail',
			'request'     => $formatted,
			'payload'     => $this->strip_payload( $payload ),
			'queue_items' => $queue_items,
			'results'     => $results,
			'change_set'  => $change_set,
			'audit'       => $this->request_audit( $request_id ),
		];
	}

	/**
	 * STEP 106.2 — the per-request audit trail: the recorded lifecycle events
	 * (created / approved / rejected / queued / executed …) for this request_id,
	 * oldest→newest. Read-only tail of the append-only AuditLog; bounded scan.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function request_audit( string $request_id ): array {
		$entries = ( new AuditLog() )->tail( 500 );
		$trail   = [];

		foreach ( $entries as $entry ) {
			$ctx = is_array( $entry['context'] ?? null ) ? $entry['context'] : [];
			if ( ( $ctx['request_id'] ?? '' ) !== $request_id ) {
				continue;
			}
			$trail[] = [
				'timestamp' => (int) ( $entry['timestamp'] ?? 0 ),
				'action'    => (string) ( $entry['action'] ?? '' ),
				'actor'     => $this->audit_actor_label( $ctx['actor'] ?? null ),
			];
		}

		// tail() is newest-first; present the trail oldest-first (chronological).
		return array_reverse( $trail );
	}

	/**
	 * Human label for an audit-entry actor descriptor (already a resolved array
	 * in the JSONL), mirroring the Change History admin precedence.
	 */
	private function audit_actor_label( mixed $actor ): ?string {
		if ( ! is_array( $actor ) ) {
			return null;
		}
		foreach ( [ 'label', 'user_login', 'name', 'agent' ] as $key ) {
			if ( ! empty( $actor[ $key ] ) && is_string( $actor[ $key ] ) ) {
				return $actor[ $key ];
			}
		}
		$type = isset( $actor['type'] ) && is_string( $actor['type'] ) ? $actor['type'] : '';
		if ( 'admin' === $type && ! empty( $actor['user_id'] ) ) {
			return 'Admin #' . (int) $actor['user_id'];
		}
		return '' !== $type ? $type : null;
	}

	// ── Formatting ──────────────────────────────────────────────────────

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function format_row( array $row ): array {
		$operation = $this->registry->get_operation( (string) ( $row['operation_id'] ?? '' ) ) ?? [];
		$payload   = json_decode( (string) ( $row['payload'] ?? '{}' ), true ) ?: [];
		$action    = isset( $payload['action'] ) ? (string) $payload['action'] : '';

		$resolved_label = isset( $row['resolved_by_label'] ) && '' !== (string) $row['resolved_by_label']
			? (string) $row['resolved_by_label']
			: null;

		return [
			'request_id'    => (string) ( $row['request_id'] ?? '' ),
			'operation_id'  => (string) ( $row['operation_id'] ?? '' ),
			'operation'     => (string) ( $operation['title'] ?? $row['operation_id'] ?? '' ),
			'action'        => $action,
			'risk_level'    => SecurityModeManager::effective_risk( $operation, $action ),
			'status'        => (string) ( $row['status'] ?? '' ),
			'reason'        => isset( $payload['reason'] ) ? (string) $payload['reason'] : '',
			'resolved_by'   => $resolved_label,
			'resolved_type' => isset( $row['resolved_by_type'] ) && '' !== (string) $row['resolved_by_type']
				? (string) $row['resolved_by_type']
				: null,
			'created_at'    => (int) ( $row['created_at'] ?? 0 ),
			'approved_at'   => $this->ts( $row['approved_at'] ?? null ),
			'rejected_at'   => $this->ts( $row['rejected_at'] ?? null ),
			'executed_at'   => $this->ts( $row['executed_at'] ?? null ),
			'failed_at'     => $this->ts( $row['failed_at'] ?? null ),
			'cancelled_at'  => $this->ts( $row['cancelled_at'] ?? null ),
			'session_id'    => isset( $row['session_id'] ) ? (string) $row['session_id'] : null,
			'plan_id'       => isset( $row['plan_id'] ) ? (string) $row['plan_id'] : null,
		];
	}

	/**
	 * @param array<string,mixed> $item
	 * @return array<string,mixed>
	 */
	private function format_queue_item( array $item ): array {
		return [
			'queue_id'      => (string) ( $item['queue_id'] ?? '' ),
			'request_id'    => (string) ( $item['request_id'] ?? '' ),
			'operation_id'  => (string) ( $item['operation_id'] ?? '' ),
			'status'        => (string) ( $item['status'] ?? '' ),
			'priority'      => (int) ( $item['priority'] ?? 0 ),
			'attempts'      => (int) ( $item['attempts'] ?? 0 ),
			'max_attempts'  => (int) ( $item['max_attempts'] ?? 0 ),
			'error_message' => isset( $item['error_message'] ) ? (string) $item['error_message'] : '',
			'created_at'    => (int) ( $item['created_at'] ?? 0 ),
			'started_at'    => $this->ts( $item['started_at'] ?? null ),
			'completed_at'  => $this->ts( $item['completed_at'] ?? null ),
			'failed_at'     => $this->ts( $item['failed_at'] ?? null ),
		];
	}

	/**
	 * @param array<string,mixed> $result
	 * @return array<string,mixed>
	 */
	private function format_result( array $result ): array {
		return [
			'result_id'         => (string) ( $result['result_id'] ?? '' ),
			'operation_id'      => (string) ( $result['operation_id'] ?? '' ),
			'status'            => (string) ( $result['status'] ?? '' ),
			'execution_time_ms' => (int) ( $result['execution_time_ms'] ?? 0 ),
			'created_count'     => (int) ( $result['created_count'] ?? 0 ),
			'updated_count'     => (int) ( $result['updated_count'] ?? 0 ),
			'skipped_count'     => (int) ( $result['skipped_count'] ?? 0 ),
			'error_count'       => (int) ( $result['error_count'] ?? 0 ),
			'error_json'        => is_array( $result['error_json'] ?? null ) ? $result['error_json'] : [],
			'created_at'        => (int) ( $result['created_at'] ?? 0 ),
		];
	}

	// ── Helpers ─────────────────────────────────────────────────────────

	/**
	 * Build the WHERE clause + params for the history query, plus an echo of the
	 * filters that were actually applied.
	 *
	 * @param array<string,mixed> $filters
	 * @return array{0:array<int,string>,1:array<int,mixed>,2:array<string,mixed>}
	 */
	private function build_where( array $filters ): array {
		$where   = [];
		$params  = [];
		$applied = [];

		// status: array (IN) or scalar. History represents the RESOLVED lifecycle
		// only — pending_review belongs exclusively to the Pending tab — so we
		// drop it from any explicit filter and default to the resolved set when no
		// status filter is supplied. This guarantees a pending request never
		// appears in History.
		$statuses = [];
		if ( isset( $filters['status'] ) ) {
			$statuses = is_array( $filters['status'] ) ? $filters['status'] : [ $filters['status'] ];
			$statuses = array_values( array_filter(
				array_map( 'strval', $statuses ),
				static fn( $s ) => '' !== $s && OperationManager::STATUS_PENDING_REVIEW !== $s
			) );
		}
		if ( ! $statuses ) {
			$statuses = self::RESOLVED_STATUSES;
		}
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$where[]      = "status IN ({$placeholders})";
		foreach ( $statuses as $s ) {
			$params[] = $s;
		}
		$applied['status'] = $statuses;

		if ( ! empty( $filters['risk'] ) ) {
			$where[]          = 'risk_level = %s';
			$params[]         = (string) $filters['risk'];
			$applied['risk']  = (string) $filters['risk'];
		}

		if ( ! empty( $filters['operation_id'] ) ) {
			$where[]                  = 'operation_id = %s';
			$params[]                 = (string) $filters['operation_id'];
			$applied['operation_id']  = (string) $filters['operation_id'];
		}

		if ( ! empty( $filters['actor'] ) ) {
			$where[]          = 'resolved_by_label LIKE %s';
			$params[]         = '%' . $GLOBALS['wpdb']->esc_like( (string) $filters['actor'] ) . '%';
			$applied['actor'] = (string) $filters['actor'];
		}

		if ( ! empty( $filters['from'] ) ) {
			$where[]          = 'created_at >= %d';
			$params[]         = (int) $filters['from'];
			$applied['from']  = (int) $filters['from'];
		}

		if ( ! empty( $filters['to'] ) ) {
			$where[]        = 'created_at <= %d';
			$params[]       = (int) $filters['to'];
			$applied['to']  = (int) $filters['to'];
		}

		return [ $where, $params, $applied ];
	}

	/**
	 * Defense in depth: never surface raw patch/file contents in the detail
	 * payload. Mirrors the OperationResults strip posture.
	 *
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	private function strip_payload( array $payload ): array {
		foreach ( [ 'contents', 'content', 'file_contents' ] as $key ) {
			if ( isset( $payload[ $key ] ) ) {
				$payload[ $key ] = '[redacted]';
			}
		}
		return $payload;
	}

	private function ts( mixed $value ): ?int {
		return ( null === $value || '' === $value ) ? null : (int) $value;
	}

	private function encode_cursor( int $offset ): string {
		return base64_encode( (string) wp_json_encode( [ 'offset' => $offset ] ) );
	}
}
