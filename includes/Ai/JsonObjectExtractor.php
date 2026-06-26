<?php
/**
 * Phase B — Universal AI Provider Runtime: tolerant JSON-object extraction.
 *
 * Shared, provider-agnostic helper for reading a JSON object out of model text.
 * Accepts a bare JSON object, a ```json fenced block, or JSON embedded in prose
 * (first "{" … last "}"). Returns the decoded associative array, or null when no
 * object can be recovered — it never fabricates.
 *
 * Extracted (extract-on-second-use) from the duplicated decode logic that lived
 * in the SEO and Content providers. Feature providers keep their own key-shape
 * validation; only the decode dance is shared here.
 *
 * Strict boundaries — this class NEVER performs I/O and is pure/stateless.
 */

namespace WPCommandCenter\Ai;

defined( 'ABSPATH' ) || exit;

final class JsonObjectExtractor {

	/**
	 * Decode a JSON object from model text. Tries the bare string first, then the
	 * first "{" … last "}" span (covering ```json fences and JSON-in-prose).
	 *
	 * @return array<string,mixed>|null
	 */
	public static function to_array( string $text ): ?array {
		$text = trim( $text );
		if ( '' === $text ) {
			return null;
		}

		$decoded = json_decode( $text, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );
		if ( false === $start || false === $end || $end <= $start ) {
			return null;
		}

		$decoded = json_decode( substr( $text, $start, $end - $start + 1 ), true );

		return is_array( $decoded ) ? $decoded : null;
	}
}
