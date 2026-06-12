<?php
defined( 'ABSPATH' ) || exit;

use WPCommandCenter\Security\AuthTokens;

$auth_tokens = new AuthTokens();
$notice      = null;
$new_token   = null;

if ( isset( $_POST['wpcc_action'] ) ) {
	check_admin_referer( 'wpcc_settings' );

	$action = sanitize_text_field( wp_unslash( $_POST['wpcc_action'] ) );

	if ( 'create_token' === $action ) {
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
	} elseif ( 'toggle_enforce_approval' === $action ) {
		$enabled = ! empty( $_POST['wpcc_enforce_approval'] );
		update_option( 'wpcc_enforce_approval', $enabled );

		$notice = [
			'type'    => 'success',
			'message' => $enabled
				? __( 'Approval enforcement enabled. Operations marked "requires approval" will now be blocked unless requested through the request → approve → execute workflow.', 'wp-command-center' )
				: __( 'Approval enforcement disabled. Operations marked "requires approval" will execute immediately when called directly.', 'wp-command-center' ),
		];
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

$enforce_approval = (bool) get_option( 'wpcc_enforce_approval', false );
$tokens         = $auth_tokens->list();
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

	<h3><?php esc_html_e( 'Operation Approval Enforcement', 'wp-command-center' ); ?></h3>
	<p><?php esc_html_e( 'A subset of operations (plugin/theme management, settings changes, user management, database writes, and similar higher-risk actions) are marked "requires approval" in the operation catalog.', 'wp-command-center' ); ?></p>
	<p>
		<?php if ( $enforce_approval ) : ?>
			<?php esc_html_e( 'Enforcement is currently ON: these operations are blocked when called directly via REST or MCP. An AI agent (or human) must first create a request via POST /operations/requests, an administrator must approve it via POST /operations/requests/{id}/approve, and only then can it be executed via POST /operations/requests/{id}/execute.', 'wp-command-center' ); ?>
		<?php else : ?>
			<?php esc_html_e( 'Enforcement is currently OFF (default): these operations execute immediately when called directly via REST or MCP, the same as any other operation. The request → approve → execute workflow still exists and can be used voluntarily, but it is not required.', 'wp-command-center' ); ?>
		<?php endif; ?>
	</p>
	<p><?php esc_html_e( 'Turning enforcement ON adds a mandatory human-in-the-loop step before an AI agent can run higher-risk operations — useful if you want every such change reviewed before it happens. Turning it OFF (default) lets AI agents act autonomously across all operations, which is faster but relies on capability assignments and token scopes for access control instead.', 'wp-command-center' ); ?></p>

	<form method="post">
		<?php wp_nonce_field( 'wpcc_settings' ); ?>
		<input type="hidden" name="wpcc_action" value="toggle_enforce_approval" />
		<label>
			<input type="checkbox" name="wpcc_enforce_approval" value="1" <?php checked( $enforce_approval ); ?> />
			<?php esc_html_e( 'Require approval for operations marked "requires approval" before they can be executed.', 'wp-command-center' ); ?>
		</label>
		<?php submit_button( __( 'Save', 'wp-command-center' ), 'secondary', '', false ); ?>
	</form>
</div>
