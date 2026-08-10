<?php
/**
 * §10 Security Model — append-only activity/audit log for AI agent and
 * patch lifecycle operations. Stored as newline-delimited JSON (JSONL)
 * in the `wpcc-audit` private store (Security\PrivateStore), which is not
 * web-retrievable on any server.
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

		/**
		 * PROGRAM-8 — behavior-neutral observation point. Fired AFTER the audit
		 * record is durably written, so the audit's behavior is unchanged. Telemetry
		 * (and any read-only observer) may subscribe; subscribers MUST self-guard and
		 * MUST NOT affect execution. No subscriber is required.
		 *
		 * @param string $action    The audit action.
		 * @param array  $context   The audit context (already secret-redacted by callers).
		 * @param int    $timestamp Unix time of the record.
		 */
		do_action( 'wpcc_audit_recorded', $action, $context, time() );
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

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- append-only audit log opened for locking (flock). WP_Filesystem has no locking primitive, so concurrent requests could interleave writes.
		$handle = fopen( $file, 'c' );

		if ( false === $handle ) {
			return;
		}

		// Coordinate with concurrent record() appends (advisory lock on the
		// same inode). Blocks until the in-flight append releases LOCK_EX.
		if ( ! flock( $handle, LOCK_EX ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- paired with the streamed fopen above.
			fclose( $handle );
			return;
		}

		// Re-check under the lock — another process may have rotated already.
		clearstatcache( true, $file );

		if ( is_file( $file ) && filesize( $file ) >= self::ROTATE_BYTES ) {
			$target = trailingslashit( $dir ) . 'audit-' . gmdate( 'Ymd-His' ) . '-' . substr( md5( uniqid( '', true ) ), 0, 6 ) . '.log';

			if ( ! file_exists( $target ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic temp-then-replace. WP_Filesystem::move() gives no atomicity guarantee and is not atomic at all over its FTP/SSH transports; snapshot and audit integrity depend on a reader seeing either the whole old file or the whole new one.
				@rename( $file, $target );
			}
		}

		flock( $handle, LOCK_UN );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- paired with the streamed fopen above.
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
			wp_delete_file( $stale );
		}
	}

	/**
	 * Build an actor descriptor for an audit entry. If `$actor` is empty,
	 * fall back to the currently logged-in admin user (or 'unknown').
	 *
	 * The parameter is deliberately untyped. This is a public entry point that
	 * several callers reach with a caller-supplied `$context['actor']` they do not
	 * validate — OperationQueue::enqueue() forwards it straight here. When that
	 * value arrived as a scalar the TypeError was fatal, and because
	 * OperationManager::approve_request() marks the request APPROVED before it
	 * enqueues, the throw left the request approved, never queued, and with no
	 * audit entry: a silently stuck approval and a missing audit record. Audit
	 * must never be the reason a governed change is lost, so a malformed actor is
	 * normalised into an honest descriptor instead of aborting the write.
	 *
	 * Well-formed array actors are returned exactly as before.
	 *
	 * @param mixed $actor Array descriptor, or a scalar label from a lax caller.
	 * @return array<string, mixed>
	 */
	public static function resolve_actor( $actor ): array {
		if ( is_array( $actor ) && ! empty( $actor ) ) {
			return $actor;
		}

		// A scalar is recorded as an unverified caller-supplied label. It is
		// deliberately NOT promoted to a trusted type — nothing authenticated it.
		if ( is_scalar( $actor ) && '' !== (string) $actor ) {
			return [
				'type'  => 'unknown',
				'label' => sanitize_text_field( (string) $actor ),
			];
		}

		$user_id = get_current_user_id();

		return $user_id
			? [ 'type' => 'admin', 'user_id' => $user_id ]
			: [ 'type' => 'unknown' ];
	}

	/**
	 * STEP 105.5 — a descriptive actor for non-interactive executions (cron,
	 * queue worker, workflow, headless request) where there is no human/token
	 * actor. Carries a human label so the Change History UI renders e.g.
	 * "System (Cron)" without any UI change. This is attribution metadata only.
	 *
	 * @param string $via One of: cron|queue|workflow|request (others accepted).
	 * @return array{type:string, via:string, label:string}
	 */
	public static function system_actor( string $via = 'system' ): array {
		$labels = [
			'cron'     => 'System (Cron)',
			'queue'    => 'System (Queue)',
			'workflow' => 'System (Workflow)',
			'request'  => 'System (Headless Request)',
		];
		$via   = '' !== $via ? $via : 'system';
		$label = $labels[ $via ] ?? ( 'system' === $via ? 'System' : 'System (' . ucfirst( $via ) . ')' );

		return [ 'type' => 'system', 'via' => $via, 'label' => $label ];
	}

	/**
	 * Absolute path of the audit log directory, creating it (and its
	 * protective files) on first use.
	 */
	private function get_storage_dir(): string|\WP_Error {
		return PrivateStore::dir(
			self::DIR_NAME,
			__( 'Failed to create the audit log directory.', 'ai-command-center' )
		);
	}

}
