#!/usr/bin/env bash
# Connection wizard UX cleanup (Base URL conditional, model dropdown, tags→advanced).
# Structural (view markup) + functional (provider-meta correctness) + no-backend-change.
set -uo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$DIR/.." && pwd)"
WP_PATH="$DIR/../../../.."
V="$ROOT/includes/Admin/views/ai-setup.php"

P=0; F=0
pass(){ P=$((P+1)); echo "  PASS: $1"; }
fail(){ F=$((F+1)); echo "  FAIL: $1"; }
has(){ if rg -q -e "$2" "$3"; then pass "$1"; else fail "$1"; fi; }
hasnt(){ if rg -q -e "$2" "$3"; then fail "$1"; else pass "$1"; fi; }

echo "== 1. Lint =="
php -l "$V" >/dev/null 2>&1 && pass "ai-setup.php lints" || fail "ai-setup.php lints"

echo "== 2. Base URL is conditional (cloud uses defaults automatically) =="
has "endpoint field has a toggle wrapper" 'id="wpcc-w-endpoint-field"' "$V"
has "copy: cloud uses official URL automatically" "Cloud providers use their official URL automatically" "$V"
hasnt "old confusing 'blank for cloud defaults' copy removed" "blank for cloud defaults" "$V"
has "JS hides Base URL unless endpoint required" "requires_endpoint \? '' : 'none'" "$V"

echo "== 3. Provider-aware model dropdown + custom fallback =="
has "model dropdown present" 'id="wpcc-w-model-select"' "$V"
has "custom model free-text fallback retained" 'name="wpcc_model_custom"' "$V"
has "'Custom model ID' option label" "Custom model ID" "$V"
has "JS wires wpcc_model flag to dropdown/custom" "mFlag.value = mdlSel.value" "$V"
has "free text used when provider has no model list" "mdlTxt.style.display = ''; mFlag.value = 'custom'" "$V"

echo "== 4. Tags hidden behind Advanced options + explained =="
has "Advanced options disclosure" "<details class=\"wpcc-aip-advanced\"" "$V"
has "Advanced summary label" "Advanced options" "$V"
has "tags explained as internal routing/organization metadata" "Internal labels to organize and route" "$V"
has "tags never sent to provider" "never sent to the provider" "$V"
# tags input must sit AFTER the <details> opener (i.e., inside advanced, not the default path)
if [ "$(rg -n '<details class="wpcc-aip-advanced"' "$V" | head -1 | cut -d: -f1)" -lt "$(rg -n 'name="wpcc_tags"' "$V" | head -1 | cut -d: -f1)" ]; then
  pass "tags field is inside Advanced (after the details opener)"; else fail "tags field not inside Advanced"; fi

echo "== 5. Field names unchanged (no backend contract change) =="
for n in wpcc_provider wpcc_endpoint wpcc_key wpcc_model wpcc_model_custom wpcc_tags; do
  has "field $n preserved" "name=\"$n\"" "$V"
done

echo "== 6. Execution/storage/security byte-identical to main (no runtime change) =="
for f in includes/Admin/ConnectionController.php includes/Ai/Platform/ConnectionStore.php includes/Ai/Platform/Dialect.php includes/Ai/Platform/ConnectionTester.php; do
  if git -C "$ROOT" diff --quiet main -- "$f" 2>/dev/null; then pass "unchanged vs main: $(basename "$f")"; else fail "CHANGED vs main: $(basename "$f")"; fi
done
# ProviderCatalog gains metadata() but ADDITIVELY — no existing line removed/changed.
if [ -z "$(git -C "$ROOT" diff main -- includes/Ai/Platform/ProviderCatalog.php | rg '^-' | rg -v '^---')" ]; then pass "ProviderCatalog change is additive-only (no execution change)"; else fail "ProviderCatalog has non-additive edits"; fi

echo "== 7. Functional: provider-meta correctness (the data the wizard renders) =="
PHPF="$(mktemp -t wizux.XXXXXX.php)"
cat > "$PHPF" <<'PHP'
<?php
use WPCommandCenter\Ai\Platform\ProviderCatalog as PC;
$p=0;$f=0; $ok=function($c,$n)use(&$p,&$f){if($c){$p++;}else{$f++;echo "FUNC-FAIL: $n\n";}};
// cloud → no endpoint needed; default endpoint auto-applied
foreach(["anthropic","openai","gemini","groq","openrouter"] as $pr){ $ok(empty(PC::get($pr)["needs_endpoint"]),"cloud $pr needs_endpoint=false (Base URL hidden)"); }
// local/azure/custom → endpoint required (Base URL shown)
foreach(["azure-openai","ollama","lmstudio","vllm","custom-openai"] as $pr){ $ok(!empty(PC::get($pr)["needs_endpoint"]),"endpoint provider $pr needs_endpoint=true (Base URL shown)"); }
// OpenAI default base URL exactly as required
$ok(PC::default_endpoint("openai")==="https://api.openai.com/v1","OpenAI default = https://api.openai.com/v1");
// cloud providers with model dropdowns
$ok(isset(PC::get("openai")["models"]["gpt-5"]),"OpenAI offers model choices");
$ok(count(PC::get("anthropic")["models"])>=2 && isset(PC::get("anthropic")["models"]["claude-sonnet-4-6"]),"Anthropic offers runtime Claude model choices");
$ok(isset(PC::get("gemini")["models"]["gemini-2.5-flash"]),"Gemini offers model choices");
// local/gateway → free text (no fixed model list)
$ok(empty(PC::get("ollama")["models"]) && empty(PC::get("groq")["models"]),"local/gateway → free-text model (no list)");
echo ($f===0 ? "FUNC_OK $p" : "FUNC_BAD $f")."\n";
PHP
OUT="$(wp eval-file "$PHPF" --path="$WP_PATH" 2>/dev/null)"; rm -f "$PHPF"
echo "$OUT" | rg "FUNC-FAIL" | sed 's/^/  /' || true
if echo "$OUT" | rg -q "FUNC-FAIL|FUNC_BAD"; then fail "functional provider-meta";
elif echo "$OUT" | rg -q "FUNC_OK"; then pass "functional provider-meta ($(echo "$OUT" | rg -o 'FUNC_OK [0-9]+' | awk '{print $2}') checks)";
else fail "functional did not complete: $(echo "$OUT" | head -c 120)"; fi

echo; echo "== Summary =="; echo "  $P passed, $F failed"; [ "$F" -eq 0 ]
