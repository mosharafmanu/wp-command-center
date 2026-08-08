<?php
/**
 * Settings › Built-in AI (hub).
 *
 * V1 refinement: Built-in AI is no longer a primary section. It is optional —
 * its generation tools are build-flagged OFF by default — while the core V1
 * journey (connect an MCP assistant) needs no provider key at all. Promoting it
 * to the top level made users believe WP Command Center could not talk to Claude
 * or Cursor until they had pasted an API key into WordPress. It could.
 *
 * This hub hosts the EXISTING views unchanged (Providers · SEO · Alt Text ·
 * Content) behind a `?aipane=` sub-nav, following the same pattern as the
 * Diagnostics and Advanced hubs. It adds no data, route, capability, or schema.
 * Pane visibility comes from AppShell::builtin_tabs(), so the build-flag +
 * FeatureGate rules stay in exactly one place.
 */

defined( 'ABSPATH' ) || exit;

use WPCommandCenter\Admin\AppShell;

/*
 * Handle the tool toggle BEFORE the pane list is built.
 *
 * The toggle used to be processed inside partials/builtin-ai-tools.php, which is
 * included further down — so builtin_tabs() had already run against the old option.
 * Turning Content on answered "Content is on" while no Content tab appeared, and the
 * tab only showed up on the next page load. Being told a feature is on and not being
 * shown where to use it is a poor moment to hand someone who has just switched on
 * their first one.
 */
$wpcc_bai_handled = \WPCommandCenter\Admin\BuiltinAiSettings::handle_post();

$wpcc_ai_panes = AppShell::builtin_tabs();

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pane selection, no state change.
$wpcc_ai_active = isset( $_GET['aipane'] ) ? sanitize_key( wp_unslash( $_GET['aipane'] ) ) : '';
if ( ! isset( $wpcc_ai_panes[ $wpcc_ai_active ] ) ) {
	$wpcc_ai_active = (string) array_key_first( $wpcc_ai_panes );
}

// True on a stock install: only "Providers" exists because no generation tool is
// switched on for this site.
$wpcc_ai_tools_off = ( 1 === count( $wpcc_ai_panes ) );
?>
<div class="wpcc-settings-ai">
	<h1><?php esc_html_e( 'Built-in AI', 'ai-command-center' ); ?></h1>
	<p class="description" style="max-width:680px;font-size:14px;">
		<?php
		// The second sentence is a consent claim, not decoration: it is the same
		// promise the readme makes to WordPress.org reviewers, so it has to be on
		// the screen where a key is entered. It previously lived in the provider
		// hero that this refinement removed.
		esc_html_e( 'Optional. Add your own AI provider key here if you want WP Command Center itself to generate content for you. AI stays off until you turn a feature on — adding a key alone changes nothing. You do not need this to connect Claude, Cursor, or any other AI assistant; that works without a key.', 'ai-command-center' );
		?>
	</p>

	<?php
	/*
	 * The two-paths block.
	 *
	 * Everything on this screen assumed the reader already knew that "AI assistant" and
	 * "AI provider" are different things. They are the most confusable pair of words in
	 * the product: both contain "AI", both involve a key-like value, and only one of
	 * them is needed to use WP Command Center at all. Someone who conflates them
	 * concludes they must buy an API key before they can connect Claude — which is the
	 * exact opposite of true, and an expensive misunderstanding to leave in place.
	 *
	 * Stating both paths side by side answers "do I need this?" before any of the
	 * provider machinery below is reached. It is deliberately two short columns rather
	 * than a diagram: the relationship is genuinely simple once named.
	 */
	?>
	<div class="wpcc-ai-paths">
		<div class="wpcc-ai-path">
			<span class="wpcc-ai-path__tag"><?php esc_html_e( 'Path 1 — the main way', 'ai-command-center' ); ?></span>
			<strong><?php esc_html_e( 'Your own AI assistant', 'ai-command-center' ); ?></strong>
			<p><?php
				printf(
					/* translators: %s: the mode-aware guarantee sentence. */
					esc_html__( 'You work in Claude, Cursor or ChatGPT and ask it to change this site. It brings its own AI, so no key is needed here. %s', 'ai-command-center' ),
					esc_html( \WPCommandCenter\Operations\SecurityModeManager::promise() )
				);
			?></p>
			<span class="wpcc-ai-path__note"><?php esc_html_e( 'No provider key required', 'ai-command-center' ); ?></span>
		</div>
		<div class="wpcc-ai-path">
			<span class="wpcc-ai-path__tag"><?php esc_html_e( 'Path 2 — optional extra', 'ai-command-center' ); ?></span>
			<strong><?php esc_html_e( 'Built-in AI (this screen)', 'ai-command-center' ); ?></strong>
			<p><?php esc_html_e( 'The plugin generates text itself — SEO descriptions, image alt text, draft content — without you opening an assistant. This is the part that needs your own provider key, and only for the tools you switch on.', 'ai-command-center' ); ?></p>
			<span class="wpcc-ai-path__note"><?php esc_html_e( 'Needs a provider key', 'ai-command-center' ); ?></span>
		</div>
	</div>
	<p class="description" style="max-width:680px;margin:0 0 18px;">
		<?php
		/*
		 * This was mode-aware but only two-way, so Standard inherited Strict's
		 * wording — "anything that changes your site still needs your approval",
		 * which is not true of the low-risk tier. The point of the sentence is that
		 * BOTH paths obey the same rules, so it should state the rules once, from
		 * the one place that knows them.
		 */
		printf(
			/* translators: %s: the mode-aware guarantee sentence. */
			esc_html__( 'Both paths obey the same rules. %s', 'ai-command-center' ),
			esc_html( \WPCommandCenter\Operations\SecurityModeManager::promise() )
		);
		?>
	</p>

	<?php if ( $wpcc_ai_tools_off ) : ?>
		<p class="wpcc-builtin-note" role="note" style="margin:14px 0;padding:10px 14px;background:#f0f6fc;border-left:3px solid #2271b1;border-radius:0 4px 4px 0;max-width:680px;font-size:13px;color:#1d2327;">
			<?php esc_html_e( 'The SEO, Alt Text, and Content tools are not switched on for this site. They appear here once enabled — adding a provider key on its own does not turn them on.', 'ai-command-center' ); ?>
		</p>
	<?php else : ?>
		<nav class="wpcc-cds-subnav" aria-label="<?php esc_attr_e( 'Built-in AI sections', 'ai-command-center' ); ?>">
			<?php foreach ( $wpcc_ai_panes as $wpcc_ai_key => $wpcc_ai_pane ) : ?>
				<a class="wpcc-cds-subnav__item<?php echo $wpcc_ai_key === $wpcc_ai_active ? ' is-active' : ''; ?>"
					href="<?php echo esc_url( admin_url( 'admin.php?page=wpcc-settings&wpcc_tab=advanced&apane=ai&aipane=' . $wpcc_ai_key ) ); ?>"
					<?php echo $wpcc_ai_key === $wpcc_ai_active ? 'aria-current="page"' : ''; ?>>
					<?php echo esc_html( $wpcc_ai_pane['label'] ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
	<?php endif; ?>

	<div class="wpcc-subnav__canvas">
		<?php
		if ( isset( $wpcc_ai_panes[ $wpcc_ai_active ] ) ) {
			$wpcc_ai_path = WPCC_PLUGIN_DIR . 'includes/Admin/views/' . $wpcc_ai_panes[ $wpcc_ai_active ]['view'] . '.php';
			if ( is_readable( $wpcc_ai_path ) ) {
				require $wpcc_ai_path;
			}
		}
		?>
	</div>
</div>

<style>
/* Two-paths explainer (Built-in AI). Presentation only — scoped to this view. */
.wpcc-ai-paths { display: grid; grid-template-columns: repeat(auto-fit, minmax(290px, 1fr)); gap: 14px; max-width: 760px; margin: 18px 0 12px; }
.wpcc-ai-path { padding: 14px 16px; background: #fff; border: 1px solid #e3e5ec; border-radius: 10px; box-shadow: 0 1px 2px rgba(16,24,40,.03); }
.wpcc-ai-path__tag { display: block; font-size: 10.5px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: #8c92a0; margin-bottom: 5px; }
.wpcc-ai-path strong { display: block; font-size: 14px; color: #1d2327; margin-bottom: 5px; }
.wpcc-ai-path p { margin: 0 0 9px; font-size: 13px; line-height: 1.55; color: #50575e; }
.wpcc-ai-path__note { display: inline-block; font-size: 11px; font-weight: 600; padding: 1px 8px; border-radius: 999px; background: #f6f7f7; border: 1px solid #dcdfe6; color: #50575e; }
.wpcc-ai-path:first-child .wpcc-ai-path__note { background: #e6f6ea; border-color: #aadfb6; color: #04620f; }
</style>
