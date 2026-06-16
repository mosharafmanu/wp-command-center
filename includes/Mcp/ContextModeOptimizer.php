<?php
/**
 * Applies MCP context modes without changing the underlying operation APIs.
 */

namespace WPCommandCenter\Mcp;

defined( 'ABSPATH' ) || exit;

final class ContextModeOptimizer {

	public const COMPACT  = 'compact';
	public const STANDARD = 'standard';
	public const VERBOSE  = 'verbose';
	public const MODES    = [ self::COMPACT, self::STANDARD, self::VERBOSE ];

	private const PREVIEW_ITEMS = 5;
	private const MAX_STRING_BYTES = 500;

	public static function normalize( mixed $mode ): string {
		$mode = sanitize_key( (string) $mode );
		return in_array( $mode, self::MODES, true ) ? $mode : self::COMPACT;
	}

	public function optimize( mixed $data, string $mode = self::COMPACT ): mixed {
		$mode = self::normalize( $mode );
		if ( self::COMPACT !== $mode ) {
			return $data;
		}

		return $this->compact( $data );
	}

	private function compact( mixed $value ): mixed {
		if ( is_string( $value ) ) {
			if ( strlen( $value ) <= self::MAX_STRING_BYTES ) {
				return $value;
			}

			return substr( $value, 0, self::MAX_STRING_BYTES ) . '...';
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( array_is_list( $value ) ) {
			$count   = count( $value );
			$preview = array_map( [ $this, 'compact' ], array_slice( $value, 0, self::PREVIEW_ITEMS ) );

			if ( $count <= self::PREVIEW_ITEMS ) {
				return $preview;
			}

			// STEP 103.2 — a truncated list is wrapped in a uniform, self-describing
			// envelope so an agent can NEVER mistake a preview for the full set:
			// total_count is the real length, has_more/truncated flag the cutoff,
			// returned says how many items are present, and items[] is always a
			// JSON array. `count`/`preview` are kept as backward-compatible aliases
			// of the previous compact shape.
			return [
				'_compact_preview' => true,
				'truncated'        => true,
				'has_more'         => true,
				'total_count'      => $count,
				'returned'         => count( $preview ),
				'items'            => $preview,
				'count'            => $count,   // back-compat alias of total_count
				'preview'          => $preview, // back-compat alias of items
			];
		}

		$result = [];
		foreach ( $value as $key => $item ) {
			$result[ $key ] = $this->compact( $item );
		}

		return $result;
	}
}
