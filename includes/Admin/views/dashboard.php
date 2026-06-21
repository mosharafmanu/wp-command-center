<?php
defined( 'ABSPATH' ) || exit;

global $wpdb;

$wpcc_environment_mode = ( new \WPCommandCenter\System\EnvironmentManager() )->get();

$op_manager = new \WPCommandCenter\Operations\OperationManager();
$op_queue   = new \WPCommandCenter\Operations\OperationQueue();

// AuditLog::resolve_actor() requires an array; OperationQueue::run_item()
// forwards context['actor'] straight to it, so it must be built here as
// an array (not the bare user_login string) wherever run_item() is called.
$wpcc_actor_context = [
	'actor' => [
		'type'       => 'admin',
		'user_id'    => get_current_user_id(),
		'user_login' => wp_get_current_user()->user_login,
	],
];

/**
 * Compute a UI-level risk assessment (low|medium|high) for a Search & Replace
 * table selection. This is independent of the operation registry's static
 * risk_level (always "high" for safe_search_replace) and is shown to help
 * the operator gauge the blast radius of the specific tables chosen.
 *
 * Rules: posts/postmeta only -> low; wp_options included -> medium;
 * any wpcc_* (plugin-internal) table included -> high; anything else -> medium.
 */
$wpcc_compute_risk = function ( array $tables ) use ( $wpdb ): string {
	if ( empty( $tables ) ) {
		return 'low';
	}

	$has_system          = false;
	$has_options         = false;
	$only_posts_postmeta = true;

	foreach ( $tables as $table ) {
		$suffix = substr( $table, strlen( $wpdb->prefix ) );

		if ( str_starts_with( $suffix, 'wpcc_' ) ) {
			$has_system = true;
		}
		if ( 'options' === $suffix ) {
			$has_options = true;
		}
		if ( ! in_array( $suffix, [ 'posts', 'postmeta' ], true ) ) {
			$only_posts_postmeta = false;
		}
	}

	if ( $has_system ) {
		return 'high';
	}
	if ( $has_options ) {
		return 'medium';
	}
	if ( $only_posts_postmeta ) {
		return 'low';
	}

	return 'medium';
};

// Overview Cards Data
$active_sessions = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcc_agent_sessions WHERE status = 'active'" );
$open_tasks      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcc_agent_tasks WHERE status IN ('draft', 'analyzing', 'waiting_for_user')" );
$proposed_actions= (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcc_agent_actions WHERE status = 'proposed'" );
$pending_plans   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcc_agent_plans WHERE status = 'pending_review'" );
$applied_patches = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcc_patches WHERE status = 'applied'" );

// WP-CLI bridge data
$wpcc_bridge      = new \WPCommandCenter\Operations\WpCliBridge();
$wpcc_wpcli_avail = $wpcc_bridge->is_available();
$wpcc_wpcli_count = count( $wpcc_bridge->get_supported_commands() );
$wpcc_wpcli_risk  = $wpcc_bridge->count_by_risk();

// Option management data
$wpcc_options_registry = new \WPCommandCenter\Operations\OptionRegistry();
$wpcc_options_count    = count( $wpcc_options_registry->get_summary() );
$wpcc_options_risk     = $wpcc_options_registry->count_by_risk();

// Plugin management data
$wpcc_plugin_registry = new \WPCommandCenter\Operations\PluginRegistry();
$wpcc_plugin_state    = $wpcc_plugin_registry->count_by_state();

// Theme management data
$wpcc_theme_registry = new \WPCommandCenter\Operations\ThemeRegistry();
$wpcc_theme_state    = $wpcc_theme_registry->count_by_state();
$wpcc_active_theme   = $wpcc_theme_registry->get_active_theme();

// Snapshot data
$wpcc_core_snaps = new \WPCommandCenter\Rollback\SnapshotManager();
$wpcc_snap_count = count( $wpcc_core_snaps->list() );

$wpcc_content_registry = new \WPCommandCenter\Operations\ContentRegistry();
$wpcc_content_counts   = $wpcc_content_registry->get_summary();

// Widgets data
$wpcc_widgets_registry = new \WPCommandCenter\Operations\WidgetsRegistry();
$wpcc_widgets_count    = count( $wpcc_widgets_registry->get_widgets() );
$wpcc_sidebars_count   = count( $wpcc_widgets_registry->get_sidebars() );

// CPT data
$wpcc_cpt_registry    = new \WPCommandCenter\Operations\CPTRegistry();
$wpcc_cpt_summary     = $wpcc_cpt_registry->get_summary();
$wpcc_cpt_count       = $wpcc_cpt_summary['total_cpt'];
$wpcc_cpt_tax_count   = $wpcc_cpt_summary['total_taxonomies'];

// Handle Actions
if ( isset( $_POST['wpcc_action'] ) && check_admin_referer( 'wpcc_dashboard_action' ) && current_user_can( 'manage_options' ) ) {
	$action = sanitize_key( $_POST['wpcc_action'] );
	$id     = sanitize_key( $_POST['id'] ?? '' );

	if ( $id ) {
		switch ( $action ) {
			// Operation-request approve/reject and manual queue-run were relocated to
			// the Approval Center (Operate › Approvals); the Runtime view keeps only
			// the controls unique to it (Safe Search & Replace, agent plans).
			//
			// For plan approval, we update the plan status directly and sync it.
			case 'approve_plan':
				$wpdb->update( "{$wpdb->prefix}wpcc_agent_plans", [ 'status' => 'approved' ], [ 'plan_id' => $id ] );
				( new \WPCommandCenter\Security\AuditLog() )->record( 'plan.approved', [ 'plan_id' => $id, 'actor' => wp_get_current_user()->user_login ] );
				( new \WPCommandCenter\Recommendations\RecommendationEngine() )->sync_plan_status( $id, 'approved', $wpcc_actor_context['actor'] );
				break;
			case 'reject_plan':
				$wpdb->update( "{$wpdb->prefix}wpcc_agent_plans", [ 'status' => 'rejected' ], [ 'plan_id' => $id ] );
				( new \WPCommandCenter\Security\AuditLog() )->record( 'plan.rejected', [ 'plan_id' => $id, 'actor' => wp_get_current_user()->user_login ] );
				break;
		}
		
		// If we had a redirect here, it would prevent us from showing notices on the same page load.
		// Let's remove the redirect and rely on the fact that we'll re-fetch data below.
	}
}

// Handle Search & Replace UI
$sr_result        = null;
$sr_preview       = null;
$sr_posted_tables = [];

if ( isset( $_POST['wpcc_sr_action'] ) && check_admin_referer( 'wpcc_sr_action' ) && current_user_can( 'manage_options' ) ) {
	$search    = (string) ( $_POST['search'] ?? '' );
	$replace   = (string) ( $_POST['replace'] ?? '' );
	$tables    = array_map( 'sanitize_text_field', (array) ( $_POST['tables'] ?? [] ) );
	$dry_run   = ! empty( $_POST['dry_run'] );
	$confirmed = '1' === ( (string) ( $_POST['confirmed'] ?? '0' ) );

	$sr_posted_tables = $tables;

	if ( '' === $search || empty( $tables ) ) {
		$sr_error = __( 'Search string and at least one table are required.', 'wp-command-center' );
	} elseif ( ! $dry_run && ! $confirmed ) {
		// Live (non-dry-run) requests must go through the confirmation dialog (requirement #7).
		$sr_error = __( 'Please confirm the Search & Replace request in the confirmation dialog before it is created, or enable Dry Run.', 'wp-command-center' );
	} else {
		$payload = [
			'search'         => $search,
			'replace'        => $replace,
			'tables'         => $tables,
			'dry_run'        => $dry_run,
			'case_sensitive' => false,
		];

		$meta = [ 'actor' => wp_get_current_user()->user_login ];
		$req  = $op_manager->create_request( 'safe_search_replace', $payload, $meta );

		if ( ! is_wp_error( $req ) ) {
			if ( $dry_run ) {
				// For dry run, auto-approve and execute immediately to show a live preview.
				$op_manager->approve_request( $req['request_id'] );

				// Get the queue item ID (auto-queued by approve_request()).
				$q_item = $wpdb->get_row( $wpdb->prepare( "SELECT queue_id FROM {$wpdb->prefix}wpcc_operation_queue WHERE request_id = %s", $req['request_id'] ) );
				if ( $q_item ) {
					$sr_result = $op_queue->run_item( $q_item->queue_id, $wpcc_actor_context );

					if ( is_wp_error( $sr_result ) ) {
						$sr_error = $sr_result->get_error_message();
					} else {
						// Queue item result is the OperationExecutor's normalized response;
						// the SearchReplace summary lives one level deeper, in result.result.
						$res = $sr_result['result']['result'] ?? [];

						if ( ! empty( $sr_result['result']['errors'] ) ) {
							$sr_error = $sr_result['result']['errors'][0]['message'] ?? __( 'Dry run failed.', 'wp-command-center' );
						} else {
							$sr_preview = [
								'search'          => $search,
								'replace'         => $replace,
								'tables'          => $tables,
								'matches_found'   => (int) ( $res['matches_found'] ?? 0 ),
								'rows_affected'   => (int) ( $res['rows_affected'] ?? 0 ),
								'tables_affected' => $res['tables_affected'] ?? [],
								'tables_checked'  => (int) ( $res['tables_checked'] ?? 0 ),
								'risk_level'      => $wpcc_compute_risk( $tables ),
								'warning'         => $res['warning'] ?? '',
							];
						}
					}
				}
			} else {
				$sr_success_msg = sprintf(
					/* translators: %s: operation request ID */
					__( 'Live Search & Replace request "%s" created and is pending review. Approve and run it from the Approval Center (Operate › Approvals), or wait for the background worker.', 'wp-command-center' ),
					$req['request_id']
				);
			}
		} else {
			$sr_error = $req->get_error_message();
		}
	}
}

// NOW fetch the data for display (after any actions have been handled)
$active_sessions = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcc_agent_sessions WHERE status = 'active'" );
$open_tasks      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcc_agent_tasks WHERE status IN ('draft', 'analyzing', 'waiting_for_user')" );
$proposed_actions= (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcc_agent_actions WHERE status = 'proposed'" );
$pending_plans   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcc_agent_plans WHERE status = 'pending_review'" );
$applied_patches = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcc_patches WHERE status = 'applied'" );

$pending_requests= $op_manager->list_requests( [ 'status' => 'pending_review', 'limit' => 5 ] );
$pending_req_cnt = count( $pending_requests );

$queued_ops      = $op_queue->list_items( [ 'status' => \WPCommandCenter\Operations\OperationQueue::STATUS_QUEUED, 'limit' => 5 ] );
$failed_ops      = $op_queue->list_items( [ 'status' => \WPCommandCenter\Operations\OperationQueue::STATUS_FAILED, 'limit' => 5 ] );

$recommendation_engine = new \WPCommandCenter\Recommendations\RecommendationEngine();
$recommendations_table  = $wpdb->prefix . 'wpcc_recommendations';
$recommendation_count   = static function ( string $status, string $severity = '' ) use ( $wpdb, $recommendations_table ): int {
	if ( '' !== $severity ) {
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$recommendations_table} WHERE status = %s AND severity = %s", $status, $severity ) );
	}
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$recommendations_table} WHERE status = %s", $status ) );
};
$open_recommendation_count              = $recommendation_count( 'open' );
$critical_recommendation_count          = $recommendation_count( 'open', 'critical' );
$awaiting_plan_recommendation_count     = $recommendation_count( 'converted_to_action' );
$awaiting_approval_recommendation_count = $recommendation_count( 'plan_created' );
$in_progress_recommendation_count       = $recommendation_count( 'executing' );
$resolved_recommendation_count          = $recommendation_count( 'resolved' );
$recent_recommendations = $recommendation_engine->list( [ 'limit' => 5 ] );

// Timeline filters and pagination are UI-only; the underlying timeline API remains unchanged.
$wpcc_timeline_type   = sanitize_key( (string) ( $_GET['timeline_type'] ?? '' ) );
$wpcc_timeline_status = sanitize_key( (string) ( $_GET['timeline_status'] ?? '' ) );
$wpcc_timeline_page   = max( 1, (int) ( $_GET['timeline_page'] ?? 1 ) );
$wpcc_timeline_limit  = 10;
$wpcc_timeline_all    = ( new \WPCommandCenter\AiAgent\TimelineBuilder() )->build( [ 'limit' => 300 ] );
$wpcc_timeline_all    = array_values( array_filter( $wpcc_timeline_all, static function ( array $event ) use ( $wpcc_timeline_type, $wpcc_timeline_status ): bool {
	return ( '' === $wpcc_timeline_type || $event['type'] === $wpcc_timeline_type )
		&& ( '' === $wpcc_timeline_status || $event['status'] === $wpcc_timeline_status );
} ) );
$wpcc_timeline_total_pages = max( 1, (int) ceil( count( $wpcc_timeline_all ) / $wpcc_timeline_limit ) );
$wpcc_timeline_page = min( $wpcc_timeline_page, $wpcc_timeline_total_pages );
$timeline = array_slice( $wpcc_timeline_all, ( $wpcc_timeline_page - 1 ) * $wpcc_timeline_limit, $wpcc_timeline_limit );

$wpcc_result_store   = new \WPCommandCenter\Operations\OperationResults();
$wpcc_recent_results = $wpcc_result_store->list_results( [ 'limit' => 5 ] );
$wpcc_selected_result_id = sanitize_text_field( (string) ( $_GET['result_id'] ?? '' ) );
$wpcc_selected_result = $wpcc_selected_result_id ? $wpcc_result_store->get_result( $wpcc_selected_result_id ) : null;

// Fetch pending plans for the list
$pending_plans_list = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wpcc_agent_plans WHERE status = 'pending_review' ORDER BY created_at DESC LIMIT 5", ARRAY_A );

$wp_tables = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}%'" );

// Classify each table for the table-preset selector and "Show System Tables" toggle.
// Groups: content (core content tables), meta (associated *meta tables),
// options (wp_options), system (wpcc_* internal plugin tables), other (everything else).
$wpcc_table_groups = [];
foreach ( $wp_tables as $table ) {
	$suffix = substr( $table, strlen( $wpdb->prefix ) );

	if ( str_starts_with( $suffix, 'wpcc_' ) ) {
		$wpcc_table_groups[ $table ] = 'system';
	} elseif ( in_array( $suffix, [ 'posts', 'terms', 'term_taxonomy', 'term_relationships', 'comments', 'links' ], true ) ) {
		$wpcc_table_groups[ $table ] = 'content';
	} elseif ( in_array( $suffix, [ 'postmeta', 'termmeta', 'commentmeta' ], true ) ) {
		$wpcc_table_groups[ $table ] = 'meta';
	} elseif ( 'options' === $suffix ) {
		$wpcc_table_groups[ $table ] = 'options';
	} else {
		$wpcc_table_groups[ $table ] = 'other';
	}
}

// Preserve the operator's table selection across reloads; default to posts + options.
$wpcc_checked_tables = isset( $_POST['wpcc_sr_action'] )
	? $sr_posted_tables
	: [ $wpdb->prefix . 'posts', $wpdb->prefix . 'options' ];

// Dry Run is enabled by default on first load; preserve the operator's choice on reload.
$wpcc_dry_run_checked = isset( $_POST['wpcc_sr_action'] ) ? ! empty( $_POST['dry_run'] ) : true;

// Table presets for the "Table Preset" selector, derived from the same
// classification used for grouping/risk so the lists never drift apart.
$wpcc_presets = [
	'content'      => [],
	'content_meta' => [],
	'options'      => [],
	'all'          => [],
];
foreach ( $wpcc_table_groups as $table => $group ) {
	switch ( $group ) {
		case 'content':
			$wpcc_presets['content'][]      = $table;
			$wpcc_presets['content_meta'][] = $table;
			$wpcc_presets['all'][]          = $table;
			break;
		case 'meta':
			$wpcc_presets['content_meta'][] = $table;
			$wpcc_presets['all'][]          = $table;
			break;
		case 'options':
			$wpcc_presets['options'][] = $table;
			$wpcc_presets['all'][]     = $table;
			break;
	}
}

// Last dry-run preview, exposed to JS so the confirmation dialog can show
// "affected rows" without re-querying the database.
$sr_preview_js = $sr_preview ? [
	'search'          => $sr_preview['search'],
	'replace'         => $sr_preview['replace'],
	'tables'          => array_values( $sr_preview['tables'] ),
	'rows_affected'   => $sr_preview['rows_affected'],
	'tables_affected' => array_values( $sr_preview['tables_affected'] ),
] : null;

?>
<style>
	.wpcc-dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 20px; }
	.wpcc-card { background: #fff; border: 1px solid #ccd0d4; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,.04); text-align: center; }
	.wpcc-card h3 { margin: 0 0 10px; font-size: 14px; color: #555; }
	.wpcc-card .num { font-size: 32px; font-weight: 600; color: #2271b1; line-height: 1; }
	.wpcc-panel { background: #fff; border: 1px solid #ccd0d4; padding: 0; box-shadow: 0 1px 1px rgba(0,0,0,.04); margin-bottom: 20px; }
	.wpcc-panel-header { padding: 15px 20px; border-bottom: 1px solid #ccd0d4; background: #f6f7f7; margin: 0; font-size: 16px; font-weight: 600; }
	.wpcc-panel-body { padding: 20px; }
	.wpcc-timeline { list-style: none; padding: 0; margin: 0; }
	.wpcc-timeline li { padding-bottom: 15px; border-left: 2px solid #ccd0d4; padding-left: 20px; margin-left: 10px; position: relative; }
	.wpcc-timeline li::before { content: ''; position: absolute; left: -6px; top: 0; width: 10px; height: 10px; border-radius: 50%; background: #2271b1; }
	.wpcc-timeline .time { font-size: 12px; color: #646970; display: block; margin-bottom: 4px; }
	.wpcc-timeline .label { font-weight: 600; display: block; }
	.wpcc-timeline .summary { font-size: 13px; color: #3c434a; }
	.wpcc-action-btn { margin-right: 5px !important; }
	.wpcc-sr-form input[type="text"], .wpcc-sr-form select { width: 100%; margin-bottom: 10px; }
	.wpcc-sr-tables { max-height: 180px; overflow-y: scroll; border: 1px solid #ccd0d4; padding: 5px; background: #fff; margin-bottom: 10px; }
	.wpcc-sr-tables label { display: block; padding: 2px 0; }
	.wpcc-sr-preview-table td { padding: 4px 8px 4px 0; vertical-align: top; }
	.wpcc-sr-preview-table td:first-child { font-weight: 600; white-space: nowrap; width: 140px; }
	.wpcc-risk-badge { display: inline-block; padding: 2px 10px; border-radius: 3px; font-size: 11px; font-weight: 700; color: #fff; text-transform: uppercase; letter-spacing: .5px; }
	.wpcc-risk-low { background: #00a32a; }
	.wpcc-risk-medium { background: #dba617; }
	.wpcc-risk-high { background: #d63638; }
	.wpcc-modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,.5); z-index: 100000; align-items: center; justify-content: center; }
	.wpcc-modal-overlay.is-visible { display: flex; }
	.wpcc-modal { background: #fff; padding: 20px 24px; max-width: 520px; width: 90%; border-radius: 4px; box-shadow: 0 4px 20px rgba(0,0,0,.2); }
	.wpcc-modal h3 { margin-top: 0; }
	.wpcc-modal table { width: 100%; margin-bottom: 15px; border-collapse: collapse; }
	.wpcc-modal table th { text-align: left; padding: 4px 10px 4px 0; vertical-align: top; width: 130px; color: #555; }
	.wpcc-modal table td { padding: 4px 0; word-break: break-word; }
	.wpcc-modal-actions { text-align: right; }
	.wpcc-modal-actions .button { margin-left: 8px; }
	.wpcc-runtime-flow { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
	.wpcc-runtime-node { background: #f6f7f7; border: 1px solid #c3c4c7; border-radius: 4px; padding: 10px 12px; min-width: 92px; text-align: center; }
	.wpcc-runtime-node strong { display: block; font-size: 18px; color: #2271b1; }
	.wpcc-runtime-arrow { color: #646970; font-weight: 700; }
	.wpcc-empty-state { text-align: center; padding: 28px 16px; color: #646970; background: #f6f7f7; border: 1px dashed #c3c4c7; }
	.wpcc-status-badge { display: inline-block; border-radius: 999px; padding: 2px 9px; font-size: 11px; font-weight: 700; text-transform: uppercase; background: #f0f0f1; color: #50575e; }
	.wpcc-status-open, .wpcc-status-queued, .wpcc-status-running, .wpcc-status-executing { background: #e7f3ff; color: #135e96; }
	.wpcc-status-completed, .wpcc-status-resolved, .wpcc-status-passed, .wpcc-status-approved { background: #edfaef; color: #187a2f; }
	.wpcc-status-failed, .wpcc-status-critical, .wpcc-status-rejected { background: #fcf0f1; color: #b32d2e; }
	.wpcc-status-warning, .wpcc-status-medium, .wpcc-status-pending_review, .wpcc-status-plan_created { background: #fcf9e8; color: #8a6500; }
	.wpcc-status-info, .wpcc-status-low, .wpcc-status-dismissed { background: #f0f6fc; color: #2271b1; }
	.wpcc-timeline-filters { display: grid; grid-template-columns: 1fr 1fr auto; gap: 8px; margin-bottom: 15px; }
	.wpcc-pagination { display: flex; justify-content: space-between; align-items: center; margin-top: 12px; }
</style>

<div class="wrap wpcc-wrap">
	<h1><?php esc_html_e( 'Agent Runtime Dashboard', 'wp-command-center' ); ?></h1>
	<?php if ( 'production' === $wpcc_environment_mode ) : ?>
		<div class="notice notice-warning"><p><strong><?php esc_html_e( 'Production environment:', 'wp-command-center' ); ?></strong> <?php esc_html_e( 'Cleanup and high-risk operations require additional confirmation. Review all approvals carefully.', 'wp-command-center' ); ?></p></div>
	<?php elseif ( 'staging' === $wpcc_environment_mode ) : ?>
		<div class="notice notice-info"><p><strong><?php esc_html_e( 'Staging environment:', 'wp-command-center' ); ?></strong> <?php esc_html_e( 'Validate changes here before production deployment.', 'wp-command-center' ); ?></p></div>
	<?php else : ?>
		<div class="notice notice-info"><p><strong><?php esc_html_e( 'Development environment:', 'wp-command-center' ); ?></strong> <?php esc_html_e( 'Runtime data may be cleaned using the guarded cleanup endpoint.', 'wp-command-center' ); ?></p></div>
	<?php endif; ?>
	
	<?php if ( ! empty( $sr_success_msg ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $sr_success_msg ); ?></p></div>
	<?php endif; ?>
	<?php if ( ! empty( $sr_error ) ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $sr_error ); ?></p></div>
	<?php endif; ?>

	<div class="wpcc-dashboard-grid">
		<div class="wpcc-card"><h3>Active Sessions</h3><div class="num"><?php echo esc_html( $active_sessions ); ?></div></div>
		<div class="wpcc-card"><h3>Open Tasks</h3><div class="num"><?php echo esc_html( $open_tasks ); ?></div></div>
		<div class="wpcc-card"><h3>Proposed Actions</h3><div class="num"><?php echo esc_html( $proposed_actions ); ?></div></div>
		<div class="wpcc-card"><h3>Pending Plans</h3><div class="num"><?php echo esc_html( $pending_plans ); ?></div></div>
		<div class="wpcc-card"><h3>Pending Op Requests</h3><div class="num"><?php echo esc_html( $pending_req_cnt ); ?></div></div>
		<div class="wpcc-card"><h3>Queued Ops</h3><div class="num"><?php echo esc_html( count( $queued_ops ) ); ?></div></div>
		<div class="wpcc-card"><h3>Applied Patches</h3><div class="num"><?php echo esc_html( $applied_patches ); ?></div></div>
		<div class="wpcc-card"><h3>Failed Queue Items</h3><div class="num"><?php echo esc_html( count( $failed_ops ) ); ?></div></div>
		<div class="wpcc-card"><h3>Open Recommendations</h3><div class="num"><?php echo esc_html( $open_recommendation_count ); ?></div></div>
		<div class="wpcc-card"><h3>Critical Recommendations</h3><div class="num"><?php echo esc_html( $critical_recommendation_count ); ?></div></div>
		<div class="wpcc-card"><h3>Awaiting Plan</h3><div class="num"><?php echo esc_html( $awaiting_plan_recommendation_count ); ?></div></div>
		<div class="wpcc-card"><h3>Awaiting Approval</h3><div class="num"><?php echo esc_html( $awaiting_approval_recommendation_count ); ?></div></div>
		<div class="wpcc-card"><h3>In Progress</h3><div class="num"><?php echo esc_html( $in_progress_recommendation_count ); ?></div></div>
		<div class="wpcc-card"><h3>Resolved</h3><div class="num"><?php echo esc_html( $resolved_recommendation_count ); ?></div></div>
		<div class="wpcc-card"><h3>WP-CLI <?php echo $wpcc_wpcli_avail ? esc_html__( 'Available', 'wp-command-center' ) : esc_html__( 'Unavailable', 'wp-command-center' ); ?></h3><div class="num"><span class="wpcc-status-badge wpcc-status-<?php echo $wpcc_wpcli_avail ? 'completed' : 'failed'; ?>"><?php echo $wpcc_wpcli_avail ? esc_html__( 'Yes', 'wp-command-center' ) : esc_html__( 'No', 'wp-command-center' ); ?></span></div></div>
		<div class="wpcc-card"><h3>WP-CLI Commands</h3><div class="num"><?php echo esc_html( $wpcc_wpcli_count ); ?></div><div style="font-size:11px;margin-top:4px;"><small><?php printf( esc_html__( 'Low: %d | Med: %d | High: %d | Crit: %d', 'wp-command-center' ), $wpcc_wpcli_risk['low'], $wpcc_wpcli_risk['medium'], $wpcc_wpcli_risk['high'], $wpcc_wpcli_risk['critical'] ); ?></small></div></div>
		<div class="wpcc-card"><h3>Managed Options</h3><div class="num"><?php echo esc_html( $wpcc_options_count ); ?></div><div style="font-size:11px;margin-top:4px;"><small><?php printf( esc_html__( 'Low: %d | Med: %d | High: %d', 'wp-command-center' ), $wpcc_options_risk['low'], $wpcc_options_risk['medium'], $wpcc_options_risk['high'] ); ?></small></div></div>
		<div class="wpcc-card"><h3>Plugins</h3><div class="num"><?php echo esc_html( $wpcc_plugin_state['total'] ); ?></div><div style="font-size:11px;margin-top:4px;"><small><?php printf( esc_html__( 'Active: %d | Inactive: %d | Updates: %d', 'wp-command-center' ), $wpcc_plugin_state['active'], $wpcc_plugin_state['inactive'], $wpcc_plugin_state['updates'] ); ?></small></div></div>
		<div class="wpcc-card"><h3>Themes</h3><div class="num"><?php echo esc_html( $wpcc_theme_state['total'] ); ?></div><div style="font-size:11px;margin-top:4px;"><small><?php printf( esc_html__( 'Active: %s', 'wp-command-center' ), $wpcc_active_theme ? esc_html( $wpcc_active_theme['name'] ) : esc_html__( 'None', 'wp-command-center' ) ); ?></small></div></div>
		<div class="wpcc-card"><h3>Snapshots</h3><div class="num"><?php echo esc_html( $wpcc_snap_count ); ?></div><div style="font-size:11px;margin-top:4px;"><small><?php esc_html_e( 'File recovery points', 'wp-command-center' ); ?></small></div></div>
		<div class="wpcc-card"><h3>Database</h3><div class="num" style="font-size:20px;"><?php global $wpdb; $sz = $wpdb->get_var("SELECT ROUND(SUM(DATA_LENGTH+INDEX_LENGTH)/1024/1024,1) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()"); echo esc_html( $sz ?? '0' ); ?> MB</div></div>
		<div class="wpcc-card"><h3>MCP Server</h3><div class="num" style="font-size:16px;"><?php esc_html_e( 'Active', 'wp-command-center' ); ?></div><div style="font-size:11px;margin-top:4px;"><small><?php esc_html_e( 'JSON-RPC 2.0', 'wp-command-center' ); ?></small></div></div>
		<div class="wpcc-card"><h3>AI Integrations</h3><div class="num" style="font-size:20px;"><?php esc_html_e( 'Active', 'wp-command-center' ); ?></div><div style="font-size:11px;margin-top:4px;"><small><?php printf( esc_html__( 'Clients: %d | Tools: %d | Resources: %d', 'wp-command-center' ), \WPCommandCenter\Integration\AIClientRegistry::get_counts()['active'], count( ( new \WPCommandCenter\Operations\OperationRegistry() )->get_operations() ), 7 ); ?></small></div><div style="margin-top:8px;"><a href="<?php echo esc_url( admin_url( 'admin.php?page=wpcc-ai-integrations' ) ); ?>" class="button button-small"><?php esc_html_e( 'View AI Integrations', 'wp-command-center' ); ?></a> <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpcc-ai-integrations' ) ); ?>" class="button button-small"><?php esc_html_e( 'Manage Clients', 'wp-command-center' ); ?></a></div></div>
		<div class="wpcc-card"><h3>Content</h3><div class="num"><?php echo esc_html( $wpcc_content_counts['post_count'] + $wpcc_content_counts['page_count'] ); ?></div><div style="font-size:11px;margin-top:4px;"><small><?php printf( esc_html__( 'Published | Drafts: %d', 'wp-command-center' ), $wpcc_content_counts['post_draft'] + $wpcc_content_counts['page_draft'] ); ?></small></div></div>
		<div class="wpcc-card"><h3>Media</h3><div class="num"><?php $media_count = (int) wp_count_posts( 'attachment' )->inherit; echo esc_html( $media_count ); ?></div><div style="font-size:11px;margin-top:4px;"><small><?php esc_html_e( 'Attachments', 'wp-command-center' ); ?></small></div></div>
		<div class="wpcc-card"><h3>Comments</h3><div class="num"><?php $comment_count = (int) wp_count_comments()->total_comments; echo esc_html( $comment_count ); ?></div><div style="font-size:11px;margin-top:4px;"><small><?php echo esc_html( wp_count_comments()->approved ?? 0 ); ?> <?php esc_html_e( 'approved', 'wp-command-center' ); ?></small></div></div>
		<div class="wpcc-card"><h3>WooCommerce</h3><div class="num"><?php echo class_exists('WooCommerce') ? esc_html(wp_count_posts('product')->publish ?? 0) : '—'; ?></div><div style="font-size:11px;margin-top:4px;"><small><?php esc_html_e('Products', 'wp-command-center'); ?></small></div></div>
		<div class="wpcc-card"><h3>ACF</h3><div class="num"><?php echo function_exists('acf_get_field_groups') ? esc_html(count(acf_get_field_groups())) : '—'; ?></div><div style="font-size:11px;margin-top:4px;"><small><?php esc_html_e('Field Groups', 'wp-command-center'); ?></small></div></div>
		<div class="wpcc-card"><h3>Forms</h3><div class="num"><?php echo defined('WPCF7_VERSION') ? esc_html(wp_count_posts('wpcf7_contact_form')->publish ?? 0) : '—'; ?></div><div style="font-size:11px;margin-top:4px;"><small><?php esc_html_e('Contact Forms', 'wp-command-center'); ?></small></div></div>
		<div class="wpcc-card"><h3>Menus</h3><div class="num"><?php echo esc_html(count(wp_get_nav_menus())); ?></div><div style="font-size:11px;margin-top:4px;"><small><?php esc_html_e('Navigation Menus', 'wp-command-center'); ?></small></div></div>
		<div class="wpcc-card"><h3>Site</h3><div class="num" style="font-size:16px;"><?php echo esc_html(get_option('blogname')); ?></div><div style="font-size:11px;margin-top:4px;"><small><?php printf(esc_html__('%s | %s','wp-command-center'),get_option('timezone_string')?:'UTC',get_option('WPLANG')?:'en_US'); ?></small></div></div>
		<div class="wpcc-card"><h3>Search</h3><div class="num" style="font-size:16px;"><?php esc_html_e('Universal','wp-command-center'); ?></div><div style="font-size:11px;margin-top:4px;"><small><?php esc_html_e('Content • Media • Users • Woo • ACF','wp-command-center'); ?></small></div></div>
		<div class="wpcc-card"><h3>Bulk Operations</h3><div class="num" style="font-size:16px;">7</div><div style="font-size:11px;margin-top:4px;"><small><?php printf(esc_html__('Actions | Rollbacks: %d','wp-command-center'), count(get_option('wpcc_bulk_rollbacks',[]))); ?></small></div></div>
		<div class="wpcc-card"><h3>Workflows</h3><div class="num"><?php $wpcc_workflow_count = count(get_option('wpcc_workflows',[])); echo esc_html($wpcc_workflow_count); ?></div><div style="font-size:11px;margin-top:4px;"><small><?php printf(esc_html__('Executions: %d','wp-command-center'), count(get_option('wpcc_workflow_history',[]))); ?></small></div></div>
		<div class="wpcc-card"><h3>Widgets</h3><div class="num"><?php echo esc_html( $wpcc_widgets_count ); ?></div><div style="font-size:11px;margin-top:4px;"><small><?php printf( esc_html__( '%d sidebars', 'wp-command-center' ), $wpcc_sidebars_count ); ?></small></div></div>
		<div class="wpcc-card"><h3>Custom Post Types</h3><div class="num"><?php echo esc_html( $wpcc_cpt_count ); ?></div><div style="font-size:11px;margin-top:4px;"><small><?php printf( esc_html__( '%d taxonomies', 'wp-command-center' ), $wpcc_cpt_tax_count ); ?></small></div></div>
	</div>

	<div class="wpcc-panel">
		<h2 class="wpcc-panel-header"><?php esc_html_e( 'Runtime Hierarchy', 'wp-command-center' ); ?></h2>
		<div class="wpcc-panel-body wpcc-runtime-flow">
			<div class="wpcc-runtime-node"><strong><?php echo esc_html( $active_sessions ); ?></strong><?php esc_html_e( 'Sessions', 'wp-command-center' ); ?></div><span class="wpcc-runtime-arrow">&rarr;</span>
			<div class="wpcc-runtime-node"><strong><?php echo esc_html( $open_tasks ); ?></strong><?php esc_html_e( 'Tasks', 'wp-command-center' ); ?></div><span class="wpcc-runtime-arrow">&rarr;</span>
			<div class="wpcc-runtime-node"><strong><?php echo esc_html( $proposed_actions ); ?></strong><?php esc_html_e( 'Actions', 'wp-command-center' ); ?></div><span class="wpcc-runtime-arrow">&rarr;</span>
			<div class="wpcc-runtime-node"><strong><?php echo esc_html( $pending_plans ); ?></strong><?php esc_html_e( 'Plans', 'wp-command-center' ); ?></div><span class="wpcc-runtime-arrow">&rarr;</span>
			<div class="wpcc-runtime-node"><strong><?php echo esc_html( $pending_req_cnt ); ?></strong><?php esc_html_e( 'Requests', 'wp-command-center' ); ?></div><span class="wpcc-runtime-arrow">&rarr;</span>
			<div class="wpcc-runtime-node"><strong><?php echo esc_html( count( $queued_ops ) ); ?></strong><?php esc_html_e( 'Queue', 'wp-command-center' ); ?></div><span class="wpcc-runtime-arrow">&rarr;</span>
			<div class="wpcc-runtime-node"><strong><?php echo esc_html( count( $wpcc_recent_results ) ); ?></strong><?php esc_html_e( 'Results', 'wp-command-center' ); ?></div>
		</div>
	</div>

	<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px;">
		
		<!-- Left Column: Pending Reviews & Trees -->
		<div>
			<div class="wpcc-panel">
				<h2 class="wpcc-panel-header"><?php esc_html_e( 'Recent Recommendations', 'wp-command-center' ); ?></h2>
				<div class="wpcc-panel-body">
					<?php if ( empty( $recent_recommendations ) ) : ?>
						<p><?php esc_html_e( 'No recommendation scans have produced findings yet.', 'wp-command-center' ); ?></p>
					<?php else : ?>
						<table class="wp-list-table widefat fixed striped">
							<thead><tr><th><?php esc_html_e( 'Recommendation', 'wp-command-center' ); ?></th><th><?php esc_html_e( 'Severity', 'wp-command-center' ); ?></th><th><?php esc_html_e( 'Status', 'wp-command-center' ); ?></th></tr></thead>
							<tbody>
							<?php foreach ( $recent_recommendations as $recommendation ) : ?>
								<tr>
									<td><strong><?php echo esc_html( $recommendation['title'] ); ?></strong><br><small><?php echo esc_html( $recommendation['type'] ); ?></small></td>
								<td><span class="wpcc-status-badge wpcc-status-<?php echo esc_attr( $recommendation['severity'] ); ?>"><?php echo esc_html( $recommendation['severity'] ); ?></span></td>
								<td><span class="wpcc-status-badge wpcc-status-<?php echo esc_attr( $recommendation['status'] ); ?>"><?php echo esc_html( str_replace( '_', ' ', $recommendation['status'] ) ); ?></span></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</div>

			<!-- Safe Search & Replace -->
			<div class="wpcc-panel">
				<h2 class="wpcc-panel-header"><?php esc_html_e( 'Safe Search & Replace', 'wp-command-center' ); ?></h2>
				<div class="wpcc-panel-body">
					<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
						<div class="wpcc-sr-form">
							<form method="post" id="wpcc-sr-form">
								<?php wp_nonce_field( 'wpcc_sr_action' ); ?>
								<input type="hidden" name="confirmed" id="wpcc-sr-confirmed" value="0">
								<p>
									<label><strong><?php esc_html_e( 'Search For:', 'wp-command-center' ); ?></strong></label>
									<input type="text" name="search" id="wpcc-sr-search" placeholder="e.g. old-domain.com" value="<?php echo esc_attr( wp_unslash( (string) ( $_POST['search'] ?? '' ) ) ); ?>" required>
								</p>
								<p>
									<label><strong><?php esc_html_e( 'Replace With:', 'wp-command-center' ); ?></strong></label>
									<input type="text" name="replace" id="wpcc-sr-replace" placeholder="e.g. new-domain.com" value="<?php echo esc_attr( wp_unslash( (string) ( $_POST['replace'] ?? '' ) ) ); ?>">
								</p>
								<p>
									<label><strong><?php esc_html_e( 'Table Preset:', 'wp-command-center' ); ?></strong></label>
									<select id="wpcc-sr-preset">
										<option value=""><?php esc_html_e( '— Select a preset —', 'wp-command-center' ); ?></option>
										<option value="content"><?php esc_html_e( 'Content Tables', 'wp-command-center' ); ?></option>
										<option value="content_meta"><?php esc_html_e( 'Content + Meta', 'wp-command-center' ); ?></option>
										<option value="options"><?php esc_html_e( 'Options', 'wp-command-center' ); ?></option>
										<option value="all"><?php esc_html_e( 'All WordPress Content', 'wp-command-center' ); ?></option>
										<option value="custom"><?php esc_html_e( 'Custom Selection', 'wp-command-center' ); ?></option>
									</select>
								</p>
								<p>
									<label><strong><?php esc_html_e( 'Target Tables:', 'wp-command-center' ); ?></strong></label>
									<label style="font-weight: normal; float: right;">
										<input type="checkbox" id="wpcc-sr-show-system"> <?php esc_html_e( 'Show System Tables', 'wp-command-center' ); ?>
									</label>
									<div class="wpcc-sr-tables">
										<?php foreach ( $wp_tables as $table ) :
											$group     = $wpcc_table_groups[ $table ];
											$is_system = ( 'system' === $group );
											$suffix    = substr( $table, strlen( $wpdb->prefix ) );
										?>
											<label class="wpcc-sr-table-row<?php echo $is_system ? ' wpcc-sr-system-row' : ''; ?>"<?php echo $is_system ? ' style="display:none;"' : ''; ?>>
												<input type="checkbox" name="tables[]" value="<?php echo esc_attr( $table ); ?>" data-group="<?php echo esc_attr( $group ); ?>" data-suffix="<?php echo esc_attr( $suffix ); ?>" <?php checked( in_array( $table, $wpcc_checked_tables, true ) ); ?>>
												<?php echo esc_html( $table ); ?>
											</label>
										<?php endforeach; ?>
									</div>
								</p>
								<p>
									<label><input type="checkbox" name="dry_run" id="wpcc-sr-dry-run" value="1" <?php checked( $wpcc_dry_run_checked ); ?>> <?php esc_html_e( 'Dry Run (Preview changes only)', 'wp-command-center' ); ?></label>
								</p>
								<p>
									<?php esc_html_e( 'Computed Risk Level:', 'wp-command-center' ); ?>
									<span id="wpcc-sr-risk-badge" class="wpcc-risk-badge wpcc-risk-low">LOW</span>
								</p>
								<p>
									<button type="submit" name="wpcc_sr_action" value="run" id="wpcc-sr-submit-btn" class="button button-primary"><?php esc_html_e( 'Run Dry Preview', 'wp-command-center' ); ?></button>
								</p>
								<noscript>
									<p style="color: #d63638;"><?php esc_html_e( 'JavaScript is required to create a live Search & Replace request (a confirmation dialog is shown first). Dry Run previews work without JavaScript.', 'wp-command-center' ); ?></p>
								</noscript>
							</form>
						</div>
						<div>
							<?php if ( $sr_preview ) : ?>
								<div class="wpcc-panel" style="margin-top: 10px; border-color: #2271b1;">
									<h3 class="wpcc-panel-header" style="font-size: 14px; padding: 10px 15px;"><?php esc_html_e( 'Dry Run Preview', 'wp-command-center' ); ?></h3>
									<div class="wpcc-panel-body" style="padding: 15px;">
										<table class="wpcc-sr-preview-table">
											<tr>
												<td><?php esc_html_e( 'Matches Found:', 'wp-command-center' ); ?></td>
												<td><?php echo esc_html( (string) $sr_preview['matches_found'] ); ?></td>
											</tr>
											<tr>
												<td><?php esc_html_e( 'Affected Rows:', 'wp-command-center' ); ?></td>
												<td><?php echo esc_html( (string) $sr_preview['rows_affected'] ); ?></td>
											</tr>
											<tr>
												<td><?php esc_html_e( 'Affected Tables:', 'wp-command-center' ); ?></td>
												<td>
													<?php
													echo $sr_preview['tables_affected']
														? esc_html( implode( ', ', $sr_preview['tables_affected'] ) )
														: esc_html__( 'None — no matches in the selected tables.', 'wp-command-center' );
													?>
												</td>
											</tr>
											<tr>
												<td><?php esc_html_e( 'Risk Level:', 'wp-command-center' ); ?></td>
												<td><span class="wpcc-risk-badge wpcc-risk-<?php echo esc_attr( $sr_preview['risk_level'] ); ?>"><?php echo esc_html( strtoupper( $sr_preview['risk_level'] ) ); ?></span></td>
											</tr>
										</table>
										<p><small><em><?php echo esc_html( $sr_preview['warning'] ); ?></em></small></p>
									</div>
								</div>
							<?php else : ?>
								<div style="background: #f6f7f7; border: 1px dashed #ccd0d4; padding: 20px; text-align: center; color: #646970;">
									<?php esc_html_e( 'Enter search parameters, choose tables, and click "Run Dry Preview" to see matches found, affected rows, affected tables, and the computed risk level.', 'wp-command-center' ); ?>
								</div>
							<?php endif; ?>
						</div>
					</div>
				</div>
			</div>

			<!-- Safe Search & Replace: confirmation dialog for live (non-dry-run) requests.
			     Lives outside the form so it can overlay the page; its submit button
			     uses form="wpcc-sr-form" to submit the form above. -->
			<div class="wpcc-modal-overlay" id="wpcc-sr-confirm-overlay">
				<div class="wpcc-modal">
					<h3><?php esc_html_e( 'Confirm Search & Replace Request', 'wp-command-center' ); ?></h3>
					<table>
						<tr><th><?php esc_html_e( 'Search For', 'wp-command-center' ); ?></th><td id="wpcc-confirm-search"></td></tr>
						<tr><th><?php esc_html_e( 'Replace With', 'wp-command-center' ); ?></th><td id="wpcc-confirm-replace"></td></tr>
						<tr><th><?php esc_html_e( 'Affected Tables', 'wp-command-center' ); ?></th><td id="wpcc-confirm-tables"></td></tr>
						<tr><th><?php esc_html_e( 'Affected Rows', 'wp-command-center' ); ?></th><td id="wpcc-confirm-rows"></td></tr>
						<tr><th><?php esc_html_e( 'Risk Level', 'wp-command-center' ); ?></th><td><span id="wpcc-confirm-risk" class="wpcc-risk-badge"></span></td></tr>
					</table>
					<p><?php esc_html_e( 'This creates a pending operation request only — no data changes until it is approved and executed from the Approval Center (Operate › Approvals), or by the background worker.', 'wp-command-center' ); ?></p>
					<div class="wpcc-modal-actions">
						<button type="button" class="button" id="wpcc-sr-confirm-cancel"><?php esc_html_e( 'Cancel', 'wp-command-center' ); ?></button>
						<button type="submit" class="button button-primary" name="wpcc_sr_action" value="run" form="wpcc-sr-form" id="wpcc-sr-confirm-submit"><?php esc_html_e( 'Confirm & Create Request', 'wp-command-center' ); ?></button>
					</div>
				</div>
			</div>

			<script>
			( function () {
				var PRESETS      = <?php echo wp_json_encode( $wpcc_presets ); ?>;
				var LAST_PREVIEW = <?php echo wp_json_encode( $sr_preview_js ); ?>;

				var LABEL_DRY_RUN   = <?php echo wp_json_encode( __( 'Run Dry Preview', 'wp-command-center' ) ); ?>;
				var LABEL_LIVE_RUN  = <?php echo wp_json_encode( __( 'Create Replace Request', 'wp-command-center' ) ); ?>;
				var LABEL_NONE      = <?php echo wp_json_encode( __( 'None selected', 'wp-command-center' ) ); ?>;
				var LABEL_FROM_PREVIEW = <?php echo wp_json_encode( ' ' . __( '(from last Dry Preview)', 'wp-command-center' ) ); ?>;
				var LABEL_UNKNOWN_ROWS = <?php echo wp_json_encode( __( 'Unknown — run "Run Dry Preview" first for an exact count.', 'wp-command-center' ) ); ?>;

				var form         = document.getElementById( 'wpcc-sr-form' );
				var dryRunCb     = document.getElementById( 'wpcc-sr-dry-run' );
				var submitBtn    = document.getElementById( 'wpcc-sr-submit-btn' );
				var presetSelect = document.getElementById( 'wpcc-sr-preset' );
				var showSystemCb = document.getElementById( 'wpcc-sr-show-system' );
				var tableBoxes   = Array.prototype.slice.call( form.querySelectorAll( 'input[name="tables[]"]' ) );
				var riskBadge    = document.getElementById( 'wpcc-sr-risk-badge' );
				var searchInput  = document.getElementById( 'wpcc-sr-search' );
				var replaceInput = document.getElementById( 'wpcc-sr-replace' );
				var confirmedFld = document.getElementById( 'wpcc-sr-confirmed' );
				var overlay      = document.getElementById( 'wpcc-sr-confirm-overlay' );

				function computeRisk() {
					var checked = tableBoxes.filter( function ( cb ) { return cb.checked; } );
					if ( ! checked.length ) {
						return 'low';
					}

					var hasSystem = false, hasOptions = false, onlyPostsPostmeta = true;
					checked.forEach( function ( cb ) {
						var group  = cb.getAttribute( 'data-group' );
						var suffix = cb.getAttribute( 'data-suffix' );
						if ( 'system' === group ) {
							hasSystem = true;
						}
						if ( 'options' === group ) {
							hasOptions = true;
						}
						if ( 'posts' !== suffix && 'postmeta' !== suffix ) {
							onlyPostsPostmeta = false;
						}
					} );

					if ( hasSystem ) {
						return 'high';
					}
					if ( hasOptions ) {
						return 'medium';
					}
					if ( onlyPostsPostmeta ) {
						return 'low';
					}

					return 'medium';
				}

				function paintRisk( el, risk ) {
					el.textContent = risk.toUpperCase();
					el.className   = 'wpcc-risk-badge wpcc-risk-' + risk;
				}

				function refreshRisk() {
					paintRisk( riskBadge, computeRisk() );
				}

				tableBoxes.forEach( function ( cb ) {
					cb.addEventListener( 'change', refreshRisk );
				} );
				refreshRisk();

				// Table presets: replace the current selection with the preset's tables.
				presetSelect.addEventListener( 'change', function () {
					var preset = this.value;
					if ( ! preset || 'custom' === preset ) {
						return;
					}
					var list = PRESETS[ preset ] || [];
					tableBoxes.forEach( function ( cb ) {
						cb.checked = list.indexOf( cb.value ) !== -1;
					} );
					refreshRisk();
				} );

				// Show/hide WP Command Center's own (wpcc_*) tables.
				showSystemCb.addEventListener( 'change', function () {
					var rows = form.querySelectorAll( '.wpcc-sr-system-row' );
					rows.forEach( function ( row ) {
						row.style.display = showSystemCb.checked ? '' : 'none';
					} );
				} );

				// Toggle between an immediate Dry Run submit and a confirmation
				// dialog before creating a live operation request.
				function refreshMode() {
					if ( dryRunCb.checked ) {
						submitBtn.textContent = LABEL_DRY_RUN;
						submitBtn.type        = 'submit';
					} else {
						submitBtn.textContent = LABEL_LIVE_RUN;
						submitBtn.type        = 'button';
					}
				}
				dryRunCb.addEventListener( 'change', refreshMode );
				refreshMode();

				submitBtn.addEventListener( 'click', function ( e ) {
					if ( dryRunCb.checked ) {
						return; // Dry Run: submit normally.
					}
					e.preventDefault();

					var tables = tableBoxes.filter( function ( cb ) { return cb.checked; } ).map( function ( cb ) { return cb.value; } );
					var risk   = computeRisk();

					document.getElementById( 'wpcc-confirm-search' ).textContent  = searchInput.value;
					document.getElementById( 'wpcc-confirm-replace' ).textContent = replaceInput.value;
					document.getElementById( 'wpcc-confirm-tables' ).textContent  = tables.length ? tables.join( ', ' ) : LABEL_NONE;
					paintRisk( document.getElementById( 'wpcc-confirm-risk' ), risk );

					var rowsEl       = document.getElementById( 'wpcc-confirm-rows' );
					var sortedTables = tables.slice().sort();
					if (
						LAST_PREVIEW &&
						LAST_PREVIEW.search === searchInput.value &&
						LAST_PREVIEW.replace === replaceInput.value &&
						JSON.stringify( LAST_PREVIEW.tables.slice().sort() ) === JSON.stringify( sortedTables )
					) {
						rowsEl.textContent = LAST_PREVIEW.rows_affected + LABEL_FROM_PREVIEW;
					} else {
						rowsEl.textContent = LABEL_UNKNOWN_ROWS;
					}

					overlay.classList.add( 'is-visible' );
				} );

				document.getElementById( 'wpcc-sr-confirm-cancel' ).addEventListener( 'click', function () {
					overlay.classList.remove( 'is-visible' );
				} );

				document.getElementById( 'wpcc-sr-confirm-submit' ).addEventListener( 'click', function () {
					confirmedFld.value = '1';
				} );
			} )();
			</script>

			<!-- Pending Plans -->
			<?php if ( ! empty( $pending_plans_list ) ) : ?>
			<div class="wpcc-panel">
				<h2 class="wpcc-panel-header">Pending Plans</h2>
				<div class="wpcc-panel-body">
					<table class="wp-list-table widefat fixed striped">
						<thead><tr><th>Title</th><th>Objective</th><th>Actions</th></tr></thead>
						<tbody>
							<?php foreach ( $pending_plans_list as $plan ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $plan['title'] ); ?></strong></td>
								<td><?php echo esc_html( $plan['objective'] ); ?></td>
								<td>
									<form method="post" style="display:inline-block;">
										<?php wp_nonce_field( 'wpcc_dashboard_action' ); ?>
										<input type="hidden" name="id" value="<?php echo esc_attr( $plan['plan_id'] ); ?>">
										<button type="submit" name="wpcc_action" value="approve_plan" class="button button-primary wpcc-action-btn">Approve</button>
										<button type="submit" name="wpcc_action" value="reject_plan" class="button">Reject</button>
									</form>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
			<?php else : ?><div class="wpcc-panel"><div class="wpcc-empty-state"><?php esc_html_e( 'No plans are waiting for approval.', 'wp-command-center' ); ?></div></div><?php endif; ?>

			<?php
			// Operation-request approval and the operation queue moved to the
			// Approval Center (Operate › Approvals). Surface a pointer when either
			// has items pending so Runtime operators know where they went.
			$wpcc_runtime_pending = $pending_req_cnt + count( $queued_ops );
			if ( $wpcc_runtime_pending > 0 ) : ?>
			<div class="wpcc-panel">
				<div class="wpcc-panel-body">
					<p>
						<?php
						printf(
							/* translators: %d: number of pending operation requests + queued operations */
							esc_html__( '%d operation request(s) / queued operation(s) are waiting. Review and run them in the Approval Center.', 'wp-command-center' ),
							(int) $wpcc_runtime_pending
						);
						?>
						<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=wpcc-operate&wpcc_tab=approvals' ) ); ?>"><?php esc_html_e( 'Open Approval Center', 'wp-command-center' ); ?></a>
					</p>
				</div>
			</div>
			<?php endif; ?>

			<div class="wpcc-panel" id="operation-results">
				<h2 class="wpcc-panel-header"><?php esc_html_e( 'Recent Operation Results', 'wp-command-center' ); ?></h2>
				<div class="wpcc-panel-body">
				<?php if ( empty( $wpcc_recent_results ) ) : ?><div class="wpcc-empty-state"><?php esc_html_e( 'No operation results recorded yet.', 'wp-command-center' ); ?></div>
				<?php else : ?><table class="wp-list-table widefat fixed striped"><thead><tr><th><?php esc_html_e( 'Operation', 'wp-command-center' ); ?></th><th><?php esc_html_e( 'Status', 'wp-command-center' ); ?></th><th><?php esc_html_e( 'Result', 'wp-command-center' ); ?></th></tr></thead><tbody>
				<?php foreach ( $wpcc_recent_results as $result ) : ?><tr><td><?php echo esc_html( $result['operation_id'] ); ?></td><td><span class="wpcc-status-badge wpcc-status-<?php echo esc_attr( $result['status'] ); ?>"><?php echo esc_html( $result['status'] ); ?></span></td><td><a href="<?php echo esc_url( add_query_arg( [ 'page' => 'wpcc-operate', 'wpcc_tab' => 'runtime', 'result_id' => $result['result_id'] ], admin_url( 'admin.php' ) ) . '#operation-results' ); ?>"><?php esc_html_e( 'View result', 'wp-command-center' ); ?></a></td></tr><?php endforeach; ?>
				</tbody></table><?php endif; ?>
				<?php if ( $wpcc_selected_result ) : ?><hr><h3><?php esc_html_e( 'Selected Result', 'wp-command-center' ); ?></h3><p><code><?php echo esc_html( $wpcc_selected_result['result_id'] ); ?></code></p><pre style="white-space:pre-wrap;max-height:260px;overflow:auto;background:#f6f7f7;padding:12px;"><?php echo esc_html( wp_json_encode( $wpcc_selected_result, JSON_PRETTY_PRINT ) ); ?></pre><?php endif; ?>
				</div>
			</div>
			
		</div>

		<!-- Right Column: Timeline -->
		<div>
			<div class="wpcc-panel">
				<h2 class="wpcc-panel-header">Recent Agent Activity</h2>
				<div class="wpcc-panel-body">
					<form method="get" class="wpcc-timeline-filters">
						<input type="hidden" name="page" value="wpcc-operate">
						<input type="hidden" name="wpcc_tab" value="runtime">
						<select name="timeline_type"><option value=""><?php esc_html_e( 'All event types', 'wp-command-center' ); ?></option><?php foreach ( [ 'session', 'task', 'action', 'plan', 'patch', 'recommendation', 'operation', 'health', 'system', 'workflow' ] as $type ) : ?><option value="<?php echo esc_attr( $type ); ?>" <?php selected( $wpcc_timeline_type, $type ); ?>><?php echo esc_html( ucfirst( $type ) ); ?></option><?php endforeach; ?></select>
						<select name="timeline_status"><option value=""><?php esc_html_e( 'All statuses', 'wp-command-center' ); ?></option><?php foreach ( [ 'open', 'pending_review', 'approved', 'queued', 'running', 'completed', 'resolved', 'failed', 'rejected', 'dismissed' ] as $status ) : ?><option value="<?php echo esc_attr( $status ); ?>" <?php selected( $wpcc_timeline_status, $status ); ?>><?php echo esc_html( ucfirst( str_replace( '_', ' ', $status ) ) ); ?></option><?php endforeach; ?></select>
						<button class="button"><?php esc_html_e( 'Filter', 'wp-command-center' ); ?></button>
					</form>
					<?php if ( empty( $timeline ) ) : ?>
						<div class="wpcc-empty-state"><?php esc_html_e( 'No activity matches the selected filters.', 'wp-command-center' ); ?></div>
					<?php else : ?>
						<ul class="wpcc-timeline">
							<?php foreach ( $timeline as $event ) : ?>
							<li>
								<span class="time"><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $event['timestamp'] ) ); ?></span>
								<span class="label"><?php echo esc_html( $event['label'] ); ?></span>
								<span class="wpcc-status-badge wpcc-status-<?php echo esc_attr( $event['status'] ); ?>"><?php echo esc_html( str_replace( '_', ' ', $event['status'] ) ); ?></span>
								<?php if ( ! empty( $event['summary'] ) ) : ?>
									<span class="summary"><?php echo esc_html( $event['summary'] ); ?></span>
								<?php endif; ?>
							</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
					<div class="wpcc-pagination"><span><?php echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'wp-command-center' ), $wpcc_timeline_page, $wpcc_timeline_total_pages ) ); ?></span><span><?php if ( $wpcc_timeline_page > 1 ) : ?><a class="button" href="<?php echo esc_url( add_query_arg( [ 'page' => 'wpcc-operate', 'wpcc_tab' => 'runtime', 'timeline_type' => $wpcc_timeline_type, 'timeline_status' => $wpcc_timeline_status, 'timeline_page' => $wpcc_timeline_page - 1 ], admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Previous', 'wp-command-center' ); ?></a><?php endif; ?> <?php if ( $wpcc_timeline_page < $wpcc_timeline_total_pages ) : ?><a class="button" href="<?php echo esc_url( add_query_arg( [ 'page' => 'wpcc-operate', 'wpcc_tab' => 'runtime', 'timeline_type' => $wpcc_timeline_type, 'timeline_status' => $wpcc_timeline_status, 'timeline_page' => $wpcc_timeline_page + 1 ], admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Next', 'wp-command-center' ); ?></a><?php endif; ?></span></div>
				</div>
			</div>
		</div>
		
	</div>
</div>
