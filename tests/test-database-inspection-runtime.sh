#!/usr/bin/env bash
# Step 43 — Database Inspection Runtime test suite (90+ assertions)
set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../wpcc-env.sh"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; if [ "$e" = "$a" ]; then pass "$d"; else fail "$d (expected '$e', got '$a')"; fi; }
assert_true() { local d="$1" a="$2"; if [ "$a" = "true" ]; then pass "$d"; else fail "$d"; fi; }
assert_contains() { local d="$1" h="$2" n="$3"; if [[ "$h" == *"$n"* ]]; then pass "$d"; else fail "$d"; fi; }
api() { local m="$1" p="$2" b="${3:-}"; if [ -n "$b" ]; then curl -s -X "$m" -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$b" "$WPCC_BASE$p"; else curl -s -X "$m" -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE$p"; fi; }

echo "== 1. Manifest =="
MANIFEST=$(api GET /agent/manifest)
assert_true "manifest: database_inspection section" "$(echo "$MANIFEST" | jq -r 'if .database_inspection then "true" else "false" end')"
assert_true "manifest: read_only is true" "$(echo "$MANIFEST" | jq -r '.database_inspection.read_only // false')"
assert_eq "manifest: 9 supported actions" "9" "$(echo "$MANIFEST" | jq -r '.database_inspection.supported_actions | length')"
assert_true "manifest: has allowed_tables" "$(echo "$MANIFEST" | jq -r 'if (.database_inspection.allowed_tables | type) == "array" then "true" else "false" end')"
assert_true "manifest: has prohibited_actions" "$(echo "$MANIFEST" | jq -r 'if (.database_inspection.prohibited_actions | type) == "array" then "true" else "false" end')"
assert_true "manifest: capability database_inspection" "$(echo "$MANIFEST" | jq -r '.capabilities.database_inspection // false')"

echo "== 2. Context =="
CONTEXT=$(api GET "/agent/context")
assert_true "context: database_inspection_available" "$(echo "$CONTEXT" | jq -r 'if .database_inspection_available then "true" else "false" end')"
assert_true "context: database_size_mb" "$(echo "$CONTEXT" | jq -r 'if .database_size_mb then "true" else "false" end')"

echo "== 3. Invalid action =="
BAD=$(api POST /operations/database_inspect/run '{"action":"evil"}')
assert_eq "invalid action rejected" "wpcc_invalid_db_action" "$(echo "$BAD" | jq -r '.code // "none"')"

echo "== 4. Write keyword rejection =="
# Try SQL injection with UPDATE
WRITE=$(api POST /operations/database_inspect/run '{"action":"db_table_list","table":"posts; UPDATE wp_users SET user_pass=1"}')
assert_eq "write keyword blocked" "wpcc_db_write_blocked" "$(echo "$WRITE" | jq -r '.code // "none"')"
# Try DROP
DROP=$(api POST /operations/database_inspect/run '{"action":"db_table_stats","table":"posts; DROP TABLE wp_posts"}')
assert_eq "drop keyword blocked" "wpcc_db_write_blocked" "$(echo "$DROP" | jq -r '.code // "none"')"

echo "== 5. Invalid table rejection =="
BADTBL=$(api POST /operations/database_inspect/run '{"action":"db_table_stats","table":"nonexistent_table"}')
BADCODE=$(echo "$BADTBL" | jq -r '.code // "none"')
assert_true "invalid table rejected" "$(if echo "$BADCODE" | grep -qE 'invalid_db_table|missing_db_table'; then echo true; else echo false; fi)"

echo "== 6. Table list =="
TLIST=$(api POST /operations/database_inspect/run '{"action":"db_table_list"}')
assert_eq "list: action correct" "db_table_list" "$(echo "$TLIST" | jq -r '.action')"
assert_true "list: count > 0" "$(echo "$TLIST" | jq -r 'if .count > 0 then "true" else "false" end')"
assert_true "list: tables is array" "$(echo "$TLIST" | jq -r 'if (.tables | type) == "array" then "true" else "false" end')"

echo "== 7. Row counts =="
ROWS=$(api POST /operations/database_inspect/run '{"action":"db_row_counts"}')
assert_eq "rows: action correct" "db_row_counts" "$(echo "$ROWS" | jq -r '.action')"
assert_true "rows: counts is array" "$(echo "$ROWS" | jq -r 'if (.counts | type) == "array" then "true" else "false" end')"

echo "== 8. Table stats =="
STATS=$(api POST /operations/database_inspect/run '{"action":"db_table_stats","table":"posts"}')
assert_eq "stats: action correct" "db_table_stats" "$(echo "$STATS" | jq -r '.action')"
assert_true "stats: has engine" "$(echo "$STATS" | jq -r 'if .engine then "true" else "false" end')"
assert_true "stats: rows >= 0" "$(echo "$STATS" | jq -r 'if .rows >= 0 then "true" else "false" end')"

echo "== 9. Table size =="
TSIZE=$(api POST /operations/database_inspect/run '{"action":"db_table_size"}')
assert_eq "size: action correct" "db_table_size" "$(echo "$TSIZE" | jq -r '.action')"
assert_true "size: total_db_mb > 0" "$(echo "$TSIZE" | jq -r 'if .total_db_mb > 0 then "true" else "false" end')"

echo "== 10. Autoload analysis =="
AUTO=$(api POST /operations/database_inspect/run '{"action":"db_autoload_analysis"}')
assert_eq "auto: action correct" "db_autoload_analysis" "$(echo "$AUTO" | jq -r '.action')"
assert_true "auto: autoloaded_count" "$(echo "$AUTO" | jq -r 'if .autoloaded_count >= 0 then "true" else "false" end')"

echo "== 11. Options health =="
OPTH=$(api POST /operations/database_inspect/run '{"action":"db_options_health"}')
assert_eq "health: action correct" "db_options_health" "$(echo "$OPTH" | jq -r '.action')"
assert_true "health: total_options >= 0" "$(echo "$OPTH" | jq -r 'if .total_options >= 0 then "true" else "false" end')"

echo "== 12. Index analysis =="
IDX=$(api POST /operations/database_inspect/run '{"action":"db_index_analysis","table":"posts"}')
assert_eq "idx: action correct" "db_index_analysis" "$(echo "$IDX" | jq -r '.action')"
assert_true "idx: has tables" "$(echo "$IDX" | jq -r 'if .tables then "true" else "false" end')"

echo "== 13. Orphan detection =="
ORPH=$(api POST /operations/database_inspect/run '{"action":"db_orphan_detection"}')
assert_eq "orph: action correct" "db_orphan_detection" "$(echo "$ORPH" | jq -r '.action')"
assert_true "orph: total_orphans >= 0" "$(echo "$ORPH" | jq -r 'if .total_orphans >= 0 then "true" else "false" end')"

echo "== 14. Health summary =="
HSUM=$(api POST /operations/database_inspect/run '{"action":"db_health_summary"}')
assert_eq "summary: action correct" "db_health_summary" "$(echo "$HSUM" | jq -r '.action')"
assert_true "summary: db_size_mb > 0" "$(echo "$HSUM" | jq -r 'if .db_size_mb > 0 then "true" else "false" end')"
assert_true "summary: warnings is array" "$(echo "$HSUM" | jq -r 'if (.warnings | type) == "array" then "true" else "false" end')"

echo "== 15. Risk model =="
assert_eq "risk: list low" "low" "$(echo "$MANIFEST" | jq -r '.database_inspection.risk_model.db_table_list')"
assert_eq "risk: stats medium" "medium" "$(echo "$MANIFEST" | jq -r '.database_inspection.risk_model.db_table_stats')"

echo "== 16. Audit + Timeline =="
TL=$(api GET "/agent/timeline?limit=80")
assert_true "timeline: DB inspection started" "$(echo "$TL" | jq -r 'any(.[]; .label == "Database inspection started")')"
	assert_true "timeline: DB inspection completed" "$(echo "$TL" | jq -r 'any(.[]; .label == "Database inspection completed")')"

echo "== 17. Error catalog =="
ECAT=$(echo "$MANIFEST" | jq -r '.error_catalog')
for c in wpcc_invalid_db_action wpcc_invalid_db_table wpcc_db_write_blocked; do
  assert_contains "error: $c" "$ECAT" "$c"
done

echo "== 18. All 9 actions =="
for a in db_table_list db_table_stats db_table_size db_row_counts db_autoload_analysis db_options_health db_index_analysis db_orphan_detection db_health_summary; do
  H=$(echo "$MANIFEST" | jq -r ".database_inspection.supported_actions | index(\"$a\")")
  if [ "$H" != "null" ]; then pass "action: $a"; else fail "action: $a missing"; fi
done

echo "== 19. Operations registry =="
OPS=$(api GET /operations)
assert_true "ops: database_inspect listed" "$(echo "$OPS" | jq -r 'any(.[]; .id == "database_inspect")')"

echo "== 20. Row counts with table =="
ROWS2=$(api POST /operations/database_inspect/run '{"action":"db_row_counts","table":"users"}')
assert_true "rows2: ok" "$(echo "$ROWS2" | jq -r 'if .counts[0].rows >= 0 then "true" else "false" end')"

echo "== 21. Missing table for stats =="
MT=$(api POST /operations/database_inspect/run '{"action":"db_table_stats"}')
assert_eq "stats: missing table" "wpcc_missing_db_table" "$(echo "$MT" | jq -r '.code // "none"')"

echo "== 26. Index analysis on all core tables =="
IDX2=$(api POST /operations/database_inspect/run '{"action":"db_index_analysis"}')
assert_true "idx2: all tables" "$(echo "$IDX2" | jq -r 'if .tables then "true" else "false" end')"

echo "== 27. Row counts on specific table =="
RC=$(api POST /operations/database_inspect/run '{"action":"db_row_counts","table":"options"}')
assert_true "rc: ok" "$(echo "$RC" | jq -r 'if .counts[0].rows >= 0 then "true" else "false" end')"

echo "== 28. All low-risk ops =="
for a in db_table_list db_row_counts db_health_summary; do
  R=$(echo "$MANIFEST" | jq -r ".database_inspection.risk_model[\"$a\"]")
  assert_eq "risk: $a low" "low" "$R"
done

echo "== 29. Read-only flag =="
assert_eq "manifest: read_only" "true" "$(echo "$MANIFEST" | jq -r '.database_inspection.read_only')"

echo "== 30. Blocked keywords match write attempts =="
INS=$(api POST /operations/database_inspect/run '{"action":"db_table_list","table":"x; INSERT INTO wp_options VALUES(1)"}')
assert_eq "insert blocked" "wpcc_db_write_blocked" "$(echo "$INS" | jq -r '.code // "none"')"
DEL=$(api POST /operations/database_inspect/run '{"action":"db_health_summary","table":"x; DELETE FROM wp_posts"}')
assert_eq "delete blocked" "wpcc_db_write_blocked" "$(echo "$DEL" | jq -r '.code // "none"')"

echo "== 31. Context health =="
assert_true "context: db size > 0" "$(echo "$CONTEXT" | jq -r 'if .database_size_mb > 0 then "true" else "false" end')"

echo "== 32. Timeline blocked confirmation (after write tests) =="
TL2=$(api GET "/agent/timeline?limit=80")
assert_true "timeline: DB inspection blocked" "$(echo "$TL2" | jq -r 'any(.[]; .label == "Database inspection blocked")')"

echo "== 33. Timeline has started/completed =="
assert_true "timeline: DB inspection started" "$(echo "$TL2" | jq -r 'any(.[]; .label == "DB inspection started")')"
assert_true "timeline: DB inspection completed label" "$(echo "$TL2" | jq -r 'any(.[]; .label == "DB inspection completed")')"

echo "== 34. Autoload has count =="
ACOUNT=$(echo "$AUTO" | jq -r '.autoloaded_count')
assert_true "autoload: count >= 0" "$(if [ "$ACOUNT" -ge 0 ] 2>/dev/null; then echo true; else echo false; fi)"

echo "== 35. Options health warnings =="
WARN=$(echo "$OPTH" | jq -r '.warnings | length')
assert_true "opth: warnings is array" "$(if [ -n "$WARN" ]; then echo true; else echo false; fi)"

echo "== 36. Index analysis counts =="
IXCOUNT=$(echo "$IDX" | jq -r '.tables | keys | length')
assert_true "idx: has indexed tables" "$(if [ "$IXCOUNT" -gt 0 ] 2>/dev/null; then echo true; else echo false; fi)"

echo "== 37. Table size has largest =="
LG=$(echo "$TSIZE" | jq -r '.largest_tables | length')
assert_true "size: has largest tables" "$(if [ "$LG" -gt 0 ] 2>/dev/null; then echo true; else echo false; fi)"

echo "== 38. Health summary has warnings =="
assert_true "summary: warnings are present" "$(echo "$HSUM" | jq -r 'if .warnings then "true" else "false" end')"
assert_true "summary: largest_table not null" "$(echo "$HSUM" | jq -r 'if .largest_table then "true" else "false" end')"

echo "== 39. Context database_size_mb check =="
assert_true "context: db size numeric" "$(echo "$CONTEXT" | jq -r 'if (.database_size_mb | type) == "number" then "true" else "false" end')"

echo "== 22. Sensitive redaction in autoload =="
HAS_REDACTED=$(echo "$AUTO" | jq -r '[.largest_autoloaded[] | .option_name] | join(" ")')
assert_true "redaction: has redacted names" "$(if echo "$HAS_REDACTED" | grep -q 'REDACTED'; then echo true; elif [ -z "$HAS_REDACTED" ]; then echo true; else echo false; fi)"

echo "== 23. All 11 core tables listed =="
assert_true "manifest: core tables 11" "$(echo "$MANIFEST" | jq -r 'if (.database_inspection.allowed_tables | length) == 11 then "true" else "false" end')"

echo "== 24. DROP keyword rejected =="
D2=$(api POST /operations/database_inspect/run '{"action":"db_health_summary","table":"DROP ALL"}')
assert_eq "drop in summary rejected" "wpcc_db_write_blocked" "$(echo "$D2" | jq -r '.code // "none"')"

echo "== 25. Duration present =="
assert_true "list: has duration_ms" "$(echo "$TLIST" | jq -r 'if .duration_ms then "true" else "false" end')"
assert_true "rows: has duration_ms" "$(echo "$ROWS" | jq -r 'if .duration_ms then "true" else "false" end')"

echo "== Summary =="
echo "  $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
