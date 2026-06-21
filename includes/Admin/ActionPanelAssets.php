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

	/** Post types the contextual content/SEO workflows apply to (Products when Woo is active). */
	private function supported_types(): array {
		$types = [ 'post', 'page' ];
		if ( class_exists( 'WooCommerce' ) ) {
			$types[] = 'product';
		}
		return $types;
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
	 * Build the enabled-workflow config map for this screen. Each workflow is gated by
	 * capability + its build flag + its FeatureGate; only enabled ones appear.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function collect_actions( string $hook ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return [];
		}
		$actions = [];

		if ( 'edit.php' === $hook ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			$ptype  = $screen ? (string) $screen->post_type : '';
			if ( ! in_array( $ptype, $this->supported_types(), true ) ) {
				return [];
			}
			if ( $this->flag( 'WPCC_SEO_META_UI', 'wpcc_seo_meta_ui' ) && FeatureGate::allows( 'seo_meta_generator' ) ) {
				$actions['seo'] = $this->seo_config();
			}
			if ( $this->flag( 'WPCC_AI_CONTENT_UI', 'wpcc_ai_content_ui' ) ) {
				if ( FeatureGate::allows( 'title_generator' ) ) {
					$actions['title'] = $this->content_config( 'title' );
				}
				if ( FeatureGate::allows( 'excerpt_generator' ) ) {
					$actions['excerpt'] = $this->content_config( 'excerpt' );
				}
			}
		}

		if ( 'upload.php' === $hook
			&& $this->flag( 'WPCC_ALT_TEXT_UI', 'wpcc_alt_text_ui' )
			&& FeatureGate::allows( 'ai_alt_text' ) ) {
			$actions['alt_text'] = $this->alt_text_config();
		}

		return $actions;
	}

	/** Build-flag check: constant OR filter (mirrors the per-surface UI flags). */
	private function flag( string $const, string $filter ): bool {
		if ( defined( $const ) && constant( $const ) ) {
			return true;
		}
		return (bool) apply_filters( $filter, false );
	}

	// ── Per-workflow declarative configs (data only; the panel interprets them) ──

	private function seo_config(): array {
		return [
			'title'      => __( 'Generate SEO Suggestion', 'wp-command-center' ),
			'generate'   => [ 'path' => '/admin/seo/generate', 'body' => 'post_ids' ],
			'apply'      => [ 'action' => 'seo_update', 'idKey' => 'content_id', 'nest' => 'seo' ],
			'suggest'    => 'seo',
			'fields'     => [
				[ 'key' => 'title', 'label' => __( 'SEO title', 'wp-command-center' ), 'type' => 'text', 'prior' => 'title' ],
				[ 'key' => 'description', 'label' => __( 'Meta description', 'wp-command-center' ), 'type' => 'textarea', 'prior' => 'description' ],
			],
			'suggestUrl' => esc_url_raw( admin_url( 'admin.php?page=wpcc-seo&tab=suggestions' ) ),
		];
	}

	/**
	 * Title / Excerpt config. Generation rides the EXISTING generic proposals route's
	 * additive generate branch ({ generate:{ kind, post_id } }); apply targets the
	 * existing content_manage/content_update operation (top-level field, no nest).
	 */
	private function content_config( string $kind ): array {
		$is_title = ( 'title' === $kind );
		$field    = $is_title ? 'title' : 'excerpt';
		return [
			'title'      => $is_title ? __( 'Generate Title Suggestion', 'wp-command-center' ) : __( 'Generate Excerpt Suggestion', 'wp-command-center' ),
			'generate'   => [ 'path' => '/admin/proposals', 'body' => [ 'generate_kind' => $kind ] ],
			'apply'      => [ 'action' => 'content_update', 'idKey' => 'content_id' ],
			'suggest'    => null,
			'fields'     => [
				$is_title
					? [ 'key' => 'title', 'label' => __( 'Title', 'wp-command-center' ), 'type' => 'text', 'prior' => 'title' ]
					: [ 'key' => 'excerpt', 'label' => __( 'Excerpt', 'wp-command-center' ), 'type' => 'textarea', 'prior' => 'excerpt' ],
			],
			'suggestUrl' => esc_url_raw( admin_url( 'admin.php?page=wpcc-ai-content&tab=suggestions&kind=' . $kind ) ),
		];
	}

	/**
	 * Alt Text config. Generation rides the EXISTING /admin/alt-text/generate route;
	 * apply targets the existing media_manage/media_update operation (alt is a top-level
	 * field keyed by media_id). Undo rides the same history rollback route.
	 */
	private function alt_text_config(): array {
		return [
			'title'      => __( 'Generate Alt Text', 'wp-command-center' ),
			'generate'   => [ 'path' => '/admin/alt-text/generate', 'body' => 'attachment_ids' ],
			'apply'      => [ 'action' => 'media_update', 'idKey' => 'media_id' ],
			'suggest'    => null,
			'fields'     => [
				[ 'key' => 'alt', 'label' => __( 'Alt text', 'wp-command-center' ), 'type' => 'textarea', 'prior' => 'alt' ],
			],
			'suggestUrl' => esc_url_raw( admin_url( 'admin.php?page=wpcc-alt-text' ) ),
		];
	}

	/** One shared i18n block consumed by every workflow (field labels live per field). */
	private function shared_i18n(): array {
		return [
			'title'            => __( 'Generate Suggestion', 'wp-command-center' ),
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
