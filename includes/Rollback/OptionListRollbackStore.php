<?php
/**
 * PROGRAM-4B (D3) — list-in-an-option {@see RollbackStore}.
 *
 * The record array is a flat list; resolution scans for `$r['id'] === $rollback_id`; new
 * records append with a FIFO cap. Matches the existing on-disk format of Settings, Media,
 * Comments, and User (options wpcc_*_rollbacks) — backward-compatible, no migration.
 */

namespace WPCommandCenter\Rollback;

defined( 'ABSPATH' ) || exit;

final class OptionListRollbackStore implements RollbackStore {

	private string $option;
	private int $cap;

	public function __construct( string $option, int $cap = 100 ) {
		$this->option = $option;
		$this->cap    = $cap;
	}

	/**
	 * @param int|string          $entity_id
	 * @param array<string,mixed> $record
	 */
	public function persist( $entity_id, string $rollback_id, array $record ): void {
		$records   = (array) get_option( $this->option, [] );
		$records[] = $record;
		if ( count( $records ) > $this->cap ) {
			$records = array_slice( $records, -$this->cap );
		}
		update_option( $this->option, $records );
	}

	public function resolve( string $rollback_id ): ?array {
		$records = (array) get_option( $this->option, [] );
		foreach ( $records as $r ) {
			if ( ( $r['id'] ?? null ) === $rollback_id ) {
				// entity_id is carried inside the record by the runtime; expose it generically
				// from whichever *_id key the record uses (post/media/comment/user/content).
				return [ 'entity_id' => self::entity_id_of( $r ), 'record' => $r ];
			}
		}
		return null;
	}

	/**
	 * @param int|string          $entity_id
	 * @param array<string,mixed> $record
	 */
	public function mark_applied( $entity_id, string $rollback_id, array $record ): void {
		$records = (array) get_option( $this->option, [] );
		foreach ( $records as $i => $r ) {
			if ( ( $r['id'] ?? null ) === $rollback_id ) {
				$records[ $i ] = $record;
				break;
			}
		}
		update_option( $this->option, $records );
	}

	/**
	 * Best-effort entity id from a record's *_id key (excluding the rollback id).
	 *
	 * @param array<string,mixed> $r
	 * @return int|string
	 */
	private static function entity_id_of( array $r ) {
		foreach ( [ 'post_id', 'media_id', 'comment_id', 'user_id', 'content_id', 'entity_id' ] as $k ) {
			if ( isset( $r[ $k ] ) ) {
				return $r[ $k ];
			}
		}
		return 0;
	}
}
