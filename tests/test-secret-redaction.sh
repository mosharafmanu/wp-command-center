#!/usr/bin/env bash
#
# The Redactor must redact THIS plugin's own credential, not only other vendors'.
#
# Every third-party format was covered — PEM, JWT, AWS, Anthropic, OpenAI, Stripe —
# while `wpcc_<64 chars>`, the one credential this codebase mints and the only one
# that unlocks this very API, was returned in clear. Measured: a READ-ONLY token
# calling GET /files/content on a file under plugins/ got back a FULL-ACCESS token
# and a plaintext password, while the Anthropic key in the same file was redacted.
# That is a read-only -> full privilege escalation, so it gets a suite of its own.
#
# The Redactor is the single redaction point for McpServerRuntime, DatabaseInspector,
# FileManager and OpenAiCompatibleTransport, so a gap here is a gap on every one.
#
# Two halves, and the second matters as much as the first:
#   1. MUST REDACT   — the formats and key names that carry secrets.
#   2. MUST NOT TOUCH — prose, table names, option keys, and the 12-character token
#                       preview the tokens screen displays ON PURPOSE. Widening the
#                       key list is only safe if it stays anchored to a separator;
#                       these assertions are what prove it did not become a blunt
#                       instrument that redacts the word "password" in a sentence.
#
# Requires wp-cli + a reachable local site (wpcc-env.sh).

set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
source "$SCRIPT_DIR/../wpcc-env.sh"
cd "$PLUGIN_DIR"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }

echo "secret redaction"
echo
echo "== 1. Redactor unit behaviour =="

# Every value below is SYNTHETIC. Never put a real credential in a test.
OUT="$( wp --path="$WP_ROOT" eval '
$r = new \WPCommandCenter\Security\Redactor();

$must_redact = [
	"wpcc_bare"          => "wpcc_AAAABBBBCCCCDDDDEEEEFFFFGGGGHHHHIIIIJJJJKKKKLLLLMMMMNNNNOOOOPPPP",
	"wpcc_exported"      => "export WPCC_TOKEN=wpcc_AAAABBBBCCCCDDDDEEEEFFFFGGGGHHHHIIIIJJJJKKKKLLLLMMMMNNNNOOOOPPPP",
	"wpcc_quoted"        => "define( \"WPCC_TOKEN\", \"wpcc_AAAABBBBCCCCDDDDEEEEFFFFGGGGHHHHIIIIJJJJKKKKLLLLMMMMNNNNOOOOPPPP\" );",
	"token_assign"       => "token = hunter2hunter2",
	"token_yaml"         => "token: abcd1234efgh5678",
	"pass_comment"       => "# Admin Pass: SuperSecret123",
	"password_assign"    => "password = SuperSecret123",
	"anthropic_key"      => "sk-ant-api03-AAAAAAAAAAAAAAAAAAAAAAAAAAAA",
	"openai_key"         => "sk-AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA",
	"bearer_header"      => "Authorization: Bearer abcdefghijklmnop",
];

// Things that merely LOOK secret-adjacent and must survive untouched.
$must_keep = [
	"prose_token"        => "Your access token is shown once.",
	"prose_pass"         => "The tests all pass now.",
	"prose_password"     => "Choose a password you will remember.",
	"table_name"         => "SELECT * FROM wp_wpcc_change_log",
	"option_key"         => "get_option( \"wpcc_security_mode\" )",
	"token_preview"      => "wpcc_yMykgPf...",
	"short_wpcc_prefix"  => "wpcc_operation_requests",
];

foreach ( $must_redact as $k => $in ) {
	$o = $r->redact( $in );
	$t = is_array( $o ) ? ( $o["text"] ?? "" ) : $o;
	echo "REDACT\t$k\t" . ( str_contains( (string) $t, "REDACTED_SECRET" ) ? "yes" : "no" ) . "\n";
}
foreach ( $must_keep as $k => $in ) {
	$o = $r->redact( $in );
	$t = is_array( $o ) ? ( $o["text"] ?? "" ) : $o;
	echo "KEEP\t$k\t" . ( str_contains( (string) $t, "REDACTED_SECRET" ) ? "no" : "yes" ) . "\n";
}
' 2>/dev/null )"

while IFS=$'\t' read -r kind name ok; do
	[ -z "${kind:-}" ] && continue
	case "$kind" in
		REDACT) [ "$ok" = "yes" ] && pass "redacts $name" || fail "redacts $name (LEAKED)";;
		KEEP)   [ "$ok" = "yes" ] && pass "leaves $name alone" || fail "leaves $name alone (FALSE POSITIVE)";;
	esac
done <<< "$OUT"

echo
echo "== 2. Pattern is present in the source (cannot be silently dropped) =="
if grep -q 'wpcc_\[A-Za-z0-9\]{40,}' includes/Security/Redactor.php; then
	pass "wpcc_ token pattern registered"
else
	fail "wpcc_ token pattern registered"
fi
# The threshold is load-bearing: below ~40 it would start eating table names,
# option keys and the deliberate 12-character preview.
if grep -qE '\|pass\||\|token\)' includes/Security/Redactor.php; then
	pass "bare token/pass key names registered"
else
	fail "bare token/pass key names registered"
fi

echo
echo "== 3. Live: a read-only surface cannot return a full-access token =="
# Round-trips the real escalation path rather than trusting the unit above:
# write a synthetic credential into a readable file under plugins/, read it back
# through the file API, and assert the value never survives the trip.
PROBE_DIR="$PLUGIN_DIR/artifacts/redaction-probe"
PROBE="$PROBE_DIR/probe.txt"
mkdir -p "$PROBE_DIR"
FAKE="wpcc_ZZZZYYYYXXXXWWWWVVVVUUUUTTTTSSSSRRRRQQQQPPPPOOOONNNNMMMMLLLLKKKK"
{
	echo "export WPCC_TOKEN=$FAKE"
	echo "# Admin Pass: NotARealPassword123"
} > "$PROBE"

BODY="$( curl -s -G "$WPCC_BASE/files/content" \
	--data-urlencode "path=plugins/ai-command-center/artifacts/redaction-probe/probe.txt" \
	-H "Authorization: Bearer $WPCC_TOKEN" )"

if echo "$BODY" | grep -q "$FAKE"; then
	fail "synthetic wpcc_ token is redacted on file read"
else
	pass "synthetic wpcc_ token is redacted on file read"
fi
if echo "$BODY" | grep -q "NotARealPassword123"; then
	fail "synthetic password is redacted on file read"
else
	pass "synthetic password is redacted on file read"
fi
if echo "$BODY" | grep -q "REDACTED_SECRET"; then
	pass "redaction marker present in the response"
else
	fail "redaction marker present in the response"
fi

rm -rf "$PROBE_DIR"

echo
echo "RESULT: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
