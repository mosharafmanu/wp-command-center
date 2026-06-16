<?php
/**
 * §8.5 Patch System — precise patch-mode resolver.
 *
 * Resolves a per-file patch entry expressed in any supported mode into the
 * single full-file "modified" body that the rest of the Patch Engine operates
 * on. Centralizing the resolution here is what keeps every downstream safety
 * guarantee unchanged — snapshot-before-write, PHP syntax verification,
 * PatchGuard header protection, and snapshot-based rollback all continue to
 * receive a complete file body, no matter how compactly the agent expressed the
 * edit (a 4-line append never has to ship the whole file).
 *
 * It also makes the contract explicit: every entry's fields are validated
 * against the chosen mode, and any unrecognized field is rejected with a
 * structured WP_Error instead of being silently ignored (which previously made
 * a mistyped content field look like a request to wipe the file).
 *
 * Modes:
 *   whole_file    — replace the entire file.            Field: modified
 *   append        — add text to the end of the file.    Field: content
 *   prepend       — add text to the start of the file.  Field: content
 *   replace_text  — replace a literal substring.        Fields: find, replace, count?
 *   replace_range — replace an inclusive 1-based line range. Fields: start_line, end_line, content
 *   unified_diff  — apply a unified diff.               Field: diff
 */

namespace WPCommandCenter\PatchSystem;

defined( 'ABSPATH' ) || exit;

final class PatchModeResolver {

	public const MODE_WHOLE_FILE    = 'whole_file';
	public const MODE_APPEND        = 'append';
	public const MODE_PREPEND       = 'prepend';
	public const MODE_REPLACE_TEXT  = 'replace_text';
	public const MODE_REPLACE_RANGE = 'replace_range';
	public const MODE_UNIFIED_DIFF  = 'unified_diff';

	public const MODES = [
		self::MODE_WHOLE_FILE,
		self::MODE_APPEND,
		self::MODE_PREPEND,
		self::MODE_REPLACE_TEXT,
		self::MODE_REPLACE_RANGE,
		self::MODE_UNIFIED_DIFF,
	];

	/** Fields allowed on every entry regardless of mode. */
	private const COMMON_FIELDS = [ 'path', 'mode' ];

	/** Mode-specific allowed fields (in addition to COMMON_FIELDS). */
	private const MODE_FIELDS = [
		self::MODE_WHOLE_FILE    => [ 'modified' ],
		self::MODE_APPEND        => [ 'content' ],
		self::MODE_PREPEND       => [ 'content' ],
		self::MODE_REPLACE_TEXT  => [ 'find', 'replace', 'count' ],
		self::MODE_REPLACE_RANGE => [ 'start_line', 'end_line', 'content' ],
		self::MODE_UNIFIED_DIFF  => [ 'diff' ],
	];

	/**
	 * Resolve a raw file entry against the current on-disk content.
	 *
	 * @param array<string,mixed> $file     Raw entry: { path, mode?, ...mode fields }.
	 * @param string              $original Current on-disk file content.
	 *
	 * @return array{modified:string,mode:string,meta:array<string,mixed>}|\WP_Error
	 */
	public static function resolve( array $file, string $original ): array|\WP_Error {
		$mode = self::determine_mode( $file );
		if ( is_wp_error( $mode ) ) {
			return $mode;
		}

		$unknown = self::unknown_fields( $file, $mode );
		if ( is_wp_error( $unknown ) ) {
			return $unknown;
		}

		return match ( $mode ) {
			self::MODE_WHOLE_FILE    => self::resolve_whole_file( $file ),
			self::MODE_APPEND        => self::resolve_append( $file, $original ),
			self::MODE_PREPEND       => self::resolve_prepend( $file, $original ),
			self::MODE_REPLACE_TEXT  => self::resolve_replace_text( $file, $original ),
			self::MODE_REPLACE_RANGE => self::resolve_replace_range( $file, $original ),
			self::MODE_UNIFIED_DIFF  => self::resolve_unified_diff( $file, $original ),
		};
	}

	/**
	 * Decide which mode an entry uses, inferring whole_file when only `modified`
	 * is present, and returning a structured, agent-readable error otherwise.
	 *
	 * @param array<string,mixed> $file
	 *
	 * @return string|\WP_Error
	 */
	private static function determine_mode( array $file ): string|\WP_Error {
		$mode = isset( $file['mode'] ) ? (string) $file['mode'] : '';

		if ( '' === $mode ) {
			if ( array_key_exists( 'modified', $file ) ) {
				return self::MODE_WHOLE_FILE;
			}
			if ( array_key_exists( 'content', $file ) ) {
				return new \WP_Error(
					'wpcc_unknown_patch_field',
					__( "The 'content' field requires an explicit 'mode' (append, prepend, or replace_range). For a full-file replacement, set mode='whole_file' and use the 'modified' field instead of 'content'.", 'wp-command-center' )
				);
			}
			if ( array_key_exists( 'diff', $file ) ) {
				return new \WP_Error(
					'wpcc_unknown_patch_field',
					__( "The 'diff' field requires mode='unified_diff'.", 'wp-command-center' )
				);
			}

			return new \WP_Error(
				'wpcc_missing_patch_mode',
				sprintf(
					/* translators: %s: list of valid modes */
					__( "Specify a 'mode' (%s), or provide the 'modified' field for a whole-file replacement.", 'wp-command-center' ),
					implode( ', ', self::MODES )
				)
			);
		}

		if ( ! in_array( $mode, self::MODES, true ) ) {
			return new \WP_Error(
				'wpcc_invalid_patch_mode',
				sprintf(
					/* translators: 1: supplied mode, 2: list of valid modes */
					__( "Invalid patch mode '%1\$s'. Valid modes: %2\$s.", 'wp-command-center' ),
					$mode,
					implode( ', ', self::MODES )
				)
			);
		}

		return $mode;
	}

	/**
	 * Reject any field that is not part of the chosen mode's contract, with a
	 * targeted hint for the most common mistake (content vs. modified).
	 *
	 * @param array<string,mixed> $file
	 *
	 * @return null|\WP_Error
	 */
	private static function unknown_fields( array $file, string $mode ): ?\WP_Error {
		$allowed = array_merge( self::COMMON_FIELDS, self::MODE_FIELDS[ $mode ] );
		$unknown = [];

		foreach ( array_keys( $file ) as $key ) {
			if ( is_int( $key ) || in_array( $key, $allowed, true ) ) {
				continue;
			}
			$unknown[] = (string) $key;
		}

		if ( empty( $unknown ) ) {
			return null;
		}

		$hint = '';
		if ( self::MODE_WHOLE_FILE === $mode && in_array( 'content', $unknown, true ) ) {
			$hint = __( " Whole-file replacement uses 'modified', not 'content'.", 'wp-command-center' );
		}

		return new \WP_Error(
			'wpcc_unknown_patch_field',
			sprintf(
				/* translators: 1: unknown field names, 2: mode, 3: allowed field names, 4: optional hint */
				__( "Unknown field(s) for mode '%2\$s': %1\$s. Allowed fields: %3\$s.%4\$s", 'wp-command-center' ),
				implode( ', ', $unknown ),
				$mode,
				implode( ', ', $allowed ),
				$hint
			)
		);
	}

	private static function resolve_whole_file( array $file ): array|\WP_Error {
		if ( ! array_key_exists( 'modified', $file ) ) {
			return new \WP_Error(
				'wpcc_missing_patch_field',
				__( "Mode 'whole_file' requires the 'modified' field (the full new file content).", 'wp-command-center' )
			);
		}

		$modified = (string) $file['modified'];

		return [
			'modified' => $modified,
			'mode'     => self::MODE_WHOLE_FILE,
			'meta'     => [ 'summary' => __( 'Replaces the entire file.', 'wp-command-center' ) ],
		];
	}

	private static function resolve_append( array $file, string $original ): array|\WP_Error {
		if ( ! array_key_exists( 'content', $file ) ) {
			return new \WP_Error( 'wpcc_missing_patch_field', __( "Mode 'append' requires the 'content' field.", 'wp-command-center' ) );
		}

		$content    = (string) $file['content'];
		$had_eof_nl = str_ends_with( $original, "\n" );
		$modified   = $original;
		if ( '' !== $original && ! $had_eof_nl ) {
			$modified .= "\n";
		}
		$modified .= $content;
		// Preserve the file's trailing newline so an append reads as a clean
		// insert of new line(s) rather than a rewrite of the final line.
		if ( $had_eof_nl && '' !== $content && ! str_ends_with( $modified, "\n" ) ) {
			$modified .= "\n";
		}

		return [
			'modified' => $modified,
			'mode'     => self::MODE_APPEND,
			'meta'     => [
				'summary'      => sprintf(
					/* translators: %d: number of lines appended */
					__( 'Appends %d line(s) to the end of the file.', 'wp-command-center' ),
					self::line_count( $content )
				),
				'lines_added' => self::line_count( $content ),
			],
		];
	}

	private static function resolve_prepend( array $file, string $original ): array|\WP_Error {
		if ( ! array_key_exists( 'content', $file ) ) {
			return new \WP_Error( 'wpcc_missing_patch_field', __( "Mode 'prepend' requires the 'content' field.", 'wp-command-center' ) );
		}

		$content  = (string) $file['content'];
		$modified = $content;
		if ( '' !== $content && ! str_ends_with( $content, "\n" ) ) {
			$modified .= "\n";
		}
		$modified .= $original;

		return [
			'modified' => $modified,
			'mode'     => self::MODE_PREPEND,
			'meta'     => [
				'summary'     => sprintf(
					/* translators: %d: number of lines prepended */
					__( 'Prepends %d line(s) to the start of the file.', 'wp-command-center' ),
					self::line_count( $content )
				),
				'lines_added' => self::line_count( $content ),
			],
		];
	}

	private static function resolve_replace_text( array $file, string $original ): array|\WP_Error {
		if ( ! array_key_exists( 'find', $file ) || '' === (string) $file['find'] ) {
			return new \WP_Error( 'wpcc_missing_patch_field', __( "Mode 'replace_text' requires a non-empty 'find' field.", 'wp-command-center' ) );
		}
		if ( ! array_key_exists( 'replace', $file ) ) {
			return new \WP_Error( 'wpcc_missing_patch_field', __( "Mode 'replace_text' requires the 'replace' field (may be an empty string to delete the text).", 'wp-command-center' ) );
		}

		$find    = (string) $file['find'];
		$replace = (string) $file['replace'];

		$total = substr_count( $original, $find );
		if ( 0 === $total ) {
			return new \WP_Error(
				'wpcc_patch_text_not_found',
				sprintf(
					/* translators: %s: the search text (truncated) */
					__( "The text to replace was not found in the file: \"%s\".", 'wp-command-center' ),
					self::truncate( $find )
				)
			);
		}

		if ( array_key_exists( 'count', $file ) ) {
			$count = (int) $file['count'];
			if ( $count < 1 ) {
				return new \WP_Error( 'wpcc_invalid_patch_field', __( "'count' must be a positive integer.", 'wp-command-center' ) );
			}
			// explode with a limit splits on at most $count delimiters, leaving the
			// remainder intact, so imploding with $replace swaps exactly the first N.
			$modified = implode( $replace, explode( $find, $original, $count + 1 ) );
			$changed  = min( $count, $total );
		} else {
			$modified = str_replace( $find, $replace, $original );
			$changed  = $total;
		}

		return [
			'modified' => $modified,
			'mode'     => self::MODE_REPLACE_TEXT,
			'meta'     => [
				'summary'              => sprintf(
					/* translators: 1: occurrences changed, 2: total occurrences found */
					__( 'Replaces %1$d of %2$d occurrence(s) of the target text.', 'wp-command-center' ),
					$changed,
					$total
				),
				'occurrences_found'    => $total,
				'occurrences_changed'  => $changed,
			],
		];
	}

	private static function resolve_replace_range( array $file, string $original ): array|\WP_Error {
		foreach ( [ 'start_line', 'end_line', 'content' ] as $required ) {
			if ( ! array_key_exists( $required, $file ) ) {
				return new \WP_Error(
					'wpcc_missing_patch_field',
					sprintf(
						/* translators: %s: field name */
						__( "Mode 'replace_range' requires the '%s' field.", 'wp-command-center' ),
						$required
					)
				);
			}
		}

		$start = (int) $file['start_line'];
		$end   = (int) $file['end_line'];
		$lines = explode( "\n", $original );
		$total = count( $lines );

		if ( $start < 1 || $end < $start || $end > $total ) {
			return new \WP_Error(
				'wpcc_patch_range_invalid',
				sprintf(
					/* translators: 1: start line, 2: end line, 3: total lines */
					__( 'Invalid line range %1$d-%2$d: the file has %3$d line(s). Lines are 1-based and the range is inclusive.', 'wp-command-center' ),
					$start,
					$end,
					$total
				)
			);
		}

		$content      = (string) $file['content'];
		$replacement  = '' === $content ? [] : explode( "\n", $content );
		$new_lines    = array_merge(
			array_slice( $lines, 0, $start - 1 ),
			$replacement,
			array_slice( $lines, $end )
		);
		$modified     = implode( "\n", $new_lines );

		return [
			'modified' => $modified,
			'mode'     => self::MODE_REPLACE_RANGE,
			'meta'     => [
				'summary'        => sprintf(
					/* translators: 1: start line, 2: end line, 3: replacement line count */
					__( 'Replaces lines %1$d-%2$d with %3$d line(s).', 'wp-command-center' ),
					$start,
					$end,
					count( $replacement )
				),
				'lines_removed'  => $end - $start + 1,
				'lines_added'    => count( $replacement ),
			],
		];
	}

	private static function resolve_unified_diff( array $file, string $original ): array|\WP_Error {
		if ( ! array_key_exists( 'diff', $file ) || '' === (string) $file['diff'] ) {
			return new \WP_Error( 'wpcc_missing_patch_field', __( "Mode 'unified_diff' requires a non-empty 'diff' field.", 'wp-command-center' ) );
		}

		$result = self::apply_unified_diff( $original, (string) $file['diff'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return [
			'modified' => $result['modified'],
			'mode'     => self::MODE_UNIFIED_DIFF,
			'meta'     => [
				'summary'        => sprintf(
					/* translators: %d: number of hunks applied */
					__( 'Applies a unified diff (%d hunk(s)).', 'wp-command-center' ),
					$result['hunks']
				),
				'hunks_applied'  => $result['hunks'],
			],
		];
	}

	/**
	 * Apply a unified diff to a string, line by line. Tolerant of the exact
	 * @@ header line numbers but strict about context/removed lines matching the
	 * source, so a stale or malformed diff fails loudly rather than corrupting
	 * the file.
	 *
	 * @return array{modified:string,hunks:int}|\WP_Error
	 */
	public static function apply_unified_diff( string $original, string $diff ): array|\WP_Error {
		$orig_lines = explode( "\n", $original );

		// Normalize line endings of the DIFF TEXT ONLY (the source file is left
		// byte-for-byte intact) so a CRLF-encoded diff matches an LF source.
		$diff       = str_replace( "\r\n", "\n", $diff );
		$diff_lines = explode( "\n", $diff );

		// A unified diff almost always ends with a newline, which explode() turns
		// into a trailing empty element. That terminator is not a content line —
		// dropping it prevents it from being treated as a spurious empty context
		// line (which would falsely reject a valid patch when the source file has
		// no final newline). A genuine trailing blank context/added line is still
		// preserved because it is carried by its own " "/"+" marker line before
		// this terminator.
		if ( ! empty( $diff_lines ) && '' === $diff_lines[ count( $diff_lines ) - 1 ] ) {
			array_pop( $diff_lines );
		}

		$result = [];
		$cursor = 0; // 0-based index into $orig_lines.
		$hunks  = 0;
		$in_hunk = false;

		$total = count( $diff_lines );
		for ( $i = 0; $i < $total; $i++ ) {
			$line = $diff_lines[ $i ];

			// File headers and "no newline" markers are metadata only.
			if ( str_starts_with( $line, '--- ' ) || str_starts_with( $line, '+++ ' ) || str_starts_with( $line, '\\' ) ) {
				continue;
			}

			if ( str_starts_with( $line, '@@' ) ) {
				if ( ! preg_match( '/^@@ -(\d+)(?:,\d+)? \+\d+(?:,\d+)? @@/', $line, $m ) ) {
					return new \WP_Error( 'wpcc_patch_diff_failed', __( 'Malformed hunk header in unified diff.', 'wp-command-center' ) );
				}
				$old_start = (int) $m[1];
				$target    = max( 0, $old_start - 1 );

				if ( $target < $cursor ) {
					return new \WP_Error( 'wpcc_patch_diff_failed', __( 'Overlapping or out-of-order hunks in unified diff.', 'wp-command-center' ) );
				}
				if ( $target > count( $orig_lines ) ) {
					return new \WP_Error( 'wpcc_patch_diff_failed', __( 'A hunk starts beyond the end of the file.', 'wp-command-center' ) );
				}

				// Copy untouched lines up to the hunk start.
				for ( ; $cursor < $target; $cursor++ ) {
					$result[] = $orig_lines[ $cursor ];
				}

				++$hunks;
				$in_hunk = true;
				continue;
			}

			if ( ! $in_hunk ) {
				// Ignore any preamble before the first hunk.
				continue;
			}

			$marker = '' === $line ? ' ' : $line[0];
			$text   = '' === $line ? '' : substr( $line, 1 );

			if ( ' ' === $marker ) {
				if ( ! isset( $orig_lines[ $cursor ] ) || $orig_lines[ $cursor ] !== $text ) {
					return new \WP_Error( 'wpcc_patch_diff_failed', sprintf(
						/* translators: %d: line number */
						__( 'Context line %d does not match the file; the diff may be stale.', 'wp-command-center' ),
						$cursor + 1
					) );
				}
				$result[] = $text;
				++$cursor;
			} elseif ( '-' === $marker ) {
				if ( ! isset( $orig_lines[ $cursor ] ) || $orig_lines[ $cursor ] !== $text ) {
					return new \WP_Error( 'wpcc_patch_diff_failed', sprintf(
						/* translators: %d: line number */
						__( 'Removed line %d does not match the file; the diff may be stale.', 'wp-command-center' ),
						$cursor + 1
					) );
				}
				++$cursor;
			} elseif ( '+' === $marker ) {
				$result[] = $text;
			} else {
				return new \WP_Error( 'wpcc_patch_diff_failed', __( 'Unrecognized line in unified diff hunk.', 'wp-command-center' ) );
			}
		}

		if ( 0 === $hunks ) {
			return new \WP_Error( 'wpcc_patch_diff_failed', __( 'The unified diff contained no hunks.', 'wp-command-center' ) );
		}

		// Copy any remaining untouched lines.
		for ( ; $cursor < count( $orig_lines ); $cursor++ ) {
			$result[] = $orig_lines[ $cursor ];
		}

		return [ 'modified' => implode( "\n", $result ), 'hunks' => $hunks ];
	}

	private static function line_count( string $text ): int {
		if ( '' === $text ) {
			return 0;
		}
		return substr_count( $text, "\n" ) + 1;
	}

	private static function truncate( string $text, int $limit = 80 ): string {
		$text = str_replace( "\n", '\\n', $text );
		return strlen( $text ) > $limit ? substr( $text, 0, $limit ) . '…' : $text;
	}
}
