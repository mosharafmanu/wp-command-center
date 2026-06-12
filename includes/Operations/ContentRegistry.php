<?php
/**
 * Step 42 — Content Registry.
 * Defines supported content types and operations.
 */

namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class ContentRegistry {

	const RISK_LOW    = 'low';
	const RISK_MEDIUM = 'medium';
	const RISK_HIGH   = 'high';

	const TYPE_POST = 'post';
	const TYPE_PAGE = 'page';
	const TYPES     = [ 'post', 'page' ];

	const ACTION_LIST              = 'content_list';
	const ACTION_GET               = 'content_get';
	const ACTION_CREATE            = 'content_create';
	const ACTION_UPDATE            = 'content_update';
	const ACTION_DELETE            = 'content_delete';
	const ACTION_PUBLISH           = 'content_publish';
	const ACTION_UNPUBLISH         = 'content_unpublish';
	const ACTION_SCHEDULE          = 'content_schedule';
	const ACTION_TAXONOMY_ASSIGN   = 'taxonomy_assign';
	const ACTION_FEATURED_IMAGE    = 'featured_image_assign';

	const ACTIONS = [
		'content_list', 'content_get', 'content_create', 'content_update',
		'content_delete', 'content_publish', 'content_unpublish',
		'content_schedule', 'taxonomy_assign', 'featured_image_assign',
	];

	const STATUS_DRAFT   = 'draft';
	const STATUS_PUBLISH = 'publish';
	const STATUS_PRIVATE = 'private';
	const STATUS_PENDING = 'pending';
	const STATUS_TRASH   = 'trash';
	const STATUS_FUTURE  = 'future';

	public function action_risk( string $action ): string {
		return match ( $action ) {
			self::ACTION_LIST, self::ACTION_GET => self::RISK_LOW,
			self::ACTION_CREATE, self::ACTION_UPDATE,
			self::ACTION_TAXONOMY_ASSIGN, self::ACTION_FEATURED_IMAGE => self::RISK_MEDIUM,
			default => self::RISK_HIGH,
		};
	}

	public function requires_approval( string $action ): bool {
		return ! in_array( $action, [ self::ACTION_LIST, self::ACTION_GET ], true );
	}

	public function get_summary(): array {
		$counts = wp_count_posts();
		$pages  = wp_count_posts( 'page' );

		return [
			'post_count'   => (int) ( $counts->publish ?? 0 ),
			'post_draft'   => (int) ( $counts->draft ?? 0 ),
			'post_trash'   => (int) ( $counts->trash ?? 0 ),
			'page_count'   => (int) ( $pages->publish ?? 0 ),
			'page_draft'   => (int) ( $pages->draft ?? 0 ),
			'supported_types' => self::TYPES,
		];
	}
}
