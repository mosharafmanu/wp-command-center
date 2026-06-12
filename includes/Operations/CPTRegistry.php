<?php
/**
 * Step 74 — Custom Post Type Registry.
 *
 * Discovers registered post types and taxonomies, provides metadata,
 * risk classification, and allowed operation definitions.
 */

namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class CPTRegistry {

	const RISK_LOW      = 'low';
	const RISK_MEDIUM   = 'medium';
	const RISK_HIGH     = 'high';
	const RISK_CRITICAL = 'critical';

	const RISK_LEVELS = [ 'low', 'medium', 'high', 'critical' ];

	const ACTION_CPT_LIST       = 'cpt_list';
	const ACTION_CPT_GET        = 'cpt_get';
	const ACTION_CPT_CREATE     = 'cpt_create';
	const ACTION_CPT_UPDATE     = 'cpt_update';
	const ACTION_CPT_DISABLE    = 'cpt_disable';
	const ACTION_TAXONOMY_LIST  = 'taxonomy_list';
	const ACTION_TAXONOMY_CREATE = 'taxonomy_create';
	const ACTION_TAXONOMY_UPDATE = 'taxonomy_update';

	const ACTIONS = [ 'cpt_list', 'cpt_get', 'cpt_create', 'cpt_update', 'cpt_disable', 'taxonomy_list', 'taxonomy_create', 'taxonomy_update', 'cpt_rollback' ];

	const CORE_POST_TYPES = [ 'post', 'page', 'attachment', 'revision', 'nav_menu_item', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation' ];

	public function action_risk( string $action ): string {
		return match ( $action ) {
			self::ACTION_CPT_LIST       => self::RISK_LOW,
			self::ACTION_CPT_GET        => self::RISK_LOW,
			self::ACTION_TAXONOMY_LIST  => self::RISK_LOW,
			self::ACTION_CPT_CREATE     => self::RISK_HIGH,
			self::ACTION_CPT_UPDATE     => self::RISK_HIGH,
			self::ACTION_CPT_DISABLE    => self::RISK_HIGH,
			self::ACTION_TAXONOMY_CREATE => self::RISK_HIGH,
			self::ACTION_TAXONOMY_UPDATE => self::RISK_HIGH,
			default                      => self::RISK_HIGH,
		};
	}

	public function requires_approval( string $action ): bool {
		return ! in_array( $action, [ self::ACTION_CPT_LIST, self::ACTION_CPT_GET, self::ACTION_TAXONOMY_LIST ], true );
	}

	public function get_post_types(): array {
		$all_types = get_post_types( [], 'objects' );
		$configs   = get_option( 'wpcc_cpt_configs', [] );
		$result    = [];

		$seen = [];

		foreach ( $all_types as $pt ) {
			if ( in_array( $pt->name, self::CORE_POST_TYPES, true ) ) {
				continue;
			}
			$seen[ $pt->name ] = true;
			$is_wpcc = isset( $configs[ $pt->name ] );
			$is_custom = ! $is_wpcc;

			$result[] = [
				'name'       => $pt->name,
				'label'      => $pt->label,
				'public'     => $pt->public,
				'supports'   => array_keys( get_all_post_type_supports( $pt->name ) ),
				'taxonomies' => get_object_taxonomies( $pt->name ),
				'has_archive' => $pt->has_archive,
				'rewrite'    => $pt->rewrite,
				'source'     => $is_wpcc ? 'wpcc' : ( $is_custom ? 'custom' : 'core' ),
				'config'     => $configs[ $pt->name ] ?? null,
				'count'      => (int) wp_count_posts( $pt->name )->publish ?? 0,
			];
		}

		// Include WPCC-managed CPTs not currently registered
		foreach ( $configs as $name => $cpt_config ) {
			if ( isset( $seen[ $name ] ) || empty( $cpt_config['active'] ) ) {
				continue;
			}
			$result[] = [
				'name'        => $name,
				'label'       => $cpt_config['label'] ?? $name,
				'public'      => $cpt_config['config']['public'] ?? false,
				'supports'    => $cpt_config['config']['supports'] ?? [],
				'taxonomies'  => [],
				'has_archive' => $cpt_config['config']['has_archive'] ?? false,
				'rewrite'     => $cpt_config['config']['rewrite'] ?? [],
				'source'      => 'wpcc',
				'config'      => $cpt_config,
				'count'       => 0,
				'active'      => $cpt_config['active'] ?? true,
			];
		}

		return $result;
	}

	public function get_post_type( string $name ): ?array {
		foreach ( $this->get_post_types() as $pt ) {
			if ( $pt['name'] === $name ) {
				return $pt;
			}
		}
		return null;
	}

	public function get_taxonomies(): array {
		$all_taxes = get_taxonomies( [], 'objects' );
		$result    = [];

		foreach ( $all_taxes as $tax ) {
			$result[] = [
				'name'       => $tax->name,
				'label'      => $tax->label,
				'public'     => $tax->public,
				'hierarchical' => $tax->hierarchical,
				'object_type'  => $tax->object_type,
				'rewrite'      => $tax->rewrite,
				'count'      => (int) wp_count_terms( [ 'taxonomy' => $tax->name, 'hide_empty' => false ] ),
			];
		}

		return $result;
	}

	public function get_summary(): array {
		return [
			'post_types'       => $this->get_post_types(),
			'taxonomies'       => $this->get_taxonomies(),
			'total_cpt'        => count( $this->get_post_types() ),
			'total_taxonomies' => count( $this->get_taxonomies() ),
		];
	}

	public function count_by_risk(): array {
		$counts = [ 'low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0 ];
		foreach ( self::ACTIONS as $action ) {
			if ( 'cpt_rollback' === $action ) {
				continue;
			}
			$risk = $this->action_risk( $action );
			if ( isset( $counts[ $risk ] ) ) {
				$counts[ $risk ]++;
			}
		}
		return $counts;
	}

	public function get_operations_definition(): array {
		return [
			self::ACTION_CPT_LIST       => [ 'name' => 'List Post Types', 'description' => 'List all registered custom post types.' ],
			self::ACTION_CPT_GET        => [ 'name' => 'Get Post Type', 'description' => 'Get details for a specific post type.' ],
			self::ACTION_CPT_CREATE     => [ 'name' => 'Create Post Type', 'description' => 'Register a new custom post type.' ],
			self::ACTION_CPT_UPDATE     => [ 'name' => 'Update Post Type', 'description' => 'Update an existing custom post type config.' ],
			self::ACTION_CPT_DISABLE    => [ 'name' => 'Disable Post Type', 'description' => 'Disable a custom post type (unregister).' ],
			self::ACTION_TAXONOMY_LIST  => [ 'name' => 'List Taxonomies', 'description' => 'List all registered taxonomies.' ],
			self::ACTION_TAXONOMY_CREATE => [ 'name' => 'Create Taxonomy', 'description' => 'Register a new taxonomy.' ],
			self::ACTION_TAXONOMY_UPDATE => [ 'name' => 'Update Taxonomy', 'description' => 'Update an existing taxonomy config.' ],
		];
	}
}
