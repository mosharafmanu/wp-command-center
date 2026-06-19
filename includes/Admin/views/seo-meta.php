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
		pageInfo: <?php echo wp_json_encode( __( 'Showing %1$d–%2$d of %3$d', 'wp-command-center' ) ); ?>
	};

	const $ = ( id ) => document.getElementById( id );
	const esc = ( s ) => String( s == null ? '' : s ).replace( /[&<>"']/g, ( c ) => ( { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[ c ] ) );
	const pg = { limit: LIMIT, offset: 0, total: 0, returned: 0, hasMore: false };

	function api( path ) {
		return fetch( API + path, { headers: { 'X-WP-Nonce': NONCE } } )
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
				'<th scope="row"><strong>' + titleCell + '</strong><div class="wpcc-seo-meta">' + esc( it.post_type || '' ) + '</div></th>' +
				'<td class="wpcc-seo-meta">' + metaCell( it.seo_title ) + '</td>' +
				'<td class="wpcc-seo-meta">' + metaCell( it.seo_description ) + '</td>' +
				'<td>' + stateBadge( it.state ) + '</td>' +
				'<td>' + esc( ( it.score != null ? it.score : 0 ) + '' ) + '</td>' +
				'</tr>';
		} );
		h += '</tbody></table>';
		setHtml( 'wpcc-seo-panel', h );
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
	load();
} )();
</script>
