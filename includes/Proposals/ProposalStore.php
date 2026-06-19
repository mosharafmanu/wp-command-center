<?php
/**
 * STEP 110 (Proposal Store / Governed Drafts) — Task 2: persistence + lifecycle.
 *
 * ProposalStore is the canonical "Propose" stage of the Governed Action contract.
 * It is a REPOSITORY + TRANSITION VALIDATOR over the `wpcc_proposals` table and
 * the SINGLE WRITER of proposal rows (especially `status`, `request_id`,
 * `change_id`, `error_json`). No other class may update those columns — all
 * status changes flow through the transition methods below.
 *
 * It owns persistence, transition legality, field invariants, and idempotency.
 * It does NOT own the *decision* of an externally-driven transition: callers
 * resolve the fact and pass it in (e.g. a future ApplyService decides the
 * executor returned pending_approval, then calls mark_pending_approval(); a
 * future Sync decides a request executed, then calls mark_applied()).
 *
 * Hard boundary — ProposalStore is NOT an executor, approval, synchronization,
 * reconciliation, or change-history system. It MUST NEVER call OperationExecutor
 * or OperationManager, read/write wpcc_operation_requests or wpcc_change_log, or
 * mutate any WordPress content (posts, attachment meta, options). The site is
 * reached only later, by another service handing final_payload to the engine.
 *
 * A proposal holds a PROPOSED operation + payload; persisting one never changes
 * the site.
 */

namespace WPCommandCenter\Proposals;

defined( 'ABSPATH' ) || exit;

final class ProposalStore {

	public const STATUS_DRAFT            = 'draft';
	public const STATUS_PENDING_APPROVAL = 'pending_approval';
	public const STATUS_APPLIED          = 'applied';
	public const STATUS_DISMISSED        = 'dismissed';
	public const STATUS_FAILED           = 'failed';

	/** Terminal states: once reached, the only legal "transition" is the no-op to self. */
	private const TERMINAL = [ self::STATUS_APPLIED, self::STATUS_DISMISSED, self::STATUS_FAILED ];

	/**
	 * Allowed status transitions (source => [permitted targets]). Same-state and
	 * any source not listed here are rejected (terminal sources are absent by
	 * design, freezing them). The draft->draft payload edit is NOT a status
	 * transition and is handled by update_final_payload().
	 */
	private const TRANSITIONS = [
		self::STATUS_DRAFT            => [ self::STATUS_PENDING_APPROVAL, self::STATUS_APPLIED, self::STATUS_FAILED, self::STATUS_DISMISSED ],
		self::STATUS_PENDING_APPROVAL => [ self::STATUS_APPLIED, self::STATUS_FAILED, self::STATUS_DISMISSED ],
	];

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wpcc_proposals';
	}

	// ── Persistence ─────────────────────────────────────────────────────────

	/**
	 * Create a proposal in `draft`. payload is the immutable PROPOSED payload.
	 *
	 * @param array $args {
	 *   @type string     $operation_id  required — the governed op the proposal will run.
	 *   @type string     $target_type   required — object class (attachment/post/...).
	 *   @type array      $payload       required — the proposed operation payload (immutable).
	 *   @type string     $action        optional — the op's sub-action.
	 *   @type string     $target_id     optional — object id.
	 *   @type string     $batch_id      optional — propose-run grouping.
	 *   @type string     $session_id    optional — change-history session at apply time.
	 *   @type array      $prior         optional — old values, for review display only.
	 *   @type string     $provider      optional — suggestion source (openai/agent/human/...).
	 *   @type string     $model         optional — model id when AI-sourced.
	 *   @type float      $confidence    optional — provider confidence.
	 *   @type array      $proposed_by   optional — actor who created the proposal.
	 *   @type string     $risk_level    optional — cached effective risk for display.
	 *   @type int        $expires_at    optional — TTL (unix seconds).
	 * }
	 * @return array|\WP_Error The created row, or an error.
	 */
	public function create( array $args ): array|\WP_Error {
		global $wpdb;

		$operation_id = (string) ( $args['operation_id'] ?? '' );
		$target_type  = (string) ( $args['target_type'] ?? '' );

		if ( '' === $operation_id ) {
			return new \WP_Error( 'wpcc_proposal_missing_operation_id', __( 'operation_id is required.', 'wp-command-center' ) );
		}
		if ( '' === $target_type ) {
			return new \WP_Error( 'wpcc_proposal_missing_target_type', __( 'target_type is required.', 'wp-command-center' ) );
		}
		if ( ! array_key_exists( 'payload', $args ) || ! is_array( $args['payload'] ) ) {
			return new \WP_Error( 'wpcc_proposal_missing_payload', __( 'payload (array) is required.', 'wp-command-center' ) );
		}

		$proposal_id = wp_generate_uuid4();
		$now         = time();

		$data    = [
			'proposal_id'  => $proposal_id,
			'operation_id' => $operation_id,
			'target_type'  => $target_type,
			'payload_json' => (string) wp_json_encode( $args['payload'] ),
			'status'       => self::STATUS_DRAFT,
			'created_at'   => $now,
			'updated_at'   => $now,
		];
		$formats = [ '%s', '%s', '%s', '%s', '%s', '%d', '%d' ];

		// Optional string columns.
		foreach ( [ 'batch_id', 'session_id', 'action', 'target_id', 'provider', 'model', 'risk_level' ] as $col ) {
			if ( isset( $args[ $col ] ) && '' !== (string) $args[ $col ] ) {
				$data[ $col ] = (string) $args[ $col ];
				$formats[]    = '%s';
			}
		}
		if ( isset( $args['prior'] ) && is_array( $args['prior'] ) ) {
			$data['prior_json'] = (string) wp_json_encode( $args['prior'] );
			$formats[]          = '%s';
		}
		if ( isset( $args['proposed_by'] ) && is_array( $args['proposed_by'] ) ) {
			$data['proposed_by'] = (string) wp_json_encode( $args['proposed_by'] );
			$formats[]           = '%s';
		}
		if ( isset( $args['confidence'] ) && is_numeric( $args['confidence'] ) ) {
			$data['confidence'] = (float) $args['confidence'];
			$formats[]          = '%f';
		}
		if ( isset( $args['expires_at'] ) && is_numeric( $args['expires_at'] ) ) {
			$data['expires_at'] = (int) $args['expires_at'];
			$formats[]          = '%d';
		}

		$inserted = $wpdb->insert( $this->table(), $data, $formats );
		if ( false === $inserted ) {
			return new \WP_Error( 'wpcc_proposal_create_failed', __( 'Failed to create proposal.', 'wp-command-center' ) );
		}

		$row = $this->get( $proposal_id );
		return $row ?: new \WP_Error( 'wpcc_proposal_create_failed', __( 'Proposal created but could not be read back.', 'wp-command-center' ) );
	}

	/** Fetch one proposal by its proposal_id (uuid). */
	public function get( string $proposal_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE proposal_id = %s", $proposal_id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * Paginated, filtered listing (newest first). Bounded by design — never
	 * returns an unbounded result set.
	 *
	 * @param array $filters status, operation_id, target_type, target_id,
	 *                       batch_id, session_id, request_id, change_id, limit, offset.
	 * @return array<int,array<string,mixed>>
	 */
	public function list( array $filters = [] ): array {
		global $wpdb;
		[ $where_sql, $params ] = $this->build_where( $filters );

		$limit  = isset( $filters['limit'] ) ? max( 1, min( 200, (int) $filters['limit'] ) ) : 50;
		$offset = isset( $filters['offset'] ) ? max( 0, (int) $filters['offset'] ) : 0;

		$sql      = "SELECT * FROM {$this->table()} {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$params[] = $limit;
		$params[] = $offset;

		return $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) ?: [];
	}

	/** Total rows matching the given filters (for pagination). */
	public function count( array $filters = [] ): int {
		global $wpdb;
		[ $where_sql, $params ] = $this->build_where( $filters );
		$sql = "SELECT COUNT(*) FROM {$this->table()} {$where_sql}";
		return (int) ( empty( $params )
			? $wpdb->get_var( $sql )
			: $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) ) );
	}

	/** @return array{0:string,1:array<int,mixed>} prepared WHERE fragment + params. */
	private function build_where( array $filters ): array {
		$where  = [];
		$params = [];
		foreach ( [ 'status', 'operation_id', 'target_type', 'target_id', 'batch_id', 'session_id', 'request_id', 'change_id' ] as $key ) {
			if ( isset( $filters[ $key ] ) && '' !== (string) $filters[ $key ] ) {
				$where[]  = "{$key} = %s";
				$params[] = (string) $filters[ $key ];
			}
		}
		$where_sql = empty( $where ) ? '' : ( 'WHERE ' . implode( ' AND ', $where ) );
		return [ $where_sql, $params ];
	}

	// ── Lifecycle (state machine) ────────────────────────────────────────────

	/**
	 * Edit the human-reviewed payload while the proposal is still `draft`.
	 * payload_json (the original proposal) is immutable and never touched here.
	 */
	public function update_final_payload( string $proposal_id, array $final_payload ): array|\WP_Error {
		global $wpdb;

		$row = $this->get( $proposal_id );
		if ( ! $row ) {
			return new \WP_Error( 'wpcc_proposal_not_found', __( 'Proposal not found.', 'wp-command-center' ) );
		}
		if ( self::STATUS_DRAFT !== $row['status'] ) {
			return new \WP_Error(
				'wpcc_proposal_not_editable',
				sprintf( __( 'final_payload is editable only while draft (current: %s).', 'wp-command-center' ), (string) $row['status'] )
			);
		}

		$updated = $wpdb->update(
			$this->table(),
			[ 'final_payload_json' => (string) wp_json_encode( $final_payload ), 'updated_at' => time() ],
			[ 'proposal_id' => $proposal_id, 'status' => self::STATUS_DRAFT ],
			[ '%s', '%d' ],
			[ '%s', '%s' ]
		);
		if ( false === $updated ) {
			return new \WP_Error( 'wpcc_proposal_update_failed', __( 'Failed to update proposal payload.', 'wp-command-center' ) );
		}
		return $this->get( $proposal_id ) ?: new \WP_Error( 'wpcc_proposal_not_found', __( 'Proposal not found.', 'wp-command-center' ) );
	}

	/**
	 * Discard a proposal. Legal from draft (user discard) or pending_approval
	 * (a future Sync reflecting a rejected/cancelled request). Idempotent.
	 */
	public function dismiss( string $proposal_id ): array|\WP_Error {
		return $this->transition( $proposal_id, self::STATUS_DISMISSED, [] );
	}

	/**
	 * Reflect that apply was queued for approval. The DECISION belongs to the
	 * caller (e.g. ApplyService saw the executor return pending_approval); this
	 * only persists it. Requires the bridge request_id.
	 */
	public function mark_pending_approval( string $proposal_id, string $request_id ): array|\WP_Error {
		if ( '' === $request_id ) {
			return new \WP_Error( 'wpcc_proposal_request_id_required', __( 'request_id is required to mark a proposal pending_approval.', 'wp-command-center' ) );
		}
		return $this->transition( $proposal_id, self::STATUS_PENDING_APPROVAL, [ 'request_id' => $request_id ] );
	}

	/**
	 * Reflect that apply succeeded. The DECISION belongs to the caller (direct
	 * apply via ApplyService, or a Sync/Reconciler reading the change log); this
	 * only persists it. Requires the bridge change_id; applied_by optional.
	 */
	public function mark_applied( string $proposal_id, string $change_id, ?array $applied_by = null ): array|\WP_Error {
		if ( '' === $change_id ) {
			return new \WP_Error( 'wpcc_proposal_change_id_required', __( 'change_id is required to mark a proposal applied.', 'wp-command-center' ) );
		}
		$fields = [ 'change_id' => $change_id ];
		if ( null !== $applied_by ) {
			$fields['applied_by'] = (string) wp_json_encode( $applied_by );
		}
		return $this->transition( $proposal_id, self::STATUS_APPLIED, $fields );
	}

	/**
	 * Reflect that apply failed. The DECISION belongs to the caller; this only
	 * persists it. Requires a non-empty error (stored as error_json).
	 */
	public function mark_failed( string $proposal_id, array|string $error ): array|\WP_Error {
		$error_json = is_string( $error ) ? $error : (string) wp_json_encode( $error );
		if ( '' === $error_json || '[]' === $error_json || 'null' === $error_json ) {
			return new \WP_Error( 'wpcc_proposal_error_required', __( 'A non-empty error is required to mark a proposal failed.', 'wp-command-center' ) );
		}
		return $this->transition( $proposal_id, self::STATUS_FAILED, [ 'error_json' => $error_json ] );
	}

	/**
	 * The single state-machine writer. Validates legality + freezes terminal
	 * states, applies the status + caller-provided fields + updated_at, and is
	 * idempotent on the no-op-to-self case. Uses an optimistic-concurrency guard
	 * (WHERE proposal_id AND status = current) so a racing transition cannot be
	 * silently overwritten.
	 *
	 * @param array<string,string> $fields Extra columns to set with the status (all %s).
	 */
	private function transition( string $proposal_id, string $to, array $fields ): array|\WP_Error {
		global $wpdb;

		$row = $this->get( $proposal_id );
		if ( ! $row ) {
			return new \WP_Error( 'wpcc_proposal_not_found', __( 'Proposal not found.', 'wp-command-center' ) );
		}
		$from = (string) $row['status'];

		// Idempotent no-op: already in the target state.
		if ( $from === $to ) {
			return $row;
		}
		// Terminal states are frozen.
		if ( in_array( $from, self::TERMINAL, true ) ) {
			return new \WP_Error(
				'wpcc_proposal_terminal',
				sprintf( __( 'Proposal is terminal (%s); cannot transition to %s.', 'wp-command-center' ), $from, $to )
			);
		}
		// Legality.
		$allowed = self::TRANSITIONS[ $from ] ?? [];
		if ( ! in_array( $to, $allowed, true ) ) {
			return new \WP_Error(
				'wpcc_proposal_invalid_transition',
				sprintf( __( 'Illegal transition %s -> %s.', 'wp-command-center' ), $from, $to )
			);
		}

		$data    = array_merge( [ 'status' => $to ], $fields, [ 'updated_at' => time() ] );
		$formats = array_merge( [ '%s' ], array_fill( 0, count( $fields ), '%s' ), [ '%d' ] );

		$updated = $wpdb->update(
			$this->table(),
			$data,
			[ 'proposal_id' => $proposal_id, 'status' => $from ],
			$formats,
			[ '%s', '%s' ]
		);

		if ( false === $updated ) {
			return new \WP_Error( 'wpcc_proposal_update_failed', __( 'Failed to update proposal status.', 'wp-command-center' ) );
		}
		if ( 0 === $updated ) {
			// The row changed under us between read and write. Re-read: if it
			// already reached the target, treat as idempotent success; else the
			// concurrent state is authoritative and this transition is stale.
			$fresh = $this->get( $proposal_id );
			if ( $fresh && $to === $fresh['status'] ) {
				return $fresh;
			}
			return new \WP_Error( 'wpcc_proposal_conflict', __( 'Proposal state changed concurrently; transition not applied.', 'wp-command-center' ) );
		}

		return $this->get( $proposal_id ) ?: new \WP_Error( 'wpcc_proposal_not_found', __( 'Proposal not found.', 'wp-command-center' ) );
	}
}
