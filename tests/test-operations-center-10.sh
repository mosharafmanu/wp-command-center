#!/usr/bin/env bash
# PROGRAM-10 — Live Operations Center. Structural + functional (wp eval-file).
set -uo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$DIR/.." && pwd)"
WP_PATH="$DIR/../../../.."

Q="$ROOT/includes/Admin/OperationsCenterQuery.php"
V="$ROOT/includes/Admin/views/operations-center.php"
SHELL_F="$ROOT/includes/Admin/AppShell.php"

P=0; F=0
pass(){ P=$((P+1)); echo "  PASS: $1"; }
fail(){ F=$((F+1)); echo "  FAIL: $1"; }
has(){ if rg -q -e "$2" "$3"; then pass "$1"; else fail "$1"; fi; }
hasnt(){ if rg -q -e "$2" "$3"; then fail "$1"; else pass "$1"; fi; }

echo "== 1. Lint =="
for f in "$Q" "$V" "$SHELL_F"; do php -l "$f" >/dev/null 2>&1 && pass "lint $(basename "$f")" || fail "lint $(basename "$f")"; done

echo "== 2. Existing data only; no writes; no runtime change =="
hasnt "query writes nothing" "update_option|->record\(|wpdb->(insert|update|delete|query)|file_put_contents" "$Q"
has "uses TelemetryQuery (P8)" "TelemetryQuery" "$Q"
has "uses AiActivity (P7 audit/approvals)" "AiActivity" "$Q"
has "uses ChangeHistoryAdminQuery (reversible)" "ChangeHistoryAdminQuery" "$Q"
has "reversible source is FeatureGate-guarded" "FeatureGate::allows\( 'change_history' \)" "$Q"

echo "== 3. Honesty — no fabricated metrics/states =="
has "cost explicitly not tracked" "'cost_tracked'     => false" "$Q"
has "view shows cost not tracked" "Not tracked yet" "$V"
hasnt "no fabricated cost figure" 'Cost.*\$[0-9]' "$V"
has "running shown only from real telemetry" "running.*=> .int. \\\$s\['running'\]|'running'   => \(int\) \\\$s\['running'\]" "$Q"
has "duration unknown surfaced honestly" "'unknown', 'wp-command-center'" "$V"
has "audit fallback marks duration not measured" "duration not measured" "$V"

echo "== 4. Required sections present =="
has "needs attention" "Needs attention" "$V"
has "operations timeline" "Operations timeline" "$V"
has "review & undo" "Review & undo" "$V"
has "system activity" "System activity" "$V"
has "data coverage / honesty" "Data coverage" "$V"

echo "== 5. Honest empty states =="
has "empty: no operations" "No operations recorded yet" "$V"
has "empty: no reversible" "No reversible changes recorded yet" "$V"
has "all clear state" "All clear" "$V"

echo "== 6. Safety — escaping, link, nav, access =="
has "operation output escaped" "esc_html\( \\\$row\['operation'\]" "$V"
has "session id url-safe" "rawurlencode\( \(string\) \\\$s\['session_id'\] \)" "$V"
has "review link to change history sessions" "wpcc-audit&wpcc_tab=changes&tab=sessions" "$V"
has "tab registered under Operate" "'view' => 'operations-center'" "$SHELL_F"
has "legacy slug mapped" "'wpcc-operations-center'" "$SHELL_F"

echo "== 7. Functional (wp eval-file) =="
PHPF="$(mktemp -t wpcc10.XXXXXX.php)"
cat > "$PHPF" <<'PHP'
<?php
use WPCommandCenter\Admin\OperationsCenterQuery as OC;
use WPCommandCenter\Telemetry\TelemetryStore as S;
$p=0;$f=0; $ok=function($c,$n)use(&$p,&$f){if($c){$p++;}else{$f++;echo "FUNC-FAIL: $n\n";}};

$oc=new OC();
// shapes (must not fatal even if telemetry empty)
$na=$oc->needs_attention();
$ok(is_array($na) && array_key_exists('pending_approvals',$na) && is_array($na['failures']),'needs_attention shape');
$tl=$oc->timeline(10);
$ok(is_array($tl) && in_array($tl['source'],['telemetry','audit'],true) && is_array($tl['rows']),'timeline shape + honest source');
$st=$oc->status_rollup();
$ok(is_int($st['completed']) && is_int($st['failed']) && is_int($st['running']),'status rollup ints');
$ok(is_array($oc->reversible(5)),'reversible returns array (guarded)');
$h=$oc->honesty();
$ok($h['cost_tracked']===false,'cost NOT tracked (honest)');

// seed real telemetry: a failed + a completed job -> they surface honestly
$store=new S(); $store->ensure_table(); global $wpdb; $t=$store->table();
$store->insert(['job_id'=>'oc_test_fail','kind'=>'operation','operation'=>'oc_test_op','provider'=>'anthropic','model'=>'claude-sonnet-4-6','status'=>'failed','error_code'=>'api_error_500','completed_at'=>time(),'duration_ms'=>420]);
$store->insert(['job_id'=>'oc_test_done','kind'=>'operation','operation'=>'oc_test_done_op','provider'=>'anthropic','status'=>'completed','completed_at'=>time(),'duration_ms'=>180]);
$na2=$oc->needs_attention();
$ok(count(array_filter($na2['failures'],fn($r)=>$r['operation']==='oc_test_op'))===1,'failed job surfaces in needs_attention');
$tl2=$oc->timeline(10);
$ok($tl2['source']==='telemetry','timeline prefers real telemetry when present');
$ok(count(array_filter($tl2['rows'],fn($r)=>$r['operation']==='oc_test_done_op' && $r['duration_ms']===180))===1,'completed job shows real duration');
$ok($oc->status_rollup()['failed']>=1,'status rollup counts the failure');

// cleanup
$wpdb->query("DELETE FROM {$t} WHERE job_id IN ('oc_test_fail','oc_test_done')");
echo ($f===0 ? "FUNC_OK $p" : "FUNC_BAD $f")."\n";
PHP
FUNC_OUT="$(wp eval-file "$PHPF" --path="$WP_PATH" 2>/dev/null)"
rm -f "$PHPF"
echo "$FUNC_OUT" | rg "FUNC-FAIL" | sed 's/^/  /' || true
if echo "$FUNC_OUT" | rg -q "FUNC-FAIL|FUNC_BAD"; then fail "functional — $(echo "$FUNC_OUT" | rg 'FUNC-FAIL|FUNC_BAD' | head -1)"
elif echo "$FUNC_OUT" | rg -q "FUNC_OK"; then pass "functional ops center ($(echo "$FUNC_OUT" | rg -o 'FUNC_OK [0-9]+' | awk '{print $2}') checks)"
else fail "functional — did not complete: $(echo "$FUNC_OUT" | head -c 160)"; fi

echo
echo "== Summary =="
echo "  $P passed, $F failed"
[ "$F" -eq 0 ]
