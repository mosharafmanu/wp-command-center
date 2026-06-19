<?php
/**
 * STEP 110 (Proposal Store / Governed Drafts) — Task 4: shared outcome interpreter.
 *
 * The SINGLE definition of "what did the engine actually do?", consumed by both
 * ProposalApplyService (the live OperationExecutor::run() envelope) and
 * ProposalSync (the durable wpcc_operation_results.result_json envelope). One
 * place, so in-band-error detection (Findings F-Task3-1 / F-Task3-2) can never
 * drift between the direct and reflected paths.
 *
 * Both inputs share the executor envelope shape:
 *   [ 'success' => bool, 'result' => [...], 'errors' => [ {code,message}, ... ] ]
 *
 * Four outcomes are distinguished:
 *   - SUCCESS        success=true, no in-band error, not gated.
 *   - GATED          success=true, result.status in {pending_approval,
 *                    confirmation_required} (carries request_id).
 *   - IN_BAND_ERROR  success=true BUT result.error=true — the executor reports a
 *                    manager-level failure as a "success" envelope (its
 *                    is_wp_error() check does not catch array-shaped errors).
 *                    Treating this as success would falsely apply a proposal.
 *   - HARD_FAILURE   success=false (WP_Error path), error in errors[0].
 */

namespace WPCommandCenter\Proposals;

defined( 'ABSPATH' ) || exit;

final class ProposalOutcome {

	public const KIND_SUCCESS       = 'success';
	public const KIND_GATED         = 'gated';
	public const KIND_IN_BAND_ERROR = 'in_band_error';
	public const KIND_HARD_FAILURE  = 'hard_failure';

	private string $kind;
	private string $request_id;
	/** @var array{code:string,message:string} */
	private array $error;

	/** @param array{code:string,message:string} $error */
	private function __construct( string $kind, string $request_id, array $error ) {
		$this->kind       = $kind;
		$this->request_id = $request_id;
		$this->error      = $error;
	}

	/**
	 * Interpret an executor envelope (live return OR decoded durable result_json).
	 *
	 * @param mixed $envelope The executor envelope, or anything non-array (treated
	 *                       as a hard failure).
	 */
	public static function interpret( mixed $envelope ): self {
		if ( ! is_array( $envelope ) ) {
			return new self( self::KIND_HARD_FAILURE, '', [
				'code'    => 'wpcc_apply_failed',
				'message' => __( 'Apply failed (no result).', 'wp-command-center' ),
			] );
		}

		$succeeded = ! empty( $envelope['success'] );
		$inner     = ( isset( $envelope['result'] ) && is_array( $envelope['result'] ) ) ? $envelope['result'] : [];
		$in_band   = ! empty( $inner['error'] );
		$status    = (string) ( $inner['status'] ?? '' );

		// Gated — only when genuinely successful (an in-band error is never gated).
		if ( $succeeded && ! $in_band && in_array( $status, [ 'pending_approval', 'confirmation_required' ], true ) ) {
			return new self( self::KIND_GATED, (string) ( $inner['request_id'] ?? '' ), [] );
		}

		// Hard failure — the WP_Error path (success=false, errors[]).
		if ( ! $succeeded ) {
			return new self( self::KIND_HARD_FAILURE, '', self::error_from_envelope( $envelope, $inner ) );
		}

		// In-band manager error — success=true but result.error=true.
		if ( $in_band ) {
			return new self( self::KIND_IN_BAND_ERROR, '', self::error_from_envelope( $envelope, $inner ) );
		}

		return new self( self::KIND_SUCCESS, '', [] );
	}

	/** @param array{code:string,message:string} $inner */
	private static function error_from_envelope( array $envelope, array $inner ): array {
		// (a) Hard-failure envelope: errors[0]{code,message}.
		$errors = ( isset( $envelope['errors'] ) && is_array( $envelope['errors'] ) ) ? $envelope['errors'] : [];
		$first  = is_array( $errors[0] ?? null ) ? $errors[0] : [];
		if ( ! empty( $first ) ) {
			return [
				'code'    => (string) ( $first['code'] ?? 'wpcc_apply_failed' ),
				'message' => (string) ( $first['message'] ?? __( 'Apply failed.', 'wp-command-center' ) ),
			];
		}
		// (b) In-band manager error: result.error=true with code/message.
		if ( ! empty( $inner['error'] ) ) {
			return [
				'code'    => (string) ( $inner['code'] ?? 'wpcc_apply_failed' ),
				'message' => (string) ( $inner['message'] ?? __( 'Apply failed.', 'wp-command-center' ) ),
			];
		}
		return [ 'code' => 'wpcc_apply_failed', 'message' => __( 'Apply failed.', 'wp-command-center' ) ];
	}

	public function kind(): string { return $this->kind; }
	public function is_success(): bool { return self::KIND_SUCCESS === $this->kind; }
	public function is_gated(): bool { return self::KIND_GATED === $this->kind; }
	public function is_failure(): bool { return self::KIND_IN_BAND_ERROR === $this->kind || self::KIND_HARD_FAILURE === $this->kind; }
	public function request_id(): string { return $this->request_id; }
	/** @return array{code:string,message:string} */
	public function error(): array { return $this->error; }
}
