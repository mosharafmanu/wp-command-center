<?php
/**
 * V1 Phase 3 — the rollback routing contract.
 *
 * Undo was reachable four different ways and a caller had to guess which:
 *
 *   - `change_history` { rollback_target, change_id }   — the general entry point
 *   - `/operations/<id>/rollback` { rollback_id }        — per-operation REST route
 *   - an action on the operation itself                 — content_rollback, seo_restore, option_rollback
 *   - `rollback_manage` / `/patches/<id>/rollback`       — patches only
 *
 * The engine behind them was already unified (OperationExecutor::rollback resolves
 * the owning runtime), so nothing here changes how an undo executes or what it is
 * allowed to do. What was missing was the CONTRACT: a write said `rollback_id` and
 * `rollback_available: true` and then left the caller to work out where to send it.
 * Measured across two certifications: an assistant holding a valid rollback_id took
 * it to rollback_manage — the one route that only understands patches — and got a
 * dead end.
 *
 * Every reversible write now carries a `rollback` block naming the tool, the action,
 * the exact arguments, and whether the undo will itself need approval. Approval is
 * unchanged: an undo is a change and is governed like one.
 */

namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class RollbackContract {

	/**
	 * Operations whose undo is an ACTION on the operation itself rather than a
	 * dedicated rollback handler. Mirrors OperationExecutor::ACTION_ROLLBACKS, which
	 * stays the authority for dispatch; this is the caller-facing description of it.
	 */
	private const ACTION_ROLLBACKS = [
		'option_manage'  => 'option_rollback',
		'content_manage' => 'content_rollback',
		'seo_manage'     => 'seo_restore',
	];

	/** Patches keep their own engine (snapshot per file, atomic replace). */
	private const PATCH_OPERATIONS = [ 'patch_manage', 'rollback_manage' ];

	/**
	 * How to undo a specific write.
	 *
	 * @param string $operation_id The operation that produced the rollback handle.
	 * @param string $rollback_id  The handle it returned.
	 * @return array<string,mixed>
	 */
	public static function describe( string $operation_id, string $rollback_id ): array {
		if ( '' === $rollback_id ) {
			return [ 'reversible' => false ];
		}

		$approval = self::undo_needs_approval();

		if ( in_array( $operation_id, self::PATCH_OPERATIONS, true ) ) {
			return [
				'reversible'        => true,
				'rollback_id'       => $rollback_id,
				'undo_with'         => [
					'tool'      => 'patch_manage',
					'arguments' => [ 'action' => 'patch_status', 'patch_id' => $rollback_id ],
					'note'      => __( 'A patch is reversed through the patch engine, which restores the per-file snapshot taken before it was applied.', 'action-steward' ),
				],
				'approval_required' => $approval,
			];
		}

		$undo = [
			// The general entry point: it resolves the change, routes to the owning
			// runtime, and records the reversal against the original.
			'tool'      => 'change_history',
			'arguments' => [ 'action' => 'rollback_target', 'rollback_id' => $rollback_id ],
		];

		if ( isset( self::ACTION_ROLLBACKS[ $operation_id ] ) ) {
			$undo['also_available'] = [
				'tool'      => $operation_id,
				'arguments' => [ 'action' => self::ACTION_ROLLBACKS[ $operation_id ], 'rollback_id' => $rollback_id ],
			];
		} else {
			$undo['also_available'] = [
				'rest' => '/operations/' . $operation_id . '/rollback',
				'body' => [ 'rollback_id' => $rollback_id ],
			];
		}

		return [
			'reversible'        => true,
			'rollback_id'       => $rollback_id,
			'undo_with'         => $undo,
			'approval_required' => $approval,
		];
	}

	/**
	 * Whether an undo will need a human decision in the current protection mode.
	 *
	 * An undo is a change and is gated like one — this only tells the caller what to
	 * expect, it does not decide anything. Developer mode executes immediately;
	 * Standard and Strict send the undo to Approvals.
	 */
	public static function undo_needs_approval(): bool {
		return SecurityModeManager::MODE_DEVELOPER !== SecurityModeManager::current();
	}

	/**
	 * Attach the contract to a result that carries a rollback handle.
	 *
	 * Additive: `rollback_id` and `rollback_available` stay exactly where they were,
	 * so every existing caller keeps working.
	 *
	 * @param array<string,mixed> $result
	 * @return array<string,mixed>
	 */
	public static function decorate( string $operation_id, array $result ): array {
		$rollback_id = '';
		foreach ( [ 'rollback_id', 'change_set_id', 'patch_id' ] as $key ) {
			if ( isset( $result[ $key ] ) && is_scalar( $result[ $key ] ) && '' !== (string) $result[ $key ] ) {
				$rollback_id = (string) $result[ $key ];
				break;
			}
		}

		if ( '' === $rollback_id || isset( $result['rollback'] ) ) {
			return $result;
		}

		// Only describe an undo the operation actually offers. A result may carry an
		// id for other reasons (a patch_status read, for instance).
		$offered = ! array_key_exists( 'rollback_available', $result ) || ! empty( $result['rollback_available'] );
		if ( ! $offered ) {
			return $result;
		}

		$result['rollback'] = self::describe( $operation_id, $rollback_id );

		return $result;
	}
}
