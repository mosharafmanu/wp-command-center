<?php
/**
 * Contextual AI Content entry points — Title & Excerpt row/bulk actions.
 *
 * Adds "Generate Title Suggestion" and "Generate Excerpt Suggestion" row actions
 * (and matching Bulk Actions) to the Posts, Pages, and (when WooCommerce is active)
 * Products list tables. Each ONLY creates a governed DRAFT via the existing
 * ContentFieldGenerator (Propose != Apply) and redirects to the AI Content Builder
 * for review/apply.
 *
 * Propose-only: it NEVER applies, NEVER writes a post, NEVER bypasses approval /
 * rollback / audit / capability scoping, and adds NO REST route / operation /
 * capability / MCP tool / schema. The drafts target the existing content_manage /
 * content_update operation; review + apply + undo happen through the governed panel
 * (data-wpcc-action) or the Builder. Thin admin wiring only.
 *
 * Mirrors SeoRowActions; build flag WPCC_AI_CONTENT_UI gates visibility, the per-kind
 * FeatureGate ('title_generator' / 'excerpt_generator') gates availability.
 */

namespace WPCommandCenter\Admin;

use WPCommandCenter\Content\ContentFieldGenerator;

defined( 'ABSPATH' ) || exit;

// Not final: make_generator() is a protected test-seam override (see tests).
class ContentRowActions {

	private const ACTION = 'wpcc_content_generate';
	private const MENU   = 'wpcc-ai-content';

	/** Cap on a bulk run (bounded; mirrors the generator's per-item batch cap). */
	private const MAX_BATCH = 25;

	/** Map a field kind to its FeatureGate key. */
	private const FEATURE = [ 'title' => 'title_generator', 'excerpt' => 'excerpt_generator' ];

	public function init(): void {
		add_filter( 'post_row_actions', [ $this, 'add_row_actions' ], 10, 2 );
		add_filter( 'page_row_actions', [ $this, 'add_row_actions' ], 10, 2 );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle' ] );

		foreach ( $this->supported_types() as $type ) {
			add_filter( "bulk_actions-edit-{$type}", [ $this, 'add_bulk_actions' ] );
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
	protected function make_generator(): ContentFieldGenerator {
		return new ContentFieldGenerator();
	}

	/** The admin who triggered the action (proposal provenance). */
	private function actor(): array {
		return [
			'wp_user_id' => get_current_user_id(),
			'user_login' => wp_get_current_user()->user_login,
			'source'     => 'admin_ui',
		];
	}

	/** AI Content Builder UI flag — entry points appear only when the Builder is on. */
	private function ui_enabled(): bool {
		if ( defined( 'WPCC_AI_CONTENT_UI' ) && WPCC_AI_CONTENT_UI ) {
			return true;
		}
		return (bool) apply_filters( 'wpcc_ai_content_ui', false );
	}

	/** Capability + build-flag + per-kind FeatureGate gate. */
	private function allowed( string $kind ): bool {
		$feature = self::FEATURE[ $kind ] ?? '';
		return '' !== $feature
			&& current_user_can( 'manage_options' )
			&& $this->ui_enabled()
			&& FeatureGate::allows( $feature );
	}

	private function supported_type( string $type ): bool {
		return in_array( $type, $this->supported_types(), true );
	}

	/**
	 * @param array<string,string> $actions
	 * @param mixed                $post
	 * @return array<string,string>
	 */
	public function add_row_actions( array $actions, $post ): array {
		if ( ! $post instanceof \WP_Post || ! $this->supported_type( $post->post_type ) ) {
			return $actions;
		}
		if ( ! ContentFieldGenerator::is_supported_status( (string) $post->post_status ) ) {
			return $actions;
		}

		$labels = [
			'title'   => __( 'Generate Title Suggestion', 'wp-command-center' ),
			'excerpt' => __( 'Generate Excerpt Suggestion', 'wp-command-center' ),
		];
		foreach ( ContentFieldGenerator::KINDS as $kind ) {
			if ( ! $this->allowed( $kind ) ) {
				continue;
			}
			$url = wp_nonce_url(
				admin_url( 'admin-post.php?action=' . self::ACTION . '&kind=' . $kind . '&post=' . (int) $post->ID ),
				self::ACTION . '_' . $kind . '_' . (int) $post->ID
			);
			$actions[ self::ACTION . '_' . $kind ] = '<a href="' . esc_url( $url ) . '"'
				. ' class="wpcc-content-quickgen"'
				. ' data-wpcc-action="' . esc_attr( $kind ) . '"'
				. ' data-id="' . (int) $post->ID . '"'
				. ' data-type="' . esc_attr( $post->post_type ) . '">'
				. esc_html( $labels[ $kind ] ) . '</a>';
		}

		return $actions;
	}

	/**
	 * admin_post handler (no-JS fallback): nonce + capability + FeatureGate, then create
	 * a draft via the existing generator and redirect to the Builder. Propose only.
	 */
	public function handle(): void {
		$id   = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
		$kind = isset( $_GET['kind'] ) ? sanitize_key( (string) $_GET['kind'] ) : '';

		if ( ! $this->allowed( $kind ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wp-command-center' ), 403 );
		}
		check_admin_referer( self::ACTION . '_' . $kind . '_' . $id );

		if ( $id <= 0 ) {
			$this->redirect( $kind, 'invalid' );
		}

		$res = $this->make_generator()->generate( $id, $kind, [ 'actor' => $this->actor() ] );
		$this->redirect( $kind, $this->outcome_code( $res ) );
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
			case 'unsupported_status':
				return 'unsupported_status';
			default:
				return 'skipped';
		}
	}

	/** Redirect to the AI Content Builder → Suggestions with a result code. */
	private function redirect( string $kind, string $code ): void {
		$url = add_query_arg(
			[ 'page' => self::MENU, 'tab' => 'suggestions', 'kind' => $kind, 'wpcc_content_gen' => $code ],
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	// ── Bulk Actions ──────────────────────────────────────────────────────────

	/**
	 * @param array<string,string> $actions
	 * @return array<string,string>
	 */
	public function add_bulk_actions( array $actions ): array {
		if ( $this->allowed( 'title' ) ) {
			$actions[ self::ACTION . '_title' ] = __( 'Generate Title Suggestions', 'wp-command-center' );
		}
		if ( $this->allowed( 'excerpt' ) ) {
			$actions[ self::ACTION . '_excerpt' ] = __( 'Generate Excerpt Suggestions', 'wp-command-center' );
		}
		return $actions;
	}

	/**
	 * Handle a bulk action: create governed DRAFTS (bounded) for the selected ids by
	 * running the single-item generator per id, then redirect with aggregate counts.
	 * Propose only. WP core has already verified the bulk-action nonce.
	 *
	 * @param string $redirect Default list-table redirect URL.
	 * @param string $action   The chosen bulk action.
	 * @param int[]  $post_ids Selected post ids.
	 */
	public function handle_bulk( string $redirect, string $action, array $post_ids ): string {
		$kind = '';
		if ( $action === self::ACTION . '_title' ) {
			$kind = 'title';
		} elseif ( $action === self::ACTION . '_excerpt' ) {
			$kind = 'excerpt';
		} else {
			return $redirect;
		}
		if ( ! $this->allowed( $kind ) ) {
			return $redirect;
		}

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ), static fn( $i ) => $i > 0 ) ) );
		if ( count( $ids ) > self::MAX_BATCH ) {
			$ids = array_slice( $ids, 0, self::MAX_BATCH );
		}
		if ( empty( $ids ) ) {
			return $redirect;
		}

		$generator = $this->make_generator();
		$created = 0;
		$skipped = 0;
		$failed  = 0;
		$reasons = [];
		foreach ( $ids as $id ) {
			$res      = $generator->generate( $id, $kind, [ 'actor' => $this->actor() ] );
			$created += count( is_array( $res['created'] ?? null ) ? $res['created'] : [] );
			$failed  += count( is_array( $res['failed'] ?? null ) ? $res['failed'] : [] );
			foreach ( ( is_array( $res['skipped'] ?? null ) ? $res['skipped'] : [] ) as $s ) {
				$skipped++;
				$r             = (string) ( $s['reason'] ?? '' );
				$reasons[ $r ] = ( $reasons[ $r ] ?? 0 ) + 1;
			}
		}

		$reason = '';
		if ( 0 === $created && $skipped > 0 ) {
			arsort( $reasons );
			$reason = (string) array_key_first( $reasons );
		}

		return add_query_arg(
			[
				'page'             => self::MENU,
				'tab'              => 'suggestions',
				'kind'             => $kind,
				'wpcc_content_bulk'=> 1,
				'c'                => $created,
				's'                => $skipped,
				'f'                => $failed,
				'r'                => $reason,
			],
			admin_url( 'admin.php' )
		);
	}
}
