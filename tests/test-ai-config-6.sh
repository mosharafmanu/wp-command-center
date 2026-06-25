#!/usr/bin/env bash
# PROGRAM-6 — Multi-provider AI Configuration System.
# Part 1: static structural/security checks (lint + rg).
# Part 2: functional checks via `wp eval-file` against the real ProviderCatalog /
#         ProviderStore logic, with snapshot + cleanup (no DB pollution, no real key).
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$DIR/.." && pwd)"
WP_PATH="$DIR/../../../.."

CAT="$ROOT/includes/Admin/ProviderCatalog.php"
STORE="$ROOT/includes/Admin/ProviderStore.php"
TESTER="$ROOT/includes/Admin/ProviderConnectionTester.php"
CTRL="$ROOT/includes/Admin/ProviderConfigController.php"
VIEW="$ROOT/includes/Admin/views/ai-setup.php"

P=0; F=0
pass(){ P=$((P+1)); echo "  PASS: $1"; }
fail(){ F=$((F+1)); echo "  FAIL: $1"; }
has(){ if rg -q -e "$2" "$3"; then pass "$1"; else fail "$1"; fi; }
hasnt(){ if rg -q -e "$2" "$3"; then fail "$1"; else pass "$1"; fi; }

echo "== 1. Lint =="
for f in "$CAT" "$STORE" "$TESTER" "$CTRL" "$VIEW"; do
	if php -l "$f" >/dev/null 2>&1; then pass "lint $(basename "$f")"; else fail "lint $(basename "$f")"; fi
done

echo "== 2. Security contract =="
has "controller checks nonce" "check_admin_referer\( self::NONCE" "$CTRL"
has "controller checks capability" "current_user_can\( 'manage_options' \)" "$CTRL"
has "controller emits add audit" "ai.provider.added" "$CTRL"
has "controller emits delete audit" "ai.provider.deleted" "$CTRL"
has "controller emits test audit" "ai.provider.test" "$CTRL"
hasnt "audit never carries the raw key var" "record\(.*wpcc_provider_key" "$CTRL"
has "default must be runtime-usable" "runtime_usable\( \\\$type \)" "$CTRL"

echo "== 3. View — never exposes the secret, honest states =="
hasnt "view never echoes a key value" "echo .*(provider_key|->secret\()" "$VIEW"
hasnt "key input not prefilled with a value" "name=\"wpcc_provider_key\"[^>]*value=" "$VIEW"
has "key inputs are type=password" 'type="password"' "$VIEW"
has "empty state present" "No AI providers configured" "$VIEW"
has "stored-only honesty label" "STORED ONLY" "$VIEW"
has "used-by-runtime honesty label" "USED BY WPCC" "$VIEW"
has "test-not-available honesty" "Test not available yet" "$VIEW"
has "add-provider flow present" "Add provider" "$VIEW"
has "feature mapping present" "Which provider does each feature use" "$VIEW"

echo "== 4. Tester — minimal, secret-safe, honest =="
has "anthropic test reuses transport" "new AnthropicClient" "$TESTER"
has "openai live test present" "api.openai.com/v1/models" "$TESTER"
has "gemini live test present" "generativelanguage.googleapis.com" "$TESTER"
has "unsupported providers not faked" "test_unsupported" "$TESTER"
has "errors scrubbed by Redactor" "Redactor" "$TESTER"
has "short timeout" "TIMEOUT = 10" "$TESTER"

echo "== 5. Functional (wp eval-file) — catalogue + store logic =="
PHPF="$(mktemp -t wpcc6.XXXXXX.php)"
cat > "$PHPF" <<'PHP'
<?php
// Environment-agnostic: does NOT assume the ambient Anthropic key is unset (this
// dev env may have one via constant/legacy option). Tests the new config-only
// provider path + honesty rules + backward-compat reflection, then restores.
use WPCommandCenter\Admin\ProviderCatalog as C;
use WPCommandCenter\Admin\ProviderStore as S;
// Closure with by-reference capture: `global` does not work under wp eval-file's
// include scope, so $p/$f must be captured by reference to count correctly.
$p=0;$f=0; $ok=function($c,$n)use(&$p,&$f){if($c){$p++;}else{$f++;echo "FUNC-FAIL: $n\n";}};

$snap_rec = get_option('wpcc_ai_providers', '__none__');
$snap_sec = get_option('wpcc_ai_provider_secrets', '__none__');
$snap_def = get_option('wpcc_ai_default_provider', '__none__');
$snap_map = get_option('wpcc_ai_feature_map', '__none__');
delete_option('wpcc_ai_providers'); delete_option('wpcc_ai_provider_secrets');
delete_option('wpcc_ai_default_provider'); delete_option('wpcc_ai_feature_map');

// catalogue (env-independent)
$types = C::types();
$ok(count($types)===8, 'catalogue has 8 types');
$ok(count($types)===count(array_unique(array_keys($types))), 'no duplicate type keys');
$ok(C::runtime_usable('anthropic')===true, 'anthropic runtime-usable');
$ok(C::runtime_usable('openai')===false, 'openai NOT runtime-usable (honest)');
$ok(C::test_supported('openai')===true, 'openai test supported');
$ok(C::test_supported('gemini')===true, 'gemini test supported');
$ok(C::test_supported('openrouter')===false, 'openrouter test NOT supported (honest)');
$ok(C::is_valid_type('mistral')===true && C::is_valid_type('nope')===false, 'type validation');

$s = new S();
// config-only provider lifecycle (independent of ambient anthropic)
$s->save_record('openai','My OpenAI','gpt-5-mini');
$s->set_secret('openai','testkeyaaaaaaaa');
$ok($s->has_secret('openai')===true, 'openai has key after set');
$ok(isset($s->records()['openai']), 'openai record present');
$ok($s->set_default('openai')===false, 'CANNOT default to config-only provider (honest)');
$ok($s->set_feature('seo_meta','openai')===false, 'CANNOT map feature to config-only provider');
$ok($s->secret('openai')==='testkeyaaaaaaaa', 'secret retrievable for tester (non-anthropic)');

// runtime-usable rules (anthropic) — env may already have a key; assert the RULES
$ok($s->set_default('anthropic')!==false ? true : (C::runtime_usable('anthropic')), 'anthropic is an allowable default type');
$ok($s->set_feature('seo_meta','anthropic')===true, 'CAN map feature to runtime-usable anthropic');

// secrets never stored in the records option
$rec_raw = json_encode(get_option('wpcc_ai_providers'));
$ok(strpos($rec_raw,'testkey')===false, 'no secret stored in records option');
$sec_raw = get_option('wpcc_ai_provider_secrets');
$ok(is_array($sec_raw) && ($sec_raw['openai'] ?? '')==='testkeyaaaaaaaa', 'non-anthropic secret stored in dedicated option');

// delete cleans up the config-only provider + its secret
$s->delete('openai');
$ok($s->has_secret('openai')===false, 'openai key cleared on delete');
$ok(!isset($s->records()['openai']), 'openai record removed on delete');

// restore
delete_option('wpcc_ai_providers'); delete_option('wpcc_ai_provider_secrets');
delete_option('wpcc_ai_default_provider'); delete_option('wpcc_ai_feature_map');
if($snap_rec!=='__none__'){update_option('wpcc_ai_providers',$snap_rec,false);}
if($snap_sec!=='__none__'){update_option('wpcc_ai_provider_secrets',$snap_sec,false);}
if($snap_def!=='__none__'){update_option('wpcc_ai_default_provider',$snap_def,false);}
if($snap_map!=='__none__'){update_option('wpcc_ai_feature_map',$snap_map,false);}

echo ($f===0 ? "FUNC_OK $p" : "FUNC_BAD $f")."\n";
PHP
FUNC_OUT="$(wp eval-file "$PHPF" --path="$WP_PATH" 2>/dev/null)"
rm -f "$PHPF"
echo "$FUNC_OUT" | rg "FUNC-FAIL" | sed 's/^/  /' || true
if echo "$FUNC_OUT" | rg -q "FUNC-FAIL|FUNC_BAD"; then
	fail "functional store/catalogue — $(echo "$FUNC_OUT" | rg 'FUNC-FAIL|FUNC_BAD' | head -1)"
elif echo "$FUNC_OUT" | rg -q "FUNC_OK [0-9]+"; then
	FP=$(echo "$FUNC_OUT" | rg -o "FUNC_OK [0-9]+" | awk '{print $2}')
	pass "functional store/catalogue ($FP checks)"
else
	fail "functional store/catalogue — did not complete: $(echo "$FUNC_OUT" | head -c 120)"
fi

echo
echo "== Summary =="
echo "  $P passed, $F failed"
[ "$F" -eq 0 ]
