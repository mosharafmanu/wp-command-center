<?php
/**
 * STEP 105.2 — Shared unified-diff renderer (admin presentation layer).
 *
 * The single renderer for patch diffs across wp-admin. Both the Patches detail
 * view and the Change History detail view render through this class — there is
 * NO second/forked diff renderer. It is read-only and produces escaped HTML
 * only (file content is untrusted and is always passed through esc_html()).
 *
 * Output model:
 *   - render_summary(): compact header (files changed / additions / deletions
 *     / affected file list).
 *   - render_accordion(): per-file collapsible <details> blocks; large diffs
 *     are truncated with a notice (no giant single-page block).
 *
 * It does not read the database or filesystem; callers pass the already-loaded
 * patch `files` array ([ [ 'path' => string, 'diff' => string ], ... ]).
 */

namespace WPCommandCenter\Admin;

defined( 'ABSPATH' ) || exit;

final class DiffRenderer {

	/** Per-file line cap before truncation. */
	private const MAX_LINES = 600;

	/**
	 * Summarise a set of file diffs.
	 *
	 * @param array<int,array<string,mixed>> $files
	 * @return array{files_changed:int,additions:int,deletions:int,files:array<int,array<string,mixed>>}
	 */
	public static function summarize( array $files ): array {
		$additions = 0;
		$deletions = 0;
		$list      = [];

		foreach ( $files as $file ) {
			$path = (string) ( $file['path'] ?? '' );
			$diff = (string) ( $file['diff'] ?? '' );
			$add  = 0;
			$del  = 0;

			foreach ( explode( "\n", $diff ) as $line ) {
				if ( str_starts_with( $line, '+++' ) || str_starts_with( $line, '---' ) ) {
					continue;
				}
				if ( str_starts_with( $line, '+' ) ) {
					$add++;
				} elseif ( str_starts_with( $line, '-' ) ) {
					$del++;
				}
			}

			$additions += $add;
			$deletions += $del;
			$list[]     = [ 'path' => $path, 'additions' => $add, 'deletions' => $del ];
		}

		return [
			'files_changed' => count( $files ),
			'additions'     => $additions,
			'deletions'     => $deletions,
			'files'         => $list,
		];
	}

	/**
	 * Render the compact summary header.
	 *
	 * @param array<string,mixed> $summary Output of summarize().
	 */
	public static function render_summary( array $summary ): string {
		$files_changed = (int) ( $summary['files_changed'] ?? 0 );
		$additions     = (int) ( $summary['additions'] ?? 0 );
		$deletions     = (int) ( $summary['deletions'] ?? 0 );

		$html  = '<div class="wpcc-diff-summary">';
		$html .= '<span class="wpcc-diff-stat">' . esc_html( sprintf(
			/* translators: %d: number of files */
			_n( '%d file changed', '%d files changed', $files_changed, 'wp-command-center' ),
			$files_changed
		) ) . '</span> ';
		$html .= '<span class="wpcc-diff-stat wpcc-diff-add">+' . esc_html( (string) $additions ) . '</span> ';
		$html .= '<span class="wpcc-diff-stat wpcc-diff-del">-' . esc_html( (string) $deletions ) . '</span>';

		$files = is_array( $summary['files'] ?? null ) ? $summary['files'] : [];
		if ( ! empty( $files ) ) {
			$html .= '<ul class="wpcc-diff-filelist">';
			foreach ( $files as $f ) {
				$html .= '<li><code>' . esc_html( (string) ( $f['path'] ?? '' ) ) . '</code> '
					. '<span class="wpcc-diff-add">+' . esc_html( (string) ( $f['additions'] ?? 0 ) ) . '</span> '
					. '<span class="wpcc-diff-del">-' . esc_html( (string) ( $f['deletions'] ?? 0 ) ) . '</span></li>';
			}
			$html .= '</ul>';
		}

		$html .= '</div>';
		return $html;
	}

	/**
	 * Render one file's unified diff as an escaped <pre> block, truncating very
	 * large diffs with a notice.
	 */
	public static function render_file_diff( string $diff ): string {
		if ( '' === trim( $diff ) ) {
			return '<p class="description">' . esc_html__( 'No textual changes.', 'wp-command-center' ) . '</p>';
		}

		$lines     = explode( "\n", $diff );
		$total     = count( $lines );
		$truncated = false;
		if ( $total > self::MAX_LINES ) {
			$lines     = array_slice( $lines, 0, self::MAX_LINES );
			$truncated = true;
		}

		$html = '<pre class="wpcc-diff">';
		foreach ( $lines as $line ) {
			$class = 'wpcc-diff-line';
			if ( str_starts_with( $line, '+++' ) || str_starts_with( $line, '---' ) ) {
				$class .= ' wpcc-diff-line--header';
			} elseif ( str_starts_with( $line, '@@' ) ) {
				$class .= ' wpcc-diff-line--hunk';
			} elseif ( str_starts_with( $line, '+' ) ) {
				$class .= ' wpcc-diff-line--add';
			} elseif ( str_starts_with( $line, '-' ) ) {
				$class .= ' wpcc-diff-line--del';
			}
			$html .= sprintf( "<span class=\"%s\">%s</span>\n", esc_attr( $class ), esc_html( $line ) );
		}
		$html .= '</pre>';

		if ( $truncated ) {
			$html .= '<p class="description wpcc-diff-truncated">' . esc_html( sprintf(
				/* translators: %d: number of hidden lines */
				_n( 'Diff truncated — %d more line not shown.', 'Diff truncated — %d more lines not shown.', $total - self::MAX_LINES, 'wp-command-center' ),
				$total - self::MAX_LINES
			) ) . '</p>';
		}

		return $html;
	}

	/**
	 * Render a per-file collapsible accordion (with the summary header on top).
	 *
	 * @param array<int,array<string,mixed>> $files [ [ 'path' => string, 'diff' => string ], ... ]
	 * @param bool                           $open  Whether each file starts expanded.
	 */
	public static function render_accordion( array $files, bool $open = false ): string {
		if ( empty( $files ) ) {
			return '<p class="description">' . esc_html__( 'No textual changes.', 'wp-command-center' ) . '</p>';
		}

		$summary = self::summarize( $files );
		$html    = self::render_summary( $summary );

		$open_attr = $open ? ' open' : '';
		foreach ( $files as $file ) {
			$path = (string) ( $file['path'] ?? '' );
			$diff = (string) ( $file['diff'] ?? '' );

			// Per-file +/- recomputed for the summary line (cheap, single file).
			$one = self::summarize( [ $file ] );

			$html .= '<details class="wpcc-diff-file"' . $open_attr . '>';
			$html .= '<summary><code>' . esc_html( $path ) . '</code> '
				. '<span class="wpcc-diff-add">+' . esc_html( (string) $one['additions'] ) . '</span> '
				. '<span class="wpcc-diff-del">-' . esc_html( (string) $one['deletions'] ) . '</span></summary>';
			$html .= self::render_file_diff( $diff );
			$html .= '</details>';
		}

		return $html;
	}
}
