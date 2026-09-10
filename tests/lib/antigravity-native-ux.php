<?php
ini_set('zend.exception_ignore_args','1');
use WPCommandCenter\Integration\AIClientRegistry as R;
use WPCommandCenter\Security\AuthTokens;
$GLOBALS['agy_pass']=0;
function agy_check($ok,$label){if(!$ok)throw new RuntimeException('FAIL: '.$label);$GLOBALS['agy_pass']++;echo "  PASS: $label\n";}
$root=$args[0];wp_set_current_user(1);$wpcc_new_record=null;
try{
 $_GET=['client'=>'antigravity','tab'=>'configuration'];
 $_POST=['wpcc_token_action'=>'create','_wpnonce'=>wp_create_nonce('wpcc_ai_integrations'),'wpcc_token_label'=>'Antigravity UX temporary','wpcc_token_scope'=>'read_only','wpcc_token_expires'=>'30d'];$_REQUEST=$_POST;
 ob_start();require $root.'/includes/Admin/views/ai-integrations.php';$html=ob_get_clean();
 agy_check(!empty($wpcc_new_token),'normal form creates read-only token');
 $dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);$xp=new DOMXPath($dom);$text=html_entity_decode(strip_tags($html),ENT_QUOTES,'UTF-8');
 $c=R::get_client('antigravity');agy_check($c['name']==='Antigravity CLI'&&$c['type']==='cli','client name and classification identify agy CLI');
 $cmd=R::setup_command_for('antigravity',$wpcc_new_token);
 agy_check(str_starts_with($cmd,'agy mcp add --header '),'native add primary command with flags first');
 agy_check(str_contains($cmd,escapeshellarg('Authorization: Bearer '.$wpcc_new_token)),'native header carries exact one-time credential');
 agy_check(str_replace(R::TOKEN_PLACEHOLDER,$wpcc_new_token,$xp->query('//*[@id="wpcc-setup-command"]')->item(0)->textContent)===$cmd,'guided setup renders executable native command');
 agy_check($xp->query('//*[@id="wpcc-token-next"]')->item(0)->getAttribute('href')==='#wpcc-guided-setup','token-created CTA leads to native path');
 $config=json_decode(R::render_config(R::generate_config('antigravity'),$wpcc_new_token),true);
 $server=$config['mcpServers']['wp-command-center']??[];
 agy_check(isset($server['serverUrl'])&&!isset($server['url'])&&!isset($server['command']),'JSON fallback preserves native serverUrl HTTP contract');
 agy_check(($server['headers']['Authorization']??'')==='Bearer '.$wpcc_new_token,'fallback header matches native credential');
	 foreach(['umask 077','chmod 600','shared history, screenshots, chats and logs','Do not commit or share','Revoke the token','Exit any running agy','/mcp','registration only','endpoint, not whether agy connected','alongside anything else'] as $needle)agy_check(str_contains($text,$needle),'guided safety and verification: '.$needle);
 agy_check(!preg_match('/no native command|no MCP management command/i',$text),'stale native-command denial absent');
 agy_check(R::get_client('gemini')['name']==='Gemini CLI'&&str_starts_with(R::setup_command_for('gemini'),'gemini mcp add'),'Gemini remains independent command surface');
 agy_check(R::get_client('gemini')['config_paths']['macos']==='~/.gemini/settings.json','Gemini keeps own config path');
 agy_check(R::get_client('windsurf')===null,'removed Windsurf not resurrected');
 agy_check(!str_contains(wp_json_encode((new AuthTokens())->list()),$wpcc_new_token),'no plaintext token in stored records');
 $_POST=[];$_REQUEST=[];ob_start();require $root.'/includes/Admin/views/ai-integrations.php';$reload=ob_get_clean();
 agy_check(!str_contains($reload,$server['headers']['Authorization']),'refresh never reveals raw credential again');
}finally{if(!empty($wpcc_new_record['id']))(new AuthTokens())->delete($wpcc_new_record['id']);}
echo "Antigravity native UX: {$GLOBALS['agy_pass']} passed, 0 failed\n";
