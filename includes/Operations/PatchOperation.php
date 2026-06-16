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
use WPCommandCenter\PatchSystem\PatchGuard;
use WPCommandCenter\PatchSystem\PatchModeResolver;
use WPCommandCenter\Security\AuditLog;
use WPCommandCenter\Security\PathGuard;

defined( 'ABSPATH' ) || exit;

// PathGuard is imported above; normalize_relative() canonicalizes inbound paths.

final class PatchOperation {

	const ACTIONS = [ 'patch_preview', 'patch_create', 'patch_apply', 'patch_verify', 'patch_status' ];

	/**
	 * Top-level parameters the operation understands. Anything else is rejected
	 * (rather than silently ignored) so a mistyped field surfaces immediately.
	 * Per-file fields are validated separately by PatchModeResolver.
	 */
	private const KNOWN_PARAMS = [ 'action', 'files', 'patch_id', 'explanation', 'risk_level', 'source', 'confirm', 'confirmation_phrase', 'reason' ];

	/**
	 * Agent-continuity / transport fields that callers (REST get_params, MCP)
	 * may carry through. Accepted and ignored here, never treated as unknown.
	 */
	private const PASSTHROUGH_PARAMS = [ 'session_id', 'task_id', 'action_id', 'plan_id', 'step', 'request_id', 'queue_id', 'context', 'context_mode', 'within_workflow', 'rest_route' ];

	public function run( array $params, array $context = [] ): array|\WP_Error {
		$action = sanitize_key( $params['action'] ?? '' );

		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			return new \WP_Error( 'wpcc_invalid_patch_action', sprintf( __( 'Invalid action: %s. Use patch_preview, patch_create, patch_apply, patch_verify, or patch_status.', 'wp-command-center' ), esc_html( $action ) ) );
		}

		$unknown = $this->reject_unknown_params( $params );
		if ( is_wp_error( $unknown ) ) {
			return $unknown;
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

		$differ    = new DiffGenerator();
		$approval  = new PatchApproval();
		$previews  = [];
		$all_valid = true;
		$dangerous = [];

		foreach ( $files as $file ) {
			// normalize_files() has already resolved the patch mode into the full
			// `modified` body and stashed the on-disk `original` for diffing.
			$original = $file['original'];
			$modified = $file['modified'];
			$diff     = $differ->generate( $original, $modified, $file['path'] );

			$is_php = 'php' === strtolower( pathinfo( $file['path'], PATHINFO_EXTENSION ) );
			$syntax = $is_php
				? $approval->tokenizer_check( $modified )
				: [ 'passed' => true, 'message' => 'Not a PHP file — syntax check skipped.', 'method' => 'none' ];

			// Flag changes that would strip a plugin/theme bootstrap header.
			$header_error = PatchGuard::validate_change( $file['path'], $original, $modified );
			$header_safe  = ! is_wp_error( $header_error );

			$all_valid = $all_valid && $syntax['passed'] && $header_safe;

			$danger = DangerousFiles::is_dangerous_path( $file['path'] );
			if ( $danger ) {
				$dangerous[] = $file['path'];
			}

			$is_whole_file = PatchModeResolver::MODE_WHOLE_FILE === $file['mode'];
			[ $added, $removed ] = $this->count_diff_lines( $diff );

			$previews[] = [
				'path'                      => $file['path'],
				'mode'                      => $file['mode'],
				// 'whole_file' replaces the entire file; everything else is a
				// localized edit. This is the field that previously made a
				// mistyped content field look like a request to wipe the file.
				'patch_type'                => $is_whole_file ? 'whole_file' : 'partial',
				'is_whole_file_replacement' => $is_whole_file,
				'summary'                   => $file['meta']['summary'] ?? '',
				'changed'                   => '' !== $diff,
				'original_lines'            => $this->line_count( $original ),
				'modified_lines'            => $this->line_count( $modified ),
				'lines_added'               => $added,
				'lines_removed'             => $removed,
				'diff'                      => $diff,
				'syntax'                    => $syntax,
				'dangerous'                 => $danger,
				'bootstrap_file'            => PatchGuard::is_bootstrap_file( $file['path'], $original ),
				'header_safe'               => $header_safe,
				'header_warning'            => $header_safe ? '' : $header_error->get_error_message(),
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

		// Hand PatchManager the canonical { path, modified } it expects; the
		// resolved mode/meta/original are preview-only and not persisted here.
		$resolved = array_map(
			static fn( array $f ): array => [ 'path' => $f['path'], 'modified' => $f['modified'] ],
			$files
		);

		$patch = ( new PatchManager() )->create(
			$resolved,
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
			// Echo how each file's edit was interpreted so the agent can confirm a
			// partial edit was not silently treated as a whole-file replacement.
			'files'       => array_map(
				static fn( array $f ): array => [
					'path'       => $f['path'],
					'mode'       => $f['mode'],
					'patch_type' => PatchModeResolver::MODE_WHOLE_FILE === $f['mode'] ? 'whole_file' : 'partial',
					'summary'    => $f['meta']['summary'] ?? '',
				],
				$files
			),
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
	 * Normalize and validate the inbound files array, resolving each entry's
	 * patch mode (whole_file/append/prepend/replace_text/replace_range/
	 * unified_diff) against the current on-disk content into the single full-file
	 * `modified` body the rest of the engine works on. Unknown per-file fields
	 * are rejected here (via PatchModeResolver) instead of being ignored.
	 *
	 * @return array<int,array{path:string,modified:string,mode:string,meta:array<string,mixed>,original:string}>|\WP_Error
	 */
	private function normalize_files( $files ): array|\WP_Error {
		if ( ! is_array( $files ) || empty( $files ) ) {
			return new \WP_Error( 'wpcc_no_files', __( 'A patch must include at least one file. Each entry needs a path plus the fields for its mode (default whole_file uses { path, modified }).', 'wp-command-center' ) );
		}

		$guard      = new PathGuard();
		$normalized = [];

		foreach ( $files as $file ) {
			if ( ! is_array( $file ) ) {
				return new \WP_Error( 'wpcc_invalid_file', __( 'Each file must be an object with a path and the fields for its patch mode.', 'wp-command-center' ) );
			}

			// Accept wp-content-prefixed / absolute paths and canonicalize to the
			// wp-content-relative form (e.g. "themes/foo/functions.php").
			$path = isset( $file['path'] ) ? $guard->normalize_relative( (string) $file['path'] ) : '';
			if ( '' === $path ) {
				return new \WP_Error( 'wpcc_invalid_path', __( 'Each file must have a path.', 'wp-command-center' ) );
			}

			$real = $guard->resolve( $path );
			if ( is_wp_error( $real ) ) {
				return $real;
			}
			if ( ! is_file( $real ) || ! is_readable( $real ) ) {
				return new \WP_Error( 'wpcc_not_readable', sprintf( __( '%s is not readable.', 'wp-command-center' ), $path ) );
			}

			$original = (string) file_get_contents( $real );

			// Resolve the patch mode against the real on-disk content. This both
			// rejects unknown per-file fields and turns a precise edit (append,
			// replace_text, …) into the full modified body.
			$resolved = PatchModeResolver::resolve( array_merge( $file, [ 'path' => $path ] ), $original );
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}

			$normalized[] = [
				'path'     => $path,
				'modified' => $resolved['modified'],
				'mode'     => $resolved['mode'],
				'meta'     => $resolved['meta'],
				'original' => $original,
			];
		}

		return $normalized;
	}

	/**
	 * Reject top-level parameters the operation does not understand so a mistyped
	 * field fails loudly. WP-internal keys (prefixed with "_") and known
	 * continuity/transport fields are accepted and ignored.
	 */
	private function reject_unknown_params( array $params ): ?\WP_Error {
		$allowed = array_merge( self::KNOWN_PARAMS, self::PASSTHROUGH_PARAMS );
		$unknown = [];

		foreach ( array_keys( $params ) as $key ) {
			if ( is_int( $key ) || str_starts_with( (string) $key, '_' ) || in_array( $key, $allowed, true ) ) {
				continue;
			}
			$unknown[] = (string) $key;
		}

		if ( empty( $unknown ) ) {
			return null;
		}

		return new \WP_Error(
			'wpcc_unknown_patch_field',
			sprintf(
				/* translators: 1: unknown parameter names, 2: allowed parameter names */
				__( 'Unknown parameter(s): %1$s. Allowed: %2$s. File contents go inside files[] (e.g. { path, modified }).', 'wp-command-center' ),
				implode( ', ', $unknown ),
				implode( ', ', self::KNOWN_PARAMS )
			)
		);
	}

	/** Lines in a string (0 for empty), counting a final newline as terminator. */
	private function line_count( string $text ): int {
		if ( '' === $text ) {
			return 0;
		}
		return substr_count( $text, "\n" ) + 1;
	}

	/**
	 * Count added (+) and removed (-) lines in a unified diff, ignoring the
	 * ---/+++ file headers.
	 *
	 * @return array{0:int,1:int} [added, removed]
	 */
	private function count_diff_lines( string $diff ): array {
		if ( '' === $diff ) {
			return [ 0, 0 ];
		}

		$added   = 0;
		$removed = 0;
		foreach ( explode( "\n", $diff ) as $line ) {
			if ( str_starts_with( $line, '+++' ) || str_starts_with( $line, '---' ) ) {
				continue;
			}
			if ( str_starts_with( $line, '+' ) ) {
				++$added;
			} elseif ( str_starts_with( $line, '-' ) ) {
				++$removed;
			}
		}

		return [ $added, $removed ];
	}

	private function audit( string $event, array $data, array $context ): void {
		$actor = isset( $context['actor'] ) ? AuditLog::resolve_actor( $context['actor'] ) : null;
		( new AuditLog() )->record( $event, array_merge( [ 'actor' => $actor ], $data ) );
	}
}
