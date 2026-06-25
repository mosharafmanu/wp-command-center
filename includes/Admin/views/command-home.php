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
	'approvals'       => admin_url( 'admin.php?page=wpcc-activity&wpcc_tab=approvals' ),
	'approvals_queue' => admin_url( 'admin.php?page=wpcc-activity&wpcc_tab=approvals&tab=queue' ),
	'operations'      => admin_url( 'admin.php?page=wpcc-settings&wpcc_tab=capabilities' ),
	'tokens'          => admin_url( 'admin.php?page=wpcc-settings&wpcc_tab=access' ),
	'change_history'  => admin_url( 'admin.php?page=wpcc-history&wpcc_tab=changes&tab=sessions' ),
	'connect'         => admin_url( 'admin.php?page=wpcc-connect&wpcc_tab=clients' ),
];

// Per-session deep link into the Change History timeline, hosted under History.
$session_base = admin_url( 'admin.php?page=wpcc-history&wpcc_tab=changes&tab=timeline' );

// PROGRAM-5A — first-run / adoption-readiness panel (server-rendered, no REST).
// Dismiss is per-user and only honored when setup is complete (incomplete setup
// keeps the panel visible so a new partner is never left without guidance).
if ( isset( $_POST['wpcc_firstrun_action'] ) && check_admin_referer( 'wpcc_firstrun' ) && current_user_can( 'manage_options' ) ) {
	$wpcc_fr_action = sanitize_key( wp_unslash( $_POST['wpcc_firstrun_action'] ) );
	if ( 'dismiss' === $wpcc_fr_action ) {
		update_user_meta( get_current_user_id(), 'wpcc_firstrun_dismissed', '1' );
	} elseif ( 'reopen' === $wpcc_fr_action ) {
		delete_user_meta( get_current_user_id(), 'wpcc_firstrun_dismissed' );
	}
}

$wpcc_checklist     = \WPCommandCenter\Admin\AdoptionStatus::checklist();
$wpcc_incomplete    = \WPCommandCenter\Admin\AdoptionStatus::setup_incomplete();
$wpcc_fr_dismissed  = '1' === get_user_meta( get_current_user_id(), 'wpcc_firstrun_dismissed', true );
$wpcc_show_firstrun = $wpcc_incomplete || ! $wpcc_fr_dismissed;
$wpcc_done_count    = count( array_filter( $wpcc_checklist, static fn ( $s ) => $s['done'] ) );
$wpcc_total_count   = count( $wpcc_checklist );
?>
<div class="wpcc-home">
	<p class="description">
		<?php esc_html_e( 'Mission control for AI on your WordPress site — what needs you, what changed, and what you can undo. Read-only: every card links to the surface that owns the detail.', 'wp-command-center' ); ?>
	</p>

	<?php if ( $wpcc_show_firstrun ) : ?>
		<section class="wpcc-firstrun" aria-labelledby="wpcc-firstrun-h" style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1;border-radius:6px;padding:18px 20px;margin:0 0 20px;max-width:840px;">
			<div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap;">
				<h2 id="wpcc-firstrun-h" style="margin:0;font-size:16px;"><?php esc_html_e( 'Set up WP Command Center', 'wp-command-center' ); ?></h2>
				<span style="color:#646970;font-size:13px;">
					<?php
					/* translators: 1: completed steps, 2: total steps */
					printf( esc_html__( '%1$d of %2$d ready', 'wp-command-center' ), (int) $wpcc_done_count, (int) $wpcc_total_count );
					?>
				</span>
			</div>
			<p style="margin:6px 0 14px;color:#50575e;font-size:13px;max-width:640px;">
				<?php esc_html_e( 'A quick checklist to use WPCC safely. AI is optional and stays off until you add a key — nothing here turns AI on or changes your security mode automatically.', 'wp-command-center' ); ?>
			</p>

			<?php
			// "Three Doors" onboarding fork (UX Master Blueprint §3.1). One question,
			// three self-contained paths — choosing one orders the journey; it never
			// hides the others. Pure navigation: no state is written.
			$wpcc_doors = [
				[
					'title' => __( 'Use WPCC’s built-in AI', 'wp-command-center' ),
					'desc'  => __( 'Generate SEO, alt text and content with your own provider key.', 'wp-command-center' ),
					'cta'   => __( 'Set up Built-in AI', 'wp-command-center' ),
					'url'   => admin_url( 'admin.php?page=wpcc-built-in-ai&wpcc_tab=providers' ),
					'icon'  => 'dashicons-superhero',
				],
				[
					'title' => __( 'Connect my AI assistant', 'wp-command-center' ),
					'desc'  => __( 'Let Claude, Cursor, Codex and others act here, under approval.', 'wp-command-center' ),
					'cta'   => __( 'Connect a client', 'wp-command-center' ),
					'url'   => admin_url( 'admin.php?page=wpcc-connect&wpcc_tab=clients' ),
					'icon'  => 'dashicons-admin-plugins',
				],
				[
					'title' => __( 'Connect an app or service', 'wp-command-center' ),
					'desc'  => __( 'Drive this site from your own software over a governed REST API.', 'wp-command-center' ),
					'cta'   => __( 'Set up the API', 'wp-command-center' ),
					'url'   => admin_url( 'admin.php?page=wpcc-connect&wpcc_tab=api' ),
					'icon'  => 'dashicons-rest-api',
				],
			];
			?>
			<div class="wpcc-doorfork" role="group" aria-label="<?php esc_attr_e( 'How do you want to use AI here?', 'wp-command-center' ); ?>" style="margin:0 0 18px;">
				<p style="margin:0 0 10px;font-weight:600;font-size:14px;"><?php esc_html_e( 'How do you want to use AI here?', 'wp-command-center' ); ?></p>
				<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;">
					<?php foreach ( $wpcc_doors as $wpcc_door ) : ?>
						<div style="display:flex;flex-direction:column;gap:8px;padding:14px;background:#fff;border:1px solid #dcdcde;border-radius:6px;">
							<span class="dashicons <?php echo esc_attr( $wpcc_door['icon'] ); ?>" aria-hidden="true" style="font-size:22px;width:22px;height:22px;color:#2271b1;"></span>
							<strong style="font-size:13px;"><?php echo esc_html( $wpcc_door['title'] ); ?></strong>
							<span style="flex:1;color:#646970;font-size:12px;line-height:1.5;"><?php echo esc_html( $wpcc_door['desc'] ); ?></span>
							<a class="button button-secondary button-small" style="align-self:flex-start;" href="<?php echo esc_url( $wpcc_door['url'] ); ?>"><?php echo esc_html( $wpcc_door['cta'] ); ?></a>
						</div>
					<?php endforeach; ?>
				</div>
				<p style="margin:10px 0 0;color:#646970;font-size:12px;"><?php esc_html_e( 'You can do all three later — pick where to start. Whatever you choose, every change waits for your approval and can be undone.', 'wp-command-center' ); ?></p>
			</div>

			<div style="margin:0 0 16px;padding:18px 20px;background:linear-gradient(135deg,#f2fbf5,#eaf7ef);border:1px solid #b6e3c5;border-left:4px solid #00a32a;border-radius:8px;display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;">
				<span style="display:flex;gap:12px;align-items:center;">
					<span class="dashicons dashicons-chart-area" aria-hidden="true" style="font-size:26px;width:26px;height:26px;color:#00a32a;"></span>
					<span style="font-size:14px;color:#1d2327;">
						<strong style="font-size:15px;"><?php esc_html_e( 'Start here: see it work in 2 minutes — no AI or setup needed.', 'wp-command-center' ); ?></strong>
						<span style="display:block;color:#50575e;font-size:13px;margin-top:3px;"><?php esc_html_e( 'Run a read-only health check on this site for an instant win. Nothing is changed — it only reads.', 'wp-command-center' ); ?></span>
					</span>
				</span>
				<a class="button button-primary button-hero" href="<?php echo esc_url( admin_url( 'admin.php?page=wpcc-settings&wpcc_tab=diagnostics' ) ); ?>"><?php esc_html_e( 'Run a site report', 'wp-command-center' ); ?></a>
			</div>

			<ol style="list-style:none;margin:0;padding:0;display:grid;gap:10px;">
				<?php foreach ( $wpcc_checklist as $step ) : ?>
					<li style="display:flex;gap:12px;align-items:flex-start;padding:10px 12px;background:<?php echo $step['done'] ? '#f6fbf7' : '#f6f7f7'; ?>;border-radius:4px;">
						<span aria-hidden="true" style="font-size:16px;line-height:1.4;color:<?php echo $step['done'] ? '#00a32a' : '#c3c4c7'; ?>;"><?php echo $step['done'] ? '&#10003;' : '&#9711;'; ?></span>
						<span style="flex:1;">
							<a href="<?php echo esc_url( $step['url'] ); ?>" style="font-weight:600;text-decoration:none;"><?php echo esc_html( $step['label'] ); ?></a>
							<span class="screen-reader-text"><?php echo $step['done'] ? esc_html__( '(done)', 'wp-command-center' ) : esc_html__( '(to do)', 'wp-command-center' ); ?></span>
							<span style="display:block;color:#646970;font-size:12px;margin-top:2px;"><?php echo esc_html( $step['hint'] ); ?></span>
						</span>
					</li>
				<?php endforeach; ?>
			</ol>

			<div style="margin-top:14px;padding:12px 14px;background:#f0f6fc;border-radius:4px;">
				<strong style="font-size:13px;display:block;margin-bottom:8px;"><?php esc_html_e( 'How WPCC keeps you in control', 'wp-command-center' ); ?></strong>
				<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;font-size:12px;color:#50575e;">
					<div><strong>1. <?php esc_html_e( 'AI proposes', 'wp-command-center' ); ?></strong><br><?php esc_html_e( 'It suggests a change — nothing happens to your site yet.', 'wp-command-center' ); ?></div>
					<div><strong>2. <?php esc_html_e( 'You approve', 'wp-command-center' ); ?></strong><br><?php esc_html_e( 'In Client mode, every change waits for your OK.', 'wp-command-center' ); ?> <a href="<?php echo esc_url( $links['approvals'] ); ?>"><?php esc_html_e( 'Approvals →', 'wp-command-center' ); ?></a></div>
					<div><strong>3. <?php esc_html_e( 'It is recorded', 'wp-command-center' ); ?></strong><br><?php esc_html_e( 'Every change is logged with who did it and when.', 'wp-command-center' ); ?></div>
					<div><strong>4. <?php esc_html_e( 'You can undo', 'wp-command-center' ); ?></strong><br><?php esc_html_e( 'Reversible changes have a one-click Restore.', 'wp-command-center' ); ?> <a href="<?php echo esc_url( $links['change_history'] ); ?>"><?php esc_html_e( 'Changes →', 'wp-command-center' ); ?></a></div>
				</div>
			</div>

			<details style="margin-top:14px;border-top:1px solid #f0f0f1;padding-top:12px;">
				<summary style="cursor:pointer;font-weight:600;font-size:13px;"><?php esc_html_e( 'What WP Command Center does — and what it doesn\'t', 'wp-command-center' ); ?></summary>
				<div style="margin-top:10px;color:#50575e;font-size:13px;max-width:640px;display:grid;gap:8px;">
					<p style="margin:0;"><strong><?php esc_html_e( 'What it does:', 'wp-command-center' ); ?></strong> <?php esc_html_e( 'Lets an AI agent operate this WordPress site under your control — with capability limits, an approval step, a full audit trail, and one-click undo for supported changes.', 'wp-command-center' ); ?></p>
					<p style="margin:0;"><strong><?php esc_html_e( 'AI is optional and off by default.', 'wp-command-center' ); ?></strong> <?php esc_html_e( 'No AI runs until you add a provider key and enable a feature. Adding a key here never turns features on by itself.', 'wp-command-center' ); ?></p>
					<p style="margin:0;"><strong><?php esc_html_e( 'Approval protects client sites.', 'wp-command-center' ); ?></strong> <?php esc_html_e( 'In Client or Enterprise mode, write operations wait for your review before they apply.', 'wp-command-center' ); ?></p>
					<p style="margin:0;"><strong><?php esc_html_e( 'Undo lives in History → Changes.', 'wp-command-center' ); ?></strong> <?php esc_html_e( 'Content, SEO meta, media metadata, settings, comments, users and several other surfaces record a reversible change you can restore.', 'wp-command-center' ); ?></p>
					<p style="margin:0;"><strong><?php esc_html_e( 'Honest limits:', 'wp-command-center' ); ?></strong> <?php esc_html_e( 'Not everything is reversible. Plugin and theme updates are NOT automatically undoable, and some surfaces (e.g. WooCommerce orders) have no rollback. WPCC tells you when a change cannot be undone — it does not promise undo everywhere.', 'wp-command-center' ); ?></p>
					<p style="margin:0;"><strong><?php esc_html_e( 'It is not a backup tool or a fleet manager.', 'wp-command-center' ); ?></strong> <?php esc_html_e( 'It governs individual actions on this one site; it does not take full-site backups or manage many sites at once.', 'wp-command-center' ); ?></p>
				</div>
			</details>

			<?php if ( ! $wpcc_incomplete ) : ?>
				<form method="post" style="margin:14px 0 0;">
					<?php wp_nonce_field( 'wpcc_firstrun' ); ?>
					<button type="submit" name="wpcc_firstrun_action" value="dismiss" class="button button-small"><?php esc_html_e( 'Hide this guide', 'wp-command-center' ); ?></button>
					<span style="margin-left:8px;color:#646970;font-size:12px;"><?php esc_html_e( 'You can reopen it from here any time setup changes.', 'wp-command-center' ); ?></span>
				</form>
			<?php endif; ?>
		</section>
	<?php elseif ( $wpcc_fr_dismissed ) : ?>
		<p style="margin:0 0 16px;">
			<form method="post" style="display:inline;">
				<?php wp_nonce_field( 'wpcc_firstrun' ); ?>
				<button type="submit" name="wpcc_firstrun_action" value="reopen" class="button-link" style="font-size:13px;"><?php esc_html_e( 'Show setup guide', 'wp-command-center' ); ?></button>
			</form>
		</p>
	<?php endif; ?>

	<div id="wpcc-home-readiness" hidden></div>

	<h2 class="screen-reader-text"><?php esc_html_e( 'Needs attention', 'wp-command-center' ); ?></h2>
	<div id="wpcc-home-attn" role="status" aria-live="polite">
		<div class="wpcc-cds-loading"><span class="spinner is-active" style="float:none;margin:0"></span><?php esc_html_e( 'Loading…', 'wp-command-center' ); ?></div>
	</div>

	<div class="wpcc-engineer-only">
		<h2><?php esc_html_e( 'Platform invariants', 'wp-command-center' ); ?></h2>
		<div id="wpcc-home-invariants" class="wpcc-cds-kpis" role="status" aria-live="polite"></div>
	</div>

	<h2><?php esc_html_e( 'At a glance', 'wp-command-center' ); ?></h2>
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
		cardApprovals: <?php echo wp_json_encode( __( 'Approvals', 'wp-command-center' ) ); ?>,
		apPending:  <?php echo wp_json_encode( __( 'Pending', 'wp-command-center' ) ); ?>,
		apCritical: <?php echo wp_json_encode( __( 'Pending critical', 'wp-command-center' ) ); ?>,
		apResolved: <?php echo wp_json_encode( __( 'Resolved', 'wp-command-center' ) ); ?>,
		apFailed:   <?php echo wp_json_encode( __( 'Queue failed', 'wp-command-center' ) ); ?>,
		cardOps:    <?php echo wp_json_encode( __( 'Capabilities', 'wp-command-center' ) ); ?>,
		opTotal:    <?php echo wp_json_encode( __( 'Total', 'wp-command-center' ) ); ?>,
		opAvailable:<?php echo wp_json_encode( __( 'Available', 'wp-command-center' ) ); ?>,
		opApproval: <?php echo wp_json_encode( __( 'Need approval', 'wp-command-center' ) ); ?>,
		riskLabel:  <?php echo wp_json_encode( __( 'Risk', 'wp-command-center' ) ); ?>,
		cardTokens: <?php echo wp_json_encode( __( 'Access', 'wp-command-center' ) ); ?>,
		tkTokens:   <?php echo wp_json_encode( __( 'Tokens', 'wp-command-center' ) ); ?>,
		tkCaps:     <?php echo wp_json_encode( __( 'Capabilities', 'wp-command-center' ) ); ?>,
		cardHistory:<?php echo wp_json_encode( __( 'History', 'wp-command-center' ) ); ?>,
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
