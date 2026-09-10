<?php
/**
 * Settings › Advanced (hub).
 *
 * IA redesign: "Advanced" now means exactly one thing — everything a normal
 * customer never needs to open. Previously this material was scattered across
 * four top-level tabs (Built-in AI, Diagnostics, Tools, Advanced) plus a section
 * of its own (Activity › System), which put engine internals at the same level
 * as the approval queue. It is all still here, all still reachable, all still
 * one click from the Settings tab bar — just no longer competing with the work.
 *
 * Panes, in order of how likely a real customer is to want them:
 *   Built-in AI   — optional provider key + generation tools (off by default)
 *   Diagnostics   — health, recommendations, site report, patches
 *   System        — the live engine feed
 *   Capabilities  — the operation/capability map
 *   Drafts        — dev-only proposal surface (build-flagged)
 *   File access / Search & replace — developer tools (DeveloperTools gate)
 *
 * Hosts the EXISTING views only. No new data, route, capability or schema.
 */

defined( 'ABSPATH' ) || exit;

use WPCommandCenter\Admin\AppShell;

/*
 * The pane list — including the build/developer gating and the FeatureGate
 * filtering — now lives on AppShell beside sections(), so the ⌘K palette can
 * offer Diagnostics, System and Capabilities as the destinations they are, and
 * can never offer a pane this site has switched off. Rendering is unchanged.
 */
$wpcc_adv_panes = AppShell::advanced_panes();

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pane selection, no state change.
$wpcc_adv_active = isset( $_GET['apane'] ) ? sanitize_key( wp_unslash( $_GET['apane'] ) ) : '';
if ( ! isset( $wpcc_adv_panes[ $wpcc_adv_active ] ) ) {
	$wpcc_adv_active = (string) array_key_first( $wpcc_adv_panes );
}
?>
<div class="wpcc-settings-advanced">
	<p class="description" style="max-width:70ch;margin:0 0 16px;">
		<?php esc_html_e( 'Everything below is optional. A site that just wants an AI assistant working safely never needs to open this tab.', 'action-steward' ); ?>
	</p>

	<?php if ( count( $wpcc_adv_panes ) > 1 ) : ?>
		<nav class="wpcc-cds-subnav" aria-label="<?php esc_attr_e( 'Advanced sections', 'action-steward' ); ?>">
			<?php foreach ( $wpcc_adv_panes as $wpcc_ak => $wpcc_ap ) : ?>
				<a class="wpcc-cds-subnav__item<?php echo $wpcc_ak === $wpcc_adv_active ? ' is-active' : ''; ?>"
					href="<?php echo esc_url( admin_url( 'admin.php?page=wpcc-settings&wpcc_tab=advanced&apane=' . $wpcc_ak ) ); ?>"
					<?php echo $wpcc_ak === $wpcc_adv_active ? 'aria-current="page"' : ''; ?>>
					<?php echo esc_html( $wpcc_ap['label'] ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
	<?php endif; ?>

	<div class="wpcc-subnav__canvas">
		<?php
		if ( isset( $wpcc_adv_panes[ $wpcc_adv_active ] ) ) {
			$wpcc_adv_path = WPCC_PLUGIN_DIR . 'includes/Admin/views/' . $wpcc_adv_panes[ $wpcc_adv_active ]['view'] . '.php';
			if ( is_readable( $wpcc_adv_path ) ) {
				require $wpcc_adv_path;
			}
		} else {
			echo '<div class="wpcc-cds-empty" role="status"><p class="description">' . esc_html__( 'No advanced surfaces are available in this edition.', 'action-steward' ) . '</p></div>';
		}
		?>
	</div>
</div>
