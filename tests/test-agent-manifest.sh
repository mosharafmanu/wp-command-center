#!/usr/bin/env bash
#
# Agent discovery / manifest test suite for WP Command Center (Step 11).
#
# Verifies GET /agent/manifest:
#
#   - top-level shape: plugin, capabilities, security, workflow, endpoints,
#     error_catalog, capability_negotiation, versions, manifest_version,
#     manifest_hash
#   - endpoint catalog includes self-discovery and known agent routes with
#     method/path/scope/description
#   - capability catalog matches the spec exactly
#   - workflow catalog matches the spec's ordered list exactly
#   - error catalog includes the required wpcc_* codes
#   - version information (plugin_version, api_version, db_version)
#   - manifest_hash is a stable, deterministic sha256 across repeated calls
#   - GET /agent/context exposes manifest_version/manifest_hash matching
#     GET /agent/manifest
#   - no file contents, secrets, tokens, or customer data are exposed
#
# Requires: curl, jq, and wpcc-env.sh (sourced from this plugin's root)
# providing $WPCC_BASE and $WPCC_TOKEN.
#
# Usage: bash tests/test-agent-manifest.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

# shellcheck source=/dev/null
source "$PLUGIN_DIR/wpcc-env.sh"

PASS=0
FAIL=0

pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }

assert_eq() {
	local desc="$1" expected="$2" actual="$3"
	if [ "$expected" = "$actual" ]; then
		pass "$desc"
	else
		fail "$desc (expected '$expected', got '$actual')"
	fi
}

assert_true() {
	local desc="$1" actual="$2"
	if [ "$actual" = "true" ]; then
		pass "$desc"
	else
		fail "$desc (expected 'true', got '$actual')"
	fi
}

assert_not_contains() {
	local desc="$1" haystack="$2" needle="$3"
	if [[ "$haystack" != *"$needle"* ]]; then
		pass "$desc"
	else
		fail "$desc (did not expect to contain '$needle')"
	fi
}

api() {
	# api METHOD PATH
	curl -s -X "$1" -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE$2"
}

api_status() {
	# api_status METHOD PATH
	curl -s -o /dev/null -w '%{http_code}' -X "$1" -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE$2"
}

echo "== 1. Manifest endpoint =="

MANIFEST=$(api GET /agent/manifest)
MANIFEST_STATUS=$(api_status GET /agent/manifest)

assert_eq "manifest: HTTP status is 200" "200" "$MANIFEST_STATUS"
assert_eq "manifest: plugin.name" "WP Command Center" "$(echo "$MANIFEST" | jq -r '.plugin.name // empty')"
assert_eq "manifest: plugin.api_version" "v1" "$(echo "$MANIFEST" | jq -r '.plugin.api_version // empty')"
assert_true "manifest: plugin.version non-empty" "$(echo "$MANIFEST" | jq -r '(.plugin.version // "") | length > 0')"
assert_true "manifest: plugin.db_version non-empty" "$(echo "$MANIFEST" | jq -r '(.plugin.db_version // "") | length > 0')"
assert_true "manifest: top-level keys present" "$(echo "$MANIFEST" | jq -r '
	(.capabilities != null)
	and (.security != null)
	and (.workflow != null)
	and (.endpoints != null)
	and (.error_catalog != null)
	and (.capability_negotiation != null)
	and (.versions != null)
	and (.manifest_version != null)
	and (.manifest_hash != null)
')"

echo
echo "== 2. Endpoint catalog =="

assert_true "endpoints: non-empty array" "$(echo "$MANIFEST" | jq -r '(.endpoints | length) > 0')"
assert_true "endpoints: every entry has method/path/scope/description" "$(echo "$MANIFEST" | jq -r '
	[.endpoints[] | (has("method") and has("path") and has("scope") and has("description"))] | all
')"

PLAN_APPROVE=$(echo "$MANIFEST" | jq -c '.endpoints[] | select(.path == "/agent/plans/{id}/approve")')
assert_eq "endpoints: /agent/plans/{id}/approve method is POST" "POST" "$(echo "$PLAN_APPROVE" | jq -r '.method // empty')"
assert_eq "endpoints: /agent/plans/{id}/approve scope is full" "full" "$(echo "$PLAN_APPROVE" | jq -r '.scope // empty')"
assert_true "endpoints: /agent/plans/{id}/approve has description" "$(echo "$PLAN_APPROVE" | jq -r '(.description // "") | length > 0')"

SELF_ENTRY=$(echo "$MANIFEST" | jq -c '.endpoints[] | select(.path == "/agent/manifest")')
assert_eq "endpoints: /agent/manifest is self-discoverable" "GET" "$(echo "$SELF_ENTRY" | jq -r '.method // empty')"
assert_eq "endpoints: /agent/manifest scope is read_only" "read_only" "$(echo "$SELF_ENTRY" | jq -r '.scope // empty')"

ROLLBACK_ENTRY=$(echo "$MANIFEST" | jq -c '.endpoints[] | select(.path == "/patches/{id}/rollback")')
assert_eq "endpoints: /patches/{id}/rollback method is POST" "POST" "$(echo "$ROLLBACK_ENTRY" | jq -r '.method // empty')"
assert_eq "endpoints: /patches/{id}/rollback scope is full" "full" "$(echo "$ROLLBACK_ENTRY" | jq -r '.scope // empty')"

echo
echo "== 3. Capability catalog =="

EXPECTED_CAPABILITIES='{"actions":true,"ai_clients":true,"capability_management":true,"claude_integration":true,"cleanup":true,"code_search":true,"content_management":true,"cpt_management":true,"database_inspection":true,"diagnostics":true,"environment_management":true,"file_access":true,"health_verification":true,"mcp_server":true,"option_management":true,"patches":true,"plan_approval":true,"plans":true,"plugin_management":true,"recommendations":true,"rollback":true,"sessions":true,"site_intelligence":true,"snapshot_management":true,"tasks":true,"theme_management":true,"widgets_management":true,"wp_cli_operations":true}'
ACTUAL_CAPABILITIES=$(echo "$MANIFEST" | jq -cS '.capabilities')
EXPECTED_CAPABILITIES_SORTED=$(echo "$EXPECTED_CAPABILITIES" | jq -cS '.')
assert_eq "capabilities: matches spec exactly" "$EXPECTED_CAPABILITIES_SORTED" "$ACTUAL_CAPABILITIES"

EXPECTED_SECURITY='{"human_approval_required":false,"patch_auto_apply":false,"rollback_supported":true,"secret_redaction":true}'
ACTUAL_SECURITY=$(echo "$MANIFEST" | jq -cS '.security')
EXPECTED_SECURITY_SORTED=$(echo "$EXPECTED_SECURITY" | jq -cS '.')
assert_eq "security: matches spec exactly" "$EXPECTED_SECURITY_SORTED" "$ACTUAL_SECURITY"

echo
echo "== 4. Workflow catalog =="

EXPECTED_WORKFLOW='["session","task","action","plan","plan_approval","patch","patch_approval","apply","rollback"]'
ACTUAL_WORKFLOW=$(echo "$MANIFEST" | jq -c '.workflow')
assert_eq "workflow: matches spec ordered list exactly" "$EXPECTED_WORKFLOW" "$ACTUAL_WORKFLOW"

echo
echo "== 5. Error catalog =="

for code in wpcc_file_blocked wpcc_plan_not_found wpcc_plan_not_approved wpcc_session_not_found wpcc_task_not_found; do
	desc=$(echo "$MANIFEST" | jq -r --arg c "$code" '.error_catalog[$c] // empty')
	assert_true "error_catalog: $code has a description" "$( [ -n "$desc" ] && echo true || echo false )"
done

assert_true "error_catalog: at least 60 known codes" "$(echo "$MANIFEST" | jq -r '(.error_catalog | length) >= 60')"

echo
echo "== 6. Version information =="

assert_eq "versions: plugin_version matches /health" "$(api GET /health | jq -r '.plugin_version')" "$(echo "$MANIFEST" | jq -r '.versions.plugin_version')"
assert_eq "versions: api_version is v1" "v1" "$(echo "$MANIFEST" | jq -r '.versions.api_version')"
assert_true "versions: db_version non-empty" "$(echo "$MANIFEST" | jq -r '(.versions.db_version // "") | length > 0')"
assert_eq "versions: plugin.version matches versions.plugin_version" "$(echo "$MANIFEST" | jq -r '.versions.plugin_version')" "$(echo "$MANIFEST" | jq -r '.plugin.version')"
assert_eq "versions: plugin.db_version matches versions.db_version" "$(echo "$MANIFEST" | jq -r '.versions.db_version')" "$(echo "$MANIFEST" | jq -r '.plugin.db_version')"

echo
echo "== 7. Capability negotiation =="

assert_true "capability_negotiation: shell_exec is boolean" "$(echo "$MANIFEST" | jq -r '(.capability_negotiation.shell_exec | type) == "boolean"')"
assert_true "capability_negotiation: proc_open is boolean" "$(echo "$MANIFEST" | jq -r '(.capability_negotiation.proc_open | type) == "boolean"')"
assert_true "capability_negotiation: wp_cli is boolean" "$(echo "$MANIFEST" | jq -r '(.capability_negotiation.wp_cli | type) == "boolean"')"
assert_true "capability_negotiation: file_access is true" "$(echo "$MANIFEST" | jq -r '.capability_negotiation.file_access == true')"
assert_true "capability_negotiation: patch_apply is true" "$(echo "$MANIFEST" | jq -r '.capability_negotiation.patch_apply == true')"
assert_true "capability_negotiation: rollback is true" "$(echo "$MANIFEST" | jq -r '.capability_negotiation.rollback == true')"

echo
echo "== 8. Manifest hash generation =="

HASH1=$(echo "$MANIFEST" | jq -r '.manifest_hash')
HASH2=$(api GET /agent/manifest | jq -r '.manifest_hash')

assert_true "manifest_hash: looks like a sha256 hex digest" "$(echo "$HASH1" | grep -Eq '^[a-f0-9]{64}$' && echo true || echo false)"
assert_eq "manifest_hash: stable across repeated calls" "$HASH1" "$HASH2"
assert_true "manifest_version: non-empty" "$(echo "$MANIFEST" | jq -r '(.manifest_version // "") | length > 0')"

echo
echo "== 9. Agent context integration =="

AGENT_CONTEXT=$(api GET /agent/context)

assert_eq "agent/context: manifest_version matches /agent/manifest" "$(echo "$MANIFEST" | jq -r '.manifest_version')" "$(echo "$AGENT_CONTEXT" | jq -r '.manifest_version // empty')"
assert_eq "agent/context: manifest_hash matches /agent/manifest" "$HASH1" "$(echo "$AGENT_CONTEXT" | jq -r '.manifest_hash // empty')"

echo
echo "== 10. No sensitive data exposed =="

MANIFEST_RAW=$(echo "$MANIFEST" | jq -c '.')

assert_true "manifest: no top-level 'contents' key" "$(echo "$MANIFEST" | jq -r 'has("contents") | not')"
assert_true "manifest: no 'tokens' key" "$(echo "$MANIFEST" | jq -r 'has("tokens") | not')"
assert_not_contains "manifest: does not leak the request bearer token" "$MANIFEST_RAW" "$WPCC_TOKEN"

echo
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"

[ "$FAIL" -eq 0 ]
