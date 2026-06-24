<?php
/**
 * PROGRAM-4 / P4.6 — WooCommerce product {@see FieldAccessor}.
 *
 * Drives the runtime-agnostic {@see RollbackDelta} core over a WC_Product's editable
 * properties for field-scoped, drift-aware product_update rollback (the F-1 fix for the
 * Woo product runtime). Replaces the full 16-field snapshot/restore.
 *
 * Integrity contract: this accessor reads and writes ONLY through WooCommerce's public
 * CRUD (WC_Product getters/setters + save()). It NEVER touches raw post meta, so every
 * write keeps WC's derived state consistent (price/stock lookup tables, term
 * relationships, caches). This is the WooCommerce-sanctioned mutation path; per-field
 * load+set+save is a cold-path cost (rollback only), mirroring MediaFieldAccessor's
 * per-column wp_update_post.
 *
 * Field model: each unified field is its own single backing key (key == field name). A WC
 * product property always "exists" (the getter returns a value/default), so key_exists is
 * always true ⇒ restore always writes the prior value (like Content/Media post columns);
 * key_delete is unreachable. Scalars drift-compare as strings; manage_stock as bool;
 * stock_quantity as nullable-numeric; id-set fields (categories/tags/gallery) order-
 * insensitively; attributes via a normalized name→options/flags compare.
 *
 * Scope: product properties only. Variations, coupons, orders, and file bytes are not
 * handled here.
 */

namespace WPCommandCenter\Rollback;

defined( 'ABSPATH' ) || exit;

final class WooProductAccessor implements FieldAccessor {

	/** Unified field → [ getter, setter ]. The field name is also its single backing key. */
	private const MAP = [
		'name'              => [ 'get_name', 'set_name' ],
		'description'       => [ 'get_description', 'set_description' ],
		'short_description' => [ 'get_short_description', 'set_short_description' ],
		'sku'               => [ 'get_sku', 'set_sku' ],
		'regular_price'     => [ 'get_regular_price', 'set_regular_price' ],
		'sale_price'        => [ 'get_sale_price', 'set_sale_price' ],
		'status'            => [ 'get_status', 'set_status' ],
		'stock_status'      => [ 'get_stock_status', 'set_stock_status' ],
		'manage_stock'      => [ 'get_manage_stock', 'set_manage_stock' ],
		'stock_quantity'    => [ 'get_stock_quantity', 'set_stock_quantity' ],
		'image_id'          => [ 'get_image_id', 'set_image_id' ],
		'category_ids'      => [ 'get_category_ids', 'set_category_ids' ],
		'tag_ids'           => [ 'get_tag_ids', 'set_tag_ids' ],
		'gallery_image_ids' => [ 'get_gallery_image_ids', 'set_gallery_image_ids' ],
		'attributes'        => [ 'get_attributes', 'set_attributes' ],
	];

	/** Integer-set fields — drift compared order-insensitively. */
	private const ID_SETS = [ 'category_ids' => true, 'tag_ids' => true, 'gallery_image_ids' => true ];

	public function backing_keys( string $field ): array {
		return isset( self::MAP[ $field ] ) ? [ $field ] : [];
	}

	/**
	 * @param int|string $entity_id
	 * @return mixed
	 */
	public function read_field( $entity_id, string $field ) {
		if ( ! isset( self::MAP[ $field ] ) ) {
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
	 * A WC product property always exists while the product exists.
	 *
	 * @param int|string $entity_id
	 */
	public function key_exists( $entity_id, string $key ): bool {
		return isset( self::MAP[ $key ] ) && (bool) wc_get_product( (int) $entity_id );
	}

	/**
	 * @param int|string $entity_id
	 * @return mixed
	 */
	public function key_get( $entity_id, string $key ) {
		return $this->read_field( $entity_id, $key );
	}

	/**
	 * Restore a property's prior value via the WC setter + save(). Keeps WC-derived
	 * state consistent; never writes raw meta.
	 *
	 * @param int|string $entity_id
	 * @param mixed      $value
	 */
	public function key_set( $entity_id, string $key, $value ): void {
		if ( ! isset( self::MAP[ $key ] ) ) {
			return;
		}
		$p = wc_get_product( (int) $entity_id );
		if ( ! $p ) {
			return;
		}
		$setter = self::MAP[ $key ][1];
		$p->{$setter}( $value );
		$p->save();
	}

	/**
	 * Unreachable in practice (key_exists is always true for an existing product); a WC
	 * property cannot be "absent". Defined to satisfy the interface.
	 *
	 * @param int|string $entity_id
	 */
	public function key_delete( $entity_id, string $key ): void {
		// No-op: product properties have no "absent" state to restore.
	}

	/**
	 * Per-field-type drift comparison.
	 *
	 * @param mixed $current
	 * @param mixed $after
	 */
	public function equals( string $field, $current, $after ): bool {
		if ( isset( self::ID_SETS[ $field ] ) ) {
			$a = array_map( 'intval', (array) $current );
			$b = array_map( 'intval', (array) $after );
			sort( $a );
			sort( $b );
			return $a === $b;
		}
		if ( 'attributes' === $field ) {
			return $this->normalize_attributes( $current ) === $this->normalize_attributes( $after );
		}
		if ( 'manage_stock' === $field ) {
			return (bool) $current === (bool) $after;
		}
		if ( 'stock_quantity' === $field ) {
			// Distinguish null (not set) from 0; otherwise numeric compare.
			if ( null === $current || null === $after ) {
				return $current === $after;
			}
			return (float) $current === (float) $after;
		}
		return (string) $current === (string) $after;
	}

	/**
	 * Normalize WC_Product_Attribute[] (or an equivalent array) to a stable, comparable
	 * shape keyed by sanitized name: [ name => [ options(sorted), visible, variation ] ].
	 *
	 * @param mixed $attrs
	 * @return array<string,array<string,mixed>>
	 */
	private function normalize_attributes( $attrs ): array {
		$out = [];
		foreach ( (array) $attrs as $key => $a ) {
			if ( $a instanceof \WC_Product_Attribute ) {
				$name      = $a->get_name();
				$options   = array_map( 'strval', (array) $a->get_options() );
				$visible   = (bool) $a->get_visible();
				$variation = (bool) $a->get_variation();
			} elseif ( is_array( $a ) ) {
				$name      = (string) ( $a['name'] ?? $key );
				$options   = array_map( 'strval', (array) ( $a['options'] ?? ( $a['value'] ?? [] ) ) );
				$visible   = (bool) ( $a['is_visible'] ?? $a['visible'] ?? true );
				$variation = (bool) ( $a['is_variation'] ?? $a['variation'] ?? false );
			} else {
				continue;
			}
			sort( $options );
			$out[ (string) $name ] = [ 'options' => $options, 'visible' => $visible, 'variation' => $variation ];
		}
		ksort( $out );
		return $out;
	}
}
