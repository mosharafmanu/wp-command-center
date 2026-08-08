<?php
/**
 * Return WP Command Center to its first-install state.
 *
 * Run with:  wp eval-file scripts/wpcc-reset-to-fresh.php
 *
 * WHAT A FRESH INSTALL ACTUALLY IS, per Core\Activator::activate():
 *
 *   1. Schema::install()            → the wpcc_* tables + `wpcc_db_version`, and the
 *                                     two migration guards, which a fresh install sets
 *                                     immediately (they no-op and mark themselves done).
 *   2. wp_schedule_event(...)       → the queue worker's cron entry.
 *   3. add_option('wpcc_security_mode', MODE_CLIENT)  → Standard protection.
 *
 * Everything else the plugin stores is created LAZILY on first use. So a faithful
 * reset is: keep those four things, empty the tables, and remove every lazily
 * created option, user-meta key and upload file.
 *
 * Tables are TRUNCATEd, never dropped — the brief preserves schema and migration
 * history, and a dropped table would also make `wpcc_db_version` a lie.
 *
 * SCOPE is the uninstaller's ownership list and nothing else: `wpcc_*` tables,
 * `wpcc_*` options/transients, `wpcc_*` user meta, six `wpcc-*` upload dirs. No
 * post, page, media item, user, WooCommerce record, or other plugin's data is
 * read or written. The prefix is the guarantee.
 */

defined( 'ABSPATH' ) || exit;

global $wpdb;

$dry = in_array( '--dry-run', (array) $args, true );

/** Tables to empty (mirrors WPCC_UNINSTALL_TABLES). */
$tables = [
	'agent_actions', 'agent_plan_steps', 'agent_plans', 'agent_sessions', 'agent_tasks',
	'change_log', 'health_verifications', 'idempotency', 'operation_queue',
	'operation_requests', 'operation_results', 'patches', 'proposals',
	'recommendations', 'snapshots', 'telemetry',
];

/**
 * Options a genuine fresh install HAS. Everything else wpcc_* is lazily created
 * runtime state and must go.
 *
 * `wpcc_migrated_v1` and `wpcc_changelog_backfilled` are migration guards, not
 * data. Schema::install() sets both on a brand-new site (each migration finds
 * nothing to do and records that it ran). Deleting them would make the next load
 * re-run migrations against already-current tables — the opposite of faithful.
 */
$keep = [
	'wpcc_db_version',
	'wpcc_migrated_v1',
	'wpcc_changelog_backfilled',
	'wpcc_security_mode', // reset to the release default below, not preserved as-is.
];

WP_CLI::log( $dry ? '── DRY RUN — nothing will be modified ──' : '── RESETTING ──' );

// ── 1. Empty the tables ───────────────────────────────────────────────────────
$emptied = 0;
foreach ( $tables as $t ) {
	$name = $wpdb->prefix . 'wpcc_' . $t;
	if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) ) {
		continue;
	}
	$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$name}`" );
	if ( 0 === $n ) {
		continue;
	}
	if ( ! $dry ) {
		$wpdb->query( "TRUNCATE TABLE `{$name}`" );
	}
	$emptied += $n;
	WP_CLI::log( sprintf( '  table  %-24s -%s rows', $t, number_format( $n ) ) );
}

// ── 2. Options ────────────────────────────────────────────────────────────────
$opts = $wpdb->get_col(
	"SELECT option_name FROM {$wpdb->options}
	 WHERE option_name LIKE 'wpcc\\_%'
	    OR option_name LIKE '\\_transient\\_wpcc\\_%'
	    OR option_name LIKE '\\_transient\\_timeout\\_wpcc\\_%'"
);
$removed_opts = 0;
foreach ( (array) $opts as $o ) {
	if ( in_array( $o, $keep, true ) ) {
		continue;
	}
	if ( ! $dry ) {
		delete_option( $o );
	}
	++$removed_opts;
	WP_CLI::log( '  option -' . $o );
}

// ── 3. User meta ──────────────────────────────────────────────────────────────
$meta_keys = $wpdb->get_col( "SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE 'wpcc\\_%'" );
foreach ( (array) $meta_keys as $mk ) {
	if ( ! $dry ) {
		delete_metadata( 'user', 0, $mk, '', true );
	}
	WP_CLI::log( '  meta   -' . $mk );
}

// ── 4. Upload directories ─────────────────────────────────────────────────────
// Removed entirely, not emptied: the plugin creates each on first write, so their
// absence IS the fresh-install state. wpcc-tokens holding no manifest is what
// makes "no assistant has ever connected" true rather than merely displayed.
$dirs = [ 'wpcc-tokens', 'wpcc-patches', 'wpcc-audit', 'wpcc-snapshots', 'wpcc-media-snapshots', 'wpcc-plugin-backups' ];
$up   = wp_upload_dir();
$files_removed = 0;
if ( empty( $up['error'] ) && ! empty( $up['basedir'] ) ) {
	foreach ( $dirs as $d ) {
		$path = trailingslashit( $up['basedir'] ) . $d;
		if ( ! is_dir( $path ) ) {
			continue;
		}
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		$c = 0;
		foreach ( $it as $f ) {
			if ( $f->isFile() || $f->isLink() ) {
				++$c;
				if ( ! $dry ) {
					@unlink( $f->getPathname() );
				}
			} elseif ( $f->isDir() && ! $dry ) {
				@rmdir( $f->getPathname() );
			}
		}
		if ( ! $dry ) {
			@rmdir( $path );
		}
		$files_removed += $c;
		WP_CLI::log( sprintf( '  files  %-24s -%d files', $d, $c ) );
	}
}

// ── 5. Release defaults ───────────────────────────────────────────────────────
// Standard protection, exactly as Activator seeds it on a fresh install.
if ( ! $dry ) {
	update_option( 'wpcc_security_mode', \WPCommandCenter\Operations\SecurityModeManager::MODE_CLIENT );

	// A fresh install schedules the queue worker. Re-seed it if the reset or any
	// earlier test run left it unscheduled — an unscheduled worker is a queue that
	// silently never drains.
	if ( ! wp_next_scheduled( \WPCommandCenter\Operations\OperationWorker::CRON_HOOK ) ) {
		wp_schedule_event( time(), 'wpcc_five_minutes', \WPCommandCenter\Operations\OperationWorker::CRON_HOOK );
	}

	wp_cache_flush();
}

WP_CLI::success( sprintf(
	'%s%s rows emptied, %d options removed, %d files removed.',
	$dry ? '[DRY RUN] ' : '',
	number_format( $emptied ),
	$removed_opts,
	$files_removed
) );
