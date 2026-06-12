<?php
/**
 * Builds a small decision-oriented site summary without loading the full
 * agent context, inventories, diagnostics, or recent runtime collections.
 */

namespace WPCommandCenter\AiAgent;

use WPCommandCenter\Operations\OperationRegistry;
use WPCommandCenter\Recommendations\RecommendationEngine;

defined( 'ABSPATH' ) || exit;

final class ContextSummaryBuilder {

	public function build(): array {
		$theme        = wp_get_theme();
		$post_counts  = wp_count_posts();
		$page_counts  = wp_count_posts( 'page' );
		$media_counts = wp_count_posts( 'attachment' );
		$user_counts  = count_users();
		$comments     = wp_count_comments();
		$findings     = $this->top_findings();

		return [
			'context_mode' => 'compact',
			'site'         => get_bloginfo( 'name' ),
			'wordpress'    => get_bloginfo( 'version' ),
			'php'          => PHP_VERSION,
			'theme'        => $theme->get( 'Name' ),
			'plugins'      => count( get_option( 'active_plugins', [] ) ),
			'woocommerce'  => class_exists( 'WooCommerce' ),
			'content'      => [
				'posts'    => (int) ( $post_counts->publish ?? 0 ),
				'pages'    => (int) ( $page_counts->publish ?? 0 ),
				'drafts'   => (int) ( $post_counts->draft ?? 0 ) + (int) ( $page_counts->draft ?? 0 ),
				'media'    => (int) ( $media_counts->inherit ?? 0 ),
				'comments' => (int) ( $comments->total_comments ?? 0 ),
			],
			'users'        => (int) ( $user_counts['total_users'] ?? 0 ),
			'acf_groups'   => function_exists( 'acf_get_field_groups' ) ? count( acf_get_field_groups() ) : 0,
			'forms'        => $this->form_count(),
			'operations'   => count( ( new OperationRegistry() )->get_operations() ),
			'issues'       => count( $findings ),
			'top_findings' => $findings,
		];
	}

	public function manifest_summary(): array {
		$operations = ( new OperationRegistry() )->get_operations();
		$risk_counts = [];
		$approvals   = 0;

		foreach ( $operations as $operation ) {
			$risk = (string) ( $operation['risk_level'] ?? 'unknown' );
			$risk_counts[ $risk ] = ( $risk_counts[ $risk ] ?? 0 ) + 1;
			$approvals += empty( $operation['requires_approval'] ) ? 0 : 1;
		}

		return [
			'context_mode' => 'compact',
			'plugin'       => [
				'name'    => 'WP Command Center',
				'version' => WPCC_VERSION,
			],
			'security'     => [
				'capability_enforcement' => (bool) get_option( 'wpcc_enforce_capabilities', true ),
				'approval_enforcement'   => (bool) get_option( 'wpcc_enforce_approval', false ),
				'rollback_supported'      => true,
			],
			'operations'   => [
				'total'             => count( $operations ),
				'requires_approval' => $approvals,
				'by_risk'           => $risk_counts,
			],
			'mcp'          => [
				'protocol'       => 'JSON-RPC 2.0',
				'default_mode'   => 'compact',
				'context_modes'  => [ 'compact', 'standard', 'verbose' ],
				'resource_count' => 7,
			],
		];
	}

	private function form_count(): int {
		if ( ! defined( 'WPCF7_VERSION' ) ) {
			return 0;
		}

		$counts = wp_count_posts( 'wpcf7_contact_form' );
		return (int) ( $counts->publish ?? 0 );
	}

	private function top_findings(): array {
		$recommendations = ( new RecommendationEngine() )->list( [
			'status' => 'open',
			'limit'  => 5,
		] );

		return array_values( array_filter( array_map(
			static fn ( array $item ): string => (string) ( $item['title'] ?? $item['summary'] ?? '' ),
			$recommendations
		) ) );
	}
}
