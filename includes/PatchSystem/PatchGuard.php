<?php
/**
 * Patch Header Guard.
 *
 * WordPress deactivates a plugin (or breaks a theme) when its bootstrap file
 * loses a valid header — "The plugin does not have a valid header." The patch
 * apply path previously validated only PHP *syntax*, so a change that was valid
 * PHP but dropped or corrupted the `Plugin Name:` / `Theme Name:` header applied
 * cleanly and silently took the plugin offline.
 *
 * This guard detects bootstrap files and rejects any patch that would remove,
 * corrupt, or invalidate their header — checked both before the write (reject
 * the patch) and after (revert if the on-disk header is gone). It uses the same
 * header-extraction rules as WordPress core's get_file_data(), so "valid" here
 * means exactly what WordPress will accept.
 *
 * A file is only guarded when its ORIGINAL content already carries the header,
 * so ordinary files — and legitimate edits that keep the header (version bumps,
 * description changes, even renaming the plugin) — are never blocked.
 */

namespace WPCommandCenter\PatchSystem;

defined( 'ABSPATH' ) || exit;

final class PatchGuard {

	public const HEADER_PLUGIN = 'Plugin Name';
	public const HEADER_THEME  = 'Theme Name';

	/**
	 * Bytes of the file head WordPress reads for header data.
	 */
	private const HEADER_SCAN_BYTES = 8192;

	/**
	 * Which bootstrap header governs this file, decided from its ORIGINAL
	 * content. Returns 'Plugin Name', 'Theme Name', or null (not a bootstrap).
	 *
	 * Any file whose head contains a non-empty `Plugin Name:` is a plugin
	 * bootstrap (this is exactly how WordPress identifies plugin files, so it
	 * covers single-file plugins and main files alike). A `style.css` whose head
	 * contains a non-empty `Theme Name:` is a theme stylesheet.
	 */
	public static function guarded_header( string $relative_path, string $original ): ?string {
		if ( '' !== self::header_value( $original, self::HEADER_PLUGIN ) ) {
			return self::HEADER_PLUGIN;
		}

		if ( 'style.css' === strtolower( basename( $relative_path ) )
			&& '' !== self::header_value( $original, self::HEADER_THEME ) ) {
			return self::HEADER_THEME;
		}

		return null;
	}

	/**
	 * Whether this file is a guarded plugin/theme bootstrap file.
	 */
	public static function is_bootstrap_file( string $relative_path, string $original ): bool {
		return null !== self::guarded_header( $relative_path, $original );
	}

	/**
	 * Validate that a proposed change preserves the bootstrap header.
	 *
	 * @return \WP_Error|null WP_Error if the change would remove/corrupt/
	 *                        invalidate the header; null when it is safe or the
	 *                        file is not a guarded bootstrap file.
	 */
	public static function validate_change( string $relative_path, string $original, string $modified ): ?\WP_Error {
		$header = self::guarded_header( $relative_path, $original );

		if ( null === $header ) {
			return null; // Not a bootstrap file — unconstrained.
		}

		if ( '' !== self::header_value( $modified, $header ) ) {
			return null; // Header still present and parseable.
		}

		$type = self::HEADER_PLUGIN === $header
			? __( 'plugin', 'wp-command-center' )
			: __( 'theme', 'wp-command-center' );

		return new \WP_Error(
			'wpcc_patch_breaks_header',
			sprintf(
				/* translators: 1: header name (e.g. "Plugin Name"), 2: file path, 3: "plugin" or "theme" */
				__( 'Patch rejected: it would remove or invalidate the "%1$s" header of %2$s, which would deactivate the %3$s. Keep a valid "%1$s" header (within the first 8 KB) in the modified file.', 'wp-command-center' ),
				$header,
				$relative_path,
				$type
			),
			[ 'status' => 400, 'header' => $header, 'path' => $relative_path ]
		);
	}

	/**
	 * Verify a file ON DISK still carries its bootstrap header (post-write).
	 * $original is the pre-patch content, used to decide whether the file is
	 * guarded at all.
	 *
	 * @return array{guarded: bool, passed: bool, header?: string, message: string}
	 */
	public static function verify_written_file( string $real_path, string $relative_path, string $original ): array {
		$header = self::guarded_header( $relative_path, $original );

		if ( null === $header ) {
			return [ 'guarded' => false, 'passed' => true, 'message' => 'Not a bootstrap file — header check skipped.' ];
		}

		$content = is_readable( $real_path ) ? (string) file_get_contents( $real_path ) : '';
		$value   = self::header_value( $content, $header );

		return [
			'guarded' => true,
			'header'  => $header,
			'passed'  => '' !== $value,
			'message' => '' !== $value
				? sprintf( '%s header present (%s).', $header, $value )
				: sprintf( '%s header missing or invalid after write.', $header ),
		];
	}

	/**
	 * Extract a header value from file content, mirroring WordPress core's
	 * get_file_data()/_cleanup_header_comment() logic: scan the first 8 KB,
	 * normalize newlines, match the header line, and strip trailing comment
	 * delimiters. Returns '' when the header is absent or empty.
	 */
	public static function header_value( string $content, string $header ): string {
		$head = substr( $content, 0, self::HEADER_SCAN_BYTES );
		$head = str_replace( "\r", "\n", $head );

		$pattern = '/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote( $header, '/' ) . ':(.*)$/mi';

		if ( preg_match( $pattern, $head, $m ) ) {
			return trim( (string) preg_replace( '/\s*(?:\*\/|\?>).*/', '', $m[1] ) );
		}

		return '';
	}
}
