<?php
/**
 * Settings › Diagnostics (hub).
 *
 * Phase 2B grouping: a thin wrapper that collapses the formerly-separate diagnostic
 * surfaces into one "Diagnostics" tab with a second-level sub-nav — Health ·
 * Recommendations · Site Report · Patches. It only hosts the EXISTING views (it adds
 * no data, route, capability, or schema); each sub-view renders exactly as before.
 * The sub-pane is selected by the namespaced `?dpane=` arg so it never collides with a
 * hosted view's own `?tab=` / `?view=` sub-navigation.
 */

defined( 'ABSPATH' ) || exit;

$wpcc_diag_panes = [
	'health'          => [ 'label' => __( 'Health', 'wp-command-center' ),         'view' => 'diagnostics' ],
	'recommendations' => [ 'label' => __( 'Recommendations', 'wp-command-center' ), 'view' => 'recommendations' ],
	'sitereport'      => [ 'label' => __( 'Site Report', 'wp-command-center' ),     'view' => 'site-intelligence' ],
	'patches'         => [ 'label' => __( 'Patches', 'wp-command-center' ),         'view' => 'patches' ],
];

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pane selection, no state change.
$wpcc_diag_active = isset( $_GET['dpane'] ) ? sanitize_key( wp_unslash( $_GET['dpane'] ) ) : 'health';
if ( ! isset( $wpcc_diag_panes[ $wpcc_diag_active ] ) ) {
	$wpcc_diag_active = 'health';
}
?>
<nav class="wpcc-subnav" aria-label="<?php esc_attr_e( 'Diagnostics sections', 'wp-command-center' ); ?>" style="display:flex;gap:4px;flex-wrap:wrap;margin:4px 0 18px;border-bottom:1px solid #dcdcde;padding-bottom:8px;">
	<?php foreach ( $wpcc_diag_panes as $key => $pane ) : ?>
		<a class="wpcc-subnav__item<?php echo $key === $wpcc_diag_active ? ' is-active' : ''; ?>"
			href="<?php echo esc_url( admin_url( 'admin.php?page=wpcc-settings&wpcc_tab=diagnostics&dpane=' . $key ) ); ?>"
			style="padding:6px 12px;border-radius:4px;font-size:13px;font-weight:600;text-decoration:none;<?php echo $key === $wpcc_diag_active ? 'background:#2271b1;color:#fff;' : 'background:#f0f0f1;color:#50575e;'; ?>"
			<?php echo $key === $wpcc_diag_active ? 'aria-current="page"' : ''; ?>>
			<?php echo esc_html( $pane['label'] ); ?>
		</a>
	<?php endforeach; ?>
</nav>

<div class="wpcc-subnav__canvas">
	<?php
	$wpcc_diag_path = WPCC_PLUGIN_DIR . 'includes/Admin/views/' . $wpcc_diag_panes[ $wpcc_diag_active ]['view'] . '.php';
	if ( is_readable( $wpcc_diag_path ) ) {
		require $wpcc_diag_path;
	}
	?>
</div>
