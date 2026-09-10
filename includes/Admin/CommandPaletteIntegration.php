<?php
namespace WPCommandCenter\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Put this plugin's screens into WordPress's own command palette.
 *
 * WordPress ships a global ⌘K palette in the admin bar. It builds its result list
 * from registered commands plus a "Go to: …" command per admin menu entry, and it
 * filters by loose subsequence: a query matches whenever its characters appear in
 * order anywhere in the searchable string. With nothing of ours registered, the
 * palette had no product-owned answer to give and returned only that noise:
 *
 *     token       → Go to: Marketing · Marketing > Coupons · Rank Math SEO > …
 *     protection  → nothing
 *
 * Neither result mentions Action Steward, and the first is actively wrong — the
 * customer asked for access tokens and was offered a coupons screen.
 *
 * The matcher is WordPress's and is not ours to change. What was missing is its
 * input: real, exactly-named destinations. Registering one command per screen —
 * the same destinations the plugin's own palette already offers, from the same
 * AppShell::nav_map() — means the query has something true to match. Measured on
 * a live install: "token" now answers "Action Steward: Access tokens" first,
 * with the Marketing rows below it, and "protection", "security mode" and
 * "built-in AI" each answer with their own screen.
 *
 * TWO THINGS TO KNOW BEFORE EDITING THE METADATA BELOW.
 *
 * The palette does NOT rank. Matches come back in registration order, so a longer
 * or richer searchable string buys no precedence — it only widens what the
 * subsequence filter will accept. And because the filter is a subsequence, a long
 * alias list is a ladder an unrelated query can climb: with the full navigation
 * keywords attached, "protection" matched Diagnostics through problem / report /
 * recommendations. Short, specific strings are the whole defence. See
 * MAX_ALIAS_TERMS.
 *
 * This adds no admin surface, no route and no capability. It registers navigation
 * targets the user can already reach from the menu, gated on the same capability
 * that draws that menu, and every command does exactly one thing: change
 * window.location to a URL WordPress itself generated.
 */
final class CommandPaletteIntegration {

	/** Same gate as the admin menu — never offer a destination the user cannot open. */
	private const CAPABILITY = 'manage_options';

	/** The script WordPress registers for its palette store. */
	private const CORE_HANDLE = 'wp-commands';

	/**
	 * How many alias words each destination may contribute to the search string.
	 *
	 * The palette filters by subsequence and does not rank, so a long alias list is
	 * not free precision — it is a longer ladder for an unrelated query to climb,
	 * and no amount of extra vocabulary earns a better position. Measured on a live
	 * install with the full lists attached: "protection" returned Diagnostics,
	 * matching **p**roblem, **r**eport, rec**o**mmenda**t**ions and so on, none of
	 * which is the word. Trimmed to the leading terms — the ones customers actually
	 * type — every required query answers with its own screen.
	 *
	 * The plugin's own palette is unaffected: it matches contiguously and keeps
	 * reading the complete `keywords` string, so no alias is lost to search.
	 */
	private const MAX_ALIAS_TERMS = 4;

	public function init(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * Deliberately NOT limited to this plugin's own screens.
	 *
	 * The palette is global: its whole value is reaching a screen from wherever you
	 * happen to be. Registering only on our own pages would mean the one place the
	 * commands were available is the place they are least needed.
	 */
	public function enqueue(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// Older or trimmed installs may not have the palette at all. Its absence is
		// not an error — there is simply nothing to register into, and asking for a
		// script WordPress never registered would enqueue a broken dependency.
		if ( ! wp_script_is( self::CORE_HANDLE, 'registered' ) ) {
			return;
		}

		$destinations = self::destinations();
		if ( empty( $destinations ) ) {
			return;
		}

		$handle = 'wpcc-command-palette';
		wp_enqueue_script(
			$handle,
			WPCC_PLUGIN_URL . 'assets/js/wpcc-command-palette.js',
			[ 'wp-data', self::CORE_HANDLE ],
			Assets::asset_version( 'assets/js/wpcc-command-palette.js' ),
			true
		);

		wp_localize_script( $handle, 'wpccCommandPalette', [
			'destinations' => $destinations,
			'i18n'         => [
				/* translators: %s: destination name, e.g. "Access tokens". */
				'commandLabel' => __( 'Action Steward: %s', 'action-steward' ),
				/*
				 * The product name on its own, so the searchable string can END with
				 * it rather than begin with it. Prefixing every row with the same
				 * shared characters gave the subsequence filter a free head start on
				 * every destination: measured, "protection" then matched Access
				 * tokens too, because its leading "p" only had to be found in
				 * "W-P Command Center". Moving the prefix to the end put every
				 * required query on its own screen. It stays present so
				 * The legacy three-word product query still finds them all.
				 */
				'product'      => __( 'Action Steward', 'action-steward' ),
			],
		] );
	}

	/**
	 * The searchable destinations, from the single navigation map.
	 *
	 * Same source as the plugin's own ⌘K palette (AppShell::nav_map), so the two
	 * cannot drift and a screen this site has switched off is offered by neither.
	 *
	 * `aliases`, not `keywords`. WordPress scores this string fuzzily, so every
	 * extra word is another chance for an unrelated query's letters to appear
	 * across it in order. Measured here: with the inherited section words included,
	 * "protection" ranked Changes ABOVE Protection — Changes does not contain the
	 * word anywhere, but its full keyword string contains the letters in sequence.
	 * Carrying only the words that describe this screen removed it, and every
	 * required query then answers with its own destination first.
	 *
	 * Breadcrumb labels are reduced to the screen's own name for the same reason
	 * and one more: the palette prefixes every row with the product name already,
	 * so "Action Steward: Settings › Connections › Access tokens" says "Settings"
	 * twice and buries the only word the customer typed at the far end of the row.
	 *
	 * @return array<int, array{name:string, label:string, search:string, url:string}>
	 */
	private static function destinations(): array {
		$out  = [];
		$seen = [];

		foreach ( AppShell::nav_map() as $item ) {
			$url = (string) ( $item['url'] ?? '' );
			if ( '' === $url || isset( $seen[ $url ] ) ) {
				continue;
			}
			$seen[ $url ] = true;

			$full  = (string) ( $item['label'] ?? '' );
			$parts = array_map( 'trim', explode( '›', $full ) );
			$leaf  = (string) end( $parts );

			if ( '' === $leaf ) {
				continue;
			}

			$out[] = [
				// Stable, namespaced and derived from the URL, so re-registering on a
				// later page load replaces the same command rather than doubling it.
				'name'   => 'action-steward/' . md5( $url ),
				'label'  => $leaf,
				'search' => self::search_terms( (string) ( $item['aliases'] ?? $item['keywords'] ?? '' ) ),
				'url'    => $url,
			];
		}

		return $out;
	}

	/** The leading MAX_ALIAS_TERMS words of an alias string, de-duplicated. */
	private static function search_terms( string $aliases ): string {
		$words = preg_split( '/\s+/', strtolower( trim( $aliases ) ), -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $words ) || [] === $words ) {
			return '';
		}

		return implode( ' ', array_slice( array_values( array_unique( $words ) ), 0, self::MAX_ALIAS_TERMS ) );
	}
}
