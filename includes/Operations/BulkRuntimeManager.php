<?php
namespace WPCommandCenter\Operations;

use WPCommandCenter\Security\AuditLog;

defined( 'ABSPATH' ) || exit;

final class BulkRegistry {
	const RISK_HIGH='high';
	const A_BULK_CONTENT='bulk_content';
	const A_BULK_PUBLISH='bulk_publish';
	const A_BULK_UNPUBLISH='bulk_unpublish';
	const A_BULK_MEDIA='bulk_media';
	const A_BULK_WOO='bulk_woocommerce';
	const A_BULK_ACF='bulk_acf';
	const A_BATCH_EXECUTE='batch_execute';
	const ACTIONS=[self::A_BULK_CONTENT,self::A_BULK_PUBLISH,self::A_BULK_UNPUBLISH,self::A_BULK_MEDIA,self::A_BULK_WOO,self::A_BULK_ACF,self::A_BATCH_EXECUTE];
	public static function requires_approval(string $a):bool{return true;}
	public static function get_risk(string $a):string{return self::RISK_HIGH;}
}

final class BulkRuntimeManager {
	const MAX_ITEMS = 200;
	private AuditLog $audit;
	public function __construct(){ $this->audit=new AuditLog(); }

	public function run(array $p,array $cx=[]):array{
		$a=(string)($p['action']??'');
		if(!in_array($a,BulkRegistry::ACTIONS,true))return $this->err('invalid',__('Invalid action.','wp-command-center'));
		$result=match($a){
			BulkRegistry::A_BULK_CONTENT=>$this->bulk_content($p,$cx),
			BulkRegistry::A_BULK_PUBLISH=>$this->bulk_status($p,'publish',$cx),
			BulkRegistry::A_BULK_UNPUBLISH=>$this->bulk_status($p,'draft',$cx),
			BulkRegistry::A_BULK_MEDIA=>$this->bulk_media($p,$cx),
			BulkRegistry::A_BULK_WOO=>$this->bulk_woo($p,$cx),
			BulkRegistry::A_BULK_ACF=>$this->bulk_acf($p,$cx),
			BulkRegistry::A_BATCH_EXECUTE=>$this->batch_execute($p,$cx),
			default=>$this->err('unknown','Unknown.')
		};
		$this->audit->record("bulk.$a",['count'=>count($result['results']??$result['updated']??[])]);
		return array_merge(['action'=>$a],$result);
	}

	private function bulk_content(array $p,array $cx):array{
		$ids=(array)($p['ids']??[]);$fields=$p['fields']??[];$results=[];$before=[];
		if(count($ids)>self::MAX_ITEMS)return$this->err('too_many_items',sprintf(__('Cannot process more than %d items in a single bulk operation.','wp-command-center'),self::MAX_ITEMS));
		foreach($ids as $id){$post=get_post((int)$id);if(!$post)continue;$before[$id]=$post->post_title;$u=['ID'=>(int)$id];if(isset($fields['post_title']))$u['post_title']=sanitize_text_field($fields['post_title']);if(isset($fields['post_content']))$u['post_content']=$fields['post_content'];wp_update_post($u);$results[]=$id;}
		$this->store_rollback('bulk_content','bulk_content',['ids'=>$ids,'before'=>$before],$cx);
		return['updated'=>count($results),'results'=>$results];
	}

	private function bulk_status(array $p,string $status,array $cx):array{
		$ids=(array)($p['ids']??[]);$results=[];$before=[];
		if(count($ids)>self::MAX_ITEMS)return$this->err('too_many_items',sprintf(__('Cannot process more than %d items in a single bulk operation.','wp-command-center'),self::MAX_ITEMS));
		foreach($ids as $id){$post=get_post((int)$id);if(!$post)continue;$before[$id]=$post->post_status;wp_update_post(['ID'=>(int)$id,'post_status'=>$status]);$results[]=$id;}
		$this->store_rollback("bulk_$status","bulk_$status",['ids'=>$ids,'before'=>$before],$cx);
		return['updated'=>count($results),'results'=>$results];
	}

	private function bulk_media(array $p,array $cx):array{
		$ids=(array)($p['ids']??[]);$results=[];
		if(count($ids)>self::MAX_ITEMS)return$this->err('too_many_items',sprintf(__('Cannot process more than %d items in a single bulk operation.','wp-command-center'),self::MAX_ITEMS));
		foreach($ids as $id){$post=get_post((int)$id);if(!$post||'attachment'!==$post->post_type)continue;if(!empty($p['title'])){wp_update_post(['ID'=>(int)$id,'post_title'=>sanitize_text_field($p['title'])]);}$results[]=$id;}
		return['updated'=>count($results),'results'=>$results];
	}

	private function bulk_woo(array $p,array $cx):array{
		if(!class_exists('WooCommerce'))return['updated'=>0,'results'=>[]];
		$ids=(array)($p['ids']??[]);$results=[];$price=$p['regular_price']??null;$status=$p['status']??null;
		if(count($ids)>self::MAX_ITEMS)return$this->err('too_many_items',sprintf(__('Cannot process more than %d items in a single bulk operation.','wp-command-center'),self::MAX_ITEMS));
		foreach($ids as $id){$pr=wc_get_product((int)$id);if(!$pr)continue;if($price!==null)$pr->set_regular_price((string)$price);if($status!==null)$pr->set_status(sanitize_key($status));$pr->save();$results[]=$id;}
		return['updated'=>count($results),'results'=>$results];
	}

	private function bulk_acf(array $p,array $cx):array{
		if(!function_exists('acf_get_field_groups'))return['updated'=>0,'results'=>[]];
		$ids=(array)($p['post_ids']??[]);$field= sanitize_text_field((string)($p['field_key']??$p['field_name']??''));$value=$p['value']??null;$results=[];
		if(''===$field)return$this->err('missing_field',__('Field key required.','wp-command-center'));
		if(count($ids)>self::MAX_ITEMS)return$this->err('too_many_items',sprintf(__('Cannot process more than %d items in a single bulk operation.','wp-command-center'),self::MAX_ITEMS));
		foreach($ids as $id){update_field($field,$value,(int)$id);$results[]=$id;}
		return['updated'=>count($results),'results'=>$results];
	}

	private function batch_execute(array $p,array $cx):array{
		$ops=(array)($p['operations']??[]);$results=[];$executor=new \WPCommandCenter\Operations\OperationExecutor();
		if(count($ops)>self::MAX_ITEMS)return$this->err('too_many_items',sprintf(__('Cannot process more than %d operations in a single batch.','wp-command-center'),self::MAX_ITEMS));
		foreach($ops as $op){$r=$executor->run((string)($op['operation_id']??''),(array)($op['payload']??[]),$cx);$results[]=['operation_id'=>$op['operation_id']??'','success'=>$r['success']??false];}
		return['executed'=>count($results),'results'=>$results];
	}

	private function store_rollback(string $id,string $action,array $before,array $cx):void{
		$rb=get_option('wpcc_bulk_rollbacks',[]);
		$rb[]=['id'=>wp_generate_uuid4(),'entity_id'=>$id,'action'=>$action,'before_state'=>$before,'rollback_applied'=>false,'created_at'=>time(),'session_id'=>$cx['session_id']??null,'task_id'=>$cx['task_id']??null];
		if(count($rb)>200)$rb=array_slice($rb,-200);update_option('wpcc_bulk_rollbacks',$rb);
	}

	public function rollback(array $p,array $cx=[]):array{
		$rid=(string)($p['rollback_id']??'');if(''===$rid)return$this->err('missing','Rollback ID required.');
		$rbs=get_option('wpcc_bulk_rollbacks',[]);$rec=null;$idx=null;
		foreach($rbs as $i=>$r){if($r['id']===$rid){$rec=$r;$idx=$i;break;}}
		if(!$rec)return$this->err('nf','Not found.');if($rec['rollback_applied'])return$this->err('done','Already applied.');
		$before=$rec['before_state'];$titles=$before['before']??[];
		foreach($titles as $id=>$old_title){$post=get_post((int)$id);if(!$post)continue;wp_update_post(['ID'=>(int)$id,'post_title'=>$old_title]);}
		$rbs[$idx]['rollback_applied']=true;update_option('wpcc_bulk_rollbacks',$rbs);
		return['action'=>'bulk_rollback','rollback_id'=>$rid];
	}

	private function err(string $c,string $m):array{return['error'=>true,'code'=>$c,'message'=>$m];}
}
