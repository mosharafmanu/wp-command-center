<?php
/**
 * PROGRAM-4 / P4.4 — Comment {@see FieldAccessor}.
 *
 * Drives the runtime-agnostic {@see RollbackDelta} core over a comment's moderation
 * status: field `status` → the `comment_approved` column ('1' approved, '0' hold,
 * 'spam', 'trash'). The column always exists while the comment does, so existence
 * fidelity does not apply and restore always writes the prior raw value via
 * wp_update_comment (which fires the normal status-transition hooks).
 *
 * Scope: comment moderation status only (no content-edit action exists in this
 * runtime). Trash/delete are reversed structurally elsewhere and not handled here.
 */

namespace WPCommandCenter\Rollback;

defined( 'ABSPATH' ) || exit;

final class CommentFieldAccessor implements FieldAccessor {

	private const KEYS = [ 'status' => 'comment_approved' ];

	public function backing_keys( string $field ): array {
		return isset( self::KEYS[ $field ] ) ? [ self::KEYS[ $field ] ] : [];
	}

	/**
	 * @param int|string $entity_id
	 * @return mixed
	 */
	public function read_field( $entity_id, string $field ) {
		$key = self::KEYS[ $field ] ?? '';
		return '' === $key ? '' : $this->key_get( $entity_id, $key );
	}

	/**
	 * A comment column exists iff the comment exists.
	 *
	 * @param int|string $entity_id
	 */
	public function key_exists( $entity_id, string $key ): bool {
		return (bool) get_comment( (int) $entity_id );
	}

	/**
	 * @param int|string $entity_id
	 * @return mixed
	 */
	public function key_get( $entity_id, string $key ) {
		$comment = get_comment( (int) $entity_id );
		return $comment ? (string) $comment->{$key} : '';
	}

	/**
	 * @param int|string $entity_id
	 * @param mixed      $value
	 */
	public function key_set( $entity_id, string $key, $value ): void {
		wp_update_comment( [ 'comment_ID' => (int) $entity_id, $key => (string) $value ], true );
	}

	/**
	 * @param int|string $entity_id
	 */
	public function key_delete( $entity_id, string $key ): void {
		// Columns cannot be absent; unreachable for an existing comment.
		wp_update_comment( [ 'comment_ID' => (int) $entity_id, $key => '' ], true );
	}

	/**
	 * @param mixed $current
	 * @param mixed $after
	 */
	public function equals( string $field, $current, $after ): bool {
		return (string) $current === (string) $after;
	}
}
