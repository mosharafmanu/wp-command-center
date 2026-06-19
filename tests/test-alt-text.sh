#!/usr/bin/env bash
#
# STEP 110 — Phase 2 Task 7A: AI Alt Text read-only scan acceptance suite.
#
# Asserts the scan audits the Media Library (missing/weak/ok), paginates, detects
# open proposals, supports opt-in usage, gates on manage_options + FeatureGate,
# and is STRICTLY READ-ONLY (no writes, no outbound HTTP, no proposal creation,
# no engine interaction). Invariants unchanged.
#
# Requires: wp-cli.

set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_ROOT="$(cd "$PLUGIN_DIR/../../.." && pwd)"
cd "$PLUGIN_DIR"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq()     { local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }
assert_absent() { local d="$1" h="$2" n="$3"; printf '%s' "$h" | grep -qF -- "$n" && fail "$d (found '$n')" || pass "$d"; }
wpe() { wp --path="$WP_ROOT" eval "$1" 2>/dev/null; }

echo "STEP 110 Task 7A — AI Alt Text read-only scan"

# ── Dynamic battery (dispatched through the REST server) ─────────────────────
BATT="$(mktemp /tmp/wpcc-altscan-XXXXXX.php)"
cat > "$BATT" <<'PHP'
<?php
use WPCommandCenter\Proposals\ProposalStore as PStore;
$a=get_users(['role'=>'administrator','number'=>1]); $uid=$a?$a[0]->ID:1;
$NS='/wp-command-center/v1/admin/alt-text/scan';
$out=[]; $emit=function($d,$ok,$x='')use(&$out){ $out[]=$d."\t".($ok?'PASS':'FAIL')."\t".$x; };
global $wpdb;
$mk=function($title,$alt)use($wpdb){ $id=wp_insert_attachment(['post_title'=>$title,'post_mime_type'=>'image/jpeg','post_status'=>'inherit'],false); update_post_meta($id,'_wp_attached_file','2026/06/'.$title.'.jpg'); if($alt!==null)update_post_meta($id,'_wp_attachment_image_alt',$alt); return $id; };
$call=function($params)use($NS){ $q=new WP_REST_Request('GET',$NS); foreach($params as $k=>$v)$q->set_param($k,$v); $x=rest_get_server()->dispatch($q); return [$x->get_status(),$x->get_data()]; };

// 1. route exists
$routes=rest_get_server()->get_routes();
$emit('scan route registered', isset($routes['/wp-command-center/v1/admin/alt-text/scan']));

// 2. auth/gate (no user -> 401)
wp_set_current_user(0); [$st0]=$call(['limit'=>1]); $emit('denied without auth (401)', 401===$st0, (string)$st0);
wp_set_current_user($uid);

// seed missing / weak / weak-by-filename / ok
$miss=$mk('alt7a-missing',null); $weak=$mk('alt7a-weak','img'); $weakfn=$mk('alt7a-weakfn','alt7a-weakfn'); $ok=$mk('alt7a-ok','A golden retriever puppy sitting on green grass');

// read-only snapshot BEFORE
$snap=function()use($wpdb){ return [
  (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta}"),
  (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}"),
  (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options}"),
  (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpcc_proposals"),
  (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpcc_change_log"),
  (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpcc_operation_requests"),
]; };
$before=$snap(); $alt_before=get_post_meta($miss,'_wp_attachment_image_alt',true);

// 3. classification
[$s,$d]=$call(['state'=>'all','limit'=>200]); $emit('scan returns 200 + envelope', 200===$s && ($d['action']??'')==='alt_text_scan');
$by=[]; foreach($d['items'] as $it){ if(strpos($it['title'],'alt7a-')===0)$by[$it['title']]=$it['state']; }
$emit('classify missing', ($by['alt7a-missing']??'')==='missing');
$emit('classify weak (short)', ($by['alt7a-weak']??'')==='weak');
$emit('classify weak (equals filename)', ($by['alt7a-weakfn']??'')==='weak');
$emit('classify ok', ($by['alt7a-ok']??'')==='ok');
$emit('summary has required keys', isset($d['summary']['total_images'],$d['summary']['missing'],$d['summary']['weak'],$d['summary']['described'],$d['summary']['described_pct']));

// 4. pagination
[$sp,$dp]=$call(['limit'=>2,'offset'=>0]);
$emit('pagination returns limit-bounded page', 2===$dp['returned'] && 2===$dp['limit']);
$emit('pagination exposes has_more + next_cursor', array_key_exists('has_more',$dp) && array_key_exists('next_cursor',$dp));

// 5. has_open_proposal detection
$store=new PStore();
$p=$store->create(['operation_id'=>'media_manage','action'=>'media_update','target_type'=>'attachment','target_id'=>(string)$ok,'payload'=>['action'=>'media_update','media_id'=>$ok,'alt'=>'x']]);
[$sh,$dh]=$call(['state'=>'all','limit'=>200]);
$flag=null; foreach($dh['items'] as $it){ if($it['attachment_id']===$ok)$flag=$it['has_open_proposal']; }
$emit('has_open_proposal=true when draft exists', $flag===true, var_export($flag,true));
$store->dismiss($p['proposal_id']);
[$sh2,$dh2]=$call(['state'=>'all','limit'=>200]); $flag2=null; foreach($dh2['items'] as $it){ if($it['attachment_id']===$ok)$flag2=$it['has_open_proposal']; }
$emit('has_open_proposal=false after dismiss', $flag2===false, var_export($flag2,true));

// 6. with_usage off by default
$io=null; foreach($d['items'] as $it){ if($it['attachment_id']===$ok)$io=$it; }
$emit('with_usage OFF by default (no used_in key)', $io!==null && !array_key_exists('used_in',$io));

// 7. with_usage optional enrichment
[$su,$du]=$call(['with_usage'=>'1','limit'=>200]); $iu=null; foreach($du['items'] as $it){ if($it['attachment_id']===$ok)$iu=$it; }
$emit('with_usage ON adds used_in', $iu!==null && array_key_exists('used_in',$iu) && isset($iu['used_in']['count']));

// 8 + 9. read-only proof (no DB writes from scanning; no outbound). Several scans ran above.
$after=$snap();
$emit('READ-ONLY: postmeta unchanged', $before[0]===$after[0], $before[0].'->'.$after[0]);
$emit('READ-ONLY: posts unchanged', $before[1]===$after[1]);
$emit('READ-ONLY: options unchanged', $before[2]===$after[2]);
$emit('READ-ONLY: wpcc_change_log unchanged', $before[4]===$after[4]);
$emit('READ-ONLY: wpcc_operation_requests unchanged', $before[5]===$after[5]);
$emit('READ-ONLY: scanned attachment alt unchanged', get_post_meta($miss,'_wp_attachment_image_alt',true)===$alt_before);

// cleanup (proposals for $ok already dismissed; remove rows + attachments)
$wpdb->query("DELETE FROM {$wpdb->prefix}wpcc_proposals WHERE target_id IN ('".(int)$ok."')");
foreach([$miss,$weak,$weakfn,$ok] as $id) wp_delete_attachment($id,true);
echo implode("\n",$out);
PHP
RESULTS="$(wp --path="$WP_ROOT" eval-file "$BATT" 2>/dev/null)"
rm -f "$BATT"
while IFS=$'\t' read -r DESC STATUS DETAIL; do
  [ -z "$DESC" ] && continue
  if [ "$STATUS" = "PASS" ]; then pass "$DESC"; else fail "$DESC ($DETAIL)"; fi
done <<< "$RESULTS"

# ── Static architecture protection ──────────────────────────────────────────
# Comment-stripped CODE written to a temp FILE so greps run against a file (no
# pipe → no early-exit SIGPIPE under `set -o pipefail`); docblock mentions of the
# forbidden terms are excluded.
SCAN_TMP="$(mktemp /tmp/wpcc-scancode-XXXXXX)"
grep -vE '^[[:space:]]*(\*|/\*|//)' includes/AltText/AltTextScanQuery.php > "$SCAN_TMP"
absent() { local d="$1" n="$2"; grep -qF -- "$n" "$SCAN_TMP" && fail "$d (found '$n')" || pass "$d"; }
present() { grep -qF -- "$1" "$SCAN_TMP" && echo yes || echo no; }

# No writes of any kind.
for w in "->insert(" "->update(" "->delete(" "update_post_meta" "wp_update_post" "wp_insert_post" "update_option" "->query("; do
  absent "scan code has no write: $w" "$w"
done
# No outbound HTTP, no engine/provider coupling, no proposal creation.
for f in "wp_remote_post" "wp_remote_get" "wp_remote_request" "OperationExecutor" "ProposalApplyService" "AltTextProvider" "ProviderResolver" "ProposalStore::create" "->create("; do
  absent "scan code has no forbidden call: $f" "$f"
done
# Uses the read collaborators it should.
assert_eq "scan uses MediaUsageResolver (opt-in usage)" "yes" "$(present 'MediaUsageResolver')"
assert_eq "scan checks proposals via ProposalStore read API" "yes" "$(present '->count(')"
# REST handler delegates to the query.
assert_eq "REST scan handler delegates to AltTextScanQuery" "yes" "$(grep -q 'AltTextScanQuery' includes/Admin/AdminRestApi.php && echo yes || echo no)"
rm -f "$SCAN_TMP"

# ── 10. Invariants ───────────────────────────────────────────────────────────
assert_eq "invariant: OPERATION_MAP == 34" "34" "$(wpe 'echo count(\WPCommandCenter\Operations\CapabilityRegistry::OPERATION_MAP);')"
assert_eq "invariant: capabilities == 23"  "23" "$(wpe 'echo count(\WPCommandCenter\Operations\CapabilityRegistry::ALL_CAPABILITIES);')"
assert_eq "invariant: catalogue == 40"     "40" "$(wpe 'echo count((new \WPCommandCenter\Operations\OperationRegistry())->get_operations());')"
assert_eq "invariant: DB_VERSION 2.5.0"    "2.5.0" "$(wpe 'echo \WPCommandCenter\Core\Schema::DB_VERSION;')"

# ─────────────────────────────────────────────────────────────────────────────
# Task 7B — Provider abstraction (interface / result / Anthropic / resolver)
# ─────────────────────────────────────────────────────────────────────────────
PBATT="$(mktemp /tmp/wpcc-prov-batt-XXXXXX.php)"
cat > "$PBATT" <<'PHP'
<?php
use WPCommandCenter\AltText\ProviderResult;
use WPCommandCenter\AltText\AnthropicVisionProvider;
use WPCommandCenter\AltText\ProviderResolver;
$out=[]; $emit=function($d,$ok,$x='')use(&$out){ $out[]=$d."\t".($ok?'PASS':'FAIL')."\t".$x; };
global $wpdb;

// 1. interface exists
$emit('AltTextProvider interface exists', interface_exists('WPCommandCenter\\AltText\\AltTextProvider'));

// 2. ProviderResult ok/error
$ok=ProviderResult::ok('a cat','anthropic','claude-sonnet-4-6',0.9);
$er=ProviderResult::error('boom','went wrong','anthropic','m');
$emit('ProviderResult ok carries text+provenance', $ok->is_ok() && $ok->text()==='a cat' && $ok->provider()==='anthropic' && $ok->confidence()===0.9);
$emit('ProviderResult error carries code+message', !$er->is_ok() && $er->get_error()['code']==='boom' && null===$er->to_array()['confidence']);

// 3. resolver null when no key
delete_option('wpcc_alt_text_api_key');
$emit('resolver active() null when no key', (new ProviderResolver())->active()===null);
$emit('resolver has_active() false when no key', (new ProviderResolver())->has_active()===false);

// 4. resolver returns Anthropic when key exists
update_option('wpcc_alt_text_api_key','sk-ant-test-DUMMYKEY000000');
$a=(new ProviderResolver())->active();
$emit('resolver returns anthropic provider with key', $a!==null && $a->id()==='anthropic' && $a->is_configured());
$emit('resolver available() lists anthropic', in_array('anthropic',(new ProviderResolver())->available(),true));

// 5. resolver makes NO outbound HTTP during resolution
$GLOBALS['wpcc_http_calls']=0;
$probe=function($pre,$args,$url){ $GLOBALS['wpcc_http_calls']++; return new WP_Error('blocked','blocked'); };
add_filter('pre_http_request',$probe,10,3);
(new ProviderResolver())->active(); (new ProviderResolver())->has_active();
$emit('resolver makes no outbound HTTP', 0===$GLOBALS['wpcc_http_calls'], (string)$GLOBALS['wpcc_http_calls']);
remove_filter('pre_http_request',$probe,10);

// 6. provider not_configured returns ProviderResult (no key)
delete_option('wpcc_alt_text_api_key');
$p=new AnthropicVisionProvider();
$emit('provider not_configured -> error result', $p->suggest_alt(['attachment_id'=>1,'path'=>'/x.jpg','mime'=>'image/jpeg'])->get_error()['code']==='not_configured');

// 8. size guard
update_option('wpcc_alt_text_api_key','sk-ant-test-DUMMYKEY000000');
$big=tempnam(sys_get_temp_dir(),'big'); file_put_contents($big,str_repeat('x',6*1024*1024));
$rg=$p->suggest_alt(['attachment_id'=>2,'path'=>$big,'mime'=>'image/jpeg']);
$emit('size guard rejects oversized image', $rg->get_error()['code']==='image_too_large');
unlink($big);

// 9 + 10 + 11. mock the API to return a 401 whose body leaks a key.
$opt_before=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options}");
$post_before=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}");
$small=tempnam(sys_get_temp_dir(),'img'); file_put_contents($small,'mocked-bytes');
$mock=function($pre,$args,$url){ return [ 'response'=>['code'=>401], 'body'=>wp_json_encode(['error'=>['message'=>'invalid key sk-ant-api03-LEAKEDSECRET99999 here']]) ]; };
add_filter('pre_http_request',$mock,10,3);
$rerr=$p->suggest_alt(['attachment_id'=>3,'path'=>$small,'mime'=>'image/jpeg']);
remove_filter('pre_http_request',$mock,10);
$json=wp_json_encode($rerr->to_array());
$emit('api error returned as result (api_error_*)', strpos($rerr->get_error()['code'],'api_error_')===0, $rerr->get_error()['code']);
$emit('API error message is redacted (no leaked key)', strpos($json,'LEAKEDSECRET')===false);
$emit('API key never present in result', strpos($json,'sk-ant-test')===false && strpos($json,'DUMMYKEY')===false);
$opt_after=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options}");
$post_after=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}");
$emit('provider performs no WP mutation (options/posts unchanged)', $opt_before===$opt_after && $post_before===$post_after);
unlink($small);

// 7. timeout set (capture the args passed to wp_remote_post)
$GLOBALS['wpcc_timeout']=-1;
$cap=function($pre,$args,$url){ $GLOBALS['wpcc_timeout']=(int)($args['timeout']??-1); return new WP_Error('stop','stop'); };
add_filter('pre_http_request',$cap,10,3);
$small2=tempnam(sys_get_temp_dir(),'img2'); file_put_contents($small2,'x');
$p->suggest_alt(['attachment_id'=>4,'path'=>$small2,'mime'=>'image/jpeg']);
remove_filter('pre_http_request',$cap,10); unlink($small2);
$emit('outbound request sets a hard timeout', $GLOBALS['wpcc_timeout']>0, (string)$GLOBALS['wpcc_timeout']);

delete_option('wpcc_alt_text_api_key');
echo implode("\n",$out);
PHP
PRES="$(wp --path="$WP_ROOT" eval-file "$PBATT" 2>/dev/null)"
rm -f "$PBATT"
while IFS=$'\t' read -r DESC STATUS DETAIL; do
  [ -z "$DESC" ] && continue
  if [ "$STATUS" = "PASS" ]; then pass "$DESC"; else fail "$DESC ($DETAIL)"; fi
done <<< "$PRES"

# ── Static: provider-layer boundaries ────────────────────────────────────────
ALT_TMP="$(mktemp /tmp/wpcc-altcode-XXXXXX)"
# 6/7: Slice 2a extracted the transport — wp_remote_* now lives ONLY in the shared
# AI transport (AnthropicClient), not in any alt-text provider-layer file.
WP_REMOTE_FILES=""
for f in includes/AltText/AltTextProvider.php includes/AltText/ProviderResult.php includes/AltText/AnthropicVisionProvider.php includes/AltText/ProviderResolver.php includes/Ai/AnthropicClient.php; do
  grep -vE '^[[:space:]]*(\*|/\*|//)' "$f" > "$ALT_TMP"
  if grep -qE 'wp_remote_(post|get|request)' "$ALT_TMP"; then WP_REMOTE_FILES="${WP_REMOTE_FILES}$(basename "$f") "; fi
done
assert_eq "wp_remote_* only in the shared AnthropicClient transport" "AnthropicClient.php " "$WP_REMOTE_FILES"
grep -vE '^[[:space:]]*(\*|/\*|//)' includes/Ai/AnthropicClient.php > "$ALT_TMP"
assert_eq "shared transport sets a timeout" "yes" "$(grep -q "'timeout'" "$ALT_TMP" && echo yes || echo no)"

# 11/12: NO provider-layer file mutates WP or touches the engine/store.
for f in includes/AltText/AltTextProvider.php includes/AltText/ProviderResult.php includes/AltText/AnthropicVisionProvider.php includes/AltText/ProviderResolver.php; do
  grep -vE '^[[:space:]]*(\*|/\*|//)' "$f" > "$ALT_TMP"
  bn="$(basename "$f")"
  for forbidden in "update_post_meta" "wp_update_post" "wp_insert_post" "update_option" "->insert(" "->update(" "->delete(" "ProposalStore" "ProposalApplyService" "OperationExecutor"; do
    grep -qF -- "$forbidden" "$ALT_TMP" && fail "$bn must not reference $forbidden" || pass "$bn has no $forbidden"
  done
done
rm -f "$ALT_TMP"

# ─────────────────────────────────────────────────────────────────────────────
# Task 7C — AltTextGenerator (provider suggestion → ProposalStore draft)
# ─────────────────────────────────────────────────────────────────────────────
GBATT="$(mktemp /tmp/wpcc-gen-batt-XXXXXX.php)"
cat > "$GBATT" <<'PHP'
<?php
use WPCommandCenter\AltText\AltTextGenerator;
use WPCommandCenter\Proposals\ProposalStore;
use WPCommandCenter\Proposals\ProposalApplyService;
$a=get_users(['role'=>'administrator','number'=>1]); $uid=$a?$a[0]->ID:1; wp_set_current_user($uid);
$NS='/wp-command-center/v1/admin/alt-text/generate';
$out=[]; $emit=function($d,$ok,$x='')use(&$out){ $out[]=$d."\t".($ok?'PASS':'FAIL')."\t".$x; };
global $wpdb;
$JPEG='/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAAAv/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AfwD/2Q==';
$up=wp_upload_dir();
$mkimg=function($name,$alt)use($up,$JPEG){ $f=$up['path'].'/'.$name.'.jpg'; file_put_contents($f,base64_decode($JPEG)); $id=wp_insert_attachment(['post_title'=>$name,'post_mime_type'=>'image/jpeg','post_status'=>'inherit'],$f); update_attached_file($id,$f); if($alt!==null)update_post_meta($id,'_wp_attachment_image_alt',$alt); return [$id,$f]; };
$ok_mock=function($pre,$args,$url){ return ['response'=>['code'=>200],'body'=>wp_json_encode(['model'=>'claude-sonnet-4-6','content'=>[['type'=>'text','text'=>'A descriptive suggestion']]])]; };
$err_mock=function($pre,$args,$url){ return ['response'=>['code'=>500],'body'=>wp_json_encode(['error'=>['message'=>'provider down']])]; };
$call=function($body)use($NS){ $q=new WP_REST_Request('POST',$NS); $q->set_body_params($body); $x=rest_get_server()->dispatch($q); return [$x->get_status(),$x->get_data()]; };
$store=new ProposalStore();

// 1. route exists
$emit('generate route registered', isset(rest_get_server()->get_routes()['/wp-command-center/v1/admin/alt-text/generate']));

// 2. auth/gate (no user -> 401)
wp_set_current_user(0); [$st0]=$call(['attachment_ids'=>[1]]); $emit('generate denied without auth (401)', 401===$st0, (string)$st0); wp_set_current_user($uid);

// 3. no provider configured -> safe (no created, skipped no_provider)
delete_option('wpcc_alt_text_api_key');
[$id0,$f0]=$mkimg('gen7c-noprov','old');
$gen=new AltTextGenerator(); $rnp=$gen->generate([$id0],['actor'=>['type'=>'admin','wp_user_id'=>$uid]]);
$emit('no provider -> safe (0 created, skip no_provider)', 0===count($rnp['created']) && ($rnp['skipped'][0]['reason']??'')==='no_provider');

update_option('wpcc_alt_text_api_key','sk-ant-test-KEY000');

// 8 + 10 + 11 + 12 + 13. provider success creates a correct draft
[$id1,$f1]=$mkimg('gen7c-ok','old alt');
$alt_before=get_post_meta($id1,'_wp_attachment_image_alt',true);
add_filter('pre_http_request',$ok_mock,10,3);
$r1=$gen->generate([$id1],['actor'=>['type'=>'admin','wp_user_id'=>$uid]]);
remove_filter('pre_http_request',$ok_mock,10);
$emit('provider success creates a draft', 1===count($r1['created']) && 0===count($r1['failed']));
$pid=$r1['created'][0]??''; $p=$pid?$store->get($pid):[];
$payload=json_decode($p['payload_json']??'[]',true); $prior=json_decode($p['prior_json']??'[]',true);
$emit('draft payload is media_update(media_id,alt)', ($payload['action']??'')==='media_update' && (int)($payload['media_id']??0)===$id1 && ($payload['alt']??'')==='A descriptive suggestion');
$emit('draft prior captures current alt', ($prior['alt']??null)==='old alt');
$emit('provenance: provider+model stored', ($p['provider']??'')==='anthropic' && ($p['model']??'')==='claude-sonnet-4-6');
$emit('provenance: confidence may be null (stored)', array_key_exists('confidence',$p) && null===$p['confidence']);
$emit('batch_id stored, UUID length 36', ($p['batch_id']??'')===$r1['batch_id'] && 36===strlen((string)$r1['batch_id']));
$pb=json_decode($p['proposed_by']??'null',true);
$emit('proposed_by stored as actor array', is_array($pb) && ($pb['type']??'')==='admin' && (int)($pb['wp_user_id']??0)===$uid);

// 14 + 15 + 16 + 17. propose-not-apply: site unchanged; no apply path
$emit('attachment alt UNCHANGED after generate', get_post_meta($id1,'_wp_attachment_image_alt',true)===$alt_before);
$emit('draft is status=draft (not applied)', ($p['status']??'')==='draft');

// 7. skip attachment with open proposal (dedup)
add_filter('pre_http_request',$ok_mock,10,3);
$rd=$gen->generate([$id1],['actor'=>['type'=>'admin','wp_user_id'=>$uid]]);
remove_filter('pre_http_request',$ok_mock,10);
$emit('skips attachment with open proposal', 0===count($rd['created']) && ($rd['skipped'][0]['reason']??'')==='has_open_proposal');

// 6. skip non-image attachment (pdf) + missing attachment
$pdf=wp_insert_attachment(['post_title'=>'gen7c-pdf','post_mime_type'=>'application/pdf','post_status'=>'inherit']);
add_filter('pre_http_request',$ok_mock,10,3);
$rs=$gen->generate([$pdf, 99999999],['actor'=>[]]);
remove_filter('pre_http_request',$ok_mock,10);
$reasons=array_column($rs['skipped'],'reason');
$emit('skips non-image (pdf) and missing attachment', in_array('not_image',$reasons,true) && in_array('not_found',$reasons,true) && 0===count($rs['created']));
wp_delete_attachment($pdf,true);

// 9. provider error -> failed[], run not aborted
[$id2,$f2]=$mkimg('gen7c-err','old');
add_filter('pre_http_request',$err_mock,10,3);
$re=$gen->generate([$id2],['actor'=>[]]);
remove_filter('pre_http_request',$err_mock,10);
$emit('provider error -> failed[] (run not aborted)', 0===count($re['created']) && 1===count($re['failed']) && strpos((string)($re['failed'][0]['code']??''),'api_error_')===0);

// 4 + 5. explicit ids only + cap (>25 ids capped; here all skip not_found but processed<=25)
$rc=$gen->generate(range(900000,900040),['actor'=>[]]);
$processed=count($rc['created'])+count($rc['skipped'])+count($rc['failed']);
$emit('caps request size to MAX_BATCH (<=25)', $processed<=25, (string)$processed);

// 18. generated proposal visible via existing /admin/proposals REST
$ql=new WP_REST_Request('GET','/wp-command-center/v1/admin/proposals'); $ql->set_param('limit',200);
$list=rest_get_server()->dispatch($ql)->get_data(); $found=false; foreach(($list['proposals']??[]) as $pp){ if($pp['proposal_id']===$pid)$found=true; }
$emit('generated draft visible via /admin/proposals', $found);

// 19. generated draft applies via existing ProposalApplyService path
$orig=get_option('wpcc_security_mode',''); update_option('wpcc_security_mode','developer');
$ap=( new ProposalApplyService() )->apply($pid,['actor'=>['type'=>'admin','wp_user_id'=>$uid]]);
$emit('generated draft applies via existing path', !is_wp_error($ap) && ($ap['status']??'')==='applied' && get_post_meta($id1,'_wp_attachment_image_alt',true)==='A descriptive suggestion');
update_option('wpcc_security_mode',$orig);

// cleanup
$wpdb->query("DELETE FROM {$wpdb->prefix}wpcc_proposals");
$wpdb->query("DELETE FROM {$wpdb->prefix}wpcc_change_log WHERE operation_id='media_manage'");
foreach([$id0,$id1,$id2] as $x) wp_delete_attachment($x,true);
@unlink($f0); @unlink($f1); @unlink($f2);
delete_option('wpcc_alt_text_api_key');
echo implode("\n",$out);
PHP
GRES="$(wp --path="$WP_ROOT" eval-file "$GBATT" 2>/dev/null)"
rm -f "$GBATT"
while IFS=$'\t' read -r DESC STATUS DETAIL; do
  [ -z "$DESC" ] && continue
  if [ "$STATUS" = "PASS" ]; then pass "$DESC"; else fail "$DESC ($DETAIL)"; fi
done <<< "$GRES"

# ── Static: generator boundaries (propose-not-apply) ─────────────────────────
GEN_TMP="$(mktemp /tmp/wpcc-gencode-XXXXXX)"
grep -vE '^[[:space:]]*(\*|/\*|//)' includes/AltText/AltTextGenerator.php > "$GEN_TMP"
for forbidden in "OperationExecutor" "ProposalApplyService" "OperationManager" "update_post_meta" "wp_update_post" "update_option" "->apply(" "wpcc_change_log" "wp_remote_post"; do
  grep -qF -- "$forbidden" "$GEN_TMP" && fail "generator must not reference $forbidden" || pass "generator has no $forbidden"
done
assert_eq "generator's only write is ProposalStore::create" "yes" "$(grep -q 'store->create(' "$GEN_TMP" && echo yes || echo no)"
rm -f "$GEN_TMP"

echo ""
echo "RESULT: ${PASS} passed, ${FAIL} failed"
[ "$FAIL" -eq 0 ]
