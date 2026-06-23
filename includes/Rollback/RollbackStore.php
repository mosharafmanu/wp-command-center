<?php
/**
 * PROGRAM-4B (D3) — rollback record store abstraction.
 *
 * Decouples a runtime's rollback persistence/resolution from its on-disk layout so the
 * field-scoped delta runtimes share one consistent storage API instead of hand-rolling
 * option access. Implementations preserve each runtime's existing on-disk format (no
 * migration, no schema/DB_VERSION change):
 *   - {@see OptionListRollbackStore}  option holding a list of records (find by id, FIFO cap)
 *   - {@see OptionKeyedRollbackStore} option keyed by rollback_id
 * SEO keeps its own postmeta-per-record store (reference impl) and is not migrated here.
 *
 * A "record" is the runtime's rollback array (legacy full-object or v2 delta); the store is
 * shape-agnostic — it persists, resolves by rollback_id, and marks-applied.
 */

namespace WPCommandCenter\Rollback;

defined( 'ABSPATH' ) || exit;

interface RollbackStore {

	/**
	 * Persist a rollback record under its rollback_id for the given entity.
	 *
	 * @param int|string          $entity_id
	 * @param array<string,mixed> $record
	 */
	public function persist( $entity_id, string $rollback_id, array $record ): void;

	/**
	 * Resolve a record by rollback_id.
	 *
	 * @return array{entity_id:mixed,record:array<string,mixed>}|null null when not found.
	 */
	public function resolve( string $rollback_id ): ?array;

	/**
	 * Persist the (already-mutated) record back as applied — the caller sets
	 * rollback_applied=true on the record; the store writes it through.
	 *
	 * @param int|string          $entity_id
	 * @param array<string,mixed> $record
	 */
	public function mark_applied( $entity_id, string $rollback_id, array $record ): void;
}
