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
		$results=[];$completed=[];$status='completed';$rolled_back=null;
		$total=count($steps);
		foreach($steps as $i=>$step){
			$op=(string)($step['operation_id']??'');
			$payload=(array)($step['payload']??[]);
			$s_start=microtime(true);
			$r=$executor->run($op,$payload,$cx);
			$s_end=microtime(true);
			$ok=(($r['success']??false)===true)&&empty($r['result']['error']);
			$rb=$r['result']['rollback_id']??null;
			$rec=['step'=>$i+1,'operation_id'=>$op,'success'=>$ok,
				'started_at'=>(int)$s_start,'finished_at'=>(int)$s_end,'duration_ms'=>(int)(($s_end-$s_start)*1000),
				'rollback_id'=>$rb,'rollbackable'=>null!==$rb];
			if(!$ok)$rec['error']=$r['errors'][0]??['code'=>$r['result']['code']??'error','message'=>$r['result']['message']??''];
			$results[]=$rec;
			if($ok){if(null!==$rb)$completed[]=['operation_id'=>$op,'rollback_id'=>$rb];continue;}
			// Step failed — apply the failure policy.
			$status='failed';
			if('continue'===$on_failure)continue;
			if('rollback'===$on_failure){$rolled_back=$this->rollback_steps($completed,$executor,$cx);$status='rolled_back';}
			for($j=$i+1;$j<$total;$j++)$results[]=['step'=>$j+1,'operation_id'=>(string)($steps[$j]['operation_id']??''),'success'=>false,'skipped'=>true];
			break;
		}
		$this->record_execution($id,$exec_id,$status,$started,$results,$on_failure,$rolled_back);
		$executed=count(array_filter($results,fn($r)=>empty($r['skipped'])));
		$out=['workflow_id'=>$id,'execution_id'=>$exec_id,'status'=>$status,'steps_executed'=>$executed,'results'=>$results];
		if(null!==$rolled_back)$out['rolled_back']=$rolled_back;
		return $out;
	}

	/** Reverse the given completed steps (latest first) via the unified rollback dispatcher. */
	private function rollback_steps(array $completed,OperationExecutor $executor,array $cx):array{
		$out=[];
		foreach(array_reverse($completed) as $c){
			$r=$executor->rollback((string)$c['operation_id'],['rollback_id'=>$c['rollback_id']],$cx);
			$out[]=['operation_id'=>$c['operation_id'],'rollback_id'=>$c['rollback_id'],'success'=>($r['success']??false)===true];
		}
		return $out;
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
		foreach(($history[$idx]['results']??[]) as $r){if(!empty($r['success'])&&!empty($r['rollback_id']))$completed[]=['operation_id'=>$r['operation_id'],'rollback_id'=>$r['rollback_id']];}
		if(!$completed)return$this->err('nothing_to_rollback',__('No rollbackable steps in this execution.','wp-command-center'));
		$rbres=$this->rollback_steps($completed,new OperationExecutor(),$cx);
		$history[$idx]['rolled_back']=$rbres;$history[$idx]['status']='rolled_back';
		update_option('wpcc_workflow_history',$history);
		return['execution_id'=>$exec_id,'rolled_back'=>$rbres,'steps_rolled_back'=>count($rbres)];
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
