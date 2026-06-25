#!/usr/bin/env bash
# PROGRAM-9 — Runtime Event Bus. Structural + functional (wp eval-file).
set -uo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$DIR/.." && pwd)"
WP_PATH="$DIR/../../../.."

BUS="$ROOT/includes/Events/EventBus.php"
BRIDGE="$ROOT/includes/Events/EventBridge.php"
FACT="$ROOT/includes/Events/EventFactory.php"
EVT="$ROOT/includes/Events/RuntimeEvent.php"
AUDIT="$ROOT/includes/Security/AuditLog.php"
TELE="$ROOT/includes/Telemetry/TelemetrySubscriber.php"
PLUGIN="$ROOT/includes/Core/Plugin.php"

P=0; F=0
pass(){ P=$((P+1)); echo "  PASS: $1"; }
fail(){ F=$((F+1)); echo "  FAIL: $1"; }
has(){ if rg -q -e "$2" "$3"; then pass "$1"; else fail "$1"; fi; }
hasnt(){ if rg -q -e "$2" "$3"; then fail "$1"; else pass "$1"; fi; }

echo "== 1. Lint =="
for f in "$BUS" "$BRIDGE" "$FACT" "$EVT" "$ROOT/includes/Events/EventCatalog.php" "$PLUGIN"; do
	php -l "$f" >/dev/null 2>&1 && pass "lint $(basename "$f")" || fail "lint $(basename "$f")"
done

echo "== 2. No runtime change; audit authoritative; telemetry unchanged =="
hasnt "AuditLog not modified by Program-9" "PROGRAM-9" "$AUDIT"
hasnt "Telemetry not modified by Program-9" "PROGRAM-9" "$TELE"
has "telemetry still subscribes to the emission point" "add_action\( 'wpcc_audit_recorded'" "$TELE"
has "bridge fed from the SAME emission point (no new runtime emission)" "add_action\( 'wpcc_audit_recorded'" "$BRIDGE"
has "plugin registers the bridge" "EventBridge" "$PLUGIN"

echo "== 3. Bus is fan-out only (records nothing; never duplicates) =="
hasnt "bus records nothing" "update_option|->record\(|wpdb->(insert|update|query)|file_put_contents" "$BUS"
hasnt "bridge records nothing" "update_option|->record\(|wpdb->|file_put_contents" "$BRIDGE"
has "bus guards each subscriber (\\Throwable)" "catch \( \\\\Throwable" "$BUS"
has "bridge guarded" "catch \( \\\\Throwable" "$BRIDGE"
has "bus is additive (no-op when no subscribers)" "no subscribers" "$BUS"

echo "== 4. Functional (wp eval-file) =="
PHPF="$(mktemp -t wpcc9.XXXXXX.php)"
cat > "$PHPF" <<'PHP'
<?php
use WPCommandCenter\Events\EventBus as B;
use WPCommandCenter\Events\EventFactory as Fy;
$p=0;$f=0; $ok=function($c,$n)use(&$p,&$f){if($c){$p++;}else{$f++;echo "FUNC-FAIL: $n\n";}};

// factory normalization (the contract)
$e = Fy::from_audit('operation.seo_manage.completed', ['actor'=>['type'=>'system']], time());
$ok($e->name()==='operation.completed','name = category.verb');
$ok($e->category()==='operation' && $e->verb()==='completed','category+verb parsed');
$ok($e->subject()==='seo_manage','subject extracted');
$ok($e->is_terminal()===true,'completed is terminal');
$ok($e->severity()==='info','completed severity info');
$ok(Fy::from_audit('operation.x.failed',[],time())->severity()==='error','failed severity error');
$ok(Fy::from_audit('change_history.rollback_target',[],time())->category()==='rollback','rollback categorized');
$ok(Fy::from_audit('ai.connection.test',['result'=>'ok'],time())->name()==='connection.test','connection test mapped');
$ok(Fy::from_audit('ai.connection.test',['result'=>'api_error_401'],time())->verb()==='failed','failed test -> failed verb');

// pattern matching
$ok($e->matches('operation.completed') && $e->matches('operation.*') && $e->matches('*'),'matches exact/wildcard/all');
$ok(!$e->matches('connection.*'),'does not match other category');

// pub/sub: exact, wildcard, all + priority order + guarded isolation
B::reset();
$log=[];
B::subscribe('operation.completed', function($ev)use(&$log){ $log[]='exact'; }, 10);
B::subscribe('operation.*',         function($ev)use(&$log){ $log[]='wild'; }, 5);   // higher priority (lower number) → first
B::subscribe('*',                   function($ev)use(&$log){ $log[]='all'; }, 20);
B::subscribe('connection.*',        function($ev)use(&$log){ $log[]='nope'; }, 1);    // should NOT fire
B::subscribe('operation.*',         function($ev){ throw new \RuntimeException('boom'); }, 1); // must not break others
B::publish($e);
$ok(in_array('exact',$log) && in_array('wild',$log) && in_array('all',$log),'all matching subscribers fired');
$ok(!in_array('nope',$log),'non-matching pattern did not fire');
$ok($log[0]==='wild','priority order honored (lower number first; throwing sub isolated)');
$ok(count($log)===3,'exactly the 3 matching, non-throwing handlers ran (guarded isolation)');

// bridge end-to-end: a real audit emission flows to a bus subscriber, ONCE
B::reset();
$count=0; $seen=null;
B::subscribe('operation.completed', function($ev)use(&$count,&$seen){ $count++; $seen=$ev->subject(); });
do_action('wpcc_audit_recorded','operation.evt_test_op.completed',['actor'=>['type'=>'system']],time());
$ok($count===1,'bridge published exactly once (no duplicate)');
$ok($seen==='evt_test_op','bridge delivered the typed event to the subscriber');

// backward compat: telemetry still listening on the same hook
$ok(has_action('wpcc_audit_recorded')!==false,'emission point still has listeners (telemetry + bridge)');

B::reset();
// cleanup any telemetry row the terminal test event created
global $wpdb; $t=$wpdb->prefix.'wpcc_telemetry';
if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$t))===$t){ $wpdb->query("DELETE FROM {$t} WHERE operation='evt_test_op'"); }
echo ($f===0 ? "FUNC_OK $p" : "FUNC_BAD $f")."\n";
PHP
FUNC_OUT="$(wp eval-file "$PHPF" --path="$WP_PATH" 2>/dev/null)"
rm -f "$PHPF"
echo "$FUNC_OUT" | rg "FUNC-FAIL" | sed 's/^/  /' || true
if echo "$FUNC_OUT" | rg -q "FUNC-FAIL|FUNC_BAD"; then fail "functional — $(echo "$FUNC_OUT" | rg 'FUNC-FAIL|FUNC_BAD' | head -1)"
elif echo "$FUNC_OUT" | rg -q "FUNC_OK"; then pass "functional event bus ($(echo "$FUNC_OUT" | rg -o 'FUNC_OK [0-9]+' | awk '{print $2}') checks)"
else fail "functional — did not complete: $(echo "$FUNC_OUT" | head -c 160)"; fi

echo
echo "== Summary =="
echo "  $P passed, $F failed"
[ "$F" -eq 0 ]
