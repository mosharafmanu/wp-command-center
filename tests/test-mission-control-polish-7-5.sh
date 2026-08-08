#!/usr/bin/env bash
# PROGRAM-7.5 — Mission Control experience polish (UX only). Static lint + rg.
set -uo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Derived, not hardcoded: the text domain follows the plugin slug, and these
# assertions must survive a slug rename.
WPCC_TEXTDOMAIN=$(grep -m1 "^ \* Text Domain:" "$(dirname "${BASH_SOURCE[0]}")"/../*.php | sed 's/.*Text Domain: *//;s/ *$//')
ROOT="$(cd "$DIR/.." && pwd)"
VIEW="$ROOT/includes/Admin/views/ai-setup.php"
HOME_F="$ROOT/includes/Admin/views/command-home.php"

P=0; F=0
pass(){ P=$((P+1)); echo "  PASS: $1"; }
fail(){ F=$((F+1)); echo "  FAIL: $1"; }
has(){ if rg -q -e "$2" "$3"; then pass "$1"; else fail "$1"; fi; }
hasnt(){ if rg -q -e "$2" "$3"; then fail "$1"; else pass "$1"; fi; }

echo "== 1. Lint =="
php -l "$VIEW" >/dev/null 2>&1 && pass "lint ai-setup.php" || fail "lint ai-setup.php"
php -l "$HOME_F" >/dev/null 2>&1 && pass "lint command-home.php" || fail "lint command-home.php"

echo "== 2. Readiness is self-explanatory (no scoring change) =="
has "readiness checklist rendered" "wpcc-aip-checklist" "$VIEW"
has "checklist derives from existing components" "wpcc_ready_steps" "$VIEW"
has "checklist item: connection" "A connection added" "$VIEW"
has "checklist item: default" "A default chosen" "$VIEW"
# The provider screen shed its dashboard furniture: a readiness ring, four KPI
# tiles, a duplicated approvals banner, and an internal pipeline diagram. Per-tool
# on/off state below is more precise than an "AI status" tile ever was.
lacks "no readiness ring on a settings screen" "wpcc-aip-ring" "$VIEW"
has "scoring logic unchanged (same components)" "wpcc_ready \+= 30" "$VIEW"

echo "== 3. Friendlier language (Off -> Inactive) =="
hasnt "no blunt 'Off' AI status" "esc_html__\( 'Off'," "$VIEW"
lacks "no KPI tiles on a settings screen" "wpcc-aip-kpis" "$VIEW"

echo "== 4. Pending approvals — Needs-you callout =="
has "needs-you callout" "wpcc-aip-needsyou" "$VIEW"
lacks "approvals are not duplicated here" "wpcc-aip-needsyou" "$VIEW"

echo "== 5. Workflow promise visualized =="
has "governance flow band" "wpcc-aip-flow" "$VIEW"
lacks "no internal pipeline diagram" "wpcc-aip-flow" "$VIEW"
has "connection warning still surfaces" "wpcc-aip-warn" "$VIEW"
has "provider connections still listed" "wpcc_conns" "$VIEW"

echo "== 6. Activity timeline polish =="
has "timeline component" "wpcc-aip-timeline" "$VIEW"
has "category icons" "wpcc_cat_icon" "$VIEW"
has "time grouping Today/Earlier" "'Today', '$WPCC_TEXTDOMAIN'" "$VIEW"

echo "== 7. Feature routing clarity =="
has "route describes what it powers" "Powers AI-written SEO" "$VIEW"

echo "== 8. Provider wizard clarity =="
has "wizard explains cloud/local/gateway" "Local = a model on your own machine" "$VIEW"

echo "== 9. First-run hero (Run a site report) =="
# V1: the hero is now the single contextual next action, not a fixed banner.
has "first-run hero elevated" "wpcc-home__next" "$HOME_F"
has "hero button" "button-hero" "$HOME_F"
has "honest: read-only surface" "READ-ONLY" "$HOME_F"

echo "== 10. Honesty + anchors preserved (no fake data) =="
has "cost still not faked" "Not tracked yet" "$VIEW"
hasnt "no fabricated cost figure" 'cost.*\$[0-9]' "$VIEW"
hasnt "view never echoes a key" "echo .*(wpcc_key|->secret\()" "$VIEW"
# Phase 2.5A: renamed "Mission control" → "Recent AI activity" (avoids the Home collision).
has "AI activity section intact" "Recent AI activity" "$VIEW"
has "honest runtime badges intact" "USED BY RUNTIME" "$VIEW"

echo
echo "== Summary =="
echo "  $P passed, $F failed"
[ "$F" -eq 0 ]
