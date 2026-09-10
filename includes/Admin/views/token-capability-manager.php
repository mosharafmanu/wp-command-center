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
 * The write controls arrived in STEP 107.3 (capability assign/remove) and 107.4
 * (token create/revoke/delete); the docblock above describing this view as
 * read-only had been wrong ever since. All API output is escaped client-side via
 * escHtml. The view honestly surfaces that a token with system.admin (a
 * full-access token) is unrestricted regardless of individual capabilities.
 *
 * Creating a token is a DIALOG, not a form sitting open above the list. The
 * default state of a screen for reviewing and revoking access must not be "one
 * click from minting a new key" — and the choice that matters most, how much
 * the token may do, needs room to explain itself rather than two unlabelled
 * options in a dropdown. Read-only is the default here; the warning shown for
 * full access names this site's actual protection mode, so it stays true rather
 * than merely reassuring.
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
	// Returns the RAW url; every call site escapes at the point of output. Escaping
	// inside the closure was correct but invisible to static analysis, so each echo
	// read as unescaped output.
	return add_query_arg( [ 'page' => $page, 'tab' => $t ], admin_url( 'admin.php' ) );
};
?>
<div class="wrap wpcc-wrap wpcc-tokens">
	<h1><?php esc_html_e( 'Access', 'action-steward' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'A token is how an assistant reaches this site. Create one for each assistant you connect, and see exactly what it is allowed to do. The token is shown once when you create it — copy it then. You can revoke any token instantly.', 'action-steward' ); ?>
	</p>

	<?php if ( '' !== $view_id ) : ?>
		<p>
			<a href="<?php echo esc_url( $tab_url( 'tokens' ) ); ?>">&larr; <?php esc_html_e( 'Back to Tokens', 'action-steward' ); ?></a>
		</p>
		<div id="wpcc-token-detail" data-token-id="<?php echo esc_attr( $view_id ); ?>">
			<p><span class="spinner is-active wpcc-spin"></span><?php esc_html_e( 'Loading token…', 'action-steward' ); ?></p>
		</div>
	<?php else : ?>
		<h2 class="nav-tab-wrapper">
			<a href="<?php echo esc_url( $tab_url( 'tokens' ) ); ?>" class="nav-tab <?php echo 'tokens' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Tokens', 'action-steward' ); ?></a>
			<a href="<?php echo esc_url( $tab_url( 'capabilities' ) ); ?>" class="nav-tab <?php echo 'capabilities' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Capabilities', 'action-steward' ); ?></a>
			<a href="<?php echo esc_url( $tab_url( 'operations' ) ); ?>" class="nav-tab <?php echo 'operations' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Operation Map', 'action-steward' ); ?></a>
		</h2>

		<div id="wpcc-tokens-panel">
			<p><span class="spinner is-active wpcc-spin"></span><?php esc_html_e( 'Loading…', 'action-steward' ); ?></p>
		</div>
	<?php endif; ?>
</div>

<?php
/*
 * Token creation dialog.
 *
 * Creation gets its OWN dialog rather than reusing the shared "Please confirm"
 * modal below. Those are different jobs: the shared modal restates a decision
 * the customer has already expressed elsewhere on the page ("Remove the
 * capability X?"), while creating a token is where the decision is MADE — the
 * name, the access level and the expiry are all chosen here. Routing creation
 * through a generic confirm produced the worst of both: a form the customer
 * could submit by accident, followed by a modal that said "Please confirm"
 * without repeating a single thing they had chosen.
 *
 * The dialog's own "Create token" button is the explicit final action. There is
 * no second confirmation, because a second confirmation after a deliberate form
 * is the kind of ceremony people learn to click through.
 */
$wpcc_tok_protected = \WPCommandCenter\Operations\SecurityModeManager::is_protected();
$wpcc_tok_mode      = \WPCommandCenter\Operations\SecurityModeManager::label();
?>
<div id="wpcc-tok-dialog" class="wpcc-modal wpcc-tokdlg" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="wpcc-tokdlg-title">
	<div class="wpcc-modal-box wpcc-tokdlg__box" role="document">
		<h2 id="wpcc-tokdlg-title"><?php esc_html_e( 'Create an access token', 'action-steward' ); ?></h2>

		<?php // ── The form. Hidden once the secret is on screen. ── ?>
		<div id="wpcc-tokdlg-form">
			<p class="description" style="margin-top:0;">
				<?php esc_html_e( 'This creates one key for one assistant. You will see it once, and you can revoke it at any time.', 'action-steward' ); ?>
			</p>

			<div class="wpcc-tokdlg__field">
				<label class="wpcc-tokdlg__label" for="wpcc-new-label"><?php esc_html_e( 'Name this token', 'action-steward' ); ?></label>
				<input type="text" id="wpcc-new-label" class="regular-text" maxlength="120" autocomplete="off" spellcheck="false" aria-describedby="wpcc-tokdlg-label-hint" />
				<p class="wpcc-tokdlg__hint" id="wpcc-tokdlg-label-hint">
					<?php esc_html_e( 'Use a name you will recognise months from now — usually the assistant you are connecting, or the person using it.', 'action-steward' ); ?>
				</p>
				<p class="wpcc-tokdlg__dupe" id="wpcc-tokdlg-dupe" role="status" hidden></p>
			</div>

			<fieldset class="wpcc-tokdlg__field">
				<legend class="wpcc-tokdlg__label"><?php esc_html_e( 'What this token may do', 'action-steward' ); ?></legend>

				<?php
				/*
				 * Read-only is the DEFAULT here, and deliberately not the default on
				 * the Assistants screen. The two screens answer different questions.
				 * Assistants is "connect Claude and have it work", where a restricted
				 * token refuses ordinary questions and reads as a broken product.
				 * This screen is the access register — someone opening it is auditing
				 * or minting a key outside the guided flow, and the least surprising
				 * key to hand them is the one that cannot ask for changes.
				 */
				?>
				<label class="wpcc-tokdlg__choice">
					<input type="radio" name="wpcc-new-scope" value="read_only" checked />
					<span>
						<strong><?php esc_html_e( 'Read-only — inspect the site, no changes', 'action-steward' ); ?></strong>
						<em><?php esc_html_e( 'Can read site information, diagnostics, and supported list/get actions. Cannot change data, submit changes for approval, or approve them.', 'action-steward' ); ?></em>
					</span>
				</label>

				<label class="wpcc-tokdlg__choice">
					<input type="radio" name="wpcc-new-scope" value="full" />
					<span>
						<strong><?php esc_html_e( 'Full access — read the site and request changes', 'action-steward' ); ?></strong>
						<em><?php esc_html_e( 'Can answer questions about the whole site and ask to change it. This is what a connected assistant normally needs.', 'action-steward' ); ?></em>
					</span>
				</label>
			</fieldset>

			<div class="wpcc-tokdlg__field">
				<label class="wpcc-tokdlg__label" for="wpcc-new-expires"><?php esc_html_e( 'Stop working after', 'action-steward' ); ?></label>
				<?php
				// 30 days is the default; Never remains available as a deliberate
				// choice rather than an inherited one. See the note in
				// ai-integrations.php — both forms behave the same way.
				?>
				<select id="wpcc-new-expires">
					<option value="30d" selected><?php esc_html_e( '30 days (recommended)', 'action-steward' ); ?></option>
					<option value="90d"><?php esc_html_e( '90 days', 'action-steward' ); ?></option>
					<option value="1y"><?php esc_html_e( '1 year', 'action-steward' ); ?></option>
					<option value="never"><?php esc_html_e( 'Never — until I revoke it', 'action-steward' ); ?></option>
				</select>
			</div>

			<?php
			// Shown only when Full access is selected — a warning that is always on
			// screen is wallpaper. It has to be true, so it names the actual mode.
			?>
			<?php
			// Full access AND never expires: a permanent key to everything, with no
			// expiry to fall back on if it leaks. Shown only when both are chosen.
			?>
			<p class="wpcc-tokdlg__warn wpcc-tokdlg__warn--danger" id="wpcc-tokdlg-longlived" hidden>
				<?php esc_html_e( 'Heads up: full access that never expires is a permanent key to everything on this site. If it is ever copied or leaked there is no expiry to fall back on — you would have to notice and revoke it. Pick an expiry unless you have a reason not to.', 'action-steward' ); ?>
			</p>

			<p class="wpcc-tokdlg__warn<?php echo esc_attr( $wpcc_tok_protected ? '' : ' wpcc-tokdlg__warn--danger' ); ?>" id="wpcc-tokdlg-warn" hidden>
				<?php if ( $wpcc_tok_protected ) : ?>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: the site's protection mode, e.g. "Standard protection". */
							__( 'Full access lets this token ask to change anything on the site. Requests follow %s; full access does not bypass required human approval.', 'action-steward' ),
							$wpcc_tok_mode
						)
					);
					?>
				<?php else : ?>
					<?php esc_html_e( 'Warning: this site is in Developer mode, so a full-access token can change your site straight away, without asking you first. Change this under Settings › Protection.', 'action-steward' ); ?>
				<?php endif; ?>
			</p>

			<div id="wpcc-tokdlg-result" class="wpcc-cap-result" style="display:none;" role="status" aria-live="polite"></div>

			<p class="wpcc-modal-actions">
				<button type="button" class="button" id="wpcc-tokdlg-cancel"><?php esc_html_e( 'Cancel', 'action-steward' ); ?></button>
				<button type="button" class="button button-primary" id="wpcc-tokdlg-create"><?php esc_html_e( 'Create token', 'action-steward' ); ?></button>
			</p>
		</div>

		<?php // ── The one-time secret. Replaces the form in place, so the customer stays put. ── ?>
		<div id="wpcc-tokdlg-secret" hidden>
			<p class="wpcc-tokdlg__ready" id="wpcc-tokdlg-ready"></p>
			<p class="wpcc-tokdlg__hint">
				<?php esc_html_e( 'This is the only time it will be shown. Copy it now and paste it into your assistant — if you lose it, revoke this token and create another.', 'action-steward' ); ?>
			</p>
			<div class="wpcc-tokdlg__secretrow">
				<input type="text" id="wpcc-tokdlg-value" class="large-text code" readonly />
				<button type="button" class="button button-primary" id="wpcc-tokdlg-copy"><?php esc_html_e( 'Copy', 'action-steward' ); ?></button>
			</div>
			<p class="wpcc-tokdlg__copied" id="wpcc-tokdlg-copied" role="status" hidden><?php esc_html_e( 'Copied to your clipboard.', 'action-steward' ); ?></p>
			<p class="wpcc-modal-actions">
				<button type="button" class="button button-primary" id="wpcc-tokdlg-done"><?php esc_html_e( 'Done', 'action-steward' ); ?></button>
			</p>
		</div>
	</div>
</div>

<?php // STEP 107.3/107.4 — one shared confirm modal for capability writes AND token lifecycle. ?>
<div id="wpcc-cap-modal" class="wpcc-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="wpcc-cap-modal-title" aria-describedby="wpcc-cap-modal-msg">
	<div class="wpcc-modal-box" role="document">
		<h2 id="wpcc-cap-modal-title"><?php esc_html_e( 'Please confirm', 'action-steward' ); ?></h2>
		<p id="wpcc-cap-modal-msg"></p>
		<div id="wpcc-cap-modal-result" class="wpcc-cap-result" style="display:none;" role="status" aria-live="polite"></div>
		<p class="wpcc-modal-actions">
			<button type="button" class="button button-primary" id="wpcc-cap-modal-confirm"><?php esc_html_e( 'Confirm', 'action-steward' ); ?></button>
			<button type="button" class="button" id="wpcc-cap-modal-cancel"><?php esc_html_e( 'Cancel', 'action-steward' ); ?></button>
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

.wpcc-token-filter { display:inline-flex; align-items:center; gap:6px; margin:0 0 10px; font-size:12px; color:var(--wpcc-text-secondary); }
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

/* Token creation dialog. */
.wpcc-tokdlg__box { max-width:560px; }
.wpcc-tokdlg__field { margin:0 0 18px;border:0;padding:0; }
.wpcc-tokdlg__label { display:block;margin:0 0 6px;font-weight:600;font-size:13px;color:#1d2327; }
.wpcc-tokdlg__hint { margin:6px 0 0;font-size:12px;line-height:1.6;color:#646970;max-width:64ch; }
.wpcc-tokdlg__dupe { margin:8px 0 0;font-size:12px;line-height:1.6;color:#8a6100;
	background:#fcf9e8;border:1px solid #f0e2a6;border-radius:6px;padding:8px 10px;max-width:64ch; }
.wpcc-tokdlg__choice { display:flex;gap:10px;align-items:flex-start;padding:10px 12px;margin:0 0 8px;
	border:1px solid #dcdcde;border-radius:8px;cursor:pointer; }
.wpcc-tokdlg__choice:has(input:checked) { border-color:#2271b1;background:#f6fafd; }
.wpcc-tokdlg__choice input { margin-top:3px;flex:0 0 auto; }
.wpcc-tokdlg__choice strong { display:block;font-size:13px;color:#1d2327; }
.wpcc-tokdlg__choice em { display:block;margin-top:4px;font-style:normal;font-size:12px;line-height:1.6;color:#646970; }
.wpcc-tokdlg__warn { margin:0 0 18px;font-size:12px;line-height:1.6;color:#3c434a;
	background:#f0f6fc;border:1px solid #c5d9ed;border-radius:6px;padding:10px 12px;max-width:64ch; }
.wpcc-tokdlg__warn--danger { color:#8a2424;background:#fcf0f0;border-color:#eec2c2; }
.wpcc-tokdlg__ready { margin:0 0 8px;font-size:14px;font-weight:600;color:#1d2327; }
.wpcc-tokdlg__secretrow { display:flex;gap:8px;align-items:center;margin:12px 0 0; }
.wpcc-tokdlg__secretrow input { flex:1 1 auto;font-family:Menlo,Consolas,monospace;font-size:12px; }
.wpcc-tokdlg__copied { margin:8px 0 0;font-size:12px;color:#0a7c2f; }
.wpcc-modal-actions { display:flex;gap:8px;justify-content:flex-end;align-items:center; }
.wpcc-modal-actions .button { margin-left:0; }
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
		loadFail:    <?php echo wp_json_encode( __( 'Failed to load. Your admin session may have expired — refresh the page and try again.', 'action-steward' ) ); ?>,
		emptyTokens: <?php echo wp_json_encode( __( 'No access tokens yet. Create one above, then paste it into your assistant to connect it.', 'action-steward' ) ); ?>,
		emptyActive: <?php echo wp_json_encode( __( 'No active tokens. Create one above, or show revoked tokens to review past access.', 'action-steward' ) ); ?>,
		/* translators: %1$d and %2$d are both the number of revoked or expired tokens */
		showRevoked: <?php echo wp_json_encode( /* translators: %1$d: number */ __( 'Show %1$d revoked or expired token(s)', 'action-steward' ) ); ?>,
		emptyCaps:   <?php echo wp_json_encode( __( 'No capabilities are defined.', 'action-steward' ) ); ?>,
		emptyOps:    <?php echo wp_json_encode( __( 'No operations are mapped.', 'action-steward' ) ); ?>,
		notFound:    <?php echo wp_json_encode( __( 'Token not found. It may have been deleted.', 'action-steward' ) ); ?>,
		none:        <?php echo wp_json_encode( __( 'None', 'action-steward' ) ); ?>,
		never:       <?php echo wp_json_encode( __( 'Never', 'action-steward' ) ); ?>,
		view:        <?php echo wp_json_encode( __( 'View', 'action-steward' ) ); ?>,
		allow:       <?php echo wp_json_encode( __( 'Allowed', 'action-steward' ) ); ?>,
		deny:        <?php echo wp_json_encode( __( 'Denied', 'action-steward' ) ); ?>,
		colLabel:    <?php echo wp_json_encode( __( 'Label', 'action-steward' ) ); ?>,
		colToken:    <?php echo wp_json_encode( __( 'Token', 'action-steward' ) ); ?>,
		colScope:    <?php echo wp_json_encode( __( 'Scope', 'action-steward' ) ); ?>,
		colStatus:   <?php echo wp_json_encode( __( 'Status', 'action-steward' ) ); ?>,
		colAccess:   <?php echo wp_json_encode( __( 'Operation access', 'action-steward' ) ); ?>,
		colLastUsed: <?php echo wp_json_encode( __( 'Last used', 'action-steward' ) ); ?>,
		colCap:      <?php echo wp_json_encode( __( 'Capability', 'action-steward' ) ); ?>,
		colUnlocks:  <?php echo wp_json_encode( __( 'Unlocks operations', 'action-steward' ) ); ?>,
		colOp:       <?php echo wp_json_encode( __( 'Operation', 'action-steward' ) ); ?>,
		colReqCap:   <?php echo wp_json_encode( __( 'Required capability', 'action-steward' ) ); ?>,
		colReadOnly: <?php echo wp_json_encode( __( 'Read-only scope', 'action-steward' ) ); ?>,
		colAccessOp: <?php echo wp_json_encode( __( 'Access', 'action-steward' ) ); ?>,
		yes:         <?php echo wp_json_encode( __( 'Yes', 'action-steward' ) ); ?>,
		no:          <?php echo wp_json_encode( __( 'No', 'action-steward' ) ); ?>,
		/* translators: %1$d allowed operations, %2$d total operations */
		accessFmt:   <?php echo wp_json_encode( /* translators: %1$d: number, %2$d: number */ __( '%1$d / %2$d operations', 'action-steward' ) ); ?>,
		dLabel:      <?php echo wp_json_encode( __( 'Label', 'action-steward' ) ); ?>,
		dPreview:    <?php echo wp_json_encode( __( 'Token preview', 'action-steward' ) ); ?>,
		dScope:      <?php echo wp_json_encode( __( 'Scope', 'action-steward' ) ); ?>,
		dStatus:     <?php echo wp_json_encode( __( 'Status', 'action-steward' ) ); ?>,
		dCaps:       <?php echo wp_json_encode( __( 'Assigned capabilities', 'action-steward' ) ); ?>,
		dCreated:    <?php echo wp_json_encode( __( 'Created', 'action-steward' ) ); ?>,
		dExpires:    <?php echo wp_json_encode( __( 'Expires', 'action-steward' ) ); ?>,
		dLastUsed:   <?php echo wp_json_encode( __( 'Last used', 'action-steward' ) ); ?>,
		dAccess:     <?php echo wp_json_encode( __( 'Operation access', 'action-steward' ) ); ?>,
		matrixTitle: <?php echo wp_json_encode( __( 'Operation access matrix', 'action-steward' ) ); ?>,
		adminNote:   <?php echo wp_json_encode( __( 'This token has system.admin (full access). It can run every operation regardless of individual capabilities.', 'action-steward' ) ); ?>,
		unrestricted:<?php echo wp_json_encode( __( 'Unrestricted (system.admin)', 'action-steward' ) ); ?>,
		reasonAdmin: <?php echo wp_json_encode( __( 'system.admin', 'action-steward' ) ); ?>,
		reasonRead: <?php echo wp_json_encode( __( 'supported read actions only', 'action-steward' ) ); ?>,
		reasonScope: <?php echo wp_json_encode( __( 'blocked by read-only scope', 'action-steward' ) ); ?>,
		reasonMiss:  <?php echo wp_json_encode( __( 'missing capability', 'action-steward' ) ); ?>,
		reasonHas:   <?php echo wp_json_encode( __( 'capability assigned', 'action-steward' ) ); ?>,
		auditTitle:  <?php echo wp_json_encode( __( 'Capability audit trail', 'action-steward' ) ); ?>,
		auditEmpty:  <?php echo wp_json_encode( __( 'No capability changes recorded for this token yet.', 'action-steward' ) ); ?>,
		colWhen:     <?php echo wp_json_encode( __( 'When', 'action-steward' ) ); ?>,
		colEvent:    <?php echo wp_json_encode( __( 'Event', 'action-steward' ) ); ?>,
		colCapEvt:   <?php echo wp_json_encode( __( 'Capability', 'action-steward' ) ); ?>,
		colActor:    <?php echo wp_json_encode( __( 'Actor', 'action-steward' ) ); ?>,
		unknownActor:<?php echo wp_json_encode( __( 'unknown', 'action-steward' ) ); ?>,
		manageTitle: <?php echo wp_json_encode( __( 'Manage capabilities', 'action-steward' ) ); ?>,
		manageHelp:  <?php echo wp_json_encode( __( 'Assigning or removing a capability runs through the same audited engine, security mode, and approval gates as the agent API.', 'action-steward' ) ); ?>,
		noAssigned:  <?php echo wp_json_encode( __( 'No capabilities assigned. A read-only-scope token without capabilities can run nothing until one is assigned.', 'action-steward' ) ); ?>,
		addLabel:    <?php echo wp_json_encode( __( 'Add capability', 'action-steward' ) ); ?>,
		assignBtn:   <?php echo wp_json_encode( __( 'Assign', 'action-steward' ) ); ?>,
		removeBtn:   <?php echo wp_json_encode( __( 'Remove', 'action-steward' ) ); ?>,
		allAssigned: <?php echo wp_json_encode( __( 'All assignable capabilities are already granted.', 'action-steward' ) ); ?>,
		adminLocked: <?php echo wp_json_encode( __( 'Capability editing is disabled for this token because system.admin already grants every operation.', 'action-steward' ) ); ?>,
		/* translators: %s: capability name */
		confirmAssign: <?php echo wp_json_encode( /* translators: %s: value */ __( 'Assign the capability "%s" to this token?', 'action-steward' ) ); ?>,
		/* translators: %s: capability name */
		confirmRemove: <?php echo wp_json_encode( /* translators: %s: value */ __( 'Remove the capability "%s" from this token?', 'action-steward' ) ); ?>,
		working:     <?php echo wp_json_encode( __( 'Working…', 'action-steward' ) ); ?>,
		doneReload:  <?php echo wp_json_encode( __( 'Done. Reloading…', 'action-steward' ) ); ?>,
		sentApprove: <?php echo wp_json_encode( __( 'This change needs your approval. It has been sent to Approvals.', 'action-steward' ) ); ?>,
		nonceFail:   <?php echo wp_json_encode( __( 'Your admin session expired. Refresh the page and try again.', 'action-steward' ) ); ?>,
		// Says what happened, that nothing changed, and what to do next. "The
		// change could not be completed." left the customer to guess all three.
		genericFail: <?php echo wp_json_encode( __( 'That did not go through, so nothing has changed. Check your connection and try again — if it keeps happening, look under Settings › Advanced › Diagnostics.', 'action-steward' ) ); ?>,
		createTitle: <?php echo wp_json_encode( __( 'Create a token', 'action-steward' ) ); ?>,
		createHelp:  <?php echo wp_json_encode( __( 'A token is how one assistant reaches this site. You choose what it may do and when it stops working; it is shown once, when you create it.', 'action-steward' ) ); ?>,
		createBtn:   <?php echo wp_json_encode( __( 'Create token', 'action-steward' ) ); ?>,
		creating:    <?php echo wp_json_encode( __( 'Creating…', 'action-steward' ) ); ?>,
		labelReq:    <?php echo wp_json_encode( __( 'Give this token a name first — you will need it to tell your tokens apart later.', 'action-steward' ) ); ?>,
		/* translators: %s: the name the customer gave the token. */
		tokenReady:  <?php echo wp_json_encode( /* translators: %s: value */ __( '“%s” is ready', 'action-steward' ) ); ?>,
		/* translators: %s: the name of an existing active token. */
		dupeWarn:    <?php echo wp_json_encode( /* translators: %s: value */ __( 'You already have an active token called “%s”. You can still create this one, but you will not be able to tell them apart later.', 'action-steward' ) ); ?>,
		colActions:  <?php echo wp_json_encode( __( 'Actions', 'action-steward' ) ); ?>,
		revokeBtn:   <?php echo wp_json_encode( __( 'Revoke', 'action-steward' ) ); ?>,
		deleteBtn:   <?php echo wp_json_encode( __( 'Delete', 'action-steward' ) ); ?>,
		/* translators: %s: token label */
		confirmRevoke: <?php echo wp_json_encode( /* translators: %s: value */ __( 'Revoke the token "%s"? Any assistant using it loses access immediately.', 'action-steward' ) ); ?>,
		/* translators: %s: token label */
		confirmDelete: <?php echo wp_json_encode( /* translators: %s: value */ __( 'Permanently delete the token "%s"? This cannot be undone.', 'action-steward' ) ); ?>,
		prev:          <?php echo wp_json_encode( __( '← Previous', 'action-steward' ) ); ?>,
		next:          <?php echo wp_json_encode( __( 'Next →', 'action-steward' ) ); ?>,
		/* translators: %1$d first row on page, %2$d last row on page, %3$d total */
		pageInfo:      <?php echo wp_json_encode( /* translators: %1$d: number, %2$d: number, %3$d: number */ __( 'Tokens %1$d–%2$d of %3$d', 'action-steward' ) ); ?>
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
		if ( reason === 'read_actions_only' )    { return i18n.reasonRead; }
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
	// Presentation-only filter state. No request changes; the same payload is
	// simply rendered without the dead tokens unless asked for.
	var showRevoked = false;
	var lastTokens  = [];

	function renderTokens( tokens ) {
		lastTokens = tokens;
		var h = '';

		/*
		 * The create CONTROL, not the create form.
		 *
		 * This panel used to render the whole form — label box, scope dropdown,
		 * expiry dropdown and a primary "Create token" button — permanently open
		 * above the token list. The default state of a page whose job is to review
		 * and revoke access was therefore "one click away from minting a new key",
		 * with the scope sitting in a dropdown that explained neither of its two
		 * options. The form now lives in a dialog the customer opens on purpose.
		 */
		h += '<div class="wpcc-cap-manage wpcc-create-token">' +
			'<h2 style="margin-top:0;">' + escHtml( i18n.createTitle ) + '</h2>' +
			'<p class="description">' + escHtml( i18n.createHelp ) + '</p>' +
			'<button type="button" class="button button-primary" id="wpcc-create-token" aria-haspopup="dialog">' + escHtml( i18n.createBtn ) + '</button>' +
			'</div>';

		if ( ! tokens.length ) {
			return h + '<div class="wpcc-empty">' + escHtml( i18n.emptyTokens ) + '</div>';
		}

		// Revoked and expired tokens are history, not access. A site that has been
		// running for a while accumulates them (208 on the test install), and
		// listing them by default buried the two tokens that actually work behind
		// screens of dead ones. They are one checkbox away, never deleted.
		var revokedCount = tokens.filter( function ( t ) { return t.effective_status !== 'active'; } ).length;
		var shown = showRevoked ? tokens : tokens.filter( function ( t ) { return t.effective_status === 'active'; } );

		if ( revokedCount > 0 ) {
			h += '<label class="wpcc-token-filter">' +
				'<input type="checkbox" id="wpcc-show-revoked"' + ( showRevoked ? ' checked' : '' ) + '> ' +
				escHtml( sprintf2( i18n.showRevoked, revokedCount, revokedCount ) ) +
			'</label>';
		}

		if ( ! shown.length ) {
			return h + '<div class="wpcc-empty">' + escHtml( i18n.emptyActive ) + '</div>';
		}

		h += '<table class="widefat striped wpcc-tokens-table"><thead><tr>' +
			'<th>' + escHtml( i18n.colLabel ) + '</th>' +
			'<th>' + escHtml( i18n.colToken ) + '</th>' +
			'<th>' + escHtml( i18n.colScope ) + '</th>' +
			'<th>' + escHtml( i18n.colStatus ) + '</th>' +
			'<th>' + escHtml( i18n.colAccess ) + '</th>' +
			'<th>' + escHtml( i18n.colLastUsed ) + '</th>' +
			'<th>' + escHtml( i18n.colActions ) + '</th></tr></thead><tbody>';
		shown.forEach( function( t ) {
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
	// Revoke/delete reuse doWrite (confirm modal, reloads on success). Create now
	// owns its own dialog below, which is also where the one-time secret is shown
	// — so the banner-in-the-list plumbing that used to carry it is gone.
	// S2.1 — server-side token pagination state.
	var tokPg = { limit: 20, offset: 0, total: 0, returned: 0, hasMore: false };

	// Fetch ONE page of tokens from the server (canonical envelope: items + paging).
	// The UI renders only the returned page — it never loads every token.
	function loadTokensPage() {
		apiFetch( '/tokens?limit=' + tokPg.limit + '&offset=' + tokPg.offset ).then( function( r ) {
			if ( ! r.ok || ! r.body ) { return fail( 'wpcc-tokens-panel' ); }
			tokPg.total    = r.body.total_count || 0;
			tokPg.returned = r.body.returned || ( r.body.items ? r.body.items.length : 0 );
			tokPg.hasMore  = !! r.body.has_more;
			setHtml( 'wpcc-tokens-panel', renderTokens( r.body.items || [] ) + renderTokensPager() );
			bindRevokedToggle();
			wireTokens();
			wireTokensPager();
		} ).catch( function() { fail( 'wpcc-tokens-panel' ); } );
	}

	function bindRevokedToggle() {
		var cb = document.getElementById( 'wpcc-show-revoked' );
		if ( ! cb ) { return; }
		cb.addEventListener( 'change', function () {
			showRevoked = cb.checked;
			setHtml( 'wpcc-tokens-panel', renderTokens( lastTokens ) + renderTokensPager() );
			// Re-bind the row actions and pager: setHtml replaces the panel, so the
			// listeners attached to the previous DOM are gone with it.
			wireTokens();
			wireTokensPager();
			bindRevokedToggle();
		} );
	}

	function renderTokensPager() {
		if ( tokPg.total <= tokPg.limit && tokPg.offset === 0 ) { return ''; }
		var from = tokPg.total ? ( tokPg.offset + 1 ) : 0;
		var to   = tokPg.offset + tokPg.returned;
		var prevDis = tokPg.offset <= 0 ? ' disabled' : '';
		var nextDis = tokPg.hasMore ? '' : ' disabled';
		var info = i18n.pageInfo.replace( '%1$d', from ).replace( '%2$d', to ).replace( '%3$d', tokPg.total );
		return '<div class="wpcc-ops-pager" style="display:flex;align-items:center;gap:10px;margin:12px 0;">'
			+ '<button type="button" class="button" id="wpcc-tok-prev"' + prevDis + '>' + escHtml( i18n.prev ) + '</button>'
			+ '<span class="wpcc-pageinfo" style="font-size:12px;color:#646970;">' + escHtml( info ) + '</span>'
			+ '<button type="button" class="button" id="wpcc-tok-next"' + nextDis + '>' + escHtml( i18n.next ) + '</button>'
			+ '</div>';
	}
	function wireTokensPager() {
		var prev = document.getElementById( 'wpcc-tok-prev' );
		var next = document.getElementById( 'wpcc-tok-next' );
		if ( prev ) { prev.addEventListener( 'click', function() { if ( tokPg.offset > 0 ) { tokPg.offset = Math.max( 0, tokPg.offset - tokPg.limit ); loadTokensPage(); } } ); }
		if ( next ) { next.addEventListener( 'click', function() { if ( tokPg.hasMore ) { tokPg.offset += tokPg.limit; loadTokensPage(); } } ); }
	}
	// ── Create dialog ────────────────────────────────────────────────────────
	// Built once, outside the token list, so re-rendering the list (paging, the
	// revoked filter, a successful create) never rebuilds it and never drops its
	// listeners. The old inline form was re-created on every render, which is why
	// every render also had to re-wire it.
	var dlg = {
		root:    document.getElementById( 'wpcc-tok-dialog' ),
		form:    document.getElementById( 'wpcc-tokdlg-form' ),
		secret:  document.getElementById( 'wpcc-tokdlg-secret' ),
		label:   document.getElementById( 'wpcc-new-label' ),
		expires: document.getElementById( 'wpcc-new-expires' ),
		dupe:    document.getElementById( 'wpcc-tokdlg-dupe' ),
		warn:    document.getElementById( 'wpcc-tokdlg-warn' ),
		longLived: document.getElementById( 'wpcc-tokdlg-longlived' ),
		result:  document.getElementById( 'wpcc-tokdlg-result' ),
		create:  document.getElementById( 'wpcc-tokdlg-create' ),
		cancel:  document.getElementById( 'wpcc-tokdlg-cancel' ),
		value:   document.getElementById( 'wpcc-tokdlg-value' ),
		ready:   document.getElementById( 'wpcc-tokdlg-ready' ),
		copy:    document.getElementById( 'wpcc-tokdlg-copy' ),
		copied:  document.getElementById( 'wpcc-tokdlg-copied' ),
		done:    document.getElementById( 'wpcc-tokdlg-done' )
	};
	var dlgPrev    = null;
	var dlgWorking = false;   // in-flight guard: one create per opening, always.
	var dlgSecret  = null;

	function dlgScope() {
		var el = dlg.root.querySelector( 'input[name="wpcc-new-scope"]:checked' );
		return el ? el.value : 'read_only';
	}
	function dlgSyncScope() {
		// The full-access warning appears only when full access is actually chosen.
		var isFull = dlgScope() === 'full';
		dlg.warn.hidden = ! isFull;
		// The long-lived-credential warning needs BOTH full access and no expiry.
		if ( dlg.longLived ) {
			dlg.longLived.hidden = ! ( isFull && dlg.expires && dlg.expires.value === 'never' );
		}
	}
	function dlgCheckDupe() {
		// Warn, do not block. Two assistants legitimately share a name; what is
		// unsafe is discovering three identical rows later and not knowing which
		// one to revoke. Say it now, while the name can still be changed.
		var v = ( dlg.label.value || '' ).trim().toLowerCase();
		var hit = null;
		if ( v ) {
			for ( var i = 0; i < lastTokens.length; i++ ) {
				var t = lastTokens[ i ];
				if ( t.effective_status === 'active' && String( t.label || '' ).trim().toLowerCase() === v ) { hit = t.label; break; }
			}
		}
		if ( hit ) {
			dlg.dupe.textContent = i18n.dupeWarn.replace( '%s', hit );
			dlg.dupe.hidden = false;
		} else {
			dlg.dupe.hidden = true;
			dlg.dupe.textContent = '';
		}
	}
	function dlgFocusable() {
		return Array.prototype.filter.call(
			dlg.root.querySelectorAll( 'button, input, select, textarea, [href]' ),
			function ( el ) { return ! el.disabled && el.offsetParent !== null; }
		);
	}
	function dlgOpen() {
		dlgPrev    = document.activeElement;
		dlgWorking = false;
		dlgSecret  = null;
		dlg.form.hidden   = false;
		dlg.secret.hidden = true;
		dlg.label.value   = '';
		dlg.expires.value = '30d';
		var ro = dlg.root.querySelector( 'input[name="wpcc-new-scope"][value="read_only"]' );
		if ( ro ) { ro.checked = true; }
		dlg.result.style.display = 'none';
		dlg.result.textContent   = '';
		dlg.copied.hidden = true;
		dlg.create.disabled = false;
		dlg.create.textContent = i18n.createBtn;
		dlgSyncScope();
		dlgCheckDupe();
		dlg.root.style.display = 'flex';
		dlg.label.focus();
	}
	function dlgClose() {
		dlg.root.style.display = 'none';
		if ( dlgPrev && dlgPrev.focus ) { dlgPrev.focus(); }
		// A created token means the list behind the dialog is out of date.
		if ( dlgSecret ) { dlgSecret = null; loadTokensPage(); }
	}
	function dlgResult( cls, text ) {
		dlg.result.className = 'wpcc-cap-result ' + cls;
		dlg.result.textContent = text;
		dlg.result.style.display = 'block';
	}
	function dlgSubmit() {
		if ( dlgWorking ) { return; }

		var label = ( dlg.label.value || '' ).trim();
		if ( ! label ) { dlgResult( 'error', i18n.labelReq ); dlg.label.focus(); return; }

		dlgWorking = true;
		dlg.create.disabled = true;
		dlg.create.textContent = i18n.creating;
		dlgResult( 'info', i18n.working );

		apiFetch( '/tokens', {
			method: 'POST',
			body: JSON.stringify( { label: label, scope: dlgScope(), expires: dlg.expires.value } )
		} ).then( function ( r ) {
			if ( r.status === 403 ) { dlgFail( i18n.nonceFail ); return; }
			var body = r.body || {};
			if ( body.success && body.token ) {
				dlgSecret = body.token;
				dlg.value.value = body.token;
				dlg.ready.textContent = i18n.tokenReady.replace( '%s', label );
				dlg.form.hidden   = true;
				dlg.secret.hidden = false;
				dlg.copy.focus();
				return;
			}
			var msg = ( body.errors && body.errors[0] && body.errors[0].message ) ? body.errors[0].message : i18n.genericFail;
			dlgFail( msg );
		} ).catch( function () { dlgFail( i18n.genericFail ); } );
	}
	function dlgFail( msg ) {
		// Re-arm on failure only. A success never re-arms, because the dialog has
		// moved on to showing a secret that cannot be shown twice.
		dlgWorking = false;
		dlg.create.disabled = false;
		dlg.create.textContent = i18n.createBtn;
		dlgResult( 'error', msg );
	}
	function dlgCopy() {
		var text = dlg.value.value;
		function ok() { dlg.copied.hidden = false; }
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( ok ).catch( function () { dlg.value.select(); } );
		} else {
			dlg.value.select();
			try { document.execCommand( 'copy' ); ok(); } catch ( e ) {}
		}
	}

	if ( dlg.root ) {
		dlg.create.addEventListener( 'click', dlgSubmit );
		dlg.cancel.addEventListener( 'click', dlgClose );
		dlg.done.addEventListener( 'click', dlgClose );
		dlg.copy.addEventListener( 'click', dlgCopy );
		dlg.label.addEventListener( 'input', dlgCheckDupe );
		dlg.expires.addEventListener( 'change', dlgSyncScope );
		dlg.value.addEventListener( 'focus', function () { dlg.value.select(); } );
		Array.prototype.forEach.call( dlg.root.querySelectorAll( 'input[name="wpcc-new-scope"]' ), function ( r ) {
			r.addEventListener( 'change', dlgSyncScope );
		} );
		// Enter in the name field means "create", not "do nothing".
		dlg.label.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' ) { e.preventDefault(); dlgSubmit(); }
		} );
		dlg.root.addEventListener( 'keydown', function ( e ) {
			if ( dlg.root.style.display === 'none' ) { return; }
			if ( e.key === 'Escape' ) { e.preventDefault(); dlgClose(); return; }
			if ( e.key !== 'Tab' ) { return; }
			var f = dlgFocusable();
			if ( ! f.length ) { return; }
			var first = f[0], last = f[ f.length - 1 ];
			if ( e.shiftKey && document.activeElement === first ) { e.preventDefault(); last.focus(); }
			else if ( ! e.shiftKey && document.activeElement === last ) { e.preventDefault(); first.focus(); }
		} );
		// Backdrop cancels — but never while a secret the customer has not copied
		// is on screen, because that secret cannot be recovered.
		dlg.root.addEventListener( 'click', function ( e ) {
			if ( e.target === dlg.root && ! dlgSecret ) { dlgClose(); }
		} );
	}

	function wireTokens() {
		var createBtn = document.getElementById( 'wpcc-create-token' );
		if ( createBtn ) { createBtn.addEventListener( 'click', dlgOpen ); }
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
			tokPg.offset = 0;
			loadTokensPage();
		}
	}

	if ( state.viewId ) { loadDetail(); } else { loadPanel(); }
})();
</script>
