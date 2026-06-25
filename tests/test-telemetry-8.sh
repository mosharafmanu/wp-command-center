#!/usr/bin/env bash
# PROGRAM-8 — runtime telemetry foundation. Structural (read-only/observe-not-change)
# + functional (wp eval-file): store, recorder lifecycle, cost honesty, query, subscriber.
set -uo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$DIR/.." && pwd)"
WP_PATH="$DIR/../../../.."

STORE="$ROOT/includes/Telemetry/TelemetryStore.php"
REC="$ROOT/includes/Telemetry/TelemetryRecorder.php"
COST="$ROOT/includes/Telemetry/CostModel.php"
SUB="$ROOT/includes/Telemetry/TelemetrySubscriber.php"
AUDIT="$ROOT/includes/Security/AuditLog.php"
SCHEMA="$ROOT/includes/Core/Schema.php"

P=0; F=0
pass(){ P=$((P+1)); echo "  PASS: $1"; }
fail(){ F=$((F+1)); echo "  FAIL: $1"; }
has(){ if rg -q -e "$2" "$3"; then pass "$1"; else fail "$1"; fi; }
hasnt(){ if rg -q -e "$2" "$3"; then fail "$1"; else pass "$1"; fi; }

echo "== 1. Lint =="
for f in "$STORE" "$REC" "$COST" "$SUB" "$ROOT/includes/Telemetry/TelemetryQuery.php" "$AUDIT"; do
	php -l "$f" >/dev/null 2>&1 && pass "lint $(basename "$f")" || fail "lint $(basename "$f")"
done

echo "== 2. Observe, don't change (STOP boundaries) =="
has "audit hook is behavior-neutral (fires AFTER write)" "do_action\( 'wpcc_audit_recorded'" "$AUDIT"
has "subscriber self-guards (never throws into runtime)" "catch \( \\\\Throwable" "$SUB"
has "recorder never throws into runtime" "catch \( \\\\Throwable" "$REC"
hasnt "DB_VERSION invariant untouched (telemetry table decoupled)" "DB_VERSION = '2\.[0-9]" "$STORE"
has "schema still 2.5.0" "DB_VERSION = '2.5.0'" "$SCHEMA"
has "telemetry table self-provisions (additive)" "CREATE TABLE IF NOT EXISTS" "$STORE"

echo "== 3. Honesty — unknown, never invented =="
has "cost null when model unpriced" "return null; // unknown model" "$COST"
has "cost null when no tokens" "no measured tokens" "$COST"
has "unmeasured fields stored NULL" "null = unknown" "$STORE"
has "subscriber leaves tokens/cost unknown" "intentionally absent" "$SUB"

echo "== 4. Future-proof provider model =="
has "provider/model are free strings (any provider)" "provider VARCHAR" "$STORE"
has "prices filterable (add providers w/o redesign)" "wpcc_telemetry_prices" "$COST"
has "indexed for dashboards" "KEY provider" "$STORE"
has "prune for growth control" "function prune" "$STORE"

echo "== 5. Functional (wp eval-file) =="
PHPF="$(mktemp -t wpcc8.XXXXXX.php)"
cat > "$PHPF" <<'PHP'
<?php
use WPCommandCenter\Telemetry\TelemetryStore as S;
use WPCommandCenter\Telemetry\TelemetryRecorder as R;
use WPCommandCenter\Telemetry\TelemetryQuery as Q;
use WPCommandCenter\Telemetry\CostModel as C;
$p=0;$f=0; $ok=function($c,$n)use(&$p,&$f){if($c){$p++;}else{$f++;echo "FUNC-FAIL: $n\n";}};

$store=new S(); $store->ensure_table();
$ok($store->exists()===true,'table self-provisioned');

// cost model honesty
$ok(C::estimate_micros('claude-sonnet-4-6',1000,500)===(1000*3+500*15),'cost computed for priced model');
$ok(C::estimate_micros('totally-unknown-model',1000,500)===null,'cost NULL for unpriced model (honest)');
$ok(C::estimate_micros('claude-sonnet-4-6',null,null)===null,'cost NULL when tokens unknown (honest)');
$ok(C::format_micros(null)==='—','unknown cost renders as dash');

// recorder lifecycle: start -> complete derives duration + cost
$rec=new R($store);
$jid='job_test_'.uniqid();
$rec->start($jid,'ai_generation',['operation'=>'tele_test_op','provider'=>'anthropic','model'=>'claude-sonnet-4-6','started_at'=>time()-2]);
$rec->complete($jid,['tokens_input'=>1000,'tokens_output'=>500,'completed_at'=>time(),'started_at'=>time()-2]);
global $wpdb; $t=$store->table();
$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE job_id=%s",$jid),ARRAY_A);
$ok($row && $row['status']==='completed','lifecycle row completed');
$ok($row && (int)$row['duration_ms']>0,'duration derived from timestamps');
$ok($row && $row['estimated_cost_micros']!==null && (int)$row['estimated_cost_micros']===10500,'cost derived from tokens+price');

// honest unknown: a job with no tokens -> cost NULL
$jid2='job_test_'.uniqid();
$rec->record(['job_id'=>$jid2,'operation'=>'tele_test_unknown','provider'=>'openrouter','model'=>'mystery','status'=>'completed','completed_at'=>time()]);
$row2=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE job_id=%s",$jid2),ARRAY_A);
$ok($row2 && $row2['estimated_cost_micros']===null,'unpriced job -> cost NULL (not invented)');
$ok($row2 && $row2['tokens_input']===null,'no tokens -> NULL (unknown, not 0-faked)');

// subscriber: a terminal audit event projects a telemetry row (observe path)
do_action('wpcc_audit_recorded','tele.test.completed',['operation'=>'tele_test_sub','provider'=>'anthropic','duration_ms'=>123,'actor'=>['type'=>'system']],time());
$row3=$wpdb->get_row("SELECT * FROM {$t} WHERE operation='tele_test_sub' ORDER BY id DESC LIMIT 1",ARRAY_A);
$ok($row3 && $row3['status']==='completed' && (int)$row3['duration_ms']===123,'subscriber projected a terminal event with real duration');

// query / dashboard contract
$sum=(new Q($store))->summary(1);
$ok(is_array($sum) && array_key_exists('cost_known',$sum) && array_key_exists('tokens_known',$sum),'summary exposes coverage (known vs unknown)');
$ok(count((new Q($store))->recent(5))>=1,'recent jobs query works');

// cleanup test rows
$wpdb->query("DELETE FROM {$t} WHERE operation LIKE 'tele_test%' OR job_id LIKE 'job_test_%'");
echo ($f===0 ? "FUNC_OK $p" : "FUNC_BAD $f")."\n";
PHP
FUNC_OUT="$(wp eval-file "$PHPF" --path="$WP_PATH" 2>/dev/null)"
rm -f "$PHPF"
echo "$FUNC_OUT" | rg "FUNC-FAIL" | sed 's/^/  /' || true
if echo "$FUNC_OUT" | rg -q "FUNC-FAIL|FUNC_BAD"; then fail "functional — $(echo "$FUNC_OUT" | rg 'FUNC-FAIL|FUNC_BAD' | head -1)"
elif echo "$FUNC_OUT" | rg -q "FUNC_OK"; then pass "functional telemetry ($(echo "$FUNC_OUT" | rg -o 'FUNC_OK [0-9]+' | awk '{print $2}') checks)"
else fail "functional — did not complete: $(echo "$FUNC_OUT" | head -c 160)"; fi

echo
echo "== Summary =="
echo "  $P passed, $F failed"
[ "$F" -eq 0 ]
