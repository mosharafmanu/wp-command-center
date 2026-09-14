#!/usr/bin/env bash
# Connections client icon presentation contract. Read-only.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_ROOT="${WPCC_TEST_WP_PATH:-$(cd "$SCRIPT_DIR/../../../.." && pwd)}"
VIEW="$PLUGIN_DIR/includes/Admin/views/ai-integrations.php"
ASSET_DIR="$PLUGIN_DIR/assets/integrations"

PASS=0
FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local description="$1" expected="$2" actual="$3"; if [[ "$expected" == "$actual" ]]; then pass "$description"; else fail "$description (expected '$expected', got '$actual')"; fi; }
assert_contains() { local description="$1" haystack="$2" needle="$3"; if [[ "$haystack" == *"$needle"* ]]; then pass "$description"; else fail "$description (missing '$needle')"; fi; }
wpe() { wp --path="$WP_ROOT" eval "$1" 2>/dev/null; }

VIEW_TEXT="$(<"$VIEW")"
ICON_MAP_BLOCK="$(sed -n '/^\$wpcc_client_icons = \[/,/^\];/p' "$VIEW")"
ICON_MAP="$(printf '%s\n' "$ICON_MAP_BLOCK" | sed -n "s/^[[:space:]]*'\([^']*\)'[[:space:]]*=>[[:space:]]*'\([^']*\)'.*/\1=\2/p")"
EXPECTED_MAP=$'chatgpt=openai.svg\ncodex=openai.svg\nclaude=claude.svg\nclaude_code=claude.svg\nantigravity=antigravity.png\ngemini=gemini.svg\ncursor=cursor.svg\ncontinue=continue.svg\nvscode=github-copilot.svg\nopencode=opencode.svg\ncommand_code=command-code.svg\nmuse_code=muse-code.svg'

echo "== Complete local icon map =="
assert_eq "all 12 clients have the intentional icon mapping" "$EXPECTED_MAP" "$ICON_MAP"
assert_eq "icon map contains 12 client identities" "12" "$(printf '%s\n' "$ICON_MAP" | wc -l | tr -d ' ')"
assert_eq "shared brands keep shared marks" "openai.svg,openai.svg,claude.svg,claude.svg" "$(printf '%s\n' "$ICON_MAP" | awk -F= '$1 ~ /^(chatgpt|codex|claude|claude_code)$/ {print $2}' | paste -sd, -)"

while IFS='=' read -r client asset; do
	if [[ -f "$ASSET_DIR/$asset" ]]; then
		pass "$client asset exists: $asset"
	else
		fail "$client asset is missing: $asset"
		continue
	fi
	if [[ "$asset" == *.svg ]]; then
		if head -c 512 "$ASSET_DIR/$asset" | grep -q '<svg'; then
			pass "$client SVG has a valid root"
		else
			fail "$client SVG root is missing"
		fi
	else
		PNG_SIGNATURE="$(od -An -tx1 -N8 "$ASSET_DIR/$asset" | tr -d ' \n')"
		assert_eq "$client PNG signature is valid" "89504e470d0a1a0a" "$PNG_SIGNATURE"
	fi
done <<< "$ICON_MAP"

assert_eq "only mapped runtime assets are present" \
	"$({ printf '%s\n' "$ICON_MAP" | cut -d= -f2; printf '%s\n' ATTRIBUTIONS.txt; } | LC_ALL=C sort -u)" \
	"$(find "$ASSET_DIR" -maxdepth 1 -type f -print | sed 's|.*/||' | LC_ALL=C sort)"
assert_contains "package-local notice credits the CC BY Copilot glyph" "$(<"$ASSET_DIR/ATTRIBUTIONS.txt")" "License: CC BY 4.0"

echo "== Rendered markup and accessibility =="
for contract in \
	'wpcc-ai-pick__identity' \
	'wpcc-ai-pick__icon' \
	'wpcc-ai-pick__copy' \
	'alt="" aria-hidden="true" width="24" height="24" decoding="async"' \
	"WPCC_PLUGIN_URL . 'assets/integrations/'" \
	'object-fit:contain' \
	'box-sizing:content-box;padding:6px' \
	'grid-template-columns:repeat(4,minmax(0,1fr))' \
	'.wpcc-ai-pick:focus-visible' \
	"'aria-current=\"page\"'" \
	'wpcc-ai-pick.is-selected::after' \
	'content:"\2713"' \
	'margin-top:auto;padding:0;border:0;background:transparent'; do
	assert_contains "view retains $contract" "$VIEW_TEXT" "$contract"
done
if [[ "$VIEW_TEXT" != *'wpcc-ai-pick.is-selected .wpcc-ai-pick__name::after'* ]]; then
	pass "selected state no longer adds a competing title glyph"
else
	fail "selected state still adds a competing title glyph"
fi

echo "== Registry and status invariants =="
ROSTER="$(wpe '
$out = [];
foreach ( WPCommandCenter\Integration\AIClientRegistry::get_clients() as $id => $client ) {
    $badge = WPCommandCenter\Integration\AIClientRegistry::selector_badge_for( $id );
    $out[] = $id . "=" . $client["name"] . "=" . ( $badge["label"] ?? "" );
}
echo implode( "\n", $out );
')"
EXPECTED_ROSTER=$'chatgpt=Codex in ChatGPT Desktop=Connection tested\ncodex=Codex CLI=Connection tested\nclaude=Claude Desktop=Connection tested\nclaude_code=Claude Code=Certified\nantigravity=Antigravity CLI=Connection tested\ngemini=Gemini CLI=Account unavailable\ncursor=Cursor=Connection tested\ncontinue=Continue for VS Code=Connection tested\nvscode=GitHub Copilot in VS Code=Connection tested\nopencode=OpenCode=Connection tested\ncommand_code=Command Code=Connection tested\nmuse_code=Muse Code=Connection tested'
assert_eq "client identities, ordering, and status labels are unchanged" "$EXPECTED_ROSTER" "$ROSTER"
assert_eq "Other / Experimental still contains only Muse Code" "muse_code" "$(wpe 'echo implode(",", array_keys(WPCommandCenter\Integration\AIClientRegistry::get_client_groups()["Other / Experimental"] ?? []));')"
assert_contains "Other / Experimental stays a disclosure" "$VIEW_TEXT" '<details class="wpcc-ai-family wpcc-ai-family--other"'
assert_contains "selected Other client still opens its family" "$VIEW_TEXT" "isset( \$wpcc_family_clients[ \$wpcc_selected_client ] ) ? ' open' : ''"

echo ""
echo "Connections client icon results: $PASS passed, $FAIL failed"
[[ $FAIL -eq 0 ]]
