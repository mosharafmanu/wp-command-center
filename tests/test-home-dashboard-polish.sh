#!/usr/bin/env bash
# SiteRadian Home — final visual-polish contract. Read-only.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
HOME_VIEW="$ROOT/includes/Admin/views/command-home.php"

PASS=0
FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
has() { if rg -q -F "$2" "$HOME_VIEW"; then pass "$1"; else fail "$1 (missing '$2')"; fi; }
lacks() { if rg -q -F "$2" "$HOME_VIEW"; then fail "$1 (found '$2')"; else pass "$1"; fi; }

echo "== Locked product story =="
php -l "$HOME_VIEW" >/dev/null 2>&1 && pass "Home PHP syntax" || fail "Home PHP syntax"
for copy in \
	'SiteRadian' \
	'The AI Command Center for WordPress' \
	'Give AI a safer way to work on your site.' \
	'Connect AI assistants to WordPress with scoped access, approvals, audit trails, and rollback.'; do
	has "Home retains: $copy" "$copy"
done
for step in 'AI assistant' 'Scope' 'Approval' 'Safe execution' 'Audit / rollback'; do
	has "workflow retains: $step" "$step"
done

echo "== Cohesive dashboard layout =="
has "Home width remains deliberately capped" '.wpcc-home { max-width:1120px;'
has "Home-only shell header is compact" 'body.toplevel_page_wp-command-center .wpcc-shell__bar'
has "hero has tighter vertical padding" 'padding:20px 28px'
has "hero and status row have deliberate proximity" 'margin:0 0 12px'
has "four status columns use real equal-height tracks" 'grid-template-columns:repeat(4,minmax(0,1fr)); grid-auto-rows:1fr'
has "status cards are internally aligned columns" 'display:flex; flex-direction:column'
has "status support text anchors at the bottom" 'margin-top:auto; padding-top:5px'

echo "== First-run hierarchy =="
has "all three existing steps remain" 'Get started in three steps'
has "desktop setup uses a compact three-step rail" 'grid-template-columns:repeat(3,minmax(0,1fr))'
has "current step carries the strongest treatment" '.wpcc-setup__step.is-active'
has "completed step remains visually quiet" '.wpcc-setup__step.is-done'
has "future step styling remains distinct" '.wpcc-setup__step.is-todo'
has "primary CTA behavior and destination remain unchanged" 'Finish connection setup'
has "primary CTA is compact but prominent" '.wpcc-setup__cta.button-hero'
has "protection notice receives its real state class" "\$wpcc_protected ? 'is-ok' : 'is-warn'"
has "warning state uses the existing semantic amber family" '.wpcc-setup__protection.is-warn'
has "trust disclosure remains collapsed markup" '<details class="wpcc-home__limits wpcc-setup__limits">'
has "sample prompt wraps as one intentional block" '.wpcc-setup__prompt code { display:block'

echo "== Learn remains useful and secondary =="
has "Learn is separated by rhythm rather than another card" 'background:transparent; border:0; border-top:1px solid'
has "wide Learn links use a compact four-column row" 'grid-template-columns:repeat(4,minmax(0,1fr))'
for topic in 'Quick Start' 'Scoped access' 'Understanding approvals' 'Protection & rollback'; do
	has "Learn retains: $topic" "$topic"
done
for destination in "\$links['connect']" "\$links['access']" "\$links['approvals']" "\$links['security']"; do
	has "Learn link stays internal: $destination" "$destination"
done

echo "== Responsive, accessible, and lightweight =="
has "medium setup becomes a compact vertical sequence" '@media (max-width:960px)'
has "mobile operational cards become one column" '.wpcc-home__status,.wpcc-home__learn-links { grid-template-columns:1fr; }'
has "Home link focus remains explicit" '.wpcc-home__stat-value:focus-visible'
has "disclosure focus remains explicit" '.wpcc-home__limits summary:focus-visible'
has "reduced motion remains respected" '@media (prefers-reduced-motion:reduce)'
lacks "no remote font dependency" '@font-face'
lacks "no remote image dependency" 'url(http'
lacks "no third-party UI dependency" 'node_modules'

echo
echo "Home dashboard polish results: $PASS passed, $FAIL failed"
[[ $FAIL -eq 0 ]]
