<?php
namespace WPCommandCenter\Operations;

use WPCommandCenter\Security\AuditLog;

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
		$result=$this->$method($p);
		if(isset($result['error'])){
			return new \WP_Error($result['code'],$result['message']);
		}
		if($is_mutation){
			$rid=$this->store_rollback($a,[],$cx);
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
	public function rollback(array $p,array $cx=[]):array{$rid=(string)($p['rollback_id']??'');if(''===$rid)return $this->err('wpcc_missing_rb',__('Rollback ID required.','wp-command-center'));$rbs=get_option('wpcc_settings_rollbacks',[]);$rec=null;$idx=null;foreach($rbs as $i=>$r){if($r['id']===$rid){$rec=$r;$idx=$i;break;}}if(!$rec)return $this->err('wpcc_rb_nf',__('Not found.','wp-command-center'));if($rec['rollback_applied'])return $this->err('wpcc_rb_done',__('Already applied.','wp-command-center'));foreach($rec['before_state']as $opt=>$val)update_option($opt,$val);$rbs[$idx]['rollback_applied']=true;update_option('wpcc_settings_rollbacks',$rbs);return['action'=>'settings_rollback','rollback_id'=>$rid];}

	private function store_rollback(string $action,array $before,array $cx):string{$rid=wp_generate_uuid4();$maps=[SettingsRegistry::A_GENERAL_UPDATE=>['blogname','blogdescription','admin_email','WPLANG','timezone_string','date_format','time_format','start_of_week'],SettingsRegistry::A_READING_UPDATE=>['page_on_front','page_for_posts','posts_per_page','posts_per_rss','blog_public'],SettingsRegistry::A_DISCUSSION_UPDATE=>['default_comment_status','comment_moderation','require_name_email','comment_registration','avatar_default','thread_comments'],SettingsRegistry::A_MEDIA_UPDATE=>['thumbnail_size_w','thumbnail_size_h','thumbnail_crop','medium_size_w','medium_size_h','large_size_w','large_size_h'],SettingsRegistry::A_PERMALINK_UPDATE=>['permalink_structure','category_base','tag_base'],SettingsRegistry::A_PRIVACY_UPDATE=>['wp_page_for_privacy_policy']];$snapshot=[];foreach(($maps[$action]??[])as $opt)$snapshot[$opt]=get_option($opt);$rbs=get_option('wpcc_settings_rollbacks',[]);$rbs[]=['id'=>$rid,'action'=>$action,'before_state'=>$snapshot,'rollback_applied'=>false,'created_at'=>time(),'session_id'=>$cx['session_id']??null,'task_id'=>$cx['task_id']??null];if(count($rbs)>200)$rbs=array_slice($rbs,-200);update_option('wpcc_settings_rollbacks',$rbs);return $rid;}
	private function err(string $code,string $msg):array{return['error'=>true,'code'=>$code,'message'=>$msg];}
}
