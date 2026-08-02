#!/usr/bin/env bash
# PROGRAM-5C — First Value Workflow & Design-Partner Reality structural verification.
# Static (lint + rg). Guards the agent-confusion copy, no-setup first win,
# approval/undo discoverability, and honest after-key guidance.
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$DIR/.." && pwd)"

EXPL="$ROOT/includes/Admin/AgentExplainer.php"
CONNECT="$ROOT/includes/Admin/views/ai-integrations.php"
HOME_F="$ROOT/includes/Admin/views/command-home.php"
AISETUP="$ROOT/includes/Admin/views/ai-setup.php"

P=0; F=0
pass(){ P=$((P+1)); echo "  PASS: $1"; }
fail(){ F=$((F+1)); echo "  FAIL: $1"; }
has(){ if rg -q -e "$2" "$3"; then pass "$1"; else fail "$1"; fi; }
hasnt(){ if rg -q -e "$2" "$3"; then fail "$1"; else pass "$1"; fi; }

echo "== 1. Lint =="
for f in "$EXPL" "$CONNECT" "$HOME_F" "$AISETUP"; do
	if php -l "$f" >/dev/null 2>&1; then pass "lint $(basename "$f")"; else fail "lint $(basename "$f")"; fi
done

echo "== 2. Agent confusion — plain-language explainer (Phase C) =="
has "explainer answers what-is-an-agent" "What is an AI agent" "$EXPL"
has "explainer answers why-do-i-need-one" "Why do I need one" "$EXPL"
has "explainer answers what-does-token-do" "What does the access token do" "$EXPL"
has "explainer answers what-talks-to-what" "What talks to what" "$EXPL"
has "explainer has a jargon-free flow line" "function flow_line" "$EXPL"
has "Connect screen renders the explainer" "AgentExplainer::faq\(\)" "$CONNECT"
has "Connect screen H1 is plain language" "esc_html_e\( 'AI Clients'" "$CONNECT"
has "Connect screen names assistants in plain words" "Connect Claude, Cursor, Codex" "$CONNECT"
hasnt "Connect screen no longer leads with MCP-protocol jargon" "via the MCP protocol. All clients share" "$CONNECT"

echo "== 3. First success — no-setup quick win (Phase D) =="
# V1: the "run a site report" quick win was removed from Home. It competed with
# the one action that actually starts the product (connect an assistant) and
# pointed at Diagnostics, a surface V1 de-emphasises. The report still exists
# at Settings > Diagnostics; it is simply no longer a second front-door CTA.
lacks "no competing quick-win CTA" "no AI or setup needed" "$HOME_F"
lacks "no second primary CTA on Home" "Run a site report" "$HOME_F"
has "Home itself stays read-only" "READ-ONLY" "$HOME_F"

echo "== 4. Approval & undo discoverability (Phase E) =="
has "approvals link in how-it-works" "Approvals →" "$HOME_F"
has "changes/undo link in how-it-works" "Changes →" "$HOME_F"

echo "== 5. Honest after-key guidance (Phase A/D) =="
has "after-key next steps present" "What happens next" "$AISETUP"
has "honest: key alone does not enable AI" "does not turn AI features on by itself" "$AISETUP"
has "after-key points to AI Clients" "AI Clients" "$AISETUP"

echo "== 6. STOP-condition guard — no architecture edits =="
hasnt "no schema edit marker" "PROGRAM-5C" "$ROOT/includes/Core/Schema.php"
hasnt "no capability registry edit marker" "PROGRAM-5C" "$ROOT/includes/Operations/CapabilityRegistry.php"
hasnt "no MCP runtime edit marker" "PROGRAM-5C" "$ROOT/includes/Mcp/McpServerRuntime.php"

echo
echo "== Summary =="
echo "  $P passed, $F failed"
[ "$F" -eq 0 ]
