<?php
namespace WPCommandCenter\Operations;

use WPCommandCenter\Security\AuditLog;
use WPCommandCenter\Rollback\RollbackDelta;
use WPCommandCenter\Rollback\OptionAccessor;

defined( 'ABSPATH' ) || exit;

final class SettingsRuntimeManager {
	private AuditLog $audit;
	public function __construct(){ $this->audit=new AuditLog(); }

	public function run(array $p,array $cx=[]):array|\WP_Error{
		$a=(string)($p['action']??'');
		if(!in_array($a,SettingsRegistry::ACTIONS,true))return new \WP_Error('wpcc_invalid_settings_action',__('Invalid settings action.','wp-command-center'));
		$opts=[
			SettingsRegistry::A_GENERAL_GET=>['general_get',false],SettingsRegistry::A_GENERAL_UPDATE=>['general_update',true],
			SettingsRegistry::A_READING_GET=>['reading_get',false],SettingsRegistry::A_READING_UPDATE=>['reading_update',true],
			SettingsRegistry::A_DISCUSSION_GET=>['discussion_get',false],SettingsRegistry::A_DISCUSSION_UPDATE=>['discussion_update',true],
			SettingsRegistry::A_MEDIA_GET=>['media_get',false],SettingsRegistry::A_MEDIA_UPDATE=>['media_update',true],
			SettingsRegistry::A_PERMALINK_GET=>['permalink_get',false],SettingsRegistry::A_PERMALINK_UPDATE=>['permalink_update',true],
			SettingsRegistry::A_PRIVACY_GET=>['privacy_get',false],SettingsRegistry::A_PRIVACY_UPDATE=>['privacy_update',true],
			SettingsRegistry::A_INVENTORY=>['inventory',false],SettingsRegistry::A_ANALYZE=>['analyze',false],
		];
		[$method,$is_mutation]=$opts[$a];
		// PROGRAM-4 / P4.1 — capture the prior state of ONLY the options this call will
		// touch BEFORE the write (the field-scoped, drift-aware delta). This both fixes the
		// pre-existing capture-after-write defect and replaces the full-object group snapshot.
		$touched=$is_mutation?$this->touched_options($a,$p):[];
		$prior=$is_mutation?RollbackDelta::capture(new OptionAccessor(),0,$touched):[];
		$result=$this->$method($p);
		if(isset($result['error'])){
			return new \WP_Error($result['code'],$result['message']);
		}
		if($is_mutation){
			$after=[];foreach($touched as $opt)$after[$opt]=get_option($opt);
			$rid=$this->store_rollback($a,$touched,$prior,$after,$cx);
			$result['rollback_id']=$rid;
			$labels=[
				SettingsRegistry::A_GENERAL_UPDATE=>['settings.general.updated','Site settings updated'],
				SettingsRegistry::A_READING_UPDATE=>['settings.reading.updated','Reading settings updated'],
				SettingsRegistry::A_DISCUSSION_UPDATE=>['settings.discussion.updated','Discussion settings updated'],
				SettingsRegistry::A_MEDIA_UPDATE=>['settings.media.updated','Media settings updated'],
				SettingsRegistry::A_PERMALINK_UPDATE=>['settings.permalink.updated','Permalink updated'],
				SettingsRegistry::A_PRIVACY_UPDATE=>['settings.privacy.updated','Privacy settings updated'],
			];
			if(isset($labels[$a]))$this->audit->record($labels[$a][0],[]);
		}
		if($a===SettingsRegistry::A_ANALYZE)$this->audit->record('settings.analyzed',[]);
		return array_merge(['action'=>$a],$result);
	}

	private function general_get(array $p):array{return['settings'=>['site_title'=>get_option('blogname'),'tagline'=>get_option('blogdescription'),'admin_email'=>get_option('admin_email'),'language'=>get_option('WPLANG')?:'en_US','timezone'=>get_option('timezone_string')?:'UTC','date_format'=>get_option('date_format'),'time_format'=>get_option('time_format'),'week_start'=>get_option('start_of_week')]];}
	private function general_update(array $p):array{$f=['blogname'=>'site_title','blogdescription'=>'tagline','admin_email'=>null,'WPLANG'=>'language','timezone_string'=>'timezone','date_format'=>null,'time_format'=>null,'start_of_week'=>'week_start'];foreach($f as $opt=>$key){if(isset($p[$key??$opt])){if('admin_email'===$opt&&!is_email($p[$key]))return $this->err('wpcc_invalid_email',__('Invalid email.','wp-command-center'));update_option($opt,sanitize_text_field((string)$p[$key]));}}return['updated'=>true];}
	private function reading_get(array $p):array{return['settings'=>['front_page'=>get_option('page_on_front'),'posts_page'=>get_option('page_for_posts'),'posts_per_page'=>(int)get_option('posts_per_page'),'feed_limit'=>(int)get_option('posts_per_rss'),'search_visibility'=>(bool)get_option('blog_public')]];}
	private function reading_update(array $p):array{foreach(['page_on_front'=>'front_page','page_for_posts'=>'posts_page','posts_per_page'=>null,'posts_per_rss'=>'feed_limit']as $opt=>$key){if(isset($p[$key??$opt]))update_option($opt,(int)$p[$key]);}if(isset($p['search_visibility']))update_option('blog_public',(int)$p['search_visibility']);return['updated'=>true];}
	private function discussion_get(array $p):array{return['settings'=>['default_comment_status'=>get_option('default_comment_status'),'comment_moderation'=>(bool)get_option('comment_moderation'),'require_name_email'=>(bool)get_option('require_name_email'),'comment_registration'=>(bool)get_option('comment_registration'),'avatar_default'=>get_option('avatar_default'),'thread_comments'=>(bool)get_option('thread_comments')]];}
	private function discussion_update(array $p):array{foreach(['default_comment_status'=>null,'comment_moderation'=>null,'require_name_email'=>null,'comment_registration'=>null,'avatar_default'=>null,'thread_comments'=>null]as $opt=>$key){if(isset($p[$opt]))update_option($opt,sanitize_text_field((string)$p[$opt]));}return['updated'=>true];}
	private function media_get(array $p):array{return['settings'=>['thumbnail_w'=>(int)get_option('thumbnail_size_w'),'thumbnail_h'=>(int)get_option('thumbnail_size_h'),'thumbnail_crop'=>(bool)get_option('thumbnail_crop'),'medium_w'=>(int)get_option('medium_size_w'),'medium_h'=>(int)get_option('medium_size_h'),'large_w'=>(int)get_option('large_size_w'),'large_h'=>(int)get_option('large_size_h')]];}
	private function media_update(array $p):array{foreach(['thumbnail_size_w','thumbnail_size_h','thumbnail_crop','medium_size_w','medium_size_h','large_size_w','large_size_h']as $opt){if(isset($p[$opt]))update_option($opt,(int)$p[$opt]);}return['updated'=>true];}
	private function permalink_get(array $p):array{$s=get_option('permalink_structure');$labels=[''=>'Plain','/archives/%post_id%'=>'Numeric','/%year%/%monthnum%/%day%/%postname%/'=>'Day and name','/%year%/%monthnum%/%postname%/'=>'Month and name','/%postname%/'=>'Post name'];return['settings'=>['structure'=>$s,'label'=>$labels[$s]??'Custom','category_base'=>get_option('category_base'),'tag_base'=>get_option('tag_base')]];}
	private function permalink_update(array $p):array{if(isset($p['structure'])){$s=sanitize_text_field((string)$p['structure']);global $wp_rewrite;$wp_rewrite->set_permalink_structure($s);flush_rewrite_rules();}return['updated'=>true];}
	private function privacy_get(array $p):array{return['settings'=>['privacy_page'=>(int)get_option('wp_page_for_privacy_policy')]];}
	private function privacy_update(array $p):array{if(isset($p['privacy_page'])){update_option('wp_page_for_privacy_policy',(int)$p['privacy_page']);}return['updated'=>true];}
	private function inventory(array $p):array{return['site_title'=>get_option('blogname'),'timezone'=>get_option('timezone_string')?:'UTC','language'=>get_option('WPLANG')?:'en_US','front_page'=>get_option('page_on_front'),'posts_page'=>get_option('page_for_posts'),'permalink'=>get_option('permalink_structure')?:'plain','comments_enabled'=>'open'===get_option('default_comment_status'),'privacy_page'=>(int)get_option('wp_page_for_privacy_policy'),'search_visible'=>(bool)get_option('blog_public')];}
	private function analyze(array $p):array{$issues=[];if(!get_option('blog_public'))$issues[]=['type'=>'seo','severity'=>'high','setting'=>'blog_public','message'=>'Search engines discouraged (site not public)'];if(empty(get_option('permalink_structure')))$issues[]=['type'=>'seo','severity'=>'medium','setting'=>'permalink_structure','message'=>'Plain permalinks — SEO unfriendly'];if(!get_option('wp_page_for_privacy_policy'))$issues[]=['type'=>'privacy','severity'=>'high','setting'=>'privacy_page','message'=>'No privacy policy page assigned'];if('open'!==get_option('default_comment_status'))$issues[]=['type'=>'discussion','severity'=>'low','setting'=>'comments','message'=>'Comments disabled by default'];if(!get_option('comment_moderation'))$issues[]=['type'=>'spam','severity'=>'low','setting'=>'comment_moderation','message'=>'No comment moderation — spam risk'];return['issue_count'=>count($issues),'issues'=>$issues];}
	public function rollback(array $p,array $cx=[]):array{
		$rid=(string)($p['rollback_id']??'');
		if(''===$rid)return $this->err('wpcc_missing_rb',__('Rollback ID required.','wp-command-center'));
		$rbs=get_option('wpcc_settings_rollbacks',[]);$rec=null;$idx=null;
		foreach($rbs as $i=>$r){if(($r['id']??'')===$rid){$rec=$r;$idx=$i;break;}}
		if(!$rec)return $this->err('wpcc_rb_nf',__('Not found.','wp-command-center'));
		if(!empty($rec['rollback_applied']))return $this->err('wpcc_rb_done',__('Already applied.','wp-command-center'));

		// PROGRAM-4 / P4.1 — v2 field-scoped, drift-aware delta restore via the RollbackDelta
		// core. Only complete is terminal (idempotency); partial/conflict stay retryable.
		if(isset($rec['fields'])&&is_array($rec['fields'])){
			$o=RollbackDelta::restore(new OptionAccessor(),0,$rec['fields']);
			$status=$o['status'];
			if('complete'===$status){$rbs[$idx]['rollback_applied']=true;update_option('wpcc_settings_rollbacks',$rbs);}
			$this->audit->record('settings.restored',['rollback_id'=>$rid,'path'=>'delta','status'=>$status,'restored_fields'=>$o['restored'],'skipped_fields'=>$o['skipped']]);
			if('complete'===$status)return['action'=>'settings_rollback','rollback_id'=>$rid,'restored'=>true,'status'=>'complete','path'=>'delta','restored_fields'=>$o['restored'],'skipped_fields'=>[]];
			$code='conflict'===$status?'wpcc_rollback_conflict':'wpcc_rollback_partial';
			$msg='conflict'===$status?__('Rollback skipped: every targeted setting changed since this update was applied. No settings were restored.','wp-command-center'):__('Partial rollback: some settings were restored; others were skipped because they changed since this update was applied (drift).','wp-command-center');
			return['error'=>true,'code'=>$code,'message'=>$msg,'action'=>'settings_rollback','rollback_id'=>$rid,'restored'=>false,'status'=>$status,'path'=>'delta','restored_fields'=>$o['restored'],'skipped_fields'=>$o['skipped'],'conflicts'=>$o['conflicts']];
		}

		// Legacy pre-P4.1 full-object record: restore the whole before_state unchanged.
		foreach(($rec['before_state']??[])as $opt=>$val)update_option($opt,$val);
		$rbs[$idx]['rollback_applied']=true;update_option('wpcc_settings_rollbacks',$rbs);
		$this->audit->record('settings.restored',['rollback_id'=>$rid,'path'=>'legacy']);
		return['action'=>'settings_rollback','rollback_id'=>$rid,'restored'=>true,'status'=>'complete','path'=>'legacy'];
	}

	/** Options a given update action writes, keyed option_name => payload_key — the single
	 *  source of truth that mirrors each *_update method's write set (field-scoped unit). */
	private function option_field_map(string $action):array{
		switch($action){
			case SettingsRegistry::A_GENERAL_UPDATE:return['blogname'=>'site_title','blogdescription'=>'tagline','admin_email'=>'admin_email','WPLANG'=>'language','timezone_string'=>'timezone','date_format'=>'date_format','time_format'=>'time_format','start_of_week'=>'week_start'];
			case SettingsRegistry::A_READING_UPDATE:return['page_on_front'=>'front_page','page_for_posts'=>'posts_page','posts_per_page'=>'posts_per_page','posts_per_rss'=>'feed_limit','blog_public'=>'search_visibility'];
			case SettingsRegistry::A_DISCUSSION_UPDATE:return['default_comment_status'=>'default_comment_status','comment_moderation'=>'comment_moderation','require_name_email'=>'require_name_email','comment_registration'=>'comment_registration','avatar_default'=>'avatar_default','thread_comments'=>'thread_comments'];
			case SettingsRegistry::A_MEDIA_UPDATE:return['thumbnail_size_w'=>'thumbnail_size_w','thumbnail_size_h'=>'thumbnail_size_h','thumbnail_crop'=>'thumbnail_crop','medium_size_w'=>'medium_size_w','medium_size_h'=>'medium_size_h','large_size_w'=>'large_size_w','large_size_h'=>'large_size_h'];
			case SettingsRegistry::A_PERMALINK_UPDATE:return['permalink_structure'=>'structure'];
			case SettingsRegistry::A_PRIVACY_UPDATE:return['wp_page_for_privacy_policy'=>'privacy_page'];
			default:return[];
		}
	}

	/** The option names this call will actually write — those whose payload key is set
	 *  (matches each update method's isset() guard). */
	private function touched_options(string $action,array $p):array{
		$touched=[];foreach($this->option_field_map($action)as $opt=>$pkey){if(isset($p[$pkey]))$touched[]=$opt;}return $touched;
	}

	/** Persist one field-scoped v2 delta record (touched options only) in the existing
	 *  wpcc_settings_rollbacks option. Legacy before_state records remain readable. */
	private function store_rollback(string $action,array $touched,array $prior,array $after,array $cx):string{
		$rid=wp_generate_uuid4();
		$fields=[];foreach($touched as $opt){$fields[$opt]=['after'=>$after[$opt]??'','keys'=>$prior[$opt]['keys']??[]];}
		$rbs=get_option('wpcc_settings_rollbacks',[]);
		$rbs[]=['id'=>$rid,'version'=>2,'action'=>$action,'fields'=>$fields,'rollback_applied'=>false,'created_at'=>time(),'session_id'=>$cx['session_id']??null,'task_id'=>$cx['task_id']??null];
		if(count($rbs)>200)$rbs=array_slice($rbs,-200);
		update_option('wpcc_settings_rollbacks',$rbs);
		return $rid;
	}
	private function err(string $code,string $msg):array{return['error'=>true,'code'=>$code,'message'=>$msg];}
}
