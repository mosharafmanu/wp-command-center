<?php
/**
 * PROGRAM-6R — AI Platform view (connection-centric).
 *
 * Manage unlimited AI Connections (each = provider + dialect + endpoint + key +
 * model + tags). Honest status: a connection is CONFIGURED, optionally TESTABLE
 * (per dialect), and only USED BY RUNTIME when its dialect is runtime-wired
 * (Anthropic today). Keys are never rendered. All writes go through
 * ConnectionController (nonce + manage_options + secret-free audit).
 */

defined( 'ABSPATH' ) || exit;

use WPCommandCenter\Admin\ConnectionController;
use WPCommandCenter\Ai\Platform\ConnectionStore;
use WPCommandCenter\Ai\Platform\ProviderCatalog;
use WPCommandCenter\Ai\Platform\Dialect;

$wpcc_notice = ( new ConnectionController() )->handle_post();

$wpcc_store   = new ConnectionStore();
$wpcc_conns   = $wpcc_store->all();
$wpcc_default = $wpcc_store->default_id();
$wpcc_routes  = $wpcc_store->routes();
$wpcc_providers = ProviderCatalog::all();

// Runtime-usable, configured connections (for routing selectors).
$wpcc_runtime_conns = [];
foreach ( $wpcc_conns as $cid => $c ) {
	if ( $wpcc_store->runtime_usable( $c ) && $wpcc_store->is_configured( $c ) && $c['enabled'] ) {
		$wpcc_runtime_conns[ $cid ] = $c['name'];
	}
}
?>
<div class="wrap wpcc-aiplatform" style="max-width:960px;">
	<h1><?php esc_html_e( 'AI Connections', 'wp-command-center' ); ?></h1>
	<p class="description" style="max-width:720px;">
		<?php esc_html_e( 'Connect any AI provider — cloud, local, or a gateway — as a reusable Connection. WP Command Center uses your default Anthropic connection for its AI features today; other connections can be saved and tested now and become usable as more transports ship. AI stays off until you add a key and turn on a feature.', 'wp-command-center' ); ?>
	</p>

	<?php if ( $wpcc_notice ) : ?>
		<div class="notice notice-<?php echo esc_attr( $wpcc_notice['type'] ); ?>"><p><?php echo esc_html( $wpcc_notice['message'] ); ?></p></div>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Your connections', 'wp-command-center' ); ?></h2>
	<?php if ( empty( $wpcc_conns ) ) : ?>
		<div style="background:#fff;border:1px dashed #c3c4c7;border-radius:6px;padding:28px;text-align:center;color:#646970;">
			<p style="font-size:14px;margin:0 0 4px;"><strong><?php esc_html_e( 'No AI connections yet.', 'wp-command-center' ); ?></strong></p>
			<p style="margin:0;"><?php esc_html_e( 'Create your first connection below.', 'wp-command-center' ); ?></p>
		</div>
	<?php else : ?>
		<?php foreach ( $wpcc_conns as $cid => $c ) :
			$def      = $wpcc_providers[ $c['provider'] ] ?? [];
			$has_key  = $wpcc_store->is_configured( $c );
			$is_const = $wpcc_store->credentials()->is_constant_backed( $c );
			$runtime  = $wpcc_store->runtime_usable( $c );
			$testable = $wpcc_store->testable( $c );
			$is_def   = ( $wpcc_default === $cid );
			$lt       = $c['last_test'];
			$editable_ep = Dialect::endpoint_editable( $c['dialect'] );
			?>
			<div class="wpcc-conn" style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid <?php echo $runtime ? '#00a32a' : ( $testable ? '#2271b1' : '#dba617' ); ?>;border-radius:6px;padding:16px 18px;margin-bottom:14px;<?php echo $c['enabled'] ? '' : 'opacity:.65;'; ?>">
				<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
					<div>
						<strong style="font-size:15px;"><?php echo esc_html( $c['name'] ); ?></strong>
						<span style="color:#646970;font-size:12px;margin-left:6px;"><?php echo esc_html( (string) ( $def['label'] ?? $c['provider'] ) ); ?> · <?php echo esc_html( $c['dialect'] ); ?></span>
						<?php if ( $is_def ) : ?><span class="wpcc-badge" style="margin-left:8px;background:#e7f0fb;color:#2271b1;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:700;"><?php esc_html_e( 'DEFAULT', 'wp-command-center' ); ?></span><?php endif; ?>
						<?php if ( $runtime ) : ?>
							<span style="margin-left:6px;background:#edfaef;color:#00a32a;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:700;"><?php esc_html_e( 'USED BY RUNTIME', 'wp-command-center' ); ?></span>
						<?php elseif ( $testable ) : ?>
							<span style="margin-left:6px;background:#eef4fb;color:#2271b1;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:700;" title="<?php esc_attr_e( 'Configured and connection-testable, but not used by WPCC’s AI features yet.', 'wp-command-center' ); ?>"><?php esc_html_e( 'TESTABLE', 'wp-command-center' ); ?></span>
						<?php else : ?>
							<span style="margin-left:6px;background:#fcf9e8;color:#996800;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:700;"><?php esc_html_e( 'STORED ONLY', 'wp-command-center' ); ?></span>
						<?php endif; ?>
						<?php foreach ( $c['tags'] as $tag ) : ?>
							<span style="margin-left:4px;background:#f0f0f1;color:#50575e;padding:2px 7px;border-radius:10px;font-size:11px;"><?php echo esc_html( $tag ); ?></span>
						<?php endforeach; ?>
					</div>
					<div style="font-size:13px;">
						<?php if ( $has_key ) : ?><span style="color:#00a32a;font-weight:600;">&#10003; <?php esc_html_e( 'Ready', 'wp-command-center' ); ?></span>
						<?php else : ?><span style="color:#646970;font-weight:600;"><?php esc_html_e( 'No key yet', 'wp-command-center' ); ?></span><?php endif; ?>
					</div>
				</div>

				<div style="margin:8px 0;color:#646970;font-size:12px;">
					<?php if ( $editable_ep ) : ?><?php esc_html_e( 'Endpoint:', 'wp-command-center' ); ?> <code><?php echo esc_html( $c['endpoint'] ?: '—' ); ?></code> · <?php endif; ?>
					<?php esc_html_e( 'Model:', 'wp-command-center' ); ?> <code><?php echo esc_html( $c['model'] ?: ( $def['default_model'] ?? '—' ) ); ?></code>
					<?php if ( ! $runtime ) : ?> · <em><?php esc_html_e( 'Saved, not used by WPCC runtime yet.', 'wp-command-center' ); ?></em><?php endif; ?>
				</div>

				<!-- Edit form (name/endpoint/model/deployment/tags) -->
				<form method="post" style="border-top:1px solid #f0f0f1;padding-top:10px;margin-bottom:8px;display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px;align-items:end;">
					<?php wp_nonce_field( ConnectionController::NONCE ); ?>
					<input type="hidden" name="wpcc_conn_id" value="<?php echo esc_attr( $cid ); ?>" />
					<input type="hidden" name="wpcc_model" value="custom" />
					<label style="font-size:12px;"><?php esc_html_e( 'Name', 'wp-command-center' ); ?><br><input type="text" name="wpcc_name" value="<?php echo esc_attr( $c['name'] ); ?>" class="regular-text" style="width:100%;" /></label>
					<?php if ( $editable_ep ) : ?>
						<label style="font-size:12px;"><?php esc_html_e( 'Base URL', 'wp-command-center' ); ?><br><input type="url" name="wpcc_endpoint" value="<?php echo esc_attr( $c['endpoint'] ); ?>" class="regular-text" style="width:100%;font-family:monospace;" placeholder="https://…/v1" /></label>
					<?php endif; ?>
					<label style="font-size:12px;"><?php esc_html_e( 'Model', 'wp-command-center' ); ?><br><input type="text" name="wpcc_model_custom" value="<?php echo esc_attr( $c['model'] ); ?>" class="regular-text" style="width:100%;font-family:monospace;" placeholder="<?php echo esc_attr( (string) ( $def['default_model'] ?? '' ) ); ?>" /></label>
					<?php if ( ! empty( $def['needs_deployment'] ) ) : ?>
						<label style="font-size:12px;"><?php esc_html_e( 'Deployment', 'wp-command-center' ); ?><br><input type="text" name="wpcc_deployment" value="<?php echo esc_attr( $c['deployment'] ); ?>" class="regular-text" style="width:100%;" /></label>
					<?php endif; ?>
					<label style="font-size:12px;"><?php esc_html_e( 'Tags (comma-separated)', 'wp-command-center' ); ?><br><input type="text" name="wpcc_tags" value="<?php echo esc_attr( implode( ', ', $c['tags'] ) ); ?>" class="regular-text" style="width:100%;" placeholder="prod, cheap" /></label>
					<div><button type="submit" name="wpcc_conn_action" value="update" class="button button-small"><?php esc_html_e( 'Save', 'wp-command-center' ); ?></button></div>
				</form>

				<!-- Key form -->
				<?php if ( $is_const ) : ?>
					<p style="color:#50575e;font-size:12px;"><?php esc_html_e( 'Key defined in wp-config.php (constant) — read-only.', 'wp-command-center' ); ?></p>
				<?php else : ?>
					<form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:8px;">
						<?php wp_nonce_field( ConnectionController::NONCE ); ?>
						<input type="hidden" name="wpcc_conn_id" value="<?php echo esc_attr( $cid ); ?>" />
						<input type="password" name="wpcc_key" autocomplete="off" spellcheck="false" class="regular-text" style="font-family:monospace;max-width:320px;" placeholder="<?php echo $has_key ? esc_attr__( '•••••••• (paste a new key to replace)', 'wp-command-center' ) : esc_attr__( 'API key', 'wp-command-center' ); ?>" />
						<button type="submit" name="wpcc_conn_action" value="update_key" class="button button-small"><?php echo $has_key ? esc_html__( 'Update key', 'wp-command-center' ) : esc_html__( 'Save key', 'wp-command-center' ); ?></button>
						<?php if ( $has_key ) : ?><button type="submit" name="wpcc_conn_action" value="clear_key" class="button button-small button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Remove this connection’s key?', 'wp-command-center' ) ); ?>');"><?php esc_html_e( 'Remove key', 'wp-command-center' ); ?></button><?php endif; ?>
					</form>
				<?php endif; ?>

				<!-- Actions -->
				<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;border-top:1px solid #f0f0f1;padding-top:10px;">
					<form method="post" style="margin:0;"><?php wp_nonce_field( ConnectionController::NONCE ); ?><input type="hidden" name="wpcc_conn_id" value="<?php echo esc_attr( $cid ); ?>" /><button type="submit" name="wpcc_conn_action" value="test" class="button button-small" <?php disabled( ! $testable || ! $has_key ); ?>><?php esc_html_e( 'Test', 'wp-command-center' ); ?></button></form>
					<?php if ( ! $testable ) : ?><span style="color:#646970;font-size:12px;"><?php esc_html_e( 'Test not available', 'wp-command-center' ); ?></span>
					<?php elseif ( $lt ) : ?><span style="font-size:12px;color:<?php echo $lt['ok'] ? '#00a32a' : '#d63638'; ?>;"><?php echo $lt['ok'] ? '&#10003; ' : '&#10007; '; echo esc_html( $lt['ok'] ? __( 'Tested OK', 'wp-command-center' ) : sprintf( /* translators: %s code */ __( 'Failed (%s)', 'wp-command-center' ), (string) $lt['code'] ) ); echo ' · ' . esc_html( sprintf( /* translators: %s ago */ __( '%s ago', 'wp-command-center' ), human_time_diff( (int) $lt['time'], time() ) ) ); ?></span><?php endif; ?>
					<span style="flex:1;"></span>
					<?php if ( $runtime && ! $is_def && $has_key ) : ?><form method="post" style="margin:0;"><?php wp_nonce_field( ConnectionController::NONCE ); ?><input type="hidden" name="wpcc_conn_id" value="<?php echo esc_attr( $cid ); ?>" /><button type="submit" name="wpcc_conn_action" value="set_default" class="button button-small"><?php esc_html_e( 'Set default', 'wp-command-center' ); ?></button></form><?php endif; ?>
					<form method="post" style="margin:0;"><?php wp_nonce_field( ConnectionController::NONCE ); ?><input type="hidden" name="wpcc_conn_id" value="<?php echo esc_attr( $cid ); ?>" /><input type="hidden" name="wpcc_enabled" value="<?php echo $c['enabled'] ? '0' : '1'; ?>" /><button type="submit" name="wpcc_conn_action" value="set_enabled" class="button button-small"><?php echo $c['enabled'] ? esc_html__( 'Disable', 'wp-command-center' ) : esc_html__( 'Enable', 'wp-command-center' ); ?></button></form>
					<form method="post" style="margin:0;"><?php wp_nonce_field( ConnectionController::NONCE ); ?><input type="hidden" name="wpcc_conn_id" value="<?php echo esc_attr( $cid ); ?>" /><button type="submit" name="wpcc_conn_action" value="duplicate" class="button button-small"><?php esc_html_e( 'Duplicate', 'wp-command-center' ); ?></button></form>
					<form method="post" style="margin:0;"><?php wp_nonce_field( ConnectionController::NONCE ); ?><input type="hidden" name="wpcc_conn_id" value="<?php echo esc_attr( $cid ); ?>" /><button type="submit" name="wpcc_conn_action" value="delete" class="button button-small button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Delete this connection and its key?', 'wp-command-center' ) ); ?>');"><?php esc_html_e( 'Delete', 'wp-command-center' ); ?></button></form>
				</div>
			</div>
		<?php endforeach; ?>
	<?php endif; ?>

	<!-- Create connection -->
	<h2><?php esc_html_e( 'Add a connection', 'wp-command-center' ); ?></h2>
	<form method="post" style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:16px 18px;max-width:600px;">
		<?php wp_nonce_field( ConnectionController::NONCE ); ?>
		<input type="hidden" name="wpcc_model" value="custom" />
		<p style="margin:0 0 10px;">
			<label style="display:block;font-weight:600;font-size:13px;margin-bottom:4px;"><?php esc_html_e( 'Provider', 'wp-command-center' ); ?></label>
			<select name="wpcc_provider" style="min-width:280px;">
				<?php foreach ( $wpcc_providers as $pid => $pdef ) : ?>
					<option value="<?php echo esc_attr( $pid ); ?>"><?php echo esc_html( (string) $pdef['label'] ); ?> — <?php echo esc_html( ProviderCatalog::runtime_usable( $pid ) ? __( 'used by runtime', 'wp-command-center' ) : ( ProviderCatalog::test_supported( $pid ) ? __( 'testable', 'wp-command-center' ) : __( 'stored only', 'wp-command-center' ) ) ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p style="margin:0 0 10px;"><label style="display:block;font-weight:600;font-size:13px;margin-bottom:4px;"><?php esc_html_e( 'Name', 'wp-command-center' ); ?></label><input type="text" name="wpcc_name" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Production Claude', 'wp-command-center' ); ?>" /></p>
		<p style="margin:0 0 10px;"><label style="display:block;font-weight:600;font-size:13px;margin-bottom:4px;"><?php esc_html_e( 'Base URL (for local / gateway / Azure / custom — leave blank for cloud defaults)', 'wp-command-center' ); ?></label><input type="url" name="wpcc_endpoint" class="regular-text" style="font-family:monospace;width:100%;max-width:420px;" placeholder="http://localhost:11434/v1" /></p>
		<p style="margin:0 0 10px;"><label style="display:block;font-weight:600;font-size:13px;margin-bottom:4px;"><?php esc_html_e( 'Model (optional — uses the provider default if blank)', 'wp-command-center' ); ?></label><input type="text" name="wpcc_model_custom" class="regular-text" style="font-family:monospace;" placeholder="model-id" /></p>
		<p style="margin:0 0 10px;"><label style="display:block;font-weight:600;font-size:13px;margin-bottom:4px;"><?php esc_html_e( 'API key (optional now — local models may not need one)', 'wp-command-center' ); ?></label><input type="password" name="wpcc_key" autocomplete="off" spellcheck="false" class="regular-text" style="font-family:monospace;width:100%;max-width:360px;" placeholder="<?php esc_attr_e( 'Paste your API key', 'wp-command-center' ); ?>" /></p>
		<p style="margin:0 0 12px;"><label style="display:block;font-weight:600;font-size:13px;margin-bottom:4px;"><?php esc_html_e( 'Tags', 'wp-command-center' ); ?></label><input type="text" name="wpcc_tags" class="regular-text" placeholder="prod, premium" /></p>
		<button type="submit" name="wpcc_conn_action" value="create" class="button button-primary"><?php esc_html_e( 'Create connection', 'wp-command-center' ); ?></button>
		<p style="margin:10px 0 0;color:#646970;font-size:12px;"><?php esc_html_e( 'Only Anthropic connections are used by WP Command Center’s AI features today. Every connection can be saved; most can be connection-tested. Status is shown honestly — never faked.', 'wp-command-center' ); ?></p>
	</form>

	<div style="margin:16px 0 0;padding:12px 14px;background:#f0f6fc;border:1px solid #c3c4c7;border-radius:4px;max-width:720px;">
		<strong style="font-size:13px;"><?php esc_html_e( 'Key added. What happens next?', 'wp-command-center' ); ?></strong>
		<ol style="margin:8px 0 0;padding-left:20px;color:#50575e;font-size:13px;line-height:1.6;">
			<li><?php esc_html_e( 'Use “Test” on the connection to confirm the key works.', 'wp-command-center' ); ?></li>
			<li>
				<?php
				printf(
					/* translators: 1: link open, 2: link close */
					esc_html__( 'Connect an AI assistant so it can do the work — see %1$sConnect an AI Agent%2$s.', 'wp-command-center' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=wpcc-connect&wpcc_tab=integrations' ) ) . '">',
					'</a>'
				);
				?>
			</li>
			<li><?php esc_html_e( 'Adding a key does not turn AI features on by itself. Built-in AI screens (alt-text, SEO drafts) are enabled per site; ask your developer to switch them on if you do not see them.', 'wp-command-center' ); ?></li>
		</ol>
	</div>

	<!-- Routing -->
	<h2 style="margin-top:28px;"><?php esc_html_e( 'Feature routing', 'wp-command-center' ); ?></h2>
	<p class="description" style="max-width:700px;"><?php esc_html_e( 'Choose which connection each AI feature uses. Only connections WP Command Center can actually run (Anthropic today) can be selected — stored-only connections are not offered here. This is the seam where failover and cost routing will live.', 'wp-command-center' ); ?></p>
	<?php if ( empty( $wpcc_runtime_conns ) ) : ?>
		<p style="color:#646970;font-size:13px;"><?php esc_html_e( 'Add a key to an Anthropic connection to choose feature routing.', 'wp-command-center' ); ?></p>
	<?php else : ?>
		<form method="post" style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:16px 18px;max-width:560px;">
			<?php wp_nonce_field( ConnectionController::NONCE ); ?>
			<table style="width:100%;border-collapse:collapse;">
				<?php foreach ( ConnectionStore::FEATURES as $fk => $flabel ) : ?>
					<tr><td style="padding:6px 0;font-size:13px;"><?php echo esc_html( $flabel ); ?></td><td style="padding:6px 0;text-align:right;">
						<select name="wpcc_route_<?php echo esc_attr( $fk ); ?>">
							<?php foreach ( $wpcc_runtime_conns as $rid => $rname ) : ?>
								<option value="<?php echo esc_attr( $rid ); ?>" <?php selected( ( $wpcc_routes[ $fk ] ?? '' ) === $rid ); ?>><?php echo esc_html( $rname ); ?></option>
							<?php endforeach; ?>
						</select>
					</td></tr>
				<?php endforeach; ?>
			</table>
			<button type="submit" name="wpcc_conn_action" value="save_routes" class="button" style="margin-top:10px;"><?php esc_html_e( 'Save routing', 'wp-command-center' ); ?></button>
		</form>
	<?php endif; ?>

	<hr style="margin:24px 0;border:none;border-top:1px solid #e0e0e0;" />
	<p style="color:#646970;font-size:12px;max-width:720px;">
		<?php esc_html_e( 'Security note: each connection’s key is stored in this site’s database (a WordPress option, not auto-loaded), used only for outbound calls to that connection’s endpoint, never shown in this screen, never written to the audit log, and never sent anywhere else. Anyone who can edit plugins on this site could read stored options — use scoped keys. The Anthropic connection set as default also drives WP Command Center’s AI features (a wp-config constant always takes priority).', 'wp-command-center' ); ?>
	</p>
</div>
