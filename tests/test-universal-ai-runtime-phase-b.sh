#!/usr/bin/env bash
#
# Phase B — Universal AI Provider Runtime: provider-neutral prompt builders.
#
# Proves the three generation providers (SEO, Alt Text, Content) are now
# provider-neutral: they build a neutral GenerationRequest and read a neutral
# GenerationResult via AiRuntime, and NO LONGER construct Anthropic wire
# messages, parse Anthropic transport arrays, or reference AnthropicClient. The
# outbound wire (proven by capturing the HTTP body through the real transport)
# and the parsed domain results remain identical. Plus: AiRuntime + the shared
# JsonObjectExtractor are neutral, the documented filter seams survive, and the
# invariants are unchanged.
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

SEO="$PLUGIN_DIR/includes/Seo/AnthropicSeoProvider.php"
ALT="$PLUGIN_DIR/includes/AltText/AnthropicVisionProvider.php"
CNT="$PLUGIN_DIR/includes/Content/AnthropicContentProvider.php"
RUNTIME="$PLUGIN_DIR/includes/Ai/AiRuntime.php"
JX="$PLUGIN_DIR/includes/Ai/JsonObjectExtractor.php"

echo "Phase B — provider-neutral prompt builders (identical behaviour)"

echo
echo "== 1. Static: the three providers are provider-neutral =="
for f in "$SEO" "$ALT" "$CNT"; do
	b="$(basename "$f")"
	has  "$b: uses AiRuntime"               "AiRuntime"          "$f"
	has  "$b: builds GenerationRequest"     "GenerationRequest"  "$f"
	lacks "$b: no AnthropicClient ref"      "AnthropicClient"    "$f"
	lacks "$b: no raw HTTP"                 "wp_remote"          "$f"
	lacks "$b: no wire text block"          "'type' => 'text'"   "$f"
	lacks "$b: no wire image block"         "'type' => 'image'"  "$f"
	lacks "$b: no transport-array parse"    "\$res['ok']"        "$f"
done

echo
echo "== 2. Static: AiRuntime + JsonObjectExtractor are neutral =="
has  "AiRuntime exposes generate()"        "function generate("  "$RUNTIME"
has  "AiRuntime exposes is_configured()"   "function is_configured(" "$RUNTIME"
has  "AiRuntime exposes model()"           "function model("     "$RUNTIME"
lacks "AiRuntime: no wire/endpoint"        "api.anthropic.com"   "$RUNTIME"
lacks "AiRuntime: no header knowledge"     "x-api-key"           "$RUNTIME"
lacks "AiRuntime: no raw HTTP"             "wp_remote"           "$RUNTIME"
has  "JsonObjectExtractor: to_array()"     "function to_array("  "$JX"
lacks "JsonObjectExtractor: no I/O"        "wp_remote"           "$JX"
lacks "JsonObjectExtractor: no options"    "get_option"          "$JX"

echo
echo "== 3. Static: AnthropicTransport remains the only wire owner =="
WIRE="$(grep -rlF "api.anthropic.com" "$PLUGIN_DIR/includes/Ai/AnthropicClient.php" "$PLUGIN_DIR/includes/Ai/AiRuntime.php" "$SEO" "$ALT" "$CNT" 2>/dev/null | wc -l | tr -d ' ')"
assert_eq "no endpoint in providers/runtime/facade" "0" "$WIRE"

echo
echo "== 4. Static: documented filter seams preserved =="
has  "seo filter seam"      "wpcc_seo_meta_provider"      "$PLUGIN_DIR/includes/Seo/SeoMetaProviderResolver.php"
has  "alt filter seam"      "wpcc_alt_text_provider"      "$PLUGIN_DIR/includes/AltText/ProviderResolver.php"
has  "content filter seam"  "wpcc_content_field_provider" "$PLUGIN_DIR/includes/Content/ContentFieldProviderResolver.php"

if ! command -v wp >/dev/null 2>&1; then
	echo; echo "  SKIP: wp-cli not available — static checks only."
	echo ""; echo "RESULT: ${PASS} passed, ${FAIL} failed"; [ "$FAIL" -eq 0 ]; exit $?
fi

# Temp image for the Alt Text path (provider validates is_file + size + reads bytes;
# it does not decode the image, so a few bytes labelled image/jpeg are sufficient).
IMG="$(mktemp -t wpcc-phaseb-img.XXXXXX)"; printf 'fake-image-bytes' > "$IMG"

echo
echo "== 5. Functional: providers build the correct neutral request + parse results =="
RES="$(wpe '
	$prev = get_option("wpcc_anthropic_api_key", null);
	update_option("wpcc_anthropic_api_key", "phaseB-key");

	$cap = []; $resp = [ "response"=>["code"=>200], "body"=>"{}" ];
	$sender = function( $u, $a ) use ( &$cap, &$resp ) { $cap = $a; return $resp; };
	$rt = new \WPCommandCenter\Ai\AiRuntime(
		new \WPCommandCenter\Ai\AnthropicClient(
			new \WPCommandCenter\Ai\Transport\AnthropicTransport(
				new \WPCommandCenter\Ai\Http\AiHttpClient( $sender ) ) ) );
	$out = [];

	// --- SEO ---
	$resp = [ "response"=>["code"=>200], "body"=>wp_json_encode([ "content"=>[[ "type"=>"text", "text"=>"{\"meta_title\":\"T\",\"meta_description\":\"D\"}" ]] ]) ];
	$seo = new \WPCommandCenter\Seo\AnthropicSeoProvider( $rt );
	$sr  = $seo->suggest_meta([ "post_id"=>1, "title"=>"Hello", "content"=>"Body text here", "current_title"=>"", "current_description"=>"" ]);
	$b   = json_decode( $cap["body"] ?? "", true );
	$out["seo_model"]  = ( ($b["model"]??"")==="claude-sonnet-4-6" ) ? 1 : 0;
	$out["seo_max"]    = ( ($b["max_tokens"]??0)===400 ) ? 1 : 0;
	$out["seo_role"]   = ( ($b["messages"][0]["role"]??"")==="user" ) ? 1 : 0;
	$out["seo_type"]   = ( ($b["messages"][0]["content"][0]["type"]??"")==="text" ) ? 1 : 0;
	$out["seo_prompt"] = ( strpos( (string)($b["messages"][0]["content"][0]["text"]??""), "You are an SEO assistant" )===0 ) ? 1 : 0;
	$out["seo_to"]     = ( (int)($cap["timeout"]??0)===30 ) ? 1 : 0;
	$out["seo_ok"]     = ( $sr->is_ok() && $sr->meta_title()==="T" && $sr->meta_description()==="D" && $sr->provider()==="anthropic" && $sr->model()==="claude-sonnet-4-6" ) ? 1 : 0;

	// --- Content (title) ---
	$resp = [ "response"=>["code"=>200], "body"=>wp_json_encode([ "content"=>[[ "type"=>"text", "text"=>"{\"title\":\"A Title\"}" ]] ]) ];
	$cnt = new \WPCommandCenter\Content\AnthropicContentProvider( $rt );
	$cr  = $cnt->suggest( "title", [ "title"=>"Hello", "content"=>"Body", "current"=>"" ] );
	$b   = json_decode( $cap["body"] ?? "", true );
	$out["cnt_max"]    = ( ($b["max_tokens"]??0)===300 ) ? 1 : 0;
	$out["cnt_type"]   = ( ($b["messages"][0]["content"][0]["type"]??"")==="text" ) ? 1 : 0;
	$out["cnt_prompt"] = ( strpos( (string)($b["messages"][0]["content"][0]["text"]??""), "You are a content assistant" )===0 ) ? 1 : 0;
	$out["cnt_ok"]     = ( $cr->is_ok() && $cr->text()==="A Title" && $cr->provider()==="anthropic" ) ? 1 : 0;

	// --- Alt Text (image + text) ---
	$resp = [ "response"=>["code"=>200], "body"=>wp_json_encode([ "content"=>[[ "type"=>"text", "text"=>"A red bicycle by a wall" ]] ]) ];
	$alt = new \WPCommandCenter\AltText\AnthropicVisionProvider( $rt );
	$ar  = $alt->suggest_alt([ "attachment_id"=>9, "path"=>"'"$IMG"'", "mime"=>"image/jpeg" ], [ "title"=>"", "filename"=>"" ]);
	$b   = json_decode( $cap["body"] ?? "", true );
	$out["alt_max"]    = ( ($b["max_tokens"]??0)===300 ) ? 1 : 0;
	$out["alt_img"]    = ( ($b["messages"][0]["content"][0]["type"]??"")==="image" ) ? 1 : 0;
	$out["alt_mime"]   = ( ($b["messages"][0]["content"][0]["source"]["media_type"]??"")==="image/jpeg" ) ? 1 : 0;
	$out["alt_b64"]    = ( ($b["messages"][0]["content"][0]["source"]["data"]??"")===base64_encode("fake-image-bytes") ) ? 1 : 0;
	$out["alt_text"]   = ( ($b["messages"][0]["content"][1]["type"]??"")==="text" ) ? 1 : 0;
	$out["alt_ok"]     = ( $ar->is_ok() && $ar->text()==="A red bicycle by a wall" && $ar->confidence()===null ) ? 1 : 0;

	// --- Error propagation: non-200 -> provider returns api_error_<status> ---
	$resp = [ "response"=>["code"=>401], "body"=>wp_json_encode([ "error"=>["message"=>"bad key"] ]) ];
	$er = $seo->suggest_meta([ "post_id"=>1, "title"=>"Hello", "content"=>"x", "current_title"=>"", "current_description"=>"" ]);
	$out["err"] = ( !$er->is_ok() && ($er->get_error()["code"]??"")==="api_error_401" ) ? 1 : 0;

	// --- invalid JSON from model -> invalid_response ---
	$resp = [ "response"=>["code"=>200], "body"=>wp_json_encode([ "content"=>[[ "type"=>"text", "text"=>"not json at all" ]] ]) ];
	$ir = $seo->suggest_meta([ "post_id"=>1, "title"=>"Hello", "content"=>"x", "current_title"=>"", "current_description"=>"" ]);
	$out["invalid"] = ( !$ir->is_ok() && ($ir->get_error()["code"]??"")==="invalid_response" ) ? 1 : 0;

	if (null!==$prev) update_option("wpcc_anthropic_api_key",$prev); else delete_option("wpcc_anthropic_api_key");
	echo wp_json_encode($out);
')"
g() { printf '%s' "$RES" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["'"$1"'"] ?? "";' 2>/dev/null; }

assert_eq "SEO: model"                 "1" "$(g seo_model)"
assert_eq "SEO: max_tokens 400"        "1" "$(g seo_max)"
assert_eq "SEO: role user"             "1" "$(g seo_role)"
assert_eq "SEO: content is text block" "1" "$(g seo_type)"
assert_eq "SEO: prompt unchanged"      "1" "$(g seo_prompt)"
assert_eq "SEO: default timeout 30"    "1" "$(g seo_to)"
assert_eq "SEO: result parsed ok"      "1" "$(g seo_ok)"
assert_eq "Content: max_tokens 300"    "1" "$(g cnt_max)"
assert_eq "Content: content is text"   "1" "$(g cnt_type)"
assert_eq "Content: prompt unchanged"  "1" "$(g cnt_prompt)"
assert_eq "Content: result parsed ok"  "1" "$(g cnt_ok)"
assert_eq "Alt: max_tokens 300"        "1" "$(g alt_max)"
assert_eq "Alt: image block first"     "1" "$(g alt_img)"
assert_eq "Alt: media_type preserved"  "1" "$(g alt_mime)"
assert_eq "Alt: base64 preserved"      "1" "$(g alt_b64)"
assert_eq "Alt: text block second"     "1" "$(g alt_text)"
assert_eq "Alt: result parsed ok"      "1" "$(g alt_ok)"
assert_eq "Error: api_error_401 propagated" "1" "$(g err)"
assert_eq "Invalid JSON -> invalid_response" "1" "$(g invalid)"

rm -f "$IMG"

echo
echo "== 6. Functional: shared JsonObjectExtractor preserves SEO/Content parsing =="
JR="$(wpe '
	$out = [];
	$out["bare"]   = \WPCommandCenter\Seo\AnthropicSeoProvider::extract_meta("{\"meta_title\":\"T\",\"meta_description\":\"D\"}") ? 1 : 0;
	$out["fenced"] = \WPCommandCenter\Seo\AnthropicSeoProvider::extract_meta("```json\n{\"meta_title\":\"T\",\"meta_description\":\"D\"}\n```") ? 1 : 0;
	$out["prose"]  = \WPCommandCenter\Seo\AnthropicSeoProvider::extract_meta("x {\"meta_title\":\"T\",\"meta_description\":\"D\"} y") ? 1 : 0;
	$out["bad"]    = ( null === \WPCommandCenter\Seo\AnthropicSeoProvider::extract_meta("not json") ) ? 1 : 0;
	$out["miss"]   = ( null === \WPCommandCenter\Seo\AnthropicSeoProvider::extract_meta("{\"meta_title\":\"only\"}") ) ? 1 : 0;
	$out["field"]  = ( "X" === \WPCommandCenter\Content\AnthropicContentProvider::extract_field("{\"title\":\"X\"}","title") ) ? 1 : 0;
	$out["fieldn"] = ( null === \WPCommandCenter\Content\AnthropicContentProvider::extract_field("{\"title\":\"\"}","title") ) ? 1 : 0;
	echo wp_json_encode($out);
')"
gj() { printf '%s' "$JR" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["'"$1"'"] ?? "";' 2>/dev/null; }
assert_eq "extract_meta: bare JSON"   "1" "$(gj bare)"
assert_eq "extract_meta: fenced"      "1" "$(gj fenced)"
assert_eq "extract_meta: in prose"    "1" "$(gj prose)"
assert_eq "extract_meta: bad -> null" "1" "$(gj bad)"
assert_eq "extract_meta: missing key -> null" "1" "$(gj miss)"
assert_eq "extract_field: value"      "1" "$(gj field)"
assert_eq "extract_field: empty -> null" "1" "$(gj fieldn)"

echo
echo "== 7. Invariants unchanged =="
assert_eq "OPERATION_MAP == 34" "34" "$(wpe 'echo count(\WPCommandCenter\Operations\CapabilityRegistry::OPERATION_MAP);')"
assert_eq "capabilities == 23"  "23" "$(wpe 'echo count(\WPCommandCenter\Operations\CapabilityRegistry::ALL_CAPABILITIES);')"
assert_eq "catalogue == 40"     "40" "$(wpe 'echo count((new \WPCommandCenter\Operations\OperationRegistry())->get_operations());')"
assert_eq "DB_VERSION 2.5.0"    "2.5.0" "$(wpe 'echo \WPCommandCenter\Core\Schema::DB_VERSION;')"

echo ""
echo "RESULT: ${PASS} passed, ${FAIL} failed"
[ "$FAIL" -eq 0 ]
