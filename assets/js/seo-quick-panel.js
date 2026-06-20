/**
 * Contextual SEO Quick Panel (Option B) — in-context AJAX modal.
 *
 * Progressive enhancement of the "Generate SEO Suggestion" row action. The anchor
 * stays a working admin-post redirect (the no-JS fallback); when this asset loads it
 * intercepts the click, opens a modal, and uses the EXISTING governed REST routes to
 * propose a draft and show it WITHOUT leaving the list:
 *
 *   POST {restBase}/admin/seo/generate   { post_ids:[id] }
 *   GET  {restBase}/admin/proposals/{created_id}
 *
 * It is DRAFTS ONLY — it NEVER applies, NEVER writes SEO meta, NEVER calls an apply
 * or rollback route. Modal actions only navigate (Open in Suggestions / Review /
 * Close). Server-side permission on the routes is authoritative; this is convenience.
 */
( function () {
	'use strict';

	var CFG = window.wpccSeoQuickPanel || {};
	var I18N = CFG.i18n || {};
	var lastFocus = null;
	var overlay = null;
	var modal = null;

	function t( key ) {
		return ( I18N && I18N[ key ] ) || '';
	}

	/** sprintf-lite: replaces %1$s / %2$s positional tokens. */
	function fmt( str, a, b ) {
		return String( str )
			.replace( '%1$s', a == null ? '' : a )
			.replace( '%2$s', b == null ? '' : b );
	}

	function el( tag, attrs, text ) {
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
	}

	// ── Modal shell (a11y: role=dialog, aria-modal, labelled, focus trap) ──────

	function buildModal() {
		overlay = el( 'div', { class: 'wpcc-qp-overlay', 'aria-hidden': 'false' } );
		modal = el( 'div', {
			class: 'wpcc-qp-modal',
			role: 'dialog',
			'aria-modal': 'true',
			'aria-labelledby': 'wpcc-qp-title',
			'aria-describedby': 'wpcc-qp-status',
			tabindex: '-1',
		} );

		var header = el( 'div', { class: 'wpcc-qp-header' } );
		header.appendChild( el( 'h2', { id: 'wpcc-qp-title', class: 'wpcc-qp-h' }, t( 'title' ) ) );
		var closeX = el( 'button', { type: 'button', class: 'wpcc-qp-x', 'aria-label': t( 'close' ) }, '×' );
		closeX.addEventListener( 'click', closeModal );
		header.appendChild( closeX );

		// role=status / aria-live so screen readers announce progress + outcomes.
		var status = el( 'div', { id: 'wpcc-qp-status', class: 'wpcc-qp-status', role: 'status', 'aria-live': 'polite' } );
		var body = el( 'div', { class: 'wpcc-qp-body' } );
		var footer = el( 'div', { class: 'wpcc-qp-footer' } );

		modal.appendChild( header );
		modal.appendChild( status );
		modal.appendChild( body );
		modal.appendChild( footer );
		overlay.appendChild( modal );
		document.body.appendChild( overlay );

		overlay.addEventListener( 'mousedown', function ( e ) {
			if ( e.target === overlay ) {
				closeModal();
			}
		} );
		document.addEventListener( 'keydown', onKeydown, true );

		modal._status = status;
		modal._body = body;
		modal._footer = footer;
		return modal;
	}

	function onKeydown( e ) {
		if ( ! overlay ) {
			return;
		}
		if ( e.key === 'Escape' ) {
			e.preventDefault();
			closeModal();
			return;
		}
		if ( e.key === 'Tab' ) {
			trapTab( e );
		}
	}

	function focusable() {
		return Array.prototype.slice.call(
			modal.querySelectorAll(
				'a[href], button:not([disabled]), textarea, input, [tabindex]:not([tabindex="-1"])'
			)
		).filter( function ( n ) {
			return n.offsetParent !== null || n === document.activeElement;
		} );
	}

	function trapTab( e ) {
		var items = focusable();
		if ( ! items.length ) {
			e.preventDefault();
			modal.focus();
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
	}

	function closeModal() {
		if ( ! overlay ) {
			return;
		}
		document.removeEventListener( 'keydown', onKeydown, true );
		overlay.parentNode && overlay.parentNode.removeChild( overlay );
		overlay = null;
		modal = null;
		if ( lastFocus && typeof lastFocus.focus === 'function' ) {
			lastFocus.focus();
		}
	}

	function setStatus( msg ) {
		if ( modal && modal._status ) {
			modal._status.textContent = msg || '';
		}
	}

	// ── Rendering ──────────────────────────────────────────────────────────────

	function field( label, value ) {
		var wrap = el( 'div', { class: 'wpcc-qp-field' } );
		wrap.appendChild( el( 'div', { class: 'wpcc-qp-label' }, label ) );
		wrap.appendChild( el( 'div', { class: 'wpcc-qp-value' }, value || t( 'empty' ) ) );
		return wrap;
	}

	function renderComparison( current, suggested, provider, model ) {
		var body = modal._body;
		body.innerHTML = '';

		var cols = el( 'div', { class: 'wpcc-qp-cols' } );

		var cur = el( 'div', { class: 'wpcc-qp-col' } );
		cur.appendChild( el( 'h3', { class: 'wpcc-qp-coltitle' }, t( 'current' ) ) );
		cur.appendChild( field( t( 'metaTitle' ), current.title ) );
		cur.appendChild( field( t( 'metaDesc' ), current.description ) );

		var sug = el( 'div', { class: 'wpcc-qp-col wpcc-qp-col--suggested' } );
		sug.appendChild( el( 'h3', { class: 'wpcc-qp-coltitle' }, t( 'suggested' ) ) );
		sug.appendChild( field( t( 'metaTitle' ), suggested.title ) );
		sug.appendChild( field( t( 'metaDesc' ), suggested.description ) );

		cols.appendChild( cur );
		cols.appendChild( sug );
		body.appendChild( cols );

		if ( provider ) {
			body.appendChild( el( 'p', { class: 'wpcc-qp-prov' }, fmt( t( 'provBy' ), provider, model || '' ) ) );
		}
		// Trust note: this is a draft, nothing has been applied.
		body.appendChild( el( 'p', { class: 'wpcc-qp-note' }, t( 'draftNote' ) ) );

		setStatus( t( 'suggested' ) );
		renderFooter( true );
	}

	function renderMessage( msg, allowOpen ) {
		var body = modal._body;
		body.innerHTML = '';
		body.appendChild( el( 'p', { class: 'wpcc-qp-msg' }, msg ) );
		setStatus( msg );
		renderFooter( !! allowOpen );
	}

	function renderLoading() {
		var body = modal._body;
		body.innerHTML = '';
		body.appendChild( el( 'p', { class: 'wpcc-qp-msg' }, t( 'generating' ) ) );
		setStatus( t( 'generating' ) );
		modal._footer.innerHTML = '';
	}

	function renderFooter( showOpen ) {
		var footer = modal._footer;
		footer.innerHTML = '';
		if ( showOpen && CFG.suggestUrl ) {
			var open = el( 'a', { href: CFG.suggestUrl, class: 'button button-primary wpcc-qp-open' }, t( 'openSuggest' ) );
			footer.appendChild( open );
		}
		var close = el( 'button', { type: 'button', class: 'button wpcc-qp-close' }, t( 'close' ) );
		close.addEventListener( 'click', closeModal );
		footer.appendChild( close );

		// Move focus into the dialog for keyboard users.
		var firstBtn = footer.querySelector( 'a, button' );
		( firstBtn || modal ).focus();
	}

	// ── REST calls (existing routes only — drafts only) ─────────────────────────

	function api( method, path, bodyObj ) {
		var opts = {
			method: method,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': CFG.nonce || '', 'Content-Type': 'application/json' },
		};
		if ( bodyObj ) {
			opts.body = JSON.stringify( bodyObj );
		}
		return fetch( CFG.restBase + path, opts ).then( function ( r ) {
			return r.json().then( function ( data ) {
				return { ok: r.ok, status: r.status, data: data };
			} );
		} );
	}

	function skipMessage( reason ) {
		switch ( reason ) {
			case 'has_open_proposal':
				return { msg: t( 'exists' ), allowOpen: true };
			case 'no_provider':
				return { msg: t( 'noProvider' ), allowOpen: false };
			case 'no_seo_plugin':
				return { msg: t( 'noPlugin' ), allowOpen: false };
			case 'not_published':
				return { msg: t( 'notPublished' ), allowOpen: false };
			default:
				return { msg: t( 'failed' ), allowOpen: false };
		}
	}

	function suggestedFrom( proposal ) {
		// Mirror the Builder's final_payload-first rule: an edited draft wins, else
		// the original AI payload. A freshly generated draft has no final_payload.
		var fp = proposal && proposal.final_payload && proposal.final_payload.seo;
		var pp = proposal && proposal.payload && proposal.payload.seo;
		var seo = fp || pp || {};
		return { title: seo.title || '', description: seo.description || '' };
	}

	function priorFrom( proposal ) {
		var prior = ( proposal && proposal.prior ) || {};
		return { title: prior.title || '', description: prior.description || '' };
	}

	function generate( postId ) {
		renderLoading();
		api( 'POST', '/admin/seo/generate', { post_ids: [ postId ] } )
			.then( function ( res ) {
				if ( ! res.ok || ! res.data || res.data.error ) {
					renderMessage( t( 'error' ), false );
					return;
				}
				var env = res.data;
				if ( env.created && env.created.length ) {
					fetchProposal( String( env.created[ 0 ] ) );
					return;
				}
				if ( env.skipped && env.skipped.length ) {
					var s = skipMessage( env.skipped[ 0 ].reason );
					renderMessage( s.msg, s.allowOpen );
					return;
				}
				renderMessage( t( 'failed' ), false );
			} )
			.catch( function () {
				renderMessage( t( 'error' ), false );
			} );
	}

	function fetchProposal( id ) {
		api( 'GET', '/admin/proposals/' + encodeURIComponent( id ), null )
			.then( function ( res ) {
				if ( ! res.ok || ! res.data || res.data.error ) {
					// The draft exists; just steer the user to Suggestions to review it.
					renderMessage( t( 'exists' ), true );
					return;
				}
				var p = res.data;
				renderComparison( priorFrom( p ), suggestedFrom( p ), p.provider, p.model );
			} )
			.catch( function () {
				renderMessage( t( 'exists' ), true );
			} );
	}

	// ── Bind (delegated — list tables re-render / paginate) ──────────────────────

	function onClick( e ) {
		var link = e.target.closest && e.target.closest( 'a.wpcc-seo-quickgen' );
		if ( ! link ) {
			return;
		}
		var id = parseInt( link.getAttribute( 'data-id' ), 10 );
		if ( ! id ) {
			return; // let the href fallback run
		}
		e.preventDefault();
		lastFocus = link;
		buildModal();
		generate( id );
	}

	if ( ! CFG.restBase || ! CFG.nonce ) {
		return; // not configured — leave the <a> redirect fallback intact
	}
	document.addEventListener( 'click', onClick );
} )();
