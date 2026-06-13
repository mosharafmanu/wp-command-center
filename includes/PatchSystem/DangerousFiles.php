<?php
/**
 * STEP 87 — Dangerous file classifier.
 *
 * Identifies files whose modification can take a site down (white screen),
 * so a patch touching them requires explicit confirmation in every security
 * mode. Path-based and deterministic — no file reads, no side effects.
 *
 * Dangerous:
 *   - any theme functions.php
 *   - any file inside the ACTIVE theme (template/style/functions)
 *   - a plugin main file (plugins/{slug}/{slug}.php convention)
 *
 * Note: wp-config.php and .htaccess are blocked outright by PathGuard and can
 * never reach the patch engine, so they are not classified here.
 */

namespace WPCommandCenter\PatchSystem;

defined( 'ABSPATH' ) || exit;

final class DangerousFiles {

	/**
	 * Whether a single relative path (relative to wp-content) is high-risk.
	 */
	public static function is_dangerous_path( string $relative_path ): bool {
		$path = strtolower( ltrim( str_replace( '\\', '/', $relative_path ), '/' ) );

		if ( '' === $path ) {
			return false;
		}

		// Any theme functions.php.
		if ( str_ends_with( $path, '/functions.php' ) || 'functions.php' === basename( $path ) ) {
			if ( str_starts_with( $path, 'themes/' ) ) {
				return true;
			}
		}

		// Any file within the active theme (or its parent template).
		foreach ( self::active_theme_dirs() as $dir ) {
			if ( '' !== $dir && str_starts_with( $path, 'themes/' . $dir . '/' ) ) {
				return true;
			}
		}

		// Plugin main file: plugins/{slug}/{slug}.php
		if ( preg_match( '#^plugins/([^/]+)/([^/]+)\.php$#', $path, $m ) && $m[1] === $m[2] ) {
			return true;
		}

		return false;
	}

	/**
	 * Return the dangerous file paths within a stored patch record, given its id.
	 * Loads the patch via PatchManager; returns [] if the patch can't be read.
	 *
	 * @return string[] Relative paths considered dangerous.
	 */
	public static function dangerous_in_patch( string $patch_id ): array {
		if ( '' === $patch_id ) {
			return [];
		}

		$patch = ( new PatchManager() )->get( $patch_id );

		if ( is_wp_error( $patch ) || empty( $patch['files'] ) ) {
			return [];
		}

		$dangerous = [];

		foreach ( $patch['files'] as $file ) {
			$path = (string) ( $file['path'] ?? '' );
			if ( '' !== $path && self::is_dangerous_path( $path ) ) {
				$dangerous[] = $path;
			}
		}

		return $dangerous;
	}

	/**
	 * Active theme stylesheet + template directory names (child + parent).
	 *
	 * @return string[]
	 */
	private static function active_theme_dirs(): array {
		if ( ! function_exists( 'wp_get_theme' ) ) {
			return [];
		}

		$theme = wp_get_theme();

		if ( ! $theme || ! $theme->exists() ) {
			return [];
		}

		return array_values( array_unique( array_filter( [
			strtolower( (string) $theme->get_stylesheet() ),
			strtolower( (string) $theme->get_template() ),
		] ) ) );
	}
}
