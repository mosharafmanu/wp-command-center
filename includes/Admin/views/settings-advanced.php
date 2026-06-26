<?php
/**
 * Settings › Advanced (hub).
 *
 * Phase 2B grouping: collapses the developer/advanced reference surfaces into one
 * "Advanced" tab with a second-level sub-nav — Capabilities · File Access. Hosts the
 * EXISTING views only (no new data/route/capability/schema). Sub-pane selected by the
 * namespaced `?apane=` arg. FeatureGate-gated panes drop out when their gate is closed.
 *
 * NOTE: the developer-only "Engine Inspector" (raw operation results, pipeline, JSON,
 * telemetry — the internals that the retired Runtime page exposed) is intentionally
 * DEFERRED. Those internals remain available via REST (/agent/timeline, /agent/tree,
 * operation results) and MCP, so no access is lost; the Inspector is an optional
 * Developer-mode convenience that must not block the Runtime cutover.
 */

defined( 'ABSPATH' ) || exit;

use WPCommandCenter\Admin\FeatureGate;

$wpcc_adv_panes = [
	'capabilities' => [ 'label' => __( 'Capabilities', 'wp-command-center' ), 'view' => 'operations-explorer', 'feature' => 'operations_explorer' ],
	'files'        => [ 'label' => __( 'File Access', 'wp-command-center' ),  'view' => 'file-access',         'feature' => null ],
];

// Drop any pane whose FeatureGate is closed (licensing seam; ungated today).
foreach ( $wpcc_adv_panes as $key => $pane ) {
	if ( null !== $pane['feature'] && ! FeatureGate::allows( $pane['feature'] ) ) {
		unset( $wpcc_adv_panes[ $key ] );
	}
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pane selection, no state change.
$wpcc_adv_active = isset( $_GET['apane'] ) ? sanitize_key( wp_unslash( $_GET['apane'] ) ) : '';
if ( ! isset( $wpcc_adv_panes[ $wpcc_adv_active ] ) ) {
	$wpcc_adv_active = (string) array_key_first( $wpcc_adv_panes );
}
?>
<nav class="wpcc-cds-subnav" aria-label="<?php esc_attr_e( 'Advanced sections', 'wp-command-center' ); ?>">
	<?php foreach ( $wpcc_adv_panes as $key => $pane ) : ?>
		<a class="wpcc-cds-subnav__item<?php echo $key === $wpcc_adv_active ? ' is-active' : ''; ?>"
			href="<?php echo esc_url( admin_url( 'admin.php?page=wpcc-settings&wpcc_tab=advanced&apane=' . $key ) ); ?>"
			<?php echo $key === $wpcc_adv_active ? 'aria-current="page"' : ''; ?>>
			<?php echo esc_html( $pane['label'] ); ?>
		</a>
	<?php endforeach; ?>
</nav>

<div class="wpcc-subnav__canvas">
	<?php
	if ( '' !== $wpcc_adv_active && isset( $wpcc_adv_panes[ $wpcc_adv_active ] ) ) {
		$wpcc_adv_path = WPCC_PLUGIN_DIR . 'includes/Admin/views/' . $wpcc_adv_panes[ $wpcc_adv_active ]['view'] . '.php';
		if ( is_readable( $wpcc_adv_path ) ) {
			require $wpcc_adv_path;
		}
	} else {
		echo '<div class="wpcc-cds-empty" role="status"><p class="description">' . esc_html__( 'No advanced surfaces are available in this edition.', 'wp-command-center' ) . '</p></div>';
	}
	?>
</div>
