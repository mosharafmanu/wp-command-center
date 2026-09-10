#!/usr/bin/env bash
#
# WPCC tiered regression runner.
#
#   tests/run.sh --tier T0|T1|T2 [--changed] [--runtime NAME ...] [-j N]
#                [--files "a b"] [--content "str"] [--list] [--quiet]
#
# Tiers:
#   T0  Fast (<30s):     lint changed PHP (php -l) + each selected runtime's PRIMARY
#                        acceptance suite (network-heavy + quarantined excluded).
#   T1  Runtime (1-2m):  all suites for the selected runtime(s) + core registry +
#                        capability + MCP parity; quarantine excluded; network retried.
#   T2  Full (pre-deploy): every suite; failures diffed against the baseline to report
#                        net-new (the whole suite always still runs here).
#
# Suite selection (T0/T1): from --runtime, or auto from the change signal
# (--changed = git diff names+content; or explicit --files/--content). Matched
# against tests/regression-map.tsv. tests/regression-quarantine.txt is excluded
# from T0/T1. tests/regression-baseline.tsv defines the known-failure baseline.
#
# --list prints the suites that WOULD run (no execution) — used by
# test-suite-selection.sh to prove selection is correct.

set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WP_ROOT="$(cd "$ROOT/../../.." && pwd)"   # wp-content/plugins/<plugin> -> WordPress root
cd "$ROOT"
MAP="tests/regression-map.tsv"
QUAR="tests/regression-quarantine.txt"
BASE="tests/regression-baseline.tsv"
T1_CORE="test-operations-registry.sh test-capability-runtime.sh test-mcp-error-surface.sh"

TIER="T1"; CHANGED=0; LIST=0; JOBS=1; FILES=""; CONTENT=""; RUNTIMES=""; QUIET=0
while [ $# -gt 0 ]; do
  case "$1" in
    --tier) TIER="$2"; shift 2;;
    --tier=*) TIER="${1#*=}"; shift;;
    --changed) CHANGED=1; shift;;
    --files) FILES="$2"; shift 2;;
    --content) CONTENT="$2"; shift 2;;
    --runtime) RUNTIMES="$RUNTIMES $2"; shift 2;;
    -j) JOBS="$2"; shift 2;;
    -j*) JOBS="${1#-j}"; shift;;
    --list) LIST=1; shift;;
    --quiet) QUIET=1; shift;;
    *) echo "run.sh: unknown arg '$1'" >&2; exit 2;;
  esac
done
TIER="$(echo "$TIER" | tr '[:lower:]' '[:upper:]')"

exists() { [ -f "tests/$1" ]; }
is_quarantined() { grep -qxF "$1" "$QUAR" 2>/dev/null; }
is_network() { grep -qEi 'download_url|placehold|picsum|unsplash' "tests/$1" 2>/dev/null; }
all_suites() { ls tests/test-*.sh 2>/dev/null | xargs -n1 basename | sort; }
dedup() { tr ' ' '\n' | grep -v '^$' | sort -u; }

# ── Build the change signal ──────────────────────────────────────
SIGNAL=""
if [ "$CHANGED" = 1 ]; then
  SIGNAL="$(git diff --name-only HEAD 2>/dev/null; git diff --name-only --cached 2>/dev/null; git ls-files --others --exclude-standard 2>/dev/null; git diff -U0 HEAD 2>/dev/null; git diff -U0 --cached 2>/dev/null)"
fi
[ -n "$FILES" ]   && SIGNAL="$SIGNAL"$'\n'"$(echo "$FILES" | tr ' ' '\n')"
[ -n "$CONTENT" ] && SIGNAL="$SIGNAL"$'\n'"$CONTENT"

# ── Select suites by runtime / signal ────────────────────────────
SELECTED=""; PRIMARIES=""; MATCHED_GROUPS=""
while IFS=$'\t' read -r group trigger primary suites; do
  case "$group" in ''|\#*) continue;; esac
  match=0
  if [ -n "$RUNTIMES" ] && echo " $RUNTIMES " | grep -qw "$group"; then match=1; fi
  # NB: here-string, NOT `printf ... | grep -q`. Under `set -o pipefail`, grep -q
  # exits early on a match near the top of the (large) signal and SIGPIPEs printf,
  # whose 141 exit then fails the whole pipeline → a false "no match" for any group
  # that matches early. A here-string has no pipe and avoids that.
  if [ "$match" = 0 ] && [ -n "$SIGNAL" ] && grep -qE "$trigger" <<<"$SIGNAL"; then match=1; fi
  if [ "$match" = 1 ]; then
    MATCHED_GROUPS="$MATCHED_GROUPS $group"
    SELECTED="$SELECTED ${suites//,/ }"
    PRIMARIES="$PRIMARIES $primary"
  fi
done < "$MAP"

# ── Compose the suite list for the requested tier ────────────────
compose_list() {
  case "$TIER" in
    T0)
      # Primary suites of matched groups, minus network + quarantine.
      for s in $(echo "$PRIMARIES" | dedup); do
        exists "$s" || continue; is_quarantined "$s" && continue; is_network "$s" && continue
        echo "$s"
      done
      ;;
    T1)
      for s in $(echo "$SELECTED $T1_CORE" | dedup); do
        exists "$s" || continue; is_quarantined "$s" && continue
        echo "$s"
      done
      ;;
    T2)
      all_suites
      ;;
    *) echo "run.sh: unknown tier '$TIER' (use T0|T1|T2)" >&2; exit 2;;
  esac
}
LIST_SUITES="$(compose_list)"

if [ "$LIST" = 1 ]; then
  [ "$QUIET" = 1 ] || { echo "# tier=$TIER groups:$([ -n "$MATCHED_GROUPS" ] && echo "$MATCHED_GROUPS" || echo ' (none)')"; }
  echo "$LIST_SUITES"
  exit 0
fi

# ── Lint (T0 / T1): php -l on changed PHP files ──────────────────
lint_changed() {
  local files lint_fail=0
  files="$(printf '%s\n' "$SIGNAL" | grep -E '\.php$' | sort -u)"
  [ -z "$files" ] && return 0
  echo "== Lint (php -l) =="
  while IFS= read -r f; do
    [ -z "$f" ] && continue; [ -f "$f" ] || continue
    if php -l "$f" >/dev/null 2>&1; then echo "  ok   $f"; else echo "  FAIL $f"; php -l "$f" 2>&1 | tail -2; lint_fail=1; fi
  done <<< "$files"
  return $lint_fail
}

# ── Governance + credential state isolation ─────────────────────
# Around twenty suites change the site's protection mode or capability enforcement to
# exercise gating, and most do it without a trap — so any early exit leaves the wrong
# mode behind and the NEXT suite fails for reasons that have nothing to do with it.
# Proven: test-capability-runtime drops 11 assertions when it inherits `enterprise`,
# because its writes are gated and the timeline entries it asserts never appear.
#
# Restoring here, after every suite, makes the full run deterministic regardless of any
# individual suite's hygiene. It is the runner's job: a suite cannot be trusted to clean
# up after a failure it did not expect.
# shellcheck source=lib/runner-state.sh
source "$ROOT/tests/lib/runner-state.sh"
source "$ROOT/tests/lib/source-integrity.sh"
source "$ROOT/tests/lib/gate-liveness.sh"
export WP_ROOT

# Every suite shares one WordPress database and one credential store. Parallel
# snapshot/suite/restore cycles race by construction: one suite can snapshot another
# suite's temporary state and restore it later. Keep the advertised flag compatible,
# but serialize execution whenever state isolation is active.
if [ "$JOBS" -gt 1 ]; then
  echo "run.sh: state-isolated suites are serialized; ignoring -j $JOBS" >&2
  JOBS=1
fi

parse_counts() {
  local out="$1" p f
  p="$(printf '%s\n' "$out" | grep -oE '[0-9]+ passed' | tail -1 | grep -oE '[0-9]+' || true)"
  f="$(printf '%s\n' "$out" | grep -oE '[0-9]+ failed' | tail -1 | grep -oE '[0-9]+' || true)"
  if [ -n "$p" ] && [ -n "$f" ]; then
    printf '%s\t%s\t1\n' "$p" "$f"
    return 0
  fi

  p="$(printf '%s\n' "$out" | grep -oE 'PASS[=:][[:space:]]*[0-9]+' | tail -1 | grep -oE '[0-9]+' || true)"
  f="$(printf '%s\n' "$out" | grep -oE 'FAIL[=:][[:space:]]*[0-9]+' | tail -1 | grep -oE '[0-9]+' || true)"
  if [ -n "$p" ] && [ -n "$f" ]; then
    printf '%s\t%s\t1\n' "$p" "$f"
    return 0
  fi

  p="$(printf '%s\n' "$out" | grep -cE '^[[:space:]]*PASS:' || true)"
  f="$(printf '%s\n' "$out" | grep -cE '^[[:space:]]*FAIL:' || true)"
  if [ "$p" -gt 0 ] || [ "$f" -gt 0 ]; then
    printf '%s\t%s\t1\n' "$p" "$f"
  else
    printf '0\t0\t0\n'
  fi
}

# ── Run a single suite (network suites retry once) ───────────────
run_one() {
  local suite="$1" out p f parsed state suite_rc restore_rc issue counts
  state="$(RUNNER_STATE_SNAPSHOT)"
  if [ $? -ne 0 ] || [ -z "$state" ]; then
    printf '%s\t0\t1\t1\t1\tstate_snapshot_failed\n' "$suite"
    return 0
  fi
  out="$(bash "tests/$suite" 2>&1)"
  suite_rc=$?
  counts="$(parse_counts "$out")"
  IFS=$'\t' read -r p f parsed <<< "$counts"
  if { [ "$suite_rc" -ne 0 ] || [ "$f" -gt 0 ] || [ "$parsed" -ne 1 ]; } && is_network "$suite"; then
    out="$(bash "tests/$suite" 2>&1)"
    suite_rc=$?
    counts="$(parse_counts "$out")"
    IFS=$'\t' read -r p f parsed <<< "$counts"
  fi
  restore_rc=0
  RUNNER_STATE_RESTORE "$state" || restore_rc=1
  issue=""
  if [ "$parsed" -ne 1 ]; then
    issue="summary_missing"
    f=$((f+1))
  elif [ "$suite_rc" -ne 0 ] && [ "$f" -eq 0 ]; then
    issue="suite_exit_${suite_rc}_without_failure_count"
    f=$((f+1))
  fi
  if [ "$restore_rc" -ne 0 ]; then
    issue="${issue:+${issue},}state_restore_failed"
  fi
  printf '%s\t%s\t%s\t%s\t%s\t%s\n' "$suite" "$p" "$f" "$suite_rc" "$restore_rc" "$issue"
}
export -f run_one is_network parse_counts
export ROOT

baseline_for() { awk -F'\t' -v s="$1" '$1==s{print $2}' "$BASE" 2>/dev/null | head -1; }

# ── Execute ──────────────────────────────────────────────────────
START_MONOTONIC_NS="$(WPCC_GATE_MONOTONIC_NS)"
LINT_RC=0
if [ "$TIER" != "T2" ]; then lint_changed || LINT_RC=1; fi

[ -z "$LIST_SUITES" ] && { echo "== $TIER: no suites selected (no matching runtime in the change signal) =="; [ "$LINT_RC" = 0 ] && exit 0 || exit 1; }

echo "== $TIER: $(echo "$LIST_SUITES" | grep -c .) suites =="

if [ "$TIER" = "T2" ]; then
  WPCC_GATE_CLOCK_INIT || { echo "run.sh: unable to initialize monotonic gate clock" >&2; exit 1; }
  WPCC_GATE_AUTH_PREFLIGHT || exit $?
fi

# ── Establish the governance baseline (T2) ───────────────────────
#
# GOV_RESTORE below makes each suite start from the same state as the one before
# it — but that state is whatever the site happened to be in when the run began,
# which is not the same thing as a known state. Most suites that WRITE do not set
# a protection mode themselves; they assume changes apply immediately. Start a run
# with the site on Standard protection and every one of those writes is answered
# with pending_approval instead, and the suite fails for a reason that has nothing
# to do with the code.
#
# Measured: a T2 run begun on Standard reported 107 failures — 76 of them the ACF
# suites, which are the first to run and had nothing before them to blame. Every
# one of those suites passes on Development. So the headline number depended on an
# unrecorded precondition, which makes it unciteable.
#
# T2 therefore SETS the baseline rather than inheriting it, and puts the operator's
# own mode back at the end. T0/T1 are left alone: they are quick, targeted runs
# where surprising the operator's site is worse than a mode-sensitive result.
RUN_STATE=""
if [ "$TIER" = "T2" ]; then
  RUN_STATE="$(RUNNER_STATE_SNAPSHOT)"
  if [ $? -ne 0 ] || [ -z "$RUN_STATE" ]; then
    echo "run.sh: unable to snapshot initial state" >&2
    exit 1
  fi
  if ! wp --path="$WP_ROOT" eval '
    update_option( "wpcc_security_mode", \WPCommandCenter\Operations\SecurityModeManager::MODE_DEVELOPER );
    update_option( "wpcc_enforce_capabilities", true );
    // All three Built-in AI tools on, so the suites that assert the ✨ WPCC AI row
    // action exists start from a known answer rather than whatever the operator left.
    update_option( "wpcc_builtin_ai_tools", [ "seo" => true, "alt_text" => true, "content" => true ] );' >/dev/null 2>&1; then
    echo "run.sh: unable to establish the T2 governance baseline" >&2
    RUNNER_STATE_RESTORE "$RUN_STATE" >/dev/null 2>&1 || true
    exit 1
  fi
  echo "   governance baseline: developer + capabilities + built-in AI tools on (restored at end)"
fi
restore_run_state() {
  local rc=$?
  trap - EXIT
  if [ -n "$RUN_STATE" ] && ! RUNNER_STATE_RESTORE "$RUN_STATE"; then
    echo "run.sh: fatal: final state restoration failed" >&2
    rc=1
  fi
  rm -f "${SOURCE_MANIFEST_BEFORE:-}" "${SOURCE_MANIFEST_AFTER:-}"
  exit "$rc"
}
trap restore_run_state EXIT

# Freeze the complete candidate tree after the runner has finished selecting
# suites but before any suite runs. A release gate is invalid if tests alter a
# tracked file or leave a new non-ignored path behind, even when all assertions
# themselves pass.
SOURCE_MANIFEST_BEFORE="$(mktemp "${TMPDIR:-/tmp}/wpcc-run-source-before.XXXXXX")"
SOURCE_MANIFEST_AFTER="$(mktemp "${TMPDIR:-/tmp}/wpcc-run-source-after.XXXXXX")"
if ! WPCC_SOURCE_MANIFEST "$SOURCE_MANIFEST_BEFORE" "$ROOT"; then
  echo "run.sh: unable to fingerprint candidate source" >&2
  exit 1
fi

RESULTS="$(mktemp)"
: > "$RESULTS"
GATE_ABORT=0; GATE_ABORT_REASON=""; EXECUTED_SUITES=0
while IFS= read -r s; do
  [ -z "$s" ] && continue
  if [ "$TIER" = "T2" ]; then
    WPCC_GATE_CHECKPOINT
    checkpoint_rc=$?
    if [ "$checkpoint_rc" -ne 0 ]; then GATE_ABORT=1; GATE_ABORT_REASON="checkpoint_before_$s:$checkpoint_rc"; break; fi
  fi
  run_one "$s" >> "$RESULTS"
  EXECUTED_SUITES=$((EXECUTED_SUITES+1))
  if [ "$TIER" = "T2" ]; then
    WPCC_GATE_CHECKPOINT
    checkpoint_rc=$?
    if [ "$checkpoint_rc" -ne 0 ]; then GATE_ABORT=1; GATE_ABORT_REASON="checkpoint_after_$s:$checkpoint_rc"; break; fi
  fi
done <<< "$LIST_SUITES"

TP=0; TF=0; NETNEW=0; HARNESS_FAIL="$GATE_ABORT"; FAILLINES=""
while IFS=$'\t' read -r suite p f suite_rc restore_rc issue; do
  [ -z "$suite" ] && continue
  TP=$((TP+p)); TF=$((TF+f))
  if [ "$restore_rc" -ne 0 ] || [ -n "$issue" ]; then
    HARNESS_FAIL=1
  fi
  if [ "$f" -gt 0 ]; then
    bl="$(baseline_for "$suite")"; bl="${bl:-0}"
    nn=$((f-bl)); [ "$nn" -lt 0 ] && nn=0
    NETNEW=$((NETNEW+nn))
    FAILLINES="$FAILLINES  $suite: $f (baseline $bl, net-new $nn)"$'\n'
  fi
  [ -n "$issue" ] && FAILLINES="$FAILLINES    runner: $issue"$'\n'
done < <(sort "$RESULTS")
rm -f "$RESULTS"

RUN_RESTORE_RC=0
if [ -n "$RUN_STATE" ]; then
  if RUNNER_STATE_RESTORE "$RUN_STATE"; then
    RUN_STATE=""
  else
    RUN_RESTORE_RC=1
    HARNESS_FAIL=1
  fi
fi

SOURCE_DRIFT_RC=0
if ! WPCC_SOURCE_MANIFEST "$SOURCE_MANIFEST_AFTER" "$ROOT" || ! WPCC_SOURCE_ASSERT_IDENTICAL "$SOURCE_MANIFEST_BEFORE" "$SOURCE_MANIFEST_AFTER"; then
  SOURCE_DRIFT_RC=1
  HARNESS_FAIL=1
fi
END_MONOTONIC_NS="$(WPCC_GATE_MONOTONIC_NS)"
DURATION_MS="$(WPCC_GATE_MONOTONIC_DURATION_MS "$START_MONOTONIC_NS" "$END_MONOTONIC_NS")"
DURATION_SECONDS=$((DURATION_MS/1000)); DURATION_REMAINDER=$((DURATION_MS%1000))
DURATION_FORMATTED="$(printf '%s.%03ds' "$DURATION_SECONDS" "$DURATION_REMAINDER")"

echo "------------------------------------------------"
echo "$TIER result: $TP passed, $TF failed  |  net-new: $NETNEW  |  monotonic: $DURATION_FORMATTED"
echo "suites executed: $EXECUTED_SUITES / $(echo "$LIST_SUITES" | grep -c .)"
[ "$GATE_ABORT" -eq 0 ] || echo "gate abort: $GATE_ABORT_REASON"
[ "$RUN_RESTORE_RC" -eq 0 ] && echo "state restoration: verified" || echo "state restoration: FAILED"
[ "$SOURCE_DRIFT_RC" -eq 0 ] && echo "source restoration: verified" || echo "source restoration: FAILED"
[ -n "$FAILLINES" ] && { echo "failing suites:"; printf '%s' "$FAILLINES"; }
[ "$LINT_RC" = 0 ] || echo "LINT FAILED"
# T2 is a release gate: any failure is fatal. T0/T1 retain baseline comparison for
# development use, but runner/restoration failures are fatal at every tier.
if [ "$TIER" = "T2" ]; then
  [ "$TF" -eq 0 ] && [ "$LINT_RC" -eq 0 ] && [ "$HARNESS_FAIL" -eq 0 ]
else
  [ "$NETNEW" -eq 0 ] && [ "$LINT_RC" -eq 0 ] && [ "$HARNESS_FAIL" -eq 0 ]
fi
