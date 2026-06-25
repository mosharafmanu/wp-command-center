#!/usr/bin/env bash
# PROGRAM-7 — AI mission-control activity (read-only experience).
# Structural (lint+rg) + functional (wp eval-file) for the read-only AiActivity helper.
set -uo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$DIR/.." && pwd)"
WP_PATH="$DIR/../../../.."

ACT="$ROOT/includes/Ai/Platform/AiActivity.php"
VIEW="$ROOT/includes/Admin/views/ai-setup.php"

P=0; F=0
pass(){ P=$((P+1)); echo "  PASS: $1"; }
fail(){ F=$((F+1)); echo "  FAIL: $1"; }
has(){ if rg -q -e "$2" "$3"; then pass "$1"; else fail "$1"; fi; }
hasnt(){ if rg -q -e "$2" "$3"; then fail "$1"; else pass "$1"; fi; }

echo "== 1. Lint =="
php -l "$ACT" >/dev/null 2>&1 && pass "lint AiActivity.php" || fail "lint AiActivity.php"
php -l "$VIEW" >/dev/null 2>&1 && pass "lint ai-setup.php" || fail "lint ai-setup.php"

echo "== 2. Helper is read-only + honest =="
hasnt "no writes in activity helper" "update_option|delete_option|->record\(|INSERT|UPDATE |DELETE " "$ACT"
has "reads existing audit log" "AuditLog" "$ACT"
has "pending approvals from existing queue" "wpcc_operation_requests" "$ACT"
has "cost explicitly NOT faked" "cost_tracked.*false|not instrumented" "$ACT"
has "table-exists guard" "SHOW TABLES LIKE" "$ACT"

echo "== 3. Mission control surface =="
has "mission control heading" "Mission control" "$VIEW"
has "recent AI activity feed" "Recent AI activity" "$VIEW"
has "pending approvals KPI" "Pending approvals" "$VIEW"
has "review changes & undo link" "Review changes & undo" "$VIEW"
has "honest cost not-tracked" "Not tracked yet" "$VIEW"
hasnt "no fabricated cost figure (no \$ amounts)" 'cost.*\$[0-9]' "$VIEW"
has "empty state teaches" "When AI or an agent acts" "$VIEW"

echo "== 4. Functional (wp eval-file) — classifier honesty =="
PHPF="$(mktemp -t wpcc7.XXXXXX.php)"
cat > "$PHPF" <<'PHP'
<?php
use WPCommandCenter\Ai\Platform\AiActivity as A;
$p=0;$f=0; $ok=function($c,$n)use(&$p,&$f){if($c){$p++;}else{$f++;echo "FUNC-FAIL: $n\n";}};
$ok(A::categorize('ai.connection.test')==='connection','connection category');
$ok(A::categorize('change_history.rollback_target')==='rollback','rollback category');
$ok(A::categorize('operation.seo_manage.completed')==='generation','seo→generation category');
$ok(A::categorize('mcp.tool.invoke')==='agent','agent category');
$ok(A::categorize('security.mode.changed')==='security','security category');
$ok(A::humanize('ai.connection.test')==='Ai connection test','humanize');
$s=A::summary();
$ok(is_array($s) && array_key_exists('pending_approvals',$s),'summary has pending_approvals');
$ok($s['cost_tracked']===false,'cost not tracked (honest)');
$ok(is_int(A::pending_approvals()),'pending approvals is an int (no fatal)');
$ok(is_array(A::feed(5)),'feed returns array');
echo ($f===0 ? "FUNC_OK $p" : "FUNC_BAD $f")."\n";
PHP
FUNC_OUT="$(wp eval-file "$PHPF" --path="$WP_PATH" 2>/dev/null)"
rm -f "$PHPF"
echo "$FUNC_OUT" | rg "FUNC-FAIL" | sed 's/^/  /' || true
if echo "$FUNC_OUT" | rg -q "FUNC-FAIL|FUNC_BAD"; then fail "functional — $(echo "$FUNC_OUT" | rg 'FUNC-FAIL|FUNC_BAD' | head -1)"
elif echo "$FUNC_OUT" | rg -q "FUNC_OK"; then pass "functional activity ($(echo "$FUNC_OUT" | rg -o 'FUNC_OK [0-9]+' | awk '{print $2}') checks)"
else fail "functional — did not complete: $(echo "$FUNC_OUT" | head -c 120)"; fi

echo
echo "== Summary =="
echo "  $P passed, $F failed"
[ "$F" -eq 0 ]
