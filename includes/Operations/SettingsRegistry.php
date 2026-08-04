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

	/**
	 * Which WordPress option each update action writes, keyed option_name => payload_key.
	 *
	 * Lives here rather than inside the runtime because THREE things need it and used to
	 * answer separately: the code that performs the write, the rollback capture that has
	 * to know which options to snapshot, and the operation catalogue that has to tell an
	 * assistant which fields it may send. The catalogue had no answer at all — it declared
	 * `action` and nothing else, so a schema-following assistant could name an action but
	 * never a value, and produced requests that changed nothing while still costing a
	 * human approval.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function field_map():array{
		return [
			self::A_GENERAL_UPDATE=>['blogname'=>'site_title','blogdescription'=>'tagline','admin_email'=>'admin_email','WPLANG'=>'language','timezone_string'=>'timezone','date_format'=>'date_format','time_format'=>'time_format','start_of_week'=>'week_start'],
			self::A_READING_UPDATE=>['page_on_front'=>'front_page','page_for_posts'=>'posts_page','posts_per_page'=>'posts_per_page','posts_per_rss'=>'feed_limit','blog_public'=>'search_visibility'],
			self::A_DISCUSSION_UPDATE=>['default_comment_status'=>'default_comment_status','comment_moderation'=>'comment_moderation','require_name_email'=>'require_name_email','comment_registration'=>'comment_registration','avatar_default'=>'avatar_default','thread_comments'=>'thread_comments'],
			self::A_MEDIA_UPDATE=>['thumbnail_size_w'=>'thumbnail_size_w','thumbnail_size_h'=>'thumbnail_size_h','thumbnail_crop'=>'thumbnail_crop','medium_size_w'=>'medium_size_w','medium_size_h'=>'medium_size_h','large_size_w'=>'large_size_w','large_size_h'=>'large_size_h'],
			self::A_PERMALINK_UPDATE=>['permalink_structure'=>'structure'],
			self::A_PRIVACY_UPDATE=>['wp_page_for_privacy_policy'=>'privacy_page'],
		];
	}

	/**
	 * Every payload key any update action accepts, deduplicated — the catalogue's
	 * parameter list for `settings_manage`, derived from the same map that performs the
	 * write so the two can never disagree about what this operation accepts.
	 *
	 * @return array<int,string>
	 */
	public static function payload_fields():array{
		$out=[];
		foreach(self::field_map() as $fields){foreach($fields as $pkey){$out[$pkey]=true;}}
		return array_keys($out);
	}

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
