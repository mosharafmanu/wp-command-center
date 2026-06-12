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

	private PathGuard $path_guard;

	public function __construct() {
		$this->path_guard = new PathGuard();
	}

	/**
	 * Read a file's contents (capped at 1 MB, with a truncated flag).
	 *
	 * @return array{path: string, size: int, modified: int, extension: string, writable: bool, truncated: bool, contents: string}|\WP_Error
	 */
	public function read( string $relative_path ): array|\WP_Error {
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

		$size       = filesize( $real );
		$read_bytes = min( $size, self::MAX_READ_BYTES );

		return [
			'path'      => $this->to_relative_path( $real ),
			'size'      => $size,
			'modified'  => filemtime( $real ),
			'extension' => strtolower( pathinfo( $real, PATHINFO_EXTENSION ) ),
			'writable'  => is_writable( $real ),
			'truncated' => $read_bytes < $size,
			'contents'  => $read_bytes > 0 ? (string) file_get_contents( $real, false, null, 0, $read_bytes ) : '',
		];
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
