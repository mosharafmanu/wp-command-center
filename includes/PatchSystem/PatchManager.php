<?php
/**
 * §8.5 Patch System (AI Coding Bridge) — stores patch records (proposed
 * file changes, explanation, risk level, diff) and their lifecycle status.
 *
 * Per-patch JSON files under wp-content/uploads/wpcc-patches/ (protected
 * from direct web access) remain the primary content store — they hold the
 * full file contents, diffs, and status history. The {$wpdb->prefix}wpcc_patches
 * table is a queryable index/metadata mirror used for listing.
 */

namespace WPCommandCenter\PatchSystem;

use WPCommandCenter\Security\AuditLog;
use WPCommandCenter\Security\PathGuard;

defined( 'ABSPATH' ) || exit;

final class PatchManager {

	public const STATUS_DRAFT            = 'draft';
	public const STATUS_PENDING_APPROVAL = 'pending_approval';
	public const STATUS_APPROVED         = 'approved';
	public const STATUS_REJECTED         = 'rejected';
	public const STATUS_APPLIED          = 'applied';
	public const STATUS_FAILED           = 'failed';
	public const STATUS_ROLLED_BACK      = 'rolled_back';

	public const SOURCE_CLAUDE = 'claude';
	public const SOURCE_CODEX  = 'codex';
	public const SOURCE_MANUAL = 'manual';
	public const SOURCE_API    = 'api';

	public const RISK_LOW    = 'low';
	public const RISK_MEDIUM = 'medium';
	public const RISK_HIGH   = 'high';

	private const VALID_STATUSES = [
		self::STATUS_DRAFT,
		self::STATUS_PENDING_APPROVAL,
		self::STATUS_APPROVED,
		self::STATUS_REJECTED,
		self::STATUS_APPLIED,
		self::STATUS_FAILED,
		self::STATUS_ROLLED_BACK,
	];

	private const VALID_SOURCES = [
		self::SOURCE_CLAUDE,
		self::SOURCE_CODEX,
		self::SOURCE_MANUAL,
		self::SOURCE_API,
	];

	private const VALID_RISK_LEVELS = [
		self::RISK_LOW,
		self::RISK_MEDIUM,
		self::RISK_HIGH,
	];

	/**
	 * Mirrors RestApi::PLAN_STATUS_APPROVED — a plan must be in this status
	 * before a patch can be linked to it.
	 */
	private const PLAN_STATUS_APPROVED = 'approved';

	private const DIR_NAME       = 'wpcc-patches';
	private const MAX_FILE_BYTES = 2 * MB_IN_BYTES;

	private PathGuard $path_guard;
	private DiffGenerator $diff_generator;
	private AuditLog $audit_log;

	public function __construct() {
		$this->path_guard     = new PathGuard();
		$this->diff_generator = new DiffGenerator();
		$this->audit_log      = new AuditLog();
	}

	public static function status_label( string $status ): string {
		$labels = [
			self::STATUS_DRAFT            => __( 'Draft', 'wp-command-center' ),
			self::STATUS_PENDING_APPROVAL => __( 'Pending Approval', 'wp-command-center' ),
			self::STATUS_APPROVED         => __( 'Approved', 'wp-command-center' ),
			self::STATUS_REJECTED         => __( 'Rejected', 'wp-command-center' ),
			self::STATUS_APPLIED          => __( 'Applied', 'wp-command-center' ),
			self::STATUS_FAILED           => __( 'Failed', 'wp-command-center' ),
			self::STATUS_ROLLED_BACK      => __( 'Rolled Back', 'wp-command-center' ),
		];

		return $labels[ $status ] ?? $status;
	}

	/**
	 * Render a patch status as an HTML badge (escaped).
	 */
	public static function status_badge( string $status ): string {
		$classes = [
			self::STATUS_DRAFT            => 'neutral',
			self::STATUS_PENDING_APPROVAL => 'recommended',
			self::STATUS_APPROVED         => 'info',
			self::STATUS_REJECTED         => 'neutral',
			self::STATUS_APPLIED          => 'good',
			self::STATUS_FAILED           => 'critical',
			self::STATUS_ROLLED_BACK      => 'neutral',
		];

		$class = $classes[ $status ] ?? 'neutral';

		return sprintf( '<span class="wpcc-badge wpcc-badge--%s">%s</span>', esc_attr( $class ), esc_html( self::status_label( $status ) ) );
	}

	public static function source_label( string $source ): string {
		$labels = [
			self::SOURCE_CLAUDE => __( 'Claude', 'wp-command-center' ),
			self::SOURCE_CODEX  => __( 'Codex', 'wp-command-center' ),
			self::SOURCE_MANUAL => __( 'Manual', 'wp-command-center' ),
			self::SOURCE_API    => __( 'API', 'wp-command-center' ),
		];

		return $labels[ $source ] ?? $source;
	}

	public static function risk_label( string $risk ): string {
		$labels = [
			self::RISK_LOW    => __( 'Low', 'wp-command-center' ),
			self::RISK_MEDIUM => __( 'Medium', 'wp-command-center' ),
			self::RISK_HIGH   => __( 'High', 'wp-command-center' ),
		];

		return $labels[ $risk ] ?? $risk;
	}

	/**
	 * Render a patch risk level as an HTML badge (escaped).
	 */
	public static function risk_badge( string $risk ): string {
		$classes = [
			self::RISK_LOW    => 'good',
			self::RISK_MEDIUM => 'recommended',
			self::RISK_HIGH   => 'critical',
		];

		$class = $classes[ $risk ] ?? 'neutral';

		return sprintf( '<span class="wpcc-badge wpcc-badge--%s">%s</span>', esc_attr( $class ), esc_html( self::risk_label( $risk ) ) );
	}

	/**
	 * Create a new patch from a set of proposed file changes.
	 *
	 * @param array<int, array{path: string, modified: string}> $files
	 * @param array<string, mixed>                               $actor Audit-log actor descriptor.
	 *
	 * @return array|\WP_Error Full patch record.
	 */
	public function create( array $files, string $explanation = '', string $risk_level = self::RISK_LOW, string $source = self::SOURCE_MANUAL, array $actor = [], ?string $session_id = null, ?string $task_id = null, ?string $plan_id = null ): array|\WP_Error {
		if ( empty( $files ) ) {
			return new \WP_Error( 'wpcc_no_files', __( 'A patch must include at least one file.', 'wp-command-center' ) );
		}

		$session_id = $session_id ?: null;
		$task_id    = $task_id ?: null;
		$plan_id    = $plan_id ?: null;

		if ( null !== $plan_id ) {
			$plan = $this->find_agent_plan( $plan_id );

			if ( null === $plan ) {
				return new \WP_Error( 'wpcc_plan_not_found', __( 'Agent plan not found.', 'wp-command-center' ) );
			}

			if ( self::PLAN_STATUS_APPROVED !== $plan['status'] ) {
				return new \WP_Error(
					'wpcc_plan_not_approved',
					__( 'Only an approved plan can be linked to a patch.', 'wp-command-center' )
				);
			}

			$session_id = $session_id ?: $plan['session_id'];
			$task_id    = $task_id ?: $plan['task_id'];
		}

		$validation = $this->validate_agent_relationships( $session_id, $task_id );

		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		if ( ! in_array( $risk_level, self::VALID_RISK_LEVELS, true ) ) {
			return new \WP_Error( 'wpcc_invalid_risk_level', __( 'Invalid risk level.', 'wp-command-center' ) );
		}

		if ( ! in_array( $source, self::VALID_SOURCES, true ) ) {
			return new \WP_Error( 'wpcc_invalid_source', __( 'Invalid patch source.', 'wp-command-center' ) );
		}

		$file_records = [];
		$has_changes  = false;

		foreach ( $files as $file ) {
			$path     = isset( $file['path'] ) ? trim( (string) $file['path'], '/' ) : '';
			$modified = (string) ( $file['modified'] ?? '' );

			if ( '' === $path ) {
				return new \WP_Error( 'wpcc_invalid_path', __( 'Each file must have a path.', 'wp-command-center' ) );
			}

			$real = $this->path_guard->resolve( $path );

			if ( is_wp_error( $real ) ) {
				return $real;
			}

			if ( ! is_file( $real ) || ! is_readable( $real ) ) {
				return new \WP_Error( 'wpcc_not_readable', __( 'File not found or not readable.', 'wp-command-center' ) );
			}

			if ( filesize( $real ) > self::MAX_FILE_BYTES ) {
				return new \WP_Error( 'wpcc_file_too_large', __( 'File is too large to patch.', 'wp-command-center' ) );
			}

			$original = (string) file_get_contents( $real );
			$diff     = $this->diff_generator->generate( $original, $modified, $path );

			if ( '' !== $diff ) {
				$has_changes = true;
			}

			$file_records[] = [
				'path'     => $path,
				'original' => $original,
				'modified' => $modified,
				'diff'     => $diff,
			];
		}

		if ( ! $has_changes ) {
			return new \WP_Error( 'wpcc_no_changes', __( 'The patch does not change any file.', 'wp-command-center' ) );
		}

		$dir = $this->get_storage_dir();

		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$id  = wp_generate_uuid4();
		$now = time();

		$record = [
			'id'             => $id,
			'session_id'     => $session_id,
			'task_id'        => $task_id,
			'plan_id'        => $plan_id,
			'source'         => $source,
			'status'         => self::STATUS_PENDING_APPROVAL,
			'risk_level'     => $risk_level,
			'explanation'    => sanitize_textarea_field( $explanation ),
			'files'          => $file_records,
			'created_at'     => $now,
			'updated_at'     => $now,
			'applied_at'     => null,
			'snapshot_ids'   => [],
			'verification'   => null,
			'status_history' => [
				[
					'status' => self::STATUS_PENDING_APPROVAL,
					'at'     => $now,
				],
			],
		];

		$this->write_record( $dir, $record );

		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'wpcc_patches',
			[
				'patch_id'       => $id,
				'session_id'     => $session_id,
				'task_id'        => $task_id,
				'plan_id'        => $plan_id,
				'source'         => $source,
				'risk_level'     => $risk_level,
				'status'         => self::STATUS_PENDING_APPROVAL,
				'explanation'    => $record['explanation'],
				'target_files'   => wp_json_encode( array_column( $file_records, 'path' ) ),
				'created_at'     => $now,
				'updated_at'     => $now,
				'approved_at'    => null,
				'applied_at'     => null,
				'rolled_back_at' => null,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d' ]
		);

		$this->audit_log->record(
			'patch.created',
			[
				'patch_id'   => $id,
				'session_id' => $session_id,
				'task_id'    => $task_id,
				'plan_id'    => $plan_id,
				'actor'      => AuditLog::resolve_actor( $actor ),
				'source'     => $source,
				'files'      => array_column( $file_records, 'path' ),
			]
		);

		return $record;
	}

	/**
	 * @return array<int, array> Patch summaries, newest first.
	 */
	public function list(): array {
		global $wpdb;

		$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wpcc_patches ORDER BY created_at DESC", ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return [];
		}

		return array_map( [ $this, 'row_to_summary' ], $rows );
	}

	/**
	 * @return array|\WP_Error Full patch record.
	 */
	public function get( string $id ): array|\WP_Error {
		$dir = $this->get_storage_dir();

		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$file = trailingslashit( $dir ) . $id . '.json';

		if ( ! is_readable( $file ) ) {
			return new \WP_Error( 'wpcc_patch_not_found', __( 'Patch not found.', 'wp-command-center' ) );
		}

		$record = json_decode( (string) file_get_contents( $file ), true );

		if ( ! is_array( $record ) ) {
			return new \WP_Error( 'wpcc_patch_corrupt', __( 'Patch record could not be read.', 'wp-command-center' ) );
		}

		$record['session_id'] ??= null;
		$record['task_id']    ??= null;
		$record['plan_id']    ??= null;

		return $record;
	}

	/**
	 * Update a patch's status and merge any extra fields (e.g.
	 * applied_at, snapshot_ids, verification) into its record. Appends an
	 * entry to the record's `status_history`.
	 *
	 * @return array|\WP_Error Full updated patch record.
	 */
	public function update_status( string $id, string $status, array $extra = [] ): array|\WP_Error {
		if ( ! in_array( $status, self::VALID_STATUSES, true ) ) {
			return new \WP_Error( 'wpcc_invalid_status', __( 'Invalid patch status.', 'wp-command-center' ) );
		}

		$record = $this->get( $id );

		if ( is_wp_error( $record ) ) {
			return $record;
		}

		$record               = array_merge( $record, $extra );
		$record['status']     = $status;
		$record['updated_at'] = time();

		$record['status_history']   ??= [];
		$record['status_history'][]   = [
			'status' => $status,
			'at'     => $record['updated_at'],
		];

		$dir = $this->get_storage_dir();

		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$this->write_record( $dir, $record );
		$this->sync_index_row( $record );

		return $record;
	}

	public function delete( string $id ): bool|\WP_Error {
		$dir = $this->get_storage_dir();

		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$file = trailingslashit( $dir ) . $id . '.json';

		if ( file_exists( $file ) ) {
			wp_delete_file( $file );
		}

		global $wpdb;

		$wpdb->delete( $wpdb->prefix . 'wpcc_patches', [ 'patch_id' => $id ] );

		return true;
	}

	/**
	 * @param array<string, mixed> $row
	 *
	 * @return array{id: string, source: string, status: string, risk_level: string, target_files: array<int, string>, created_at: int, updated_at: int, applied_at: ?int}
	 */
	public function row_to_summary( array $row ): array {
		return [
			'id'           => $row['patch_id'],
			'session_id'   => $row['session_id'] ?: null,
			'task_id'      => $row['task_id'] ?: null,
			'plan_id'      => $row['plan_id'] ?: null,
			'source'       => $row['source'],
			'status'       => $row['status'],
			'risk_level'   => $row['risk_level'],
			'target_files' => json_decode( $row['target_files'] ?: '[]', true ) ?: [],
			'created_at'   => (int) $row['created_at'],
			'updated_at'   => (int) $row['updated_at'],
			'applied_at'   => null !== $row['applied_at'] ? (int) $row['applied_at'] : null,
		];
	}

	/**
	 * @return array<int, array> Patch summaries matching the filter.
	 */
	public function list_by( string $field, string $value ): array {
		global $wpdb;

		if ( ! in_array( $field, [ 'session_id', 'task_id', 'plan_id' ], true ) ) {
			return [];
		}

		$table = $wpdb->prefix . 'wpcc_patches';
		$sql   = $wpdb->prepare( "SELECT * FROM {$table} WHERE {$field} = %s ORDER BY created_at DESC", $value );
		$rows  = $wpdb->get_results( $sql, ARRAY_A );

		return array_map( [ $this, 'row_to_summary' ], $rows ?: [] );
	}

	/**
	 * @return array{plan_id: string, session_id: ?string, task_id: ?string, status: string}|null
	 */
	private function find_agent_plan( string $plan_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT plan_id, session_id, task_id, status FROM {$wpdb->prefix}wpcc_agent_plans WHERE plan_id = %s", $plan_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	private function validate_agent_relationships( ?string $session_id, ?string $task_id ): bool|\WP_Error {
		global $wpdb;

		if ( null !== $session_id ) {
			$session_exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT session_id FROM {$wpdb->prefix}wpcc_agent_sessions WHERE session_id = %s",
				$session_id
			) );

			if ( ! $session_exists ) {
				return new \WP_Error( 'wpcc_session_not_found', __( 'Agent session not found.', 'wp-command-center' ) );
			}
		}

		if ( null !== $task_id ) {
			$task = $wpdb->get_row( $wpdb->prepare(
				"SELECT task_id, session_id FROM {$wpdb->prefix}wpcc_agent_tasks WHERE task_id = %s",
				$task_id
			), ARRAY_A );

			if ( ! is_array( $task ) ) {
				return new \WP_Error( 'wpcc_task_not_found', __( 'Agent task not found.', 'wp-command-center' ) );
			}

			if ( null !== $session_id && $task['session_id'] !== $session_id ) {
				return new \WP_Error(
					'wpcc_task_session_mismatch',
					__( 'The agent task does not belong to the supplied session.', 'wp-command-center' )
				);
			}
		}

		return true;
	}

	/**
	 * Mirror a patch record's status/timestamps into the wpcc_patches index row.
	 */
	private function sync_index_row( array $record ): void {
		global $wpdb;

		$extra_timestamps = [];

		switch ( $record['status'] ) {
			case self::STATUS_APPROVED:
				$extra_timestamps['approved_at'] = $record['updated_at'];
				break;

			case self::STATUS_APPLIED:
				$extra_timestamps['applied_at'] = $record['applied_at'] ?? $record['updated_at'];
				break;

			case self::STATUS_ROLLED_BACK:
				$extra_timestamps['rolled_back_at'] = $record['rolled_back_at'] ?? $record['updated_at'];
				break;
		}

		$wpdb->update(
			$wpdb->prefix . 'wpcc_patches',
			array_merge(
				[
					'status'     => $record['status'],
					'updated_at' => $record['updated_at'],
				],
				$extra_timestamps
			),
			[ 'patch_id' => $record['id'] ]
		);
	}

	/**
	 * Absolute path of the patch storage directory, creating it (and its
	 * protective files) on first use.
	 */
	private function get_storage_dir(): string|\WP_Error {
		$upload_dir = wp_upload_dir();

		if ( ! empty( $upload_dir['error'] ) ) {
			return new \WP_Error( 'wpcc_upload_dir_error', $upload_dir['error'] );
		}

		$dir = trailingslashit( $upload_dir['basedir'] ) . self::DIR_NAME;

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'wpcc_mkdir_failed', __( 'Failed to create the patch storage directory.', 'wp-command-center' ) );
		}

		$this->protect_directory( $dir );

		return $dir;
	}

	private function protect_directory( string $dir ): void {
		$htaccess = trailingslashit( $dir ) . '.htaccess';

		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Require all denied\nDeny from all\n" );
		}

		$index = trailingslashit( $dir ) . 'index.php';

		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}

	private function write_record( string $dir, array $record ): void {
		file_put_contents( trailingslashit( $dir ) . $record['id'] . '.json', (string) wp_json_encode( $record, JSON_PRETTY_PRINT ), LOCK_EX );
	}
}
