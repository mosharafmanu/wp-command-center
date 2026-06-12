<?php
/**
 * Step 31 - deterministic recommendation generation and persistence.
 */

namespace WPCommandCenter\Recommendations;

use WPCommandCenter\Diagnostics\PerformanceDiagnostics;
use WPCommandCenter\Diagnostics\SecurityDiagnostics;
use WPCommandCenter\Diagnostics\WooCommerceDiagnostics;
use WPCommandCenter\Operations\OperationQueue;
use WPCommandCenter\Operations\OperationResults;
use WPCommandCenter\Security\AuditLog;
use WPCommandCenter\SiteIntelligence\SiteScanner;

defined( 'ABSPATH' ) || exit;

final class RecommendationEngine {

	public const TYPES = [ 'security', 'performance', 'woocommerce', 'operations', 'developer_experience', 'maintenance' ];
	public const SEVERITIES = [ 'info', 'low', 'medium', 'high', 'critical' ];
	public const STATUSES = [ 'open', 'converted_to_action', 'plan_created', 'approved', 'executing', 'resolved', 'dismissed' ];

	/**
	 * Scan current site/runtime state and persist deterministic findings.
	 *
	 * @return array{generated: int, created: int, updated: int, unchanged: int, recommendations: array}
	 */
	public function scan( array $actor = [] ): array|\WP_Error {
		$audit = new AuditLog();
		$audit->record( 'recommendation.scan.started', [
			'actor' => AuditLog::resolve_actor( $actor ),
		] );

		try {
			$candidates = $this->generate();
			$created    = 0;
			$updated    = 0;
			$unchanged  = 0;
			$records    = [];

			foreach ( $candidates as $candidate ) {
				$result = $this->upsert( $candidate, $actor );
				if ( is_wp_error( $result ) ) {
					throw new \RuntimeException( $result->get_error_message() );
				}

				${$result['operation']}++;
				$records[] = $result['recommendation'];
			}

			$response = [
				'generated'       => count( $candidates ),
				'created'         => $created,
				'updated'         => $updated,
				'unchanged'       => $unchanged,
				'recommendations' => $records,
			];

			$audit->record( 'recommendation.scan.completed', [
				'generated' => $response['generated'],
				'created'   => $created,
				'updated'   => $updated,
				'unchanged' => $unchanged,
				'actor'     => AuditLog::resolve_actor( $actor ),
			] );

			return $response;
		} catch ( \Throwable $exception ) {
			$audit->record( 'recommendation.scan.failed', [
				'error' => $exception->getMessage(),
				'actor' => AuditLog::resolve_actor( $actor ),
			] );

			return new \WP_Error( 'wpcc_recommendation_scan_failed', __( 'Recommendation scan failed.', 'wp-command-center' ) );
		}
	}

	/**
	 * Generate recommendation candidates without mutating the site.
	 */
	public function generate(): array {
		$site        = ( new SiteScanner() )->scan( true );
		$security    = $this->index_checks( ( new SecurityDiagnostics() )->analyze( $site ) );
		$performance = $this->index_checks( ( new PerformanceDiagnostics() )->analyze( $site ) );
		$woocommerce = $this->index_checks( ( new WooCommerceDiagnostics() )->analyze() );
		$rules       = [];

		$this->add_diagnostic_rule( $rules, $security, 'wp_debug_display', 'security', 'high', 'WP_DEBUG_DISPLAY is enabled', 'PHP errors may be visible to visitors.', 'May expose paths, warnings, or sensitive implementation details.', 'Disable WP_DEBUG_DISPLAY in production.', 'security_diagnostics' );

		$config = $site['file_permissions']['wp-config.php'] ?? [];
		if ( ! empty( $config['exists'] ) && ( ! empty( $config['writable'] ) || $this->is_world_writable( (string) ( $config['permissions'] ?? '' ) ) ) ) {
			$rules[] = $this->candidate( 'security.wp_config_writable', 'security', 'critical', 'wp-config.php is writable', 'The WordPress configuration file is writable by the PHP process or has unsafe permissions.', 'An attacker who gains code execution could alter database credentials, authentication salts, or site behavior.', 'Restrict wp-config.php permissions and ownership; use 600 or 640 where hosting permits.', 'security_diagnostics', [ 'permissions' => $config['permissions'] ?? '', 'writable' => (bool) ( $config['writable'] ?? false ) ] );
		}

		$this->add_diagnostic_rule( $rules, $security, 'ssl', 'security', 'medium', 'SSL is not enabled', 'The site is not currently being served over HTTPS.', 'Credentials and visitor traffic may be exposed or modified in transit.', 'Install a valid TLS certificate and redirect HTTP traffic to HTTPS.', 'security_diagnostics' );
		$this->add_diagnostic_rule( $rules, $security, 'file_edit', 'security', 'medium', 'Theme and plugin file editor is enabled', 'WordPress administrators can edit runtime PHP files from wp-admin.', 'A compromised administrator account can modify executable code directly.', 'Set DISALLOW_FILE_EDIT to true.', 'security_diagnostics' );
		$this->add_diagnostic_rule( $rules, $security, 'default_admin_account', 'security', 'medium', 'Default administrator username exists', 'An administrator account uses the predictable username "admin".', 'Predictable usernames make credential attacks easier.', 'Create a replacement administrator account and remove or demote the default admin account.', 'security_diagnostics' );

		$this->add_diagnostic_rule( $rules, $performance, 'page_cache', 'performance', 'medium', 'No page cache detected', 'No page caching plugin or advanced-cache.php drop-in was detected.', 'Uncached page generation increases response time and server load.', 'Configure a page cache appropriate for the hosting environment.', 'performance_diagnostics' );
		$this->add_diagnostic_rule( $rules, $performance, 'object_cache', 'performance', 'low', 'No persistent object cache detected', 'WordPress is not using a persistent external object cache.', 'Database-heavy workloads may perform more repeated queries.', 'Consider Redis or Memcached for sites with sustained dynamic traffic.', 'performance_diagnostics' );
		$this->add_diagnostic_rule( $rules, $performance, 'opcache', 'performance', 'medium', 'PHP OPcache is disabled', 'PHP bytecode caching is not enabled.', 'PHP files must be compiled repeatedly, increasing CPU use and response time.', 'Enable and size PHP OPcache for the site workload.', 'performance_diagnostics' );

		$plugin_count = count( $site['plugins'] ?? [] );
		if ( $plugin_count > 50 ) {
			$rules[] = $this->candidate( 'performance.active_plugins', 'performance', 'low', 'Many plugins are active', sprintf( '%d plugins are active.', $plugin_count ), 'A large plugin set can increase bootstrap time, query volume, and maintenance overhead.', 'Review active plugins and remove unused or overlapping functionality.', 'site_intelligence', [ 'active_plugin_count' => $plugin_count ] );
		}

		$autoload = $performance['autoloaded_options'] ?? null;
		if ( $autoload && in_array( $autoload['status'], [ 'recommended', 'critical' ], true ) ) {
			$rules[] = $this->candidate( 'performance.autoloaded_options', 'performance', 'critical' === $autoload['status'] ? 'high' : 'medium', 'Autoloaded options are too large', $autoload['description'], 'Large autoloaded data is loaded on every WordPress request and can increase memory use and latency.', 'Audit large autoloaded options and disable autoloading for data that is not needed on every request.', 'performance_diagnostics' );
		}

		if ( ! empty( $site['woocommerce']['active'] ) ) {
			$this->add_diagnostic_rule( $rules, $woocommerce, 'woocommerce_payment_gateways', 'woocommerce', 'critical', 'No WooCommerce payment gateways are enabled', 'Customers cannot complete checkout because no payment gateway is available.', 'The store cannot accept payments.', 'Enable and configure at least one production payment gateway.', 'woocommerce_diagnostics' );
			$this->add_diagnostic_rule( $rules, $woocommerce, 'woocommerce_scheduled_actions', 'woocommerce', 'high', 'WooCommerce scheduled actions are failing', $woocommerce['woocommerce_scheduled_actions']['description'] ?? '', 'Failed background jobs can interrupt orders, subscriptions, webhooks, or stock processing.', 'Review failed scheduled actions and fix the recurring error before retrying them.', 'woocommerce_diagnostics' );
			$this->add_diagnostic_rule( $rules, $woocommerce, 'woocommerce_db_version', 'woocommerce', 'high', 'WooCommerce database update is pending', $woocommerce['woocommerce_db_version']['description'] ?? '', 'Plugin code and database schema may be inconsistent.', 'Back up the database and run the WooCommerce database update.', 'woocommerce_diagnostics' );

			$template = $woocommerce['woocommerce_template_overrides'] ?? null;
			if ( $template && in_array( $template['status'], [ 'info', 'recommended' ], true ) ) {
				$rules[] = $this->candidate( 'woocommerce.template_overrides', 'woocommerce', 'recommended' === $template['status'] ? 'medium' : 'info', 'WooCommerce template overrides detected', $template['description'], 'Theme overrides may diverge from current WooCommerce templates and require maintenance after upgrades.', 'Review override versions after WooCommerce updates and test checkout-related templates.', 'woocommerce_diagnostics' );
			}
		}

		$failed_queue = ( new OperationQueue() )->list_items( [ 'status' => OperationQueue::STATUS_FAILED, 'limit' => 100 ] );
		if ( $failed_queue ) {
			$rules[] = $this->candidate( 'operations.failed_queue', 'operations', count( $failed_queue ) >= 5 ? 'high' : 'medium', 'Operation queue contains failed items', sprintf( '%d operation queue item(s) are in failed status.', count( $failed_queue ) ), 'Requested maintenance or content operations may be incomplete.', 'Review failure details, correct the cause, and retry eligible items.', 'operation_queue', [ 'count' => count( $failed_queue ) ] );
		}

		$retryable = array_filter( $failed_queue, static fn ( array $item ): bool => (int) $item['attempts'] < (int) $item['max_attempts'] );
		if ( $retryable ) {
			$rules[] = $this->candidate( 'operations.retryable_queue', 'operations', 'medium', 'Failed operations can be retried', sprintf( '%d failed queue item(s) remain within their retry limit.', count( $retryable ) ), 'Recoverable work remains incomplete.', 'Inspect each failure and retry only after correcting the underlying cause.', 'operation_queue', [ 'count' => count( $retryable ) ] );
		}

		$failed_results = ( new OperationResults() )->list_results( [ 'status' => 'failed', 'limit' => 100 ] );
		if ( $failed_results ) {
			$rules[] = $this->candidate( 'operations.failed_results', 'operations', count( $failed_results ) >= 5 ? 'high' : 'medium', 'Operation history contains failures', sprintf( '%d failed operation result(s) were found.', count( $failed_results ) ), 'Repeated failures may indicate invalid inputs, unavailable dependencies, or a persistent runtime fault.', 'Review recent operation results and address recurring error codes.', 'operation_results', [ 'count' => count( $failed_results ) ] );
		}

		$server = $site['server'] ?? [];
		if ( empty( $server['wp_cli_available'] ) ) {
			$rules[] = $this->candidate( 'developer.wp_cli', 'developer_experience', 'info', 'WP-CLI is unavailable', 'The WP-CLI bridge cannot find a usable wp binary.', 'Command-line maintenance and selected automation capabilities are unavailable.', 'Install WP-CLI or keep using the REST and wp-admin workflows.', 'server_capabilities' );
		}
		if ( empty( $server['shell_exec_enabled'] ) || empty( $server['proc_open_enabled'] ) ) {
			$rules[] = $this->candidate( 'developer.process_functions', 'developer_experience', 'info', 'Server process functions are restricted', 'shell_exec or proc_open is disabled in this PHP environment.', 'Features that rely on controlled child processes, including WP-CLI, may be unavailable.', 'Confirm this hosting restriction is intentional and use native WordPress APIs where possible.', 'server_capabilities', [ 'shell_exec' => (bool) ( $server['shell_exec_enabled'] ?? false ), 'proc_open' => (bool) ( $server['proc_open_enabled'] ?? false ) ] );
		}

		$debug_log = $this->recent_debug_log_errors();
		if ( $debug_log['count'] > 0 ) {
			$rules[] = $this->candidate( 'developer.debug_log_errors', 'developer_experience', $debug_log['count'] >= 10 ? 'high' : 'medium', 'Recent errors were found in debug.log', sprintf( '%d recent error-like log line(s) were detected.', $debug_log['count'] ), 'Unhandled warnings, exceptions, or fatal errors can indicate broken features or expose implementation details.', 'Review the redacted debug log, fix recurring errors, and rotate the log after verification.', 'debug_log', [ 'count' => $debug_log['count'] ] );
		}

		return $rules;
	}

	public function list( array $filters = [] ): array {
		global $wpdb;
		$table  = $this->table();
		$where  = [];
		$params = [];

		foreach ( [ 'type', 'severity', 'status', 'source' ] as $field ) {
			if ( ! empty( $filters[ $field ] ) ) {
				$where[]  = "{$field} = %s";
				$params[] = $filters[ $field ];
			}
		}

		$sql = "SELECT * FROM {$table}" . ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' ) . ' ORDER BY updated_at DESC, id DESC';
		$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', max( 1, min( 200, (int) ( $filters['limit'] ?? 50 ) ) ), max( 0, (int) ( $filters['offset'] ?? 0 ) ) );
		if ( $params ) {
			$sql = $wpdb->prepare( $sql, ...$params );
		}

		return array_map( [ $this, 'normalize' ], $wpdb->get_results( $sql, ARRAY_A ) ?: [] );
	}

	public function get( string $recommendation_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE recommendation_id = %s", $recommendation_id ), ARRAY_A );
		return $row ? $this->normalize( $row ) : null;
	}

	public function transition( string $recommendation_id, string $status, array $actor = [] ): array|\WP_Error {
		global $wpdb;
		$record = $this->get( $recommendation_id );
		if ( ! $record ) {
			return new \WP_Error( 'wpcc_recommendation_not_found', __( 'Recommendation not found.', 'wp-command-center' ) );
		}
		$allowed_from = [
			'dismissed' => [ 'open' ],
			'resolved'  => [ 'open', 'converted_to_action', 'plan_created', 'approved', 'executing' ],
		];
		if ( ! isset( $allowed_from[ $status ] ) || ! in_array( $record['status'], $allowed_from[ $status ], true ) ) {
			return new \WP_Error( 'wpcc_invalid_recommendation_status', __( 'The recommendation cannot make that status transition.', 'wp-command-center' ) );
		}

		$now  = time();
		$data = [ 'status' => $status, 'updated_at' => $now ];
		$data[ $status . '_at' ] = $now;
		$updated = $wpdb->update( $this->table(), $data, [ 'recommendation_id' => $recommendation_id ] );
		if ( false === $updated ) {
			return new \WP_Error( 'wpcc_recommendation_update_failed', __( 'Failed to update recommendation.', 'wp-command-center' ) );
		}

		( new AuditLog() )->record( 'recommendation.' . $status, [
			'recommendation_id' => $recommendation_id,
			'title'             => $record['title'],
			'source'            => $record['source'],
			'previous_status'   => $record['status'],
			'status'            => $status,
			'actor'             => AuditLog::resolve_actor( $actor ),
		] );

		return $this->get( $recommendation_id );
	}

	public function convert_to_action( string $recommendation_id, string $session_id, string $task_id, array $actor = [] ): array|\WP_Error {
		global $wpdb;
		$record = $this->get( $recommendation_id );
		if ( ! $record ) {
			return new \WP_Error( 'wpcc_recommendation_not_found', __( 'Recommendation not found.', 'wp-command-center' ) );
		}
		if ( 'open' !== $record['status'] ) {
			return new \WP_Error( 'wpcc_invalid_recommendation_status', __( 'Only open recommendations can be converted to actions.', 'wp-command-center' ) );
		}

		$session = $wpdb->get_var( $wpdb->prepare( "SELECT session_id FROM {$wpdb->prefix}wpcc_agent_sessions WHERE session_id = %s", $session_id ) );
		$task    = $wpdb->get_row( $wpdb->prepare( "SELECT task_id, session_id FROM {$wpdb->prefix}wpcc_agent_tasks WHERE task_id = %s", $task_id ), ARRAY_A );
		if ( ! $session ) {
			return new \WP_Error( 'wpcc_session_not_found', __( 'Agent session not found.', 'wp-command-center' ) );
		}
		if ( ! $task ) {
			return new \WP_Error( 'wpcc_task_not_found', __( 'Agent task not found.', 'wp-command-center' ) );
		}
		if ( $task['session_id'] !== $session_id ) {
			return new \WP_Error( 'wpcc_task_session_mismatch', __( 'The agent task does not belong to the supplied session.', 'wp-command-center' ) );
		}

		$action_id   = wp_generate_uuid4();
		$now         = time();
		$description = trim( $record['description'] . "\n\nSuggested action: " . $record['suggested_action'] );
		$inserted    = $wpdb->insert( $wpdb->prefix . 'wpcc_agent_actions', [
			'action_id' => $action_id, 'session_id' => $session_id, 'task_id' => $task_id,
			'type' => 'recommendation', 'title' => $record['title'], 'description' => $description,
			'status' => 'proposed', 'created_at' => $now, 'updated_at' => $now,
		] );
		if ( false === $inserted ) {
			return new \WP_Error( 'wpcc_action_create_failed', __( 'Failed to create the agent action.', 'wp-command-center' ) );
		}

		$context = $record['context'];
		$context['action_id']  = $action_id;
		$context['session_id'] = $session_id;
		$context['task_id']    = $task_id;
		$updated = $wpdb->update( $this->table(), [
			'action_id' => $action_id, 'status' => 'converted_to_action', 'context_json' => wp_json_encode( $context ), 'updated_at' => $now,
		], [ 'recommendation_id' => $recommendation_id ] );
		if ( false === $updated ) {
			$wpdb->delete( $wpdb->prefix . 'wpcc_agent_actions', [ 'action_id' => $action_id ] );
			return new \WP_Error( 'wpcc_recommendation_update_failed', __( 'Failed to link recommendation to action.', 'wp-command-center' ) );
		}

		$resolved_actor = AuditLog::resolve_actor( $actor );
		( new AuditLog() )->record( 'action.created', [
			'action_id' => $action_id, 'session_id' => $session_id, 'task_id' => $task_id,
			'type' => 'recommendation', 'title' => $record['title'], 'description' => $description,
			'status' => 'proposed', 'recommendation_id' => $recommendation_id, 'actor' => $resolved_actor,
		] );
		( new AuditLog() )->record( 'recommendation.converted_to_action', [
			'recommendation_id' => $recommendation_id, 'action_id' => $action_id,
			'session_id' => $session_id, 'task_id' => $task_id, 'title' => $record['title'],
			'previous_status' => 'open', 'status' => 'converted_to_action', 'actor' => $resolved_actor,
		] );
		( new AuditLog() )->record( 'recommendation.action_created', [
			'recommendation_id' => $recommendation_id, 'action_id' => $action_id,
			'session_id' => $session_id, 'task_id' => $task_id, 'title' => $record['title'],
			'previous_status' => 'open', 'status' => 'converted_to_action', 'actor' => $resolved_actor,
		] );

		return [ 'recommendation' => $this->get( $recommendation_id ), 'action' => $this->get_action( $action_id ) ];
	}

	public function create_plan( string $recommendation_id, array $data, array $actor = [] ): array|\WP_Error {
		global $wpdb;
		$record = $this->get( $recommendation_id );
		if ( ! $record ) {
			return new \WP_Error( 'wpcc_recommendation_not_found', __( 'Recommendation not found.', 'wp-command-center' ) );
		}
		if ( 'converted_to_action' !== $record['status'] || ! $record['action_id'] ) {
			return new \WP_Error( 'wpcc_invalid_recommendation_status', __( 'Only recommendations converted to an action can create a plan.', 'wp-command-center' ) );
		}

		$action = $this->get_action( $record['action_id'] );
		if ( ! $action ) {
			return new \WP_Error( 'wpcc_action_not_found', __( 'Linked agent action not found.', 'wp-command-center' ) );
		}
		$title     = sanitize_text_field( (string) ( $data['title'] ?? 'Plan: ' . $record['title'] ) );
		$objective = sanitize_textarea_field( (string) ( $data['objective'] ?? $record['suggested_action'] ) );
		$steps     = $data['steps'] ?? [ [ 'title' => $record['suggested_action'], 'description' => $record['description'] ] ];
		if ( '' === $title || '' === $objective || ! is_array( $steps ) || empty( $steps ) ) {
			return new \WP_Error( 'wpcc_invalid_plan', __( 'Plan title, objective, and at least one step are required.', 'wp-command-center' ) );
		}

		$normalized_steps = [];
		foreach ( array_values( $steps ) as $index => $step ) {
			$step_title = sanitize_text_field( (string) ( $step['title'] ?? '' ) );
			if ( '' === $step_title ) {
				return new \WP_Error( 'wpcc_invalid_plan_step', __( 'Each plan step needs a title.', 'wp-command-center' ) );
			}
			$normalized_steps[] = [
				'step_order' => $index + 1,
				'title' => $step_title,
				'description' => sanitize_textarea_field( (string) ( $step['description'] ?? '' ) ),
				'status' => 'pending',
			];
		}

		$plan_id = wp_generate_uuid4();
		$now     = time();
		$wpdb->query( 'START TRANSACTION' );
		$inserted = $wpdb->insert( $wpdb->prefix . 'wpcc_agent_plans', [
			'plan_id' => $plan_id, 'session_id' => $action['session_id'], 'task_id' => $action['task_id'],
			'action_id' => $action['action_id'], 'title' => $title, 'objective' => $objective,
			'status' => 'pending_review', 'created_at' => $now, 'updated_at' => $now,
		] );
		if ( false === $inserted ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcc_plan_create_failed', __( 'Failed to create the recommendation plan.', 'wp-command-center' ) );
		}
		foreach ( $normalized_steps as $step ) {
			if ( false === $wpdb->insert( $wpdb->prefix . 'wpcc_agent_plan_steps', [ 'plan_id' => $plan_id ] + $step ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'wpcc_plan_step_create_failed', __( 'Failed to create a recommendation plan step.', 'wp-command-center' ) );
			}
		}
		$context = $record['context'] + [];
		$context['plan_id'] = $plan_id;
		$linked = $wpdb->update( $this->table(), [
			'plan_id' => $plan_id, 'status' => 'plan_created', 'context_json' => wp_json_encode( $context ), 'updated_at' => $now,
		], [ 'recommendation_id' => $recommendation_id ] );
		if ( false === $linked ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcc_recommendation_update_failed', __( 'Failed to link recommendation to plan.', 'wp-command-center' ) );
		}
		$wpdb->query( 'COMMIT' );

		$resolved_actor = AuditLog::resolve_actor( $actor );
		( new AuditLog() )->record( 'plan.created', [
			'plan_id' => $plan_id, 'session_id' => $action['session_id'], 'task_id' => $action['task_id'],
			'action_id' => $action['action_id'], 'title' => $title, 'objective' => $objective,
			'status' => 'pending_review', 'recommendation_id' => $recommendation_id, 'actor' => $resolved_actor,
		] );
		( new AuditLog() )->record( 'recommendation.plan_created', [
			'recommendation_id' => $recommendation_id, 'action_id' => $action['action_id'], 'plan_id' => $plan_id,
			'session_id' => $action['session_id'], 'task_id' => $action['task_id'], 'title' => $record['title'],
			'previous_status' => 'converted_to_action', 'status' => 'plan_created', 'actor' => $resolved_actor,
		] );

		return [ 'recommendation' => $this->get( $recommendation_id ), 'plan' => $this->get_plan( $plan_id ) ];
	}

	public function sync_plan_status( string $plan_id, string $status, array $actor = [] ): ?array {
		global $wpdb;
		$record = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE plan_id = %s", $plan_id ), ARRAY_A );
		if ( ! $record ) {
			return null;
		}
		$record = $this->normalize( $record );
		$allowed = [
			'approved'  => [ 'plan_created' ],
			'executing' => [ 'approved' ],
			'resolved'  => [ 'executing', 'approved' ],
		];
		if ( ! isset( $allowed[ $status ] ) || ! in_array( $record['status'], $allowed[ $status ], true ) ) {
			return $record;
		}
		$data = [ 'status' => $status, 'updated_at' => time() ];
		if ( 'resolved' === $status ) {
			$data['resolved_at'] = time();
		}
		$wpdb->update( $this->table(), $data, [ 'recommendation_id' => $record['recommendation_id'] ] );
		( new AuditLog() )->record( 'recommendation.' . $status, [
			'recommendation_id' => $record['recommendation_id'], 'action_id' => $record['action_id'], 'plan_id' => $plan_id,
			'title' => $record['title'], 'previous_status' => $record['status'], 'status' => $status,
			'actor' => AuditLog::resolve_actor( $actor ),
		] );
		return $this->get( $record['recommendation_id'] );
	}

	private function upsert( array $candidate, array $actor ): array|\WP_Error {
		global $wpdb;
		$key = $candidate['context']['rule_key'];
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE status NOT IN ('resolved','dismissed') AND source = %s ORDER BY id DESC", $candidate['source'] ), ARRAY_A );
		$existing = null;
		foreach ( $rows ?: [] as $row ) {
			$context = json_decode( (string) $row['context_json'], true ) ?: [];
			if ( ( $context['rule_key'] ?? '' ) === $key ) {
				$existing = $this->normalize( $row );
				break;
			}
		}

		$now = time();
		if ( ! $existing ) {
			$id = wp_generate_uuid4();
			$inserted = $wpdb->insert( $this->table(), [
				'recommendation_id' => $id, 'type' => $candidate['type'], 'severity' => $candidate['severity'],
				'title' => $candidate['title'], 'description' => $candidate['description'], 'impact' => $candidate['impact'],
				'suggested_action' => $candidate['suggested_action'], 'source' => $candidate['source'], 'status' => 'open',
				'context_json' => wp_json_encode( $candidate['context'] ), 'created_at' => $now, 'updated_at' => $now,
			] );
			if ( false === $inserted ) {
				return new \WP_Error( 'wpcc_recommendation_create_failed', __( 'Failed to create recommendation.', 'wp-command-center' ) );
			}
			( new AuditLog() )->record( 'recommendation.created', $this->audit_context( $id, $candidate, 'open', $actor ) );
			return [ 'operation' => 'created', 'recommendation' => $this->get( $id ) ];
		}

		$changed = false;
		foreach ( [ 'type', 'severity', 'title', 'description', 'impact', 'suggested_action', 'source' ] as $field ) {
			if ( $existing[ $field ] !== $candidate[ $field ] ) {
				$changed = true;
				break;
			}
		}
		if ( $existing['context'] !== $candidate['context'] ) {
			$changed = true;
		}
		if ( ! $changed || 'open' !== $existing['status'] ) {
			return [ 'operation' => 'unchanged', 'recommendation' => $existing ];
		}

		$wpdb->update( $this->table(), [
			'type' => $candidate['type'], 'severity' => $candidate['severity'], 'title' => $candidate['title'],
			'description' => $candidate['description'], 'impact' => $candidate['impact'], 'suggested_action' => $candidate['suggested_action'],
			'source' => $candidate['source'], 'context_json' => wp_json_encode( $candidate['context'] ), 'updated_at' => $now,
		], [ 'recommendation_id' => $existing['recommendation_id'] ] );
		( new AuditLog() )->record( 'recommendation.updated', $this->audit_context( $existing['recommendation_id'], $candidate, 'open', $actor ) );
		return [ 'operation' => 'updated', 'recommendation' => $this->get( $existing['recommendation_id'] ) ];
	}

	private function candidate( string $rule_key, string $type, string $severity, string $title, string $description, string $impact, string $suggested_action, string $source, array $context = [] ): array {
		return compact( 'type', 'severity', 'title', 'description', 'impact', 'suggested_action', 'source' ) + [ 'context' => [ 'rule_key' => $rule_key ] + $context ];
	}

	private function add_diagnostic_rule( array &$rules, array $checks, string $id, string $type, string $severity, string $title, string $description, string $impact, string $suggested_action, string $source ): void {
		$check = $checks[ $id ] ?? null;
		if ( ! $check || ! in_array( $check['status'], [ 'recommended', 'critical' ], true ) ) {
			return;
		}
		$rules[] = $this->candidate( $source . '.' . $id, $type, $severity, $title, $description ?: $check['description'], $impact, $suggested_action, $source, [ 'diagnostic_id' => $id, 'diagnostic_status' => $check['status'] ] );
	}

	private function index_checks( array $checks ): array {
		$result = [];
		foreach ( $checks as $check ) {
			$result[ $check['id'] ] = $check;
		}
		return $result;
	}

	private function recent_debug_log_errors(): array {
		$file = WP_CONTENT_DIR . '/debug.log';
		if ( ! is_readable( $file ) || filemtime( $file ) < time() - WEEK_IN_SECONDS ) {
			return [ 'count' => 0 ];
		}
		$handle = fopen( $file, 'rb' );
		if ( false === $handle ) {
			return [ 'count' => 0 ];
		}
		$size = filesize( $file );
		if ( $size > 131072 ) {
			fseek( $handle, -131072, SEEK_END );
		}
		$text = stream_get_contents( $handle ) ?: '';
		fclose( $handle );
		preg_match_all( '/^.*(?:fatal|error|exception|warning).*$/mi', $text, $matches );
		return [ 'count' => count( $matches[0] ?? [] ) ];
	}

	private function is_world_writable( string $permissions ): bool {
		return '' !== $permissions && in_array( substr( $permissions, -1 ), [ '2', '3', '6', '7' ], true );
	}

	private function audit_context( string $id, array $candidate, string $status, array $actor ): array {
		return [ 'recommendation_id' => $id, 'type' => $candidate['type'], 'severity' => $candidate['severity'], 'title' => $candidate['title'], 'source' => $candidate['source'], 'status' => $status, 'actor' => AuditLog::resolve_actor( $actor ) ];
	}

	private function normalize( array $row ): array {
		return [
			'recommendation_id' => $row['recommendation_id'], 'action_id' => $row['action_id'] ?: null, 'plan_id' => $row['plan_id'] ?: null,
			'type' => $row['type'], 'severity' => $row['severity'],
			'title' => $row['title'], 'description' => $row['description'], 'impact' => $row['impact'],
			'suggested_action' => $row['suggested_action'], 'source' => $row['source'], 'status' => $row['status'],
			'context' => json_decode( (string) $row['context_json'], true ) ?: [],
			'created_at' => (int) $row['created_at'], 'updated_at' => (int) $row['updated_at'],
			'dismissed_at' => $row['dismissed_at'] ? (int) $row['dismissed_at'] : null,
			'resolved_at' => $row['resolved_at'] ? (int) $row['resolved_at'] : null,
		];
	}

	private function get_action( string $action_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcc_agent_actions WHERE action_id = %s", $action_id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		foreach ( [ 'created_at', 'updated_at' ] as $field ) {
			$row[ $field ] = (int) $row[ $field ];
		}
		return $row;
	}

	private function get_plan( string $plan_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcc_agent_plans WHERE plan_id = %s", $plan_id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$row['steps'] = $wpdb->get_results( $wpdb->prepare( "SELECT step_order, title, description, status FROM {$wpdb->prefix}wpcc_agent_plan_steps WHERE plan_id = %s ORDER BY step_order ASC", $plan_id ), ARRAY_A ) ?: [];
		return $row;
	}

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wpcc_recommendations';
	}
}
