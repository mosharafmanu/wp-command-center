<?php
/**
 * STEP 107.1 — Token & Capability Manager admin view (read-only).
 *
 * Visibility surface over the API token system (AuthTokens, STEP 10) and the
 * per-token capability assignments (CapabilityRegistry, STEP 38/44/79). Three
 * URL-driven tabs render over the cookie-authed admin REST reads:
 *
 *   - Tokens (default): every API token with its effective scope, status, and a
 *     compact "N / 34 operations" access summary; drill into one token via
 *     ?view=… to see its full per-operation access matrix.
 *   - Capabilities: the 23-capability catalogue and which operations each unlocks.
 *   - Operation Map: the 34-entry operation→capability map + read-only allowlist.
 *
 * This step is READ-ONLY: there are NO create/revoke/delete or capability
 * assign/remove controls (those arrive in STEP 107.3 / 107.4). All API output is
 * escaped client-side via escHtml. The view honestly surfaces that a token with
 * system.admin (a full-access token) is unrestricted regardless of individual
 * capabilities.
 */

defined( 'ABSPATH' ) || exit;

$nonce    = wp_create_nonce( 'wp_rest' );
$api_base = rest_url( 'wp-command-center/v1/admin' );

$page = 'wpcc-tokens';

$valid_tabs = [ 'tokens', 'capabilities', 'operations' ];
$tab        = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'tokens'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$tab        = in_array( $tab, $valid_tabs, true ) ? $tab : 'tokens';

$view_id = isset( $_GET['view'] ) ? sanitize_text_field( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$tab_url = static function ( string $t ) use ( $page ): string {
	return esc_url( add_query_arg( [ 'page' => $page, 'tab' => $t ], admin_url( 'admin.php' ) ) );
};
?>
<div class="wrap wpcc-wrap wpcc-tokens">
	<h1><?php esc_html_e( 'Tokens & Capabilities', 'wp-command-center' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'Every API token and the operations it can run, derived from its scope and assigned capabilities. Capabilities are assigned through the same audited engine as the agent API; token create/revoke arrives in a later release.', 'wp-command-center' ); ?>
	</p>

	<?php if ( '' !== $view_id ) : ?>
		<p>
			<a href="<?php echo $tab_url( 'tokens' ); ?>">&larr; <?php esc_html_e( 'Back to Tokens', 'wp-command-center' ); ?></a>
		</p>
		<div id="wpcc-token-detail" data-token-id="<?php echo esc_attr( $view_id ); ?>">
			<p><span class="spinner is-active wpcc-spin"></span><?php esc_html_e( 'Loading token…', 'wp-command-center' ); ?></p>
		</div>
	<?php else : ?>
		<h2 class="nav-tab-wrapper">
			<a href="<?php echo $tab_url( 'tokens' ); ?>" class="nav-tab <?php echo 'tokens' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Tokens', 'wp-command-center' ); ?></a>
			<a href="<?php echo $tab_url( 'capabilities' ); ?>" class="nav-tab <?php echo 'capabilities' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Capabilities', 'wp-command-center' ); ?></a>
			<a href="<?php echo $tab_url( 'operations' ); ?>" class="nav-tab <?php echo 'operations' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Operation Map', 'wp-command-center' ); ?></a>
		</h2>

		<div id="wpcc-tokens-panel">
			<p><span class="spinner is-active wpcc-spin"></span><?php esc_html_e( 'Loading…', 'wp-command-center' ); ?></p>
		</div>
	<?php endif; ?>
</div>

<?php // STEP 107.3/107.4 — one shared confirm modal for capability writes AND token lifecycle. ?>
<div id="wpcc-cap-modal" class="wpcc-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="wpcc-cap-modal-title" aria-describedby="wpcc-cap-modal-msg">
	<div class="wpcc-modal-box" role="document">
		<h2 id="wpcc-cap-modal-title"><?php esc_html_e( 'Please confirm', 'wp-command-center' ); ?></h2>
		<p id="wpcc-cap-modal-msg"></p>
		<div id="wpcc-cap-modal-result" class="wpcc-cap-result" style="display:none;" role="status" aria-live="polite"></div>
		<p class="wpcc-modal-actions">
			<button type="button" class="button button-primary" id="wpcc-cap-modal-confirm"><?php esc_html_e( 'Confirm', 'wp-command-center' ); ?></button>
			<button type="button" class="button" id="wpcc-cap-modal-cancel"><?php esc_html_e( 'Cancel', 'wp-command-center' ); ?></button>
		</p>
	</div>
</div>

<style>
.wpcc-tokens .wpcc-spin { float:none;margin:0 6px 0 0;vertical-align:middle; }
.wpcc-tokens-table,.wpcc-caps-table,.wpcc-ops-table,.wpcc-matrix-table,.wpcc-audit-table { max-width:1000px;margin-top:8px; }
.wpcc-tokens-table td,.wpcc-tokens-table th,.wpcc-caps-table td,.wpcc-caps-table th,.wpcc-ops-table td,.wpcc-ops-table th,.wpcc-matrix-table td,.wpcc-matrix-table th,.wpcc-audit-table td,.wpcc-audit-table th { vertical-align:middle; }
.wpcc-token-detail-table { max-width:760px;margin:8px 0 18px; }
.wpcc-token-detail-table th { text-align:left;width:200px;color:#50575e; }
.wpcc-token-detail-table td,.wpcc-token-detail-table th { padding:6px 12px;border-bottom:1px solid #f0f0f1;vertical-align:top; }
.wpcc-chip { display:inline-block;font-size:11px;background:#f0f0f1;border:1px solid #dcdcde;border-radius:10px;padding:1px 8px;margin:1px 2px;color:#3c434a; }
.wpcc-chip-mono { font-family:Menlo,Consolas,monospace; }
.wpcc-badge { display:inline-block;font-size:11px;border-radius:10px;padding:1px 8px; }
.wpcc-badge--good { background:#edfaef;border:1px solid #00a32a;color:#0a7c2f; }
.wpcc-badge--neutral { background:#f0f0f1;border:1px solid #c3c4c7;color:#50575e; }
.wpcc-badge--critical { background:#fce9e9;border:1px solid #d63638;color:#b32d2e; }
.wpcc-allow { color:#0a7c2f;font-weight:600; }
.wpcc-deny  { color:#b32d2e; }
.wpcc-admin-note { background:#f0f6fc;border:1px solid #72aee6;border-radius:4px;padding:10px 14px;margin:10px 0;max-width:1000px;font-size:13px; }
.wpcc-empty { background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:18px;max-width:1000px;color:#50575e; }
.wpcc-reason { font-size:11px;color:#646970; }
.wpcc-cap-manage { background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:12px 14px;margin:10px 0 18px;max-width:1000px; }
.wpcc-cap-assigned-row { display:flex;align-items:center;gap:8px;margin:4px 0; }
.wpcc-cap-add { display:flex;align-items:center;gap:8px;margin-top:10px;flex-wrap:wrap; }
.wpcc-modal { position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100000;display:flex;align-items:center;justify-content:center; }
.wpcc-modal-box { background:#fff;border-radius:6px;padding:20px 24px;max-width:520px;width:92%;box-shadow:0 6px 30px rgba(0,0,0,.3); }
.wpcc-modal-box h2 { margin-top:0; }
.wpcc-modal-actions { margin:16px 0 0;text-align:right; }
.wpcc-modal-actions .button { margin-left:8px; }
.wpcc-cap-result { padding:8px 12px;border-radius:3px;font-size:13px;margin:8px 0; }
.wpcc-cap-result.success { background:#edfaef;border:1px solid #00a32a; }
.wpcc-cap-result.error   { background:#fce9e9;border:1px solid #d63638; }
.wpcc-cap-result.info    { background:#f0f6fc;border:1px solid #72aee6; }
</style>

<script>
(function() {
	var nonce   = <?php echo wp_json_encode( $nonce ); ?>;
	var apiBase = <?php echo wp_json_encode( $api_base ); ?>;
	var state   = {
		tab:     <?php echo wp_json_encode( $tab ); ?>,
		viewId:  <?php echo wp_json_encode( $view_id ); ?>,
		pageUrl: <?php echo wp_json_encode( admin_url( 'admin.php' ) ); ?>,
		page:    <?php echo wp_json_encode( $page ); ?>
	};
	var i18n = {
		loadFail:    <?php echo wp_json_encode( __( 'Failed to load. Your admin session may have expired — refresh the page and try again.', 'wp-command-center' ) ); ?>,
		emptyTokens: <?php echo wp_json_encode( __( 'No API tokens yet. Use the form above to create one and connect an AI agent.', 'wp-command-center' ) ); ?>,
		emptyCaps:   <?php echo wp_json_encode( __( 'No capabilities are defined.', 'wp-command-center' ) ); ?>,
		emptyOps:    <?php echo wp_json_encode( __( 'No operations are mapped.', 'wp-command-center' ) ); ?>,
		notFound:    <?php echo wp_json_encode( __( 'Token not found. It may have been deleted.', 'wp-command-center' ) ); ?>,
		none:        <?php echo wp_json_encode( __( 'None', 'wp-command-center' ) ); ?>,
		never:       <?php echo wp_json_encode( __( 'Never', 'wp-command-center' ) ); ?>,
		view:        <?php echo wp_json_encode( __( 'View', 'wp-command-center' ) ); ?>,
		allow:       <?php echo wp_json_encode( __( 'Allowed', 'wp-command-center' ) ); ?>,
		deny:        <?php echo wp_json_encode( __( 'Denied', 'wp-command-center' ) ); ?>,
		colLabel:    <?php echo wp_json_encode( __( 'Label', 'wp-command-center' ) ); ?>,
		colToken:    <?php echo wp_json_encode( __( 'Token', 'wp-command-center' ) ); ?>,
		colScope:    <?php echo wp_json_encode( __( 'Scope', 'wp-command-center' ) ); ?>,
		colStatus:   <?php echo wp_json_encode( __( 'Status', 'wp-command-center' ) ); ?>,
		colAccess:   <?php echo wp_json_encode( __( 'Operation access', 'wp-command-center' ) ); ?>,
		colLastUsed: <?php echo wp_json_encode( __( 'Last used', 'wp-command-center' ) ); ?>,
		colCap:      <?php echo wp_json_encode( __( 'Capability', 'wp-command-center' ) ); ?>,
		colUnlocks:  <?php echo wp_json_encode( __( 'Unlocks operations', 'wp-command-center' ) ); ?>,
		colOp:       <?php echo wp_json_encode( __( 'Operation', 'wp-command-center' ) ); ?>,
		colReqCap:   <?php echo wp_json_encode( __( 'Required capability', 'wp-command-center' ) ); ?>,
		colReadOnly: <?php echo wp_json_encode( __( 'Read-only scope', 'wp-command-center' ) ); ?>,
		colAccessOp: <?php echo wp_json_encode( __( 'Access', 'wp-command-center' ) ); ?>,
		yes:         <?php echo wp_json_encode( __( 'Yes', 'wp-command-center' ) ); ?>,
		no:          <?php echo wp_json_encode( __( 'No', 'wp-command-center' ) ); ?>,
		/* translators: %1$d allowed operations, %2$d total operations */
		accessFmt:   <?php echo wp_json_encode( __( '%1$d / %2$d operations', 'wp-command-center' ) ); ?>,
		dLabel:      <?php echo wp_json_encode( __( 'Label', 'wp-command-center' ) ); ?>,
		dPreview:    <?php echo wp_json_encode( __( 'Token preview', 'wp-command-center' ) ); ?>,
		dScope:      <?php echo wp_json_encode( __( 'Scope', 'wp-command-center' ) ); ?>,
		dStatus:     <?php echo wp_json_encode( __( 'Status', 'wp-command-center' ) ); ?>,
		dCaps:       <?php echo wp_json_encode( __( 'Assigned capabilities', 'wp-command-center' ) ); ?>,
		dCreated:    <?php echo wp_json_encode( __( 'Created', 'wp-command-center' ) ); ?>,
		dExpires:    <?php echo wp_json_encode( __( 'Expires', 'wp-command-center' ) ); ?>,
		dLastUsed:   <?php echo wp_json_encode( __( 'Last used', 'wp-command-center' ) ); ?>,
		dAccess:     <?php echo wp_json_encode( __( 'Operation access', 'wp-command-center' ) ); ?>,
		matrixTitle: <?php echo wp_json_encode( __( 'Operation access matrix', 'wp-command-center' ) ); ?>,
		adminNote:   <?php echo wp_json_encode( __( 'This token has system.admin (full access). It can run every operation regardless of individual capabilities.', 'wp-command-center' ) ); ?>,
		unrestricted:<?php echo wp_json_encode( __( 'Unrestricted (system.admin)', 'wp-command-center' ) ); ?>,
		reasonAdmin: <?php echo wp_json_encode( __( 'system.admin', 'wp-command-center' ) ); ?>,
		reasonScope: <?php echo wp_json_encode( __( 'blocked by read-only scope', 'wp-command-center' ) ); ?>,
		reasonMiss:  <?php echo wp_json_encode( __( 'missing capability', 'wp-command-center' ) ); ?>,
		reasonHas:   <?php echo wp_json_encode( __( 'capability assigned', 'wp-command-center' ) ); ?>,
		auditTitle:  <?php echo wp_json_encode( __( 'Capability audit trail', 'wp-command-center' ) ); ?>,
		auditEmpty:  <?php echo wp_json_encode( __( 'No capability changes recorded for this token yet.', 'wp-command-center' ) ); ?>,
		colWhen:     <?php echo wp_json_encode( __( 'When', 'wp-command-center' ) ); ?>,
		colEvent:    <?php echo wp_json_encode( __( 'Event', 'wp-command-center' ) ); ?>,
		colCapEvt:   <?php echo wp_json_encode( __( 'Capability', 'wp-command-center' ) ); ?>,
		colActor:    <?php echo wp_json_encode( __( 'Actor', 'wp-command-center' ) ); ?>,
		unknownActor:<?php echo wp_json_encode( __( 'unknown', 'wp-command-center' ) ); ?>,
		manageTitle: <?php echo wp_json_encode( __( 'Manage capabilities', 'wp-command-center' ) ); ?>,
		manageHelp:  <?php echo wp_json_encode( __( 'Assigning or removing a capability runs through the same audited engine, security mode, and approval gates as the agent API.', 'wp-command-center' ) ); ?>,
		noAssigned:  <?php echo wp_json_encode( __( 'No capabilities assigned. A read-only-scope token without capabilities can run nothing until one is assigned.', 'wp-command-center' ) ); ?>,
		addLabel:    <?php echo wp_json_encode( __( 'Add capability', 'wp-command-center' ) ); ?>,
		assignBtn:   <?php echo wp_json_encode( __( 'Assign', 'wp-command-center' ) ); ?>,
		removeBtn:   <?php echo wp_json_encode( __( 'Remove', 'wp-command-center' ) ); ?>,
		allAssigned: <?php echo wp_json_encode( __( 'All assignable capabilities are already granted.', 'wp-command-center' ) ); ?>,
		adminLocked: <?php echo wp_json_encode( __( 'Capability editing is disabled for this token because system.admin already grants every operation.', 'wp-command-center' ) ); ?>,
		/* translators: %s: capability name */
		confirmAssign: <?php echo wp_json_encode( __( 'Assign the capability "%s" to this token?', 'wp-command-center' ) ); ?>,
		/* translators: %s: capability name */
		confirmRemove: <?php echo wp_json_encode( __( 'Remove the capability "%s" from this token?', 'wp-command-center' ) ); ?>,
		working:     <?php echo wp_json_encode( __( 'Working…', 'wp-command-center' ) ); ?>,
		doneReload:  <?php echo wp_json_encode( __( 'Done. Reloading…', 'wp-command-center' ) ); ?>,
		sentApprove: <?php echo wp_json_encode( __( 'This change needs administrator approval and has been sent to the Approval Center.', 'wp-command-center' ) ); ?>,
		nonceFail:   <?php echo wp_json_encode( __( 'Your admin session expired. Refresh the page and try again.', 'wp-command-center' ) ); ?>,
		genericFail: <?php echo wp_json_encode( __( 'The change could not be completed.', 'wp-command-center' ) ); ?>,
		createTitle: <?php echo wp_json_encode( __( 'Create a token', 'wp-command-center' ) ); ?>,
		createHelp:  <?php echo wp_json_encode( __( 'AI agents authenticate to the REST API with a bearer token. The secret is shown once on creation.', 'wp-command-center' ) ); ?>,
		fLabel:      <?php echo wp_json_encode( __( 'Label', 'wp-command-center' ) ); ?>,
		fScope:      <?php echo wp_json_encode( __( 'Scope', 'wp-command-center' ) ); ?>,
		fExpires:    <?php echo wp_json_encode( __( 'Expires', 'wp-command-center' ) ); ?>,
		createBtn:   <?php echo wp_json_encode( __( 'Create token', 'wp-command-center' ) ); ?>,
		scopeRead:   <?php echo wp_json_encode( __( 'Read-only', 'wp-command-center' ) ); ?>,
		scopeFull:   <?php echo wp_json_encode( __( 'Full access', 'wp-command-center' ) ); ?>,
		expNever:    <?php echo wp_json_encode( __( 'Never', 'wp-command-center' ) ); ?>,
		exp30:       <?php echo wp_json_encode( __( '30 days', 'wp-command-center' ) ); ?>,
		exp90:       <?php echo wp_json_encode( __( '90 days', 'wp-command-center' ) ); ?>,
		exp1y:       <?php echo wp_json_encode( __( '1 year', 'wp-command-center' ) ); ?>,
		labelReq:    <?php echo wp_json_encode( __( 'Enter a label for this token first.', 'wp-command-center' ) ); ?>,
		newTokenLbl: <?php echo wp_json_encode( __( 'New token (copy it now — it will not be shown again):', 'wp-command-center' ) ); ?>,
		colActions:  <?php echo wp_json_encode( __( 'Actions', 'wp-command-center' ) ); ?>,
		revokeBtn:   <?php echo wp_json_encode( __( 'Revoke', 'wp-command-center' ) ); ?>,
		deleteBtn:   <?php echo wp_json_encode( __( 'Delete', 'wp-command-center' ) ); ?>,
		/* translators: %s: token label */
		confirmRevoke: <?php echo wp_json_encode( __( 'Revoke the token "%s"? Any AI agent using it loses access immediately.', 'wp-command-center' ) ); ?>,
		/* translators: %s: token label */
		confirmDelete: <?php echo wp_json_encode( __( 'Permanently delete the token "%s"? This cannot be undone.', 'wp-command-center' ) ); ?>,
		tokenCreated:  <?php echo wp_json_encode( __( 'Token created.', 'wp-command-center' ) ); ?>
	};

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
	function fmtDate( ts ) {
		if ( ! ts ) { return i18n.never; }
		try { return new Date( ts * 1000 ).toLocaleString(); } catch ( e ) { return String( ts ); }
	}
	function sprintf2( tpl, a, b ) {
		return tpl.replace( '%1$d', a ).replace( '%2$d', b );
	}
	function viewUrl( id ) {
		return state.pageUrl + '?page=' + encodeURIComponent( state.page ) + '&tab=tokens&view=' + encodeURIComponent( id );
	}
	function statusBadge( eff ) {
		var cls = eff === 'active' ? 'wpcc-badge--good' : ( eff === 'revoked' ? 'wpcc-badge--neutral' : 'wpcc-badge--critical' );
		return '<span class="wpcc-badge ' + cls + '">' + escHtml( eff ) + '</span>';
	}
	function reasonText( reason ) {
		if ( reason === 'system_admin' )        { return i18n.reasonAdmin; }
		if ( reason === 'scope_blocked' )        { return i18n.reasonScope; }
		if ( reason === 'missing_capability' )   { return i18n.reasonMiss; }
		if ( reason === 'capability_assigned' )  { return i18n.reasonHas; }
		return reason;
	}
	function setHtml( id, html ) {
		var el = document.getElementById( id );
		if ( el ) { el.innerHTML = html; }
	}
	function fail( id ) { setHtml( id, '<div class="wpcc-empty">' + escHtml( i18n.loadFail ) + '</div>' ); }

	// ── Tokens list + lifecycle (STEP 107.4) ─────────────────────────────────
	function renderTokens( tokens ) {
		var h = '<div id="wpcc-new-token" class="wpcc-cap-result info" style="display:none;" role="status" aria-live="polite"></div>';

		// Create form (reuses AuthTokens::create server-side; secret shown once).
		h += '<div class="wpcc-cap-manage wpcc-create-token">' +
			'<h2 style="margin-top:0;">' + escHtml( i18n.createTitle ) + '</h2>' +
			'<p class="description">' + escHtml( i18n.createHelp ) + '</p>' +
			'<div class="wpcc-cap-add">' +
				'<label for="wpcc-new-label">' + escHtml( i18n.fLabel ) + '</label>' +
				'<input type="text" id="wpcc-new-label" class="regular-text" autocomplete="off" />' +
				'<label for="wpcc-new-scope">' + escHtml( i18n.fScope ) + '</label>' +
				'<select id="wpcc-new-scope">' +
					'<option value="read_only">' + escHtml( i18n.scopeRead ) + '</option>' +
					'<option value="full">' + escHtml( i18n.scopeFull ) + '</option>' +
				'</select>' +
				'<label for="wpcc-new-expires">' + escHtml( i18n.fExpires ) + '</label>' +
				'<select id="wpcc-new-expires">' +
					'<option value="never">' + escHtml( i18n.expNever ) + '</option>' +
					'<option value="30d">' + escHtml( i18n.exp30 ) + '</option>' +
					'<option value="90d">' + escHtml( i18n.exp90 ) + '</option>' +
					'<option value="1y">' + escHtml( i18n.exp1y ) + '</option>' +
				'</select>' +
				'<button type="button" class="button button-primary" id="wpcc-create-token">' + escHtml( i18n.createBtn ) + '</button>' +
			'</div>' +
			'</div>';

		if ( ! tokens.length ) {
			return h + '<div class="wpcc-empty">' + escHtml( i18n.emptyTokens ) + '</div>';
		}

		h += '<table class="widefat striped wpcc-tokens-table"><thead><tr>' +
			'<th>' + escHtml( i18n.colLabel ) + '</th>' +
			'<th>' + escHtml( i18n.colToken ) + '</th>' +
			'<th>' + escHtml( i18n.colScope ) + '</th>' +
			'<th>' + escHtml( i18n.colStatus ) + '</th>' +
			'<th>' + escHtml( i18n.colAccess ) + '</th>' +
			'<th>' + escHtml( i18n.colLastUsed ) + '</th>' +
			'<th>' + escHtml( i18n.colActions ) + '</th></tr></thead><tbody>';
		tokens.forEach( function( t ) {
			var access = t.is_admin
				? escHtml( i18n.unrestricted )
				: escHtml( sprintf2( i18n.accessFmt, t.allowed_operations, t.total_operations ) );
			var actions = '<a class="button button-small" href="' + escHtml( viewUrl( t.id ) ) + '">' + escHtml( i18n.view ) + '</a> ';
			// Active tokens can be revoked; any token can be deleted.
			if ( t.effective_status === 'active' ) {
				actions += '<button type="button" class="button button-small wpcc-token-revoke" data-id="' + escHtml( t.id ) + '" data-label="' + escHtml( t.label ) + '">' + escHtml( i18n.revokeBtn ) + '</button> ';
			}
			actions += '<button type="button" class="button button-small wpcc-token-delete" data-id="' + escHtml( t.id ) + '" data-label="' + escHtml( t.label ) + '">' + escHtml( i18n.deleteBtn ) + '</button>';
			h += '<tr>' +
				'<td>' + escHtml( t.label ) + '</td>' +
				'<td><code>' + escHtml( t.token_preview ) + '…</code></td>' +
				'<td>' + escHtml( t.scope_label ) + '</td>' +
				'<td>' + statusBadge( t.effective_status ) + '</td>' +
				'<td>' + access + '</td>' +
				'<td>' + escHtml( fmtDate( t.last_used_at ) ) + '</td>' +
				'<td class="wpcc-actions">' + actions + '</td>' +
				'</tr>';
		} );
		h += '</tbody></table>';
		return h;
	}

	// ── Token detail + access matrix ────────────────────────────────────────
	function renderTokenDetail( data ) {
		var t = data.token;
		var caps = ( t.assigned_capabilities && t.assigned_capabilities.length )
			? t.assigned_capabilities.map( function( c ) { return '<span class="wpcc-chip wpcc-chip-mono">' + escHtml( c ) + '</span>'; } ).join( ' ' )
			: escHtml( i18n.none );

		var h = '<h2>' + escHtml( t.label ) + '</h2>';
		h += '<table class="widefat wpcc-token-detail-table"><tbody>' +
			row( i18n.dPreview, '<code>' + escHtml( t.token_preview ) + '…</code>' ) +
			row( i18n.dScope, escHtml( t.scope_label ) ) +
			row( i18n.dStatus, statusBadge( t.effective_status ) ) +
			row( i18n.dCaps, caps ) +
			row( i18n.dCreated, escHtml( fmtDate( t.created_at ) ) ) +
			row( i18n.dExpires, escHtml( fmtDate( t.expires_at ) ) ) +
			row( i18n.dLastUsed, escHtml( fmtDate( t.last_used_at ) ) ) +
			'</tbody></table>';

		if ( t.is_admin ) {
			h += '<div class="wpcc-admin-note" role="note">' + escHtml( i18n.adminNote ) + '</div>';
		}

		h += '<h3>' + escHtml( i18n.matrixTitle ) + '</h3>';
		h += '<table class="widefat striped wpcc-matrix-table"><thead><tr>' +
			'<th>' + escHtml( i18n.colOp ) + '</th>' +
			'<th>' + escHtml( i18n.colReqCap ) + '</th>' +
			'<th>' + escHtml( i18n.colAccessOp ) + '</th></tr></thead><tbody>';
		( data.access_matrix || [] ).forEach( function( m ) {
			var badge = m.allowed
				? '<span class="wpcc-allow">' + escHtml( i18n.allow ) + '</span>'
				: '<span class="wpcc-deny">' + escHtml( i18n.deny ) + '</span>';
			h += '<tr>' +
				'<td><code>' + escHtml( m.operation ) + '</code></td>' +
				'<td><code>' + escHtml( m.required_capability ) + '</code></td>' +
				'<td>' + badge + ' <span class="wpcc-reason">(' + escHtml( reasonText( m.reason ) ) + ')</span></td>' +
				'</tr>';
		} );
		h += '</tbody></table>';

		// STEP 107.2 — per-token capability audit trail (read-only AuditLog tail).
		h += '<h3>' + escHtml( i18n.auditTitle ) + '</h3>';
		var trail = data.audit_trail || [];
		if ( ! trail.length ) {
			h += '<div class="wpcc-empty">' + escHtml( i18n.auditEmpty ) + '</div>';
		} else {
			h += '<table class="widefat striped wpcc-audit-table"><thead><tr>' +
				'<th>' + escHtml( i18n.colWhen ) + '</th>' +
				'<th>' + escHtml( i18n.colEvent ) + '</th>' +
				'<th>' + escHtml( i18n.colCapEvt ) + '</th>' +
				'<th>' + escHtml( i18n.colActor ) + '</th></tr></thead><tbody>';
			trail.forEach( function( e ) {
				h += '<tr>' +
					'<td>' + escHtml( fmtDate( e.timestamp ) ) + '</td>' +
					'<td><code>' + escHtml( e.action ) + '</code></td>' +
					'<td>' + ( e.capability ? '<code>' + escHtml( e.capability ) + '</code>' : escHtml( i18n.none ) ) + '</td>' +
					'<td>' + escHtml( e.actor || i18n.unknownActor ) + '</td>' +
					'</tr>';
			} );
			h += '</tbody></table>';
		}

		// STEP 107.3 — capability management (assign/remove). Honesty rule: editing
		// is DISABLED for a system.admin (full-access) token, which is already
		// unrestricted. All writes route through the audited engine (no bypass).
		h += '<h3>' + escHtml( i18n.manageTitle ) + '</h3>';
		if ( t.is_admin ) {
			h += '<div class="wpcc-admin-note" role="note">' + escHtml( i18n.adminLocked ) + '</div>';
		} else {
			h += '<div class="wpcc-cap-manage">';
			h += '<p class="description">' + escHtml( i18n.manageHelp ) + '</p>';
			var assigned = t.assigned_capabilities || [];
			if ( ! assigned.length ) {
				h += '<p>' + escHtml( i18n.noAssigned ) + '</p>';
			} else {
				assigned.forEach( function( c ) {
					h += '<div class="wpcc-cap-assigned-row">' +
						'<span class="wpcc-chip wpcc-chip-mono">' + escHtml( c ) + '</span>' +
						'<button type="button" class="button button-small wpcc-cap-remove" data-cap="' + escHtml( c ) + '">' + escHtml( i18n.removeBtn ) + '</button>' +
						'</div>';
				} );
			}
			// Assignable = catalogue caps (non-admin) not already assigned.
			var options = ( catalogue || [] ).filter( function( cap ) {
				return ! cap.is_admin && assigned.indexOf( cap.capability ) === -1;
			} );
			if ( options.length ) {
				h += '<div class="wpcc-cap-add">' +
					'<label for="wpcc-cap-select">' + escHtml( i18n.addLabel ) + '</label>' +
					'<select id="wpcc-cap-select">';
				options.forEach( function( cap ) {
					h += '<option value="' + escHtml( cap.capability ) + '">' + escHtml( cap.capability ) + '</option>';
				} );
				h += '</select>' +
					'<button type="button" class="button button-secondary" id="wpcc-cap-assign">' + escHtml( i18n.assignBtn ) + '</button>' +
					'</div>';
			} else {
				h += '<p class="description">' + escHtml( i18n.allAssigned ) + '</p>';
			}
			h += '</div>';
		}
		return h;
	}
	function row( label, valueHtml ) {
		return '<tr><th scope="row">' + escHtml( label ) + '</th><td>' + valueHtml + '</td></tr>';
	}

	// ── Capability catalogue ────────────────────────────────────────────────
	function renderCapabilities( caps ) {
		if ( ! caps.length ) { return '<div class="wpcc-empty">' + escHtml( i18n.emptyCaps ) + '</div>'; }
		var h = '<table class="widefat striped wpcc-caps-table"><thead><tr>' +
			'<th>' + escHtml( i18n.colCap ) + '</th>' +
			'<th>' + escHtml( i18n.colUnlocks ) + '</th></tr></thead><tbody>';
		caps.forEach( function( c ) {
			var ops;
			if ( c.is_admin ) {
				ops = '<em>' + escHtml( c.note ) + '</em>';
			} else if ( c.operations.length ) {
				ops = c.operations.map( function( o ) { return '<span class="wpcc-chip wpcc-chip-mono">' + escHtml( o ) + '</span>'; } ).join( ' ' );
			} else {
				ops = escHtml( i18n.none );
			}
			h += '<tr><td><code>' + escHtml( c.capability ) + '</code></td><td>' + ops + '</td></tr>';
		} );
		h += '</tbody></table>';
		return h;
	}

	// ── Operation map ───────────────────────────────────────────────────────
	function renderOperations( ops ) {
		if ( ! ops.length ) { return '<div class="wpcc-empty">' + escHtml( i18n.emptyOps ) + '</div>'; }
		var h = '<table class="widefat striped wpcc-ops-table"><thead><tr>' +
			'<th>' + escHtml( i18n.colOp ) + '</th>' +
			'<th>' + escHtml( i18n.colReqCap ) + '</th>' +
			'<th>' + escHtml( i18n.colReadOnly ) + '</th></tr></thead><tbody>';
		ops.forEach( function( o ) {
			h += '<tr>' +
				'<td><code>' + escHtml( o.operation ) + '</code></td>' +
				'<td><code>' + escHtml( o.required_capability ) + '</code></td>' +
				'<td>' + escHtml( o.read_only_scope ? i18n.yes : i18n.no ) + '</td>' +
				'</tr>';
		} );
		h += '</tbody></table>';
		return h;
	}

	// ── Capability write actions (STEP 107.3) — engine-routed, no bypass ─────
	// Confirm modal on EVERY mutation; the result lands in a role=status region.
	var modal      = document.getElementById( 'wpcc-cap-modal' );
	var modalMsg   = document.getElementById( 'wpcc-cap-modal-msg' );
	var modalRes   = document.getElementById( 'wpcc-cap-modal-result' );
	var modalOk    = document.getElementById( 'wpcc-cap-modal-confirm' );
	var modalNo    = document.getElementById( 'wpcc-cap-modal-cancel' );
	var lastFocus  = null;

	function modalOpen() { return modal && modal.style.display !== 'none'; }
	function closeModal() {
		if ( ! modal ) { return; }
		modal.style.display = 'none';
		// STEP 107.5 — return focus to the control that opened the modal.
		if ( lastFocus && lastFocus.focus ) { lastFocus.focus(); }
	}
	function openModal( message, onConfirm ) {
		if ( ! modal ) { return; }
		lastFocus = document.activeElement;
		modalMsg.textContent = message;
		modalRes.style.display = 'none';
		modalRes.className = 'wpcc-cap-result';
		modalRes.textContent = '';
		modalOk.disabled = false;
		modal.style.display = 'flex';
		modalOk.onclick = onConfirm;
		modalOk.focus();
	}
	function showResult( cls, text ) {
		modalRes.className = 'wpcc-cap-result ' + cls;
		modalRes.textContent = text;
		modalRes.style.display = 'block';
	}
	// STEP 107.5 — keep Tab focus inside the open modal (focus trap).
	function trapFocus( e ) {
		var nodes = modal.querySelectorAll( 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])' );
		var f = Array.prototype.filter.call( nodes, function( el ) {
			return ! el.disabled && el.offsetParent !== null;
		} );
		if ( ! f.length ) { return; }
		var first = f[0], last = f[ f.length - 1 ];
		if ( e.shiftKey && document.activeElement === first ) { e.preventDefault(); last.focus(); }
		else if ( ! e.shiftKey && document.activeElement === last ) { e.preventDefault(); first.focus(); }
	}
	if ( modalNo ) { modalNo.addEventListener( 'click', closeModal ); }
	if ( modal ) {
		// STEP 107.5 — full keyboard support: Esc closes, Tab/Shift+Tab trapped.
		modal.addEventListener( 'keydown', function( e ) {
			if ( ! modalOpen() ) { return; }
			if ( e.key === 'Escape' ) { closeModal(); return; }
			if ( e.key === 'Tab' ) { trapFocus( e ); }
		} );
	}

	function handleWrite( r ) {
		if ( r.status === 403 ) { showResult( 'error', i18n.nonceFail ); return; }
		var body = r.body || {};
		var res  = body.result || {};
		// Engine returned pending_approval (client/enterprise mode): no execution.
		if ( body.success && res.status === 'pending_approval' ) { showResult( 'info', i18n.sentApprove ); return; }
		if ( body.success ) { showResult( 'success', i18n.doneReload ); setTimeout( function() { window.location.reload(); }, 700 ); return; }
		var msg = ( body.errors && body.errors[0] && body.errors[0].message ) ? body.errors[0].message : i18n.genericFail;
		showResult( 'error', msg );
	}
	// One write path for both assign (POST + body) and remove (DELETE). Every
	// call opens the confirm modal first; the request only fires on confirm.
	function doWrite( method, path, jsonBody, capName, confirmTpl ) {
		openModal( confirmTpl.replace( '%s', capName ), function() {
			modalOk.disabled = true;
			showResult( 'info', i18n.working );
			var opts = { method: method };
			if ( jsonBody ) { opts.body = JSON.stringify( jsonBody ); }
			apiFetch( path, opts ).then( handleWrite ).catch( function() { showResult( 'error', i18n.genericFail ); } );
		} );
	}
	function wireManage() {
		var assignBtn = document.getElementById( 'wpcc-cap-assign' );
		if ( assignBtn ) {
			assignBtn.addEventListener( 'click', function() {
				var sel = document.getElementById( 'wpcc-cap-select' );
				var cap = sel ? sel.value : '';
				if ( ! cap ) { return; }
				doWrite(
					'POST',
					'/tokens/' + encodeURIComponent( state.viewId ) + '/capabilities',
					{ capability: cap },
					cap,
					i18n.confirmAssign
				);
			} );
		}
		var removeBtns = document.querySelectorAll( '.wpcc-cap-remove' );
		Array.prototype.forEach.call( removeBtns, function( btn ) {
			btn.addEventListener( 'click', function() {
				var cap = btn.getAttribute( 'data-cap' );
				doWrite(
					'DELETE',
					'/tokens/' + encodeURIComponent( state.viewId ) + '/capabilities/' + encodeURIComponent( cap ),
					null,
					cap,
					i18n.confirmRemove
				);
			} );
		} );
	}

	// ── Token lifecycle (STEP 107.4) — create / revoke / delete ──────────────
	// Reuses the same confirm modal + apiFetch. revoke/delete reuse doWrite (which
	// reloads on success); create preserves the one-time secret instead of a full
	// reload, then re-renders the list in place.
	function bannerMsg( cls, text ) {
		var b = document.getElementById( 'wpcc-new-token' );
		if ( ! b ) { return; }
		b.className = 'wpcc-cap-result ' + cls;
		b.style.display = 'block';
		b.textContent = text;
	}
	function showNewToken( secret ) {
		var b = document.getElementById( 'wpcc-new-token' );
		if ( ! b ) { return; }
		b.className = 'wpcc-cap-result info';
		b.style.display = 'block';
		b.textContent = '';
		var strong = document.createElement( 'strong' );
		strong.textContent = i18n.newTokenLbl + ' ';
		var inp = document.createElement( 'input' );
		inp.type = 'text'; inp.readOnly = true; inp.className = 'large-text code'; inp.value = secret;
		inp.addEventListener( 'focus', function() { inp.select(); } );
		b.appendChild( strong );
		b.appendChild( inp );
	}
	function reloadTokensPanel( secret ) {
		apiFetch( '/tokens' ).then( function( r ) {
			if ( ! r.ok ) { return fail( 'wpcc-tokens-panel' ); }
			setHtml( 'wpcc-tokens-panel', renderTokens( r.body.tokens || [] ) );
			wireTokens();
			if ( secret ) { showNewToken( secret ); }
		} ).catch( function() { fail( 'wpcc-tokens-panel' ); } );
	}
	function createToken() {
		var labelEl = document.getElementById( 'wpcc-new-label' );
		var label   = labelEl ? labelEl.value : '';
		if ( ! label || ! label.trim() ) { bannerMsg( 'error', i18n.labelReq ); if ( labelEl ) { labelEl.focus(); } return; }
		var scope   = ( document.getElementById( 'wpcc-new-scope' )   || {} ).value || 'read_only';
		var expires = ( document.getElementById( 'wpcc-new-expires' ) || {} ).value || 'never';
		openModal( i18n.createTitle + ' — ' + label, function() {
			modalOk.disabled = true;
			showResult( 'info', i18n.working );
			apiFetch( '/tokens', { method: 'POST', body: JSON.stringify( { label: label, scope: scope, expires: expires } ) } ).then( function( r ) {
				if ( r.status === 403 ) { showResult( 'error', i18n.nonceFail ); return; }
				var body = r.body || {};
				if ( body.success && body.token ) {
					showResult( 'success', i18n.tokenCreated );
					var secret = body.token;
					setTimeout( function() { closeModal(); reloadTokensPanel( secret ); }, 500 );
					return;
				}
				var msg = ( body.errors && body.errors[0] && body.errors[0].message ) ? body.errors[0].message : i18n.genericFail;
				showResult( 'error', msg );
			} ).catch( function() { showResult( 'error', i18n.genericFail ); } );
		} );
	}
	function wireTokens() {
		var createBtn = document.getElementById( 'wpcc-create-token' );
		if ( createBtn ) { createBtn.addEventListener( 'click', createToken ); }
		Array.prototype.forEach.call( document.querySelectorAll( '.wpcc-token-revoke' ), function( btn ) {
			btn.addEventListener( 'click', function() {
				doWrite( 'POST', '/tokens/' + encodeURIComponent( btn.getAttribute( 'data-id' ) ) + '/revoke', null, btn.getAttribute( 'data-label' ), i18n.confirmRevoke );
			} );
		} );
		Array.prototype.forEach.call( document.querySelectorAll( '.wpcc-token-delete' ), function( btn ) {
			btn.addEventListener( 'click', function() {
				doWrite( 'DELETE', '/tokens/' + encodeURIComponent( btn.getAttribute( 'data-id' ) ), null, btn.getAttribute( 'data-label' ), i18n.confirmDelete );
			} );
		} );
	}

	// ── Boot ────────────────────────────────────────────────────────────────
	var catalogue = [];
	function loadDetail() {
		Promise.all([
			apiFetch( '/tokens/' + encodeURIComponent( state.viewId ) ),
			apiFetch( '/capabilities' )
		]).then( function( results ) {
			var detail = results[0];
			var caps   = results[1];
			if ( detail.status === 404 ) {
				setHtml( 'wpcc-token-detail', '<div class="wpcc-empty">' + escHtml( i18n.notFound ) + '</div>' );
				return;
			}
			if ( ! detail.ok ) { return fail( 'wpcc-token-detail' ); }
			catalogue = ( caps.ok && caps.body && caps.body.capabilities ) ? caps.body.capabilities : [];
			setHtml( 'wpcc-token-detail', renderTokenDetail( detail.body ) );
			wireManage();
		} ).catch( function() { fail( 'wpcc-token-detail' ); } );
	}
	function loadPanel() {
		if ( state.tab === 'capabilities' ) {
			apiFetch( '/capabilities' ).then( function( r ) {
				if ( ! r.ok ) { return fail( 'wpcc-tokens-panel' ); }
				setHtml( 'wpcc-tokens-panel', renderCapabilities( r.body.capabilities || [] ) );
			} ).catch( function() { fail( 'wpcc-tokens-panel' ); } );
		} else if ( state.tab === 'operations' ) {
			apiFetch( '/operations-map' ).then( function( r ) {
				if ( ! r.ok ) { return fail( 'wpcc-tokens-panel' ); }
				setHtml( 'wpcc-tokens-panel', renderOperations( r.body.operations || [] ) );
			} ).catch( function() { fail( 'wpcc-tokens-panel' ); } );
		} else {
			apiFetch( '/tokens' ).then( function( r ) {
				if ( ! r.ok ) { return fail( 'wpcc-tokens-panel' ); }
				setHtml( 'wpcc-tokens-panel', renderTokens( r.body.tokens || [] ) );
				wireTokens();
			} ).catch( function() { fail( 'wpcc-tokens-panel' ); } );
		}
	}

	if ( state.viewId ) { loadDetail(); } else { loadPanel(); }
})();
</script>
