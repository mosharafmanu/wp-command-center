<?php
defined( 'ABSPATH' ) || exit;

use WPCommandCenter\SiteIntelligence\SiteScanner;

$force_refresh = false;

if ( isset( $_POST['wpcc_action'] ) && 'refresh_scan' === sanitize_text_field( wp_unslash( $_POST['wpcc_action'] ) ) ) {
	check_admin_referer( 'wpcc_site_intelligence' );
	$force_refresh = true;
}

$data = ( new SiteScanner() )->scan( $force_refresh );

// CDS status pill — a boolean fact, not an error: Yes=success, No=neutral
// (color carries meaning; "No" is informational, not alarming).
$badge = static function ( bool $value ): string {
	$label   = $value ? __( 'Yes', 'siteradian' ) : __( 'No', 'siteradian' );
	$variant = $value ? 'success' : 'neutral';

	return sprintf( '<span class="wpcc-cds-pill wpcc-cds-pill--%s">%s</span>', esc_attr( $variant ), esc_html( $label ) );
};

$render_table = static function ( array $rows ): void {
	?>
	<table class="widefat striped wpcc-cds-table wpcc-table">
		<tbody>
		<?php foreach ( $rows as $label => $value ) : ?>
			<tr>
				<th scope="row"><?php echo esc_html( $label ); ?></th>
				<td><?php echo wp_kses_post( $value ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
};

$render_section = static function ( string $title, array $rows ) use ( $render_table ): void {
	?>
	<h2><?php echo esc_html( $title ); ?></h2>
	<?php $render_table( $rows ); ?>
	<?php
};

$wp_info = $data['wordpress'];
$php     = $data['php'];
$theme   = $data['theme'];
$wc      = $data['woocommerce'];
$cache   = $data['cache'];
$server  = $data['server'];
$debug   = $data['debug'];
?>
<div class="wrap wpcc-wrap">
	<h1><?php esc_html_e( 'Site report', 'siteradian' ); ?></h1>
	<p><?php esc_html_e( 'A structured snapshot of this site for AI agents — WordPress & PHP versions, active theme/plugins, WooCommerce status, cache configuration, server capabilities, debug status, and file permissions.', 'siteradian' ); ?></p>

	<p class="wpcc-scan-meta">
		<?php
		printf(
			/* translators: %s: date and time of the last scan. */
			esc_html__( 'Last scanned: %s', 'siteradian' ),
			esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $data['generated_at'] ) )
		);
		?>
	</p>

	<form method="post">
		<?php wp_nonce_field( 'wpcc_site_intelligence' ); ?>
		<input type="hidden" name="wpcc_action" value="refresh_scan" />
		<?php submit_button( __( 'Refresh Scan', 'siteradian' ), 'secondary', 'submit', false ); ?>
	</form>

	<?php
	$render_section(
		__( 'WordPress Environment', 'siteradian' ),
		[
			__( 'WordPress Version', 'siteradian' )   => esc_html( $wp_info['version'] ),
			__( 'Site URL', 'siteradian' )            => esc_html( $wp_info['site_url'] ),
			__( 'Home URL', 'siteradian' )            => esc_html( $wp_info['home_url'] ),
			__( 'Multisite', 'siteradian' )           => $badge( $wp_info['is_multisite'] ),
			__( 'Locale', 'siteradian' )              => esc_html( $wp_info['locale'] ),
			__( 'Timezone', 'siteradian' )            => esc_html( $wp_info['timezone'] ?: 'UTC' ),
			__( 'Permalink Structure', 'siteradian' ) => esc_html( $wp_info['permalink_structure'] ),
			__( 'SSL (HTTPS)', 'siteradian' )         => $badge( $wp_info['is_ssl'] ),
		]
	);

	$render_section(
		__( 'PHP Environment', 'siteradian' ),
		[
			__( 'PHP Version', 'siteradian' )         => esc_html( $php['version'] ),
			__( 'Memory Limit', 'siteradian' )        => esc_html( $php['memory_limit'] ),
			__( 'Max Execution Time', 'siteradian' )  => esc_html( $php['max_execution_time'] . 's' ),
			__( 'Upload Max Filesize', 'siteradian' ) => esc_html( $php['upload_max_filesize'] ),
			__( 'Post Max Size', 'siteradian' )       => esc_html( $php['post_max_size'] ),
			__( 'Loaded Extensions', 'siteradian' )   => esc_html( $php['loaded_extensions'] ? implode( ', ', $php['loaded_extensions'] ) : '—' ),
			__( 'Missing Extensions', 'siteradian' )  => esc_html( $php['missing_extensions'] ? implode( ', ', $php['missing_extensions'] ) : __( 'None', 'siteradian' ) ),
		]
	);

	$theme_rows = [
		__( 'Name', 'siteradian' )        => esc_html( $theme['name'] ),
		__( 'Version', 'siteradian' )     => esc_html( $theme['version'] ),
		__( 'Author', 'siteradian' )      => esc_html( $theme['author'] ),
		__( 'Template', 'siteradian' )    => esc_html( $theme['template'] ),
		__( 'Child Theme', 'siteradian' ) => $badge( $theme['is_child_theme'] ),
	];

	if ( $theme['parent'] ) {
		$theme_rows[ __( 'Parent Theme', 'siteradian' ) ] = esc_html( sprintf( '%1$s (%2$s)', $theme['parent']['name'], $theme['parent']['version'] ) );
	}

	$render_section( __( 'Active Theme', 'siteradian' ), $theme_rows );
	?>

	<h2>
		<?php
		printf(
			/* translators: %d: number of active plugins. */
			esc_html__( 'Active Plugins (%d)', 'siteradian' ),
			count( $data['plugins'] )
		);
		?>
	</h2>
	<table class="widefat striped wpcc-cds-table wpcc-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Plugin', 'siteradian' ); ?></th>
				<th><?php esc_html_e( 'Version', 'siteradian' ); ?></th>
				<th><?php esc_html_e( 'Author', 'siteradian' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $data['plugins'] as $plugin ) : ?>
			<tr>
				<td><?php echo esc_html( $plugin['name'] ); ?></td>
				<td><?php echo esc_html( $plugin['version'] ); ?></td>
				<td><?php echo esc_html( $plugin['author'] ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<?php
	if ( $wc['active'] ) {
		$render_section(
			__( 'WooCommerce', 'siteradian' ),
			[
				__( 'Status', 'siteradian' )        => $badge( true ),
				__( 'Version', 'siteradian' )       => esc_html( $wc['version'] ),
				__( 'Currency', 'siteradian' )      => esc_html( $wc['currency'] ),
				__( 'Base Location', 'siteradian' ) => esc_html( $wc['base_location'] ),
			]
		);
	} else {
		$render_section(
			__( 'WooCommerce', 'siteradian' ),
			[
				__( 'Status', 'siteradian' ) => $badge( false ) . ' ' . esc_html__( 'WooCommerce is not active on this site.', 'siteradian' ),
			]
		);
	}

	$render_section(
		__( 'Cache Configuration', 'siteradian' ),
		[
			__( 'External Object Cache', 'siteradian' )      => $badge( $cache['object_cache_enabled'] ),
			__( 'object-cache.php Drop-in', 'siteradian' )    => $badge( $cache['object_cache_dropin'] ),
			__( 'advanced-cache.php Drop-in', 'siteradian' )  => $badge( $cache['page_cache_dropin'] ),
			__( 'OPcache Enabled', 'siteradian' )             => $badge( $cache['opcache_enabled'] ),
			__( 'Detected Caching Plugins', 'siteradian' )    => esc_html( $cache['caching_plugins'] ? implode( ', ', $cache['caching_plugins'] ) : __( 'None detected', 'siteradian' ) ),
		]
	);

	$render_section(
		__( 'Server Capabilities', 'siteradian' ),
		[
			__( 'Server Software', 'siteradian' )       => esc_html( $server['software'] ?: '—' ),
			__( 'Operating System', 'siteradian' )      => esc_html( $server['os'] ),
			__( 'shell_exec() Enabled', 'siteradian' )  => $badge( $server['shell_exec_enabled'] ),
			__( 'proc_open() Enabled', 'siteradian' )   => $badge( $server['proc_open_enabled'] ),
			__( 'WP-CLI Available', 'siteradian' )      => $badge( $server['wp_cli_available'] ),
			__( 'Disabled PHP Functions', 'siteradian' ) => esc_html( $server['disabled_functions'] ? implode( ', ', $server['disabled_functions'] ) : __( 'None', 'siteradian' ) ),
		]
	);

	$render_section(
		__( 'Debug Status', 'siteradian' ),
		[
			__( 'WP_DEBUG', 'siteradian' )         => $badge( $debug['wp_debug'] ),
			__( 'WP_DEBUG_LOG', 'siteradian' )     => $badge( $debug['wp_debug_log'] ),
			__( 'WP_DEBUG_DISPLAY', 'siteradian' ) => $badge( $debug['wp_debug_display'] ),
			__( 'SCRIPT_DEBUG', 'siteradian' )     => $badge( $debug['script_debug'] ),
			__( 'debug.log Exists', 'siteradian' ) => $badge( $debug['log_exists'] ),
			__( 'debug.log Size', 'siteradian' )   => esc_html( $debug['log_exists'] ? size_format( $debug['log_size'] ) : '—' ),
		]
	);
	?>

	<h2><?php esc_html_e( 'File & Directory Permissions', 'siteradian' ); ?></h2>
	<table class="widefat striped wpcc-cds-table wpcc-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Path', 'siteradian' ); ?></th>
				<th><?php esc_html_e( 'Exists', 'siteradian' ); ?></th>
				<th><?php esc_html_e( 'Permissions', 'siteradian' ); ?></th>
				<th><?php esc_html_e( 'Writable', 'siteradian' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $data['file_permissions'] as $label => $info ) : ?>
			<tr>
				<td><?php echo esc_html( $label ); ?></td>
				<td><?php echo wp_kses_post( $badge( $info['exists'] ) ); ?></td>
				<td><?php echo esc_html( $info['permissions'] ?: '—' ); ?></td>
				<td><?php echo wp_kses_post( $badge( $info['writable'] ) ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</div>
