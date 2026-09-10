#!/usr/bin/env bash
# Gemini CLI manual fallback: preserve shared settings and keep native setup primary.
# No live credential is loaded or retained by this suite.
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

echo "== Gemini native setup remains primary and unchanged =="
MCP_URL="$(wpe 'echo rest_url( WPCommandCenter\Mcp\McpServerRuntime::NAMESPACE . "/mcp" );')"
GEMINI_CMD="$(wpe 'echo WPCommandCenter\Integration\AIClientRegistry::setup_command_for("gemini");')"
EXPECTED_CMD="gemini mcp add 'wp-command-center' '$MCP_URL' \\"
EXPECTED_CMD+=$'\n'
EXPECTED_CMD+="  --transport http \\"
EXPECTED_CMD+=$'\n'
EXPECTED_CMD+="  --scope user \\"
EXPECTED_CMD+=$'\n'
EXPECTED_CMD+="  --header 'Authorization: Bearer \${WPCC_TOKEN}'"
assert_eq "native command contract is byte-for-byte unchanged" "$EXPECTED_CMD" "$GEMINI_CMD"
assert_contains "native command is described as recommended" "$(wpe 'echo implode(" ", WPCommandCenter\Integration\AIClientRegistry::post_setup_notes_for("gemini"));')" "recommended path"
assert_contains "native command explains settings preservation" "$(wpe 'echo implode(" ", WPCommandCenter\Integration\AIClientRegistry::post_setup_notes_for("gemini"));')" "preserves every existing Gemini setting"
assert_contains "manual JSON stays visually advanced" "$(<"$VIEW")" "Manual setup for %s (advanced)"
assert_contains "manual intro keeps native setup recommended" "$(<"$VIEW")" "native gemini mcp add command above is recommended"

echo "== Gemini manual setup contract =="
GEMINI_CFG="$(wpe '$r="WPCommandCenter\\Integration\\AIClientRegistry"; echo $r::render_config($r::generate_config("gemini"));')"
GEMINI_ENTRY="$(wpe 'echo WPCommandCenter\Integration\AIClientRegistry::render_entry_config("gemini");')"
assert_eq "manual fallback receives shared-file treatment" "1" "$(wpe 'echo WPCommandCenter\Integration\AIClientRegistry::config_file_is_shared("gemini") ? 1 : 0;')"
assert_eq "settings path remains correct" "~/.gemini/settings.json" "$(wpe '$c=WPCommandCenter\Integration\AIClientRegistry::get_client("gemini"); echo $c["config_paths"]["macos"];')"
assert_contains "JSON keeps mcpServers root" "$GEMINI_CFG" '"mcpServers"'
assert_contains "JSON keeps url" "$GEMINI_CFG" '"url"'
assert_contains "JSON keeps HTTP type" "$GEMINI_CFG" '"type": "http"'
assert_contains "JSON keeps Authorization header" "$GEMINI_CFG" '"Authorization": "Bearer ${WPCC_TOKEN}"'
assert_contains "entry is keyed for insertion" "$GEMINI_ENTRY" '"wp-command-center":'
assert_not_contains "entry does not replace mcpServers" "$GEMINI_ENTRY" '"mcpServers"'

VIEW_TEXT="$(<"$VIEW")"
assert_contains "manual warning forbids whole-file replacement" "$VIEW_TEXT" "Do not replace the whole settings.json file"
assert_contains "manual warning says to add only WPCC" "$VIEW_TEXT" "Add only the “wp-command-center” entry"
assert_contains "manual warning preserves other servers" "$VIEW_TEXT" "Keep every other server"
assert_contains "manual warning preserves unrelated top-level settings" "$VIEW_TEXT" "every unrelated top-level setting"
assert_contains "manual warning covers absent mcpServers" "$VIEW_TEXT" "If “mcpServers” does not exist"
assert_contains "full JSON is labelled as an empty-file example" "$VIEW_TEXT" "Copy empty-file example"
assert_contains "keyed snippet is labelled as the merge entry" "$VIEW_TEXT" "Entry to merge into mcpServers"
assert_contains "manual disconnect removes only WPCC" "$VIEW_TEXT" "remove only the “wp-command-center” entry"

echo "== Non-secret merge proof =="
MERGE_RESULT="$(python3 - "$GEMINI_ENTRY" <<'PY'
import json
import sys

entry = json.loads("{" + sys.argv[1] + "}")["wp-command-center"]
before = {
    "mcpServers": {"another-server": {"command": "example"}},
    "security": {"folderTrust": True},
    "ui": {"theme": "system"},
    "ide": {"enabled": True},
    "account": {"selected": "existing"},
}
after = json.loads(json.dumps(before))
after["mcpServers"]["wp-command-center"] = entry
round_trip = json.loads(json.dumps(after))
checks = {
    "valid_json": isinstance(round_trip, dict),
    "wpcc_added": round_trip["mcpServers"]["wp-command-center"]["type"] == "http",
    "existing_mcp_preserved": round_trip["mcpServers"]["another-server"] == before["mcpServers"]["another-server"],
    "security_preserved": round_trip["security"] == before["security"],
    "ui_preserved": round_trip["ui"] == before["ui"],
    "ide_preserved": round_trip["ide"] == before["ide"],
    "account_preserved": round_trip["account"] == before["account"],
}
print("|".join(name for name, ok in checks.items() if ok))
PY
)"
for CHECK in valid_json wpcc_added existing_mcp_preserved security_preserved ui_preserved ide_preserved account_preserved; do
	assert_contains "merge: $CHECK" "$MERGE_RESULT" "$CHECK"
done

echo "== Credential truth and retained-evidence safety =="
GEMINI_NOTES="$(wpe 'echo implode(" ", WPCommandCenter\Integration\AIClientRegistry::post_setup_notes_for("gemini"));')"
assert_eq "credential mode remains inline" "inline" "$(wpe 'echo WPCommandCenter\Integration\AIClientRegistry::credential_mode_for("gemini");')"
assert_contains "inline storage is disclosed" "$GEMINI_NOTES" "stores the bearer token inline"
assert_contains "credential guidance names the real settings file" "$GEMINI_NOTES" "~/.gemini/settings.json"
assert_contains "placeholder remains non-secret" "$GEMINI_CFG" '${WPCC_TOKEN}'
if grep -Eq 'wpcc_[A-Za-z0-9]{20,}' "$0"; then
	fail "test source contains a raw WPCC token pattern"
else
	pass "test source contains no raw WPCC token"
fi

echo
echo "Gemini manual merge safety: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
