<?php
/**
 * Step 48 — AI Integrations UX (Client Integration Layer).
 *
 * Central location for all AI client setup. Tabbed interface:
 * Clients, Configuration, Activity, Security.
 */
defined( 'ABSPATH' ) || exit;

use WPCommandCenter\Integration\AIClientRegistry;
use WPCommandCenter\Integration\ClaudeIntegration;
use WPCommandCenter\Security\AuthTokens;
use WPCommandCenter\Operations\OperationRegistry;

$wpcc_tokens      = new AuthTokens();
$wpcc_all_tokens   = $wpcc_tokens->list();
$wpcc_clients      = AIClientRegistry::get_clients();
$wpcc_active_clients = AIClientRegistry::get_active_clients();
$wpcc_counts       = AIClientRegistry::get_counts();
$wpcc_matrix       = AIClientRegistry::get_compatibility_matrix();
$wpcc_ops          = ( new OperationRegistry() )->get_operations();
$wpcc_tool_count   = count( $wpcc_ops );

// Selected client for config tab
$wpcc_selected_client = sanitize_key( (string) ( $_GET['client'] ?? 'claude' ) );
$wpcc_current_client  = AIClientRegistry::get_client( $wpcc_selected_client );
if ( ! $wpcc_current_client || \WPCommandCenter\Integration\AIClientRegistry::CERT_PLANNED === ( $wpcc_current_client['certification_level'] ?? '' ) ) {
	$wpcc_selected_client = 'claude';
	$wpcc_current_client  = AIClientRegistry::get_client( 'claude' );
}

$wpcc_config      = AIClientRegistry::generate_config( $wpcc_selected_client );
$wpcc_config_json = $wpcc_config ? wp_json_encode( $wpcc_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) : '';

// All AI client activity from audit log
$wpcc_audit         = new \WPCommandCenter\Security\AuditLog();
$wpcc_entries       = $wpcc_audit->tail( 200 );
$wpcc_ai_activity   = [];
foreach ( $wpcc_entries as $entry ) {
	if ( str_starts_with( $entry['action'], 'claude.' ) || str_starts_with( $entry['action'], 'ai_client.' ) || str_starts_with( $entry['action'], 'mcp.' ) ) {
		$wpcc_ai_activity[] = $entry;
	}
	if ( count( $wpcc_ai_activity ) >= 15 ) {
		break;
	}
}

// Handle token generation
$wpcc_new_token     = null;
$wpcc_token_message  = '';
$wpcc_token_error    = '';

if ( isset( $_POST['wpcc_token_action'] ) && check_admin_referer( 'wpcc_ai_integrations' ) && current_user_can( 'manage_options' ) ) {
	$wpcc_token_action = sanitize_key( $_POST['wpcc_token_action'] );

	if ( 'generate_read_only' === $wpcc_token_action || 'generate_full' === $wpcc_token_action ) {
		$scope = 'generate_read_only' === $wpcc_token_action ? AuthTokens::SCOPE_READ_ONLY : AuthTokens::SCOPE_FULL;
		$label = sprintf(
			'%s %s',
			'generate_read_only' === $wpcc_token_action ? __( 'AI Read-only', 'wp-command-center' ) : __( 'AI Full Access', 'wp-command-center' ),
			gmdate( 'Y-m-d H:i' )
		);
		$result = $wpcc_tokens->create( $label, $scope, null, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			$wpcc_token_error = $result->get_error_message();
		} else {
			$wpcc_new_token    = $result['token'];
			$wpcc_token_message = __( 'Token generated. Copy it now — it will not be shown again.', 'wp-command-center' );

			// Inject token into config
			if ( $wpcc_config ) {
				$wpcc_config['mcpServers']['wp-command-center']['env']['WPCC_TOKEN'] = $wpcc_new_token;
				$wpcc_config_json = wp_json_encode( $wpcc_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			}
			$wpcc_all_tokens = $wpcc_tokens->list();
		}
	}
}

// Config with selected token
$wpcc_selected_token_id = sanitize_text_field( (string) ( $_GET['token_id'] ?? '' ) );
if ( $wpcc_selected_token_id && $wpcc_config ) {
	$selected = array_filter( $wpcc_all_tokens, fn( $t ) => $t['id'] === $wpcc_selected_token_id );
	if ( ! empty( $selected ) ) {
		// A selected token keeps the same minimal env as the default config: only the
		// WPCC_TOKEN placeholder is swapped to the clearer "paste your token here" form.
		// The token value is shown only once at creation, so it stays a placeholder; no
		// token metadata (id/label/scope) is added — the relay needs only the token value
		// plus the runtime env vars.
		$wpcc_config['mcpServers']['wp-command-center']['env']['WPCC_TOKEN'] = 'wpcc_YOUR_TOKEN_HERE';
		$wpcc_config_json = wp_json_encode( $wpcc_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}
}

// Active tab
$wpcc_tab = sanitize_key( (string) ( $_GET['tab'] ?? 'clients' ) );
$wpcc_tabs = [
	'clients'       => __( 'Clients', 'wp-command-center' ),
	'configuration' => __( 'Configuration', 'wp-command-center' ),
	'activity'      => __( 'Activity', 'wp-command-center' ),
	'security'      => __( 'Security', 'wp-command-center' ),
];
if ( ! isset( $wpcc_tabs[ $wpcc_tab ] ) ) {
	$wpcc_tab = 'clients';
}
?>
<style>
	/* AI Clients — scoped polish. Premium, light, wp-admin compatible. Reuses CDS
	   chip classes (wpcc-cds-chip--*) loaded site-wide; everything else is scoped. */
	.wpcc-ai-wrap { max-width: 1020px; }
	.wpcc-ai-wrap h1 { margin-bottom: 4px; }
	.wpcc-ai-wrap a:focus-visible,
	.wpcc-ai-wrap button:focus-visible,
	.wpcc-ai-tab:focus-visible { outline: 2px solid #2271b1; outline-offset: 2px; border-radius: 7px; }

	/* Hero / explainer */
	.wpcc-ai-hero { background: #fff; border: 1px solid #e3e5ec; border-radius: 14px; padding: 24px 26px; margin: 14px 0 24px; box-shadow: 0 1px 2px rgba(16,24,40,.04), 0 8px 24px rgba(16,24,40,.05); }
	.wpcc-ai-hero .wpcc-ai-lead { font-size: 16px; line-height: 1.55; color: #1d2327; max-width: 70ch; margin: 0 0 10px; font-weight: 600; }
	.wpcc-ai-hero p { font-size: 14.5px; line-height: 1.6; color: #4b5161; max-width: 72ch; margin: 0; }
	.wpcc-ai-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 18px; align-items: center; }
	.wpcc-ai-chips .lbl { font-size: 12.5px; font-weight: 600; color: #646970; margin-right: 2px; }

	/* Tabs */
	.wpcc-ai-tabs { display: flex; gap: 6px; border-bottom: 1px solid #e3e5ec; margin-bottom: 24px; }
	.wpcc-ai-tab { padding: 10px 16px; border-radius: 8px 8px 0 0; border: 1px solid transparent; border-bottom: none; cursor: pointer; font-size: 13px; font-weight: 600; background: transparent; color: #50575e; text-decoration: none; transition: background .12s ease, color .12s ease; }
	.wpcc-ai-tab:hover { background: #f2f3f7; color: #1d2327; }
	.wpcc-ai-tab--active { background: #fff; border-color: #e3e5ec; box-shadow: 0 -2px 0 #2271b1 inset; color: #1d2327; }

	/* Metric cards */
	.wpcc-ai-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 26px; }
	.wpcc-ai-stat { background: #fff; border: 1px solid #e3e5ec; padding: 18px 20px; border-radius: 12px; box-shadow: 0 1px 2px rgba(16,24,40,.04); }
	.wpcc-ai-stat__value { font-size: 30px; font-weight: 700; color: #1d2327; line-height: 1.1; letter-spacing: -.01em; }
	.wpcc-ai-stat__label { font-size: 11.5px; color: #646970; margin-top: 6px; text-transform: uppercase; letter-spacing: .5px; font-weight: 600; }
	.wpcc-ai-stat--good .wpcc-ai-stat__value { color: #008a25; }

	/* Panels */
	.wpcc-ai-panel { background: #fff; border: 1px solid #e3e5ec; border-radius: 12px; margin-bottom: 22px; box-shadow: 0 1px 2px rgba(16,24,40,.04); overflow: hidden; }
	.wpcc-ai-panel__header { padding: 15px 22px; border-bottom: 1px solid #eef0f4; font-size: 14.5px; font-weight: 600; display: flex; justify-content: space-between; align-items: center; gap: 12px; }
	.wpcc-ai-panel__body { padding: 22px; }
	.wpcc-ai-panel__hint { margin: 14px 0 0; color: #646970; font-size: 12.5px; }

	/* Config + code blocks (functional — Configuration tab) */
	.wpcc-ai-config { background: #1d2327; color: #c3c4c7; padding: 18px; border-radius: 10px; font-family: ui-monospace,SFMono-Regular,Menlo,monospace; font-size: 13px; line-height: 1.6; overflow-x: auto; white-space: pre-wrap; word-break: break-all; max-height: 400px; overflow-y: auto; position: relative; }
	.wpcc-ai-code { background: #f6f7f9; border: 1px solid #e3e5ec; border-radius: 9px; padding: 9px 12px; font-family: ui-monospace,SFMono-Regular,Menlo,monospace; font-size: 13px; word-break: break-all; margin: 8px 0; display: flex; justify-content: space-between; align-items: center; }
	.wpcc-ai-code__text { flex: 1; margin-right: 10px; }

	/* Tables */
	.wpcc-ai-token-table { width: 100%; border-collapse: collapse; }
	.wpcc-ai-token-table th { text-align: left; padding: 10px 12px; border-bottom: 1px solid #e3e5ec; font-weight: 600; font-size: 11.5px; text-transform: uppercase; letter-spacing: .4px; color: #646970; }
	.wpcc-ai-token-table td { padding: 11px 12px; border-bottom: 1px solid #eef0f4; font-size: 13.5px; }
	.wpcc-ai-token-table tbody tr:hover { background: #fafbfc; }
	.wpcc-ai-token-table tbody tr:last-child td { border-bottom: none; }

	.wpcc-ai-verify-result { margin-top: 12px; padding: 14px; border-radius: 10px; display: none; }
	.wpcc-ai-verify-result--success { background: #edfaef; border: 1px solid #00a32a; display: block; }
	.wpcc-ai-verify-result--fail { background: #fcf0f1; border: 1px solid #d63638; display: block; }
	.wpcc-ai-verify-result--loading { background: #f0f6fc; border: 1px solid #2271b1; display: block; }

	.wpcc-ai-security-list { list-style: none; padding: 0; margin: 14px 0 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 12px; }
	.wpcc-ai-security-list li { padding: 13px 16px; background: #f7f8fb; border: 1px solid #eef0f4; border-left: 3px solid #00a32a; border-radius: 0 10px 10px 0; }
	.wpcc-ai-security-list li strong { display: block; margin-bottom: 4px; }
	.wpcc-ai-security-list li span { font-size: 12.5px; color: #646970; }

	.wpcc-badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; }
	.wpcc-badge--good { background: #e7f6ee; color: #008a25; }
	.wpcc-badge--neutral { background: #eef0f4; color: #50575e; }
	.wpcc-badge--critical { background: #fcf0f1; color: #d63638; }
	.wpcc-badge--info { background: #eef4fc; color: #1f5fa8; }
	.wpcc-ai-notice { margin: 0 0 16px 0; }
	.wpcc-ai-copied { color: #00a32a; font-size: 12px; display: inline-block; margin-left: 8px; opacity: 0; transition: opacity .2s; }
	.wpcc-ai-copied--visible { opacity: 1; }

	/* Active client cards */
	.wpcc-ai-client-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(290px, 1fr)); gap: 16px; }
	.wpcc-ai-client-card { background: #fff; border: 1px solid #e3e5ec; border-radius: 12px; padding: 18px 20px; box-shadow: 0 1px 2px rgba(16,24,40,.04); transition: transform .12s ease, box-shadow .12s ease; }
	.wpcc-ai-client-card:hover { transform: translateY(-2px); box-shadow: 0 1px 2px rgba(16,24,40,.04), 0 10px 26px rgba(16,24,40,.07); }
	.wpcc-ai-client-card--active { border-left: 4px solid #00a32a; }
	.wpcc-ai-client-card--planned { border-left: 4px solid #c3c4c7; opacity: 0.7; }
	.wpcc-ai-client-card h3 { margin: 0 0 4px; font-size: 15.5px; }
	.wpcc-ai-client-card .vendor { font-size: 12.5px; color: #646970; }
	.wpcc-ai-client-card .type { font-size: 11px; color: #646970; margin-top: 6px; }
	.wpcc-ai-client-card .desc { font-size: 13px; color: #3c434a; margin-top: 10px; line-height: 1.5; }
	@media (max-width: 600px) { .wpcc-ai-hero { padding: 20px; } }

	/* MCP setup page */
	.wpcc-ai-setup .wpcc-ai-panel__header { gap:10px; }
	.wpcc-setup-status { font-size:12.5px;font-weight:600;color:#008a25;display:inline-flex;align-items:center;gap:6px;text-transform:none;letter-spacing:0; }
	.wpcc-setup-dot { width:9px;height:9px;border-radius:50%;background:#00a32a;box-shadow:0 0 0 3px #e7f6ee;display:inline-block; }
	.wpcc-ai-field { margin:0 0 20px; }
	.wpcc-ai-field:last-child { margin-bottom:0; }
	.wpcc-ai-field__label { display:block;font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#646970;margin-bottom:6px; }
	.wpcc-ai-field__hint { font-size:12.5px;color:#646970;margin:6px 0 0; }
	.wpcc-ai-field__status { font-size:13.5px;color:#3c434a;margin:0; }
	.wpcc-ai-field__status--ok { color:#1d6b3f; }
	.wpcc-ai-url { display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:#f6f7f9;border:1px solid #e3e5ec;border-radius:9px;padding:8px 10px 8px 14px; }
	.wpcc-ai-url__text { flex:1;min-width:200px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;color:#1d2327;word-break:break-all;background:none; }
	.wpcc-ai-setup__actions { display:flex;gap:10px;flex-wrap:wrap;margin-top:6px; }
	.wpcc-ai-steps { margin:0;padding:0;list-style:none;counter-reset:wpcc-step; }
	.wpcc-ai-steps li { counter-increment:wpcc-step;position:relative;padding:8px 0 8px 38px;font-size:14px;color:#3c434a;border-top:1px solid #f0f1f4; }
	.wpcc-ai-steps li:first-child { border-top:none; }
	.wpcc-ai-steps li::before { content:counter(wpcc-step);position:absolute;left:0;top:7px;width:26px;height:26px;border-radius:50%;background:#eef4fc;color:#1f5fa8;font-weight:700;font-size:13px;display:flex;align-items:center;justify-content:center; }
	.wpcc-ai-presets { display:flex;flex-wrap:wrap;gap:10px; }
	.wpcc-ai-preset { display:inline-flex;align-items:center;gap:12px;justify-content:space-between;min-width:200px;background:#fff;border:1px solid #e3e5ec;border-radius:10px;padding:11px 14px;text-decoration:none;color:#1d2327;font-weight:600;font-size:13.5px;box-shadow:0 1px 2px rgba(16,24,40,.04);transition:border-color .12s ease,box-shadow .12s ease; }
	.wpcc-ai-preset:hover { border-color:#2271b1;box-shadow:0 1px 2px rgba(16,24,40,.04),0 6px 16px rgba(16,24,40,.06); }
	.wpcc-ai-preset__go { font-size:12px;color:#2271b1;font-weight:600;white-space:nowrap; }
	.wpcc-ai-advanced { max-width:1020px;margin:6px 0 20px;border:1px solid #e3e5ec;border-radius:12px;background:#fff;box-shadow:0 1px 2px rgba(16,24,40,.04); }
	.wpcc-ai-advanced > summary { cursor:pointer;padding:14px 20px;font-weight:600;font-size:14px;color:#1d2327;list-style:none;user-select:none; }
	.wpcc-ai-advanced > summary::-webkit-details-marker { display:none; }
	.wpcc-ai-advanced > summary::after { content:"\203A";float:right;color:#646970;font-weight:700;transition:transform .12s ease; }
	.wpcc-ai-advanced[open] > summary::after { transform:rotate(90deg); }
	.wpcc-ai-advanced__body { padding:6px 20px 20px; }
	.wpcc-ai-advanced__body .wpcc-ai-panel,.wpcc-ai-advanced__body .wpcc-ai-grid { margin-bottom:18px; }

	/* Configuration tab — safety note */
	.wpcc-ai-safe-note { display:flex;gap:14px;align-items:flex-start;max-width:1020px;background:#f4f8f4;border:1px solid #cfe6d4;border-left:4px solid #00a32a;border-radius:12px;padding:16px 20px;margin:6px 0 20px; }
	.wpcc-ai-safe-note__icon { font-size:20px;line-height:1.3;flex:0 0 auto; }
	.wpcc-ai-safe-note strong { display:block;margin-bottom:6px;color:#1d2327; }
	.wpcc-ai-safe-note ul { margin:0;padding-left:18px;color:#3c434a;font-size:13px;line-height:1.65; }
	.wpcc-ai-config { border-radius:0 0 12px 12px; }
</style>

<div class="wrap wpcc-ai-wrap">
	<h1><?php esc_html_e( 'AI Clients', 'wp-command-center' ); ?></h1>

	<section class="wpcc-ai-hero">
		<p class="wpcc-ai-lead"><?php esc_html_e( 'Connect AI assistants — Claude Desktop, Cursor, Codex, ChatGPT, Gemini CLI, and more — to this site, safely. Every action they take waits for your approval, is recorded, and can be undone.', 'wp-command-center' ); ?></p>
		<p><?php esc_html_e( 'An AI client is an assistant like Claude Desktop, Cursor, Codex, Continue, or Gemini CLI, running on your computer. Connect one here so it can help with WordPress tasks on this site — safely, with your approval, a full record, and one-click undo. New to this? Start with the short guide below.', 'wp-command-center' ); ?></p>
		<div class="wpcc-ai-chips" role="note" aria-label="<?php esc_attr_e( 'How every assistant stays safe', 'wp-command-center' ); ?>">
			<span class="lbl"><?php esc_html_e( 'Every assistant is:', 'wp-command-center' ); ?></span>
			<span class="wpcc-cds-chip wpcc-cds-chip--approval"><?php esc_html_e( 'Requires approval', 'wp-command-center' ); ?></span>
			<span class="wpcc-cds-chip wpcc-cds-chip--audited"><?php esc_html_e( 'Audited', 'wp-command-center' ); ?></span>
			<span class="wpcc-cds-chip wpcc-cds-chip--reversible"><?php esc_html_e( 'Reversible', 'wp-command-center' ); ?></span>
			<span class="wpcc-cds-chip wpcc-cds-chip--scoped"><?php esc_html_e( 'Scoped access', 'wp-command-center' ); ?></span>
			<span class="wpcc-cds-chip"><?php esc_html_e( 'Your site, your token', 'wp-command-center' ); ?></span>
		</div>
	</section>

	<details class="wpcc-agent-explainer" style="max-width:760px;margin:10px 0 20px;border:1px solid #c3c4c7;border-radius:6px;background:#f0f6fc;padding:14px 18px;" open>
		<summary style="cursor:pointer;font-weight:700;font-size:14px;"><?php esc_html_e( 'New to AI assistants? Read this first (2 min)', 'wp-command-center' ); ?></summary>
		<div style="margin-top:12px;display:grid;gap:12px;">
			<?php foreach ( \WPCommandCenter\Admin\AgentExplainer::faq() as $wpcc_qa ) : ?>
				<div>
					<strong style="display:block;font-size:13px;color:#1d2327;"><?php echo esc_html( $wpcc_qa['q'] ); ?></strong>
					<span style="display:block;color:#50575e;font-size:13px;margin-top:2px;"><?php echo esc_html( $wpcc_qa['a'] ); ?></span>
				</div>
			<?php endforeach; ?>
			<p style="margin:4px 0 0;padding:10px 12px;background:#fff;border-radius:4px;font-size:12px;color:#2271b1;font-weight:600;text-align:center;"><?php echo esc_html( \WPCommandCenter\Admin\AgentExplainer::flow_line() ); ?></p>
			<p style="margin:0;color:#646970;font-size:12px;">
				<?php
				printf(
					/* translators: 1: Providers link open, 2: link close, 3: Tokens link open, 4: link close */
					esc_html__( 'Setup order: 1) add your AI key in %1$sBuilt-in AI → Providers%2$s, 2) create an %3$saccess token%4$s, 3) paste the token into your AI assistant using the configuration below.', 'wp-command-center' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=wpcc-built-in-ai&wpcc_tab=providers' ) ) . '">',
					'</a>',
					'<a href="' . esc_url( admin_url( 'admin.php?page=wpcc-settings&wpcc_tab=access' ) ) . '">',
					'</a>'
				);
				?>
			</p>
		</div>
	</details>

	<?php if ( $wpcc_token_error ) : ?>
		<div class="notice notice-error wpcc-ai-notice"><p><?php echo esc_html( $wpcc_token_error ); ?></p></div>
	<?php endif; ?>
	<?php if ( $wpcc_token_message ) : ?>
		<div class="notice notice-success wpcc-ai-notice"><p><?php echo esc_html( $wpcc_token_message ); ?></p></div>
	<?php endif; ?>
	<?php if ( $wpcc_new_token ) : ?>
		<div class="notice notice-warning wpcc-ai-notice">
			<p><strong><?php esc_html_e( 'Your new token (save it now):', 'wp-command-center' ); ?></strong></p>
			<div class="wpcc-ai-code">
				<code class="wpcc-ai-code__text" id="wpcc-new-token"><?php echo esc_html( $wpcc_new_token ); ?></code>
				<button type="button" class="button wpcc-copy-btn" data-copy="<?php echo esc_attr( $wpcc_new_token ); ?>"><?php esc_html_e( 'Copy', 'wp-command-center' ); ?></button>
			</div>
		</div>
	<?php endif; ?>

	<!-- Tab navigation -->
	<div class="wpcc-ai-tabs">
		<?php foreach ( $wpcc_tabs as $tab_id => $tab_label ) : ?>
			<a href="<?php echo esc_url( add_query_arg( 'tab', $tab_id, admin_url( 'admin.php?page=wpcc-connect&wpcc_tab=clients' ) ) ); ?>"
			   class="wpcc-ai-tab<?php echo $tab_id === $wpcc_tab ? ' wpcc-ai-tab--active' : ''; ?>"<?php echo $tab_id === $wpcc_tab ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $tab_label ); ?></a>
		<?php endforeach; ?>
	</div>

	<?php if ( 'clients' === $wpcc_tab ) : ?>
		<!-- ===== CLIENTS TAB ===== -->

		<?php
		$wpcc_mcp_url     = rest_url( \WPCommandCenter\Mcp\McpServerRuntime::NAMESPACE . '/mcp' );
		$wpcc_token_count = is_array( $wpcc_all_tokens ) ? count( $wpcc_all_tokens ) : 0;
		$wpcc_cfg_url     = esc_url( add_query_arg( [ 'tab' => 'configuration' ], admin_url( 'admin.php?page=wpcc-connect&wpcc_tab=clients' ) ) );
		?>

		<!-- ===== Primary setup panel ===== -->
		<div class="wpcc-ai-panel wpcc-ai-setup">
			<div class="wpcc-ai-panel__header">
				<?php esc_html_e( 'Connect your assistant', 'wp-command-center' ); ?>
				<span class="wpcc-setup-status"><span class="wpcc-setup-dot"></span> <?php esc_html_e( 'Connection ready', 'wp-command-center' ); ?></span>
			</div>
			<div class="wpcc-ai-panel__body">
				<div class="wpcc-ai-field">
					<label class="wpcc-ai-field__label"><?php esc_html_e( 'Connection URL', 'wp-command-center' ); ?></label>
					<div class="wpcc-ai-url">
						<code class="wpcc-ai-url__text"><?php echo esc_html( $wpcc_mcp_url ); ?></code>
						<button type="button" class="button wpcc-copy-btn" data-copy="<?php echo esc_attr( $wpcc_mcp_url ); ?>"><?php esc_html_e( 'Copy', 'wp-command-center' ); ?></button>
						<span class="wpcc-ai-copied" id="wpcc-copy-feedback">&#10003; <?php esc_html_e( 'Copied!', 'wp-command-center' ); ?></span>
					</div>
					<p class="wpcc-ai-field__hint"><?php esc_html_e( 'Your assistant connects to this site at this address — on your own server, with your own token.', 'wp-command-center' ); ?></p>
				</div>

				<div class="wpcc-ai-field">
					<label class="wpcc-ai-field__label"><?php esc_html_e( 'Access token', 'wp-command-center' ); ?></label>
					<?php if ( $wpcc_token_count > 0 ) : ?>
						<p class="wpcc-ai-field__status wpcc-ai-field__status--ok">&#10003; <?php
							/* translators: %d: number of access tokens */
							printf( esc_html( _n( '%d access token ready.', '%d access tokens ready.', $wpcc_token_count, 'wp-command-center' ) ), (int) $wpcc_token_count );
						?> <a href="<?php echo $wpcc_cfg_url; // phpcs:ignore ?>"><?php esc_html_e( 'Manage tokens', 'wp-command-center' ); ?></a></p>
					<?php else : ?>
						<p class="wpcc-ai-field__status"><?php esc_html_e( 'No access token yet — your assistant needs one to connect.', 'wp-command-center' ); ?> <a href="<?php echo $wpcc_cfg_url; // phpcs:ignore ?>"><?php esc_html_e( 'Create an access token', 'wp-command-center' ); ?></a></p>
					<?php endif; ?>
					<p class="wpcc-ai-field__hint"><?php esc_html_e( 'A token is like a password for your assistant. It’s shown once, stays on your site, and you can revoke it anytime.', 'wp-command-center' ); ?></p>
				</div>

				<div class="wpcc-ai-setup__actions">
					<a class="button button-primary" href="<?php echo $wpcc_cfg_url; // phpcs:ignore ?>"><?php esc_html_e( 'Get my configuration', 'wp-command-center' ); ?></a>
					<a class="button" href="<?php echo $wpcc_cfg_url; // phpcs:ignore ?>"><?php esc_html_e( 'Test connection', 'wp-command-center' ); ?></a>
				</div>
			</div>
		</div>

		<!-- ===== Setup steps ===== -->
		<div class="wpcc-ai-panel">
			<div class="wpcc-ai-panel__header"><?php esc_html_e( 'How to connect — 5 steps', 'wp-command-center' ); ?></div>
			<div class="wpcc-ai-panel__body">
				<ol class="wpcc-ai-steps">
					<li><?php esc_html_e( 'Create or choose an access token.', 'wp-command-center' ); ?></li>
					<li><?php esc_html_e( 'Copy the configuration for your assistant.', 'wp-command-center' ); ?></li>
					<li><?php esc_html_e( 'Paste it into your AI assistant’s settings.', 'wp-command-center' ); ?></li>
					<li><?php esc_html_e( 'Run a safe, read-only test to confirm it connected.', 'wp-command-center' ); ?></li>
					<li><?php esc_html_e( 'From then on, any change your assistant makes waits for your approval — and you can undo it.', 'wp-command-center' ); ?></li>
				</ol>
			</div>
		</div>

		<!-- ===== Common assistants (compact presets) ===== -->
		<?php $wpcc_presets = array_slice( $wpcc_active_clients, 0, 6, true ); ?>
		<?php if ( ! empty( $wpcc_presets ) ) : ?>
		<div class="wpcc-ai-panel">
			<div class="wpcc-ai-panel__header"><?php esc_html_e( 'Popular assistants', 'wp-command-center' ); ?></div>
			<div class="wpcc-ai-panel__body">
				<div class="wpcc-ai-presets">
					<?php foreach ( $wpcc_presets as $wpcc_pid => $wpcc_pc ) : ?>
						<a class="wpcc-ai-preset" href="<?php echo esc_url( add_query_arg( [ 'tab' => 'configuration', 'client' => $wpcc_pid ], admin_url( 'admin.php?page=wpcc-connect&wpcc_tab=clients' ) ) ); ?>">
							<span class="wpcc-ai-preset__name"><?php echo esc_html( $wpcc_pc['name'] ); ?></span>
							<span class="wpcc-ai-preset__go"><?php esc_html_e( 'Set up →', 'wp-command-center' ); ?></span>
						</a>
					<?php endforeach; ?>
				</div>
				<p class="wpcc-ai-panel__hint"><?php esc_html_e( 'Any assistant that speaks the same connection protocol can connect — see the full list under Advanced below.', 'wp-command-center' ); ?></p>
			</div>
		</div>
		<?php endif; ?>

		<!-- ===== Advanced: stats + full supported clients ===== -->
		<details class="wpcc-ai-advanced">
			<summary><?php esc_html_e( 'Advanced — all supported assistants & details', 'wp-command-center' ); ?></summary>
			<div class="wpcc-ai-advanced__body">

				<div class="wpcc-ai-grid">
					<div class="wpcc-ai-stat wpcc-ai-stat--good">
						<div class="wpcc-ai-stat__value"><?php echo esc_html( $wpcc_counts['active'] ); ?></div>
						<div class="wpcc-ai-stat__label"><?php esc_html_e( 'Assistants connected', 'wp-command-center' ); ?></div>
					</div>
					<div class="wpcc-ai-stat">
						<div class="wpcc-ai-stat__value"><?php echo esc_html( $wpcc_counts['total'] ); ?></div>
						<div class="wpcc-ai-stat__label"><?php esc_html_e( 'Clients supported', 'wp-command-center' ); ?></div>
					</div>
					<div class="wpcc-ai-stat">
						<div class="wpcc-ai-stat__value"><?php echo esc_html( $wpcc_tool_count ); ?></div>
						<div class="wpcc-ai-stat__label"><?php esc_html_e( 'Actions available', 'wp-command-center' ); ?></div>
					</div>
					<div class="wpcc-ai-stat wpcc-ai-stat--good">
						<div class="wpcc-ai-stat__value"><?php esc_html_e( 'Ready', 'wp-command-center' ); ?></div>
						<div class="wpcc-ai-stat__label"><?php esc_html_e( 'Safe connection', 'wp-command-center' ); ?></div>
					</div>
				</div>

				<!-- Compatibility Matrix -->
		<div class="wpcc-ai-panel">
			<div class="wpcc-ai-panel__header"><?php esc_html_e( 'Supported Clients', 'wp-command-center' ); ?></div>
			<div class="wpcc-ai-panel__body">
				<table class="wpcc-ai-token-table">
					<thead><tr>
						<th><?php esc_html_e( 'Client', 'wp-command-center' ); ?></th>
						<th><?php esc_html_e( 'Vendor', 'wp-command-center' ); ?></th>
						<th><?php esc_html_e( 'Type', 'wp-command-center' ); ?></th>
						<th><?php esc_html_e( 'Certification', 'wp-command-center' ); ?></th>
						<th><?php esc_html_e( 'Connects', 'wp-command-center' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $wpcc_matrix as $row ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $row['name'] ); ?></strong></td>
							<td><?php echo esc_html( $row['vendor'] ); ?></td>
							<td><span class="wpcc-badge wpcc-badge--neutral"><?php echo esc_html( $row['type'] ); ?></span></td>
							<td>
								<?php $cert = $row['certification_level']; $labels = AIClientRegistry::CERT_LABELS; ?>
								<?php if ( 'gold' === $cert ) : ?>
									<span class="wpcc-badge wpcc-badge--good" title="<?php echo esc_attr( $row['last_validated_at'] ?? '' ); ?>"><?php echo esc_html( $labels[ $cert ] ?? $cert ); ?></span>
								<?php elseif ( in_array( $cert, [ 'silver', 'bronze', 'active', 'compatible' ], true ) ) : ?>
									<span class="wpcc-badge wpcc-badge--info"><?php echo esc_html( $labels[ $cert ] ?? $cert ); ?></span>
								<?php else : ?>
									<span class="wpcc-badge wpcc-badge--neutral"><?php esc_html_e( 'Planned', 'wp-command-center' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $row['mcp_support'] ) : ?>
									<span class="wpcc-badge wpcc-badge--good">&#10003;</span>
								<?php else : ?>
									<span class="wpcc-badge wpcc-badge--critical">&#10007;</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="wpcc-ai-panel__hint"><?php esc_html_e( 'Certification shows how thoroughly each assistant has been tested: Planned → Compatible → Active → Bronze → Silver → Gold. Every assistant connects the same safe way — none gets special access or skips your approval.', 'wp-command-center' ); ?></p>
			</div>
		</div>

			</div>
		</details>

	<?php elseif ( 'configuration' === $wpcc_tab ) : ?>
		<!-- ===== CONFIGURATION TAB ===== -->

		<!-- Assistant selector -->
		<div class="wpcc-ai-panel">
			<div class="wpcc-ai-panel__header"><?php esc_html_e( 'Choose your assistant', 'wp-command-center' ); ?></div>
			<div class="wpcc-ai-panel__body">
				<p class="wpcc-ai-field__hint" style="margin-top:0;"><?php esc_html_e( 'Pick the assistant you’re connecting — the configuration below updates to match.', 'wp-command-center' ); ?></p>
				<div style="display: flex; gap: 10px; flex-wrap: wrap;">
					<?php foreach ( $wpcc_active_clients as $id => $client ) : ?>
						<a href="<?php echo esc_url( add_query_arg( [ 'tab' => 'configuration', 'client' => $id ], admin_url( 'admin.php?page=wpcc-connect&wpcc_tab=clients' ) ) ); ?>"
						   class="button<?php echo $id === $wpcc_selected_client ? ' button-primary' : ''; ?>">
							<?php echo esc_html( $client['name'] ); ?>
						</a>
					<?php endforeach; ?>
				</div>
			</div>
		</div>

		<?php
		$wpcc_cfg_mcp_url   = rest_url( \WPCommandCenter\Mcp\McpServerRuntime::NAMESPACE . '/mcp' );
		$wpcc_cfg_tok_count = is_array( $wpcc_all_tokens ) ? count( $wpcc_all_tokens ) : 0;
		?>

		<!-- Setup card: your configuration -->
		<?php if ( $wpcc_config ) : ?>
			<div class="wpcc-ai-panel">
				<div class="wpcc-ai-panel__header">
					<?php printf( esc_html__( 'Your %s configuration', 'wp-command-center' ), esc_html( $wpcc_current_client['name'] ) ); ?>
					<button type="button" class="button button-primary wpcc-copy-btn" data-copy-target="wpcc-config-block">
						<?php esc_html_e( 'Copy configuration', 'wp-command-center' ); ?>
					</button>
					<span class="wpcc-ai-copied" id="wpcc-copy-feedback">&#10003; <?php esc_html_e( 'Copied!', 'wp-command-center' ); ?></span>
				</div>
				<div class="wpcc-ai-panel__body">
					<p class="wpcc-ai-field__hint" style="margin-top:0;"><?php printf( esc_html__( 'Copy this and paste it into %s to connect it to this site. It includes your connection address and access token.', 'wp-command-center' ), esc_html( $wpcc_current_client['name'] ) ); ?></p>
					<?php if ( $wpcc_cfg_tok_count > 0 ) : ?>
						<p class="wpcc-ai-field__status wpcc-ai-field__status--ok" style="margin:0 0 12px;">&#10003; <?php
							/* translators: %d: number of access tokens */
							printf( esc_html( _n( '%d access token ready.', '%d access tokens ready.', $wpcc_cfg_tok_count, 'wp-command-center' ) ), (int) $wpcc_cfg_tok_count );
						?></p>
					<?php else : ?>
						<p class="wpcc-ai-field__status" style="margin:0 0 12px;"><?php esc_html_e( 'No access token yet — create one in “Access tokens” below, then it appears in this configuration.', 'wp-command-center' ); ?></p>
					<?php endif; ?>
				</div>
				<div class="wpcc-ai-panel__body" style="padding:0;">
					<pre class="wpcc-ai-config" id="wpcc-config-block"><?php echo esc_html( $wpcc_config_json ); ?></pre>
				</div>
				<div class="wpcc-ai-panel__body" style="padding-top:14px;">
					<details class="wpcc-ai-advanced" style="margin:0;">
						<summary><?php esc_html_e( 'Connection address & where to paste', 'wp-command-center' ); ?></summary>
						<div class="wpcc-ai-advanced__body">
							<div class="wpcc-ai-field">
								<label class="wpcc-ai-field__label"><?php esc_html_e( 'Connection URL', 'wp-command-center' ); ?></label>
								<div class="wpcc-ai-url">
									<code class="wpcc-ai-url__text"><?php echo esc_html( $wpcc_cfg_mcp_url ); ?></code>
									<button type="button" class="button wpcc-copy-btn" data-copy="<?php echo esc_attr( $wpcc_cfg_mcp_url ); ?>"><?php esc_html_e( 'Copy', 'wp-command-center' ); ?></button>
								</div>
							</div>
							<?php if ( ! empty( $wpcc_current_client['config_paths'] ) ) : ?>
								<div class="wpcc-ai-field">
									<label class="wpcc-ai-field__label"><?php esc_html_e( 'Where to paste this', 'wp-command-center' ); ?></label>
									<table class="widefat" style="border:none;">
										<?php foreach ( $wpcc_current_client['config_paths'] as $os => $path ) : ?>
											<tr><td style="padding:6px 0;width:80px;"><strong><?php echo esc_html( ucfirst( $os ) ); ?></strong></td><td style="padding:6px 0;"><code><?php echo esc_html( $path ); ?></code></td></tr>
										<?php endforeach; ?>
									</table>
								</div>
							<?php endif; ?>
						</div>
					</details>
				</div>
			</div>
		<?php else : ?>
			<div class="wpcc-ai-panel">
				<div class="wpcc-ai-panel__header"><?php esc_html_e( 'Your configuration', 'wp-command-center' ); ?></div>
				<div class="wpcc-ai-panel__body">
					<p style="color:#646970;"><?php esc_html_e( 'A ready-made configuration isn’t available for this assistant yet. You can still connect it manually using the connection address and an access token below.', 'wp-command-center' ); ?></p>
					<div class="wpcc-ai-field" style="margin-top:14px;">
						<label class="wpcc-ai-field__label"><?php esc_html_e( 'Connection URL', 'wp-command-center' ); ?></label>
						<div class="wpcc-ai-url">
							<code class="wpcc-ai-url__text"><?php echo esc_html( $wpcc_cfg_mcp_url ); ?></code>
							<button type="button" class="button wpcc-copy-btn" data-copy="<?php echo esc_attr( $wpcc_cfg_mcp_url ); ?>"><?php esc_html_e( 'Copy', 'wp-command-center' ); ?></button>
							<span class="wpcc-ai-copied" id="wpcc-copy-feedback">&#10003; <?php esc_html_e( 'Copied!', 'wp-command-center' ); ?></span>
						</div>
					</div>
				</div>
			</div>
		<?php endif; ?>

		<!-- Access tokens -->
		<div class="wpcc-ai-panel">
			<div class="wpcc-ai-panel__header"><?php esc_html_e( 'Access tokens', 'wp-command-center' ); ?></div>
			<div class="wpcc-ai-panel__body">
				<p class="wpcc-ai-field__hint" style="margin-top:0;"><?php esc_html_e( 'A token is your assistant’s key to this site. Start with read-only so it can look around safely; with full access, every change still waits for your approval.', 'wp-command-center' ); ?></p>
				<form method="post">
					<?php wp_nonce_field( 'wpcc_ai_integrations' ); ?>
					<div style="display: flex; gap: 8px; flex-wrap: wrap;">
						<button type="submit" name="wpcc_token_action" value="generate_read_only" class="button button-primary">
							<?php esc_html_e( 'Create read-only token', 'wp-command-center' ); ?>
						</button>
						<button type="submit" name="wpcc_token_action" value="generate_full" class="button">
							<?php esc_html_e( 'Create full-access token', 'wp-command-center' ); ?>
						</button>
					</div>
				</form>
				<?php if ( ! empty( $wpcc_all_tokens ) ) : ?>
					<h4 style="margin: 18px 0 10px;"><?php esc_html_e( 'Your tokens', 'wp-command-center' ); ?></h4>
					<table class="wpcc-ai-token-table">
						<thead><tr><th><?php esc_html_e( 'Label', 'wp-command-center' ); ?></th><th><?php esc_html_e( 'Scope', 'wp-command-center' ); ?></th><th><?php esc_html_e( 'Status', 'wp-command-center' ); ?></th><th></th></tr></thead>
						<tbody>
						<?php foreach ( $wpcc_all_tokens as $t ) : ?>
							<tr>
								<td><?php echo esc_html( $t['label'] ); ?><br><small style="color:#646970"><?php echo esc_html( $t['token_preview'] ); ?>...</small></td>
								<td><?php echo esc_html( AuthTokens::scope_label( $t['scope'] ) ); ?></td>
								<td><?php echo AuthTokens::status_badge( $t ); // phpcs:ignore ?></td>
								<td><button type="button" class="button button-small wpcc-select-token-btn" data-token-id="<?php echo esc_attr( $t['id'] ); ?>"><?php esc_html_e( 'Use in config', 'wp-command-center' ); ?></button></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<p class="wpcc-ai-panel__hint"><?php esc_html_e( 'A token is shown in full only once, when you create it. Manage or revoke tokens anytime in Settings → Access.', 'wp-command-center' ); ?></p>
				<?php else : ?>
					<p style="color:#646970;margin-top:12px;"><?php esc_html_e( 'No access tokens yet. Create one above to finish your configuration.', 'wp-command-center' ); ?></p>
				<?php endif; ?>
			</div>
		</div>

		<!-- Test the connection safely -->
		<div class="wpcc-ai-panel">
			<div class="wpcc-ai-panel__header"><?php esc_html_e( 'Test the connection safely', 'wp-command-center' ); ?></div>
			<div class="wpcc-ai-panel__body">
				<p><?php esc_html_e( 'Run a quick read-only test to confirm your assistant can connect. This only reads — it never changes anything on your site.', 'wp-command-center' ); ?></p>
				<div style="margin-bottom: 12px;">
					<label for="wpcc-test-token" style="display: block; font-weight: 600; margin-bottom: 4px;"><?php esc_html_e( 'Access token', 'wp-command-center' ); ?></label>
					<input type="text" id="wpcc-test-token" class="regular-text" placeholder="wpcc_..." style="width: 100%; max-width: 500px; font-family: monospace;"
						value="<?php echo esc_attr( $wpcc_new_token ); ?>">
					<p style="color: #646970; font-size: 12px; margin: 4px 0 0;"><?php esc_html_e( 'Paste an access token, or create one in “Access tokens” above.', 'wp-command-center' ); ?></p>
				</div>
				<button type="button" class="button button-primary" id="wpcc-test-connection"><?php esc_html_e( 'Run read-only test', 'wp-command-center' ); ?></button>
				<div class="wpcc-ai-verify-result" id="wpcc-verify-result"></div>
			</div>
		</div>

		<!-- Safety note -->
		<div class="wpcc-ai-safe-note" role="note">
			<span class="wpcc-ai-safe-note__icon" aria-hidden="true">&#128274;</span>
			<div>
				<strong><?php esc_html_e( 'Connecting an assistant is safe by design.', 'wp-command-center' ); ?></strong>
				<ul>
					<li><?php esc_html_e( 'Any change your assistant makes waits for your approval first.', 'wp-command-center' ); ?></li>
					<li><?php esc_html_e( 'Every action is recorded in History.', 'wp-command-center' ); ?></li>
					<li><?php esc_html_e( 'Reversible changes can be undone with one click.', 'wp-command-center' ); ?></li>
				</ul>
			</div>
		</div>

	<?php elseif ( 'activity' === $wpcc_tab ) : ?>
		<!-- ===== ACTIVITY TAB ===== -->

		<div class="wpcc-ai-panel">
			<div class="wpcc-ai-panel__header"><?php esc_html_e( 'Last AI Client Activity', 'wp-command-center' ); ?></div>
			<div class="wpcc-ai-panel__body">
				<?php if ( empty( $wpcc_ai_activity ) ) : ?>
					<p style="color:#646970;"><?php esc_html_e( 'No AI client activity recorded yet. Call the config or discovery endpoints to generate activity.', 'wp-command-center' ); ?></p>
				<?php else : ?>
					<table class="wpcc-ai-token-table">
						<thead><tr><th><?php esc_html_e( 'Time', 'wp-command-center' ); ?></th><th><?php esc_html_e( 'Event', 'wp-command-center' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $wpcc_ai_activity as $entry ) : ?>
							<tr>
								<td style="white-space:nowrap;"><?php echo esc_html( gmdate( 'Y-m-d H:i:s', $entry['timestamp'] ) ); ?></td>
								<td><?php echo esc_html( $entry['action'] ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>

	<?php elseif ( 'security' === $wpcc_tab ) : ?>
		<!-- ===== SECURITY TAB ===== -->

		<div class="wpcc-ai-panel">
			<div class="wpcc-ai-panel__header"><?php esc_html_e( 'AI Client Security Model', 'wp-command-center' ); ?></div>
			<div class="wpcc-ai-panel__body">
				<p><?php esc_html_e( 'All AI clients connect through the same MCP endpoint and share an identical security model. No client receives elevated privileges or bypasses any platform control.', 'wp-command-center' ); ?></p>
				<ul class="wpcc-ai-security-list">
					<li>
						<strong><?php esc_html_e( 'Capabilities', 'wp-command-center' ); ?></strong>
						<span><?php esc_html_e( 'Every tool requires a specific capability assigned to the API token. No client can bypass capability enforcement.', 'wp-command-center' ); ?></span>
					</li>
					<li>
						<strong><?php esc_html_e( 'Approvals', 'wp-command-center' ); ?></strong>
						<span><?php esc_html_e( 'Operations requiring human approval must go through the request-approve-execute workflow. No client can auto-approve.', 'wp-command-center' ); ?></span>
					</li>
					<li>
						<strong><?php esc_html_e( 'Queue', 'wp-command-center' ); ?></strong>
						<span><?php esc_html_e( 'All operations follow the same queuing and execution flow. No client can bypass the queue or execute directly.', 'wp-command-center' ); ?></span>
					</li>
					<li>
						<strong><?php esc_html_e( 'Audit', 'wp-command-center' ); ?></strong>
						<span><?php esc_html_e( 'Every action is logged with the client source, actor context, and timestamp. Full traceability for all clients.', 'wp-command-center' ); ?></span>
					</li>
					<li>
						<strong><?php esc_html_e( 'Rollback', 'wp-command-center' ); ?></strong>
						<span><?php esc_html_e( 'Every modification is snapshotted before execution. All clients inherit the same rollback protection.', 'wp-command-center' ); ?></span>
					</li>
				</ul>
			</div>
		</div>

		<div class="wpcc-ai-panel">
			<div class="wpcc-ai-panel__header"><?php esc_html_e( 'Architecture', 'wp-command-center' ); ?></div>
			<div class="wpcc-ai-panel__body">
				<p><?php esc_html_e( 'All AI clients follow the same execution path through the platform:', 'wp-command-center' ); ?></p>
				<pre style="background:#f6f7f7;padding:14px;border-radius:4px;font-size:13px;line-height:1.8;overflow-x:auto;">AI Client &rarr; MCP &rarr; WP Command Center &rarr; Capability Runtime &rarr; Approval Runtime &rarr; Queue Runtime &rarr; OperationExecutor &rarr; Verification &rarr; Audit &rarr; Rollback</pre>
				<p style="color:#646970;font-size:12px;"><?php esc_html_e( 'There are no per-client runtimes, no special execution paths, and no vendor-specific privileges.', 'wp-command-center' ); ?></p>
			</div>
		</div>

	<?php endif; ?>

</div>

<script>
(function() {
	document.querySelectorAll('.wpcc-copy-btn').forEach(function(btn) {
		btn.addEventListener('click', function() {
			var targetId = this.getAttribute('data-copy-target');
			var text;
			if (targetId) {
				text = document.getElementById(targetId).textContent;
			} else {
				text = this.getAttribute('data-copy');
			}
			if (!text) return;
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(text).then(function() {
					var fb = document.getElementById('wpcc-copy-feedback');
					if (fb) { fb.classList.add('wpcc-ai-copied--visible'); setTimeout(function() { fb.classList.remove('wpcc-ai-copied--visible'); }, 2000); }
				});
			} else {
				var ta = document.createElement('textarea');
				ta.value = text; ta.style.position = 'fixed'; ta.style.left = '-9999px';
				document.body.appendChild(ta); ta.select();
				try { document.execCommand('copy'); } catch(e) {}
				document.body.removeChild(ta);
				var fb = document.getElementById('wpcc-copy-feedback');
				if (fb) { fb.classList.add('wpcc-ai-copied--visible'); setTimeout(function() { fb.classList.remove('wpcc-ai-copied--visible'); }, 2000); }
			}
		});
	});

	document.querySelectorAll('.wpcc-select-token-btn').forEach(function(btn) {
		btn.addEventListener('click', function() {
			var tid = this.getAttribute('data-token-id');
			var url = new URL(window.location.href);
			url.searchParams.set('token_id', tid);
			window.location.href = url.toString();
		});
	});

	var testBtn = document.getElementById('wpcc-test-connection');
	if (testBtn) {
		testBtn.addEventListener('click', function() {
			var resultEl = document.getElementById('wpcc-verify-result');
			var token = document.getElementById('wpcc-test-token').value.trim();

			if (!token) {
				resultEl.className = 'wpcc-ai-verify-result wpcc-ai-verify-result--fail';
				resultEl.innerHTML = '<p><strong>&#10007; No token:</strong> <?php esc_html_e( 'Paste an API token above or generate one in the Configuration tab.', 'wp-command-center' ); ?></p>';
				return;
			}

			resultEl.className = 'wpcc-ai-verify-result wpcc-ai-verify-result--loading';
			resultEl.innerHTML = '<p><span class="spinner is-active" style="float:none;margin:0 10px 0 0;"></span><?php esc_html_e( 'Testing connection...', 'wp-command-center' ); ?></p>';
			var authHeader = { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json' };
			var authHeaderGet = { 'Authorization': 'Bearer ' + token };
			var baseUrl = <?php echo wp_json_encode( rest_url( \WPCommandCenter\Mcp\McpServerRuntime::NAMESPACE ) ); ?>;
			var checks = [];

			function record(name, pass, detail) {
				checks.push({ name: name, pass: pass, detail: detail || '' });
			}

			fetch(baseUrl + '/health', { headers: authHeaderGet })
				.then(function(r) { return r.json().then(function(d) { return { ok: r.ok, status: r.status, data: d }; }); })
				.then(function(r) {
					var pass = r.ok && r.data.status === 'ok';
					var detail = pass ? '' : ('HTTP ' + r.status + ': ' + (r.data.message || r.data.code || JSON.stringify(r.data).substring(0, 200)));
					record('Health endpoint', pass, detail);
					return fetch(baseUrl + '/agent/manifest', { headers: authHeaderGet }).then(function(r) { return r.json().then(function(d) { return { ok: r.ok, status: r.status, data: d }; }); });
				})
				.then(function(r) {
					var pass = r.ok && r.data.plugin;
					var detail = pass ? '' : ('HTTP ' + r.status + ': ' + (r.data.message || r.data.code || JSON.stringify(r.data).substring(0, 200)));
					record('Agent manifest', pass, detail);
					return fetch(baseUrl + '/mcp', { method: 'POST', headers: authHeader, body: JSON.stringify({ jsonrpc: '2.0', method: 'initialize', params: { protocolVersion: '2024-11-05' }, id: 1 }) }).then(function(r) { return r.json().then(function(d) { return { ok: r.ok, status: r.status, data: d }; }); });
				})
				.then(function(r) {
					var pass = r.ok && r.data.result && r.data.result.serverInfo;
					var detail = pass ? '' : ('HTTP ' + r.status + ': ' + ((r.data.error && r.data.error.message) || (r.data.message) || JSON.stringify(r.data).substring(0, 200)));
					record('MCP initialize', pass, detail);
					return fetch(baseUrl + '/mcp', { method: 'POST', headers: authHeader, body: JSON.stringify({ jsonrpc: '2.0', method: 'resources/list', id: 2 }) }).then(function(r) { return r.json().then(function(d) { return { ok: r.ok, status: r.status, data: d }; }); });
				})
				.then(function(r) {
					var res = r.data.result;
					var pass = r.ok && res && res.resources && res.resources.length >= 7;
					var detail = pass ? '' : ('HTTP ' + r.status + ': ' + ((r.data.error && r.data.error.message) || 'got ' + (res && res.resources ? res.resources.length : 0) + ' resources'));
					record('MCP resources', pass, detail);
					return fetch(baseUrl + '/mcp', { method: 'POST', headers: authHeader, body: JSON.stringify({ jsonrpc: '2.0', method: 'tools/list', id: 3 }) }).then(function(r) { return r.json().then(function(d) { return { ok: r.ok, status: r.status, data: d }; }); });
				})
				.then(function(r) {
					var res = r.data.result;
					var pass = r.ok && res && res.tools && res.tools.length > 0;
					var detail = pass ? '' : ('HTTP ' + r.status + ': ' + ((r.data.error && r.data.error.message) || 'got ' + (res && res.tools ? res.tools.length : 0) + ' tools'));
					record('MCP tools', pass, detail);

					var allPass = checks.every(function(c) { return c.pass; });
					resultEl.className = 'wpcc-ai-verify-result wpcc-ai-verify-result--' + (allPass ? 'success' : 'fail');
					var html = allPass ? '<h3 style="margin:0 0 10px;color:#00a32a;">&#10003; <?php esc_html_e( 'All checks passed!', 'wp-command-center' ); ?></h3>' : '<h3 style="margin:0 0 10px;color:#d63638;">&#10007; <?php esc_html_e( 'Some checks failed.', 'wp-command-center' ); ?></h3>';
					html += '<table style="border-collapse:collapse;width:100%;">';
					checks.forEach(function(c) {
						html += '<tr><td style="padding:4px 8px;">' + (c.pass ? '&#10003;' : '&#10007;') + '</td><td style="padding:4px 8px;font-weight:600;">' + c.name + '</td>';
						if (!c.pass) { html += '<td style="padding:4px 8px;color:#d63638;font-size:12px;">' + c.detail + '</td>'; }
						html += '</tr>';
					});
					html += '</table>';
					resultEl.innerHTML = html;
				})
				.catch(function(err) {
					resultEl.className = 'wpcc-ai-verify-result wpcc-ai-verify-result--fail';
					resultEl.innerHTML = '<p><strong>&#10007; <?php esc_html_e( 'Connection test failed:', 'wp-command-center' ); ?></strong> ' + err.message + '</p><p style="color:#646970;font-size:12px;"><?php esc_html_e( 'Check that your site is reachable and the token is valid.', 'wp-command-center' ); ?></p>';
				});
		});
	}
})();
</script>
