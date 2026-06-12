<?php
/**
 * Step 74 — CPT Runtime Manager.
 *
 * Safely manages WordPress custom post types and taxonomies.
 * Operations: cpt_list, cpt_get, cpt_create, cpt_update, cpt_disable,
 *             taxonomy_list, taxonomy_create, taxonomy_update.
 * Registry-driven, rollback-capable. Configs stored in wpcc_cpt_configs.
 */

namespace WPCommandCenter\Operations;

use WPCommandCenter\Security\AuditLog;

defined( 'ABSPATH' ) || exit;

final class CPTRuntimeManager {

	private CPTRegistry $registry;

	public function __construct() {
		$this->registry = new CPTRegistry();
	}

	private function audit( string $event, array $data, array $context = [] ): void {
		$audit = new AuditLog();
		$actor = isset( $context['actor'] ) ? AuditLog::resolve_actor( $context['actor'] ) : null;
		$audit->record( $event, array_merge( [ 'actor' => $actor ], $data ) );
	}

	/**
	 * @param array{action: string, name?: string, label?: string, config?: array, taxonomy?: string} $params
	 * @return array|\WP_Error
	 */
	public function run( array $params, array $context = [] ): array|\WP_Error {
		$action = sanitize_key( $params['action'] ?? '' );

		if ( ! in_array( $action, CPTRegistry::ACTIONS, true ) ) {
			return new \WP_Error( 'wpcc_invalid_cpt_action', __( 'Invalid CPT action.', 'wp-command-center' ) );
		}

		return match ( $action ) {
			CPTRegistry::ACTION_CPT_LIST       => $this->cpt_list(),
			CPTRegistry::ACTION_CPT_GET        => $this->cpt_get( $params ),
			CPTRegistry::ACTION_CPT_CREATE     => $this->cpt_create( $params, $context ),
			CPTRegistry::ACTION_CPT_UPDATE     => $this->cpt_update( $params, $context ),
			CPTRegistry::ACTION_CPT_DISABLE    => $this->cpt_disable( $params, $context ),
			CPTRegistry::ACTION_TAXONOMY_LIST  => $this->taxonomy_list(),
			CPTRegistry::ACTION_TAXONOMY_CREATE => $this->taxonomy_create( $params, $context ),
			CPTRegistry::ACTION_TAXONOMY_UPDATE => $this->taxonomy_update( $params, $context ),
			'cpt_rollback'                      => $this->cpt_rollback( $params, $context ),
			default                             => new \WP_Error( 'wpcc_invalid_cpt_action', __( 'Unknown CPT action.', 'wp-command-center' ) ),
		};
	}

	private function cpt_list(): array {
		$this->audit( 'cpt.list', [] );
		return [
			'action'  => 'cpt_list',
			'summary' => $this->registry->get_summary(),
		];
	}

	private function cpt_get( array $params ): array|\WP_Error {
		$name = sanitize_text_field( $params['name'] ?? '' );
		if ( '' === $name ) {
			return new \WP_Error( 'wpcc_missing_cpt_name', __( 'CPT name is required.', 'wp-command-center' ) );
		}

		$pt = $this->registry->get_post_type( $name );
		if ( null === $pt ) {
			return new \WP_Error( 'wpcc_cpt_not_found', __( 'Post type not found.', 'wp-command-center' ) );
		}

		$this->audit( 'cpt.get', [ 'name' => $name ] );
		return [
			'action'    => 'cpt_get',
			'post_type' => $pt,
		];
	}

	private function cpt_create( array $params, array $context ): array|\WP_Error {
		$name  = sanitize_text_field( $params['name'] ?? '' );
		$label = sanitize_text_field( $params['label'] ?? '' );
		$config = $params['config'] ?? [];

		if ( '' === $name ) {
			return new \WP_Error( 'wpcc_missing_cpt_name', __( 'CPT name is required.', 'wp-command-center' ) );
		}
		if ( '' === $label ) {
			return new \WP_Error( 'wpcc_missing_cpt_label', __( 'CPT label is required.', 'wp-command-center' ) );
		}

		if ( ! preg_match( '/^[a-z][a-z0-9_]*$/', $name ) ) {
			return new \WP_Error( 'wpcc_invalid_cpt_name', __( 'CPT name must be lowercase alphanumeric with underscores only.', 'wp-command-center' ) );
		}

		if ( post_type_exists( $name ) ) {
			return new \WP_Error( 'wpcc_cpt_exists', __( 'Post type already exists.', 'wp-command-center' ) );
		}

		$default_config = [
			'public'       => true,
			'has_archive'  => true,
			'supports'     => [ 'title', 'editor', 'thumbnail', 'excerpt' ],
			'show_in_rest' => true,
			'menu_icon'    => 'dashicons-admin-post',
			'labels'       => [
				'name'          => $label,
				'singular_name' => $label,
			],
		];

		$final_config = array_merge( $default_config, $config );
		$final_config['labels'] = array_merge( $default_config['labels'], $config['labels'] ?? [] );

		// Store config
		$configs = get_option( 'wpcc_cpt_configs', [] );
		$configs[ $name ] = [
			'name'   => $name,
			'label'  => $label,
			'config' => $final_config,
			'active' => true,
		];
		update_option( 'wpcc_cpt_configs', $configs );

		// Register
		register_post_type( $name, $final_config );

		$rollback_id = $this->store_rollback( 'cpt_create', [ 'name' => $name, 'config' => $final_config ], $context );

		flush_rewrite_rules();

		$this->audit( 'cpt.created', [ 'name' => $name, 'rollback_id' => $rollback_id ], $context );

		return [
			'action'      => 'cpt_create',
			'name'        => $name,
			'rollback_id' => $rollback_id,
		];
	}

	private function cpt_update( array $params, array $context ): array|\WP_Error {
		$name   = sanitize_text_field( $params['name'] ?? '' );
		$config = $params['config'] ?? [];

		if ( '' === $name ) {
			return new \WP_Error( 'wpcc_missing_cpt_name', __( 'CPT name is required.', 'wp-command-center' ) );
		}

		$configs = get_option( 'wpcc_cpt_configs', [] );
		if ( ! isset( $configs[ $name ] ) ) {
			return new \WP_Error( 'wpcc_cpt_not_found', __( 'Post type not found in WPCC configs.', 'wp-command-center' ) );
		}

		$old_config = $configs[ $name ]['config'];
		$rollback_id = $this->store_rollback( 'cpt_update', [ 'name' => $name, 'old_config' => $old_config ], $context );

		$configs[ $name ]['config'] = array_merge( $old_config, $config );
		update_option( 'wpcc_cpt_configs', $configs );

		// Re-register
		register_post_type( $name, $configs[ $name ]['config'] );
		flush_rewrite_rules();

		$this->audit( 'cpt.updated', [ 'name' => $name, 'rollback_id' => $rollback_id ], $context );

		return [
			'action'      => 'cpt_update',
			'name'        => $name,
			'rollback_id' => $rollback_id,
		];
	}

	private function cpt_disable( array $params, array $context ): array|\WP_Error {
		$name = sanitize_text_field( $params['name'] ?? '' );

		if ( '' === $name ) {
			return new \WP_Error( 'wpcc_missing_cpt_name', __( 'CPT name is required.', 'wp-command-center' ) );
		}

		$configs = get_option( 'wpcc_cpt_configs', [] );
		if ( ! isset( $configs[ $name ] ) ) {
			return new \WP_Error( 'wpcc_cpt_not_found', __( 'Post type not found in WPCC configs.', 'wp-command-center' ) );
		}

		$old_config = $configs[ $name ];
		$rollback_id = $this->store_rollback( 'cpt_disable', [ 'name' => $name, 'old_config' => $old_config ], $context );

		$configs[ $name ]['active'] = false;
		update_option( 'wpcc_cpt_configs', $configs );

		if ( post_type_exists( $name ) ) {
			unregister_post_type( $name );
		}
		flush_rewrite_rules();

		$this->audit( 'cpt.disabled', [ 'name' => $name, 'rollback_id' => $rollback_id ], $context );

		return [
			'action'      => 'cpt_disable',
			'name'        => $name,
			'rollback_id' => $rollback_id,
		];
	}

	private function taxonomy_list(): array {
		$this->audit( 'cpt.taxonomy_list', [] );
		return [
			'action'     => 'taxonomy_list',
			'taxonomies' => $this->registry->get_taxonomies(),
		];
	}

	private function taxonomy_create( array $params, array $context ): array|\WP_Error {
		$name       = sanitize_text_field( $params['name'] ?? '' );
		$label      = sanitize_text_field( $params['label'] ?? '' );
		$object_type = $params['object_type'] ?? 'post';
		$config     = $params['config'] ?? [];

		if ( '' === $name ) {
			return new \WP_Error( 'wpcc_missing_taxonomy_name', __( 'Taxonomy name is required.', 'wp-command-center' ) );
		}
		if ( '' === $label ) {
			return new \WP_Error( 'wpcc_missing_taxonomy_label', __( 'Taxonomy label is required.', 'wp-command-center' ) );
		}

		if ( ! preg_match( '/^[a-z][a-z0-9_]*$/', $name ) ) {
			return new \WP_Error( 'wpcc_invalid_taxonomy_name', __( 'Taxonomy name must be lowercase alphanumeric with underscores only.', 'wp-command-center' ) );
		}

		if ( taxonomy_exists( $name ) ) {
			return new \WP_Error( 'wpcc_taxonomy_exists', __( 'Taxonomy already exists.', 'wp-command-center' ) );
		}

		$default_config = [
			'public'       => true,
			'hierarchical' => false,
			'show_in_rest' => true,
			'labels'       => [
				'name'          => $label,
				'singular_name' => $label,
			],
		];

		$final_config = array_merge( $default_config, $config );
		$final_config['labels'] = array_merge( $default_config['labels'], $config['labels'] ?? [] );

		register_taxonomy( $name, $object_type, $final_config );

		$rollback_id = $this->store_rollback( 'taxonomy_create', [ 'name' => $name, 'config' => $final_config, 'object_type' => $object_type ], $context );

		flush_rewrite_rules();

		$this->audit( 'cpt.taxonomy_created', [ 'name' => $name, 'rollback_id' => $rollback_id ], $context );

		return [
			'action'      => 'taxonomy_create',
			'name'        => $name,
			'rollback_id' => $rollback_id,
		];
	}

	private function taxonomy_update( array $params, array $context ): array|\WP_Error {
		$name   = sanitize_text_field( $params['name'] ?? '' );
		$config = $params['config'] ?? [];

		if ( '' === $name ) {
			return new \WP_Error( 'wpcc_missing_taxonomy_name', __( 'Taxonomy name is required.', 'wp-command-center' ) );
		}

		$tax = taxonomy_exists( $name ) ? get_taxonomy( $name ) : null;
		$object_type = $tax ? $tax->object_type : [ 'post' ];
		$old_config = $tax ? [
			'label'        => $tax->label,
			'public'       => $tax->public,
			'hierarchical' => $tax->hierarchical,
		] : [];

		$rollback_id = $this->store_rollback( 'taxonomy_update', [ 'name' => $name, 'old_config' => $old_config ], $context );

		$defaults = $tax ? [
			'label'        => $tax->label,
			'public'       => $tax->public,
			'hierarchical' => $tax->hierarchical,
			'show_in_rest' => $tax->show_in_rest,
		] : [ 'label' => $name, 'public' => true, 'hierarchical' => false, 'show_in_rest' => true ];

		register_taxonomy( $name, $object_type, array_merge( $defaults, $config ) );

		flush_rewrite_rules();

		$this->audit( 'cpt.taxonomy_updated', [ 'name' => $name, 'rollback_id' => $rollback_id ], $context );

		return [
			'action'      => 'taxonomy_update',
			'name'        => $name,
			'rollback_id' => $rollback_id,
		];
	}

	private function cpt_rollback( array $params, array $context ): array|\WP_Error {
		$rollback_id = sanitize_text_field( $params['rollback_id'] ?? '' );
		if ( '' === $rollback_id ) {
			return new \WP_Error( 'wpcc_missing_rollback_id', __( 'rollback_id is required.', 'wp-command-center' ) );
		}

		$records = get_option( 'wpcc_cpt_rollbacks', [] );
		if ( ! isset( $records[ $rollback_id ] ) ) {
			return new \WP_Error( 'wpcc_rollback_not_found', __( 'Rollback record not found.', 'wp-command-center' ) );
		}

		$record = $records[ $rollback_id ];
		if ( ! empty( $record['rollback_applied'] ) ) {
			return new \WP_Error( 'wpcc_rollback_already_applied', __( 'Rollback already applied.', 'wp-command-center' ) );
		}

		$action = $record['action'];

		switch ( $action ) {
			case 'cpt_create':
				$configs = get_option( 'wpcc_cpt_configs', [] );
				unset( $configs[ $record['name'] ] );
				update_option( 'wpcc_cpt_configs', $configs );
				if ( post_type_exists( $record['name'] ) ) {
					unregister_post_type( $record['name'] );
				}
				flush_rewrite_rules();
				break;

			case 'cpt_update':
				$configs = get_option( 'wpcc_cpt_configs', [] );
				if ( isset( $configs[ $record['name'] ] ) ) {
					$configs[ $record['name'] ]['config'] = $record['old_config'];
					update_option( 'wpcc_cpt_configs', $configs );
					register_post_type( $record['name'], $record['old_config'] );
					flush_rewrite_rules();
				}
				break;

			case 'cpt_disable':
				$configs = get_option( 'wpcc_cpt_configs', [] );
				if ( isset( $configs[ $record['name'] ] ) ) {
					$configs[ $record['name'] ] = $record['old_config'];
					update_option( 'wpcc_cpt_configs', $configs );
					register_post_type( $record['name'], $record['old_config']['config'] );
					flush_rewrite_rules();
				}
				break;

			case 'taxonomy_create':
				if ( taxonomy_exists( $record['name'] ) ) {
					unregister_taxonomy( $record['name'] );
					flush_rewrite_rules();
				}
				break;

			case 'taxonomy_update':
				if ( taxonomy_exists( $record['name'] ) ) {
					$tax = get_taxonomy( $record['name'] );
					register_taxonomy( $record['name'], $tax->object_type, $record['old_config'] );
					flush_rewrite_rules();
				}
				break;

			default:
				return new \WP_Error( 'wpcc_rollback_unsupported', __( 'Rollback not supported for this action.', 'wp-command-center' ) );
		}

		$records[ $rollback_id ]['rollback_applied'] = true;
		update_option( 'wpcc_cpt_rollbacks', $records );

		$this->audit( 'cpt.rollback.applied', [
			'rollback_id' => $rollback_id,
			'action'      => $action,
		], $context );

		return [
			'action'      => 'cpt_rollback',
			'rollback_id' => $rollback_id,
			'restored'    => true,
		];
	}

	private function store_rollback( string $action, array $data, array $context ): string {
		$rid = wp_generate_uuid4();
		$records = get_option( 'wpcc_cpt_rollbacks', [] );
		$records[ $rid ] = array_merge( $data, [
			'id'               => $rid,
			'action'           => $action,
			'rollback_applied' => false,
			'created_at'       => time(),
			'session_id'       => $context['session_id'] ?? '',
			'task_id'          => $context['task_id'] ?? '',
		] );
		update_option( 'wpcc_cpt_rollbacks', $records );
		return $rid;
	}

	public function get_registry(): CPTRegistry {
		return $this->registry;
	}
}
