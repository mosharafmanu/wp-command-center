<?php
/**
 * PROGRAM-4 / P4.3 — Content (post-column) {@see FieldAccessor}.
 *
 * Drives the runtime-agnostic {@see RollbackDelta} core over a post's editable
 * columns: title→post_title, status→post_status, content→post_content,
 * excerpt→post_excerpt. All four are post columns (never meta), so they always
 * "exist" while the post exists — existence-vs-absence fidelity does not apply and
 * restore always writes the prior value (even ''). Reads use the 'raw' context to
 * avoid display filtering.
 */

namespace WPCommandCenter\Rollback;

defined( 'ABSPATH' ) || exit;

final class ContentFieldAccessor implements FieldAccessor {

	private const KEYS = [
		'title'   => 'post_title',
		'status'  => 'post_status',
		'content' => 'post_content',
		'excerpt' => 'post_excerpt',
	];

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
	 * A post column exists iff the post exists.
	 *
	 * @param int|string $entity_id
	 */
	public function key_exists( $entity_id, string $key ): bool {
		return (bool) get_post( (int) $entity_id );
	}

	/**
	 * @param int|string $entity_id
	 * @return mixed
	 */
	public function key_get( $entity_id, string $key ) {
		return (string) get_post_field( $key, (int) $entity_id, 'raw' );
	}

	/**
	 * @param int|string $entity_id
	 * @param mixed      $value
	 */
	public function key_set( $entity_id, string $key, $value ): void {
		wp_update_post( [ 'ID' => (int) $entity_id, $key => $value ] );
	}

	/**
	 * @param int|string $entity_id
	 */
	public function key_delete( $entity_id, string $key ): void {
		// Columns cannot be absent — "restore absence" means an empty value. In practice
		// unreachable (key_exists is always true for an existing post).
		wp_update_post( [ 'ID' => (int) $entity_id, $key => '' ] );
	}

	/**
	 * @param mixed $current
	 * @param mixed $after
	 */
	public function equals( string $field, $current, $after ): bool {
		return (string) $current === (string) $after;
	}
}
