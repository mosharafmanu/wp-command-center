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

		file_put_contents( trailingslashit( $dir ) . self::LOG_FILE, $line . "\n", FILE_APPEND | LOCK_EX );
	}

	/**
	 * @return array<int, array{timestamp: int, action: string, context: array}> Last $limit entries, newest first.
	 */
	public function tail( int $limit = 200 ): array {
		$dir = $this->get_storage_dir();

		if ( is_wp_error( $dir ) ) {
			return [];
		}

		$file = trailingslashit( $dir ) . self::LOG_FILE;

		if ( ! is_readable( $file ) ) {
			return [];
		}

		$lines = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );

		if ( false === $lines ) {
			return [];
		}

		$lines   = array_slice( $lines, -max( 1, $limit ) );
		$entries = [];

		foreach ( array_reverse( $lines ) as $line ) {
			$decoded = json_decode( $line, true );

			if ( is_array( $decoded ) ) {
				$entries[] = $decoded;
			}
		}

		return $entries;
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
