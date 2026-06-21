<?php
namespace WPCommandCenter\Admin;

use WPCommandCenter\Operations\SecurityModeManager;

defined( 'ABSPATH' ) || exit;

final class Assets {

	public function init(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function enqueue( string $hook ): void {
		if ( ! str_contains( $hook, 'wp-command-center' ) && ! str_contains( $hook, 'wpcc-' ) && ! str_contains( $hook, 'command-center' ) ) {
			return;
		}

		// Design tokens → CDS component layer (the Experience Layer substrate).
		wp_enqueue_style( 'wpcc-tokens', WPCC_PLUGIN_URL . 'assets/css/wpcc-tokens.css', [], WPCC_VERSION );
		wp_enqueue_style( 'wpcc-cds', WPCC_PLUGIN_URL . 'assets/css/wpcc-cds.css', [ 'wpcc-tokens' ], WPCC_VERSION );
		wp_enqueue_style( 'wpcc-admin', WPCC_PLUGIN_URL . 'assets/css/admin.css', [ 'wpcc-cds' ], WPCC_VERSION );

		// Shared runtime (window.WPCC.*) → CDS runtime (mode toggle, ⌘K, render helpers).
		// These load in the HEAD (in_footer = false), NOT the footer: the admin views
		// embed inline <script> in the page body that reference window.WPCC at parse
		// time (e.g. the Command Center Home). A footer-loaded runtime would not yet
		// exist when those body scripts run, leaving the page stuck on "Loading…".
		wp_enqueue_script( 'wpcc-admin-runtime', WPCC_PLUGIN_URL . 'assets/js/wpcc-admin-runtime.js', [], WPCC_VERSION, false );
		wp_enqueue_script( 'wpcc-cds', WPCC_PLUGIN_URL . 'assets/js/wpcc-cds.js', [ 'wpcc-admin-runtime' ], WPCC_VERSION, false );
		wp_enqueue_script( 'wpcc-admin', WPCC_PLUGIN_URL . 'assets/js/admin.js', [ 'wpcc-cds' ], WPCC_VERSION, true );

		// Default lens by context: Engineer for developer mode, Builder otherwise.
		// (localStorage overrides this per-browser; this is only the first-load default.)
		$default_mode = ( 'developer' === SecurityModeManager::current() ) ? 'engineer' : 'builder';

		wp_localize_script( 'wpcc-cds', 'wpccCds', [
			'mode' => $default_mode,
			'nav'  => AppShell::nav_map(),
			'i18n' => [
				'section'       => __( 'Section', 'wp-command-center' ),
				'paletteLabel'  => __( 'Command palette', 'wp-command-center' ),
				'paletteSearch' => __( 'Jump to a section…', 'wp-command-center' ),
			],
		] );
	}
}
