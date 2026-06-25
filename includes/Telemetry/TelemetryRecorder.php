<?php
/**
 * PROGRAM-8 — telemetry recorder (the sanctioned push API).
 *
 * The official, behavior-neutral insertion point for recording runtime facts. It
 * NEVER throws into the runtime (every write is guarded) and NEVER changes
 * execution — a telemetry failure must never affect an operation's outcome.
 *
 * Two usage modes:
 *   - lifecycle: start() → complete()/fail()/cancel()/retry() correlated by job_id
 *     (for future runtime instrumentation to call directly);
 *   - one-shot: record() for a single terminal fact (used by the audit observer).
 *
 * Derived facts are computed honestly: duration from start/complete timestamps;
 * estimated cost from measured tokens + a known model price (else NULL/unknown).
 */

namespace WPCommandCenter\Telemetry;

defined( 'ABSPATH' ) || exit;

final class TelemetryRecorder {

	private TelemetryStore $store;

	public function __construct( ?TelemetryStore $store = null ) {
		$this->store = $store ?? new TelemetryStore();
	}

	/** Generate a correlation id when the caller doesn't supply one. */
	public static function new_job_id(): string {
		return 'job_' . str_replace( '-', '', wp_generate_uuid4() );
	}

	/** Begin a job (status=running). Returns the job_id. Never throws. */
	public function start( string $job_id, string $kind, array $fields = [] ): string {
		try {
			$job_id = '' !== $job_id ? $job_id : self::new_job_id();
			$row = array_merge( [
				'job_id'     => $job_id,
				'kind'       => $kind,
				'status'     => 'running',
				'started_at' => time(),
			], $this->clean( $fields ) );
			$this->store->insert( $row );
		} catch ( \Throwable $e ) { /* telemetry must never break the runtime */ }
		return $job_id;
	}

	/** Mark a job completed; derives duration + estimated cost. Never throws. */
	public function complete( string $job_id, array $fields = [] ): void {
		$this->terminate( $job_id, 'completed', $fields );
	}

	/** Mark a job failed with an error code. Never throws. */
	public function fail( string $job_id, string $error_code, array $fields = [] ): void {
		$fields['error_code'] = $error_code;
		$this->terminate( $job_id, 'failed', $fields );
	}

	/** Mark a job cancelled. Never throws. */
	public function cancel( string $job_id, array $fields = [] ): void {
		$fields['cancelled'] = 1;
		$this->terminate( $job_id, 'cancelled', $fields );
	}

	/** Increment retry count for a job. Never throws. */
	public function retry( string $job_id, int $count, array $fields = [] ): void {
		try {
			$fields['retry_count'] = max( 0, $count );
			$this->store->update_by_job( $job_id, $this->clean( $fields ) );
		} catch ( \Throwable $e ) {}
	}

	/**
	 * One-shot terminal record (no prior start row) — used by the audit observer.
	 * Inserts a single row with whatever facts are known; unknowns stay NULL.
	 */
	public function record( array $fields ): void {
		try {
			$row = $this->clean( $fields );
			if ( empty( $row['job_id'] ) ) {
				$row['job_id'] = self::new_job_id();
			}
			if ( empty( $row['status'] ) ) {
				$row['status'] = 'completed';
			}
			$row = $this->derive( $row );
			$this->store->insert( $row );
		} catch ( \Throwable $e ) {}
	}

	/* ---------------- internals ---------------- */

	private function terminate( string $job_id, string $status, array $fields ): void {
		try {
			$row = $this->clean( $fields );
			$row['status']       = $status;
			$row['completed_at'] = $row['completed_at'] ?? time();
			// Backfill derive-inputs from the start() row (model/started_at/tokens)
			// so cost + duration compute even when complete() doesn't re-pass them.
			if ( '' !== $job_id ) {
				$prior = $this->store->get_by_job( $job_id );
				if ( is_array( $prior ) ) {
					foreach ( [ 'model', 'provider', 'started_at', 'tokens_input', 'tokens_output' ] as $k ) {
						if ( ! isset( $row[ $k ] ) && isset( $prior[ $k ] ) && '' !== (string) $prior[ $k ] && null !== $prior[ $k ] ) {
							$row[ $k ] = $prior[ $k ];
						}
					}
				}
			}
			$row = $this->derive( $row );
			if ( '' === $job_id || ! $this->store->update_by_job( $job_id, $row ) ) {
				$row['job_id'] = '' !== $job_id ? $job_id : self::new_job_id();
				$this->store->insert( $row );
			}
		} catch ( \Throwable $e ) {}
	}

	/** Compute duration + estimated cost from measured facts (honest; null if unknown). */
	private function derive( array $row ): array {
		if ( empty( $row['duration_ms'] ) && ! empty( $row['started_at'] ) && ! empty( $row['completed_at'] ) ) {
			$row['duration_ms'] = max( 0, ( (int) $row['completed_at'] - (int) $row['started_at'] ) * 1000 );
		}
		$model = (string) ( $row['model'] ?? '' );
		$ti    = isset( $row['tokens_input'] ) ? (int) $row['tokens_input'] : null;
		$to    = isset( $row['tokens_output'] ) ? (int) $row['tokens_output'] : null;
		if ( '' !== $model && ( null !== $ti || null !== $to ) && ! isset( $row['estimated_cost_micros'] ) ) {
			$row['estimated_cost_micros'] = CostModel::estimate_micros( $model, $ti, $to ); // null if unpriced
		}
		return $row;
	}

	/** Whitelist + light sanitize input fields (no secrets ever pass through). */
	private function clean( array $fields ): array {
		$allowed = [ 'job_id', 'kind', 'operation', 'capability', 'provider', 'model', 'status',
			'started_at', 'completed_at', 'duration_ms', 'queue_ms', 'exec_ms', 'approval_wait_ms',
			'tokens_input', 'tokens_output', 'estimated_cost_micros', 'currency', 'error_code',
			'retry_count', 'cancelled', 'rollback_available', 'actor_type' ];
		$out = [];
		foreach ( $allowed as $k ) {
			if ( array_key_exists( $k, $fields ) ) {
				$out[ $k ] = $fields[ $k ];
			}
		}
		return $out;
	}
}
