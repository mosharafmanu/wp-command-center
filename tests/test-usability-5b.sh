#!/usr/bin/env bash
# PROGRAM-5B — Product Usability & Adoption Readiness structural verification.
# Static (lint + rg). Guards the IA/navigation rebuild, honest stale-copy fixes,
# provider catalogue, model explainer, first-run "how it works", and safety-mode UX.
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$DIR/.." && pwd)"

SHELL_F="$ROOT/includes/Admin/AppShell.php"
CATALOG="$ROOT/includes/Ai/Platform/ProviderCatalog.php"
AISETUP="$ROOT/includes/Admin/views/ai-setup.php"
HOME_F="$ROOT/includes/Admin/views/command-home.php"
SETTINGS="$ROOT/includes/Admin/views/settings.php"
CHANGES="$ROOT/includes/Admin/views/change-history.php"
TOKENS="$ROOT/includes/Admin/views/token-capability-manager.php"

P=0; F=0
pass(){ P=$((P+1)); echo "  PASS: $1"; }
fail(){ F=$((F+1)); echo "  FAIL: $1"; }
has(){ if rg -q -e "$2" "$3"; then pass "$1"; else fail "$1"; fi; }
hasnt(){ if rg -q -e "$2" "$3"; then fail "$1"; else pass "$1"; fi; }

echo "== 1. Lint =="
for f in "$SHELL_F" "$CATALOG" "$AISETUP" "$HOME_F" "$SETTINGS" "$CHANGES" "$TOKENS"; do
	if php -l "$f" >/dev/null 2>&1; then pass "lint $(basename "$f")"; else fail "lint $(basename "$f")"; fi
done

echo "== 2. Navigation rebuild — clarity, no regression =="
has "sections carry a plain-language description" "'desc'  => __\(" "$SHELL_F"
has "shell renders the section description" "wpcc-shell__desc" "$SHELL_F"
has "AI client door uses plain 'AI Clients' label" "__\( 'AI Clients'" "$SHELL_F"
# Phase 2B: Runtime retired; the advanced area is now the Advanced hub.
has "Advanced hub present under Settings" "__\( 'Advanced', 'wp-command-center' \)" "$SHELL_F"
has "Connect section slug intact" "CONNECT_SLUG\s*=\s*'wpcc-connect'" "$SHELL_F"
has "Providers (AI Setup) tab still present" "'view' => 'ai-setup'" "$SHELL_F"

echo "== 3. Honest stale-copy fixes (P0) =="
hasnt "Changes no longer claims restore is unreleased" "restore controls arrive in a later release" "$CHANGES"
has "Changes describes working Restore" "Restore button" "$CHANGES"
hasnt "Tokens no longer claims create/revoke is unreleased" "token create/revoke arrives in a later release" "$TOKENS"
has "Tokens describes working create" "Create access tokens" "$TOKENS"

echo "== 4. Provider catalogue — connection-centric (PROGRAM-6R: dialect-classified) =="
has "catalogue lists anthropic" "'anthropic' =>" "$CATALOG"
has "catalogue lists openai" "'openai' =>" "$CATALOG"
has "catalogue lists gemini" "'gemini' =>" "$CATALOG"
has "providers classified by dialect" "'dialect' => Dialect::" "$CATALOG"
has "AI Setup iterates the catalogue" "ProviderCatalog::all\(\)" "$AISETUP"
hasnt "no fake per-provider key field names" "openai_api_key|gemini_api_key" "$AISETUP"

echo "== 5. Model management — per-connection model field =="
has "per-connection model field" "name=\"wpcc_model_custom\"" "$AISETUP"
has "provider default model shown" "default_model" "$AISETUP"
has "honest: AI off until key+feature" "AI stays off until you add a key" "$AISETUP"

echo "== 6. First-run — how it works (AI→approve→record→undo) =="
has "how-it-works strip present" "How WPCC keeps you in control" "$HOME_F"
has "step: AI proposes" "AI proposes" "$HOME_F"
has "step: you approve" "You approve" "$HOME_F"
has "step: you can undo" "You can undo" "$HOME_F"

echo "== 7. Safety mode UX — consequences obvious =="
has "developer mode flagged not-for-client" "NOT FOR CLIENT SITES" "$SETTINGS"
has "developer consequence in plain language" "change or delete things on this site immediately" "$SETTINGS"
has "client mode still recommended" "RECOMMENDED" "$SETTINGS"
has "developer confirm guard retained" "window.confirm" "$SETTINGS"

echo "== 8. STOP-condition guard — no architecture edits in this program =="
hasnt "no schema edit marker" "PROGRAM-5B" "$ROOT/includes/Core/Schema.php"
hasnt "no capability registry edit marker" "PROGRAM-5B" "$ROOT/includes/Operations/CapabilityRegistry.php"

echo
echo "== Summary =="
echo "  $P passed, $F failed"
[ "$F" -eq 0 ]
