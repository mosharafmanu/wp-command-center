<?php
defined( 'ABSPATH' ) || exit;

use WPCommandCenter\AiAgent\FileAccessApi;
use WPCommandCenter\PatchSystem\PatchApproval;
use WPCommandCenter\PatchSystem\PatchManager;

$patch_manager = new PatchManager();
$approval      = new PatchApproval();
$notice        = null;

if ( isset( $_POST['wpcc_action'] ) ) {
	check_admin_referer( 'wpcc_patches' );

	$action = sanitize_text_field( wp_unslash( $_POST['wpcc_action'] ) );

	if ( 'create_patch' === $action ) {
		$path        = isset( $_POST['path'] ) ? trim( wp_unslash( $_POST['path'] ), '/' ) : '';
		$modified    = isset( $_POST['modified'] ) ? wp_unslash( $_POST['modified'] ) : '';
		$explanation = isset( $_POST['explanation'] ) ? wp_unslash( $_POST['explanation'] ) : '';
		$risk_level  = isset( $_POST['risk_level'] ) ? sanitize_text_field( wp_unslash( $_POST['risk_level'] ) ) : PatchManager::RISK_LOW;

		$result = $patch_manager->create(
			[ [ 'path' => $path, 'modified' => $modified ] ],
			$explanation,
			$risk_level,
			PatchManager::SOURCE_MANUAL,
			[ 'type' => 'admin', 'user_id' => get_current_user_id() ]
		);

		$notice = is_wp_error( $result )
			? [ 'type' => 'error', 'message' => $result->get_error_message() ]
			: [ 'type' => 'success', 'message' => __( 'Patch created and pending approval.', 'wp-command-center' ) ];
	} elseif ( in_array( $action, [ 'approve_patch', 'reject_patch', 'apply_patch', 'rollback_patch', 'delete_patch' ], true ) ) {
		$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';

		$actor = [ 'type' => 'admin', 'user_id' => get_current_user_id() ];

		$result = match ( $action ) {
			'approve_patch'  => $approval->approve( $id, $actor ),
			'reject_patch'   => $approval->reject( $id, $actor ),
			'apply_patch'    => $approval->apply( $id, $actor ),
			'rollback_patch' => $approval->rollback( $id, $actor ),
			'delete_patch'   => ( static function () use ( $patch_manager, $id ) {
				$patch = $patch_manager->get( $id );

				if ( is_wp_error( $patch ) ) {
					return $patch;
				}

				if ( PatchManager::STATUS_APPLIED === $patch['status'] ) {
					return new \WP_Error( 'wpcc_invalid_status', __( 'Roll back this patch before deleting it.', 'wp-command-center' ) );
				}

				return $patch_manager->delete( $id );
			} )(),
		};

		if ( is_wp_error( $result ) ) {
			$notice = [ 'type' => 'error', 'message' => $result->get_error_message() ];
		} elseif ( 'apply_patch' === $action && PatchManager::STATUS_FAILED === ( $result['status'] ?? '' ) ) {
			$notice = [ 'type' => 'error', 'message' => __( 'Patch failed verification and the affected file(s) were automatically reverted.', 'wp-command-center' ) ];
		} else {
			$messages = [
				'approve_patch'  => __( 'Patch approved.', 'wp-command-center' ),
				'reject_patch'   => __( 'Patch rejected.', 'wp-command-center' ),
				'apply_patch'    => __( 'Patch applied successfully. A snapshot was taken automatically.', 'wp-command-center' ),
				'rollback_patch' => __( 'Patch rolled back. The affected file(s) were restored.', 'wp-command-center' ),
				'delete_patch'   => __( 'Patch deleted.', 'wp-command-center' ),
			];

			$notice = [ 'type' => 'success', 'message' => $messages[ $action ] ?? '' ];
		}
	}
}

$page_url = static function ( array $args = [] ): string {
	return esc_url( add_query_arg( array_merge( [ 'page' => 'wpcc-patches' ], $args ), admin_url( 'admin.php' ) ) );
};

$risk_labels = [
	PatchManager::RISK_LOW    => PatchManager::risk_label( PatchManager::RISK_LOW ),
	PatchManager::RISK_MEDIUM => PatchManager::risk_label( PatchManager::RISK_MEDIUM ),
	PatchManager::RISK_HIGH   => PatchManager::risk_label( PatchManager::RISK_HIGH ),
];

$render_diff = static function ( string $diff ): void {
	if ( '' === $diff ) {
		echo '<p class="description">' . esc_html__( 'No textual changes.', 'wp-command-center' ) . '</p>';
		return;
	}

	echo '<pre class="wpcc-diff">';

	foreach ( explode( "\n", $diff ) as $line ) {
		$class = 'wpcc-diff-line';

		if ( str_starts_with( $line, '+++' ) || str_starts_with( $line, '---' ) ) {
			$class .= ' wpcc-diff-line--header';
		} elseif ( str_starts_with( $line, '@@' ) ) {
			$class .= ' wpcc-diff-line--hunk';
		} elseif ( str_starts_with( $line, '+' ) ) {
			$class .= ' wpcc-diff-line--add';
		} elseif ( str_starts_with( $line, '-' ) ) {
			$class .= ' wpcc-diff-line--del';
		}

		printf( "<span class=\"%s\">%s</span>\n", esc_attr( $class ), esc_html( $line ) );
	}

	echo '</pre>';
};

$view_id      = isset( $_GET['view'] ) ? sanitize_text_field( wp_unslash( $_GET['view'] ) ) : '';
$prefill_path = isset( $_GET['path'] ) ? trim( wp_unslash( $_GET['path'] ), '/' ) : '';

$patches = $patch_manager->list();
?>
<div class="wrap wpcc-wrap">
	<h1><?php esc_html_e( 'Patches', 'wp-command-center' ); ?></h1>
	<p><?php esc_html_e( 'AI-generated and manual patches — review the diff, explanation, and risk level, then approve, apply, or roll back.', 'wp-command-center' ); ?></p>

	<?php if ( $notice ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?>"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
	<?php endif; ?>

	<?php if ( '' !== $view_id ) : ?>

		<?php $patch = $patch_manager->get( $view_id ); ?>

		<?php if ( is_wp_error( $patch ) ) : ?>

			<div class="notice notice-error"><p><?php echo esc_html( $patch->get_error_message() ); ?></p></div>

		<?php else : ?>

			<h2><?php esc_html_e( 'Patch Details', 'wp-command-center' ); ?></h2>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Patch ID', 'wp-command-center' ); ?></th>
					<td><code><?php echo esc_html( $patch['id'] ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Source', 'wp-command-center' ); ?></th>
					<td><?php echo esc_html( PatchManager::source_label( $patch['source'] ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'wp-command-center' ); ?></th>
					<td><?php echo PatchManager::status_badge( $patch['status'] ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Risk Level', 'wp-command-center' ); ?></th>
					<td><?php echo PatchManager::risk_badge( $patch['risk_level'] ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Target File(s)', 'wp-command-center' ); ?></th>
					<td>
						<?php foreach ( $patch['files'] as $file ) : ?>
							<code><?php echo esc_html( $file['path'] ); ?></code><br />
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Created', 'wp-command-center' ); ?></th>
					<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $patch['created_at'] ) ); ?></td>
				</tr>
				<?php if ( ! empty( $patch['applied_at'] ) ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Applied', 'wp-command-center' ); ?></th>
						<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $patch['applied_at'] ) ); ?></td>
					</tr>
				<?php endif; ?>
				<?php if ( '' !== $patch['explanation'] ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Explanation', 'wp-command-center' ); ?></th>
						<td><?php echo nl2br( esc_html( $patch['explanation'] ) ); ?></td>
					</tr>
				<?php endif; ?>
			</table>

			<?php if ( ! empty( $patch['verification'] ) ) : ?>
				<h2><?php esc_html_e( 'Verification', 'wp-command-center' ); ?></h2>
				<ul class="ul-disc">
					<?php foreach ( $patch['verification']['checks'] as $path => $check ) : ?>
						<li>
							<?php echo $check['passed'] ? '✅' : '❌'; ?>
							<code><?php echo esc_html( $path ); ?></code>
							&mdash; <?php echo esc_html( $check['message'] ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Diff Preview', 'wp-command-center' ); ?></h2>
			<?php foreach ( $patch['files'] as $file ) : ?>
				<h3><code><?php echo esc_html( $file['path'] ); ?></code></h3>
				<?php $render_diff( $file['diff'] ); ?>
			<?php endforeach; ?>

			<h2><?php esc_html_e( 'Actions', 'wp-command-center' ); ?></h2>
			<p class="wpcc-actions">
				<?php if ( in_array( $patch['status'], [ PatchManager::STATUS_DRAFT, PatchManager::STATUS_PENDING_APPROVAL ], true ) ) : ?>
					<form method="post" class="wpcc-inline-form">
						<?php wp_nonce_field( 'wpcc_patches' ); ?>
						<input type="hidden" name="wpcc_action" value="approve_patch" />
						<input type="hidden" name="id" value="<?php echo esc_attr( $patch['id'] ); ?>" />
						<?php submit_button( __( 'Approve Patch', 'wp-command-center' ), 'primary', '', false ); ?>
					</form>
				<?php endif; ?>

				<?php if ( PatchManager::STATUS_APPROVED === $patch['status'] ) : ?>
					<form method="post" class="wpcc-inline-form">
						<?php wp_nonce_field( 'wpcc_patches' ); ?>
						<input type="hidden" name="wpcc_action" value="apply_patch" />
						<input type="hidden" name="id" value="<?php echo esc_attr( $patch['id'] ); ?>" />
						<?php submit_button( __( 'Apply Patch', 'wp-command-center' ), 'primary', '', false ); ?>
					</form>
				<?php endif; ?>

				<?php if ( in_array( $patch['status'], [ PatchManager::STATUS_DRAFT, PatchManager::STATUS_PENDING_APPROVAL, PatchManager::STATUS_APPROVED ], true ) ) : ?>
					<form method="post" class="wpcc-inline-form">
						<?php wp_nonce_field( 'wpcc_patches' ); ?>
						<input type="hidden" name="wpcc_action" value="reject_patch" />
						<input type="hidden" name="id" value="<?php echo esc_attr( $patch['id'] ); ?>" />
						<?php submit_button( __( 'Reject Patch', 'wp-command-center' ), 'secondary', '', false ); ?>
					</form>
				<?php endif; ?>

				<?php if ( PatchManager::STATUS_APPLIED === $patch['status'] ) : ?>
					<form method="post" class="wpcc-inline-form" onsubmit="return confirm('<?php echo esc_js( __( 'Roll back this patch and restore the affected file(s)?', 'wp-command-center' ) ); ?>');">
						<?php wp_nonce_field( 'wpcc_patches' ); ?>
						<input type="hidden" name="wpcc_action" value="rollback_patch" />
						<input type="hidden" name="id" value="<?php echo esc_attr( $patch['id'] ); ?>" />
						<?php submit_button( __( 'Roll Back Patch', 'wp-command-center' ), 'secondary', '', false ); ?>
					</form>
				<?php endif; ?>

				<?php if ( PatchManager::STATUS_APPLIED !== $patch['status'] ) : ?>
					<form method="post" class="wpcc-inline-form" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this patch? This cannot be undone.', 'wp-command-center' ) ); ?>');">
						<?php wp_nonce_field( 'wpcc_patches' ); ?>
						<input type="hidden" name="wpcc_action" value="delete_patch" />
						<input type="hidden" name="id" value="<?php echo esc_attr( $patch['id'] ); ?>" />
						<?php submit_button( __( 'Delete Patch', 'wp-command-center' ), 'delete', '', false ); ?>
					</form>
				<?php endif; ?>
			</p>

			<p><a href="<?php echo $page_url(); ?>"><?php esc_html_e( '← Back to all patches', 'wp-command-center' ); ?></a></p>

		<?php endif; ?>

	<?php else : ?>

		<table class="widefat striped wpcc-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Patch ID', 'wp-command-center' ); ?></th>
					<th><?php esc_html_e( 'Source', 'wp-command-center' ); ?></th>
					<th><?php esc_html_e( 'Target File(s)', 'wp-command-center' ); ?></th>
					<th><?php esc_html_e( 'Date Created', 'wp-command-center' ); ?></th>
					<th><?php esc_html_e( 'Status', 'wp-command-center' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'wp-command-center' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $patches ) ) : ?>
				<tr>
					<td colspan="6"><?php esc_html_e( 'No patches yet.', 'wp-command-center' ); ?></td>
				</tr>
			<?php endif; ?>
			<?php foreach ( $patches as $summary ) : ?>
				<tr>
					<td>
						<a href="<?php echo $page_url( [ 'view' => $summary['id'] ] ); ?>">
							<code><?php echo esc_html( substr( $summary['id'], 0, 8 ) ); ?></code>
						</a>
					</td>
					<td><?php echo esc_html( PatchManager::source_label( $summary['source'] ) ); ?></td>
					<td>
						<?php foreach ( $summary['target_files'] as $target_file ) : ?>
							<code><?php echo esc_html( $target_file ); ?></code><br />
						<?php endforeach; ?>
					</td>
					<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $summary['created_at'] ) ); ?></td>
					<td><?php echo PatchManager::status_badge( $summary['status'] ); ?></td>
					<td class="wpcc-actions">
						<a class="button button-small" href="<?php echo $page_url( [ 'view' => $summary['id'] ] ); ?>"><?php esc_html_e( 'View', 'wp-command-center' ); ?></a>

						<?php if ( in_array( $summary['status'], [ PatchManager::STATUS_DRAFT, PatchManager::STATUS_PENDING_APPROVAL ], true ) ) : ?>
							<form method="post" class="wpcc-inline-form">
								<?php wp_nonce_field( 'wpcc_patches' ); ?>
								<input type="hidden" name="wpcc_action" value="approve_patch" />
								<input type="hidden" name="id" value="<?php echo esc_attr( $summary['id'] ); ?>" />
								<?php submit_button( __( 'Approve', 'wp-command-center' ), 'small primary', '', false ); ?>
							</form>
						<?php endif; ?>

						<?php if ( PatchManager::STATUS_APPROVED === $summary['status'] ) : ?>
							<form method="post" class="wpcc-inline-form">
								<?php wp_nonce_field( 'wpcc_patches' ); ?>
								<input type="hidden" name="wpcc_action" value="apply_patch" />
								<input type="hidden" name="id" value="<?php echo esc_attr( $summary['id'] ); ?>" />
								<?php submit_button( __( 'Apply', 'wp-command-center' ), 'small primary', '', false ); ?>
							</form>
						<?php endif; ?>

						<?php if ( PatchManager::STATUS_APPLIED === $summary['status'] ) : ?>
							<form method="post" class="wpcc-inline-form" onsubmit="return confirm('<?php echo esc_js( __( 'Roll back this patch and restore the affected file(s)?', 'wp-command-center' ) ); ?>');">
								<?php wp_nonce_field( 'wpcc_patches' ); ?>
								<input type="hidden" name="wpcc_action" value="rollback_patch" />
								<input type="hidden" name="id" value="<?php echo esc_attr( $summary['id'] ); ?>" />
								<?php submit_button( __( 'Roll Back', 'wp-command-center' ), 'small', '', false ); ?>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<details id="create-patch"<?php echo '' !== $prefill_path ? ' open' : ''; ?>>
			<summary><?php esc_html_e( 'Create Patch Manually', 'wp-command-center' ); ?></summary>

			<form method="get" class="wpcc-search-form">
				<input type="hidden" name="page" value="wpcc-patches" />
				<label for="wpcc-patch-load-path" class="screen-reader-text"><?php esc_html_e( 'File path', 'wp-command-center' ); ?></label>
				<input type="text" id="wpcc-patch-load-path" name="path" class="regular-text" value="<?php echo esc_attr( $prefill_path ); ?>" placeholder="plugins/my-plugin/file.php" />
				<?php submit_button( __( 'Load File', 'wp-command-center' ), 'secondary', '', false ); ?>
				<p class="description"><?php esc_html_e( 'Load the current contents of a file (relative to wp-content/), edit it below, and submit to create a patch for review.', 'wp-command-center' ); ?></p>
			</form>

			<?php if ( '' !== $prefill_path ) : ?>
				<?php $loaded = ( new FileAccessApi() )->read( $prefill_path ); ?>

				<?php if ( is_wp_error( $loaded ) ) : ?>
					<div class="notice notice-error"><p><?php echo esc_html( $loaded->get_error_message() ); ?></p></div>
				<?php else : ?>
					<form method="post">
						<?php wp_nonce_field( 'wpcc_patches' ); ?>
						<input type="hidden" name="wpcc_action" value="create_patch" />
						<input type="hidden" name="path" value="<?php echo esc_attr( $loaded['path'] ); ?>" />
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php esc_html_e( 'File', 'wp-command-center' ); ?></th>
								<td><code><?php echo esc_html( $loaded['path'] ); ?></code></td>
							</tr>
							<tr>
								<th scope="row"><label for="wpcc-patch-modified"><?php esc_html_e( 'New Contents', 'wp-command-center' ); ?></label></th>
								<td><textarea name="modified" id="wpcc-patch-modified" class="large-text code" rows="20" spellcheck="false"><?php echo esc_textarea( $loaded['contents'] ); ?></textarea></td>
							</tr>
							<tr>
								<th scope="row"><label for="wpcc-patch-explanation"><?php esc_html_e( 'Explanation', 'wp-command-center' ); ?></label></th>
								<td><textarea name="explanation" id="wpcc-patch-explanation" class="large-text" rows="3" placeholder="<?php esc_attr_e( 'What does this change do and why?', 'wp-command-center' ); ?>"></textarea></td>
							</tr>
							<tr>
								<th scope="row"><label for="wpcc-patch-risk"><?php esc_html_e( 'Risk Level', 'wp-command-center' ); ?></label></th>
								<td>
									<select name="risk_level" id="wpcc-patch-risk">
										<?php foreach ( $risk_labels as $value => $label ) : ?>
											<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						</table>
						<?php submit_button( __( 'Create Patch', 'wp-command-center' ) ); ?>
					</form>
				<?php endif; ?>
			<?php endif; ?>
		</details>

	<?php endif; ?>
</div>
