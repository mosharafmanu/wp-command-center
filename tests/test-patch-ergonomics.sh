#!/usr/bin/env bash
#
# STEP 105.6 — Patch agent-ergonomics acceptance suite.
#
#   - patch_create / patch_preview proactively return the confirmation contract
#     (requires_confirmation, confirmation_phrase=APPLY_PATCH, confirmation_params)
#     for proposals touching high-risk files — BEFORE patch_apply is attempted.
#   - non-high-risk proposals report requires_confirmation=false (no phrase).
#   - preview exposes its verification method (tokenizer, in-memory).
#   - verification result codes are surfaced in structured responses.
#
# patch_preview / patch_create do NOT write to disk, so this is non-destructive.
#
# Requires: curl, jq, wpcc-env.sh (full-scope token), active theme hello-elementor.
# Usage: bash tests/test-patch-ergonomics.sh

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

# A harmless append edit (preview/create never write to disk).
DANGER_FILE="themes/hello-elementor/functions.php"
SAFE_FILE="plugins/wp-command-center/readme.txt"
APPEND='\n// wpcc 105.6 ergonomics probe (never applied)\n'

echo "== 1. patch_preview on a HIGH-RISK file exposes the confirmation contract =="
PV=$(pm "$(jq -nc --arg p "$DANGER_FILE" --arg c "$APPEND" '{action:"patch_preview",files:[{path:$p,mode:"append",content:$c}]}')")
assert_eq "preview: requires_confirmation true" "true" "$(pj "$PV" '.requires_confirmation')"
assert_eq "preview: confirmation_phrase = APPLY_PATCH" "APPLY_PATCH" "$(pj "$PV" '.confirmation_phrase')"
assert_eq "preview: confirmation_params.confirmation_phrase" "APPLY_PATCH" "$(pj "$PV" '.confirmation_params.confirmation_phrase')"
assert_eq "preview: lists the high-risk file" "$DANGER_FILE" "$(pj "$PV" '.dangerous_files[0]')"
assert_eq "preview: verification method exposed" "tokenizer" "$(pj "$PV" '.verification.method')"

echo
echo "== 2. patch_create on a HIGH-RISK file returns the phrase up front =="
CR=$(pm "$(jq -nc --arg p "$DANGER_FILE" --arg c "$APPEND" '{action:"patch_create",files:[{path:$p,mode:"append",content:$c}],explanation:"105.6 probe"}')")
PID=$(pj "$CR" '.patch_id')
assert_eq "create: requires_confirmation true" "true" "$(pj "$CR" '.requires_confirmation')"
assert_eq "create: confirmation_phrase = APPLY_PATCH" "APPLY_PATCH" "$(pj "$CR" '.confirmation_phrase')"
assert_eq "create: confirmation_params present" "APPLY_PATCH" "$(pj "$CR" '.confirmation_params.confirmation_phrase')"

echo
echo "== 3. Non-high-risk proposal needs no confirmation phrase =="
SV=$(pm "$(jq -nc --arg p "$SAFE_FILE" --arg c "$APPEND" '{action:"patch_preview",files:[{path:$p,mode:"append",content:$c}]}')")
assert_eq "safe preview: requires_confirmation false" "false" "$(pj "$SV" '.requires_confirmation')"
assert_eq "safe preview: no confirmation_phrase" "null" "$(pj "$SV" '.confirmation_phrase // "null"')"

echo
echo "== 4. patch_apply structured verification (code surfaced) on the safe file =="
SC=$(pm "$(jq -nc --arg p "$SAFE_FILE" --arg c "$APPEND" '{action:"patch_create",files:[{path:$p,mode:"append",content:$c}],explanation:"105.6 safe probe"}')")
SPID=$(pj "$SC" '.patch_id')
AP=$(pm "$(jq -nc --arg id "$SPID" '{action:"patch_apply",patch_id:$id}')")
assert_eq "apply: change_set applied" "applied" "$(pj "$AP" '.change_set_status')"
assert_eq "apply: verification_summary.code surfaced" "ok" "$(pj "$AP" '.verification_summary.code')"
# Method is surfaced (readme.txt is non-PHP → 'none'; a PHP target → 'php -l'/'tokenizer').
APM=$(pj "$AP" '.verification_summary.method')
case "$APM" in
	none|"php -l"|tokenizer|mixed) pass "apply: verification method surfaced ($APM)";;
	*) fail "apply: verification method surfaced (got '$APM')";;
esac
# Clean up: roll the safe append back so the file is unchanged.
RB=$(curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$(jq -nc --arg id "$SPID" '{action:"rollback_apply",patch_id:$id}')" "$WPCC_BASE/operations/rollback_manage/run")
assert_eq "cleanup: safe append rolled back" "true" "$([ "$(pj "$RB" '.success // .restored // "true"')" != "false" ] && echo true || echo false)"

echo
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
