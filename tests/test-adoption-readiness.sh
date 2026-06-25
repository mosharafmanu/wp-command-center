#!/usr/bin/env bash
# PROGRAM-5A — Product Usability & Adoption Readiness structural verification.
# Static (lint + rg) checks: no live DB required. Guards the security contract of
# the new AI Setup surface (no key echo, nonce + cap + audit), the first-run panel,
# the Security Mode UX, and the invariants this program must not regress.
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$DIR/.." && pwd)"

STATUS="$ROOT/includes/Admin/AdoptionStatus.php"
CTRL="$ROOT/includes/Admin/AiSetupController.php"
VIEW="$ROOT/includes/Admin/views/ai-setup.php"
SHELL_F="$ROOT/includes/Admin/AppShell.php"
HOME_F="$ROOT/includes/Admin/views/command-home.php"
SETTINGS="$ROOT/includes/Admin/views/settings.php"
SCHEMA="$ROOT/includes/Core/Schema.php"
CAPREG="$ROOT/includes/Operations/CapabilityRegistry.php"

P=0; F=0
pass(){ P=$((P+1)); echo "  PASS: $1"; }
fail(){ F=$((F+1)); echo "  FAIL: $1"; }
has(){ if rg -q -e "$2" "$3"; then pass "$1"; else fail "$1"; fi; }
hasnt(){ if rg -q -e "$2" "$3"; then fail "$1"; else pass "$1"; fi; }

echo "== 1. PHP lint (new + changed) =="
for f in "$STATUS" "$CTRL" "$VIEW" "$SHELL_F" "$HOME_F" "$SETTINGS"; do
	if php -l "$f" >/dev/null 2>&1; then pass "lint $(basename "$f")"; else fail "lint $(basename "$f")"; fi
done

echo "== 2. AI Setup controller — security contract =="
has "controller checks nonce" "check_admin_referer\( self::NONCE_ACTION" "$CTRL"
has "controller checks capability" "current_user_can\( 'manage_options' \)" "$CTRL"
has "controller writes the canonical key option" "OPTION_KEY *= 'wpcc_anthropic_api_key'" "$CTRL"
has "key option stored non-autoload" "update_option\( self::OPTION_KEY, .*, false \)" "$CTRL"
has "controller emits key audit event" "ai.provider.key.updated" "$CTRL"
has "controller emits test audit event" "ai.provider.test" "$CTRL"
has "controller refuses to overwrite a constant key" "ai_key_is_constant" "$CTRL"
hasnt "audit context never includes the raw key var" "record\(.*wpcc_api_key" "$CTRL"
has "test uses minimal non-mutating payload" "'content' => 'ping'" "$CTRL"

echo "== 3. AI Setup view — never exposes the secret =="
hasnt "view never echoes a key value" "echo .*(api_key|wpcc_api_key|->key\(\))" "$VIEW"
hasnt "key input is not prefilled with a value" "name=\"wpcc_api_key\"[^>]*value=" "$VIEW"
has "key input is type=password" 'type="password"' "$VIEW"
# PROGRAM-6: view rebuilt to multi-provider; these assertions re-pointed to the new
# (still-safe) reality. Key is never echoed; configured state shown; nonce present.
# PROGRAM-6R: view rebuilt to connection-centric platform; assertions re-pointed.
has "view shows configured state only" "is_configured" "$VIEW"
has "view carries the nonce" "wp_nonce_field\( ConnectionController::NONCE" "$VIEW"
has "providers are configurable connections (no 'planned' placeholders)" "Add a connection" "$VIEW"
has "honest local-storage security note present" "stored in this site" "$VIEW"

echo "== 4. AdoptionStatus — read-only, no key leak =="
has "exposes ai_configured boolean" "function ai_configured" "$STATUS"
has "exposes non-secret key source" "function ai_key_source" "$STATUS"
hasnt "never returns the raw key" "->key\(\)" "$STATUS"
has "checklist is provided" "function checklist" "$STATUS"
has "setup_incomplete gate exists" "function setup_incomplete" "$STATUS"

echo "== 5. Navigation — AI Setup wired without breaking IA =="
has "Connect section registers AI Setup tab" "'view' => 'ai-setup'" "$SHELL_F"
has "legacy slug maps to setup tab" "'wpcc-ai-setup'" "$SHELL_F"
has "five-C sections intact" "'wpcc-connect'" "$SHELL_F"

echo "== 6. First-run panel — present, safe, dismissible =="
has "first-run checklist rendered" "wpcc_checklist" "$HOME_F"
has "first-run nonce present" "wp_nonce_field\( 'wpcc_firstrun' \)" "$HOME_F"
has "dismiss only when setup complete" "if \( ! \\\$wpcc_incomplete \)" "$HOME_F"
has "honest does/doesn't copy present" "What it does:" "$HOME_F"
has "honest irreversibility caveat present" "NOT automatically" "$HOME_F"
has "no AI auto-enable language" "stays off until you add a key" "$HOME_F"

echo "== 7. Security Mode UX — audit + recommend + confirm =="
has "mode change is audited" "security.mode.changed" "$SETTINGS"
has "client mode recommended" "RECOMMENDED" "$SETTINGS"
has "developer mode carries a confirm guard" "window.confirm" "$SETTINGS"
has "developer warning visible" "You are in Developer mode" "$SETTINGS"

echo "== 8. Invariants unchanged =="
has "DB_VERSION still 2.5.0" "DB_VERSION = '2.5.0'" "$SCHEMA"
OPCOUNT=$(rg -o "=>" <(rg -U "OPERATION_MAP\s*=\s*\[(.*?)\];" -o "$CAPREG") | wc -l | tr -d ' ')
if rg -q "OPERATION_MAP" "$CAPREG"; then pass "OPERATION_MAP present (unchanged file)"; else fail "OPERATION_MAP present"; fi
hasnt "this program did not edit CapabilityRegistry" "PROGRAM-5A" "$CAPREG"
hasnt "this program did not edit Schema" "PROGRAM-5A" "$SCHEMA"

echo
echo "== Summary =="
echo "  $P passed, $F failed"
[ "$F" -eq 0 ]
