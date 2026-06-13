<?php
/**
 * §8.5 Patch System (AI Coding Bridge) — human-in-the-loop
 * Approve / Reject / Apply / Roll Back workflow.
 *
 * Applying a patch automatically: 1) snapshots every affected file,
 * 2) writes the new contents, 3) runs a syntax verification pass, and
 * 4) rolls the files back to their pre-patch contents if verification
 * fails.
 *
 * Rolling back verifies that every restored file's hash matches the
 * snapshot it was restored from before the patch is marked rolled back.
 */

namespace WPCommandCenter\PatchSystem;

use WPCommandCenter\Rollback\RollbackManager;
use WPCommandCenter\Rollback\SnapshotManager;
use WPCommandCenter\Security\AuditLog;
use WPCommandCenter\Security\PathGuard;

defined( 'ABSPATH' ) || exit;

final class PatchApproval {

	private PatchManager $patches;
	private SnapshotManager $snapshots;
	private RollbackManager $rollback;
	private PathGuard $path_guard;
	private AuditLog $audit_log;

	public function __construct() {
		$this->patches    = new PatchManager();
		$this->snapshots  = new SnapshotManager();
		$this->rollback   = new RollbackManager();
		$this->path_guard = new PathGuard();
		$this->audit_log  = new AuditLog();
	}

	/**
	 * @param array<string, mixed> $actor Audit-log actor descriptor.
	 *
	 * @return array|\WP_Error Full updated patch record.
	 */
	public function approve( string $id, array $actor = [] ): array|\WP_Error {
		$patch = $this->patches->get( $id );

		if ( is_wp_error( $patch ) ) {
			return $patch;
		}

		if ( ! in_array( $patch['status'], [ PatchManager::STATUS_DRAFT, PatchManager::STATUS_PENDING_APPROVAL ], true ) ) {
			return new \WP_Error( 'wpcc_invalid_status', __( 'Only patches awaiting approval can be approved.', 'wp-command-center' ) );
		}

		$result = $this->patches->update_status( $id, PatchManager::STATUS_APPROVED );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->audit_log->record(
			'patch.approved',
			[
				'patch_id' => $id,
				'actor'    => AuditLog::resolve_actor( $actor ),
			]
		);

		return $result;
	}

	/**
	 * @param array<string, mixed> $actor Audit-log actor descriptor.
	 *
	 * @return array|\WP_Error Full updated patch record.
	 */
	public function reject( string $id, array $actor = [] ): array|\WP_Error {
		$patch = $this->patches->get( $id );

		if ( is_wp_error( $patch ) ) {
			return $patch;
		}

		if ( ! in_array( $patch['status'], [ PatchManager::STATUS_DRAFT, PatchManager::STATUS_PENDING_APPROVAL, PatchManager::STATUS_APPROVED ], true ) ) {
			return new \WP_Error( 'wpcc_invalid_status', __( 'This patch can no longer be rejected.', 'wp-command-center' ) );
		}

		$result = $this->patches->update_status( $id, PatchManager::STATUS_REJECTED );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->audit_log->record(
			'patch.rejected',
			[
				'patch_id' => $id,
				'actor'    => AuditLog::resolve_actor( $actor ),
			]
		);

		return $result;
	}

	/**
	 * Apply an approved patch: snapshot the affected files, write the new
	 * contents, verify, and roll back automatically if verification fails.
	 *
	 * @param array<string, mixed> $actor Audit-log actor descriptor.
	 *
	 * @return array|\WP_Error Full updated patch record (status reflects
	 *                          'applied' or 'failed'); WP_Error only for
	 *                          pre-flight failures where nothing was changed.
	 */
	public function apply( string $id, array $actor = [] ): array|\WP_Error {
		$patch = $this->patches->get( $id );

		if ( is_wp_error( $patch ) ) {
			return $patch;
		}

		if ( PatchManager::STATUS_APPROVED !== $patch['status'] ) {
			return new \WP_Error( 'wpcc_invalid_status', __( 'Only approved patches can be applied.', 'wp-command-center' ) );
		}

		// Pre-flight: resolve every target file and make sure none of them
		// have changed since the patch was generated.
		$targets = [];

		foreach ( $patch['files'] as $file ) {
			$real = $this->path_guard->resolve( $file['path'] );

			if ( is_wp_error( $real ) ) {
				return $real;
			}

			if ( ! is_writable( $real ) ) {
				return new \WP_Error( 'wpcc_not_writable', sprintf(
					/* translators: %s: file path */
					__( '%s is not writable.', 'wp-command-center' ),
					$file['path']
				) );
			}

			if ( file_get_contents( $real ) !== $file['original'] ) {
				return new \WP_Error( 'wpcc_file_changed', sprintf(
					/* translators: %s: file path */
					__( '%s has changed since this patch was generated. Regenerate the patch and try again.', 'wp-command-center' ),
					$file['path']
				) );
			}

			$targets[] = [
				'path' => $file['path'],
				'real' => $real,
				'file' => $file,
			];
		}

		// Snapshot every file before writing anything.
		$snapshot_ids = [];

		foreach ( $targets as $target ) {
			$snapshot = $this->snapshots->create(
				$target['path'],
				sprintf(
					/* translators: %s: patch ID */
					__( 'Before applying patch %s', 'wp-command-center' ),
					$id
				),
				$id
			);

			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}

			$snapshot_ids[ $target['path'] ] = $snapshot['id'];
		}

		// Write the new contents.
		foreach ( $targets as $target ) {
			if ( false === file_put_contents( $target['real'], $target['file']['modified'], LOCK_EX ) ) {
				return new \WP_Error( 'wpcc_write_failed', sprintf(
					/* translators: %s: file path */
					__( 'Failed to write %s.', 'wp-command-center' ),
					$target['path']
				) );
			}
		}

		// Verify: run a syntax check on every PHP file that changed.
		$checks = [];
		$passed = true;

		foreach ( $targets as $target ) {
			$check                          = $this->verify_file( $target['real'] );
			$checks[ $target['path'] ]      = $check;
			$passed                         = $passed && $check['passed'];
		}

		if ( ! $passed ) {
			// Restore every file from the snapshot just taken.
			foreach ( $targets as $target ) {
				file_put_contents( $target['real'], $target['file']['original'], LOCK_EX );
			}

			$result = $this->patches->update_status(
				$id,
				PatchManager::STATUS_FAILED,
				[
					'snapshot_ids' => $snapshot_ids,
					'verification' => [
						'passed' => false,
						'checks' => $checks,
					],
				]
			);

			$this->audit_log->record(
				'patch.failed',
				[
					'patch_id' => $id,
					'actor'    => AuditLog::resolve_actor( $actor ),
					'checks'   => $checks,
				]
			);

			return $result;
		}

		$result = $this->patches->update_status(
			$id,
			PatchManager::STATUS_APPLIED,
			[
				'applied_at'   => time(),
				'snapshot_ids' => $snapshot_ids,
				'verification' => [
					'passed' => true,
					'checks' => $checks,
				],
			]
		);

		$this->audit_log->record(
			'patch.applied',
			[
				'patch_id'     => $id,
				'actor'        => AuditLog::resolve_actor( $actor ),
				'snapshot_ids' => $snapshot_ids,
			]
		);

		return $result;
	}

	/**
	 * Roll back an applied patch by restoring every affected file from
	 * the snapshot taken before it was applied. Every restore is verified
	 * (snapshot integrity + post-write hash match); the patch is only
	 * marked 'rolled_back' if all files verify successfully. Files are
	 * restored on disk regardless, but if verification fails the status
	 * stays 'applied' pending investigation.
	 *
	 * @param array<string, mixed> $actor Audit-log actor descriptor.
	 *
	 * @return array|\WP_Error Full updated patch record.
	 */
	public function rollback( string $id, array $actor = [] ): array|\WP_Error {
		$patch = $this->patches->get( $id );

		if ( is_wp_error( $patch ) ) {
			return $patch;
		}

		if ( PatchManager::STATUS_APPLIED !== $patch['status'] ) {
			return new \WP_Error( 'wpcc_invalid_status', __( 'Only applied patches can be rolled back.', 'wp-command-center' ) );
		}

		if ( empty( $patch['snapshot_ids'] ) ) {
			return new \WP_Error( 'wpcc_no_snapshots', __( 'No snapshots are available for this patch.', 'wp-command-center' ) );
		}

		$results      = [];
		$all_verified = true;

		foreach ( $patch['snapshot_ids'] as $path => $snapshot_id ) {
			$result = $this->rollback->rollback( $snapshot_id );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$results[ $path ] = [
				'verified' => $result['verified'],
				'checks'   => $result['checks'],
			];

			$all_verified = $all_verified && $result['verified'];
		}

		if ( ! $all_verified ) {
			$this->audit_log->record(
				'patch.rollback_failed',
				[
					'patch_id' => $id,
					'actor'    => AuditLog::resolve_actor( $actor ),
					'results'  => $results,
				]
			);

			return new \WP_Error(
				'wpcc_rollback_verification_failed',
				__( 'One or more files could not be verified after rollback. The files have been restored, but the patch status was not changed — please investigate.', 'wp-command-center' ),
				[
					'status'           => 500,
					'rollback_results' => $results,
				]
			);
		}

		$result = $this->patches->update_status(
			$id,
			PatchManager::STATUS_ROLLED_BACK,
			[
				'rolled_back_at'   => time(),
				'rollback_results' => $results,
			]
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->audit_log->record(
			'patch.rolled_back',
			[
				'patch_id' => $id,
				'actor'    => AuditLog::resolve_actor( $actor ),
				'results'  => $results,
			]
		);

		return $result;
	}

	/**
	 * Validate the syntax of a PHP file. Prefers a real `php -l` via shell when
	 * available; otherwise falls back to a tokenizer/parser pass that needs no
	 * shell and still catches syntax errors (STEP 87). Public so the patch
	 * preview/verify operations can reuse the exact same validation.
	 *
	 * @return array{passed: bool, message: string, method: string}
	 */
	public function verify_file( string $real_path ): array {
		if ( 'php' !== strtolower( pathinfo( $real_path, PATHINFO_EXTENSION ) ) ) {
			return [
				'passed'  => true,
				'message' => __( 'Not a PHP file — syntax check skipped.', 'wp-command-center' ),
				'method'  => 'none',
			];
		}

		// Preferred: real `php -l` via shell.
		if ( $this->can_shell_exec() ) {
			foreach ( $this->lint_binaries() as $binary ) {
				$output = shell_exec( escapeshellarg( $binary ) . ' -l ' . escapeshellarg( $real_path ) . ' 2>&1' );

				if ( ! is_string( $output ) ) {
					continue;
				}

				if ( str_contains( $output, 'No syntax errors detected' ) ) {
					return [ 'passed' => true, 'message' => trim( $output ), 'method' => 'php -l' ];
				}

				if ( str_starts_with( trim( $output ), 'Usage:' ) ) {
					// This binary doesn't support -l (e.g. PHP_BINARY points at
					// php-fpm on FPM-based hosts); try the next candidate.
					continue;
				}

				return [ 'passed' => false, 'message' => trim( $output ), 'method' => 'php -l' ];
			}
		}

		// Fallback: tokenizer/parser validation (no shell required).
		return $this->tokenizer_check( (string) file_get_contents( $real_path ) );
	}

	/**
	 * Validate PHP source via the tokenizer using TOKEN_PARSE, which raises a
	 * ParseError on a syntax error. Works on hosts without shell access, so a
	 * broken patch is still blocked (STEP 87, safety requirement 2).
	 *
	 * @return array{passed: bool, message: string, method: string}
	 */
	public function tokenizer_check( string $code ): array {
		if ( ! function_exists( 'token_get_all' ) || ! defined( 'TOKEN_PARSE' ) ) {
			return [
				'passed'  => true,
				'message' => __( 'Syntax check skipped (tokenizer unavailable).', 'wp-command-center' ),
				'method'  => 'none',
			];
		}

		try {
			token_get_all( $code, TOKEN_PARSE );
			return [
				'passed'  => true,
				'message' => __( 'No syntax errors detected (tokenizer).', 'wp-command-center' ),
				'method'  => 'tokenizer',
			];
		} catch ( \ParseError $e ) {
			return [ 'passed' => false, 'message' => 'PHP parse error: ' . $e->getMessage(), 'method' => 'tokenizer' ];
		} catch ( \Throwable $e ) {
			return [ 'passed' => false, 'message' => 'PHP syntax error: ' . $e->getMessage(), 'method' => 'tokenizer' ];
		}
	}

	/**
	 * Candidate PHP CLI binaries to try for `-l` syntax checking, in order
	 * of preference. On FPM-based hosts PHP_BINARY points at php-fpm, which
	 * doesn't support -l (it prints a usage message), so a sibling CLI
	 * binary and the PATH-resolved `php` are tried as fallbacks.
	 *
	 * @return list<string>
	 */
	private function lint_binaries(): array {
		$binaries = [ PHP_BINARY ];
		$base     = basename( PHP_BINARY );

		if ( str_contains( $base, 'fpm' ) ) {
			$binaries[] = trailingslashit( dirname( PHP_BINARY ) ) . str_replace( '-fpm', '', $base );
			$binaries[] = 'php';
		}

		return $binaries;
	}

	private function can_shell_exec(): bool {
		if ( ! function_exists( 'shell_exec' ) || ! defined( 'PHP_BINARY' ) || '' === PHP_BINARY ) {
			return false;
		}

		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );

		return ! in_array( 'shell_exec', $disabled, true );
	}
}
