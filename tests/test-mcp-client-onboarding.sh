#!/usr/bin/env bash
#
# MCP client onboarding contract — REAL_TEST_FINDINGS.md remediation.
#
# These assertions exist because a generated configuration that LOOKS right is
# indistinguishable, from the admin screen, from one that cannot work. Every check here
# corresponds to a failure observed against a real client, not to a style preference:
#
#   Finding #1  Codex/ChatGPT Desktop were handed `bearer_token = "${WPCC_TOKEN}"`.
#               TOML has no interpolation, so the literal characters were sent as the
#               credential and a healthy server answered 401. The correct key is
#               `bearer_token_env_var`, which is also the only form `codex mcp add`
#               offers (`--bearer-token-env-var`, verified against codex-cli 0.146.0).
#
# The suite deliberately asserts on the ABSENCE of the broken forms too. A regression
# here is silent at generation time and only shows up as an authentication error in a
# third-party application, which is the most expensive place to discover it.
#
# No real credential appears in this file. Where a token is needed the shared
# placeholder is used, so nothing here is a secret even though the file is committed.
set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
if [[ -n "${WPCC_ONBOARDING_TOKEN_OVERRIDE:-}" ]]; then
	WPCC_TOKEN="$WPCC_ONBOARDING_TOKEN_OVERRIDE"
	WP_ROOT="${WPCC_TEST_WP_PATH:-$(cd "$SCRIPT_DIR/../../../.." && pwd)}"
	WPCC_BASE="$(wp --path="$WP_ROOT" eval 'echo untrailingslashit( rest_url( WPCommandCenter\Mcp\McpServerRuntime::NAMESPACE ) );' 2>/dev/null)"
else
	# Legacy local runner fallback. Release/certification runs should supply the short-lived
	# override above so this retained environment file is neither read nor depended upon.
	source "$PLUGIN_DIR/wpcc-env.sh"
fi

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; if [ "$e" = "$a" ]; then pass "$d"; else fail "$d (expected '$e', got '$a')"; fi; }
assert_contains() { local d="$1" h="$2" n="$3"; if [[ "$h" == *"$n"* ]]; then pass "$d"; else fail "$d (missing '$n')"; fi; }
assert_not_contains() { local d="$1" h="$2" n="$3"; if [[ "$h" != *"$n"* ]]; then pass "$d"; else fail "$d (should not contain '$n')"; fi; }

wpe() { wp --path="$WP_ROOT" eval "$1" 2>/dev/null; }

# Registry facts, fetched once each rather than per assertion.
reg() { wpe "echo WPCommandCenter\\Integration\\AIClientRegistry::$1;"; }
cfg() { wpe "\$r='WPCommandCenter\\Integration\\AIClientRegistry'; echo \$r::render_config(\$r::generate_config('$1'));"; }

echo "MCP client onboarding contract — $(date)"
echo ""

# ===================================================================
echo "== 1. Codex CLI — credential shape (Finding #1) =="

CODEX_CFG="$(cfg codex)"
assert_contains "codex: TOML table header"        "$CODEX_CFG" "[mcp_servers.wp-command-center]"
assert_contains "codex: names the env var"        "$CODEX_CFG" 'bearer_token_env_var = "WPCC_TOKEN"'
assert_contains "codex: persistent fallback is server-specific and annotation-aware" "$CODEX_CFG" 'default_tools_approval_mode = "writes"'

# The two regressions this finding was actually about.
assert_not_contains "codex: no interpolated bearer_token" "$CODEX_CFG" 'bearer_token = "${WPCC_TOKEN}"'
assert_not_contains "codex: no token placeholder at all"  "$CODEX_CFG" '${WPCC_TOKEN}'

# `mcpServers` is the JSON clients' key. Codex reads TOML and would ignore it entirely.
assert_not_contains "codex: not emitting JSON shape"      "$CODEX_CFG" 'mcpServers'

assert_eq "codex: transport is direct HTTP"       "http"    "$(reg "transport_for('codex')")"
assert_eq "codex: credential mode is env_var"     "env_var" "$(reg "credential_mode_for('codex')")"
assert_eq "codex: env var name"                   "WPCC_TOKEN" "$(reg "credential_env_var_for('codex')")"

echo ""
echo "== 2. Codex CLI — native one-command setup =="

CODEX_CMD="$(reg "setup_command_for('codex')")"
assert_contains "codex: uses codex mcp add"       "$CODEX_CMD" "codex mcp add"
assert_contains "codex: passes --url"             "$CODEX_CMD" "--url"
assert_contains "codex: passes the env var flag"  "$CODEX_CMD" "--bearer-token-env-var"
assert_contains "codex: points at this site's MCP endpoint" "$CODEX_CMD" "wp-command-center/v1/mcp"

# The command is displayed, copied and pasted into shared terminals. It must never
# carry the secret — only the NAME of the variable holding it.
assert_not_contains "codex: setup command carries no token" "$CODEX_CMD" "wpcc_"

# There is no --bearer-token flag in codex-cli; emitting one would be inventing an API.
assert_not_contains "codex: no invented literal-token flag" "$CODEX_CMD" "--bearer-token "

echo ""
echo "== 3. Credential publication commands =="

CRED_MAC="$(wpe "\$c=WPCommandCenter\\Integration\\AIClientRegistry::credential_commands_for('codex'); echo \$c['macos'] ?? '';")"
CRED_LIN="$(wpe "\$c=WPCommandCenter\\Integration\\AIClientRegistry::credential_commands_for('codex'); echo \$c['linux'] ?? '';")"
CRED_WIN="$(wpe "\$c=WPCommandCenter\\Integration\\AIClientRegistry::credential_commands_for('codex'); echo \$c['windows'] ?? '';")"

# Codex inherits shell exports; Desktop uses the GUI session environment.
# Shared config must not collapse these independently correct bootstrap commands.
assert_contains "Codex macOS: uses shell export" "$CRED_MAC" "export WPCC_TOKEN="
assert_not_contains "Codex macOS: no launchctl" "$CRED_MAC" "launchctl"
DESKTOP_MAC="$(wpe "\$c=WPCommandCenter\\Integration\\AIClientRegistry::credential_commands_for('chatgpt'); echo \$c['macos'];")"
assert_contains "Desktop macOS: launchctl remains" "$DESKTOP_MAC" "launchctl setenv WPCC_TOKEN"
assert_contains "linux: uses export"              "$CRED_LIN" "export WPCC_TOKEN="
assert_contains "windows: uses setx"              "$CRED_WIN" "setx WPCC_TOKEN"

# Unfilled, these carry the shared placeholder so the browser-side fill completes them.
assert_contains "macOS: carries the fillable placeholder" "$CRED_MAC" '${WPCC_TOKEN}'

echo ""
echo "== 4. Codex in ChatGPT Desktop shares the Codex configuration =="

CHATGPT_CFG="$(cfg chatgpt)"
assert_eq "chatgpt: identical config to Codex"    "$CODEX_CFG" "$CHATGPT_CFG"
assert_eq "chatgpt: identical setup command"      "$CODEX_CMD" "$(reg "setup_command_for('chatgpt')")"
assert_eq "chatgpt: credential mode is env_var"   "env_var" "$(reg "credential_mode_for('chatgpt')")"
assert_eq "chatgpt: transport is direct HTTP"     "http"    "$(reg "transport_for('chatgpt')")"

echo ""
echo "== 5. Client surfaces are named as applications (Finding #1.3, #1.4) =="

assert_eq "chatgpt: names the working Codex surface"  "Codex in ChatGPT Desktop" "$(wpe "\$c=WPCommandCenter\\Integration\\AIClientRegistry::get_client('chatgpt'); echo \$c['name'];")"
assert_eq "codex: named as the CLI"          "Codex CLI"       "$(wpe "\$c=WPCommandCenter\\Integration\\AIClientRegistry::get_client('codex'); echo \$c['name'];")"

# Both surfaces must say they share one file, or a user who set up one will set up the
# other and wonder which of the two entries is live.
assert_contains "chatgpt: explains the shared surface" "$(wpe "\$c=WPCommandCenter\\Integration\\AIClientRegistry::get_client('chatgpt'); echo \$c['surface_note'] ?? '';")" "Codex"
assert_contains "codex: explains the shared file"      "$(wpe "\$c=WPCommandCenter\\Integration\\AIClientRegistry::get_client('codex'); echo \$c['surface_note'] ?? '';")" "config.toml"

echo ""
echo "== 6. Setup screen wording and safety rails =="

VIEW="$PLUGIN_DIR/includes/Admin/views/ai-integrations.php"
CONNECT_VIEW="$PLUGIN_DIR/includes/Admin/views/partials/assistant-connect.php"
VIEW_TEXT="$(<"$VIEW")"$'\n'"$(<"$CONNECT_VIEW")"

# The chooser asks which APPLICATION, because "ChatGPT" appears on it twice.
#
# Scoped to translatable STRINGS rather than raw file content: the old wording is
# quoted in the comment explaining why it changed, and a check that cannot tell a
# rendered label from an explanation of itself would force that history to be deleted.
VIEW_STRINGS="$(grep -oE "esc_html_e\( '[^']+'" "$VIEW" || true)"
assert_contains "view: asks for the app"                 "$VIEW_STRINGS" "Choose your app"
assert_not_contains "view: no longer labels it 'assistant'" "$VIEW_STRINGS" "Choose your assistant"
CHATGPT_SURFACE="$(wpe '$c=WPCommandCenter\Integration\AIClientRegistry::get_client("chatgpt"); echo $c["surface_note"] ?? "";')"
assert_contains "view: identifies Codex inside ChatGPT"  "$CHATGPT_SURFACE" "Use Codex mode in the ChatGPT desktop app"
assert_contains "view: excludes normal ChatGPT chats"    "$CHATGPT_SURFACE" "Regular ChatGPT chats do not use this local MCP connection"

# Secret handling and the actual next actions remain explicit in the shared renderer.
assert_contains "view: keeps the one-time token private" "$VIEW_TEXT" "stores it securely"
assert_contains "view: names the restart requirement"   "$VIEW_TEXT" "Fully quit and reopen"
assert_contains "view: offers a copyable setup command" "$VIEW_TEXT" "Copy setup command"

echo ""
echo "== 7. Google surfaces are distinct clients (Finding #3) =="

# Gemini CLI and Antigravity are NOT the same client. They read different files with
# different field names, so a single "Gemini" card hands half its users a configuration
# their client ignores in silence. Both shapes were confirmed against working local
# installations before these assertions were written.
GEMINI_CFG="$(cfg gemini)"
ANTIG_CFG="$(cfg antigravity)"

assert_eq "gemini: named as the CLI surface" "Gemini CLI" "$(wpe "\$c=WPCommandCenter\\Integration\\AIClientRegistry::get_client('gemini'); echo \$c['name'];")"
assert_eq "antigravity: registered as its own client" "Antigravity CLI" "$(wpe "\$c=WPCommandCenter\\Integration\\AIClientRegistry::get_client('antigravity'); echo \$c['name'] ?? '';")"

# The shape `gemini mcp add` itself writes, not the legacy `httpUrl` alias.
assert_contains "gemini: uses url + type (what the official command writes)" "$GEMINI_CFG" '"type": "http"'
assert_contains "gemini: uses url key"        "$GEMINI_CFG" '"url"'
assert_not_contains "gemini: drops legacy httpUrl" "$GEMINI_CFG" 'httpUrl'

# Antigravity's one distinguishing field. Getting this wrong is a config it ignores.
assert_contains "antigravity: uses serverUrl"  "$ANTIG_CFG" '"serverUrl"'
assert_not_contains "antigravity: not gemini's url key" "$ANTIG_CFG" '"type": "http"'

# The two must never generate the same thing, or the split is cosmetic.
if [ "$GEMINI_CFG" != "$ANTIG_CFG" ]; then pass "gemini and antigravity configs genuinely differ"; else fail "gemini and antigravity configs genuinely differ"; fi

GEMINI_CMD="$(reg "setup_command_for('gemini')")"
assert_contains "gemini: native one-command setup"   "$GEMINI_CMD" "gemini mcp add"
assert_contains "gemini: http transport flag"        "$GEMINI_CMD" "--transport http"
# --scope user, or the server lands in whatever folder the user happened to be in.
assert_contains "gemini: user scope, not project"    "$GEMINI_CMD" "--scope user"
assert_contains "gemini: sends the bearer header"    "$GEMINI_CMD" "Authorization: Bearer"
assert_contains "gemini: token stays a fill-time placeholder" "$GEMINI_CMD" '${WPCC_TOKEN}'
assert_eq "gemini: exact user settings path" "~/.gemini/settings.json" "$(wpe "\$c=WPCommandCenter\\Integration\\AIClientRegistry::get_client('gemini'); echo \$c['config_paths']['macos'];")"
assert_eq "gemini: credential model stays inline" "inline" "$(reg "credential_mode_for('gemini')")"

# Installed agy 1.1.27 supports native management; flags precede positionals.
assert_contains "antigravity: native setup command" "$(reg "setup_command_for('antigravity')")" "agy mcp add --header"

echo ""
echo "== 8. Shared config files warn about merging (Finding #3 problem 3, Finding #4) =="

# A client configured by hand edits a file full of the user's other settings. Native setup
# remains the safe primary path, but a separately displayed manual fallback still needs the
# warning because the user is editing that shared file by hand.
assert_eq "antigravity: advanced fallback needs merge warning" "1" "$(wpe "echo WPCommandCenter\\Integration\\AIClientRegistry::config_file_is_shared('antigravity') ? 1 : 0;")"
assert_eq "cursor: flagged as a shared config file"      "1" "$(wpe "echo WPCommandCenter\\Integration\\AIClientRegistry::config_file_is_shared('cursor') ? 1 : 0;")"
assert_eq "gemini: advanced fallback gets merge warning" "1" "$(wpe "echo WPCommandCenter\\Integration\\AIClientRegistry::config_file_is_shared('gemini') ? 1 : 0;")"
assert_eq "codex: command handles the merge, no warning"  "0" "$(wpe "echo WPCommandCenter\\Integration\\AIClientRegistry::config_file_is_shared('codex') ? 1 : 0;")"

# Wording is owned by section 9 (Finding #4), which replaced the original single warning
# with an explicit two-case presentation. Asserted there against the current copy; here
# only the behaviour that drives it is checked, so the two sections cannot disagree.
assert_eq "shared-file clients get the merge treatment" "1" "$(wpe "echo WPCommandCenter\\Integration\\AIClientRegistry::config_file_is_shared('antigravity') && '' !== WPCommandCenter\\Integration\\AIClientRegistry::render_entry_config('antigravity') ? 1 : 0;")"

# Client quirks that look like a WPCC failure must be stated somewhere on the screen.
GEMINI_NOTES="$(wpe "echo implode(' ', WPCommandCenter\\Integration\\AIClientRegistry::post_setup_notes_for('gemini'));")"
assert_contains "gemini: explains the untrusted-folder behaviour" "$GEMINI_NOTES" "trust"
assert_contains "gemini: native command stays recommended" "$GEMINI_NOTES" "recommended path"
assert_contains "gemini: native command promises preservation" "$GEMINI_NOTES" "preserves every existing Gemini setting"
assert_contains "gemini: inline token storage is disclosed" "$GEMINI_NOTES" "stores the bearer token inline"
assert_contains "gemini: secret safety names settings file" "$GEMINI_NOTES" "~/.gemini/settings.json"
assert_not_contains "gemini: guidance retains no raw token" "$GEMINI_NOTES" "wpcc_"

echo ""
echo "== 9. Existing configs are never clobbered (Finding #4) =="

# Cursor speaks remote HTTP MCP directly. It was advertised as "Relay / Node.js required"
# only because CursorIntegration did not extend the base class, so the registry's
# transport lookup fell through to its stdio default.
assert_eq "cursor: direct HTTP, not relay" "http" "$(reg "transport_for('cursor')")"

CURSOR_CFG="$(cfg cursor)"
assert_contains     "cursor: remote url form"        "$CURSOR_CFG" '"url"'
assert_contains     "cursor: bearer header"          "$CURSOR_CFG" 'Authorization'
# No relay means no downloaded script and no node invocation in the config.
assert_not_contains "cursor: no relay bootstrap"     "$CURSOR_CFG" 'wpcc-mcp-relay'
assert_not_contains "cursor: no node requirement"    "$CURSOR_CFG" 'node '

# The entry-only fragment is what makes merging unambiguous: it IS the thing that goes
# inside mcpServers, so there is nothing left for the reader to work out.
ENTRY="$(wpe "echo WPCommandCenter\\Integration\\AIClientRegistry::render_entry_config('cursor');")"
assert_contains     "cursor: entry fragment is keyed"      "$ENTRY" '"wp-command-center":'
assert_not_contains "cursor: entry fragment has no wrapper" "$ENTRY" 'mcpServers'

# Merging the fragment into a file that already holds another server must produce valid
# JSON with BOTH servers — the exact situation that broke during real testing.
MERGE_OK="$(python3 - "$ENTRY" <<'PY'
import json, sys
entry = sys.argv[1]
existing = '{\n  "mcpServers": {\n    "wordpress-mcp": {"command": "npx"}\n  }\n}'
merged = existing.replace('"wordpress-mcp": {"command": "npx"}',
                          '"wordpress-mcp": {"command": "npx"},\n    ' + entry.replace('\n', '\n    '))
try:
    d = json.loads(merged)
except Exception:
    print("invalid"); raise SystemExit
ok = ("wordpress-mcp" in d["mcpServers"]
      and "wp-command-center" in d["mcpServers"]
      and d["mcpServers"]["wordpress-mcp"] == {"command": "npx"})
print("ok" if ok else "lost")
PY
)"
assert_eq "cursor: entry merges cleanly, keeping the existing server" "ok" "$MERGE_OK"

# Gemini's advanced fallback is the same shared-file problem with more at stake: its
# settings.json may also contain IDE, security, UI and account preferences. Apply the
# displayed entry-only semantics to that realistic starting file and parse the result.
GEMINI_ENTRY="$(wpe "echo WPCommandCenter\\Integration\\AIClientRegistry::render_entry_config('gemini');")"
assert_contains     "gemini: merge fragment is keyed" "$GEMINI_ENTRY" '"wp-command-center":'
assert_not_contains "gemini: merge fragment has no wrapper" "$GEMINI_ENTRY" '"mcpServers"'
GEMINI_MERGE_OK="$(python3 - "$GEMINI_ENTRY" <<'PY'
import json, sys

entry = json.loads("{" + sys.argv[1] + "}")["wp-command-center"]
settings = {
    "mcpServers": {"another-server": {"command": "example"}},
    "security": {"folderTrust": True},
    "ui": {"theme": "system"},
    "ide": {"enabled": True},
}
settings["mcpServers"]["wp-command-center"] = entry
round_trip = json.loads(json.dumps(settings))
ok = (
    round_trip["mcpServers"]["another-server"] == {"command": "example"}
    and round_trip["mcpServers"]["wp-command-center"]["type"] == "http"
    and round_trip["security"] == {"folderTrust": True}
    and round_trip["ui"] == {"theme": "system"}
    and round_trip["ide"] == {"enabled": True}
)
print("ok" if ok else "lost")
PY
)"
assert_eq "gemini: entry merge keeps other MCP/security/UI/IDE settings and valid JSON" "ok" "$GEMINI_MERGE_OK"

# Formats with no wrapper+entry shape must return nothing rather than an invented one.
assert_eq "codex: no entry fragment for TOML"        "" "$(wpe "echo WPCommandCenter\\Integration\\AIClientRegistry::render_entry_config('codex');")"
assert_eq "claude_code: no entry fragment for shell" "" "$(wpe "echo WPCommandCenter\\Integration\\AIClientRegistry::render_entry_config('claude_code');")"

assert_contains "view: offers an entry-only copy"      "$(cat "$VIEW")" "Copy this entry only"
assert_contains "view: states the do-not-replace case" "$(cat "$VIEW")" "do not replace it"
assert_contains "view: Gemini forbids replacing settings.json" "$(cat "$VIEW")" "Do not replace the whole settings.json file"
assert_contains "view: Gemini says to add only its entry" "$(cat "$VIEW")" "Add only the “wp-command-center” entry"
assert_contains "view: Gemini preserves other MCP servers" "$(cat "$VIEW")" "Keep every other server"
assert_contains "view: Gemini preserves unrelated top-level settings" "$(cat "$VIEW")" "every unrelated top-level setting"
assert_contains "view: Gemini covers a missing mcpServers object" "$(cat "$VIEW")" "If “mcpServers” does not exist"
assert_contains "view: Gemini labels the merge fragment" "$(cat "$VIEW")" "Entry to merge into mcpServers"
assert_contains "view: Gemini labels full JSON as empty-file only" "$(cat "$VIEW")" "Copy empty-file example"

if grep -Eq 'wpcc_[A-Za-z0-9]{20,}' "$0"; then
	fail "test evidence contains a raw WPCC token pattern"
else
	pass "test evidence contains no raw WPCC token"
fi

echo ""
echo "== 10. Continue targets its real config file and format (Finding #5) =="

CONT_CFG="$(cfg continue)"

# The tested install had NO ~/.continue/mcp.json — the path WPCC used to hand out. Its
# "Main Config" opens ~/.continue/config.yaml.
assert_contains     "continue: points at config.yaml" "$(wpe "\$c=WPCommandCenter\\Integration\\AIClientRegistry::get_client('continue'); echo \$c['config_paths']['macos'];")" "config.yaml"
assert_not_contains "continue: no longer names mcp.json" "$(wpe "\$c=WPCommandCenter\\Integration\\AIClientRegistry::get_client('continue'); echo \$c['config_paths']['macos'];")" "mcp.json"

# YAML, and specifically Continue's LIST shape with a name: field. Every other client in
# the registry keys servers by name inside an object; a faithful JSON->YAML translation
# would still have produced the wrong structure here.
assert_eq       "continue: format is yaml" "yaml" "$(wpe "\$r='WPCommandCenter\\Integration\\AIClientRegistry'; echo \$r::config_format(\$r::generate_config('continue'));")"
assert_contains "continue: list item with name:" "$CONT_CFG" "- name: wp-command-center"
assert_not_contains "continue: not the JSON object shape" "$CONT_CFG" '"mcpServers"'

# Parse it for real rather than pattern-matching a string that only looks like YAML.
YAML_OK="$(printf '%s' "$CONT_CFG" > /tmp/wpcc-cont-test.yaml && ruby -ryaml -e '
d = YAML.load_file("/tmp/wpcc-cont-test.yaml")
s = d["mcpServers"][0] rescue nil
ok = d["mcpServers"].is_a?(Array) && s && s["name"] == "wp-command-center" &&
     s["command"] == "bash" && s["args"].is_a?(Array) && s["env"].is_a?(Hash)
print(ok ? "ok" : "bad")' 2>/dev/null)"
assert_eq "continue: generated YAML parses to the right structure" "ok" "$YAML_OK"

# Appending it to an existing Main Config must not disturb what is already there.
MERGE_OK="$(ruby -ryaml -e '
existing = "name: Local Config\nversion: 1.0.0\nmodels:\n  - name: C\n    provider: anthropic\n"
merged = existing + File.read("/tmp/wpcc-cont-test.yaml")
d = YAML.load(merged)
ok = d["name"] == "Local Config" && d["models"].length == 1 &&
     d["mcpServers"][0]["name"] == "wp-command-center"
print(ok ? "ok" : "bad")' 2>/dev/null)"
assert_eq "continue: merges into an existing Main Config" "ok" "$MERGE_OK"

# The model prerequisite is the difference between "connected" and "usable", and its
# absence is what made a working setup look broken.
assert_contains "continue: states the model prerequisite" "$(wpe "\$n=WPCommandCenter\\Integration\\AIClientRegistry::post_setup_notes_for('continue'); echo \$n[0] ?? '';")" "model"

echo ""
echo "== 11. MCP methods clients actually call (Finding #5 problem E) =="

# Continue calls resources/templates/list during initialisation. Answering -32601 made
# the client's first impression of a healthy server an error message.
TPL="$(curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"resources/templates/list","params":{}}' "$WPCC_BASE/mcp")"
assert_contains     "resources/templates/list answers"        "$TPL" "resourceTemplates"
assert_not_contains "resources/templates/list is not -32601"  "$TPL" "-32601"

# ping is a spec liveness check any client may send.
PING="$(curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":2,"method":"ping","params":{}}' "$WPCC_BASE/mcp")"
assert_contains     "ping answers"           "$PING" '"result"'
assert_not_contains "ping is not -32601"     "$PING" "-32601"

# An genuinely unknown method must STILL be refused — the fix adds methods, it does not
# make the server answer everything.
BOGUS="$(curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":3,"method":"wpcc/not_a_real_method","params":{}}' "$WPCC_BASE/mcp")"
assert_contains "unknown methods are still rejected" "$BOGUS" "-32601"

echo ""
echo "== 12. Meta Muse Code (Finding #6) =="

MUSE_CFG="$(cfg muse_code)"

# Three keys decide whether this config works at all, and each differs from what every
# other JSON client in this registry uses. See MuseCodeIntegration for the sources.
assert_contains     "muse: snake_case mcp_servers"      "$MUSE_CFG" '"mcp_servers"'
assert_not_contains "muse: NOT camelCase mcpServers"    "$MUSE_CFG" '"mcpServers"'
assert_contains     "muse: transport selector key"      "$MUSE_CFG" '"transport": "streamable_http"'
assert_not_contains "muse: not the 'type' key"          "$MUSE_CFG" '"type": "streamable_http"'

# Mandatory: a settings.json without it fails EVERY muse command at startup, not just MCP.
assert_contains "muse: carries mandatory schema_version" "$MUSE_CFG" '"schema_version": 1'
assert_contains "muse: warns not to delete schema_version" "$(wpe "\$n=WPCommandCenter\\Integration\\AIClientRegistry::post_setup_notes_for('muse_code'); echo \$n[0] ?? '';")" "schema_version"

assert_eq       "muse: direct HTTP"                 "http" "$(reg "transport_for('muse_code')")"
# No `muse mcp add` exists in the docs; inventing one is out of bounds.
assert_eq       "muse: no invented setup command"   ""     "$(reg "setup_command_for('muse_code')")"
# So it is a hand-edited shared file and must get the merge treatment.
assert_eq       "muse: gets the merge treatment"    "1"    "$(wpe "echo WPCommandCenter\\Integration\\AIClientRegistry::config_file_is_shared('muse_code') ? 1 : 0;")"

# Honest labelling: the owner subsequently authenticated the actual client and completed
# one read-only system_info call; that is Pass evidence, not Gold lifecycle evidence.
assert_eq       "muse: not marked recommended"      "experimental" "$(wpe "\$c=WPCommandCenter\\Integration\\AIClientRegistry::get_client('muse_code'); echo \$c['tier'];")"
assert_contains "muse: records owner actual-client pass" "$(wpe "\$c=WPCommandCenter\\Integration\\AIClientRegistry::get_client('muse_code'); echo \$c['validation_notes'];")" "FINAL VERDICT: CERT_PASS"
assert_contains "muse: records model-driven read" "$(wpe "\$c=WPCommandCenter\\Integration\\AIClientRegistry::get_client('muse_code'); echo \$c['validation_notes'];")" "invoked system_info once"

# The generated whole-file config must be valid JSON — it is the entire settings file.
MUSE_JSON_OK="$(printf '%s' "$MUSE_CFG" | python3 -c "
import json,sys
try:
    d=json.load(sys.stdin)
    print('ok' if d.get('schema_version')==1 and 'wp-command-center' in d['mcp_servers'] else 'bad')
except Exception:
    print('invalid')")"
assert_eq "muse: whole-file config is valid JSON" "ok" "$MUSE_JSON_OK"

echo ""
echo "== 13. OpenCode native remote MCP (Finding #7) =="

OC_CFG="$(cfg opencode)"
OC_CMD="$(reg "setup_command_for('opencode')")"

# Was advertised as relay + Node.js + connector script. OpenCode has native remote MCP.
assert_eq           "opencode: direct HTTP, not relay" "http" "$(reg "transport_for('opencode')")"
assert_not_contains "opencode: no relay bootstrap"     "$OC_CFG" "wpcc-mcp-relay"
assert_not_contains "opencode: no node requirement"    "$OC_CFG" '"command"'

# OpenCode's block is `mcp` with a `type: remote` server — not `mcpServers`.
assert_contains     "opencode: mcp block"              "$OC_CFG" '"mcp"'
assert_not_contains "opencode: not mcpServers"         "$OC_CFG" '"mcpServers"'
assert_contains     "opencode: type remote"            "$OC_CFG" '"type": "remote"'

# The native command, verified by running it against the installed client.
assert_contains "opencode: uses opencode mcp add"      "$OC_CMD" "opencode mcp add"
assert_contains "opencode: passes --url"               "$OC_CMD" "--url"
assert_contains "opencode: passes --header"            "$OC_CMD" "--header"

# --header takes KEY=VALUE, not the "Key: Value" wire form. Getting this wrong produces
# a header literally named "Authorization: Bearer wpcc_..." with an empty value.
assert_contains     "opencode: header uses KEY=VALUE form" "$OC_CMD" "Authorization=Bearer"
assert_not_contains "opencode: not the wire header form"   "$OC_CMD" "Authorization: Bearer"

# `opencode mcp auth` is an OAuth flow and must never be recommended for a bearer-token
# server — real testing wasted a cycle on exactly that.
assert_contains     "opencode: warns off the OAuth command" "$(wpe "\$n=WPCommandCenter\\Integration\\AIClientRegistry::post_setup_notes_for('opencode'); echo \$n[0] ?? '';")" "opencode mcp auth"
assert_not_contains "opencode: setup command is not mcp auth" "$OC_CMD" "mcp auth"

echo ""
echo "== 14. Strict tool-schema validation (Finding #8) =="

# THE regression that matters most in this file.
#
# GitHub Copilot validates every tool schema before it will use ANY of them, and rejects
# an array without `items`. One malformed schema therefore took down all 42 tools: a
# request for system_info failed because bulk_manage was invalid. A scan found eight such
# properties across six tools, not the one the client named.
#
# This walks every tool schema recursively rather than checking the known offenders, so a
# NEW array parameter added without `items` fails here instead of in someone's editor.
TOOLS_JSON="$(curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}' "$WPCC_BASE/mcp")"

SCHEMA_REPORT="$(printf '%s' "$TOOLS_JSON" | python3 -c '
import json,sys
tools = json.load(sys.stdin)["result"]["tools"]
bad = []
def walk(node, path, tool):
    if not isinstance(node, dict): return
    if node.get("type") == "array" and "items" not in node:
        bad.append(tool + path)
    for key in ("properties", "$defs", "definitions"):
        if isinstance(node.get(key), dict):
            for k, v in node[key].items(): walk(v, path + "." + k, tool)
    if isinstance(node.get("items"), dict): walk(node["items"], path + ".items", tool)
    for key in ("anyOf", "oneOf", "allOf"):
        if isinstance(node.get(key), list):
            for i, sub in enumerate(node[key]): walk(sub, path + "." + key, tool)
for t in tools: walk(t.get("inputSchema") or {}, "", t["name"])
print(str(len(tools)) + "|" + str(len(bad)) + "|" + ",".join(bad))
')"
TOOL_COUNT="${SCHEMA_REPORT%%|*}"
REST="${SCHEMA_REPORT#*|}"
BAD_COUNT="${REST%%|*}"
BAD_LIST="${REST#*|}"

assert_eq "all 42 tools exposed"                      "42" "$TOOL_COUNT"
assert_eq "no array schema is missing items ($BAD_LIST)" "0"  "$BAD_COUNT"

# Codex treats tools without standard MCP behavior annotations as approval-requiring.
# system_info is diagnostic in WPCC's worst-case operation registry and is therefore
# safe to label read-only. Mutating and mixed-risk operations must remain conservative.
ANNOTATION_REPORT="$(printf '%s' "$TOOLS_JSON" | python3 -c '
import json,sys
tools={t["name"]:t for t in json.load(sys.stdin)["result"]["tools"]}
system=tools["system_info"].get("annotations", {})
mutation=tools["content_manage"].get("annotations", {})
print("|".join([
  str(system.get("readOnlyHint", "missing")).lower(),
  str(system.get("destructiveHint", "missing")).lower(),
  str(mutation.get("readOnlyHint", "missing")).lower(),
  str(mutation.get("destructiveHint", "missing")).lower(),
]))')"
assert_eq "system_info is truthfully classified read-only for Codex" "true|false|false|true" "$ANNOTATION_REPORT"

# Item types should be MEANINGFUL where the parameter's contract is known — the
# permissive {} fallback is a guarantee against future regressions, not a target.
IDS_ITEMS="$(printf '%s' "$TOOLS_JSON" | python3 -c '
import json,sys
t=[x for x in json.load(sys.stdin)["result"]["tools"] if x["name"]=="bulk_manage"][0]
print(json.dumps(t["inputSchema"]["properties"]["ids"].get("items")))')"
assert_contains "bulk_manage.ids declares a real item type" "$IDS_ITEMS" "number"

echo ""
echo "== 15. VS Code / Copilot credential handling (Finding #8) =="

VSC_CFG="$(cfg vscode)"
# `servers`, not `mcpServers`. VS Code ignores the wrong key silently.
assert_contains     "vscode: servers root key"     "$VSC_CFG" '"servers"'
assert_not_contains "vscode: not mcpServers"       "$VSC_CFG" '"mcpServers"'

# The token is prompted for, never written into a file that is routinely committed.
assert_eq       "vscode: credential mode is prompt" "prompt" "$(reg "credential_mode_for('vscode')")"
assert_contains "vscode: references a site-scoped input" "$VSC_CFG" '${input:wpcc-token-'
assert_contains "vscode: declares the input"        "$VSC_CFG" '"promptString"'
assert_contains "vscode: masks the input"           "$VSC_CFG" '"password": true'
assert_not_contains "vscode: does not claim OAuth"  "$VSC_CFG" '"oauth"'
# The whole point: no token, and no token-shaped placeholder, in the file.
assert_not_contains "vscode: no token placeholder"  "$VSC_CFG" '${WPCC_TOKEN}'
assert_not_contains "vscode: no literal token"      "$VSC_CFG" 'wpcc_'

# VS Code memoizes promptString values in secure storage. A new WPCC token record must
# receive a different input id. The guided flow must also restore the token to the
# clipboard after the JSON copy, because a missing/invalid value receives 401 and VS
# Code falls into its unrelated OAuth/DCR recovery path.
VSC_A="$(wpe 'echo WPCommandCenter\Integration\AIClientRegistry::primary_config_for("vscode", "wpcc_SYNTHETIC_A", "record-a");')"
VSC_B="$(wpe 'echo WPCommandCenter\Integration\AIClientRegistry::primary_config_for("vscode", "wpcc_SYNTHETIC_B", "record-b");')"
VSC_A_ID="$(printf '%s' "$VSC_A" | jq -r '.inputs[0].id')"
VSC_B_ID="$(printf '%s' "$VSC_B" | jq -r '.inputs[0].id')"
assert_eq "vscode: credential records use different secure-input slots" "true" "$([ "$VSC_A_ID" != "$VSC_B_ID" ] && echo true || echo false)"
assert_eq "vscode: Authorization references its declared input" "Bearer \${input:$VSC_A_ID}" "$(printf '%s' "$VSC_A" | jq -r '.servers["wp-command-center"].headers.Authorization')"
assert_not_contains "vscode: credential id does not expose token record id" "$VSC_A_ID" "record-a"
assert_not_contains "vscode: generated config never embeds synthetic token" "$VSC_A" "wpcc_SYNTHETIC_A"

VSC_GUIDED="$(wpe '
use WPCommandCenter\Security\AuthTokens;
wp_set_current_user(1);
$_GET=["client"=>"vscode","tab"=>"configuration"];
$_POST=["wpcc_token_action"=>"create","_wpnonce"=>wp_create_nonce("wpcc_ai_integrations"),"wpcc_token_label"=>"VS Code clipboard-order synthetic test","wpcc_token_scope"=>"read_only","wpcc_token_expires"=>"30d"];
$_REQUEST=$_POST;
$record=null;
try {
 ob_start(); require WPCC_PLUGIN_DIR."includes/Admin/views/ai-integrations.php"; $html=ob_get_clean();
 $record=$wpcc_new_record["id"]??null;
 $dom=new DOMDocument(); @$dom->loadHTML("<?xml encoding=\"utf-8\" ?>".$html); $xp=new DOMXPath($dom);
 $setup=$xp->query("//*[@data-copy-target=\"wpcc-primary-config\"]")->item(0);
 $token=$xp->query("//*[@data-copy-target=\"wpcc-new-token\" and contains(.,\"Copy token for VS Code\")]")->item(0);
 $finish=$token?$xp->query("ancestor::*[contains(concat(\" \",normalize-space(@class),\" \"),\" wpcc-connect-step \")]",$token)->item(0):null;
 echo implode("|",[
  $setup&&$token?"both_actions":"missing_action",
  $finish&&str_contains($finish->textContent,"copying the setup replaced your clipboard")?"order_explained":"order_missing",
  $finish&&$xp->query(".//*[contains(concat(\" \",normalize-space(@class),\" \"),\" button-primary \")]",$finish)->length===1?"one_primary":"primary_count_bad",
  $token&&!$token->hasAttribute("data-copy")?"target_only":"secret_attribute"
 ]);
} finally { if($record){(new AuthTokens())->delete($record);} }
')"
assert_contains "vscode: setup and final token actions both render" "$VSC_GUIDED" "both_actions"
assert_contains "vscode: final action explains clipboard order" "$VSC_GUIDED" "order_explained"
assert_contains "vscode: final action has one primary CTA" "$VSC_GUIDED" "one_primary"
assert_contains "vscode: copy button targets one-time reveal without embedding secret" "$VSC_GUIDED" "target_only"

echo ""
echo "== 16. Windsurf deferred from v1 (Finding #9) =="

# Absent, not demoted. The finding was explicit: do not replace it with "coming soon",
# "experimental", "beta" or "unsupported" — for v1, simply do not advertise it.
assert_eq "windsurf: not in the client roster" "" "$(wpe "\$c=WPCommandCenter\\Integration\\AIClientRegistry::get_client('windsurf'); echo \$c ? 'present' : '';")"
assert_eq "windsurf: generates no configuration" "" "$(wpe "\$x=WPCommandCenter\\Integration\\AIClientRegistry::generate_config('windsurf'); echo \$x ? 'present' : '';")"
assert_eq "windsurf: integration class gone" "0" "$(ls "$PLUGIN_DIR/includes/Integration/" | grep -c -i windsurf || true)"

# The shared relay MUST survive: Claude Desktop and Continue still depend on it. Removing
# a client must not remove infrastructure other clients use.
assert_contains "relay still available to clients that use it" "$(cfg claude)" "wpcc-mcp-relay"
assert_eq       "claude desktop still on the relay" "stdio" "$(reg "transport_for('claude')")"

# Customer-facing copy must not still advertise it.
assert_not_contains "readme no longer lists Windsurf" "$(cat "$PLUGIN_DIR/readme.txt")" "Windsurf"

echo ""
echo "== 17. Command Code native Direct HTTP (Finding #10) =="

CC_CFG="$(cfg command_code)"
CC_CMD="$(reg "setup_command_for('command_code')")"

assert_eq           "command_code: direct HTTP, not relay" "http" "$(reg "transport_for('command_code')")"
assert_not_contains "command_code: no relay bootstrap"     "$CC_CFG" "wpcc-mcp-relay"
assert_not_contains "command_code: no node/bash launcher"  "$CC_CFG" '"command"'
# The relay's environment block existed only to feed the connector.
assert_not_contains "command_code: no relay env vars"      "$CC_CFG" "WPCC_CONTEXT_MODE"

assert_contains "command_code: uses cmd mcp add"      "$CC_CMD" "cmd mcp add"
assert_contains "command_code: http transport"        "$CC_CMD" "--transport http"
# Default scope is `local`, which would tie the server to one directory.
assert_contains "command_code: user scope"            "$CC_CMD" "--scope user"

# TWO CLIENTS, TWO HEADER SYNTAXES — asserted in both directions because getting either
# backwards is a real, tested failure:
#   Command Code  "Authorization: Bearer x"   KEY=VALUE is REJECTED outright
#   OpenCode      "Authorization=Bearer x"    the wire form registers with an EMPTY value
assert_contains     "command_code: 'Header: value' syntax"  "$CC_CMD" "Authorization: Bearer"
assert_not_contains "command_code: not the KEY=VALUE form"  "$CC_CMD" "Authorization=Bearer"

# Guard the pair against a future refactor collapsing them onto one shared helper.
assert_contains     "opencode still uses KEY=VALUE"         "$(reg "setup_command_for('opencode')")" "Authorization=Bearer"

# The OAuth message Command Code prints is misleading and must be pre-empted.
assert_contains "command_code: explains the OAuth message" "$(wpe "\$n=WPCommandCenter\\Integration\\AIClientRegistry::post_setup_notes_for('command_code'); echo \$n[0] ?? '';")" "OAuth"

echo ""
echo "== 18. Transport claims are truthful across the roster =="

# Node.js is only ever required by a genuine relay client. After this remediation only
# Claude Desktop and Continue are relay; every other client must be direct HTTP.
for C in chatgpt codex gemini antigravity cursor opencode vscode claude_code command_code muse_code; do
	assert_eq "$C: direct HTTP" "http" "$(reg "transport_for('$C')")"
done
for C in claude continue; do
	assert_eq "$C: genuinely uses the relay" "stdio" "$(reg "transport_for('$C')")"
done

echo ""
echo "== 19. No credential leaks in committed files =="

# Committed docs/tests must use a placeholder. A real token is 64 random characters
# after the prefix; this catches one pasted in during debugging and forgotten.
#
# Deliberately-fake fixtures are excluded by ENTROPY, not by an exemption list. The
# redaction suite has to embed token-shaped strings — proving they get redacted is its
# entire job — and it builds them from repeated blocks (wpcc_AAAABBBBCCCC...). Four
# identical consecutive characters is something a real generated token effectively never
# contains, so that single rule separates the fixtures from a genuine leak without any
# file needing to be trusted by name.
LEAK_HITS="$(grep -rIhoE 'wpcc_[A-Za-z0-9]{40,}' "$PLUGIN_DIR/tests" "$PLUGIN_DIR/includes" "$PLUGIN_DIR/docs" 2>/dev/null || true)"
LEAKS="$(printf '%s\n' "$LEAK_HITS" | grep -vE '(.)\1\1\1' | grep -v '^$' || true)"
assert_eq "no real tokens committed under tests/includes/docs" "" "$LEAKS"

echo ""
echo "------------------------------------------------"
echo "Onboarding contract: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
