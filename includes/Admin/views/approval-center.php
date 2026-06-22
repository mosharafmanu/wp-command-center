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

$security_mode = \WPCommandCenter\Operations\SecurityModeManager::label();
$human_only    = \WPCommandCenter\Operations\SecurityModeManager::requires_human_approver();
$nonce         = wp_create_nonce( 'wp_rest' );
$api_base      = rest_url( 'wp-command-center/v1/admin' );

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab nav, no state change.
$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'pending';
$tabs       = [ 'pending', 'history', 'queue' ];
if ( ! in_array( $active_tab, $tabs, true ) ) {
	$active_tab = 'pending';
}
$base_url = admin_url( 'admin.php?page=wpcc-approval-center' );

// STEP 106.2 — single-request detail view (?view=<request_id>). UUID-shaped only.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only detail nav, no state change.
$detail_id = isset( $_GET['view'] ) ? sanitize_text_field( wp_unslash( $_GET['view'] ) ) : '';
if ( ! preg_match( '/^[a-f0-9-]{36}$/', $detail_id ) ) {
	$detail_id = '';
}
?>
<div class="wrap wpcc-wrap">
	<h1><?php esc_html_e( 'Approval Center', 'wp-command-center' ); ?>
		<span id="wpcc-pending-badge" style="display:none;margin-left:8px;background:var(--wpcc-red-600);color:var(--wpcc-white);font-size:12px;border-radius:10px;padding:2px 8px;vertical-align:middle;"></span>
	</h1>

	<p class="description">
		<?php printf(
			/* translators: %s: security mode label */
			esc_html__( 'Security mode: %s. Review AI-requested operations, inspect the full approval history, and watch the execution queue.', 'wp-command-center' ),
			'<strong>' . esc_html( $security_mode ) . '</strong>'
		); ?>
		<?php if ( $human_only ) : ?>
			<br><span class="dashicons dashicons-lock" style="color:var(--wpcc-gray-600);" aria-hidden="true"></span>
			<?php esc_html_e( 'A WordPress administrator must approve gated operations — API tokens cannot self-approve in this mode.', 'wp-command-center' ); ?>
		<?php endif; ?>
	</p>

	<div id="wpcc-approval-summary" class="wpcc-summary-bar" aria-live="polite"></div>

	<?php if ( '' !== $detail_id ) : ?>
	<p><a href="<?php echo esc_url( $base_url . '&tab=history' ); ?>" class="button">&larr; <?php esc_html_e( 'Back to Approval Center', 'wp-command-center' ); ?></a></p>
	<div id="wpcc-detail" data-id="<?php echo esc_attr( $detail_id ); ?>">
		<p><span class="spinner is-active" style="float:none;margin:0 6px 0 0;vertical-align:middle;"></span><?php esc_html_e( 'Loading…', 'wp-command-center' ); ?></p>
	</div>
	<?php else : ?>

	<h2 class="nav-tab-wrapper">
		<a href="<?php echo esc_url( $base_url . '&tab=pending' ); ?>" class="nav-tab <?php echo 'pending' === $active_tab ? 'nav-tab-active' : ''; ?>"<?php echo 'pending' === $active_tab ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'Pending', 'wp-command-center' ); ?></a>
		<a href="<?php echo esc_url( $base_url . '&tab=history' ); ?>" class="nav-tab <?php echo 'history' === $active_tab ? 'nav-tab-active' : ''; ?>"<?php echo 'history' === $active_tab ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'History', 'wp-command-center' ); ?></a>
		<a href="<?php echo esc_url( $base_url . '&tab=queue' ); ?>" class="nav-tab <?php echo 'queue' === $active_tab ? 'nav-tab-active' : ''; ?>"<?php echo 'queue' === $active_tab ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'Queue', 'wp-command-center' ); ?></a>
	</h2>

	<div id="wpcc-tab-pending" class="wpcc-tab" style="<?php echo 'pending' === $active_tab ? '' : 'display:none;'; ?>">
		<div id="wpcc-approvals-list">
			<p><span class="spinner is-active" style="float:none;margin:0 6px 0 0;vertical-align:middle;"></span><?php esc_html_e( 'Loading…', 'wp-command-center' ); ?></p>
		</div>
	</div>

	<div id="wpcc-tab-history" class="wpcc-tab" style="<?php echo 'history' === $active_tab ? '' : 'display:none;'; ?>">
		<div id="wpcc-history-list">
			<p><span class="spinner is-active" style="float:none;margin:0 6px 0 0;vertical-align:middle;"></span><?php esc_html_e( 'Loading…', 'wp-command-center' ); ?></p>
		</div>
	</div>

	<div id="wpcc-tab-queue" class="wpcc-tab" style="<?php echo 'queue' === $active_tab ? '' : 'display:none;'; ?>">
		<div id="wpcc-queue-list">
			<p><span class="spinner is-active" style="float:none;margin:0 6px 0 0;vertical-align:middle;"></span><?php esc_html_e( 'Loading…', 'wp-command-center' ); ?></p>
		</div>
	</div>
	<?php endif; ?>

	<div id="wpcc-confirm-modal" class="wpcc-modal-backdrop" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="wpcc-confirm-title" aria-describedby="wpcc-confirm-warning">
		<div class="wpcc-modal">
			<h2 id="wpcc-confirm-title"><?php esc_html_e( 'Confirm destructive approval', 'wp-command-center' ); ?></h2>
			<div id="wpcc-confirm-warning" class="wpcc-card-destructive" role="alert"></div>
			<p>
				<label for="wpcc-confirm-phrase"><?php esc_html_e( 'Type the confirmation phrase to proceed:', 'wp-command-center' ); ?> <code id="wpcc-confirm-phrase-hint"></code></label><br>
				<input type="text" id="wpcc-confirm-phrase" class="regular-text" autocomplete="off">
			</p>
			<p>
				<label for="wpcc-confirm-reason"><?php esc_html_e( 'Reason (required):', 'wp-command-center' ); ?></label><br>
				<textarea id="wpcc-confirm-reason" rows="2" class="large-text"></textarea>
			</p>
			<p>
				<button type="button" class="button button-primary wpcc-reject-btn" id="wpcc-confirm-go" disabled><?php esc_html_e( 'Approve &amp; Run', 'wp-command-center' ); ?></button>
				<button type="button" class="button" id="wpcc-confirm-cancel"><?php esc_html_e( 'Cancel', 'wp-command-center' ); ?></button>
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
.wpcc-approval-card { background:var(--wpcc-white); border:1px solid var(--wpcc-gray-100); border-left:4px solid var(--wpcc-gray-500); border-radius:4px; padding:16px 20px; margin:12px 0; max-width:820px; box-shadow:0 1px 3px rgba(0,0,0,.06); }
.wpcc-approval-card.risk-critical { border-left-color:var(--wpcc-risk-critical-fg); }
.wpcc-approval-card.risk-high { border-left-color:var(--wpcc-risk-high-fg); }
.wpcc-approval-card.risk-medium { border-left-color:var(--wpcc-risk-medium-fg); }
.wpcc-approval-card.risk-low { border-left-color:var(--wpcc-risk-low-fg); }
.wpcc-approval-card.risk-diagnostic { border-left-color:var(--wpcc-risk-diagnostic-fg); }
.wpcc-risk-badge { display:inline-block;font-size:11px;font-weight:600;padding:2px 8px;border-radius:3px;color:var(--wpcc-white);text-transform:uppercase; }
.wpcc-risk-badge.risk-critical { background:var(--wpcc-risk-critical-fg); }
.wpcc-risk-badge.risk-high { background:var(--wpcc-risk-high-fg); }
.wpcc-risk-badge.risk-medium { background:var(--wpcc-risk-medium-fg); }
.wpcc-risk-badge.risk-low { background:var(--wpcc-risk-low-fg); }
.wpcc-risk-badge.risk-diagnostic { background:var(--wpcc-risk-diagnostic-fg); }
.wpcc-card-header { display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:10px; }
.wpcc-card-title { font-size:15px;font-weight:600;margin:0; }
.wpcc-card-meta { font-size:13px;color:var(--wpcc-gray-700);margin:6px 0; }
.wpcc-card-meta td { padding:3px 12px 3px 0;vertical-align:top; }
.wpcc-card-meta td:first-child { font-weight:500;white-space:nowrap; }
.wpcc-card-reason { background:var(--wpcc-gray-0);border-left:3px solid var(--wpcc-gray-100);padding:8px 12px;margin:10px 0;font-size:13px;color:var(--wpcc-gray-700);font-style:italic; }
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
		readOnly:    <?php echo wp_json_encode( __( 'Read Only', 'wp-command-center' ) ); ?>,
		approve:     <?php echo wp_json_encode( __( 'Approve', 'wp-command-center' ) ); ?>,
		reject:      <?php echo wp_json_encode( __( 'Reject', 'wp-command-center' ) ); ?>,
		approved:    <?php echo wp_json_encode( __( 'Approved and executed.', 'wp-command-center' ) ); ?>,
		rejected:    <?php echo wp_json_encode( __( 'Rejected.', 'wp-command-center' ) ); ?>,
		approvedErr: <?php echo wp_json_encode( __( 'Approved but execution failed: ', 'wp-command-center' ) ); ?>,
		unknownErr:  <?php echo wp_json_encode( __( 'Unknown error.', 'wp-command-center' ) ); ?>,
		reqFailed:   <?php echo wp_json_encode( __( 'Request failed. Please try again.', 'wp-command-center' ) ); ?>,
		destructive: <?php echo wp_json_encode( __( 'DESTRUCTIVE — this permanently deletes data and cannot be undone.', 'wp-command-center' ) ); ?>,
		auditNote:   <?php echo wp_json_encode( __( 'This action will be logged in the audit trail.', 'wp-command-center' ) ); ?>,
		noPending:   <?php echo wp_json_encode( __( 'No pending approvals. When an AI agent requests a write operation, the approval cards will appear here.', 'wp-command-center' ) ); ?>,
		noHistory:   <?php echo wp_json_encode( __( 'No resolved requests yet.', 'wp-command-center' ) ); ?>,
		noQueue:     <?php echo wp_json_encode( __( 'The execution queue is empty.', 'wp-command-center' ) ); ?>,
		loadFailed:  <?php echo wp_json_encode( __( 'Failed to load.', 'wp-command-center' ) ); ?>,
		loadMore:    <?php echo wp_json_encode( __( 'Load more', 'wp-command-center' ) ); ?>,
		colOp:       <?php echo wp_json_encode( __( 'Operation', 'wp-command-center' ) ); ?>,
		colAction:   <?php echo wp_json_encode( __( 'Action', 'wp-command-center' ) ); ?>,
		colRisk:     <?php echo wp_json_encode( __( 'Risk', 'wp-command-center' ) ); ?>,
		colStatus:   <?php echo wp_json_encode( __( 'Status', 'wp-command-center' ) ); ?>,
		colResolved: <?php echo wp_json_encode( __( 'Resolved by', 'wp-command-center' ) ); ?>,
		colWhen:     <?php echo wp_json_encode( __( 'Requested', 'wp-command-center' ) ); ?>,
		colQueueId:  <?php echo wp_json_encode( __( 'Queue item', 'wp-command-center' ) ); ?>,
		colAttempts: <?php echo wp_json_encode( __( 'Attempts', 'wp-command-center' ) ); ?>,
		colError:    <?php echo wp_json_encode( __( 'Error', 'wp-command-center' ) ); ?>,
		unavailable: <?php echo wp_json_encode( __( 'unavailable', 'wp-command-center' ) ); ?>,
		chipPending: <?php echo wp_json_encode( __( 'Pending', 'wp-command-center' ) ); ?>,
		chipCrit:    <?php echo wp_json_encode( __( 'Critical pending', 'wp-command-center' ) ); ?>,
		chipResolved:<?php echo wp_json_encode( __( 'Resolved (all-time)', 'wp-command-center' ) ); ?>,
		chipFailed:  <?php echo wp_json_encode( __( 'Failed in queue', 'wp-command-center' ) ); ?>,
		details:     <?php echo wp_json_encode( __( 'Details', 'wp-command-center' ) ); ?>,
		secReason:   <?php echo wp_json_encode( __( 'Reason', 'wp-command-center' ) ); ?>,
		secRequest:  <?php echo wp_json_encode( __( 'Request', 'wp-command-center' ) ); ?>,
		secChangeset:<?php echo wp_json_encode( __( 'Change set', 'wp-command-center' ) ); ?>,
		secDiff:     <?php echo wp_json_encode( __( 'Diff', 'wp-command-center' ) ); ?>,
		secPayload:  <?php echo wp_json_encode( __( 'Request payload', 'wp-command-center' ) ); ?>,
		secQueue:    <?php echo wp_json_encode( __( 'Queue', 'wp-command-center' ) ); ?>,
		secResults:  <?php echo wp_json_encode( __( 'Execution result', 'wp-command-center' ) ); ?>,
		secAudit:    <?php echo wp_json_encode( __( 'Audit trail', 'wp-command-center' ) ); ?>,
		lblResolved: <?php echo wp_json_encode( __( 'Resolved by', 'wp-command-center' ) ); ?>,
		lblRequested:<?php echo wp_json_encode( __( 'Requested', 'wp-command-center' ) ); ?>,
		lblApproved: <?php echo wp_json_encode( __( 'Approved', 'wp-command-center' ) ); ?>,
		lblRejected: <?php echo wp_json_encode( __( 'Rejected', 'wp-command-center' ) ); ?>,
		lblExecuted: <?php echo wp_json_encode( __( 'Executed', 'wp-command-center' ) ); ?>,
		lblFailedAt: <?php echo wp_json_encode( __( 'Failed', 'wp-command-center' ) ); ?>,
		lblCancelled:<?php echo wp_json_encode( __( 'Cancelled', 'wp-command-center' ) ); ?>,
		lblFiles:    <?php echo wp_json_encode( __( 'files', 'wp-command-center' ) ); ?>,
		lblHighRisk: <?php echo wp_json_encode( __( 'HIGH-RISK paths included', 'wp-command-center' ) ); ?>,
		diffUnavail: <?php echo wp_json_encode( __( 'No diff stored for this request.', 'wp-command-center' ) ); ?>,
		noResult:    <?php echo wp_json_encode( __( 'No execution result recorded yet.', 'wp-command-center' ) ); ?>,
		noAudit:     <?php echo wp_json_encode( __( 'No audit events recorded for this request.', 'wp-command-center' ) ); ?>,
		notFound:    <?php echo wp_json_encode( __( 'Request not found.', 'wp-command-center' ) ); ?>,
		counts:      <?php echo wp_json_encode( __( 'Created %1$s · Updated %2$s · Skipped %3$s · Errors %4$s', 'wp-command-center' ) ); ?>,
		retry:       <?php echo wp_json_encode( __( 'Retry', 'wp-command-center' ) ); ?>,
		retrying:    <?php echo wp_json_encode( __( 'Retrying…', 'wp-command-center' ) ); ?>,
		retryConfirm:<?php echo wp_json_encode( __( 'Re-queue this failed item for another attempt?', 'wp-command-center' ) ); ?>,
		retryFailed: <?php echo wp_json_encode( __( 'Retry failed: ', 'wp-command-center' ) ); ?>,
		nonceExpired:<?php echo wp_json_encode( __( 'Your session expired. Please reload the page and try again.', 'wp-command-center' ) ); ?>,
		detailsFor:  <?php echo wp_json_encode( __( 'View details for this request', 'wp-command-center' ) ); ?>
	};

	var riskLabels = {
		critical:   <?php echo wp_json_encode( __( 'Critical', 'wp-command-center' ) ); ?>,
		high:       <?php echo wp_json_encode( __( 'High Risk', 'wp-command-center' ) ); ?>,
		medium:     <?php echo wp_json_encode( __( 'Medium Risk', 'wp-command-center' ) ); ?>,
		low:        <?php echo wp_json_encode( __( 'Low Risk', 'wp-command-center' ) ); ?>,
		diagnostic: i18n.readOnly
	};
	var statusLabels = {
		pending_review: <?php echo wp_json_encode( __( 'Pending', 'wp-command-center' ) ); ?>,
		approved:       <?php echo wp_json_encode( __( 'Approved', 'wp-command-center' ) ); ?>,
		rejected:       <?php echo wp_json_encode( __( 'Rejected', 'wp-command-center' ) ); ?>,
		executed:       <?php echo wp_json_encode( __( 'Executed', 'wp-command-center' ) ); ?>,
		failed:         <?php echo wp_json_encode( __( 'Failed', 'wp-command-center' ) ); ?>,
		cancelled:      <?php echo wp_json_encode( __( 'Cancelled', 'wp-command-center' ) ); ?>,
		queued:         <?php echo wp_json_encode( __( 'Queued', 'wp-command-center' ) ); ?>,
		running:        <?php echo wp_json_encode( __( 'Running', 'wp-command-center' ) ); ?>,
		completed:      <?php echo wp_json_encode( __( 'Completed', 'wp-command-center' ) ); ?>
	};
	function statusLabel( s ) { return statusLabels[ s ] || s; }

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

	function escHtml( s ) {
		var d = document.createElement('div');
		d.appendChild( document.createTextNode( String( s == null ? '' : s ) ) );
		return d.innerHTML;
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
			var el = document.getElementById('wpcc-approval-summary');
			if ( ! el || ! data.summary ) { return; }
			var s = data.summary;
			el.innerHTML =
				chip( i18n.chipPending, s.pending, false ) +
				chip( i18n.chipCrit, s.pending_critical, s.pending_critical > 0 ) +
				chip( i18n.chipResolved, s.resolved, false ) +
				chip( i18n.chipFailed, s.queue_failed, s.queue_failed > 0 );
			updateBadge( s.pending );
		} ).catch( function() {} );
	}
	function chip( label, value, alert ) {
		return '<div class="wpcc-summary-chip' + ( alert ? ' alert' : '' ) + '"><strong>' + escHtml( value ) + '</strong>' + escHtml( label ) + '</div>';
	}
	function updateBadge( count ) {
		var badge = document.getElementById('wpcc-pending-badge');
		if ( ! badge ) { return; }
		if ( count > 0 ) { badge.textContent = count; badge.style.display = 'inline'; }
		else { badge.style.display = 'none'; }
	}

	// ── Pending tab (approve/reject retained) ──
	function renderCard( req ) {
		var risk  = req.risk_level || 'medium';
		var label = riskLabels[ risk ] || risk;
		var reasonHtml = req.reason ? '<div class="wpcc-card-reason">' + escHtml( req.reason ) + '</div>' : '';
		var destructiveHtml = req.destructive
			? '<div class="wpcc-card-destructive">&#9888; ' + escHtml( i18n.destructive ) + ( req.destructive_warning ? ' ' + escHtml( req.destructive_warning ) : '' ) + '</div>'
			: '';
		return '<div class="wpcc-approval-card risk-' + escHtml(risk) + '" id="wpcc-card-' + escHtml(req.request_id) + '">' +
			'<div class="wpcc-card-header"><span class="wpcc-card-title">' + escHtml(req.operation) + '</span>' +
			'<span class="wpcc-risk-badge risk-' + escHtml(risk) + '">' + escHtml(label) + '</span></div>' +
			destructiveHtml +
			'<table class="wpcc-card-meta"><tbody>' +
			'<tr><td>' + escHtml(i18n.colAction) + '</td><td>' + escHtml(req.action || '—') + '</td></tr>' +
			'<tr><td>' + escHtml(i18n.colWhen) + '</td><td>' + escHtml(req.created_ago) + '</td></tr>' +
			'</tbody></table>' + reasonHtml +
			'<p style="font-size:12px;color:#8c8f94;margin:6px 0;">&#10003; ' + escHtml(i18n.auditNote) + '</p>' +
			'<div class="wpcc-card-actions">' +
			'<button class="button button-primary wpcc-approve-btn" data-id="' + escHtml(req.request_id) + '" data-action="approve">&#10003; ' + escHtml(i18n.approve) + '</button> ' +
			'<button class="button wpcc-reject-btn" data-id="' + escHtml(req.request_id) + '" data-action="reject">&#10007; ' + escHtml(i18n.reject) + '</button> ' +
			'<a class="wpcc-detail-link" href="' + escHtml(baseUrl) + '&view=' + escHtml(req.request_id) + '">' + escHtml(i18n.details) + ' &rarr;</a>' +
			'</div>' +
			'<div class="wpcc-card-result" id="wpcc-result-' + escHtml(req.request_id) + '" role="status" aria-live="polite" style="display:none;"></div>' +
		'</div>';
	}
	function loadPending() {
		apiFetch( '/approvals' ).then( function( data ) {
			var list = document.getElementById('wpcc-approvals-list');
			if ( ! list ) { return; }
			if ( ! data.requests || data.requests.length === 0 ) {
				list.innerHTML = '<div class="notice notice-info inline" style="max-width:820px;"><p>' + escHtml(i18n.noPending) + '</p></div>';
				return;
			}
			list.innerHTML = data.requests.map( renderCard ).join('');
			attachPendingHandlers();
		} ).catch( function() {
			var list = document.getElementById('wpcc-approvals-list');
			if ( list ) { list.innerHTML = '<div class="notice notice-error inline"><p>' + escHtml(i18n.loadFailed) + '</p></div>'; }
		} );
	}
	function showResult( result, msg, cls ) {
		if ( ! result ) { return; }
		result.textContent = msg;
		result.className = 'wpcc-card-result ' + cls;
		result.style.display = 'block';
	}
	function postAction( id, action, body ) {
		var opts = { method: 'POST' };
		if ( body ) { opts.body = JSON.stringify( body ); }
		return apiFetch( '/approvals/' + id + '/' + action, opts );
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
				var msg = data.error ? ( i18n.approvedErr + data.error ) : i18n.approved;
				showResult( ctx.result, msg, 'success' );
				if ( ctx.card ) { ctx.card.style.opacity = '0.6'; }
				loadSummary();
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
						loadSummary();
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
			if ( ! window.confirm( i18n.retryConfirm ) ) { return; }
			var qid = btn.dataset.queueId;
			btn.disabled = true; btn.textContent = i18n.retrying;
			apiFetch( '/approvals/queue/' + qid + '/retry', { method: 'POST' } ).then( function(d) {
				if ( d && d.success ) {
					loadSummary();
					if ( detailId ) { loadDetail( detailId ); }
					else { loadQueue(); }
				} else {
					window.alert( i18n.retryFailed + actionError( d ) );
					btn.disabled = false; btn.textContent = i18n.retry;
				}
			} ).catch( function() {
				window.alert( i18n.retryFailed + i18n.reqFailed );
				btn.disabled = false; btn.textContent = i18n.retry;
			} );
		} );
	}

	// ── History tab ──
	var historyOffset = 0;
	function statusPill( status ) {
		return '<span class="wpcc-status-pill ' + escHtml(status) + '">' + escHtml( statusLabel(status) ) + '</span>';
	}
	function riskBadge( risk ) {
		return '<span class="wpcc-risk-badge risk-' + escHtml(risk) + '">' + escHtml( riskLabels[risk] || risk ) + '</span>';
	}
	function historyRow( r ) {
		return '<tr>' +
			'<td><a href="' + escHtml(baseUrl) + '&view=' + escHtml(r.request_id) + '" aria-label="' + escHtml(i18n.detailsFor) + ': ' + escHtml(r.operation) + '">' + escHtml(r.operation) + '</a></td>' +
			'<td>' + escHtml(r.action || '—') + '</td>' +
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
			'<td>' + escHtml(q.operation_id) + '</td>' +
			'<td>' + statusPill(q.status) + '</td>' +
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
	function metaRow( label, value, raw ) {
		if ( value === null || value === undefined || value === '' ) { return ''; }
		return '<tr><td>' + escHtml(label) + '</td><td>' + ( raw ? value : escHtml(value) ) + '</td></tr>';
	}
	// Lifecycle-timestamp row: render only when the timestamp is actually set,
	// so a successful request does not show empty "Rejected —/Failed —" rows.
	function tsRow( label, ts ) { return ts ? metaRow( label, whenAgo(ts) ) : ''; }
	function loadDetail( id ) {
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

			var head = '<div class="wpcc-detail-head">' +
				'<span class="wpcc-detail-title">' + escHtml(r.operation) + '</span>' +
				statusPill(r.status) + riskBadge(risk) + '</div>';

			var meta = '<table class="wpcc-detail-meta"><tbody>' +
				metaRow(i18n.colAction, r.action || '—') +
				metaRow(i18n.colStatus, statusPill(r.status), true) +
				metaRow(i18n.lblResolved, resolvedBy, true) +
				metaRow(i18n.lblRequested, whenAgo(r.created_at)) +
				tsRow(i18n.lblApproved, r.approved_at) +
				tsRow(i18n.lblRejected, r.rejected_at) +
				tsRow(i18n.lblExecuted, r.executed_at) +
				tsRow(i18n.lblFailedAt, r.failed_at) +
				tsRow(i18n.lblCancelled, r.cancelled_at) +
				'</tbody></table>';
			var reason = ( d.payload && d.payload.reason ) ? '<div class="wpcc-card-reason">' + escHtml(d.payload.reason) + '</div>' : '';
			var html = head + section( i18n.secRequest, meta + reason );

			// Change set
			if ( d.change_set ) {
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
			if ( d.diff && ( d.diff.available || d.diff.diff_kind === 'patch_unavailable' ) ) {
				html += section( i18n.secDiff, '<div class="wpcc-diff-host"></div>' );
			}

			// Payload
			html += section( i18n.secPayload, '<div class="wpcc-payload"><pre>' + escHtml( JSON.stringify( d.payload || {}, null, 2 ) ) + '</pre></div>' );

			// Queue
			if ( d.queue_items && d.queue_items.length ) {
				var qb = '<table class="widefat striped"><thead><tr><th>' + escHtml(i18n.colQueueId) + '</th><th>' +
					escHtml(i18n.colStatus) + '</th><th>' + escHtml(i18n.colAttempts) + '</th><th>' + escHtml(i18n.colError) + '</th><th></th></tr></thead><tbody>' +
					d.queue_items.map( function(q) {
						return '<tr><td><code>' + escHtml(String(q.queue_id).substring(0,8)) + '</code></td><td>' +
							statusPill(q.status) + '</td><td>' + escHtml(q.attempts) + ' / ' + escHtml(q.max_attempts) + '</td><td>' +
							( q.error_message ? escHtml(q.error_message) : '—' ) + '</td><td>' + retryButton(q) + '</td></tr>';
					} ).join('') + '</tbody></table>';
				html += section( i18n.secQueue, qb );
			}

			// Results
			var rb;
			if ( d.results && d.results.length ) {
				rb = d.results.map( function(res) {
					var line = '<p>' + statusPill(res.status) + ' ' +
						escHtml( fmt( i18n.counts, [ res.created_count, res.updated_count, res.skipped_count, res.error_count ] ) ) + '</p>';
					if ( res.error_json && res.error_json.length ) {
						line += '<pre class="wpcc-diff">' + escHtml( JSON.stringify( res.error_json, null, 2 ) ) + '</pre>';
					}
					return line;
				} ).join('');
			} else {
				rb = '<p class="description">' + escHtml(i18n.noResult) + '</p>';
			}
			html += section( i18n.secResults, rb );

			// Audit trail
			var ab;
			if ( d.audit && d.audit.length ) {
				ab = '<ul class="wpcc-audit-trail">' + d.audit.map( function(a) {
					return '<li><span class="wpcc-audit-when">' + escHtml( whenAgo(a.timestamp) ) + '</span>' +
						'<span class="wpcc-audit-action">' + escHtml(a.action) + '</span>' +
						( a.actor ? '<span class="wpcc-audit-actor">' + escHtml(a.actor) + '</span>' : '' ) + '</li>';
				} ).join('') + '</ul>';
			} else {
				ab = '<p class="description">' + escHtml(i18n.noAudit) + '</p>';
			}
			html += section( i18n.secAudit, ab );

			box.innerHTML = html;

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
