#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────────────────────────
# WP Command Center — Plugin Lifecycle & Snapshot/Rollback Example
# ──────────────────────────────────────────────────────────────────────────────
# Demonstrates:
#   1. List plugins via plugin_manage operation
#   2. Activate a plugin
#   3. Create a file snapshot for safety
#   4. Rollback a plugin activation (deactivate)
#
# Prerequisites:
#   - curl installed
#   - wpcc-env.sh sourced (exports $WPCC_BASE and $WPCC_TOKEN)
#   - A full-access token
# ──────────────────────────────────────────────────────────────────────────────
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(dirname "$SCRIPT_DIR")"

# ── 1. Source credentials ──
if [ -f "$PLUGIN_DIR/wpcc-env.sh" ]; then
  # shellcheck source=/dev/null
  source "$PLUGIN_DIR/wpcc-env.sh"
else
  echo "ERROR: wpcc-env.sh not found at $PLUGIN_DIR/wpcc-env.sh"
  exit 1
fi

echo "=== WP Command Center — Plugin Lifecycle & Rollback ==="
echo ""

# ── Helper ──
wpcc() {
  curl -s -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" "$@"
}

# ── 2. List plugins ──
echo ">>> Step 1: Listing installed plugins..."
PLUGIN_LIST=$(wpcc -X POST \
  -d '{"action":"plugin_list"}' \
  "$WPCC_BASE/operations/plugin_manage/run")

echo "$PLUGIN_LIST" | jq -r '.plugins[]? | select(.active == true) | "   ACTIVE:   \(.slug) v\(.version)"'
echo "$PLUGIN_LIST" | jq -r '.plugins[]? | select(.active != true) | "   INACTIVE: \(.slug) v\(.version)"'
echo ""

# Pick an inactive plugin to activate (demonstrate the workflow)
TARGET_SLUG=$(echo "$PLUGIN_LIST" | jq -r '.plugins[]? | select(.active != true) | .slug' | head -1)

if [ -z "$TARGET_SLUG" ]; then
  echo "No inactive plugins found to demonstrate activation."
  echo "Showing active plugins only — skipping activation/rollback steps."
  echo ""
  echo "=== Plugin Lifecycle complete ==="
  exit 0
fi

echo "   Target for demo: $TARGET_SLUG"
echo ""

# ── 3. Create a snapshot of the plugin file before activation ──
echo ">>> Step 2: Creating a snapshot of plugins/$TARGET_SLUG..."
SNAPSHOT=$(wpcc -X POST \
  -d "{\"action\":\"snapshot_create\",\"path\":\"plugins/$TARGET_SLUG/$TARGET_SLUG.php\",\"label\":\"Pre-activation snapshot of $TARGET_SLUG\"}" \
  "$WPCC_BASE/operations/snapshot_manage/run")

SNAPSHOT_ID=$(echo "$SNAPSHOT" | jq -r '.snapshot_id')
echo "   Snapshot created: $SNAPSHOT_ID"
echo "   Path: $(echo "$SNAPSHOT" | jq -r '.path')"
echo "   Created at: $(echo "$SNAPSHOT" | jq -r '.created_at')"
echo ""

# ── 4. Activate the plugin ──
echo ">>> Step 3: Activating plugin $TARGET_SLUG..."
ACTIVATE=$(wpcc -X POST \
  -d "{\"action\":\"plugin_activate\",\"slug\":\"$TARGET_SLUG\"}" \
  "$WPCC_BASE/operations/plugin_manage/run")

ACTIVATE_STATUS=$(echo "$ACTIVATE" | jq -r '.status')
echo "   Activation result: $ACTIVATE_STATUS"
echo ""

if echo "$ACTIVATE" | jq -e '.error' > /dev/null 2>&1; then
  echo "   WARNING: Activation returned an error — continuing with rollback demo anyway."
fi

# ── 5. Rollback the plugin activation (deactivate) ──
echo ">>> Step 4: Rolling back plugin activation (deactivating $TARGET_SLUG)..."
DEACTIVATE=$(wpcc -X POST \
  -d "{\"action\":\"plugin_deactivate\",\"slug\":\"$TARGET_SLUG\"}" \
  "$WPCC_BASE/operations/plugin_manage/run")

DEACTIVATE_STATUS=$(echo "$DEACTIVATE" | jq -r '.status')
echo "   Deactivation result: $DEACTIVATE_STATUS"
echo ""

# ── 6. Verify the snapshot still exists ──
echo ">>> Step 5: Verifying snapshot $SNAPSHOT_ID..."
SNAPSHOT_LIST=$(wpcc -X POST \
  -d '{"action":"snapshot_list"}' \
  "$WPCC_BASE/operations/snapshot_manage/run")

SNAPSHOT_COUNT=$(echo "$SNAPSHOT_LIST" | jq '.snapshots | length')
echo "   Total snapshots: $SNAPSHOT_COUNT"
echo ""

echo "=== Plugin Lifecycle complete ==="
