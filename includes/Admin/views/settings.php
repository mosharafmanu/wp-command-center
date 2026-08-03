<?php
/**
 * Settings › Security & Approvals.
 *
 * V1 refinement: this screen answers exactly ONE question — "How much can an AI
 * change on this site without asking me first?" Everything that used to share the
 * page has moved to the surface that owns it, with no capability lost:
 *   • token creation / scopes  → Settings › Connections (the single token surface)
 *   • REST endpoint reference  → Connect › API & Integrations
 *   • access-control notes     → the same two screens, next to the controls
 * A duplicated reference table is not extra help; it is a second place to be wrong.
 *
 * Modes are listed safest-first and named in product language (Standard protection /
 * Strict approval / Development). All three are peer comparison cards carrying the
 * same shape of information — name, badge, three benefits, one footnote — because a
 * customer cannot choose between options they cannot see side by side.
 *
 * Development states what it costs AND what it keeps. It removes the approval step,
 * but the recording and the undo survive, and saying only the first part made the
 * mode read as reckless rather than situational. The honest "never on a live site"
 * warning, the badge, and the switch-in confirmation all remain.
 *
 * The mode POLICY is untouched: SecurityModeManager decides what gets gated, and
 * this file no longer calls describe() at all — the card copy lives here, in the
 * presentation layer, so the policy class has no view concerns.
 */

defined( 'ABSPATH' ) || exit;

use WPCommandCenter\Operations\SecurityModeManager;

$notice = null;

if ( isset( $_POST['wpcc_action'] ) ) {
	check_admin_referer( 'wpcc_settings' );

	$action = sanitize_text_field( wp_unslash( $_POST['wpcc_action'] ) );

	if ( 'set_security_mode' === $action ) {
		$mode = sanitize_key( wp_unslash( $_POST['wpcc_security_mode'] ?? '' ) );
		if ( in_array( $mode, SecurityModeManager::MODES, true ) ) {
			$previous = SecurityModeManager::current();
			update_option( 'wpcc_security_mode', $mode );
			// PROGRAM-5A — record every mode change (no secret). Switching into the
			// self-approving Developer mode is logged with an explicit risk flag.
			if ( $previous !== $mode ) {
				( new \WPCommandCenter\Security\AuditLog() )->record( 'security.mode.changed', [
					'from'         => $previous,
					'to'           => $mode,
					'self_approve' => ( SecurityModeManager::MODE_DEVELOPER === $mode ),
					'actor'        => 'admin_ui',
				] );
			}
			$notice = [
				'type'    => 'success',
				'message' => sprintf(
					/* translators: %s: security mode label */
					__( 'Saved. This site is now set to %s.', 'ai-command-center' ),
					SecurityModeManager::label()
				),
			];
		} else {
			$notice = [ 'type' => 'error', 'message' => __( 'That is not a valid protection setting.', 'ai-command-center' ) ];
		}
	} elseif ( 'set_uninstall_policy' === $action ) {
		// What happens to this site's change history if the plugin is deleted.
		// Default is to keep it; this is the explicit opt-in to erase it.
		$purge = ! empty( $_POST['wpcc_delete_data_on_uninstall'] );
		update_option( 'wpcc_delete_data_on_uninstall', $purge ? 1 : 0 );
		$notice = [
			'type'    => 'success',
			'message' => $purge
				? __( 'Saved. Deleting the plugin will also erase its data.', 'ai-command-center' )
				: __( 'Saved. Your history and audit trail will be kept if the plugin is deleted.', 'ai-command-center' ),
		];
	}
}

$current_mode = SecurityModeManager::current();
$is_dev       = ( SecurityModeManager::MODE_DEVELOPER === $current_mode );

/**
 * Presentation copy for the comparison cards. This lives in the view on purpose:
 * SecurityModeManager owns POLICY and is not touched here. Nothing below changes
 * what gets gated — SecurityModeManager::requires_approval() remains the only
 * decision-maker, and these bullets simply describe it accurately:
 *
 *   | risk       | Standard | Strict | Development |
 *   | diagnostic | run      | run    | run         |
 *   | low        | run      | wait   | run         |
 *   | medium+    | wait     | wait   | run         |
 *
 * Safest-first. Development is a peer card rather than a hidden disclosure: a
 * customer cannot compare against an option they cannot see, and hiding it made
 * the mode look disreputable rather than situational. The badge says where the
 * mode belongs; the red footnote and the switch-in confirmation still say, in
 * plain words, never to run it on a live site.
 */
$wpcc_mode_cards = [
	SecurityModeManager::MODE_CLIENT     => [
		'badge'      => __( 'Recommended', 'ai-command-center' ),
		'badge_kind' => 'good',
		'points'     => [
			__( 'Questions and checks run instantly', 'ai-command-center' ),
			__( 'Anything that could affect visitors waits for your approval', 'ai-command-center' ),
			__( 'Every action recorded — supported changes can be undone', 'ai-command-center' ),
		],
		'footnote'   => __( 'Recommended for live websites.', 'ai-command-center' ),
	],
	SecurityModeManager::MODE_ENTERPRISE => [
		'badge'      => '',
		'badge_kind' => '',
		'points'     => [
			__( 'Questions and checks run instantly', 'ai-command-center' ),
			__( 'Every change waits for approval — including low-risk ones', 'ai-command-center' ),
			__( 'Every action recorded — supported changes can be undone', 'ai-command-center' ),
		],
		'footnote'   => __( 'Maximum control. Best for enterprise and regulated sites.', 'ai-command-center' ),
	],
	SecurityModeManager::MODE_DEVELOPER  => [
		'badge'      => __( 'Local & staging only', 'ai-command-center' ),
		'badge_kind' => 'warn',
		'points'     => [
			__( 'AI changes run immediately — no waiting', 'ai-command-center' ),
			__( 'Everything is still recorded', 'ai-command-center' ),
			__( 'Supported changes can still be undone at any time', 'ai-command-center' ),
		],
		'footnote'   => __( 'Never use this mode on a live production website.', 'ai-command-center' ),
	],
];
?>
<style>
/* Protection — comparison cards.
   Presentation only. Every colour comes from the CDS token layer so the screen
   follows the rest of the product (and the viewer's colour scheme) instead of
   pinning its own palette. Nothing here alters what any mode does. */
.wpcc-prot { max-width: 1040px; }
.wpcc-prot__lead {
	max-width: 660px; margin: 4px 0 var( --wpcc-space-5, 20px );
	font-size: var( --wpcc-fs-body, 13px ); color: var( --wpcc-text-secondary, #50575e );
}
.wpcc-prot__set { border: 0; padding: 0; margin: 0; }
.wpcc-prot__grid {
	display: grid; gap: var( --wpcc-space-3, 12px );
	grid-template-columns: repeat( auto-fit, minmax( 260px, 1fr ) );
	align-items: stretch;
}
.wpcc-prot__card {
	position: relative; display: flex; flex-direction: column;
	padding: 16px 18px 18px; cursor: pointer;
	background: var( --wpcc-surface-card, #fff );
	border: 1px solid var( --wpcc-border-subtle, #dcdcde );
	border-radius: var( --wpcc-r-card, 8px );
	transition: border-color .15s ease, background-color .15s ease;
}
.wpcc-prot__card:hover { border-color: var( --wpcc-border-strong, #c3c4c7 ); }
.wpcc-prot__card.is-current {
	border-color: var( --wpcc-border-accent, #2271b1 );
	background: var( --wpcc-surface-accent-soft, #f0f6fc );
}
/* The risk mode reads as risky only once it is the mode actually in force. */
.wpcc-prot__card.is-risk.is-current {
	border-color: var( --wpcc-state-danger-fg, #d63638 );
	background: var( --wpcc-state-danger-bg, #fcf0f1 );
}
.wpcc-prot__card:focus-within {
	outline: var( --wpcc-focus-ring-width, 2px ) solid var( --wpcc-border-focus, #2271b1 );
	outline-offset: 2px;
}
.wpcc-prot__radio { position: absolute; top: 18px; right: 16px; margin: 0; }
.wpcc-prot__head { display: block; padding-right: 28px; }
.wpcc-prot__badges { display: flex; align-items: center; gap: 6px; min-height: 20px; margin-top: 6px; }
.wpcc-prot__name {
	font-size: var( --wpcc-fs-h1, 16px ); font-weight: 600; line-height: 1.3;
	color: var( --wpcc-text-primary, #1d2327 );
}
.wpcc-prot__badge {
	display: inline-block; padding: var( --wpcc-badge-pad-y, 1px ) var( --wpcc-badge-pad-x, 8px );
	border-radius: var( --wpcc-badge-radius, 10px );
	font-size: var( --wpcc-badge-font-size, 11px ); font-weight: 600; letter-spacing: .01em;
	line-height: 1.6; white-space: nowrap;
}
.wpcc-prot__badge--good    { background: var( --wpcc-state-success-bg, #edfaef ); color: var( --wpcc-state-success-fg, #00a32a ); }
.wpcc-prot__badge--warn    { background: var( --wpcc-state-warning-bg, #fcf9e8 ); color: var( --wpcc-state-warning-fg, #8a6d00 ); }
.wpcc-prot__badge--current { background: var( --wpcc-state-info-bg, #f0f6fc );    color: var( --wpcc-text-accent, #2271b1 ); }
.wpcc-prot__points { margin: 14px 0 12px; padding: 0; list-style: none; }
.wpcc-prot__points li {
	display: flex; gap: 8px; margin: 0 0 8px;
	font-size: var( --wpcc-fs-body, 13px ); line-height: 1.45;
	color: var( --wpcc-text-secondary, #50575e );
}
.wpcc-prot__tick { flex: 0 0 auto; font-weight: 700; color: var( --wpcc-state-success-fg, #00a32a ); }
.wpcc-prot__card.is-risk .wpcc-prot__tick { color: var( --wpcc-text-accent, #2271b1 ); }
.wpcc-prot__foot {
	display: block; margin-top: auto; padding-top: 12px;
	border-top: 1px solid var( --wpcc-border-subtle, #dcdcde );
	font-size: var( --wpcc-fs-small, 12px ); color: var( --wpcc-text-muted, #646970 );
}
.wpcc-prot__card.is-risk .wpcc-prot__foot { color: var( --wpcc-state-danger-fg, #d63638 ); font-weight: 600; }
</style>
<div class="wrap wpcc-wrap wpcc-prot">
	<h1><?php esc_html_e( 'Protection', 'ai-command-center' ); ?></h1>
	<p class="wpcc-prot__lead">
		<?php esc_html_e( 'How much can an AI assistant change on this site without asking you first? Questions and diagnostics are never held back in any mode — this only controls changes.', 'ai-command-center' ); ?>
	</p>

	<?php if ( $notice ) : ?>
		<div class="notice inline notice-<?php echo esc_attr( $notice['type'] ); ?>"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
	<?php endif; ?>

	<form method="post" id="wpcc-security-mode-form">
		<?php wp_nonce_field( 'wpcc_settings' ); ?>
		<input type="hidden" name="wpcc_action" value="set_security_mode" />
		<fieldset class="wpcc-prot__set">
			<legend class="screen-reader-text"><?php esc_html_e( 'How much can AI change without asking?', 'ai-command-center' ); ?></legend>

			<div class="wpcc-prot__grid">
			<?php foreach ( $wpcc_mode_cards as $wpcc_mode => $wpcc_card ) : ?>
				<?php
				$wpcc_on   = ( $current_mode === $wpcc_mode );
				$wpcc_risk = ( SecurityModeManager::MODE_DEVELOPER === $wpcc_mode );
				$wpcc_cls  = 'wpcc-prot__card'
					. ( $wpcc_on ? ' is-current' : '' )
					. ( $wpcc_risk ? ' is-risk' : '' );
				?>
				<label class="<?php echo esc_attr( $wpcc_cls ); ?>">
					<input type="radio" name="wpcc_security_mode" value="<?php echo esc_attr( $wpcc_mode ); ?>" <?php checked( $current_mode, $wpcc_mode ); ?> class="wpcc-prot__radio" />
					<span class="wpcc-prot__head">
						<span class="wpcc-prot__name"><?php echo esc_html( SecurityModeManager::label_for( $wpcc_mode ) ); ?></span>
						<span class="wpcc-prot__badges">
							<?php if ( $wpcc_on ) : ?>
								<span class="wpcc-prot__badge wpcc-prot__badge--current"><?php esc_html_e( 'Current', 'ai-command-center' ); ?></span>
							<?php endif; ?>
							<?php if ( '' !== $wpcc_card['badge'] ) : ?>
								<span class="wpcc-prot__badge wpcc-prot__badge--<?php echo esc_attr( $wpcc_card['badge_kind'] ); ?>"><?php echo esc_html( $wpcc_card['badge'] ); ?></span>
							<?php endif; ?>
						</span>
					</span>
					<ul class="wpcc-prot__points">
						<?php foreach ( $wpcc_card['points'] as $wpcc_point ) : ?>
							<li><span class="wpcc-prot__tick" aria-hidden="true">&#10003;</span><?php echo esc_html( $wpcc_point ); ?></li>
						<?php endforeach; ?>
					</ul>
					<span class="wpcc-prot__foot"><?php echo esc_html( $wpcc_card['footnote'] ); ?></span>
				</label>
			<?php endforeach; ?>
			</div>
		</fieldset>
		<?php submit_button( __( 'Save', 'ai-command-center' ) ); ?>
	</form>
	<script>
	(function () {
		var form = document.getElementById( 'wpcc-security-mode-form' );
		if ( ! form ) { return; }
		var warn = <?php echo wp_json_encode( __( 'This turns off the approval step. An AI assistant will be able to change this site immediately, with no review. Continue?', 'ai-command-center' ) ); ?>;
		var devValue = <?php echo wp_json_encode( SecurityModeManager::MODE_DEVELOPER ); ?>;
		var wasDev = <?php echo wp_json_encode( $is_dev ); ?>;
		form.addEventListener( 'submit', function ( e ) {
			var sel = form.querySelector( 'input[name="wpcc_security_mode"]:checked' );
			// Only confirm when this is an actual switch INTO development mode —
			// re-saving a site that is already there does not need a scare prompt.
			if ( sel && sel.value === devValue && ! wasDev && ! window.confirm( warn ) ) {
				e.preventDefault();
			}
		} );
	})();
	</script>

	<h2 style="margin-top:32px;padding-top:20px;border-top:1px solid #dcdcde;font-size:15px;"><?php esc_html_e( 'If you delete this plugin', 'ai-command-center' ); ?></h2>
	<form method="post">
		<?php wp_nonce_field( 'wpcc_settings' ); ?>
		<input type="hidden" name="wpcc_action" value="set_uninstall_policy" />
		<p class="description" style="max-width:620px;font-size:13px;">
			<?php esc_html_e( 'By default your change history and audit trail are kept, in case you still need the record of what happened on this site. Tick this only if you want everything removed.', 'ai-command-center' ); ?>
		</p>
		<label style="display:block;margin:10px 0;">
			<input type="checkbox" name="wpcc_delete_data_on_uninstall" value="1" <?php checked( (bool) get_option( 'wpcc_delete_data_on_uninstall', false ) ); ?> />
			<?php esc_html_e( 'Also delete all WP Command Center data when the plugin is deleted', 'ai-command-center' ); ?>
		</label>
		<?php submit_button( __( 'Save', 'ai-command-center' ), 'secondary', 'submit', false ); ?>
	</form>

	<p class="description" style="margin-top:24px;padding-top:16px;border-top:1px solid #dcdcde;font-size:13px;">
		<?php
		printf(
			/* translators: %1$s, %2$s: opening/closing link tags for Access; %3$s, %4$s: for History */
			esc_html__( 'Access tokens and what each one is allowed to do are managed in %1$sConnections%2$s. Everything that has already changed is listed in %3$sChanges%4$s.', 'ai-command-center' ),
			'<a href="' . esc_url( admin_url( 'admin.php?page=wpcc-settings&wpcc_tab=connections&cpane=tokens' ) ) . '">',
			'</a>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=wpcc-history' ) ) . '">',
			'</a>'
		);
		?>
	</p>
</div>
