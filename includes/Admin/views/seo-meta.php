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
 * Three tabs: Review (Slice 1 audit + Slice 2b generate), Suggestions (Slice 3
 * review/edit/dismiss of governed DRAFTS), and Applied (Slice 4a apply + status,
 * Slice 4b per-item Undo). Apply reuses POST /admin/proposals/{id}/apply; Undo
 * reuses the existing governed rollback path POST /admin/history/{change_id}/rollback
 * (change_history → seo_restore). It performs NO bulk apply/undo, no cross-page
 * selection, no Approval-Center / Change-History navigation links, no direct
 * operation-executor or seo-write call, and NEVER writes SEO meta directly. Outcome
 * language only:
 * proposal_id / change_id / request_id are never displayed (proposal_id is an opaque
 * DOM key for edit/dismiss; change_id is an opaque DOM key for Undo). All API output
 * is escaped client-side via esc().
 */

defined( 'ABSPATH' ) || exit;

$nonce     = wp_create_nonce( 'wp_rest' );
$api_base  = rest_url( 'wp-command-center/v1/admin' );
$core_base = rest_url( 'wp/v2' );
$edit_base = admin_url( 'post.php' ); // client builds ?post=ID&action=edit (any post type)
$ai_url    = admin_url( 'admin.php?page=wpcc-connect&wpcc_tab=clients' ); // U1.4 — connect an AI key
// Server-rendered security mode drives the apply button label (developer applies
// directly; client/enterprise submit for approval). The outcome is still taken from
// the apply API response (defensive) — the UI never assumes from the label.
$security_mode = \WPCommandCenter\Operations\SecurityModeManager::current();
?>
<div class="wrap wpcc-wrap wpcc-seo">
	<h1><?php esc_html_e( 'SEO', 'wp-command-center' ); ?></h1>
	<p class="description" style="max-width:720px;">
		<?php esc_html_e( 'Generate clear SEO titles and descriptions for posts and pages that need them. Review each suggestion, then approve to apply — nothing changes until you say so.', 'wp-command-center' ); ?>
	</p>
	<?php require WPCC_PLUGIN_DIR . 'includes/Admin/views/partials/trust-strip.php'; ?>

	<h2 class="nav-tab-wrapper">
		<a href="#" class="nav-tab nav-tab-active" id="wpcc-seo-tab-review"><?php esc_html_e( 'Review', 'wp-command-center' ); ?><span class="wpcc-seo-tabcount" id="wpcc-seo-tabcount-review"></span></a>
		<a href="#" class="nav-tab" id="wpcc-seo-tab-suggestions"><?php esc_html_e( 'Suggestions', 'wp-command-center' ); ?><span class="wpcc-seo-tabcount" id="wpcc-seo-tabcount-suggestions"></span></a>
		<a href="#" class="nav-tab" id="wpcc-seo-tab-applied"><?php esc_html_e( 'Applied', 'wp-command-center' ); ?><span class="wpcc-seo-tabcount" id="wpcc-seo-tabcount-applied"></span></a>
	</h2>

	<?php // Result of a contextual "Generate SEO Suggestion" row action (set via the
	// wpcc_seo_gen query arg on redirect; rendered client-side, escaped). ?>
	<div id="wpcc-seo-entry-notice" class="notice inline" role="status" aria-live="polite" style="display:none;margin:10px 0;"></div>

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

	<?php // U1.4 — surfaced when generation returns no_provider (no AI key connected). ?>
	<div id="wpcc-seo-gen-notice" class="notice notice-warning inline" role="status" aria-live="polite" style="display:none;margin:8px 0;"></div>

	<div id="wpcc-seo-panel">
		<p><span class="spinner is-active wpcc-spin"></span><?php esc_html_e( 'Loading…', 'wp-command-center' ); ?></p>
	</div>

	<div id="wpcc-seo-pager" class="wpcc-seo-pager"></div>
	</div><!-- /review -->

	<!-- ============ SUGGESTIONS TAB ============ -->
	<div id="wpcc-seo-panel-suggestions" style="display:none;">
		<p style="margin:12px 0;">
			<span class="description"><?php esc_html_e( 'AI suggestions awaiting your review. Edit the title or description, dismiss a suggestion, or apply individually or in bulk. Nothing is applied to your site until you choose to.', 'wp-command-center' ); ?></span>
			<span id="wpcc-seo-sg-status" role="status" aria-live="polite" style="margin-left:12px;color:#646970;"></span>
		</p>
		<?php // Slice 5a — page-scoped bulk action bar. Operates only on checked rows of the
		// currently rendered page; Apply/Dismiss are sequential loops over the existing
		// per-proposal routes (no batch object, no cross-page selection). ?>
		<div class="wpcc-seo-bulkbar" id="wpcc-seo-sg-bulkbar">
			<label><input type="checkbox" id="wpcc-seo-sg-selectall"> <?php esc_html_e( 'Select all on this page', 'wp-command-center' ); ?></label>
			<button type="button" class="button button-primary" id="wpcc-seo-sg-apply" disabled><?php esc_html_e( 'Apply selected', 'wp-command-center' ); ?></button>
			<button type="button" class="button" id="wpcc-seo-sg-dismiss" disabled><?php esc_html_e( 'Dismiss selected', 'wp-command-center' ); ?></button>
		</div>
		<div id="wpcc-seo-sg-progress" role="status" aria-live="polite" style="display:none;"></div>
		<table class="widefat striped wpcc-seo-sg-table">
			<thead>
				<tr>
					<th scope="col" style="width:28px;"><span class="screen-reader-text"><?php esc_html_e( 'Select', 'wp-command-center' ); ?></span></th>
					<th scope="col" style="width:22%;"><?php esc_html_e( 'Content', 'wp-command-center' ); ?></th>
					<th scope="col" style="width:26%;"><?php esc_html_e( 'Current', 'wp-command-center' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Suggested (editable)', 'wp-command-center' ); ?></th>
					<th scope="col" style="width:130px;"><?php esc_html_e( 'Actions', 'wp-command-center' ); ?></th>
				</tr>
			</thead>
			<tbody id="wpcc-seo-sg-rows">
				<tr><td colspan="5"><?php esc_html_e( 'Loading…', 'wp-command-center' ); ?></td></tr>
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
		<?php // Segmented, single-status pagination — each segment is one paginated
		// /admin/proposals read; default = Applied. ?>
		<div class="wpcc-seo-segbar" id="wpcc-seo-ap-segbar" role="group" aria-label="<?php esc_attr_e( 'Filter applied items by status', 'wp-command-center' ); ?>">
			<button type="button" class="button button-primary wpcc-seo-seg" data-seg="applied"><?php esc_html_e( 'Applied', 'wp-command-center' ); ?></button>
			<button type="button" class="button wpcc-seo-seg" data-seg="pending_approval"><?php esc_html_e( 'Awaiting approval', 'wp-command-center' ); ?></button>
			<button type="button" class="button wpcc-seo-seg" data-seg="failed"><?php esc_html_e( 'Failed', 'wp-command-center' ); ?></button>
		</div>
		<table class="widefat striped wpcc-seo-sg-table">
			<thead>
				<tr>
					<th scope="col" style="width:26%;"><?php esc_html_e( 'Content', 'wp-command-center' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Applied SEO meta', 'wp-command-center' ); ?></th>
					<th scope="col" style="width:160px;"><?php esc_html_e( 'Status', 'wp-command-center' ); ?></th>
					<th scope="col" style="width:120px;"><?php esc_html_e( 'Actions', 'wp-command-center' ); ?></th>
				</tr>
			</thead>
			<tbody id="wpcc-seo-ap-rows">
				<tr><td colspan="4"><?php esc_html_e( 'Loading…', 'wp-command-center' ); ?></td></tr>
			</tbody>
		</table>
		<div id="wpcc-seo-ap-pager" class="wpcc-seo-pager"></div>
	</div>

	<?php // Trust polish (Fix B) — post-apply confirmation toast (reversible · audited · Undo). ?>
	<div id="wpcc-seo-toast" class="wpcc-seo-toast" role="status" aria-live="polite" style="display:none;"></div>
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
.wpcc-seo-bulkbar { display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:12px 0;padding:8px 10px;border:1px solid #c3c4c7;background:#f6f7f7;border-radius:4px; }
#wpcc-seo-sg-progress { margin:8px 0;padding:10px;border:1px solid #c3c4c7;background:#fff;border-radius:4px;font-size:13px; }
.wpcc-seo-tabcount { display:inline-block;margin-left:6px;padding:0 7px;border-radius:9px;background:#dcdcde;color:#1d2327;font-size:11px;line-height:18px;vertical-align:2px; }
.wpcc-seo-dash { border:1px solid #c3c4c7;background:#fff;border-radius:4px;padding:16px;max-width:1100px; }
.wpcc-seo-dash-bar { height:8px;border-radius:5px;background:#e6e6e9;overflow:hidden;margin-bottom:8px; }
.wpcc-seo-dash-fill { height:100%;background:#00a32a;transition:width .2s; }
.wpcc-seo-dash-head { font-size:13px;color:#1d2327;margin-bottom:12px; }
.wpcc-seo-dash-groups { display:flex;gap:32px;flex-wrap:wrap;align-items:flex-start; }
.wpcc-seo-dash-grp { display:flex;align-items:center;gap:10px;flex-wrap:wrap; }
.wpcc-seo-dash-label { font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#646970; }
.wpcc-seo-stat { font-size:13px;border:1px solid #dcdcde;border-radius:4px;padding:6px 12px;background:#f6f7f7;cursor:default; }
button.wpcc-seo-stat { cursor:pointer; }
button.wpcc-seo-stat:hover { background:#fff;border-color:#8c8f94; }
.wpcc-seo-stat b { font-size:16px;margin-right:4px; }
.wpcc-seo-stat--bad b { color:#b32d2e; } .wpcc-seo-stat--warn b { color:#996800; } .wpcc-seo-stat--good b { color:#0a7c2f; }
.wpcc-seo-dash-foot { margin-top:12px;font-size:13px;color:#50575e; }
.wpcc-seo-link { background:none;border:none;color:#2271b1;cursor:pointer;padding:0;font-size:13px;text-decoration:underline; }
.wpcc-seo-link:hover { color:#135e96; }
.wpcc-seo-segbar { display:flex;gap:6px;flex-wrap:wrap;margin:8px 0 6px; }
.wpcc-seo-toast { position:fixed;right:24px;bottom:24px;z-index:99999;max-width:400px;background:#1d2327;color:#fff;border-radius:6px;padding:12px 14px;box-shadow:0 6px 24px rgba(0,0,0,.25);font-size:13px; }
.wpcc-seo-toast-row { display:flex;align-items:center;gap:10px;flex-wrap:wrap; }
.wpcc-seo-toast-msg { flex:1 1 auto; }
.wpcc-seo-toast-actions { display:flex;align-items:center;gap:8px; }
.wpcc-seo-chip { display:inline-block;font-size:11px;border-radius:10px;padding:1px 8px;background:#3c434a;color:#f0f0f1;margin-left:4px; }
.wpcc-seo-chip--good { background:#0a7c2f;color:#fff; }
.wpcc-seo-toast-view { color:#72aee6;text-decoration:underline;cursor:pointer;font-size:12px; }
.wpcc-seo-toast-x { background:none;border:none;color:#c3c4c7;cursor:pointer;font-size:16px;line-height:1;padding:0 2px; }
.wpcc-seo-toast-status:empty { display:none; }
.wpcc-seo-toast-status { font-size:12px;color:#c3c4c7;margin-top:6px; }
@media (prefers-reduced-motion: no-preference) { .wpcc-seo-toast { animation:wpcc-toast-in .18s ease-out; } }
@keyframes wpcc-toast-in { from { opacity:0;transform:translateY(8px); } to { opacity:1;transform:none; } }
</style>

<script>
( function () {
	const API   = <?php echo wp_json_encode( $api_base ); ?>;
	const CORE  = <?php echo wp_json_encode( $core_base ); ?>;
	const EDIT  = <?php echo wp_json_encode( $edit_base ); ?>;
	const AI_URL = <?php echo wp_json_encode( $ai_url ); ?>;
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
		// U3 — action-first dashboard.
		dashNeedsYou:  <?php echo wp_json_encode( esc_html__( 'Needs you', 'wp-command-center' ) ); ?>,
		dashHealthy:   <?php echo wp_json_encode( esc_html__( 'Healthy', 'wp-command-center' ) ); ?>,
		stMissing:     <?php echo wp_json_encode( esc_html__( 'Missing', 'wp-command-center' ) ); ?>,
		stNeedsWork:   <?php echo wp_json_encode( esc_html__( 'Needs work', 'wp-command-center' ) ); ?>,
		stOptimized:   <?php echo wp_json_encode( esc_html__( 'Optimized', 'wp-command-center' ) ); ?>,
		dashSugReady:  <?php echo wp_json_encode( esc_html__( 'suggestions ready', 'wp-command-center' ) ); ?>,
		dashApplied:   <?php echo wp_json_encode( esc_html__( 'applied (reversible)', 'wp-command-center' ) ); ?>,
		/* translators: %d: optimized percentage (literal percent sign follows) */
		dashPct:       <?php echo wp_json_encode( __( '%d% optimized', 'wp-command-center' ) ); ?>,
		/* translators: %d: number of published items */
		dashPublished: <?php echo wp_json_encode( __( '%d published', 'wp-command-center' ) ); ?>,
		// U1.2 — Generate → Suggestions handoff.
		/* translators: %d: suggestions created */
		viewSug:       <?php echo wp_json_encode( __( 'Review %d suggestions →', 'wp-command-center' ) ); ?>,
		// U1.4 — no AI provider connected.
		noKey:         <?php echo wp_json_encode( esc_html__( 'No AI provider is connected, so no suggestions were generated. Add an Anthropic API key, then try again.', 'wp-command-center' ) ); ?>,
		aiIntegrations:<?php echo wp_json_encode( esc_html__( 'Open AI Integrations', 'wp-command-center' ) ); ?>,
		// Contextual row-action result notices (wpcc_seo_gen redirect codes).
		genCreated:    <?php echo wp_json_encode( esc_html__( 'SEO suggestion created. Review it below and apply when you’re ready.', 'wp-command-center' ) ); ?>,
		genExists:     <?php echo wp_json_encode( esc_html__( 'This item already has an open suggestion — review it below.', 'wp-command-center' ) ); ?>,
		genNoProvider: <?php echo wp_json_encode( esc_html__( 'No AI provider is connected, so nothing was generated. Add an Anthropic API key in AI Integrations.', 'wp-command-center' ) ); ?>,
		genNoPlugin:   <?php echo wp_json_encode( esc_html__( 'No supported SEO plugin (Rank Math or Yoast SEO) is active.', 'wp-command-center' ) ); ?>,
		genUnsupported: <?php echo wp_json_encode( esc_html__( 'Some items have a status that cannot receive SEO suggestions (e.g. trashed or auto-draft) and were skipped. Draft, pending, scheduled, private, and published content are all supported.', 'wp-command-center' ) ); ?>,
		genFailed:     <?php echo wp_json_encode( esc_html__( 'Couldn’t generate a suggestion. Please try again.', 'wp-command-center' ) ); ?>,
		// Bulk-action result summary (wpcc_seo_bulk redirect).
		/* translators: %1$d created, %2$d skipped, %3$d failed */
		bulkSummary:   <?php echo wp_json_encode( __( '%1$d suggestions created · %2$d skipped · %3$d failed. Review and apply below.', 'wp-command-center' ) ); ?>,
		bulkAllExist:  <?php echo wp_json_encode( esc_html__( 'All selected items already have open suggestions — review them below.', 'wp-command-center' ) ); ?>,
		bulkNone:      <?php echo wp_json_encode( esc_html__( 'No suggestions were created for the selected items.', 'wp-command-center' ) ); ?>,
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
		// Slice 5a — page-scoped bulk apply/dismiss (Suggestions tab).
		selectSug:   <?php echo wp_json_encode( esc_html__( 'Select suggestion', 'wp-command-center' ) ); ?>,
		applySel:    <?php echo wp_json_encode( esc_html__( 'Apply selected', 'wp-command-center' ) ); ?>,
		dismissSel:  <?php echo wp_json_encode( esc_html__( 'Dismiss selected', 'wp-command-center' ) ); ?>,
		bulkProcessing: <?php echo wp_json_encode( esc_html__( 'Processing', 'wp-command-center' ) ); ?>,
		bulkDone:    <?php echo wp_json_encode( esc_html__( 'processed', 'wp-command-center' ) ); ?>,
		lblApplied:  <?php echo wp_json_encode( esc_html__( 'applied', 'wp-command-center' ) ); ?>,
		lblPending:  <?php echo wp_json_encode( esc_html__( 'submitted', 'wp-command-center' ) ); ?>,
		lblDismissed:<?php echo wp_json_encode( esc_html__( 'dismissed', 'wp-command-center' ) ); ?>,
		lblFailed:   <?php echo wp_json_encode( esc_html__( 'failed', 'wp-command-center' ) ); ?>,
		/* translators: %d: number of selected suggestions */
		confirmBulkApplyDev:  <?php echo wp_json_encode( __( 'Apply the %d selected suggestions now? Each is applied individually and can be undone.', 'wp-command-center' ) ); ?>,
		/* translators: %d: number of selected suggestions */
		confirmBulkApplyGate: <?php echo wp_json_encode( __( 'Submit the %d selected suggestions for approval? Each becomes its own approval request.', 'wp-command-center' ) ); ?>,
		/* translators: %d: number of selected suggestions */
		confirmBulkDismiss:   <?php echo wp_json_encode( __( 'Dismiss the %d selected suggestions? This discards the drafts.', 'wp-command-center' ) ); ?>,
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
		noApplied: <?php echo wp_json_encode( esc_html__( 'Nothing applied yet.', 'wp-command-center' ) ); ?>,
		// Slice 4b — per-item Undo (reuses the governed change-history rollback).
		undo:      <?php echo wp_json_encode( esc_html__( 'Undo', 'wp-command-center' ) ); ?>,
		undoSent:  <?php echo wp_json_encode( esc_html__( 'Undo sent for approval', 'wp-command-center' ) ); ?>,
		cantUndo:  <?php echo wp_json_encode( esc_html__( 'Couldn’t undo', 'wp-command-center' ) ); ?>,
		colActions:<?php echo wp_json_encode( esc_html__( 'Actions', 'wp-command-center' ) ); ?>,
		// Trust polish — post-apply confirmation toast (reversibility + audit affordances).
		toastApplied:   <?php echo wp_json_encode( esc_html__( 'Applied successfully', 'wp-command-center' ) ); ?>,
		toastSubmitted: <?php echo wp_json_encode( esc_html__( 'Submitted for approval', 'wp-command-center' ) ); ?>,
		chipReversible: <?php echo wp_json_encode( esc_html__( 'Reversible', 'wp-command-center' ) ); ?>,
		chipAudited:    <?php echo wp_json_encode( esc_html__( 'Audited', 'wp-command-center' ) ); ?>,
		toastView:      <?php echo wp_json_encode( esc_html__( 'View in Applied', 'wp-command-center' ) ); ?>,
		toastUndone:    <?php echo wp_json_encode( esc_html__( 'Reverted', 'wp-command-center' ) ); ?>,
		toastClose:     <?php echo wp_json_encode( esc_html__( 'Dismiss notification', 'wp-command-center' ) ); ?>
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

	// U1.3 — tab count badge helper.
	function setTabCount( id, n ) { const el = $( id ); if ( el ) { el.textContent = ( n > 0 ? ' ' + n : '' ); el.style.display = ( n > 0 ? '' : 'none' ); } }

	// U1.3 / U3 — refresh Suggestions + Applied counts (used by tab badges AND the
	// dashboard footer). Reuses the EXISTING proposal list route (limit=1 → total_count).
	function updateTabCounts() {
		api( '/proposals?status=draft&operation_id=seo_manage&limit=1' ).then( ( r ) => {
			const n = ( r.ok && r.data && r.data.total_count ) || 0;
			setTabCount( 'wpcc-seo-tabcount-suggestions', n );
			const f = $( 'wpcc-seo-dash-sug' ); if ( f ) { f.textContent = n; }
		} );
		api( '/proposals?status=applied&operation_id=seo_manage&limit=1' ).then( ( r ) => {
			const n = ( r.ok && r.data && r.data.total_count ) || 0;
			setTabCount( 'wpcc-seo-tabcount-applied', n );
			const f = $( 'wpcc-seo-dash-applied' ); if ( f ) { f.textContent = n; }
		} );
	}

	// U3 — action-first dashboard: progress bar + "Needs you" (clickable Missing /
	// Needs work, which set the filter) + "Healthy" (Optimized count) + a footer with
	// Suggestions-ready / Applied counts that deep-link to those tabs.
	function renderReadiness( s ) {
		if ( ! s ) { setHtml( 'wpcc-seo-readiness', '' ); return; }
		const pct     = s.optimized_pct != null ? s.optimized_pct : 0;
		const missing = s.missing != null ? s.missing : 0;
		const weak    = s.weak != null ? s.weak : 0;
		const ok      = s.ok != null ? s.ok : 0;
		const total   = s.total_content != null ? s.total_content : 0;
		setTabCount( 'wpcc-seo-tabcount-review', missing + weak );
		setHtml( 'wpcc-seo-readiness',
			'<div class="wpcc-seo-dash">' +
				'<div class="wpcc-seo-dash-bar"><div class="wpcc-seo-dash-fill" style="width:' + esc( pct + '' ) + '%"></div></div>' +
				'<div class="wpcc-seo-dash-head">' + esc( STR.dashPct.replace( '%d', pct ) ) + ' · ' + esc( STR.dashPublished.replace( '%d', total ) ) + '</div>' +
				'<div class="wpcc-seo-dash-groups">' +
					'<div class="wpcc-seo-dash-grp"><span class="wpcc-seo-dash-label">' + esc( STR.dashNeedsYou ) + '</span>' +
						'<button type="button" class="wpcc-seo-stat wpcc-seo-stat--bad" data-filter="missing"><b>' + esc( missing + '' ) + '</b>' + esc( STR.stMissing ) + '</button>' +
						'<button type="button" class="wpcc-seo-stat wpcc-seo-stat--warn" data-filter="weak"><b>' + esc( weak + '' ) + '</b>' + esc( STR.stNeedsWork ) + '</button>' +
					'</div>' +
					'<div class="wpcc-seo-dash-grp"><span class="wpcc-seo-dash-label">' + esc( STR.dashHealthy ) + '</span>' +
						'<span class="wpcc-seo-stat wpcc-seo-stat--good"><b>' + esc( ok + '' ) + '</b>' + esc( STR.stOptimized ) + '</span>' +
					'</div>' +
				'</div>' +
				'<div class="wpcc-seo-dash-foot">' +
					'<button type="button" class="wpcc-seo-link" data-go="suggestions"><span id="wpcc-seo-dash-sug">0</span> ' + esc( STR.dashSugReady ) + '</button>' +
					' · ' +
					'<button type="button" class="wpcc-seo-link" data-go="applied"><span id="wpcc-seo-dash-applied">0</span> ' + esc( STR.dashApplied ) + '</button>' +
				'</div>' +
			'</div>'
		);
		updateTabCounts();
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
		hideGenNotice();
		const status = $( 'wpcc-seo-gen-status' ); if ( status ) { status.textContent = STR.generating; }
		api( '/seo/generate', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( { post_ids: ids } ) } )
			.then( ( res ) => {
				genBusy = false;
				const d = res.data || {};
				if ( ! res.ok ) { if ( status ) { status.textContent = STR.genErr; } refreshGenerate(); return; }
				const created = d.created || [], skipped = d.skipped || [], failed = d.failed || [];
				const c = created.length, sk = skipped.length, f = failed.length;
				// U1.4 — nothing created AND a no_provider skip → the AI key isn't connected.
				const noProvider = ( c === 0 ) && skipped.some( ( s ) => s && s.reason === 'no_provider' );
				if ( noProvider ) {
					if ( status ) { status.textContent = ''; }
					showGenNotice();
					refreshGenerate();
					return;
				}
				if ( status ) { status.textContent = STR.genDone.replace( '%1$d', c ).replace( '%2$d', sk ).replace( '%3$d', f ); }
				pg.offset = 0; load(); // refresh audit + counts
				// U1.2 — handoff: when suggestions were created, move the user to them.
				if ( c > 0 ) { switchTab( 'suggestions' ); }
				else { refreshGenerate(); updateTabCounts(); }
			} )
			.catch( () => { genBusy = false; if ( status ) { status.textContent = STR.genErr; } refreshGenerate(); } );
	}
	// U1.4 — no-AI-provider guidance with a link to AI Integrations (server-provided URL).
	function showGenNotice() {
		const el = $( 'wpcc-seo-gen-notice' ); if ( ! el ) { return; }
		el.innerHTML = '<p>' + esc( STR.noKey ) + ' <a href="' + esc( AI_URL ) + '">' + esc( STR.aiIntegrations ) + '</a></p>';
		el.style.display = '';
	}
	function hideGenNotice() {
		const el = $( 'wpcc-seo-gen-notice' ); if ( el ) { el.style.display = 'none'; el.innerHTML = ''; }
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
			// proposal_id is an OPAQUE DOM key only (edit/dismiss/bulk); never displayed.
			return '<tr data-id="' + esc( p.proposal_id ) + '" data-tid="' + esc( tid ) + '">' +
				'<td><input type="checkbox" class="wpcc-seo-sg-cb" aria-label="' + esc( STR.selectSug ) + '"></td>' +
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
		// New page of rows → clear any prior selection and re-evaluate the bulk bar.
		if ( $( 'wpcc-seo-sg-selectall' ) ) { $( 'wpcc-seo-sg-selectall' ).checked = false; }
		sgRefreshBulk();
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
		setHtml( 'wpcc-seo-sg-rows', '<tr><td colspan="5">' + esc( STR.loading ) + '</td></tr>' );
		api( '/proposals?status=draft&operation_id=seo_manage&limit=' + LIMIT + '&offset=' + sgOffset )
			.then( ( res ) => {
				if ( ! res.ok ) { setHtml( 'wpcc-seo-sg-rows', '<tr><td colspan="5" style="color:#b32d2e;">' + esc( STR.error ) + '</td></tr>' ); return; }
				const d = res.data || {}, list = d.proposals || [];
				sgTotal = d.total_count || 0; sgReturned = d.returned || list.length; sgHasMore = !! d.has_more;
				if ( ! list.length ) { setHtml( 'wpcc-seo-sg-rows', '<tr><td colspan="5">' + esc( STR.noSug ) + '</td></tr>' ); setHtml( 'wpcc-seo-sg-pager', '' ); $( 'wpcc-seo-sg-status' ).textContent = ''; if ( $( 'wpcc-seo-sg-selectall' ) ) { $( 'wpcc-seo-sg-selectall' ).checked = false; } sgRefreshBulk(); return; }
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
			.catch( () => { setHtml( 'wpcc-seo-sg-rows', '<tr><td colspan="5" style="color:#b32d2e;">' + esc( STR.error ) + '</td></tr>' ); } );
	}

	// ---------- SUGGESTIONS TAB (Slice 5a): page-scoped bulk Apply / Dismiss ----------
	// UI-only orchestration. Bulk Apply/Dismiss are SEQUENTIAL loops over the EXISTING
	// per-proposal routes (/proposals/{id}/apply, /proposals/{id}/dismiss). No new
	// endpoint, no batch object, no batch approval, no batch rollback, and no
	// cross-page / criteria-based selection. Each item is governed individually (its own
	// capability check, approval gate, change_id, rollback). Per-item failure NEVER
	// aborts the run. Acts ONLY on checked rows of the currently rendered page.
	let sgBusy = false;
	function sgSelectedIds() {
		return Array.prototype.slice.call( document.querySelectorAll( '#wpcc-seo-sg-rows .wpcc-seo-sg-cb:checked' ) )
			.map( ( c ) => ( c.closest ? c.closest( 'tr[data-id]' ) : null ) ).filter( Boolean )
			.map( ( r ) => r.getAttribute( 'data-id' ) );
	}
	function sgRowById( id ) {
		const rows = document.querySelectorAll( '#wpcc-seo-sg-rows tr[data-id]' );
		for ( let i = 0; i < rows.length; i++ ) { if ( rows[ i ].getAttribute( 'data-id' ) === id ) { return rows[ i ]; } }
		return null;
	}
	function sgRefreshBulk() {
		const ap = $( 'wpcc-seo-sg-apply' ), di = $( 'wpcc-seo-sg-dismiss' );
		if ( ! ap || ! di ) { return; }
		const n = sgSelectedIds().length;
		ap.disabled = ( n === 0 ) || sgBusy;
		di.disabled = ( n === 0 ) || sgBusy;
		ap.textContent = n > 0 ? STR.applySel + ' (' + n + ')' : STR.applySel;
		di.textContent = n > 0 ? STR.dismissSel + ' (' + n + ')' : STR.dismissSel;
	}
	function sgProgress( text ) {
		const p = $( 'wpcc-seo-sg-progress' ); if ( ! p ) { return; }
		p.style.display = 'block'; p.textContent = text;
	}
	// Process ids one at a time (sequential); isolate per-item failure.
	function sgRunSeq( ids, worker, onEach, onDone ) {
		let i = 0;
		( function step() {
			if ( i >= ids.length ) { onDone(); return; }
			const id = ids[ i ];
			worker( id ).then( ( outcome ) => { onEach( id, outcome ); i++; step(); } );
		} )();
	}
	function sgBulkApply( ids ) {
		sgBusy = true; sgRefreshBulk();
		const total = ids.length; let processed = 0, applied = 0, pending = 0, failed = 0;
		const tick = () => sgProgress( STR.bulkProcessing + ' ' + processed + '/' + total + ' — ' +
			applied + ' ' + STR.lblApplied + ', ' + pending + ' ' + STR.lblPending + ', ' + failed + ' ' + STR.lblFailed );
		tick();
		sgRunSeq( ids,
			( id ) => {
				const row = sgRowById( id ); const msg = row ? row.querySelector( '.wpcc-seo-rowmsg' ) : null;
				if ( msg ) { msg.textContent = '…'; }
				// EXISTING per-proposal apply route — outcome read from the response, never the mode.
				return api( '/proposals/' + encodeURIComponent( id ) + '/apply', { method: 'POST' } )
					.then( ( res ) => ( { st: ( res.data && res.data.status ) || '' } ) )
					.catch( () => ( { st: '' } ) );
			},
			( id, outcome ) => {
				processed++;
				const row = sgRowById( id ); const msg = row ? row.querySelector( '.wpcc-seo-rowmsg' ) : null;
				if ( outcome.st === 'applied' ) { applied++; if ( row && row.parentNode ) { row.parentNode.removeChild( row ); } }
				else if ( outcome.st === 'pending_approval' ) { pending++; if ( row && row.parentNode ) { row.parentNode.removeChild( row ); } }
				else { failed++; if ( msg ) { msg.textContent = STR.cantApply; } }
				tick();
			},
			() => {
				sgBusy = false;
				sgProgress( total + ' ' + STR.bulkDone + ' — ' + applied + ' ' + STR.lblApplied + ', ' +
					pending + ' ' + STR.lblPending + ', ' + failed + ' ' + STR.lblFailed + '.' );
				if ( $( 'wpcc-seo-sg-selectall' ) ) { $( 'wpcc-seo-sg-selectall' ).checked = false; }
				sgRefreshBulk();
				updateTabCounts(); // U1.3 — refresh Suggestions/Applied badges after bulk apply.
			}
		);
	}
	function sgBulkDismiss( ids ) {
		sgBusy = true; sgRefreshBulk();
		const total = ids.length; let processed = 0, dismissed = 0, failed = 0;
		const tick = () => sgProgress( STR.bulkProcessing + ' ' + processed + '/' + total + ' — ' +
			dismissed + ' ' + STR.lblDismissed + ', ' + failed + ' ' + STR.lblFailed );
		tick();
		sgRunSeq( ids,
			( id ) => {
				const row = sgRowById( id ); const msg = row ? row.querySelector( '.wpcc-seo-rowmsg' ) : null;
				if ( msg ) { msg.textContent = '…'; }
				// EXISTING per-proposal dismiss route.
				return api( '/proposals/' + encodeURIComponent( id ) + '/dismiss', { method: 'POST' } )
					.then( ( res ) => ( { ok: res.ok } ) ).catch( () => ( { ok: false } ) );
			},
			( id, outcome ) => {
				processed++;
				const row = sgRowById( id ); const msg = row ? row.querySelector( '.wpcc-seo-rowmsg' ) : null;
				if ( outcome.ok ) { dismissed++; if ( row && row.parentNode ) { row.parentNode.removeChild( row ); } }
				else { failed++; if ( msg ) { msg.textContent = STR.error; } }
				tick();
			},
			() => {
				sgBusy = false;
				sgProgress( total + ' ' + STR.bulkDone + ' — ' + dismissed + ' ' + STR.lblDismissed + ', ' + failed + ' ' + STR.lblFailed + '.' );
				if ( $( 'wpcc-seo-sg-selectall' ) ) { $( 'wpcc-seo-sg-selectall' ).checked = false; }
				sgRefreshBulk();
				updateTabCounts(); // U1.3 — refresh Suggestions/Applied badges after bulk dismiss.
			}
		);
	}

	// Trust fix A: build the governed final_payload from the row's CURRENTLY VISIBLE
	// (possibly unsaved) field values, and persist it via the EXISTING PUT route.
	// Shared by Save AND Apply so Apply can never ignore an unsaved edit.
	function rowFinalPayload( row, tid ) {
		return { action: 'seo_update', content_id: tid, seo: {
			title: row.querySelector( '.wpcc-seo-et' ).value,
			description: row.querySelector( '.wpcc-seo-ed' ).value
		} };
	}
	function persistRow( id, row, tid ) {
		return api( '/proposals/' + encodeURIComponent( id ), {
			method: 'PUT', headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { final_payload: rowFinalPayload( row, tid ) } )
		} );
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
			if ( msg ) { msg.textContent = '…'; }
			persistRow( id, row, tid )
				.then( ( res ) => { if ( msg ) { msg.textContent = res.ok ? STR.saved : ( ( res.data && res.data.message ) || STR.error ); } } )
				.catch( () => { if ( msg ) { msg.textContent = STR.error; } } );
		} else if ( t.classList.contains( 'wpcc-seo-dismiss' ) ) {
			api( '/proposals/' + encodeURIComponent( id ) + '/dismiss', { method: 'POST' } )
				.then( ( res ) => { if ( res.ok ) { row.parentNode.removeChild( row ); } else if ( msg ) { msg.textContent = ( res.data && res.data.message ) || STR.error; } } )
				.catch( () => { if ( msg ) { msg.textContent = STR.error; } } );
		} else if ( t.classList.contains( 'wpcc-seo-apply' ) ) {
			// Approve & Apply (developer) / Submit for approval (client/enterprise).
			// Trust fix A: PERSIST the visible (possibly unsaved) field values as
			// final_payload via the EXISTING governed PUT route BEFORE applying, so
			// Apply never silently applies a stale AI suggestion. ProposalApplyService
			// then applies that final_payload — Propose ≠ Apply preserved; no bypass.
			// Outcome is driven by the API response (applied | pending_approval), never
			// assumed from the label.
			t.disabled = true;
			if ( msg ) { msg.textContent = '…'; }
			persistRow( id, row, tid )
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
						showApplyToast( st, cid ); // Trust fix B — confirmation + reversibility
						updateTabCounts();
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
		if ( ! list.length ) { setHtml( 'wpcc-seo-ap-rows', '<tr><td colspan="4">' + esc( STR.noApplied ) + '</td></tr>' ); return; }
		setHtml( 'wpcc-seo-ap-rows', list.map( ( p ) => {
			const tid = parseInt( p.target_id, 10 );
			const c = ctx[ tid ] || {};
			const title = c.title || ( '#' + tid );
			const editLink = EDIT + '?post=' + encodeURIComponent( tid ) + '&action=edit';
			let badge, reversible = false;
			if ( p.status === 'pending_approval' ) { badge = apBadge( '#bd8600', STR.stAwaiting ); }
			else if ( p.status === 'failed' ) { badge = apBadge( '#b32d2e', STR.stFailed ); }
			else if ( p.status === 'applied' && p.change_status === 'rolled_back' ) { badge = apBadge( '#646970', STR.stReverted ); }
			else { badge = apBadge( '#1a7f37', STR.stApplied ); reversible = ( p.status === 'applied' && !! p.change_id ); }
			// Slice 4b — Undo ONLY for applied + reversible (not yet rolled back) rows
			// that carry a change_id. change_id is an OPAQUE handle for the single Undo
			// action (never displayed). Reuses POST /admin/history/{change_id}/rollback
			// → change_history → seo_restore. Pending/failed/reverted rows get no Undo.
			const cid = reversible ? ' data-cid="' + esc( p.change_id ) + '"' : '';
			const actions = reversible
				? '<button type="button" class="button button-small wpcc-seo-undo">' + esc( STR.undo ) + '</button><div class="wpcc-seo-rowmsg" role="status"></div>'
				: '';
			return '<tr' + cid + '>' +
				'<td><strong><a href="' + esc( editLink ) + '">' + esc( title ) + '</a></strong><div class="wpcc-seo-meta">' + esc( c.type || '' ) + '</div></td>' +
				'<td class="wpcc-seo-meta">' + appliedMeta( p ) + '</td>' +
				'<td>' + badge + '</td>' +
				'<td>' + actions + '</td>' +
				'</tr>';
		} ).join( '' ) );
	}
	// Segmented single-status pagination. Each segment (applied | pending_approval |
	// failed) is ONE paginated read over the EXISTING /admin/proposals route + canonical
	// envelope (total_count/returned/has_more/offset). No 3-read merge, no limit=50
	// truncation, no new route. ProposalAdminQuery enriches applied rows with
	// change_status (rollback-aware). loadApplied() keeps its name so the Undo handler
	// and the tab switch reload the current segment/page transparently.
	let apSeg = 'applied';            // applied | pending_approval | failed
	let apOffset = 0, apTotal = 0, apReturned = 0, apHasMore = false;
	const AP_LIMIT = LIMIT;           // 20, consistent with Review/Suggestions

	function renderAppliedPager() {
		if ( apTotal <= AP_LIMIT && apOffset === 0 ) { setHtml( 'wpcc-seo-ap-pager', '' ); return; }
		const prevDis = apOffset <= 0 ? ' disabled' : ''; const nextDis = apHasMore ? '' : ' disabled';
		setHtml( 'wpcc-seo-ap-pager',
			'<button type="button" class="button" id="wpcc-seo-ap-prev"' + prevDis + '>' + esc( STR.prev ) + '</button>' +
			'<button type="button" class="button" id="wpcc-seo-ap-next"' + nextDis + '>' + esc( STR.next ) + '</button>' );
		const p = $( 'wpcc-seo-ap-prev' ), n = $( 'wpcc-seo-ap-next' );
		if ( p ) { p.addEventListener( 'click', () => { if ( apOffset > 0 ) { apOffset = Math.max( 0, apOffset - AP_LIMIT ); loadApplied(); } } ); }
		if ( n ) { n.addEventListener( 'click', () => { if ( apHasMore ) { apOffset += AP_LIMIT; loadApplied(); } } ); }
	}
	function switchApSeg( seg ) {
		apSeg = seg; apOffset = 0;
		document.querySelectorAll( '#wpcc-seo-ap-segbar .wpcc-seo-seg' ).forEach( ( b ) => {
			b.classList.toggle( 'button-primary', b.getAttribute( 'data-seg' ) === seg );
		} );
		loadApplied();
	}
	function loadApplied() {
		setHtml( 'wpcc-seo-ap-rows', '<tr><td colspan="4">' + esc( STR.loading ) + '</td></tr>' );
		api( '/proposals?status=' + encodeURIComponent( apSeg ) + '&operation_id=seo_manage&limit=' + AP_LIMIT + '&offset=' + apOffset )
			.then( ( res ) => {
				if ( ! res.ok ) { setHtml( 'wpcc-seo-ap-rows', '<tr><td colspan="4" style="color:#b32d2e;">' + esc( STR.error ) + '</td></tr>' ); setHtml( 'wpcc-seo-ap-pager', '' ); $( 'wpcc-seo-ap-status' ).textContent = ''; return; }
				const d = res.data || {}, list = d.proposals || [];
				apTotal = d.total_count || 0; apReturned = d.returned || list.length; apHasMore = !! d.has_more;
				if ( ! list.length ) { setHtml( 'wpcc-seo-ap-rows', '<tr><td colspan="4">' + esc( STR.noApplied ) + '</td></tr>' ); setHtml( 'wpcc-seo-ap-pager', '' ); $( 'wpcc-seo-ap-status' ).textContent = ''; return; }
				const ids = list.map( ( p ) => parseInt( p.target_id, 10 ) ).filter( ( n ) => n > 0 );
				const csv = ids.join( ',' );
				Promise.all( [
					coreGet( '/posts?include=' + csv + '&per_page=' + ids.length + '&_fields=id,title,type' ),
					coreGet( '/pages?include=' + csv + '&per_page=' + ids.length + '&_fields=id,title,type' )
				] ).then( ( r ) => {
					const ctx = {};
					[ r[0].data, r[1].data ].forEach( ( arr ) => { ( Array.isArray( arr ) ? arr : [] ).forEach( ( m ) => { ctx[ m.id ] = { title: ( m.title && m.title.rendered ) || '', type: m.type || '' }; } ); } );
					renderApplied( list, ctx );
					$( 'wpcc-seo-ap-status' ).textContent = STR.pageInfo.replace( '%1$d', apOffset + 1 ).replace( '%2$d', apOffset + apReturned ).replace( '%3$d', apTotal );
					renderAppliedPager();
				} ).catch( () => { renderApplied( list, {} ); renderAppliedPager(); } );
			} )
			.catch( () => { setHtml( 'wpcc-seo-ap-rows', '<tr><td colspan="4" style="color:#b32d2e;">' + esc( STR.error ) + '</td></tr>' ); setHtml( 'wpcc-seo-ap-pager', '' ); } );
	}

	// ---------- APPLIED TAB (Slice 4b): per-item Undo ----------
	// Reuses the EXISTING governed change-history rollback route only:
	//   POST /admin/history/{change_id}/rollback
	// The change_history operation resolves the owning change and reverses it via its
	// seo_restore action — the same governed chokepoint as apply (capability +
	// approval + audit + rollback all inherited). Developer → immediate revert
	// (status flips to "Reverted" on reload); client/enterprise → pending_approval
	// ("Undo sent for approval"). No bulk, no cross-page selection, no direct
	// executor call, no direct seo write.
	// SINGLE governed rollback path — reused by BOTH the Applied-tab per-item Undo and
	// the post-apply toast Undo (Fix B). Reuses ONLY the existing rollback route; there
	// is no second rollback path. change_history → seo_restore (capability + approval +
	// audit + rollback all inherited).
	function rollbackChange( cid ) {
		return fetch( API + '/history/' + encodeURIComponent( cid ) + '/rollback', { method: 'POST', headers: { 'X-WP-Nonce': NONCE } } )
			.then( ( r ) => r.json().then( ( d ) => ( { ok: r.ok, data: d } ), () => ( { ok: r.ok, data: {} } ) ) );
	}
	function rollbackOutcome( res ) {
		const inner = ( res.data && res.data.result ) || {};
		if ( inner.status === 'pending_approval' ) { return 'pending'; } // gated → sent for approval
		if ( res.ok && res.data && res.data.success === true && inner.status !== 'confirmation_required' ) { return 'reverted'; }
		return 'failed';
	}

	// Fix B — post-apply confirmation toast: "Applied successfully · Reversible ·
	// Audited" with an inline Undo (developer/applied + change_id) that calls the shared
	// rollbackChange(). Gated apply → "Submitted for approval · Audited" (no Undo yet).
	function showApplyToast( status, cid ) {
		const el = $( 'wpcc-seo-toast' );
		if ( ! el ) { return; }
		const applied = ( status === 'applied' );
		let h = '<div class="wpcc-seo-toast-row">';
		h += '<span class="wpcc-seo-toast-msg"><strong>' + esc( applied ? STR.toastApplied : STR.toastSubmitted ) + '</strong>';
		if ( applied ) { h += '<span class="wpcc-seo-chip wpcc-seo-chip--good">' + esc( STR.chipReversible ) + '</span>'; }
		h += '<span class="wpcc-seo-chip">' + esc( STR.chipAudited ) + '</span></span>';
		h += '<span class="wpcc-seo-toast-actions">';
		if ( applied && cid ) {
			h += '<button type="button" class="button button-small wpcc-seo-toast-undo" data-cid="' + esc( cid ) + '">' + esc( STR.undo ) + '</button>';
		}
		h += '<a href="#" class="wpcc-seo-toast-view" data-go="applied">' + esc( STR.toastView ) + '</a>';
		h += '<button type="button" class="wpcc-seo-toast-x" aria-label="' + esc( STR.toastClose ) + '">×</button>';
		h += '</span></div><div class="wpcc-seo-toast-status"></div>';
		el.innerHTML = h;
		el.style.display = 'block';
	}

	document.addEventListener( 'click', function ( e ) {
		const t = e.target;
		if ( ! t.classList || ! t.classList.contains( 'wpcc-seo-undo' ) ) { return; }
		const row = t.closest ? t.closest( '#wpcc-seo-ap-rows tr[data-cid]' ) : null;
		if ( ! row ) { return; }
		const cid = row.getAttribute( 'data-cid' );
		if ( ! cid ) { return; }
		const msg = row.querySelector( '.wpcc-seo-rowmsg' );
		t.disabled = true; // disable while the request is in flight
		if ( msg ) { msg.textContent = '…'; }
		rollbackChange( cid )
			.then( ( res ) => {
				const out = rollbackOutcome( res );
				if ( out === 'pending' ) { if ( msg ) { msg.textContent = STR.undoSent; } } // gated → sent for approval, row stays
				else if ( out === 'reverted' ) { loadApplied(); } // success → reload; status flips to "Reverted"
				else { t.disabled = false; if ( msg ) { msg.textContent = STR.cantUndo; } } // non-fatal: let the operator retry
			} )
			.catch( () => { t.disabled = false; if ( msg ) { msg.textContent = STR.cantUndo; } } );
	} );

	// Fix B — toast actions: Undo (shared rollback path), "View in Applied" deep link,
	// and Dismiss. Delegated; the toast is a single replace-on-each-apply element.
	document.addEventListener( 'click', function ( e ) {
		const el = $( 'wpcc-seo-toast' );
		if ( ! el || el.style.display === 'none' ) { return; }
		const t = e.target;
		if ( t.classList && t.classList.contains( 'wpcc-seo-toast-x' ) ) { el.style.display = 'none'; el.innerHTML = ''; return; }
		const view = t.closest ? t.closest( '.wpcc-seo-toast-view[data-go]' ) : null;
		if ( view ) { e.preventDefault(); switchTab( view.getAttribute( 'data-go' ) ); el.style.display = 'none'; el.innerHTML = ''; return; }
		if ( t.classList && t.classList.contains( 'wpcc-seo-toast-undo' ) ) {
			const cid = t.getAttribute( 'data-cid' ); if ( ! cid ) { return; }
			const st = el.querySelector( '.wpcc-seo-toast-status' );
			t.disabled = true; if ( st ) { st.textContent = '…'; }
			rollbackChange( cid )
				.then( ( res ) => {
					const out = rollbackOutcome( res );
					if ( out === 'reverted' ) {
						if ( st ) { st.textContent = STR.toastUndone; }
						t.style.display = 'none';
						updateTabCounts();
						const ap = $( 'wpcc-seo-panel-applied' );
						if ( ap && ap.style.display !== 'none' ) { loadApplied(); } // refresh if Applied tab is open
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

	function switchTab( which ) {
		$( 'wpcc-seo-panel-review' ).style.display = ( which === 'review' ) ? '' : 'none';
		$( 'wpcc-seo-panel-suggestions' ).style.display = ( which === 'suggestions' ) ? '' : 'none';
		$( 'wpcc-seo-panel-applied' ).style.display = ( which === 'applied' ) ? '' : 'none';
		$( 'wpcc-seo-tab-review' ).classList.toggle( 'nav-tab-active', which === 'review' );
		$( 'wpcc-seo-tab-suggestions' ).classList.toggle( 'nav-tab-active', which === 'suggestions' );
		$( 'wpcc-seo-tab-applied' ).classList.toggle( 'nav-tab-active', which === 'applied' );
		if ( which === 'suggestions' ) { sgOffset = 0; loadSuggestions(); }
		else if ( which === 'applied' ) { switchApSeg( 'applied' ); } // default segment + offset 0
		updateTabCounts(); // U1.3 — keep tab badges fresh on every switch.
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

	// Slice 5a — Suggestions-tab bulk wiring (page-scoped).
	if ( $( 'wpcc-seo-sg-apply' ) ) {
		$( 'wpcc-seo-sg-apply' ).addEventListener( 'click', function () {
			const ids = sgSelectedIds(); if ( ! ids.length || sgBusy ) { return; }
			// Mode-aware confirm; outcome is still read from each API response, not the mode.
			const tmpl = IS_DEV ? STR.confirmBulkApplyDev : STR.confirmBulkApplyGate;
			if ( ! window.confirm( tmpl.replace( '%d', ids.length ) ) ) { return; }
			sgBulkApply( ids );
		} );
	}
	if ( $( 'wpcc-seo-sg-dismiss' ) ) {
		$( 'wpcc-seo-sg-dismiss' ).addEventListener( 'click', function () {
			const ids = sgSelectedIds(); if ( ! ids.length || sgBusy ) { return; }
			if ( ! window.confirm( STR.confirmBulkDismiss.replace( '%d', ids.length ) ) ) { return; }
			sgBulkDismiss( ids );
		} );
	}
	if ( $( 'wpcc-seo-sg-selectall' ) ) {
		$( 'wpcc-seo-sg-selectall' ).addEventListener( 'change', function () {
			const on = this.checked;
			document.querySelectorAll( '#wpcc-seo-sg-rows .wpcc-seo-sg-cb' ).forEach( ( c ) => { c.checked = on; } );
			sgRefreshBulk();
		} );
	}
	// Per-row checkbox toggles the bulk bar + keeps select-all in sync.
	document.addEventListener( 'change', function ( e ) {
		if ( ! e.target.classList || ! e.target.classList.contains( 'wpcc-seo-sg-cb' ) ) { return; }
		sgRefreshBulk();
		const all = document.querySelectorAll( '#wpcc-seo-sg-rows .wpcc-seo-sg-cb' );
		const checked = document.querySelectorAll( '#wpcc-seo-sg-rows .wpcc-seo-sg-cb:checked' );
		const sa = $( 'wpcc-seo-sg-selectall' ); if ( sa ) { sa.checked = ( all.length > 0 && all.length === checked.length ); }
	} );

	// Applied-tab segment control: each segment is a single-status paginated list.
	document.addEventListener( 'click', function ( e ) {
		const seg = e.target.closest ? e.target.closest( '#wpcc-seo-ap-segbar .wpcc-seo-seg' ) : null;
		if ( seg ) { switchApSeg( seg.getAttribute( 'data-seg' ) ); }
	} );

	// U3 — dashboard actions: "Needs you" stats set the audit filter; footer links
	// deep-link to the Suggestions / Applied tabs. Client-side only.
	document.addEventListener( 'click', function ( e ) {
		const stat = e.target.closest ? e.target.closest( '.wpcc-seo-stat[data-filter]' ) : null;
		if ( stat ) {
			const f = stat.getAttribute( 'data-filter' );
			const sel = $( 'wpcc-seo-filter' );
			if ( sel ) { sel.value = f; pg.offset = 0; load(); }
			return;
		}
		const link = e.target.closest ? e.target.closest( '.wpcc-seo-link[data-go]' ) : null;
		if ( link ) { switchTab( link.getAttribute( 'data-go' ) ); }
	} );

	// Contextual entry points: a "Generate SEO Suggestion" row action redirects here
	// with ?tab=suggestions&wpcc_seo_gen={code}. Show the result + land on Suggestions.
	function showEntryNotice( code ) {
		const el = $( 'wpcc-seo-entry-notice' ); if ( ! el ) { return; }
		const map = {
			created:     [ 'notice-success', STR.genCreated ],
			exists:      [ 'notice-info',    STR.genExists ],
			no_provider: [ 'notice-warning', STR.genNoProvider, AI_URL, STR.aiIntegrations ],
			no_plugin:   [ 'notice-warning', STR.genNoPlugin ],
			unsupported_status:[ 'notice-warning', STR.genUnsupported ],
			failed:      [ 'notice-error',   STR.genFailed ]
		};
		const m = map[ code ]; if ( ! m ) { return; }
		el.className = 'notice inline ' + m[0];
		let html = '<p>' + esc( m[1] );
		if ( m[2] ) { html += ' <a href="' + esc( m[2] ) + '">' + esc( m[3] ) + '</a>'; }
		html += '</p>';
		el.innerHTML = html;
		el.style.display = '';
	}
	// Bulk-action result: "X created · Y skipped · Z failed", or the dominant skip
	// reason when nothing was created (Sprint B — WP Bulk Actions).
	function showBulkNotice( c, s, f, r ) {
		const el = $( 'wpcc-seo-entry-notice' ); if ( ! el ) { return; }
		let cls = 'notice-success', extraUrl = '', extraLabel = '';
		let msg = STR.bulkSummary.replace( '%1$d', c ).replace( '%2$d', s ).replace( '%3$d', f );
		if ( c === 0 ) {
			cls = 'notice-warning';
			if ( r === 'no_provider' ) { msg = STR.genNoProvider; extraUrl = AI_URL; extraLabel = STR.aiIntegrations; }
			else if ( r === 'no_seo_plugin' ) { msg = STR.genNoPlugin; }
			else if ( r === 'has_open_proposal' ) { msg = STR.bulkAllExist; cls = 'notice-info'; }
			else if ( r === 'unsupported_status' ) { msg = STR.genUnsupported; }
			else { msg = STR.bulkNone; }
		}
		el.className = 'notice inline ' + cls;
		let html = '<p>' + esc( msg );
		if ( extraUrl ) { html += ' <a href="' + esc( extraUrl ) + '">' + esc( extraLabel ) + '</a>'; }
		html += '</p>';
		el.innerHTML = html;
		el.style.display = '';
	}

	updateTabCounts(); // U1.3 — populate tab badges on first paint.
	( function () {
		const sp = new URLSearchParams( location.search );
		const code = sp.get( 'wpcc_seo_gen' );
		if ( code ) { showEntryNotice( code ); }
		if ( sp.get( 'wpcc_seo_bulk' ) ) {
			showBulkNotice(
				parseInt( sp.get( 'c' ) || '0', 10 ),
				parseInt( sp.get( 's' ) || '0', 10 ),
				parseInt( sp.get( 'f' ) || '0', 10 ),
				sp.get( 'r' ) || ''
			);
		}
		if ( sp.get( 'tab' ) === 'suggestions' ) { switchTab( 'suggestions' ); }
	} )();
	load();
} )();
</script>
