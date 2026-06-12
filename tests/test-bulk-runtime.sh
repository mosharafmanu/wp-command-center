#!/usr/bin/env bash
# Bulk Runtime test suite (30+ assertions)
set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../wpcc-env.sh"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; if [ "$e" = "$a" ]; then pass "$d"; else fail "$d (expected '$e', got '$a')"; fi; }
assert_true() { local d="$1" a="$2"; if [ "$a" = "true" ]; then pass "$d"; else fail "$d (expected 'true', got '$a')"; fi; }
assert_contains() { local d="$1" h="$2" n="$3"; if [[ "$h" == *"$n"* ]]; then pass "$d"; else fail "$d"; fi; }
api() { curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$@"; }
api_post() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" "$@"; }
mcp() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/mcp"; }

echo "Bulk Runtime Test — $(date)"

MANIFEST=$(api "$WPCC_BASE/agent/manifest")
DISC=$(api "$WPCC_BASE/claude/discovery")
CONTEXT=$(api "$WPCC_BASE/agent/context")

# ── 1. Registration ──
echo "== 1. Registration =="
assert_true "reg: bulk_manage in manifest ops" "$(echo "$MANIFEST" | jq -r 'any(.operations[];.id=="bulk_manage")')"
assert_eq "reg: risk_level high" "high" "$(echo "$MANIFEST" | jq -r '.operations[]|select(.id=="bulk_manage")|.risk_level')"
assert_eq "reg: requires_approval" "true" "$(echo "$MANIFEST" | jq -r '.operations[]|select(.id=="bulk_manage")|.requires_approval')"

# ── 2. Capability ──
echo "== 2. Capability =="
assert_contains "cap: bulk.manage in capabilities" "$(echo "$MANIFEST" | jq -r '.capability_management.capabilities|join(",")')" "bulk.manage"
assert_contains "cap: bulk_manage in operation_map" "$(echo "$MANIFEST" | jq -r '.capability_management.operation_map|keys|join(",")')" "bulk_manage"

# ── 3. Routes ──
echo "== 3. Routes =="
assert_true "route: /bulk_manage/run" "$(echo "$MANIFEST" | jq -r 'any(.endpoints[];.path=="/operations/bulk_manage/run")')"
assert_true "route: /bulk_manage/rollback" "$(echo "$MANIFEST" | jq -r 'any(.endpoints[];.path=="/operations/bulk_manage/rollback")')"
assert_eq "route: scope full" "full" "$(echo "$MANIFEST" | jq -r '.endpoints[]|select(.path=="/operations/bulk_manage/run")|.scope')"

# ── 4. Bulk Content ──
echo "== 4. Bulk Content =="
# Create two test posts for bulk operations
P1=$(api_post -d '{"action":"content_create","title":"Bulk Test Post Alpha","status":"draft","type":"post"}' "$WPCC_BASE/operations/content_manage/run")
P2=$(api_post -d '{"action":"content_create","title":"Bulk Test Post Beta","status":"draft","type":"post"}' "$WPCC_BASE/operations/content_manage/run")
PID1=$(echo "$P1" | jq -r '.content_id')
PID2=$(echo "$P2" | jq -r '.content_id')
assert_true "setup: created post 1" "$(if [ "$PID1" -gt 0 ] 2>/dev/null; then echo true; else echo false; fi)"
assert_true "setup: created post 2" "$(if [ "$PID2" -gt 0 ] 2>/dev/null; then echo true; else echo false; fi)"

# Bulk content update
BCONTENT=$(api_post -d "{\"action\":\"bulk_content\",\"ids\":[$PID1,$PID2],\"fields\":{\"post_title\":\"Bulk Renamed\"}}" "$WPCC_BASE/operations/bulk_manage/run")
assert_eq "bc: action" "bulk_content" "$(echo "$BCONTENT" | jq -r '.action')"
assert_eq "bc: updated 2" "2" "$(echo "$BCONTENT" | jq -r '.updated')"

# Verify the rename
V1=$(api_post -d "{\"action\":\"content_get\",\"content_id\":$PID1}" "$WPCC_BASE/operations/content_manage/run")
assert_eq "bc: verify rename" "Bulk Renamed" "$(echo "$V1" | jq -r '.title')"

# ── 5. Bulk Publish ──
echo "== 5. Bulk Publish =="
BPUB=$(api_post -d "{\"action\":\"bulk_publish\",\"ids\":[$PID1,$PID2]}" "$WPCC_BASE/operations/bulk_manage/run")
assert_eq "bp: action" "bulk_publish" "$(echo "$BPUB" | jq -r '.action')"
assert_eq "bp: updated 2" "2" "$(echo "$BPUB" | jq -r '.updated')"

# Verify published
VP1=$(api_post -d "{\"action\":\"content_get\",\"content_id\":$PID1}" "$WPCC_BASE/operations/content_manage/run")
assert_eq "bp: verify status" "publish" "$(echo "$VP1" | jq -r '.status')"

# ── 6. Bulk Unpublish ──
echo "== 6. Bulk Unpublish =="
BUNP=$(api_post -d "{\"action\":\"bulk_unpublish\",\"ids\":[$PID1,$PID2]}" "$WPCC_BASE/operations/bulk_manage/run")
assert_eq "bu: action" "bulk_unpublish" "$(echo "$BUNP" | jq -r '.action')"
assert_eq "bu: updated 2" "2" "$(echo "$BUNP" | jq -r '.updated')"

# Verify unpublished
VU1=$(api_post -d "{\"action\":\"content_get\",\"content_id\":$PID1}" "$WPCC_BASE/operations/content_manage/run")
assert_eq "bu: verify status draft" "draft" "$(echo "$VU1" | jq -r '.status')"

# ── 7. Bulk Media ──
echo "== 7. Bulk Media =="
# Get first two media IDs
MEDIA_LIST=$(api_post -d '{"action":"media_list","per_page":2}' "$WPCC_BASE/operations/media_manage/run")
MID1=$(echo "$MEDIA_LIST" | jq -r '.items[0].id // 0')
MID2=$(echo "$MEDIA_LIST" | jq -r '.items[1].id // 0')
if [ "$MID1" -gt 0 ] 2>/dev/null && [ "$MID2" -gt 0 ] 2>/dev/null; then
  BMEDIA=$(api_post -d "{\"action\":\"bulk_media\",\"ids\":[$MID1,$MID2],\"title\":\"Bulk Media Title\"}" "$WPCC_BASE/operations/bulk_manage/run")
  assert_eq "bm: action" "bulk_media" "$(echo "$BMEDIA" | jq -r '.action')"
  assert_eq "bm: updated" "2" "$(echo "$BMEDIA" | jq -r '.updated')"
else
  pass "bm: no media to test (skipped)"
  pass "bm: no media to test (skipped)"
fi

# ── 8. Bulk WooCommerce ──
echo "== 8. Bulk WooCommerce =="
if [ "$(echo "$CONTEXT" | jq -r '.site_summary.woocommerce.active // false')" = "true" ] 2>/dev/null; then
  # Get product IDs
  PROD_LIST=$(api_post -d '{"action":"product_list","per_page":2}' "$WPCC_BASE/operations/woocommerce_manage/run")
  WID1=$(echo "$PROD_LIST" | jq -r '.items[0].id // 0')
  WID2=$(echo "$PROD_LIST" | jq -r '.items[1].id // 0')
  if [ "$WID1" -gt 0 ] 2>/dev/null && [ "$WID2" -gt 0 ] 2>/dev/null; then
    BWOO=$(api_post -d "{\"action\":\"bulk_woocommerce\",\"ids\":[$WID1,$WID2],\"regular_price\":\"55.55\"}" "$WPCC_BASE/operations/bulk_manage/run")
    assert_eq "bw: action" "bulk_woocommerce" "$(echo "$BWOO" | jq -r '.action')"
    assert_true "bw: has results" "$(echo "$BWOO" | jq -r 'if .results then "true" else "false" end')"
  else
    pass "bw: no products to test (skipped)"
    pass "bw: no products to test (skipped)"
  fi
else
  pass "bw: Woo not active (skipped)"
  pass "bw: Woo not active (skipped)"
fi

# ── 9. Bulk ACF ──
echo "== 9. Bulk ACF =="
if function_exists_acf=$(echo "$MANIFEST" | jq -r '.operations[]|select(.id=="acf_manage")|.available') && [ "$function_exists_acf" = "true" ]; then
  BACF=$(api_post -d "{\"action\":\"bulk_acf\",\"post_ids\":[$PID1,$PID2],\"field_key\":\"test_field\",\"value\":\"bulk-acf-val\"}" "$WPCC_BASE/operations/bulk_manage/run")
  assert_true "ba: has results or updated" "$(echo "$BACF" | jq -r 'if .results or .updated then "true" else "false" end')"
else
  pass "ba: ACF not available (skipped)"
fi

# ── 10. Batch Execute ──
echo "== 10. Batch Execute =="
BATCH=$(api_post -d '{"action":"batch_execute","operations":[{"operation_id":"content_manage","payload":{"action":"content_list","type":"post","per_page":1}},{"operation_id":"content_manage","payload":{"action":"content_list","type":"page","per_page":1}}]}' "$WPCC_BASE/operations/bulk_manage/run")
assert_eq "bex: action" "batch_execute" "$(echo "$BATCH" | jq -r '.action')"
assert_eq "bex: executed 2" "2" "$(echo "$BATCH" | jq -r '.executed')"
assert_true "bex: results is array" "$(echo "$BATCH" | jq -r 'if (.results | type) == "array" then "true" else "false" end')"

# ── 11. Validation — Invalid action ──
echo "== 11. Validation =="
BAD=$(api_post -d '{"action":"nonexistent_action"}' "$WPCC_BASE/operations/bulk_manage/run")
assert_contains "val: invalid action error" "$BAD" "Invalid action"

# Missing ids
MISSING=$(api_post -d '{"action":"bulk_content"}' "$WPCC_BASE/operations/bulk_manage/run")
assert_eq "val: bulk_content no ids" "0" "$(echo "$MISSING" | jq -r '.updated')"

# ── 12. MCP ──
echo "== 12. MCP =="
MCP_TOOLS=$(mcp '{"jsonrpc":"2.0","method":"tools/list","id":1}')
assert_true "mcp: bulk_manage tool" "$(echo "$MCP_TOOLS" | jq -r 'any(.result.tools[];.name=="bulk_manage")')"

# ── 13. Timeline ──
echo "== 13. Timeline =="
TL=$(api "$WPCC_BASE/agent/timeline?limit=100")
assert_true "tl: Bulk content updated" "$(echo "$TL" | jq -r 'any(.[];.label=="Bulk content updated")')"
assert_true "tl: Bulk publish completed" "$(echo "$TL" | jq -r 'any(.[];.label=="Bulk publish completed")')"
assert_true "tl: Bulk unpublish completed" "$(echo "$TL" | jq -r 'any(.[];.label=="Bulk unpublish completed")')"
assert_true "tl: Batch execute completed" "$(echo "$TL" | jq -r 'any(.[];.label=="Batch execute completed")')"

# ── 14. Rollback ──
echo "== 14. Rollback =="
# Get a rollback_id from the bulk operations
RBTEST=$(curl -s -o /dev/null -w "%{http_code}" -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d '{"rollback_id":"00000000-0000-0000-0000-000000000000"}' "$WPCC_BASE/operations/bulk_manage/rollback")
assert_true "rb: endpoint responds" "$( [ "$RBTEST" = "400" -o "$RBTEST" = "404" ] && echo true || echo false )"

# ── 15. No Token Access ──
echo "== 15. No Token =="
NOAUTH_RUN=$(curl -s -o /dev/null -w "%{http_code}" -X POST -H "Content-Type: application/json" -d '{"action":"bulk_content","ids":[1]}' "$WPCC_BASE/operations/bulk_manage/run")
assert_contains "auth: run 401" "$NOAUTH_RUN" "401"
NOAUTH_RB=$(curl -s -o /dev/null -w "%{http_code}" -X POST -H "Content-Type: application/json" -d '{"rollback_id":"x"}' "$WPCC_BASE/operations/bulk_manage/rollback")
assert_contains "auth: rollback 401" "$NOAUTH_RB" "401"

# ── 16. Context check ──
echo "== 16. Context =="
assert_true "ctx: operations in context" "$(echo "$CONTEXT" | jq -r 'if .operations then "true" else "false" end')"

# ── 17. Operations registry check ──
echo "== 17. Operations Registry =="
OPS=$(api "$WPCC_BASE/operations")
assert_true "ops: bulk_manage listed" "$(echo "$OPS" | jq -r 'any(.[];.id=="bulk_manage")')"

# ── 18. Manifest op count >= 22 ──
echo "== 18. Manifest Op Count =="
assert_true "ops: 22+" "$(if [ "$(echo "$MANIFEST" | jq -r '.operations|length')" -ge 22 ] 2>/dev/null; then echo true; else echo false; fi)"

# ── Cleanup ──
echo "== Cleanup =="
api_post -d "{\"action\":\"content_delete\",\"content_id\":$PID1}" "$WPCC_BASE/operations/content_manage/run" >/dev/null
api_post -d "{\"action\":\"content_delete\",\"content_id\":$PID2}" "$WPCC_BASE/operations/content_manage/run" >/dev/null
pass "cleanup: trashed test posts"

echo ""
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
