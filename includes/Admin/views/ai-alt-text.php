<?php
/**
 * STEP 110 (Task 8.1 + 8.2) — Builder-facing AI Alt Text surface.
 *
 * A THIN REST CLIENT over existing, validated endpoints only:
 *   - GET  /wp-command-center/v1/admin/alt-text/scan        (Review audit, 7A)
 *   - POST /wp-command-center/v1/admin/alt-text/generate    (suggestions, 7C)
 *   - GET  /wp-command-center/v1/admin/proposals            (draft suggestions, 5)
 *   - PATCH/wp-command-center/v1/admin/proposals/{id}       (edit final_payload, 5)
 *   - POST /wp-command-center/v1/admin/proposals/{id}/dismiss (dismiss, 5)
 *   - GET  /wp/v2/media?include=…  (WP core; thumbnails/filenames only)
 *
 * Two tabs: Review (8.1 audit + 8.2 select/generate) and Suggestions (8.2 review/
 * edit/dismiss of governed DRAFTS). It performs NO apply/approve/rollback/undo,
 * and has NO Change History / Approval Center controls — those are Task 8.3.
 *
 * Outcome language only: no proposal_id / request_id / change_id / operation_id /
 * payload JSON / raw status enum / batch_id is ever displayed. proposal_id is used
 * solely as an opaque DOM key (data-id) to call edit/dismiss.
 */

defined( 'ABSPATH' ) || exit;

$nonce      = wp_create_nonce( 'wp_rest' );
$api_base   = rest_url( 'wp-command-center/v1/admin' );
$core_base  = rest_url( 'wp/v2' );
// Server-rendered security mode drives the apply button label (developer applies
// directly; client/enterprise submit for approval). The actual outcome is still
// taken from the apply API response (defensive).
$security_mode = \WPCommandCenter\Operations\SecurityModeManager::current();
$approval_url  = admin_url( 'admin.php?page=wpcc-approval-center' );
$history_url   = admin_url( 'admin.php?page=wpcc-change-history' );
?>
<div class="wrap wpcc-wrap">
	<h1><?php esc_html_e( 'AI Alt Text', 'wp-command-center' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'Generate and review AI alt-text suggestions for your images. Suggestions are drafts — nothing is applied to your site here.', 'wp-command-center' ); ?>
	</p>

	<!-- Readiness header -->
	<div id="wpcc-at-readiness" style="display:flex;gap:24px;flex-wrap:wrap;margin:16px 0;padding:16px;border:1px solid #c3c4c7;background:#fff;border-radius:4px;">
		<div><strong style="font-size:22px;" id="wpcc-at-pct">—</strong><br><span class="description"><?php esc_html_e( 'Media described', 'wp-command-center' ); ?></span></div>
		<div><strong style="font-size:22px;color:#b32d2e;" id="wpcc-at-missing">—</strong><br><span class="description"><?php esc_html_e( 'Missing alt text', 'wp-command-center' ); ?></span></div>
		<div><strong style="font-size:22px;color:#bd8600;" id="wpcc-at-weak">—</strong><br><span class="description"><?php esc_html_e( 'Weak alt text', 'wp-command-center' ); ?></span></div>
		<div><strong style="font-size:22px;" id="wpcc-at-total">—</strong><br><span class="description"><?php esc_html_e( 'Total images', 'wp-command-center' ); ?></span></div>
	</div>

	<!-- Tabs -->
	<h2 class="nav-tab-wrapper">
		<a href="#" class="nav-tab nav-tab-active" id="wpcc-at-tab-review"><?php esc_html_e( 'Review', 'wp-command-center' ); ?></a>
		<a href="#" class="nav-tab" id="wpcc-at-tab-suggestions"><?php esc_html_e( 'Suggestions', 'wp-command-center' ); ?></a>
		<a href="#" class="nav-tab" id="wpcc-at-tab-applied"><?php esc_html_e( 'Applied', 'wp-command-center' ); ?></a>
	</h2>

	<!-- ============ REVIEW TAB ============ -->
	<div id="wpcc-at-panel-review">
		<div style="display:flex;align-items:center;gap:12px;margin:12px 0;flex-wrap:wrap;">
			<label for="wpcc-at-filter"><?php esc_html_e( 'Show:', 'wp-command-center' ); ?></label>
			<select id="wpcc-at-filter">
				<option value="missing"><?php esc_html_e( 'Missing', 'wp-command-center' ); ?></option>
				<option value="weak"><?php esc_html_e( 'Weak', 'wp-command-center' ); ?></option>
				<option value="all"><?php esc_html_e( 'All images', 'wp-command-center' ); ?></option>
			</select>
			<label><input type="checkbox" id="wpcc-at-selectall"> <?php esc_html_e( 'Select all on this page', 'wp-command-center' ); ?></label>
			<button type="button" class="button button-primary" id="wpcc-at-generate" disabled><?php esc_html_e( 'Generate suggestions', 'wp-command-center' ); ?></button>
			<span class="description"><?php
				/* translators: %d: max images per generation */
				printf( esc_html__( 'Up to %d images per generation.', 'wp-command-center' ), 25 );
			?></span>
			<span id="wpcc-at-status" role="status" aria-live="polite" style="margin-left:auto;color:#646970;"></span>
		</div>

		<!-- progress -->
		<div id="wpcc-at-progress" style="display:none;margin:8px 0;padding:10px;border:1px solid #c3c4c7;background:#fff;border-radius:4px;">
			<span id="wpcc-at-progress-text"></span>
		</div>

		<table class="widefat striped">
			<thead>
				<tr>
					<th style="width:28px;"></th>
					<th style="width:60px;"><?php esc_html_e( 'Image', 'wp-command-center' ); ?></th>
					<th><?php esc_html_e( 'File', 'wp-command-center' ); ?></th>
					<th><?php esc_html_e( 'Current alt text', 'wp-command-center' ); ?></th>
					<th style="width:120px;"><?php esc_html_e( 'State', 'wp-command-center' ); ?></th>
				</tr>
			</thead>
			<tbody id="wpcc-at-rows">
				<tr><td colspan="5"><?php esc_html_e( 'Loading…', 'wp-command-center' ); ?></td></tr>
			</tbody>
		</table>

		<p style="margin-top:12px;">
			<button type="button" class="button" id="wpcc-at-prev" disabled>&larr; <?php esc_html_e( 'Previous', 'wp-command-center' ); ?></button>
			<button type="button" class="button" id="wpcc-at-next" disabled><?php esc_html_e( 'Next', 'wp-command-center' ); ?> &rarr;</button>
		</p>
	</div>

	<!-- ============ SUGGESTIONS TAB ============ -->
	<div id="wpcc-at-panel-suggestions" style="display:none;">
		<p style="margin:12px 0;">
			<span class="description"><?php esc_html_e( 'AI suggestions awaiting your review. Edit the text or dismiss a suggestion. Nothing is applied to your site yet.', 'wp-command-center' ); ?></span>
			<span id="wpcc-at-sg-status" role="status" aria-live="polite" style="margin-left:12px;color:#646970;"></span>
		</p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th style="width:60px;"><?php esc_html_e( 'Image', 'wp-command-center' ); ?></th>
					<th><?php esc_html_e( 'File', 'wp-command-center' ); ?></th>
					<th style="width:24%;"><?php esc_html_e( 'Current alt text', 'wp-command-center' ); ?></th>
					<th><?php esc_html_e( 'Suggested alt text', 'wp-command-center' ); ?></th>
					<th style="width:150px;"><?php esc_html_e( 'Actions', 'wp-command-center' ); ?></th>
				</tr>
			</thead>
			<tbody id="wpcc-at-sg-rows">
				<tr><td colspan="5"><?php esc_html_e( 'Loading…', 'wp-command-center' ); ?></td></tr>
			</tbody>
		</table>
		<p style="margin-top:12px;">
			<button type="button" class="button" id="wpcc-at-sg-prev" disabled>&larr; <?php esc_html_e( 'Previous', 'wp-command-center' ); ?></button>
			<button type="button" class="button" id="wpcc-at-sg-next" disabled><?php esc_html_e( 'Next', 'wp-command-center' ); ?> &rarr;</button>
		</p>
	</div>

	<!-- ============ APPLIED TAB ============ -->
	<div id="wpcc-at-panel-applied" style="display:none;">
		<p style="margin:12px 0;">
			<span class="description"><?php esc_html_e( 'Applied descriptions and items awaiting approval. Undo is available for reversible changes.', 'wp-command-center' ); ?></span>
			<span id="wpcc-at-ap-status" role="status" aria-live="polite" style="margin-left:12px;color:#646970;"></span>
		</p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th style="width:60px;"><?php esc_html_e( 'Image', 'wp-command-center' ); ?></th>
					<th><?php esc_html_e( 'File', 'wp-command-center' ); ?></th>
					<th><?php esc_html_e( 'Applied alt text', 'wp-command-center' ); ?></th>
					<th style="width:140px;"><?php esc_html_e( 'Status', 'wp-command-center' ); ?></th>
					<th style="width:160px;"><?php esc_html_e( 'Actions', 'wp-command-center' ); ?></th>
				</tr>
			</thead>
			<tbody id="wpcc-at-ap-rows">
				<tr><td colspan="5"><?php esc_html_e( 'Loading…', 'wp-command-center' ); ?></td></tr>
			</tbody>
		</table>
	</div>
</div>

<script>
( function () {
	const API   = <?php echo wp_json_encode( $api_base ); ?>;
	const CORE  = <?php echo wp_json_encode( $core_base ); ?>;
	const NONCE = <?php echo wp_json_encode( $nonce ); ?>;
	const MODE  = <?php echo wp_json_encode( $security_mode ); ?>; // developer | client | enterprise
	const APPROVAL_URL = <?php echo wp_json_encode( $approval_url ); ?>;
	const HISTORY_URL  = <?php echo wp_json_encode( $history_url ); ?>;
	const IS_DEV = ( MODE === 'developer' );
	const LIMIT = 20;
	const MAX_BATCH = 25;
	const CHUNK = 3; // client-side chunked generation: small requests, incremental progress
	const STR = {
		empty:   <?php echo wp_json_encode( esc_html__( 'No images in this view. 🎉', 'wp-command-center' ) ); ?>,
		error:   <?php echo wp_json_encode( esc_html__( 'Could not load. Please retry.', 'wp-command-center' ) ); ?>,
		loading: <?php echo wp_json_encode( esc_html__( 'Loading…', 'wp-command-center' ) ); ?>,
		none:    <?php echo wp_json_encode( esc_html__( '(no alt text)', 'wp-command-center' ) ); ?>,
		pending: <?php echo wp_json_encode( esc_html__( 'Suggestion pending', 'wp-command-center' ) ); ?>,
		missing: <?php echo wp_json_encode( esc_html__( 'Missing', 'wp-command-center' ) ); ?>,
		weak:    <?php echo wp_json_encode( esc_html__( 'Weak', 'wp-command-center' ) ); ?>,
		ok:      <?php echo wp_json_encode( esc_html__( 'OK', 'wp-command-center' ) ); ?>,
		cap:     <?php echo wp_json_encode( esc_html__( 'You can generate up to 25 at a time; only the first 25 will be used.', 'wp-command-center' ) ); ?>,
		generating: <?php echo wp_json_encode( esc_html__( 'Generating', 'wp-command-center' ) ); ?>,
		byAI:    <?php echo wp_json_encode( esc_html__( 'Suggested by AI', 'wp-command-center' ) ); ?>,
		edited:  <?php echo wp_json_encode( esc_html__( 'Edited', 'wp-command-center' ) ); ?>,
		save:    <?php echo wp_json_encode( esc_html__( 'Save', 'wp-command-center' ) ); ?>,
		saved:   <?php echo wp_json_encode( esc_html__( 'Saved', 'wp-command-center' ) ); ?>,
		dismiss: <?php echo wp_json_encode( esc_html__( 'Dismiss', 'wp-command-center' ) ); ?>,
		dismissed: <?php echo wp_json_encode( esc_html__( 'Dismissed', 'wp-command-center' ) ); ?>,
		noSug:   <?php echo wp_json_encode( esc_html__( 'No suggestions yet. Generate some from the Review tab.', 'wp-command-center' ) ); ?>,
		applyDev:  <?php echo wp_json_encode( esc_html__( 'Approve & Apply', 'wp-command-center' ) ); ?>,
		applyGate: <?php echo wp_json_encode( esc_html__( 'Submit for approval', 'wp-command-center' ) ); ?>,
		applied:   <?php echo wp_json_encode( esc_html__( 'Applied', 'wp-command-center' ) ); ?>,
		awaiting:  <?php echo wp_json_encode( esc_html__( 'Awaiting approval', 'wp-command-center' ) ); ?>,
		reverted:  <?php echo wp_json_encode( esc_html__( 'Reverted', 'wp-command-center' ) ); ?>,
		cantApply: <?php echo wp_json_encode( esc_html__( 'Couldn’t apply', 'wp-command-center' ) ); ?>,
		undo:      <?php echo wp_json_encode( esc_html__( 'Undo', 'wp-command-center' ) ); ?>,
		undoSent:  <?php echo wp_json_encode( esc_html__( 'Undo sent for approval', 'wp-command-center' ) ); ?>,
		reviewAppr: <?php echo wp_json_encode( esc_html__( 'Review in Approval Center →', 'wp-command-center' ) ); ?>,
		viewHist:  <?php echo wp_json_encode( esc_html__( 'View in Change History →', 'wp-command-center' ) ); ?>,
		noApplied: <?php echo wp_json_encode( esc_html__( 'Nothing applied yet.', 'wp-command-center' ) ); ?>
	};

	const $ = ( id ) => document.getElementById( id );
	const esc = ( s ) => String( s == null ? '' : s ).replace( /[&<>"']/g, ( c ) => ( { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[ c ] ) );

	function req( base, path, opts ) {
		opts = opts || {};
		opts.headers = Object.assign( { 'X-WP-Nonce': NONCE }, opts.headers || {} );
		return fetch( base + path, opts ).then( ( r ) => r.json().then( ( d ) => ( { status: r.status, ok: r.ok, data: d } ) ) );
	}
	const wpcc = ( path, opts ) => req( API, path, opts );
	const core = ( path ) => req( CORE, path );

	// ---------- REVIEW TAB ----------
	let rOffset = 0, rHasMore = false;

	function badge( state ) {
		const map = { missing:[ '#b32d2e', STR.missing ], weak:[ '#bd8600', STR.weak ], ok:[ '#1a7f37', STR.ok ] };
		const m = map[ state ] || [ '#646970', esc( state ) ];
		return '<span style="display:inline-block;padding:2px 8px;border-radius:10px;color:#fff;font-size:12px;background:' + m[0] + ';">' + esc( m[1] ) + '</span>';
	}
	function selectedIds() {
		return Array.prototype.slice.call( document.querySelectorAll( '.wpcc-at-cb:checked' ) ).map( ( c ) => parseInt( c.value, 10 ) );
	}
	function refreshGenerateBtn() {
		const n = selectedIds().length;
		const btn = $( 'wpcc-at-generate' );
		btn.disabled = n === 0;
		btn.textContent = n > 0
			? <?php echo wp_json_encode( esc_html__( 'Generate suggestions', 'wp-command-center' ) ); ?> + ' (' + n + ')'
			: <?php echo wp_json_encode( esc_html__( 'Generate suggestions', 'wp-command-center' ) ); ?>;
		$( 'wpcc-at-status' ).textContent = ( n > MAX_BATCH ) ? STR.cap : '';
	}

	function loadReview() {
		const filter = $( 'wpcc-at-filter' ).value;
		$( 'wpcc-at-rows' ).innerHTML = '<tr><td colspan="5">' + esc( STR.loading ) + '</td></tr>';
		wpcc( '/alt-text/scan?state=' + encodeURIComponent( filter ) + '&limit=' + LIMIT + '&offset=' + rOffset )
			.then( ( res ) => {
				const d = res.data || {}, s = d.summary || {};
				$( 'wpcc-at-pct' ).textContent = ( s.described_pct != null ? s.described_pct : 0 ) + '%';
				$( 'wpcc-at-missing' ).textContent = s.missing != null ? s.missing : 0;
				$( 'wpcc-at-weak' ).textContent = s.weak != null ? s.weak : 0;
				$( 'wpcc-at-total' ).textContent = s.total_images != null ? s.total_images : 0;
				const items = d.items || [];
				if ( ! items.length ) {
					$( 'wpcc-at-rows' ).innerHTML = '<tr><td colspan="5">' + esc( STR.empty ) + '</td></tr>';
				} else {
					$( 'wpcc-at-rows' ).innerHTML = items.map( ( it ) => {
						const thumb = it.url ? '<img src="' + esc( it.url ) + '" alt="" width="48" height="48" style="object-fit:cover;border-radius:3px;">' : '';
						const pending = it.has_open_proposal;
						// Rows with a pending suggestion are NOT selectable (generator would skip them).
						const cb = pending
							? '<span class="description" title="' + esc( STR.pending ) + '">•</span>'
							: '<input type="checkbox" class="wpcc-at-cb" value="' + esc( it.attachment_id ) + '">';
						const pendingChip = pending ? ' <span style="display:inline-block;padding:1px 6px;border-radius:8px;font-size:11px;background:#dcdcde;color:#1d2327;">' + esc( STR.pending ) + '</span>' : '';
						const alt = it.alt ? esc( it.alt ) : '<em style="color:#646970;">' + esc( STR.none ) + '</em>';
						return '<tr><td>' + cb + '</td><td>' + thumb + '</td>' +
							'<td>' + esc( it.title || '' ) + pendingChip + '</td>' +
							'<td>' + alt + '</td><td>' + badge( it.state ) + '</td></tr>';
					} ).join( '' );
				}
				rHasMore = !! d.has_more;
				$( 'wpcc-at-prev' ).disabled = rOffset <= 0;
				$( 'wpcc-at-next' ).disabled = ! rHasMore;
				$( 'wpcc-at-selectall' ).checked = false;
				refreshGenerateBtn();
			} )
			.catch( () => { $( 'wpcc-at-rows' ).innerHTML = '<tr><td colspan="5" style="color:#b32d2e;">' + esc( STR.error ) + '</td></tr>'; } );
	}

	// Client-side CHUNKED generation: small POSTs to /alt-text/generate, incremental progress.
	function generate() {
		let ids = selectedIds();
		if ( ! ids.length ) { return; }
		if ( ids.length > MAX_BATCH ) { ids = ids.slice( 0, MAX_BATCH ); }
		const total = ids.length;
		let created = 0, skipped = 0, failed = 0, done = 0;
		$( 'wpcc-at-generate' ).disabled = true;
		const prog = $( 'wpcc-at-progress' ); prog.style.display = 'block';
		const setProg = () => { $( 'wpcc-at-progress-text' ).textContent =
			STR.generating + ' ' + done + '/' + total + ' — ' + created + ' created, ' + skipped + ' skipped, ' + failed + ' failed'; };
		setProg();

		const chunks = [];
		for ( let i = 0; i < ids.length; i += CHUNK ) { chunks.push( ids.slice( i, i + CHUNK ) ); }

		function runChunk( idx ) {
			if ( idx >= chunks.length ) {
				$( 'wpcc-at-progress-text' ).textContent = total + ' processed — ' + created + ' created, ' + skipped + ' skipped, ' + failed + ' failed.';
				setTimeout( () => { switchTab( 'suggestions' ); }, 600 );
				return;
			}
			wpcc( '/alt-text/generate', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( { attachment_ids: chunks[ idx ] } )
			} ).then( ( res ) => {
				const d = res.data || {};
				created += ( d.created || [] ).length;
				skipped += ( d.skipped || [] ).length;
				failed  += ( d.failed  || [] ).length;
				done    += chunks[ idx ].length;
				setProg();
				runChunk( idx + 1 );
			} ).catch( () => {
				failed += chunks[ idx ].length; done += chunks[ idx ].length; setProg(); runChunk( idx + 1 );
			} );
		}
		runChunk( 0 );
	}

	// ---------- SUGGESTIONS TAB ----------
	let sOffset = 0, sHasMore = false;

	function loadSuggestions() {
		$( 'wpcc-at-sg-rows' ).innerHTML = '<tr><td colspan="5">' + esc( STR.loading ) + '</td></tr>';
		// alt-text drafts only (media_manage drafts are produced solely by the alt-text generator).
		wpcc( '/proposals?status=draft&operation_id=media_manage&limit=' + LIMIT + '&offset=' + sOffset )
			.then( ( res ) => {
				const d = res.data || {}, list = d.proposals || [];
				sHasMore = !! d.has_more;
				$( 'wpcc-at-sg-prev' ).disabled = sOffset <= 0;
				$( 'wpcc-at-sg-next' ).disabled = ! sHasMore;
				if ( ! list.length ) { $( 'wpcc-at-sg-rows' ).innerHTML = '<tr><td colspan="5">' + esc( STR.noSug ) + '</td></tr>'; $( 'wpcc-at-sg-status' ).textContent = ''; return; }
				// Resolve thumbnails/filenames via WP core media (batched).
				const ids = list.map( ( p ) => parseInt( p.target_id, 10 ) ).filter( ( n ) => n > 0 );
				core( '/media?include=' + ids.join( ',' ) + '&per_page=' + ids.length + '&_fields=id,source_url,media_details,title' )
					.then( ( mres ) => {
						const media = {};
						( Array.isArray( mres.data ) ? mres.data : [] ).forEach( ( m ) => {
							const t = ( m.media_details && m.media_details.sizes && m.media_details.sizes.thumbnail ) ? m.media_details.sizes.thumbnail.source_url : m.source_url;
							media[ m.id ] = { thumb: t, title: ( m.title && m.title.rendered ) || '' };
						} );
						renderSuggestions( list, media );
						$( 'wpcc-at-sg-status' ).textContent = ( sOffset + 1 ) + '–' + ( sOffset + list.length ) + ' of ' + ( d.total_count != null ? d.total_count : list.length );
					} )
					.catch( () => renderSuggestions( list, {} ) );
			} )
			.catch( () => { $( 'wpcc-at-sg-rows' ).innerHTML = '<tr><td colspan="5" style="color:#b32d2e;">' + esc( STR.error ) + '</td></tr>'; } );
	}

	function renderSuggestions( list, media ) {
		$( 'wpcc-at-sg-rows' ).innerHTML = list.map( ( p ) => {
			const tid = parseInt( p.target_id, 10 );
			const m = media[ tid ] || {};
			const original = ( p.payload && p.payload.alt ) || '';
			const suggested = ( p.final_payload && p.final_payload.alt != null ) ? p.final_payload.alt : original;
			const isEdited = ( p.final_payload && p.final_payload.alt != null && p.final_payload.alt !== original );
			const cur = ( p.prior && p.prior.alt ) ? esc( p.prior.alt ) : '<em style="color:#646970;">' + esc( STR.none ) + '</em>';
			const thumb = m.thumb ? '<img src="' + esc( m.thumb ) + '" alt="" width="48" height="48" style="object-fit:cover;border-radius:3px;">' : '';
			const editedChip = isEdited ? ' <span style="display:inline-block;padding:1px 6px;border-radius:8px;font-size:11px;background:#cce5d6;color:#1a4731;">' + esc( STR.edited ) + '</span>' : '';
			const prov = p.provider ? '<div class="description" style="font-size:11px;">' + esc( STR.byAI ) + '</div>' : '';
			// proposal_id is an OPAQUE DOM key only — never shown to the user.
			// data-mid carries the attachment id needed to rebuild final_payload on save.
			return '<tr data-id="' + esc( p.proposal_id ) + '" data-mid="' + esc( tid ) + '">' +
				'<td>' + thumb + '</td>' +
				'<td>' + esc( m.title || ( '#' + tid ) ) + editedChip + prov + '</td>' +
				'<td>' + cur + '</td>' +
				'<td><textarea class="wpcc-at-edit large-text" rows="2" style="width:100%;">' + esc( suggested ) + '</textarea></td>' +
				'<td>' +
					'<button type="button" class="button button-primary button-small wpcc-at-apply">' + esc( IS_DEV ? STR.applyDev : STR.applyGate ) + '</button> ' +
					'<button type="button" class="button button-small wpcc-at-save">' + esc( STR.save ) + '</button> ' +
					'<button type="button" class="button button-small wpcc-at-dismiss">' + esc( STR.dismiss ) + '</button>' +
					'<div class="wpcc-at-rowmsg description" role="status" style="margin-top:4px;"></div>' +
				'</td></tr>';
		} ).join( '' );
	}

	// Edit (final_payload) + dismiss, delegated.
	document.addEventListener( 'click', function ( e ) {
		const t = e.target;
		const row = t.closest ? t.closest( 'tr[data-id]' ) : null;
		if ( ! row ) { return; }
		const id = row.getAttribute( 'data-id' );
		const msg = row.querySelector( '.wpcc-at-rowmsg' );
		if ( t.classList.contains( 'wpcc-at-save' ) ) {
			const text = row.querySelector( '.wpcc-at-edit' ).value;
			const mid = parseInt( row.getAttribute( 'data-mid' ) || '0', 10 );
			const final_payload = { action: 'media_update', media_id: mid, alt: text };
			if ( msg ) { msg.textContent = '…'; }
			wpcc( '/proposals/' + encodeURIComponent( id ), {
				method: 'PUT',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( { final_payload: final_payload } )
			} ).then( ( res ) => { if ( msg ) { msg.textContent = res.ok ? STR.saved : ( ( res.data && res.data.message ) || STR.error ); } } )
			  .catch( () => { if ( msg ) { msg.textContent = STR.error; } } );
		} else if ( t.classList.contains( 'wpcc-at-dismiss' ) ) {
			wpcc( '/proposals/' + encodeURIComponent( id ) + '/dismiss', { method: 'POST' } )
				.then( ( res ) => { if ( res.ok ) { row.parentNode.removeChild( row ); } else if ( msg ) { msg.textContent = ( res.data && res.data.message ) || STR.error; } } )
				.catch( () => { if ( msg ) { msg.textContent = STR.error; } } );
		} else if ( t.classList.contains( 'wpcc-at-apply' ) ) {
			// Approve & Apply (developer) / Submit for approval (gated). Outcome is
			// driven by the API response — never assumed from the button label.
			t.disabled = true;
			if ( msg ) { msg.textContent = '…'; }
			wpcc( '/proposals/' + encodeURIComponent( id ) + '/apply', { method: 'POST' } )
				.then( ( res ) => {
					const st = ( res.data && res.data.status ) || '';
					if ( st === 'applied' || st === 'pending_approval' ) {
						// Leaves the Suggestions list either way; lives in Applied tab now.
						row.parentNode.removeChild( row );
						$( 'wpcc-at-sg-status' ).textContent = ( st === 'applied' ) ? STR.applied : STR.awaiting;
					} else {
						t.disabled = false;
						if ( msg ) { msg.textContent = ( res.data && res.data.message ) ? STR.cantApply : STR.cantApply; }
					}
				} )
				.catch( () => { t.disabled = false; if ( msg ) { msg.textContent = STR.cantApply; } } );
		} else if ( t.classList.contains( 'wpcc-at-undo' ) ) {
			// Undo via the EXISTING Change History rollback route (single rollback
			// primitive). Developer -> immediate revert; gated -> sent for approval.
			const cid = row.getAttribute( 'data-cid' );
			if ( ! cid ) { return; }
			t.disabled = true;
			const amsg = row.querySelector( '.wpcc-at-rowmsg' );
			if ( amsg ) { amsg.textContent = '…'; }
			fetch( API + '/history/' + encodeURIComponent( cid ) + '/rollback', { method: 'POST', headers: { 'X-WP-Nonce': NONCE } } )
				.then( ( r ) => r.json().then( ( d ) => ( { ok: r.ok, data: d } ) ) )
				.then( ( res ) => {
					const inner = ( res.data && res.data.result ) || {};
					if ( inner.status === 'pending_approval' ) {
						if ( amsg ) { amsg.textContent = STR.undoSent; }
					} else {
						loadApplied(); // refresh -> rollback-aware status flips to Reverted
					}
				} )
				.catch( () => { t.disabled = false; if ( amsg ) { amsg.textContent = STR.error; } } );
		}
	} );

	// ---------- APPLIED TAB ----------
	function loadApplied() {
		$( 'wpcc-at-ap-rows' ).innerHTML = '<tr><td colspan="5">' + esc( STR.loading ) + '</td></tr>';
		// Applied (with Undo / rollback-aware) + pending_approval (awaiting) — two
		// reads of the SAME endpoint, merged. No new endpoint.
		Promise.all( [
			wpcc( '/proposals?status=applied&operation_id=media_manage&limit=50' ),
			wpcc( '/proposals?status=pending_approval&operation_id=media_manage&limit=50' )
		] ).then( ( results ) => {
			const applied = ( results[0].data && results[0].data.proposals ) || [];
			const pending = ( results[1].data && results[1].data.proposals ) || [];
			const list = pending.concat( applied );
			if ( ! list.length ) { $( 'wpcc-at-ap-rows' ).innerHTML = '<tr><td colspan="5">' + esc( STR.noApplied ) + '</td></tr>'; $( 'wpcc-at-ap-status' ).textContent = ''; return; }
			const ids = list.map( ( p ) => parseInt( p.target_id, 10 ) ).filter( ( n ) => n > 0 );
			core( '/media?include=' + ids.join( ',' ) + '&per_page=' + ids.length + '&_fields=id,source_url,media_details,title' )
				.then( ( mres ) => {
					const media = {};
					( Array.isArray( mres.data ) ? mres.data : [] ).forEach( ( m ) => {
						const th = ( m.media_details && m.media_details.sizes && m.media_details.sizes.thumbnail ) ? m.media_details.sizes.thumbnail.source_url : m.source_url;
						media[ m.id ] = { thumb: th, title: ( m.title && m.title.rendered ) || '' };
					} );
					renderApplied( list, media );
				} )
				.catch( () => renderApplied( list, {} ) );
		} ).catch( () => { $( 'wpcc-at-ap-rows' ).innerHTML = '<tr><td colspan="5" style="color:#b32d2e;">' + esc( STR.error ) + '</td></tr>'; } );
	}

	function apBadge( color, label ) {
		return '<span style="display:inline-block;padding:2px 8px;border-radius:10px;color:#fff;font-size:12px;background:' + color + ';">' + esc( label ) + '</span>';
	}

	function renderApplied( list, media ) {
		$( 'wpcc-at-ap-rows' ).innerHTML = list.map( ( p ) => {
			const tid = parseInt( p.target_id, 10 );
			const m = media[ tid ] || {};
			const appliedAlt = ( p.final_payload && p.final_payload.alt != null ) ? p.final_payload.alt : ( ( p.payload && p.payload.alt ) || '' );
			const thumb = m.thumb ? '<img src="' + esc( m.thumb ) + '" alt="" width="48" height="48" style="object-fit:cover;border-radius:3px;">' : '';
			let badge = '', actions = '';
			if ( p.status === 'pending_approval' ) {
				badge = apBadge( '#bd8600', STR.awaiting );
				actions = '<a href="' + esc( APPROVAL_URL ) + '">' + esc( STR.reviewAppr ) + '</a>';
			} else if ( p.status === 'applied' && p.change_status === 'rolled_back' ) {
				badge = apBadge( '#646970', STR.reverted );
				actions = '<a href="' + esc( HISTORY_URL ) + '">' + esc( STR.viewHist ) + '</a>';
			} else { // applied + reversible
				badge = apBadge( '#1a7f37', STR.applied );
				// change_id is an OPAQUE handle for the single Undo action — never displayed.
				const cid = p.change_id ? ' data-cid="' + esc( p.change_id ) + '"' : '';
				actions = ( p.change_id ? '<button type="button" class="button button-small wpcc-at-undo">' + esc( STR.undo ) + '</button> ' : '' ) +
					'<a href="' + esc( HISTORY_URL ) + '">' + esc( STR.viewHist ) + '</a>';
				return '<tr data-id="' + esc( p.proposal_id ) + '"' + cid + '>' +
					'<td>' + thumb + '</td><td>' + esc( m.title || ( '#' + tid ) ) + '</td>' +
					'<td>' + esc( appliedAlt ) + '</td><td>' + badge + '</td>' +
					'<td>' + actions + '<div class="wpcc-at-rowmsg description" role="status" style="margin-top:4px;"></div></td></tr>';
			}
			return '<tr data-id="' + esc( p.proposal_id ) + '">' +
				'<td>' + thumb + '</td><td>' + esc( m.title || ( '#' + tid ) ) + '</td>' +
				'<td>' + esc( appliedAlt ) + '</td><td>' + badge + '</td>' +
				'<td>' + actions + '</td></tr>';
		} ).join( '' );
	}

	// ---------- tabs + wiring ----------
	function switchTab( which ) {
		$( 'wpcc-at-panel-review' ).style.display = ( which === 'review' ) ? '' : 'none';
		$( 'wpcc-at-panel-suggestions' ).style.display = ( which === 'suggestions' ) ? '' : 'none';
		$( 'wpcc-at-panel-applied' ).style.display = ( which === 'applied' ) ? '' : 'none';
		$( 'wpcc-at-tab-review' ).classList.toggle( 'nav-tab-active', which === 'review' );
		$( 'wpcc-at-tab-suggestions' ).classList.toggle( 'nav-tab-active', which === 'suggestions' );
		$( 'wpcc-at-tab-applied' ).classList.toggle( 'nav-tab-active', which === 'applied' );
		if ( which === 'suggestions' ) { sOffset = 0; loadSuggestions(); }
		else if ( which === 'applied' ) { loadApplied(); }
		else { loadReview(); }
	}

	$( 'wpcc-at-tab-review' ).addEventListener( 'click', function ( e ) { e.preventDefault(); switchTab( 'review' ); } );
	$( 'wpcc-at-tab-suggestions' ).addEventListener( 'click', function ( e ) { e.preventDefault(); switchTab( 'suggestions' ); } );
	$( 'wpcc-at-tab-applied' ).addEventListener( 'click', function ( e ) { e.preventDefault(); switchTab( 'applied' ); } );
	$( 'wpcc-at-filter' ).addEventListener( 'change', function () { rOffset = 0; loadReview(); } );
	$( 'wpcc-at-prev' ).addEventListener( 'click', function () { if ( rOffset > 0 ) { rOffset = Math.max( 0, rOffset - LIMIT ); loadReview(); } } );
	$( 'wpcc-at-next' ).addEventListener( 'click', function () { if ( rHasMore ) { rOffset += LIMIT; loadReview(); } } );
	$( 'wpcc-at-sg-prev' ).addEventListener( 'click', function () { if ( sOffset > 0 ) { sOffset = Math.max( 0, sOffset - LIMIT ); loadSuggestions(); } } );
	$( 'wpcc-at-sg-next' ).addEventListener( 'click', function () { if ( sHasMore ) { sOffset += LIMIT; loadSuggestions(); } } );
	$( 'wpcc-at-generate' ).addEventListener( 'click', generate );
	$( 'wpcc-at-selectall' ).addEventListener( 'change', function () {
		const on = this.checked;
		document.querySelectorAll( '.wpcc-at-cb' ).forEach( ( c ) => { c.checked = on; } );
		refreshGenerateBtn();
	} );
	document.addEventListener( 'change', function ( e ) { if ( e.target.classList.contains( 'wpcc-at-cb' ) ) { refreshGenerateBtn(); } } );

	loadReview();
} )();
</script>
