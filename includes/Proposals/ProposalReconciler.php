<?php
/**
 * STEP 110 (Proposal Store / Governed Drafts) — Task 4: ProposalReconciler.
 *
 * A SCHEDULER/SWEEP only. It selects pending_approval proposals and hands each to
 * ProposalSync — the safety net that heals proposals whose gated request was
 * executed asynchronously (queue/cron) while no interactive read materialized
 * them. It reuses ProposalSync VERBATIM.
 *
 * It contains ZERO independent lifecycle logic: no request/result/change reads,
 * no status mapping, no transition decisions, no writes. The authoritative
 * mapping lives in exactly one place (ProposalSync), so the cron path and the
 * read-through path can never diverge.
 *
 * NOTE: cron *scheduling/wiring* is intentionally out of scope here — reconcile()
 * is a plain callable (invoked by tests now; a self-healing wp-cron schedule is a
 * later step).
 */

namespace WPCommandCenter\Proposals;

defined( 'ABSPATH' ) || exit;

final class ProposalReconciler {

	private ProposalStore $store;
	private ProposalSync $sync;

	public function __construct( ?ProposalStore $store = null, ?ProposalSync $sync = null ) {
		$this->store = $store ?? new ProposalStore();
		$this->sync  = $sync ?? new ProposalSync( $this->store );
	}

	/**
	 * Sweep up to $limit pending_approval proposals and reconcile each via Sync.
	 *
	 * @param int $limit Max proposals to process this pass (bounded).
	 * @return array{processed:int,applied:int,failed:int,dismissed:int,pending:int}
	 *         A tally of resulting states (counting only; no lifecycle logic).
	 */
	public function reconcile( int $limit = 50 ): array {
		$limit   = max( 1, min( 200, $limit ) );
		$pending = $this->store->list( [ 'status' => ProposalStore::STATUS_PENDING_APPROVAL, 'limit' => $limit ] );

		$tally = [ 'processed' => 0, 'applied' => 0, 'failed' => 0, 'dismissed' => 0, 'pending' => 0 ];

		foreach ( $pending as $proposal ) {
			$result = $this->sync->sync( $proposal );
			++$tally['processed'];

			$status = is_wp_error( $result ) ? '' : (string) ( $result['status'] ?? '' );
			switch ( $status ) {
				case ProposalStore::STATUS_APPLIED:
					++$tally['applied'];
					break;
				case ProposalStore::STATUS_FAILED:
					++$tally['failed'];
					break;
				case ProposalStore::STATUS_DISMISSED:
					++$tally['dismissed'];
					break;
				case ProposalStore::STATUS_PENDING_APPROVAL:
					++$tally['pending'];
					break;
			}
		}

		return $tally;
	}
}
