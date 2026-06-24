<?php
/**
 * PROGRAM-4.8 — Bulk-scoped WooCommerce product {@see FieldAccessor}.
 *
 * Minimal accessor for the fields the `bulk_woocommerce` operation writes (regular_price,
 * status), driving the runtime-agnostic {@see RollbackDelta} core through WooCommerce public
 * CRUD only (getters/setters + save()) — never raw post meta, so WC-derived state stays
 * consistent. Bulk-operation-scoped: it exists only because no WooProductAccessor is present
 * in this branch lineage; it is NOT a Woo-runtime change.
 *
 * A WC product property always "exists" while the product exists, so key_exists is true and
 * restore always writes the prior value (existence fidelity collapses to always-write).
 */

namespace WPCommandCenter\Rollback;

defined( 'ABSPATH' ) || exit;

final class BulkWooAccessor implements FieldAccessor {

	/** Unified field → [ getter, setter ]; the field name is also its single backing key. */
	private const MAP = [
		'regular_price' => [ 'get_regular_price', 'set_regular_price' ],
		'status'        => [ 'get_status', 'set_status' ],
	];

	public function backing_keys( string $field ): array {
		return isset( self::MAP[ $field ] ) ? [ $field ] : [];
	}

	/**
	 * @param int|string $entity_id
	 * @return mixed
	 */
	public function read_field( $entity_id, string $field ) {
		if ( ! isset( self::MAP[ $field ] ) || ! function_exists( 'wc_get_product' ) ) {
			return '';
		}
		$p = wc_get_product( (int) $entity_id );
		if ( ! $p ) {
			return '';
		}
		$getter = self::MAP[ $field ][0];
		return $p->{$getter}();
	}

	/**
	 * @param int|string $entity_id
	 */
	public function key_exists( $entity_id, string $key ): bool {
		return isset( self::MAP[ $key ] ) && function_exists( 'wc_get_product' ) && (bool) wc_get_product( (int) $entity_id );
	}

	/**
	 * @param int|string $entity_id
	 * @return mixed
	 */
	public function key_get( $entity_id, string $key ) {
		return $this->read_field( $entity_id, $key );
	}

	/**
	 * @param int|string $entity_id
	 * @param mixed      $value
	 */
	public function key_set( $entity_id, string $key, $value ): void {
		if ( ! isset( self::MAP[ $key ] ) || ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		$p = wc_get_product( (int) $entity_id );
		if ( ! $p ) {
			return;
		}
		$setter = self::MAP[ $key ][1];
		$p->{$setter}( (string) $value );
		$p->save();
	}

	/**
	 * Unreachable (a product property has no "absent" state). Defined for the interface.
	 *
	 * @param int|string $entity_id
	 */
	public function key_delete( $entity_id, string $key ): void {
		// No-op: product properties are always present.
	}

	/**
	 * @param mixed $current
	 * @param mixed $after
	 */
	public function equals( string $field, $current, $after ): bool {
		if ( 'regular_price' === $field ) {
			// Normalize decimals so "10" / "10.00" compare equal (avoids false drift).
			$c = ( '' === (string) $current ) ? '' : (string) ( (float) $current );
			$a = ( '' === (string) $after ) ? '' : (string) ( (float) $after );
			return $c === $a;
		}
		return (string) $current === (string) $after;
	}
}
