<?php
/**
 * STEP 108.1 — Operations Explorer admin view (read-only).
 *
 * A discovery surface over the operation catalogue (OperationRegistry) joined
 * with the authorization map (CapabilityRegistry) and the current security mode
 * (SecurityModeManager), rendered via the cookie-authed admin REST reads
 * (OperationExplorerAdminQuery):
 *
 *   - A header strip of catalogue counts (total / available / requiring approval
 *     / unrestricted) and the current security mode.
 *   - A filterable table of every operation with its risk tier, required
 *     capability (or "Unrestricted"), approval requirement, read-only-scope
 *     eligibility, and LIVE availability.
 *
 * This surface is READ-ONLY and discovery-only: it NEVER executes an operation
 * and carries no write controls. Per-operation detail arrives in STEP 108.2. All
 * API output is escaped client-side via escHtml.
 */

defined( 'ABSPATH' ) || exit;

$nonce    = wp_create_nonce( 'wp_rest' );
$api_base = rest_url( 'wp-command-center/v1/admin' );

$page    = 'wpcc-operations';
$view_id = isset( $_GET['view'] ) ? sanitize_text_field( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$list_url = esc_url( add_query_arg( [ 'page' => $page ], admin_url( 'admin.php' ) ) );
?>
<div class="wrap wpcc-wrap wpcc-operations">
	<h1><?php esc_html_e( 'Operations Explorer', 'wp-command-center' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'Every operation the platform exposes, with its risk tier, the capability it requires, whether it needs approval in the current security mode, and whether it is available on this site right now. Read-only — this page does not run operations.', 'wp-command-center' ); ?>
	</p>

	<?php if ( '' !== $view_id ) : ?>
		<p>
			<a href="<?php echo $list_url; ?>">&larr; <?php esc_html_e( 'Back to all operations', 'wp-command-center' ); ?></a>
		</p>
		<div id="wpcc-op-detail" data-op-id="<?php echo esc_attr( $view_id ); ?>">
			<div class="wpcc-cds-loading"><span class="spinner is-active" style="float:none;margin:0"></span><span><?php esc_html_e( 'Loading operation…', 'wp-command-center' ); ?></span></div>
		</div>
	<?php else : ?>
		<div id="wpcc-ops-summary" class="wpcc-ops-summary" role="status" aria-live="polite"></div>

		<div class="wpcc-ops-filters">
			<label class="screen-reader-text" for="wpcc-ops-search"><?php esc_html_e( 'Filter operations', 'wp-command-center' ); ?></label>
			<input type="search" id="wpcc-ops-search" class="regular-text wpcc-cds-field" placeholder="<?php esc_attr_e( 'Filter by name, id, or capability…', 'wp-command-center' ); ?>" aria-controls="wpcc-ops-panel" />

			<label class="screen-reader-text" for="wpcc-ops-risk"><?php esc_html_e( 'Filter by risk', 'wp-command-center' ); ?></label>
			<select id="wpcc-ops-risk" class="wpcc-cds-field" aria-controls="wpcc-ops-panel">
				<option value=""><?php esc_html_e( 'All risk levels', 'wp-command-center' ); ?></option>
				<option value="diagnostic"><?php esc_html_e( 'Diagnostic', 'wp-command-center' ); ?></option>
				<option value="low"><?php esc_html_e( 'Low', 'wp-command-center' ); ?></option>
				<option value="medium"><?php esc_html_e( 'Medium', 'wp-command-center' ); ?></option>
				<option value="high"><?php esc_html_e( 'High', 'wp-command-center' ); ?></option>
				<option value="critical"><?php esc_html_e( 'Critical', 'wp-command-center' ); ?></option>
			</select>

			<label><input type="checkbox" id="wpcc-ops-available" /> <?php esc_html_e( 'Available only', 'wp-command-center' ); ?></label>
		</div>

		<div id="wpcc-ops-count" class="wpcc-ops-count" role="status" aria-live="polite"></div>

		<div id="wpcc-ops-panel">
			<div class="wpcc-cds-loading"><span class="spinner is-active" style="float:none;margin:0"></span><span><?php esc_html_e( 'Loading operations…', 'wp-command-center' ); ?></span></div>
		</div>

		<div id="wpcc-ops-pager" class="wpcc-ops-pager"></div>
	<?php endif; ?>
</div>

<style>
/* Operations Explorer — layout only. Badges, chips, empty/error/loading states,
 * KPI tiles and table chrome are now CDS components (wpcc-cds.css); what remains
 * here is view-specific layout, re-pointed onto CDS tokens (no hardcoded color). */
.wpcc-ops-filters { display:flex;align-items:center;gap:var(--wpcc-space-6);flex-wrap:wrap;margin:var(--wpcc-space-4) 0; }
.wpcc-ops-count { font-size:var(--wpcc-fs-small);color:var(--wpcc-text-secondary);margin:var(--wpcc-space-2) 0 var(--wpcc-space-3); }
.wpcc-ops-pager { display:flex;align-items:center;gap:var(--wpcc-space-4);margin:var(--wpcc-space-5) 0;max-width:1100px; }
.wpcc-ops-pager .wpcc-pageinfo { font-size:var(--wpcc-fs-small);color:var(--wpcc-text-secondary); }
.wpcc-ops-table { max-width:1100px;margin-top:var(--wpcc-space-2); }
.wpcc-ops-table .wpcc-op-id { font-family:var(--wpcc-font-mono);font-size:var(--wpcc-fs-caption);color:var(--wpcc-text-secondary); }
.wpcc-ops-table .wpcc-op-desc { color:var(--wpcc-text-muted);font-size:var(--wpcc-fs-small); }
.wpcc-op-detail-table { max-width:820px;margin:var(--wpcc-space-3) 0 var(--wpcc-space-7); }
.wpcc-op-detail-table th { text-align:left;width:200px;color:var(--wpcc-text-muted); }
.wpcc-op-detail-table td,.wpcc-op-detail-table th { padding:var(--wpcc-space-3) var(--wpcc-space-5);border-bottom:1px solid var(--wpcc-border-subtle);vertical-align:top; }
.wpcc-op-section { margin:var(--wpcc-space-7) 0; }
.wpcc-op-section h3 { font-size:var(--wpcc-fs-h2);margin:0 0 var(--wpcc-space-3); }
.wpcc-op-params-table,.wpcc-op-actions-table { max-width:1000px; }
.wpcc-op-params-table td,.wpcc-op-actions-table td,.wpcc-op-params-table th,.wpcc-op-actions-table th { vertical-align:top; }
.wpcc-op-desc-full { max-width:1000px;color:var(--wpcc-text-primary); }
.wpcc-op-note { background:var(--wpcc-surface-accent-soft);border:1px solid var(--wpcc-border-accent);border-radius:var(--wpcc-radius-sm);padding:var(--wpcc-space-4) var(--wpcc-space-6);margin:var(--wpcc-space-3) 0;max-width:1000px;font-size:var(--wpcc-fs-body); }
.wpcc-op-note--warn { background:var(--wpcc-state-warning-bg);border-color:var(--wpcc-state-warning-border); }
.wpcc-req { color:var(--wpcc-state-danger-fg);font-weight:600; }
.wpcc-opt { color:var(--wpcc-text-secondary); }
.wpcc-op-name { font-family:var(--wpcc-font-mono);font-size:var(--wpcc-fs-small); }
</style>

<script>
(function() {
	var nonce   = <?php echo wp_json_encode( $nonce ); ?>;
	var apiBase = <?php echo wp_json_encode( $api_base ); ?>;
	var state   = {
		viewId:  <?php echo wp_json_encode( $view_id ); ?>,
		pageUrl: <?php echo wp_json_encode( admin_url( 'admin.php' ) ); ?>,
		page:    <?php echo wp_json_encode( $page ); ?>
	};
	var i18n = {
		loadFail:   <?php echo wp_json_encode( __( 'Failed to load. Your admin session may have expired — refresh the page and try again.', 'wp-command-center' ) ); ?>,
		empty:      <?php echo wp_json_encode( __( 'No operations match your filters.', 'wp-command-center' ) ); ?>,
		notFound:   <?php echo wp_json_encode( __( 'Operation not found. It may have been removed.', 'wp-command-center' ) ); ?>,
		colOp:      <?php echo wp_json_encode( __( 'Operation', 'wp-command-center' ) ); ?>,
		colRisk:    <?php echo wp_json_encode( __( 'Risk', 'wp-command-center' ) ); ?>,
		colCap:     <?php echo wp_json_encode( __( 'Required capability', 'wp-command-center' ) ); ?>,
		colApproval:<?php echo wp_json_encode( __( 'Approval', 'wp-command-center' ) ); ?>,
		colAvail:   <?php echo wp_json_encode( __( 'Availability', 'wp-command-center' ) ); ?>,
		unrestricted:<?php echo wp_json_encode( __( 'Unrestricted', 'wp-command-center' ) ); ?>,
		readOnly:   <?php echo wp_json_encode( __( 'read-only scope', 'wp-command-center' ) ); ?>,
		required:   <?php echo wp_json_encode( __( 'Required', 'wp-command-center' ) ); ?>,
		notReq:     <?php echo wp_json_encode( __( 'Not required', 'wp-command-center' ) ); ?>,
		available:  <?php echo wp_json_encode( __( 'Available', 'wp-command-center' ) ); ?>,
		unavailable:<?php echo wp_json_encode( __( 'Unavailable', 'wp-command-center' ) ); ?>,
		statTotal:  <?php echo wp_json_encode( __( 'Operations', 'wp-command-center' ) ); ?>,
		statAvail:  <?php echo wp_json_encode( __( 'Available', 'wp-command-center' ) ); ?>,
		statApprove:<?php echo wp_json_encode( __( 'Need approval', 'wp-command-center' ) ); ?>,
		statUnrest: <?php echo wp_json_encode( __( 'Unrestricted', 'wp-command-center' ) ); ?>,
		statMode:   <?php echo wp_json_encode( __( 'Security mode', 'wp-command-center' ) ); ?>,
		view:       <?php echo wp_json_encode( __( 'Details', 'wp-command-center' ) ); ?>,
		/* translators: %1$d shown operations, %2$d total operations */
		countFmt:   <?php echo wp_json_encode( __( 'Showing %1$d of %2$d operations', 'wp-command-center' ) ); ?>,
		prev:       <?php echo wp_json_encode( __( '← Previous', 'wp-command-center' ) ); ?>,
		next:       <?php echo wp_json_encode( __( 'Next →', 'wp-command-center' ) ); ?>,
		// Detail panel.
		secOverview:   <?php echo wp_json_encode( __( 'Overview', 'wp-command-center' ) ); ?>,
		secAuth:       <?php echo wp_json_encode( __( 'Authorization', 'wp-command-center' ) ); ?>,
		secApproval:   <?php echo wp_json_encode( __( 'Approval', 'wp-command-center' ) ); ?>,
		secAvail:      <?php echo wp_json_encode( __( 'Availability', 'wp-command-center' ) ); ?>,
		secParams:     <?php echo wp_json_encode( __( 'Parameters', 'wp-command-center' ) ); ?>,
		secActions:    <?php echo wp_json_encode( __( 'Action risk breakdown', 'wp-command-center' ) ); ?>,
		dId:           <?php echo wp_json_encode( __( 'Operation id', 'wp-command-center' ) ); ?>,
		dRisk:         <?php echo wp_json_encode( __( 'Worst-case risk', 'wp-command-center' ) ); ?>,
		dReqCap:       <?php echo wp_json_encode( __( 'Required capability', 'wp-command-center' ) ); ?>,
		dReadOnly:     <?php echo wp_json_encode( __( 'Read-only scope', 'wp-command-center' ) ); ?>,
		dAdminUnlock:  <?php echo wp_json_encode( __( 'system.admin override', 'wp-command-center' ) ); ?>,
		adminUnlocks:  <?php echo wp_json_encode( __( 'A token with system.admin can run this operation regardless of individual capabilities.', 'wp-command-center' ) ); ?>,
		unrestrictedNote: <?php echo wp_json_encode( __( 'This operation requires no capability assignment — it is unrestricted (read-only or low-risk).', 'wp-command-center' ) ); ?>,
		readOnlyYes:   <?php echo wp_json_encode( __( 'A read-only-scope token may call this operation.', 'wp-command-center' ) ); ?>,
		readOnlyNo:    <?php echo wp_json_encode( __( 'Requires a full-scope token (read-only tokens cannot call it).', 'wp-command-center' ) ); ?>,
		/* translators: %s: security mode label */
		approvalGated: <?php echo wp_json_encode( __( 'In %s, this operation requires administrator approval before it runs.', 'wp-command-center' ) ); ?>,
		/* translators: %s: security mode label */
		approvalFree:  <?php echo wp_json_encode( __( 'In %s, this operation runs immediately (no approval required).', 'wp-command-center' ) ); ?>,
		approvalDeclared: <?php echo wp_json_encode( __( 'The operation declares that it can require approval; the actual gate depends on the security mode and the specific action.', 'wp-command-center' ) ); ?>,
		availYes:      <?php echo wp_json_encode( __( 'Available on this site right now.', 'wp-command-center' ) ); ?>,
		availNo:       <?php echo wp_json_encode( __( 'Not available on this site. Availability reflects the live environment — a required plugin, integration, or WP-CLI may be inactive or missing.', 'wp-command-center' ) ); ?>,
		noParams:      <?php echo wp_json_encode( __( 'This operation declares no parameters.', 'wp-command-center' ) ); ?>,
		noActions:     <?php echo wp_json_encode( __( 'This operation declares no per-action risk breakdown; the worst-case risk above applies.', 'wp-command-center' ) ); ?>,
		colName:       <?php echo wp_json_encode( __( 'Name', 'wp-command-center' ) ); ?>,
		colType:       <?php echo wp_json_encode( __( 'Type', 'wp-command-center' ) ); ?>,
		colReq:        <?php echo wp_json_encode( __( 'Required', 'wp-command-center' ) ); ?>,
		colDesc:       <?php echo wp_json_encode( __( 'Description', 'wp-command-center' ) ); ?>,
		colAction:     <?php echo wp_json_encode( __( 'Action', 'wp-command-center' ) ); ?>,
		colValues:     <?php echo wp_json_encode( __( 'Allowed values', 'wp-command-center' ) ); ?>,
		yes:           <?php echo wp_json_encode( __( 'Yes', 'wp-command-center' ) ); ?>,
		no:            <?php echo wp_json_encode( __( 'No', 'wp-command-center' ) ); ?>
	};

	// S2.1 — server-side pagination state (no client-side load-all).
	var pg = { limit: 20, offset: 0, total: 0, returned: 0, hasMore: false };

	// D1/M2 closure: HTML escaping + the nonce-authenticated JSON fetch come from
	// the shared window.WPCC runtime (enqueued in the head), not a per-view copy.
	// CDS render helpers (badges/pills/tags/states/kpis) come from WPCC.cds.
	var WPCC = window.WPCC;
	var cds  = WPCC.cds;
	var escHtml = WPCC.escHtml;
	function apiFetch( path ) {
		// GET-only read surface — delegates to WPCC.api and maps {data}→{body}
		// so the existing call sites stay unchanged.
		return WPCC.api( 'GET', apiBase + path, nonce ).then( function( r ) {
			return { ok: r.ok, status: r.status, body: r.data };
		} );
	}
	function setHtml( id, html ) {
		var el = document.getElementById( id );
		if ( el ) { el.innerHTML = html; }
	}
	function sprintf2( tpl, a, b ) {
		return tpl.replace( '%1$d', a ).replace( '%2$d', b );
	}
	function sprintf1( tpl, a ) {
		return tpl.replace( '%s', a );
	}
	function detailUrl( id ) {
		return state.pageUrl + '?page=' + encodeURIComponent( state.page ) + '&view=' + encodeURIComponent( id );
	}
	// Risk tier → CDS risk pill (semantic, 5-tier). aria-label keeps column context.
	function riskBadge( risk ) {
		return cds.riskPill( risk, risk, i18n.colRisk + ': ' + risk );
	}
	// Availability → CDS status pill (success / neutral).
	function availBadge( ok ) {
		var text = ok ? i18n.available : i18n.unavailable;
		return cds.statusPill( ok ? 'available' : 'unavailable', text, i18n.colAvail + ': ' + text );
	}
	// Approval requirement → CDS status pill (required = warning, otherwise neutral).
	function approvalBadge( req ) {
		var text = req ? i18n.required : i18n.notReq;
		return cds.statusPill( req ? 'required' : 'notreq', text, i18n.colApproval + ': ' + text );
	}
	function capCell( op ) {
		if ( ! op.required_capability ) {
			var ro = op.read_only_scope ? ' ' + cds.tag( i18n.readOnly ) : '';
			return '<em>' + escHtml( i18n.unrestricted ) + '</em>' + ro;
		}
		return cds.tag( op.required_capability, true )
			+ ( op.read_only_scope ? ' ' + cds.tag( i18n.readOnly ) : '' );
	}

	function renderSummary( s ) {
		if ( ! s ) { setHtml( 'wpcc-ops-summary', '' ); return; }
		var mode = ( s.security_mode && s.security_mode.label ) ? s.security_mode.label : '';
		var html = '<div class="wpcc-cds-kpis">'
			+ cds.kpi( s.total, i18n.statTotal )
			+ cds.kpi( s.available, i18n.statAvail )
			+ cds.kpi( s.requires_approval_count, i18n.statApprove )
			+ cds.kpi( s.unmapped_count, i18n.statUnrest )
			+ cds.kpi( mode, i18n.statMode )
			+ '</div>';
		setHtml( 'wpcc-ops-summary', html );
	}

	// S2.1 — build the server query string from the current filter controls.
	function currentQuery() {
		var q  = document.getElementById( 'wpcc-ops-search' );
		var rk = document.getElementById( 'wpcc-ops-risk' );
		var av = document.getElementById( 'wpcc-ops-available' );
		var parts = [ 'limit=' + pg.limit, 'offset=' + pg.offset ];
		if ( q && q.value ) { parts.push( 'search=' + encodeURIComponent( q.value ) ); }
		if ( rk && rk.value ) { parts.push( 'risk=' + encodeURIComponent( rk.value ) ); }
		if ( av && av.checked ) { parts.push( 'available=1' ); }
		return '/operations?' + parts.join( '&' );
	}

	// Fetch ONE page from the server (server-side filtered + paginated). The UI
	// renders exactly the returned page — it never loads the whole catalogue.
	function loadPage() {
		apiFetch( currentQuery() ).then( function( res ) {
			if ( ! res.ok || ! res.body || ! Array.isArray( res.body.items ) ) {
				setHtml( 'wpcc-ops-panel', cds.error( i18n.loadFail ) );
				setHtml( 'wpcc-ops-pager', '' );
				return;
			}
			pg.total    = res.body.total_count || 0;
			pg.returned = res.body.returned || res.body.items.length;
			pg.hasMore  = !! res.body.has_more;
			renderTable( res.body.items );
			setHtml( 'wpcc-ops-count', escHtml( sprintf2( i18n.countFmt, pg.returned, pg.total ) ) );
			renderPager();
		} ).catch( function() {
			setHtml( 'wpcc-ops-panel', cds.error( i18n.loadFail ) );
			setHtml( 'wpcc-ops-pager', '' );
		} );
	}

	// Reset to the first page (used on any filter change).
	function reload() { pg.offset = 0; loadPage(); }

	function renderPager() {
		if ( pg.total <= pg.limit && pg.offset === 0 ) { setHtml( 'wpcc-ops-pager', '' ); return; }
		var from = pg.total ? ( pg.offset + 1 ) : 0;
		var to   = pg.offset + pg.returned;
		var html = cds.button( { label: i18n.prev, id: 'wpcc-ops-prev', disabled: pg.offset <= 0 } )
			+ '<span class="wpcc-pageinfo">' + escHtml( sprintf2( i18n.countFmt, from + '–' + to, pg.total ) ) + '</span>'
			+ cds.button( { label: i18n.next, id: 'wpcc-ops-next', disabled: ! pg.hasMore } );
		setHtml( 'wpcc-ops-pager', html );
		var prev = document.getElementById( 'wpcc-ops-prev' );
		var next = document.getElementById( 'wpcc-ops-next' );
		if ( prev ) { prev.addEventListener( 'click', function() { if ( pg.offset > 0 ) { pg.offset = Math.max( 0, pg.offset - pg.limit ); loadPage(); } } ); }
		if ( next ) { next.addEventListener( 'click', function() { if ( pg.hasMore ) { pg.offset += pg.limit; loadPage(); } } ); }
	}

	function renderTable( rows ) {
		if ( ! rows.length ) {
			setHtml( 'wpcc-ops-panel', cds.empty( i18n.empty ) );
			return;
		}
		var h = '<table class="widefat striped wpcc-cds-table wpcc-ops-table"><thead><tr>'
			+ '<th scope="col">' + escHtml( i18n.colOp ) + '</th>'
			+ '<th scope="col">' + escHtml( i18n.colRisk ) + '</th>'
			+ '<th scope="col">' + escHtml( i18n.colCap ) + '</th>'
			+ '<th scope="col">' + escHtml( i18n.colApproval ) + '</th>'
			+ '<th scope="col">' + escHtml( i18n.colAvail ) + '</th>'
			+ '</tr></thead><tbody>';

		rows.forEach( function( op ) {
			h += '<tr>'
				+ '<th scope="row"><strong><a href="' + escHtml( detailUrl( op.id ) ) + '">' + escHtml( op.title ) + '</a></strong>'
					+ '<div class="wpcc-op-id">' + escHtml( op.id ) + '</div>'
					+ ( op.summary ? '<div class="wpcc-op-desc">' + escHtml( op.summary ) + '</div>' : '' ) + '</th>'
				+ '<td>' + riskBadge( op.risk_level ) + '</td>'
				+ '<td>' + capCell( op ) + '</td>'
				+ '<td>' + approvalBadge( op.requires_approval ) + '</td>'
				+ '<td>' + availBadge( op.available ) + '</td>'
				+ '</tr>';
		} );

		h += '</tbody></table>';
		setHtml( 'wpcc-ops-panel', h );
	}

	// ── STEP 108.2 — operation detail panel (read-only) ──────────────────────
	function row( label, value ) {
		return '<tr><th scope="row">' + escHtml( label ) + '</th><td>' + value + '</td></tr>';
	}
	function note( text, warn ) {
		return '<p class="wpcc-op-note' + ( warn ? ' wpcc-op-note--warn' : '' ) + '">' + escHtml( text ) + '</p>';
	}
	function section( title, inner ) {
		return '<div class="wpcc-op-section"><h3>' + escHtml( title ) + '</h3>' + inner + '</div>';
	}

	function renderDetail( data ) {
		var op   = data.operation || {};
		var auth = data.authorization || {};
		var sec  = data.security || {};
		var mode = ( sec.mode && sec.mode.label ) ? sec.mode.label : '';

		// Overview.
		var overview = '<table class="wpcc-op-detail-table"><tbody>'
			+ row( i18n.dId, '<span class="wpcc-op-name">' + escHtml( op.id ) + '</span>' )
			+ row( i18n.dRisk, riskBadge( op.risk_level ) )
			+ row( i18n.colAvail, availBadge( op.available ) )
			+ '</tbody></table>'
			+ ( op.description ? '<div class="wpcc-op-desc-full">' + escHtml( op.description ) + '</div>' : '' );

		// Authorization.
		var authInner;
		if ( ! auth.required_capability ) {
			authInner = '<p><em>' + escHtml( i18n.unrestricted ) + '</em></p>' + note( i18n.unrestrictedNote );
		} else {
			authInner = '<table class="wpcc-op-detail-table"><tbody>'
				+ row( i18n.dReqCap, cds.tag( auth.required_capability, true ) )
				+ row( i18n.dReadOnly, escHtml( auth.read_only_scope ? i18n.yes : i18n.no ) + ' — ' + escHtml( auth.read_only_scope ? i18n.readOnlyYes : i18n.readOnlyNo ) )
				+ '</tbody></table>';
			if ( auth.unlocked_by_admin ) { authInner += note( i18n.adminUnlocks ); }
		}

		// Approval (per current security mode).
		var approvalInner;
		if ( sec.requires_approval ) {
			approvalInner = note( sprintf1( i18n.approvalGated, mode ), true );
		} else {
			approvalInner = note( sprintf1( i18n.approvalFree, mode ) );
		}
		if ( op.requires_approval ) { approvalInner += '<p class="description">' + escHtml( i18n.approvalDeclared ) + '</p>'; }

		// Availability explanation.
		var availInner = note( op.available ? i18n.availYes : i18n.availNo, ! op.available );

		// Parameters.
		var paramsInner;
		var params = Array.isArray( op.parameters ) ? op.parameters : [];
		if ( ! params.length ) {
			paramsInner = '<p class="description">' + escHtml( i18n.noParams ) + '</p>';
		} else {
			paramsInner = '<table class="widefat striped wpcc-cds-table wpcc-op-params-table"><thead><tr>'
				+ '<th scope="col">' + escHtml( i18n.colName ) + '</th>'
				+ '<th scope="col">' + escHtml( i18n.colType ) + '</th>'
				+ '<th scope="col">' + escHtml( i18n.colReq ) + '</th>'
				+ '<th scope="col">' + escHtml( i18n.colValues ) + '</th>'
				+ '<th scope="col">' + escHtml( i18n.colDesc ) + '</th>'
				+ '</tr></thead><tbody>';
			params.forEach( function( p ) {
				var enumVals = ( Array.isArray( p.enum ) && p.enum.length )
					? p.enum.map( function( v ) { return cds.tag( v, true ); } ).join( ' ' )
					: '';
				paramsInner += '<tr>'
					+ '<td><span class="wpcc-op-name">' + escHtml( p.name ) + '</span></td>'
					+ '<td>' + escHtml( p.type ) + '</td>'
					+ '<td>' + ( p.required ? '<span class="wpcc-req">' + escHtml( i18n.yes ) + '</span>' : '<span class="wpcc-opt">' + escHtml( i18n.no ) + '</span>' ) + '</td>'
					+ '<td>' + enumVals + '</td>'
					+ '<td class="wpcc-op-desc">' + escHtml( p.description ) + '</td>'
					+ '</tr>';
			} );
			paramsInner += '</tbody></table>';
		}

		// Action risk breakdown.
		var actionsInner;
		var actions = Array.isArray( op.action_risks ) ? op.action_risks : [];
		if ( ! actions.length ) {
			actionsInner = '<p class="description">' + escHtml( i18n.noActions ) + '</p>';
		} else {
			actionsInner = '<table class="widefat striped wpcc-cds-table wpcc-op-actions-table"><thead><tr>'
				+ '<th scope="col">' + escHtml( i18n.colAction ) + '</th>'
				+ '<th scope="col">' + escHtml( i18n.colRisk ) + '</th>'
				+ '<th scope="col">' + escHtml( i18n.colApproval ) + '</th>'
				+ '</tr></thead><tbody>';
			actions.forEach( function( a ) {
				actionsInner += '<tr>'
					+ '<td><span class="wpcc-op-name">' + escHtml( a.action ) + '</span></td>'
					+ '<td>' + riskBadge( a.risk_level ) + '</td>'
					+ '<td>' + approvalBadge( a.requires_approval ) + '</td>'
					+ '</tr>';
			} );
			actionsInner += '</tbody></table>';
		}

		var html = '<h2 class="wpcc-op-title">' + escHtml( op.title ) + '</h2>'
			+ section( i18n.secOverview, overview )
			+ section( i18n.secAuth, authInner )
			+ section( i18n.secApproval, approvalInner )
			+ section( i18n.secAvail, availInner )
			+ section( i18n.secParams, paramsInner )
			+ section( i18n.secActions, actionsInner );

		setHtml( 'wpcc-op-detail', html );
	}

	function initDetail() {
		var el = document.getElementById( 'wpcc-op-detail' );
		var id = el ? el.getAttribute( 'data-op-id' ) : '';
		if ( ! id ) { return; }

		apiFetch( '/operations/' + encodeURIComponent( id ) ).then( function( res ) {
			if ( res.status === 404 ) {
				setHtml( 'wpcc-op-detail', cds.empty( i18n.notFound ) );
				return;
			}
			if ( ! res.ok || ! res.body || ! res.body.operation ) {
				setHtml( 'wpcc-op-detail', cds.error( i18n.loadFail ) );
				return;
			}
			renderDetail( res.body );
		} ).catch( function() {
			setHtml( 'wpcc-op-detail', cds.error( i18n.loadFail ) );
		} );
	}

	function initList() {
		// Header counts come from the global summary endpoint (catalogue-wide).
		apiFetch( '/operations/summary' ).then( function( sum ) {
			renderSummary( sum.ok ? sum.body : null );
		} ).catch( function() { /* header is non-critical */ } );

		// Filters reset to page 1 and re-query the SERVER (no client-side load-all).
		[ 'wpcc-ops-search', 'wpcc-ops-risk', 'wpcc-ops-available' ].forEach( function( id ) {
			var el = document.getElementById( id );
			if ( el ) {
				el.addEventListener( 'input', reload );
				el.addEventListener( 'change', reload );
			}
		} );

		loadPage();
	}

	function init() {
		if ( state.viewId ) { initDetail(); } else { initList(); }
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
})();
</script>
