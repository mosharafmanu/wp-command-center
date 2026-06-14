<?php
namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class MediaRegistry {

	const RISK_LOW      = 'low';
	const RISK_MEDIUM   = 'medium';
	const RISK_HIGH     = 'high';

	const ACTION_LIST                = 'media_list';
	const ACTION_GET                 = 'media_get';
	const ACTION_SEARCH              = 'media_search';
	const ACTION_UPLOAD              = 'media_upload';
	const ACTION_UPDATE              = 'media_update';
	const ACTION_REPLACE             = 'media_replace';
	const ACTION_REPLACE_VERIFY      = 'media_replace_verify';
	const ACTION_DELETE              = 'media_delete';
	const ACTION_RESTORE             = 'media_restore';
	const ACTION_FEATURED_ASSIGN     = 'featured_image_assign';
	const ACTION_FEATURED_REMOVE     = 'featured_image_remove';
	// STEP 90 — spec-named aliases of featured_image_assign/remove.
	const ACTION_SET_FEATURED        = 'media_set_featured';
	const ACTION_REMOVE_FEATURED     = 'media_remove_featured';
	const ACTION_REGENERATE_METADATA = 'media_regenerate_metadata';
	// STEP 100.1 — file-level snapshot primitives.
	const ACTION_SNAPSHOT_CREATE     = 'media_snapshot_create';
	const ACTION_SNAPSHOT_RESTORE    = 'media_snapshot_restore';
	const ACTION_SNAPSHOT_VERIFY     = 'media_snapshot_verify';
	const ACTION_SNAPSHOT_LIST       = 'media_snapshot_list';

	const ACTIONS = [
		'media_list', 'media_get', 'media_search', 'media_upload', 'media_update',
		'media_replace', 'media_replace_verify', 'media_delete', 'media_restore',
		'featured_image_assign', 'featured_image_remove',
		'media_set_featured', 'media_remove_featured', 'media_regenerate_metadata',
		'media_snapshot_create', 'media_snapshot_restore', 'media_snapshot_verify', 'media_snapshot_list',
	];

	const ACTION_RISK = [
		self::ACTION_LIST                => self::RISK_LOW,
		self::ACTION_GET                 => self::RISK_LOW,
		self::ACTION_SEARCH              => self::RISK_LOW,
		self::ACTION_REPLACE_VERIFY      => self::RISK_LOW,
		self::ACTION_UPDATE              => self::RISK_MEDIUM,
		self::ACTION_FEATURED_ASSIGN     => self::RISK_MEDIUM,
		self::ACTION_FEATURED_REMOVE     => self::RISK_MEDIUM,
		self::ACTION_SET_FEATURED        => self::RISK_MEDIUM,
		self::ACTION_REMOVE_FEATURED     => self::RISK_MEDIUM,
		self::ACTION_REGENERATE_METADATA => self::RISK_MEDIUM,
		self::ACTION_UPLOAD              => self::RISK_HIGH,
		self::ACTION_REPLACE             => self::RISK_HIGH,
		self::ACTION_DELETE              => self::RISK_HIGH,
		self::ACTION_RESTORE             => self::RISK_HIGH,
		self::ACTION_SNAPSHOT_CREATE     => self::RISK_LOW,
		self::ACTION_SNAPSHOT_VERIFY     => self::RISK_LOW,
		self::ACTION_SNAPSHOT_LIST       => self::RISK_LOW,
		self::ACTION_SNAPSHOT_RESTORE    => self::RISK_MEDIUM,
	];

	const ACTION_APPROVAL = [
		self::ACTION_LIST                => false,
		self::ACTION_GET                 => false,
		self::ACTION_SEARCH              => false,
		self::ACTION_REPLACE_VERIFY      => false,
		self::ACTION_UPDATE              => false,
		self::ACTION_FEATURED_ASSIGN     => false,
		self::ACTION_FEATURED_REMOVE     => false,
		self::ACTION_SET_FEATURED        => false,
		self::ACTION_REMOVE_FEATURED     => false,
		self::ACTION_REGENERATE_METADATA => false,
		self::ACTION_UPLOAD              => true,
		self::ACTION_REPLACE             => true,
		self::ACTION_DELETE              => true,
		self::ACTION_RESTORE             => true,
		self::ACTION_SNAPSHOT_CREATE     => false,
		self::ACTION_SNAPSHOT_RESTORE    => false,
		self::ACTION_SNAPSHOT_VERIFY     => false,
		self::ACTION_SNAPSHOT_LIST       => false,
	];

	const ACTION_ROLLBACK = [
		self::ACTION_UPLOAD          => true,
		self::ACTION_UPDATE          => true,
		self::ACTION_REPLACE         => true,
		self::ACTION_DELETE          => true,
		self::ACTION_FEATURED_ASSIGN => true,
		self::ACTION_FEATURED_REMOVE => true,
		self::ACTION_SET_FEATURED    => true,
		self::ACTION_REMOVE_FEATURED => true,
	];

	public static function get_risk( string $action ): string {
		return self::ACTION_RISK[ $action ] ?? self::RISK_MEDIUM;
	}

	public static function requires_approval( string $action ): bool {
		return self::ACTION_APPROVAL[ $action ] ?? true;
	}

	public static function supports_rollback( string $action ): bool {
		return self::ACTION_ROLLBACK[ $action ] ?? false;
	}
}
