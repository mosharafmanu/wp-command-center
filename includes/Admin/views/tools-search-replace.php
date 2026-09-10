<?php
/**
 * Settings › Tools — Safe Search & Replace (governed maintenance tool).
 *
 * Phase 2A of the Runtime migration: the Search & Replace tool is re-homed here from
 * the legacy Runtime dashboard, UNCHANGED in behavior. It creates a governed
 * `safe_search_replace` operation request (OperationManager) that must be approved and
 * run from Approvals; Dry Run auto-approves and runs a single preview only.
 * The dry-run risk model, the confirmation dialog for live requests, and the full
 * approval/audit path are preserved exactly — no engine, REST, capability, or schema
 * change. (Runtime keeps its own copy until Phase 2B; this is the intentional,
 * temporary duplication called out in the migration blueprint.)
 */

defined( 'ABSPATH' ) || exit;

global $wpdb;

$op_manager = new \WPCommandCenter\Operations\OperationManager();
$op_queue   = new \WPCommandCenter\Operations\OperationQueue();

// OperationQueue::run_item() forwards context['actor'] straight to
// AuditLog::resolve_actor(). That now normalises a malformed actor rather than
// throwing, but a scalar is only ever recorded as an unverified label — build the
// full array here so the audit trail carries real admin attribution.
$wpcc_actor_context = [
	'actor' => [
		'type'       => 'admin',
		'user_id'    => get_current_user_id(),
		'user_login' => wp_get_current_user()->user_login,
	],
];

/**
 * What class of data is this table?
 *
 * The old model had three buckets — plugin-internal, options, everything else —
 * so `wp_users`, session stores, token tables and OAuth credentials all landed
 * in "everything else" and were scored MEDIUM, the same as a category table. A
 * customer running a domain replace across the whole database was told the risk
 * was medium while the operation could rewrite credentials.
 *
 * Classification is by suffix, and errs toward caution: anything whose name
 * suggests authentication, sessions or secrets is sensitive even if this
 * install has never heard of it, because an unknown table matching those words
 * is exactly the one not to guess about.
 */
$wpcc_classify_table = function ( string $suffix ): string {
	if ( str_starts_with( $suffix, 'wpcc_' ) ) {
		return 'system';
	}
	if ( in_array( $suffix, [ 'users', 'usermeta' ], true ) ) {
		return 'users';
	}
	// Auth / session / secret surfaces, including third-party ones this install
	// may have that WP Command Center knows nothing about.
	if ( preg_match( '/(session|token|oauth|auth|login|password|secret|api_key|apikey|nonce|credential|2fa|totp)/i', $suffix ) ) {
		return 'security';
	}
	if ( in_array( $suffix, [ 'posts', 'terms', 'term_taxonomy', 'term_relationships', 'comments', 'links' ], true ) ) {
		return 'content';
	}
	if ( in_array( $suffix, [ 'postmeta', 'termmeta', 'commentmeta' ], true ) ) {
		return 'meta';
	}
	if ( 'options' === $suffix ) {
		return 'options';
	}
	return 'other';
};

/**
 * Risk for the chosen tables — five tiers that mean what they say.
 *
 * Drives the badge, and (above `medium`) the dry-run-before-live requirement.
 * Breadth counts too: replacing one string across the entire database is a
 * different act from replacing it in posts, whatever the individual tables are.
 */
$wpcc_compute_risk = function ( array $tables ) use ( $wpdb, $wpcc_classify_table ): string {
	if ( empty( $tables ) ) {
		return 'low';
	}

	$classes = [];
	foreach ( $tables as $table ) {
		$classes[] = $wpcc_classify_table( substr( $table, strlen( $wpdb->prefix ) ) );
	}
	$classes = array_unique( $classes );

	// Anything that can rewrite credentials, sessions or the plugin's own
	// governance state is critical regardless of how few tables are selected.
	foreach ( [ 'users', 'security', 'system' ] as $critical ) {
		if ( in_array( $critical, $classes, true ) ) {
			return 'critical';
		}
	}
	// A broad sweep across many tables is critical on breadth alone: the
	// customer cannot have reviewed what a single string touches in all of them.
	if ( count( $tables ) >= 8 ) {
		return 'critical';
	}
	if ( in_array( 'options', $classes, true ) ) {
		return 'high';
	}
	if ( in_array( 'other', $classes, true ) ) {
		return 'high';
	}
	// Content and meta only. Still a real write to live content, so never "low"
	// once something is selected — low is reserved for "nothing chosen yet".
	return 'medium';
};

// Handle Search & Replace UI (same governed flow as the legacy Runtime view).
$sr_result        = null;
$sr_preview       = null;
$sr_posted_tables = [];
$sr_error         = '';
$sr_success_msg   = '';

if ( isset( $_POST['wpcc_sr_action'] ) && check_admin_referer( 'wpcc_sr_action' ) && current_user_can( 'manage_options' ) ) {
	/*
	 * wp_unslash() FIRST. WordPress slashes $_POST, so without it a search for
	 * O'Brien was sent to the operation as O\'Brien and matched nothing — and a
	 * replacement containing a quote or backslash wrote the escaped form into the
	 * customer's content. The redisplay further down this file already unslashed
	 * these two fields, which is what made the omission here visible.
	 *
	 * search/replace are deliberately NOT sanitized beyond unslashing: this tool
	 * exists to find and replace arbitrary content, including markup, and
	 * sanitizing would corrupt the very strings the operator typed. They are never
	 * interpolated into SQL — SearchReplace binds them with $wpdb->prepare().
	 * REST and MCP callers are unaffected either way; they never arrive slashed.
	 */
	$search    = (string) wp_unslash( $_POST['search'] ?? '' );
	$replace   = (string) wp_unslash( $_POST['replace'] ?? '' );
	$tables    = array_map( 'sanitize_text_field', array_map( 'wp_unslash', (array) ( $_POST['tables'] ?? [] ) ) );
	$dry_run   = ! empty( $_POST['dry_run'] );
	$confirmed = '1' === ( (string) ( $_POST['confirmed'] ?? '0' ) );

	$sr_posted_tables = $tables;

	if ( '' === $search || empty( $tables ) ) {
		$sr_error = __( 'Search string and at least one table are required.', 'ai-command-center' );
	} elseif ( ! $dry_run && ! $confirmed ) {
		$sr_error = __( 'Please confirm the Search & Replace request in the confirmation dialog before it is created, or enable Dry Run.', 'ai-command-center' );
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
				// Dry run: auto-approve and execute immediately to show a live preview.
				$op_manager->approve_request( $req['request_id'] );
				$q_item = $wpdb->get_row( $wpdb->prepare( "SELECT queue_id FROM {$wpdb->prefix}wpcc_operation_queue WHERE request_id = %s", $req['request_id'] ) );
				if ( $q_item ) {
					$sr_result = $op_queue->run_item( $q_item->queue_id, $wpcc_actor_context );
					if ( is_wp_error( $sr_result ) ) {
						$sr_error = $sr_result->get_error_message();
					} else {
						$res = $sr_result['result']['result'] ?? [];
						if ( ! empty( $sr_result['result']['errors'] ) ) {
							$sr_error = $sr_result['result']['errors'][0]['message'] ?? __( 'Dry run failed.', 'ai-command-center' );
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
					__( 'Live Search & Replace request "%s" created and is pending review. Approve and run it from Approvals, or wait for the background worker.', 'ai-command-center' ),
					$req['request_id']
				);
			}
		} else {
			$sr_error = $req->get_error_message();
		}
	}
}

$wp_tables = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}%'" );

// Classify each table (content / meta / options / system / other).
$wpcc_table_groups = [];
foreach ( $wp_tables as $table ) {
	$wpcc_table_groups[ $table ] = $wpcc_classify_table( substr( $table, strlen( $wpdb->prefix ) ) );
}
// Tables a first-time customer should never have to think about, let alone
// tick by accident: the plugin's own governance tables, the user table, and
// anything holding sessions, tokens or secrets.
$wpcc_sensitive_groups = [ 'system', 'users', 'security' ];

// Preserve selection across reloads; default to posts + options.
$wpcc_checked_tables = isset( $_POST['wpcc_sr_action'] )
	? $sr_posted_tables
	: [ $wpdb->prefix . 'posts', $wpdb->prefix . 'options' ];

// Dry Run on by default; preserve the operator's choice on reload.
$wpcc_dry_run_checked = isset( $_POST['wpcc_sr_action'] ) ? ! empty( $_POST['dry_run'] ) : true;

$wpcc_presets = [ 'content' => [], 'content_meta' => [], 'options' => [], 'all' => [] ];
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

$sr_preview_js = $sr_preview ? [
	'search'          => $sr_preview['search'],
	'replace'         => $sr_preview['replace'],
	'tables'          => array_values( $sr_preview['tables'] ),
	'rows_affected'   => $sr_preview['rows_affected'],
	'tables_affected' => array_values( $sr_preview['tables_affected'] ),
] : null;
?>
<style>
	.wpcc-tools-wrap { max-width: 980px; }
	.wpcc-tools-panel { background: #fff; border: 1px solid #ccd0d4; padding: 0; box-shadow: 0 1px 1px rgba(0,0,0,.04); margin-bottom: 20px; }
	.wpcc-tools-panel-header { padding: 15px 20px; border-bottom: 1px solid #ccd0d4; background: #f6f7f7; margin: 0; font-size: 16px; font-weight: 600; }
	.wpcc-tools-panel-body { padding: 20px; }
	.wpcc-sr-form input[type="text"], .wpcc-sr-form select { width: 100%; margin-bottom: 10px; }
	.wpcc-sr-tables { max-height: 180px; overflow-y: scroll; border: 1px solid #ccd0d4; padding: 5px; background: #fff; margin-bottom: 10px; }
	.wpcc-sr-tables label { display: block; padding: 2px 0; }
	.wpcc-sr-preview-table td { padding: 4px 8px 4px 0; vertical-align: top; }
	.wpcc-sr-preview-table td:first-child { font-weight: 600; white-space: nowrap; width: 140px; }
	.wpcc-risk-badge { display: inline-block; padding: 2px 10px; border-radius: 3px; font-size: 11px; font-weight: 700; color: #fff; text-transform: uppercase; letter-spacing: .5px; }
	.wpcc-risk-low { background: #00a32a; }
	.wpcc-risk-medium { background: #dba617; }
	.wpcc-risk-high { background: #d63638; }
	.wpcc-risk-critical { background: #7f1d1d; }
	.wpcc-sr-summary { font-weight: normal; color: #50575e; font-size: 12px; float: right; }
	.wpcc-sr-advanced { border: 1px solid #dcdcde; border-radius: 4px; padding: 10px 12px; margin-bottom: 12px; background: #fbfbfc; }
	.wpcc-sr-advanced > summary { cursor: pointer; font-weight: 600; font-size: 13px; }
	.wpcc-sr-sensitive { margin-top: 12px; border: 1px solid #eec2c2; border-radius: 4px; padding: 10px 12px; background: #fcf0f0; }
	.wpcc-sr-sensitive > summary { cursor: pointer; font-weight: 600; font-size: 13px; color: #8a2424; }
	.wpcc-sr-sensitive__warn { margin: 8px 0; font-size: 12px; line-height: 1.6; color: #8a2424; }
	.wpcc-sr-gate { margin: 0 0 10px; padding: 8px 10px; font-size: 12px; line-height: 1.6; color: #8a6100; background: #fcf9e8; border: 1px solid #f0e2a6; border-radius: 4px; }
	/* The form and its preview sat in a hardcoded two-column grid with no
	   breakpoint, so on a tablet or phone each column was ~220px wide holding
	   text inputs and a table list. Stacks at the WordPress admin breakpoint. */
	.wpcc-sr-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
	@media ( max-width: 782px ) {
		.wpcc-sr-grid { grid-template-columns: 1fr; }
		.wpcc-tools-panel-body { padding: 15px; }
		.wpcc-sr-tables { max-height: 220px; }
	}
	.wpcc-modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,.5); z-index: 100000; align-items: center; justify-content: center; }
	.wpcc-modal-overlay.is-visible { display: flex; }
	.wpcc-modal { background: #fff; padding: 20px 24px; max-width: 520px; width: 90%; border-radius: 4px; box-shadow: 0 4px 20px rgba(0,0,0,.2); }
	.wpcc-modal h3 { margin-top: 0; }
	.wpcc-modal table { width: 100%; margin-bottom: 15px; border-collapse: collapse; }
	.wpcc-modal table th { text-align: left; padding: 4px 10px 4px 0; vertical-align: top; width: 130px; color: #555; }
	.wpcc-modal table td { padding: 4px 0; word-break: break-word; }
	.wpcc-modal-actions { text-align: right; }
	.wpcc-modal-actions .button { margin-left: 8px; }
</style>

<div class="wpcc-tools-wrap">
	<h1><?php esc_html_e( 'Tools', 'ai-command-center' ); ?></h1>
	<p class="description" style="max-width:680px;">
		<?php esc_html_e( 'Governed maintenance tools. Each runs through the same engine as everything else: changes are previewed, approved when your protection mode requires it, and audited. A database search and replace rewrites rows in place and cannot be undone.', 'ai-command-center' ); ?>
	</p>
	<?php
	/*
	 * Search & Replace records NO rollback point, so the strip must not offer one.
	 * The engine has always been honest about this — the run response carries
	 * `rollback_available: false` with "Rows are mutated in place and cannot be
	 * automatically reverted", the change log stores `reversible=0` /
	 * `rollback_kind=none`, and the Changes screen shows no Undo for these rows.
	 * This screen was the one surface claiming otherwise, on the page where the
	 * customer actually runs it and decides whether to take a backup first.
	 */
	$wpcc_trust_reversible = false;
	require WPCC_PLUGIN_DIR . 'includes/Admin/views/partials/trust-strip.php';
	?>

	<?php if ( ! empty( $sr_success_msg ) ) : ?>
		<div class="notice inline notice-success is-dismissible"><p><?php echo esc_html( $sr_success_msg ); ?></p></div>
	<?php endif; ?>
	<?php if ( ! empty( $sr_error ) ) : ?>
		<div class="notice inline notice-error is-dismissible"><p><?php echo esc_html( $sr_error ); ?></p></div>
	<?php endif; ?>

	<div class="wpcc-tools-panel">
		<h2 class="wpcc-tools-panel-header"><?php esc_html_e( 'Safe Search & Replace', 'ai-command-center' ); ?></h2>
		<div class="wpcc-tools-panel-body">
			<p class="description" style="margin-top:0;"><?php esc_html_e( 'Find and replace text across database tables (for example after a domain change). Preview safely with Dry Run; a live run creates a governed request you approve under Approvals.', 'ai-command-center' ); ?></p>
			<div class="wpcc-sr-grid">
				<div class="wpcc-sr-form">
					<form method="post" id="wpcc-sr-form">
						<?php wp_nonce_field( 'wpcc_sr_action' ); ?>
						<input type="hidden" name="confirmed" id="wpcc-sr-confirmed" value="0">
						<p>
							<label for="wpcc-sr-search"><strong><?php esc_html_e( 'Search For:', 'ai-command-center' ); ?></strong></label>
							<input type="text" name="search" id="wpcc-sr-search" placeholder="e.g. old-domain.com" value="<?php echo esc_attr( wp_unslash( (string) ( $_POST['search'] ?? '' ) ) ); ?>" required>
						</p>
						<p>
							<label for="wpcc-sr-replace"><strong><?php esc_html_e( 'Replace With:', 'ai-command-center' ); ?></strong></label>
							<input type="text" name="replace" id="wpcc-sr-replace" placeholder="e.g. new-domain.com" value="<?php echo esc_attr( wp_unslash( (string) ( $_POST['replace'] ?? '' ) ) ); ?>">
						</p>
						<p>
							<label for="wpcc-sr-preset"><strong><?php esc_html_e( 'Table Preset:', 'ai-command-center' ); ?></strong></label>
							<select id="wpcc-sr-preset">
								<option value=""><?php esc_html_e( '— Select a preset —', 'ai-command-center' ); ?></option>
								<option value="content"><?php esc_html_e( 'Content Tables', 'ai-command-center' ); ?></option>
								<option value="content_meta"><?php esc_html_e( 'Content + Meta', 'ai-command-center' ); ?></option>
								<option value="options"><?php esc_html_e( 'Options', 'ai-command-center' ); ?></option>
								<option value="all"><?php esc_html_e( 'All WordPress Content', 'ai-command-center' ); ?></option>
								<option value="custom"><?php esc_html_e( 'Custom Selection', 'ai-command-center' ); ?></option>
							</select>
						</p>
						<?php
						/*
						 * Presets first; the raw table list behind a deliberate reveal.
						 *
						 * The old picker opened on every table in the database, with the
						 * plugin's own tables merely display:none behind a checkbox. So a
						 * first-time customer doing a domain replace was scrolling a list
						 * containing users, usermeta, session stores and WooCommerce
						 * internals, deciding which of them a string replace should touch.
						 * That is not a decision to hand someone who wanted to fix a URL.
						 *
						 * A preset now covers the ordinary job. The full list is one click
						 * away for anyone who needs it, and the tables that can rewrite
						 * credentials or governance state need a second, separate opt-in
						 * with the risk stated in words. Nothing is removed — the same
						 * tables remain reachable, and the governed flow behind them is
						 * unchanged.
						 */
						?>
						<p>
							<span><strong><?php esc_html_e( 'Target Tables:', 'ai-command-center' ); ?></strong></span>
							<span id="wpcc-sr-selected-summary" class="wpcc-sr-summary" role="status"></span>
						</p>

						<details class="wpcc-sr-advanced" id="wpcc-sr-advanced">
							<summary><?php esc_html_e( 'Choose specific tables', 'ai-command-center' ); ?></summary>
							<p class="description" style="margin:8px 0;">
								<?php esc_html_e( 'Most jobs are covered by a preset above. Pick individual tables only if you know why you need them.', 'ai-command-center' ); ?>
							</p>
							<div class="wpcc-sr-tables">
								<?php foreach ( $wp_tables as $table ) :
									$group        = $wpcc_table_groups[ $table ];
									$is_sensitive = in_array( $group, $wpcc_sensitive_groups, true );
									$suffix       = substr( $table, strlen( $wpdb->prefix ) );
									if ( $is_sensitive ) {
										continue; // rendered in its own reveal below
									}
								?>
									<label class="wpcc-sr-table-row">
										<input type="checkbox" name="tables[]" value="<?php echo esc_attr( $table ); ?>" data-group="<?php echo esc_attr( $group ); ?>" data-suffix="<?php echo esc_attr( $suffix ); ?>" <?php checked( in_array( $table, $wpcc_checked_tables, true ) ); ?>>
										<?php echo esc_html( $table ); ?>
									</label>
								<?php endforeach; ?>
							</div>

							<?php
							// The second gate. Separate from the list above precisely so
							// that reaching these tables is a distinct, deliberate act.
							$wpcc_sensitive_tables = array_filter(
								$wp_tables,
								static fn ( $t ) => in_array( $wpcc_table_groups[ $t ], $wpcc_sensitive_groups, true )
							);
							?>
							<?php if ( ! empty( $wpcc_sensitive_tables ) ) : ?>
								<details class="wpcc-sr-sensitive" id="wpcc-sr-sensitive">
									<summary><?php esc_html_e( 'Show sensitive tables (accounts, sessions, security, plugin internals)', 'ai-command-center' ); ?></summary>
									<p class="wpcc-sr-sensitive__warn" role="note">
										<?php esc_html_e( 'These tables hold account records, sign-in sessions, access tokens and WP Command Center’s own approval and audit history. A text replace here can lock people out of the site or damage the record of what happened on it. Selecting any of them makes this a critical-risk run.', 'ai-command-center' ); ?>
									</p>
									<div class="wpcc-sr-tables">
										<?php foreach ( $wpcc_sensitive_tables as $table ) :
											$suffix = substr( $table, strlen( $wpdb->prefix ) );
										?>
											<label class="wpcc-sr-table-row">
												<input type="checkbox" name="tables[]" value="<?php echo esc_attr( $table ); ?>" data-group="<?php echo esc_attr( $wpcc_table_groups[ $table ] ); ?>" data-suffix="<?php echo esc_attr( $suffix ); ?>" <?php checked( in_array( $table, $wpcc_checked_tables, true ) ); ?>>
												<?php echo esc_html( $table ); ?>
											</label>
										<?php endforeach; ?>
									</div>
								</details>
							<?php endif; ?>
						</details>
						<p>
							<label><input type="checkbox" name="dry_run" id="wpcc-sr-dry-run" value="1" <?php checked( $wpcc_dry_run_checked ); ?>> <?php esc_html_e( 'Dry Run (Preview changes only)', 'ai-command-center' ); ?></label>
						</p>
						<p>
							<?php esc_html_e( 'Computed Risk Level:', 'ai-command-center' ); ?>
							<span id="wpcc-sr-risk-badge" class="wpcc-risk-badge wpcc-risk-low">LOW</span>
						</p>
						<p class="wpcc-sr-gate" id="wpcc-sr-gate-note" role="note" hidden>
							<?php esc_html_e( 'Run a Dry Preview first. At this risk level the preview is what tells you how many rows a live run would rewrite — tick Dry Run, run it, then come back.', 'ai-command-center' ); ?>
						</p>
						<p>
							<button type="submit" name="wpcc_sr_action" value="run" id="wpcc-sr-submit-btn" class="button button-primary"><?php esc_html_e( 'Run Dry Preview', 'ai-command-center' ); ?></button>
						</p>
						<noscript>
							<p style="color: #d63638;"><?php esc_html_e( 'JavaScript is required to create a live Search & Replace request (a confirmation dialog is shown first). Dry Run previews work without JavaScript.', 'ai-command-center' ); ?></p>
						</noscript>
					</form>
				</div>
				<div>
					<?php if ( $sr_preview ) : ?>
						<div class="wpcc-tools-panel" style="margin-top: 10px; border-color: #2271b1;">
							<h3 class="wpcc-tools-panel-header" style="font-size: 14px; padding: 10px 15px;"><?php esc_html_e( 'Dry Run Preview', 'ai-command-center' ); ?></h3>
							<div class="wpcc-tools-panel-body" style="padding: 15px;">
								<table class="wpcc-sr-preview-table">
									<tr><td><?php esc_html_e( 'Matches Found:', 'ai-command-center' ); ?></td><td><?php echo esc_html( (string) $sr_preview['matches_found'] ); ?></td></tr>
									<tr><td><?php esc_html_e( 'Affected Rows:', 'ai-command-center' ); ?></td><td><?php echo esc_html( (string) $sr_preview['rows_affected'] ); ?></td></tr>
									<tr><td><?php esc_html_e( 'Affected Tables:', 'ai-command-center' ); ?></td><td>
										<?php echo $sr_preview['tables_affected'] ? esc_html( implode( ', ', $sr_preview['tables_affected'] ) ) : esc_html__( 'None — no matches in the selected tables.', 'ai-command-center' ); ?>
									</td></tr>
									<tr><td><?php esc_html_e( 'Risk Level:', 'ai-command-center' ); ?></td><td><span class="wpcc-risk-badge wpcc-risk-<?php echo esc_attr( $sr_preview['risk_level'] ); ?>"><?php echo esc_html( strtoupper( $sr_preview['risk_level'] ) ); ?></span></td></tr>
								</table>
								<p><small><em><?php echo esc_html( $sr_preview['warning'] ); ?></em></small></p>
							</div>
						</div>
					<?php else : ?>
						<div style="background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 8px; padding: 20px; text-align: center; color: #646970;">
							<?php esc_html_e( 'Enter search parameters, choose tables, and click "Run Dry Preview" to see matches found, affected rows, affected tables, and the computed risk level.', 'ai-command-center' ); ?>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>

	<!-- Confirmation dialog for live (non-dry-run) requests. -->
	<div class="wpcc-modal-overlay" id="wpcc-sr-confirm-overlay">
		<div class="wpcc-modal">
			<h3><?php esc_html_e( 'Confirm Search & Replace Request', 'ai-command-center' ); ?></h3>
			<table>
				<tr><th><?php esc_html_e( 'Search For', 'ai-command-center' ); ?></th><td id="wpcc-confirm-search"></td></tr>
				<tr><th><?php esc_html_e( 'Replace With', 'ai-command-center' ); ?></th><td id="wpcc-confirm-replace"></td></tr>
				<tr><th><?php esc_html_e( 'Affected Tables', 'ai-command-center' ); ?></th><td id="wpcc-confirm-tables"></td></tr>
				<tr><th><?php esc_html_e( 'Affected Rows', 'ai-command-center' ); ?></th><td id="wpcc-confirm-rows"></td></tr>
				<tr><th><?php esc_html_e( 'Risk Level', 'ai-command-center' ); ?></th><td><span id="wpcc-confirm-risk" class="wpcc-risk-badge"></span></td></tr>
			</table>
			<p><?php esc_html_e( 'This creates a pending operation request only — no data changes until it is approved and executed from Approvals, or by the background worker.', 'ai-command-center' ); ?></p>
			<div class="wpcc-modal-actions">
				<button type="button" class="button" id="wpcc-sr-confirm-cancel"><?php esc_html_e( 'Cancel', 'ai-command-center' ); ?></button>
				<button type="submit" class="button button-primary" name="wpcc_sr_action" value="run" form="wpcc-sr-form" id="wpcc-sr-confirm-submit"><?php esc_html_e( 'Confirm & Create Request', 'ai-command-center' ); ?></button>
			</div>
		</div>
	</div>

	<script>
	( function () {
		var PRESETS      = <?php echo wp_json_encode( $wpcc_presets ); ?>;
		var LAST_PREVIEW = <?php echo wp_json_encode( $sr_preview_js ); ?>;
		var LABEL_DRY_RUN   = <?php echo wp_json_encode( __( 'Run Dry Preview', 'ai-command-center' ) ); ?>;
		var LABEL_LIVE_RUN  = <?php echo wp_json_encode( __( 'Create Replace Request', 'ai-command-center' ) ); ?>;
		var LABEL_NONE      = <?php echo wp_json_encode( __( 'None selected', 'ai-command-center' ) ); ?>;
		/* translators: %1$d: number of database tables selected. */
		var LABEL_SELECTED  = <?php echo wp_json_encode( /* translators: %1$d: number */ __( '%1$d table(s) selected', 'ai-command-center' ) ); ?>;
		var LABEL_NONE_SELECTED = <?php echo wp_json_encode( __( 'No tables selected yet — pick a preset above.', 'ai-command-center' ) ); ?>;
		var LABEL_FROM_PREVIEW = <?php echo wp_json_encode( ' ' . __( '(from last Dry Preview)', 'ai-command-center' ) ); ?>;
		var LABEL_UNKNOWN_ROWS = <?php echo wp_json_encode( __( 'Unknown — run "Run Dry Preview" first for an exact count.', 'ai-command-center' ) ); ?>;

		var form         = document.getElementById( 'wpcc-sr-form' );
		var dryRunCb     = document.getElementById( 'wpcc-sr-dry-run' );
		var submitBtn    = document.getElementById( 'wpcc-sr-submit-btn' );
		var presetSelect = document.getElementById( 'wpcc-sr-preset' );
		var summaryEl    = document.getElementById( 'wpcc-sr-selected-summary' );
		var advancedEl   = document.getElementById( 'wpcc-sr-advanced' );
		var gateNote     = document.getElementById( 'wpcc-sr-gate-note' );
		var tableBoxes   = Array.prototype.slice.call( form.querySelectorAll( 'input[name="tables[]"]' ) );
		var riskBadge    = document.getElementById( 'wpcc-sr-risk-badge' );
		var searchInput  = document.getElementById( 'wpcc-sr-search' );
		var replaceInput = document.getElementById( 'wpcc-sr-replace' );
		var confirmedFld = document.getElementById( 'wpcc-sr-confirmed' );
		var overlay      = document.getElementById( 'wpcc-sr-confirm-overlay' );

		// Mirrors $wpcc_compute_risk on the server, tier for tier. The badge a
		// customer reads before clicking must be the one the request is scored
		// with — a preview that under-states the risk is worse than no preview.
		function computeRisk() {
			var checked = tableBoxes.filter( function ( cb ) { return cb.checked; } );
			if ( ! checked.length ) { return 'low'; }
			var groups = checked.map( function ( cb ) { return cb.getAttribute( 'data-group' ); } );
			if ( groups.indexOf( 'users' ) !== -1 || groups.indexOf( 'security' ) !== -1 || groups.indexOf( 'system' ) !== -1 ) {
				return 'critical';
			}
			if ( checked.length >= 8 ) { return 'critical'; }
			if ( groups.indexOf( 'options' ) !== -1 || groups.indexOf( 'other' ) !== -1 ) { return 'high'; }
			return 'medium';
		}
		// A live run at high or critical risk must be previewed first. The dry run
		// is the only thing that turns "this might match a lot" into a number, and
		// it is free — so the guard costs a click and buys the customer the fact
		// they most need before rewriting rows they cannot see.
		function needsPreviewFirst( risk ) { return 'high' === risk || 'critical' === risk; }
		function previewMatchesSelection( tables ) {
			if ( ! LAST_PREVIEW ) { return false; }
			var sorted = tables.slice().sort();
			return LAST_PREVIEW.search === searchInput.value &&
				LAST_PREVIEW.replace === replaceInput.value &&
				JSON.stringify( LAST_PREVIEW.tables.slice().sort() ) === JSON.stringify( sorted );
		}
		function paintRisk( el, risk ) { el.textContent = risk.toUpperCase(); el.className = 'wpcc-risk-badge wpcc-risk-' + risk; }
		function refreshRisk() { paintRisk( riskBadge, computeRisk() ); refreshSummary(); refreshMode(); }
		tableBoxes.forEach( function ( cb ) { cb.addEventListener( 'change', refreshRisk ); } );

		presetSelect.addEventListener( 'change', function () {
			var preset = this.value;
			if ( ! preset || 'custom' === preset ) { return; }
			var list = PRESETS[ preset ] || [];
			tableBoxes.forEach( function ( cb ) { cb.checked = list.indexOf( cb.value ) !== -1; } );
			refreshRisk();
		} );
		// A custom selection is what the reveal is for — open it rather than
		// leaving the customer looking at an unchanged screen.
		presetSelect.addEventListener( 'change', function () {
			if ( 'custom' === this.value && advancedEl ) { advancedEl.open = true; }
		} );

		// A running summary of what is selected, so the customer never has to open
		// the reveal to find out what they are about to run against.
		function refreshSummary() {
			if ( ! summaryEl ) { return; }
			var checked = tableBoxes.filter( function ( cb ) { return cb.checked; } );
			summaryEl.textContent = checked.length
				? LABEL_SELECTED.replace( '%1$d', checked.length )
				: LABEL_NONE_SELECTED;
		}

		function refreshMode() {
			if ( dryRunCb.checked ) {
				submitBtn.textContent = LABEL_DRY_RUN;
				submitBtn.type = 'submit';
				submitBtn.disabled = false;
				if ( gateNote ) { gateNote.hidden = true; }
				return;
			}
			submitBtn.textContent = LABEL_LIVE_RUN;
			submitBtn.type = 'button';

			var tables = tableBoxes.filter( function ( cb ) { return cb.checked; } ).map( function ( cb ) { return cb.value; } );
			var blocked = needsPreviewFirst( computeRisk() ) && ! previewMatchesSelection( tables );
			submitBtn.disabled = blocked;
			if ( gateNote ) { gateNote.hidden = ! blocked; }
		}
		dryRunCb.addEventListener( 'change', refreshMode );
		searchInput.addEventListener( 'input', refreshMode );
		replaceInput.addEventListener( 'input', refreshMode );
		refreshRisk();

		submitBtn.addEventListener( 'click', function ( e ) {
			if ( dryRunCb.checked ) { return; }
			e.preventDefault();
			var tables = tableBoxes.filter( function ( cb ) { return cb.checked; } ).map( function ( cb ) { return cb.value; } );
			var risk   = computeRisk();
			document.getElementById( 'wpcc-confirm-search' ).textContent  = searchInput.value;
			document.getElementById( 'wpcc-confirm-replace' ).textContent = replaceInput.value;
			document.getElementById( 'wpcc-confirm-tables' ).textContent  = tables.length ? tables.join( ', ' ) : LABEL_NONE;
			paintRisk( document.getElementById( 'wpcc-confirm-risk' ), risk );
			var rowsEl       = document.getElementById( 'wpcc-confirm-rows' );
			var sortedTables = tables.slice().sort();
			if ( LAST_PREVIEW && LAST_PREVIEW.search === searchInput.value && LAST_PREVIEW.replace === replaceInput.value &&
				JSON.stringify( LAST_PREVIEW.tables.slice().sort() ) === JSON.stringify( sortedTables ) ) {
				rowsEl.textContent = LAST_PREVIEW.rows_affected + LABEL_FROM_PREVIEW;
			} else {
				rowsEl.textContent = LABEL_UNKNOWN_ROWS;
			}
			overlay.classList.add( 'is-visible' );
		} );
		document.getElementById( 'wpcc-sr-confirm-cancel' ).addEventListener( 'click', function () { overlay.classList.remove( 'is-visible' ); } );
		document.getElementById( 'wpcc-sr-confirm-submit' ).addEventListener( 'click', function () { confirmedFld.value = '1'; } );
	} )();
	</script>
</div>
