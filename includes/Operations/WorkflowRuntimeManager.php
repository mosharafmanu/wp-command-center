<?php
namespace WPCommandCenter\Operations;

use WPCommandCenter\Security\AuditLog;

defined( 'ABSPATH' ) || exit;

final class WorkflowRegistry {
	const RISK_LOW='low';const RISK_HIGH='high';
	const A_LIST='workflow_list';const A_GET='workflow_get';const A_CREATE='workflow_create';
	const A_UPDATE='workflow_update';const A_DELETE='workflow_delete';const A_EXECUTE='workflow_execute';
	const A_IMPORT='workflow_import';const A_EXPORT='workflow_export';const A_HISTORY='workflow_history';
	const A_ROLLBACK='workflow_rollback';
	const ACTIONS=[self::A_LIST,self::A_GET,self::A_CREATE,self::A_UPDATE,self::A_DELETE,self::A_EXECUTE,self::A_IMPORT,self::A_EXPORT,self::A_HISTORY,self::A_ROLLBACK];
	public static function requires_approval(string $a):bool{return in_array($a,[self::A_CREATE,self::A_DELETE,self::A_EXECUTE,self::A_IMPORT,self::A_ROLLBACK]);}
	public static function get_risk(string $a):string{return in_array($a,[self::A_LIST,self::A_GET,self::A_HISTORY])?self::RISK_LOW:self::RISK_HIGH;}
}

final class WorkflowRuntimeManager {
	const ON_FAILURE=['stop','continue','rollback'];
	private AuditLog $audit;
	public function __construct(){ $this->audit=new AuditLog(); }

	public function run(array $p,array $cx=[]):array{
		$a=(string)($p['action']??'');
		if(!in_array($a,WorkflowRegistry::ACTIONS,true))return $this->err('invalid',__('Invalid workflow action.','wp-command-center'));
		$result=match($a){
			WorkflowRegistry::A_LIST=>$this->list_workflows(),
			WorkflowRegistry::A_GET=>$this->get_workflow($p),
			WorkflowRegistry::A_CREATE=>$this->create($p,$cx),
			WorkflowRegistry::A_UPDATE=>$this->update($p,$cx),
			WorkflowRegistry::A_DELETE=>$this->delete($p,$cx),
			WorkflowRegistry::A_EXECUTE=>$this->execute($p,$cx),
			WorkflowRegistry::A_IMPORT=>$this->import($p,$cx),
			WorkflowRegistry::A_EXPORT=>$this->export($p),
			WorkflowRegistry::A_HISTORY=>$this->history($p),
			WorkflowRegistry::A_ROLLBACK=>$this->rollback_execution($p,$cx),
			default=>$this->err('unknown','Unknown.')
		};
		$this->audit->record(str_replace('_','.',$a),$result);
		return array_merge(['action'=>$a],$result);
	}

	private function load():array{return get_option('wpcc_workflows',[]);}
	private function save(array $w):void{update_option('wpcc_workflows',$w);}

	private function list_workflows():array{
		$w=$this->load();$items=[];
		foreach($w as $k=>$v)$items[]=['id'=>$k,'name'=>$v['name']??'','description'=>$v['description']??'','step_count'=>count($v['steps']??[]),'created'=>$v['created_at']??0];
		return['workflows'=>$items,'total'=>count($items)];
	}

	private function get_workflow(array $p):array{
		$id=sanitize_key((string)($p['workflow_id']??''));$w=$this->load();
		return isset($w[$id])?['workflow'=>$w[$id]]:$this->err('nf',__('Workflow not found.','wp-command-center'));
	}

	private function create(array $p,array $cx):array{
		$name=sanitize_text_field((string)($p['name']??''));if(''===$name)return$this->err('missing_name',__('Name required.','wp-command-center'));
		$steps=(array)($p['steps']??[]);$id=sanitize_key($p['workflow_id']??$name);$id=sanitize_title($id).'_'.time();
		$w=$this->load();$w[$id]=['id'=>$id,'name'=>$name,'description'=>sanitize_text_field((string)($p['description']??'')),'steps'=>$steps,'created_at'=>time(),'updated_at'=>time()];
		$this->save($w);return['workflow_id'=>$id,'name'=>$name,'step_count'=>count($steps)];
	}

	private function update(array $p,array $cx):array{
		$id=sanitize_key((string)($p['workflow_id']??''));$w=$this->load();
		if(!isset($w[$id]))return$this->err('nf',__('Not found.','wp-command-center'));
		if(isset($p['name']))$w[$id]['name']=sanitize_text_field((string)$p['name']);
		if(isset($p['steps']))$w[$id]['steps']=(array)$p['steps'];
		$w[$id]['updated_at']=time();
		$this->save($w);return['workflow_id'=>$id,'updated'=>true];
	}

	private function delete(array $p,array $cx):array{
		$id=sanitize_key((string)($p['workflow_id']??''));$w=$this->load();
		if(!isset($w[$id]))return$this->err('nf',__('Not found.','wp-command-center'));
		$name=$w[$id]['name'];unset($w[$id]);$this->save($w);
		return['workflow_id'=>$id,'name'=>$name,'deleted'=>true];
	}

	/**
	 * STEP 97 — Execute a multi-step plan as one unit. Each step records an
	 * execution-timeline entry (start/finish/duration) and captures any
	 * rollback_id the sub-operation returns (rollback awareness). Steps run with
	 * `within_workflow` set so they inherit this workflow's single approval. The
	 * `on_failure` policy controls recovery: stop (default), continue, or rollback
	 * (auto-reverse all completed steps in reverse order).
	 */
	private function execute(array $p,array $cx):array{
		$id=sanitize_key((string)($p['workflow_id']??''));$w=$this->load();
		if(!isset($w[$id]))return$this->err('nf',__('Not found.','wp-command-center'));
		$steps=array_values((array)($w[$id]['steps']??[]));
		$on_failure=in_array(($p['on_failure']??'stop'),self::ON_FAILURE,true)?(string)$p['on_failure']:'stop';
		$cx['within_workflow']=true; // single approval: plan approved as a unit
		$executor=new OperationExecutor();
		$exec_id=wp_generate_uuid4();
		$started=microtime(true);
		$results=[];$completed=[];$status='completed';$rolled_back=null;$step_outputs=[];
		$total=count($steps);
		foreach($steps as $i=>$step){
			$op=(string)($step['operation_id']??'');
			// F6.2 — resolve inter-step references ({{steps.N.result.x}}, {{steps.N.created.0}}, ...)
			// in this step's payload against earlier steps' outputs before executing.
			$unresolved=[];
			$payload=$this->resolve_refs((array)($step['payload']??[]),$step_outputs,$unresolved);
			$s_start=microtime(true);
			if(!empty($unresolved)){
				$msg=__('Unresolved step reference(s): ','wp-command-center').implode(', ',$unresolved);
				$r=['success'=>false,'result'=>['error'=>true,'code'=>'wpcc_unresolved_reference','message'=>$msg],
					'errors'=>[['code'=>'wpcc_unresolved_reference','message'=>$msg]],'created'=>[]];
			}else{
				$r=$executor->run($op,$payload,$cx);
			}
			$s_end=microtime(true);
			$step_outputs[$i]=is_array($r)?$r:[];
			$ok=(($r['success']??false)===true)&&empty($r['result']['error']);
			$rb=$r['result']['rollback_id']??null;
			// F6.1 — capture the resources the step created. Many create operations
			// (e.g. content_create) return no rollback_id, so without this the step
			// was treated as non-reversible and on_failure:rollback left orphans.
			$created=array_values(array_unique(array_map('intval',(array)($r['created']??[]))));
			$rec=['step'=>$i+1,'operation_id'=>$op,'success'=>$ok,
				'started_at'=>(int)$s_start,'finished_at'=>(int)$s_end,'duration_ms'=>(int)(($s_end-$s_start)*1000),
				'rollback_id'=>$rb,'created'=>$created,'rollbackable'=>(null!==$rb)||!empty($created)];
			if(!$ok)$rec['error']=$r['errors'][0]??['code'=>$r['result']['code']??'error','message'=>$r['result']['message']??''];
			$results[]=$rec;
			if($ok){if((null!==$rb)||!empty($created))$completed[]=['operation_id'=>$op,'rollback_id'=>$rb,'created'=>$created];continue;}
			// Step failed — apply the failure policy.
			$status='failed';
			if('continue'===$on_failure)continue;
			if('rollback'===$on_failure){
				$rolled_back=$this->rollback_steps($completed,$executor,$cx);
				// Honest status: only 'rolled_back' when every reversal verified.
				$status=$this->all_verified($rolled_back)?'rolled_back':'rollback_incomplete';
			}
			for($j=$i+1;$j<$total;$j++)$results[]=['step'=>$j+1,'operation_id'=>(string)($steps[$j]['operation_id']??''),'success'=>false,'skipped'=>true];
			break;
		}
		$this->record_execution($id,$exec_id,$status,$started,$results,$on_failure,$rolled_back);
		$executed=count(array_filter($results,fn($r)=>empty($r['skipped'])));
		$out=['workflow_id'=>$id,'execution_id'=>$exec_id,'status'=>$status,'steps_executed'=>$executed,'results'=>$results];
		if(null!==$rolled_back)$out['rolled_back']=$rolled_back;
		return $out;
	}

	/**
	 * Reverse the given completed steps (latest first), then VERIFY the reversal.
	 * Two reversal paths: (1) the unified rollback dispatcher when the step's
	 * operation returned a rollback_id; (2) deletion of resources the step created
	 * (covers create operations that expose no rollback_id, e.g. content_create).
	 * Each reversed step is verified — created resources must no longer exist.
	 */
	private function rollback_steps(array $completed,OperationExecutor $executor,array $cx):array{
		$out=[];
		foreach(array_reverse($completed) as $c){
			$op=(string)($c['operation_id']??'');
			$rbid=$c['rollback_id']??null;
			$created=array_values(array_unique(array_map('intval',(array)($c['created']??[]))));
			$dispatched=null;
			if(null!==$rbid&&''!==$rbid){
				$r=$executor->rollback($op,['rollback_id'=>$rbid],$cx);
				$dispatched=($r['success']??false)===true;
			}
			// Remove any still-present created resources (post-type entities).
			foreach($created as $cid){ if($cid>0&&get_post($cid)){ wp_delete_post($cid,true); } }
			// Verify: no created resource may remain.
			$still=0; foreach($created as $cid){ if($cid>0&&get_post($cid)) $still++; }
			$verified=(0===$still);
			// Success: created-steps succeed iff verified gone; rollback_id-only
			// steps succeed iff the dispatcher reported success.
			$success=!empty($created)?$verified:(true===$dispatched);
			$out[]=['operation_id'=>$op,'rollback_id'=>$rbid,'created'=>$created,'dispatched'=>$dispatched,'verified'=>$verified,'success'=>$success];
		}
		return $out;
	}

	/** True when every reversed step verified (or there was nothing to reverse). */
	private function all_verified(array $rolled_back):bool{
		foreach($rolled_back as $r){ if(empty($r['success'])) return false; }
		return true;
	}

	/**
	 * F6.2 — Recursively resolve {{ steps.N.path }} references in a step payload
	 * against the outputs of earlier steps. A placeholder that is the WHOLE value
	 * is replaced preserving the resolved value's type (so an int post ID stays an
	 * int); a placeholder embedded in a larger string is interpolated. References
	 * that cannot be resolved are collected in $unresolved (the step then fails
	 * with wpcc_unresolved_reference rather than silently passing a literal).
	 */
	private function resolve_refs($value,array $outputs,array &$unresolved){
		if(is_array($value)){
			$out=[];
			foreach($value as $k=>$v){ $out[$k]=$this->resolve_refs($v,$outputs,$unresolved); }
			return $out;
		}
		if(!is_string($value)||false===strpos($value,'{{')) return $value;
		if(preg_match('/^\s*\{\{\s*(.+?)\s*\}\}\s*$/',$value,$m)){
			$found=false;$res=$this->lookup_ref($m[1],$outputs,$found);
			if(!$found){ $unresolved[]=trim($m[1]); return $value; }
			return $res;
		}
		return preg_replace_callback('/\{\{\s*(.+?)\s*\}\}/',function($mm) use($outputs,&$unresolved){
			$found=false;$res=$this->lookup_ref($mm[1],$outputs,$found);
			if(!$found){ $unresolved[]=trim($mm[1]); return $mm[0]; }
			return is_scalar($res)?(string)$res:wp_json_encode($res);
		},$value);
	}

	/** Look up a `steps.<index>.<dot.path>` reference in prior step outputs. */
	private function lookup_ref(string $ref,array $outputs,bool &$found){
		$found=false;
		$parts=array_values(array_filter(explode('.',trim($ref)),static fn($s)=>'' !== $s));
		if(count($parts)<2||'steps'!==$parts[0]||!ctype_digit($parts[1])) return null;
		$idx=(int)$parts[1];
		if(!array_key_exists($idx,$outputs)) return null;
		$cur=$outputs[$idx];
		for($i=2;$i<count($parts);$i++){
			$key=$parts[$i];
			if(is_array($cur)&&array_key_exists($key,$cur)) $cur=$cur[$key];
			elseif(is_array($cur)&&ctype_digit($key)&&array_key_exists((int)$key,$cur)) $cur=$cur[(int)$key];
			else return null;
		}
		$found=true;
		return $cur;
	}

	private function record_execution(string $wid,string $exec_id,string $status,float $started,array $results,string $on_failure,?array $rolled_back):void{
		$finished=microtime(true);
		$rec=['execution_id'=>$exec_id,'workflow_id'=>$wid,'status'=>$status,'on_failure'=>$on_failure,
			'started_at'=>(int)$started,'finished_at'=>(int)$finished,'duration_ms'=>(int)(($finished-$started)*1000),
			'results'=>$results,'rolled_back'=>$rolled_back,'executed_at'=>time()];
		$history=get_option('wpcc_workflow_history',[]);$history[]=$rec;
		if(count($history)>200)$history=array_slice($history,-200);
		update_option('wpcc_workflow_history',$history);
	}

	/** STEP 97 — Roll back a past execution by execution_id (reverse every successful, rollbackable step). */
	private function rollback_execution(array $p,array $cx):array{
		$exec_id=sanitize_text_field((string)($p['execution_id']??''));
		if(''===$exec_id)return$this->err('missing_execution_id',__('execution_id required.','wp-command-center'));
		$history=get_option('wpcc_workflow_history',[]);$idx=null;
		foreach($history as $i=>$h){if(($h['execution_id']??'')===$exec_id){$idx=$i;break;}}
		if(null===$idx)return$this->err('execution_not_found',__('Execution not found.','wp-command-center'));
		if(!empty($history[$idx]['rolled_back']))return$this->err('already_rolled_back',__('Execution already rolled back.','wp-command-center'));
		$completed=[];
		foreach(($history[$idx]['results']??[]) as $r){
			if(empty($r['success']))continue;
			if(!empty($r['rollback_id'])||!empty($r['created'])){
				$completed[]=['operation_id'=>$r['operation_id']??'','rollback_id'=>$r['rollback_id']??null,'created'=>$r['created']??[]];
			}
		}
		if(!$completed)return$this->err('nothing_to_rollback',__('No rollbackable steps in this execution.','wp-command-center'));
		$rbres=$this->rollback_steps($completed,new OperationExecutor(),$cx);
		$verified=$this->all_verified($rbres);
		$history[$idx]['rolled_back']=$rbres;$history[$idx]['status']=$verified?'rolled_back':'rollback_incomplete';
		update_option('wpcc_workflow_history',$history);
		return['execution_id'=>$exec_id,'rolled_back'=>$rbres,'steps_rolled_back'=>count($rbres),'verified'=>$verified];
	}

	private function import(array $p,array $cx):array{
		$json=(string)($p['json']??'');if(''===$json)return$this->err('missing',__('JSON required.','wp-command-center'));
		$data=json_decode($json,true);if(!$data||!isset($data['name']))return$this->err('invalid_json',__('Invalid workflow JSON.','wp-command-center'));
		$id=sanitize_title($data['name']).'_'.time();$w=$this->load();$w[$id]=['id'=>$id,'name'=>$data['name'],'description'=>$data['description']??'','steps'=>$data['steps']??[],'created_at'=>time(),'updated_at'=>time()];$this->save($w);
		return['workflow_id'=>$id,'name'=>$data['name'],'imported'=>true];
	}

	private function export(array $p):array{
		$id=sanitize_key((string)($p['workflow_id']??''));$w=$this->load();
		if(!isset($w[$id]))return$this->err('nf',__('Not found.','wp-command-center'));
		return['workflow_id'=>$id,'json'=>wp_json_encode($w[$id],JSON_PRETTY_PRINT)];
	}

	private function history(array $p):array{
		$h=get_option('wpcc_workflow_history',[]);
		if(isset($p['workflow_id'])){$wid=sanitize_key((string)$p['workflow_id']);$h=array_values(array_filter($h,fn($e)=>($e['workflow_id']??'')===$wid));}
		return['history'=>array_slice(array_reverse($h),0,50),'total'=>count($h)];
	}

	private function err(string $c,string $m):array{return['error'=>true,'code'=>$c,'message'=>$m];}
}
