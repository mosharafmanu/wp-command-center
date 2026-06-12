<?php
namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class MenuRegistry {

	const RISK_LOW = 'low'; const RISK_MEDIUM = 'medium'; const RISK_HIGH = 'high';

	const A_MENU_LIST        = 'menu_list';
	const A_MENU_GET         = 'menu_get';
	const A_MENU_CREATE      = 'menu_create';
	const A_MENU_UPDATE      = 'menu_update';
	const A_MENU_DELETE      = 'menu_delete';
	const A_MENU_DUPLICATE   = 'menu_duplicate';
	const A_MENU_EXPORT      = 'menu_export';
	const A_MENU_IMPORT      = 'menu_import';
	const A_MENU_ITEM_LIST   = 'menu_item_list';
	const A_MENU_ITEM_GET    = 'menu_item_get';
	const A_MENU_ITEM_ADD    = 'menu_item_add';
	const A_MENU_ITEM_UPDATE = 'menu_item_update';
	const A_MENU_ITEM_REMOVE = 'menu_item_remove';
	const A_MENU_ITEM_MOVE   = 'menu_item_move';
	const A_MENU_ITEM_REORDER = 'menu_item_reorder';
	const A_LOCATION_LIST     = 'menu_location_list';
	const A_LOCATION_ASSIGN   = 'menu_location_assign';
	const A_LOCATION_REMOVE   = 'menu_location_remove';
	const A_LOCATION_SYNC     = 'menu_location_sync';
	const A_TREE_GET          = 'menu_tree_get';
	const A_TREE_VALIDATE     = 'menu_tree_validate';
	const A_TREE_REPAIR       = 'menu_tree_repair';
	const A_MENU_ANALYZE      = 'menu_analyze';
	const A_MENU_INVENTORY    = 'menu_inventory';

	const ACTIONS = [
		self::A_MENU_LIST, self::A_MENU_GET, self::A_MENU_CREATE, self::A_MENU_UPDATE, self::A_MENU_DELETE,
		self::A_MENU_DUPLICATE, self::A_MENU_EXPORT, self::A_MENU_IMPORT,
		self::A_MENU_ITEM_LIST, self::A_MENU_ITEM_GET, self::A_MENU_ITEM_ADD, self::A_MENU_ITEM_UPDATE,
		self::A_MENU_ITEM_REMOVE, self::A_MENU_ITEM_MOVE, self::A_MENU_ITEM_REORDER,
		self::A_LOCATION_LIST, self::A_LOCATION_ASSIGN, self::A_LOCATION_REMOVE, self::A_LOCATION_SYNC,
		self::A_TREE_GET, self::A_TREE_VALIDATE, self::A_TREE_REPAIR,
		self::A_MENU_ANALYZE, self::A_MENU_INVENTORY,
	];

	private static ?array $risk = null; private static ?array $approval = null; private static ?array $rollback = null;

	private static function init(): void {
		if ( self::$risk !== null ) return;
		$L = self::RISK_LOW; $M = self::RISK_MEDIUM; $H = self::RISK_HIGH;
		self::$risk = [
			self::A_MENU_LIST => $L, self::A_MENU_GET => $L, self::A_MENU_ITEM_LIST => $L,
			self::A_MENU_ITEM_GET => $L, self::A_LOCATION_LIST => $L, self::A_TREE_GET => $L,
			self::A_MENU_ANALYZE => $L, self::A_MENU_INVENTORY => $L, self::A_MENU_EXPORT => $L,
			self::A_TREE_VALIDATE => $M, self::A_MENU_ITEM_ADD => $M, self::A_MENU_ITEM_UPDATE => $M,
			self::A_MENU_ITEM_MOVE => $M, self::A_MENU_ITEM_REORDER => $M, self::A_LOCATION_ASSIGN => $M,
			self::A_LOCATION_REMOVE => $M, self::A_LOCATION_SYNC => $M, self::A_MENU_UPDATE => $M,
			self::A_MENU_CREATE => $H, self::A_MENU_DELETE => $H, self::A_MENU_DUPLICATE => $H,
			self::A_MENU_IMPORT => $H, self::A_TREE_REPAIR => $H, self::A_MENU_ITEM_REMOVE => $M,
		];
		self::$approval = []; foreach ( self::ACTIONS as $a ) self::$approval[ $a ] = ( self::$risk[ $a ] ?? $M ) === $H;
		self::$rollback = [
			self::A_MENU_CREATE => true, self::A_MENU_UPDATE => true, self::A_MENU_DELETE => true,
			self::A_MENU_IMPORT => true, self::A_MENU_ITEM_ADD => true, self::A_MENU_ITEM_UPDATE => true,
			self::A_MENU_ITEM_REMOVE => true, self::A_LOCATION_ASSIGN => true, self::A_LOCATION_REMOVE => true,
		];
	}

	public static function get_risk( string $a ): string { self::init(); return self::$risk[ $a ] ?? self::RISK_MEDIUM; }
	public static function requires_approval( string $a ): bool { self::init(); return self::$approval[ $a ] ?? true; }
	public static function supports_rollback( string $a ): bool { self::init(); return self::$rollback[ $a ] ?? false; }
}
