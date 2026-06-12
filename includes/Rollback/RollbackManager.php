<?php
/**
 * §8.6 Rollback Engine — restores a file's contents from a snapshot.
 */

namespace WPCommandCenter\Rollback;

use WPCommandCenter\Security\PathGuard;

defined( 'ABSPATH' ) || exit;

final class RollbackManager {

	private SnapshotManager $snapshots;
	private PathGuard $path_guard;

	public function __construct() {
		$this->snapshots  = new SnapshotManager();
		$this->path_guard = new PathGuard();
	}

	/**
	 * Restore a file to the contents captured in a snapshot. A safety
	 * snapshot of the file's current contents is taken first, so the
	 * restore itself can be undone.
	 *
	 * Verification is two-fold: a pre-check confirms the stored snapshot
	 * content still matches its recorded hash (catches a corrupted
	 * backup before it overwrites the live file), and a post-check
	 * confirms the live file's hash matches the snapshot's hash after
	 * the restore is written.
	 *
	 * @return array{record: array, safety_snapshot: ?array, verified: bool, checks: array{snapshot_hash_valid: bool, restored_hash_matches: bool}}|\WP_Error
	 */
	public function rollback( string $snapshot_id ): array|\WP_Error {
		$record = $this->snapshots->get( $snapshot_id );

		if ( is_wp_error( $record ) ) {
			return $record;
		}

		$real = $this->path_guard->resolve( $record['path'] );

		if ( is_wp_error( $real ) ) {
			return $real;
		}

		if ( ! is_writable( $real ) ) {
			return new \WP_Error( 'wpcc_not_writable', __( 'The target file is not writable.', 'wp-command-center' ) );
		}

		$contents = $this->snapshots->get_contents( $snapshot_id );

		if ( is_wp_error( $contents ) ) {
			return $contents;
		}

		$checks = [
			'snapshot_hash_valid' => ( md5( $contents ) === $record['hash'] ),
		];

		$safety_snapshot = $this->snapshots->create(
			$record['path'],
			sprintf(
				/* translators: %s: date and time of the snapshot being restored */
				__( 'Automatic backup before restoring snapshot from %s', 'wp-command-center' ),
				wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $record['created_at'] )
			)
		);

		if ( false === file_put_contents( $real, $contents, LOCK_EX ) ) {
			return new \WP_Error( 'wpcc_restore_failed', __( 'Failed to write the restored file.', 'wp-command-center' ) );
		}

		clearstatcache( true, $real );

		$restored = file_get_contents( $real );

		$checks['restored_hash_matches'] = ( false !== $restored && md5( $restored ) === $record['hash'] );

		return [
			'record'          => $record,
			'safety_snapshot' => is_wp_error( $safety_snapshot ) ? null : $safety_snapshot,
			'verified'        => $checks['snapshot_hash_valid'] && $checks['restored_hash_matches'],
			'checks'          => $checks,
		];
	}
}
