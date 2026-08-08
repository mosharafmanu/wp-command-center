#!/usr/bin/env bash
#
# No screen may promise approval on a site that does not require it.
#
# SecurityModeManager owns the protection promise ("the product's promise, in ONE
# place"). The helpers were introduced once and then applied to SOME surfaces:
# Home's subtitle, Home's four-step explainer, the Approvals subtitle, the
# Approvals empty state and the trust strip's review chip all kept a hardcoded
# sentence. A Development site therefore rendered "Approvals are turned off" and
# "Nothing runs until you approve it" in the same header.
#
# This suite is the sweep that stops it recurring. It asserts BOTH:
#   1. The helpers tell the truth in all three modes — including that Standard is
#      not Strict. Standard lets low-risk writes (media_regenerate_metadata,
#      cache_purge_all/url, media_snapshot_create, patch_create) through without
#      approval, so it must not claim that "anything that changes the site waits".
#   2. No shipped view re-states an approval guarantee as a literal instead of
#      asking for it. This is the half that actually prevents regression: a new
#      screen with a hand-written promise fails here.
#
# Restores the site's protection mode on exit, including on failure.
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

ORIG_MODE="$( wp --path="$WP_ROOT" option get wpcc_security_mode 2>/dev/null )"
restore_mode() {
	[ -n "${ORIG_MODE:-}" ] && wp --path="$WP_ROOT" option update wpcc_security_mode "$ORIG_MODE" >/dev/null 2>&1
}
trap restore_mode EXIT

say() { # say <mode> <helper>
	wp --path="$WP_ROOT" option update wpcc_security_mode "$1" >/dev/null 2>&1
	wp --path="$WP_ROOT" eval "echo \WPCommandCenter\Operations\SecurityModeManager::$2();" 2>/dev/null
}

echo "protection-mode promise sweep"
echo
echo "== 1. Development mode never promises approval =="
# The failure mode being locked out is a promise that NEW changes are held. The
# word "approval" may legitimately appear ("...run immediately without approval"),
# and so may "waits" — but only about work queued BEFORE the mode was switched,
# which really is still held. Turning approvals off does not release the queue,
# and a screen that pretended otherwise would strand it.
for helper in promise approval_step approvals_desc approvals_empty_detail; do
	OUT="$( say developer "$helper" )"
	if echo "$OUT" | grep -qiE "nothing runs until|needs your approval|requires approval"; then
		fail "$helper() does not promise approval in Development ($OUT)"
	elif echo "$OUT" | grep -qiE "waits for (your|you)" && ! echo "$OUT" | grep -qiE "queued|already|before you switched"; then
		fail "$helper() says changes wait, without scoping it to the existing queue ($OUT)"
	else
		pass "$helper() does not promise approval in Development"
	fi
	# Whatever else it says, it must state the actual behaviour.
	if echo "$OUT" | grep -qiE "run immediately|runs immediately|without waiting|apply immediately"; then
		pass "$helper() states that changes run immediately"
	else
		fail "$helper() states that changes run immediately ($OUT)"
	fi
done

OUT="$( say developer review_chip )"
[ "$OUT" = "Not reviewed" ] && pass "review_chip() is honest in Development" || fail "review_chip() is honest in Development (got '$OUT')"
OUT="$( say developer approval_chip )"
[ "$OUT" = "Runs immediately" ] && pass "approval_chip() is honest in Development" || fail "approval_chip() is honest in Development (got '$OUT')"

echo
echo "== 2. Standard is not Strict =="
# Standard gates medium/high/critical only. Claiming everything waits is the same
# class of false promise as claiming it in Development, just quieter.
CLIENT_PROMISE="$( say client promise )"
ENT_PROMISE="$( say enterprise promise )"
[ "$CLIENT_PROMISE" != "$ENT_PROMISE" ] \
	&& pass "Standard and Strict make different promises" \
	|| fail "Standard and Strict make different promises (both: $CLIENT_PROMISE)"

echo "$CLIENT_PROMISE" | grep -qi "low-risk" \
	&& pass "Standard promise names the low-risk exception" \
	|| fail "Standard promise names the low-risk exception (got: $CLIENT_PROMISE)"

echo "$ENT_PROMISE" | grep -qi "every change" \
	&& pass "Strict promise says every change waits" \
	|| fail "Strict promise says every change waits (got: $ENT_PROMISE)"

for m in client enterprise; do
	OUT="$( say "$m" approval_chip )"
	[ "$OUT" = "Requires approval" ] && pass "approval_chip() promises approval in $m" || fail "approval_chip() in $m (got '$OUT')"
	OUT="$( say "$m" review_chip )"
	[ "$OUT" = "Reviewed by you" ] && pass "review_chip() promises review in $m" || fail "review_chip() in $m (got '$OUT')"
done

restore_mode

echo
echo "== 3. No shipped view hardcodes an approval guarantee =="
# Literal promises that must come from SecurityModeManager instead. Comments are
# excluded: several files explain the rule in prose, which is not a rendered claim.
STRAYS="$( grep -rn -F \
	-e "You approve anything that matters" \
	-e "Nothing runs until you approve it" \
	-e "Anything that changes the site waits for your yes" \
	-e "Any change your assistant makes waits for your approval first" \
	includes/ --include='*.php' \
	| grep -v 'includes/Operations/SecurityModeManager.php' \
	| grep -vE ':[0-9]+:[[:space:]]*(//|\*|/\*)' \
	| grep -v '? __(' )"

if [ -z "$STRAYS" ]; then
	pass "no hardcoded approval promise in any shipped view"
else
	fail "no hardcoded approval promise in any shipped view"
	echo "$STRAYS" | sed 's/^/        /'
fi

echo
echo "== 4. The trust strip asks for its wording rather than stating it =="
STRIP="includes/Admin/views/partials/trust-strip.php"
grep -q 'SecurityModeManager::review_chip()' "$STRIP" \
	&& pass "trust strip uses review_chip()" || fail "trust strip uses review_chip()"
grep -q 'SecurityModeManager::approval_chip()' "$STRIP" \
	&& pass "trust strip uses approval_chip()" || fail "trust strip uses approval_chip()"
# Reversibility is per screen; see test-search-replace-irreversible.sh.
grep -q 'wpcc_trust_reversible' "$STRIP" \
	&& pass "trust strip honours a per-screen reversibility flag" || fail "trust strip honours a per-screen reversibility flag"

echo
echo "== 5. Dead code cannot hide a stale promise =="
# The retired hero sat behind `if ( false )` carrying a hardcoded approval chip.
if grep -rn 'if ( false )' includes/ --include='*.php' | grep -v '^\s*//' | grep -vE ':[0-9]+:[[:space:]]*//' | grep -q .; then
	fail "no disabled-but-shipped markup blocks remain"
	grep -rn 'if ( false )' includes/ --include='*.php' | sed 's/^/        /'
else
	pass "no disabled-but-shipped markup blocks remain"
fi

echo
echo "RESULT: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
