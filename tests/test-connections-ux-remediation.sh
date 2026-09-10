#!/usr/bin/env bash
# Connections → Assistants beginner-onboarding contract. Read-only.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_ROOT="${WPCC_TEST_WP_PATH:-$(cd "$SCRIPT_DIR/../../../.." && pwd)}"
VIEW="$PLUGIN_DIR/includes/Admin/views/ai-integrations.php"

PASS=0
FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; if [ "$e" = "$a" ]; then pass "$d"; else fail "$d (expected '$e', got '$a')"; fi; }
assert_contains() { local d="$1" h="$2" n="$3"; if [[ "$h" == *"$n"* ]]; then pass "$d"; else fail "$d (missing '$n')"; fi; }
assert_not_contains() { local d="$1" h="$2" n="$3"; if [[ "$h" != *"$n"* ]]; then pass "$d"; else fail "$d (should not contain '$n')"; fi; }
wpe() { wp --path="$WP_ROOT" eval "$1" 2>/dev/null; }

echo "== App-first selector =="
ROSTER="$(wpe '
use WPCommandCenter\Integration\AIClientRegistry;
$out = [];
foreach ( AIClientRegistry::get_clients() as $id => $c ) {
    $out[] = [ $id, $c["name"], $c["family"], $c["surface"], $c["setup_kind"], AIClientRegistry::selector_badge_for( $id )["label"] ?? "" ];
}
echo wp_json_encode( $out );
')"
assert_eq "selector exact order" \
  'chatgpt,codex,claude,claude_code,antigravity,gemini,cursor,continue,vscode,opencode,command_code,muse_code' \
  "$(printf '%s' "$ROSTER" | jq -r 'map(.[0]) | join(",")')"
assert_eq "ChatGPT card names the actual surface" "Codex in ChatGPT Desktop" "$(printf '%s' "$ROSTER" | jq -r '.[] | select(.[0] == "chatgpt") | .[1]')"
assert_eq "Continue names the editor surface" "Continue for VS Code" "$(printf '%s' "$ROSTER" | jq -r '.[] | select(.[0] == "continue") | .[1]')"
assert_eq "Copilot names the consumer surface" "GitHub Copilot in VS Code" "$(printf '%s' "$ROSTER" | jq -r '.[] | select(.[0] == "vscode") | .[1]')"
assert_eq "Muse is separated from mainstream apps" "Other / Experimental" "$(printf '%s' "$ROSTER" | jq -r '.[] | select(.[0] == "muse_code") | .[2]')"
assert_eq "Muse reflects the owner-driven connection test" "Connection tested" "$(printf '%s' "$ROSTER" | jq -r '.[] | select(.[0] == "muse_code") | .[5]')"
assert_eq "Muse remains a standalone CLI client type" "cli" "$(wpe 'echo WPCommandCenter\Integration\AIClientRegistry::get_client("muse_code")["type"];')"
MUSE_DESCRIPTION="$(wpe 'echo WPCommandCenter\Integration\AIClientRegistry::get_client("muse_code")["description"];')"
assert_contains "Muse identity names its own executable" "$MUSE_DESCRIPTION" "Muse Code CLI (muse)"
assert_contains "Muse identity separates the model/API" "$MUSE_DESCRIPTION" "distinct from the Muse Spark model/API"
assert_eq "Other Experimental has a real client and is not empty" "muse_code" "$(wpe 'echo implode(",", array_keys(WPCommandCenter\Integration\AIClientRegistry::get_client_groups()["Other / Experimental"] ?? []));')"
assert_eq "Codex badge reflects the successful owner retest" "Connection tested" "$(printf '%s' "$ROSTER" | jq -r '.[] | select(.[0] == "codex") | .[5]')"

echo "== One recommended action per client =="
assert_eq "Cursor uses official reviewed install link" "install_link" "$(printf '%s' "$ROSTER" | jq -r '.[] | select(.[0] == "cursor") | .[4]')"
CURSOR_LINK="$(wpe 'echo WPCommandCenter\Integration\AIClientRegistry::setup_link_for("cursor", "wpcc_TEST_ONLY");')"
assert_contains "Cursor link uses the local Cursor protocol" "$CURSOR_LINK" "cursor://anysphere.cursor-deeplink/mcp/install"
assert_not_contains "Cursor link is not an HTTP credential URL" "$CURSOR_LINK" "https://"

CONTINUE_ENTRY="$(wpe 'echo WPCommandCenter\Integration\AIClientRegistry::primary_config_for("continue", "wpcc_TEST_ONLY");')"
assert_contains "Continue primary copy is one list entry" "$CONTINUE_ENTRY" "- name: wp-command-center"
assert_contains "Continue primary copy includes the supplied token" "$CONTINUE_ENTRY" "wpcc_TEST_ONLY"
assert_not_contains "Continue primary copy omits a duplicate root heading" "$CONTINUE_ENTRY" "mcpServers:"
assert_contains "Continue entry keeps child indentation" "$CONTINUE_ENTRY" "  - name: wp-command-center"

echo "== Beginner flow and client-specific truth =="
VIEW_TEXT="$(<"$VIEW")"$'\n'"$(<"$PLUGIN_DIR/includes/Admin/views/partials/assistant-connect.php")"$'\n'"$(<"$PLUGIN_DIR/includes/Admin/views/partials/assistant-verify.php")"
for copy in '1. Choose your app' '2. Create access' '3. Connect %s' '4. Test your connection' 'Recommended setup'; do
  assert_contains "view contains $copy" "$VIEW_TEXT" "$copy"
done
CHATGPT_NOTE="$(wpe 'echo WPCommandCenter\Integration\AIClientRegistry::get_client("chatgpt")["surface_note"];')"
assert_contains "ChatGPT note directs Codex mode" "$CHATGPT_NOTE" "Use Codex mode in the ChatGPT desktop app"
assert_contains "ChatGPT note excludes regular chats" "$CHATGPT_NOTE" "Regular ChatGPT chats do not use this local MCP connection"
SELECTOR_BLOCK="$(sed -n '/id="wpcc-choose-app"/,/<!-- Access tokens -->/p' "$VIEW")"
assert_not_contains "selected-client note is absent from Step 1" "$SELECTOR_BLOCK" "wpcc-selected-client-note"
assert_contains "selected-client note uses a subtle contextual callout" "$VIEW_TEXT" "wpcc-selected-client-note"
assert_contains "Step 2 notes are limited to clients with pre-access prerequisites" "$VIEW_TEXT" "in_array( \$wpcc_selected_client, [ 'chatgpt', 'codex' ], true )"
assert_contains "Step 2 lead has an intentional spacing hook" "$VIEW_TEXT" "wpcc-create-access__lead"
assert_contains "Step 2 form has an intentional spacing hook" "$VIEW_TEXT" "wpcc-create-access__form"
assert_contains "Step 2 token state has an intentional spacing hook" "$VIEW_TEXT" "wpcc-create-access__state"
assert_contains "Step 2 form uses the WPCC 16px spacing token" "$VIEW_TEXT" "margin-top:var(--wpcc-space-4,16px)"
assert_contains "Step 2 token state uses the WPCC 12px spacing token" "$VIEW_TEXT" "margin:var(--wpcc-space-3,12px) 0 0"
assert_contains "Codex uses interactive approvals" "$VIEW_TEXT" "codex --ask-for-approval on-request"
assert_contains "Codex primary Step 1 is concise" "$VIEW_TEXT" "Make the token available in Terminal"
assert_contains "Codex primary Step 3 is concise" "$VIEW_TEXT" "Start Codex"
assert_contains "Codex launch has its own copy action" "$VIEW_TEXT" "Copy start command"
assert_contains "Codex technical explanation is collapsed" "$VIEW_TEXT" "Why this step?"
assert_contains "Codex permission rationale is collapsed" "$VIEW_TEXT" "Why this command?"
assert_contains "Codex preserves the no-bypass warning" "$VIEW_TEXT" "Do not use bypass flags or an approval policy of never"
assert_contains "selector links mark deliberate continuation" "$VIEW_TEXT" "'wpcc_next' => 'access'"
assert_contains "Step 2 heading carries the selected app context" "$VIEW_TEXT" "2. Create access for %s"
assert_not_contains "selected app context is not duplicated in a callout" "$VIEW_TEXT" "wpcc-access-context"
assert_not_contains "selector has no duplicate next CTA" "$VIEW_TEXT" "Next: Create access token"
assert_not_contains "removed next control has no leftover markup or handler" "$VIEW_TEXT" "wpcc-selection-next"
assert_contains "selection flow clears its one-shot marker" "$VIEW_TEXT" "searchParams.delete('wpcc_next')"
assert_contains "selection flow focuses the next heading" "$VIEW_TEXT" "accessTitle.focus({ preventScroll: true })"
assert_contains "selection flow respects reduced motion" "$VIEW_TEXT" "prefers-reduced-motion: reduce"
assert_not_contains "selection flow never submits token form" "$(sed -n "/A deliberate app selection/,/Token creation dialog/p" "$VIEW")" ".submit("
assert_contains "families use a responsive outer grid" "$VIEW_TEXT" "wpcc-ai-family-grid"
assert_contains "family layout queries the actual admin content width" "$VIEW_TEXT" ".wpcc-ai-family-layout { container-type:inline-size; }"
assert_contains "wide layout fits three small families across" "$VIEW_TEXT" "grid-template-columns:repeat(3,minmax(0,1fr))"
assert_contains "medium layout fits two family groups across" "$VIEW_TEXT" "grid-template-columns:repeat(2,minmax(0,1fr))"
assert_contains "small families use exactly two card columns" "$VIEW_TEXT" ".wpcc-ai-picks { display:grid;grid-template-columns:repeat(2,minmax(0,1fr))"
assert_contains "last small family spans the medium row without a grid hole" "$VIEW_TEXT" "wpcc-ai-family--compact-last"
assert_contains "editor family spans the selector width" "$VIEW_TEXT" "wpcc-ai-family--wide"
assert_contains "editor family detection matches registry identity" "$VIEW_TEXT" "'Editors & coding assistants' === \$wpcc_family"
assert_contains "compact cards override WordPress no-wrap styling" "$VIEW_TEXT" "white-space:normal !important;overflow-wrap:anywhere"
assert_contains "cards share consistent row heights" "$VIEW_TEXT" "grid-auto-rows:1fr;align-items:stretch"
assert_contains "card badges align to the bottom" "$VIEW_TEXT" "margin-top:auto;font-size:10px"
assert_contains "selected cards keep comfortable inset spacing" "$VIEW_TEXT" "padding-left:19px !important"
assert_contains "narrow layout collapses cards to one column" "$VIEW_TEXT" "@container (max-width: 559px)"
assert_contains "Other Experimental remains a disclosure" "$VIEW_TEXT" "<details class=\"wpcc-ai-family wpcc-ai-family--other\""
assert_contains "Cursor opens its install review" "$VIEW_TEXT" "Open Cursor’s install review"
assert_contains "Cursor stores no credential-bearing href" "$VIEW_TEXT" "data-mcp-url"
CURSOR_BUTTON="$(grep 'id="wpcc-cursor-install"' "$VIEW" || true)"
assert_not_contains "Cursor button has no static credential URI" "$CURSOR_BUTTON" 'href="cursor:'
assert_contains "Continue names its extension location" "$VIEW_TEXT" "In the Continue sidebar in VS Code"
assert_contains "Continue names its exact config path" "$VIEW_TEXT" "~/.continue/config.yaml"
assert_contains "Continue preserves existing config" "$VIEW_TEXT" "Keep the existing file"
assert_contains "Claude primary flow copies one entry" "$VIEW_TEXT" "Copy WPCC entry"
assert_contains "Claude primary flow preserves existing servers" "$VIEW_TEXT" "keep every other entry"
assert_contains "VS Code primary flow names the secure prompt" "$VIEW_TEXT" "paste the WPCC token when VS Code asks"
assert_contains "VS Code restores the token after copying setup" "$VIEW_TEXT" "Copy token for VS Code"
assert_contains "VS Code explains why the final copy is required" "$VIEW_TEXT" "copying the setup replaced your clipboard"
VSCODE_NOTES="$(wpe 'echo implode("\n", WPCommandCenter\Integration\AIClientRegistry::post_setup_notes_for("vscode"));')"
assert_contains "VS Code DCR recovery is explicit but secondary" "$VSCODE_NOTES" "If VS Code opens an OAuth or client-ID screen, cancel it"
assert_contains "VS Code DCR recovery identifies invalid credential" "$VSCODE_NOTES" "saved WPCC token was missing or invalid"
assert_contains "VS Code merge keeps both top-level structures" "$VIEW_TEXT" "preserve every server and input"
assert_contains "OpenCode and every client gets real verification" "$VIEW_TEXT" "Run system_info once"
assert_contains "Advanced manual setup is a disclosure" "$VIEW_TEXT" "<details class=\"wpcc-ai-advanced\" id=\"wpcc-config-panel\">"
ADVANCED_LINE="$(grep 'id="wpcc-config-panel"' "$VIEW" || true)"
assert_not_contains "Advanced manual setup starts collapsed" "$ADVANCED_LINE" " open"
assert_not_contains "OpenRouter is not an MCP client" "$ROSTER" "OpenRouter"

echo "== M2 tool classification is narrow and governance-neutral =="
RUNTIME="$(<"$PLUGIN_DIR/includes/Mcp/McpServerRuntime.php")"
assert_contains "runtime publishes MCP readOnlyHint" "$RUNTIME" "'readOnlyHint'"
assert_contains "only diagnostic worst-case tools are read-only" "$RUNTIME" "SecurityModeManager::RISK_DIAGNOSTIC ==="
assert_contains "mutating/mixed tools remain conservative" "$RUNTIME" "'destructiveHint'"
assert_not_contains "runtime does not claim every tool is closed-world" "$RUNTIME" "'openWorldHint'"
assert_contains "runtime documents unchanged WPCC enforcement" "$RUNTIME" "WPCC scope/capability/approval enforcement"

echo "== Secret-safety guardrails =="
assert_contains "Cursor URI is created only on click" "$VIEW_TEXT" "cursorInstall.addEventListener('click'"
assert_contains "Cursor payload is encoded locally" "$VIEW_TEXT" "window.btoa(config)"
assert_contains "terminal history warning remains" "$VIEW_TEXT" "shared history, screenshots, chats and logs"
assert_contains "one-time reveal remains explicit" "$VIEW_TEXT" "it will not be shown again"

echo "== Summary =="
echo "  $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
