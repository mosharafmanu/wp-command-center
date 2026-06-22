/**
 * WP Command Center — Governed Action Panel (generalized Quick Panel).
 *
 * The proven SEO Quick Panel, generalized into ONE config-driven, in-context
 * governed-action surface reusable by every AI content workflow (SEO, Title,
 * Excerpt, Alt Text). It carries a single item through its FULL governed lifecycle
 * WITHOUT leaving the list:
 *
 *   Generate → Review → Edit → Apply → Undo
 *
 * It uses ONLY the EXISTING governed routes — there is NO second execution path and
 * NO second rollback path:
 *   <generate.path>                                   (propose → draft)
 *   GET  {restBase}/admin/proposals/{id}              (review)
 *   PUT  {restBase}/admin/proposals/{id}              (persist edit BEFORE apply)
 *   POST {restBase}/admin/proposals/{id}/apply        (governed apply, mode-aware)
 *   POST {restBase}/admin/history/{change_id}/rollback (governed undo)
 *
 * Apply persists the VISIBLE edited values first (persist-before-apply) so a stale
 * AI suggestion can never be silently applied, and the OUTCOME is read from the API
 * response, never assumed from the button label:
 *   - developer  → immediate apply (status `applied`) + one-click Undo.
 *   - client/enterprise → gated (status `pending_approval`); "approval required"
 *     pre-signal before the click, "submitted" after — never "applied".
 *
 * Each workflow is a DATA-ONLY config under `window.wpccActionPanel.actions[key]`
 * (no JS per workflow). A list-table anchor carries data-wpcc-action="<key>" +
 * data-id; clicking it opens the panel for that workflow. Server-side permission on
 * the routes is authoritative; the client nonce/gate is convenience only. Depends on
 * window.WPCC (the shared admin runtime).
 */
( function () {
	'use strict';

	var R = window.WPCC || null;
	var ROOT = window.wpccActionPanel || {};
	var ACTIONS = ROOT.actions || {};
	var I18N = ROOT.i18n || {};
	var MODE = ROOT.mode || 'developer';
	var IS_DEV = ( MODE === 'developer' );

	var lastFocus = null;
	var overlay = null;
	var modal = null;
	// Per-open governed-action state: which workflow, its config, the ids in flight.
	// `heading` overrides the modal title for the chooser step (no cfg yet).
	var ST = { key: '', cfg: null, postId: 0, proposalId: '', changeId: '', heading: '' };

	function t( key ) {
		return ( I18N && I18N[ key ] ) || '';
	}
	function el( tag, attrs, text ) { return R.el( tag, attrs, text ); }
	function fmt( str, a, b ) { return R.fmt( str, a, b ); }

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
		var heading = ST.heading || ( ST.cfg ? ST.cfg.title : t( 'title' ) );
		header.appendChild( el( 'h2', { id: 'wpcc-qp-title', class: 'wpcc-qp-h' }, heading ) );
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
			R.a11y.trapTab( modal, e );
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

	// Editable Suggested field. The visible value here is the source of truth at
	// Apply time (persist-before-apply). data-key links the input to its field config.
	function editField( fieldCfg, value ) {
		var wrap = el( 'div', { class: 'wpcc-qp-field' } );
		var id = 'wpcc-qp-f-' + fieldCfg.key;
		wrap.appendChild( el( 'label', { class: 'wpcc-qp-label', for: id }, fieldCfg.label ) );
		var input;
		if ( fieldCfg.type === 'textarea' ) {
			input = el( 'textarea', { id: id, class: 'wpcc-qp-input wpcc-qp-ed', rows: '3', 'data-key': fieldCfg.key } );
		} else {
			input = el( 'input', { id: id, type: 'text', class: 'wpcc-qp-input wpcc-qp-et', 'data-key': fieldCfg.key } );
		}
		input.value = value || '';
		wrap.appendChild( input );
		return wrap;
	}

	function renderComparison( current, suggested, provider, model ) {
		var body = modal._body;
		body.innerHTML = '';

		var cols = el( 'div', { class: 'wpcc-qp-cols' } );

		var cur = el( 'div', { class: 'wpcc-qp-col' } );
		cur.appendChild( el( 'h3', { class: 'wpcc-qp-coltitle' }, t( 'current' ) ) );

		// Suggested column is EDITABLE — review then refine in context.
		var sug = el( 'div', { class: 'wpcc-qp-col wpcc-qp-col--suggested' } );
		sug.appendChild( el( 'h3', { class: 'wpcc-qp-coltitle' }, t( 'suggested' ) ) );

		ST.cfg.fields.forEach( function ( f ) {
			cur.appendChild( field( f.label, current[ f.key ] ) );
			sug.appendChild( editField( f, suggested[ f.key ] ) );
		} );

		cols.appendChild( cur );
		cols.appendChild( sug );
		body.appendChild( cols );

		if ( provider ) {
			body.appendChild( el( 'p', { class: 'wpcc-qp-prov' }, fmt( t( 'provBy' ), provider, model || '' ) ) );
		}
		// Trust note: this is a draft, nothing has been applied yet.
		body.appendChild( el( 'p', { class: 'wpcc-qp-note' }, t( 'draftNote' ) ) );
		// Approval-required pre-signal (gated modes only) — shown BEFORE the click.
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

	// Footer for the review/edit state: Apply (mode-aware) + Open in Builder + Close.
	function renderActionFooter() {
		var footer = modal._footer;
		footer.innerHTML = '';

		var apply = el( 'button', { type: 'button', class: 'button button-primary wpcc-qp-apply' }, IS_DEV ? t( 'applyDev' ) : t( 'applyGate' ) );
		apply.addEventListener( 'click', function () { applyAction( apply ); } );
		footer.appendChild( apply );

		appendOpen( footer, 'button' );
		var close = el( 'button', { type: 'button', class: 'button wpcc-qp-close' }, t( 'close' ) );
		close.addEventListener( 'click', closeModal );
		footer.appendChild( close );

		( apply || modal ).focus();
	}

	function appendOpen( footer, variant ) {
		var url = ST.cfg.suggestUrl || ROOT.suggestUrl || '';
		if ( url ) {
			footer.appendChild( el( 'a', { href: url, class: 'button wpcc-qp-open' }, t( 'openSuggest' ) ) );
		}
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
		var url = ST.cfg.suggestUrl || ROOT.suggestUrl || '';
		if ( showOpen && url ) {
			footer.appendChild( el( 'a', { href: url, class: 'button button-primary wpcc-qp-open' }, t( 'openSuggest' ) ) );
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

	// Post-apply outcome — reversibility-as-hero: Applied · Reversible · Audited (+
	// Undo) for a developer apply; Submitted · Audited for a gated submit.
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
		body.appendChild( el( 'div', { class: 'wpcc-qp-actionmsg', role: 'status', 'aria-live': 'polite' } ) );

		setStatus( applied ? t( 'appliedTitle' ) : t( 'submittedTitle' ) );
		renderAppliedFooter( applied && !! changeId );
	}

	function renderAppliedFooter( showUndo ) {
		var footer = modal._footer;
		footer.innerHTML = '';
		if ( showUndo ) {
			var undo = el( 'button', { type: 'button', class: 'button wpcc-qp-undo' }, t( 'undo' ) );
			undo.addEventListener( 'click', function () { undoAction( undo ); } );
			footer.appendChild( undo );
		}
		appendOpen( footer );
		var close = el( 'button', { type: 'button', class: 'button button-primary wpcc-qp-close' }, t( 'close' ) );
		close.addEventListener( 'click', closeModal );
		footer.appendChild( close );
		( footer.querySelector( 'a, button' ) || modal ).focus();
	}

	// ── REST calls (existing routes only) ────────────────────────────────────────

	function api( method, path, bodyObj ) {
		return R.api( method, ROOT.restBase + path, ROOT.nonce, bodyObj );
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

	// ── Config-driven payload mapping (declarative; no per-workflow JS) ──────────

	function generateBody( id ) {
		var b = ST.cfg.generate.body;
		if ( b === 'post_ids' ) { return { post_ids: [ id ] }; }
		if ( b === 'attachment_ids' ) { return { attachment_ids: [ id ] }; }
		if ( b && b.generate_kind ) { return { generate: { kind: b.generate_kind, post_id: id } }; }
		return { post_ids: [ id ] };
	}

	function suggestedFrom( proposal ) {
		var src = ( proposal && ( proposal.final_payload || proposal.payload ) ) || {};
		var base = ST.cfg.suggest ? ( src[ ST.cfg.suggest ] || {} ) : src;
		var out = {};
		ST.cfg.fields.forEach( function ( f ) { out[ f.key ] = ( base && base[ f.key ] ) || ''; } );
		return out;
	}

	function priorFrom( proposal ) {
		var prior = ( proposal && proposal.prior ) || {};
		var out = {};
		ST.cfg.fields.forEach( function ( f ) {
			var k = f.prior || f.key;
			out[ f.key ] = ( prior && prior[ k ] ) || '';
		} );
		return out;
	}

	function buildFinalPayload( values ) {
		var ap = ST.cfg.apply;
		var fp = { action: ap.action };
		fp[ ap.idKey ] = ST.postId;
		if ( ap.nest ) {
			fp[ ap.nest ] = values;
		} else {
			Object.keys( values ).forEach( function ( k ) { fp[ k ] = values[ k ]; } );
		}
		return fp;
	}

	// ── Lifecycle ────────────────────────────────────────────────────────────────

	function generate( postId ) {
		ST.postId = postId;
		ST.proposalId = '';
		ST.changeId = '';
		renderLoading();
		api( 'POST', ST.cfg.generate.path, generateBody( postId ) )
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

	// Apply = persist the VISIBLE edited values (PUT final_payload) THEN apply.
	// Outcome read from the response (applied | pending_approval), never the label.
	function applyAction( btn ) {
		if ( ! ST.proposalId ) { return; }
		var values = {};
		ST.cfg.fields.forEach( function ( f ) {
			var input = modal._body.querySelector( '.wpcc-qp-input[data-key="' + f.key + '"]' );
			values[ f.key ] = input ? input.value : '';
		} );
		var fp = buildFinalPayload( values );

		btn.disabled = true;
		setStatus( t( 'applying' ) );
		actionMsg( t( 'applying' ) );
		api( 'PUT', '/admin/proposals/' + encodeURIComponent( ST.proposalId ), { final_payload: fp } )
			.then( function ( saveRes ) {
				if ( ! saveRes.ok ) {
					btn.disabled = false;
					actionMsg( ( saveRes.data && saveRes.data.message ) || t( 'cantApply' ) );
					return null; // never apply stale data if the edit could not be persisted
				}
				return api( 'POST', '/admin/proposals/' + encodeURIComponent( ST.proposalId ) + '/apply', null );
			} )
			.then( function ( res ) {
				if ( ! res ) { return; }
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

	// Undo = the EXISTING governed rollback route only (change_history → runtime
	// rollback). Developer → immediate revert; gated → "Undo sent for approval".
	function undoAction( btn ) {
		if ( ! ST.changeId ) { return; }
		btn.disabled = true;
		setStatus( t( 'undoing' ) );
		actionMsg( t( 'undoing' ) );
		api( 'POST', '/admin/history/' + encodeURIComponent( ST.changeId ) + '/rollback', null )
			.then( function ( res ) {
				var inner = ( res.data && res.data.result ) || {};
				if ( inner.status === 'pending_approval' ) {
					actionMsg( t( 'undoSent' ) );
				} else if ( res.ok && res.data && res.data.success === true && inner.status !== 'confirmation_required' ) {
					actionMsg( t( 'reverted' ) );
					btn.textContent = t( 'reverted' );
				} else {
					btn.disabled = false;
					actionMsg( t( 'cantUndo' ) );
				}
			} )
			.catch( function () {
				btn.disabled = false;
				actionMsg( t( 'cantUndo' ) );
			} );
	}

	// ── Chooser (AI Assist → pick which action to run for this object) ───────────

	// Resolve which action ids apply: prefer the server-computed data-actions list
	// (per-object: type + enabled + status/eligibility), else fall back to filtering
	// the localized actions by object type.
	function resolveAssistIds( link, type ) {
		var listed = ( link.getAttribute( 'data-actions' ) || '' )
			.split( ',' )
			.map( function ( s ) { return s.trim(); } )
			.filter( function ( k ) { return k && ACTIONS[ k ]; } );
		if ( listed.length ) {
			return listed;
		}
		return Object.keys( ACTIONS ).filter( function ( k ) {
			var ot = ACTIONS[ k ].objectTypes || [];
			return ot.indexOf( type ) >= 0;
		} );
	}

	// Begin one action's governed lifecycle (shared by the chooser and single-action path).
	function startAction( key, id ) {
		ST.key = key;
		ST.cfg = ACTIONS[ key ];
		var h = modal ? modal.querySelector( '#wpcc-qp-title' ) : null;
		if ( h ) { h.textContent = ( ST.cfg && ST.cfg.title ) || t( 'title' ); }
		generate( id );
	}

	// ── Row dropdown chooser (compact menu off the ✨ WPCC AI row action) ─────────
	// A small WAI-ARIA menu anchored to the row action — NOT the big modal. Picking an
	// item then opens the existing Governed Action Panel for that action. Keyboard:
	// Up/Down/Home/End move, Enter/Space select, Esc closes (returns focus to trigger),
	// Tab/outside-click close.

	var dropdown = null; // { el, trigger }
	var closeTimer = null; // hover-intent close delay (prevents flicker crossing the gap)

	function cancelClose() { if ( closeTimer ) { clearTimeout( closeTimer ); closeTimer = null; } }

	// Close after a short grace period — cancelled if the pointer re-enters the trigger
	// or the menu, and skipped while keyboard focus is inside the menu.
	function scheduleClose() {
		cancelClose();
		closeTimer = setTimeout( function () {
			if ( dropdown && ( dropdown.el.contains( document.activeElement ) || document.activeElement === dropdown.trigger ) ) { return; }
			closeDropdown( false );
		}, 220 );
	}

	function closeDropdown( returnFocus ) {
		cancelClose();
		if ( ! dropdown ) { return; }
		document.removeEventListener( 'click', onDocClick, true );
		document.removeEventListener( 'keydown', onMenuKeydown, true );
		var trigger = dropdown.trigger;
		if ( trigger ) { trigger.setAttribute( 'aria-expanded', 'false' ); }
		// Release the row's native action strip back to WP's hover behavior.
		if ( dropdown.rowActions ) { dropdown.rowActions.classList.remove( 'wpcc-ai-keep-open' ); }
		if ( dropdown.el && dropdown.el.parentNode ) { dropdown.el.parentNode.removeChild( dropdown.el ); }
		dropdown = null;
		if ( returnFocus && trigger && typeof trigger.focus === 'function' ) { trigger.focus(); }
	}

	function menuItems() {
		return dropdown ? Array.prototype.slice.call( dropdown.el.querySelectorAll( '.wpcc-ai-menu__item' ) ) : [];
	}

	function onDocClick( e ) {
		if ( dropdown && ! dropdown.el.contains( e.target ) && e.target !== dropdown.trigger ) {
			closeDropdown( false );
		}
	}

	function onMenuKeydown( e ) {
		if ( ! dropdown ) { return; }
		var items = menuItems();
		var idx = items.indexOf( document.activeElement );
		if ( e.key === 'Escape' ) { e.preventDefault(); closeDropdown( true ); }
		else if ( e.key === 'ArrowDown' ) { e.preventDefault(); ( items[ ( idx + 1 ) % items.length ] || items[ 0 ] ).focus(); }
		else if ( e.key === 'ArrowUp' ) { e.preventDefault(); ( items[ ( idx - 1 + items.length ) % items.length ] || items[ 0 ] ).focus(); }
		else if ( e.key === 'Home' ) { e.preventDefault(); items[ 0 ] && items[ 0 ].focus(); }
		else if ( e.key === 'End' ) { e.preventDefault(); items[ items.length - 1 ] && items[ items.length - 1 ].focus(); }
		else if ( e.key === 'Tab' ) { closeDropdown( false ); }
	}

	function positionDropdown( menu, trigger ) {
		var r = trigger.getBoundingClientRect();
		var sx = window.pageXOffset || document.documentElement.scrollLeft;
		var sy = window.pageYOffset || document.documentElement.scrollTop;
		menu.style.position = 'absolute';
		menu.style.top = ( sy + r.bottom + 2 ) + 'px';
		menu.style.left = ( sx + r.left ) + 'px';
		menu.style.zIndex = '100000';
	}

	// Pick an action from the menu → close it, then open the governed panel for it.
	function chooseFromMenu( key, id ) {
		var trigger = dropdown ? dropdown.trigger : lastFocus;
		closeDropdown( false );
		lastFocus = trigger; // panel restores focus here when it closes
		ST.heading = '';
		ST.cfg = ACTIONS[ key ];
		buildModal();
		startAction( key, id );
	}

	function openDropdown( trigger, ids, id, focusFirst ) {
		closeDropdown( false );
		var menu = el( 'div', { class: 'wpcc-ai-menu', role: 'menu', 'aria-label': t( 'chooserTitle' ) } );
		ids.forEach( function ( key ) {
			var cfg = ACTIONS[ key ];
			if ( ! cfg ) { return; }
			var item = el( 'button', { type: 'button', class: 'wpcc-ai-menu__item', role: 'menuitem', tabindex: '-1', 'data-action-id': key } );
			if ( cfg.icon ) {
				item.appendChild( el( 'span', { class: 'dashicons ' + cfg.icon + ' wpcc-ai-menu__ic', 'aria-hidden': 'true' } ) );
			}
			item.appendChild( el( 'span', { class: 'wpcc-ai-menu__label' }, cfg.label || cfg.title ) );
			item.addEventListener( 'click', function ( ev ) { ev.preventDefault(); chooseFromMenu( key, id ); } );
			menu.appendChild( item );
		} );
		// Keep the menu open while the pointer is in it; close (debounced) when it leaves.
		menu.addEventListener( 'mouseenter', cancelClose );
		menu.addEventListener( 'mouseleave', scheduleClose );
		document.body.appendChild( menu );
		// Pin the row's native action strip (Edit | Quick Edit | Trash | …) visible while
		// the menu is open — the menu lives on <body>, so the pointer leaves the row and
		// WP would otherwise hide the hover-only strip. Released in closeDropdown().
		var rowActions = trigger.closest ? trigger.closest( '.row-actions' ) : null;
		if ( rowActions ) { rowActions.classList.add( 'wpcc-ai-keep-open' ); }
		dropdown = { el: menu, trigger: trigger, rowActions: rowActions };
		positionDropdown( menu, trigger );
		trigger.setAttribute( 'aria-expanded', 'true' );
		document.addEventListener( 'click', onDocClick, true );
		document.addEventListener( 'keydown', onMenuKeydown, true );
		if ( focusFirst ) {
			var first = menu.querySelector( '.wpcc-ai-menu__item' );
			if ( first ) { first.focus(); }
		}
	}

	// Resolve a trigger to {ids,id}; returns null when nothing applies (href fallback).
	function triggerActions( trigger ) {
		var id = parseInt( trigger.getAttribute( 'data-id' ), 10 );
		if ( ! id ) { return null; }
		var ids = resolveAssistIds( trigger, trigger.getAttribute( 'data-type' ) || '' );
		return ids.length ? { ids: ids, id: id } : null;
	}

	// Hover / focus opens the MENU only (never the heavy panel) and only when there is a
	// real choice (>1 action); a single-action row is left to click/keyboard to open its panel.
	function hoverOpen( trigger ) {
		cancelClose();
		if ( dropdown && dropdown.trigger === trigger ) { return; }
		var a = triggerActions( trigger );
		if ( a && a.ids.length > 1 ) { openDropdown( trigger, a.ids, a.id, false ); }
	}

	// ── Bind (delegated — list tables re-render / paginate) ──────────────────────

	function onClick( e ) {
		var link = e.target.closest && e.target.closest( 'a[data-wpcc-action]' );
		if ( ! link ) {
			return;
		}
		var key = link.getAttribute( 'data-wpcc-action' );
		var id = parseInt( link.getAttribute( 'data-id' ), 10 );
		if ( ! id ) {
			return; // let the href fallback run
		}

		// Consolidated "✨ WPCC AI" entry.
		if ( key === 'assist' ) {
			// Already open (hover/focus opened it on mousedown) → keep it open; the menu
			// closes on Esc / outside click / pointer-leave / selecting an action.
			if ( dropdown && dropdown.trigger === link ) { e.preventDefault(); return; }
			var a = triggerActions( link );
			if ( ! a ) {
				return; // nothing applicable — let the href fallback (Builder) run
			}
			e.preventDefault();
			lastFocus = link;
			// Exactly one applicable action → skip the menu, open its panel directly.
			if ( a.ids.length === 1 ) {
				ST.heading = '';
				ST.cfg = ACTIONS[ a.ids[ 0 ] ];
				buildModal();
				startAction( a.ids[ 0 ], id );
				return;
			}
			// Multiple → compact row dropdown (focus the first item for keyboard/click).
			openDropdown( link, a.ids, id, true );
			return;
		}

		// Back-compat: a specific per-action data-wpcc-action key (legacy anchors).
		var cfg = ACTIONS[ key ];
		if ( ! cfg ) {
			return; // unknown workflow — let the href fallback run
		}
		e.preventDefault();
		lastFocus = link;
		ST.heading = '';
		ST.key = key;
		ST.cfg = cfg;
		buildModal();
		generate( id );
	}

	// Hover opens the menu; leaving the trigger debounce-closes it (cancelled if the
	// pointer lands in the menu — see openDropdown's mouseenter/leave).
	function onMouseOver( e ) {
		var link = e.target.closest && e.target.closest( 'a.wpcc-ai-assist[data-wpcc-action="assist"]' );
		if ( link ) { hoverOpen( link ); }
	}
	function onMouseOut( e ) {
		if ( ! dropdown ) { return; }
		var link = e.target.closest && e.target.closest( 'a.wpcc-ai-assist[data-wpcc-action="assist"]' );
		if ( ! link || link !== dropdown.trigger ) { return; }
		var to = e.relatedTarget;
		var stillInside = to && to.closest && ( to === dropdown.trigger || ( to.closest && to.closest( '.wpcc-ai-menu' ) ) );
		if ( ! stillInside ) { scheduleClose(); }
	}

	// Keyboard focus on the trigger opens the menu (focus stays on the trigger; ArrowDown
	// moves into it). Focus leaving both trigger and menu closes it.
	function onFocusIn( e ) {
		var link = e.target.closest && e.target.closest( 'a.wpcc-ai-assist[data-wpcc-action="assist"]' );
		if ( link ) { hoverOpen( link ); }
	}
	function onFocusOut() {
		if ( ! dropdown ) { return; }
		setTimeout( function () {
			var ae = document.activeElement;
			if ( dropdown && ! dropdown.el.contains( ae ) && ae !== dropdown.trigger ) { closeDropdown( false ); }
		}, 0 );
	}

	// Space / ArrowDown on the trigger opens + enters the menu (Enter fires native click).
	function onTriggerKeydown( e ) {
		var link = e.target.closest && e.target.closest( 'a.wpcc-ai-assist[data-wpcc-action="assist"]' );
		if ( ! link ) { return; }
		// Already open for this trigger → let the menu's own key handler navigate.
		if ( dropdown && dropdown.trigger === link ) { return; }
		if ( e.key === ' ' || e.key === 'Spacebar' || e.key === 'ArrowDown' || e.key === 'Enter' ) {
			var a = triggerActions( link );
			if ( ! a ) { return; }
			e.preventDefault();
			lastFocus = link;
			if ( a.ids.length === 1 ) {
				ST.heading = ''; ST.cfg = ACTIONS[ a.ids[ 0 ] ]; buildModal(); startAction( a.ids[ 0 ], a.id );
			} else {
				openDropdown( link, a.ids, a.id, true );
			}
		}
	}

	if ( ! R || ! ROOT.restBase || ! ROOT.nonce ) {
		return; // runtime missing or not configured — leave the <a> redirect fallback intact
	}
	document.addEventListener( 'click', onClick );
	document.addEventListener( 'mouseover', onMouseOver );
	document.addEventListener( 'mouseout', onMouseOut );
	document.addEventListener( 'focusin', onFocusIn );
	document.addEventListener( 'focusout', onFocusOut, true );
	document.addEventListener( 'keydown', onTriggerKeydown );
} )();
