<?php
/**
 * AI Content (Title & Excerpt) Builder.
 *
 * A THIN REST CLIENT over existing, governed endpoints only — it introduces NO
 * backend, NO new REST route / operation / capability / MCP tool / schema, and
 * never writes a post / postmeta / option / change_log directly. It reuses ONLY:
 *   - GET  /wp-command-center/v1/admin/proposals               (list drafts/applied)
 *   - GET  /wp-command-center/v1/admin/proposals/{id}          (detail; not strictly used)
 *   - PUT  /wp-command-center/v1/admin/proposals/{id}          (edit final_payload)
 *   - POST /wp-command-center/v1/admin/proposals/{id}/apply    (governed apply)
 *   - POST /wp-command-center/v1/admin/proposals/{id}/dismiss  (discard draft)
 *   - POST /wp-command-center/v1/admin/history/{change_id}/rollback (governed Undo)
 *   - GET  /wp/v2/posts|pages?include=…  (WP core; post title/type/edit only)
 *
 * DATA MODEL — an AI Content draft is a proposal with operation_id='content_manage'
 * and target_type of either 'content_title' or 'content_excerpt'. Its payload (and
 * edited final_payload) is { action:'content_update', content_id:<postId>,
 * title|excerpt:<text> }; its prior is { title|excerpt:<currentValue> }. The field
 * name is 'title' for content_title drafts and 'excerpt' for content_excerpt drafts.
 *
 * Two tabs: Suggestions (review / edit / Save / Apply / Dismiss governed DRAFTS) and
 * Applied (segmented single-status status list with per-item Undo). Apply is
 * persist-before-apply: the visible (possibly unsaved) value is PUT as final_payload
 * BEFORE /apply, so a stale AI value is never applied. The apply button LABEL is
 * mode-aware (developer → Approve & Apply; client/enterprise → Submit for approval)
 * but the OUTCOME is always read from the apply response .status (applied |
 * pending_approval), never assumed from the label. proposal_id / change_id are opaque
 * DOM keys (edit/dismiss/undo) and are never displayed. All API output is escaped
 * client-side via esc().
 */

defined( 'ABSPATH' ) || exit;

$nonce     = wp_create_nonce( 'wp_rest' );
$api_base  = esc_url( rest_url( 'wp-command-center/v1/admin' ) );
$core_base = esc_url( rest_url( 'wp/v2' ) );
$edit_base = esc_url( admin_url( 'post.php' ) ); // client builds ?post=ID&action=edit (any post type)
$ai_url    = esc_url( admin_url( 'admin.php?page=wpcc-connect&wpcc_tab=clients' ) ); // connect an AI key
// Server-rendered security mode drives the apply button label (developer applies
// directly; client/enterprise submit for approval). The outcome is still taken from
// the apply API response (defensive) — the UI never assumes from the label.
$security_mode = \WPCommandCenter\Operations\SecurityModeManager::current();
?>
<div class="wrap wpcc-wrap wpcc-aic">
	<h1><?php esc_html_e( 'Content', 'wp-command-center' ); ?></h1>
	<p class="description" style="max-width:720px;">
		<?php esc_html_e( 'Draft titles and excerpts for your posts and pages with AI. Review and edit each suggestion, then approve to apply — you’re always in control.', 'wp-command-center' ); ?>
	</p>
	<?php require WPCC_PLUGIN_DIR . 'includes/Admin/views/partials/trust-strip.php'; ?>

	<h2 class="nav-tab-wrapper">
		<a href="#" class="nav-tab nav-tab-active" id="wpcc-aic-tab-suggestions"><?php esc_html_e( 'Suggestions', 'wp-command-center' ); ?><span class="wpcc-aic-tabcount" id="wpcc-aic-tabcount-suggestions"></span></a>
		<a href="#" class="nav-tab" id="wpcc-aic-tab-applied"><?php esc_html_e( 'Applied', 'wp-command-center' ); ?><span class="wpcc-aic-tabcount" id="wpcc-aic-tabcount-applied"></span></a>
	</h2>

	<?php // Result of a contextual "Generate" row/bulk action (set via the wpcc_content_gen
	// / wpcc_content_bulk query args on redirect; rendered client-side, escaped). ?>
	<div id="wpcc-aic-entry-notice" class="notice inline" role="status" aria-live="polite" style="display:none;margin:10px 0;"></div>

	<!-- ============ SUGGESTIONS TAB ============ -->
	<div id="wpcc-aic-panel-suggestions">
		<div class="wpcc-aic-filters">
			<label for="wpcc-aic-kind"><?php esc_html_e( 'Show:', 'wp-command-center' ); ?></label>
			<select id="wpcc-aic-kind">
				<option value="all"><?php esc_html_e( 'All', 'wp-command-center' ); ?></option>
				<option value="title"><?php esc_html_e( 'Titles', 'wp-command-center' ); ?></option>
				<option value="excerpt"><?php esc_html_e( 'Excerpts', 'wp-command-center' ); ?></option>
			</select>
			<span id="wpcc-aic-sg-status" role="status" aria-live="polite" style="margin-left:12px;color:#646970;"></span>
		</div>
		<p style="margin:12px 0;">
			<span class="description"><?php esc_html_e( 'AI suggestions awaiting your review. Edit the title or excerpt, dismiss a suggestion, or apply it. Nothing is applied to your site until you choose to.', 'wp-command-center' ); ?></span>
		</p>
		<table class="widefat striped wpcc-aic-sg-table">
			<thead>
				<tr>
					<th scope="col" style="width:22%;"><?php esc_html_e( 'Content', 'wp-command-center' ); ?></th>
					<th scope="col" style="width:14%;"><?php esc_html_e( 'Field', 'wp-command-center' ); ?></th>
					<th scope="col" style="width:26%;"><?php esc_html_e( 'Current', 'wp-command-center' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Suggested (editable)', 'wp-command-center' ); ?></th>
					<th scope="col" style="width:150px;"><?php esc_html_e( 'Actions', 'wp-command-center' ); ?></th>
				</tr>
			</thead>
			<tbody id="wpcc-aic-sg-rows">
				<tr><td colspan="5"><?php esc_html_e( 'Loading…', 'wp-command-center' ); ?></td></tr>
			</tbody>
		</table>
		<div id="wpcc-aic-sg-pager" class="wpcc-aic-pager"></div>
	</div>

	<!-- ============ APPLIED TAB ============ -->
	<div id="wpcc-aic-panel-applied" style="display:none;">
		<p style="margin:12px 0;">
			<span class="description"><?php esc_html_e( 'Applied titles and excerpts, plus items awaiting approval.', 'wp-command-center' ); ?></span>
			<span id="wpcc-aic-ap-status" role="status" aria-live="polite" style="margin-left:12px;color:#646970;"></span>
		</p>
		<?php // Segmented, single-status pagination — each segment is one paginated
		// /admin/proposals read; default = Applied. ?>
		<div class="wpcc-aic-segbar" id="wpcc-aic-ap-segbar" role="group" aria-label="<?php esc_attr_e( 'Filter applied items by status', 'wp-command-center' ); ?>">
			<button type="button" class="button button-primary wpcc-aic-seg" data-seg="applied"><?php esc_html_e( 'Applied', 'wp-command-center' ); ?></button>
			<button type="button" class="button wpcc-aic-seg" data-seg="pending_approval"><?php esc_html_e( 'Awaiting approval', 'wp-command-center' ); ?></button>
			<button type="button" class="button wpcc-aic-seg" data-seg="failed"><?php esc_html_e( 'Failed', 'wp-command-center' ); ?></button>
		</div>
		<table class="widefat striped wpcc-aic-sg-table">
			<thead>
				<tr>
					<th scope="col" style="width:26%;"><?php esc_html_e( 'Content', 'wp-command-center' ); ?></th>
					<th scope="col" style="width:14%;"><?php esc_html_e( 'Field', 'wp-command-center' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Applied value', 'wp-command-center' ); ?></th>
					<th scope="col" style="width:160px;"><?php esc_html_e( 'Status', 'wp-command-center' ); ?></th>
					<th scope="col" style="width:120px;"><?php esc_html_e( 'Actions', 'wp-command-center' ); ?></th>
				</tr>
			</thead>
			<tbody id="wpcc-aic-ap-rows">
				<tr><td colspan="5"><?php esc_html_e( 'Loading…', 'wp-command-center' ); ?></td></tr>
			</tbody>
		</table>
		<div id="wpcc-aic-ap-pager" class="wpcc-aic-pager"></div>
	</div>

	<?php // Post-apply confirmation toast (reversible · audited · Undo). ?>
	<div id="wpcc-aic-toast" class="wpcc-aic-toast" role="status" aria-live="polite" style="display:none;"></div>
</div>

<style>
.wpcc-aic-filters { display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:12px 0; }
.wpcc-aic-none { color:#646970; }
.wpcc-empty { background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:18px;max-width:1100px;color:#50575e; }
.wpcc-aic-meta { color:#50575e;font-size:12px; }
.wpcc-aic-pager { display:flex;align-items:center;gap:10px;margin:12px 0;max-width:1100px; }
.wpcc-aic-pager .wpcc-pageinfo { font-size:12px;color:#646970; }
.wpcc-aic-sg-table td,.wpcc-aic-sg-table th { vertical-align:top; }
.wpcc-aic-sg-table input.wpcc-aic-et,.wpcc-aic-sg-table textarea.wpcc-aic-ed { width:100%; }
.wpcc-aic-cc { font-size:11px;color:#646970;margin:2px 0 8px; }
.wpcc-aic-cc.out { color:#b32d2e; }
.wpcc-aic-prov { font-size:11px;color:#646970; }
.wpcc-aic-edited { display:inline-block;font-size:11px;border-radius:8px;padding:1px 6px;background:#cce5d6;color:#1a4731;margin-left:6px; }
.wpcc-aic-rowmsg { font-size:12px;color:#646970;margin-top:4px; }
.wpcc-aic-field { display:inline-block;font-size:11px;border-radius:10px;padding:1px 8px;background:#dcdcde;color:#1d2327; }
.wpcc-aic-tabcount { display:inline-block;margin-left:6px;padding:0 7px;border-radius:9px;background:#dcdcde;color:#1d2327;font-size:11px;line-height:18px;vertical-align:2px; }
.wpcc-aic-segbar { display:flex;gap:6px;flex-wrap:wrap;margin:8px 0 6px; }
.wpcc-aic-toast { position:fixed;right:24px;bottom:24px;z-index:99999;max-width:400px;background:#1d2327;color:#fff;border-radius:6px;padding:12px 14px;box-shadow:0 6px 24px rgba(0,0,0,.25);font-size:13px; }
.wpcc-aic-toast-row { display:flex;align-items:center;gap:10px;flex-wrap:wrap; }
.wpcc-aic-toast-msg { flex:1 1 auto; }
.wpcc-aic-toast-actions { display:flex;align-items:center;gap:8px; }
.wpcc-aic-chip { display:inline-block;font-size:11px;border-radius:10px;padding:1px 8px;background:#3c434a;color:#f0f0f1;margin-left:4px; }
.wpcc-aic-chip--good { background:#0a7c2f;color:#fff; }
.wpcc-aic-toast-view { color:#72aee6;text-decoration:underline;cursor:pointer;font-size:12px; }
.wpcc-aic-toast-x { background:none;border:none;color:#c3c4c7;cursor:pointer;font-size:16px;line-height:1;padding:0 2px; }
.wpcc-aic-toast-status:empty { display:none; }
.wpcc-aic-toast-status { font-size:12px;color:#c3c4c7;margin-top:6px; }
@media (prefers-reduced-motion: no-preference) { .wpcc-aic-toast { animation:wpcc-aic-toast-in .18s ease-out; } }
@keyframes wpcc-aic-toast-in { from { opacity:0;transform:translateY(8px); } to { opacity:1;transform:none; } }
</style>

<script>
( function () {
	const API    = <?php echo wp_json_encode( $api_base ); ?>;
	const CORE   = <?php echo wp_json_encode( $core_base ); ?>;
	const EDIT   = <?php echo wp_json_encode( $edit_base ); ?>;
	const AI_URL = <?php echo wp_json_encode( $ai_url ); ?>;
	const NONCE  = <?php echo wp_json_encode( $nonce ); ?>;
	const MODE   = <?php echo wp_json_encode( $security_mode ); ?>; // developer | client | enterprise
	const IS_DEV = ( MODE === 'developer' );
	const LIMIT  = 20;
	const TITLE_MAX = 60, EXCERPT_MAX = 320; // advisory char-count targets
	// operation_id + per-field target_type used on EVERY proposal read/write below.
	const OP = 'content_manage';
	const TT_TITLE = 'content_title', TT_EXCERPT = 'content_excerpt';
	const STR = {
		loading:  <?php echo wp_json_encode( esc_html__( 'Loading…', 'wp-command-center' ) ); ?>,
		error:    <?php echo wp_json_encode( esc_html__( 'Could not load. Please retry.', 'wp-command-center' ) ); ?>,
		none:     <?php echo wp_json_encode( esc_html__( '(not set)', 'wp-command-center' ) ); ?>,
		fieldTitle:   <?php echo wp_json_encode( esc_html__( 'Title', 'wp-command-center' ) ); ?>,
		fieldExcerpt: <?php echo wp_json_encode( esc_html__( 'Excerpt', 'wp-command-center' ) ); ?>,
		byAI:     <?php echo wp_json_encode( esc_html__( 'Suggested by AI', 'wp-command-center' ) ); ?>,
		edited:   <?php echo wp_json_encode( esc_html__( 'Edited', 'wp-command-center' ) ); ?>,
		save:     <?php echo wp_json_encode( esc_html__( 'Save', 'wp-command-center' ) ); ?>,
		saved:    <?php echo wp_json_encode( esc_html__( 'Saved', 'wp-command-center' ) ); ?>,
		dismiss:  <?php echo wp_json_encode( esc_html__( 'Dismiss', 'wp-command-center' ) ); ?>,
		noSug:    <?php echo wp_json_encode( esc_html__( 'No suggestions yet. Generate some from a post or page.', 'wp-command-center' ) ); ?>,
		/* translators: %1$d current length, %2$d max */
		ccTitle:  <?php echo wp_json_encode( __( '%1$d / %2$d', 'wp-command-center' ) ); ?>,
		/* translators: %1$d current length */
		ccExcerpt: <?php echo wp_json_encode( __( '%1$d characters', 'wp-command-center' ) ); ?>,
		// Apply + Applied tab (mode-aware label; outcome from response).
		applyDev:  <?php echo wp_json_encode( esc_html__( 'Approve & Apply', 'wp-command-center' ) ); ?>,
		applyGate: <?php echo wp_json_encode( esc_html__( 'Submit for approval', 'wp-command-center' ) ); ?>,
		cantApply: <?php echo wp_json_encode( esc_html__( 'Couldn’t apply', 'wp-command-center' ) ); ?>,
		stApplied: <?php echo wp_json_encode( esc_html__( 'Applied', 'wp-command-center' ) ); ?>,
		stAwaiting:<?php echo wp_json_encode( esc_html__( 'Awaiting approval', 'wp-command-center' ) ); ?>,
		stFailed:  <?php echo wp_json_encode( esc_html__( 'Failed', 'wp-command-center' ) ); ?>,
		stReverted:<?php echo wp_json_encode( esc_html__( 'Reverted', 'wp-command-center' ) ); ?>,
		noApplied: <?php echo wp_json_encode( esc_html__( 'Nothing applied yet.', 'wp-command-center' ) ); ?>,
		// Per-item Undo (reuses the governed change-history rollback).
		undo:      <?php echo wp_json_encode( esc_html__( 'Undo', 'wp-command-center' ) ); ?>,
		undoSent:  <?php echo wp_json_encode( esc_html__( 'Undo sent for approval', 'wp-command-center' ) ); ?>,
		cantUndo:  <?php echo wp_json_encode( esc_html__( 'Couldn’t undo', 'wp-command-center' ) ); ?>,
		prev:     <?php echo wp_json_encode( esc_html__( '← Previous', 'wp-command-center' ) ); ?>,
		next:     <?php echo wp_json_encode( esc_html__( 'Next →', 'wp-command-center' ) ); ?>,
		/* translators: %1$d first row, %2$d last row, %3$d total */
		pageInfo: <?php echo wp_json_encode( __( 'Showing %1$d–%2$d of %3$d', 'wp-command-center' ) ); ?>,
		// Post-apply confirmation toast.
		toastApplied:   <?php echo wp_json_encode( esc_html__( 'Applied successfully', 'wp-command-center' ) ); ?>,
		toastSubmitted: <?php echo wp_json_encode( esc_html__( 'Submitted for approval', 'wp-command-center' ) ); ?>,
		chipReversible: <?php echo wp_json_encode( esc_html__( 'Reversible', 'wp-command-center' ) ); ?>,
		chipAudited:    <?php echo wp_json_encode( esc_html__( 'Audited', 'wp-command-center' ) ); ?>,
		toastView:      <?php echo wp_json_encode( esc_html__( 'View in Applied', 'wp-command-center' ) ); ?>,
		toastUndone:    <?php echo wp_json_encode( esc_html__( 'Reverted', 'wp-command-center' ) ); ?>,
		toastClose:     <?php echo wp_json_encode( esc_html__( 'Dismiss notification', 'wp-command-center' ) ); ?>,
		// Contextual entry-point notices (wpcc_content_gen / wpcc_content_bulk).
		genCreated:    <?php echo wp_json_encode( esc_html__( 'Suggestion created. Review it below and apply when you’re ready.', 'wp-command-center' ) ); ?>,
		genExists:     <?php echo wp_json_encode( esc_html__( 'This item already has an open suggestion — review it below.', 'wp-command-center' ) ); ?>,
		genNoProvider: <?php echo wp_json_encode( esc_html__( 'No AI provider is connected, so nothing was generated. Add an Anthropic API key in AI Integrations.', 'wp-command-center' ) ); ?>,
		genUnsupported: <?php echo wp_json_encode( esc_html__( 'Some items have a status that cannot receive suggestions (e.g. trashed or auto-draft) and were skipped.', 'wp-command-center' ) ); ?>,
		genFailed:     <?php echo wp_json_encode( esc_html__( 'Couldn’t generate a suggestion. Please try again.', 'wp-command-center' ) ); ?>,
		genSkipped:    <?php echo wp_json_encode( esc_html__( 'Nothing was generated for the selected items.', 'wp-command-center' ) ); ?>,
		aiIntegrations: <?php echo wp_json_encode( esc_html__( 'Open AI Integrations', 'wp-command-center' ) ); ?>,
		/* translators: %1$d created, %2$d skipped, %3$d failed */
		bulkSummary:   <?php echo wp_json_encode( __( '%1$d suggestions created · %2$d skipped · %3$d failed. Review and apply below.', 'wp-command-center' ) ); ?>
	};

	const $ = ( id ) => document.getElementById( id );
	const esc = ( s ) => String( s == null ? '' : s ).replace( /[&<>"']/g, ( c ) => ( { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[ c ] ) );

	function api( path, opts ) {
		opts = opts || {};
		opts.headers = Object.assign( { 'X-WP-Nonce': NONCE }, opts.headers || {} );
		return fetch( API + path, opts )
			.then( ( r ) => r.json().then( ( d ) => ( { ok: r.ok, data: d } ), () => ( { ok: r.ok, data: {} } ) ) );
	}
	function coreGet( path ) {
		return fetch( CORE + path, { headers: { 'X-WP-Nonce': NONCE } } )
			.then( ( r ) => r.json().then( ( d ) => ( { ok: r.ok, data: d } ), () => ( { ok: r.ok, data: [] } ) ) );
	}
	function setHtml( id, html ) { const el = $( id ); if ( el ) { el.innerHTML = html; } }
	function setTabCount( id, n ) { const el = $( id ); if ( el ) { el.textContent = ( n > 0 ? ' ' + n : '' ); el.style.display = ( n > 0 ? '' : 'none' ); } }

	// The field a content draft carries is decided by its target_type. content_title →
	// 'title'; content_excerpt → 'excerpt'. Everything (current value, suggested value,
	// final_payload, char-count, label) keys off this.
	function fieldOf( p ) { return ( p.target_type === TT_EXCERPT ) ? 'excerpt' : 'title'; }
	function fieldLabel( field ) { return field === 'excerpt' ? STR.fieldExcerpt : STR.fieldTitle; }

	// Suggested (editable) value: final_payload[field] wins over payload[field].
	function suggestedValue( p, field ) {
		const fp = p.final_payload || null;
		const pl = p.payload || {};
		if ( fp && fp[ field ] != null ) { return fp[ field ]; }
		return ( pl[ field ] != null ) ? pl[ field ] : '';
	}
	function priorValue( p, field ) {
		const pr = p.prior || {};
		return ( pr[ field ] != null ) ? pr[ field ] : '';
	}
	function isEdited( p, field ) {
		if ( ! p.final_payload || p.final_payload[ field ] == null ) { return false; }
		const pl = ( p.payload || {} );
		return p.final_payload[ field ] !== ( pl[ field ] != null ? pl[ field ] : '' );
	}

	// ---------- SUGGESTIONS TAB: review / edit / Save / Apply / Dismiss DRAFTS ----------
	let sgOffset = 0, sgHasMore = false, sgReturned = 0, sgTotal = 0;

	function kindFilter() { const s = $( 'wpcc-aic-kind' ); return s ? s.value : 'all'; }
	function targetTypeParam() {
		const k = kindFilter();
		if ( k === 'title' ) { return '&target_type=' + encodeURIComponent( TT_TITLE ); }
		if ( k === 'excerpt' ) { return '&target_type=' + encodeURIComponent( TT_EXCERPT ); }
		return ''; // All → no target_type filter
	}

	function updateCount( row ) {
		const field = row.getAttribute( 'data-field' );
		const cc = row.querySelector( '.wpcc-aic-cc' );
		if ( ! cc ) { return; }
		if ( field === 'excerpt' ) {
			const ta = row.querySelector( '.wpcc-aic-ed' );
			const n = ( ta ? ta.value : '' ).length;
			cc.textContent = STR.ccExcerpt.replace( '%1$d', n );
			cc.classList.toggle( 'out', n > EXCERPT_MAX );
		} else {
			const inp = row.querySelector( '.wpcc-aic-et' );
			const n = ( inp ? inp.value : '' ).length;
			cc.textContent = STR.ccTitle.replace( '%1$d', n ).replace( '%2$d', TITLE_MAX );
			cc.classList.toggle( 'out', n > TITLE_MAX );
		}
	}

	function renderSuggestions( list, ctx ) {
		setHtml( 'wpcc-aic-sg-rows', list.map( ( p ) => {
			const tid = parseInt( p.target_id, 10 );
			const c = ctx[ tid ] || {};
			const title = c.title || ( '#' + tid );
			const editLink = EDIT + '?post=' + encodeURIComponent( tid ) + '&action=edit';
			const field = fieldOf( p );
			const cur = priorValue( p, field );
			const sg  = suggestedValue( p, field );
			const editedChip = isEdited( p, field ) ? '<span class="wpcc-aic-edited">' + esc( STR.edited ) + '</span>' : '';
			const prov = p.provider ? '<div class="wpcc-aic-prov">' + esc( STR.byAI ) + ( p.model ? ' · ' + esc( p.model ) : '' ) + editedChip + '</div>' : ( editedChip ? '<div>' + editedChip + '</div>' : '' );
			const curCell = cur ? esc( cur ) : '<em class="wpcc-aic-none">' + esc( STR.none ) + '</em>';
			const editor = ( field === 'excerpt' )
				? '<label class="screen-reader-text">' + esc( STR.fieldExcerpt ) + '</label><textarea class="wpcc-aic-ed" rows="3">' + esc( sg ) + '</textarea>'
				: '<label class="screen-reader-text">' + esc( STR.fieldTitle ) + '</label><input type="text" class="wpcc-aic-et" value="' + esc( sg ) + '">';
			// proposal_id is an OPAQUE DOM key only (edit/apply/dismiss); never displayed.
			return '<tr data-id="' + esc( p.proposal_id ) + '" data-tid="' + esc( tid ) + '" data-field="' + esc( field ) + '">' +
				'<td><strong><a href="' + esc( editLink ) + '">' + esc( title ) + '</a></strong><div class="wpcc-aic-meta">' + esc( c.type || '' ) + '</div></td>' +
				'<td><span class="wpcc-aic-field">' + esc( fieldLabel( field ) ) + '</span></td>' +
				'<td class="wpcc-aic-meta">' + curCell + '</td>' +
				'<td>' + prov + editor +
					'<div class="wpcc-aic-cc"></div>' +
				'</td>' +
				'<td><button type="button" class="button button-primary button-small wpcc-aic-apply">' + esc( IS_DEV ? STR.applyDev : STR.applyGate ) + '</button> ' +
					'<button type="button" class="button button-small wpcc-aic-save">' + esc( STR.save ) + '</button> ' +
					'<button type="button" class="button button-small wpcc-aic-dismiss">' + esc( STR.dismiss ) + '</button>' +
					'<div class="wpcc-aic-rowmsg" role="status"></div></td>' +
				'</tr>';
		} ).join( '' ) );
		document.querySelectorAll( '#wpcc-aic-sg-rows tr[data-id]' ).forEach( updateCount );
	}

	function renderSgPager() {
		if ( sgTotal <= LIMIT && sgOffset === 0 ) { setHtml( 'wpcc-aic-sg-pager', '' ); return; }
		const prevDis = sgOffset <= 0 ? ' disabled' : ''; const nextDis = sgHasMore ? '' : ' disabled';
		setHtml( 'wpcc-aic-sg-pager',
			'<button type="button" class="button" id="wpcc-aic-sg-prev"' + prevDis + '>' + esc( STR.prev ) + '</button>' +
			'<button type="button" class="button" id="wpcc-aic-sg-next"' + nextDis + '>' + esc( STR.next ) + '</button>' );
		const p = $( 'wpcc-aic-sg-prev' ), n = $( 'wpcc-aic-sg-next' );
		if ( p ) { p.addEventListener( 'click', () => { if ( sgOffset > 0 ) { sgOffset = Math.max( 0, sgOffset - LIMIT ); loadSuggestions(); } } ); }
		if ( n ) { n.addEventListener( 'click', () => { if ( sgHasMore ) { sgOffset += LIMIT; loadSuggestions(); } } ); }
	}

	function loadSuggestions() {
		setHtml( 'wpcc-aic-sg-rows', '<tr><td colspan="5">' + esc( STR.loading ) + '</td></tr>' );
		api( '/proposals?status=draft&operation_id=' + encodeURIComponent( OP ) + targetTypeParam() + '&limit=' + LIMIT + '&offset=' + sgOffset )
			.then( ( res ) => {
				if ( ! res.ok ) { setHtml( 'wpcc-aic-sg-rows', '<tr><td colspan="5" style="color:#b32d2e;">' + esc( STR.error ) + '</td></tr>' ); return; }
				const d = res.data || {}, list = d.proposals || [];
				sgTotal = d.total_count || 0; sgReturned = d.returned || list.length; sgHasMore = !! d.has_more;
				if ( ! list.length ) { setHtml( 'wpcc-aic-sg-rows', '<tr><td colspan="5">' + esc( STR.noSug ) + '</td></tr>' ); setHtml( 'wpcc-aic-sg-pager', '' ); $( 'wpcc-aic-sg-status' ).textContent = ''; return; }
				const ids = list.map( ( p ) => parseInt( p.target_id, 10 ) ).filter( ( n ) => n > 0 );
				const csv = ids.join( ',' );
				Promise.all( [
					coreGet( '/posts?include=' + csv + '&per_page=' + ids.length + '&_fields=id,title,type' ),
					coreGet( '/pages?include=' + csv + '&per_page=' + ids.length + '&_fields=id,title,type' )
				] ).then( ( r ) => {
					const ctx = {};
					[ r[0].data, r[1].data ].forEach( ( arr ) => { ( Array.isArray( arr ) ? arr : [] ).forEach( ( m ) => { ctx[ m.id ] = { title: ( m.title && m.title.rendered ) || '', type: m.type || '' }; } ); } );
					renderSuggestions( list, ctx );
					$( 'wpcc-aic-sg-status' ).textContent = STR.pageInfo.replace( '%1$d', sgOffset + 1 ).replace( '%2$d', sgOffset + sgReturned ).replace( '%3$d', sgTotal );
					renderSgPager();
				} ).catch( () => { renderSuggestions( list, {} ); renderSgPager(); } );
			} )
			.catch( () => { setHtml( 'wpcc-aic-sg-rows', '<tr><td colspan="5" style="color:#b32d2e;">' + esc( STR.error ) + '</td></tr>' ); } );
	}

	// Build the governed final_payload from the row's CURRENTLY VISIBLE (possibly
	// unsaved) field value, and persist it via the EXISTING PUT route. Shared by Save
	// AND Apply so Apply can never apply an unsaved/stale edit.
	function rowFinalPayload( row, tid, field ) {
		const fp = { action: 'content_update', content_id: tid };
		if ( field === 'excerpt' ) { fp.excerpt = row.querySelector( '.wpcc-aic-ed' ).value; }
		else { fp.title = row.querySelector( '.wpcc-aic-et' ).value; }
		return fp;
	}
	function persistRow( id, row, tid, field ) {
		return api( '/proposals/' + encodeURIComponent( id ), {
			method: 'PUT', headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { final_payload: rowFinalPayload( row, tid, field ) } )
		} );
	}

	// Live char counts.
	document.addEventListener( 'input', function ( e ) {
		const row = e.target.closest ? e.target.closest( '#wpcc-aic-sg-rows tr[data-id]' ) : null;
		if ( row && ( e.target.classList.contains( 'wpcc-aic-et' ) || e.target.classList.contains( 'wpcc-aic-ed' ) ) ) { updateCount( row ); }
	} );

	// Save / Dismiss / Apply — delegated.
	document.addEventListener( 'click', function ( e ) {
		const t = e.target; const row = t.closest ? t.closest( '#wpcc-aic-sg-rows tr[data-id]' ) : null;
		if ( ! row ) { return; }
		const id = row.getAttribute( 'data-id' ), tid = parseInt( row.getAttribute( 'data-tid' ) || '0', 10 );
		const field = row.getAttribute( 'data-field' ) || 'title';
		const msg = row.querySelector( '.wpcc-aic-rowmsg' );
		if ( t.classList.contains( 'wpcc-aic-save' ) ) {
			if ( msg ) { msg.textContent = '…'; }
			persistRow( id, row, tid, field )
				.then( ( res ) => { if ( msg ) { msg.textContent = res.ok ? STR.saved : ( ( res.data && res.data.message ) || STR.error ); } } )
				.catch( () => { if ( msg ) { msg.textContent = STR.error; } } );
		} else if ( t.classList.contains( 'wpcc-aic-dismiss' ) ) {
			api( '/proposals/' + encodeURIComponent( id ) + '/dismiss', { method: 'POST' } )
				.then( ( res ) => { if ( res.ok ) { row.parentNode.removeChild( row ); updateTabCounts(); } else if ( msg ) { msg.textContent = ( res.data && res.data.message ) || STR.error; } } )
				.catch( () => { if ( msg ) { msg.textContent = STR.error; } } );
		} else if ( t.classList.contains( 'wpcc-aic-apply' ) ) {
			// Approve & Apply (developer) / Submit for approval (client/enterprise).
			// PERSIST the visible (possibly unsaved) value as final_payload via the
			// EXISTING governed PUT route BEFORE applying, so Apply never applies a stale
			// AI suggestion. The outcome is driven by the API response status
			// (applied | pending_approval), never assumed from the label.
			t.disabled = true;
			if ( msg ) { msg.textContent = '…'; }
			persistRow( id, row, tid, field )
				.then( ( saveRes ) => {
					if ( ! saveRes.ok ) {
						t.disabled = false;
						if ( msg ) { msg.textContent = ( saveRes.data && saveRes.data.message ) || STR.error; }
						return null; // do NOT apply stale data if the edit could not be persisted
					}
					return api( '/proposals/' + encodeURIComponent( id ) + '/apply', { method: 'POST' } );
				} )
				.then( ( res ) => {
					if ( ! res ) { return; } // persist failed; already surfaced
					const st  = ( res.data && res.data.status ) || '';
					const cid = ( res.data && res.data.change_id ) || '';
					if ( st === 'applied' || st === 'pending_approval' ) {
						if ( row.parentNode ) { row.parentNode.removeChild( row ); } // moves to Applied tab
						showApplyToast( st, cid );
						updateTabCounts();
					} else {
						t.disabled = false;
						if ( msg ) { msg.textContent = STR.cantApply; }
					}
				} )
				.catch( () => { t.disabled = false; if ( msg ) { msg.textContent = STR.cantApply; } } );
		}
	} );

	// ---------- APPLIED TAB: segmented single-status status list + per-item Undo ----------
	function apBadge( color, label ) {
		return '<span style="display:inline-block;padding:2px 8px;border-radius:10px;color:#fff;font-size:12px;background:' + color + ';">' + esc( label ) + '</span>';
	}
	function appliedValue( p ) {
		const field = fieldOf( p );
		const v = suggestedValue( p, field );
		return v ? esc( v ) : '<em class="wpcc-aic-none">' + esc( STR.none ) + '</em>';
	}
	function renderApplied( list, ctx ) {
		if ( ! list.length ) { setHtml( 'wpcc-aic-ap-rows', '<tr><td colspan="5">' + esc( STR.noApplied ) + '</td></tr>' ); return; }
		setHtml( 'wpcc-aic-ap-rows', list.map( ( p ) => {
			const tid = parseInt( p.target_id, 10 );
			const c = ctx[ tid ] || {};
			const title = c.title || ( '#' + tid );
			const editLink = EDIT + '?post=' + encodeURIComponent( tid ) + '&action=edit';
			const field = fieldOf( p );
			let badge, reversible = false;
			if ( p.status === 'pending_approval' ) { badge = apBadge( '#bd8600', STR.stAwaiting ); }
			else if ( p.status === 'failed' ) { badge = apBadge( '#b32d2e', STR.stFailed ); }
			else if ( p.status === 'applied' && p.change_status === 'rolled_back' ) { badge = apBadge( '#646970', STR.stReverted ); }
			else { badge = apBadge( '#1a7f37', STR.stApplied ); reversible = ( p.status === 'applied' && !! p.change_id ); }
			// Undo ONLY for applied + reversible (not yet rolled back) rows that carry a
			// change_id. change_id is an OPAQUE handle for the single Undo action (never
			// displayed). Reuses POST /admin/history/{change_id}/rollback.
			const cid = reversible ? ' data-cid="' + esc( p.change_id ) + '"' : '';
			const actions = reversible
				? '<button type="button" class="button button-small wpcc-aic-undo">' + esc( STR.undo ) + '</button><div class="wpcc-aic-rowmsg" role="status"></div>'
				: '';
			return '<tr' + cid + '>' +
				'<td><strong><a href="' + esc( editLink ) + '">' + esc( title ) + '</a></strong><div class="wpcc-aic-meta">' + esc( c.type || '' ) + '</div></td>' +
				'<td><span class="wpcc-aic-field">' + esc( fieldLabel( field ) ) + '</span></td>' +
				'<td class="wpcc-aic-meta">' + appliedValue( p ) + '</td>' +
				'<td>' + badge + '</td>' +
				'<td>' + actions + '</td>' +
				'</tr>';
		} ).join( '' ) );
	}

	// Segmented single-status pagination. Each segment (applied | pending_approval |
	// failed) is ONE paginated read over the EXISTING /admin/proposals route + canonical
	// envelope (total_count/returned/has_more/offset). No 3-read merge, no new route.
	let apSeg = 'applied';
	let apOffset = 0, apTotal = 0, apReturned = 0, apHasMore = false;
	const AP_LIMIT = LIMIT;

	function renderAppliedPager() {
		if ( apTotal <= AP_LIMIT && apOffset === 0 ) { setHtml( 'wpcc-aic-ap-pager', '' ); return; }
		const prevDis = apOffset <= 0 ? ' disabled' : ''; const nextDis = apHasMore ? '' : ' disabled';
		setHtml( 'wpcc-aic-ap-pager',
			'<button type="button" class="button" id="wpcc-aic-ap-prev"' + prevDis + '>' + esc( STR.prev ) + '</button>' +
			'<button type="button" class="button" id="wpcc-aic-ap-next"' + nextDis + '>' + esc( STR.next ) + '</button>' );
		const p = $( 'wpcc-aic-ap-prev' ), n = $( 'wpcc-aic-ap-next' );
		if ( p ) { p.addEventListener( 'click', () => { if ( apOffset > 0 ) { apOffset = Math.max( 0, apOffset - AP_LIMIT ); loadApplied(); } } ); }
		if ( n ) { n.addEventListener( 'click', () => { if ( apHasMore ) { apOffset += AP_LIMIT; loadApplied(); } } ); }
	}
	function switchApSeg( seg ) {
		apSeg = seg; apOffset = 0;
		document.querySelectorAll( '#wpcc-aic-ap-segbar .wpcc-aic-seg' ).forEach( ( b ) => {
			b.classList.toggle( 'button-primary', b.getAttribute( 'data-seg' ) === seg );
		} );
		loadApplied();
	}
	function loadApplied() {
		setHtml( 'wpcc-aic-ap-rows', '<tr><td colspan="5">' + esc( STR.loading ) + '</td></tr>' );
		api( '/proposals?status=' + encodeURIComponent( apSeg ) + '&operation_id=' + encodeURIComponent( OP ) + '&limit=' + AP_LIMIT + '&offset=' + apOffset )
			.then( ( res ) => {
				if ( ! res.ok ) { setHtml( 'wpcc-aic-ap-rows', '<tr><td colspan="5" style="color:#b32d2e;">' + esc( STR.error ) + '</td></tr>' ); setHtml( 'wpcc-aic-ap-pager', '' ); $( 'wpcc-aic-ap-status' ).textContent = ''; return; }
				const d = res.data || {}, list = d.proposals || [];
				apTotal = d.total_count || 0; apReturned = d.returned || list.length; apHasMore = !! d.has_more;
				if ( ! list.length ) { setHtml( 'wpcc-aic-ap-rows', '<tr><td colspan="5">' + esc( STR.noApplied ) + '</td></tr>' ); setHtml( 'wpcc-aic-ap-pager', '' ); $( 'wpcc-aic-ap-status' ).textContent = ''; return; }
				const ids = list.map( ( p ) => parseInt( p.target_id, 10 ) ).filter( ( n ) => n > 0 );
				const csv = ids.join( ',' );
				Promise.all( [
					coreGet( '/posts?include=' + csv + '&per_page=' + ids.length + '&_fields=id,title,type' ),
					coreGet( '/pages?include=' + csv + '&per_page=' + ids.length + '&_fields=id,title,type' )
				] ).then( ( r ) => {
					const ctx = {};
					[ r[0].data, r[1].data ].forEach( ( arr ) => { ( Array.isArray( arr ) ? arr : [] ).forEach( ( m ) => { ctx[ m.id ] = { title: ( m.title && m.title.rendered ) || '', type: m.type || '' }; } ); } );
					renderApplied( list, ctx );
					$( 'wpcc-aic-ap-status' ).textContent = STR.pageInfo.replace( '%1$d', apOffset + 1 ).replace( '%2$d', apOffset + apReturned ).replace( '%3$d', apTotal );
					renderAppliedPager();
				} ).catch( () => { renderApplied( list, {} ); renderAppliedPager(); } );
			} )
			.catch( () => { setHtml( 'wpcc-aic-ap-rows', '<tr><td colspan="5" style="color:#b32d2e;">' + esc( STR.error ) + '</td></tr>' ); setHtml( 'wpcc-aic-ap-pager', '' ); } );
	}

	// SINGLE governed rollback path — reused by BOTH the Applied-tab per-item Undo and
	// the post-apply toast Undo. Reuses ONLY the existing rollback route. The
	// change_history operation resolves the owning change and reverses it via its
	// content_update restore — the same governed chokepoint as apply (capability +
	// approval + audit + rollback all inherited).
	function rollbackChange( cid ) {
		return fetch( API + '/history/' + encodeURIComponent( cid ) + '/rollback', { method: 'POST', headers: { 'X-WP-Nonce': NONCE } } )
			.then( ( r ) => r.json().then( ( d ) => ( { ok: r.ok, data: d } ), () => ( { ok: r.ok, data: {} } ) ) );
	}
	function rollbackOutcome( res ) {
		const inner = ( res.data && res.data.result ) || {};
		if ( inner.status === 'pending_approval' ) { return 'pending'; }
		if ( res.ok && res.data && res.data.success === true && inner.status !== 'confirmation_required' ) { return 'reverted'; }
		return 'failed';
	}

	// Applied-tab per-item Undo. Developer → immediate revert (status flips to
	// "Reverted" on reload); client/enterprise → pending_approval ("Undo sent for
	// approval").
	document.addEventListener( 'click', function ( e ) {
		const t = e.target;
		if ( ! t.classList || ! t.classList.contains( 'wpcc-aic-undo' ) ) { return; }
		const row = t.closest ? t.closest( '#wpcc-aic-ap-rows tr[data-cid]' ) : null;
		if ( ! row ) { return; }
		const cid = row.getAttribute( 'data-cid' );
		if ( ! cid ) { return; }
		const msg = row.querySelector( '.wpcc-aic-rowmsg' );
		t.disabled = true;
		if ( msg ) { msg.textContent = '…'; }
		rollbackChange( cid )
			.then( ( res ) => {
				const out = rollbackOutcome( res );
				if ( out === 'pending' ) { if ( msg ) { msg.textContent = STR.undoSent; } }
				else if ( out === 'reverted' ) { loadApplied(); updateTabCounts(); }
				else { t.disabled = false; if ( msg ) { msg.textContent = STR.cantUndo; } }
			} )
			.catch( () => { t.disabled = false; if ( msg ) { msg.textContent = STR.cantUndo; } } );
	} );

	// Post-apply confirmation toast: "Applied successfully · Reversible · Audited" with
	// an inline Undo (developer/applied + change_id) that calls the shared
	// rollbackChange(). Gated apply → "Submitted for approval · Audited" (no Undo yet).
	function showApplyToast( status, cid ) {
		const el = $( 'wpcc-aic-toast' );
		if ( ! el ) { return; }
		const applied = ( status === 'applied' );
		let h = '<div class="wpcc-aic-toast-row">';
		h += '<span class="wpcc-aic-toast-msg"><strong>' + esc( applied ? STR.toastApplied : STR.toastSubmitted ) + '</strong>';
		if ( applied ) { h += '<span class="wpcc-aic-chip wpcc-aic-chip--good">' + esc( STR.chipReversible ) + '</span>'; }
		h += '<span class="wpcc-aic-chip">' + esc( STR.chipAudited ) + '</span></span>';
		h += '<span class="wpcc-aic-toast-actions">';
		if ( applied && cid ) {
			h += '<button type="button" class="button button-small wpcc-aic-toast-undo" data-cid="' + esc( cid ) + '">' + esc( STR.undo ) + '</button>';
		}
		h += '<a href="#" class="wpcc-aic-toast-view" data-go="applied">' + esc( STR.toastView ) + '</a>';
		h += '<button type="button" class="wpcc-aic-toast-x" aria-label="' + esc( STR.toastClose ) + '">×</button>';
		h += '</span></div><div class="wpcc-aic-toast-status"></div>';
		el.innerHTML = h;
		el.style.display = 'block';
	}

	// Toast actions: Undo (shared rollback path), "View in Applied" deep link, Dismiss.
	document.addEventListener( 'click', function ( e ) {
		const el = $( 'wpcc-aic-toast' );
		if ( ! el || el.style.display === 'none' ) { return; }
		const t = e.target;
		if ( t.classList && t.classList.contains( 'wpcc-aic-toast-x' ) ) { el.style.display = 'none'; el.innerHTML = ''; return; }
		const view = t.closest ? t.closest( '.wpcc-aic-toast-view[data-go]' ) : null;
		if ( view ) { e.preventDefault(); switchTab( view.getAttribute( 'data-go' ) ); el.style.display = 'none'; el.innerHTML = ''; return; }
		if ( t.classList && t.classList.contains( 'wpcc-aic-toast-undo' ) ) {
			const cid = t.getAttribute( 'data-cid' ); if ( ! cid ) { return; }
			const st = el.querySelector( '.wpcc-aic-toast-status' );
			t.disabled = true; if ( st ) { st.textContent = '…'; }
			rollbackChange( cid )
				.then( ( res ) => {
					const out = rollbackOutcome( res );
					if ( out === 'reverted' ) {
						if ( st ) { st.textContent = STR.toastUndone; }
						t.style.display = 'none';
						updateTabCounts();
						const ap = $( 'wpcc-aic-panel-applied' );
						if ( ap && ap.style.display !== 'none' ) { loadApplied(); }
					} else if ( out === 'pending' ) {
						if ( st ) { st.textContent = STR.undoSent; }
						t.style.display = 'none';
					} else {
						t.disabled = false;
						if ( st ) { st.textContent = STR.cantUndo; }
					}
				} )
				.catch( () => { t.disabled = false; if ( st ) { st.textContent = STR.cantUndo; } } );
		}
	} );

	// ---------- tab counts ----------
	function updateTabCounts() {
		api( '/proposals?status=draft&operation_id=' + encodeURIComponent( OP ) + '&limit=1' ).then( ( r ) => {
			const n = ( r.ok && r.data && r.data.total_count ) || 0;
			setTabCount( 'wpcc-aic-tabcount-suggestions', n );
		} );
		api( '/proposals?status=applied&operation_id=' + encodeURIComponent( OP ) + '&limit=1' ).then( ( r ) => {
			const n = ( r.ok && r.data && r.data.total_count ) || 0;
			setTabCount( 'wpcc-aic-tabcount-applied', n );
		} );
	}

	function switchTab( which ) {
		$( 'wpcc-aic-panel-suggestions' ).style.display = ( which === 'suggestions' ) ? '' : 'none';
		$( 'wpcc-aic-panel-applied' ).style.display = ( which === 'applied' ) ? '' : 'none';
		$( 'wpcc-aic-tab-suggestions' ).classList.toggle( 'nav-tab-active', which === 'suggestions' );
		$( 'wpcc-aic-tab-applied' ).classList.toggle( 'nav-tab-active', which === 'applied' );
		if ( which === 'suggestions' ) { sgOffset = 0; loadSuggestions(); }
		else if ( which === 'applied' ) { switchApSeg( 'applied' ); }
		updateTabCounts();
	}

	// ---------- wiring ----------
	$( 'wpcc-aic-tab-suggestions' ).addEventListener( 'click', function ( e ) { e.preventDefault(); switchTab( 'suggestions' ); } );
	$( 'wpcc-aic-tab-applied' ).addEventListener( 'click', function ( e ) { e.preventDefault(); switchTab( 'applied' ); } );
	if ( $( 'wpcc-aic-kind' ) ) { $( 'wpcc-aic-kind' ).addEventListener( 'change', function () { sgOffset = 0; loadSuggestions(); } ); }
	document.addEventListener( 'click', function ( e ) {
		const seg = e.target.closest ? e.target.closest( '#wpcc-aic-ap-segbar .wpcc-aic-seg' ) : null;
		if ( seg ) { switchApSeg( seg.getAttribute( 'data-seg' ) ); }
	} );

	// Contextual entry points: a row/bulk "Generate" action redirects here with
	// ?tab=suggestions&wpcc_content_gen={code} (single) or
	// ?wpcc_content_bulk=1&c=&s=&f=&r= (bulk). Show the result + land on Suggestions.
	function showEntryNotice( code ) {
		const el = $( 'wpcc-aic-entry-notice' ); if ( ! el ) { return; }
		const map = {
			created:            [ 'notice-success', STR.genCreated ],
			exists:             [ 'notice-info',    STR.genExists ],
			no_provider:        [ 'notice-warning', STR.genNoProvider, AI_URL, STR.aiIntegrations ],
			unsupported_status: [ 'notice-warning', STR.genUnsupported ],
			skipped:            [ 'notice-warning', STR.genSkipped ],
			failed:             [ 'notice-error',   STR.genFailed ]
		};
		const m = map[ code ]; if ( ! m ) { return; }
		el.className = 'notice inline ' + m[0];
		let html = '<p>' + esc( m[1] );
		if ( m[2] ) { html += ' <a href="' + esc( m[2] ) + '">' + esc( m[3] ) + '</a>'; }
		html += '</p>';
		el.innerHTML = html;
		el.style.display = '';
	}
	function showBulkNotice( c, s, f, r ) {
		const el = $( 'wpcc-aic-entry-notice' ); if ( ! el ) { return; }
		let cls = 'notice-success', extraUrl = '', extraLabel = '';
		let msg = STR.bulkSummary.replace( '%1$d', c ).replace( '%2$d', s ).replace( '%3$d', f );
		if ( c === 0 ) {
			cls = 'notice-warning';
			if ( r === 'no_provider' ) { msg = STR.genNoProvider; extraUrl = AI_URL; extraLabel = STR.aiIntegrations; }
			else if ( r === 'has_open_proposal' ) { msg = STR.genExists; cls = 'notice-info'; }
			else if ( r === 'unsupported_status' ) { msg = STR.genUnsupported; }
			else { msg = STR.genSkipped; }
		}
		el.className = 'notice inline ' + cls;
		let html = '<p>' + esc( msg );
		if ( extraUrl ) { html += ' <a href="' + esc( extraUrl ) + '">' + esc( extraLabel ) + '</a>'; }
		html += '</p>';
		el.innerHTML = html;
		el.style.display = '';
	}

	updateTabCounts();
	( function () {
		const sp = new URLSearchParams( location.search );
		// Kind deep link (?kind=title|excerpt) preselects the Suggestions filter.
		const kind = sp.get( 'kind' );
		if ( ( kind === 'title' || kind === 'excerpt' ) && $( 'wpcc-aic-kind' ) ) { $( 'wpcc-aic-kind' ).value = kind; }
		const code = sp.get( 'wpcc_content_gen' );
		if ( code ) { showEntryNotice( code ); }
		if ( sp.get( 'wpcc_content_bulk' ) ) {
			showBulkNotice(
				parseInt( sp.get( 'c' ) || '0', 10 ),
				parseInt( sp.get( 's' ) || '0', 10 ),
				parseInt( sp.get( 'f' ) || '0', 10 ),
				sp.get( 'r' ) || ''
			);
		}
		// Default tab = Suggestions; ?tab=suggestions is the explicit contextual landing.
		loadSuggestions();
	} )();
} )();
</script>
