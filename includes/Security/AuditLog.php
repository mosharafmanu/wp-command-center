<?php
/**
 * §10 Security Model — append-only activity/audit log for AI agent and
 * patch lifecycle operations. Stored as newline-delimited JSON (JSONL)
 * under wp-content/uploads/wpcc-audit/, protected from direct web access.
 */

namespace WPCommandCenter\Security;

defined( 'ABSPATH' ) || exit;

final class AuditLog {

	private const DIR_NAME = 'wpcc-audit';
	private const LOG_FILE = 'audit.log';

	/**
	 * STEP 104.0 rotation policy. The active log is rotated to
	 * `audit-<ts>.log` once it reaches ROTATE_BYTES; at most MAX_SEGMENTS
	 * rotated files are retained (older segments are pruned). With the
	 * active file this bounds on-disk audit history at ~(N+1)·cap.
	 */
	private const ROTATE_BYTES  = 52428800; // 50 MB.
	private const MAX_SEGMENTS  = 5;

	/**
	 * Append an entry to the audit log. Failures (e.g. unwritable
	 * directory) are silently ignored — auditing must never break the
	 * operation it's recording.
	 *
	 * @param array<string, mixed> $context
	 */
	public function record( string $action, array $context = [] ): void {
		$dir = $this->get_storage_dir();

		if ( is_wp_error( $dir ) ) {
			return;
		}

		$line = wp_json_encode( [
			'timestamp' => time(),
			'action'    => $action,
			'context'   => $context,
		] );

		if ( false === $line ) {
			return;
		}

		// Rotate before appending so each segment stays bounded near the cap.
		$this->maybe_rotate( $dir );

		file_put_contents( trailingslashit( $dir ) . self::LOG_FILE, $line . "\n", FILE_APPEND | LOCK_EX );
	}

	/**
	 * Last $limit entries, newest first. Rotation-aware: reads the active
	 * log first, then rotated segments newest→oldest, until $limit valid
	 * entries are collected. This preserves the pre-rotation contract — a
	 * rotation never shrinks what callers see.
	 *
	 * @return array<int, array{timestamp: int, action: string, context: array}>
	 */
	public function tail( int $limit = 200 ): array {
		$dir = $this->get_storage_dir();

		if ( is_wp_error( $dir ) ) {
			return [];
		}

		$limit   = max( 1, $limit );
		$entries = [];

		foreach ( $this->log_segments( $dir ) as $file ) {
			if ( count( $entries ) >= $limit ) {
				break;
			}

			if ( ! is_readable( $file ) ) {
				continue;
			}

			$lines = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );

			if ( false === $lines ) {
				continue;
			}

			foreach ( array_reverse( $lines ) as $line ) {
				$decoded = json_decode( $line, true );

				if ( is_array( $decoded ) ) {
					$entries[] = $decoded;

					if ( count( $entries ) >= $limit ) {
						break;
					}
				}
			}
		}

		return $entries;
	}

	/**
	 * Log files in newest→oldest order: the active log, then rotated
	 * `audit-<ts>.log` segments by descending (timestamped) name. The
	 * zero-padded `audit-Ymd-His-*` names sort lexically == chronologically.
	 *
	 * @return array<int, string> Absolute paths.
	 */
	private function log_segments( string $dir ): array {
		$dir      = trailingslashit( $dir );
		$segments = glob( $dir . 'audit-*.log' );

		if ( ! is_array( $segments ) ) {
			$segments = [];
		}

		rsort( $segments, SORT_STRING );
		array_unshift( $segments, $dir . self::LOG_FILE );

		return $segments;
	}

	/**
	 * Rotate the active log to `audit-<ts>.log` when it reaches the size
	 * cap. Uses the same LOCK_EX discipline as record()'s append and is
	 * idempotent: the size is re-checked under the lock, so if a concurrent
	 * process already rotated this is a no-op. Best-effort — any failure is
	 * swallowed so auditing never breaks the recorded operation.
	 */
	private function maybe_rotate( string $dir ): void {
		$file = trailingslashit( $dir ) . self::LOG_FILE;

		clearstatcache( true, $file );

		if ( ! is_file( $file ) || filesize( $file ) < self::ROTATE_BYTES ) {
			return;
		}

		$handle = fopen( $file, 'c' );

		if ( false === $handle ) {
			return;
		}

		// Coordinate with concurrent record() appends (advisory lock on the
		// same inode). Blocks until the in-flight append releases LOCK_EX.
		if ( ! flock( $handle, LOCK_EX ) ) {
			fclose( $handle );
			return;
		}

		// Re-check under the lock — another process may have rotated already.
		clearstatcache( true, $file );

		if ( is_file( $file ) && filesize( $file ) >= self::ROTATE_BYTES ) {
			$target = trailingslashit( $dir ) . 'audit-' . gmdate( 'Ymd-His' ) . '-' . substr( md5( uniqid( '', true ) ), 0, 6 ) . '.log';

			if ( ! file_exists( $target ) ) {
				@rename( $file, $target );
			}
		}

		flock( $handle, LOCK_UN );
		fclose( $handle );

		$this->prune_segments( $dir );
	}

	/**
	 * Keep only the newest MAX_SEGMENTS rotated segments; delete the rest.
	 */
	private function prune_segments( string $dir ): void {
		$segments = glob( trailingslashit( $dir ) . 'audit-*.log' );

		if ( ! is_array( $segments ) || count( $segments ) <= self::MAX_SEGMENTS ) {
			return;
		}

		rsort( $segments, SORT_STRING ); // Newest first.

		foreach ( array_slice( $segments, self::MAX_SEGMENTS ) as $stale ) {
			@unlink( $stale );
		}
	}

	/**
	 * Build an actor descriptor for an audit entry. If `$actor` is empty,
	 * fall back to the currently logged-in admin user (or 'unknown').
	 *
	 * @param array<string, mixed> $actor
	 * @return array<string, mixed>
	 */
	public static function resolve_actor( array $actor ): array {
		if ( ! empty( $actor ) ) {
			return $actor;
		}

		$user_id = get_current_user_id();

		return $user_id
			? [ 'type' => 'admin', 'user_id' => $user_id ]
			: [ 'type' => 'unknown' ];
	}

	/**
	 * Absolute path of the audit log directory, creating it (and its
	 * protective files) on first use.
	 */
	private function get_storage_dir(): string|\WP_Error {
		$upload_dir = wp_upload_dir();

		if ( ! empty( $upload_dir['error'] ) ) {
			return new \WP_Error( 'wpcc_upload_dir_error', $upload_dir['error'] );
		}

		$dir = trailingslashit( $upload_dir['basedir'] ) . self::DIR_NAME;

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'wpcc_mkdir_failed', __( 'Failed to create the audit log directory.', 'wp-command-center' ) );
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
}
