/**
 * WP Command Center — shared admin runtime (D1 closure).
 *
 * The single home for the DOM/format/fetch/accessibility helpers that every WPCC
 * admin surface previously copy-pasted inline (escHtml, sprintf-lite, a focus trap,
 * a nonce'd JSON fetch). Surfaces depend on this script and call `window.WPCC.*`
 * instead of re-declaring helpers — one definition, consistent a11y + i18n behavior.
 *
 * Plugin-agnostic and side-effect-free: it registers no listeners and touches no
 * DOM on load; it only exposes helpers. Load it BEFORE any consumer script (declare
 * it as a script dependency).
 */
( function () {
	'use strict';

	var WPCC = window.WPCC || {};

	/** Create an element with attributes + text content. */
	WPCC.el = function ( tag, attrs, text ) {
		var node = document.createElement( tag );
		if ( attrs ) {
			Object.keys( attrs ).forEach( function ( k ) {
				node.setAttribute( k, attrs[ k ] );
			} );
		}
		if ( text != null ) {
			node.textContent = text;
		}
		return node;
	};

	/** sprintf-lite: positional %1$s / %2$s / %3$s replacement (escaping-safe — text only). */
	WPCC.fmt = function ( str, a, b, c ) {
		return String( str )
			.replace( '%1$s', a == null ? '' : a )
			.replace( '%2$s', b == null ? '' : b )
			.replace( '%3$s', c == null ? '' : c );
	};

	/** HTML-escape a string for safe innerHTML interpolation (defense in depth). */
	WPCC.escHtml = function ( s ) {
		var d = document.createElement( 'div' );
		d.textContent = s == null ? '' : String( s );
		return d.innerHTML;
	};

	/**
	 * Nonce-authenticated JSON fetch returning a normalized envelope (never throws on
	 * a non-2xx — the caller branches on `ok`). Same-origin cookie auth + X-WP-Nonce.
	 *
	 * @returns Promise<{ ok:boolean, status:number, data:object }>
	 */
	WPCC.api = function ( method, url, nonce, bodyObj ) {
		var opts = {
			method: method,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': nonce || '', 'Content-Type': 'application/json' },
		};
		if ( bodyObj ) {
			opts.body = JSON.stringify( bodyObj );
		}
		return fetch( url, opts ).then( function ( r ) {
			return r.json().then( function ( data ) {
				return { ok: r.ok, status: r.status, data: data };
			}, function () {
				return { ok: r.ok, status: r.status, data: {} };
			} );
		} );
	};

	/** Accessibility helpers — focus management for modal/dialog surfaces. */
	WPCC.a11y = {
		/** Visible, focusable descendants of a container, in tab order. */
		focusable: function ( root ) {
			return Array.prototype.slice.call(
				root.querySelectorAll(
					'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])'
				)
			).filter( function ( n ) {
				return n.offsetParent !== null || n === document.activeElement;
			} );
		},
		/** Cycle Tab/Shift+Tab focus within `root` (call from a keydown handler). */
		trapTab: function ( root, e ) {
			var items = WPCC.a11y.focusable( root );
			if ( ! items.length ) {
				e.preventDefault();
				root.focus();
				return;
			}
			var first = items[ 0 ];
			var last = items[ items.length - 1 ];
			if ( e.shiftKey && document.activeElement === first ) {
				e.preventDefault();
				last.focus();
			} else if ( ! e.shiftKey && document.activeElement === last ) {
				e.preventDefault();
				first.focus();
			}
		},
	};

	window.WPCC = WPCC;
} )();
