<?php
/**
 * PROGRAM-5A — AI Setup view (Connect → AI Setup).
 *
 * Provider key management, model selection, and a connection test for WPCC's
 * outbound AI. Anthropic is the only wired transport, so it is fully manageable
 * here; OpenAI and Gemini are shown as Planned (not yet supported) rather than
 * offering key fields that would do nothing.
 *
 * The API key is NEVER rendered: the form shows only a configured/not-configured
 * state. All writes go through AiSetupController (nonce + manage_options + audit,
 * no secret). This view adds no routes, operations, capabilities, or schema.
 */

defined( 'ABSPATH' ) || exit;

use WPCommandCenter\Admin\AdoptionStatus;
use WPCommandCenter\Admin\AiSetupController;

$wpcc_notice = ( new AiSetupController() )->handle_post();

$wpcc_configured   = AdoptionStatus::ai_configured();
$wpcc_key_constant = AdoptionStatus::ai_key_is_constant();
$wpcc_key_source   = AdoptionStatus::ai_key_source();
$wpcc_model        = AdoptionStatus::ai_model();
$wpcc_last_test    = AiSetupController::last_test();
$wpcc_presets      = AiSetupController::MODEL_PRESETS;
$wpcc_is_preset    = isset( $wpcc_presets[ $wpcc_model ] );
?>
<div class="wrap wpcc-aisetup" style="max-width:880px;">
	<h1><?php esc_html_e( 'AI Setup', 'wp-command-center' ); ?></h1>
	<p class="description" style="max-width:680px;">
		<?php esc_html_e( 'Connect an AI provider so WP Command Center can generate suggestions (for example alt text or SEO meta). AI is optional and stays off until you add a key and enable a feature — the rest of the plugin works without it.', 'wp-command-center' ); ?>
	</p>

	<?php if ( $wpcc_notice ) : ?>
		<div class="notice notice-<?php echo esc_attr( $wpcc_notice['type'] ); ?>"><p><?php echo esc_html( $wpcc_notice['message'] ); ?></p></div>
	<?php endif; ?>

	<!-- ===== Providers ===== -->
	<h2><?php esc_html_e( 'Provider', 'wp-command-center' ); ?></h2>

	<div class="wpcc-aisetup-card" style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid #00a32a;border-radius:4px;padding:18px 20px;margin-bottom:16px;">
		<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
			<div>
				<strong style="font-size:15px;"><?php esc_html_e( 'Anthropic (Claude)', 'wp-command-center' ); ?></strong>
				<span style="display:inline-block;margin-left:8px;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:700;background:#edfaef;color:#00a32a;"><?php esc_html_e( 'SUPPORTED', 'wp-command-center' ); ?></span>
			</div>
			<div>
				<?php if ( $wpcc_configured ) : ?>
					<span style="font-weight:600;color:#00a32a;">&#10003; <?php esc_html_e( 'Key configured', 'wp-command-center' ); ?></span>
				<?php else : ?>
					<span style="font-weight:600;color:#646970;"><?php esc_html_e( 'No key yet', 'wp-command-center' ); ?></span>
				<?php endif; ?>
			</div>
		</div>

		<?php if ( $wpcc_key_constant ) : ?>
			<p style="margin:14px 0 0;color:#50575e;">
				<?php esc_html_e( 'The key is defined in wp-config.php (a PHP constant). To manage it from this screen, remove that constant. Constants always take priority and are never editable here.', 'wp-command-center' ); ?>
			</p>
		<?php else : ?>
			<form method="post" style="margin-top:14px;">
				<?php wp_nonce_field( AiSetupController::NONCE_ACTION ); ?>
				<label for="wpcc-api-key" style="display:block;font-weight:600;margin-bottom:4px;">
					<?php echo $wpcc_configured ? esc_html__( 'Replace API key', 'wp-command-center' ) : esc_html__( 'API key', 'wp-command-center' ); ?>
				</label>
				<input type="password" id="wpcc-api-key" name="wpcc_api_key" autocomplete="off" spellcheck="false"
					class="regular-text" style="width:100%;max-width:480px;font-family:monospace;"
					placeholder="<?php echo $wpcc_configured ? esc_attr__( '•••••••• (hidden — paste a new key to replace)', 'wp-command-center' ) : esc_attr_e( 'sk-ant-…', 'wp-command-center' ); ?>" />
				<p style="color:#646970;font-size:12px;margin:6px 0 10px;max-width:560px;">
					<?php esc_html_e( 'Stored on this site only and used for your own API calls (you pay your provider directly). For security it is never displayed again after saving.', 'wp-command-center' ); ?>
				</p>
				<button type="submit" name="wpcc_ai_setup_action" value="save_key" class="button button-primary">
					<?php echo $wpcc_configured ? esc_html__( 'Update key', 'wp-command-center' ) : esc_html__( 'Save key', 'wp-command-center' ); ?>
				</button>
				<?php if ( $wpcc_configured ) : ?>
					<button type="submit" name="wpcc_ai_setup_action" value="clear_key" class="button button-link-delete"
						onclick="return confirm('<?php echo esc_js( __( 'Remove the saved API key? AI features will turn off until you add a new one.', 'wp-command-center' ) ); ?>');">
						<?php esc_html_e( 'Remove key', 'wp-command-center' ); ?>
					</button>
				<?php endif; ?>
			</form>
		<?php endif; ?>
	</div>

	<!-- Planned providers: shown, not offered (no fake key fields). -->
	<div class="wpcc-aisetup-card" style="background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:14px 18px;margin-bottom:24px;opacity:.85;">
		<strong style="font-size:14px;color:#50575e;"><?php esc_html_e( 'OpenAI · Google Gemini', 'wp-command-center' ); ?></strong>
		<span style="display:inline-block;margin-left:8px;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:700;background:#f0f0f1;color:#646970;"><?php esc_html_e( 'PLANNED', 'wp-command-center' ); ?></span>
		<p style="margin:8px 0 0;color:#646970;font-size:12px;max-width:620px;">
			<?php esc_html_e( 'Not yet supported. WP Command Center currently routes all AI through Anthropic. Other providers will appear here when their transport ships — no key is collected for them today.', 'wp-command-center' ); ?>
		</p>
	</div>

	<!-- ===== Model ===== -->
	<h2><?php esc_html_e( 'Model', 'wp-command-center' ); ?></h2>
	<p class="description" style="max-width:620px;">
		<?php esc_html_e( 'Which Claude model to use. You can change this without re-entering your key. Saving a model never calls the provider.', 'wp-command-center' ); ?>
	</p>
	<form method="post" style="margin:10px 0 24px;">
		<?php wp_nonce_field( AiSetupController::NONCE_ACTION ); ?>
		<select name="wpcc_model_choice" id="wpcc-model-choice" onchange="document.getElementById('wpcc-model-custom-row').style.display = (this.value === 'custom' ? 'block' : 'none');">
			<?php foreach ( $wpcc_presets as $id => $label ) : ?>
				<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $wpcc_is_preset && $wpcc_model === $id ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
			<option value="custom" <?php selected( ! $wpcc_is_preset && '' !== $wpcc_model ); ?>><?php esc_html_e( 'Custom…', 'wp-command-center' ); ?></option>
		</select>
		<div id="wpcc-model-custom-row" style="margin-top:10px;display:<?php echo ( ! $wpcc_is_preset && '' !== $wpcc_model ) ? 'block' : 'none'; ?>;">
			<input type="text" name="wpcc_model_custom" class="regular-text" style="font-family:monospace;max-width:360px;"
				value="<?php echo esc_attr( $wpcc_is_preset ? '' : $wpcc_model ); ?>" placeholder="claude-…" />
			<p style="color:#646970;font-size:12px;margin:4px 0 0;"><?php esc_html_e( 'Advanced: enter an exact model id. Invalid ids are rejected on save.', 'wp-command-center' ); ?></p>
		</div>
		<p style="margin-top:12px;">
			<button type="submit" name="wpcc_ai_setup_action" value="save_model" class="button"><?php esc_html_e( 'Save model', 'wp-command-center' ); ?></button>
			<span style="margin-left:10px;color:#646970;font-size:12px;">
				<?php
				/* translators: %s: active model id */
				printf( esc_html__( 'Active: %s', 'wp-command-center' ), '<code>' . esc_html( $wpcc_model !== '' ? $wpcc_model : AiSetupController::DEFAULT_MODEL ) . '</code>' );
				?>
			</span>
		</p>
	</form>

	<!-- ===== Connection test ===== -->
	<h2><?php esc_html_e( 'Test connection', 'wp-command-center' ); ?></h2>
	<p class="description" style="max-width:620px;">
		<?php esc_html_e( 'Sends one tiny request to verify your key works. It makes no changes to your site and creates no drafts.', 'wp-command-center' ); ?>
	</p>
	<form method="post" style="margin:10px 0 8px;">
		<?php wp_nonce_field( AiSetupController::NONCE_ACTION ); ?>
		<button type="submit" name="wpcc_ai_setup_action" value="test_connection" class="button button-secondary" <?php disabled( ! $wpcc_configured ); ?>>
			<?php esc_html_e( 'Test connection', 'wp-command-center' ); ?>
		</button>
		<?php if ( ! $wpcc_configured ) : ?>
			<span style="margin-left:10px;color:#646970;font-size:12px;"><?php esc_html_e( 'Add a key first.', 'wp-command-center' ); ?></span>
		<?php endif; ?>
	</form>
	<?php if ( $wpcc_last_test ) : ?>
		<p style="font-size:13px;color:<?php echo $wpcc_last_test['ok'] ? '#00a32a' : '#d63638'; ?>;">
			<?php
			$when = sprintf(
				/* translators: %s: human time diff, e.g. "2 minutes" */
				esc_html__( '%s ago', 'wp-command-center' ),
				esc_html( human_time_diff( $wpcc_last_test['time'], time() ) )
			);
			if ( $wpcc_last_test['ok'] ) {
				echo '&#10003; ' . esc_html__( 'Last test: succeeded', 'wp-command-center' ) . ' — ' . $when; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pieces escaped above.
			} else {
				echo '&#10007; ' . esc_html__( 'Last test: failed', 'wp-command-center' ) . ' (' . esc_html( $wpcc_last_test['code'] ) . ') — ' . $when; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pieces escaped above.
			}
			?>
		</p>
	<?php endif; ?>

	<hr style="margin:24px 0;border:none;border-top:1px solid #e0e0e0;" />
	<p style="color:#646970;font-size:12px;max-width:680px;">
		<?php esc_html_e( 'Security note: your key is stored in this site\'s database (a WordPress option) and used only for outbound calls to your provider. It is never shown in this screen, never written to the audit log, and never sent anywhere except your AI provider. Anyone who can edit plugins on this site could read stored options, so use a scoped provider key.', 'wp-command-center' ); ?>
	</p>
</div>
