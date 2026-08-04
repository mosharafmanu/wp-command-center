<?php
/**
 * PROGRAM-10 — Live Operations Center (read-only; existing data only).
 *
 * Answers: what needs attention? · what happened? · what can I review? · what can
 * I undo? — from real telemetry/audit/approval/change-history data. Never
 * fabricates jobs, cost, tokens, or running states; shows "unknown"/"not tracked
 * yet" where data is unavailable. No writes, no runtime change, no new routes.
 */

defined( 'ABSPATH' ) || exit;

use WPCommandCenter\Admin\OperationsCenterQuery;

$wpcc_oc      = new OperationsCenterQuery();
$wpcc_attn    = $wpcc_oc->needs_attention();
$wpcc_tl      = $wpcc_oc->timeline( 20 );
$wpcc_status  = $wpcc_oc->status_rollup();
$wpcc_rev     = $wpcc_oc->reversible( 8 );
$wpcc_honest  = $wpcc_oc->honesty();

$wpcc_links = [
	'approvals' => admin_url( 'admin.php?page=wpcc-activity&wpcc_tab=approvals' ),
	'changes'   => admin_url( 'admin.php?page=wpcc-history&wpcc_tab=changes' ),
	'sessions'  => admin_url( 'admin.php?page=wpcc-history&wpcc_tab=changes&tab=sessions' ),
];

/** Status → [label, color] (honest; only known statuses). */
$wpcc_status_meta = static function ( string $s ): array {
	switch ( $s ) {
		case 'completed': return [ __( 'Completed', 'ai-command-center' ), '#0a7a33' ];
		case 'failed':    return [ __( 'Failed', 'ai-command-center' ), '#d63638' ];
		case 'running':   return [ __( 'Running', 'ai-command-center' ), '#2271b1' ];
		case 'cancelled': return [ __( 'Cancelled', 'ai-command-center' ), '#646970' ];
		default:          return [ __( 'Recorded', 'ai-command-center' ), '#8a6a00' ];
	}
};
$wpcc_dur = static function ( $ms ): string {
	if ( null === $ms ) { return __( 'unknown', 'ai-command-center' ); }
	$ms = (int) $ms;
	return $ms >= 1000 ? sprintf( '%.1fs', $ms / 1000 ) : ( $ms . ' ms' );
};
?>
<style>
.wpcc-oc { max-width: 1100px; }
.wpcc-oc h2 { font-size: 16px; margin: 26px 0 8px; }
.wpcc-oc .muted { color:#646970; }
.wpcc-oc-hero { display:flex; justify-content:space-between; gap:18px; flex-wrap:wrap; align-items:center; background:linear-gradient(135deg,#1d2734,#2c3a4f); color:#e8edf3; border-radius:12px; padding:20px 24px; margin:6px 0 18px; }
.wpcc-oc-hero h1 { color:#fff; margin:0 0 4px; font-size:21px; }
.wpcc-oc-hero p { margin:0; color:#b9c4d2; font-size:13px; max-width:560px; }
.wpcc-oc-pills { display:flex; gap:10px; flex-wrap:wrap; }
.wpcc-oc-pill { background:rgba(255,255,255,.08); border-radius:9px; padding:8px 14px; text-align:center; min-width:78px; }
.wpcc-oc-pill .v { font-size:20px; font-weight:700; color:#fff; line-height:1.1; }
.wpcc-oc-pill .l { font-size:11px; color:#b9c4d2; text-transform:uppercase; letter-spacing:.4px; }
.wpcc-oc-attn { background:#fef0f0; border:1px solid #f0a8a8; border-left:4px solid #d63638; border-radius:10px; padding:14px 16px; margin:10px 0; }
.wpcc-oc-clear { background:#f2fbf5; border:1px solid #b6e3c5; border-left:4px solid #00a32a; border-radius:10px; padding:14px 16px; margin:10px 0; font-size:13px; }
.wpcc-oc-card { background:#fff; border:1px solid #dcdfe3; border-radius:12px; padding:14px 16px; }
.wpcc-oc-row { display:flex; gap:10px; align-items:baseline; padding:7px 0; border-bottom:1px solid #f3f4f6; font-size:13px; }
.wpcc-oc-row:last-child { border-bottom:0; }
.wpcc-oc-badge { font-size:11px; font-weight:700; padding:1px 8px; border-radius:20px; white-space:nowrap; }
.wpcc-oc-empty { background:#fff; border:2px dashed #c3c4c7; border-radius:12px; padding:26px; text-align:center; color:#646970; }
.wpcc-oc-grid { display:grid; grid-template-columns:1.4fr 1fr; gap:16px; align-items:start; }
@media (max-width:782px){ .wpcc-oc-grid{grid-template-columns:1fr;} }
</style>

<div class="wrap wpcc-oc">
	<?php
	/*
	 * The dark gradient hero and its four pills are gone.
	 *
	 * Every number they showed is repeated further down this same screen —
	 * pending in "Needs attention", and completed / failed / running in the
	 * "System activity" panel. Printing the same four figures twice, in two
	 * visual languages, on one page is not a summary; it doubles the reading
	 * with no new information, and the dark slab was the only surface in the
	 * product using that treatment. The screen now opens on the one thing that
	 * can require an action.
	 */
	?>

	<!-- 1. NEEDS ATTENTION -->
	<h2><?php esc_html_e( 'Needs attention', 'ai-command-center' ); ?></h2>
	<?php if ( (int) $wpcc_attn['pending_approvals'] === 0 && empty( $wpcc_attn['failures'] ) ) : ?>
		<div class="wpcc-oc-clear" role="status">&#10003; <?php esc_html_e( 'All clear — nothing is waiting on you and no recent operations have failed.', 'ai-command-center' ); ?></div>
	<?php else : ?>
		<div class="wpcc-oc-attn" role="status">
			<?php if ( (int) $wpcc_attn['pending_approvals'] > 0 ) : ?>
				<p style="margin:0 0 8px;font-size:13px;"><strong><?php printf( esc_html( /* translators: %d: number */ _n( '%d change is waiting for your approval.', '%d changes are waiting for your approval.', (int) $wpcc_attn['pending_approvals'], 'ai-command-center' ) ), (int) $wpcc_attn['pending_approvals'] ); ?></strong> <?php esc_html_e( 'Nothing applies until you review it.', 'ai-command-center' ); ?> <a href="<?php echo esc_url( $wpcc_links['approvals'] ); ?>"><?php esc_html_e( 'Review now →', 'ai-command-center' ); ?></a></p>
			<?php endif; ?>
			<?php if ( ! empty( $wpcc_attn['failures'] ) ) : ?>
				<p style="margin:0 0 4px;font-size:13px;font-weight:600;"><?php esc_html_e( 'Recent failures:', 'ai-command-center' ); ?></p>
				<?php foreach ( $wpcc_attn['failures'] as $frow ) : ?>
					<div style="font-size:12px;color:#50575e;">&#10007; <code><?php echo esc_html( $frow['operation'] ?: $frow['kind'] ); ?></code><?php if ( '' !== $frow['error_code'] ) : ?> — <?php echo esc_html( $frow['error_code'] ); ?><?php endif; ?> <span class="muted"><?php echo $frow['time'] ? esc_html( sprintf( /* translators: %s: value */ __( '%s ago', 'ai-command-center' ), human_time_diff( $frow['time'], time() ) ) ) : ''; ?></span></div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<div class="wpcc-oc-grid">
		<!-- 2. OPERATIONS TIMELINE -->
		<div>
			<h2 style="margin-top:18px;"><?php esc_html_e( 'Operations timeline', 'ai-command-center' ); ?></h2>
			<?php
			/*
			 * Say what this list is before showing it.
			 *
			 * The rows carry the engine's own internal names — "worker", "execution",
			 * "result" — because that is what was recorded. The background queue runs on
			 * a schedule on every install, so even a site nobody has touched fills this
			 * with "Completed worker" within minutes. A first-time reader meets a column
			 * of unexplained machine words and has no way to tell whether any of it needs
			 * them. One line answers both questions: what it is, and that it is not a
			 * to-do list. The names themselves are engine data and are left untouched.
			 */
			?>
			<p class="muted" style="font-size:12px;margin:0 0 10px;">
				<?php esc_html_e( 'A technical record of work the plugin has run, newest first. Nothing here needs your attention — anything that does appears under Approvals.', 'ai-command-center' ); ?>
			</p>
			<?php if ( empty( $wpcc_tl['rows'] ) ) : ?>
				<div class="wpcc-oc-empty"><strong><?php esc_html_e( 'No operations recorded yet.', 'ai-command-center' ); ?></strong><br><span class="muted"><?php esc_html_e( 'When AI or an agent performs governed work, each operation appears here — newest first.', 'ai-command-center' ); ?></span></div>
			<?php else : ?>
				<div class="wpcc-oc-card">
					<?php if ( 'audit' === $wpcc_tl['source'] ) : ?>
						<p class="muted" style="font-size:11px;margin:0 0 6px;"><?php esc_html_e( 'Showing recorded activity (duration not measured for these events).', 'ai-command-center' ); ?></p>
					<?php endif; ?>
					<?php foreach ( $wpcc_tl['rows'] as $row ) : [ $slabel, $scolor ] = $wpcc_status_meta( $row['status'] ); ?>
						<div class="wpcc-oc-row">
							<span class="wpcc-oc-badge" style="background:<?php echo esc_attr( $scolor ); ?>22;color:<?php echo esc_attr( $scolor ); ?>;"><?php echo esc_html( $slabel ); ?></span>
							<span style="flex:1;"><strong style="font-weight:600;"><?php echo esc_html( $row['operation'] ?: $row['kind'] ); ?></strong><?php if ( '' !== $row['provider'] ) : ?> <span class="muted">· <?php echo esc_html( $row['provider'] ); ?><?php echo '' !== $row['model'] ? '/' . esc_html( $row['model'] ) : ''; ?></span><?php endif; ?></span>
							<?php
						// Print the duration only when it was actually measured. This
						// column rendered the literal word "unknown" on every row, so a
						// healthy timeline read as a column of failures. Honesty about
						// unmeasured data is right; repeating it once per row is noise.
						// The "what is and isn't measured" note below still explains it.
						?>
						<?php if ( null !== $row['duration_ms'] ) : ?>
							<span class="muted" style="white-space:nowrap;font-size:12px;"><?php echo esc_html( $wpcc_dur( $row['duration_ms'] ) ); ?></span>
						<?php endif; ?>
							<span class="muted" style="white-space:nowrap;font-size:12px;"><?php echo $row['time'] ? esc_html( sprintf( /* translators: %s: value */ __( '%s ago', 'ai-command-center' ), human_time_diff( $row['time'], time() ) ) ) : ''; ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>

		<!-- 4. SYSTEM ACTIVITY (status roll-up) + DATA HONESTY -->
		<div>
			<h2 style="margin-top:18px;"><?php esc_html_e( 'System activity', 'ai-command-center' ); ?></h2>
			<div class="wpcc-oc-card">
				<p class="muted" style="font-size:12px;margin:0 0 8px;">
					<?php
					/* translators: %d: window in days */
					printf( esc_html__( 'Last %d days', 'ai-command-center' ), (int) $wpcc_status['window_days'] );
					?>
				</p>
				<div class="wpcc-oc-row"><span style="flex:1;"><?php esc_html_e( 'Completed', 'ai-command-center' ); ?></span><strong style="color:#0a7a33;"><?php echo (int) $wpcc_status['completed']; ?></strong></div>
				<div class="wpcc-oc-row"><span style="flex:1;"><?php esc_html_e( 'Failed', 'ai-command-center' ); ?></span><strong style="color:#d63638;"><?php echo (int) $wpcc_status['failed']; ?></strong></div>
				<div class="wpcc-oc-row"><span style="flex:1;"><?php esc_html_e( 'Running', 'ai-command-center' ); ?></span><strong><?php echo (int) $wpcc_status['running']; ?></strong></div>
				<div class="wpcc-oc-row"><span style="flex:1;"><?php esc_html_e( 'Cancelled', 'ai-command-center' ); ?></span><strong><?php echo (int) $wpcc_status['cancelled']; ?></strong></div>
				<div class="wpcc-oc-row"><span style="flex:1;"><?php esc_html_e( 'Avg duration', 'ai-command-center' ); ?></span><strong><?php echo null !== $wpcc_status['avg_duration_ms'] ? esc_html( $wpcc_dur( $wpcc_status['avg_duration_ms'] ) ) : esc_html__( 'unknown', 'ai-command-center' ); ?></strong></div>
			</div>

			<!-- 5. DATA HONESTY -->
			<h2><?php esc_html_e( 'What’s measured', 'ai-command-center' ); ?></h2>
			<div class="wpcc-oc-card" style="font-size:13px;">
				<div class="wpcc-oc-row"><span style="flex:1;"><?php esc_html_e( 'Activity tracking', 'ai-command-center' ); ?></span><strong style="color:<?php echo $wpcc_honest['telemetry_active'] ? '#0a7a33' : '#646970'; ?>;"><?php echo $wpcc_honest['telemetry_active'] ? esc_html__( 'Active', 'ai-command-center' ) : esc_html__( 'No data yet', 'ai-command-center' ); ?></strong></div>
				<div class="wpcc-oc-row"><span style="flex:1;"><?php esc_html_e( 'Token usage', 'ai-command-center' ); ?></span><strong class="muted"><?php echo $wpcc_honest['tokens_tracked'] ? esc_html__( 'Partly tracked', 'ai-command-center' ) : esc_html__( 'Not tracked yet', 'ai-command-center' ); ?></strong></div>
				<div class="wpcc-oc-row"><span style="flex:1;"><?php esc_html_e( 'Cost', 'ai-command-center' ); ?></span><strong class="muted"><?php esc_html_e( 'Not tracked yet', 'ai-command-center' ); ?></strong></div>
				<p class="muted" style="font-size:11px;margin:8px 0 0;"><?php esc_html_e( 'Usage and cost appear here only once AI usage reporting is available — nothing is estimated.', 'ai-command-center' ); ?></p>
			</div>
		</div>
	</div>

	<!-- 3. REVIEW & UNDO -->
	<h2><?php esc_html_e( 'Review & undo', 'ai-command-center' ); ?></h2>
	<?php if ( empty( $wpcc_rev ) ) : ?>
		<div class="wpcc-oc-empty">
			<strong><?php esc_html_e( 'No reversible changes recorded yet.', 'ai-command-center' ); ?></strong><br>
			<span class="muted"><?php esc_html_e( 'Reversible changes (content, SEO, media metadata, settings, comments, users…) appear here with a Restore.', 'ai-command-center' ); ?></span>
			<p style="margin:12px 0 0;"><a class="button button-small" href="<?php echo esc_url( $wpcc_links['changes'] ); ?>"><?php esc_html_e( 'Open Changes', 'ai-command-center' ); ?></a></p>
		</div>
	<?php else : ?>
		<div class="wpcc-oc-card">
			<?php foreach ( $wpcc_rev as $s ) : ?>
				<div class="wpcc-oc-row">
					<span style="flex:1;">
						<strong style="font-weight:600;"><?php echo esc_html( implode( ', ', array_slice( (array) $s['runtimes'], 0, 3 ) ) ?: __( 'change session', 'ai-command-center' ) ); ?></strong>
						<span class="muted">· <?php printf( esc_html( /* translators: %d: number */ _n( '%d reversible change', '%d reversible changes', (int) $s['reversible_count'], 'ai-command-center' ) ), (int) $s['reversible_count'] ); ?></span>
						<span class="muted">· <?php echo esc_html( $s['actor_summary'] ); ?></span>
					</span>
					<span class="muted" style="white-space:nowrap;font-size:12px;"><?php echo (int) $s['last_at'] ? esc_html( sprintf( /* translators: %s: value */ __( '%s ago', 'ai-command-center' ), human_time_diff( (int) $s['last_at'], time() ) ) ) : ''; ?></span>
					<a class="button button-small" href="<?php echo esc_url( add_query_arg( 'session_id', rawurlencode( (string) $s['session_id'] ), $wpcc_links['sessions'] ) ); ?>"><?php esc_html_e( 'Review & undo', 'ai-command-center' ); ?></a>
				</div>
			<?php endforeach; ?>
			<p style="margin:10px 0 0;"><a class="button button-small" href="<?php echo esc_url( $wpcc_links['changes'] ); ?>"><?php esc_html_e( 'All changes →', 'ai-command-center' ); ?></a></p>
		</div>
	<?php endif; ?>
</div>
