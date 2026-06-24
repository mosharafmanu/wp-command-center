<?php
namespace WPCommandCenter\Operations;

use WPCommandCenter\Security\AuditLog;
use WPCommandCenter\Rollback\RollbackDelta;
use WPCommandCenter\Rollback\PostMetaRollbackStore;
use WPCommandCenter\Rollback\ContentFieldAccessor;
use WPCommandCenter\Rollback\BulkWooAccessor;
use WPCommandCenter\Rollback\BulkAcfAccessor;

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
 * PROGRAM-4.8 — Bulk per-item, field-scoped, drift-aware rollback.
 *
 * Each mutated item gets its OWN v2 RollbackDelta record (capture touched fields → after
 * values → build_record) stored by {@see PostMetaRollbackStore} under `_wpcc_bulk_rb_{itemRid}`
 * on the item post. A batch is an indexed set of membership meta rows
 * `_wpcc_bulk_b_{batchId}` (post_id → itemRid) — the batch_id is encoded in the meta_key, so
 * the whole batch resolves in one indexed query with NO option, NO FIFO eviction, NO autoload,
 * GC'd with the posts. rollback() restores each item drift-aware (skip+report on drift, never
 * clobber a sibling/newer change), isolates per-item failures, and reports honest
 * complete/partial/conflict status. Closes the residual Bulk F-1 (G1 drift, G2 eviction,
 * G3 autoload, G4 isolation, G5 truthfulness, G6 out-of-order) on top of the P4C.0a hotfix.
 *
 * Backward-compatible: pre-P4.8 records still live in the `wpcc_bulk_rollbacks` option and are
 * resolved by the legacy path (legacy_rollback) when no batch membership exists; new ops never
 * write that option. No schema / registry / capability / MCP / REST / security change.
 *
 * All five mutating bulk entities are post-bound: posts (content/status), attachments (media),
 * WC products (woocommerce), and ACF values on posts (acf) — so every record is postmeta.
 */
final class BulkRuntimeManager {
	const MAX_ITEMS    = 200;
	const ITEM_PREFIX  = '_wpcc_bulk_rb_';
	const BATCH_PREFIX = '_wpcc_bulk_b_';

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

	// ── Per-item delta store + batch membership ──────────────────────────────

	private function item_store():PostMetaRollbackStore{ return new PostMetaRollbackStore(self::ITEM_PREFIX); }

	/**
	 * Persist one item's field-scoped delta record + its batch-membership row.
	 *
	 * @param string[]            $touched unified field names this item wrote
	 * @param array<string,mixed> $prior   RollbackDelta::capture() output
	 * @param array<string,mixed> $after   post-write per-field values
	 */
	private function persist_item(int $post_id,string $batch,string $action,string $accessor_type,array $touched,array $prior,array $after,array $cx,string $field_key=''):string{
		$rid=wp_generate_uuid4();
		$head=['id'=>$rid,'post_id'=>$post_id,'action'=>$action,'accessor'=>$accessor_type,'batch_id'=>$batch];
		if(''!==$field_key)$head['field_key']=$field_key;
		$record=RollbackDelta::build_record($touched,$prior,$after,$cx,$head);
		$this->item_store()->persist($post_id,$rid,$record);
		add_post_meta($post_id,self::BATCH_PREFIX.$batch,$rid,false);
		return $rid;
	}

	private function build_accessor(string $type,string $field_key){
		switch($type){
			case 'content': return new ContentFieldAccessor();
			case 'woo':     return (class_exists('WooCommerce')&&function_exists('wc_get_product'))?new BulkWooAccessor():null;
			case 'acf':     return (''!==$field_key&&function_exists('update_field')&&function_exists('get_field'))?new BulkAcfAccessor($field_key):null;
		}
		return null;
	}

	// ── Mutating operations (capture → write → after → persist, per item) ─────

	private function bulk_content(array $p,array $cx):array{
		$ids=(array)($p['ids']??[]);$fields=$p['fields']??[];$results=[];
		if(count($ids)>self::MAX_ITEMS)return$this->err('too_many_items',$this->cap_msg());
		$acc=new ContentFieldAccessor();$batch=wp_generate_uuid4();$items=0;
		foreach($ids as $id){
			$id=(int)$id;$post=get_post($id);if(!$post)continue;
			$touched=[];$u=['ID'=>$id];
			if(isset($fields['post_title'])){$touched[]='title';$u['post_title']=sanitize_text_field($fields['post_title']);}
			if(isset($fields['post_content'])){$touched[]='content';$u['post_content']=$fields['post_content'];}
			if(!$touched)continue;
			$prior=RollbackDelta::capture($acc,$id,$touched);
			wp_update_post($u);
			$after=$this->after($acc,$id,$touched);
			$this->persist_item($id,$batch,'bulk_content','content',$touched,$prior,$after,$cx);
			$results[]=$id;$items++;
		}
		return['updated'=>count($results),'results'=>$results,'rollback_id'=>$items?$batch:''];
	}

	private function bulk_status(array $p,string $status,array $cx):array{
		$ids=(array)($p['ids']??[]);$results=[];
		if(count($ids)>self::MAX_ITEMS)return$this->err('too_many_items',$this->cap_msg());
		$acc=new ContentFieldAccessor();$batch=wp_generate_uuid4();$items=0;$action="bulk_$status"; // bulk_publish | bulk_draft
		foreach($ids as $id){
			$id=(int)$id;$post=get_post($id);if(!$post)continue;
			$touched=['status'];
			$prior=RollbackDelta::capture($acc,$id,$touched);
			wp_update_post(['ID'=>$id,'post_status'=>$status]);
			$after=$this->after($acc,$id,$touched);
			$this->persist_item($id,$batch,$action,'content',$touched,$prior,$after,$cx);
			$results[]=$id;$items++;
		}
		return['updated'=>count($results),'results'=>$results,'rollback_id'=>$items?$batch:''];
	}

	private function bulk_media(array $p,array $cx):array{
		$ids=(array)($p['ids']??[]);$results=[];$title=$p['title']??null;
		if(count($ids)>self::MAX_ITEMS)return$this->err('too_many_items',$this->cap_msg());
		$acc=new ContentFieldAccessor();$batch=wp_generate_uuid4();$items=0;
		foreach($ids as $id){
			$id=(int)$id;$post=get_post($id);if(!$post||'attachment'!==$post->post_type)continue;
			if(!empty($title)){
				$touched=['title'];
				$prior=RollbackDelta::capture($acc,$id,$touched);
				wp_update_post(['ID'=>$id,'post_title'=>sanitize_text_field((string)$title)]);
				$after=$this->after($acc,$id,$touched);
				$this->persist_item($id,$batch,'bulk_media','content',$touched,$prior,$after,$cx);
				$items++;
			}
			$results[]=$id;
		}
		return['updated'=>count($results),'results'=>$results,'rollback_id'=>$items?$batch:''];
	}

	private function bulk_woo(array $p,array $cx):array{
		if(!class_exists('WooCommerce'))return['updated'=>0,'results'=>[],'rollback_id'=>''];
		$ids=(array)($p['ids']??[]);$results=[];$price=$p['regular_price']??null;$status=$p['status']??null;
		if(count($ids)>self::MAX_ITEMS)return$this->err('too_many_items',$this->cap_msg());
		$acc=new BulkWooAccessor();$batch=wp_generate_uuid4();$items=0;
		foreach($ids as $id){
			$id=(int)$id;$pr=wc_get_product($id);if(!$pr)continue;
			$touched=[];if($price!==null)$touched[]='regular_price';if($status!==null)$touched[]='status';
			if(!$touched){$results[]=$id;continue;}
			$prior=RollbackDelta::capture($acc,$id,$touched);
			if($price!==null)$pr->set_regular_price((string)$price);
			if($status!==null)$pr->set_status(sanitize_key((string)$status));
			$pr->save();
			$after=$this->after($acc,$id,$touched);
			$this->persist_item($id,$batch,'bulk_woocommerce','woo',$touched,$prior,$after,$cx);
			$results[]=$id;$items++;
		}
		return['updated'=>count($results),'results'=>$results,'rollback_id'=>$items?$batch:''];
	}

	private function bulk_acf(array $p,array $cx):array{
		if(!function_exists('acf_get_field_groups')||!function_exists('update_field'))return['updated'=>0,'results'=>[],'rollback_id'=>''];
		$ids=(array)($p['post_ids']??[]);$field=sanitize_text_field((string)($p['field_key']??$p['field_name']??''));$value=$p['value']??null;$results=[];
		if(''===$field)return$this->err('missing_field',__('Field key required.','wp-command-center'));
		if(count($ids)>self::MAX_ITEMS)return$this->err('too_many_items',$this->cap_msg());
		$acc=new BulkAcfAccessor($field);$batch=wp_generate_uuid4();$items=0;
		foreach($ids as $id){
			$id=(int)$id;$touched=['value'];
			$prior=RollbackDelta::capture($acc,$id,$touched);
			update_field($field,$value,$id);
			$after=$this->after($acc,$id,$touched);
			$this->persist_item($id,$batch,'bulk_acf','acf',$touched,$prior,$after,$cx,$field);
			$results[]=$id;$items++;
		}
		return['updated'=>count($results),'results'=>$results,'rollback_id'=>$items?$batch:''];
	}

	private function batch_execute(array $p,array $cx):array{
		$ops=(array)($p['operations']??[]);$results=[];$executor=new \WPCommandCenter\Operations\OperationExecutor();
		if(count($ops)>self::MAX_ITEMS)return$this->err('too_many_items',sprintf(__('Cannot process more than %d operations in a single batch.','wp-command-center'),self::MAX_ITEMS));
		foreach($ops as $op){$r=$executor->run((string)($op['operation_id']??''),(array)($op['payload']??[]),$cx);$results[]=['operation_id'=>$op['operation_id']??'','success'=>$r['success']??false];}
		return['executed'=>count($results),'results'=>$results];
	}

	/** @param string[] $touched @return array<string,mixed> */
	private function after($acc,int $id,array $touched):array{
		$after=[];foreach($touched as $f)$after[$f]=$acc->read_field($id,$f);return $after;
	}

	// ── Rollback ──────────────────────────────────────────────────────────────

	public function rollback(array $p,array $cx=[]):array{
		$rid=(string)($p['rollback_id']??'');if(''===$rid)return$this->err('missing','Rollback ID required.');

		// PROGRAM-4.8 — per-item batch path: resolve membership by the (indexed) batch meta_key.
		global $wpdb;
		$members=$wpdb->get_results($wpdb->prepare("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",self::BATCH_PREFIX.$rid));
		if($members){return $this->rollback_batch($rid,$members);}

		// Backward-compat: pre-P4.8 records in the wpcc_bulk_rollbacks option (P4C.0a path).
		return $this->legacy_rollback($rid);
	}

	/**
	 * Restore a batch item-by-item, drift-aware, with per-item isolation + honest aggregate.
	 *
	 * @param array<int,object> $members rows of {post_id, meta_value=itemRid}
	 */
	private function rollback_batch(string $batch,array $members):array{
		$store=$this->item_store();
		$total=0;$restored=0;$skipped=0;$missing=0;$already=0;$errored=0;$per=[];$type='';$fields=[];$seen=[];
		foreach($members as $m){
			$itemRid=(string)$m->meta_value;$post_id=(int)$m->post_id;
			if(isset($seen[$itemRid]))continue;$seen[$itemRid]=1;$total++;
			$res=$store->resolve($itemRid);
			if(null===$res){$missing++;$per[]=['post_id'=>$post_id,'rid'=>$itemRid,'status'=>'missing'];continue;}
			$rec=$res['record'];$post_id=(int)($res['entity_id']??$post_id);
			$type=''!==$type?$type:(string)($rec['action']??'');
			if(!empty($rec['rollback_applied'])){$already++;$per[]=['post_id'=>$post_id,'rid'=>$itemRid,'status'=>'already'];continue;}
			$acc=$this->build_accessor((string)($rec['accessor']??''),(string)($rec['field_key']??''));
			if(null===$acc){
				// Dependency inactive (WC/ACF) or unknown type — the batch cannot be reversed now;
				// nothing is marked applied, so it stays retryable (mirrors the hotfix gate).
				return $this->unsupported($this->dep_message((string)($rec['accessor']??'')));
			}
			try{$o=RollbackDelta::restore($acc,$post_id,(array)($rec['fields']??[]));}
			catch(\Throwable $e){$errored++;$per[]=['post_id'=>$post_id,'rid'=>$itemRid,'status'=>'error'];continue;}
			foreach((array)($o['restored']??[]) as $f)$fields[$f]=true;
			if('complete'===($o['status']??'')){
				$rec['rollback_applied']=true;$rec['applied_at']=time();
				$store->mark_applied($post_id,$itemRid,$rec);$restored++;
			}else{
				$skipped+=count((array)($o['skipped']??[]));
			}
			$per[]=['post_id'=>$post_id,'rid'=>$itemRid,'status'=>$o['status']??'','restored'=>$o['restored']??[],'skipped'=>$o['skipped']??[]];
		}

		// Fully already-applied batch → idempotent repeat (preserves hotfix B8 semantics).
		if($total>0&&0===$restored&&0===$skipped&&0===$missing&&0===$errored&&$already===$total){
			return$this->err('done','Already applied.');
		}

		$status=(0===$skipped&&0===$missing&&0===$errored)?'complete':($restored>0?'partial':'conflict');
		$this->audit->record('bulk.rollback',['type'=>$type,'items'=>$total,'restored'=>$restored,'skipped'=>$skipped,'missing'=>$missing,'already'=>$already,'errored'=>$errored,'status'=>$status]);
		$env=['action'=>'bulk_rollback','rollback_id'=>$batch,'type'=>$type,'items'=>$total,'restored'=>$restored,'skipped'=>$skipped,'missing'=>$missing,'already'=>$already,'errored'=>$errored,'status'=>$status,'fields'=>array_keys($fields),'per_item'=>$per];
		// Honest aggregate: a non-complete batch is an error envelope (so the executor's success
		// boolean is truthful) — matching RollbackDelta::result(). Restored items stay applied;
		// skipped/missing/errored items are not marked applied, so the batch stays retryable.
		if('complete'===$status){ $env['reversible']=true; return $env; }
		$env['error']=true;
		$env['code']=('conflict'===$status)?'wpcc_rollback_conflict':'wpcc_rollback_partial';
		$env['reversible']=false;
		return $env;
	}

	private function dep_message(string $type):string{
		if('woo'===$type)return __('WooCommerce is not active; cannot reverse this bulk operation.','wp-command-center');
		if('acf'===$type)return __('ACF is not active; cannot reverse this bulk operation.','wp-command-center');
		return __('This bulk rollback record type cannot be reversed.','wp-command-center');
	}

	// ── Legacy (P4C.0a option-record) rollback — unchanged behavior ───────────

	private function legacy_rollback(string $rid):array{
		$rbs=get_option('wpcc_bulk_rollbacks',[]);$rec=null;$idx=null;
		foreach($rbs as $i=>$r){if(($r['id']??null)===$rid){$rec=$r;$idx=$i;break;}}
		if(!$rec)return$this->err('nf','Not found.');if(!empty($rec['rollback_applied']))return$this->err('done','Already applied.');

		$action=(string)($rec['action']??'');
		$bs=(array)($rec['before_state']??[]);
		$before_map=(array)($bs['before']??[]);
		$restored=0;$fields_set=[];

		if(in_array($action,['bulk_content','bulk_publish','bulk_draft','bulk_media'],true)){
			foreach($before_map as $id=>$snap){
				$snap=$this->normalize_snap($snap,$action);
				if(!get_post((int)$id))continue;
				$data=['ID'=>(int)$id];
				foreach(['post_title','post_status','post_content'] as $f){if(array_key_exists($f,$snap)){$data[$f]=$snap[$f];$fields_set[$f]=true;}}
				if(count($data)>1){wp_update_post($data);$restored++;}
			}
		}elseif('bulk_woocommerce'===$action){
			if(!class_exists('WooCommerce'))return$this->unsupported($this->dep_message('woo'));
			foreach($before_map as $id=>$snap){
				if(!is_array($snap))continue;$pr=wc_get_product((int)$id);if(!$pr)continue;
				if(array_key_exists('regular_price',$snap)){$pr->set_regular_price((string)$snap['regular_price']);$fields_set['regular_price']=true;}
				if(array_key_exists('status',$snap)){$pr->set_status((string)$snap['status']);$fields_set['status']=true;}
				$pr->save();$restored++;
			}
		}elseif('bulk_acf'===$action){
			if(!function_exists('update_field'))return$this->unsupported($this->dep_message('acf'));
			$fk=(string)($bs['field_key']??'');if(''===$fk)return$this->unsupported(__('Rollback record is missing its ACF field key.','wp-command-center'));
			foreach($before_map as $id=>$snap){$val=is_array($snap)?($snap['acf']??null):$snap;update_field($fk,$val,(int)$id);$fields_set['acf']=true;$restored++;}
		}else{
			return$this->unsupported($this->dep_message(''));
		}

		$rbs[$idx]['rollback_applied']=true;update_option('wpcc_bulk_rollbacks',$rbs);
		$this->audit->record('bulk.rollback',['type'=>$action,'restored'=>$restored,'fields'=>array_keys($fields_set),'path'=>'legacy']);
		return['action'=>'bulk_rollback','rollback_id'=>$rid,'type'=>$action,'restored'=>$restored,'fields'=>array_keys($fields_set),'reversible'=>true,'path'=>'legacy'];
	}

	/** Map a legacy scalar before[id] to the action's primary field; arrays pass through. */
	private function normalize_snap($snap,string $action):array{
		if(is_array($snap))return $snap;
		if('bulk_publish'===$action||'bulk_draft'===$action)return['post_status'=>$snap];
		return['post_title'=>$snap];
	}

	private function cap_msg():string{ return sprintf(__('Cannot process more than %d items in a single bulk operation.','wp-command-center'),self::MAX_ITEMS); }
	private function unsupported(string $m):array{return['error'=>true,'code'=>'wpcc_bulk_rollback_unsupported','message'=>$m,'reversible'=>false];}
	private function err(string $c,string $m):array{return['error'=>true,'code'=>$c,'message'=>$m];}
}
