<?php
/**
 * STEP 105.1 — Change History admin aggregation read (presentation layer).
 *
 * Powers the wp-admin "Sessions" view: a session-grouped roll-up of the
 * `wpcc_change_log` table (the STEP 104.1 system of record). This is a
 * READ-ONLY presentation-layer helper — NOT a runtime API, NOT MCP-exposed,
 * and NOT a new source of truth. It never writes, and it must never grow
 * runtime/business logic; it only summarises rows that the STEP 104 backend
 * already records.
 *
 * Cheap by design: one GROUP BY for the session roll-up + one bounded
 * secondary query (scoped to the current page's session ids) to attach a
 * human "first actor" label. No per-row N+1, no caching, no persistence.
 *
 * Session grouping rule: a session = all rows sharing a non-empty session_id.
 * Rows with no session_id (direct API calls, historical backfill) are
 * intentionally excluded here — they remain fully visible in the flat
 * Timeline view. change_set_id is surfaced as a count, not a grouping tier.
 */

namespace WPCommandCenter\Admin;

defined( 'ABSPATH' ) || exit;

final class ChangeHistoryAdminQuery {

	private const DEFAULT_LIMIT = 20;
	private const MAX_LIMIT     = 100;

	/** Filters accepted from the admin UI, mapped to their column. */
	private const FILTER_MAP = [
		'runtime' => 'runtime',
		'status'  => 'status',
	];

	/**
	 * How many changes have ever been recorded on this site.
	 *
	 * Deliberately NOT derived from sessions(): a change written through the
	 * approval-queue path carries no session_id, so it belongs to no session and is
	 * invisible to a session roll-up. A caller asking "has anything ever changed
	 * here?" must not be told "no" because the change happened to arrive without a
	 * session. Same table, same read-only posture, no filters — just the count.
	 */
	public function total_changes(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcc_change_log';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, no user input, presentation read.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * The most recent changes, newest first — regardless of session.
	 *
	 * Home's "Recent changes" panel was built from session roll-ups, and changes
	 * written through the approval-queue path carry no session_id, so they belong
	 * to no session. The result was a dashboard reporting "No changes yet" on a
	 * site with a full change log — the panel was blind to the product's own
	 * primary flow. Read-only, same table, no new source of truth.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function recent_changes( int $limit = 5 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcc_change_log';
		$limit = max( 1, min( 50, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, prepared limit, presentation read.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT change_id, operation_id, action, runtime, status, reversible, actor_json, target_summary, created_at FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d", $limit ),
			ARRAY_A
		);

		$out = [];
		foreach ( (array) $rows as $r ) {
			$operation_id = (string) ( $r['operation_id'] ?? '' );
			$action       = (string) ( $r['action'] ?? '' );
			// The actor is stored as JSON on this table, not as a column pair.
			$actor      = json_decode( (string) ( $r['actor_json'] ?? '' ), true );
			$actor_type = is_array( $actor ) ? (string) ( $actor['type'] ?? '' ) : '';

			// Reuse the human target stored with the change so the dashboard row can
			// say WHICH setting changed, not just that a setting did.
			$summary      = json_decode( (string) ( $r['target_summary'] ?? '' ), true );
			$target_label = is_array( $summary ) ? trim( (string) ( $summary['label'] ?? '' ) ) : '';

			$out[] = [
				'change_id'  => (string) ( $r['change_id'] ?? '' ),
				'headline'   => ActionLabels::describe( $operation_id, $action, [], '' )
					. ( '' !== $target_label ? ' — ' . $target_label : '' ),
				// area() maps a RUNTIME to a plain-language area; passing the
				// operation id yielded "Option manage" instead of "Settings".
				'area'       => ActionLabels::area( (string) ( $r['runtime'] ?? '' ) ),
				'target'     => (string) ( $r['target_summary'] ?? '' ),
				'status'     => (string) ( $r['status'] ?? '' ),
				'reversible' => ! empty( $r['reversible'] ),
				'actor'      => $actor_type,
				'created_at' => (string) ( $r['created_at'] ?? '' ),
			];
		}

		return $out;
	}

	/**
	 * Session-grouped roll-up, newest activity first.
	 *
	 * @param array<string,mixed> $filters runtime/status/since/until (all optional).
	 * @return array<string,mixed> Envelope: { action, sessions[], total_count, returned,
	 *                             has_more, next_cursor, limit, offset, filters }.
	 */
	public function sessions( array $filters, int $limit, int $offset ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcc_change_log';

		$limit  = min( self::MAX_LIMIT, max( 1, $limit ) );
		$offset = max( 0, $offset );

		[ $where, $params, $applied ] = $this->build_where( $filters );
		$where_sql = $where ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '';

		// Total distinct sessions (for has_more / pagination).
		$count_sql = "SELECT COUNT(*) FROM (SELECT session_id FROM {$table} {$where_sql} GROUP BY session_id) AS s";
		$total     = (int) $wpdb->get_var(
			$params ? $wpdb->prepare( $count_sql, $params ) : $count_sql // phpcs:ignore WordPress.DB.PreparedSQL
		);

		// Session roll-up. GROUP_CONCAT for runtimes/sources is cheap and
		// portable (no JSON functions). SUM(reversible) counts reversible rows.
		$rollup_sql = "SELECT
				session_id,
				COUNT(*)                       AS change_count,
				SUM(reversible)                AS reversible_count,
				COUNT(DISTINCT change_set_id)  AS change_set_count,
				MIN(created_at)                AS first_at,
				MAX(created_at)                AS last_at,
				GROUP_CONCAT(DISTINCT runtime) AS runtimes,
				GROUP_CONCAT(DISTINCT source)  AS sources
			FROM {$table}
			{$where_sql}
			GROUP BY session_id
			ORDER BY last_at DESC, session_id DESC
			LIMIT %d OFFSET %d";

		$rollup_params = array_merge( $params, [ $limit, $offset ] );
		$rows          = $wpdb->get_results( $wpdb->prepare( $rollup_sql, $rollup_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$rows          = is_array( $rows ) ? $rows : [];

		$labels   = $this->first_actor_labels( $table, wp_list_pluck( $rows, 'session_id' ) );
		$sessions = array_map(
			fn( array $r ): array => $this->format_session( $r, $labels ),
			$rows
		);

		$returned    = count( $sessions );
		$next_offset = $offset + $returned;
		$has_more    = $next_offset < $total;

		return [
			'action'      => 'history_sessions',
			'sessions'    => $sessions,
			'total_count' => $total,
			'returned'    => $returned,
			'count'       => $total,
			'has_more'    => $has_more,
			'next_cursor' => $has_more ? base64_encode( (string) wp_json_encode( [ 'offset' => $next_offset ] ) ) : null,
			'limit'       => $limit,
			'offset'      => $offset,
			'filters'     => (object) $applied,
		];
	}

	/**
	 * Build the shared WHERE for both the count and roll-up queries. Always
	 * scoped to rows that actually belong to a session.
	 *
	 * @param array<string,mixed> $filters
	 * @return array{0:string[],1:array<int,mixed>,2:array<string,mixed>}
	 */
	private function build_where( array $filters ): array {
		$where   = [ "session_id IS NOT NULL", "session_id <> %s" ];
		$params  = [ '' ];
		$applied = [];

		foreach ( self::FILTER_MAP as $key => $column ) {
			if ( isset( $filters[ $key ] ) && '' !== (string) $filters[ $key ] ) {
				$value           = sanitize_text_field( (string) $filters[ $key ] );
				$where[]         = "{$column} = %s";
				$params[]        = $value;
				$applied[ $key ] = $value;
			}
		}

		if ( isset( $filters['since'] ) && '' !== (string) $filters['since'] ) {
			$where[]           = 'created_at >= %d';
			$params[]          = (int) $filters['since'];
			$applied['since']  = (int) $filters['since'];
		}
		if ( isset( $filters['until'] ) && '' !== (string) $filters['until'] ) {
			$where[]           = 'created_at <= %d';
			$params[]          = (int) $filters['until'];
			$applied['until']  = (int) $filters['until'];
		}

		return [ $where, $params, $applied ];
	}

	/**
	 * One bounded query: the earliest (MIN id) actor_json for each session on the
	 * current page, decoded into a short human label. Returns [] for an empty page.
	 *
	 * @param string[] $session_ids
	 * @return array<string,string> session_id => actor label
	 */
	private function first_actor_labels( string $table, array $session_ids ): array {
		$session_ids = array_values( array_filter( array_map( 'strval', $session_ids ), 'strlen' ) );
		if ( empty( $session_ids ) ) {
			return [];
		}

		global $wpdb;
		$placeholders = implode( ', ', array_fill( 0, count( $session_ids ), '%s' ) );

		// Join each session to its first row (smallest id) and pull that actor_json.
		$sql = "SELECT t.session_id, t.actor_json
			FROM {$table} t
			INNER JOIN (
				SELECT session_id, MIN(id) AS min_id
				FROM {$table}
				WHERE session_id IN ({$placeholders})
				GROUP BY session_id
			) m ON t.id = m.min_id";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $session_ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$rows = is_array( $rows ) ? $rows : [];

		$labels = [];
		foreach ( $rows as $row ) {
			$labels[ (string) $row['session_id'] ] = $this->actor_label( $row['actor_json'] ?? null );
		}

		return $labels;
	}

	/**
	 * Derive a short, display-safe actor label from a stored actor_json blob.
	 * Prefers an explicit label, then a login, then the actor type.
	 */
	private function actor_label( mixed $actor_json ): string {
		$actor = is_string( $actor_json ) && '' !== $actor_json ? json_decode( $actor_json, true ) : null;
		if ( ! is_array( $actor ) ) {
			return 'unknown';
		}

		foreach ( [ 'label', 'user_login', 'name', 'agent' ] as $key ) {
			if ( ! empty( $actor[ $key ] ) && is_string( $actor[ $key ] ) ) {
				return $actor[ $key ];
			}
		}

		$type = isset( $actor['type'] ) && is_string( $actor['type'] ) ? $actor['type'] : 'unknown';
		if ( 'admin' === $type && ! empty( $actor['user_id'] ) ) {
			return 'Admin #' . (int) $actor['user_id'];
		}

		return $type;
	}

	/**
	 * Normalise a raw roll-up row into a typed session summary.
	 *
	 * @param array<string,mixed>  $row
	 * @param array<string,string> $labels
	 * @return array<string,mixed>
	 */
	private function format_session( array $row, array $labels ): array {
		$session_id = (string) ( $row['session_id'] ?? '' );
		$runtimes   = $this->split_list( $row['runtimes'] ?? '' );

		return [
			'session_id'       => $session_id,
			'change_count'     => (int) ( $row['change_count'] ?? 0 ),
			'reversible_count' => (int) ( $row['reversible_count'] ?? 0 ),
			'change_set_count' => (int) ( $row['change_set_count'] ?? 0 ),
			'first_at'         => (int) ( $row['first_at'] ?? 0 ),
			'last_at'          => (int) ( $row['last_at'] ?? 0 ),
			'runtimes'         => $runtimes,
			// V1 — the same runtimes named the way a site owner would name them
			// ("Posts & pages, SEO"), so History reads as a list of things that
			// happened rather than a list of subsystem IDs. Additive: `runtimes`
			// above is untouched for anything matching on the raw value.
			'areas'            => array_values( array_unique( array_map(
				static fn ( $runtime ) => ActionLabels::area( (string) $runtime ),
				$runtimes
			) ) ),
			'sources'          => $this->split_list( $row['sources'] ?? '' ),
			'actor_summary'    => $labels[ $session_id ] ?? 'unknown',
		];
	}

	/**
	 * Split a GROUP_CONCAT result into a clean, de-duplicated string list.
	 *
	 * @return string[]
	 */
	private function split_list( mixed $concat ): array {
		if ( ! is_string( $concat ) || '' === $concat ) {
			return [];
		}
		$parts = array_filter( array_map( 'trim', explode( ',', $concat ) ), 'strlen' );
		return array_values( array_unique( $parts ) );
	}
}
