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

		// Phase 3 (F-1) — capture ONLY the backing meta keys this update touches, each
		// with its prior raw value and prior existence, BEFORE the write. This replaces
		// the full-object `before_state` snapshot that caused layered-rollback corruption.
		$prior = $this->capture_prior( $post->ID, array_keys( $fields ), $provider );

		$updated = SeoProvider::write( $post->ID, $fields, $provider );

		// Persist a field-scoped delta record. After-values come from the post-write read
		// ($updated) so drift detection can later compare live values against them.
		$rollback_id = $this->store_rollback( $post->ID, $provider, array_keys( $fields ), $prior, $updated, $context );

		$this->audit->record( 'seo.updated', [
			'post_id'         => $post->ID,
			'provider'        => $provider,
			'fields'          => array_keys( $fields ),
			'rollback_format' => 'delta',
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

			// Phase 3 (F-1) — field-scoped, drift-aware restore for v2 delta records.
			if ( isset( $record['fields'] ) && is_array( $record['fields'] ) ) {
				return $this->restore_delta( $post_id, $meta_key, $record );
			}

			// Pre-Phase-3 full-snapshot post-meta record: restore unchanged (forward-only,
			// no destructive migration).
			return $this->restore_legacy_meta( $post_id, $meta_key, $record, $rollback_id );
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

		$this->audit->record( 'seo.restored', [ 'post_id' => $record['post_id'], 'rollback_id' => $rollback_id, 'path' => 'legacy' ] );

		return [ 'action' => 'seo_restore', 'post_id' => (int) $record['post_id'], 'rollback_id' => $rollback_id, 'restored' => true, 'status' => 'complete', 'path' => 'legacy' ];
	}

	/**
	 * Phase 3 (F-1) — restore a pre-Phase-3 full-snapshot post-meta record. Behaviour is
	 * the unchanged pre-Phase-3 restore (whole `before_state` written back); historical
	 * records keep their original semantics — no destructive migration.
	 */
	private function restore_legacy_meta( int $post_id, string $meta_key, array $record, string $rollback_id ): array {
		SeoProvider::write( (int) $record['post_id'], $record['before_state'], $record['provider'] );

		$record['rollback_applied'] = true;
		update_post_meta( $post_id, $meta_key, $record );

		$this->audit->record( 'seo.restored', [ 'post_id' => $record['post_id'], 'rollback_id' => $rollback_id, 'path' => 'legacy' ] );

		return [ 'action' => 'seo_restore', 'post_id' => (int) $record['post_id'], 'rollback_id' => $rollback_id, 'restored' => true, 'status' => 'complete', 'path' => 'legacy' ];
	}

	/**
	 * Phase 3 (F-1) — field-scoped, drift-aware delta restore. For each field the
	 * original update touched, compare the current live value to the recorded `after`.
	 * If they differ the field has drifted (a later change touched it) — skip it and
	 * report a conflict rather than clobber a sibling/newer change. Otherwise restore
	 * that field's backing meta keys to their exact prior raw value and existence
	 * (existed=false ⇒ delete; existed=true ⇒ write the prior value, even when '').
	 *
	 * Only a clean `complete` restore is terminal (marks rollback_applied → idempotency
	 * guard). `partial`/`conflict` stay retryable so the correct multi-step recovery
	 * (roll back the newer change first, then retry the older) remains reachable; a retry
	 * never clobbers because drift always skips.
	 */
	private function restore_delta( int $post_id, string $meta_key, array $record ): array {
		$provider  = (string) $record['provider'];
		$restored  = [];
		$skipped   = [];
		$conflicts = [];

		foreach ( (array) $record['fields'] as $field => $spec ) {
			$field   = (string) $field;
			$after   = $spec['after'] ?? '';
			$current = SeoProvider::read_field( $post_id, $field, $provider );

			if ( ! $this->values_equal( $current, $after, $field ) ) {
				$skipped[]   = $field;
				$conflicts[] = [ 'field' => $field, 'reason' => 'drift', 'expected' => $after, 'current' => $current ];
				continue;
			}

			foreach ( (array) ( $spec['keys'] ?? [] ) as $key => $meta ) {
				$key = (string) $key;
				if ( ! empty( $meta['existed'] ) ) {
					update_post_meta( $post_id, $key, $meta['prior'] );
				} elseif ( metadata_exists( 'post', $post_id, $key ) ) {
					delete_post_meta( $post_id, $key );
				}
			}
			$restored[] = $field;
		}

		$status      = empty( $skipped ) ? 'complete' : ( empty( $restored ) ? 'conflict' : 'partial' );
		$rollback_id = (string) ( $record['id'] ?? '' );

		if ( 'complete' === $status ) {
			$record['rollback_applied'] = true;
			update_post_meta( $post_id, $meta_key, $record );
		}

		$this->audit->record( 'seo.restored', [
			'post_id'         => $post_id,
			'rollback_id'     => $rollback_id,
			'path'            => 'delta',
			'status'          => $status,
			'restored_fields' => $restored,
			'skipped_fields'  => $skipped,
			'conflicts'       => $conflicts,
		] );

		if ( 'complete' === $status ) {
			return [
				'action'          => 'seo_restore',
				'post_id'         => $post_id,
				'rollback_id'     => $rollback_id,
				'restored'        => true,
				'status'          => 'complete',
				'path'            => 'delta',
				'restored_fields' => $restored,
				'skipped_fields'  => [],
				'conflicts'       => [],
			];
		}

		$code = 'conflict' === $status ? 'wpcc_rollback_conflict' : 'wpcc_rollback_partial';
		$msg  = 'conflict' === $status
			? sprintf( __( 'Rollback skipped: every targeted SEO field (%s) changed since this update was applied. No fields were restored.', 'wp-command-center' ), implode( ', ', $skipped ) )
			: sprintf( __( 'Partial rollback: restored %1$s; skipped %2$s because they changed since this update was applied (drift).', 'wp-command-center' ), implode( ', ', $restored ), implode( ', ', $skipped ) );

		return [
			'error'           => true,
			'code'            => $code,
			'message'         => $msg,
			'action'          => 'seo_restore',
			'post_id'         => $post_id,
			'rollback_id'     => $rollback_id,
			'restored'        => false,
			'status'          => $status,
			'path'            => 'delta',
			'restored_fields' => $restored,
			'skipped_fields'  => $skipped,
			'conflicts'       => $conflicts,
		];
	}

	/**
	 * Phase 3 (F-1) — drift comparison. robots compares normalized directive sets
	 * (order-insensitive); scalars compare as strings — the same normalization
	 * seo_update applied when it produced the recorded `after` value.
	 *
	 * @param mixed $current
	 * @param mixed $expected
	 */
	private function values_equal( $current, $expected, string $field ): bool {
		if ( 'robots' === $field ) {
			$c = is_array( $current ) ? $current : [];
			$e = is_array( $expected ) ? $expected : [];
			sort( $c );
			sort( $e );
			return $c === $e;
		}
		return (string) $current === (string) $expected;
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
	 * Phase 3 (F-1) — snapshot the backing meta keys for the touched unified fields,
	 * each with prior existence + raw value, BEFORE the write. Captures only the keys
	 * the update touches (one per scalar field, the provider's robots key-set for
	 * robots) — never the full SEO object.
	 *
	 * @param string[] $touched
	 * @return array<string,array{keys:array<string,array{existed:bool,prior:mixed}>}>
	 */
	private function capture_prior( int $post_id, array $touched, string $provider ): array {
		$out = [];
		foreach ( $touched as $field ) {
			$keys = [];
			foreach ( SeoProvider::backing_keys( (string) $field, $provider ) as $key ) {
				$keys[ $key ] = [
					'existed' => metadata_exists( 'post', $post_id, $key ),
					'prior'   => get_post_meta( $post_id, $key, true ),
				];
			}
			$out[ (string) $field ] = [ 'keys' => $keys ];
		}
		return $out;
	}

	/**
	 * Phase 3 (F-1) — persist one field-scoped SEO rollback record (format v2) as a
	 * dedicated protected post-meta row keyed by the rollback_id (`_wpcc_seo_rb_{id}`).
	 * Stores ONLY the touched fields: each carries the post-write `after` value (for
	 * drift detection) and its backing meta keys' prior raw value + existence (for a
	 * faithful, sibling-safe restore). One row per rollback — no cap, no FIFO eviction,
	 * not autoloaded, no shared-option race. The rollback_id contract is unchanged — the
	 * same uuid4 is returned and recorded by ChangeRecorder. Slice 4c per-meta storage
	 * is preserved; only the record *shape* changed (`before_state` → `version`+`fields`).
	 *
	 * @param string[]             $touched   Unified field names the update wrote.
	 * @param array<string,mixed>  $prior     Per-field backing-key prior map (capture_prior).
	 * @param array<string,mixed>  $after_all Post-write unified read (SeoProvider::write return).
	 */
	private function store_rollback( int $post_id, string $provider, array $touched, array $prior, array $after_all, array $context ): string {
		$rollback_id = wp_generate_uuid4();

		$fields = [];
		foreach ( $touched as $field ) {
			$field            = (string) $field;
			$fields[ $field ] = [
				'after' => $after_all[ $field ] ?? '',
				'keys'  => $prior[ $field ]['keys'] ?? [],
			];
		}

		$record = [
			'id'               => $rollback_id,
			'version'          => 2,
			'post_id'          => $post_id,
			'provider'         => $provider,
			'fields'           => $fields,
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
