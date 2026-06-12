<?php
namespace WPCommandCenter\System;

defined( 'ABSPATH' ) || exit;

final class EnvironmentManager {
	public const MODES = [ 'development', 'staging', 'production' ];

	public function get(): string {
		$stored = sanitize_key( (string) get_option( 'wpcc_environment_mode', '' ) );
		if ( in_array( $stored, self::MODES, true ) ) {
			return $stored;
		}
		$wp_mode = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		return in_array( $wp_mode, self::MODES, true ) ? $wp_mode : 'production';
	}

	public function set( string $mode ): string|\WP_Error {
		$mode = sanitize_key( $mode );
		if ( ! in_array( $mode, self::MODES, true ) ) {
			return new \WP_Error( 'wpcc_invalid_environment_mode', __( 'Environment mode must be development, staging, or production.', 'wp-command-center' ) );
		}
		update_option( 'wpcc_environment_mode', $mode, false );
		return $mode;
	}
}
