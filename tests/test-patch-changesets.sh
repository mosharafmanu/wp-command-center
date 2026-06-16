#!/usr/bin/env bash
#
# STEP 103 — Atomic Multi-File Change Sets acceptance suite.
#
# Proves a single patch_manage call can change one OR many files as one
# transactional change set: one proposal, one preview, one approval,
# all-or-nothing apply (no partial state), one combined rollback. Exercises
# REST + MCP and confirms PatchGuard / syntax verification / approval / rollback
# all still hold.
#
# Requires: curl, jq, wp (security-mode switching only), wpcc-env.sh.
# Usage: bash tests/test-patch-changesets.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
# shellcheck source=/dev/null
source "$PLUGIN_DIR/wpcc-env.sh"

WP_ROOT="$(cd "$SCRIPT_DIR/../../../.." && pwd)"
WP_PATH="$WP_ROOT"
PLUGINS_DIR="$WP_ROOT/wp-content/plugins"
SB="$PLUGINS_DIR/wpcc-cs-sandbox"

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
call()      { jq -nc --argjson a "$1" '{jsonrpc:"2.0",id:1,method:"tools/call",params:{name:"patch_manage",arguments:$a}}'; }

# Reset the sandbox to a known baseline (PHP + JS files).
seed() {
  rm -rf "$SB"; mkdir -p "$SB/inc"
  printf '<?php\n$a = "a1";\n' > "$SB/a.php"
  printf '<?php\n$b = "b1";\n' > "$SB/inc/b.php"
  printf 'export const v = "c1";\n' > "$SB/c.js"
}
A="plugins/wpcc-cs-sandbox/a.php"
B="plugins/wpcc-cs-sandbox/inc/b.php"
C="plugins/wpcc-cs-sandbox/c.js"

cleanup() { set_mode "developer"; rm -rf "$SB" 2>/dev/null; }
trap cleanup EXIT

set_mode "developer"
seed

echo "== 1. Successful 2-file change set (append + replace_text) =="
FILES=$(jq -nc --arg a "$A" --arg c "$C" '[{path:$a,mode:"append",content:"$a2 = \"x\";"},{path:$c,mode:"replace_text",find:"\"c1\"",replace:"\"c2\""}]')
R=$(rest patch_manage "$(jq -nc --argjson f "$FILES" '{action:"patch_preview",files:$f}')")
assert_eq "preview is_change_set=true" "true" "$(pj "$R" '.change_set.is_change_set')"
assert_eq "preview file_count=2" "2" "$(pj "$R" '.change_set.file_count')"
assert_eq "preview affected_paths=2" "2" "$(pj "$R" '.change_set.affected_paths | length')"
assert_eq "preview modes include append+replace_text" "append,replace_text" "$(pj "$R" '.change_set.modes | sort | join(",")')"
assert_eq "preview syntax_ok" "true" "$(pj "$R" '.change_set.syntax_ok')"
PID=$(pj "$(rest patch_manage "$(jq -nc --argjson f "$FILES" '{action:"patch_create",files:$f,explanation:"cs 2-file"}')")" '.change_set_id')
assert_nonempty "create returns change_set_id" "$PID"
R=$(rest patch_manage "$(jq -nc --arg id "$PID" '{action:"patch_apply",patch_id:$id}')")
assert_eq "apply change_set_status=applied" "applied" "$(pj "$R" '.change_set_status')"
assert_eq "apply transactional=true" "true" "$(pj "$R" '.transactional')"
assert_nonempty "apply combined rollback_id" "$(pj "$R" '.rollback_id')"
assert_eq "apply affected_paths=2" "2" "$(pj "$R" '.affected_paths | length')"
assert_eq "file A appended on disk" "1" "$(grep -c 'a2 = "x"' "$SB/a.php")"
assert_eq "file C replaced on disk" "1" "$(grep -c '"c2"' "$SB/c.js")"
echo "-- combined rollback restores BOTH files --"
R=$(rest rollback_manage "$(jq -nc --arg id "$PID" '{action:"rollback_apply",patch_id:$id}')")
assert_eq "rollback restored" "true" "$(pj "$R" '.restored')"
assert_eq "rollback files_restored=2" "2" "$(pj "$R" '.files_restored')"
assert_eq "rollback all_verified" "true" "$(pj "$R" '.all_verified')"
assert_eq "file A back to original" "1" "$(grep -c 'a = "a1"' "$SB/a.php")"
assert_eq "file A appended line gone" "0" "$(grep -c 'a2 = "x"' "$SB/a.php")"
assert_eq "file C back to original" "1" "$(grep -c '"c1"' "$SB/c.js")"

echo "== 2. Successful 3-file change set, mixed modes (append + replace_range + unified_diff) =="
seed
DIFF=$'@@ -1,1 +1,1 @@\n-export const v = "c1";\n+export const v = "c3";'
FILES=$(jq -nc --arg a "$A" --arg b "$B" --arg c "$C" --arg d "$DIFF" \
  '[{path:$a,mode:"append",content:"$a3 = 1;"},{path:$b,mode:"replace_range",start_line:2,end_line:2,content:"$b = \"b3\";"},{path:$c,mode:"unified_diff",diff:$d}]')
R=$(rest patch_manage "$(jq -nc --argjson f "$FILES" '{action:"patch_preview",files:$f}')")
assert_eq "3-file preview file_count=3" "3" "$(pj "$R" '.change_set.file_count')"
assert_eq "3-file preview 3 modes" "3" "$(pj "$R" '.change_set.modes | length')"
PID=$(pj "$(rest patch_manage "$(jq -nc --argjson f "$FILES" '{action:"patch_create",files:$f,explanation:"cs 3-file"}')")" '.change_set_id')
R=$(rest patch_manage "$(jq -nc --arg id "$PID" '{action:"patch_apply",patch_id:$id}')")
assert_eq "3-file apply applied" "applied" "$(pj "$R" '.change_set_status')"
assert_eq "A changed" "1" "$(grep -c 'a3 = 1' "$SB/a.php")"
assert_eq "B changed" "1" "$(grep -c 'b = "b3"' "$SB/inc/b.php")"
assert_eq "C changed" "1" "$(grep -c '"c3"' "$SB/c.js")"
R=$(rest rollback_manage "$(jq -nc --arg id "$PID" '{action:"rollback_apply",patch_id:$id}')")
assert_eq "3-file rollback files_restored=3" "3" "$(pj "$R" '.files_restored')"
assert_eq "A restored" "1" "$(grep -c 'a = "a1"' "$SB/a.php")"
assert_eq "B restored" "1" "$(grep -c 'b = "b1"' "$SB/inc/b.php")"
assert_eq "C restored" "1" "$(grep -c '"c1"' "$SB/c.js")"

echo "== 3. One file fails BEFORE apply → nothing changed (atomic pre-flight) =="
seed
FILES=$(jq -nc --arg a "$A" --arg b "$B" '[{path:$a,mode:"append",content:"$pf = 1;"},{path:$b,mode:"append",content:"$pf = 2;"}]')
PID=$(pj "$(rest patch_manage "$(jq -nc --argjson f "$FILES" '{action:"patch_create",files:$f,explanation:"preflight"}')")" '.change_set_id')
chmod 0444 "$SB/inc/b.php"   # make the 2nd file non-writable AFTER create
R=$(rest patch_manage "$(jq -nc --arg id "$PID" '{action:"patch_apply",patch_id:$id}')")
assert_eq "pre-flight failure code = not_writable" "wpcc_not_writable" "$(pj "$R" '.code // "NONE"')"
assert_eq "file A untouched (no partial apply)" "0" "$(grep -c 'pf = 1' "$SB/a.php")"
chmod 0644 "$SB/inc/b.php"

echo "== 4. Syntax failure in one file blocks the whole set; already-written file restored =="
seed
# File A is valid; file B has a PHP parse error. A is written first, then the set fails on B.
BROKEN=$'<?php\n$b = ;\n'
FILES=$(jq -nc --arg a "$A" --arg b "$B" --arg br "$BROKEN" '[{path:$a,mode:"append",content:"$ok = 1;"},{path:$b,mode:"whole_file",modified:$br}]')
PID=$(pj "$(rest patch_manage "$(jq -nc --argjson f "$FILES" '{action:"patch_create",files:$f,explanation:"syntax fail"}')")" '.change_set_id')
R=$(rest patch_manage "$(jq -nc --arg id "$PID" '{action:"patch_apply",patch_id:$id}')")
assert_eq "legacy status stays failed" "failed" "$(pj "$R" '.status')"
assert_eq "change_set_status=transactional_apply_failed" "transactional_apply_failed" "$(pj "$R" '.change_set_status')"
assert_eq "apply reports restored=true" "true" "$(pj "$R" '.restored')"
assert_eq "verification reason=verification_failed" "verification_failed" "$(pj "$R" '.verification.reason')"
assert_eq "already-written file A restored to original" "1" "$(grep -c 'a = "a1"' "$SB/a.php")"
assert_eq "file A new line rolled back" "0" "$(grep -c 'ok = 1' "$SB/a.php")"
assert_eq "broken file B restored to original" "1" "$(grep -c 'b = "b1"' "$SB/inc/b.php")"

echo "== 5. Single approval covers all files (client mode) =="
seed
FILES=$(jq -nc --arg a "$A" --arg c "$C" '[{path:$a,mode:"append",content:"$ap = 1;"},{path:$c,mode:"append",content:"// note"}]')
PID=$(pj "$(rest patch_manage "$(jq -nc --argjson f "$FILES" '{action:"patch_create",files:$f,explanation:"approval"}')")" '.change_set_id')
set_mode "client"
R=$(rest patch_manage "$(jq -nc --arg id "$PID" '{action:"patch_apply",patch_id:$id}')")
assert_eq "client apply → pending_approval" "pending_approval" "$(pj "$R" '.status')"
assert_eq "approval carries change_set file_count=2" "2" "$(pj "$R" '.change_set.file_count')"
assert_eq "approval lists all affected paths" "2" "$(pj "$R" '.change_set.affected_paths | length')"
assert_eq "approval message mentions change set" "true" "$(pj "$R" '.message | test("change set") ')"
assert_nonempty "single request_id for the whole set" "$(pj "$R" '.request_id')"
set_mode "developer"
assert_eq "files untouched while pending" "0" "$(grep -c 'ap = 1' "$SB/a.php")"

echo "== 6. Single-file backward compatibility (legacy whole-file) =="
seed
PID=$(pj "$(rest patch_manage "$(jq -nc --arg a "$A" --arg m $'<?php\n$a = "legacy";\n' '{action:"patch_create",files:[{path:$a,modified:$m}],explanation:"legacy"}')")" '.patch_id')
assert_nonempty "legacy create patch_id" "$PID"
R=$(rest patch_manage "$(jq -nc --arg a "$A" --arg m $'<?php\n$a = "legacy";\n' '{action:"patch_preview",files:[{path:$a,modified:$m}]}')")
assert_eq "single-file preview is_change_set=false" "false" "$(pj "$R" '.change_set.is_change_set')"
assert_eq "single-file preview whole_file mode" "whole_file" "$(pj "$R" '.files[0].mode')"
R=$(rest patch_manage "$(jq -nc --arg id "$PID" '{action:"patch_apply",patch_id:$id}')")
assert_eq "legacy apply status=applied (unchanged contract)" "applied" "$(pj "$R" '.status')"
assert_nonempty "legacy apply rollback_id" "$(pj "$R" '.rollback_id')"
assert_eq "legacy file applied" "1" "$(grep -c 'a = "legacy"' "$SB/a.php")"
rest rollback_manage "$(jq -nc --arg id "$PID" '{action:"rollback_apply",patch_id:$id}')" >/dev/null
assert_eq "legacy rollback restored" "1" "$(grep -c 'a = "a1"' "$SB/a.php")"

echo "== 7. PatchGuard still blocks protected headers inside a change set =="
seed
printf '<?php\n/**\n * Plugin Name: WPCC CS\n */\n$x = "a";\n' > "$SB/wpcc-cs-sandbox.php"
DREL="plugins/wpcc-cs-sandbox/wpcc-cs-sandbox.php"
# Multi-file set where one entry strips the Plugin Name header → create must be refused.
FILES=$(jq -nc --arg a "$A" --arg d "$DREL" '[{path:$a,mode:"append",content:"$g=1;"},{path:$d,mode:"replace_range",start_line:3,end_line:3,content:" * removed"}]')
R=$(rest patch_manage "$(jq -nc --argjson f "$FILES" '{action:"patch_preview",files:$f}')")
assert_eq "preview flags header-unsafe entry" "false" "$(pj "$R" '.files[1].header_safe')"
R=$(rest patch_manage "$(jq -nc --argjson f "$FILES" '{action:"patch_create",files:$f,explanation:"guard"}')")
assert_eq "header-stripping change set rejected at create" "wpcc_patch_breaks_header" "$(pj "$R" '.code // "NONE"')"
assert_eq "no patch created (atomic)" "null" "$(pj "$R" '.change_set_id // "null"')"

echo "== 8. REST + MCP parity: multi-file change set over MCP =="
seed
FILES=$(jq -nc --arg a "$A" --arg c "$C" '[{path:$a,mode:"append",content:"$mcp=1;"},{path:$c,mode:"replace_text",find:"\"c1\"",replace:"\"mcp\""}]')
R=$(mcp_text "$(call "$(jq -nc --argjson f "$FILES" '{action:"patch_preview",files:$f}')")")
assert_eq "MCP preview is_change_set" "true" "$(pj "$R" '.change_set.is_change_set')"
PID=$(pj "$(mcp_text "$(call "$(jq -nc --argjson f "$FILES" '{action:"patch_create",files:$f,explanation:"mcp cs"}')")")" '.change_set_id')
assert_nonempty "MCP create change_set_id" "$PID"
R=$(mcp_text "$(call "$(jq -nc --arg id "$PID" '{action:"patch_apply",patch_id:$id}')")")
assert_eq "MCP apply change_set_status=applied" "applied" "$(pj "$R" '.change_set_status')"
assert_nonempty "MCP combined rollback_id" "$(pj "$R" '.rollback_id')"
assert_eq "MCP A changed" "1" "$(grep -c 'mcp=1' "$SB/a.php")"
assert_eq "MCP C changed" "1" "$(grep -c '"mcp"' "$SB/c.js")"
R=$(mcp_text "$(jq -nc --arg id "$PID" '{jsonrpc:"2.0",id:1,method:"tools/call",params:{name:"rollback_manage",arguments:{action:"rollback_apply",patch_id:$id}}}')")
assert_eq "MCP combined rollback files_restored=2" "2" "$(pj "$R" '.files_restored')"
assert_eq "MCP A restored" "1" "$(grep -c 'a = "a1"' "$SB/a.php")"
assert_eq "MCP C restored" "1" "$(grep -c '"c1"' "$SB/c.js")"

echo
echo "================================================"
echo "  Patch Change Sets: $PASS passed, $FAIL failed"
echo "================================================"
[ "$FAIL" -eq 0 ]
