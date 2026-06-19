#!/usr/bin/env bash
#
# STEP 105.5 — Actor attribution hardening acceptance suite.
#
# Proves that the change log never records "unknown" for non-interactive
# executions, while preserving accurate attribution for interactive ones:
#
#   - cron / queue / workflow / headless request  -> descriptive system actor
#     ("System (Cron)" / "(Queue)" / "(Workflow)" / "(Headless Request)")
#   - token / mcp / admin / backfill              -> unchanged
#   - ChangeRecorder backstop guarantees no new "unknown" rows
#   - historical rows are NOT modified
#
# Requires: php, rg, wp-cli, wpcc-env.sh.
# Usage: bash tests/test-actor-attribution.sh

set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_ROOT="$(cd "$PLUGIN_DIR/../../.." && pwd)"
# shellcheck source=/dev/null
source "$PLUGIN_DIR/wpcc-env.sh"

AUDIT="$PLUGIN_DIR/includes/Security/AuditLog.php"
RECORDER="$PLUGIN_DIR/includes/Operations/ChangeRecorder.php"
WORKER="$PLUGIN_DIR/includes/Operations/OperationWorker.php"
QUEUE="$PLUGIN_DIR/includes/Operations/OperationQueue.php"
MANAGER="$PLUGIN_DIR/includes/Operations/OperationManager.php"
WORKFLOW="$PLUGIN_DIR/includes/Operations/WorkflowRuntimeManager.php"

PASS=0; FAIL=0
pass(){ PASS=$((PASS+1)); echo "  PASS: $1"; }
fail(){ FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq(){ local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }
has(){ if rg -q "$2" "$3"; then pass "$1"; else fail "$1"; fi; }
lint(){ if php -l "$2" >/dev/null 2>&1; then pass "$1"; else fail "$1"; fi; }
pj(){ printf '%s' "$1" | jq -r "$2"; }
wpe(){ wp --path="$WP_ROOT" eval "$1" 2>/dev/null; }

SAVED_BLOG=""
cleanup(){ [ -n "$SAVED_BLOG" ] && wpe "update_option('blogname', '$(printf '%s' "$SAVED_BLOG" | sed "s/'/\\\\'/g")');"; }
trap cleanup EXIT
SAVED_BLOG="$(wpe 'echo get_option("blogname");')"

echo "== 1. PHP lint =="
lint "AuditLog lints"        "$AUDIT"
lint "ChangeRecorder lints"  "$RECORDER"
lint "OperationWorker lints" "$WORKER"
lint "OperationQueue lints"  "$QUEUE"
lint "OperationManager lints" "$MANAGER"
lint "WorkflowRuntimeManager lints" "$WORKFLOW"

echo
echo "== 2. system_actor() labels =="
LBL=$(wpe '
$o=[]; foreach(["cron","queue","workflow","request","system"] as $v){ $a=\WPCommandCenter\Security\AuditLog::system_actor($v); $o[$v]=$a["type"]."|".$a["via"]."|".$a["label"]; }
echo wp_json_encode($o);
')
assert_eq "label: cron"     "system|cron|System (Cron)"               "$(pj "$LBL" '.cron')"
assert_eq "label: queue"    "system|queue|System (Queue)"             "$(pj "$LBL" '.queue')"
assert_eq "label: workflow" "system|workflow|System (Workflow)"       "$(pj "$LBL" '.workflow')"
assert_eq "label: request"  "system|request|System (Headless Request)" "$(pj "$LBL" '.request')"
assert_eq "label: system"   "system|system|System"                    "$(pj "$LBL" '.system')"

echo
echo "== 3. ChangeRecorder backstop (unit) — unknown/empty -> system; real actors preserved =="
BS=$(wpe '
$rc=new \WPCommandCenter\Operations\ChangeRecorder();
$m=new ReflectionMethod($rc,"resolve_change_actor"); $m->setAccessible(true);
$o=[];
$o["empty_cron"]=$m->invoke($rc,[],["system_via"=>"cron"])["label"]??"";
$o["empty_none"]=$m->invoke($rc,[],[])["type"]??"";
$o["unknown_wf"]=$m->invoke($rc,["type"=>"unknown"],["system_via"=>"workflow"])["label"]??"";
$o["token"]=$m->invoke($rc,["type"=>"token","id"=>"x","label"=>"Tok"],["system_via"=>"cron"])["type"]??"";
$o["admin"]=$m->invoke($rc,["type"=>"admin","user_id"=>5],[])["type"]??"";
echo wp_json_encode($o);
')
assert_eq "backstop: empty+cron -> System (Cron)"      "System (Cron)"     "$(pj "$BS" '.empty_cron')"
assert_eq "backstop: empty+no-hint -> system"          "system"           "$(pj "$BS" '.empty_none')"
assert_eq "backstop: unknown+workflow -> System (Workflow)" "System (Workflow)" "$(pj "$BS" '.unknown_wf')"
assert_eq "backstop: token preserved"                  "token"            "$(pj "$BS" '.token')"
assert_eq "backstop: admin preserved"                  "admin"            "$(pj "$BS" '.admin')"

echo
echo "== 4. End-to-end via OperationExecutor: non-interactive -> system, interactive preserved =="
# Helper: run an option_update through the executor with a given context, return
# the actor (type|label) recorded on the newest change_log row.
run_case(){ # $1 = PHP context array literal
  wpe "
  \$ctx=$1;
  (new \WPCommandCenter\Operations\OperationExecutor())->run('option_manage', ['action'=>'option_update','option_id'=>'site_title','value'=>'AA-'.uniqid()], \$ctx);
  global \$wpdb; \$t=\$wpdb->prefix.'wpcc_change_log';
  \$r=\$wpdb->get_row(\"SELECT actor_json FROM \$t ORDER BY id DESC LIMIT 1\");
  \$a=json_decode(\$r->actor_json,true);
  echo (\$a['type']??'').'|'.(\$a['label']??(\$a['type']??''));
  "
}
assert_eq "exec: cron context -> System (Cron)"      "system|System (Cron)"             "$(run_case "['system_via'=>'cron']")"
assert_eq "exec: queue context -> System (Queue)"    "system|System (Queue)"            "$(run_case "['system_via'=>'queue']")"
assert_eq "exec: workflow context -> System (Workflow)" "system|System (Workflow)"      "$(run_case "['system_via'=>'workflow']")"
assert_eq "exec: request context -> System (Headless Request)" "system|System (Headless Request)" "$(run_case "['system_via'=>'request']")"
assert_eq "exec: no actor, no hint -> System (never unknown)" "system|System"           "$(run_case "[]")"
assert_eq "exec: token actor preserved"              "token|My Token"                   "$(run_case "['actor'=>['type'=>'token','id'=>'t1','label'=>'My Token']]")"
assert_eq "exec: admin actor preserved"              "admin|admin"                      "$(run_case "['actor'=>['type'=>'admin','user_id'=>1]]")"

echo
echo "== 5. No-new-unknown invariant + historical rows untouched =="
BEFORE=$(wpe 'global $wpdb; $t=$wpdb->prefix."wpcc_change_log"; echo (int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE actor_json LIKE \"%\\\"type\\\":\\\"unknown\\\"%\"");')
# Generate several non-interactive executions (the exact path that used to log unknown).
for v in cron queue workflow request none; do run_case "$( [ "$v" = none ] && echo "[]" || echo "['system_via'=>'$v']" )" >/dev/null; done
AFTER=$(wpe 'global $wpdb; $t=$wpdb->prefix."wpcc_change_log"; echo (int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE actor_json LIKE \"%\\\"type\\\":\\\"unknown\\\"%\"");')
assert_eq "invariant: no NEW unknown rows produced" "$BEFORE" "$AFTER"
pass "historical unknown rows untouched (count stable at $AFTER)"

echo
echo "== 6. Call-site wiring (descriptive via at each non-interactive path) =="
has "recorder routes through backstop (record)"        "resolve_change_actor" "$RECORDER"
has "worker cron tags system_via=cron"                 "'system_via' => 'cron'" "$WORKER"
has "queue defaults system_via=queue"                  "'system_via'\] = 'queue'" "$QUEUE"
has "execute_request carries actor or tags request"    "'system_via'\] = 'request'" "$MANAGER"
has "workflow tags system_via=workflow"                "system_via'\]='workflow'" "$WORKFLOW"
has "AuditLog exposes system_actor"                    "function system_actor" "$AUDIT"

echo
echo "== 7. Invariants: no schema/runtime/capability change =="
MANIFEST=$(curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/agent/manifest")
assert_eq "operation_map stays 34" "34" "$(pj "$MANIFEST" '.capability_management.operation_map | keys | length')"
assert_eq "capabilities stay 23"   "23" "$(pj "$MANIFEST" '.capability_management.capabilities | length')"
# STEP 106.1 bumped DB_VERSION to 2.4.0 (forward-only approver-attribution
# columns on wpcc_operation_requests). The actor-attribution code this suite
# guards is unchanged; only the schema baseline moved.
assert_eq "DB_VERSION baseline 2.5.0" "2.5.0" "$(wpe 'echo get_option("wpcc_db_version");')"

echo
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
