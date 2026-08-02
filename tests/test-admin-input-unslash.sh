#!/usr/bin/env bash
#
# WordPress slashes $_POST. Any admin form value that is passed on to an operation must
# be wp_unslash()ed first, or the operation receives the escaped form.
#
# Found in the Search & Replace tool: the form REDISPLAY unslashed `search` and
# `replace`, but the values actually sent to the safe_search_replace operation did not.
# Searching for O'Brien queried for O\'Brien and matched nothing; a replacement
# containing a quote or backslash wrote the escaped form into the customer's content.
#
# Static, because the bug is a missing call on a specific line rather than a runtime
# state — and it is exactly the kind of omission that reappears when a view is edited.

set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_ROOT="$(cd "$PLUGIN_DIR/../../.." && pwd)"
cd "$PLUGIN_DIR"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }

SR="$PLUGIN_DIR/includes/Admin/views/tools-search-replace.php"

echo "admin input unslashing"

# --- the payload actually handed to the operation ---------------------------------
for f in search replace; do
  if grep -qE "\\\$$f *= *\(string\) *wp_unslash\( *\\\$_POST\['$f'\]" "$SR"; then
    pass "tools-search-replace unslashes '$f' before sending it"
  else
    fail "tools-search-replace unslashes '$f' before sending it"
  fi
done

grep -q "array_map( 'wp_unslash'" "$SR" \
  && pass "tools-search-replace unslashes the table list" \
  || fail "tools-search-replace unslashes the table list"

# --- and it must NOT sanitize the strings themselves -------------------------------
# This tool replaces arbitrary content, including markup. sanitize_text_field() on the
# search or replace value would silently corrupt the operator's intent.
if grep -qE "\\\$(search|replace) *= *\(string\) *sanitize_text_field" "$SR"; then
  fail "search/replace values are left unsanitized (they are bound by prepare, not interpolated)"
else
  pass "search/replace values are left unsanitized (they are bound by prepare, not interpolated)"
fi

# --- round trip through WordPress own slashing --------------------------------------
PHPTMP="$(mktemp -t wpccunslash)"
cat > "$PHPTMP" <<'PHPEOF'
<?php
$literal = "O" . chr(39) . "Brien " . chr(92) . " " . chr(34) . "q" . chr(34);
$_POST   = [ "search" => addslashes( $literal ) ];
echo ( wp_unslash( $_POST["search"] ) === $literal ) ? "ok" : "mismatch";
PHPEOF
ROUND=$(wp --path="$WP_ROOT" eval-file "$PHPTMP" 2>/dev/null)
rm -f "$PHPTMP"
assert_eq "wp_unslash restores the operator literal string" "ok" "$ROUND"

# --- the operation binds rather than interpolates ------------------------------------
grep -q 'prepare(' "$PLUGIN_DIR/includes/Operations/SearchReplace.php" \
  && pass "SearchReplace binds its values with prepare()" \
  || fail "SearchReplace binds its values with prepare()"

echo
echo "admin input unslashing: $PASS passed / $FAIL failed"
[ "$FAIL" -eq 0 ]
