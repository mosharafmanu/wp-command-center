<?php
/**
 * Idempotency journal for MCP write operations.
 *
 * A client (the relay) supplies a stable idempotency key per tools/call. The key
 * is claimed atomically BEFORE the operation runs, so a call that times out on the
 * transport and is retried with the same key never executes twice — the retry gets
 * the recorded result (or is refused while the original is still in flight) instead
 * of creating duplicate/orphan state.
 *
 * The atomicity relies on the UNIQUE(idem_key) constraint: `INSERT IGNORE` inserts
 * exactly one row for the first caller and reports 0 rows for every duplicate,
 * which is race-safe (unlike WordPress add_option, whose INSERT … ON DUPLICATE KEY
 * UPDATE would overwrite). See Schema::install() for the table definition.
 */

namespace WPCommandCenter\Mcp;

defined( 'ABSPATH' ) || exit;

final class IdempotencyStore {

	/**
	 * A claim still marked in_progress after this many seconds is treated as
	 * stale — the original request presumably died mid-flight — and may be
	 * re-claimed so a legitimate retry is not blocked forever.
	 */
	private const STALE_SECONDS = 300;

	/** Completed records older than this are pruned opportunistically. */
	private const TTL_SECONDS = 86400;

	private string $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'wpcc_idempotency';
	}

	/**
	 * Atomically claim a key before executing.
	 *
	 * @return array{claim:string,result?:array<string,mixed>} One of:
	 *   ['claim' => 'new']                     → caller should execute, then complete()
	 *   ['claim' => 'done', 'result' => array] → return this recorded result; do NOT execute
	 *   ['claim' => 'in_progress']             → a concurrent request holds it; do NOT execute
	 */
	public function claim( string $key, string $tool ): array {
		global $wpdb;
		$now = time();

		// Atomic: INSERT IGNORE inserts one row for the first caller (1 affected)
		// and silently no-ops for a duplicate key (0 affected) — no overwrite.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$this->table} (idem_key, tool, status, created_at) VALUES (%s, %s, 'in_progress', %d)",
				$key,
				substr( $tool, 0, 191 ),
				$now
			)
		);

		if ( 1 === (int) $inserted ) {
			return [ 'claim' => 'new' ];
		}

		// Duplicate key — read the prior record.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT status, result_json, created_at FROM {$this->table} WHERE idem_key = %s", $key ),
			ARRAY_A
		);

		if ( ! $row ) {
			// Row vanished between the failed INSERT and this SELECT (very rare) —
			// let the caller execute rather than dead-lock the request.
			return [ 'claim' => 'new' ];
		}

		if ( 'done' === $row['status'] ) {
			$result = json_decode( (string) $row['result_json'], true );
			return [ 'claim' => 'done', 'result' => is_array( $result ) ? $result : [] ];
		}

		// Still in_progress. If the claim is stale, the original request likely died
		// before completing; re-arm it so this retry can proceed.
		if ( ( $now - (int) $row['created_at'] ) > self::STALE_SECONDS ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$this->table} SET created_at = %d, result_json = NULL, completed_at = NULL WHERE idem_key = %s AND status = 'in_progress'",
					$now,
					$key
				)
			);
			return [ 'claim' => 'new' ];
		}

		return [ 'claim' => 'in_progress' ];
	}

	/**
	 * Record the terminal result (success or failure) so any retry returns it.
	 *
	 * @param array<string,mixed> $result The MCP tools/call response to replay.
	 */
	public function complete( string $key, array $result ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table} SET status = 'done', result_json = %s, completed_at = %d WHERE idem_key = %s",
				(string) wp_json_encode( $result ),
				time(),
				$key
			)
		);
		$this->prune();
	}

	/**
	 * Look up the journaled outcome for a key (powers change_history operation_status).
	 * Lets a client that lost a response ask "did my request with key X commit?".
	 *
	 * @return array{found:bool,status?:string,tool?:string,created_at?:int,completed_at?:?int,result?:?array<string,mixed>}
	 */
	public function lookup( string $key ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT status, tool, result_json, created_at, completed_at FROM {$this->table} WHERE idem_key = %s", $key ),
			ARRAY_A
		);
		if ( ! $row ) {
			return [ 'found' => false ];
		}
		$result = json_decode( (string) $row['result_json'], true );
		return [
			'found'        => true,
			'status'       => (string) $row['status'],
			'tool'         => (string) $row['tool'],
			'created_at'   => (int) $row['created_at'],
			'completed_at' => null !== $row['completed_at'] ? (int) $row['completed_at'] : null,
			'result'       => is_array( $result ) ? $result : null,
		];
	}

	/**
	 * Release an in_progress claim (e.g. if the caller bailed before executing) so
	 * the key can be retried cleanly.
	 */
	public function release( string $key ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$this->table} WHERE idem_key = %s AND status = 'in_progress'", $key )
		);
	}

	/** Opportunistic GC of records past their TTL (indexed on created_at). */
	private function prune(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$this->table} WHERE created_at < %d", time() - self::TTL_SECONDS )
		);
	}
}
