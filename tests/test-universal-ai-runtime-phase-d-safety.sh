#!/usr/bin/env bash
#
# Phase D Safety — SSRF endpoint guard + honest provider/runtime copy.
#
# Proves the endpoint guard blocks unsafe (private/loopback/link-local/reserved/
# non-http) endpoints, allows public endpoints, allows declared-local providers on
# loopback, disables redirects for custom AI endpoints, leaks no secrets, and
# leaves the Anthropic path untouched. Also proves the stale "only Anthropic"
# copy is gone and replaced with honest, calm runtime copy.
#
# Fully offline & DNS-free: the guard is exercised with IP literals; outbound
# calls are injected. Requires wp-cli for the functional sections.

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

GUARD="$PLUGIN_DIR/includes/Ai/Http/AiEndpointGuard.php"
TRANSPORT="$PLUGIN_DIR/includes/Ai/Transport/OpenAiCompatibleTransport.php"
ANTHROPIC_T="$PLUGIN_DIR/includes/Ai/Transport/AnthropicTransport.php"

echo "Phase D Safety — SSRF guard + honest copy"

echo
echo "== 1. Static: guard is pure; Anthropic transport untouched =="
has  "guard exposes validate()"          "function validate("  "$GUARD"
has  "guard: scheme allowlist"           "http"                "$GUARD"
has  "guard: private/reserved filter"    "FILTER_FLAG_NO_PRIV_RANGE" "$GUARD"
lacks "guard: no options"                "get_option"          "$GUARD"
lacks "guard: no raw HTTP"               "wp_remote"           "$GUARD"
has  "openai transport calls guard"      "guard"               "$TRANSPORT"
has  "openai transport disables redirects" "0 // never follow redirects" "$TRANSPORT"
lacks "Anthropic transport unchanged (no guard)" "AiEndpointGuard" "$ANTHROPIC_T"
lacks "Anthropic transport unchanged (no redirects arg)" "redirect" "$ANTHROPIC_T"

echo
echo "== 2. Static: honest runtime copy (no Anthropic-only claims) =="
lacks "ai-setup: no 'only Anthropic' claim" "only run AI tasks through Anthropic" "$PLUGIN_DIR/includes/Admin/views/ai-setup.php"
has  "ai-setup: default-connection copy"  "the one connection you set as the default" "$PLUGIN_DIR/includes/Admin/views/ai-setup.php"
has  "ai-setup: no auto-selection"        "Nothing is selected automatically" "$PLUGIN_DIR/includes/Admin/views/ai-setup.php"
has  "ai-setup: content sent to chosen"   "sent only to the provider you pick" "$PLUGIN_DIR/includes/Admin/views/ai-setup.php"
lacks "readiness: no 'runs on Anthropic today'" "Generation runs on Anthropic today" "$PLUGIN_DIR/includes/Admin/DesignPartnerReadiness.php"
has  "readiness: honest default copy"     "the provider you set as the default" "$PLUGIN_DIR/includes/Admin/DesignPartnerReadiness.php"
lacks "builtin-ai-tools: no 'Anthropic today'" "Generation runs on Anthropic today." "$PLUGIN_DIR/includes/Admin/views/partials/builtin-ai-tools.php"

if ! command -v wp >/dev/null 2>&1; then
	echo; echo "  SKIP: wp-cli not available — static checks only."
	echo ""; echo "RESULT: ${PASS} passed, ${FAIL} failed"; [ "$FAIL" -eq 0 ]; exit $?
fi

echo
echo "== 3. Functional: guard verdicts (DNS-free IP literals) =="
GR="$(wpe '
use WPCommandCenter\Ai\Http\AiEndpointGuard;
$out = [];
// public IP, non-local provider → allowed
$out["public_ok"]   = AiEndpointGuard::validate("https://1.1.1.1/v1", false)["ok"] ? 1 : 0;
// private/loopback/link-local, non-local → blocked
$out["private"]     = AiEndpointGuard::validate("https://10.0.0.5/v1", false)["ok"] ? 0 : 1;
$out["loopback"]    = AiEndpointGuard::validate("http://127.0.0.1:11434/v1", false)["ok"] ? 0 : 1;
$out["linklocal"]   = AiEndpointGuard::validate("http://169.254.169.254/latest/meta-data", false)["ok"] ? 0 : 1; // cloud metadata SSRF
$out["ipv6_loop"]   = AiEndpointGuard::validate("http://[::1]:8080/v1", false)["ok"] ? 0 : 1;
// declared-local provider may use loopback/private
$out["local_loop_ok"] = AiEndpointGuard::validate("http://127.0.0.1:11434/v1", true)["ok"] ? 1 : 0;
$out["local_priv_ok"] = AiEndpointGuard::validate("http://192.168.1.50:11434/v1", true)["ok"] ? 1 : 0;
// scheme allowlist
$out["scheme_ftp"]  = AiEndpointGuard::validate("ftp://1.1.1.1/x", false)["ok"] ? 0 : 1;
$out["scheme_file"] = AiEndpointGuard::validate("file:///etc/passwd", false)["ok"] ? 0 : 1;
// codes carry no secrets
$v = AiEndpointGuard::validate("http://10.0.0.5", false);
$out["code"] = $v["code"]==="private_endpoint" ? 1 : 0;
echo wp_json_encode($out);
')"
g() { printf '%s' "$GR" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["'"$1"'"] ?? "";' 2>/dev/null; }
assert_eq "public endpoint allowed"            "1" "$(g public_ok)"
assert_eq "private endpoint blocked"           "1" "$(g private)"
assert_eq "loopback blocked (non-local)"       "1" "$(g loopback)"
assert_eq "link-local/metadata blocked"        "1" "$(g linklocal)"
assert_eq "ipv6 loopback blocked"              "1" "$(g ipv6_loop)"
assert_eq "declared-local loopback allowed"    "1" "$(g local_loop_ok)"
assert_eq "declared-local private allowed"     "1" "$(g local_priv_ok)"
assert_eq "ftp scheme blocked"                 "1" "$(g scheme_ftp)"
assert_eq "file scheme blocked"                "1" "$(g scheme_file)"
assert_eq "block code is descriptive"          "1" "$(g code)"

echo
echo "== 4. Functional: transport enforces the guard + disables redirects =="
TR="$(wpe '
use WPCommandCenter\Ai\Transport\OpenAiCompatibleTransport;
use WPCommandCenter\Ai\Http\AiHttpClient;
use WPCommandCenter\Ai\Contract\GenerationRequest;
use WPCommandCenter\Ai\Contract\GenerationMessage;
use WPCommandCenter\Ai\Contract\GenerationTextPart;
$req = new GenerationRequest("gpt-5", 100, [ new GenerationMessage("user",[ new GenerationTextPart("hi") ]) ]);
$out = [];

// (a) real guard: openai (non-local) + private endpoint → blocked, NO outbound call.
$called = 0;
$t1 = new OpenAiCompatibleTransport(new AiHttpClient(function($u,$a)use(&$called){ $called=1; return ["response"=>["code"=>200],"body"=>"{}"]; }));
$r1 = $t1->generate($req, "sk-x", "https://10.0.0.9/v1", "openai", "");
$out["blocked_no_call"] = (!$r1->is_ok() && $r1->code()==="endpoint_blocked" && $called===0) ? 1 : 0;

// (b) real guard: declared-local provider (ollama) + loopback → allowed, call made.
$cap=[];
$t2 = new OpenAiCompatibleTransport(new AiHttpClient(function($u,$a)use(&$cap){ $cap=$a; return ["response"=>["code"=>200],"body"=>wp_json_encode(["choices"=>[["message"=>["content"=>"ok"]]]])]; }));
$r2 = $t2->generate($req, "sk-x", "http://127.0.0.1:11434/v1", "ollama", "");
$out["local_allowed"]   = ($r2->is_ok() && $r2->text()==="ok") ? 1 : 0;
$out["redirects_off"]   = ((int)($cap["redirection"]??-1)===0) ? 1 : 0; // custom endpoint follows no redirects

// (c) real guard: public endpoint via IP literal → allowed.
$t3 = new OpenAiCompatibleTransport(new AiHttpClient(function($u,$a){ return ["response"=>["code"=>200],"body"=>wp_json_encode(["choices"=>[["message"=>["content"=>"pub"]]]])]; }));
$r3 = $t3->generate($req, "sk-x", "https://1.1.1.1/v1", "openai", "");
$out["public_allowed"]  = ($r3->is_ok() && $r3->text()==="pub") ? 1 : 0;

// (d) blocked message carries no key/secret.
$out["no_secret_leak"]  = (strpos($r1->message(),"sk-x")===false) ? 1 : 0;
echo wp_json_encode($out);
')"
t() { printf '%s' "$TR" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["'"$1"'"] ?? "";' 2>/dev/null; }
assert_eq "private endpoint blocked, no outbound call" "1" "$(t blocked_no_call)"
assert_eq "declared-local loopback allowed end-to-end" "1" "$(t local_allowed)"
assert_eq "custom endpoint disables redirects"         "1" "$(t redirects_off)"
assert_eq "public endpoint allowed end-to-end"         "1" "$(t public_allowed)"
assert_eq "blocked message leaks no secret"            "1" "$(t no_secret_leak)"

echo
echo "== 5. Anthropic path unchanged (no redirect arg, byte-identical body) =="
AN="$(wpe '
use WPCommandCenter\Ai\AnthropicClient;
use WPCommandCenter\Ai\Transport\AnthropicTransport;
use WPCommandCenter\Ai\Http\AiHttpClient;
$prev=get_option("wpcc_anthropic_api_key",null); update_option("wpcc_anthropic_api_key","k");
$cap=[]; $s=function($u,$a)use(&$cap){ $cap=$a; return ["response"=>["code"=>200],"body"=>"{}"]; };
$c=new AnthropicClient(new AnthropicTransport(new AiHttpClient($s)));
$c->send([["role"=>"user","content"=>[["type"=>"text","text"=>"x"]]]], 10, "claude-sonnet-4-6");
$out=[];
$out["no_redirect_arg"] = array_key_exists("redirection",$cap) ? 0 : 1; // Anthropic must NOT set redirection
$out["body_ok"] = (($cap["body"]??"")===wp_json_encode(["model"=>"claude-sonnet-4-6","max_tokens"=>10,"messages"=>[["role"=>"user","content"=>[["type"=>"text","text"=>"x"]]]]])) ? 1 : 0;
if(null!==$prev)update_option("wpcc_anthropic_api_key",$prev);else delete_option("wpcc_anthropic_api_key");
echo wp_json_encode($out);
')"
a() { printf '%s' "$AN" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["'"$1"'"] ?? "";' 2>/dev/null; }
assert_eq "Anthropic sets NO redirection arg"  "1" "$(a no_redirect_arg)"
assert_eq "Anthropic body byte-identical"      "1" "$(a body_ok)"

echo
echo "== 6. Invariants unchanged =="
assert_eq "OPERATION_MAP == 34" "34" "$(wpe 'echo count(\WPCommandCenter\Operations\CapabilityRegistry::OPERATION_MAP);')"
assert_eq "capabilities == 23"  "23" "$(wpe 'echo count(\WPCommandCenter\Operations\CapabilityRegistry::ALL_CAPABILITIES);')"
assert_eq "catalogue == 40"     "40" "$(wpe 'echo count((new \WPCommandCenter\Operations\OperationRegistry())->get_operations());')"
assert_eq "DB_VERSION 2.5.0"    "2.5.0" "$(wpe 'echo \WPCommandCenter\Core\Schema::DB_VERSION;')"

echo ""
echo "RESULT: ${PASS} passed, ${FAIL} failed"
[ "$FAIL" -eq 0 ]
