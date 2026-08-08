<?php
/**
 * PROGRAM-6S — AI Platform experience (premium product UX over the 6R foundation).
 *
 * EXPERIENCE ONLY: no architecture/runtime/data-model change. Renders the same
 * ConnectionStore data as a real AI platform — dashboard, guided wizard, rich
 * connection cards with health, declared capabilities, visual feature routing —
 * with honest status (CONFIGURED / TESTABLE / USED BY RUNTIME, never faked).
 * All writes still go through ConnectionController (nonce + manage_options + audit).
 */

defined( 'ABSPATH' ) || exit;

use WPCommandCenter\Admin\ConnectionController;
use WPCommandCenter\Ai\Platform\ConnectionStore;
use WPCommandCenter\Ai\Platform\ProviderCatalog;
use WPCommandCenter\Ai\Platform\Dialect;
use WPCommandCenter\Ai\Platform\Capabilities;
use WPCommandCenter\Ai\Platform\Health;
use WPCommandCenter\Ai\Platform\AiActivity;

$wpcc_notice = ( new ConnectionController() )->handle_post();

$wpcc_act  = AiActivity::summary();
$wpcc_feed = AiActivity::feed( 12 );

$wpcc_store     = new ConnectionStore();
$wpcc_conns     = $wpcc_store->all();
$wpcc_default   = $wpcc_store->default_id();
$wpcc_routes    = $wpcc_store->routes();
$wpcc_providers = ProviderCatalog::all();
$wpcc_health    = Health::summary( $wpcc_conns, $wpcc_store );

// Runtime-usable, configured connections (for routing).
$wpcc_runtime_conns = [];
// Healthy/configured connections the runtime CANNOT execute yet (e.g. OpenAI):
// surfaced in routing with an explicit reason so a healthy connection's absence
// is never a mystery. Runtime capability is unchanged — clarity only.
$wpcc_ineligible_conns = [];
foreach ( $wpcc_conns as $cid => $c ) {
	if ( ! $wpcc_store->is_configured( $c ) || ! $c['enabled'] ) {
		continue;
	}
	if ( $wpcc_store->runtime_usable( $c ) ) {
		$wpcc_runtime_conns[ $cid ] = $c['name'];
	} else {
		$wpcc_ineligible_conns[ $cid ] = ( $wpcc_providers[ $c['provider'] ]['label'] ?? $c['provider'] ) . ' · ' . $c['name'];
	}
}

// Readiness score (honest, derived): a connection, a default, a healthy test, a key.
$wpcc_ready = 0;
if ( ! empty( $wpcc_conns ) ) { $wpcc_ready += 30; }
if ( '' !== $wpcc_default ) { $wpcc_ready += 25; }
$wpcc_has_healthy = false;
foreach ( $wpcc_conns as $c ) { if ( in_array( Health::of( $c, $wpcc_store )['state'], [ 'healthy', 'slow' ], true ) ) { $wpcc_has_healthy = true; break; } }
if ( $wpcc_has_healthy ) { $wpcc_ready += 30; }
if ( $wpcc_health['attention'] === 0 && ! empty( $wpcc_conns ) ) { $wpcc_ready += 15; }
$wpcc_ready = min( 100, $wpcc_ready );

// PROGRAM-7.5 — make the readiness % self-explanatory: expose the exact components
// that produced it (no scoring-logic change), plus honest context for AI being
// inactive (not part of the score — purely informational).
$wpcc_ready_steps = [
	[ 'done' => ! empty( $wpcc_conns ),                                      'label' => __( 'A connection added', 'ai-command-center' ) ],
	[ 'done' => '' !== $wpcc_default,                                        'label' => __( 'A default chosen', 'ai-command-center' ) ],
	[ 'done' => $wpcc_has_healthy,                                           'label' => __( 'Tested healthy', 'ai-command-center' ) ],
	[ 'done' => ( $wpcc_health['attention'] === 0 && ! empty( $wpcc_conns ) ), 'label' => __( 'No connection issues', 'ai-command-center' ) ],
];

// Provider groups for the wizard.
$wpcc_groups = [ 'cloud' => [], 'local' => [], 'gateway' => [] ];
foreach ( $wpcc_providers as $pid => $pdef ) {
	if ( ! empty( $pdef['local'] ) ) { $wpcc_groups['local'][ $pid ] = $pdef['label']; }
	elseif ( ! empty( $pdef['needs_endpoint'] ) && empty( $pdef['needs_deployment'] ) ) { $wpcc_groups['gateway'][ $pid ] = $pdef['label']; }
	else { $wpcc_groups['cloud'][ $pid ] = $pdef['label']; }
}
$wpcc_default_name = '' !== $wpcc_default && isset( $wpcc_conns[ $wpcc_default ] ) ? $wpcc_conns[ $wpcc_default ]['name'] : __( 'none yet', 'ai-command-center' );

/*
 * Is any built-in AI tool actually switched on for this site?
 *
 * Resolved once, here, because two separate places now need the answer: the
 * outcome card that tells a customer what to do after saving a connection, and
 * the "what happens next" list at the bottom. It was previously computed only at
 * the bottom, which is also why the top of the screen could not tell the
 * difference between "you are ready" and "you have a key and nothing turned on".
 *
 * Moving it up means it is now read BEFORE partials/builtin-ai-tools.php renders,
 * and that partial is where a tool toggle would otherwise be processed — so the
 * answer would describe the state before the toggle rather than after it. The
 * hosting view (settings-ai.php) already handles the toggle first, for exactly
 * this reason, and every route to this screen goes through it. Rather than leave
 * that as an unstated dependency, the same `?? handle_post()` guard the partial
 * uses is applied here: whoever gets there first handles it, and it is never
 * handled twice — so this value is correct no matter who includes this view.
 */
$wpcc_bai_handled = $wpcc_bai_handled ?? \WPCommandCenter\Admin\BuiltinAiSettings::handle_post();
$wpcc_ai_tools_on = count( \WPCommandCenter\Admin\AppShell::builtin_tabs() ) > 1;

/*
 * WHICH tools are on — not merely whether any is.
 *
 * `$wpcc_ai_tools_on` answers a yes/no question, and the post-success experience
 * has to answer a which-one question: it names the tools that are running, and it
 * has to be able to say "SEO is on, Content is not" rather than "some tools are
 * on". Same precedence as everywhere else — BuiltinAiSettings::is_on() is the one
 * place constant → filter → option is decided, so this cannot drift from what the
 * toggles, the tabs and the row actions actually do.
 */
$wpcc_tools_on = [];
foreach ( \WPCommandCenter\Admin\BuiltinAiSettings::tools() as $wpcc_tk => $wpcc_td ) {
	if ( \WPCommandCenter\Admin\BuiltinAiSettings::is_on( $wpcc_tk ) ) {
		$wpcc_tools_on[ $wpcc_tk ] = $wpcc_td['label'];
	}
}

/*
 * Does the "✨ WPCC AI" entry point exist on Posts and Pages right now?
 *
 * This is the single most undiscoverable thing in the product, and the reason is
 * that it is CONDITIONAL in a way nothing ever states: AiActionRegistry only adds
 * the row action for actions whose built-in tool is switched on. Content carries
 * Title and Excerpt; SEO carries SEO meta; Alt Text is a Media Library action and
 * deliberately not counted here, because it never appears on a post or page row.
 *
 * So "you will see WPCC AI when editing a post" is not a fact about the product —
 * it is a fact about this site's current settings, and on a stock install it is
 * false. Deriving it from the same flags the registry reads means the screen
 * promises the menu only when the menu is genuinely there.
 */
$wpcc_editor_actions = [];
if ( isset( $wpcc_tools_on['content'] ) ) {
	$wpcc_editor_actions[] = __( 'Title', 'ai-command-center' );
	$wpcc_editor_actions[] = __( 'Excerpt', 'ai-command-center' );
}
if ( isset( $wpcc_tools_on['seo'] ) ) {
	$wpcc_editor_actions[] = __( 'SEO title and description', 'ai-command-center' );
}

/*
 * Real assistant state (usable tokens + whether one has ever called) is resolved
 * LAZILY, at the point of use, rather than here.
 *
 * ConnectionStatus::get() reads the token manifest off disk. That is cheap, and
 * it is still work this screen does not need: the only thing that consumes it is
 * one row of the post-success card, which renders after an action on a proven
 * connection and not on the plain GET that most visits to this screen are. A
 * read every visitor pays for so that a minority of visits can show a sentence
 * is the kind of cost that is invisible until it is not.
 */

/*
 * Per-connection usage, keyed by connection id.
 *
 * "When did I last use this?" is the first question a returning customer asks of
 * a connection, and the answer was already being recorded — UsageLedger buckets
 * carry the connection id and a `last` timestamp — it was simply never read back
 * out. Folding the buckets by connection here costs one option read that the
 * screen was already doing, adds no storage, and changes nothing about what is
 * recorded or when.
 */
$wpcc_conn_usage = [];
foreach ( \WPCommandCenter\Ai\Platform\UsageLedger::read()['buckets'] as $wpcc_b ) {
	$wpcc_bcid = (string) ( $wpcc_b['connection'] ?? '' );
	if ( '' === $wpcc_bcid ) {
		continue; // Recorded before a connection was attributable; not attributable now either.
	}
	if ( ! isset( $wpcc_conn_usage[ $wpcc_bcid ] ) ) {
		$wpcc_conn_usage[ $wpcc_bcid ] = [ 'calls' => 0, 'last' => 0 ];
	}
	$wpcc_conn_usage[ $wpcc_bcid ]['calls'] += (int) ( $wpcc_b['calls'] ?? 0 );
	$wpcc_conn_usage[ $wpcc_bcid ]['last']   = max( $wpcc_conn_usage[ $wpcc_bcid ]['last'], (int) ( $wpcc_b['last'] ?? 0 ) );
}
?>
<style>
.wpcc-aip { max-width: 1080px; }
.wpcc-aip h2 { font-size: 16px; margin: 28px 0 6px; }
.wpcc-aip .muted { color: #646970; }
.wpcc-aip-hero { display:flex; justify-content:space-between; gap:20px; flex-wrap:wrap; align-items:center; background:linear-gradient(135deg,#1d2734,#2c3a4f); color:#e8edf3; border-radius:12px; padding:22px 26px; margin:6px 0 18px; }
.wpcc-aip-hero h1 { color:#fff; margin:0 0 4px; font-size:22px; }
.wpcc-aip-hero p { margin:0; color:#b9c4d2; font-size:13px; max-width:520px; }
.wpcc-aip-score { text-align:center; min-width:110px; }
.wpcc-aip-readiness { display:flex; gap:18px; align-items:center; }
.wpcc-aip-checklist { list-style:none; margin:0; padding:0; display:grid; gap:4px; font-size:12.5px; color:#cdd6e2; }
.wpcc-aip-checklist li { white-space:nowrap; }
.wpcc-aip-checklist .ck { display:inline-block; width:16px; font-weight:700; }
.wpcc-aip-ring { --v:0; width:84px; height:84px; border-radius:50%; margin:0 auto 6px; background:conic-gradient(#3ec46d calc(var(--v)*1%), rgba(255,255,255,.15) 0); display:flex; align-items:center; justify-content:center; }
.wpcc-aip-ring span { width:64px; height:64px; border-radius:50%; background:#1d2734; display:flex; align-items:center; justify-content:center; font-size:20px; font-weight:700; color:#fff; }
.wpcc-aip-kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:12px; margin-bottom:8px; }
.wpcc-aip-kpi { background:#fff; border:1px solid #dcdfe3; border-radius:10px; padding:14px 16px; }
.wpcc-aip-kpi .v { font-size:24px; font-weight:700; color:#1d2327; line-height:1.1; }
.wpcc-aip-kpi .l { font-size:12px; color:#646970; text-transform:uppercase; letter-spacing:.4px; margin-top:3px; }
.wpcc-aip-warn { background:#fcf6e6; border:1px solid #f0d97a; border-radius:8px; padding:12px 16px; margin:10px 0; font-size:13px; }
.wpcc-aip-needsyou { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; background:#fef0f0; border:1px solid #f0a8a8; border-left:4px solid #d63638; border-radius:8px; padding:12px 16px; margin:10px 0; font-size:13px; }
.wpcc-aip-flow { display:flex; align-items:center; gap:6px; flex-wrap:wrap; background:#f6f7f9; border:1px solid #e4e7eb; border-radius:10px; padding:10px 14px; margin:14px 0; font-size:12.5px; color:#50575e; }
.wpcc-aip-flow .step { display:inline-flex; align-items:center; gap:5px; font-weight:600; color:#2c3a4f; }
.wpcc-aip-flow .step .dashicons { font-size:16px; width:16px; height:16px; color:#5b6b82; }
.wpcc-aip-flow .sep { color:#aab2bd; font-weight:700; }
.wpcc-aip-cards { display:grid; grid-template-columns:repeat(auto-fill,minmax(330px,1fr)); gap:14px; }
.wpcc-aip-card { background:#fff; border:1px solid #dcdfe3; border-radius:12px; padding:16px 18px; box-shadow:0 1px 2px rgba(0,0,0,.04); display:flex; flex-direction:column; gap:8px; transition:box-shadow .15s ease, border-color .15s ease; }
.wpcc-aip-card:hover { box-shadow:0 4px 14px rgba(28,39,52,.10); border-color:#c5ccd4; }
.wpcc-aip-card.dim { opacity:.62; }
.wpcc-aip-card__top { display:flex; justify-content:space-between; align-items:flex-start; gap:8px; }
.wpcc-aip-avatar { width:38px; height:38px; border-radius:9px; background:#eef2f7; color:#2c3a4f; font-weight:700; display:flex; align-items:center; justify-content:center; font-size:15px; flex:0 0 auto; }
.wpcc-aip-name { font-size:15px; font-weight:700; line-height:1.2; }
.wpcc-aip-sub { font-size:12px; color:#646970; }
.wpcc-aip-dot { width:9px; height:9px; border-radius:50%; display:inline-block; vertical-align:middle; margin-right:5px; }
.wpcc-aip-timeline { list-style:none; margin:0; padding:0; }
.wpcc-aip-timeline .grp { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#8a93a0; margin:8px 0 4px; }
.wpcc-aip-timeline .grp:first-child { margin-top:0; }
.wpcc-aip-timeline .ev { display:flex; gap:9px; align-items:baseline; font-size:13px; padding:4px 0; border-bottom:1px solid #f3f4f6; }
.wpcc-aip-timeline .ev:last-child { border-bottom:0; }
.wpcc-aip-timeline .ic { flex:0 0 18px; }
.wpcc-aip-timeline .ic .dashicons { font-size:16px; width:16px; height:16px; }
.wpcc-aip-timeline .bd { flex:1; }
.wpcc-aip-timeline .tm { white-space:nowrap; font-size:12px; }
.wpcc-aip-badge { display:inline-block; padding:2px 8px; border-radius:20px; font-size:11px; font-weight:700; letter-spacing:.2px; }
.wpcc-aip-meta { font-size:12px; color:#50575e; display:grid; gap:3px; }
.wpcc-aip-meta code { background:#f4f6f8; padding:1px 6px; border-radius:4px; }
.wpcc-aip-caps { display:flex; flex-wrap:wrap; gap:4px; }
.wpcc-aip-cap { font-size:11px; padding:1px 7px; border-radius:5px; background:#f0f3f7; color:#50575e; }
.wpcc-aip-cap.on { background:#e7f6ec; color:#0a7a33; }
.wpcc-aip-actions { display:flex; flex-wrap:wrap; align-items:center; gap:6px; border-top:1px solid #eef0f2; padding-top:10px; margin-top:2px; }
.wpcc-aip-actions form { margin:0; }
/* The one line on a card that asks for something, told apart from the four that
   only report. Same size, not muted — emphasis by colour, not by shouting. */
.wpcc-aip-todo { color:#8a5700; font-weight:600; }
/* Duplicate / Delete. Present, reachable, and not competing with Test. */
.wpcc-aip-more { margin-left:auto; }
/* Open, it stops being a trailing item and becomes its own full-width row, so the
   summary sits above its buttons instead of floating to the right of them. */
.wpcc-aip-more[open] { margin-left:0; flex:1 0 100%; }
.wpcc-aip-more > summary { cursor:pointer; list-style:none; font-size:12px; color:#646970; padding:2px 6px; border-radius:4px; display:inline-block; }
.wpcc-aip-more > summary::-webkit-details-marker { display:none; }
.wpcc-aip-more > summary:hover { color:#1d2327; background:#f0f0f1; }
.wpcc-aip-more[open] > summary { color:#1d2327; padding-left:0; }
.wpcc-aip-more__body { display:flex; flex-wrap:wrap; gap:6px; padding-top:8px; }
.wpcc-aip-more__body form { margin:0; }
.wpcc-aip-empty { background:#fff; border:2px dashed #c3c4c7; border-radius:12px; padding:36px 24px; text-align:center; }
.wpcc-aip-empty h3 { margin:0 0 6px; font-size:17px; }
.wpcc-aip-route { display:flex; align-items:center; gap:10px; padding:11px 0; border-bottom:1px solid #f0f0f1; }
.wpcc-aip-route .f { min-width:200px; font-weight:600; }
.wpcc-aip-route .arrow { color:#8a93a0; }
.wpcc-aip-wizard { display:none; background:#fff; border:1px solid #c3c4c7; border-radius:12px; padding:20px 22px; margin:8px 0 18px; max-width:620px; }
.wpcc-aip-wizard.open { display:block; }
.wpcc-aip-steps { display:flex; gap:6px; margin-bottom:16px; }
.wpcc-aip-steps .s { flex:1; height:5px; border-radius:3px; background:#e5e8ec; }
.wpcc-aip-steps .s.active { background:#2c3a4f; }
.wpcc-aip-step { display:none; }
.wpcc-aip-step.active { display:block; }
.wpcc-aip-step h3 { margin:0 0 4px; font-size:15px; }
.wpcc-aip-field { margin:12px 0; }
.wpcc-aip-field label { display:block; font-weight:600; font-size:13px; margin-bottom:4px; }
.wpcc-aip-field input, .wpcc-aip-field select { width:100%; max-width:420px; }
.wpcc-aip-wnav { display:flex; justify-content:space-between; margin-top:18px; }

/* ── Outcome card: what was saved, and the one thing left to do ───────────── */
.wpcc-aip-outcome { background:#fff; border:1px solid #cfe4d2; border-left:3px solid #00a32a; border-radius:12px;
	padding:18px 20px; margin:6px 0 20px; max-width:760px; box-shadow:0 1px 2px rgba(16,24,40,.04); }
.wpcc-aip-outcome__title { margin:0 0 6px; font-size:15px; font-weight:650; color:#1d2327; letter-spacing:-.01em; }
.wpcc-aip-outcome__title::before { content:"\2713"; color:#00a32a; font-weight:700; margin-right:8px; }
.wpcc-aip-outcome__step { margin:0; font-size:13px; line-height:1.6; color:#50575e; max-width:70ch; }
.wpcc-aip-outcome__actions { display:flex; flex-wrap:wrap; align-items:center; gap:12px; margin:14px 0 0; }
.wpcc-aip-outcome__link { font-size:12.5px; color:#2271b1; text-decoration:none; }
.wpcc-aip-outcome__link:hover { text-decoration:underline; }

/* "What you can do now" — the three surfaces a working connection unlocks.
   Sits INSIDE the outcome card, below a rule, so it reads as the consequence of
   the success above it rather than as a second, competing announcement. */
.wpcc-aip-uses { margin:16px 0 0; padding-top:14px; border-top:1px solid #eef0f2; }
.wpcc-aip-uses__h { margin:0 0 10px; font-size:12px; font-weight:700; letter-spacing:.04em;
	text-transform:uppercase; color:#646970; }
.wpcc-aip-use { display:flex; align-items:flex-start; gap:11px; padding:9px 0; border-top:1px solid #f4f5f7; }
.wpcc-aip-use:first-of-type { border-top:0; }
.wpcc-aip-use__icon { flex:0 0 auto; width:22px; height:22px; margin-top:1px; border-radius:6px;
	background:#f0f3f7; color:#50575e; font-size:12px; line-height:22px; text-align:center; }
.wpcc-aip-use__body { flex:1 1 auto; min-width:0; }
.wpcc-aip-use__body strong { display:block; font-size:13px; color:#1d2327; margin-bottom:2px; }
.wpcc-aip-use__body span { display:block; font-size:12.5px; line-height:1.55; color:#50575e; max-width:66ch; }
/* The control keeps its own column so three rows of differing text length still
   line their buttons up, and never squeezes below a tappable width. */
.wpcc-aip-use > .button { flex:0 0 auto; margin-top:1px; }
@media (max-width:600px){
	.wpcc-aip-use { flex-wrap:wrap; }
	.wpcc-aip-use > .button { margin-left:33px; }
}

/* The connection a just-completed action applied to. Marks the destination when
   the page has scrolled to it, then fades — nothing stays decorated. */
.wpcc-aip-spotlight { animation: wpcc-aip-spot 2.4s ease-out 1; }
@keyframes wpcc-aip-spot {
	0%   { box-shadow: 0 0 0 3px rgba(34,113,177,.45); }
	70%  { box-shadow: 0 0 0 3px rgba(34,113,177,.30); }
	100% { box-shadow: 0 0 0 3px rgba(34,113,177,0); }
}
@media (prefers-reduced-motion: reduce) {
	.wpcc-aip-spotlight { animation:none; box-shadow:0 0 0 3px rgba(34,113,177,.35); }
}

@media (max-width:782px){ .wpcc-aip-hero{flex-direction:column; align-items:flex-start;} .wpcc-aip-cards{grid-template-columns:1fr;} }
</style>

<div class="wrap wpcc-aip">
	<?php
	/*
	 * What just happened, and what to do about it.
	 *
	 * Saving a connection used to produce the words "Connection created." in a
	 * generic admin notice — and then nothing. The connection itself renders
	 * further down the page, past the tool switches and the activity feed, so a
	 * first-time customer who had just pasted an API key was left on a screen that
	 * looked identical to the one before, with no confirmation of what had been
	 * saved, no sign of where it went, and no idea whether they were finished.
	 * "Created" is not an outcome; it is a database event.
	 *
	 * The card below answers the three questions that moment actually raises —
	 * what was saved, is it working, what is left — and carries ONE control for
	 * whatever is genuinely next. Which control that is depends on the state the
	 * connection is really in, so the screen never offers "Test" for a connection
	 * with no key, or "you're ready" for a site with every tool switched off.
	 *
	 * Nothing here changes what saving a connection does. It is the same POST, the
	 * same store, the same audit event; only the reporting is different.
	 *
	 * THREE moments, not one. Creating a connection was never the only place this
	 * flow stopped dead — it was simply the first. The full journey is:
	 *
	 *     create → add key → test → healthy → ???
	 *
	 * and the last arrow was the worst of the three, because it is the one the
	 * customer reaches having done everything right. "Connection succeeded." is a
	 * result, not an outcome: the key works, and the product says nothing about
	 * what now works BECAUSE it works. So `update_key` and `test` produce an
	 * outcome card too, and a successful test additionally opens the "what you can
	 * do now" section below — the point of the whole setup.
	 */
	$wpcc_outcome_action = (string) ( $wpcc_notice['action'] ?? '' );
	$wpcc_outcome_id     = (string) ( $wpcc_notice['connection'] ?? '' );
	$wpcc_outcome        = ( $wpcc_notice
		&& in_array( $wpcc_outcome_action, [ 'create', 'update_key', 'test' ], true )
		&& 'success' === $wpcc_notice['type']
		&& isset( $wpcc_conns[ $wpcc_outcome_id ] ) )
			? $wpcc_conns[ $wpcc_outcome_id ]
			: null;
	?>
	<?php if ( $wpcc_outcome ) : ?>
		<?php
		$wpcc_o_def      = $wpcc_providers[ $wpcc_outcome['provider'] ] ?? [];
		$wpcc_o_haskey   = $wpcc_store->is_configured( $wpcc_outcome );
		$wpcc_o_runtime  = $wpcc_store->runtime_usable( $wpcc_outcome );
		$wpcc_o_testable = $wpcc_store->testable( $wpcc_outcome );
		$wpcc_o_health   = Health::of( $wpcc_outcome, $wpcc_store );
		$wpcc_o_isdef    = ( $wpcc_default === $wpcc_outcome_id );
		$wpcc_o_provider = (string) ( $wpcc_o_def['label'] ?? $wpcc_outcome['provider'] );

		/*
		 * The single next step, decided in the order things actually block on.
		 *
		 * Each branch produces one sentence and at most one primary control. The
		 * order is not cosmetic: a connection with no key cannot be tested, and a
		 * tested connection still generates nothing while every tool is off — so
		 * asking "is there a key?" before "is it tested?" before "is anything
		 * switched on?" is the order in which a customer would otherwise hit each
		 * of these as a surprise.
		 */
		$wpcc_o_step = '';
		$wpcc_o_cta  = '';
		if ( ! $wpcc_o_haskey ) {
			$wpcc_o_step = __( 'It has no API key yet, so it cannot generate anything. Add one to finish setup.', 'ai-command-center' );
			$wpcc_o_cta  = 'addkey';
		} elseif ( ! $wpcc_o_runtime ) {
			$wpcc_o_step = sprintf(
				/* translators: %s: provider label, e.g. "Mistral". */
				__( 'Your key is saved. WP Command Center cannot run its own AI features through %s yet, so this connection is stored and testable rather than generating.', 'ai-command-center' ),
				$wpcc_o_provider
			);
			$wpcc_o_cta = $wpcc_o_testable ? 'test' : '';
		} elseif ( 'untested' === $wpcc_o_health['state'] ) {
			$wpcc_o_step = __( 'Nothing is switched on by saving a key. Test it once to confirm the key works — the test only reads, and changes nothing on your site.', 'ai-command-center' );
			$wpcc_o_cta  = 'test';
		} elseif ( in_array( $wpcc_o_health['state'], [ 'healthy', 'slow' ], true ) && ! $wpcc_ai_tools_on ) {
			$wpcc_o_step = __( 'The key works. The SEO, Alt Text and Content tools are still switched off for this site — turn one on and it will generate through this connection.', 'ai-command-center' );
			$wpcc_o_cta  = 'tools';
		} elseif ( in_array( $wpcc_o_health['state'], [ 'healthy', 'slow' ], true ) ) {
			$wpcc_o_step = __( 'The key works and your built-in AI tools will generate through this connection. Nothing else to set up.', 'ai-command-center' );
		} else {
			$wpcc_o_step = $wpcc_o_health['action'];
			$wpcc_o_cta  = $wpcc_o_testable ? 'test' : '';
		}

		/*
		 * The headline reports what the customer just DID, not a generic "saved".
		 *
		 * A test that passes is the moment the product has actually proved
		 * something, and "is saved" would throw that away — the customer pressed
		 * Test to find out whether it works, so the first line answers that.
		 */
		if ( 'test' === $wpcc_outcome_action ) {
			/* translators: 1: connection name chosen by the customer, 2: provider label, e.g. "Anthropic". */
			$wpcc_o_head = __( '“%1$s” is working — %2$s', 'ai-command-center' );
		} elseif ( 'update_key' === $wpcc_outcome_action ) {
			/* translators: 1: connection name chosen by the customer, 2: provider label, e.g. "Anthropic". */
			$wpcc_o_head = __( '“%1$s” has its API key — %2$s', 'ai-command-center' );
		} else {
			/* translators: 1: connection name chosen by the customer, 2: provider label, e.g. "Anthropic". */
			$wpcc_o_head = __( '“%1$s” is saved — %2$s', 'ai-command-center' );
		}

		/*
		 * Is this connection PROVEN to work right now?
		 *
		 * Gates the "what you can do now" section below. Deliberately not the same
		 * thing as "the test just passed": arriving here by saving a key on a
		 * connection that was already healthy is the same readiness, and a customer
		 * who tests twice should not watch the section vanish. Health is the fact;
		 * the action that got us here is not.
		 */
		$wpcc_o_proven = in_array( $wpcc_o_health['state'], [ 'healthy', 'slow' ], true );
		?>
		<div class="wpcc-aip-outcome" role="status">
			<p class="wpcc-aip-outcome__title">
				<?php
				echo esc_html(
					sprintf(
						$wpcc_o_head,
						(string) $wpcc_outcome['name'],
						$wpcc_o_provider
					)
				);
				?>
				<?php if ( $wpcc_o_isdef ) : ?>
					<span class="wpcc-aip-badge" style="background:#e7f0fb;color:#1d62b0;margin-left:6px;"><?php esc_html_e( 'DEFAULT', 'ai-command-center' ); ?></span>
				<?php endif; ?>
			</p>
			<p class="wpcc-aip-outcome__step"><?php echo esc_html( $wpcc_o_step ); ?></p>
			<div class="wpcc-aip-outcome__actions">
				<?php if ( 'test' === $wpcc_o_cta ) : ?>
					<?php // The whole action, right here — no scrolling to find the same button on the card. ?>
					<form method="post" style="margin:0;">
						<?php wp_nonce_field( ConnectionController::NONCE ); ?>
						<input type="hidden" name="wpcc_conn_id" value="<?php echo esc_attr( $wpcc_outcome_id ); ?>" />
						<button type="submit" name="wpcc_conn_action" value="test" class="button button-primary"><?php esc_html_e( 'Test this connection', 'ai-command-center' ); ?></button>
					</form>
				<?php elseif ( 'addkey' === $wpcc_o_cta ) : ?>
					<?php // Opens this connection's own key field and puts the cursor in it. ?>
					<a class="button button-primary wpcc-aip-jump" href="#wpcc-conn-<?php echo esc_attr( $wpcc_outcome_id ); ?>" data-open-key="1"><?php esc_html_e( 'Add your API key', 'ai-command-center' ); ?></a>
				<?php elseif ( 'tools' === $wpcc_o_cta ) : ?>
					<a class="button button-primary wpcc-aip-jump" href="#wpcc-bai-tools-h"><?php esc_html_e( 'Choose a tool to turn on', 'ai-command-center' ); ?></a>
				<?php endif; ?>
				<a class="wpcc-aip-outcome__link wpcc-aip-jump" href="#wpcc-conn-<?php echo esc_attr( $wpcc_outcome_id ); ?>"><?php esc_html_e( 'Show the connection', 'ai-command-center' ); ?></a>
			</div>

			<?php if ( $wpcc_o_proven ) : ?>
				<?php
				/*
				 * ── WHERE THIS ACTUALLY SHOWS UP ────────────────────────────────
				 *
				 * The end of setup was the emptiest moment in the product. A
				 * customer who created a connection, pasted a key and pressed Test
				 * got the word "succeeded" and a screen of settings — having never
				 * been told what any of it was FOR. Three surfaces were now live on
				 * their site and the product mentioned none of them:
				 *
				 *   1. Built-in AI  — the SEO / Alt Text / Content tools, which run
				 *      through this key. Five levels down in the menu.
				 *   2. "✨ WPCC AI" on Posts and Pages — the row action. Nothing
				 *      anywhere announced it; you found it by hovering a row.
				 *   3. External assistants — Claude, ChatGPT and the rest, which do
				 *      NOT use this key and are a genuinely separate path.
				 *
				 * Each row states what it is, what state it is in HERE, and carries
				 * one link. The states are read, never assumed: a row that says the
				 * WPCC AI menu is on your posts says it only when the flags that
				 * put it there are on. That is the difference between telling
				 * someone where a feature is and sending them to look for one that
				 * is switched off.
				 *
				 * Presentation only — no route, option, capability or write.
				 */
				?>
				<div class="wpcc-aip-uses">
					<p class="wpcc-aip-uses__h"><?php esc_html_e( 'What you can do now', 'ai-command-center' ); ?></p>

					<?php if ( $wpcc_o_runtime ) : ?>
						<?php
						/*
						 * 1 — Built-in AI. The one that spends this key, so it is
						 * first, and its state is the honest blocker: switched-on
						 * tools generate; switched-off tools are the reason nothing
						 * appears to happen after a successful test.
						 */
						?>
						<div class="wpcc-aip-use">
							<span class="wpcc-aip-use__icon" aria-hidden="true">✦</span>
							<div class="wpcc-aip-use__body">
								<strong><?php esc_html_e( 'Built-in AI', 'ai-command-center' ); ?></strong>
								<span>
									<?php
									if ( $wpcc_tools_on ) {
										/*
										 * Names the tools that are ON, and nothing else.
										 *
										 * An earlier draft of this line listed what Built-in
										 * AI can generate — "SEO descriptions, image alt text
										 * or draft content" — regardless of which tools were
										 * actually switched on, so a site running SEO and
										 * Content was told it could produce alt text while
										 * the Alt Text tool sat off. Promising a capability
										 * the site cannot currently perform is the exact
										 * defect this release already fixed in the operation
										 * catalogue; the tool names carry the meaning and
										 * cannot drift from the state.
										 *
										 * wp_sprintf_l() gives "SEO and Content" in English
										 * and whatever the locale's list conjunction is
										 * elsewhere; _n() keeps the verb agreeing with it.
										 */
										echo esc_html(
											sprintf(
												/* translators: %s: the built-in AI tool that is switched on, or a localized list of them, e.g. "SEO" / "SEO and Content". */
												_n(
													'The %s tool is on and generates through this connection, from inside WordPress.',
													'The %s tools are on and generate through this connection, from inside WordPress.',
													count( $wpcc_tools_on ),
													'ai-command-center'
												),
												wp_sprintf_l( '%l', array_values( $wpcc_tools_on ) )
											)
										);
									} else {
										esc_html_e( 'Generate SEO descriptions, image alt text and draft content from inside WordPress. All three tools are switched off for this site, so nothing generates yet.', 'ai-command-center' );
									}
									?>
								</span>
							</div>
							<?php if ( $wpcc_tools_on ) : ?>
								<a class="button button-small wpcc-aip-jump" href="#wpcc-bai-tools-h"><?php esc_html_e( 'Open Built-in AI', 'ai-command-center' ); ?></a>
							<?php else : ?>
								<a class="button button-small button-primary wpcc-aip-jump" href="#wpcc-bai-tools-h"><?php esc_html_e( 'Turn one on', 'ai-command-center' ); ?></a>
							<?php endif; ?>
						</div>

						<?php
						/*
						 * 2 — The row action. Named exactly as it appears ("✨ WPCC
						 * AI"), on the screens it appears on (the Posts and Pages
						 * LISTS — there is no block-editor sidebar, and sending
						 * someone into the editor to hunt for a menu that lives on
						 * the list screen would replace one dead end with another).
						 *
						 * The approval promise is stated here rather than in a
						 * footnote: "a suggestion is a draft you approve" is the
						 * single fact that makes a customer willing to try it.
						 */
						?>
						<div class="wpcc-aip-use">
							<span class="wpcc-aip-use__icon" aria-hidden="true">✎</span>
							<div class="wpcc-aip-use__body">
								<strong><?php esc_html_e( 'WPCC AI on your posts and pages', 'ai-command-center' ); ?></strong>
								<span>
									<?php
									if ( $wpcc_editor_actions ) {
										echo esc_html(
											sprintf(
												/* translators: %s: comma-separated list of what can be generated, e.g. "Title, Excerpt, SEO title and description". */
												__( 'Open Posts or Pages and hover any row — “✨ WPCC AI” is now there, and generates: %s. Every suggestion arrives as a draft you review before anything changes.', 'ai-command-center' ),
												implode( ', ', $wpcc_editor_actions )
											)
										);
									} else {
										esc_html_e( 'Hover a row in Posts or Pages for “✨ WPCC AI” and generate a title, excerpt or SEO meta in place. It appears once you switch on the Content or SEO tool above — that switch is what puts it there.', 'ai-command-center' );
									}
									?>
								</span>
							</div>
							<?php if ( $wpcc_editor_actions ) : ?>
								<a class="button button-small" href="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>"><?php esc_html_e( 'Open Posts', 'ai-command-center' ); ?></a>
							<?php endif; ?>
						</div>
					<?php endif; ?>

					<?php
					/*
					 * 3 — External assistants. Shown even when the runtime cannot
					 * use this provider, because this path does not use the key at
					 * all — it is token-authenticated MCP/REST. Saying so is the
					 * point: the surrounding screen is about a provider key, and a
					 * customer who assumes their key is what connects Claude will
					 * wire up the wrong thing. The state is real (usable tokens,
					 * and whether one has ever actually called).
					 */
					$wpcc_assistant = \WPCommandCenter\Admin\ConnectionStatus::get();
					$wpcc_use_tok   = (int) $wpcc_assistant['active_tokens'];
					?>
					<div class="wpcc-aip-use">
						<span class="wpcc-aip-use__icon" aria-hidden="true">◇</span>
						<div class="wpcc-aip-use__body">
							<strong><?php esc_html_e( 'Claude, ChatGPT and other assistants', 'ai-command-center' ); ?></strong>
							<span>
								<?php
								if ( \WPCommandCenter\Admin\ConnectionStatus::STATE_NO_TOKEN === $wpcc_assistant['state'] ) {
									esc_html_e( 'Let an assistant work on this site directly. It needs its own access token, not this provider key. Nothing it asks for is applied on its own — changes wait for your approval.', 'ai-command-center' );
								} elseif ( \WPCommandCenter\Admin\ConnectionStatus::STATE_UNUSED === $wpcc_assistant['state'] ) {
									echo esc_html(
										sprintf(
											/* translators: %s: number of access tokens, already formatted. */
											_n(
												'%s access token is ready, and nothing has connected with it yet. Finish the setup inside your assistant, then ask it about this site.',
												'%s access tokens are ready, and nothing has connected with them yet. Finish the setup inside your assistant, then ask it about this site.',
												$wpcc_use_tok,
												'ai-command-center'
											),
											number_format_i18n( $wpcc_use_tok )
										)
									);
								} else {
									echo esc_html(
										sprintf(
											/* translators: %s: sentence about the last assistant request, e.g. "Last request 2 hours ago." */
											__( 'An assistant is already connected. %s Changes it asks for still wait for your approval.', 'ai-command-center' ),
											(string) $wpcc_assistant['detail']
										)
									);
								}
								?>
							</span>
						</div>
						<a class="button button-small<?php echo \WPCommandCenter\Admin\ConnectionStatus::STATE_NO_TOKEN === $wpcc_assistant['state'] ? ' button-primary' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=wpcc-settings&wpcc_tab=connections&cpane=assistants' ) ); ?>">
							<?php
							echo \WPCommandCenter\Admin\ConnectionStatus::STATE_NO_TOKEN === $wpcc_assistant['state']
								? esc_html__( 'Connect an assistant', 'ai-command-center' )
								: esc_html__( 'Assistants', 'ai-command-center' );
							?>
						</a>
					</div>
				</div>
			<?php endif; ?>
		</div>
	<?php elseif ( $wpcc_notice ) : ?>
		<div class="notice inline notice-<?php echo esc_attr( $wpcc_notice['type'] ); ?>" role="alert"><p><?php echo esc_html( $wpcc_notice['message'] ); ?></p></div>
	<?php endif; ?>

	<?php
	/*
	 * This screen opened with six competing display blocks before the customer
	 * reached a single control: a dark gradient hero, a percentage "readiness"
	 * ring drawn around a five-item checklist, four KPI tiles reporting on (often)
	 * one connection, a pending-approvals banner that belongs to Activity, an
	 * Inspect→Plan→Approve→Execute→Verify→Rollback diagram of our own internals,
	 * and a fourth copy of the trust chips. None of it was a setting.
	 *
	 * What is left is the one warning that changes what the customer should do
	 * next. Real connection state is shown by the connection cards below, where
	 * the actions are — a stat tile that says "1 Connections" above a list of one
	 * connection is not information. The heading and lede come from the hub
	 * (settings-ai.php), so they are not repeated here.
	 */
	?>
	<?php if ( $wpcc_health['attention'] > 0 ) : ?>
		<div class="wpcc-aip-warn" role="status">⚠ <?php printf( esc_html( /* translators: %d: number */ _n( '%d connection needs attention. Open it below for the recommended fix.', '%d connections need attention. Open them below for the recommended fix.', $wpcc_health['attention'], 'ai-command-center' ) ), (int) $wpcc_health['attention'] ); ?></div>
	<?php elseif ( '' === $wpcc_default && ! empty( $wpcc_conns ) ) : ?>
		<div class="wpcc-aip-warn" role="status"><?php esc_html_e( 'No default connection yet. Add a key to a connection and set it as default so AI features have something to use.', 'ai-command-center' ); ?></div>
	<?php endif; ?>

	<!-- ===== Built-in AI tools enablement (Phase 4) ===== -->
	<?php require WPCC_PLUGIN_DIR . 'includes/Admin/views/partials/builtin-ai-tools.php'; ?>

	<?php
	/*
	 * Recent AI activity — only once there is AI to have activity from.
	 *
	 * This feed is not scoped to built-in AI: it reports governed engine events, and the
	 * queue worker ticks on a schedule on every install. So a site where nobody has
	 * configured anything opened this setup screen to a wall of "Operation worker
	 * started / completed", a "100 recent events" counter, and a heading calling all of
	 * it AI activity — on the very screen whose job is to explain that built-in AI is
	 * off until you turn it on. It contradicted the page's own message and read as
	 * machine output leaking into a product surface.
	 *
	 * The events are still recorded and still visible under Settings > Advanced > System,
	 * which is where engine detail belongs. Here the section simply waits until a
	 * connection exists, so the first thing a new customer sees on this screen is the
	 * setup path rather than a log of things they did not do.
	 */
	?>
	<?php if ( ! empty( $wpcc_conns ) ) : ?>
	<!-- ===== Recent AI activity ===== -->
	<h2><?php esc_html_e( 'Recent AI activity', 'ai-command-center' ); ?></h2>
	<div style="display:grid;grid-template-columns:1.2fr 1fr;gap:16px;align-items:start;">
		<div style="background:#fff;border:1px solid #dcdfe3;border-radius:12px;padding:16px 18px;">
			<?php // The card repeated the section heading sitting directly above it. ?>
			<div style="display:flex;justify-content:flex-end;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px;">
				<?php if ( (int) $wpcc_act['pending_approvals'] > 0 ) : ?>
					<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=wpcc-activity&wpcc_tab=approvals' ) ); ?>"><?php printf( esc_html( /* translators: %d: number */ _n( '%d pending approval', '%d pending approvals', (int) $wpcc_act['pending_approvals'], 'ai-command-center' ) ), (int) $wpcc_act['pending_approvals'] ); ?></a>
				<?php endif; ?>
			</div>
			<?php if ( empty( $wpcc_feed ) ) : ?>
				<p class="muted" style="font-size:13px;margin:0;"><?php esc_html_e( 'No activity yet. When AI or an agent acts on this site, the governed history appears here — every action recorded, reversible where supported.', 'ai-command-center' ); ?></p>
			<?php else : ?>
				<?php
				// Category → dashicon (visual scanning aid; presentation only).
				$wpcc_cat_icon = [
					'rollback' => 'dashicons-undo', 'connection' => 'dashicons-admin-links', 'generation' => 'dashicons-superhero',
					'agent' => 'dashicons-rest-api', 'change' => 'dashicons-edit', 'operation' => 'dashicons-controls-play',
					'security' => 'dashicons-shield', 'patch' => 'dashicons-media-code', 'activity' => 'dashicons-marker',
				];
				// Group by day bucket (Today / Earlier) using existing timestamps — no new data.
				$wpcc_today = (int) current_time( 'timestamp' ) - (int) ( current_time( 'timestamp' ) % DAY_IN_SECONDS );
				$wpcc_bucket = '';
				?>
				<ul class="wpcc-aip-timeline" aria-label="<?php esc_attr_e( 'Recent AI activity', 'ai-command-center' ); ?>">
					<?php foreach ( $wpcc_feed as $ev ) :
						$b = ( $ev['time'] && $ev['time'] >= $wpcc_today ) ? 'today' : 'earlier';
						if ( $b !== $wpcc_bucket ) :
							$wpcc_bucket = $b;
							?>
							<li class="grp"><?php echo 'today' === $b ? esc_html__( 'Today', 'ai-command-center' ) : esc_html__( 'Earlier', 'ai-command-center' ); ?></li>
						<?php endif; ?>
						<li class="ev">
							<span class="ic" style="color:<?php echo esc_attr( $ev['color'] ); ?>" aria-hidden="true"><span class="dashicons <?php echo esc_attr( $wpcc_cat_icon[ $ev['category'] ] ?? 'dashicons-marker' ); ?>"></span></span>
							<span class="bd"><strong><?php echo esc_html( $ev['cat_label'] ); ?></strong> <span class="muted"><?php echo esc_html( $ev['label'] ); ?></span><?php if ( '' !== $ev['actor'] ) : ?> <span class="muted">· <?php echo esc_html( $ev['actor'] ); ?></span><?php endif; ?></span>
							<span class="tm muted"><?php echo $ev['time'] ? esc_html( sprintf( /* translators: %s ago */ __( '%s ago', 'ai-command-center' ), human_time_diff( $ev['time'], time() ) ) ) : ''; ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<p style="margin:12px 0 0;display:flex;gap:8px;flex-wrap:wrap;">
				<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=wpcc-history&wpcc_tab=changes' ) ); ?>"><?php esc_html_e( 'Review changes & undo', 'ai-command-center' ); ?></a>
				<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=wpcc-activity&wpcc_tab=approvals' ) ); ?>"><?php esc_html_e( 'Approvals', 'ai-command-center' ); ?></a>
				<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=wpcc-settings&wpcc_tab=connections&cpane=assistants' ) ); ?>"><?php esc_html_e( 'Connect an assistant', 'ai-command-center' ); ?></a>
			</p>
		</div>
		<div style="display:grid;gap:10px;">
			<div class="wpcc-aip-kpi"><div class="v"><?php echo (int) $wpcc_act['events']; ?></div><div class="l"><?php esc_html_e( 'Recent events', 'ai-command-center' ); ?></div></div>
			<div class="wpcc-aip-kpi"><div class="v" style="color:<?php echo (int) $wpcc_act['pending_approvals'] ? '#d63638' : '#0a7a33'; ?>;"><?php echo (int) $wpcc_act['pending_approvals']; ?></div><div class="l"><?php esc_html_e( 'Pending approvals', 'ai-command-center' ); ?></div></div>
			<?php
			/*
			 * Token usage.
			 *
			 * This panel said "Not tracked yet" even to a customer who had just run real
			 * generations and would be billed for them. The providers were reporting the
			 * numbers all along — the transports decoded the response, took the text and
			 * discarded the usage block. They now keep it, and UsageLedger aggregates it.
			 *
			 * Cost stays absent on purpose. Nothing in the product maintains a versioned
			 * provider price list, so any figure here would be a guess that drifts out of
			 * date silently and that someone might reconcile against a real invoice.
			 * Tokens are a fact the provider stated; a price would not be.
			 */
			$wpcc_usage = \WPCommandCenter\Ai\Platform\UsageLedger::summary();
			?>
			<div class="wpcc-aip-kpi">
				<?php if ( $wpcc_usage['tracked'] ) : ?>
					<div class="v"><?php echo esc_html( number_format_i18n( $wpcc_usage['total_tokens'] ) ); ?></div>
					<div class="l"><?php esc_html_e( 'Tokens used', 'ai-command-center' ); ?></div>
					<div class="muted" style="font-size:11px;margin-top:6px;line-height:1.5;">
						<?php
						printf(
							/* translators: %1$s: input token count, %2$s: output token count. */
							esc_html__( '%1$s in · %2$s out', 'ai-command-center' ),
							esc_html( number_format_i18n( $wpcc_usage['input'] ) ),
							esc_html( number_format_i18n( $wpcc_usage['output'] ) )
						);
						echo '<br />';
						printf(
							/* translators: %s: number of generation calls. */
							esc_html( _n( 'across %s generation', 'across %s generations', $wpcc_usage['calls'], 'ai-command-center' ) ),
							esc_html( number_format_i18n( $wpcc_usage['calls'] ) )
						);
						if ( $wpcc_usage['partial'] ) {
							echo '<br />';
							printf(
								/* translators: %s: number of calls whose provider reported no usage. */
								esc_html( _n( '%s call reported no usage, so this is a minimum.', '%s calls reported no usage, so this is a minimum.', $wpcc_usage['unreported_calls'], 'ai-command-center' ) ),
								esc_html( number_format_i18n( $wpcc_usage['unreported_calls'] ) )
							);
						}
						?>
						<br /><?php esc_html_e( 'Cost is not shown — no price list ships with the plugin, and an estimate could be wrong.', 'ai-command-center' ); ?>
					</div>
				<?php else : ?>
					<div class="v" style="font-size:15px;color:#646970;"><?php esc_html_e( 'Nothing generated yet', 'ai-command-center' ); ?></div>
					<div class="l"><?php esc_html_e( 'Tokens used', 'ai-command-center' ); ?></div>
					<div class="muted" style="font-size:11px;margin-top:6px;line-height:1.5;">
						<?php esc_html_e( 'Token counts appear here after your first Built-in AI generation. Cost is not shown — no price list ships with the plugin.', 'ai-command-center' ); ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<?php if ( $wpcc_usage['tracked'] ) : ?>
		<?php $wpcc_usage_rows = \WPCommandCenter\Ai\Platform\UsageLedger::breakdown(); ?>
		<details style="max-width:760px;margin:0 0 4px;">
			<summary style="cursor:pointer;font-size:12px;font-weight:600;color:#2271b1;"><?php esc_html_e( 'Token usage by feature and model', 'ai-command-center' ); ?></summary>
			<div style="overflow-x:auto;">
				<table class="widefat striped" style="margin-top:8px;">
					<caption class="screen-reader-text"><?php esc_html_e( 'Token usage broken down by feature, provider and model', 'ai-command-center' ); ?></caption>
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Feature', 'ai-command-center' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Provider', 'ai-command-center' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Model', 'ai-command-center' ); ?></th>
							<th scope="col" style="text-align:right;"><?php esc_html_e( 'Calls', 'ai-command-center' ); ?></th>
							<th scope="col" style="text-align:right;"><?php esc_html_e( 'In', 'ai-command-center' ); ?></th>
							<th scope="col" style="text-align:right;"><?php esc_html_e( 'Out', 'ai-command-center' ); ?></th>
							<th scope="col" style="text-align:right;"><?php esc_html_e( 'Total', 'ai-command-center' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $wpcc_usage_rows as $wpcc_ur ) : ?>
							<tr>
								<td><?php echo esc_html( \WPCommandCenter\Ai\Platform\ConnectionStore::FEATURES[ $wpcc_ur['feature'] ] ?? $wpcc_ur['feature'] ); ?></td>
								<td><?php echo esc_html( $wpcc_ur['provider'] ); ?></td>
								<td><code><?php echo esc_html( $wpcc_ur['model'] ); ?></code></td>
								<td style="text-align:right;"><?php echo esc_html( number_format_i18n( (int) $wpcc_ur['calls'] ) ); ?></td>
								<td style="text-align:right;"><?php echo esc_html( number_format_i18n( (int) $wpcc_ur['input'] ) ); ?></td>
								<td style="text-align:right;"><?php echo esc_html( number_format_i18n( (int) $wpcc_ur['output'] ) ); ?></td>
								<td style="text-align:right;"><strong><?php echo esc_html( number_format_i18n( (int) $wpcc_ur['total'] ) ); ?></strong></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<p class="muted" style="font-size:11px;margin:6px 0 0;"><?php esc_html_e( 'Counts as the provider reported them. Token counts only — no prompt or generated text is stored to produce this.', 'ai-command-center' ); ?></p>
		</details>
	<?php endif; ?>

	<?php endif; ?>
	<!-- ===== Quick action ===== -->
	<p style="margin:18px 0;"><button type="button" class="button button-primary button-hero" id="wpcc-aip-new" aria-expanded="false" aria-controls="wpcc-aip-wizard">+ <?php esc_html_e( 'New connection', 'ai-command-center' ); ?></button></p>

	<!-- ===== Connection wizard (progressive; degrades to a full form without JS) ===== -->
	<form method="post" class="wpcc-aip-wizard" id="wpcc-aip-wizard" aria-label="<?php esc_attr_e( 'New connection wizard', 'ai-command-center' ); ?>">
		<?php wp_nonce_field( ConnectionController::NONCE ); ?>
		<input type="hidden" name="wpcc_conn_action" value="create" />
		<input type="hidden" name="wpcc_model" value="custom" />
		<div class="wpcc-aip-steps" aria-hidden="true"><span class="s active"></span><span class="s"></span><span class="s"></span><span class="s"></span><span class="s"></span></div>

		<div class="wpcc-aip-step active" data-step="1">
			<h3><?php esc_html_e( 'Step 1 — Choose a provider', 'ai-command-center' ); ?></h3>
			<p class="muted" style="font-size:13px;"><?php esc_html_e( 'Pick the AI service. Cloud = hosted (Claude, GPT, Gemini); Local = a model on your own machine (Ollama, LM Studio); Gateway / Custom = your own endpoint. Only Anthropic powers WPCC’s features today — each option shows whether WPCC can use it, test it, or just store it.', 'ai-command-center' ); ?></p>
			<div class="wpcc-aip-field">
				<label for="wpcc-w-provider"><?php esc_html_e( 'Provider', 'ai-command-center' ); ?></label>
				<select name="wpcc_provider" id="wpcc-w-provider">
					<?php foreach ( [ 'cloud' => __( 'Cloud', 'ai-command-center' ), 'local' => __( 'Local', 'ai-command-center' ), 'gateway' => __( 'Gateway / Custom', 'ai-command-center' ) ] as $gk => $glabel ) : ?>
						<?php if ( ! empty( $wpcc_groups[ $gk ] ) ) : ?>
							<optgroup label="<?php echo esc_attr( $glabel ); ?>">
								<?php foreach ( $wpcc_groups[ $gk ] as $pid => $plabel ) : ?>
									<option value="<?php echo esc_attr( $pid ); ?>"><?php echo esc_html( $plabel ); ?> — <?php echo esc_html( ProviderCatalog::runtime_usable( $pid ) ? __( 'used by runtime', 'ai-command-center' ) : ( ProviderCatalog::test_supported( $pid ) ? __( 'testable', 'ai-command-center' ) : __( 'stored only', 'ai-command-center' ) ) ); ?></option>
								<?php endforeach; ?>
							</optgroup>
						<?php endif; ?>
					<?php endforeach; ?>
				</select>
			</div>
		</div>

		<div class="wpcc-aip-step" data-step="2">
			<h3><?php esc_html_e( 'Step 2 — Name & where it runs', 'ai-command-center' ); ?></h3>
			<div class="wpcc-aip-field"><label for="wpcc-w-name"><?php esc_html_e( 'Connection name', 'ai-command-center' ); ?></label><input type="text" id="wpcc-w-name" name="wpcc_name" placeholder="<?php esc_attr_e( 'e.g. Production Claude', 'ai-command-center' ); ?>" /></div>
			<div class="wpcc-aip-field" id="wpcc-w-endpoint-field"><label for="wpcc-w-endpoint"><?php esc_html_e( 'Base URL', 'ai-command-center' ); ?> <span class="muted">(<?php esc_html_e( 'required for this provider', 'ai-command-center' ); ?>)</span></label><input type="url" id="wpcc-w-endpoint" name="wpcc_endpoint" style="font-family:monospace;" placeholder="https://…" /><p class="muted" style="font-size:12px;margin:4px 0 0;"><?php esc_html_e( 'Cloud providers use their official URL automatically — you only set this for local, Azure, gateway, or custom endpoints.', 'ai-command-center' ); ?></p></div>
		</div>

		<div class="wpcc-aip-step" data-step="3">
			<h3><?php esc_html_e( 'Step 3 — Credentials', 'ai-command-center' ); ?></h3>
			<p class="muted" style="font-size:13px;"><?php esc_html_e( 'Paste your API key. It is stored on this site and never shown again. Local models usually need no key.', 'ai-command-center' ); ?></p>
			<?php
			// Disclosure BEFORE a key is entered, not buried in the readme: the user
			// is about to authorise this site to send content to a third party, and
			// that is the moment to say so plainly.
			?>
			<div role="note" style="margin:0 0 12px;padding:12px 14px;background:#f0f6fc;border-left:3px solid #2271b1;border-radius:0 4px 4px 0;font-size:13px;color:#1d2327;max-width:640px;">
				<strong style="display:block;margin-bottom:4px;"><?php esc_html_e( 'What this key does', 'ai-command-center' ); ?></strong>
				<?php esc_html_e( 'When you use a built-in AI tool, the content it works on — for example a post title, an excerpt, or an image — is sent to the provider you selected, under your account and their terms. It is stored on this site so the plugin can authenticate, never shown again, and never written to logs. You can remove it at any time. Connecting an external AI assistant does not require this key.', 'ai-command-center' ); ?>
			</div>
			<div class="wpcc-aip-field"><label for="wpcc-w-key"><?php esc_html_e( 'API key', 'ai-command-center' ); ?></label><input type="password" id="wpcc-w-key" name="wpcc_key" autocomplete="off" spellcheck="false" style="font-family:monospace;" placeholder="<?php esc_attr_e( 'Paste your API key (optional for local)', 'ai-command-center' ); ?>" /></div>
		</div>

		<div class="wpcc-aip-step" data-step="4">
			<h3><?php esc_html_e( 'Step 4 — Model', 'ai-command-center' ); ?></h3>
			<p class="muted" style="font-size:13px;"><?php esc_html_e( 'Recommended models are shown during setup. After you save and test this connection, WP Command Center automatically adds any other models your account exposes — you’ll find them in this connection’s Model list under “Edit”. Providers that don’t publish a model list simply keep the recommended set. You can change the model any time.', 'ai-command-center' ); ?></p>
			<div class="wpcc-aip-field">
				<label for="wpcc-w-model-select"><?php esc_html_e( 'Model', 'ai-command-center' ); ?></label>
				<input type="search" id="wpcc-w-model-search" style="display:none;width:100%;margin-bottom:6px;" placeholder="<?php esc_attr_e( 'Filter models…', 'ai-command-center' ); ?>" aria-label="<?php esc_attr_e( 'Filter models', 'ai-command-center' ); ?>" />
				<select id="wpcc-w-model-select" style="display:none;"></select>
				<input type="text" id="wpcc-w-model" name="wpcc_model_custom" style="font-family:monospace;" placeholder="model-id" />
				<p class="muted" id="wpcc-w-model-help" style="font-size:12px;margin:4px 0 0;"></p>
			</div>
			<details class="wpcc-aip-advanced" style="margin-top:4px;">
				<summary style="cursor:pointer;font-size:13px;"><?php esc_html_e( 'Advanced options', 'ai-command-center' ); ?></summary>
				<div class="wpcc-aip-field" style="margin-top:10px;">
					<label for="wpcc-w-tags"><?php esc_html_e( 'Tags', 'ai-command-center' ); ?> <span class="muted">(<?php esc_html_e( 'optional', 'ai-command-center' ); ?>)</span></label>
					<input type="text" id="wpcc-w-tags" name="wpcc_tags" placeholder="prod, premium" />
					<p class="muted" style="font-size:12px;margin:4px 0 0;"><?php esc_html_e( 'Internal labels to organize and route your connections (for example “prod” or “cheap”). Optional, used only inside WP Command Center — never sent to the provider.', 'ai-command-center' ); ?></p>
				</div>
				<div class="wpcc-aip-field" id="wpcc-w-deployment-field" style="display:none;margin-top:10px;">
					<label for="wpcc-w-deployment"><?php esc_html_e( 'Deployment name', 'ai-command-center' ); ?></label>
					<input type="text" id="wpcc-w-deployment" name="wpcc_deployment" />
					<p class="muted" style="font-size:12px;margin:4px 0 0;"><?php esc_html_e( 'Azure OpenAI only: the deployment name you created for this model in your Azure resource.', 'ai-command-center' ); ?></p>
				</div>
			</details>
		</div>

		<div class="wpcc-aip-step" data-step="5">
			<h3><?php esc_html_e( 'Step 5 — Create & test', 'ai-command-center' ); ?></h3>
			<p class="muted" style="font-size:13px;"><?php esc_html_e( 'We’ll create the connection now. Then use “Test” on its card to verify the key works — no changes are made to your site.', 'ai-command-center' ); ?></p>
			<p style="font-size:13px;"><?php esc_html_e( 'Adding a key does not turn AI features on by itself — built-in AI screens are enabled per site.', 'ai-command-center' ); ?></p>
		</div>

		<div class="wpcc-aip-wnav">
			<button type="button" class="button" id="wpcc-w-back" style="visibility:hidden;"><?php esc_html_e( 'Back', 'ai-command-center' ); ?></button>
			<span>
				<button type="button" class="button" id="wpcc-w-cancel"><?php esc_html_e( 'Cancel', 'ai-command-center' ); ?></button>
				<button type="button" class="button button-primary" id="wpcc-w-next"><?php esc_html_e( 'Next', 'ai-command-center' ); ?></button>
				<button type="submit" class="button button-primary" id="wpcc-w-finish" style="display:none;"><?php esc_html_e( 'Create connection', 'ai-command-center' ); ?></button>
			</span>
		</div>
	</form>

	<!-- ===== Connections ===== -->
	<h2><?php esc_html_e( 'Your connections', 'ai-command-center' ); ?></h2>
	<?php if ( empty( $wpcc_conns ) ) : ?>
		<div class="wpcc-aip-empty">
			<h3><?php esc_html_e( 'No AI connections yet', 'ai-command-center' ); ?></h3>
			<p class="muted" style="max-width:460px;margin:0 auto 14px;"><?php esc_html_e( 'A connection links an AI provider to this site so WP Command Center can do work for you — safely, with your approval, and an undo for supported changes. Add a connection to get started.', 'ai-command-center' ); ?></p>
			<button type="button" class="button button-primary" id="wpcc-aip-new2">+ <?php esc_html_e( 'Add a connection', 'ai-command-center' ); ?></button>
		</div>
	<?php else : ?>
		<div class="wpcc-aip-cards">
			<?php foreach ( $wpcc_conns as $cid => $c ) :
				$def      = $wpcc_providers[ $c['provider'] ] ?? [];
				$has_key  = $wpcc_store->is_configured( $c );
				$is_const = $wpcc_store->credentials()->is_constant_backed( $c );
				$runtime  = $wpcc_store->runtime_usable( $c );
				$testable = $wpcc_store->testable( $c );
				$is_def   = ( $wpcc_default === $cid );
				$lt       = $c['last_test'];
				$h        = Health::of( $c, $wpcc_store );
				$caps     = Capabilities::for_provider( $c['provider'] );
				$avatar   = strtoupper( substr( (string) ( $def['label'] ?? $c['provider'] ), 0, 1 ) );

				/*
				 * Which built-in tools generate through this connection.
				 *
				 * This was already computed further down, used for one purpose — naming
				 * the consequence in the delete confirmation — and then thrown away. It
				 * is the answer to "what is this connection FOR?", which is the question
				 * a customer returning after three months opens this screen to ask, so it
				 * now travels with the connection instead of appearing only at the moment
				 * they try to destroy it.
				 */
				$wpcc_routed = [];
				foreach ( $wpcc_routes as $wpcc_feat => $wpcc_rcid ) {
					if ( $wpcc_rcid === $cid && isset( ConnectionStore::FEATURES[ $wpcc_feat ] ) ) {
						$wpcc_routed[] = ConnectionStore::FEATURES[ $wpcc_feat ];
					}
				}

				// Recorded generations attributed to this connection (0/never when unused).
				$wpcc_used = $wpcc_conn_usage[ $cid ] ?? [ 'calls' => 0, 'last' => 0 ];

				/*
				 * Which of this card's controls is THE one to press.
				 *
				 * Every action on this card used to be a `button-small` in a row, so
				 * "Test" — the thing a connection that has never passed a test needs —
				 * carried exactly the same visual weight as "Duplicate". A row of five
				 * identical buttons is a row with no recommendation in it, which leaves
				 * the customer to work out the product's own state machine. Health
				 * already knows the answer; the card now spends its one point of emphasis
				 * saying it.
				 */
				$wpcc_needs_key  = ( ! $has_key && ! $is_const );
				$wpcc_needs_test = ( $has_key && $testable && ! in_array( $h['state'], [ 'healthy', 'slow' ], true ) );
				?>
				<div class="wpcc-aip-card <?php echo $c['enabled'] ? '' : 'dim'; ?>" id="wpcc-conn-<?php echo esc_attr( $cid ); ?>">
					<div class="wpcc-aip-card__top">
						<div style="display:flex;gap:10px;align-items:center;">
							<div class="wpcc-aip-avatar" aria-hidden="true"><?php echo esc_html( $avatar ); ?></div>
							<div>
								<div class="wpcc-aip-name"><?php echo esc_html( $c['name'] ); ?></div>
								<div class="wpcc-aip-sub"><?php echo esc_html( (string) ( $def['label'] ?? $c['provider'] ) ); ?> · <?php echo esc_html( $c['dialect'] ); ?></div>
							</div>
						</div>
						<div style="text-align:right;font-size:12px;">
							<span class="wpcc-aip-dot" style="background:<?php echo esc_attr( $h['dot'] ); ?>"></span><strong><?php echo esc_html( $h['label'] ); ?></strong>
						</div>
					</div>

					<div>
						<?php if ( $is_def ) : ?><span class="wpcc-aip-badge" style="background:#e7f0fb;color:#1d62b0;"><?php esc_html_e( 'DEFAULT', 'ai-command-center' ); ?></span> <?php endif; ?>
						<?php if ( $runtime ) : ?><span class="wpcc-aip-badge" style="background:#e7f6ec;color:#0a7a33;"><?php esc_html_e( 'USED BY RUNTIME', 'ai-command-center' ); ?></span>
						<?php elseif ( $testable ) : ?><span class="wpcc-aip-badge" style="background:#eef4fb;color:#1d62b0;" title="<?php esc_attr_e( 'Configured and testable, but not used by WPCC’s AI features yet.', 'ai-command-center' ); ?>"><?php esc_html_e( 'TESTABLE', 'ai-command-center' ); ?></span>
						<?php else : ?><span class="wpcc-aip-badge" style="background:#fcf6e6;color:#8a6a00;"><?php esc_html_e( 'STORED ONLY', 'ai-command-center' ); ?></span><?php endif; ?>
						<?php foreach ( $c['tags'] as $tag ) : ?><span class="wpcc-aip-badge" style="background:#f0f0f1;color:#50575e;">#<?php echo esc_html( $tag ); ?></span> <?php endforeach; ?>
					</div>

					<div class="wpcc-aip-meta">
						<?php if ( Dialect::endpoint_editable( $c['dialect'] ) ) : ?><div><?php esc_html_e( 'Endpoint', 'ai-command-center' ); ?>: <code><?php echo esc_html( $c['endpoint'] ?: '—' ); ?></code></div><?php endif; ?>
						<div><?php esc_html_e( 'Model', 'ai-command-center' ); ?>: <code><?php echo esc_html( $c['model'] ?: ( $def['default_model'] ?? '—' ) ); ?></code></div>
						<?php
						/*
						 * The returning-customer line: what this powers, and when it last
						 * did anything.
						 *
						 * Both facts were already in the database and neither was ever shown.
						 * Routes have always been stored; UsageLedger has recorded a call
						 * count and a timestamp against a connection id since it was added.
						 * Someone coming back after six months previously got "Healthy" and
						 * five buttons, which answers neither "what is this for" nor "is
						 * anything still using it" — the two things that decide whether they
						 * keep it, re-point it, or delete it.
						 *
						 * Deliberately one line, not three. It is the highest-value row on
						 * the card and it still has to earn its space against a brief that
						 * says do not make the interface busier.
						 */
						$wpcc_use_bits = [];
						$wpcc_use_bits[] = $wpcc_routed
							? sprintf(
								/* translators: %s: comma-separated tool names, e.g. "SEO meta, Alt text". */
								__( 'Powers %s', 'ai-command-center' ),
								implode( ', ', $wpcc_routed )
							)
							: __( 'Not powering any tool', 'ai-command-center' );
						if ( $wpcc_used['calls'] > 0 && $wpcc_used['last'] > 0 ) {
							$wpcc_use_bits[] = sprintf(
								/* translators: 1: human time difference, e.g. "3 days"; 2: number of generations. */
								_n( 'last used %1$s ago (%2$s generation)', 'last used %1$s ago (%2$s generations)', (int) $wpcc_used['calls'], 'ai-command-center' ),
								human_time_diff( (int) $wpcc_used['last'], time() ),
								number_format_i18n( (int) $wpcc_used['calls'] )
							);
						} else {
							$wpcc_use_bits[] = __( 'not used yet', 'ai-command-center' );
						}
						?>
						<div><?php echo esc_html( implode( ' · ', $wpcc_use_bits ) ); ?></div>
						<?php if ( is_array( $lt ) && isset( $lt['time'] ) ) : ?>
							<div class="muted">
								<?php
								$bits = [];
								$bits[] = sprintf( /* translators: %s ago */ __( 'Last test %s ago', 'ai-command-center' ), human_time_diff( (int) $lt['time'], time() ) );
								if ( ! empty( $lt['latency_ms'] ) ) { $bits[] = sprintf( /* translators: %d ms */ __( '%d ms', 'ai-command-center' ), (int) $lt['latency_ms'] ); }
								if ( ! empty( $lt['models'] ) ) { $bits[] = sprintf( /* translators: %d models */ _n( '%d model', '%d models', (int) $lt['models'], 'ai-command-center' ), (int) $lt['models'] ); }
								echo esc_html( implode( ' · ', $bits ) );
								?>
							</div>
						<?php endif; ?>
						<?php
						/*
						 * "Working. Nothing to do." is worth saying quietly; "The key was
						 * rejected" is not. The same muted grey served both, so the one
						 * line on the card that required action looked exactly like the one
						 * that confirmed there was none.
						 */
						$wpcc_h_needs = ! in_array( $h['state'], [ 'healthy', 'slow' ], true );
						?>
						<div class="<?php echo $wpcc_h_needs ? 'wpcc-aip-todo' : 'muted'; ?>" style="margin-top:2px;"><?php echo esc_html( $h['action'] ); ?></div>
					</div>

					<details>
						<summary style="cursor:pointer;font-size:12px;font-weight:600;color:#2271b1;"><?php esc_html_e( 'Capabilities (declared)', 'ai-command-center' ); ?></summary>
						<div class="wpcc-aip-caps" style="margin-top:8px;">
							<?php foreach ( Capabilities::keys() as $ck => $clabel ) : $cv = $caps[ $ck ] ?? 'no'; ?>
								<span class="wpcc-aip-cap <?php echo in_array( $cv, [ 'yes' ], true ) ? 'on' : ''; ?>"><?php echo esc_html( $clabel ); ?>: <?php echo esc_html( Capabilities::value_label( $cv ) ); ?></span>
							<?php endforeach; ?>
						</div>
						<p class="muted" style="font-size:11px;margin:6px 0 0;"><?php esc_html_e( 'Declared from the provider’s API — not live-tested.', 'ai-command-center' ); ?></p>
					</details>

					<!-- Inline edit -->
					<details>
						<summary style="cursor:pointer;font-size:12px;font-weight:600;color:#2271b1;"><?php esc_html_e( 'Edit', 'ai-command-center' ); ?></summary>
						<form method="post" style="margin-top:8px;display:grid;gap:8px;">
							<?php wp_nonce_field( ConnectionController::NONCE ); ?>
							<input type="hidden" name="wpcc_conn_id" value="<?php echo esc_attr( $cid ); ?>" />
							<label style="font-size:12px;"><?php esc_html_e( 'Name', 'ai-command-center' ); ?><input type="text" name="wpcc_name" value="<?php echo esc_attr( $c['name'] ); ?>" style="width:100%;" /></label>
							<?php if ( Dialect::endpoint_editable( $c['dialect'] ) ) : ?><label style="font-size:12px;"><?php esc_html_e( 'Base URL', 'ai-command-center' ); ?><input type="url" name="wpcc_endpoint" value="<?php echo esc_attr( $c['endpoint'] ); ?>" style="width:100%;font-family:monospace;" /></label><?php endif; ?>
							<?php
								$wpcc_rec   = ( isset( $def['models'] ) && is_array( $def['models'] ) ) ? $def['models'] : [];
								$wpcc_disc  = ( is_array( $lt ) && ! empty( $lt['models_list'] ) && is_array( $lt['models_list'] ) ) ? $lt['models_list'] : [];
								$wpcc_cur   = (string) $c['model'];
								$wpcc_known = isset( $wpcc_rec[ $wpcc_cur ] ) || in_array( $wpcc_cur, $wpcc_disc, true );
								// Copy selection only: providers whose connection test lists account models.
								$wpcc_lists = in_array( $c['dialect'], [ Dialect::OPENAI, Dialect::GEMINI ], true );
								?>
								<label style="font-size:12px;"><?php esc_html_e( 'Model', 'ai-command-center' ); ?>
									<select name="wpcc_model" class="wpcc-edit-model" style="width:100%;">
										<?php if ( $wpcc_rec ) : ?><optgroup label="<?php esc_attr_e( 'Recommended', 'ai-command-center' ); ?>"><?php foreach ( $wpcc_rec as $wpcc_mid => $wpcc_mlabel ) : ?><option value="<?php echo esc_attr( $wpcc_mid ); ?>" <?php selected( $wpcc_cur === (string) $wpcc_mid ); ?>><?php echo esc_html( $wpcc_mlabel ); ?></option><?php endforeach; ?></optgroup><?php endif; ?>
										<?php if ( $wpcc_disc ) : ?><optgroup label="<?php echo esc_attr( sprintf( /* translators: %d: number */ __( 'Discovered from your account (%d)', 'ai-command-center' ), count( $wpcc_disc ) ) ); ?>"><?php foreach ( $wpcc_disc as $wpcc_did ) : if ( isset( $wpcc_rec[ $wpcc_did ] ) ) { continue; } ?><option value="<?php echo esc_attr( $wpcc_did ); ?>" <?php selected( $wpcc_cur === (string) $wpcc_did ); ?>><?php echo esc_html( $wpcc_did ); ?></option><?php endforeach; ?></optgroup><?php endif; ?>
										<option value="custom" <?php selected( ! $wpcc_known ); ?>><?php esc_html_e( 'Custom model ID…', 'ai-command-center' ); ?></option>
									</select>
								</label>
								<input type="text" name="wpcc_model_custom" class="wpcc-edit-model-custom" value="<?php echo esc_attr( $wpcc_known ? '' : $wpcc_cur ); ?>" style="width:100%;font-family:monospace;<?php echo $wpcc_known ? 'display:none;' : ''; ?>" placeholder="<?php echo esc_attr( (string) ( $def['default_model'] ?? 'model-id' ) ); ?>" />
								<?php if ( ! empty( $wpcc_disc ) ) : ?>
									<p class="muted" style="font-size:11px;margin:0;"><?php esc_html_e( 'Recommended = our defaults. Discovered from your account = pulled live from your last connection test. Custom = enter any model id.', 'ai-command-center' ); ?></p>
								<?php elseif ( $wpcc_lists ) : ?>
									<p class="muted" style="font-size:11px;margin:0;"><?php esc_html_e( 'Test this connection once to discover the additional models available to your account.', 'ai-command-center' ); ?></p>
								<?php else : ?>
									<p class="muted" style="font-size:11px;margin:0;"><?php esc_html_e( 'This provider offers the recommended models only — it doesn’t publish an account model list to discover. Nothing is broken; use Custom to enter any model id.', 'ai-command-center' ); ?></p>
								<?php endif; ?>
							<label style="font-size:12px;"><?php esc_html_e( 'Tags', 'ai-command-center' ); ?><input type="text" name="wpcc_tags" value="<?php echo esc_attr( implode( ', ', $c['tags'] ) ); ?>" style="width:100%;" placeholder="prod, cheap" /></label>
							<div><button type="submit" name="wpcc_conn_action" value="update" class="button button-small"><?php esc_html_e( 'Save changes', 'ai-command-center' ); ?></button></div>
						</form>
						<?php if ( ! $is_const ) : ?>
						<form method="post" style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
							<?php wp_nonce_field( ConnectionController::NONCE ); ?>
							<input type="hidden" name="wpcc_conn_id" value="<?php echo esc_attr( $cid ); ?>" />
							<input type="password" name="wpcc_key" autocomplete="off" spellcheck="false" style="font-family:monospace;max-width:240px;" placeholder="<?php echo $has_key ? esc_attr__( '•••••• (replace key)', 'ai-command-center' ) : esc_attr__( 'API key', 'ai-command-center' ); ?>" />
							<button type="submit" name="wpcc_conn_action" value="update_key" class="button button-small"><?php echo $has_key ? esc_html__( 'Update key', 'ai-command-center' ) : esc_html__( 'Save key', 'ai-command-center' ); ?></button>
							<?php if ( $has_key ) : ?><button type="submit" name="wpcc_conn_action" value="clear_key" class="button button-small button-link-delete wpcc-conn-confirm" data-confirm="clear_key" data-conn-name="<?php echo esc_attr( $c['name'] ); ?>"><?php esc_html_e( 'Remove key', 'ai-command-center' ); ?></button><?php endif; ?>
						</form>
						<?php else : ?><p class="muted" style="font-size:12px;margin-top:8px;"><?php esc_html_e( 'Key defined in wp-config.php (constant) — read-only.', 'ai-command-center' ); ?></p><?php endif; ?>
					</details>

					<?php
					/*
					 * Actions, in two ranks instead of one row of five.
					 *
					 * Everything a customer does routinely — test it, make it the default,
					 * turn it off — stays on the card. Duplicate and Delete move behind a
					 * disclosure: neither is a thing anyone does on a normal visit, and one
					 * of them destroys a stored API key. Putting an irreversible action at
					 * the same rank as "Test", in the same size and colour, is how a tired
					 * person deletes the wrong connection.
					 *
					 * Nothing is removed and nothing changes what any control does. Same
					 * forms, same nonces, same handler, same confirmation dialog — this is
					 * rank and grouping only.
					 */
					?>
					<!-- Actions -->
					<div class="wpcc-aip-actions">
						<form method="post"><?php wp_nonce_field( ConnectionController::NONCE ); ?><input type="hidden" name="wpcc_conn_id" value="<?php echo esc_attr( $cid ); ?>" /><button type="submit" name="wpcc_conn_action" value="test" class="button button-small <?php echo $wpcc_needs_test ? 'button-primary' : ''; ?>" <?php disabled( ! $testable || ! $has_key ); ?>><?php esc_html_e( 'Test', 'ai-command-center' ); ?></button></form>
						<?php if ( $wpcc_needs_key ) : ?>
							<?php // The only thing this connection can usefully do next: it has no key. ?>
							<a class="button button-small button-primary wpcc-aip-jump" href="#wpcc-conn-<?php echo esc_attr( $cid ); ?>" data-open-key="1"><?php esc_html_e( 'Add API key', 'ai-command-center' ); ?></a>
						<?php endif; ?>
						<?php if ( $runtime && ! $is_def && $has_key ) : ?><form method="post"><?php wp_nonce_field( ConnectionController::NONCE ); ?><input type="hidden" name="wpcc_conn_id" value="<?php echo esc_attr( $cid ); ?>" /><button type="submit" name="wpcc_conn_action" value="set_default" class="button button-small"><?php esc_html_e( 'Set default', 'ai-command-center' ); ?></button></form><?php endif; ?>
						<form method="post"><?php wp_nonce_field( ConnectionController::NONCE ); ?><input type="hidden" name="wpcc_conn_id" value="<?php echo esc_attr( $cid ); ?>" /><input type="hidden" name="wpcc_enabled" value="<?php echo $c['enabled'] ? '0' : '1'; ?>" /><button type="submit" name="wpcc_conn_action" value="set_enabled" class="button button-small"><?php echo $c['enabled'] ? esc_html__( 'Disable', 'ai-command-center' ) : esc_html__( 'Enable', 'ai-command-center' ); ?></button></form>
						<details class="wpcc-aip-more">
							<summary><?php esc_html_e( 'More', 'ai-command-center' ); ?></summary>
							<div class="wpcc-aip-more__body">
								<form method="post"><?php wp_nonce_field( ConnectionController::NONCE ); ?><input type="hidden" name="wpcc_conn_id" value="<?php echo esc_attr( $cid ); ?>" /><button type="submit" name="wpcc_conn_action" value="duplicate" class="button button-small"><?php esc_html_e( 'Duplicate', 'ai-command-center' ); ?></button></form>
								<?php
								/*
								 * $wpcc_routed is resolved once at the top of this card now (it
								 * answers "what does this power?" in the meta line as well). A
								 * delete confirmation that cannot name the consequence is just a
								 * speed bump; naming the tools that stop working is the whole
								 * point of asking.
								 */
								?>
								<form method="post"><?php wp_nonce_field( ConnectionController::NONCE ); ?><input type="hidden" name="wpcc_conn_id" value="<?php echo esc_attr( $cid ); ?>" /><button type="submit" name="wpcc_conn_action" value="delete" class="button button-small button-link-delete wpcc-conn-confirm" data-confirm="delete" data-conn-name="<?php echo esc_attr( $c['name'] ); ?>" data-has-key="<?php echo $has_key ? '1' : '0'; ?>" data-routed="<?php echo esc_attr( implode( ', ', $wpcc_routed ) ); ?>"><?php esc_html_e( 'Delete', 'ai-command-center' ); ?></button></form>
							</div>
						</details>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<!-- ===== Feature routing (visual) ===== -->
	<h2><?php esc_html_e( 'Feature routing', 'ai-command-center' ); ?></h2>
	<p class="muted" style="max-width:700px;font-size:13px;"><?php esc_html_e( 'Which connection powers your AI tasks. WP Command Center runs generation through the one connection you set as the default — Anthropic (Claude) and OpenAI-compatible providers both work once selected. Other providers can be saved and tested, but only a supported provider that you choose will generate. Nothing is selected automatically, and your content is sent only to the provider you pick.', 'ai-command-center' ); ?></p>
	<?php if ( empty( $wpcc_runtime_conns ) ) : ?>
		<?php if ( ! empty( $wpcc_ineligible_conns ) ) : ?>
			<p class="muted" style="font-size:13px;max-width:700px;">
				<?php
				/* translators: 1: number of healthy connections, 2: their names. */
				printf( esc_html__( 'You have %1$d connection(s) that connected and tested fine (%2$s) — but WP Command Center can only run AI through Anthropic (Claude) right now, so they can’t power features yet. Add a key to an Anthropic connection to choose routing.', 'ai-command-center' ), count( $wpcc_ineligible_conns ), esc_html( implode( ', ', $wpcc_ineligible_conns ) ) );
				?>
			</p>
		<?php else : ?>
			<p class="muted" style="font-size:13px;"><?php esc_html_e( 'Add a key to an Anthropic connection to choose feature routing.', 'ai-command-center' ); ?></p>
		<?php endif; ?>
	<?php else : ?>
		<form method="post" style="background:#fff;border:1px solid #dcdfe3;border-radius:10px;padding:16px 18px;max-width:560px;">
			<?php wp_nonce_field( ConnectionController::NONCE ); ?>
			<?php foreach ( ConnectionStore::FEATURES as $fk => $flabel ) : ?>
				<div class="wpcc-aip-route">
					<span class="f"><?php echo esc_html( $flabel ); ?><span style="display:block;font-weight:400;font-size:11.5px;color:#8a93a0;"><?php
						$wpcc_fdesc = [ 'seo_meta' => __( 'Powers AI-written SEO titles & descriptions', 'ai-command-center' ), 'alt_text' => __( 'Powers AI image alt text for accessibility & SEO', 'ai-command-center' ), 'ai_content' => __( 'Powers AI title & excerpt suggestions', 'ai-command-center' ) ];
						echo esc_html( $wpcc_fdesc[ $fk ] ?? '' );
					?></span></span>
					<span class="arrow" aria-hidden="true">→</span>
					<label class="screen-reader-text" for="wpcc-route-<?php echo esc_attr( $fk ); ?>"><?php printf( /* translators: %s: value */ esc_html__( 'Connection for %s', 'ai-command-center' ), esc_html( $flabel ) ); ?></label>
					<select name="wpcc_route_<?php echo esc_attr( $fk ); ?>" id="wpcc-route-<?php echo esc_attr( $fk ); ?>" style="flex:1;">
						<?php foreach ( $wpcc_runtime_conns as $rid => $rname ) : ?>
							<option value="<?php echo esc_attr( $rid ); ?>" <?php selected( ( $wpcc_routes[ $fk ] ?? '' ) === $rid ); ?>><?php echo esc_html( $rname ); ?></option>
						<?php endforeach; ?>
						<?php foreach ( $wpcc_ineligible_conns as $iname ) : ?>
							<option disabled><?php echo esc_html( sprintf( /* translators: %s: connection label */ __( '%s — healthy, but WP Command Center can’t run it yet', 'ai-command-center' ), $iname ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			<?php endforeach; ?>
			<button type="submit" name="wpcc_conn_action" value="save_routes" class="button" style="margin-top:12px;"><?php esc_html_e( 'Save routing', 'ai-command-center' ); ?></button>
			<?php if ( ! empty( $wpcc_ineligible_conns ) ) : ?>
				<p class="muted" style="font-size:12px;margin:12px 0 0;max-width:520px;"><?php esc_html_e( 'Connections marked “healthy, but WP Command Center can’t run it yet” connected and tested successfully — WP Command Center simply can’t run AI tasks through them yet (today it runs through Anthropic / Claude only). They’ll appear as selectable the moment that changes. Nothing is hidden or faked.', 'ai-command-center' ); ?></p>
			<?php endif; ?>
		</form>
	<?php endif; ?>

	<!-- ===== Next steps + security ===== -->
	<?php
	/*
	 * What happens next — and it must be BUILT-IN AI's next step, not MCP's.
	 *
	 * This list used to tell someone who had just pasted a provider key to go and
	 * connect an external assistant, on the grounds that the assistant would do
	 * the work. That is false here and it undoes the whole point of the screen:
	 * Built-in AI calls the configured
	 * provider directly (AiRuntime → AnthropicClient / OpenAiCompatibleTransport;
	 * there is no MCP anywhere in that path), which is exactly why it is the one
	 * path that needs a key. A customer who follows that instruction concludes
	 * their key did nothing, goes and connects Claude, and never finds the three
	 * tools they just paid a provider to power.
	 *
	 * The step now depends on whether the tools are actually switched on for this
	 * site, because those are two genuinely different next actions — and the
	 * screen already knows which is true rather than making the customer guess.
	 *
	 * $wpcc_ai_tools_on is resolved once at the top of this view — the outcome card
	 * needs the same answer, and two independent lookups is how the top and bottom
	 * of a screen end up disagreeing about whether a feature is on.
	 */
	?>
	<?php if ( $wpcc_store->is_configured( $wpcc_conns[ $wpcc_default ] ?? [] ) ) : ?>
		<div style="margin:18px 0 0;padding:12px 14px;background:#f0f6fc;border:1px solid #c3c4c7;border-radius:8px;max-width:720px;">
			<strong style="font-size:13px;"><?php esc_html_e( 'Your provider is ready. What happens next?', 'ai-command-center' ); ?></strong>
			<ol style="margin:8px 0 0;padding-left:20px;color:#50575e;font-size:13px;line-height:1.6;">
				<li><?php esc_html_e( 'Use “Test” on a connection to confirm the key works.', 'ai-command-center' ); ?></li>
				<?php if ( $wpcc_ai_tools_on ) : ?>
					<li><?php esc_html_e( 'Choose SEO, Alt Text or Content above to generate suggestions. Built-in AI uses this provider directly — you do not need to connect an external assistant.', 'ai-command-center' ); ?></li>
				<?php else : ?>
					<li><?php esc_html_e( 'Switch on SEO, Alt Text or Content above to start generating. Adding a key does not turn the tools on by itself.', 'ai-command-center' ); ?></li>
				<?php endif; ?>
				<li><?php esc_html_e( 'Nothing is published automatically. Every suggestion is a draft you review, and applying one follows the same approval and undo rules as any other change.', 'ai-command-center' ); ?></li>
			</ol>
			<p style="margin:10px 0 0;font-size:12px;color:#646970;">
				<?php
				// Named as the SEPARATE, OPTIONAL path it is — the correct mental
				// model — rather than as a step in this one.
				printf(
					/* translators: %1$s: opening link tag, %2$s: closing link tag */
					esc_html__( 'Connecting Claude, Cursor or another assistant is a separate, optional path that needs no provider key — see %1$sAssistants%2$s.', 'ai-command-center' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=wpcc-settings&wpcc_tab=connections&cpane=assistants' ) ) . '">',
					'</a>'
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<p class="muted" style="font-size:12px;max-width:720px;margin-top:20px;">
		<?php esc_html_e( 'Security: each key is stored in this site’s database (a WordPress option, not auto-loaded), used only for calls to that connection’s endpoint, never shown here, never written to the audit log, and never sent anywhere else. Anyone who can edit plugins could read stored options — use scoped keys. The default Anthropic connection also drives WPCC’s AI features (a wp-config constant always wins).', 'ai-command-center' ); ?>
	</p>
</div>

<?php
// PROVIDER-DRIVEN WIZARD METADATA — the single descriptor the wizard renders from
// (ProviderCatalog::metadata()). Data only: NO runtime/provider-execution/key-storage/
// security/API-contract behavior. Adding a provider = a catalog row; the wizard adapts
// with no view change. recommended_models normalize to objects so empty serialises {}.
$wpcc_provider_meta = ProviderCatalog::metadata_all();
foreach ( $wpcc_provider_meta as &$wpcc_m ) {
	$wpcc_m['recommended_models'] = ! empty( $wpcc_m['recommended_models'] ) ? $wpcc_m['recommended_models'] : new stdClass();
}
unset( $wpcc_m );
?>
<script>
(function () {
	var wiz = document.getElementById('wpcc-aip-wizard');
	if (!wiz) return;

	// ---- Provider-driven wizard: every field renders from provider metadata
	// (ProviderCatalog::metadata) — no provider-specific conditionals. Adding a
	// provider needs no code here. Model discovery is a gated seam (no backend
	// listing endpoint exists yet → curated fallback; never fabricated). ----
	var WPCC_PMETA = <?php echo wp_json_encode( $wpcc_provider_meta ); ?>;
	(function () {
		var provSel = document.getElementById('wpcc-w-provider');
		var epField = document.getElementById('wpcc-w-endpoint-field');
		var epInput = document.getElementById('wpcc-w-endpoint');
		var depField= document.getElementById('wpcc-w-deployment-field');
		var mdlSel  = document.getElementById('wpcc-w-model-select');
		var mdlTxt  = document.getElementById('wpcc-w-model');
		var mdlSrch = document.getElementById('wpcc-w-model-search');
		var mdlHelp = document.getElementById('wpcc-w-model-help');
		var mFlag   = wiz.querySelector('input[name="wpcc_model"]');
		if (!provSel || !mdlSel || !mdlTxt || !mFlag) return;
		var CUSTOM = '__custom__';
		var CUSTOM_LABEL = <?php echo wp_json_encode( __( 'Custom model ID…', 'ai-command-center' ) ); ?>;
		var FREE_HELP = <?php echo wp_json_encode( __( 'Enter the model id your endpoint serves (free text).', 'ai-command-center' ) ); ?>;
		var DISC_HELP = <?php echo wp_json_encode( __( 'Discovering models…', 'ai-command-center' ) ); ?>;
		var THRESHOLD = <?php echo (int) ProviderCatalog::SEARCH_THRESHOLD; ?>;

		function syncModel() {
			if (mdlSel.style.display === 'none') { mFlag.value = 'custom'; return; }
			if (mdlSel.value === CUSTOM) { mFlag.value = 'custom'; mdlTxt.style.display = ''; mdlTxt.focus(); }
			else { mFlag.value = mdlSel.value; mdlTxt.style.display = 'none'; mdlTxt.value = ''; }
		}
		function filterModels() {
			var q = (mdlSrch.value || '').toLowerCase();
			Array.prototype.forEach.call(mdlSel.options, function (o) {
				if (o.value === CUSTOM) { return; } // custom stays reachable
				o.hidden = q !== '' && o.textContent.toLowerCase().indexOf(q) === -1 && o.value.toLowerCase().indexOf(q) === -1;
			});
		}
		// Render the model control from a {id:label} map (curated, or future discovered).
		function populate(meta, models) {
			var ids = models ? Object.keys(models) : [];
			if (ids.length) {
				mdlSel.innerHTML = '';
				ids.forEach(function (id) { var o = document.createElement('option'); o.value = id; o.textContent = models[id]; mdlSel.appendChild(o); });
				if (meta.supports_custom_model !== false) { var c = document.createElement('option'); c.value = CUSTOM; c.textContent = CUSTOM_LABEL; mdlSel.appendChild(c); }
				mdlSel.value = (meta.default_model && models[meta.default_model]) ? meta.default_model : ids[0];
				mdlSel.style.display = ''; mdlTxt.style.display = 'none'; mdlTxt.value = '';
				if (mdlSrch) { mdlSrch.style.display = (ids.length > THRESHOLD || meta.supports_search) ? '' : 'none'; mdlSrch.value = ''; filterModels(); }
				if (mdlHelp) { mdlHelp.textContent = ''; }
				syncModel();
			} else {
				// No list, no discovery → free text (local / gateway / custom). Never an empty dropdown.
				mdlSel.style.display = 'none';
				if (mdlSrch) { mdlSrch.style.display = 'none'; }
				mdlTxt.style.display = ''; mFlag.value = 'custom';
				if (mdlHelp) { mdlHelp.textContent = FREE_HELP; }
			}
		}
		function renderModels(meta) {
			var curated = (meta.recommended_models && typeof meta.recommended_models === 'object') ? meta.recommended_models : {};
			// Discovery seam: used only when the provider advertises discovery AND a
			// discovery transport is registered. None exists today, so this always
			// falls back to the curated list — no fabricated models.
			if (meta.supports_discovery && typeof window.wpccDiscoverModels === 'function') {
				if (mdlHelp) { mdlHelp.textContent = DISC_HELP; }
				try {
					window.wpccDiscoverModels(meta, function (discovered) {
						populate(meta, (discovered && Object.keys(discovered).length) ? discovered : curated);
					});
					return;
				} catch (e) { /* fall through to curated */ }
			}
			populate(meta, curated);
		}
		function applyProvider() {
			var meta = WPCC_PMETA[provSel.value] || {};
			if (epField) {
				epField.style.display = meta.requires_endpoint ? '' : 'none';
				if (epInput) { epInput.placeholder = meta.default_endpoint || 'https://…'; if (!meta.requires_endpoint) { epInput.value = ''; } }
			}
			if (depField) { depField.style.display = meta.needs_deployment ? '' : 'none'; }
			renderModels(meta);
		}
		provSel.addEventListener('change', applyProvider);
		mdlSel.addEventListener('change', syncModel);
		if (mdlSrch) { mdlSrch.addEventListener('input', filterModels); }
		applyProvider();
	})();
	var steps = wiz.querySelectorAll('.wpcc-aip-step');
	var bars  = wiz.querySelectorAll('.wpcc-aip-steps .s');
	var back  = document.getElementById('wpcc-w-back');
	var next  = document.getElementById('wpcc-w-next');
	var fin   = document.getElementById('wpcc-w-finish');
	var i = 0;
	function show(n){
		i = Math.max(0, Math.min(steps.length-1, n));
		steps.forEach(function(s,x){ s.classList.toggle('active', x===i); });
		bars.forEach(function(b,x){ b.classList.toggle('active', x<=i); });
		back.style.visibility = i===0 ? 'hidden' : 'visible';
		next.style.display = i===steps.length-1 ? 'none' : '';
		fin.style.display  = i===steps.length-1 ? '' : 'none';
		var h = steps[i].querySelector('h3'); if (h){ h.setAttribute('tabindex','-1'); h.focus(); }
	}
	function open(){ wiz.classList.add('open'); show(0); var t=document.getElementById('wpcc-aip-new'); if(t) t.setAttribute('aria-expanded','true'); wiz.scrollIntoView({behavior:'smooth',block:'nearest'}); }
	function close(){ wiz.classList.remove('open'); var t=document.getElementById('wpcc-aip-new'); if(t){ t.setAttribute('aria-expanded','false'); t.focus(); } }
	['wpcc-aip-new','wpcc-aip-new2'].forEach(function(id){ var b=document.getElementById(id); if(b) b.addEventListener('click', open); });
	next.addEventListener('click', function(){ show(i+1); });
	back.addEventListener('click', function(){ show(i-1); });
	document.getElementById('wpcc-w-cancel').addEventListener('click', close);
	// Edit-card model selectors: reveal the custom text input only when "Custom" is chosen.
	Array.prototype.forEach.call(document.querySelectorAll('.wpcc-edit-model'), function (sel) {
		var form = sel.closest ? sel.closest('form') : null;
		var txt = form ? form.querySelector('.wpcc-edit-model-custom') : null;
		if (!txt) return;
		function toggle(){ txt.style.display = (sel.value === 'custom') ? '' : 'none'; }
		sel.addEventListener('change', toggle); toggle();
	});

	// Progressive enhancement: JS active → start collapsed as a wizard.
	wiz.classList.remove('open');
})();

/*
 * Destructive connection actions — the product's own confirmation, not the browser's.
 *
 * Deleting a connection used to go through window.confirm(). A native dialog renders as
 * "localhost says:", ignores the design system, and — the part that actually matters
 * here — can only carry one line of text. So the question a customer was asked before
 * removing a stored provider key was "Delete this connection and its key?", which names
 * neither the connection nor what stops working. WPCC.cds.confirm is the dialog the rest
 * of the product already uses for destructive decisions (token revoke, undo, bulk
 * approve/reject); it can say which connection, that the key is destroyed, which tools
 * were routed through it, and — the reassuring half — what is NOT destroyed.
 *
 * No-JS keeps working: without JS the submit is never intercepted and the form posts
 * straight through to the same nonce-checked, capability-checked handler.
 */
( function () {
	var buttons = document.querySelectorAll( '.wpcc-conn-confirm' );
	if ( ! buttons.length || ! window.WPCC || ! WPCC.cds || ! WPCC.cds.confirm ) { return; }

	var i18n = <?php echo wp_json_encode( [
		'deleteTitle'  => __( 'Delete this connection?', 'ai-command-center' ),
		/* translators: %s: the connection name. */
		'deleteBody'   => __( '“%s” will be removed and its stored API key deleted. This cannot be undone — you would need to paste the key again to restore it.', 'ai-command-center' ),
		/* translators: %s: comma-separated list of tool names. */
		'deleteRouted' => __( 'These tools generate through it and will stop until you point them at another connection: %s.', 'ai-command-center' ),
		'deleteKeeps'  => __( 'Your existing suggestions, applied changes and audit history are not affected — nothing already recorded is erased.', 'ai-command-center' ),
		'deleteGo'     => __( 'Delete connection', 'ai-command-center' ),
		'keyTitle'     => __( 'Remove this key?', 'ai-command-center' ),
		/* translators: %s: the connection name. */
		'keyBody'      => __( 'The stored API key for “%s” will be deleted. The connection stays, but it cannot generate anything until you add a key again.', 'ai-command-center' ),
		'keyGo'        => __( 'Remove key', 'ai-command-center' ),
		'cancel'       => __( 'Cancel', 'ai-command-center' ),
	] ); ?>;

	Array.prototype.forEach.call( buttons, function ( btn ) {
		btn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			// A double-click must not open two dialogs, nor queue two deletes. Once a
			// decision is in flight or already taken, every further click is ignored.
			if ( btn.dataset.wpccPending === '1' || btn.dataset.wpccDone === '1' ) { return; }
			btn.dataset.wpccPending = '1';

			var isDelete = ( btn.dataset.confirm === 'delete' );
			var name = btn.dataset.connName || '';
			var body;
			if ( isDelete ) {
				body = i18n.deleteBody.replace( '%s', name );
				if ( btn.dataset.routed ) { body += ' ' + i18n.deleteRouted.replace( '%s', btn.dataset.routed ); }
				body += ' ' + i18n.deleteKeeps;
			} else {
				body = i18n.keyBody.replace( '%s', name );
			}

			WPCC.cds.confirm( {
				title: isDelete ? i18n.deleteTitle : i18n.keyTitle,
				body: body,
				confirmLabel: isDelete ? i18n.deleteGo : i18n.keyGo,
				cancelLabel: i18n.cancel,
				danger: true
			} ).then( function ( ok ) {
				btn.dataset.wpccPending = '';
				if ( ! ok ) { return; } // cancelled: the connection is untouched and still listed.
				var form = btn.closest ? btn.closest( 'form' ) : null;
				if ( ! form ) { return; }
				btn.dataset.wpccDone = '1';
				/*
				 * Carry the action in a hidden field rather than re-clicking the button.
				 * A submit button contributes its name/value only when it is the one that
				 * submitted the form, and a DISABLED button contributes nothing at all —
				 * so "disable to prevent a double submit" and "this button's value is the
				 * action" are in direct conflict. A hidden input settles it: the value is
				 * posted regardless, and the button can be disabled immediately.
				 */
				var hidden = document.createElement( 'input' );
				hidden.type = 'hidden';
				hidden.name = btn.name;
				hidden.value = btn.value;
				form.appendChild( hidden );
				btn.disabled = true;
				form.submit();
			} );
		} );
	} );
} )();

/*
 * Taking the customer to the thing that was just talked about.
 *
 * Two problems, one mechanism. After any action on this screen the browser
 * reloads at the TOP: the outcome card or notice says what happened, and the
 * connection it happened to is somewhere below the tool switches and the
 * activity feed. Pressing "Test" on a card therefore scrolled you away from that
 * card to read a sentence about it — and then left you to scroll back and find
 * which of several cards you had acted on. The same gap is why "Connection
 * created." used to feel like nothing had happened at all.
 *
 * So: every in-page jump on this screen (and every completed action) lands on the
 * relevant card, marks it briefly, and — when the point of the trip was the API
 * key — opens the right disclosure and puts the cursor in the field. Scrolling
 * only happens when the target is actually off screen, because moving the page
 * under someone who can already see the answer is its own annoyance.
 *
 * All progressive enhancement: these are real `#id` anchors, so with JS off the
 * browser still jumps to the same place.
 */
( function () {
	function reduced() {
		return window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	}

	function spotlight( el ) {
		el.classList.remove( 'wpcc-aip-spotlight' );
		void el.offsetWidth; // restart the animation on a repeat visit
		el.classList.add( 'wpcc-aip-spotlight' );
	}

	function onScreen( el ) {
		var r = el.getBoundingClientRect();
		return r.top >= 0 && r.bottom <= ( window.innerHeight || document.documentElement.clientHeight );
	}

	// Open this connection's key field: the Edit disclosure holds it, and a
	// disclosure that is closed cannot be focused into.
	function openKey( card ) {
		var input = card.querySelector( 'input[name="wpcc_key"]' );
		if ( ! input ) { return false; }
		var d = input.closest ? input.closest( 'details' ) : null;
		if ( d ) { d.open = true; }
		input.focus( { preventScroll: true } );
		return true;
	}

	function reveal( card, wantKey ) {
		if ( wantKey ) { openKey( card ); }
		if ( ! onScreen( card ) ) {
			var soft = ! reduced();
			card.scrollIntoView( { behavior: soft ? 'smooth' : 'auto', block: 'center' } );
			/*
			 * Smooth scrolling is an animation and animations do not always run — a
			 * backgrounded tab suspends them, and the page then never moves at all.
			 * Silently leaving someone where they were, with a highlight on a card
			 * two screens down, is the exact confusion this whole mechanism exists to
			 * remove. Verify the arrival; jump if it did not happen.
			 */
			if ( soft ) {
				setTimeout( function () {
					var r  = card.getBoundingClientRect();
					var vh = window.innerHeight || document.documentElement.clientHeight;
					if ( r.top > vh || r.bottom < 0 ) { card.scrollIntoView( { behavior: 'auto', block: 'center' } ); }
				}, 700 );
			}
		}
		spotlight( card );
	}

	Array.prototype.forEach.call( document.querySelectorAll( '.wpcc-aip-jump' ), function ( link ) {
		link.addEventListener( 'click', function ( e ) {
			var id = ( link.getAttribute( 'href' ) || '' ).replace( /^#/, '' );
			var target = id ? document.getElementById( id ) : null;
			if ( ! target ) { return; } // let the plain anchor try
			e.preventDefault();
			reveal( target, link.dataset.openKey === '1' );
		} );
	} );

	/*
	 * The connection the action that produced this page load applied to. Empty on
	 * a plain visit, so a customer who just came to look is never scrolled.
	 */
	/*
	 * Arriving from ANOTHER screen with a #target.
	 *
	 * Home's "Turn on a tool" points at `#wpcc-bai-tools-h` — the tool switches,
	 * which sit below a hero, a two-path explainer and the provider cards. The
	 * browser jumps there on its own and says nothing about why the page is
	 * suddenly halfway down itself; a customer who followed a specific promise
	 * arrives with no confirmation they landed on the thing they were promised.
	 *
	 * So the destination is marked exactly as an in-page jump marks it, and takes
	 * keyboard focus so the arrival is announced rather than merely rendered. No
	 * scrolling of our own — the browser has already done it, and doing it twice
	 * is how a page ends up lurching.
	 */
	if ( window.location.hash.length > 1 ) {
		var hashed = document.getElementById( window.location.hash.slice( 1 ) );
		if ( hashed ) {
			// A heading is not focusable by default; -1 makes it programmatically
			// focusable without adding it to the tab order.
			if ( ! hashed.hasAttribute( 'tabindex' ) ) { hashed.setAttribute( 'tabindex', '-1' ); }
			hashed.focus( { preventScroll: true } );
			/*
			 * Focus belongs on the thing that was named; the RING belongs on the
			 * panel around it. `#wpcc-bai-tools-h` is a heading, and a box-shadow
			 * drawn on a heading is a rectangle hugging a line of text, which
			 * reads as a rendering fault rather than as "here". Marking its card
			 * shows the customer the whole thing they came for.
			 */
			spotlight( hashed.closest( '.wpcc-cds-card, .wpcc-aip-card' ) || hashed );
		}
	}

	var acted = <?php echo wp_json_encode( (string) ( $wpcc_notice['connection'] ?? '' ) ); ?>;
	if ( acted ) {
		var card = document.getElementById( 'wpcc-conn-' + acted );
		// Not scrolled for a brand-new connection: the outcome card at the top is
		// carrying the next step, and yanking the page away from it would hide the
		// one control the customer is meant to press.
		if ( card && ! document.querySelector( '.wpcc-aip-outcome' ) ) {
			reveal( card, false );
		}
	}
} )();
</script>
