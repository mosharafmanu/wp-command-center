<?php
/**
 * Command Center Home.
 *
 * V1 refinement. Home used to answer eleven questions at once: three onboarding
 * "doors", a first-value hero, a site-report hero, a setup checklist, platform
 * invariants (operation map / capability count / MCP tool count / DB version),
 * subsystem cards with risk-tier pills, an AI proposal feed, and a session
 * timeline keyed by hex session IDs. All of it true; none of it a decision.
 *
 * It now answers exactly the questions a site owner has on arrival:
 *
 *   1. What is this?                → one sentence
 *   2. Is my site protected?        → status strip
 *   3. Is an assistant connected?   → status strip (real token usage, not setup state)
 *   4. Does anything need me?       → approvals, only when non-zero
 *   5. What should I do next?       → exactly one primary action
 *   6. What changed, and can I undo it? → recent changes in plain language
 *
 * Everything removed from here still exists on the screen that owns it —
 * capabilities and the operation map under Settings › Advanced, health and the
 * site report under Settings › Diagnostics. Nothing was deleted, and the
 * engineering detail is one click away under the Engineer toggle.
 *
 * READ-ONLY: it links out and never executes. Data comes from the EXISTING
 * `/admin/dashboard` read; no route, capability, or schema is added.
 */

defined( 'ABSPATH' ) || exit;

use WPCommandCenter\Admin\AdoptionStatus;
use WPCommandCenter\Admin\Brand;
use WPCommandCenter\Admin\ConnectionStatus;
use WPCommandCenter\Operations\SecurityModeManager;

$nonce    = wp_create_nonce( 'wp_rest' );
$api_base = rest_url( 'wp-command-center/v1/admin' );

$links = [
	'approvals'      => admin_url( 'admin.php?page=wpcc-activity&wpcc_tab=approvals' ),
	// Failures live on the Execution tab, not on Pending.
	'approvalsQueue' => admin_url( 'admin.php?page=wpcc-activity&wpcc_tab=approvals&tab=queue' ),
	'change_history' => admin_url( 'admin.php?page=wpcc-history&wpcc_tab=changes' ),
	'connect'        => admin_url( 'admin.php?page=wpcc-settings&wpcc_tab=connections&cpane=assistants' ),
	'security'       => admin_url( 'admin.php?page=wpcc-settings&wpcc_tab=security' ),
	'access'         => admin_url( 'admin.php?page=wpcc-settings&wpcc_tab=connections&cpane=tokens' ),
	// Canonical hub URLs. These previously pointed at `wpcc_tab=capabilities` and
	// `wpcc_tab=recommendations`, tabs that were retired in the Phase 2B grouping;
	// they only still worked because a legacy redirect caught them.
	'advanced'       => admin_url( 'admin.php?page=wpcc-settings&wpcc_tab=advanced&apane=capabilities' ),
	'diagnostics'    => admin_url( 'admin.php?page=wpcc-settings&wpcc_tab=advanced&apane=diagnostics' ),
];

// Per-session deep link into the Change History timeline, hosted under History.
$session_base = admin_url( 'admin.php?page=wpcc-history&wpcc_tab=changes&tab=timeline' );

// First-run guide dismissal is per-user, and only honoured once setup is actually
// complete — an unfinished site never loses its guidance to a stray click.
if ( isset( $_POST['wpcc_firstrun_action'] ) && check_admin_referer( 'wpcc_firstrun' ) && current_user_can( 'manage_options' ) ) {
	$wpcc_fr_action = sanitize_key( wp_unslash( $_POST['wpcc_firstrun_action'] ) );
	if ( 'dismiss' === $wpcc_fr_action ) {
		update_user_meta( get_current_user_id(), 'wpcc_firstrun_dismissed', '1' );
	} elseif ( 'reopen' === $wpcc_fr_action ) {
		delete_user_meta( get_current_user_id(), 'wpcc_firstrun_dismissed' );
	}
}

$wpcc_conn         = ConnectionStatus::get();
$wpcc_mode         = SecurityModeManager::current();
$wpcc_protected    = SecurityModeManager::is_protected();
$wpcc_incomplete   = AdoptionStatus::setup_incomplete();
$wpcc_fr_dismissed = '1' === get_user_meta( get_current_user_id(), 'wpcc_firstrun_dismissed', true );
$wpcc_show_guide   = $wpcc_incomplete || ! $wpcc_fr_dismissed;

/*
 * STATE FOR "ALSO INCLUDED" — the difference between a brochure and a dashboard.
 *
 * Those three cards described what the plugin can do and never once said what
 * this site is actually doing. Read after six months away they are unanswerable:
 * "Built-in AI — let the plugin write SEO descriptions" gives a returning admin
 * no way to tell whether they switched it on in March, whether the key is still
 * there, or whether it has been quietly off the whole time. The card looks the
 * same either way, so the only way to find out is to click it — which is the
 * "now what?" this pass exists to remove.
 *
 * Both reads are free. The tool flags are options resolved through the one
 * precedence helper, and the token count is already computed above for the
 * status strip — so this adds no query, no route and no option to a screen whose
 * heavy data is fetched asynchronously and deliberately stays that way. Nothing
 * here is derived, estimated, or padded: a card either states a fact the product
 * already knows or says nothing.
 */
$wpcc_home_tools_on = [];
foreach ( \WPCommandCenter\Admin\BuiltinAiSettings::tools() as $wpcc_hk => $wpcc_hd ) {
	if ( \WPCommandCenter\Admin\BuiltinAiSettings::is_on( $wpcc_hk ) ) {
		$wpcc_home_tools_on[] = $wpcc_hd['label'];
	}
}
$wpcc_home_ai_key = AdoptionStatus::ai_configured();

/**
 * SETUP STATE — the redesign's central idea.
 *
 * Home is two different screens depending on one fact: has an assistant ever
 * reached this site? Before that, a dashboard is the wrong shape entirely —
 * counters of zero, an empty change list and a status strip of "not connected"
 * tell a new customer nothing and ask nothing of them. Home is therefore the
 * setup flow until setup is done, and the command view forever after.
 *
 * The three steps are the real dependency chain, in order:
 *   1. Protect  — decide what AI may do before granting any access at all
 *   2. Connect  — token + configuration, pasted into the assistant
 *   3. Try it   — the assistant actually reaches the site, which we can prove
 *
 * Step 3 completes on evidence (a real authenticated request), never on a
 * checkbox the user ticks themselves.
 */
/*
 * TWO steps, not three.
 *
 * Protection used to be step 1, pre-ticked. But a fresh install seeds Standard
 * protection automatically — so the customer arrived to a checkmark beside
 * "Choose how much AI can change", a thing they had not chosen. Crediting
 * someone for work they did not do makes the whole progress indicator feel
 * decorative, and it padded a two-step journey into "step 2 of 3", inflating
 * the effort before they had done anything.
 *
 * Protection is now stated as a fact above the steps, with a way to change it.
 * What remains is what the customer must genuinely do.
 */
$wpcc_steps = [
	[
		'done'  => ConnectionStatus::STATE_NO_TOKEN !== $wpcc_conn['state'],
		'title' => __( 'Connect your assistant', 'ai-command-center' ),
		'body'  => __( 'Pick Claude, Cursor, Codex, ChatGPT or Gemini, create an access token, and copy the setup. No AI provider key needed.', 'ai-command-center' ),
		'cta'   => __( 'Connect', 'ai-command-center' ),
		'url'   => $links['connect'],
		'donce' => __( 'An access token is ready.', 'ai-command-center' ),
	],
	[
		'done'   => ConnectionStatus::ever_connected(),
		'title'  => __( 'Ask your assistant to do something', 'ai-command-center' ),
		'body'   => __( 'Questions are answered straight away. Anything that would change the site comes back here for your approval first.', 'ai-command-center' ),
		// A step with no action and no completion signal is a dead end. This one
		// gives the customer the exact words to type, and says plainly how it
		// finishes — which is by evidence, not by them ticking a box.
		'prompt' => __( 'What plugins are installed on my site?', 'ai-command-center' ),
		'note'   => __( 'This step completes on its own the moment your assistant reaches the site.', 'ai-command-center' ),
		'cta'    => __( 'Back to setup', 'ai-command-center' ),
		'url'    => $links['connect'],
		'donce'  => __( 'Your assistant has reached this site.', 'ai-command-center' ),
	],
];

$wpcc_done_steps = count( array_filter( $wpcc_steps, static fn ( $s ) => $s['done'] ) );

/*
 * "Set up" means one thing only: an assistant has actually reached this site.
 *
 * It deliberately does NOT require protection to be on. Development mode is a
 * deliberate choice, not an unfinished step — gating on it would trap a
 * developer on the setup screen forever and take their dashboard away. The
 * dashboard carries a standing warning in that state instead.
 */
$wpcc_setup_done = ConnectionStatus::ever_connected();

// The one open step — the only action Home offers until setup is finished.
$wpcc_active_step = null;
foreach ( $wpcc_steps as $wpcc_i => $wpcc_s ) {
	if ( ! $wpcc_s['done'] ) {
		$wpcc_active_step = $wpcc_i;
		break;
	}
}
?>
<div class="wpcc-home">
<?php if ( ! $wpcc_setup_done ) : ?>
	<?php
	/*
	 * ── NOT SET UP YET ────────────────────────────────────────────────────────
	 * The whole screen is the setup. No status strip of zeros, no empty change
	 * list, no counters — none of it is true or useful before an assistant
	 * exists. One promise, three steps, exactly one button.
	 */
	?>
	<section class="wpcc-setup" aria-labelledby="wpcc-setup-h">
		<?php
		/*
		 * First run is the one screen that introduces the product, so it gets the full
		 * lockup rather than the standalone mark — the identity standard reserves the
		 * lockup for exactly this. It carries a real accessible name here (unlike the
		 * shell header's decorative mark) because on this screen the lockup IS the
		 * product's first statement of who it is; the headline below is a promise, not
		 * a name.
		 */
		echo wp_kses(
			Brand::picture(
				Brand::logo(),
				Brand::logo_dark(),
				esc_attr__( 'WP Command Center', 'ai-command-center' ),
				'wpcc-setup__logo',
				244,
				32
			),
			Brand::allowed_html()
		);
		?>
		<h2 id="wpcc-setup-h" class="wpcc-setup__title"><?php esc_html_e( 'Let an AI assistant work on this site — safely', 'ai-command-center' ); ?></h2>
		<p class="wpcc-setup__lede">
			<?php esc_html_e( 'You work in your AI assistant — Claude, Cursor or ChatGPT — and ask for changes to this site in your own words and your own language. Anything that matters waits here for your approval, every change is recorded, and supported changes can be undone.', 'ai-command-center' ); ?>
		</p>

		<?php
		// Protection stated as fact, not claimed as an achievement. A fresh install
		// is already protected; the customer should know that and be able to change
		// it, without being told they did it.
		?>
		<p class="wpcc-setup__protection">
			<span class="wpcc-setup__protection-dot <?php echo $wpcc_protected ? 'is-ok' : 'is-warn'; ?>" aria-hidden="true"></span>
			<?php
			if ( $wpcc_protected ) {
				// Mode-aware: on a Development site this line used to reassure the
				// customer about a protection they did not have.
				echo esc_html( \WPCommandCenter\Operations\SecurityModeManager::is_protected()
					? __( 'Already protected — changes will wait for your approval.', 'ai-command-center' )
					: __( 'Development mode — AI changes apply immediately, with no approval step.', 'ai-command-center' ) );
			} else {
				esc_html_e( 'Approvals are off — AI changes will apply immediately.', 'ai-command-center' );
			}
			?>
			<a href="<?php echo esc_url( $links['security'] ); ?>"><?php esc_html_e( 'Change', 'ai-command-center' ); ?></a>
		</p>

		<p class="wpcc-setup__progress">
			<?php
			// The OPEN step, not "done + 1" — these can complete out of order (a
			// site can be connected while protection is still off), and claiming
			// "step 3 of 3" above an unfinished step 1 is simply wrong.
			printf(
				/* translators: 1: the step the customer is on, 2: total steps */
				esc_html__( 'Step %1$d of %2$d', 'ai-command-center' ),
				(int) ( null === $wpcc_active_step ? count( $wpcc_steps ) : $wpcc_active_step + 1 ),
				(int) count( $wpcc_steps )
			);
			?>
		</p>

		<ol class="wpcc-setup__steps">
			<?php foreach ( $wpcc_steps as $wpcc_i => $wpcc_step ) : ?>
				<?php
				$wpcc_is_active = ( $wpcc_i === $wpcc_active_step );
				$wpcc_state     = $wpcc_step['done'] ? 'is-done' : ( $wpcc_is_active ? 'is-active' : 'is-todo' );
				?>
				<li class="wpcc-setup__step <?php echo esc_attr( $wpcc_state ); ?>">
					<span class="wpcc-setup__marker" aria-hidden="true"><?php echo $wpcc_step['done'] ? '&#10003;' : (int) ( $wpcc_i + 1 ); ?></span>
					<div class="wpcc-setup__body">
						<h3 class="wpcc-setup__step-title"><?php echo esc_html( $wpcc_step['title'] ); ?></h3>
						<p class="wpcc-setup__step-text">
							<?php echo esc_html( $wpcc_step['done'] ? $wpcc_step['donce'] : $wpcc_step['body'] ); ?>
						</p>
						<?php if ( $wpcc_is_active && ! empty( $wpcc_step['prompt'] ) ) : ?>
							<p class="wpcc-setup__prompt">
								<span class="wpcc-setup__prompt-label"><?php esc_html_e( 'Try asking:', 'ai-command-center' ); ?></span>
								<code>“<?php echo esc_html( $wpcc_step['prompt'] ); ?>”</code>
							</p>
							<p class="wpcc-setup__note"><?php echo esc_html( $wpcc_step['note'] ); ?></p>
						<?php elseif ( $wpcc_is_active ) : ?>
							<a class="button button-primary button-hero wpcc-setup__cta" href="<?php echo esc_url( $wpcc_step['url'] ); ?>">
								<?php echo esc_html( $wpcc_step['cta'] ); ?>
							</a>
						<?php endif; ?>
					</div>
					<span class="screen-reader-text"><?php echo $wpcc_step['done'] ? esc_html__( '(done)', 'ai-command-center' ) : esc_html__( '(to do)', 'ai-command-center' ); ?></span>
				</li>
			<?php endforeach; ?>
		</ol>

		<details class="wpcc-home__limits wpcc-setup__limits">
			<summary><?php esc_html_e( 'What it does not do', 'ai-command-center' ); ?></summary>
			<ul>
				<li><?php esc_html_e( 'Not everything can be undone. Plugin and theme updates are not automatically reversible, and some areas — WooCommerce orders, for example — have no undo at all. You are told which is which before you approve.', 'ai-command-center' ); ?></li>
				<li><?php esc_html_e( 'It is not a backup tool. It records and reverses individual changes; it does not take full-site backups. Keep your usual backups.', 'ai-command-center' ); ?></li>
				<li><?php esc_html_e( 'It manages this one site, not a fleet.', 'ai-command-center' ); ?></li>
				<li><?php esc_html_e( 'No AI runs on this site unless you connect an assistant. Nothing is sent anywhere until you do.', 'ai-command-center' ); ?></li>
			</ul>
		</details>
	</section>

<?php else : ?>
	<?php
	/*
	 * ── SET UP ────────────────────────────────────────────────────────────────
	 * Now a dashboard earns its place, because every number on it is real.
	 */
	?>
	<?php
	/*
	 * The value sentence now lives under the brand, where Approvals, Changes and
	 * Settings all put theirs. It used to float in the canvas above the tiles with
	 * no heading over it, which read as a stray paragraph — and once the customer
	 * is connected it also said the same thing as the "Your assistant is ready"
	 * card directly beneath it. One statement, in the one place the product always
	 * puts it, is worth more than two in two styles.
	 */
	?>
	<h2 class="screen-reader-text"><?php esc_html_e( 'Site status', 'ai-command-center' ); ?></h2>
	<div class="wpcc-home__status">
		<div class="wpcc-home__stat">
			<span class="wpcc-home__stat-label"><?php esc_html_e( 'Protection', 'ai-command-center' ); ?></span>
			<a class="wpcc-home__stat-value" href="<?php echo esc_url( $links['security'] ); ?>">
				<span class="wpcc-home__dot <?php echo $wpcc_protected ? 'is-ok' : 'is-warn'; ?>" aria-hidden="true"></span>
				<?php echo esc_html( SecurityModeManager::label() ); ?>
			</a>
		</div>
		<div class="wpcc-home__stat">
			<span class="wpcc-home__stat-label"><?php esc_html_e( 'Assistant', 'ai-command-center' ); ?></span>
			<a class="wpcc-home__stat-value" href="<?php echo esc_url( $links['connect'] ); ?>">
				<span class="wpcc-home__dot <?php echo ConnectionStatus::STATE_CONNECTED === $wpcc_conn['state'] ? 'is-ok' : 'is-warn'; ?>" aria-hidden="true"></span>
				<?php echo esc_html( $wpcc_conn['label'] ); ?>
			</a>
			<span class="wpcc-home__stat-hint">
				<?php
				/*
				 * WHICH assistant, not just that there is one.
				 *
				 * "Assistant connected · Last request 28 minutes ago" is true and
				 * still leaves the obvious question unanswered on a site with more
				 * than one token: connected to *what*? The name of the token that
				 * last authenticated answers it from data already read — and since
				 * the token form pre-fills that name with the assistant the
				 * customer picked, in practice it reads "Claude Desktop".
				 *
				 * It is the name they gave, not a detected client. Nothing here
				 * inspects a user agent, and a customer who named a token "staging
				 * laptop" sees "staging laptop", which is the truthful answer to
				 * "which one of mine is that?".
				 */
				echo esc_html(
					'' !== $wpcc_conn['last_label']
						? sprintf(
							/* translators: 1: the name the customer gave the token that last connected, e.g. "Claude Desktop"; 2: sentence about when, e.g. "Last request 28 minutes ago." */
							__( '%1$s · %2$s', 'ai-command-center' ),
							$wpcc_conn['last_label'],
							$wpcc_conn['detail']
						)
						: $wpcc_conn['detail']
				);
				?>
			</span>
		</div>
		<div class="wpcc-home__stat">
			<span class="wpcc-home__stat-label"><?php esc_html_e( 'Waiting for you', 'ai-command-center' ); ?></span>
			<a class="wpcc-home__stat-value" href="<?php echo esc_url( $links['approvals'] ); ?>" id="wpcc-home-pending-stat">
				<span class="wpcc-home__dot is-idle" aria-hidden="true"></span>
				<span id="wpcc-home-pending-text"><?php esc_html_e( 'Checking…', 'ai-command-center' ); ?></span>
			</a>
		</div>
	</div>

	<?php if ( ! $wpcc_protected ) : ?>
		<section class="wpcc-home__next" aria-labelledby="wpcc-home-next-h">
			<div>
				<h2 id="wpcc-home-next-h"><?php esc_html_e( 'Approvals are turned off', 'ai-command-center' ); ?></h2>
				<p><?php esc_html_e( 'This site applies AI changes with no review step. That is fine for staging, risky for a live site.', 'ai-command-center' ); ?></p>
			</div>
			<a class="button button-primary button-hero" href="<?php echo esc_url( $links['security'] ); ?>"><?php esc_html_e( 'Choose protection', 'ai-command-center' ); ?></a>
		</section>
	<?php endif; ?>
<?php endif; ?>

	<?php
	// Everything below is the running dashboard. It stays hidden until setup is
	// complete: an approvals feed, a change list and a platform-invariants strip
	// are all guaranteed empty on a site no assistant has reached, and an empty
	// dashboard teaches a new customer nothing except that nothing is happening.
	if ( $wpcc_setup_done ) :
	?>
	<!-- Approvals: rendered only when something is actually waiting. -->
	<div id="wpcc-home-attn" role="status" aria-live="polite"></div>

	<?php
	/*
	 * "Your assistant is ready" — the answer to the one question a freshly
	 * connected customer actually has: do I keep working in here, or go back to
	 * Claude/Cursor/ChatGPT? The whole product is driven from the assistant, and
	 * nothing on this page said so; the customer was left on a dashboard with
	 * three green tiles and no next move.
	 *
	 * Deliberately AFTER #wpcc-home-attn in the DOM and in visual weight: when
	 * something is genuinely waiting for a decision, that is the next step, and
	 * this card does not render at all (see shouldShowReady()).
	 *
	 * It disappears on its own from evidence the dashboard already returns — no
	 * new option, route, schema, or "mark as done" control.
	 */
	?>
	<div id="wpcc-home-ready" role="status" aria-live="polite"></div>

	<h2><?php esc_html_e( 'Recent changes', 'ai-command-center' ); ?></h2>
	<div id="wpcc-home-activity" aria-live="polite">
		<div class="wpcc-cds-loading"><span class="spinner is-active" style="float:none;margin:0"></span><?php esc_html_e( 'Loading…', 'ai-command-center' ); ?></div>
	</div>
	<p class="wpcc-home__more">
		<a href="<?php echo esc_url( $links['change_history'] ); ?>"><?php esc_html_e( 'View all changes and undo →', 'ai-command-center' ); ?></a>
	</p>

	<?php if ( $wpcc_show_guide ) : ?>
		<section class="wpcc-home__guide" aria-labelledby="wpcc-home-guide-h">
			<h2 id="wpcc-home-guide-h"><?php esc_html_e( 'How this keeps you in control', 'ai-command-center' ); ?></h2>
			<ol class="wpcc-home__steps">
				<li><strong><?php esc_html_e( 'You ask', 'ai-command-center' ); ?></strong><?php esc_html_e( 'Tell your assistant what you want changed, in your own words.', 'ai-command-center' ); ?></li>
				<?php // Mode-aware: this sat directly under a banner reading "Approvals are turned off". ?>
				<li><strong><?php esc_html_e( 'You approve', 'ai-command-center' ); ?></strong><?php echo esc_html( \WPCommandCenter\Operations\SecurityModeManager::approval_step() ); ?> <a href="<?php echo esc_url( $links['approvals'] ); ?>"><?php esc_html_e( 'Approvals →', 'ai-command-center' ); ?></a></li>
				<li><strong><?php esc_html_e( 'It is recorded', 'ai-command-center' ); ?></strong><?php esc_html_e( 'Every change is logged with who made it and when.', 'ai-command-center' ); ?></li>
				<li><strong><?php esc_html_e( 'You can undo', 'ai-command-center' ); ?></strong><?php esc_html_e( 'Supported changes can be undone. An undo is a change too, so it follows the same approval rules.', 'ai-command-center' ); ?> <a href="<?php echo esc_url( $links['change_history'] ); ?>"><?php esc_html_e( 'Changes →', 'ai-command-center' ); ?></a></li>
			</ol>

			<details class="wpcc-home__limits">
				<summary><?php esc_html_e( 'What it does not do', 'ai-command-center' ); ?></summary>
				<ul>
					<li><?php esc_html_e( 'Not everything can be undone. Plugin and theme updates are not automatically reversible, and some areas — WooCommerce orders, for example — have no undo at all. You are told which is which before you approve.', 'ai-command-center' ); ?></li>
					<li><?php esc_html_e( 'It is not a backup tool. It records and reverses individual changes; it does not take full-site backups. Keep your usual backups.', 'ai-command-center' ); ?></li>
					<li><?php esc_html_e( 'It manages this one site, not a fleet.', 'ai-command-center' ); ?></li>
					<li><?php esc_html_e( 'No AI runs on this site unless you connect an assistant. Nothing is sent anywhere until you do.', 'ai-command-center' ); ?></li>
				</ul>
			</details>

			<?php if ( ! $wpcc_incomplete ) : ?>
				<form method="post" class="wpcc-home__dismiss">
					<?php wp_nonce_field( 'wpcc_firstrun' ); ?>
					<button type="submit" name="wpcc_firstrun_action" value="dismiss" class="button button-small"><?php esc_html_e( 'Hide this', 'ai-command-center' ); ?></button>
				</form>
			<?php endif; ?>
		</section>
	<?php elseif ( $wpcc_fr_dismissed ) : ?>
		<form method="post" class="wpcc-home__dismiss">
			<?php wp_nonce_field( 'wpcc_firstrun' ); ?>
			<button type="submit" name="wpcc_firstrun_action" value="reopen" class="button-link"><?php esc_html_e( 'Show how this works', 'ai-command-center' ); ?></button>
		</form>
	<?php endif; ?>

	<?php
	/*
	 * WHAT ELSE IS INCLUDED — the discoverability fix.
	 *
	 * Built-in AI sits five levels down (Settings > Advanced > Built-in AI > Providers >
	 * the tool), so in practice almost nobody found it. Everything below exists and is
	 * paid for; none of it was visible from the one screen people actually open.
	 *
	 * The wording is deliberately conditional. On a stock install the SEO, Alt Text and
	 * Content tools are switched OFF — build-flagged and option-gated — so this must read
	 * as "the plugin can also do this, here is where to switch it on", never as a list of
	 * things already running. Advertising a capability the site cannot currently perform
	 * is the exact defect this release already fixed once in the operation catalogue; it
	 * is not being reintroduced in the UI.
	 */
	?>
	<h2><?php esc_html_e( 'Also included', 'ai-command-center' ); ?></h2>
	<p class="wpcc-home__also-lede"><?php esc_html_e( 'Optional extras that are part of the plugin. Nothing here is switched on unless you choose it.', 'ai-command-center' ); ?></p>
	<div class="wpcc-home__also">
		<?php
		/*
		 * Built-in AI — four genuinely different situations, and the card used to
		 * render one sentence for all of them.
		 *
		 * The states are not decoration; each has a different next action, and
		 * getting them the wrong way round wastes the customer's time in a
		 * specific way. "Tools on, no key" sends someone to switch on a tool they
		 * already switched on; "key, no tools on" sends someone to add a key they
		 * already added. Both were reachable before, because the card could not
		 * tell the two apart.
		 */
		$wpcc_bai_link  = admin_url( 'admin.php?page=wpcc-settings&wpcc_tab=advanced&apane=ai' );
		$wpcc_bai_total = count( \WPCommandCenter\Admin\BuiltinAiSettings::tools() );
		$wpcc_bai_count = count( $wpcc_home_tools_on );
		$wpcc_bai_state = '';
		$wpcc_bai_tone  = 'off';

		/*
		 * "X of 3 tools on" rather than a list of names.
		 *
		 * The earlier wording — "SEO and Content on" / "Key ready · no tools on" —
		 * named things but never said how many there were to have. "0 of 3" tells
		 * a first-time customer two things at once: nothing is running, and there
		 * are three of them to choose from. That second fact is the invitation,
		 * and a bare "no tools on" threw it away.
		 *
		 * The names are not lost — they are one click away on the screen this card
		 * links to, which is where acting on them happens. Repeating them here
		 * would be the duplicated information this pass is meant to remove.
		 */
		$wpcc_bai_ratio = sprintf(
			/* translators: 1: how many built-in AI tools are switched on; 2: how many exist in total. */
			__( '%1$s of %2$s tools on', 'ai-command-center' ),
			number_format_i18n( $wpcc_bai_count ),
			number_format_i18n( $wpcc_bai_total )
		);

		if ( $wpcc_home_tools_on && $wpcc_home_ai_key ) {
			$wpcc_bai_tone  = 'on';
			$wpcc_bai_state = $wpcc_bai_ratio;
			$wpcc_bai_cta   = __( 'Open Built-in AI →', 'ai-command-center' );
		} elseif ( $wpcc_home_tools_on ) {
			// Switched on and unable to generate — the one state worth flagging.
			$wpcc_bai_tone  = 'warn';
			$wpcc_bai_state = __( 'Needs a provider key', 'ai-command-center' );
			$wpcc_bai_cta   = __( 'Add a provider key →', 'ai-command-center' );
		} elseif ( $wpcc_home_ai_key ) {
			// A key on its own generates nothing. Say both halves, so the customer
			// is not left wondering why a "ready" provider produces no output.
			$wpcc_bai_state = sprintf(
				/* translators: %s: e.g. "0 of 3 tools on". */
				__( 'Provider ready · %s', 'ai-command-center' ),
				$wpcc_bai_ratio
			);
			$wpcc_bai_cta = __( 'Turn on a tool →', 'ai-command-center' );
		} else {
			$wpcc_bai_state = __( 'Not set up', 'ai-command-center' );
			$wpcc_bai_cta   = __( 'Set up Built-in AI →', 'ai-command-center' );
		}

		/*
		 * Land on the switches, not the top of a long screen.
		 *
		 * When the next action is "turn one on", the destination is the tool
		 * toggles — which sit below a hero, a two-path explainer and the provider
		 * cards. `#wpcc-bai-tools-h` is the heading the toggles already carry, and
		 * the receiving screen now spotlights an incoming hash target on load.
		 */
		if ( ! $wpcc_home_tools_on && $wpcc_home_ai_key ) {
			$wpcc_bai_link .= '#wpcc-bai-tools-h';
		}
		?>
		<a class="wpcc-home__also-card" href="<?php echo esc_url( $wpcc_bai_link ); ?>">
			<strong><?php esc_html_e( 'Built-in AI', 'ai-command-center' ); ?></strong>
			<span class="wpcc-home__also-state is-<?php echo esc_attr( $wpcc_bai_tone ); ?>"><?php echo esc_html( $wpcc_bai_state ); ?></span>
			<span><?php esc_html_e( 'Let the plugin write SEO descriptions, image alt text and draft content by itself, without opening an assistant. Needs your own AI provider key, and only for the tools you switch on.', 'ai-command-center' ); ?></span>
			<em><?php echo esc_html( $wpcc_bai_cta ); ?></em>
		</a>
		<a class="wpcc-home__also-card" href="<?php echo esc_url( $links['change_history'] ); ?>">
			<strong><?php esc_html_e( 'Undo any change', 'ai-command-center' ); ?></strong>
			<?php
			/*
			 * The third card's state — filled in by script, not by a query.
			 *
			 * Its two neighbours state what this site is doing; this one described
			 * a feature and stopped, which made it the odd card in a row of three
			 * and left "is there anything to undo?" unanswered.
			 *
			 * The honest count lives behind a `COUNT(*)`, and adding one to every
			 * Home render to fill a pill would be the wrong trade. It does not need
			 * one: `change_history.changes` is ALREADY in the `/admin/dashboard`
			 * response this page fetches for the activity list, on the same request,
			 * so the number is free — it was simply never read out of the payload.
			 *
			 * Hidden until it has a real answer, so it never flashes a guess. A
			 * gated response leaves it hidden for good rather than showing a zero
			 * the customer has no permission to verify.
			 */
			?>
			<span class="wpcc-home__also-state" id="wpcc-home-undo-state" hidden></span>
			<span><?php esc_html_e( 'Every change is recorded with who made it and when. Supported changes can be put back exactly as they were, and the undo follows the same approval rules.', 'ai-command-center' ); ?></span>
			<em><?php esc_html_e( 'See what changed →', 'ai-command-center' ); ?></em>
		</a>
		<?php
		/*
		 * Access tokens — the count is already in hand.
		 *
		 * $wpcc_conn was resolved at the top of this view for the status strip and
		 * carries `active_tokens`, counted the way validate() actually counts
		 * (usable, so an expired token is not advertised as live access). Saying
		 * "2 active tokens" instead of nothing turns a card describing a feature
		 * into a card describing this site, and the CTA stops saying "manage" to
		 * someone with nothing to manage.
		 */
		$wpcc_home_tokens = (int) $wpcc_conn['active_tokens'];
		$wpcc_home_ro     = (int) $wpcc_conn['read_only_tokens'];

		/*
		 * A count alone was actively misleading under THIS heading.
		 *
		 * The card is titled "Read-only access" and its copy pitches a token that
		 * "can never change anything". Under that, "3 active tokens" reads as
		 * three read-only assistants — and on the site this was written against,
		 * all three were FULL access. That is not a wording nit: it invites a
		 * customer to believe their site is safer than it is, which is the one
		 * direction a safety-adjacent card must never be wrong in.
		 *
		 * So the pill states the composition. Both halves are read from the same
		 * records the count came from; `scope` has always been stored.
		 *
		 * Deliberately NOT coloured as a warning when every token is full access.
		 * Full access is a legitimate, deliberate choice — the product offers it
		 * and defaults away from it. Stating it plainly is honest; painting it
		 * amber would editorialise a supported configuration as a fault.
		 */
		?>
		<a class="wpcc-home__also-card" href="<?php echo esc_url( $links['access'] ); ?>">
			<strong><?php esc_html_e( 'Read-only access', 'ai-command-center' ); ?></strong>
			<span class="wpcc-home__also-state is-<?php echo $wpcc_home_ro > 0 ? 'on' : 'off'; ?>">
				<?php
				if ( 0 === $wpcc_home_tokens ) {
					esc_html_e( 'No tokens yet', 'ai-command-center' );
				} elseif ( $wpcc_home_ro > 0 ) {
					echo esc_html(
						sprintf(
							/* translators: 1: total active access tokens; 2: how many of them are read-only. */
							_n( '%1$s active token · %2$s read-only', '%1$s active tokens · %2$s read-only', $wpcc_home_tokens, 'ai-command-center' ),
							number_format_i18n( $wpcc_home_tokens ),
							number_format_i18n( $wpcc_home_ro )
						)
					);
				} else {
					/*
					 * The noun stays in. "3 active · all full access" drops the thing
					 * being counted, and under a heading reading "Read-only access"
					 * the missing noun is exactly the word that stops a customer
					 * reading the number as "3 read-only assistants".
					 */
					echo esc_html(
						sprintf(
							/* translators: %s: number of active access tokens, none of which are read-only. */
							_n( '%s active token · full access', '%s active tokens · all full access', $wpcc_home_tokens, 'ai-command-center' ),
							number_format_i18n( $wpcc_home_tokens )
						)
					);
				}
				?>
			</span>
			<span><?php esc_html_e( 'Give an assistant a token that can answer questions about the site but can never change anything — useful for trying one out safely.', 'ai-command-center' ); ?></span>
			<em>
				<?php
				/*
				 * The next action follows the card's own purpose. A site with three
				 * full-access tokens and no read-only one is precisely the site
				 * this card is pitching to, so "Manage" — a filing action — is the
				 * wrong verb. It offers the thing the card is about.
				 */
				if ( 0 === $wpcc_home_tokens ) {
					esc_html_e( 'Create an access token →', 'ai-command-center' );
				} elseif ( 0 === $wpcc_home_ro ) {
					esc_html_e( 'Add a read-only token →', 'ai-command-center' );
				} else {
					esc_html_e( 'Manage access tokens →', 'ai-command-center' );
				}
				?>
			</em>
		</a>
	</div>

	<!-- Engineering detail: present, never in the way. -->
	<div class="wpcc-engineer-only">
		<h2><?php esc_html_e( 'Platform invariants', 'ai-command-center' ); ?></h2>
		<div id="wpcc-home-invariants" class="wpcc-cds-kpis" role="status" aria-live="polite"></div>
		<p class="wpcc-home__more">
			<a href="<?php echo esc_url( $links['advanced'] ); ?>"><?php esc_html_e( 'Capabilities & operation map →', 'ai-command-center' ); ?></a>
			&nbsp;·&nbsp;
			<a href="<?php echo esc_url( $links['diagnostics'] ); ?>"><?php esc_html_e( 'Diagnostics →', 'ai-command-center' ); ?></a>
		</p>
	</div>
	<?php endif; // dashboard (setup complete) ?>
</div>

<style>
/* The reading column is capped for line length, but it is centred against the
   full canvas rather than pinned to the left of it. Left-pinned, a 620px setup
   column inside a 900px block inside a 1200px shell left ~500px of dead space on
   one side only, which reads as a layout mistake rather than as composition. */
.wpcc-home { max-width: 940px; margin-inline: auto; }
/* ── Setup flow (pre-connection Home) ───────────────────────────────────────
 * Deliberately narrow and vertical: one column, one reading path, one button.
 * A grid would invite the eye to wander across choices the customer has not
 * earned yet. */
.wpcc-setup { max-width: 620px; margin: 24px auto 56px; }
.wpcc-setup__prompt { margin: 12px 0 6px; font-size: 13px; }
.wpcc-setup__prompt-label { color: #646970; margin-right: 6px; }
.wpcc-setup__prompt code { background: #f0f0f1; padding: 3px 8px; border-radius: 4px; font-size: 13px; }
.wpcc-setup__note { margin: 6px 0 0; font-size: 12px; color: #646970; }
/* The first-run lockup, sized by HEIGHT so the wordmark lands at a known size.
   It used to be capped by width (max-width:248px) against an artwork whose 456px
   viewBox was sized by a tagline, not by the name — so the whole lockup rendered
   at 0.54x and "WP Command Center" came out at 12px: smaller than the 13px body
   text beneath it, and the smallest type on the screen that introduces the
   product. The tagline is gone from the artwork and the viewBox is retightened to
   244x32, so height:32px now renders the wordmark at its intended 20px with the
   mark at 24px. max-width keeps it inside very narrow columns. */
.wpcc-setup__logo { display:block; width:auto; height:32px; max-width:100%; margin:0 0 18px; }
@media (max-width: 480px) { .wpcc-setup__logo { height:28px; } }
/* First-run is the one screen that earns a real headline. The CDS type scale
   caps h2 at 16px for dense operator screens, which is right everywhere else
   and wrong here — this is the product introducing itself. Scoped override. */
.wpcc-app .wpcc-setup__title { font-size: 27px; line-height: 1.22; margin: 0 0 12px; letter-spacing: -0.02em; font-weight: 650; }
/* max-width caps the measure so the lede stays readable; the rest is unchanged. */
.wpcc-setup__lede { font-size: 15px; line-height: 1.6; color: #50575e; margin: 0 0 24px; max-width: 34em; }
.wpcc-setup__protection { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin:0 0 22px; padding:10px 14px; background:#f6f7f7; border-radius:8px; font-size:13px; color:#1d2327; }
.wpcc-setup__protection a { margin-left:auto; font-size:12px; }
.wpcc-setup__protection-dot { width:8px; height:8px; border-radius:50%; background:#c3c4c7; flex:0 0 auto; }
.wpcc-setup__protection-dot.is-ok { background:#00a32a; }
.wpcc-setup__protection-dot.is-warn { background:#dba617; }
.wpcc-setup__progress { font-size: 12px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: #646970; margin: 0 0 12px; }
.wpcc-setup__steps { list-style: none; margin: 0; padding: 0; counter-reset: none; }
.wpcc-setup__step { position: relative; display: flex; gap: 14px; padding: 18px 0; border-top: 1px solid #dcdcde; }
.wpcc-setup__step:last-child { border-bottom: 1px solid #dcdcde; }
.wpcc-setup__marker { flex: 0 0 auto; display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; border-radius: 999px; font-size: 13px; font-weight: 600; background: #f0f0f1; color: #646970; }
.wpcc-setup__step.is-done .wpcc-setup__marker { background: #edfaef; color: #00a32a; }
.wpcc-setup__step.is-active .wpcc-setup__marker { background: #2271b1; color: #fff; }
.wpcc-setup__body { flex: 1; min-width: 0; }
.wpcc-setup__step-title { margin: 2px 0 4px; font-size: 15px; font-weight: 600; }
.wpcc-setup__step.is-todo .wpcc-setup__step-title { color: #646970; font-weight: 500; }
.wpcc-setup__step-text { margin: 0; font-size: 13px; line-height: 1.6; color: #50575e; }
.wpcc-setup__step.is-done .wpcc-setup__step-text { color: #00a32a; }
.wpcc-setup__cta { margin-top: 14px; }
.wpcc-setup__limits { margin-top: 24px; }

.wpcc-home__lede { max-width: 640px; margin: 0 0 24px; font-size: 15px; line-height: 1.6; color: #1d2327; }
/* Equal thirds. auto-fit let the first tile absorb the slack, so the three
   status cells came out 468/312/312 — an uneven rhythm on the first thing
   anyone looks at. They carry equally important facts; they should read as
   equals. Falls back to stacking below 640px. */
.wpcc-home__status { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1px; background: #dcdcde; border: 1px solid #dcdcde; border-radius: 8px; overflow: hidden; margin: 0 0 24px; }
@media (max-width: 640px) { .wpcc-home__status { grid-template-columns: 1fr; } }
.wpcc-home__stat { background: #fff; padding: 14px 18px; }
.wpcc-home__stat-label { display: block; font-size: 12px; color: #646970; margin-bottom: 4px; }
.wpcc-home__stat-value { display: block; font-size: 14px; font-weight: 600; color: #1d2327; text-decoration: none; }
.wpcc-home__stat-value:hover { color: #2271b1; }
.wpcc-home__stat-hint { display: block; font-size: 12px; color: #646970; margin-top: 3px; }
.wpcc-home__dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 6px; vertical-align: middle; background: #c3c4c7; }
.wpcc-home__dot.is-ok { background: #00a32a; }
.wpcc-home__dot.is-warn { background: #dba617; }
.wpcc-home__dot.is-idle { background: #c3c4c7; }
.wpcc-home__next { display: flex; justify-content: space-between; align-items: center; gap: 20px; flex-wrap: wrap; padding: 20px 22px; margin: 0 0 24px; background: #f0f6fc; border: 1px solid #c5d9ee; border-radius: 8px; }
.wpcc-home__next h2 { margin: 0 0 4px; font-size: 16px; }
.wpcc-home__next p { margin: 0; color: #50575e; font-size: 13px; max-width: 520px; }
/* "Your assistant is ready". Quieter than .wpcc-home__next (which is a call to
   fix something) and quieter than the approval alert: this is guidance, not an
   alarm, so it sits on the plain card surface with no accent fill. */
.wpcc-home__ready { padding: 18px 20px; margin: 0 0 22px; background: var( --wpcc-surface-card, #fff ); border: 1px solid var( --wpcc-border-subtle, #dcdcde ); border-radius: var( --wpcc-r-card, 8px ); }
.wpcc-app .wpcc-home__ready-title { margin: 0 0 8px; font-size: 18px; font-weight: 650; letter-spacing: -.01em; }
.wpcc-home__ready-body { margin: 0 0 12px; max-width: 620px; font-size: var( --wpcc-fs-body, 13px ); color: var( --wpcc-text-secondary, #50575e ); }
.wpcc-home__ready-try { margin: 0 0 6px; font-size: var( --wpcc-fs-small, 12px ); font-weight: 600; color: var( --wpcc-text-muted, #646970 ); }
.wpcc-home__ready-prompts { margin: 0 0 14px; padding: 0; list-style: none; display: grid; gap: 6px; }
.wpcc-home__ready-prompts code { display: block; padding: 9px 13px; background: var( --wpcc-surface-sunken, #f6f7f7 ); border: 1px solid var( --wpcc-border-subtle, #dcdcde ); border-radius: 7px; font-family: inherit; font-size: 13.5px; line-height: 1.5; color: var( --wpcc-text-primary, #1d2327 ); }
.wpcc-home__ready-actions { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
.wpcc-home__ready-link { font-size: var( --wpcc-fs-body, 13px ); }
.wpcc-home__ready-ok { font-size: var( --wpcc-fs-small, 12px ); font-weight: 600; color: var( --wpcc-state-success-fg, #00a32a ); }
.wpcc-home__ready-ok[hidden] { display: none; }
.wpcc-home__also-lede { font-size: 13px; color: #50575e; margin: 0 0 12px; }
.wpcc-home__also { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px; margin: 0 0 26px; }
.wpcc-home__also-card { display: block; padding: 15px 17px; background: #fff; border: 1px solid #dcdcde; border-radius: 10px; text-decoration: none; box-shadow: 0 1px 2px rgba(16,24,40,.03); transition: border-color .13s ease, box-shadow .13s ease, transform .13s ease; }
.wpcc-home__also-card:hover { border-color: #c8ccd4; transform: translateY(-1px); box-shadow: 0 1px 2px rgba(16,24,40,.04), 0 6px 16px rgba(16,24,40,.06); }
.wpcc-home__also-card:focus-visible { outline: 2px solid #2271b1; outline-offset: 2px; }
.wpcc-home__also-card strong { display: block; font-size: 14px; color: #1d2327; margin-bottom: 5px; }
.wpcc-home__also-card span { display: block; font-size: 12.5px; line-height: 1.55; color: #50575e; margin-bottom: 9px; }
.wpcc-home__also-card em { font-style: normal; font-size: 12.5px; font-weight: 600; color: #2271b1; }
/* What this site is actually doing, as opposed to what the card describes.
   `display:inline-block` and the tighter margin deliberately override the
   generic `.wpcc-home__also-card span` block rule above — the pill sits on its
   own line under the title, not as another paragraph of description. */
.wpcc-home__also-state { display: inline-block !important; margin: 0 0 8px !important; padding: 1px 8px;
	border-radius: 999px; font-size: 11px !important; font-weight: 600; line-height: 1.7;
	letter-spacing: .01em; background: #f0f0f1; color: #646970 !important; }
.wpcc-home__also-state.is-on { background: #e7f6ec; color: #0a7a33 !important; }
.wpcc-home__also-state.is-warn { background: #fcf3e3; color: #8a5700 !important; }
/* The `!important` above (needed to beat the generic `.wpcc-home__also-card span`
   block rule) also beats the UA stylesheet's `[hidden] { display: none }`, which
   is NOT important — so the script-filled pill would render as an empty chip
   before its data arrives. Restore `hidden` at the same weight. */
.wpcc-home__also-state[hidden] { display: none !important; }
.wpcc-home__more { font-size: 13px; margin: 10px 0 26px; }
.wpcc-home__guide { padding: 20px 22px; background: #fff; border: 1px solid #dcdcde; border-radius: 8px; margin: 8px 0 24px; }
.wpcc-home__guide h2 { margin: 0 0 14px; } /* size comes from the type scale */
.wpcc-home__steps { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; counter-reset: wpcc-step; }
.wpcc-home__steps li { font-size: 13px; color: #50575e; line-height: 1.5; counter-increment: wpcc-step; }
.wpcc-home__steps strong { display: block; color: #1d2327; margin-bottom: 2px; }
.wpcc-home__steps strong::before { content: counter(wpcc-step) ". "; color: #2271b1; }
.wpcc-home__limits { margin-top: 16px; border-top: 1px solid #f0f0f1; padding-top: 12px; }
.wpcc-home__limits summary { cursor: pointer; font-size: 13px; font-weight: 600; }
.wpcc-home__limits ul { margin: 10px 0 0 18px; color: #50575e; font-size: 13px; line-height: 1.6; }
.wpcc-home__dismiss { margin: 14px 0 24px; }
</style>

<script>
(function () {
	var WPCC     = window.WPCC || {};
	var nonce    = <?php echo wp_json_encode( $nonce ); ?>;
	var apiBase  = <?php echo wp_json_encode( $api_base ); ?>;
	var links    = <?php echo wp_json_encode( $links ); ?>;
	var sessBase = <?php echo wp_json_encode( $session_base ); ?>;
	/* Brand mark for the product's own empty state (see WPCC.cds.empty). */
	var brandMark = <?php echo wp_json_encode( Brand::mark() ); ?>;
	/* Whether approvals are on, so the attention banner can explain a queue that
	   outlived them being switched off. See i18n.attnQueuedBefore. */
	var isProtected = <?php echo wp_json_encode( (bool) $wpcc_protected ); ?>;

	var i18n = {
		loadFail:    <?php echo wp_json_encode( __( 'Could not load. Your admin session may have expired — refresh and try again.', 'ai-command-center' ) ); ?>,
		tJustNow:    <?php echo wp_json_encode( __( 'Just now', 'ai-command-center' ) ); ?>,
		/* translators: %d: number of minutes */
		tMinutes:    <?php echo wp_json_encode( /* translators: %d: number */ __( '%d min ago', 'ai-command-center' ) ); ?>,
		/* translators: %d: number of hours */
		tHours:      <?php echo wp_json_encode( /* translators: %d: number */ __( '%d hr ago', 'ai-command-center' ) ); ?>,
		/* translators: %d: number of days */
		tDays:       <?php echo wp_json_encode( /* translators: %d: number */ __( '%d days ago', 'ai-command-center' ) ); ?>,
		attnTitle:   <?php echo wp_json_encode( __( 'Waiting for your approval', 'ai-command-center' ) ); ?>,
		attnTitleFailed: <?php echo wp_json_encode( __( 'Something did not run', 'ai-command-center' ) ); ?>,
		/* translators: %d: number of pending approvals */
		attnPending:  <?php echo wp_json_encode( /* translators: %d: number */ __( '%d changes need your decision before they can run.', 'ai-command-center' ) ); ?>,
		attnPending1: <?php echo wp_json_encode( __( '1 change needs your decision before it can run.', 'ai-command-center' ) ); ?>,
		/* translators: %d: number of failed queue items */
		attnFailed:  <?php echo wp_json_encode( /* translators: %d: number */ __( '%d requests failed to run.', 'ai-command-center' ) ); ?>,
		attnFailed1: <?php echo wp_json_encode( __( '1 request failed to run.', 'ai-command-center' ) ); ?>,
		/*
		 * Shown only when approvals are OFF and a queue still exists — the one
		 * combination on this screen that reads as a contradiction. "Approvals are
		 * turned off" and "50 changes need your decision" are both true and sit two
		 * inches apart, and nothing said why. Turning approvals off governs what
		 * happens NEXT; it does not release what was already queued, and it is not
		 * obvious that a setting is not retroactive. One sentence, only in the state
		 * that needs it.
		 */
		attnQueuedBefore: <?php echo wp_json_encode( __( 'These were queued while approvals were still on. Turning approvals off does not release them — they wait for you either way.', 'ai-command-center' ) ); ?>,
		attnReview:  <?php echo wp_json_encode( __( 'Review now', 'ai-command-center' ) ); ?>,
		attnSeeFailed: <?php echo wp_json_encode( __( 'See what failed', 'ai-command-center' ) ); ?>,
		pendingNone: <?php echo wp_json_encode( __( 'Nothing', 'ai-command-center' ) ); ?>,
		/* translators: %d: number of pending approvals */
		pendingSome:  <?php echo wp_json_encode( /* translators: %d: number */ __( '%d approvals', 'ai-command-center' ) ); ?>,
		pendingSome1: <?php echo wp_json_encode( __( '1 approval', 'ai-command-center' ) ); ?>,
		readyTitle:  <?php echo wp_json_encode( __( 'Your assistant is ready', 'ai-command-center' ) ); ?>,
		readyBody:   <?php echo wp_json_encode( __( 'Go back to Claude, Codex, ChatGPT, or whichever assistant you connected, and describe what you want changed on this site — in your own words, in any language your assistant speaks. Requests arrive here under the same protection, approval and undo rules.', 'ai-command-center' ) ); ?>,
		readyTryLbl: <?php echo wp_json_encode( __( 'Try asking:', 'ai-command-center' ) ); ?>,
		/* A read-only question: it answers instantly and changes nothing, so the
		   first thing a customer tries cannot go wrong. */
		readyPrompt: <?php echo wp_json_encode( __( 'What plugins are installed on this site?', 'ai-command-center' ) ); ?>,
		/* The change-shaped example, which will come back here for approval. */
		readyPrompt2: <?php echo wp_json_encode( __( 'Change my site tagline to “Handmade ceramics from Lisbon”.', 'ai-command-center' ) ); ?>,
		readyCopy:   <?php echo wp_json_encode( __( 'Copy starter prompt', 'ai-command-center' ) ); ?>,
		readyCopied: <?php echo wp_json_encode( __( 'Copied', 'ai-command-center' ) ); ?>,
		readyGuide:  <?php echo wp_json_encode( __( 'View connection guide', 'ai-command-center' ) ); ?>,
		/* The "Undo any change" card's state pill. Real counts from the dashboard
		   read that is already in flight — never a placeholder or an estimate. */
		undoNone:    <?php echo wp_json_encode( __( 'Nothing to undo yet', 'ai-command-center' ) ); ?>,
		undoOne:     <?php echo wp_json_encode( __( '1 change recorded', 'ai-command-center' ) ); ?>,
		/* translators: %s: number of recorded changes. */
		undoMany:    <?php echo wp_json_encode( /* translators: %s: number of recorded changes. */ __( '%s changes recorded', 'ai-command-center' ) ); ?>,
		actEmptyTitle:  <?php echo wp_json_encode( __( 'No changes yet', 'ai-command-center' ) ); ?>,
		actEmptyDetail: <?php echo wp_json_encode( __( 'Once your assistant changes something here, it appears in this list — with an undo where the change supports one.', 'ai-command-center' ) ); ?>,
		/* translators: %d: number of changes in a session */
		actChanges:  <?php echo wp_json_encode( /* translators: %d: number */ __( '%d changes', 'ai-command-center' ) ); ?>,
		actChanges1: <?php echo wp_json_encode( __( '1 change', 'ai-command-center' ) ); ?>,
		chipReversible: <?php echo wp_json_encode( __( 'Can be undone', 'ai-command-center' ) ); ?>,
		byLabel:     <?php echo wp_json_encode( __( 'by', 'ai-command-center' ) ); ?>,
		view:        <?php echo wp_json_encode( __( 'View', 'ai-command-center' ) ); ?>,
		invOpMap:    <?php echo wp_json_encode( __( 'Mapped operations', 'ai-command-center' ) ); ?>,
		invCaps:     <?php echo wp_json_encode( __( 'Capabilities', 'ai-command-center' ) ); ?>,
		invCat:      <?php echo wp_json_encode( __( 'Operations', 'ai-command-center' ) ); ?>,
		invMcp:      <?php echo wp_json_encode( __( 'MCP tools', 'ai-command-center' ) ); ?>,
		invDb:       <?php echo wp_json_encode( __( 'DB version', 'ai-command-center' ) ); ?>
	};

	function esc( s ) { return WPCC.escHtml ? WPCC.escHtml( s ) : String( s == null ? '' : s ); }
	function fmt1( tpl, a ) { return String( tpl ).replace( '%d', a ).replace( '%s', a ); }
	/* Picks the singular string when n is exactly 1, so the dashboard never says "1 approval(s)". */
	function fmtN( key, n ) { var one = i18n[ key + '1' ]; return ( 1 === n && one ) ? String( one ) : fmt1( i18n[ key ], n ); }
	function set( id, html ) { var el = document.getElementById( id ); if ( el ) { el.innerHTML = html; } }
	/*
	 * Relative time on the dashboard.
	 *
	 * Five rows each opening with "8/1/2026, 10:35:50 PM" gave the timestamp more
	 * weight than the change, and repeated the same date five times to say
	 * "recently". People reading a recent-activity list think in "a few minutes
	 * ago", not in seconds-precision clock time. The exact timestamp is still one
	 * click away on the Changes screen, and stays in the title attribute here.
	 */
	function fmtTime( s ) {
		if ( ! s ) { return ''; }
		var ts = Number( s ) * 1000;
		if ( ! isFinite( ts ) || ts <= 0 ) { return String( s ); }
		var diff = Math.floor( ( Date.now() - ts ) / 1000 );
		if ( diff < 0 )    { diff = 0; }
		if ( diff < 60 )   { return i18n.tJustNow; }
		if ( diff < 3600 ) { return fmt1( i18n.tMinutes, Math.floor( diff / 60 ) ); }
		if ( diff < 86400 ){ return fmt1( i18n.tHours,   Math.floor( diff / 3600 ) ); }
		if ( diff < 604800 ){ return fmt1( i18n.tDays,   Math.floor( diff / 86400 ) ); }
		try { return new Date( ts ).toLocaleDateString(); } catch ( e ) { return String( s ); }
	}
	function absTime( s ) { try { return new Date( Number( s ) * 1000 ).toLocaleString(); } catch ( e ) { return ''; } }

	/* ── Approvals: silent when there is nothing to do ─────────────────────── */
	function renderAttn( ap ) {
		ap = ap || {};
		var pending = ap.pending || 0;
		var failed  = ap.queue_failed || 0;

		set( 'wpcc-home-pending-text', pending > 0 ? esc( fmtN( 'pendingSome', pending ) ) : esc( i18n.pendingNone ) );
		var stat = document.getElementById( 'wpcc-home-pending-stat' );
		if ( stat ) {
			var dot = stat.querySelector( '.wpcc-home__dot' );
			if ( dot ) { dot.className = 'wpcc-home__dot ' + ( pending > 0 ? 'is-warn' : 'is-ok' ); }
		}

		// An "all clear" banner is noise on a dashboard that already says "Nothing"
		// in the status strip. Show this block only when there is real work.
		if ( pending <= 0 && failed <= 0 ) { set( 'wpcc-home-attn', '' ); return; }

		var bits = [];
		if ( pending > 0 ) { bits.push( esc( fmtN( 'attnPending', pending ) ) ); }
		if ( failed > 0 )  { bits.push( esc( fmtN( 'attnFailed', failed ) ) ); }
		/*
		 * The heading has to match the situation. This block also fires when nothing
		 * is pending and something merely failed to run, and it still announced
		 * "Waiting for your approval" — sending the customer to look for a decision
		 * that did not exist. A failure is news, not a request.
		 */
		var attnTitle = pending > 0 ? i18n.attnTitle : i18n.attnTitleFailed;
		/*
		 * Only when approvals are off AND something is still pending. In every other
		 * state this line would be answering a question nobody asked.
		 */
		var queuedNote = ( ! isProtected && pending > 0 )
			? '<p class="wpcc-cds-attn__detail">' + esc( i18n.attnQueuedBefore ) + '</p>'
			: '';
		set( 'wpcc-home-attn',
			'<div class="wpcc-cds-attn is-action">'
			+ '<span class="wpcc-cds-attn__icon" aria-hidden="true">&#9888;</span>'
			+ '<div class="wpcc-cds-attn__body"><p class="wpcc-cds-attn__title">' + esc( attnTitle ) + '</p>'
			+ '<p class="wpcc-cds-attn__detail">' + bits.join( ' &middot; ' ) + '</p>' + queuedNote + '</div>'
			// Secondary. The page already has one primary action (the next step);
			// two blue buttons competing on a dashboard means neither is the answer.
			+ '<div class="wpcc-cds-attn__actions"><a class="button" href="' + esc( pending > 0 ? links.approvals : links.approvalsQueue ) + '">'
			+ esc( pending > 0 ? i18n.attnReview : i18n.attnSeeFailed ) + ' &rarr;</a></div></div>' );
	}

	/* ── "Your assistant is ready" — onboarding's last mile ────────────────────
	 *
	 * Shown only when ALL of these hold, every one read from the dashboard payload
	 * this page already fetches:
	 *
	 *   1. An assistant has connected.       The whole block is inside the
	 *      `$wpcc_setup_done` branch, so the element does not exist before that.
	 *   2. Nothing is waiting for a decision. pending === 0 && queue_failed === 0.
	 *      A real approval outranks onboarding, so the card yields to it entirely.
	 *   3. No first meaningful request yet.   No change has ever been recorded
	 *      (change_history.changes === 0) AND no approval has ever been decided
	 *      (resolved === 0). Either one means the customer has already done the
	 *      thing this card is teaching.
	 *
	 * The change count matters on its own: in Development mode nothing is ever
	 * approved, so `resolved` stays 0 forever and would keep the card on screen
	 * long after the customer had changed the site. It also cannot be replaced by
	 * the recent-activity rows, which are session-grouped and therefore blank for
	 * changes that arrive without a session_id.
	 *
	 * Condition 3 is why there is no dismiss button: the evidence that the lesson
	 * landed is the customer's own first request. A manual "got it" checkbox would
	 * be a new stored flag that can disagree with reality.
	 */
	function shouldShowReady( ap, hist ) {
		ap   = ap || {};
		hist = hist || {};
		if ( ( ap.pending || 0 ) > 0 || ( ap.queue_failed || 0 ) > 0 ) { return false; }
		if ( ( ap.resolved || 0 ) > 0 ) { return false; }
		return ( hist.changes || 0 ) === 0;
	}

	/*
	 * "Undo any change" — the one card in the row that could not state its own
	 * state, because the count is not cheap enough to fetch on every page render.
	 * It does not have to be: the number arrives in the dashboard read already
	 * running for the activity list.
	 *
	 * Stays hidden on a gated or malformed response. A pill that says "0" because
	 * a permission check failed is worse than no pill: it reads as a fact about
	 * the site rather than a fact about the reader.
	 */
	function renderUndoState( hist ) {
		var el = document.getElementById( 'wpcc-home-undo-state' );
		if ( ! el || ! hist || typeof hist.changes !== 'number' ) { return; }
		var n = hist.changes;
		el.textContent = n === 0
			? i18n.undoNone
			: ( n === 1 ? i18n.undoOne : i18n.undoMany.replace( '%s', n.toLocaleString() ) );
		el.classList.toggle( 'is-on', n > 0 );
		el.hidden = false;
	}

	function renderReady( ap, hist ) {
		var host = document.getElementById( 'wpcc-home-ready' );
		if ( ! host ) { return; }
		if ( ! shouldShowReady( ap, hist ) ) { host.innerHTML = ''; return; }

		host.innerHTML =
			'<section class="wpcc-home__ready" aria-labelledby="wpcc-home-ready-h">'
			+ '<h2 id="wpcc-home-ready-h" class="wpcc-home__ready-title">' + esc( i18n.readyTitle ) + '</h2>'
			+ '<p class="wpcc-home__ready-body">' + esc( i18n.readyBody ) + '</p>'
			+ '<p class="wpcc-home__ready-try">' + esc( i18n.readyTryLbl ) + '</p>'
			+ '<ul class="wpcc-home__ready-prompts">'
			+ '<li><code>' + esc( i18n.readyPrompt ) + '</code></li>'
			+ '<li><code>' + esc( i18n.readyPrompt2 ) + '</code></li>'
			+ '</ul>'
			+ '<div class="wpcc-home__ready-actions">'
			// One primary action. There is deliberately no "Open assistant" button:
			// the product does not know which assistant was configured, and a button
			// that guesses wrong is worse than no button.
			+ '<button type="button" class="button button-primary" id="wpcc-home-ready-copy">' + esc( i18n.readyCopy ) + '</button>'
			+ '<a class="wpcc-home__ready-link" href="' + esc( links.connect ) + '">' + esc( i18n.readyGuide ) + '</a>'
			+ '<span class="wpcc-home__ready-ok" id="wpcc-home-ready-ok" hidden>&#10003; ' + esc( i18n.readyCopied ) + '</span>'
			+ '</div></section>';

		var btn = document.getElementById( 'wpcc-home-ready-copy' );
		if ( btn ) { btn.addEventListener( 'click', function () { copyPrompt( i18n.readyPrompt ); } ); }
	}

	/* Clipboard with a selection fallback for non-secure contexts (plain http
	   admin panels, which are common), and never a modal dialog on failure. */
	function copyPrompt( text ) {
		function done() {
			var ok = document.getElementById( 'wpcc-home-ready-ok' );
			if ( ! ok ) { return; }
			ok.hidden = false;
			window.setTimeout( function () { ok.hidden = true; }, 2400 );
		}
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( text ).then( done ).catch( function () { legacyCopy( text, done ); } );
		} else {
			legacyCopy( text, done );
		}
	}

	function legacyCopy( text, done ) {
		var ta = document.createElement( 'textarea' );
		ta.value = text;
		ta.setAttribute( 'readonly', '' );
		ta.style.position = 'fixed';
		ta.style.opacity = '0';
		document.body.appendChild( ta );
		ta.select();
		try { document.execCommand( 'copy' ); done(); } catch ( e ) { /* leave it selected to copy by hand */ }
		document.body.removeChild( ta );
	}

	/* ── Invariants (Engineer disclosure only) ─────────────────────────────── */
	function renderInvariants( inv ) {
		inv = inv || {};
		set( 'wpcc-home-invariants',
			WPCC.cds.kpi( inv.operation_map, i18n.invOpMap )
			+ WPCC.cds.kpi( inv.capabilities, i18n.invCaps )
			+ WPCC.cds.kpi( inv.catalogue, i18n.invCat )
			+ WPCC.cds.kpi( inv.mcp_tools, i18n.invMcp )
			+ WPCC.cds.kpi( inv.db_version, i18n.invDb ) );
	}

	/* ── Recent changes ───────────────────────────────────────────────────── */
	function actorType( summary ) {
		var s = String( summary || '' ).toLowerCase();
		if ( s.indexOf( 'agent' ) !== -1 || s.indexOf( 'claude' ) !== -1 || s.indexOf( 'ai' ) === 0 ) { return 'agent'; }
		if ( s.indexOf( 'system' ) !== -1 || s.indexOf( 'cron' ) !== -1 || s.indexOf( 'queue' ) !== -1 || s.indexOf( 'workflow' ) !== -1 ) { return 'system'; }
		return 'human';
	}

	function renderActivity( rows ) {
		if ( ! rows || ! rows.length ) {
			set( 'wpcc-home-activity', WPCC.cds.empty( i18n.actEmptyTitle, i18n.actEmptyDetail, brandMark ) );
			return;
		}
		var html = '<ul class="wpcc-cds-timeline">';
		// Five is a glance; the full list is one click away in History.
		rows.slice( 0, 5 ).forEach( function ( r ) {
			/*
			 * Two row shapes reach this list. A SESSION row groups related work
			 * (session_id + change_count); a CHANGE row is a single recorded change
			 * (change_id + headline), used when nothing carries a session_id. Render
			 * whichever arrived rather than showing an empty panel.
			 */
			if ( r.change_id && ! r.session_id ) {
				var cMeta = WPCC.cds.actorChip( actorType( r.actor ), r.actor || '' );
				if ( r.reversible ) { cMeta += WPCC.cds.chip( 'reversible', i18n.chipReversible ); }
				html += '<li class="wpcc-cds-timeline__item">'
					// created_at is a unix timestamp, same as a session's last_at.
					+ '<span class="wpcc-cds-timeline__time" title="' + esc( absTime( r.created_at ) ) + '">' + esc( fmtTime( r.created_at ) ) + '</span>'
					+ '<span class="wpcc-cds-timeline__main">'
					+ '<a href="' + esc( links.change_history ) + '">' + esc( r.headline || i18n.view ) + '</a>'
					+ ( r.area ? ' <span style="color:#646970;">&middot; ' + esc( r.area ) + '</span>' : '' )
					+ '</span>'
					+ '<span class="wpcc-cds-timeline__meta">' + cMeta + '</span></li>';
				return;
			}
			var rev = ( r.reversible_count || 0 ) > 0;
			// `areas` is the plain-language form of `runtimes` ("Posts & pages, SEO").
			// Falling back to runtimes keeps this correct against an older payload.
			var where = ( r.areas && r.areas.length ) ? r.areas.join( ', ' ) : ( r.runtimes || [] ).join( ', ' );
			var meta = WPCC.cds.actorChip( actorType( r.actor_summary ), r.actor_summary || '' );
			if ( rev ) { meta += WPCC.cds.chip( 'reversible', i18n.chipReversible ); }
			html += '<li class="wpcc-cds-timeline__item">'
				+ '<span class="wpcc-cds-timeline__time">' + esc( fmtTime( r.last_at ) ) + '</span>'
				+ '<span class="wpcc-cds-timeline__main">'
				+ '<a href="' + esc( sessBase + '&session_id=' + encodeURIComponent( r.session_id ) ) + '">'
				+ esc( where || i18n.view ) + '</a>'
				+ ' <span style="color:#646970;">&middot; ' + esc( fmtN( 'actChanges', r.change_count || 0 ) ) + '</span>'
				+ '</span>'
				+ '<span class="wpcc-cds-timeline__meta">' + meta + '</span></li>';
		} );
		html += '</ul>';
		set( 'wpcc-home-activity', html );
	}

	function showFail() {
		set( 'wpcc-home-activity', '<div class="wpcc-cds-empty">' + esc( i18n.loadFail ) + '</div>' );
		set( 'wpcc-home-pending-text', '—' );
	}

	function init() {
		// Pre-setup Home renders no dashboard, so there is nothing to populate and
		// no reason to call the admin read at all.
		if ( ! document.getElementById( 'wpcc-home-activity' ) ) { return; }
		WPCC = window.WPCC || WPCC;
		if ( ! WPCC.api || ! WPCC.cds ) { showFail(); return; }

		WPCC.api( 'GET', apiBase + '/dashboard', nonce ).then( function ( res ) {
			if ( ! res.ok || ! res.data || res.data.action !== 'dashboard_overview' ) { showFail(); return; }
			var d = res.data;
			var ap   = d.approvals && ! d.approvals.gated ? d.approvals : {};
			var rows = Array.isArray( d.recent_activity ) ? d.recent_activity : [];
			renderAttn( ap );
			// Same response, no extra request.
			renderReady( ap, d.change_history && ! d.change_history.gated ? d.change_history : {} );
			renderUndoState( d.change_history && ! d.change_history.gated ? d.change_history : null );
			renderInvariants( d.invariants );
			/*
			 * Prefer the session feed (it groups related work), but fall back to the
			 * flat change list when there are no sessions — otherwise a site whose
			 * changes all came through the approval queue sees "No changes yet".
			 */
			renderActivity( rows.length ? rows : ( Array.isArray( d.recent_changes ) ? d.recent_changes : [] ) );
		} ).catch( showFail );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
})();
</script>
