<?php
/**
 * §8.2 File Access API. Allowed: wp-content/themes, wp-content/plugins,
 * wp-content/mu-plugins. Blocked: wp-config.php, .htaccess, core files,
 * and any credential/secret files regardless of directory (§14.3).
 */

namespace WPCommandCenter\AiAgent;

use WPCommandCenter\Security\PathGuard;

defined( 'ABSPATH' ) || exit;

final class FileAccessApi {

	private const MAX_READ_BYTES = MB_IN_BYTES;

	/** Above this size, total_lines is not computed (would require scanning a huge file). */
	private const MAX_LINE_COUNT_BYTES = 16 * MB_IN_BYTES;

	/** Default number of lines returned in line mode when line_count is omitted. */
	private const DEFAULT_LINE_COUNT = 500;

	private PathGuard $path_guard;

	public function __construct() {
		$this->path_guard = new PathGuard();
	}

	/**
	 * Read a file's contents. Supports paginated reads so agents can inspect
	 * large live files reliably (STEP 103.0A):
	 *   - line mode: opts = { line_start, line_count, context_before, context_after }
	 *   - byte mode: opts = { byte_offset, byte_limit }
	 *   - default : first chunk (capped at 1 MB) — backward compatible.
	 * Every response carries total_bytes, total_lines (when known), returned_*,
	 * truncated, and next_line_start / next_byte_offset cursors for continuation.
	 *
	 * Path restrictions and binary-file protection are preserved in all modes.
	 *
	 * @param array<string,mixed> $opts
	 * @return array<string,mixed>|\WP_Error
	 */
	public function read( string $relative_path, array $opts = [] ): array|\WP_Error {
		$real = $this->path_guard->resolve( $relative_path );

		if ( is_wp_error( $real ) ) {
			return $real;
		}

		if ( is_dir( $real ) ) {
			return new \WP_Error( 'wpcc_is_directory', __( 'The requested path is a directory.', 'wp-command-center' ) );
		}

		if ( ! is_file( $real ) || ! is_readable( $real ) ) {
			return new \WP_Error( 'wpcc_not_readable', __( 'File not found or not readable.', 'wp-command-center' ) );
		}

		if ( $this->is_binary( $real ) ) {
			return new \WP_Error( 'wpcc_binary_file', __( 'Binary files cannot be previewed.', 'wp-command-center' ) );
		}

		$total_bytes = (int) filesize( $real );

		$base = [
			'path'        => $this->to_relative_path( $real ),
			'size'        => $total_bytes, // kept for backward compatibility
			'total_bytes' => $total_bytes,
			'total_lines' => $this->count_lines_capped( $real, $total_bytes ),
			'modified'    => filemtime( $real ),
			'extension'   => strtolower( pathinfo( $real, PATHINFO_EXTENSION ) ),
			'writable'    => is_writable( $real ),
		];

		$has_line = isset( $opts['line_start'] ) || isset( $opts['line_count'] )
			|| isset( $opts['context_before'] ) || isset( $opts['context_after'] );
		$has_byte = isset( $opts['byte_offset'] ) || isset( $opts['byte_limit'] );

		if ( $has_line ) {
			return $base + $this->read_lines( $real, $total_bytes, $opts );
		}
		if ( $has_byte ) {
			return $base + $this->read_byte_range( $real, $total_bytes, $opts );
		}

		return $base + $this->read_default_chunk( $real, $total_bytes );
	}

	/**
	 * Default first-chunk read (capped at 1 MB), with continuation cursor.
	 *
	 * @return array<string,mixed>
	 */
	private function read_default_chunk( string $real, int $total_bytes ): array {
		$read_bytes = min( $total_bytes, self::MAX_READ_BYTES );
		$contents   = $read_bytes > 0 ? (string) file_get_contents( $real, false, null, 0, $read_bytes ) : '';
		$truncated  = $read_bytes < $total_bytes;

		return [
			'mode'             => 'default',
			'truncated'        => $truncated,
			'returned_bytes'   => strlen( $contents ),
			'returned_lines'   => $this->line_count( $contents ),
			'next_byte_offset' => $truncated ? $read_bytes : null,
			'next_line_start'  => null,
			'contents'         => $contents,
		];
	}

	/**
	 * Byte-range read: opts { byte_offset, byte_limit }. The slice may begin or
	 * end mid-line; use line mode for line-aligned reads.
	 *
	 * @param array<string,mixed> $opts
	 * @return array<string,mixed>
	 */
	private function read_byte_range( string $real, int $total_bytes, array $opts ): array {
		$offset = max( 0, (int) ( $opts['byte_offset'] ?? 0 ) );
		$limit  = isset( $opts['byte_limit'] ) ? (int) $opts['byte_limit'] : self::MAX_READ_BYTES;
		$limit  = max( 1, min( $limit, self::MAX_READ_BYTES ) );

		$contents = ( $total_bytes > 0 && $offset < $total_bytes )
			? (string) file_get_contents( $real, false, null, $offset, $limit )
			: '';
		$returned  = strlen( $contents );
		$end       = $offset + $returned;
		$truncated = $end < $total_bytes;

		return [
			'mode'             => 'byte',
			'byte_offset'      => $offset,
			'returned_bytes'   => $returned,
			'returned_lines'   => $this->line_count( $contents ),
			'truncated'        => $truncated,
			'next_byte_offset' => $truncated ? $end : null,
			'next_line_start'  => null,
			'contents'         => $contents,
		];
	}

	/**
	 * Line-range read: opts { line_start (1-based), line_count, context_before,
	 * context_after }. Returns line-aligned content with a per-request byte
	 * budget (1 MB) so a file with very long lines can never exhaust memory.
	 *
	 * @param array<string,mixed> $opts
	 * @return array<string,mixed>
	 */
	private function read_lines( string $real, int $total_bytes, array $opts ): array {
		$ctx_before = max( 0, (int) ( $opts['context_before'] ?? 0 ) );
		$ctx_after  = max( 0, (int) ( $opts['context_after'] ?? 0 ) );
		$req_start  = max( 1, (int) ( $opts['line_start'] ?? 1 ) );
		$req_count  = isset( $opts['line_count'] ) ? max( 1, (int) $opts['line_count'] ) : self::DEFAULT_LINE_COUNT;

		$eff_start = max( 1, $req_start - $ctx_before );
		$eff_count = $req_count + $ctx_before + $ctx_after;
		$end_line  = $eff_start + $eff_count - 1;

		$handle = fopen( $real, 'rb' );
		if ( false === $handle ) {
			return [
				'mode'            => 'line',
				'line_start'      => $eff_start,
				'returned_lines'  => 0,
				'truncated'       => false,
				'next_line_start' => null,
				'next_byte_offset' => null,
				'contents'        => '',
			];
		}

		$line_no    = 0;
		$collected  = [];
		$bytes      = 0;
		$truncated  = false;

		while ( false !== ( $line = fgets( $handle ) ) ) {
			++$line_no;

			if ( $line_no < $eff_start ) {
				continue;
			}
			if ( $line_no > $end_line ) {
				$truncated = true; // more lines exist past the requested window
				break;
			}

			$bytes += strlen( $line );
			if ( $bytes > self::MAX_READ_BYTES && ! empty( $collected ) ) {
				$truncated = true; // byte budget hit before the window ended
				break;
			}

			$collected[] = $line;
		}

		fclose( $handle );

		$returned_lines = count( $collected );
		$next_line_start = $truncated ? ( $eff_start + $returned_lines ) : null;

		return [
			'mode'             => 'line',
			'line_start'       => $eff_start,
			'requested_line_start' => $req_start,
			'line_count'       => $req_count,
			'context_before'   => $ctx_before,
			'context_after'    => $ctx_after,
			'returned_lines'   => $returned_lines,
			'returned_bytes'   => $bytes,
			'truncated'        => $truncated,
			'next_line_start'  => $next_line_start,
			'next_byte_offset' => null,
			'contents'         => implode( '', $collected ),
		];
	}

	/** Lines in a string: 0 for empty, +1 for a final line without a trailing newline. */
	private function line_count( string $text ): int {
		if ( '' === $text ) {
			return 0;
		}
		$count = substr_count( $text, "\n" );
		if ( "\n" !== substr( $text, -1 ) ) {
			++$count;
		}
		return $count;
	}

	/**
	 * Count total lines without loading the whole file, skipping files large
	 * enough that the scan isn't worth it (returns null = unknown).
	 */
	private function count_lines_capped( string $real, int $total_bytes ): ?int {
		if ( 0 === $total_bytes ) {
			return 0;
		}
		if ( $total_bytes > self::MAX_LINE_COUNT_BYTES ) {
			return null;
		}

		$handle = fopen( $real, 'rb' );
		if ( false === $handle ) {
			return null;
		}

		$count = 0;
		$last  = "\n";
		while ( ! feof( $handle ) ) {
			$chunk = fread( $handle, 1 << 16 );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$count += substr_count( $chunk, "\n" );
			$last   = substr( $chunk, -1 );
		}
		fclose( $handle );

		if ( "\n" !== $last ) {
			++$count; // final line without a trailing newline
		}

		return $count;
	}

	/**
	 * Lightweight metadata for a single file — size, modified time,
	 * writability, and a SHA-1 hash of its contents (computed without
	 * loading the file into memory, so it works for any file size).
	 *
	 * @return array{path: string, size: int, modified: int, writable: bool, hash: string}|\WP_Error
	 */
	public function meta( string $relative_path ): array|\WP_Error {
		$real = $this->path_guard->resolve( $relative_path );

		if ( is_wp_error( $real ) ) {
			return $real;
		}

		if ( is_dir( $real ) ) {
			return new \WP_Error( 'wpcc_is_directory', __( 'The requested path is a directory.', 'wp-command-center' ) );
		}

		if ( ! is_file( $real ) || ! is_readable( $real ) ) {
			return new \WP_Error( 'wpcc_not_readable', __( 'File not found or not readable.', 'wp-command-center' ) );
		}

		return [
			'path'     => $this->to_relative_path( $real ),
			'size'     => filesize( $real ),
			'modified' => filemtime( $real ),
			'writable' => is_writable( $real ),
			'hash'     => (string) hash_file( 'sha1', $real ),
		];
	}

	/**
	 * List the contents of a directory. An empty path lists the allowed
	 * top-level roots (themes, plugins, mu-plugins).
	 *
	 * @return array{path: string, parent: ?string, entries: array<int, array{name: string, path: string, type: string, size: ?int, modified: int, extension: string}>}|\WP_Error
	 */
	public function list_directory( string $relative_path = '' ): array|\WP_Error {
		$relative_path = trim( str_replace( '\\', '/', $relative_path ), '/' );

		if ( '' === $relative_path ) {
			$entries = [];

			foreach ( $this->path_guard->get_allowed_root_paths() as $root_path ) {
				$entries[] = $this->build_entry( $root_path );
			}

			return [
				'path'    => '',
				'parent'  => null,
				'entries' => $entries,
			];
		}

		$real = $this->path_guard->resolve( $relative_path );

		if ( is_wp_error( $real ) ) {
			return $real;
		}

		if ( ! is_dir( $real ) ) {
			return new \WP_Error( 'wpcc_not_a_directory', __( 'The requested path is not a directory.', 'wp-command-center' ) );
		}

		$entries = [];

		foreach ( scandir( $real ) ?: [] as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}

			$child = trailingslashit( $real ) . $name;

			if ( $this->path_guard->is_denied( $child ) ) {
				continue;
			}

			$entries[] = $this->build_entry( $child );
		}

		usort(
			$entries,
			static function ( array $a, array $b ): int {
				if ( $a['type'] !== $b['type'] ) {
					return 'dir' === $a['type'] ? -1 : 1;
				}

				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		$parent = strpos( $relative_path, '/' ) !== false ? dirname( $relative_path ) : '';

		return [
			'path'    => $relative_path,
			'parent'  => $parent,
			'entries' => $entries,
		];
	}

	/**
	 * @return array{name: string, path: string, type: string, size: ?int, modified: int, extension: string}
	 */
	private function build_entry( string $real_path ): array {
		$is_dir = is_dir( $real_path );

		return [
			'name'      => basename( $real_path ),
			'path'      => $this->to_relative_path( $real_path ),
			'type'      => $is_dir ? 'dir' : 'file',
			'size'      => $is_dir ? null : filesize( $real_path ),
			'modified'  => filemtime( $real_path ),
			'extension' => $is_dir ? '' : strtolower( pathinfo( $real_path, PATHINFO_EXTENSION ) ),
		];
	}

	private function to_relative_path( string $real_path ): string {
		return ltrim( str_replace( trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ), '', $real_path ), '/' );
	}

	private function is_binary( string $path ): bool {
		$sample = file_get_contents( $path, false, null, 0, 8192 );

		return false !== $sample && str_contains( $sample, "\0" );
	}
}
