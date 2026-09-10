#!/usr/bin/env bash
# T2-only authentication liveness, wall-clock discontinuity, and monotonic
# timing helpers. No function in this file mutates WordPress state.

WPCC_GATE_EXPECTED_TOOLS="${WPCC_GATE_EXPECTED_TOOLS:-42}"
WPCC_GATE_CLOCK_THRESHOLD_SECONDS="${WPCC_GATE_CLOCK_THRESHOLD_SECONDS:-60}"
WPCC_GATE_HTTP_TIMEOUT_SECONDS="${WPCC_GATE_HTTP_TIMEOUT_SECONDS:-15}"

WPCC_GATE_WALL_NOW() {
	date +%s
}

WPCC_GATE_MONOTONIC_NS() {
	php -r 'echo hrtime(true);'
}

WPCC_GATE_CLOCK_INIT() {
	WPCC_GATE_WALL_START="$(WPCC_GATE_WALL_NOW)" || return 1
	WPCC_GATE_MONOTONIC_START="$(WPCC_GATE_MONOTONIC_NS)" || return 1
	WPCC_GATE_WALL_LAST="$WPCC_GATE_WALL_START"
	WPCC_GATE_MONOTONIC_LAST="$WPCC_GATE_MONOTONIC_START"
	export WPCC_GATE_WALL_START WPCC_GATE_MONOTONIC_START WPCC_GATE_WALL_LAST WPCC_GATE_MONOTONIC_LAST
}

# Pure comparator used by both the runner and synthetic regression tests.
WPCC_GATE_CLOCK_CHECK_VALUES() {
	local previous_wall="$1" previous_mono="$2" current_wall="$3" current_mono="$4" threshold="${5:-$WPCC_GATE_CLOCK_THRESHOLD_SECONDS}"
	local wall_delta mono_delta skew magnitude
	wall_delta=$((current_wall-previous_wall))
	mono_delta=$(((current_mono-previous_mono)/1000000000))
	skew=$((wall_delta-mono_delta))
	magnitude="$skew"; [ "$magnitude" -ge 0 ] || magnitude=$((-magnitude))
	if [ "$magnitude" -gt "$threshold" ]; then
		printf 'CLOCK DISCONTINUITY: wall_delta=%ss monotonic_delta=%ss skew=%ss threshold=%ss\n' "$wall_delta" "$mono_delta" "$skew" "$threshold" >&2
		return 1
	fi
	return 0
}

WPCC_GATE_CLOCK_CHECK() {
	local current_wall current_mono rc=0
	current_wall="$(WPCC_GATE_WALL_NOW)" || return 1
	current_mono="$(WPCC_GATE_MONOTONIC_NS)" || return 1
	WPCC_GATE_CLOCK_CHECK_VALUES "$WPCC_GATE_WALL_LAST" "$WPCC_GATE_MONOTONIC_LAST" "$current_wall" "$current_mono" "$WPCC_GATE_CLOCK_THRESHOLD_SECONDS" || rc=$?
	WPCC_GATE_WALL_LAST="$current_wall"
	WPCC_GATE_MONOTONIC_LAST="$current_mono"
	export WPCC_GATE_WALL_LAST WPCC_GATE_MONOTONIC_LAST
	return "$rc"
}

WPCC_GATE_MONOTONIC_DURATION_MS() {
	local start_ns="$1" end_ns="$2"
	printf '%s\n' "$(((end_ns-start_ns)/1000000))"
}

WPCC_GATE_AUTH_LIVENESS() {
	local token="${1:-${WPCC_TOKEN:-}}" base="${2:-${WPCC_BASE:-}}" body status count
	[ -n "$token" ] || { echo 'credential is absent' >&2; return 1; }
	[ -n "$base" ] || { echo 'WPCC endpoint is absent' >&2; return 1; }
	body="$(mktemp "${TMPDIR:-/tmp}/wpcc-gate-auth.XXXXXX")" || return 1
	status="$(curl -sS --connect-timeout "$WPCC_GATE_HTTP_TIMEOUT_SECONDS" --max-time "$WPCC_GATE_HTTP_TIMEOUT_SECONDS" -o "$body" -w '%{http_code}' -H "Authorization: Bearer $token" "$base/operations" 2>/dev/null)" || status="000"
	if [ "$status" != "200" ]; then
		rm -f "$body"
		printf 'credential authentication returned HTTP %s\n' "$status" >&2
		return 1
	fi
	count="$(jq -r 'if type == "array" then length else (.operations | length) end' "$body" 2>/dev/null || true)"
	rm -f "$body"
	if [ "$count" != "$WPCC_GATE_EXPECTED_TOOLS" ]; then
		printf 'operation catalogue count mismatch: expected %s, got %s\n' "$WPCC_GATE_EXPECTED_TOOLS" "${count:-unparseable}" >&2
		return 1
	fi
	return 0
}

WPCC_GATE_AUTH_PREFLIGHT() {
	local token="${1:-${WPCC_TOKEN:-}}" base="${2:-${WPCC_BASE:-}}" body status count
	if ! WPCC_GATE_AUTH_LIVENESS "$token" "$base"; then
		echo 'AUTHENTICATION GATE FAILURE: initial credential preflight failed; no suites executed.' >&2
		return 91
	fi
	body="$(mktemp "${TMPDIR:-/tmp}/wpcc-gate-mcp.XXXXXX")" || return 91
	status="$(curl -sS --connect-timeout "$WPCC_GATE_HTTP_TIMEOUT_SECONDS" --max-time "$WPCC_GATE_HTTP_TIMEOUT_SECONDS" -o "$body" -w '%{http_code}' -X POST -H "Authorization: Bearer $token" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}' "$base/mcp" 2>/dev/null)" || status="000"
	count="$(jq -r '.result.tools | length' "$body" 2>/dev/null || true)"
	rm -f "$body"
	if [ "$status" != "200" ] || [ "$count" != "$WPCC_GATE_EXPECTED_TOOLS" ]; then
		printf 'AUTHENTICATION GATE FAILURE: MCP preflight expected HTTP 200 and %s tools; got HTTP %s and %s.\n' "$WPCC_GATE_EXPECTED_TOOLS" "$status" "${count:-unparseable}" >&2
		return 91
	fi
	printf 'authentication preflight: PASS (REST authenticated; MCP tools=%s)\n' "$count"
	return 0
}

WPCC_GATE_CHECKPOINT() {
	if ! WPCC_GATE_CLOCK_CHECK; then
		echo 'CLOCK GATE FAILURE: wall-clock discontinuity detected; aborting before further suites.' >&2
		return 92
	fi
	if ! WPCC_GATE_AUTH_LIVENESS; then
		echo 'AUTHENTICATION GATE FAILURE: credential lost liveness; aborting before further suites.' >&2
		return 91
	fi
	return 0
}

export -f WPCC_GATE_WALL_NOW WPCC_GATE_MONOTONIC_NS WPCC_GATE_CLOCK_INIT
export -f WPCC_GATE_CLOCK_CHECK_VALUES WPCC_GATE_CLOCK_CHECK WPCC_GATE_MONOTONIC_DURATION_MS
export -f WPCC_GATE_AUTH_LIVENESS WPCC_GATE_AUTH_PREFLIGHT WPCC_GATE_CHECKPOINT
