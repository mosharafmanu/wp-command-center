#!/usr/bin/env bash
# M22/M23/M25 fresh-user onboarding contract. Synthetic credentials only.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_ROOT="${WPCC_TEST_WP_PATH:-$(cd "$SCRIPT_DIR/../../../.." && pwd)}"
PASS=0
FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; if [ "$e" = "$a" ]; then pass "$d"; else fail "$d (expected '$e', got '$a')"; fi; }
assert_contains() { local d="$1" h="$2" n="$3"; if [[ "$h" == *"$n"* ]]; then pass "$d"; else fail "$d (missing '$n')"; fi; }
assert_not_contains() { local d="$1" h="$2" n="$3"; if [[ "$h" != *"$n"* ]]; then pass "$d"; else fail "$d (should not contain '$n')"; fi; }
wpe() { wp --path="$WP_ROOT" eval "$1" 2>/dev/null; }

echo '== M22 Continue first-MCP states =='
CONT_FULL="$(wpe '$r="WPCommandCenter\\Integration\\AIClientRegistry"; echo $r::render_config($r::generate_config("continue"), "wpcc_SYNTHETIC");')"
CONT_ENTRY="$(wpe 'echo WPCommandCenter\Integration\AIClientRegistry::primary_config_for("continue", "wpcc_SYNTHETIC");')"
assert_contains 'complete block supplies top-level key' "$CONT_FULL" 'mcpServers:'
assert_contains 'complete block supplies indented list item' "$CONT_FULL" '  - name: wp-command-center'
assert_not_contains 'entry-only copy has no second root key' "$CONT_ENTRY" 'mcpServers:'
assert_contains 'entry-only copy retains required indentation' "$CONT_ENTRY" '  - name: wp-command-center'
CONT_PARSE="$(printf '%s' "$CONT_FULL" | ruby -ryaml -e 'd=YAML.load(STDIN.read); print(d["mcpServers"][0]["name"]=="wp-command-center" ? "ok" : "bad")' 2>/dev/null)"
assert_eq 'complete YAML parses' ok "$CONT_PARSE"
ENTRY_PARSE="$(printf 'mcpServers:\n%s' "$CONT_ENTRY" | ruby -ryaml -e 'd=YAML.load(STDIN.read); print(d["mcpServers"][0]["name"]=="wp-command-center" ? "ok" : "bad")' 2>/dev/null)"
assert_eq 'entry YAML parses beneath existing key' ok "$ENTRY_PARSE"

echo '== M23 Muse first-file states =='
MUSE_PATH="$(wpe '$c=WPCommandCenter\Integration\AIClientRegistry::get_client("muse_code"); echo $c["config_paths"]["macos"];')"
MUSE_PREP="$(wpe 'echo WPCommandCenter\Integration\AIClientRegistry::prepare_config_command_for("muse_code");')"
MUSE_FULL="$(wpe '$r="WPCommandCenter\\Integration\\AIClientRegistry"; echo $r::render_config($r::generate_config("muse_code"), "wpcc_SYNTHETIC");')"
MUSE_ENTRY="$(wpe 'echo WPCommandCenter\Integration\AIClientRegistry::primary_config_for("muse_code", "wpcc_SYNTHETIC");')"
assert_eq 'exact Muse path shown' '~/.config/muse/settings.json' "$MUSE_PATH"
assert_contains 'prepare helper creates parent directory' "$MUSE_PREP" 'mkdir -p'
assert_contains 'prepare helper checks before writing' "$MUSE_PREP" '[ -f'
assert_contains 'prepare helper writes only on missing file' "$MUSE_PREP" '|| printf'
MUSE_JSON="$(printf '%s' "$MUSE_FULL" | python3 -c 'import json,sys; d=json.load(sys.stdin); print("ok" if d.get("schema_version")==1 and "wp-command-center" in d["mcp_servers"] else "bad")' 2>/dev/null)"
assert_eq 'complete Muse JSON is valid' ok "$MUSE_JSON"
MUSE_MERGE="$(python3 - "$MUSE_ENTRY" <<'PY'
import json,sys
entry=json.loads('{'+sys.argv[1]+'}')
existing={'schema_version':1,'mcp_servers':{'other':{'transport':'stdio'}},'theme':'dark'}
existing['mcp_servers'].update(entry)
roundtrip=json.loads(json.dumps(existing))
print('ok' if roundtrip['theme']=='dark' and 'other' in roundtrip['mcp_servers'] and 'wp-command-center' in roundtrip['mcp_servers'] else 'bad')
PY
)"
assert_eq 'entry-only Muse merge preserves settings' ok "$MUSE_MERGE"

SAFE_HOME="$(mktemp -d /tmp/wpcc-muse-prepare.XXXXXX)"
trap 'rm -rf "$SAFE_HOME"' EXIT
HOME="$SAFE_HOME" bash -c "$MUSE_PREP"
assert_eq 'prepare helper creates missing settings file' '{}' "$(tr -d '\n' < "$SAFE_HOME/.config/muse/settings.json")"
printf '%s\n' '{"keep":true}' > "$SAFE_HOME/.config/muse/settings.json"
BEFORE_HASH="$(shasum -a 256 "$SAFE_HOME/.config/muse/settings.json" | awk '{print $1}')"
HOME="$SAFE_HOME" bash -c "$MUSE_PREP"
AFTER_HASH="$(shasum -a 256 "$SAFE_HOME/.config/muse/settings.json" | awk '{print $1}')"
assert_eq 'prepare helper never overwrites existing settings' "$BEFORE_HASH" "$AFTER_HASH"

echo '== M25 rendered and browser-only gate =='
RENDER_DIR="$(mktemp -d /tmp/wpcc-fresh-render.XXXXXX)"
WPCC_FRESH_RENDER_DIR="$RENDER_DIR" wp --path="$WP_ROOT" eval-file "$PLUGIN_DIR/tests/lib/fresh-user-onboarding.php" "$PLUGIN_DIR"
PHP_STATUS=$?
if [ "$PHP_STATUS" -eq 0 ]; then pass 'rendered credential-state matrix'; else fail 'rendered credential-state matrix'; fi
node "$PLUGIN_DIR/tests/lib/fresh-user-token-gate.mjs" "$RENDER_DIR"
JS_STATUS=$?
if [ "$JS_STATUS" -eq 0 ]; then pass 'saved-token browser fill unlocks complete payloads'; else fail 'saved-token browser fill unlocks complete payloads'; fi
rm -rf "$RENDER_DIR"

echo
echo "Fresh-user onboarding: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
