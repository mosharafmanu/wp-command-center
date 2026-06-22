<?php
/**
 * ✨ AI Assist — one consolidated, scalable AI row action per object.
 *
 * Replaces the previous per-feature row links (Generate Title / Excerpt / SEO / Alt
 * Text). A single "AI Assist" anchor is added to Posts, Pages, Products, and Media
 * Library rows whenever the AiActionRegistry has at least one ENABLED action that
 * applies to that object (right object type, build flag on, FeatureGate allowed, and
 * per-object eligibility — e.g. supported status / image). Clicking it opens the
 * existing Governed Action Panel, which presents a chooser of the applicable actions
 * (carried in `data-actions`) and then runs the unchanged governed lifecycle
 * (generate → review → edit → apply → undo).
 *
 * Thin admin wiring only. NO REST route / operation / capability / MCP tool / schema.
 * The anchor's `href` is a no-JS fallback to the relevant Builder surface (still a
 * fully governed surface); the per-feature `admin_post_*` fallback handlers in the
 * SeoRowActions / ContentRowActions / MediaRowActions classes are retained.
 */

namespace WPCommandCenter\Admin;

defined( 'ABSPATH' ) || exit;

final class AiAssistRowActions {

	private AiActionRegistry $registry;

	public function __construct( ?AiActionRegistry $registry = null ) {
		$this->registry = $registry ?? new AiActionRegistry();
	}

	public function init(): void {
		// `post_row_actions` covers posts AND custom post types (incl. WooCommerce
		// products); `page_row_actions` covers pages; `media_row_actions` covers the
		// Media Library. One method serves all — applicability comes from the registry.
		add_filter( 'post_row_actions', [ $this, 'add' ], 10, 2 );
		add_filter( 'page_row_actions', [ $this, 'add' ], 10, 2 );
		add_filter( 'media_row_actions', [ $this, 'add' ], 10, 2 );
	}

	/**
	 * Append the single "✨ AI Assist" row action when ≥1 AI action applies to $post.
	 *
	 * @param array<string,string> $actions
	 * @param mixed                $post
	 * @return array<string,string>
	 */
	public function add( array $actions, $post ): array {
		if ( ! $post instanceof \WP_Post ) {
			return $actions;
		}
		$ids = $this->registry->applicable_for_post( $post );
		if ( empty( $ids ) ) {
			return $actions;
		}
		$actions['wpcc_ai_assist'] = $this->anchor( $post, $ids );
		return $actions;
	}

	/**
	 * The AI Assist anchor. `data-wpcc-action="assist"` + `data-actions` (the applicable
	 * ids) drive the panel chooser; the `href` is the no-JS fallback (first applicable
	 * action's Builder). Visible label is the short WPCC brand "✨ WPCC AI" (the sparkle
	 * is decorative, `aria-hidden`); the `aria-label` gives screen-reader users the full
	 * "WP Command Center" attribution + purpose, and `aria-haspopup="dialog"` signals the
	 * chooser. Branded so it is unmistakably WPCC and not a generic competitor "magic wand".
	 *
	 * @param string[] $ids
	 */
	private function anchor( \WP_Post $post, array $ids ): string {
		$fallback = $this->registry->fallback_url( $ids[0] );
		// Multiple applicable actions open the compact dropdown MENU; a single action
		// opens the Governed Action Panel DIALOG directly — reflect that in aria-haspopup.
		$multi   = count( $ids ) > 1;
		$popup   = $multi ? 'menu' : 'dialog';
		$expanded = $multi ? ' aria-expanded="false"' : '';
		return '<a href="' . esc_url( $fallback ) . '"'
			. ' class="wpcc-ai-assist"'
			. ' data-wpcc-action="assist"'
			. ' data-id="' . (int) $post->ID . '"'
			. ' data-type="' . esc_attr( $post->post_type ) . '"'
			. ' data-actions="' . esc_attr( implode( ',', $ids ) ) . '"'
			. ' aria-haspopup="' . $popup . '"' . $expanded
			. ' aria-label="' . esc_attr__( 'WP Command Center — AI actions', 'wp-command-center' ) . '">'
			. '<span aria-hidden="true">✨ </span>'
			. esc_html__( 'WPCC AI', 'wp-command-center' )
			. '</a>';
	}
}
