#!/usr/bin/env bash
# Provider-driven wizard: metadata descriptor, discovery seam, fallback, custom,
# search, endpoint visibility, progressive enhancement. UX/metadata only.
set -uo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$DIR/.." && pwd)"
WP_PATH="$DIR/../../../.."
V="$ROOT/includes/Admin/views/ai-setup.php"
PC="$ROOT/includes/Ai/Platform/ProviderCatalog.php"

P=0; F=0
pass(){ P=$((P+1)); echo "  PASS: $1"; }
fail(){ F=$((F+1)); echo "  FAIL: $1"; }
has(){ if rg -q -e "$2" "$3"; then pass "$1"; else fail "$1"; fi; }

echo "== 1. Lint =="
php -l "$V" >/dev/null 2>&1 && pass "ai-setup lints" || fail "ai-setup lints"
php -l "$PC" >/dev/null 2>&1 && pass "ProviderCatalog lints" || fail "ProviderCatalog lints"

echo "== 2. Provider metadata descriptor exists (provider-driven) =="
has "ProviderCatalog::metadata()" "public static function metadata\(" "$PC"
has "ProviderCatalog::metadata_all()" "public static function metadata_all\(" "$PC"
has "wizard renders from metadata_all()" "ProviderCatalog::metadata_all\(\)" "$V"
has "supports_discovery exposed" "'supports_discovery'" "$PC"
has "requires_endpoint exposed" "'requires_endpoint'" "$PC"
has "recommended_models exposed" "'recommended_models'" "$PC"
has "supports_custom_model exposed" "'supports_custom_model'" "$PC"
has "supports_search exposed" "'supports_search'" "$PC"
has "supports_testing exposed" "'supports_testing'" "$PC"

echo "== 3. Discovery seam is honest (gated; curated fallback; no fabrication) =="
has "discovery gated behind supports_discovery + transport" "meta.supports_discovery && typeof window.wpccDiscoverModels" "$V"
has "falls back to curated list" "populate\(meta, curated\)" "$V"
has "discovery OFF for all providers today" "'supports_discovery'    => false" "$PC"

echo "== 4. Fallback never leaves an empty dropdown =="
has "empty list -> free text (not empty select)" "No list, no discovery" "$V"
has "free-text help shown" "FREE_HELP" "$V"

echo "== 5. Custom model always available =="
has "custom option appended for every list" "supports_custom_model !== false" "$V"
has "custom reveals text input" "mFlag.value = 'custom'; mdlTxt.style.display = ''" "$V"

echo "== 6. Search for large lists =="
has "search input present" 'id="wpcc-w-model-search"' "$V"
has "search shown only when list large or supports_search" "ids.length > THRESHOLD || meta.supports_search" "$V"
has "filter keeps custom reachable" "if \(o.value === CUSTOM\) \{ return; \}" "$V"
has "threshold from catalog const" "SEARCH_THRESHOLD" "$PC"

echo "== 7. Endpoint + deployment visibility driven by metadata =="
has "Base URL toggled by requires_endpoint" "meta.requires_endpoint \? '' : 'none'" "$V"
has "deployment field toggled by needs_deployment" "meta.needs_deployment \? '' : 'none'" "$V"
has "deployment is a real consumed field (wpcc_deployment)" 'name="wpcc_deployment"' "$V"

echo "== 8. Progressive enhancement (no-JS safe) =="
has "model select hidden by default (JS reveals)" 'id="wpcc-w-model-select" style="display:none;"' "$V"
has "search hidden by default" 'id="wpcc-w-model-search" style="display:none;' "$V"
has "free-text model input present as fallback" 'id="wpcc-w-model" name="wpcc_model_custom"' "$V"

echo "== 9. Functional: metadata correctness across providers =="
PHPF="$(mktemp -t wizmeta.XXXXXX.php)"
cat > "$PHPF" <<'PHP'
<?php
use WPCommandCenter\Ai\Platform\ProviderCatalog as PC;
$p=0;$f=0; $ok=function($c,$n)use(&$p,&$f){if($c){$p++;}else{$f++;echo "FUNC-FAIL: $n\n";}};
$all = PC::metadata_all();
$ok(count($all) >= 15, "metadata_all covers every provider (".count($all).")");
// discovery is OFF for ALL today (honest — no listing endpoint)
$disc=0; foreach($all as $m){ if(!empty($m['supports_discovery'])) $disc++; }
$ok($disc===0, "no provider claims discovery yet (honest fallback)");
// cloud: no endpoint; local/azure: endpoint required
foreach(["anthropic","openai","gemini","groq"] as $pr){ $ok($all[$pr]['requires_endpoint']===false, "cloud $pr requires_endpoint=false"); }
foreach(["azure-openai","ollama","vllm","custom-openai"] as $pr){ $ok($all[$pr]['requires_endpoint']===true, "endpoint provider $pr requires_endpoint=true"); }
// curated recommended lists where known; empty (→free text) for gateways/local
$ok(count((array)$all['anthropic']['recommended_models'])>=2, "Anthropic has curated recommended models");
$ok(count((array)$all['openai']['recommended_models'])>=1, "OpenAI has curated recommended models");
$ok(count((array)$all['ollama']['recommended_models'])===0, "Ollama has no curated list (free text)");
// custom model supported broadly
$ok($all['openai']['supports_custom_model']===true && $all['ollama']['supports_custom_model']===true, "custom model supported");
// testing capability surfaced
$ok($all['anthropic']['supports_testing']===true, "Anthropic testable surfaced");
// adding a provider needs no wizard change: metadata derives entirely from the catalog row
$ok(array_keys(PC::metadata("openai")) === array_keys(PC::metadata("ollama")), "uniform descriptor shape across providers");
echo ($f===0 ? "FUNC_OK $p" : "FUNC_BAD $f")."\n";
PHP
OUT="$(wp eval-file "$PHPF" --path="$WP_PATH" 2>/dev/null)"; rm -f "$PHPF"
echo "$OUT" | rg "FUNC-FAIL" | sed 's/^/  /' || true
if echo "$OUT" | rg -q "FUNC-FAIL|FUNC_BAD"; then fail "functional metadata";
elif echo "$OUT" | rg -q "FUNC_OK"; then pass "functional metadata ($(echo "$OUT" | rg -o 'FUNC_OK [0-9]+' | awk '{print $2}') checks)";
else fail "functional did not complete: $(echo "$OUT" | head -c 120)"; fi

echo; echo "== Summary =="; echo "  $P passed, $F failed"; [ "$F" -eq 0 ]
