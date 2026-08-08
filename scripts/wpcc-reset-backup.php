<?php
/**
 * Full, restorable backup of everything WP Command Center owns.
 *
 * Run with:  wp eval-file scripts/wpcc-reset-backup.php <dest-dir>
 *
 * Exists because `wp db export` / `mysqldump` cannot authenticate under AMPPS on
 * this machine (ERROR 1045 for root@localhost), so the usual "take a dump before
 * you delete anything" step is unavailable. $wpdb authenticates fine — it uses
 * the wp-config credentials directly — so the backup is taken through it instead.
 *
 * Scope is exactly the uninstaller's ownership list: the wpcc_* tables, the
 * wpcc_* options and transients, wpcc_* user meta, and the six upload
 * directories. Nothing outside that is read or written.
 *
 * Reads only. It never modifies the database.
 */

defined( 'ABSPATH' ) || exit;

global $wpdb;

$dest = isset( $args[0] ) ? rtrim( (string) $args[0], '/' ) : '';
if ( '' === $dest ) {
	WP_CLI::error( 'Usage: wp eval-file scripts/wpcc-reset-backup.php <dest-dir>' );
}
if ( ! is_dir( $dest ) && ! mkdir( $dest, 0755, true ) && ! is_dir( $dest ) ) {
	WP_CLI::error( "Cannot create {$dest}" );
}

$tables = [
	'agent_actions', 'agent_plan_steps', 'agent_plans', 'agent_sessions', 'agent_tasks',
	'change_log', 'health_verifications', 'idempotency', 'operation_queue',
	'operation_requests', 'operation_results', 'patches', 'proposals',
	'recommendations', 'snapshots', 'telemetry',
];

$manifest = [
	'generated_at' => gmdate( 'c' ),
	'site_url'     => get_site_url(),
	'db_prefix'    => $wpdb->prefix,
	'tables'       => [],
	'options'      => 0,
	'user_meta'    => 0,
];

// ── Tables ────────────────────────────────────────────────────────────────────
// Streamed in chunks as newline-delimited JSON: one 55k-row table held in memory
// as a single array is how a backup script becomes the reason you needed one.
foreach ( $tables as $t ) {
	$name = $wpdb->prefix . 'wpcc_' . $t;
	if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) ) {
		$manifest['tables'][ $t ] = [ 'rows' => 0, 'present' => false ];
		continue;
	}

	$create = $wpdb->get_row( "SHOW CREATE TABLE `{$name}`", ARRAY_N );
	file_put_contents( "{$dest}/schema-{$t}.sql", ( $create[1] ?? '' ) . ";\n" );

	$fh     = fopen( "{$dest}/data-{$t}.ndjson", 'w' );
	$offset = 0;
	$rows   = 0;
	do {
		$batch = $wpdb->get_results( "SELECT * FROM `{$name}` LIMIT 1000 OFFSET {$offset}", ARRAY_A );
		foreach ( (array) $batch as $r ) {
			fwrite( $fh, wp_json_encode( $r ) . "\n" );
			++$rows;
		}
		$offset += 1000;
	} while ( ! empty( $batch ) );
	fclose( $fh );

	$manifest['tables'][ $t ] = [ 'rows' => $rows, 'present' => true ];
	WP_CLI::log( sprintf( '  %-24s %d rows', $t, $rows ) );
}

// ── Options + transients ──────────────────────────────────────────────────────
// Raw option_value straight out of the table: get_option() would unserialize and
// a re-serialize on restore is a chance to change the bytes. This includes the
// live API key, which is the single genuinely irreplaceable value on the site.
$opt_rows = $wpdb->get_results(
	"SELECT option_name, option_value, autoload FROM {$wpdb->options}
	 WHERE option_name LIKE 'wpcc\\_%'
	    OR option_name LIKE '\\_transient\\_wpcc\\_%'
	    OR option_name LIKE '\\_transient\\_timeout\\_wpcc\\_%'",
	ARRAY_A
);
file_put_contents( "{$dest}/options.json", wp_json_encode( $opt_rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
$manifest['options'] = count( (array) $opt_rows );

// ── User meta ─────────────────────────────────────────────────────────────────
$meta_rows = $wpdb->get_results(
	"SELECT umeta_id, user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key LIKE 'wpcc\\_%'",
	ARRAY_A
);
file_put_contents( "{$dest}/usermeta.json", wp_json_encode( $meta_rows, JSON_PRETTY_PRINT ) );
$manifest['user_meta'] = count( (array) $meta_rows );

file_put_contents( "{$dest}/manifest.json", wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );

WP_CLI::success( sprintf(
	'Backed up %d tables, %d options, %d user-meta rows to %s',
	count( $manifest['tables'] ),
	$manifest['options'],
	$manifest['user_meta'],
	$dest
) );
