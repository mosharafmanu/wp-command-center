#!/usr/bin/env bash
# Workstream 4 — the Token usage panel reports what the provider actually said.
#
# Built-in AI has always shown a "Token usage & cost" panel and it has always read "Not
# tracked yet" — including to a customer who had just run real generations and would be
# billed for them. The number was never missing from the provider: Anthropic returns a
# `usage` block on every Messages response and OpenAI-compatible endpoints return one
# too. The transports decoded the response, took content[0].text, and dropped the rest.
#
# Usage now rides the neutral contract (GenerationUsage on GenerationResult), AiRuntime
# meters every attributed call at the one point all generation passes through, and
# UsageLedger aggregates counts. COST is still not shown, on purpose: no versioned price
# list ships with the product, so any figure would be a guess that could be reconciled
# against a real invoice and found wrong.
#
# The generation chain here is REAL — real AiRuntime, real AnthropicClient, real
# AnthropicTransport, real ledger — driven through AiHttpClient's injectable sender with
# canned wire responses. No network, and no production-only fake output.
#
# Covered here:
#   1. GenerationUsage normalization: both provider vocabularies, omitted, malformed.
#   2. "Not reported" stays distinct from "zero" — the honesty this rests on.
#   3. A real end-to-end Anthropic call records real counts against feature/model.
#   4. A provider that omits usage counts the CALL but no fabricated tokens.
#   5. Repeated generations aggregate; features, providers and models stay separable.
#   6. A call refused before the wire is not metered at all.
#   7. The ledger stores counts only — no prompt, no generated text, no credential.
#   8. The panel's empty state and populated state, and cost reported as unavailable.
set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../wpcc-env.sh"
WP_PATH="$SCRIPT_DIR/../../../.."
VIEW="$SCRIPT_DIR/../includes/Admin/views/ai-setup.php"
PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; if [ "$e" = "$a" ]; then pass "$d"; else fail "$d (expected '$e', got '$a')"; fi; }

echo "Built-in AI token usage tracking (Workstream 4) — $(date)"
echo ""

PHPF="$(mktemp /tmp/wpcc-ws4-XXXXXX.php)"
cat > "$PHPF" <<'PHP'
<?php
use WPCommandCenter\Ai\AiRuntime;
use WPCommandCenter\Ai\AnthropicClient;
use WPCommandCenter\Ai\Contract\GenerationMessage;
use WPCommandCenter\Ai\Contract\GenerationRequest;
use WPCommandCenter\Ai\Contract\GenerationTextPart;
use WPCommandCenter\Ai\Contract\GenerationUsage;
use WPCommandCenter\Ai\Http\AiHttpClient;
use WPCommandCenter\Ai\Platform\UsageLedger;
use WPCommandCenter\Ai\Transport\AnthropicTransport;

// Snapshot every option this suite writes, and put them all back on the way out —
// including the developer's real provider key, which the metering path reads.
$OPTS = [ 'wpcc_ai_usage', 'wpcc_anthropic_api_key', 'wpcc_ai_default_conn' ];
$SNAP = [];
foreach ( $OPTS as $o ) { $SNAP[ $o ] = get_option( $o, '__ABSENT__' ); }
register_shutdown_function( static function () use ( $OPTS, $SNAP ) {
	foreach ( $OPTS as $o ) {
		if ( '__ABSENT__' === $SNAP[ $o ] ) { delete_option( $o ); } else { update_option( $o, $SNAP[ $o ], false ); }
	}
} );

// ── 1 & 2. GenerationUsage normalization ────────────────────────────────────
$anthropic = GenerationUsage::from_provider( [ 'input_tokens' => 120, 'output_tokens' => 45, 'cache_read_input_tokens' => 12 ], 'msg_abc' );
echo 'anth_reported=' . ( $anthropic->is_reported() ? 'yes' : 'no' ) . "\n";
echo 'anth_in=' . $anthropic->input_tokens() . "\n";
echo 'anth_out=' . $anthropic->output_tokens() . "\n";
echo 'anth_cached=' . $anthropic->cached_tokens() . "\n";
echo 'anth_total=' . $anthropic->total_tokens() . "\n";
echo 'anth_reqid=' . $anthropic->request_id() . "\n";

$openai = GenerationUsage::from_provider( [ 'prompt_tokens' => 200, 'completion_tokens' => 60, 'total_tokens' => 260 ] );
echo 'oai_in=' . $openai->input_tokens() . "\n";
echo 'oai_out=' . $openai->output_tokens() . "\n";
echo 'oai_total=' . $openai->total_tokens() . "\n";

// Omitted / malformed: every one of these must be "we were not told", never a zero
// that would silently understate what the customer consumed.
foreach ( [ 'null' => null, 'string' => 'lots', 'empty' => [], 'junk' => [ 'foo' => 'bar' ], 'nonnum' => [ 'input_tokens' => 'many', 'output_tokens' => null ] ] as $label => $bad ) {
	echo 'bad_' . $label . '=' . ( GenerationUsage::from_provider( $bad )->is_reported() ? 'reported' : 'unknown' ) . "\n";
}
// Negative counts are clamped, not propagated.
$neg = GenerationUsage::from_provider( [ 'input_tokens' => -5, 'output_tokens' => 10 ] );
echo 'neg_in=' . $neg->input_tokens() . "\n";
echo 'neg_reported=' . ( $neg->is_reported() ? 'yes' : 'no' ) . "\n";
// A genuine zero IS reported — distinct from unknown.
$zero = GenerationUsage::from_provider( [ 'input_tokens' => 0, 'output_tokens' => 0 ] );
echo 'zero_reported=' . ( $zero->is_reported() ? 'yes' : 'no' ) . "\n";
echo 'unknown_reported=' . ( GenerationUsage::unknown()->is_reported() ? 'yes' : 'no' ) . "\n";

// ── 3-6. Real chain, canned wire responses ──────────────────────────────────
UsageLedger::reset();
update_option( 'wpcc_anthropic_api_key', 'sk-ant-test-0000000000000000000000000000000000000000', false );
delete_option( 'wpcc_ai_default_conn' ); // force the Anthropic path deterministically.

$reply = static function ( array $body ): callable {
	return static function ( $url, $args ) use ( $body ) {
		return [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( $body ) ];
	};
};
$run = static function ( callable $sender, string $feature, string $model ) {
	$runtime = new AiRuntime( new AnthropicClient( new AnthropicTransport( new AiHttpClient( $sender ) ) ) );
	return $runtime->generate( new GenerationRequest(
		$model, 300,
		[ new GenerationMessage( 'user', [ new GenerationTextPart( 'Summarize this post about hedgehogs.' ) ] ) ],
		30, [], [ 'feature' => $feature ]
	) );
};

// A provider that reports usage.
$r = $run( $reply( [
	'id'      => 'msg_01real',
	'content' => [ [ 'type' => 'text', 'text' => '{"title":"A real title"}' ] ],
	'usage'   => [ 'input_tokens' => 100, 'output_tokens' => 20 ],
] ), 'ai_content', 'claude-sonnet-4-6' );
echo 'e2e_ok=' . ( $r->is_ok() ? 'yes' : 'no' ) . "\n";
echo 'e2e_usage_reported=' . ( $r->usage()->is_reported() ? 'yes' : 'no' ) . "\n";
echo 'e2e_usage_total=' . $r->usage()->total_tokens() . "\n";
$s = UsageLedger::summary();
echo 'led_total=' . $s['total_tokens'] . "\n";
echo 'led_calls=' . $s['calls'] . "\n";
echo 'led_tracked=' . ( $s['tracked'] ? 'yes' : 'no' ) . "\n";
echo 'led_partial=' . ( $s['partial'] ? 'yes' : 'no' ) . "\n";
echo 'led_cost_available=' . ( $s['cost_available'] ? 'yes' : 'no' ) . "\n";

// A provider that omits usage: the CALL counts, the tokens do not.
$run( $reply( [ 'id' => 'msg_02', 'content' => [ [ 'type' => 'text', 'text' => 'ok' ] ] ] ), 'ai_content', 'claude-sonnet-4-6' );
$s = UsageLedger::summary();
echo 'omit_total=' . $s['total_tokens'] . "\n";
echo 'omit_calls=' . $s['calls'] . "\n";
echo 'omit_unreported=' . $s['unreported_calls'] . "\n";
echo 'omit_partial=' . ( $s['partial'] ? 'yes' : 'no' ) . "\n";

// Aggregation across repeats, and separability across feature + model.
$run( $reply( [ 'id' => 'm3', 'content' => [ [ 'type' => 'text', 'text' => 'x' ] ], 'usage' => [ 'input_tokens' => 50, 'output_tokens' => 10 ] ] ), 'ai_content', 'claude-sonnet-4-6' );
$run( $reply( [ 'id' => 'm4', 'content' => [ [ 'type' => 'text', 'text' => 'x' ] ], 'usage' => [ 'input_tokens' => 30, 'output_tokens' => 5 ] ] ), 'seo_meta', 'claude-sonnet-4-6' );
$run( $reply( [ 'id' => 'm5', 'content' => [ [ 'type' => 'text', 'text' => 'x' ] ], 'usage' => [ 'input_tokens' => 70, 'output_tokens' => 7 ] ] ), 'ai_content', 'claude-opus-4-1' );
$s = UsageLedger::summary();
echo 'agg_total=' . $s['total_tokens'] . "\n";
echo 'agg_calls=' . $s['calls'] . "\n";
$rows = UsageLedger::breakdown();
echo 'buckets=' . count( $rows ) . "\n";
$byKey = [];
foreach ( $rows as $row ) { $byKey[ $row['feature'] . '|' . $row['model'] ] = $row; }
echo 'bucket_content_sonnet=' . ( (int) ( $byKey['ai_content|claude-sonnet-4-6']['input'] ?? -1 ) ) . "\n";
echo 'bucket_seo_sonnet=' . ( (int) ( $byKey['seo_meta|claude-sonnet-4-6']['input'] ?? -1 ) ) . "\n";
echo 'bucket_content_opus=' . ( (int) ( $byKey['ai_content|claude-opus-4-1']['input'] ?? -1 ) ) . "\n";
echo 'bucket_content_sonnet_unreported=' . ( (int) ( $byKey['ai_content|claude-sonnet-4-6']['unreported'] ?? -1 ) ) . "\n";

// A call that generated nothing was billed nothing and must not be metered — whether it
// was refused before the wire (no key) or rejected by the provider (a 401 / 429).
$before = UsageLedger::summary()['calls'];
delete_option( 'wpcc_anthropic_api_key' );
$nokey = $run( $reply( [ 'id' => 'never', 'content' => [] ] ), 'ai_content', 'claude-sonnet-4-6' );
echo 'nokey_code=' . $nokey->code() . "\n";
echo 'nokey_calls_delta=' . ( UsageLedger::summary()['calls'] - $before ) . "\n";
update_option( 'wpcc_anthropic_api_key', 'sk-ant-test-0000000000000000000000000000000000000000', false );

// A provider that rejects the key generated nothing either.
$before = UsageLedger::summary()['calls'];
$reject = static function ( $url, $args ) {
	return [ 'response' => [ 'code' => 401 ], 'body' => wp_json_encode( [ 'error' => [ 'message' => 'invalid x-api-key' ] ] ) ];
};
$rejected = $run( $reject, 'alt_text', 'claude-sonnet-4-6' );
echo 'rejected_ok=' . ( $rejected->is_ok() ? 'yes' : 'no' ) . "\n";
echo 'rejected_code=' . $rejected->code() . "\n";
echo 'rejected_calls_delta=' . ( UsageLedger::summary()['calls'] - $before ) . "\n";

// An unattributed call (a connection-test ping carries no feature) is not customer usage.
$before = UsageLedger::summary()['calls'];
$runtime = new AiRuntime( new AnthropicClient( new AnthropicTransport( new AiHttpClient(
	$reply( [ 'id' => 'ping', 'content' => [ [ 'type' => 'text', 'text' => 'ok' ] ], 'usage' => [ 'input_tokens' => 9, 'output_tokens' => 1 ] ] )
) ) ) );
$runtime->generate( new GenerationRequest( 'claude-sonnet-4-6', 1, [ new GenerationMessage( 'user', [ new GenerationTextPart( 'ping' ) ] ) ] ) );
echo 'unattributed_delta=' . ( UsageLedger::summary()['calls'] - $before ) . "\n";

// ── 7. What the ledger actually holds ───────────────────────────────────────
$raw = wp_json_encode( get_option( UsageLedger::OPTION, [] ) );
echo 'store_has_prompt=' . ( false !== strpos( $raw, 'hedgehog' ) ? 'yes' : 'no' ) . "\n";
echo 'store_has_output=' . ( false !== strpos( $raw, 'A real title' ) ? 'yes' : 'no' ) . "\n";
echo 'store_has_key=' . ( false !== strpos( $raw, 'sk-ant' ) ? 'yes' : 'no' ) . "\n";

UsageLedger::reset();
echo 'after_reset_tracked=' . ( UsageLedger::summary()['tracked'] ? 'yes' : 'no' ) . "\n";
PHP

OUT="$(wp --path="$WP_PATH" eval-file "$PHPF" 2>/dev/null)"
rm -f "$PHPF"
g() { echo "$OUT" | grep -F "$1=" | head -1 | cut -d= -f2-; }

echo "== 1. Normalizing what a provider reported =="
assert_eq "an Anthropic usage block is recognized"      "yes"     "$(g anth_reported)"
assert_eq "input tokens read from input_tokens"         "120"     "$(g anth_in)"
assert_eq "output tokens read from output_tokens"       "45"      "$(g anth_out)"
assert_eq "cached tokens read from cache_read"          "12"      "$(g anth_cached)"
assert_eq "total is input + output (cache not double-counted)" "165" "$(g anth_total)"
assert_eq "the provider request id is carried"          "msg_abc" "$(g anth_reqid)"
assert_eq "OpenAI prompt_tokens read as input"          "200"     "$(g oai_in)"
assert_eq "OpenAI completion_tokens read as output"     "60"      "$(g oai_out)"
assert_eq "OpenAI total computed consistently"          "260"     "$(g oai_total)"

echo ""
echo "== 2. \"Not reported\" is never silently turned into zero =="
for c in null string empty junk nonnum; do
	assert_eq "malformed usage ($c) is unknown, not zero" "unknown" "$(g "bad_$c")"
done
assert_eq "a negative count is clamped"                 "0"   "$(g neg_in)"
assert_eq "a partly-valid block still counts as reported" "yes" "$(g neg_reported)"
assert_eq "a genuine zero IS reported"                  "yes" "$(g zero_reported)"
assert_eq "unknown() is not reported"                   "no"  "$(g unknown_reported)"

echo ""
echo "== 3. A real generation records real counts =="
assert_eq "the generation succeeded"                    "yes" "$(g e2e_ok)"
assert_eq "usage survived the transport"                "yes" "$(g e2e_usage_reported)"
assert_eq "usage total reached the result"              "120" "$(g e2e_usage_total)"
assert_eq "the ledger recorded the tokens"              "120" "$(g led_total)"
assert_eq "the ledger recorded one call"                "1"   "$(g led_calls)"
assert_eq "the panel now has something to show"         "yes" "$(g led_tracked)"
assert_eq "nothing is flagged as missing yet"           "no"  "$(g led_partial)"
assert_eq "cost is reported as unavailable"             "no"  "$(g led_cost_available)"

echo ""
echo "== 4. A provider that reports no usage =="
assert_eq "no tokens were invented"                     "120" "$(g omit_total)"
assert_eq "the call itself was still counted"           "2"   "$(g omit_calls)"
assert_eq "the unreported call is counted separately"   "1"   "$(g omit_unreported)"
assert_eq "the total is flagged as a minimum"           "yes" "$(g omit_partial)"

echo ""
echo "== 5. Aggregation, and keeping features and models apart =="
# 100+20, (omitted), 50+10, 30+5, 70+7 = 292
assert_eq "totals accumulate across generations"        "292" "$(g agg_total)"
assert_eq "every call is counted"                       "5"   "$(g agg_calls)"
assert_eq "three distinct feature/model buckets"        "3"   "$(g buckets)"
assert_eq "content on sonnet holds its own input total" "150" "$(g bucket_content_sonnet)"
assert_eq "SEO on the same model is a separate bucket"  "30"  "$(g bucket_seo_sonnet)"
assert_eq "content on a different model is separate"    "70"  "$(g bucket_content_opus)"
assert_eq "the unreported call is attributed to its bucket" "1" "$(g bucket_content_sonnet_unreported)"

echo ""
echo "== 6. Calls that generated nothing are not metered =="
assert_eq "an unconfigured call fails as not_configured" "not_configured" "$(g nokey_code)"
assert_eq "it is not counted as a generation"            "0" "$(g nokey_calls_delta)"
# A rejected key produces no output and no charge. Counting it would have made the panel
# report "31 generations" on a site whose suites mock 401s — which it did, before this.
assert_eq "a 401 is not a successful generation"         "no" "$(g rejected_ok)"
assert_eq "…and reports the provider's status"           "api_error_401" "$(g rejected_code)"
assert_eq "…and is not counted as a generation"          "0" "$(g rejected_calls_delta)"
assert_eq "an unattributed ping is not customer usage"   "0" "$(g unattributed_delta)"

echo ""
echo "== 7. The ledger holds counts and nothing else =="
assert_eq "no prompt text is stored"                    "no"  "$(g store_has_prompt)"
assert_eq "no generated text is stored"                 "no"  "$(g store_has_output)"
assert_eq "no API key is stored"                        "no"  "$(g store_has_key)"
assert_eq "resetting clears the counters"               "no"  "$(g after_reset_tracked)"
# The option must never autoload — it is read on one screen.
if grep -q "update_option( self::OPTION, \$data, false )" "$SCRIPT_DIR/../includes/Ai/Platform/UsageLedger.php"; then
	pass "the ledger option is written with autoload disabled"
else
	fail "the ledger option may be autoloaded"
fi

echo ""
echo "== 8. What the panel says =="
grep -q "Nothing generated yet" "$VIEW" && pass "the panel has an honest empty state" || fail "no empty state in the panel"
grep -q "UsageLedger::summary" "$VIEW" && pass "the panel reads the real ledger" || fail "the panel does not read the ledger"
grep -q "Cost is not shown" "$VIEW" && pass "the panel says cost is not shown, and why" || fail "the panel does not explain the missing cost"
grep -q "reported no usage, so this is a minimum" "$VIEW" && pass "a partial total is labelled as a minimum" || fail "a partial total is presented as complete"
# The old wording promised instrumentation that has now arrived; it must be gone.
if grep -q "not metered yet" "$VIEW"; then
	fail "the panel still claims usage is not metered"
else
	pass "the stale 'not metered yet' claim is gone"
fi

echo ""
echo "RESULT: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
