<?php
/**
 * STEP 111 — Governed Action #2 (SEO Meta Generator) Builder.
 *
 * A THIN REST CLIENT over existing, validated endpoints only:
 *   - GET  /wp-command-center/v1/admin/seo/audit          (Review audit, Slice 1)
 *   - POST /wp-command-center/v1/admin/seo/generate       (generate drafts, Slice 2b)
 *   - GET  /wp-command-center/v1/admin/proposals          (draft suggestions, Slice 3)
 *   - PATCH/wp-command-center/v1/admin/proposals/{id}     (edit final_payload, Slice 3)
 *   - POST /wp-command-center/v1/admin/proposals/{id}/dismiss (dismiss, Slice 3)
 *   - GET  /wp/v2/posts|pages?include=…  (WP core; post title/type only)
 *
 * Two tabs: Review (Slice 1 audit + Slice 2b generate) and Suggestions (Slice 3
 * review/edit/dismiss of governed DRAFTS). It performs NO apply / approve / undo /
 * rollback / bulk, has NO Approval-Center / Change-History controls, and NEVER
 * writes SEO meta. Outcome language only: proposal_id / change_id / request_id are
 * never displayed (proposal_id is an opaque DOM key for edit/dismiss). All API
 * output is escaped client-side via esc().
 */

defined( 'ABSPATH' ) || exit;

$nonce     = wp_create_nonce( 'wp_rest' );
$api_base  = rest_url( 'wp-command-center/v1/admin' );
$core_base = rest_url( 'wp/v2' );
$edit_base = admin_url( 'post.php' ); // client builds ?post=ID&action=edit (any post type)
// Server-rendered security mode drives the apply button label (developer applies
// directly; client/enterprise submit for approval). The outcome is still taken from
// the apply API response (defensive) — the UI never assumes from the label.
$security_mode = \WPCommandCenter\Operations\SecurityModeManager::current();
?>
<div class="wrap wpcc-wrap wpcc-seo">
	<h1><?php esc_html_e( 'SEO Meta', 'wp-command-center' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'Audit which posts and pages are missing or have weak SEO titles and meta descriptions. Read-only — this page does not change anything.', 'wp-command-center' ); ?>
	</p>

	<h2 class="nav-tab-wrapper">
		<a href="#" class="nav-tab nav-tab-active" id="wpcc-seo-tab-review"><?php esc_html_e( 'Review', 'wp-command-center' ); ?></a>
		<a href="#" class="nav-tab" id="wpcc-seo-tab-suggestions"><?php esc_html_e( 'Suggestions', 'wp-command-center' ); ?></a>
		<a href="#" class="nav-tab" id="wpcc-seo-tab-applied"><?php esc_html_e( 'Applied', 'wp-command-center' ); ?></a>
	</h2>

	<!-- ============ REVIEW TAB ============ -->
	<div id="wpcc-seo-panel-review">
	<div id="wpcc-seo-readiness" class="wpcc-seo-readiness" role="status" aria-live="polite"></div>

	<div class="wpcc-seo-filters" id="wpcc-seo-controls" style="display:none;">
		<label for="wpcc-seo-filter"><?php esc_html_e( 'Show:', 'wp-command-center' ); ?></label>
		<select id="wpcc-seo-filter">
			<option value="missing"><?php esc_html_e( 'Missing', 'wp-command-center' ); ?></option>
			<option value="weak"><?php esc_html_e( 'Weak', 'wp-command-center' ); ?></option>
			<option value="all"><?php esc_html_e( 'All content', 'wp-command-center' ); ?></option>
		</select>
		<span id="wpcc-seo-count" class="wpcc-seo-count" role="status" aria-live="polite"></span>
		<?php // GA#2 Slice 2b — minimal generate control. Creates governed DRAFTS only; nothing is applied here. ?>
		<label><input type="checkbox" id="wpcc-seo-selectall"> <?php esc_html_e( 'Select all on this page', 'wp-command-center' ); ?></label>
		<button type="button" class="button button-primary" id="wpcc-seo-generate" disabled><?php esc_html_e( 'Generate suggestions', 'wp-command-center' ); ?></button>
		<span class="description"><?php
			/* translators: %d: max per generation */
			printf( esc_html__( 'Up to %d at a time. Suggestions are drafts — nothing is applied.', 'wp-command-center' ), 25 );
		?></span>
		<span id="wpcc-seo-gen-status" role="status" aria-live="polite" style="margin-left:auto;color:#646970;"></span>
	</div>

	<div id="wpcc-seo-panel">
		<p><span class="spinner is-active wpcc-spin"></span><?php esc_html_e( 'Loading…', 'wp-command-center' ); ?></p>
	</div>

	<div id="wpcc-seo-pager" class="wpcc-seo-pager"></div>
	</div><!-- /review -->

	<!-- ============ SUGGESTIONS TAB ============ -->
	<div id="wpcc-seo-panel-suggestions" style="display:none;">
		<p style="margin:12px 0;">
			<span class="description"><?php esc_html_e( 'AI suggestions awaiting your review. Edit the title or description, or dismiss a suggestion. Nothing is applied to your site here.', 'wp-command-center' ); ?></span>
			<span id="wpcc-seo-sg-status" role="status" aria-live="polite" style="margin-left:12px;color:#646970;"></span>
		</p>
		<table class="widefat striped wpcc-seo-sg-table">
			<thead>
				<tr>
					<th style="width:22%;"><?php esc_html_e( 'Content', 'wp-command-center' ); ?></th>
					<th style="width:26%;"><?php esc_html_e( 'Current', 'wp-command-center' ); ?></th>
					<th><?php esc_html_e( 'Suggested (editable)', 'wp-command-center' ); ?></th>
					<th style="width:130px;"><?php esc_html_e( 'Actions', 'wp-command-center' ); ?></th>
				</tr>
			</thead>
			<tbody id="wpcc-seo-sg-rows">
				<tr><td colspan="4"><?php esc_html_e( 'Loading…', 'wp-command-center' ); ?></td></tr>
			</tbody>
		</table>
		<div id="wpcc-seo-sg-pager" class="wpcc-seo-pager"></div>
	</div>

	<!-- ============ APPLIED TAB (Slice 4a — read-only status) ============ -->
	<div id="wpcc-seo-panel-applied" style="display:none;">
		<p style="margin:12px 0;">
			<span class="description"><?php esc_html_e( 'Applied SEO descriptions and items awaiting approval.', 'wp-command-center' ); ?></span>
			<span id="wpcc-seo-ap-status" role="status" aria-live="polite" style="margin-left:12px;color:#646970;"></span>
		</p>
		<table class="widefat striped wpcc-seo-sg-table">
			<thead>
				<tr>
					<th style="width:26%;"><?php esc_html_e( 'Content', 'wp-command-center' ); ?></th>
					<th><?php esc_html_e( 'Applied SEO meta', 'wp-command-center' ); ?></th>
					<th style="width:160px;"><?php esc_html_e( 'Status', 'wp-command-center' ); ?></th>
				</tr>
			</thead>
			<tbody id="wpcc-seo-ap-rows">
				<tr><td colspan="3"><?php esc_html_e( 'Loading…', 'wp-command-center' ); ?></td></tr>
			</tbody>
		</table>
	</div>
</div>

<style>
.wpcc-seo .wpcc-spin { float:none;margin:0 6px 0 0;vertical-align:middle; }
.wpcc-seo-readiness { display:flex;gap:24px;flex-wrap:wrap;margin:16px 0;padding:16px;border:1px solid #c3c4c7;background:#fff;border-radius:4px; }
.wpcc-seo-readiness .stat b { display:block;font-size:22px;line-height:1.2; }
.wpcc-seo-readiness .stat span { font-size:12px;color:#50575e; }
.wpcc-seo-filters { display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:12px 0; }
.wpcc-seo-count { font-size:12px;color:#646970; }
.wpcc-seo-table { max-width:1100px;margin-top:4px; }
.wpcc-seo-table td,.wpcc-seo-table th { vertical-align:middle; }
.wpcc-seo-meta { color:#50575e;font-size:12px; }
.wpcc-seo-none { color:#646970; }
.wpcc-badge { display:inline-block;font-size:11px;border-radius:10px;padding:1px 8px; }
.wpcc-badge--good { background:#edfaef;border:1px solid #00a32a;color:#0a7c2f; }
.wpcc-badge--warn { background:#fcf3e6;border:1px solid #dba617;color:#996800; }
.wpcc-badge--bad  { background:#fce9e9;border:1px solid #d63638;color:#b32d2e; }
.wpcc-empty { background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:18px;max-width:1100px;color:#50575e; }
.wpcc-seo-pager { display:flex;align-items:center;gap:10px;margin:12px 0;max-width:1100px; }
.wpcc-seo-pager .wpcc-pageinfo { font-size:12px;color:#646970; }
.wpcc-seo-sg-table td,.wpcc-seo-sg-table th { vertical-align:top; }
.wpcc-seo-sg-table input.wpcc-seo-et,.wpcc-seo-sg-table textarea.wpcc-seo-ed { width:100%; }
.wpcc-seo-cc { font-size:11px;color:#646970;margin:2px 0 8px; }
.wpcc-seo-cc.out { color:#b32d2e; }
.wpcc-seo-prov { font-size:11px;color:#646970; }
.wpcc-seo-edited { display:inline-block;font-size:11px;border-radius:8px;padding:1px 6px;background:#cce5d6;color:#1a4731;margin-left:6px; }
.wpcc-seo-rowmsg { font-size:12px;color:#646970;margin-top:4px; }
</style>

<script>
( function () {
	const API   = <?php echo wp_json_encode( $api_base ); ?>;
	const CORE  = <?php echo wp_json_encode( $core_base ); ?>;
	const EDIT  = <?php echo wp_json_encode( $edit_base ); ?>;
	const NONCE = <?php echo wp_json_encode( $nonce ); ?>;
	const MODE  = <?php echo wp_json_encode( $security_mode ); ?>; // developer | client | enterprise
	const IS_DEV = ( MODE === 'developer' );
	const LIMIT = 20;
	const TITLE_MAX = 60, DESC_MIN = 120, DESC_MAX = 160;
	const STR = {
		loading:  <?php echo wp_json_encode( esc_html__( 'Loading…', 'wp-command-center' ) ); ?>,
		error:    <?php echo wp_json_encode( esc_html__( 'Could not load. Please retry.', 'wp-command-center' ) ); ?>,
		empty:    <?php echo wp_json_encode( esc_html__( 'No content in this view. 🎉', 'wp-command-center' ) ); ?>,
		noPlugin: <?php echo wp_json_encode( esc_html__( 'No supported SEO plugin (Rank Math or Yoast SEO) is active. Activate one to audit SEO meta.', 'wp-command-center' ) ); ?>,
		none:     <?php echo wp_json_encode( esc_html__( '(not set)', 'wp-command-center' ) ); ?>,
		missing:  <?php echo wp_json_encode( esc_html__( 'Missing', 'wp-command-center' ) ); ?>,
		weak:     <?php echo wp_json_encode( esc_html__( 'Weak', 'wp-command-center' ) ); ?>,
		ok:       <?php echo wp_json_encode( esc_html__( 'OK', 'wp-command-center' ) ); ?>,
		colPost:  <?php echo wp_json_encode( esc_html__( 'Content', 'wp-command-center' ) ); ?>,
		colTitle: <?php echo wp_json_encode( esc_html__( 'SEO title', 'wp-command-center' ) ); ?>,
		colDesc:  <?php echo wp_json_encode( esc_html__( 'Meta description', 'wp-command-center' ) ); ?>,
		colState: <?php echo wp_json_encode( esc_html__( 'State', 'wp-command-center' ) ); ?>,
		colScore: <?php echo wp_json_encode( esc_html__( 'Score', 'wp-command-center' ) ); ?>,
		edit:     <?php echo wp_json_encode( esc_html__( 'Edit', 'wp-command-center' ) ); ?>,
		optimized:<?php echo wp_json_encode( esc_html__( 'Optimized', 'wp-command-center' ) ); ?>,
		missingN: <?php echo wp_json_encode( esc_html__( 'Missing meta', 'wp-command-center' ) ); ?>,
		weakN:    <?php echo wp_json_encode( esc_html__( 'Weak meta', 'wp-command-center' ) ); ?>,
		totalN:   <?php echo wp_json_encode( esc_html__( 'Total content', 'wp-command-center' ) ); ?>,
		prev:     <?php echo wp_json_encode( esc_html__( '← Previous', 'wp-command-center' ) ); ?>,
		next:     <?php echo wp_json_encode( esc_html__( 'Next →', 'wp-command-center' ) ); ?>,
		/* translators: %1$d first row, %2$d last row, %3$d total */
		pageInfo: <?php echo wp_json_encode( __( 'Showing %1$d–%2$d of %3$d', 'wp-command-center' ) ); ?>,
		// GA#2 Slice 2b — generation (drafts only).
		colSel:    <?php echo wp_json_encode( esc_html__( 'Select', 'wp-command-center' ) ); ?>,
		gen:       <?php echo wp_json_encode( esc_html__( 'Generate suggestions', 'wp-command-center' ) ); ?>,
		generating:<?php echo wp_json_encode( esc_html__( 'Generating…', 'wp-command-center' ) ); ?>,
		/* translators: %1$d created, %2$d skipped, %3$d failed */
		genDone:   <?php echo wp_json_encode( __( '%1$d drafts created, %2$d skipped, %3$d failed.', 'wp-command-center' ) ); ?>,
		genCap:    <?php echo wp_json_encode( esc_html__( 'Up to 25 at a time; only the first 25 are used.', 'wp-command-center' ) ); ?>,
		genErr:    <?php echo wp_json_encode( esc_html__( 'Generation failed. Please retry.', 'wp-command-center' ) ); ?>,
		// Slice 3 — Suggestions tab.
		noSug:     <?php echo wp_json_encode( esc_html__( 'No suggestions yet. Generate some from the Review tab.', 'wp-command-center' ) ); ?>,
		sgCurTitle: <?php echo wp_json_encode( esc_html__( 'Title', 'wp-command-center' ) ); ?>,
		sgCurDesc:  <?php echo wp_json_encode( esc_html__( 'Description', 'wp-command-center' ) ); ?>,
		byAI:      <?php echo wp_json_encode( esc_html__( 'Suggested by AI', 'wp-command-center' ) ); ?>,
		edited:    <?php echo wp_json_encode( esc_html__( 'Edited', 'wp-command-center' ) ); ?>,
		save:      <?php echo wp_json_encode( esc_html__( 'Save', 'wp-command-center' ) ); ?>,
		saved:     <?php echo wp_json_encode( esc_html__( 'Saved', 'wp-command-center' ) ); ?>,
		dismiss:   <?php echo wp_json_encode( esc_html__( 'Dismiss', 'wp-command-center' ) ); ?>,
		edit:      <?php echo wp_json_encode( esc_html__( 'Edit', 'wp-command-center' ) ); ?>,
		none:      <?php echo wp_json_encode( esc_html__( '(not set)', 'wp-command-center' ) ); ?>,
		/* translators: %1$d current length, %2$d max */
		ccTitle:   <?php echo wp_json_encode( __( '%1$d / %2$d', 'wp-command-center' ) ); ?>,
		/* translators: %1$d current length, %2$d min, %3$d max */
		ccDesc:    <?php echo wp_json_encode( __( '%1$d (target %2$d–%3$d)', 'wp-command-center' ) ); ?>,
		// Slice 4a — apply + Applied tab.
		applyDev:  <?php echo wp_json_encode( esc_html__( 'Approve & Apply', 'wp-command-center' ) ); ?>,
		applyGate: <?php echo wp_json_encode( esc_html__( 'Submit for approval', 'wp-command-center' ) ); ?>,
		cantApply: <?php echo wp_json_encode( esc_html__( 'Couldn’t apply', 'wp-command-center' ) ); ?>,
		stApplied: <?php echo wp_json_encode( esc_html__( 'Applied', 'wp-command-center' ) ); ?>,
		stAwaiting:<?php echo wp_json_encode( esc_html__( 'Awaiting approval', 'wp-command-center' ) ); ?>,
		stFailed:  <?php echo wp_json_encode( esc_html__( 'Failed', 'wp-command-center' ) ); ?>,
		stReverted:<?php echo wp_json_encode( esc_html__( 'Reverted', 'wp-command-center' ) ); ?>,
		colStatus2:<?php echo wp_json_encode( esc_html__( 'Status', 'wp-command-center' ) ); ?>,
		noApplied: <?php echo wp_json_encode( esc_html__( 'Nothing applied yet.', 'wp-command-center' ) ); ?>
	};
	const MAX_BATCH = 25;

	const $ = ( id ) => document.getElementById( id );
	const esc = ( s ) => String( s == null ? '' : s ).replace( /[&<>"']/g, ( c ) => ( { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[ c ] ) );
	const pg = { limit: LIMIT, offset: 0, total: 0, returned: 0, hasMore: false };
	let genBusy = false;

	function api( path, opts ) {
		opts = opts || {};
		opts.headers = Object.assign( { 'X-WP-Nonce': NONCE }, opts.headers || {} );
		return fetch( API + path, opts )
			.then( ( r ) => r.json().then( ( d ) => ( { ok: r.ok, data: d } ), () => ( { ok: r.ok, data: {} } ) ) );
	}
	function setHtml( id, html ) { const el = $( id ); if ( el ) { el.innerHTML = html; } }

	function stateBadge( st ) {
		const map = { missing: [ 'wpcc-badge--bad', STR.missing ], weak: [ 'wpcc-badge--warn', STR.weak ], ok: [ 'wpcc-badge--good', STR.ok ] };
		const m = map[ st ] || [ 'wpcc-badge--warn', esc( st ) ];
		return '<span class="wpcc-badge ' + m[0] + '">' + esc( m[1] ) + '</span>';
	}
	function metaCell( v ) { return v ? esc( v ) : '<em class="wpcc-seo-none">' + esc( STR.none ) + '</em>'; }

	function renderReadiness( s ) {
		if ( ! s ) { setHtml( 'wpcc-seo-readiness', '' ); return; }
		const stat = ( n, label ) => '<div class="stat"><b>' + esc( n ) + '</b><span>' + esc( label ) + '</span></div>';
		setHtml( 'wpcc-seo-readiness',
			stat( ( s.optimized_pct != null ? s.optimized_pct : 0 ) + '%', STR.optimized ) +
			stat( s.missing != null ? s.missing : 0, STR.missingN ) +
			stat( s.weak != null ? s.weak : 0, STR.weakN ) +
			stat( s.total_content != null ? s.total_content : 0, STR.totalN )
		);
	}

	function renderTable( items ) {
		if ( ! items.length ) { setHtml( 'wpcc-seo-panel', '<div class="wpcc-empty">' + esc( STR.empty ) + '</div>' ); return; }
		let h = '<table class="widefat striped wpcc-seo-table"><thead><tr>' +
			'<th scope="col" style="width:28px;"><span class="screen-reader-text">' + esc( STR.colSel ) + '</span></th>' +
			'<th scope="col">' + esc( STR.colPost ) + '</th>' +
			'<th scope="col">' + esc( STR.colTitle ) + '</th>' +
			'<th scope="col">' + esc( STR.colDesc ) + '</th>' +
			'<th scope="col" style="width:90px;">' + esc( STR.colState ) + '</th>' +
			'<th scope="col" style="width:70px;">' + esc( STR.colScore ) + '</th>' +
			'</tr></thead><tbody>';
		items.forEach( ( it ) => {
			const titleCell = it.edit_link
				? '<a href="' + esc( it.edit_link ) + '">' + esc( it.title || ( '#' + it.post_id ) ) + '</a>'
				: esc( it.title || ( '#' + it.post_id ) );
			h += '<tr>' +
				'<td><input type="checkbox" class="wpcc-seo-cb" value="' + esc( it.post_id ) + '" aria-label="' + esc( STR.gen ) + '"></td>' +
				'<th scope="row"><strong>' + titleCell + '</strong><div class="wpcc-seo-meta">' + esc( it.post_type || '' ) + '</div></th>' +
				'<td class="wpcc-seo-meta">' + metaCell( it.seo_title ) + '</td>' +
				'<td class="wpcc-seo-meta">' + metaCell( it.seo_description ) + '</td>' +
				'<td>' + stateBadge( it.state ) + '</td>' +
				'<td>' + esc( ( it.score != null ? it.score : 0 ) + '' ) + '</td>' +
				'</tr>';
		} );
		h += '</tbody></table>';
		setHtml( 'wpcc-seo-panel', h );
		if ( $( 'wpcc-seo-selectall' ) ) { $( 'wpcc-seo-selectall' ).checked = false; }
		refreshGenerate();
	}

	// ---------- GA#2 Slice 2b — generation (governed DRAFTS only) ----------
	function selectedIds() {
		return Array.prototype.slice.call( document.querySelectorAll( '.wpcc-seo-cb:checked' ) ).map( ( c ) => parseInt( c.value, 10 ) ).filter( ( n ) => n > 0 );
	}
	function refreshGenerate() {
		const b = $( 'wpcc-seo-generate' ); if ( ! b ) { return; }
		const n = selectedIds().length;
		b.disabled = ( n === 0 ) || genBusy;
		b.textContent = n > 0 ? STR.gen + ' (' + n + ')' : STR.gen;
		const s = $( 'wpcc-seo-gen-status' ); if ( s && n > MAX_BATCH ) { s.textContent = STR.genCap; }
	}
	function generate() {
		if ( genBusy ) { return; }
		let ids = selectedIds();
		if ( ! ids.length ) { return; }
		if ( ids.length > MAX_BATCH ) { ids = ids.slice( 0, MAX_BATCH ); }
		genBusy = true; refreshGenerate();
		const status = $( 'wpcc-seo-gen-status' ); if ( status ) { status.textContent = STR.generating; }
		api( '/seo/generate', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( { post_ids: ids } ) } )
			.then( ( res ) => {
				genBusy = false;
				const d = res.data || {};
				if ( ! res.ok ) { if ( status ) { status.textContent = STR.genErr; } refreshGenerate(); return; }
				const c = ( d.created || [] ).length, sk = ( d.skipped || [] ).length, f = ( d.failed || [] ).length;
				if ( status ) { status.textContent = STR.genDone.replace( '%1$d', c ).replace( '%2$d', sk ).replace( '%3$d', f ); }
				pg.offset = 0; load(); // reflect items that now have an open proposal
			} )
			.catch( () => { genBusy = false; if ( status ) { status.textContent = STR.genErr; } refreshGenerate(); } );
	}

	function renderPager() {
		if ( pg.total <= pg.limit && pg.offset === 0 ) { setHtml( 'wpcc-seo-pager', '' ); return; }
		const from = pg.total ? ( pg.offset + 1 ) : 0;
		const to   = pg.offset + pg.returned;
		const info = STR.pageInfo.replace( '%1$d', from ).replace( '%2$d', to ).replace( '%3$d', pg.total );
		const prevDis = pg.offset <= 0 ? ' disabled' : '';
		const nextDis = pg.hasMore ? '' : ' disabled';
		setHtml( 'wpcc-seo-pager',
			'<button type="button" class="button" id="wpcc-seo-prev"' + prevDis + '>' + esc( STR.prev ) + '</button>' +
			'<span class="wpcc-pageinfo">' + esc( info ) + '</span>' +
			'<button type="button" class="button" id="wpcc-seo-next"' + nextDis + '>' + esc( STR.next ) + '</button>' );
		const p = $( 'wpcc-seo-prev' ), n = $( 'wpcc-seo-next' );
		if ( p ) { p.addEventListener( 'click', () => { if ( pg.offset > 0 ) { pg.offset = Math.max( 0, pg.offset - pg.limit ); load(); } } ); }
		if ( n ) { n.addEventListener( 'click', () => { if ( pg.hasMore ) { pg.offset += pg.limit; load(); } } ); }
	}

	function load() {
		const filter = $( 'wpcc-seo-filter' ) ? $( 'wpcc-seo-filter' ).value : 'missing';
		setHtml( 'wpcc-seo-panel', '<p>' + esc( STR.loading ) + '</p>' );
		api( '/seo/audit?state=' + encodeURIComponent( filter ) + '&limit=' + pg.limit + '&offset=' + pg.offset )
			.then( ( res ) => {
				const d = res.data || {};
				if ( ! res.ok ) { setHtml( 'wpcc-seo-panel', '<div class="wpcc-empty">' + esc( STR.error ) + '</div>' ); return; }
				renderReadiness( d.summary );
				// No supported SEO plugin → empty-state, hide all controls.
				if ( ! d.provider_available ) {
					$( 'wpcc-seo-controls' ).style.display = 'none';
					setHtml( 'wpcc-seo-pager', '' );
					setHtml( 'wpcc-seo-panel', '<div class="wpcc-empty">' + esc( STR.noPlugin ) + '</div>' );
					return;
				}
				$( 'wpcc-seo-controls' ).style.display = 'flex';
				pg.total = d.total_count || 0; pg.returned = d.returned || ( d.items ? d.items.length : 0 ); pg.hasMore = !! d.has_more;
				renderTable( Array.isArray( d.items ) ? d.items : [] );
				setHtml( 'wpcc-seo-count', '' );
				renderPager();
			} )
			.catch( () => { setHtml( 'wpcc-seo-panel', '<div class="wpcc-empty">' + esc( STR.error ) + '</div>' ); } );
	}

	// ---------- SUGGESTIONS TAB (Slice 3): review / edit / dismiss governed DRAFTS ----------
	// Reuses ONLY the existing proposal routes; post title/type enriched via WP core
	// REST (client-side). NO apply / approve / undo / rollback / bulk / selection.
	let sgOffset = 0, sgHasMore = false, sgReturned = 0, sgTotal = 0;

	function coreGet( path ) {
		return fetch( CORE + path, { headers: { 'X-WP-Nonce': NONCE } } )
			.then( ( r ) => r.json().then( ( d ) => ( { ok: r.ok, data: d } ), () => ( { ok: r.ok, data: [] } ) ) );
	}
	function suggested( p ) {
		const fp = ( p.final_payload && p.final_payload.seo ) ? p.final_payload.seo : null;
		const pl = ( p.payload && p.payload.seo ) ? p.payload.seo : {};
		return {
			title: ( fp && fp.title != null ) ? fp.title : ( pl.title || '' ),
			description: ( fp && fp.description != null ) ? fp.description : ( pl.description || '' )
		};
	}
	function isEdited( p ) {
		if ( ! p.final_payload || ! p.final_payload.seo ) { return false; }
		const pl = ( p.payload && p.payload.seo ) || {};
		const fp = p.final_payload.seo;
		return ( fp.title != null && fp.title !== ( pl.title || '' ) ) || ( fp.description != null && fp.description !== ( pl.description || '' ) );
	}
	function updateCounts( row ) {
		const t = row.querySelector( '.wpcc-seo-et' ), d = row.querySelector( '.wpcc-seo-ed' );
		const ct = row.querySelector( '.wpcc-seo-cc-t' ), cd = row.querySelector( '.wpcc-seo-cc-d' );
		if ( t && ct ) { const n = ( t.value || '' ).length; ct.textContent = STR.ccTitle.replace( '%1$d', n ).replace( '%2$d', TITLE_MAX ); ct.classList.toggle( 'out', n > TITLE_MAX ); }
		if ( d && cd ) { const n = ( d.value || '' ).length; cd.textContent = STR.ccDesc.replace( '%1$d', n ).replace( '%2$d', DESC_MIN ).replace( '%3$d', DESC_MAX ); cd.classList.toggle( 'out', n < DESC_MIN || n > DESC_MAX ); }
	}
	function renderSuggestions( list, ctx ) {
		setHtml( 'wpcc-seo-sg-rows', list.map( ( p ) => {
			const tid = parseInt( p.target_id, 10 );
			const c = ctx[ tid ] || {};
			const title = c.title || ( '#' + tid );
			const editLink = EDIT + '?post=' + encodeURIComponent( tid ) + '&action=edit';
			const cur = ( p.prior || {} );
			const sg = suggested( p );
			const editedChip = isEdited( p ) ? '<span class="wpcc-seo-edited">' + esc( STR.edited ) + '</span>' : '';
			const prov = p.provider ? '<div class="wpcc-seo-prov">' + esc( STR.byAI ) + ( p.model ? ' · ' + esc( p.model ) : '' ) + editedChip + '</div>' : ( editedChip ? '<div>' + editedChip + '</div>' : '' );
			const curT = cur.title ? esc( cur.title ) : '<em class="wpcc-seo-none">' + esc( STR.none ) + '</em>';
			const curD = cur.description ? esc( cur.description ) : '<em class="wpcc-seo-none">' + esc( STR.none ) + '</em>';
			// proposal_id is an OPAQUE DOM key only (edit/dismiss); never displayed.
			return '<tr data-id="' + esc( p.proposal_id ) + '" data-tid="' + esc( tid ) + '">' +
				'<td><strong><a href="' + esc( editLink ) + '">' + esc( title ) + '</a></strong><div class="wpcc-seo-meta">' + esc( c.type || '' ) + '</div></td>' +
				'<td class="wpcc-seo-meta"><div><strong>' + esc( STR.sgCurTitle ) + ':</strong> ' + curT + '</div><div style="margin-top:6px;"><strong>' + esc( STR.sgCurDesc ) + ':</strong> ' + curD + '</div></td>' +
				'<td>' + prov +
					'<label class="screen-reader-text">' + esc( STR.sgCurTitle ) + '</label>' +
					'<input type="text" class="wpcc-seo-et" value="' + esc( sg.title ) + '">' +
					'<div class="wpcc-seo-cc wpcc-seo-cc-t"></div>' +
					'<label class="screen-reader-text">' + esc( STR.sgCurDesc ) + '</label>' +
					'<textarea class="wpcc-seo-ed" rows="3">' + esc( sg.description ) + '</textarea>' +
					'<div class="wpcc-seo-cc wpcc-seo-cc-d"></div>' +
				'</td>' +
				'<td><button type="button" class="button button-primary button-small wpcc-seo-apply">' + esc( IS_DEV ? STR.applyDev : STR.applyGate ) + '</button> ' +
					'<button type="button" class="button button-small wpcc-seo-save">' + esc( STR.save ) + '</button> ' +
					'<button type="button" class="button button-small wpcc-seo-dismiss">' + esc( STR.dismiss ) + '</button>' +
					'<div class="wpcc-seo-rowmsg" role="status"></div></td>' +
				'</tr>';
		} ).join( '' ) );
		document.querySelectorAll( '#wpcc-seo-sg-rows tr[data-id]' ).forEach( updateCounts );
	}
	function renderSgPager() {
		if ( sgTotal <= LIMIT && sgOffset === 0 ) { setHtml( 'wpcc-seo-sg-pager', '' ); return; }
		const prevDis = sgOffset <= 0 ? ' disabled' : ''; const nextDis = sgHasMore ? '' : ' disabled';
		setHtml( 'wpcc-seo-sg-pager',
			'<button type="button" class="button" id="wpcc-seo-sg-prev"' + prevDis + '>' + esc( STR.prev ) + '</button>' +
			'<button type="button" class="button" id="wpcc-seo-sg-next"' + nextDis + '>' + esc( STR.next ) + '</button>' );
		const p = $( 'wpcc-seo-sg-prev' ), n = $( 'wpcc-seo-sg-next' );
		if ( p ) { p.addEventListener( 'click', () => { if ( sgOffset > 0 ) { sgOffset = Math.max( 0, sgOffset - LIMIT ); loadSuggestions(); } } ); }
		if ( n ) { n.addEventListener( 'click', () => { if ( sgHasMore ) { sgOffset += LIMIT; loadSuggestions(); } } ); }
	}
	function loadSuggestions() {
		setHtml( 'wpcc-seo-sg-rows', '<tr><td colspan="4">' + esc( STR.loading ) + '</td></tr>' );
		api( '/proposals?status=draft&operation_id=seo_manage&limit=' + LIMIT + '&offset=' + sgOffset )
			.then( ( res ) => {
				if ( ! res.ok ) { setHtml( 'wpcc-seo-sg-rows', '<tr><td colspan="4" style="color:#b32d2e;">' + esc( STR.error ) + '</td></tr>' ); return; }
				const d = res.data || {}, list = d.proposals || [];
				sgTotal = d.total_count || 0; sgReturned = d.returned || list.length; sgHasMore = !! d.has_more;
				if ( ! list.length ) { setHtml( 'wpcc-seo-sg-rows', '<tr><td colspan="4">' + esc( STR.noSug ) + '</td></tr>' ); setHtml( 'wpcc-seo-sg-pager', '' ); $( 'wpcc-seo-sg-status' ).textContent = ''; return; }
				const ids = list.map( ( p ) => parseInt( p.target_id, 10 ) ).filter( ( n ) => n > 0 );
				const csv = ids.join( ',' );
				Promise.all( [
					coreGet( '/posts?include=' + csv + '&per_page=' + ids.length + '&_fields=id,title,type' ),
					coreGet( '/pages?include=' + csv + '&per_page=' + ids.length + '&_fields=id,title,type' )
				] ).then( ( r ) => {
					const ctx = {};
					[ r[0].data, r[1].data ].forEach( ( arr ) => { ( Array.isArray( arr ) ? arr : [] ).forEach( ( m ) => { ctx[ m.id ] = { title: ( m.title && m.title.rendered ) || '', type: m.type || '' }; } ); } );
					renderSuggestions( list, ctx );
					$( 'wpcc-seo-sg-status' ).textContent = ( sgOffset + 1 ) + '–' + ( sgOffset + sgReturned ) + ' of ' + sgTotal;
					renderSgPager();
				} ).catch( () => { renderSuggestions( list, {} ); renderSgPager(); } );
			} )
			.catch( () => { setHtml( 'wpcc-seo-sg-rows', '<tr><td colspan="4" style="color:#b32d2e;">' + esc( STR.error ) + '</td></tr>' ); } );
	}

	// Live char counts.
	document.addEventListener( 'input', function ( e ) {
		const row = e.target.closest ? e.target.closest( '#wpcc-seo-sg-rows tr[data-id]' ) : null;
		if ( row && ( e.target.classList.contains( 'wpcc-seo-et' ) || e.target.classList.contains( 'wpcc-seo-ed' ) ) ) { updateCounts( row ); }
	} );
	// Save (edit final_payload, draft stays draft) + Dismiss — delegated.
	document.addEventListener( 'click', function ( e ) {
		const t = e.target; const row = t.closest ? t.closest( '#wpcc-seo-sg-rows tr[data-id]' ) : null;
		if ( ! row ) { return; }
		const id = row.getAttribute( 'data-id' ), tid = parseInt( row.getAttribute( 'data-tid' ) || '0', 10 );
		const msg = row.querySelector( '.wpcc-seo-rowmsg' );
		if ( t.classList.contains( 'wpcc-seo-save' ) ) {
			const final_payload = { action: 'seo_update', content_id: tid, seo: { title: row.querySelector( '.wpcc-seo-et' ).value, description: row.querySelector( '.wpcc-seo-ed' ).value } };
			if ( msg ) { msg.textContent = '…'; }
			api( '/proposals/' + encodeURIComponent( id ), { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( { final_payload: final_payload } ) } )
				.then( ( res ) => { if ( msg ) { msg.textContent = res.ok ? STR.saved : ( ( res.data && res.data.message ) || STR.error ); } } )
				.catch( () => { if ( msg ) { msg.textContent = STR.error; } } );
		} else if ( t.classList.contains( 'wpcc-seo-dismiss' ) ) {
			api( '/proposals/' + encodeURIComponent( id ) + '/dismiss', { method: 'POST' } )
				.then( ( res ) => { if ( res.ok ) { row.parentNode.removeChild( row ); } else if ( msg ) { msg.textContent = ( res.data && res.data.message ) || STR.error; } } )
				.catch( () => { if ( msg ) { msg.textContent = STR.error; } } );
		} else if ( t.classList.contains( 'wpcc-seo-apply' ) ) {
			// Approve & Apply (developer) / Submit for approval (client/enterprise).
			// Reuses the EXISTING proposal apply route; outcome is driven by the API
			// response (applied | pending_approval), never assumed from the label.
			t.disabled = true;
			if ( msg ) { msg.textContent = '…'; }
			api( '/proposals/' + encodeURIComponent( id ) + '/apply', { method: 'POST' } )
				.then( ( res ) => {
					const st = ( res.data && res.data.status ) || '';
					if ( st === 'applied' || st === 'pending_approval' ) {
						if ( row.parentNode ) { row.parentNode.removeChild( row ); } // moves to Applied tab
					} else {
						t.disabled = false;
						if ( msg ) { msg.textContent = STR.cantApply; }
					}
				} )
				.catch( () => { t.disabled = false; if ( msg ) { msg.textContent = STR.cantApply; } } );
		}
	} );

	// ---------- APPLIED TAB (Slice 4a): read-only status (applied / awaiting / failed) ----------
	function apBadge( color, label ) {
		return '<span style="display:inline-block;padding:2px 8px;border-radius:10px;color:#fff;font-size:12px;background:' + color + ';">' + esc( label ) + '</span>';
	}
	function appliedMeta( p ) {
		const fp = ( p.final_payload && p.final_payload.seo ) ? p.final_payload.seo : null;
		const pl = ( p.payload && p.payload.seo ) ? p.payload.seo : {};
		const tt = ( fp && fp.title != null ) ? fp.title : ( pl.title || '' );
		const dd = ( fp && fp.description != null ) ? fp.description : ( pl.description || '' );
		return '<div><strong>' + esc( STR.sgCurTitle ) + ':</strong> ' + ( tt ? esc( tt ) : '<em class="wpcc-seo-none">' + esc( STR.none ) + '</em>' ) + '</div>' +
			'<div style="margin-top:6px;"><strong>' + esc( STR.sgCurDesc ) + ':</strong> ' + ( dd ? esc( dd ) : '<em class="wpcc-seo-none">' + esc( STR.none ) + '</em>' ) + '</div>';
	}
	function renderApplied( list, ctx ) {
		if ( ! list.length ) { setHtml( 'wpcc-seo-ap-rows', '<tr><td colspan="3">' + esc( STR.noApplied ) + '</td></tr>' ); return; }
		setHtml( 'wpcc-seo-ap-rows', list.map( ( p ) => {
			const tid = parseInt( p.target_id, 10 );
			const c = ctx[ tid ] || {};
			const title = c.title || ( '#' + tid );
			const editLink = EDIT + '?post=' + encodeURIComponent( tid ) + '&action=edit';
			let badge;
			if ( p.status === 'pending_approval' ) { badge = apBadge( '#bd8600', STR.stAwaiting ); }
			else if ( p.status === 'failed' ) { badge = apBadge( '#b32d2e', STR.stFailed ); }
			else if ( p.status === 'applied' && p.change_status === 'rolled_back' ) { badge = apBadge( '#646970', STR.stReverted ); }
			else { badge = apBadge( '#1a7f37', STR.stApplied ); } // applied + reversible (Undo arrives in Slice 4b)
			return '<tr>' +
				'<td><strong><a href="' + esc( editLink ) + '">' + esc( title ) + '</a></strong><div class="wpcc-seo-meta">' + esc( c.type || '' ) + '</div></td>' +
				'<td class="wpcc-seo-meta">' + appliedMeta( p ) + '</td>' +
				'<td>' + badge + '</td>' +
				'</tr>';
		} ).join( '' ) );
	}
	function loadApplied() {
		setHtml( 'wpcc-seo-ap-rows', '<tr><td colspan="3">' + esc( STR.loading ) + '</td></tr>' );
		// Reuse the EXISTING proposal list route: applied + pending_approval + failed
		// for seo_manage. ProposalAdminQuery already enriches applied rows with
		// change_status (rollback-aware). No new route, no new query.
		Promise.all( [
			api( '/proposals?status=applied&operation_id=seo_manage&limit=50' ),
			api( '/proposals?status=pending_approval&operation_id=seo_manage&limit=50' ),
			api( '/proposals?status=failed&operation_id=seo_manage&limit=50' )
		] ).then( ( results ) => {
			const applied = ( results[0].data && results[0].data.proposals ) || [];
			const pending = ( results[1].data && results[1].data.proposals ) || [];
			const failed  = ( results[2].data && results[2].data.proposals ) || [];
			const list = pending.concat( applied, failed );
			$( 'wpcc-seo-ap-status' ).textContent = '';
			if ( ! list.length ) { setHtml( 'wpcc-seo-ap-rows', '<tr><td colspan="3">' + esc( STR.noApplied ) + '</td></tr>' ); return; }
			const ids = list.map( ( p ) => parseInt( p.target_id, 10 ) ).filter( ( n ) => n > 0 );
			const csv = ids.join( ',' );
			Promise.all( [
				coreGet( '/posts?include=' + csv + '&per_page=' + ids.length + '&_fields=id,title,type' ),
				coreGet( '/pages?include=' + csv + '&per_page=' + ids.length + '&_fields=id,title,type' )
			] ).then( ( r ) => {
				const ctx = {};
				[ r[0].data, r[1].data ].forEach( ( arr ) => { ( Array.isArray( arr ) ? arr : [] ).forEach( ( m ) => { ctx[ m.id ] = { title: ( m.title && m.title.rendered ) || '', type: m.type || '' }; } ); } );
				renderApplied( list, ctx );
			} ).catch( () => renderApplied( list, {} ) );
		} ).catch( () => { setHtml( 'wpcc-seo-ap-rows', '<tr><td colspan="3" style="color:#b32d2e;">' + esc( STR.error ) + '</td></tr>' ); } );
	}

	function switchTab( which ) {
		$( 'wpcc-seo-panel-review' ).style.display = ( which === 'review' ) ? '' : 'none';
		$( 'wpcc-seo-panel-suggestions' ).style.display = ( which === 'suggestions' ) ? '' : 'none';
		$( 'wpcc-seo-panel-applied' ).style.display = ( which === 'applied' ) ? '' : 'none';
		$( 'wpcc-seo-tab-review' ).classList.toggle( 'nav-tab-active', which === 'review' );
		$( 'wpcc-seo-tab-suggestions' ).classList.toggle( 'nav-tab-active', which === 'suggestions' );
		$( 'wpcc-seo-tab-applied' ).classList.toggle( 'nav-tab-active', which === 'applied' );
		if ( which === 'suggestions' ) { sgOffset = 0; loadSuggestions(); }
		else if ( which === 'applied' ) { loadApplied(); }
	}

	// ---------- wiring ----------
	$( 'wpcc-seo-tab-review' ).addEventListener( 'click', function ( e ) { e.preventDefault(); switchTab( 'review' ); } );
	$( 'wpcc-seo-tab-suggestions' ).addEventListener( 'click', function ( e ) { e.preventDefault(); switchTab( 'suggestions' ); } );
	$( 'wpcc-seo-tab-applied' ).addEventListener( 'click', function ( e ) { e.preventDefault(); switchTab( 'applied' ); } );
	if ( $( 'wpcc-seo-filter' ) ) { $( 'wpcc-seo-filter' ).addEventListener( 'change', function () { pg.offset = 0; load(); } ); }
	if ( $( 'wpcc-seo-generate' ) ) { $( 'wpcc-seo-generate' ).addEventListener( 'click', generate ); }
	if ( $( 'wpcc-seo-selectall' ) ) {
		$( 'wpcc-seo-selectall' ).addEventListener( 'change', function () {
			const on = this.checked;
			document.querySelectorAll( '.wpcc-seo-cb' ).forEach( ( c ) => { c.checked = on; } );
			refreshGenerate();
		} );
	}
	document.addEventListener( 'change', function ( e ) { if ( e.target.classList && e.target.classList.contains( 'wpcc-seo-cb' ) ) { refreshGenerate(); } } );
	load();
} )();
</script>
