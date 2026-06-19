<?php
/**
 * STEP 111 (S2.2.1) — stateless selection contract.
 *
 * A value object describing WHAT the operator wants to act on, in one of two
 * shapes:
 *   - by = ids       → an explicit, enumerated id set (the small / manual case).
 *   - by = criteria  → a filter snapshot ("everything matching {filters}"),
 *                      resolved server-side at action time into a bounded id set.
 *
 * It is STATELESS and READ-ONLY: it is never persisted, never a selection table,
 * never an authority. It only carries intent; SelectionResolver turns it into a
 * bounded, capability-scoped id set by reading the existing list sources. No
 * schema, no writes, no new operation/capability/MCP tool.
 */

namespace WPCommandCenter\Admin;

defined( 'ABSPATH' ) || exit;

final class SelectionContract {

	public const BY_IDS      = 'ids';
	public const BY_CRITERIA = 'criteria';

	/** Absolute ceiling on the requested cap, independent of caller input. */
	public const HARD_CAP = 100;

	private string $by;
	/** @var array<int,string> */
	private array $ids;
	/** @var array<string,string> */
	private array $filters;
	private int $cap;

	/**
	 * @param array<int,string>    $ids
	 * @param array<string,string> $filters
	 */
	private function __construct( string $by, array $ids, array $filters, int $cap ) {
		$this->by      = $by;
		$this->ids     = $ids;
		$this->filters = $filters;
		$this->cap     = $cap;
	}

	/**
	 * Build (and validate) a contract from a loosely-typed input array.
	 *
	 * @param array<string,mixed> $a { by, ids?, filters?, cap? }
	 * @return self|\WP_Error WP_Error when `by` is not a recognised shape.
	 */
	public static function from_array( array $a ): self|\WP_Error {
		$by = (string) ( $a['by'] ?? self::BY_CRITERIA );
		if ( ! in_array( $by, [ self::BY_IDS, self::BY_CRITERIA ], true ) ) {
			return new \WP_Error( 'wpcc_selection_bad_by', __( 'Selection "by" must be "ids" or "criteria".', 'wp-command-center' ) );
		}

		// Cap: clamp caller input into [1, HARD_CAP]; default to the hard cap.
		$cap = isset( $a['cap'] ) ? (int) $a['cap'] : self::HARD_CAP;
		$cap = max( 1, min( self::HARD_CAP, $cap ) );

		// Ids: sanitize to non-empty strings, de-duped (used only when by=ids).
		$ids = [];
		foreach ( (array) ( $a['ids'] ?? [] ) as $id ) {
			$id = (string) $id;
			if ( '' !== $id ) {
				$ids[] = $id;
			}
		}
		$ids = array_values( array_unique( $ids ) );

		// Filters: a plain string map (the snapshot). Whitelisting happens in the
		// resolver; here we only coerce to string values.
		$filters = [];
		foreach ( (array) ( $a['filters'] ?? [] ) as $k => $v ) {
			if ( is_string( $k ) ) {
				$filters[ $k ] = (string) $v;
			}
		}

		return new self( $by, $ids, $filters, $cap );
	}

	public function by(): string {
		return $this->by;
	}

	/** @return array<int,string> */
	public function ids(): array {
		return $this->ids;
	}

	/** @return array<string,string> */
	public function filters(): array {
		return $this->filters;
	}

	public function cap(): int {
		return $this->cap;
	}
}
