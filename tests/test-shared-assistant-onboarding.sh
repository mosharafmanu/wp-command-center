#!/usr/bin/env bash
# Shared rendered onboarding contract for every registered assistant client.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WP_ROOT="${WPCC_TEST_WP_PATH:-$(cd "$ROOT/../../.." && pwd)}"

wp --path="$WP_ROOT" eval-file "$ROOT/tests/lib/shared-assistant-onboarding.php" "$ROOT"
