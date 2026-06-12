<?php
namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class SearchRegistry {
	const RISK_LOW='low';
	const A_SEARCH_ALL='search_all';
	const A_SEARCH_CONTENT='search_content';
	const A_SEARCH_MEDIA='search_media';
	const A_SEARCH_USERS='search_users';
	const A_SEARCH_WOO='search_woocommerce';
	const A_SEARCH_FORMS='search_forms';
	const A_SEARCH_ACF='search_acf';
	const A_SEARCH_MENUS='search_menus';
	const A_REPORT_ORPHANS='report_orphans';
	const A_REPORT_UNUSED_MEDIA='report_unused_media';
	const A_REPORT_CONTENT_INVENTORY='report_content_inventory';
	const A_REPORT_WOO_INVENTORY='report_woo_inventory';
	const A_REPORT_SITE_SUMMARY='report_site_summary';

	const ACTIONS=[
		self::A_SEARCH_ALL,self::A_SEARCH_CONTENT,self::A_SEARCH_MEDIA,self::A_SEARCH_USERS,
		self::A_SEARCH_WOO,self::A_SEARCH_FORMS,self::A_SEARCH_ACF,self::A_SEARCH_MENUS,
		self::A_REPORT_ORPHANS,self::A_REPORT_UNUSED_MEDIA,self::A_REPORT_CONTENT_INVENTORY,
		self::A_REPORT_WOO_INVENTORY,self::A_REPORT_SITE_SUMMARY,
	];

	public static function requires_approval(string $a):bool{return false;}
	public static function get_risk(string $a):string{return self::RISK_LOW;}
}
