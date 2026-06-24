<?php
/**
 * PROGRAM-4.8 — Bulk-scoped ACF value {@see FieldAccessor}.
 *
 * Minimal accessor for the single ACF field value the `bulk_acf` operation writes, driving
 * the {@see RollbackDelta} core through the ACF public API (get_field/update_field). The
 * field is identified by the field key/name supplied to the constructor; the unified field
 * name is the constant 'value'. Bulk-operation-scoped: it covers the one value bulk_acf
 * writes — nested/structured ACF fidelity remains the ACF runtime's concern (P4.9), not this.
 *
 * Existence fidelity uses the value meta row (metadata_exists): if the field had no value
 * before the write, restore clears it via update_field(null); otherwise the prior value is
 * written back (drift-aware).
 */

namespace WPCommandCenter\Rollback;

defined( 'ABSPATH' ) || exit;

final class BulkAcfAccessor implements FieldAccessor {

	private const FIELD = 'value';

	/** Field key or name as supplied to bulk_acf. */
	private string $field_key;

	/** Resolved field name (the raw meta_key), for existence checks. */
	private string $name;

	public function __construct( string $field_key ) {
		$this->field_key = $field_key;
		// PROGRAM-4 certification (AR-MED-1) — resolve the underlying field NAME for existence,
		// because the value's meta_key is the field name, not the key. Without this, a field-KEY
		// selector would read existence=false and clear-instead-of-restore on rollback.
		$this->name = $field_key;
		if ( function_exists( 'acf_get_field' ) ) {
			$f = acf_get_field( $field_key );
			if ( is_array( $f ) && ! empty( $f['name'] ) ) {
				$this->name = (string) $f['name'];
			}
		}
	}

	public function backing_keys( string $field ): array {
		return self::FIELD === $field ? [ self::FIELD ] : [];
	}

	/**
	 * @param int|string $entity_id
	 * @return mixed
	 */
	public function read_field( $entity_id, string $field ) {
		if ( self::FIELD !== $field || ! function_exists( 'get_field' ) ) {
			return null;
		}
		// Read RAW (unformatted) — the storable form update_field() expects; keeps
		// capture↔after↔restore symmetric for formatted-return fields (relationship/image).
		return get_field( $this->field_key, (int) $entity_id, false );
	}

	/**
	 * @param int|string $entity_id
	 */
	public function key_exists( $entity_id, string $key ): bool {
		// ACF stores a scalar value under meta_key == field NAME; the row's presence is the
		// existence signal (distinguishes absent from present-but-empty). Uses the resolved name
		// so a field-KEY selector reports existence correctly.
		return metadata_exists( 'post', (int) $entity_id, $this->name );
	}

	/**
	 * @param int|string $entity_id
	 * @return mixed
	 */
	public function key_get( $entity_id, string $key ) {
		return $this->read_field( $entity_id, self::FIELD );
	}

	/**
	 * @param int|string $entity_id
	 * @param mixed      $value
	 */
	public function key_set( $entity_id, string $key, $value ): void {
		if ( function_exists( 'update_field' ) ) {
			update_field( $this->field_key, $value, (int) $entity_id );
		}
	}

	/**
	 * Restore prior absence — clear the ACF value.
	 *
	 * @param int|string $entity_id
	 */
	public function key_delete( $entity_id, string $key ): void {
		if ( function_exists( 'update_field' ) ) {
			update_field( $this->field_key, null, (int) $entity_id );
		}
	}

	/**
	 * Scalar/normalized drift comparison; arrays compared structurally.
	 *
	 * @param mixed $current
	 * @param mixed $after
	 */
	public function equals( string $field, $current, $after ): bool {
		if ( is_array( $current ) || is_array( $after ) ) {
			return wp_json_encode( $current ) === wp_json_encode( $after );
		}
		return (string) $current === (string) $after;
	}
}
