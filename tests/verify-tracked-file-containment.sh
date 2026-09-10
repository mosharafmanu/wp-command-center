#!/usr/bin/env bash
# Standalone release-gate sentinel. This file is deliberately not named
# test-*.sh, so the full T2 runner cannot recursively invoke it.
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$DIR/.." && pwd)"
WP_ROOT="$(cd "$ROOT/../../.." && pwd)"
source "$DIR/lib/source-integrity.sh"
source "$DIR/lib/runner-state.sh"
export ROOT WP_ROOT

P=0; F=0
pass(){ P=$((P+1)); echo "  PASS: $1"; }
fail(){ F=$((F+1)); echo "  FAIL: $1"; }

BEFORE="$(mktemp "${TMPDIR:-/tmp}/wpcc-source-before.XXXXXX")"
AFTER="$(mktemp "${TMPDIR:-/tmp}/wpcc-source-after.XXXXXX")"
STATUS_BEFORE="$(mktemp "${TMPDIR:-/tmp}/wpcc-status-before.XXXXXX")"
STATUS_AFTER="$(mktemp "${TMPDIR:-/tmp}/wpcc-status-after.XXXXXX")"
STATE=""
cleanup(){
	[ -z "$STATE" ] || RUNNER_STATE_RESTORE "$STATE" >/dev/null 2>&1 || true
	rm -f "$BEFORE" "$AFTER" "$STATUS_BEFORE" "$STATUS_AFTER"
}
trap cleanup EXIT

WPCC_SOURCE_MANIFEST "$BEFORE" "$ROOT" || { fail "candidate manifest created"; echo "  $P passed, $F failed"; exit 1; }
git -C "$ROOT" status --short --untracked-files=all > "$STATUS_BEFORE"
EVIDENCE="$ROOT/artifacts/step-36-validation/validation-evidence.json"
EVIDENCE_BEFORE="$(shasum -a 256 -- "$EVIDENCE" | awk '{print $1}')"

STATE="$(RUNNER_STATE_SNAPSHOT)"
if [ $? -ne 0 ] || [ -z "$STATE" ]; then
	fail "WordPress state snapshot created"
else
	pass "WordPress state snapshot created"
fi

SUITES=( "$@" )
if [ "$#" -eq 0 ]; then SUITES=( "test-real-site-validation.sh" ); fi
for suite in "${SUITES[@]}"; do
	if [ ! -f "$DIR/$suite" ]; then
		fail "$suite exists"
		continue
	fi
	if bash "$DIR/$suite"; then
		pass "$suite completed without assertion or exit failure"
	else
		fail "$suite completed without assertion or exit failure"
	fi
	if ! RUNNER_STATE_RESTORE "$STATE"; then
		fail "$suite WordPress state restored"
	else
		pass "$suite WordPress state restored"
	fi
done
STATE=""

WPCC_SOURCE_MANIFEST "$AFTER" "$ROOT" || fail "post-test candidate manifest created"
git -C "$ROOT" status --short --untracked-files=all > "$STATUS_AFTER"
EVIDENCE_AFTER="$(shasum -a 256 -- "$EVIDENCE" | awk '{print $1}')"

if WPCC_SOURCE_ASSERT_IDENTICAL "$BEFORE" "$AFTER"; then
	pass "candidate files are byte-identical"
else
	fail "candidate files are byte-identical"
fi
if cmp -s "$STATUS_BEFORE" "$STATUS_AFTER"; then
	pass "Git working-state listing is identical"
else
	fail "Git working-state listing is identical"
	diff -u "$STATUS_BEFORE" "$STATUS_AFTER" || true
fi
[ "$EVIDENCE_BEFORE" = "$EVIDENCE_AFTER" ] && pass "validation evidence is byte-identical" || fail "validation evidence is byte-identical"

echo "== Summary =="
echo "  $P passed, $F failed"
[ "$F" -eq 0 ]
