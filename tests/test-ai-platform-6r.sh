#!/usr/bin/env bash
# PROGRAM-6R — Connection-centric AI platform.
# Part 1: structural/security (lint + rg). Part 2: functional via wp eval-file
# (dialects, catalogue, connection lifecycle, runtime-usable honesty, routing,
# secrets isolation, backward-compat bridge), snapshot + cleanup, no real key.
set -uo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$DIR/.." && pwd)"
WP_PATH="$DIR/../../../.."

DIAL="$ROOT/includes/Ai/Platform/Dialect.php"
PCAT="$ROOT/includes/Ai/Platform/ProviderCatalog.php"
CRED="$ROOT/includes/Ai/Platform/CredentialStore.php"
CONN="$ROOT/includes/Ai/Platform/ConnectionStore.php"
TEST="$ROOT/includes/Ai/Platform/ConnectionTester.php"
CTRL="$ROOT/includes/Admin/ConnectionController.php"
VIEW="$ROOT/includes/Admin/views/ai-setup.php"

P=0; F=0
pass(){ P=$((P+1)); echo "  PASS: $1"; }
fail(){ F=$((F+1)); echo "  FAIL: $1"; }
has(){ if rg -q -e "$2" "$3"; then pass "$1"; else fail "$1"; fi; }
hasnt(){ if rg -q -e "$2" "$3"; then fail "$1"; else pass "$1"; fi; }

echo "== 1. Lint =="
for f in "$DIAL" "$PCAT" "$CRED" "$CONN" "$TEST" "$CTRL" "$VIEW"; do
	if php -l "$f" >/dev/null 2>&1; then pass "lint $(basename "$f")"; else fail "lint $(basename "$f")"; fi
done

echo "== 2. Security contract =="
has "controller nonce" "check_admin_referer\( self::NONCE" "$CTRL"
has "controller capability" "current_user_can\( 'manage_options' \)" "$CTRL"
has "create audit" "ai.connection.created" "$CTRL"
has "delete audit" "ai.connection.deleted" "$CTRL"
has "test audit" "ai.connection.test" "$CTRL"
hasnt "audit never carries the raw key var" "record\(.*wpcc_key" "$CTRL"
has "default must be runtime-usable" "runtime_usable" "$CTRL"

echo "== 3. View — connection-centric, honest, no key echo =="
hasnt "view never echoes a key" "echo .*(wpcc_key|->secret\()" "$VIEW"
hasnt "key input not prefilled" "name=\"wpcc_key\"[^>]*value=" "$VIEW"
has "key inputs type=password" 'type="password"' "$VIEW"
has "empty state" "No AI connections yet" "$VIEW"
has "add connection flow" "Add a connection" "$VIEW"
has "duplicate action" "value=\"duplicate\"" "$VIEW"
has "USED BY RUNTIME honesty" "USED BY RUNTIME" "$VIEW"
has "TESTABLE honesty" "TESTABLE" "$VIEW"
has "STORED ONLY honesty" "STORED ONLY" "$VIEW"
has "tags supported" "name=\"wpcc_tags\"" "$VIEW"
has "base URL / endpoint supported" "name=\"wpcc_endpoint\"" "$VIEW"
has "feature routing present" "Feature routing" "$VIEW"
has "after-key guidance retained (5C)" "does not turn AI features on by itself" "$VIEW"

echo "== 4. Dialect architecture =="
has "anthropic dialect" "ANTHROPIC = 'anthropic'" "$DIAL"
has "openai-compatible dialect" "OPENAI    = 'openai-compatible'" "$DIAL"
has "gemini dialect" "GEMINI    = 'gemini'" "$DIAL"
has "only anthropic runtime-supported" "'runtime_supported' => true" "$DIAL"
has "tester reuses anthropic transport" "AnthropicClient" "$TEST"
has "openai-compatible base_url test" "/models" "$TEST"

echo "== 5. Local/gateway providers via one dialect =="
has "ollama present" "'ollama'" "$PCAT"
has "lmstudio present" "'lmstudio'" "$PCAT"
has "custom openai-compatible present" "'custom-openai'" "$PCAT"
has "openrouter present" "'openrouter'" "$PCAT"

echo "== 6. Functional (wp eval-file) =="
PHPF="$(mktemp -t wpcc6r.XXXXXX.php)"
cat > "$PHPF" <<'PHP'
<?php
use WPCommandCenter\Ai\Platform\Dialect as D;
use WPCommandCenter\Ai\Platform\ProviderCatalog as C;
use WPCommandCenter\Ai\Platform\ConnectionStore as S;
$p=0;$f=0; $ok=function($c,$n)use(&$p,&$f){if($c){$p++;}else{$f++;echo "FUNC-FAIL: $n\n";}};

$snap_c=get_option('wpcc_ai_connections','__none__');
$snap_k=get_option('wpcc_ai_credentials','__none__');
$snap_d=get_option('wpcc_ai_default_conn','__none__');
$snap_r=get_option('wpcc_ai_routes','__none__');
delete_option('wpcc_ai_connections');delete_option('wpcc_ai_credentials');
delete_option('wpcc_ai_default_conn');delete_option('wpcc_ai_routes');

// dialects + catalogue
$ok(count(D::all())===3,'three dialects');
$ok(D::runtime_supported('anthropic')===true,'anthropic dialect runtime');
$ok(D::runtime_supported(D::OPENAI)===false,'openai-compatible NOT runtime (honest)');
$ok(D::test_supported(D::OPENAI)===true,'openai-compatible testable');
$ok(C::dialect_of('ollama')===D::OPENAI,'ollama uses openai-compatible dialect');
$ok(C::dialect_of('groq')===D::OPENAI,'groq uses openai-compatible dialect');
$ok(C::runtime_usable('openai')===false && C::runtime_usable('anthropic')===true,'runtime honesty by dialect');
$ok(C::test_supported('ollama')===true,'ollama testable (dialect)');
$ok(count(C::all())>=12,'catalogue has many providers via 3 dialects');

$s=new S();
// create an openai-compatible local connection (no key needed) with opaque id
$id=$s->create('ollama',['name'=>'Office Ollama','endpoint'=>'http://localhost:11434/v1','model'=>'llama3']);
$ok(strpos($id,'conn_')===0,'opaque connection id generated');
$c=$s->get($id);
$ok($c && $c['provider']==='ollama' && $c['dialect']===D::OPENAI,'connection stored with provider+dialect');
$ok($c['endpoint']==='http://localhost:11434/v1','endpoint stored (local base_url)');
$ok($s->runtime_usable($c)===false,'ollama connection not runtime-usable (honest)');
$ok($s->set_default($id)===false,'CANNOT default to non-runtime connection');
$ok($s->set_route('seo_meta',$id)===false,'CANNOT route a feature to non-runtime connection');

// two connections of the SAME provider (environments) — the thing Program-6 could not do
$id2=$s->create('openai',['name'=>'Prod GPT']);
$id3=$s->create('openai',['name'=>'Cheap GPT']);
$ok($id2!==$id3 && $s->exists($id2) && $s->exists($id3),'multiple connections of one provider type (environments)');

// secrets isolated, never in the connection record
$s->credentials()->set_secret($id2,'testkeyaaaa');
$ok($s->credentials()->secret($s->get($id2))==='testkeyaaaa','secret retrievable for tester');
$conn_raw=json_encode(get_option('wpcc_ai_connections'));
$ok(strpos($conn_raw,'testkey')===false,'no secret in connection record option');
$cred_raw=get_option('wpcc_ai_credentials');
$ok(is_array($cred_raw) && ($cred_raw[$id2]??'')==='testkeyaaaa','secret in dedicated credential option');

// duplicate does NOT copy the key (security)
$iddup=$s->duplicate($id2);
$ok($iddup!=='' && !$s->credentials()->has_secret($s->get($iddup)),'duplicate omits the key');

// delete cleans up
$s->delete($id2);
$ok(!$s->exists($id2),'connection deleted');
$ok(($s->credentials()->secret(['id'=>$id2,'dialect'=>D::OPENAI,'provider'=>'openai']))==='','secret cleared on delete');

// restore
delete_option('wpcc_ai_connections');delete_option('wpcc_ai_credentials');
delete_option('wpcc_ai_default_conn');delete_option('wpcc_ai_routes');
if($snap_c!=='__none__')update_option('wpcc_ai_connections',$snap_c,false);
if($snap_k!=='__none__')update_option('wpcc_ai_credentials',$snap_k,false);
if($snap_d!=='__none__')update_option('wpcc_ai_default_conn',$snap_d,false);
if($snap_r!=='__none__')update_option('wpcc_ai_routes',$snap_r,false);
echo ($f===0 ? "FUNC_OK $p" : "FUNC_BAD $f")."\n";
PHP
FUNC_OUT="$(wp eval-file "$PHPF" --path="$WP_PATH" 2>/dev/null)"
rm -f "$PHPF"
echo "$FUNC_OUT" | rg "FUNC-FAIL" | sed 's/^/  /' || true
if echo "$FUNC_OUT" | rg -q "FUNC-FAIL|FUNC_BAD"; then
	fail "functional — $(echo "$FUNC_OUT" | rg 'FUNC-FAIL|FUNC_BAD' | head -1)"
elif echo "$FUNC_OUT" | rg -q "FUNC_OK [0-9]+"; then
	pass "functional connection platform ($(echo "$FUNC_OUT" | rg -o 'FUNC_OK [0-9]+' | awk '{print $2}') checks)"
else
	fail "functional — did not complete: $(echo "$FUNC_OUT" | head -c 140)"
fi

echo
echo "== Summary =="
echo "  $P passed, $F failed"
[ "$F" -eq 0 ]
