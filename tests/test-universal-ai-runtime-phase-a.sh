#!/usr/bin/env bash
#
# Phase A — Universal AI Provider Runtime: neutral contract + shared HttpClient
# + Anthropic transport + AnthropicClient facade.
#
# Proves the byte-identical migration: for the three live call shapes (SEO text,
# Content text, Alt Text image+text) the new path produces the same URL, method,
# headers, request body, timeout, and return-array shape as the pre-Phase-A
# Anthropic runtime. Plus: neutral contract is I/O-free, HTTP client is single-
# attempt with no retry/SSRF, errors map identically, Redactor still applied, no
# API key leakage, and the invariants are unchanged.
#
# Fully offline: the outbound call is injected (no network, no key, no cost).
# Requires: wp-cli for the functional sections; static checks run regardless.

set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_ROOT="$(cd "$PLUGIN_DIR/../../.." && pwd)"
cd "$PLUGIN_DIR"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }
has()  { grep -qF -- "$2" "$3" && pass "$1" || fail "$1 (missing '$2')"; }
lacks(){ grep -qF -- "$2" "$3" && fail "$1 (found '$2')" || pass "$1"; }
wpe() { wp --path="$WP_ROOT" eval "$1" 2>/dev/null; }

CONTRACT_DIR="$PLUGIN_DIR/includes/Ai/Contract"
HTTP_DIR="$PLUGIN_DIR/includes/Ai/Http"
TRANSPORT="$PLUGIN_DIR/includes/Ai/Transport/AnthropicTransport.php"
HTTPC="$HTTP_DIR/AiHttpClient.php"
FACADE="$PLUGIN_DIR/includes/Ai/AnthropicClient.php"

echo "Phase A — Universal AI Provider Runtime: neutral seam (byte-identical migration)"

echo
echo "== 1. Static: neutral contract is immutable + I/O-free =="
for f in GenerationRequest GenerationResult GenerationMessage GenerationTextPart GenerationImagePart; do
	C="$CONTRACT_DIR/$f.php"
	[ -f "$C" ] && pass "contract present: $f" || fail "contract present: $f"
	lacks "$f: no wp_remote_post"  "wp_remote_post" "$C"
	lacks "$f: no get_option"      "get_option"     "$C"
	lacks "$f: no update_option"   "update_option"  "$C"
	lacks "$f: no constant() read" "WPCC_ANTHROPIC" "$C"
	lacks "$f: no api key header"  "x-api-key"      "$C"
done

echo
echo "== 2. Static: shared HTTP client — single attempt, no retry, no SSRF, no key leak =="
has  "http client wraps wp_remote_post"   "wp_remote_post"  "$HTTPC"
has  "http client redacts errors"         "Redactor"        "$HTTPC"
# Exactly one outbound call site (single attempt — no retry loop). Count the real
# invocation `wp_remote_post( $url` only, not the docstring mentions.
ATT="$(grep -cF 'wp_remote_post( $url' "$HTTPC")"
assert_eq "http client: exactly one wp_remote_post call site" "1" "$ATT"
lacks "http client: no retry loop (for)"  "for ("           "$HTTPC"
lacks "http client: no retry loop (while)" "while ("        "$HTTPC"
lacks "http client: no SSRF/endpoint guard yet" "wp_http_validate_url" "$HTTPC"
lacks "http client: does not parse provider body (json_decode)" "json_decode" "$HTTPC"
lacks "http client: no option reads"      "get_option"      "$HTTPC"

echo
echo "== 3. Static: transport owns Anthropic wire; emits only model/max_tokens/messages =="
has  "transport endpoint"                 "api.anthropic.com/v1/messages" "$TRANSPORT"
has  "transport version 2023-06-01"       "2023-06-01"      "$TRANSPORT"
has  "transport body: model"              "'model'"         "$TRANSPORT"
has  "transport body: max_tokens"         "'max_tokens'"    "$TRANSPORT"
has  "transport body: messages"           "'messages'"      "$TRANSPORT"
# Phase A must NOT introduce any new body fields.
lacks "transport emits no system field"        "'system'"        "$TRANSPORT"
lacks "transport emits no temperature field"   "'temperature'"   "$TRANSPORT"
lacks "transport emits no stop_sequences field" "'stop_sequences'" "$TRANSPORT"
lacks "transport reads no options"             "get_option"      "$TRANSPORT"
lacks "transport no engine use"                "OperationExecutor" "$TRANSPORT"

echo
echo "== 4. Static: facade preserves surface, delegates, exposes no new fields =="
has  "facade keeps send()"                "function send("  "$FACADE"
has  "facade keeps is_configured()"       "function is_configured(" "$FACADE"
has  "facade keeps key_source()"          "function key_source("    "$FACADE"
has  "facade keeps model()"               "function model("         "$FACADE"
has  "facade delegates to transport"      "AnthropicTransport"      "$FACADE"
lacks "facade exposes no usage field"     "'usage'"         "$FACADE"
lacks "facade exposes no finish_reason"   "finish_reason"   "$FACADE"

if ! command -v wp >/dev/null 2>&1; then
	echo
	echo "  SKIP: wp-cli not available — static checks only."
	echo ""
	echo "RESULT: ${PASS} passed, ${FAIL} failed"
	[ "$FAIL" -eq 0 ]; exit $?
fi

echo
echo "== 5. Functional: BYTE-IDENTICAL outbound request for the three live call shapes =="
# Inject a capturing sender into the new path; force a key so send() reaches the
# transport; compare the captured wp_remote_post args against what the pre-Phase-A
# code would have produced (computed inline with the same wp_json_encode).
RES="$(wpe '
	$prev = get_option("wpcc_anthropic_api_key", null);
	update_option("wpcc_anthropic_api_key", "phaseA-fixture-key");

	$captured = [];
	$body200  = wp_json_encode([ "content" => [ [ "type" => "text", "text" => "  hello world  " ] ] ]);
	$sender   = function( $url, $args ) use ( &$captured, $body200 ) {
		$captured = [ "url" => $url, "args" => $args ];
		return [ "response" => [ "code" => 200 ], "body" => $body200 ];
	};
	$http      = new \WPCommandCenter\Ai\Http\AiHttpClient( $sender );
	$transport = new \WPCommandCenter\Ai\Transport\AnthropicTransport( $http );
	$client    = new \WPCommandCenter\Ai\AnthropicClient( $transport );

	$out = [];
	$URL = "https://api.anthropic.com/v1/messages";

	// (a) SEO text shape (max_tokens 400).
	$seo_msgs = [ [ "role" => "user", "content" => [ [ "type" => "text", "text" => "SEO-PROMPT" ] ] ] ];
	$seo_exp  = wp_json_encode([ "model" => "claude-sonnet-4-6", "max_tokens" => 400, "messages" => $seo_msgs ]);
	$r = $client->send( $seo_msgs, 400, "claude-sonnet-4-6" );
	$out["seo_url"]    = ( ($captured["url"] ?? "") === $URL ) ? 1 : 0;
	$out["seo_body"]   = ( ($captured["args"]["body"] ?? "") === $seo_exp ) ? 1 : 0;
	$out["seo_ver"]    = ( ($captured["args"]["headers"]["anthropic-version"] ?? "") === "2023-06-01" ) ? 1 : 0;
	$out["seo_ctype"]  = ( ($captured["args"]["headers"]["content-type"] ?? "") === "application/json" ) ? 1 : 0;
	$out["seo_key"]    = ( ($captured["args"]["headers"]["x-api-key"] ?? "") !== "" ) ? 1 : 0;
	$out["seo_to"]     = ( (int)($captured["args"]["timeout"] ?? 0) === 30 ) ? 1 : 0;
	$out["seo_ret"]    = ( !empty($r["ok"]) && $r["text"] === "hello world" && $r["model"] === "claude-sonnet-4-6" ) ? 1 : 0;

	// (b) Content text shape (max_tokens 300).
	$cnt_msgs = [ [ "role" => "user", "content" => [ [ "type" => "text", "text" => "CONTENT-PROMPT" ] ] ] ];
	$cnt_exp  = wp_json_encode([ "model" => "claude-sonnet-4-6", "max_tokens" => 300, "messages" => $cnt_msgs ]);
	$client->send( $cnt_msgs, 300, "claude-sonnet-4-6" );
	$out["cnt_body"] = ( ($captured["args"]["body"] ?? "") === $cnt_exp ) ? 1 : 0;

	// (c) Alt Text image+text shape (image block FIRST, then text; max_tokens 300).
	$b64 = base64_encode( "fake-image-bytes" );
	$alt_msgs = [ [ "role" => "user", "content" => [
		[ "type" => "image", "source" => [ "type" => "base64", "media_type" => "image/jpeg", "data" => $b64 ] ],
		[ "type" => "text", "text" => "ALT-PROMPT" ],
	] ] ];
	$alt_exp = wp_json_encode([ "model" => "claude-sonnet-4-6", "max_tokens" => 300, "messages" => $alt_msgs ]);
	$client->send( $alt_msgs, 300, "claude-sonnet-4-6" );
	$out["alt_body"] = ( ($captured["args"]["body"] ?? "") === $alt_exp ) ? 1 : 0;

	// (d) timeout override honored identically.
	$client->send( $seo_msgs, 400, "claude-sonnet-4-6", [ "timeout" => 12 ] );
	$out["to_override"] = ( (int)($captured["args"]["timeout"] ?? 0) === 12 ) ? 1 : 0;

	if (null!==$prev) update_option("wpcc_anthropic_api_key",$prev); else delete_option("wpcc_anthropic_api_key");
	echo wp_json_encode($out);
')"
getj() { printf '%s' "$RES" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["'"$1"'"] ?? "";' 2>/dev/null; }

assert_eq "SEO: URL identical"                  "1" "$(getj seo_url)"
assert_eq "SEO: request body BYTE-identical"    "1" "$(getj seo_body)"
assert_eq "SEO: anthropic-version header"       "1" "$(getj seo_ver)"
assert_eq "SEO: content-type header"            "1" "$(getj seo_ctype)"
assert_eq "SEO: x-api-key header present"       "1" "$(getj seo_key)"
assert_eq "SEO: default timeout 30s"            "1" "$(getj seo_to)"
assert_eq "SEO: return array shape + trim"      "1" "$(getj seo_ret)"
assert_eq "Content: request body BYTE-identical" "1" "$(getj cnt_body)"
assert_eq "Alt Text: image+text body BYTE-identical" "1" "$(getj alt_body)"
assert_eq "timeout override honored"            "1" "$(getj to_override)"

echo
echo "== 6. Functional: error mapping identical + redaction + not_configured =="
ERR="$(wpe '
	$prev = get_option("wpcc_anthropic_api_key", null);
	update_option("wpcc_anthropic_api_key", "phaseA-fixture-key");
	$out = [];

	// (a) HTTP non-200 -> api_error_<status> with provider message.
	$h1 = new \WPCommandCenter\Ai\Http\AiHttpClient( function($u,$a){
		return [ "response" => [ "code" => 401 ], "body" => wp_json_encode([ "error" => [ "message" => "invalid x-api-key" ] ]) ];
	});
	$c1 = new \WPCommandCenter\Ai\AnthropicClient( new \WPCommandCenter\Ai\Transport\AnthropicTransport($h1) );
	$r1 = $c1->send([[ "role"=>"user","content"=>[[ "type"=>"text","text"=>"x" ]] ]], 10, "m");
	$out["api_err"] = ( empty($r1["ok"]) && ($r1["code"]??"")==="api_error_401" && ($r1["model"]??"")==="m" ) ? 1 : 0;

	// (b) Transport WP_Error -> request_failed.
	$h2 = new \WPCommandCenter\Ai\Http\AiHttpClient( function($u,$a){
		return new \WP_Error("http_request_failed","connect timeout");
	});
	$c2 = new \WPCommandCenter\Ai\AnthropicClient( new \WPCommandCenter\Ai\Transport\AnthropicTransport($h2) );
	$r2 = $c2->send([[ "role"=>"user","content"=>[[ "type"=>"text","text"=>"x" ]] ]], 10, "m");
	$out["req_failed"] = ( empty($r2["ok"]) && ($r2["code"]??"")==="request_failed" && ($r2["message"]??"")==="connect timeout" ) ? 1 : 0;

	// (c) Redaction: an anthropic key leaked in an error message is scrubbed.
	$h3 = new \WPCommandCenter\Ai\Http\AiHttpClient( function($u,$a){
		return new \WP_Error("x","leaked sk-ant-AAAAAAAAAAAAAAAAAAAAAAAA here");
	});
	$c3 = new \WPCommandCenter\Ai\AnthropicClient( new \WPCommandCenter\Ai\Transport\AnthropicTransport($h3) );
	$r3 = $c3->send([[ "role"=>"user","content"=>[[ "type"=>"text","text"=>"x" ]] ]], 10, "m");
	$msg3 = (string)($r3["message"] ?? "");
	$out["redacted"]   = ( strpos($msg3,"sk-ant-")===false && strpos($msg3,"REDACTED")!==false ) ? 1 : 0;

	// (d) not_configured: transport given an empty key never calls HTTP (env-independent).
	$called = 0;
	$h4 = new \WPCommandCenter\Ai\Http\AiHttpClient( function($u,$a) use (&$called){ $called=1; return ["response"=>["code"=>200],"body"=>"{}"]; });
	$t4 = new \WPCommandCenter\Ai\Transport\AnthropicTransport($h4);
	$req = new \WPCommandCenter\Ai\Contract\GenerationRequest("m", 10, [], 30);
	$res4 = $t4->generate($req, "");
	$out["not_cfg"]    = ( !$res4->is_ok() && $res4->code()==="not_configured" && $called===0 ) ? 1 : 0;

	if (null!==$prev) update_option("wpcc_anthropic_api_key",$prev); else delete_option("wpcc_anthropic_api_key");
	echo wp_json_encode($out);
')"
gete() { printf '%s' "$ERR" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["'"$1"'"] ?? "";' 2>/dev/null; }

assert_eq "api_error_<status> mapped + model echoed" "1" "$(gete api_err)"
assert_eq "request_failed mapped from WP_Error"      "1" "$(gete req_failed)"
assert_eq "error message redacted (no key leak)"     "1" "$(gete redacted)"
assert_eq "empty key -> not_configured, NO HTTP call" "1" "$(gete not_cfg)"

echo
echo "== 7. Functional: live providers still call AnthropicClient unchanged =="
PROV="$(wpe '
	$out = [];
	$out["seo"]     = class_exists("\WPCommandCenter\Seo\AnthropicSeoProvider") ? 1 : 0;
	$out["alt"]     = class_exists("\WPCommandCenter\AltText\AnthropicVisionProvider") ? 1 : 0;
	$out["content"] = class_exists("\WPCommandCenter\Content\AnthropicContentProvider") ? 1 : 0;
	// Each constructs with the no-arg AnthropicClient (drop-in facade) without error.
	$p1 = new \WPCommandCenter\Seo\AnthropicSeoProvider();
	$p2 = new \WPCommandCenter\AltText\AnthropicVisionProvider();
	$p3 = new \WPCommandCenter\Content\AnthropicContentProvider();
	$out["construct"] = ( $p1->id()==="anthropic" && $p2->id()==="anthropic" && $p3->id()==="anthropic" ) ? 1 : 0;
	echo wp_json_encode($out);
')"
getp() { printf '%s' "$PROV" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["'"$1"'"] ?? "";' 2>/dev/null; }
assert_eq "SEO provider unchanged"      "1" "$(getp seo)"
assert_eq "Alt Text provider unchanged" "1" "$(getp alt)"
assert_eq "Content provider unchanged"  "1" "$(getp content)"
assert_eq "providers construct over facade (no-arg)" "1" "$(getp construct)"

echo
echo "== 8. Invariants unchanged (no op/cap/tool/schema drift) =="
assert_eq "OPERATION_MAP == 34" "34" "$(wpe 'echo count(\WPCommandCenter\Operations\CapabilityRegistry::OPERATION_MAP);')"
assert_eq "capabilities == 23"  "23" "$(wpe 'echo count(\WPCommandCenter\Operations\CapabilityRegistry::ALL_CAPABILITIES);')"
assert_eq "catalogue == 40"     "40" "$(wpe 'echo count((new \WPCommandCenter\Operations\OperationRegistry())->get_operations());')"
assert_eq "DB_VERSION 2.5.0"    "2.5.0" "$(wpe 'echo \WPCommandCenter\Core\Schema::DB_VERSION;')"

echo ""
echo "RESULT: ${PASS} passed, ${FAIL} failed"
[ "$FAIL" -eq 0 ]
