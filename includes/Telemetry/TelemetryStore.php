<?php
/**
 * PROGRAM-8 — runtime telemetry store (self-provisioning, additive, decoupled).
 *
 * Stores RUNTIME FACTS about jobs/executions so future dashboards (Mission
 * Control, Job Center, Usage & Cost, reporting) can read them — never compute
 * business logic in a view. It OBSERVES the runtime; it does not change it.
 *
 * Storage: its OWN table `{prefix}wpcc_telemetry`, created lazily via
 * `CREATE TABLE IF NOT EXISTS` and DELIBERATELY DECOUPLED from Schema::DB_VERSION
 * (the 2.5.0 invariant is untouched). Additive + non-destructive: it can never
 * conflict with the core schema versioning.
 *
 * Honesty: unmeasurable facts are stored as NULL ("unknown"), never invented.
 * Indexed for status/operation/provider/created_at/job_id; pruneable for growth.
 */

namespace WPCommandCenter\Telemetry;

defined( 'ABSPATH' ) || exit;

final class TelemetryStore {

	private const TABLE = 'wpcc_telemetry';

	/** Columns that are integers/nullable measurements (NULL = unknown). */
	private const NULLABLE_INT = [ 'started_at', 'completed_at', 'duration_ms', 'queue_ms', 'exec_ms', 'approval_wait_ms', 'tokens_input', 'tokens_output', 'estimated_cost_micros', 'rollback_available' ];

	public function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/** Per-request guard so we don't issue CREATE TABLE on every insert. */
	private static bool $ensured = false;

	/** Lazily provision the telemetry table. Idempotent; never destructive. */
	public function ensure_table(): void {
		if ( self::$ensured ) {
			return;
		}
		global $wpdb;
		self::$ensured = true;
		$table   = $this->table();
		$collate = $wpdb->get_charset_collate();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				job_id VARCHAR(64) NOT NULL DEFAULT '',
				kind VARCHAR(32) NOT NULL DEFAULT 'operation',
				operation VARCHAR(64) NOT NULL DEFAULT '',
				capability VARCHAR(64) NOT NULL DEFAULT '',
				provider VARCHAR(64) NOT NULL DEFAULT '',
				model VARCHAR(128) NOT NULL DEFAULT '',
				status VARCHAR(24) NOT NULL DEFAULT 'unknown',
				started_at INT UNSIGNED NULL,
				completed_at INT UNSIGNED NULL,
				duration_ms INT UNSIGNED NULL,
				queue_ms INT UNSIGNED NULL,
				exec_ms INT UNSIGNED NULL,
				approval_wait_ms INT UNSIGNED NULL,
				tokens_input INT UNSIGNED NULL,
				tokens_output INT UNSIGNED NULL,
				estimated_cost_micros BIGINT UNSIGNED NULL,
				currency VARCHAR(8) NOT NULL DEFAULT 'USD',
				error_code VARCHAR(64) NOT NULL DEFAULT '',
				retry_count INT UNSIGNED NOT NULL DEFAULT 0,
				cancelled TINYINT(1) NOT NULL DEFAULT 0,
				rollback_available TINYINT(1) NULL,
				actor_type VARCHAR(24) NOT NULL DEFAULT '',
				created_at INT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY (id),
				KEY job_id (job_id),
				KEY status (status),
				KEY operation (operation),
				KEY provider (provider),
				KEY kind (kind),
				KEY created_at (created_at)
			) {$collate}"
		);
	}

	/** True if the table exists (no provisioning). */
	public function exists(): bool {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * Insert a telemetry row. Unknown fields MUST be passed as null (stored NULL).
	 * Returns the row id, or 0 on failure (never throws into the runtime).
	 *
	 * @param array<string,mixed> $row
	 */
	public function insert( array $row ): int {
		global $wpdb;
		$this->ensure_table();
		$data = $this->normalize( $row );
		$data['created_at'] = time();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert( $this->table(), $data, $this->formats( $data ) );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/** Update a row by job_id (latest matching). Unknown fields = null. */
	public function update_by_job( string $job_id, array $row ): bool {
		global $wpdb;
		if ( '' === $job_id || ! $this->exists() ) {
			return false;
		}
		$data = $this->normalize( $row );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $wpdb->update( $this->table(), $data, [ 'job_id' => $job_id ], $this->formats( $data ), [ '%s' ] );
	}

	/** Coerce a row to the column set; map unknowns to null. */
	private function normalize( array $row ): array {
		$cols = [ 'job_id', 'kind', 'operation', 'capability', 'provider', 'model', 'status',
			'started_at', 'completed_at', 'duration_ms', 'queue_ms', 'exec_ms', 'approval_wait_ms',
			'tokens_input', 'tokens_output', 'estimated_cost_micros', 'currency', 'error_code',
			'retry_count', 'cancelled', 'rollback_available', 'actor_type' ];
		$out = [];
		foreach ( $cols as $c ) {
			if ( ! array_key_exists( $c, $row ) ) {
				continue;
			}
			$v = $row[ $c ];
			if ( in_array( $c, self::NULLABLE_INT, true ) ) {
				$out[ $c ] = ( null === $v || '' === $v ) ? null : (int) $v; // null = unknown
			} else {
				$out[ $c ] = is_int( $v ) ? $v : (string) $v;
			}
		}
		return $out;
	}

	private function formats( array $data ): array {
		$fmt = [];
		foreach ( array_keys( $data ) as $c ) {
			$fmt[] = ( in_array( $c, self::NULLABLE_INT, true ) || in_array( $c, [ 'retry_count', 'cancelled', 'created_at' ], true ) ) ? '%d' : '%s';
		}
		return $fmt;
	}

	/** Prune rows older than $days (growth control). Returns rows deleted. */
	public function prune( int $days = 90 ): int {
		global $wpdb;
		if ( ! $this->exists() ) {
			return 0;
		}
		$cutoff = time() - ( max( 1, $days ) * DAY_IN_SECONDS );
		$table  = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %d", $cutoff ) );
	}

	/** Fetch the latest row for a job_id (for lifecycle backfill), or null. */
	public function get_by_job( string $job_id ): ?array {
		global $wpdb;
		if ( '' === $job_id || ! $this->exists() ) {
			return null;
		}
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE job_id = %s ORDER BY id DESC LIMIT 1", $job_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public function count(): int {
		global $wpdb;
		if ( ! $this->exists() ) {
			return 0;
		}
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}
}
