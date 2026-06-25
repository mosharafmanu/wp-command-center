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

$wpcc_notice = ( new ConnectionController() )->handle_post();

$wpcc_store     = new ConnectionStore();
$wpcc_conns     = $wpcc_store->all();
$wpcc_default   = $wpcc_store->default_id();
$wpcc_routes    = $wpcc_store->routes();
$wpcc_providers = ProviderCatalog::all();
$wpcc_health    = Health::summary( $wpcc_conns, $wpcc_store );

// Runtime-usable, configured connections (for routing).
$wpcc_runtime_conns = [];
foreach ( $wpcc_conns as $cid => $c ) {
	if ( $wpcc_store->runtime_usable( $c ) && $wpcc_store->is_configured( $c ) && $c['enabled'] ) {
		$wpcc_runtime_conns[ $cid ] = $c['name'];
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
.wpcc-aip-score { text-align:center; min-width:120px; }
.wpcc-aip-ring { --v:0; width:84px; height:84px; border-radius:50%; margin:0 auto 6px; background:conic-gradient(#3ec46d calc(var(--v)*1%), rgba(255,255,255,.15) 0); display:flex; align-items:center; justify-content:center; }
.wpcc-aip-ring span { width:64px; height:64px; border-radius:50%; background:#1d2734; display:flex; align-items:center; justify-content:center; font-size:20px; font-weight:700; color:#fff; }
.wpcc-aip-kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:12px; margin-bottom:8px; }
.wpcc-aip-kpi { background:#fff; border:1px solid #dcdfe3; border-radius:10px; padding:14px 16px; }
.wpcc-aip-kpi .v { font-size:24px; font-weight:700; color:#1d2327; line-height:1.1; }
.wpcc-aip-kpi .l { font-size:12px; color:#646970; text-transform:uppercase; letter-spacing:.4px; margin-top:3px; }
.wpcc-aip-warn { background:#fcf6e6; border:1px solid #f0d97a; border-radius:8px; padding:12px 16px; margin:10px 0; font-size:13px; }
.wpcc-aip-cards { display:grid; grid-template-columns:repeat(auto-fill,minmax(330px,1fr)); gap:14px; }
.wpcc-aip-card { background:#fff; border:1px solid #dcdfe3; border-radius:12px; padding:16px 18px; box-shadow:0 1px 2px rgba(0,0,0,.04); display:flex; flex-direction:column; gap:8px; }
.wpcc-aip-card.dim { opacity:.62; }
.wpcc-aip-card__top { display:flex; justify-content:space-between; align-items:flex-start; gap:8px; }
.wpcc-aip-avatar { width:38px; height:38px; border-radius:9px; background:#eef2f7; color:#2c3a4f; font-weight:700; display:flex; align-items:center; justify-content:center; font-size:15px; flex:0 0 auto; }
.wpcc-aip-name { font-size:15px; font-weight:700; line-height:1.2; }
.wpcc-aip-sub { font-size:12px; color:#646970; }
.wpcc-aip-dot { width:9px; height:9px; border-radius:50%; display:inline-block; vertical-align:middle; margin-right:5px; }
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
.wpcc-aip-route { display:flex; align-items:center; gap:10px; padding:10px 0; border-bottom:1px solid #f0f0f1; }
.wpcc-aip-route .f { min-width:120px; font-weight:600; }
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
			<h1><?php esc_html_e( 'AI Connections', 'wp-command-center' ); ?></h1>
			<p><?php esc_html_e( 'Your AI control panel. Connect any provider — cloud, local, or a gateway — see what’s healthy, and choose which connection powers each feature. AI stays off until you add a key and turn a feature on.', 'wp-command-center' ); ?></p>
		</div>
		<div class="wpcc-aip-score" aria-label="<?php echo esc_attr( sprintf( /* translators: %d score */ __( 'Setup readiness %d percent', 'wp-command-center' ), (int) $wpcc_ready ) ); ?>">
			<div class="wpcc-aip-ring" style="--v:<?php echo (int) $wpcc_ready; ?>"><span><?php echo (int) $wpcc_ready; ?></span></div>
			<div style="font-size:12px;color:#b9c4d2;"><?php esc_html_e( 'Setup readiness', 'wp-command-center' ); ?></div>
		</div>
	</div>

	<!-- ===== KPIs ===== -->
	<div class="wpcc-aip-kpis">
		<div class="wpcc-aip-kpi"><div class="v"><?php echo (int) $wpcc_health['total']; ?></div><div class="l"><?php esc_html_e( 'Connections', 'wp-command-center' ); ?></div></div>
		<div class="wpcc-aip-kpi"><div class="v" style="color:<?php echo $wpcc_health['attention'] ? '#d63638' : '#0a7a33'; ?>;"><?php echo (int) $wpcc_health['healthy']; ?></div><div class="l"><?php esc_html_e( 'Healthy', 'wp-command-center' ); ?></div></div>
		<div class="wpcc-aip-kpi"><div class="v"><?php echo esc_html( $wpcc_default_name ); ?></div><div class="l"><?php esc_html_e( 'Default environment', 'wp-command-center' ); ?></div></div>
		<div class="wpcc-aip-kpi"><div class="v"><?php echo $wpcc_has_healthy ? esc_html__( 'Ready', 'wp-command-center' ) : esc_html__( 'Off', 'wp-command-center' ); ?></div><div class="l"><?php esc_html_e( 'AI status', 'wp-command-center' ); ?></div></div>
	</div>

	<?php if ( $wpcc_health['attention'] > 0 ) : ?>
		<div class="wpcc-aip-warn" role="status">⚠ <?php printf( esc_html( _n( '%d connection needs attention. Open it below for the recommended fix.', '%d connections need attention. Open them below for the recommended fix.', $wpcc_health['attention'], 'wp-command-center' ) ), (int) $wpcc_health['attention'] ); ?></div>
	<?php elseif ( '' === $wpcc_default && ! empty( $wpcc_conns ) ) : ?>
		<div class="wpcc-aip-warn" role="status"><?php esc_html_e( 'No default connection yet. Add a key to an Anthropic connection and set it as default so AI features have something to use.', 'wp-command-center' ); ?></div>
	<?php endif; ?>

	<!-- ===== Quick action ===== -->
	<p style="margin:14px 0;"><button type="button" class="button button-primary button-hero" id="wpcc-aip-new" aria-expanded="false" aria-controls="wpcc-aip-wizard">+ <?php esc_html_e( 'New connection', 'wp-command-center' ); ?></button></p>

	<!-- ===== Connection wizard (progressive; degrades to a full form without JS) ===== -->
	<form method="post" class="wpcc-aip-wizard" id="wpcc-aip-wizard" aria-label="<?php esc_attr_e( 'New connection wizard', 'wp-command-center' ); ?>">
		<?php wp_nonce_field( ConnectionController::NONCE ); ?>
		<input type="hidden" name="wpcc_conn_action" value="create" />
		<input type="hidden" name="wpcc_model" value="custom" />
		<div class="wpcc-aip-steps" aria-hidden="true"><span class="s active"></span><span class="s"></span><span class="s"></span><span class="s"></span><span class="s"></span></div>

		<div class="wpcc-aip-step active" data-step="1">
			<h3><?php esc_html_e( 'Step 1 — Choose a provider', 'wp-command-center' ); ?></h3>
			<p class="muted" style="font-size:13px;"><?php esc_html_e( 'Pick the AI service. Only Anthropic powers WPCC’s features today; others can be saved and tested now.', 'wp-command-center' ); ?></p>
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
			<div class="wpcc-aip-field"><label for="wpcc-w-endpoint"><?php esc_html_e( 'Base URL', 'wp-command-center' ); ?> <span class="muted">(<?php esc_html_e( 'local / gateway / Azure / custom only — blank for cloud defaults', 'wp-command-center' ); ?>)</span></label><input type="url" id="wpcc-w-endpoint" name="wpcc_endpoint" style="font-family:monospace;" placeholder="http://localhost:11434/v1" /></div>
		</div>

		<div class="wpcc-aip-step" data-step="3">
			<h3><?php esc_html_e( 'Step 3 — Credentials', 'wp-command-center' ); ?></h3>
			<p class="muted" style="font-size:13px;"><?php esc_html_e( 'Paste your API key. It is stored on this site and never shown again. Local models usually need no key.', 'wp-command-center' ); ?></p>
			<div class="wpcc-aip-field"><label for="wpcc-w-key"><?php esc_html_e( 'API key', 'wp-command-center' ); ?></label><input type="password" id="wpcc-w-key" name="wpcc_key" autocomplete="off" spellcheck="false" style="font-family:monospace;" placeholder="<?php esc_attr_e( 'Paste your API key (optional for local)', 'wp-command-center' ); ?>" /></div>
		</div>

		<div class="wpcc-aip-step" data-step="4">
			<h3><?php esc_html_e( 'Step 4 — Model', 'wp-command-center' ); ?></h3>
			<p class="muted" style="font-size:13px;"><?php esc_html_e( 'Leave blank to use the provider’s recommended default. You can change it any time.', 'wp-command-center' ); ?></p>
			<div class="wpcc-aip-field"><label for="wpcc-w-model"><?php esc_html_e( 'Model', 'wp-command-center' ); ?></label><input type="text" id="wpcc-w-model" name="wpcc_model_custom" style="font-family:monospace;" placeholder="model-id" /></div>
			<div class="wpcc-aip-field"><label for="wpcc-w-tags"><?php esc_html_e( 'Tags', 'wp-command-center' ); ?></label><input type="text" id="wpcc-w-tags" name="wpcc_tags" placeholder="prod, premium" /></div>
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
							<input type="hidden" name="wpcc_model" value="custom" />
							<label style="font-size:12px;"><?php esc_html_e( 'Name', 'wp-command-center' ); ?><input type="text" name="wpcc_name" value="<?php echo esc_attr( $c['name'] ); ?>" style="width:100%;" /></label>
							<?php if ( Dialect::endpoint_editable( $c['dialect'] ) ) : ?><label style="font-size:12px;"><?php esc_html_e( 'Base URL', 'wp-command-center' ); ?><input type="url" name="wpcc_endpoint" value="<?php echo esc_attr( $c['endpoint'] ); ?>" style="width:100%;font-family:monospace;" /></label><?php endif; ?>
							<label style="font-size:12px;"><?php esc_html_e( 'Model', 'wp-command-center' ); ?><input type="text" name="wpcc_model_custom" value="<?php echo esc_attr( $c['model'] ); ?>" style="width:100%;font-family:monospace;" placeholder="<?php echo esc_attr( (string) ( $def['default_model'] ?? '' ) ); ?>" /></label>
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
	<p class="muted" style="max-width:700px;font-size:13px;"><?php esc_html_e( 'Which connection powers each AI feature. Only connections WP Command Center can actually run are offered — this is the seam where failover and cost routing will live.', 'wp-command-center' ); ?></p>
	<?php if ( empty( $wpcc_runtime_conns ) ) : ?>
		<p class="muted" style="font-size:13px;"><?php esc_html_e( 'Add a key to an Anthropic connection to choose feature routing.', 'wp-command-center' ); ?></p>
	<?php else : ?>
		<form method="post" style="background:#fff;border:1px solid #dcdfe3;border-radius:10px;padding:16px 18px;max-width:560px;">
			<?php wp_nonce_field( ConnectionController::NONCE ); ?>
			<?php foreach ( ConnectionStore::FEATURES as $fk => $flabel ) : ?>
				<div class="wpcc-aip-route">
					<span class="f"><?php echo esc_html( $flabel ); ?></span>
					<span class="arrow" aria-hidden="true">→</span>
					<label class="screen-reader-text" for="wpcc-route-<?php echo esc_attr( $fk ); ?>"><?php printf( esc_html__( 'Connection for %s', 'wp-command-center' ), esc_html( $flabel ) ); ?></label>
					<select name="wpcc_route_<?php echo esc_attr( $fk ); ?>" id="wpcc-route-<?php echo esc_attr( $fk ); ?>" style="flex:1;">
						<?php foreach ( $wpcc_runtime_conns as $rid => $rname ) : ?>
							<option value="<?php echo esc_attr( $rid ); ?>" <?php selected( ( $wpcc_routes[ $fk ] ?? '' ) === $rid ); ?>><?php echo esc_html( $rname ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			<?php endforeach; ?>
			<button type="submit" name="wpcc_conn_action" value="save_routes" class="button" style="margin-top:12px;"><?php esc_html_e( 'Save routing', 'wp-command-center' ); ?></button>
		</form>
	<?php endif; ?>

	<!-- ===== Next steps + security ===== -->
	<?php if ( $wpcc_store->is_configured( $wpcc_conns[ $wpcc_default ] ?? [] ) ) : ?>
		<div style="margin:18px 0 0;padding:12px 14px;background:#f0f6fc;border:1px solid #c3c4c7;border-radius:8px;max-width:720px;">
			<strong style="font-size:13px;"><?php esc_html_e( 'Key added. What happens next?', 'wp-command-center' ); ?></strong>
			<ol style="margin:8px 0 0;padding-left:20px;color:#50575e;font-size:13px;line-height:1.6;">
				<li><?php esc_html_e( 'Use “Test” on a connection to confirm the key works.', 'wp-command-center' ); ?></li>
				<li><?php printf( esc_html__( 'Connect an AI assistant so it can do the work — see %1$sConnect an AI Agent%2$s.', 'wp-command-center' ), '<a href="' . esc_url( admin_url( 'admin.php?page=wpcc-connect&wpcc_tab=integrations' ) ) . '">', '</a>' ); ?></li>
				<li><?php esc_html_e( 'Adding a key does not turn AI features on by itself. Built-in AI screens are enabled per site; ask your developer to switch them on if you do not see them.', 'wp-command-center' ); ?></li>
			</ol>
		</div>
	<?php endif; ?>

	<p class="muted" style="font-size:12px;max-width:720px;margin-top:20px;">
		<?php esc_html_e( 'Security: each key is stored in this site’s database (a WordPress option, not auto-loaded), used only for calls to that connection’s endpoint, never shown here, never written to the audit log, and never sent anywhere else. Anyone who can edit plugins could read stored options — use scoped keys. The default Anthropic connection also drives WPCC’s AI features (a wp-config constant always wins).', 'wp-command-center' ); ?>
	</p>
</div>

<script>
(function () {
	var wiz = document.getElementById('wpcc-aip-wizard');
	if (!wiz) return;
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
	// Progressive enhancement: JS active → start collapsed as a wizard.
	wiz.classList.remove('open');
})();
</script>
