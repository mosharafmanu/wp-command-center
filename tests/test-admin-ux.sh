#!/usr/bin/env bash
# Step 35 - admin dashboard UX structural verification.
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$DIR/.." && pwd)"
DASHBOARD="$ROOT/includes/Admin/views/dashboard.php"
SCHEMA="$ROOT/includes/Core/Schema.php"

P=0
F=0
pass(){ P=$((P+1)); echo "  PASS: $1"; }
fail(){ F=$((F+1)); echo "  FAIL: $1"; }
has(){ if rg -q "$2" "$3"; then pass "$1"; else fail "$1"; fi; }

echo "== 1. PHP Structure =="
if php -l "$DASHBOARD" >/dev/null; then pass "dashboard passes PHP lint"; else fail "dashboard passes PHP lint"; fi

echo "== 2. Runtime Visualization =="
has "runtime hierarchy panel exists" "Runtime Hierarchy" "$DASHBOARD"
for label in Sessions Tasks Actions Plans Requests Queue Results; do
	has "runtime hierarchy includes $label" "'$label'" "$DASHBOARD"
done

echo "== 3. Timeline Controls =="
has "timeline type filter exists" 'name="timeline_type"' "$DASHBOARD"
has "timeline status filter exists" 'name="timeline_status"' "$DASHBOARD"
has "timeline page input is handled" 'timeline_page' "$DASHBOARD"
has "timeline previous control exists" "'Previous'" "$DASHBOARD"
has "timeline next control exists" "'Next'" "$DASHBOARD"
has "timeline status badges exist" 'event\['"'"'status'"'"'\]' "$DASHBOARD"

echo "== 4. States, Badges & Results =="
has "shared empty state styling exists" 'wpcc-empty-state' "$DASHBOARD"
has "shared status badge styling exists" 'wpcc-status-badge' "$DASHBOARD"
has "recommendation severity badge exists" "recommendation\['severity'\]" "$DASHBOARD"
has "queue status badge exists" "op\['status'\]" "$DASHBOARD"
has "operation results panel exists" 'Recent Operation Results' "$DASHBOARD"
has "operation result links exist" 'View result' "$DASHBOARD"
has "selected result detail exists" 'Selected Result' "$DASHBOARD"

echo "== 5. Scope Guard =="
has "schema is at the current expected version" "DB_VERSION = '2.4.0'" "$SCHEMA"

echo
echo "== Summary =="
echo "  $P passed, $F failed"
[ "$F" -eq 0 ]
