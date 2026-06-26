#!/usr/bin/env bash
#
# Phase C — Universal AI Provider Runtime: capability gate.
#
# Proves the gate is wired into the three generators, is provider-neutral and
# I/O-free, and is BEHAVIOUR-NEUTRAL: for every declared provider the gate is
# OPEN (vision is never declared 'no'), so generation is identical to Phase B.
# Also proves the matching primitives — including correct detection of a declared
# 'no' (the only thing that would close the gate) and warn-allow for unknown/model.
#
# Honest coverage note: with the current declared capability data NO provider
# lacks vision, so the gate's CLOSED end-to-end path is a forward-looking guard
# and cannot be reached without synthetic data (which Phase C does not add). The
# closing CONDITION is proven at the primitive level (supports()=='no').
#
# Offline; requires wp-cli for the functional sections.

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

GATE="$PLUGIN_DIR/includes/Ai/CapabilityGate.php"
SEO="$PLUGIN_DIR/includes/Seo/SeoMetaGenerator.php"
ALT="$PLUGIN_DIR/includes/AltText/AltTextGenerator.php"
CNT="$PLUGIN_DIR/includes/Content/ContentFieldGenerator.php"

echo "Phase C — capability gate (behaviour-neutral validation)"

echo
echo "== 1. Static: gate is provider-neutral + I/O-free =="
has  "gate exposes check()"        "function check("        "$GATE"
has  "gate exposes requirements()" "function requirements(" "$GATE"
has  "gate exposes supports()"     "function supports("     "$GATE"
has  "gate reads declared data"    "Capabilities::for_provider" "$GATE"
lacks "gate: no raw HTTP"          "wp_remote"  "$GATE"
lacks "gate: no option reads"      "get_option" "$GATE"
lacks "gate: no option writes"     "update_option" "$GATE"
lacks "gate: no provider selection/routing" "active(" "$GATE"
lacks "gate: no proposal/engine"   "ProposalStore" "$GATE"

echo
echo "== 2. Static: the three generators invoke the gate after resolution =="
has  "SEO calls gate (seo_meta)"      "CapabilityGate::check( 'seo_meta'"   "$SEO"
has  "Alt calls gate (alt_text)"      "CapabilityGate::check( 'alt_text'"   "$ALT"
has  "Content calls gate (ai_content)" "CapabilityGate::check( 'ai_content'" "$CNT"
has  "SEO capability skip reason"     "capability_unsupported" "$SEO"
has  "Alt capability skip reason"     "capability_unsupported" "$ALT"
has  "Content capability skip reason" "capability_unsupported" "$CNT"

if ! command -v wp >/dev/null 2>&1; then
	echo; echo "  SKIP: wp-cli not available — static checks only."
	echo ""; echo "RESULT: ${PASS} passed, ${FAIL} failed"; [ "$FAIL" -eq 0 ]; exit $?
fi

echo
echo "== 3. Unit: feature-needs taxonomy =="
RES="$(wpe '
use WPCommandCenter\Ai\CapabilityGate;
use WPCommandCenter\Ai\Platform\Capabilities;
$out = [];
$out["version"]   = (string) CapabilityGate::VERSION;
$out["req_alt"]   = implode( ",", CapabilityGate::requirements("alt_text") );
$out["req_seo"]   = implode( ",", CapabilityGate::requirements("seo_meta") );
$out["req_cnt"]   = implode( ",", CapabilityGate::requirements("ai_content") );
// every required capability must be a known taxonomy key
$keys = array_keys( Capabilities::keys() );
$ok = true;
foreach ( ["seo_meta","alt_text","ai_content"] as $f ) {
    foreach ( CapabilityGate::requirements($f) as $cap ) { if ( ! in_array($cap,$keys,true) ) { $ok = false; } }
}
$out["req_subset"] = $ok ? 1 : 0;
echo wp_json_encode($out);
')"
g() { printf '%s' "$RES" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["'"$1"'"] ?? "";' 2>/dev/null; }
assert_eq "taxonomy VERSION is set"              "1"      "$(g version)"
assert_eq "alt_text requires vision"             "vision" "$(g req_alt)"
assert_eq "seo_meta requires nothing"            ""       "$(g req_seo)"
assert_eq "ai_content requires nothing"          ""       "$(g req_cnt)"
assert_eq "all requirements are known cap keys"  "1"      "$(g req_subset)"

echo
echo "== 4. Unit: supports() classification (yes / no / unknown) =="
SR="$(wpe '
use WPCommandCenter\Ai\CapabilityGate;
$out = [];
$out["anth_vision"] = CapabilityGate::supports("anthropic","vision");      // yes
$out["anth_audio"]  = CapabilityGate::supports("anthropic","audio");       // no
$out["anth_embed"]  = CapabilityGate::supports("anthropic","embeddings");  // no
$out["ollama_vis"]  = CapabilityGate::supports("ollama","vision");         // model -> unknown
$out["openai_tools"]= CapabilityGate::supports("openai","tools");          // yes (override)
$out["missing_cap"] = CapabilityGate::supports("anthropic","does_not_exist"); // unknown
echo wp_json_encode($out);
')"
s() { printf '%s' "$SR" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["'"$1"'"] ?? "";' 2>/dev/null; }
assert_eq "anthropic vision = yes"        "yes"     "$(s anth_vision)"
assert_eq "anthropic audio = no"          "no"      "$(s anth_audio)"
assert_eq "anthropic embeddings = no"     "no"      "$(s anth_embed)"
assert_eq "ollama vision (model) = unknown" "unknown" "$(s ollama_vis)"
assert_eq "openai tools (override) = yes" "yes"     "$(s openai_tools)"
assert_eq "missing capability = unknown"  "unknown" "$(s missing_cap)"

echo
echo "== 5. Unit: check() decisions — inert for ALL declared providers, closes only on 'no' =="
CR="$(wpe '
use WPCommandCenter\Ai\Platform\ProviderCatalog;
use WPCommandCenter\Ai\CapabilityGate;
$out = [];
// (a) behaviour-neutral: gate OPEN for every declared provider, every feature.
$all_open = true;
foreach ( array_keys( ProviderCatalog::all() ) as $p ) {
    foreach ( ["seo_meta","alt_text","ai_content"] as $f ) {
        if ( ! CapabilityGate::check($f,$p)["ok"] ) { $all_open = false; }
    }
}
$out["all_open"] = $all_open ? 1 : 0;
// (b) the verdict shape on open
$v = CapabilityGate::check("alt_text","anthropic");
$out["open_shape"] = ( $v["ok"]===true && $v["missing"]==="" && $v["message"]==="" ) ? 1 : 0;
// (c) closing CONDITION is satisfiable and correct: the gate closes IFF a required
//     capability is declared "no". anthropic declares audio="no", so the inner
//     condition fires for (anthropic, audio) — the exact predicate check() uses.
$out["close_condition"] = ( "no" === CapabilityGate::supports("anthropic","audio") ) ? 1 : 0;
// (d) honest: with current data no required capability is "no" for any provider,
//     so check() never returns closed (forward-looking guard). Confirm none close.
$any_closed = false;
foreach ( array_keys( ProviderCatalog::all() ) as $p ) {
    foreach ( ["seo_meta","alt_text","ai_content"] as $f ) {
        if ( ! CapabilityGate::check($f,$p)["ok"] ) { $any_closed = true; }
    }
}
$out["none_closed_today"] = $any_closed ? 0 : 1;
echo wp_json_encode($out);
')"
c() { printf '%s' "$CR" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["'"$1"'"] ?? "";' 2>/dev/null; }
assert_eq "gate OPEN for every declared provider × feature (behaviour-neutral)" "1" "$(c all_open)"
assert_eq "open verdict shape correct"          "1" "$(c open_shape)"
assert_eq "closing condition satisfiable ('no' detected)" "1" "$(c close_condition)"
assert_eq "no provider blocked today (forward-looking guard)" "1" "$(c none_closed_today)"

echo
echo "== 6. Invariants unchanged =="
assert_eq "OPERATION_MAP == 34" "34" "$(wpe 'echo count(\WPCommandCenter\Operations\CapabilityRegistry::OPERATION_MAP);')"
assert_eq "capabilities == 23"  "23" "$(wpe 'echo count(\WPCommandCenter\Operations\CapabilityRegistry::ALL_CAPABILITIES);')"
assert_eq "catalogue == 40"     "40" "$(wpe 'echo count((new \WPCommandCenter\Operations\OperationRegistry())->get_operations());')"
assert_eq "DB_VERSION 2.5.0"    "2.5.0" "$(wpe 'echo \WPCommandCenter\Core\Schema::DB_VERSION;')"

echo ""
echo "RESULT: ${PASS} passed, ${FAIL} failed"
[ "$FAIL" -eq 0 ]
