#!/usr/bin/env bash
# Public identity audit: customer-facing output is SiteRadian-native while
# protocol/storage compatibility contracts remain intact.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_ROOT="${WPCC_TEST_WP_PATH:-$(cd "$SCRIPT_DIR/../../../.." && pwd)}"
cd "$ROOT"

PASS=0; FAIL=0
pass(){ PASS=$((PASS+1)); echo "  PASS: $1"; }
fail(){ FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
eq(){ local d="$1" e="$2" a="$3"; [[ "$e" == "$a" ]] && pass "$d" || fail "$d (expected '$e', got '$a')"; }
has(){ local d="$1" h="$2" n="$3"; [[ "$h" == *"$n"* ]] && pass "$d" || fail "$d (missing '$n')"; }
lacks(){ local d="$1" h="$2" n="$3"; [[ "$h" != *"$n"* ]] && pass "$d" || fail "$d (contains '$n')"; }
wpe(){ wp --path="$WP_ROOT" eval "$1" 2>/dev/null; }

echo "Public identity — generated customer surface"

CLIENTS="$(wpe '$r="WPCommandCenter\\Integration\\AIClientRegistry"; echo implode(" ",array_keys($r::get_clients()));')"
eq "all 12 supported client surfaces audited" "12" "$(wc -w <<<"$CLIENTS" | tr -d ' ')"

for client in $CLIENTS; do
	output="$(WPCC_PUBLIC_CLIENT="$client" wp --path="$WP_ROOT" eval '$r="WPCommandCenter\\Integration\\AIClientRegistry"; $id=(string)getenv("WPCC_PUBLIC_CLIENT"); echo $r::render_config($r::generate_config($id))."\n".$r::setup_command_for($id)."\n".$r::primary_config_for($id)."\n".$r::render_entry_config($id)."\n".implode("\n",$r::post_setup_notes_for($id));' 2>/dev/null)"
	# The REST path is a documented protocol exception, not the local MCP alias.
	identity_only="${output//wp-command-center\/v1/protocol-v1}"
	has "$client uses the SiteRadian alias" "$identity_only" "siteradian"
	lacks "$client has no legacy local alias" "$identity_only" "wp-command-center"
	lacks "$client has no legacy token variable" "$identity_only" "WPCC_TOKEN"
	lacks "$client has no legacy relay filename" "$identity_only" "wpcc-mcp-relay"
	lacks "$client has no pre-public product name" "$identity_only" "Action Steward"
	lacks "$client has no appended-AI product name" "$identity_only" "SiteRadian AI"
done

echo
echo "Public identity — metadata, UI, docs, and compatibility boundary"
PUBLIC_FILES=(siteradian.php readme.txt includes/Admin/views/command-home.php includes/Admin/views/ai-integrations.php includes/Admin/views/api-integrations.php includes/Admin/views/partials/assistant-connect.php includes/Admin/views/partials/assistant-verify.php)
eq "no former display brand in current public files" "0" "$(rg -i -l 'WP Command Center|Action Steward|SiteRadian AI' "${PUBLIC_FILES[@]}" | wc -l | tr -d ' ')"
eq "current public UI has no legacy MCP alias" "0" "$(rg --pcre2 -l 'wp-command-center(?!/v1)' "${PUBLIC_FILES[@]}" | wc -l | tr -d ' ')"
eq "current public UI has no legacy token variable" "0" "$(rg -l 'WPCC_TOKEN' "${PUBLIC_FILES[@]}" | wc -l | tr -d ' ')"
eq "current public UI has no legacy token placeholder" "0" "$(rg -F -l 'wpcc_...' "${PUBLIC_FILES[@]}" | wc -l | tr -d ' ')"
has "plugin header uses SiteRadian" "$(sed -n '1,18p' siteradian.php)" "Plugin Name:       SiteRadian"
has "hero carries category descriptor" "$(<includes/Admin/views/command-home.php)" "The AI Command Center for WordPress"
has "hero carries value proposition" "$(<includes/Admin/views/command-home.php)" "Give AI a safer way to work on your site."
has "Codex keeps the same-terminal warning" "$(<includes/Admin/views/partials/assistant-connect.php)" "Important: Keep this terminal open."
has "new relay accepts SiteRadian token input" "$(<sdk/javascript/siteradian-mcp-relay.mjs)" "process.env.SITERADIAN_TOKEN"
has "new relay accepts legacy token input" "$(<sdk/javascript/siteradian-mcp-relay.mjs)" "process.env.WPCC_TOKEN"
has "legacy relay remains packaged for existing configs" "$(<scripts/build-release.sh)" "sdk/javascript/wpcc-mcp-relay.mjs"
eq "REST namespace remains the certified protocol contract" "2" "$(rg -l "NAMESPACE = 'wp-command-center/v1'" includes/Mcp/McpServerRuntime.php includes/AiAgent/RestApi.php | wc -l | tr -d ' ')"
has "compatibility exceptions are documented" "$(<docs/PUBLIC-IDENTITY.md)" "Deliberate compatibility exceptions"

echo
echo "RESULT: $PASS passed, $FAIL failed"
[[ "$FAIL" -eq 0 ]]
