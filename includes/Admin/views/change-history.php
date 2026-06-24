<?php
/**
 * STEP 105.1 — Change History admin view (read-only).
 *
 * Audit/investigation surface first. Three URL-driven view states render over
 * the STEP 104 change-history backend via the cookie-authed admin REST reads:
 *
 *   - Timeline (default): flat, newest-first change list.
 *   - Sessions: session-grouped roll-up (thin aggregation read).
 *   - Reversible only: Timeline filtered to reversible changes.
 *
 * Drill into one session via ?session_id=…; open one change via ?view=… (a
 * minimal metadata panel — the diff viewer + Restore action arrive in
 * STEP 105.2 / 105.3). Reversibility is shown here as a read-only badge only;
 * there is NO rollback execution in this step.
 */

defined( 'ABSPATH' ) || exit;

$nonce    = wp_create_nonce( 'wp_rest' );
$api_base = rest_url( 'wp-command-center/v1/admin' );

$page = 'wpcc-change-history';

$valid_tabs = [ 'timeline', 'sessions', 'reversible' ];
$tab        = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'timeline'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$tab        = in_array( $tab, $valid_tabs, true ) ? $tab : 'timeline';

$session_id = isset( $_GET['session_id'] ) ? sanitize_text_field( wp_unslash( $_GET['session_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$view_id    = isset( $_GET['view'] ) ? sanitize_text_field( wp_unslash( $_GET['view'] ) ) : '';            // phpcs:ignore WordPress.Security.NonceVerification.Recommended

// Filter inputs (echoed back into the GET form so they survive navigation).
$f_runtime = isset( $_GET['runtime'] ) ? sanitize_text_field( wp_unslash( $_GET['runtime'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$f_status  = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';   // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$f_from    = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$f_to      = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';     // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$tab_url = static function ( string $t ) use ( $page ): string {
	return esc_url( add_query_arg( [ 'page' => $page, 'tab' => $t ], admin_url( 'admin.php' ) ) );
};
?>
<div class="wrap wpcc-wrap wpcc-history">
	<h1><?php esc_html_e( 'Change History', 'wp-command-center' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'Every AI- or API-driven change to this site, newest first. Reversible changes show a Restore button — restoring runs through the same approval, safety check, and audit trail as any other change.', 'wp-command-center' ); ?>
	</p>

	<?php if ( '' !== $view_id ) : ?>
		<p>
			<a href="<?php echo $tab_url( 'timeline' ); ?>">&larr; <?php esc_html_e( 'Back to Change History', 'wp-command-center' ); ?></a>
		</p>
		<div id="wpcc-history-detail" data-change-id="<?php echo esc_attr( $view_id ); ?>">
			<p><span class="spinner is-active wpcc-spin"></span><?php esc_html_e( 'Loading change…', 'wp-command-center' ); ?></p>
		</div>
		<h2 id="wpcc-diff-heading" style="display:none;"><?php esc_html_e( 'What changed', 'wp-command-center' ); ?></h2>
		<div id="wpcc-history-diff"></div>
	<?php else : ?>
		<h2 class="nav-tab-wrapper">
			<a href="<?php echo $tab_url( 'timeline' ); ?>" class="nav-tab <?php echo 'timeline' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Timeline', 'wp-command-center' ); ?></a>
			<a href="<?php echo $tab_url( 'sessions' ); ?>" class="nav-tab <?php echo 'sessions' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Sessions', 'wp-command-center' ); ?></a>
			<a href="<?php echo $tab_url( 'reversible' ); ?>" class="nav-tab <?php echo 'reversible' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Reversible only', 'wp-command-center' ); ?></a>
		</h2>

		<?php if ( '' !== $session_id ) : ?>
			<div id="wpcc-session-summary" data-session-id="<?php echo esc_attr( $session_id ); ?>" class="wpcc-session-summary">
				<span class="spinner is-active wpcc-spin"></span><?php esc_html_e( 'Loading session…', 'wp-command-center' ); ?>
			</div>
		<?php endif; ?>

		<?php if ( 'sessions' !== $tab ) : ?>
			<form method="get" class="wpcc-history-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( $page ); ?>" />
				<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>" />
				<?php if ( '' !== $session_id ) : ?>
					<input type="hidden" name="session_id" value="<?php echo esc_attr( $session_id ); ?>" />
				<?php endif; ?>
				<label><?php esc_html_e( 'Runtime', 'wp-command-center' ); ?>
					<input type="text" name="runtime" value="<?php echo esc_attr( $f_runtime ); ?>" placeholder="<?php esc_attr_e( 'e.g. seo', 'wp-command-center' ); ?>" />
				</label>
				<label><?php esc_html_e( 'Status', 'wp-command-center' ); ?>
					<input type="text" name="status" value="<?php echo esc_attr( $f_status ); ?>" placeholder="<?php esc_attr_e( 'e.g. success', 'wp-command-center' ); ?>" />
				</label>
				<label><?php esc_html_e( 'From', 'wp-command-center' ); ?>
					<input type="date" name="date_from" value="<?php echo esc_attr( $f_from ); ?>" />
				</label>
				<label><?php esc_html_e( 'To', 'wp-command-center' ); ?>
					<input type="date" name="date_to" value="<?php echo esc_attr( $f_to ); ?>" />
				</label>
				<?php submit_button( __( 'Apply', 'wp-command-center' ), 'secondary', '', false ); ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( [ 'page' => $page, 'tab' => $tab ], admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Reset', 'wp-command-center' ); ?></a>
			</form>
		<?php endif; ?>

		<div id="wpcc-history-list">
			<p><span class="spinner is-active wpcc-spin"></span><?php esc_html_e( 'Loading…', 'wp-command-center' ); ?></p>
		</div>
		<p id="wpcc-history-more" style="display:none;">
			<button type="button" class="button" id="wpcc-history-more-btn"><?php esc_html_e( 'Load more', 'wp-command-center' ); ?></button>
		</p>
	<?php endif; ?>
</div>

<div id="wpcc-restore-modal" class="wpcc-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="wpcc-restore-title" aria-describedby="wpcc-restore-msg">
	<div class="wpcc-modal-box" role="document">
		<h2 id="wpcc-restore-title"><?php esc_html_e( 'Restore change', 'wp-command-center' ); ?></h2>
		<p id="wpcc-restore-msg"></p>
		<div id="wpcc-restore-highrisk" style="display:none;">
			<div class="wpcc-restore-warning" id="wpcc-restore-warning" role="alert"></div>
			<p><label for="wpcc-restore-reason" id="wpcc-restore-reason-label"></label>
				<textarea id="wpcc-restore-reason" rows="2" class="large-text"></textarea></p>
			<p><label for="wpcc-restore-phrase" id="wpcc-restore-phrase-label"></label>
				<input type="text" id="wpcc-restore-phrase" class="regular-text" autocomplete="off" spellcheck="false" /></p>
		</div>
		<div id="wpcc-restore-result" class="wpcc-restore-result" style="display:none;" role="status" aria-live="polite"></div>
		<p class="wpcc-modal-actions">
			<button type="button" class="button button-primary" id="wpcc-restore-confirm"></button>
			<button type="button" class="button" id="wpcc-restore-cancel"></button>
		</p>
	</div>
</div>

<style>
.wpcc-modal { position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100000;display:flex;align-items:center;justify-content:center; }
.wpcc-modal-box { background:#fff;border-radius:6px;padding:20px 24px;max-width:560px;width:92%;box-shadow:0 6px 30px rgba(0,0,0,.3); }
.wpcc-modal-box h2 { margin-top:0; }
.wpcc-modal-actions { margin:16px 0 0;text-align:right; }
.wpcc-modal-actions .button { margin-left:8px; }
.wpcc-restore-warning { background:#fcf0f1;border:1px solid #d63638;color:#8a1f1f;border-radius:3px;padding:10px 12px;margin:6px 0 12px;font-size:13px; }
.wpcc-restore-result { padding:8px 12px;border-radius:3px;font-size:13px;margin:8px 0; }
.wpcc-restore-result.success { background:#edfaef;border:1px solid #00a32a; }
.wpcc-restore-result.error   { background:#fce9e9;border:1px solid #d63638; }
.wpcc-restore-result.info    { background:#f0f6fc;border:1px solid #72aee6; }
.wpcc-restore-link { color:#b32d2e; }
.wpcc-detail-restore { margin-top:14px; }
.wpcc-history .wpcc-spin { float:none;margin:0 6px 0 0;vertical-align:middle; }
.wpcc-history-filters { display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin:14px 0; }
.wpcc-history-filters label { display:flex;flex-direction:column;font-size:12px;font-weight:600;color:#50575e;gap:4px; }
.wpcc-history-filters input { font-weight:400; }
.wpcc-day-group { margin:18px 0 6px;font-size:13px;font-weight:600;color:#1d2327;border-bottom:1px solid #dcdcde;padding-bottom:4px; }
.wpcc-change-row { background:#fff;border:1px solid #e0e0e0;border-left:4px solid #c3c4c7;border-radius:4px;padding:10px 14px;margin:8px 0;max-width:900px; }
.wpcc-change-row.st-success { border-left-color:#00a32a; }
.wpcc-change-row.st-failed,.wpcc-change-row.st-error { border-left-color:#d63638; }
.wpcc-change-row.st-rolled_back { border-left-color:#8c8f94;opacity:.75; }
.wpcc-change-top { display:flex;justify-content:space-between;align-items:baseline;gap:10px; }
.wpcc-change-title { font-size:14px;font-weight:600;margin:0; }
.wpcc-change-title a { text-decoration:none; }
.wpcc-change-time { font-size:12px;color:#646970;white-space:nowrap; }
.wpcc-change-meta { font-size:12px;color:#646970;margin-top:4px;display:flex;flex-wrap:wrap;gap:6px 14px;align-items:center; }
.wpcc-chip { display:inline-block;font-size:11px;background:#f0f0f1;border:1px solid #dcdcde;border-radius:10px;padding:1px 8px;color:#3c434a;text-decoration:none; }
.wpcc-chip:hover { background:#e8e8e9; }
.wpcc-badge-rev { font-size:11px;color:#2271b1; }
.wpcc-badge-norev { font-size:11px;color:#8c8f94; }
.wpcc-badge-done { font-size:11px;color:#8c8f94;font-style:italic; }
.wpcc-session-summary { background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:10px 14px;margin:12px 0;max-width:900px;font-size:13px; }
.wpcc-sessions-table { max-width:980px; }
.wpcc-sessions-table td,.wpcc-sessions-table th { vertical-align:middle; }
.wpcc-detail-table { max-width:900px;margin-top:8px; }
.wpcc-detail-table th { text-align:left;width:180px;color:#50575e; }
.wpcc-detail-table td,.wpcc-detail-table th { padding:6px 12px;border-bottom:1px solid #f0f0f1;vertical-align:top; }
.wpcc-empty { background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:18px;max-width:900px;color:#50575e; }
.wpcc-diff-summary { background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:10px 14px;margin:10px 0;max-width:980px;font-size:13px; }
.wpcc-diff-stat { font-weight:600;margin-right:10px; }
.wpcc-diff-add { color:#0a7c2f; }
.wpcc-diff-del { color:#b32d2e; }
.wpcc-diff-filelist { margin:8px 0 0;padding-left:0;list-style:none; }
.wpcc-diff-filelist li { font-size:12px;padding:2px 0; }
.wpcc-diff-file { max-width:980px;margin:8px 0;border:1px solid #e0e0e0;border-radius:4px;background:#fff; }
.wpcc-diff-file > summary { cursor:pointer;padding:8px 12px;font-size:13px;user-select:none; }
.wpcc-diff-truncated { color:#646970;font-style:italic; }
.wpcc-change-meta-summary { max-width:980px; }
</style>

<script>
(function() {
	var nonce   = <?php echo wp_json_encode( $nonce ); ?>;
	var apiBase = <?php echo wp_json_encode( $api_base ); ?>;
	var state   = {
		tab:        <?php echo wp_json_encode( $tab ); ?>,
		sessionId:  <?php echo wp_json_encode( $session_id ); ?>,
		viewId:     <?php echo wp_json_encode( $view_id ); ?>,
		runtime:    <?php echo wp_json_encode( $f_runtime ); ?>,
		status:     <?php echo wp_json_encode( $f_status ); ?>,
		dateFrom:   <?php echo wp_json_encode( $f_from ); ?>,
		dateTo:     <?php echo wp_json_encode( $f_to ); ?>,
		pageUrl:    <?php echo wp_json_encode( admin_url( 'admin.php' ) ); ?>,
		page:       <?php echo wp_json_encode( $page ); ?>
	};
	var i18n = {
		none:        <?php echo wp_json_encode( __( 'None', 'wp-command-center' ) ); ?>,
		empty:       <?php echo wp_json_encode( __( 'No changes recorded yet. Once an AI agent or API client modifies this site, every change will be listed here.', 'wp-command-center' ) ); ?>,
		emptySess:   <?php echo wp_json_encode( __( 'No agent sessions found. Changes made without a session id appear in the Timeline view.', 'wp-command-center' ) ); ?>,
		loadFail:    <?php echo wp_json_encode( __( 'Failed to load. Your admin session may have expired — refresh the page and try again.', 'wp-command-center' ) ); ?>,
		reversible:  <?php echo wp_json_encode( __( 'Reversible', 'wp-command-center' ) ); ?>,
		notRev:      <?php echo wp_json_encode( __( 'Not reversible', 'wp-command-center' ) ); ?>,
		rolledBack:  <?php echo wp_json_encode( __( 'Rolled back', 'wp-command-center' ) ); ?>,
		changes:     <?php echo wp_json_encode( __( 'changes', 'wp-command-center' ) ); ?>,
		session:     <?php echo wp_json_encode( __( 'Session', 'wp-command-center' ) ); ?>,
		changeSets:  <?php echo wp_json_encode( __( 'change-sets', 'wp-command-center' ) ); ?>,
		allChanges:  <?php echo wp_json_encode( __( 'all changes', 'wp-command-center' ) ); ?>,
		view:        <?php echo wp_json_encode( __( 'View', 'wp-command-center' ) ); ?>,
		actor:       <?php echo wp_json_encode( __( 'Actor', 'wp-command-center' ) ); ?>,
		restore:     <?php echo wp_json_encode( __( 'Restore', 'wp-command-center' ) ); ?>,
		restoreQ:    <?php echo wp_json_encode( __( 'Revert this change? It will be reversed through the same approval and safety pipeline as the agent API — destructive and high-risk reversals require extra confirmation.', 'wp-command-center' ) ); ?>,
		restoreOk:   <?php echo wp_json_encode( __( 'Change reversed. Reloading…', 'wp-command-center' ) ); ?>,
		sentApprove: <?php echo wp_json_encode( __( 'This restore needs administrator approval and has been sent to the Approval Center.', 'wp-command-center' ) ); ?>,
		phraseLabel: <?php echo wp_json_encode( __( 'Type ROLLBACK_CHANGE to confirm', 'wp-command-center' ) ); ?>,
		reasonLabel: <?php echo wp_json_encode( __( 'Reason (required)', 'wp-command-center' ) ); ?>,
		nonceFail:   <?php echo wp_json_encode( __( 'Your admin session expired. Refresh the page and try again.', 'wp-command-center' ) ); ?>,
		genericFail: <?php echo wp_json_encode( __( 'Restore failed.', 'wp-command-center' ) ); ?>,
		openApprove: <?php echo wp_json_encode( __( 'Open Approval Center', 'wp-command-center' ) ); ?>,
		cancel:      <?php echo wp_json_encode( __( 'Cancel', 'wp-command-center' ) ); ?>,
		close:       <?php echo wp_json_encode( __( 'Close', 'wp-command-center' ) ); ?>,
		emptyRev:    <?php echo wp_json_encode( __( 'No reversible changes. Changes that can be rolled back will appear here.', 'wp-command-center' ) ); ?>,
		restoreOne:  <?php echo wp_json_encode( __( 'Restore this change', 'wp-command-center' ) ); ?>,
		colRuntimes: <?php echo wp_json_encode( __( 'Runtimes', 'wp-command-center' ) ); ?>,
		colLastAct:  <?php echo wp_json_encode( __( 'Last activity', 'wp-command-center' ) ); ?>,
		dRuntimes:   <?php echo wp_json_encode( __( 'runtimes', 'wp-command-center' ) ); ?>,
		lChangeId:   <?php echo wp_json_encode( __( 'Change ID', 'wp-command-center' ) ); ?>,
		lOperation:  <?php echo wp_json_encode( __( 'Operation', 'wp-command-center' ) ); ?>,
		lRuntime:    <?php echo wp_json_encode( __( 'Runtime', 'wp-command-center' ) ); ?>,
		lStatus:     <?php echo wp_json_encode( __( 'Status', 'wp-command-center' ) ); ?>,
		lRisk:       <?php echo wp_json_encode( __( 'Risk level', 'wp-command-center' ) ); ?>,
		lSource:     <?php echo wp_json_encode( __( 'Source', 'wp-command-center' ) ); ?>,
		lTarget:     <?php echo wp_json_encode( __( 'Target', 'wp-command-center' ) ); ?>,
		lReversible: <?php echo wp_json_encode( __( 'Reversible', 'wp-command-center' ) ); ?>,
		lKind:       <?php echo wp_json_encode( __( 'Rollback kind', 'wp-command-center' ) ); ?>,
		lChangeSet:  <?php echo wp_json_encode( __( 'Change-set', 'wp-command-center' ) ); ?>,
		lWhen:       <?php echo wp_json_encode( __( 'When', 'wp-command-center' ) ); ?>,
		lCounts:     <?php echo wp_json_encode( __( 'Counts', 'wp-command-center' ) ); ?>,
		/* translators: %1$d created, %2$d updated, %3$d skipped, %4$d errors */
		countsFmt:   <?php echo wp_json_encode( __( 'created %1$d, updated %2$d, skipped %3$d, error %4$d', 'wp-command-center' ) ); ?>,
		restoreBusy: <?php echo wp_json_encode( __( 'Restoring…', 'wp-command-center' ) ); ?>
	};
	var REQUIRED_PHRASE = 'ROLLBACK_CHANGE';
	var approvalsUrl = <?php echo wp_json_encode( admin_url( 'admin.php?page=wpcc-approval-center' ) ); ?>;

	function escHtml( s ) {
		var d = document.createElement('div');
		d.appendChild( document.createTextNode( String( s === null || s === undefined ? '' : s ) ) );
		return d.innerHTML;
	}
	function apiFetch( path, opts ) {
		opts = opts || {};
		var headers = { 'X-WP-Nonce': nonce };
		if ( opts.body ) { headers['Content-Type'] = 'application/json'; }
		return fetch( apiBase + path, Object.assign( { headers: headers }, opts ) ).then( function(r) {
			return r.json().then(
				function(j) { return { ok: r.ok, status: r.status, body: j }; },
				function()  { return { ok: r.ok, status: r.status, body: {} }; }
			);
		} );
	}
	function qs( params ) {
		var out = [];
		Object.keys( params ).forEach( function(k) {
			var v = params[k];
			if ( v !== '' && v !== null && v !== undefined ) {
				out.push( encodeURIComponent(k) + '=' + encodeURIComponent(v) );
			}
		} );
		return out.length ? ( '?' + out.join('&') ) : '';
	}
	function unix( dateStr, endOfDay ) {
		if ( ! dateStr ) return '';
		var t = Date.parse( dateStr + 'T00:00:00' );
		if ( isNaN(t) ) return '';
		t = Math.floor( t / 1000 );
		return endOfDay ? ( t + 86399 ) : t;
	}
	function fmtTime( unixSecs ) {
		if ( ! unixSecs ) return '';
		try { return new Date( unixSecs * 1000 ).toLocaleString(); } catch(e) { return String(unixSecs); }
	}
	function dayKey( unixSecs ) {
		try { return new Date( unixSecs * 1000 ).toLocaleDateString( undefined, { year:'numeric', month:'short', day:'numeric' } ); }
		catch(e) { return ''; }
	}
	function detailUrl( changeId ) {
		return state.pageUrl + qs( { page: state.page, view: changeId } );
	}
	function sessionUrl( sessionId ) {
		return state.pageUrl + qs( { page: state.page, tab: 'timeline', session_id: sessionId } );
	}

	// ── shared filter payload for list/timeline reads ──
	function listQuery( cursor ) {
		var p = {
			limit:   20,
			runtime: state.runtime,
			status:  state.status,
			since:   unix( state.dateFrom, false ),
			until:   unix( state.dateTo, true )
		};
		if ( state.sessionId ) { p.session_id = state.sessionId; }
		if ( state.tab === 'reversible' ) { p.reversible_only = 1; }
		if ( cursor ) { p.cursor = cursor; }
		return qs( p );
	}

	// ── Timeline / reversible rendering ──
	var lastDay = null;
	function renderChange( c ) {
		var status = c.status || '';
		var rev    = c.rollback || {};
		var revBadge;
		if ( rev.rolled_back ) { revBadge = '<span class="wpcc-badge-done">&#8634; ' + escHtml(i18n.rolledBack) + '</span>'; }
		else if ( rev.reversible ) { revBadge = '<span class="wpcc-badge-rev">&#8635; ' + escHtml(i18n.reversible) + '</span>'; }
		else { revBadge = '<span class="wpcc-badge-norev">&mdash;</span>'; }

		var title = ( c.operation_id || '' ) + ( c.action ? ' &middot; ' + escHtml(c.action) : '' );
		var target = c.target_key ? escHtml(c.target_key) : '';
		var actor  = ( c.actor && ( c.actor.label || c.actor.user_login || c.actor.type ) ) || '';

		var links = c.links || {};
		var chips = '';
		if ( links.session_id ) {
			chips += '<a class="wpcc-chip" href="' + escHtml( sessionUrl( links.session_id ) ) + '">' +
				escHtml(i18n.session) + ' ' + escHtml( String(links.session_id).substring(0,8) ) + '&#8230;</a>';
		}
		if ( rev.change_set_id ) {
			chips += '<span class="wpcc-chip">' + escHtml(i18n.lChangeSet) + ' ' + escHtml( String(rev.change_set_id).substring(0,8) ) + '&#8230;</span>';
		}

		// Restore is secondary and only offered when actually reversible.
		var restore = ( rev.reversible && ! rev.rolled_back )
			? '<button type="button" class="button-link wpcc-restore-link" data-change-id="' + escHtml(c.change_id) + '" aria-label="' + escHtml(i18n.restoreOne) + '">' + escHtml(i18n.restore) + '</button>'
			: '';

		return '<div class="wpcc-change-row st-' + escHtml(status) + '">' +
			'<div class="wpcc-change-top">' +
				'<p class="wpcc-change-title"><a href="' + escHtml( detailUrl( c.change_id ) ) + '">' + title + '</a></p>' +
				'<span class="wpcc-change-time">' + escHtml( fmtTime( c.created_at ) ) + '</span>' +
			'</div>' +
			'<div class="wpcc-change-meta">' +
				( target ? '<span>' + target + '</span>' : '' ) +
				( actor ? '<span>' + escHtml(i18n.actor) + ': ' + escHtml(actor) + '</span>' : '' ) +
				revBadge + chips + restore +
			'</div>' +
		'</div>';
	}

	function renderChangeList( rows, append ) {
		var list = document.getElementById('wpcc-history-list');
		if ( ! list ) return;
		if ( ! append ) { list.innerHTML = ''; lastDay = null; }
		if ( ( ! rows || ! rows.length ) && ! append ) {
			var emptyMsg = ( state.tab === 'reversible' ) ? i18n.emptyRev : i18n.empty;
			list.innerHTML = '<div class="wpcc-empty">' + escHtml( emptyMsg ) + '</div>';
			return;
		}
		var html = '';
		rows.forEach( function(c) {
			var dk = dayKey( c.created_at );
			if ( dk && dk !== lastDay ) { html += '<div class="wpcc-day-group">' + escHtml(dk) + '</div>'; lastDay = dk; }
			html += renderChange( c );
		} );
		list.insertAdjacentHTML( 'beforeend', html );
	}

	var moreCursor = null;
	function loadTimeline( cursor ) {
		// The flat view always uses /history: it honours every filter (incl.
		// reversible_only), whereas /history/timeline only honours the time window.
		apiFetch( '/history' + listQuery( cursor ) ).then( function(res) {
			if ( ! res.ok ) { showListError(); return; }
			var data = res.body || {};
			renderChangeList( data.changes || [], !! cursor );
			moreCursor = data.has_more ? data.next_cursor : null;
			toggleMore();
		} ).catch( showListError );
	}
	function showListError() {
		var list = document.getElementById('wpcc-history-list');
		if ( list ) list.innerHTML = '<div class="notice notice-error inline"><p>' + escHtml( i18n.loadFail ) + '</p></div>';
	}
	function toggleMore() {
		var more = document.getElementById('wpcc-history-more');
		if ( ! more ) return;
		more.style.display = moreCursor ? 'block' : 'none';
	}

	// ── Sessions rendering ──
	function renderSessions( rows ) {
		var list = document.getElementById('wpcc-history-list');
		if ( ! list ) return;
		if ( ! rows || ! rows.length ) {
			list.innerHTML = '<div class="wpcc-empty">' + escHtml( i18n.emptySess ) + '</div>';
			return;
		}
		var head = '<table class="widefat striped wpcc-sessions-table"><thead><tr>' +
			'<th>' + escHtml(i18n.session) + '</th><th>' + escHtml(i18n.changes) + '</th>' +
			'<th>' + escHtml(i18n.reversible) + '</th><th>' + escHtml(i18n.changeSets) + '</th>' +
			'<th>' + escHtml(i18n.actor) + '</th><th>' + escHtml(i18n.colRuntimes) + '</th><th>' + escHtml(i18n.colLastAct) + '</th>' +
			'</tr></thead><tbody>';
		var body = rows.map( function(s) {
			return '<tr>' +
				'<td><a href="' + escHtml( sessionUrl( s.session_id ) ) + '"><code>' + escHtml( String(s.session_id).substring(0,12) ) + '&#8230;</code></a></td>' +
				'<td>' + escHtml( s.change_count ) + '</td>' +
				'<td>' + escHtml( s.reversible_count ) + '</td>' +
				'<td>' + escHtml( s.change_set_count ) + '</td>' +
				'<td>' + escHtml( s.actor_summary || '' ) + '</td>' +
				'<td>' + escHtml( ( s.runtimes || [] ).join(', ') ) + '</td>' +
				'<td>' + escHtml( fmtTime( s.last_at ) ) + '</td>' +
			'</tr>';
		} ).join('');
		list.innerHTML = head + body + '</tbody></table>';
	}
	function loadSessions() {
		var p = { limit: 50, runtime: state.runtime, status: state.status, since: unix(state.dateFrom,false), until: unix(state.dateTo,true) };
		apiFetch( '/history/sessions' + qs(p) ).then( function(res) {
			if ( ! res.ok ) { showListError(); return; }
			renderSessions( ( res.body || {} ).sessions || [] );
		} ).catch( showListError );
	}

	// ── Session summary header (drill view) ──
	function loadSessionSummary() {
		var box = document.getElementById('wpcc-session-summary');
		if ( ! box ) return;
		apiFetch( '/history/sessions' + qs({ limit: 100 }) ).then( function(res) {
			var sessions = ( ( res.body || {} ).sessions ) || [];
			var s = sessions.filter( function(x){ return x.session_id === state.sessionId; } )[0];
			if ( ! s ) { box.style.display = 'none'; return; }
			box.innerHTML =
				'<strong>' + escHtml(i18n.session) + ' ' + escHtml(s.session_id) + '</strong> &middot; ' +
				escHtml(s.change_count) + ' ' + escHtml(i18n.changes) + ' &middot; ' +
				escHtml(s.reversible_count) + ' ' + escHtml(i18n.reversible.toLowerCase()) + ' &middot; ' +
				escHtml(i18n.dRuntimes) + ': ' + escHtml( (s.runtimes||[]).join(', ') || '—' ) + ' &middot; ' +
				escHtml(i18n.actor) + ': ' + escHtml(s.actor_summary || '') +
				' &nbsp; <a href="' + escHtml( state.pageUrl + qs({ page: state.page, tab:'timeline' }) ) + '">&larr; ' + escHtml(i18n.allChanges) + '</a>';
		} ).catch( function(){ box.style.display = 'none'; } );
	}

	// ── Detail panel (minimal metadata; diff viewer = STEP 105.2) ──
	function renderDetail( change ) {
		var box = document.getElementById('wpcc-history-detail');
		if ( ! box ) return;
		if ( ! change ) { box.innerHTML = '<div class="notice notice-error inline"><p>' + escHtml(i18n.loadFail) + '</p></div>'; return; }
		var rev = change.rollback || {};
		var counts = change.counts || {};
		var countsStr = i18n.countsFmt
			.replace( '%1$d', counts.created || 0 ).replace( '%2$d', counts.updated || 0 )
			.replace( '%3$d', counts.skipped || 0 ).replace( '%4$d', counts.error || 0 );
		var rows = [
			[ i18n.lChangeId,   change.change_id ],
			[ i18n.lOperation,  ( change.operation_id || '' ) + ( change.action ? ' · ' + change.action : '' ) ],
			[ i18n.lRuntime,    change.runtime || '—' ],
			[ i18n.lStatus,     change.status || '—' ],
			[ i18n.lRisk,       change.risk_level || '—' ],
			[ i18n.actor,       ( change.actor && ( change.actor.label || change.actor.user_login || change.actor.type ) ) || '—' ],
			[ i18n.lSource,     change.source || '—' ],
			[ i18n.lTarget,     change.target_key || '—' ],
			[ i18n.lReversible, rev.reversible ? ( rev.rolled_back ? i18n.rolledBack : i18n.reversible ) : i18n.notRev ],
			[ i18n.lKind,       rev.kind || 'none' ],
			[ i18n.lChangeSet,  rev.change_set_id || '—' ],
			[ i18n.lWhen,       fmtTime( change.created_at ) ],
			[ i18n.lCounts,     countsStr ]
		];

		var html = '<table class="widefat wpcc-detail-table"><tbody>';
		rows.forEach( function(r) { html += '<tr><th scope="row">' + escHtml(r[0]) + '</th><td>' + escHtml(r[1]) + '</td></tr>'; } );
		html += '</tbody></table>';

		// Secondary Restore action — same endpoint/path as the Timeline control.
		if ( rev.reversible && ! rev.rolled_back ) {
			html += '<p class="wpcc-detail-restore"><button type="button" class="button wpcc-restore-link" data-change-id="' + escHtml(change.change_id) + '" aria-label="' + escHtml(i18n.restoreOne) + '">' + escHtml(i18n.restore) + '</button></p>';
		}
		box.innerHTML = html;
	}
	function loadDetail() {
		var box = document.getElementById('wpcc-history-detail');
		if ( ! box ) return;
		apiFetch( '/history/' + encodeURIComponent( box.dataset.changeId ) ).then( function(res) {
			renderDetail( res.ok ? ( res.body || {} ).change : null );
		} ).catch( function(){ renderDetail( null ); } );
	}

	// Diff / "what changed" panel. The endpoint returns server-rendered, escaped
	// HTML (diff_kind: patch | patch_unavailable | metadata | none); JS only
	// injects it — no client-side diff parsing.
	function loadDiff() {
		var box  = document.getElementById('wpcc-history-detail');
		var diff = document.getElementById('wpcc-history-diff');
		var head = document.getElementById('wpcc-diff-heading');
		if ( ! box || ! diff ) return;
		apiFetch( '/history/' + encodeURIComponent( box.dataset.changeId ) + '/diff' ).then( function(res) {
			if ( ! res.ok ) { return; }
			var data = res.body || {};
			if ( head ) { head.style.display = 'block'; }
			diff.innerHTML = data.html || '';
		} ).catch( function(){ /* leave the metadata panel intact on diff failure */ } );
	}

	// ── Restore (rollback) modal ──
	// Every restore opens a confirmation dialog. Low-risk: confirm and POST.
	// High-risk-file patch reversals: the backend replies confirmation_required
	// (DestructiveGuard), and the modal escalates to require the ROLLBACK_CHANGE
	// phrase + a reason before re-POSTing. All execution is server-side via
	// OperationExecutor — the UI never rolls back anything itself.
	var restoreState = { changeId: null, highRisk: false };
	var restoreTrigger = null; // element to return focus to on close

	function el( id ) { return document.getElementById( id ); }

	function focusableIn( container ) {
		return Array.prototype.slice.call( container.querySelectorAll(
			'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])'
		) ).filter( function( n ) { return n.offsetParent !== null; } );
	}

	function openRestoreModal( changeId ) {
		restoreState = { changeId: changeId, highRisk: false };
		restoreTrigger = document.activeElement;
		el('wpcc-restore-msg').textContent = i18n.restoreQ;
		el('wpcc-restore-highrisk').style.display = 'none';
		el('wpcc-restore-warning').textContent = '';
		el('wpcc-restore-phrase').value = '';
		el('wpcc-restore-reason').value = '';
		el('wpcc-restore-phrase-label').textContent = i18n.phraseLabel;
		el('wpcc-restore-reason-label').textContent = i18n.reasonLabel;
		var result = el('wpcc-restore-result');
		result.style.display = 'none'; result.className = 'wpcc-restore-result';
		var confirm = el('wpcc-restore-confirm');
		confirm.textContent = i18n.restore; confirm.disabled = false; confirm.style.display = '';
		el('wpcc-restore-cancel').textContent = i18n.cancel;
		el('wpcc-restore-modal').style.display = 'flex';
		confirm.focus();
	}

	function closeRestoreModal() {
		el('wpcc-restore-modal').style.display = 'none';
		// Return focus to the control that opened the dialog (a11y).
		if ( restoreTrigger && typeof restoreTrigger.focus === 'function' ) { restoreTrigger.focus(); }
		restoreTrigger = null;
	}

	// Trap Tab within the open modal.
	function trapRestoreFocus( e ) {
		var modal = el('wpcc-restore-modal');
		if ( e.key !== 'Tab' || modal.style.display !== 'flex' ) { return; }
		var f = focusableIn( modal );
		if ( ! f.length ) { return; }
		var first = f[0], last = f[ f.length - 1 ];
		if ( e.shiftKey && document.activeElement === first ) { e.preventDefault(); last.focus(); }
		else if ( ! e.shiftKey && document.activeElement === last ) { e.preventDefault(); first.focus(); }
	}

	function showRestoreResult( cls, text, html ) {
		var r = el('wpcc-restore-result');
		r.className = 'wpcc-restore-result ' + cls;
		if ( html ) { r.innerHTML = html; } else { r.textContent = text; }
		r.style.display = 'block';
	}

	function submitRestore() {
		var confirmBtn = el('wpcc-restore-confirm');
		var body = { confirm: true };
		if ( restoreState.highRisk ) {
			var phrase = el('wpcc-restore-phrase').value.trim();
			var reason = el('wpcc-restore-reason').value.trim();
			if ( phrase !== REQUIRED_PHRASE || reason === '' ) {
				showRestoreResult( 'error', i18n.phraseLabel + ' — ' + i18n.reasonLabel );
				return;
			}
			body.confirmation_phrase = phrase;
			body.reason = reason;
		}
		confirmBtn.disabled = true;
		confirmBtn.textContent = i18n.restoreBusy;
		apiFetch( '/history/' + encodeURIComponent( restoreState.changeId ) + '/rollback', {
			method: 'POST',
			body: JSON.stringify( body )
		} ).then( function( res ) {
			confirmBtn.textContent = i18n.restore;
			if ( res.status === 403 ) { showRestoreResult( 'error', i18n.nonceFail ); confirmBtn.disabled = false; return; }
			var data   = res.body || {};
			var result = data.result || {};

			if ( result.status === 'confirmation_required' ) {
				// Escalate to the high-risk phrase + reason flow.
				restoreState.highRisk = true;
				el('wpcc-restore-highrisk').style.display = 'block';
				el('wpcc-restore-warning').textContent = result.warning || '';
				el('wpcc-restore-msg').textContent = result.message || i18n.restoreQ;
				confirmBtn.disabled = false;
				el('wpcc-restore-reason').focus();
				return;
			}
			if ( result.status === 'pending_approval' ) {
				var url = result.approval_url || approvalsUrl;
				showRestoreResult( 'info', '', escHtml(i18n.sentApprove) + ' <a href="' + escHtml(url) + '">' + escHtml(i18n.openApprove) + '</a>' );
				confirmBtn.style.display = 'none';
				el('wpcc-restore-cancel').textContent = i18n.close;
				return;
			}
			if ( data.success && ! ( result.error ) && ( result.success !== false ) ) {
				showRestoreResult( 'success', i18n.restoreOk );
				setTimeout( function() {
					closeRestoreModal();
					if ( state.viewId ) { loadDetail(); loadDiff(); }
					else if ( state.tab === 'sessions' ) { loadSessions(); }
					else { loadTimeline( null ); }
				}, 900 );
				return;
			}
			// Failure: surface the engine/manager message.
			var msg = result.message || ( ( data.errors && data.errors[0] && data.errors[0].message ) ) || result.error || i18n.genericFail;
			showRestoreResult( 'error', msg );
			confirmBtn.disabled = false;
		} ).catch( function() {
			confirmBtn.textContent = i18n.restore;
			showRestoreResult( 'error', i18n.genericFail );
			confirmBtn.disabled = false;
		} );
	}

	// ── boot ──
	document.addEventListener( 'DOMContentLoaded', function() {
		if ( state.viewId ) { loadDetail(); loadDiff(); }
		else {
			if ( state.sessionId ) { loadSessionSummary(); }
			if ( state.tab === 'sessions' ) { loadSessions(); }
			else { loadTimeline( null ); }
			var moreBtn = document.getElementById('wpcc-history-more-btn');
			if ( moreBtn ) { moreBtn.addEventListener( 'click', function(){ if ( moreCursor ) loadTimeline( moreCursor ); } ); }
		}

		// Delegated Restore triggers (Timeline rows + Detail view).
		document.addEventListener( 'click', function( e ) {
			var btn = e.target.closest ? e.target.closest( '.wpcc-restore-link' ) : null;
			if ( btn && btn.dataset.changeId ) { e.preventDefault(); openRestoreModal( btn.dataset.changeId ); }
		} );
		el('wpcc-restore-confirm').addEventListener( 'click', submitRestore );
		el('wpcc-restore-cancel').addEventListener( 'click', closeRestoreModal );
		el('wpcc-restore-modal').addEventListener( 'click', function( e ) {
			if ( e.target === el('wpcc-restore-modal') ) { closeRestoreModal(); }
		} );
		document.addEventListener( 'keydown', function( e ) {
			if ( e.key === 'Escape' && el('wpcc-restore-modal').style.display === 'flex' ) { closeRestoreModal(); return; }
			trapRestoreFocus( e );
		} );
	} );
})();
</script>
