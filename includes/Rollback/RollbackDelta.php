<?php
/**
 * PROGRAM-4 / P4.0 — runtime-agnostic field-scoped, drift-aware rollback core.
 *
 * Extracted verbatim (behaviour-preserving) from the SEO runtime's Phase-3 (F-1)
 * delta machinery so every runtime can reuse the same correctness without
 * re-deriving it. Operates only through a {@see FieldAccessor}; it never calls
 * WordPress directly, which makes it unit-testable with an in-memory fake accessor
 * and storage-agnostic.
 *
 * Two operations:
 *   - capture(): snapshot the prior raw value + existence of each touched field's
 *                backing keys, BEFORE the write. Captures only touched fields — never
 *                a full object (the F-1 fix).
 *   - restore(): for each touched field, compare the current live value to the
 *                recorded apply-time `after`. If they differ the field has drifted
 *                (a later change touched it) — skip it and report a conflict rather
 *                than clobber a sibling/newer change. Otherwise restore that field's
 *                backing keys to their exact prior raw value and existence
 *                (existed=false ⇒ delete; existed=true ⇒ write the prior value,
 *                even when ''). Returns a structured result; it does NOT persist the
 *                "applied" flag or audit — the caller owns terminal/idempotency and
 *                provenance, so partial/conflict stay retryable.
 *
 * Record `fields` shape (format v2, owned by the runtime's store_rollback):
 *   [ field => [ 'after' => mixed, 'keys' => [ key => [ 'existed' => bool, 'prior' => mixed ] ] ] ]
 */

namespace WPCommandCenter\Rollback;

defined( 'ABSPATH' ) || exit;

final class RollbackDelta {

	/**
	 * Snapshot the backing keys for the touched fields, each with prior existence +
	 * raw value, BEFORE the write.
	 *
	 * @param int|string $entity_id
	 * @param string[]   $touched
	 * @return array<string,array{keys:array<string,array{existed:bool,prior:mixed}>}>
	 */
	public static function capture( FieldAccessor $accessor, $entity_id, array $touched ): array {
		$out = [];
		foreach ( $touched as $field ) {
			$field = (string) $field;
			$keys  = [];
			foreach ( $accessor->backing_keys( $field ) as $key ) {
				$key          = (string) $key;
				$keys[ $key ] = [
					'existed' => $accessor->key_exists( $entity_id, $key ),
					'prior'   => $accessor->key_get( $entity_id, $key ),
				];
			}
			$out[ $field ] = [ 'keys' => $keys ];
		}
		return $out;
	}

	/**
	 * Field-scoped, drift-aware restore. Pure: returns the outcome; the caller
	 * decides terminality (mark applied only on 'complete'), audit, and the response
	 * envelope.
	 *
	 * @param int|string                $entity_id
	 * @param array<string,mixed>       $fields  The record's v2 `fields` map.
	 * @return array{status:string,restored:string[],skipped:string[],conflicts:array<int,array{field:string,reason:string,expected:mixed,current:mixed}>}
	 *         status ∈ { complete, partial, conflict }.
	 */
	public static function restore( FieldAccessor $accessor, $entity_id, array $fields ): array {
		$restored  = [];
		$skipped   = [];
		$conflicts = [];

		foreach ( $fields as $field => $spec ) {
			$field   = (string) $field;
			$after   = $spec['after'] ?? '';
			$current = $accessor->read_field( $entity_id, $field );

			if ( ! $accessor->equals( $field, $current, $after ) ) {
				$skipped[]   = $field;
				$conflicts[] = [ 'field' => $field, 'reason' => 'drift', 'expected' => $after, 'current' => $current ];
				continue;
			}

			foreach ( (array) ( $spec['keys'] ?? [] ) as $key => $meta ) {
				$key = (string) $key;
				if ( ! empty( $meta['existed'] ) ) {
					$accessor->key_set( $entity_id, $key, $meta['prior'] );
				} elseif ( $accessor->key_exists( $entity_id, $key ) ) {
					$accessor->key_delete( $entity_id, $key );
				}
			}
			$restored[] = $field;
		}

		$status = empty( $skipped ) ? 'complete' : ( empty( $restored ) ? 'conflict' : 'partial' );

		return [
			'status'    => $status,
			'restored'  => $restored,
			'skipped'   => $skipped,
			'conflicts' => $conflicts,
		];
	}
}
