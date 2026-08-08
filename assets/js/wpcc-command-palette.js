/**
 * Register WP Command Center's screens with WordPress's own ⌘K command palette.
 *
 * See includes/Admin/CommandPaletteIntegration.php for why: with nothing of ours
 * registered, the palette's subsequence filter answered "token" with Marketing and
 * Rank Math rows — labels whose letters happen to appear in the right order — and
 * answered "protection" with nothing at all. Supplying the real destinations gives
 * those queries something true to match. WordPress keeps doing the matching; we
 * only stop asking it to search a set that does not contain the answer.
 *
 * Navigate-only, exactly like the plugin's in-page palette: every command sets
 * window.location to a URL WordPress generated. Nothing here executes an
 * operation, and nothing here can approve anything.
 */
( function () {
	'use strict';

	var cfg = window.wpccCommandPalette;
	if ( ! cfg || ! cfg.destinations || ! cfg.destinations.length ) { return; }

	function register() {
		var data = window.wp && window.wp.data;
		if ( ! data || typeof data.dispatch !== 'function' ) { return false; }

		var store = data.dispatch( 'core/commands' );
		if ( ! store || typeof store.registerCommand !== 'function' ) { return false; }

		var template = ( cfg.i18n && cfg.i18n.commandLabel ) || 'WP Command Center: %s';
		var product  = ( cfg.i18n && cfg.i18n.product ) || 'WP Command Center';

		cfg.destinations.forEach( function ( item ) {
			if ( ! item || ! item.url || ! item.label ) { return; }

			var label = template.replace( '%s', item.label );

			store.registerCommand( {
				name: item.name,
				label: label,
				/*
				 * searchLabel is matched but never shown, so the aliases ride along
				 * without turning every visible row into a paragraph.
				 *
				 * Order is load-bearing, and so is length. The palette filters by
				 * subsequence and returns matches in registration order, so extra
				 * words never earn a better position — they only widen what the
				 * filter accepts. This leads with the screen's own name and ends with
				 * the product name; the shared prefix first gave every row a free
				 * head start on any query beginning with w or p.
				 */
				searchLabel: item.label + ' ' + ( item.search || '' ) + ' ' + product,
				callback: function ( args ) {
					if ( args && typeof args.close === 'function' ) { args.close(); }
					window.location.href = item.url;
				}
			} );
		} );

		return true;
	}

	/*
	 * The palette store is registered by wp-commands, which this script depends on,
	 * so it is normally there already. On a screen that loads it late, retry a few
	 * times and then stop — a palette that never appears must cost nothing, not spin
	 * a timer for the life of the page.
	 */
	if ( ! register() ) {
		var tries = 0;
		var timer = window.setInterval( function () {
			if ( register() || ++tries >= 10 ) { window.clearInterval( timer ); }
		}, 200 );
	}
}() );
