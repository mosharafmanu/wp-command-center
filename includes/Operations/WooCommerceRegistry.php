<?php
namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class WooCommerceRegistry {

	const RISK_LOW    = 'low';
	const RISK_MEDIUM = 'medium';
	const RISK_HIGH   = 'high';

	// Product
	const ACTION_PRODUCT_LIST      = 'product_list';
	const ACTION_PRODUCT_GET       = 'product_get';
	const ACTION_PRODUCT_SEARCH    = 'product_search';
	const ACTION_PRODUCT_CREATE    = 'product_create';
	const ACTION_PRODUCT_UPDATE    = 'product_update';
	const ACTION_PRODUCT_DELETE    = 'product_delete';
	const ACTION_PRODUCT_PUBLISH   = 'product_publish';
	const ACTION_PRODUCT_UNPUBLISH = 'product_unpublish';
	const ACTION_PRODUCT_DUPLICATE = 'product_duplicate';
	// Inventory
	const ACTION_STOCK_GET         = 'stock_get';
	const ACTION_STOCK_UPDATE      = 'stock_update';
	const ACTION_STOCK_BULK_UPDATE = 'stock_bulk_update';
	// Pricing
	const ACTION_PRICE_GET         = 'price_get';
	const ACTION_PRICE_UPDATE      = 'price_update';
	const ACTION_SALE_PRICE_UPDATE = 'sale_price_update';
	// Categories
	const ACTION_CATEGORY_ASSIGN   = 'product_category_assign';
	const ACTION_CATEGORY_REMOVE   = 'product_category_remove';
	const ACTION_CATEGORY_LIST     = 'product_category_list';
	// Attributes
	const ACTION_ATTRIBUTE_ASSIGN  = 'product_attribute_assign';
	const ACTION_ATTRIBUTE_REMOVE  = 'product_attribute_remove';
	const ACTION_ATTRIBUTE_LIST    = 'product_attribute_list';
	// Variations
	const ACTION_VARIATION_LIST    = 'variation_list';
	const ACTION_VARIATION_GET     = 'variation_get';
	const ACTION_VARIATION_CREATE  = 'variation_create';
	const ACTION_VARIATION_UPDATE  = 'variation_update';
	const ACTION_VARIATION_DELETE  = 'variation_delete';
	// Orders
	const ACTION_ORDER_LIST          = 'order_list';
	const ACTION_ORDER_GET           = 'order_get';
	const ACTION_ORDER_SEARCH        = 'order_search';
	// STEP 94 — order + customer management
	const ACTION_ORDER_UPDATE        = 'order_update';
	const ACTION_ORDER_NOTE_ADD      = 'order_note_add';
	const ACTION_ORDER_STATUS_CHANGE = 'order_status_change';
	const ACTION_REFUND_CREATE       = 'refund_create';
	const ACTION_CUSTOMER_GET        = 'customer_get';
	const ACTION_CUSTOMER_SEARCH     = 'customer_search';
	// Coupons
	const ACTION_COUPON_LIST       = 'coupon_list';
	const ACTION_COUPON_GET        = 'coupon_get';
	const ACTION_COUPON_CREATE     = 'coupon_create';
	const ACTION_COUPON_UPDATE     = 'coupon_update';
	const ACTION_COUPON_DELETE     = 'coupon_delete';

	const ACTIONS = [
		self::ACTION_PRODUCT_LIST, self::ACTION_PRODUCT_GET, self::ACTION_PRODUCT_SEARCH,
		self::ACTION_PRODUCT_CREATE, self::ACTION_PRODUCT_UPDATE, self::ACTION_PRODUCT_DELETE,
		self::ACTION_PRODUCT_PUBLISH, self::ACTION_PRODUCT_UNPUBLISH, self::ACTION_PRODUCT_DUPLICATE,
		self::ACTION_STOCK_GET, self::ACTION_STOCK_UPDATE, self::ACTION_STOCK_BULK_UPDATE,
		self::ACTION_PRICE_GET, self::ACTION_PRICE_UPDATE, self::ACTION_SALE_PRICE_UPDATE,
		self::ACTION_CATEGORY_ASSIGN, self::ACTION_CATEGORY_REMOVE, self::ACTION_CATEGORY_LIST,
		self::ACTION_ATTRIBUTE_ASSIGN, self::ACTION_ATTRIBUTE_REMOVE, self::ACTION_ATTRIBUTE_LIST,
		self::ACTION_VARIATION_LIST, self::ACTION_VARIATION_GET, self::ACTION_VARIATION_CREATE,
		self::ACTION_VARIATION_UPDATE, self::ACTION_VARIATION_DELETE,
		self::ACTION_ORDER_LIST, self::ACTION_ORDER_GET, self::ACTION_ORDER_SEARCH,
		self::ACTION_ORDER_UPDATE, self::ACTION_ORDER_NOTE_ADD, self::ACTION_ORDER_STATUS_CHANGE,
		self::ACTION_REFUND_CREATE, self::ACTION_CUSTOMER_GET, self::ACTION_CUSTOMER_SEARCH,
		self::ACTION_COUPON_LIST, self::ACTION_COUPON_GET, self::ACTION_COUPON_CREATE,
		self::ACTION_COUPON_UPDATE, self::ACTION_COUPON_DELETE,
	];

	private static array $action_risk     = [];
	private static array $action_approval = [];
	private static array $action_rollback = [];
	private static bool  $initialized     = false;

	public static function init(): void {
		if ( self::$initialized ) return;
		self::$initialized = true;
		self::init_maps();
	}

	private static function init_maps(): void {
		foreach ( self::ACTIONS as $a ) {
			self::$action_risk[ $a ]     = self::RISK_LOW;
			self::$action_approval[ $a ] = false;
			self::$action_rollback[ $a ] = false;
		}
		// Low risk
		$low = [ self::ACTION_PRODUCT_LIST, self::ACTION_PRODUCT_GET, self::ACTION_PRODUCT_SEARCH,
			self::ACTION_STOCK_GET, self::ACTION_PRICE_GET,
			self::ACTION_CATEGORY_LIST, self::ACTION_ATTRIBUTE_LIST,
			self::ACTION_VARIATION_LIST, self::ACTION_VARIATION_GET,
			self::ACTION_ORDER_LIST, self::ACTION_ORDER_GET, self::ACTION_ORDER_SEARCH,
			self::ACTION_CUSTOMER_GET, self::ACTION_CUSTOMER_SEARCH,
			self::ACTION_COUPON_LIST, self::ACTION_COUPON_GET ];
		foreach ( $low as $a ) { self::$action_risk[ $a ] = self::RISK_LOW; self::$action_approval[ $a ] = false; }

		// Medium
		$med = [ self::ACTION_PRODUCT_UPDATE, self::ACTION_PRODUCT_DUPLICATE,
			self::ACTION_STOCK_UPDATE, self::ACTION_STOCK_BULK_UPDATE,
			self::ACTION_PRICE_UPDATE, self::ACTION_SALE_PRICE_UPDATE,
			self::ACTION_CATEGORY_ASSIGN, self::ACTION_CATEGORY_REMOVE,
			self::ACTION_ATTRIBUTE_ASSIGN, self::ACTION_ATTRIBUTE_REMOVE,
			self::ACTION_VARIATION_UPDATE, self::ACTION_COUPON_UPDATE,
			self::ACTION_ORDER_UPDATE, self::ACTION_ORDER_NOTE_ADD, self::ACTION_ORDER_STATUS_CHANGE ];
		foreach ( $med as $a ) { self::$action_risk[ $a ] = self::RISK_MEDIUM; self::$action_approval[ $a ] = true; }

		// High
		$high = [ self::ACTION_PRODUCT_CREATE, self::ACTION_PRODUCT_DELETE,
			self::ACTION_PRODUCT_PUBLISH, self::ACTION_PRODUCT_UNPUBLISH,
			self::ACTION_VARIATION_CREATE, self::ACTION_VARIATION_DELETE,
			self::ACTION_COUPON_CREATE, self::ACTION_COUPON_DELETE,
			self::ACTION_REFUND_CREATE ];
		foreach ( $high as $a ) { self::$action_risk[ $a ] = self::RISK_HIGH; self::$action_approval[ $a ] = true; }

		// Rollback support — all mutations
		$rb = [ self::ACTION_PRODUCT_CREATE, self::ACTION_PRODUCT_UPDATE, self::ACTION_PRODUCT_DELETE,
			self::ACTION_PRODUCT_PUBLISH, self::ACTION_PRODUCT_UNPUBLISH,
			self::ACTION_STOCK_UPDATE, self::ACTION_PRICE_UPDATE, self::ACTION_SALE_PRICE_UPDATE,
			self::ACTION_CATEGORY_ASSIGN, self::ACTION_CATEGORY_REMOVE,
			self::ACTION_ATTRIBUTE_ASSIGN, self::ACTION_ATTRIBUTE_REMOVE,
			self::ACTION_VARIATION_CREATE, self::ACTION_VARIATION_UPDATE, self::ACTION_VARIATION_DELETE,
			self::ACTION_COUPON_CREATE, self::ACTION_COUPON_UPDATE, self::ACTION_COUPON_DELETE,
			self::ACTION_ORDER_UPDATE, self::ACTION_ORDER_NOTE_ADD, self::ACTION_ORDER_STATUS_CHANGE, self::ACTION_REFUND_CREATE ];
		foreach ( $rb as $a ) { self::$action_rollback[ $a ] = true; }
	}

	public static function get_risk( string $action ): string {
		self::init();
		return self::$action_risk[ $action ] ?? self::RISK_MEDIUM;
	}

	public static function requires_approval( string $action ): bool {
		self::init();
		return self::$action_approval[ $action ] ?? true;
	}

	public static function supports_rollback( string $action ): bool {
		self::init();
		return self::$action_rollback[ $action ] ?? false;
	}
}
