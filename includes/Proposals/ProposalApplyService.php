<?php
/**
 * STEP 110 (Proposal Store / Governed Drafts) — Task 3: ProposalApplyService.
 *
 * The execution crossing point for a proposal — Developer-mode DIRECT apply only.
 * It is the one place that hands a proposal's payload to the engine. It owns the
 * apply DECISION and orchestration; it does NOT own proposal persistence, status
 * legality, approval, synchronization, or reconciliation.
 *
 * Flow (success):
 *   draft -> apply() -> OperationExecutor::run() -> resolve change_id (read-back)
 *         -> ProposalStore::mark_applied(change_id) -> applied
 * Flow (failure):
 *   draft -> apply() -> executor error -> ProposalStore::mark_failed(error)
 *
 * Boundary (must hold):
 *  - OperationExecutor is the ONLY mutation chokepoint. This service never mutates
 *    WordPress content, attachment meta, posts, or options, and never calls a
 *    runtime manager (e.g. MediaRuntimeManager) directly.
 *  - ProposalStore is the SOLE writer of proposal rows. This service NEVER writes
 *    wpcc_proposals directly — all transitions go through ProposalStore methods.
 *  - It never writes wpcc_change_log or wpcc_operation_requests. It performs ONE
 *    read-only authoritative read-back of wpcc_change_log to resolve change_id
 *    (Pre-Implementation Finding F1: OperationExecutor::run() does not return it).
 *  - Allowed dependencies: ProposalStore, OperationExecutor, ProposalOutcome
 *    (+ the read-only change_id read-back). No other engine subsystem.
 *
 * Gated path (Task 4): in client/enterprise mode the executor auto-creates an
 * approval request and returns a pending_approval envelope. This service reflects
 * that by calling ProposalStore::mark_pending_approval(request_id) — it does NOT
 * mark applied and does NOT fake success. Resolving that pending proposal to its
 * terminal state later (executed/rejected/...) belongs to ProposalSync.
 */

namespace WPCommandCenter\Proposals;

use WPCommandCenter\Operations\OperationExecutor;

defined( 'ABSPATH' ) || exit;

final class ProposalApplyService {

	private ProposalStore $store;

	public function __construct( ?ProposalStore $store = null ) {
		$this->store = $store ?? new ProposalStore();
	}

	/**
	 * Developer-mode direct apply of a single draft proposal.
	 *
	 * @param string $proposal_id The proposal to apply.
	 * @param array  $context     Optional execution context. Recognized: actor.
	 * @return array|\WP_Error The applied proposal row on success; WP_Error on a
	 *                         load/validation error, on executor failure (the
	 *                         proposal is also marked failed), or when the apply
	 *                         is gated for approval (the proposal stays draft).
	 */
	public function apply( string $proposal_id, array $context = [] ): array|\WP_Error {
		// 1. Load.
		$proposal = $this->store->get( $proposal_id );
		if ( ! $proposal ) {
			return new \WP_Error( 'wpcc_proposal_not_found', __( 'Proposal not found.', 'wp-command-center' ) );
		}

		// 2. Verify draft (direct apply only acts on a draft).
		if ( ProposalStore::STATUS_DRAFT !== $proposal['status'] ) {
			return new \WP_Error(
				'wpcc_proposal_not_draft',
				sprintf( __( 'Only a draft proposal can be applied (current: %s).', 'wp-command-center' ), (string) $proposal['status'] )
			);
		}

		// 3. Resolve payload: the human-edited final payload wins, else the original.
		$payload = $this->resolve_payload( $proposal );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$operation_id = (string) $proposal['operation_id'];

		// 4. Unique session_id for this apply — the read-back key (F1) and the
		//    change-history batch this proposal's change belongs to.
		$session_id = wp_generate_uuid4();

		// 5. Execute through the single chokepoint. Provenance: actor (attribution)
		//    + source marker for the change record.
		$exec_context = [
			'session_id' => $session_id,
			'source'     => 'proposal',
		];
		if ( isset( $context['actor'] ) && is_array( $context['actor'] ) ) {
			$exec_context['actor'] = $context['actor'];
		}

		$result = ( new OperationExecutor() )->run( $operation_id, $payload, $exec_context );

		// 6. Interpret the executor envelope through the SHARED outcome interpreter
		//    (the one definition of success/in-band-error/gated/hard-failure, also
		//    used by ProposalSync over the durable result_json).
		$outcome = ProposalOutcome::interpret( $result );

		// 6a. Gated — the executor auto-created an approval request. Reflect
		//     pending_approval (with the request_id) via ProposalStore; do NOT mark
		//     applied and do NOT fake success. Task 4's Sync resolves it later.
		if ( $outcome->is_gated() ) {
			$request_id = $outcome->request_id();
			if ( '' === $request_id ) {
				return new \WP_Error( 'wpcc_apply_gated_no_request', __( 'Apply was gated for approval but no request id was returned.', 'wp-command-center' ) );
			}
			return $this->store->mark_pending_approval( $proposal_id, $request_id );
		}

		// 6b. Failure (hard failure OR in-band manager error) — reflect via
		//     ProposalStore (never a direct write here).
		if ( $outcome->is_failure() ) {
			$error = $outcome->error();
			$this->store->mark_failed( $proposal_id, $error );
			return new \WP_Error( (string) $error['code'], (string) $error['message'] );
		}

		// 7. Success — resolve the change_id by authoritative read-back (F1) and
		//    record the transition through ProposalStore.
		$change_id = $this->resolve_change_id( $session_id );
		if ( '' === $change_id ) {
			// The op succeeded but produced no attributable change record. We
			// cannot mark_applied without change_id; reflect as failed so the
			// proposal is not left in a false draft state.
			$error = [
				'code'    => 'wpcc_change_id_unresolved',
				'message' => __( 'Apply succeeded but no change record was found to attribute it.', 'wp-command-center' ),
			];
			$this->store->mark_failed( $proposal_id, $error );
			return new \WP_Error( $error['code'], $error['message'] );
		}

		$applied_by = ( isset( $context['actor'] ) && is_array( $context['actor'] ) ) ? $context['actor'] : null;
		return $this->store->mark_applied( $proposal_id, $change_id, $applied_by );
	}

	/**
	 * final_payload_json (human-edited) wins over payload_json (original proposal).
	 * Returns the decoded payload array, or an error if neither decodes to an array.
	 */
	private function resolve_payload( array $proposal ): array|\WP_Error {
		$final = (string) ( $proposal['final_payload_json'] ?? '' );
		$raw   = ( '' !== $final ) ? $final : (string) ( $proposal['payload_json'] ?? '' );
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'wpcc_proposal_payload_invalid', __( 'Proposal payload is not a valid object.', 'wp-command-center' ) );
		}
		return $decoded;
	}

	/**
	 * Pre-Implementation Finding F1: OperationExecutor::run() does not return the
	 * change_id (ChangeRecorder::record() is void). For a Developer-mode DIRECT
	 * apply we mint a unique session_id per apply, so exactly one change row
	 * carries it; this read-back is deterministic (newest row for the session).
	 *
	 * This is a READ-ONLY authoritative read-back — the service never writes
	 * wpcc_change_log.
	 */
	private function resolve_change_id( string $session_id ): string {
		global $wpdb;
		if ( '' === $session_id ) {
			return '';
		}
		$change_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT change_id FROM {$wpdb->prefix}wpcc_change_log WHERE session_id = %s ORDER BY id DESC LIMIT 1",
				$session_id
			)
		);
		return is_string( $change_id ) ? $change_id : '';
	}
}
