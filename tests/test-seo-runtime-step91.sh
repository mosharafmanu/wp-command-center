#!/usr/bin/env bash
#
# STEP 91 — SEO Runtime acceptance suite.
#
# Unified SEO management over REST + MCP, provider-agnostic (Rank Math / Yoast).
# Workflow: create content → generate SEO → save → verify metadata → update →
# verify changes. Plus seo_validate, seo_analyze, rollback, structured errors,
# robots round-trip, and provider detection.
#
# Requires an active SEO plugin (this suite installs/uses Yoast on the dev site).
# Requires: curl, jq, wp, wpcc-env.sh.
# Usage: bash tests/test-seo-runtime-step91.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
# shellcheck source=/dev/null
source "$PLUGIN_DIR/wpcc-env.sh"
WP_PATH="$SCRIPT_DIR/../../../.."

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }
assert_nonempty() { local d="$1" a="$2"; { [ -n "$a" ] && [ "$a" != "null" ]; } && pass "$d" || fail "$d (empty/null)"; }
seo() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/operations/seo_manage/run"; }
seo_mcp() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/mcp" | jq -r '.result.content[0].text // empty'; }
wpe() { wp eval "$1" --path="$WP_PATH" 2>/dev/null; }

# Ensure a provider is active. The suite is provider-AGNOSTIC by design (the runtime
# supports Rank Math and Yoast through one unified field map), so it certifies whichever
# provider this site actually runs rather than assuming one. Hardcoding "yoast" made the
# whole section fail on a Rank Math site and told us nothing about either provider.
PROVIDER_OK=$(wpe 'echo (defined("WPSEO_VERSION")||class_exists("RankMath"))?"yes":"no";')
# The provider the runtime itself reports, and that provider's native meta keys.
EXPECTED_PROVIDER=$(wpe 'echo \WPCommandCenter\Operations\SeoProvider::detect();')
TITLE_META=$(wpe 'echo \WPCommandCenter\Operations\SeoProvider::meta_key("title", \WPCommandCenter\Operations\SeoProvider::detect());')
if [ "$PROVIDER_OK" != "yes" ]; then
  echo "  SKIP: no SEO plugin (Rank Math/Yoast) active — cannot run STEP 91 acceptance"
  echo "  SEO Runtime (STEP 91): 0 passed, 0 failed"
  exit 0
fi

PID=$(wpe 'echo wp_insert_post(["post_title"=>"S91 Article","post_content"=>"An in-depth article about widgets, including widget tips.","post_status"=>"publish","post_type"=>"post"]);')
cleanup() { wpe 'wp_delete_post('"$PID"',true);' >/dev/null 2>&1; }
trap cleanup EXIT
assert_nonempty "fixture: post created" "$PID"

echo "== 1. Provider detection via seo_get =="
R=$(seo "$(jq -n --argjson id "$PID" '{action:"seo_get",content_id:$id}')")
PROVIDER=$(echo "$R" | jq -r '.provider')
assert_eq "seo_get: provider detected" "$EXPECTED_PROVIDER" "$PROVIDER"

echo "== 2. Generate + save SEO (seo_update) returns rollback_id =="
GEN='{"title":"Widgets: The Complete Guide","description":"A complete, practical guide to widgets that covers selection, setup, and troubleshooting for every kind of widget you will meet.","focus_keyword":"widgets","canonical":"https://example.com/widgets-guide","og_title":"Widgets OG","og_description":"OG desc","twitter_title":"Widgets TW","robots":["noindex","nofollow"]}'
R=$(seo "$(jq -n --argjson id "$PID" --argjson seo "$GEN" '{action:"seo_update",content_id:$id,seo:$seo}')")
RID=$(echo "$R" | jq -r '.rollback_id')
assert_nonempty "seo_update: rollback_id" "$RID"
assert_eq "seo_update: title saved" "Widgets: The Complete Guide" "$(echo "$R" | jq -r '.seo.title')"

echo "== 3. Verify metadata persisted (seo_get + the active provider's native meta) =="
R=$(seo "$(jq -n --argjson id "$PID" '{action:"seo_get",content_id:$id}')")
assert_eq "verify: focus_keyword" "widgets" "$(echo "$R" | jq -r '.seo.focus_keyword')"
assert_eq "verify: canonical" "https://example.com/widgets-guide" "$(echo "$R" | jq -r '.seo.canonical')"
assert_eq "verify: robots normalized" "nofollow,noindex" "$(echo "$R" | jq -r '.seo.robots | join(",")')"
assert_eq "verify: native title meta ($EXPECTED_PROVIDER)" "Widgets: The Complete Guide" "$(wpe 'echo get_post_meta('"$PID"',"'"$TITLE_META"'",true);')"
NOINDEX_SET=$(wpe 'if ( "yoast" === \WPCommandCenter\Operations\SeoProvider::detect() ) { echo ( "1" === get_post_meta('"$PID"',"_yoast_wpseo_meta-robots-noindex",true) ) ? "yes" : "no"; } else { $r = get_post_meta('"$PID"',"rank_math_robots",true); echo ( is_array($r) && in_array("noindex",$r,true) ) ? "yes" : "no"; }')
assert_eq "verify: native noindex meta ($EXPECTED_PROVIDER)" "yes" "$NOINDEX_SET"

echo "== 4. Update SEO + verify changes =="
seo "$(jq -n --argjson id "$PID" '{action:"seo_update",content_id:$id,seo:{title:"Updated Widgets Title",robots:[]}}')" >/dev/null
R=$(seo "$(jq -n --argjson id "$PID" '{action:"seo_get",content_id:$id}')")
assert_eq "update: title changed" "Updated Widgets Title" "$(echo "$R" | jq -r '.seo.title')"
assert_eq "update: description unchanged" "true" "$(echo "$R" | jq -r '(.seo.description | length) > 0')"
assert_eq "update: robots cleared" "0" "$(echo "$R" | jq -r '.seo.robots | length')"

echo "== 5. seo_analyze returns a score and checks =="
R=$(seo "$(jq -n --argjson id "$PID" '{action:"seo_analyze",content_id:$id}')")
assert_eq "analyze: focus_keyword_in_title passes" "true" "$(echo "$R" | jq -r '[.checks[] | select(.check=="focus_keyword_in_title") | .passed][0]')"
S=$(echo "$R" | jq -r '.score')
{ [ "$S" -ge 0 ] && [ "$S" -le 100 ]; } && pass "analyze: score in range ($S)" || fail "analyze: score '$S'"

echo "== 6. seo_validate flags structural issues =="
assert_eq "validate: bad canonical → error + invalid" "false" "$(seo '{"action":"seo_validate","seo":{"canonical":"not a url"}}' | jq -r '.valid')"
assert_eq "validate: unknown robots directive → error" "true" "$(seo '{"action":"seo_validate","seo":{"robots":["bogus"]}}' | jq -r '[.issues[] | select(.field=="robots" and .severity=="error")] | length > 0')"
assert_eq "validate: clean fields → valid" "true" "$(seo '{"action":"seo_validate","seo":{"title":"Good","canonical":"https://example.com/x"}}' | jq -r '.valid')"

echo "== 7. Structured errors =="
assert_eq "update no fields → wpcc_seo_no_fields" "wpcc_seo_no_fields" "$(seo "$(jq -n --argjson id "$PID" '{action:"seo_update",content_id:$id,seo:{}}')" | jq -r '.code // "none"')"
assert_eq "get missing post → wpcc_seo_post_not_found" "wpcc_seo_post_not_found" "$(seo '{"action":"seo_get","content_id":99999999}' | jq -r '.code // "none"')"
assert_eq "update invalid canonical → wpcc_seo_invalid_field" "wpcc_seo_invalid_field" "$(seo "$(jq -n --argjson id "$PID" '{action:"seo_update",content_id:$id,seo:{canonical:"nope"}}')" | jq -r '.code // "none"')"

echo "== 8. MCP parity =="
R=$(seo_mcp "$(jq -n --argjson id "$PID" '{jsonrpc:"2.0",id:1,method:"tools/call",params:{name:"seo_manage",arguments:{action:"seo_get",content_id:$id}}}')")
assert_eq "MCP seo_get: provider" "$EXPECTED_PROVIDER" "$(echo "$R" | jq -r '.provider')"
assert_eq "MCP seo_get: title" "Updated Widgets Title" "$(echo "$R" | jq -r '.seo.title')"

echo "== 9. Rollback is field-scoped + drift-aware (F-1) =="
# RID (section 2) set several SEO fields; section 4 then changed ONLY the title, so on
# rollback the untouched fields restore cleanly while the drifted title is skipped — a
# field-scoped PARTIAL rollback that must NOT clobber the newer title (the F-1 fix).
DRIFT=$(seo "$(jq -n --arg rid "$RID" '{action:"seo_restore",rollback_id:$rid}')")
# A drifted rollback is reported as wpcc_rollback_partial, with the structured detail
# (restored/skipped/conflicts) carried in the error's data payload — an assistant must be
# able to act on skipped_fields, not parse the prose message.
assert_eq "rollback: drifted RID reported partial" "wpcc_rollback_partial" "$(echo "$DRIFT" | jq -r '.code')"
assert_eq "rollback: drifted RID not a clean restore" "false" "$(echo "$DRIFT" | jq -r '.data.restored')"
assert_eq "rollback: drifted title reported skipped" "title" "$(echo "$DRIFT" | jq -r '.data.skipped_fields // [] | index("title") | if . == null then "" else "title" end')"
assert_eq "rollback: untouched field still restored" "description" "$(echo "$DRIFT" | jq -r '.data.restored_fields // [] | index("description") | if . == null then "" else "description" end')"
assert_eq "rollback: conflict names the drifted field" "title" "$(echo "$DRIFT" | jq -r '.data.conflicts[0].field // ""')"
assert_eq "rollback: newer title preserved (no clobber)" "Updated Widgets Title" "$(seo "$(jq -n --argjson id "$PID" '{action:"seo_get",content_id:$id}')" | jq -r '.seo.title')"
# A fresh (non-drifted) rollback restores cleanly and is idempotency-guarded.
FRID=$(seo "$(jq -n --argjson id "$PID" '{action:"seo_update",content_id:$id,seo:{title:"Temp Rollback Title"}}')" | jq -r '.rollback_id')
assert_eq "rollback: fresh restore complete" "complete" "$(seo "$(jq -n --arg rid "$FRID" '{action:"seo_restore",rollback_id:$rid}')" | jq -r '.status // "none"')"
assert_eq "rollback: already-applied guard" "wpcc_rollback_already_applied" "$(seo "$(jq -n --arg rid "$FRID" '{action:"seo_restore",rollback_id:$rid}')" | jq -r '.code // "none"')"

echo
echo "================================================"
echo "  SEO Runtime (STEP 91): $PASS passed, $FAIL failed"
echo "================================================"
[ "$FAIL" -eq 0 ]
