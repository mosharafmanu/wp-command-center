<?php
/**
 * Experience Layer — unified Command Center Home (Overview).
 *
 * The single, read-only home that resolves the two-dashboard conflict (UX-2). It is
 * hosted inside the AppShell canvas (the shell renders the page <h1>/brand, the
 * security-posture pill, the Builder/Engineer toggle, and ⌘K), and aggregates the
 * platform via the EXISTING cookie-authed admin REST reads — it adds no routes:
 *
 *   - /admin/dashboard  → security posture, platform invariants, and the Approvals /
 *     Operations / Tokens / Change History roll-ups + the recent change activity.
 *   - /admin/proposals  → the Governed Action (AI) workflow summary.
 *
 * Sections: a readiness strip (onboarding), a "Needs attention" action-first hero,
 * reversibility/audit status, subsystem cards, an AI workflow summary, and a recent
 * AI activity timeline with actor provenance + reversible/audited trust chips and
 * cross-links into Change History. READ-ONLY: it links out and never executes.
 *
 * Builder mode foregrounds "what needs you" + activity; Engineer mode (data-wpcc-mode)
 * additionally reveals the invariants strip and operation-ID detail.
 */

defined( 'ABSPATH' ) || exit;

$nonce    = wp_create_nonce( 'wp_rest' );
$api_base = rest_url( 'wp-command-center/v1/admin' );

$links = [
	'approvals'       => admin_url( 'admin.php?page=wpcc-operate&wpcc_tab=approvals' ),
	'approvals_queue' => admin_url( 'admin.php?page=wpcc-operate&wpcc_tab=approvals&tab=queue' ),
	'operations'      => admin_url( 'admin.php?page=wpcc-operate&wpcc_tab=operations' ),
	'tokens'          => admin_url( 'admin.php?page=wpcc-access&wpcc_tab=tokens' ),
	'change_history'  => admin_url( 'admin.php?page=wpcc-audit&wpcc_tab=changes&tab=sessions' ),
	'connect'         => admin_url( 'admin.php?page=wpcc-connect&wpcc_tab=integrations' ),
];

// Per-session deep link into the Change History timeline, hosted under Audit.
$session_base = admin_url( 'admin.php?page=wpcc-audit&wpcc_tab=changes&tab=timeline' );
?>
<div class="wpcc-home">
	<p class="description">
		<?php esc_html_e( 'Mission control for AI on your WordPress site — what needs you, what changed, and what you can undo. Read-only: every card links to the surface that owns the detail.', 'wp-command-center' ); ?>
	</p>

	<div id="wpcc-home-readiness" hidden></div>

	<h2 class="screen-reader-text"><?php esc_html_e( 'Needs attention', 'wp-command-center' ); ?></h2>
	<div id="wpcc-home-attn" role="status" aria-live="polite">
		<div class="wpcc-cds-loading"><span class="spinner is-active" style="float:none;margin:0"></span><?php esc_html_e( 'Loading…', 'wp-command-center' ); ?></div>
	</div>

	<div class="wpcc-engineer-only">
		<h2><?php esc_html_e( 'Platform invariants', 'wp-command-center' ); ?></h2>
		<div id="wpcc-home-invariants" class="wpcc-cds-kpis" role="status" aria-live="polite"></div>
	</div>

	<h2><?php esc_html_e( 'Subsystems', 'wp-command-center' ); ?></h2>
	<div id="wpcc-home-cards" class="wpcc-cds-cards" aria-live="polite"></div>

	<h2><?php esc_html_e( 'AI workflow activity', 'wp-command-center' ); ?></h2>
	<div id="wpcc-home-ai" aria-live="polite"></div>

	<h2><?php esc_html_e( 'Recent activity', 'wp-command-center' ); ?></h2>
	<div class="wpcc-home-activity-filters">
		<label><input type="checkbox" id="wpcc-home-reversible" aria-controls="wpcc-home-activity" /> <?php esc_html_e( 'Reversible only', 'wp-command-center' ); ?></label>
		<span id="wpcc-home-activity-count" role="status" aria-live="polite"></span>
	</div>
	<div id="wpcc-home-activity" aria-live="polite"></div>
</div>

<style>
.wpcc-home { max-width: 1200px; }
.wpcc-home > .description { max-width: 720px; margin: 0 0 var(--wpcc-space-5); }
.wpcc-home-activity-filters { display:flex; align-items:center; gap:14px; flex-wrap:wrap; margin:8px 0; font-size:var(--wpcc-fs-body); }
#wpcc-home-activity-count { font-size:var(--wpcc-fs-small); color:var(--wpcc-text-secondary); }
</style>

<script>
(function () {
	var WPCC      = window.WPCC || {};
	var nonce     = <?php echo wp_json_encode( $nonce ); ?>;
	var apiBase   = <?php echo wp_json_encode( $api_base ); ?>;
	var links     = <?php echo wp_json_encode( $links ); ?>;
	var sessBase  = <?php echo wp_json_encode( $session_base ); ?>;
	var READY_KEY = 'wpcc_readiness_dismissed';

	var i18n = {
		loadFail:   <?php echo wp_json_encode( __( 'Failed to load. Your admin session may have expired — refresh the page and try again.', 'wp-command-center' ) ); ?>,
		// Needs attention.
		attnClearTitle:  <?php echo wp_json_encode( __( 'All clear', 'wp-command-center' ) ); ?>,
		attnClearDetail: <?php echo wp_json_encode( __( 'Nothing is waiting on you right now. AI activity is governed, audited, and reversible.', 'wp-command-center' ) ); ?>,
		attnTitle:       <?php echo wp_json_encode( __( 'Needs your attention', 'wp-command-center' ) ); ?>,
		/* translators: %1$s pending count phrase, %2$s critical/failure phrase */
		attnReview:      <?php echo wp_json_encode( __( 'Review queue', 'wp-command-center' ) ); ?>,
		/* translators: %d: number of pending approvals */
		attnPending:     <?php echo wp_json_encode( __( '%d approval(s) pending', 'wp-command-center' ) ); ?>,
		/* translators: %d: number of critical pending approvals */
		attnCritical:    <?php echo wp_json_encode( __( '%d critical', 'wp-command-center' ) ); ?>,
		/* translators: %d: number of failed queue items */
		attnFailed:      <?php echo wp_json_encode( __( '%d queue failure(s)', 'wp-command-center' ) ); ?>,
		// Invariants.
		invOpMap:   <?php echo wp_json_encode( __( 'Mapped operations', 'wp-command-center' ) ); ?>,
		invCaps:    <?php echo wp_json_encode( __( 'Capabilities', 'wp-command-center' ) ); ?>,
		invCat:     <?php echo wp_json_encode( __( 'Operations', 'wp-command-center' ) ); ?>,
		invMcp:     <?php echo wp_json_encode( __( 'MCP tools', 'wp-command-center' ) ); ?>,
		invDb:      <?php echo wp_json_encode( __( 'DB version', 'wp-command-center' ) ); ?>,
		// Cards.
		view:       <?php echo wp_json_encode( __( 'View details', 'wp-command-center' ) ); ?>,
		cardApprovals: <?php echo wp_json_encode( __( 'Approval Center', 'wp-command-center' ) ); ?>,
		apPending:  <?php echo wp_json_encode( __( 'Pending', 'wp-command-center' ) ); ?>,
		apCritical: <?php echo wp_json_encode( __( 'Pending critical', 'wp-command-center' ) ); ?>,
		apResolved: <?php echo wp_json_encode( __( 'Resolved', 'wp-command-center' ) ); ?>,
		apFailed:   <?php echo wp_json_encode( __( 'Queue failed', 'wp-command-center' ) ); ?>,
		cardOps:    <?php echo wp_json_encode( __( 'Operations', 'wp-command-center' ) ); ?>,
		opTotal:    <?php echo wp_json_encode( __( 'Total', 'wp-command-center' ) ); ?>,
		opAvailable:<?php echo wp_json_encode( __( 'Available', 'wp-command-center' ) ); ?>,
		opApproval: <?php echo wp_json_encode( __( 'Need approval', 'wp-command-center' ) ); ?>,
		riskLabel:  <?php echo wp_json_encode( __( 'Risk', 'wp-command-center' ) ); ?>,
		cardTokens: <?php echo wp_json_encode( __( 'Tokens & Capabilities', 'wp-command-center' ) ); ?>,
		tkTokens:   <?php echo wp_json_encode( __( 'Tokens', 'wp-command-center' ) ); ?>,
		tkCaps:     <?php echo wp_json_encode( __( 'Capabilities', 'wp-command-center' ) ); ?>,
		cardHistory:<?php echo wp_json_encode( __( 'Change History', 'wp-command-center' ) ); ?>,
		chSessions: <?php echo wp_json_encode( __( 'Change sessions', 'wp-command-center' ) ); ?>,
		gated:      <?php echo wp_json_encode( __( 'Not available in this edition.', 'wp-command-center' ) ); ?>,
		// AI workflow summary.
		aiEmptyTitle:  <?php echo wp_json_encode( __( 'No AI proposals yet', 'wp-command-center' ) ); ?>,
		aiEmptyDetail: <?php echo wp_json_encode( __( 'When an AI workflow proposes a change, it appears here as a governed draft before anything is applied.', 'wp-command-center' ) ); ?>,
		/* translators: %d: total AI proposals */
		aiTotal:    <?php echo wp_json_encode( __( '%d AI proposal(s)', 'wp-command-center' ) ); ?>,
		// Activity.
		actEmptyTitle: <?php echo wp_json_encode( __( 'No activity recorded yet', 'wp-command-center' ) ); ?>,
		actEmptyDetail:<?php echo wp_json_encode( __( 'Changes made through the platform — by you, the system, or an AI agent — will be timelined here.', 'wp-command-center' ) ); ?>,
		actNoMatch: <?php echo wp_json_encode( __( 'No sessions match the filter.', 'wp-command-center' ) ); ?>,
		/* translators: %1$d shown, %2$d total */
		actCount:   <?php echo wp_json_encode( __( 'Showing %1$d of %2$d sessions', 'wp-command-center' ) ); ?>,
		/* translators: %d: number of changes */
		actChanges: <?php echo wp_json_encode( __( '%d change(s)', 'wp-command-center' ) ); ?>,
		chipReversible: <?php echo wp_json_encode( __( 'Reversible', 'wp-command-center' ) ); ?>,
		chipAudited:    <?php echo wp_json_encode( __( 'Audited', 'wp-command-center' ) ); ?>,
		actorHuman:  <?php echo wp_json_encode( __( 'Human', 'wp-command-center' ) ); ?>,
		actorSystem: <?php echo wp_json_encode( __( 'System', 'wp-command-center' ) ); ?>,
		actorAgent:  <?php echo wp_json_encode( __( 'AI agent', 'wp-command-center' ) ); ?>,
		// Readiness.
		readyTitle: <?php echo wp_json_encode( __( 'Getting started', 'wp-command-center' ) ); ?>,
		/* translators: %d: completion percent */
		readyPct:   <?php echo wp_json_encode( __( '%d%% complete', 'wp-command-center' ) ); ?>,
		readyDismiss: <?php echo wp_json_encode( __( 'Dismiss', 'wp-command-center' ) ); ?>,
		rsMode:     <?php echo wp_json_encode( __( 'Security mode chosen', 'wp-command-center' ) ); ?>,
		rsToken:    <?php echo wp_json_encode( __( 'Agent token created', 'wp-command-center' ) ); ?>,
		rsChange:   <?php echo wp_json_encode( __( 'First change recorded', 'wp-command-center' ) ); ?>,
		rsReverse:  <?php echo wp_json_encode( __( 'Reversible change captured', 'wp-command-center' ) ); ?>
	};

	function esc( s ) { return WPCC.escHtml ? WPCC.escHtml( s ) : String( s == null ? '' : s ); }
	function fmt1( tpl, a ) { return String( tpl ).replace( '%d', a ).replace( '%s', a ); }
	function fmt2( tpl, a, b ) { return String( tpl ).replace( '%1$d', a ).replace( '%2$d', b ); }
	function set( id, html ) { var el = document.getElementById( id ); if ( el ) { el.innerHTML = html; } }
	function fmtTime( s ) { if ( ! s ) { return ''; } try { return new Date( s * 1000 ).toLocaleString(); } catch ( e ) { return String( s ); } }
	function get( path ) { return WPCC.api( 'GET', apiBase + path, nonce ); }

	/* ── Needs attention ──────────────────────────────────────────────────── */
	function renderAttn( ap ) {
		ap = ap || {};
		var pending  = ap.pending || 0;
		var critical = ap.pending_critical || 0;
		var failed   = ap.queue_failed || 0;
		var needs    = pending > 0 || failed > 0;
		if ( ! needs ) {
			set( 'wpcc-home-attn',
				'<div class="wpcc-cds-attn is-clear">'
				+ '<span class="wpcc-cds-attn__icon" aria-hidden="true">&#10003;</span>'
				+ '<div class="wpcc-cds-attn__body"><p class="wpcc-cds-attn__title">' + esc( i18n.attnClearTitle ) + '</p>'
				+ '<p class="wpcc-cds-attn__detail">' + esc( i18n.attnClearDetail ) + '</p></div></div>' );
			return;
		}
		var bits = [];
		if ( pending > 0 ) { bits.push( esc( fmt1( i18n.attnPending, pending ) ) ); }
		if ( critical > 0 ) { bits.push( WPCC.cds.pill( 'critical', fmt1( i18n.attnCritical, critical ) ) ); }
		if ( failed > 0 ) { bits.push( WPCC.cds.pill( 'danger', fmt1( i18n.attnFailed, failed ) ) ); }
		set( 'wpcc-home-attn',
			'<div class="wpcc-cds-attn is-action">'
			+ '<span class="wpcc-cds-attn__icon" aria-hidden="true">&#9888;</span>'
			+ '<div class="wpcc-cds-attn__body"><p class="wpcc-cds-attn__title">' + esc( i18n.attnTitle ) + '</p>'
			+ '<p class="wpcc-cds-attn__detail">' + bits.join( ' &middot; ' ) + '</p></div>'
			+ '<div class="wpcc-cds-attn__actions"><a class="button button-primary" href="' + esc( links.approvals ) + '">' + esc( i18n.attnReview ) + ' &rarr;</a></div></div>' );
	}

	/* ── Invariants (engineer) ────────────────────────────────────────────── */
	function renderInvariants( inv ) {
		inv = inv || {};
		set( 'wpcc-home-invariants',
			WPCC.cds.kpi( inv.operation_map, i18n.invOpMap )
			+ WPCC.cds.kpi( inv.capabilities, i18n.invCaps )
			+ WPCC.cds.kpi( inv.catalogue, i18n.invCat )
			+ WPCC.cds.kpi( inv.mcp_tools, i18n.invMcp )
			+ WPCC.cds.kpi( inv.db_version, i18n.invDb ) );
	}

	/* ── Subsystem cards ──────────────────────────────────────────────────── */
	function metric( label, value, attn ) {
		return '<li' + ( attn ? ' class="is-attn"' : '' ) + '><span>' + esc( label ) + '</span><b>' + esc( value ) + '</b></li>';
	}
	function card( title, href, metricsHtml, extraHtml ) {
		return '<section class="wpcc-cds-card" aria-label="' + esc( title ) + '">'
			+ '<div class="wpcc-cds-card__head"><h3 class="wpcc-cds-card__title">' + esc( title ) + '</h3>'
			+ '<a class="wpcc-cds-card__link" href="' + esc( href ) + '">' + esc( i18n.view ) + ' &rarr;</a></div>'
			+ ( metricsHtml ? '<ul class="wpcc-cds-metrics">' + metricsHtml + '</ul>' : '' )
			+ ( extraHtml || '' ) + '</section>';
	}
	function gatedCard( title ) {
		return '<section class="wpcc-cds-card" aria-label="' + esc( title ) + '">'
			+ '<div class="wpcc-cds-card__head"><h3 class="wpcc-cds-card__title">' + esc( title ) + '</h3></div>'
			+ '<p class="description">' + esc( i18n.gated ) + '</p></section>';
	}
	function riskRow( byRisk ) {
		byRisk = byRisk || {};
		var tiers = [ 'critical', 'high', 'medium', 'low', 'diagnostic' ];
		var parts = tiers.filter( function ( t ) { return ( byRisk[ t ] || 0 ) > 0; } ).map( function ( t ) {
			return WPCC.cds.riskPill( t, ( byRisk[ t ] || 0 ) + ' ' + t );
		} );
		if ( ! parts.length ) { return ''; }
		return '<div class="wpcc-cds-card__meta wpcc-engineer-only" style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px">' + parts.join( '' ) + '</div>';
	}

	function renderCards( data ) {
		var html = '';

		var ap = data.approvals;
		if ( ap && ap.gated ) { html += gatedCard( i18n.cardApprovals ); }
		else { ap = ap || {}; html += card( i18n.cardApprovals, links.approvals,
			metric( i18n.apPending, ap.pending || 0, ( ap.pending || 0 ) > 0 )
			+ metric( i18n.apCritical, ap.pending_critical || 0, ( ap.pending_critical || 0 ) > 0 )
			+ metric( i18n.apResolved, ap.resolved || 0 )
			+ metric( i18n.apFailed, ap.queue_failed || 0, ( ap.queue_failed || 0 ) > 0 ) ); }

		var op = data.operations;
		if ( op && op.gated ) { html += gatedCard( i18n.cardOps ); }
		else { op = op || {}; html += card( i18n.cardOps, links.operations,
			metric( i18n.opTotal, op.total || 0 )
			+ metric( i18n.opAvailable, op.available || 0 )
			+ metric( i18n.opApproval, op.requires_approval || 0 ),
			riskRow( op.by_risk ) ); }

		var tk = data.tokens;
		if ( tk && tk.gated ) { html += gatedCard( i18n.cardTokens ); }
		else { tk = tk || {}; html += card( i18n.cardTokens, links.tokens,
			metric( i18n.tkTokens, tk.total || 0 )
			+ metric( i18n.tkCaps, tk.capabilities || 0 ) ); }

		var ch = data.change_history;
		if ( ch && ch.gated ) { html += gatedCard( i18n.cardHistory ); }
		else { ch = ch || {}; html += card( i18n.cardHistory, links.change_history,
			metric( i18n.chSessions, ch.sessions || 0 ) ); }

		set( 'wpcc-home-cards', html );
	}

	/* ── AI workflow summary (proposals) ──────────────────────────────────── */
	function statusPill( status ) {
		var map = { applied: 'success', pending_approval: 'warning', failed: 'danger', dismissed: 'neutral', draft: 'neutral' };
		return WPCC.cds.pill( map[ status ] || 'neutral', String( status || '' ).replace( /_/g, ' ' ) );
	}
	function renderAi( res ) {
		if ( ! res || ! res.ok || ! res.data ) {
			set( 'wpcc-home-ai', WPCC.cds.empty( i18n.aiEmptyTitle, i18n.aiEmptyDetail, 'dashicons-lightbulb' ) );
			return;
		}
		var list = res.data.proposals || res.data.items || [];
		var total = ( res.data.total != null ) ? res.data.total : list.length;
		if ( ! list.length ) {
			set( 'wpcc-home-ai', WPCC.cds.empty( i18n.aiEmptyTitle, i18n.aiEmptyDetail, 'dashicons-lightbulb' ) );
			return;
		}
		var rows = list.slice( 0, 6 ).map( function ( p ) {
			var op = p.operation_id || p.operation || '';
			var when = p.updated_at || p.created_at || 0;
			return '<li class="wpcc-cds-timeline__item">'
				+ '<span class="wpcc-cds-timeline__time">' + esc( fmtTime( when ) ) + '</span>'
				+ '<span class="wpcc-cds-timeline__main">' + esc( p.title || p.target_label || op )
				+ ' <span class="wpcc-cds-mono wpcc-engineer-only">' + esc( op ) + '</span></span>'
				+ '<span class="wpcc-cds-timeline__meta">' + statusPill( p.status ) + '</span></li>';
		} ).join( '' );
		set( 'wpcc-home-ai',
			'<p class="description">' + esc( fmt1( i18n.aiTotal, total ) ) + '</p>'
			+ '<ul class="wpcc-cds-timeline">' + rows + '</ul>' );
	}

	/* ── Recent activity timeline ─────────────────────────────────────────── */
	var allActivity = [];

	function actorType( summary ) {
		var s = String( summary || '' ).toLowerCase();
		if ( s.indexOf( 'agent' ) !== -1 || s.indexOf( 'claude' ) !== -1 || s.indexOf( 'ai' ) === 0 ) { return 'agent'; }
		if ( s.indexOf( 'system' ) !== -1 || s.indexOf( 'cron' ) !== -1 || s.indexOf( 'queue' ) !== -1 || s.indexOf( 'workflow' ) !== -1 ) { return 'system'; }
		return 'human';
	}
	function sessionUrl( id ) { return sessBase + '&session_id=' + encodeURIComponent( id ); }

	function renderActivity( rows, totalAvailable ) {
		if ( ! rows || ! rows.length ) {
			var detail = ( totalAvailable > 0 ) ? i18n.actNoMatch : i18n.actEmptyDetail;
			set( 'wpcc-home-activity', WPCC.cds.empty( i18n.actEmptyTitle, detail, 'dashicons-backup' ) );
			return;
		}
		var html = '<ul class="wpcc-cds-timeline">';
		rows.forEach( function ( r ) {
			var rev = ( r.reversible_count || 0 ) > 0;
			var runtimes = ( r.runtimes || [] ).join( ', ' );
			var meta = WPCC.cds.actorChip( actorType( r.actor_summary ), r.actor_summary || '' )
				+ WPCC.cds.chip( 'audited', i18n.chipAudited );
			if ( rev ) { meta += WPCC.cds.chip( 'reversible', i18n.chipReversible ); }
			html += '<li class="wpcc-cds-timeline__item">'
				+ '<span class="wpcc-cds-timeline__time">' + esc( fmtTime( r.last_at ) ) + '</span>'
				+ '<span class="wpcc-cds-timeline__main">' + esc( fmt1( i18n.actChanges, r.change_count || 0 ) )
				+ ( runtimes ? ' <span class="wpcc-cds-mono wpcc-engineer-only">' + esc( runtimes ) + '</span>' : '' )
				+ ' &middot; <a href="' + esc( sessionUrl( r.session_id ) ) + '"><code class="wpcc-cds-mono">' + esc( String( r.session_id ).substring( 0, 10 ) ) + '&#8230;</code></a></span>'
				+ '<span class="wpcc-cds-timeline__meta">' + meta + '</span></li>';
		} );
		html += '</ul>';
		set( 'wpcc-home-activity', html );
	}

	function applyActivityFilter() {
		var revEl = document.getElementById( 'wpcc-home-reversible' );
		var revOnly = revEl && revEl.checked;
		var rows = revOnly ? allActivity.filter( function ( r ) { return ( r.reversible_count || 0 ) > 0; } ) : allActivity;
		renderActivity( rows, allActivity.length );
		set( 'wpcc-home-activity-count', esc( fmt2( i18n.actCount, rows.length, allActivity.length ) ) );
	}

	/* ── Readiness / onboarding ───────────────────────────────────────────── */
	function renderReadiness( data ) {
		var dismissed;
		try { dismissed = window.localStorage.getItem( READY_KEY ) === '1'; } catch ( e ) { dismissed = false; }
		var tk = ( data.tokens && ! data.tokens.gated ) ? data.tokens : {};
		var ch = ( data.change_history && ! data.change_history.gated ) ? data.change_history : {};
		var anyRev = ( data.recent_activity || [] ).some( function ( r ) { return ( r.reversible_count || 0 ) > 0; } );
		var steps = [
			{ label: i18n.rsMode, done: true },
			{ label: i18n.rsToken, done: ( tk.total || 0 ) > 0 },
			{ label: i18n.rsChange, done: ( ch.sessions || 0 ) > 0 },
			{ label: i18n.rsReverse, done: anyRev }
		];
		var doneCount = steps.filter( function ( s ) { return s.done; } ).length;
		var pct = Math.round( ( doneCount / steps.length ) * 100 );
		var el = document.getElementById( 'wpcc-home-readiness' );
		if ( ! el || dismissed || pct === 100 ) { return; }

		var chips = steps.map( function ( s ) {
			return '<li class="wpcc-cds-readiness__step' + ( s.done ? ' is-done' : '' ) + '">'
				+ '<span class="dashicons ' + ( s.done ? 'dashicons-yes' : 'dashicons-marker' ) + '" aria-hidden="true"></span>'
				+ esc( s.label ) + '</li>';
		} ).join( '' );
		el.innerHTML = '<div class="wpcc-cds-readiness"><div class="wpcc-cds-readiness__head">'
			+ '<strong>' + esc( i18n.readyTitle ) + '</strong>'
			+ '<span><span class="wpcc-cds-pill wpcc-cds-pill--neutral">' + esc( fmt1( i18n.readyPct, pct ) ) + '</span> '
			+ '<button type="button" class="button-link" id="wpcc-home-ready-dismiss">' + esc( i18n.readyDismiss ) + '</button></span></div>'
			+ '<div class="wpcc-cds-readiness__bar"><div class="wpcc-cds-readiness__fill" style="width:' + pct + '%"></div></div>'
			+ '<ul class="wpcc-cds-readiness__steps">' + chips + '</ul></div>';
		el.hidden = false;
		var btn = document.getElementById( 'wpcc-home-ready-dismiss' );
		if ( btn ) {
			btn.addEventListener( 'click', function () {
				try { window.localStorage.setItem( READY_KEY, '1' ); } catch ( e ) {}
				el.hidden = true;
			} );
		}
	}

	function showFail() {
		set( 'wpcc-home-attn', '<div class="wpcc-cds-empty">' + esc( i18n.loadFail ) + '</div>' );
		set( 'wpcc-home-cards', '' );
		set( 'wpcc-home-activity', '' );
	}

	function init() {
		// Re-resolve the shared runtime at run time: the enqueued wpcc-admin-runtime /
		// wpcc-cds load in the <head>, but this guards against any load-order surprise
		// (a footer fallback, a deferred script) so the home never silently hangs.
		WPCC = window.WPCC || WPCC;
		if ( ! WPCC.api || ! WPCC.cds ) {
			set( 'wpcc-home-attn', '<div class="wpcc-cds-empty">' + esc( i18n.loadFail ) + '</div>' );
			return;
		}

		var revEl = document.getElementById( 'wpcc-home-reversible' );
		if ( revEl ) { revEl.addEventListener( 'change', applyActivityFilter ); }

		get( '/dashboard' ).then( function ( res ) {
			if ( ! res.ok || ! res.data || res.data.action !== 'dashboard_overview' ) { showFail(); return; }
			var d = res.data;
			renderReadiness( d );
			renderAttn( d.approvals && ! d.approvals.gated ? d.approvals : {} );
			renderInvariants( d.invariants );
			renderCards( d );
			allActivity = Array.isArray( d.recent_activity ) ? d.recent_activity : [];
			applyActivityFilter();
		} ).catch( showFail );

		// AI workflow summary — independent; degrades to an empty state if the
		// proposal surface is gated/unavailable (dormant-safe).
		get( '/proposals?limit=8' ).then( renderAi ).catch( function () {
			set( 'wpcc-home-ai', WPCC.cds.empty( i18n.aiEmptyTitle, i18n.aiEmptyDetail, 'dashicons-lightbulb' ) );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
})();
</script>
