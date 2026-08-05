/**
 * WP Command Center — Command Design System (CDS) runtime.
 *
 * The behavioral layer for the Experience Layer shell, built on window.WPCC:
 *   - Builder / Engineer mode (disclosure + density), persisted per-browser in
 *     localStorage and reflected as data-wpcc-mode / data-wpcc-density on the shell.
 *   - A navigate-only ⌘K command palette over the 5-C navigation map (no execution).
 *   - WPCC.cds.* render helpers (chip / pill / actorChip / card / kpi / empty / loading)
 *     so every surface renders trust signals identically (retires per-view drift).
 *
 * Config is localized as `window.wpccCds` ({ mode, nav, i18n }). Side-effect on load
 * is limited to reading the persisted mode and wiring the shell controls present on
 * the page; it adds no routes and performs no writes.
 */
( function () {
	'use strict';

	var WPCC = window.WPCC || {};
	var cfg = window.wpccCds || {};
	var STORE_KEY = 'wpcc_mode';
	var MODES = { builder: 'comfortable', engineer: 'compact' };

	/* ── Mode (Builder / Engineer) ─────────────────────────────────────────── */

	function readMode() {
		var stored;
		try { stored = window.localStorage.getItem( STORE_KEY ); } catch ( e ) { stored = null; }
		if ( stored && MODES[ stored ] ) { return stored; }
		return ( cfg.mode && MODES[ cfg.mode ] ) ? cfg.mode : 'builder';
	}

	function applyMode( mode ) {
		var density = MODES[ mode ] || 'comfortable';
		var apps = document.querySelectorAll( '.wpcc-app' );
		Array.prototype.forEach.call( apps, function ( app ) {
			app.setAttribute( 'data-wpcc-mode', mode );
			app.setAttribute( 'data-wpcc-density', density );
		} );
		// Mirror onto <html> so tokens that key on density also reach hosted views.
		document.documentElement.setAttribute( 'data-wpcc-density', density );
		// Reflect pressed state on the toggle.
		var btns = document.querySelectorAll( '.wpcc-shell__mode' );
		Array.prototype.forEach.call( btns, function ( b ) {
			b.setAttribute( 'aria-pressed', b.getAttribute( 'data-mode' ) === mode ? 'true' : 'false' );
		} );
	}

	function setMode( mode ) {
		if ( ! MODES[ mode ] ) { return; }
		try { window.localStorage.setItem( STORE_KEY, mode ); } catch ( e ) {}
		applyMode( mode );
	}

	function wireModeToggle() {
		var btns = document.querySelectorAll( '.wpcc-shell__mode' );
		Array.prototype.forEach.call( btns, function ( b ) {
			b.addEventListener( 'click', function () { setMode( b.getAttribute( 'data-mode' ) ); } );
		} );
	}

	/* ── ⌘K command palette (navigate-only) ────────────────────────────────── */

	var palette = null;
	var paletteItems = [];
	var activeIdx = 0;
	var lastFocus = null;

	/**
	 * The destination list, already flat and already unique per URL (AppShell::
	 * nav_map builds one row per real destination). The old version derived rows
	 * from a section/tab tree, which emitted a section AND its only tab as two
	 * rows pointing at the same screen. Deduplicating by URL here as well keeps
	 * that guarantee true even if a future map hands us the same URL twice.
	 */
	function flattenNav() {
		var seen = {};
		var out  = [];
		( cfg.nav || [] ).forEach( function ( item ) {
			if ( ! item || ! item.url || seen[ item.url ] ) { return; }
			seen[ item.url ] = true;
			out.push( {
				label:    item.label,
				hint:     item.hint || ( cfg.i18n && cfg.i18n.section ) || 'Section',
				url:      item.url,
				keywords: ( item.keywords || '' ).toLowerCase()
			} );
		} );
		return out;
	}

	/**
	 * Score one destination against a query, or -1 for no match.
	 *
	 * Ranking, best first:
	 *   0  the label starts with the query        ("app" → Approvals)
	 *   1  a word inside the label starts with it ("tok" → … › Access tokens)
	 *   2  the label contains it anywhere
	 *   3  only a hidden keyword matches          ("undo" → Changes)
	 *
	 * Multi-word queries must match every word somewhere ("access token" finds
	 * Access tokens; "token access" finds it too). Keywords are never displayed —
	 * they exist so the words a customer actually types reach the screen they
	 * mean, without turning the list into a glossary.
	 */
	function score( item, words ) {
		var label = item.label.toLowerCase();
		var hay   = label + ' ' + item.keywords;
		var best  = 0;

		for ( var i = 0; i < words.length; i++ ) {
			var w = words[ i ];
			if ( hay.indexOf( w ) === -1 ) { return -1; }

			var rank;
			if ( label.indexOf( w ) === 0 ) {
				rank = 0;
			} else if ( new RegExp( '(^|[^a-z0-9])' + w.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) ).test( label ) ) {
				rank = 1;
			} else if ( label.indexOf( w ) !== -1 ) {
				rank = 2;
			} else {
				rank = 3;
			}
			if ( rank > best ) { best = rank; }
		}
		return best;
	}

	function buildPalette() {
		var overlay = WPCC.el( 'div', { class: 'wpcc-cmdk', hidden: 'hidden', role: 'dialog', 'aria-modal': 'true', 'aria-label': ( cfg.i18n && cfg.i18n.paletteLabel ) || 'Command palette' } );
		var panel = WPCC.el( 'div', { class: 'wpcc-cmdk__panel' } );
		var input = WPCC.el( 'input', { type: 'text', class: 'wpcc-cmdk__input', 'aria-label': ( cfg.i18n && cfg.i18n.paletteSearch ) || 'Search sections', placeholder: ( cfg.i18n && cfg.i18n.paletteSearch ) || 'Jump to…' } );
		var list = WPCC.el( 'ul', { class: 'wpcc-cmdk__list', role: 'listbox' } );
		panel.appendChild( input );
		panel.appendChild( list );
		overlay.appendChild( panel );
		document.body.appendChild( overlay );

		overlay.addEventListener( 'click', function ( e ) { if ( e.target === overlay ) { closePalette(); } } );
		input.addEventListener( 'input', function () { renderOpts( input.value ); } );
		overlay.addEventListener( 'keydown', onPaletteKey );

		palette = { overlay: overlay, input: input, list: list };
	}

	function renderOpts( query ) {
		var all = flattenNav();
		var q = ( query || '' ).toLowerCase().trim();
		var matches;

		if ( ! q ) {
			matches = all;
		} else {
			var words = q.split( /\s+/ );
			matches = all
				.map( function ( o, i ) { return { o: o, s: score( o, words ), i: i }; } )
				.filter( function ( r ) { return r.s !== -1; } )
				// Ties keep map order, which is navigation order — so equally good
				// matches come back in the order the product itself lists them.
				.sort( function ( a, b ) { return a.s - b.s || a.i - b.i; } )
				.map( function ( r ) { return r.o; } );
		}

		paletteItems = matches;
		activeIdx = 0;
		palette.list.innerHTML = '';

		/*
		 * An empty result used to render an empty <ul>: the panel simply went
		 * blank, which reads as the search being broken rather than as "that word
		 * matched nothing". Say so, and say what to do about it.
		 */
		if ( ! matches.length ) {
			var none = WPCC.el( 'li', { class: 'wpcc-cmdk__none', role: 'status' } );
			none.appendChild( WPCC.el( 'span', null, ( cfg.i18n && cfg.i18n.paletteNone ) || 'Nothing matches that.' ) );
			none.appendChild( WPCC.el( 'small', null, ( cfg.i18n && cfg.i18n.paletteNoneHint ) || 'Try a shorter word, or clear the box to see everywhere you can go.' ) );
			palette.list.appendChild( none );
			return;
		}

		matches.forEach( function ( o, i ) {
			var li = WPCC.el( 'li', { class: 'wpcc-cmdk__opt' + ( i === 0 ? ' is-active' : '' ), role: 'option', 'aria-selected': i === 0 ? 'true' : 'false' } );
			li.appendChild( WPCC.el( 'span', null, o.label ) );
			li.appendChild( WPCC.el( 'small', null, o.hint ) );
			li.addEventListener( 'click', function () { go( o.url ); } );
			palette.list.appendChild( li );
		} );
	}

	function highlight() {
		var opts = palette.list.querySelectorAll( '.wpcc-cmdk__opt' );
		Array.prototype.forEach.call( opts, function ( el, i ) {
			var on = i === activeIdx;
			el.classList.toggle( 'is-active', on );
			el.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			if ( on ) { el.scrollIntoView( { block: 'nearest' } ); }
		} );
	}

	function onPaletteKey( e ) {
		if ( e.key === 'Escape' ) { e.preventDefault(); closePalette(); return; }
		if ( e.key === 'ArrowDown' ) { e.preventDefault(); activeIdx = Math.min( activeIdx + 1, paletteItems.length - 1 ); highlight(); return; }
		if ( e.key === 'ArrowUp' ) { e.preventDefault(); activeIdx = Math.max( activeIdx - 1, 0 ); highlight(); return; }
		if ( e.key === 'Enter' ) { e.preventDefault(); if ( paletteItems[ activeIdx ] ) { go( paletteItems[ activeIdx ].url ); } return; }
		if ( e.key === 'Tab' ) { WPCC.a11y.trapTab( palette.overlay, e ); }
	}

	function go( url ) { if ( url ) { window.location.href = url; } }

	function openPalette() {
		if ( ! palette ) { buildPalette(); }
		lastFocus = document.activeElement;
		renderOpts( '' );
		palette.input.value = '';
		palette.overlay.hidden = false;
		palette.input.focus();
	}

	function closePalette() {
		if ( ! palette ) { return; }
		palette.overlay.hidden = true;
		if ( lastFocus && lastFocus.focus ) { lastFocus.focus(); }
	}

	function wirePalette() {
		var triggers = document.querySelectorAll( '.wpcc-shell__cmdk' );
		Array.prototype.forEach.call( triggers, function ( t ) {
			t.addEventListener( 'click', openPalette );
		} );
		document.addEventListener( 'keydown', function ( e ) {
			if ( ( e.metaKey || e.ctrlKey ) && ( e.key === 'k' || e.key === 'K' ) ) {
				e.preventDefault();
				if ( palette && ! palette.overlay.hidden ) { closePalette(); } else { openPalette(); }
			}
		} );
	}

	/* ── Render helpers (WPCC.cds.*) — HTML strings, escaped ────────────────── */

	var RISK = { diagnostic: 'diagnostic', low: 'low', medium: 'medium', high: 'high', critical: 'critical' };

	WPCC.cds = {
		/** A trust chip: kind in reversible|audited|approval|scoped|irreversible. */
		chip: function ( kind, label ) {
			return '<span class="wpcc-cds-chip wpcc-cds-chip--' + WPCC.escHtml( kind ) + '">' + WPCC.escHtml( label ) + '</span>';
		},
		/** A status/risk pill: variant in success|warning|danger|neutral|<risk tier>.
		 * Optional ariaLabel adds an accessible label when the visible text alone
		 * lacks column context. */
		pill: function ( variant, label, ariaLabel ) {
			return '<span class="wpcc-cds-pill wpcc-cds-pill--' + WPCC.escHtml( variant ) + '"'
				+ ( ariaLabel ? ' aria-label="' + WPCC.escHtml( ariaLabel ) + '"' : '' )
				+ '>' + WPCC.escHtml( label ) + '</span>';
		},
		riskPill: function ( tier, label, ariaLabel ) {
			var v = RISK[ tier ] || 'neutral';
			return '<span class="wpcc-cds-pill wpcc-cds-pill--' + v + '"'
				+ ( ariaLabel ? ' aria-label="' + WPCC.escHtml( ariaLabel ) + '"' : '' )
				+ '>' + WPCC.escHtml( label || tier ) + '</span>';
		},
		/** Status pill: maps a known status token to a pill variant (then delegates
		 * to pill). Keeps status colors consistent across every surface. */
		statusPill: function ( status, label, ariaLabel ) {
			var map = {
				success: 'success', applied: 'success', available: 'success', ok: 'success', active: 'success',
				warning: 'warning', required: 'warning', pending: 'warning', awaiting: 'warning',
				danger: 'danger', failed: 'danger', error: 'danger',
				neutral: 'neutral', notreq: 'neutral', inactive: 'neutral', unavailable: 'neutral'
			};
			return WPCC.cds.pill( map[ status ] || 'neutral', label == null ? status : label, ariaLabel );
		},
		/** Metadata tag (e.g. a capability id or enum value); mono optional. */
		tag: function ( label, mono ) {
			return '<span class="wpcc-cds-tag' + ( mono ? ' wpcc-cds-tag--mono' : '' ) + '">' + WPCC.escHtml( label ) + '</span>';
		},
		/** Button (HTML string). opts: { label, variant, id, type, disabled, attrs }.
		 * Emits WP's `.button` baseline + the `.wpcc-cds-btn` token hook so it is
		 * visually native today and CDS-themed as Phase 2 lands. */
		button: function ( opts ) {
			opts = opts || {};
			var html = '<button type="' + WPCC.escHtml( opts.type || 'button' ) + '"'
				+ ' class="button wpcc-cds-btn wpcc-cds-btn--' + WPCC.escHtml( opts.variant || 'secondary' ) + '"';
			if ( opts.id ) { html += ' id="' + WPCC.escHtml( opts.id ) + '"'; }
			if ( opts.disabled ) { html += ' disabled'; }
			if ( opts.attrs ) {
				Object.keys( opts.attrs ).forEach( function ( k ) {
					html += ' ' + k + '="' + WPCC.escHtml( opts.attrs[ k ] ) + '"';
				} );
			}
			return html + '>' + WPCC.escHtml( opts.label == null ? '' : opts.label ) + '</button>';
		},
		/** Actor provenance chip: type in human|system|agent. */
		actorChip: function ( type, label ) {
			var t = ( type === 'system' || type === 'agent' ) ? type : 'human';
			return '<span class="wpcc-cds-actor wpcc-cds-actor--' + t + '">'
				+ '<span class="wpcc-cds-actor__dot" aria-hidden="true"></span>'
				+ WPCC.escHtml( label == null ? t : label ) + '</span>';
		},
		kpi: function ( value, label ) {
			return '<div class="wpcc-cds-kpi"><span class="wpcc-cds-kpi__value">' + WPCC.escHtml( value )
				+ '</span><span class="wpcc-cds-kpi__label">' + WPCC.escHtml( label ) + '</span></div>';
		},
		/**
		 * Empty state.
		 *
		 * `icon` stays a dashicons class name, exactly as before, so every existing
		 * caller is untouched. It may ALSO be a `.svg` URL, which renders the brand
		 * mark instead - used where the empty state is the product's own "nothing has
		 * happened here yet" rather than a state carrying its own meaning. Decorative
		 * either way: the title directly below already says what is empty.
		 */
		empty: function ( title, detail, icon ) {
			var art = '';
			if ( icon ) {
				art = /\.svg($|\?)/.test( icon )
					? '<img src="' + WPCC.escHtml( icon ) + '" alt="" class="wpcc-cds-empty__mark" width="32" height="32" decoding="async" />'
					: '<span class="dashicons ' + WPCC.escHtml( icon ) + ' wpcc-cds-empty__icon" aria-hidden="true"></span>';
			}
			return '<div class="wpcc-cds-empty">'
				+ art
				+ '<div class="wpcc-cds-empty__title">' + WPCC.escHtml( title ) + '</div>'
				+ ( detail ? '<p>' + WPCC.escHtml( detail ) + '</p>' : '' )
				+ '</div>';
		},
		loading: function ( label ) {
			return '<div class="wpcc-cds-loading"><span class="spinner is-active" style="float:none;margin:0"></span>'
				+ '<span>' + WPCC.escHtml( label ) + '</span></div>';
		},
		/** Error state (companion to empty/loading) — role=alert for assistive tech. */
		error: function ( title, detail ) {
			return '<div class="wpcc-cds-error" role="alert">'
				+ '<span class="wpcc-cds-error__title">' + WPCC.escHtml( title ) + '</span>'
				+ ( detail ? '<span class="wpcc-cds-error__detail">' + WPCC.escHtml( detail ) + '</span>' : '' )
				+ '</div>';
		},

		/**
		 * In-page confirmation. Returns a Promise<boolean>.
		 *
		 * Replaces window.confirm(). A native dialog is the one piece of UI in this
		 * product that the product does not control: it renders as "localhost says:",
		 * ignores the design system, cannot show what is about to happen, and blocks
		 * the whole renderer while it is open. On a bulk action that is about to
		 * approve or reject several real changes, that is the wrong moment to hand
		 * the customer a browser default.
		 *
		 * opts: { title, body, confirmLabel, cancelLabel, danger }
		 */
		confirm: function ( opts ) {
			opts = opts || {};
			return new Promise( function ( resolve ) {
				var prev = document.activeElement;
				var host = document.createElement( 'div' );
				host.className = 'wpcc-cds-confirm';
				host.setAttribute( 'role', 'dialog' );
				host.setAttribute( 'aria-modal', 'true' );
				host.setAttribute( 'aria-label', opts.title || '' );
				host.innerHTML =
					'<div class="wpcc-cds-confirm__box">'
					+ '<h2 class="wpcc-cds-confirm__title">' + WPCC.escHtml( opts.title || '' ) + '</h2>'
					+ ( opts.body ? '<p class="wpcc-cds-confirm__body">' + WPCC.escHtml( opts.body ) + '</p>' : '' )
					+ '<div class="wpcc-cds-confirm__actions">'
					+ '<button type="button" class="button button-primary' + ( opts.danger ? ' wpcc-cds-confirm__danger' : '' ) + '" data-wpcc-go>'
					+ WPCC.escHtml( opts.confirmLabel || 'Continue' ) + '</button>'
					+ '<button type="button" class="button" data-wpcc-cancel>'
					+ WPCC.escHtml( opts.cancelLabel || 'Cancel' ) + '</button>'
					+ '</div></div>';
				document.body.appendChild( host );

				function done( value ) {
					document.removeEventListener( 'keydown', onKey, true );
					if ( host.parentNode ) { host.parentNode.removeChild( host ); }
					if ( prev && prev.focus ) { try { prev.focus(); } catch ( e ) {} }
					resolve( value );
				}
				function onKey( e ) {
					if ( 'Escape' === e.key ) { e.preventDefault(); done( false ); }
				}
				host.querySelector( '[data-wpcc-go]' ).addEventListener( 'click', function () { done( true ); } );
				host.querySelector( '[data-wpcc-cancel]' ).addEventListener( 'click', function () { done( false ); } );
				// Clicking the backdrop cancels; clicking inside the box does not.
				host.addEventListener( 'click', function ( e ) { if ( e.target === host ) { done( false ); } } );
				document.addEventListener( 'keydown', onKey, true );
				host.querySelector( '[data-wpcc-go]' ).focus();
			} );
		},
	};

	/* ── Boot ───────────────────────────────────────────────────────────────── */

	function init() {
		applyMode( readMode() );
		wireModeToggle();
		wirePalette();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	window.WPCC = WPCC;
} )();
