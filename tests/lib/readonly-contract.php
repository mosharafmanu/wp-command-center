<?php
ini_set("zend.exception_ignore_args", "1");
// Integration test: real hashed tokens, HTTP-equivalent REST dispatch, executor,
// and database effects. Run only on a disposable WordPress test installation.
use WPCommandCenter\Security\AuthTokens;
use WPCommandCenter\Operations\CapabilityRegistry as Caps;
use WPCommandCenter\Operations\OperationRegistry;
use WPCommandCenter\Operations\OperationExecutor;
$GLOBALS['pass']=0; $GLOBALS['fail']=0;
function ro_check($ok,$label) { global $pass,$fail; if($ok){$pass++;echo "  PASS: $label\n";}else{$fail++;echo "  FAIL: $label\n";} }
$auth=new AuthTokens(); $caps=new Caps(); $tokens=[]; $proposal_id="";
$mode='client'; $enforce='1';
add_filter('pre_option_wpcc_security_mode',static function()use(&$mode){return $mode;});
add_filter('pre_option_wpcc_enforce_capabilities',static function()use(&$enforce){return $enforce;});
function ro_rpc($raw,$method,$params=[]) {
 $req=new WP_REST_Request('POST','/wp-command-center/v1/mcp');
 $req->set_header('Authorization','Bearer '.$raw); $req->set_header('Content-Type','application/json');
 $req->set_body(wp_json_encode(['jsonrpc'=>'2.0','id'=>1,'method'=>$method,'params'=>$params]));
 return rest_do_request($req)->get_data();
}
function ro_call($raw,$op,$args=[]) {
 $r=ro_rpc($raw,'tools/call',['name'=>$op,'arguments'=>$args]);
 $out=json_decode($r['result']['content'][0]['text']??'{}',true)??[]; $out['_ok']=isset($r['result']['content']) && empty($r['result']['isError']) && empty($r['error']); return $out;
}
function ro_snapshot() {
 global $wpdb;
 $out=[];
 foreach([$wpdb->posts,$wpdb->postmeta,$wpdb->users,$wpdb->usermeta,$wpdb->terms,$wpdb->term_taxonomy,$wpdb->term_relationships,$wpdb->comments,$wpdb->commentmeta] as $t){$out[$t]=hash('sha256',serialize($wpdb->get_results("SELECT * FROM `$t`",ARRAY_A)));}
 foreach($wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}wpcc_%'") as $t){
  if(preg_match('/(request|queue|change_log|patch|snapshot|rollback)/',$t)) $out[$t]=hash('sha256',serialize($wpdb->get_results("SELECT * FROM `$t`",ARRAY_A)));
 }
 $options=$wpdb->get_results("SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name NOT LIKE '%transient%' ORDER BY option_name",ARRAY_A);
 // Usage/audit bookkeeping is permitted; stored credentials are hashed only.
 $options=array_values(array_filter($options,static fn($r)=>!preg_match('/wpcc_(audit|auth_tokens|api_tokens|operation_results)/',$r['option_name'])));
 $out['options']=hash('sha256',serialize($options)); return $out;
}
try {
 $ro=$auth->create('DEF read-only regression',AuthTokens::SCOPE_READ_ONLY,null,1);$tokens[]=$ro['record']['id'];
 $full=$auth->create('DEF full regression',AuthTokens::SCOPE_FULL,null,1);$tokens[]=$full['record']['id'];
 $raw=$ro['token']; $id=$ro['record']['id'];
 ro_check(($auth->validate($raw)['scope']??'')==='read_only','valid read-only token authenticates');
 ro_check(!str_contains(wp_json_encode($auth->list()),$raw),'token store never retains raw token');
 ro_check(isset(ro_rpc($raw,'initialize',['protocolVersion'=>'2024-11-05','capabilities'=>[],'clientInfo'=>['name'=>'readonly-regression','version'=>'1']])['result']['serverInfo']),'authenticated initialize succeeds');
 $tools=ro_rpc($raw,'tools/list')['result']['tools']??[];
 ro_check(count($tools)===42,'native MCP catalogue contains 42 operations');
 ro_check(count(ro_rpc($raw,'resources/list')['result']['resources']??[])===7,'seven resources exposed');
 foreach(['wpcc://manifest','wpcc://context','wpcc://capabilities','wpcc://operations','wpcc://queue','wpcc://results','wpcc://recommendations'] as $uri) ro_check(isset(ro_rpc($raw,'resources/read',['uri'=>$uri])['result']['contents']),"resource readable: $uri");
 $reads=[['system_info',[]],['report_manage',['action'=>'report_site_health']],['settings_manage',['action'=>'settings_general_get']],['option_manage',['action'=>'option_get','option_id'=>'site_title']],['content_manage',['action'=>'content_list']],['media_manage',['action'=>'media_list']],['plugin_manage',['action'=>'plugin_list']],['theme_manage',['action'=>'theme_list']],['user_manage',['action'=>'user_list']],['approval_manage',['action'=>'request_list']],['approval_manage',['action'=>'queue_list']],['change_history',['action'=>'history_list']],['rollback_manage',['action'=>'rollback_list']],['snapshot_manage',['action'=>'snapshot_list']],['database_inspect',['action'=>'db_table_list']],['capability_manage',['action'=>'capability_list']],['file_manage',['action'=>'file_tree']],['code_search',['action'=>'search_text','query'=>'namespace','path'=>'plugins/ai-command-center/includes/Operations/CapabilityRegistry.php']],['woocommerce_manage',['action'=>'woo_describe']]];
 foreach(['client','enterprise','developer'] as $mode){
  foreach($reads as [$op,$args]) { $r=ro_call($raw,$op,$args);ro_check(!empty($r['_ok']),"$mode actual read succeeds: $op/".($args['action']??'')); }
 }
 $operations=array_column((new OperationRegistry())->get_operations(),null,'id');
 ro_check(array_keys($operations)===array_keys(Caps::READ_ONLY_ACTIONS),'read policy explicitly accounts for every registered operation');
 // Independent catalogue risk classification catches omitted read actions;
 // special cases are audited explicitly rather than granting a whole tool.
 foreach($operations as $op=>$def){
  foreach($def['action_risks']??[] as $action=>$risk){
   if($risk==='diagnostic' && $op!=='approval_manage') ro_check(!$caps->requires_full_scope($op,['action'=>$action]),"catalogue diagnostic admitted: $op/$action");
  }
 }
 // Every audited read reaches a handler (or its domain validation); optional
 // plugins/IDs may be unavailable, but scope/capability/approval must not reject it.
 $read_before=ro_snapshot();
 foreach(Caps::READ_ONLY_ACTIONS as $op=>$actions)foreach($actions as $action){
  $read_args=$action===''?[]:['action'=>$action];
  if($op==='media_enhance')$read_args['limit']=1; // one real item is sufficient for authorization/effect coverage
  $r=ro_call($raw,$op,$read_args);
  ro_check(!in_array($r['code']??'',['wpcc_token_read_only','wpcc_capability_denied','wpcc_tool_exception'],true) && empty($r['requires_approval']),"audited read reaches domain handler: $op/$action");
 }
 ro_check($read_before===ro_snapshot(),'all audited reads including failed lookups leave site state and change history unchanged');
 $before=ro_snapshot();
 // Independent security requirements: these expected denials do not derive
 // from the implementation allowlist, so accidentally adding a write cannot
 // make its regression assertion disappear.
 $must_deny=[
  'content_manage'=>['content_create','content_update','content_delete'],
  'settings_manage'=>['settings_general_update'],'option_manage'=>['option_update'],
  'media_manage'=>['media_update','media_delete'],'plugin_manage'=>['plugin_activate','plugin_update'],
  'theme_manage'=>['theme_activate','theme_update'],'user_manage'=>['user_create','user_update','user_delete'],
  'woocommerce_manage'=>['product_create','product_update','order_update'],
  'capability_manage'=>['capability_assign','capability_remove'],
  'approval_manage'=>['request_create','request_approve','request_reject','queue_run','queue_retry'],
  'rollback_manage'=>['rollback_apply'],'change_history'=>['rollback_target'],
  'patch_manage'=>['patch_create','patch_apply'],'snapshot_manage'=>['snapshot_restore','snapshot_create'],
  'cache_manage'=>['cache_purge_all','cache_purge_url'],'workflow_manage'=>['workflow_execute'],
  'bulk_manage'=>['bulk_publish'],'wp_cli_bridge'=>[''],
  'content_seed'=>[''],'acf_seed'=>[''],'cf7_seed'=>[''],'woo_product_seed'=>[''],
  'safe_search_replace'=>[''],'safe_updates'=>[''],'media_import'=>['']
 ];
 foreach(['client','enterprise','developer'] as $mode)foreach(['1','0'] as $enforce){
  foreach($must_deny as $op=>$actions)foreach($actions as $action){
   $r=ro_call($raw,$op,['action'=>$action,'dry_run'=>true,'confirm'=>true]);
   ro_check(($r['code']??'')==='wpcc_token_read_only',"independent mutation boundary $mode caps=$enforce: $op/$action");
  }

  foreach($operations as $op=>$def){
   $actions=array_keys($def['action_risks']??[]);
   foreach($def['parameters']??[] as $param) if(($param['name']??'')==='action')$actions=array_merge($actions,$param['enum']??[]);
   $actions=array_unique(array_merge($actions,['','unknown_action']));
   foreach($actions as $action){
    if(in_array($action,Caps::READ_ONLY_ACTIONS[$op],true))continue;
    $args=['action'=>$action,'dry_run'=>true,'confirm'=>true,'reason'=>'Read-only denial regression'];
    $r=ro_call($raw,$op,$args);
    ro_check(($r['code']??'')==='wpcc_token_read_only',"$mode caps=$enforce scope denial: $op/$action");
   }
  }
 }
 ro_check($before===ro_snapshot(),'denials leave content, users, options, approvals, queues, patches, snapshots and change history unchanged');
 $enforce='1';$mode='client';
 foreach([['settings_manage','settings_general_get','settings_general_update'],['option_manage','option_get','option_update'],['content_manage','content_list','content_create'],['media_manage','media_list','media_update']] as [$op,$read,$write])foreach([$read,$write] as $action){
  $req=new WP_REST_Request('POST',"/wp-command-center/v1/operations/$op/run");$req->set_header('Authorization','Bearer '.$raw);$req->set_param('action',$action);$req->set_param('option_id','site_title');
  $r=rest_do_request($req); ro_check($action===$read ? $r->get_status()!==403 : $r->get_status()===403,"REST action boundary: $op/$action");
 }
 $spoof=(new OperationExecutor())->run('option_manage',['action'=>'option_update','option_id'=>'site_title','value'=>'MUST NOT WRITE'],['actor'=>['type'=>'token','id'=>$id],'token_scope'=>'full']);
 ro_check(($spoof['errors'][0]['code']??'')==='wpcc_token_read_only','executor uses stored scope despite forged full context');
 $assign=$caps->get_assignments();$assign['token:'.$id]=[Caps::CAP_DATABASE_INSPECT];$caps->save_assignments($assign);
 ro_check((ro_call($raw,'system_info')['code']??'')==='wpcc_capability_denied','custom capability restriction enforced for site reads');
 ro_check($caps->get_for_subject('token',$id)===[Caps::CAP_DATABASE_INSPECT],'custom nonempty assignments are preserved');
 $assign['token:'.$id]=[Caps::CAP_DATABASE_INSPECT,Caps::CAP_SEARCH_MANAGE,Caps::CAP_HISTORY_READ];$caps->save_assignments($assign);
 ro_check(!empty(ro_call($raw,'system_info')['_ok']),'existing default read-only token upgrades to read profile');
 ro_check(in_array(Caps::CAP_SITE_READ,$caps->get_for_subject('token',$id),true),'migration grants only site.read');
 ro_check(($caps->validate('option_manage','token',$id,['action'=>'option_update'],'read_only')['allowed']??true)===false,'read capabilities cannot authorize management');
 ro_check(!empty(ro_call($full['token'],'system_info')['_ok']),'full-access read behavior unchanged');
 // Deliberate full-access proposal on the isolated test site. No execution.
 $r=ro_call($full['token'],'content_manage',['action'=>'content_create','post_type'=>'post','title'=>'DEF approval only','status'=>'draft','reason'=>'Regression: proposal must wait for human approval']);
 $proposal_id=$r['request_id']??'';
 ro_check((bool)$proposal_id,'full-access proposal exposes an ID for exact cleanup');
 ro_check(!empty($r['requires_approval']) || ($r['status']??'')==='pending_approval' || str_contains(wp_json_encode($r),'pending_approval'),'full-access governed write still awaits approval');
 $r=ro_call($raw,'approval_manage',['action'=>'request_create','operation_id'=>'content_seed','payload'=>['type'=>'post']]);
 ro_check(($r['code']??'')==='wpcc_token_read_only','read-only cannot submit a mutation proposal');
 $r=ro_call($raw,'option_manage',['action'=>'option_get','option_id'=>'wpcc_api_tokens']);
 ro_check(!str_contains(wp_json_encode($r),$raw),'option read cannot expose raw token');
 $r=ro_call($raw,'file_manage',['action'=>'file_read','path'=>'../wp-config.php']);
 ro_check(empty($r['_ok']),'file path secret guard remains active');
} finally {
 global $wpdb;
 if($proposal_id)$wpdb->delete($wpdb->prefix.'wpcc_operation_requests',['request_id'=>$proposal_id]);
 foreach($tokens as $tid)$auth->delete($tid);
}
ro_check(!$proposal_id || !$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}wpcc_operation_requests WHERE request_id=%s",$proposal_id)),'temporary full-access approval fixture removed');
echo "Read-only contract: {$GLOBALS['pass']} passed, {$GLOBALS['fail']} failed\n";
exit($GLOBALS['fail']?1:0);
