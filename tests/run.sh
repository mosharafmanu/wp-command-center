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

# ── Run a single suite (network suites retry once) ───────────────
run_one() {
  local suite="$1" out p f
  out="$(bash "tests/$suite" 2>&1)"
  p="$(echo "$out" | grep -oE '[0-9]+ passed' | tail -1 | grep -oE '[0-9]+')"; p="${p:-0}"
  f="$(echo "$out" | grep -oE '[0-9]+ failed' | tail -1 | grep -oE '[0-9]+')"; f="${f:-0}"
  if [ "$f" -gt 0 ] && is_network "$suite"; then
    out="$(bash "tests/$suite" 2>&1)"
    p="$(echo "$out" | grep -oE '[0-9]+ passed' | tail -1 | grep -oE '[0-9]+')"; p="${p:-0}"
    f="$(echo "$out" | grep -oE '[0-9]+ failed' | tail -1 | grep -oE '[0-9]+')"; f="${f:-0}"
  fi
  printf '%s\t%s\t%s\n' "$suite" "$p" "$f"
}
export -f run_one is_network
export ROOT

baseline_for() { awk -F'\t' -v s="$1" '$1==s{print $2}' "$BASE" 2>/dev/null | head -1; }

# ── Execute ──────────────────────────────────────────────────────
START=$(date +%s)
LINT_RC=0
if [ "$TIER" != "T2" ]; then lint_changed || LINT_RC=1; fi

[ -z "$LIST_SUITES" ] && { echo "== $TIER: no suites selected (no matching runtime in the change signal) =="; [ "$LINT_RC" = 0 ] && exit 0 || exit 1; }

echo "== $TIER: $(echo "$LIST_SUITES" | grep -c .) suites =="
RESULTS="$(mktemp)"
if [ "$JOBS" -gt 1 ] && command -v xargs >/dev/null; then
  echo "$LIST_SUITES" | xargs -P "$JOBS" -I{} bash -c 'cd "$ROOT"; run_one "{}"' > "$RESULTS"
else
  while IFS= read -r s; do [ -z "$s" ] && continue; run_one "$s"; done <<< "$LIST_SUITES" > "$RESULTS"
fi

TP=0; TF=0; NETNEW=0; FAILLINES=""
while IFS=$'\t' read -r suite p f; do
  [ -z "$suite" ] && continue
  TP=$((TP+p)); TF=$((TF+f))
  if [ "$f" -gt 0 ]; then
    bl="$(baseline_for "$suite")"; bl="${bl:-0}"
    nn=$((f-bl)); [ "$nn" -lt 0 ] && nn=0
    NETNEW=$((NETNEW+nn))
    FAILLINES="$FAILLINES  $suite: $f (baseline $bl, net-new $nn)"$'\n'
  fi
done < <(sort "$RESULTS")
rm -f "$RESULTS"
END=$(date +%s)

echo "------------------------------------------------"
echo "$TIER result: $TP passed, $TF failed  |  net-new: $NETNEW  |  $((END-START))s"
[ -n "$FAILLINES" ] && { echo "failing suites:"; printf '%s' "$FAILLINES"; }
[ "$LINT_RC" = 0 ] || echo "LINT FAILED"
# Exit non-zero only on net-new failures or lint failure (chronic baseline is OK).
{ [ "$NETNEW" -eq 0 ] && [ "$LINT_RC" -eq 0 ]; }
