#!/usr/bin/env bash
# Discovered models (capture→persist→select→accept) + Feature Routing clarity.
# Data/UX only — generation/security/runtime untouched.
set -uo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$DIR/.." && pwd)"
WP_PATH="$DIR/../../../.."
V="$ROOT/includes/Admin/views/ai-setup.php"
T="$ROOT/includes/Ai/Platform/ConnectionTester.php"
S="$ROOT/includes/Ai/Platform/ConnectionStore.php"
CC="$ROOT/includes/Admin/ConnectionController.php"

P=0; F=0
pass(){ P=$((P+1)); echo "  PASS: $1"; }
fail(){ F=$((F+1)); echo "  FAIL: $1"; }
has(){ if rg -q -e "$2" "$3"; then pass "$1"; else fail "$1"; fi; }

echo "== 1. Lint =="
for f in "$V" "$T" "$S" "$CC"; do php -l "$f" >/dev/null 2>&1 && pass "lint $(basename "$f")" || fail "lint $(basename "$f")"; done

echo "== 2. Discovery: real model ids captured (not just counted) =="
has "tester captures model ids from /models response" "models_list" "$T"
has "tester reads OpenAI data\[\].id" "\\\$m\['id'\]" "$T"
has "ids never invented (from provider response only)" "never invented" "$T"

echo "== 3. Persistence: sanitised + bounded, no secrets =="
has "store persists models_list" "'models_list'\]" "$S"
has "bounded to 250" "count\( \\\$ids \) >= 250" "$S"
has "rejects path-ish ids (..)" "strpos\( \\\$m, '..' \)" "$S"

echo "== 4. Editor shows recommended + discovered + custom =="
has "edit selector present" 'class="wpcc-edit-model"' "$V"
has "Recommended optgroup" "esc_attr_e\( 'Recommended'" "$V"
has "Discovered optgroup (count)" "Discovered from your account \(%d\)" "$V"
has "Custom model ID preserved" "Custom model ID" "$V"
has "custom text revealed by JS" "wpcc-edit-model-custom" "$V"

echo "== 5. Backend accepts a selected discovered/recommended id =="
has "model_value accepts validated id" "preg_match\( '/\^\[A-Za-z0-9._:" "$CC"
has "model_value rejects path-ish id" "strpos\( \\\$choice, '..' \)" "$CC"

echo "== 6. Routing clarity (Issue 2) — explicit reason, no faked support =="
has "intro explains runtime honestly (default connection)" "the one connection you set as the default" "$V"
has "intro: no auto-selection promise" "Nothing is selected automatically" "$V"
has "ineligible connections collected" "wpcc_ineligible_conns" "$V"
has "ineligible shown as disabled with reason" "healthy, but WP Command Center can" "$V"
has "explicit note: nothing hidden or faked" "Nothing is hidden or faked" "$V"

echo "== 6b. Copy guidance (setup→discovery, no-discovery, discovery-unavailable) =="
has "wizard: recommended-at-setup, discovery-after-test" "Recommended models are shown during setup" "$V"
has "edit: 'test once to discover' hint" "Test this connection once to discover" "$V"
has "edit: discovery-unavailable explained (not broken)" "doesn.t publish an account model list" "$V"
has "copy-selection flag is presentation-only" "Copy selection only" "$V"

echo "== 7. Generation/security/runtime byte-identical to main =="
for f in includes/Ai/AnthropicClient.php includes/Ai/Platform/Dialect.php includes/Ai/Platform/CredentialStore.php; do
  if git -C "$ROOT" diff --quiet main -- "$f" 2>/dev/null; then pass "unchanged vs main: $(basename "$f")"; else fail "CHANGED vs main: $(basename "$f")"; fi
done

echo "== 8. Functional: capture→persist→select→accept + routing eligibility =="
PHPF="$(mktemp -t discr.XXXXXX.php)"
cat > "$PHPF" <<'PHP'
<?php
use WPCommandCenter\Ai\Platform\ConnectionStore as CS;
use WPCommandCenter\Admin\ConnectionController as CC;
$p=0;$f=0; $ok=function($c,$n)use(&$p,&$f){if($c){$p++;}else{$f++;echo "FUNC-FAIL: $n\n";}};

$s=new CS();
$id=$s->create("openai",["name"=>"DiscoverTest"]);
// simulate a successful test that discovered 4 models (one invalid)
$s->record_test($id,true,"ok",["latency_ms"=>50,"models"=>4,"models_list"=>["gpt-4o","gpt-4o-mini","models/x","../evil","gpt-4o"]]);
$c=$s->get($id); $list=$c["last_test"]["models_list"]??[];
$ok(in_array("gpt-4o",$list,true) && in_array("gpt-4o-mini",$list,true),"discovered ids persisted");
$ok(!in_array("../evil",$list,true),"path-ish id rejected");
$ok(count($list)===count(array_unique($list)),"deduped");

// backend accepts a discovered id chosen in the selector
$_POST=["wpcc_model"=>"gpt-4o-mini"];
$ctrl=new CC(); $rm=new ReflectionMethod($ctrl,"model_value"); $rm->setAccessible(true);
$ok($rm->invoke($ctrl,"openai")==="gpt-4o-mini","selected discovered id accepted by backend");
$_POST=["wpcc_model"=>"../evil"];
$ok($rm->invoke($ctrl,"openai")!=="../evil","path-ish id NOT accepted (falls back)");
$s->delete($id);

// routing eligibility (Phase D): OpenAI-compatible AND Anthropic are runtime-usable.
$ok($s->runtime_usable(["provider"=>"anthropic","dialect"=>"anthropic"])===true,"Anthropic runtime-usable");
$ok($s->runtime_usable(["provider"=>"openai","dialect"=>"openai"])===true,"OpenAI runtime-usable (Phase D)");
echo ($f===0 ? "FUNC_OK $p" : "FUNC_BAD $f")."\n";
PHP
OUT="$(wp eval-file "$PHPF" --path="$WP_PATH" 2>/dev/null)"; rm -f "$PHPF"
echo "$OUT" | rg "FUNC-FAIL" | sed 's/^/  /' || true
if echo "$OUT" | rg -q "FUNC-FAIL|FUNC_BAD"; then fail "functional";
elif echo "$OUT" | rg -q "FUNC_OK"; then pass "functional ($(echo "$OUT" | rg -o 'FUNC_OK [0-9]+' | awk '{print $2}') checks)";
else fail "functional did not complete: $(echo "$OUT" | head -c 140)"; fi

echo; echo "== Summary =="; echo "  $P passed, $F failed"; [ "$F" -eq 0 ]
