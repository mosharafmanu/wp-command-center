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
