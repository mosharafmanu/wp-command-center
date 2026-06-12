<?php
/**
 * Step 23 — Operation Results Store.
 *
 * Manages the persistent storage and retrieval of operation execution results.
 */

namespace WPCommandCenter\Operations;

use WPCommandCenter\Security\AuditLog;

defined( 'ABSPATH' ) || exit;

final class OperationResults {

	/**
	 * Create a new result record.
	 *
	 * @param array $data {
	 *     operation_id: string,
	 *     status: string,
	 *     execution_time_ms: int,
	 *     created_count: int,
	 *     updated_count: int,
	 *     skipped_count: int,
	 *     error_count: int,
	 *     result_json: string,
	 *     error_json: string,
	 *     started_at: int,
	 *     completed_at: int,
	 *     queue_id?: string,
	 *     request_id?: string
	 * }
	 * @return string Result ID (UUID).
	 */
	public function create( array $data ): string {
		global $wpdb;

		$result_id = wp_generate_uuid4();
		$now       = time();

		$wpdb->insert(
			$wpdb->prefix . 'wpcc_operation_results',
			[
				'result_id'         => $result_id,
				'queue_id'          => $data['queue_id'] ?? null,
				'request_id'        => $data['request_id'] ?? null,
				'operation_id'      => $data['operation_id'],
				'status'            => $data['status'],
				'execution_time_ms' => $data['execution_time_ms'] ?? 0,
				'created_count'     => $data['created_count'] ?? 0,
				'updated_count'     => $data['updated_count'] ?? 0,
				'skipped_count'     => $data['skipped_count'] ?? 0,
				'error_count'       => $data['error_count'] ?? 0,
				'result_json'       => $data['result_json'] ?? null,
				'error_json'        => $data['error_json'] ?? null,
				'started_at'        => $data['started_at'],
				'completed_at'      => $data['completed_at'] ?? $now,
				'created_at'        => $now,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%d' ]
		);

		( new AuditLog() )->record( 'operation.result.created', [
			'result_id'    => $result_id,
			'operation_id' => $data['operation_id'],
			'queue_id'     => $data['queue_id'] ?? null,
			'request_id'   => $data['request_id'] ?? null,
			'session_id'   => $data['session_id'] ?? null,
			'task_id'      => $data['task_id'] ?? null,
			'action_id'    => $data['action_id'] ?? null,
			'plan_id'      => $data['plan_id'] ?? null,
		] );

		return $result_id;
	}

	public function get_result( string $result_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcc_operation_results WHERE result_id = %s", $result_id ), ARRAY_A );
		return $row ? $this->normalize_result( $row ) : null;
	}

	public function list_results( array $filters = [] ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcc_operation_results';
		$sql   = "SELECT * FROM {$table}";
		$where = [];
		$params = [];

		foreach ( [ 'operation_id', 'queue_id', 'request_id', 'status' ] as $key ) {
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

		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return array_map( [ $this, 'normalize_result' ], $rows ?: [] );
	}

	private function normalize_result( array $row ): array {
		$row['result_json'] = json_decode( $row['result_json'], true ) ?: [];
		$row['error_json']  = json_decode( $row['error_json'], true ) ?: [];
		$row['execution_time_ms'] = (int) $row['execution_time_ms'];
		$row['created_count'] = (int) $row['created_count'];
		$row['updated_count'] = (int) $row['updated_count'];
		$row['skipped_count'] = (int) $row['skipped_count'];
		$row['error_count'] = (int) $row['error_count'];
		$row['started_at'] = (int) $row['started_at'];
		$row['completed_at'] = (int) $row['completed_at'];
		$row['created_at'] = (int) $row['created_at'];

		return $row;
	}
}
