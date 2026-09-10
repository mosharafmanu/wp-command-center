<?php
/**
 * Governed Action Panel — centralized asset enqueuer + per-workflow config registry.
 *
 * The ONE place that enqueues the generalized Governed Action Panel (the design
 * tokens, the shared admin runtime, the panel JS/CSS) and localizes a SINGLE
 * `wpccActionPanel` config carrying EVERY enabled AI content workflow's declarative
 * config. Centralizing it is required: each workflow's contextual row action binds
 * to the same panel via `data-wpcc-action`, so two workflows on one list table
 * (e.g. SEO + Title on Posts) must share one localized object — a per-class enqueue
 * would overwrite it.
 *
 * The panel uses ONLY existing governed routes (generate → proposal review → PUT →
 * apply → history rollback). This class adds NO REST route / operation / capability /
 * MCP tool / schema. Each workflow is gated independently (cap + build flag +
 * FeatureGate); a workflow that is off contributes nothing to the config.
 */

namespace WPCommandCenter\Admin;

use WPCommandCenter\Operations\SecurityModeManager;

defined( 'ABSPATH' ) || exit;

final class ActionPanelAssets {

	/**
	 * Asset version = plugin version + file mtime, matching Assets::ver().
	 *
	 * These four files were versioned with the bare WPCC_VERSION, so a change to
	 * the panel's JS or CSS inside a released version reached nobody whose browser
	 * had already cached it — including anyone applying a hotfix. The sibling
	 * enqueuer has always done this; the two now behave the same way.
	 */
	private static function ver( string $relative ): string {
		$path  = WPCC_PLUGIN_DIR . $relative;
		$mtime = is_readable( $path ) ? filemtime( $path ) : false;

		return false === $mtime ? WPCC_VERSION : WPCC_VERSION . '.' . $mtime;
	}

	public function init(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * Enqueue the panel + localize the enabled workflows for the current admin screen.
	 *
	 * @param string $hook Current admin page hook (e.g. 'edit.php', 'upload.php').
	 */
	public function enqueue( string $hook ): void {
		$actions = $this->collect_actions( $hook );
		if ( empty( $actions ) ) {
			return; // no enabled workflow on this screen — enqueue nothing
		}

		wp_enqueue_style( 'wpcc-tokens', WPCC_PLUGIN_URL . 'assets/css/wpcc-tokens.css', [], self::ver( 'assets/css/wpcc-tokens.css' ) );
		wp_enqueue_style( 'wpcc-action-panel', WPCC_PLUGIN_URL . 'assets/css/wpcc-action-panel.css', [ 'wpcc-tokens' ], self::ver( 'assets/css/wpcc-action-panel.css' ) );

		wp_enqueue_script( 'wpcc-admin-runtime', WPCC_PLUGIN_URL . 'assets/js/wpcc-admin-runtime.js', [], self::ver( 'assets/js/wpcc-admin-runtime.js' ), true );
		wp_enqueue_script( 'wpcc-action-panel', WPCC_PLUGIN_URL . 'assets/js/wpcc-action-panel.js', [ 'wpcc-admin-runtime' ], self::ver( 'assets/js/wpcc-action-panel.js' ), true );

		/*
		 * Continuity URLs.
		 *
		 * The panel used to end its own story: it said "Submitted for approval" and
		 * stopped, leaving the customer to find the global Approvals screen on their
		 * own and then identify their item in a queue of a hundred. The apply
		 * response already carries the request_id (ProposalAdminQuery exposes it), so
		 * the panel can link to the exact approval — it only lacked the base URL.
		 */
		$approval_base = admin_url( 'admin.php?page=wpcc-activity&wpcc_tab=approvals' );

		wp_localize_script( 'wpcc-action-panel', 'wpccActionPanel', [
			'restBase'    => esc_url_raw( rest_url( 'wp-command-center/v1' ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'mode'        => SecurityModeManager::current(),
			'approvalUrl' => esc_url_raw( $approval_base ),
			'changesUrl'  => esc_url_raw( admin_url( 'admin.php?page=wpcc-history&wpcc_tab=changes' ) ),
			// The model that will actually run, so the loading state can name it
			// instead of saying nothing. No outbound call; '' when unconfigured, and
			// the panel simply omits the line rather than inventing one.
			'model'       => ( new \WPCommandCenter\Ai\AiRuntime() )->model( '' ),
			'i18n'        => $this->shared_i18n(),
			'actions'     => $actions,
		] );
	}

	/**
	 * Build the enabled-action config map for this screen by reading AiActionRegistry.
	 * The screen determines the object type (edit.php → the list table's post type;
	 * upload.php → attachment); the registry decides which actions are enabled + apply
	 * to that type. Each action stays gated by capability + build flag + FeatureGate.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function collect_actions( string $hook ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return [];
		}

		$type = '';
		if ( 'edit.php' === $hook ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			$type   = $screen ? (string) $screen->post_type : '';
		} elseif ( 'upload.php' === $hook ) {
			$type = 'attachment';
		}
		if ( '' === $type ) {
			return [];
		}

		$registry = new AiActionRegistry();
		return $registry->localized( $registry->enabled_for_type( $type ) );
	}

	/** One shared i18n block consumed by every workflow (field labels live per field). */
	private function shared_i18n(): array {
		return [
			'title'            => __( 'Generate Suggestion', 'action-steward' ),
			'chooserTitle'     => __( 'Action Steward AI', 'action-steward' ),
			'chooserIntro'     => __( 'Choose what to generate for this item.', 'action-steward' ),
			/* translators: %1$s: what is being generated, e.g. "title" or "SEO details". */
			'generatingFor'    => /* translators: %1$s: value */ __( 'Generating a %1$s…', 'action-steward' ),
			'generating'       => __( 'Generating suggestion…', 'action-steward' ),
			/* translators: %1$s: the AI model being used, e.g. "claude-sonnet-4-6". */
			'usingModel'       => /* translators: %1$s: value */ __( 'Using %1$s', 'action-steward' ),
			// Said while the customer waits, because waiting is exactly when someone
			// wonders whether they have already changed something.
			'nothingYet'       => __( 'Nothing on your site is changing yet.', 'action-steward' ),
			'current'          => __( 'Current', 'action-steward' ),
			'suggested'        => __( 'Suggested', 'action-steward' ),
			'empty'            => __( '(none)', 'action-steward' ),
			'openSuggest'      => __( 'Open in Suggestions', 'action-steward' ),
			'close'            => __( 'Close', 'action-steward' ),
			'draftNote'        => __( 'Saved as a draft for review — nothing has been applied to your site.', 'action-steward' ),
			'provBy'           => /* translators: %1$s: value, %2$s: value */ __( 'Suggested by %1$s · %2$s', 'action-steward' ),
			/*
			 * The duplicate state. "A suggestion already exists" told the customer
			 * that something was in their way without saying what, why, or what to
			 * do — so the natural reading is "it failed". It is the opposite: their
			 * earlier suggestion is safe and waiting.
			 */
			'existsTitle'      => __( 'You already have a draft for this', 'action-steward' ),
			'exists'           => __( 'An earlier suggestion for this item is still waiting for your review, so a second one was not generated. Review or dismiss that draft first — generating again would leave you with two.', 'action-steward' ),
			'reviewDraft'      => __( 'Review existing draft', 'action-steward' ),
			// Skips that are NOT failures. Each used to fall through to the generic
			// failure text, which told the customer something had broken when in
			// fact there was simply nothing to do.
			'skNothingTitle'   => __( 'Nothing to generate here', 'action-steward' ),
			'skUnsupported'    => __( 'This item does not support that kind of suggestion, so nothing was generated and nothing on your site changed.', 'action-steward' ),
			'skNotFound'       => __( 'That item could not be found — it may have been deleted since this page loaded. Nothing on your site changed.', 'action-steward' ),
			'skUpToDate'       => __( 'This item is already up to date, so there was nothing to suggest. Nothing on your site changed.', 'action-steward' ),
			'failedTitle'      => __( 'That did not work', 'action-steward' ),
			'nothingChanged'   => __( 'Nothing on your site changed.', 'action-steward' ),
			// Same destination correction as the SEO and Content views: Built-in AI
			// keys live on Built-in AI > Providers, not on the MCP assistants screen.
			'noProvider'       => __( 'Built-in AI has no provider key yet, so nothing was generated and nothing on your site changed. Add a key under Settings › Built-in AI › Providers.', 'action-steward' ),
			'noPlugin'         => __( 'No supported SEO plugin (Rank Math or Yoast) is active.', 'action-steward' ),
			'unsupportedStatus'=> __( 'Suggestions are only generated for published or draft content — this item is trashed or not started yet.', 'action-steward' ),
			'failed'           => __( 'The AI did not return a suggestion this time, so nothing was created and nothing on your site changed. Try again — if it keeps happening, check Settings › Advanced › Diagnostics.', 'action-steward' ),
			'error'            => __( 'Something went wrong. Please try again.', 'action-steward' ),
			'applyDev'         => __( 'Approve & Apply', 'action-steward' ),
			'applyGate'        => __( 'Submit for approval', 'action-steward' ),
			'applying'         => __( 'Applying…', 'action-steward' ),
			'approvalRequired' => __( 'Approval required — this will be sent for approval, not applied immediately.', 'action-steward' ),
			'appliedTitle'     => __( 'Applied successfully', 'action-steward' ),
			'submittedTitle'   => __( 'Submitted for approval', 'action-steward' ),
			'appliedNote'      => __( 'The change was applied. It is reversible and recorded in the audit log.', 'action-steward' ),
			'submittedNote'    => __( 'Your suggestion is waiting for approval and is recorded in the audit log. Nothing on your site has changed yet.', 'action-steward' ),
			// The continuation. Both states used to end on "Close", which is not a
			// next step — it is the absence of one.
			'reviewApproval'   => __( 'Review approval', 'action-steward' ),
			'viewInChanges'    => __( 'View in Changes', 'action-steward' ),
			'done'             => __( 'Done', 'action-steward' ),
			'chipReversible'   => __( 'Reversible', 'action-steward' ),
			'chipAudited'      => __( 'Audited', 'action-steward' ),
			'undo'             => __( 'Undo', 'action-steward' ),
			'undoing'          => __( 'Undoing…', 'action-steward' ),
			'reverted'         => __( 'Reverted', 'action-steward' ),
			'undoSent'         => __( 'Undo sent for approval', 'action-steward' ),
			'cantApply'        => __( 'Couldn’t apply. Please try again.', 'action-steward' ),
			'cantUndo'         => __( 'Couldn’t undo. Please try again.', 'action-steward' ),
		];
	}
}
