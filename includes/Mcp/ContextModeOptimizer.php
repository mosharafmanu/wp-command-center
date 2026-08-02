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
		/*
		 * Strings are returned whole, deliberately.
		 *
		 * Compact mode used to cut every string over 500 bytes down to
		 * `substr( $value, 0, 500 ) . '...'`. A truncated LIST is wrapped in the
		 * self-describing envelope below so an agent can never mistake a preview
		 * for the full set — but a truncated STRING got no marker at all, and the
		 * sibling metadata describing it was left untouched and therefore lying.
		 *
		 * file_read on a 1442-byte, 68-line theme file returned
		 * `truncated: false, returned_bytes: 1442, returned_lines: 68` alongside
		 * 503 bytes of content. Feeding that back into patch_manage — the
		 * documented read-then-patch workflow — produced a whole-file patch of
		 * +2/-46 lines: it would have deleted 46 of the 68 lines of a real
		 * customer's theme file, with both the assistant and the site owner told
		 * the read was complete. compact is the default context mode in every
		 * client configuration this plugin generates, so this was the default path.
		 *
		 * Operations that return large strings already carry their own explicit
		 * truncation contract (total_bytes / returned_bytes / next_byte_offset /
		 * truncated) and page properly. Transport-level trimming did not cooperate
		 * with that contract, it invalidated it. Context savings come from
		 * previewing long lists, which is preserved below.
		 */
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
