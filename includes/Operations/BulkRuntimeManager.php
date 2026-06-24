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

/**
 * PROGRAM-4C.0a — Bulk rollback corruption remediation.
 *
 * Each mutating bulk action records a SELF-DESCRIBING per-id field map (which fields it
 * wrote + their prior values) instead of a bare scalar, and rollback() dispatches on the
 * record's `action` to restore exactly those fields via the correct primitive. This
 * eliminates the prior defect where a status rollback wrote the captured status string
 * into post_title (and never restored post_status), and closes the missing-rollback gaps
 * for media / woocommerce / acf. Backward-compatible: a legacy scalar `before[id]` is
 * mapped to the action's primary field (so even a legacy bulk_publish/bulk_draft record now
 * restores status correctly). Hotfix scope only — no RollbackDelta/per-item store/drift
 * (deferred to P4.8); no schema / registry / contract change.
 */
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
		$rfields=[];if(isset($fields['post_title']))$rfields[]='post_title';if(isset($fields['post_content']))$rfields[]='post_content';
		foreach($ids as $id){
			$post=get_post((int)$id);if(!$post)continue;
			$snap=[];$u=['ID'=>(int)$id];
			if(isset($fields['post_title'])){$snap['post_title']=$post->post_title;$u['post_title']=sanitize_text_field($fields['post_title']);}
			if(isset($fields['post_content'])){$snap['post_content']=$post->post_content;$u['post_content']=$fields['post_content'];}
			if(count($u)<2)continue;
			wp_update_post($u);$before[$id]=$snap;$results[]=$id;
		}
		$rid=$before?$this->store_rollback('bulk_content','bulk_content',['ids'=>$ids,'fields'=>$rfields,'before'=>$before],$cx):'';
		return['updated'=>count($results),'results'=>$results,'rollback_id'=>$rid];
	}

	private function bulk_status(array $p,string $status,array $cx):array{
		$ids=(array)($p['ids']??[]);$results=[];$before=[];
		if(count($ids)>self::MAX_ITEMS)return$this->err('too_many_items',sprintf(__('Cannot process more than %d items in a single bulk operation.','wp-command-center'),self::MAX_ITEMS));
		foreach($ids as $id){$post=get_post((int)$id);if(!$post)continue;$before[$id]=['post_status'=>$post->post_status];wp_update_post(['ID'=>(int)$id,'post_status'=>$status]);$results[]=$id;}
		$rid=$before?$this->store_rollback("bulk_$status","bulk_$status",['ids'=>$ids,'fields'=>['post_status'],'before'=>$before],$cx):'';
		return['updated'=>count($results),'results'=>$results,'rollback_id'=>$rid];
	}

	private function bulk_media(array $p,array $cx):array{
		$ids=(array)($p['ids']??[]);$results=[];$before=[];$title=$p['title']??null;
		if(count($ids)>self::MAX_ITEMS)return$this->err('too_many_items',sprintf(__('Cannot process more than %d items in a single bulk operation.','wp-command-center'),self::MAX_ITEMS));
		foreach($ids as $id){
			$post=get_post((int)$id);if(!$post||'attachment'!==$post->post_type)continue;
			if(!empty($title)){$before[$id]=['post_title'=>$post->post_title];wp_update_post(['ID'=>(int)$id,'post_title'=>sanitize_text_field((string)$title)]);}
			$results[]=$id;
		}
		$rid=$before?$this->store_rollback('bulk_media','bulk_media',['ids'=>$ids,'fields'=>['post_title'],'before'=>$before],$cx):'';
		return['updated'=>count($results),'results'=>$results,'rollback_id'=>$rid];
	}

	private function bulk_woo(array $p,array $cx):array{
		if(!class_exists('WooCommerce'))return['updated'=>0,'results'=>[],'rollback_id'=>''];
		$ids=(array)($p['ids']??[]);$results=[];$before=[];$price=$p['regular_price']??null;$status=$p['status']??null;
		if(count($ids)>self::MAX_ITEMS)return$this->err('too_many_items',sprintf(__('Cannot process more than %d items in a single bulk operation.','wp-command-center'),self::MAX_ITEMS));
		$rfields=[];if($price!==null)$rfields[]='regular_price';if($status!==null)$rfields[]='status';
		foreach($ids as $id){
			$pr=wc_get_product((int)$id);if(!$pr)continue;
			$snap=[];
			if($price!==null){$snap['regular_price']=$pr->get_regular_price();$pr->set_regular_price((string)$price);}
			if($status!==null){$snap['status']=$pr->get_status();$pr->set_status(sanitize_key((string)$status));}
			$pr->save();if($snap)$before[$id]=$snap;$results[]=$id;
		}
		$rid=$before?$this->store_rollback('bulk_woocommerce','bulk_woocommerce',['ids'=>$ids,'fields'=>$rfields,'before'=>$before],$cx):'';
		return['updated'=>count($results),'results'=>$results,'rollback_id'=>$rid];
	}

	private function bulk_acf(array $p,array $cx):array{
		if(!function_exists('acf_get_field_groups')||!function_exists('update_field'))return['updated'=>0,'results'=>[],'rollback_id'=>''];
		$ids=(array)($p['post_ids']??[]);$field= sanitize_text_field((string)($p['field_key']??$p['field_name']??''));$value=$p['value']??null;$results=[];$before=[];
		if(''===$field)return$this->err('missing_field',__('Field key required.','wp-command-center'));
		if(count($ids)>self::MAX_ITEMS)return$this->err('too_many_items',sprintf(__('Cannot process more than %d items in a single bulk operation.','wp-command-center'),self::MAX_ITEMS));
		foreach($ids as $id){$before[$id]=['acf'=>get_field($field,(int)$id)];update_field($field,$value,(int)$id);$results[]=$id;}
		$rid=$before?$this->store_rollback('bulk_acf','bulk_acf',['ids'=>$ids,'fields'=>['acf'],'field_key'=>$field,'before'=>$before],$cx):'';
		return['updated'=>count($results),'results'=>$results,'rollback_id'=>$rid];
	}

	private function batch_execute(array $p,array $cx):array{
		$ops=(array)($p['operations']??[]);$results=[];$executor=new \WPCommandCenter\Operations\OperationExecutor();
		if(count($ops)>self::MAX_ITEMS)return$this->err('too_many_items',sprintf(__('Cannot process more than %d operations in a single batch.','wp-command-center'),self::MAX_ITEMS));
		foreach($ops as $op){$r=$executor->run((string)($op['operation_id']??''),(array)($op['payload']??[]),$cx);$results[]=['operation_id'=>$op['operation_id']??'','success'=>$r['success']??false];}
		return['executed'=>count($results),'results'=>$results];
	}

	private function store_rollback(string $id,string $action,array $before,array $cx):string{
		$rid=wp_generate_uuid4();
		$rb=get_option('wpcc_bulk_rollbacks',[]);
		$rb[]=['id'=>$rid,'entity_id'=>$id,'action'=>$action,'before_state'=>$before,'rollback_applied'=>false,'created_at'=>time(),'session_id'=>$cx['session_id']??null,'task_id'=>$cx['task_id']??null];
		if(count($rb)>200)$rb=array_slice($rb,-200);update_option('wpcc_bulk_rollbacks',$rb);
		return $rid;
	}

	public function rollback(array $p,array $cx=[]):array{
		$rid=(string)($p['rollback_id']??'');if(''===$rid)return$this->err('missing','Rollback ID required.');
		$rbs=get_option('wpcc_bulk_rollbacks',[]);$rec=null;$idx=null;
		foreach($rbs as $i=>$r){if(($r['id']??null)===$rid){$rec=$r;$idx=$i;break;}}
		if(!$rec)return$this->err('nf','Not found.');if(!empty($rec['rollback_applied']))return$this->err('done','Already applied.');

		$action=(string)($rec['action']??'');
		$bs=(array)($rec['before_state']??[]);
		$before_map=(array)($bs['before']??[]);
		$restored=0;$fields_set=[];

		// PROGRAM-4C.0a — action-dispatched restore; each branch restores ONLY the fields it
		// captured (no cross-field clobber). Legacy scalar before[id] is normalized to the
		// action's primary field. Dependency-gated actions (woo/acf) return a structured
		// reversible:false error WITHOUT marking applied, so they stay retryable.
		if(in_array($action,['bulk_content','bulk_publish','bulk_draft','bulk_media'],true)){
			foreach($before_map as $id=>$snap){
				$snap=$this->normalize_snap($snap,$action);
				if(!get_post((int)$id))continue;
				$data=['ID'=>(int)$id];
				foreach(['post_title','post_status','post_content'] as $f){if(array_key_exists($f,$snap)){$data[$f]=$snap[$f];$fields_set[$f]=true;}}
				if(count($data)>1){wp_update_post($data);$restored++;}
			}
		}elseif('bulk_woocommerce'===$action){
			if(!class_exists('WooCommerce'))return$this->unsupported(__('WooCommerce is not active; cannot reverse this bulk operation.','wp-command-center'));
			foreach($before_map as $id=>$snap){
				if(!is_array($snap))continue;$pr=wc_get_product((int)$id);if(!$pr)continue;
				if(array_key_exists('regular_price',$snap)){$pr->set_regular_price((string)$snap['regular_price']);$fields_set['regular_price']=true;}
				if(array_key_exists('status',$snap)){$pr->set_status((string)$snap['status']);$fields_set['status']=true;}
				$pr->save();$restored++;
			}
		}elseif('bulk_acf'===$action){
			if(!function_exists('update_field'))return$this->unsupported(__('ACF is not active; cannot reverse this bulk operation.','wp-command-center'));
			$fk=(string)($bs['field_key']??'');if(''===$fk)return$this->unsupported(__('Rollback record is missing its ACF field key.','wp-command-center'));
			foreach($before_map as $id=>$snap){$val=is_array($snap)?($snap['acf']??null):$snap;update_field($fk,$val,(int)$id);$fields_set['acf']=true;$restored++;}
		}else{
			return$this->unsupported(__('This bulk rollback record type cannot be reversed.','wp-command-center'));
		}

		$rbs[$idx]['rollback_applied']=true;update_option('wpcc_bulk_rollbacks',$rbs);
		$this->audit->record('bulk.rollback',['type'=>$action,'restored'=>$restored,'fields'=>array_keys($fields_set)]);
		return['action'=>'bulk_rollback','rollback_id'=>$rid,'type'=>$action,'restored'=>$restored,'fields'=>array_keys($fields_set),'reversible'=>true];
	}

	/** Map a legacy scalar before[id] to the action's primary field; arrays pass through. */
	private function normalize_snap($snap,string $action):array{
		if(is_array($snap))return $snap;
		if('bulk_publish'===$action||'bulk_draft'===$action)return['post_status'=>$snap];
		return['post_title'=>$snap];
	}

	private function unsupported(string $m):array{return['error'=>true,'code'=>'wpcc_bulk_rollback_unsupported','message'=>$m,'reversible'=>false];}

	private function err(string $c,string $m):array{return['error'=>true,'code'=>$c,'message'=>$m];}
}
