<?php
defined( 'ABSPATH' ) || exit;

use WPCommandCenter\Diagnostics\DebugLogViewer;
use WPCommandCenter\Diagnostics\PerformanceDiagnostics;
use WPCommandCenter\Diagnostics\SecurityDiagnostics;
use WPCommandCenter\Diagnostics\WooCommerceDiagnostics;
use WPCommandCenter\SiteIntelligence\SiteScanner;

$valid_tabs = [ 'performance', 'security', 'woocommerce', 'debug-log' ];
$tab        = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'performance';

if ( ! in_array( $tab, $valid_tabs, true ) ) {
	$tab = 'performance';
}

$tabs = [
	'performance' => __( 'Performance', 'wp-command-center' ),
	'security'    => __( 'Security', 'wp-command-center' ),
	'woocommerce' => __( 'WooCommerce', 'wp-command-center' ),
	'debug-log'   => __( 'Debug Log', 'wp-command-center' ),
];

$status_labels = [
	'good'        => __( 'Good', 'wp-command-center' ),
	'recommended' => __( 'Recommended', 'wp-command-center' ),
	'critical'    => __( 'Critical', 'wp-command-center' ),
	'info'        => __( 'Info', 'wp-command-center' ),
];

// CDS status pill — color carries meaning (good=success, recommended=warning,
// critical=danger, info=info). Variant map keeps the diagnostics semantics.
$status_variant = [
	'good'        => 'success',
	'recommended' => 'warning',
	'critical'    => 'danger',
	'info'        => 'info',
];

$status_badge = static function ( string $status ) use ( $status_labels, $status_variant ): string {
	$label   = $status_labels[ $status ] ?? ucfirst( $status );
	$variant = $status_variant[ $status ] ?? 'neutral';

	return sprintf( '<span class="wpcc-cds-pill wpcc-cds-pill--%s">%s</span>', esc_attr( $variant ), esc_html( $label ) );
};

$render_checks = static function ( array $checks ) use ( $status_badge ): void {
	?>
	<table class="widefat striped wpcc-cds-table wpcc-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Check', 'wp-command-center' ); ?></th>
				<th><?php esc_html_e( 'Status', 'wp-command-center' ); ?></th>
				<th><?php esc_html_e( 'Details', 'wp-command-center' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $checks as $check ) : ?>
			<tr>
				<td><?php echo esc_html( $check['label'] ); ?></td>
				<td><?php echo wp_kses_post( $status_badge( $check['status'] ) ); ?></td>
				<td><?php echo esc_html( $check['description'] ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
};
?>
<div class="wrap wpcc-wrap">
	<h1><?php esc_html_e( 'Diagnostics', 'wp-command-center' ); ?></h1>
	<p><?php esc_html_e( 'Performance, security, and WooCommerce diagnostics, plus the debug log viewer.', 'wp-command-center' ); ?></p>

	<h2 class="nav-tab-wrapper">
		<?php foreach ( $tabs as $tab_id => $tab_label ) : ?>
			<a
				href="<?php echo esc_url( add_query_arg( [ 'page' => 'wpcc-diagnostics', 'tab' => $tab_id ], admin_url( 'admin.php' ) ) ); ?>"
				class="nav-tab <?php echo $tab === $tab_id ? 'nav-tab-active' : ''; ?>"
			>
				<?php echo esc_html( $tab_label ); ?>
			</a>
		<?php endforeach; ?>
	</h2>

	<?php if ( 'performance' === $tab ) : ?>

		<?php $render_checks( ( new PerformanceDiagnostics() )->analyze( ( new SiteScanner() )->scan() ) ); ?>

	<?php elseif ( 'security' === $tab ) : ?>

		<?php $render_checks( ( new SecurityDiagnostics() )->analyze( ( new SiteScanner() )->scan() ) ); ?>

	<?php elseif ( 'woocommerce' === $tab ) : ?>

		<?php $render_checks( ( new WooCommerceDiagnostics() )->analyze() ); ?>

	<?php elseif ( 'debug-log' === $tab ) : ?>

		<?php
		$log_viewer = new DebugLogViewer();

		if ( isset( $_POST['wpcc_action'] ) && 'clear_debug_log' === sanitize_text_field( wp_unslash( $_POST['wpcc_action'] ) ) ) {
			check_admin_referer( 'wpcc_clear_debug_log' );

			$cleared = $log_viewer->clear();

			if ( is_wp_error( $cleared ) ) {
				printf( '<div class="wpcc-cds-notice wpcc-cds-notice--danger"><p>%s</p></div>', esc_html( $cleared->get_error_message() ) );
			} else {
				printf( '<div class="wpcc-cds-notice wpcc-cds-notice--success"><p>%s</p></div>', esc_html__( 'Debug log cleared.', 'wp-command-center' ) );
			}
		}

		$line_options = [ 50, 100, 200, 500, 1000 ];
		$lines        = isset( $_GET['lines'] ) ? absint( $_GET['lines'] ) : 200;

		if ( ! in_array( $lines, $line_options, true ) ) {
			$lines = 200;
		}

		$result = $log_viewer->tail( $lines );
		?>

		<form method="get" class="wpcc-debug-log-controls">
			<input type="hidden" name="page" value="wpcc-diagnostics" />
			<input type="hidden" name="tab" value="debug-log" />
			<label for="wpcc-lines"><?php esc_html_e( 'Lines:', 'wp-command-center' ); ?></label>
			<select name="lines" id="wpcc-lines" onchange="this.form.submit()">
				<?php foreach ( $line_options as $option ) : ?>
					<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $lines, $option ); ?>><?php echo esc_html( $option ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Refresh', 'wp-command-center' ), 'secondary', '', false ); ?>
		</form>

		<?php if ( is_wp_error( $result ) ) : ?>

			<p><?php echo esc_html( $result->get_error_message() ); ?></p>

		<?php else : ?>

			<p class="wpcc-scan-meta">
				<?php
				printf(
					/* translators: 1: file size, 2: last modified date/time */
					esc_html__( 'Size: %1$s — Last modified: %2$s', 'wp-command-center' ),
					esc_html( size_format( $result['size'] ) ),
					esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $result['modified'] ) )
				);

				if ( $result['truncated'] ) {
					echo ' &mdash; ' . esc_html__( 'showing the most recent portion of a large log file.', 'wp-command-center' );
				}
				?>
			</p>

			<?php if ( empty( $result['lines'] ) ) : ?>
				<div class="wpcc-cds-empty"><div class="wpcc-cds-empty__title"><?php esc_html_e( 'The debug log is empty.', 'wp-command-center' ); ?></div></div>
			<?php else : ?>
				<pre class="wpcc-debug-log"><?php foreach ( $result['lines'] as $line ) : ?><span class="wpcc-log-line wpcc-log-line--<?php echo esc_attr( $line['level'] ); ?>"><?php echo esc_html( $line['text'] ); ?>
</span><?php endforeach; ?></pre>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( 'wpcc_clear_debug_log' ); ?>
				<input type="hidden" name="wpcc_action" value="clear_debug_log" />
				<?php submit_button( __( 'Clear Log', 'wp-command-center' ), 'delete', 'submit', false ); ?>
			</form>

		<?php endif; ?>

	<?php endif; ?>
</div>
