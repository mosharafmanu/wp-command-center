<?php
/**
 * Settings › Recommendations (read + governed actions).
 *
 * Phase 2A of the Runtime migration: the deterministic recommendation findings that
 * were buried as cards + a table in the legacy Runtime dashboard get a real home here.
 * It SURFACES existing data only (RecommendationEngine::list() + count queries) — it
 * never invents findings — and preserves the actions that already exist in the engine:
 *   - Dismiss / Resolve a recommendation  → RecommendationEngine::transition()
 *   - Approve / Reject a suggested-fix plan → the exact wpcc_agent_plans + AuditLog +
 *     sync_plan_status flow relocated verbatim from the Runtime dashboard
 *   - Run a scan (non-destructive: generates findings, never patches/executes) → scan()
 * No REST route, capability, MCP tool, or schema is added. Honest empty state when
 * there are no findings. (Runtime keeps its own read-only copy until Phase 2B.)
 */

defined( 'ABSPATH' ) || exit;

global $wpdb;

$wpcc_rec_engine = new \WPCommandCenter\Recommendations\RecommendationEngine();
$wpcc_rec_table  = $wpdb->prefix . 'wpcc_recommendations';
$wpcc_plan_table = $wpdb->prefix . 'wpcc_agent_plans';
$wpcc_rec_actor  = [
	'type'       => 'admin',
	'user_id'    => get_current_user_id(),
	'user_login' => wp_get_current_user()->user_login,
];

$wpcc_rec_notice = '';
$wpcc_rec_error  = '';

// ── Governed actions (nonce + capability gated; reuse existing engine methods) ──
if ( isset( $_POST['wpcc_rec_action'] ) && check_admin_referer( 'wpcc_recommendations' ) && current_user_can( 'manage_options' ) ) {
	$wpcc_rec_act = sanitize_key( wp_unslash( $_POST['wpcc_rec_action'] ) );
	$wpcc_rec_id  = sanitize_text_field( wp_unslash( (string) ( $_POST['id'] ?? '' ) ) );

	if ( 'scan' === $wpcc_rec_act ) {
		$scan = $wpcc_rec_engine->scan( $wpcc_rec_actor );
		if ( is_wp_error( $scan ) ) {
			$wpcc_rec_error = $scan->get_error_message();
		} else {
			$wpcc_rec_notice = __( 'Scan complete. Findings are listed below.', 'wp-command-center' );
		}
	} elseif ( ( 'dismiss' === $wpcc_rec_act || 'resolve' === $wpcc_rec_act ) && '' !== $wpcc_rec_id ) {
		$status = 'dismiss' === $wpcc_rec_act ? 'dismissed' : 'resolved';
		$res    = $wpcc_rec_engine->transition( $wpcc_rec_id, $status, $wpcc_rec_actor );
		if ( is_wp_error( $res ) ) {
			$wpcc_rec_error = $res->get_error_message();
		} else {
			$wpcc_rec_notice = 'dismissed' === $status
				? __( 'Recommendation dismissed.', 'wp-command-center' )
				: __( 'Recommendation marked resolved.', 'wp-command-center' );
		}
	} elseif ( ( 'approve_plan' === $wpcc_rec_act || 'reject_plan' === $wpcc_rec_act ) && '' !== $wpcc_rec_id ) {
		// Relocated verbatim from the Runtime dashboard: update plan status, audit,
		// and sync the linked recommendation. Behavior preserved exactly.
		$new = 'approve_plan' === $wpcc_rec_act ? 'approved' : 'rejected';
		$wpdb->update( $wpcc_plan_table, [ 'status' => $new ], [ 'plan_id' => $wpcc_rec_id ] );
		( new \WPCommandCenter\Security\AuditLog() )->record( 'plan.' . $new, [ 'plan_id' => $wpcc_rec_id, 'actor' => $wpcc_rec_actor['user_login'] ] );
		if ( 'approved' === $new ) {
			$wpcc_rec_engine->sync_plan_status( $wpcc_rec_id, 'approved', $wpcc_rec_actor );
		}
		$wpcc_rec_notice = 'approved' === $new
			? __( 'Suggested fix approved.', 'wp-command-center' )
			: __( 'Suggested fix rejected.', 'wp-command-center' );
	}
}

// ── Read counts (same status vocabulary the engine uses; read-only) ──
$wpcc_rc = static function ( string $status, string $severity = '' ) use ( $wpdb, $wpcc_rec_table ): int {
	if ( '' !== $severity ) {
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpcc_rec_table} WHERE status = %s AND severity = %s", $status, $severity ) );
	}
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpcc_rec_table} WHERE status = %s", $status ) );
};
$wpcc_c_open     = $wpcc_rc( 'open' );
$wpcc_c_critical = $wpcc_rc( 'open', 'critical' );
$wpcc_c_resolved = $wpcc_rc( 'resolved' );

$wpcc_rec_list = $wpcc_rec_engine->list( [ 'limit' => 20 ] );

// Pending suggested-fix plans (awaiting approval).
$wpcc_pending_plans = $wpdb->get_results( "SELECT * FROM {$wpcc_plan_table} WHERE status = 'pending_review' ORDER BY created_at DESC LIMIT 10", ARRAY_A );

// Which statuses can dismiss/resolve (mirrors RecommendationEngine::transition()).
$wpcc_can_dismiss = static fn ( string $s ): bool => 'open' === $s;
$wpcc_can_resolve = static fn ( string $s ): bool => in_array( $s, [ 'open', 'converted_to_action', 'plan_created', 'approved', 'executing' ], true );
?>
<style>
	.wpcc-rec-wrap { max-width: 1000px; }
	.wpcc-rec-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 14px; margin: 14px 0 22px; }
	.wpcc-rec-card { background: #fff; border: 1px solid #c3c4c7; border-radius: 6px; padding: 14px 16px; text-align: center; }
	.wpcc-rec-card .v { font-size: 26px; font-weight: 700; color: #2271b1; line-height: 1.1; }
	.wpcc-rec-card.is-critical .v { color: #d63638; }
	.wpcc-rec-card .l { font-size: 12px; color: #646970; margin-top: 4px; text-transform: uppercase; letter-spacing: .4px; }
	.wpcc-rec-badge { display: inline-block; border-radius: 999px; padding: 2px 9px; font-size: 11px; font-weight: 700; text-transform: uppercase; background: #f0f0f1; color: #50575e; }
	.wpcc-rec-sev-critical, .wpcc-rec-sev-high { background: #fcf0f1; color: #b32d2e; }
	.wpcc-rec-sev-medium { background: #fcf9e8; color: #8a6500; }
	.wpcc-rec-sev-low, .wpcc-rec-sev-info { background: #f0f6fc; color: #2271b1; }
	.wpcc-rec-empty { text-align: center; padding: 28px 16px; color: #646970; background: #f6f7f7; border: 1px dashed #c3c4c7; border-radius: 6px; }
</style>

<div class="wpcc-rec-wrap">
	<h1><?php esc_html_e( 'Recommendations', 'wp-command-center' ); ?></h1>
	<p class="description" style="max-width:720px;">
		<?php esc_html_e( 'Things worth your attention on this site — detected by deterministic checks, never invented. Review a finding, dismiss it, or mark it resolved. Suggested fixes that need your sign-off appear at the bottom.', 'wp-command-center' ); ?>
	</p>
	<?php require WPCC_PLUGIN_DIR . 'includes/Admin/views/partials/trust-strip.php'; ?>

	<?php if ( $wpcc_rec_notice ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( $wpcc_rec_notice ); ?></p></div><?php endif; ?>
	<?php if ( $wpcc_rec_error ) : ?><div class="notice notice-error is-dismissible"><p><?php echo esc_html( $wpcc_rec_error ); ?></p></div><?php endif; ?>

	<div class="wpcc-rec-cards">
		<div class="wpcc-rec-card"><div class="v"><?php echo esc_html( (string) $wpcc_c_open ); ?></div><div class="l"><?php esc_html_e( 'Open', 'wp-command-center' ); ?></div></div>
		<div class="wpcc-rec-card is-critical"><div class="v"><?php echo esc_html( (string) $wpcc_c_critical ); ?></div><div class="l"><?php esc_html_e( 'Critical', 'wp-command-center' ); ?></div></div>
		<div class="wpcc-rec-card"><div class="v"><?php echo esc_html( (string) $wpcc_c_resolved ); ?></div><div class="l"><?php esc_html_e( 'Resolved', 'wp-command-center' ); ?></div></div>
	</div>

	<form method="post" style="margin:0 0 18px;">
		<?php wp_nonce_field( 'wpcc_recommendations' ); ?>
		<button type="submit" name="wpcc_rec_action" value="scan" class="button"><?php esc_html_e( 'Run a scan', 'wp-command-center' ); ?></button>
		<span class="description" style="margin-left:8px;"><?php esc_html_e( 'Re-checks this site for issues. Generates findings only — it never changes your site.', 'wp-command-center' ); ?></span>
	</form>

	<?php if ( empty( $wpcc_rec_list ) ) : ?>
		<div class="wpcc-rec-empty">
			<p style="margin:0 0 4px;font-size:14px;"><strong><?php esc_html_e( 'No recommendations yet.', 'wp-command-center' ); ?></strong></p>
			<p style="margin:0;"><?php esc_html_e( 'When a check finds something worth your attention, it appears here. Run a scan to check this site now.', 'wp-command-center' ); ?></p>
		</div>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead><tr>
				<th><?php esc_html_e( 'Recommendation', 'wp-command-center' ); ?></th>
				<th style="width:90px;"><?php esc_html_e( 'Severity', 'wp-command-center' ); ?></th>
				<th style="width:120px;"><?php esc_html_e( 'Status', 'wp-command-center' ); ?></th>
				<th style="width:180px;"><?php esc_html_e( 'Actions', 'wp-command-center' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $wpcc_rec_list as $rec ) :
				$rid    = (string) ( $rec['recommendation_id'] ?? '' );
				$status = (string) ( $rec['status'] ?? '' );
				$sev    = (string) ( $rec['severity'] ?? 'info' );
			?>
				<tr>
					<td>
						<strong><?php echo esc_html( (string) ( $rec['title'] ?? '' ) ); ?></strong>
						<br><small style="color:#646970;"><?php echo esc_html( (string) ( $rec['type'] ?? '' ) ); ?></small>
						<?php if ( ! empty( $rec['description'] ) ) : ?>
							<br><span style="color:#50575e;font-size:13px;"><?php echo esc_html( (string) $rec['description'] ); ?></span>
						<?php endif; ?>
					</td>
					<td><span class="wpcc-rec-badge wpcc-rec-sev-<?php echo esc_attr( $sev ); ?>"><?php echo esc_html( $sev ); ?></span></td>
					<td><span class="wpcc-rec-badge"><?php echo esc_html( str_replace( '_', ' ', $status ) ); ?></span></td>
					<td>
						<?php if ( $rid && ( $wpcc_can_dismiss( $status ) || $wpcc_can_resolve( $status ) ) ) : ?>
							<form method="post" style="display:inline;">
								<?php wp_nonce_field( 'wpcc_recommendations' ); ?>
								<input type="hidden" name="id" value="<?php echo esc_attr( $rid ); ?>">
								<?php if ( $wpcc_can_resolve( $status ) ) : ?>
									<button type="submit" name="wpcc_rec_action" value="resolve" class="button button-small"><?php esc_html_e( 'Resolve', 'wp-command-center' ); ?></button>
								<?php endif; ?>
								<?php if ( $wpcc_can_dismiss( $status ) ) : ?>
									<button type="submit" name="wpcc_rec_action" value="dismiss" class="button button-small"><?php esc_html_e( 'Dismiss', 'wp-command-center' ); ?></button>
								<?php endif; ?>
							</form>
						<?php else : ?>
							<span class="description">—</span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<h2 style="margin-top:26px;"><?php esc_html_e( 'Suggested fixes awaiting your approval', 'wp-command-center' ); ?></h2>
	<?php if ( empty( $wpcc_pending_plans ) ) : ?>
		<div class="wpcc-rec-empty">
			<p style="margin:0;"><?php esc_html_e( 'Nothing waiting. When a recommendation produces a fix that needs your sign-off, it appears here.', 'wp-command-center' ); ?></p>
		</div>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead><tr><th><?php esc_html_e( 'Title', 'wp-command-center' ); ?></th><th><?php esc_html_e( 'Objective', 'wp-command-center' ); ?></th><th style="width:180px;"><?php esc_html_e( 'Actions', 'wp-command-center' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $wpcc_pending_plans as $plan ) : ?>
				<tr>
					<td><strong><?php echo esc_html( (string) ( $plan['title'] ?? '' ) ); ?></strong></td>
					<td><?php echo esc_html( (string) ( $plan['objective'] ?? '' ) ); ?></td>
					<td>
						<form method="post" style="display:inline;">
							<?php wp_nonce_field( 'wpcc_recommendations' ); ?>
							<input type="hidden" name="id" value="<?php echo esc_attr( (string) $plan['plan_id'] ); ?>">
							<button type="submit" name="wpcc_rec_action" value="approve_plan" class="button button-primary button-small"><?php esc_html_e( 'Approve', 'wp-command-center' ); ?></button>
							<button type="submit" name="wpcc_rec_action" value="reject_plan" class="button button-small"><?php esc_html_e( 'Reject', 'wp-command-center' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
