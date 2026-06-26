<?php
/**
 * Phase 4 — Built-in AI tools enablement card (Built-in AI › Providers).
 *
 * Lets an admin turn the built-in AI tools (SEO · Alt Text · Content) on/off from the
 * UI — the design-partner blocker that previously required a wp-config edit. Governed:
 * the toggle is nonce-protected, capability-checked, and audited; tools controlled by a
 * site constant/filter are shown locked ("Set in configuration"). No provider execution,
 * route, capability, MCP tool, or schema changes. Honest about provider reality.
 */

defined( 'ABSPATH' ) || exit;

use WPCommandCenter\Admin\BuiltinAiSettings;

$wpcc_bai_notice = BuiltinAiSettings::handle_post();

$wpcc_bai_status_text = static function ( string $status ): string {
	switch ( $status ) {
		case 'enabled':           return __( 'On', 'wp-command-center' );
		case 'requires_provider': return __( 'On — connect a provider to generate', 'wp-command-center' );
		case 'enabled_by_config': return __( 'On — set in your site configuration', 'wp-command-center' );
		case 'disabled_by_config':return __( 'Off — set in your site configuration', 'wp-command-center' );
		default:                  return __( 'Off', 'wp-command-center' );
	}
};
?>
<section class="wpcc-cds-card" style="max-width:760px;margin:0 0 22px;" aria-labelledby="wpcc-bai-tools-h">
	<h2 id="wpcc-bai-tools-h" style="margin:0 0 4px;font-size:15px;"><?php esc_html_e( 'Built-in AI tools', 'wp-command-center' ); ?></h2>
	<p class="description" style="margin:0;">
		<?php esc_html_e( 'Turn on the AI tools you want to use — each appears as a tab here once it’s on. Generation runs on the provider you select as the default (Anthropic or an OpenAI-compatible provider).', 'wp-command-center' ); ?>
	</p>

	<?php if ( $wpcc_bai_notice ) : ?>
		<div class="wpcc-cds-notice wpcc-cds-notice--<?php echo esc_attr( 'warning' === $wpcc_bai_notice['type'] ? 'warning' : 'success' ); ?>" role="status" style="margin:12px 0 0;">
			<p><?php echo esc_html( $wpcc_bai_notice['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<ul style="list-style:none;margin:14px 0 0;padding:0;display:grid;gap:10px;">
		<?php foreach ( BuiltinAiSettings::tools() as $wpcc_tool_key => $wpcc_tool ) :
			$wpcc_status  = BuiltinAiSettings::status( $wpcc_tool_key );
			$wpcc_is_on   = in_array( $wpcc_status, [ 'enabled', 'requires_provider', 'enabled_by_config' ], true );
			$wpcc_locked  = in_array( $wpcc_status, [ 'enabled_by_config', 'disabled_by_config' ], true );
			$wpcc_warn    = 'requires_provider' === $wpcc_status;
			?>
			<li style="display:flex;align-items:center;justify-content:space-between;gap:14px;padding:11px 13px;background:var(--wpcc-surface-sunken);border-radius:var(--wpcc-radius-sm);">
				<span style="display:flex;align-items:center;gap:10px;">
					<span class="dashicons <?php echo $wpcc_is_on ? ( $wpcc_warn ? 'dashicons-warning' : 'dashicons-yes-alt' ) : 'dashicons-marker'; ?>" aria-hidden="true" style="color:<?php echo $wpcc_is_on ? ( $wpcc_warn ? 'var(--wpcc-state-warning-fg)' : 'var(--wpcc-state-success-fg)' ) : 'var(--wpcc-gray-500)'; ?>;"></span>
					<span style="display:flex;flex-direction:column;">
						<strong><?php echo esc_html( $wpcc_tool['label'] ); ?></strong>
						<span class="description" style="font-size:12px;"><?php echo esc_html( $wpcc_bai_status_text( $wpcc_status ) ); ?></span>
					</span>
				</span>
				<?php if ( $wpcc_locked ) : ?>
					<span class="description" style="white-space:nowrap;"><span class="dashicons dashicons-lock" aria-hidden="true"></span> <?php esc_html_e( 'Locked', 'wp-command-center' ); ?></span>
				<?php else : ?>
					<form method="post" style="margin:0;">
						<?php wp_nonce_field( BuiltinAiSettings::NONCE ); ?>
						<input type="hidden" name="wpcc_builtin_ai_tool" value="<?php echo esc_attr( $wpcc_tool_key ); ?>">
						<input type="hidden" name="wpcc_builtin_ai_state" value="<?php echo $wpcc_is_on ? '0' : '1'; ?>">
						<button type="submit" class="button <?php echo $wpcc_is_on ? '' : 'button-primary'; ?>">
							<?php echo $wpcc_is_on ? esc_html__( 'Turn off', 'wp-command-center' ) : esc_html__( 'Turn on', 'wp-command-center' ); ?>
						</button>
					</form>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
</section>
