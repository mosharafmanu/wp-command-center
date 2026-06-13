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

	private const MAX_FILE_BYTES    = 2 * MB_IN_BYTES;
	private const MAX_FILES_SCANNED = 5000;
	private const DEFAULT_MAX_RESULTS = 100;

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

				foreach ( $this->search_file( $file, $query, $pattern ) as $match ) {
					$matches[] = $match;

					if ( count( $matches ) >= $max_results ) {
						break;
					}
				}
			}
		}

		return [
			'query'         => $query,
			'path'          => $relative_path,
			'type'          => $type,
			'matches'       => $matches,
			'match_count'   => count( $matches ),
			'files_scanned' => $files_scanned,
			'truncated'     => $truncated,
		];
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

			if ( $file->getSize() > self::MAX_FILE_BYTES ) {
				continue;
			}

			yield $file;
		}
	}

	/**
	 * @return array<int, array{file: string, line: int, text: string}>
	 */
	private function search_file( \SplFileInfo $file, string $query, ?string $pattern ): array {
		$matches = [];
		$handle  = fopen( $file->getPathname(), 'rb' );

		if ( false === $handle ) {
			return $matches;
		}

		$relative = ltrim( str_replace( trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ), '', wp_normalize_path( $file->getPathname() ) ), '/' );
		$line_no  = 0;

		while ( false !== ( $line = fgets( $handle ) ) ) {
			++$line_no;

			$found = null !== $pattern
				? 1 === preg_match( $pattern, $line )
				: false !== stripos( $line, $query );

			if ( $found ) {
				$matches[] = [
					'file' => $relative,
					'line' => $line_no,
					'text' => trim( $line ),
				];
			}
		}

		fclose( $handle );

		return $matches;
	}
}
