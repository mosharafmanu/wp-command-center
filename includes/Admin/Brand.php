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
 *   16 px  -> wpcc-admin-16.svg   (simplified monochrome geometry)
 *   20 px  -> wpcc-admin-20.svg   (the WordPress admin menu size)
 *   24-96  -> the 32-unit master mark
 *   190x36 -> the horizontal wordmark lockup
 *
 * @package WPCommandCenter
 */

namespace WPCommandCenter\Admin;

defined( 'ABSPATH' ) || exit;

final class Brand {

	/** Directory holding the official identity artwork, relative to the plugin root. */
	private const DIR = 'assets/brand/';

	/**
	 * Public URL for a brand asset, cache-keyed to the artwork's own content.
	 *
	 * Assets::ver() already does this for the stylesheets and scripts, and its docblock
	 * names the artwork as the case that motivated it — but the SVGs themselves were
	 * still emitted as bare URLs with no version at all. So the *rule* that paints the
	 * mark could be refreshed while the mark itself could not: a browser holding
	 * `wpcc-logo.svg` kept serving the old artwork forever, because nothing about the
	 * URL ever changed. Revising a brand asset was invisible to every admin who had
	 * loaded the page before, which is precisely the failure the CSS/JS fix was for.
	 *
	 * Appending the file's modification time makes the cache key follow the file: an
	 * edited asset is refetched, an unedited one still comes from cache.
	 *
	 * Both constants are checked before use because this class is deliberately loadable
	 * standalone — tests/test-branding-assets.sh requires it with only ABSPATH and
	 * WPCC_PLUGIN_URL defined to assert the menu icon. An un-stat-able file falls back
	 * to the bare URL, which is the previous behaviour: a missing mtime must never stop
	 * the artwork being rendered.
	 *
	 * @param string $file File name inside assets/brand/.
	 */
	public static function url( string $file ): string {
		$url = WPCC_PLUGIN_URL . self::DIR . $file;

		$path  = defined( 'WPCC_PLUGIN_DIR' ) ? WPCC_PLUGIN_DIR . self::DIR . $file : '';
		$mtime = ( '' !== $path && is_readable( $path ) ) ? filemtime( $path ) : false;
		if ( false === $mtime ) {
			return $url;
		}

		$version = defined( 'WPCC_VERSION' ) ? WPCC_VERSION . '.' . $mtime : (string) $mtime;

		return $url . '?ver=' . rawurlencode( $version );
	}

	/** The 32x32 master mark for light surfaces (navy boundary, indigo radius). */
	public static function mark(): string {
		return self::url( 'wpcc-mark.svg' );
	}

	/** The 32x32 master mark for dark surfaces (white boundary, signal-indigo radius). */
	public static function mark_dark(): string {
		return self::url( 'wpcc-mark-dark.svg' );
	}

	/** The one-colour master for print, masks, and constrained integrations. */
	public static function mark_mono(): string {
		return self::url( 'wpcc-mark-mono.svg' );
	}

	/**
	 * The horizontal lockup for hero use — mark + "SiteRadian" — light surfaces.
	 *
	 * Symbol and wordmark only. The lockup used to carry the tagline "AI-POWERED
	 * WORDPRESS OPERATIONS PLATFORM" set at 10.5px inside a 456x72 viewBox; because
	 * the tagline is what made the artwork 456 wide, any surface rendering the lockup
	 * at a sane size scaled the whole thing down and the tagline arrived at ~5.7px —
	 * a grey smudge, unreadable at any real viewing size, that also dragged the
	 * product name down to 12px. Removing it lets the viewBox tighten to 244x32, so
	 * the wordmark renders at its intended 190x36 display size.
	 */
	public static function logo(): string {
		return self::url( 'wpcc-logo.svg' );
	}

	/** The horizontal lockup for hero use, dark surfaces. See logo(). */
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
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" aria-label="SiteRadian">'
			. '<path d="M10 2a8 8 0 1 1-8 8" fill="none" stroke="#a7aaad" stroke-width="2.4" stroke-linecap="round"/>'
			. '<path d="m10 10 5.5-5.5" fill="none" stroke="#a7aaad" stroke-width="2" stroke-linecap="round"/>'
			. '<circle cx="10" cy="10" r="2" fill="#a7aaad"/>'
			. '<circle cx="15.6" cy="4.4" r="1.5" fill="#a7aaad"/>'
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
	 * product name it depicts is decorative, and announcing "SiteRadian" twice is
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
