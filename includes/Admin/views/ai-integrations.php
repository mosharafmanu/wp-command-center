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
		$selected_record = reset( $selected );
		$wpcc_config['mcpServers']['wp-command-center']['env']['WPCC_TOKEN'] = 'wpcc_YOUR_TOKEN_HERE';
		$wpcc_config['mcpServers']['wp-command-center']['env']['WPCC_TOKEN_ID'] = $selected_record['id'];
		$wpcc_config['mcpServers']['wp-command-center']['env']['WPCC_TOKEN_LABEL'] = $selected_record['label'];
		$wpcc_config['mcpServers']['wp-command-center']['env']['WPCC_TOKEN_SCOPE'] = $selected_record['scope'];
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
	.wpcc-ai-wrap { max-width: 960px; }
	.wpcc-ai-tabs { display: flex; gap: 4px; border-bottom: 2px solid #c3c4c7; margin-bottom: 24px; }
	.wpcc-ai-tab { padding: 10px 18px; border-radius: 4px 4px 0 0; border: 1px solid transparent; cursor: pointer; font-size: 13px; font-weight: 600; background: #f0f0f1; color: #50575e; text-decoration: none; }
	.wpcc-ai-tab--active { background: #fff; border-color: #c3c4c7 #c3c4c7 #fff; color: #1d2327; }
	.wpcc-ai-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 14px; margin-bottom: 24px; }
	.wpcc-ai-stat { background: #fff; border: 1px solid #c3c4c7; padding: 16px; text-align: center; border-radius: 4px; }
	.wpcc-ai-stat__value { font-size: 26px; font-weight: 700; color: #2271b1; line-height: 1.2; }
	.wpcc-ai-stat__label { font-size: 12px; color: #646970; margin-top: 4px; text-transform: uppercase; letter-spacing: .5px; }
	.wpcc-ai-stat--good .wpcc-ai-stat__value { color: #00a32a; }
	.wpcc-ai-panel { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; margin-bottom: 20px; }
	.wpcc-ai-panel__header { padding: 14px 20px; border-bottom: 1px solid #e5e5e5; font-size: 15px; font-weight: 600; display: flex; justify-content: space-between; align-items: center; }
	.wpcc-ai-panel__body { padding: 20px; }
	.wpcc-ai-config { background: #1d2327; color: #c3c4c7; padding: 16px; border-radius: 4px; font-family: monospace; font-size: 13px; line-height: 1.6; overflow-x: auto; white-space: pre-wrap; word-break: break-all; max-height: 400px; overflow-y: auto; position: relative; }
	.wpcc-ai-code { background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px; padding: 8px 12px; font-family: monospace; font-size: 13px; word-break: break-all; margin: 8px 0; display: flex; justify-content: space-between; align-items: center; }
	.wpcc-ai-code__text { flex: 1; margin-right: 10px; }
	.wpcc-ai-token-table { width: 100%; border-collapse: collapse; }
	.wpcc-ai-token-table th { text-align: left; padding: 8px 10px; border-bottom: 2px solid #c3c4c7; font-weight: 600; }
	.wpcc-ai-token-table td { padding: 8px 10px; border-bottom: 1px solid #e5e5e5; }
	.wpcc-ai-verify-result { margin-top: 12px; padding: 12px; border-radius: 4px; display: none; }
	.wpcc-ai-verify-result--success { background: #edfaef; border: 1px solid #00a32a; display: block; }
	.wpcc-ai-verify-result--fail { background: #fcf0f1; border: 1px solid #d63638; display: block; }
	.wpcc-ai-verify-result--loading { background: #f0f6fc; border: 1px solid #2271b1; display: block; }
	.wpcc-ai-security-list { list-style: none; padding: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; }
	.wpcc-ai-security-list li { padding: 10px 14px; background: #f6f7f7; border-left: 3px solid #00a32a; border-radius: 0 4px 4px 0; }
	.wpcc-ai-security-list li strong { display: block; margin-bottom: 3px; }
	.wpcc-ai-security-list li span { font-size: 12px; color: #646970; }
	.wpcc-badge { display: inline-block; padding: 2px 10px; border-radius: 3px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; }
	.wpcc-badge--good { background: #edfaef; color: #00a32a; }
	.wpcc-badge--neutral { background: #f0f0f1; color: #50575e; }
	.wpcc-badge--critical { background: #fcf0f1; color: #d63638; }
	.wpcc-badge--info { background: #f0f6fc; color: #2271b1; }
	.wpcc-ai-notice { margin: 0 0 16px 0; }
	.wpcc-ai-copied { color: #00a32a; font-size: 12px; display: inline-block; margin-left: 8px; opacity: 0; transition: opacity .2s; }
	.wpcc-ai-copied--visible { opacity: 1; }
	.wpcc-ai-client-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px; }
	.wpcc-ai-client-card { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 16px; }
	.wpcc-ai-client-card--active { border-left: 4px solid #00a32a; }
	.wpcc-ai-client-card--planned { border-left: 4px solid #c3c4c7; opacity: 0.7; }
	.wpcc-ai-client-card h3 { margin: 0 0 4px; font-size: 15px; }
	.wpcc-ai-client-card .vendor { font-size: 12px; color: #646970; }
	.wpcc-ai-client-card .type { font-size: 11px; color: #646970; margin-top: 4px; }
	.wpcc-ai-client-card .desc { font-size: 12px; color: #3c434a; margin-top: 8px; }
</style>

<div class="wrap wpcc-ai-wrap">
	<h1><?php esc_html_e( 'AI Integrations', 'wp-command-center' ); ?></h1>
	<p><?php esc_html_e( 'Connect external AI tools to your WordPress site via the MCP protocol. All clients share the same platform, security model, and execution pipeline.', 'wp-command-center' ); ?></p>

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
			<a href="<?php echo esc_url( add_query_arg( 'tab', $tab_id, admin_url( 'admin.php?page=wpcc-ai-integrations' ) ) ); ?>"
			   class="wpcc-ai-tab<?php echo $tab_id === $wpcc_tab ? ' wpcc-ai-tab--active' : ''; ?>"><?php echo esc_html( $tab_label ); ?></a>
		<?php endforeach; ?>
	</div>

	<?php if ( 'clients' === $wpcc_tab ) : ?>
		<!-- ===== CLIENTS TAB ===== -->

		<div class="wpcc-ai-grid">
			<div class="wpcc-ai-stat wpcc-ai-stat--good">
				<div class="wpcc-ai-stat__value"><?php echo esc_html( $wpcc_counts['active'] ); ?></div>
				<div class="wpcc-ai-stat__label"><?php esc_html_e( 'Active Clients', 'wp-command-center' ); ?></div>
			</div>
			<div class="wpcc-ai-stat">
				<div class="wpcc-ai-stat__value"><?php echo esc_html( $wpcc_counts['total'] ); ?></div>
				<div class="wpcc-ai-stat__label"><?php esc_html_e( 'Total Supported', 'wp-command-center' ); ?></div>
			</div>
			<div class="wpcc-ai-stat">
				<div class="wpcc-ai-stat__value"><?php echo esc_html( $wpcc_tool_count ); ?></div>
				<div class="wpcc-ai-stat__label"><?php esc_html_e( 'MCP Tools', 'wp-command-center' ); ?></div>
			</div>
			<div class="wpcc-ai-stat">
				<div class="wpcc-ai-stat__value">7</div>
				<div class="wpcc-ai-stat__label"><?php esc_html_e( 'MCP Resources', 'wp-command-center' ); ?></div>
			</div>
			<div class="wpcc-ai-stat wpcc-ai-stat--good">
				<div class="wpcc-ai-stat__value"><?php esc_html_e( 'Active', 'wp-command-center' ); ?></div>
				<div class="wpcc-ai-stat__label"><?php esc_html_e( 'MCP Server', 'wp-command-center' ); ?></div>
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
						<th><?php esc_html_e( 'MCP', 'wp-command-center' ); ?></th>
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
				<p style="margin-top:12px;color:#646970;font-size:12px;"><?php esc_html_e( 'Certification levels: Planned → Compatible → Active → Bronze → Silver → Gold. All clients connect through the same MCP endpoint.', 'wp-command-center' ); ?></p>
			</div>
		</div>

		<!-- Active Client Cards -->
		<h2><?php esc_html_e( 'Active Clients', 'wp-command-center' ); ?></h2>
		<div class="wpcc-ai-client-grid">
			<?php foreach ( $wpcc_active_clients as $id => $client ) : ?>
				<div class="wpcc-ai-client-card wpcc-ai-client-card--active">
					<h3><?php echo esc_html( $client['name'] ); ?></h3>
					<div class="vendor"><?php echo esc_html( $client['vendor'] ); ?></div>
					<div class="type"><span class="wpcc-badge wpcc-badge--neutral"><?php echo esc_html( $client['type'] ); ?></span></div>
					<div class="desc"><?php echo esc_html( $client['description'] ); ?></div>
					<div style="margin-top:10px;">
						<a href="<?php echo esc_url( add_query_arg( [ 'tab' => 'configuration', 'client' => $id ], admin_url( 'admin.php?page=wpcc-ai-integrations' ) ) ); ?>" class="button button-small">
							<?php esc_html_e( 'Configure', 'wp-command-center' ); ?>
						</a>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

	<?php elseif ( 'configuration' === $wpcc_tab ) : ?>
		<!-- ===== CONFIGURATION TAB ===== -->

		<!-- Client selector -->
		<div class="wpcc-ai-panel">
			<div class="wpcc-ai-panel__header"><?php esc_html_e( 'Client', 'wp-command-center' ); ?></div>
			<div class="wpcc-ai-panel__body">
				<div style="display: flex; gap: 10px; flex-wrap: wrap;">
					<?php foreach ( $wpcc_active_clients as $id => $client ) : ?>
						<a href="<?php echo esc_url( add_query_arg( [ 'tab' => 'configuration', 'client' => $id ], admin_url( 'admin.php?page=wpcc-ai-integrations' ) ) ); ?>"
						   class="button<?php echo $id === $wpcc_selected_client ? ' button-primary' : ''; ?>">
							<?php echo esc_html( $client['name'] ); ?>
						</a>
					<?php endforeach; ?>
				</div>
			</div>
		</div>

		<!-- Token + Config two-column -->
		<div style="display: grid; grid-template-columns: 1fr 2fr; gap: 20px;">
			<div>
				<div class="wpcc-ai-panel">
					<div class="wpcc-ai-panel__header"><?php esc_html_e( 'API Tokens', 'wp-command-center' ); ?></div>
					<div class="wpcc-ai-panel__body">
						<form method="post">
							<?php wp_nonce_field( 'wpcc_ai_integrations' ); ?>
							<div style="display: flex; gap: 8px; flex-wrap: wrap;">
								<button type="submit" name="wpcc_token_action" value="generate_read_only" class="button button-primary">
									<?php esc_html_e( 'Generate Read-Only Token', 'wp-command-center' ); ?>
								</button>
								<button type="submit" name="wpcc_token_action" value="generate_full" class="button">
									<?php esc_html_e( 'Generate Full Access Token', 'wp-command-center' ); ?>
								</button>
							</div>
						</form>
						<?php if ( ! empty( $wpcc_all_tokens ) ) : ?>
							<h4 style="margin: 16px 0 10px;"><?php esc_html_e( 'Existing Tokens', 'wp-command-center' ); ?></h4>
							<table class="wpcc-ai-token-table">
								<thead><tr><th><?php esc_html_e( 'Label', 'wp-command-center' ); ?></th><th><?php esc_html_e( 'Scope', 'wp-command-center' ); ?></th><th><?php esc_html_e( 'Status', 'wp-command-center' ); ?></th><th></th></tr></thead>
								<tbody>
								<?php foreach ( $wpcc_all_tokens as $t ) : ?>
									<tr>
										<td><?php echo esc_html( $t['label'] ); ?><br><small style="color:#646970"><?php echo esc_html( $t['token_preview'] ); ?>...</small></td>
										<td><?php echo esc_html( AuthTokens::scope_label( $t['scope'] ) ); ?></td>
										<td><?php echo AuthTokens::status_badge( $t ); // phpcs:ignore ?></td>
										<td><button type="button" class="button button-small wpcc-select-token-btn" data-token-id="<?php echo esc_attr( $t['id'] ); ?>"><?php esc_html_e( 'Use', 'wp-command-center' ); ?></button></td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						<?php else : ?>
							<p style="color:#646970;margin-top:12px;"><?php esc_html_e( 'No tokens yet.', 'wp-command-center' ); ?></p>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<div>
				<?php if ( $wpcc_config ) : ?>
					<div class="wpcc-ai-panel">
						<div class="wpcc-ai-panel__header">
							<?php printf( esc_html__( '%s MCP Configuration', 'wp-command-center' ), esc_html( $wpcc_current_client['name'] ) ); ?>
							<button type="button" class="button button-primary wpcc-copy-btn" data-copy-target="wpcc-config-block">
								<?php esc_html_e( 'Copy Config', 'wp-command-center' ); ?>
							</button>
							<span class="wpcc-ai-copied" id="wpcc-copy-feedback">&#10003; <?php esc_html_e( 'Copied!', 'wp-command-center' ); ?></span>
						</div>
						<div class="wpcc-ai-panel__body" style="padding:0;">
							<pre class="wpcc-ai-config" id="wpcc-config-block"><?php echo esc_html( $wpcc_config_json ); ?></pre>
						</div>
					</div>

					<?php if ( ! empty( $wpcc_current_client['config_paths'] ) ) : ?>
						<div class="wpcc-ai-panel">
							<div class="wpcc-ai-panel__header"><?php esc_html_e( 'Where to paste this config', 'wp-command-center' ); ?></div>
							<div class="wpcc-ai-panel__body">
								<table class="widefat" style="border:none;">
									<?php foreach ( $wpcc_current_client['config_paths'] as $os => $path ) : ?>
										<tr><td style="padding: 6px 0;width:80px;"><strong><?php echo esc_html( ucfirst( $os ) ); ?></strong></td><td style="padding: 6px 0;"><code><?php echo esc_html( $path ); ?></code></td></tr>
									<?php endforeach; ?>
								</table>
							</div>
						</div>
					<?php endif; ?>
				<?php else : ?>
					<div class="wpcc-ai-panel">
						<div class="wpcc-ai-panel__header"><?php esc_html_e( 'Configuration', 'wp-command-center' ); ?></div>
						<div class="wpcc-ai-panel__body">
							<p style="color:#646970;"><?php esc_html_e( 'Configuration generator not yet implemented for this client.', 'wp-command-center' ); ?></p>
						</div>
					</div>
				<?php endif; ?>
			</div>
		</div>

		<!-- Connection Test -->
		<div class="wpcc-ai-panel">
			<div class="wpcc-ai-panel__header"><?php esc_html_e( 'Connection Test', 'wp-command-center' ); ?></div>
			<div class="wpcc-ai-panel__body">
				<p><?php esc_html_e( 'Verify the MCP endpoint is reachable and returning valid responses.', 'wp-command-center' ); ?></p>
				<button type="button" class="button button-primary" id="wpcc-test-connection"><?php esc_html_e( 'Test Connection', 'wp-command-center' ); ?></button>
				<div class="wpcc-ai-verify-result" id="wpcc-verify-result"></div>
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
			resultEl.className = 'wpcc-ai-verify-result wpcc-ai-verify-result--loading';
			resultEl.innerHTML = '<p><span class="spinner is-active" style="float:none;margin:0 10px 0 0;"></span><?php esc_html_e( 'Testing connection...', 'wp-command-center' ); ?></p>';
			var baseUrl = <?php echo wp_json_encode( rest_url( \WPCommandCenter\Mcp\McpServerRuntime::NAMESPACE ) ); ?>;
			var checks = [];
			fetch(baseUrl + '/health')
				.then(function(r) { return r.json().then(function(d) { return { ok: r.ok, data: d }; }); })
				.then(function(r) {
					checks.push({ name: 'Health endpoint', pass: r.ok && r.data.status === 'ok' });
					return fetch(baseUrl + '/agent/manifest').then(function(r) { return r.json().then(function(d) { return { ok: r.ok, data: d }; }); });
				})
				.then(function(r) {
					checks.push({ name: 'Agent manifest', pass: r.ok && r.data.plugin });
					return fetch(baseUrl + '/claude/discovery').then(function(r) { return r.json().then(function(d) { return { ok: r.ok, data: d }; }); });
				})
				.then(function(r) {
					checks.push({ name: 'Discovery metadata', pass: r.ok && r.data.server && r.data.tools });
					return fetch(baseUrl + '/mcp', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ jsonrpc: '2.0', method: 'initialize', params: { protocolVersion: '2024-11-05' }, id: 1 }) }).then(function(r) { return r.json().then(function(d) { return { ok: r.ok, data: d }; }); });
				})
				.then(function(r) {
					checks.push({ name: 'MCP initialize', pass: r.ok && r.data.result && r.data.result.serverInfo });
					return fetch(baseUrl + '/mcp', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ jsonrpc: '2.0', method: 'resources/list', id: 2 }) }).then(function(r) { return r.json().then(function(d) { return { ok: r.ok, data: d }; }); });
				})
				.then(function(r) {
					checks.push({ name: 'MCP resources', pass: r.ok && r.data.result && r.data.result.resources && r.data.result.resources.length >= 7 });
					return fetch(baseUrl + '/mcp', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ jsonrpc: '2.0', method: 'tools/list', id: 3 }) }).then(function(r) { return r.json().then(function(d) { return { ok: r.ok, data: d }; }); });
				})
				.then(function(r) {
					checks.push({ name: 'MCP tools', pass: r.ok && r.data.result && r.data.result.tools && r.data.result.tools.length > 0 });
					var allPass = checks.every(function(c) { return c.pass; });
					resultEl.className = 'wpcc-ai-verify-result wpcc-ai-verify-result--' + (allPass ? 'success' : 'fail');
					var html = allPass ? '<h3 style="margin:0 0 10px;color:#00a32a;">&#10003; All checks passed!</h3>' : '<h3 style="margin:0 0 10px;color:#d63638;">&#10007; Some checks failed.</h3>';
					html += '<ul style="list-style:none;padding:0;margin:0;">';
					checks.forEach(function(c) { html += '<li>' + (c.pass ? '&#10003;' : '&#10007;') + ' ' + c.name + '</li>'; });
					html += '</ul>';
					resultEl.innerHTML = html;
				})
				.catch(function(err) {
					resultEl.className = 'wpcc-ai-verify-result wpcc-ai-verify-result--fail';
					resultEl.innerHTML = '<p><strong>&#10007; Connection test failed:</strong> ' + err.message + '</p>';
				});
		});
	}
})();
</script>
