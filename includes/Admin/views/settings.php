<?php
/**
 * Settings view.
 *
 * STEP 107.4 — API token management (create / revoke / delete + per-token
 * capabilities) moved to the dedicated "Tokens & Capabilities" manager
 * (page `wpcc-tokens`). This page now owns only Security Mode and the read-only
 * AI-agent connection reference. No AuthTokens calls remain here; old deep-links
 * into the former token section redirect to the new manager
 * (AdminMenu::redirect_legacy_tokens).
 */

defined( 'ABSPATH' ) || exit;

use WPCommandCenter\Operations\SecurityModeManager;

$notice = null;

if ( isset( $_POST['wpcc_action'] ) ) {
	check_admin_referer( 'wpcc_settings' );

	$action = sanitize_text_field( wp_unslash( $_POST['wpcc_action'] ) );

	if ( 'set_security_mode' === $action ) {
		$mode = sanitize_key( wp_unslash( $_POST['wpcc_security_mode'] ?? '' ) );
		if ( in_array( $mode, SecurityModeManager::MODES, true ) ) {
			$previous = SecurityModeManager::current();
			update_option( 'wpcc_security_mode', $mode );
			// PROGRAM-5A — record every mode change (no secret). Switching into the
			// self-approving Developer mode is logged with an explicit risk flag.
			if ( $previous !== $mode ) {
				( new \WPCommandCenter\Security\AuditLog() )->record( 'security.mode.changed', [
					'from'         => $previous,
					'to'           => $mode,
					'self_approve' => ( SecurityModeManager::MODE_DEVELOPER === $mode ),
					'actor'        => 'admin_ui',
				] );
			}
			$notice = [
				'type'    => 'success',
				'message' => sprintf(
					/* translators: %s: security mode label */
					__( 'Security mode updated to %s.', 'wp-command-center' ),
					SecurityModeManager::label()
				),
			];
		} else {
			$notice = [ 'type' => 'error', 'message' => __( 'Invalid security mode.', 'wp-command-center' ) ];
		}
	}
}

$current_mode = SecurityModeManager::current();
$rest_base    = rest_url( 'wp-command-center/v1' );
$tokens_url   = admin_url( 'admin.php?page=wpcc-tokens' );

// Read-only scope labels for the connection reference. Kept local so this view
// no longer depends on AuthTokens (STEP 107.4 migration).
$scope_label = static function ( string $scope ): string {
	return 'full' === $scope
		? __( 'Full access', 'wp-command-center' )
		: __( 'Read-only', 'wp-command-center' );
};

$endpoints = [
	[ 'GET', '/site-intelligence', 'read_only', __( 'Site Intelligence scan (WordPress, PHP, theme, plugins, cache, server, debug status).', 'wp-command-center' ) ],
	[ 'GET', '/diagnostics?type=performance|security|woocommerce', 'read_only', __( 'Diagnostics checks for the given category.', 'wp-command-center' ) ],
	[ 'GET', '/diagnostics/debug-log?lines=200', 'read_only', __( 'Tail of wp-content/debug.log.', 'wp-command-center' ) ],
	[ 'GET', '/files?path=…', 'read_only', __( 'List a directory under themes/plugins/mu-plugins.', 'wp-command-center' ) ],
	[ 'GET', '/files/content?path=…', 'read_only', __( 'Read a file\'s contents.', 'wp-command-center' ) ],
	[ 'GET', '/search?q=…&path=…', 'read_only', __( 'Search code across allowed directories.', 'wp-command-center' ) ],
	[ 'GET', '/patches', 'read_only', __( 'List all patches.', 'wp-command-center' ) ],
	[ 'GET', '/patches/{id}', 'read_only', __( 'Get a single patch record (files, diff, status).', 'wp-command-center' ) ],
	[ 'POST', '/patches', 'full', __( 'Create a new patch. Body: { files: [{path, modified}], explanation, risk_level, source }.', 'wp-command-center' ) ],
	[ 'POST', '/patches/{id}/approve', 'full', __( 'Approve a pending patch.', 'wp-command-center' ) ],
	[ 'POST', '/patches/{id}/reject', 'full', __( 'Reject a pending or approved patch.', 'wp-command-center' ) ],
	[ 'POST', '/patches/{id}/apply', 'full', __( 'Apply an approved patch (auto-snapshots and verifies).', 'wp-command-center' ) ],
	[ 'POST', '/patches/{id}/rollback', 'full', __( 'Roll back an applied patch using its snapshot(s).', 'wp-command-center' ) ],
];
?>
<div class="wrap wpcc-wrap">
	<h1><?php esc_html_e( 'Settings', 'wp-command-center' ); ?></h1>
	<p><?php esc_html_e( 'Security mode and AI agent connection reference.', 'wp-command-center' ); ?></p>

	<?php if ( $notice ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?>"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Security Mode', 'wp-command-center' ); ?></h2>
	<p><?php esc_html_e( 'Controls whether AI agents can execute write operations immediately or must wait for administrator approval. Diagnostic and read-only operations are never gated, in any mode.', 'wp-command-center' ); ?></p>

	<?php if ( SecurityModeManager::MODE_DEVELOPER === $current_mode ) : ?>
		<div class="notice notice-warning inline" style="margin:0 0 14px;max-width:640px;">
			<p style="margin:.5em 0;">
				<strong><?php esc_html_e( 'You are in Developer mode.', 'wp-command-center' ); ?></strong>
				<?php esc_html_e( 'AI agents can change this site with no approval step. Before working on a real client site, switch to Client mode so every change waits for your review. Audit and undo stay on in all modes.', 'wp-command-center' ); ?>
			</p>
		</div>
	<?php else : ?>
		<div class="notice notice-success inline" style="margin:0 0 14px;max-width:640px;">
			<p style="margin:.5em 0;"><?php esc_html_e( 'A human-approval mode is active — recommended for client sites. Write operations wait for your review.', 'wp-command-center' ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post" id="wpcc-security-mode-form">
		<?php wp_nonce_field( 'wpcc_settings' ); ?>
		<input type="hidden" name="wpcc_action" value="set_security_mode" />
		<fieldset>
			<legend class="screen-reader-text"><?php esc_html_e( 'Security Mode', 'wp-command-center' ); ?></legend>

			<label style="display:block;margin-bottom:14px;padding:14px 16px;border:1px solid <?php echo $current_mode === SecurityModeManager::MODE_DEVELOPER ? '#2271b1' : '#ddd'; ?>;border-radius:4px;background:<?php echo $current_mode === SecurityModeManager::MODE_DEVELOPER ? '#f0f6fc' : '#fff'; ?>;cursor:pointer;max-width:640px;">
				<input type="radio" name="wpcc_security_mode" value="<?php echo esc_attr( SecurityModeManager::MODE_DEVELOPER ); ?>" <?php checked( $current_mode, SecurityModeManager::MODE_DEVELOPER ); ?> style="margin-top:2px;float:left;" />
				<span style="display:block;margin-left:24px;">
					<strong><?php esc_html_e( 'Developer Mode', 'wp-command-center' ); ?></strong>
					<span style="display:inline-block;margin-left:8px;padding:1px 8px;border-radius:3px;font-size:11px;font-weight:700;background:#fcf0f1;color:#d63638;vertical-align:middle;"><?php esc_html_e( 'NOT FOR CLIENT SITES', 'wp-command-center' ); ?></span>
					<span style="display:block;color:#50575e;margin-top:3px;font-size:13px;"><?php esc_html_e( 'No approval step: AI can change or delete things on this site immediately, with no review. Use only on your own development or staging site. (Audit trail and undo still work, but there is no gate to stop a change before it happens.)', 'wp-command-center' ); ?></span>
				</span>
			</label>

			<label style="display:block;margin-bottom:14px;padding:14px 16px;border:1px solid <?php echo $current_mode === SecurityModeManager::MODE_CLIENT ? '#2271b1' : '#ddd'; ?>;border-radius:4px;background:<?php echo $current_mode === SecurityModeManager::MODE_CLIENT ? '#f0f6fc' : '#fff'; ?>;cursor:pointer;max-width:640px;">
				<input type="radio" name="wpcc_security_mode" value="<?php echo esc_attr( SecurityModeManager::MODE_CLIENT ); ?>" <?php checked( $current_mode, SecurityModeManager::MODE_CLIENT ); ?> style="margin-top:2px;float:left;" />
				<span style="display:block;margin-left:24px;">
					<strong><?php esc_html_e( 'Client Mode', 'wp-command-center' ); ?></strong>
					<span style="display:inline-block;margin-left:8px;padding:1px 8px;border-radius:3px;font-size:11px;font-weight:700;background:#edfaef;color:#00a32a;vertical-align:middle;"><?php esc_html_e( 'RECOMMENDED', 'wp-command-center' ); ?></span>
					<span style="display:block;color:#50575e;margin-top:3px;font-size:13px;"><?php esc_html_e( 'Medium, high, and critical operations require administrator approval before running. Read-only and diagnostic operations always execute freely. Switch to this mode before handing a site to a client.', 'wp-command-center' ); ?></span>
				</span>
			</label>

			<label style="display:block;margin-bottom:14px;padding:14px 16px;border:1px solid <?php echo $current_mode === SecurityModeManager::MODE_ENTERPRISE ? '#2271b1' : '#ddd'; ?>;border-radius:4px;background:<?php echo $current_mode === SecurityModeManager::MODE_ENTERPRISE ? '#f0f6fc' : '#fff'; ?>;cursor:pointer;max-width:640px;">
				<input type="radio" name="wpcc_security_mode" value="<?php echo esc_attr( SecurityModeManager::MODE_ENTERPRISE ); ?>" <?php checked( $current_mode, SecurityModeManager::MODE_ENTERPRISE ); ?> style="margin-top:2px;float:left;" />
				<span style="display:block;margin-left:24px;">
					<strong><?php esc_html_e( 'Enterprise Mode', 'wp-command-center' ); ?></strong>
					<span style="display:block;color:#50575e;margin-top:3px;font-size:13px;"><?php esc_html_e( 'All non-diagnostic operations require administrator approval. Maximum human oversight for compliance environments or sites with strict change management requirements.', 'wp-command-center' ); ?></span>
				</span>
			</label>
		</fieldset>
		<?php submit_button( __( 'Save Security Mode', 'wp-command-center' ) ); ?>
	</form>
	<script>
	(function () {
		var form = document.getElementById( 'wpcc-security-mode-form' );
		if ( ! form ) { return; }
		var warn = <?php echo wp_json_encode( __( 'Developer mode lets AI change this site with no approval step. This is unsafe for a live client site. Switch to Developer mode anyway?', 'wp-command-center' ) ); ?>;
		var devValue = <?php echo wp_json_encode( SecurityModeManager::MODE_DEVELOPER ); ?>;
		form.addEventListener( 'submit', function ( e ) {
			var sel = form.querySelector( 'input[name="wpcc_security_mode"]:checked' );
			if ( sel && sel.value === devValue && ! window.confirm( warn ) ) {
				e.preventDefault();
			}
		} );
	})();
	</script>

	<h2><?php esc_html_e( 'API Tokens', 'wp-command-center' ); ?></h2>
	<p>
		<?php esc_html_e( 'API tokens and per-token capabilities are now managed in the Tokens & Capabilities screen.', 'wp-command-center' ); ?>
	</p>
	<p>
		<a class="button button-primary" href="<?php echo esc_url( $tokens_url ); ?>"><?php esc_html_e( 'Open Tokens & Capabilities', 'wp-command-center' ); ?></a>
	</p>

	<h2><?php esc_html_e( 'AI Agent Connections', 'wp-command-center' ); ?></h2>
	<p>
		<?php
		printf(
			/* translators: %s: REST API base URL */
			esc_html__( 'REST API base URL: %s', 'wp-command-center' ),
			'<code>' . esc_html( $rest_base ) . '</code>'
		);
		?>
	</p>
	<p><?php esc_html_e( 'Authenticate every request with a bearer token created on the Tokens & Capabilities screen:', 'wp-command-center' ); ?></p>
	<pre class="wpcc-file-viewer">curl -H "Authorization: Bearer wpcc_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" \
  <?php echo esc_html( trailingslashit( $rest_base ) . 'patches' ); ?></pre>

	<table class="widefat striped wpcc-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Method', 'wp-command-center' ); ?></th>
				<th><?php esc_html_e( 'Endpoint', 'wp-command-center' ); ?></th>
				<th><?php esc_html_e( 'Required Scope', 'wp-command-center' ); ?></th>
				<th><?php esc_html_e( 'Description', 'wp-command-center' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $endpoints as [ $method, $path, $scope, $description ] ) : ?>
			<tr>
				<td><code><?php echo esc_html( $method ); ?></code></td>
				<td><code><?php echo esc_html( $path ); ?></code></td>
				<td><?php echo esc_html( $scope_label( $scope ) ); ?></td>
				<td><?php echo esc_html( $description ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Access Control', 'wp-command-center' ); ?></h2>
	<ul class="ul-disc">
		<li><?php esc_html_e( 'All Command Center admin pages require the Administrator role (manage_options).', 'wp-command-center' ); ?></li>
		<li><?php esc_html_e( 'The REST API does not use WordPress login sessions — every request must carry a valid, non-expired API token.', 'wp-command-center' ); ?></li>
		<li><?php esc_html_e( 'Read-only tokens can inspect the site (Site Intelligence, Diagnostics, File Access, Code Search, Patches) but cannot change anything.', 'wp-command-center' ); ?></li>
		<li><?php esc_html_e( 'Full-access tokens can additionally create, approve, apply, and roll back patches — the same human-in-the-loop workflow as the Patches admin page.', 'wp-command-center' ); ?></li>
		<li><?php esc_html_e( 'Revoke a token immediately if it is lost or compromised; revoked tokens stop working on their next request.', 'wp-command-center' ); ?></li>
	</ul>

</div>
