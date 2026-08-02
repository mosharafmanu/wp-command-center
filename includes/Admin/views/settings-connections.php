<?php
/**
 * Settings › Connections (hub).
 *
 * IA redesign: "Connect" was a top-level destination, which meant a task you
 * perform once on day one held permanent space in the navigation forever. Home
 * now owns the setup journey; this is where connections live permanently for the
 * rarer jobs — adding a second assistant, wiring up your own software, or
 * revoking access.
 *
 * One question: **who is allowed to reach this site, and how?**
 *   Assistants — connect Claude, Cursor, Codex, ChatGPT, Gemini…
 *   Your own software — the developer REST API
 *   Access tokens — the keys themselves, and what each one may do
 *
 * Hosts the EXISTING views unchanged behind a `?cpane=` sub-nav. Adds no data,
 * route, capability or schema.
 */

defined( 'ABSPATH' ) || exit;

use WPCommandCenter\Admin\FeatureGate;

$wpcc_conn_panes = [
	'assistants' => [ 'label' => __( 'Assistants', 'wp-command-center' ),      'view' => 'ai-integrations',          'feature' => null ],
	'api'        => [ 'label' => __( 'Your own software', 'wp-command-center' ), 'view' => 'api-integrations',       'feature' => null ],
	'tokens'     => [ 'label' => __( 'Access tokens', 'wp-command-center' ),   'view' => 'token-capability-manager', 'feature' => 'token_capability_manager' ],
];

foreach ( $wpcc_conn_panes as $wpcc_ck => $wpcc_cp ) {
	if ( null !== $wpcc_cp['feature'] && ! FeatureGate::allows( $wpcc_cp['feature'] ) ) {
		unset( $wpcc_conn_panes[ $wpcc_ck ] );
	}
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pane selection, no state change.
$wpcc_conn_active = isset( $_GET['cpane'] ) ? sanitize_key( wp_unslash( $_GET['cpane'] ) ) : '';
if ( ! isset( $wpcc_conn_panes[ $wpcc_conn_active ] ) ) {
	$wpcc_conn_active = (string) array_key_first( $wpcc_conn_panes );
}
?>
<div class="wpcc-settings-connections">
	<?php if ( count( $wpcc_conn_panes ) > 1 ) : ?>
		<nav class="wpcc-cds-subnav" aria-label="<?php esc_attr_e( 'Connection types', 'wp-command-center' ); ?>">
			<?php foreach ( $wpcc_conn_panes as $wpcc_ck => $wpcc_cp ) : ?>
				<a class="wpcc-cds-subnav__item<?php echo $wpcc_ck === $wpcc_conn_active ? ' is-active' : ''; ?>"
					href="<?php echo esc_url( admin_url( 'admin.php?page=wpcc-settings&wpcc_tab=connections&cpane=' . $wpcc_ck ) ); ?>"
					<?php echo $wpcc_ck === $wpcc_conn_active ? 'aria-current="page"' : ''; ?>>
					<?php echo esc_html( $wpcc_cp['label'] ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
	<?php endif; ?>

	<div class="wpcc-subnav__canvas">
		<?php
		if ( isset( $wpcc_conn_panes[ $wpcc_conn_active ] ) ) {
			$wpcc_conn_path = WPCC_PLUGIN_DIR . 'includes/Admin/views/' . $wpcc_conn_panes[ $wpcc_conn_active ]['view'] . '.php';
			if ( is_readable( $wpcc_conn_path ) ) {
				require $wpcc_conn_path;
			}
		}
		?>
	</div>
</div>
