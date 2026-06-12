<?php
namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class SettingsRegistry {
	const RISK_LOW='low'; const RISK_MEDIUM='medium'; const RISK_HIGH='high';

	const A_GENERAL_GET = 'settings_general_get'; const A_GENERAL_UPDATE = 'settings_general_update';
	const A_READING_GET = 'settings_reading_get'; const A_READING_UPDATE = 'settings_reading_update';
	const A_DISCUSSION_GET = 'settings_discussion_get'; const A_DISCUSSION_UPDATE = 'settings_discussion_update';
	const A_MEDIA_GET = 'settings_media_get'; const A_MEDIA_UPDATE = 'settings_media_update';
	const A_PERMALINK_GET = 'settings_permalink_get'; const A_PERMALINK_UPDATE = 'settings_permalink_update';
	const A_PRIVACY_GET = 'settings_privacy_get'; const A_PRIVACY_UPDATE = 'settings_privacy_update';
	const A_INVENTORY = 'settings_inventory'; const A_ANALYZE = 'settings_analyze';

	const ACTIONS = [
		self::A_GENERAL_GET,self::A_GENERAL_UPDATE,self::A_READING_GET,self::A_READING_UPDATE,
		self::A_DISCUSSION_GET,self::A_DISCUSSION_UPDATE,self::A_MEDIA_GET,self::A_MEDIA_UPDATE,
		self::A_PERMALINK_GET,self::A_PERMALINK_UPDATE,self::A_PRIVACY_GET,self::A_PRIVACY_UPDATE,
		self::A_INVENTORY,self::A_ANALYZE,
	];

	private static ?array $risk=null; private static ?array $approval=null; private static ?array $rollback=null;

	private static function init():void{if(self::$risk!==null)return;$L=self::RISK_LOW;$M=self::RISK_MEDIUM;$H=self::RISK_HIGH;
		self::$risk=[self::A_GENERAL_GET=>$L,self::A_READING_GET=>$L,self::A_DISCUSSION_GET=>$L,self::A_MEDIA_GET=>$L,self::A_PERMALINK_GET=>$L,self::A_PRIVACY_GET=>$L,self::A_INVENTORY=>$L,self::A_ANALYZE=>$L,
			self::A_GENERAL_UPDATE=>$M,self::A_READING_UPDATE=>$M,self::A_DISCUSSION_UPDATE=>$M,self::A_MEDIA_UPDATE=>$M,self::A_PRIVACY_UPDATE=>$M,
			self::A_PERMALINK_UPDATE=>$H];
		self::$approval=[];foreach(self::ACTIONS as $a)self::$approval[$a]=(self::$risk[$a]??$M)===$H;
		self::$rollback=[self::A_GENERAL_UPDATE=>true,self::A_READING_UPDATE=>true,self::A_DISCUSSION_UPDATE=>true,self::A_MEDIA_UPDATE=>true,self::A_PERMALINK_UPDATE=>true,self::A_PRIVACY_UPDATE=>true];
	}
	public static function get_risk(string $a):string{self::init();return self::$risk[$a]??self::RISK_MEDIUM;}
	public static function requires_approval(string $a):bool{self::init();return self::$approval[$a]??true;}
	public static function supports_rollback(string $a):bool{self::init();return self::$rollback[$a]??false;}
}
