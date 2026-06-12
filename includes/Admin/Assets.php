<?php
namespace WPCommandCenter\Admin;

defined( 'ABSPATH' ) || exit;

final class Assets {

	public function init(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function enqueue( string $hook ): void {
		if ( ! str_contains( $hook, 'wp-command-center' ) && ! str_contains( $hook, 'wpcc-' ) ) {
			return;
		}

		wp_enqueue_style( 'wpcc-admin', WPCC_PLUGIN_URL . 'assets/css/admin.css', [], WPCC_VERSION );
		wp_enqueue_script( 'wpcc-admin', WPCC_PLUGIN_URL . 'assets/js/admin.js', [], WPCC_VERSION, true );
	}
}
