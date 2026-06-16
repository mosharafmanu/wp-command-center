<?php
/**
 * Database schema for patches, rollback snapshots, and the agent runtime.
 *
 * `wpcc_patches` and `wpcc_snapshots` are queryable indexes/metadata only —
 * the JSON files under wp-content/uploads/wpcc-patches/ and
 * wp-content/uploads/wpcc-snapshots/ remain the primary content store
 * (full diffs, original/modified file contents, status history).
 */

namespace WPCommandCenter\Core;

defined( 'ABSPATH' ) || exit;

final class Schema {

	public const DB_VERSION = '2.3.0';

	/**
	 * Install schema changes when the active plugin code is newer than the
	 * stored database version. This covers upgrades without reactivation.
	 */
	public static function maybe_upgrade(): void {
		if ( self::DB_VERSION !== get_option( 'wpcc_db_version' ) ) {
			self::install();
		}

		// STEP 104.3 — one-time historical backfill of the change log. Runs once
		// (flag-guarded) on the first load after the feature ships, independent of
		// the DB version gate above, so a site already at 2.3.0 (table created in
		// 104.1) still gets seeded.
		self::maybe_backfill_change_log();
	}

	/**
	 * Create/upgrade the plugin's tables and migrate any existing
	 * JSON-manifest data into them. Safe to call on every activation.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate  = $wpdb->get_charset_collate();
		$patches_table    = $wpdb->prefix . 'wpcc_patches';
		$snapshots_table  = $wpdb->prefix . 'wpcc_snapshots';
		$sessions_table   = $wpdb->prefix . 'wpcc_agent_sessions';
		$tasks_table      = $wpdb->prefix . 'wpcc_agent_tasks';
		$plans_table      = $wpdb->prefix . 'wpcc_agent_plans';
		$plan_steps_table = $wpdb->prefix . 'wpcc_agent_plan_steps';
		$actions_table    = $wpdb->prefix . 'wpcc_agent_actions';
		$requests_table   = $wpdb->prefix . 'wpcc_operation_requests';
		$queue_table      = $wpdb->prefix . 'wpcc_operation_queue';
		$results_table    = $wpdb->prefix . 'wpcc_operation_results';
		$recommendations_table = $wpdb->prefix . 'wpcc_recommendations';
		$health_results_table = $wpdb->prefix . 'wpcc_health_verifications';
		$change_log_table     = $wpdb->prefix . 'wpcc_change_log';

		dbDelta( "CREATE TABLE {$patches_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			patch_id VARCHAR(36) NOT NULL,
			session_id VARCHAR(36) NULL,
			task_id VARCHAR(36) NULL,
			plan_id VARCHAR(36) NULL,
			source VARCHAR(20) NOT NULL,
			risk_level VARCHAR(20) NOT NULL,
			status VARCHAR(20) NOT NULL,
			explanation TEXT NULL,
			target_files TEXT NULL,
			created_at BIGINT UNSIGNED NOT NULL,
			updated_at BIGINT UNSIGNED NOT NULL,
			approved_at BIGINT UNSIGNED NULL,
			applied_at BIGINT UNSIGNED NULL,
			rolled_back_at BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY patch_id (patch_id),
			KEY session_id (session_id),
			KEY task_id (task_id),
			KEY plan_id (plan_id),
			KEY status (status)
		) {$charset_collate};" );

		dbDelta( "CREATE TABLE {$requests_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			request_id VARCHAR(36) NOT NULL,
			operation_id VARCHAR(50) NOT NULL,
			session_id VARCHAR(36) NULL,
			task_id VARCHAR(36) NULL,
			action_id VARCHAR(36) NULL,
			plan_id VARCHAR(36) NULL,
			status VARCHAR(20) NOT NULL,
			payload LONGTEXT NOT NULL,
			risk_level VARCHAR(20) NOT NULL,
			created_at BIGINT UNSIGNED NOT NULL,
			approved_at BIGINT UNSIGNED NULL,
			rejected_at BIGINT UNSIGNED NULL,
			executed_at BIGINT UNSIGNED NULL,
			failed_at BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY request_id (request_id),
			KEY operation_id (operation_id),
			KEY session_id (session_id),
			KEY status (status)
		) {$charset_collate};" );

		dbDelta( "CREATE TABLE {$results_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			result_id VARCHAR(36) NOT NULL,
			queue_id VARCHAR(36) NULL,
			request_id VARCHAR(36) NULL,
			operation_id VARCHAR(50) NOT NULL,
			status VARCHAR(20) NOT NULL,
			execution_time_ms INT UNSIGNED NOT NULL DEFAULT 0,
			created_count INT UNSIGNED NOT NULL DEFAULT 0,
			updated_count INT UNSIGNED NOT NULL DEFAULT 0,
			skipped_count INT UNSIGNED NOT NULL DEFAULT 0,
			error_count INT UNSIGNED NOT NULL DEFAULT 0,
			result_json LONGTEXT NULL,
			error_json LONGTEXT NULL,
			started_at BIGINT UNSIGNED NOT NULL,
			completed_at BIGINT UNSIGNED NULL,
			created_at BIGINT UNSIGNED NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY result_id (result_id),
			KEY queue_id (queue_id),
			KEY request_id (request_id),
			KEY operation_id (operation_id),
			KEY status (status)
		) {$charset_collate};" );

		dbDelta( "CREATE TABLE {$queue_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			queue_id VARCHAR(36) NOT NULL,
			request_id VARCHAR(36) NOT NULL,
			operation_id VARCHAR(50) NOT NULL,
			status VARCHAR(20) NOT NULL,
			priority INT UNSIGNED NOT NULL DEFAULT 10,
			attempts INT UNSIGNED NOT NULL DEFAULT 0,
			max_attempts INT UNSIGNED NOT NULL DEFAULT 3,
			payload LONGTEXT NOT NULL,
			result LONGTEXT NULL,
			error_message TEXT NULL,
			created_at BIGINT UNSIGNED NOT NULL,
			started_at BIGINT UNSIGNED NULL,
			completed_at BIGINT UNSIGNED NULL,
			failed_at BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY queue_id (queue_id),
			KEY request_id (request_id),
			KEY operation_id (operation_id),
			KEY status (status)
		) {$charset_collate};" );

		dbDelta( "CREATE TABLE {$snapshots_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			snapshot_id VARCHAR(36) NOT NULL,
			patch_id VARCHAR(36) NULL,
			file_path VARCHAR(500) NOT NULL,
			backup_path VARCHAR(255) NOT NULL,
			label VARCHAR(255) NULL,
			size BIGINT UNSIGNED NOT NULL,
			hash VARCHAR(64) NOT NULL,
			created_at BIGINT UNSIGNED NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY snapshot_id (snapshot_id),
			KEY patch_id (patch_id)
		) {$charset_collate};" );

		dbDelta( "CREATE TABLE {$sessions_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id VARCHAR(36) NOT NULL,
			source VARCHAR(20) NOT NULL,
			label VARCHAR(255) NOT NULL,
			status VARCHAR(20) NOT NULL,
			created_at BIGINT UNSIGNED NOT NULL,
			updated_at BIGINT UNSIGNED NOT NULL,
			expires_at BIGINT UNSIGNED NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY session_id (session_id),
			KEY status (status),
			KEY expires_at (expires_at)
		) {$charset_collate};" );

		dbDelta( "CREATE TABLE {$tasks_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			task_id VARCHAR(36) NOT NULL,
			session_id VARCHAR(36) NOT NULL,
			source VARCHAR(20) NOT NULL,
			user_prompt TEXT NOT NULL,
			status VARCHAR(20) NOT NULL,
			created_at BIGINT UNSIGNED NOT NULL,
			updated_at BIGINT UNSIGNED NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY task_id (task_id),
			KEY session_id (session_id),
			KEY status (status)
		) {$charset_collate};" );

		dbDelta( "CREATE TABLE {$plans_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			plan_id VARCHAR(36) NOT NULL,
			session_id VARCHAR(36) NOT NULL,
			task_id VARCHAR(36) NOT NULL,
			action_id VARCHAR(36) NULL,
			title VARCHAR(255) NOT NULL,
			objective TEXT NOT NULL,
			status VARCHAR(20) NOT NULL,
			created_at BIGINT UNSIGNED NOT NULL,
			updated_at BIGINT UNSIGNED NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY plan_id (plan_id),
			KEY session_id (session_id),
			KEY task_id (task_id),
			KEY action_id (action_id),
			KEY status (status)
		) {$charset_collate};" );

		dbDelta( "CREATE TABLE {$plan_steps_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			plan_id VARCHAR(36) NOT NULL,
			step_order INT UNSIGNED NOT NULL,
			title VARCHAR(255) NOT NULL,
			description TEXT NOT NULL,
			status VARCHAR(20) NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY plan_step (plan_id, step_order),
			KEY plan_id (plan_id),
			KEY status (status)
		) {$charset_collate};" );

		dbDelta( "CREATE TABLE {$actions_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			action_id VARCHAR(36) NOT NULL,
			session_id VARCHAR(36) NOT NULL,
			task_id VARCHAR(36) NOT NULL,
			type VARCHAR(32) NOT NULL,
			title VARCHAR(255) NOT NULL,
			description TEXT NULL,
			status VARCHAR(20) NOT NULL,
			created_at BIGINT UNSIGNED NOT NULL,
			updated_at BIGINT UNSIGNED NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY action_id (action_id),
			KEY session_id (session_id),
			KEY task_id (task_id),
			KEY status (status)
		) {$charset_collate};" );

		dbDelta( "CREATE TABLE {$recommendations_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			recommendation_id VARCHAR(36) NOT NULL,
			action_id VARCHAR(36) NULL,
			plan_id VARCHAR(36) NULL,
			type VARCHAR(32) NOT NULL,
			severity VARCHAR(20) NOT NULL,
			title VARCHAR(255) NOT NULL,
			description TEXT NOT NULL,
			impact TEXT NOT NULL,
			suggested_action TEXT NOT NULL,
			source VARCHAR(64) NOT NULL,
			status VARCHAR(24) NOT NULL,
			context_json LONGTEXT NULL,
			created_at BIGINT UNSIGNED NOT NULL,
			updated_at BIGINT UNSIGNED NOT NULL,
			dismissed_at BIGINT UNSIGNED NULL,
			resolved_at BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY recommendation_id (recommendation_id),
			KEY action_id (action_id),
			KEY plan_id (plan_id),
			KEY type (type),
			KEY severity (severity),
			KEY source (source),
			KEY status (status)
		) {$charset_collate};" );

		dbDelta( "CREATE TABLE {$health_results_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			verification_id VARCHAR(36) NOT NULL,
			status VARCHAR(20) NOT NULL,
			checks_json LONGTEXT NOT NULL,
			summary_json LONGTEXT NOT NULL,
			started_at BIGINT UNSIGNED NOT NULL,
			completed_at BIGINT UNSIGNED NULL,
			created_at BIGINT UNSIGNED NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY verification_id (verification_id),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset_collate};" );

		// STEP 104.1 — Change-log system of record. A queryable metadata index
		// over every executed *mutating* operation, with rollback linkage. No
		// content blobs: full diffs/snapshots stay in their existing stores; this
		// table points at them (mirrors wpcc_patches / wpcc_snapshots). Additive
		// and idempotent via dbDelta — no destructive migration.
		dbDelta( "CREATE TABLE {$change_log_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			change_id VARCHAR(36) NOT NULL,
			operation_id VARCHAR(50) NOT NULL,
			action VARCHAR(64) NULL,
			runtime VARCHAR(40) NULL,
			status VARCHAR(24) NOT NULL,
			reversible TINYINT UNSIGNED NOT NULL DEFAULT 0,
			rollback_kind VARCHAR(20) NOT NULL DEFAULT 'none',
			rollback_id VARCHAR(36) NULL,
			rolled_back_by_change_id VARCHAR(36) NULL,
			change_set_id VARCHAR(36) NULL,
			request_id VARCHAR(36) NULL,
			session_id VARCHAR(36) NULL,
			task_id VARCHAR(36) NULL,
			plan_id VARCHAR(36) NULL,
			action_id VARCHAR(36) NULL,
			actor_json TEXT NULL,
			risk_level VARCHAR(20) NULL,
			source VARCHAR(20) NULL,
			target_summary TEXT NULL,
			target_key VARCHAR(190) NULL,
			created_count INT UNSIGNED NOT NULL DEFAULT 0,
			updated_count INT UNSIGNED NOT NULL DEFAULT 0,
			skipped_count INT UNSIGNED NOT NULL DEFAULT 0,
			error_count INT UNSIGNED NOT NULL DEFAULT 0,
			result_ref VARCHAR(36) NULL,
			created_at BIGINT UNSIGNED NOT NULL,
			rolled_back_at BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY change_id (change_id),
			KEY operation_id (operation_id),
			KEY runtime (runtime),
			KEY status (status),
			KEY change_set_id (change_set_id),
			KEY rollback_id (rollback_id),
			KEY target_key (target_key),
			KEY session_id (session_id),
			KEY created_at (created_at)
		) {$charset_collate};" );

		update_option( 'wpcc_db_version', self::DB_VERSION );

		self::migrate_legacy_data();
	}

	/**
	 * One-time, idempotent migration of the legacy JSON manifests into the
	 * new index tables. No-op on a fresh install (no manifests exist) and
	 * a no-op on repeat activations (guarded by the 'wpcc_migrated_v1' option).
	 */
	private static function migrate_legacy_data(): void {
		if ( get_option( 'wpcc_migrated_v1' ) ) {
			return;
		}

		global $wpdb;

		$upload_dir = wp_upload_dir();
		$base       = trailingslashit( $upload_dir['basedir'] );

		self::migrate_patches( $wpdb, $base . 'wpcc-patches/manifest.json' );
		self::migrate_snapshots( $wpdb, $base . 'wpcc-snapshots/manifest.json' );

		update_option( 'wpcc_migrated_v1', 1 );
	}

	private static function migrate_patches( \wpdb $wpdb, string $manifest_file ): void {
		if ( ! is_readable( $manifest_file ) ) {
			return;
		}

		$patches = json_decode( (string) file_get_contents( $manifest_file ), true );

		if ( ! is_array( $patches ) ) {
			return;
		}

		$table = $wpdb->prefix . 'wpcc_patches';

		foreach ( $patches as $patch ) {
			if ( ! isset( $patch['id'] ) ) {
				continue;
			}

			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE patch_id = %s", $patch['id'] ) );

			if ( $exists ) {
				continue;
			}

			$wpdb->insert(
				$table,
				[
					'patch_id'       => $patch['id'],
					'source'         => $patch['source'] ?? 'manual',
					'risk_level'     => $patch['risk_level'] ?? 'low',
					'status'         => $patch['status'] ?? 'draft',
					'explanation'    => '',
					'target_files'   => wp_json_encode( $patch['target_files'] ?? [] ),
					'created_at'     => $patch['created_at'] ?? time(),
					'updated_at'     => $patch['updated_at'] ?? ( $patch['created_at'] ?? time() ),
					'approved_at'    => null,
					'applied_at'     => $patch['applied_at'] ?? null,
					'rolled_back_at' => null,
				],
				[ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d' ]
			);
		}
	}

	private static function migrate_snapshots( \wpdb $wpdb, string $manifest_file ): void {
		if ( ! is_readable( $manifest_file ) ) {
			return;
		}

		$snapshots = json_decode( (string) file_get_contents( $manifest_file ), true );

		if ( ! is_array( $snapshots ) ) {
			return;
		}

		$table = $wpdb->prefix . 'wpcc_snapshots';

		foreach ( $snapshots as $snapshot ) {
			if ( ! isset( $snapshot['id'] ) ) {
				continue;
			}

			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE snapshot_id = %s", $snapshot['id'] ) );

			if ( $exists ) {
				continue;
			}

			$wpdb->insert(
				$table,
				[
					'snapshot_id' => $snapshot['id'],
					'patch_id'    => null,
					'file_path'   => $snapshot['path'] ?? '',
					'backup_path' => $snapshot['id'] . '.snapshot',
					'label'       => $snapshot['label'] ?? '',
					'size'        => $snapshot['size'] ?? 0,
					'hash'        => $snapshot['hash'] ?? '',
					'created_at'  => $snapshot['created_at'] ?? time(),
				],
				[ '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d' ]
			);
		}
	}

	/**
	 * STEP 104.3 — one-time, idempotent backfill of `wpcc_change_log` from the
	 * existing `wpcc_patches` and `wpcc_operation_results` tables so production
	 * has change history from day one. Read-only over the source tables; never
	 * touches any runtime. Guarded by the `wpcc_changelog_backfilled` option AND
	 * by deterministic change_ids (so a re-run — even with the flag cleared —
	 * inserts no duplicate rows). Historical timestamps are preserved.
	 */
	public static function maybe_backfill_change_log(): void {
		if ( get_option( 'wpcc_changelog_backfilled' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'wpcc_change_log';

		// Require the table to exist before seeding.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}

		self::backfill_from_patches( $wpdb, $table );
		self::backfill_from_results( $wpdb, $table );

		update_option( 'wpcc_changelog_backfilled', 1 );
	}

	/**
	 * Deterministic, UUID-shaped change_id for a backfill source key, so the
	 * UNIQUE(change_id) constraint makes re-runs idempotent.
	 */
	private static function backfill_change_id( string $source_key ): string {
		$h = md5( 'wpcc-changelog-backfill:' . $source_key );
		return substr( $h, 0, 8 ) . '-' . substr( $h, 8, 4 ) . '-' . substr( $h, 12, 4 ) . '-' . substr( $h, 16, 4 ) . '-' . substr( $h, 20, 12 );
	}

	/**
	 * Seed change rows from applied / rolled-back patches (with snapshot
	 * linkage). rollback_kind = patch; reversible when the patch is applied.
	 */
	private static function backfill_from_patches( \wpdb $wpdb, string $table ): void {
		$patches_table = $wpdb->prefix . 'wpcc_patches';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $patches_table ) ) !== $patches_table ) {
			return;
		}

		$rows = $wpdb->get_results(
			"SELECT patch_id, session_id, task_id, plan_id, source, risk_level, status, target_files, created_at, applied_at, rolled_back_at FROM {$patches_table} WHERE status IN ('applied','rolled_back')",
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $patch ) {
			$patch_id  = (string) $patch['patch_id'];
			$change_id = self::backfill_change_id( 'patch:' . $patch_id );

			// Idempotent: skip our own deterministic row, AND skip any patch that
			// was already recorded live (a change_log row already references this
			// change_set_id) so backfill never duplicates live history.
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT change_id FROM {$table} WHERE change_id = %s", $change_id ) )
				|| $wpdb->get_var( $wpdb->prepare( "SELECT change_id FROM {$table} WHERE change_set_id = %s LIMIT 1", $patch_id ) ) ) {
				continue;
			}

			$is_rolled_back = 'rolled_back' === $patch['status'];
			$paths          = json_decode( (string) $patch['target_files'], true );
			$paths          = is_array( $paths ) ? array_values( $paths ) : [];
			$target_key     = ! empty( $paths ) ? substr( (string) $paths[0], 0, 190 ) : null;
			$created_at     = (int) ( $patch['applied_at'] ?: $patch['created_at'] ?: time() );

			$wpdb->insert(
				$table,
				[
					'change_id'                => $change_id,
					'operation_id'             => 'patch_manage',
					'action'                   => 'patch_apply',
					'runtime'                  => 'patch',
					'status'                   => $is_rolled_back ? 'rolled_back' : 'applied',
					'reversible'               => $is_rolled_back ? 0 : 1,
					'rollback_kind'            => 'patch',
					'rollback_id'              => (string) $patch['patch_id'],
					'rolled_back_by_change_id' => null,
					'change_set_id'            => (string) $patch['patch_id'],
					'request_id'               => null,
					'session_id'               => $patch['session_id'] ?: null,
					'task_id'                  => $patch['task_id'] ?: null,
					'plan_id'                  => $patch['plan_id'] ?: null,
					'action_id'                => null,
					'actor_json'               => wp_json_encode( [ 'type' => 'backfill' ] ),
					'risk_level'               => substr( (string) ( $patch['risk_level'] ?: 'medium' ), 0, 20 ),
					'source'                   => substr( (string) ( $patch['source'] ?: 'backfill' ), 0, 20 ),
					'target_summary'           => wp_json_encode( array_filter( [ 'affected_paths' => $paths ?: null, 'backfilled' => true ] ) ),
					'target_key'               => $target_key,
					'created_count'            => 0,
					'updated_count'            => count( $paths ),
					'skipped_count'            => 0,
					'error_count'              => 0,
					'result_ref'               => null,
					'created_at'               => $created_at,
					'rolled_back_at'           => $is_rolled_back ? (int) ( $patch['rolled_back_at'] ?: $created_at ) : null,
				],
				[
					'%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s',
					'%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
					'%d', '%d', '%d', '%d', '%s', '%d', '%d',
				]
			);
		}
	}

	/**
	 * Seed change rows from operation results of mutating operations. Patch and
	 * rollback engines are excluded (covered by the patches table); purely
	 * diagnostic operations are skipped. No rollback linkage (results do not
	 * store it) — these rows are historical evidence, not reversible handles.
	 */
	private static function backfill_from_results( \wpdb $wpdb, string $table ): void {
		$results_table = $wpdb->prefix . 'wpcc_operation_results';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $results_table ) ) !== $results_table ) {
			return;
		}

		// Purely diagnostic / patch-covered operations to skip.
		$skip = [
			'system_info', 'database_inspect', 'report_manage', 'file_manage', 'code_search',
			'search_manage', 'media_enhance', 'change_history', 'patch_manage', 'rollback_manage',
		];

		$rows = $wpdb->get_results(
			"SELECT result_id, operation_id, request_id, status, created_count, updated_count, skipped_count, error_count, created_at FROM {$results_table}",
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $res ) {
			$operation_id = (string) $res['operation_id'];

			if ( in_array( $operation_id, $skip, true ) ) {
				continue;
			}

			$result_id = (string) $res['result_id'];
			$change_id = self::backfill_change_id( 'result:' . $result_id );

			// Idempotent: skip our own deterministic row, AND skip any result that
			// was already recorded live (a change_log row already references this
			// result_ref) so backfill never duplicates live history.
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT change_id FROM {$table} WHERE change_id = %s", $change_id ) )
				|| $wpdb->get_var( $wpdb->prepare( "SELECT change_id FROM {$table} WHERE result_ref = %s LIMIT 1", $result_id ) ) ) {
				continue;
			}

			$runtime = (string) preg_replace( '/_manage$/', '', $operation_id );
			$status  = 'failed' === $res['status'] ? 'failed' : 'applied';

			$wpdb->insert(
				$table,
				[
					'change_id'                => $change_id,
					'operation_id'             => substr( $operation_id, 0, 50 ),
					'action'                   => null,
					'runtime'                  => substr( $runtime, 0, 40 ),
					'status'                   => $status,
					'reversible'               => 0,
					'rollback_kind'            => 'none',
					'rollback_id'              => null,
					'rolled_back_by_change_id' => null,
					'change_set_id'            => null,
					'request_id'               => $res['request_id'] ?: null,
					'session_id'               => null,
					'task_id'                  => null,
					'plan_id'                  => null,
					'action_id'                => null,
					'actor_json'               => wp_json_encode( [ 'type' => 'backfill' ] ),
					'risk_level'               => null,
					'source'                   => 'backfill',
					'target_summary'           => wp_json_encode( [ 'backfilled' => true ] ),
					'target_key'               => null,
					'created_count'            => (int) $res['created_count'],
					'updated_count'            => (int) $res['updated_count'],
					'skipped_count'            => (int) $res['skipped_count'],
					'error_count'              => (int) $res['error_count'],
					'result_ref'               => (string) $res['result_id'],
					'created_at'               => (int) ( $res['created_at'] ?: time() ),
					'rolled_back_at'           => null,
				],
				[
					'%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s',
					'%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
					'%d', '%d', '%d', '%d', '%s', '%d', '%d',
				]
			);
		}
	}
}
