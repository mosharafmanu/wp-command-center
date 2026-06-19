<?php
/**
 * STEP 111 — Governed Action #2 (SEO Meta Generator), Slice 1: read-only Builder.
 *
 * A THIN REST CLIENT over a single read-only endpoint:
 *   - GET /wp-command-center/v1/admin/seo/audit   (missing/weak/ok meta audit)
 *
 * Slice 1 is READ-ONLY: it audits SEO meta (title/description) across public
 * content via the active SEO plugin (Rank Math / Yoast). It performs NO generation,
 * NO proposal creation, NO apply, NO undo — those are later slices. When no
 * supported SEO plugin is active it shows an empty-state and no controls. All API
 * output is escaped client-side via escHtml.
 */

defined( 'ABSPATH' ) || exit;

$nonce    = wp_create_nonce( 'wp_rest' );
$api_base = rest_url( 'wp-command-center/v1/admin' );
?>
<div class="wrap wpcc-wrap wpcc-seo">
	<h1><?php esc_html_e( 'SEO Meta', 'wp-command-center' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'Audit which posts and pages are missing or have weak SEO titles and meta descriptions. Read-only — this page does not change anything.', 'wp-command-center' ); ?>
	</p>

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
</style>

<script>
( function () {
	const API   = <?php echo wp_json_encode( $api_base ); ?>;
	const NONCE = <?php echo wp_json_encode( $nonce ); ?>;
	const LIMIT = 20;
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
		genErr:    <?php echo wp_json_encode( esc_html__( 'Generation failed. Please retry.', 'wp-command-center' ) ); ?>
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
