<?php
/**
 * §8.5 Patch System (AI Coding Bridge) — generates a unified diff for
 * human review. The diff is for display only; patches are applied by
 * writing the stored "modified" content directly, not by applying this
 * diff text.
 */

namespace WPCommandCenter\PatchSystem;

defined( 'ABSPATH' ) || exit;

final class DiffGenerator {

	private const CONTEXT_LINES = 3;

	/**
	 * Above this many (old lines × new lines) cells, the LCS table would
	 * use too much memory, so fall back to a whole-file replacement diff.
	 */
	private const MAX_DIFF_CELLS = 4_000_000;

	/**
	 * Generate a unified diff between two strings.
	 *
	 * @return string Empty string if the contents are identical.
	 */
	public function generate( string $original, string $modified, string $path = '' ): string {
		if ( $original === $modified ) {
			return '';
		}

		$old_lines = explode( "\n", $original );
		$new_lines = explode( "\n", $modified );

		// Trim matching lines from both ends first so the expensive LCS
		// table only has to cover the part that actually changed — most
		// edits to large files are small and localized.
		$old_count = count( $old_lines );
		$new_count = count( $new_lines );

		$prefix = 0;
		$limit  = min( $old_count, $new_count );

		while ( $prefix < $limit && $old_lines[ $prefix ] === $new_lines[ $prefix ] ) {
			++$prefix;
		}

		$suffix = 0;
		$limit  = min( $old_count, $new_count ) - $prefix;

		while ( $suffix < $limit && $old_lines[ $old_count - 1 - $suffix ] === $new_lines[ $new_count - 1 - $suffix ] ) {
			++$suffix;
		}

		$old_middle = array_slice( $old_lines, $prefix, $old_count - $prefix - $suffix );
		$new_middle = array_slice( $new_lines, $prefix, $new_count - $prefix - $suffix );

		if ( ( count( $old_middle ) + 1 ) * ( count( $new_middle ) + 1 ) > self::MAX_DIFF_CELLS ) {
			$middle_ops = $this->whole_file_ops( $old_middle, $new_middle );
		} else {
			$middle_ops = $this->diff_lines( $old_middle, $new_middle );
		}

		$ops = [];

		for ( $i = 0; $i < $prefix; $i++ ) {
			$ops[] = [ 'equal', $old_lines[ $i ] ];
		}

		array_push( $ops, ...$middle_ops );

		for ( $i = 0; $i < $suffix; $i++ ) {
			$ops[] = [ 'equal', $old_lines[ $old_count - $suffix + $i ] ];
		}

		return $this->build_unified_diff( $ops, $path );
	}

	/**
	 * Compute a line-level diff using the classic LCS backtracking
	 * algorithm.
	 *
	 * @return array<int, array{0: string, 1: string}> Ops as [type, line] where type is 'equal'|'delete'|'insert'.
	 */
	private function diff_lines( array $old_lines, array $new_lines ): array {
		$old_count = count( $old_lines );
		$new_count = count( $new_lines );

		$width = $new_count + 1;
		$table = new \SplFixedArray( ( $old_count + 1 ) * $width );

		for ( $i = $old_count - 1; $i >= 0; $i-- ) {
			for ( $j = $new_count - 1; $j >= 0; $j-- ) {
				if ( $old_lines[ $i ] === $new_lines[ $j ] ) {
					$table[ $i * $width + $j ] = $table[ ( $i + 1 ) * $width + ( $j + 1 ) ] + 1;
				} else {
					$below = $table[ ( $i + 1 ) * $width + $j ];
					$right = $table[ $i * $width + ( $j + 1 ) ];
					$table[ $i * $width + $j ] = max( $below, $right );
				}
			}
		}

		$ops = [];
		$i   = 0;
		$j   = 0;

		while ( $i < $old_count && $j < $new_count ) {
			if ( $old_lines[ $i ] === $new_lines[ $j ] ) {
				$ops[] = [ 'equal', $old_lines[ $i ] ];
				++$i;
				++$j;
			} elseif ( $table[ ( $i + 1 ) * $width + $j ] >= $table[ $i * $width + ( $j + 1 ) ] ) {
				$ops[] = [ 'delete', $old_lines[ $i ] ];
				++$i;
			} else {
				$ops[] = [ 'insert', $new_lines[ $j ] ];
				++$j;
			}
		}

		while ( $i < $old_count ) {
			$ops[] = [ 'delete', $old_lines[ $i ] ];
			++$i;
		}

		while ( $j < $new_count ) {
			$ops[] = [ 'insert', $new_lines[ $j ] ];
			++$j;
		}

		return $ops;
	}

	/**
	 * Fallback for very large files: treat the entire old content as
	 * removed and the entire new content as added.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	private function whole_file_ops( array $old_lines, array $new_lines ): array {
		$ops = [];

		foreach ( $old_lines as $line ) {
			$ops[] = [ 'delete', $line ];
		}

		foreach ( $new_lines as $line ) {
			$ops[] = [ 'insert', $line ];
		}

		return $ops;
	}

	/**
	 * @param array<int, array{0: string, 1: string}> $ops
	 */
	private function build_unified_diff( array $ops, string $path ): string {
		$total = count( $ops );

		if ( 0 === $total ) {
			return '';
		}

		$marked = array_fill( 0, $total, false );

		foreach ( $ops as $index => $op ) {
			if ( 'equal' !== $op[0] ) {
				$from = max( 0, $index - self::CONTEXT_LINES );
				$to   = min( $total - 1, $index + self::CONTEXT_LINES );

				for ( $k = $from; $k <= $to; $k++ ) {
					$marked[ $k ] = true;
				}
			}
		}

		// 1-based line numbers in the old/new files for every op.
		$old_lines_no = [];
		$new_lines_no = [];
		$old_line     = 1;
		$new_line     = 1;

		foreach ( $ops as $op ) {
			if ( 'equal' === $op[0] ) {
				$old_lines_no[] = $old_line++;
				$new_lines_no[] = $new_line++;
			} elseif ( 'delete' === $op[0] ) {
				$old_lines_no[] = $old_line++;
				$new_lines_no[] = null;
			} else {
				$old_lines_no[] = null;
				$new_lines_no[] = $new_line++;
			}
		}

		// Group marked indices into contiguous hunk ranges.
		$ranges = [];
		$start  = null;

		for ( $index = 0; $index <= $total; $index++ ) {
			$is_marked = $index < $total && $marked[ $index ];

			if ( $is_marked && null === $start ) {
				$start = $index;
			} elseif ( ! $is_marked && null !== $start ) {
				$ranges[] = [ $start, $index - 1 ];
				$start    = null;
			}
		}

		if ( empty( $ranges ) ) {
			return '';
		}

		$label  = '' !== $path ? $path : 'file';
		$output = [
			"--- a/{$label}",
			"+++ b/{$label}",
		];

		foreach ( $ranges as [ $range_start, $range_end ] ) {
			$old_start = null;
			$new_start = null;
			$old_count = 0;
			$new_count = 0;

			for ( $index = $range_start; $index <= $range_end; $index++ ) {
				if ( null !== $old_lines_no[ $index ] ) {
					$old_start ??= $old_lines_no[ $index ];
					++$old_count;
				}

				if ( null !== $new_lines_no[ $index ] ) {
					$new_start ??= $new_lines_no[ $index ];
					++$new_count;
				}
			}

			if ( null === $old_start ) {
				$old_start = $range_start > 0 ? ( $old_lines_no[ $range_start - 1 ] ?? 0 ) : 0;
			}

			if ( null === $new_start ) {
				$new_start = $range_start > 0 ? ( $new_lines_no[ $range_start - 1 ] ?? 0 ) : 0;
			}

			$output[] = sprintf( '@@ -%d,%d +%d,%d @@', $old_start, $old_count, $new_start, $new_count );

			for ( $index = $range_start; $index <= $range_end; $index++ ) {
				[ $type, $line ] = $ops[ $index ];
				$prefix          = match ( $type ) {
					'delete' => '-',
					'insert' => '+',
					default  => ' ',
				};

				$output[] = $prefix . $line;
			}
		}

		return implode( "\n", $output );
	}
}
