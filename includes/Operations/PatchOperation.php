<?php
/**
 * STEP 87 — patch_manage operation handler.
 *
 * Bridges the existing Patch Engine (PatchManager + PatchApproval) to the
 * Operations framework so REST (/operations/patch_manage/run) and MCP
 * (patch_manage tool) drive the exact same engine. No patch/snapshot logic is
 * duplicated here.
 *
 * Actions:
 *   - patch_preview : compute diff + syntax check, persist nothing       (read)
 *   - patch_create  : create a pending patch proposal                    (write-proposal)
 *   - patch_apply   : approve (if needed) + apply, snapshot + verify      (file write)
 *   - patch_verify  : re-check syntax/integrity of a patch's files        (read)
 *   - patch_status  : fetch a patch record                               (read)
 *
 * Apply preserves every safety guarantee of PatchApproval::apply():
 * snapshot-before-write, PHP syntax verification (shell php -l or tokenizer
 * fallback), and automatic revert on verification failure. The Security Mode
 * approval gate (handled upstream in OperationExecutor) is the human approval
 * layer for client/enterprise modes; dangerous files require explicit
 * confirmation via DestructiveGuard in every mode.
 */

namespace WPCommandCenter\Operations;

use WPCommandCenter\PatchSystem\PatchManager;
use WPCommandCenter\PatchSystem\PatchApproval;
use WPCommandCenter\PatchSystem\DiffGenerator;
use WPCommandCenter\PatchSystem\DangerousFiles;
use WPCommandCenter\Security\AuditLog;
use WPCommandCenter\Security\PathGuard;

defined( 'ABSPATH' ) || exit;

final class PatchOperation {

	const ACTIONS = [ 'patch_preview', 'patch_create', 'patch_apply', 'patch_verify', 'patch_status' ];

	public function run( array $params, array $context = [] ): array|\WP_Error {
		$action = sanitize_key( $params['action'] ?? '' );

		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			return new \WP_Error( 'wpcc_invalid_patch_action', sprintf( __( 'Invalid action: %s. Use patch_preview, patch_create, patch_apply, patch_verify, or patch_status.', 'wp-command-center' ), esc_html( $action ) ) );
		}

		return match ( $action ) {
			'patch_preview' => $this->preview( $params, $context ),
			'patch_create'  => $this->create( $params, $context ),
			'patch_apply'   => $this->apply( $params, $context ),
			'patch_verify'  => $this->verify( $params, $context ),
			'patch_status'  => $this->status( $params ),
		};
	}

	// ── Preview (no persistence, no disk write) ──────────────────

	private function preview( array $params, array $context ): array|\WP_Error {
		$files = $this->normalize_files( $params['files'] ?? [] );

		if ( is_wp_error( $files ) ) {
			return $files;
		}

		$guard     = new PathGuard();
		$differ    = new DiffGenerator();
		$approval  = new PatchApproval();
		$previews  = [];
		$all_valid = true;
		$dangerous = [];

		foreach ( $files as $file ) {
			$real = $guard->resolve( $file['path'] );
			if ( is_wp_error( $real ) ) {
				return $real;
			}
			if ( ! is_file( $real ) || ! is_readable( $real ) ) {
				return new \WP_Error( 'wpcc_not_readable', sprintf( __( '%s is not readable.', 'wp-command-center' ), $file['path'] ) );
			}

			$original = (string) file_get_contents( $real );
			$diff     = $differ->generate( $original, $file['modified'], $file['path'] );

			$is_php = 'php' === strtolower( pathinfo( $real, PATHINFO_EXTENSION ) );
			$syntax = $is_php
				? $approval->tokenizer_check( $file['modified'] )
				: [ 'passed' => true, 'message' => 'Not a PHP file — syntax check skipped.', 'method' => 'none' ];

			$all_valid = $all_valid && $syntax['passed'];

			$danger = DangerousFiles::is_dangerous_path( $file['path'] );
			if ( $danger ) {
				$dangerous[] = $file['path'];
			}

			$previews[] = [
				'path'      => $file['path'],
				'changed'   => '' !== $diff,
				'diff'      => $diff,
				'syntax'    => $syntax,
				'dangerous' => $danger,
			];
		}

		$this->audit( 'patch.preview', [ 'files' => count( $previews ), 'syntax_ok' => $all_valid ], $context );

		return [
			'action'             => 'patch_preview',
			'files'              => $previews,
			'syntax_ok'          => $all_valid,
			'dangerous_files'    => $dangerous,
			'requires_confirmation' => ! empty( $dangerous ),
			'persisted'          => false,
		];
	}

	// ── Create (proposal only; no disk write) ────────────────────

	private function create( array $params, array $context ): array|\WP_Error {
		$files = $this->normalize_files( $params['files'] ?? [] );

		if ( is_wp_error( $files ) ) {
			return $files;
		}

		$explanation = (string) ( $params['explanation'] ?? '' );
		$risk_level  = (string) ( $params['risk_level'] ?? PatchManager::RISK_LOW );
		$source      = (string) ( $params['source'] ?? PatchManager::SOURCE_API );
		$actor       = $context['actor'] ?? [];

		$patch = ( new PatchManager() )->create(
			$files,
			$explanation,
			$risk_level,
			$source,
			$actor,
			$context['session_id'] ?? null,
			$context['task_id'] ?? null,
			$context['plan_id'] ?? null
		);

		if ( is_wp_error( $patch ) ) {
			return $patch;
		}

		// PatchManager already audits patch.created.
		return [
			'action'      => 'patch_create',
			'patch_id'    => $patch['id'],
			'status'      => $patch['status'],
			'file_count'  => count( $patch['files'] ),
			'risk_level'  => $patch['risk_level'] ?? $risk_level,
		];
	}

	// ── Apply (snapshot + write + verify + auto-revert) ──────────

	private function apply( array $params, array $context ): array|\WP_Error {
		$patch_id = sanitize_text_field( (string) ( $params['patch_id'] ?? '' ) );

		if ( '' === $patch_id ) {
			return new \WP_Error( 'wpcc_missing_patch_id', __( 'patch_id is required.', 'wp-command-center' ) );
		}

		$patches  = new PatchManager();
		$approval = new PatchApproval();
		$actor    = $context['actor'] ?? [];

		$patch = $patches->get( $patch_id );
		if ( is_wp_error( $patch ) ) {
			return $patch;
		}

		// The Security Mode operation gate upstream is the human approval layer.
		// Move a pending/draft patch to approved here so apply() can proceed.
		if ( in_array( $patch['status'], [ PatchManager::STATUS_DRAFT, PatchManager::STATUS_PENDING_APPROVAL ], true ) ) {
			$approved = $approval->approve( $patch_id, $actor );
			if ( is_wp_error( $approved ) ) {
				return $approved;
			}
		}

		$result = $approval->apply( $patch_id, $actor );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$snapshot_ids = $result['snapshot_ids'] ?? [];
		$verification = $result['verification'] ?? [ 'passed' => null ];
		$applied      = PatchManager::STATUS_APPLIED === $result['status'];

		$this->audit( 'patch.apply.bridge', [
			'patch_id'     => $patch_id,
			'status'       => $result['status'],
			'verified'     => ! empty( $verification['passed'] ),
			'reason'       => $context['destructive_reason'] ?? '',
		], $context );

		return [
			'action'             => 'patch_apply',
			'patch_id'           => $patch_id,
			'status'             => $result['status'],
			'applied'            => $applied,
			// rollback_id is the handle passed to rollback_manage{rollback_apply}.
			'rollback_id'        => $applied ? $patch_id : null,
			'snapshot_ids'       => $snapshot_ids,
			'rollback_available' => $applied && ! empty( $snapshot_ids ),
			'verification'       => $verification,
		];
	}

	// ── Verify (syntax + applied-state integrity) ────────────────

	private function verify( array $params, array $context ): array|\WP_Error {
		$patch_id = sanitize_text_field( (string) ( $params['patch_id'] ?? '' ) );

		if ( '' === $patch_id ) {
			return new \WP_Error( 'wpcc_missing_patch_id', __( 'patch_id is required.', 'wp-command-center' ) );
		}

		$patch = ( new PatchManager() )->get( $patch_id );
		if ( is_wp_error( $patch ) ) {
			return $patch;
		}

		$guard    = new PathGuard();
		$approval = new PatchApproval();
		$checks   = [];
		$all_ok   = true;

		foreach ( $patch['files'] as $file ) {
			$real = $guard->resolve( $file['path'] );
			if ( is_wp_error( $real ) ) {
				return $real;
			}

			$current   = is_readable( $real ) ? (string) file_get_contents( $real ) : '';
			$syntax    = $approval->verify_file( $real );
			$applied   = $current === ( $file['modified'] ?? '' );
			$all_ok    = $all_ok && $syntax['passed'];

			$checks[] = [
				'path'            => $file['path'],
				'syntax'          => $syntax,
				'matches_patched' => $applied,
			];
		}

		$this->audit( 'patch.verify', [ 'patch_id' => $patch_id, 'syntax_ok' => $all_ok ], $context );

		return [
			'action'    => 'patch_verify',
			'patch_id'  => $patch_id,
			'status'    => $patch['status'],
			'syntax_ok' => $all_ok,
			'checks'    => $checks,
		];
	}

	// ── Status ───────────────────────────────────────────────────

	private function status( array $params ): array|\WP_Error {
		$patch_id = sanitize_text_field( (string) ( $params['patch_id'] ?? '' ) );

		if ( '' === $patch_id ) {
			return new \WP_Error( 'wpcc_missing_patch_id', __( 'patch_id is required.', 'wp-command-center' ) );
		}

		$patch = ( new PatchManager() )->get( $patch_id );
		if ( is_wp_error( $patch ) ) {
			return $patch;
		}

		return [
			'action'       => 'patch_status',
			'patch_id'     => $patch['id'],
			'status'       => $patch['status'],
			'risk_level'   => $patch['risk_level'] ?? null,
			'paths'        => array_map( static fn( $f ) => $f['path'], $patch['files'] ),
			'snapshot_ids' => $patch['snapshot_ids'] ?? [],
			'verification' => $patch['verification'] ?? null,
			'created_at'   => $patch['created_at'] ?? null,
		];
	}

	// ── Helpers ──────────────────────────────────────────────────

	/**
	 * Normalize and validate the inbound files array: [{ path, modified }].
	 *
	 * @return array<int,array{path:string,modified:string}>|\WP_Error
	 */
	private function normalize_files( $files ): array|\WP_Error {
		if ( ! is_array( $files ) || empty( $files ) ) {
			return new \WP_Error( 'wpcc_no_files', __( 'A patch must include at least one file: [{ path, modified }].', 'wp-command-center' ) );
		}

		$normalized = [];

		foreach ( $files as $file ) {
			if ( ! is_array( $file ) ) {
				return new \WP_Error( 'wpcc_invalid_file', __( 'Each file must be an object with path and modified.', 'wp-command-center' ) );
			}

			$path = isset( $file['path'] ) ? trim( (string) $file['path'], '/' ) : '';
			if ( '' === $path ) {
				return new \WP_Error( 'wpcc_invalid_path', __( 'Each file must have a path.', 'wp-command-center' ) );
			}

			$normalized[] = [
				'path'     => $path,
				'modified' => (string) ( $file['modified'] ?? '' ),
			];
		}

		return $normalized;
	}

	private function audit( string $event, array $data, array $context ): void {
		$actor = isset( $context['actor'] ) ? AuditLog::resolve_actor( $context['actor'] ) : null;
		( new AuditLog() )->record( $event, array_merge( [ 'actor' => $actor ], $data ) );
	}
}
