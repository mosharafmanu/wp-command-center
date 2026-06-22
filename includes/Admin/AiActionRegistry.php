<?php
/**
 * AI Action Registry — the single source of truth for governed AI content actions.
 *
 * Each entry declares everything a contextual entry point (the "✨ AI Assist" row
 * action) and the Governed Action Panel need to surface and run one AI action:
 * id, label, icon, the object types it applies to, its build flag + FeatureGate key,
 * its (existing) generate endpoint/body, its proposal field mapping for apply, and a
 * no-JS fallback URL. Adding a future AI action = one entry here; it then appears in
 * the chooser and panel everywhere, with no new row/bulk UX wiring.
 *
 * This registry adds NO REST route / operation / capability / MCP tool / schema — every
 * action reuses an existing governed route (generate → proposal review → PUT → apply →
 * history rollback). Gating per action = manage_options + build flag + FeatureGate, the
 * same posture the individual row-action classes used before consolidation.
 *
 * `definitions()` carries server-only bits (the build flag/filter, the FeatureGate key,
 * and a `supports` closure for per-object eligibility). `localized()` returns ONLY the
 * JS-safe subset the panel consumes (identical in shape to the prior ActionPanelAssets
 * config), plus additive `label`/`icon`/`objectTypes` for the chooser.
 */

namespace WPCommandCenter\Admin;

use WPCommandCenter\Content\ContentFieldGenerator;
use WPCommandCenter\Seo\SeoMetaGenerator;

defined( 'ABSPATH' ) || exit;

final class AiActionRegistry {

	/** Object types the content/SEO actions apply to (Products only matter when a Products screen exists). */
	private const CONTENT_TYPES = [ 'post', 'page', 'product' ];

	/** Object types the media actions apply to. */
	private const MEDIA_TYPES = [ 'attachment' ];

	/**
	 * Raw action definitions, keyed by action id. Ordered: title, excerpt, seo, alt_text.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function definitions(): array {
		return [
			'title'    => $this->content_def( 'title' ),
			'excerpt'  => $this->content_def( 'excerpt' ),
			'seo'      => $this->seo_def(),
			'alt_text' => $this->alt_def(),
		];
	}

	/** Whether an action is enabled for the current user/site (cap + build flag + FeatureGate). */
	public function is_enabled( string $id ): bool {
		$defs = $this->definitions();
		if ( ! isset( $defs[ $id ] ) ) {
			return false;
		}
		$def = $defs[ $id ];
		return current_user_can( 'manage_options' )
			&& $this->flag( (string) $def['build_flag'], (string) $def['filter'] )
			&& FeatureGate::allows( (string) $def['feature_key'] );
	}

	/**
	 * Enabled action ids whose object_types include $type (no per-object status check).
	 * Used by ActionPanelAssets to localize the panel config for a screen.
	 *
	 * @return string[]
	 */
	public function enabled_for_type( string $type ): array {
		$ids = [];
		foreach ( $this->definitions() as $id => $def ) {
			if ( in_array( $type, (array) $def['object_types'], true ) && $this->is_enabled( $id ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Enabled + object-applicable + per-object-supported action ids for a specific post/attachment.
	 * Used by AiAssistRowActions to decide whether to show "AI Assist" and which actions it offers.
	 *
	 * @return string[]
	 */
	public function applicable_for_post( \WP_Post $post ): array {
		$ids = [];
		foreach ( $this->definitions() as $id => $def ) {
			if ( ! in_array( $post->post_type, (array) $def['object_types'], true ) ) {
				continue;
			}
			if ( ! $this->is_enabled( $id ) ) {
				continue;
			}
			$supports = $def['supports'] ?? null;
			if ( is_callable( $supports ) && ! $supports( $post ) ) {
				continue;
			}
			$ids[] = $id;
		}
		return $ids;
	}

	/**
	 * JS-safe localized config map for the given ids (the panel reads this).
	 * Shape preserved from the prior ActionPanelAssets config (title/generate/apply/
	 * suggest/fields/suggestUrl) plus additive label/icon/objectTypes for the chooser.
	 *
	 * @param string[] $ids
	 * @return array<string,array<string,mixed>>
	 */
	public function localized( array $ids ): array {
		$defs = $this->definitions();
		$out  = [];
		foreach ( $ids as $id ) {
			if ( ! isset( $defs[ $id ] ) ) {
				continue;
			}
			$def          = $defs[ $id ];
			$out[ $id ] = [
				'title'       => $def['panel_title'],
				'label'       => $def['label'],
				'icon'        => $def['icon'],
				'objectTypes' => array_values( (array) $def['object_types'] ),
				'generate'    => $def['generate'],
				'apply'       => $def['apply'],
				'suggest'     => $def['suggest'],
				'fields'      => $def['fields'],
				'suggestUrl'  => $def['suggest_url'],
			];
		}
		return $out;
	}

	/** No-JS fallback URL for an action (the relevant Builder surface). */
	public function fallback_url( string $id ): string {
		$defs = $this->definitions();
		return isset( $defs[ $id ] ) ? (string) $defs[ $id ]['fallback_url'] : esc_url_raw( admin_url( 'admin.php' ) );
	}

	// ── Definitions ────────────────────────────────────────────────────────────

	/** Title / Excerpt — ride the existing proposals generate branch; apply = content_update (top-level field). */
	private function content_def( string $kind ): array {
		$is_title = ( 'title' === $kind );
		return [
			'id'           => $kind,
			'label'        => $is_title ? __( 'Generate Title', 'wp-command-center' ) : __( 'Generate Excerpt', 'wp-command-center' ),
			'panel_title'  => $is_title ? __( 'Generate Title Suggestion', 'wp-command-center' ) : __( 'Generate Excerpt Suggestion', 'wp-command-center' ),
			// Distinct icons so the two content actions read differently at a glance:
			// Title = edit/pencil, Excerpt = lines-of-text.
			'icon'         => $is_title ? 'dashicons-edit' : 'dashicons-text',
			'object_types' => self::CONTENT_TYPES,
			'build_flag'   => 'WPCC_AI_CONTENT_UI',
			'filter'       => 'wpcc_ai_content_ui',
			'feature_key'  => $is_title ? 'title_generator' : 'excerpt_generator',
			'generate'     => [ 'path' => '/admin/proposals', 'body' => [ 'generate_kind' => $kind ] ],
			'apply'        => [ 'action' => 'content_update', 'idKey' => 'content_id' ],
			'suggest'      => null,
			'fields'       => [
				$is_title
					? [ 'key' => 'title', 'label' => __( 'Title', 'wp-command-center' ), 'type' => 'text', 'prior' => 'title' ]
					: [ 'key' => 'excerpt', 'label' => __( 'Excerpt', 'wp-command-center' ), 'type' => 'textarea', 'prior' => 'excerpt' ],
			],
			'suggest_url'  => esc_url_raw( admin_url( 'admin.php?page=wpcc-ai-content&tab=suggestions&kind=' . $kind ) ),
			'fallback_url' => esc_url_raw( admin_url( 'admin.php?page=wpcc-ai-content&tab=suggestions&kind=' . $kind ) ),
			'supports'     => static fn ( \WP_Post $post ): bool => ContentFieldGenerator::is_supported_status( (string) $post->post_status ),
		];
	}

	/** SEO Meta — existing /admin/seo/generate; apply = seo_update (nested under `seo`). */
	private function seo_def(): array {
		return [
			'id'           => 'seo',
			'label'        => __( 'Generate SEO Meta', 'wp-command-center' ),
			'panel_title'  => __( 'Generate SEO Suggestion', 'wp-command-center' ),
			'icon'         => 'dashicons-search',
			'object_types' => self::CONTENT_TYPES,
			'build_flag'   => 'WPCC_SEO_META_UI',
			'filter'       => 'wpcc_seo_meta_ui',
			'feature_key'  => 'seo_meta_generator',
			'generate'     => [ 'path' => '/admin/seo/generate', 'body' => 'post_ids' ],
			'apply'        => [ 'action' => 'seo_update', 'idKey' => 'content_id', 'nest' => 'seo' ],
			'suggest'      => 'seo',
			'fields'       => [
				[ 'key' => 'title', 'label' => __( 'SEO title', 'wp-command-center' ), 'type' => 'text', 'prior' => 'title' ],
				[ 'key' => 'description', 'label' => __( 'Meta description', 'wp-command-center' ), 'type' => 'textarea', 'prior' => 'description' ],
			],
			'suggest_url'  => esc_url_raw( admin_url( 'admin.php?page=wpcc-seo&tab=suggestions' ) ),
			'fallback_url' => esc_url_raw( admin_url( 'admin.php?page=wpcc-seo&tab=suggestions' ) ),
			'supports'     => static fn ( \WP_Post $post ): bool => SeoMetaGenerator::is_supported_status( (string) $post->post_status ),
		];
	}

	/** Alt Text — existing /admin/alt-text/generate; apply = media_update (top-level alt). */
	private function alt_def(): array {
		return [
			'id'           => 'alt_text',
			'label'        => __( 'Generate Alt Text', 'wp-command-center' ),
			'panel_title'  => __( 'Generate Alt Text', 'wp-command-center' ),
			'icon'         => 'dashicons-format-image',
			'object_types' => self::MEDIA_TYPES,
			'build_flag'   => 'WPCC_ALT_TEXT_UI',
			'filter'       => 'wpcc_alt_text_ui',
			'feature_key'  => 'ai_alt_text',
			'generate'     => [ 'path' => '/admin/alt-text/generate', 'body' => 'attachment_ids' ],
			'apply'        => [ 'action' => 'media_update', 'idKey' => 'media_id' ],
			'suggest'      => null,
			'fields'       => [
				[ 'key' => 'alt', 'label' => __( 'Alt text', 'wp-command-center' ), 'type' => 'textarea', 'prior' => 'alt' ],
			],
			'suggest_url'  => esc_url_raw( admin_url( 'admin.php?page=wpcc-alt-text' ) ),
			'fallback_url' => esc_url_raw( admin_url( 'admin.php?page=wpcc-alt-text' ) ),
			'supports'     => static fn ( \WP_Post $post ): bool => wp_attachment_is_image( $post->ID ),
		];
	}

	/** Build-flag check: constant OR filter (mirrors the per-surface UI flags). */
	private function flag( string $const, string $filter ): bool {
		if ( defined( $const ) && constant( $const ) ) {
			return true;
		}
		return (bool) apply_filters( $filter, false );
	}
}
