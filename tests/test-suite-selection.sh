#!/usr/bin/env bash
#
# Acceptance test for the tiered-regression suite SELECTION logic (tests/run.sh).
# Proves that changed files / runtimes map to the correct suites per tier, that
# quarantined suites never enter T0/T1, and that T2 is the full suite. Pure
# selection (uses --list --quiet); runs no runtime suites, so it is fast.
#
# Usage: bash tests/test-suite-selection.sh

set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
sel() { bash "$SCRIPT_DIR/run.sh" --list --quiet "$@"; }
has() { local d="$1" out="$2" s="$3"; echo "$out" | grep -qxF "$s" && pass "$d: has $s" || fail "$d: MISSING $s"; }
lacks() { local d="$1" out="$2" s="$3"; echo "$out" | grep -qxF "$s" && fail "$d: should NOT have $s" || pass "$d: excludes $s"; }
count_eq() { local d="$1" out="$2" n="$3"; local c; c="$(echo "$out" | grep -c .)"; [ "$c" = "$n" ] && pass "$d ($c)" || fail "$d (expected $n, got $c)"; }

echo "== 1. ACF change → T1 selects ACF suites + core-light, nothing unrelated =="
O="$(sel --tier T1 --files includes/Operations/ACFRuntimeManager.php)"
has  "acf T1" "$O" test-acf-runtime.sh
has  "acf T1" "$O" test-acf-group-delete-f31.sh
has  "acf T1 core-light" "$O" test-operations-registry.sh
has  "acf T1 core-light" "$O" test-capability-runtime.sh
has  "acf T1 core-light" "$O" test-mcp-error-surface.sh
lacks "acf T1" "$O" test-woocommerce-runtime.sh
lacks "acf T1" "$O" test-media-runtime.sh
lacks "acf T1" "$O" test-elementor-step96.sh

echo "== 2. Media change isolates to media (not ACF) =="
O="$(sel --tier T1 --files includes/Operations/MediaSnapshot.php)"
has  "media T1" "$O" test-media-snapshot-step100-1.sh
has  "media T1" "$O" test-media-replace-step100-2.sh
lacks "media T1" "$O" test-acf-runtime.sh

echo "== 3. Workflow change isolates to workflow =="
O="$(sel --tier T1 --files includes/Operations/WorkflowRuntimeManager.php)"
has  "workflow T1" "$O" test-workflow-rollback-f61.sh
has  "workflow T1" "$O" test-workflow-dataflow-f62.sh
lacks "workflow T1" "$O" test-acf-runtime.sh

echo "== 4. Operation-id selection (RestApi route diff mentioning acf_manage) → ACF + core =="
O="$(sel --tier T1 --files includes/AiAgent/RestApi.php --content 'operations/acf_manage/run')"
has  "op-id" "$O" test-acf-runtime.sh
has  "op-id core" "$O" test-operations-registry.sh

echo "== 5. Core dispatcher change → core suites =="
O="$(sel --tier T1 --files includes/Operations/OperationExecutor.php)"
has  "core" "$O" test-operations-registry.sh
has  "core" "$O" test-operation-requests.sh

echo "== 6. T0 runs only the runtime's PRIMARY suite (network-free) =="
O="$(sel --tier T0 --files includes/Operations/ACFRuntimeManager.php)"
has   "acf T0 primary" "$O" test-acf-group-delete-f31.sh
count_eq "acf T0 is a single primary" "$O" 1
# A media change's T0 primary must not be a network-download suite.
O="$(sel --tier T0 --files includes/Operations/MediaRuntimeManager.php)"
lacks "media T0 excludes network suite" "$O" test-media-runtime.sh
lacks "media T0 excludes network suite" "$O" test-media-import.sh

echo "== 7. T2 is the FULL suite (all 85) =="
O="$(sel --tier T2)"
count_eq "T2 = all suites" "$O" "$(ls "$SCRIPT_DIR"/test-*.sh | wc -l | tr -d ' ')"

echo "== 8. Quarantined suites never enter T1, but DO appear in T2 =="
for rt in acf media workflow security core; do
  O="$(sel --tier T1 --runtime "$rt")"
  lacks "T1/$rt" "$O" test-documentation-consistency.sh
  lacks "T1/$rt" "$O" test-security-redaction.sh
  lacks "T1/$rt" "$O" test-final-validation.sh
done
O="$(sel --tier T2)"
has  "T2" "$O" test-documentation-consistency.sh
has  "T2" "$O" test-final-validation.sh

echo "== 9. --runtime flag forces a group without a file change =="
O="$(sel --tier T1 --runtime woocommerce)"
has  "forced woo" "$O" test-woocommerce-product-step93.sh
has  "forced woo" "$O" test-woocommerce-order-step94.sh
lacks "forced woo" "$O" test-acf-runtime.sh

echo
echo "================================================"
echo "  Suite selection: $PASS passed, $FAIL failed"
echo "================================================"
[ "$FAIL" -eq 0 ]
