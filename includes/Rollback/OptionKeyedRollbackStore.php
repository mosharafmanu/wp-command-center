<?php
/**
 * PROGRAM-4B (D3) — keyed-option {@see RollbackStore}.
 *
 * The records array is keyed by rollback_id (`$records[$id]`), giving O(1) resolution and
 * natural overwrite-on-same-id. Matches the existing on-disk format of Content
 * (option wpcc_content_rollbacks) — backward-compatible, no migration.
 */

namespace WPCommandCenter\Rollback;

defined( 'ABSPATH' ) || exit;

final class OptionKeyedRollbackStore implements RollbackStore {

	private string $option;

	public function __construct( string $option ) {
		$this->option = $option;
	}

	/**
	 * @param int|string          $entity_id
	 * @param array<string,mixed> $record
	 */
	public function persist( $entity_id, string $rollback_id, array $record ): void {
		$records                 = (array) get_option( $this->option, [] );
		$records[ $rollback_id ] = $record;
		update_option( $this->option, $records );
	}

	public function resolve( string $rollback_id ): ?array {
		$records = (array) get_option( $this->option, [] );
		if ( ! isset( $records[ $rollback_id ] ) ) {
			return null;
		}
		$r = $records[ $rollback_id ];
		return [ 'entity_id' => $r['content_id'] ?? ( $r['entity_id'] ?? 0 ), 'record' => $r ];
	}

	/**
	 * @param int|string          $entity_id
	 * @param array<string,mixed> $record
	 */
	public function mark_applied( $entity_id, string $rollback_id, array $record ): void {
		$records = (array) get_option( $this->option, [] );
		if ( isset( $records[ $rollback_id ] ) ) {
			$records[ $rollback_id ] = $record;
			update_option( $this->option, $records );
		}
	}
}
