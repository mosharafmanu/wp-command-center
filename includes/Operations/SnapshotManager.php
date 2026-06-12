<?php
/**
 * Step 41 — Snapshot Operations Runtime.
 *
 * Wraps the existing SnapshotManager and RollbackManager as structured
 * operations within the Operations Runtime. No new snapshot/rollback logic —
 * all work delegated to the existing engines.
 */

namespace WPCommandCenter\Operations;

use WPCommandCenter\Rollback\SnapshotManager as CoreSnapshotManager;
use WPCommandCenter\Rollback\RollbackManager as CoreRollbackManager;
use WPCommandCenter\Health\HealthVerificationEngine;
use WPCommandCenter\Security\AuditLog;
use WPCommandCenter\Security\PathGuard;

defined( 'ABSPATH' ) || exit;

final class SnapshotManager {

	private SnapshotRegistry $registry;
	private CoreSnapshotManager $snapshots;
	private CoreRollbackManager $rollback;

	public function __construct() {
		$this->registry  = new SnapshotRegistry();
		$this->snapshots = new CoreSnapshotManager();
		$this->rollback  = new CoreRollbackManager();
	}

	public function run( array $params, array $context = [] ): array|\WP_Error {
		$action = sanitize_key( $params['action'] ?? '' );

		if ( ! in_array( $action, SnapshotRegistry::ACTIONS, true ) ) {
			return new \WP_Error( 'wpcc_invalid_snapshot_action', __( 'Invalid snapshot action.', 'wp-command-center' ) );
		}

		return match ( $action ) {
			SnapshotRegistry::ACTION_CREATE  => $this->create( $params, $context ),
			SnapshotRegistry::ACTION_LIST    => $this->list_snapshots(),
			SnapshotRegistry::ACTION_DETAILS => $this->details( $params ),
			SnapshotRegistry::ACTION_RESTORE => $this->restore( $params, $context ),
			SnapshotRegistry::ACTION_VERIFY  => $this->verify( $params, $context ),
			default => new \WP_Error( 'wpcc_invalid_snapshot_action', __( 'Unknown action.', 'wp-command-center' ) ),
		};
	}

	// ── Create ──

	private function create( array $params, array $context ): array|\WP_Error {
		$path  = sanitize_text_field( $params['path'] ?? '' );
		$label = sanitize_text_field( $params['label'] ?? 'Manual snapshot' );

		if ( '' === $path ) {
			return new \WP_Error( 'wpcc_missing_snapshot_path', __( 'File path is required for snapshot creation.', 'wp-command-center' ) );
		}

		if ( '' === $label ) {
			$label = 'Manual snapshot';
		}

		$this->audit( 'snapshot.create.started', [ 'path' => $path, 'label' => $label ], $context );

		$result = $this->snapshots->create( $path, $label );

		if ( is_wp_error( $result ) ) {
			$this->audit( 'snapshot.create.failed', [ 'path' => $path, 'error' => $result->get_error_message() ], $context );
			return $result;
		}

		$this->audit( 'snapshot.create', [
			'snapshot_id' => $result['id'],
			'path'        => $result['path'],
			'label'       => $result['label'],
			'size'        => $result['size'],
		], $context );

		return [
			'action'      => 'snapshot_create',
			'snapshot_id' => $result['id'],
			'path'        => $result['path'],
			'label'       => $result['label'],
			'size'        => $result['size'],
			'hash'        => $result['hash'],
			'created_at'  => $result['created_at'],
		];
	}

	// ── List ──

	private function list_snapshots(): array {
		$this->audit( 'snapshot.list', [] );
		$all = $this->snapshots->list();
		return [
			'action' => 'snapshot_list',
			'count'  => count( $all ),
			'snapshots' => array_map( static fn( $s ) => [
				'snapshot_id' => $s['id'],
				'path'        => $s['path'],
				'label'       => $s['label'],
				'size'        => $s['size'],
				'hash'        => $s['hash'],
				'created_at'  => $s['created_at'],
			], $all ),
		];
	}

	// ── Details ──

	private function details( array $params ): array|\WP_Error {
		$id = sanitize_text_field( $params['snapshot_id'] ?? '' );
		if ( '' === $id ) {
			return new \WP_Error( 'wpcc_missing_snapshot_id', __( 'snapshot_id is required.', 'wp-command-center' ) );
		}

		$record = $this->snapshots->get( $id );
		if ( is_wp_error( $record ) ) {
			return $record;
		}

		// Verify integrity
		$contents = $this->snapshots->get_contents( $id );
		$valid = ! is_wp_error( $contents ) && md5( $contents ) === $record['hash'];

		return [
			'action'      => 'snapshot_details',
			'snapshot_id' => $record['id'],
			'path'        => $record['path'],
			'label'       => $record['label'],
			'size'        => $record['size'],
			'hash'        => $record['hash'],
			'created_at'  => $record['created_at'],
			'verified'    => $valid,
		];
	}

	// ── Restore (critical) ──

	private function restore( array $params, array $context ): array|\WP_Error {
		$id = sanitize_text_field( $params['snapshot_id'] ?? '' );
		if ( '' === $id ) {
			return new \WP_Error( 'wpcc_missing_snapshot_id', __( 'snapshot_id is required.', 'wp-command-center' ) );
		}

		$this->audit( 'snapshot.restore.started', [ 'snapshot_id' => $id ], $context );

		$result = $this->rollback->rollback( $id );

		if ( is_wp_error( $result ) ) {
			$this->audit( 'snapshot.restore.failed', [
				'snapshot_id' => $id,
				'error'       => $result->get_error_message(),
			], $context );
			return $result;
		}

		$this->audit( 'snapshot.restore.completed', [
			'snapshot_id' => $id,
			'path'        => $result['record']['path'] ?? '',
			'verified'    => $result['verified'],
		], $context );

		// Health check after restore
		$health = null;
		if ( $this->registry->requires_health_check( SnapshotRegistry::ACTION_RESTORE ) ) {
			try {
				$he    = new HealthVerificationEngine();
				$actor = $context['actor'] ?? [];
				$hres  = $he->verify( $actor );
				$health = is_wp_error( $hres ) ? [ 'status' => 'failed' ] : [ 'status' => $hres['status'] ?? 'unknown' ];
			} catch ( \Throwable $e ) {
				$health = [ 'status' => 'error' ];
			}
		}

		return [
			'action'       => 'snapshot_restore',
			'snapshot_id'  => $id,
			'path'         => $result['record']['path'] ?? '',
			'label'        => $result['record']['label'] ?? '',
			'restored'     => true,
			'verified'     => $result['verified'],
			'checks'       => $result['checks'] ?? [],
			'health_check' => $health['status'] ?? 'skipped',
		];
	}

	// ── Verify ──

	private function verify( array $params, array $context ): array|\WP_Error {
		$id = sanitize_text_field( $params['snapshot_id'] ?? '' );
		if ( '' === $id ) {
			return new \WP_Error( 'wpcc_missing_snapshot_id', __( 'snapshot_id is required.', 'wp-command-center' ) );
		}

		$record = $this->snapshots->get( $id );
		if ( is_wp_error( $record ) ) {
			return $record;
		}

		$contents = $this->snapshots->get_contents( $id );
		$hash_ok  = ! is_wp_error( $contents ) && md5( $contents ) === $record['hash'];

		$this->audit( 'snapshot.verify', [
			'snapshot_id' => $id,
			'valid'       => $hash_ok,
		], $context );

		return [
			'action'       => 'snapshot_verify',
			'snapshot_id'  => $id,
			'valid'        => $hash_ok,
			'hash_matches' => $hash_ok,
			'details'      => $hash_ok ? 'Snapshot is intact.' : ( is_wp_error( $contents ) ? 'Missing on disk.' : 'Hash mismatch — corrupted.' ),
		];
	}

	// ── Audit ──

	private function audit( string $event, array $data, array $context = [] ): void {
		$audit  = new AuditLog();
		$actor  = isset( $context['actor'] ) ? AuditLog::resolve_actor( $context['actor'] ) : null;
		$action = explode( '.', $event )[1] ?? '';
		$full   = 'snapshot_' . $action;
		$risk   = in_array( $full, SnapshotRegistry::ACTIONS, true ) ? $this->registry->action_risk( $full ) : SnapshotRegistry::RISK_MEDIUM;
		$audit->record( $event, array_merge( [ 'risk_level' => $risk, 'actor' => $actor ], $data ) );
	}

	public function get_registry(): SnapshotRegistry {
		return $this->registry;
	}
}
