<?php
/**
 * PROGRAM-6S — AI Platform experience (premium product UX over the 6R foundation).
 *
 * EXPERIENCE ONLY: no architecture/runtime/data-model change. Renders the same
 * ConnectionStore data as a real AI platform — dashboard, guided wizard, rich
 * connection cards with health, declared capabilities, visual feature routing —
 * with honest status (CONFIGURED / TESTABLE / USED BY RUNTIME, never faked).
 * All writes still go through ConnectionController (nonce + manage_options + audit).
 */

defined( 'ABSPATH' ) || exit;

use WPCommandCenter\Admin\ConnectionController;
use WPCommandCenter\Ai\Platform\ConnectionStore;
use WPCommandCenter\Ai\Platform\ProviderCatalog;
use WPCommandCenter\Ai\Platform\Dialect;
use WPCommandCenter\Ai\Platform\Capabilities;
use WPCommandCenter\Ai\Platform\Health;
use WPCommandCenter\Ai\Platform\AiActivity;

$wpcc_notice = ( new ConnectionController() )->handle_post();

$wpcc_act  = AiActivity::summary();
$wpcc_feed = AiActivity::feed( 12 );

$wpcc_store     = new ConnectionStore();
$wpcc_conns     = $wpcc_store->all();
$wpcc_default   = $wpcc_store->default_id();
$wpcc_routes    = $wpcc_store->routes();
$wpcc_providers = ProviderCatalog::all();
$wpcc_health    = Health::summary( $wpcc_conns, $wpcc_store );

// Runtime-usable, configured connections (for routing).
$wpcc_runtime_conns = [];
// Healthy/configured connections the runtime CANNOT execute yet (e.g. OpenAI):
// surfaced in routing with an explicit reason so a healthy connection's absence
// is never a mystery. Runtime capability is unchanged — clarity only.
$wpcc_ineligible_conns = [];
foreach ( $wpcc_conns as $cid => $c ) {
	if ( ! $wpcc_store->is_configured( $c ) || ! $c['enabled'] ) {
		continue;
	}
	if ( $wpcc_store->runtime_usable( $c ) ) {
		$wpcc_runtime_conns[ $cid ] = $c['name'];
	} else {
		$wpcc_ineligible_conns[ $cid ] = ( $wpcc_providers[ $c['provider'] ]['label'] ?? $c['provider'] ) . ' · ' . $c['name'];
	}
}

// Readiness score (honest, derived): a connection, a default, a healthy test, a key.
$wpcc_ready = 0;
if ( ! empty( $wpcc_conns ) ) { $wpcc_ready += 30; }
if ( '' !== $wpcc_default ) { $wpcc_ready += 25; }
$wpcc_has_healthy = false;
foreach ( $wpcc_conns as $c ) { if ( in_array( Health::of( $c, $wpcc_store )['state'], [ 'healthy', 'slow' ], true ) ) { $wpcc_has_healthy = true; break; } }
if ( $wpcc_has_healthy ) { $wpcc_ready += 30; }
if ( $wpcc_health['attention'] === 0 && ! empty( $wpcc_conns ) ) { $wpcc_ready += 15; }
$wpcc_ready = min( 100, $wpcc_ready );

// PROGRAM-7.5 — make the readiness % self-explanatory: expose the exact components
// that produced it (no scoring-logic change), plus honest context for AI being
// inactive (not part of the score — purely informational).
$wpcc_ready_steps = [
	[ 'done' => ! empty( $wpcc_conns ),                                      'label' => __( 'A connection added', 'wp-command-center' ) ],
	[ 'done' => '' !== $wpcc_default,                                        'label' => __( 'A default chosen', 'wp-command-center' ) ],
	[ 'done' => $wpcc_has_healthy,                                           'label' => __( 'Tested healthy', 'wp-command-center' ) ],
	[ 'done' => ( $wpcc_health['attention'] === 0 && ! empty( $wpcc_conns ) ), 'label' => __( 'No connection issues', 'wp-command-center' ) ],
];

// Provider groups for the wizard.
$wpcc_groups = [ 'cloud' => [], 'local' => [], 'gateway' => [] ];
foreach ( $wpcc_providers as $pid => $pdef ) {
	if ( ! empty( $pdef['local'] ) ) { $wpcc_groups['local'][ $pid ] = $pdef['label']; }
	elseif ( ! empty( $pdef['needs_endpoint'] ) && empty( $pdef['needs_deployment'] ) ) { $wpcc_groups['gateway'][ $pid ] = $pdef['label']; }
	else { $wpcc_groups['cloud'][ $pid ] = $pdef['label']; }
}
$wpcc_default_name = '' !== $wpcc_default && isset( $wpcc_conns[ $wpcc_default ] ) ? $wpcc_conns[ $wpcc_default ]['name'] : __( 'none yet', 'wp-command-center' );
?>
<style>
.wpcc-aip { max-width: 1080px; }
.wpcc-aip h2 { font-size: 16px; margin: 28px 0 6px; }
.wpcc-aip .muted { color: #646970; }
.wpcc-aip-hero { display:flex; justify-content:space-between; gap:20px; flex-wrap:wrap; align-items:center; background:linear-gradient(135deg,#1d2734,#2c3a4f); color:#e8edf3; border-radius:12px; padding:22px 26px; margin:6px 0 18px; }
.wpcc-aip-hero h1 { color:#fff; margin:0 0 4px; font-size:22px; }
.wpcc-aip-hero p { margin:0; color:#b9c4d2; font-size:13px; max-width:520px; }
.wpcc-aip-score { text-align:center; min-width:110px; }
.wpcc-aip-readiness { display:flex; gap:18px; align-items:center; }
.wpcc-aip-checklist { list-style:none; margin:0; padding:0; display:grid; gap:4px; font-size:12.5px; color:#cdd6e2; }
.wpcc-aip-checklist li { white-space:nowrap; }
.wpcc-aip-checklist .ck { display:inline-block; width:16px; font-weight:700; }
.wpcc-aip-ring { --v:0; width:84px; height:84px; border-radius:50%; margin:0 auto 6px; background:conic-gradient(#3ec46d calc(var(--v)*1%), rgba(255,255,255,.15) 0); display:flex; align-items:center; justify-content:center; }
.wpcc-aip-ring span { width:64px; height:64px; border-radius:50%; background:#1d2734; display:flex; align-items:center; justify-content:center; font-size:20px; font-weight:700; color:#fff; }
.wpcc-aip-kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:12px; margin-bottom:8px; }
.wpcc-aip-kpi { background:#fff; border:1px solid #dcdfe3; border-radius:10px; padding:14px 16px; }
.wpcc-aip-kpi .v { font-size:24px; font-weight:700; color:#1d2327; line-height:1.1; }
.wpcc-aip-kpi .l { font-size:12px; color:#646970; text-transform:uppercase; letter-spacing:.4px; margin-top:3px; }
.wpcc-aip-warn { background:#fcf6e6; border:1px solid #f0d97a; border-radius:8px; padding:12px 16px; margin:10px 0; font-size:13px; }
.wpcc-aip-needsyou { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; background:#fef0f0; border:1px solid #f0a8a8; border-left:4px solid #d63638; border-radius:8px; padding:12px 16px; margin:10px 0; font-size:13px; }
.wpcc-aip-flow { display:flex; align-items:center; gap:6px; flex-wrap:wrap; background:#f6f7f9; border:1px solid #e4e7eb; border-radius:10px; padding:10px 14px; margin:14px 0; font-size:12.5px; color:#50575e; }
.wpcc-aip-flow .step { display:inline-flex; align-items:center; gap:5px; font-weight:600; color:#2c3a4f; }
.wpcc-aip-flow .step .dashicons { font-size:16px; width:16px; height:16px; color:#5b6b82; }
.wpcc-aip-flow .sep { color:#aab2bd; font-weight:700; }
.wpcc-aip-cards { display:grid; grid-template-columns:repeat(auto-fill,minmax(330px,1fr)); gap:14px; }
.wpcc-aip-card { background:#fff; border:1px solid #dcdfe3; border-radius:12px; padding:16px 18px; box-shadow:0 1px 2px rgba(0,0,0,.04); display:flex; flex-direction:column; gap:8px; transition:box-shadow .15s ease, border-color .15s ease; }
.wpcc-aip-card:hover { box-shadow:0 4px 14px rgba(28,39,52,.10); border-color:#c5ccd4; }
.wpcc-aip-card.dim { opacity:.62; }
.wpcc-aip-card__top { display:flex; justify-content:space-between; align-items:flex-start; gap:8px; }
.wpcc-aip-avatar { width:38px; height:38px; border-radius:9px; background:#eef2f7; color:#2c3a4f; font-weight:700; display:flex; align-items:center; justify-content:center; font-size:15px; flex:0 0 auto; }
.wpcc-aip-name { font-size:15px; font-weight:700; line-height:1.2; }
.wpcc-aip-sub { font-size:12px; color:#646970; }
.wpcc-aip-dot { width:9px; height:9px; border-radius:50%; display:inline-block; vertical-align:middle; margin-right:5px; }
.wpcc-aip-timeline { list-style:none; margin:0; padding:0; }
.wpcc-aip-timeline .grp { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#8a93a0; margin:8px 0 4px; }
.wpcc-aip-timeline .grp:first-child { margin-top:0; }
.wpcc-aip-timeline .ev { display:flex; gap:9px; align-items:baseline; font-size:13px; padding:4px 0; border-bottom:1px solid #f3f4f6; }
.wpcc-aip-timeline .ev:last-child { border-bottom:0; }
.wpcc-aip-timeline .ic { flex:0 0 18px; }
.wpcc-aip-timeline .ic .dashicons { font-size:16px; width:16px; height:16px; }
.wpcc-aip-timeline .bd { flex:1; }
.wpcc-aip-timeline .tm { white-space:nowrap; font-size:12px; }
.wpcc-aip-badge { display:inline-block; padding:2px 8px; border-radius:20px; font-size:11px; font-weight:700; letter-spacing:.2px; }
.wpcc-aip-meta { font-size:12px; color:#50575e; display:grid; gap:3px; }
.wpcc-aip-meta code { background:#f4f6f8; padding:1px 6px; border-radius:4px; }
.wpcc-aip-caps { display:flex; flex-wrap:wrap; gap:4px; }
.wpcc-aip-cap { font-size:11px; padding:1px 7px; border-radius:5px; background:#f0f3f7; color:#50575e; }
.wpcc-aip-cap.on { background:#e7f6ec; color:#0a7a33; }
.wpcc-aip-actions { display:flex; flex-wrap:wrap; gap:6px; border-top:1px solid #eef0f2; padding-top:10px; margin-top:2px; }
.wpcc-aip-actions form { margin:0; }
.wpcc-aip-empty { background:#fff; border:2px dashed #c3c4c7; border-radius:12px; padding:36px 24px; text-align:center; }
.wpcc-aip-empty h3 { margin:0 0 6px; font-size:17px; }
.wpcc-aip-route { display:flex; align-items:center; gap:10px; padding:11px 0; border-bottom:1px solid #f0f0f1; }
.wpcc-aip-route .f { min-width:200px; font-weight:600; }
.wpcc-aip-route .arrow { color:#8a93a0; }
.wpcc-aip-wizard { display:none; background:#fff; border:1px solid #c3c4c7; border-radius:12px; padding:20px 22px; margin:8px 0 18px; max-width:620px; }
.wpcc-aip-wizard.open { display:block; }
.wpcc-aip-steps { display:flex; gap:6px; margin-bottom:16px; }
.wpcc-aip-steps .s { flex:1; height:5px; border-radius:3px; background:#e5e8ec; }
.wpcc-aip-steps .s.active { background:#2c3a4f; }
.wpcc-aip-step { display:none; }
.wpcc-aip-step.active { display:block; }
.wpcc-aip-step h3 { margin:0 0 4px; font-size:15px; }
.wpcc-aip-field { margin:12px 0; }
.wpcc-aip-field label { display:block; font-weight:600; font-size:13px; margin-bottom:4px; }
.wpcc-aip-field input, .wpcc-aip-field select { width:100%; max-width:420px; }
.wpcc-aip-wnav { display:flex; justify-content:space-between; margin-top:18px; }
@media (max-width:782px){ .wpcc-aip-hero{flex-direction:column; align-items:flex-start;} .wpcc-aip-cards{grid-template-columns:1fr;} }
</style>

<div class="wrap wpcc-aip">
	<?php if ( $wpcc_notice ) : ?>
		<div class="notice notice-<?php echo esc_attr( $wpcc_notice['type'] ); ?>" role="alert"><p><?php echo esc_html( $wpcc_notice['message'] ); ?></p></div>
	<?php endif; ?>

	<!-- ===== Dashboard hero ===== -->
	<div class="wpcc-aip-hero">
		<div>
			<h1><?php esc_html_e( 'Providers', 'wp-command-center' ); ?></h1>
			<p><?php esc_html_e( 'Connect the AI provider that powers WP Command Center’s built-in AI. Add a key, pick a model, test it, and choose which features use it. AI stays off until you turn a feature on.', 'wp-command-center' ); ?></p>
		</div>
		<div class="wpcc-aip-readiness">
			<div class="wpcc-aip-score" aria-label="<?php echo esc_attr( sprintf( /* translators: %d score */ __( 'Setup readiness %d percent', 'wp-command-center' ), (int) $wpcc_ready ) ); ?>">
				<div class="wpcc-aip-ring" style="--v:<?php echo (int) $wpcc_ready; ?>"><span><?php echo (int) $wpcc_ready; ?></span></div>
				<div style="font-size:12px;color:#b9c4d2;"><?php esc_html_e( 'Setup readiness', 'wp-command-center' ); ?></div>
			</div>
			<ul class="wpcc-aip-checklist" aria-label="<?php esc_attr_e( 'What makes up your readiness', 'wp-command-center' ); ?>">
				<?php foreach ( $wpcc_ready_steps as $rs ) : ?>
					<li><span class="ck" aria-hidden="true" style="color:<?php echo $rs['done'] ? '#3ec46d' : '#7c8694'; ?>;"><?php echo $rs['done'] ? '&#10003;' : '&#9711;'; ?></span> <?php echo esc_html( $rs['label'] ); ?><span class="screen-reader-text"><?php echo $rs['done'] ? esc_html__( ' (done)', 'wp-command-center' ) : esc_html__( ' (to do)', 'wp-command-center' ); ?></span></li>
				<?php endforeach; ?>
				<li style="opacity:.75;"><span class="ck" aria-hidden="true">&#8230;</span> <?php esc_html_e( 'AI features: inactive (enable when ready)', 'wp-command-center' ); ?></li>
			</ul>
		</div>
	</div>

	<!-- ===== KPIs ===== -->
	<div class="wpcc-aip-kpis">
		<div class="wpcc-aip-kpi"><div class="v"><?php echo (int) $wpcc_health['total']; ?></div><div class="l"><?php esc_html_e( 'Connections', 'wp-command-center' ); ?></div></div>
		<div class="wpcc-aip-kpi"><div class="v" style="color:<?php echo $wpcc_health['attention'] ? '#d63638' : '#0a7a33'; ?>;"><?php echo (int) $wpcc_health['healthy']; ?></div><div class="l"><?php esc_html_e( 'Healthy', 'wp-command-center' ); ?></div></div>
		<div class="wpcc-aip-kpi"><div class="v"><?php echo esc_html( $wpcc_default_name ); ?></div><div class="l"><?php esc_html_e( 'Default environment', 'wp-command-center' ); ?></div></div>
		<div class="wpcc-aip-kpi"><div class="v" style="<?php echo $wpcc_has_healthy ? 'color:#0a7a33;' : 'font-size:16px;color:#646970;'; ?>"><?php echo $wpcc_has_healthy ? esc_html__( 'Ready', 'wp-command-center' ) : esc_html__( 'Inactive', 'wp-command-center' ); ?></div><div class="l"><?php esc_html_e( 'AI status', 'wp-command-center' ); ?></div></div>
	</div>

	<?php if ( $wpcc_health['attention'] > 0 ) : ?>
		<div class="wpcc-aip-warn" role="status">⚠ <?php printf( esc_html( _n( '%d connection needs attention. Open it below for the recommended fix.', '%d connections need attention. Open them below for the recommended fix.', $wpcc_health['attention'], 'wp-command-center' ) ), (int) $wpcc_health['attention'] ); ?></div>
	<?php elseif ( '' === $wpcc_default && ! empty( $wpcc_conns ) ) : ?>
		<div class="wpcc-aip-warn" role="status"><?php esc_html_e( 'No default connection yet. Add a key to an Anthropic connection and set it as default so AI features have something to use.', 'wp-command-center' ); ?></div>
	<?php endif; ?>

	<?php if ( (int) $wpcc_act['pending_approvals'] > 0 ) : ?>
		<div class="wpcc-aip-needsyou" role="status">
			<span><strong><?php printf( esc_html( _n( '%d change is waiting for your approval.', '%d changes are waiting for your approval.', (int) $wpcc_act['pending_approvals'], 'wp-command-center' ) ), (int) $wpcc_act['pending_approvals'] ); ?></strong> <?php esc_html_e( 'Nothing applies to your site until you review it.', 'wp-command-center' ); ?></span>
			<a class="button button-primary button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=wpcc-activity&wpcc_tab=approvals' ) ); ?>"><?php esc_html_e( 'Review now', 'wp-command-center' ); ?></a>
		</div>
	<?php endif; ?>

	<!-- ===== How WP Command Center works (the governed promise, visualized) ===== -->
	<div class="wpcc-aip-flow" aria-label="<?php esc_attr_e( 'How WP Command Center works', 'wp-command-center' ); ?>">
		<?php
		$wpcc_flow = [
			[ 'dashicons-search',        __( 'Inspect', 'wp-command-center' ) ],
			[ 'dashicons-lightbulb',     __( 'Plan', 'wp-command-center' ) ],
			[ 'dashicons-yes-alt',       __( 'Approve', 'wp-command-center' ) ],
			[ 'dashicons-controls-play', __( 'Execute', 'wp-command-center' ) ],
			[ 'dashicons-shield',        __( 'Verify', 'wp-command-center' ) ],
			[ 'dashicons-undo',          __( 'Rollback', 'wp-command-center' ) ],
		];
		$wpcc_flow_last = count( $wpcc_flow ) - 1;
		foreach ( $wpcc_flow as $i => $step ) :
			?>
			<span class="step"><span class="dashicons <?php echo esc_attr( $step[0] ); ?>" aria-hidden="true"></span><?php echo esc_html( $step[1] ); ?></span>
			<?php if ( $i < $wpcc_flow_last ) : ?><span class="sep" aria-hidden="true">›</span><?php endif; ?>
		<?php endforeach; ?>
	</div>

	<?php require WPCC_PLUGIN_DIR . 'includes/Admin/views/partials/trust-strip.php'; ?>

	<!-- ===== Recent AI activity ===== -->
	<h2><?php esc_html_e( 'Recent AI activity', 'wp-command-center' ); ?></h2>
	<div style="display:grid;grid-template-columns:1.2fr 1fr;gap:16px;align-items:start;">
		<div style="background:#fff;border:1px solid #dcdfe3;border-radius:12px;padding:16px 18px;">
			<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px;">
				<strong style="font-size:14px;"><?php esc_html_e( 'Recent AI activity', 'wp-command-center' ); ?></strong>
				<?php if ( (int) $wpcc_act['pending_approvals'] > 0 ) : ?>
					<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=wpcc-activity&wpcc_tab=approvals' ) ); ?>"><?php printf( esc_html( _n( '%d pending approval', '%d pending approvals', (int) $wpcc_act['pending_approvals'], 'wp-command-center' ) ), (int) $wpcc_act['pending_approvals'] ); ?></a>
				<?php endif; ?>
			</div>
			<?php if ( empty( $wpcc_feed ) ) : ?>
				<p class="muted" style="font-size:13px;margin:0;"><?php esc_html_e( 'No activity yet. When AI or an agent acts on this site, the governed history appears here — every action recorded, reversible where supported.', 'wp-command-center' ); ?></p>
			<?php else : ?>
				<?php
				// Category → dashicon (visual scanning aid; presentation only).
				$wpcc_cat_icon = [
					'rollback' => 'dashicons-undo', 'connection' => 'dashicons-admin-links', 'generation' => 'dashicons-superhero',
					'agent' => 'dashicons-rest-api', 'change' => 'dashicons-edit', 'operation' => 'dashicons-controls-play',
					'security' => 'dashicons-shield', 'patch' => 'dashicons-media-code', 'activity' => 'dashicons-marker',
				];
				// Group by day bucket (Today / Earlier) using existing timestamps — no new data.
				$wpcc_today = (int) current_time( 'timestamp' ) - (int) ( current_time( 'timestamp' ) % DAY_IN_SECONDS );
				$wpcc_bucket = '';
				?>
				<ul class="wpcc-aip-timeline" aria-label="<?php esc_attr_e( 'Recent AI activity', 'wp-command-center' ); ?>">
					<?php foreach ( $wpcc_feed as $ev ) :
						$b = ( $ev['time'] && $ev['time'] >= $wpcc_today ) ? 'today' : 'earlier';
						if ( $b !== $wpcc_bucket ) :
							$wpcc_bucket = $b;
							?>
							<li class="grp"><?php echo 'today' === $b ? esc_html__( 'Today', 'wp-command-center' ) : esc_html__( 'Earlier', 'wp-command-center' ); ?></li>
						<?php endif; ?>
						<li class="ev">
							<span class="ic" style="color:<?php echo esc_attr( $ev['color'] ); ?>" aria-hidden="true"><span class="dashicons <?php echo esc_attr( $wpcc_cat_icon[ $ev['category'] ] ?? 'dashicons-marker' ); ?>"></span></span>
							<span class="bd"><strong><?php echo esc_html( $ev['cat_label'] ); ?></strong> <span class="muted"><?php echo esc_html( $ev['label'] ); ?></span><?php if ( '' !== $ev['actor'] ) : ?> <span class="muted">· <?php echo esc_html( $ev['actor'] ); ?></span><?php endif; ?></span>
							<span class="tm muted"><?php echo $ev['time'] ? esc_html( sprintf( /* translators: %s ago */ __( '%s ago', 'wp-command-center' ), human_time_diff( $ev['time'], time() ) ) ) : ''; ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<p style="margin:12px 0 0;display:flex;gap:8px;flex-wrap:wrap;">
				<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=wpcc-history&wpcc_tab=changes' ) ); ?>"><?php esc_html_e( 'Review changes & undo', 'wp-command-center' ); ?></a>
				<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=wpcc-activity&wpcc_tab=approvals' ) ); ?>"><?php esc_html_e( 'Approvals', 'wp-command-center' ); ?></a>
				<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=wpcc-connect&wpcc_tab=clients' ) ); ?>"><?php esc_html_e( 'Connect an AI client', 'wp-command-center' ); ?></a>
			</p>
		</div>
		<div style="display:grid;gap:10px;">
			<div class="wpcc-aip-kpi"><div class="v"><?php echo (int) $wpcc_act['events']; ?></div><div class="l"><?php esc_html_e( 'Recent events', 'wp-command-center' ); ?></div></div>
			<div class="wpcc-aip-kpi"><div class="v" style="color:<?php echo (int) $wpcc_act['pending_approvals'] ? '#d63638' : '#0a7a33'; ?>;"><?php echo (int) $wpcc_act['pending_approvals']; ?></div><div class="l"><?php esc_html_e( 'Pending approvals', 'wp-command-center' ); ?></div></div>
			<div class="wpcc-aip-kpi" title="<?php esc_attr_e( 'Per-token usage and cost are not metered yet — they arrive when the AI runtime is instrumented. No estimated figure is shown to avoid misleading you.', 'wp-command-center' ); ?>"><div class="v" style="font-size:15px;color:#646970;"><?php esc_html_e( 'Not tracked yet', 'wp-command-center' ); ?></div><div class="l"><?php esc_html_e( 'Token usage & cost', 'wp-command-center' ); ?></div></div>
		</div>
	</div>

	<!-- ===== Quick action ===== -->
	<p style="margin:18px 0;"><button type="button" class="button button-primary button-hero" id="wpcc-aip-new" aria-expanded="false" aria-controls="wpcc-aip-wizard">+ <?php esc_html_e( 'New connection', 'wp-command-center' ); ?></button></p>

	<!-- ===== Connection wizard (progressive; degrades to a full form without JS) ===== -->
	<form method="post" class="wpcc-aip-wizard" id="wpcc-aip-wizard" aria-label="<?php esc_attr_e( 'New connection wizard', 'wp-command-center' ); ?>">
		<?php wp_nonce_field( ConnectionController::NONCE ); ?>
		<input type="hidden" name="wpcc_conn_action" value="create" />
		<input type="hidden" name="wpcc_model" value="custom" />
		<div class="wpcc-aip-steps" aria-hidden="true"><span class="s active"></span><span class="s"></span><span class="s"></span><span class="s"></span><span class="s"></span></div>

		<div class="wpcc-aip-step active" data-step="1">
			<h3><?php esc_html_e( 'Step 1 — Choose a provider', 'wp-command-center' ); ?></h3>
			<p class="muted" style="font-size:13px;"><?php esc_html_e( 'Pick the AI service. Cloud = hosted (Claude, GPT, Gemini); Local = a model on your own machine (Ollama, LM Studio); Gateway / Custom = your own endpoint. Only Anthropic powers WPCC’s features today — each option shows whether WPCC can use it, test it, or just store it.', 'wp-command-center' ); ?></p>
			<div class="wpcc-aip-field">
				<label for="wpcc-w-provider"><?php esc_html_e( 'Provider', 'wp-command-center' ); ?></label>
				<select name="wpcc_provider" id="wpcc-w-provider">
					<?php foreach ( [ 'cloud' => __( 'Cloud', 'wp-command-center' ), 'local' => __( 'Local', 'wp-command-center' ), 'gateway' => __( 'Gateway / Custom', 'wp-command-center' ) ] as $gk => $glabel ) : ?>
						<?php if ( ! empty( $wpcc_groups[ $gk ] ) ) : ?>
							<optgroup label="<?php echo esc_attr( $glabel ); ?>">
								<?php foreach ( $wpcc_groups[ $gk ] as $pid => $plabel ) : ?>
									<option value="<?php echo esc_attr( $pid ); ?>"><?php echo esc_html( $plabel ); ?> — <?php echo esc_html( ProviderCatalog::runtime_usable( $pid ) ? __( 'used by runtime', 'wp-command-center' ) : ( ProviderCatalog::test_supported( $pid ) ? __( 'testable', 'wp-command-center' ) : __( 'stored only', 'wp-command-center' ) ) ); ?></option>
								<?php endforeach; ?>
							</optgroup>
						<?php endif; ?>
					<?php endforeach; ?>
				</select>
			</div>
		</div>

		<div class="wpcc-aip-step" data-step="2">
			<h3><?php esc_html_e( 'Step 2 — Name & where it runs', 'wp-command-center' ); ?></h3>
			<div class="wpcc-aip-field"><label for="wpcc-w-name"><?php esc_html_e( 'Connection name', 'wp-command-center' ); ?></label><input type="text" id="wpcc-w-name" name="wpcc_name" placeholder="<?php esc_attr_e( 'e.g. Production Claude', 'wp-command-center' ); ?>" /></div>
			<div class="wpcc-aip-field" id="wpcc-w-endpoint-field"><label for="wpcc-w-endpoint"><?php esc_html_e( 'Base URL', 'wp-command-center' ); ?> <span class="muted">(<?php esc_html_e( 'required for this provider', 'wp-command-center' ); ?>)</span></label><input type="url" id="wpcc-w-endpoint" name="wpcc_endpoint" style="font-family:monospace;" placeholder="https://…" /><p class="muted" style="font-size:12px;margin:4px 0 0;"><?php esc_html_e( 'Cloud providers use their official URL automatically — you only set this for local, Azure, gateway, or custom endpoints.', 'wp-command-center' ); ?></p></div>
		</div>

		<div class="wpcc-aip-step" data-step="3">
			<h3><?php esc_html_e( 'Step 3 — Credentials', 'wp-command-center' ); ?></h3>
			<p class="muted" style="font-size:13px;"><?php esc_html_e( 'Paste your API key. It is stored on this site and never shown again. Local models usually need no key.', 'wp-command-center' ); ?></p>
			<div class="wpcc-aip-field"><label for="wpcc-w-key"><?php esc_html_e( 'API key', 'wp-command-center' ); ?></label><input type="password" id="wpcc-w-key" name="wpcc_key" autocomplete="off" spellcheck="false" style="font-family:monospace;" placeholder="<?php esc_attr_e( 'Paste your API key (optional for local)', 'wp-command-center' ); ?>" /></div>
		</div>

		<div class="wpcc-aip-step" data-step="4">
			<h3><?php esc_html_e( 'Step 4 — Model', 'wp-command-center' ); ?></h3>
			<p class="muted" style="font-size:13px;"><?php esc_html_e( 'Recommended models are shown during setup. After you save and test this connection, WP Command Center automatically adds any other models your account exposes — you’ll find them in this connection’s Model list under “Edit”. Providers that don’t publish a model list simply keep the recommended set. You can change the model any time.', 'wp-command-center' ); ?></p>
			<div class="wpcc-aip-field">
				<label for="wpcc-w-model-select"><?php esc_html_e( 'Model', 'wp-command-center' ); ?></label>
				<input type="search" id="wpcc-w-model-search" style="display:none;width:100%;margin-bottom:6px;" placeholder="<?php esc_attr_e( 'Filter models…', 'wp-command-center' ); ?>" aria-label="<?php esc_attr_e( 'Filter models', 'wp-command-center' ); ?>" />
				<select id="wpcc-w-model-select" style="display:none;"></select>
				<input type="text" id="wpcc-w-model" name="wpcc_model_custom" style="font-family:monospace;" placeholder="model-id" />
				<p class="muted" id="wpcc-w-model-help" style="font-size:12px;margin:4px 0 0;"></p>
			</div>
			<details class="wpcc-aip-advanced" style="margin-top:4px;">
				<summary style="cursor:pointer;font-size:13px;"><?php esc_html_e( 'Advanced options', 'wp-command-center' ); ?></summary>
				<div class="wpcc-aip-field" style="margin-top:10px;">
					<label for="wpcc-w-tags"><?php esc_html_e( 'Tags', 'wp-command-center' ); ?> <span class="muted">(<?php esc_html_e( 'optional', 'wp-command-center' ); ?>)</span></label>
					<input type="text" id="wpcc-w-tags" name="wpcc_tags" placeholder="prod, premium" />
					<p class="muted" style="font-size:12px;margin:4px 0 0;"><?php esc_html_e( 'Internal labels to organize and route your connections (for example “prod” or “cheap”). Optional, used only inside WP Command Center — never sent to the provider.', 'wp-command-center' ); ?></p>
				</div>
				<div class="wpcc-aip-field" id="wpcc-w-deployment-field" style="display:none;margin-top:10px;">
					<label for="wpcc-w-deployment"><?php esc_html_e( 'Deployment name', 'wp-command-center' ); ?></label>
					<input type="text" id="wpcc-w-deployment" name="wpcc_deployment" />
					<p class="muted" style="font-size:12px;margin:4px 0 0;"><?php esc_html_e( 'Azure OpenAI only: the deployment name you created for this model in your Azure resource.', 'wp-command-center' ); ?></p>
				</div>
			</details>
		</div>

		<div class="wpcc-aip-step" data-step="5">
			<h3><?php esc_html_e( 'Step 5 — Create & test', 'wp-command-center' ); ?></h3>
			<p class="muted" style="font-size:13px;"><?php esc_html_e( 'We’ll create the connection now. Then use “Test” on its card to verify the key works — no changes are made to your site.', 'wp-command-center' ); ?></p>
			<p style="font-size:13px;"><?php esc_html_e( 'Adding a key does not turn AI features on by itself — built-in AI screens are enabled per site.', 'wp-command-center' ); ?></p>
		</div>

		<div class="wpcc-aip-wnav">
			<button type="button" class="button" id="wpcc-w-back" style="visibility:hidden;"><?php esc_html_e( 'Back', 'wp-command-center' ); ?></button>
			<span>
				<button type="button" class="button" id="wpcc-w-cancel"><?php esc_html_e( 'Cancel', 'wp-command-center' ); ?></button>
				<button type="button" class="button button-primary" id="wpcc-w-next"><?php esc_html_e( 'Next', 'wp-command-center' ); ?></button>
				<button type="submit" class="button button-primary" id="wpcc-w-finish" style="display:none;"><?php esc_html_e( 'Create connection', 'wp-command-center' ); ?></button>
			</span>
		</div>
	</form>

	<!-- ===== Connections ===== -->
	<h2><?php esc_html_e( 'Your connections', 'wp-command-center' ); ?></h2>
	<?php if ( empty( $wpcc_conns ) ) : ?>
		<div class="wpcc-aip-empty">
			<h3><?php esc_html_e( 'No AI connections yet', 'wp-command-center' ); ?></h3>
			<p class="muted" style="max-width:460px;margin:0 auto 14px;"><?php esc_html_e( 'A connection links an AI provider to this site so WP Command Center can do work for you — safely, with your approval and one-click undo. Add a connection to get started.', 'wp-command-center' ); ?></p>
			<button type="button" class="button button-primary" id="wpcc-aip-new2">+ <?php esc_html_e( 'Add a connection', 'wp-command-center' ); ?></button>
		</div>
	<?php else : ?>
		<div class="wpcc-aip-cards">
			<?php foreach ( $wpcc_conns as $cid => $c ) :
				$def      = $wpcc_providers[ $c['provider'] ] ?? [];
				$has_key  = $wpcc_store->is_configured( $c );
				$is_const = $wpcc_store->credentials()->is_constant_backed( $c );
				$runtime  = $wpcc_store->runtime_usable( $c );
				$testable = $wpcc_store->testable( $c );
				$is_def   = ( $wpcc_default === $cid );
				$lt       = $c['last_test'];
				$h        = Health::of( $c, $wpcc_store );
				$caps     = Capabilities::for_provider( $c['provider'] );
				$avatar   = strtoupper( substr( (string) ( $def['label'] ?? $c['provider'] ), 0, 1 ) );
				?>
				<div class="wpcc-aip-card <?php echo $c['enabled'] ? '' : 'dim'; ?>">
					<div class="wpcc-aip-card__top">
						<div style="display:flex;gap:10px;align-items:center;">
							<div class="wpcc-aip-avatar" aria-hidden="true"><?php echo esc_html( $avatar ); ?></div>
							<div>
								<div class="wpcc-aip-name"><?php echo esc_html( $c['name'] ); ?></div>
								<div class="wpcc-aip-sub"><?php echo esc_html( (string) ( $def['label'] ?? $c['provider'] ) ); ?> · <?php echo esc_html( $c['dialect'] ); ?></div>
							</div>
						</div>
						<div style="text-align:right;font-size:12px;">
							<span class="wpcc-aip-dot" style="background:<?php echo esc_attr( $h['dot'] ); ?>"></span><strong><?php echo esc_html( $h['label'] ); ?></strong>
						</div>
					</div>

					<div>
						<?php if ( $is_def ) : ?><span class="wpcc-aip-badge" style="background:#e7f0fb;color:#1d62b0;"><?php esc_html_e( 'DEFAULT', 'wp-command-center' ); ?></span> <?php endif; ?>
						<?php if ( $runtime ) : ?><span class="wpcc-aip-badge" style="background:#e7f6ec;color:#0a7a33;"><?php esc_html_e( 'USED BY RUNTIME', 'wp-command-center' ); ?></span>
						<?php elseif ( $testable ) : ?><span class="wpcc-aip-badge" style="background:#eef4fb;color:#1d62b0;" title="<?php esc_attr_e( 'Configured and testable, but not used by WPCC’s AI features yet.', 'wp-command-center' ); ?>"><?php esc_html_e( 'TESTABLE', 'wp-command-center' ); ?></span>
						<?php else : ?><span class="wpcc-aip-badge" style="background:#fcf6e6;color:#8a6a00;"><?php esc_html_e( 'STORED ONLY', 'wp-command-center' ); ?></span><?php endif; ?>
						<?php foreach ( $c['tags'] as $tag ) : ?><span class="wpcc-aip-badge" style="background:#f0f0f1;color:#50575e;">#<?php echo esc_html( $tag ); ?></span> <?php endforeach; ?>
					</div>

					<div class="wpcc-aip-meta">
						<?php if ( Dialect::endpoint_editable( $c['dialect'] ) ) : ?><div><?php esc_html_e( 'Endpoint', 'wp-command-center' ); ?>: <code><?php echo esc_html( $c['endpoint'] ?: '—' ); ?></code></div><?php endif; ?>
						<div><?php esc_html_e( 'Model', 'wp-command-center' ); ?>: <code><?php echo esc_html( $c['model'] ?: ( $def['default_model'] ?? '—' ) ); ?></code></div>
						<?php if ( is_array( $lt ) && isset( $lt['time'] ) ) : ?>
							<div class="muted">
								<?php
								$bits = [];
								$bits[] = sprintf( /* translators: %s ago */ __( 'Last test %s ago', 'wp-command-center' ), human_time_diff( (int) $lt['time'], time() ) );
								if ( ! empty( $lt['latency_ms'] ) ) { $bits[] = sprintf( /* translators: %d ms */ __( '%d ms', 'wp-command-center' ), (int) $lt['latency_ms'] ); }
								if ( ! empty( $lt['models'] ) ) { $bits[] = sprintf( /* translators: %d models */ _n( '%d model', '%d models', (int) $lt['models'], 'wp-command-center' ), (int) $lt['models'] ); }
								echo esc_html( implode( ' · ', $bits ) );
								?>
							</div>
						<?php endif; ?>
						<div class="muted" style="margin-top:2px;"><?php echo esc_html( $h['action'] ); ?></div>
					</div>

					<details>
						<summary style="cursor:pointer;font-size:12px;font-weight:600;color:#2271b1;"><?php esc_html_e( 'Capabilities (declared)', 'wp-command-center' ); ?></summary>
						<div class="wpcc-aip-caps" style="margin-top:8px;">
							<?php foreach ( Capabilities::keys() as $ck => $clabel ) : $cv = $caps[ $ck ] ?? 'no'; ?>
								<span class="wpcc-aip-cap <?php echo in_array( $cv, [ 'yes' ], true ) ? 'on' : ''; ?>"><?php echo esc_html( $clabel ); ?>: <?php echo esc_html( Capabilities::value_label( $cv ) ); ?></span>
							<?php endforeach; ?>
						</div>
						<p class="muted" style="font-size:11px;margin:6px 0 0;"><?php esc_html_e( 'Declared from the provider’s API — not live-tested.', 'wp-command-center' ); ?></p>
					</details>

					<!-- Inline edit -->
					<details>
						<summary style="cursor:pointer;font-size:12px;font-weight:600;color:#2271b1;"><?php esc_html_e( 'Edit', 'wp-command-center' ); ?></summary>
						<form method="post" style="margin-top:8px;display:grid;gap:8px;">
							<?php wp_nonce_field( ConnectionController::NONCE ); ?>
							<input type="hidden" name="wpcc_conn_id" value="<?php echo esc_attr( $cid ); ?>" />
							<label style="font-size:12px;"><?php esc_html_e( 'Name', 'wp-command-center' ); ?><input type="text" name="wpcc_name" value="<?php echo esc_attr( $c['name'] ); ?>" style="width:100%;" /></label>
							<?php if ( Dialect::endpoint_editable( $c['dialect'] ) ) : ?><label style="font-size:12px;"><?php esc_html_e( 'Base URL', 'wp-command-center' ); ?><input type="url" name="wpcc_endpoint" value="<?php echo esc_attr( $c['endpoint'] ); ?>" style="width:100%;font-family:monospace;" /></label><?php endif; ?>
							<?php
								$wpcc_rec   = ( isset( $def['models'] ) && is_array( $def['models'] ) ) ? $def['models'] : [];
								$wpcc_disc  = ( is_array( $lt ) && ! empty( $lt['models_list'] ) && is_array( $lt['models_list'] ) ) ? $lt['models_list'] : [];
								$wpcc_cur   = (string) $c['model'];
								$wpcc_known = isset( $wpcc_rec[ $wpcc_cur ] ) || in_array( $wpcc_cur, $wpcc_disc, true );
								// Copy selection only: providers whose connection test lists account models.
								$wpcc_lists = in_array( $c['dialect'], [ Dialect::OPENAI, Dialect::GEMINI ], true );
								?>
								<label style="font-size:12px;"><?php esc_html_e( 'Model', 'wp-command-center' ); ?>
									<select name="wpcc_model" class="wpcc-edit-model" style="width:100%;">
										<?php if ( $wpcc_rec ) : ?><optgroup label="<?php esc_attr_e( 'Recommended', 'wp-command-center' ); ?>"><?php foreach ( $wpcc_rec as $wpcc_mid => $wpcc_mlabel ) : ?><option value="<?php echo esc_attr( $wpcc_mid ); ?>" <?php selected( $wpcc_cur === (string) $wpcc_mid ); ?>><?php echo esc_html( $wpcc_mlabel ); ?></option><?php endforeach; ?></optgroup><?php endif; ?>
										<?php if ( $wpcc_disc ) : ?><optgroup label="<?php echo esc_attr( sprintf( __( 'Discovered from your account (%d)', 'wp-command-center' ), count( $wpcc_disc ) ) ); ?>"><?php foreach ( $wpcc_disc as $wpcc_did ) : if ( isset( $wpcc_rec[ $wpcc_did ] ) ) { continue; } ?><option value="<?php echo esc_attr( $wpcc_did ); ?>" <?php selected( $wpcc_cur === (string) $wpcc_did ); ?>><?php echo esc_html( $wpcc_did ); ?></option><?php endforeach; ?></optgroup><?php endif; ?>
										<option value="custom" <?php selected( ! $wpcc_known ); ?>><?php esc_html_e( 'Custom model ID…', 'wp-command-center' ); ?></option>
									</select>
								</label>
								<input type="text" name="wpcc_model_custom" class="wpcc-edit-model-custom" value="<?php echo esc_attr( $wpcc_known ? '' : $wpcc_cur ); ?>" style="width:100%;font-family:monospace;<?php echo $wpcc_known ? 'display:none;' : ''; ?>" placeholder="<?php echo esc_attr( (string) ( $def['default_model'] ?? 'model-id' ) ); ?>" />
								<?php if ( ! empty( $wpcc_disc ) ) : ?>
									<p class="muted" style="font-size:11px;margin:0;"><?php esc_html_e( 'Recommended = our defaults. Discovered from your account = pulled live from your last connection test. Custom = enter any model id.', 'wp-command-center' ); ?></p>
								<?php elseif ( $wpcc_lists ) : ?>
									<p class="muted" style="font-size:11px;margin:0;"><?php esc_html_e( 'Test this connection once to discover the additional models available to your account.', 'wp-command-center' ); ?></p>
								<?php else : ?>
									<p class="muted" style="font-size:11px;margin:0;"><?php esc_html_e( 'This provider offers the recommended models only — it doesn’t publish an account model list to discover. Nothing is broken; use Custom to enter any model id.', 'wp-command-center' ); ?></p>
								<?php endif; ?>
							<label style="font-size:12px;"><?php esc_html_e( 'Tags', 'wp-command-center' ); ?><input type="text" name="wpcc_tags" value="<?php echo esc_attr( implode( ', ', $c['tags'] ) ); ?>" style="width:100%;" placeholder="prod, cheap" /></label>
							<div><button type="submit" name="wpcc_conn_action" value="update" class="button button-small"><?php esc_html_e( 'Save changes', 'wp-command-center' ); ?></button></div>
						</form>
						<?php if ( ! $is_const ) : ?>
						<form method="post" style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
							<?php wp_nonce_field( ConnectionController::NONCE ); ?>
							<input type="hidden" name="wpcc_conn_id" value="<?php echo esc_attr( $cid ); ?>" />
							<input type="password" name="wpcc_key" autocomplete="off" spellcheck="false" style="font-family:monospace;max-width:240px;" placeholder="<?php echo $has_key ? esc_attr__( '•••••• (replace key)', 'wp-command-center' ) : esc_attr__( 'API key', 'wp-command-center' ); ?>" />
							<button type="submit" name="wpcc_conn_action" value="update_key" class="button button-small"><?php echo $has_key ? esc_html__( 'Update key', 'wp-command-center' ) : esc_html__( 'Save key', 'wp-command-center' ); ?></button>
							<?php if ( $has_key ) : ?><button type="submit" name="wpcc_conn_action" value="clear_key" class="button button-small button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Remove this connection’s key?', 'wp-command-center' ) ); ?>');"><?php esc_html_e( 'Remove key', 'wp-command-center' ); ?></button><?php endif; ?>
						</form>
						<?php else : ?><p class="muted" style="font-size:12px;margin-top:8px;"><?php esc_html_e( 'Key defined in wp-config.php (constant) — read-only.', 'wp-command-center' ); ?></p><?php endif; ?>
					</details>

					<!-- Actions -->
					<div class="wpcc-aip-actions">
						<form method="post"><?php wp_nonce_field( ConnectionController::NONCE ); ?><input type="hidden" name="wpcc_conn_id" value="<?php echo esc_attr( $cid ); ?>" /><button type="submit" name="wpcc_conn_action" value="test" class="button button-small" <?php disabled( ! $testable || ! $has_key ); ?>><?php esc_html_e( 'Test', 'wp-command-center' ); ?></button></form>
						<?php if ( $runtime && ! $is_def && $has_key ) : ?><form method="post"><?php wp_nonce_field( ConnectionController::NONCE ); ?><input type="hidden" name="wpcc_conn_id" value="<?php echo esc_attr( $cid ); ?>" /><button type="submit" name="wpcc_conn_action" value="set_default" class="button button-small"><?php esc_html_e( 'Set default', 'wp-command-center' ); ?></button></form><?php endif; ?>
						<form method="post"><?php wp_nonce_field( ConnectionController::NONCE ); ?><input type="hidden" name="wpcc_conn_id" value="<?php echo esc_attr( $cid ); ?>" /><input type="hidden" name="wpcc_enabled" value="<?php echo $c['enabled'] ? '0' : '1'; ?>" /><button type="submit" name="wpcc_conn_action" value="set_enabled" class="button button-small"><?php echo $c['enabled'] ? esc_html__( 'Disable', 'wp-command-center' ) : esc_html__( 'Enable', 'wp-command-center' ); ?></button></form>
						<form method="post"><?php wp_nonce_field( ConnectionController::NONCE ); ?><input type="hidden" name="wpcc_conn_id" value="<?php echo esc_attr( $cid ); ?>" /><button type="submit" name="wpcc_conn_action" value="duplicate" class="button button-small"><?php esc_html_e( 'Duplicate', 'wp-command-center' ); ?></button></form>
						<form method="post"><?php wp_nonce_field( ConnectionController::NONCE ); ?><input type="hidden" name="wpcc_conn_id" value="<?php echo esc_attr( $cid ); ?>" /><button type="submit" name="wpcc_conn_action" value="delete" class="button button-small button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Delete this connection and its key?', 'wp-command-center' ) ); ?>');"><?php esc_html_e( 'Delete', 'wp-command-center' ); ?></button></form>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<!-- ===== Feature routing (visual) ===== -->
	<h2><?php esc_html_e( 'Feature routing', 'wp-command-center' ); ?></h2>
	<p class="muted" style="max-width:700px;font-size:13px;"><?php esc_html_e( 'Which connection powers each AI feature. Right now WP Command Center can only run AI tasks through Anthropic (Claude), so only Anthropic connections appear here. Other providers can still be saved and tested — they’ll appear here automatically once WP Command Center can run them. This is the seam where failover and cost routing will live.', 'wp-command-center' ); ?></p>
	<?php if ( empty( $wpcc_runtime_conns ) ) : ?>
		<?php if ( ! empty( $wpcc_ineligible_conns ) ) : ?>
			<p class="muted" style="font-size:13px;max-width:700px;">
				<?php
				/* translators: 1: number of healthy connections, 2: their names. */
				printf( esc_html__( 'You have %1$d connection(s) that connected and tested fine (%2$s) — but WP Command Center can only run AI through Anthropic (Claude) right now, so they can’t power features yet. Add a key to an Anthropic connection to choose routing.', 'wp-command-center' ), count( $wpcc_ineligible_conns ), esc_html( implode( ', ', $wpcc_ineligible_conns ) ) );
				?>
			</p>
		<?php else : ?>
			<p class="muted" style="font-size:13px;"><?php esc_html_e( 'Add a key to an Anthropic connection to choose feature routing.', 'wp-command-center' ); ?></p>
		<?php endif; ?>
	<?php else : ?>
		<form method="post" style="background:#fff;border:1px solid #dcdfe3;border-radius:10px;padding:16px 18px;max-width:560px;">
			<?php wp_nonce_field( ConnectionController::NONCE ); ?>
			<?php foreach ( ConnectionStore::FEATURES as $fk => $flabel ) : ?>
				<div class="wpcc-aip-route">
					<span class="f"><?php echo esc_html( $flabel ); ?><span style="display:block;font-weight:400;font-size:11.5px;color:#8a93a0;"><?php
						$wpcc_fdesc = [ 'seo_meta' => __( 'Powers AI-written SEO titles & descriptions', 'wp-command-center' ), 'alt_text' => __( 'Powers AI image alt text for accessibility & SEO', 'wp-command-center' ), 'ai_content' => __( 'Powers AI title & excerpt suggestions', 'wp-command-center' ) ];
						echo esc_html( $wpcc_fdesc[ $fk ] ?? '' );
					?></span></span>
					<span class="arrow" aria-hidden="true">→</span>
					<label class="screen-reader-text" for="wpcc-route-<?php echo esc_attr( $fk ); ?>"><?php printf( esc_html__( 'Connection for %s', 'wp-command-center' ), esc_html( $flabel ) ); ?></label>
					<select name="wpcc_route_<?php echo esc_attr( $fk ); ?>" id="wpcc-route-<?php echo esc_attr( $fk ); ?>" style="flex:1;">
						<?php foreach ( $wpcc_runtime_conns as $rid => $rname ) : ?>
							<option value="<?php echo esc_attr( $rid ); ?>" <?php selected( ( $wpcc_routes[ $fk ] ?? '' ) === $rid ); ?>><?php echo esc_html( $rname ); ?></option>
						<?php endforeach; ?>
						<?php foreach ( $wpcc_ineligible_conns as $iname ) : ?>
							<option disabled><?php echo esc_html( sprintf( /* translators: %s: connection label */ __( '%s — healthy, but WP Command Center can’t run it yet', 'wp-command-center' ), $iname ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			<?php endforeach; ?>
			<button type="submit" name="wpcc_conn_action" value="save_routes" class="button" style="margin-top:12px;"><?php esc_html_e( 'Save routing', 'wp-command-center' ); ?></button>
			<?php if ( ! empty( $wpcc_ineligible_conns ) ) : ?>
				<p class="muted" style="font-size:12px;margin:12px 0 0;max-width:520px;"><?php esc_html_e( 'Connections marked “healthy, but WP Command Center can’t run it yet” connected and tested successfully — WP Command Center simply can’t run AI tasks through them yet (today it runs through Anthropic / Claude only). They’ll appear as selectable the moment that changes. Nothing is hidden or faked.', 'wp-command-center' ); ?></p>
			<?php endif; ?>
		</form>
	<?php endif; ?>

	<!-- ===== Next steps + security ===== -->
	<?php if ( $wpcc_store->is_configured( $wpcc_conns[ $wpcc_default ] ?? [] ) ) : ?>
		<div style="margin:18px 0 0;padding:12px 14px;background:#f0f6fc;border:1px solid #c3c4c7;border-radius:8px;max-width:720px;">
			<strong style="font-size:13px;"><?php esc_html_e( 'Key added. What happens next?', 'wp-command-center' ); ?></strong>
			<ol style="margin:8px 0 0;padding-left:20px;color:#50575e;font-size:13px;line-height:1.6;">
				<li><?php esc_html_e( 'Use “Test” on a connection to confirm the key works.', 'wp-command-center' ); ?></li>
				<li><?php printf( esc_html__( 'Connect an AI assistant so it can do the work — see %1$sAI Clients%2$s.', 'wp-command-center' ), '<a href="' . esc_url( admin_url( 'admin.php?page=wpcc-connect&wpcc_tab=clients' ) ) . '">', '</a>' ); ?></li>
				<li><?php esc_html_e( 'Adding a key does not turn AI features on by itself. Built-in AI screens are enabled per site; ask your developer to switch them on if you do not see them.', 'wp-command-center' ); ?></li>
			</ol>
		</div>
	<?php endif; ?>

	<p class="muted" style="font-size:12px;max-width:720px;margin-top:20px;">
		<?php esc_html_e( 'Security: each key is stored in this site’s database (a WordPress option, not auto-loaded), used only for calls to that connection’s endpoint, never shown here, never written to the audit log, and never sent anywhere else. Anyone who can edit plugins could read stored options — use scoped keys. The default Anthropic connection also drives WPCC’s AI features (a wp-config constant always wins).', 'wp-command-center' ); ?>
	</p>
</div>

<?php
// PROVIDER-DRIVEN WIZARD METADATA — the single descriptor the wizard renders from
// (ProviderCatalog::metadata()). Data only: NO runtime/provider-execution/key-storage/
// security/API-contract behavior. Adding a provider = a catalog row; the wizard adapts
// with no view change. recommended_models normalize to objects so empty serialises {}.
$wpcc_provider_meta = ProviderCatalog::metadata_all();
foreach ( $wpcc_provider_meta as &$wpcc_m ) {
	$wpcc_m['recommended_models'] = ! empty( $wpcc_m['recommended_models'] ) ? $wpcc_m['recommended_models'] : new stdClass();
}
unset( $wpcc_m );
?>
<script>
(function () {
	var wiz = document.getElementById('wpcc-aip-wizard');
	if (!wiz) return;

	// ---- Provider-driven wizard: every field renders from provider metadata
	// (ProviderCatalog::metadata) — no provider-specific conditionals. Adding a
	// provider needs no code here. Model discovery is a gated seam (no backend
	// listing endpoint exists yet → curated fallback; never fabricated). ----
	var WPCC_PMETA = <?php echo wp_json_encode( $wpcc_provider_meta ); ?>;
	(function () {
		var provSel = document.getElementById('wpcc-w-provider');
		var epField = document.getElementById('wpcc-w-endpoint-field');
		var epInput = document.getElementById('wpcc-w-endpoint');
		var depField= document.getElementById('wpcc-w-deployment-field');
		var mdlSel  = document.getElementById('wpcc-w-model-select');
		var mdlTxt  = document.getElementById('wpcc-w-model');
		var mdlSrch = document.getElementById('wpcc-w-model-search');
		var mdlHelp = document.getElementById('wpcc-w-model-help');
		var mFlag   = wiz.querySelector('input[name="wpcc_model"]');
		if (!provSel || !mdlSel || !mdlTxt || !mFlag) return;
		var CUSTOM = '__custom__';
		var CUSTOM_LABEL = <?php echo wp_json_encode( __( 'Custom model ID…', 'wp-command-center' ) ); ?>;
		var FREE_HELP = <?php echo wp_json_encode( __( 'Enter the model id your endpoint serves (free text).', 'wp-command-center' ) ); ?>;
		var DISC_HELP = <?php echo wp_json_encode( __( 'Discovering models…', 'wp-command-center' ) ); ?>;
		var THRESHOLD = <?php echo (int) ProviderCatalog::SEARCH_THRESHOLD; ?>;

		function syncModel() {
			if (mdlSel.style.display === 'none') { mFlag.value = 'custom'; return; }
			if (mdlSel.value === CUSTOM) { mFlag.value = 'custom'; mdlTxt.style.display = ''; mdlTxt.focus(); }
			else { mFlag.value = mdlSel.value; mdlTxt.style.display = 'none'; mdlTxt.value = ''; }
		}
		function filterModels() {
			var q = (mdlSrch.value || '').toLowerCase();
			Array.prototype.forEach.call(mdlSel.options, function (o) {
				if (o.value === CUSTOM) { return; } // custom stays reachable
				o.hidden = q !== '' && o.textContent.toLowerCase().indexOf(q) === -1 && o.value.toLowerCase().indexOf(q) === -1;
			});
		}
		// Render the model control from a {id:label} map (curated, or future discovered).
		function populate(meta, models) {
			var ids = models ? Object.keys(models) : [];
			if (ids.length) {
				mdlSel.innerHTML = '';
				ids.forEach(function (id) { var o = document.createElement('option'); o.value = id; o.textContent = models[id]; mdlSel.appendChild(o); });
				if (meta.supports_custom_model !== false) { var c = document.createElement('option'); c.value = CUSTOM; c.textContent = CUSTOM_LABEL; mdlSel.appendChild(c); }
				mdlSel.value = (meta.default_model && models[meta.default_model]) ? meta.default_model : ids[0];
				mdlSel.style.display = ''; mdlTxt.style.display = 'none'; mdlTxt.value = '';
				if (mdlSrch) { mdlSrch.style.display = (ids.length > THRESHOLD || meta.supports_search) ? '' : 'none'; mdlSrch.value = ''; filterModels(); }
				if (mdlHelp) { mdlHelp.textContent = ''; }
				syncModel();
			} else {
				// No list, no discovery → free text (local / gateway / custom). Never an empty dropdown.
				mdlSel.style.display = 'none';
				if (mdlSrch) { mdlSrch.style.display = 'none'; }
				mdlTxt.style.display = ''; mFlag.value = 'custom';
				if (mdlHelp) { mdlHelp.textContent = FREE_HELP; }
			}
		}
		function renderModels(meta) {
			var curated = (meta.recommended_models && typeof meta.recommended_models === 'object') ? meta.recommended_models : {};
			// Discovery seam: used only when the provider advertises discovery AND a
			// discovery transport is registered. None exists today, so this always
			// falls back to the curated list — no fabricated models.
			if (meta.supports_discovery && typeof window.wpccDiscoverModels === 'function') {
				if (mdlHelp) { mdlHelp.textContent = DISC_HELP; }
				try {
					window.wpccDiscoverModels(meta, function (discovered) {
						populate(meta, (discovered && Object.keys(discovered).length) ? discovered : curated);
					});
					return;
				} catch (e) { /* fall through to curated */ }
			}
			populate(meta, curated);
		}
		function applyProvider() {
			var meta = WPCC_PMETA[provSel.value] || {};
			if (epField) {
				epField.style.display = meta.requires_endpoint ? '' : 'none';
				if (epInput) { epInput.placeholder = meta.default_endpoint || 'https://…'; if (!meta.requires_endpoint) { epInput.value = ''; } }
			}
			if (depField) { depField.style.display = meta.needs_deployment ? '' : 'none'; }
			renderModels(meta);
		}
		provSel.addEventListener('change', applyProvider);
		mdlSel.addEventListener('change', syncModel);
		if (mdlSrch) { mdlSrch.addEventListener('input', filterModels); }
		applyProvider();
	})();
	var steps = wiz.querySelectorAll('.wpcc-aip-step');
	var bars  = wiz.querySelectorAll('.wpcc-aip-steps .s');
	var back  = document.getElementById('wpcc-w-back');
	var next  = document.getElementById('wpcc-w-next');
	var fin   = document.getElementById('wpcc-w-finish');
	var i = 0;
	function show(n){
		i = Math.max(0, Math.min(steps.length-1, n));
		steps.forEach(function(s,x){ s.classList.toggle('active', x===i); });
		bars.forEach(function(b,x){ b.classList.toggle('active', x<=i); });
		back.style.visibility = i===0 ? 'hidden' : 'visible';
		next.style.display = i===steps.length-1 ? 'none' : '';
		fin.style.display  = i===steps.length-1 ? '' : 'none';
		var h = steps[i].querySelector('h3'); if (h){ h.setAttribute('tabindex','-1'); h.focus(); }
	}
	function open(){ wiz.classList.add('open'); show(0); var t=document.getElementById('wpcc-aip-new'); if(t) t.setAttribute('aria-expanded','true'); wiz.scrollIntoView({behavior:'smooth',block:'nearest'}); }
	function close(){ wiz.classList.remove('open'); var t=document.getElementById('wpcc-aip-new'); if(t){ t.setAttribute('aria-expanded','false'); t.focus(); } }
	['wpcc-aip-new','wpcc-aip-new2'].forEach(function(id){ var b=document.getElementById(id); if(b) b.addEventListener('click', open); });
	next.addEventListener('click', function(){ show(i+1); });
	back.addEventListener('click', function(){ show(i-1); });
	document.getElementById('wpcc-w-cancel').addEventListener('click', close);
	// Edit-card model selectors: reveal the custom text input only when "Custom" is chosen.
	Array.prototype.forEach.call(document.querySelectorAll('.wpcc-edit-model'), function (sel) {
		var form = sel.closest ? sel.closest('form') : null;
		var txt = form ? form.querySelector('.wpcc-edit-model-custom') : null;
		if (!txt) return;
		function toggle(){ txt.style.display = (sel.value === 'custom') ? '' : 'none'; }
		sel.addEventListener('change', toggle); toggle();
	});

	// Progressive enhancement: JS active → start collapsed as a wizard.
	wiz.classList.remove('open');
})();
</script>
