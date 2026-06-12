#!/usr/bin/env bash
#
# Security hardening test suite for WP Command Center (Step 10).
#
# Verifies the credential protection & redaction layer:
#
#   - PathGuard deny-pattern coverage (.env, vendor/, etc.) and the
#     wpcc_file_blocked error code / HTTP 403
#   - /search skips blocked files
#   - normal file reads are unaffected
#   - secret-like strings are redacted in /files/content,
#     /diagnostics/debug-log, /context, and /agent/context, with
#     redacted/redaction_count metadata
#   - audit log records security.file_blocked and
#     security.content_redacted
#   - the full E2E runtime suite still passes
#
# Requires: curl, jq, wp-cli, and wpcc-env.sh (sourced from this plugin's
# root) providing $WPCC_BASE and a *full*-scope $WPCC_TOKEN.
#
# Usage: bash tests/test-security-redaction.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_CONTENT_DIR="$(cd "$PLUGIN_DIR/../.." && pwd)"
WP_ROOT="$(cd "$WP_CONTENT_DIR/.." && pwd)"

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

assert_contains() {
	local desc="$1" haystack="$2" needle="$3"
	if [[ "$haystack" == *"$needle"* ]]; then
		pass "$desc"
	else
		fail "$desc (expected to contain '$needle')"
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

present() {
	if [ -n "$1" ]; then echo "true"; else echo "false"; fi
}

api() {
	# api METHOD PATH [JSON_BODY]
	local method="$1" path="$2" body="${3:-}"

	if [ -n "$body" ]; then
		curl -s -X "$method" -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$body" "$WPCC_BASE$path"
	else
		curl -s -X "$method" -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE$path"
	fi
}

api_status() {
	# api_status METHOD PATH
	curl -s -o /dev/null -w '%{http_code}' -X "$1" -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE$2"
}

FIXTURE_DIR="$WP_CONTENT_DIR/mu-plugins/wpcc-test-redaction"
DEBUG_LOG="$WP_CONTENT_DIR/debug.log"
DEBUG_LOG_BACKUP=""

cleanup() {
	rm -rf "$FIXTURE_DIR"

	if [ -n "$DEBUG_LOG_BACKUP" ] && [ -f "$DEBUG_LOG_BACKUP" ]; then
		cat "$DEBUG_LOG_BACKUP" > "$DEBUG_LOG"
		rm -f "$DEBUG_LOG_BACKUP"
	fi
}
trap cleanup EXIT

echo "== Setup =="

mkdir -p "$FIXTURE_DIR/vendor"

ENV_REL="mu-plugins/wpcc-test-redaction/.env"
printf 'OPENAI_API_KEY=sk-test-blocked-env-1234567890\n' > "$FIXTURE_DIR/.env"
pass "fixture: .env file created"

NORMAL_REL="mu-plugins/wpcc-test-redaction/normal.txt"
printf 'Hello world\n' > "$FIXTURE_DIR/normal.txt"
pass "fixture: normal.txt file created"

SECRET_REL="mu-plugins/wpcc-test-redaction/notes.php"
printf '<?php\n// Found a leaked credential: sk-test1234567890ABCDEFGHIJ1234567890\necho "ok";\n' > "$FIXTURE_DIR/notes.php"
pass "fixture: notes.php file created"

VENDOR_MARKER="WPCC_VENDOR_SECRET_MARKER_12345"
printf "<?php\n// %s\n" "$VENDOR_MARKER" > "$FIXTURE_DIR/vendor/leaked.php"
pass "fixture: vendor/leaked.php file created"

if [ -f "$DEBUG_LOG" ]; then
	DEBUG_LOG_BACKUP="$(mktemp /tmp/wpcc-debug-log-backup-XXXXXX)"
	cat "$DEBUG_LOG" > "$DEBUG_LOG_BACKUP"
	pass "fixture: debug.log backed up"
else
	fail "fixture: debug.log not found at $DEBUG_LOG"
fi

echo
echo "== 1. Blocked file read fails =="

ENV_READ=$(api GET "/files/content?path=$ENV_REL")
ENV_STATUS=$(api_status GET "/files/content?path=$ENV_REL")

assert_eq "blocked read: error code is wpcc_file_blocked" "wpcc_file_blocked" "$(echo "$ENV_READ" | jq -r '.code // empty')"
assert_eq "blocked read: HTTP status is 403" "403" "$ENV_STATUS"

echo
echo "== 2. Blocked file search skips result =="

VENDOR_SEARCH=$(api GET "/search?q=$VENDOR_MARKER&path=mu-plugins/wpcc-test-redaction")
assert_eq "blocked search: vendor/ file produces no matches" "0" "$(echo "$VENDOR_SEARCH" | jq -r '.match_count // empty')"
assert_true "blocked search: matches array is empty" "$(echo "$VENDOR_SEARCH" | jq -r '(.matches | length) == 0')"

echo
echo "== 3. Normal file read still works =="

NORMAL_READ=$(api GET "/files/content?path=$NORMAL_REL")
assert_eq "normal read: contents are unchanged" "Hello world" "$(echo "$NORMAL_READ" | jq -r '.contents // empty' | tr -d '\n')"
assert_eq "normal read: no redacted flag" "" "$(echo "$NORMAL_READ" | jq -r '.redacted // empty')"

echo
echo "== 4. Secret-like string is redacted (file content) =="

SECRET_READ=$(api GET "/files/content?path=$SECRET_REL")
SECRET_CONTENTS=$(echo "$SECRET_READ" | jq -r '.contents // empty')

assert_contains "file redaction: contents contain placeholder" "$SECRET_CONTENTS" "[REDACTED_SECRET]"
assert_not_contains "file redaction: raw key no longer present" "$SECRET_CONTENTS" "sk-test1234567890ABCDEFGHIJ1234567890"
assert_true "file redaction: redacted flag is true" "$(echo "$SECRET_READ" | jq -r '.redacted // false')"
assert_true "file redaction: redaction_count >= 1" "$(echo "$SECRET_READ" | jq -r '(.redaction_count // 0) >= 1')"

echo
echo "== 5. Debug log secret is redacted =="

printf '[10-Jun-2026 12:00:00 UTC] PHP Warning: smtp connection failed, password=SuperSecretPass123 - retrying\n' >> "$DEBUG_LOG"

DEBUG_RESULT=$(api GET "/diagnostics/debug-log?lines=50")
DEBUG_LINE=$(echo "$DEBUG_RESULT" | jq -r '.lines[] | select(.text | contains("smtp connection failed")) | .text')

assert_contains "debug log redaction: secret line contains placeholder" "$DEBUG_LINE" "[REDACTED_SECRET]"
assert_not_contains "debug log redaction: raw password no longer present" "$DEBUG_LINE" "SuperSecretPass123"
assert_true "debug log redaction: redacted flag is true" "$(echo "$DEBUG_RESULT" | jq -r '.redacted // false')"
assert_true "debug log redaction: redaction_count >= 1" "$(echo "$DEBUG_RESULT" | jq -r '(.redaction_count // 0) >= 1')"

echo
echo "== 6. Context redaction =="

CONTEXT=$(api GET "/context")
assert_true "context: endpoint still returns wordpress info" "$(echo "$CONTEXT" | jq -r '(.wordpress.version // "") | length > 0')"

REDACTOR_TEST_PHP="$(mktemp /tmp/wpcc-redactor-test-XXXXXX.php)"
cat > "$REDACTOR_TEST_PHP" <<'PHP'
<?php
$redactor = new \WPCommandCenter\Security\Redactor();

$sample = [
	'site_summary' => [
		'note' => 'Found AWS key AKIAFAKEID01234567890 and Stripe key sk_fake_test_4eC39HqLyjWDarjtT1zdp7dc in config.',
	],
	'context' => [
		'private_key' => "-----BEGIN RSA PRIVATE KEY-----\nMIIBOwIBAAJBAKj34GkxFhD90vcNLYLInFEX6Ppy1tPf9Cnzj4p4WGeKLs1Pt8Qu\n-----END RSA PRIVATE KEY-----",
	],
];

$result = $redactor->redact_recursive( $sample );

echo wp_json_encode( [
	'count' => $result['count'],
	'data'  => $result['data'],
] );
PHP

REDACTOR_RESULT=$(wp --path="$WP_ROOT" eval-file "$REDACTOR_TEST_PHP" 2>/dev/null)
rm -f "$REDACTOR_TEST_PHP"

assert_true "context redactor: count > 0" "$(echo "$REDACTOR_RESULT" | jq -r '(.count // 0) > 0')"

REDACTOR_NOTE=$(echo "$REDACTOR_RESULT" | jq -r '.data.site_summary.note // empty')
assert_contains "context redactor: AWS/Stripe keys replaced" "$REDACTOR_NOTE" "[REDACTED_SECRET]"
assert_not_contains "context redactor: raw AWS key removed" "$REDACTOR_NOTE" "AKIAFAKEID01234567890"
assert_not_contains "context redactor: raw Stripe key removed" "$REDACTOR_NOTE" "sk_fake_test_4eC39HqLyjWDarjtT1zdp7dc"

REDACTOR_PEM=$(echo "$REDACTOR_RESULT" | jq -r '.data.context.private_key // empty')
assert_contains "context redactor: PEM block replaced" "$REDACTOR_PEM" "[REDACTED_SECRET]"
assert_not_contains "context redactor: raw PEM header removed" "$REDACTOR_PEM" "BEGIN RSA PRIVATE KEY"

echo
echo "== 7. Agent context redaction =="

SESSION_CREATE=$(api POST /agent/sessions '{"source":"codex","label":"Security redaction test session"}')
SESSION_ID=$(echo "$SESSION_CREATE" | jq -r '.session_id // empty')

AWS_MARKER="AKIAABCDEFGHIJKLMNOP"
TASK_BODY=$(jq -n --arg session_id "$SESSION_ID" --arg prompt "Please rotate the leaked AWS key $AWS_MARKER found in vendor config." \
	'{session_id:$session_id,source:"codex",user_prompt:$prompt}')
TASK_CREATE=$(api POST /agent/tasks "$TASK_BODY")
TASK_ID=$(echo "$TASK_CREATE" | jq -r '.task_id // empty')

AGENT_CONTEXT=$(api GET "/agent/context?session_id=$SESSION_ID")
TASK_PROMPT=$(echo "$AGENT_CONTEXT" | jq -r --arg tid "$TASK_ID" '.session_tasks[] | select(.task_id == $tid) | .user_prompt // empty')

assert_contains "agent context redaction: user_prompt contains placeholder" "$TASK_PROMPT" "[REDACTED_SECRET]"
assert_not_contains "agent context redaction: raw AWS key removed" "$TASK_PROMPT" "$AWS_MARKER"
assert_true "agent context redaction: redacted flag is true" "$(echo "$AGENT_CONTEXT" | jq -r '.redacted // false')"
assert_true "agent context redaction: redaction_count >= 1" "$(echo "$AGENT_CONTEXT" | jq -r '(.redaction_count // 0) >= 1')"

echo
echo "== 8. Audit log =="

AUDIT_LOG="$WP_CONTENT_DIR/uploads/wpcc-audit/audit.log"

if [ ! -r "$AUDIT_LOG" ]; then
	fail "audit log: file not readable at $AUDIT_LOG"
else
	FILE_BLOCKED=$(jq -c --arg path "$ENV_REL" \
		'select(.action == "security.file_blocked" and .context.path == $path)' "$AUDIT_LOG" | tail -1)
	assert_true "audit log: security.file_blocked recorded" "$(present "$FILE_BLOCKED")"
	assert_eq "audit log: security.file_blocked endpoint is files/content" "files/content" "$(echo "$FILE_BLOCKED" | jq -r '.context.endpoint // empty')"

	CONTENT_REDACTED=$(jq -c \
		'select(.action == "security.content_redacted" and .context.endpoint == "files/content")' "$AUDIT_LOG" | tail -1)
	assert_true "audit log: security.content_redacted recorded" "$(present "$CONTENT_REDACTED")"
	assert_true "audit log: security.content_redacted has count >= 1" "$(echo "$CONTENT_REDACTED" | jq -r '(.context.count // 0) >= 1')"
fi

echo
echo "== 9. Full runtime regression =="

if bash "$SCRIPT_DIR/test-e2e-runtime.sh" > /tmp/wpcc-e2e-runtime-output.log 2>&1; then
	pass "full runtime suite (test-e2e-runtime.sh) still passes"
else
	fail "full runtime suite (test-e2e-runtime.sh) failed, see /tmp/wpcc-e2e-runtime-output.log"
fi

echo
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"

[ "$FAIL" -eq 0 ]
