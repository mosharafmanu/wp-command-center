<?php
/**
 * STEP 104.1 — Change-log recorder (system of record).
 *
 * Invoked once per execution at the single OperationExecutor chokepoint, after
 * the result is normalized and the operation_result row is written (so the
 * operation_id, action, links, counts, rollback handle, and result_ref are all
 * known). Records exactly one `wpcc_change_log` row per *mutating* execution —
 * success or failure — and emits a dual-write `change.recorded` audit event.
 *
 * It NEVER stores content blobs: full diffs/snapshots live in their existing
 * stores; this row is a queryable metadata index that points at them. It is
 * best-effort and failure-silent — recording a change must never break the
 * operation it records (mirrors AuditLog).
 *
 * Read/diagnostic executions are skipped. The determinant is the registry
 * effective risk: `diagnostic` actions are always skipped. The only read
 * actions the registry classifies above `diagnostic` are `content_get` /
 * `content_list` (`low`); for the whole `low` tier we additionally skip a
 * *successful* execution that produced no observable change (no created/
 * updated/skipped counts, no rollback handle), so under-classified reads do
 * not pollute history while every real low-risk write is still recorded.
 */

namespace WPCommandCenter\Operations;

use WPCommandCenter\Security\AuditLog;

defined( 'ABSPATH' ) || exit;

final class ChangeRecorder {

	/**
	 * Record one change-log row for a completed (or failed) operation execution.
	 *
	 * @param array $args {
	 *     @type array  $operation    Registry operation definition (for effective risk).
	 *     @type string $operation_id
	 *     @type array  $payload      Original request payload (for `action`).
	 *     @type array  $context      Execution context (actor, source, links).
	 *     @type array  $links        session_id/task_id/action_id/plan_id/request_id/queue_id.
	 *     @type string $status       applied | transactional_apply_failed | failed.
	 *     @type array  $result       Redaction-safe normalized result (empty on failure).
	 *     @type string $result_ref   wpcc_operation_results.result_id for this execution.
	 *     @type array  $counts       [created, updated, skipped, error].
	 * }
	 */
	public function record( array $args ): void {
		try {
			$this->record_inner( $args );
		} catch ( \Throwable $e ) {
			// Best-effort: never let change recording break the operation.
			return;
		}
	}

	private function record_inner( array $args ): void {
		$operation    = is_array( $args['operation'] ?? null ) ? $args['operation'] : [];
		$operation_id = (string) ( $args['operation_id'] ?? '' );
		$payload      = is_array( $args['payload'] ?? null ) ? $args['payload'] : [];
		$context      = is_array( $args['context'] ?? null ) ? $args['context'] : [];
		$links        = is_array( $args['links'] ?? null ) ? $args['links'] : [];
		$status       = (string) ( $args['status'] ?? 'applied' );
		$result       = is_array( $args['result'] ?? null ) ? $args['result'] : [];
		$result_ref   = (string) ( $args['result_ref'] ?? '' );
		$counts       = is_array( $args['counts'] ?? null ) ? $args['counts'] : [ 0, 0, 0, 0 ];

		if ( '' === $operation_id ) {
			return;
		}

		// STEP 104.3 — the change_history runtime is the index/router itself, not a
		// recorded change. Its read actions are diagnostic (skipped below anyway);
		// its one write action (rollback_target) records its OWN authoritative
		// `rolled_back` row via record_rollback(), so never auto-record it here.
		if ( 'change_history' === $operation_id ) {
			return;
		}

		$action         = (string) ( $payload['action'] ?? '' );
		$effective_risk = SecurityModeManager::effective_risk( $operation, $action );
		$is_failure     = 'failed' === $status;

		// Read/diagnostic executions are not changes.
		if ( SecurityModeManager::RISK_DIAGNOSTIC === $effective_risk ) {
			return;
		}

		$created_count = (int) ( $counts[0] ?? 0 );
		$updated_count = (int) ( $counts[1] ?? 0 );
		$skipped_count = (int) ( $counts[2] ?? 0 );
		$error_count   = (int) ( $counts[3] ?? 0 );

		$rdata         = is_array( $result['result'] ?? null ) ? $result['result'] : [];
		$rollback_id   = $this->first_string( [ $result['rollback_id'] ?? null, $rdata['rollback_id'] ?? null ] );
		$change_set_id = $this->first_string( [ $rdata['change_set_id'] ?? null ] );

		// Low tier: skip a *successful* no-footprint execution (covers the
		// registry's content_get / content_list under-classification).
		if ( ! $is_failure
			&& SecurityModeManager::RISK_LOW === $effective_risk
			&& 0 === $created_count + $updated_count + $skipped_count
			&& null === $rollback_id
			&& null === $change_set_id
		) {
			return;
		}

		$runtime = (string) preg_replace( '/_manage$/', '', $operation_id );

		if ( null !== $change_set_id ) {
			$rollback_kind = 'patch';
		} elseif ( null !== $rollback_id ) {
			$rollback_kind = 'runtime_option';
		} else {
			$rollback_kind = 'none';
		}
		$reversible = 'none' === $rollback_kind ? 0 : 1;

		$created = is_array( $result['created'] ?? null ) ? $result['created'] : [];
		$updated = is_array( $result['updated'] ?? null ) ? $result['updated'] : [];
		$affected_paths = is_array( $rdata['affected_paths'] ?? null ) ? array_values( $rdata['affected_paths'] ) : [];

		$target_summary = array_filter(
			[
				'affected_paths' => $affected_paths ?: null,
				'created'        => $created ?: null,
				'updated'        => $updated ?: null,
				'file_count'     => isset( $rdata['file_count'] ) ? (int) $rdata['file_count'] : null,
			],
			static fn( $v ) => null !== $v
		);

		$target_key = null;
		if ( ! empty( $affected_paths ) ) {
			$target_key = (string) $affected_paths[0];
		} elseif ( ! empty( $created ) ) {
			$target_key = $runtime . ':' . (string) reset( $created );
		} elseif ( ! empty( $updated ) ) {
			$target_key = $runtime . ':' . (string) reset( $updated );
		}
		if ( is_string( $target_key ) ) {
			$target_key = substr( $target_key, 0, 190 );
		}

		$actor      = $context['actor'] ?? [];
		$actor      = is_array( $actor ) ? $actor : [];
		$actor_json = wp_json_encode( $this->resolve_change_actor( $actor, $context ) );
		$source     = substr( (string) ( $context['source'] ?? ( $payload['source'] ?? 'api' ) ), 0, 20 );

		$change_id = wp_generate_uuid4();
		$now       = time();

		global $wpdb;
		$table = $wpdb->prefix . 'wpcc_change_log';

		$wpdb->insert(
			$table,
			[
				'change_id'                => $change_id,
				'operation_id'             => substr( $operation_id, 0, 50 ),
				'action'                   => '' !== $action ? substr( $action, 0, 64 ) : null,
				'runtime'                  => substr( $runtime, 0, 40 ),
				'status'                   => substr( $status, 0, 24 ),
				'reversible'               => $reversible,
				'rollback_kind'            => $rollback_kind,
				'rollback_id'              => $rollback_id,
				'rolled_back_by_change_id' => null,
				'change_set_id'            => $change_set_id,
				'request_id'               => $this->link( $links, 'request_id' ),
				'session_id'               => $this->link( $links, 'session_id' ),
				'task_id'                  => $this->link( $links, 'task_id' ),
				'plan_id'                  => $this->link( $links, 'plan_id' ),
				'action_id'                => $this->link( $links, 'action_id' ),
				'actor_json'               => is_string( $actor_json ) ? $actor_json : null,
				'risk_level'               => substr( $effective_risk, 0, 20 ),
				'source'                   => $source,
				'target_summary'           => $target_summary ? wp_json_encode( $target_summary ) : null,
				'target_key'               => $target_key,
				'created_count'            => $created_count,
				'updated_count'            => $updated_count,
				'skipped_count'            => $skipped_count,
				'error_count'              => $error_count,
				'result_ref'               => '' !== $result_ref ? $result_ref : null,
				'created_at'               => $now,
				'rolled_back_at'           => null,
			],
			[
				'%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s',
				'%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
				'%d', '%d', '%d', '%d', '%s', '%d', '%d',
			]
		);

		// Dual-write: the table is the queryable source of truth, the JSONL audit
		// log keeps the immutable append-only trail (compact — no payload blobs).
		( new AuditLog() )->record( 'change.recorded', array_merge( $links, [
			'change_id'     => $change_id,
			'operation_id'  => $operation_id,
			'action'        => $action,
			'runtime'       => $runtime,
			'status'        => $status,
			'reversible'    => (bool) $reversible,
			'rollback_kind' => $rollback_kind,
			'rollback_id'   => $rollback_id,
			'change_set_id' => $change_set_id,
			'target_key'    => $target_key,
			'risk_level'    => $effective_risk,
			'counts'        => [
				'created' => $created_count,
				'updated' => $updated_count,
				'skipped' => $skipped_count,
				'error'   => $error_count,
			],
			'result_ref'    => '' !== $result_ref ? $result_ref : null,
		] ) );
	}

	/**
	 * STEP 104.3 — record a successful rollback_target. Inserts ONE new
	 * `rolled_back` change-log row describing the reversal, stamps the original
	 * row (status=rolled_back, rolled_back_at, rolled_back_by_change_id), and
	 * emits a `change.rolled_back` audit event. This is the sole writer of the
	 * rolled_back linkage — the change_history op itself is never auto-recorded.
	 *
	 * @param array<string,mixed> $original Raw original wpcc_change_log row (assoc).
	 * @param array<string,mixed> $context  Execution context (actor, source, links).
	 * @return string The new change_id (the reverser), or '' on failure.
	 */
	public function record_rollback( array $original, array $context, ?string $result_ref = null ): string {
		try {
			global $wpdb;
			$table = $wpdb->prefix . 'wpcc_change_log';

			$original_change_id = (string) ( $original['change_id'] ?? '' );
			if ( '' === $original_change_id ) {
				return '';
			}

			$now       = time();
			$new_id    = wp_generate_uuid4();
			$links     = is_array( $context['links'] ?? null ) ? $context['links'] : $context;
			$actor     = is_array( $context['actor'] ?? null ) ? $context['actor'] : [];
			$actor_json = wp_json_encode( $this->resolve_change_actor( $actor, $context ) );
			$source    = substr( (string) ( $context['source'] ?? 'api' ), 0, 20 );
			$runtime   = (string) ( $original['runtime'] ?? '' );

			$summary = [ 'reverts_change_id' => $original_change_id ];
			if ( ! empty( $original['target_summary'] ) ) {
				$decoded = json_decode( (string) $original['target_summary'], true );
				if ( is_array( $decoded ) ) {
					$summary = array_merge( $summary, $decoded );
				}
			}

			// New row: the reversal event (not itself reversible).
			$wpdb->insert(
				$table,
				[
					'change_id'                => $new_id,
					'operation_id'             => substr( (string) ( $original['operation_id'] ?? 'change_history' ), 0, 50 ),
					'action'                   => 'rollback_target',
					'runtime'                  => substr( $runtime, 0, 40 ),
					'status'                   => 'rolled_back',
					'reversible'               => 0,
					'rollback_kind'            => 'none',
					'rollback_id'              => null,
					'rolled_back_by_change_id' => null,
					'change_set_id'            => null !== ( $original['change_set_id'] ?? null ) ? (string) $original['change_set_id'] : null,
					'request_id'               => $this->link( $links, 'request_id' ),
					'session_id'               => $this->link( $links, 'session_id' ),
					'task_id'                  => $this->link( $links, 'task_id' ),
					'plan_id'                  => $this->link( $links, 'plan_id' ),
					'action_id'                => $this->link( $links, 'action_id' ),
					'actor_json'               => is_string( $actor_json ) ? $actor_json : null,
					'risk_level'               => 'high',
					'source'                   => $source,
					'target_summary'           => wp_json_encode( $summary ),
					'target_key'               => null !== ( $original['target_key'] ?? null ) ? substr( (string) $original['target_key'], 0, 190 ) : null,
					'created_count'            => 0,
					'updated_count'            => 0,
					'skipped_count'            => 0,
					'error_count'              => 0,
					'result_ref'               => ( null !== $result_ref && '' !== $result_ref ) ? $result_ref : null,
					'created_at'               => $now,
					'rolled_back_at'           => null,
				],
				[
					'%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s',
					'%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
					'%d', '%d', '%d', '%d', '%s', '%d', '%d',
				]
			);

			// Stamp the original as reversed.
			$wpdb->update(
				$table,
				[
					'status'                   => 'rolled_back',
					'rolled_back_at'           => $now,
					'rolled_back_by_change_id' => $new_id,
				],
				[ 'change_id' => $original_change_id ],
				[ '%s', '%d', '%s' ],
				[ '%s' ]
			);

			( new AuditLog() )->record( 'change.rolled_back', array_merge( is_array( $links ) ? $links : [], [
				'change_id'          => $original_change_id,
				'rolled_back_by'     => $new_id,
				'operation_id'       => (string) ( $original['operation_id'] ?? '' ),
				'runtime'            => $runtime,
				'rollback_kind'      => (string) ( $original['rollback_kind'] ?? '' ),
				'rollback_id'        => $original['rollback_id'] ?? null,
				'target_key'         => $original['target_key'] ?? null,
			] ) );

			return $new_id;
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * STEP 105.5 — actor for a change-log row, with a backstop that GUARANTEES no
	 * row is ever recorded as "unknown". For interactive callers (token/mcp/admin)
	 * resolve_actor returns their real actor and is used as-is. When there is no
	 * actor and no logged-in user (cron / queue worker / workflow / headless
	 * request), resolve_actor falls back to {type:unknown}; here we replace that
	 * with a descriptive system actor, labelled by the caller's `system_via` hint.
	 *
	 * Scoped to the change log only — AuditLog::resolve_actor() and the JSONL
	 * audit semantics are unchanged. Never modifies historical rows.
	 *
	 * @param array<string,mixed> $actor   Raw actor from the execution context.
	 * @param array<string,mixed> $context Execution context (may carry system_via).
	 * @return array<string,mixed>
	 */
	private function resolve_change_actor( array $actor, array $context ): array {
		$resolved = AuditLog::resolve_actor( $actor );

		if ( empty( $resolved ) || 'unknown' === ( $resolved['type'] ?? '' ) ) {
			$via = isset( $context['system_via'] ) && is_string( $context['system_via'] ) && '' !== $context['system_via']
				? $context['system_via']
				: 'system';
			return AuditLog::system_actor( $via );
		}

		return $resolved;
	}

	/**
	 * First non-empty string from a list of candidates, else null.
	 *
	 * @param array<int, mixed> $candidates
	 */
	private function first_string( array $candidates ): ?string {
		foreach ( $candidates as $c ) {
			if ( is_string( $c ) && '' !== $c ) {
				return $c;
			}
		}
		return null;
	}

	/**
	 * Read a non-empty link value from the links map, else null.
	 */
	private function link( array $links, string $key ): ?string {
		$v = $links[ $key ] ?? null;
		return ( is_string( $v ) && '' !== $v ) ? $v : null;
	}
}
