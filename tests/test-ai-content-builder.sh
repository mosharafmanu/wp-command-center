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
# The connect-a-key link points at the CANONICAL destination, not the legacy
# `wpcc-connect` alias (AppShell maps that alias to the same place). Asserting the real
# target also proves the link lands on the assistants pane, not just the Settings page.
# The "no AI provider" error must route to the screen that HAS a provider key
# field — Built-in AI > Providers. It pointed at Settings > Connections >
# Assistants, the MCP screen, which has no key field and whose own copy says no
# key is needed there: the one error state whose entire job is "go and add a
# key" delivered the customer somewhere that made it impossible.
has  "no_provider links Built-in AI > Providers" "apane=ai&aipane=providers" "$VIEW"
lacks "no_provider does NOT link the MCP assistants screen" "cpane=assistants" "$VIEW"

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
	assert_eq "catalogue == 42"     "42" "$(wpe 'echo count((new \WPCommandCenter\Operations\OperationRegistry())->get_operations());')"
	assert_eq "DB_VERSION 2.6.0"    "2.6.0" "$(wpe 'echo \WPCommandCenter\Core\Schema::DB_VERSION;')"
fi

# ── Built-in AI mental model: the plugin does the work, not an assistant ─────
#
# A first-time customer audit found the contradiction this section guards.
# Built-in AI onboarding promises the plugin generates SEO / Alt Text / Content
# without opening an external assistant — which is TRUE: the generation chain is
# AiRuntime -> AnthropicClient / OpenAiCompatibleTransport, with no MCP anywhere.
# But after a key was added, "What happens next?" said "Connect an AI assistant
# so it can do the work", telling the customer their key had achieved nothing.
echo ""
echo "== 12. Built-in AI needs no external assistant (mental model) =="
SETUP="$PLUGIN_DIR/includes/Admin/views/ai-setup.php"
HUB="$PLUGIN_DIR/includes/Admin/views/settings-ai.php"

lacks "next-steps does NOT say an assistant does the work" "Connect an AI assistant so it can do the work" "$SETUP"
has   "next-steps points at the Built-in AI tools"   "Choose SEO, Alt Text or Content above" "$SETUP"
has   "next-steps states no assistant is required"   "you do not need to connect an external assistant" "$SETUP"
has   "assistants named as a SEPARATE optional path" "separate, optional path that needs no provider key" "$SETUP"
has   "unswitched-on tools get their own next step"  "Switch on SEO, Alt Text or Content above" "$SETUP"
has   "hub: built-in AI works without an assistant"  "without you opening an assistant" "$HUB"
has   "hub: MCP path needs no provider key"          "No provider key required" "$HUB"

for f in seo-meta ai-alt-text ai-content; do
	lacks "$f: no 'connect an assistant' instruction" "Connect an AI assistant" "$PLUGIN_DIR/includes/Admin/views/$f.php"
done

# ── Row-level accessible names ───────────────────────────────────────────────
#
# Every row control carried the SAME accessible name, so a screen reader
# announced "Generate suggestions" once per row with nothing to tell them
# apart — operable but not identifiable, which on a bulk-generate table means
# choosing blind. The alt-text generate checkbox had no accessible name at all.
echo ""
echo "== 13. Row controls name the item they act on (a11y) =="
SEOV="$PLUGIN_DIR/includes/Admin/views/seo-meta.php"
ALTV="$PLUGIN_DIR/includes/Admin/views/ai-alt-text.php"

has "shared row-label helper (seo)"              "const rowLabel" "$SEOV"
has "shared row-label helper (alt text)"         "const rowLabel" "$ALTV"
has "shared row-label helper (content)"          "const rowLabel" "$VIEW"
has "seo: generate checkbox names the post"      "Generate suggestions for %s" "$SEOV"
has "seo: suggestion checkbox names the post"    "Select suggestion for %s"    "$SEOV"
has "alt: generate checkbox names the image"     "Generate alt text for %s"    "$ALTV"
has "alt: suggestion checkbox names the image"   "Select suggestion for %s"    "$ALTV"
has "content: apply names the post"              "Approve and apply suggestion for %s"   "$VIEW"
has "content: submit names the post"             "Submit suggestion for %s for approval" "$VIEW"
has "content: dismiss names the post"            "Dismiss suggestion for %s"   "$VIEW"

echo ""
echo "RESULT: ${PASS} passed, ${FAIL} failed"
[ "$FAIL" -eq 0 ]
