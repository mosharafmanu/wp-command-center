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
// Built-in AI keys live on Built-in AI › Providers, not on the MCP Assistants
// screen (which has no key field). See the note in seo-meta.php.
$ai_url    = esc_url( admin_url( 'admin.php?page=wpcc-settings&wpcc_tab=advanced&apane=ai&aipane=providers' ) );
/*
 * Where an awaiting-approval row sends the customer.
 *
 * A row that says "Awaiting approval" and offers nothing to click asks the
 * customer to leave Built-in AI, find the global Approvals screen in the
 * sidebar, and then identify their own item in a queue of a hundred. The
 * proposal already carries its request_id, so the row can open the exact one.
 */
$approval_url = esc_url( admin_url( 'admin.php?page=wpcc-activity&wpcc_tab=approvals' ) );
// Server-rendered security mode drives the apply button label (developer applies
// directly; client/enterprise submit for approval). The outcome is still taken from
// the apply API response (defensive) — the UI never assumes from the label.
$security_mode = \WPCommandCenter\Operations\SecurityModeManager::current();

/*
 * Whether this site can actually generate right now.
 *
 * The Content screen used to consist of two review tabs and nothing else. Its empty
 * state read "No suggestions yet. Generate some from a post or page." — a true sentence
 * that named no page, linked nowhere, and did not mention that the thing to look for is
 * a row action called "✨ Action Steward AI". SEO and Alt Text both have a Review tab where you
 * pick items and press Generate; Content was the only built-in tool with no way to start
 * from its own screen, so an independent tester enabled it, opened it, found four stale
 * drafts and concluded there was no generation path at all.
 *
 * The Review tab below is that missing step, built the same way as the other two: pick
 * eligible content, press Generate, land on Suggestions. It introduces NO backend — it
 * drives the EXISTING POST /admin/proposals generate branch, one post per call, which is
 * the same governed route the row action already used.
 */
$has_provider  = \WPCommandCenter\Admin\AdoptionStatus::ai_configured();
// One source of truth for "too little to work from" — the same number the generators
// use server-side, so the badge here and the notice after generation cannot disagree.
$thin_words    = \WPCommandCenter\Ai\SourceContentSignal::THIN_BELOW_WORDS;
?>
<div class="wrap wpcc-wrap wpcc-aic">
	<h1><?php esc_html_e( 'Content', 'action-steward' ); ?></h1>
	<p class="description" style="max-width:720px;">
		<?php esc_html_e( 'Draft titles and excerpts for your posts and pages with AI. Review and edit each suggestion, then approve to apply — you’re always in control.', 'action-steward' ); ?>
	</p>
	<?php require WPCC_PLUGIN_DIR . 'includes/Admin/views/partials/trust-strip.php'; ?>

	<h2 class="nav-tab-wrapper">
		<a href="#" class="nav-tab nav-tab-active" id="wpcc-aic-tab-review"><?php esc_html_e( 'Review', 'action-steward' ); ?></a>
		<a href="#" class="nav-tab" id="wpcc-aic-tab-suggestions"><?php esc_html_e( 'Suggestions', 'action-steward' ); ?><span class="wpcc-aic-tabcount" id="wpcc-aic-tabcount-suggestions"></span></a>
		<a href="#" class="nav-tab" id="wpcc-aic-tab-applied"><?php esc_html_e( 'Applied', 'action-steward' ); ?><span class="wpcc-aic-tabcount" id="wpcc-aic-tabcount-applied"></span></a>
	</h2>

	<?php // Result of a contextual "Generate" row/bulk action (set via the wpcc_content_gen
	// / wpcc_content_bulk query args on redirect; rendered client-side, escaped). ?>
	<div id="wpcc-aic-entry-notice" class="notice inline" role="status" aria-live="polite" style="display:none;margin:10px 0;"></div>

	<!-- ============ REVIEW TAB (pick content → generate) ============ -->
	<div id="wpcc-aic-panel-review">
		<?php if ( ! $has_provider ) : ?>
			<?php // Honest precondition. A tool that is on but has no key generates nothing,
			// and saying so here beats letting someone select five posts and press a button
			// that can only fail. ?>
			<div class="notice notice-warning inline" style="margin:12px 0;max-width:1100px;">
				<p>
					<strong><?php esc_html_e( 'No AI provider key yet.', 'action-steward' ); ?></strong>
					<?php esc_html_e( 'Content is switched on, but generating a title or excerpt needs your own provider key. Nothing on your site has changed, and adding a key alone will not change anything either — you choose what to generate, and every suggestion still needs your approval.', 'action-steward' ); ?>
					<a href="<?php echo esc_url( $ai_url ); ?>"><?php esc_html_e( 'Add a key on Built-in AI › Providers', 'action-steward' ); ?></a>
				</p>
			</div>
		<?php endif; ?>

		<p style="margin:12px 0;max-width:900px;">
			<span class="description"><?php esc_html_e( 'Pick the posts or pages you want a suggestion for, choose whether to draft a title or an excerpt, then generate. Each suggestion is saved as a draft for you to review — nothing is applied to your site here.', 'action-steward' ); ?></span>
		</p>

		<div class="wpcc-aic-filters" id="wpcc-aic-rv-controls">
			<label for="wpcc-aic-rv-kind"><?php esc_html_e( 'Generate:', 'action-steward' ); ?></label>
			<select id="wpcc-aic-rv-kind">
				<option value="title"><?php esc_html_e( 'Titles', 'action-steward' ); ?></option>
				<option value="excerpt"><?php esc_html_e( 'Excerpts', 'action-steward' ); ?></option>
			</select>

			<label for="wpcc-aic-rv-type"><?php esc_html_e( 'From:', 'action-steward' ); ?></label>
			<select id="wpcc-aic-rv-type">
				<option value="posts"><?php esc_html_e( 'Posts', 'action-steward' ); ?></option>
				<option value="pages"><?php esc_html_e( 'Pages', 'action-steward' ); ?></option>
			</select>

			<label><input type="checkbox" id="wpcc-aic-rv-selectall"> <?php esc_html_e( 'Select all on this page', 'action-steward' ); ?></label>

			<button type="button" class="button button-primary" id="wpcc-aic-rv-generate" disabled<?php echo $has_provider ? '' : ' title="' . esc_attr__( 'Add a provider key first.', 'action-steward' ) . '"'; ?>><?php esc_html_e( 'Generate suggestions', 'action-steward' ); ?></button>
			<span class="description"><?php
				printf(
					/* translators: %d: maximum items per generation run. */
					esc_html__( 'Up to %d at a time. Suggestions are drafts — nothing is applied.', 'action-steward' ),
					25
				);
			?></span>
			<span id="wpcc-aic-rv-status" role="status" aria-live="polite" style="margin-left:auto;color:#646970;"></span>
		</div>

		<div id="wpcc-aic-rv-notice" class="notice inline" role="status" aria-live="polite" style="display:none;margin:8px 0;max-width:1100px;"></div>

		<table class="widefat striped wpcc-aic-sg-table">
			<thead>
				<tr>
					<th scope="col" style="width:34px;"><span class="screen-reader-text"><?php esc_html_e( 'Select', 'action-steward' ); ?></span></th>
					<th scope="col" style="width:30%;"><?php esc_html_e( 'Content', 'action-steward' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Current value', 'action-steward' ); ?></th>
					<th scope="col" style="width:160px;"><?php esc_html_e( 'Source content', 'action-steward' ); ?></th>
					<th scope="col" style="width:140px;"><?php esc_html_e( 'Status', 'action-steward' ); ?></th>
				</tr>
			</thead>
			<tbody id="wpcc-aic-rv-rows">
				<tr><td colspan="5"><?php esc_html_e( 'Loading…', 'action-steward' ); ?></td></tr>
			</tbody>
		</table>
		<div id="wpcc-aic-rv-pager" class="wpcc-aic-pager"></div>
	</div>

	<!-- ============ SUGGESTIONS TAB ============ -->
	<div id="wpcc-aic-panel-suggestions" style="display:none;">
		<div class="wpcc-aic-filters">
			<label for="wpcc-aic-kind"><?php esc_html_e( 'Show:', 'action-steward' ); ?></label>
			<select id="wpcc-aic-kind">
				<option value="all"><?php esc_html_e( 'All', 'action-steward' ); ?></option>
				<option value="title"><?php esc_html_e( 'Titles', 'action-steward' ); ?></option>
				<option value="excerpt"><?php esc_html_e( 'Excerpts', 'action-steward' ); ?></option>
			</select>
			<span id="wpcc-aic-sg-status" role="status" aria-live="polite" style="margin-left:12px;color:#646970;"></span>
		</div>
		<p style="margin:12px 0;">
			<span class="description"><?php esc_html_e( 'AI suggestions awaiting your review. Edit the title or excerpt, dismiss a suggestion, or apply it. Nothing is applied to your site until you choose to.', 'action-steward' ); ?></span>
		</p>
		<table class="widefat striped wpcc-aic-sg-table">
			<thead>
				<tr>
					<th scope="col" style="width:22%;"><?php esc_html_e( 'Content', 'action-steward' ); ?></th>
					<th scope="col" style="width:14%;"><?php esc_html_e( 'Field', 'action-steward' ); ?></th>
					<th scope="col" style="width:26%;"><?php esc_html_e( 'Current', 'action-steward' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Suggested (editable)', 'action-steward' ); ?></th>
					<th scope="col" style="width:150px;"><?php esc_html_e( 'Actions', 'action-steward' ); ?></th>
				</tr>
			</thead>
			<tbody id="wpcc-aic-sg-rows">
				<tr><td colspan="5"><?php esc_html_e( 'Loading…', 'action-steward' ); ?></td></tr>
			</tbody>
		</table>
		<div id="wpcc-aic-sg-pager" class="wpcc-aic-pager"></div>
	</div>

	<!-- ============ APPLIED TAB ============ -->
	<div id="wpcc-aic-panel-applied" style="display:none;">
		<p style="margin:12px 0;">
			<span class="description"><?php esc_html_e( 'Applied titles and excerpts, plus items awaiting approval.', 'action-steward' ); ?></span>
			<span id="wpcc-aic-ap-status" role="status" aria-live="polite" style="margin-left:12px;color:#646970;"></span>
		</p>
		<?php // Segmented, single-status pagination — each segment is one paginated
		// /admin/proposals read; default = Applied. ?>
		<div class="wpcc-aic-segbar" id="wpcc-aic-ap-segbar" role="group" aria-label="<?php esc_attr_e( 'Filter applied items by status', 'action-steward' ); ?>">
			<button type="button" class="button button-primary wpcc-aic-seg" data-seg="applied"><?php esc_html_e( 'Applied', 'action-steward' ); ?></button>
			<button type="button" class="button wpcc-aic-seg" data-seg="pending_approval"><?php esc_html_e( 'Awaiting approval', 'action-steward' ); ?></button>
			<button type="button" class="button wpcc-aic-seg" data-seg="failed"><?php esc_html_e( 'Failed', 'action-steward' ); ?></button>
		</div>
		<table class="widefat striped wpcc-aic-sg-table">
			<thead>
				<tr>
					<th scope="col" style="width:26%;"><?php esc_html_e( 'Content', 'action-steward' ); ?></th>
					<th scope="col" style="width:14%;"><?php esc_html_e( 'Field', 'action-steward' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Applied value', 'action-steward' ); ?></th>
					<th scope="col" style="width:160px;"><?php esc_html_e( 'Status', 'action-steward' ); ?></th>
					<th scope="col" style="width:120px;"><?php esc_html_e( 'Actions', 'action-steward' ); ?></th>
				</tr>
			</thead>
			<tbody id="wpcc-aic-ap-rows">
				<tr><td colspan="5"><?php esc_html_e( 'Loading…', 'action-steward' ); ?></td></tr>
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
.wpcc-aic-thin { display:inline-block;font-size:11px;border-radius:10px;padding:1px 8px;background:#fcf0d6;color:#8a6a00;cursor:help; }
.wpcc-aic-echo { font-size:11px;color:#8a6a00;margin-top:4px;max-width:46ch; }
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
	const APPROVAL_URL = <?php echo wp_json_encode( $approval_url ); ?>;
	const NONCE  = <?php echo wp_json_encode( $nonce ); ?>;
	const MODE   = <?php echo wp_json_encode( $security_mode ); ?>; // developer | client | enterprise
	const IS_DEV = ( MODE === 'developer' );
	const HAS_PROVIDER = <?php echo wp_json_encode( (bool) $has_provider ); ?>;
	// The generators' own threshold, passed down so the badge here and the server-side
	// notice after generation are the same judgement rather than two similar ones.
	const THIN_WORDS = <?php echo (int) $thin_words; ?>;
	const MAX_GEN = 25; // mirrors the row/bulk entry point's cap
	const LIMIT  = 20;
	const TITLE_MAX = 60, EXCERPT_MAX = 320; // advisory char-count targets
	// operation_id + per-field target_type used on EVERY proposal read/write below.
	const OP = 'content_manage';
	const TT_TITLE = 'content_title', TT_EXCERPT = 'content_excerpt';
	const STR = {
		loading:  <?php echo wp_json_encode( esc_html__( 'Loading…', 'action-steward' ) ); ?>,
		error:    <?php echo wp_json_encode( esc_html__( 'Could not load. Please retry.', 'action-steward' ) ); ?>,
		none:     <?php echo wp_json_encode( esc_html__( '(not set)', 'action-steward' ) ); ?>,
		fieldTitle:   <?php echo wp_json_encode( esc_html__( 'Title', 'action-steward' ) ); ?>,
		fieldExcerpt: <?php echo wp_json_encode( esc_html__( 'Excerpt', 'action-steward' ) ); ?>,
		byAI:     <?php echo wp_json_encode( esc_html__( 'Suggested by AI', 'action-steward' ) ); ?>,
		edited:   <?php echo wp_json_encode( esc_html__( 'Edited', 'action-steward' ) ); ?>,
		save:     <?php echo wp_json_encode( esc_html__( 'Save', 'action-steward' ) ); ?>,
		saved:    <?php echo wp_json_encode( esc_html__( 'Saved', 'action-steward' ) ); ?>,
		dismiss:  <?php echo wp_json_encode( esc_html__( 'Dismiss', 'action-steward' ) ); ?>,
		reviewApproval: <?php echo wp_json_encode( esc_html__( 'Review approval', 'action-steward' ) ); ?>,
		/* translators: %1$s: the reason items were skipped. */
		skipWhy:      <?php echo wp_json_encode( /* translators: %1$s: value */ esc_html__( 'Skipped because they %1$s.', 'action-steward' ) ); ?>,
		skHasDraft:   <?php echo wp_json_encode( esc_html__( 'already have a draft waiting for review', 'action-steward' ) ); ?>,
		skUpToDate:   <?php echo wp_json_encode( esc_html__( 'are already up to date', 'action-steward' ) ); ?>,
		skStatus:     <?php echo wp_json_encode( esc_html__( 'are trashed or not started yet', 'action-steward' ) ); ?>,
		skNoProvider: <?php echo wp_json_encode( esc_html__( 'have no provider key', 'action-steward' ) ); ?>,
		skUnsupported:<?php echo wp_json_encode( esc_html__( 'do not support this kind of suggestion', 'action-steward' ) ); ?>,
		skNotFound:   <?php echo wp_json_encode( esc_html__( 'no longer exist', 'action-steward' ) ); ?>,
		skOther:      <?php echo wp_json_encode( esc_html__( 'needed nothing generating', 'action-steward' ) ); ?>,
		skReview:     <?php echo wp_json_encode( esc_html__( 'Open Suggestions to review the drafts already waiting.', 'action-steward' ) ); ?>,
		/* translators: %s: the post or page title. */
		reviewApprovalFor: <?php echo wp_json_encode( /* translators: %s: value */ esc_html__( 'Review the approval for %s', 'action-steward' ) ); ?>,
		/* Row-level accessible names — the visible button keeps its short label. */
		/* translators: %s: the post or page title. */
		applyDevFor:  <?php echo wp_json_encode( /* translators: %s: value */ esc_html__( 'Approve and apply suggestion for %s', 'action-steward' ) ); ?>,
		/* translators: %s: the post or page title. */
		applyGateFor: <?php echo wp_json_encode( /* translators: %s: value */ esc_html__( 'Submit suggestion for %s for approval', 'action-steward' ) ); ?>,
		/* translators: %s: the post or page title. */
		saveFor:      <?php echo wp_json_encode( /* translators: %s: value */ esc_html__( 'Save edited suggestion for %s', 'action-steward' ) ); ?>,
		/* translators: %s: the post or page title. */
		dismissFor:   <?php echo wp_json_encode( /* translators: %s: value */ esc_html__( 'Dismiss suggestion for %s', 'action-steward' ) ); ?>,
		/*
		 * The old text here was "No suggestions yet. Generate some from a post or page."
		 * It was true and it was useless: it named no screen, linked nowhere, and the
		 * thing it was pointing at is a row action labelled "✨ Action Steward AI" that a first-time
		 * customer has no reason to look for. An empty state on the tool's own screen
		 * should point at the tool's own first step.
		 */
		noSug:    <?php echo wp_json_encode( esc_html__( 'No suggestions waiting. Open the Review tab to pick a post or page and generate one.', 'action-steward' ) ); ?>,
		noSugGo:  <?php echo wp_json_encode( esc_html__( 'Go to Review', 'action-steward' ) ); ?>,
		/* translators: %1$d current length, %2$d max */
		ccTitle:  <?php echo wp_json_encode( /* translators: %1$d: number, %2$d: number */ __( '%1$d / %2$d', 'action-steward' ) ); ?>,
		/* translators: %1$d current length */
		ccExcerpt: <?php echo wp_json_encode( /* translators: %1$d: number */ __( '%1$d characters', 'action-steward' ) ); ?>,
		// Apply + Applied tab (mode-aware label; outcome from response).
		/*
		 * __() not esc_html__(): every STR value is escaped again by the JS `esc()`
		 * helper at the moment it is inserted, so escaping here as well runs the
		 * string through twice. Ampersand is the only character in this block that
		 * shows it — the button rendered literally as "Approve &amp; Apply" on the
		 * suggestions table. Anything added here must stay insert-time escaped.
		 */
		applyDev:  <?php echo wp_json_encode( __( 'Approve & Apply', 'action-steward' ) ); ?>,
		applyGate: <?php echo wp_json_encode( esc_html__( 'Submit for approval', 'action-steward' ) ); ?>,
		cantApply: <?php echo wp_json_encode( esc_html__( 'Couldn’t apply', 'action-steward' ) ); ?>,
		stApplied: <?php echo wp_json_encode( esc_html__( 'Applied', 'action-steward' ) ); ?>,
		stAwaiting:<?php echo wp_json_encode( esc_html__( 'Awaiting approval', 'action-steward' ) ); ?>,
		stFailed:  <?php echo wp_json_encode( esc_html__( 'Failed', 'action-steward' ) ); ?>,
		stReverted:<?php echo wp_json_encode( esc_html__( 'Reverted', 'action-steward' ) ); ?>,
		noApplied: <?php echo wp_json_encode( esc_html__( 'Nothing applied yet.', 'action-steward' ) ); ?>,
		// Per-item Undo (reuses the governed change-history rollback).
		undo:      <?php echo wp_json_encode( esc_html__( 'Undo', 'action-steward' ) ); ?>,
		undoSent:  <?php echo wp_json_encode( esc_html__( 'Undo sent for approval', 'action-steward' ) ); ?>,
		cantUndo:  <?php echo wp_json_encode( esc_html__( 'Couldn’t undo', 'action-steward' ) ); ?>,
		prev:     <?php echo wp_json_encode( esc_html__( '← Previous', 'action-steward' ) ); ?>,
		next:     <?php echo wp_json_encode( esc_html__( 'Next →', 'action-steward' ) ); ?>,
		/* translators: %1$d first row, %2$d last row, %3$d total */
		pageInfo: <?php echo wp_json_encode( /* translators: %1$d: number, %2$d: number, %3$d: number */ __( 'Showing %1$d–%2$d of %3$d', 'action-steward' ) ); ?>,
		// Post-apply confirmation toast.
		toastApplied:   <?php echo wp_json_encode( esc_html__( 'Applied successfully', 'action-steward' ) ); ?>,
		toastSubmitted: <?php echo wp_json_encode( esc_html__( 'Submitted for approval', 'action-steward' ) ); ?>,
		chipReversible: <?php echo wp_json_encode( esc_html__( 'Reversible', 'action-steward' ) ); ?>,
		chipAudited:    <?php echo wp_json_encode( esc_html__( 'Audited', 'action-steward' ) ); ?>,
		toastView:      <?php echo wp_json_encode( esc_html__( 'View in Applied', 'action-steward' ) ); ?>,
		toastUndone:    <?php echo wp_json_encode( esc_html__( 'Reverted', 'action-steward' ) ); ?>,
		toastClose:     <?php echo wp_json_encode( esc_html__( 'Dismiss notification', 'action-steward' ) ); ?>,
		// Contextual entry-point notices (wpcc_content_gen / wpcc_content_bulk).
		genCreated:    <?php echo wp_json_encode( esc_html__( 'Suggestion created. Review it below and apply when you’re ready.', 'action-steward' ) ); ?>,
		genExists:     <?php echo wp_json_encode( esc_html__( 'This item already has an open suggestion — review it below.', 'action-steward' ) ); ?>,
		genNoProvider: <?php echo wp_json_encode( esc_html__( 'Built-in AI has no provider key yet, so nothing was generated and nothing on your site changed. Add a key on Built-in AI › Providers.', 'action-steward' ) ); ?>,
		genUnsupported: <?php echo wp_json_encode( esc_html__( 'Some items have a status that cannot receive suggestions (e.g. trashed or auto-draft) and were skipped.', 'action-steward' ) ); ?>,
		genFailed:     <?php echo wp_json_encode( esc_html__( 'Couldn’t generate a suggestion. Please try again.', 'action-steward' ) ); ?>,
		genSkipped:    <?php echo wp_json_encode( esc_html__( 'Nothing was generated for the selected items.', 'action-steward' ) ); ?>,
		aiIntegrations: <?php echo wp_json_encode( esc_html__( 'Open Built-in AI › Providers', 'action-steward' ) ); ?>,
		/* translators: %1$d created, %2$d skipped, %3$d failed */
		bulkSummary:   <?php echo wp_json_encode( /* translators: %1$d: number, %2$d: number, %3$d: number */ __( '%1$d suggestions created · %2$d skipped · %3$d failed. Review and apply below.', 'action-steward' ) ); ?>,

		// ---- Review tab (pick content → generate) ----
		rvNone:      <?php echo wp_json_encode( esc_html__( 'Nothing here to generate for yet.', 'action-steward' ) ); ?>,
		rvNoneHint:  <?php echo wp_json_encode( esc_html__( 'Publish or draft a post or page first, then come back.', 'action-steward' ) ); ?>,
		rvNotSet:    <?php echo wp_json_encode( esc_html__( '(not set)', 'action-steward' ) ); ?>,
		rvReady:     <?php echo wp_json_encode( esc_html__( 'Ready', 'action-steward' ) ); ?>,
		rvHasDraft:  <?php echo wp_json_encode( esc_html__( 'Draft waiting', 'action-steward' ) ); ?>,
		rvHasDraftT: <?php echo wp_json_encode( esc_html__( 'A suggestion for this field is already waiting on the Suggestions tab.', 'action-steward' ) ); ?>,
		/* translators: %s: number of words in the post's content. */
		rvWords:     <?php echo wp_json_encode( /* translators: %s: value */ esc_html__( '%s words', 'action-steward' ) ); ?>,
		rvThin:      <?php echo wp_json_encode( esc_html__( 'Thin', 'action-steward' ) ); ?>,
		rvThinT:     <?php echo wp_json_encode( esc_html__( 'There is little content here to work from, so a suggestion may stay close to what you already have. It will still generate.', 'action-steward' ) ); ?>,
		/* translators: %1$s: number done, %2$s: total to do. */
		rvProgress:  <?php echo wp_json_encode( /* translators: %1$s: value, %2$s: value */ esc_html__( 'Generating %1$s of %2$s…', 'action-steward' ) ); ?>,
		/* translators: %s: number of suggestions created. */
		rvDone:      <?php echo wp_json_encode( /* translators: %s: value */ esc_html__( '%s ready to review.', 'action-steward' ) ); ?>,
		rvDoneGo:    <?php echo wp_json_encode( esc_html__( 'Review suggestions', 'action-steward' ) ); ?>,
		rvNothing:   <?php echo wp_json_encode( esc_html__( 'Nothing was generated.', 'action-steward' ) ); ?>,
		/* translators: %s: number of items whose source content was thin. */
		rvThinNote:  <?php echo wp_json_encode( /* translators: %s: value */ esc_html__( '%s had little content to work from, so those suggestions may stay close to what is already there. Adding more body content usually produces a stronger suggestion.', 'action-steward' ) ); ?>,
		rvNoProvider:<?php echo wp_json_encode( esc_html__( 'No provider key, so nothing was generated and nothing on your site changed.', 'action-steward' ) ); ?>,
		rvGenBusy:   <?php echo wp_json_encode( esc_html__( 'Generating…', 'action-steward' ) ); ?>,
		rvGenLabel:  <?php echo wp_json_encode( esc_html__( 'Generate suggestions', 'action-steward' ) ); ?>,
		/* translators: %s: the post or page title. */
		rvSelectFor: <?php echo wp_json_encode( /* translators: %s: value */ esc_html__( 'Select %s', 'action-steward' ) ); ?>,

		// ---- Regenerate (ask for another draft) ----
		regen:        <?php echo wp_json_encode( esc_html__( 'Regenerate', 'action-steward' ) ); ?>,
		regenBusy:    <?php echo wp_json_encode( esc_html__( 'Asking again…', 'action-steward' ) ); ?>,
		regenOk:      <?php echo wp_json_encode( esc_html__( 'New suggestion ready', 'action-steward' ) ); ?>,
		regenFail:    <?php echo wp_json_encode( esc_html__( 'Couldn’t get another suggestion — your current one is unchanged.', 'action-steward' ) ); ?>,
		/* translators: %s: the post or page title. */
		regenFor:     <?php echo wp_json_encode( /* translators: %s: value */ esc_html__( 'Ask for a different suggestion for %s', 'action-steward' ) ); ?>,
		// Shown when the returned draft simply restates what is already there.
		echoNote:     <?php echo wp_json_encode( esc_html__( 'This matches your current value. With little content to work from, the AI stays close to what you have rather than inventing something about the page.', 'action-steward' ) ); ?>
	};

	const $ = ( id ) => document.getElementById( id );
	const esc = ( s ) => String( s == null ? '' : s ).replace( /[&<>"']/g, ( c ) => ( { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[ c ] ) );

	/**
	 * Row-level accessible name. This screen's row controls are buttons rather
	 * than checkboxes, and Apply / Save / Dismiss carried the same three names on
	 * every row — so a screen reader user reviewing a page of suggestions heard
	 * "Apply" repeatedly with no way to tell which post they were applying to.
	 */
	const rowLabel = ( tpl, title, id ) => String( tpl ).replace( '%s', ( title && String( title ).trim() ) ? title : ( '#' + id ) );

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

	// ---------- REVIEW TAB: pick eligible content → generate governed DRAFTS ----------
	//
	// This tab introduces NO backend. Candidates come from WordPress core's own REST
	// routes (context=edit so the RAW excerpt is available — excerpt.rendered is
	// auto-generated from the body when a post has no excerpt of its own, which would
	// have shown a "current excerpt" that does not exist). Generation calls the EXISTING
	// POST /admin/proposals generate branch once per post, the same governed route the
	// row action uses. It creates drafts and nothing else.
	let rvOffset = 0, rvTotal = 0, rvHasMore = false, rvBusy = false;
	let rvRows = [];      // candidates currently rendered
	let rvOpenDrafts = {}; // "<postId>:<kind>" -> true, for items already awaiting review

	function rvKind() { const s = $( 'wpcc-aic-rv-kind' ); return s ? s.value : 'title'; }
	function rvType() { const s = $( 'wpcc-aic-rv-type' ); return s ? s.value : 'posts'; }

	// Word count of the source body, matched to the server's own measure so the "Thin"
	// badge and the post-generation notice agree.
	function rvWordCount( html ) {
		const text = String( html || '' ).replace( /<[^>]*>/g, ' ' ).replace( /&[a-z#0-9]+;/gi, ' ' );
		const t = text.replace( /\s+/g, ' ' ).trim();
		return t ? t.split( ' ' ).length : 0;
	}

	function rvSelected() {
		return Array.prototype.slice.call( document.querySelectorAll( '#wpcc-aic-rv-rows input.wpcc-aic-rv-cb:checked' ) )
			.map( ( c ) => parseInt( c.getAttribute( 'data-id' ), 10 ) ).filter( ( n ) => n > 0 );
	}

	function rvRefreshButton() {
		const btn = $( 'wpcc-aic-rv-generate' );
		if ( ! btn ) { return; }
		const n = rvSelected().length;
		btn.disabled = rvBusy || ! HAS_PROVIDER || n === 0;
		btn.textContent = ( n > 0 && ! rvBusy ) ? ( STR.rvGenLabel + ' (' + n + ')' ) : ( rvBusy ? STR.rvGenBusy : STR.rvGenLabel );
	}

	// Which candidates already have a draft or a pending approval for this field, so the
	// row can say so instead of letting the customer generate a duplicate the server
	// would only skip.
	function rvLoadOpenDrafts( kind ) {
		const tt = ( kind === 'excerpt' ) ? TT_EXCERPT : TT_TITLE;
		rvOpenDrafts = {};
		return Promise.all( [ 'draft', 'pending_approval' ].map( ( st ) =>
			api( '/proposals?status=' + st + '&operation_id=' + encodeURIComponent( OP ) + '&target_type=' + encodeURIComponent( tt ) + '&limit=100' )
		) ).then( ( results ) => {
			results.forEach( ( res ) => {
				const list = ( res.ok && res.data && res.data.proposals ) || [];
				list.forEach( ( p ) => { rvOpenDrafts[ parseInt( p.target_id, 10 ) + ':' + kind ] = true; } );
			} );
		} ).catch( () => {} );
	}

	function renderReview() {
		const kind = rvKind();
		if ( ! rvRows.length ) {
			setHtml( 'wpcc-aic-rv-rows', '<tr><td colspan="5"><strong>' + esc( STR.rvNone ) + '</strong><div class="wpcc-aic-meta">' + esc( STR.rvNoneHint ) + '</div></td></tr>' );
			setHtml( 'wpcc-aic-rv-pager', '' );
			rvRefreshButton();
			return;
		}
		setHtml( 'wpcc-aic-rv-rows', rvRows.map( ( m ) => {
			const id = m.id;
			const title = ( m.title && ( m.title.raw || m.title.rendered ) ) || ( '#' + id );
			const editLink = EDIT + '?post=' + encodeURIComponent( id ) + '&action=edit';
			const current = ( kind === 'excerpt' )
				? ( ( m.excerpt && m.excerpt.raw != null ) ? m.excerpt.raw : '' )
				: title;
			const body = ( m.content && ( m.content.raw != null ? m.content.raw : m.content.rendered ) ) || '';
			const words = rvWordCount( body );
			const thin = words < THIN_WORDS;
			const taken = !! rvOpenDrafts[ id + ':' + kind ];
			const curCell = ( current && String( current ).trim() )
				? esc( current )
				: '<em class="wpcc-aic-none">' + esc( STR.rvNotSet ) + '</em>';
			const wordCell = esc( STR.rvWords.replace( '%s', words ) )
				+ ( thin ? ' <span class="wpcc-aic-thin" title="' + esc( STR.rvThinT ) + '">' + esc( STR.rvThin ) + '</span>' : '' );
			const status = taken
				? '<span class="wpcc-aic-field" title="' + esc( STR.rvHasDraftT ) + '">' + esc( STR.rvHasDraft ) + '</span>'
				: '<span class="wpcc-aic-meta">' + esc( STR.rvReady ) + '</span>';
			return '<tr>' +
				'<td><input type="checkbox" class="wpcc-aic-rv-cb" data-id="' + esc( id ) + '"' + ( taken ? ' disabled' : '' ) +
					' aria-label="' + esc( rowLabel( STR.rvSelectFor, title, id ) ) + '"></td>' +
				'<td><strong><a href="' + esc( editLink ) + '">' + esc( title ) + '</a></strong>' +
					'<div class="wpcc-aic-meta">' + esc( m.type || '' ) + '</div></td>' +
				'<td class="wpcc-aic-meta">' + curCell + '</td>' +
				'<td class="wpcc-aic-meta">' + wordCell + '</td>' +
				'<td>' + status + '</td>' +
				'</tr>';
		} ).join( '' ) );
		renderRvPager();
		rvRefreshButton();
	}

	function renderRvPager() {
		if ( rvTotal <= LIMIT && rvOffset === 0 ) { setHtml( 'wpcc-aic-rv-pager', '' ); return; }
		const prevDis = rvOffset <= 0 ? ' disabled' : ''; const nextDis = rvHasMore ? '' : ' disabled';
		setHtml( 'wpcc-aic-rv-pager',
			'<button type="button" class="button" id="wpcc-aic-rv-prev"' + prevDis + '>' + esc( STR.prev ) + '</button>' +
			'<button type="button" class="button" id="wpcc-aic-rv-next"' + nextDis + '>' + esc( STR.next ) + '</button>' );
		const p = $( 'wpcc-aic-rv-prev' ), n = $( 'wpcc-aic-rv-next' );
		if ( p ) { p.addEventListener( 'click', () => { if ( rvOffset > 0 ) { rvOffset = Math.max( 0, rvOffset - LIMIT ); loadReview(); } } ); }
		if ( n ) { n.addEventListener( 'click', () => { if ( rvHasMore ) { rvOffset += LIMIT; loadReview(); } } ); }
	}

	function loadReview() {
		setHtml( 'wpcc-aic-rv-rows', '<tr><td colspan="5">' + esc( STR.loading ) + '</td></tr>' );
		const sa = $( 'wpcc-aic-rv-selectall' ); if ( sa ) { sa.checked = false; }
		const kind = rvKind();
		const page = Math.floor( rvOffset / LIMIT ) + 1;
		// context=edit gives excerpt.raw / content.raw — the values actually stored,
		// rather than the rendered ones WordPress synthesizes for display.
		const path = '/' + rvType() + '?context=edit&status=publish,draft,pending,future,private'
			+ '&per_page=' + LIMIT + '&page=' + page + '&_fields=id,title,type,status,excerpt,content&orderby=modified&order=desc';
		Promise.all( [ coreGet( path ), rvLoadOpenDrafts( kind ) ] )
			.then( ( r ) => {
				const res = r[0];
				const list = Array.isArray( res.data ) ? res.data : [];
				if ( ! res.ok ) {
					setHtml( 'wpcc-aic-rv-rows', '<tr><td colspan="5" style="color:#b32d2e;">' + esc( STR.error ) + '</td></tr>' );
					return;
				}
				rvRows = list;
				rvHasMore = list.length >= LIMIT;
				rvTotal = rvOffset + list.length + ( rvHasMore ? 1 : 0 );
				renderReview();
			} )
			.catch( () => { setHtml( 'wpcc-aic-rv-rows', '<tr><td colspan="5" style="color:#b32d2e;">' + esc( STR.error ) + '</td></tr>' ); } );
	}

	function rvNotice( cls, msg, linkGo ) {
		const el = $( 'wpcc-aic-rv-notice' ); if ( ! el ) { return; }
		el.className = 'notice inline ' + cls;
		let html = '<p>' + esc( msg );
		if ( linkGo ) { html += ' <a href="#" data-rv-go="suggestions">' + esc( STR.rvDoneGo ) + '</a>'; }
		html += '</p>';
		el.innerHTML = html;
		el.style.display = '';
	}

	/**
	 * Generate one draft per selected post, sequentially.
	 *
	 * Sequential rather than parallel: each call is one provider request, and firing
	 * twenty at once is a good way to be rate-limited into failures that look like the
	 * product is broken. Progress is reported as it goes, because a button that goes
	 * quiet for thirty seconds reads as a hang.
	 */
	function rvGenerate() {
		if ( rvBusy ) { return; }
		const ids = rvSelected().slice( 0, MAX_GEN );
		if ( ! ids.length ) { return; }
		const kind = rvKind();
		rvBusy = true; rvRefreshButton();
		const status = $( 'wpcc-aic-rv-status' );
		const el = $( 'wpcc-aic-rv-notice' ); if ( el ) { el.style.display = 'none'; }

		let created = 0, thin = 0, noProvider = false, failed = 0;
		// Why items did not produce a suggestion, tallied so the summary can say so.
		// "Nothing was generated." on its own leaves the customer to guess, and the
		// commonest answer is the reassuring one — a draft is already waiting.
		const reasons = {};

		const step = ( i ) => {
			if ( i >= ids.length ) {
				rvBusy = false;
				if ( status ) { status.textContent = ''; }
				const topReason = Object.keys( reasons ).sort( ( a, b ) => reasons[ b ] - reasons[ a ] )[ 0 ] || '';
				if ( noProvider ) {
					rvNotice( 'notice-warning', STR.rvNoProvider, false );
				} else if ( created > 0 ) {
					let msg = STR.rvDone.replace( '%s', created );
					if ( thin > 0 ) { msg += ' ' + STR.rvThinNote.replace( '%s', thin ); }
					// Partial runs explain the remainder too.
					if ( topReason ) { msg += ' ' + STR.skipWhy.replace( '%1$s', skipReasonLabel( topReason ) ); }
					rvNotice( 'notice-success', msg, true );
				} else if ( topReason === 'has_open_proposal' ) {
					rvNotice( 'notice-info', STR.genExists, true );
				} else if ( topReason ) {
					rvNotice( 'notice-warning', STR.rvNothing + ' ' + STR.skipWhy.replace( '%1$s', skipReasonLabel( topReason ) ), false );
				} else if ( failed > 0 ) {
					rvNotice( 'notice-error', STR.genFailed, false );
				} else {
					rvNotice( 'notice-warning', STR.rvNothing, false );
				}
				updateTabCounts();
				loadReview();
				return;
			}
			if ( status ) { status.textContent = STR.rvProgress.replace( '%1$s', i + 1 ).replace( '%2$s', ids.length ); }
			api( '/proposals', {
				method: 'POST', headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( { generate: { kind: kind, post_id: ids[ i ] } } )
			} ).then( ( res ) => {
				const d = ( res.ok && res.data ) || {};
				created += ( d.created && d.created.length ) || 0;
				// The server's own judgement of the source content — not re-derived here.
				if ( d.source && d.source.thin ) { thin++; }
				if ( d.failed && d.failed.length ) { failed++; }
				const skipped = ( d.skipped && d.skipped[0] && d.skipped[0].reason ) || '';
				if ( skipped ) {
					reasons[ skipped ] = ( reasons[ skipped ] || 0 ) + 1;
					if ( skipped === 'no_provider' ) { noProvider = true; }
				}
				// A non-2xx (tool switched off, edition-gated) is a failure to report,
				// not a silent nothing.
				if ( ! res.ok ) { failed++; }
				step( i + 1 );
			} ).catch( () => { failed++; step( i + 1 ); } );
		};
		step( 0 );
	}

	// Review tab wiring.
	document.addEventListener( 'change', function ( e ) {
		const t = e.target;
		if ( t.id === 'wpcc-aic-rv-kind' || t.id === 'wpcc-aic-rv-type' ) { rvOffset = 0; loadReview(); return; }
		if ( t.id === 'wpcc-aic-rv-selectall' ) {
			document.querySelectorAll( '#wpcc-aic-rv-rows input.wpcc-aic-rv-cb:not([disabled])' ).forEach( ( c ) => { c.checked = t.checked; } );
			rvRefreshButton();
			return;
		}
		if ( t.classList && t.classList.contains( 'wpcc-aic-rv-cb' ) ) { rvRefreshButton(); }
	} );
	document.addEventListener( 'click', function ( e ) {
		if ( e.target && e.target.id === 'wpcc-aic-rv-generate' ) { e.preventDefault(); rvGenerate(); return; }
		const go = e.target.closest ? e.target.closest( '[data-rv-go]' ) : null;
		if ( go ) { e.preventDefault(); switchTab( go.getAttribute( 'data-rv-go' ) ); }
	} );

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
				? '<textarea class="wpcc-aic-ed" rows="3" aria-label="' + esc( STR.fieldExcerpt ) + '">' + esc( sg ) + '</textarea>'
				: '<input type="text" class="wpcc-aic-et" aria-label="' + esc( STR.fieldTitle ) + '" value="' + esc( sg ) + '">';
			/*
			 * When the suggestion restates what is already there.
			 *
			 * A tester generated a title for a nearly-empty page and got their own title
			 * back, with nothing to say why. That IS the right answer for a page with two
			 * sentences on it — the prompt forbids inventing claims about content the
			 * model has not seen — but presented silently it reads as the feature not
			 * working, and the tempting "fix" is to let the model make things up.
			 */
			const echoed = String( sg ).trim() !== '' && String( sg ).trim() === String( cur ).trim();
			const echoNote = echoed ? '<div class="wpcc-aic-echo">' + esc( STR.echoNote ) + '</div>' : '';
			// proposal_id is an OPAQUE DOM key only (edit/apply/dismiss); never displayed.
			return '<tr data-id="' + esc( p.proposal_id ) + '" data-tid="' + esc( tid ) + '" data-field="' + esc( field ) + '">' +
				'<td><strong><a href="' + esc( editLink ) + '">' + esc( title ) + '</a></strong><div class="wpcc-aic-meta">' + esc( c.type || '' ) + '</div></td>' +
				'<td><span class="wpcc-aic-field">' + esc( fieldLabel( field ) ) + '</span></td>' +
				'<td class="wpcc-aic-meta">' + curCell + '</td>' +
				'<td>' + prov + editor +
					'<div class="wpcc-aic-cc"></div>' + echoNote +
				'</td>' +
				'<td><button type="button" class="button button-primary button-small wpcc-aic-apply" aria-label="' + esc( rowLabel( IS_DEV ? STR.applyDevFor : STR.applyGateFor, title, tid ) ) + '">' + esc( IS_DEV ? STR.applyDev : STR.applyGate ) + '</button> ' +
					'<button type="button" class="button button-small wpcc-aic-save" aria-label="' + esc( rowLabel( STR.saveFor, title, tid ) ) + '">' + esc( STR.save ) + '</button> ' +
					'<button type="button" class="button button-small wpcc-aic-regen"' + ( HAS_PROVIDER ? '' : ' disabled' ) + ' aria-label="' + esc( rowLabel( STR.regenFor, title, tid ) ) + '">' + esc( STR.regen ) + '</button> ' +
					'<button type="button" class="button button-small wpcc-aic-dismiss" aria-label="' + esc( rowLabel( STR.dismissFor, title, tid ) ) + '">' + esc( STR.dismiss ) + '</button>' +
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
				if ( ! list.length ) {
					// An empty state that names the next step and can take you there.
					setHtml( 'wpcc-aic-sg-rows', '<tr><td colspan="5">' + esc( STR.noSug ) +
						' <a href="#" data-rv-go="review">' + esc( STR.noSugGo ) + '</a></td></tr>' );
					setHtml( 'wpcc-aic-sg-pager', '' ); $( 'wpcc-aic-sg-status' ).textContent = ''; return;
				}
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
		} else if ( t.classList.contains( 'wpcc-aic-regen' ) ) {
			/*
			 * Ask for a different suggestion.
			 *
			 * The current draft is NOT removed first. The server creates the replacement,
			 * and only then retires the one being replaced — so a provider error, a
			 * timeout, or an unparseable response leaves the customer exactly where they
			 * were, with the suggestion they already had. The button disables itself for
			 * the duration so a second click cannot start a concurrent run against the
			 * same draft.
			 */
			if ( t.disabled ) { return; }
			t.disabled = true;
			const prevLabel = t.textContent;
			t.textContent = STR.regenBusy;
			if ( msg ) { msg.textContent = ''; }
			api( '/proposals', {
				method: 'POST', headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( { generate: { kind: field, post_id: tid, replacing: id } } )
			} ).then( ( res ) => {
				const d = ( res.ok && res.data ) || {};
				if ( d.created && d.created.length ) {
					// The row is rebuilt from the replacement draft rather than patched in
					// place, so its proposal id, provenance and char count all stay true.
					loadSuggestions();
					updateTabCounts();
					return;
				}
				t.disabled = false; t.textContent = prevLabel;
				if ( msg ) { msg.textContent = STR.regenFail; }
			} ).catch( () => {
				t.disabled = false; t.textContent = prevLabel;
				if ( msg ) { msg.textContent = STR.regenFail; }
			} );
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
			// Awaiting approval is not a dead end: link straight to the decision.
			let actions = '';
			if ( reversible ) {
				actions = '<button type="button" class="button button-small wpcc-aic-undo">' + esc( STR.undo ) + '</button><div class="wpcc-aic-rowmsg" role="status"></div>';
			} else if ( p.status === 'pending_approval' && p.request_id ) {
				actions = '<a class="button button-small" href="' + esc( APPROVAL_URL + '&view=' + encodeURIComponent( p.request_id ) ) + '" aria-label="' +
					esc( rowLabel( STR.reviewApprovalFor, title, tid ) ) + '">' + esc( STR.reviewApproval ) + '</a>';
			}
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
		$( 'wpcc-aic-panel-review' ).style.display = ( which === 'review' ) ? '' : 'none';
		$( 'wpcc-aic-panel-suggestions' ).style.display = ( which === 'suggestions' ) ? '' : 'none';
		$( 'wpcc-aic-panel-applied' ).style.display = ( which === 'applied' ) ? '' : 'none';
		$( 'wpcc-aic-tab-review' ).classList.toggle( 'nav-tab-active', which === 'review' );
		$( 'wpcc-aic-tab-suggestions' ).classList.toggle( 'nav-tab-active', which === 'suggestions' );
		$( 'wpcc-aic-tab-applied' ).classList.toggle( 'nav-tab-active', which === 'applied' );
		if ( which === 'review' ) { rvOffset = 0; loadReview(); }
		else if ( which === 'suggestions' ) { sgOffset = 0; loadSuggestions(); }
		else if ( which === 'applied' ) { switchApSeg( 'applied' ); }
		updateTabCounts();
	}

	// ---------- wiring ----------
	$( 'wpcc-aic-tab-review' ).addEventListener( 'click', function ( e ) { e.preventDefault(); switchTab( 'review' ); } );
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
	// The engine's skip reason, in the customer's words.
	function skipReasonLabel( reason ) {
		switch ( reason ) {
			case 'has_open_proposal':      return STR.skHasDraft;
			case 'already_optimized':
			case 'up_to_date':             return STR.skUpToDate;
			case 'unsupported_status':     return STR.skStatus;
			case 'no_provider':            return STR.skNoProvider;
			case 'capability_unsupported': return STR.skUnsupported;
			case 'not_found':              return STR.skNotFound;
			default:                       return STR.skOther;
		}
	}
	function showBulkNotice( c, s, f, r ) {
		const el = $( 'wpcc-aic-entry-notice' ); if ( ! el ) { return; }
		let cls = 'notice-success', extraUrl = '', extraLabel = '';
		let msg = STR.bulkSummary.replace( '%1$d', c ).replace( '%2$d', s ).replace( '%3$d', f );
		/*
		 * Say WHY items were skipped even when others succeeded.
		 *
		 * "2 suggestions created · 1 skipped" left the customer to guess what
		 * happened to the third page — and the commonest answer is the reassuring
		 * one: it already has a draft waiting. The redirect already carries the
		 * reason; the mixed-result branch simply never read it.
		 */
		if ( c > 0 && s > 0 && r ) {
			msg += ' ' + STR.skipWhy.replace( '%1$s', skipReasonLabel( r ) );
			if ( r === 'has_open_proposal' ) { msg += ' ' + STR.skReview; }
		}
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
		/*
		 * Landing tab.
		 *
		 * A contextual entry point (a row action, or a bulk run) has just created a
		 * suggestion, so it lands on Suggestions — that is what the customer asked for
		 * and where the result is. Otherwise the screen opens on Review, its own first
		 * step, rather than on a list that is empty until someone has already found the
		 * generation path somewhere else.
		 */
		const cameFromEntryPoint = !! ( code || sp.get( 'wpcc_content_bulk' ) || sp.get( 'tab' ) === 'suggestions' );
		switchTab( cameFromEntryPoint ? 'suggestions' : 'review' );
	} )();
} )();
</script>
