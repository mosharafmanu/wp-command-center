<?php
/**
 * Fired when WP Command Center is deleted from the Plugins screen.
 *
 * POLICY — retain by default, purge only on explicit request.
 *
 * WP Command Center's tables hold an audit trail: who changed what on this site,
 * when, and what the change can be rolled back to. That is exactly the kind of
 * record a site owner may still need after uninstalling, and deleting a plugin
 * is not consent to erase it. So the default is to KEEP the data and remove only
 * what is meaningless without the plugin (scheduled jobs and caches).
 *
 * A site that wants everything gone opts in first, under
 * Settings › Protection, which sets `wpcc_delete_data_on_uninstall`.
 * When set, this file removes every table, option, user meta key, and upload
 * directory the plugin created.
 *
 * Both paths are documented in readme.txt so the behaviour is never a surprise.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/** Custom tables created by Schema (unprefixed). */
const WPCC_UNINSTALL_TABLES = [
	'wpcc_agent_actions',
	'wpcc_agent_plan_steps',
	'wpcc_agent_plans',
	'wpcc_agent_sessions',
	'wpcc_agent_tasks',
	'wpcc_change_log',
	'wpcc_health_verifications',
	'wpcc_idempotency',
	'wpcc_operation_queue',
	'wpcc_operation_requests',
	'wpcc_operation_results',
	'wpcc_patches',
	'wpcc_proposals',
	'wpcc_recommendations',
	'wpcc_snapshots',
	/*
	 * Created lazily by TelemetryStore on its first write (CREATE TABLE IF NOT
	 * EXISTS), deliberately decoupled from Schema::DB_VERSION — so it is absent
	 * from the schema installer and was absent here too. The consequence: a site
	 * that had ever recorded telemetry kept an orphaned `wpcc_telemetry` table
	 * after the owner explicitly opted into deleting all data. Uninstall must
	 * honour that choice completely; DROP TABLE IF EXISTS is a no-op where the
	 * lazy table was never created.
	 */
	'wpcc_telemetry',
];

/** Upload subdirectories created by the plugin. */
const WPCC_UNINSTALL_UPLOAD_DIRS = [
	'wpcc-tokens',
	'wpcc-patches',
	'wpcc-audit',
	'wpcc-snapshots',
	'wpcc-media-snapshots',
	'wpcc-plugin-backups',
];

/** The queue worker's cron hook (mirrors OperationWorker::CRON_HOOK). */
const WPCC_UNINSTALL_CRON_HOOK = 'wpcc_process_operation_queue';

/**
 * Clean up one site. Called once normally, or once per site on multisite.
 */
function wpcc_uninstall_site(): void {
	global $wpdb;

	// Always: a scheduled job pointing at code that no longer exists is pure
	// breakage, so it goes regardless of the retention choice.
	wp_clear_scheduled_hook( WPCC_UNINSTALL_CRON_HOOK );

	$purge = (bool) get_option( 'wpcc_delete_data_on_uninstall', false );
	if ( ! $purge ) {
		return;
	}

	// Tables.
	foreach ( WPCC_UNINSTALL_TABLES as $table ) {
		$name = $wpdb->prefix . $table;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a fixed const list; identifiers cannot be bound.
		$wpdb->query( "DROP TABLE IF EXISTS `{$name}`" );
	}

	// Options + transients. Matched by prefix so a forgotten key cannot survive
	// the purge the user explicitly asked for.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery -- one-off uninstall cleanup.
	$option_names = $wpdb->get_col(
		"SELECT option_name FROM {$wpdb->options}
		 WHERE option_name LIKE 'wpcc\\_%'
		    OR option_name LIKE '\\_transient\\_wpcc\\_%'
		    OR option_name LIKE '\\_transient\\_timeout\\_wpcc\\_%'"
	);
	foreach ( (array) $option_names as $option_name ) {
		delete_option( $option_name );
	}

	// Per-user admin state (e.g. the dismissed first-run guide).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery -- one-off uninstall cleanup.
	$meta_keys = $wpdb->get_col(
		"SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE 'wpcc\\_%'"
	);
	foreach ( (array) $meta_keys as $meta_key ) {
		delete_metadata( 'user', 0, $meta_key, '', true );
	}

	// Upload directories (tokens, audit log, snapshots, patch backups).
	$upload_dir = wp_upload_dir();
	if ( empty( $upload_dir['error'] ) && ! empty( $upload_dir['basedir'] ) ) {
		foreach ( WPCC_UNINSTALL_UPLOAD_DIRS as $dir_name ) {
			wpcc_uninstall_rmdir( trailingslashit( $upload_dir['basedir'] ) . $dir_name );
		}
	}
}

/**
 * Recursively delete a directory. Refuses to follow symlinks, and unlinks them
 * instead of descending — a symlinked storage directory must never turn an
 * uninstall into a delete of something outside the uploads folder.
 */
function wpcc_uninstall_rmdir( string $path ): void {
	if ( '' === $path || ! file_exists( $path ) ) {
		return;
	}
	if ( is_link( $path ) ) {
		wp_delete_file( $path );
		return;
	}
	if ( ! is_dir( $path ) ) {
		wp_delete_file( $path );
		return;
	}

	$entries = scandir( $path );
	if ( false !== $entries ) {
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			wpcc_uninstall_rmdir( trailingslashit( $path ) . $entry );
		}
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged -- removes a now-empty directory this plugin created. WP_Filesystem requires credentialed initialisation that is not available on the uninstall path.
	@rmdir( $path );
}

if ( is_multisite() ) {
	$wpcc_sites = get_sites( [ 'fields' => 'ids', 'number' => 0 ] );
	foreach ( $wpcc_sites as $wpcc_site_id ) {
		switch_to_blog( (int) $wpcc_site_id );
		wpcc_uninstall_site();
		restore_current_blog();
	}
} else {
	wpcc_uninstall_site();
}
