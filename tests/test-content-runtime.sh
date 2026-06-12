#!/usr/bin/env bash
# Step 42 — Content Runtime test suite (90+ assertions)
set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
source "$PLUGIN_DIR/wpcc-env.sh"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; if [ "$e" = "$a" ]; then pass "$d"; else fail "$d (expected '$e', got '$a')"; fi; }
assert_true() { local d="$1" a="$2"; if [ "$a" = "true" ]; then pass "$d"; else fail "$d (expected 'true', got '$a')"; fi; }
assert_contains() { local d="$1" h="$2" n="$3"; if [[ "$h" == *"$n"* ]]; then pass "$d"; else fail "$d"; fi; }
api() { local m="$1" p="$2" b="${3:-}"; if [ -n "$b" ]; then curl -s -X "$m" -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$b" "$WPCC_BASE$p"; else curl -s -X "$m" -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE$p"; fi; }

echo "== 1. Manifest =="
MANIFEST=$(api GET /agent/manifest)
assert_true "manifest: content_management section" "$(echo "$MANIFEST" | jq -r 'if .content_management then "true" else "false" end')"
assert_eq "manifest: 10 supported actions" "10" "$(echo "$MANIFEST" | jq -r '.content_management.supported_actions | length')"
assert_true "manifest: capability content_management" "$(echo "$MANIFEST" | jq -r '.capabilities.content_management // false')"

echo "== 2. Context =="
CONTEXT=$(api GET "/agent/context")
assert_true "context: content_management_available" "$(echo "$CONTEXT" | jq -r 'if .content_management_available then "true" else "false" end')"
assert_true "context: content_counts present" "$(echo "$CONTEXT" | jq -r 'if .content_counts then "true" else "false" end')"

echo "== 3. Invalid action =="
BAD=$(api POST /operations/content_manage/run '{"action":"evil"}')
assert_eq "invalid action" "wpcc_invalid_content_action" "$(echo "$BAD" | jq -r '.code // "none"')"

echo "== 4. Missing content_id =="
NS=$(api POST /operations/content_manage/run '{"action":"content_get"}')
assert_eq "missing id: get" "wpcc_missing_content_id" "$(echo "$NS" | jq -r '.code // "none"')"

echo "== 5. Not found =="
NF=$(api POST /operations/content_manage/run '{"action":"content_get","content_id":999999}')
assert_eq "not found" "wpcc_content_not_found" "$(echo "$NF" | jq -r '.code // "none"')"

echo "== 6. Content list =="
LIST=$(api POST /operations/content_manage/run '{"action":"content_list","type":"post","per_page":5}')
assert_eq "list: action correct" "content_list" "$(echo "$LIST" | jq -r '.action')"
assert_true "list: total >= 0" "$(echo "$LIST" | jq -r 'if .total >= 0 then "true" else "false" end')"
assert_true "list: items is array" "$(echo "$LIST" | jq -r 'if (.items | type) == "array" then "true" else "false" end')"

echo "== 7. Create content =="
CREATE=$(api POST /operations/content_manage/run '{"action":"content_create","title":"Step 42 Test Post","content":"Test content body","status":"draft","type":"post"}')
assert_eq "create: action correct" "content_create" "$(echo "$CREATE" | jq -r '.action')"
assert_eq "create: status draft" "draft" "$(echo "$CREATE" | jq -r '.status')"
CID=$(echo "$CREATE" | jq -r '.content_id')
assert_true "create: got content_id" "$(if [ "$CID" -gt 0 ] 2>/dev/null; then echo true; else echo false; fi)"

echo "== 8. Create missing title =="
NT=$(api POST /operations/content_manage/run '{"action":"content_create","content":"body"}')
assert_eq "create: no title rejected" "wpcc_missing_content_title" "$(echo "$NT" | jq -r '.code // "none"')"

echo "== 9. Content get =="
GET=$(api POST /operations/content_manage/run "{\"action\":\"content_get\",\"content_id\":$CID}")
assert_eq "get: correct id" "$CID" "$(echo "$GET" | jq -r '.content_id')"
assert_eq "get: title correct" "Step 42 Test Post" "$(echo "$GET" | jq -r '.title')"
assert_eq "get: status draft" "draft" "$(echo "$GET" | jq -r '.status')"
assert_true "get: has permalink" "$(echo "$GET" | jq -r 'if .permalink then "true" else "false" end')"

echo "== 10. Content update =="
UPD=$(api POST /operations/content_manage/run "{\"action\":\"content_update\",\"content_id\":$CID,\"title\":\"Step 42 Updated\"}")
assert_eq "update: action correct" "content_update" "$(echo "$UPD" | jq -r '.action')"
assert_eq "update: new title" "Step 42 Updated" "$(echo "$UPD" | jq -r '.title')"
assert_true "update: has rollback_id" "$(echo "$UPD" | jq -r 'if .rollback_id then "true" else "false" end')"

echo "== 11. Content publish =="
PUB=$(api POST /operations/content_manage/run "{\"action\":\"content_publish\",\"content_id\":$CID}")
assert_eq "publish: action correct" "content_publish" "$(echo "$PUB" | jq -r '.action')"
assert_eq "publish: new_status publish" "publish" "$(echo "$PUB" | jq -r '.new_status')"
assert_eq "publish: old_status draft" "draft" "$(echo "$PUB" | jq -r '.old_status')"
assert_true "publish: has rollback_id" "$(echo "$PUB" | jq -r 'if .rollback_id then "true" else "false" end')"

echo "== 12. Content unpublish =="
UNP=$(api POST /operations/content_manage/run "{\"action\":\"content_unpublish\",\"content_id\":$CID}")
assert_eq "unpublish: new_status draft" "draft" "$(echo "$UNP" | jq -r '.new_status')"

echo "== 13. Content schedule =="
FUTURE=$(date -v+1d -u +"%Y-%m-%d %H:%M:%S" 2>/dev/null || date -d "+1 day" -u +"%Y-%m-%d %H:%M:%S" 2>/dev/null || echo "2099-01-01 00:00:00")
SCH=$(api POST /operations/content_manage/run "{\"action\":\"content_schedule\",\"content_id\":$CID,\"publish_at\":\"$FUTURE\"}")
assert_eq "schedule: action correct" "content_schedule" "$(echo "$SCH" | jq -r '.action')"
assert_eq "schedule: new_status future" "future" "$(echo "$SCH" | jq -r '.new_status')"

echo "== 14. Schedule bad time =="
BADSCH=$(api POST /operations/content_manage/run '{"action":"content_schedule","content_id":'"$CID"',"publish_at":"2020-01-01"}')
assert_eq "schedule: past date rejected" "wpcc_invalid_schedule_time" "$(echo "$BADSCH" | jq -r '.code // "none"')"

echo "== 15. Taxonomies =="
TAX=$(api POST /operations/content_manage/run "{\"action\":\"taxonomy_assign\",\"content_id\":$CID,\"taxonomy\":\"category\",\"terms\":[\"Uncategorized\"]}")
assert_eq "tax: action correct" "taxonomy_assign" "$(echo "$TAX" | jq -r '.action')"
assert_true "tax: Uncategorized assigned" "$(echo "$TAX" | jq -r 'if (.assigned | index("Uncategorized")) then "true" else "false" end')"

echo "== 16. Invalid taxonomy =="
BADTAX=$(api POST /operations/content_manage/run '{"action":"taxonomy_assign","content_id":'"$CID"',"taxonomy":"nonexistent_tax","terms":["a"]}')
assert_eq "tax: bad taxonomy rejected" "wpcc_invalid_taxonomy" "$(echo "$BADTAX" | jq -r '.code // "none"')"

echo "== 17. Featured image — missing attachment =="
NOFI=$(api POST /operations/content_manage/run '{"action":"featured_image_assign","content_id":'"$CID"',"attachment_id":0}')
FI_CODE=$(echo "$NOFI" | jq -r '.code // "none"')
assert_true "fi: missing id rejected" "$(if echo "$FI_CODE" | grep -qE 'missing_attachment|content_not_found'; then echo true; else echo false; fi)"

echo "== 18. Featured image — not found =="
BADFI=$(api POST /operations/content_manage/run '{"action":"featured_image_assign","content_id":'"$CID"',"attachment_id":999999}')
BFI_CODE=$(echo "$BADFI" | jq -r '.code // "none"')
assert_true "fi: bad id rejected" "$(if echo "$BFI_CODE" | grep -qE 'invalid_attachment|content_not_found'; then echo true; else echo false; fi)"

echo "== 19. Content delete (trash) =="
DEL=$(api POST /operations/content_manage/run "{\"action\":\"content_delete\",\"content_id\":$CID}")
assert_eq "delete: action correct" "content_delete" "$(echo "$DEL" | jq -r '.action')"
assert_eq "delete: status trash" "trash" "$(echo "$DEL" | jq -r '.status')"
assert_true "delete: has rollback_id" "$(echo "$DEL" | jq -r 'if .rollback_id then "true" else "false" end')"

# Verify trashed
TRASHED=$(api POST /operations/content_manage/run "{\"action\":\"content_get\",\"content_id\":$CID}")
assert_eq "verify: trashed post accessible" "$CID" "$(echo "$TRASHED" | jq -r '.content_id')"

echo "== 20. Risk model =="
assert_eq "risk: list low" "low" "$(echo "$MANIFEST" | jq -r '.content_management.risk_model.content_list')"
assert_eq "risk: create medium" "medium" "$(echo "$MANIFEST" | jq -r '.content_management.risk_model.content_create')"
assert_eq "risk: delete high" "high" "$(echo "$MANIFEST" | jq -r '.content_management.risk_model.content_delete')"
assert_eq "risk: publish high" "high" "$(echo "$MANIFEST" | jq -r '.content_management.risk_model.content_publish')"

echo "== 21. Audit + Timeline =="
TL=$(api GET "/agent/timeline?limit=100")
assert_true "timeline: Content created" "$(echo "$TL" | jq -r 'any(.[]; .label == "Content created")')"
assert_true "timeline: Content updated" "$(echo "$TL" | jq -r 'any(.[]; .label == "Content updated")')"
assert_true "timeline: Content published" "$(echo "$TL" | jq -r 'any(.[]; .label == "Content published")')"
assert_true "timeline: Content deleted" "$(echo "$TL" | jq -r 'any(.[]; .label == "Content deleted")')"
assert_true "timeline: Content scheduled" "$(echo "$TL" | jq -r 'any(.[]; .label == "Content scheduled")')"
assert_true "timeline: Taxonomy assigned" "$(echo "$TL" | jq -r 'any(.[]; .label == "Taxonomy assigned")')"

echo "== 22. Error catalog =="
ECAT=$(echo "$MANIFEST" | jq -r '.error_catalog')
for c in wpcc_invalid_content_action wpcc_missing_content_id wpcc_content_not_found wpcc_missing_content_title wpcc_content_delete_failed wpcc_missing_taxonomy_terms wpcc_missing_attachment_id wpcc_invalid_attachment; do
  assert_contains "error: $c" "$ECAT" "$c"
done

echo "== 23. Operations registry =="
OPS=$(api GET /operations)
assert_true "ops: content_manage listed" "$(echo "$OPS" | jq -r 'any(.[]; .id == "content_manage")')"

echo "== 24. All 10 actions in manifest =="
for a in content_list content_get content_create content_update content_delete content_publish content_unpublish content_schedule taxonomy_assign featured_image_assign; do
  H=$(echo "$MANIFEST" | jq -r ".content_management.supported_actions | index(\"$a\")")
  if [ "$H" != "null" ]; then pass "action: $a"; else fail "action: $a missing"; fi
done

echo "== 25. Content counts =="
assert_true "counts: post_count >= 0" "$(echo "$CONTEXT" | jq -r 'if .content_counts.post_count >= 0 then "true" else "false" end')"
assert_true "counts: has supported_types" "$(echo "$CONTEXT" | jq -r 'if .content_counts.supported_types then "true" else "false" end')"

echo "== 26. Get published post for taxonomy check =="
if [ "$(echo "$LIST" | jq -r '.total')" -gt 0 ]; then
  PUB_ID=$(echo "$LIST" | jq -r '.items[0].id')
  GET2=$(api POST /operations/content_manage/run "{\"action\":\"content_get\",\"content_id\":$PUB_ID}")
  assert_true "get: has taxonomies" "$(echo "$GET2" | jq -r 'if .taxonomies then "true" else "false" end')"
else
  pass "get: taxonomies (no posts)"
fi

echo "== 27. Page operations =="
PAGE=$(api POST /operations/content_manage/run '{"action":"content_create","title":"Step 42 Test Page","status":"publish","type":"page"}')
PID=$(echo "$PAGE" | jq -r '.content_id')
assert_eq "page: created" "content_create" "$(echo "$PAGE" | jq -r '.action')"
assert_eq "page: type page" "page" "$(echo "$PAGE" | jq -r '.type')"
PAGE_GET=$(api POST /operations/content_manage/run "{\"action\":\"content_get\",\"content_id\":$PID}")
assert_eq "page get: correct" "$PID" "$(echo "$PAGE_GET" | jq -r '.content_id')"
# Trash the page
api POST /operations/content_manage/run "{\"action\":\"content_delete\",\"content_id\":$PID}" > /dev/null

echo "== 28. Content search =="
# Create a published post for search test
SEARCH_POST=$(api POST /operations/content_manage/run '{"action":"content_create","title":"Step 42 Searchable Post","status":"publish","type":"post"}')
SPID=$(echo "$SEARCH_POST" | jq -r '.content_id')
SRCH=$(api POST /operations/content_manage/run "{\"action\":\"content_list\",\"type\":\"post\",\"search\":\"Step 42 Searchable\",\"per_page\":5}")
assert_true "search: found results" "$(echo "$SRCH" | jq -r 'if .total > 0 then "true" else "false" end')"
# Trash it
api POST /operations/content_manage/run "{\"action\":\"content_delete\",\"content_id\":$SPID}" > /dev/null

echo "== 29. Missing schedule time =="
# Use a real published post ID from the list
REALID=$(echo "$LIST" | jq -r '.items[0].id // 0')
NOSCH=$(api POST /operations/content_manage/run "{\"action\":\"content_schedule\",\"content_id\":$REALID}")
assert_eq "schedule: no time rejected" "wpcc_missing_schedule_time" "$(echo "$NOSCH" | jq -r '.code // "none"')"

echo "== 30. Empty tax terms =="
EMPTAX=$(api POST /operations/content_manage/run "{\"action\":\"taxonomy_assign\",\"content_id\":$REALID,\"taxonomy\":\"category\",\"terms\":[]}")
assert_eq "tax: empty terms rejected" "wpcc_missing_taxonomy_terms" "$(echo "$EMPTAX" | jq -r '.code // "none"')"

echo "== 31. Content management timeline ops =="
assert_true "timeline: Content mgmt started" "$(echo "$TL" | jq -r 'any(.[]; .label == "Content management started")')"
assert_true "timeline: Content mgmt completed" "$(echo "$TL" | jq -r 'any(.[]; .label == "Content management completed")')"

echo "== 32. Manifest content counts =="
assert_true "manifest: has post_count" "$(echo "$MANIFEST" | jq -r 'if .content_management.content_counts.post_count >= 0 then "true" else "false" end')"

echo "== 33. Context totals =="
assert_eq "context: counts match manifest" "$(echo "$MANIFEST" | jq -r '.content_management.content_counts.post_count')" "$(echo "$CONTEXT" | jq -r '.content_counts.post_count')"

echo "== 34. Invalid content type =="
BADTYPE=$(api POST /operations/content_manage/run '{"action":"content_create","title":"Bad","type":"invalid_type"}')
assert_eq "create: bad type rejected" "wpcc_invalid_content_type" "$(echo "$BADTYPE" | jq -r '.code // "none"')"

echo "== 35. Page listing =="
PLIST=$(api POST /operations/content_manage/run '{"action":"content_list","type":"page","per_page":3}')
assert_true "page list: items present" "$(echo "$PLIST" | jq -r 'if (.items | type) == "array" then "true" else "false" end')"

echo "== 36. Manifest supported types =="
assert_true "manifest: types has post" "$(echo "$MANIFEST" | jq -r '.content_management.supported_types | index("post") != null')"
assert_true "manifest: types has page" "$(echo "$MANIFEST" | jq -r '.content_management.supported_types | index("page") != null')"

echo "== 37. Create post with content body =="
BODY_POST=$(api POST /operations/content_manage/run '{"action":"content_create","title":"Body Test","content":"<p>Rich content</p>","status":"draft","type":"post"}')
BPID=$(echo "$BODY_POST" | jq -r '.content_id')
BODY_GET=$(api POST /operations/content_manage/run "{\"action\":\"content_get\",\"content_id\":$BPID}")
assert_contains "get: has content" "$(echo "$BODY_GET" | jq -r '.content')" "Rich content"
# Clean up
api POST /operations/content_manage/run "{\"action\":\"content_delete\",\"content_id\":$BPID}" > /dev/null

echo "== 38. Manifest risk model completeness =="
for a in content_list content_get content_create content_update content_delete content_publish content_unpublish content_schedule taxonomy_assign featured_image_assign; do
  R=$(echo "$MANIFEST" | jq -r ".content_management.risk_model[\"$a\"] // \"missing\"")
  if [ "$R" != "missing" ]; then pass "risk: $a=$R"; else fail "risk: $a missing"; fi
done

echo "== 39. Timeline summary check =="
TSUM=$(echo "$TL" | jq -r '[.[] | select(.label == "Content created")] | first | .summary // ""')
assert_contains "timeline: created summary" "$TSUM" "Step 42"

echo "== Summary =="
echo "  $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
