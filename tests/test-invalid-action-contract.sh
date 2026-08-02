#!/usr/bin/env bash
#
# The pre-approval guard must reject an unknown action in the RUNTIME'S OWN CODE.
#
# Each runtime answers an unknown action with a self-describing code
# (wpcc_invalid_content_action, wpcc_invalid_woo_action, ...). That is a public
# contract integrations branch on. When the pre-approval guard began catching bogus
# actions before dispatch — so they can no longer be filed as an approval that could
# only ever fail — it briefly flattened every one of them to a generic
# `wpcc_invalid_action`. This suite exists so that cannot happen again.
#
# Two independent checks:
#   1. STATIC  — every code in InvalidActionContract is actually emitted by a runtime
#                source file, so the map cannot drift from the runtimes.
#   2. LIVE    — the API really returns the mapped code for a bogus action.
#
# Requires wp-cli + a reachable local site (wpcc-env.sh).

set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
source "$SCRIPT_DIR/../wpcc-env.sh"
cd "$PLUGIN_DIR"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }

MAP="$PLUGIN_DIR/includes/Operations/InvalidActionContract.php"

echo "invalid-action contract"

# --- 1. STATIC: every mapped code exists in a runtime source ---------------------
missing=0
while IFS='|' read -r op code; do
  [ -z "$code" ] && continue
  if grep -rqF "'$code'" "$PLUGIN_DIR/includes/Operations/" --include='*.php' \
     --exclude='InvalidActionContract.php'; then :; else
    echo "    orphan: $op => $code not emitted by any runtime"; missing=$((missing+1))
  fi
done < <(grep -oE "'[a-z_]+' *=> *'wpcc_invalid_[a-z_]+'" "$MAP" | tr -d "'" | sed 's/ *=> */|/')
assert_eq "every mapped code is emitted by a runtime" "0" "$missing"

# --- 2. LIVE: the API returns the runtime's code, not the generic one ------------
probe() { # operation -> code returned for a bogus action
  curl -s -X POST "$WPCC_BASE/operations/$1/run" \
    -H "Authorization: Bearer $WPCC_TOKEN" -H 'Content-Type: application/json' \
    -d '{"action":"definitely_not_a_real_action"}' \
  | sed -n 's/.*"code":"\([^"]*\)".*/\1/p'
}

assert_eq "content_manage keeps its code"     "wpcc_invalid_content_action"  "$(probe content_manage)"
assert_eq "cpt_manage keeps its code"         "wpcc_invalid_cpt_action"      "$(probe cpt_manage)"
assert_eq "widgets_manage keeps its code"     "wpcc_invalid_widgets_action"  "$(probe widgets_manage)"
assert_eq "workflow_manage keeps its code"    "wpcc_invalid_workflow_action" "$(probe workflow_manage)"
assert_eq "media_manage keeps its code"       "wpcc_invalid_media_action"    "$(probe media_manage)"
assert_eq "woocommerce_manage keeps its code" "wpcc_invalid_woo_action"      "$(probe woocommerce_manage)"
assert_eq "user_manage keeps its code"        "wpcc_invalid_user_action"     "$(probe user_manage)"

# The message must still name the valid actions — the DX half of the contract.
MSG=$(curl -s -X POST "$WPCC_BASE/operations/content_manage/run" \
  -H "Authorization: Bearer $WPCC_TOKEN" -H 'Content-Type: application/json' \
  -d '{"action":"nope"}')
case "$MSG" in *"Valid actions:"*) pass "message still lists the valid actions";;
  *) fail "message still lists the valid actions";; esac

# --- 3. A required parameter that is EMPTY is the runtime's business -------------
# `replace` is required by safe_search_replace, and replacing with the empty string
# is how you delete text. The guard must not treat "supplied but empty" as missing.
R=$(curl -s -X POST "$WPCC_BASE/operations/safe_search_replace/run" \
  -H "Authorization: Bearer $WPCC_TOKEN" -H 'Content-Type: application/json' \
  -d '{"search":"zzz_wpcc_no_match_zzz","replace":"","tables":["'"${WPCC_TABLE_PREFIX:-wp_}"'posts"],"dry_run":true}')
case "$R" in
  *wpcc_missing_parameters*) fail "empty replace is accepted (deleting text is legitimate)";;
  *) pass "empty replace is accepted (deleting text is legitimate)";;
esac

# An empty SEARCH is still refused — but by the runtime, in the runtime's own code.
R=$(curl -s -X POST "$WPCC_BASE/operations/safe_search_replace/run" \
  -H "Authorization: Bearer $WPCC_TOKEN" -H 'Content-Type: application/json' \
  -d '{"search":"","replace":"x","tables":["'"${WPCC_TABLE_PREFIX:-wp_}"'posts"],"dry_run":true}')
case "$R" in
  *wpcc_empty_search*) pass "empty search keeps the runtime's wpcc_empty_search";;
  *) fail "empty search keeps the runtime's wpcc_empty_search (got: $(echo "$R" | head -c 120))";;
esac

# A parameter-LESS call is still refused before it can spend an approval.
R=$(curl -s -X POST "$WPCC_BASE/operations/safe_search_replace/run" \
  -H "Authorization: Bearer $WPCC_TOKEN" -H 'Content-Type: application/json' -d '{}')
case "$R" in
  *wpcc_missing_parameters*) pass "parameter-less call still refused pre-approval";;
  *) fail "parameter-less call still refused pre-approval";;
esac

echo
echo "invalid-action contract: $PASS passed / $FAIL failed"
[ "$FAIL" -eq 0 ]
