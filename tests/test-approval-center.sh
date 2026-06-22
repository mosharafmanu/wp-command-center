#!/usr/bin/env bash
#
# STEP 106.1 — Approval Center (read surface) acceptance suite.
#
# Validates the dedicated wp-admin Approval Center read layer over the existing
# approval engine (STEP 20/78/80) WITHOUT forking the approval logic:
#
#   - PHP lint of every new/changed file
#   - Schema: DB_VERSION bumped to 2.4.0; the four forward-only approver-
#     attribution columns are present in wpcc_operation_requests
#   - OperationManager stamps approver attribution at approve/reject/cancel
#     (both the admin and MCP paths) and records cancelled_at
#   - Admin REST: READABLE history/summary/queue/results/detail routes behind a
#     manage_options + FeatureGate('approval_center') gate; NO new write route
#   - ApprovalAdminQuery is presentation-only (read-only; no INSERT/UPDATE/DELETE)
#   - View: Pending / History / Queue tabs, output rendered through an escaper
#   - Menu: the Approvals page renders the new Approval Center view
#   - Functional (wp-cli): attribution is stamped forward-only (admin -> wp_user,
#     token -> token, no actor -> NULL/"unavailable"); ApprovalAdminQuery history
#     + summary read those rows back correctly
#   - Invariants: operation_map stays 34, capabilities stay 23 (no runtime op,
#     MCP tool, or capability added)
#
# Requires: curl, jq, wp-cli, php, rg, wpcc-env.sh (full-scope $WPCC_TOKEN).
# Usage: bash tests/test-approval-center.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_ROOT="$(cd "$PLUGIN_DIR/../../.." && pwd)"

# shellcheck source=/dev/null
source "$PLUGIN_DIR/wpcc-env.sh"

SCHEMA="$PLUGIN_DIR/includes/Core/Schema.php"
OPMGR="$PLUGIN_DIR/includes/Operations/OperationManager.php"
APPRT="$PLUGIN_DIR/includes/Operations/ApprovalRuntimeManager.php"
RESTAPI="$PLUGIN_DIR/includes/Admin/AdminRestApi.php"
QUERY="$PLUGIN_DIR/includes/Admin/ApprovalAdminQuery.php"
VIEW="$PLUGIN_DIR/includes/Admin/views/approval-center.php"
MENU="$PLUGIN_DIR/includes/Admin/AdminMenu.php"
SHELL="$PLUGIN_DIR/includes/Admin/AppShell.php"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq()  { local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }
assert_true(){ local d="$1" a="$2"; [ "$a" = "true" ] && pass "$d" || fail "$d (got '$a')"; }
assert_ge()  { local d="$1" a="$2" b="$3"; [ "$a" -ge "$b" ] 2>/dev/null && pass "$d" || fail "$d ($a < $b)"; }
has()  { if rg -q "$2" "$3"; then pass "$1"; else fail "$1"; fi; }
lacks(){ if rg -q "$2" "$3"; then fail "$1"; else pass "$1"; fi; }
lint() { if php -l "$2" >/dev/null 2>&1; then pass "$1"; else fail "$1"; fi; }
pj()   { printf '%s' "$1" | jq -r "$2"; }
api()  { curl -s -X "$1" -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE$2"; }
wpe()  { wp --path="$WP_ROOT" eval "$1" 2>/dev/null; }

echo "== 1. PHP lint =="
lint "Schema lints"               "$SCHEMA"
lint "OperationManager lints"     "$OPMGR"
lint "ApprovalRuntimeManager lints" "$APPRT"
lint "AdminRestApi lints"         "$RESTAPI"
lint "ApprovalAdminQuery lints"   "$QUERY"
lint "approval-center view lints" "$VIEW"
lint "AdminMenu lints"            "$MENU"

echo
echo "== 2. Schema: DB 2.4.0 + forward-only attribution columns =="
has "DB_VERSION bumped to 2.5.0"        "DB_VERSION = '2.5.0'"   "$SCHEMA"
has "column resolved_by_label"          "resolved_by_label"      "$SCHEMA"
has "column resolved_by_type"           "resolved_by_type"       "$SCHEMA"
has "column resolved_by_user_id"        "resolved_by_user_id"    "$SCHEMA"
has "column cancelled_at"               "cancelled_at BIGINT"    "$SCHEMA"

echo
echo "== 3. OperationManager attribution stamping =="
has "attribution_columns helper present"   "function attribution_columns" "$OPMGR"
has "update_status takes extra columns"    "array \\\$extra = \[\]"        "$OPMGR"
has "reject_request accepts context"       "function reject_request\( string \\\$request_id, array \\\$context" "$OPMGR"
has "cancel_request accepts context"       "function cancel_request\( string \\\$request_id, array \\\$context" "$OPMGR"
has "cancel records cancelled_at"          "STATUS_CANCELLED, 'cancelled_at'" "$OPMGR"
has "MCP reject passes actor"              "reject_request\( \\\$id, \[ 'actor'" "$APPRT"
has "MCP cancel passes actor"              "cancel_request\( \\\$id, \[ 'actor'" "$APPRT"
has "admin reject passes actor"            "reject_request\( \\\$request_id, \[ 'actor'" "$RESTAPI"

echo
echo "== 4. Admin REST read surface (no new write route) =="
has "history route"        "/admin/approvals/history"          "$RESTAPI"
has "summary route"        "/admin/approvals/summary"          "$RESTAPI"
has "queue route"          "/admin/approvals/queue"            "$RESTAPI"
has "results route"        "/admin/approvals/results"          "$RESTAPI"
has "detail route handler" "function approval_detail"          "$RESTAPI"
has "approval permission gate"  "function check_approval_permission" "$RESTAPI"
has "gate maps surface to FeatureGate key (C1 consolidated gate)" "'approvals'\s*=> 'approval_center'" "$RESTAPI"
has "all new approval read routes are READABLE" "approvals_history" "$RESTAPI"
# The only approval write surfaces are the existing approve/reject + the 106.3
# engine-routed retry; there must be NO direct rollback bypass in this namespace.
lacks "no approvals rollback bypass route" "approvals/.*rollback" "$RESTAPI"

echo
echo "== 5. ApprovalAdminQuery is presentation-only (read-only) =="
has "Admin namespace"           "namespace WPCommandCenter.Admin" "$QUERY"
has "history() method"          "function history"                "$QUERY"
has "summary() method"          "function summary"                "$QUERY"
has "queue() method"            "function queue"                  "$QUERY"
has "detail() method"           "function detail"                 "$QUERY"
has "strips raw contents"       "strip_payload"                   "$QUERY"
lacks "no INSERT"               'wpdb->insert\('                  "$QUERY"
lacks "no UPDATE"               'wpdb->update\('                  "$QUERY"
lacks "no DELETE"               'wpdb->delete\('                  "$QUERY"

echo
echo "== 6. View: tabs + escaping =="
has "Pending tab"               "wpcc-tab-pending"   "$VIEW"
has "History tab"               "wpcc-tab-history"   "$VIEW"
has "Queue tab"                 "wpcc-tab-queue"     "$VIEW"
has "summary bar"               "wpcc-approval-summary" "$VIEW"
has "uses an HTML escaper"      "function escHtml"   "$VIEW"
has "renders unavailable for legacy rows" "i18n.unavailable" "$VIEW"

echo
echo "== 6b. STEP 106.2: detail panel (view) =="
has "detail view param guarded (UUID)"   'preg_match\(.*36.*detail_id' "$VIEW"
has "detail container present"           'id="wpcc-detail"'            "$VIEW"
has "loadDetail renderer"                "function loadDetail"         "$VIEW"
has "request payload section"            "secPayload"                  "$VIEW"
has "change-set section"                 "secChangeset"                "$VIEW"
has "diff section"                       "secDiff"                     "$VIEW"
has "audit trail section"                "wpcc-audit-trail"            "$VIEW"
has "history rows link to detail"        '&view=. \+ escHtml\(r.request_id\)' "$VIEW"
has "back link to approval center"       "Back to Approval Center"     "$VIEW"

echo
echo "== 6c. STEP 106.2: detail endpoint reuses shared DiffRenderer (no fork) =="
has "detail attaches diff payload"       "approval_diff"               "$RESTAPI"
has "diff via shared DiffRenderer"       "DiffRenderer::render_accordion" "$RESTAPI"
has "diff summarize via shared renderer" "DiffRenderer::summarize"     "$RESTAPI"
has "graceful patch_unavailable degrade" "patch_unavailable"           "$RESTAPI"
has "detail returns per-request audit"   "request_audit"               "$QUERY"
has "audit read via AuditLog tail"       "tail\( 500 \)"               "$QUERY"

echo
echo "== 6d. STEP 106.3: queue retry + destructive approve escalation =="
has "queue retry route (CREATABLE)"      "approvals/queue/.*/retry" "$RESTAPI"
has "retry routes through ApprovalRuntimeManager (no bypass)" "ApprovalRuntimeManager\(\)" "$RESTAPI"
has "retry uses queue_retry action"      "'action' => 'queue_retry'"  "$RESTAPI"
has "approve gate classifies destructive" "DestructiveGuard::classify\( \(string\) \\\$row" "$RESTAPI"
has "approve returns confirmation_required" "'confirmation_required' => true" "$RESTAPI"
has "approve folds confirmation into payload" "merge_request_payload"  "$RESTAPI"
has "confirmation gated on hash_equals phrase" "hash_equals\( \(string\) \\\$destructive" "$RESTAPI"
# View
has "confirm modal present"              'id="wpcc-confirm-modal"'    "$VIEW"
has "modal is role=dialog aria-modal"    'role="dialog" aria-modal'   "$VIEW"
has "submitApprove handles confirmation_required" "data.confirmation_required" "$VIEW"
has "retry button renderer"              "function retryButton"       "$VIEW"
has "retry posts to engine route"        "approvals/queue/. \+ qid \+ ./retry" "$VIEW"
has "modal init wired"                   "initModal\(\)"              "$VIEW"
has "retry init wired"                   "initRetry\(\)"              "$VIEW"

echo
echo "== 7. App Shell hosts the Approval Center as Operate › Approvals =="
# Experience Layer: the standalone submenu became the Operate › Approvals tab; the
# 5-C App Shell routes the existing approval-center view via ?wpcc_tab=approvals.
has "Approvals tab renders approval-center view" "'view' => 'approval-center'" "$SHELL"
has "Approvals tab gated by approval_center feature" "'feature' => 'approval_center'" "$SHELL"

echo
echo "== 7b. STEP 106.4 + Experience Layer: IA placement + redirect + FeatureGate + a11y + i18n =="
CH="$PLUGIN_DIR/includes/Admin/views/change-history.php"
# Approvals lives under the Operate section; the legacy slug redirects in (deep-link preserved).
has "Operate section registered"             "'wpcc-operate'"           "$MENU"
has "Approvals tab labeled in shell"         "'Approvals'"              "$SHELL"
has "FeatureGate gates the Approvals tab"    "FeatureGate::allows"      "$SHELL"
has "legacy approval-center slug redirects (map)" "'wpcc-approval-center'    => \[ 'wpcc-operate', 'approvals' \]" "$SHELL"
has "consolidated legacy redirect handler"   "function redirect_legacy_slugs" "$MENU"
has "badge href -> Operate/Approvals"        "page=wpcc-operate&wpcc_tab=approvals" "$MENU"
lacks "no standalone approval-center submenu" "add_submenu_page.*wpcc-approval-center" "$MENU"
# Obsolete view removed
[ ! -f "$PLUGIN_DIR/includes/Admin/views/approvals.php" ] && pass "obsolete approvals.php removed" || fail "approvals.php still present"
# View uses new slug + a11y + i18n
has "view base_url uses new slug"            "page=wpcc-approval-center" "$VIEW"
has "tabs expose aria-current"               'aria-current="page"'       "$VIEW"
has "modal is role=dialog + aria-modal"      'role="dialog" aria-modal'  "$VIEW"
has "modal focus trap (Tab handling)"        "ev.key !== 'Tab'"          "$VIEW"
has "result region is role=status live"      'role="status" aria-live'   "$VIEW"
has "table headers carry scope=col"          '<th scope="col">'          "$VIEW"
has "risk labels localized (not raw JS)"     "__\( 'High Risk'"          "$VIEW"
has "status labels localized map"            "statusLabels = \{"         "$VIEW"
has "unset lifecycle timestamps suppressed"  "function tsRow"            "$VIEW"
has "nonce-expiry (403) error state"         "nonceExpired"              "$VIEW"
lacks "no raw English risk labels in JS"     "critical: 'Critical'"      "$VIEW"
# Cross-view consistency
has "change-history links to new slug"       "page=wpcc-approval-center" "$CH"
lacks "change-history drops stale Pending Approvals label" "Open Pending Approvals" "$CH"

echo
echo "== 8. Invariants: no runtime/MCP/capability additions =="
MANIFEST=$(api GET /agent/manifest)
assert_eq "operation_map stays 34" "34" "$(pj "$MANIFEST" '.capability_management.operation_map | keys | length')"
assert_eq "capabilities stay 23"   "23" "$(pj "$MANIFEST" '.capability_management.capabilities | length')"

echo
echo "== 9. Functional: forward-only attribution + admin-query read-back =="
RESULT=$(wpe '
\WPCommandCenter\Core\Schema::install();
global $wpdb;
$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}wpcc_operation_requests" );
$have = ( in_array("resolved_by_label",$cols,true) && in_array("resolved_by_type",$cols,true)
       && in_array("resolved_by_user_id",$cols,true) && in_array("cancelled_at",$cols,true) ) ? "yes" : "no";

$m = new \WPCommandCenter\Operations\OperationManager();
$mk = function() use ($m){
  $r = $m->create_request("option_manage", ["action"=>"option_update","option_name"=>"wpcc_t","reason"=>"t"], ["actor"=>[]]);
  return is_array($r) ? $r["request_id"] : "";
};

// admin actor -> wp_user
$a = $mk();
$m->reject_request($a, ["actor"=>["type"=>"admin","wp_user_id"=>1,"user_login"=>"admin"]]);
$ra = $m->get_request($a);

// token actor -> token
$b = $mk();
$m->reject_request($b, ["actor"=>["type"=>"mcp","token_id"=>"abcdef1234567890"]]);
$rb = $m->get_request($b);

// no actor -> NULL (forward-only)
$c = $mk();
$m->reject_request($c);
$rc = $m->get_request($c);

// A still-pending request must NEVER appear in History (option 1(a)).
$p = $mk();
$q = new \WPCommandCenter\Admin\ApprovalAdminQuery();
$h = $q->history(["status"=>["rejected"]], 100, 0);
$found_admin = false; $found_token = false; $found_null = false;
foreach ($h["requests"] as $row) {
  if ($row["request_id"]===$a) $found_admin = ($row["resolved_by"]==="admin" && $row["resolved_type"]==="wp_user");
  if ($row["request_id"]===$b) $found_token = ($row["resolved_type"]==="token" && strpos((string)$row["resolved_by"],"Token ")===0);
  if ($row["request_id"]===$c) $found_null  = ($row["resolved_by"]===null);
}

// Unfiltered history excludes pending_review; explicit pending filter is ignored.
$h_all  = $q->history([], 200, 0);
$h_pend = $q->history(["status"=>["pending_review"]], 200, 0);
$pending_in_default  = false; $pending_in_explicit = false; $default_only_resolved = true;
$resolved = ["approved","rejected","executed","failed","cancelled"];
foreach ($h_all["requests"] as $row) {
  if ($row["request_id"]===$p) $pending_in_default = true;
  if (!in_array($row["status"],$resolved,true)) $default_only_resolved = false;
}
foreach ($h_pend["requests"] as $row) {
  if ($row["status"]==="pending_review") $pending_in_explicit = true;
}
$s = $q->summary();
$summary_ok = (is_int($s["pending"]) && is_int($s["resolved"]) && is_int($s["pending_critical"]) && is_int($s["queue_failed"])) ? "yes":"no";

// 106.4 — FeatureGate seam behavior for approval_center (ungated today; flips via filter).
$fg_default = \WPCommandCenter\Admin\FeatureGate::allows("approval_center") ? "yes":"no";
add_filter("wpcc_feature_allowed", function($allowed,$feature){ return $feature==="approval_center" ? false : $allowed; }, 10, 2);
$fg_gated = \WPCommandCenter\Admin\FeatureGate::allows("approval_center") ? "yes":"no";
remove_all_filters("wpcc_feature_allowed");

// Detail endpoint (106.2): structure + per-request audit trail (oldest-first).
$d = $mk();
$al = new \WPCommandCenter\Security\AuditLog();
$al->record("operation.request.created",  ["request_id"=>$d,"actor"=>["type"=>"admin","user_login"=>"mosharaf"]]);
$al->record("operation.request.approved", ["request_id"=>$d,"actor"=>["type"=>"admin","user_login"=>"mosharaf"]]);
$detail = $q->detail($d);
$detail_keys = (array_key_exists("request",$detail) && array_key_exists("payload",$detail) && array_key_exists("queue_items",$detail) && array_key_exists("results",$detail) && array_key_exists("change_set",$detail) && array_key_exists("audit",$detail)) ? "yes":"no";
$audit_ok = (is_array($detail["audit"]) && count($detail["audit"])>=2
  && $detail["audit"][0]["action"]==="operation.request.created"
  && $detail["audit"][0]["actor"]==="mosharaf") ? "yes":"no";
$detail_missing = ($q->detail("00000000-0000-0000-0000-000000000000")===null) ? "yes":"no";

// 106.3 — destructive approve escalation (handle_action is admin/cookie; public, call directly).
rest_get_server();
wp_set_current_user(1);
$api = new \WPCommandCenter\Admin\AdminRestApi();
$RT = $wpdb->prefix."wpcc_operation_requests";
$dr = wp_generate_uuid4();
$wpdb->insert($RT,["request_id"=>$dr,"operation_id"=>"user_manage","status"=>"pending_review","payload"=>wp_json_encode(["action"=>"user_delete","user_id"=>999999,"reason"=>"spam"]),"risk_level"=>"critical","created_at"=>time()]);
$st = function($id) use($wpdb,$RT){ return $wpdb->get_var($wpdb->prepare("SELECT status FROM $RT WHERE request_id=%s",$id)); };
// (1) no confirmation -> blocked, request untouched
$r1=new WP_REST_Request("POST","/x"); $r1->set_param("id",$dr);
$d1=$api->handle_action($r1,"approve")->get_data();
$gate_blocks=(!empty($d1["confirmation_required"]) && ($d1["status"]??"")==="confirmation_required" && $st($dr)==="pending_review")?"yes":"no";
// (2) wrong phrase -> still blocked
$r2=new WP_REST_Request("POST","/x"); $r2->set_param("id",$dr); $r2->set_param("confirm",true); $r2->set_param("confirmation_phrase","WRONG"); $r2->set_param("reason","x");
$d2=$api->handle_action($r2,"approve")->get_data();
$wrong_blocks=(!empty($d2["confirmation_required"]) && $st($dr)==="pending_review")?"yes":"no";
// (3) correct phrase+reason -> gate opens (leaves pending); fake user id => harmless execution
$r3=new WP_REST_Request("POST","/x"); $r3->set_param("id",$dr); $r3->set_param("confirm",true); $r3->set_param("confirmation_phrase","DELETE_USER"); $r3->set_param("reason","confirmed spam");
$api->handle_action($r3,"approve");
$gate_opens=($st($dr)!=="pending_review")?"yes":"no";
$paf=json_decode((string)$wpdb->get_var($wpdb->prepare("SELECT payload FROM $RT WHERE request_id=%s",$dr)),true);
$payload_confirmed=(!empty($paf["confirm"]) && ($paf["confirmation_phrase"]??"")==="DELETE_USER")?"yes":"no";

// 106.3 — queue retry round-trip + human-approver guard (pure engine reuse).
$QT=$wpdb->prefix."wpcc_operation_queue";
$qrid=wp_generate_uuid4(); $qqid=wp_generate_uuid4();
$wpdb->insert($RT,["request_id"=>$qrid,"operation_id"=>"option_manage","status"=>"approved","payload"=>wp_json_encode(["action"=>"option_update"]),"risk_level"=>"low","created_at"=>time()]);
$wpdb->insert($QT,["queue_id"=>$qqid,"request_id"=>$qrid,"operation_id"=>"option_manage","status"=>"failed","priority"=>10,"attempts"=>1,"max_attempts"=>3,"payload"=>wp_json_encode(["action"=>"option_update"]),"created_at"=>time()]);
$arm=new \WPCommandCenter\Operations\ApprovalRuntimeManager();
$retry=$arm->run(["action"=>"queue_retry","queue_id"=>$qqid],["actor"=>["wp_user_id"=>1,"user_login"=>"admin"]]);
$retry_requeued=(is_array($retry) && ($retry["item"]["status"]??"")==="queued")?"yes":"no";
$savemode=get_option("wpcc_security_mode","");
update_option("wpcc_security_mode","client");
$wpdb->update($QT,["status"=>"failed"],["queue_id"=>$qqid]);
$rtok=$arm->run(["action"=>"queue_retry","queue_id"=>$qqid],["actor"=>["type"=>"mcp","token_id"=>"agent"]]);
$guard_blocks_token=(is_wp_error($rtok) && $rtok->get_error_code()==="wpcc_approval_requires_human")?"yes":"no";
update_option("wpcc_security_mode",$savemode!==""?$savemode:"developer");

// cleanup
foreach ([$a,$b,$c,$p,$d,$dr,$qrid] as $rid) {
  $wpdb->delete($wpdb->prefix."wpcc_operation_requests", ["request_id"=>$rid]);
  $wpdb->delete($wpdb->prefix."wpcc_operation_queue", ["request_id"=>$rid]);
}

echo json_encode([
  "have_cols"     => $have,
  "admin_label"   => $ra["resolved_by_label"] ?? null,
  "admin_type"    => $ra["resolved_by_type"] ?? null,
  "admin_uid"     => (int)($ra["resolved_by_user_id"] ?? 0),
  "admin_cancelled_at" => $ra["cancelled_at"],
  "admin_rejected_at_set" => !empty($ra["rejected_at"]),
  "token_type"    => $rb["resolved_by_type"] ?? null,
  "token_label_prefix" => substr((string)($rb["resolved_by_label"] ?? ""),0,6),
  "null_label"    => $rc["resolved_by_label"],
  "found_admin"   => $found_admin,
  "found_token"   => $found_token,
  "found_null"    => $found_null,
  "summary_ok"    => $summary_ok,
  "pending_in_default"     => $pending_in_default,
  "pending_in_explicit"    => $pending_in_explicit,
  "default_only_resolved"  => $default_only_resolved,
  "detail_keys"            => $detail_keys,
  "audit_ok"               => $audit_ok,
  "detail_missing"         => $detail_missing,
  "gate_blocks"            => $gate_blocks,
  "wrong_blocks"           => $wrong_blocks,
  "gate_opens"             => $gate_opens,
  "payload_confirmed"      => $payload_confirmed,
  "retry_requeued"         => $retry_requeued,
  "guard_blocks_token"     => $guard_blocks_token,
  "fg_default"             => $fg_default,
  "fg_gated"               => $fg_gated,
]);
')

assert_eq "attribution columns exist on table"  "yes"     "$(pj "$RESULT" '.have_cols')"
assert_eq "admin reject -> label admin"          "admin"   "$(pj "$RESULT" '.admin_label')"
assert_eq "admin reject -> type wp_user"         "wp_user" "$(pj "$RESULT" '.admin_type')"
assert_eq "admin reject -> user id 1"            "1"       "$(pj "$RESULT" '.admin_uid')"
assert_eq "reject does not set cancelled_at"     "null"    "$(pj "$RESULT" '.admin_cancelled_at')"
assert_true "reject stamps rejected_at"          "$(pj "$RESULT" '.admin_rejected_at_set')"
assert_eq "token reject -> type token"           "token"   "$(pj "$RESULT" '.token_type')"
assert_eq "token reject -> label 'Token '"       "Token "  "$(pj "$RESULT" '.token_label_prefix')"
assert_eq "no-actor reject -> NULL (forward-only)" "null"  "$(pj "$RESULT" '.null_label')"
assert_true "history surfaces admin attribution" "$(pj "$RESULT" '.found_admin')"
assert_true "history surfaces token attribution" "$(pj "$RESULT" '.found_token')"
assert_true "history surfaces NULL as unattributed" "$(pj "$RESULT" '.found_null')"
assert_eq "summary returns integer counts"       "yes"     "$(pj "$RESULT" '.summary_ok')"
assert_eq "history (default) excludes pending_review"  "false" "$(pj "$RESULT" '.pending_in_default')"
assert_true "history (default) only resolved statuses"  "$(pj "$RESULT" '.default_only_resolved')"
assert_eq "explicit pending filter is ignored in history" "false" "$(pj "$RESULT" '.pending_in_explicit')"
assert_eq "detail returns full structure"        "yes"     "$(pj "$RESULT" '.detail_keys')"
assert_eq "detail per-request audit (oldest-first, attributed)" "yes" "$(pj "$RESULT" '.audit_ok')"
assert_eq "detail of unknown id returns null"    "yes"     "$(pj "$RESULT" '.detail_missing')"
assert_eq "destructive approve blocked w/o confirmation (no state change)" "yes" "$(pj "$RESULT" '.gate_blocks')"
assert_eq "destructive approve blocked on wrong phrase" "yes" "$(pj "$RESULT" '.wrong_blocks')"
assert_eq "destructive approve proceeds with phrase+reason" "yes" "$(pj "$RESULT" '.gate_opens')"
assert_eq "confirmation folded into stored payload" "yes" "$(pj "$RESULT" '.payload_confirmed')"
assert_eq "queue retry re-queues failed item (engine reuse)" "yes" "$(pj "$RESULT" '.retry_requeued')"
assert_eq "retry human-approver guard blocks token in client mode" "yes" "$(pj "$RESULT" '.guard_blocks_token')"
assert_eq "FeatureGate ungated by default (approval_center)" "yes" "$(pj "$RESULT" '.fg_default')"
assert_eq "FeatureGate filter can gate approval_center"     "no"  "$(pj "$RESULT" '.fg_gated')"

# CDS Scope 2 — risk colors must stay token-driven (no hardcoded risk hex).
# Scoped to markup + inline <style>; the byte-identical <script> block keeps
# muted-grey JS literals that are NOT risk colors and are intentionally retained.
RISK_HEX="$(awk '/^<script>/{s=1} /^<\/script>/{s=0;next} !s{print}' "$VIEW" | grep -ciE '#d63638|#dba617|#72aee6|#00a32a|#b32d2e' || true)"
assert_eq "no hardcoded risk hex in approval-center.php (CDS Scope 2)" "0" "$RISK_HEX"
RISK_TOKENS="$(grep -cE 'var\(--wpcc-risk-(critical|high|medium|low|diagnostic)-fg\)' "$VIEW" || true)"
assert_true "risk tiers use CDS risk-semantic tokens" "$([ "${RISK_TOKENS:-0}" -ge 5 ] && echo true || echo false)"

echo
echo "========================================"
echo "  RESULTS: $PASS passed, $FAIL failed"
echo "========================================"
[ "$FAIL" -eq 0 ]
