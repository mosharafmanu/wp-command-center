#!/usr/bin/env bash
#
# AI Content (Title & Excerpt) Builder — view-only static assertions.
#
# Asserts the AI Content Builder view (Suggestions + Applied tabs) is a THIN REST
# client over the EXISTING governed proposal/history routes ONLY — it introduces NO
# backend, NO new REST route / operation / capability / MCP tool / schema, and writes
# nothing directly. It reuses the per-proposal list/PUT/apply/dismiss routes and the
# governed change-history rollback for Undo, drives the content_manage /
# content_title|content_excerpt data model, applies persist-before-apply, and uses a
# mode-aware apply label whose outcome is read from the response. Invariants frozen.
#
# Requires: wp-cli (for the invariant block only; static checks run without it).

set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_ROOT="$(cd "$PLUGIN_DIR/../../.." && pwd)"
cd "$PLUGIN_DIR"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }
has()  { grep -qF -- "$2" "$3" && pass "$1" || fail "$1 (missing '$2')"; }
lacks(){ grep -qF -- "$2" "$3" && fail "$1 (found '$2')" || pass "$1"; }
wpe() { wp --path="$WP_ROOT" eval "$1" 2>/dev/null; }

VIEW="$PLUGIN_DIR/includes/Admin/views/ai-content.php"

echo "AI Content Builder — view-only static assertions"

echo
echo "== 1. View exists, both tabs present =="
has  "view file present"                    "wpcc-aic" "$VIEW"
has  "Suggestions tab present"              "wpcc-aic-tab-suggestions"   "$VIEW"
has  "Suggestions panel present"            "wpcc-aic-panel-suggestions" "$VIEW"
has  "Applied tab present"                  "wpcc-aic-tab-applied"       "$VIEW"
has  "Applied panel present"                "wpcc-aic-panel-applied"     "$VIEW"
has  "Kind filter present"                  "wpcc-aic-kind"              "$VIEW"

echo
echo "== 2. Data model: content_manage + content_title/content_excerpt =="
has  "operation_id content_manage"          "content_manage"             "$VIEW"
has  "target_type content_title"            "content_title"              "$VIEW"
has  "target_type content_excerpt"          "content_excerpt"            "$VIEW"
has  "draft action content_update"          "content_update"             "$VIEW"
has  "final_payload edits"                  "final_payload"              "$VIEW"
has  "prior current value"                  "p.prior"                    "$VIEW"

echo
echo "== 3. ONLY the allowed governed routes appear =="
has  "loads content_manage drafts"          "status=draft&operation_id=" "$VIEW"
has  "list reads operation_id=content_manage" "OP = 'content_manage'"    "$VIEW"
has  "edit via PUT (proposals/{id})"        "method: 'PUT'"              "$VIEW"
has  "apply via existing /apply route"      "/apply"                     "$VIEW"
has  "dismiss via /dismiss route"           "/dismiss"                   "$VIEW"
has  "Undo via /history/{cid}/rollback"     "/history/"                  "$VIEW"
has  "core REST posts enrichment"           "/posts?include="            "$VIEW"
has  "core REST pages enrichment"           "/pages?include="            "$VIEW"
# Exactly ONE rollback fetch — a single governed path, no second rollback added.
assert_eq "exactly one rollback fetch in view" "1" "$(grep -c "/history/' + encodeURIComponent( cid ) + '/rollback'" "$VIEW")"

echo
echo "== 4. NO new route / executor / SEO / ajax / direct write =="
lacks "no /admin/seo/ route"                "/admin/seo/"                "$VIEW"
lacks "no /admin/content/generate route"    "/admin/content/generate"    "$VIEW"
lacks "no /seo/generate route"              "/seo/generate"              "$VIEW"
lacks "no OperationExecutor"                "OperationExecutor"          "$VIEW"
lacks "no SeoProvider"                      "SeoProvider"                "$VIEW"
lacks "no admin-ajax"                       "admin-ajax"                 "$VIEW"
lacks "no Approval Center link"             "wpcc-approval-center"       "$VIEW"
lacks "no Change History link"             "wpcc-change-history"        "$VIEW"
lacks "no SelectionResolver"               "SelectionResolver"          "$VIEW"
lacks "no ContentManager direct write"     "ContentManager"             "$VIEW"

echo
echo "== 5. Persist-before-apply (PUT before /apply) =="
has  "shared persistRow helper"             "function persistRow"        "$VIEW"
has  "persistRow uses governed PUT route"   "method: 'PUT'"              "$VIEW"
has  "Apply persists before applying"       "persistRow( id, row, tid, field )" "$VIEW"
has  "no apply on persist failure"          "do NOT apply stale data"    "$VIEW"
has  "final_payload built from visible row" "function rowFinalPayload"   "$VIEW"

echo
echo "== 6. Mode-aware apply label; outcome from response =="
has  "mode-aware (MODE const)"              "const MODE"                 "$VIEW"
has  "developer label"                      "Approve & Apply"            "$VIEW"
has  "gated label"                          "Submit for approval"        "$VIEW"
has  "label chosen by IS_DEV"               "IS_DEV ? STR.applyDev : STR.applyGate" "$VIEW"
has  "outcome read from response status"    "pending_approval"           "$VIEW"
has  "applied outcome read from response"   "st === 'applied'"           "$VIEW"

echo
echo "== 7. Applied tab: segmented single-status paginated + Undo =="
has  "segment control present"              "wpcc-aic-ap-segbar"         "$VIEW"
has  "Applied segment"                      'data-seg="applied"'         "$VIEW"
has  "Awaiting approval segment"            'data-seg="pending_approval"' "$VIEW"
has  "Failed segment"                       'data-seg="failed"'          "$VIEW"
has  "default segment = applied"            "apSeg = 'applied'"          "$VIEW"
has  "single-status paginated read"         "status=' + encodeURIComponent( apSeg )" "$VIEW"
has  "consumes canonical total_count"       "d.total_count"              "$VIEW"
has  "consumes has_more"                    "d.has_more"                 "$VIEW"
has  "Showing X-Y of N status"              "STR.pageInfo.replace"       "$VIEW"
has  "Undo control present"                 "wpcc-aic-undo"              "$VIEW"
has  "rollback-aware Reverted state"        "change_status"             "$VIEW"

echo
echo "== 8. Editable fields, char counts, attribution, edited indicator =="
has  "editable title input"                 "wpcc-aic-et"                "$VIEW"
has  "editable excerpt textarea"            "wpcc-aic-ed"                "$VIEW"
has  "char count element"                   "wpcc-aic-cc"                "$VIEW"
has  "title advisory target"                "TITLE_MAX = 60"             "$VIEW"
has  "excerpt advisory target"              "EXCERPT_MAX"                "$VIEW"
has  "provider attribution"                 "Suggested by AI"            "$VIEW"
has  "edited indicator"                     "wpcc-aic-edited"            "$VIEW"
has  "save control"                         "wpcc-aic-save"              "$VIEW"
has  "dismiss control"                      "wpcc-aic-dismiss"           "$VIEW"
has  "empty state"                          "No suggestions yet"         "$VIEW"

echo
echo "== 9. Notices: contextual entry args =="
has  "reads wpcc_content_gen arg"           "wpcc_content_gen"           "$VIEW"
has  "reads wpcc_content_bulk arg"          "wpcc_content_bulk"          "$VIEW"
has  "reads kind arg"                       "sp.get( 'kind' )"           "$VIEW"
has  "no_provider links AI Integrations"    "wpcc-ai-integrations"       "$VIEW"

echo
echo "== 10. Config injection + escaping helpers =="
has  "ABSPATH guard"                        "defined( 'ABSPATH' ) || exit;" "$VIEW"
has  "rest_url namespaced base"             "rest_url( 'wp-command-center/v1/admin' )" "$VIEW"
has  "fresh wp_rest nonce"                  "wp_create_nonce( 'wp_rest' )" "$VIEW"
has  "security mode injected"               "SecurityModeManager::current()" "$VIEW"
has  "esc_html__ used"                      "esc_html__"                 "$VIEW"
has  "esc_url used"                         "esc_url"                    "$VIEW"
has  "esc_attr used"                        "esc_attr"                   "$VIEW"
has  "text domain wp-command-center"        "wp-command-center"          "$VIEW"
has  "client-side esc() helper"             "const esc ="                "$VIEW"
has  "role=status live region"              'role="status"'             "$VIEW"

echo
echo "== 11. Invariants unchanged (no new op/cap/tool/schema) =="
if ! command -v wp >/dev/null 2>&1; then
	echo "  SKIP: wp-cli not available — static checks only."
else
	assert_eq "OPERATION_MAP == 34" "34" "$(wpe 'echo count(\WPCommandCenter\Operations\CapabilityRegistry::OPERATION_MAP);')"
	assert_eq "capabilities == 23"  "23" "$(wpe 'echo count(\WPCommandCenter\Operations\CapabilityRegistry::ALL_CAPABILITIES);')"
	assert_eq "catalogue == 40"     "40" "$(wpe 'echo count((new \WPCommandCenter\Operations\OperationRegistry())->get_operations());')"
	assert_eq "DB_VERSION 2.5.0"    "2.5.0" "$(wpe 'echo \WPCommandCenter\Core\Schema::DB_VERSION;')"
fi

echo ""
echo "RESULT: ${PASS} passed, ${FAIL} failed"
[ "$FAIL" -eq 0 ]
