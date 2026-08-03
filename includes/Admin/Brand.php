<?php
/**
 * Brand asset accessors — the single place the product resolves its identity artwork.
 *
 * The artwork itself lives in `assets/brand/` and is the deliverable from the official
 * identity standard. Nothing here draws the mark: the standard is explicit that it must
 * never be reconstructed from CSS borders or font glyphs, so every surface loads a real
 * SVG. Keeping the paths in one class means a future artwork revision touches this file
 * and the asset directory, never the twenty views that display it.
 *
 * Sizing follows the standard's small-size system:
 *   16 px  -> wpcc-admin-16.svg   (integer coordinates, simplified radii, monochrome)
 *   20 px  -> wpcc-admin-20.svg   (ditto; this is the WordPress admin menu size)
 *   24-48  -> the master mark
 *   64 px  -> the light container
 *
 * @package WPCommandCenter
 */

namespace WPCommandCenter\Admin;

defined( 'ABSPATH' ) || exit;

final class Brand {

	/** Directory holding the official identity artwork, relative to the plugin root. */
	private const DIR = 'assets/brand/';

	/**
	 * Public URL for a brand asset.
	 *
	 * @param string $file File name inside assets/brand/.
	 */
	public static function url( string $file ): string {
		return WPCC_PLUGIN_URL . self::DIR . $file;
	}

	/** The 24x24 master mark for light surfaces (navy blocks, Execute Blue core). */
	public static function mark(): string {
		return self::url( 'wpcc-mark.svg' );
	}

	/** The 24x24 master mark for dark surfaces (white blocks, Signal Blue core). */
	public static function mark_dark(): string {
		return self::url( 'wpcc-mark-dark.svg' );
	}

	/** The full horizontal lockup for hero use, light surfaces. */
	public static function logo(): string {
		return self::url( 'wpcc-logo.svg' );
	}

	/** The full horizontal lockup for hero use, dark surfaces. */
	public static function logo_dark(): string {
		return self::url( 'wpcc-logo-dark.svg' );
	}

	/** The 16 px monochrome master — for admin bar and other 16 px slots. */
	public static function admin_16(): string {
		return self::url( 'wpcc-admin-16.svg' );
	}

	/**
	 * The admin menu icon, as the base64 SVG data URI WordPress expects.
	 *
	 * WordPress renders a `data:image/svg+xml;base64,` icon_url as
	 * `<div class="wp-menu-image svg" style="background-image:url(...)">` and then owns
	 * the state treatment itself — core fades the icon with opacity and brings it to full
	 * strength on hover and for the current menu item. That is exactly what the identity
	 * standard asks for ("allow WordPress hover/active treatment to control state"), so
	 * the artwork ships in the platform's own icon gray and does not try to restyle
	 * itself. Core paints it at `background-size: 20px auto`, which is why this is the
	 * dedicated 20 px master rather than the display mark scaled down.
	 *
	 * The markup is inline rather than read from disk because this runs on every admin
	 * page load; a file read per request to produce a fixed 364-byte string would be
	 * waste. `assets/brand/wpcc-admin-20.svg` is the canonical source and this is a
	 * verbatim copy of it — `tests/test-branding-assets.sh` asserts the two never drift.
	 */
	public static function menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" aria-label="WP Command Center">'
			. '<path fill="#a7aaad" d="M3 2h6v3H5v4H2V3a1 1 0 0 1 1-1Zm8 0h6a1 1 0 0 1 1 1v6h-3V5h-4V2ZM2 11h3v4h4v3H3a1 1 0 0 1-1-1v-6Zm13 0h3v6a1 1 0 0 1-1 1h-6v-3h4v-4Z"/>'
			. '<path fill="#a7aaad" fill-rule="evenodd" d="m10 6 4 4-4 4-4-4 4-4Zm0 2.5L8.5 10l1.5 1.5 1.5-1.5-1.5-1.5Z"/>'
			. '</svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/**
	 * The HTML `picture()` is allowed to produce.
	 *
	 * Callers pass this to `wp_kses()` rather than echoing the markup raw. That is not
	 * ceremony: it means the output passes through a real escaping function, so the
	 * sniffers see what is actually true instead of being told to look away with a
	 * `phpcs:ignore`, and the element set stays pinned to exactly what the brand markup
	 * needs. `srcset` and `media` must be listed explicitly — the default post allowlist
	 * has no concept of <picture>/<source>.
	 *
	 * @return array<string,array<string,bool>>
	 */
	public static function allowed_html(): array {
		return [
			'picture' => [],
			'source'  => [
				'srcset' => true,
				'media'  => true,
				'type'   => true,
			],
			'img'     => [
				'src'      => true,
				'alt'      => true,
				'class'    => true,
				'width'    => true,
				'height'   => true,
				'decoding' => true,
				'style'    => true,
			],
		];
	}

	/**
	 * A <picture> element that serves the dark-surface artwork to dark-scheme viewers.
	 *
	 * Returned as markup rather than two stacked <img>s toggled by CSS so assistive
	 * technology encounters exactly one image with one accessible name, whichever scheme
	 * is active. `$alt` is intentionally allowed to be empty: a mark sitting beside the
	 * product name it depicts is decorative, and announcing "WP Command Center" twice is
	 * worse than announcing it once.
	 *
	 * @param string $light Light-surface asset URL.
	 * @param string $dark  Dark-surface asset URL.
	 * @param string $alt   Accessible name; '' marks the image decorative.
	 * @param string $class CSS class for the <img>.
	 * @param int    $w     Intrinsic width in px.
	 * @param int    $h     Intrinsic height in px.
	 */
	public static function picture( string $light, string $dark, string $alt, string $class, int $w, int $h ): string {
		return sprintf(
			'<picture><source srcset="%1$s" media="(prefers-color-scheme: dark)" /><img src="%2$s" alt="%3$s" class="%4$s" width="%5$d" height="%6$d" decoding="async" /></picture>',
			esc_url( $dark ),
			esc_url( $light ),
			esc_attr( $alt ),
			esc_attr( $class ),
			$w,
			$h
		);
	}
}
