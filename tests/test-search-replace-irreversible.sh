#!/usr/bin/env bash
#
# Safe Search & Replace is NOT reversible, and every surface must say so.
#
# The engine has always been honest: the run response carries
# `rollback_available: false` and warns that rows "cannot be automatically
# reverted", the change log stores `reversible=0` / `rollback_kind=none`, and the
# Changes screen renders no Undo for those rows. Two customer-facing surfaces
# contradicted all of that:
#
#   1. the operation catalogue description said "with rollback support" — and that
#      string is what an ASSISTANT reads before proposing the operation;
#   2. the trust strip on the Search & Replace screen itself said
#      "Every change is ... Reversible" — on the page where the customer decides
#      whether to take a database backup first.
#
# This suite pins the engine's contract AND the wording, because the two drifting
# apart is exactly the failure being fixed.
#
# Nothing here runs a live replace: the destructive path is covered by
# test-destructive-guardrails.sh, and a suite that rewrites rows to prove a point
# about rollback is a suite that cannot undo itself.
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

echo "search & replace irreversibility contract"
echo
echo "== 1. The engine records no rollback point =="
# SearchReplace::run() returns no rollback_id and no change_set_id, which is what
# makes ChangeRecorder store rollback_kind='none'. If a future change adds one,
# this assertion should be REMOVED and the wording restored — not worked around.
if grep -qE "'rollback_id'|'change_set_id'" includes/Operations/SearchReplace.php; then
	fail "SearchReplace still returns no rollback handle"
else
	pass "SearchReplace still returns no rollback handle"
fi

echo
echo "== 2. The catalogue does not advertise rollback =="
DESC="$( wp --path="$WP_ROOT" eval '
$op = ( new \WPCommandCenter\Operations\OperationRegistry() )->get_operation( "safe_search_replace" );
echo (string) ( $op["description"] ?? "" );' 2>/dev/null )"

if [ -z "$DESC" ]; then
	fail "safe_search_replace is present in the catalogue"
else
	pass "safe_search_replace is present in the catalogue"
	echo "$DESC" | grep -qi "with rollback support" \
		&& fail "catalogue description does not claim rollback support" \
		|| pass "catalogue description does not claim rollback support"
	echo "$DESC" | grep -qi "NOT REVERSIBLE" \
		&& pass "catalogue description states it is not reversible" \
		|| fail "catalogue description states it is not reversible"
	echo "$DESC" | grep -qi "backup" \
		&& pass "catalogue description tells the caller to back up first" \
		|| fail "catalogue description tells the caller to back up first"
fi

echo
echo "== 3. The risk model is unchanged (governance must not move) =="
RISK="$( wp --path="$WP_ROOT" eval '
$op = ( new \WPCommandCenter\Operations\OperationRegistry() )->get_operation( "safe_search_replace" );
echo ( $op["risk_level"] ?? "?" ) . "|" . ( ! empty( $op["requires_approval"] ) ? "1" : "0" );' 2>/dev/null )"
[ "$RISK" = "critical|1" ] \
	&& pass "still critical + requires_approval" \
	|| fail "still critical + requires_approval (got '$RISK')"

# The destructive confirmation phrase is part of the safety model, not wording.
grep -q 'RUN_DESTRUCTIVE_DB' includes/Operations/DestructiveGuard.php \
	&& pass "destructive confirmation phrase intact" \
	|| fail "destructive confirmation phrase intact"

echo
echo "== 4. The screen declares itself irreversible to the trust strip =="
SR="includes/Admin/views/tools-search-replace.php"
grep -q 'wpcc_trust_reversible = false' "$SR" \
	&& pass "screen sets \$wpcc_trust_reversible = false" \
	|| fail "screen sets \$wpcc_trust_reversible = false"
grep -qi "cannot be undone" "$SR" \
	&& pass "screen intro says it cannot be undone" \
	|| fail "screen intro says it cannot be undone"
# The old blanket claim must be gone from this screen's own copy.
grep -qi "reversible where supported" "$SR" \
	&& fail "screen no longer implies reversibility" \
	|| pass "screen no longer implies reversibility"

echo
echo "== 5. Reversible screens are NOT affected by the flag =="
# The flag defaults to true and is reset inside the partial, so SEO / Alt Text /
# Content / Recommendations must keep their Reversible chip.
for v in seo-meta.php ai-alt-text.php ai-content.php recommendations.php; do
	if grep -q 'wpcc_trust_reversible' "includes/Admin/views/$v"; then
		fail "$v leaves reversibility at the default"
	else
		pass "$v leaves reversibility at the default"
	fi
done
grep -q 'isset( $wpcc_trust_reversible )' includes/Admin/views/partials/trust-strip.php \
	&& pass "trust strip defaults to Reversible when unset" \
	|| fail "trust strip defaults to Reversible when unset"

echo
echo "RESULT: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
