<?php
/** Shared verification finish for every assistant client. */

defined( 'ABSPATH' ) || exit;

if ( in_array( $wpcc_selected_client, [ 'codex', 'claude_code', 'antigravity', 'command_code' ], true ) ) {
	$wpcc_verify_first = sprintf( /* translators: %s: generated MCP server alias. */ __( 'Run /mcp in the app and confirm %s is loaded.', 'siteradian' ), $wpcc_sel_server_key );
} elseif ( 'cursor' === $wpcc_selected_client ) {
	$wpcc_verify_first = sprintf( /* translators: %s: generated MCP server alias. */ __( 'Open Cursor → Customize → MCPs and confirm %s is enabled.', 'siteradian' ), $wpcc_sel_server_key );
} elseif ( 'vscode' === $wpcc_selected_client ) {
	$wpcc_verify_first = sprintf( /* translators: %s: generated MCP server alias. */ __( 'Run MCP: List Servers in VS Code and confirm %s is running.', 'siteradian' ), $wpcc_sel_server_key );
} else {
	/* translators: %s: selected assistant or coding client name. */
	$wpcc_verify_first = sprintf( __( 'Open %s and confirm SiteRadian appears in its tools or connections.', 'siteradian' ), $wpcc_current_client['name'] );
}
?>

<div class="wpcc-verify-system" data-client="<?php echo esc_attr( $wpcc_selected_client ); ?>">
	<p class="wpcc-verify-system__lead"><?php echo esc_html( $wpcc_verify_first ); ?></p>
	<p class="wpcc-verify-system__instruction"><?php esc_html_e( 'Then send this read-only test:', 'siteradian' ); ?></p>
	<div class="wpcc-verify-prompt">
		<code id="wpcc-verification-prompt"><?php echo esc_html( sprintf( /* translators: %s: generated MCP server alias. */ __( 'Test my %s MCP connection.', 'siteradian' ), $wpcc_sel_server_key ) ); ?>
Run system_info once.
Read-only only.</code>
		<button type="button" class="button button-primary wpcc-copy-btn" data-copy-target="wpcc-verification-prompt"><?php esc_html_e( 'Copy test prompt', 'siteradian' ); ?></button>
	</div>
	<div class="wpcc-verify-success" aria-label="<?php esc_attr_e( 'Success looks like', 'siteradian' ); ?>">
		<strong><?php esc_html_e( 'Success looks like', 'siteradian' ); ?></strong>
		<ul>
			<li><?php esc_html_e( 'SiteRadian responds.', 'siteradian' ); ?></li>
			<li><?php esc_html_e( 'system_info returns details from this WordPress site.', 'siteradian' ); ?></li>
			<li><?php esc_html_e( 'No site content or settings are changed.', 'siteradian' ); ?></li>
		</ul>
	</div>
</div>
