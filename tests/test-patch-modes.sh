#!/usr/bin/env bash
#
# Patch Engine — precise patch-mode + agent-usability acceptance suite.
#
# Reproduces the live-site confusion that motivated this work:
#   - a wrong/mistyped field name was silently ignored (looked like a wipe),
#   - the correct content field (modified) was undocumented,
#   - a small edit required resending the whole file.
#
# Proves the fixes through BOTH REST (+token) and MCP via the same shared
# service layer (OperationExecutor → PatchOperation → PatchModeResolver →
# PatchManager / PatchApproval). Rollback, approval, PatchGuard and syntax
# verification must all still hold.
#
# Requires: curl, jq, wp (security-mode reset only), wpcc-env.sh.
# Usage: bash tests/test-patch-modes.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
# shellcheck source=/dev/null
source "$PLUGIN_DIR/wpcc-env.sh"

WP_ROOT="$(cd "$SCRIPT_DIR/../../../.." && pwd)"
WP_PATH="$WP_ROOT"
PLUGINS_DIR="$WP_ROOT/wp-content/plugins"

SANDBOX="$PLUGINS_DIR/wpcc-patch-modes-sandbox"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }
assert_nonempty() { local d="$1" a="$2"; { [ -n "$a" ] && [ "$a" != "null" ]; } && pass "$d" || fail "$d (empty/null)"; }

pj() { printf '%s' "$1" | jq -r "$2"; }

rest()      { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$2" "$WPCC_BASE/operations/$1/run"; }
mcp()       { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/mcp"; }
mcp_text()  { mcp "$1" | jq -r '.result.content[0].text // empty'; }
set_mode()  { wp eval "update_option('wpcc_security_mode', '$1');" --path="$WP_PATH" >/dev/null 2>&1; }

# Create + apply a patch in one shot, echoing the apply status.
apply_files() {
  local files="$1"
  local pid
  pid=$(pj "$(rest patch_manage "$(jq -n --argjson f "$files" '{action:"patch_create",files:$f,explanation:"patch-modes test"}')")" '.patch_id')
  [ -z "$pid" ] || [ "$pid" = "null" ] && { echo "CREATE_FAILED"; return; }
  pj "$(rest patch_manage "$(jq -n --arg id "$pid" '{action:"patch_apply",patch_id:$id}')")" '.status'
}

cleanup() { set_mode "developer"; rm -rf "$SANDBOX" 2>/dev/null; }
trap cleanup EXIT

set_mode "developer"
mkdir -p "$SANDBOX/sub"
TARGET="$SANDBOX/sub/target.php"
REL="plugins/wpcc-patch-modes-sandbox/sub/target.php"
printf '<?php\n$wpcc_value = "v1";\n' > "$TARGET"

echo "== 1. Wrong field name fails clearly (the live bug) =="
# content without a mode is the exact mistake — must NOT be silently ignored.
REQ=$(jq -n --arg p "$REL" '{action:"patch_preview",files:[{path:$p,content:"<?php $x=1;"}]}')
R=$(rest patch_manage "$REQ")
assert_eq "content-without-mode rejected with structured code" "wpcc_unknown_patch_field" "$(pj "$R" '.code // "NONE"')"
# A genuinely unknown extra field on a valid whole_file entry is also rejected.
REQ=$(jq -n --arg p "$REL" --arg m '<?php $wpcc_value="v2";' '{action:"patch_preview",files:[{path:$p,modified:$m,new_content:"oops"}]}')
R=$(rest patch_manage "$REQ")
assert_eq "unknown field on whole_file rejected" "wpcc_unknown_patch_field" "$(pj "$R" '.code // "NONE"')"
# Unknown TOP-LEVEL parameter is rejected too.
REQ=$(jq -n --arg p "$REL" --arg m '<?php $wpcc_value="v2";' '{action:"patch_preview",files:[{path:$p,modified:$m}],bogus:"x"}')
R=$(rest patch_manage "$REQ")
assert_eq "unknown top-level param rejected" "wpcc_unknown_patch_field" "$(pj "$R" '.code // "NONE"')"

echo "== 2. The documented 'modified' field works (whole_file) =="
REQ=$(jq -n --arg p "$REL" --arg m $'<?php\n$wpcc_value = "v2";\n' '{action:"patch_preview",files:[{path:$p,modified:$m}]}')
R=$(rest patch_manage "$REQ")
assert_eq "whole_file preview syntax_ok" "true" "$(pj "$R" '.syntax_ok')"
assert_eq "whole_file preview changed" "true" "$(pj "$R" '.files[0].changed')"
assert_eq "whole_file preview patch_type" "whole_file" "$(pj "$R" '.files[0].patch_type')"
assert_eq "whole_file preview is_whole_file_replacement" "true" "$(pj "$R" '.files[0].is_whole_file_replacement')"

echo "== 3. A small append does NOT require resending the whole file =="
REQ=$(jq -n --arg p "$REL" '{action:"patch_preview",files:[{path:$p,mode:"append",content:"$wpcc_extra = \"x\";"}]}')
R=$(rest patch_manage "$REQ")
assert_eq "append preview patch_type partial" "partial" "$(pj "$R" '.files[0].patch_type')"
assert_eq "append preview not whole-file" "false" "$(pj "$R" '.files[0].is_whole_file_replacement')"
assert_eq "append preview lines_removed is 0" "0" "$(pj "$R" '.files[0].lines_removed')"
assert_eq "append preview reports syntax_ok" "true" "$(pj "$R" '.syntax_ok')"
# Apply it and confirm BOTH the original and the appended line survive.
STATUS=$(apply_files "$(jq -n --arg p "$REL" '[{path:$p,mode:"append",content:"$wpcc_extra = \"x\";"}]')")
assert_eq "append apply status applied" "applied" "$STATUS"
assert_eq "append kept original line" "1" "$(grep -c 'wpcc_value = "v1"' "$TARGET")"
assert_eq "append added new line" "1" "$(grep -c 'wpcc_extra = "x"' "$TARGET")"
# restore for the next case
printf '<?php\n$wpcc_value = "v1";\n' > "$TARGET"

echo "== 4. replace_text changes ONLY the target text =="
printf '<?php\n$a = "keep";\n$wpcc_value = "v1";\n$b = "keep";\n' > "$TARGET"
REQ=$(jq -n --arg p "$REL" '{action:"patch_preview",files:[{path:$p,mode:"replace_text",find:"\"v1\"",replace:"\"v2\""}]}')
R=$(rest patch_manage "$REQ")
assert_eq "replace_text preview partial" "partial" "$(pj "$R" '.files[0].patch_type')"
assert_eq "replace_text preview 1 occurrence" "1" "$(pj "$R" '.files[0].summary | test("Replaces 1 of 1") | if . then "1" else "0" end')"
STATUS=$(apply_files "$(jq -n --arg p "$REL" '[{path:$p,mode:"replace_text",find:"\"v1\"",replace:"\"v2\""}]')")
assert_eq "replace_text apply status applied" "applied" "$STATUS"
assert_eq "replace_text changed the target" "1" "$(grep -c 'wpcc_value = "v2"' "$TARGET")"
assert_eq "replace_text left surrounding lines intact" "2" "$(grep -c '= "keep"' "$TARGET")"
# replace_text with a find that is absent fails cleanly.
R=$(rest patch_manage "$(jq -n --arg p "$REL" '{action:"patch_preview",files:[{path:$p,mode:"replace_text",find:"NOPE_NOT_THERE",replace:"x"}]}')")
assert_eq "replace_text missing find → structured error" "wpcc_patch_text_not_found" "$(pj "$R" '.code // "NONE"')"

echo "== 5. A large file is edited without reconstructing the whole file =="
# 500-line file; we send only a one-line replace_range and a short append.
{ echo '<?php'; for i in $(seq 1 499); do echo "\$line_$i = $i;"; done; } > "$TARGET"
LINES_BEFORE=$(wc -l < "$TARGET" | tr -d ' ')
# replace_range: swap line 250 only.
REQ=$(jq -n --arg p "$REL" '{action:"patch_preview",files:[{path:$p,mode:"replace_range",start_line:250,end_line:250,content:"$line_249 = 99999;"}]}')
R=$(rest patch_manage "$REQ")
assert_eq "replace_range preview partial" "partial" "$(pj "$R" '.files[0].patch_type')"
assert_eq "replace_range preview lines_removed 1" "1" "$(pj "$R" '.files[0].lines_removed')"
STATUS=$(apply_files "$(jq -n --arg p "$REL" '[{path:$p,mode:"replace_range",start_line:250,end_line:250,content:"$line_249 = 99999;"}]')")
assert_eq "replace_range apply status applied" "applied" "$STATUS"
assert_eq "large file kept its line count" "$LINES_BEFORE" "$(wc -l < "$TARGET" | tr -d ' ')"
assert_eq "large file line 1 still present" "1" "$(grep -c '\$line_1 = 1;' "$TARGET")"
assert_eq "large file targeted line changed" "1" "$(grep -c '99999' "$TARGET")"
assert_eq "large file untouched neighbor present" "1" "$(grep -c '\$line_498 = 498;' "$TARGET")"

echo "== 6. unified_diff mode applies a hunk =="
printf '<?php\n$one = 1;\n$two = 2;\n$three = 3;\n' > "$TARGET"
DIFF=$'--- a/'"$REL"$'\n+++ b/'"$REL"$'\n@@ -2,3 +2,3 @@\n $one = 1;\n-$two = 2;\n+$two = 222;\n $three = 3;'
REQ=$(jq -n --arg p "$REL" --arg d "$DIFF" '{action:"patch_preview",files:[{path:$p,mode:"unified_diff",diff:$d}]}')
R=$(rest patch_manage "$REQ")
assert_eq "unified_diff preview partial" "partial" "$(pj "$R" '.files[0].patch_type')"
assert_eq "unified_diff preview syntax_ok" "true" "$(pj "$R" '.syntax_ok')"
STATUS=$(apply_files "$(jq -n --arg p "$REL" --arg d "$DIFF" '[{path:$p,mode:"unified_diff",diff:$d}]')")
assert_eq "unified_diff apply status applied" "applied" "$STATUS"
assert_eq "unified_diff changed only the hunk line" "1" "$(grep -c '\$two = 222;' "$TARGET")"
assert_eq "unified_diff left other lines intact" "1" "$(grep -c '\$three = 3;' "$TARGET")"
# A stale diff (context that no longer matches) fails loudly, not silently.
BADDIFF=$'@@ -2,1 +2,1 @@\n-$two = NOPE;\n+$two = 0;'
R=$(rest patch_manage "$(jq -n --arg p "$REL" --arg d "$BADDIFF" '{action:"patch_preview",files:[{path:$p,mode:"unified_diff",diff:$d}]}')")
assert_eq "stale unified_diff → structured error" "wpcc_patch_diff_failed" "$(pj "$R" '.code // "NONE"')"

echo "== 7. Safety still holds: invalid mode + replace_range bounds =="
R=$(rest patch_manage "$(jq -n --arg p "$REL" --arg m "x" '{action:"patch_preview",files:[{path:$p,mode:"frobnicate",modified:$m}]}')")
assert_eq "invalid mode rejected" "wpcc_invalid_patch_mode" "$(pj "$R" '.code // "NONE"')"
R=$(rest patch_manage "$(jq -n --arg p "$REL" '{action:"patch_preview",files:[{path:$p,mode:"replace_range",start_line:9000,end_line:9001,content:"x"}]}')")
assert_eq "out-of-range replace_range rejected" "wpcc_patch_range_invalid" "$(pj "$R" '.code // "NONE"')"

echo "== 8. PatchGuard still protects bootstrap headers under precise modes =="
printf '<?php\n/**\n * Plugin Name: WPCC Modes\n */\n$x = "a";\n' > "$SANDBOX/wpcc-patch-modes-sandbox.php"
DREL="plugins/wpcc-patch-modes-sandbox/wpcc-patch-modes-sandbox.php"
# replace_range that deletes the Plugin Name header line must be refused.
R=$(rest patch_manage "$(jq -n --arg p "$DREL" '{action:"patch_preview",files:[{path:$p,mode:"replace_range",start_line:3,end_line:3,content:" * Removed"}]}')")
assert_eq "header-stripping patch flagged unsafe in preview" "false" "$(pj "$R" '.files[0].header_safe')"

echo "== 9. Rollback still works after a precise-mode apply =="
printf '<?php\n$wpcc_value = "v1";\n' > "$TARGET"
PID=$(pj "$(rest patch_manage "$(jq -n --arg p "$REL" '{action:"patch_create",files:[{path:$p,mode:"append",content:"$rb = 1;"}],explanation:"rollback test"}')")" '.patch_id')
assert_nonempty "precise-mode patch_create id" "$PID"
R=$(rest patch_manage "$(jq -n --arg id "$PID" '{action:"patch_apply",patch_id:$id}')")
assert_eq "precise-mode apply applied" "applied" "$(pj "$R" '.status')"
assert_nonempty "precise-mode apply rollback_id" "$(pj "$R" '.rollback_id')"
assert_eq "appended line is on disk" "1" "$(grep -c 'rb = 1' "$TARGET")"
R=$(rest rollback_manage "$(jq -n --arg id "$PID" '{action:"rollback_apply",patch_id:$id}')")
assert_eq "rollback restored" "true" "$(pj "$R" '.restored')"
assert_eq "rollback removed appended line" "0" "$(grep -c 'rb = 1' "$TARGET")"
assert_eq "rollback kept original line" "1" "$(grep -c 'wpcc_value = "v1"' "$TARGET")"

echo "== 10. MCP parity: append mode + unknown-field rejection over MCP =="
printf '<?php\n$wpcc_value = "v1";\n' > "$TARGET"
REQ=$(jq -n --arg p "$REL" '{jsonrpc:"2.0",id:1,method:"tools/call",params:{name:"patch_manage",arguments:{action:"patch_preview",files:[{path:$p,mode:"append",content:"$m = 1;"}]}}}')
R=$(mcp_text "$REQ")
assert_eq "MCP append preview partial" "partial" "$(pj "$R" '.files[0].patch_type')"
REQ=$(jq -n --arg p "$REL" '{jsonrpc:"2.0",id:2,method:"tools/call",params:{name:"patch_manage",arguments:{action:"patch_preview",files:[{path:$p,content:"oops"}]}}}')
R=$(mcp_text "$REQ")
assert_eq "MCP wrong-field rejected (isError)" "true" "$(pj "$R" '.isError // false')"
assert_eq "MCP wrong-field error code" "wpcc_unknown_patch_field" "$(pj "$R" '.code // "NONE"')"

echo
echo "================================================"
echo "  Patch Modes: $PASS passed, $FAIL failed"
echo "================================================"
[ "$FAIL" -eq 0 ]
