<?php
/**
 * STEP 110 (Proposal Store / Governed Drafts) — Task 4: ProposalSync.
 *
 * The pull-only authority-state resolver for the GATED path (Hybrid-D). When a
 * proposal sits in `pending_approval` (carrying a request_id from the executor's
 * auto-created approval request), Sync reflects the authoritative outcome into
 * the proposal — without owning approval, execution, or persistence.
 *
 * Authority sources (READ-ONLY; Sync never writes them):
 *   - wpcc_operation_requests   — the approval/execution status of the request.
 *   - wpcc_operation_results    — the DURABLE executor envelope (result_json /
 *                                 error_json): the authoritative success/failure.
 *   - wpcc_change_log           — to resolve change_id (by request_id) ONLY for a
 *                                 confirmed success.
 *
 * Critical correctness rules (Findings F-Task3-1 / F-Task3-2):
 *   - request.status = 'executed' is NOT trusted as "applied". The executor marks
 *     a request executed even when the handler returned an in-band error.
 *   - change_log row existence is NOT trusted as "applied" (a row is written for
 *     in-band failures too).
 *   - True success/failure is determined from the durable result envelope via the
 *     SHARED ProposalOutcome interpreter (the same one ProposalApplyService uses).
 *
 * All proposal transitions go through ProposalStore (the sole writer). Sync owns
 * the resolver mapping only; ProposalReconciler reuses it verbatim.
 */

namespace WPCommandCenter\Proposals;

defined( 'ABSPATH' ) || exit;

final class ProposalSync {

	private ProposalStore $store;

	public function __construct( ?ProposalStore $store = null ) {
		$this->store = $store ?? new ProposalStore();
	}

	/**
	 * Resolve one proposal's pending_approval state against the authorities.
	 *
	 * @param array|string $proposal A proposal row, or a proposal_id.
	 * @return array|\WP_Error The (possibly transitioned) proposal row, or an error.
	 */
	public function sync( array|string $proposal ): array|\WP_Error {
		$proposal_id = is_array( $proposal ) ? (string) ( $proposal['proposal_id'] ?? '' ) : (string) $proposal;
		if ( '' === $proposal_id ) {
			return new \WP_Error( 'wpcc_proposal_not_found', __( 'Proposal not found.', 'wp-command-center' ) );
		}

		// Always work from the fresh row (the swept list may be slightly stale).
		$row = $this->store->get( $proposal_id );
		if ( ! $row ) {
			return new \WP_Error( 'wpcc_proposal_not_found', __( 'Proposal not found.', 'wp-command-center' ) );
		}

		// Sync only resolves pending_approval; everything else is a no-op.
		if ( ProposalStore::STATUS_PENDING_APPROVAL !== $row['status'] ) {
			return $row;
		}

		$request_id = (string) ( $row['request_id'] ?? '' );
		if ( '' === $request_id ) {
			return $row; // No bridge to resolve against; remain pending.
		}

		$request = $this->read_request( $request_id );
		if ( ! $request ) {
			return $row; // Missing request: defensive no-op, remain pending.
		}

		switch ( (string) $request['status'] ) {
			// In flight — not yet a terminal outcome.
			case 'pending_review':
			case 'approved':
				return $row;

			// Human declined / cancelled the request.
			case 'rejected':
			case 'cancelled':
				return $this->store->dismiss( $proposal_id );

			// Hard execution failure (the WP_Error path).
			case 'failed':
				return $this->store->mark_failed( $proposal_id, $this->durable_error( $request_id, 'wpcc_request_failed' ) );

			// Executed — but NOT trusted as applied. Determine the true outcome
			// from the durable result envelope via the shared interpreter.
			case 'executed':
				return $this->resolve_executed( $proposal_id, $request_id );

			default:
				return $row; // Unknown status: remain pending.
		}
	}

	/**
	 * Resolve an `executed` request to applied/failed using the durable result
	 * envelope (never request.status or change-row existence alone).
	 */
	private function resolve_executed( string $proposal_id, string $request_id ): array|\WP_Error {
		$envelope = $this->read_result_envelope( $request_id );
		$outcome  = ProposalOutcome::interpret( $envelope );

		if ( $outcome->is_success() ) {
			$change_id = $this->resolve_change_id( $request_id );
			if ( '' === $change_id ) {
				return $this->store->mark_failed( $proposal_id, [
					'code'    => 'wpcc_change_id_unresolved',
					'message' => __( 'Request executed but no change record was found to attribute it.', 'wp-command-center' ),
				] );
			}
			return $this->store->mark_applied( $proposal_id, $change_id );
		}

		// In-band error (or any non-success): failed — even if a change_log row exists.
		return $this->store->mark_failed( $proposal_id, $outcome->error() );
	}

	// ── Read-only authority reads (Sync never writes these tables) ────────────

	private function read_request( string $request_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT request_id, status FROM {$wpdb->prefix}wpcc_operation_requests WHERE request_id = %s", $request_id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * Build an executor envelope from the durable operation_results row so the
	 * shared interpreter can read it. A success-path row stores result_json (the
	 * full envelope, possibly carrying an in-band error); a hard-failure row stores
	 * error_json. Either is normalized to the envelope shape.
	 */
	private function read_result_envelope( string $request_id ): array {
		global $wpdb;
		$res = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT status, result_json, error_json FROM {$wpdb->prefix}wpcc_operation_results WHERE request_id = %s ORDER BY id DESC LIMIT 1",
				$request_id
			),
			ARRAY_A
		);
		if ( ! $res ) {
			return [ 'success' => false, 'result' => [], 'errors' => [] ];
		}
		$result_json = (string) ( $res['result_json'] ?? '' );
		if ( '' !== $result_json ) {
			$decoded = json_decode( $result_json, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		$error = [];
		$error_json = (string) ( $res['error_json'] ?? '' );
		if ( '' !== $error_json ) {
			$decoded = json_decode( $error_json, true );
			if ( is_array( $decoded ) ) {
				$error = $decoded;
			}
		}
		return [ 'success' => false, 'result' => [], 'errors' => $error ? [ $error ] : [] ];
	}

	/** Best-effort error detail for a hard-failed request, from the durable record. */
	private function durable_error( string $request_id, string $fallback_code ): array {
		$outcome = ProposalOutcome::interpret( $this->read_result_envelope( $request_id ) );
		$error   = $outcome->error();
		if ( ! empty( $error['code'] ) && 'wpcc_apply_failed' !== $error['code'] ) {
			return $error;
		}
		return [ 'code' => $fallback_code, 'message' => __( 'The approved request failed during execution.', 'wp-command-center' ) ];
	}

	/**
	 * Resolve change_id for a gated apply by request_id (the gated execution stamps
	 * request_id into the change_log row). READ-ONLY; only called on confirmed
	 * success, so the in-band "applied"-looking change row is never used.
	 */
	private function resolve_change_id( string $request_id ): string {
		global $wpdb;
		$change_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT change_id FROM {$wpdb->prefix}wpcc_change_log WHERE request_id = %s ORDER BY id DESC LIMIT 1",
				$request_id
			)
		);
		return is_string( $change_id ) ? $change_id : '';
	}
}
