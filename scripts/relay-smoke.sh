#!/usr/bin/env bash
#
# SiteRadian MCP Relay smoke test wrapper.
#
#   scripts/relay-smoke.sh            # full run (needs a live endpoint + token)
#   RELAY=/path/to/relay.mjs scripts/relay-smoke.sh
#   SITERADIAN_MCP_URL=... SITERADIAN_TOKEN=... scripts/relay-smoke.sh
#   SMOKE_SKIP_LIVE=1 scripts/relay-smoke.sh   # offline: BOOT + TIMEOUT only
#
# Verifies the relay BOOTS and ANSWERS — the exact failure mode (`node --check`
# clean but dies on boot) that this test exists to make unrepeatable.
#
# Env resolution order for the live endpoint:
#   1. $SITERADIAN_MCP_URL / $SITERADIAN_TOKEN if already exported.
#   2. The `purple-live` server block in Claude Desktop's config (dev convenience).
#   3. Otherwise → offline mode (BOOT + TIMEOUT still gate the relay).
#
# Exit code is the harness's: 0 = pass, non-zero = fail. Wire into CI / pre-deploy.
set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

RELAY="${RELAY:-$ROOT/sdk/javascript/siteradian-mcp-relay.mjs}"
export RELAY
export EXPECTED_TOOLS="${EXPECTED_TOOLS:-42}"

command -v node >/dev/null 2>&1 || { echo "relay-smoke: node not found on PATH" >&2; exit 3; }
[ -f "$RELAY" ] || { echo "relay-smoke: relay not found at $RELAY" >&2; exit 3; }

# Resolve live endpoint if not already provided.
SITERADIAN_MCP_URL="${SITERADIAN_MCP_URL:-${WPCC_MCP_URL:-}}"
SITERADIAN_TOKEN="${SITERADIAN_TOKEN:-${WPCC_TOKEN:-}}"
export SITERADIAN_MCP_URL SITERADIAN_TOKEN
if [ -z "${SITERADIAN_MCP_URL:-}" ] || [ -z "${SITERADIAN_TOKEN:-}" ]; then
  CFG="${WPCC_DESKTOP_CONFIG:-$HOME/Library/Application Support/Claude/claude_desktop_config.json}"
  if [ "${SMOKE_SKIP_LIVE:-0}" != "1" ] && [ -f "$CFG" ] && command -v python3 >/dev/null 2>&1; then
    eval "$(python3 - "$CFG" <<'PY'
import json, sys, shlex
try:
    cfg = json.load(open(sys.argv[1]))
    for name, srv in cfg.get("mcpServers", {}).items():
        if "purple" in name.lower() and "live" in name.lower():
            env = srv.get("env", {})
            url = env.get("SITERADIAN_MCP_URL") or env.get("WPCC_MCP_URL")
            token = env.get("SITERADIAN_TOKEN") or env.get("WPCC_TOKEN")
            if url and token:
                print("export SITERADIAN_MCP_URL=" + shlex.quote(url))
                print("export SITERADIAN_TOKEN=" + shlex.quote(token))
                break
except Exception:
    pass
PY
)"
  fi
fi

if [ -z "${SITERADIAN_MCP_URL:-}" ] || [ -z "${SITERADIAN_TOKEN:-}" ]; then
  if [ "${WPCC_SMOKE_REQUIRE_LIVE:-0}" = "1" ]; then
    echo "relay-smoke: no live endpoint/token and WPCC_SMOKE_REQUIRE_LIVE=1 → fail" >&2
    exit 3
  fi
  echo "relay-smoke: no live endpoint/token found → offline mode (BOOT + TIMEOUT only)."
  echo "             set SITERADIAN_MCP_URL + SITERADIAN_TOKEN (or WPCC_SMOKE_REQUIRE_LIVE=1) for full coverage."
  export SMOKE_SKIP_LIVE=1
fi

exec node "$ROOT/scripts/relay-smoke.mjs"
