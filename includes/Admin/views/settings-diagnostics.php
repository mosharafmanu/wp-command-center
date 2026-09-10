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

use WPCommandCenter\Admin\DeveloperTools;

$wpcc_diag_panes = [
	'health'          => [ 'label' => __( 'Health', 'action-steward' ),         'view' => 'diagnostics' ],
	'recommendations' => [ 'label' => __( 'Recommendations', 'action-steward' ), 'view' => 'recommendations' ],
	'sitereport'      => [ 'label' => __( 'Site Report', 'action-steward' ),     'view' => 'site-intelligence' ],
];

// Patches edit site FILES. The operation stays available over REST/MCP under the
// same approval policy; only this screen is hidden unless developer tools are on.
if ( DeveloperTools::enabled() ) {
	$wpcc_diag_panes['patches'] = [ 'label' => __( 'Patches', 'action-steward' ), 'view' => 'patches' ];
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pane selection, no state change.
$wpcc_diag_active = isset( $_GET['dpane'] ) ? sanitize_key( wp_unslash( $_GET['dpane'] ) ) : 'health';
if ( ! isset( $wpcc_diag_panes[ $wpcc_diag_active ] ) ) {
	$wpcc_diag_active = 'health';
}
?>
<nav class="wpcc-cds-subnav" aria-label="<?php esc_attr_e( 'Diagnostics sections', 'action-steward' ); ?>">
	<?php foreach ( $wpcc_diag_panes as $key => $pane ) : ?>
		<a class="wpcc-cds-subnav__item<?php echo $key === $wpcc_diag_active ? ' is-active' : ''; ?>"
			href="<?php echo esc_url( admin_url( 'admin.php?page=wpcc-settings&wpcc_tab=advanced&apane=diagnostics&dpane=' . $key ) ); ?>"
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
