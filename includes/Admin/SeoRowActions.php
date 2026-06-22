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
		// The contextual SEO row action is now consolidated into the single "✨ AI
		// Assist" entry (AiAssistRowActions); the SEO panel config lives in
		// AiActionRegistry. This class retains only the no-JS admin-post fallback
		// handler + native Bulk Actions (bulk consolidation is deferred).
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle' ] );

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

	// ── Governed Action Panel — enqueued centrally by ActionPanelAssets ────────
	// (the panel shared by every AI workflow). The contextual SEO entry is now the
	// consolidated ✨ AI Assist row action (AiAssistRowActions), whose chooser opens
	// this panel; the SEO panel config (routes, fields, apply shape) lives in
	// AiActionRegistry. This class keeps only the no-JS admin-post fallback handler
	// (above) and the native Bulk Actions (below).

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
