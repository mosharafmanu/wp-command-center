<?php
namespace WPCommandCenter\Operations;

use WPCommandCenter\Security\AuditLog;

defined( 'ABSPATH' ) || exit;

final class WorkflowRegistry {
	const RISK_LOW='low';const RISK_HIGH='high';
	const A_LIST='workflow_list';const A_GET='workflow_get';const A_CREATE='workflow_create';
	const A_UPDATE='workflow_update';const A_DELETE='workflow_delete';const A_EXECUTE='workflow_execute';
	const A_IMPORT='workflow_import';const A_EXPORT='workflow_export';const A_HISTORY='workflow_history';
	const ACTIONS=[self::A_LIST,self::A_GET,self::A_CREATE,self::A_UPDATE,self::A_DELETE,self::A_EXECUTE,self::A_IMPORT,self::A_EXPORT,self::A_HISTORY];
	public static function requires_approval(string $a):bool{return in_array($a,[self::A_CREATE,self::A_DELETE,self::A_EXECUTE,self::A_IMPORT]);}
	public static function get_risk(string $a):string{return in_array($a,[self::A_LIST,self::A_GET,self::A_HISTORY])?self::RISK_LOW:self::RISK_HIGH;}
}

final class WorkflowRuntimeManager {
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
		$this->save($w);return['workflow_id'=>$id,'updated'=>true];
	}

	private function delete(array $p,array $cx):array{
		$id=sanitize_key((string)($p['workflow_id']??''));$w=$this->load();
		if(!isset($w[$id]))return$this->err('nf',__('Not found.','wp-command-center'));
		$name=$w[$id]['name'];unset($w[$id]);$this->save($w);
		return['workflow_id'=>$id,'name'=>$name,'deleted'=>true];
	}

	private function execute(array $p,array $cx):array{
		$id=sanitize_key((string)($p['workflow_id']??''));$w=$this->load();
		if(!isset($w[$id]))return$this->err('nf',__('Not found.','wp-command-center'));
		$steps=$w[$id]['steps']??[];$results=[];$executor=new OperationExecutor();
		foreach($steps as $i=>$step){
			$r=$executor->run((string)($step['operation_id']??''),(array)($step['payload']??[]),$cx);
			$results[]=['step'=>$i+1,'operation'=>$step['operation_id']??'','success'=>$r['success']??false];
		}
		$history=get_option('wpcc_workflow_history',[]);$history[]=['workflow_id'=>$id,'executed_at'=>time(),'results'=>$results];update_option('wpcc_workflow_history',$history);
		return['workflow_id'=>$id,'steps_executed'=>count($results),'results'=>$results];
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
		return['history'=>array_slice(array_reverse($h),0,50),'total'=>count($h)];
	}

	private function err(string $c,string $m):array{return['error'=>true,'code'=>$c,'message'=>$m];}
}
