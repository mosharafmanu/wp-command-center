<?php
/**
 * §8.6 Rollback Engine — file snapshot taken before every patch.
 * Only the affected file(s) are backed up. The `.snapshot` content files
 * remain on disk under wp-content/uploads/wpcc-snapshots/, protected from
 * direct web access; the {$wpdb->prefix}wpcc_snapshots table is a
 * queryable index/metadata mirror used for listing and lookups.
 */

namespace WPCommandCenter\Rollback;

use WPCommandCenter\Security\PathGuard;

defined( 'ABSPATH' ) || exit;

final class SnapshotManager {

	private const DIR_NAME = 'wpcc-snapshots';

	/** STEP 105.6 — default large-file cap (bytes) and read time budget (seconds). */
	private const DEFAULT_MAX_BYTES = 10485760; // 10 MB
	private const DEFAULT_TIMEOUT   = 10;

	private PathGuard $path_guard;

	public function __construct() {
		$this->path_guard = new PathGuard();
	}

	/** Configurable max snapshot size in bytes (WPCC_SNAPSHOT_MAX_BYTES const/option). */
	private static function max_bytes(): int {
		if ( defined( 'WPCC_SNAPSHOT_MAX_BYTES' ) && (int) WPCC_SNAPSHOT_MAX_BYTES > 0 ) {
			return (int) WPCC_SNAPSHOT_MAX_BYTES;
		}
		$opt = (int) get_option( 'wpcc_snapshot_max_bytes', 0 );
		return $opt > 0 ? $opt : self::DEFAULT_MAX_BYTES;
	}

	/** Configurable snapshot read time budget in seconds. */
	private static function timeout(): int {
		if ( defined( 'WPCC_SNAPSHOT_TIMEOUT' ) && (int) WPCC_SNAPSHOT_TIMEOUT > 0 ) {
			return (int) WPCC_SNAPSHOT_TIMEOUT;
		}
		$opt = (int) get_option( 'wpcc_snapshot_timeout', 0 );
		return $opt > 0 ? $opt : self::DEFAULT_TIMEOUT;
	}

	/**
	 * Snapshot the current contents of an allowed file.
	 *
	 * @return array{id: string, patch_id: ?string, path: string, label: string, created_at: int, size: int, hash: string}|\WP_Error
	 */
	public function create( string $relative_path, string $label = '', ?string $patch_id = null ): array|\WP_Error {
		$real = $this->path_guard->resolve( $relative_path );

		if ( is_wp_error( $real ) ) {
			return $real;
		}

		if ( ! is_file( $real ) || ! is_readable( $real ) ) {
			return new \WP_Error( 'wpcc_not_readable', __( 'File not found or not readable.', 'wp-command-center' ) );
		}

		// STEP 105.6 — large-file protection: cap the snapshot size up front so a
		// huge file can never stall the read or exhaust memory. Patch targets are
		// source files; a multi-MB cap is generous. Returns a clear, classified
		// error instead of hanging.
		$max   = self::max_bytes();
		$fsize = (int) @filesize( $real );
		if ( $fsize > $max ) {
			return new \WP_Error(
				'wpcc_snapshot_too_large',
				sprintf(
					/* translators: 1: file size, 2: limit */
					__( 'File is too large to snapshot (%1$d bytes > %2$d limit). Set WPCC_SNAPSHOT_MAX_BYTES to raise the cap.', 'wp-command-center' ),
					$fsize,
					$max
				)
			);
		}

		// Bounded read: guard wall-clock so a slow/locked filesystem surfaces a
		// classified timeout rather than an unbounded hang.
		$deadline = microtime( true ) + self::timeout();
		$contents = file_get_contents( $real );

		if ( false === $contents ) {
			return new \WP_Error( 'wpcc_read_failed', __( 'Failed to read the file.', 'wp-command-center' ) );
		}
		if ( microtime( true ) > $deadline ) {
			return new \WP_Error( 'wpcc_snapshot_timeout', __( 'Snapshot read exceeded the time budget.', 'wp-command-center' ) );
		}

		$dir = $this->get_storage_dir();

		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$id          = wp_generate_uuid4();
		$backup_path = $id . '.snapshot';
		$dest        = trailingslashit( $dir ) . $backup_path;

		// STEP 105.6 — NON-BLOCKING write: write to a unique temp file then
		// atomically rename into place. This avoids the previous blocking LOCK_EX,
		// which could wait minutes on a contended/NFS lock. The temp name is
		// unique per snapshot (UUID), so there is no lock to contend for, and
		// rename() is atomic on the same filesystem.
		$tmp = $dest . '.' . wp_generate_password( 8, false ) . '.tmp';
		if ( false === file_put_contents( $tmp, $contents ) ) {
			return new \WP_Error( 'wpcc_write_failed', __( 'Failed to store the snapshot.', 'wp-command-center' ) );
		}
		if ( ! @rename( $tmp, $dest ) ) {
			@unlink( $tmp );
			return new \WP_Error( 'wpcc_write_failed', __( 'Failed to finalize the snapshot.', 'wp-command-center' ) );
		}

		$record = [
			'id'         => $id,
			'patch_id'   => $patch_id,
			'path'       => $this->to_relative_path( $real ),
			'label'      => sanitize_text_field( $label ),
			'created_at' => time(),
			'size'       => strlen( $contents ),
			'hash'       => md5( $contents ),
		];

		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'wpcc_snapshots',
			[
				'snapshot_id' => $record['id'],
				'patch_id'    => $record['patch_id'],
				'file_path'   => $record['path'],
				'backup_path' => $backup_path,
				'label'       => $record['label'],
				'size'        => $record['size'],
				'hash'        => $record['hash'],
				'created_at'  => $record['created_at'],
			],
			[ '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d' ]
		);

		return $record;
	}

	/**
	 * @return array<int, array{id: string, patch_id: ?string, path: string, label: string, created_at: int, size: int, hash: string}>
	 */
	public function list( ?string $relative_path = null ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'wpcc_snapshots';

		if ( null !== $relative_path ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE file_path = %s ORDER BY created_at DESC", $relative_path ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC", ARRAY_A );
		}

		if ( ! is_array( $rows ) ) {
			return [];
		}

		return array_map( [ $this, 'row_to_record' ], $rows );
	}

	/**
	 * @return array{id: string, patch_id: ?string, path: string, label: string, created_at: int, size: int, hash: string}|\WP_Error
	 */
	public function get( string $id ): array|\WP_Error {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcc_snapshots WHERE snapshot_id = %s", $id ), ARRAY_A );

		if ( ! is_array( $row ) ) {
			return new \WP_Error( 'wpcc_snapshot_not_found', __( 'Snapshot not found.', 'wp-command-center' ) );
		}

		return $this->row_to_record( $row );
	}

	public function get_contents( string $id ): string|\WP_Error {
		$record = $this->get( $id );

		if ( is_wp_error( $record ) ) {
			return $record;
		}

		$dir  = $this->get_storage_dir();

		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$file = trailingslashit( $dir ) . $id . '.snapshot';

		if ( ! is_readable( $file ) ) {
			return new \WP_Error( 'wpcc_snapshot_missing', __( 'Snapshot file is missing on disk.', 'wp-command-center' ) );
		}

		return (string) file_get_contents( $file );
	}

	public function delete( string $id ): bool|\WP_Error {
		$record = $this->get( $id );

		if ( is_wp_error( $record ) ) {
			return $record;
		}

		$dir = $this->get_storage_dir();

		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$file = trailingslashit( $dir ) . $id . '.snapshot';

		if ( file_exists( $file ) ) {
			wp_delete_file( $file );
		}

		global $wpdb;

		$wpdb->delete( $wpdb->prefix . 'wpcc_snapshots', [ 'snapshot_id' => $id ] );

		return true;
	}

	/**
	 * @param array<string, mixed> $row
	 *
	 * @return array{id: string, patch_id: ?string, path: string, label: string, created_at: int, size: int, hash: string}
	 */
	private function row_to_record( array $row ): array {
		return [
			'id'         => $row['snapshot_id'],
			'patch_id'   => $row['patch_id'],
			'path'       => $row['file_path'],
			'label'      => $row['label'],
			'created_at' => (int) $row['created_at'],
			'size'       => (int) $row['size'],
			'hash'       => $row['hash'],
		];
	}

	/**
	 * Absolute path of the snapshot storage directory, creating it (and
	 * its protective files) on first use.
	 */
	private function get_storage_dir(): string|\WP_Error {
		$upload_dir = wp_upload_dir();

		if ( ! empty( $upload_dir['error'] ) ) {
			return new \WP_Error( 'wpcc_upload_dir_error', $upload_dir['error'] );
		}

		$dir = trailingslashit( $upload_dir['basedir'] ) . self::DIR_NAME;

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'wpcc_mkdir_failed', __( 'Failed to create the snapshot storage directory.', 'wp-command-center' ) );
		}

		$this->protect_directory( $dir );

		return $dir;
	}

	private function protect_directory( string $dir ): void {
		$htaccess = trailingslashit( $dir ) . '.htaccess';

		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Require all denied\nDeny from all\n" );
		}

		$index = trailingslashit( $dir ) . 'index.php';

		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}

	private function to_relative_path( string $real_path ): string {
		return ltrim( str_replace( trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ), '', $real_path ), '/' );
	}
}
