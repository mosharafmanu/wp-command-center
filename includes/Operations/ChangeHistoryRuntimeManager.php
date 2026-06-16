<?php
/**
 * STEP 104.2 — Change History runtime (read-only).
 *
 * Read side of the STEP 104 change-history system. Queries the
 * `wpcc_change_log` table (the STEP 104.1 system of record) — never the
 * AuditLog JSONL tail — and returns a uniform, cursor-paginated, compact-safe
 * envelope (STEP 103.2 trust fields: total_count / has_more / next_cursor).
 *
 * All actions are diagnostic: they never trigger approval, destructive
 * confirmation, or rollback routing. `rollback_target` (the one write action)
 * arrives in STEP 104.3 — this runtime is index + read only.
 *
 * Mode-determinism (compact|standard|verbose): this manager returns identical
 * data in every mode; only the MCP layer's list shaping differs (the top-level
 * total_count/has_more/next_cursor are scalars that survive compaction, exactly
 * like search's match_count).
 */

namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class ChangeHistoryRuntimeManager {

	private const DEFAULT_LIMIT = 20;
	private const MAX_LIMIT      = 100;

	/** Columns selected and returned (full record). */
	private const COLUMNS = [
		'change_id', 'operation_id', 'action', 'runtime', 'status', 'reversible',
		'rollback_kind', 'rollback_id', 'rolled_back_by_change_id', 'change_set_id',
		'request_id', 'session_id', 'task_id', 'plan_id', 'action_id',
		'actor_json', 'risk_level', 'source', 'target_summary', 'target_key',
		'created_count', 'updated_count', 'skipped_count', 'error_count',
		'result_ref', 'created_at', 'rolled_back_at',
	];

	public function run( array $p, array $cx = [] ): array {
		$action = (string) ( $p['action'] ?? '' );

		switch ( $action ) {
			case 'history_list':
				return $this->history_list( $p );
			case 'history_get':
				return $this->history_get( $p );
			case 'history_timeline':
				return $this->history_timeline( $p );
			default:
				return $this->err( 'wpcc_invalid_history_action', sprintf(
					/* translators: %s: action */
					__( 'Unknown change_history action: %s. Supported: history_list, history_get, history_timeline.', 'wp-command-center' ),
					$action
				) );
		}
	}

	// ── history_list ────────────────────────────────────────────────

	private function history_list( array $p ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcc_change_log';

		[ $where, $params, $filters ] = $this->build_filters( $p );
		[ $limit, $offset ]           = $this->paging( $p );

		$where_sql = $where ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '';

		$total = (int) $wpdb->get_var(
			$params
				? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where_sql}", $params )
				: "SELECT COUNT(*) FROM {$table} {$where_sql}"
		);

		$rows = $this->fetch_rows( $table, $where_sql, $params, 'created_at DESC, id DESC', $limit, $offset );

		return $this->envelope( 'history_list', 'changes', $rows, $total, $limit, $offset, $filters );
	}

	// ── history_get ─────────────────────────────────────────────────

	private function history_get( array $p ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcc_change_log';

		$change_id = sanitize_text_field( (string) ( $p['change_id'] ?? '' ) );
		if ( '' === $change_id ) {
			return $this->err( 'wpcc_missing_change_id', __( 'change_id is required for history_get.', 'wp-command-center' ) );
		}

		$cols = implode( ', ', self::COLUMNS );
		$row  = $wpdb->get_row(
			$wpdb->prepare( "SELECT {$cols} FROM {$table} WHERE change_id = %s LIMIT 1", $change_id ),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return $this->err( 'wpcc_change_not_found', sprintf(
				/* translators: %s: change id */
				__( 'No change found for change_id %s.', 'wp-command-center' ),
				$change_id
			) );
		}

		$change = $this->format_row( $row );

		// Result metadata: a lightweight read-only join to the linked
		// operation_results row (no content blobs).
		$result_meta = null;
		if ( ! empty( $row['result_ref'] ) ) {
			$results_table = $wpdb->prefix . 'wpcc_operation_results';
			$res           = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT result_id, status, execution_time_ms, created_count, updated_count, skipped_count, error_count, created_at FROM {$results_table} WHERE result_id = %s LIMIT 1",
					(string) $row['result_ref']
				),
				ARRAY_A
			);
			if ( is_array( $res ) ) {
				$result_meta = [
					'result_id'         => (string) $res['result_id'],
					'status'            => (string) $res['status'],
					'execution_time_ms' => (int) $res['execution_time_ms'],
					'created_count'     => (int) $res['created_count'],
					'updated_count'     => (int) $res['updated_count'],
					'skipped_count'     => (int) $res['skipped_count'],
					'error_count'       => (int) $res['error_count'],
					'created_at'        => (int) $res['created_at'],
				];
			}
		}
		$change['result'] = $result_meta;

		return [
			'action' => 'history_get',
			'change' => $change,
		];
	}

	// ── history_timeline ────────────────────────────────────────────

	private function history_timeline( array $p ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcc_change_log';

		// Chronological view, table-backed (no AuditLog tail). Optional time-window
		// filters; newest-first like the legacy /agent/timeline.
		$where   = [];
		$params  = [];
		$filters = [];
		$this->time_window( $p, $where, $params, $filters );

		[ $limit, $offset ] = $this->paging( $p );
		$where_sql          = $where ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '';

		$total = (int) $wpdb->get_var(
			$params
				? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where_sql}", $params )
				: "SELECT COUNT(*) FROM {$table} {$where_sql}"
		);

		$rows = $this->fetch_rows( $table, $where_sql, $params, 'created_at DESC, id DESC', $limit, $offset );

		return $this->envelope( 'history_timeline', 'timeline', $rows, $total, $limit, $offset, $filters );
	}

	// ── filters / paging / formatting ───────────────────────────────

	/**
	 * @return array{0: string[], 1: array<int,mixed>, 2: array<string,mixed>}
	 */
	private function build_filters( array $p ): array {
		$where   = [];
		$params  = [];
		$filters = [];

		$map = [
			'runtime'       => 'runtime',
			'operation_id'  => 'operation_id',
			'status'        => 'status',
			'target'        => 'target_key',
			'change_set_id' => 'change_set_id',
			'session_id'    => 'session_id',
			'task_id'       => 'task_id',
			'plan_id'       => 'plan_id',
		];
		foreach ( $map as $param => $column ) {
			if ( isset( $p[ $param ] ) && '' !== (string) $p[ $param ] ) {
				$value          = sanitize_text_field( (string) $p[ $param ] );
				$where[]        = "{$column} = %s";
				$params[]       = $value;
				$filters[ $param ] = $value;
			}
		}

		if ( ! empty( $p['reversible_only'] ) && $this->truthy( $p['reversible_only'] ) ) {
			$where[]              = 'reversible = %d';
			$params[]             = 1;
			$filters['reversible_only'] = true;
		}

		$this->time_window( $p, $where, $params, $filters );

		return [ $where, $params, $filters ];
	}

	/**
	 * Append since/until (created_at) constraints.
	 *
	 * @param string[]            $where
	 * @param array<int,mixed>    $params
	 * @param array<string,mixed> $filters
	 */
	private function time_window( array $p, array &$where, array &$params, array &$filters ): void {
		if ( isset( $p['since'] ) && '' !== (string) $p['since'] ) {
			$where[]           = 'created_at >= %d';
			$params[]          = (int) $p['since'];
			$filters['since']  = (int) $p['since'];
		}
		if ( isset( $p['until'] ) && '' !== (string) $p['until'] ) {
			$where[]           = 'created_at <= %d';
			$params[]          = (int) $p['until'];
			$filters['until']  = (int) $p['until'];
		}
	}

	/**
	 * @return array{0:int,1:int} [limit, offset]
	 */
	private function paging( array $p ): array {
		$limit  = min( self::MAX_LIMIT, max( 1, (int) ( $p['limit'] ?? self::DEFAULT_LIMIT ) ) );
		$offset = 0;

		$cursor = (string) ( $p['cursor'] ?? '' );
		if ( '' !== $cursor ) {
			$decoded = json_decode( (string) base64_decode( $cursor, true ), true );
			if ( is_array( $decoded ) && isset( $decoded['offset'] ) ) {
				$offset = max( 0, (int) $decoded['offset'] );
			}
		}

		return [ $limit, $offset ];
	}

	/**
	 * @param array<int,mixed> $params
	 * @return array<int,array<string,mixed>>
	 */
	private function fetch_rows( string $table, string $where_sql, array $params, string $order, int $limit, int $offset ): array {
		global $wpdb;
		$cols = implode( ', ', self::COLUMNS );

		$sql       = "SELECT {$cols} FROM {$table} {$where_sql} ORDER BY {$order} LIMIT %d OFFSET %d";
		$all_params = array_merge( $params, [ $limit, $offset ] );

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $all_params ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return [];
		}

		return array_map( [ $this, 'format_row' ], $rows );
	}

	/**
	 * Build the STEP 103.2 compact envelope around a result list.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @param array<string,mixed>            $filters
	 */
	private function envelope( string $action, string $list_key, array $rows, int $total, int $limit, int $offset, array $filters ): array {
		$returned    = count( $rows );
		$next_offset = $offset + $returned;
		$has_more    = $next_offset < $total;

		return [
			'action'      => $action,
			$list_key     => $rows,
			'total_count' => $total,
			'returned'    => $returned,
			'count'       => $total, // back-compat alias of total_count (per STEP 103.2)
			'has_more'    => $has_more,
			'next_cursor' => $has_more ? base64_encode( (string) wp_json_encode( [ 'offset' => $next_offset ] ) ) : null,
			'limit'       => $limit,
			'offset'      => $offset,
			'filters'     => (object) $filters,
		];
	}

	/**
	 * Normalize a raw DB row into a typed, decoded change record with explicit
	 * rollback + change-set + actor metadata.
	 *
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function format_row( array $row ): array {
		$reversible = (int) ( $row['reversible'] ?? 0 );
		$status     = (string) ( $row['status'] ?? '' );

		return [
			'change_id'      => (string) ( $row['change_id'] ?? '' ),
			'operation_id'   => (string) ( $row['operation_id'] ?? '' ),
			'action'         => null !== ( $row['action'] ?? null ) ? (string) $row['action'] : null,
			'runtime'        => null !== ( $row['runtime'] ?? null ) ? (string) $row['runtime'] : null,
			'status'         => $status,
			'risk_level'     => null !== ( $row['risk_level'] ?? null ) ? (string) $row['risk_level'] : null,
			'source'         => null !== ( $row['source'] ?? null ) ? (string) $row['source'] : null,
			'actor'          => $this->decode_json( $row['actor_json'] ?? null ),
			'target_key'     => null !== ( $row['target_key'] ?? null ) ? (string) $row['target_key'] : null,
			'target_summary' => $this->decode_json( $row['target_summary'] ?? null ),
			'counts'         => [
				'created' => (int) ( $row['created_count'] ?? 0 ),
				'updated' => (int) ( $row['updated_count'] ?? 0 ),
				'skipped' => (int) ( $row['skipped_count'] ?? 0 ),
				'error'   => (int) ( $row['error_count'] ?? 0 ),
			],
			'rollback'       => [
				'reversible'               => 1 === $reversible,
				'kind'                     => (string) ( $row['rollback_kind'] ?? 'none' ),
				'rollback_id'              => null !== ( $row['rollback_id'] ?? null ) ? (string) $row['rollback_id'] : null,
				'change_set_id'            => null !== ( $row['change_set_id'] ?? null ) ? (string) $row['change_set_id'] : null,
				'rolled_back'              => 'rolled_back' === $status,
				'rolled_back_at'           => null !== ( $row['rolled_back_at'] ?? null ) ? (int) $row['rolled_back_at'] : null,
				'rolled_back_by_change_id' => null !== ( $row['rolled_back_by_change_id'] ?? null ) ? (string) $row['rolled_back_by_change_id'] : null,
			],
			'change_set_id'  => null !== ( $row['change_set_id'] ?? null ) ? (string) $row['change_set_id'] : null,
			'links'          => [
				'request_id' => null !== ( $row['request_id'] ?? null ) ? (string) $row['request_id'] : null,
				'session_id' => null !== ( $row['session_id'] ?? null ) ? (string) $row['session_id'] : null,
				'task_id'    => null !== ( $row['task_id'] ?? null ) ? (string) $row['task_id'] : null,
				'plan_id'    => null !== ( $row['plan_id'] ?? null ) ? (string) $row['plan_id'] : null,
				'action_id'  => null !== ( $row['action_id'] ?? null ) ? (string) $row['action_id'] : null,
			],
			'result_ref'     => null !== ( $row['result_ref'] ?? null ) ? (string) $row['result_ref'] : null,
			'created_at'     => (int) ( $row['created_at'] ?? 0 ),
		];
	}

	private function decode_json( mixed $value ): mixed {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		$decoded = json_decode( $value, true );
		return null === $decoded ? null : $decoded;
	}

	private function truthy( mixed $v ): bool {
		return in_array( $v, [ true, 1, '1', 'true', 'yes', 'on' ], true );
	}

	private function err( string $code, string $message ): array {
		return [ 'error' => true, 'code' => $code, 'message' => $message ];
	}
}
