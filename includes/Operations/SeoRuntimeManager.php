<?php
/**
 * STEP 91 — SEO Runtime.
 *
 * Unified SEO management over REST + MCP, independent of the active SEO plugin
 * (Rank Math or Yoast) via SeoProvider. Read/validate/analyze are diagnostic;
 * seo_update is a rollback-capable, audited write.
 *
 * Actions: seo_get, seo_update, seo_validate, seo_analyze, seo_restore.
 */

namespace WPCommandCenter\Operations;

use WPCommandCenter\Security\AuditLog;

defined( 'ABSPATH' ) || exit;

final class SeoRuntimeManager {

	/** Recommended length bounds used by validate/analyze. */
	private const TITLE_MAX = 60;
	private const DESC_MIN  = 120;
	private const DESC_MAX  = 160;

	/**
	 * Slice 4c — protected post-meta key prefix for SEO rollback snapshots. One
	 * meta row per rollback, keyed `_wpcc_seo_rb_{rollback_id}`. Leading underscore
	 * makes it protected (hidden from the Custom Fields UI / unauthorized core REST
	 * meta). Replaces the capped, autoloaded `wpcc_seo_rollbacks` option for new
	 * rollbacks; the option is still read as a backward-compat fallback.
	 */
	private const ROLLBACK_META_PREFIX  = '_wpcc_seo_rb_';
	private const LEGACY_ROLLBACK_OPTION = 'wpcc_seo_rollbacks';

	private AuditLog $audit;

	public function __construct() {
		$this->audit = new AuditLog();
	}

	public function run( array $payload, array $context = [] ): array {
		$action = (string) ( $payload['action'] ?? '' );

		if ( ! in_array( $action, SeoRegistry::ACTIONS, true ) ) {
			return $this->error( 'wpcc_invalid_seo_action', __( 'Invalid SEO action.', 'wp-command-center' ) );
		}

		// Every action needs an SEO provider except none-specific reporting.
		$provider = SeoProvider::detect();
		if ( SeoProvider::NONE === $provider && SeoRegistry::ACTION_VALIDATE !== $action ) {
			return $this->error( 'wpcc_seo_no_provider', __( 'No supported SEO plugin (Rank Math or Yoast SEO) is active.', 'wp-command-center' ) );
		}

		return match ( $action ) {
			SeoRegistry::ACTION_GET      => $this->seo_get( $payload, $provider ),
			SeoRegistry::ACTION_UPDATE   => $this->seo_update( $payload, $provider, $context ),
			SeoRegistry::ACTION_VALIDATE => $this->seo_validate( $payload, $provider ),
			SeoRegistry::ACTION_ANALYZE  => $this->seo_analyze( $payload, $provider ),
			SeoRegistry::ACTION_RESTORE  => $this->seo_restore( $payload, $provider, $context ),
			default                      => $this->error( 'wpcc_invalid_seo_action', __( 'Invalid SEO action.', 'wp-command-center' ) ),
		};
	}

	private function seo_get( array $payload, string $provider ): array {
		$post = $this->resolve_post( $payload );
		if ( is_string( $post ) ) {
			return $this->error( $post, __( 'Content not found.', 'wp-command-center' ) );
		}

		$this->audit->record( 'seo.get', [ 'post_id' => $post->ID, 'provider' => $provider ] );

		return [
			'action'   => 'seo_get',
			'provider' => $provider,
			'post_id'  => $post->ID,
			'seo'      => SeoProvider::read( $post->ID, $provider ),
		];
	}

	private function seo_update( array $payload, string $provider, array $context ): array {
		$post = $this->resolve_post( $payload );
		if ( is_string( $post ) ) {
			return $this->error( $post, __( 'Content not found.', 'wp-command-center' ) );
		}

		$fields = $this->extract_fields( $payload );
		if ( empty( $fields ) ) {
			return $this->error( 'wpcc_seo_no_fields', __( 'Provide at least one SEO field to update.', 'wp-command-center' ) );
		}

		// Structural validation before writing.
		$issues = $this->validate_fields( $fields );
		$errors = array_filter( $issues, static fn( $i ) => 'error' === $i['severity'] );
		if ( ! empty( $errors ) ) {
			return $this->error( 'wpcc_seo_invalid_field', implode( ' ', array_map( static fn( $i ) => $i['message'], $errors ) ) );
		}

		$before      = SeoProvider::read( $post->ID, $provider );
		$rollback_id = $this->store_rollback( $post->ID, $provider, $before, $context );

		$updated = SeoProvider::write( $post->ID, $fields, $provider );

		$this->audit->record( 'seo.updated', [
			'post_id'  => $post->ID,
			'provider' => $provider,
			'fields'   => array_keys( $fields ),
		] );

		return [
			'action'      => 'seo_update',
			'provider'    => $provider,
			'post_id'     => $post->ID,
			'seo'         => $updated,
			'rollback_id' => $rollback_id,
		];
	}

	private function seo_validate( array $payload, string $provider ): array {
		// Validate either supplied fields or the post's current SEO.
		$fields = $this->extract_fields( $payload );
		if ( empty( $fields ) ) {
			$post = $this->resolve_post( $payload );
			if ( is_string( $post ) ) {
				return $this->error( $post, __( 'Provide fields or a valid content_id to validate.', 'wp-command-center' ) );
			}
			$fields = SeoProvider::read( $post->ID, SeoProvider::NONE === $provider ? SeoProvider::YOAST : $provider );
		}

		$issues = $this->validate_fields( $fields );
		$valid  = empty( array_filter( $issues, static fn( $i ) => 'error' === $i['severity'] ) );

		return [
			'action' => 'seo_validate',
			'valid'  => $valid,
			'issues' => array_values( $issues ),
		];
	}

	private function seo_analyze( array $payload, string $provider ): array {
		$post = $this->resolve_post( $payload );
		if ( is_string( $post ) ) {
			return $this->error( $post, __( 'Content not found.', 'wp-command-center' ) );
		}

		$seo     = SeoProvider::read( $post->ID, $provider );
		$title   = (string) $seo['title'];
		$desc    = (string) $seo['description'];
		$kw      = strtolower( trim( (string) $seo['focus_keyword'] ) );
		$content = strtolower( wp_strip_all_tags( (string) $post->post_content ) );
		$haystk  = strtolower( $title . ' ' . get_the_title( $post ) );

		$checks = [];
		$checks[] = $this->check( 'title_present', '' !== $title, __( 'An SEO title is set.', 'wp-command-center' ) );
		$checks[] = $this->check( 'title_length', '' !== $title && mb_strlen( $title ) <= self::TITLE_MAX, sprintf( __( 'SEO title is within %d characters (is %d).', 'wp-command-center' ), self::TITLE_MAX, mb_strlen( $title ) ) );
		$checks[] = $this->check( 'description_present', '' !== $desc, __( 'A meta description is set.', 'wp-command-center' ) );
		$checks[] = $this->check( 'description_length', mb_strlen( $desc ) >= self::DESC_MIN && mb_strlen( $desc ) <= self::DESC_MAX, sprintf( __( 'Meta description is %d–%d characters (is %d).', 'wp-command-center' ), self::DESC_MIN, self::DESC_MAX, mb_strlen( $desc ) ) );
		$checks[] = $this->check( 'focus_keyword_present', '' !== $kw, __( 'A focus keyword is set.', 'wp-command-center' ) );
		$checks[] = $this->check( 'focus_keyword_in_title', '' !== $kw && str_contains( $haystk, $kw ), __( 'Focus keyword appears in the title.', 'wp-command-center' ) );
		$checks[] = $this->check( 'focus_keyword_in_description', '' !== $kw && str_contains( strtolower( $desc ), $kw ), __( 'Focus keyword appears in the meta description.', 'wp-command-center' ) );
		$checks[] = $this->check( 'focus_keyword_in_content', '' !== $kw && str_contains( $content, $kw ), __( 'Focus keyword appears in the content.', 'wp-command-center' ) );
		$checks[] = $this->check( 'canonical_set', '' !== (string) $seo['canonical'], __( 'A canonical URL is set.', 'wp-command-center' ) );
		$checks[] = $this->check( 'open_graph_set', '' !== (string) $seo['og_title'] || '' !== (string) $seo['og_description'], __( 'Open Graph metadata is set.', 'wp-command-center' ) );

		$passed = count( array_filter( $checks, static fn( $c ) => $c['passed'] ) );
		$score  = (int) round( ( $passed / count( $checks ) ) * 100 );

		$this->audit->record( 'seo.analyzed', [ 'post_id' => $post->ID, 'provider' => $provider, 'score' => $score ] );

		return [
			'action'   => 'seo_analyze',
			'provider' => $provider,
			'post_id'  => $post->ID,
			'score'    => $score,
			'passed'   => $passed,
			'total'    => count( $checks ),
			'checks'   => $checks,
		];
	}

	private function seo_restore( array $payload, string $provider, array $context ): array {
		$rollback_id = (string) ( $payload['rollback_id'] ?? '' );
		if ( '' === $rollback_id ) {
			return $this->error( 'wpcc_missing_rollback_id', __( 'Rollback ID is required.', 'wp-command-center' ) );
		}

		// Slice 4c — current store: resolve the per-post snapshot by rollback_id
		// alone (seo_restore is dispatched with only the rollback_id — never a
		// post_id). meta_key is indexed, so this is one indexed lookup; get_post_meta
		// then returns the unserialized record from the meta cache.
		global $wpdb;
		$meta_key = self::ROLLBACK_META_PREFIX . $rollback_id;
		$post_id  = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1", $meta_key )
		);

		if ( $post_id > 0 ) {
			$record = get_post_meta( $post_id, $meta_key, true );
			if ( ! is_array( $record ) ) {
				return $this->error( 'wpcc_rollback_not_found', __( 'Rollback record not found.', 'wp-command-center' ) );
			}
			if ( ! empty( $record['rollback_applied'] ) ) {
				return $this->error( 'wpcc_rollback_already_applied', __( 'Rollback already applied.', 'wp-command-center' ) );
			}

			SeoProvider::write( (int) $record['post_id'], $record['before_state'], $record['provider'] );

			$record['rollback_applied'] = true;
			update_post_meta( $post_id, $meta_key, $record );

			$this->audit->record( 'seo.restored', [ 'post_id' => $record['post_id'], 'rollback_id' => $rollback_id ] );

			return [ 'action' => 'seo_restore', 'post_id' => (int) $record['post_id'], 'rollback_id' => $rollback_id, 'restored' => true ];
		}

		// Backward-compat: records created before Slice 4c still live in the legacy
		// option (a draining set; new rollbacks never write there).
		return $this->seo_restore_legacy( $rollback_id );
	}

	/**
	 * Slice 4c — legacy fallback. Restore a rollback record that still lives in the
	 * pre-4c `wpcc_seo_rollbacks` option. Behavior is identical to the pre-4c
	 * restore, including marking the option record rollback_applied = true.
	 */
	private function seo_restore_legacy( string $rollback_id ): array {
		$rollbacks = get_option( self::LEGACY_ROLLBACK_OPTION, [] );
		$idx       = null;
		foreach ( $rollbacks as $i => $r ) {
			if ( ( $r['id'] ?? '' ) === $rollback_id ) {
				$idx = $i;
				break;
			}
		}
		if ( null === $idx ) {
			return $this->error( 'wpcc_rollback_not_found', __( 'Rollback record not found.', 'wp-command-center' ) );
		}
		if ( ! empty( $rollbacks[ $idx ]['rollback_applied'] ) ) {
			return $this->error( 'wpcc_rollback_already_applied', __( 'Rollback already applied.', 'wp-command-center' ) );
		}

		$record = $rollbacks[ $idx ];
		SeoProvider::write( (int) $record['post_id'], $record['before_state'], $record['provider'] );

		$rollbacks[ $idx ]['rollback_applied'] = true;
		update_option( self::LEGACY_ROLLBACK_OPTION, $rollbacks );

		$this->audit->record( 'seo.restored', [ 'post_id' => $record['post_id'], 'rollback_id' => $rollback_id ] );

		return [ 'action' => 'seo_restore', 'post_id' => (int) $record['post_id'], 'rollback_id' => $rollback_id, 'restored' => true ];
	}

	// ── Helpers ──────────────────────────────────────────────────

	/**
	 * @return \WP_Post|string WP_Post or an error code string.
	 */
	private function resolve_post( array $payload ) {
		$post_id = (int) ( $payload['content_id'] ?? $payload['post_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			return 'wpcc_seo_missing_content_id';
		}
		$post = get_post( $post_id );
		if ( ! $post || 'attachment' === $post->post_type ) {
			return 'wpcc_seo_post_not_found';
		}
		return $post;
	}

	/**
	 * Extract recognized unified SEO fields from the payload.
	 *
	 * @return array<string,mixed>
	 */
	private function extract_fields( array $payload ): array {
		$source = isset( $payload['seo'] ) && is_array( $payload['seo'] ) ? $payload['seo'] : $payload;
		$fields = [];

		foreach ( SeoProvider::SCALAR_FIELDS as $field ) {
			if ( array_key_exists( $field, $source ) ) {
				$fields[ $field ] = is_scalar( $source[ $field ] ) ? (string) $source[ $field ] : '';
			}
		}
		if ( array_key_exists( 'robots', $source ) ) {
			$fields['robots'] = is_array( $source['robots'] ) ? $source['robots'] : array_filter( array_map( 'trim', explode( ',', (string) $source['robots'] ) ) );
		}

		return $fields;
	}

	/**
	 * @param array<string,mixed> $fields
	 * @return array<int,array{field:string,severity:string,message:string}>
	 */
	private function validate_fields( array $fields ): array {
		$issues = [];

		if ( isset( $fields['title'] ) && mb_strlen( (string) $fields['title'] ) > self::TITLE_MAX ) {
			$issues[] = [ 'field' => 'title', 'severity' => 'warning', 'message' => sprintf( __( 'SEO title exceeds %d characters and may be truncated.', 'wp-command-center' ), self::TITLE_MAX ) ];
		}
		if ( isset( $fields['description'] ) && '' !== (string) $fields['description'] ) {
			$len = mb_strlen( (string) $fields['description'] );
			if ( $len > self::DESC_MAX ) {
				$issues[] = [ 'field' => 'description', 'severity' => 'warning', 'message' => sprintf( __( 'Meta description exceeds %d characters and may be truncated.', 'wp-command-center' ), self::DESC_MAX ) ];
			} elseif ( $len < self::DESC_MIN ) {
				$issues[] = [ 'field' => 'description', 'severity' => 'info', 'message' => sprintf( __( 'Meta description is under the recommended %d characters.', 'wp-command-center' ), self::DESC_MIN ) ];
			}
		}
		if ( isset( $fields['canonical'] ) && '' !== (string) $fields['canonical'] && ! wp_http_validate_url( (string) $fields['canonical'] ) ) {
			$issues[] = [ 'field' => 'canonical', 'severity' => 'error', 'message' => __( 'Canonical URL is not a valid URL.', 'wp-command-center' ) ];
		}
		if ( isset( $fields['robots'] ) ) {
			foreach ( (array) $fields['robots'] as $d ) {
				if ( ! in_array( strtolower( trim( (string) $d ) ), SeoProvider::ROBOTS_DIRECTIVES, true ) ) {
					$issues[] = [ 'field' => 'robots', 'severity' => 'error', 'message' => sprintf( __( 'Unknown robots directive: %s', 'wp-command-center' ), esc_html( (string) $d ) ) ];
				}
			}
		}

		return $issues;
	}

	private function check( string $name, bool $passed, string $detail ): array {
		return [ 'check' => $name, 'passed' => $passed, 'detail' => $detail ];
	}

	/**
	 * Slice 4c — persist one SEO rollback snapshot as a dedicated protected post-meta
	 * row keyed by the rollback_id (`_wpcc_seo_rb_{id}`). One row per rollback: no
	 * global cap, no FIFO eviction, not autoloaded, and free of the shared-option
	 * lost-update race. New rollbacks NO LONGER write the `wpcc_seo_rollbacks` option
	 * (seo_restore still reads it for pre-4c records). The rollback_id contract is
	 * unchanged — the same uuid4 is returned and recorded by ChangeRecorder.
	 */
	private function store_rollback( int $post_id, string $provider, array $before, array $context ): string {
		$rollback_id = wp_generate_uuid4();

		$record = [
			'id'               => $rollback_id,
			'post_id'          => $post_id,
			'provider'         => $provider,
			'before_state'     => $before,
			'rollback_applied' => false,
			'created_at'       => time(),
			'session_id'       => $context['session_id'] ?? null,
			'task_id'          => $context['task_id'] ?? null,
		];

		add_post_meta( $post_id, self::ROLLBACK_META_PREFIX . $rollback_id, $record, true );

		return $rollback_id;
	}

	private function error( string $code, string $message ): array {
		return [ 'error' => true, 'code' => $code, 'message' => $message ];
	}
}
