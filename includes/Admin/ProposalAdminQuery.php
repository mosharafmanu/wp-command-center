<?php
/**
 * STEP 110 (Proposal Store / Governed Drafts) — Task 5: admin read-shaping.
 *
 * READ-ONLY presentation layer over the Proposal Store, mirroring the Phase A
 * AdminQuery pattern (DashboardAdminQuery / ChangeHistoryAdminQuery / ...). It
 * shapes proposals for the admin REST surface and enriches `applied` proposals
 * with the CURRENT rollback state of their underlying change (read-through of
 * wpcc_change_log) so the UI never misreports an applied-then-rolled-back change.
 *
 * Boundaries:
 *  - It NEVER writes: not wpcc_proposals (ProposalStore is the sole writer), not
 *    any authority table. It only reads.
 *  - It does NOT perform synchronization. The REST handler invokes ProposalSync
 *    (read-through) BEFORE calling this shaper, so this class stays read-only.
 *  - It is not a runtime API and not a new source of truth — it only projects
 *    rows the Proposal Store already records, plus a read-only change-state probe.
 *
 * Cheap by design: ProposalStore::list/count for the page, plus ONE batched
 * change_log read for the page's applied change_ids (no per-row N+1).
 */

namespace WPCommandCenter\Admin;

use WPCommandCenter\Proposals\ProposalStore;

defined( 'ABSPATH' ) || exit;

final class ProposalAdminQuery {

	private const DEFAULT_LIMIT = 20;
	private const MAX_LIMIT     = 100;

	private ProposalStore $store;

	public function __construct( ?ProposalStore $store = null ) {
		$this->store = $store ?? new ProposalStore();
	}

	/**
	 * Paginated, filtered listing (newest first), shaped for the admin UI.
	 *
	 * @param array<string,mixed> $filters status/operation_id/target_type/batch_id (optional).
	 * @return array<string,mixed> Envelope: { action, proposals[], total_count, returned,
	 *                             count, has_more, next_cursor, limit, offset, filters }.
	 */
	public function list( array $filters, int $limit, int $offset ): array {
		$limit  = min( self::MAX_LIMIT, max( 1, $limit ) );
		$offset = max( 0, $offset );

		$query_filters = $this->store_filters( $filters );
		$total = $this->store->count( $query_filters );
		$rows  = $this->store->list( array_merge( $query_filters, [ 'limit' => $limit, 'offset' => $offset ] ) );

		$change_states = $this->change_states_for( $rows );
		$proposals     = array_map(
			fn( array $r ): array => $this->format( $r, $change_states ),
			$rows
		);

		$returned    = count( $proposals );
		$next_offset = $offset + $returned;
		$has_more    = $next_offset < $total;

		return [
			'action'      => 'proposals_list',
			'proposals'   => $proposals,
			'total_count' => $total,
			'returned'    => $returned,
			'count'       => $total,
			'has_more'    => $has_more,
			'next_cursor' => $has_more ? base64_encode( (string) wp_json_encode( [ 'offset' => $next_offset ] ) ) : null,
			'limit'       => $limit,
			'offset'      => $offset,
			'filters'     => (object) $query_filters,
		];
	}

	/** Shaped single proposal (with rollback-aware presentation), or null if absent. */
	public function get( string $proposal_id ): ?array {
		$row = $this->store->get( $proposal_id );
		if ( ! $row ) {
			return null;
		}
		return $this->format( $row, $this->change_states_for( [ $row ] ) );
	}

	/** Whitelist the filters we pass through to the store (avoids leaking limit/offset twice). */
	private function store_filters( array $filters ): array {
		$out = [];
		foreach ( [ 'status', 'operation_id', 'target_type', 'target_id', 'batch_id', 'session_id', 'request_id', 'change_id' ] as $k ) {
			if ( isset( $filters[ $k ] ) && '' !== (string) $filters[ $k ] ) {
				$out[ $k ] = (string) $filters[ $k ];
			}
		}
		return $out;
	}

	/**
	 * Batched, READ-ONLY probe of wpcc_change_log for the applied proposals in this
	 * page: maps change_id => 'rolled_back' | 'applied'. One query, no N+1.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<string,string>
	 */
	private function change_states_for( array $rows ): array {
		$change_ids = [];
		foreach ( $rows as $r ) {
			$cid = (string) ( $r['change_id'] ?? '' );
			if ( ProposalStore::STATUS_APPLIED === ( $r['status'] ?? '' ) && '' !== $cid ) {
				$change_ids[ $cid ] = true;
			}
		}
		if ( empty( $change_ids ) ) {
			return [];
		}

		global $wpdb;
		$ids          = array_keys( $change_ids );
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%s' ) );
		$sql          = "SELECT change_id, status, rolled_back_by_change_id FROM {$wpdb->prefix}wpcc_change_log WHERE change_id IN ({$placeholders})";
		$found        = $wpdb->get_results( $wpdb->prepare( $sql, $ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$found        = is_array( $found ) ? $found : [];

		$states = [];
		foreach ( $found as $row ) {
			$reverted = ! empty( $row['rolled_back_by_change_id'] ) || 'rolled_back' === ( $row['status'] ?? '' );
			$states[ (string) $row['change_id'] ] = $reverted ? 'rolled_back' : 'applied';
		}
		return $states;
	}

	/**
	 * Project a raw proposal row into the admin shape. Decodes JSON columns and
	 * attaches change_status for applied proposals (rollback-aware presentation).
	 *
	 * @param array<string,mixed>  $r
	 * @param array<string,string> $change_states
	 * @return array<string,mixed>
	 */
	private function format( array $r, array $change_states ): array {
		$status    = (string) ( $r['status'] ?? '' );
		$change_id = (string) ( $r['change_id'] ?? '' );

		$change_status = null;
		if ( ProposalStore::STATUS_APPLIED === $status && '' !== $change_id ) {
			// Default to 'applied'; downgrade to 'rolled_back' if the change was reversed.
			$change_status = $change_states[ $change_id ] ?? 'applied';
		}

		return [
			'proposal_id'   => (string) ( $r['proposal_id'] ?? '' ),
			'batch_id'      => $r['batch_id'] ?? null,
			'session_id'    => $r['session_id'] ?? null,
			'operation_id'  => (string) ( $r['operation_id'] ?? '' ),
			'action'        => $r['action'] ?? null,
			'target_type'   => (string) ( $r['target_type'] ?? '' ),
			'target_id'     => $r['target_id'] ?? null,
			'status'        => $status,
			'payload'       => $this->decode( $r['payload_json'] ?? null ),
			'prior'         => $this->decode( $r['prior_json'] ?? null ),
			'final_payload' => $this->decode( $r['final_payload_json'] ?? null ),
			'provider'      => $r['provider'] ?? null,
			'model'         => $r['model'] ?? null,
			'confidence'    => isset( $r['confidence'] ) && null !== $r['confidence'] ? (float) $r['confidence'] : null,
			'proposed_by'   => $this->decode( $r['proposed_by'] ?? null ),
			'applied_by'    => $this->decode( $r['applied_by'] ?? null ),
			'request_id'    => $r['request_id'] ?? null,
			'change_id'     => '' !== $change_id ? $change_id : null,
			'change_status' => $change_status,
			'risk_level'    => $r['risk_level'] ?? null,
			'error'         => $this->decode( $r['error_json'] ?? null ),
			'created_at'    => isset( $r['created_at'] ) ? (int) $r['created_at'] : null,
			'updated_at'    => isset( $r['updated_at'] ) ? (int) $r['updated_at'] : null,
			'expires_at'    => isset( $r['expires_at'] ) && null !== $r['expires_at'] ? (int) $r['expires_at'] : null,
		];
	}

	/** Decode a JSON column to an array, or null. */
	private function decode( mixed $json ): ?array {
		if ( ! is_string( $json ) || '' === $json ) {
			return null;
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : null;
	}
}
