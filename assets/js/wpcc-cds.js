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

	function flattenNav() {
		var out = [];
		( cfg.nav || [] ).forEach( function ( section ) {
			out.push( { label: section.label, hint: ( cfg.i18n && cfg.i18n.section ) || 'Section', url: section.url } );
			( section.tabs || [] ).forEach( function ( tab ) {
				out.push( { label: section.label + ' › ' + tab.label, hint: section.label, url: tab.url } );
			} );
		} );
		return out;
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
		var matches = q ? all.filter( function ( o ) { return o.label.toLowerCase().indexOf( q ) !== -1; } ) : all;
		paletteItems = matches;
		activeIdx = 0;
		palette.list.innerHTML = '';
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
		empty: function ( title, detail, icon ) {
			return '<div class="wpcc-cds-empty">'
				+ ( icon ? '<span class="dashicons ' + WPCC.escHtml( icon ) + ' wpcc-cds-empty__icon" aria-hidden="true"></span>' : '' )
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
