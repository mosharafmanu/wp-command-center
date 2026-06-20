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
 * (no_seo_plugin / no_provider / has_open_proposal / unsupported_status).
 *
 * Thin admin wiring only: WP `*_row_actions` filters + one `admin_post_*` handler.
 */

namespace WPCommandCenter\Admin;

use WPCommandCenter\Seo\SeoMetaGenerator;

defined( 'ABSPATH' ) || exit;

// Not final: `make_generator()` is a protected test-seam override (see tests).
class SeoRowActions {

	private const ACTION  = 'wpcc_seo_generate';
	private const MENU    = 'wpcc-seo';
	private const FEATURE = 'seo_meta_generator';

	public function init(): void {
		// Row actions: `post_row_actions` covers posts AND custom post types (incl.
		// WooCommerce products); `page_row_actions` covers pages. One handler serves all.
		add_filter( 'post_row_actions', [ $this, 'add_row_action' ], 10, 2 );
		add_filter( 'page_row_actions', [ $this, 'add_row_action' ], 10, 2 );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle' ] );

		// Quick Panel (Option B) — progressive enhancement. The row action stays a
		// working admin-post <a> (no-JS fallback = the redirect handler above); when
		// the asset loads it intercepts the click and proposes a draft in-context.
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		// Sprint B — native WordPress Bulk Actions (per-screen hooks). WP core verifies
		// the bulk-action nonce before `handle_bulk_actions-*` runs.
		foreach ( $this->supported_types() as $type ) {
			add_filter( "bulk_actions-edit-{$type}", [ $this, 'add_bulk_action' ] );
			add_filter( "handle_bulk_actions-edit-{$type}", [ $this, 'handle_bulk' ], 10, 3 );
		}
	}

	/** Post types these entry points apply to (Products only when WooCommerce is active). */
	private function supported_types(): array {
		$types = [ 'post', 'page' ];
		if ( class_exists( 'WooCommerce' ) ) {
			$types[] = 'product';
		}
		return $types;
	}

	/** Generator factory (override seam for tests; production uses the real generator). */
	protected function make_generator(): SeoMetaGenerator {
		return new SeoMetaGenerator();
	}

	/** The admin who triggered the action (proposal provenance). */
	private function actor(): array {
		return [
			'wp_user_id' => get_current_user_id(),
			'user_login' => wp_get_current_user()->user_login,
			'source'     => 'admin_ui',
		];
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

	/** Whether a single post type is supported. */
	private function supported_type( string $type ): bool {
		return in_array( $type, $this->supported_types(), true );
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
		// Offer the action only for statuses the generator will actually accept — the
		// shared allow-list (publish/draft/pending/future/private); never on
		// trash/auto-draft/revisions. Single source of truth = SeoMetaGenerator.
		if ( ! SeoMetaGenerator::is_supported_status( (string) $post->post_status ) ) {
			return $actions;
		}

		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION . '&post=' . (int) $post->ID ),
			self::ACTION . '_' . (int) $post->ID
		);
		// `wpcc-seo-quickgen` + data-* are the progressive-enhancement hooks the Quick
		// Panel asset binds to. With no JS the href still redirects (the fallback path).
		$actions[ self::ACTION ] = '<a href="' . esc_url( $url ) . '"'
			. ' class="wpcc-seo-quickgen"'
			. ' data-id="' . (int) $post->ID . '"'
			. ' data-type="' . esc_attr( $post->post_type ) . '">'
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

		// The ONLY action: create a governed draft via the existing generator.
		$res = $this->make_generator()->generate( [ $id ], [ "actor" => $this->actor() ] );

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
			case 'unsupported_status':
				return 'unsupported_status';
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

	// ── Quick Panel (Option B) — in-context AJAX modal over EXISTING routes ────

	/**
	 * Enqueue the Quick Panel asset on the list-table screen for supported types.
	 *
	 * Loads ONLY on `edit.php` for a supported post type and ONLY when the entry
	 * points are allowed (cap + build flag + FeatureGate) — same gate as the row
	 * action itself. Localizes the EXISTING REST base + a fresh `wp_rest` nonce; the
	 * modal calls `POST /admin/seo/generate` then `GET /admin/proposals/{id}` and only
	 * ever navigates. No apply, no new route. Server-side permission on those routes
	 * (`check_seo_permission`) is authoritative; this client gate is convenience only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'edit.php' !== $hook ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$ptype  = $screen ? (string) $screen->post_type : '';
		if ( ! $this->supported_type( $ptype ) || ! $this->allowed() ) {
			return;
		}

		wp_enqueue_style(
			'wpcc-seo-quick-panel',
			WPCC_PLUGIN_URL . 'assets/css/seo-quick-panel.css',
			[],
			WPCC_VERSION
		);
		wp_enqueue_script(
			'wpcc-seo-quick-panel',
			WPCC_PLUGIN_URL . 'assets/js/seo-quick-panel.js',
			[],
			WPCC_VERSION,
			true
		);
		wp_localize_script( 'wpcc-seo-quick-panel', 'wpccSeoQuickPanel', [
			'restBase'   => esc_url_raw( rest_url( 'wp-command-center/v1' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			// Where "Open Suggestions" / "Review in WPCC" navigate (the governed Builder).
			'suggestUrl' => esc_url_raw( admin_url( 'admin.php?page=' . self::MENU . '&tab=suggestions' ) ),
			'i18n'       => [
				'title'        => __( 'Generate SEO Suggestion', 'wp-command-center' ),
				'generating'   => __( 'Generating suggestion…', 'wp-command-center' ),
				'current'      => __( 'Current', 'wp-command-center' ),
				'suggested'    => __( 'Suggested', 'wp-command-center' ),
				'metaTitle'    => __( 'SEO title', 'wp-command-center' ),
				'metaDesc'     => __( 'Meta description', 'wp-command-center' ),
				'empty'        => __( '(none)', 'wp-command-center' ),
				'openSuggest'  => __( 'Open in Suggestions', 'wp-command-center' ),
				'review'       => __( 'Review in WP Command Center', 'wp-command-center' ),
				'close'        => __( 'Close', 'wp-command-center' ),
				'draftNote'    => __( 'Saved as a draft for review — nothing has been applied to your site.', 'wp-command-center' ),
				'provBy'       => __( 'Suggested by %1$s · %2$s', 'wp-command-center' ),
				'exists'       => __( 'A suggestion already exists for this item. Open it in Suggestions to review.', 'wp-command-center' ),
				'noProvider'   => __( 'No AI provider is configured. Add an API key under AI Integrations to generate suggestions.', 'wp-command-center' ),
				'noPlugin'           => __( 'No supported SEO plugin (Rank Math or Yoast) is active.', 'wp-command-center' ),
				'unsupportedStatus'  => __( 'This content status cannot receive SEO suggestions (e.g. trashed or auto-draft).', 'wp-command-center' ),
				'failed'       => __( 'Could not generate a suggestion. Please try again.', 'wp-command-center' ),
				'error'        => __( 'Something went wrong. Please try again.', 'wp-command-center' ),
			],
		] );
	}

	// ── Sprint B — native WordPress Bulk Actions ──────────────────────────────

	/**
	 * Add "Generate SEO Suggestions" to a list-table Bulk Actions dropdown.
	 *
	 * @param array<string,string> $actions
	 * @return array<string,string>
	 */
	public function add_bulk_action( array $actions ): array {
		if ( ! $this->allowed() ) {
			return $actions;
		}
		$actions[ self::ACTION ] = __( 'Generate SEO Suggestions', 'wp-command-center' );
		return $actions;
	}

	/**
	 * Handle the bulk action: create governed DRAFTS for the selected ids (bounded to
	 * MAX_BATCH) via the existing generator, then redirect to SEO Meta → Suggestions
	 * with aggregate counts. Propose only — never applies. WP core has already
	 * verified the bulk-action nonce before this filter runs.
	 *
	 * @param string $redirect Default list-table redirect URL.
	 * @param string $action   The chosen bulk action.
	 * @param int[]  $post_ids Selected post ids.
	 */
	public function handle_bulk( string $redirect, string $action, array $post_ids ): string {
		if ( self::ACTION !== $action ) {
			return $redirect;
		}
		if ( ! current_user_can( 'manage_options' ) || ! $this->ui_enabled() || ! FeatureGate::allows( self::FEATURE ) ) {
			return $redirect; // not allowed — do nothing, keep the user on the list
		}

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ), static fn( $i ) => $i > 0 ) ) );
		if ( count( $ids ) > SeoMetaGenerator::MAX_BATCH ) {
			$ids = array_slice( $ids, 0, SeoMetaGenerator::MAX_BATCH );
		}
		if ( empty( $ids ) ) {
			return $redirect;
		}

		$res = $this->make_generator()->generate( $ids, [ "actor" => $this->actor() ] );

		return $this->bulk_redirect( $res );
	}

	/** Build the Suggestions redirect URL with aggregate counts + a dominant skip reason. */
	private function bulk_redirect( array $res ): string {
		$created = count( is_array( $res['created'] ?? null ) ? $res['created'] : [] );
		$skipped_list = is_array( $res['skipped'] ?? null ) ? $res['skipped'] : [];
		$skipped = count( $skipped_list );
		$failed  = count( is_array( $res['failed'] ?? null ) ? $res['failed'] : [] );

		// When nothing was created, surface the dominant skip reason for a clear notice.
		$reason = '';
		if ( 0 === $created && $skipped > 0 ) {
			$by_reason = [];
			foreach ( $skipped_list as $s ) {
				$r              = (string) ( $s['reason'] ?? '' );
				$by_reason[ $r ] = ( $by_reason[ $r ] ?? 0 ) + 1;
			}
			arsort( $by_reason );
			$reason = (string) array_key_first( $by_reason );
		}

		return add_query_arg(
			[
				'page'          => self::MENU,
				'tab'           => 'suggestions',
				'wpcc_seo_bulk' => 1,
				'c'             => $created,
				's'             => $skipped,
				'f'             => $failed,
				'r'             => $reason,
			],
			admin_url( 'admin.php' )
		);
	}
}
