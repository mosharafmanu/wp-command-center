<?php
/**
 * PROGRAM-4.7 — postmeta-per-record {@see RollbackStore} (the storage keystone).
 *
 * Generalizes SEO's proven inline pattern (SeoRuntimeManager: one protected post-meta row
 * per rollback, keyed `_wpcc_seo_rb_{id}`) into a reusable, runtime-neutral store. Each
 * rollback record is its OWN post-meta row whose meta_key encodes the rollback_id, so:
 *   - resolution by rollback_id alone is O(1) on the core wp_postmeta `meta_key` index;
 *   - there is NO FIFO cap / silent eviction (every record is an independent row);
 *   - the rows are NOT autoloaded (unlike the wpcc_*_rollbacks options) — no per-request cost;
 *   - records are garbage-collected with their post (meta cascades on delete);
 *   - writes are single-row inserts/updates — no whole-option rewrite or last-writer-wins clobber.
 *
 * Schema-free: uses the existing wp_postmeta table and its built-in meta_key index — no new
 * table, no column, no DB_VERSION change. Post-bound only (entity_id is a post id); global /
 * non-post entities keep the option/keyed stores.
 *
 * This is the storage foundation for P4.8 (Bulk per-item), P4.9 (ACF), P4.10 (Elementor).
 * It is record-shape-agnostic (legacy full-object or v2 delta) — exactly like the option
 * stores — and implements the existing {@see RollbackStore} interface unchanged.
 */

namespace WPCommandCenter\Rollback;

defined( 'ABSPATH' ) || exit;

final class PostMetaRollbackStore implements RollbackStore {

	/**
	 * Meta-key prefix identifying the consuming runtime, e.g. `_wpcc_bulk_rb_`. MUST start
	 * with `_` so the rows are protected meta (hidden from the Custom Fields UI / REST meta).
	 * The full meta_key is `{$prefix}{$rollback_id}`.
	 */
	private string $prefix;

	public function __construct( string $prefix ) {
		// Defensively force the protected-meta leading underscore; never silently emit a
		// public meta row for a rollback record.
		$this->prefix = ( '' !== $prefix && '_' === $prefix[0] ) ? $prefix : '_' . $prefix;
	}

	/**
	 * Persist a rollback record as a unique post-meta row keyed by the rollback_id.
	 *
	 * @param int|string          $entity_id post id the record attaches to
	 * @param array<string,mixed> $record
	 */
	public function persist( $entity_id, string $rollback_id, array $record ): void {
		$post_id = (int) $entity_id;
		if ( $post_id <= 0 || '' === $rollback_id ) {
			return;
		}
		// unique=true → exactly one row per (post, rollback_id); a UUID collision is rejected
		// rather than duplicated, leaving the original intact.
		add_post_meta( $post_id, $this->meta_key( $rollback_id ), $record, true );
	}

	/**
	 * Resolve a record by rollback_id alone — one indexed meta_key lookup, no post id needed.
	 *
	 * @return array{entity_id:int,record:array<string,mixed>}|null null when absent or malformed.
	 */
	public function resolve( string $rollback_id ): ?array {
		if ( '' === $rollback_id ) {
			return null;
		}
		global $wpdb;
		$meta_key = $this->meta_key( $rollback_id );
		$post_id  = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1", $meta_key )
		);
		if ( $post_id <= 0 ) {
			return null;
		}
		$record = get_post_meta( $post_id, $meta_key, true );
		if ( ! is_array( $record ) ) {
			// Defensive: a missing/corrupted/non-array value is "not found", never a fatal.
			return null;
		}
		return [ 'entity_id' => $post_id, 'record' => $record ];
	}

	/**
	 * Write the (already-mutated) record back as applied. Caller sets rollback_applied=true.
	 *
	 * @param int|string          $entity_id post id
	 * @param array<string,mixed> $record
	 */
	public function mark_applied( $entity_id, string $rollback_id, array $record ): void {
		$post_id = (int) $entity_id;
		if ( $post_id <= 0 || '' === $rollback_id ) {
			return;
		}
		update_post_meta( $post_id, $this->meta_key( $rollback_id ), $record );
	}

	private function meta_key( string $rollback_id ): string {
		return $this->prefix . $rollback_id;
	}
}
