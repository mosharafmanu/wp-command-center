<?php
/**
 * PROGRAM-4 / P4.1 — WP-option backed {@see FieldAccessor}.
 *
 * Drives the runtime-agnostic {@see RollbackDelta} core over WordPress options
 * (global state — no entity). A field maps 1:1 to an option of the same name, so
 * this base is usable as-is by option-backed runtimes (e.g. Settings); a runtime
 * whose unified field name differs from its option key can subclass and override
 * `backing_keys`/`read_field`.
 *
 * Existence fidelity: WordPress `get_option` returns the supplied default only when
 * an option is genuinely absent, so a unique sentinel distinguishes "absent" from
 * "present but empty/false" — the existed-vs-empty guarantee. `$entity_id` is unused
 * (options are global); callers pass 0.
 */

namespace WPCommandCenter\Rollback;

defined( 'ABSPATH' ) || exit;

class OptionAccessor implements FieldAccessor {

	/** Sentinel that cannot collide with a real stored option value. */
	private const ABSENT = "\0__wpcc_option_absent__\0";

	public function backing_keys( string $field ): array {
		return [ $field ];
	}

	/**
	 * @param int|string $entity_id Unused (options are global).
	 * @return mixed
	 */
	public function read_field( $entity_id, string $field ) {
		return get_option( $field );
	}

	/**
	 * @param int|string $entity_id Unused.
	 */
	public function key_exists( $entity_id, string $key ): bool {
		return get_option( $key, self::ABSENT ) !== self::ABSENT;
	}

	/**
	 * @param int|string $entity_id Unused.
	 * @return mixed
	 */
	public function key_get( $entity_id, string $key ) {
		return get_option( $key );
	}

	/**
	 * @param int|string $entity_id Unused.
	 * @param mixed      $value
	 */
	public function key_set( $entity_id, string $key, $value ): void {
		update_option( $key, $value );
	}

	/**
	 * @param int|string $entity_id Unused.
	 */
	public function key_delete( $entity_id, string $key ): void {
		delete_option( $key );
	}

	/**
	 * Scalar string drift comparison — all option values handled here are scalar.
	 *
	 * @param mixed $current
	 * @param mixed $after
	 */
	public function equals( string $field, $current, $after ): bool {
		return (string) $current === (string) $after;
	}
}
