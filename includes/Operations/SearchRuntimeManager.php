<?php
namespace WPCommandCenter\Operations;

use WPCommandCenter\Security\AuditLog;

defined( 'ABSPATH' ) || exit;

final class SearchRuntimeManager {
	private AuditLog $audit;
	public function __construct(){ $this->audit=new AuditLog(); }

	public function run(array $p,array $cx=[]):array{
		$a=(string)($p['action']??'');
		if(!in_array($a,SearchRegistry::ACTIONS,true))return $this->err('invalid',__('Invalid action.','wp-command-center'));
		$this->audit->record("search.$a",['query'=>$p['search']??'']);
		$r=match($a){
			SearchRegistry::A_SEARCH_ALL=>$this->search_all($p),
			SearchRegistry::A_SEARCH_CONTENT=>$this->search_content($p),
			SearchRegistry::A_SEARCH_MEDIA=>$this->search_media($p),
			SearchRegistry::A_SEARCH_USERS=>$this->search_users($p),
			SearchRegistry::A_SEARCH_WOO=>$this->search_woo($p),
			SearchRegistry::A_SEARCH_FORMS=>$this->search_forms($p),
			SearchRegistry::A_SEARCH_ACF=>$this->search_acf($p),
			SearchRegistry::A_SEARCH_MENUS=>$this->search_menus($p),
			SearchRegistry::A_REPORT_ORPHANS=>$this->report_orphans(),
			SearchRegistry::A_REPORT_UNUSED_MEDIA=>$this->report_unused_media(),
			SearchRegistry::A_REPORT_CONTENT_INVENTORY=>$this->report_content_inventory(),
			SearchRegistry::A_REPORT_WOO_INVENTORY=>$this->report_woo_inventory(),
			SearchRegistry::A_REPORT_SITE_SUMMARY=>$this->report_site_summary(),
			default=>$this->err('unknown','Unknown.')
		};
		return array_merge(['action'=>$a],$r);
	}

	private function search_all(array $p):array{
		$q=sanitize_text_field((string)($p['search']??''));
		$limit=min(5,max(1,(int)($p['max_results']??$p['per_page']??5)));
		return['query'=>$q,'content'=>$this->search_content(['search'=>$q,'per_page'=>$limit])['items']??[],
			'media'=>$this->search_media(['search'=>$q,'per_page'=>$limit])['items']??[],
			'users'=>$this->search_users(['search'=>$q,'per_page'=>$limit])['items']??[],
			'woocommerce'=>$this->search_woo(['search'=>$q,'per_page'=>$limit])['items']??[]];
	}

	private function search_content(array $p):array{
		$q=sanitize_text_field((string)($p['search']??''));
		if(''===$q)return['items'=>[],'total'=>0];
		[$limit,$offset]=$this->paging($p);
		$query=new \WP_Query(['s'=>$q,'post_type'=>['post','page'],'posts_per_page'=>$limit,'offset'=>$offset,'post_status'=>'any']);
		$items=[];foreach($query->posts as $post)$items[]=['id'=>$post->ID,'title'=>$post->post_title,'type'=>$post->post_type,'status'=>$post->post_status,'excerpt'=>wp_trim_words($post->post_content,20)];
		return$this->page_result($items,(int)$query->found_posts,$offset);
	}

	private function search_media(array $p):array{
		$q=sanitize_text_field((string)($p['search']??''));
		if(''===$q)return['items'=>[],'total'=>0];
		[$limit,$offset]=$this->paging($p);
		$query=new \WP_Query(['s'=>$q,'post_type'=>'attachment','post_status'=>'inherit','posts_per_page'=>$limit,'offset'=>$offset]);
		$items=[];foreach($query->posts as $post)$items[]=['id'=>$post->ID,'title'=>$post->post_title,'mime'=>get_post_mime_type($post->ID),'url'=>wp_get_attachment_url($post->ID)];
		return$this->page_result($items,(int)$query->found_posts,$offset);
	}

	private function search_users(array $p):array{
		$q=sanitize_text_field((string)($p['search']??''));
		if(''===$q)return['items'=>[],'total'=>0];
		[$limit,$offset]=$this->paging($p);
		$query=new \WP_User_Query(['search'=>'*'.$q.'*','search_columns'=>['user_login','user_email','display_name'],'number'=>$limit,'offset'=>$offset]);
		$items=[];foreach($query->get_results() as $u)$items[]=['id'=>$u->ID,'username'=>$u->user_login,'email'=>$u->user_email,'display_name'=>$u->display_name,'roles'=>array_values($u->roles)];
		return$this->page_result($items,(int)$query->get_total(),$offset);
	}

	private function search_woo(array $p):array{
		$q=sanitize_text_field((string)($p['search']??''));
		if(''===$q||!class_exists('WooCommerce'))return['items'=>[],'total'=>0];
		[$limit,$offset]=$this->paging($p);
		$products=wc_get_products(['s'=>$q,'limit'=>$limit,'offset'=>$offset,'paginate'=>true]);
		$items=[];foreach($products->products as $pr)$items[]=['id'=>$pr->get_id(),'name'=>$pr->get_name(),'price'=>$pr->get_price(),'status'=>$pr->get_status()];
		return$this->page_result($items,(int)$products->total,$offset);
	}

	private function search_forms(array $p):array{
		$q=sanitize_text_field((string)($p['search']??''));
		if(''===$q||!defined('WPCF7_VERSION'))return['items'=>[],'total'=>0];
		[$limit,$offset]=$this->paging($p);
		$posts=get_posts(['post_type'=>'wpcf7_contact_form','s'=>$q,'posts_per_page'=>$limit,'offset'=>$offset]);
		$items=[];foreach($posts as $post)$items[]=['id'=>$post->ID,'title'=>$post->post_title];
		$total=(int)(new \WP_Query(['post_type'=>'wpcf7_contact_form','s'=>$q,'posts_per_page'=>1,'fields'=>'ids']))->found_posts;
		return$this->page_result($items,$total,$offset);
	}

	private function search_acf(array $p):array{
		$q=sanitize_text_field((string)($p['search']??''));
		if(''===$q||!function_exists('acf_get_field_groups'))return['items'=>[],'total'=>0];
		[$limit,$offset]=$this->paging($p);$groups=acf_get_field_groups();$matches=[];
		foreach($groups as $g){if(stripos($g['title'],$q)!==false)$matches[]=['key'=>$g['key'],'title'=>$g['title']];}
		return$this->page_result(array_slice($matches,$offset,$limit),count($matches),$offset);
	}

	private function search_menus(array $p):array{
		$q=sanitize_text_field((string)($p['search']??''));
		if(''===$q)return['items'=>[],'total'=>0];
		[$limit,$offset]=$this->paging($p);$menus=wp_get_nav_menus();$items=[];
		foreach($menus as $m){
			$mi=wp_get_nav_menu_items($m->term_id)?:[];
			foreach($mi as $i){if(stripos($i->title,$q)!==false)$items[]=['id'=>$i->ID,'title'=>$i->title,'url'=>$i->url,'menu'=>$m->name];}
		}
		return$this->page_result(array_slice($items,$offset,$limit),count($items),$offset);
	}

	private function report_orphans():array{
		$types=['post','page'];$orphans=[];$total=0;
		foreach($types as $t){$posts=get_posts(['post_type'=>$t,'post_status'=>'any','posts_per_page'=>-1,'post_parent'=>0]);foreach($posts as $p){if($p->post_parent>0){$parent=get_post($p->post_parent);if(!$parent||'trash'===$parent->post_status){$orphans[]=['id'=>$p->ID,'title'=>$p->post_title,'type'=>$t,'parent_id'=>$p->post_parent];$total++;}}}}
		return['orphans'=>$orphans,'total'=>$total];
	}

	private function report_unused_media():array{
		$media=get_posts(['post_type'=>'attachment','post_status'=>'inherit','posts_per_page'=>200,'fields'=>'ids']);
		$unused=[];foreach($media as $mid){if(!get_post($mid)->post_parent&&!get_post_meta($mid,'_wp_attachment_image_alt',true))$unused[]=$mid;}
		return['unused_ids'=>$unused,'count'=>count($unused)];
	}

	private function report_content_inventory():array{
		$counts=wp_count_posts();$pages=wp_count_posts('page');
		return['posts'=>(int)$counts->publish,'pages'=>(int)$pages->publish,'drafts'=>(int)$counts->draft,'trash'=>(int)$counts->trash,'total'=>(int)($counts->publish+$pages->publish)];
	}

	private function report_woo_inventory():array{
		if(!class_exists('WooCommerce'))return['available'=>false];
		$p=wc_get_products(['limit'=>1,'paginate'=>true]);$o=wc_get_orders(['limit'=>1,'paginate'=>true,'return'=>'ids']);
		return['products'=>(int)$p->total,'orders'=>(int)$o->total,'available'=>true];
	}

	private function report_site_summary():array{
		$c=wp_count_posts();$p=wp_count_posts('page');$u=count_users();
		return['posts'=>(int)$c->publish,'pages'=>(int)$p->publish,'users'=>$u['total_users'],'plugins'=>count(get_plugins()),'themes'=>count(wp_get_themes()),'menus'=>count(wp_get_nav_menus()),'media'=>(int)wp_count_posts('attachment')->inherit,'comments'=>(int)wp_count_comments()->total_comments];
	}

	private function paging(array $p):array{
		$limit=min(50,max(1,(int)($p['max_results']??$p['per_page']??20)));
		$offset=max(0,((int)($p['page']??1)-1)*$limit);
		$cursor=(string)($p['cursor']??'');
		if(''!==$cursor){
			$decoded=json_decode((string)base64_decode($cursor,true),true);
			if(is_array($decoded)&&isset($decoded['offset']))$offset=max(0,(int)$decoded['offset']);
		}
		return[$limit,$offset];
	}

	private function page_result(array $items,int $total,int $offset):array{
		$next_offset=$offset+count($items);
		return[
			'items'=>$items,
			'total'=>$total,
			'count'=>count($items),
			'next_cursor'=>$next_offset<$total?base64_encode((string)wp_json_encode(['offset'=>$next_offset])):null,
		];
	}

	private function err(string $c,string $m):array{return['error'=>true,'code'=>$c,'message'=>$m];}
}
