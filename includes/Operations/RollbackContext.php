<?php
/**
 * STEP 102 — Shared rollback-id surfacing.
 *
 * Root cause addressed (STEP 101.3 findings F-2 / F-3): runtime managers store a
 * rollback record (always in an option named `wpcc_<runtime>_rollbacks`) but most
 * write methods do not echo the generated rollback_id back to the caller, and there
 * is no per-runtime discovery list — so the documented rollback routes are not
 * driveable from the public API.
 *
 * Rather than editing the return array of every write method in every manager
 * (15+ managers, dozens of call sites), this collector hooks the single common
 * chokepoint every store_rollback() passes through: update_option()/add_option()
 * on a `wpcc_*_rollbacks` option. It diffs the old vs. new option value, captures
 * the newly stored rollback id, and exposes it to OperationExecutor, which injects
 * `rollback_id` + `rollback_available` into the normalized response uniformly.
 *
 * Handles both stored shapes:
 *   - list:  [ ['id'=>uuid, ...], ... ]            (Menu, ACF, User, Woo, Settings, …)
 *   - assoc: [ uuid => [ ... ], ... ]              (Content)
 *
 * Marking a rollback "applied" rewrites the same option but adds no new id, so the
 * diff is empty and nothing is captured — rollback responses are never polluted.
 */

namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class RollbackContext {

	/** @var string|null Most-recent rollback id stored during the current operation run. */
	private static ?string $last = null;

	/** @var bool Whether the capture hooks have been registered. */
	private static bool $booted = false;

	/**
	 * Register the global option-write capture hooks once.
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_action( 'updated_option', [ self::class, 'on_updated' ], 10, 3 );
		add_action( 'added_option', [ self::class, 'on_added' ], 10, 2 );
	}

	/**
	 * Clear the captured id at the start of each operation run so a stale id from a
	 * previous request can never be surfaced.
	 */
	public static function reset(): void {
		self::$last = null;
	}

	/**
	 * The rollback id stored during the current run, if any.
	 */
	public static function last(): ?string {
		return self::$last;
	}

	/**
	 * @param string $option
	 * @param mixed  $old_value
	 * @param mixed  $value
	 */
	public static function on_updated( $option, $old_value, $value ): void {
		self::capture( $option, $old_value, $value );
	}

	/**
	 * @param string $option
	 * @param mixed  $value
	 */
	public static function on_added( $option, $value ): void {
		self::capture( $option, [], $value );
	}

	/**
	 * Capture the newly stored rollback id from a `wpcc_*_rollbacks` option write.
	 *
	 * @param mixed $option
	 * @param mixed $old_value
	 * @param mixed $value
	 */
	private static function capture( $option, $old_value, $value ): void {
		if ( ! is_string( $option ) || 0 !== strpos( $option, 'wpcc_' ) || ! self::is_rollbacks_option( $option ) ) {
			return;
		}
		if ( ! is_array( $value ) ) {
			return;
		}

		$old_ids = self::ids( is_array( $old_value ) ? $old_value : [] );
		$new_ids = self::ids( $value );
		$added   = array_values( array_diff( $new_ids, $old_ids ) );

		if ( ! empty( $added ) ) {
			self::$last = (string) end( $added );
		}
	}

	private static function is_rollbacks_option( string $option ): bool {
		return (bool) preg_match( '/^wpcc_[a-z0-9_]*rollbacks$/', $option );
	}

	/**
	 * Extract the set of rollback ids from either stored shape.
	 *
	 * @param array $records
	 * @return string[]
	 */
	private static function ids( array $records ): array {
		$ids = [];
		foreach ( $records as $key => $rec ) {
			if ( is_array( $rec ) && isset( $rec['id'] ) && '' !== (string) $rec['id'] ) {
				$ids[] = (string) $rec['id'];      // list shape
			} elseif ( is_string( $key ) && '' !== $key ) {
				$ids[] = $key;                      // assoc-keyed shape (Content)
			}
		}
		return $ids;
	}
}
