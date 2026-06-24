<?php
/**
 * PROGRAM-4 / P4.0 — Field accessor abstraction for the runtime-agnostic
 * RollbackDelta core.
 *
 * A FieldAccessor adapts one runtime's storage (post meta, user meta, comment
 * meta, options, WC setters, …) to a small uniform surface the {@see RollbackDelta}
 * core uses to capture and restore a field-scoped, drift-aware delta. The core
 * never touches WordPress directly — it goes through this interface — so the same
 * drift/idempotency/existence-fidelity logic serves every runtime.
 *
 * Concepts:
 *   - "field"        a unified, runtime-facing field name (e.g. SEO 'title', 'robots').
 *   - "backing key"  the underlying storage key(s) a field maps to (a field may fan
 *                    out to several keys — e.g. Yoast robots → three meta keys).
 *   - "entity_id"    the stable identity of the target object (post_id, user_id, …).
 */

namespace WPCommandCenter\Rollback;

defined( 'ABSPATH' ) || exit;

interface FieldAccessor {

	/**
	 * The backing storage key(s) for a unified field. One scalar field typically
	 * maps to a single key; a structured field (e.g. robots) may fan out.
	 *
	 * @return string[]
	 */
	public function backing_keys( string $field ): array;

	/**
	 * The current, live unified value of a field — used as the drift comparison
	 * left-hand side (compared against the recorded apply-time `after` value).
	 *
	 * @param int|string $entity_id
	 * @return mixed Scalar string for scalar fields; a structured value (e.g. array)
	 *              for structured fields.
	 */
	public function read_field( $entity_id, string $field );

	/**
	 * Whether a backing key currently exists in storage (distinguishes "absent"
	 * from "present but empty" — the existence-fidelity guarantee).
	 *
	 * @param int|string $entity_id
	 */
	public function key_exists( $entity_id, string $key ): bool;

	/**
	 * Read a backing key's raw stored value.
	 *
	 * @param int|string $entity_id
	 * @return mixed
	 */
	public function key_get( $entity_id, string $key );

	/**
	 * Write a backing key's raw value (restores a prior value, even '').
	 *
	 * @param int|string $entity_id
	 * @param mixed      $value
	 */
	public function key_set( $entity_id, string $key, $value ): void;

	/**
	 * Delete a backing key (restores prior absence).
	 *
	 * @param int|string $entity_id
	 */
	public function key_delete( $entity_id, string $key ): void;

	/**
	 * Drift comparison for a field: is the current live value equal to the recorded
	 * apply-time `after` value? Default implementations compare as strings; a runtime
	 * overrides per field type (e.g. order-insensitive set compare for robots).
	 *
	 * @param mixed $current
	 * @param mixed $after
	 */
	public function equals( string $field, $current, $after ): bool;
}
