<?php
/**
 * PROGRAM-8 — telemetry read API (the dashboard contract).
 *
 * The ONLY surface dashboards (Mission Control, Job Center, Usage & Cost,
 * Operations Timeline, reporting) should read. Views consume these shapes and
 * render — they never compute business logic. All reads are bounded + read-only.
 *
 * "Unknown" is honest: aggregates separate measured from unmeasured (e.g.
 * `cost_known` vs total), so a dashboard can show "estimated where known".
 */

namespace WPCommandCenter\Telemetry;

defined( 'ABSPATH' ) || exit;

final class TelemetryQuery {

	private TelemetryStore $store;

	public function __construct( ?TelemetryStore $store = null ) {
		$this->store = $store ?? new TelemetryStore();
	}

	/**
	 * Roll-up over a recent window (default 30 days). Honest: token/cost sums cover
	 * only rows where they were measured; `*_known` counts expose coverage.
	 *
	 * @return array<string,mixed>
	 */
	public function summary( int $days = 30 ): array {
		global $wpdb;
		$empty = [
			'total' => 0, 'completed' => 0, 'failed' => 0, 'running' => 0, 'cancelled' => 0,
			'avg_duration_ms' => null, 'duration_known' => 0,
			'tokens_input' => 0, 'tokens_output' => 0, 'tokens_known' => 0,
			'cost_micros' => 0, 'cost_known' => 0, 'window_days' => $days,
		];
		if ( ! $this->store->exists() ) {
			return $empty;
		}
		$table  = $this->store->table();
		$cutoff = time() - ( max( 1, $days ) * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$r = $wpdb->get_row( $wpdb->prepare(
			"SELECT
				COUNT(*) total,
				SUM(status='completed') completed,
				SUM(status='failed') failed,
				SUM(status='running') running,
				SUM(status='cancelled') cancelled,
				AVG(duration_ms) avg_duration_ms,
				SUM(duration_ms IS NOT NULL) duration_known,
				COALESCE(SUM(tokens_input),0) tokens_input,
				COALESCE(SUM(tokens_output),0) tokens_output,
				SUM(tokens_input IS NOT NULL OR tokens_output IS NOT NULL) tokens_known,
				COALESCE(SUM(estimated_cost_micros),0) cost_micros,
				SUM(estimated_cost_micros IS NOT NULL) cost_known
			FROM {$table} WHERE created_at >= %d",
			$cutoff
		), ARRAY_A );
		if ( ! is_array( $r ) ) {
			return $empty;
		}
		return [
			'total'          => (int) $r['total'],
			'completed'      => (int) $r['completed'],
			'failed'         => (int) $r['failed'],
			'running'        => (int) $r['running'],
			'cancelled'      => (int) $r['cancelled'],
			'avg_duration_ms'=> null !== $r['avg_duration_ms'] ? (int) round( (float) $r['avg_duration_ms'] ) : null,
			'duration_known' => (int) $r['duration_known'],
			'tokens_input'   => (int) $r['tokens_input'],
			'tokens_output'  => (int) $r['tokens_output'],
			'tokens_known'   => (int) $r['tokens_known'],
			'cost_micros'    => (int) $r['cost_micros'],
			'cost_known'     => (int) $r['cost_known'],
			'window_days'    => $days,
		];
	}

	/**
	 * Recent jobs, newest first (for a Job Center / timeline).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function recent( int $limit = 25 ): array {
		global $wpdb;
		if ( ! $this->store->exists() ) {
			return [];
		}
		$table = $this->store->table();
		$limit = max( 1, min( 200, $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Per-provider roll-up (for provider comparison / Usage & Cost).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function by_provider( int $days = 30 ): array {
		global $wpdb;
		if ( ! $this->store->exists() ) {
			return [];
		}
		$table  = $this->store->table();
		$cutoff = time() - ( max( 1, $days ) * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT provider, COUNT(*) jobs,
				COALESCE(SUM(tokens_input),0) tokens_input,
				COALESCE(SUM(tokens_output),0) tokens_output,
				COALESCE(SUM(estimated_cost_micros),0) cost_micros,
				SUM(estimated_cost_micros IS NOT NULL) cost_known,
				AVG(duration_ms) avg_duration_ms
			FROM {$table} WHERE created_at >= %d AND provider <> '' GROUP BY provider ORDER BY jobs DESC",
			$cutoff
		), ARRAY_A );
		return is_array( $rows ) ? $rows : [];
	}
}
