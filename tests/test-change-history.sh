#!/usr/bin/env bash
#
# STEP 104.1 — Change-log foundation acceptance suite (record-only).
#
# Verifies the queryable change-log system of record written at the single
# OperationExecutor chokepoint by ChangeRecorder:
#
#   - schema: wpcc_change_log exists, DB_VERSION 2.3.0
#   - a mutating execution records exactly ONE row (success or failure)
#   - read/diagnostic executions record NONE (diagnostic + under-classified
#     low-risk reads content_get/content_list)
#   - rollback linkage: a reversible runtime write records rollback_id +
#     rollback_kind=runtime_option + reversible=1, matching the live response
#   - a failed mutating execution records status=failed, error_count=1
#   - an atomic patch change set records rollback_kind=patch + change_set_id
#   - dual-write: a `change.recorded` JSONL audit event is emitted per row
#
# There is NO new MCP runtime yet (104.2) — this asserts the foundation only.
#
# Requires: curl, jq, wp-cli, wpcc-env.sh (full-scope $WPCC_TOKEN).
# Usage: bash tests/test-change-history.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_ROOT="$(cd "$PLUGIN_DIR/../../.." && pwd)"
PLUGINS_DIR="$WP_ROOT/wp-content/plugins"
AUDIT_LOG="$WP_ROOT/wp-content/uploads/wpcc-audit/audit.log"

# shellcheck source=/dev/null
source "$PLUGIN_DIR/wpcc-env.sh"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }
assert_nonempty() { local d="$1" a="$2"; { [ -n "$a" ] && [ "$a" != "null" ]; } && pass "$d" || fail "$d (empty/null)"; }

pj()   { printf '%s' "$1" | jq -r "$2"; }
rest() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$2" "$WPCC_BASE/operations/$1/run"; }
wpe()  { wp --path="$WP_ROOT" eval "$1" 2>/dev/null; }

# Safe, prepared change-log query helper (reads params from env, prints JSON).
QHELPER="$(mktemp /tmp/wpcc-cl-query-XXXXXX.php)"
cat > "$QHELPER" <<'PHP'
<?php
global $wpdb;
$t      = $wpdb->prefix . 'wpcc_change_log';
$opid   = (string) getenv( 'CL_OPID' );
$action = (string) getenv( 'CL_ACTION' );
$min    = (int) getenv( 'CL_MINID' );

$where  = 'id > %d AND operation_id = %s';
$params = [ $min, $opid ];
if ( '' !== $action ) {
	$where   .= ' AND action = %s';
	$params[] = $action;
}
$rows = $wpdb->get_results(
	$wpdb->prepare( "SELECT * FROM {$t} WHERE {$where} ORDER BY id DESC", $params ),
	ARRAY_A
);
echo wp_json_encode( [ 'count' => count( $rows ), 'latest' => $rows[0] ?? null ] );
PHP

cl_query() { CL_OPID="$1" CL_ACTION="$2" CL_MINID="$3" wp --path="$WP_ROOT" eval-file "$QHELPER" 2>/dev/null; }
cl_maxid() { wpe 'global $wpdb;$t=$wpdb->prefix."wpcc_change_log";echo (int)$wpdb->get_var("SELECT COALESCE(MAX(id),0) FROM $t");'; }

SB="$PLUGINS_DIR/wpcc-ch-sandbox"
SAVED_TITLE=""
cleanup() {
	wpe "update_option('wpcc_security_mode','developer');"
	[ -n "$SAVED_TITLE" ] && wpe "update_option('blogname', '$(printf '%s' "$SAVED_TITLE" | sed "s/'/\\\\'/g")');"
	rm -rf "$SB" 2>/dev/null
	rm -f "$QHELPER" 2>/dev/null
}
trap cleanup EXIT

wpe "update_option('wpcc_security_mode','developer');"
SAVED_TITLE="$(wpe 'echo get_option("blogname");')"

echo "== 1. Foundation: schema + DB version =="

assert_eq "DB version is 2.3.0" "2.3.0" "$(wpe 'echo get_option("wpcc_db_version");')"
assert_eq "wpcc_change_log table exists" "yes" "$(wpe 'global $wpdb;$t=$wpdb->prefix."wpcc_change_log";echo $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$t))?"yes":"no";')"
assert_eq "change_log has 28 columns" "28" "$(wpe 'global $wpdb;$t=$wpdb->prefix."wpcc_change_log";echo count($wpdb->get_col("SHOW COLUMNS FROM $t"));')"

echo
echo "== 2. Diagnostic read records nothing =="

BASE=$(cl_maxid)
rest option_manage '{"action":"option_get","option_id":"site_title"}' >/dev/null
RES=$(cl_query "option_manage" "option_get" "$BASE")
assert_eq "option_get (diagnostic) records 0 rows" "0" "$(pj "$RES" '.count')"

echo
echo "== 3. Under-classified low-risk read records nothing =="

BASE=$(cl_maxid)
rest content_manage '{"action":"content_list"}' >/dev/null
RES=$(cl_query "content_manage" "content_list" "$BASE")
assert_eq "content_list (low read) records 0 rows" "0" "$(pj "$RES" '.count')"

echo
echo "== 4. Mutating reversible write records exactly one applied row =="

BASE=$(cl_maxid)
WRITE=$(rest option_manage '{"action":"option_update","option_id":"site_title","value":"WPCC 104.1 ChangeLog Test"}')
RB_RESP=$(pj "$WRITE" '.rollback_id')
assert_nonempty "live write returned rollback_id" "$RB_RESP"

RES=$(cl_query "option_manage" "option_update" "$BASE")
assert_eq "option_update records exactly 1 row" "1" "$(pj "$RES" '.count')"
assert_eq "row status=applied" "applied" "$(pj "$RES" '.latest.status')"
assert_eq "row runtime=option" "option" "$(pj "$RES" '.latest.runtime')"
assert_eq "row reversible=1" "1" "$(pj "$RES" '.latest.reversible')"
assert_eq "row rollback_kind=runtime_option" "runtime_option" "$(pj "$RES" '.latest.rollback_kind')"
assert_eq "row rollback_id matches live response" "$RB_RESP" "$(pj "$RES" '.latest.rollback_id')"
assert_nonempty "row risk_level recorded" "$(pj "$RES" '.latest.risk_level')"
assert_nonempty "row result_ref linked" "$(pj "$RES" '.latest.result_ref')"
assert_eq "row actor recorded as token" "token" "$(pj "$RES" '.latest.actor_json' | jq -r '.type // empty')"
CHANGE_ID="$(pj "$RES" '.latest.change_id')"
assert_nonempty "row has change_id" "$CHANGE_ID"

echo
echo "== 5. Failed mutating execution records one failed row =="

BASE=$(cl_maxid)
rest content_manage '{"action":"content_update","content_id":999999001,"title":"nope"}' >/dev/null
RES=$(cl_query "content_manage" "content_update" "$BASE")
assert_eq "failed content_update records exactly 1 row" "1" "$(pj "$RES" '.count')"
assert_eq "failed row status=failed" "failed" "$(pj "$RES" '.latest.status')"
assert_eq "failed row error_count=1" "1" "$(pj "$RES" '.latest.error_count')"
assert_eq "failed row reversible=0" "0" "$(pj "$RES" '.latest.reversible')"

echo
echo "== 6. Atomic patch change set records rollback_kind=patch =="

rm -rf "$SB"; mkdir -p "$SB"
printf '<?php\n$ch = "before";\n' > "$SB/file.php"
PATCH_PATH="plugins/wpcc-ch-sandbox/file.php"
FILES=$(jq -nc --arg p "$PATCH_PATH" '[{path:$p,mode:"replace_text",find:"\"before\"",replace:"\"after\""}]')
BASE=$(cl_maxid)
PID=$(pj "$(rest patch_manage "$(jq -nc --argjson f "$FILES" '{action:"patch_create",files:$f,explanation:"104.1 changelog patch"}')")" '.change_set_id')
assert_nonempty "patch_create returned change_set_id" "$PID"
APPLY=$(rest patch_manage "$(jq -nc --arg id "$PID" '{action:"patch_apply",patch_id:$id}')")
assert_eq "patch apply status=applied" "applied" "$(pj "$APPLY" '.change_set_status')"

RES=$(cl_query "patch_manage" "patch_apply" "$BASE")
assert_eq "patch_apply records exactly 1 row" "1" "$(pj "$RES" '.count')"
assert_eq "patch row status=applied" "applied" "$(pj "$RES" '.latest.status')"
assert_eq "patch row rollback_kind=patch" "patch" "$(pj "$RES" '.latest.rollback_kind')"
assert_eq "patch row reversible=1" "1" "$(pj "$RES" '.latest.reversible')"
assert_eq "patch row change_set_id matches patch id" "$PID" "$(pj "$RES" '.latest.change_set_id')"
assert_eq "patch row target_key is the file path" "$PATCH_PATH" "$(pj "$RES" '.latest.target_key')"

echo
echo "== 7. Dual-write: change.recorded audit event emitted =="

if [ ! -r "$AUDIT_LOG" ]; then
	fail "audit log not readable at $AUDIT_LOG"
else
	REC=$(jq -c --arg cid "$CHANGE_ID" 'select(.action == "change.recorded" and .context.change_id == $cid)' "$AUDIT_LOG" | tail -1)
	assert_nonempty "change.recorded event present for the recorded change_id" "$REC"
	assert_eq "audit event runtime=option" "option" "$(echo "$REC" | jq -r '.context.runtime // empty')"
	assert_eq "audit event rollback_kind=runtime_option" "runtime_option" "$(echo "$REC" | jq -r '.context.rollback_kind // empty')"
fi

echo
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"

[ "$FAIL" -eq 0 ]
