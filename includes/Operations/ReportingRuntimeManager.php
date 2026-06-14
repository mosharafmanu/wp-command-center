<?php
/**
 * STEP 98 — Reporting Runtime.
 *
 * Read-only operational reports for AI agents and admins: Site Health, Plugin
 * Health, Security posture, Content inventory, WooCommerce, and Agent / Approval
 * / Patch activity. Every action is diagnostic (no writes, no rollback). Activity
 * reports aggregate the append-only audit log; inventory reports read WordPress
 * core + plugin data. Reports degrade gracefully when a subsystem is absent
 * (e.g. WooCommerce inactive) rather than erroring.
 */

namespace WPCommandCenter\Operations;

use WPCommandCenter\Security\AuditLog;
use WPCommandCenter\Security\AuthTokens;

defined( 'ABSPATH' ) || exit;

final class ReportingRegistry {

	const A_LIST              = 'report_list';
	const A_SITE_HEALTH       = 'report_site_health';
	const A_PLUGIN_HEALTH     = 'report_plugin_health';
	const A_SECURITY          = 'report_security';
	const A_CONTENT           = 'report_content';
	const A_WOOCOMMERCE       = 'report_woocommerce';
	const A_AGENT_ACTIVITY    = 'report_agent_activity';
	const A_APPROVAL_ACTIVITY = 'report_approval_activity';
	const A_PATCH_ACTIVITY    = 'report_patch_activity';

	const ACTIONS = [
		self::A_LIST, self::A_SITE_HEALTH, self::A_PLUGIN_HEALTH, self::A_SECURITY,
		self::A_CONTENT, self::A_WOOCOMMERCE, self::A_AGENT_ACTIVITY,
		self::A_APPROVAL_ACTIVITY, self::A_PATCH_ACTIVITY,
	];

	/** Every report is read-only. */
	public static function get_risk( string $a ): string {
		return 'diagnostic';
	}
}

final class ReportingRuntimeManager {

	/** Default / max number of audit entries scanned for activity reports. */
	private const AUDIT_DEFAULT = 1000;
	private const AUDIT_MAX     = 5000;

	private AuditLog $audit;

	public function __construct() {
		$this->audit = new AuditLog();
	}

	public function run( array $p, array $cx = [] ): array|\WP_Error {
		$a = (string) ( $p['action'] ?? '' );
		if ( ! in_array( $a, ReportingRegistry::ACTIONS, true ) ) {
			return new \WP_Error( 'wpcc_invalid_report_action', __( 'Invalid report action.', 'wp-command-center' ) );
		}

		$report = match ( $a ) {
			ReportingRegistry::A_LIST              => $this->report_list(),
			ReportingRegistry::A_SITE_HEALTH       => $this->site_health(),
			ReportingRegistry::A_PLUGIN_HEALTH     => $this->plugin_health(),
			ReportingRegistry::A_SECURITY          => $this->security(),
			ReportingRegistry::A_CONTENT           => $this->content(),
			ReportingRegistry::A_WOOCOMMERCE       => $this->woocommerce(),
			ReportingRegistry::A_AGENT_ACTIVITY    => $this->agent_activity( $p ),
			ReportingRegistry::A_APPROVAL_ACTIVITY => $this->approval_activity( $p ),
			ReportingRegistry::A_PATCH_ACTIVITY    => $this->patch_activity( $p ),
		};

		$this->audit->record( 'report.' . str_replace( 'report_', '', $a ), [ 'generated_at' => time() ] );
		return array_merge( [ 'action' => $a, 'report' => $a, 'generated_at' => time() ], $report );
	}

	// ── Catalogue ────────────────────────────────────────────────

	private function report_list(): array {
		$reports = [
			[ 'id' => ReportingRegistry::A_SITE_HEALTH,       'title' => 'Site Health',       'available' => true ],
			[ 'id' => ReportingRegistry::A_PLUGIN_HEALTH,     'title' => 'Plugin Health',     'available' => true ],
			[ 'id' => ReportingRegistry::A_SECURITY,          'title' => 'Security',          'available' => true ],
			[ 'id' => ReportingRegistry::A_CONTENT,           'title' => 'Content',           'available' => true ],
			[ 'id' => ReportingRegistry::A_WOOCOMMERCE,       'title' => 'WooCommerce',       'available' => class_exists( 'WooCommerce' ) ],
			[ 'id' => ReportingRegistry::A_AGENT_ACTIVITY,    'title' => 'Agent Activity',    'available' => true ],
			[ 'id' => ReportingRegistry::A_APPROVAL_ACTIVITY, 'title' => 'Approval Activity', 'available' => true ],
			[ 'id' => ReportingRegistry::A_PATCH_ACTIVITY,    'title' => 'Patch Activity',    'available' => true ],
		];
		return [ 'reports' => $reports, 'total' => count( $reports ) ];
	}

	// ── Site Health ──────────────────────────────────────────────

	private function site_health(): array {
		global $wpdb;
		$updates = function_exists( 'wp_get_update_data' ) ? wp_get_update_data() : [ 'counts' => [] ];
		$counts  = $updates['counts'] ?? [];
		$theme   = wp_get_theme();
		$php_ok  = version_compare( PHP_VERSION, '7.4', '>=' );

		$flags = [];
		if ( ! $php_ok ) $flags[] = 'php_version_below_7.4';
		if ( (int) ( $counts['total'] ?? 0 ) > 0 ) $flags[] = 'updates_pending';
		if ( ! is_ssl() ) $flags[] = 'not_https';

		return [ 'site_health' => [
			'php_version'        => PHP_VERSION,
			'php_supported'      => $php_ok,
			'wp_version'         => get_bloginfo( 'version' ),
			'mysql_version'      => $wpdb->db_version(),
			'server_software'    => sanitize_text_field( (string) ( $_SERVER['SERVER_SOFTWARE'] ?? '' ) ),
			'memory_limit'       => WP_MEMORY_LIMIT,
			'max_execution_time' => (int) ini_get( 'max_execution_time' ),
			'max_upload_size'    => size_format( wp_max_upload_size() ),
			'https'              => is_ssl(),
			'multisite'          => is_multisite(),
			'debug_mode'         => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'active_theme'       => [ 'name' => $theme->get( 'Name' ), 'version' => $theme->get( 'Version' ) ],
			'updates'            => [
				'core'    => (int) ( $counts['wordpress'] ?? 0 ),
				'plugins' => (int) ( $counts['plugins'] ?? 0 ),
				'themes'  => (int) ( $counts['themes'] ?? 0 ),
				'total'   => (int) ( $counts['total'] ?? 0 ),
			],
			'status' => empty( $flags ) ? 'healthy' : 'attention',
			'flags'  => $flags,
		] ];
	}

	// ── Plugin Health ────────────────────────────────────────────

	private function plugin_health(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all     = get_plugins();
		$active  = (array) get_option( 'active_plugins', [] );
		$mu      = function_exists( 'get_mu_plugins' ) ? get_mu_plugins() : [];
		$dropins = function_exists( 'get_dropins' ) ? get_dropins() : [];

		if ( ! function_exists( 'get_plugin_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		$updates = function_exists( 'get_plugin_updates' ) ? get_plugin_updates() : [];
		$update_slugs = array_values( array_map( fn( $f ) => $f, array_keys( $updates ) ) );

		return [ 'plugin_health' => [
			'total'             => count( $all ),
			'active'            => count( array_intersect( array_keys( $all ), $active ) ),
			'inactive'          => max( 0, count( $all ) - count( array_intersect( array_keys( $all ), $active ) ) ),
			'must_use'          => count( $mu ),
			'dropins'           => count( $dropins ),
			'updates_available' => count( $updates ),
			'update_plugins'    => array_slice( $update_slugs, 0, 50 ),
			'status'            => count( $updates ) > 0 ? 'attention' : 'healthy',
		] ];
	}

	// ── Security ─────────────────────────────────────────────────

	private function security(): array {
		$mode   = class_exists( SecurityModeManager::class ) ? SecurityModeManager::current() : 'developer';
		$tokens = [];
		try { $tokens = ( new AuthTokens() )->list(); } catch ( \Throwable $e ) { $tokens = []; }
		$by_scope = [];
		foreach ( $tokens as $t ) {
			$s = (string) ( $t['scope'] ?? 'unknown' );
			$by_scope[ $s ] = ( $by_scope[ $s ] ?? 0 ) + 1;
		}

		$pending = 0;
		try { $pending = count( ( new OperationManager() )->list_requests( [ 'status' => 'pending', 'limit' => 1000 ] ) ); } catch ( \Throwable $e ) { $pending = 0; }

		// Recent security-relevant audit events.
		$entries = $this->audit->tail( self::AUDIT_DEFAULT );
		$denied = $blocked = $destructive = 0;
		foreach ( $entries as $e ) {
			$act = (string) ( $e['action'] ?? '' );
			if ( 'capability.denied' === $act ) $denied++;
			elseif ( str_ends_with( $act, '.blocked' ) || str_ends_with( $act, '.denied' ) ) $blocked++;
			elseif ( 'operation.destructive.confirmation_required' === $act || 'operation.destructive.confirmed' === $act ) $destructive++;
		}

		return [ 'security' => [
			'security_mode'          => $mode,
			'approval_enforced'      => 'developer' !== $mode,
			'capability_enforcement' => (bool) get_option( 'wpcc_enforce_capabilities', true ),
			'tokens'                 => [ 'total' => count( $tokens ), 'by_scope' => $by_scope ],
			'pending_approvals'      => $pending,
			'recent_events'          => [
				'capability_denied'     => $denied,
				'blocked_or_denied'     => $blocked,
				'destructive_attempts'  => $destructive,
				'window_entries'        => count( $entries ),
			],
			'status' => ( $denied > 0 || $blocked > 0 ) ? 'attention' : 'healthy',
		] ];
	}

	// ── Content ──────────────────────────────────────────────────

	private function content(): array {
		$types = [];
		foreach ( get_post_types( [ 'public' => true ], 'names' ) as $pt ) {
			if ( 'attachment' === $pt ) continue;
			$c = (array) wp_count_posts( $pt );
			$types[ $pt ] = [
				'publish' => (int) ( $c['publish'] ?? 0 ),
				'draft'   => (int) ( $c['draft'] ?? 0 ),
				'pending' => (int) ( $c['pending'] ?? 0 ),
				'private' => (int) ( $c['private'] ?? 0 ),
				'future'  => (int) ( $c['future'] ?? 0 ),
				'trash'   => (int) ( $c['trash'] ?? 0 ),
			];
		}
		$comments = (array) wp_count_comments();
		$users    = function_exists( 'count_users' ) ? count_users() : [ 'total_users' => 0 ];

		return [ 'content' => [
			'post_types' => $types,
			'media'      => (int) ( (array) wp_count_posts( 'attachment' ) )['inherit'] ?? 0,
			'comments'   => [
				'approved' => (int) ( $comments['approved'] ?? 0 ),
				'pending'  => (int) ( $comments['moderated'] ?? 0 ),
				'spam'     => (int) ( $comments['spam'] ?? 0 ),
				'trash'    => (int) ( $comments['trash'] ?? 0 ),
			],
			'taxonomies' => [
				'categories' => (int) wp_count_terms( [ 'taxonomy' => 'category', 'hide_empty' => false ] ),
				'tags'       => (int) wp_count_terms( [ 'taxonomy' => 'post_tag', 'hide_empty' => false ] ),
			],
			'users' => (int) ( $users['total_users'] ?? 0 ),
		] ];
	}

	// ── WooCommerce ──────────────────────────────────────────────

	private function woocommerce(): array {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return [ 'woocommerce' => [ 'available' => false, 'message' => 'WooCommerce is not active.' ] ];
		}
		$products = (array) wp_count_posts( 'product' );
		$orders   = [];
		foreach ( [ 'wc-pending', 'wc-processing', 'wc-on-hold', 'wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-failed' ] as $st ) {
			if ( function_exists( 'wc_orders_count' ) ) {
				$orders[ str_replace( 'wc-', '', $st ) ] = (int) wc_orders_count( str_replace( 'wc-', '', $st ) );
			}
		}
		$low_stock = 0;
		if ( function_exists( 'wc_get_products' ) ) {
			$low = wc_get_products( [ 'limit' => -1, 'stock_status' => 'outofstock', 'return' => 'ids' ] );
			$low_stock = is_array( $low ) ? count( $low ) : 0;
		}
		$customers = count_users()['avail_roles']['customer'] ?? 0;

		return [ 'woocommerce' => [
			'available' => true,
			'products'  => [
				'total'     => array_sum( array_map( 'intval', $products ) ),
				'published' => (int) ( $products['publish'] ?? 0 ),
				'draft'     => (int) ( $products['draft'] ?? 0 ),
			],
			'orders'        => $orders,
			'out_of_stock'  => $low_stock,
			'customers'     => (int) $customers,
		] ];
	}

	// ── Activity reports (audit-derived) ─────────────────────────

	private function agent_activity( array $p ): array {
		$entries = $this->audit->tail( $this->audit_limit( $p ) );
		$started = $completed = $failed = 0;
		$by_op = [];
		$recent = [];
		foreach ( $entries as $e ) {
			$act = (string) ( $e['action'] ?? '' );
			if ( 'operation.execution.started' === $act ) {
				$started++;
				$op = (string) ( $e['context']['operation_id'] ?? 'unknown' );
				$by_op[ $op ] = ( $by_op[ $op ] ?? 0 ) + 1;
			} elseif ( 'operation.execution.completed' === $act ) {
				$completed++;
			} elseif ( 'operation.execution.failed' === $act ) {
				$failed++;
			}
			if ( str_starts_with( $act, 'operation.execution.' ) && count( $recent ) < 25 ) {
				$recent[] = [
					'at'        => (int) ( $e['timestamp'] ?? 0 ),
					'event'     => str_replace( 'operation.execution.', '', $act ),
					'operation' => (string) ( $e['context']['operation_id'] ?? '' ),
				];
			}
		}
		arsort( $by_op );
		return [ 'agent_activity' => [
			'window_entries' => count( $entries ),
			'operations'     => [ 'started' => $started, 'completed' => $completed, 'failed' => $failed ],
			'by_operation'   => array_slice( $by_op, 0, 15, true ),
			'recent'         => $recent,
		] ];
	}

	private function approval_activity( array $p ): array {
		$by_status = [];
		try {
			foreach ( ( new OperationManager() )->list_requests( [ 'limit' => 1000 ] ) as $r ) {
				$s = (string) ( $r['status'] ?? 'unknown' );
				$by_status[ $s ] = ( $by_status[ $s ] ?? 0 ) + 1;
			}
		} catch ( \Throwable $e ) { $by_status = []; }

		$entries = $this->audit->tail( $this->audit_limit( $p ) );
		$requested = $approved = $rejected = 0;
		foreach ( $entries as $e ) {
			$act = (string) ( $e['action'] ?? '' );
			if ( 'operation.approval.auto_requested' === $act ) $requested++;
			elseif ( str_contains( $act, 'approval' ) && str_contains( $act, 'approve' ) ) $approved++;
			elseif ( str_contains( $act, 'approval' ) && str_contains( $act, 'reject' ) ) $rejected++;
		}
		return [ 'approval_activity' => [
			'requests_by_status' => $by_status,
			'pending'            => (int) ( $by_status['pending'] ?? 0 ),
			'audit'              => [ 'auto_requested' => $requested, 'approved' => $approved, 'rejected' => $rejected ],
		] ];
	}

	private function patch_activity( array $p ): array {
		$by_status = [];
		try {
			foreach ( ( new \WPCommandCenter\PatchSystem\PatchManager() )->list() as $row ) {
				$s = (string) ( $row['status'] ?? 'unknown' );
				$by_status[ $s ] = ( $by_status[ $s ] ?? 0 ) + 1;
			}
		} catch ( \Throwable $e ) { $by_status = []; }

		$entries = $this->audit->tail( $this->audit_limit( $p ) );
		$created = $applied = $rolled_back = $rejected = 0;
		foreach ( $entries as $e ) {
			$act = (string) ( $e['action'] ?? '' );
			if ( ! str_contains( $act, 'patch' ) && ! str_contains( $act, 'rollback' ) ) continue;
			if ( str_contains( $act, 'create' ) ) $created++;
			elseif ( str_contains( $act, 'apply' ) ) $applied++;
			elseif ( str_contains( $act, 'rollback' ) ) $rolled_back++;
			elseif ( str_contains( $act, 'reject' ) ) $rejected++;
		}
		return [ 'patch_activity' => [
			'patches_by_status' => $by_status,
			'total_patches'     => array_sum( $by_status ),
			'audit'             => [ 'created' => $created, 'applied' => $applied, 'rolled_back' => $rolled_back, 'rejected' => $rejected ],
		] ];
	}

	private function audit_limit( array $p ): int {
		$n = isset( $p['limit'] ) ? (int) $p['limit'] : self::AUDIT_DEFAULT;
		return max( 1, min( self::AUDIT_MAX, $n ) );
	}
}
