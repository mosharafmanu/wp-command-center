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
use WPCommandCenter\Operations\SecurityModeManager;

$wpcc_tokens      = new AuthTokens();
$wpcc_all_tokens   = $wpcc_tokens->list();
$wpcc_clients      = AIClientRegistry::get_clients();
$wpcc_active_clients = AIClientRegistry::get_active_clients();
$wpcc_client_groups = AIClientRegistry::get_client_groups();
$wpcc_counts       = AIClientRegistry::get_counts();
$wpcc_matrix       = AIClientRegistry::get_compatibility_matrix();
$wpcc_ops          = ( new OperationRegistry() )->get_operations();
$wpcc_tool_count   = count( $wpcc_ops );

// Selected client for config tab
$wpcc_selected_client = sanitize_key( (string) ( $_GET['client'] ?? 'chatgpt' ) );
$wpcc_current_client  = AIClientRegistry::get_client( $wpcc_selected_client );
if ( ! $wpcc_current_client || \WPCommandCenter\Integration\AIClientRegistry::CERT_PLANNED === ( $wpcc_current_client['certification_level'] ?? '' ) ) {
	$wpcc_selected_client = 'chatgpt';
	$wpcc_current_client  = AIClientRegistry::get_client( 'chatgpt' );
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

/*
 * How this client takes its credential, and whether it can be set up with one command.
 *
 * Resolved once here for the same reason as the transport above: the setup steps, the
 * warning text, the copy box and the "what do I paste?" label all depend on it, and a
 * screen that answers that question in more than one place will eventually answer it two
 * different ways.
 *
 * 'env_var' is the case this whole block exists for. Codex and ChatGPT Desktop take the
 * NAME of an environment variable, not the token — a real token pasted into their
 * "Bearer token env var" field produces a 401 from a perfectly healthy server, which is
 * indistinguishable from a broken install. See BaseClientIntegration::credential_mode().
 */
$wpcc_sel_cred_mode = AIClientRegistry::credential_mode_for( $wpcc_selected_client );
$wpcc_sel_env_var   = AIClientRegistry::credential_env_var_for( $wpcc_selected_client );
$wpcc_sel_uses_env  = 'env_var' === $wpcc_sel_cred_mode;

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
$wpcc_new_record    = null;
$wpcc_token_message  = '';
$wpcc_token_error    = '';

if ( isset( $_POST['wpcc_token_action'] ) && check_admin_referer( 'wpcc_ai_integrations' ) && current_user_can( 'manage_options' ) ) {
	$wpcc_token_action = sanitize_key( $_POST['wpcc_token_action'] );

	/*
	 * Token creation is a DELIBERATE act now, not a side effect of one click.
	 *
	 * This used to be a single `<button value="generate_full">` beside the word
	 * "Create access token". Pressing it — or pressing Enter anywhere in that
	 * form — minted a full-access, never-expiring key to the whole site, named
	 * after the clock ("AI Full Access 2026-08-05 03:36"). The customer chose
	 * nothing, was warned of nothing, and afterwards could not tell one such
	 * token from the next. That is the single riskiest control in the product.
	 *
	 * It is now a dialog the customer has to open, fill in and submit: a label
	 * they choose (required), an access level they pick, and an expiry. The
	 * scope arrives from the form instead of being encoded in the button, so a
	 * stray submit cannot silently mean "full access".
	 */
	if ( 'create' === $wpcc_token_action ) {
		/*
		 * Full access requires an EXPLICIT selection — enforced here, not just in
		 * the markup.
		 *
		 * This test used to run the other way round ("is it read_only? …else full"),
		 * so a missing, misspelt or stripped `wpcc_token_scope` produced a
		 * full-access key. On the one control in the product that hands out access
		 * to the whole site, the absence of a choice must never BE a choice. Only
		 * the literal string "full" grants full access; everything else — including
		 * a request that carries no scope at all — is read-only.
		 */
		$scope = AuthTokens::SCOPE_FULL === sanitize_key( (string) ( $_POST['wpcc_token_scope'] ?? '' ) )
			? AuthTokens::SCOPE_FULL
			: AuthTokens::SCOPE_READ_ONLY;

		$label = sanitize_text_field( wp_unslash( (string) ( $_POST['wpcc_token_label'] ?? '' ) ) );

		// Expiry is validated against the offered set. An unrecognised value used
		// to fall through to "never" on the manager screen, which turns a typo
		// into a permanent key — here an unknown value is simply refused.
		// An ABSENT expiry field now means the recommended 30 days, not "never".
		// Fail-closed on this control means the shorter life, not the longer one:
		// a form that arrives without the field (tampered, or an old cached page)
		// should not be the way somebody gets a permanent credential.
		$wpcc_exp_choice = sanitize_key( (string) ( $_POST['wpcc_token_expires'] ?? '30d' ) );
		$wpcc_exp_map    = [
			'30d' => 30 * DAY_IN_SECONDS,
			'90d' => 90 * DAY_IN_SECONDS,
			'1y'  => YEAR_IN_SECONDS,
		];

		if ( 'never' !== $wpcc_exp_choice && ! isset( $wpcc_exp_map[ $wpcc_exp_choice ] ) ) {
			$wpcc_token_error = __( 'Choose when this token should stop working, then try again.', 'action-steward' );
			$result           = null;
		} else {
			$expires_at = isset( $wpcc_exp_map[ $wpcc_exp_choice ] ) ? time() + $wpcc_exp_map[ $wpcc_exp_choice ] : null;
			$result     = $wpcc_tokens->create( $label, $scope, $expires_at, get_current_user_id() );
		}

		if ( null === $result ) {
			// Validation already explained itself above; fall through to render.
			$wpcc_all_tokens = $wpcc_tokens->list();
		} elseif ( is_wp_error( $result ) ) {
			$wpcc_token_error = $result->get_error_message();
		} else {
			$wpcc_new_token    = $result['token'];
			// What was created, in the customer's own words, so the reveal card can
			// say which token this is rather than only that "a token" exists.
			$wpcc_new_record   = $result['record'];
			$wpcc_token_message = __( 'Token generated. Copy it now — it will not be shown again.', 'action-steward' );

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
			$wpcc_token_message = __( 'Token revoked. Any assistant using it is disconnected immediately.', 'action-steward' );
		} else {
			$wpcc_token_error = __( 'That token could not be found.', 'action-steward' );
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
	'configuration' => __( 'Set up', 'action-steward' ),
	'activity'      => __( 'Recent requests', 'action-steward' ),
	'security'      => __( 'How access works', 'action-steward' ),
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
	/* overflow-x on the body, not the panel: the panel needs overflow:hidden for its
	   border-radius, and that clip removed the Revoke control entirely on a phone-
	   width window — a security action with no way to reach it. */
	.wpcc-ai-panel__body { padding: 22px; overflow-x: auto; -webkit-overflow-scrolling: touch; }
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
	/*
	 * min-width: max-content is what actually makes the panel body scroll.
	 * With width:100% alone the table box stays pinned to the container while its
	 * nowrap action cell overflows *visually* — the parent's scrollWidth never
	 * grows, so overflow-x:auto has nothing to scroll and the Revoke button was
	 * simply unreachable below ~520px. Sizing the table to its content instead
	 * makes the overflow real, which the body can then scroll. Wide screens are
	 * unchanged: max-content is narrower than 100% there, so width:100% wins.
	 */
	.wpcc-ai-token-table { width: 100%; min-width: max-content; border-collapse: collapse; }
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

	/* Families share the available row before their cards do. This keeps the small
	   two-client families compact instead of making auto-fill reserve two empty card
	   columns beside them. Container queries follow the actual wp-admin content width,
	   including the admin menu, rather than assuming a particular browser width. */
	.wpcc-ai-family-layout { container-type:inline-size; }
	.wpcc-ai-family-grid { display:grid;grid-template-columns:minmax(0,1fr);gap:18px 20px;margin-top:16px; }
	.wpcc-ai-family { min-width:0;margin:0; }
	.wpcc-ai-family--compact { display:flex;flex-direction:column; }
	.wpcc-ai-picks { display:grid;grid-template-columns:repeat(2,minmax(0,1fr));grid-auto-rows:1fr;align-items:stretch;gap:11px;margin-top:5px; }
	.wpcc-ai-family--compact .wpcc-ai-picks { flex:1; }
	.wpcc-ai-family--wide,
	.wpcc-ai-family--other { grid-column:1 / -1; }
	.wpcc-ai-family--wide .wpcc-ai-picks { grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); }
	.wpcc-ai-family__name { margin:0 0 9px;font-size:12px;line-height:1.3;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#646970; }
	.wpcc-ai-family--other { border-top:1px solid #eef0f4;padding-top:14px; }
	.wpcc-ai-family--other > summary { cursor:pointer;font-weight:600;color:#50575e; }
	.wpcc-ai-family--other .wpcc-ai-picks { grid-template-columns:repeat(auto-fit,minmax(220px,280px)); }
	@container (min-width: 760px) {
		.wpcc-ai-family-grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
		.wpcc-ai-family--compact-last { grid-column:1 / -1; }
		.wpcc-ai-family--compact-last .wpcc-ai-picks { grid-template-columns:repeat(2,minmax(0,280px)); }
	}
	@container (min-width: 900px) {
		.wpcc-ai-family-grid { grid-template-columns:repeat(3,minmax(0,1fr)); }
		.wpcc-ai-family--compact-last { grid-column:auto; }
		.wpcc-ai-family--compact-last .wpcc-ai-picks { grid-template-columns:repeat(2,minmax(0,1fr)); }
	}
	@container (max-width: 559px) {
		.wpcc-ai-picks,
		.wpcc-ai-family--wide .wpcc-ai-picks,
		.wpcc-ai-family--other .wpcc-ai-picks { grid-template-columns:minmax(0,1fr); }
	}

	.wpcc-ai-pick { position: relative; height: 100% !important; min-height:104px;display:flex !important;flex-direction:column;
		align-items:flex-start !important;gap:8px;padding:14px 15px 15px !important;line-height:1.4 !important;
		border: 1px solid #e3e5ec !important; border-radius: 10px; background: #fff; box-shadow: 0 1px 2px rgba(16,24,40,.03);
		min-width:0;white-space:normal !important;overflow-wrap:anywhere;text-decoration:none;
		transition: border-color .13s ease, box-shadow .13s ease, transform .13s ease, background-color .13s ease; }
	.wpcc-ai-pick__name { max-width:100%;font-size:13.5px;line-height:1.3;font-weight:600;color:#1d2327;letter-spacing:-.01em; }
	.wpcc-ai-pick__surface { max-width:100%;font-size:11.5px;line-height:1.35;color:#646970;margin-top:-4px; }

	.wpcc-ai-pick:hover { border-color: #c8ccd4 !important; background: #fff; transform: translateY(-1px);
		box-shadow: 0 1px 2px rgba(16,24,40,.04), 0 6px 16px rgba(16,24,40,.06); }
	/* Keyboard focus must be at least as visible as hover — these are links. */
	.wpcc-ai-pick:focus-visible { outline: 2px solid #2271b1; outline-offset: 2px; }

	/* Selected: a quiet raised panel, not a filled blue button. Weight comes from an
	   inset accent rule, a slightly stronger border and real elevation — the same way
	   an enterprise settings list marks the active row. The old treatment flooded the
	   card with #f0f6fc, which shouted louder than the assistant's own name. */
	.wpcc-ai-pick.is-selected { border-color:#aeb4bd !important;background:#fff;padding-left:19px !important;
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
	/* Earned certification. Outlined like its siblings so it stays subordinate to the
	   recommendation, but green — it is the only positive claim on the card. */
	.wpcc-ai-badge--secondary.wpcc-ai-badge--ok   { border-color: #aadfb6; color: #04620f; }
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
	.wpcc-ai-pick .wpcc-ai-badge { margin-top:auto;font-size:10px; }
	.wpcc-selected-client-note { display:flex;align-items:flex-start;gap:7px;margin:12px 0 14px;padding:8px 11px;
		border-left:3px solid #72aee6;border-radius:0 6px 6px 0;background:#f6f9fc;color:#50575e;font-size:12.5px;line-height:1.45; }
	.wpcc-selected-client-note strong { flex:0 0 auto;color:#1d2327; }
	.wpcc-create-access__lead { margin:0; }
	#wpcc-create-access .wpcc-selected-client-note { margin:var(--wpcc-space-3,12px) 0 0; }
	.wpcc-create-access__form { margin-top:var(--wpcc-space-4,16px); }
	.wpcc-create-access__state { margin:var(--wpcc-space-3,12px) 0 0;color:#646970; }
	#wpcc-create-access-title:focus { outline:2px solid #2271b1;outline-offset:3px;border-radius:3px; }

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
	.wpcc-connect-system__intro { display:flex;align-items:center;gap:8px;margin:0 0 var(--wpcc-space-5,20px);font-size:13px;color:#646970; }
	.wpcc-connect-system__intro strong { display:inline-flex;padding:2px 9px;border:1px solid #cbdcef;border-radius:999px;background:#f6f9fc;color:#1d5b96;font-size:11px;font-weight:650; }
	.wpcc-connect-credential { display:flex;align-items:flex-start;gap:10px;max-width:720px;margin:0 0 var(--wpcc-space-5,20px);padding:11px 13px;border:1px solid #dfe5ec;border-radius:8px;background:#f8fafc;color:#3c434a; }
	.wpcc-connect-credential > .dashicons { flex:0 0 auto;width:18px;height:18px;font-size:18px;color:#2271b1; }
	.wpcc-connect-credential__content { flex:1;min-width:0; }
	.wpcc-connect-credential strong,.wpcc-connect-credential label { display:block;margin:0 0 3px;font-size:13px;font-weight:650;color:#1d2327; }
	.wpcc-connect-credential p { margin:0;font-size:12px;line-height:1.5;color:#646970; }
	.wpcc-connect-credential input { width:100%;max-width:520px;margin:3px 0 5px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace; }
	.wpcc-connect-steps { max-width:820px; }
	.wpcc-connect-step { position:relative;display:grid;grid-template-columns:34px minmax(0,1fr);column-gap:14px;padding:0 0 var(--wpcc-space-6,24px);margin:0; }
	.wpcc-connect-step:last-child { padding-bottom:0; }
	.wpcc-connect-step::after { content:"";position:absolute;left:16px;top:36px;bottom:4px;width:1px;background:#dfe3e8; }
	.wpcc-connect-step:last-child::after { display:none; }
	.wpcc-connect-step__number { position:relative;z-index:1;display:flex;align-items:center;justify-content:center;width:32px;height:32px;border:1px solid #b8d0e7;border-radius:50%;background:#eef5fb;color:#135e96;font-size:13px;font-weight:700; }
	.wpcc-connect-step__body { min-width:0;padding-top:1px; }
	.wpcc-connect-step__title { margin:0 0 4px;font-size:14px;line-height:1.4;font-weight:650;color:#1d2327; }
	.wpcc-connect-step__description { margin:0 0 10px;max-width:76ch;font-size:13px;line-height:1.55;color:#50575e; }
	.wpcc-connect-step__actions { display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 0 8px; }
	.wpcc-connect-step__actions--os .button { min-width:150px; }
	.wpcc-connect-note { max-width:72ch;margin:8px 0 10px;padding:8px 10px;border-left:3px solid #72aee6;border-radius:0 6px 6px 0;background:#f6f9fc;color:#50575e;font-size:12px;line-height:1.5; }
	.wpcc-token-needed { margin:0 0 var(--wpcc-space-5,20px); }
	.wpcc-setup-choice-grid { display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;max-width:760px; }
	.wpcc-setup-choice { min-width:0;padding:12px 13px;border:1px solid #e3e5ec;border-radius:8px;background:#fbfcfd; }
	.wpcc-setup-choice > strong { display:block;margin:0 0 4px;font-size:13px;color:#1d2327; }
	.wpcc-setup-choice > p { margin:0 0 10px;font-size:12px;line-height:1.5;color:#50575e; }
	.wpcc-setup-choice .wpcc-connect-step__actions { margin-bottom:0; }
	[data-wpcc-requires-token-control][disabled] { cursor:not-allowed;opacity:.55; }
	.wpcc-action-preview,.wpcc-connect-why,.wpcc-connect-help { max-width:760px;margin:8px 0 0;border:1px solid #e3e5ec;border-radius:8px;background:#fff; }
	.wpcc-action-preview > summary,.wpcc-connect-why > summary,.wpcc-connect-help > summary { cursor:pointer;padding:9px 12px;color:#50575e;font-size:12px;font-weight:600; }
	.wpcc-action-preview__body,.wpcc-connect-why > div,.wpcc-connect-help > div { padding:0 12px 12px; }
	.wpcc-action-preview__body .wpcc-ai-config,.wpcc-connect-why .wpcc-ai-config { max-height:210px;margin:4px 0 10px;border-radius:7px;overflow:auto; }
	.wpcc-action-preview__label { display:block;margin:8px 0 3px;font-size:11px;color:#646970; }
	.wpcc-connect-why p,.wpcc-connect-help p { margin:5px 0;font-size:12px;line-height:1.55;color:#50575e; }
	.wpcc-connect-help { margin:var(--wpcc-space-5,20px) 0 0; }
	.wpcc-primary-config { max-height:230px;border-radius:9px;margin:9px 0; }
	.wpcc-verify-system { max-width:820px; }
	.wpcc-verify-system__lead { margin:0 0 12px;font-size:13px;line-height:1.55;color:#3c434a; }
	.wpcc-verify-system__instruction { margin:0 0 7px;font-size:12px;font-weight:650;color:#50575e; }
	.wpcc-verify-prompt { background:#f6f7f9;border:1px solid #e3e5ec;border-radius:9px;padding:12px 14px;margin:0;display:flex;gap:12px;align-items:center; }
	.wpcc-verify-prompt code { flex:1;white-space:pre-wrap;color:#1d2327;background:transparent;line-height:1.55; }
	.wpcc-verify-success { margin:14px 0 0;padding:11px 13px;border-left:3px solid #00a32a;border-radius:0 7px 7px 0;background:#f4f9f5;color:#3c434a; }
	.wpcc-verify-success > strong { display:block;margin-bottom:5px;font-size:12px;color:#1d2327; }
	.wpcc-verify-success ul { display:flex;flex-wrap:wrap;gap:5px 18px;margin:0;padding:0;list-style:none;font-size:12px; }
	.wpcc-verify-success li::before { content:"\2713";margin-right:6px;color:#008a20;font-weight:700; }
	.wpcc-inline-warning { padding:10px 12px;border-left:3px solid #dba617;background:#fcf9e8;color:#5f4b00;margin:10px 0;border-radius:0 6px 6px 0; }
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
	.wpcc-token-list { margin-top:16px;border-top:1px solid #eef0f4;padding-top:12px; }
	.wpcc-token-list > summary { cursor:pointer;font-weight:600;color:#50575e; }
	/* WordPress keeps its admin sidebar until a wider breakpoint. Collapse the picker
	   before the content column becomes narrower than two usable cards, not only at phone width. */
	@media (max-width: 960px) {
		.wpcc-ai-picks { grid-template-columns:1fr; }
	}
	@media (max-width: 600px) {
		.wpcc-ai-pick { min-height:0; }
		.wpcc-connect-step { grid-template-columns:30px minmax(0,1fr);column-gap:11px; }
		.wpcc-connect-step__number { width:28px;height:28px; }
		.wpcc-connect-step::after { left:14px;top:32px; }
		.wpcc-connect-step__actions .button { width:100%;text-align:center; }
		.wpcc-verify-prompt { align-items:flex-start;flex-direction:column; }
		.wpcc-verify-prompt .button { width:100%;text-align:center; }
		.wpcc-verify-success ul { display:block; }
		.wpcc-verify-success li + li { margin-top:5px; }
		.wpcc-setup-choice-grid { grid-template-columns:1fr; }
	}

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

	/* ── "What do I do next?" ────────────────────────────────────────────────
	 * A customer who has just created a token has done the hard part and is
	 * holding a string. The thing they need next — the finished configuration
	 * with that token already in it — renders two panels further down, below a
	 * picker they have already used and a token table they have no reason to
	 * read. Nothing pointed at it, so the reliable ending to this flow was
	 * "…now where do I paste this?".
	 *
	 * Two additions, both presentation: a three-word rail that says which step
	 * this is, and one primary control that takes them to the next one. No new
	 * data, no new route, no change to what a token is or does.
	 * ──────────────────────────────────────────────────────────────────────── */
	.wpcc-token-reveal__steps { display:flex; flex-wrap:wrap; gap:6px 16px; margin:0 0 14px; padding:0; list-style:none; }
	.wpcc-token-reveal__steps li { display:inline-flex; align-items:center; gap:6px; font-size:12px; color:#8c92a0; }
	.wpcc-token-reveal__steps .n { display:inline-flex; align-items:center; justify-content:center; width:17px; height:17px;
		border-radius:50%; background:#eef0f4; color:#8c92a0; font-size:10.5px; font-weight:700; flex:0 0 auto; }
	.wpcc-token-reveal__steps .is-done { color:#04620f; }
	.wpcc-token-reveal__steps .is-done .n { background:#e6f6ea; color:#04620f; }
	.wpcc-token-reveal__steps .is-now { color:#1d2327; font-weight:650; }
	.wpcc-token-reveal__steps .is-now .n { background:#2271b1; color:#fff; }
	.wpcc-token-reveal__next { margin:14px 0 0; }

	/* The destination, when it is reached by that button rather than by scrolling.
	 * A page that jumps without saying where it landed is disorienting; the ring
	 * fades out on its own so nothing is left decorated permanently. */
	.wpcc-spotlight { animation: wpcc-spotlight 2.4s ease-out 1; }
	@keyframes wpcc-spotlight {
		0%   { box-shadow: 0 0 0 3px rgba(34,113,177,.45), 0 1px 2px rgba(16,24,40,.04); }
		70%  { box-shadow: 0 0 0 3px rgba(34,113,177,.30), 0 1px 2px rgba(16,24,40,.04); }
		100% { box-shadow: 0 0 0 3px rgba(34,113,177,0),  0 1px 2px rgba(16,24,40,.04); }
	}
	@media (prefers-reduced-motion: reduce) {
		.wpcc-spotlight { animation: none; box-shadow: 0 0 0 3px rgba(34,113,177,.35), 0 1px 2px rgba(16,24,40,.04); }
	}

	/* Token creation dialog.
	   Without JS the panel is simply a form on the page and the opener is hidden;
	   `.wpcc-tokenmake--js` (added by script) inverts that. Nothing about the
	   form's validation depends on either state. */
	.wpcc-tokenmake__opener { display:none; margin:0; }
	.wpcc-tokenmake--js .wpcc-tokenmake__opener { display:block; }
	.wpcc-tokenmake--js .wpcc-tokenmake__panel { display:none; }
	.wpcc-tokenmake--js.is-open .wpcc-tokenmake__panel {
		display:flex; align-items:flex-start; justify-content:center;
		position:fixed; inset:0; z-index:100000; background:rgba(16,24,40,.55);
		padding:8vh 16px 16px; overflow:auto;
	}
	.wpcc-tokenmake__box { max-width:560px; }
	.wpcc-tokenmake--js.is-open .wpcc-tokenmake__box {
		background:#fff; border-radius:12px; padding:24px 26px; width:100%;
		box-shadow:0 12px 40px rgba(16,24,40,.28);
	}
	.wpcc-tokenmake__title { margin:0 0 6px; font-size:16px; font-weight:650; color:#1d2327; }
	.wpcc-tokenmake__lead { margin:0 0 18px; font-size:13px; line-height:1.6; color:#50575e; max-width:64ch; }
	.wpcc-tokenmake__field { margin:0 0 18px; border:0; padding:0; }
	.wpcc-tokenmake__label { display:block; margin:0 0 6px; font-weight:600; font-size:13px; color:#1d2327; }
	.wpcc-tokenmake__hint { margin:6px 0 0; font-size:12px; line-height:1.6; color:#646970; max-width:64ch; }
	.wpcc-tokenmake__dupe { margin:8px 0 0; font-size:12px; line-height:1.6; color:#8a6100;
		background:#fcf9e8; border:1px solid #f0e2a6; border-radius:6px; padding:8px 10px; max-width:64ch; }
	.wpcc-tokenmake__choice { display:flex; gap:10px; align-items:flex-start; padding:10px 12px; margin:0 0 8px;
		border:1px solid #dcdcde; border-radius:8px; cursor:pointer; }
	.wpcc-tokenmake__choice:has(input:checked) { border-color:#2271b1; background:#f6fafd; }
	.wpcc-tokenmake__choice input { margin-top:3px; flex:0 0 auto; }
	.wpcc-tokenmake__choice strong { display:inline; font-size:13px; color:#1d2327; }
	.wpcc-tokenmake__choice em { display:block; margin-top:4px; font-style:normal; font-size:12px; line-height:1.6; color:#646970; }
	.wpcc-tokenmake__rec { display:inline-block; margin-left:8px; font-size:11px; font-weight:600;
		color:#0a7c2f; background:#edfaef; border:1px solid #b8e6c3; border-radius:10px; padding:0 7px; vertical-align:1px; }
	.wpcc-tokenmake__note { margin:0 0 18px; font-size:12px; line-height:1.6; color:#3c434a;
		background:#f0f6fc; border:1px solid #c5d9ed; border-radius:6px; padding:10px 12px; max-width:64ch; }
	.wpcc-tokenmake__note--warn { color:#8a2424; background:#fcf0f0; border-color:#eec2c2; }
	.wpcc-tokenmake__actions { display:flex; gap:10px; align-items:center; margin:0; }
</style>

<div class="wrap wpcc-ai-wrap">
	<?php // The tab that leads here is called "Assistants"; a heading naming the same
	// screen "AI Clients" made the navigation label and the page disagree. ?>
	<h1><?php esc_html_e( 'Assistants', 'action-steward' ); ?></h1>

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
	//
	// It was left behind an `if ( false )` rather than deleted. Dead markup still
	// ships, still gets read as if it were live, and this block in particular
	// carried a hardcoded "Needs your approval" chip that a mode sweep would keep
	// flagging forever. Removed; git history has it if the hero is ever wanted back.
	//
	// Closed by default. This opened expanded, so the first thing on the page a
	// customer met was a FAQ — documentation ahead of the action they came for.
	// It stays one click away for anyone who wants it.
	?>

	<details class="wpcc-agent-explainer">
		<summary><?php esc_html_e( 'New to AI assistants? Read this first (2 min)', 'action-steward' ); ?></summary>
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
					esc_html__( 'Setup: 1) create an %1$saccess token%2$s, 2) paste the configuration below into your assistant. You do not need an AI provider key — your assistant brings its own AI.', 'action-steward' ),
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
	 *
	 * "Pointing at" was a sentence — "your configuration below already includes
	 * it" — which is true and was still not enough. Below is two panels away,
	 * past a picker the customer has already used and a token table they have no
	 * reason to read, and nothing on the screen carried them there. So the card
	 * now ends with the control that does it, and says which step this is, which
	 * between them answer the two questions this moment actually raises: how much
	 * is left, and what do I press.
	 */
	?>
	<?php if ( $wpcc_new_token ) : ?>
		<div class="wpcc-token-reveal" role="status">
			<p class="wpcc-token-reveal__title">
				<?php
				// Name the token that was just made. "Your access token is ready" was
				// true of any token; after a dialog in which the customer chose a name
				// and an access level, the card should confirm what they actually got.
				if ( is_array( $wpcc_new_record ) ) {
					echo esc_html(
						sprintf(
							/* translators: 1: token name chosen by the customer, 2: access level, e.g. "Full access". */
							__( 'Token “%1$s” created — %2$s', 'action-steward' ),
							(string) $wpcc_new_record['label'],
							AuthTokens::scope_label( (string) $wpcc_new_record['scope'] )
						)
					);
				} else {
					esc_html_e( 'Your access token is ready', 'action-steward' );
				}
				?>
			</p>
			<?php
			/*
			 * Where am I? Three steps, and this screen is all three of them, so the
			 * rail is honest rather than aspirational — it is not promising a wizard
			 * that does not exist. The last step names the assistant being connected,
			 * because "paste it into your assistant" is the instruction a first-timer
			 * cannot act on and "paste it into Claude Desktop" is the one they can.
			 */
			$wpcc_reveal_client = (string) ( $wpcc_current_client['name'] ?? '' );
			?>
			<ol class="wpcc-token-reveal__steps">
				<li class="is-done"><span class="n" aria-hidden="true">&#10003;</span><?php esc_html_e( 'Assistant chosen', 'action-steward' ); ?></li>
				<li class="is-done"><span class="n" aria-hidden="true">&#10003;</span><?php esc_html_e( 'Token created', 'action-steward' ); ?></li>
				<li class="is-now"><span class="n" aria-hidden="true">3</span><?php
					echo '' !== $wpcc_reveal_client
						/* translators: %s: the assistant being connected, e.g. "Claude Desktop". */
						? esc_html( sprintf( __( 'Set up %s', 'action-steward' ), $wpcc_reveal_client ) )
						: esc_html__( 'Set up your assistant', 'action-steward' );
				?></li>
			</ol>
			<div class="wpcc-ai-code wpcc-token-reveal__code">
				<code class="wpcc-ai-code__text" id="wpcc-new-token"><?php echo esc_html( $wpcc_new_token ); ?></code>
				<button type="button" class="button button-primary wpcc-copy-btn" data-copy="<?php echo esc_attr( $wpcc_new_token ); ?>"><?php esc_html_e( 'Copy', 'action-steward' ); ?></button>
			</div>
			<p class="wpcc-token-reveal__note">
				<?php esc_html_e( 'Copy and save this token now — it will not be shown again. Your setup steps below already use it where needed. Keep it out of screenshots and shared chats.', 'action-steward' ); ?>
			</p>
			<?php
			/*
			 * The next step, as a control rather than as a direction.
			 *
			 * Rendered only when the destination exists on this response — the
			 * configuration section is gated on a usable token (it is, we just made
			 * one) but a client with no generated config renders the manual panel
			 * instead. The guided steps are the destination when available; otherwise use the config panel, so
			 * the button is offered whenever the configuration tab is what rendered.
			 * Without JS it is still a real in-page link to that section, so nothing
			 * here depends on a script having loaded.
			 */
			?>
			<?php if ( 'configuration' === $wpcc_tab ) : ?>
				<p class="wpcc-token-reveal__next">
					<a class="button button-primary" href="#wpcc-guided-setup" id="wpcc-token-next">
						<?php
						echo '' !== $wpcc_reveal_client
							/* translators: %s: the assistant being connected, e.g. "Claude Desktop". */
							? esc_html( sprintf( __( 'Next: set up %s', 'action-steward' ), $wpcc_reveal_client ) )
							: esc_html__( 'Next: open setup', 'action-steward' );
						?> &rarr;
					</a>
				</p>
			<?php endif; ?>
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
			<div class="wpcc-ai-panel" id="wpcc-choose-app">
			<?php
			/*
			 * "Choose your AI client", not "Choose your assistant".
			 *
			 * The grid does not contain assistants; it contains the APPLICATIONS an
			 * assistant runs in. "ChatGPT" is an assistant and it appears on this screen
			 * twice — once as the desktop app and once as Codex CLI, which are two
			 * programs sharing one configuration file. Someone who says "I use Codex"
			 * may mean either, and asking them to pick an assistant gives them no way to
			 * tell which card is theirs. Asking which app they are connecting does.
			 */
			?>
				<div class="wpcc-ai-panel__header"><?php esc_html_e( '1. Choose your app', 'action-steward' ); ?></div>
				<div class="wpcc-ai-panel__body">
					<p class="wpcc-ai-field__hint" style="margin-top:0;"><?php esc_html_e( 'Pick the app you already use. Desktop apps, terminal apps, and editor extensions have different setup steps.', 'action-steward' ); ?></p>
					<?php
					// Mark the final small family so a two-column layout has no empty grid cell.
					$wpcc_last_compact_family = '';
					foreach ( array_keys( $wpcc_client_groups ) as $wpcc_group_name ) {
						if ( 'Editors & coding assistants' !== $wpcc_group_name && 'Other / Experimental' !== $wpcc_group_name ) {
							$wpcc_last_compact_family = $wpcc_group_name;
						}
					}
					?>
					<div class="wpcc-ai-family-layout">
					<div class="wpcc-ai-family-grid">
					<?php foreach ( $wpcc_client_groups as $wpcc_family => $wpcc_family_clients ) : ?>
						<?php $wpcc_is_other = 'Other / Experimental' === $wpcc_family; ?>
						<?php $wpcc_is_wide = 'Editors & coding assistants' === $wpcc_family; ?>
						<?php if ( $wpcc_is_other ) : ?>
						<details class="wpcc-ai-family wpcc-ai-family--other"<?php echo isset( $wpcc_family_clients[ $wpcc_selected_client ] ) ? ' open' : ''; ?>>
							<summary><?php esc_html_e( 'Other / Experimental', 'action-steward' ); ?></summary>
						<?php else : ?>
						<section class="wpcc-ai-family <?php echo $wpcc_is_wide ? 'wpcc-ai-family--wide' : 'wpcc-ai-family--compact'; ?><?php echo $wpcc_family === $wpcc_last_compact_family ? ' wpcc-ai-family--compact-last' : ''; ?>" aria-labelledby="wpcc-family-<?php echo esc_attr( sanitize_key( $wpcc_family ) ); ?>">
							<h3 class="wpcc-ai-family__name" id="wpcc-family-<?php echo esc_attr( sanitize_key( $wpcc_family ) ); ?>"><?php echo esc_html( $wpcc_family ); ?></h3>
						<?php endif; ?>
						<div class="wpcc-ai-picks">
						<?php foreach ( $wpcc_family_clients as $id => $client ) : ?>
							<a href="<?php echo esc_url( add_query_arg( [ 'tab' => 'configuration', 'client' => $id, 'wpcc_next' => 'access' ], admin_url( 'admin.php?page=wpcc-settings&wpcc_tab=connections&cpane=assistants' ) ) ); ?>"
							   class="button wpcc-ai-pick<?php echo $id === $wpcc_selected_client ? ' is-selected' : ''; ?>"
						   <?php echo $id === $wpcc_selected_client ? 'aria-current="page"' : ''; ?>>
								<span class="wpcc-ai-pick__name"><?php echo esc_html( $client['name'] ); ?></span>
								<span class="wpcc-ai-pick__surface"><?php echo esc_html( $client['surface'] ?? '' ); ?></span>
								<?php $wpcc_pick_badge = AIClientRegistry::selector_badge_for( $id ); ?>
								<?php if ( $wpcc_pick_badge ) : ?>
									<span class="wpcc-ai-badge wpcc-ai-badge--secondary wpcc-ai-badge--<?php echo esc_attr( $wpcc_pick_badge['tone'] ); ?>"><?php echo esc_html( $wpcc_pick_badge['label'] ); ?></span>
								<?php endif; ?>
							</a>
						<?php endforeach; ?>
						</div>
						<?php echo $wpcc_is_other ? '</details>' : '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed local tags. ?>
					<?php endforeach; ?>
					</div>
					</div>
			</div>
		</div>

		<?php
		$wpcc_cfg_mcp_url = rest_url( \WPCommandCenter\Mcp\McpServerRuntime::NAMESPACE . '/mcp' );

		/*
		 * "Ready" means usable, not merely on record.
		 *
		 * This counted every row in the manifest, so a site with one working token
		 * and three revoked or expired ones was told "4 access tokens ready" — and
		 * the same number gates the configuration and connection-test cards below,
		 * so a site whose only token had expired was shown a setup flow it could not
		 * complete. AuthTokens::is_usable() is the same rule validate() enforces at
		 * request time and the same one the status badge prints.
		 */
		$wpcc_usable_tokens = AuthTokens::usable_only( is_array( $wpcc_all_tokens ) ? $wpcc_all_tokens : [] );
		$wpcc_cfg_tok_count = count( $wpcc_usable_tokens );
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
		<div class="wpcc-ai-panel" id="wpcc-create-access">
			<div class="wpcc-ai-panel__header" id="wpcc-create-access-title" tabindex="-1">
				<?php
				printf(
					/* translators: %s: selected assistant application name. */
					esc_html__( '2. Create access for %s', 'action-steward' ),
					esc_html( $wpcc_current_client['name'] )
				);
				?>
			</div>
			<div class="wpcc-ai-panel__body">
				<p class="wpcc-ai-field__hint wpcc-create-access__lead"><?php esc_html_e( 'This token lets this app talk to this WordPress site. Read-only can inspect the site; Full access can also request or make changes under your protection settings.', 'action-steward' ); ?></p>
				<?php if ( in_array( $wpcc_selected_client, [ 'chatgpt', 'codex' ], true ) && ! empty( $wpcc_current_client['surface_note'] ) ) : ?>
					<p class="wpcc-selected-client-note">
						<strong><?php esc_html_e( 'Important', 'action-steward' ); ?></strong>
						<span><?php echo esc_html( $wpcc_current_client['surface_note'] ); ?></span>
					</p>
				<?php endif; ?>
				<?php
				// Read-only is the safe default. The shared action policy admits site
				// inspection while refusing mutations and approval submissions.
				$wpcc_protected  = SecurityModeManager::is_protected();
				$wpcc_mode_label = SecurityModeManager::label();
				// Suggest the assistant being connected as the name — the thing the
				// customer will actually want to recognise in the list later. Still
				// required, still editable: a suggestion, not an auto-generated label.
				$wpcc_label_hint = (string) ( $wpcc_current_client['name'] ?? '' );
				?>
				<form method="post" class="wpcc-tokenmake wpcc-create-access__form" id="wpcc-tokenmake">
					<?php wp_nonce_field( 'wpcc_ai_integrations' ); ?>

					<?php // Shown only once JS has turned the panel below into a dialog. ?>
					<p class="wpcc-tokenmake__opener">
						<button type="button" class="button button-primary" id="wpcc-tokenmake-open" aria-haspopup="dialog">
							<?php esc_html_e( 'Create access token', 'action-steward' ); ?>
						</button>
					</p>

					<div class="wpcc-tokenmake__panel" id="wpcc-tokenmake-panel" role="dialog" aria-modal="true" aria-labelledby="wpcc-tokenmake-title">
						<div class="wpcc-tokenmake__box">
							<h2 class="wpcc-tokenmake__title" id="wpcc-tokenmake-title"><?php esc_html_e( 'Create an access token', 'action-steward' ); ?></h2>
							<p class="wpcc-tokenmake__lead">
								<?php esc_html_e( 'This creates one key for one assistant. You will see it once, and you can revoke it at any time.', 'action-steward' ); ?>
							</p>

							<div class="wpcc-tokenmake__field">
								<label class="wpcc-tokenmake__label" for="wpcc-tokenmake-label">
									<?php esc_html_e( 'Name this token', 'action-steward' ); ?>
								</label>
								<input type="text" id="wpcc-tokenmake-label" name="wpcc_token_label" class="regular-text"
									required maxlength="120" autocomplete="off" spellcheck="false"
									value="<?php echo esc_attr( $wpcc_label_hint ); ?>"
									aria-describedby="wpcc-tokenmake-label-hint" />
								<p class="wpcc-tokenmake__hint" id="wpcc-tokenmake-label-hint">
									<?php esc_html_e( 'Use a name you will recognise months from now — usually the assistant you are connecting, or the person using it.', 'action-steward' ); ?>
								</p>
								<p class="wpcc-tokenmake__dupe" id="wpcc-tokenmake-dupe" role="status" hidden></p>
							</div>

							<?php
							/*
							 * Read-only is the default on BOTH token forms. Full access is
							 * never preselected anywhere in the product, and is reached only
							 * by the customer choosing it.
							 *
							 * The owner-selected Read-only default now supports ordinary site
							 * questions through the audited action-level scope contract.
							 */
							?>
							<fieldset class="wpcc-tokenmake__field wpcc-tokenmake__scopes">
								<legend class="wpcc-tokenmake__label"><?php esc_html_e( 'What this assistant may do', 'action-steward' ); ?></legend>

								<label class="wpcc-tokenmake__choice">
									<input type="radio" name="wpcc_token_scope" value="read_only" checked />
									<span>
										<strong><?php esc_html_e( 'Read-only', 'action-steward' ); ?></strong>
										<em><?php esc_html_e( 'Read site information, diagnostics, and supported list/get actions. Cannot change data, submit changes for approval, or approve them.', 'action-steward' ); ?></em>
									</span>
								</label>

								<label class="wpcc-tokenmake__choice">
									<input type="radio" name="wpcc_token_scope" value="full" />
									<span>
										<strong><?php esc_html_e( 'Full access', 'action-steward' ); ?></strong>
										<em><?php esc_html_e( 'May request or perform changes according to the active protection mode. Choose this if you want the assistant to work across the whole site.', 'action-steward' ); ?></em>
									</span>
								</label>
							</fieldset>

							<div class="wpcc-tokenmake__field">
								<label class="wpcc-tokenmake__label" for="wpcc-tokenmake-expires">
									<?php esc_html_e( 'Stop working after', 'action-steward' ); ?>
								</label>
								<?php
								// 30 days is the default. A key that expires on its own is
								// the difference between "I forgot to revoke that" being a
								// note-to-self and being a permanent hole. Never stays on the
								// list — some connections genuinely are permanent — but it is
								// now a choice someone makes rather than one they inherit.
								?>
								<select id="wpcc-tokenmake-expires" name="wpcc_token_expires">
									<option value="30d" selected><?php esc_html_e( '30 days (recommended)', 'action-steward' ); ?></option>
									<option value="90d"><?php esc_html_e( '90 days', 'action-steward' ); ?></option>
									<option value="1y"><?php esc_html_e( '1 year', 'action-steward' ); ?></option>
									<option value="never"><?php esc_html_e( 'Never — until I revoke it', 'action-steward' ); ?></option>
								</select>
								<p class="wpcc-tokenmake__hint">
									<?php esc_html_e( 'An expiring token stops working on its own. Pick one if this is for a temporary job or someone else’s computer.', 'action-steward' ); ?>
								</p>
							</div>

							<?php
							/*
							 * The Full-access consequence note. Two rules:
							 *
							 *  - It appears only when Full access is SELECTED. Now that
							 *    read-only is the default, a warning shown on open would be
							 *    warning about a scope the customer has not chosen — and a
							 *    notice that is always on screen is wallpaper by the second
							 *    time it is seen.
							 *  - It has to be TRUE rather than reassuring. On Standard and
							 *    Strict protection a full-access token cannot bypass
							 *    required human approval; Standard still allows low-risk work. In Developer mode
							 *    it can, immediately — so that is the one case that gets a
							 *    warning instead of comfort.
							 */
							?>
							<?php
							/*
							 * Full access AND never expires is the one combination worth
							 * calling out on its own. Either alone is a reasonable choice;
							 * together they mint a credential that can do anything, for as
							 * long as the site exists, that nobody will be reminded about.
							 * Shown only when both are selected, so it stays a signal.
							 */
							?>
							<p class="wpcc-tokenmake__note wpcc-tokenmake__note--warn" id="wpcc-tokenmake-longlived" hidden>
								<?php esc_html_e( 'Heads up: full access that never expires is a permanent key to everything on this site. If it is ever copied or leaked there is no expiry to fall back on — you would have to notice and revoke it. Pick an expiry unless you have a reason not to.', 'action-steward' ); ?>
							</p>

							<p class="wpcc-tokenmake__note<?php echo esc_attr( $wpcc_protected ? '' : ' wpcc-tokenmake__note--warn' ); ?>" id="wpcc-tokenmake-note" data-protected="<?php echo esc_attr( $wpcc_protected ? '1' : '0' ); ?>" hidden>
								<?php if ( $wpcc_protected ) : ?>
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: the site's protection mode, e.g. "Standard protection". */
											__( 'Full access lets this token ask to change anything on the site. Requests follow %s; full access does not bypass required human approval.', 'action-steward' ),
											$wpcc_mode_label
										)
									);
									?>
								<?php else : ?>
									<?php esc_html_e( 'Warning: this site is in Developer mode, so a full-access token can change your site straight away, without asking you first. Change this under Settings › Protection.', 'action-steward' ); ?>
								<?php endif; ?>
							</p>

							<?php
							// The action travels as a hidden field, not on the button.
							// A disabled submit button contributes no name/value pair, so
							// carrying the action on the button and disabling it against
							// double-clicks would have submitted a form with no action at
							// all. It also means a stray Enter keypress can only ever mean
							// "create the token I have filled in", never a scope the
							// customer did not pick.
							?>
							<input type="hidden" name="wpcc_token_action" value="create" />

							<p class="wpcc-tokenmake__actions">
								<button type="submit" class="button button-primary" id="wpcc-tokenmake-submit">
									<?php esc_html_e( 'Create token', 'action-steward' ); ?>
								</button>
								<button type="button" class="button" id="wpcc-tokenmake-cancel"><?php esc_html_e( 'Cancel', 'action-steward' ); ?></button>
							</p>
						</div>
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
				// `expires_at` — so filtering on status alone offered expired tokens as
				// "Use in config", where they would silently fail against the assistant.
				// $wpcc_usable_tokens is resolved once, above, from the canonical rule in
				// AuthTokens::is_usable(); this table and the "N access tokens ready"
				// count must never be able to disagree about what a usable token is.
				?>
				<?php if ( ! empty( $wpcc_usable_tokens ) ) : ?>
					<details class="wpcc-token-list">
						<summary>
							<?php
							/* translators: %d: number of usable access tokens. */
							printf( esc_html( _n( 'Use or manage %d existing token', 'Use or manage %d existing tokens', count( $wpcc_usable_tokens ), 'action-steward' ) ), count( $wpcc_usable_tokens ) );
							?>
						</summary>
					<table class="wpcc-ai-token-table">
						<thead><tr><th><?php esc_html_e( 'Label', 'action-steward' ); ?></th><th><?php esc_html_e( 'Scope', 'action-steward' ); ?></th><th><?php esc_html_e( 'Status', 'action-steward' ); ?></th><th></th></tr></thead>
						<tbody>
						<?php foreach ( $wpcc_usable_tokens as $t ) : ?>
							<tr>
								<td><?php echo esc_html( $t['label'] ); ?><br><small style="color:#646970"><?php echo esc_html( $t['token_preview'] ); ?>...</small></td>
								<td><?php echo esc_html( AuthTokens::scope_label( $t['scope'] ) ); ?></td>
								<td><?php echo AuthTokens::status_badge( $t ); // phpcs:ignore ?></td>
								<td style="white-space:nowrap;">
								<button type="button" class="button button-small wpcc-select-token-btn" data-token-id="<?php echo esc_attr( $t['id'] ); ?>"><?php esc_html_e( 'Use in config', 'action-steward' ); ?></button>
								<?php if ( 'active' === ( $t['status'] ?? '' ) ) : ?>
									<form method="post" style="display:inline;" onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Revoke this token? Any assistant using it loses access immediately.', 'action-steward' ) ) ); ?>);">
										<?php wp_nonce_field( 'wpcc_ai_integrations' ); ?>
										<input type="hidden" name="wpcc_token_id" value="<?php echo esc_attr( $t['id'] ); ?>" />
										<button type="submit" name="wpcc_token_action" value="revoke" class="button button-small button-link-delete"><?php esc_html_e( 'Revoke', 'action-steward' ); ?></button>
									</form>
								<?php endif; ?>
							</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<p class="wpcc-ai-panel__hint"><?php esc_html_e( 'A token is shown in full only once, when you create it. Manage or revoke tokens anytime in Settings → Connections.', 'action-steward' ); ?></p>
					</details>
				<?php else : ?>
					<p class="wpcc-create-access__state"><?php esc_html_e( 'No active tokens yet.', 'action-steward' ); ?></p>
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
		<?php
		/*
		 * ── Guided setup, for clients that offer something better than a text file ──
		 *
		 * This panel exists because of a real failure. WPCC generated a valid-looking
		 * Codex/ChatGPT configuration, the user followed it exactly, and the connection
		 * returned 401 from a server that was in perfect health — 42 tools, valid token,
		 * everything. Two separate causes, both invisible from the screen:
		 *
		 *   1. The generated TOML said `bearer_token = "${WPCC_TOKEN}"`. TOML has no
		 *      string interpolation and Codex performs none, so the literal characters
		 *      `${WPCC_TOKEN}` were sent as the bearer token. (Fixed in
		 *      CodexIntegration; the config now names an environment variable.)
		 *
		 *   2. The client's own field is labelled "Bearer token env var" and wants the
		 *      NAME of an environment variable. Pasting the token there — the obvious
		 *      reading — makes the client look up a variable named `wpcc_jkSf...`, find
		 *      nothing, and send no credential at all.
		 *
		 * Neither is discoverable by staring at a config blob, so for these clients the
		 * blob is no longer the primary instruction. The steps are, in the order they
		 * have to happen, with the native one-command registration first because it
		 * cannot be pasted into the wrong file and cannot be malformed.
		 */
		$wpcc_setup_template = AIClientRegistry::setup_command_for( $wpcc_selected_client );
		// Command Code's inline credential is rendered server-side only on the creation
		// response that owns the raw token. On reload the executable command stays hidden
		// until the customer supplies a saved token in the browser-only field.
		$wpcc_setup_cmd = 'command_code' === $wpcc_selected_client && $wpcc_new_token
			? AIClientRegistry::setup_command_for( $wpcc_selected_client, $wpcc_new_token )
			: $wpcc_setup_template;
		// Only this creation response owns the raw token. Generate escaped commands
		// here; never rely on browser placeholder replacement for credentials.
		$wpcc_cred_cmds   = $wpcc_new_token
			? AIClientRegistry::credential_commands_for( $wpcc_selected_client, $wpcc_new_token )
			: [];
		$wpcc_setup_kind  = (string) ( $wpcc_current_client['setup_kind'] ?? 'file' );
		$wpcc_setup_credential_class = AIClientRegistry::setup_credential_class_for( $wpcc_selected_client );
		$wpcc_setup_requires_token   = 'raw_token' === $wpcc_setup_credential_class;
		$wpcc_token_ready            = is_string( $wpcc_new_token ) && '' !== $wpcc_new_token;
		$wpcc_prepare_config_cmd      = AIClientRegistry::prepare_config_command_for( $wpcc_selected_client );
		// Prompt-based clients cache secrets by input id. Tie that id to the selected
		// token record so replacing/revoking a token cannot silently reuse an old value.
		$wpcc_credential_id = is_array( $wpcc_new_record )
			? (string) ( $wpcc_new_record['id'] ?? '' )
			: (string) ( $wpcc_selected_token['id'] ?? '' );
		$wpcc_primary_config = AIClientRegistry::primary_config_for( $wpcc_selected_client, (string) $wpcc_new_token, $wpcc_credential_id );
		if ( '' === $wpcc_primary_config && in_array( $wpcc_setup_kind, [ 'file', 'vscode_config' ], true ) ) {
			$wpcc_primary_config = AIClientRegistry::render_config( $wpcc_config, (string) $wpcc_new_token );
		}
		if ( 'vscode' === $wpcc_selected_client && '' !== $wpcc_primary_config ) {
			// Keep Recommended and Advanced on the same token-scoped input id.
			$wpcc_config_json = $wpcc_primary_config;
		}
		// Every supported client now has one recommended path; raw/manual alternatives
		// are presented separately under Advanced.
		$wpcc_has_guided  = true;
		$wpcc_command_code_manual = 'command_code' === $wpcc_selected_client;
		$wpcc_os_labels   = [
			'macos'   => __( 'macOS', 'action-steward' ),
			'windows' => __( 'Windows', 'action-steward' ),
			'linux'   => __( 'Linux', 'action-steward' ),
		];
		?>
		<?php if ( $wpcc_cfg_tok_count > 0 && $wpcc_has_guided ) : ?>
			<div class="wpcc-ai-panel" id="wpcc-guided-setup">
				<div class="wpcc-ai-panel__header">
					<?php printf( /* translators: %s: client name */ esc_html__( '3. Connect %s', 'action-steward' ), esc_html( $wpcc_current_client['name'] ) ); ?>
				</div>
				<div class="wpcc-ai-panel__body">
					<?php require __DIR__ . '/partials/assistant-connect.php'; ?>
				</div>
			</div>

			<div class="wpcc-ai-panel" id="wpcc-verify-client">
				<div class="wpcc-ai-panel__header"><?php esc_html_e( '4. Test your connection', 'action-steward' ); ?></div>
				<div class="wpcc-ai-panel__body">
					<?php require __DIR__ . '/partials/assistant-verify.php'; ?>
				</div>
			</div>
		<?php endif; ?>

		<!-- Setup card: your configuration. Whole section waits for a token. -->
		<?php if ( $wpcc_cfg_tok_count > 0 ) : ?>
		<?php if ( $wpcc_config ) : ?>
			<?php // Named target for the reveal card's "Next" control, and for #wpcc-config-panel deep links. ?>
			<details class="wpcc-ai-advanced" id="wpcc-config-panel">
				<summary>
					<?php
					/* translators: %s: selected assistant or coding client name. */
					printf( esc_html__( 'Advanced: manual configuration for %s', 'action-steward' ), esc_html( $wpcc_current_client['name'] ) );
					?>
				</summary>
				<div class="wpcc-ai-panel" style="margin:0;border:0;border-top:1px solid #eef0f4;border-radius:0;box-shadow:none;">
				<div class="wpcc-ai-panel__header">
					<?php
					/*
					 * Demoted, not hidden, when a native command exists.
					 *
					 * A hand-edited file is the slowest and most error-prone way to
					 * configure any client that will register itself in one command, and
					 * leading with it is what sent people to the wrong field in the first
					 * place. It stays available — some people would rather see the file,
					 * and some environments make running a command awkward — but it no
					 * longer presents itself as the way to do this.
					 */
					if ( $wpcc_has_guided ) {
						printf( /* translators: %s: client name */ esc_html__( 'Manual setup for %s (advanced)', 'action-steward' ), esc_html( $wpcc_current_client['name'] ) );
					} else {
						printf( /* translators: %s: value */ esc_html__( 'Your %s configuration', 'action-steward' ), esc_html( $wpcc_current_client['name'] ) );
					}
					?>
					<button type="button" class="button wpcc-copy-btn" id="wpcc-copy-config" data-copy-target="wpcc-config-block"<?php $wpcc_token_gate_control( $wpcc_setup_requires_token, $wpcc_token_ready ); ?>>
						<?php
						echo 'gemini' === $wpcc_selected_client || $wpcc_command_code_manual
							? esc_html__( 'Copy empty-file example', 'action-steward' )
							: esc_html__( 'Copy configuration', 'action-steward' );
						?>
					</button>
					<span class="wpcc-ai-copied" id="wpcc-copy-feedback">&#10003; <?php esc_html_e( 'Copied!', 'action-steward' ); ?></span>
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
								<span class="wpcc-ai-summary__k"><?php esc_html_e( 'Connects via', 'action-steward' ); ?></span>
								<span class="wpcc-ai-summary__v"><?php echo $wpcc_sel_http ? esc_html__( 'Direct HTTP', 'action-steward' ) : esc_html__( 'Connector script', 'action-steward' ); ?></span>
							</span>
							<span class="wpcc-ai-summary__item">
								<span class="wpcc-ai-summary__k"><?php esc_html_e( 'Node.js', 'action-steward' ); ?></span>
								<span class="wpcc-ai-summary__v<?php echo $wpcc_sel_http ? '' : ' wpcc-ai-summary__v--muted'; ?>"><?php echo $wpcc_sel_http ? esc_html__( 'Not required', 'action-steward' ) : esc_html__( 'Required', 'action-steward' ); ?></span>
							</span>
							<?php foreach ( $wpcc_sel_badges as $wpcc_b ) : ?>
								<?php if ( 'secondary' === $wpcc_b['rank'] && ( 'info' === $wpcc_b['tone'] || 'neutral' === $wpcc_b['tone'] ) ) { continue; } // transport is already spelled out above ?>
								<span class="wpcc-ai-summary__item">
									<span class="wpcc-ai-summary__k"><?php echo 'primary' === $wpcc_b['rank'] && 'rec' === $wpcc_b['tone'] ? esc_html__( 'Status', 'action-steward' ) : esc_html__( 'Certification', 'action-steward' ); ?></span>
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
						 *
						 * Third case, and the one that was actively wrong: an env_var
						 * client's configuration contains NO token and must not. Telling
						 * that user to "put your access token in place of ${WPCC_TOKEN}"
						 * describes the exact edit that produces a 401 — the client would
						 * then treat the token as the name of an environment variable.
						 */
						if ( $wpcc_sel_uses_env ) {
							printf(
								/* translators: 1: client name, 2: environment variable name */
								esc_html__( 'This is the file %1$s reads. There is deliberately no token in it — it names the %2$s environment variable instead, and the token goes there. Setting it up with the command above does all of this for you.', 'action-steward' ),
								esc_html( $wpcc_current_client['name'] ),
								esc_html( $wpcc_sel_env_var )
							);
						} elseif ( 'prompt' === $wpcc_sel_cred_mode ) {
							printf(
								/* translators: %s: client name */
								esc_html__( 'Copy this into %s. It is complete as it stands — there is no token in it, and none needs to be added: it asks for your token the first time it connects and keeps it in its own secure storage. That also makes this file safe to commit to a repository.', 'action-steward' ),
								esc_html( $wpcc_current_client['name'] )
							);
						} elseif ( 'gemini' === $wpcc_selected_client ) {
							esc_html_e( 'This is the advanced fallback. The native gemini mcp add command above is recommended because it preserves your existing Gemini settings. If you continue manually, fill in the token and follow the merge instructions below — do not replace settings.json.', 'action-steward' );
						} elseif ( $wpcc_command_code_manual ) {
							esc_html_e( 'This is the advanced fallback. The native cmd mcp add command above is recommended. If you deliberately edit ~/.commandcode/mcp.json instead, fill in the token and merge only the Action Steward server entry without replacing the file or its existing servers.', 'action-steward' );
						} else {
							echo $wpcc_new_token
								? sprintf( /* translators: %s: value */ esc_html__( 'Copy this and paste it into %s. It is complete — your connection address and your access token are both in it.', 'action-steward' ), esc_html( $wpcc_current_client['name'] ) )
								: esc_html__( 'Paste your saved token in the browser-only field below to unlock a complete configuration. If you did not save it, create a new access token. Action Steward never reconstructs a stored token.', 'action-steward' );
						}
					?></p>
					<?php if ( ! empty( $wpcc_selected_token ) ) : ?>
						<div class="notice inline notice-info" style="margin:0 0 12px;padding:10px 12px;">
							<p style="margin:0;">
								<?php
								printf(
									/* translators: 1: token label, 2: token preview prefix */
									esc_html__( 'Using “%1$s” (starts with %2$s…). Paste that saved token in the field below to drop it straight into the configuration. For security, a token is shown in full only once — if you didn’t save it, create a new token below.', 'action-steward' ),
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
							printf( esc_html( _n( '%d access token ready.', '%d access tokens ready.', $wpcc_cfg_tok_count, 'action-steward' ) ), (int) $wpcc_cfg_tok_count );
						?></p>
					<?php else : ?>
						<p class="wpcc-ai-field__status" style="margin:0 0 12px;"><?php esc_html_e( 'No access token yet — create one in “Access tokens” above, then it appears in this configuration.', 'action-steward' ); ?></p>
					<?php endif; ?>
				</div>
				<div class="wpcc-ai-panel__body" style="padding:0;">
					<?php
					/*
					 * Only one #wpcc-token-fill may exist. When the guided panel above is
					 * showing, it owns the field; duplicating the id here would leave the
					 * fill script bound to whichever the DOM happened to return first.
					 *
						 * 'prompt' clients get no field at all. VS Code's configuration
						 * references a token-scoped `${input:...}` value and VS Code asks
					 * itself, so there is no placeholder to substitute — offering a box
					 * labelled "paste your token to complete the configuration" would be
					 * asking for something the configuration does not want and cannot use.
					 */
					if ( ! $wpcc_has_guided && 'prompt' !== $wpcc_sel_cred_mode ) :
						?>
					<div class="wpcc-ai-field" style="padding:14px 22px 0;margin:0;">
							<label class="wpcc-ai-field__label" for="wpcc-token-fill"><?php
								if ( $wpcc_sel_uses_env ) {
									// For an env_var client this field completes the setup
									// COMMANDS above, not the configuration below — the
									// configuration has no token slot to complete.
									echo $wpcc_new_token
										? esc_html__( 'Access token (already filled into the setup steps above)', 'action-steward' )
										: esc_html__( 'Paste your access token to complete the setup steps above', 'action-steward' );
								} else {
									echo $wpcc_new_token
										? esc_html__( 'Access token (already filled in below)', 'action-steward' )
										: esc_html__( 'Paste your access token to complete the configuration', 'action-steward' );
								}
							?></label>
							<input type="text" id="wpcc-token-fill" class="regular-text" placeholder="wpcc_..." autocomplete="off" spellcheck="false" style="width:100%;max-width:520px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;" value="<?php echo esc_attr( $wpcc_new_token ); ?>">
							<p class="wpcc-ai-field__hint" style="margin:6px 0 0;"><?php
								echo $wpcc_new_token
									? esc_html__( 'Nothing more to fill in — the configuration below is ready to copy. Your token stays in this browser; it is never sent back to the server.', 'action-steward' )
									: esc_html__( 'Your token is inserted into the configuration below right here in your browser — it is never sent back to the server or stored. Then click “Copy configuration” to copy the complete, ready-to-use config.', 'action-steward' );
							?></p>
						</div>
						<?php endif; // guided panel owns the token field ?>
						<?php if ( $wpcc_sel_uses_env ) : ?>
							<p class="wpcc-ai-field__hint"><?php esc_html_e( 'Manual setup replaces only Step 2. Complete Step 1 above, add this block to ~/.codex/config.toml without replacing existing settings, then follow Step 3 for your selected client. Keep bearer_token_env_var = "WPCC_TOKEN": WPCC_TOKEN is the variable name, never replace it with your token.', 'action-steward' ); ?></p>
						<?php endif; ?>
						<?php
						$wpcc_vscode_input_snippet = '';
						if ( 'vscode' === $wpcc_selected_client ) {
							$wpcc_vscode_config = json_decode( $wpcc_primary_config, true );
							$wpcc_vscode_server = is_array( $wpcc_vscode_config ) ? ( $wpcc_vscode_config['servers']['wp-command-center'] ?? null ) : null;
							$wpcc_vscode_input  = is_array( $wpcc_vscode_config ) ? ( $wpcc_vscode_config['inputs'][0] ?? null ) : null;
							$wpcc_entry_snippet = is_array( $wpcc_vscode_server )
								? '"wp-command-center": ' . (string) wp_json_encode( $wpcc_vscode_server, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
								: '';
							$wpcc_vscode_input_snippet = is_array( $wpcc_vscode_input )
								? (string) wp_json_encode( $wpcc_vscode_input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
								: '';
						} else {
							$wpcc_entry_snippet = AIClientRegistry::config_file_is_shared( $wpcc_selected_client )
								? AIClientRegistry::render_entry_config( $wpcc_selected_client )
								: '';
						}
						$wpcc_gemini_manual = 'gemini' === $wpcc_selected_client && '' !== $wpcc_entry_snippet;
						?>
						<?php if ( $wpcc_gemini_manual ) : ?>
							<div class="notice inline notice-warning" style="margin:0 22px 14px;padding:10px 12px;">
								<p style="margin:0 0 6px;"><strong><?php esc_html_e( 'Merge this server into your existing Gemini settings. Do not replace the whole settings.json file.', 'action-steward' ); ?></strong></p>
								<p style="margin:0;"><?php esc_html_e( 'Add only the “wp-command-center” entry inside the existing “mcpServers” object. Keep every other server and every unrelated top-level setting, including any IDE, security, UI or account settings. If “mcpServers” does not exist, add that object without removing the other settings.', 'action-steward' ); ?></p>
							</div>
							<span class="wpcc-ai-field__label" style="display:block;padding:0 22px;"><?php esc_html_e( 'Empty-file example', 'action-steward' ); ?></span>
						<?php elseif ( $wpcc_command_code_manual ) : ?>
							<span class="wpcc-ai-field__label" style="display:block;padding:0 22px;"><?php esc_html_e( 'Empty-file example for ~/.commandcode/mcp.json', 'action-steward' ); ?></span>
						<?php endif; ?>
						<p class="wpcc-ai-field__hint" data-wpcc-token-needed<?php echo $wpcc_token_ready || ! $wpcc_setup_requires_token ? ' hidden' : ''; ?>><?php esc_html_e( 'Create an access token first, or paste a saved token above, before copying this setup.', 'action-steward' ); ?></p>
						<pre class="wpcc-ai-config" id="wpcc-config-block"<?php echo $wpcc_setup_requires_token ? ' data-wpcc-token-slot data-wpcc-requires-token-payload' : ''; ?><?php echo $wpcc_setup_requires_token && ! $wpcc_token_ready ? ' hidden' : ''; ?>><?php echo esc_html( $wpcc_config_json ); ?></pre>
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
							if ( 'gemini' === $wpcc_selected_client ) {
								esc_html_e( 'What this does: Gemini CLI connects straight to this site using the address and bearer token above. Nothing is installed or left running. To disconnect a manual setup, remove only the “wp-command-center” entry and keep every other Gemini setting.', 'action-steward' );
							} elseif ( $wpcc_command_code_manual ) {
								esc_html_e( 'What this does: Command Code connects straight to this site using the address and bearer token above. Nothing is installed or left running. To disconnect a manual setup, remove only the “wp-command-center” entry and keep every other Command Code MCP server.', 'action-steward' );
							} elseif ( $wpcc_sel_http ) {
								esc_html_e( 'What this does: your assistant connects straight to this site over the web using the address and token above. Nothing is installed or run on your computer, and no other service is involved. Remove the configuration and the connection is gone.', 'action-steward' );
							} else {
								esc_html_e( 'What this does: your assistant runs a small connector script on your computer, downloaded from this site, which passes requests to WordPress. It runs locally under your own account, sends nothing anywhere except to this site, and can be removed by deleting the configuration. The connector is part of this plugin and is served from your own domain.', 'action-steward' );
							}
							?>
						</p>
						<?php
						/*
						 * MERGE, do not replace — stated as two concrete cases.
						 *
						 * These configuration files are not Action Steward's to own. A Gemini CLI
						 * settings.json holds authentication, IDE and UI preferences; a
						 * Cursor mcp.json holds every other MCP server the developer uses.
						 * "Paste this into <file>" is ambiguous between adding to it and
						 * replacing it, and one of those readings silently destroys the
						 * user's existing setup. In real testing (REAL_TEST_FINDINGS.md
						 * #4) a tester's ~/.cursor/mcp.json already contained another
						 * server, and hand-merging this block produced a JSON syntax error
						 * before it produced a working file.
						 *
						 * So the ambiguity is removed rather than warned about: the block
						 * above is for an empty file, and the fragment below is for a file
						 * that already has servers in it. The second one needs no merging
						 * reasoning at all — it IS the thing that goes inside mcpServers.
						 *
						 * Shown for hand-configured clients and for any advanced fallback that
						 * still edits a shared file despite having a safer native command.
						 */
						?>
						<?php if ( '' !== $wpcc_entry_snippet && ! $wpcc_gemini_manual ) : ?>
							<div class="notice inline notice-warning" style="margin:0 22px 14px;padding:10px 12px;">
								<p style="margin:0 0 6px;">
									<strong><?php esc_html_e( 'If this file already exists, do not replace it.', 'action-steward' ); ?></strong>
								</p>
								<p style="margin:0;">
									<?php echo 'vscode' === $wpcc_selected_client
										? esc_html__( 'The block above is only for an empty file. In an existing VS Code user mcp.json, add the server entry inside “servers” and add the token-input item inside “inputs”. Keep every other server and input.', 'action-steward' )
										: esc_html__( 'The block above is the whole file, and is only right when you are creating it for the first time. If you already have this file — for example because you use other MCP servers — it will contain settings that replacing it would delete. Add just this entry inside the “mcpServers” braces you already have, alongside anything else in there:', 'action-steward' ); ?>
								</p>
							</div>
						<?php endif; ?>
						<?php if ( '' !== $wpcc_entry_snippet ) : ?>
							<div class="wpcc-ai-field" style="padding:0 22px 14px;margin:0;">
								<span class="wpcc-ai-field__label"><?php
									echo 'vscode' === $wpcc_selected_client
										? esc_html__( 'Add inside servers', 'action-steward' )
										: ( $wpcc_gemini_manual
										? esc_html__( 'Entry to merge into mcpServers', 'action-steward' )
										: esc_html__( 'Add to an existing file', 'action-steward' ) );
								?></span>
								<pre class="wpcc-ai-config" id="wpcc-entry-block" data-wpcc-token-slot<?php echo $wpcc_setup_requires_token ? ' data-wpcc-requires-token-payload' : ''; ?><?php echo $wpcc_setup_requires_token && ! $wpcc_token_ready ? ' hidden' : ''; ?>><?php echo esc_html( $wpcc_entry_snippet ); ?></pre>
								<button type="button" class="button wpcc-copy-btn" data-copy-target="wpcc-entry-block"<?php $wpcc_token_gate_control( $wpcc_setup_requires_token, $wpcc_token_ready ); ?>><?php esc_html_e( 'Copy this entry only', 'action-steward' ); ?></button>
								<p class="wpcc-ai-field__hint" style="margin:8px 0 0;"><?php esc_html_e( 'Remember the comma: entries inside the braces are separated by commas, and a missing or trailing one is the usual reason the file stops working.', 'action-steward' ); ?></p>
							</div>
						<?php endif; ?>
						<?php if ( '' !== $wpcc_vscode_input_snippet ) : ?>
							<div class="wpcc-ai-field" style="padding:0 22px 14px;margin:0;">
								<span class="wpcc-ai-field__label"><?php esc_html_e( 'Add as an item inside inputs', 'action-steward' ); ?></span>
								<pre class="wpcc-ai-config" id="wpcc-vscode-input-block"><?php echo esc_html( $wpcc_vscode_input_snippet ); ?></pre>
								<button type="button" class="button wpcc-copy-btn" data-copy-target="wpcc-vscode-input-block"><?php esc_html_e( 'Copy secure input item', 'action-steward' ); ?></button>
								<p class="wpcc-ai-field__hint" style="margin:8px 0 0;"><?php esc_html_e( 'Items in inputs are separated by commas. The id here must match the ${input:…} reference in the server entry above.', 'action-steward' ); ?></p>
							</div>
						<?php endif; ?>
						<?php
						// Client quirks belong with the guided steps when there are any;
						// for a file-configured client this panel IS the setup, so they
						// render here instead. Never both — see the guided panel above.
						if ( ! $wpcc_has_guided ) :
							foreach ( AIClientRegistry::post_setup_notes_for( $wpcc_selected_client ) as $wpcc_note ) :
								?>
								<p class="wpcc-ai-field__hint" style="padding:0 22px 14px;margin:0;"><?php echo esc_html( $wpcc_note ); ?></p>
								<?php
							endforeach;
						endif;
						?>
				</div>
				<div class="wpcc-ai-panel__body" style="padding-top:14px;">
					<details class="wpcc-ai-advanced" style="margin:0;">
						<summary><?php esc_html_e( 'Connection address & where to paste', 'action-steward' ); ?></summary>
						<div class="wpcc-ai-advanced__body">
							<div class="wpcc-ai-field">
								<span class="wpcc-ai-field__label"><?php esc_html_e( 'Connection URL', 'action-steward' ); ?></span>
								<div class="wpcc-ai-url">
									<code class="wpcc-ai-url__text"><?php echo esc_html( $wpcc_cfg_mcp_url ); ?></code>
									<button type="button" class="button wpcc-copy-btn" data-copy="<?php echo esc_attr( $wpcc_cfg_mcp_url ); ?>"><?php esc_html_e( 'Copy', 'action-steward' ); ?></button>
								</div>
							</div>
							<?php if ( ! empty( $wpcc_current_client['config_paths'] ) ) : ?>
								<div class="wpcc-ai-field">
									<span class="wpcc-ai-field__label"><?php esc_html_e( 'Where to paste this', 'action-steward' ); ?></span>
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
			</details>
		<?php else : ?>
			<?php // Same target id on the manual fallback: the "Next" control must land somewhere for every assistant. ?>
			<div class="wpcc-ai-panel" id="wpcc-config-panel">
				<div class="wpcc-ai-panel__header"><?php esc_html_e( 'Your configuration', 'action-steward' ); ?></div>
				<div class="wpcc-ai-panel__body">
					<p style="color:#646970;"><?php esc_html_e( 'A ready-made configuration isn’t available for this assistant yet. You can still connect it manually using the connection address and an access token below.', 'action-steward' ); ?></p>
					<div class="wpcc-ai-field" style="margin-top:14px;">
						<span class="wpcc-ai-field__label"><?php esc_html_e( 'Connection URL', 'action-steward' ); ?></span>
						<div class="wpcc-ai-url">
							<code class="wpcc-ai-url__text"><?php echo esc_html( $wpcc_cfg_mcp_url ); ?></code>
							<button type="button" class="button wpcc-copy-btn" data-copy="<?php echo esc_attr( $wpcc_cfg_mcp_url ); ?>"><?php esc_html_e( 'Copy', 'action-steward' ); ?></button>
							<span class="wpcc-ai-copied" id="wpcc-copy-feedback">&#10003; <?php esc_html_e( 'Copied!', 'action-steward' ); ?></span>
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
		<details class="wpcc-ai-advanced">
			<summary><?php esc_html_e( 'Advanced: test the site endpoint in this browser', 'action-steward' ); ?></summary>
		<div class="wpcc-ai-panel" style="margin:0;border:0;border-top:1px solid #eef0f4;border-radius:0;box-shadow:none;">
			<div class="wpcc-ai-panel__header"><?php esc_html_e( 'Browser-only endpoint test', 'action-steward' ); ?></div>
			<div class="wpcc-ai-panel__body">
				<p><?php esc_html_e( 'Test Action Steward endpoints and token authentication from this browser, including the MCP handshake, tools and resources. This does not test whether your assistant loaded the server. Confirm that inside your selected client. No content is changed; authentication activity may be recorded.', 'action-steward' ); ?></p>
				<div style="margin-bottom: 12px;">
					<label for="wpcc-test-token" style="display: block; font-weight: 600; margin-bottom: 4px;"><?php esc_html_e( 'Access token', 'action-steward' ); ?></label>
					<input type="text" id="wpcc-test-token" class="regular-text" placeholder="wpcc_..." style="width: 100%; max-width: 500px; font-family: monospace;"
						value="<?php echo esc_attr( $wpcc_new_token ); ?>">
					<p style="color: #646970; font-size: 12px; margin: 4px 0 0;"><?php
						/*
						 * The field is prefilled with the token that was just created, so
						 * telling that customer to paste one — or to go and make one — was
						 * instructing them to redo the step they had just finished. Same
						 * fix as the configuration hint directly above: say whichever of
						 * the two is actually true.
						 */
						echo $wpcc_new_token
							? esc_html__( 'Your new token is already filled in — just run the test.', 'action-steward' )
							: esc_html__( 'Paste an access token, or create one in “Access tokens” above.', 'action-steward' );
					?></p>
				</div>
				<button type="button" class="button" id="wpcc-test-connection"><?php esc_html_e( 'Run read-only test', 'action-steward' ); ?></button>
				<div class="wpcc-ai-verify-result" id="wpcc-verify-result"></div>
			</div>
		</div>
		</details>

		<?php endif; ?>

		<!-- Safety note -->
		<div class="wpcc-ai-safe-note" role="note">
			<span class="wpcc-ai-safe-note__icon" aria-hidden="true">&#128274;</span>
			<div>
				<strong><?php esc_html_e( 'Connecting an assistant is safe by design.', 'action-steward' ); ?></strong>
				<ul>
					<li><?php echo esc_html( SecurityModeManager::is_protected()
						? SecurityModeManager::approval_step()
						: __( 'This site is in Development mode, so your assistant’s changes apply immediately — no approval step.', 'action-steward' ) ); ?></li>
					<li><?php esc_html_e( 'Every action is recorded under Changes.', 'action-steward' ); ?></li>
					<li><?php esc_html_e( 'Reversible changes can be undone from the Changes screen.', 'action-steward' ); ?></li>
				</ul>
			</div>
		</div>

	<?php elseif ( 'activity' === $wpcc_tab ) : ?>
		<!-- ===== ACTIVITY TAB ===== -->

		<div class="wpcc-ai-panel">
			<div class="wpcc-ai-panel__header"><?php esc_html_e( 'Recent assistant activity', 'action-steward' ); ?></div>
			<div class="wpcc-ai-panel__body">
				<?php if ( empty( $wpcc_ai_activity ) ) : ?>
					<p style="color:#646970;"><?php esc_html_e( 'No assistant activity recorded yet. It appears here once an assistant connects to this site.', 'action-steward' ); ?></p>
				<?php else : ?>
					<table class="wpcc-ai-token-table">
						<thead><tr><th><?php esc_html_e( 'Time', 'action-steward' ); ?></th><th><?php esc_html_e( 'Event', 'action-steward' ); ?></th></tr></thead>
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
			<div class="wpcc-ai-panel__header"><?php esc_html_e( 'How assistant access is controlled', 'action-steward' ); ?></div>
			<div class="wpcc-ai-panel__body">
				<p><?php esc_html_e( 'Every assistant connects through the same endpoint and is held to the same rules. No assistant gets extra privileges, and none can bypass required human approval, recording or the limits on its access token.', 'action-steward' ); ?></p>
				<ul class="wpcc-ai-security-list">
					<li>
						<strong><?php esc_html_e( 'Capabilities', 'action-steward' ); ?></strong>
						<span><?php esc_html_e( 'Every tool requires a specific capability assigned to the API token. No client can bypass capability enforcement.', 'action-steward' ); ?></span>
					</li>
					<li>
						<strong><?php esc_html_e( 'Approvals', 'action-steward' ); ?></strong>
						<span><?php esc_html_e( 'Operations requiring human approval must go through the request-approve-execute workflow. No client can auto-approve.', 'action-steward' ); ?></span>
					</li>
					<li>
						<strong><?php esc_html_e( 'Queue', 'action-steward' ); ?></strong>
						<span><?php esc_html_e( 'All operations follow the same queuing and execution flow. No client can bypass the queue or execute directly.', 'action-steward' ); ?></span>
					</li>
					<li>
						<strong><?php esc_html_e( 'Audit', 'action-steward' ); ?></strong>
						<span><?php esc_html_e( 'Every action is logged with the client source, actor context, and timestamp. Full traceability for all clients.', 'action-steward' ); ?></span>
					</li>
					<li>
						<strong><?php esc_html_e( 'Rollback', 'action-steward' ); ?></strong>
						<span><?php esc_html_e( 'Every modification is snapshotted before execution. All clients inherit the same rollback protection.', 'action-steward' ); ?></span>
					</li>
				</ul>
			</div>
		</div>

		<div class="wpcc-ai-panel">
			<div class="wpcc-ai-panel__header"><?php esc_html_e( 'Architecture', 'action-steward' ); ?></div>
			<div class="wpcc-ai-panel__body">
				<p><?php esc_html_e( 'Every assistant follows the same path through the plugin:', 'action-steward' ); ?></p>
				<pre style="background:#f6f7f7;padding:14px;border-radius:4px;font-size:13px;line-height:1.8;overflow-x:auto;">AI Client &rarr; MCP &rarr; Action Steward &rarr; Capability Runtime &rarr; Approval Runtime &rarr; Queue Runtime &rarr; OperationExecutor &rarr; Verification &rarr; Audit &rarr; Rollback</pre>
				<p style="color:#646970;font-size:12px;"><?php esc_html_e( 'There are no per-client runtimes, no special execution paths, and no vendor-specific privileges.', 'action-steward' ); ?></p>
			</div>
		</div>

	<?php endif; ?>

</div>

<script>
(function() {
	/*
	 * A deliberate app selection continues to Step 2 exactly once.
	 *
	 * The query marker exists only on app-card links, never on the page's initial URL.
	 * Remove it before moving so reload/back navigation cannot steal the user's position.
	 * Focus announces the new step to keyboard/screen-reader users; the token is never
	 * created until the separate Create button is pressed and its form is submitted.
	 */
	var accessTitle = document.getElementById('wpcc-create-access-title');
	function moveToAccess() {
		if (!accessTitle) { return; }
		accessTitle.focus({ preventScroll: true });
		var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
		accessTitle.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'start' });
	}
	try {
		var selectionUrl = new URL(window.location.href);
		if (selectionUrl.searchParams.get('wpcc_next') === 'access') {
			selectionUrl.searchParams.delete('wpcc_next');
			window.history.replaceState({}, '', selectionUrl.toString());
			window.requestAnimationFrame(function() { window.requestAnimationFrame(moveToAccess); });
		}
	} catch (e) {
		// Without URL support, Step 2 still follows the selector in document order.
	}

	/*
	 * Token creation dialog.
	 *
	 * Progressive enhancement, and it matters here: with JavaScript off the form
	 * renders inline and still works — still requires a label, still carries the
	 * scope and expiry choices, still needs its own submit. JS only turns it into
	 * a dialog and reveals the button that opens it. There is no path in which the
	 * safety of this control depends on a script having loaded.
	 */
	var mkForm = document.getElementById('wpcc-tokenmake');
	if (mkForm) {
		var mkPanel  = document.getElementById('wpcc-tokenmake-panel');
		var mkOpen   = document.getElementById('wpcc-tokenmake-open');
		var mkCancel = document.getElementById('wpcc-tokenmake-cancel');
		var mkLabel  = document.getElementById('wpcc-tokenmake-label');
		var mkDupe   = document.getElementById('wpcc-tokenmake-dupe');
		var mkSubmit = document.getElementById('wpcc-tokenmake-submit');
		var mkNote   = document.getElementById('wpcc-tokenmake-note');
		var mkLongLived = document.getElementById('wpcc-tokenmake-longlived');
		var mkExpires   = document.getElementById('wpcc-tokenmake-expires');
		var mkPrev   = null;
		var mkSent   = false;

		// Active token labels, lowercased — used only to warn about a name that is
		// already in use. Labels are not secrets; no token value is exposed here.
		var mkExisting = <?php echo wp_json_encode( array_values( array_map( static fn( $t ) => (string) $t['label'], $wpcc_usable_tokens ?? [] ) ) ); ?>;
		var mkDupeTpl  = <?php echo wp_json_encode( /* translators: %s: the name of an access token that already exists. */ __( 'You already have an active token called “%s”. You can still create this one, but you will not be able to tell them apart later.', 'action-steward' ) ); ?>;

		mkForm.classList.add('wpcc-tokenmake--js');

		function mkFocusable() {
			return [].filter.call(
				mkPanel.querySelectorAll('button, input, select, textarea, [href]'),
				function (el) { return !el.disabled && el.offsetParent !== null; }
			);
		}
		function mkScope() {
			var el = mkForm.querySelector('input[name="wpcc_token_scope"]:checked');
			return el ? el.value : 'read_only';
		}
		function mkSyncScope() {
			// The full-access consequence note appears only when full access is
			// actually selected — read-only is the default, so on open there is
			// nothing to warn about.
			var isFull = mkScope() === 'full';
			if (mkNote) { mkNote.hidden = ! isFull; }
			// The long-lived-credential warning needs BOTH conditions.
			if (mkLongLived) {
				mkLongLived.hidden = ! ( isFull && mkExpires && mkExpires.value === 'never' );
			}
		}
		function mkShow() {
			mkPrev = document.activeElement;
			// Reopening always returns to the safe default: a dialog that remembered
			// a previous Full access pick would preselect it, which is exactly the
			// thing "Full access must be an explicit selection" rules out.
			var ro = mkForm.querySelector('input[name="wpcc_token_scope"][value="read_only"]');
			if (ro) { ro.checked = true; }
			// Reopening returns to the safe defaults, expiry included — a dialog
			// that remembered a previous "never" would hand it back silently.
			if (mkExpires) { mkExpires.value = '30d'; }
			mkSyncScope();
			mkForm.classList.add('is-open');
			mkLabel.focus();
			mkLabel.select();
			mkCheckDupe();
		}
		function mkHide() {
			mkForm.classList.remove('is-open');
			if (mkPrev && mkPrev.focus) { mkPrev.focus(); }
		}
		function mkCheckDupe() {
			var v = (mkLabel.value || '').trim().toLowerCase();
			var hit = null;
			for (var i = 0; i < mkExisting.length; i++) {
				if (String(mkExisting[i]).trim().toLowerCase() === v && v !== '') { hit = mkExisting[i]; break; }
			}
			if (hit) {
				mkDupe.textContent = mkDupeTpl.replace('%s', hit);
				mkDupe.hidden = false;
			} else {
				mkDupe.hidden = true;
				mkDupe.textContent = '';
			}
		}

		mkOpen.addEventListener('click', mkShow);
		mkCancel.addEventListener('click', mkHide);
		mkLabel.addEventListener('input', mkCheckDupe);
		Array.prototype.forEach.call(
			mkForm.querySelectorAll('input[name="wpcc_token_scope"]'),
			function (r) { r.addEventListener('change', mkSyncScope); }
		);
		if (mkExpires) { mkExpires.addEventListener('change', mkSyncScope); }
		mkSyncScope();

		// Clicking the backdrop cancels; clicking inside the box does not.
		mkPanel.addEventListener('click', function (e) { if (e.target === mkPanel) { mkHide(); } });

		mkPanel.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') { e.preventDefault(); mkHide(); return; }
			if (e.key !== 'Tab') { return; }
			var f = mkFocusable();
			if (!f.length) { return; }
			var first = f[0], last = f[f.length - 1];
			if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
			else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
		});

		/*
		 * Double-submit protection. A slow save used to leave the primary button
		 * live, so an impatient second click minted a second full-access token the
		 * customer never wanted and would not know to revoke. `required` on the
		 * label field still runs first — the guard only arms once the browser has
		 * accepted the form.
		 */
		mkForm.addEventListener('submit', function (e) {
			if (mkSent) { e.preventDefault(); return; }
			mkSent = true;
			mkSubmit.disabled = true;
			mkSubmit.textContent = <?php echo wp_json_encode( __( 'Creating…', 'action-steward' ) ); ?>;
		});
	}

	/*
	 * "Next: set up <assistant>" — the one control that ends the
	 * token flow.
	 *
	 * The anchor already works with JS off; this upgrades the jump into something
	 * a person can follow. Three things have to happen together, and the order
	 * matters: move the keyboard to the Copy button FIRST (so assistive tech is
	 * told where it now is), then scroll, then mark the destination. Focusing
	 * after an animated scroll makes the browser jump a second time.
	 *
	 * preventDefault() means the URL never grows a #hash, so a reload does not
	 * silently re-scroll a customer who came back for something else.
	 */
	var nextBtn = document.getElementById('wpcc-token-next');
	if (nextBtn) {
		nextBtn.addEventListener('click', function (e) {
			var panel = document.querySelector(nextBtn.getAttribute('href'));
			if (!panel) { return; } // no destination: fall through to the plain anchor.
			e.preventDefault();

			var copyBtn = panel.querySelector('.wpcc-copy-btn');
			if (copyBtn) {
				copyBtn.focus({ preventScroll: true });
			} else {
				// Manual-configuration fallback has no copy button; make the panel
				// itself the focus target so the jump is still announced.
				panel.setAttribute('tabindex', '-1');
				panel.focus({ preventScroll: true });
			}

			var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
			panel.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'start' });

			/*
			 * A smooth scroll is an animation, and animations are not guaranteed to
			 * run — a backgrounded tab suspends them outright (measured: the request
			 * is accepted and the page simply never moves). That failure is silent,
			 * and it lands on the one control whose entire job is "take me there":
			 * the customer would be left exactly where they were, with a highlight
			 * drawn on something two screens below. So the arrival is verified, and
			 * if it did not happen the page jumps instead. A plain jump is a worse
			 * transition and an infinitely better outcome than none.
			 */
			if ( ! reduced ) {
				setTimeout( function () {
					var r = panel.getBoundingClientRect();
					var vh = window.innerHeight || document.documentElement.clientHeight;
					if ( r.top > vh || r.bottom < 0 ) { panel.scrollIntoView({ behavior: 'auto', block: 'start' }); }
				}, 700 );
			}

			// Restart the animation reliably even if the button is pressed twice.
			panel.classList.remove('wpcc-spotlight');
			void panel.offsetWidth;
			panel.classList.add('wpcc-spotlight');
		});
	}

	// Live, browser-only token fill: insert the pasted access token into the
	// displayed configuration so "Copy configuration" copies a complete, ready
	// config. The token is substituted in the DOM only — it is never sent back to
	// the server or persisted (the server stores only a salted hash of tokens).
	var tokenFill = document.getElementById('wpcc-token-fill');
	// Inline-client setup fields retain their existing browser fill behavior.
	// One-time environment credential commands are server-rendered and excluded.
	var tokenSlots = [].slice.call(document.querySelectorAll('[data-wpcc-token-slot]'));
	var tokenControls = [].slice.call(document.querySelectorAll('[data-wpcc-requires-token-control]'));
	var tokenPreviews = [].slice.call(document.querySelectorAll('[data-wpcc-requires-token-preview]'));
	var tokenPayloads = [].slice.call(document.querySelectorAll('[data-wpcc-requires-token-payload]'));
	var tokenMessages = [].slice.call(document.querySelectorAll('[data-wpcc-token-needed]'));
	var configBlock = document.getElementById('wpcc-config-block');
	if (configBlock && tokenSlots.indexOf(configBlock) === -1) { tokenSlots.push(configBlock); }
	if (tokenFill && tokenSlots.length) {
		// The same constant the generators emit and the note above names, so this
		// field can never again search for a string the configuration does not have.
		var PLACEHOLDER = <?php echo wp_json_encode( AIClientRegistry::TOKEN_PLACEHOLDER ); ?>;
		var slotTemplates = tokenSlots.map(function (el) {
			return el.hasAttribute('data-wpcc-token-template')
				? el.getAttribute('data-wpcc-token-template')
				: el.textContent;
		});
		var applyToken = function() {
			var v = tokenFill.value.trim();
			tokenSlots.forEach(function (el, i) {
				el.textContent = v ? slotTemplates[i].split(PLACEHOLDER).join(v) : slotTemplates[i];
			});
			var ready = !!v;
			tokenControls.forEach(function(control) {
				control.disabled = !ready;
				control.setAttribute('aria-disabled', ready ? 'false' : 'true');
			});
			tokenPreviews.forEach(function(preview) { preview.hidden = !ready; });
			tokenPayloads.forEach(function(payload) { payload.hidden = !ready; });
			tokenMessages.forEach(function(message) { message.hidden = ready; });
		};
		tokenFill.addEventListener('input', applyToken);
		applyToken(); // apply any pre-filled (just-created) token on load
		// If the user arrived via "Use in config", focus the field so they can paste.
		if (window.location.hash === '#wpcc-token-fill' && !tokenFill.value) {
			tokenFill.focus();
		}
	}

	/*
	 * Cursor's official install link is created only when the customer presses the
	 * button. It uses the local cursor:// protocol and is never requested from, or
	 * sent through, cursor.com. Keeping it out of href also prevents link previews,
	 * crawlers and browser prefetch from seeing a token-bearing URI.
	 */
	var cursorInstall = document.getElementById('wpcc-cursor-install');
	if (cursorInstall) {
		cursorInstall.addEventListener('click', function() {
			var fill = document.getElementById('wpcc-token-fill');
			var token = fill ? fill.value.trim() : '';
			if (!token) {
				cursorInstall.textContent = <?php echo wp_json_encode( __( 'Paste your token first', 'action-steward' ) ); ?>;
				if (fill) { fill.focus(); }
				return;
			}
			var config = JSON.stringify({
				url: cursorInstall.getAttribute('data-mcp-url'),
				headers: { Authorization: 'Bearer ' + token }
			});
			var link = 'cursor://anysphere.cursor-deeplink/mcp/install?name=wp-command-center&config=' + encodeURIComponent(window.btoa(config));
			window.location.assign(link);
		});
	}

	/*
	 * Copy buttons confirm on THEMSELVES.
	 *
	 * Every copy button on this screen used to report success into one shared
	 * element, `#wpcc-copy-feedback`, which lives in the configuration panel's
	 * header. That is fine for the button standing next to it and wrong for every
	 * other one — and the worst case was the most important button in the product:
	 * the Copy beside a freshly minted access token, at the top of the page, whose
	 * "Copied!" flashed two panels below the fold. A customer copying the one
	 * string they will never be shown again saw nothing happen at all, and the
	 * rational response to that is to press it again, or to select the text by
	 * hand and hope.
	 *
	 * Confirmation now happens on the pressed button, so it is by definition where
	 * the customer is looking. The shared span is still flashed when it is a
	 * sibling of the button (the configuration header, where it reads correctly),
	 * which keeps that panel looking exactly as it did.
	 *
	 * The failure path matters too: a clipboard write can be refused (insecure
	 * origin, denied permission). It used to be swallowed, leaving a button that
	 * said nothing whether it worked or not. Now it says so, and the text is still
	 * on screen to select by hand.
	 *
	 * And the write is not allowed to answer with silence. `navigator.clipboard.
	 * writeText()` can return a promise that settles NEITHER way — observed here,
	 * on a page whose document reported itself focused — which is the one outcome
	 * a confirmation cannot survive, because the button would sit there saying
	 * nothing exactly as it did before. So the promise is given a short deadline;
	 * miss it and the synchronous `execCommand` path decides, since that returns a
	 * real boolean instead of a maybe. Every press ends in a definite answer, and
	 * the answer is the truth rather than an optimistic guess.
	 */
		document.querySelectorAll('.wpcc-copy-btn').forEach(function(btn) {
		var COPIED = <?php echo wp_json_encode( __( 'Copied', 'action-steward' ) ); ?>;
		var FAILED = <?php echo wp_json_encode( __( 'Press Ctrl/Cmd+C', 'action-steward' ) ); ?>;

		// Deprecated, still synchronous, still the only call here that answers.
		function legacyCopy(text) {
			var ta = document.createElement('textarea');
			ta.value = text; ta.style.position = 'fixed'; ta.style.left = '-9999px';
			document.body.appendChild(ta); ta.select();
			var ok = false;
			try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
			document.body.removeChild(ta);
			return ok;
		}

		function copyText(text, done) {
			var answered = false;
			function answer(ok) { if (!answered) { answered = true; done(ok); } }
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(text).then(
					function () { answer(true); },
					function () { answer(legacyCopy(text)); }
				);
				// Still inside the browser's transient user activation window, so the
				// fallback is allowed to run.
				setTimeout(function () { if (!answered) { answer(legacyCopy(text)); } }, 400);
				return;
			}
			answer(legacyCopy(text));
		}

		function confirmOn(ok) {
			// A second press while the confirmation is showing must not capture
			// "Copied" as the button's original label and leave it stuck there.
			if (btn.dataset.wpccRestore === undefined) { btn.dataset.wpccRestore = btn.textContent; }
			clearTimeout(btn._wpccT);
			btn.textContent = ok ? ('✓ ' + COPIED) : FAILED;
			btn.setAttribute('aria-live', 'polite');
			btn._wpccT = setTimeout(function() {
				btn.textContent = btn.dataset.wpccRestore;
				delete btn.dataset.wpccRestore;
			}, 2000);

			// Only when it genuinely sits beside this button.
			var fb = btn.parentNode ? btn.parentNode.querySelector('.wpcc-ai-copied') : null;
			if (ok && fb) { fb.classList.add('wpcc-ai-copied--visible'); setTimeout(function() { fb.classList.remove('wpcc-ai-copied--visible'); }, 2000); }
		}

		btn.addEventListener('click', function() {
			if (this.disabled || this.getAttribute('aria-disabled') === 'true') { return; }
			var text;
			if (this.hasAttribute('data-copy-target-el')) {
				/*
				 * Copy the code element sitting beside this button.
				 *
				 * The per-OS credential commands are a repeated row, so an id-based
				 * target would need a unique id minted per row purely to let a button
				 * find the thing next to it. Reading the sibling keeps the markup flat
				 * and copies the complete server-rendered credential command verbatim.
				 */
				var el = this.parentNode.querySelector('[data-wpcc-token-slot], code');
				text = el ? el.textContent : '';
			} else {
				var targetId = this.getAttribute('data-copy-target');
				var target   = targetId ? document.getElementById(targetId) : null;
				text = targetId ? ( target ? target.textContent : '' ) : this.getAttribute('data-copy');
			}
			if (!text) return;
			copyText(text, confirmOn);
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
				resultEl.innerHTML = '<p><strong>&#10007; No token:</strong> <?php esc_html_e( 'Paste an API token above or generate one in the Configuration tab.', 'action-steward' ); ?></p>';
				return;
			}

			resultEl.className = 'wpcc-ai-verify-result wpcc-ai-verify-result--loading';
			resultEl.innerHTML = '<p><span class="spinner is-active" style="float:none;margin:0 10px 0 0;"></span><?php esc_html_e( 'Testing connection...', 'action-steward' ); ?></p>';
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
				relay:     <?php echo wp_json_encode( __( 'Connector script', 'action-steward' ) ); ?>,
				health:    <?php echo wp_json_encode( __( 'Health endpoint', 'action-steward' ) ); ?>,
				manifest:  <?php echo wp_json_encode( __( 'Agent manifest', 'action-steward' ) ); ?>,
				initialize:<?php echo wp_json_encode( __( 'MCP handshake', 'action-steward' ) ); ?>,
				resources: <?php echo wp_json_encode( __( 'MCP resources', 'action-steward' ) ); ?>,
				tools:     <?php echo wp_json_encode( __( 'MCP tools', 'action-steward' ) ); ?>,
				relay404:  <?php echo wp_json_encode( __( 'Not found on this site — your assistant cannot start the connector.', 'action-steward' ) ); ?>
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
					var html = allPass ? '<h3 style="margin:0 0 10px;color:#00a32a;">&#10003; <?php esc_html_e( 'Action Steward can authenticate this token. Server checks passed; verify the connection inside your client.', 'action-steward' ); ?></h3>' : '<h3 style="margin:0 0 10px;color:#d63638;">&#10007; <?php esc_html_e( 'Some checks failed.', 'action-steward' ); ?></h3>';
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
					resultEl.innerHTML = '<p><strong>&#10007; <?php esc_html_e( 'Connection test failed:', 'action-steward' ); ?></strong> ' + err.message + '</p><p style="color:#646970;font-size:12px;"><?php esc_html_e( 'Check that your site is reachable and the token is valid.', 'action-steward' ); ?></p>';
				});
		});
	}
})();
</script>
