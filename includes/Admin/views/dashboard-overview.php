<?php
/**
 * STEP 109 — Dashboard Overview admin view (read-only).
 *
 * A single at-a-glance landing that aggregates the existing admin surfaces,
 * rendered via the cookie-authed admin REST read (DashboardAdminQuery):
 *
 *   - 109.1 — the current security mode + the platform invariants strip (operation
 *     map, capabilities, catalogue, MCP tools, DB version) and summary cards for the
 *     Approval Center, Operations Explorer, Tokens & Capabilities, and Change
 *     History, each linking out to its own surface.
 *   - 109.2 — a recent change-activity feed (deep-linking each session into the
 *     Change History timeline), the operations risk distribution, and tab-targeted
 *     deep links.
 *   - 109.3 — a client-side "reversible only" filter over the recent-activity feed
 *     with a live count region; full accessibility (heading hierarchy h1 → h2 → h3,
 *     scope="col"/scope="row" tables, labeled controls, role="status" live regions),
 *     i18n completeness, and empty / filter-no-match / load-failure states.
 *
 * This surface is READ-ONLY: it aggregates and links out only. It NEVER executes
 * an operation and carries no write controls. The legacy operational Dashboard
 * (views/dashboard.php) is untouched and continues to own operational actions.
 * All API output is escaped client-side via escHtml.
 */

defined( 'ABSPATH' ) || exit;

$nonce    = wp_create_nonce( 'wp_rest' );
$api_base = rest_url( 'wp-command-center/v1/admin' );

// Drill-out targets — each card links into the relevant tab/view of the surface
// that owns its data (STEP 109.2). Tab slugs mirror the destination surfaces:
// Approval Center (pending|history|queue), Tokens (tokens|capabilities|operations),
// Change History (timeline|sessions|reversible).
$links = [
	'approvals'       => admin_url( 'admin.php?page=wpcc-approval-center&tab=pending' ),
	'approvals_queue' => admin_url( 'admin.php?page=wpcc-approval-center&tab=queue' ),
	'operations'      => admin_url( 'admin.php?page=wpcc-operations' ),
	'tokens'          => admin_url( 'admin.php?page=wpcc-tokens&tab=tokens' ),
	'change_history'  => admin_url( 'admin.php?page=wpcc-change-history&tab=sessions' ),
];

// Base for building per-session deep links into the Change History Timeline,
// mirroring change-history.php's sessionUrl (?page=…&tab=timeline&session_id=…).
$admin_php    = admin_url( 'admin.php' );
$history_page = 'wpcc-change-history';
?>
<div class="wrap wpcc-wrap wpcc-dashboard-overview">
	<h1><?php esc_html_e( 'Dashboard Overview', 'wp-command-center' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'An at-a-glance summary of the Command Center: the current security posture, the platform invariants, and a roll-up of approvals, operations, tokens, and recorded changes. Read-only — each card links to the surface that owns the detail.', 'wp-command-center' ); ?>
	</p>

	<div id="wpcc-dash-posture" class="wpcc-dash-posture" role="status" aria-live="polite"></div>

	<h2><?php esc_html_e( 'Platform invariants', 'wp-command-center' ); ?></h2>
	<div id="wpcc-dash-invariants" class="wpcc-dash-strip" role="status" aria-live="polite"></div>

	<h2><?php esc_html_e( 'Subsystems', 'wp-command-center' ); ?></h2>
	<div id="wpcc-dash-cards" class="wpcc-dash-cards">
		<p><span class="spinner is-active wpcc-spin"></span><?php esc_html_e( 'Loading overview…', 'wp-command-center' ); ?></p>
	</div>

	<h2><?php esc_html_e( 'Recent activity', 'wp-command-center' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'The most recent change sessions recorded across the platform. Each row links into the session on the Change History timeline.', 'wp-command-center' ); ?>
	</p>
	<div class="wpcc-dash-activity-filters">
		<label><input type="checkbox" id="wpcc-dash-reversible" aria-controls="wpcc-dash-activity" /> <?php esc_html_e( 'Reversible only', 'wp-command-center' ); ?></label>
		<span id="wpcc-dash-activity-count" class="wpcc-dash-activity-count" role="status" aria-live="polite"></span>
	</div>
	<div id="wpcc-dash-activity" class="wpcc-dash-activity" role="status" aria-live="polite">
		<p><span class="spinner is-active wpcc-spin"></span><?php esc_html_e( 'Loading recent activity…', 'wp-command-center' ); ?></p>
	</div>
</div>

<style>
.wpcc-dashboard-overview .wpcc-spin { float:none;margin:0 6px 0 0;vertical-align:middle; }
.wpcc-dash-posture { margin:12px 0; }
.wpcc-dash-mode { display:inline-block;font-size:13px;background:#f0f6fc;border:1px solid #72aee6;border-radius:4px;padding:6px 12px;color:#0a4b78; }
.wpcc-dash-strip { display:flex;flex-wrap:wrap;gap:8px;margin:8px 0 4px; }
.wpcc-dash-stat { background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:8px 14px;min-width:90px; }
.wpcc-dash-stat b { display:block;font-size:20px;line-height:1.2; }
.wpcc-dash-stat span { font-size:12px;color:#50575e; }
.wpcc-dash-cards { display:flex;flex-wrap:wrap;gap:16px;margin:8px 0;align-items:flex-start; }
.wpcc-dash-card { background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:14px 16px;min-width:260px;flex:1 1 260px;max-width:340px; }
.wpcc-dash-card h3 { margin:0 0 8px;font-size:14px; }
.wpcc-dash-metrics { list-style:none;margin:0 0 10px;padding:0; }
.wpcc-dash-metrics li { display:flex;justify-content:space-between;padding:3px 0;border-bottom:1px solid #f0f0f1;font-size:13px; }
.wpcc-dash-metrics li b { font-weight:600; }
.wpcc-dash-metrics li.wpcc-attn b { color:#b32d2e; }
.wpcc-dash-card .wpcc-dash-link { font-size:13px;margin-right:14px; }
.wpcc-dash-link--warn { color:#b32d2e; }
.wpcc-dash-riskdist { display:flex;flex-wrap:wrap;gap:10px;align-items:baseline;margin:0 0 10px;font-size:12px;color:#50575e; }
.wpcc-dash-riskdist-label { width:100%;font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#646970; }
.wpcc-dash-risk b { font-weight:600;color:#1d2327; }
.wpcc-dash-activity-filters { display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin:8px 0; }
.wpcc-dash-activity-count { font-size:12px;color:#646970; }
.wpcc-dash-activity { margin:8px 0 4px; }
.wpcc-dash-activity-table { max-width:1100px; }
.wpcc-dash-activity-table td,.wpcc-dash-activity-table th { vertical-align:middle; }
.wpcc-dash-activity-table code { font-size:11px; }
.wpcc-empty { background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:18px;max-width:1100px;color:#50575e; }
</style>

<script>
(function() {
	var nonce       = <?php echo wp_json_encode( $nonce ); ?>;
	var apiBase     = <?php echo wp_json_encode( $api_base ); ?>;
	var links       = <?php echo wp_json_encode( $links ); ?>;
	var adminPhp    = <?php echo wp_json_encode( $admin_php ); ?>;
	var historyPage = <?php echo wp_json_encode( $history_page ); ?>;
	var i18n = {
		loadFail:     <?php echo wp_json_encode( __( 'Failed to load. Your admin session may have expired — refresh the page and try again.', 'wp-command-center' ) ); ?>,
		empty:        <?php echo wp_json_encode( __( 'No overview data is available right now.', 'wp-command-center' ) ); ?>,
		modeLabel:    <?php echo wp_json_encode( __( 'Security mode', 'wp-command-center' ) ); ?>,
		view:         <?php echo wp_json_encode( __( 'View details', 'wp-command-center' ) ); ?>,
		// Invariants.
		invOpMap:     <?php echo wp_json_encode( __( 'Mapped operations', 'wp-command-center' ) ); ?>,
		invCaps:      <?php echo wp_json_encode( __( 'Capabilities', 'wp-command-center' ) ); ?>,
		invCatalogue: <?php echo wp_json_encode( __( 'Operations', 'wp-command-center' ) ); ?>,
		invMcp:       <?php echo wp_json_encode( __( 'MCP tools', 'wp-command-center' ) ); ?>,
		invDb:        <?php echo wp_json_encode( __( 'DB version', 'wp-command-center' ) ); ?>,
		// Approval card.
		cardApprovals: <?php echo wp_json_encode( __( 'Approval Center', 'wp-command-center' ) ); ?>,
		apPending:     <?php echo wp_json_encode( __( 'Pending', 'wp-command-center' ) ); ?>,
		apCritical:    <?php echo wp_json_encode( __( 'Pending critical', 'wp-command-center' ) ); ?>,
		apResolved:    <?php echo wp_json_encode( __( 'Resolved', 'wp-command-center' ) ); ?>,
		apQueueFailed: <?php echo wp_json_encode( __( 'Queue failed', 'wp-command-center' ) ); ?>,
		apViewQueue:   <?php echo wp_json_encode( __( 'View failed queue', 'wp-command-center' ) ); ?>,
		// Operations card.
		cardOps:       <?php echo wp_json_encode( __( 'Operations Explorer', 'wp-command-center' ) ); ?>,
		opTotal:       <?php echo wp_json_encode( __( 'Total operations', 'wp-command-center' ) ); ?>,
		opAvailable:   <?php echo wp_json_encode( __( 'Available', 'wp-command-center' ) ); ?>,
		opApproval:    <?php echo wp_json_encode( __( 'Need approval', 'wp-command-center' ) ); ?>,
		opUnrestricted:<?php echo wp_json_encode( __( 'Unrestricted', 'wp-command-center' ) ); ?>,
		// Operations risk distribution.
		riskTitle:     <?php echo wp_json_encode( __( 'Risk distribution', 'wp-command-center' ) ); ?>,
		rCritical:     <?php echo wp_json_encode( __( 'critical', 'wp-command-center' ) ); ?>,
		rHigh:         <?php echo wp_json_encode( __( 'high', 'wp-command-center' ) ); ?>,
		rMedium:       <?php echo wp_json_encode( __( 'medium', 'wp-command-center' ) ); ?>,
		rLow:          <?php echo wp_json_encode( __( 'low', 'wp-command-center' ) ); ?>,
		rDiagnostic:   <?php echo wp_json_encode( __( 'diagnostic', 'wp-command-center' ) ); ?>,
		// Tokens card.
		cardTokens:    <?php echo wp_json_encode( __( 'Tokens & Capabilities', 'wp-command-center' ) ); ?>,
		tkTokens:      <?php echo wp_json_encode( __( 'Tokens', 'wp-command-center' ) ); ?>,
		tkCaps:        <?php echo wp_json_encode( __( 'Capabilities', 'wp-command-center' ) ); ?>,
		// Change history card.
		cardHistory:   <?php echo wp_json_encode( __( 'Change History', 'wp-command-center' ) ); ?>,
		chSessions:    <?php echo wp_json_encode( __( 'Change sessions', 'wp-command-center' ) ); ?>,
		// Recent activity feed.
		actEmpty:      <?php echo wp_json_encode( __( 'No change activity recorded yet.', 'wp-command-center' ) ); ?>,
		actWhen:       <?php echo wp_json_encode( __( 'When', 'wp-command-center' ) ); ?>,
		actActor:      <?php echo wp_json_encode( __( 'Actor', 'wp-command-center' ) ); ?>,
		actChangesCol: <?php echo wp_json_encode( __( 'Changes', 'wp-command-center' ) ); ?>,
		actRuntimes:   <?php echo wp_json_encode( __( 'Runtimes', 'wp-command-center' ) ); ?>,
		actSession:    <?php echo wp_json_encode( __( 'Session', 'wp-command-center' ) ); ?>,
		/* translators: %d: number of reversible changes */
		actReversible: <?php echo wp_json_encode( __( '%d reversible', 'wp-command-center' ) ); ?>,
		actNoMatch:    <?php echo wp_json_encode( __( 'No sessions match the filter.', 'wp-command-center' ) ); ?>,
		/* translators: %1$d shown sessions, %2$d total sessions */
		actCountFmt:   <?php echo wp_json_encode( __( 'Showing %1$d of %2$d sessions', 'wp-command-center' ) ); ?>
	};

	function escHtml( s ) {
		var d = document.createElement('div');
		d.appendChild( document.createTextNode( String( s === null || s === undefined ? '' : s ) ) );
		return d.innerHTML;
	}
	function apiFetch( path ) {
		return fetch( apiBase + path, { headers: { 'X-WP-Nonce': nonce } } ).then( function(r) {
			return r.json().then(
				function(j) { return { ok: r.ok, status: r.status, body: j }; },
				function()  { return { ok: r.ok, status: r.status, body: {} }; }
			);
		} );
	}
	function setHtml( id, html ) {
		var el = document.getElementById( id );
		if ( el ) { el.innerHTML = html; }
	}
	function stat( n, label ) {
		return '<div class="wpcc-dash-stat"><b>' + escHtml( n ) + '</b><span>' + escHtml( label ) + '</span></div>';
	}
	function metric( label, value, attn ) {
		return '<li' + ( attn ? ' class="wpcc-attn"' : '' ) + '><span>' + escHtml( label ) + '</span><b>' + escHtml( value ) + '</b></li>';
	}
	function card( title, href, metricsHtml, extraHtml ) {
		return '<section class="wpcc-dash-card" aria-label="' + escHtml( title ) + '">'
			+ '<h3>' + escHtml( title ) + '</h3>'
			+ '<ul class="wpcc-dash-metrics">' + metricsHtml + '</ul>'
			+ ( extraHtml || '' )
			+ '<a class="wpcc-dash-link" href="' + escHtml( href ) + '">' + escHtml( i18n.view ) + ' &rarr;</a>'
			+ '</section>';
	}
	function sprintf1( tpl, a ) {
		return String( tpl ).replace( '%d', a ).replace( '%s', a );
	}
	function sprintf2( tpl, a, b ) {
		return String( tpl ).replace( '%1$d', a ).replace( '%2$d', b );
	}
	function fmtTime( unixSecs ) {
		if ( ! unixSecs ) { return ''; }
		try { return new Date( unixSecs * 1000 ).toLocaleString(); } catch ( e ) { return String( unixSecs ); }
	}
	function sessionUrl( sessionId ) {
		return adminPhp + '?page=' + encodeURIComponent( historyPage )
			+ '&tab=timeline&session_id=' + encodeURIComponent( sessionId );
	}
	function riskDist( byRisk ) {
		byRisk = byRisk || {};
		var tiers = [ [ 'critical', i18n.rCritical ], [ 'high', i18n.rHigh ], [ 'medium', i18n.rMedium ], [ 'low', i18n.rLow ], [ 'diagnostic', i18n.rDiagnostic ] ];
		var parts = tiers.map( function( t ) {
			return '<span class="wpcc-dash-risk"><b>' + escHtml( byRisk[ t[0] ] || 0 ) + '</b> ' + escHtml( t[1] ) + '</span>';
		} );
		return '<div class="wpcc-dash-riskdist"><span class="wpcc-dash-riskdist-label">' + escHtml( i18n.riskTitle ) + '</span>' + parts.join( '' ) + '</div>';
	}

	function renderPosture( s ) {
		var label = ( s && s.label ) ? s.label : '';
		setHtml( 'wpcc-dash-posture',
			'<span class="wpcc-dash-mode">' + escHtml( i18n.modeLabel ) + ': <strong>' + escHtml( label ) + '</strong></span>' );
	}
	function renderInvariants( inv ) {
		inv = inv || {};
		setHtml( 'wpcc-dash-invariants', ''
			+ stat( inv.operation_map, i18n.invOpMap )
			+ stat( inv.capabilities, i18n.invCaps )
			+ stat( inv.catalogue, i18n.invCatalogue )
			+ stat( inv.mcp_tools, i18n.invMcp )
			+ stat( inv.db_version, i18n.invDb )
		);
	}
	function renderCards( data ) {
		var ap = data.approvals || {};
		var op = data.operations || {};
		var tk = data.tokens || {};
		var ch = data.change_history || {};

		// Surface a failed-queue shortcut only when there is something to act on.
		var apExtra = ( ap.queue_failed || 0 ) > 0
			? '<a class="wpcc-dash-link wpcc-dash-link--warn" href="' + escHtml( links.approvals_queue ) + '">' + escHtml( i18n.apViewQueue ) + ' &rarr;</a>'
			: '';
		var approvals = card( i18n.cardApprovals, links.approvals,
			  metric( i18n.apPending, ap.pending || 0, ( ap.pending || 0 ) > 0 )
			+ metric( i18n.apCritical, ap.pending_critical || 0, ( ap.pending_critical || 0 ) > 0 )
			+ metric( i18n.apResolved, ap.resolved || 0 )
			+ metric( i18n.apQueueFailed, ap.queue_failed || 0, ( ap.queue_failed || 0 ) > 0 ),
			apExtra );

		var operations = card( i18n.cardOps, links.operations,
			  metric( i18n.opTotal, op.total || 0 )
			+ metric( i18n.opAvailable, op.available || 0 )
			+ metric( i18n.opApproval, op.requires_approval || 0 )
			+ metric( i18n.opUnrestricted, op.unrestricted || 0 ),
			riskDist( op.by_risk ) );

		var tokens = card( i18n.cardTokens, links.tokens,
			  metric( i18n.tkTokens, tk.total || 0 )
			+ metric( i18n.tkCaps, tk.capabilities || 0 ) );

		var history = card( i18n.cardHistory, links.change_history,
			  metric( i18n.chSessions, ch.sessions || 0 ) );

		setHtml( 'wpcc-dash-cards', approvals + operations + tokens + history );
	}
	var allActivity = [];

	function applyActivityFilter() {
		var revEl   = document.getElementById( 'wpcc-dash-reversible' );
		var revOnly = revEl && revEl.checked;
		var rows    = revOnly
			? allActivity.filter( function( r ) { return ( r.reversible_count || 0 ) > 0; } )
			: allActivity;
		renderActivity( rows, allActivity.length );
		setHtml( 'wpcc-dash-activity-count', escHtml( sprintf2( i18n.actCountFmt, rows.length, allActivity.length ) ) );
	}

	function renderActivity( rows, totalAvailable ) {
		if ( ! rows || ! rows.length ) {
			// Distinguish "nothing recorded" from "nothing matches the filter".
			var msg = ( totalAvailable > 0 ) ? i18n.actNoMatch : i18n.actEmpty;
			setHtml( 'wpcc-dash-activity', '<p class="description">' + escHtml( msg ) + '</p>' );
			return;
		}
		var h = '<table class="widefat striped wpcc-dash-activity-table"><thead><tr>'
			+ '<th scope="col">' + escHtml( i18n.actWhen ) + '</th>'
			+ '<th scope="col">' + escHtml( i18n.actActor ) + '</th>'
			+ '<th scope="col">' + escHtml( i18n.actChangesCol ) + '</th>'
			+ '<th scope="col">' + escHtml( i18n.actRuntimes ) + '</th>'
			+ '<th scope="col">' + escHtml( i18n.actSession ) + '</th>'
			+ '</tr></thead><tbody>';
		rows.forEach( function( r ) {
			var runtimes = ( r.runtimes || [] ).join( ', ' );
			var changes  = escHtml( r.change_count || 0 );
			if ( ( r.reversible_count || 0 ) > 0 ) {
				changes += ' <span class="wpcc-opt">(' + escHtml( sprintf1( i18n.actReversible, r.reversible_count ) ) + ')</span>';
			}
			h += '<tr>'
				+ '<th scope="row">' + escHtml( fmtTime( r.last_at ) ) + '</th>'
				+ '<td>' + escHtml( r.actor_summary ) + '</td>'
				+ '<td>' + changes + '</td>'
				+ '<td>' + escHtml( runtimes ) + '</td>'
				+ '<td><a href="' + escHtml( sessionUrl( r.session_id ) ) + '"><code>' + escHtml( String( r.session_id ).substring( 0, 12 ) ) + '&#8230;</code></a></td>'
				+ '</tr>';
		} );
		h += '</tbody></table>';
		setHtml( 'wpcc-dash-activity', h );
	}

	function showLoadFail() {
		setHtml( 'wpcc-dash-cards', '<div class="wpcc-empty">' + escHtml( i18n.loadFail ) + '</div>' );
		setHtml( 'wpcc-dash-activity', '<div class="wpcc-empty">' + escHtml( i18n.loadFail ) + '</div>' );
		setHtml( 'wpcc-dash-activity-count', '' );
	}

	function init() {
		var revEl = document.getElementById( 'wpcc-dash-reversible' );
		if ( revEl ) { revEl.addEventListener( 'change', applyActivityFilter ); }

		apiFetch( '/dashboard' ).then( function( res ) {
			if ( ! res.ok || ! res.body || res.body.action !== 'dashboard_overview' ) {
				showLoadFail();
				return;
			}
			renderPosture( res.body.security );
			renderInvariants( res.body.invariants );
			renderCards( res.body );
			allActivity = Array.isArray( res.body.recent_activity ) ? res.body.recent_activity : [];
			applyActivityFilter();
		} ).catch( showLoadFail );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
})();
</script>
