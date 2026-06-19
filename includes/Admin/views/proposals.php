<?php
/**
 * STEP 110 (Task 6) — Governed Drafts (Proposal Store) DEV validation surface.
 *
 * A THIN REST CLIENT over wp-command-center/v1/admin/proposals (Task 5). It makes
 * NO direct ProposalStore / ProposalApplyService / OperationExecutor calls — every
 * read and write goes through the REST layer (cookie + X-WP-Nonce). It is a
 * developer instrument to validate the Proposal Store primitive, NOT the AI Alt
 * Text product UI.
 *
 * Boundary (no second Approval Center): there are NO approve / reject / rollback
 * controls here. Gated proposals cross-link to the Approval Center (where the
 * request is approved); applied proposals cross-link to Change History (where the
 * change is viewed / undone). This surface only proposes, edits, applies, and
 * dismisses — it never approves or reverses.
 */

defined( 'ABSPATH' ) || exit;

$nonce        = wp_create_nonce( 'wp_rest' );
$api_base     = rest_url( 'wp-command-center/v1/admin' );
$approval_url = admin_url( 'admin.php?page=wpcc-approval-center' );
$history_url  = admin_url( 'admin.php?page=wpcc-change-history' );
?>
<div class="wrap wpcc-wrap">
	<h1><?php esc_html_e( 'Governed Drafts (Dev)', 'wp-command-center' ); ?></h1>

	<div class="notice notice-warning" style="margin-top:12px;">
		<p>
			<strong><?php esc_html_e( 'Developer validation surface.', 'wp-command-center' ); ?></strong>
			<?php esc_html_e( 'This is not the AI Alt Text product UI. Applying a draft here runs a real governed action through the engine — it is audited and reversible. Approvals happen in the Approval Center; rollback happens in Change History.', 'wp-command-center' ); ?>
		</p>
	</div>

	<p class="description">
		<?php esc_html_e( 'Stage a draft (Propose), review it, then Apply it through the governed engine. Pending-approval and applied drafts link out to the surfaces that own those stages.', 'wp-command-center' ); ?>
	</p>

	<!-- Create test proposal (DEV TOOL ONLY) -->
	<h2><?php esc_html_e( 'Create test proposal', 'wp-command-center' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Test tool: creates a real proposal that, when applied, performs a real governed operation.', 'wp-command-center' ); ?></p>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="wpcc-p-op"><?php esc_html_e( 'Operation ID', 'wp-command-center' ); ?></label></th>
			<td><input type="text" id="wpcc-p-op" class="regular-text" value="media_manage"></td>
		</tr>
		<tr>
			<th scope="row"><label for="wpcc-p-action"><?php esc_html_e( 'Action', 'wp-command-center' ); ?></label></th>
			<td><input type="text" id="wpcc-p-action" class="regular-text" value="media_update"></td>
		</tr>
		<tr>
			<th scope="row"><label for="wpcc-p-ttype"><?php esc_html_e( 'Target type', 'wp-command-center' ); ?></label></th>
			<td><input type="text" id="wpcc-p-ttype" class="regular-text" value="attachment"></td>
		</tr>
		<tr>
			<th scope="row"><label for="wpcc-p-tid"><?php esc_html_e( 'Target ID', 'wp-command-center' ); ?></label></th>
			<td><input type="text" id="wpcc-p-tid" class="regular-text" placeholder="123"></td>
		</tr>
		<tr>
			<th scope="row"><label for="wpcc-p-payload"><?php esc_html_e( 'Payload (JSON)', 'wp-command-center' ); ?></label></th>
			<td><textarea id="wpcc-p-payload" class="large-text code" rows="3">{"action":"media_update","media_id":123,"alt":"a described image"}</textarea></td>
		</tr>
	</table>
	<p><button type="button" class="button button-secondary" id="wpcc-p-create"><?php esc_html_e( 'Create draft', 'wp-command-center' ); ?></button></p>

	<hr>

	<h2><?php esc_html_e( 'Governed drafts', 'wp-command-center' ); ?></h2>
	<div id="wpcc-p-status" role="status" aria-live="polite" style="min-height:1.5em;"></div>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Status', 'wp-command-center' ); ?></th>
				<th><?php esc_html_e( 'Target', 'wp-command-center' ); ?></th>
				<th><?php esc_html_e( 'Operation', 'wp-command-center' ); ?></th>
				<th><?php esc_html_e( 'Change', 'wp-command-center' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'wp-command-center' ); ?></th>
			</tr>
		</thead>
		<tbody id="wpcc-p-rows">
			<tr><td colspan="5"><?php esc_html_e( 'Loading…', 'wp-command-center' ); ?></td></tr>
		</tbody>
	</table>

	<!-- Detail panel -->
	<div id="wpcc-p-detail" style="display:none;margin-top:20px;padding:16px;border:1px solid #c3c4c7;background:#fff;">
		<h2><?php esc_html_e( 'Proposal detail', 'wp-command-center' ); ?></h2>
		<div id="wpcc-p-detail-body"></div>
	</div>
</div>

<script>
( function () {
	const API   = <?php echo wp_json_encode( $api_base ); ?>;
	const NONCE = <?php echo wp_json_encode( $nonce ); ?>;
	const APPROVAL_URL = <?php echo wp_json_encode( $approval_url ); ?>;
	const HISTORY_URL  = <?php echo wp_json_encode( $history_url ); ?>;
	const EMPTY = <?php echo wp_json_encode( esc_html__( 'No governed drafts yet. This is a developer surface for validating the Proposal Store. Use “Create test proposal” to stage one.', 'wp-command-center' ) ); ?>;

	const $ = ( id ) => document.getElementById( id );
	const esc = ( s ) => String( s == null ? '' : s ).replace( /[&<>"']/g, ( c ) => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ] ) );
	const setStatus = ( msg, ok ) => { const n = $( 'wpcc-p-status' ); n.textContent = msg || ''; n.style.color = ok === false ? '#d63638' : '#1d2327'; };

	function api( method, path, body ) {
		const opts = { method, headers: { 'X-WP-Nonce': NONCE } };
		if ( body !== undefined ) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify( body ); }
		return fetch( API + path, opts ).then( ( r ) => r.json().then( ( d ) => ( { status: r.status, data: d } ) ) );
	}

	function actionsFor( p ) {
		// Propose-stage controls only. NEVER approve/reject (Approval Center) or
		// rollback (Change History).
		const btns = [ '<button type="button" class="button button-small wpcc-p-view" data-id="' + esc( p.proposal_id ) + '"><?php echo esc_js( __( 'View', 'wp-command-center' ) ); ?></button>' ];
		if ( p.status === 'draft' ) {
			btns.push( '<button type="button" class="button button-small button-primary wpcc-p-apply" data-id="' + esc( p.proposal_id ) + '"><?php echo esc_js( __( 'Apply', 'wp-command-center' ) ); ?></button>' );
			btns.push( '<button type="button" class="button button-small wpcc-p-dismiss" data-id="' + esc( p.proposal_id ) + '"><?php echo esc_js( __( 'Dismiss', 'wp-command-center' ) ); ?></button>' );
		}
		return btns.join( ' ' );
	}

	function changeCell( p ) {
		if ( p.status !== 'applied' || ! p.change_id ) { return '—'; }
		const label = p.change_status === 'rolled_back' ? '<?php echo esc_js( __( 'Rolled back', 'wp-command-center' ) ); ?>' : '<?php echo esc_js( __( 'Applied', 'wp-command-center' ) ); ?>';
		return esc( label ) + ' · <a href="' + esc( HISTORY_URL ) + '"><?php echo esc_js( __( 'Change History →', 'wp-command-center' ) ); ?></a>';
	}

	function load() {
		api( 'GET', '/proposals?limit=50' ).then( ( res ) => {
			const rows = $( 'wpcc-p-rows' );
			const list = ( res.data && res.data.proposals ) || [];
			if ( ! list.length ) { rows.innerHTML = '<tr><td colspan="5">' + esc( EMPTY ) + '</td></tr>'; return; }
			rows.innerHTML = list.map( ( p ) =>
				'<tr><td>' + esc( p.status ) + '</td>' +
				'<td>' + esc( ( p.target_type || '' ) + ( p.target_id ? ( ':' + p.target_id ) : '' ) ) + '</td>' +
				'<td>' + esc( ( p.operation_id || '' ) + ( p.action ? ( ' / ' + p.action ) : '' ) ) + '</td>' +
				'<td>' + changeCell( p ) + '</td>' +
				'<td>' + actionsFor( p ) + '</td></tr>'
			).join( '' );
		} );
	}

	function detail( id ) {
		api( 'GET', '/proposals/' + encodeURIComponent( id ) ).then( ( res ) => {
			const p = res.data || {};
			const panel = $( 'wpcc-p-detail' ), body = $( 'wpcc-p-detail-body' );
			const rowsHtml = [
				[ '<?php echo esc_js( __( 'Proposal ID', 'wp-command-center' ) ); ?>', esc( p.proposal_id ) ],
				[ '<?php echo esc_js( __( 'Status', 'wp-command-center' ) ); ?>', esc( p.status ) ],
				[ '<?php echo esc_js( __( 'Operation', 'wp-command-center' ) ); ?>', esc( ( p.operation_id || '' ) + ' / ' + ( p.action || '' ) ) ],
				[ '<?php echo esc_js( __( 'Target', 'wp-command-center' ) ); ?>', esc( ( p.target_type || '' ) + ':' + ( p.target_id || '' ) ) ],
				[ '<?php echo esc_js( __( 'Request ID', 'wp-command-center' ) ); ?>', p.request_id ? ( esc( p.request_id ) + ' · <a href="' + esc( APPROVAL_URL ) + '"><?php echo esc_js( __( 'Review in Approval Center →', 'wp-command-center' ) ); ?></a>' ) : '—' ],
				[ '<?php echo esc_js( __( 'Change ID', 'wp-command-center' ) ); ?>', p.change_id ? ( esc( p.change_id ) + ' · <a href="' + esc( HISTORY_URL ) + '"><?php echo esc_js( __( 'View in Change History →', 'wp-command-center' ) ); ?></a>' ) : '—' ],
				[ '<?php echo esc_js( __( 'Change status', 'wp-command-center' ) ); ?>', p.change_status ? esc( p.change_status ) : '—' ],
				[ '<?php echo esc_js( __( 'Payload', 'wp-command-center' ) ); ?>', '<code>' + esc( JSON.stringify( p.payload ) ) + '</code>' ],
				[ '<?php echo esc_js( __( 'Final payload', 'wp-command-center' ) ); ?>', '<code>' + esc( JSON.stringify( p.final_payload ) ) + '</code>' ],
				[ '<?php echo esc_js( __( 'Error', 'wp-command-center' ) ); ?>', p.error ? ( '<code>' + esc( JSON.stringify( p.error ) ) + '</code>' ) : '—' ]
			];
			let html = '<table class="widefat striped">' + rowsHtml.map( ( r ) => '<tr><th style="width:180px;">' + r[0] + '</th><td>' + r[1] + '</td></tr>' ).join( '' ) + '</table>';
			if ( p.status === 'draft' ) {
				html += '<p><label><?php echo esc_js( __( 'Edit final payload (JSON):', 'wp-command-center' ) ); ?></label><br>' +
					'<textarea id="wpcc-p-edit" class="large-text code" rows="3">' + esc( JSON.stringify( p.final_payload || p.payload ) ) + '</textarea></p>' +
					'<p><button type="button" class="button wpcc-p-save" data-id="' + esc( p.proposal_id ) + '"><?php echo esc_js( __( 'Save final payload', 'wp-command-center' ) ); ?></button></p>';
			}
			body.innerHTML = html;
			panel.style.display = 'block';
		} );
	}

	document.addEventListener( 'click', function ( e ) {
		const t = e.target;
		if ( t.classList.contains( 'wpcc-p-view' ) ) { detail( t.dataset.id ); }
		else if ( t.classList.contains( 'wpcc-p-apply' ) ) {
			setStatus( '<?php echo esc_js( __( 'Applying…', 'wp-command-center' ) ); ?>' );
			api( 'POST', '/proposals/' + encodeURIComponent( t.dataset.id ) + '/apply' ).then( ( res ) => {
				const ok = res.status >= 200 && res.status < 300;
				setStatus( ok ? ( '<?php echo esc_js( __( 'Result: ', 'wp-command-center' ) ); ?>' + ( res.data.status || '' ) ) : ( res.data.message || '<?php echo esc_js( __( 'Apply failed.', 'wp-command-center' ) ); ?>' ), ok );
				load();
			} );
		}
		else if ( t.classList.contains( 'wpcc-p-dismiss' ) ) {
			api( 'POST', '/proposals/' + encodeURIComponent( t.dataset.id ) + '/dismiss' ).then( () => { setStatus( '<?php echo esc_js( __( 'Dismissed.', 'wp-command-center' ) ); ?>' ); load(); } );
		}
		else if ( t.classList.contains( 'wpcc-p-save' ) ) {
			let payload;
			try { payload = JSON.parse( $( 'wpcc-p-edit' ).value ); } catch ( err ) { setStatus( '<?php echo esc_js( __( 'Invalid JSON.', 'wp-command-center' ) ); ?>', false ); return; }
			api( 'PUT', '/proposals/' + encodeURIComponent( t.dataset.id ), { final_payload: payload } ).then( ( res ) => {
				const ok = res.status >= 200 && res.status < 300;
				setStatus( ok ? '<?php echo esc_js( __( 'Saved.', 'wp-command-center' ) ); ?>' : ( res.data.message || '<?php echo esc_js( __( 'Save failed.', 'wp-command-center' ) ); ?>' ), ok );
				if ( ok ) { detail( t.dataset.id ); }
			} );
		}
	} );

	$( 'wpcc-p-create' ).addEventListener( 'click', function () {
		let payload;
		try { payload = JSON.parse( $( 'wpcc-p-payload' ).value ); } catch ( err ) { setStatus( '<?php echo esc_js( __( 'Invalid payload JSON.', 'wp-command-center' ) ); ?>', false ); return; }
		api( 'POST', '/proposals', {
			operation_id: $( 'wpcc-p-op' ).value,
			action: $( 'wpcc-p-action' ).value,
			target_type: $( 'wpcc-p-ttype' ).value,
			target_id: $( 'wpcc-p-tid' ).value,
			payload: payload
		} ).then( ( res ) => {
			const ok = res.status >= 200 && res.status < 300;
			setStatus( ok ? '<?php echo esc_js( __( 'Draft created.', 'wp-command-center' ) ); ?>' : ( res.data.message || '<?php echo esc_js( __( 'Create failed.', 'wp-command-center' ) ); ?>' ), ok );
			load();
		} );
	} );

	load();
} )();
</script>
