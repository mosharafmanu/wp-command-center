#!/usr/bin/env bash
#
# Regression: multiple ops on the SAME file within one change set must COMPOSE
# (chain on evolving content), not clobber each other (last-write-wins).
#
# Before the fix, PatchOperation::normalize_files() resolved every op against the
# same on-disk original, so two edits to one file kept only the last one. The fix
# chains same-path ops and collapses them into a single cumulative entry.
#
# patch_preview does NOT write to disk, so this is non-destructive.
#
# Requires: curl, jq, wpcc-env.sh (full-scope token), active theme hello-elementor.
# Usage: bash tests/test-patch-samepath-chain.sh

set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
# shellcheck source=/dev/null
source "$PLUGIN_DIR/wpcc-env.sh"

PASS=0; FAIL=0
pass(){ PASS=$((PASS+1)); echo "  PASS: $1"; }
fail(){ FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq(){ local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }
pj(){ printf '%s' "$1" | jq -r "$2"; }
pm(){ curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/operations/patch_manage/run"; }

# A safe file (preview never writes). Two distinct appends to the SAME path.
SAFE_FILE="plugins/ai-command-center/readme.txt"
M1="WPCC_SAMEPATH_MARKER_ONE"
M2="WPCC_SAMEPATH_MARKER_TWO"

echo "== Two append ops on one file in a single change set =="
REQ=$(jq -nc --arg p "$SAFE_FILE" --arg a "\n// $M1\n" --arg b "\n// $M2\n" \
  '{action:"patch_preview",files:[{path:$p,mode:"append",content:$a},{path:$p,mode:"append",content:$b}]}')
PV=$(pm "$REQ")

# 1) The two same-path ops collapse into a single change-set entry.
assert_eq "same-path ops collapse to one preview entry" "1" "$(pj "$PV" '.previews | length')"

# 2) BOTH edits are present (composition), not just the last one.
HAS1=$(printf '%s' "$PV" | grep -c "$M1")
HAS2=$(printf '%s' "$PV" | grep -c "$M2")
[ "$HAS1" -ge 1 ] && pass "first op ($M1) preserved (not clobbered)" || fail "first op ($M1) missing — last-write-wins regression"
[ "$HAS2" -ge 1 ] && pass "second op ($M2) present" || fail "second op ($M2) missing"

echo
echo "== Summary =="
echo "  PASS: $PASS  FAIL: $FAIL"
[ "$FAIL" -eq 0 ]
