<?php
defined( 'ABSPATH' ) || exit;

use WPCommandCenter\PatchSystem\PatchApproval;
use WPCommandCenter\PatchSystem\PatchManager;

$patch_manager = new PatchManager();
$notice        = null;

if ( isset( $_POST['wpcc_action'] ) ) {
	check_admin_referer( 'wpcc_rollback' );

	$action = sanitize_text_field( wp_unslash( $_POST['wpcc_action'] ) );

	if ( 'restore_patch' === $action ) {
		$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';

		$result = ( new PatchApproval() )->rollback( $id, [ 'type' => 'admin', 'user_id' => get_current_user_id() ] );

		$notice = is_wp_error( $result )
			? [ 'type' => 'error', 'message' => $result->get_error_message() ]
			: [ 'type' => 'success', 'message' => __( 'Patch restored. The affected file(s) were reverted to their pre-patch contents.', 'wp-command-center' ) ];
	}
}

$patches_url = static function ( array $args = [] ): string {
	return esc_url( add_query_arg( array_merge( [ 'page' => 'wpcc-patches' ], $args ), admin_url( 'admin.php' ) ) );
};

$history = array_values(
	array_filter(
		$patch_manager->list(),
		static fn( array $summary ): bool => in_array( $summary['status'], [ PatchManager::STATUS_APPLIED, PatchManager::STATUS_ROLLED_BACK, PatchManager::STATUS_FAILED ], true )
	)
);
?>
<div class="wrap wpcc-wrap">
	<h1><?php esc_html_e( 'Rollback', 'wp-command-center' ); ?></h1>
	<p><?php esc_html_e( 'Patch History & Restore Center. Applying a patch automatically snapshots the files it touches — Restore reverts a patch and brings back its pre-patch file contents.', 'wp-command-center' ); ?></p>

	<?php if ( $notice ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?>"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
	<?php endif; ?>

	<table class="widefat striped wpcc-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Patch ID', 'wp-command-center' ); ?></th>
				<th><?php esc_html_e( 'Date', 'wp-command-center' ); ?></th>
				<th><?php esc_html_e( 'Modified Files', 'wp-command-center' ); ?></th>
				<th><?php esc_html_e( 'Source', 'wp-command-center' ); ?></th>
				<th><?php esc_html_e( 'Status', 'wp-command-center' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'wp-command-center' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( empty( $history ) ) : ?>
			<tr>
				<td colspan="6"><?php esc_html_e( 'No applied patches yet. Patch history will appear here once a patch is approved and applied from the Patches page.', 'wp-command-center' ); ?></td>
			</tr>
		<?php endif; ?>
		<?php foreach ( $history as $summary ) : ?>
			<tr>
				<td>
					<a href="<?php echo $patches_url( [ 'view' => $summary['id'] ] ); ?>">
						<code><?php echo esc_html( substr( $summary['id'], 0, 8 ) ); ?></code>
					</a>
				</td>
				<td>
					<?php
					$date = $summary['applied_at'] ?? $summary['created_at'];
					echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $date ) );
					?>
				</td>
				<td>
					<?php foreach ( $summary['target_files'] as $target_file ) : ?>
						<code><?php echo esc_html( $target_file ); ?></code><br />
					<?php endforeach; ?>
				</td>
				<td><?php echo esc_html( PatchManager::source_label( $summary['source'] ) ); ?></td>
				<td><?php echo PatchManager::status_badge( $summary['status'] ); ?></td>
				<td class="wpcc-actions">
					<a class="button button-small" href="<?php echo $patches_url( [ 'view' => $summary['id'] ] ); ?>"><?php esc_html_e( 'View', 'wp-command-center' ); ?></a>

					<?php if ( PatchManager::STATUS_APPLIED === $summary['status'] ) : ?>
						<form method="post" class="wpcc-inline-form" onsubmit="return confirm('<?php echo esc_js( __( 'Restore this patch? The affected file(s) will be reverted to their pre-patch contents.', 'wp-command-center' ) ); ?>');">
							<?php wp_nonce_field( 'wpcc_rollback' ); ?>
							<input type="hidden" name="wpcc_action" value="restore_patch" />
							<input type="hidden" name="id" value="<?php echo esc_attr( $summary['id'] ); ?>" />
							<?php submit_button( __( 'Restore', 'wp-command-center' ), 'small primary', '', false ); ?>
						</form>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</div>
