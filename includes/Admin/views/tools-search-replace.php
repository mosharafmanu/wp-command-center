<?php
/**
 * Settings › Tools — Safe Search & Replace (governed maintenance tool).
 *
 * Phase 2A of the Runtime migration: the Search & Replace tool is re-homed here from
 * the legacy Runtime dashboard, UNCHANGED in behavior. It creates a governed
 * `safe_search_replace` operation request (OperationManager) that must be approved and
 * run from Activity › Approvals; Dry Run auto-approves and runs a single preview only.
 * The dry-run risk model, the confirmation dialog for live requests, and the full
 * approval/audit path are preserved exactly — no engine, REST, capability, or schema
 * change. (Runtime keeps its own copy until Phase 2B; this is the intentional,
 * temporary duplication called out in the migration blueprint.)
 */

defined( 'ABSPATH' ) || exit;

global $wpdb;

$op_manager = new \WPCommandCenter\Operations\OperationManager();
$op_queue   = new \WPCommandCenter\Operations\OperationQueue();

// AuditLog::resolve_actor() requires an array; OperationQueue::run_item() forwards
// context['actor'] straight to it, so build it as an array here.
$wpcc_actor_context = [
	'actor' => [
		'type'       => 'admin',
		'user_id'    => get_current_user_id(),
		'user_login' => wp_get_current_user()->user_login,
	],
];

/**
 * UI-level risk assessment (low|medium|high) for the chosen tables — independent of
 * the operation registry's static risk_level. posts/postmeta only -> low; wp_options
 * -> medium; any wpcc_* (plugin-internal) table -> high; anything else -> medium.
 */
$wpcc_compute_risk = function ( array $tables ) use ( $wpdb ): string {
	if ( empty( $tables ) ) {
		return 'low';
	}
	$has_system = false;
	$has_options = false;
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

// Handle Search & Replace UI (same governed flow as the legacy Runtime view).
$sr_result        = null;
$sr_preview       = null;
$sr_posted_tables = [];
$sr_error         = '';
$sr_success_msg   = '';

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
					__( 'Live Search & Replace request "%s" created and is pending review. Approve and run it from Activity › Approvals, or wait for the background worker.', 'wp-command-center' ),
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
	<h1><?php esc_html_e( 'Tools', 'wp-command-center' ); ?></h1>
	<p class="description" style="max-width:680px;">
		<?php esc_html_e( 'Governed maintenance tools. Each runs through the same engine as everything else: changes are previewed, approved, audited, and reversible where supported.', 'wp-command-center' ); ?>
	</p>

	<?php if ( ! empty( $sr_success_msg ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $sr_success_msg ); ?></p></div>
	<?php endif; ?>
	<?php if ( ! empty( $sr_error ) ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $sr_error ); ?></p></div>
	<?php endif; ?>

	<div class="wpcc-tools-panel">
		<h2 class="wpcc-tools-panel-header"><?php esc_html_e( 'Safe Search & Replace', 'wp-command-center' ); ?></h2>
		<div class="wpcc-tools-panel-body">
			<p class="description" style="margin-top:0;"><?php esc_html_e( 'Find and replace text across database tables (for example after a domain change). Preview safely with Dry Run; a live run creates a governed request you approve in Activity › Approvals.', 'wp-command-center' ); ?></p>
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
						<div class="wpcc-tools-panel" style="margin-top: 10px; border-color: #2271b1;">
							<h3 class="wpcc-tools-panel-header" style="font-size: 14px; padding: 10px 15px;"><?php esc_html_e( 'Dry Run Preview', 'wp-command-center' ); ?></h3>
							<div class="wpcc-tools-panel-body" style="padding: 15px;">
								<table class="wpcc-sr-preview-table">
									<tr><td><?php esc_html_e( 'Matches Found:', 'wp-command-center' ); ?></td><td><?php echo esc_html( (string) $sr_preview['matches_found'] ); ?></td></tr>
									<tr><td><?php esc_html_e( 'Affected Rows:', 'wp-command-center' ); ?></td><td><?php echo esc_html( (string) $sr_preview['rows_affected'] ); ?></td></tr>
									<tr><td><?php esc_html_e( 'Affected Tables:', 'wp-command-center' ); ?></td><td>
										<?php echo $sr_preview['tables_affected'] ? esc_html( implode( ', ', $sr_preview['tables_affected'] ) ) : esc_html__( 'None — no matches in the selected tables.', 'wp-command-center' ); ?>
									</td></tr>
									<tr><td><?php esc_html_e( 'Risk Level:', 'wp-command-center' ); ?></td><td><span class="wpcc-risk-badge wpcc-risk-<?php echo esc_attr( $sr_preview['risk_level'] ); ?>"><?php echo esc_html( strtoupper( $sr_preview['risk_level'] ) ); ?></span></td></tr>
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

	<!-- Confirmation dialog for live (non-dry-run) requests. -->
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
			<p><?php esc_html_e( 'This creates a pending operation request only — no data changes until it is approved and executed from Activity › Approvals, or by the background worker.', 'wp-command-center' ); ?></p>
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
			if ( ! checked.length ) { return 'low'; }
			var hasSystem = false, hasOptions = false, onlyPostsPostmeta = true;
			checked.forEach( function ( cb ) {
				var group  = cb.getAttribute( 'data-group' );
				var suffix = cb.getAttribute( 'data-suffix' );
				if ( 'system' === group ) { hasSystem = true; }
				if ( 'options' === group ) { hasOptions = true; }
				if ( 'posts' !== suffix && 'postmeta' !== suffix ) { onlyPostsPostmeta = false; }
			} );
			if ( hasSystem ) { return 'high'; }
			if ( hasOptions ) { return 'medium'; }
			if ( onlyPostsPostmeta ) { return 'low'; }
			return 'medium';
		}
		function paintRisk( el, risk ) { el.textContent = risk.toUpperCase(); el.className = 'wpcc-risk-badge wpcc-risk-' + risk; }
		function refreshRisk() { paintRisk( riskBadge, computeRisk() ); }
		tableBoxes.forEach( function ( cb ) { cb.addEventListener( 'change', refreshRisk ); } );
		refreshRisk();

		presetSelect.addEventListener( 'change', function () {
			var preset = this.value;
			if ( ! preset || 'custom' === preset ) { return; }
			var list = PRESETS[ preset ] || [];
			tableBoxes.forEach( function ( cb ) { cb.checked = list.indexOf( cb.value ) !== -1; } );
			refreshRisk();
		} );

		showSystemCb.addEventListener( 'change', function () {
			var rows = form.querySelectorAll( '.wpcc-sr-system-row' );
			rows.forEach( function ( row ) { row.style.display = showSystemCb.checked ? '' : 'none'; } );
		} );

		function refreshMode() {
			if ( dryRunCb.checked ) { submitBtn.textContent = LABEL_DRY_RUN; submitBtn.type = 'submit'; }
			else { submitBtn.textContent = LABEL_LIVE_RUN; submitBtn.type = 'button'; }
		}
		dryRunCb.addEventListener( 'change', refreshMode );
		refreshMode();

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
