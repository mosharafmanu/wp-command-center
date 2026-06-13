<?php
/**
 * STEP 84 — Destructive Operation Guardrail.
 *
 * Central classifier for permanently-destructive operations (deletes that
 * cannot be reversed, live database mutations). It does two things:
 *
 *   1. classify()             — decides whether a given (operation, payload)
 *                               pair is destructive, and returns a descriptor
 *                               (confirmation phrase, target key, whether a
 *                               pre-delete backup is possible, warning text).
 *   2. missing_confirmation() — returns the confirmation requirements the
 *                               caller has not yet satisfied.
 *
 * The guard is enforced by OperationExecutor BEFORE the Security Mode approval
 * gate, so it applies in every mode — including Developer Mode, where there is
 * otherwise no approval step. A destructive operation can never execute, nor be
 * queued for approval, until the caller echoes back an explicit confirmation
 * phrase, a reason, and the target identifier.
 *
 * This class performs no side effects: no audit, no execution, no file writes.
 */

namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class DestructiveGuard {

	const PHRASE_PLUGIN  = 'DELETE_PLUGIN';
	const PHRASE_THEME   = 'DELETE_THEME';
	const PHRASE_USER    = 'DELETE_USER';
	const PHRASE_MEDIA   = 'DELETE_MEDIA';
	const PHRASE_CONTENT = 'DELETE_CONTENT';
	const PHRASE_DB      = 'RUN_DESTRUCTIVE_DB';

	/** Every destructive operation is reported at the highest risk tier. */
	const RISK_LEVEL = 'critical';

	/**
	 * Classify a (operation, payload) pair.
	 *
	 * @return array{phrase:string,target_key:string,backup_capable:bool,warning:string}|null
	 *         Descriptor when the call is destructive; null when it is not.
	 */
	public static function classify( string $operation_id, array $payload ): ?array {
		$action = isset( $payload['action'] ) ? (string) $payload['action'] : '';

		switch ( $operation_id ) {
			case 'plugin_manage':
				if ( 'plugin_delete' === $action ) {
					return self::descriptor(
						self::PHRASE_PLUGIN,
						'slug',
						true,
						__( 'Permanently deletes the plugin files from disk. This cannot be undone except by restoring the pre-delete backup.', 'wp-command-center' )
					);
				}
				break;

			case 'theme_manage':
				if ( 'theme_delete' === $action ) {
					return self::descriptor(
						self::PHRASE_THEME,
						'slug',
						false,
						__( 'Permanently deletes the theme files from disk. This cannot be undone.', 'wp-command-center' )
					);
				}
				break;

			case 'user_manage':
				if ( 'user_delete' === $action ) {
					return self::descriptor(
						self::PHRASE_USER,
						'user_id',
						false,
						__( 'Permanently deletes the user account. Authored content is reassigned only when reassign_to is supplied, otherwise it is deleted with the user.', 'wp-command-center' )
					);
				}
				break;

			case 'media_manage':
				// Only the permanent ("force") variant is destructive; a normal
				// media_delete sends the attachment to the trash and is recoverable.
				if ( 'media_delete' === $action && self::is_truthy( $payload['force'] ?? null ) ) {
					return self::descriptor(
						self::PHRASE_MEDIA,
						'media_id',
						false,
						__( 'Permanently deletes the media attachment and its files, bypassing the trash.', 'wp-command-center' )
					);
				}
				break;

			case 'content_manage':
				// content_delete is trash-only (recoverable) today; the guard only
				// engages if a caller requests the permanent/force variant.
				if ( 'content_delete' === $action
					&& ( self::is_truthy( $payload['force'] ?? null ) || self::is_truthy( $payload['permanent'] ?? null ) ) ) {
					return self::descriptor(
						self::PHRASE_CONTENT,
						'content_id',
						false,
						__( 'Permanently deletes content, bypassing the trash. This cannot be undone.', 'wp-command-center' )
					);
				}
				break;

			case 'safe_search_replace':
				// A live (non-dry-run) search-and-replace mutates rows in place.
				// SearchReplace defaults dry_run to TRUE, so a missing dry_run is a
				// safe dry run — only an explicit dry_run=false is destructive.
				if ( array_key_exists( 'dry_run', $payload ) && ! self::is_truthy( $payload['dry_run'] ) ) {
					return self::descriptor(
						self::PHRASE_DB,
						'search',
						false,
						__( 'Runs a live database search-and-replace across tables. Rows are mutated in place and cannot be automatically reverted.', 'wp-command-center' )
					);
				}
				break;
		}

		return null;
	}

	/**
	 * Convenience boolean wrapper around classify().
	 */
	public static function is_destructive( string $operation_id, array $payload ): bool {
		return null !== self::classify( $operation_id, $payload );
	}

	/**
	 * Return the confirmation requirements not yet satisfied by the payload.
	 *
	 * @param array{phrase:string,target_key:string,backup_capable:bool,warning:string} $descriptor
	 * @return string[] Empty array when confirmation is complete.
	 */
	public static function missing_confirmation( array $descriptor, array $payload ): array {
		$missing = [];

		if ( ! self::is_truthy( $payload['confirm'] ?? null ) ) {
			$missing[] = 'confirm';
		}

		$phrase = isset( $payload['confirmation_phrase'] ) ? (string) $payload['confirmation_phrase'] : '';
		if ( ! hash_equals( $descriptor['phrase'], $phrase ) ) {
			$missing[] = 'confirmation_phrase';
		}

		if ( '' === trim( (string) ( $payload['reason'] ?? '' ) ) ) {
			$missing[] = 'reason';
		}

		$target_key = $descriptor['target_key'];
		if ( '' === trim( (string) ( $payload[ $target_key ] ?? '' ) ) ) {
			$missing[] = $target_key;
		}

		return $missing;
	}

	private static function descriptor( string $phrase, string $target_key, bool $backup_capable, string $warning ): array {
		return [
			'phrase'         => $phrase,
			'target_key'     => $target_key,
			'backup_capable' => $backup_capable,
			'warning'        => $warning,
		];
	}

	/**
	 * Loose-truthy interpretation that also treats common string flags
	 * ("true", "1", "yes", "on") as true — REST/JSON callers vary.
	 */
	private static function is_truthy( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), [ '1', 'true', 'yes', 'on' ], true );
		}
		return ! empty( $value ) && '0' !== (string) $value;
	}
}
