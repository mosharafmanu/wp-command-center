<?php
/**
 * PROGRAM-4.9 — ACF single-field value {@see FieldAccessor}.
 *
 * Drives the runtime-agnostic {@see RollbackDelta} core over ONE ACF field value on a post,
 * exposing the unified field 'value'. The value is treated ATOMICALLY — for scalar fields a
 * string, for nested fields (repeater / flexible_content / group / clone / gallery /
 * relationship) the WHOLE resolved array — and is never decomposed. Drift is detected by a
 * normalized whole-value compare, so any change (including a single nested row) is seen and
 * the prior value is refused-not-clobbered.
 *
 * ACF nuance: a field is addressed by key OR name, but the value's existence lives on the raw
 * meta_key (= field NAME). This accessor resolves the field name once (acf_get_field) for the
 * existence check, while reads/writes go through the ACF API (get_field/update_field), which
 * also maintain the `_field_name` key-reference meta. update_field(null) clears the value
 * (removes the meta) — restoring prior absence faithfully.
 *
 * Post-bound only (the runtime's value_update supports post_id; user/term/option are not
 * supported). Reads/writes never touch raw post meta directly, so the ACF key-reference stays
 * consistent.
 */

namespace WPCommandCenter\Rollback;

defined( 'ABSPATH' ) || exit;

final class AcfValueAccessor implements FieldAccessor {

	private const FIELD = 'value';

	/** Field key or name as supplied to value_update. */
	private string $selector;

	/** Resolved field name (the raw meta_key), for existence checks. */
	private string $name;

	public function __construct( string $selector ) {
		$this->selector = $selector;
		$this->name     = $selector;
		if ( function_exists( 'acf_get_field' ) ) {
			$f = acf_get_field( $selector );
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
		// Read the RAW (unformatted) value — the storable form update_field() expects. For fields
		// whose return_format yields formatted objects (relationship→WP_Post, image→array/URL),
		// the formatted value is not losslessly re-storable; capturing/comparing/restoring the raw
		// value keeps capture↔after↔restore symmetric and drift detection exact.
		return get_field( $this->selector, (int) $entity_id, false );
	}

	/**
	 * Existence on the raw meta_key (= field name) — distinguishes absent from present-empty.
	 *
	 * @param int|string $entity_id
	 */
	public function key_exists( $entity_id, string $key ): bool {
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
			update_field( $this->selector, $value, (int) $entity_id );
		}
	}

	/**
	 * Restore prior absence — clear the value (removes the meta).
	 *
	 * @param int|string $entity_id
	 */
	public function key_delete( $entity_id, string $key ): void {
		if ( function_exists( 'update_field' ) ) {
			update_field( $this->selector, null, (int) $entity_id );
		}
	}

	/**
	 * Normalized whole-value drift compare; nested values compared structurally (atomic).
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
