#!/usr/bin/env bash
#
# STEP 110 (Proposal Store) — Task 6: Governed Drafts (Dev) admin surface.
#
# Asserts the dev-gated submenu + the thin-REST-client view:
#   - submenu is OFF by default (real users never see it), ON via the dev switch
#   - view renders with developer framing (banner, empty-state, warning copy)
#   - view is a THIN REST CLIENT: no direct ProposalStore/ApplyService/Executor
#   - no approve / reject / rollback controls (no second Approval Center)
#   - cross-links to Approval Center + Change History
#   - invariants unchanged
#
# Requires: wp-cli.

set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_ROOT="$(cd "$PLUGIN_DIR/../../.." && pwd)"
cd "$PLUGIN_DIR"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq()      { local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }
assert_has()     { local d="$1" h="$2" n="$3"; printf '%s' "$h" | grep -qF -- "$n" && pass "$d" || fail "$d (missing '$n')"; }
assert_absent()  { local d="$1" h="$2" n="$3"; printf '%s' "$h" | grep -qF -- "$n" && fail "$d (found '$n')" || pass "$d"; }
wpe() { wp --path="$WP_ROOT" eval "$1" 2>/dev/null; }

echo "STEP 110 Task 6 — Governed Drafts (Dev) admin surface"

VIEW="includes/Admin/views/proposals.php"
VIEW_SRC="$(cat "$VIEW")"
# PHP code only: strip the inline <script> JS AND comment lines so JS identifiers
# and the docblock (which names the very classes the view must NOT call) aren't
# mistaken for real PHP calls.
VIEW_PHP="$(awk '/<script>/{s=1} /<\/script>/{s=0;next} !s' "$VIEW" | grep -vE '^[[:space:]]*(\*|/\*|//|#)')"

# ── Tab gating: OFF by default, ON via the dev switch ────────────────────────
# Experience Layer: Governed Drafts (Dev) is the Operate › drafts tab in the 5-C
# App Shell, present only when the dev switch is on (and proposal_store allowed).
DEFAULT_REG="$(wpe '
remove_all_filters("wpcc_proposals_dev_ui");
$s=\WPCommandCenter\Admin\AppShell::sections();
echo isset($s["wpcc-operate"]["tabs"]["drafts"])?"yes":"no";
')"
assert_eq "tab OFF by default (real users)" "no" "$DEFAULT_REG"

FILTER_REG="$(wpe '
add_filter("wpcc_proposals_dev_ui","__return_true");
$s=\WPCommandCenter\Admin\AppShell::sections();
remove_all_filters("wpcc_proposals_dev_ui");
echo isset($s["wpcc-operate"]["tabs"]["drafts"])?"yes":"no";
')"
assert_eq "tab ON when dev switch enabled" "yes" "$FILTER_REG"

# ── View renders with developer framing ──────────────────────────────────────
RENDERED="$(wpe '
$nonce_ph=1; ob_start();
$path=WPCC_PLUGIN_DIR."includes/Admin/views/proposals.php";
require $path;
echo ob_get_clean();
')"
assert_has "view renders dev banner"        "$RENDERED" "Developer validation surface"
assert_has "view renders create-test note"  "$RENDERED" "creates a real proposal"
assert_has "view renders the drafts table"  "$RENDERED" "Governed drafts"
assert_has "view exposes change_status col" "$RENDERED" "Change"

# ── Thin REST client: no direct primitive/engine calls in the view PHP ───────
for forbidden in "new ProposalStore" "new ProposalApplyService" "new ProposalSync" "OperationExecutor" "->mark_applied" "->apply(" "ProposalAdminQuery"; do
  assert_absent "view PHP has no direct call: $forbidden" "$VIEW_PHP" "$forbidden"
done
assert_has "view uses the REST base" "$VIEW_SRC" "wp-command-center/v1/admin"
assert_has "view targets /proposals endpoint" "$VIEW_SRC" "/proposals"
assert_has "view sends nonce" "$VIEW_SRC" "X-WP-Nonce"

# ── No second Approval Center: no approve/reject/rollback controls ────────────
assert_absent "no approve action control" "$VIEW_SRC" "wpcc-p-approve"
assert_absent "no reject action control"  "$VIEW_SRC" "wpcc-p-reject"
assert_absent "no rollback action control" "$VIEW_SRC" "wpcc-p-rollback"
# Cross-links instead of duplicated controls.
assert_has "cross-links to Approval Center" "$VIEW_SRC" "wpcc-approval-center"
assert_has "cross-links to Change History"  "$VIEW_SRC" "wpcc-change-history"

# ── Invariants (only DB_VERSION may have moved earlier; nothing here) ─────────
assert_eq "invariant: OPERATION_MAP == 34" "34" "$(wpe 'echo count(\WPCommandCenter\Operations\CapabilityRegistry::OPERATION_MAP);')"
assert_eq "invariant: capabilities == 23"  "23" "$(wpe 'echo count(\WPCommandCenter\Operations\CapabilityRegistry::ALL_CAPABILITIES);')"
assert_eq "invariant: catalogue == 40"     "40" "$(wpe 'echo count((new \WPCommandCenter\Operations\OperationRegistry())->get_operations());')"
assert_eq "invariant: DB_VERSION 2.5.0"    "2.5.0" "$(wpe 'echo \WPCommandCenter\Core\Schema::DB_VERSION;')"

echo ""
echo "RESULT: ${PASS} passed, ${FAIL} failed"
[ "$FAIL" -eq 0 ]
