<?php
namespace WPCommandCenter\Admin;

use WPCommandCenter\Operations\SecurityModeManager;

defined( 'ABSPATH' ) || exit;

final class Assets {

	public function init(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * Cache-busting version for one of the plugin's own admin assets.
	 *
	 * These files were all enqueued at a flat `WPCC_VERSION`, which meant the browser's
	 * cache key was the RELEASE version rather than the asset's own content. Any change
	 * to a stylesheet or script that did not also change the plugin version was therefore
	 * invisible to every browser that already held the old copy — it kept serving
	 * `?ver=1.0.0` indefinitely. That is exactly what happened when the identity artwork
	 * landed: the markup updated, the stylesheet did not, and the retired blue tile went
	 * on painting behind the new mark on any admin that had loaded the page before.
	 *
	 * Appending the file's modification time makes the cache key follow the file. An
	 * edited asset is fetched; an unedited one is still served from cache. The release
	 * version stays in the string so the query arg remains readable and diagnosable.
	 *
	 * Falls back to the bare release version if the file cannot be stat'ed, which is the
	 * previous behaviour — a missing mtime must never stop the asset being enqueued.
	 *
	 * @param string $relative Path under the plugin root, e.g. `assets/css/wpcc-cds.css`.
	 */
	private static function ver( string $relative ): string {
		$path = WPCC_PLUGIN_DIR . $relative;
		$mtime = is_readable( $path ) ? filemtime( $path ) : false;

		return false === $mtime ? WPCC_VERSION : WPCC_VERSION . '.' . $mtime;
	}

	public function enqueue( string $hook ): void {
		if ( ! str_contains( $hook, 'wp-command-center' ) && ! str_contains( $hook, 'wpcc-' ) && ! str_contains( $hook, 'command-center' ) ) {
			return;
		}

		// Design tokens → CDS component layer (the Experience Layer substrate).
		wp_enqueue_style( 'wpcc-tokens', WPCC_PLUGIN_URL . 'assets/css/wpcc-tokens.css', [], self::ver( 'assets/css/wpcc-tokens.css' ) );
		wp_enqueue_style( 'wpcc-cds', WPCC_PLUGIN_URL . 'assets/css/wpcc-cds.css', [ 'wpcc-tokens' ], self::ver( 'assets/css/wpcc-cds.css' ) );
		wp_enqueue_style( 'wpcc-admin', WPCC_PLUGIN_URL . 'assets/css/admin.css', [ 'wpcc-cds' ], self::ver( 'assets/css/admin.css' ) );

		// Shared runtime (window.WPCC.*) → CDS runtime (mode toggle, ⌘K, render helpers).
		// These load in the HEAD (in_footer = false), NOT the footer: the admin views
		// embed inline <script> in the page body that reference window.WPCC at parse
		// time (e.g. the Command Center Home). A footer-loaded runtime would not yet
		// exist when those body scripts run, leaving the page stuck on "Loading…".
		wp_enqueue_script( 'wpcc-admin-runtime', WPCC_PLUGIN_URL . 'assets/js/wpcc-admin-runtime.js', [], self::ver( 'assets/js/wpcc-admin-runtime.js' ), false );
		wp_enqueue_script( 'wpcc-cds', WPCC_PLUGIN_URL . 'assets/js/wpcc-cds.js', [ 'wpcc-admin-runtime' ], self::ver( 'assets/js/wpcc-cds.js' ), false );
		wp_enqueue_script( 'wpcc-admin', WPCC_PLUGIN_URL . 'assets/js/admin.js', [ 'wpcc-cds' ], self::ver( 'assets/js/admin.js' ), true );

		// Default lens by context: Engineer for developer mode, Builder otherwise.
		// (localStorage overrides this per-browser; this is only the first-load default.)
		$default_mode = ( 'developer' === SecurityModeManager::current() ) ? 'engineer' : 'builder';

		wp_localize_script( 'wpcc-cds', 'wpccCds', [
			'mode' => $default_mode,
			'nav'  => AppShell::nav_map(),
			'i18n' => [
				'section'         => __( 'Section', 'ai-command-center' ),
				'paletteLabel'    => __( 'Search WP Command Center', 'ai-command-center' ),
				// The palette reaches every screen, not just the four top sections
				// — "Jump to a section…" described a smaller product than the one
				// the customer is searching.
				'paletteSearch'   => __( 'Search for a screen…', 'ai-command-center' ),
				'paletteNone'     => __( 'Nothing here matches that.', 'ai-command-center' ),
				'paletteNoneHint' => __( 'Try a shorter word — or clear the box to see every screen.', 'ai-command-center' ),
			],
		] );
	}
}
