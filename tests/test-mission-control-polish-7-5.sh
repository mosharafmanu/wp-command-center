#!/usr/bin/env bash
# PROGRAM-7.5 — Mission Control experience polish (UX only). Static lint + rg.
set -uo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
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
has "AI inactive context (not scored)" "AI features: inactive" "$VIEW"
has "scoring logic unchanged (same components)" "wpcc_ready \+= 30" "$VIEW"

echo "== 3. Friendlier language (Off -> Inactive) =="
hasnt "no blunt 'Off' AI status" "esc_html__\( 'Off'," "$VIEW"
has "AI status reads Inactive" "esc_html__\( 'Inactive'" "$VIEW"

echo "== 4. Pending approvals — Needs-you callout =="
has "needs-you callout" "wpcc-aip-needsyou" "$VIEW"
has "calming approval copy" "Nothing applies to your site until you review it" "$VIEW"

echo "== 5. Workflow promise visualized =="
has "governance flow band" "wpcc-aip-flow" "$VIEW"
has "flow: Inspect" "'Inspect'" "$VIEW"
has "flow: Approve" "'Approve'" "$VIEW"
has "flow: Rollback" "'Rollback'" "$VIEW"

echo "== 6. Activity timeline polish =="
has "timeline component" "wpcc-aip-timeline" "$VIEW"
has "category icons" "wpcc_cat_icon" "$VIEW"
has "time grouping Today/Earlier" "'Today', 'wp-command-center'" "$VIEW"

echo "== 7. Feature routing clarity =="
has "route describes what it powers" "Powers AI-written SEO" "$VIEW"

echo "== 8. Provider wizard clarity =="
has "wizard explains cloud/local/gateway" "Local = a model on your own machine" "$VIEW"

echo "== 9. First-run hero (Run a site report) =="
has "first-run hero elevated" "Start here: see it work in 2 minutes" "$HOME_F"
has "hero button" "button-hero" "$HOME_F"
has "honest: read-only, nothing changed" "Nothing is changed" "$HOME_F"

echo "== 10. Honesty + anchors preserved (no fake data) =="
has "cost still not faked" "Not tracked yet" "$VIEW"
hasnt "no fabricated cost figure" 'cost.*\$[0-9]' "$VIEW"
hasnt "view never echoes a key" "echo .*(wpcc_key|->secret\()" "$VIEW"
has "mission control intact" "Mission control" "$VIEW"
has "honest runtime badges intact" "USED BY RUNTIME" "$VIEW"

echo
echo "== Summary =="
echo "  $P passed, $F failed"
[ "$F" -eq 0 ]
