<?php
/**
 * STEP 111 (S2.2.1) — read-only selection resolver.
 *
 * Turns a stateless SelectionContract into a BOUNDED, CAPABILITY-SCOPED id set by
 * reading the EXISTING list source (ProposalStore — the same rows ProposalAdminQuery
 * shapes). It is the server-side answer to "select all matching {filter}" without
 * the client ever enumerating the whole dataset.
 *
 * Hard boundaries (must hold):
 *  - READ-ONLY. It calls only ProposalStore::count()/list() (reads). It NEVER
 *    writes, never applies, never calls OperationExecutor / ProposalApplyService,
 *    never persists a selection. There is no selection table and no schema change.
 *  - It is NOT an authority. The resolved id set is a transient INPUT to the
 *    existing per-item governed action (apply/dismiss); each id still passes the
 *    real capability + approval + rollback + audit chokepoint per item.
 *  - BOUNDED. The cap is min(contract cap, MAX_SELECTION). When the match count
 *    EXCEEDS the cap it REFUSES (over_cap) rather than silently truncating into a
 *    partial, ungoverned mass action — the caller must narrow the filter.
 *  - CAPABILITY-SCOPED. Criteria are resolved only for operations the caller
 *    declares allowed (context.allowed_operations); a criteria targeting any other
 *    operation resolves to the empty set. Per-item OperationExecutor enforcement
 *    remains the hard boundary at apply time.
 */

namespace WPCommandCenter\Admin;

use WPCommandCenter\Proposals\ProposalStore;

defined( 'ABSPATH' ) || exit;

final class SelectionResolver {

	/** Platform ceiling on a single resolved selection (bounded execution). */
	public const MAX_SELECTION = 100;

	private ProposalStore $store;

	public function __construct( ?ProposalStore $store = null ) {
		$this->store = $store ?? new ProposalStore();
	}

	/**
	 * Resolve a contract into a bounded id set.
	 *
	 * @param array<string,mixed> $context { allowed_operations?: string[] }
	 * @return array<string,mixed> {
	 *   action, by, total_matched, count, ids[], over_cap, cap
	 * }
	 */
	public function resolve( SelectionContract $contract, array $context = [] ): array {
		$cap     = min( $contract->cap(), self::MAX_SELECTION );
		$allowed = isset( $context['allowed_operations'] ) && is_array( $context['allowed_operations'] )
			? array_map( 'strval', $context['allowed_operations'] )
			: [];

		if ( SelectionContract::BY_IDS === $contract->by() ) {
			$ids   = $contract->ids();
			$total = count( $ids );
			// Over-cap: refuse (empty id set) rather than truncate.
			if ( $total > $cap ) {
				return $this->envelope( SelectionContract::BY_IDS, $total, [], true, $cap );
			}
			return $this->envelope( SelectionContract::BY_IDS, $total, $ids, false, $cap );
		}

		// by = criteria: resolve server-side over the existing proposal source.
		$filters = $this->scoped_filters( $contract->filters(), $allowed );
		if ( null === $filters ) {
			// Operation not in the allowed set → resolves to nothing (capability scope).
			return $this->envelope( SelectionContract::BY_CRITERIA, 0, [], false, $cap );
		}

		$total = $this->store->count( $filters );
		if ( $total > $cap ) {
			// Refuse over-cap; the caller must narrow the filter (no silent truncation).
			return $this->envelope( SelectionContract::BY_CRITERIA, $total, [], true, $cap );
		}

		$rows = $this->store->list( array_merge( $filters, [ 'limit' => $cap, 'offset' => 0 ] ) );
		$ids  = [];
		foreach ( $rows as $row ) {
			$pid = (string) ( $row['proposal_id'] ?? '' );
			if ( '' !== $pid ) {
				$ids[] = $pid;
			}
		}

		return $this->envelope( SelectionContract::BY_CRITERIA, $total, $ids, false, $cap );
	}

	/**
	 * Capability-scope + whitelist the criteria filters. Returns null when the
	 * requested operation is outside the allowed set (so the caller cannot select
	 * across its capability boundary). Only a fixed set of read-safe filter columns
	 * is honoured — no arbitrary column injection.
	 *
	 * @param array<string,string> $filters
	 * @param array<int,string>    $allowed
	 * @return array<string,string>|null
	 */
	private function scoped_filters( array $filters, array $allowed ): ?array {
		$operation = (string) ( $filters['operation_id'] ?? '' );
		if ( ! empty( $allowed ) && '' !== $operation && ! in_array( $operation, $allowed, true ) ) {
			return null;
		}

		$out = [];
		foreach ( [ 'operation_id', 'status', 'target_type', 'batch_id' ] as $key ) {
			if ( isset( $filters[ $key ] ) && '' !== (string) $filters[ $key ] ) {
				$out[ $key ] = (string) $filters[ $key ];
			}
		}
		return $out;
	}

	/**
	 * @param array<int,string> $ids
	 * @return array<string,mixed>
	 */
	private function envelope( string $by, int $total, array $ids, bool $over_cap, int $cap ): array {
		return [
			'action'        => 'selection_resolve',
			'by'            => $by,
			'total_matched' => $total,
			'count'         => count( $ids ),
			'ids'           => $ids,
			'over_cap'      => $over_cap,
			'cap'           => $cap,
		];
	}
}
