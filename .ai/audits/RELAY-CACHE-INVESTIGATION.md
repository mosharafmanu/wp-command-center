# Relay Cache Investigation — WPCC Real World Finding #005

**Date:** 2026-06-13  
**Severity:** High (stale relay prevents fixes from reaching Claude Desktop)  
**Status:** Fixed

---

## Current Behavior (Before Fix)

The config generator output this bootstrap command:

```bash
RELAY=/tmp/wpcc-mcp-relay.mjs; [ -f "$RELAY" ] || curl -fsSL -o "$RELAY" https://.../wpcc-mcp-relay.mjs; node "$RELAY"
```

**The `[ -f "$RELAY" ] ||` check meant:**
- First launch: file doesn't exist → download → run
- Second launch: file exists → **skip download** → run cached copy
- After plugin update: file still exists → **skip download** → run STALE copy

Once downloaded, the relay was **never updated**. Every relay fix deployed to the plugin was invisible to Claude Desktop until the user manually deleted `/tmp/wpcc-mcp-relay.mjs`.

---

## Investigation Method

1. Read the current relay source (`sdk/javascript/wpcc-mcp-relay.mjs`) — v2.0.0
2. Analyzed the bootstrap command in all three config generators
3. Traced relay version history across findings:
   - v1.0.0 (Finding #001): Basic forward, no notification handling
   - v2.0.0 (Finding #002): HTTP 204 handling, JSON-RPC validation guard, stderr logging

If a user first connected before Finding #002, their cached relay would be v1.0.0, which:
- Writes error responses for `notifications/initialized` to stdout ✗
- Has no JSON-RPC response validation guard ✗
- May write non-RPC output to stdout ✗

---

## Could Stale Relay Cause MCP Attachment Failures?

**Yes.** If the cached relay is v1.0.0 (from Finding #001):
- It lacks the HTTP 204 notification handling
- It lacks the JSON-RPC guard (`parsed.id == null` check)
- It may write unexpected data to stdout that Claude Desktop rejects

Additionally, all relay improvements (stderr logging for debugging, fetch error handling) are missing from stale copies.

---

## Fix Applied

### 1. Relay Versioning

Added `RELAY_VERSION = '2.0.0'` constant and startup logging to stderr:

```javascript
process.stderr.write(`WPCC MCP Relay v${RELAY_VERSION} starting\n`);
process.stderr.write(`WPCC MCP Relay: endpoint ${MCP_URL}\n`);
```

This appears in Claude Desktop's MCP logs, making it possible to identify which relay version is executing.

### 2. Bootstrap: Always Download

Changed from conditional download to always-download:

**Before (cached forever):**
```bash
RELAY=/tmp/wpcc-mcp-relay.mjs; [ -f "$RELAY" ] || curl -fsSL -o "$RELAY" URL; node "$RELAY"
```

**After (always fresh):**
```bash
RELAY=/tmp/wpcc-mcp-relay.mjs; curl -fsSL -o "$RELAY" "URL?v=0.1.0"; node "$RELAY"
```

- Removed `[ -f "$RELAY" ] ||` guard
- Added `?v=<WPCC_VERSION>` cache-buster to URL (prevents proxy/CDN caching)
- Every Claude Desktop restart downloads the latest relay (~3 KB in ~50 ms on typical connections)

### 3. Updated Config Generators

All three generators updated to match:
- `includes/Integration/BaseClientIntegration.php` (9 clients)
- `includes/Integration/ClaudeIntegration.php`
- `includes/Integration/CursorIntegration.php`

### 4. Updated Documentation

- `docs/architecture/AI-INTEGRATIONS.md`
- `docs/product/QUICKSTART.md`

---

## Relay Versions Compared

| Version | Finding | Key Features | In Cache? |
|---------|---------|-------------|-----------|
| v1.0.0 | #001 | Basic stdio↔HTTP forward, no notification handling | If user connected before #002 |
| v2.0.0 | #002 | HTTP 204, JSON-RPC guard, stderr logging, fetch errors | Current source |

---

## Validation Evidence

**Check if stale relay exists on user machine:**

```bash
# Check if cached relay exists
ls -la /tmp/wpcc-mcp-relay.mjs

# Check for version string in cached relay
grep -c 'RELAY_VERSION' /tmp/wpcc-mcp-relay.mjs
# 0 = v1.0.0 (stale, no version field)
# 1 = v2.0.0 (current)

# Check for HTTP 204 handling (v2 feature)
grep -c 'response.status === 204' /tmp/wpcc-mcp-relay.mjs
# 0 = v1.0.0 (stale)
# 1 = v2.0.0 (current)
```

**After fix, Claude Desktop logs should show:**
```
WPCC MCP Relay v2.0.0 starting
WPCC MCP Relay: endpoint https://mosharafmanu.com/wp-json/wp-command-center/v1/mcp
```

---

## Long-Term Architecture Recommendation

### Recommended: Option B — Version File Check

For production, the current always-download approach is acceptable (3 KB = negligible overhead). But for optimal performance:

1. Ship a `RELAY_VERSION` file alongside the relay script
2. Bootstrap fetches just the version file first (a few bytes)
3. Compare against a `.version` stamp stored next to the cached relay
4. Only re-download if versions differ

```bash
RELAY=/tmp/wpcc-mcp-relay.mjs
REMOTE_VER=$(curl -fsSL "https://site/.../RELAY_VERSION")
LOCAL_VER=$(cat /tmp/wpcc-relay.version 2>/dev/null || echo 0)
if [ "$REMOTE_VER" != "$LOCAL_VER" ]; then
    curl -fsSL -o "$RELAY" "https://site/.../wpcc-mcp-relay.mjs"
    echo "$REMOTE_VER" > /tmp/wpcc-relay.version
fi
node "$RELAY"
```

This achieves:
- Instant startup on version match (no download)
- Automatic update on version mismatch
- Minimal overhead (< 1 KB version check)

### Not recommended: Option D — Local Bundled Relay

Shipping the relay locally (e.g., in the Claude Desktop config directory) requires manual user action on every update. The remote download approach keeps the relay synchronized with the plugin automatically.

---

## Files Changed

| File | Change |
|------|--------|
| `sdk/javascript/wpcc-mcp-relay.mjs` | Added RELAY_VERSION, startup logging to stderr |
| `includes/Integration/BaseClientIntegration.php` | Changed bootstrap to always-download with cache-buster |
| `includes/Integration/ClaudeIntegration.php` | Same |
| `includes/Integration/CursorIntegration.php` | Same |
| `docs/architecture/AI-INTEGRATIONS.md` | Updated config template and explanation |
| `docs/product/QUICKSTART.md` | Updated config template |
