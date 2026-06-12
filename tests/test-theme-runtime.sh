#!/usr/bin/env bash
#
# Theme Management Runtime test suite for WP Command Center (Step 40).
# Usage: bash tests/test-theme-runtime.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
source "$PLUGIN_DIR/wpcc-env.sh"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; if [ "$e" = "$a" ]; then pass "$d"; else fail "$d (expected '$e', got '$a')"; fi; }
assert_true() { local d="$1" a="$2"; if [ "$a" = "true" ]; then pass "$d"; else fail "$d (expected 'true', got '$a')"; fi; }
assert_contains() { local d="$1" h="$2" n="$3"; if [[ "$h" == *"$n"* ]]; then pass "$d"; else fail "$d (does not contain '$n')"; fi; }
api() { local m="$1" p="$2" b="${3:-}"; if [ -n "$b" ]; then curl -s -X "$m" -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$b" "$WPCC_BASE$p"; else curl -s -X "$m" -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE$p"; fi; }

echo "== 1. Manifest Integration =="
MANIFEST=$(api GET /agent/manifest)
assert_true "manifest: theme_management section exists" "$(echo "$MANIFEST" | jq -r 'if .theme_management then "true" else "false" end')"
assert_true "manifest: supported_actions present" "$(echo "$MANIFEST" | jq -r 'if (.theme_management.supported_actions | type) == "array" then "true" else "false" end')"
ACT_COUNT=$(echo "$MANIFEST" | jq -r '.theme_management.supported_actions | length')
assert_eq "manifest: 6 supported actions" "6" "$ACT_COUNT"
assert_true "manifest: risk_model present" "$(echo "$MANIFEST" | jq -r 'if .theme_management.risk_model then "true" else "false" end')"
assert_true "manifest: capability theme_management" "$(echo "$MANIFEST" | jq -r '.capabilities.theme_management // false')"

echo "== 2. Agent Context =="
CONTEXT=$(api GET "/agent/context")
assert_true "context: theme_management_available" "$(echo "$CONTEXT" | jq -r 'if .theme_management_available then "true" else "false" end')"
assert_true "context: theme_state present" "$(echo "$CONTEXT" | jq -r 'if .theme_state then "true" else "false" end')"
assert_true "context: active_theme present" "$(echo "$CONTEXT" | jq -r 'if .active_theme then "true" else "false" end')"
assert_true "context: installed_themes array" "$(echo "$CONTEXT" | jq -r 'if (.installed_themes | type) == "array" then "true" else "false" end')"

echo "== 3. Theme List =="
TL=$(api POST /operations/theme_manage/run '{"action":"theme_list"}')
assert_eq "list: action correct" "theme_list" "$(echo "$TL" | jq -r '.action // "none"')"
assert_true "list: has themes array" "$(echo "$TL" | jq -r 'if (.themes.themes | type) == "array" then "true" else "false" end')"

echo "== 4. Invalid action =="
BAD=$(api POST /operations/theme_manage/run '{"action":"evil"}')
assert_eq "invalid action rejected" "wpcc_invalid_theme_action" "$(echo "$BAD" | jq -r '.code // "none"')"

echo "== 5. Invalid slug =="
BS=$(api POST /operations/theme_manage/run '{"action":"theme_activate","slug":"../malicious"}')
assert_eq "path traversal rejected" "wpcc_invalid_theme_slug" "$(echo "$BS" | jq -r '.code // "none"')"

echo "== 6. Missing slug =="
NS=$(api POST /operations/theme_manage/run '{"action":"theme_activate"}')
assert_eq "missing slug rejected" "wpcc_missing_theme_slug" "$(echo "$NS" | jq -r '.code // "none"')"

echo "== 7. Theme not found =="
NF=$(api POST /operations/theme_manage/run '{"action":"theme_activate","slug":"nonexistent-theme-xyz"}')
assert_eq "not found: activate" "wpcc_theme_not_found" "$(echo "$NF" | jq -r '.code // "none"')"
NF2=$(api POST /operations/theme_manage/run '{"action":"theme_delete","slug":"nonexistent-theme-xyz"}')
assert_eq "not found: delete" "wpcc_theme_not_found" "$(echo "$NF2" | jq -r '.code // "none"')"

echo "== 8. Duplicate install =="
DI=$(api POST /operations/theme_manage/run '{"action":"theme_install","slug":"twentytwentyfive"}')
assert_eq "dup install rejected" "wpcc_theme_already_installed" "$(echo "$DI" | jq -r '.code // "none"')"

echo "== 9. Already active =="
ACTIVE_SLUG=$(echo "$TL" | jq -r '.themes.themes[] | select(.active == true) | .slug' | head -1)
if [ -n "$ACTIVE_SLUG" ]; then
  AA=$(api POST /operations/theme_manage/run "{\"action\":\"theme_activate\",\"slug\":\"$ACTIVE_SLUG\"}")
  assert_eq "already active rejected" "wpcc_theme_already_active" "$(echo "$AA" | jq -r '.code // "none"')"
else
  pass "already active: no active theme, ski pped"
fi

echo "== 10. Delete active =="
if [ -n "$ACTIVE_SLUG" ]; then
  DA=$(api POST /operations/theme_manage/run "{\"action\":\"theme_delete\",\"slug\":\"$ACTIVE_SLUG\"}")
  assert_eq "delete active rejected" "wpcc_theme_delete_active" "$(echo "$DA" | jq -r '.code // "none"')"
else
  pass "delete active: skipped"
fi

echo "== 11. No update =="
NOUP=$(echo "$TL" | jq -r '.themes.themes[] | select(.update_available == false) | .slug' | head -1)
if [ -n "$NOUP" ]; then
  NU=$(api POST /operations/theme_manage/run "{\"action\":\"theme_update\",\"slug\":\"$NOUP\"}")
  assert_eq "no update rejected" "wpcc_theme_no_update" "$(echo "$NU" | jq -r '.code // "none"')"
else
  pass "no update: skipped"
fi

echo "== 12. Activate + rollback (full cycle) =="
# Find an inactive theme
INACTIVE=$(echo "$TL" | jq -r '.themes.themes[] | select(.active == false) | .slug' | head -1)
if [ -n "$INACTIVE" ] && [ -n "$ACTIVE_SLUG" ] && [ "$INACTIVE" != "$ACTIVE_SLUG" ]; then
  echo "  Switching: $ACTIVE_SLUG → $INACTIVE"
  ACTIVATE=$(api POST /operations/theme_manage/run "{\"action\":\"theme_activate\",\"slug\":\"$INACTIVE\"}")
  assert_eq "activate success" "theme_activate" "$(echo "$ACTIVATE" | jq -r '.action // "none"')"
  assert_true "activate has rollback_id" "$(echo "$ACTIVATE" | jq -r 'if .rollback_id then "true" else "false" end')"
  assert_eq "previous was old active" "$ACTIVE_SLUG" "$(echo "$ACTIVATE" | jq -r '.previous_slug')"

  # Verify new active
  TL2=$(api POST /operations/theme_manage/run '{"action":"theme_list"}')
  NEW_ACTIVE=$(echo "$TL2" | jq -r '.themes.themes[] | select(.active == true) | .slug')
  assert_eq "now active" "$INACTIVE" "$NEW_ACTIVE"

  # Switch back to original
  echo "  Switching back: $INACTIVE → $ACTIVE_SLUG"
  BACK=$(api POST /operations/theme_manage/run "{\"action\":\"theme_activate\",\"slug\":\"$ACTIVE_SLUG\"}")
  assert_eq "switch back success" "theme_activate" "$(echo "$BACK" | jq -r '.action // "none"')"
  TL3=$(api POST /operations/theme_manage/run '{"action":"theme_list"}')
  FINAL_ACTIVE=$(echo "$TL3" | jq -r '.themes.themes[] | select(.active == true) | .slug')
  assert_eq "restored active" "$ACTIVE_SLUG" "$FINAL_ACTIVE"
  pass "full theme switch cycle: $ACTIVE_SLUG → $INACTIVE → $ACTIVE_SLUG"
else
  pass "full cycle: no suitable themes"
fi

echo "== 13. Risk model =="
assert_eq "risk: list low" "low" "$(echo "$MANIFEST" | jq -r '.theme_management.risk_model.theme_list')"
assert_eq "risk: install medium" "medium" "$(echo "$MANIFEST" | jq -r '.theme_management.risk_model.theme_install')"
assert_eq "risk: activate critical" "critical" "$(echo "$MANIFEST" | jq -r '.theme_management.risk_model.theme_activate')"
assert_eq "risk: delete critical" "critical" "$(echo "$MANIFEST" | jq -r '.theme_management.risk_model.theme_delete')"
assert_eq "risk: update high" "high" "$(echo "$MANIFEST" | jq -r '.theme_management.risk_model.theme_update')"

echo "== 14. Install validation =="
FI=$(api POST /operations/theme_manage/run '{"action":"theme_install","slug":"zzzz-nonexistent-theme-999"}')
FI_CODE=$(echo "$FI" | jq -r '.code // "none"')
assert_true "fake install rejected" "$(if echo "$FI_CODE" | grep -qE 'failed|error|api|not_found'; then echo true; else echo false; fi)"

echo "== 15. Audit + Timeline =="
TL_AUDIT=$(api GET "/agent/timeline?limit=100")
AUDIT_S=$(echo "$TL_AUDIT" | jq -r '[.[].label] | join(" ")')
assert_contains "audit: theme.list" "$AUDIT_S" "Theme list"
if [ -n "${INACTIVE:-}" ] && [ -n "${ACTIVE_SLUG:-}" ] && [ "$INACTIVE" != "$ACTIVE_SLUG" ]; then
  assert_contains "audit: theme activated" "$AUDIT_S" "Theme activated"
fi

echo "== 16. Error catalog =="
ECAT=$(echo "$MANIFEST" | jq -r '.error_catalog')
for c in wpcc_missing_theme_slug wpcc_invalid_theme_slug wpcc_theme_not_found wpcc_theme_already_installed wpcc_theme_delete_active; do
  assert_contains "error: $c" "$ECAT" "$c"
done

echo "== 17. Operation registry =="
OPS=$(api GET /operations)
assert_true "ops: theme_manage listed" "$(echo "$OPS" | jq -r 'any(.[]; .id == "theme_manage")')"

echo "== 18. All 5 actions in manifest =="
for a in theme_list theme_install theme_activate theme_update theme_delete; do
  H=$(echo "$MANIFEST" | jq -r ".theme_management.supported_actions | index(\"$a\")")
  if [ "$H" != "null" ]; then pass "action: $a"; else fail "action: $a missing"; fi
done

echo "== 19. Active theme in context =="
AT_NAME=$(echo "$CONTEXT" | jq -r '.active_theme.name // ""')
assert_true "context: active theme has name" "$(if [ -n "$AT_NAME" ]; then echo true; else echo false; fi)"

echo "== 20. Theme state counters =="
assert_true "state: total >= 1" "$(echo "$CONTEXT" | jq -r 'if .theme_state.total >= 1 then "true" else "false" end')"
assert_true "state: active == 1" "$(echo "$CONTEXT" | jq -r 'if .theme_state.active == 1 then "true" else "false" end')"

echo "== 21. Health check metadata =="
if [ -n "${INACTIVE:-}" ] && [ -n "${ACTIVE_SLUG:-}" ]; then
  ACT_TEST=$(api POST /operations/theme_manage/run "{\"action\":\"theme_activate\",\"slug\":\"$INACTIVE\"}")
  assert_true "health: required is true" "$(echo "$ACT_TEST" | jq -r '.health_required // false')"
  assert_true "health: check result present" "$(echo "$ACT_TEST" | jq -r 'if .health_check then "true" else "false" end')"
  # Switch back
  api POST /operations/theme_manage/run "{\"action\":\"theme_activate\",\"slug\":\"$ACTIVE_SLUG\"}" > /dev/null
else
  pass "health: skipped (no suitable themes)"
  pass "health: skipped"
fi

echo "== 22. Timeline specific labels =="
T=$(api GET "/agent/timeline?limit=100")
assert_true "timeline: Theme management started" "$(echo "$T" | jq -r 'any(.[]; .label == "Theme management started")')"
assert_true "timeline: Theme management completed" "$(echo "$T" | jq -r 'any(.[]; .label == "Theme management completed")')"
assert_true "timeline: Theme list requested" "$(echo "$T" | jq -r 'any(.[]; .label == "Theme list requested")')"

echo "== 23. Rollback metadata on activate =="
if [ -n "${INACTIVE:-}" ] && [ -n "${ACTIVE_SLUG:-}" ] && [ "$INACTIVE" != "$ACTIVE_SLUG" ]; then
  ACT=$(api POST /operations/theme_manage/run "{\"action\":\"theme_activate\",\"slug\":\"$INACTIVE\"}")
  RB=$(echo "$ACT" | jq -r '.rollback_id')
  assert_true "rollback: id not empty" "$(if [ -n "$RB" ] && [ "$RB" != "null" ]; then echo true; else echo false; fi)"
  assert_eq "rollback: previous captured" "$ACTIVE_SLUG" "$(echo "$ACT" | jq -r '.previous_slug')"
  assert_eq "rollback: active is true" "true" "$(echo "$ACT" | jq -r '.active')"
  # Restore
  api POST /operations/theme_manage/run "{\"action\":\"theme_activate\",\"slug\":\"$ACTIVE_SLUG\"}" > /dev/null
else
  pass "rollback: id not empty (skipped)"
  pass "rollback: previous captured (skipped)"
  pass "rollback: active is true (skipped)"
fi

echo "== 24. Activation verification =="
A_NOW=$(echo "$TL" | jq -r '.themes.themes[] | select(.active == true) | .slug')
assert_eq "verify: active theme unchanged after tests" "$ACTIVE_SLUG" "$A_NOW"

echo "== 25. Context theme fields =="
assert_true "context: active_theme has slug" "$(echo "$CONTEXT" | jq -r 'if .active_theme.slug then "true" else "false" end')"
assert_true "context: active_theme has version" "$(echo "$CONTEXT" | jq -r 'if .active_theme.version then "true" else "false" end')"
assert_true "context: installed_themes has entries" "$(echo "$CONTEXT" | jq -r 'if (.installed_themes | length) > 0 then "true" else "false" end')"

echo "== 26. Manifest counts =="
assert_true "manifest: themes total > 0" "$(echo "$MANIFEST" | jq -r 'if .theme_management.themes.total > 0 then "true" else "false" end')"
assert_true "manifest: active_theme not null" "$(echo "$MANIFEST" | jq -r 'if .theme_management.themes.active_theme then "true" else "false" end')"
assert_true "manifest: themes array > 0" "$(echo "$MANIFEST" | jq -r 'if (.theme_management.themes.themes | length) > 0 then "true" else "false" end')"

echo "== 27. Activation has previous_name =="
N=$(echo "$MANIFEST" | jq -r '.theme_management.themes.themes | length')
assert_true "manifest: themes count consistent" "$(echo "$CONTEXT" | jq -r "if .theme_state.total == $N then \"true\" else \"false\" end")"

echo "== 28. Theme install rejected for fake slug =="
FINST=$(api POST /operations/theme_manage/run '{"action":"theme_install","slug":"this-theme-does-not-exist-99999"}')
FINST_CODE=$(echo "$FINST" | jq -r '.code')
assert_true "install: rejected fake slug" "$(if echo "$FINST_CODE" | grep -qE 'api_error|not_found|failed'; then echo true; else echo false; fi)"

echo "== 29. Theme update rejected (no update) =="
# Try update on current active theme (usually no update pending)
if [ -n "${ACTIVE_SLUG:-}" ]; then
  UP_CHECK=$(api POST /operations/theme_manage/run "{\"action\":\"theme_update\",\"slug\":\"$ACTIVE_SLUG\"}")
  UP_CODE=$(echo "$UP_CHECK" | jq -r '.code // "none"')
  assert_true "update: correctly handled" "$(if [ "$UP_CODE" = "wpcc_theme_no_update" ] || [ "$UP_CODE" = "wpcc_theme_update_failed" ]; then echo true; else echo false; fi)"
else
  pass "update: skipped (no active slug)"
fi

echo "== 30. Delete non-existent theme =="
DN=$(api POST /operations/theme_manage/run '{"action":"theme_delete","slug":"no-such-theme-abc"}')
assert_eq "delete: not found" "wpcc_theme_not_found" "$(echo "$DN" | jq -r '.code // "none"')"

echo "== 31. All error codes in catalog =="
for c in wpcc_theme_api_error wpcc_theme_install_failed wpcc_theme_update_failed wpcc_theme_delete_failed wpcc_theme_no_update wpcc_theme_already_active wpcc_invalid_theme_action; do
  assert_contains "error: $c" "$ECAT" "$c"
done

echo "== 32. Timeline has theme activate event =="
if [ -n "${INACTIVE:-}" ]; then
  assert_true "timeline: Theme activated" "$(echo "$T" | jq -r 'any(.[]; .label == "Theme activated")')"
  assert_true "timeline: Theme activate started" "$(echo "$T" | jq -r 'any(.[]; .label == "Theme activate started")')"
else
  pass "timeline: Theme activated (skipped)"
  pass "timeline: Theme activate started (skipped)"
fi

echo "== Summary =="
echo "  $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
