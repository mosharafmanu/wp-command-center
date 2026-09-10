#!/usr/bin/env bash
# Focused regression for T2 credential liveness, clock-jump detection, monotonic
# duration, and exact cleanup. Raw credentials are never printed.
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$DIR/.." && pwd)"
WP_ROOT="$(cd "$ROOT/../../.." && pwd)"
source "$DIR/lib/gate-liveness.sh"
source "$DIR/lib/source-integrity.sh"

P=0; F=0; LIVE_ID=""; EXPIRED_ID=""
pass(){ P=$((P+1)); echo "  PASS: $1"; }
fail(){ F=$((F+1)); echo "  FAIL: $1"; }
ok(){ [ "$2" = 1 ] && pass "$1" || fail "$1"; }
cleanup(){
	for id in "$LIVE_ID" "$EXPIRED_ID"; do
		[ -z "$id" ] || env WPCC_GATE_DELETE_ID="$id" wp --path="$WP_ROOT" eval '$r=(new \WPCommandCenter\Security\AuthTokens())->delete((string)getenv("WPCC_GATE_DELETE_ID"));' >/dev/null 2>&1 || true
	done
	rm -f "${SOURCE_BEFORE:-}" "${SOURCE_AFTER:-}"
}
trap cleanup EXIT

SOURCE_BEFORE="$(mktemp "${TMPDIR:-/tmp}/wpcc-liveness-source-before.XXXXXX")"
SOURCE_AFTER="$(mktemp "${TMPDIR:-/tmp}/wpcc-liveness-source-after.XXXXXX")"
WPCC_SOURCE_MANIFEST "$SOURCE_BEFORE" "$ROOT"
WPCC_BASE="$(wp --path="$WP_ROOT" eval 'echo untrailingslashit(rest_url("wp-command-center/v1"));' 2>/dev/null)"

LIVE="$(wp --path="$WP_ROOT" eval '$a=new \WPCommandCenter\Security\AuthTokens();$r=$a->create("T2 liveness valid ".wp_generate_uuid4(),\WPCommandCenter\Security\AuthTokens::SCOPE_FULL,time()+600,1);if(!is_wp_error($r))echo wp_json_encode(["token"=>$r["token"],"id"=>$r["record"]["id"]]);' 2>/dev/null)"
LIVE_TOKEN="$(printf '%s' "$LIVE"|jq -r '.token // empty')"; LIVE_ID="$(printf '%s' "$LIVE"|jq -r '.id // empty')"
if WPCC_GATE_AUTH_PREFLIGHT "$LIVE_TOKEN" "$WPCC_BASE" >/dev/null 2>&1; then pass "valid credential passes REST and MCP preflight"; else fail "valid credential passes REST and MCP preflight"; fi

INVALID_TOKEN="wpcc_$(printf 'Z%.0s' {1..64})"
if WPCC_GATE_AUTH_PREFLIGHT "$INVALID_TOKEN" "$WPCC_BASE" >/dev/null 2>&1; then fail "invalid credential aborts before suites"; else pass "invalid credential aborts before suites"; fi
RUN_OUT="$(env WPCC_TOKEN="$INVALID_TOKEN" WPCC_BASE="$WPCC_BASE" bash "$DIR/run.sh" --tier T2 2>&1)"; RUN_RC=$?
ok "runner classifies invalid startup credential before suite execution" "$([ "$RUN_RC" -eq 91 ] && printf '%s' "$RUN_OUT"|grep -q 'AUTHENTICATION GATE FAILURE' && ! printf '%s' "$RUN_OUT"|grep -q 'governance baseline:' && echo 1 || echo 0)"

EXPIRED="$(wp --path="$WP_ROOT" eval '$a=new \WPCommandCenter\Security\AuthTokens();$r=$a->create("T2 liveness expired ".wp_generate_uuid4(),\WPCommandCenter\Security\AuthTokens::SCOPE_FULL,time()-1,1);if(!is_wp_error($r))echo wp_json_encode(["token"=>$r["token"],"id"=>$r["record"]["id"]]);' 2>/dev/null)"
EXPIRED_TOKEN="$(printf '%s' "$EXPIRED"|jq -r '.token // empty')"; EXPIRED_ID="$(printf '%s' "$EXPIRED"|jq -r '.id // empty')"
EXP_OUT="$(WPCC_GATE_AUTH_PREFLIGHT "$EXPIRED_TOKEN" "$WPCC_BASE" 2>&1)"; EXP_RC=$?
ok "simulated expiry returns gate-auth failure" "$([ "$EXP_RC" -eq 91 ] && printf '%s' "$EXP_OUT"|grep -q 'AUTHENTICATION GATE FAILURE' && echo 1 || echo 0)"

WPCC_TOKEN="$LIVE_TOKEN"; export WPCC_TOKEN WPCC_BASE
WPCC_GATE_CLOCK_INIT
if WPCC_GATE_CHECKPOINT >/dev/null 2>&1; then pass "between-suite liveness accepts live credential"; else fail "between-suite liveness accepts live credential"; fi
env WPCC_GATE_DELETE_ID="$LIVE_ID" wp --path="$WP_ROOT" eval '$r=(new \WPCommandCenter\Security\AuthTokens())->delete((string)getenv("WPCC_GATE_DELETE_ID"));' >/dev/null 2>&1
LIVE_ID=""
MID_OUT="$(WPCC_GATE_CHECKPOINT 2>&1)"; MID_RC=$?
ok "mid-run invalidation stops at the next checkpoint" "$([ "$MID_RC" -eq 91 ] && printf '%s' "$MID_OUT"|grep -q 'AUTHENTICATION GATE FAILURE' && echo 1 || echo 0)"

if WPCC_GATE_CLOCK_CHECK_VALUES 1000 1000000000 1700 61000000000 60 >/dev/null 2>&1; then fail "forward wall-clock jump detected"; else pass "forward wall-clock jump detected"; fi
if WPCC_GATE_CLOCK_CHECK_VALUES 1000 1000000000 500 61000000000 60 >/dev/null 2>&1; then fail "backward wall-clock jump detected"; else pass "backward wall-clock jump detected"; fi
if WPCC_GATE_CLOCK_CHECK_VALUES 1000 1000000000 1060 61000000000 60 >/dev/null 2>&1; then pass "normal wall and monotonic deltas accepted"; else fail "normal wall and monotonic deltas accepted"; fi

M_START="$(WPCC_GATE_MONOTONIC_NS)"; sleep 0.05; M_END="$(WPCC_GATE_MONOTONIC_NS)"; M_MS="$(WPCC_GATE_MONOTONIC_DURATION_MS "$M_START" "$M_END")"
ok "monotonic duration remains sane independently of wall time" "$([ "$M_MS" -ge 20 ] && [ "$M_MS" -lt 2000 ] && echo 1 || echo 0)"

env WPCC_GATE_DELETE_ID="$EXPIRED_ID" wp --path="$WP_ROOT" eval '$r=(new \WPCommandCenter\Security\AuthTokens())->delete((string)getenv("WPCC_GATE_DELETE_ID"));' >/dev/null 2>&1; EXPIRED_ID=""
REMAINS="$(wp --path="$WP_ROOT" eval '$n=0;foreach((new \WPCommandCenter\Security\AuthTokens())->list() as $t){if(str_starts_with((string)$t["label"],"T2 liveness "))$n++;}echo $n;' 2>/dev/null)"
ok "synthetic credentials are deleted" "$([ "$REMAINS" = 0 ] && echo 1 || echo 0)"
WRAP_OUT="$(env -u WPCC_TOKEN bash "$DIR/run-t2-gate.sh" --preflight-only 2>&1)"; WRAP_RC=$?
ok "gate wrapper valid preflight path succeeds" "$([ "$WRAP_RC" -eq 0 ] && printf '%s' "$WRAP_OUT"|grep -q 'authentication preflight: PASS' && printf '%s' "$WRAP_OUT"|grep -q 'gate credential cleanup: verified' && echo 1 || echo 0)"
WRAP_REMAINS="$(wp --path="$WP_ROOT" eval '$n=0;foreach((new \WPCommandCenter\Security\AuthTokens())->list() as $t){if(str_starts_with((string)$t["label"],"WPCC T2 Gate Wrapper Focused "))$n++;}echo $n;' 2>/dev/null)"
ok "gate wrapper trap deletes its exact credential" "$([ "$WRAP_REMAINS" = 0 ] && echo 1 || echo 0)"

launchd="$(launchctl getenv WPCC_TOKEN 2>/dev/null || true)"
ok "launchd token binding remains absent" "$([ -z "$launchd" ] && echo 1 || echo 0)"; unset launchd
startup=0; for file in "$HOME/.zshrc" "$HOME/.zprofile" "$HOME/.zshenv" "$HOME/.bashrc" "$HOME/.bash_profile" "$HOME/.profile"; do [ -f "$file" ] || continue; rg -q '(^|[^A-Za-z0-9_])WPCC_TOKEN([^A-Za-z0-9_]|$)' "$file" && startup=$((startup+1)); done
ok "shell startup token binding remains absent" "$([ "$startup" -eq 0 ] && echo 1 || echo 0)"

unset WPCC_TOKEN LIVE_TOKEN EXPIRED_TOKEN INVALID_TOKEN
WPCC_SOURCE_MANIFEST "$SOURCE_AFTER" "$ROOT"
if WPCC_SOURCE_ASSERT_IDENTICAL "$SOURCE_BEFORE" "$SOURCE_AFTER" >/dev/null 2>&1; then pass "focused liveness tests do not change candidate source"; else fail "focused liveness tests do not change candidate source"; fi

echo "Gate liveness: $P passed, $F failed"
[ "$F" -eq 0 ]
