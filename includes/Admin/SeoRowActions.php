<?php
/**
 * Contextual SEO entry points — admin list-table row actions.
 *
 * Adds a "Generate SEO Suggestion" row action to the Posts, Pages, and (when
 * WooCommerce is active) Products list tables. The action ONLY creates a governed
 * DRAFT proposal via the existing SeoMetaGenerator (Propose != Apply) and redirects
 * to the SEO Meta → Suggestions tab for review/apply.
 *
 * It NEVER applies, NEVER writes SEO meta, NEVER bypasses approval / rollback /
 * audit / capability scoping, and adds NO REST route / operation / capability /
 * MCP tool / schema. All proposal creation flows through the existing generator's
 * single ProposalStore::create write, including its skip handling
 * (no_seo_plugin / no_provider / has_open_proposal / not_published).
 *
 * Thin admin wiring only: WP `*_row_actions` filters + one `admin_post_*` handler.
 */

namespace WPCommandCenter\Admin;

use WPCommandCenter\Seo\SeoMetaGenerator;

defined( 'ABSPATH' ) || exit;

final class SeoRowActions {

	private const ACTION  = 'wpcc_seo_generate';
	private const MENU    = 'wpcc-seo';
	private const FEATURE = 'seo_meta_generator';

	public function init(): void {
		// `post_row_actions` covers posts AND custom post types (incl. WooCommerce
		// products); `page_row_actions` covers pages. One handler serves all.
		add_filter( 'post_row_actions', [ $this, 'add_row_action' ], 10, 2 );
		add_filter( 'page_row_actions', [ $this, 'add_row_action' ], 10, 2 );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle' ] );
	}

	/** SEO Meta Builder UI flag — entry points appear only when the Builder is on. */
	private function ui_enabled(): bool {
		if ( defined( 'WPCC_SEO_META_UI' ) && WPCC_SEO_META_UI ) {
			return true;
		}
		return (bool) apply_filters( 'wpcc_seo_meta_ui', false );
	}

	/** Capability + FeatureGate + build-flag gate (same posture as the SEO REST surface). */
	private function allowed(): bool {
		return current_user_can( 'manage_options' )
			&& $this->ui_enabled()
			&& FeatureGate::allows( self::FEATURE );
	}

	/** Post types the action applies to (Products only when WooCommerce is active). */
	private function supported_type( string $type ): bool {
		$types = [ 'post', 'page' ];
		if ( class_exists( 'WooCommerce' ) ) {
			$types[] = 'product';
		}
		return in_array( $type, $types, true );
	}

	/**
	 * @param array<string,string> $actions
	 * @param mixed                $post
	 * @return array<string,string>
	 */
	public function add_row_action( array $actions, $post ): array {
		if ( ! $post instanceof \WP_Post ) {
			return $actions;
		}
		if ( ! $this->allowed() || ! $this->supported_type( $post->post_type ) ) {
			return $actions;
		}
		// The generator only proposes for published content — mirror that here.
		if ( 'publish' !== $post->post_status ) {
			return $actions;
		}

		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION . '&post=' . (int) $post->ID ),
			self::ACTION . '_' . (int) $post->ID
		);
		$actions[ self::ACTION ] = '<a href="' . esc_url( $url ) . '">'
			. esc_html__( 'Generate SEO Suggestion', 'wp-command-center' ) . '</a>';

		return $actions;
	}

	/**
	 * admin_post handler: nonce + capability + FeatureGate, then create a draft via
	 * the existing generator and redirect to the Suggestions tab. Propose only.
	 */
	public function handle(): void {
		$id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;

		if ( ! current_user_can( 'manage_options' ) || ! $this->ui_enabled() || ! FeatureGate::allows( self::FEATURE ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wp-command-center' ), 403 );
		}
		check_admin_referer( self::ACTION . '_' . $id );

		if ( $id <= 0 ) {
			$this->redirect( 'invalid' );
		}

		$actor = [
			'wp_user_id' => get_current_user_id(),
			'user_login' => wp_get_current_user()->user_login,
			'source'     => 'admin_ui',
		];

		// The ONLY action: create a governed draft via the existing generator.
		$res = ( new SeoMetaGenerator() )->generate( [ $id ], [ 'actor' => $actor ] );

		$this->redirect( $this->outcome_code( $res ) );
	}

	/** Map the generator envelope (single id) to a UI notice code. */
	private function outcome_code( array $res ): string {
		if ( ! empty( $res['created'] ) ) {
			return 'created';
		}
		if ( ! empty( $res['failed'] ) ) {
			return 'failed';
		}
		$reason = isset( $res['skipped'][0]['reason'] ) ? (string) $res['skipped'][0]['reason'] : 'skipped';
		switch ( $reason ) {
			case 'has_open_proposal':
				return 'exists';
			case 'no_provider':
				return 'no_provider';
			case 'no_seo_plugin':
				return 'no_plugin';
			case 'not_published':
				return 'not_published';
			default:
				return 'skipped';
		}
	}

	/** Redirect to SEO Meta → Suggestions with a result code (read by the view). */
	private function redirect( string $code ): void {
		$url = add_query_arg(
			[ 'page' => self::MENU, 'tab' => 'suggestions', 'wpcc_seo_gen' => $code ],
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}
}
