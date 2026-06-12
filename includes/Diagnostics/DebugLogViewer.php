<?php
/**
 * §8.4 Debug Log Viewer — read-only access to wp-content/debug.log,
 * with an efficient tail and a level-classifier for each line.
 */

namespace WPCommandCenter\Diagnostics;

defined( 'ABSPATH' ) || exit;

final class DebugLogViewer {

	private const MAX_READ_BYTES = 5 * MB_IN_BYTES;

	public function get_log_path(): string {
		return WP_CONTENT_DIR . '/debug.log';
	}

	/**
	 * @return array{path: string, size: int, modified: int, truncated: bool, lines: array<int, array{level: string, text: string}>}|\WP_Error
	 */
	public function tail( int $lines = 200 ): array|\WP_Error {
		$path = $this->get_log_path();

		if ( ! file_exists( $path ) ) {
			return new \WP_Error( 'wpcc_no_debug_log', __( 'No debug.log file was found.', 'wp-command-center' ) );
		}

		if ( ! is_readable( $path ) ) {
			return new \WP_Error( 'wpcc_unreadable_debug_log', __( 'debug.log exists but is not readable.', 'wp-command-center' ) );
		}

		$size = filesize( $path );

		$handle = fopen( $path, 'rb' );

		if ( false === $handle ) {
			return new \WP_Error( 'wpcc_open_failed', __( 'Failed to open debug.log.', 'wp-command-center' ) );
		}

		$read_bytes = min( $size, self::MAX_READ_BYTES );

		fseek( $handle, -$read_bytes, SEEK_END );
		$contents = stream_get_contents( $handle );
		fclose( $handle );

		$truncated = $read_bytes < $size;

		$raw_lines = explode( "\n", rtrim( (string) $contents, "\n" ) );

		if ( $truncated ) {
			// The first line is likely a partial line — drop it.
			array_shift( $raw_lines );
		}

		$raw_lines = array_slice( $raw_lines, -$lines );

		return [
			'path'      => $path,
			'size'      => $size,
			'modified'  => filemtime( $path ),
			'truncated' => $truncated,
			'lines'     => array_map( [ $this, 'parse_line' ], $raw_lines ),
		];
	}

	/**
	 * Truncate the debug log file.
	 */
	public function clear(): bool|\WP_Error {
		$path = $this->get_log_path();

		if ( ! file_exists( $path ) ) {
			return new \WP_Error( 'wpcc_no_debug_log', __( 'No debug.log file was found.', 'wp-command-center' ) );
		}

		if ( ! is_writable( $path ) ) {
			return new \WP_Error( 'wpcc_unwritable_debug_log', __( 'debug.log exists but is not writable.', 'wp-command-center' ) );
		}

		return false !== file_put_contents( $path, '' );
	}

	/**
	 * @return array{level: string, text: string}
	 */
	public function parse_line( string $line ): array {
		$level = 'other';

		if ( preg_match( '/PHP Fatal error/i', $line ) ) {
			$level = 'fatal';
		} elseif ( preg_match( '/PHP Warning/i', $line ) ) {
			$level = 'warning';
		} elseif ( preg_match( '/PHP Deprecated/i', $line ) ) {
			$level = 'deprecated';
		} elseif ( preg_match( '/PHP Notice/i', $line ) ) {
			$level = 'notice';
		}

		return [
			'level' => $level,
			'text'  => $line,
		];
	}
}
