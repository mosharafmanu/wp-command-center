<?php
namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class ACFRegistry {

	const RISK_LOW = 'low'; const RISK_MEDIUM = 'medium'; const RISK_HIGH = 'high';

	const ACTION_GROUP_LIST       = 'acf_group_list';
	const ACTION_GROUP_GET        = 'acf_group_get';
	const ACTION_GROUP_CREATE     = 'acf_group_create';
	const ACTION_GROUP_UPDATE     = 'acf_group_update';
	const ACTION_GROUP_DELETE     = 'acf_group_delete';
	const ACTION_GROUP_DUPLICATE  = 'acf_group_duplicate';
	const ACTION_GROUP_ACTIVATE   = 'acf_group_activate';
	const ACTION_GROUP_DEACTIVATE = 'acf_group_deactivate';
	const ACTION_FIELD_LIST       = 'acf_field_list';
	const ACTION_FIELD_GET        = 'acf_field_get';
	const ACTION_FIELD_CREATE     = 'acf_field_create';
	const ACTION_FIELD_UPDATE     = 'acf_field_update';
	const ACTION_FIELD_DELETE     = 'acf_field_delete';
	const ACTION_FIELD_DUPLICATE  = 'acf_field_duplicate';
	const ACTION_LOCATION_LIST    = 'acf_location_list';
	const ACTION_LOCATION_ASSIGN  = 'acf_location_assign';
	const ACTION_LOCATION_REMOVE  = 'acf_location_remove';
	const ACTION_JSON_STATUS      = 'acf_json_status';
	const ACTION_JSON_EXPORT      = 'acf_json_export';
	const ACTION_JSON_IMPORT      = 'acf_json_import';
	const ACTION_JSON_SYNC        = 'acf_json_sync';
	const ACTION_JSON_DIFF        = 'acf_json_diff';
	const ACTION_VALUE_GET        = 'acf_value_get';
	const ACTION_VALUE_UPDATE     = 'acf_value_update';
	const ACTION_BULK_VALUE_UPDATE = 'acf_bulk_value_update';
	const ACTION_INVENTORY         = 'acf_inventory';

	const ACTIONS = [
		self::ACTION_GROUP_LIST, self::ACTION_GROUP_GET, self::ACTION_GROUP_CREATE, self::ACTION_GROUP_UPDATE,
		self::ACTION_GROUP_DELETE, self::ACTION_GROUP_DUPLICATE, self::ACTION_GROUP_ACTIVATE, self::ACTION_GROUP_DEACTIVATE,
		self::ACTION_FIELD_LIST, self::ACTION_FIELD_GET, self::ACTION_FIELD_CREATE, self::ACTION_FIELD_UPDATE,
		self::ACTION_FIELD_DELETE, self::ACTION_FIELD_DUPLICATE,
		self::ACTION_LOCATION_LIST, self::ACTION_LOCATION_ASSIGN, self::ACTION_LOCATION_REMOVE,
		self::ACTION_JSON_STATUS, self::ACTION_JSON_EXPORT, self::ACTION_JSON_IMPORT, self::ACTION_JSON_SYNC, self::ACTION_JSON_DIFF,
		self::ACTION_VALUE_GET, self::ACTION_VALUE_UPDATE, self::ACTION_BULK_VALUE_UPDATE,
		self::ACTION_INVENTORY,
	];

	private static ?array $risk = null;
	private static ?array $approval = null;
	private static ?array $rollback = null;

	private static function init(): void {
		if ( self::$risk !== null ) return;
		$L = self::RISK_LOW; $M = self::RISK_MEDIUM; $H = self::RISK_HIGH;
		self::$risk = [
			self::ACTION_GROUP_LIST => $L, self::ACTION_GROUP_GET => $L, self::ACTION_FIELD_LIST => $L,
			self::ACTION_FIELD_GET => $L, self::ACTION_INVENTORY => $L, self::ACTION_JSON_STATUS => $L,
			self::ACTION_VALUE_GET => $L, self::ACTION_LOCATION_LIST => $L,
			self::ACTION_VALUE_UPDATE => $M, self::ACTION_BULK_VALUE_UPDATE => $M,
			self::ACTION_LOCATION_ASSIGN => $M, self::ACTION_LOCATION_REMOVE => $M,
			self::ACTION_JSON_SYNC => $M, self::ACTION_JSON_DIFF => $M, self::ACTION_JSON_EXPORT => $M,
			self::ACTION_GROUP_CREATE => $H, self::ACTION_GROUP_UPDATE => $H, self::ACTION_GROUP_DELETE => $H,
			self::ACTION_FIELD_CREATE => $H, self::ACTION_FIELD_UPDATE => $H, self::ACTION_FIELD_DELETE => $H,
			self::ACTION_JSON_IMPORT => $H, self::ACTION_GROUP_DUPLICATE => $H,
			self::ACTION_GROUP_ACTIVATE => $M, self::ACTION_GROUP_DEACTIVATE => $M,
			self::ACTION_FIELD_DUPLICATE => $H,
		];
		self::$approval = [];
		foreach ( self::ACTIONS as $a ) self::$approval[ $a ] = ( self::$risk[ $a ] ?? $M ) === $H;
		self::$rollback = [
			self::ACTION_GROUP_CREATE => true, self::ACTION_GROUP_UPDATE => true, self::ACTION_GROUP_DELETE => true,
			self::ACTION_FIELD_CREATE => true, self::ACTION_FIELD_UPDATE => true, self::ACTION_FIELD_DELETE => true,
			self::ACTION_LOCATION_ASSIGN => true, self::ACTION_LOCATION_REMOVE => true,
			self::ACTION_VALUE_UPDATE => true, self::ACTION_JSON_IMPORT => true,
		];
	}

	public static function get_risk( string $a ): string { self::init(); return self::$risk[ $a ] ?? self::RISK_MEDIUM; }
	public static function requires_approval( string $a ): bool { self::init(); return self::$approval[ $a ] ?? true; }
	public static function supports_rollback( string $a ): bool { self::init(); return self::$rollback[ $a ] ?? false; }
}
