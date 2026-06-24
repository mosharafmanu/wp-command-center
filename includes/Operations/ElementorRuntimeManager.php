<?php
/**
 * STEP 96 — Elementor Runtime.
 *
 * Read and edit Elementor pages over REST + MCP by operating on the page's
 * `_elementor_data` element tree (sections → columns → widgets). Reads export
 * the structure / widget list; edits update a widget's text, image, or button
 * by widget id, then persist and clear Elementor's cache. Edits are
 * rollback-capable (full pre-edit `_elementor_data` snapshot) and audited.
 */

namespace WPCommandCenter\Operations;

use WPCommandCenter\Security\AuditLog;
use WPCommandCenter\Rollback\RollbackDelta;
use WPCommandCenter\Rollback\PostMetaRollbackStore;
use WPCommandCenter\Rollback\ElementorDataAccessor;

defined( 'ABSPATH' ) || exit;

final class ElementorRuntimeManager {

	/** Primary text setting key per widget type. */
	private const TEXT_FIELD = [
		'heading'     => 'title',
		'text-editor' => 'editor',
		'button'      => 'text',
	];

	/** PROGRAM-4.10 — postmeta-per-record store prefix for _elementor_data delta records. */
	private const RB_PREFIX = '_wpcc_elementor_rb_';

	private AuditLog $audit;

	public function __construct() {
		$this->audit = new AuditLog();
	}

	public function run( array $payload, array $context = [] ): array {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			return $this->error( 'wpcc_elementor_inactive', __( 'Elementor is not active.', 'wp-command-center' ) );
		}
		$action = (string) ( $payload['action'] ?? '' );
		if ( ! in_array( $action, ElementorRegistry::ACTIONS, true ) ) {
			return $this->error( 'wpcc_invalid_elementor_action', __( 'Invalid Elementor action.', 'wp-command-center' ) );
		}

		return match ( $action ) {
			ElementorRegistry::ACTION_GET_PAGE         => $this->get_page( $payload ),
			ElementorRegistry::ACTION_EXPORT_STRUCTURE => $this->export_structure( $payload ),
			ElementorRegistry::ACTION_LIST_WIDGETS     => $this->list_widgets( $payload ),
			ElementorRegistry::ACTION_UPDATE_TEXT      => $this->update_text( $payload, $context ),
			ElementorRegistry::ACTION_UPDATE_IMAGE     => $this->update_image( $payload, $context ),
			ElementorRegistry::ACTION_UPDATE_BUTTON    => $this->update_button( $payload, $context ),
			default => $this->error( 'wpcc_invalid_elementor_action', __( 'Invalid Elementor action.', 'wp-command-center' ) ),
		};
	}

	// ── Read ─────────────────────────────────────────────────────

	private function get_page( array $p ): array {
		$id = $this->page_id( $p );
		$data = $this->load_data( $id );
		if ( is_string( $data ) ) return $this->error( $data, __( 'Not an Elementor page.', 'wp-command-center' ) );

		$this->audit->record( 'elementor.get_page', [ 'page_id' => $id ] );
		return [ 'action' => 'elementor_get_page', 'page_id' => $id, 'title' => get_the_title( $id ), 'data' => $data ];
	}

	private function export_structure( array $p ): array {
		$id = $this->page_id( $p );
		$data = $this->load_data( $id );
		if ( is_string( $data ) ) return $this->error( $data, __( 'Not an Elementor page.', 'wp-command-center' ) );

		return [ 'action' => 'elementor_export_structure', 'page_id' => $id, 'structure' => array_map( [ $this, 'summarize_element' ], $data ) ];
	}

	private function list_widgets( array $p ): array {
		$id = $this->page_id( $p );
		$data = $this->load_data( $id );
		if ( is_string( $data ) ) return $this->error( $data, __( 'Not an Elementor page.', 'wp-command-center' ) );

		$widgets = [];
		$this->walk_widgets( $data, function ( array $w ) use ( &$widgets ) {
			$widgets[] = [
				'id'          => $w['id'] ?? '',
				'widget_type' => $w['widgetType'] ?? '',
				'text'        => $this->widget_text( $w ),
			];
		} );

		return [ 'action' => 'elementor_list_widgets', 'page_id' => $id, 'widgets' => $widgets, 'total' => count( $widgets ) ];
	}

	// ── Edit ─────────────────────────────────────────────────────

	private function update_text( array $p, array $cx ): array {
		return $this->edit_widget( $p, $cx, 'elementor_update_text', function ( array $settings, string $type ) use ( $p ) {
			$text  = (string) ( $p['text'] ?? '' );
			$field = isset( $p['field'] ) ? sanitize_key( (string) $p['field'] ) : ( self::TEXT_FIELD[ $type ] ?? 'title' );
			$settings[ $field ] = wp_kses_post( $text );
			return $settings;
		} );
	}

	private function update_image( array $p, array $cx ): array {
		$url = esc_url_raw( (string) ( $p['image_url'] ?? '' ) );
		if ( '' === $url && empty( $p['image_id'] ) ) {
			return $this->error( 'wpcc_missing_image', __( 'image_url or image_id is required.', 'wp-command-center' ) );
		}
		return $this->edit_widget( $p, $cx, 'elementor_update_image', function ( array $settings ) use ( $p, $url ) {
			$img = is_array( $settings['image'] ?? null ) ? $settings['image'] : [];
			if ( '' !== $url ) $img['url'] = $url;
			if ( ! empty( $p['image_id'] ) ) $img['id'] = (int) $p['image_id'];
			$settings['image'] = $img;
			return $settings;
		} );
	}

	private function update_button( array $p, array $cx ): array {
		if ( ! isset( $p['text'] ) && ! isset( $p['url'] ) ) {
			return $this->error( 'wpcc_missing_button_fields', __( 'Provide text and/or url for the button.', 'wp-command-center' ) );
		}
		return $this->edit_widget( $p, $cx, 'elementor_update_button', function ( array $settings ) use ( $p ) {
			if ( isset( $p['text'] ) ) $settings['text'] = sanitize_text_field( (string) $p['text'] );
			if ( isset( $p['url'] ) ) {
				$link = is_array( $settings['link'] ?? null ) ? $settings['link'] : [];
				$link['url'] = esc_url_raw( (string) $p['url'] );
				$settings['link'] = $link;
			}
			return $settings;
		} );
	}

	/**
	 * Shared edit path: locate the widget by id, apply $mutator to its settings,
	 * persist, clear cache, and record a rollback.
	 */
	private function edit_widget( array $p, array $cx, string $action, callable $mutator ): array {
		$id = $this->page_id( $p );
		$widget_id = sanitize_text_field( (string) ( $p['widget_id'] ?? '' ) );
		if ( '' === $widget_id ) return $this->error( 'wpcc_missing_widget_id', __( 'widget_id is required.', 'wp-command-center' ) );

		$data = $this->load_data( $id );
		if ( is_string( $data ) ) return $this->error( $data, __( 'Not an Elementor page.', 'wp-command-center' ) );

		// PROGRAM-4.10 — capture the WHOLE pre-edit _elementor_data document atomically (never
		// decomposed), drift-aware via the RollbackDelta core. Replaces the unconditional
		// whole-document option-blob restore (which clobbered concurrent edits to other widgets).
		$acc   = new ElementorDataAccessor();
		$prior = RollbackDelta::capture( $acc, $id, [ 'data' ] );

		$found = $this->mutate_widget( $data, $widget_id, $mutator );
		if ( ! $found ) return $this->error( 'wpcc_widget_not_found', sprintf( __( 'Widget %s not found on this page.', 'wp-command-center' ), esc_html( $widget_id ) ) );

		$this->save_data( $id, $data );

		$after       = [ 'data' => $acc->read_field( $id, 'data' ) ];
		$rollback_id = wp_generate_uuid4();
		$record      = RollbackDelta::build_record( [ 'data' ], $prior, $after, $cx, [
			'id' => $rollback_id, 'page_id' => $id, 'action' => $action,
		] );
		( new PostMetaRollbackStore( self::RB_PREFIX ) )->persist( $id, $rollback_id, $record );

		$this->audit->record( str_replace( 'elementor_', 'elementor.', $action ), [ 'page_id' => $id, 'widget_id' => $widget_id ] );
		return [ 'action' => $action, 'page_id' => $id, 'widget_id' => $widget_id, 'rollback_id' => $rollback_id ];
	}

	// ── Rollback ─────────────────────────────────────────────────

	public function rollback( array $payload, array $context = [] ): array {
		$rid = (string) ( $payload['rollback_id'] ?? '' );
		if ( '' === $rid ) return $this->error( 'wpcc_missing_rollback_id', __( 'Rollback ID required.', 'wp-command-center' ) );

		// PROGRAM-4.10 — v2 whole-document delta records live in postmeta (per page), resolved by id.
		$store    = new PostMetaRollbackStore( self::RB_PREFIX );
		$resolved = $store->resolve( $rid );
		if ( null !== $resolved ) {
			return $this->rollback_data_delta( $store, $rid, $resolved );
		}

		// Legacy option records (pre-P4.10): unchanged unconditional whole-document restore.
		$rollbacks = get_option( 'wpcc_elementor_rollbacks', [] );
		$idx = null;
		foreach ( $rollbacks as $i => $r ) { if ( ( $r['id'] ?? null ) === $rid ) { $idx = $i; break; } }
		if ( null === $idx ) return $this->error( 'wpcc_rollback_not_found', __( 'Rollback not found.', 'wp-command-center' ) );
		if ( ! empty( $rollbacks[ $idx ]['rollback_applied'] ) ) return $this->error( 'wpcc_rollback_already_applied', __( 'Already applied.', 'wp-command-center' ) );

		$rec = $rollbacks[ $idx ];
		$id  = (int) $rec['entity_id'];
		$json = (string) ( $rec['before_state']['data'] ?? '' );
		update_post_meta( $id, '_elementor_data', wp_slash( $json ) );
		$this->clear_cache( $id );

		$rollbacks[ $idx ]['rollback_applied'] = true;
		update_option( 'wpcc_elementor_rollbacks', $rollbacks );
		$this->audit->record( 'elementor.rollback', [ 'rollback_id' => $rid, 'page_id' => $id, 'path' => 'legacy' ] );
		return [ 'action' => 'elementor_rollback', 'rollback_id' => $rid, 'page_id' => $id, 'restored' => true, 'path' => 'legacy' ];
	}

	/**
	 * PROGRAM-4.10 — drift-aware whole-document restore of a v2 delta record. Marks applied only
	 * on a complete restore; a drift conflict is an honest error envelope that stays retryable
	 * (never clobbers a newer edit to any widget on the page).
	 *
	 * @param array{entity_id:mixed,record:array<string,mixed>} $resolved
	 */
	private function rollback_data_delta( PostMetaRollbackStore $store, string $rid, array $resolved ): array {
		$rec = $resolved['record'];
		$id  = (int) ( $resolved['entity_id'] ?? ( $rec['page_id'] ?? 0 ) );
		if ( ! empty( $rec['rollback_applied'] ) ) return $this->error( 'wpcc_rollback_already_applied', __( 'Already applied.', 'wp-command-center' ) );

		$o = RollbackDelta::restore( new ElementorDataAccessor(), $id, (array) ( $rec['fields'] ?? [] ) );

		if ( 'complete' === $o['status'] ) {
			$this->clear_cache( $id );
			$rec['rollback_applied'] = true;
			$rec['applied_at']       = time();
			$store->mark_applied( $id, $rid, $rec );
			$this->audit->record( 'elementor.rollback', [ 'rollback_id' => $rid, 'page_id' => $id, 'status' => 'complete' ] );
			return [ 'action' => 'elementor_rollback', 'rollback_id' => $rid, 'page_id' => $id, 'status' => 'complete', 'restored' => true, 'reversible' => true ];
		}

		// drift → conflict: not applied, retryable, honest (never a clean success, never clobbers).
		$this->audit->record( 'elementor.rollback', [ 'rollback_id' => $rid, 'page_id' => $id, 'status' => $o['status'] ] );
		return [ 'action' => 'elementor_rollback', 'rollback_id' => $rid, 'page_id' => $id, 'error' => true,
			'code' => 'wpcc_rollback_conflict', 'status' => $o['status'], 'restored' => false, 'reversible' => false ];
	}

	// ── Helpers ──────────────────────────────────────────────────

	private function page_id( array $p ): int {
		return (int) ( $p['page_id'] ?? $p['post_id'] ?? $p['content_id'] ?? 0 );
	}

	/** @return array|string Decoded element tree, or an error code string. */
	private function load_data( int $id ) {
		if ( $id <= 0 || ! get_post( $id ) ) return 'wpcc_page_not_found';
		$raw = get_post_meta( $id, '_elementor_data', true );
		if ( empty( $raw ) ) return 'wpcc_not_elementor_page';
		$data = json_decode( is_string( $raw ) ? $raw : wp_json_encode( $raw ), true );
		if ( ! is_array( $data ) ) return 'wpcc_elementor_data_corrupt';
		return $data;
	}

	private function save_data( int $id, array $data ): void {
		update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
		$this->clear_cache( $id );
	}

	private function clear_cache( int $id ): void {
		delete_post_meta( $id, '_elementor_css' );
		if ( class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
	}

	/** Apply $mutator to the settings of the widget with $widget_id. */
	private function mutate_widget( array &$elements, string $widget_id, callable $mutator ): bool {
		foreach ( $elements as &$el ) {
			if ( ( $el['id'] ?? '' ) === $widget_id && 'widget' === ( $el['elType'] ?? '' ) ) {
				$el['settings'] = $mutator( is_array( $el['settings'] ?? null ) ? $el['settings'] : [], (string) ( $el['widgetType'] ?? '' ) );
				return true;
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				if ( $this->mutate_widget( $el['elements'], $widget_id, $mutator ) ) return true;
			}
		}
		return false;
	}

	private function walk_widgets( array $elements, callable $cb ): void {
		foreach ( $elements as $el ) {
			if ( 'widget' === ( $el['elType'] ?? '' ) ) $cb( $el );
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) $this->walk_widgets( $el['elements'], $cb );
		}
	}

	private function summarize_element( array $el ): array {
		$out = [ 'id' => $el['id'] ?? '', 'elType' => $el['elType'] ?? '' ];
		if ( 'widget' === ( $el['elType'] ?? '' ) ) $out['widgetType'] = $el['widgetType'] ?? '';
		if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
			$out['children'] = array_map( [ $this, 'summarize_element' ], $el['elements'] );
		}
		return $out;
	}

	private function widget_text( array $w ): string {
		$type     = (string) ( $w['widgetType'] ?? '' );
		$settings = is_array( $w['settings'] ?? null ) ? $w['settings'] : [];
		$field    = self::TEXT_FIELD[ $type ] ?? 'title';
		$val      = $settings[ $field ] ?? '';
		return is_string( $val ) ? wp_strip_all_tags( $val ) : '';
	}

	private function error( string $code, string $message ): array {
		return [ 'error' => true, 'code' => $code, 'message' => $message ];
	}
}
