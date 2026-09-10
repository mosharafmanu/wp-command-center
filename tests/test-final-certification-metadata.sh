#!/usr/bin/env bash
# Final v1 client-certification metadata consistency. Read-only: no credentials or site writes.
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

REGISTRY="$(wpe '
use WPCommandCenter\Integration\AIClientRegistry;
$out = [];
foreach ( AIClientRegistry::get_clients() as $id => $client ) {
    $out[ $id ] = [
        "name" => $client["name"],
        "type" => $client["type"],
        "status" => $client["status"],
        "certification_level" => $client["certification_level"],
        "validation_notes" => $client["validation_notes"],
        "description" => $client["description"],
        "badges" => array_column( AIClientRegistry::ui_badges( $id ), "label" ),
    ];
}
echo wp_json_encode( $out );
')"

field() { printf '%s' "$REGISTRY" | jq -r --arg id "$1" --arg key "$2" '.[$id][$key] // ""'; }

echo "== Registry verdicts =="
assert_eq "Claude Code is Gold" "gold" "$(field claude_code certification_level)"
assert_contains "Claude Code records CERT_GOLD" "$(field claude_code validation_notes)" "FINAL VERDICT: CERT_GOLD"

for id in chatgpt codex claude antigravity cursor continue opencode vscode; do
    assert_eq "$id uses backward-compatible Pass tier" "active" "$(field "$id" certification_level)"
    assert_contains "$id records CERT_PASS" "$(field "$id" validation_notes)" "FINAL VERDICT: CERT_PASS"
done
assert_not_contains "ChatGPT is not Gold" "$(field chatgpt validation_notes)" "FINAL VERDICT: CERT_GOLD"
assert_not_contains "Cursor is never labelled Gold" "$(field cursor description) $(field cursor validation_notes)" "FINAL VERDICT: CERT_GOLD"

for id in gemini; do
    assert_eq "$id stays at narrow compatible tier" "compatible" "$(field "$id" certification_level)"
done
for id in command_code muse_code; do
    assert_eq "$id uses backward-compatible Pass tier" "active" "$(field "$id" certification_level)"
done
assert_contains "Claude Desktop records its actual desktop pass" "$(field claude validation_notes)" "FINAL VERDICT: CERT_PASS"
assert_contains "Gemini is externally blocked" "$(field gemini validation_notes)" "BLOCKED — EXTERNAL ACCOUNT/PROVIDER"
assert_eq "Gemini customer badge names account availability" "Account unavailable" "$(wpe 'echo WPCommandCenter\Integration\AIClientRegistry::selector_badge_for("gemini")["label"];')"
assert_contains "Command Code records the owner actual-client pass" "$(field command_code validation_notes)" "FINAL VERDICT: CERT_PASS"
assert_eq "Command Code customer badge reflects its pass" "Connection tested" "$(wpe 'echo WPCommandCenter\Integration\AIClientRegistry::selector_badge_for("command_code")["label"];')"
assert_contains "Copilot/VS Code records actual-client Pass" "$(field vscode validation_notes)" "FINAL VERDICT: CERT_PASS"
assert_contains "Copilot records reproduced OAuth/DCR trigger" "$(field vscode validation_notes)" "automatically entered OAuth discovery/DCR"
assert_contains "Copilot records successful path without OAuth/DCR" "$(field vscode validation_notes)" "without OAuth/DCR"
assert_contains "Copilot records one successful system_info" "$(field vscode validation_notes)" "completed system_info exactly once"
assert_contains "Muse records the owner actual-client pass" "$(field muse_code validation_notes)" "FINAL VERDICT: CERT_PASS"
assert_contains "Muse records actual authenticated discovery" "$(field muse_code validation_notes)" "requested tools, resources, resource templates and prompts successfully"
assert_eq "Muse customer badge reflects its pass" "Connection tested" "$(wpe 'echo WPCommandCenter\Integration\AIClientRegistry::selector_badge_for("muse_code")["label"];')"
assert_eq "Muse remains a CLI host, not a provider" "cli" "$(field muse_code type)"
assert_contains "Muse description names the standalone executable" "$(field muse_code description)" "Muse Code CLI (muse)"
assert_contains "Muse description separates Muse Spark" "$(field muse_code description)" "distinct from the Muse Spark model/API"

assert_eq "Antigravity is a CLI" "cli" "$(field antigravity type)"
assert_eq "Antigravity keeps the current product name" "Antigravity CLI" "$(field antigravity name)"
assert_eq "Gemini and Antigravity are separate entries" "2" "$(printf '%s' "$REGISTRY" | jq '[has("gemini"), has("antigravity")] | map(select(. == true)) | length')"
assert_eq "Gemini Spark is not advertised as a v1 client" "false" "$(printf '%s' "$REGISTRY" | jq -r 'has("gemini_spark")')"
assert_contains "Gemini CLI metadata distinguishes hosted Spark" "$(wpe '$c=WPCommandCenter\Integration\AIClientRegistry::get_client("gemini"); echo $c["surface_note"];')" "Gemini Spark custom apps are a separate hosted feature"
assert_eq "Windsurf is absent from the current registry" "false" "$(printf '%s' "$REGISTRY" | jq -r 'has("windsurf")')"
assert_eq "exactly one Gold client remains" "1" "$(printf '%s' "$REGISTRY" | jq '[.[] | select(.certification_level == "gold")] | length')"
assert_eq "ten Pass clients remain" "10" "$(printf '%s' "$REGISTRY" | jq '[.[] | select(.certification_level == "active")] | length')"
assert_eq "Pass is the active-tier customer label" "Pass" "$(wpe 'echo WPCommandCenter\Integration\AIClientRegistry::CERT_LABELS[WPCommandCenter\Integration\AIClientRegistry::CERT_ACTIVE];')"
assert_contains "Pass clients show a Pass badge" "$(field cursor badges)" "Pass"

echo "== Current documentation =="
AI_DOC="$(<"$PLUGIN_DIR/docs/AI-INTEGRATIONS.md")"
CERT_DOC="$(<"$PLUGIN_DIR/docs/ASSISTANT-CERTIFICATION.md")"
STATUS_TOP="$(sed -n '1,48p' "$PLUGIN_DIR/PROJECT_STATUS.md")"
HANDOFF_TOP="$(sed -n '1,48p' "$PLUGIN_DIR/RELEASE_HANDOFF.md")"

for doc_name in AI-INTEGRATIONS ASSISTANT-CERTIFICATION; do
    if [ "$doc_name" = "AI-INTEGRATIONS" ]; then doc="$AI_DOC"; else doc="$CERT_DOC"; fi
    assert_contains "$doc_name records Claude Code Gold" "$doc" "Claude Code | **CERT_GOLD**"
    assert_contains "$doc_name records ChatGPT Pass" "$doc" "Codex in ChatGPT Desktop | **CERT_PASS**"
    assert_contains "$doc_name records Codex Pass" "$doc" "Codex CLI 0.153.4 | **CERT_PASS**"
    assert_contains "$doc_name records Claude Desktop pass" "$doc" "Claude Desktop | **CERT_PASS**"
    assert_contains "$doc_name records Gemini external block" "$doc" "Gemini CLI 0.46.0 | **BLOCKED — EXTERNAL ACCOUNT/PROVIDER**"
    assert_contains "$doc_name records Antigravity Pass" "$doc" 'Antigravity CLI (`agy`) 1.1.27 | **CERT_PASS**'
    assert_contains "$doc_name records Cursor Pass" "$doc" "Cursor | **CERT_PASS**"
    assert_contains "$doc_name records Continue Pass" "$doc" "Continue for VS Code | **CERT_PASS**"
    assert_contains "$doc_name records OpenCode Pass" "$doc" "OpenCode | **CERT_PASS**"
    assert_contains "$doc_name records Command Code Pass" "$doc" "Command Code 1.51.0 | **CERT_PASS**"
    assert_contains "$doc_name records Copilot Pass" "$doc" "GitHub Copilot in VS Code | **CERT_PASS**"
    assert_contains "$doc_name records Muse Pass" "$doc" "Muse Code 1.0.3 | **CERT_PASS**"
    assert_contains "$doc_name identifies Muse as standalone" "$doc" "standalone"
    assert_contains "$doc_name keeps Muse Spark out of the client roster" "$doc" "Muse Spark"
done

assert_not_contains "current integration guide has no stale no-certified claim" "$AI_DOC" "No client is marked Certified"
assert_not_contains "Windsurf has no current supported-client row" "$AI_DOC" "| Windsurf |"
assert_contains "PROJECT_STATUS carries the fresh-user closeout" "$STATUS_TOP" "Final fresh-user onboarding closeout M22/M23/M25"
assert_contains "RELEASE_HANDOFF carries the fresh-user closeout" "$HANDOFF_TOP" "Final fresh-user onboarding closeout M22/M23/M25"
assert_contains "PROJECT_STATUS records Copilot actual-client completion" "$STATUS_TOP" "Copilot Agent"
assert_contains "RELEASE_HANDOFF records Copilot actual-client completion" "$HANDOFF_TOP" "Copilot Agent"

if git -C "$PLUGIN_DIR" diff --quiet -- docs/archive; then
    pass "historical archive evidence was not rewritten"
else
    fail "historical archive evidence was modified"
fi

echo "== Summary =="
echo "  $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
