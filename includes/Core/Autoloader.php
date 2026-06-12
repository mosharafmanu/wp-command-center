<?php
/**
 * PSR-4 autoloader for the WPCommandCenter\ namespace.
 */

namespace WPCommandCenter\Core;

defined( 'ABSPATH' ) || exit;

final class Autoloader {

	private const PREFIX = 'WPCommandCenter\\';

	public static function register(): void {
		spl_autoload_register( [ self::class, 'load' ] );
	}

	private static function load( string $class ): void {
		if ( ! str_starts_with( $class, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class, strlen( self::PREFIX ) );
		$path     = WPCC_PLUGIN_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require $path;
		}
	}
}
