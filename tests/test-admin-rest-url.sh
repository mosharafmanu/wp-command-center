#!/usr/bin/env bash
# Default-permalink admin REST URL composition regression.

set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }

echo "Admin REST URLs — default and pretty permalink compatibility"

for file in includes/Admin/views/approval-center.php includes/Admin/views/change-history.php includes/Admin/views/token-capability-manager.php; do
	if rg -q "queryAt = path.indexOf\( '\?' \)" "$file" && rg -q "apiBase.indexOf\( '\?' \)" "$file"; then
		pass "$(basename "$file") merges path queries into query-style rest_url"
	else
		fail "$(basename "$file") lacks query-style rest_url handling"
	fi
	if rg -q "fetch\( apiBase \+ path" "$file"; then
		fail "$(basename "$file") still concatenates an unsafe second question mark"
	else
		pass "$(basename "$file") fetches the normalized URL"
	fi
done

node <<'NODE'
function apiUrl(apiBase, path) {
	const queryAt = path.indexOf('?');
	return queryAt !== -1 && apiBase.indexOf('?') !== -1
		? apiBase + path.slice(0, queryAt) + '&' + path.slice(queryAt + 1)
		: apiBase + path;
}
const query = apiUrl('https://example.test/index.php?rest_route=/wp-command-center/v1/admin', '/history?limit=20');
const pretty = apiUrl('https://example.test/wp-json/wp-command-center/v1/admin', '/history?limit=20');
if (query !== 'https://example.test/index.php?rest_route=/wp-command-center/v1/admin/history&limit=20') process.exit(1);
if (pretty !== 'https://example.test/wp-json/wp-command-center/v1/admin/history?limit=20') process.exit(1);
NODE
if [ "$?" -eq 0 ]; then
	pass "URL normalizer preserves both WordPress REST URL forms"
else
	fail "URL normalizer output"
fi

echo "RESULT: ${PASS} passed, ${FAIL} failed"
[ "$FAIL" -eq 0 ]
