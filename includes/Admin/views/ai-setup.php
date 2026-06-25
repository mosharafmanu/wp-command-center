<?php
/**
 * PROGRAM-6 — AI Setup view (multi-provider configuration).
 *
 * A real provider-management surface: add providers, store/replace/remove keys,
 * choose models, test connections, set the default, map features — with honest
 * runtime labels (only Anthropic is wired to WPCC's AI features today; others are
 * stored, and OpenAI/Gemini can be connection-tested).
 *
 * The API key is NEVER rendered: forms show only a configured/not-configured
 * state. All writes go through ProviderConfigController (nonce + manage_options +
 * secret-free audit). No routes/operations/capabilities/MCP/schema.
 */

defined( 'ABSPATH' ) || exit;

use WPCommandCenter\Admin\ProviderCatalog;
use WPCommandCenter\Admin\ProviderConfigController;
use WPCommandCenter\Admin\ProviderStore;

$wpcc_notice = ( new ProviderConfigController() )->handle_post();

$wpcc_store    = new ProviderStore();
$wpcc_records  = $wpcc_store->records();
$wpcc_default  = $wpcc_store->default_type();
$wpcc_features = $wpcc_store->feature_map();
$wpcc_types    = ProviderCatalog::types();

// Provider types not yet configured (for the Add Provider selector).
$wpcc_available = [];
foreach ( $wpcc_types as $wpcc_tid => $wpcc_tdef ) {
	if ( ! isset( $wpcc_records[ $wpcc_tid ] ) ) {
		$wpcc_available[ $wpcc_tid ] = $wpcc_tdef['label'];
	}
}

/** Render a model <select> for a provider type + current value. */
$wpcc_model_select = static function ( string $type, string $current ) use ( $wpcc_types ) {
	$def    = $wpcc_types[ $type ];
	$models = is_array( $def['models'] ) ? $def['models'] : [];
	$is_preset = isset( $models[ $current ] );
	echo '<select name="wpcc_provider_model" onchange="var c=this.closest(\'form\').querySelector(\'.wpcc-cm\'); if(c) c.style.display=(this.value===\'custom\'?\'block\':\'none\');">';
	foreach ( $models as $mid => $mlabel ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $mid ), selected( $is_preset && $current === $mid, true, false ), esc_html( $mlabel ) );
	}
	if ( ! empty( $def['allow_custom_model'] ) ) {
		printf( '<option value="custom"%s>%s</option>', selected( ! $is_preset && '' !== $current, true, false ), esc_html__( 'Custom…', 'wp-command-center' ) );
	}
	echo '</select>';
	$show_custom = ! $is_preset && '' !== $current;
	printf(
		'<div class="wpcc-cm" style="margin-top:8px;display:%s;"><input type="text" name="wpcc_provider_model_custom" class="regular-text" style="font-family:monospace;max-width:320px;" value="%s" placeholder="model-id" /></div>',
		$show_custom ? 'block' : 'none',
		esc_attr( $is_preset ? '' : $current )
	);
};
?>
<div class="wrap wpcc-aisetup" style="max-width:900px;">
	<h1><?php esc_html_e( 'AI Setup', 'wp-command-center' ); ?></h1>
	<p class="description" style="max-width:700px;">
		<?php esc_html_e( 'Configure the AI providers WP Command Center can use. Add a provider, paste your API key, choose a model, and test the connection. AI stays off until you add a key and turn on an AI feature — the rest of the plugin works without it.', 'wp-command-center' ); ?>
	</p>

	<?php if ( $wpcc_notice ) : ?>
		<div class="notice notice-<?php echo esc_attr( $wpcc_notice['type'] ); ?>"><p><?php echo esc_html( $wpcc_notice['message'] ); ?></p></div>
	<?php endif; ?>

	<details style="max-width:700px;margin:8px 0 18px;">
		<summary style="cursor:pointer;font-weight:600;font-size:13px;color:#2271b1;"><?php esc_html_e( 'About models — why this model? What changes if I switch?', 'wp-command-center' ); ?></summary>
		<div style="margin-top:10px;color:#50575e;font-size:13px;display:grid;gap:8px;">
			<p style="margin:0;"><strong><?php esc_html_e( 'Recommended (Sonnet / balanced):', 'wp-command-center' ); ?></strong> <?php esc_html_e( 'strong quality at a sensible cost and speed — best for most sites.', 'wp-command-center' ); ?></p>
			<p style="margin:0;"><strong><?php esc_html_e( 'Higher capability:', 'wp-command-center' ); ?></strong> <?php esc_html_e( 'better on hard tasks, but slower and more expensive per request.', 'wp-command-center' ); ?></p>
			<p style="margin:0;"><strong><?php esc_html_e( 'Faster / cheaper:', 'wp-command-center' ); ?></strong> <?php esc_html_e( 'quickest and lowest cost — great for simple, high-volume work.', 'wp-command-center' ); ?></p>
			<p style="margin:0;color:#646970;"><?php esc_html_e( 'Switching a model only changes which model handles future AI requests. It does not change your key, your saved work, or anything already on your site.', 'wp-command-center' ); ?></p>
		</div>
	</details>

	<!-- ===== Configured providers ===== -->
	<h2><?php esc_html_e( 'Your providers', 'wp-command-center' ); ?></h2>

	<?php if ( empty( $wpcc_records ) ) : ?>
		<div style="background:#fff;border:1px dashed #c3c4c7;border-radius:6px;padding:28px;text-align:center;color:#646970;">
			<p style="font-size:14px;margin:0 0 4px;"><strong><?php esc_html_e( 'No AI providers configured yet.', 'wp-command-center' ); ?></strong></p>
			<p style="margin:0;"><?php esc_html_e( 'Add your first provider below to enable AI features.', 'wp-command-center' ); ?></p>
		</div>
	<?php else : ?>
		<?php foreach ( $wpcc_records as $wpcc_type => $wpcc_rec ) : ?>
			<?php
			$wpcc_def       = $wpcc_types[ $wpcc_type ];
			$wpcc_has_key   = $wpcc_store->has_secret( $wpcc_type );
			$wpcc_is_const  = $wpcc_store->is_constant_secret( $wpcc_type );
			$wpcc_runtime   = ProviderCatalog::runtime_usable( $wpcc_type );
			$wpcc_can_test  = ProviderCatalog::test_supported( $wpcc_type );
			$wpcc_is_def    = ( $wpcc_default === $wpcc_type );
			$wpcc_lt        = $wpcc_rec['last_test'];
			?>
			<div class="wpcc-prov" style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid <?php echo $wpcc_runtime ? '#00a32a' : '#dba617'; ?>;border-radius:4px;padding:16px 18px;margin-bottom:14px;<?php echo $wpcc_rec['enabled'] ? '' : 'opacity:.7;'; ?>">
				<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
					<div>
						<strong style="font-size:15px;"><?php echo esc_html( $wpcc_rec['name'] ); ?></strong>
						<?php if ( $wpcc_is_def ) : ?>
							<span style="margin-left:8px;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:700;background:#e7f0fb;color:#2271b1;"><?php esc_html_e( 'DEFAULT', 'wp-command-center' ); ?></span>
						<?php endif; ?>
						<?php if ( $wpcc_runtime ) : ?>
							<span style="margin-left:6px;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:700;background:#edfaef;color:#00a32a;"><?php esc_html_e( 'USED BY WPCC', 'wp-command-center' ); ?></span>
						<?php else : ?>
							<span style="margin-left:6px;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:700;background:#fcf9e8;color:#996800;" title="<?php esc_attr_e( 'Stored and (where supported) testable, but WP Command Center does not route its AI features through this provider yet.', 'wp-command-center' ); ?>"><?php esc_html_e( 'STORED ONLY', 'wp-command-center' ); ?></span>
						<?php endif; ?>
					</div>
					<div style="font-size:13px;">
						<?php if ( $wpcc_has_key ) : ?>
							<span style="color:#00a32a;font-weight:600;">&#10003; <?php esc_html_e( 'Key configured', 'wp-command-center' ); ?></span>
						<?php else : ?>
							<span style="color:#646970;font-weight:600;"><?php esc_html_e( 'No key yet', 'wp-command-center' ); ?></span>
						<?php endif; ?>
					</div>
				</div>

				<p style="margin:6px 0 12px;color:#646970;font-size:12px;max-width:640px;">
					<?php echo esc_html( $wpcc_def['description'] ); ?>
					<?php if ( ! $wpcc_runtime ) : ?>
						<em><?php esc_html_e( 'Saved, but not used by WPCC runtime yet.', 'wp-command-center' ); ?></em>
					<?php endif; ?>
				</p>

				<!-- Key form -->
				<?php if ( $wpcc_is_const ) : ?>
					<p style="color:#50575e;font-size:13px;"><?php esc_html_e( 'The key is defined in wp-config.php (a constant) and cannot be changed here.', 'wp-command-center' ); ?></p>
				<?php else : ?>
					<form method="post" style="display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap;margin-bottom:10px;">
						<?php wp_nonce_field( ProviderConfigController::NONCE ); ?>
						<input type="hidden" name="wpcc_provider_type" value="<?php echo esc_attr( $wpcc_type ); ?>" />
						<input type="password" name="wpcc_provider_key" autocomplete="off" spellcheck="false" class="regular-text" style="font-family:monospace;max-width:340px;"
							placeholder="<?php echo $wpcc_has_key ? esc_attr__( '•••••••• (hidden — paste a new key to replace)', 'wp-command-center' ) : esc_attr( $wpcc_def['key_prefix_hint'] !== '' ? $wpcc_def['key_prefix_hint'] . '…' : 'API key' ); ?>" />
						<button type="submit" name="wpcc_provider_action" value="update_key" class="button"><?php echo $wpcc_has_key ? esc_html__( 'Update key', 'wp-command-center' ) : esc_html__( 'Save key', 'wp-command-center' ); ?></button>
						<?php if ( $wpcc_has_key ) : ?>
							<button type="submit" name="wpcc_provider_action" value="clear_key" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Remove the saved key for this provider?', 'wp-command-center' ) ); ?>');"><?php esc_html_e( 'Remove key', 'wp-command-center' ); ?></button>
						<?php endif; ?>
						<span style="flex-basis:100%;color:#646970;font-size:12px;"><?php echo esc_html( $wpcc_def['key_help'] ); ?></span>
					</form>
				<?php endif; ?>

				<!-- Model form -->
				<?php if ( ! empty( $wpcc_def['models'] ) || ! empty( $wpcc_def['allow_custom_model'] ) ) : ?>
					<form method="post" style="margin-bottom:10px;">
						<?php wp_nonce_field( ProviderConfigController::NONCE ); ?>
						<input type="hidden" name="wpcc_provider_type" value="<?php echo esc_attr( $wpcc_type ); ?>" />
						<label style="font-size:13px;font-weight:600;margin-right:8px;"><?php esc_html_e( 'Model', 'wp-command-center' ); ?></label>
						<?php $wpcc_model_select( $wpcc_type, (string) $wpcc_rec['model'] ); ?>
						<button type="submit" name="wpcc_provider_action" value="save_model" class="button button-small" style="margin-left:8px;"><?php esc_html_e( 'Save model', 'wp-command-center' ); ?></button>
					</form>
				<?php endif; ?>

				<!-- Actions row -->
				<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;border-top:1px solid #f0f0f1;padding-top:10px;">
					<!-- Test -->
					<form method="post" style="margin:0;">
						<?php wp_nonce_field( ProviderConfigController::NONCE ); ?>
						<input type="hidden" name="wpcc_provider_type" value="<?php echo esc_attr( $wpcc_type ); ?>" />
						<button type="submit" name="wpcc_provider_action" value="test_connection" class="button button-small" <?php disabled( ! $wpcc_can_test || ! $wpcc_has_key ); ?>>
							<?php esc_html_e( 'Test connection', 'wp-command-center' ); ?>
						</button>
					</form>
					<?php if ( ! $wpcc_can_test ) : ?>
						<span style="color:#646970;font-size:12px;"><?php esc_html_e( 'Test not available yet', 'wp-command-center' ); ?></span>
					<?php elseif ( $wpcc_lt ) : ?>
						<span style="font-size:12px;color:<?php echo $wpcc_lt['ok'] ? '#00a32a' : '#d63638'; ?>;">
							<?php
							echo $wpcc_lt['ok'] ? '&#10003; ' : '&#10007; ';
							echo esc_html( $wpcc_lt['ok'] ? __( 'Tested OK', 'wp-command-center' ) : sprintf( /* translators: %s: error code */ __( 'Test failed (%s)', 'wp-command-center' ), (string) $wpcc_lt['code'] ) );
							echo ' · ' . esc_html( sprintf( /* translators: %s: time diff */ __( '%s ago', 'wp-command-center' ), human_time_diff( (int) $wpcc_lt['time'], time() ) ) );
							?>
						</span>
					<?php endif; ?>

					<span style="flex:1;"></span>

					<!-- Set default (runtime-usable only) -->
					<?php if ( $wpcc_runtime && ! $wpcc_is_def && $wpcc_has_key ) : ?>
						<form method="post" style="margin:0;">
							<?php wp_nonce_field( ProviderConfigController::NONCE ); ?>
							<input type="hidden" name="wpcc_provider_type" value="<?php echo esc_attr( $wpcc_type ); ?>" />
							<button type="submit" name="wpcc_provider_action" value="set_default" class="button button-small"><?php esc_html_e( 'Set as default', 'wp-command-center' ); ?></button>
						</form>
					<?php endif; ?>

					<!-- Enable/disable -->
					<form method="post" style="margin:0;">
						<?php wp_nonce_field( ProviderConfigController::NONCE ); ?>
						<input type="hidden" name="wpcc_provider_type" value="<?php echo esc_attr( $wpcc_type ); ?>" />
						<input type="hidden" name="wpcc_provider_enabled" value="<?php echo $wpcc_rec['enabled'] ? '0' : '1'; ?>" />
						<button type="submit" name="wpcc_provider_action" value="set_enabled" class="button button-small"><?php echo $wpcc_rec['enabled'] ? esc_html__( 'Disable', 'wp-command-center' ) : esc_html__( 'Enable', 'wp-command-center' ); ?></button>
					</form>

					<!-- Delete -->
					<form method="post" style="margin:0;">
						<?php wp_nonce_field( ProviderConfigController::NONCE ); ?>
						<input type="hidden" name="wpcc_provider_type" value="<?php echo esc_attr( $wpcc_type ); ?>" />
						<button type="submit" name="wpcc_provider_action" value="delete_provider" class="button button-small button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Remove this provider and its saved key?', 'wp-command-center' ) ); ?>');"><?php esc_html_e( 'Delete', 'wp-command-center' ); ?></button>
					</form>
				</div>
			</div>
		<?php endforeach; ?>
	<?php endif; ?>

	<!-- ===== Add provider ===== -->
	<?php if ( ! empty( $wpcc_available ) ) : ?>
		<h2><?php esc_html_e( 'Add a provider', 'wp-command-center' ); ?></h2>
		<form method="post" style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:16px 18px;max-width:560px;">
			<?php wp_nonce_field( ProviderConfigController::NONCE ); ?>
			<p style="margin:0 0 10px;">
				<label style="display:block;font-weight:600;font-size:13px;margin-bottom:4px;"><?php esc_html_e( 'Provider type', 'wp-command-center' ); ?></label>
				<select name="wpcc_provider_type" style="min-width:240px;">
					<?php foreach ( $wpcc_available as $wpcc_aid => $wpcc_alabel ) : ?>
						<option value="<?php echo esc_attr( $wpcc_aid ); ?>"><?php echo esc_html( $wpcc_alabel ); ?><?php echo ProviderCatalog::runtime_usable( $wpcc_aid ) ? '' : esc_html__( ' — stored only', 'wp-command-center' ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p style="margin:0 0 10px;">
				<label style="display:block;font-weight:600;font-size:13px;margin-bottom:4px;"><?php esc_html_e( 'API key (optional now — you can add it after)', 'wp-command-center' ); ?></label>
				<input type="password" name="wpcc_provider_key" autocomplete="off" spellcheck="false" class="regular-text" style="font-family:monospace;width:100%;max-width:360px;" placeholder="<?php esc_attr_e( 'Paste your API key', 'wp-command-center' ); ?>" />
			</p>
			<button type="submit" name="wpcc_provider_action" value="save_provider" class="button button-primary"><?php esc_html_e( 'Add provider', 'wp-command-center' ); ?></button>
			<p style="margin:10px 0 0;color:#646970;font-size:12px;"><?php esc_html_e( 'Only Anthropic is used by WP Command Center’s AI features today. Other providers are stored (and OpenAI/Gemini can be connection-tested) for when more connectors ship — they are clearly labelled.', 'wp-command-center' ); ?></p>
		</form>
	<?php else : ?>
		<p style="color:#646970;font-size:13px;"><?php esc_html_e( 'All available provider types are configured.', 'wp-command-center' ); ?></p>
	<?php endif; ?>

	<!-- ===== Defaults & feature mapping ===== -->
	<?php
	$wpcc_runtime_choices = [];
	foreach ( $wpcc_records as $wpcc_rt => $wpcc_rrec ) {
		if ( ProviderCatalog::runtime_usable( $wpcc_rt ) && $wpcc_store->has_secret( $wpcc_rt ) && $wpcc_rrec['enabled'] ) {
			$wpcc_runtime_choices[ $wpcc_rt ] = $wpcc_rrec['name'];
		}
	}
	?>
	<h2 style="margin-top:28px;"><?php esc_html_e( 'Which provider does each feature use?', 'wp-command-center' ); ?></h2>
	<p class="description" style="max-width:680px;">
		<?php esc_html_e( 'WP Command Center can only route a feature through a provider it is able to call. Today that is Anthropic. Providers stored for future use cannot be selected here yet — this is shown honestly, not hidden.', 'wp-command-center' ); ?>
	</p>
	<?php if ( empty( $wpcc_runtime_choices ) ) : ?>
		<p style="color:#646970;font-size:13px;"><?php esc_html_e( 'Add a key for Anthropic above to choose feature providers.', 'wp-command-center' ); ?></p>
	<?php else : ?>
		<form method="post" style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:16px 18px;max-width:560px;">
			<?php wp_nonce_field( ProviderConfigController::NONCE ); ?>
			<table style="width:100%;border-collapse:collapse;">
				<?php foreach ( ProviderStore::FEATURES as $wpcc_fk => $wpcc_flabel ) : ?>
					<tr>
						<td style="padding:6px 0;font-size:13px;"><?php echo esc_html( $wpcc_flabel ); ?></td>
						<td style="padding:6px 0;text-align:right;">
							<select name="wpcc_feature_<?php echo esc_attr( $wpcc_fk ); ?>">
								<?php foreach ( $wpcc_runtime_choices as $wpcc_cid => $wpcc_cname ) : ?>
									<option value="<?php echo esc_attr( $wpcc_cid ); ?>" <?php selected( ( $wpcc_features[ $wpcc_fk ] ?? '' ) === $wpcc_cid ); ?>><?php echo esc_html( $wpcc_cname ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
			<button type="submit" name="wpcc_provider_action" value="save_feature_map" class="button" style="margin-top:10px;"><?php esc_html_e( 'Save feature mapping', 'wp-command-center' ); ?></button>
		</form>
	<?php endif; ?>

	<!-- ===== After-key guidance (preserved from 5C) ===== -->
	<?php if ( $wpcc_store->has_secret( 'anthropic' ) ) : ?>
		<div style="margin:24px 0 0;padding:12px 14px;background:#f0f6fc;border:1px solid #c3c4c7;border-radius:4px;max-width:760px;">
			<strong style="font-size:13px;"><?php esc_html_e( 'Key added. What happens next?', 'wp-command-center' ); ?></strong>
			<ol style="margin:8px 0 0;padding-left:20px;color:#50575e;font-size:13px;line-height:1.6;">
				<li><?php esc_html_e( 'Use “Test connection” on the provider above to confirm your key works.', 'wp-command-center' ); ?></li>
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
				<li><?php esc_html_e( 'Adding a key does not turn AI features on by itself. Built-in AI screens (like alt-text and SEO drafts) are enabled per site; ask your developer to switch them on if you do not see them.', 'wp-command-center' ); ?></li>
			</ol>
		</div>
	<?php endif; ?>

	<hr style="margin:24px 0;border:none;border-top:1px solid #e0e0e0;" />
	<p style="color:#646970;font-size:12px;max-width:700px;">
		<?php esc_html_e( 'Security note: each key is stored in this site’s database (a WordPress option, not auto-loaded) and used only for outbound calls to that provider. Keys are never shown in this screen, never written to the audit log, and never sent anywhere except the provider you chose. Anyone who can edit plugins on this site could read stored options, so use scoped provider keys.', 'wp-command-center' ); ?>
	</p>
</div>
