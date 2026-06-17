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

			// Reject before writing anything if this would strip/corrupt a plugin
			// or theme bootstrap header (defends against patches created before
			// this rule existed, too).
			$header_error = PatchGuard::validate_change( $file['path'], $file['original'], $file['modified'] );
			if ( is_wp_error( $header_error ) ) {
				$this->audit_log->record( 'patch.header_blocked', [
					'patch_id' => $id,
					'path'     => $file['path'],
					'actor'    => AuditLog::resolve_actor( $actor ),
				] );
				return $header_error;
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

		// Write the new contents. The change set is transactional: if any write
		// fails, restore every file (including the ones already written) to its
		// pre-apply content so no file is left partially changed, then fail.
		foreach ( $targets as $i => $target ) {
			if ( false === file_put_contents( $target['real'], $target['file']['modified'], LOCK_EX ) ) {
				$restore = $this->restore_targets( $targets );

				$result = $this->patches->update_status(
					$id,
					PatchManager::STATUS_FAILED,
					[
						'snapshot_ids' => $snapshot_ids,
						'verification' => [
							'passed'      => false,
							'reason'      => 'write_failed',
							'failed_path' => $target['path'],
							'restored'    => $this->all_restored( $restore ),
							'restore'     => $restore,
						],
					]
				);

				$this->audit_log->record(
					'patch.transactional_failed',
					[
						'patch_id'    => $id,
						'reason'      => 'write_failed',
						'failed_path' => $target['path'],
						'written'     => $i, // files written before the failure, now restored
						'restore'     => $restore,
						'actor'       => AuditLog::resolve_actor( $actor ),
					]
				);

				return $result;
			}
		}

		// Verify: run a syntax check on every PHP file that changed.
		$checks = [];
		$passed = true;

		foreach ( $targets as $target ) {
			$check = $this->verify_file( $target['real'] );

			// Post-write header verification for plugin/theme bootstrap files: if
			// the written file lost its header, fail so the auto-revert below
			// restores it (the site never sees an invalid-header plugin/theme).
			$header = PatchGuard::verify_written_file( $target['real'], $target['path'], $target['file']['original'] );
			if ( $header['guarded'] ) {
				$check['header'] = $header;
				$check['passed'] = $check['passed'] && $header['passed'];
			}

			$checks[ $target['path'] ] = $check;
			$passed                    = $passed && $check['passed'];
		}

		if ( ! $passed ) {
			// Transactional restore: revert EVERY file to its pre-apply content so a
			// verification failure on one file leaves no file changed.
			$restore = $this->restore_targets( $targets );

			$result = $this->patches->update_status(
				$id,
				PatchManager::STATUS_FAILED,
				[
					'snapshot_ids' => $snapshot_ids,
					'verification' => [
						'passed'   => false,
						'reason'   => 'verification_failed',
						'checks'   => $checks,
						'restored' => $this->all_restored( $restore ),
						'restore'  => $restore,
					],
				]
			);

			$this->audit_log->record(
				'patch.transactional_failed',
				[
					'patch_id' => $id,
					'reason'   => 'verification_failed',
					'actor'    => AuditLog::resolve_actor( $actor ),
					'checks'   => $checks,
					'restore'  => $restore,
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
	 * Restore every target file to its pre-apply ('original') content and verify
	 * the on-disk bytes match. Used by the transactional apply path so a failure
	 * anywhere leaves no file partially changed.
	 *
	 * @param array<int,array{path:string,real:string,file:array}> $targets
	 *
	 * @return array<string,array{restored:bool}> Per-path restore verification.
	 */
	private function restore_targets( array $targets ): array {
		$report = [];

		foreach ( $targets as $target ) {
			$written = false !== file_put_contents( $target['real'], $target['file']['original'], LOCK_EX );
			$matches = $written && file_get_contents( $target['real'] ) === $target['file']['original'];

			$report[ $target['path'] ] = [ 'restored' => $matches ];
		}

		return $report;
	}

	/**
	 * @param array<string,array{restored:bool}> $restore
	 */
	private function all_restored( array $restore ): bool {
		foreach ( $restore as $r ) {
			if ( empty( $r['restored'] ) ) {
				return false;
			}
		}

		return true;
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
				'code'    => 'ok',
				'reason'  => 'none',
				'binary'  => null,
			];
		}

		// Preferred: real `php -l` via a validated, timeout-bounded PHP CLI.
		$lint = PhpBinary::lint( $real_path );

		if ( $lint['ran'] ) {
			if ( $lint['passed'] ) {
				return [
					'passed'  => true,
					'message' => trim( $lint['output'] ) ?: __( 'No syntax errors detected.', 'wp-command-center' ),
					'method'  => 'php -l',
					'code'    => 'ok',
					'reason'  => 'none',
					'binary'  => $lint['binary'],
				];
			}
			// php -l found a REAL syntax error → block (safety guarantee).
			return [
				'passed'  => false,
				'message' => trim( $lint['output'] ),
				'method'  => 'php -l',
				'code'    => 'syntax_error',
				'reason'  => 'none',
				'binary'  => $lint['binary'],
			];
		}

		// php -l could NOT run (binary missing/not-executable/timeout). This is a
		// TOOLING failure, NOT a syntax failure — fall back to the tokenizer, which
		// still catches real syntax errors without a shell. The tooling reason is
		// surfaced so callers/agents see why php -l was skipped.
		$tok = $this->tokenizer_check( (string) file_get_contents( $real_path ) );

		if ( ! $tok['passed'] ) {
			// Tokenizer found a real syntax error → still block.
			return [
				'passed'  => false,
				'message' => $tok['message'],
				'method'  => 'tokenizer',
				'code'    => 'syntax_error',
				'reason'  => $lint['reason'],
				'binary'  => null,
			];
		}

		return [
			'passed'  => true,
			'message' => $tok['message'],
			'method'  => $tok['method'], // 'tokenizer' or 'none' (tokenizer unavailable)
			'code'    => 'tokenizer_fallback_used',
			'reason'  => $lint['reason'], // php_cli_not_found | php_cli_not_executable | verification_timeout
			'binary'  => null,
		];
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
	 * STEP 105.6 — aggregate per-file verification checks into one machine-readable
	 * summary for the agent-facing response: the dominant method, the worst
	 * outcome code, whether a tokenizer fallback was used (and why), and a
	 * human warning when verification was best-effort.
	 *
	 * @param array<string,array<string,mixed>> $checks path => verify_file() result
	 * @return array{passed:bool,method:string,code:string,reason:string,tokenizer_fallback_used:bool,warning:?string}
	 */
	public static function summarize_verification( array $checks ): array {
		$methods  = [];
		$reason   = 'none';
		$fallback = false;
		$passed   = true;
		$code     = 'ok';

		foreach ( $checks as $c ) {
			$passed     = $passed && ! empty( $c['passed'] );
			$methods[]  = (string) ( $c['method'] ?? 'none' );
			$c_code     = (string) ( $c['code'] ?? 'ok' );
			$c_reason   = (string) ( $c['reason'] ?? 'none' );

			if ( 'syntax_error' === $c_code ) {
				$code = 'syntax_error';
			} elseif ( 'tokenizer_fallback_used' === $c_code && 'syntax_error' !== $code ) {
				$code     = 'tokenizer_fallback_used';
				$fallback = true;
				if ( 'none' === $reason && 'none' !== $c_reason ) {
					$reason = $c_reason;
				}
			}
		}

		$methods = array_values( array_unique( array_filter( $methods, static fn( $m ) => 'none' !== $m ) ) );
		$method  = count( $methods ) > 1 ? 'mixed' : ( $methods[0] ?? 'none' );

		$warning = null;
		if ( $fallback ) {
			$why = [
				'php_cli_not_found'      => __( 'no PHP CLI binary was found', 'wp-command-center' ),
				'php_cli_not_executable' => __( 'the configured PHP binary is not an executable CLI', 'wp-command-center' ),
				'verification_timeout'   => __( 'php -l exceeded the time budget', 'wp-command-center' ),
			];
			$warning = sprintf(
				/* translators: %s: reason php -l was unavailable */
				__( 'Syntax verified with the tokenizer fallback because %s. Set the WPCC_PHP_BINARY constant/option to a PHP CLI path for full `php -l` verification.', 'wp-command-center' ),
				$why[ $reason ] ?? __( 'php -l was unavailable', 'wp-command-center' )
			);
		}

		return [
			'passed'                  => $passed,
			'method'                  => $method,
			'code'                    => $code,
			'reason'                  => $reason,
			'tokenizer_fallback_used' => $fallback,
			'warning'                 => $warning,
		];
	}
}
