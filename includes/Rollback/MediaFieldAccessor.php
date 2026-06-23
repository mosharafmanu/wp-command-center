<?php
/**
 * PROGRAM-4 / P4.2 — Attachment-metadata {@see FieldAccessor}.
 *
 * Drives the runtime-agnostic {@see RollbackDelta} core over an attachment's
 * metadata, which is a MIX of post columns and post meta:
 *   title       → post_title    (column)
 *   caption     → post_excerpt  (column)
 *   description → post_content  (column)
 *   alt         → _wp_attachment_image_alt (post meta)
 *
 * The accessor dispatches each backing key to the right primitive (column writes
 * via wp_update_post; meta via *_post_meta) so the core stays storage-agnostic.
 * Post columns always exist (the attachment post does), so existence fidelity is
 * meaningful only for `alt`: absent → deleted on rollback, present-but-empty →
 * restored as an empty meta row.
 *
 * Scope: attachment metadata only. File bytes / generated sizes are handled
 * separately by MediaSnapshot and are never touched here.
 */

namespace WPCommandCenter\Rollback;

defined( 'ABSPATH' ) || exit;

final class MediaFieldAccessor implements FieldAccessor {

	/** Unified field → its single backing key. */
	private const KEYS = [
		'title'       => 'post_title',
		'caption'     => 'post_excerpt',
		'description' => 'post_content',
		'alt'         => '_wp_attachment_image_alt',
	];

	/** Backing keys that are post columns (everything else is post meta). */
	private const COLUMNS = [
		'post_title'   => true,
		'post_excerpt' => true,
		'post_content' => true,
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
	 * @param int|string $entity_id
	 */
	public function key_exists( $entity_id, string $key ): bool {
		if ( isset( self::COLUMNS[ $key ] ) ) {
			// A post column exists iff the attachment post exists.
			return (bool) get_post( (int) $entity_id );
		}
		return metadata_exists( 'post', (int) $entity_id, $key );
	}

	/**
	 * @param int|string $entity_id
	 * @return mixed
	 */
	public function key_get( $entity_id, string $key ) {
		if ( isset( self::COLUMNS[ $key ] ) ) {
			return (string) get_post_field( $key, (int) $entity_id, 'raw' );
		}
		return get_post_meta( (int) $entity_id, $key, true );
	}

	/**
	 * @param int|string $entity_id
	 * @param mixed      $value
	 */
	public function key_set( $entity_id, string $key, $value ): void {
		if ( isset( self::COLUMNS[ $key ] ) ) {
			wp_update_post( [ 'ID' => (int) $entity_id, $key => $value ] );
			return;
		}
		update_post_meta( (int) $entity_id, $key, $value );
	}

	/**
	 * @param int|string $entity_id
	 */
	public function key_delete( $entity_id, string $key ): void {
		if ( isset( self::COLUMNS[ $key ] ) ) {
			// Columns cannot be absent — restoring "absence" means an empty value.
			wp_update_post( [ 'ID' => (int) $entity_id, $key => '' ] );
			return;
		}
		delete_post_meta( (int) $entity_id, $key );
	}

	/**
	 * Scalar string drift comparison — all attachment metadata fields are scalar.
	 *
	 * @param mixed $current
	 * @param mixed $after
	 */
	public function equals( string $field, $current, $after ): bool {
		return (string) $current === (string) $after;
	}
}
