#!/usr/bin/env bash
# Workstreams 3 & 7 — destructive connection actions use the product's own dialog, and
# flipping a Built-in AI tool stops moving the page under the customer.
#
# WS3: deleting a connection went through window.confirm(). A native dialog renders as
# "localhost says:", ignores the design system, and can carry exactly one line — so the
# question asked before destroying a stored provider key was "Delete this connection and
# its key?", naming neither the connection nor what would stop working. Every other
# destructive decision in the product (token revoke, undo, bulk approve/reject) already
# uses WPCC.cds.confirm, which can say all of it.
#
# WS7: the tool switches post the form and re-render the page. On the way back the card
# grew a confirmation banner it did not previously have, pushing the tool list and
# everything under it down by the banner's height — so the button just pressed was no
# longer under the cursor and its neighbour had slid into roughly that spot.
#
# Covered here:
#   1. No native confirm() survives in the connection flow.
#   2. The dialog names the connection, the key, the affected tools, and what is kept.
#   3. A double-click cannot issue two deletes, and cancelling changes nothing.
#   4. The action still posts when disabled (hidden field, not the button's own value).
#   5. Without JS the form still posts to the same nonce- and capability-checked handler.
#   6. The dialog is keyboard-operable: Escape cancels, Tab is trapped, focus restored.
#   7. The notice slot is reserved, so a toggle does not shift the list.
#   8. The toggle returns focus and scroll to the switch that was used.
#   9. Toggling still actually works, and remains nonce- and capability-gated.
set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../wpcc-env.sh"
WP_PATH="$SCRIPT_DIR/../../../.."
SETUP="$SCRIPT_DIR/../includes/Admin/views/ai-setup.php"
TOOLS="$SCRIPT_DIR/../includes/Admin/views/partials/builtin-ai-tools.php"
CDS="$SCRIPT_DIR/../assets/js/wpcc-cds.js"
CTRL="$SCRIPT_DIR/../includes/Admin/ConnectionController.php"
PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; if [ "$e" = "$a" ]; then pass "$d"; else fail "$d (expected '$e', got '$a')"; fi; }
has() { if grep -q "$1" "$2"; then pass "$3"; else fail "$3"; fi; }
hasnt() { if grep -q "$1" "$2"; then fail "$3"; else pass "$3"; fi; }

echo "Connection delete dialog & toggle reflow (Workstreams 3/7) — $(date)"
echo ""

echo "== 1. The native dialog is gone from this flow =="
hasnt "onclick=\"return confirm" "$SETUP" "no inline confirm() remains on the connection screen"
# Match a CALL, not a mention: the comment above the replacement names the old API to
# explain why it went, and that sentence is worth keeping.
if grep -vE '^\s*(\*|//|/\*)' "$SETUP" | grep -q "window\.confirm("; then
	fail "a window.confirm() call remains on the connection screen"
else
	pass "no window.confirm() call remains on the connection screen"
fi
has   "WPCC.cds.confirm"         "$SETUP" "destructive actions use the product's own dialog"
has   "wpcc-conn-confirm"        "$SETUP" "…wired through a single confirm hook"

echo ""
echo "== 2. The dialog says what is about to happen =="
has "data-conn-name"             "$SETUP" "the connection name is passed to the dialog"
has "stored API key deleted"     "$SETUP" "it says the stored key is deleted"
has "cannot be undone"           "$SETUP" "it says the deletion cannot be undone"
has "data-routed"                "$SETUP" "the tools routed through the connection are passed in"
has "will stop until you point them at another connection" "$SETUP" "…and named as the consequence"
has "audit history are not affected" "$SETUP" "it says suggestions and audit history are kept"
has "danger: true"               "$SETUP" "the confirm button is styled as destructive"
has "'cancel'"                   "$SETUP" "a cancel action is offered"
# The "history is kept" claim must be TRUE: deleting a connection touches connection
# options and credentials only — never proposals, changes, or the audit log.
if grep -qE "wpcc_proposals|wpcc_change_log|AuditLog" "$SCRIPT_DIR/../includes/Ai/Platform/ConnectionStore.php"; then
	fail "delete() touches proposal/change/audit storage — the dialog's promise would be false"
else
	pass "delete() never touches proposal, change or audit storage (the promise is true)"
fi

echo ""
echo "== 3-4. One click, one delete =="
has "wpccPending"                "$SETUP" "a second click while the dialog is open is ignored"
has "wpccDone"                   "$SETUP" "a second click after confirming is ignored"
has "hidden.name = btn.name"     "$SETUP" "the action posts via a hidden field…"
has "btn.disabled = true"        "$SETUP" "…so the button can be disabled immediately"
# The order matters: a disabled submit button contributes no name/value at all.
if grep -A3 "hidden.name = btn.name" "$SETUP" | grep -q "form.appendChild"; then
	pass "the hidden field is appended before the form is submitted"
else
	fail "the hidden field may not reach the request"
fi
has "if ( ! ok ) { return; }"    "$SETUP" "cancelling performs no action at all"

echo ""
echo "== 5. Without JavaScript the flow still works =="
has "method=\"post\""            "$SETUP" "the delete control is still a real form post"
has "wp_nonce_field"             "$SETUP" "…carrying a nonce"
has "check_admin_referer\|wp_verify_nonce" "$CTRL" "the handler verifies the nonce"
has "current_user_can"           "$CTRL" "the handler verifies capability"

echo ""
echo "== 6. The dialog is keyboard-operable =="
has "'Escape' === e.key"         "$CDS" "Escape cancels"
has "'Tab' !== e.key"            "$CDS" "Tab is handled"
has "e.preventDefault(); last.focus()"  "$CDS" "shift-Tab wraps to the end"
has "e.preventDefault(); first.focus()" "$CDS" "Tab wraps to the start"
has "prev.focus()"               "$CDS" "focus returns to whatever opened the dialog"
has "aria-modal"                 "$CDS" "the dialog is marked modal for assistive tech"

echo ""
echo "== 7-8. Toggling a tool no longer moves the page =="
has "wpcc-bai-noticeslot"        "$TOOLS" "the notice has a reserved slot"
has "min-height"                 "$TOOLS" "…with space reserved whether or not it is filled"
# The slot must be rendered unconditionally — that is the entire point.
if grep -B2 "wpcc-bai-noticeslot\" role" "$TOOLS" | grep -q "if ( \$wpcc_bai_has_notice )"; then
	fail "the notice slot itself is still conditional"
else
	pass "the slot is always rendered; only its contents are conditional"
fi
has "wpcc-bai-tool-"             "$TOOLS" "each tool row has a stable anchor"
has "action=\"#wpcc-bai-tool-"   "$TOOLS" "the form posts back to that anchor (scroll preserved)"
has "autofocus"                  "$TOOLS" "focus returns to the switch that was used"
has "wpcc_bai_changed"           "$TOOLS" "…only for the tool that actually changed"

echo ""
echo "== 9. The toggle still works, and is still governed =="
TOG="$(wp --path="$WP_PATH" eval '
use WPCommandCenter\Admin\BuiltinAiSettings;
$snap = get_option( BuiltinAiSettings::OPTION, [] );
$was  = BuiltinAiSettings::option_on( "content" );
// Start from a KNOWN state. set() reports whether it changed anything, so asserting
// "turning it on returns true" only means something if it was off to begin with — and
// on a site where the operator (or a T2 baseline) left the tools on, it would not be.
BuiltinAiSettings::set( "content", false );
echo "changed_on=" . ( BuiltinAiSettings::set( "content", true ) ? "yes" : "no" ) . "\n";
echo "is_on=" . ( BuiltinAiSettings::is_on( "content" ) ? "yes" : "no" ) . "\n";
echo "noop_repeat=" . ( BuiltinAiSettings::set( "content", true ) ? "changed" : "noop" ) . "\n";
echo "changed_off=" . ( BuiltinAiSettings::set( "content", false ) ? "yes" : "no" ) . "\n";
echo "is_off=" . ( BuiltinAiSettings::is_on( "content" ) ? "yes" : "no" ) . "\n";
update_option( BuiltinAiSettings::OPTION, $snap );
echo "restored=" . ( BuiltinAiSettings::option_on( "content" ) === $was ? "yes" : "no" ) . "\n";
' 2>/dev/null)"
t() { echo "$TOG" | grep -F "$1=" | head -1 | cut -d= -f2-; }
assert_eq "turning a tool on takes effect"    "yes"     "$(t changed_on)"
assert_eq "…and reads as on"                  "yes"     "$(t is_on)"
assert_eq "setting the same state is a no-op" "noop"    "$(t noop_repeat)"
assert_eq "turning it off takes effect"       "yes"     "$(t changed_off)"
assert_eq "…and reads as off"                 "no"      "$(t is_off)"
assert_eq "the suite restored the original state" "yes" "$(t restored)"
has "check_admin_referer( self::NONCE )" "$SCRIPT_DIR/../includes/Admin/BuiltinAiSettings.php" "the toggle POST is nonce-checked"
has "current_user_can( 'manage_options' )" "$SCRIPT_DIR/../includes/Admin/BuiltinAiSettings.php" "…and capability-checked"
has "AuditLog"                             "$SCRIPT_DIR/../includes/Admin/BuiltinAiSettings.php" "…and audited"

echo ""
echo "RESULT: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
