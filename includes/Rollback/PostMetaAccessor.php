<?php
/**
 * PROGRAM-4 / P4.0 — Post-meta backed {@see FieldAccessor} base.
 *
 * Implements the raw backing-key primitives over a post's meta table
 * (`metadata_exists`/`get_post_meta`/`update_post_meta`/`delete_post_meta`) and a
 * default string drift comparison. Concrete post-bound runtimes extend this and
 * supply only the field→key mapping (`backing_keys`) and the unified field read
 * (`read_field`); see {@see SeoFieldAccessor}.
 *
 * The key write/delete pair is existence-faithful by construction: `key_set`
 * restores a prior value (including ''), `key_delete` restores prior absence.
 */

namespace WPCommandCenter\Rollback;

defined( 'ABSPATH' ) || exit;

abstract class PostMetaAccessor implements FieldAccessor {

	/**
	 * @param int|string $entity_id
	 */
	public function key_exists( $entity_id, string $key ): bool {
		return metadata_exists( 'post', (int) $entity_id, $key );
	}

	/**
	 * @param int|string $entity_id
	 * @return mixed
	 */
	public function key_get( $entity_id, string $key ) {
		return get_post_meta( (int) $entity_id, $key, true );
	}

	/**
	 * @param int|string $entity_id
	 * @param mixed      $value
	 */
	public function key_set( $entity_id, string $key, $value ): void {
		update_post_meta( (int) $entity_id, $key, $value );
	}

	/**
	 * @param int|string $entity_id
	 */
	public function key_delete( $entity_id, string $key ): void {
		delete_post_meta( (int) $entity_id, $key );
	}

	/**
	 * Default drift comparison — scalar string equality. Subclasses override for
	 * structured fields.
	 *
	 * @param mixed $current
	 * @param mixed $after
	 */
	public function equals( string $field, $current, $after ): bool {
		return (string) $current === (string) $after;
	}

	abstract public function backing_keys( string $field ): array;

	/**
	 * @param int|string $entity_id
	 * @return mixed
	 */
	abstract public function read_field( $entity_id, string $field );
}
