<?php
/**
 * PROGRAM-4.10 — Elementor document {@see FieldAccessor}.
 *
 * Drives the runtime-agnostic {@see RollbackDelta} core over a single Elementor page's
 * `_elementor_data` postmeta, exposing the unified field 'data'. The WHOLE `_elementor_data`
 * JSON document (sections → columns → widgets) is treated ATOMICALLY — never decomposed into
 * per-widget edits. Drift is detected by a normalized whole-document compare, so any change
 * (an edit to any widget, a reorder, an add/remove) is seen and the prior document is
 * refused-not-clobbered.
 *
 * Storage nuance: the stored value is the raw `_elementor_data` JSON string. Writes wp_slash()
 * the value (mirroring the runtime's save/rollback) so the byte content round-trips through
 * update_post_meta's slash-stripping. Reads return the raw stored string. The Elementor CSS
 * cache is intentionally NOT cleared here (the runtime owns that after a complete restore), so
 * this accessor stays a thin meta adapter with no Elementor\Plugin coupling.
 *
 * Post-bound only (an Elementor page is a post).
 */

namespace WPCommandCenter\Rollback;

defined( 'ABSPATH' ) || exit;

final class ElementorDataAccessor implements FieldAccessor {

	private const FIELD    = 'data';
	private const META_KEY = '_elementor_data';

	public function backing_keys( string $field ): array {
		return self::FIELD === $field ? [ self::META_KEY ] : [];
	}

	/**
	 * @param int|string $entity_id
	 * @return mixed
	 */
	public function read_field( $entity_id, string $field ) {
		return self::FIELD === $field ? $this->key_get( $entity_id, self::META_KEY ) : '';
	}

	/**
	 * @param int|string $entity_id
	 */
	public function key_exists( $entity_id, string $key ): bool {
		return metadata_exists( 'post', (int) $entity_id, self::META_KEY );
	}

	/**
	 * @param int|string $entity_id
	 * @return mixed Raw stored JSON string.
	 */
	public function key_get( $entity_id, string $key ) {
		return get_post_meta( (int) $entity_id, self::META_KEY, true );
	}

	/**
	 * Restore the whole document. wp_slash keeps the JSON byte-faithful through
	 * update_post_meta's slash stripping (mirrors the runtime save/rollback).
	 *
	 * @param int|string $entity_id
	 * @param mixed      $value
	 */
	public function key_set( $entity_id, string $key, $value ): void {
		update_post_meta( (int) $entity_id, self::META_KEY, wp_slash( (string) $value ) );
	}

	/**
	 * Restore prior absence (unreachable in practice — an edited page always has data).
	 *
	 * @param int|string $entity_id
	 */
	public function key_delete( $entity_id, string $key ): void {
		delete_post_meta( (int) $entity_id, self::META_KEY );
	}

	/**
	 * Normalized whole-document drift compare: decode both then order-preserving re-encode, so
	 * any structural/content/order change registers as drift while pure encoding noise does not.
	 * Falls back to raw-string compare when either side is not decodable JSON.
	 *
	 * @param mixed $current
	 * @param mixed $after
	 */
	public function equals( string $field, $current, $after ): bool {
		$c = $this->normalize( $current );
		$a = $this->normalize( $after );
		if ( null !== $c && null !== $a ) {
			return $c === $a;
		}
		return (string) $current === (string) $after;
	}

	/** @param mixed $raw @return string|null canonical JSON, or null if not decodable. */
	private function normalize( $raw ): ?string {
		$decoded = json_decode( is_string( $raw ) ? $raw : (string) wp_json_encode( $raw ), true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}
		$encoded = wp_json_encode( $decoded ); // order-preserving; canonicalizes whitespace/escaping
		return is_string( $encoded ) ? $encoded : null;
	}
}
