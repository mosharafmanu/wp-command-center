#!/usr/bin/env bash
# PROGRAM-6S — AI Platform experience (UX) structural verification.
# Static lint + rg + a functional check of the read-only Health/Capabilities helpers.
set -uo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$DIR/.." && pwd)"
WP_PATH="$DIR/../../../.."

VIEW="$ROOT/includes/Admin/views/ai-setup.php"
CAPS="$ROOT/includes/Ai/Platform/Capabilities.php"
HEALTH="$ROOT/includes/Ai/Platform/Health.php"

P=0; F=0
pass(){ P=$((P+1)); echo "  PASS: $1"; }
fail(){ F=$((F+1)); echo "  FAIL: $1"; }
has(){ if rg -q -e "$2" "$3"; then pass "$1"; else fail "$1"; fi; }
hasnt(){ if rg -q -e "$2" "$3"; then fail "$1"; else pass "$1"; fi; }

echo "== 1. Lint =="
for f in "$VIEW" "$CAPS" "$HEALTH"; do
	if php -l "$f" >/dev/null 2>&1; then pass "lint $(basename "$f")"; else fail "lint $(basename "$f")"; fi
done

echo "== 2. Dashboard experience =="
has "hero/landing present" "wpcc-aip-hero" "$VIEW"
has "setup readiness score" "Setup readiness" "$VIEW"
has "KPI grid" "wpcc-aip-kpis" "$VIEW"
has "default environment KPI" "Default environment" "$VIEW"
has "AI status KPI" "AI status" "$VIEW"
has "warnings surface" "wpcc-aip-warn" "$VIEW"
has "quick action new connection" "New connection" "$VIEW"

echo "== 3. Connection wizard =="
has "wizard present" "wpcc-aip-wizard" "$VIEW"
has "step 1 provider" "Step 1 — Choose a provider" "$VIEW"
has "step 3 credentials" "Step 3 — Credentials" "$VIEW"
has "step 4 model" "Step 4 — Model" "$VIEW"
has "step 5 create & test" "Step 5 — Create" "$VIEW"
has "wizard grouped cloud/local/gateway" "Gateway / Custom" "$VIEW"
has "wizard JS stepper" "wpcc-w-next" "$VIEW"

echo "== 4. Rich connection cards + health + capabilities =="
has "card component" "wpcc-aip-card" "$VIEW"
has "health dot" "wpcc-aip-dot" "$VIEW"
has "health label from helper" "Health::of" "$VIEW"
has "latency shown" "latency_ms" "$VIEW"
has "model discovery shown" "models" "$VIEW"
has "declared capabilities" "Capabilities \(declared\)" "$VIEW"
has "honest: declared not tested" "not live-tested" "$VIEW"
has "avatar/visual identity" "wpcc-aip-avatar" "$VIEW"

echo "== 5. Health helper honesty =="
has "healthy state" "'healthy'" "$HEALTH"
has "auth failed state" "auth_failed" "$HEALTH"
has "rate limited state" "rate_limited" "$HEALTH"
has "offline state" "offline" "$HEALTH"
has "next recommended action" "action" "$HEALTH"

echo "== 6. Capabilities honesty (declared, not faked) =="
has "capability keys" "function keys" "$CAPS"
has "model tags (recommended/fastest)" "recommended" "$CAPS"
has "model-dependent honesty value" "Model-dependent" "$CAPS"

echo "== 7. Accessibility + responsive =="
has "role=alert on notice" 'role="alert"' "$VIEW"
has "aria-expanded on toggle" 'aria-expanded' "$VIEW"
has "screen-reader labels for routing" "screen-reader-text" "$VIEW"
has "responsive media query" "@media \(max-width:782px\)" "$VIEW"
has "aria label on score" "aria-label" "$VIEW"

echo "== 8. Preserved anchors (no regression of prior programs) =="
has "honest runtime badges" "USED BY RUNTIME" "$VIEW"
has "stored-only honesty" "STORED ONLY" "$VIEW"
hasnt "key never echoed" "echo .*(wpcc_key|->secret\()" "$VIEW"
hasnt "key input not prefilled" "name=\"wpcc_key\"[^>]*value=" "$VIEW"
has "AI off until key+feature" "AI stays off until you add a key" "$VIEW"

echo "== 9. Functional (wp eval-file) — health derivation =="
PHPF="$(mktemp -t wpcc6s.XXXXXX.php)"
cat > "$PHPF" <<'PHP'
<?php
use WpCommandCenter\Ai\Platform\Health as H;
$p=0;$f=0; $ok=function($c,$n)use(&$p,&$f){if($c){$p++;}else{$f++;echo "FUNC-FAIL: $n\n";}};
// Use a tiny fake store via real ConnectionStore against a throwaway connection.
$store=new \WPCommandCenter\Ai\Platform\ConnectionStore();
// disabled connection → disabled state
$c=['id'=>'x','provider'=>'openai','dialect'=>'openai-compatible','enabled'=>false,'last_test'=>null,'tags'=>[],'model'=>'','endpoint'=>''];
$ok(\WPCommandCenter\Ai\Platform\Health::of($c,$store)['state']==='disabled','disabled health');
// enabled, no key → needs_setup
$c['enabled']=true;
$ok(\WPCommandCenter\Ai\Platform\Health::of($c,$store)['state']==='needs_setup','needs-setup health');
echo ($f===0 ? "FUNC_OK $p" : "FUNC_BAD $f")."\n";
PHP
FUNC_OUT="$(wp eval-file "$PHPF" --path="$WP_PATH" 2>/dev/null)"
rm -f "$PHPF"
echo "$FUNC_OUT" | rg "FUNC-FAIL" | sed 's/^/  /' || true
if echo "$FUNC_OUT" | rg -q "FUNC-FAIL|FUNC_BAD"; then fail "functional health — $(echo "$FUNC_OUT" | rg 'FUNC-FAIL|FUNC_BAD' | head -1)"
elif echo "$FUNC_OUT" | rg -q "FUNC_OK"; then pass "functional health ($(echo "$FUNC_OUT" | rg -o 'FUNC_OK [0-9]+' | awk '{print $2}') checks)"
else fail "functional health — did not complete: $(echo "$FUNC_OUT" | head -c 120)"; fi

echo
echo "== Summary =="
echo "  $P passed, $F failed"
[ "$F" -eq 0 ]
