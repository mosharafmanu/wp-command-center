<?php
/** Shared verification finish for every assistant client. */

defined( 'ABSPATH' ) || exit;

if ( in_array( $wpcc_selected_client, [ 'codex', 'claude_code', 'antigravity', 'command_code' ], true ) ) {
	$wpcc_verify_first = __( 'Run /mcp in the app and confirm wp-command-center is loaded.', 'ai-command-center' );
} elseif ( 'cursor' === $wpcc_selected_client ) {
	$wpcc_verify_first = __( 'Open Cursor → Customize → MCPs and confirm wp-command-center is enabled.', 'ai-command-center' );
} elseif ( 'vscode' === $wpcc_selected_client ) {
	$wpcc_verify_first = __( 'Run MCP: List Servers in VS Code and confirm wp-command-center is running.', 'ai-command-center' );
} else {
	/* translators: %s: selected assistant or coding client name. */
	$wpcc_verify_first = sprintf( __( 'Open %s and confirm WP Command Center appears in its tools or connections.', 'ai-command-center' ), $wpcc_current_client['name'] );
}
?>

<div class="wpcc-verify-system" data-client="<?php echo esc_attr( $wpcc_selected_client ); ?>">
	<p class="wpcc-verify-system__lead"><?php echo esc_html( $wpcc_verify_first ); ?></p>
	<p class="wpcc-verify-system__instruction"><?php esc_html_e( 'Then send this read-only test:', 'ai-command-center' ); ?></p>
	<div class="wpcc-verify-prompt">
		<code id="wpcc-verification-prompt">Test my wp-command-center MCP connection.
Run system_info once.
Read-only only.</code>
		<button type="button" class="button button-primary wpcc-copy-btn" data-copy-target="wpcc-verification-prompt"><?php esc_html_e( 'Copy test prompt', 'ai-command-center' ); ?></button>
	</div>
	<div class="wpcc-verify-success" aria-label="<?php esc_attr_e( 'Success looks like', 'ai-command-center' ); ?>">
		<strong><?php esc_html_e( 'Success looks like', 'ai-command-center' ); ?></strong>
		<ul>
			<li><?php esc_html_e( 'WP Command Center responds.', 'ai-command-center' ); ?></li>
			<li><?php esc_html_e( 'system_info returns details from this WordPress site.', 'ai-command-center' ); ?></li>
			<li><?php esc_html_e( 'No site content or settings are changed.', 'ai-command-center' ); ?></li>
		</ul>
	</div>
</div>
