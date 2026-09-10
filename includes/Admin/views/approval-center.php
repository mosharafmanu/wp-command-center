<?php
/**
 * STEP 106 — Approval Center.
 *
 * Tabs: Pending (approve/reject + destructive escalation) / History (resolved
 * lifecycle, paginated) / Queue (read + retry). Per-request detail panel with
 * change-set, shared-DiffRenderer diff, queue/result and audit trail. All API
 * output is escaped; the only innerHTML injection is the server-escaped diff.
 * Accessible (role=dialog modal with focus trap, role=status live regions,
 * aria-current tabs) and fully localized.
 */

defined( 'ABSPATH' ) || exit;

$human_only    = \WPCommandCenter\Operations\SecurityModeManager::requires_human_approver();
$nonce         = wp_create_nonce( 'wp_rest' );
$api_base      = rest_url( 'wp-command-center/v1/admin' );

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab nav, no state change.
$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'pending';
$tabs       = [ 'pending', 'history', 'queue' ];
if ( ! in_array( $active_tab, $tabs, true ) ) {
	$active_tab = 'pending';
}
$base_url = admin_url( 'admin.php?page=wpcc-activity&wpcc_tab=approvals' );

// STEP 106.2 — single-request detail view (?view=<request_id>). UUID-shaped only.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only detail nav, no state change.
$detail_id = isset( $_GET['view'] ) ? sanitize_text_field( wp_unslash( $_GET['view'] ) ) : '';
if ( ! preg_match( '/^[a-f0-9-]{36}$/', $detail_id ) ) {
	$detail_id = '';
}
?>
<div class="wrap wpcc-wrap">
	<h1><?php esc_html_e( 'Approvals', 'ai-command-center' ); ?>
		<span id="wpcc-pending-badge" style="display:none;margin-left:8px;background:var(--wpcc-red-600);color:var(--wpcc-white);font-size:12px;border-radius:10px;padding:2px 8px;vertical-align:middle;"></span>
	</h1>

	<?php
	// Removed: a restatement of the security mode (the header pill already shows
	// it, live) and a sentence that just listed this page's own tabs. What is left
	// is the one fact a customer cannot see from the UI itself — that a token
	// cannot wave its own request through.
	?>
	<?php if ( $human_only ) : ?>
		<p class="description">
			<span class="dashicons dashicons-lock" style="color:var(--wpcc-gray-600);" aria-hidden="true"></span>
			<?php esc_html_e( 'Only you can approve these. An AI assistant cannot approve its own request.', 'ai-command-center' ); ?>
		</p>
	<?php endif; ?>

	<?php
	// Queue-level context belongs to the queue. On a single-request detail screen
	// the pending/critical/failed counts and the Recommendations pointer are about
	// OTHER work — they pushed the change being reviewed below the fold and invited
	// the reader away mid-decision. Shown on the list only.
	?>
	<?php if ( '' === $detail_id ) : ?>
	<div id="wpcc-approval-summary" class="wpcc-summary-bar" aria-live="polite"></div>
	<?php endif; ?>

	<?php
	// Phase 2A — pointer to Recommendations when suggested-fix plans await approval.
	// Real data only (no duplicate UI); the plans are approved in their recommendation
	// context. Shown only when there is at least one pending plan.
	global $wpdb;
	$wpcc_pending_plan_cnt = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcc_agent_plans WHERE status = %s", 'pending_review' ) );
	if ( '' === $detail_id && $wpcc_pending_plan_cnt > 0 ) :
		?>
		<p class="wpcc-approvals-recsignal" style="margin:0 0 14px;padding:10px 14px;background:#f0f6fc;border-left:3px solid #2271b1;border-radius:0 4px 4px 0;max-width:760px;font-size:13px;">
			<span class="dashicons dashicons-lightbulb" aria-hidden="true" style="color:#2271b1;"></span>
			<?php
			printf(
				/* translators: %d: number of suggested fixes awaiting approval */
				esc_html( _n( '%d suggested fix is awaiting your review.', '%d suggested fixes are awaiting your review.', $wpcc_pending_plan_cnt, 'ai-command-center' ) ),
				(int) $wpcc_pending_plan_cnt
			);
			?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpcc-settings&wpcc_tab=advanced&apane=diagnostics&dpane=recommendations' ) ); ?>"><?php esc_html_e( 'Review in Recommendations →', 'ai-command-center' ); ?></a>
		</p>
		<?php
	endif;
	?>

	<?php if ( '' !== $detail_id ) : ?>
	<?php // Back to the pending queue — where the reader came from, not the Decided tab. ?>
	<p><a href="<?php echo esc_url( $base_url . '&tab=pending' ); ?>" class="wpcc-detail-back">&larr; <?php esc_html_e( 'Back to Approvals', 'ai-command-center' ); ?></a></p>
	<div id="wpcc-detail" data-id="<?php echo esc_attr( $detail_id ); ?>">
		<p><span class="spinner is-active" style="float:none;margin:0 6px 0 0;vertical-align:middle;"></span><?php esc_html_e( 'Loading…', 'ai-command-center' ); ?></p>
	</div>
	<?php else : ?>

	<h2 class="nav-tab-wrapper">
		<a href="<?php echo esc_url( $base_url . '&tab=pending' ); ?>" class="nav-tab <?php echo 'pending' === $active_tab ? 'nav-tab-active' : ''; ?>"<?php echo 'pending' === $active_tab ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'Pending', 'ai-command-center' ); ?></a>
		<a href="<?php echo esc_url( $base_url . '&tab=history' ); ?>" class="nav-tab <?php echo 'history' === $active_tab ? 'nav-tab-active' : ''; ?>"<?php echo 'history' === $active_tab ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'Decided', 'ai-command-center' ); ?></a>
		<a href="<?php echo esc_url( $base_url . '&tab=queue' ); ?>" class="nav-tab <?php echo 'queue' === $active_tab ? 'nav-tab-active' : ''; ?>"<?php echo 'queue' === $active_tab ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'Execution', 'ai-command-center' ); ?></a>
	</h2>

	<div id="wpcc-tab-pending" class="wpcc-tab" style="<?php echo 'pending' === $active_tab ? '' : 'display:none;'; ?>">
		<div id="wpcc-approvals-list">
			<p><span class="spinner is-active" style="float:none;margin:0 6px 0 0;vertical-align:middle;"></span><?php esc_html_e( 'Loading…', 'ai-command-center' ); ?></p>
		</div>
	</div>

	<div id="wpcc-tab-history" class="wpcc-tab" style="<?php echo 'history' === $active_tab ? '' : 'display:none;'; ?>">
		<div id="wpcc-history-list">
			<p><span class="spinner is-active" style="float:none;margin:0 6px 0 0;vertical-align:middle;"></span><?php esc_html_e( 'Loading…', 'ai-command-center' ); ?></p>
		</div>
	</div>

	<div id="wpcc-tab-queue" class="wpcc-tab" style="<?php echo 'queue' === $active_tab ? '' : 'display:none;'; ?>">
		<div id="wpcc-queue-list">
			<p><span class="spinner is-active" style="float:none;margin:0 6px 0 0;vertical-align:middle;"></span><?php esc_html_e( 'Loading…', 'ai-command-center' ); ?></p>
		</div>
	</div>
	<?php endif; ?>

	<div id="wpcc-confirm-modal" class="wpcc-modal-backdrop" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="wpcc-confirm-title" aria-describedby="wpcc-confirm-warning">
		<div class="wpcc-modal">
			<h2 id="wpcc-confirm-title"><?php esc_html_e( 'Confirm destructive approval', 'ai-command-center' ); ?></h2>
			<div id="wpcc-confirm-warning" class="wpcc-card-destructive" role="alert"></div>
			<p>
				<label for="wpcc-confirm-phrase"><?php esc_html_e( 'Type the confirmation phrase to proceed:', 'ai-command-center' ); ?> <code id="wpcc-confirm-phrase-hint"></code></label><br>
				<input type="text" id="wpcc-confirm-phrase" class="regular-text" autocomplete="off">
			</p>
			<p>
				<label for="wpcc-confirm-reason"><?php esc_html_e( 'Reason (required):', 'ai-command-center' ); ?></label><br>
				<textarea id="wpcc-confirm-reason" rows="2" class="large-text"></textarea>
			</p>
			<p>
				<button type="button" class="button button-primary wpcc-reject-btn" id="wpcc-confirm-go" disabled><?php esc_html_e( 'Approve &amp; Run', 'ai-command-center' ); ?></button>
				<button type="button" class="button" id="wpcc-confirm-cancel"><?php esc_html_e( 'Cancel', 'ai-command-center' ); ?></button>
			</p>
		</div>
	</div>
</div>

<style>
/* CDS Scope 2 — risk/state/structural colors are token-driven (wpcc-tokens.css,
 * enqueued before this view). Risk tiers use the CDS risk semantic (diagnostic=
 * green · low=teal · medium=amber · high=orange · critical=red); this is the
 * intended reconciliation of the former ad-hoc approval palette. The dark
 * code/diff surface (#1e1e1e / #e6e6e6 / #7ee787 / #ff9492) is deliberately kept
 * literal — no dark-surface token exists and remapping would hurt diff legibility. */
.wpcc-summary-bar { margin:12px 0; display:flex; gap:10px; flex-wrap:wrap; }
.wpcc-summary-chip { background:var(--wpcc-white); border:1px solid var(--wpcc-gray-100); border-radius:4px; padding:8px 14px; font-size:13px; min-width:90px; }
.wpcc-summary-chip strong { display:block; font-size:20px; line-height:1.2; }
.wpcc-summary-chip.alert strong { color:var(--wpcc-state-danger-fg); }
/* ── Review list (PROGRAM-7D) ───────────────────────────────────────────────
 * One row per decision. Risk is carried by a 3px left edge so the eye can sort
 * the queue by severity before reading a word; actions stay quiet until the row
 * is hovered or focused so that a page of pending items has ONE visual weight
 * instead of a hundred competing primary buttons. Keyboard users get the same
 * reveal via :focus-within, and the actions remain in the DOM (never
 * display:none) so they stay reachable by tab and by screen readers.
 * ─────────────────────────────────────────────────────────────────────────── */
.wpcc-apr-bar { display:flex; align-items:center; gap:16px; flex-wrap:wrap; padding:0 0 10px; border-bottom:1px solid var(--wpcc-border-subtle); margin-bottom:2px; }
.wpcc-apr-all { font-size:13px; color:var(--wpcc-text-secondary); display:inline-flex; align-items:center; gap:6px; }
.wpcc-apr-count { font-size:12px; color:var(--wpcc-text-secondary); }
.wpcc-apr-more { color:var(--wpcc-text-secondary); opacity:.85; }
.wpcc-apr-bulk { margin-left:auto; display:inline-flex; align-items:center; gap:8px; }
/* An explicit `display` outranks the user-agent rule for [hidden], so the bulk
 * bar stayed visible reading "0 selected". Restore the intent. */
.wpcc-apr-bulk[hidden] { display:none; }
.wpcc-apr-selcount { font-size:12px; font-weight:600; color:var(--wpcc-text-primary); }

.wpcc-apr-list { list-style:none; margin:0; padding:0; border:1px solid var(--wpcc-border-subtle); border-radius:8px; overflow:hidden; background:var(--wpcc-surface-card); }
.wpcc-apr-row { position:relative; display:flex; align-items:center; gap:12px; padding:10px 14px 10px 11px; border-bottom:1px solid var(--wpcc-border-subtle); border-left:3px solid transparent; transition:background-color .12s ease; }
.wpcc-apr-row:last-child { border-bottom:0; }
.wpcc-apr-row:hover { background:var(--wpcc-surface-sunken); }
.wpcc-apr-row.risk-critical { border-left-color:var(--wpcc-risk-critical-fg); }
.wpcc-apr-row.risk-high     { border-left-color:var(--wpcc-risk-high-fg); }
.wpcc-apr-row.risk-medium   { border-left-color:var(--wpcc-risk-medium-fg); }
.wpcc-apr-row.risk-low      { border-left-color:var(--wpcc-risk-low-fg); }
.wpcc-apr-row.risk-diagnostic { border-left-color:var(--wpcc-risk-diagnostic-fg); }

.wpcc-apr-pick { display:flex; align-items:center; }
.wpcc-apr-main { flex:1; min-width:0; }
/*
 * The row title has always been a link to the full request, but it was painted
 * exactly like static text — same colour as the sub-line, no underline — and
 * only revealed itself on hover. So on a queue of eighty rows the only two
 * things that LOOKED actionable were Approve and Reject, and the one action
 * that lets a customer find out what they are approving was invisible. The
 * layout is untouched; the link now looks like a link.
 */
.wpcc-apr-title { display:inline-block; font-size:13.5px; font-weight:600; color:var(--wpcc-text-accent); text-decoration:none; line-height:1.4; }
.wpcc-apr-title:hover,
.wpcc-apr-title:focus { text-decoration:underline; }
/* Review sits with the other actions, so it costs no resting visual weight and
   appears at the moment the customer engages with a row — and it comes FIRST,
   because reading a request is what should precede deciding on it. */
.wpcc-apr-review { align-self:center; white-space:nowrap; }
/* Completion card — the "what now?" that a finished change deserves.
   Token-driven (CDS Scope 2): success colour comes from the design system, not
   from a literal, so it stays in step with every other success surface. */
.wpcc-detail-done {
	background: var( --wpcc-surface-success-soft, var( --wpcc-surface-sunken ) );
	border: 1px solid var( --wpcc-border-success, var( --wpcc-border-subtle ) );
	border-left: 3px solid var( --wpcc-risk-low-fg, var( --wpcc-text-accent ) );
}
.wpcc-detail-done__lead { margin:0 0 4px; font-size:14px; font-weight:600; color: var( --wpcc-text-primary ); }
.wpcc-detail-done__note { margin:0 0 12px; font-size:13px; color: var( --wpcc-text-secondary ); }
.wpcc-detail-done__actions { margin:0; display:flex; gap:8px; flex-wrap:wrap; }
.wpcc-apr-preview { display:inline-block; margin-left:8px; font-size:13px; color:var(--wpcc-text-secondary); }
.wpcc-apr-warn { display:inline-block; margin-left:8px; font-size:11px; font-weight:600; color:var(--wpcc-risk-critical-fg); }
.wpcc-apr-sub { display:block; margin-top:2px; font-size:12px; color:var(--wpcc-text-secondary); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.wpcc-apr-sub code { font-size:11px; }
.wpcc-apr-dot { margin:0 6px; opacity:.5; }

/*
 * Approve and Reject are always visible.
 *
 * Hiding them until hover is a density trick borrowed from mail clients, where
 * rows are disposable. Here the row IS the decision — the entire reason the
 * screen exists — and a customer arriving on it saw a list with no way to act.
 * They stay quiet until you approach (muted, then full strength on hover) so a
 * long queue does not turn into a wall of buttons, but they are never absent.
 */
.wpcc-apr-actions { display:flex; gap:6px; opacity:.72; transition:opacity .12s ease; }
.wpcc-apr-row:hover .wpcc-apr-actions,
.wpcc-apr-row:focus-within .wpcc-apr-actions { opacity:1; }
@media (hover:none) { .wpcc-apr-actions { opacity:1; } }

/* Decision bar on the detail screen: the action sits with the evidence. */
.wpcc-detail-decide { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin:0 0 18px; padding:14px 16px; border:1px solid var(--wpcc-border-subtle); border-radius:8px; background:var(--wpcc-surface-sunken); }
.wpcc-detail-decide__note { font-size:12px; color:var(--wpcc-text-secondary); }
.wpcc-detail-decide .wpcc-card-result { flex-basis:100%; }
.wpcc-detail-raw { margin:0 0 18px; }
.wpcc-detail-back { display:inline-block; margin:0 0 12px; font-size:13px; text-decoration:none; }
.wpcc-detail-back:hover { text-decoration:underline; }
.wpcc-detail-raw > summary { cursor:pointer; font-size:13px; font-weight:600; color:var(--wpcc-text-secondary); padding:6px 0; }
.wpcc-detail-raw[open] > summary { color:var(--wpcc-text-primary); margin-bottom:8px; }

.wpcc-apr-pager { display:flex; align-items:center; justify-content:center; gap:12px; margin-top:14px; font-size:12px; color:var(--wpcc-text-secondary); }

.wpcc-apr-clear { text-align:center; padding:48px 24px; border:1px solid var(--wpcc-border-subtle); border-radius:8px; background:var(--wpcc-surface-card); }
.wpcc-apr-clear__mark { display:inline-flex; align-items:center; justify-content:center; width:40px; height:40px; border-radius:999px; background:var(--wpcc-state-success-bg); color:var(--wpcc-state-success-fg); font-size:20px; margin-bottom:12px; }
.wpcc-apr-clear__title { margin:0 0 4px; font-size:15px; font-weight:600; color:var(--wpcc-text-primary); }
.wpcc-apr-clear__detail { margin:0 auto; max-width:46ch; font-size:13px; color:var(--wpcc-text-secondary); }

.wpcc-approval-card { background:var(--wpcc-white); border:1px solid var(--wpcc-gray-100); border-left:4px solid var(--wpcc-gray-500); border-radius:4px; padding:16px 20px; margin:12px 0; max-width:820px; box-shadow:0 1px 3px rgba(0,0,0,.06); }
.wpcc-approval-card.risk-critical { border-left-color:var(--wpcc-risk-critical-fg); }
.wpcc-approval-card.risk-high { border-left-color:var(--wpcc-risk-high-fg); }
.wpcc-approval-card.risk-medium { border-left-color:var(--wpcc-risk-medium-fg); }
.wpcc-approval-card.risk-low { border-left-color:var(--wpcc-risk-low-fg); }
.wpcc-approval-card.risk-diagnostic { border-left-color:var(--wpcc-risk-diagnostic-fg); }
/*
 * Risk badges are a reading aid, not a siren.
 *
 * These filled the badge with the tier's FOREGROUND colour, producing a solid
 * dark-red block reading "HIGH RISK" next to sentences like "Change a WordPress
 * setting — Tagline". Changing a tagline is high risk in the engine's sense (a
 * settings write can break a site) and that is worth knowing — but rendered as an
 * alarm it teaches the customer either to fear routine edits or to stop reading
 * the badge. Neither is what a governance product wants.
 *
 * Tinted background, tier-coloured text, a small dot for scanning. Critical alone
 * keeps the solid fill, because that is the one where alarm is the correct answer.
 */
.wpcc-risk-badge {
	display:inline-flex; align-items:center; gap:6px;
	font-size:11px; font-weight:650; padding:3px 9px; border-radius:999px;
	text-transform:uppercase; letter-spacing:.02em; white-space:nowrap;
}
.wpcc-risk-badge::before { content:""; width:6px; height:6px; border-radius:50%; background:currentColor; flex:0 0 auto; }
.wpcc-risk-badge.risk-critical { background:var(--wpcc-risk-critical-fg); color:var(--wpcc-white); }
.wpcc-risk-badge.risk-critical::before { background:var(--wpcc-white); }
.wpcc-risk-badge.risk-high { background:var(--wpcc-risk-high-bg); color:var(--wpcc-risk-high-fg); }
.wpcc-risk-badge.risk-medium { background:var(--wpcc-risk-medium-bg); color:var(--wpcc-risk-medium-fg); }
.wpcc-risk-badge.risk-low { background:var(--wpcc-risk-low-bg); color:var(--wpcc-risk-low-fg); }
.wpcc-risk-badge.risk-diagnostic { background:var(--wpcc-risk-diagnostic-bg); color:var(--wpcc-risk-diagnostic-fg); }
.wpcc-card-header { display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:10px; }
.wpcc-card-title { font-size:15px;font-weight:600;margin:0; }
.wpcc-card-meta { font-size:13px;color:var(--wpcc-gray-700);margin:6px 0; }
.wpcc-card-meta td { padding:3px 12px 3px 0;vertical-align:top; }
.wpcc-card-meta td:first-child { font-weight:500;white-space:nowrap; }
.wpcc-card-reason { background:var(--wpcc-gray-0);border-left:3px solid var(--wpcc-gray-100);padding:8px 12px;margin:10px 0;font-size:13px;color:var(--wpcc-gray-700);font-style:italic; }
.wpcc-card-area { display:inline-block;margin:2px 0 6px;padding:1px 8px;border-radius:10px;background:var(--wpcc-gray-100,#f0f0f1);color:var(--wpcc-text-secondary,#50575e);font-size:11px;font-weight:600; }
.wpcc-card-actions { margin-top:14px; }
.wpcc-approve-btn { background:var(--wpcc-green-600);color:var(--wpcc-white);border-color:var(--wpcc-green-600); }
.wpcc-approve-btn:hover { background:var(--wpcc-green-700);border-color:var(--wpcc-green-700);color:var(--wpcc-white); }
.wpcc-reject-btn { background:var(--wpcc-red-600);color:var(--wpcc-white);border-color:var(--wpcc-red-600); }
.wpcc-reject-btn:hover { background:var(--wpcc-red-700);border-color:var(--wpcc-red-700);color:var(--wpcc-white); }
.wpcc-card-result { margin-top:10px;padding:8px 12px;border-radius:3px;font-size:13px; }
.wpcc-card-result.success { background:var(--wpcc-state-success-bg);border:1px solid var(--wpcc-green-600);color:var(--wpcc-gray-900); }
.wpcc-card-result.error { background:var(--wpcc-state-danger-bg);border:1px solid var(--wpcc-state-danger-fg);color:var(--wpcc-gray-900); }
.wpcc-card-destructive { background:var(--wpcc-state-danger-bg);border:1px solid var(--wpcc-state-danger-fg);color:var(--wpcc-red-700);border-radius:3px;padding:8px 12px;margin:8px 0 10px;font-size:13px;font-weight:600; }
.wpcc-status-pill { display:inline-block;font-size:11px;font-weight:600;padding:2px 8px;border-radius:3px;text-transform:uppercase;background:var(--wpcc-gray-50);color:var(--wpcc-gray-700); }
.wpcc-status-pill.executed,.wpcc-status-pill.completed,.wpcc-status-pill.approved { background:var(--wpcc-state-success-bg);color:var(--wpcc-state-success-fg); }
.wpcc-status-pill.failed,.wpcc-status-pill.rejected { background:var(--wpcc-state-danger-bg);color:var(--wpcc-red-700); }
.wpcc-status-pill.cancelled { background:var(--wpcc-gray-50);color:var(--wpcc-gray-600); }
.wpcc-status-pill.running,.wpcc-status-pill.queued { background:var(--wpcc-state-info-bg);color:var(--wpcc-state-info-fg); }
.wpcc-loadmore { margin:12px 0; }
.wpcc-detail-section { background:var(--wpcc-white);border:1px solid var(--wpcc-gray-100);border-radius:4px;padding:14px 18px;margin:12px 0;max-width:980px; }
.wpcc-detail-section h2 { font-size:14px;margin:0 0 10px;padding:0; }
.wpcc-detail-meta td { padding:4px 14px 4px 0;font-size:13px;vertical-align:top; }
.wpcc-detail-meta td:first-child { font-weight:600;white-space:nowrap;color:var(--wpcc-gray-900); }
.wpcc-detail-head { display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:6px; }
.wpcc-detail-head .wpcc-detail-title { font-size:18px;font-weight:600; }
.wpcc-changeset { background:var(--wpcc-gray-0);border:1px solid var(--wpcc-gray-100);border-radius:4px;padding:10px 14px;font-size:13px; }
.wpcc-changeset .hi { color:var(--wpcc-red-700);font-weight:600; }
.wpcc-apr-progress { font-size:12px; color:var(--wpcc-text-secondary,#50575e); }
.wpcc-apr-progress[hidden] { display:none; }
.wpcc-result-link { margin-left:8px; white-space:nowrap; font-weight:600; }
.wpcc-retry-error { display:block; margin-top:6px; font-size:12px; color:var(--wpcc-state-danger-fg); }
.wpcc-whatchanges { width:100%; border-collapse:collapse; }
.wpcc-whatchanges th { text-align:left; width:190px; padding:8px 12px 8px 0; vertical-align:top; font-weight:600; color:var(--wpcc-text-secondary,#50575e); border-bottom:1px solid var(--wpcc-border-subtle,#dcdcde); }
.wpcc-whatchanges td { padding:8px 0; vertical-align:top; color:var(--wpcc-text-primary,#1d2327); border-bottom:1px solid var(--wpcc-border-subtle,#dcdcde); word-break:break-word; }
.wpcc-whatchanges tr:last-child th, .wpcc-whatchanges tr:last-child td { border-bottom:0; }
.wpcc-payload pre { background:#1e1e1e;color:#e6e6e6;padding:12px 14px;border-radius:4px;overflow:auto;max-height:340px;font-size:12px;line-height:1.5; }
.wpcc-audit-trail { list-style:none;margin:0;padding:0; }
.wpcc-audit-trail li { padding:6px 0;border-top:1px solid var(--wpcc-gray-50);font-size:13px;display:flex;gap:10px;flex-wrap:wrap; }
.wpcc-audit-trail li:first-child { border-top:none; }
.wpcc-audit-when { color:var(--wpcc-gray-600);min-width:120px; }
.wpcc-audit-action { font-weight:600;color:var(--wpcc-gray-900); }
.wpcc-audit-actor { color:var(--wpcc-gray-700); }
.wpcc-detail-link { font-size:12px;text-decoration:none; }
.wpcc-diff-summary { background:var(--wpcc-gray-0);border:1px solid var(--wpcc-gray-100);border-radius:4px;padding:10px 14px;margin:10px 0;max-width:980px;font-size:13px; }
.wpcc-diff-stat { font-weight:600;margin-right:10px; }
.wpcc-diff-add { color:var(--wpcc-green-700); }
.wpcc-diff-del { color:var(--wpcc-red-700); }
.wpcc-diff-filelist { margin:8px 0 0;padding-left:0;list-style:none; }
.wpcc-diff-filelist li { font-size:12px;padding:2px 0; }
.wpcc-diff-file { max-width:980px;margin:8px 0;border:1px solid var(--wpcc-gray-100);border-radius:4px;background:var(--wpcc-white); }
.wpcc-diff-file > summary { cursor:pointer;padding:8px 12px;font-size:13px;user-select:none; }
.wpcc-diff { background:#1e1e1e;color:#e6e6e6;padding:12px 14px;margin:0;border-radius:0 0 4px 4px;overflow:auto;font-size:12px;line-height:1.5; }
.wpcc-diff .wpcc-diff-add { color:#7ee787; }
.wpcc-diff .wpcc-diff-del { color:#ff9492; }
.wpcc-diff-truncated { color:var(--wpcc-gray-600);font-style:italic; }
.wpcc-modal-backdrop { position:fixed;inset:0;background:var(--wpcc-overlay-scrim);z-index:100000;display:flex;align-items:center;justify-content:center; }
.wpcc-modal { background:var(--wpcc-white);border-radius:6px;padding:20px 24px;max-width:520px;width:92%;box-shadow:0 6px 28px rgba(0,0,0,.3); }
.wpcc-modal h2 { margin-top:0;font-size:16px; }
.wpcc-retry-btn { font-size:12px; }
</style>

<script>
(function() {
	var nonce      = <?php echo wp_json_encode( $nonce ); ?>;
	var apiBase    = <?php echo wp_json_encode( $api_base ); ?>;
	var activeTab  = <?php echo wp_json_encode( $active_tab ); ?>;
	var detailId   = <?php echo wp_json_encode( $detail_id ); ?>;
	var baseUrl    = <?php echo wp_json_encode( $base_url ); ?>;

	var i18n = {
		readOnly:    <?php echo wp_json_encode( __( 'Read Only', 'ai-command-center' ) ); ?>,
		review:      <?php echo wp_json_encode( __( 'Review', 'ai-command-center' ) ); ?>,
		approve:     <?php echo wp_json_encode( __( 'Approve', 'ai-command-center' ) ); ?>,
		reject:      <?php echo wp_json_encode( __( 'Reject', 'ai-command-center' ) ); ?>,
		approved:    <?php echo wp_json_encode( __( 'Done — your site has been updated.', 'ai-command-center' ) ); ?>,
		approvedLink:<?php echo wp_json_encode( __( 'See it in Changes', 'ai-command-center' ) ); ?>,
		/*
		 * The end of the journey, on the one page that had none.
		 *
		 * After a change ran, the detail page showed a queue table, an execution
		 * result and an audit trail — a technical record, with nothing to do next.
		 * The customer had just changed their live site and was left on a log.
		 */
		doneTitle:   <?php echo wp_json_encode( __( 'This change has been applied to your site.', 'ai-command-center' ) ); ?>,
		doneUndo:    <?php echo wp_json_encode( __( 'It is recorded and can be undone from Changes.', 'ai-command-center' ) ); ?>,
		doneBackAi:  <?php echo wp_json_encode( __( 'Back to Built-in AI', 'ai-command-center' ) ); ?>,
		builtinUrl:  <?php echo wp_json_encode( admin_url( 'admin.php?page=wpcc-settings&wpcc_tab=advanced&apane=ai' ) ); ?>,
		<?php
		/*
		 * Offer the Built-in AI route only when Built-in AI actually exists here.
		 *
		 * This button rendered on every executed request. Most requests do not come
		 * from Built-in AI at all — an assistant's write over MCP and an Undo
		 * started from the Changes screen both landed on "Back to Built-in AI" —
		 * and on a stock install, where no generation tool is switched on, it sent
		 * the customer to a screen that only offers to turn the feature on. A
		 * "continue" action should lead somewhere they were.
		 *
		 * builtin_tabs() returns Providers alone until a tool is enabled, which is
		 * the same test settings-ai.php uses for its own "tools are off" notice.
		 * See it in Changes / Review other approvals are always right, so the
		 * continuation never ends up empty.
		 */
		?>
		hasBuiltinAi:<?php echo wp_json_encode( count( \WPCommandCenter\Admin\AppShell::builtin_tabs() ) > 1 ); ?>,
		approvalsUrl:<?php echo wp_json_encode( admin_url( 'admin.php?page=wpcc-activity&wpcc_tab=approvals' ) ); ?>,
		doneMore:    <?php echo wp_json_encode( __( 'Review other approvals', 'ai-command-center' ) ); ?>,
		changesUrl:  <?php echo wp_json_encode( admin_url( 'admin.php?page=wpcc-history' ) ); ?>,
		rejected:    <?php echo wp_json_encode( __( 'Rejected.', 'ai-command-center' ) ); ?>,
		/*
		 * Four things a failure has to say: what happened, whether the site
		 * changed, what to do, and where the detail is. "Approved but execution
		 * failed: <engine string>" answered only the first, and answered it in the
		 * engine's words.
		 *
		 * This case is precise and worth stating precisely: the DECISION was
		 * recorded, and the change did NOT run — so the site is untouched. That is
		 * the fact the customer is actually anxious about.
		 */
		approvedErr: <?php echo wp_json_encode( __( 'You approved this, but it could not run — so nothing on your site has changed. It stays in Approvals; you can try it again from the Execution tab.', 'ai-command-center' ) ); ?>,
		unknownErr:  <?php echo wp_json_encode( __( 'Unknown error.', 'ai-command-center' ) ); ?>,
		/*
		 * This one fires from .catch() — the request never completed, so we
		 * genuinely do not know whether the server recorded the decision. Saying
		 * "Request failed. Please try again." invites a second approval of
		 * something that may already be approved. Say what is actually known.
		 */
		reqFailed:   <?php echo wp_json_encode( __( 'Could not reach your site, so this decision may not have been saved. Reload the page to see where the request stands before deciding again.', 'ai-command-center' ) ); ?>,
		destructive: <?php echo wp_json_encode( __( 'DESTRUCTIVE — this permanently deletes data and cannot be undone.', 'ai-command-center' ) ); ?>,
		auditNote:   <?php echo wp_json_encode( __( 'This action will be logged in the audit trail.', 'ai-command-center' ) ); ?>,
		noPending:   <?php echo wp_json_encode( __( 'Nothing is waiting for you. Requests that need approval appear here for your decision.', 'ai-command-center' ) ); ?>,
		clearTitle:  <?php echo wp_json_encode( __( 'Nothing is waiting for you', 'ai-command-center' ) ); ?>,
		<?php // Mode-aware: on a Development site nothing will ever appear here to approve. ?>
		clearDetail: <?php echo wp_json_encode( \WPCommandCenter\Operations\SecurityModeManager::approvals_empty_detail() ); ?>,
		selectAll:   <?php echo wp_json_encode( __( 'Select all on this page', 'ai-command-center' ) ); ?>,
		selectOne:   <?php echo wp_json_encode( __( 'Select this request', 'ai-command-center' ) ); ?>,
		/* translators: 1: range such as "1–25", 2: total count */
		showingRange: <?php echo wp_json_encode( /* translators: %1$s: value, %2$s: value */ __( 'Showing %1$s of %2$s', 'ai-command-center' ) ); ?>,
		/* translators: 1: current page, 2: total pages */
		pageOf:      <?php echo wp_json_encode( /* translators: %1$s: value, %2$s: value */ __( 'Page %1$s of %2$s', 'ai-command-center' ) ); ?>,
		prev:        <?php echo wp_json_encode( __( 'Previous', 'ai-command-center' ) ); ?>,
		next:        <?php echo wp_json_encode( __( 'Next', 'ai-command-center' ) ); ?>,
		/* translators: %d: number of selected requests */
		nSelected:   <?php echo wp_json_encode( /* translators: %d: number */ __( '%d selected', 'ai-command-center' ) ); ?>,
		approveSelected: <?php echo wp_json_encode( __( 'Approve selected', 'ai-command-center' ) ); ?>,
		rejectSelected:  <?php echo wp_json_encode( __( 'Reject selected', 'ai-command-center' ) ); ?>,
		/* translators: %d: number of requests */
		confirmBulkApprove: <?php echo wp_json_encode( /* translators: %d: number */ __( 'Approve %d requests? Each one runs through the same checks as approving it individually. Requests that permanently delete something are skipped and must be approved one at a time.', 'ai-command-center' ) ); ?>,
		/* translators: %d: number of requests */
		confirmBulkReject:  <?php echo wp_json_encode( /* translators: %d: number */ __( 'Reject %d requests?', 'ai-command-center' ) ); ?>,
		bulkReason:  <?php echo wp_json_encode( __( 'Rejected in bulk by administrator.', 'ai-command-center' ) ); ?>,
		/* translators: %d: total number of pending requests */
		moreQueued:  <?php echo wp_json_encode( /* translators: %d: number */ __( '(%d pending in total)', 'ai-command-center' ) ); ?>,
		noHistory:   <?php echo wp_json_encode( __( 'Nothing decided yet. Once you approve or reject something, it is kept here as a record.', 'ai-command-center' ) ); ?>,
		noQueue:     <?php echo wp_json_encode( __( 'The execution queue is empty.', 'ai-command-center' ) ); ?>,
		// Reading, not writing: nothing was attempted, so nothing changed. The
		// wording matches Changes and Access tokens, which already say this well.
		loadFailed:  <?php echo wp_json_encode( __( 'Could not load this list — nothing has changed on your site. Your admin session may have expired; reload the page to try again.', 'ai-command-center' ) ); ?>,
		loadMore:    <?php echo wp_json_encode( __( 'Load more', 'ai-command-center' ) ); ?>,
		colOp:       <?php echo wp_json_encode( __( 'Operation', 'ai-command-center' ) ); ?>,
		colAction:   <?php echo wp_json_encode( __( 'Action', 'ai-command-center' ) ); ?>,
		colRisk:     <?php echo wp_json_encode( __( 'Risk', 'ai-command-center' ) ); ?>,
		colStatus:   <?php echo wp_json_encode( __( 'Status', 'ai-command-center' ) ); ?>,
		colResolved: <?php echo wp_json_encode( __( 'Resolved by', 'ai-command-center' ) ); ?>,
		colWhen:     <?php echo wp_json_encode( __( 'Requested', 'ai-command-center' ) ); ?>,
		colQueueId:  <?php echo wp_json_encode( __( 'Queue item', 'ai-command-center' ) ); ?>,
		colAttempts: <?php echo wp_json_encode( __( 'Attempts', 'ai-command-center' ) ); ?>,
		colError:    <?php echo wp_json_encode( __( 'Error', 'ai-command-center' ) ); ?>,
		unavailable: <?php echo wp_json_encode( __( 'unavailable', 'ai-command-center' ) ); ?>,
		chipPending: <?php echo wp_json_encode( __( 'Pending', 'ai-command-center' ) ); ?>,
		chipCrit:    <?php echo wp_json_encode( __( 'Critical pending', 'ai-command-center' ) ); ?>,
		chipResolved:<?php echo wp_json_encode( __( 'Resolved (all-time)', 'ai-command-center' ) ); ?>,
		chipFailed:  <?php echo wp_json_encode( __( 'Failed in queue', 'ai-command-center' ) ); ?>,
		details:     <?php echo wp_json_encode( __( 'Details', 'ai-command-center' ) ); ?>,
		secReason:   <?php echo wp_json_encode( __( 'Reason', 'ai-command-center' ) ); ?>,
		secRequest:  <?php echo wp_json_encode( __( 'Request', 'ai-command-center' ) ); ?>,
		secChangeset:<?php echo wp_json_encode( __( 'Change set', 'ai-command-center' ) ); ?>,
		secDiff:     <?php echo wp_json_encode( __( 'Diff', 'ai-command-center' ) ); ?>,
		secWhatChanges: <?php echo wp_json_encode( __( 'What will change', 'ai-command-center' ) ); ?>,
		// Past tense once the decision is made — on a request that has already run,
		// "What will change" describes a future that has happened.
		secWhatChanged: <?php echo wp_json_encode( __( 'What changed', 'ai-command-center' ) ); ?>,
		secWhatWouldHave: <?php echo wp_json_encode( __( 'What this would have changed', 'ai-command-center' ) ); ?>,
		lblUndoes:      <?php echo wp_json_encode( __( 'Undoes', 'ai-command-center' ) ); ?>,
		undoUnknown:    <?php echo wp_json_encode( __( 'A change that is no longer in your history', 'ai-command-center' ) ); ?>,
		lblChangeId:    <?php echo wp_json_encode( __( 'Change id', 'ai-command-center' ) ); ?>,
		secPayload:  <?php echo wp_json_encode( __( 'Technical details', 'ai-command-center' ) ); ?>,
		yes:         <?php echo wp_json_encode( __( 'Yes', 'ai-command-center' ) ); ?>,
		no:          <?php echo wp_json_encode( __( 'No', 'ai-command-center' ) ); ?>,
		emptyValue:  <?php echo wp_json_encode( __( '(empty)', 'ai-command-center' ) ); ?>,
		/*
		 * Field names a site owner recognises, for the "What will change" table.
		 *
		 * The setting keys come from ActionLabels::option_labels(), which is the same
		 * map that names the setting in this request's own heading. They used to be
		 * duplicated here and had drifted, so a settings approval said "Site title"
		 * at the top of the page and `site_title` in the table below it. The rows
		 * added afterwards are fields only this table renders (post, user, comment
		 * and media fields), so they stay local; anything a heading also has to name
		 * belongs in the shared map.
		 */
		fieldNames:  <?php echo wp_json_encode( array_merge( \WPCommandCenter\Admin\ActionLabels::option_labels(), [
			'post_title'      => __( 'Title', 'ai-command-center' ),
			'post_content'    => __( 'Content', 'ai-command-center' ),
			'post_status'     => __( 'Status', 'ai-command-center' ),
			'post_excerpt'    => __( 'Excerpt', 'ai-command-center' ),
			'post_id'         => __( 'Item', 'ai-command-center' ),
			'option_id'       => __( 'Setting', 'ai-command-center' ),
			'user_id'         => __( 'User', 'ai-command-center' ),
			'comment_id'      => __( 'Comment', 'ai-command-center' ),
			'display_name'    => __( 'Display name', 'ai-command-center' ),
			'user_email'      => __( 'Email address', 'ai-command-center' ),
			'role'            => __( 'Role', 'ai-command-center' ),
			'slug'            => __( 'Slug', 'ai-command-center' ),
			'value'           => __( 'New value', 'ai-command-center' ),
			'ids'             => __( 'Items', 'ai-command-center' ),
			'alt_text'        => __( 'Alt text', 'ai-command-center' ),
			'caption'         => __( 'Caption', 'ai-command-center' ),
			'start_url'       => __( 'Site address', 'ai-command-center' ),
		] ) ); ?>,
		/* Audit events, in words. Unknown ids fall back to their tidied last segment. */
		auditNames:  <?php echo wp_json_encode( [
			'operation.approval.auto_requested' => __( 'Sent for your approval', 'ai-command-center' ),
			'operation.approval.approved'       => __( 'Approved', 'ai-command-center' ),
			'operation.approval.rejected'       => __( 'Rejected', 'ai-command-center' ),
			'operation.approval.cancelled'      => __( 'Cancelled', 'ai-command-center' ),
			'operation.queued'                  => __( 'Added to the queue', 'ai-command-center' ),
			'operation.executed'                => __( 'Carried out', 'ai-command-center' ),
			'operation.failed'                  => __( 'Failed to run', 'ai-command-center' ),
		] ); ?>,
		secQueue:    <?php echo wp_json_encode( __( 'Queue', 'ai-command-center' ) ); ?>,
		secResults:  <?php echo wp_json_encode( __( 'Execution result', 'ai-command-center' ) ); ?>,
		secAudit:    <?php echo wp_json_encode( __( 'Audit trail', 'ai-command-center' ) ); ?>,
		lblResolved: <?php echo wp_json_encode( __( 'Resolved by', 'ai-command-center' ) ); ?>,
		lblArea:     <?php echo wp_json_encode( __( 'Area', 'ai-command-center' ) ); ?>,
		lblRequested:<?php echo wp_json_encode( __( 'Requested', 'ai-command-center' ) ); ?>,
		lblApproved: <?php echo wp_json_encode( __( 'Approved', 'ai-command-center' ) ); ?>,
		lblRejected: <?php echo wp_json_encode( __( 'Rejected', 'ai-command-center' ) ); ?>,
		lblExecuted: <?php echo wp_json_encode( __( 'Executed', 'ai-command-center' ) ); ?>,
		lblFailedAt: <?php echo wp_json_encode( __( 'Failed', 'ai-command-center' ) ); ?>,
		lblCancelled:<?php echo wp_json_encode( __( 'Cancelled', 'ai-command-center' ) ); ?>,
		lblFiles:    <?php echo wp_json_encode( __( 'files', 'ai-command-center' ) ); ?>,
		lblHighRisk: <?php echo wp_json_encode( __( 'HIGH-RISK paths included', 'ai-command-center' ) ); ?>,
		diffUnavail: <?php echo wp_json_encode( __( 'No diff stored for this request.', 'ai-command-center' ) ); ?>,
		noResult:    <?php echo wp_json_encode( __( 'This has not run yet.', 'ai-command-center' ) ); ?>,
		noAudit:     <?php echo wp_json_encode( __( 'No audit events recorded for this request.', 'ai-command-center' ) ); ?>,
		notFound:    <?php echo wp_json_encode( __( 'Request not found.', 'ai-command-center' ) ); ?>,
		counts:      <?php echo wp_json_encode( /* translators: %1$s: value, %2$s: value, %3$s: value, %4$s: value */ __( 'Created %1$s · Updated %2$s · Skipped %3$s · Errors %4$s', 'ai-command-center' ) ); ?>,
		retry:       <?php echo wp_json_encode( __( 'Retry', 'ai-command-center' ) ); ?>,
		retrying:    <?php echo wp_json_encode( __( 'Retrying…', 'ai-command-center' ) ); ?>,
		retryConfirm:<?php echo wp_json_encode( __( 'Re-queue this failed item for another attempt?', 'ai-command-center' ) ); ?>,
		retryYes:    <?php echo wp_json_encode( __( 'Try again', 'ai-command-center' ) ); ?>,
		cancel:      <?php echo wp_json_encode( __( 'Cancel', 'ai-command-center' ) ); ?>,
		/* translators: 1: current item number, 2: total items */
		bulkProgress: <?php echo wp_json_encode( /* translators: %1$s: value, %2$s: value */ __( 'Working — %1$s of %2$s', 'ai-command-center' ) ); ?>,
		bulkApproveBody: <?php echo wp_json_encode( __( 'Each one runs through the engine exactly as if you had approved it on its own. Anything needing its own destructive confirmation is skipped.', 'ai-command-center' ) ); ?>,
		bulkRejectBody:  <?php echo wp_json_encode( __( 'Nothing will run. The requests are recorded as rejected and your site is left unchanged.', 'ai-command-center' ) ); ?>,
		retryFailed: <?php echo wp_json_encode( __( 'Retry failed: ', 'ai-command-center' ) ); ?>,
		nonceExpired:<?php echo wp_json_encode( __( 'Your session expired. Please reload the page and try again.', 'ai-command-center' ) ); ?>,
		detailsFor:  <?php echo wp_json_encode( __( 'View details for this request', 'ai-command-center' ) ); ?>
	};

	var riskLabels = {
		critical:   <?php echo wp_json_encode( __( 'Critical', 'ai-command-center' ) ); ?>,
		high:       <?php echo wp_json_encode( __( 'High Risk', 'ai-command-center' ) ); ?>,
		medium:     <?php echo wp_json_encode( __( 'Medium Risk', 'ai-command-center' ) ); ?>,
		low:        <?php echo wp_json_encode( __( 'Low Risk', 'ai-command-center' ) ); ?>,
		diagnostic: i18n.readOnly
	};
	/*
	 * TWO state machines, one screen — and they used to share one vocabulary.
	 *
	 * A REQUEST moves pending_review → approved → executed. Its QUEUE ITEM moves
	 * queued → running → completed. Both were rendered from a single map, so one
	 * change showed the customer "Executed" in one place and "Completed" in
	 * another, and a superseded retry attempt showed "Cancelled" beside a change
	 * that had in fact been applied. Three words for one outcome, one of which
	 * reads as "your change did not happen" when it did.
	 *
	 * The engine states are untouched and still shown verbatim in Detailed. What
	 * changes is that the customer-facing words describe the OUTCOME, and the same
	 * reality gets the same word: an executed request and its completed queue item
	 * both read "Applied".
	 *
	 * `cancelled` genuinely means different things in the two machines — a request
	 * the customer stopped, versus an attempt the engine superseded — so it is the
	 * one label that must never be shared. That is exactly why these are two maps.
	 */
	var statusLabels = {
		pending_review: <?php echo wp_json_encode( __( 'Waiting for you', 'ai-command-center' ) ); ?>,
		approved:       <?php echo wp_json_encode( __( 'Approved', 'ai-command-center' ) ); ?>,
		rejected:       <?php echo wp_json_encode( __( 'Rejected', 'ai-command-center' ) ); ?>,
		executed:       <?php echo wp_json_encode( __( 'Applied', 'ai-command-center' ) ); ?>,
		failed:         <?php echo wp_json_encode( __( 'Did not run', 'ai-command-center' ) ); ?>,
		cancelled:      <?php echo wp_json_encode( __( 'Cancelled', 'ai-command-center' ) ); ?>,
		queued:         <?php echo wp_json_encode( __( 'Waiting to run', 'ai-command-center' ) ); ?>,
		running:        <?php echo wp_json_encode( __( 'Running', 'ai-command-center' ) ); ?>,
		completed:      <?php echo wp_json_encode( __( 'Applied', 'ai-command-center' ) ); ?>
	};
	// Queue-item overrides: same tokens, different meaning inside the queue.
	var queueStatusLabels = {
		completed:      <?php echo wp_json_encode( __( 'Applied', 'ai-command-center' ) ); ?>,
		queued:         <?php echo wp_json_encode( __( 'Waiting to run', 'ai-command-center' ) ); ?>,
		running:        <?php echo wp_json_encode( __( 'Running now', 'ai-command-center' ) ); ?>,
		failed:         <?php echo wp_json_encode( __( 'This attempt did not run', 'ai-command-center' ) ); ?>,
		// NOT "Cancelled": the change itself may well have been applied by another
		// attempt. This says what happened to the attempt, and nothing more.
		cancelled:      <?php echo wp_json_encode( __( 'Attempt stopped', 'ai-command-center' ) ); ?>
	};
	function statusLabel( s, kind ) {
		if ( 'queue' === kind && queueStatusLabels[ s ] ) { return queueStatusLabels[ s ]; }
		return statusLabels[ s ] || s;
	}

	function apiFetch( path, opts ) {
		opts = opts || {};
		return fetch( apiBase + path, Object.assign( {
			headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' }
		}, opts ) ).then( function(r) {
			var status = r.status;
			return r.json().catch( function() { return {}; } ).then( function(j) {
				if ( j && typeof j === 'object' ) { j._status = status; }
				return j;
			} );
		} );
	}
	// Friendly message for an expired cookie nonce (403) on a write action.
	function actionError( data ) {
		if ( data && data._status === 403 ) { return i18n.nonceExpired; }
		return ( data && data.error ) || i18n.unknownErr;
	}

	// Server-built plain-language dictionary (ActionLabels::dictionary), shared with
	// History so the same operation reads the same way everywhere in the product.
	var WPCC_LABELS = <?php echo wp_json_encode( \WPCommandCenter\Admin\ActionLabels::dictionary() ); ?>;

	function escHtml( s ) {
		var d = document.createElement('div');
		d.appendChild( document.createTextNode( String( s == null ? '' : s ) ) );
		return d.innerHTML;
	}

	// Minimal printf for the localized strings above (%d / %s and positional).
	function fmt1( tpl, a ) { return String( tpl ).replace( '%d', a ).replace( '%s', a ); }
	function fmt2( tpl, a, b ) {
		return String( tpl )
			.replace( '%1$s', a ).replace( '%2$s', b )
			.replace( '%1$d', a ).replace( '%2$d', b );
	}

	function whenAgo( ts ) {
		if ( ! ts ) { return '—'; }
		var secs = Math.max( 1, Math.floor( Date.now() / 1000 ) - ts );
		if ( secs < 60 ) { return secs + 's ago'; }
		if ( secs < 3600 ) { return Math.floor( secs / 60 ) + 'm ago'; }
		if ( secs < 86400 ) { return Math.floor( secs / 3600 ) + 'h ago'; }
		return Math.floor( secs / 86400 ) + 'd ago';
	}

	// ── Summary ──
	function loadSummary() {
		apiFetch( '/approvals/summary' ).then( function( data ) {
			if ( ! data.summary ) { return; }
			var el = document.getElementById('wpcc-approval-summary');
			var s = data.summary;

			/*
			 * The counters come first, and unconditionally.
			 *
			 * This used to return early when #wpcc-approval-summary was absent — and it
			 * is absent on exactly one screen: the single-request detail view, which
			 * deliberately drops the queue-level chips. So on the one page where a
			 * decision is most often made, the toolbar counter was never told the
			 * request had been resolved: approve or reject the last pending item and
			 * the admin bar went on advertising "Approvals 1" until the next
			 * navigation. The missing chip container is a layout fact; it says nothing
			 * about whether the badges are on the page.
			 */
			updateBadge( s.pending );

			if ( ! el ) { return; }

			// Only counts that change what the user does next. "Resolved (all-time)"
			// was a vanity number: it never requires an action and it gave a
			// lifetime total equal visual weight to the four critical items that
			// actually need a decision. Critical and Failed appear only when
			// non-zero, so a healthy queue shows one calm number instead of four.
			summaryPending = parseInt( s.pending, 10 ) || 0;
			if ( pendingAll.length && summaryPending > pendingAll.length && pendingTotal !== summaryPending ) {
				pendingTotal = summaryPending;
				renderPendingPage();
			}
			/*
			 * The summary earns its place only when it says something the list does
			 * not.
			 *
			 * A "1 Pending" tile sitting above "Showing 1–1 of 1" is the same fact
			 * twice, in two type sizes — and it made a quiet queue look instrumented.
			 * A count helps when there is a queue worth measuring, or when something
			 * needs prioritising (a critical item, a failed run). Below that, the
			 * list is already the answer and the tiles are just furniture.
			 */
			var summaryEarnsSpace = ( s.pending >= 4 || s.pending_critical > 0 || s.queue_failed > 0 );
			el.innerHTML = summaryEarnsSpace
				? (
					chip( i18n.chipPending, s.pending, false ) +
					( s.pending_critical > 0 ? chip( i18n.chipCrit, s.pending_critical, true ) : '' ) +
					( s.queue_failed > 0 ? chip( i18n.chipFailed, s.queue_failed, true ) : '' )
				)
				: '';
		} ).catch( function() {} );
	}
	function chip( label, value, alert ) {
		return '<div class="wpcc-summary-chip' + ( alert ? ' alert' : '' ) + '"><strong>' + escHtml( value ) + '</strong>' + escHtml( label ) + '</div>';
	}
	function updateBadge( count ) {
		count = parseInt( count, 10 ) || 0;
		var badge = document.getElementById('wpcc-pending-badge');
		if ( badge ) {
			if ( count > 0 ) { badge.textContent = count; badge.style.display = 'inline'; }
			else { badge.style.display = 'none'; }
		}
		/*
		 * The toolbar counter is rendered by PHP at page load (AdminMenu::admin_bar_badge)
		 * and then never told anything again, so approving the last pending request left
		 * the admin bar insisting one was still waiting until the next navigation — the
		 * screen disagreeing with itself about the one number it exists to report.
		 *
		 * Correct it from the same authoritative summary the in-page badge uses. Only
		 * downward: the node does not exist when the page loaded with nothing pending,
		 * and inventing one here would mean re-implementing its markup, its icon and its
		 * capability check in JavaScript. Going stale-high is the failure that misleads;
		 * a newly-arrived request appears on the next page load as it always has.
		 */
		var barCount = document.getElementById('wpcc-adminbar-pending-count');
		if ( ! barCount ) { return; }
		var barNode = document.getElementById('wp-admin-bar-wpcc-pending-approvals');
		if ( count > 0 ) {
			barCount.textContent = count;
			if ( barNode ) { barNode.style.display = ''; }
		} else if ( barNode ) {
			barNode.style.display = 'none';
		}
	}

	// ── Pending tab ────────────────────────────────────────────────────────────
	// Rebuilt from stacked cards to a dense review list. The card layout spent
	// ~172px per item on three lines of content, so a real queue (282 items on the
	// test site) became 18 screens of scrolling with 100 identical blue "Approve"
	// buttons and no way to act in bulk. Nothing here changes what approving does —
	// it is the same request, the same endpoint, the same confirmation path.
	//
	// One row = one decision: what will change · where · how risky · how old.
	// Actions stay quiet until the row is hovered or focused, so the eye lands on
	// the change description rather than on 100 competing buttons.
	function renderRow( req ) {
		var risk  = req.risk_level || 'medium';
		var label = riskLabels[ risk ] || risk;
		var headline = req.headline || req.operation;
		var tech = ( req.operation_id || '' ) + ( req.action ? ' · ' + req.action : '' );

		return '<li class="wpcc-apr-row risk-' + escHtml(risk) + '" id="wpcc-card-' + escHtml(req.request_id) + '">' +
			'<label class="wpcc-apr-pick"><input type="checkbox" class="wpcc-apr-cb" value="' + escHtml(req.request_id) + '" aria-label="' + escHtml(i18n.selectOne) + '"></label>' +
			'<div class="wpcc-apr-main">' +
				'<a class="wpcc-apr-title" href="' + escHtml(baseUrl) + '&view=' + escHtml(req.request_id) + '">' + escHtml(headline) + '</a>' +
				/* What it will be set to — so the decision can be made from the row. */
				( req.preview ? '<span class="wpcc-apr-preview">&rarr; ' + escHtml(req.preview) + '</span>' : '' ) +
				( req.destructive ? '<span class="wpcc-apr-warn" title="' + escHtml(req.destructive_warning || '') + '">&#9888; ' + escHtml(i18n.destructive) + '</span>' : '' ) +
				'<span class="wpcc-apr-sub">' +
					( req.area ? escHtml(req.area) : '' ) +
					'<span class="wpcc-apr-dot">·</span>' + escHtml(req.created_ago) +
					( req.reason ? '<span class="wpcc-apr-dot">·</span>' + escHtml(req.reason) : '' ) +
					'<span class="wpcc-engineer-only"><span class="wpcc-apr-dot">·</span><code>' + escHtml(tech) + '</code></span>' +
				'</span>' +
			'</div>' +
			'<span class="wpcc-risk-badge risk-' + escHtml(risk) + '">' + escHtml(label) + '</span>' +
			'<div class="wpcc-apr-actions">' +
				'<a class="button button-small wpcc-apr-review" href="' + escHtml(baseUrl) + '&view=' + escHtml(req.request_id) + '">' + escHtml(i18n.review) + '</a> ' +
				'<button class="button button-small wpcc-approve-btn" data-id="' + escHtml(req.request_id) + '" data-action="approve">' + escHtml(i18n.approve) + '</button> ' +
				'<button class="button button-small button-link-delete wpcc-reject-btn" data-id="' + escHtml(req.request_id) + '" data-action="reject">' + escHtml(i18n.reject) + '</button>' +
			'</div>' +
			'<div class="wpcc-card-result" id="wpcc-result-' + escHtml(req.request_id) + '" role="status" aria-live="polite" style="display:none;"></div>' +
		'</li>';
	}
	// Client-side paging over the already-fetched queue. The endpoint and its
	// response are untouched; this only stops the browser from painting hundreds
	// of rows the customer will never scroll to, and — more importantly — tells
	// them how many there actually are, which the old list never did.
	var pendingAll = [], pendingPage = 0, pendingTotal = 0, summaryPending = 0;
	var PENDING_PER_PAGE = 25;

	function renderPendingPage() {
		var list = document.getElementById('wpcc-approvals-list');
		if ( ! list ) { return; }
		var total = pendingAll.length;
		var pages = Math.max( 1, Math.ceil( total / PENDING_PER_PAGE ) );
		if ( pendingPage >= pages ) { pendingPage = pages - 1; }
		var start = pendingPage * PENDING_PER_PAGE;
		var slice = pendingAll.slice( start, start + PENDING_PER_PAGE );

		var head =
			'<div class="wpcc-apr-bar">' +
				'<label class="wpcc-apr-all"><input type="checkbox" id="wpcc-apr-selectall"> ' + escHtml(i18n.selectAll) + '</label>' +
				// Two separate truths, both stated: how many rows are loaded here, and
				// how many are actually pending. The old list silently rendered the
				// first 100 of 282 and said nothing at all.
				'<span class="wpcc-apr-count">' +
					escHtml( fmt2( i18n.showingRange, ( total ? start + 1 : 0 ) + '–' + Math.min( start + PENDING_PER_PAGE, total ), total ) ) +
					( pendingTotal > total ? ' <span class="wpcc-apr-more">' + escHtml( fmt1( i18n.moreQueued, pendingTotal ) ) + '</span>' : '' ) +
				'</span>' +
				'<span class="wpcc-apr-bulk" id="wpcc-apr-bulk" hidden>' +
					'<span class="wpcc-apr-selcount" id="wpcc-apr-selcount"></span>' +
					'<button type="button" class="button button-primary button-small" id="wpcc-apr-bulk-approve">' + escHtml(i18n.approveSelected) + '</button> ' +
					'<button type="button" class="button button-small button-link-delete" id="wpcc-apr-bulk-reject">' + escHtml(i18n.rejectSelected) + '</button>' +
					'<span class="wpcc-apr-progress" id="wpcc-apr-bulk-progress" role="status" aria-live="polite" hidden></span>' +
				'</span>' +
			'</div>';

		var pager = pages > 1
			? '<div class="wpcc-apr-pager">' +
				'<button type="button" class="button button-small" id="wpcc-apr-prev"' + ( pendingPage === 0 ? ' disabled' : '' ) + '>&larr; ' + escHtml(i18n.prev) + '</button>' +
				'<span>' + escHtml( fmt2( i18n.pageOf, pendingPage + 1, pages ) ) + '</span>' +
				'<button type="button" class="button button-small" id="wpcc-apr-next"' + ( pendingPage >= pages - 1 ? ' disabled' : '' ) + '>' + escHtml(i18n.next) + ' &rarr;</button>' +
			  '</div>'
			: '';

		list.innerHTML = head + '<ul class="wpcc-apr-list">' + slice.map( renderRow ).join('') + '</ul>' + pager;
		attachPendingHandlers();
		attachPendingListChrome();
	}

	function loadPending() {
		apiFetch( '/approvals' ).then( function( data ) {
			var list = document.getElementById('wpcc-approvals-list');
			if ( ! list ) { return; }
			pendingAll = ( data && data.requests ) ? data.requests : [];
			// `data.total` counts what this response returned, not how deep the queue
			// is. The summary read knows the real number, so prefer it when we have it.
			pendingTotal = ( summaryPending > 0 ) ? summaryPending : pendingAll.length;
			if ( pendingAll.length === 0 ) {
				// A cleared queue is good news and should look like it, not like an
				// error notice.
				list.innerHTML =
					'<div class="wpcc-apr-clear" role="status">' +
						'<span class="wpcc-apr-clear__mark" aria-hidden="true">&#10003;</span>' +
						'<p class="wpcc-apr-clear__title">' + escHtml(i18n.clearTitle) + '</p>' +
						'<p class="wpcc-apr-clear__detail">' + escHtml(i18n.clearDetail) + '</p>' +
					'</div>';
				return;
			}
			pendingPage = 0;
			renderPendingPage();
		} ).catch( function() {
			var list = document.getElementById('wpcc-approvals-list');
			if ( list ) { list.innerHTML = '<div class="notice notice-error inline"><p>' + escHtml(i18n.loadFailed) + '</p></div>'; }
		} );
	}

	// Selection + paging chrome. Bulk actions reuse the SAME single-request path
	// as the row buttons, one after another, so every governance check, audit
	// entry and destructive-confirmation still applies to each request
	// individually. Nothing is batched server-side.
	function selectedIds() {
		return [].slice.call( document.querySelectorAll('.wpcc-apr-cb:checked') ).map( function(c){ return c.value; } );
	}
	function syncBulkBar() {
		var n = selectedIds().length;
		var bar = document.getElementById('wpcc-apr-bulk');
		var cnt = document.getElementById('wpcc-apr-selcount');
		if ( ! bar ) { return; }
		bar.hidden = n === 0;
		if ( cnt ) { cnt.textContent = fmt1( i18n.nSelected, n ); }
	}
	function attachPendingListChrome() {
		var all = document.getElementById('wpcc-apr-selectall');
		if ( all ) {
			all.addEventListener( 'change', function () {
				[].slice.call( document.querySelectorAll('.wpcc-apr-cb') ).forEach( function(c){ c.checked = all.checked; } );
				syncBulkBar();
			} );
		}
		[].slice.call( document.querySelectorAll('.wpcc-apr-cb') ).forEach( function(c){
			c.addEventListener( 'change', syncBulkBar );
		} );
		var prev = document.getElementById('wpcc-apr-prev');
		var next = document.getElementById('wpcc-apr-next');
		if ( prev ) { prev.addEventListener( 'click', function(){ pendingPage--; renderPendingPage(); window.scrollTo({top:0,behavior:'smooth'}); } ); }
		if ( next ) { next.addEventListener( 'click', function(){ pendingPage++; renderPendingPage(); window.scrollTo({top:0,behavior:'smooth'}); } ); }

		var ba = document.getElementById('wpcc-apr-bulk-approve');
		var br = document.getElementById('wpcc-apr-bulk-reject');
		if ( ba ) { ba.addEventListener( 'click', function(){ runBulk( 'approve' ); } ); }
		if ( br ) { br.addEventListener( 'click', function(){ runBulk( 'reject' ); } ); }
		syncBulkBar();
	}

	function runBulk( action ) {
		var ids = selectedIds();
		if ( ! ids.length ) { return; }
		// In-page confirmation (WPCC.cds.confirm) rather than window.confirm: this
		// decision covers several real changes at once, so it belongs in the
		// product's own dialog, which can name the count and the consequence.
		WPCC.cds.confirm( {
			title: fmt1( action === 'approve' ? i18n.confirmBulkApprove : i18n.confirmBulkReject, ids.length ),
			body: action === 'approve' ? i18n.bulkApproveBody : i18n.bulkRejectBody,
			confirmLabel: action === 'approve' ? i18n.approveSelected : i18n.rejectSelected,
			cancelLabel: i18n.cancel,
			danger: action !== 'approve'
		} ).then( function ( ok ) {
			if ( ! ok ) { return; }
			runBulkConfirmed( action, ids );
		} );
	}

	function runBulkConfirmed( action, ids ) {
		var bar = document.getElementById('wpcc-apr-bulk');
		if ( bar ) { bar.hidden = true; }
		// Sequential, not parallel: each request goes through the engine exactly as
		// a single click would, and a destructive one that needs its own
		// confirmation is skipped here rather than waved through.
		/*
		 * Sequential work with no progress shown looked like the page had hung:
		 * the bulk bar hid itself and nothing else moved until every request had
		 * finished. Report position while it runs.
		 */
		var progress = document.getElementById( 'wpcc-apr-bulk-progress' );
		function report( n ) {
			if ( progress ) {
				progress.hidden = false;
				progress.textContent = fmt( i18n.bulkProgress, [ Math.min( n + 1, ids.length ), ids.length ] );
			}
		}
		var i = 0, done = 0, skipped = 0;
		report( 0 );
		(function step() {
			if ( i >= ids.length ) {
				if ( progress ) { progress.hidden = true; }
				loadPending();
				loadSummary();
				return;
			}
			report( i );
			var id = ids[ i++ ];
			var req = pendingAll.filter( function(r){ return r.request_id === id; } )[0];
			if ( action === 'approve' && req && req.destructive ) { skipped++; return step(); }
			postAction( id, action, action === 'reject' ? { reason: i18n.bulkReason } : null )
				.then( function(){ done++; step(); } )
				.catch( function(){ step(); } );
		})();
	}
	function showResult( result, msg, cls ) {
		if ( ! result ) { return; }
		result.textContent = msg; // text first — the optional link is appended as HTML by the caller

		result.className = 'wpcc-card-result ' + cls;
		result.style.display = 'block';
	}
	function postAction( id, action, body ) {
		var opts = { method: 'POST' };
		if ( body ) { opts.body = JSON.stringify( body ); }
		return apiFetch( '/approvals/' + id + '/' + action, opts );
	}
	/*
	 * One way back to the truth after a decision.
	 *
	 * A decision changes more than the row that was clicked: the status, the
	 * resolver, the timestamps, the audit trail, the queue, the execution result
	 * and every count on the screen. Patching those individually is how the detail
	 * screen came to say "pending_review · WAITING FOR YOU" under a green
	 * "Rejected." — the confirmation and the record contradicting each other until
	 * the customer reloaded by hand.
	 *
	 * So nothing is patched. The summary and, on a detail screen, the request
	 * itself are re-read from the server and re-rendered from that response, which
	 * is the same authoritative record a manual reload would have fetched. The
	 * decision's own confirmation is handed to loadDetail() so it survives the
	 * repaint instead of being wiped by it.
	 *
	 * Called only after a recorded decision. A failed action must leave the screen
	 * exactly as it was, still showing the controls needed to try again.
	 */
	function refreshAfterDecision( notice ) {
		loadSummary();
		if ( ! detailId ) { return; }
		// Deferred so the confirmation is readable in place before the section
		// repaints; the repaint then re-states it from the authoritative record.
		window.setTimeout( function () { loadDetail( detailId, notice ); }, 1200 );
	}
	function submitApprove( ctx, body ) {
		ctx.btn.disabled = true; if ( ctx.sibling ) { ctx.sibling.disabled = true; }
		postAction( ctx.id, 'approve', body ).then( function( data ) {
			// confirmation_required carries success:true — check it FIRST.
			if ( data && data.confirmation_required ) {
				openConfirmModal( ctx, data );
				ctx.btn.disabled = false; if ( ctx.sibling ) { ctx.sibling.disabled = false; }
				return;
			}
			if ( data && data.success ) {
				closeConfirmModal();
				/*
				 * `success` here means the DECISION was recorded, not that the change
				 * ran. When execution then fails, the customer was shown "Approved but
				 * execution failed: …" in success green — a failure wearing the colour
				 * of a success, on the screen where they had just granted permission.
				 * The decision still stands either way, so the row still settles; only
				 * the message tells the truth about what happened to the change.
				 */
				var failed = !! data.error;
				showResult( ctx.result, failed ? i18n.approvedErr : i18n.approved, failed ? 'error' : 'success' );
				/*
				 * The engine's own words for what went wrong are kept — they are
				 * what a developer needs — but they are a technical detail, so they
				 * live behind the Detailed disclosure like every other technical
				 * detail on this screen. In Simple mode the customer gets the
				 * sentence above, which already tells them their site is untouched
				 * and what to do next.
				 */
				if ( failed && ctx.result ) {
					ctx.result.innerHTML += ' <span class="wpcc-engineer-only"><code>' + escHtml( data.error ) + '</code></span>';
				}
				if ( ctx.card ) { ctx.card.style.opacity = '0.6'; }
				/*
				 * Close the loop.
				 *
				 * This is the moment the whole product exists for: an assistant asked
				 * to change the customer's live website, they read it, and they said
				 * yes. What they got was a row dimmed to 60% and the words "Approved
				 * and executed" — a log entry, not an outcome — and then the decided
				 * item sat in the Pending list until the page was reloaded, so the
				 * queue never actually looked finished.
				 *
				 * Now the sentence confirms the SITE changed, offers the one thing
				 * someone might want next (seeing or undoing it), and the queue
				 * settles on its own so the customer is left with a genuinely empty
				 * "nothing is waiting for you" — the calm, complete end of the task.
				 */
				if ( ! failed && ctx.result ) {
					ctx.result.innerHTML += ' <a class="wpcc-result-link" href="' +
						escHtml( i18n.changesUrl ) + '">' + escHtml( i18n.approvedLink ) + ' &rarr;</a>';
				}
				if ( ! failed && ! detailId ) {
					window.setTimeout( function () {
						if ( ctx.card ) { ctx.card.style.transition = 'opacity .25s ease'; ctx.card.style.opacity = '0'; }
						window.setTimeout( function () { loadPending(); loadSummary(); }, 260 );
					}, 2600 );
				}
				// Authoritative re-read: the chip, the timestamps, the audit trail, the
				// queue and the counts all come back from the server rather than being
				// guessed at here. The confirmation just shown is carried through the
				// repaint — including the engine's own error text when the decision was
				// recorded but the change could not run.
				refreshAfterDecision( {
					msg:  failed ? i18n.approvedErr : i18n.approved,
					cls:  failed ? 'error' : 'success',
					html: failed
						? ' <span class="wpcc-engineer-only"><code>' + escHtml( data.error ) + '</code></span>'
						: ''
				} );
			} else {
				showResult( ctx.result, actionError( data ), 'error' );
				ctx.btn.disabled = false; if ( ctx.sibling ) { ctx.sibling.disabled = false; }
			}
		} ).catch( function() {
			showResult( ctx.result, i18n.reqFailed, 'error' );
			ctx.btn.disabled = false; if ( ctx.sibling ) { ctx.sibling.disabled = false; }
		} );
	}
	function attachPendingHandlers() {
		document.querySelectorAll('.wpcc-approve-btn, .wpcc-reject-btn').forEach( function(btn) {
			btn.addEventListener('click', function() {
				var id = btn.dataset.id, action = btn.dataset.action;
				var card = document.getElementById('wpcc-card-' + id);
				var result = document.getElementById('wpcc-result-' + id);
				var sibling = card.querySelector( action === 'approve' ? '.wpcc-reject-btn' : '.wpcc-approve-btn' );
				var ctx = { id: id, card: card, result: result, btn: btn, sibling: sibling };

				if ( 'approve' === action ) { submitApprove( ctx, null ); return; }

				// reject
				btn.disabled = true; if ( sibling ) { sibling.disabled = true; }
				postAction( id, 'reject', null ).then( function(data) {
					if ( data && data.success ) {
						showResult( result, i18n.rejected, 'success' );
						if ( card ) { card.style.opacity = '0.6'; }
						// Rejecting used to update nothing but the summary counts, so a
						// detail screen kept its "WAITING FOR YOU" chip, its Approve and
						// Reject buttons and its pre-decision audit trail under a green
						// "Rejected." — the one path where the page could still be acted
						// on after the decision had already been made. Same authoritative
						// re-read as approve.
						refreshAfterDecision( { msg: i18n.rejected, cls: 'success', html: '' } );
					} else {
						showResult( result, actionError( data ), 'error' );
						btn.disabled = false; if ( sibling ) { sibling.disabled = false; }
					}
				} ).catch( function() {
					showResult( result, i18n.reqFailed, 'error' );
					btn.disabled = false; if ( sibling ) { sibling.disabled = false; }
				} );
			} );
		} );
	}

	// ── Destructive-approval confirm modal (106.3, parity with 105.3 restore) ──
	var modalCtx = null, modalTrigger = null;
	function openConfirmModal( ctx, resp ) {
		modalCtx = ctx; modalTrigger = ctx.btn;
		var m = document.getElementById('wpcc-confirm-modal');
		document.getElementById('wpcc-confirm-warning').textContent = resp.warning || '';
		document.getElementById('wpcc-confirm-phrase-hint').textContent = resp.confirmation_phrase || '';
		m.dataset.phrase = resp.confirmation_phrase || '';
		var p = document.getElementById('wpcc-confirm-phrase'), r = document.getElementById('wpcc-confirm-reason');
		p.value = ''; r.value = ''; document.getElementById('wpcc-confirm-go').disabled = true;
		m.style.display = 'flex';
		p.focus();
	}
	function closeConfirmModal() {
		var m = document.getElementById('wpcc-confirm-modal');
		if ( ! m || m.style.display === 'none' ) { return; }
		m.style.display = 'none';
		if ( modalTrigger ) { try { modalTrigger.focus(); } catch(e){} }
		modalCtx = null; modalTrigger = null;
	}
	function validateModal() {
		var m = document.getElementById('wpcc-confirm-modal');
		var phrase = document.getElementById('wpcc-confirm-phrase').value;
		var reason = document.getElementById('wpcc-confirm-reason').value.replace(/^\s+|\s+$/g,'');
		document.getElementById('wpcc-confirm-go').disabled = ! ( phrase === m.dataset.phrase && reason.length > 0 );
	}
	function initModal() {
		var p = document.getElementById('wpcc-confirm-phrase'), r = document.getElementById('wpcc-confirm-reason');
		if ( ! p ) { return; }
		p.addEventListener('input', validateModal);
		r.addEventListener('input', validateModal);
		document.getElementById('wpcc-confirm-cancel').addEventListener('click', closeConfirmModal);
		document.getElementById('wpcc-confirm-go').addEventListener('click', function() {
			if ( ! modalCtx ) { return; }
			submitApprove( modalCtx, {
				confirm: true,
				confirmation_phrase: p.value,
				reason: r.value.replace(/^\s+|\s+$/g,'')
			} );
		});
		document.addEventListener('keydown', function(ev) {
			var m = document.getElementById('wpcc-confirm-modal');
			if ( ! m || m.style.display === 'none' ) { return; }
			if ( ev.key === 'Escape' ) { closeConfirmModal(); return; }
			if ( ev.key !== 'Tab' ) { return; }
			// Focus trap: keep Tab/Shift+Tab within the modal's focusable controls.
			var f = m.querySelectorAll('input, textarea, button');
			if ( ! f.length ) { return; }
			var first = f[0], last = f[ f.length - 1 ];
			if ( ev.shiftKey && document.activeElement === first ) { ev.preventDefault(); last.focus(); }
			else if ( ! ev.shiftKey && document.activeElement === last ) { ev.preventDefault(); first.focus(); }
		});
	}

	// ── Queue retry (106.3): delegated; routes through the audited engine ──
	function retryButton( q ) {
		return q.status === 'failed'
			? '<button class="button wpcc-retry-btn" data-queue-id="' + escHtml(q.queue_id) + '">' + escHtml(i18n.retry) + '</button>'
			: '';
	}
	function initRetry() {
		document.addEventListener('click', function(ev) {
			var btn = ev.target && ev.target.closest ? ev.target.closest('.wpcc-retry-btn') : null;
			if ( ! btn ) { return; }
			ev.preventDefault();
			// Same reasoning as the bulk confirmation above: the product's own dialog.
			WPCC.cds.confirm( {
				title: i18n.retryConfirm,
				confirmLabel: i18n.retryYes,
				cancelLabel: i18n.cancel
			} ).then( function ( ok ) { if ( ok ) { doRetry( btn ); } } );
		} );
	}

	function doRetry( btn ) {
		var qid = btn.dataset.queueId;
		btn.disabled = true; btn.textContent = i18n.retrying;
		/*
		 * Failures used to surface through window.alert(), which blocks the whole
		 * renderer and looks like a browser error rather than something this
		 * product is telling you. The message now appears next to the button that
		 * caused it, where the customer is already looking.
		 */
		function fail( detail ) {
			var cell = btn.parentNode;
			if ( cell ) {
				var note = cell.querySelector( '.wpcc-retry-error' );
				if ( ! note ) {
					note = document.createElement( 'span' );
					note.className = 'wpcc-retry-error';
					cell.appendChild( note );
				}
				note.textContent = i18n.retryFailed + detail;
			}
			btn.disabled = false; btn.textContent = i18n.retry;
		}
		apiFetch( '/approvals/queue/' + qid + '/retry', { method: 'POST' } ).then( function(d) {
			if ( d && d.success ) {
				loadSummary();
				if ( detailId ) { loadDetail( detailId ); }
				else { loadQueue(); }
			} else {
				fail( actionError( d ) );
			}
		} ).catch( function() { fail( i18n.reqFailed ); } );
	}

	// ── History tab ──
	var historyOffset = 0;
	// The raw engine token rides along in Detailed, so the technical truth is
	// never lost — only moved out of the customer's way.
	function statusPill( status, kind ) {
		return '<span class="wpcc-status-pill ' + escHtml(status) + '">' + escHtml( statusLabel(status, kind) ) + '</span>' +
			'<code class="wpcc-engineer-only" style="font-size:11px;margin-left:6px;">' + escHtml(status) + '</code>';
	}
	function riskBadge( risk ) {
		return '<span class="wpcc-risk-badge risk-' + escHtml(risk) + '">' + escHtml( riskLabels[risk] || risk ) + '</span>';
	}
	function historyRow( r ) {
		var rowTitle = r.headline || r.operation;
		return '<tr>' +
			'<td><a href="' + escHtml(baseUrl) + '&view=' + escHtml(r.request_id) + '" aria-label="' + escHtml(i18n.detailsFor) + ': ' + escHtml(rowTitle) + '">' + escHtml(rowTitle) + '</a></td>' +
			'<td>' + escHtml(r.area || '—') + '<span class="wpcc-engineer-only"><br><code style="font-size:11px;">' + escHtml(r.action || '') + '</code></span></td>' +
			'<td>' + riskBadge(r.risk_level || 'medium') + '</td>' +
			'<td>' + statusPill(r.status) + '</td>' +
			'<td>' + ( r.resolved_by ? escHtml(r.resolved_by) : '<em style="color:#646970;">' + escHtml(i18n.unavailable) + '</em>' ) + '</td>' +
			'<td>' + escHtml( whenAgo(r.created_at) ) + '</td>' +
		'</tr>';
	}
	function loadHistory( append ) {
		if ( ! append ) { historyOffset = 0; }
		apiFetch( '/approvals/history?limit=25&offset=' + historyOffset ).then( function( data ) {
			var list = document.getElementById('wpcc-history-list');
			if ( ! list ) { return; }
			var rows = data.requests || [];
			if ( ! append && rows.length === 0 ) {
				list.innerHTML = '<div class="notice notice-info inline"><p>' + escHtml(i18n.noHistory) + '</p></div>';
				return;
			}
			var rowsHtml = rows.map( historyRow ).join('');
			if ( append ) {
				var tbody = document.getElementById('wpcc-history-tbody');
				if ( tbody ) { tbody.insertAdjacentHTML('beforeend', rowsHtml); }
			} else {
				list.innerHTML =
					'<table class="widefat striped"><thead><tr>' +
					'<th scope="col">' + escHtml(i18n.colOp) + '</th><th>' + escHtml(i18n.colAction) + '</th><th>' + escHtml(i18n.colRisk) + '</th>' +
					'<th scope="col">' + escHtml(i18n.colStatus) + '</th><th>' + escHtml(i18n.colResolved) + '</th><th>' + escHtml(i18n.colWhen) + '</th>' +
					'</tr></thead><tbody id="wpcc-history-tbody">' + rowsHtml + '</tbody></table>' +
					'<div class="wpcc-loadmore" id="wpcc-history-more"></div>';
			}
			historyOffset += rows.length;
			var more = document.getElementById('wpcc-history-more');
			if ( more ) {
				more.innerHTML = data.has_more ? '<button class="button" id="wpcc-history-loadmore">' + escHtml(i18n.loadMore) + '</button>' : '';
				var btn = document.getElementById('wpcc-history-loadmore');
				if ( btn ) { btn.addEventListener('click', function() { loadHistory( true ); } ); }
			}
		} ).catch( function() {
			var list = document.getElementById('wpcc-history-list');
			if ( list ) { list.innerHTML = '<div class="notice notice-error inline"><p>' + escHtml(i18n.loadFailed) + '</p></div>'; }
		} );
	}

	// ── Queue tab ──
	function queueRow( q ) {
		return '<tr>' +
			'<td><code>' + escHtml( String(q.queue_id).substring(0,8) ) + '</code></td>' +
			// Was the raw operation ID. Same dictionary the rest of the product uses.
			'<td>' + escHtml( WPCC_LABELS.titles[ q.operation_id ] || WPCC_LABELS.areas[ q.operation_id ] || q.operation_id ) +
				'<span class="wpcc-engineer-only"><br><code style="font-size:11px;">' + escHtml( q.operation_id ) + '</code></span></td>' +
			// Queue vocabulary, not request vocabulary — this is the Execution tab's
			// own table and it shows the SAME rows as the detail panel's queue.
			'<td>' + statusPill(q.status, 'queue') + '</td>' +
			'<td>' + escHtml(q.attempts) + ' / ' + escHtml(q.max_attempts) + '</td>' +
			'<td>' + ( q.error_message ? escHtml(q.error_message) : '—' ) + '</td>' +
			'<td>' + retryButton(q) + '</td>' +
		'</tr>';
	}
	function loadQueue() {
		apiFetch( '/approvals/queue?limit=50' ).then( function( data ) {
			var list = document.getElementById('wpcc-queue-list');
			if ( ! list ) { return; }
			var items = data.items || [];
			if ( items.length === 0 ) {
				list.innerHTML = '<div class="notice notice-info inline"><p>' + escHtml(i18n.noQueue) + '</p></div>';
				return;
			}
			list.innerHTML =
				'<table class="widefat striped"><thead><tr>' +
				'<th scope="col">' + escHtml(i18n.colQueueId) + '</th><th>' + escHtml(i18n.colOp) + '</th><th>' + escHtml(i18n.colStatus) + '</th>' +
				'<th scope="col">' + escHtml(i18n.colAttempts) + '</th><th>' + escHtml(i18n.colError) + '</th><th></th>' +
				'</tr></thead><tbody>' + items.map( queueRow ).join('') + '</tbody></table>';
		} ).catch( function() {
			var list = document.getElementById('wpcc-queue-list');
			if ( list ) { list.innerHTML = '<div class="notice notice-error inline"><p>' + escHtml(i18n.loadFailed) + '</p></div>'; }
		} );
	}

	// ── Detail panel (106.2) ──
	function fmt( tpl, args ) {
		return tpl.replace( /%(\d+)\$s/g, function( m, i ) { return escHtml( args[ i - 1 ] ); } );
	}
	function section( title, body ) {
		return '<div class="wpcc-detail-section"><h2>' + escHtml(title) + '</h2>' + body + '</div>';
	}

	/*
	 * Turn a request payload into plain "field -> value" rows.
	 *
	 * Deliberately dumb and additive: it renames the keys it recognises, walks one
	 * level into a nested object (settings/fields carry the real values), and skips
	 * the plumbing keys that mean nothing to a site owner. Anything it does not
	 * recognise is still shown, with its key tidied up, because silently dropping a
	 * field from an approval screen would be worse than showing a plain key name.
	 */
	var PAYLOAD_SKIP = { action:1, reason:1, confirm:1, confirmation_phrase:1, idempotency_key:1, request_id:1, dry_run:1 };

	function fieldLabel( key ) {
		if ( i18n.fieldNames && i18n.fieldNames[ key ] ) { return i18n.fieldNames[ key ]; }
		var w = String( key ).replace( /_/g, ' ' ).trim();
		return w.charAt( 0 ).toUpperCase() + w.slice( 1 );
	}

	function displayValue( v ) {
		if ( v === null || v === undefined ) { return '—'; }
		if ( typeof v === 'boolean' ) { return v ? i18n.yes : i18n.no; }
		if ( Array.isArray( v ) ) { return v.length ? v.join( ', ' ) : '—'; }
		if ( typeof v === 'object' ) { return JSON.stringify( v ); }
		var s = String( v );
		return '' === s.trim() ? i18n.emptyValue : s;
	}

	/*
	 * Keys whose VALUE is itself the name of a setting, not a value a user typed.
	 * `option_manage` sends {option_id: "site_title", value: "…"}, so the row read
	 * "Setting: site_title" — the raw registry id — directly beneath a heading that
	 * had already called the very same thing "Site title". The name is known; the
	 * table just was not asking for it.
	 */
	var NAMES_A_SETTING = { option_id: 1, setting: 1, option: 1, option_name: 1 };

	function settingName( v ) {
		var s = String( v );
		return ( i18n.fieldNames && i18n.fieldNames[ s ] ) ? i18n.fieldNames[ s ] : displayValue( v );
	}

	/*
	 * `undoTarget` is the server-resolved description of the change an undo
	 * reverses (ApprovalAdminQuery::detail -> ActionLabels::undo_target). When it
	 * is present, the change-id fields become ONE readable row instead of a raw
	 * UUID: "Undoes: Update SEO details". The identifier itself is still available
	 * to anyone who wants it — as a Detailed-only row, and in the raw request
	 * payload one click below — but it is no longer the answer a customer reads
	 * when deciding whether to approve.
	 */
	var UNDO_ID_KEYS = { change_id: 1, target_change_id: 1, rollback_id: 1 };

	/*
	 * Object ids are technical details now that the heading names the object.
	 *
	 * "Content id 111" sat in What-will-change as though it were one of the things
	 * being changed. It is not — it is how the engine addresses the page, and the
	 * heading above already says which page ("Update SEO details — “Shop”"). It
	 * stays, in Detailed, next to every other identifier.
	 */
	var OBJECT_ID_KEYS = { content_id: 1, post_id: 1, media_id: 1, attachment_id: 1, product_id: 1, order_id: 1, user_id: 1, term_id: 1, comment_id: 1 };

	function summarisePayload( payload, undoTarget ) {
		var rows = [];
		var undoIds = [];
		Object.keys( payload || {} ).forEach( function ( key ) {
			if ( PAYLOAD_SKIP[ key ] ) { return; }
			// Collect only ids that actually have a value — an undo request can
			// carry an empty change_id, and an empty "Change id: " row is noise
			// wearing a label.
			if ( UNDO_ID_KEYS[ key ] ) {
				var idVal = String( payload[ key ] == null ? '' : payload[ key ] ).trim();
				if ( idVal ) { undoIds.push( idVal ); }
				return;
			}
			var val = payload[ key ];
			// One level down: settings/fields/meta hold the values that actually change.
			if ( val && typeof val === 'object' && ! Array.isArray( val ) ) {
				Object.keys( val ).forEach( function ( sub ) {
					rows.push( { label: fieldLabel( sub ), value: displayValue( val[ sub ] ) } );
				} );
				return;
			}
			rows.push( {
				label: fieldLabel( key ),
				value: NAMES_A_SETTING[ key ] ? settingName( val ) : displayValue( val ),
				tech: !! OBJECT_ID_KEYS[ key ],
			} );
		} );
		/*
		 * Keyed off the ACTION, not off finding an id.
		 *
		 * An undo whose change_id is empty (it happens — the reversible list can
		 * submit one) would otherwise produce an entirely blank "What will change"
		 * on a screen asking for consent. Every undo says what it undoes, even
		 * when the honest answer is that the original can no longer be resolved.
		 */
		if ( 'rollback_target' === ( payload || {} ).action || undoIds.length ) {
			rows.unshift( {
				label: i18n.lblUndoes,
				value: ( undoTarget && String( undoTarget ).trim() ) ? undoTarget : i18n.undoUnknown
			} );
			// The identifier itself: Detailed only, and only when there is one.
			if ( undoIds.length ) {
				rows.push( { label: i18n.lblChangeId, value: undoIds.join( ', ' ), tech: true } );
			}
		}
		return rows;
	}

	/* Audit events are recorded as machine ids (operation.approval.auto_requested). */
	function auditLabel( action ) {
		if ( i18n.auditNames && i18n.auditNames[ action ] ) { return i18n.auditNames[ action ]; }
		var tail = String( action || '' ).split( '.' ).pop().replace( /_/g, ' ' ).trim();
		return tail.charAt( 0 ).toUpperCase() + tail.slice( 1 );
	}
	function metaRow( label, value, raw ) {
		if ( value === null || value === undefined || value === '' ) { return ''; }
		return '<tr><td>' + escHtml(label) + '</td><td>' + ( raw ? value : escHtml(value) ) + '</td></tr>';
	}
	// Lifecycle-timestamp row: render only when the timestamp is actually set,
	// so a successful request does not show empty "Rejected —/Failed —" rows.
	function tsRow( label, ts ) { return ts ? metaRow( label, whenAgo(ts) ) : ''; }
	/*
	 * Render the request from the server's record.
	 *
	 * `notice` is optional and carries the confirmation for a decision that was
	 * just recorded, so the repaint that proves the decision landed does not also
	 * erase the sentence telling the customer it did. It is presentation only —
	 * every fact on this screen comes from the response.
	 */
	function loadDetail( id, notice ) {
		var box = document.getElementById('wpcc-detail');
		if ( ! box ) { return; }
		apiFetch( '/approvals/' + id ).then( function( d ) {
			if ( ! d || ! d.success ) {
				box.innerHTML = '<div class="notice notice-error inline"><p>' + escHtml( d && d.message ? d.message : i18n.notFound ) + '</p></div>';
				return;
			}
			var r = d.request || {};
			var risk = r.risk_level || 'medium';
			var resolvedBy = r.resolved_by ? escHtml(r.resolved_by) : '<em style="color:#646970;">' + escHtml(i18n.unavailable) + '</em>';

			// The decision belongs ON the decision screen.
			//
			// This page existed to let someone inspect a diff before approving — and
			// then offered no way to approve. The only control was "Back to
			// Approvals", so a customer who did the careful thing (open it, read the
			// change) had to navigate back and re-find the row to act. A review screen
			// you cannot act from is a dead end.
			//
			// Same buttons, same classes, same handlers, same destructive-confirmation
			// path as the list — attachPendingHandlers() binds these exactly as it
			// binds a row. Only pending requests get them; a decided one shows its
			// outcome instead.
			var isPending = ( r.status === 'pending_review' || r.status === 'pending' );
			var decision = isPending
				? '<div class="wpcc-detail-decide" id="wpcc-card-' + escHtml(r.request_id) + '">' +
						'<button class="button button-primary wpcc-approve-btn" data-id="' + escHtml(r.request_id) + '" data-action="approve">' + escHtml(i18n.approve) + '</button> ' +
						'<button class="button button-link-delete wpcc-reject-btn" data-id="' + escHtml(r.request_id) + '" data-action="reject">' + escHtml(i18n.reject) + '</button>' +
						'<span class="wpcc-detail-decide__note">' + escHtml(i18n.auditNote) + '</span>' +
						'<div class="wpcc-card-result" id="wpcc-result-' + escHtml(r.request_id) + '" role="status" aria-live="polite" style="display:none;"></div>' +
					'</div>'
				: '';

			// The decision's confirmation, restated above the record it produced.
			// Text is escaped; `html` is the caller's own already-escaped markup.
			var decided = notice && notice.msg
				? '<div class="wpcc-card-result ' + escHtml( notice.cls || 'success' ) + '" role="status" aria-live="polite" ' +
					'style="display:block;margin:0 0 12px;">' + escHtml( notice.msg ) + ( notice.html || '' ) + '</div>'
				: '';

			var head = '<div class="wpcc-detail-head">' +
				'<span class="wpcc-detail-title">' + escHtml(r.headline || r.operation) + '</span>' +
				statusPill(r.status) + riskBadge(risk) + '</div>' + decided + decision;

			// "Action" previously showed the AREA ("Site files"), and "Resolved by"
			// printed "unavailable" on every pending request — a row that is empty by
			// definition until someone decides. Label what is shown, and show a field
			// only when it has an answer.
			var meta = '<table class="wpcc-detail-meta"><tbody>' +
				metaRow(i18n.lblArea, escHtml( r.area || '—' ) + ' <code class="wpcc-engineer-only" style="font-size:11px;">' + escHtml( ( r.operation_id || '' ) + ( r.action ? ' · ' + r.action : '' ) ) + '</code>', true) +
				metaRow(i18n.colStatus, statusPill(r.status), true) +
				( r.resolved_by ? metaRow(i18n.lblResolved, resolvedBy, true) : '' ) +
				metaRow(i18n.lblRequested, whenAgo(r.created_at)) +
				tsRow(i18n.lblApproved, r.approved_at) +
				tsRow(i18n.lblRejected, r.rejected_at) +
				tsRow(i18n.lblExecuted, r.executed_at) +
				tsRow(i18n.lblFailedAt, r.failed_at) +
				tsRow(i18n.lblCancelled, r.cancelled_at) +
				'</tbody></table>';
			var reason = ( d.payload && d.payload.reason ) ? '<div class="wpcc-card-reason">' + escHtml(d.payload.reason) + '</div>' : '';
			var html = head + section( i18n.secRequest, meta + reason );

			// Change set — only when there is no diff to render. With a diff present
			// the two cards stated the same three facts (file count, +/- lines, path)
			// one above the other; the diff header already carries them, and carries
			// the actual change as well.
			var hasDiff = !! ( d.diff && ( d.diff.available || d.diff.diff_kind === 'patch_unavailable' ) );
			if ( d.change_set && ! hasDiff ) {
				var cs = d.change_set;
				var csBody = '<div class="wpcc-changeset">' +
					escHtml(cs.file_count) + ' ' + escHtml(i18n.lblFiles) +
					' &middot; <span class="wpcc-diff-add">+' + escHtml(cs.total_lines_added) + '</span> ' +
					'<span class="wpcc-diff-del">-' + escHtml(cs.total_lines_removed) + '</span>' +
					' &middot; ' + escHtml( (cs.affected_paths || []).join(', ') ) +
					( cs.has_high_risk_paths ? ' &middot; <span class="hi">' + escHtml(i18n.lblHighRisk) + '</span>' : '' ) +
					'</div>';
				html += section( i18n.secChangeset, csBody );
			}

			// Diff (server-rendered, escaped HTML injected as-is)
			if ( hasDiff ) {
				html += section( i18n.secDiff, '<div class="wpcc-diff-host"></div>' );
			}

			/*
			 * What will change — the whole point of this screen.
			 *
			 * The request details used to appear ONLY as raw JSON inside a collapsed
			 * <details> labelled "Request payload". Approving is an act of informed
			 * consent, and the information the consent rests on was hidden behind a
			 * developer word and then written in a developer format. A site owner
			 * should not have to read {"settings":{"blogname":"..."}} to find out
			 * that their site title is about to change.
			 *
			 * This renders the same payload as field -> value rows, in plain words,
			 * open by default. The raw JSON stays exactly one click away for anyone
			 * who wants it.
			 */
			var changeRows = summarisePayload( d.payload || {}, d.undo_target );
			if ( changeRows.length ) {
				var st = r.status || '';
				var changeHeading = i18n.secWhatChanges;
				if ( st === 'executed' ) { changeHeading = i18n.secWhatChanged; }
				else if ( st === 'rejected' || st === 'cancelled' || st === 'failed' ) { changeHeading = i18n.secWhatWouldHave; }
				html += section( changeHeading,
					'<table class="wpcc-whatchanges"><tbody>' + changeRows.map( function ( r ) {
						return '<tr' + ( r.tech ? ' class="wpcc-engineer-only"' : '' ) + '><th scope="row">' +
							escHtml( r.label ) + '</th><td>' + escHtml( r.value ) + '</td></tr>';
					} ).join('') + '</tbody></table>' );
			}

			/*
			 * Continuation. Only on a request that actually ran — a pending one
			 * already has its Approve/Reject controls, and offering "what next"
			 * before the decision would be answering a question nobody asked.
			 */
			if ( ( r.status || '' ) === 'executed' ) {
				var nextHtml = '<p class="wpcc-detail-done__lead">' + escHtml( i18n.doneTitle ) + '</p>' +
					'<p class="wpcc-detail-done__note">' + escHtml( i18n.doneUndo ) + '</p>' +
					'<p class="wpcc-detail-done__actions">' +
						'<a class="button button-primary" href="' + escHtml( i18n.changesUrl ) + '">' + escHtml( i18n.approvedLink ) + '</a> ' +
						// Only when this site actually has Built-in AI switched on — see hasBuiltinAi.
						( i18n.hasBuiltinAi ? '<a class="button" href="' + escHtml( i18n.builtinUrl ) + '">' + escHtml( i18n.doneBackAi ) + '</a> ' : '' ) +
						'<a class="button" href="' + escHtml( i18n.approvalsUrl ) + '">' + escHtml( i18n.doneMore ) + '</a>' +
					'</p>';
				html += '<div class="wpcc-detail-section wpcc-detail-done">' + nextHtml + '</div>';
			}

			// The raw request, collapsed, for anyone who wants the exact call.
			html += '<details class="wpcc-detail-raw"><summary>' + escHtml( i18n.secPayload ) + '</summary>' +
				'<div class="wpcc-payload"><pre>' + escHtml( JSON.stringify( d.payload || {}, null, 2 ) ) + '</pre></div></details>';

			// Queue
			if ( d.queue_items && d.queue_items.length ) {
				var qb = '<table class="widefat striped"><thead><tr><th>' + escHtml(i18n.colQueueId) + '</th><th>' +
					escHtml(i18n.colStatus) + '</th><th>' + escHtml(i18n.colAttempts) + '</th><th>' + escHtml(i18n.colError) + '</th><th></th></tr></thead><tbody>' +
					d.queue_items.map( function(q) {
						return '<tr><td><code>' + escHtml(String(q.queue_id).substring(0,8)) + '</code></td><td>' +
							statusPill(q.status, 'queue') + '</td><td>' + escHtml(q.attempts) + ' / ' + escHtml(q.max_attempts) + '</td><td>' +
							( q.error_message ? escHtml(q.error_message) : '—' ) + '</td><td>' + retryButton(q) + '</td></tr>';
					} ).join('') + '</tbody></table>';
				html += section( i18n.secQueue, qb );
			}

			// Results — rendered only once there ARE results. A request that has not
			// run yet cannot have an execution result, so a card announcing "no
			// execution result recorded yet" appeared on every single pending item.
			if ( d.results && d.results.length ) {
				html += section( i18n.secResults, d.results.map( function(res) {
					/*
					 * Four zeros beside "Applied" invite exactly the doubt this screen
					 * exists to remove. Not every operation reports per-item counts —
					 * an SEO field update changes one thing and counts none of it — so
					 * an all-zero row is the absence of a measurement, not a result of
					 * zero. Show the counts when there are any; otherwise let the
					 * outcome speak for itself. The raw numbers stay in Detailed.
					 */
					var total = ( res.created_count | 0 ) + ( res.updated_count | 0 ) +
						( res.skipped_count | 0 ) + ( res.error_count | 0 );
					var countsTxt = escHtml( fmt( i18n.counts, [ res.created_count, res.updated_count, res.skipped_count, res.error_count ] ) );
					var line = '<p>' + statusPill(res.status) + ' ' +
						( total > 0
							? countsTxt
							: '<span class="wpcc-engineer-only">' + countsTxt + '</span>' ) + '</p>';
					if ( res.error_json && res.error_json.length ) {
						line += '<pre class="wpcc-diff">' + escHtml( JSON.stringify( res.error_json, null, 2 ) ) + '</pre>';
					}
					return line;
				} ).join('') );
			}

			// Audit trail — same rule: shown when there is one.
			if ( d.audit && d.audit.length ) {
				html += section( i18n.secAudit, '<ul class="wpcc-audit-trail">' + d.audit.map( function(a) {
					return '<li><span class="wpcc-audit-when">' + escHtml( whenAgo(a.timestamp) ) + '</span>' +
						'<span class="wpcc-audit-action">' + escHtml( auditLabel( a.action ) ) + '</span>' +
						( a.actor ? '<span class="wpcc-audit-actor">' + escHtml(a.actor) + '</span>' : '' ) + '</li>';
				} ).join('') + '</ul>' );
			}

			box.innerHTML = html;
			// Bind Approve/Reject on the detail exactly as on a list row.
			attachPendingHandlers();

			// Inject the trusted, server-escaped diff HTML (never parsed client-side).
			if ( d.diff && ( d.diff.available || d.diff.diff_kind === 'patch_unavailable' ) ) {
				var host = box.querySelector('.wpcc-diff-host');
				if ( host ) { host.innerHTML = d.diff.html || ( '<p class="description">' + escHtml(i18n.diffUnavail) + '</p>' ); }
			}
		} ).catch( function() {
			box.innerHTML = '<div class="notice notice-error inline"><p>' + escHtml(i18n.loadFailed) + '</p></div>';
		} );
	}

	document.addEventListener('DOMContentLoaded', function() {
		initModal();
		initRetry();
		loadSummary();
		if ( detailId ) { loadDetail( detailId ); return; }
		if ( activeTab === 'pending' ) { loadPending(); }
		else if ( activeTab === 'history' ) { loadHistory( false ); }
		else if ( activeTab === 'queue' ) { loadQueue(); }
	});
})();
</script>
