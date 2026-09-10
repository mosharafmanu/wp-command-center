#!/usr/bin/env bash
# Command Code 1.51.0 setup UX: native registration, inline-secret safety,
# client-side verification, and the verified ~/.commandcode/mcp.json fallback.
# No local credential file is sourced and no WPCC token is created by this suite.
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
reg() { wpe "echo WPCommandCenter\\Integration\\AIClientRegistry::$1;"; }

echo "== Command Code 1.51.0 native contract =="
MCP_URL="$(wpe 'echo rest_url( WPCommandCenter\Mcp\McpServerRuntime::NAMESPACE . "/mcp" );')"
CC_CMD="$(reg "setup_command_for('command_code')")"
EXPECTED_CMD="cmd mcp add \\"
EXPECTED_CMD+=$'\n'
EXPECTED_CMD+="  --transport http \\"
EXPECTED_CMD+=$'\n'
EXPECTED_CMD+="  --scope user \\"
EXPECTED_CMD+=$'\n'
EXPECTED_CMD+="  --header 'Authorization: Bearer \${WPCC_TOKEN}' \\"
EXPECTED_CMD+=$'\n'
EXPECTED_CMD+="  'wp-command-center' '$MCP_URL'"
assert_eq "native setup remains primary and byte-for-byte correct" "$EXPECTED_CMD" "$CC_CMD"
assert_eq "direct HTTP transport unchanged" "http" "$(reg "transport_for('command_code')")"
assert_eq "inline credential contract unchanged" "inline" "$(reg "credential_mode_for('command_code')")"
assert_contains "header uses Header: value form" "$CC_CMD" "Authorization: Bearer"
assert_not_contains "header does not use KEY=VALUE form" "$CC_CMD" "Authorization=Bearer"

SYNTHETIC_TOKEN="SYNTHETIC_COMMAND_CODE_ONE_TIME_TOKEN"
READY_CMD="$(reg "setup_command_for('command_code', '$SYNTHETIC_TOKEN')")"
assert_contains "one-time token produces complete command" "$READY_CMD" "$SYNTHETIC_TOKEN"
assert_not_contains "complete command has no placeholder" "$READY_CMD" '${WPCC_TOKEN}'

echo "== Verified manual file contract =="
CC_CFG="$(wpe '$r="WPCommandCenter\\Integration\\AIClientRegistry"; echo $r::render_config($r::generate_config("command_code"));')"
CC_ENTRY="$(reg "render_entry_config('command_code')")"
assert_eq "verified macOS path" "~/.commandcode/mcp.json" "$(wpe '$c=WPCommandCenter\Integration\AIClientRegistry::get_client("command_code"); echo $c["config_paths"]["macos"];')"
assert_eq "verified Linux path" "~/.commandcode/mcp.json" "$(wpe '$c=WPCommandCenter\Integration\AIClientRegistry::get_client("command_code"); echo $c["config_paths"]["linux"];')"
assert_eq "manual file receives shared-file merge treatment" "1" "$(reg "config_file_is_shared('command_code') ? 1 : 0")"
assert_contains "manual root is mcpServers" "$CC_CFG" '"mcpServers"'
assert_contains "manual entry uses transport" "$CC_CFG" '"transport": "http"'
assert_contains "manual entry is enabled" "$CC_CFG" '"enabled": true'
assert_contains "manual entry carries URL" "$CC_CFG" '"url"'
assert_contains "manual entry carries Authorization" "$CC_CFG" '"Authorization": "Bearer ${WPCC_TOKEN}"'
assert_not_contains "obsolete type key removed" "$CC_CFG" '"type": "http"'
assert_contains "entry fragment is keyed" "$CC_ENTRY" '"wp-command-center":'
assert_not_contains "entry fragment does not replace mcpServers" "$CC_ENTRY" '"mcpServers"'

MERGE_RESULT="$(python3 - "$CC_ENTRY" <<'PY'
import json
import sys

entry = json.loads("{" + sys.argv[1] + "}")["wp-command-center"]
before = {
    "mcpServers": {"another-server": {"transport": "http", "url": "http://example.invalid/mcp"}},
    "security": {"trust": "existing"},
    "ui": {"theme": "existing"},
}
after = json.loads(json.dumps(before))
after["mcpServers"]["wp-command-center"] = entry
round_trip = json.loads(json.dumps(after))
checks = {
    "valid_json": isinstance(round_trip, dict),
    "wpcc_added": round_trip["mcpServers"]["wp-command-center"]["transport"] == "http",
    "existing_mcp_preserved": round_trip["mcpServers"]["another-server"] == before["mcpServers"]["another-server"],
    "security_preserved": round_trip["security"] == before["security"],
    "ui_preserved": round_trip["ui"] == before["ui"],
}
print("|".join(name for name, ok in checks.items() if ok))
PY
)"
for CHECK in valid_json wpcc_added existing_mcp_preserved security_preserved ui_preserved; do
	assert_contains "manual merge: $CHECK" "$MERGE_RESULT" "$CHECK"
done

echo "== Guided security and verification copy =="
VIEW_TEXT="$(<"$VIEW")"$'\n'"$(<"$PLUGIN_DIR/includes/Admin/views/partials/assistant-connect.php")"$'\n'"$(<"$PLUGIN_DIR/includes/Admin/views/partials/assistant-verify.php")"
CLIENT_NOTES="$(wpe 'echo implode(" ", WPCommandCenter\Integration\AIClientRegistry::post_setup_notes_for("command_code"));')"
for NEEDLE in \
	'The setup command contains your access token' \
	'Open a private terminal' \
	'shared history' \
	'screenshots, chats and logs' \
	'Run /mcp' \
	'Run system_info once' \
	'system_info returns details from this WordPress site'; do
	assert_contains "guided copy: $NEEDLE" "$VIEW_TEXT" "$NEEDLE"
done
assert_contains "Command Code OAuth false-positive remains explained" "$CLIENT_NOTES" "There is no OAuth sign-in to complete"
assert_contains "Command Code note confirms token header is authoritative" "$CLIENT_NOTES" "access token in the header above is the whole of it"
assert_contains "reload explains one-time secret loss" "$VIEW_TEXT" "WPCC cannot reconstruct an existing token"
assert_contains "reload offers saved token or a new token" "$VIEW_TEXT" "Paste your saved access token"
assert_contains "reload command uses the shared real-token gate" "$VIEW_TEXT" "data-wpcc-requires-token-control"
assert_contains "reload copy control starts disabled" "$VIEW_TEXT" "disabled aria-disabled=\"true\""
assert_contains "browser fill unlocks controls only when ready" "$VIEW_TEXT" "control.disabled = !ready"
assert_contains "browser fill reveals the command disclosure only when ready" "$VIEW_TEXT" "preview.hidden = !ready"

echo "== Advanced/manual and browser-test truth =="
assert_contains "manual setup names native path as recommended" "$VIEW_TEXT" "native cmd mcp add command above is recommended"
assert_contains "manual setup names verified file" "$VIEW_TEXT" "Empty-file example for ~/.commandcode/mcp.json"
assert_contains "manual setup forbids replacement" "$VIEW_TEXT" "If this file already exists, do not replace it"
assert_contains "manual setup preserves other servers" "$VIEW_TEXT" "Add just this entry inside the “mcpServers” braces you already have"
assert_contains "manual disconnect removes only WPCC" "$VIEW_TEXT" "remove only the “wp-command-center” entry"
assert_not_contains "old unknown-file guidance removed from Command Code metadata" "$(wpe '$c=WPCommandCenter\Integration\AIClientRegistry::get_client("command_code"); echo implode(" ",$c["config_paths"]);')" "no file to edit"
assert_contains "browser test disclaims client loading" "$VIEW_TEXT" "This does not test whether your assistant loaded the server"
assert_contains "browser result remains scoped to WPCC authentication" "$VIEW_TEXT" "WPCC can authenticate this token. Server checks passed; verify the connection inside your client."

echo "== Real no-secret reload rendering =="
RELOAD_STATE="$(wpe '
wp_set_current_user(1);
$_GET=["client"=>"command_code","tab"=>"configuration"];
$_POST=[]; $_REQUEST=[];
ob_start(); require WPCC_PLUGIN_DIR . "includes/Admin/views/ai-integrations.php"; $html=ob_get_clean();
$dom=new DOMDocument(); @$dom->loadHTML("<?xml encoding=\"utf-8\" ?>".$html); $xp=new DOMXPath($dom);
$pre=$xp->query("//*[@id=\"wpcc-setup-command\"]")->item(0);
$preview=$xp->query("//*[@id=\"wpcc-setup-command-preview\"]")->item(0);
$copy=$xp->query("//*[@id=\"wpcc-setup-command-copy\"]")->item(0);
$wait=$xp->query("//*[@data-wpcc-token-needed and not(@hidden)]")->item(0);
if(!$pre){echo "NO_USABLE_TOKEN_PANEL";}else{
 echo implode("|",[
  $preview&&$preview->hasAttribute("hidden")?"preview_hidden":"preview_visible",
  str_contains($pre->textContent,"\${WPCC_TOKEN}")?"command_placeholder":"command_not_placeholder",
  $copy&&$copy->hasAttribute("disabled")?"copy_disabled":"copy_ready",
  $wait&&!$wait->hasAttribute("hidden")?"waiting_visible":"waiting_hidden",
  !str_contains($html,"id=\"wpcc-new-token\"")?"no_token_reveal":"token_revealed"
 ]);
}')"
if [ "$RELOAD_STATE" = "NO_USABLE_TOKEN_PANEL" ]; then
	pass "no-secret reload correctly withholds setup when no usable token exists"
else
	for STATE in preview_hidden command_placeholder copy_disabled waiting_visible no_token_reveal; do
		assert_contains "reload state: $STATE" "$RELOAD_STATE" "$STATE"
	done
fi

if grep -Eq 'wpcc_[A-Za-z0-9]{20,}' "$0" "$VIEW" "$PLUGIN_DIR/includes/Integration/CommandCodeIntegration.php"; then
	fail "changed Command Code UX sources contain a raw WPCC token pattern"
else
	pass "changed Command Code UX sources retain no raw WPCC token"
fi

echo
echo "Command Code setup UX: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
