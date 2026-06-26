#!/usr/bin/env bash
#
# Phase D — Universal AI Provider Runtime: OpenAI-compatible execution backend.
#
# Proves the second execution backend (codec + transport + profiles) is wire-
# correct and integrated into the runtime's dialect dispatch, WITHOUT disturbing
# the Anthropic path. Fully offline: the outbound call is injected (no network,
# no key, no cost). Connection options are snapshotted and restored.
#
# Scope guard: the runtime dispatches to OpenAI ONLY for a KEYED openai-compatible
# default connection; an unkeyed connection, an Anthropic default, or no default
# all take the unchanged Anthropic path (byte-identical).
#
# Requires wp-cli for the functional sections.

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

T_DIR="$PLUGIN_DIR/includes/Ai/Transport"
RUNTIME="$PLUGIN_DIR/includes/Ai/AiRuntime.php"

echo "Phase D — OpenAI-compatible execution backend"

echo
echo "== 1. Static: transport owns wire; codec/profiles are pure; no options =="
has  "transport: openai chat path"      "/chat/completions"   "$T_DIR/OpenAiCompatibleTransport.php"
has  "transport: bearer auth"           "Bearer "             "$T_DIR/OpenAiCompatibleTransport.php"
has  "transport: uses shared AiHttpClient" "AiHttpClient"     "$T_DIR/OpenAiCompatibleTransport.php"
has  "transport: redacts errors"        "Redactor"            "$T_DIR/OpenAiCompatibleTransport.php"
lacks "transport: no option reads"      "get_option"          "$T_DIR/OpenAiCompatibleTransport.php"
lacks "transport: no retry loop (for)"  "for ("               "$T_DIR/OpenAiCompatibleTransport.php"
has  "transport: SSRF endpoint guard"   "AiEndpointGuard"     "$T_DIR/OpenAiCompatibleTransport.php"
lacks "codec: no I/O"                   "wp_remote"           "$T_DIR/OpenAiCompatibleCodec.php"
lacks "codec: no options"               "get_option"          "$T_DIR/OpenAiCompatibleCodec.php"
lacks "profiles: no I/O"                "wp_remote"           "$T_DIR/OpenAiCompatProfiles.php"
lacks "profiles: no options"            "get_option"          "$T_DIR/OpenAiCompatProfiles.php"
has  "runtime dispatches openai dialect" "OpenAiCompatibleTransport" "$RUNTIME"
lacks "runtime: no provider-selection loop" "foreach"         "$RUNTIME"

if ! command -v wp >/dev/null 2>&1; then
	echo; echo "  SKIP: wp-cli not available — static checks only."
	echo ""; echo "RESULT: ${PASS} passed, ${FAIL} failed"; [ "$FAIL" -eq 0 ]; exit $?
fi

echo
echo "== 2. Functional: registration + codec + transport + runtime dispatch =="
RES="$(wpe '
use WPCommandCenter\Ai\Platform\ConnectionStore;
use WPCommandCenter\Ai\Platform\Dialect;
use WPCommandCenter\Ai\AiRuntime;
use WPCommandCenter\Ai\AnthropicClient;
use WPCommandCenter\Ai\Transport\OpenAiCompatibleTransport;
use WPCommandCenter\Ai\Transport\OpenAiCompatibleCodec;
use WPCommandCenter\Ai\Transport\OpenAiCompatProfiles;
use WPCommandCenter\Ai\Http\AiHttpClient;
use WPCommandCenter\Ai\Contract\GenerationRequest;
use WPCommandCenter\Ai\Contract\GenerationMessage;
use WPCommandCenter\Ai\Contract\GenerationTextPart;
use WPCommandCenter\Ai\Contract\GenerationImagePart;

$out = [];

// (a) registration: openai-compatible dialect is runtime-supported.
$out["registered"] = Dialect::runtime_supported(Dialect::OPENAI) ? 1 : 0;

// (b) profiles: default + azure override.
$def = OpenAiCompatProfiles::for_provider("openai");
$az  = OpenAiCompatProfiles::for_provider("azure-openai");
$out["profile_default_auth"] = ($def["auth"]==="bearer" && $def["token_param"]==="max_tokens") ? 1 : 0;
$out["profile_azure_auth"]   = ($az["auth"]==="api-key" && !empty($az["deploy_path"]) && $az["api_version"]!=="") ? 1 : 0;

// (c) codec: text-only message → string content; image message → array content.
$txt = new GenerationRequest("gpt-5", 300, [ new GenerationMessage("user",[ new GenerationTextPart("hello") ]) ]);
$bt  = OpenAiCompatibleCodec::request_body($txt, $def);
$out["codec_text"] = ($bt["model"]==="gpt-5" && $bt["max_tokens"]===300 && $bt["messages"][0]["content"]==="hello") ? 1 : 0;
$b64 = base64_encode("img");
$img = new GenerationRequest("gpt-5", 300, [ new GenerationMessage("user",[ new GenerationImagePart("image/png",$b64), new GenerationTextPart("describe") ]) ]);
$bi  = OpenAiCompatibleCodec::request_body($img, $def);
$out["codec_image"] = (
    ($bi["messages"][0]["content"][0]["type"]??"")==="image_url" &&
    ($bi["messages"][0]["content"][0]["image_url"]["url"]??"")==="data:image/png;base64,".$b64 &&
    ($bi["messages"][0]["content"][1]["type"]??"")==="text"
) ? 1 : 0;
// azure token param override
$baz = OpenAiCompatibleCodec::request_body($txt, $az);
$out["codec_azure_tokens"] = isset($baz["max_tokens"]) ? 1 : 0; // azure default keeps max_tokens

// (d) transport: success, url, auth, parse. A permissive endpoint guard is
// injected so these dispatch/codec checks stay DNS-independent; the SSRF guard
// itself is proven in test-universal-ai-runtime-phase-d-safety.sh.
$pass = function($u,$l){ return ["ok"=>true,"code"=>"","message"=>""]; };
$cap=[]; $sender=function($u,$a)use(&$cap){ $cap=["url"=>$u,"args"=>$a]; return ["response"=>["code"=>200],"body"=>wp_json_encode(["choices"=>[["message"=>["content"=>"  done  "]]]])]; };
$tr = new OpenAiCompatibleTransport(new AiHttpClient($sender), $pass);
$r1 = $tr->generate($txt, "sk-x", "https://api.openai.com/v1", "openai", "");
$out["tr_url"]  = (($cap["url"]??"")==="https://api.openai.com/v1/chat/completions") ? 1 : 0;
$out["tr_auth"] = (($cap["args"]["headers"]["Authorization"]??"")==="Bearer sk-x") ? 1 : 0;
$out["tr_ok"]   = ($r1->is_ok() && $r1->text()==="done") ? 1 : 0;

// (e) transport: azure url (deployment + api-version).
$cap=[]; $tr->generate($txt, "sk-x", "https://x.openai.azure.com", "azure-openai", "mydeploy");
$out["tr_azure_url"] = ( strpos($cap["url"]??"", "/openai/deployments/mydeploy/chat/completions")!==false && strpos($cap["url"]??"","api-version=")!==false ) ? 1 : 0;
$out["tr_azure_auth"]= (($cap["args"]["headers"]["api-key"]??"")==="sk-x") ? 1 : 0;

// (f) transport: error mapping + redaction + not_configured.
$te = new OpenAiCompatibleTransport(new AiHttpClient(function($u,$a){ return ["response"=>["code"=>401],"body"=>wp_json_encode(["error"=>["message"=>"bad key"]])]; }), $pass);
$re = $te->generate($txt,"k","https://e","openai","");
$out["tr_apierr"] = (!$re->is_ok() && $re->code()==="api_error_401") ? 1 : 0;
$tw = new OpenAiCompatibleTransport(new AiHttpClient(function($u,$a){ return new \WP_Error("x","leaked sk-ant-AAAAAAAAAAAAAAAAAAAAAAAA"); }), $pass);
$rw = $tw->generate($txt,"k","https://e","openai","");
$out["tr_redacted"] = (!$rw->is_ok() && $rw->code()==="request_failed" && strpos($rw->message(),"sk-ant-")===false) ? 1 : 0;
$rn = $tr->generate($txt,"","https://e","openai","");
$out["tr_notcfg"] = (!$rn->is_ok() && $rn->code()==="not_configured") ? 1 : 0;

// (g) runtime dispatch: KEYED openai default → openai path.
$snap_c=get_option("wpcc_ai_connections","__n__"); $snap_k=get_option("wpcc_ai_credentials","__n__");
$snap_d=get_option("wpcc_ai_default_conn","__n__"); $snap_r=get_option("wpcc_ai_routes","__n__");
$store = new ConnectionStore();
$id = $store->create("openai", ["name"=>"D","model"=>"gpt-5"]);
$store->credentials()->set_secret($id,"sk-rt");
$store->set_default($id);
$cap=[]; $sender2=function($u,$a)use(&$cap){ $cap=$a; return ["response"=>["code"=>200],"body"=>wp_json_encode(["choices"=>[["message"=>["content"=>"openai!"]]]])]; };
$rt = new AiRuntime(null, new OpenAiCompatibleTransport(new AiHttpClient($sender2), $pass));
$out["rt_model"]  = ($rt->model("claude-sonnet-4-6")==="gpt-5") ? 1 : 0;       // connection model, not anthropic default
$out["rt_cfg"]    = $rt->is_configured() ? 1 : 0;
$rr = $rt->generate(new GenerationRequest("gpt-5", 400, [ new GenerationMessage("user",[ new GenerationTextPart("x") ]) ]));
$bb = json_decode($cap["body"]??"",true);
$out["rt_dispatch"] = ($rr->is_ok() && $rr->text()==="openai!" && ($bb["model"]??"")==="gpt-5") ? 1 : 0;

// (h) safety: KEYLESS openai default → Anthropic path (target null).
$store->credentials()->clear_secret($id);
$rt2 = new AiRuntime(null, new OpenAiCompatibleTransport(new AiHttpClient($sender2), $pass));
$out["rt_keyless_falls_back"] = ($rt2->model("claude-sonnet-4-6")==="claude-sonnet-4-6" || $rt2->model("claude-sonnet-4-6")!=="gpt-5") ? 1 : 0;

// cleanup / restore
$store->delete($id);
foreach (["wpcc_ai_connections"=>$snap_c,"wpcc_ai_credentials"=>$snap_k,"wpcc_ai_default_conn"=>$snap_d,"wpcc_ai_routes"=>$snap_r] as $k=>$v){
    if ($v==="__n__") delete_option($k); else update_option($k,$v,false);
}
echo wp_json_encode($out);
')"
g() { printf '%s' "$RES" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["'"$1"'"] ?? "";' 2>/dev/null; }

assert_eq "openai-compatible registered (runtime_supported)" "1" "$(g registered)"
assert_eq "profile: default (bearer/max_tokens)"  "1" "$(g profile_default_auth)"
assert_eq "profile: azure (api-key/deploy/version)" "1" "$(g profile_azure_auth)"
assert_eq "codec: text → string content"          "1" "$(g codec_text)"
assert_eq "codec: image → image_url + text parts"  "1" "$(g codec_image)"
assert_eq "codec: azure token param"               "1" "$(g codec_azure_tokens)"
assert_eq "transport: URL"                         "1" "$(g tr_url)"
assert_eq "transport: Bearer auth"                 "1" "$(g tr_auth)"
assert_eq "transport: success parsed"              "1" "$(g tr_ok)"
assert_eq "transport: azure deployment URL"        "1" "$(g tr_azure_url)"
assert_eq "transport: azure api-key auth"          "1" "$(g tr_azure_auth)"
assert_eq "transport: api_error_<status>"          "1" "$(g tr_apierr)"
assert_eq "transport: error redacted (no key leak)" "1" "$(g tr_redacted)"
assert_eq "transport: empty key → not_configured"  "1" "$(g tr_notcfg)"
assert_eq "runtime: model = connection model"      "1" "$(g rt_model)"
assert_eq "runtime: is_configured via connection"  "1" "$(g rt_cfg)"
assert_eq "runtime: dispatches to OpenAI"          "1" "$(g rt_dispatch)"
assert_eq "runtime: keyless openai → Anthropic path" "1" "$(g rt_keyless_falls_back)"

echo
echo "== 3. Invariants unchanged =="
assert_eq "OPERATION_MAP == 34" "34" "$(wpe 'echo count(\WPCommandCenter\Operations\CapabilityRegistry::OPERATION_MAP);')"
assert_eq "capabilities == 23"  "23" "$(wpe 'echo count(\WPCommandCenter\Operations\CapabilityRegistry::ALL_CAPABILITIES);')"
assert_eq "catalogue == 40"     "40" "$(wpe 'echo count((new \WPCommandCenter\Operations\OperationRegistry())->get_operations());')"
assert_eq "DB_VERSION 2.5.0"    "2.5.0" "$(wpe 'echo \WPCommandCenter\Core\Schema::DB_VERSION;')"

echo ""
echo "RESULT: ${PASS} passed, ${FAIL} failed"
[ "$FAIL" -eq 0 ]
