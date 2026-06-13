<?php
/**
 * §8.2 / §14.3 — File Access allow-list and deny-pattern enforcement.
 * Allowed roots: wp-content/themes, wp-content/plugins, wp-content/mu-plugins.
 * Blocked regardless of directory: wp-config.php, .htaccess, VCS/dependency
 * directories, and common credential/secret file patterns.
 */

namespace WPCommandCenter\Security;

defined( 'ABSPATH' ) || exit;

final class PathGuard {

	/**
	 * Top-level wp-content directories the File Access API may read from.
	 */
	public const ALLOWED_ROOTS = [ 'themes', 'plugins', 'mu-plugins' ];

	/**
	 * Path segment patterns that are blocked anywhere in the path,
	 * regardless of the allowed root they live under.
	 */
	private const DENY_NAME_PATTERNS = [
		'/^wp-config(-sample)?\.php$/i',
		'/^\.htaccess$/i',
		'/^\.env(\..*)?$/i',
		'/^\.git$/i',
		'/^\.svn$/i',
		'/^node_modules$/i',
		'/^vendor$/i',
		'/\.(pem|key|p12|pfx|crt|cer)$/i',
		'/^id_rsa$/i',
		'/^id_ed25519$/i',
		'/credentials/i',
		'/secrets?\./i',
		'/(^|-)auth\.json$/i',
		'/^service-account\.json$/i',
	];

	/**
	 * Resolve and validate a path relative to wp-content/, returning the
	 * real absolute path on success.
	 */
	public function resolve( string $relative_path ): string|\WP_Error {
		$relative_path = $this->normalize_relative( $relative_path );

		if ( '' === $relative_path || str_contains( $relative_path, '..' ) ) {
			return new \WP_Error( 'wpcc_invalid_path', __( 'Invalid path.', 'wp-command-center' ) );
		}

		$absolute = trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) . $relative_path;
		$real     = realpath( $absolute );

		if ( false === $real ) {
			return new \WP_Error( 'wpcc_not_found', __( 'The requested path does not exist.', 'wp-command-center' ) );
		}

		$real = wp_normalize_path( $real );

		if ( ! $this->is_within_allowed_root( $real ) ) {
			return new \WP_Error( 'wpcc_path_not_allowed', __( 'This path is outside the allowed directories (themes, plugins, mu-plugins).', 'wp-command-center' ) );
		}

		if ( $this->is_denied( $real ) ) {
			return new \WP_Error( 'wpcc_file_blocked', __( 'Access to this path is blocked for security reasons.', 'wp-command-center' ) );
		}

		return $real;
	}

	/**
	 * Normalize a caller-supplied path to the wp-content-relative form the rest
	 * of the file API expects (e.g. "themes/foo/functions.php").
	 *
	 * Forgiving by design so AI agents and REST callers don't have to guess the
	 * exact root: it accepts an absolute path under wp-content or the WP root, a
	 * leading "wp-content/" prefix, and stray leading slashes. This only changes
	 * the accepted *spelling* of the input — resolve() still realpaths the result
	 * and enforces the allowed-root and deny-pattern checks, so it cannot widen
	 * what is reachable.
	 */
	public function normalize_relative( string $path ): string {
		// Normalize separators but keep any leading slash so absolute paths can
		// be compared against WP_CONTENT_DIR / ABSPATH (both have a leading slash).
		$norm = wp_normalize_path( str_replace( '\\', '/', $path ) );

		if ( '' === trim( $norm, '/' ) ) {
			return '';
		}

		$content = untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
		$abspath = untrailingslashit( wp_normalize_path( ABSPATH ) );

		// Absolute path under wp-content/ → strip to the relative remainder.
		if ( str_starts_with( $norm . '/', $content . '/' ) ) {
			$path = substr( $norm, strlen( $content ) );
		} elseif ( str_starts_with( $norm . '/', $abspath . '/' ) ) {
			// Absolute path under the WP root → strip the root (wp-content/ is
			// then stripped below).
			$path = substr( $norm, strlen( $abspath ) );
		} else {
			$path = $norm;
		}

		$path = trim( $path, '/' );

		// Relative path that still carries a leading wp-content/ prefix.
		if ( 0 === stripos( $path, 'wp-content/' ) ) {
			$path = substr( $path, strlen( 'wp-content/' ) );
		}

		return trim( $path, '/' );
	}

	/**
	 * Absolute, real paths of the allowed roots that exist on this install.
	 *
	 * @return array<int, string>
	 */
	public function get_allowed_root_paths(): array {
		$content_dir = trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
		$roots       = [];

		foreach ( self::ALLOWED_ROOTS as $root ) {
			$path = $content_dir . $root;

			if ( is_dir( $path ) ) {
				$roots[] = wp_normalize_path( realpath( $path ) );
			}
		}

		return $roots;
	}

	/**
	 * Whether any path segment (file or directory name) along $real_path
	 * matches a deny pattern.
	 */
	public function is_denied( string $real_path ): bool {
		$relative = ltrim( str_replace( trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ), '', $real_path ), '/' );

		foreach ( explode( '/', $relative ) as $segment ) {
			foreach ( self::DENY_NAME_PATTERNS as $pattern ) {
				if ( preg_match( $pattern, $segment ) ) {
					return true;
				}
			}
		}

		return false;
	}

	private function is_within_allowed_root( string $real_path ): bool {
		foreach ( $this->get_allowed_root_paths() as $root_path ) {
			if ( $real_path === $root_path || str_starts_with( $real_path . '/', $root_path . '/' ) ) {
				return true;
			}
		}

		return false;
	}
}
