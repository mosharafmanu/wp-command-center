<?php
/**
 * Shared assistant connection journey.
 *
 * Integration classes own transport, credentials and generated payloads. This partial
 * owns the presentation grammar: one numbered action row, one action area, and one
 * collapsed preview for every client.
 */

defined( 'ABSPATH' ) || exit;

$wpcc_connect_step = static function ( int $number, string $title, string $description = '' ): void {
	?>
	<div class="wpcc-connect-step" data-step="<?php echo esc_attr( (string) $number ); ?>" role="listitem">
		<span class="wpcc-connect-step__number" aria-hidden="true"><?php echo esc_html( (string) $number ); ?></span>
		<div class="wpcc-connect-step__body">
			<h3 class="wpcc-connect-step__title"><?php echo esc_html( $title ); ?></h3>
			<?php if ( '' !== $description ) : ?>
				<p class="wpcc-connect-step__description"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
	<?php
};

$wpcc_connect_step_end = static function (): void {
	?>
		</div>
	</div>
	<?php
};

$wpcc_payload_preview = static function ( string $label, string $id, string $payload, bool $token_slot = false, bool $requires_token = false, bool $token_ready = true ): void {
	?>
	<details class="wpcc-action-preview"<?php echo $requires_token ? ' data-wpcc-requires-token-preview' : ''; ?><?php echo $requires_token && ! $token_ready ? ' hidden' : ''; ?>>
		<summary><?php echo esc_html( $label ); ?></summary>
		<div class="wpcc-action-preview__body">
			<pre class="wpcc-ai-config wpcc-primary-config" id="<?php echo esc_attr( $id ); ?>"<?php echo $token_slot ? ' data-wpcc-token-slot' : ''; ?>><?php echo esc_html( $payload ); ?></pre>
		</div>
	</details>
	<?php
};

$wpcc_token_gate_control = static function ( bool $requires_token, bool $token_ready ): void {
	if ( ! $requires_token ) {
		return;
	}
	echo ' data-wpcc-requires-token-control';
	if ( ! $token_ready ) {
		echo ' disabled aria-disabled="true"';
	}
};

$wpcc_step_number = 0;
$wpcc_inline_token_waiting = $wpcc_setup_requires_token && ! $wpcc_token_ready;
?>

<div class="wpcc-connect-system" data-client="<?php echo esc_attr( $wpcc_selected_client ); ?>">
	<p class="wpcc-connect-system__intro"><strong><?php esc_html_e( 'Recommended setup', 'siteradian' ); ?></strong><span><?php esc_html_e( 'Follow these actions in order.', 'siteradian' ); ?></span></p>
	<?php if ( 'codex' === $wpcc_selected_client ) : ?>
		<p class="wpcc-connect-terminal-warning" role="note">
			<span class="dashicons dashicons-warning" aria-hidden="true"></span>
			<span><strong><?php esc_html_e( 'Important: Keep this terminal open.', 'siteradian' ); ?></strong> <?php esc_html_e( 'Run Steps 1–3 in this same terminal window. Do not open a different Terminal/Warp tab for this step.', 'siteradian' ); ?></span>
		</p>
	<?php endif; ?>

	<?php if ( ! $wpcc_sel_uses_env && 'prompt' !== $wpcc_sel_cred_mode ) : ?>
		<?php if ( $wpcc_new_token ) : ?>
			<input type="hidden" id="wpcc-token-fill" value="<?php echo esc_attr( (string) $wpcc_new_token ); ?>" />
			<div class="wpcc-connect-credential" role="status">
				<span class="dashicons dashicons-lock" aria-hidden="true"></span>
				<div><strong><?php esc_html_e( 'Access token ready', 'siteradian' ); ?></strong><p><?php esc_html_e( 'The copy action below is already filled with the one-time token shown above. SiteRadian AI stores only its hash.', 'siteradian' ); ?></p></div>
			</div>
		<?php else : ?>
			<div class="wpcc-connect-credential">
				<span class="dashicons dashicons-lock" aria-hidden="true"></span>
				<div class="wpcc-connect-credential__content">
					<label for="wpcc-token-fill"><?php esc_html_e( 'Paste your saved access token', 'siteradian' ); ?></label>
					<input type="password" id="wpcc-token-fill" class="regular-text" placeholder="wpcc_..." autocomplete="off" spellcheck="false" />
					<p><?php esc_html_e( 'It fills the copy action in this browser only. SiteRadian AI cannot reconstruct an existing token; create a new one if you did not save it.', 'siteradian' ); ?></p>
				</div>
			</div>
		<?php endif; ?>
	<?php elseif ( 'prompt' === $wpcc_sel_cred_mode ) : ?>
		<div class="wpcc-connect-credential">
			<span class="dashicons dashicons-lock" aria-hidden="true"></span>
			<div><strong><?php esc_html_e( 'Your token stays in VS Code', 'siteradian' ); ?></strong><p><?php echo esc_html( $wpcc_new_token ? __( 'VS Code asks for the SiteRadian AI token when the server starts and stores it securely. After copying the setup, use the final Copy token button before you start the server.', 'siteradian' ) : __( 'VS Code asks for the SiteRadian AI token when the server starts and stores it securely. Have your saved token ready, and do not add OAuth or client-registration details.', 'siteradian' ) ); ?></p></div>
		</div>
	<?php endif; ?>

	<?php if ( $wpcc_setup_requires_token ) : ?>
		<p class="wpcc-connect-note wpcc-token-needed" data-wpcc-token-needed<?php echo $wpcc_token_ready ? ' hidden' : ''; ?>><strong><?php esc_html_e( 'Create an access token first.', 'siteradian' ); ?></strong> <?php esc_html_e( 'If you already saved one, paste it above. Setup copy actions unlock only when the complete token is available in this browser.', 'siteradian' ); ?></p>
	<?php endif; ?>

	<div class="wpcc-connect-steps" role="list">
		<?php if ( 'install_link' === $wpcc_setup_kind ) : ?>
			<?php $wpcc_connect_step( ++$wpcc_step_number, __( 'Open Cursor’s install review', 'siteradian' ), __( 'Cursor will show the server name and this WordPress address before anything is saved.', 'siteradian' ) ); ?>
				<div class="wpcc-connect-step__actions">
					<button type="button" class="button button-primary" id="wpcc-cursor-install" data-mcp-url="<?php echo esc_attr( $wpcc_cfg_mcp_url ); ?>"<?php $wpcc_token_gate_control( $wpcc_setup_requires_token, $wpcc_token_ready ); ?>><?php esc_html_e( 'Add to Cursor', 'siteradian' ); ?></button>
				</div>
				<p class="wpcc-connect-note"><?php esc_html_e( 'The private install link is created only when you click. Do not copy or share it.', 'siteradian' ); ?></p>
			<?php $wpcc_connect_step_end(); ?>

			<?php $wpcc_connect_step( ++$wpcc_step_number, __( 'Confirm and reload Cursor', 'siteradian' ), __( 'Choose Install, then open Customize → MCPs and confirm wp-command-center is enabled. If needed, run Developer: Reload Window.', 'siteradian' ) ); ?>
			<?php $wpcc_connect_step_end(); ?>

		<?php elseif ( 'continue_config' === $wpcc_setup_kind ) : ?>
			<?php $wpcc_connect_step( ++$wpcc_step_number, __( 'Open Continue’s Local Config', 'siteradian' ), __( 'In the Continue sidebar in VS Code, open the Agent selector and choose the gear beside Local Config. This opens ~/.continue/config.yaml.', 'siteradian' ) ); ?>
			<?php $wpcc_connect_step_end(); ?>

			<?php $wpcc_connect_step( ++$wpcc_step_number, __( 'Add SiteRadian AI', 'siteradian' ), __( 'Keep the existing file. Choose the option that matches your Local Config; both preserve your existing models and settings.', 'siteradian' ) ); ?>
				<div class="wpcc-setup-choice-grid">
					<div class="wpcc-setup-choice">
						<strong><?php esc_html_e( 'No MCP servers yet', 'siteradian' ); ?></strong>
						<p><?php esc_html_e( 'If mcpServers is missing, add this complete top-level MCP block.', 'siteradian' ); ?></p>
						<div class="wpcc-connect-step__actions">
							<button type="button" class="button wpcc-copy-btn" data-copy-target="wpcc-continue-complete"<?php $wpcc_token_gate_control( true, $wpcc_token_ready ); ?>><?php esc_html_e( 'Copy complete MCP block', 'siteradian' ); ?></button>
						</div>
						<?php $wpcc_payload_preview( __( 'Preview complete MCP block', 'siteradian' ), 'wpcc-continue-complete', $wpcc_config_json, true, true, $wpcc_token_ready ); ?>
					</div>
					<div class="wpcc-setup-choice">
						<strong><?php esc_html_e( 'Already have mcpServers', 'siteradian' ); ?></strong>
						<p><?php esc_html_e( 'Add this as another indented list item beneath the existing mcpServers key. Do not add a second key.', 'siteradian' ); ?></p>
						<div class="wpcc-connect-step__actions">
							<button type="button" class="button wpcc-copy-btn" data-copy-target="wpcc-continue-entry"<?php $wpcc_token_gate_control( true, $wpcc_token_ready ); ?>><?php esc_html_e( 'Copy SiteRadian AI entry only', 'siteradian' ); ?></button>
						</div>
						<?php $wpcc_payload_preview( __( 'Preview SiteRadian AI entry', 'siteradian' ), 'wpcc-continue-entry', $wpcc_primary_config, true, true, $wpcc_token_ready ); ?>
					</div>
				</div>
			<?php $wpcc_connect_step_end(); ?>

			<?php $wpcc_connect_step( ++$wpcc_step_number, __( 'Save and return to Continue', 'siteradian' ), __( 'Save config.yaml. Continue refreshes automatically; use Agent mode with a model that supports tools.', 'siteradian' ) ); ?>
			<?php $wpcc_connect_step_end(); ?>

		<?php elseif ( 'muse_code' === $wpcc_selected_client ) : ?>
			<?php $wpcc_connect_step( ++$wpcc_step_number, __( 'Prepare Muse Code settings', 'siteradian' ), __( 'Muse Code reads ~/.config/muse/settings.json. If it does not exist, the safe command below creates the folder and an empty settings file without replacing an existing file.', 'siteradian' ) ); ?>
				<div class="wpcc-connect-step__actions">
					<button type="button" class="button wpcc-copy-btn" data-copy-target="wpcc-muse-prepare"><?php esc_html_e( 'Copy create-file command', 'siteradian' ); ?></button>
				</div>
				<?php $wpcc_payload_preview( __( 'Show create-file command', 'siteradian' ), 'wpcc-muse-prepare', $wpcc_prepare_config_cmd ); ?>
			<?php $wpcc_connect_step_end(); ?>

			<?php $wpcc_connect_step( ++$wpcc_step_number, __( 'Add SiteRadian AI', 'siteradian' ), __( 'Choose the option that matches your settings file. Never replace existing Muse settings.', 'siteradian' ) ); ?>
				<div class="wpcc-setup-choice-grid">
					<div class="wpcc-setup-choice">
						<strong><?php esc_html_e( 'New or empty settings file', 'siteradian' ); ?></strong>
						<p><?php esc_html_e( 'Use this complete structure when the file is new or contains only an empty object.', 'siteradian' ); ?></p>
						<div class="wpcc-connect-step__actions">
							<button type="button" class="button wpcc-copy-btn" data-copy-target="wpcc-muse-complete"<?php $wpcc_token_gate_control( true, $wpcc_token_ready ); ?>><?php esc_html_e( 'Copy complete Muse settings', 'siteradian' ); ?></button>
						</div>
						<?php $wpcc_payload_preview( __( 'Preview complete settings', 'siteradian' ), 'wpcc-muse-complete', $wpcc_config_json, true, true, $wpcc_token_ready ); ?>
					</div>
					<div class="wpcc-setup-choice">
						<strong><?php esc_html_e( 'Existing settings file', 'siteradian' ); ?></strong>
						<p><?php esc_html_e( 'Add only this entry inside the existing mcp_servers object. Keep schema_version and every other setting.', 'siteradian' ); ?></p>
						<div class="wpcc-connect-step__actions">
							<button type="button" class="button wpcc-copy-btn" data-copy-target="wpcc-muse-entry"<?php $wpcc_token_gate_control( true, $wpcc_token_ready ); ?>><?php esc_html_e( 'Copy SiteRadian AI entry only', 'siteradian' ); ?></button>
						</div>
						<?php $wpcc_payload_preview( __( 'Preview SiteRadian AI entry', 'siteradian' ), 'wpcc-muse-entry', $wpcc_primary_config, true, true, $wpcc_token_ready ); ?>
					</div>
				</div>
			<?php $wpcc_connect_step_end(); ?>

			<?php $wpcc_connect_step( ++$wpcc_step_number, __( 'Save and restart Muse Code', 'siteradian' ), __( 'Save settings.json, exit any running Muse Code session, and start muse again.', 'siteradian' ) ); ?>
			<?php $wpcc_connect_step_end(); ?>

		<?php elseif ( in_array( $wpcc_setup_kind, [ 'file', 'vscode_config' ], true ) && ! $wpcc_sel_uses_env ) : ?>
			<?php
			if ( 'claude' === $wpcc_selected_client ) {
				$wpcc_open_title = __( 'Open Claude Desktop settings', 'siteradian' );
				$wpcc_open_desc  = __( 'Open Settings → Developer → Edit Config. Claude opens claude_desktop_config.json for you.', 'siteradian' );
			} elseif ( 'vscode_config' === $wpcc_setup_kind ) {
				$wpcc_open_title = __( 'Open Copilot’s MCP configuration', 'siteradian' );
				$wpcc_open_desc  = __( 'In VS Code, open the Command Palette and run MCP: Open User Configuration.', 'siteradian' );
			} else {
				/* translators: %s: selected assistant or coding client name. */
				$wpcc_open_title = sprintf( __( 'Open %s’s configuration', 'siteradian' ), $wpcc_current_client['name'] );
				$wpcc_open_desc  = __( 'Open the configuration file listed under Advanced setup below.', 'siteradian' );
			}
			$wpcc_connect_step( ++$wpcc_step_number, $wpcc_open_title, $wpcc_open_desc );
			$wpcc_connect_step_end();

			$wpcc_file_copy_label = 'claude' === $wpcc_selected_client
				? __( 'Copy SiteRadian AI entry', 'siteradian' )
				: ( 'vscode_config' === $wpcc_setup_kind ? __( 'Copy VS Code setup', 'siteradian' ) : __( 'Copy SiteRadian AI setup', 'siteradian' ) );
			$wpcc_file_desc = 'claude' === $wpcc_selected_client
				? __( 'Paste this entry inside the existing mcpServers braces and keep every other entry. Use the whole-file example under Advanced only when the file is empty.', 'siteradian' )
				: ( 'vscode_config' === $wpcc_setup_kind
					? __( 'Use this block for an empty user mcp.json. If it already contains settings, use the merge instructions under Advanced and preserve every server and input.', 'siteradian' )
					: __( 'Use this block only for an empty file. If the file already has settings, use the entry-only merge instructions under Advanced.', 'siteradian' ) );
			$wpcc_connect_step( ++$wpcc_step_number, __( 'Add SiteRadian AI', 'siteradian' ), $wpcc_file_desc );
			?>
				<div class="wpcc-connect-step__actions">
					<button type="button" class="button button-primary wpcc-copy-btn" data-copy-target="wpcc-primary-config"<?php $wpcc_token_gate_control( $wpcc_setup_requires_token, $wpcc_token_ready ); ?>><?php echo esc_html( $wpcc_file_copy_label ); ?></button>
				</div>
				<?php $wpcc_payload_preview( __( 'Preview what will be copied', 'siteradian' ), 'wpcc-primary-config', $wpcc_primary_config, 'prompt' !== $wpcc_sel_cred_mode, $wpcc_setup_requires_token, $wpcc_token_ready ); ?>
			<?php $wpcc_connect_step_end(); ?>

			<?php
			$wpcc_finish_desc = 'vscode_config' === $wpcc_setup_kind
				? ( $wpcc_new_token
					? __( 'Save the file. Copy your token again here because copying the setup replaced your clipboard. Then run MCP: List Servers, start wp-command-center, approve trust, and paste the SiteRadian AI token when VS Code asks.', 'siteradian' )
					: __( 'Save the file, have your saved SiteRadian AI token ready, then run MCP: List Servers, start wp-command-center, approve trust, and paste the SiteRadian AI token when VS Code asks.', 'siteradian' ) )
					: __( 'Save the file, fully quit the app, and open it again so it loads the connection.', 'siteradian' );
			/* translators: %s: selected assistant or coding client name. */
			$wpcc_connect_step( ++$wpcc_step_number, 'vscode_config' === $wpcc_setup_kind ? __( 'Save and start the server', 'siteradian' ) : sprintf( __( 'Save and restart %s', 'siteradian' ), $wpcc_current_client['name'] ), $wpcc_finish_desc );
			?>
				<?php if ( 'vscode_config' === $wpcc_setup_kind && $wpcc_new_token ) : ?>
					<div class="wpcc-connect-step__actions">
						<button type="button" class="button button-primary wpcc-copy-btn" data-copy-target="wpcc-new-token"><?php esc_html_e( 'Copy token for VS Code', 'siteradian' ); ?></button>
					</div>
				<?php endif; ?>
			<?php $wpcc_connect_step_end(); ?>

		<?php elseif ( $wpcc_sel_uses_env ) : ?>
			<?php $wpcc_connect_step( ++$wpcc_step_number, 'codex' === $wpcc_selected_client ? __( 'Make the token available in this terminal', 'siteradian' ) : __( 'Make the token available to ChatGPT Desktop', 'siteradian' ), 'codex' === $wpcc_selected_client ? __( 'Copy the command for your computer and run it here. Keep this terminal open for Steps 2 and 3.', 'siteradian' ) : __( 'Copy the command for your computer and run it in a private terminal. Fully quit ChatGPT Desktop before reopening it.', 'siteradian' ) ); ?>
				<?php if ( empty( $wpcc_cred_cmds ) ) : ?>
					<p class="wpcc-connect-note"><?php esc_html_e( 'Create a new access token to get a copy-ready credential command. Existing tokens cannot be shown again.', 'siteradian' ); ?></p>
				<?php else : ?>
				<div class="wpcc-connect-step__actions wpcc-connect-step__actions--os">
					<?php foreach ( $wpcc_cred_cmds as $wpcc_os => $wpcc_cmd ) : ?>
						<?php /* translators: %s: operating system name. */ ?>
						<button type="button" class="button wpcc-copy-btn" data-copy-target="wpcc-credential-<?php echo esc_attr( $wpcc_os ); ?>"><?php printf( esc_html__( 'Copy %s command', 'siteradian' ), esc_html( $wpcc_os_labels[ $wpcc_os ] ?? ucfirst( $wpcc_os ) ) ); ?></button>
						<?php endforeach; ?>
					</div>
					<details class="wpcc-action-preview">
						<summary><?php esc_html_e( 'Show credential commands', 'siteradian' ); ?></summary>
						<div class="wpcc-action-preview__body">
							<?php foreach ( $wpcc_cred_cmds as $wpcc_os => $wpcc_cmd ) : ?>
								<strong class="wpcc-action-preview__label"><?php echo esc_html( $wpcc_os_labels[ $wpcc_os ] ?? ucfirst( $wpcc_os ) ); ?></strong>
								<pre class="wpcc-ai-config" id="wpcc-credential-<?php echo esc_attr( $wpcc_os ); ?>" data-wpcc-credential-command="<?php echo esc_attr( $wpcc_os ); ?>"><?php echo esc_html( $wpcc_cmd ); ?></pre>
							<?php endforeach; ?>
						</div>
					</details>
				<?php endif; ?>
				<details class="wpcc-connect-why">
					<summary><?php esc_html_e( 'Why this step?', 'siteradian' ); ?></summary>
					<div>
						<?php if ( 'codex' === $wpcc_selected_client ) : ?>
							<p><?php esc_html_e( 'WPCC_TOKEN keeps the raw token out of ~/.codex/config.toml. On macOS and Linux, every Codex setup action must run from this terminal; a new terminal needs this action again. Never replace the variable name with the token.', 'siteradian' ); ?></p>
							<p><?php esc_html_e( 'Optional presence check:', 'siteradian' ); ?> <code>printenv WPCC_TOKEN &gt;/dev/null</code></p>
						<?php else : ?>
							<p><?php esc_html_e( 'The Codex setting stores the variable name WPCC_TOKEN, not the token itself. Dock- or Finder-launched apps need the GUI-session command above and a full restart.', 'siteradian' ); ?></p>
						<?php endif; ?>
					</div>
				</details>
			<?php $wpcc_connect_step_end(); ?>

			<?php $wpcc_connect_step( ++$wpcc_step_number, __( 'Add SiteRadian AI', 'siteradian' ), __( 'Run this registration command. It stores the server address and credential variable name, not the raw token.', 'siteradian' ) ); ?>
				<div class="wpcc-connect-step__actions">
					<button type="button" class="button button-primary wpcc-copy-btn" id="wpcc-setup-command-copy" data-copy-target="wpcc-setup-command"><?php esc_html_e( 'Copy setup command', 'siteradian' ); ?></button>
				</div>
				<?php $wpcc_payload_preview( __( 'Show command', 'siteradian' ), 'wpcc-setup-command', $wpcc_setup_cmd, true ); ?>
			<?php $wpcc_connect_step_end(); ?>

			<?php if ( 'codex' === $wpcc_selected_client ) : ?>
				<?php $wpcc_connect_step( ++$wpcc_step_number, __( 'Start Codex in this same terminal', 'siteradian' ), __( 'Run the command here—not in a different Terminal, Warp, or PowerShell window—so Codex receives WPCC_TOKEN and can request approval when needed.', 'siteradian' ) ); ?>
					<div class="wpcc-connect-step__actions">
						<button type="button" class="button button-primary wpcc-copy-btn" data-copy-target="wpcc-codex-launch"><?php esc_html_e( 'Copy start command', 'siteradian' ); ?></button>
					</div>
					<?php $wpcc_payload_preview( __( 'Show command', 'siteradian' ), 'wpcc-codex-launch', 'codex --ask-for-approval on-request' ); ?>
					<details class="wpcc-connect-why"><summary><?php esc_html_e( 'Why this command?', 'siteradian' ); ?></summary><div><p><?php esc_html_e( 'It lets Codex show a client-side approval when one is needed. Do not use bypass flags or an approval policy of never.', 'siteradian' ); ?></p></div></details>
				<?php $wpcc_connect_step_end(); ?>
			<?php else : ?>
				<?php $wpcc_connect_step( ++$wpcc_step_number, __( 'Open Codex in ChatGPT Desktop', 'siteradian' ), __( 'Fully quit and reopen the ChatGPT desktop app, then switch from ChatGPT to Codex.', 'siteradian' ) ); ?>
				<?php $wpcc_connect_step_end(); ?>
			<?php endif; ?>

		<?php elseif ( '' !== $wpcc_setup_template ) : ?>
			<?php
			/* translators: %s: selected assistant or coding client name. */
			$wpcc_connect_step( ++$wpcc_step_number, __( 'Open Terminal', 'siteradian' ), sprintf( __( 'Open a private terminal for the %s setup command.', 'siteradian' ), $wpcc_current_client['name'] ) );
			?>
			<?php $wpcc_connect_step_end(); ?>

			<?php $wpcc_connect_step( ++$wpcc_step_number, __( 'Add SiteRadian AI', 'siteradian' ), __( 'Run the generated command. It registers this WordPress site without making you edit a configuration file.', 'siteradian' ) ); ?>
				<p class="wpcc-connect-note"><strong><?php esc_html_e( 'Keep this private.', 'siteradian' ); ?></strong> <?php esc_html_e( 'The setup command contains your access token. Keep it out of shared history, screenshots, chats and logs.', 'siteradian' ); ?></p>
				<div class="wpcc-connect-step__actions">
					<button type="button" class="button button-primary wpcc-copy-btn" id="wpcc-setup-command-copy" data-copy-target="wpcc-setup-command"<?php $wpcc_token_gate_control( $wpcc_setup_requires_token, $wpcc_token_ready ); ?>><?php esc_html_e( 'Copy setup command', 'siteradian' ); ?></button>
				</div>
				<details class="wpcc-action-preview" id="wpcc-setup-command-preview"<?php echo $wpcc_setup_requires_token ? ' data-wpcc-requires-token-preview' : ''; ?><?php echo $wpcc_inline_token_waiting ? ' hidden' : ''; ?>>
					<summary><?php esc_html_e( 'Show command', 'siteradian' ); ?></summary>
					<div class="wpcc-action-preview__body"><pre class="wpcc-ai-config" id="wpcc-setup-command" data-wpcc-token-slot data-wpcc-token-template="<?php echo esc_attr( $wpcc_setup_template ); ?>"><?php echo esc_html( $wpcc_setup_cmd ); ?></pre></div>
				</details>
				<?php if ( 'antigravity' === $wpcc_selected_client ) : ?>
					<details class="wpcc-connect-why"><summary><?php esc_html_e( 'Optional: protect the local credential file', 'siteradian' ); ?></summary><div><p><?php esc_html_e( 'On macOS/Linux, restrict the file to your account before setup if it already exists.', 'siteradian' ); ?></p><pre class="wpcc-ai-config">umask 077
mkdir -p ~/.gemini/config
if [ -f ~/.gemini/config/mcp_config.json ]; then chmod 600 ~/.gemini/config/mcp_config.json; fi</pre></div></details>
				<?php endif; ?>
			<?php $wpcc_connect_step_end(); ?>

			<?php
			/* translators: %s: selected assistant or coding client name. */
			$wpcc_start_title = sprintf( __( 'Start %s', 'siteradian' ), $wpcc_current_client['name'] );
			/* translators: %s: selected assistant or coding client name. */
			$wpcc_start_desc = sprintf( __( 'Open %s so it loads the new wp-command-center registration.', 'siteradian' ), $wpcc_current_client['name'] );
			$wpcc_connect_step( ++$wpcc_step_number, $wpcc_start_title, $wpcc_start_desc );
			?>
			<?php $wpcc_connect_step_end(); ?>
		<?php endif; ?>
	</div>

	<?php $wpcc_client_notes = \WPCommandCenter\Integration\AIClientRegistry::post_setup_notes_for( $wpcc_selected_client ); ?>
	<?php if ( $wpcc_client_notes ) : ?>
		<details class="wpcc-connect-help">
			<summary><?php esc_html_e( 'Troubleshooting for this app', 'siteradian' ); ?></summary>
			<div><?php foreach ( $wpcc_client_notes as $wpcc_note ) : ?><p><?php echo esc_html( $wpcc_note ); ?></p><?php endforeach; ?></div>
		</details>
	<?php endif; ?>
</div>
