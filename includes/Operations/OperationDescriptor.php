<?php
/**
 * V1 Phase 6 — self-describing operations.
 *
 * ACF and WooCommerce had rich `*_describe` actions; the other 40 operations had a
 * title, a description and a parameter list, which is not enough to build a correct
 * first request. An assistant could not tell, without trying: whether an action
 * reads or writes, whether it will stop for approval in THIS site's protection mode,
 * which synonyms it accepts, whether the result can be undone, or whether the
 * operation works on this host at all.
 *
 * This adds none of that as a new tool or a new action — the catalogue stays at 42
 * operations and every action list is unchanged. It enriches the two discovery
 * surfaces that already exist and are already reachable:
 *
 *   - GET /operations/<id>          (REST)
 *   - resources/read wpcc://operations  (MCP — the protocol's own discovery channel)
 */

namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class OperationDescriptor {

	/**
	 * Risk tiers that never require a human decision in any mode.
	 * Mirrors SecurityModeManager's table; used only to describe, never to gate.
	 */
	private const READ_TIERS = [ SecurityModeManager::RISK_DIAGNOSTIC, SecurityModeManager::RISK_LOW ];

	/**
	 * Add the metadata an assistant needs to construct a correct first request.
	 *
	 * @param array<string,mixed> $operation A catalogue entry.
	 * @return array<string,mixed>
	 */
	public static function enrich( array $operation ): array {
		$id = (string) ( $operation['id'] ?? '' );
		if ( '' === $id ) {
			return $operation;
		}

		$operation['actions']            = self::actions( $operation );
		$operation['parameter_aliases']  = ParameterVocabulary::for_operation( $id );
		$operation['rollback']           = self::rollback_summary( $id );
		$operation['requirements']       = self::requirements( $operation );
		$operation['approval_by_mode']   = self::approval_by_mode( $operation );

		return $operation;
	}

	/**
	 * Per-action detail: risk, whether it reads or writes, and whether it will stop
	 * for approval in the mode this site is actually running.
	 *
	 * @param array<string,mixed> $operation
	 * @return array<int,array<string,mixed>>
	 */
	private static function actions( array $operation ): array {
		$declared = [];

		foreach ( (array) ( $operation['parameters'] ?? [] ) as $param ) {
			if ( 'action' === ( $param['name'] ?? '' ) && ! empty( $param['enum'] ) ) {
				$declared = (array) $param['enum'];
				break;
			}
		}
		foreach ( array_keys( (array) ( $operation['action_risks'] ?? [] ) ) as $name ) {
			if ( ! in_array( $name, $declared, true ) ) {
				$declared[] = $name;
			}
		}

		$out = [];
		foreach ( $declared as $action ) {
			$risk = SecurityModeManager::effective_risk( $operation, (string) $action );
			$out[] = [
				'action'            => $action,
				'risk'              => $risk,
				'kind'              => in_array( $risk, self::READ_TIERS, true ) ? 'read' : 'write',
				'requires_approval' => SecurityModeManager::requires_approval( $risk ),
			];
		}

		return $out;
	}

	/**
	 * What approval looks like in each mode, so a caller can explain the consequence
	 * before asking rather than discovering it from a pending_approval response.
	 *
	 * @param array<string,mixed> $operation
	 * @return array<string,string>
	 */
	private static function approval_by_mode( array $operation ): array {
		$risk = (string) ( $operation['risk_level'] ?? SecurityModeManager::RISK_HIGH );

		return [
			'current'    => SecurityModeManager::current(),
			'client'     => in_array( $risk, [ SecurityModeManager::RISK_DIAGNOSTIC, SecurityModeManager::RISK_LOW ], true ) ? 'immediate' : 'waits for approval',
			'enterprise' => SecurityModeManager::RISK_DIAGNOSTIC === $risk ? 'immediate' : 'waits for approval',
			'developer'  => 'immediate',
		];
	}

	/** How a write from this operation is undone. */
	private static function rollback_summary( string $operation_id ): array {
		$sample = RollbackContract::describe( $operation_id, 'ROLLBACK_ID' );

		return [
			'supported'         => ! empty( $sample['reversible'] ),
			'undo_with'         => $sample['undo_with'] ?? null,
			'approval_required' => $sample['approval_required'] ?? null,
			'note'              => __( 'Substitute the rollback_id the write returns. An undo is itself a change and follows the same approval rules.', 'wp-command-center' ),
		];
	}

	/**
	 * Environmental and integration prerequisites, answered for THIS site.
	 *
	 * @param array<string,mixed> $operation
	 * @return array<string,mixed>
	 */
	private static function requirements( array $operation ): array {
		$id  = (string) ( $operation['id'] ?? '' );
		$out = [ 'available' => ! empty( $operation['available'] ), 'needs' => [] ];

		if ( in_array( $id, [ 'wp_cli_bridge' ], true ) ) {
			$out['needs'][] = [
				'what'      => 'process execution (proc_open) and a WP-CLI binary',
				'satisfied' => function_exists( 'proc_open' ),
			];
		}

		if ( in_array( $id, [ 'patch_manage' ], true ) ) {
			$out['needs'][] = [
				'what'      => 'a PHP binary for php -l verification (falls back to a tokenizer check)',
				'satisfied' => true,
			];
		}

		$integrations = [
			'woocommerce_manage' => [ 'WooCommerce', 'WooCommerce' ],
			'acf_manage'         => [ 'Advanced Custom Fields', 'acf_get_field_groups' ],
			'elementor_manage'   => [ 'Elementor', 'Elementor\\Plugin' ],
			'forms_manage'       => [ 'a supported forms plugin', 'WPCF7' ],
			'seo_manage'         => [ 'Rank Math or Yoast SEO', '' ],
		];
		if ( isset( $integrations[ $id ] ) ) {
			[ $label, $probe ] = $integrations[ $id ];
			$satisfied = '' === $probe
				? ( class_exists( 'RankMath' ) || defined( 'WPSEO_VERSION' ) )
				: ( class_exists( $probe ) || function_exists( $probe ) );
			$out['needs'][] = [ 'what' => $label, 'satisfied' => $satisfied ];
		}

		return $out;
	}
}
