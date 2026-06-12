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

			return [
				'count'     => $count,
				'preview'   => $preview,
				'truncated' => true,
			];
		}

		$result = [];
		foreach ( $value as $key => $item ) {
			$result[ $key ] = $this->compact( $item );
		}

		return $result;
	}
}
