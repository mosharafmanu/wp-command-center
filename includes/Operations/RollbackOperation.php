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
			return new \WP_Error( 'wpcc_invalid_rollback_action', sprintf( __( 'Invalid action: %s. Use rollback_list, rollback_get, rollback_apply, or rollback_verify.', 'wp-command-center' ), esc_html( $action ) ) );
		}

		return match ( $action ) {
			'rollback_list'   => $this->list(),
			'rollback_get'    => $this->get( $params ),
			'rollback_apply'  => $this->apply( $params, $context ),
			'rollback_verify' => $this->verify( $params ),
		};
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

	private function get( array $params ): array|\WP_Error {
		$patch_id = sanitize_text_field( (string) ( $params['patch_id'] ?? '' ) );

		if ( '' === $patch_id ) {
			return new \WP_Error( 'wpcc_missing_patch_id', __( 'patch_id is required.', 'wp-command-center' ) );
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
			return new \WP_Error( 'wpcc_missing_patch_id', __( 'patch_id is required.', 'wp-command-center' ) );
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
			return new \WP_Error( 'wpcc_missing_patch_id', __( 'patch_id is required.', 'wp-command-center' ) );
		}

		$patch = ( new PatchManager() )->get( $patch_id );
		if ( is_wp_error( $patch ) ) {
			return $patch;
		}

		if ( empty( $patch['snapshot_ids'] ) ) {
			return new \WP_Error( 'wpcc_no_snapshots', __( 'No snapshots are available for this patch.', 'wp-command-center' ) );
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
