/**
 * Contextual SEO Quick Panel (Option B → Initiative 2 Option B) — in-context
 * governed action surface.
 *
 * Progressive enhancement of the "Generate SEO Suggestion" row action. The anchor
 * stays a working admin-post redirect (the no-JS fallback); when this asset loads it
 * intercepts the click, opens a modal, and carries one item through its FULL governed
 * lifecycle WITHOUT leaving the list — using ONLY the EXISTING governed routes:
 *
 *   POST {restBase}/admin/seo/generate            { post_ids:[id] }      (propose)
 *   GET  {restBase}/admin/proposals/{id}                                 (review)
 *   PUT  {restBase}/admin/proposals/{id}          { final_payload:{…} }  (persist edit)
 *   POST {restBase}/admin/proposals/{id}/apply                           (governed apply)
 *   POST {restBase}/admin/history/{change_id}/rollback                   (governed undo)
 *
 * Generate → Review → Edit → Apply → Undo. Every mutation flows through the SAME
 * governed chokepoint as the Builder (propose → approval → execute → audit; rollback
 * via change_history → seo_restore). There is NO second execution path and NO second
 * rollback path. Apply is mode-aware and the OUTCOME is read from the API
 * response, never assumed from the button label:
 *   - developer  → immediate apply (status `applied`) + one-click Undo.
 *   - client/enterprise → gated (status `pending_approval`); the modal shows
 *     "approval required" BEFORE the click and "submitted" after — never "applied".
 * Edits are persisted via the EXISTING PUT route BEFORE apply (the Trust Polish
 * persist-before-apply pattern), so the visible field values are what get applied —
 * Apply can never silently apply a stale AI suggestion. Server-side permission on the
 * routes is authoritative; the client nonce/gate is convenience.
 */
( function () {
	'use strict';

	var CFG = window.wpccSeoQuickPanel || {};
	var I18N = CFG.i18n || {};
	var MODE = CFG.mode || 'developer';
	var IS_DEV = ( MODE === 'developer' );
	var lastFocus = null;
	var overlay = null;
	var modal = null;
	// Per-open governed-action state (the id we propose for, the draft, the change).
	var ST = { postId: 0, proposalId: '', changeId: '' };

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

	// Editable Suggested field (title = input, description = textarea). The visible
	// value here is the source of truth at Apply time (persist-before-apply).
	function editField( label, value, kind ) {
		var wrap = el( 'div', { class: 'wpcc-qp-field' } );
		var id = 'wpcc-qp-' + kind;
		wrap.appendChild( el( 'label', { class: 'wpcc-qp-label', for: id }, label ) );
		var input;
		if ( kind === 'ed' ) {
			input = el( 'textarea', { id: id, class: 'wpcc-qp-input wpcc-qp-ed', rows: '3' } );
			input.value = value || '';
		} else {
			input = el( 'input', { id: id, type: 'text', class: 'wpcc-qp-input wpcc-qp-et' } );
			input.value = value || '';
		}
		wrap.appendChild( input );
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

		// Suggested column is EDITABLE — review then refine in context.
		var sug = el( 'div', { class: 'wpcc-qp-col wpcc-qp-col--suggested' } );
		sug.appendChild( el( 'h3', { class: 'wpcc-qp-coltitle' }, t( 'suggested' ) ) );
		sug.appendChild( editField( t( 'metaTitle' ), suggested.title, 'et' ) );
		sug.appendChild( editField( t( 'metaDesc' ), suggested.description, 'ed' ) );

		cols.appendChild( cur );
		cols.appendChild( sug );
		body.appendChild( cols );

		if ( provider ) {
			body.appendChild( el( 'p', { class: 'wpcc-qp-prov' }, fmt( t( 'provBy' ), provider, model || '' ) ) );
		}
		// Trust note: this is a draft, nothing has been applied yet.
		body.appendChild( el( 'p', { class: 'wpcc-qp-note' }, t( 'draftNote' ) ) );
		// Approval-required pre-signal (gated modes only) — shown BEFORE the click so
		// the user knows Apply submits for approval rather than applying immediately.
		if ( ! IS_DEV ) {
			body.appendChild( el( 'p', { class: 'wpcc-qp-approval', role: 'note' }, t( 'approvalRequired' ) ) );
		}
		// Inline action message (errors) — does NOT destroy the edited fields.
		body.appendChild( el( 'div', { class: 'wpcc-qp-actionmsg', role: 'status', 'aria-live': 'polite' } ) );

		setStatus( t( 'suggested' ) );
		renderActionFooter();
	}

	function actionMsg( text ) {
		var m = modal && modal._body ? modal._body.querySelector( '.wpcc-qp-actionmsg' ) : null;
		if ( m ) { m.textContent = text || ''; }
	}

	// Footer for the review/edit state: Apply (mode-aware) + Open in Suggestions + Close.
	function renderActionFooter() {
		var footer = modal._footer;
		footer.innerHTML = '';

		var apply = el( 'button', { type: 'button', class: 'button button-primary wpcc-qp-apply' }, IS_DEV ? t( 'applyDev' ) : t( 'applyGate' ) );
		apply.addEventListener( 'click', function () { applyAction( apply ); } );
		footer.appendChild( apply );

		if ( CFG.suggestUrl ) {
			footer.appendChild( el( 'a', { href: CFG.suggestUrl, class: 'button wpcc-qp-open' }, t( 'openSuggest' ) ) );
		}
		var close = el( 'button', { type: 'button', class: 'button wpcc-qp-close' }, t( 'close' ) );
		close.addEventListener( 'click', closeModal );
		footer.appendChild( close );

		// Move focus into the dialog for keyboard users (the primary action).
		( apply || modal ).focus();
	}

	function renderMessage( msg, allowOpen ) {
		var body = modal._body;
		body.innerHTML = '';
		body.appendChild( el( 'p', { class: 'wpcc-qp-msg' }, msg ) );
		setStatus( msg );
		renderNavFooter( !! allowOpen );
	}

	// Footer for non-actionable states (skips / errors): navigate only.
	function renderNavFooter( showOpen ) {
		var footer = modal._footer;
		footer.innerHTML = '';
		if ( showOpen && CFG.suggestUrl ) {
			footer.appendChild( el( 'a', { href: CFG.suggestUrl, class: 'button button-primary wpcc-qp-open' }, t( 'openSuggest' ) ) );
		}
		var close = el( 'button', { type: 'button', class: 'button wpcc-qp-close' }, t( 'close' ) );
		close.addEventListener( 'click', closeModal );
		footer.appendChild( close );
		( footer.querySelector( 'a, button' ) || modal ).focus();
	}

	function renderLoading() {
		var body = modal._body;
		body.innerHTML = '';
		body.appendChild( el( 'p', { class: 'wpcc-qp-msg' }, t( 'generating' ) ) );
		setStatus( t( 'generating' ) );
		modal._footer.innerHTML = '';
	}

	// Post-apply outcome state — reversibility-as-hero: Applied · Reversible · Audited
	// (+ Undo) for a developer apply; Submitted · Audited for a gated submit.
	function renderApplied( status, changeId ) {
		var applied = ( status === 'applied' );
		var body = modal._body;
		body.innerHTML = '';

		var head = el( 'p', { class: 'wpcc-qp-applied' } );
		head.appendChild( el( 'strong', null, applied ? t( 'appliedTitle' ) : t( 'submittedTitle' ) ) );
		if ( applied ) {
			head.appendChild( el( 'span', { class: 'wpcc-qp-chip wpcc-qp-chip--good' }, t( 'chipReversible' ) ) );
		}
		head.appendChild( el( 'span', { class: 'wpcc-qp-chip' }, t( 'chipAudited' ) ) );
		body.appendChild( head );

		body.appendChild( el( 'p', { class: 'wpcc-qp-note' }, applied ? t( 'appliedNote' ) : t( 'submittedNote' ) ) );
		// Visible result line for Undo outcomes.
		body.appendChild( el( 'div', { class: 'wpcc-qp-actionmsg', role: 'status', 'aria-live': 'polite' } ) );

		setStatus( applied ? t( 'appliedTitle' ) : t( 'submittedTitle' ) );
		renderAppliedFooter( applied && !! changeId );
	}

	// Footer for the applied state: Undo (developer + change_id) + Open + Close.
	function renderAppliedFooter( showUndo ) {
		var footer = modal._footer;
		footer.innerHTML = '';
		if ( showUndo ) {
			var undo = el( 'button', { type: 'button', class: 'button wpcc-qp-undo' }, t( 'undo' ) );
			undo.addEventListener( 'click', function () { undoAction( undo ); } );
			footer.appendChild( undo );
		}
		if ( CFG.suggestUrl ) {
			footer.appendChild( el( 'a', { href: CFG.suggestUrl, class: 'button wpcc-qp-open' }, t( 'openSuggest' ) ) );
		}
		var close = el( 'button', { type: 'button', class: 'button button-primary wpcc-qp-close' }, t( 'close' ) );
		close.addEventListener( 'click', closeModal );
		footer.appendChild( close );
		( footer.querySelector( 'a, button' ) || modal ).focus();
	}

	// ── REST calls (existing routes only) ────────────────────────────────────────

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
			}, function () {
				return { ok: r.ok, status: r.status, data: {} };
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
			case 'unsupported_status':
				return { msg: t( 'unsupportedStatus' ), allowOpen: false };
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
		ST.postId = postId;
		ST.proposalId = '';
		ST.changeId = '';
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
		ST.proposalId = id;
		api( 'GET', '/admin/proposals/' + encodeURIComponent( id ), null )
			.then( function ( res ) {
				if ( ! res.ok || ! res.data || res.data.error ) {
					// The draft exists; steer the user to Suggestions to review it.
					renderMessage( t( 'exists' ), true );
					return;
				}
				var p = res.data;
				if ( p.target_id ) { ST.postId = parseInt( p.target_id, 10 ) || ST.postId; }
				renderComparison( priorFrom( p ), suggestedFrom( p ), p.provider, p.model );
			} )
			.catch( function () {
				renderMessage( t( 'exists' ), true );
			} );
	}

	// Apply = persist the VISIBLE edited values (PUT final_payload) THEN apply — the
	// Trust Polish persist-before-apply pattern, so an unsaved edit can never be
	// silently discarded. Reuses the existing governed routes; outcome read from the
	// response (applied | pending_approval), never assumed from the label.
	function applyAction( btn ) {
		if ( ! ST.proposalId ) { return; }
		var titleEl = modal._body.querySelector( '.wpcc-qp-et' );
		var descEl = modal._body.querySelector( '.wpcc-qp-ed' );
		var fp = {
			action: 'seo_update',
			content_id: ST.postId,
			seo: {
				title: titleEl ? titleEl.value : '',
				description: descEl ? descEl.value : '',
			},
		};
		btn.disabled = true;
		setStatus( t( 'applying' ) );
		actionMsg( t( 'applying' ) );
		api( 'PUT', '/admin/proposals/' + encodeURIComponent( ST.proposalId ), { final_payload: fp } )
			.then( function ( saveRes ) {
				if ( ! saveRes.ok ) {
					btn.disabled = false;
					actionMsg( ( saveRes.data && saveRes.data.message ) || t( 'cantApply' ) );
					return null; // do NOT apply stale data if the edit could not be persisted
				}
				return api( 'POST', '/admin/proposals/' + encodeURIComponent( ST.proposalId ) + '/apply', null );
			} )
			.then( function ( res ) {
				if ( ! res ) { return; } // persist failed; already surfaced
				var st = ( res.data && res.data.status ) || '';
				var cid = ( res.data && res.data.change_id ) || '';
				if ( st === 'applied' || st === 'pending_approval' ) {
					ST.changeId = cid;
					renderApplied( st, cid );
				} else {
					btn.disabled = false;
					actionMsg( ( res.data && res.data.message ) || t( 'cantApply' ) );
				}
			} )
			.catch( function () {
				btn.disabled = false;
				actionMsg( t( 'cantApply' ) );
			} );
	}

	// Undo = the EXISTING governed rollback route only (change_history → seo_restore).
	// Developer → immediate revert; client/enterprise → "Undo sent for approval".
	function undoAction( btn ) {
		if ( ! ST.changeId ) { return; }
		btn.disabled = true;
		setStatus( t( 'undoing' ) );
		actionMsg( t( 'undoing' ) );
		api( 'POST', '/admin/history/' + encodeURIComponent( ST.changeId ) + '/rollback', null )
			.then( function ( res ) {
				var inner = ( res.data && res.data.result ) || {};
				if ( inner.status === 'pending_approval' ) {
					actionMsg( t( 'undoSent' ) ); // gated → sent for approval; keep disabled
				} else if ( res.ok && res.data && res.data.success === true && inner.status !== 'confirmation_required' ) {
					actionMsg( t( 'reverted' ) ); // success → reverted
					btn.textContent = t( 'reverted' );
				} else {
					btn.disabled = false; // non-fatal: let the operator retry
					actionMsg( t( 'cantUndo' ) );
				}
			} )
			.catch( function () {
				btn.disabled = false;
				actionMsg( t( 'cantUndo' ) );
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
