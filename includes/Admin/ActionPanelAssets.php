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

		wp_enqueue_style( 'wpcc-tokens', WPCC_PLUGIN_URL . 'assets/css/wpcc-tokens.css', [], WPCC_VERSION );
		wp_enqueue_style( 'wpcc-action-panel', WPCC_PLUGIN_URL . 'assets/css/wpcc-action-panel.css', [ 'wpcc-tokens' ], WPCC_VERSION );

		wp_enqueue_script( 'wpcc-admin-runtime', WPCC_PLUGIN_URL . 'assets/js/wpcc-admin-runtime.js', [], WPCC_VERSION, true );
		wp_enqueue_script( 'wpcc-action-panel', WPCC_PLUGIN_URL . 'assets/js/wpcc-action-panel.js', [ 'wpcc-admin-runtime' ], WPCC_VERSION, true );

		wp_localize_script( 'wpcc-action-panel', 'wpccActionPanel', [
			'restBase' => esc_url_raw( rest_url( 'wp-command-center/v1' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'mode'     => SecurityModeManager::current(),
			'i18n'     => $this->shared_i18n(),
			'actions'  => $actions,
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
			'title'            => __( 'Generate Suggestion', 'wp-command-center' ),
			'chooserTitle'     => __( 'WPCC AI', 'wp-command-center' ),
			'chooserIntro'     => __( 'Choose what to generate for this item.', 'wp-command-center' ),
			'generating'       => __( 'Generating suggestion…', 'wp-command-center' ),
			'current'          => __( 'Current', 'wp-command-center' ),
			'suggested'        => __( 'Suggested', 'wp-command-center' ),
			'empty'            => __( '(none)', 'wp-command-center' ),
			'openSuggest'      => __( 'Open in Suggestions', 'wp-command-center' ),
			'close'            => __( 'Close', 'wp-command-center' ),
			'draftNote'        => __( 'Saved as a draft for review — nothing has been applied to your site.', 'wp-command-center' ),
			'provBy'           => __( 'Suggested by %1$s · %2$s', 'wp-command-center' ),
			'exists'           => __( 'A suggestion already exists for this item. Open it in Suggestions to review.', 'wp-command-center' ),
			'noProvider'       => __( 'No AI provider is configured. Add an API key under AI Integrations to generate suggestions.', 'wp-command-center' ),
			'noPlugin'         => __( 'No supported SEO plugin (Rank Math or Yoast) is active.', 'wp-command-center' ),
			'unsupportedStatus'=> __( 'This content status cannot receive suggestions (e.g. trashed or auto-draft).', 'wp-command-center' ),
			'failed'           => __( 'Could not generate a suggestion. Please try again.', 'wp-command-center' ),
			'error'            => __( 'Something went wrong. Please try again.', 'wp-command-center' ),
			'applyDev'         => __( 'Approve & Apply', 'wp-command-center' ),
			'applyGate'        => __( 'Submit for approval', 'wp-command-center' ),
			'applying'         => __( 'Applying…', 'wp-command-center' ),
			'approvalRequired' => __( 'Approval required — this will be sent for approval, not applied immediately.', 'wp-command-center' ),
			'appliedTitle'     => __( 'Applied successfully', 'wp-command-center' ),
			'submittedTitle'   => __( 'Submitted for approval', 'wp-command-center' ),
			'appliedNote'      => __( 'The change was applied. It is reversible and recorded in the audit log.', 'wp-command-center' ),
			'submittedNote'    => __( 'Submitted for approval and recorded in the audit log. Nothing has been applied yet.', 'wp-command-center' ),
			'chipReversible'   => __( 'Reversible', 'wp-command-center' ),
			'chipAudited'      => __( 'Audited', 'wp-command-center' ),
			'undo'             => __( 'Undo', 'wp-command-center' ),
			'undoing'          => __( 'Undoing…', 'wp-command-center' ),
			'reverted'         => __( 'Reverted', 'wp-command-center' ),
			'undoSent'         => __( 'Undo sent for approval', 'wp-command-center' ),
			'cantApply'        => __( 'Couldn’t apply. Please try again.', 'wp-command-center' ),
			'cantUndo'         => __( 'Couldn’t undo. Please try again.', 'wp-command-center' ),
		];
	}
}
