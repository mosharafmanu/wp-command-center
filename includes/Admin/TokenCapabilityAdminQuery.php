<?php
/**
 * STEP 107.1 — Token & Capability admin aggregation read (presentation layer).
 *
 * Powers the wp-admin "Tokens & Capabilities" manager: an enriched, read-only
 * roll-up that joins the API token manifest (AuthTokens, STEP 10) with the
 * per-token capability assignments (CapabilityRegistry / wpcc_capability_
 * assignments, STEP 38/44/79) and the operation map. This is a READ-ONLY
 * presentation-layer helper — NOT a runtime API, NOT MCP-exposed, and NOT a new
 * source of truth. It never writes, never adds policy, and must never grow
 * runtime/business logic: it only reads what AuthTokens and CapabilityRegistry
 * already record, and renders the *effective* access truthfully.
 *
 * Cheap by design: one file-manifest read (AuthTokens::list()) + one option
 * read (capability assignments) joined in memory. No DB, no N+1, no caching, no
 * persistence.
 *
 * The "effective access matrix" (the headline read) reproduces the platform's
 * own gates by reading the SAME inputs the engine reads — it does not
 * re-implement or alter policy:
 *   1. system.admin in the token's caps  -> allow (unrestricted shortcut, the
 *      CapabilityRegistry::validate() $has_admin behaviour);
 *   2. else a read_only-scope token is scope-blocked for any operation that
 *      requires full scope (CapabilityRegistry::requires_full_scope(), the
 *      RestApi::require_write() mirror);
 *   3. else allow iff the operation's required capability is assigned
 *      (CapabilityRegistry::validate() missing_capability behaviour).
 * Secrets are never exposed: only the stored token_preview is surfaced; the
 * token_hash is dropped entirely.
 */

namespace WPCommandCenter\Admin;

use WPCommandCenter\Security\AuthTokens;
use WPCommandCenter\Security\AuditLog;
use WPCommandCenter\Operations\CapabilityRegistry;

defined( 'ABSPATH' ) || exit;

final class TokenCapabilityAdminQuery {

	/** Hard cap on audit-trail entries surfaced per token (display bound). */
	private const MAX_AUDIT = 100;

	private AuthTokens $tokens;
	private CapabilityRegistry $caps;

	public function __construct() {
		$this->tokens = new AuthTokens();
		$this->caps   = new CapabilityRegistry();
	}

	/**
	 * Enriched token list (newest first), each with its effective scope, assigned
	 * capabilities, admin flag, status, and a compact access summary (how many of
	 * the mapped operations it can run). No access matrix here — that is per-token
	 * detail. No secrets: token_hash is never surfaced.
	 *
	 * @return array<string,mixed> { action, tokens[], total }
	 */
	public function tokens(): array {
		$rows = array_map( fn( array $t ): array => $this->summarise_token( $t ), $this->tokens->list() );

		return [
			'action' => 'tokens_list',
			'tokens' => $rows,
			'total'  => count( $rows ),
		];
	}

	/**
	 * One token's detail: the summary plus the full per-operation access matrix
	 * over OPERATION_MAP. Returns null when the id is unknown so the route can 404.
	 *
	 * @return array<string,mixed>|null
	 */
	public function token( string $id ): ?array {
		$record = null;
		foreach ( $this->tokens->list() as $t ) {
			if ( (string) ( $t['id'] ?? '' ) === $id ) {
				$record = $t;
				break;
			}
		}

		if ( null === $record ) {
			return null;
		}

		$summary  = $this->summarise_token( $record );
		$assigned = $this->caps->get_for_subject( 'token', $id );

		return [
			'action'         => 'token_detail',
			'token'          => $summary,
			'access_matrix'  => $this->access_matrix( (string) $record['scope'], $assigned ),
			'audit_trail'    => $this->audit_trail( $id ),
		];
	}

	/**
	 * STEP 107.2 — the per-token audit trail: the capability/token lifecycle
	 * events recorded for this token (bootstrap / assigned / removed / validated /
	 * denied / deprovisioned), oldest→newest. A READ-ONLY, bounded tail of the
	 * append-only AuditLog — the same mechanism the Approval Center detail (106.2)
	 * uses. It records nothing and adds no new source of truth.
	 *
	 * A token is referenced two ways in the audit context: directly as
	 * `token_id` (bootstrap / deprovision / capability.denied) or as the
	 * subject pair `subject=token` + `subject_id` (assign / remove / validate via
	 * CapabilityManager). Both are matched.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function audit_trail( string $token_id ): array {
		if ( '' === $token_id ) {
			return [];
		}

		$entries = ( new AuditLog() )->tail( 500 );
		$trail   = [];

		foreach ( $entries as $entry ) {
			$ctx = is_array( $entry['context'] ?? null ) ? $entry['context'] : [];

			$by_token_id = ( (string) ( $ctx['token_id'] ?? '' ) === $token_id );
			$by_subject  = ( 'token' === ( $ctx['subject'] ?? '' ) && (string) ( $ctx['subject_id'] ?? '' ) === $token_id );

			if ( ! $by_token_id && ! $by_subject ) {
				continue;
			}

			$trail[] = [
				'timestamp'  => (int) ( $entry['timestamp'] ?? 0 ),
				'action'     => (string) ( $entry['action'] ?? '' ),
				'capability' => isset( $ctx['capability'] ) && is_string( $ctx['capability'] ) ? $ctx['capability'] : '',
				'actor'      => $this->audit_actor_label( $ctx['actor'] ?? null ),
			];

			if ( count( $trail ) >= self::MAX_AUDIT ) {
				break;
			}
		}

		// tail() is newest-first; present the trail oldest-first (chronological).
		return array_reverse( $trail );
	}

	/**
	 * Human label for an audit-entry actor descriptor (already a resolved array
	 * in the JSONL), mirroring the Change History / Approval Center precedence.
	 */
	private function audit_actor_label( mixed $actor ): ?string {
		if ( ! is_array( $actor ) ) {
			return null;
		}
		foreach ( [ 'label', 'user_login', 'name', 'agent' ] as $key ) {
			if ( ! empty( $actor[ $key ] ) && is_string( $actor[ $key ] ) ) {
				return $actor[ $key ];
			}
		}
		$type = isset( $actor['type'] ) && is_string( $actor['type'] ) ? $actor['type'] : '';
		if ( 'admin' === $type && ! empty( $actor['user_id'] ) ) {
			return 'Admin #' . (int) $actor['user_id'];
		}
		return '' !== $type ? $type : null;
	}

	/**
	 * The capability catalogue: all 23 capabilities and which mapped operations
	 * each one unlocks (reverse of OPERATION_MAP). system.admin is annotated as
	 * the unrestricted shortcut. Read-only — derived entirely from the registry's
	 * own constants.
	 *
	 * @return array<string,mixed> { action, capabilities[], total }
	 */
	public function capabilities(): array {
		$reverse = [];
		foreach ( CapabilityRegistry::OPERATION_MAP as $operation => $capability ) {
			$reverse[ $capability ][] = $operation;
		}

		$catalogue = [];
		foreach ( CapabilityRegistry::ALL_CAPABILITIES as $capability ) {
			$is_admin    = CapabilityRegistry::CAP_SYSTEM_ADMIN === $capability;
			$operations  = $reverse[ $capability ] ?? [];
			sort( $operations );

			$catalogue[] = [
				'capability'      => $capability,
				'is_admin'        => $is_admin,
				'operations'      => $operations,
				'operation_count' => count( $operations ),
				'note'            => $is_admin
					? __( 'Unrestricted — grants every operation regardless of individual capabilities. Cannot be assigned via the API or this manager.', 'wp-command-center' )
					: '',
			];
		}

		return [
			'action'       => 'capabilities_catalogue',
			'capabilities' => $catalogue,
			'total'        => count( $catalogue ),
		];
	}

	/**
	 * The operation map: all 34 OPERATION_MAP entries with the required
	 * capability and whether the operation is in the read-only scope allowlist
	 * (callable by a read_only-scope token). Read-only.
	 *
	 * @return array<string,mixed> { action, operations[], total, read_only_scope_operations[] }
	 */
	public function operations_map(): array {
		$operations = [];
		foreach ( CapabilityRegistry::OPERATION_MAP as $operation => $capability ) {
			$operations[] = [
				'operation'           => $operation,
				'required_capability' => $capability,
				'read_only_scope'     => in_array( $operation, CapabilityRegistry::READ_ONLY_SCOPE_OPERATIONS, true ),
			];
		}

		usort( $operations, static fn( array $a, array $b ): int => strcmp( $a['operation'], $b['operation'] ) );

		return [
			'action'                      => 'operations_map',
			'operations'                  => $operations,
			'total'                       => count( $operations ),
			'read_only_scope_operations'  => array_values( CapabilityRegistry::READ_ONLY_SCOPE_OPERATIONS ),
		];
	}

	/**
	 * Normalise one raw token record into a display-safe summary. Drops the
	 * token_hash entirely; keeps only the stored preview. Computes effective
	 * status, admin flag, assigned caps, and the allowed/total access count.
	 *
	 * @param array<string,mixed> $t
	 * @return array<string,mixed>
	 */
	private function summarise_token( array $t ): array {
		$id       = (string) ( $t['id'] ?? '' );
		$scope    = (string) ( $t['scope'] ?? '' );
		$assigned = '' !== $id ? $this->caps->get_for_subject( 'token', $id ) : [];
		$is_admin = in_array( CapabilityRegistry::CAP_SYSTEM_ADMIN, $assigned, true );

		$matrix      = $this->access_matrix( $scope, $assigned );
		$allowed     = 0;
		foreach ( $matrix as $entry ) {
			if ( $entry['allowed'] ) {
				$allowed++;
			}
		}

		return [
			'id'                    => $id,
			'label'                 => (string) ( $t['label'] ?? '' ),
			'token_preview'         => (string) ( $t['token_preview'] ?? '' ),
			'scope'                 => $scope,
			'scope_label'           => AuthTokens::scope_label( $scope ),
			'status'                => (string) ( $t['status'] ?? '' ),
			'effective_status'      => $this->effective_status( $t ),
			'is_admin'              => $is_admin,
			'assigned_capabilities' => array_values( $assigned ),
			'created_at'            => isset( $t['created_at'] ) ? (int) $t['created_at'] : null,
			'expires_at'            => isset( $t['expires_at'] ) ? (int) $t['expires_at'] : null,
			'last_used_at'          => isset( $t['last_used_at'] ) ? (int) $t['last_used_at'] : null,
			'allowed_operations'    => $allowed,
			'total_operations'      => count( $matrix ),
		];
	}

	/**
	 * Effective status of a token (active / expired / revoked), mirroring
	 * AuthTokens::status_badge()'s precedence without re-rendering HTML.
	 *
	 * @param array<string,mixed> $t
	 */
	private function effective_status( array $t ): string {
		if ( AuthTokens::STATUS_REVOKED === ( $t['status'] ?? '' ) ) {
			return 'revoked';
		}
		if ( isset( $t['expires_at'] ) && null !== $t['expires_at'] && (int) $t['expires_at'] < time() ) {
			return 'expired';
		}
		return 'active';
	}

	/**
	 * The per-operation effective access matrix for a token with the given scope
	 * and assigned capabilities. One entry per OPERATION_MAP operation. See the
	 * class header for the three-step algorithm — it reproduces the engine's own
	 * gates by reading the same registry inputs; it adds no new policy.
	 *
	 * @param string[] $assigned
	 * @return array<int,array<string,mixed>>
	 */
	private function access_matrix( string $scope, array $assigned ): array {
		$is_admin     = in_array( CapabilityRegistry::CAP_SYSTEM_ADMIN, $assigned, true );
		$is_read_only = AuthTokens::SCOPE_READ_ONLY === $scope;
		$matrix       = [];

		foreach ( CapabilityRegistry::OPERATION_MAP as $operation => $required ) {
			if ( $is_admin ) {
				$matrix[] = $this->matrix_entry( $operation, $required, true, 'system_admin' );
				continue;
			}

			if ( $is_read_only && $this->caps->requires_full_scope( $operation ) ) {
				$matrix[] = $this->matrix_entry( $operation, $required, false, 'scope_blocked' );
				continue;
			}

			$allowed = in_array( $required, $assigned, true );
			$matrix[] = $this->matrix_entry(
				$operation,
				$required,
				$allowed,
				$allowed ? 'capability_assigned' : 'missing_capability'
			);
		}

		usort( $matrix, static fn( array $a, array $b ): int => strcmp( $a['operation'], $b['operation'] ) );

		return $matrix;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function matrix_entry( string $operation, string $required, bool $allowed, string $reason ): array {
		return [
			'operation'           => $operation,
			'required_capability' => $required,
			'allowed'             => $allowed,
			'reason'              => $reason,
		];
	}
}
