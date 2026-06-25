<?php
/**
 * PROGRAM-10 — Operations Center read model (read-only; existing data only).
 *
 * Composes EXISTING read-only sources — TelemetryQuery (P8), AiActivity (P7,
 * audit + approval queue), ChangeHistoryAdminQuery (reversible sessions) — into
 * the Operations Center's sections. It performs NO writes, NO AI calls, alters NO
 * execution, and **never fabricates** jobs, cost, tokens, or running states:
 * unmeasured values are surfaced as "unknown"/"not tracked yet".
 *
 * Adds no routes/operations/capabilities/MCP/schema/EventBus/telemetry-model change.
 */

namespace WPCommandCenter\Admin;

use WPCommandCenter\Telemetry\TelemetryQuery;
use WPCommandCenter\Ai\Platform\AiActivity;

defined( 'ABSPATH' ) || exit;

final class OperationsCenterQuery {

	private TelemetryQuery $telemetry;

	public function __construct( ?TelemetryQuery $telemetry = null ) {
		$this->telemetry = $telemetry ?? new TelemetryQuery();
	}

	/** Whether the telemetry store has any data yet (drives honest empty states). */
	public function telemetry_active(): bool {
		return (int) ( $this->telemetry->summary( 3650 )['total'] ?? 0 ) > 0;
	}

	/** Pending human approvals (real count; reuses the audit-bar source). */
	public function pending_approvals(): int {
		return AiActivity::pending_approvals();
	}

	/**
	 * Needs attention: pending approvals + recent FAILED operations (real telemetry).
	 *
	 * @return array{pending_approvals:int,failures:array<int,array<string,mixed>>}
	 */
	public function needs_attention(): array {
		$failures = [];
		foreach ( $this->telemetry->recent( 100 ) as $r ) {
			if ( 'failed' === ( $r['status'] ?? '' ) ) {
				$failures[] = $this->normalize_job( $r );
				if ( count( $failures ) >= 5 ) {
					break;
				}
			}
		}
		return [ 'pending_approvals' => $this->pending_approvals(), 'failures' => $failures ];
	}

	/**
	 * Operations timeline: real telemetry jobs when present (status/duration/
	 * provider/model), else an honest audit-derived fallback (no duration).
	 *
	 * @return array{source:string,rows:array<int,array<string,mixed>>}
	 */
	public function timeline( int $limit = 20 ): array {
		$tele = $this->telemetry->recent( $limit );
		if ( ! empty( $tele ) ) {
			return [ 'source' => 'telemetry', 'rows' => array_map( [ $this, 'normalize_job' ], $tele ) ];
		}
		// Fallback: audit-derived activity (real events; duration unknown).
		$rows = [];
		foreach ( AiActivity::feed( $limit ) as $e ) {
			$rows[] = [
				'time'        => (int) $e['time'],
				'status'      => 'completed', // audit terminal events; failures show via category/label
				'kind'        => (string) $e['category'],
				'operation'   => (string) $e['label'],
				'provider'    => '',
				'model'       => '',
				'duration_ms' => null, // unknown — not faked
				'actor'       => (string) $e['actor'],
			];
		}
		return [ 'source' => 'audit', 'rows' => $rows ];
	}

	/**
	 * Status roll-up (real telemetry summary). `running` reflects ONLY actually-
	 * recorded running rows — it is never invented.
	 */
	public function status_rollup( int $days = 30 ): array {
		$s = $this->telemetry->summary( $days );
		return [
			'total'     => (int) $s['total'],
			'completed' => (int) $s['completed'],
			'failed'    => (int) $s['failed'],
			'running'   => (int) $s['running'],
			'cancelled' => (int) $s['cancelled'],
			'avg_duration_ms' => $s['avg_duration_ms'], // null when unknown
			'window_days'     => (int) $s['window_days'],
		];
	}

	/**
	 * Recent reversible change sessions (for review/undo). Gated by FeatureGate +
	 * guarded against a missing change-log table. Returns [] when unavailable.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function reversible( int $limit = 8 ): array {
		if ( ! FeatureGate::allows( 'change_history' ) ) {
			return [];
		}
		try {
			$res      = ( new ChangeHistoryAdminQuery() )->sessions( [], $limit, 0 );
			$sessions = is_array( $res['sessions'] ?? null ) ? $res['sessions'] : [];
			return array_values( array_filter( $sessions, static fn ( $s ) => (int) ( $s['reversible_count'] ?? 0 ) > 0 ) );
		} catch ( \Throwable $e ) {
			return []; // missing table / gated source → honest empty.
		}
	}

	/** Honest data-availability flags for the UI. */
	public function honesty(): array {
		$s = $this->telemetry->summary( 30 );
		return [
			'cost_tracked'     => false, // per-token cost is not instrumented (P8 boundary)
			'tokens_tracked'   => (int) ( $s['tokens_known'] ?? 0 ) > 0,
			'telemetry_active' => $this->telemetry_active(),
		];
	}

	/** Normalize a telemetry row for the UI (NULL stays NULL = unknown). */
	private function normalize_job( array $r ): array {
		return [
			'time'        => (int) ( $r['completed_at'] ?? $r['created_at'] ?? 0 ),
			'status'      => (string) ( $r['status'] ?? 'unknown' ),
			'kind'        => (string) ( $r['kind'] ?? 'operation' ),
			'operation'   => (string) ( $r['operation'] ?? '' ),
			'provider'    => (string) ( $r['provider'] ?? '' ),
			'model'       => (string) ( $r['model'] ?? '' ),
			'duration_ms' => isset( $r['duration_ms'] ) && null !== $r['duration_ms'] ? (int) $r['duration_ms'] : null,
			'error_code'  => (string) ( $r['error_code'] ?? '' ),
			'actor'       => (string) ( $r['actor_type'] ?? '' ),
		];
	}
}
