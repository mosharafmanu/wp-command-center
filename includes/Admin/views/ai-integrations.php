<?php
/**
 * Step 48 — AI Integrations UX (Client Integration Layer).
 *
 * Central location for all AI client setup. Tabbed interface:
 * Clients, Configuration, Activity, Security.
 */
defined( 'ABSPATH' ) || exit;

use WPCommandCenter\Integration\AIClientRegistry;
use WPCommandCenter\Integration\ClaudeIntegration;
use WPCommandCenter\Security\AuthTokens;
use WPCommandCenter\Operations\OperationRegistry;

$wpcc_tokens      = new AuthTokens();
$wpcc_all_tokens   = $wpcc_tokens->list();
$wpcc_clients      = AIClientRegistry::get_clients();
$wpcc_active_clients = AIClientRegistry::get_active_clients();
$wpcc_counts       = AIClientRegistry::get_counts();
$wpcc_matrix       = AIClientRegistry::get_compatibility_matrix();
$wpcc_ops          = ( new OperationRegistry() )->get_operations();
$wpcc_tool_count   = count( $wpcc_ops );

// Selected client for config tab
$wpcc_selected_client = sanitize_key( (string) ( $_GET['client'] ?? 'claude' ) );
$wpcc_current_client  = AIClientRegistry::get_client( $wpcc_selected_client );
if ( ! $wpcc_current_client || \WPCommandCenter\Integration\AIClientRegistry::CERT_PLANNED === ( $wpcc_current_client['certification_level'] ?? '' ) ) {
	$wpcc_selected_client = 'claude';
	$wpcc_current_client  = AIClientRegistry::get_client( 'claude' );
}

$wpcc_config      = AIClientRegistry::generate_config( $wpcc_selected_client );
$wpcc_config_json = $wpcc_config ? AIClientRegistry::render_config( $wpcc_config ) : '';

/*
 * Does the selected assistant connect directly over HTTP, or through the connector?
 *
 * Three places on this screen need the answer — the summary row, the "what this does"
 * explanation, and which checks the connection test runs — and each had been asking
 * separately. One lookup, resolved before anything renders, so the page cannot end up
 * describing one transport while testing the other.
 */
$wpcc_sel_http = 'http' === AIClientRegistry::transport_for( $wpcc_selected_client );

// All AI client activity from audit log
$wpcc_audit         = new \WPCommandCenter\Security\AuditLog();
$wpcc_entries       = $wpcc_audit->tail( 200 );
$wpcc_ai_activity   = [];
foreach ( $wpcc_entries as $entry ) {
	if ( str_starts_with( $entry['action'], 'claude.' ) || str_starts_with( $entry['action'], 'ai_client.' ) || str_starts_with( $entry['action'], 'mcp.' ) ) {
		$wpcc_ai_activity[] = $entry;
	}
	if ( count( $wpcc_ai_activity ) >= 15 ) {
		break;
	}
}

// Handle token generation
$wpcc_new_token     = null;
$wpcc_token_message  = '';
$wpcc_token_error    = '';

if ( isset( $_POST['wpcc_token_action'] ) && check_admin_referer( 'wpcc_ai_integrations' ) && current_user_can( 'manage_options' ) ) {
	$wpcc_token_action = sanitize_key( $_POST['wpcc_token_action'] );

	if ( 'generate_read_only' === $wpcc_token_action || 'generate_full' === $wpcc_token_action ) {
		$scope = 'generate_read_only' === $wpcc_token_action ? AuthTokens::SCOPE_READ_ONLY : AuthTokens::SCOPE_FULL;
		$label = sprintf(
			'%s %s',
			'generate_read_only' === $wpcc_token_action ? __( 'AI Read-only', 'ai-command-center' ) : __( 'AI Full Access', 'ai-command-center' ),
			gmdate( 'Y-m-d H:i' )
		);
		$result = $wpcc_tokens->create( $label, $scope, null, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			$wpcc_token_error = $result->get_error_message();
		} else {
			$wpcc_new_token    = $result['token'];
			$wpcc_token_message = __( 'Token generated. Copy it now — it will not be shown again.', 'ai-command-center' );

			// Inject token into config
			if ( $wpcc_config ) {
				// Substituted on the rendered text, so it works whether this client takes
				// JSON, TOML or a shell command — the old fixed array path only ever
				// reached clients shaped like Claude Desktop.
				$wpcc_config_json = AIClientRegistry::render_config( $wpcc_config, $wpcc_new_token );
			}
			$wpcc_all_tokens = $wpcc_tokens->list();
		}
	} elseif ( 'revoke' === $wpcc_token_action ) {
		// Disconnecting belongs in the same place as connecting. The screen already
		// promised "you can revoke it anytime" while sending the user elsewhere to
		// do it — the control now lives next to the promise. AuthTokens::revoke()
		// also deprovisions the token's capabilities, so this is a real disconnect.
		$wpcc_revoke_id = sanitize_text_field( wp_unslash( (string) ( $_POST['wpcc_token_id'] ?? '' ) ) );
		$wpcc_revoked   = '' !== $wpcc_revoke_id ? $wpcc_tokens->revoke( $wpcc_revoke_id ) : false;
		if ( is_wp_error( $wpcc_revoked ) ) {
			$wpcc_token_error = $wpcc_revoked->get_error_message();
		} elseif ( true === $wpcc_revoked ) {
			$wpcc_token_message = __( 'Token revoked. Any assistant using it is disconnected immediately.', 'ai-command-center' );
		} else {
			$wpcc_token_error = __( 'That token could not be found.', 'ai-command-center' );
		}
		$wpcc_all_tokens = $wpcc_tokens->list();
	}
}

// Config with a selected existing token.
//
// Tokens are stored as salted hashes and the raw value is shown only once, at
// creation (see AuthTokens). An existing token's secret therefore cannot be
// re-injected into the config here. Rather than silently leaving the placeholder
// (which makes "Use in config" look like it did nothing), we resolve the selected
// token's metadata so the Configuration tab can show a clear note telling the user
// exactly which saved token to paste in place of the WPCC_TOKEN placeholder.
$wpcc_selected_token_id = sanitize_text_field( (string) ( $_GET['token_id'] ?? '' ) );
$wpcc_selected_token    = null;
if ( $wpcc_selected_token_id ) {
	$selected            = array_filter( $wpcc_all_tokens, fn( $t ) => $t['id'] === $wpcc_selected_token_id );
	$wpcc_selected_token = ! empty( $selected ) ? reset( $selected ) : null;
}
// Nothing to substitute: the generator's own placeholder is already what the note
// below, the token-fill field and the copy button all refer to. This branch used to
// re-render with a SECOND placeholder string, which is what broke the token-fill
// field — it left the two halves of the screen looking for different text.

// Active tab
$wpcc_tab = sanitize_key( (string) ( $_GET['tab'] ?? 'clients' ) );
// Labels only — the query keys are unchanged, so every existing deep link
// still resolves. "Clients", "Activity" and "Security" each duplicated a name
// used elsewhere in the product at a different level, which made the tab bar
// impossible to place. These read as the steps they actually are.
$wpcc_tabs = [
	'configuration' => __( 'Set up', 'ai-command-center' ),
	'activity'      => __( 'Recent requests', 'ai-command-center' ),
	'security'      => __( 'How access works', 'ai-command-center' ),
];
// Arriving here from the Home wizard's single "Connect" button already costs the
// customer three stacked tab rows. Before a token exists there is nothing to see
// in "Recent requests" (no assistant has called) and "How access works" describes
// a connection they do not have yet — so both are noise on the one screen that
// has to succeed. They come back the moment setup is real. Same gating precedent
// as AppShell's $has_started power chrome; no route or capability changes, and a
// deep link to either tab still resolves because the fallback below is unchanged.
if ( ! \WPCommandCenter\Admin\ConnectionStatus::ever_connected()
	&& [] === ( new \WPCommandCenter\Security\AuthTokens() )->list() ) {
	unset( $wpcc_tabs['activity'], $wpcc_tabs['security'] );
}
// The retired "Choose" tab now resolves to the flow it used to describe, so old
// links and the ?tab=clients default both land on the real thing.
if ( ! isset( $wpcc_tabs[ $wpcc_tab ] ) ) {
	$wpcc_tab = 'configuration';
}
?>
<style>
	/* AI Clients — scoped polish. Premium, light, wp-admin compatible. Reuses CDS
	   chip classes (wpcc-cds-chip--*) loaded site-wide; everything else is scoped. */
	.wpcc-ai-wrap { max-width: 1020px; }
	.wpcc-ai-wrap h1 { margin-bottom: 4px; }
	.wpcc-ai-wrap a:focus-visible,
	.wpcc-ai-wrap button:focus-visible,
	.wpcc-ai-tab:focus-visible { outline: 2px solid #2271b1; outline-offset: 2px; border-radius: 7px; }

	/* Hero / explainer */
	/* Assistant picker: a selected chip reads as chosen, not as the page's CTA. */
	/* Superseded by the assistant picker block below — the flat button treatment
	   could not express selection, hierarchy or rank. */
	.wpcc-ai-hero { background: #fff; border: 1px solid #e3e5ec; border-radius: 14px; padding: 24px 26px; margin: 14px 0 24px; box-shadow: 0 1px 2px rgba(16,24,40,.04), 0 8px 24px rgba(16,24,40,.05); }
	.wpcc-ai-hero .wpcc-ai-lead { font-size: 16px; line-height: 1.55; color: #1d2327; max-width: 70ch; margin: 0 0 10px; font-weight: 600; }
	.wpcc-ai-hero p { font-size: 14.5px; line-height: 1.6; color: #4b5161; max-width: 72ch; margin: 0; }
	.wpcc-ai-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 18px; align-items: center; }
	.wpcc-ai-chips .lbl { font-size: 12.5px; font-weight: 600; color: #646970; margin-right: 2px; }

	/* Tabs */
	.wpcc-ai-tabs { display: flex; gap: 6px; border-bottom: 1px solid #e3e5ec; margin-bottom: 24px; }
	.wpcc-ai-tab { padding: 10px 16px; border-radius: 8px 8px 0 0; border: 1px solid transparent; border-bottom: none; cursor: pointer; font-size: 13px; font-weight: 600; background: transparent; color: #50575e; text-decoration: none; transition: background .12s ease, color .12s ease; }
	.wpcc-ai-tab:hover { background: #f2f3f7; color: #1d2327; }
	.wpcc-ai-tab--active { background: #fff; border-color: #e3e5ec; box-shadow: 0 -2px 0 #2271b1 inset; color: #1d2327; }

	/* Metric cards */
	.wpcc-ai-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 26px; }
	.wpcc-ai-stat { background: #fff; border: 1px solid #e3e5ec; padding: 18px 20px; border-radius: 12px; box-shadow: 0 1px 2px rgba(16,24,40,.04); }
	.wpcc-ai-stat__value { font-size: 30px; font-weight: 700; color: #1d2327; line-height: 1.1; letter-spacing: -.01em; }
	.wpcc-ai-stat__label { font-size: 11.5px; color: #646970; margin-top: 6px; text-transform: uppercase; letter-spacing: .5px; font-weight: 600; }
	.wpcc-ai-stat--good .wpcc-ai-stat__value { color: #008a25; }

	/* Panels */
	.wpcc-ai-panel { background: #fff; border: 1px solid #e3e5ec; border-radius: 12px; margin-bottom: 22px; box-shadow: 0 1px 2px rgba(16,24,40,.04); overflow: hidden; }
	.wpcc-ai-panel__header { padding: 15px 22px; border-bottom: 1px solid #eef0f4; font-size: 14.5px; font-weight: 600; display: flex; justify-content: space-between; align-items: center; gap: 12px; }
	.wpcc-ai-panel__body { padding: 22px; }
	.wpcc-ai-panel__hint { margin: 14px 0 0; color: #646970; font-size: 12.5px; }

	/* Config + code blocks (functional — Configuration tab) */
	.wpcc-ai-config { background: #1d2327; color: #c3c4c7; padding: 18px; border-radius: 10px; font-family: ui-monospace,SFMono-Regular,Menlo,monospace; font-size: 13px; line-height: 1.6; overflow-x: auto; white-space: pre-wrap; word-break: break-all; max-height: 400px; overflow-y: auto; position: relative; }
	.wpcc-ai-code { background: #f6f7f9; border: 1px solid #e3e5ec; border-radius: 9px; padding: 9px 12px; font-family: ui-monospace,SFMono-Regular,Menlo,monospace; font-size: 13px; word-break: break-all; margin: 8px 0; display: flex; justify-content: space-between; align-items: center; }
	/* High-contrast, readable text selection inside dark + light code/config blocks. */
	.wpcc-ai-config::selection, .wpcc-ai-config *::selection,
	#wpcc-config-block::selection, #wpcc-config-block *::selection,
	.wpcc-ai-code::selection, .wpcc-ai-code *::selection { background: #bcdcff; color: #10233b; text-shadow: none; }
	.wpcc-ai-config::-moz-selection, .wpcc-ai-config *::-moz-selection,
	#wpcc-config-block::-moz-selection, #wpcc-config-block *::-moz-selection,
	.wpcc-ai-code::-moz-selection, .wpcc-ai-code *::-moz-selection { background: #bcdcff; color: #10233b; text-shadow: none; }
	.wpcc-ai-code__text { flex: 1; margin-right: 10px; }

	/* Tables */
	.wpcc-ai-token-table { width: 100%; border-collapse: collapse; }
	.wpcc-ai-token-table th { text-align: left; padding: 10px 12px; border-bottom: 1px solid #e3e5ec; font-weight: 600; font-size: 11.5px; text-transform: uppercase; letter-spacing: .4px; color: #646970; }
	.wpcc-ai-token-table td { padding: 11px 12px; border-bottom: 1px solid #eef0f4; font-size: 13.5px; }
	.wpcc-ai-token-table tbody tr:hover { background: #fafbfc; }
	.wpcc-ai-token-table tbody tr:last-child td { border-bottom: none; }

	.wpcc-ai-verify-result { margin-top: 12px; padding: 14px; border-radius: 10px; display: none; }
	.wpcc-ai-verify-result--success { background: #edfaef; border: 1px solid #00a32a; display: block; }
	.wpcc-ai-verify-result--fail { background: #fcf0f1; border: 1px solid #d63638; display: block; }
	.wpcc-ai-verify-result--loading { background: #f0f6fc; border: 1px solid #2271b1; display: block; }

	.wpcc-ai-security-list { list-style: none; padding: 0; margin: 14px 0 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 12px; }
	.wpcc-ai-security-list li { padding: 13px 16px; background: #f7f8fb; border: 1px solid #eef0f4; border-left: 3px solid #00a32a; border-radius: 0 10px 10px 0; }
	.wpcc-ai-security-list li strong { display: block; margin-bottom: 4px; }
	.wpcc-ai-security-list li span { font-size: 12.5px; color: #646970; }

	.wpcc-badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; }
	.wpcc-badge--good { background: #e7f6ee; color: #008a25; }
	.wpcc-badge--neutral { background: #eef0f4; color: #50575e; }
	.wpcc-badge--critical { background: #fcf0f1; color: #d63638; }
	.wpcc-badge--info { background: #eef4fc; color: #1f5fa8; }
	.wpcc-ai-notice { margin: 0 0 16px 0; }
	.wpcc-ai-copied { color: #00a32a; font-size: 12px; display: inline-block; margin-left: 8px; opacity: 0; transition: opacity .2s; }
	.wpcc-ai-copied--visible { opacity: 1; }

	/* Active client cards */
	/* ── Assistant picker ──────────────────────────────────────────────────────
	 * Presentation only. Every value still comes from AIClientRegistry::ui_badges();
	 * this decides how loudly each one speaks, not what any of them say.
	 * ──────────────────────────────────────────────────────────────────────── */

	/* An even grid rather than flex-wrap. Flex sized each card to its own label, so
	   eleven cards came out eleven different widths with a ragged right edge — the
	   single strongest "unfinished" signal on the screen. Equal columns also mean the
	   badge rows line up across cards, so the page can be scanned down a column. */
	.wpcc-ai-picks { display: grid; grid-template-columns: repeat(auto-fill, minmax(232px, 1fr)); gap: 10px; margin-top: 4px; }

	.wpcc-ai-pick { position: relative; height: auto !important; display: flex !important; flex-direction: column;
		align-items: flex-start !important; gap: 9px; padding: 13px 15px 14px !important; line-height: 1.45 !important;
		border: 1px solid #e3e5ec !important; border-radius: 10px; background: #fff; box-shadow: 0 1px 2px rgba(16,24,40,.03);
		text-decoration: none; transition: border-color .13s ease, box-shadow .13s ease, transform .13s ease, background-color .13s ease; }
	.wpcc-ai-pick__name { font-size: 13.5px; font-weight: 600; color: #1d2327; letter-spacing: -.01em; }

	.wpcc-ai-pick:hover { border-color: #c8ccd4 !important; background: #fff; transform: translateY(-1px);
		box-shadow: 0 1px 2px rgba(16,24,40,.04), 0 6px 16px rgba(16,24,40,.06); }
	/* Keyboard focus must be at least as visible as hover — these are links. */
	.wpcc-ai-pick:focus-visible { outline: 2px solid #2271b1; outline-offset: 2px; }

	/* Selected: a quiet raised panel, not a filled blue button. Weight comes from an
	   inset accent rule, a slightly stronger border and real elevation — the same way
	   an enterprise settings list marks the active row. The old treatment flooded the
	   card with #f0f6fc, which shouted louder than the assistant's own name. */
	.wpcc-ai-pick.is-selected { border-color: #c4c9d2 !important; background: #fff; padding-left: 18px !important;
		box-shadow: 0 0 0 1px #c4c9d2, 0 2px 4px rgba(16,24,40,.05), 0 10px 24px rgba(16,24,40,.08); }
	/* One accent, and it is the only colour on the card. An earlier pass drew this rule
	   in the same blue as the selected border, so a 3px sliver sat against a blue edge
	   and read as nothing at all. The border is neutral now and the rule carries the
	   selection on its own — the way an active row is marked in a settings panel. */
	.wpcc-ai-pick.is-selected::after { content: ""; position: absolute; left: -1px; top: 9px; bottom: 9px;
		width: 3px; border-radius: 3px; background: #2271b1; }
	.wpcc-ai-pick.is-selected .wpcc-ai-pick__name { color: #0f1c2e; font-weight: 650; }
	/* The tick reads as confirmation of the current choice; it replaces the ::before
	   glyph that used to push the label off its own baseline. */
	.wpcc-ai-pick.is-selected .wpcc-ai-pick__name::after { content: "\2713"; margin-left: 7px; color: #2271b1; font-weight: 700; font-size: 12px; }

	/* Badges: three ranks, three deliberately different weights. */
	.wpcc-ai-badges { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
	.wpcc-ai-badge { display: inline-flex; align-items: center; font-size: 10.5px; line-height: 1.6;
		border-radius: 999px; white-space: nowrap; }

	/* PRIMARY — filled, the only badge with real colour weight. */
	.wpcc-ai-badge--primary { padding: 1px 8px; font-weight: 650; letter-spacing: .01em; border: 1px solid transparent; }
	.wpcc-ai-badge--primary.wpcc-ai-badge--rec { background: #eeeafb; color: #4c33a0; border-color: #d5cbf2; }
	.wpcc-ai-badge--primary.wpcc-ai-badge--ok  { background: #e6f6ea; color: #04620f; border-color: #aadfb6; }

	/* SECONDARY — outlined, no fill. Present, clearly subordinate. */
	.wpcc-ai-badge--secondary { padding: 1px 8px; font-weight: 600; background: #fff; border: 1px solid #dcdfe6; color: #50575e; }
	.wpcc-ai-badge--secondary.wpcc-ai-badge--info { border-color: #cbdcef; color: #1d5b96; }
	.wpcc-ai-badge--secondary.wpcc-ai-badge--warn { border-color: #ecd6a6; color: #8a5700; }
	.wpcc-ai-badge--secondary.wpcc-ai-badge--bad  { border-color: #eebfc1; color: #a02226; }

	/* TERTIARY — no pill at all. A dot and muted text, so eleven identical copies of
	   the same status stop competing with the badges that actually differ per card. */
	/* Always starts its own line. Cards carrying two badges kept it inline while cards
	   carrying one wrapped it, so no two cards in a row shared a baseline and the grid
	   looked accidental. A forced break gives every card the same two-line rhythm. */
	.wpcc-ai-badge--tertiary { flex: 0 0 100%; margin-top: 1px; padding: 0; border: 0; background: none; color: #7c8290; font-weight: 500; font-size: 10.5px; }
	.wpcc-ai-badge--tertiary::before { content: ""; width: 4px; height: 4px; border-radius: 50%;
		background: #c3c4c7; margin-right: 5px; flex: 0 0 auto; }

	.wpcc-ai-pick .wpcc-ai-badges { pointer-events: none; }

	/* Transport legend under the picker — defines the two badges once, in place. */
	.wpcc-ai-legend { display: flex; flex-wrap: wrap; gap: 6px 22px; margin: 12px 0 0; padding: 0; list-style: none; }
	.wpcc-ai-legend li { display: flex; align-items: center; gap: 7px; font-size: 12px; color: #646970; }

	/* Selected-assistant summary in the configuration panel. One compact row, so the
	   four facts a user needs before pasting sit together instead of as prose. */
	.wpcc-ai-summary { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 14px;
		margin: 0 0 12px; padding: 10px 13px; background: #fbfbfc; border: 1px solid #e9eaee; border-radius: 8px; }
	.wpcc-ai-summary__item { display: inline-flex; align-items: baseline; gap: 6px; font-size: 12px; color: #50575e; }
	.wpcc-ai-summary__k { font-size: 10.5px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: #8c92a0; }
	.wpcc-ai-summary__v { font-weight: 600; color: #1d2327; }
	.wpcc-ai-summary__v--muted { font-weight: 500; color: #646970; }

	.wpcc-ai-client-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(290px, 1fr)); gap: 16px; }
	.wpcc-ai-client-card { background: #fff; border: 1px solid #e3e5ec; border-radius: 12px; padding: 18px 20px; box-shadow: 0 1px 2px rgba(16,24,40,.04); transition: transform .12s ease, box-shadow .12s ease; }
	.wpcc-ai-client-card:hover { transform: translateY(-2px); box-shadow: 0 1px 2px rgba(16,24,40,.04), 0 10px 26px rgba(16,24,40,.07); }
	.wpcc-ai-client-card--active { border-left: 4px solid #00a32a; }
	.wpcc-ai-client-card--planned { border-left: 4px solid #c3c4c7; opacity: 0.7; }
	.wpcc-ai-client-card h3 { margin: 0 0 4px; font-size: 15.5px; }
	.wpcc-ai-client-card .vendor { font-size: 12.5px; color: #646970; }
	.wpcc-ai-client-card .type { font-size: 11px; color: #646970; margin-top: 6px; }
	.wpcc-ai-client-card .desc { font-size: 13px; color: #3c434a; margin-top: 10px; line-height: 1.5; }
	@media (max-width: 600px) { .wpcc-ai-hero { padding: 20px; } }

	/* MCP setup page */
	.wpcc-ai-setup .wpcc-ai-panel__header { gap:10px; }
	.wpcc-setup-status { font-size:12.5px;font-weight:600;color:#008a25;display:inline-flex;align-items:center;gap:6px;text-transform:none;letter-spacing:0; }
	.wpcc-setup-dot { width:9px;height:9px;border-radius:50%;background:#00a32a;box-shadow:0 0 0 3px #e7f6ee;display:inline-block; }
	.wpcc-ai-field { margin:0 0 20px; }
	.wpcc-ai-field:last-child { margin-bottom:0; }
	.wpcc-ai-field__label { display:block;font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#646970;margin-bottom:6px; }
	.wpcc-ai-field__hint { font-size:12.5px;color:#646970;margin:6px 0 0; }
	.wpcc-ai-field__status { font-size:13.5px;color:#3c434a;margin:0; }
	.wpcc-ai-field__status--ok { color:#1d6b3f; }
	.wpcc-ai-url { display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:#f6f7f9;border:1px solid #e3e5ec;border-radius:9px;padding:8px 10px 8px 14px; }
	.wpcc-ai-url__text { flex:1;min-width:200px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;color:#1d2327;word-break:break-all;background:none; }
	.wpcc-ai-setup__actions { display:flex;gap:10px;flex-wrap:wrap;margin-top:6px; }
	.wpcc-ai-steps { margin:0;padding:0;list-style:none;counter-reset:wpcc-step; }
	.wpcc-ai-steps li { counter-increment:wpcc-step;position:relative;padding:8px 0 8px 38px;font-size:14px;color:#3c434a;border-top:1px solid #f0f1f4; }
	.wpcc-ai-steps li:first-child { border-top:none; }
	.wpcc-ai-steps li::before { content:counter(wpcc-step);position:absolute;left:0;top:7px;width:26px;height:26px;border-radius:50%;background:#eef4fc;color:#1f5fa8;font-weight:700;font-size:13px;display:flex;align-items:center;justify-content:center; }
	.wpcc-ai-presets { display:flex;flex-wrap:wrap;gap:10px; }
	.wpcc-ai-preset { display:inline-flex;align-items:center;gap:12px;justify-content:space-between;min-width:200px;background:#fff;border:1px solid #e3e5ec;border-radius:10px;padding:11px 14px;text-decoration:none;color:#1d2327;font-weight:600;font-size:13.5px;box-shadow:0 1px 2px rgba(16,24,40,.04);transition:border-color .12s ease,box-shadow .12s ease; }
	.wpcc-ai-preset:hover { border-color:#2271b1;box-shadow:0 1px 2px rgba(16,24,40,.04),0 6px 16px rgba(16,24,40,.06); }
	.wpcc-ai-preset__go { font-size:12px;color:#2271b1;font-weight:600;white-space:nowrap; }
	.wpcc-ai-advanced { max-width:1020px;margin:6px 0 20px;border:1px solid #e3e5ec;border-radius:12px;background:#fff;box-shadow:0 1px 2px rgba(16,24,40,.04); }
	.wpcc-ai-advanced > summary { cursor:pointer;padding:14px 20px;font-weight:600;font-size:14px;color:#1d2327;list-style:none;user-select:none; }
	.wpcc-ai-advanced > summary::-webkit-details-marker { display:none; }
	.wpcc-ai-advanced > summary::after { content:"\203A";float:right;color:#646970;font-weight:700;transition:transform .12s ease; }
	.wpcc-ai-advanced[open] > summary::after { transform:rotate(90deg); }
	.wpcc-ai-advanced__body { padding:6px 20px 20px; }
	.wpcc-ai-advanced__body .wpcc-ai-panel,.wpcc-ai-advanced__body .wpcc-ai-grid { margin-bottom:18px; }

	/* Configuration tab — safety note */
	.wpcc-ai-safe-note { display:flex;gap:14px;align-items:flex-start;max-width:1020px;background:#f4f8f4;border:1px solid #cfe6d4;border-left:4px solid #00a32a;border-radius:12px;padding:16px 20px;margin:6px 0 20px; }
	.wpcc-ai-safe-note__icon { font-size:20px;line-height:1.3;flex:0 0 auto; }
	.wpcc-ai-safe-note strong { display:block;margin-bottom:6px;color:#1d2327; }
	.wpcc-ai-safe-note ul { margin:0;padding-left:18px;color:#3c434a;font-size:13px;line-height:1.65; }
	.wpcc-token-reveal { background: #fff; border: 1px solid #cfe4d2; border-left: 3px solid #00a32a; border-radius: 12px; padding: 20px 22px; margin: 0 0 22px; box-shadow: 0 1px 2px rgba(16,24,40,.04); }
	.wpcc-token-reveal__title { margin: 0 0 12px; font-size: 15px; font-weight: 650; color: #1d2327; letter-spacing: -.01em; }
	.wpcc-token-reveal__title::before { content: "\2713"; color: #00a32a; font-weight: 700; margin-right: 8px; }
	.wpcc-token-reveal__code { margin: 0; }
	.wpcc-token-reveal__note { margin: 12px 0 0; font-size: 13px; line-height: 1.6; color: #50575e; max-width: 72ch; }
	.wpcc-ai-config { border-radius:0 0 12px 12px; }
</style>

<div class="wrap wpcc-ai-wrap">
	<?php // The tab that leads here is called "Assistants"; a heading naming the same
	// screen "AI Clients" made the navigation label and the page disagree. ?>
	<h1><?php esc_html_e( 'Assistants', 'ai-command-center' ); ?></h1>

	<?php
	// Two paragraphs said the same thing in sequence: name the assistants, then
	// name them again. One lead sentence, then the chips carry the safety promise
	// visually — the customer came here to connect something, not to read.
	//
	// Shown on the entry tab ONLY. It used to re-render on all four inner tabs, so
	// a customer who had already chosen an assistant and clicked "Set up" was made
	// to scroll past the same pitch again before reaching the configuration they
	// came for. A hero introduces a screen once; after that it is an obstacle.
	// The hero is gone with the "Choose" tab: it pitched the product to someone
	// who had already clicked Connect. The beginner explainer below stays — it
	// answers a real question a first-timer has — but collapsed, on the setup
	// screen, where it is one click away instead of in the way.
	if ( false ) :
	?>
	<section class="wpcc-ai-hero">
		<p class="wpcc-ai-lead"><?php esc_html_e( 'Connect Claude, Cursor, Codex, ChatGPT, Gemini or any other AI assistant to this site. Anything it changes waits for your approval, is recorded, and can be undone.', 'ai-command-center' ); ?></p>
		<div class="wpcc-ai-chips" role="note" aria-label="<?php esc_attr_e( 'How every assistant stays safe', 'ai-command-center' ); ?>">
			<span class="wpcc-cds-chip wpcc-cds-chip--approval"><?php esc_html_e( 'Needs your approval', 'ai-command-center' ); ?></span>
			<span class="wpcc-cds-chip wpcc-cds-chip--audited"><?php esc_html_e( 'Recorded', 'ai-command-center' ); ?></span>
			<span class="wpcc-cds-chip wpcc-cds-chip--reversible"><?php esc_html_e( 'Reversible', 'ai-command-center' ); ?></span>
			<span class="wpcc-cds-chip wpcc-cds-chip--scoped"><?php esc_html_e( 'Limited access', 'ai-command-center' ); ?></span>
		</div>
	</section>

	<?php
	// Closed by default. This opened expanded, so the first thing on the page a
	// customer met was a FAQ — documentation ahead of the action they came for.
	// It stays one click away for anyone who wants it.
	?>
	<?php endif; // hero retired with the Choose tab ?>

	<details class="wpcc-agent-explainer">
		<summary><?php esc_html_e( 'New to AI assistants? Read this first (2 min)', 'ai-command-center' ); ?></summary>
		<div style="margin-top:12px;display:grid;gap:12px;">
			<?php foreach ( \WPCommandCenter\Admin\AgentExplainer::faq() as $wpcc_qa ) : ?>
				<div>
					<strong style="display:block;font-size:13px;color:#1d2327;"><?php echo esc_html( $wpcc_qa['q'] ); ?></strong>
					<span style="display:block;color:#50575e;font-size:13px;margin-top:2px;"><?php echo esc_html( $wpcc_qa['a'] ); ?></span>
				</div>
			<?php endforeach; ?>
			<p style="margin:4px 0 0;padding:10px 12px;background:#fff;border-radius:4px;font-size:12px;color:#2271b1;font-weight:600;text-align:center;"><?php echo esc_html( \WPCommandCenter\Admin\AgentExplainer::flow_line() ); ?></p>
			<p style="margin:0;color:#646970;font-size:12px;">
				<?php
				// This previously told users to add an AI provider key FIRST. That was
				// wrong and it was the single most confusing sentence in the product:
				// connecting an assistant over MCP uses the assistant's own AI and
				// needs no key here. Two steps, not three.
				printf(
					/* translators: 1: Tokens link open, 2: link close */
					esc_html__( 'Setup: 1) create an %1$saccess token%2$s, 2) paste the configuration below into your assistant. You do not need an AI provider key — your assistant brings its own AI.', 'ai-command-center' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=wpcc-settings&wpcc_tab=connections&cpane=tokens' ) ) . '">',
					'</a>'
				);
				?>
			</p>
		</div>
	</details>

	<?php if ( $wpcc_token_error ) : ?>
		<div class="notice inline notice-error wpcc-ai-notice"><p><?php echo esc_html( $wpcc_token_error ); ?></p></div>
	<?php endif; ?>
	<?php
	/*
	 * The token reveal is the emotional peak of setup — the moment the customer
	 * gets the key to their own site. It used to be two stacked banners saying the
	 * same thing three times ("Copy it now", "will not be shown again", "save it
	 * now"), the second of them in warning amber. Repetition reads as nagging, and
	 * amber reads as "something went wrong" at the exact moment something went
	 * right.
	 *
	 * One card. Success, not warning. Said once. And it ends by pointing at the
	 * next step instead of leaving the customer holding a string.
	 */
	?>
	<?php if ( $wpcc_new_token ) : ?>
		<div class="wpcc-token-reveal" role="status">
			<p class="wpcc-token-reveal__title"><?php esc_html_e( 'Your access token is ready', 'ai-command-center' ); ?></p>
			<div class="wpcc-ai-code wpcc-token-reveal__code">
				<code class="wpcc-ai-code__text" id="wpcc-new-token"><?php echo esc_html( $wpcc_new_token ); ?></code>
				<button type="button" class="button button-primary wpcc-copy-btn" data-copy="<?php echo esc_attr( $wpcc_new_token ); ?>"><?php esc_html_e( 'Copy', 'ai-command-center' ); ?></button>
			</div>
			<p class="wpcc-token-reveal__note">
				<?php esc_html_e( 'This is the only time it will be shown, so save it somewhere safe. Your configuration below already includes it — copy that and paste it into your assistant.', 'ai-command-center' ); ?>
			</p>
		</div>
	<?php elseif ( $wpcc_token_message ) : ?>
		<div class="notice inline notice-success wpcc-ai-notice"><p><?php echo esc_html( $wpcc_token_message ); ?></p></div>
	<?php endif; ?>

	<!-- Tab navigation. A one-item tab bar is decoration, not navigation. -->
	<?php if ( count( $wpcc_tabs ) > 1 ) : ?>
	<div class="wpcc-ai-tabs">
		<?php foreach ( $wpcc_tabs as $tab_id => $tab_label ) : ?>
			<a href="<?php echo esc_url( add_query_arg( 'tab', $tab_id, admin_url( 'admin.php?page=wpcc-settings&wpcc_tab=connections&cpane=assistants' ) ) ); ?>"
			   class="wpcc-ai-tab<?php echo $tab_id === $wpcc_tab ? ' wpcc-ai-tab--active' : ''; ?>"<?php echo $tab_id === $wpcc_tab ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $tab_label ); ?></a>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>

	<?php
	/*
	 * The "Choose" tab is gone.
	 *
	 * It re-pitched the product to someone who had already clicked Connect, then
	 * printed a numbered "How to connect — 5 steps" list telling them to go and do
	 * the work on a different tab. Every one of those steps already exists as a
	 * panel on this screen, in order — the assistant picker was literally the first
	 * one. A product that has to write instructions for its own interface has a
	 * flow problem, not a documentation problem.
	 *
	 * Setting up is now ONE screen: pick an assistant → create a token → copy the
	 * configuration → test it. Three screens became one.
	 */
	?>
	<?php if ( 'configuration' === $wpcc_tab ) : ?>
		<!-- ===== CONFIGURATION TAB ===== -->

		<!-- Assistant selector -->
		<div class="wpcc-ai-panel">
			<div class="wpcc-ai-panel__header"><?php esc_html_e( 'Choose your assistant', 'ai-command-center' ); ?></div>
			<div class="wpcc-ai-panel__body">
				<p class="wpcc-ai-field__hint" style="margin-top:0;"><?php esc_html_e( 'Pick the assistant you’re connecting — the configuration below updates to match.', 'ai-command-center' ); ?></p>
				<div class="wpcc-ai-picks">
					<?php foreach ( $wpcc_active_clients as $id => $client ) : ?>
						<a href="<?php echo esc_url( add_query_arg( [ 'tab' => 'configuration', 'client' => $id ], admin_url( 'admin.php?page=wpcc-settings&wpcc_tab=connections&cpane=assistants' ) ) ); ?>"
						   class="button wpcc-ai-pick<?php echo $id === $wpcc_selected_client ? ' is-selected' : ''; ?>"
					   <?php echo $id === $wpcc_selected_client ? 'aria-current="true"' : ''; ?>>
							<span class="wpcc-ai-pick__name"><?php echo esc_html( $client['name'] ); ?></span>
							<?php
							/*
							 * The picker used to show eleven identical-looking names, so a
							 * customer could not tell a widely-used, config-confirmed client
							 * from a niche unverified one, nor which choices need Node.js
							 * installed. Both facts now travel with the name, ranked so the
							 * recommendation leads and the repeated status recedes.
							 */
							$wpcc_pick_badges = AIClientRegistry::ui_badges( $id );
							if ( $wpcc_pick_badges ) :
								?>
								<span class="wpcc-ai-badges">
									<?php foreach ( $wpcc_pick_badges as $wpcc_b ) : ?>
										<span class="wpcc-ai-badge wpcc-ai-badge--<?php echo esc_attr( $wpcc_b['rank'] ); ?> wpcc-ai-badge--<?php echo esc_attr( $wpcc_b['tone'] ); ?>" title="<?php echo esc_attr( $wpcc_b['title'] ); ?>"><?php echo esc_html( $wpcc_b['label'] ); ?></span>
									<?php endforeach; ?>
								</span>
							<?php endif; ?>
						</a>
					<?php endforeach; ?>
				</div>
				<?php
				/*
				 * Transport legend.
				 *
				 * The badges answer "how does this one connect?" — but only for someone who
				 * already knows what the two words mean. On a fresh install the panel that
				 * explains them does not exist yet: it renders only once a token has been
				 * created, so the first-time reader meets "Direct HTTP" and "Relay" with no
				 * definition anywhere on the screen, and the practical question behind them
				 * (do I have to install Node.js?) goes unanswered at the exact moment they
				 * are choosing. Two lines, stated once, below the grid.
				 */
				?>
				<ul class="wpcc-ai-legend">
					<li><span class="wpcc-ai-badge wpcc-ai-badge--secondary wpcc-ai-badge--info"><?php esc_html_e( 'Direct HTTP', 'ai-command-center' ); ?></span> <?php esc_html_e( 'Connects straight to this site. Nothing to install.', 'ai-command-center' ); ?></li>
					<li><span class="wpcc-ai-badge wpcc-ai-badge--secondary"><?php esc_html_e( 'Relay', 'ai-command-center' ); ?></span> <?php esc_html_e( 'Runs a small connector on your computer. Needs Node.js.', 'ai-command-center' ); ?></li>
				</ul>
			</div>
		</div>

		<?php
		$wpcc_cfg_mcp_url   = rest_url( \WPCommandCenter\Mcp\McpServerRuntime::NAMESPACE . '/mcp' );
		$wpcc_cfg_tok_count = is_array( $wpcc_all_tokens ) ? count( $wpcc_all_tokens ) : 0;
		?>

		<?php
		// Token panel FIRST, configuration second.
		//
		// The configuration panel asks the customer to paste an access token into it.
		// It used to sit ABOVE the panel that creates one, so a first-time user met
		// "paste your access token" before anything had told them how to get a token —
		// a dead end on the single most important onboarding screen. The order now
		// matches the actual task: choose an assistant, create a token, paste it into
		// the config, test it.
		?>
		<!-- Access tokens -->
		<div class="wpcc-ai-panel">
			<div class="wpcc-ai-panel__header"><?php esc_html_e( 'Access tokens', 'ai-command-center' ); ?></div>
			<div class="wpcc-ai-panel__body">
				<p class="wpcc-ai-field__hint" style="margin-top:0;"><?php esc_html_e( 'A token is your assistant’s key to this site. A standard token lets your assistant answer questions about the site and propose changes — it can never change anything on its own, because every change waits for your approval first.', 'ai-command-center' ); ?></p>
				<form method="post">
					<?php wp_nonce_field( 'wpcc_ai_integrations' ); ?>
					<?php
					// Read-only used to be the recommended starting point. It is not a
					// good one: the read-only SCOPE allowlist (CapabilityRegistry::
					// READ_ONLY_SCOPE_OPERATIONS) covers six operations, so the ordinary
					// first question — "what plugins are installed?", "what pages do I
					// have?" — is refused. A customer who follows that advice connects
					// successfully and then cannot do anything, which reads as a broken
					// product rather than as a safety boundary.
					//
					// The allowlist is deliberately fail-closed and is NOT changed here.
					// What changes is which token we recommend: on the default Standard
					// protection a full-scope token still cannot alter the site without
					// an explicit human approval, so it is the safe default AND the one
					// that works. Restricted stays available for anyone who wants it.
					?>
					<div style="display: flex; gap: 14px; flex-wrap: wrap; align-items: center;">
						<button type="submit" name="wpcc_token_action" value="generate_full" class="button button-primary">
							<?php esc_html_e( 'Create access token', 'ai-command-center' ); ?>
						</button>
					</div>
				</form>
				<?php
				// This table exists so the customer can pick a token to paste into the
				// configuration above. A revoked or expired token can never do that, so
				// listing them here was 200+ rows of things that cannot be chosen —
				// it turned a two-minute setup screen into twelve screens of scrolling.
				// Revoked tokens remain fully visible in Settings › Connections › Access tokens,
				// screen that exists to audit them.
				// "Usable" must mean the same thing the status badge means. The stored
				// `status` stays 'active' on an expired token — expiry is computed from
				// `expires_at` (see AuthTokens::status_badge) — so filtering on status
				// alone offered expired tokens as "Use in config", where they would
				// silently fail against the assistant. Mirror the canonical rule.
				$wpcc_usable_tokens = array_values( array_filter(
					is_array( $wpcc_all_tokens ) ? $wpcc_all_tokens : [],
					static function ( $t ) {
						if ( ( $t['status'] ?? '' ) !== 'active' ) {
							return false;
						}
						$expires = $t['expires_at'] ?? null;
						return null === $expires || (int) $expires >= time();
					}
				) );
				?>
				<?php if ( ! empty( $wpcc_usable_tokens ) ) : ?>
					<h4 style="margin: 18px 0 10px;"><?php esc_html_e( 'Your tokens', 'ai-command-center' ); ?></h4>
					<table class="wpcc-ai-token-table">
						<thead><tr><th><?php esc_html_e( 'Label', 'ai-command-center' ); ?></th><th><?php esc_html_e( 'Scope', 'ai-command-center' ); ?></th><th><?php esc_html_e( 'Status', 'ai-command-center' ); ?></th><th></th></tr></thead>
						<tbody>
						<?php foreach ( $wpcc_usable_tokens as $t ) : ?>
							<tr>
								<td><?php echo esc_html( $t['label'] ); ?><br><small style="color:#646970"><?php echo esc_html( $t['token_preview'] ); ?>...</small></td>
								<td><?php echo esc_html( AuthTokens::scope_label( $t['scope'] ) ); ?></td>
								<td><?php echo AuthTokens::status_badge( $t ); // phpcs:ignore ?></td>
								<td style="white-space:nowrap;">
								<button type="button" class="button button-small wpcc-select-token-btn" data-token-id="<?php echo esc_attr( $t['id'] ); ?>"><?php esc_html_e( 'Use in config', 'ai-command-center' ); ?></button>
								<?php if ( 'active' === ( $t['status'] ?? '' ) ) : ?>
									<form method="post" style="display:inline;" onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Revoke this token? Any assistant using it loses access immediately.', 'ai-command-center' ) ) ); ?>);">
										<?php wp_nonce_field( 'wpcc_ai_integrations' ); ?>
										<input type="hidden" name="wpcc_token_id" value="<?php echo esc_attr( $t['id'] ); ?>" />
										<button type="submit" name="wpcc_token_action" value="revoke" class="button button-small button-link-delete"><?php esc_html_e( 'Revoke', 'ai-command-center' ); ?></button>
									</form>
								<?php endif; ?>
							</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<p class="wpcc-ai-panel__hint"><?php esc_html_e( 'A token is shown in full only once, when you create it. Manage or revoke tokens anytime in Settings → Connections.', 'ai-command-center' ); ?></p>
				<?php else : ?>
					<p style="color:#646970;margin-top:12px;"><?php esc_html_e( 'No active tokens yet. Create one above to finish your configuration.', 'ai-command-center' ); ?></p>
				<?php endif; ?>
			</div>
		</div>

		<?php
		/*
		 * DELETE: the configuration card does not exist until there is something to
		 * configure with.
		 *
		 * On a brand-new site this card rendered in full — heading, Copy button, a
		 * paste field, and a screen-height block of JSON — and then told the customer
		 * "No access token yet — create one in 'Access tokens' above". The single most
		 * important onboarding screen showed its end state before its first step, and
		 * offered a Copy button that copied a placeholder. Nothing on it could be
		 * acted on, so all of it was load.
		 *
		 * With a token present it is exactly as before. Without one, the screen is now
		 * two things: choose your assistant, create a token.
		 */
		?>
		<!-- Setup card: your configuration. Whole section waits for a token. -->
		<?php if ( $wpcc_cfg_tok_count > 0 ) : ?>
		<?php if ( $wpcc_config ) : ?>
			<div class="wpcc-ai-panel">
				<div class="wpcc-ai-panel__header">
					<?php printf( /* translators: %s: value */ esc_html__( 'Your %s configuration', 'ai-command-center' ), esc_html( $wpcc_current_client['name'] ) ); ?>
					<button type="button" class="button wpcc-copy-btn" data-copy-target="wpcc-config-block">
						<?php esc_html_e( 'Copy configuration', 'ai-command-center' ); ?>
					</button>
					<span class="wpcc-ai-copied" id="wpcc-copy-feedback">&#10003; <?php esc_html_e( 'Copied!', 'ai-command-center' ); ?></span>
				</div>
				<div class="wpcc-ai-panel__body">
					<?php
					/*
					 * State the selected assistant's standing at the point of copying, not
					 * only back up in the picker. This is where someone commits to a client,
					 * and it is the honest place to say whether the pairing has actually been
					 * run end to end — "Awaiting certification" is a real answer, and a more
					 * useful one than silence.
					 */
					$wpcc_sel_badges = AIClientRegistry::ui_badges( $wpcc_selected_client );
					if ( $wpcc_sel_badges ) :
						?>
						<div class="wpcc-ai-summary">
							<span class="wpcc-ai-summary__item">
								<span class="wpcc-ai-summary__k"><?php esc_html_e( 'Connects via', 'ai-command-center' ); ?></span>
								<span class="wpcc-ai-summary__v"><?php echo $wpcc_sel_http ? esc_html__( 'Direct HTTP', 'ai-command-center' ) : esc_html__( 'Connector script', 'ai-command-center' ); ?></span>
							</span>
							<span class="wpcc-ai-summary__item">
								<span class="wpcc-ai-summary__k"><?php esc_html_e( 'Node.js', 'ai-command-center' ); ?></span>
								<span class="wpcc-ai-summary__v<?php echo $wpcc_sel_http ? '' : ' wpcc-ai-summary__v--muted'; ?>"><?php echo $wpcc_sel_http ? esc_html__( 'Not required', 'ai-command-center' ) : esc_html__( 'Required', 'ai-command-center' ); ?></span>
							</span>
							<?php foreach ( $wpcc_sel_badges as $wpcc_b ) : ?>
								<?php if ( 'secondary' === $wpcc_b['rank'] && ( 'info' === $wpcc_b['tone'] || 'neutral' === $wpcc_b['tone'] ) ) { continue; } // transport is already spelled out above ?>
								<span class="wpcc-ai-summary__item">
									<span class="wpcc-ai-summary__k"><?php echo 'primary' === $wpcc_b['rank'] && 'rec' === $wpcc_b['tone'] ? esc_html__( 'Status', 'ai-command-center' ) : esc_html__( 'Certification', 'ai-command-center' ); ?></span>
									<span class="wpcc-ai-badge wpcc-ai-badge--<?php echo esc_attr( $wpcc_b['rank'] ); ?> wpcc-ai-badge--<?php echo esc_attr( $wpcc_b['tone'] ); ?>" title="<?php echo esc_attr( $wpcc_b['title'] ); ?>"><?php echo esc_html( $wpcc_b['label'] ); ?></span>
								</span>
							<?php endforeach; ?>
						</div>
						<?php
					endif;
					?>
					<p class="wpcc-ai-field__hint" style="margin-top:0;"><?php
						/*
						 * Two sentences on the same card were contradicting each other:
						 * this one said "add your access token where it says
						 * wpcc_YOUR_TOKEN_HERE" while the line below it said the token
						 * was already filled in. Right after creating a token the second
						 * one is true. Say whichever is actually the case.
						 */
						echo $wpcc_new_token
							? sprintf( /* translators: %s: value */ esc_html__( 'Copy this and paste it into %s. It is complete — your connection address and your access token are both in it.', 'ai-command-center' ), esc_html( $wpcc_current_client['name'] ) )
							/* translators: 1: assistant name, 2: the literal token placeholder shown in the configuration */
							: sprintf( esc_html__( 'Copy this and paste it into %1$s to connect it to this site. It includes your connection address — put your access token in place of %2$s, or paste it in the field below and it will be filled in for you.', 'ai-command-center' ), esc_html( $wpcc_current_client['name'] ), esc_html( AIClientRegistry::TOKEN_PLACEHOLDER ) );
					?></p>
					<?php if ( ! empty( $wpcc_selected_token ) ) : ?>
						<div class="notice inline notice-info" style="margin:0 0 12px;padding:10px 12px;">
							<p style="margin:0;">
								<?php
								printf(
									/* translators: 1: token label, 2: token preview prefix */
									esc_html__( 'Using “%1$s” (starts with %2$s…). Paste that saved token in the field below to drop it straight into the configuration. For security, a token is shown in full only once — if you didn’t save it, create a new token below.', 'ai-command-center' ),
									esc_html( $wpcc_selected_token['label'] ),
									esc_html( $wpcc_selected_token['token_preview'] )
								);
								?>
							</p>
						</div>
					<?php endif; ?>
					<?php if ( $wpcc_cfg_tok_count > 0 ) : ?>
						<p class="wpcc-ai-field__status wpcc-ai-field__status--ok" style="margin:0 0 12px;">&#10003; <?php
							/* translators: %d: number of access tokens */
							printf( esc_html( _n( '%d access token ready.', '%d access tokens ready.', $wpcc_cfg_tok_count, 'ai-command-center' ) ), (int) $wpcc_cfg_tok_count );
						?></p>
					<?php else : ?>
						<p class="wpcc-ai-field__status" style="margin:0 0 12px;"><?php esc_html_e( 'No access token yet — create one in “Access tokens” above, then it appears in this configuration.', 'ai-command-center' ); ?></p>
					<?php endif; ?>
				</div>
				<div class="wpcc-ai-panel__body" style="padding:0;">
					<div class="wpcc-ai-field" style="padding:14px 22px 0;margin:0;">
							<label class="wpcc-ai-field__label" for="wpcc-token-fill"><?php
								echo $wpcc_new_token
									? esc_html__( 'Access token (already filled in below)', 'ai-command-center' )
									: esc_html__( 'Paste your access token to complete the configuration', 'ai-command-center' );
							?></label>
							<input type="text" id="wpcc-token-fill" class="regular-text" placeholder="wpcc_..." autocomplete="off" spellcheck="false" style="width:100%;max-width:520px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;" value="<?php echo esc_attr( $wpcc_new_token ); ?>">
							<p class="wpcc-ai-field__hint" style="margin:6px 0 0;"><?php
								echo $wpcc_new_token
									? esc_html__( 'Nothing more to fill in — the configuration below is ready to copy. Your token stays in this browser; it is never sent back to the server.', 'ai-command-center' )
									: esc_html__( 'Your token is inserted into the configuration below right here in your browser — it is never sent back to the server or stored. Then click “Copy configuration” to copy the complete, ready-to-use config.', 'ai-command-center' );
							?></p>
						</div>
						<pre class="wpcc-ai-config" id="wpcc-config-block"><?php echo esc_html( $wpcc_config_json ); ?></pre>
						<?php
						// This config asks the user's machine to fetch a small relay
						// script from THIS site and run it under Node. That is a
						// reasonable design, but pasting a command that downloads and
						// executes code deserves a plain explanation rather than
						// silence — a user who does not understand what they are
						// pasting cannot meaningfully consent to it.
						?>
						<?php
						/*
						 * The explanation has to match the transport this client actually uses.
						 * Clients that speak HTTP MCP directly (VS Code/Copilot, Codex, ChatGPT,
						 * Gemini CLI, Claude Code) run NO connector and need no Node.js — telling
						 * those users a script executes on their machine is both wrong and a
						 * scarier claim than the truth, which defeats the point of explaining it.
						 */
						?>
						<p class="wpcc-ai-field__hint" style="padding:0 22px 14px;margin:8px 0 0;">
							<?php
							if ( $wpcc_sel_http ) {
								esc_html_e( 'What this does: your assistant connects straight to this site over the web using the address and token above. Nothing is installed or run on your computer, and no other service is involved. Remove the configuration and the connection is gone.', 'ai-command-center' );
							} else {
								esc_html_e( 'What this does: your assistant runs a small connector script on your computer, downloaded from this site, which passes requests to WordPress. It runs locally under your own account, sends nothing anywhere except to this site, and can be removed by deleting the configuration. The connector is part of this plugin and is served from your own domain.', 'ai-command-center' );
							}
							?>
						</p>
				</div>
				<div class="wpcc-ai-panel__body" style="padding-top:14px;">
					<details class="wpcc-ai-advanced" style="margin:0;">
						<summary><?php esc_html_e( 'Connection address & where to paste', 'ai-command-center' ); ?></summary>
						<div class="wpcc-ai-advanced__body">
							<div class="wpcc-ai-field">
								<label class="wpcc-ai-field__label"><?php esc_html_e( 'Connection URL', 'ai-command-center' ); ?></label>
								<div class="wpcc-ai-url">
									<code class="wpcc-ai-url__text"><?php echo esc_html( $wpcc_cfg_mcp_url ); ?></code>
									<button type="button" class="button wpcc-copy-btn" data-copy="<?php echo esc_attr( $wpcc_cfg_mcp_url ); ?>"><?php esc_html_e( 'Copy', 'ai-command-center' ); ?></button>
								</div>
							</div>
							<?php if ( ! empty( $wpcc_current_client['config_paths'] ) ) : ?>
								<div class="wpcc-ai-field">
									<label class="wpcc-ai-field__label"><?php esc_html_e( 'Where to paste this', 'ai-command-center' ); ?></label>
									<table class="widefat" style="border:none;">
										<?php foreach ( $wpcc_current_client['config_paths'] as $os => $path ) : ?>
											<tr><td style="padding:6px 0;width:80px;"><strong><?php echo esc_html( ucfirst( $os ) ); ?></strong></td><td style="padding:6px 0;"><code><?php echo esc_html( $path ); ?></code></td></tr>
										<?php endforeach; ?>
									</table>
								</div>
							<?php endif; ?>
						</div>
					</details>
				</div>
			</div>
		<?php else : ?>
			<div class="wpcc-ai-panel">
				<div class="wpcc-ai-panel__header"><?php esc_html_e( 'Your configuration', 'ai-command-center' ); ?></div>
				<div class="wpcc-ai-panel__body">
					<p style="color:#646970;"><?php esc_html_e( 'A ready-made configuration isn’t available for this assistant yet. You can still connect it manually using the connection address and an access token below.', 'ai-command-center' ); ?></p>
					<div class="wpcc-ai-field" style="margin-top:14px;">
						<label class="wpcc-ai-field__label"><?php esc_html_e( 'Connection URL', 'ai-command-center' ); ?></label>
						<div class="wpcc-ai-url">
							<code class="wpcc-ai-url__text"><?php echo esc_html( $wpcc_cfg_mcp_url ); ?></code>
							<button type="button" class="button wpcc-copy-btn" data-copy="<?php echo esc_attr( $wpcc_cfg_mcp_url ); ?>"><?php esc_html_e( 'Copy', 'ai-command-center' ); ?></button>
							<span class="wpcc-ai-copied" id="wpcc-copy-feedback">&#10003; <?php esc_html_e( 'Copied!', 'ai-command-center' ); ?></span>
						</div>
					</div>
				</div>
			</div>
		<?php endif; ?>
		<?php endif; // no token yet: the configuration section has nothing to show. ?>

		<?php
		/*
		 * DELETE (until usable): the connection test needs a token, and on a fresh
		 * site there isn't one. Its own field said so — "Paste an access token, or
		 * create one in 'Access tokens' above" — which is the card admitting it
		 * cannot be used yet. Before a token exists the screen is now exactly one
		 * decision (which assistant) and one action (create a token).
		 */
		?>
		<?php if ( $wpcc_cfg_tok_count > 0 ) : ?>
		<!-- Test the connection safely -->
		<div class="wpcc-ai-panel">
			<div class="wpcc-ai-panel__header"><?php esc_html_e( 'Test the connection safely', 'ai-command-center' ); ?></div>
			<div class="wpcc-ai-panel__body">
				<p><?php esc_html_e( 'Run a quick read-only test to confirm your assistant can connect. This only reads — it never changes anything on your site.', 'ai-command-center' ); ?></p>
				<div style="margin-bottom: 12px;">
					<label for="wpcc-test-token" style="display: block; font-weight: 600; margin-bottom: 4px;"><?php esc_html_e( 'Access token', 'ai-command-center' ); ?></label>
					<input type="text" id="wpcc-test-token" class="regular-text" placeholder="wpcc_..." style="width: 100%; max-width: 500px; font-family: monospace;"
						value="<?php echo esc_attr( $wpcc_new_token ); ?>">
					<p style="color: #646970; font-size: 12px; margin: 4px 0 0;"><?php esc_html_e( 'Paste an access token, or create one in “Access tokens” above.', 'ai-command-center' ); ?></p>
				</div>
				<button type="button" class="button" id="wpcc-test-connection"><?php esc_html_e( 'Run read-only test', 'ai-command-center' ); ?></button>
				<div class="wpcc-ai-verify-result" id="wpcc-verify-result"></div>
			</div>
		</div>

		<?php endif; ?>

		<!-- Safety note -->
		<div class="wpcc-ai-safe-note" role="note">
			<span class="wpcc-ai-safe-note__icon" aria-hidden="true">&#128274;</span>
			<div>
				<strong><?php esc_html_e( 'Connecting an assistant is safe by design.', 'ai-command-center' ); ?></strong>
				<ul>
					<li><?php esc_html_e( 'Any change your assistant makes waits for your approval first.', 'ai-command-center' ); ?></li>
					<li><?php esc_html_e( 'Every action is recorded under Changes.', 'ai-command-center' ); ?></li>
					<li><?php esc_html_e( 'Reversible changes can be undone from the Changes screen.', 'ai-command-center' ); ?></li>
				</ul>
			</div>
		</div>

	<?php elseif ( 'activity' === $wpcc_tab ) : ?>
		<!-- ===== ACTIVITY TAB ===== -->

		<div class="wpcc-ai-panel">
			<div class="wpcc-ai-panel__header"><?php esc_html_e( 'Recent assistant activity', 'ai-command-center' ); ?></div>
			<div class="wpcc-ai-panel__body">
				<?php if ( empty( $wpcc_ai_activity ) ) : ?>
					<p style="color:#646970;"><?php esc_html_e( 'No assistant activity recorded yet. It appears here once an assistant connects to this site.', 'ai-command-center' ); ?></p>
				<?php else : ?>
					<table class="wpcc-ai-token-table">
						<thead><tr><th><?php esc_html_e( 'Time', 'ai-command-center' ); ?></th><th><?php esc_html_e( 'Event', 'ai-command-center' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $wpcc_ai_activity as $entry ) : ?>
							<tr>
								<td style="white-space:nowrap;"><?php echo esc_html( gmdate( 'Y-m-d H:i:s', $entry['timestamp'] ) ); ?></td>
								<td><?php echo esc_html( $entry['action'] ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>

	<?php elseif ( 'security' === $wpcc_tab ) : ?>
		<!-- ===== SECURITY TAB ===== -->

		<div class="wpcc-ai-panel">
			<div class="wpcc-ai-panel__header"><?php esc_html_e( 'How assistant access is controlled', 'ai-command-center' ); ?></div>
			<div class="wpcc-ai-panel__body">
				<p><?php esc_html_e( 'Every assistant connects through the same endpoint and is held to the same rules. No assistant gets extra privileges, and none can skip approval, recording or the limits on its access token.', 'ai-command-center' ); ?></p>
				<ul class="wpcc-ai-security-list">
					<li>
						<strong><?php esc_html_e( 'Capabilities', 'ai-command-center' ); ?></strong>
						<span><?php esc_html_e( 'Every tool requires a specific capability assigned to the API token. No client can bypass capability enforcement.', 'ai-command-center' ); ?></span>
					</li>
					<li>
						<strong><?php esc_html_e( 'Approvals', 'ai-command-center' ); ?></strong>
						<span><?php esc_html_e( 'Operations requiring human approval must go through the request-approve-execute workflow. No client can auto-approve.', 'ai-command-center' ); ?></span>
					</li>
					<li>
						<strong><?php esc_html_e( 'Queue', 'ai-command-center' ); ?></strong>
						<span><?php esc_html_e( 'All operations follow the same queuing and execution flow. No client can bypass the queue or execute directly.', 'ai-command-center' ); ?></span>
					</li>
					<li>
						<strong><?php esc_html_e( 'Audit', 'ai-command-center' ); ?></strong>
						<span><?php esc_html_e( 'Every action is logged with the client source, actor context, and timestamp. Full traceability for all clients.', 'ai-command-center' ); ?></span>
					</li>
					<li>
						<strong><?php esc_html_e( 'Rollback', 'ai-command-center' ); ?></strong>
						<span><?php esc_html_e( 'Every modification is snapshotted before execution. All clients inherit the same rollback protection.', 'ai-command-center' ); ?></span>
					</li>
				</ul>
			</div>
		</div>

		<div class="wpcc-ai-panel">
			<div class="wpcc-ai-panel__header"><?php esc_html_e( 'Architecture', 'ai-command-center' ); ?></div>
			<div class="wpcc-ai-panel__body">
				<p><?php esc_html_e( 'Every assistant follows the same path through the plugin:', 'ai-command-center' ); ?></p>
				<pre style="background:#f6f7f7;padding:14px;border-radius:4px;font-size:13px;line-height:1.8;overflow-x:auto;">AI Client &rarr; MCP &rarr; WP Command Center &rarr; Capability Runtime &rarr; Approval Runtime &rarr; Queue Runtime &rarr; OperationExecutor &rarr; Verification &rarr; Audit &rarr; Rollback</pre>
				<p style="color:#646970;font-size:12px;"><?php esc_html_e( 'There are no per-client runtimes, no special execution paths, and no vendor-specific privileges.', 'ai-command-center' ); ?></p>
			</div>
		</div>

	<?php endif; ?>

</div>

<script>
(function() {
	// Live, browser-only token fill: insert the pasted access token into the
	// displayed configuration so "Copy configuration" copies a complete, ready
	// config. The token is substituted in the DOM only — it is never sent back to
	// the server or persisted (the server stores only a salted hash of tokens).
	var tokenFill  = document.getElementById('wpcc-token-fill');
	var configBlock = document.getElementById('wpcc-config-block');
	if (tokenFill && configBlock) {
		var configTemplate = configBlock.textContent;
		// The same constant the generators emit and the note above names, so this
		// field can never again search for a string the configuration does not have.
		var PLACEHOLDER = <?php echo wp_json_encode( AIClientRegistry::TOKEN_PLACEHOLDER ); ?>;
		var applyToken = function() {
			var v = tokenFill.value.trim();
			configBlock.textContent = v ? configTemplate.split(PLACEHOLDER).join(v) : configTemplate;
		};
		tokenFill.addEventListener('input', applyToken);
		applyToken(); // apply any pre-filled (just-created) token on load
		// If the user arrived via "Use in config", focus the field so they can paste.
		if (window.location.hash === '#wpcc-token-fill' && !tokenFill.value) {
			tokenFill.focus();
		}
	}

	document.querySelectorAll('.wpcc-copy-btn').forEach(function(btn) {
		btn.addEventListener('click', function() {
			var targetId = this.getAttribute('data-copy-target');
			var text;
			if (targetId) {
				text = document.getElementById(targetId).textContent;
			} else {
				text = this.getAttribute('data-copy');
			}
			if (!text) return;
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(text).then(function() {
					var fb = document.getElementById('wpcc-copy-feedback');
					if (fb) { fb.classList.add('wpcc-ai-copied--visible'); setTimeout(function() { fb.classList.remove('wpcc-ai-copied--visible'); }, 2000); }
				});
			} else {
				var ta = document.createElement('textarea');
				ta.value = text; ta.style.position = 'fixed'; ta.style.left = '-9999px';
				document.body.appendChild(ta); ta.select();
				try { document.execCommand('copy'); } catch(e) {}
				document.body.removeChild(ta);
				var fb = document.getElementById('wpcc-copy-feedback');
				if (fb) { fb.classList.add('wpcc-ai-copied--visible'); setTimeout(function() { fb.classList.remove('wpcc-ai-copied--visible'); }, 2000); }
			}
		});
	});

	document.querySelectorAll('.wpcc-select-token-btn').forEach(function(btn) {
		btn.addEventListener('click', function() {
			var tid = this.getAttribute('data-token-id');
			var url = new URL(window.location.href);
			url.searchParams.set('token_id', tid);
			url.hash = 'wpcc-token-fill';
			window.location.href = url.toString();
		});
	});

	var testBtn = document.getElementById('wpcc-test-connection');
	if (testBtn) {
		testBtn.addEventListener('click', function() {
			var resultEl = document.getElementById('wpcc-verify-result');
			var token = document.getElementById('wpcc-test-token').value.trim();

			if (!token) {
				resultEl.className = 'wpcc-ai-verify-result wpcc-ai-verify-result--fail';
				resultEl.innerHTML = '<p><strong>&#10007; No token:</strong> <?php esc_html_e( 'Paste an API token above or generate one in the Configuration tab.', 'ai-command-center' ); ?></p>';
				return;
			}

			resultEl.className = 'wpcc-ai-verify-result wpcc-ai-verify-result--loading';
			resultEl.innerHTML = '<p><span class="spinner is-active" style="float:none;margin:0 10px 0 0;"></span><?php esc_html_e( 'Testing connection...', 'ai-command-center' ); ?></p>';
			var authHeader = { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json' };
			var authHeaderGet = { 'Authorization': 'Bearer ' + token };
			var baseUrl = <?php echo wp_json_encode( rest_url( \WPCommandCenter\Mcp\McpServerRuntime::NAMESPACE ) ); ?>;
			// The connector is the first thing a RELAY client touches: the generated
			// configuration downloads this file from this site and runs it. If it is not
			// reachable nothing else matters — every other check below can pass while the
			// assistant is still unable to connect, so it is checked first and by URL.
			var relayUrl = <?php echo wp_json_encode( WPCC_PLUGIN_URL . 'sdk/javascript/wpcc-mcp-relay.mjs?v=' . WPCC_VERSION ); ?>;
			/*
			 * …but ONLY for a relay client. A Direct HTTP assistant never fetches the
			 * connector, so testing it answers a question that assistant does not ask —
			 * and gets it wrong in both directions. On the panel directly above, the
			 * product has just told this user that nothing is installed or run on their
			 * computer; a check named "Connector script" then reported on one anyway.
			 * Worse, on a site where the file is genuinely unreachable the check FAILS,
			 * so a Direct HTTP user whose setup is completely correct is told "Some
			 * checks failed" and sent hunting for a component they will never use.
			 */
			var usesRelay = <?php echo wp_json_encode( ! $wpcc_sel_http ); ?>;
			var checks = [];

			// Check labels, in the site's language. These were bare English literals
			// while the connector's label beside them was translated, so a translated
			// site rendered a half-translated results table.
			var L = {
				relay:     <?php echo wp_json_encode( __( 'Connector script', 'ai-command-center' ) ); ?>,
				health:    <?php echo wp_json_encode( __( 'Health endpoint', 'ai-command-center' ) ); ?>,
				manifest:  <?php echo wp_json_encode( __( 'Agent manifest', 'ai-command-center' ) ); ?>,
				initialize:<?php echo wp_json_encode( __( 'MCP handshake', 'ai-command-center' ) ); ?>,
				resources: <?php echo wp_json_encode( __( 'MCP resources', 'ai-command-center' ) ); ?>,
				tools:     <?php echo wp_json_encode( __( 'MCP tools', 'ai-command-center' ) ); ?>,
				relay404:  <?php echo wp_json_encode( __( 'Not found on this site — your assistant cannot start the connector.', 'ai-command-center' ) ); ?>
			};

			function record(name, pass, detail) {
				checks.push({ name: name, pass: pass, detail: detail || '' });
			}

			( usesRelay
				? fetch(relayUrl, { cache: 'no-store' })
					.then(function(r) { return r.text().then(function(t) { return { ok: r.ok, status: r.status, text: t }; }); })
					.then(function(r) {
						var pass = r.ok && r.text.length > 0;
						var detail = pass ? '' : ( r.status === 404 ? L.relay404 : ( 'HTTP ' + r.status ) );
						record(L.relay, pass, detail);
					}, function(err) {
						record(L.relay, false, err.message);
					})
				: Promise.resolve()
			)
				.then(function() { return fetch(baseUrl + '/health', { headers: authHeaderGet }); })
				.then(function(r) { return r.json().then(function(d) { return { ok: r.ok, status: r.status, data: d }; }); })
				.then(function(r) {
					var pass = r.ok && r.data.status === 'ok';
					var detail = pass ? '' : ('HTTP ' + r.status + ': ' + (r.data.message || r.data.code || JSON.stringify(r.data).substring(0, 200)));
					record(L.health, pass, detail);
					return fetch(baseUrl + '/agent/manifest', { headers: authHeaderGet }).then(function(r) { return r.json().then(function(d) { return { ok: r.ok, status: r.status, data: d }; }); });
				})
				.then(function(r) {
					var pass = r.ok && r.data.plugin;
					var detail = pass ? '' : ('HTTP ' + r.status + ': ' + (r.data.message || r.data.code || JSON.stringify(r.data).substring(0, 200)));
					record(L.manifest, pass, detail);
					return fetch(baseUrl + '/mcp', { method: 'POST', headers: authHeader, body: JSON.stringify({ jsonrpc: '2.0', method: 'initialize', params: { protocolVersion: '2024-11-05' }, id: 1 }) }).then(function(r) { return r.json().then(function(d) { return { ok: r.ok, status: r.status, data: d }; }); });
				})
				.then(function(r) {
					var pass = r.ok && r.data.result && r.data.result.serverInfo;
					var detail = pass ? '' : ('HTTP ' + r.status + ': ' + ((r.data.error && r.data.error.message) || (r.data.message) || JSON.stringify(r.data).substring(0, 200)));
					record(L.initialize, pass, detail);
					return fetch(baseUrl + '/mcp', { method: 'POST', headers: authHeader, body: JSON.stringify({ jsonrpc: '2.0', method: 'resources/list', id: 2 }) }).then(function(r) { return r.json().then(function(d) { return { ok: r.ok, status: r.status, data: d }; }); });
				})
				.then(function(r) {
					var res = r.data.result;
					var pass = r.ok && res && res.resources && res.resources.length >= 7;
					var detail = pass ? '' : ('HTTP ' + r.status + ': ' + ((r.data.error && r.data.error.message) || 'got ' + (res && res.resources ? res.resources.length : 0) + ' resources'));
					record(L.resources, pass, detail);
					return fetch(baseUrl + '/mcp', { method: 'POST', headers: authHeader, body: JSON.stringify({ jsonrpc: '2.0', method: 'tools/list', id: 3 }) }).then(function(r) { return r.json().then(function(d) { return { ok: r.ok, status: r.status, data: d }; }); });
				})
				.then(function(r) {
					var res = r.data.result;
					var pass = r.ok && res && res.tools && res.tools.length > 0;
					var detail = pass ? '' : ('HTTP ' + r.status + ': ' + ((r.data.error && r.data.error.message) || 'got ' + (res && res.tools ? res.tools.length : 0) + ' tools'));
					record(L.tools, pass, detail);

					var allPass = checks.every(function(c) { return c.pass; });
					resultEl.className = 'wpcc-ai-verify-result wpcc-ai-verify-result--' + (allPass ? 'success' : 'fail');
					var html = allPass ? '<h3 style="margin:0 0 10px;color:#00a32a;">&#10003; <?php esc_html_e( 'All checks passed!', 'ai-command-center' ); ?></h3>' : '<h3 style="margin:0 0 10px;color:#d63638;">&#10007; <?php esc_html_e( 'Some checks failed.', 'ai-command-center' ); ?></h3>';
					html += '<table style="border-collapse:collapse;width:100%;">';
					checks.forEach(function(c) {
						html += '<tr><td style="padding:4px 8px;">' + (c.pass ? '&#10003;' : '&#10007;') + '</td><td style="padding:4px 8px;font-weight:600;">' + c.name + '</td>';
						if (!c.pass) { html += '<td style="padding:4px 8px;color:#d63638;font-size:12px;">' + c.detail + '</td>'; }
						html += '</tr>';
					});
					html += '</table>';
					resultEl.innerHTML = html;
				})
				.catch(function(err) {
					resultEl.className = 'wpcc-ai-verify-result wpcc-ai-verify-result--fail';
					resultEl.innerHTML = '<p><strong>&#10007; <?php esc_html_e( 'Connection test failed:', 'ai-command-center' ); ?></strong> ' + err.message + '</p><p style="color:#646970;font-size:12px;"><?php esc_html_e( 'Check that your site is reachable and the token is valid.', 'ai-command-center' ); ?></p>';
				});
		});
	}
})();
</script>
