<?php
/**
 * §8.3 AI Terminal (virtual) — natural-language code search across
 * allowed directories. Read-only; not a real shell.
 */

namespace WPCommandCenter\AiAgent;

use WPCommandCenter\Security\PathGuard;

defined( 'ABSPATH' ) || exit;

final class CodeSearch {

	private const SEARCHABLE_EXTENSIONS = [
		'php', 'js', 'jsx', 'ts', 'tsx', 'css', 'scss', 'less',
		'html', 'htm', 'twig', 'json', 'txt', 'md', 'xml', 'yml', 'yaml',
	];

	// STEP 103.0A — raised so large CSS/JS/PHP (well past 250 KB) are searched,
	// not silently skipped. Files still larger than this are reported as skipped
	// with a structured reason rather than vanishing from the results.
	private const MAX_FILE_BYTES    = 8 * MB_IN_BYTES;
	private const MAX_FILES_SCANNED = 5000;
	private const DEFAULT_MAX_RESULTS = 100;

	/** Lines of context suggested around each match for the search-to-read bridge. */
	private const READ_HINT_CONTEXT = 5;

	/** Cap how many skipped-file records are returned (the count is always exact). */
	private const MAX_SKIPPED_REPORTED = 100;

	/**
	 * Supported values for the `type` argument. `text` is a plain
	 * case-insensitive substring match (the original behavior); the
	 * others match a regular expression built from the query.
	 */
	private const VALID_TYPES = [ 'text', 'function', 'class', 'hook' ];

	private PathGuard $path_guard;

	public function __construct() {
		$this->path_guard = new PathGuard();
	}

	/**
	 * @param array{path?: string, max_results?: int, type?: string} $args
	 * @return array{query: string, path: string, type: string, matches: array<int, array{file: string, line: int, text: string}>, match_count: int, files_scanned: int, truncated: bool}|\WP_Error
	 */
	public function search( string $query, array $args = [] ): array|\WP_Error {
		$query = trim( $query );

		if ( '' === $query ) {
			return new \WP_Error( 'wpcc_empty_query', __( 'Please enter a search term.', 'wp-command-center' ) );
		}

		$relative_path = isset( $args['path'] ) ? trim( str_replace( '\\', '/', (string) $args['path'] ), '/' ) : '';
		$max_results   = isset( $args['max_results'] ) ? max( 1, (int) $args['max_results'] ) : self::DEFAULT_MAX_RESULTS;
		$type          = isset( $args['type'] ) && '' !== $args['type'] ? sanitize_key( (string) $args['type'] ) : 'text';

		if ( ! in_array( $type, self::VALID_TYPES, true ) ) {
			return new \WP_Error( 'wpcc_invalid_type', __( 'Invalid search type. Use text, function, class, or hook.', 'wp-command-center' ) );
		}

		$roots = $this->resolve_search_roots( $relative_path );

		if ( is_wp_error( $roots ) ) {
			return $roots;
		}

		$pattern = $this->build_pattern( $type, $query );

		$matches        = [];
		$files_searched = 0;
		$skipped        = [];
		$per_file_count = [];
		$truncated      = false;

		foreach ( $roots as $root ) {
			foreach ( $this->iterate_files( $root ) as $file ) {
				if ( ( $files_searched + count( $skipped ) ) >= self::MAX_FILES_SCANNED || count( $matches ) >= $max_results ) {
					$truncated = true;
					break 2;
				}

				$rel  = $this->relative_of( $file->getPathname() );
				$size = (int) $file->getSize();

				// Report — never silently drop — files we can't search.
				$reason = $this->skip_reason( $file, $size );
				if ( null !== $reason ) {
					if ( count( $skipped ) < self::MAX_SKIPPED_REPORTED ) {
						$skipped[] = [ 'file' => $rel, 'reason' => $reason, 'size_bytes' => $size ];
					} else {
						$skipped[] = [ 'file' => $rel, 'reason' => $reason, 'size_bytes' => $size, '_overflow' => true ];
					}
					continue;
				}

				++$files_searched;

				foreach ( $this->search_file( $file, $rel, $size, $query, $pattern ) as $match ) {
					$matches[] = $match;
					$per_file_count[ $rel ] = ( $per_file_count[ $rel ] ?? 0 ) + 1;

					if ( count( $matches ) >= $max_results ) {
						$truncated = true;
						break;
					}
				}
			}
		}

		// Trim the reported skip list to the cap (count stays exact below).
		$skipped_total    = count( $skipped );
		$skipped_reported = array_slice( $skipped, 0, self::MAX_SKIPPED_REPORTED );

		$matched_files = [];
		foreach ( $per_file_count as $f => $c ) {
			$matched_files[] = [ 'file' => $f, 'match_count' => $c ];
		}

		return [
			'query'          => $query,
			'path'           => $relative_path,
			'type'           => $type,
			'matches'        => $matches,
			'match_count'    => count( $matches ),
			'matched_files'  => $matched_files,
			'files_searched' => $files_searched,
			'files_skipped'  => $skipped_total,
			'skipped'        => $skipped_reported,
			// True only when every candidate file was searched and no result cap hit,
			// so an empty result is trustworthy rather than possibly-incomplete.
			'complete'       => 0 === $skipped_total && ! $truncated,
			'truncated'      => $truncated,
			// Back-compat alias for the previous field name.
			'files_scanned'  => $files_searched,
		];
	}

	/**
	 * Why a candidate file can't be searched, or null if it can. Makes size /
	 * binary / permission skips visible instead of silently returning 0 matches.
	 */
	private function skip_reason( \SplFileInfo $file, int $size ): ?string {
		if ( $size > self::MAX_FILE_BYTES ) {
			return 'too_large';
		}
		if ( ! $file->isReadable() ) {
			return 'unreadable';
		}
		if ( $this->is_binary( $file->getPathname() ) ) {
			return 'binary';
		}
		return null;
	}

	private function is_binary( string $path ): bool {
		$sample = file_get_contents( $path, false, null, 0, 8192 );
		return false !== $sample && str_contains( $sample, "\0" );
	}

	private function relative_of( string $pathname ): string {
		return ltrim( str_replace( trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ), '', wp_normalize_path( $pathname ) ), '/' );
	}

	/**
	 * Build the regular expression used to match a line for a given search
	 * `type`, or null for `text` (which uses a plain `stripos` match).
	 */
	private function build_pattern( string $type, string $query ): ?string {
		$quoted = preg_quote( $query, '/' );

		return match ( $type ) {
			'function' => '/function\s+' . $quoted . '\s*\(/i',
			'class'    => '/class\s+' . $quoted . '\b/i',
			'hook'     => '/(add_action|add_filter|do_action|apply_filters)\s*\(\s*[\'"]' . $quoted . '[\'"]/i',
			default    => null,
		};
	}

	/**
	 * Find files whose name (or relative path) matches a query substring.
	 * Reuses the same allow-listed roots and deny-list filtering as search().
	 * STEP 87 — backs the code_search/search_file operation.
	 *
	 * @return array{query:string,path:string,matches:array<int,array{file:string,name:string}>,match_count:int,files_scanned:int,truncated:bool}|\WP_Error
	 */
	public function find_files( string $query, array $args = [] ): array|\WP_Error {
		$query = trim( $query );

		if ( '' === $query ) {
			return new \WP_Error( 'wpcc_empty_query', __( 'Please enter a search term.', 'wp-command-center' ) );
		}

		$relative_path = isset( $args['path'] ) ? trim( str_replace( '\\', '/', (string) $args['path'] ), '/' ) : '';
		$max_results   = isset( $args['max_results'] ) ? max( 1, (int) $args['max_results'] ) : self::DEFAULT_MAX_RESULTS;

		$roots = $this->resolve_search_roots( $relative_path );

		if ( is_wp_error( $roots ) ) {
			return $roots;
		}

		$needle        = strtolower( $query );
		$matches       = [];
		$files_scanned = 0;
		$truncated     = false;

		foreach ( $roots as $root ) {
			foreach ( $this->iterate_files( $root ) as $file ) {
				if ( $files_scanned >= self::MAX_FILES_SCANNED || count( $matches ) >= $max_results ) {
					$truncated = true;
					break 2;
				}

				++$files_scanned;

				$relative = ltrim( str_replace( trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ), '', wp_normalize_path( $file->getPathname() ) ), '/' );

				if ( false !== stripos( $relative, $needle ) ) {
					$matches[] = [ 'file' => $relative, 'name' => $file->getFilename() ];
				}
			}
		}

		return [
			'query'         => $query,
			'path'          => $relative_path,
			'matches'       => $matches,
			'match_count'   => count( $matches ),
			'files_scanned' => $files_scanned,
			'truncated'     => $truncated,
		];
	}

	/**
	 * @return array<int, string>|\WP_Error Absolute root directories to search.
	 */
	private function resolve_search_roots( string $relative_path ): array|\WP_Error {
		if ( '' === $relative_path ) {
			return $this->path_guard->get_allowed_root_paths();
		}

		$real = $this->path_guard->resolve( $relative_path );

		if ( is_wp_error( $real ) ) {
			return $real;
		}

		if ( ! is_dir( $real ) ) {
			return new \WP_Error( 'wpcc_not_a_directory', __( 'The search path must be a directory.', 'wp-command-center' ) );
		}

		return [ $real ];
	}

	/**
	 * @return \Generator<\SplFileInfo>
	 */
	private function iterate_files( string $root ): \Generator {
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			if ( $this->path_guard->is_denied( wp_normalize_path( $file->getPathname() ) ) ) {
				continue;
			}

			if ( ! in_array( strtolower( $file->getExtension() ), self::SEARCHABLE_EXTENSIONS, true ) ) {
				continue;
			}

			// Note: size / binary / readability are NOT filtered here anymore — they
			// are evaluated in search() so every skip is reported, not silent.
			yield $file;
		}
	}

	/**
	 * @return array<int, array{file: string, line: int, line_number: int, text: string, file_size_bytes: int, read_hint: array{path: string, line_start: int, line_count: int}}>
	 */
	private function search_file( \SplFileInfo $file, string $relative, int $size, string $query, ?string $pattern ): array {
		$matches = [];
		$handle  = fopen( $file->getPathname(), 'rb' );

		if ( false === $handle ) {
			return $matches;
		}

		$line_no = 0;

		while ( false !== ( $line = fgets( $handle ) ) ) {
			++$line_no;

			$found = null !== $pattern
				? 1 === preg_match( $pattern, $line )
				: false !== stripos( $line, $query );

			if ( $found ) {
				$matches[] = [
					'file'            => $relative,
					'line'            => $line_no,
					'line_number'     => $line_no,
					'text'            => trim( $line ),
					'file_size_bytes' => $size,
					// Search-to-read bridge: feed these straight into file_read to
					// inspect the match with surrounding context.
					'read_hint'       => [
						'path'       => $relative,
						'line_start' => max( 1, $line_no - self::READ_HINT_CONTEXT ),
						'line_count' => ( self::READ_HINT_CONTEXT * 2 ) + 1,
					],
				];
			}
		}

		fclose( $handle );

		return $matches;
	}
}
