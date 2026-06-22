<?php
/**
 * Contextual AI Alt Text entry point — Media Library row/bulk action.
 *
 * Adds "Generate Alt Text" to the Media Library list-table row actions (and a
 * matching Bulk Action) on image attachments. Each ONLY creates a governed DRAFT via
 * the existing AltTextGenerator (Propose != Apply) and redirects to the AI Alt Text
 * Builder for review/apply — the same in-context Governed Action Panel that SEO /
 * Title / Excerpt use (now opened from the consolidated ✨ AI Assist chooser).
 *
 * Propose-only: it NEVER applies, NEVER writes attachment meta, NEVER bypasses
 * approval / rollback / audit / capability scoping, and adds NO REST route /
 * operation / capability / MCP tool / schema. Apply targets the existing
 * media_manage / media_update operation; undo rides the existing history rollback.
 * Build flag WPCC_ALT_TEXT_UI gates visibility; FeatureGate 'ai_alt_text' availability.
 */

namespace WPCommandCenter\Admin;

use WPCommandCenter\AltText\AltTextGenerator;

defined( 'ABSPATH' ) || exit;

// Not final: make_generator() is a protected test-seam override (see tests).
class MediaRowActions {

	private const ACTION  = 'wpcc_alt_text_generate';
	private const MENU    = 'wpcc-alt-text';
	private const FEATURE = 'ai_alt_text';
	private const MAX_BATCH = 25;

	public function init(): void {
		// The Media row action is now consolidated into the single "✨ AI Assist" entry
		// (AiAssistRowActions); the Alt Text panel config lives in AiActionRegistry.
		// This class retains only the no-JS admin-post fallback handler + Bulk Actions.
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle' ] );
		add_filter( 'bulk_actions-upload', [ $this, 'add_bulk_action' ] );
		add_filter( 'handle_bulk_actions-upload', [ $this, 'handle_bulk' ], 10, 3 );
	}

	/** Generator factory (override seam for tests). */
	protected function make_generator(): AltTextGenerator {
		return new AltTextGenerator();
	}

	private function actor(): array {
		return [
			'wp_user_id' => get_current_user_id(),
			'user_login' => wp_get_current_user()->user_login,
			'source'     => 'admin_ui',
		];
	}

	private function ui_enabled(): bool {
		if ( defined( 'WPCC_ALT_TEXT_UI' ) && WPCC_ALT_TEXT_UI ) {
			return true;
		}
		return (bool) apply_filters( 'wpcc_alt_text_ui', false );
	}

	private function allowed(): bool {
		return current_user_can( 'manage_options' )
			&& $this->ui_enabled()
			&& FeatureGate::allows( self::FEATURE );
	}

	/** admin_post handler (no-JS fallback): create a draft, redirect to the Builder. */
	public function handle(): void {
		$id = isset( $_GET['attachment'] ) ? (int) $_GET['attachment'] : 0;

		if ( ! $this->allowed() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wp-command-center' ), 403 );
		}
		check_admin_referer( self::ACTION . '_' . $id );

		if ( $id <= 0 ) {
			$this->redirect( 'invalid' );
		}
		$res = $this->make_generator()->generate( [ $id ], [ 'actor' => $this->actor() ] );
		$this->redirect( $this->outcome_code( $res ) );
	}

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
			case 'not_image':
				return 'not_image';
			default:
				return 'skipped';
		}
	}

	private function redirect( string $code ): void {
		$url = add_query_arg( [ 'page' => self::MENU, 'wpcc_alt_gen' => $code ], admin_url( 'admin.php' ) );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * @param array<string,string> $actions
	 * @return array<string,string>
	 */
	public function add_bulk_action( array $actions ): array {
		if ( $this->allowed() ) {
			$actions[ self::ACTION ] = __( 'Generate Alt Text', 'wp-command-center' );
		}
		return $actions;
	}

	/**
	 * Bulk handler: bounded governed DRAFTS via the existing generator (which dedups,
	 * caps, and skips non-images itself), then redirect to the Builder with counts.
	 *
	 * @param string $redirect
	 * @param string $action
	 * @param int[]  $ids
	 */
	public function handle_bulk( string $redirect, string $action, array $ids ): string {
		if ( self::ACTION !== $action || ! $this->allowed() ) {
			return $redirect;
		}
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( $i ) => $i > 0 ) ) );
		if ( count( $ids ) > self::MAX_BATCH ) {
			$ids = array_slice( $ids, 0, self::MAX_BATCH );
		}
		if ( empty( $ids ) ) {
			return $redirect;
		}
		$res     = $this->make_generator()->generate( $ids, [ 'actor' => $this->actor() ] );
		$created = count( is_array( $res['created'] ?? null ) ? $res['created'] : [] );
		$skipped = count( is_array( $res['skipped'] ?? null ) ? $res['skipped'] : [] );
		$failed  = count( is_array( $res['failed'] ?? null ) ? $res['failed'] : [] );
		return add_query_arg(
			[ 'page' => self::MENU, 'wpcc_alt_bulk' => 1, 'c' => $created, 's' => $skipped, 'f' => $failed ],
			admin_url( 'admin.php' )
		);
	}
}
