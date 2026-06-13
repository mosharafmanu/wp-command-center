<?php
defined( 'ABSPATH' ) || exit;

$security_mode = \WPCommandCenter\Operations\SecurityModeManager::label();
$nonce         = wp_create_nonce( 'wp_rest' );
$api_base      = rest_url( 'wp-command-center/v1/admin' );
?>
<div class="wrap wpcc-wrap">
	<h1><?php esc_html_e( 'Pending Approvals', 'wp-command-center' ); ?>
		<span id="wpcc-pending-badge" style="display:none;margin-left:8px;background:#d63638;color:#fff;font-size:12px;border-radius:10px;padding:2px 8px;vertical-align:middle;"></span>
	</h1>

	<p class="description">
		<?php printf(
			/* translators: %s: security mode label */
			esc_html__( 'Security mode: %s. Approve or reject AI-requested operations below. Approving executes the operation immediately and logs the result.', 'wp-command-center' ),
			'<strong>' . esc_html( $security_mode ) . '</strong>'
		); ?>
	</p>

	<div id="wpcc-approvals-list">
		<p><span class="spinner is-active" style="float:none;margin:0 6px 0 0;vertical-align:middle;"></span><?php esc_html_e( 'Loading…', 'wp-command-center' ); ?></p>
	</div>
</div>

<style>
.wpcc-approval-card {
	background: #fff;
	border: 1px solid #ddd;
	border-left: 4px solid #8c8f94;
	border-radius: 4px;
	padding: 16px 20px;
	margin: 12px 0;
	max-width: 820px;
	box-shadow: 0 1px 3px rgba(0,0,0,.06);
}
.wpcc-approval-card.risk-critical { border-left-color: #d63638; }
.wpcc-approval-card.risk-high     { border-left-color: #dba617; }
.wpcc-approval-card.risk-medium   { border-left-color: #72aee6; }
.wpcc-approval-card.risk-low      { border-left-color: #00a32a; }
.wpcc-approval-card.risk-diagnostic { border-left-color: #8c8f94; }
.wpcc-risk-badge {
	display:inline-block;font-size:11px;font-weight:600;padding:2px 8px;border-radius:3px;color:#fff;text-transform:uppercase;float:right;
}
.wpcc-risk-badge.risk-critical { background:#d63638; }
.wpcc-risk-badge.risk-high     { background:#dba617; color:#1e1e1e; }
.wpcc-risk-badge.risk-medium   { background:#72aee6; color:#1e1e1e; }
.wpcc-risk-badge.risk-low      { background:#00a32a; }
.wpcc-risk-badge.risk-diagnostic { background:#8c8f94; }
.wpcc-card-header { display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:10px; }
.wpcc-card-title  { font-size:15px;font-weight:600;margin:0; }
.wpcc-card-meta   { font-size:13px;color:#50575e;margin:6px 0; }
.wpcc-card-meta td { padding:3px 12px 3px 0;vertical-align:top; }
.wpcc-card-meta td:first-child { font-weight:500;white-space:nowrap; }
.wpcc-card-reason { background:#f6f7f7;border-left:3px solid #ddd;padding:8px 12px;margin:10px 0;font-size:13px;color:#3c434a;font-style:italic; }
.wpcc-card-actions { margin-top:14px; }
.wpcc-approve-btn { background:#00a32a;color:#fff;border-color:#00a32a; }
.wpcc-approve-btn:hover { background:#008a20;border-color:#008a20;color:#fff; }
.wpcc-reject-btn  { background:#d63638;color:#fff;border-color:#d63638; }
.wpcc-reject-btn:hover { background:#b32d2e;border-color:#b32d2e;color:#fff; }
.wpcc-card-result { margin-top:10px;padding:8px 12px;border-radius:3px;font-size:13px; }
.wpcc-card-result.success { background:#edfaef;border:1px solid #00a32a;color:#1e1e1e; }
.wpcc-card-result.error   { background:#fce9e9;border:1px solid #d63638;color:#1e1e1e; }
.wpcc-card-destructive {
	background:#fcf0f1;border:1px solid #d63638;color:#8a1f1f;border-radius:3px;
	padding:8px 12px;margin:8px 0 10px;font-size:13px;font-weight:600;
}
.wpcc-card-destructive .dashicons,.wpcc-card-destructive .warn { color:#d63638; }
</style>

<script>
(function() {
	var nonce   = <?php echo wp_json_encode( $nonce ); ?>;
	var apiBase = <?php echo wp_json_encode( $api_base ); ?>;

	var riskLabels = {
		critical:   'Critical',
		high:       'High Risk',
		medium:     'Medium Risk',
		low:        'Low Risk',
		diagnostic: 'Read Only'
	};

	function apiFetch( path, opts ) {
		opts = opts || {};
		return fetch( apiBase + path, Object.assign( {
			headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' }
		}, opts ) ).then( function(r) { return r.json(); } );
	}

	function escHtml( s ) {
		var d = document.createElement('div');
		d.appendChild( document.createTextNode( String(s || '') ) );
		return d.innerHTML;
	}

	function renderCard( req ) {
		var risk  = req.risk_level || 'medium';
		var label = riskLabels[ risk ] || risk;
		var reasonHtml = req.reason
			? '<div class="wpcc-card-reason">' + escHtml( req.reason ) + '</div>'
			: '';
		var destructiveHtml = req.destructive
			? '<div class="wpcc-card-destructive"><span class="warn">&#9888;</span> ' +
				'<?php echo esc_js( __( 'DESTRUCTIVE — this permanently deletes data and cannot be undone.', 'wp-command-center' ) ); ?>' +
				( req.destructive_warning ? ' ' + escHtml( req.destructive_warning ) : '' ) +
				'</div>'
			: '';
		var actionLabel = req.action ? escHtml( req.action ) : '—';
		var opLabel     = escHtml( req.operation );
		var ago         = escHtml( req.created_ago );
		var planInfo    = req.plan_id ? ' &bull; Plan: ' + escHtml( req.plan_id.substring(0,8) ) + '…' : '';

		return '<div class="wpcc-approval-card risk-' + escHtml(risk) + '" id="wpcc-card-' + escHtml(req.request_id) + '">' +
			'<div class="wpcc-card-header">' +
				'<span class="wpcc-card-title">' + opLabel + '</span>' +
				'<span class="wpcc-risk-badge risk-' + escHtml(risk) + '">' + escHtml(label) + '</span>' +
			'</div>' +
			destructiveHtml +
			'<table class="wpcc-card-meta"><tbody>' +
				'<tr><td>Action</td><td>' + actionLabel + '</td></tr>' +
				'<tr><td>Requested</td><td>' + ago + planInfo + '</td></tr>' +
			'</tbody></table>' +
			reasonHtml +
			'<p style="font-size:12px;color:#8c8f94;margin:6px 0;">&#10003; This action will be logged in the audit trail.</p>' +
			'<div class="wpcc-card-actions">' +
				'<button class="button button-primary wpcc-approve-btn" data-id="' + escHtml(req.request_id) + '" data-action="approve">&#10003; Approve</button>' +
				' <button class="button wpcc-reject-btn" data-id="' + escHtml(req.request_id) + '" data-action="reject">&#10007; Reject</button>' +
			'</div>' +
			'<div class="wpcc-card-result" id="wpcc-result-' + escHtml(req.request_id) + '" style="display:none;"></div>' +
		'</div>';
	}

	function updateBadge( count ) {
		var badge = document.getElementById('wpcc-pending-badge');
		if ( ! badge ) return;
		if ( count > 0 ) {
			badge.textContent = count;
			badge.style.display = 'inline';
		} else {
			badge.style.display = 'none';
		}
	}

	function load() {
		apiFetch( '/approvals' ).then( function( data ) {
			var list = document.getElementById('wpcc-approvals-list');
			if ( ! list ) return;

			if ( ! data.requests || data.requests.length === 0 ) {
				list.innerHTML = '<div class="notice notice-info inline" style="max-width:820px;"><p>' +
					'<?php echo esc_js( __( 'No pending approvals. When an AI agent requests a write operation, the approval cards will appear here.', 'wp-command-center' ) ); ?>' +
					'</p></div>';
				updateBadge(0);
				return;
			}

			var html = data.requests.map( renderCard ).join('');
			list.innerHTML = html;
			updateBadge( data.total );
			attachHandlers();
		} ).catch( function(e) {
			var list = document.getElementById('wpcc-approvals-list');
			if ( list ) list.innerHTML = '<div class="notice notice-error inline"><p><?php echo esc_js( __( 'Failed to load approvals.', 'wp-command-center' ) ); ?></p></div>';
		} );
	}

	function attachHandlers() {
		document.querySelectorAll('.wpcc-approve-btn, .wpcc-reject-btn').forEach( function(btn) {
			btn.addEventListener('click', function() {
				var id     = btn.dataset.id;
				var action = btn.dataset.action;
				var card   = document.getElementById('wpcc-card-' + id);
				var result = document.getElementById('wpcc-result-' + id);

				btn.disabled = true;
				var sibling = card.querySelector( action === 'approve' ? '.wpcc-reject-btn' : '.wpcc-approve-btn' );
				if ( sibling ) sibling.disabled = true;

				apiFetch( '/approvals/' + id + '/' + action, { method: 'POST' } ).then( function(data) {
					if ( ! result ) return;
					if ( data.success ) {
						var msg = action === 'approve'
							? '<?php echo esc_js( __( 'Approved and executed.', 'wp-command-center' ) ); ?>'
							: '<?php echo esc_js( __( 'Rejected.', 'wp-command-center' ) ); ?>';
						if ( data.error ) {
							msg = '<?php echo esc_js( __( 'Approved but execution failed: ', 'wp-command-center' ) ); ?>' + escHtml( data.error );
						}
						result.innerHTML  = msg;
						result.className  = 'wpcc-card-result success';
						result.style.display = 'block';
						if ( card ) card.style.opacity = '0.6';
					} else {
						result.innerHTML  = escHtml( data.error || '<?php echo esc_js( __( 'Unknown error.', 'wp-command-center' ) ); ?>' );
						result.className  = 'wpcc-card-result error';
						result.style.display = 'block';
						btn.disabled = false;
						if ( sibling ) sibling.disabled = false;
					}
				} ).catch( function() {
					if ( result ) {
						result.innerHTML = '<?php echo esc_js( __( 'Request failed. Please try again.', 'wp-command-center' ) ); ?>';
						result.className = 'wpcc-card-result error';
						result.style.display = 'block';
					}
					btn.disabled = false;
					if ( sibling ) sibling.disabled = false;
				} );
			} );
		} );
	}

	document.addEventListener('DOMContentLoaded', load);
})();
</script>
