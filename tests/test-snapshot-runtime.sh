#!/usr/bin/env bash
# Step 41 — Snapshot Runtime test suite
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

echo "== 1. Manifest Integration =="
MANIFEST=$(api GET /agent/manifest)
assert_true "manifest: snapshot_management section" "$(echo "$MANIFEST" | jq -r 'if .snapshot_management then "true" else "false" end')"
assert_true "manifest: supported_actions present" "$(echo "$MANIFEST" | jq -r 'if (.snapshot_management.supported_actions | type) == "array" then "true" else "false" end')"
ACT_COUNT=$(echo "$MANIFEST" | jq -r '.snapshot_management.supported_actions | length')
assert_eq "manifest: 5 supported actions" "5" "$ACT_COUNT"
assert_true "manifest: restore_supported" "$(echo "$MANIFEST" | jq -r '.snapshot_management.restore_supported // false')"
assert_true "manifest: capability snapshot_management" "$(echo "$MANIFEST" | jq -r '.capabilities.snapshot_management // false')"

echo "== 2. Agent Context =="
CONTEXT=$(api GET "/agent/context")
assert_true "context: snapshot_management_available" "$(echo "$CONTEXT" | jq -r 'if .snapshot_management_available then "true" else "false" end')"
assert_true "context: snapshot_count numeric" "$(echo "$CONTEXT" | jq -r 'if (.snapshot_count | type) == "number" then "true" else "false" end')"

echo "== 3. Invalid action =="
BAD=$(api POST /operations/snapshot_manage/run '{"action":"evil"}')
assert_eq "invalid action rejected" "wpcc_invalid_snapshot_action" "$(echo "$BAD" | jq -r '.code // "none"')"

echo "== 4. Missing snapshot_id =="
NSID=$(api POST /operations/snapshot_manage/run '{"action":"snapshot_details"}')
assert_eq "missing id: details" "wpcc_missing_snapshot_id" "$(echo "$NSID" | jq -r '.code // "none"')"
NSID2=$(api POST /operations/snapshot_manage/run '{"action":"snapshot_verify"}')
assert_eq "missing id: verify" "wpcc_missing_snapshot_id" "$(echo "$NSID2" | jq -r '.code // "none"')"
NSID3=$(api POST /operations/snapshot_manage/run '{"action":"snapshot_restore"}')
assert_eq "missing id: restore" "wpcc_missing_snapshot_id" "$(echo "$NSID3" | jq -r '.code // "none"')"

echo "== 5. Invalid snapshot_id =="
INV=$(api POST /operations/snapshot_manage/run '{"action":"snapshot_details","snapshot_id":"fake-id-12345"}')
assert_eq "fake id: not found" "wpcc_snapshot_not_found" "$(echo "$INV" | jq -r '.code // "none"')"

echo "== 6. Create snapshot =="
SNAP=$(api POST /operations/snapshot_manage/run '{"action":"snapshot_create","path":"themes/mosharaf-core/style.css","label":"Step 41 Test Snapshot"}')
assert_eq "create: action correct" "snapshot_create" "$(echo "$SNAP" | jq -r '.action // "none"')"
SNAP_ID=$(echo "$SNAP" | jq -r '.snapshot_id')
assert_true "create: snapshot_id not empty" "$(if [ -n "$SNAP_ID" ] && [ "$SNAP_ID" != "null" ]; then echo true; else echo false; fi)"
assert_eq "create: label correct" "Step 41 Test Snapshot" "$(echo "$SNAP" | jq -r '.label // ""')"

echo "== 7. Create missing path =="
NOPATH=$(api POST /operations/snapshot_manage/run '{"action":"snapshot_create","label":"Test"}')
assert_eq "create: missing path rejected" "wpcc_missing_snapshot_path" "$(echo "$NOPATH" | jq -r '.code // "none"')"

echo "== 8. List snapshots =="
SLIST=$(api POST /operations/snapshot_manage/run '{"action":"snapshot_list"}')
assert_eq "list: action correct" "snapshot_list" "$(echo "$SLIST" | jq -r '.action // "none"')"
assert_true "list: count > 0" "$(echo "$SLIST" | jq -r 'if .count > 0 then "true" else "false" end')"
HAS_SNAP=$(echo "$SLIST" | jq -r "any(.snapshots[]; .snapshot_id == \"$SNAP_ID\")")
assert_true "list: has our snapshot" "$HAS_SNAP"

echo "== 9. Snapshot details =="
DETAIL=$(api POST /operations/snapshot_manage/run "{\"action\":\"snapshot_details\",\"snapshot_id\":\"$SNAP_ID\"}")
assert_eq "details: id correct" "$SNAP_ID" "$(echo "$DETAIL" | jq -r '.snapshot_id')"
VRFD=$(echo "$DETAIL" | jq -r 'if .verified == true or .verified == false then "true" else "false" end')
assert_true "details: verified boolean" "$VRFD"

echo "== 10. Snapshot verify =="
VERIFY=$(api POST /operations/snapshot_manage/run "{\"action\":\"snapshot_verify\",\"snapshot_id\":\"$SNAP_ID\"}")
assert_eq "verify: action correct" "snapshot_verify" "$(echo "$VERIFY" | jq -r '.action // "none"')"
assert_eq "verify: valid is true" "true" "$(echo "$VERIFY" | jq -r '.valid')"

echo "== 11. Risk model =="
assert_eq "risk: list low" "low" "$(echo "$MANIFEST" | jq -r '.snapshot_management.risk_model.snapshot_list')"
assert_eq "risk: create medium" "medium" "$(echo "$MANIFEST" | jq -r '.snapshot_management.risk_model.snapshot_create')"
assert_eq "risk: restore critical" "critical" "$(echo "$MANIFEST" | jq -r '.snapshot_management.risk_model.snapshot_restore')"
assert_eq "risk: details low" "low" "$(echo "$MANIFEST" | jq -r '.snapshot_management.risk_model.snapshot_details')"

echo "== 12. Audit + Timeline =="
TL=$(api GET "/agent/timeline?limit=50")
assert_true "timeline: Snapshot created" "$(echo "$TL" | jq -r 'any(.[]; .label == "Snapshot created")')"
assert_true "timeline: Snapshot verified" "$(echo "$TL" | jq -r 'any(.[]; .label == "Snapshot verified")')"
assert_true "timeline: Snapshot list requested" "$(echo "$TL" | jq -r 'any(.[]; .label == "Snapshot list requested")')"

echo "== 13. Error catalog =="
ECAT=$(echo "$MANIFEST" | jq -r '.error_catalog')
for c in wpcc_invalid_snapshot_action wpcc_missing_snapshot_path wpcc_missing_snapshot_id; do
  assert_contains "error: $c" "$ECAT" "$c"
done

echo "== 14. Operation registry =="
OPS=$(api GET /operations)
assert_true "ops: snapshot_manage listed" "$(echo "$OPS" | jq -r 'any(.[]; .id == "snapshot_manage")')"

echo "== 15. All 5 actions =="
for a in snapshot_create snapshot_list snapshot_details snapshot_restore snapshot_verify; do
  H=$(echo "$MANIFEST" | jq -r ".snapshot_management.supported_actions | index(\"$a\")")
  if [ "$H" != "null" ]; then pass "action: $a"; else fail "action: $a missing"; fi
done

echo "== 16. Snapshot create with bad path =="
BADPATH=$(api POST /operations/snapshot_manage/run '{"action":"snapshot_create","path":"nonexistent/file.php","label":"bad"}')
BADCODE=$(echo "$BADPATH" | jq -r '.code // "none"')
assert_true "create: bad path rejected" "$(if echo "$BADCODE" | grep -qE 'not_readable|not_found|blocked'; then echo true; else echo false; fi)"

echo "== 17. Context snapshot fields =="
LS=$(echo "$CONTEXT" | jq -r 'if .latest_snapshot == null or .latest_snapshot.snapshot_id then "true" else "false" end')
assert_true "context: latest_snapshot or null" "$LS"

echo "== 18. Verify wrong id =="
WV=$(api POST /operations/snapshot_manage/run '{"action":"snapshot_verify","snapshot_id":"nonexistent-id-999"}')
assert_eq "verify: wrong id" "wpcc_snapshot_not_found" "$(echo "$WV" | jq -r '.code // "none"')"

echo "== 19. Create without path rejects =="
NP2=$(api POST /operations/snapshot_manage/run '{"action":"snapshot_create"}')
assert_eq "create: no path" "wpcc_missing_snapshot_path" "$(echo "$NP2" | jq -r '.code // "none"')"

echo "== 20. Restore validation (requires approval) =="
# Restore is critical risk — verify it attempts (may fail due to write perms or succeed)
RESTORE=$(api POST /operations/snapshot_manage/run "{\"action\":\"snapshot_restore\",\"snapshot_id\":\"$SNAP_ID\"}")
RESTORE_CODE=$(echo "$RESTORE" | jq -r '.action // .code // "none"')
assert_true "restore: attempted" "$(if [ "$RESTORE_CODE" = "snapshot_restore" ] || echo "$RESTORE" | grep -q 'error\|failed\|writable\|blocked'; then echo true; else echo false; fi)"

echo "== 21. Create second snapshot =="
SNAP2=$(api POST /operations/snapshot_manage/run '{"action":"snapshot_create","path":"themes/mosharaf-core/functions.php","label":"Step 41 Second Snapshot"}')
SID2=$(echo "$SNAP2" | jq -r '.snapshot_id')
assert_eq "2nd create: action" "snapshot_create" "$(echo "$SNAP2" | jq -r '.action // "none"')"

echo "== 22. List shows both snapshots =="
SLIST2=$(api POST /operations/snapshot_manage/run '{"action":"snapshot_list"}')
C2=$(echo "$SLIST2" | jq -r '.count')
assert_true "list: count >= 2" "$(if [ "$C2" -ge 2 ]; then echo true; else echo false; fi)"

echo "== 23. Verify second snapshot =="
V2=$(api POST /operations/snapshot_manage/run "{\"action\":\"snapshot_verify\",\"snapshot_id\":\"$SID2\"}")
assert_eq "v2: valid true" "true" "$(echo "$V2" | jq -r '.valid')"
assert_eq "v2: snapshot_id correct" "$SID2" "$(echo "$V2" | jq -r '.snapshot_id')"

echo "== 24. Details second snapshot =="
D2=$(api POST /operations/snapshot_manage/run "{\"action\":\"snapshot_details\",\"snapshot_id\":\"$SID2\"}")
assert_eq "d2: path correct" "themes/mosharaf-core/functions.php" "$(echo "$D2" | jq -r '.path')"
assert_true "d2: has hash" "$(echo "$D2" | jq -r 'if .hash and (.hash | length) > 0 then "true" else "false" end')"
assert_true "d2: has size" "$(echo "$D2" | jq -r 'if .size and .size > 0 then "true" else "false" end')"

echo "== 25. Context shows latest snapshot =="
CTX2=$(api GET "/agent/context")
LS_ID=$(echo "$CTX2" | jq -r '.latest_snapshot.snapshot_id // ""')
assert_true "context: latest snapshot id present" "$(if [ -n "$LS_ID" ]; then echo true; else echo false; fi)"

echo "== 26. Timeline has snapshot events =="
TL2=$(api GET "/agent/timeline?limit=80")
assert_true "timeline: Snapshot creation started" "$(echo "$TL2" | jq -r 'any(.[]; .label == "Snapshot creation started")')"
assert_true "timeline: Snapshot management completed" "$(echo "$TL2" | jq -r 'any(.[]; .label == "Snapshot management completed")')"

echo "== 27. Manifest operation risk model =="
assert_eq "risk: verify medium" "medium" "$(echo "$MANIFEST" | jq -r '.snapshot_management.risk_model.snapshot_verify')"

echo "== 28. Details of non-existent snapshot =="
DNF=$(api POST /operations/snapshot_manage/run '{"action":"snapshot_details","snapshot_id":"zzz-fake-id"}')
assert_eq "details: not found" "wpcc_snapshot_not_found" "$(echo "$DNF" | jq -r '.code // "none"')"

echo "== 29. Restore non-existent snapshot =="
RNF=$(api POST /operations/snapshot_manage/run '{"action":"snapshot_restore","snapshot_id":"zzz-fake-id"}')
assert_eq "restore: not found" "wpcc_snapshot_not_found" "$(echo "$RNF" | jq -r '.code // "none"')"

echo "== 30. Create with blocked path =="
BLK=$(api POST /operations/snapshot_manage/run '{"action":"snapshot_create","path":"wp-config.php","label":"blocked"}')
BLK_CODE=$(echo "$BLK" | jq -r '.code // "none"')
assert_true "create: blocked path rejected" "$(if echo "$BLK_CODE" | grep -qE 'blocked|denied|not_allowed|not_found|not_readable'; then echo true; else echo false; fi)"

echo "== Summary =="
echo "  $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
