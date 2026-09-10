<?php
/**
 * STEP 87 — rollback_manage operation handler.
 *
 * Bridges the Patch Engine's rollback path (PatchApproval::rollback, which
 * restores every affected file from the pre-apply snapshot with hash
 * verification) to the Operations framework, for both REST and MCP.
 *
 * Actions:
 *   - rollback_list   : applied, rollback-capable patches                (read)
 *   - rollback_get    : a patch's rollback metadata (snapshots, paths)   (read)
 *   - rollback_apply  : restore files from the pre-apply snapshots        (file write)
 *   - rollback_verify : verify snapshot integrity for a patch            (read)
 */

namespace WPCommandCenter\Operations;

use WPCommandCenter\PatchSystem\PatchManager;
use WPCommandCenter\PatchSystem\PatchApproval;
use WPCommandCenter\Rollback\SnapshotManager;
use WPCommandCenter\Security\AuditLog;

defined( 'ABSPATH' ) || exit;

final class RollbackOperation {

	const ACTIONS = [ 'rollback_list', 'rollback_get', 'rollback_apply', 'rollback_verify' ];

	public function run( array $params, array $context = [] ): array|\WP_Error {
		$action = sanitize_key( $params['action'] ?? '' );

		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			return new \WP_Error( 'wpcc_invalid_rollback_action', InvalidAction::message( 'rollback', $action, self::ACTIONS ) );
		}

		return match ( $action ) {
			'rollback_list'   => $this->list(),
			'rollback_get'    => $this->get( $params ),
			'rollback_apply'  => $this->apply( $params, $context ),
			'rollback_verify' => $this->verify( $params ),
		};
	}

	/**
	 * Refuse a rollback this operation could never perform, BEFORE a human is asked to
	 * approve it. See OperationExecutor::preflight() for the general contract.
	 *
	 * WHY (REAL_TEST_FINDINGS.md #2, observed against a live client): a site tagline was
	 * changed through MCP, approved, applied and recorded as reversible. A rollback was
	 * then requested for it — reasonably, since the tool is called `rollback_manage` and
	 * has an action called `rollback_apply`. That request was queued, marked high risk,
	 * approved by an administrator, and only then refused, because `rollback_manage`
	 * reverses file patches and nothing else. The refusal itself was correct and the site
	 * stayed intact; what was wrong is that it arrived after the approval instead of
	 * before it.
	 *
	 * The decision here is made from the ACTUAL change type, not from the example that
	 * exposed it. The identifier is resolved against the change log and the row's own
	 * `rollback_kind` decides: `patch` belongs here, anything else belongs to
	 * `change_history {action: "rollback_target"}`, which already routes every kind to
	 * its owning engine. No new restore path is introduced and no rollback is silently
	 * re-routed — silently executing a different operation than the one an administrator
	 * approved would trade this bug for a much worse one.
	 *
	 * @param array<string,mixed> $params
	 * @param array<string,mixed> $context
	 */
	public function preflight( array $params, array $context = [] ): ?\WP_Error {
		$action = sanitize_key( $params['action'] ?? '' );

		// Only the write path can waste an approval; reads are never gated.
		if ( 'rollback_apply' !== $action ) {
			return null;
		}

		$patch_id = sanitize_text_field( (string) ( $params['patch_id'] ?? '' ) );

		if ( '' === $patch_id ) {
			// Wrong-shaped call: no patch_id at all, possibly a rollback_id instead.
			// missing_patch_id() already names the correct route for both cases.
			return self::missing_patch_id( $params );
		}

		$patch = ( new PatchManager() )->get( $patch_id );

		if ( is_wp_error( $patch ) ) {
			/*
			 * The id did not resolve as a patch. Before rejecting it outright, ask the
			 * change log what it actually is — an id that names a real, reversible,
			 * non-patch change deserves an error that points at the operation which CAN
			 * undo it, rather than a bare "patch not found" that leaves the caller with
			 * a valid handle and nowhere to take it.
			 */
			$kind = self::change_log_rollback_kind( $patch_id );

			if ( null !== $kind && 'patch' !== $kind ) {
				return new \WP_Error(
					'wpcc_not_a_patch_rollback',
					sprintf(
						/* translators: 1: the rollback kind recorded for this change, 2: the identifier supplied */
						__( 'This is a %1$s change, not a file patch, so rollback_manage cannot undo it — and this was refused before asking anyone to approve it. Undo it with change_history {action: "rollback_target", rollback_id: "%2$s"}, which routes each change to the engine that made it.', 'ai-command-center' ),
						$kind,
						$patch_id
					)
				);
			}

			return $patch;
		}

		return null;
	}

	/**
	 * The `rollback_kind` the change log recorded for an identifier, or null when the
	 * identifier is unknown to it.
	 *
	 * Accepts either handle because callers hold either: `rollback_id` is what a
	 * reversible write returns, `change_id` is what the recorder mints afterwards.
	 * Read-only, and tolerant of the table being absent so preflight can never become a
	 * reason an operation fails to start.
	 */
	private static function change_log_rollback_kind( string $identifier ): ?string {
		global $wpdb;

		$table = $wpdb->prefix . 'wpcc_change_log';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery -- single indexed lookup on a plugin table, no cache layer exists for it.
		$kind = $wpdb->get_var( $wpdb->prepare(
			"SELECT rollback_kind FROM {$table} WHERE rollback_id = %s OR change_id = %s ORDER BY id DESC LIMIT 1",
			$identifier,
			$identifier
		) );

		return ( null === $kind || '' === $kind ) ? null : (string) $kind;
	}

	private function list(): array {
		$rollbackable = [];

		foreach ( ( new PatchManager() )->list() as $summary ) {
			if ( ( $summary['status'] ?? '' ) === PatchManager::STATUS_APPLIED ) {
				$rollbackable[] = [
					'patch_id'   => $summary['id'] ?? null,
					'status'     => $summary['status'],
					'risk_level' => $summary['risk_level'] ?? null,
					'created_at' => $summary['created_at'] ?? null,
				];
			}
		}

		return [
			'action'  => 'rollback_list',
			'patches' => $rollbackable,
			'count'   => count( $rollbackable ),
		];
	}

	/**
	 * rollback_manage only ever operates on patches (file changes made through
	 * patch_manage). Every other runtime — ACF values, SEO, Woo, settings — hands
	 * back a `rollback_id` with `rollback_available: true`, and this tool offers an
	 * action literally called `rollback_apply`, so a caller holding one of those
	 * ids very reasonably brings it here. It used to answer "patch_id is required",
	 * naming a parameter they never supplied and never mentioning that their id is
	 * undone somewhere else — which costs them the undo. Say both things.
	 *
	 * @param array<string,mixed> $params
	 */
	private static function missing_patch_id( array $params ): \WP_Error {
		$offered = sanitize_text_field( (string) ( $params['rollback_id'] ?? '' ) );

		if ( '' !== $offered ) {
			return new \WP_Error(
				'wpcc_not_a_patch_rollback',
				__( 'rollback_manage undoes patches only, and takes patch_id. A rollback_id returned by another operation (ACF, SEO, WooCommerce, settings, media) is undone with change_history {action: "rollback_target", change_id: "..."} — use change_history {action: "rollback_discover"} to find the change_id.', 'ai-command-center' )
			);
		}

		return new \WP_Error( 'wpcc_missing_patch_id', __( 'patch_id is required. rollback_manage undoes patches only; to undo any other change use change_history {action: "rollback_target"}.', 'ai-command-center' ) );
	}

	private function get( array $params ): array|\WP_Error {
		$patch_id = sanitize_text_field( (string) ( $params['patch_id'] ?? '' ) );

		if ( '' === $patch_id ) {
			return self::missing_patch_id( $params );
		}

		$patch = ( new PatchManager() )->get( $patch_id );
		if ( is_wp_error( $patch ) ) {
			return $patch;
		}

		return [
			'action'             => 'rollback_get',
			'patch_id'           => $patch['id'],
			'status'             => $patch['status'],
			'snapshot_ids'       => $patch['snapshot_ids'] ?? [],
			'paths'              => array_map( static fn( $f ) => $f['path'], $patch['files'] ),
			'rollback_available' => PatchManager::STATUS_APPLIED === $patch['status'] && ! empty( $patch['snapshot_ids'] ),
		];
	}

	private function apply( array $params, array $context ): array|\WP_Error {
		$patch_id = sanitize_text_field( (string) ( $params['patch_id'] ?? '' ) );

		if ( '' === $patch_id ) {
			return self::missing_patch_id( $params );
		}

		$actor  = $context['actor'] ?? [];
		$result = ( new PatchApproval() )->rollback( $patch_id, $actor );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// STEP 103 — one combined rollback restores every file in the change set.
		$rollback_results = $result['rollback_results'] ?? [];
		$restored_paths   = array_keys( $rollback_results );
		$all_verified     = true;
		foreach ( $rollback_results as $r ) {
			$all_verified = $all_verified && ! empty( $r['verified'] );
		}

		// PatchApproval already audits patch.rolled_back.
		return [
			'action'           => 'rollback_apply',
			'patch_id'         => $patch_id,
			'change_set_id'    => $patch_id,
			'status'           => $result['status'],
			'restored'         => PatchManager::STATUS_ROLLED_BACK === $result['status'],
			'files_restored'   => count( $restored_paths ),
			'affected_paths'   => $restored_paths,
			'all_verified'     => $all_verified,
			'rollback_results' => $rollback_results,
		];
	}

	private function verify( array $params ): array|\WP_Error {
		$patch_id = sanitize_text_field( (string) ( $params['patch_id'] ?? '' ) );

		if ( '' === $patch_id ) {
			return self::missing_patch_id( $params );
		}

		$patch = ( new PatchManager() )->get( $patch_id );
		if ( is_wp_error( $patch ) ) {
			return $patch;
		}

		if ( empty( $patch['snapshot_ids'] ) ) {
			return new \WP_Error( 'wpcc_no_snapshots', __( 'No snapshots are available for this patch.', 'ai-command-center' ) );
		}

		$snapshots = new SnapshotManager();
		$checks    = [];
		$all_ok    = true;

		foreach ( $patch['snapshot_ids'] as $path => $snapshot_id ) {
			$record   = $snapshots->get( $snapshot_id );
			$contents = $snapshots->get_contents( $snapshot_id );

			$intact = ! is_wp_error( $record )
				&& ! is_wp_error( $contents )
				&& isset( $record['hash'] )
				&& hash_equals( (string) $record['hash'], md5( (string) $contents ) );

			$all_ok = $all_ok && $intact;

			$checks[ $path ] = [
				'snapshot_id' => $snapshot_id,
				'intact'      => $intact,
			];
		}

		return [
			'action'      => 'rollback_verify',
			'patch_id'    => $patch_id,
			'all_intact'  => $all_ok,
			'checks'      => $checks,
		];
	}

	private function audit( string $event, array $data, array $context ): void {
		$actor = isset( $context['actor'] ) ? AuditLog::resolve_actor( $context['actor'] ) : null;
		( new AuditLog() )->record( $event, array_merge( [ 'actor' => $actor ], $data ) );
	}
}
