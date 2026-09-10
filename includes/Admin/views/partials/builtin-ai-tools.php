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

/*
 * The toggle POST is normally handled by the hosting view BEFORE it builds the pane
 * list, because a tool turned on in this request must appear as a tab in the SAME
 * response. `$wpcc_bai_handled` says so. Kept as a fallback so this partial still
 * works if included somewhere that has not done that — but never run twice, which
 * would process one toggle as two.
 */
$wpcc_bai_notice = $wpcc_bai_handled ?? BuiltinAiSettings::handle_post();

$wpcc_bai_status_text = static function ( string $status ): string {
	switch ( $status ) {
		case 'enabled':           return __( 'On', 'action-steward' );
		case 'requires_provider': return __( 'On — connect a provider to generate', 'action-steward' );
		case 'enabled_by_config': return __( 'On — turned on in your site’s code', 'action-steward' );
		case 'disabled_by_config':return __( 'Off — turned off in your site’s code', 'action-steward' );
		default:                  return __( 'Off', 'action-steward' );
	}
};
?>
<section class="wpcc-cds-card" style="max-width:760px;margin:0 0 22px;" aria-labelledby="wpcc-bai-tools-h">
	<h2 id="wpcc-bai-tools-h" style="margin:0 0 4px;font-size:15px;"><?php esc_html_e( 'Built-in AI tools', 'action-steward' ); ?></h2>
	<p class="description" style="margin:0;">
		<?php esc_html_e( 'Turn on the AI tools you want to use — each appears as a tab here once it’s on. Generation runs on the provider you select as the default (Anthropic or an OpenAI-compatible provider).', 'action-steward' ); ?>
	</p>

	<?php
	/*
	 * The notice slot is always present, whether or not there is a notice.
	 *
	 * Toggling a tool posts the form and re-renders the page. On the way back the card
	 * grew a confirmation banner it did not have before, which pushed the whole tool list
	 * — and everything below it — down by the height of that banner. The button you had
	 * just pressed was no longer under your cursor, and the next tool's button had slid
	 * into roughly where it used to be, which is the worst possible way for a list of
	 * on/off switches to move. Reserving the space means the banner appears IN it rather
	 * than in front of it, and nothing below shifts.
	 */
	$wpcc_bai_has_notice = ! empty( $wpcc_bai_notice );
	?>
	<div class="wpcc-bai-noticeslot" role="status" aria-live="polite">
		<?php if ( $wpcc_bai_has_notice ) : ?>
			<div class="wpcc-cds-notice wpcc-cds-notice--<?php echo esc_attr( 'warning' === $wpcc_bai_notice['type'] ? 'warning' : 'success' ); ?>">
				<p><?php echo esc_html( $wpcc_bai_notice['message'] ); ?></p>
			</div>
		<?php endif; ?>
	</div>

	<style>
	/*
	 * Height of one notice line, reserved whether or not a notice is showing. Presentation
	 * only, scoped to this card. min-height rather than a fixed height so a message that
	 * wraps to two lines is never clipped — it grows in the rare case instead of the
	 * common one shifting every time.
	 */
	.wpcc-bai-noticeslot { min-height: 46px; margin: 12px 0 0; }
	.wpcc-bai-noticeslot > .wpcc-cds-notice { margin: 0; }
	</style>
	<ul style="list-style:none;margin:14px 0 0;padding:0;display:grid;gap:10px;">
		<?php foreach ( BuiltinAiSettings::tools() as $wpcc_tool_key => $wpcc_tool ) :
			$wpcc_status  = BuiltinAiSettings::status( $wpcc_tool_key );
			$wpcc_is_on   = in_array( $wpcc_status, [ 'enabled', 'requires_provider', 'enabled_by_config' ], true );
			$wpcc_locked  = in_array( $wpcc_status, [ 'enabled_by_config', 'disabled_by_config' ], true );
			$wpcc_warn    = 'requires_provider' === $wpcc_status;
			?>
			<li id="wpcc-bai-tool-<?php echo esc_attr( $wpcc_tool_key ); ?>" style="display:flex;align-items:center;justify-content:space-between;gap:14px;padding:11px 13px;background:var(--wpcc-surface-sunken);border-radius:var(--wpcc-radius-sm);">
				<span style="display:flex;align-items:center;gap:10px;">
					<span class="dashicons <?php echo $wpcc_is_on ? ( $wpcc_warn ? 'dashicons-warning' : 'dashicons-yes-alt' ) : 'dashicons-marker'; ?>" aria-hidden="true" style="color:<?php echo $wpcc_is_on ? ( $wpcc_warn ? 'var(--wpcc-state-warning-fg)' : 'var(--wpcc-state-success-fg)' ) : 'var(--wpcc-gray-500)'; ?>;"></span>
					<span style="display:flex;flex-direction:column;">
						<strong><?php echo esc_html( $wpcc_tool['label'] ); ?></strong>
						<span class="description" style="font-size:12px;"><?php echo esc_html( $wpcc_bai_status_text( $wpcc_status ) ); ?></span>
					</span>
				</span>
				<?php if ( $wpcc_locked ) : ?>
					<?php
					/*
					 * "Locked" read as a permission refusal — as though the plugin were
					 * withholding something. The truth is duller and more useful: a
					 * developer set this in wp-config or a filter, so this particular
					 * switch is not the thing that controls it. Say that instead.
					 */
					?>
					<span class="description" style="white-space:nowrap;" title="<?php esc_attr_e( 'A constant or filter in your site’s code controls this tool, so it cannot be changed from here.', 'action-steward' ); ?>"><span class="dashicons dashicons-lock" aria-hidden="true"></span> <?php esc_html_e( 'Set in code', 'action-steward' ); ?></span>
				<?php else : ?>
					<?php
					/*
					 * The form posts back to this row's own anchor, so the browser
					 * restores the scroll position to the switch that was just used
					 * rather than dropping the reader at the top of a long settings page.
					 * `autofocus` on the tool that changed puts the keyboard back where
					 * it was too — the same switch, now reading the opposite label.
					 */
					$wpcc_bai_changed = ( $wpcc_bai_notice && isset( $_POST['wpcc_builtin_ai_tool'] ) && sanitize_key( wp_unslash( $_POST['wpcc_builtin_ai_tool'] ) ) === $wpcc_tool_key ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- display-only; the state change was already nonce-verified in handle_post().
					?>
					<form method="post" action="#wpcc-bai-tool-<?php echo esc_attr( $wpcc_tool_key ); ?>" style="margin:0;">
						<?php wp_nonce_field( BuiltinAiSettings::NONCE ); ?>
						<input type="hidden" name="wpcc_builtin_ai_tool" value="<?php echo esc_attr( $wpcc_tool_key ); ?>">
						<input type="hidden" name="wpcc_builtin_ai_state" value="<?php echo $wpcc_is_on ? '0' : '1'; ?>">
						<button type="submit" class="button <?php echo $wpcc_is_on ? '' : 'button-primary'; ?>"<?php echo $wpcc_bai_changed ? ' autofocus' : ''; ?>>
							<?php echo $wpcc_is_on ? esc_html__( 'Turn off', 'action-steward' ) : esc_html__( 'Turn on', 'action-steward' ); ?>
						</button>
					</form>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
</section>
