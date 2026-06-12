<?php
namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class FormsRegistry {

	const RISK_LOW = 'low'; const RISK_MEDIUM = 'medium'; const RISK_HIGH = 'high';

	const A_FORM_LIST       = 'form_list';
	const A_FORM_GET        = 'form_get';
	const A_FORM_SEARCH     = 'form_search';
	const A_FORM_CREATE     = 'form_create';
	const A_FORM_UPDATE     = 'form_update';
	const A_FORM_DUPLICATE  = 'form_duplicate';
	const A_FORM_DELETE     = 'form_delete';
	const A_FORM_ACTIVATE   = 'form_activate';
	const A_FORM_DEACTIVATE = 'form_deactivate';
	const A_ENTRY_LIST      = 'entry_list';
	const A_ENTRY_GET       = 'entry_get';
	const A_ENTRY_SEARCH    = 'entry_search';
	const A_ENTRY_EXPORT    = 'entry_export';
	const A_NOTIF_GET       = 'notification_get';
	const A_NOTIF_UPDATE    = 'notification_update';
	const A_NOTIF_TEST      = 'notification_test';
	const A_SUBMISSION_STATS = 'submission_stats';
	const A_FORM_ANALYZE     = 'form_analyze';

	const ACTIONS = [
		self::A_FORM_LIST, self::A_FORM_GET, self::A_FORM_SEARCH, self::A_FORM_CREATE, self::A_FORM_UPDATE,
		self::A_FORM_DUPLICATE, self::A_FORM_DELETE, self::A_FORM_ACTIVATE, self::A_FORM_DEACTIVATE,
		self::A_ENTRY_LIST, self::A_ENTRY_GET, self::A_ENTRY_SEARCH, self::A_ENTRY_EXPORT,
		self::A_NOTIF_GET, self::A_NOTIF_UPDATE, self::A_NOTIF_TEST,
		self::A_SUBMISSION_STATS, self::A_FORM_ANALYZE,
	];

	private static ?array $risk = null; private static ?array $approval = null; private static ?array $rollback = null;

	private static function init(): void {
		if ( self::$risk !== null ) return;
		$L = self::RISK_LOW; $M = self::RISK_MEDIUM; $H = self::RISK_HIGH;
		self::$risk = [
			self::A_FORM_LIST => $L, self::A_FORM_GET => $L, self::A_FORM_SEARCH => $L,
			self::A_ENTRY_LIST => $L, self::A_ENTRY_GET => $L, self::A_ENTRY_SEARCH => $L,
			self::A_SUBMISSION_STATS => $L, self::A_FORM_ANALYZE => $L, self::A_NOTIF_GET => $L,
			self::A_NOTIF_UPDATE => $M, self::A_FORM_UPDATE => $M, self::A_ENTRY_EXPORT => $M,
			self::A_FORM_CREATE => $H, self::A_FORM_DELETE => $H, self::A_FORM_ACTIVATE => $H,
			self::A_FORM_DEACTIVATE => $H, self::A_FORM_DUPLICATE => $H, self::A_NOTIF_TEST => $M,
		];
		self::$approval = []; foreach ( self::ACTIONS as $a ) self::$approval[ $a ] = ( self::$risk[ $a ] ?? $M ) === $H;
		self::$rollback = [
			self::A_FORM_CREATE => true, self::A_FORM_UPDATE => true, self::A_FORM_DELETE => true,
			self::A_NOTIF_UPDATE => true,
		];
	}

	public static function get_risk( string $a ): string { self::init(); return self::$risk[ $a ] ?? self::RISK_MEDIUM; }
	public static function requires_approval( string $a ): bool { self::init(); return self::$approval[ $a ] ?? true; }
	public static function supports_rollback( string $a ): bool { self::init(); return self::$rollback[ $a ] ?? false; }
}
