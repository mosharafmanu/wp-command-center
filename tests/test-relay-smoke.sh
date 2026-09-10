#!/usr/bin/env bash
#
# Runner hook: exercises the MCP relay end-to-end (boot → handshake →
# tools/list==42 → live system_info → AbortController timeout → invalid-action
# audit). Delegates to scripts/relay-smoke.sh so the same harness is usable
# standalone and from tests/run.sh. Included in the T2 (pre-deploy) tier so a
# relay or runtime change cannot be handed over syntax-clean-but-dead.
set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# As a suite in the pre-deploy tier the target endpoint may not yet carry the
# runtime under test, so the invalid-action AUDIT runs in 'report' mode: relay
# health (BOOT/TOOLS/CALL/TIMEOUT) gates, audit mismatches are surfaced but not
# fatal here. Run scripts/relay-smoke.sh directly (SMOKE_AUDIT=strict, default)
# after deploy to prove all runtimes conform.
export SMOKE_AUDIT="${SMOKE_AUDIT:-report}"
bash "$ROOT/scripts/relay-smoke.sh"
relay_rc=$?
if [ "$relay_rc" -eq 0 ]; then
	echo "Relay smoke: 1 passed, 0 failed"
	exit 0
fi
echo "Relay smoke: 0 passed, 1 failed"
exit "$relay_rc"
