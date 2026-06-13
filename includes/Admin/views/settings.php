<?php
defined( 'ABSPATH' ) || exit;

use WPCommandCenter\Security\AuthTokens;
use WPCommandCenter\Operations\SecurityModeManager;

$auth_tokens = new AuthTokens();
$notice      = null;
$new_token   = null;

if ( isset( $_POST['wpcc_action'] ) ) {
	check_admin_referer( 'wpcc_settings' );

	$action = sanitize_text_field( wp_unslash( $_POST['wpcc_action'] ) );

	if ( 'set_security_mode' === $action ) {
		$mode = sanitize_key( wp_unslash( $_POST['wpcc_security_mode'] ?? '' ) );
		if ( in_array( $mode, SecurityModeManager::MODES, true ) ) {
			update_option( 'wpcc_security_mode', $mode );
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
	} elseif ( 'create_token' === $action ) {
		$label   = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		$scope   = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : AuthTokens::SCOPE_READ_ONLY;
		$expires = isset( $_POST['expires'] ) ? sanitize_key( wp_unslash( $_POST['expires'] ) ) : 'never';

		$expires_at = match ( $expires ) {
			'30d' => time() + 30 * DAY_IN_SECONDS,
			'90d' => time() + 90 * DAY_IN_SECONDS,
			'1y'  => time() + YEAR_IN_SECONDS,
			default => null,
		};

		$result = $auth_tokens->create( $label, $scope, $expires_at, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			$notice = [ 'type' => 'error', 'message' => $result->get_error_message() ];
		} else {
			$new_token = $result['token'];
			$notice    = [ 'type' => 'success', 'message' => __( 'API token created. Copy it now — it will not be shown again.', 'wp-command-center' ) ];
		}
	} elseif ( in_array( $action, [ 'revoke_token', 'delete_token' ], true ) ) {
		$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';

		$result = 'revoke_token' === $action ? $auth_tokens->revoke( $id ) : $auth_tokens->delete( $id );

		if ( is_wp_error( $result ) ) {
			$notice = [ 'type' => 'error', 'message' => $result->get_error_message() ];
		} else {
			$messages = [
				'revoke_token' => __( 'Token revoked.', 'wp-command-center' ),
				'delete_token' => __( 'Token deleted.', 'wp-command-center' ),
			];

			$notice = [ 'type' => 'success', 'message' => $messages[ $action ] ];
		}
	}
}

$current_mode = SecurityModeManager::current();
$tokens       = $auth_tokens->list();
$rest_base      = rest_url( 'wp-command-center/v1' );
$expiry_options = [
	'never' => __( 'Never', 'wp-command-center' ),
	'30d'   => __( '30 days', 'wp-command-center' ),
	'90d'   => __( '90 days', 'wp-command-center' ),
	'1y'    => __( '1 year', 'wp-command-center' ),
];
$scope_options  = [
	AuthTokens::SCOPE_READ_ONLY => AuthTokens::scope_label( AuthTokens::SCOPE_READ_ONLY ),
	AuthTokens::SCOPE_FULL      => AuthTokens::scope_label( AuthTokens::SCOPE_FULL ),
];

$format_date = static function ( ?int $timestamp ): string {
	if ( null === $timestamp ) {
		return __( 'Never', 'wp-command-center' );
	}

	return esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) );
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
	<p><?php esc_html_e( 'API tokens, AI agent connections, and access control.', 'wp-command-center' ); ?></p>

	<?php if ( $notice ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?>"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
	<?php endif; ?>

	<?php if ( null !== $new_token ) : ?>
		<div class="notice notice-warning">
			<p><strong><?php esc_html_e( 'Your new API token (copy it now — it will not be shown again):', 'wp-command-center' ); ?></strong></p>
			<p><input type="text" readonly="readonly" class="large-text code" value="<?php echo esc_attr( $new_token ); ?>" onclick="this.select();" /></p>
		</div>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Security Mode', 'wp-command-center' ); ?></h2>
	<p><?php esc_html_e( 'Controls whether AI agents can execute write operations immediately or must wait for administrator approval. Diagnostic and read-only operations are never gated, in any mode.', 'wp-command-center' ); ?></p>

	<form method="post">
		<?php wp_nonce_field( 'wpcc_settings' ); ?>
		<input type="hidden" name="wpcc_action" value="set_security_mode" />
		<fieldset>
			<legend class="screen-reader-text"><?php esc_html_e( 'Security Mode', 'wp-command-center' ); ?></legend>

			<label style="display:block;margin-bottom:14px;padding:14px 16px;border:1px solid <?php echo $current_mode === SecurityModeManager::MODE_DEVELOPER ? '#2271b1' : '#ddd'; ?>;border-radius:4px;background:<?php echo $current_mode === SecurityModeManager::MODE_DEVELOPER ? '#f0f6fc' : '#fff'; ?>;cursor:pointer;max-width:640px;">
				<input type="radio" name="wpcc_security_mode" value="<?php echo esc_attr( SecurityModeManager::MODE_DEVELOPER ); ?>" <?php checked( $current_mode, SecurityModeManager::MODE_DEVELOPER ); ?> style="margin-top:2px;float:left;" />
				<span style="display:block;margin-left:24px;">
					<strong><?php esc_html_e( 'Developer Mode', 'wp-command-center' ); ?></strong>
					<span style="display:block;color:#50575e;margin-top:3px;font-size:13px;"><?php esc_html_e( 'No approval gate. AI agents can execute all operations immediately. Recommended during development and staging. Full audit trail and rollback remain active.', 'wp-command-center' ); ?></span>
				</span>
			</label>

			<label style="display:block;margin-bottom:14px;padding:14px 16px;border:1px solid <?php echo $current_mode === SecurityModeManager::MODE_CLIENT ? '#2271b1' : '#ddd'; ?>;border-radius:4px;background:<?php echo $current_mode === SecurityModeManager::MODE_CLIENT ? '#f0f6fc' : '#fff'; ?>;cursor:pointer;max-width:640px;">
				<input type="radio" name="wpcc_security_mode" value="<?php echo esc_attr( SecurityModeManager::MODE_CLIENT ); ?>" <?php checked( $current_mode, SecurityModeManager::MODE_CLIENT ); ?> style="margin-top:2px;float:left;" />
				<span style="display:block;margin-left:24px;">
					<strong><?php esc_html_e( 'Client Mode', 'wp-command-center' ); ?></strong>
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

	<h2><?php esc_html_e( 'API Tokens', 'wp-command-center' ); ?></h2>
	<p><?php esc_html_e( 'AI agents (Claude, Codex, custom integrations) authenticate to the REST API using a bearer token. Read-only tokens can query Site Intelligence, Diagnostics, File Access, and Patches; full-access tokens can additionally create, approve, apply, and roll back patches.', 'wp-command-center' ); ?></p>

	<table class="widefat striped wpcc-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Label', 'wp-command-center' ); ?></th>
				<th><?php esc_html_e( 'Token', 'wp-command-center' ); ?></th>
				<th><?php esc_html_e( 'Scope', 'wp-command-center' ); ?></th>
				<th><?php esc_html_e( 'Status', 'wp-command-center' ); ?></th>
				<th><?php esc_html_e( 'Created', 'wp-command-center' ); ?></th>
				<th><?php esc_html_e( 'Expires', 'wp-command-center' ); ?></th>
				<th><?php esc_html_e( 'Last Used', 'wp-command-center' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'wp-command-center' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( empty( $tokens ) ) : ?>
			<tr>
				<td colspan="8"><?php esc_html_e( 'No API tokens yet. Create one below to connect an AI agent.', 'wp-command-center' ); ?></td>
			</tr>
		<?php endif; ?>
		<?php foreach ( $tokens as $token ) : ?>
			<tr>
				<td><?php echo esc_html( $token['label'] ); ?></td>
				<td><code><?php echo esc_html( $token['token_preview'] ); ?>…</code></td>
				<td><?php echo esc_html( AuthTokens::scope_label( $token['scope'] ) ); ?></td>
				<td><?php echo AuthTokens::status_badge( $token ); ?></td>
				<td><?php echo $format_date( $token['created_at'] ); ?></td>
				<td><?php echo $format_date( $token['expires_at'] ); ?></td>
				<td><?php echo $format_date( $token['last_used_at'] ); ?></td>
				<td class="wpcc-actions">
					<?php if ( AuthTokens::STATUS_ACTIVE === $token['status'] ) : ?>
						<form method="post" class="wpcc-inline-form" onsubmit="return confirm('<?php echo esc_js( __( 'Revoke this token? Any AI agent using it will immediately lose access.', 'wp-command-center' ) ); ?>');">
							<?php wp_nonce_field( 'wpcc_settings' ); ?>
							<input type="hidden" name="wpcc_action" value="revoke_token" />
							<input type="hidden" name="id" value="<?php echo esc_attr( $token['id'] ); ?>" />
							<?php submit_button( __( 'Revoke', 'wp-command-center' ), 'small', '', false ); ?>
						</form>
					<?php else : ?>
						<form method="post" class="wpcc-inline-form" onsubmit="return confirm('<?php echo esc_js( __( 'Permanently delete this token?', 'wp-command-center' ) ); ?>');">
							<?php wp_nonce_field( 'wpcc_settings' ); ?>
							<input type="hidden" name="wpcc_action" value="delete_token" />
							<input type="hidden" name="id" value="<?php echo esc_attr( $token['id'] ); ?>" />
							<?php submit_button( __( 'Delete', 'wp-command-center' ), 'small', '', false ); ?>
						</form>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<h3><?php esc_html_e( 'Create New Token', 'wp-command-center' ); ?></h3>
	<form method="post">
		<?php wp_nonce_field( 'wpcc_settings' ); ?>
		<input type="hidden" name="wpcc_action" value="create_token" />
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="wpcc-token-label"><?php esc_html_e( 'Label', 'wp-command-center' ); ?></label></th>
				<td><input type="text" id="wpcc-token-label" name="label" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Claude Desktop', 'wp-command-center' ); ?>" required="required" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="wpcc-token-scope"><?php esc_html_e( 'Scope', 'wp-command-center' ); ?></label></th>
				<td>
					<select name="scope" id="wpcc-token-scope">
						<?php foreach ( $scope_options as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Full access allows creating, approving, applying, and rolling back patches via the API.', 'wp-command-center' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wpcc-token-expires"><?php esc_html_e( 'Expires', 'wp-command-center' ); ?></label></th>
				<td>
					<select name="expires" id="wpcc-token-expires">
						<?php foreach ( $expiry_options as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
		</table>
		<?php submit_button( __( 'Create Token', 'wp-command-center' ) ); ?>
	</form>

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
	<p><?php esc_html_e( 'Authenticate every request with the bearer token created above:', 'wp-command-center' ); ?></p>
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
				<td><?php echo esc_html( AuthTokens::scope_label( $scope ) ); ?></td>
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
