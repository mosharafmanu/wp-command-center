#!/usr/bin/env bash
#
# STEP 111 — GA#2 Slice 2a: shared Anthropic transport (AnthropicClient).
#
# Asserts the extracted transport: canonical + legacy key/model resolution
# (back-compat), config-only resolution (no outbound calls), error redaction,
# enforced timeout, that the HTTP call lives only in the client/provider layer,
# no WordPress writes, no ProposalStore/OperationExecutor usage, and invariants.
#
# Requires: wp-cli.

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

CLIENT="$PLUGIN_DIR/includes/Ai/AnthropicClient.php"
VISION="$PLUGIN_DIR/includes/AltText/AnthropicVisionProvider.php"
TRANSPORT="$PLUGIN_DIR/includes/Ai/Transport/AnthropicTransport.php"
HTTP="$PLUGIN_DIR/includes/Ai/Http/AiHttpClient.php"

echo "STEP 111 — GA#2 Slice 2a: shared Anthropic transport"
echo "Phase A — neutral runtime seam: wire relocated to AnthropicTransport over AiHttpClient"

echo
echo "== 1. Static: transport owns the wire; HTTP client owns the call; facade delegates =="
# Phase A relocated the Anthropic wire from the facade into the transport, and the
# single HTTP attempt into the shared client. The facade now delegates and keeps
# only key/model resolution + the timeout default.
has  "API endpoint (transport)"            "api.anthropic.com/v1/messages" "$TRANSPORT"
has  "anthropic version header (transport)" "anthropic-version" "$TRANSPORT"
has  "api key header (transport)"          "x-api-key"          "$TRANSPORT"
has  "timeout default (facade)"            "DEFAULT_TIMEOUT"    "$CLIENT"
has  "performs wp_remote_post (http client)" "wp_remote_post"   "$HTTP"
has  "redacts errors (transport)"          "Redactor"           "$TRANSPORT"
has  "redacts errors (http client)"        "Redactor"           "$HTTP"
has  "facade delegates to transport"       "AnthropicTransport" "$CLIENT"
lacks "facade performs no direct HTTP"     "wp_remote_post"     "$CLIENT"
# Key resolution names (canonical + legacy) all present.
has  "key: canonical constant"             "WPCC_ANTHROPIC_API_KEY" "$CLIENT"
has  "key: canonical option"               "wpcc_anthropic_api_key" "$CLIENT"
has  "key: legacy constant (back-compat)"  "WPCC_VISION_API_KEY" "$CLIENT"
has  "key: legacy option (back-compat)"    "wpcc_alt_text_api_key" "$CLIENT"
# Model resolution names (canonical + legacy) all present.
has  "model: canonical constant"           "WPCC_ANTHROPIC_MODEL" "$CLIENT"
has  "model: canonical option"             "wpcc_anthropic_model" "$CLIENT"
has  "model: legacy constant (back-compat)" "WPCC_VISION_MODEL"  "$CLIENT"
has  "model: legacy option (back-compat)"  "wpcc_alt_text_model" "$CLIENT"
lacks "client: no option writes"           "update_option"      "$CLIENT"
lacks "client: no post meta writes"        "update_post_meta"   "$CLIENT"
lacks "client: no db writes"               "wpdb"               "$CLIENT"
lacks "client: no ProposalStore use"       "new ProposalStore"  "$CLIENT"
lacks "client: no OperationExecutor use"   "new OperationExecutor" "$CLIENT"

echo
echo "== 2. Static: vision provider delegates transport (no direct HTTP) =="
has  "vision still implements AltTextProvider" "implements AltTextProvider" "$VISION"
has  "vision returns ProviderResult"       "ProviderResult"     "$VISION"
has  "vision keeps image size guard"       "MAX_IMAGE_BYTES"    "$VISION"
has  "vision uses the shared client"       "AnthropicClient"    "$VISION"
lacks "vision no direct wp_remote_post"    "wp_remote_post"     "$VISION"
lacks "vision no post meta writes"         "update_post_meta"   "$VISION"
lacks "vision no ProposalStore use"        "new ProposalStore"  "$VISION"
lacks "vision no OperationExecutor use"    "new OperationExecutor" "$VISION"

echo
echo "== 3. Functional: key + model resolution (env-aware; canonical + legacy back-compat) =="
# NOTE: this dev box may DEFINE a key constant (WPCC_VISION_API_KEY) — the same
# condition behind test-alt-text.sh's chronic env failures. Expectations are
# computed from what is actually defined, and send() is NEVER called with a real
# key (no network / no cost).
if ! command -v wp >/dev/null 2>&1; then
	echo "  SKIP: wp-cli not available — static checks only."
else
	RES="$(wpe '
		$canon_k = get_option("wpcc_anthropic_api_key", null);
		$leg_k   = get_option("wpcc_alt_text_api_key", null);
		$canon_m = get_option("wpcc_anthropic_model", null);
		$leg_m   = get_option("wpcc_alt_text_model", null);
		delete_option("wpcc_anthropic_api_key"); delete_option("wpcc_alt_text_api_key");
		delete_option("wpcc_anthropic_model");   delete_option("wpcc_alt_text_model");

		$anth_const = defined("WPCC_ANTHROPIC_API_KEY") && "" !== (string) WPCC_ANTHROPIC_API_KEY;
		$vis_const  = defined("WPCC_VISION_API_KEY") && "" !== (string) WPCC_VISION_API_KEY;
		$C = new \WPCommandCenter\Ai\AnthropicClient();
		$out = [];

		// (a) No options set — source is the highest-precedence CONSTANT, else none.
		$exp_none = $anth_const ? "anthropic_constant" : ( $vis_const ? "vision_constant" : "none" );
		$out["a_ok"]  = ( $C->key_source() === $exp_none ) ? 1 : 0;
		$out["a_cfg"] = ( $C->is_configured() === ( $anth_const || $vis_const ) ) ? 1 : 0;

		// (b) Legacy option set — honoured unless a higher-precedence source exists.
		update_option("wpcc_alt_text_api_key", "legacy-key");
		$exp_leg = $anth_const ? "anthropic_constant" : ( $vis_const ? "vision_constant" : "vision_option" );
		$out["b_ok"]  = ( $C->key_source() === $exp_leg ) ? 1 : 0;
		$out["b_cfg"] = $C->is_configured() ? 1 : 0; // always configured now

		// (c) Canonical option added — beats legacy option AND the legacy constant.
		update_option("wpcc_anthropic_api_key", "canon-key");
		$exp_canon = $anth_const ? "anthropic_constant" : "anthropic_option";
		$out["c_ok"] = ( $C->key_source() === $exp_canon ) ? 1 : 0;

		// (d) Not-configured send() — only safe to call when NO key exists at all.
		delete_option("wpcc_anthropic_api_key"); delete_option("wpcc_alt_text_api_key");
		if ( ! $C->is_configured() ) {
			$r = $C->send([[ "role"=>"user","content"=>[[ "type"=>"text","text"=>"hi" ]] ]], 50, "m");
			$out["nc"] = ( is_array($r) && empty($r["ok"]) && ($r["code"]??"") === "not_configured" ) ? 1 : 0;
		} else {
			$out["nc"] = "skip"; // a key constant is defined on this env
		}

		// Model resolution (values observable; not secret).
		$out["m_def"]   = $C->model("def-model");                                  // no model set -> default
		update_option("wpcc_alt_text_model", "legacy-model");
		$out["m_leg"]   = $C->model("def-model");                                  // legacy back-compat
		update_option("wpcc_anthropic_model", "canon-model");
		$out["m_canon"] = $C->model("def-model");                                  // canonical wins

		// restore
		delete_option("wpcc_anthropic_api_key"); delete_option("wpcc_alt_text_api_key");
		delete_option("wpcc_anthropic_model");   delete_option("wpcc_alt_text_model");
		if (null!==$canon_k) update_option("wpcc_anthropic_api_key",$canon_k);
		if (null!==$leg_k)   update_option("wpcc_alt_text_api_key",$leg_k);
		if (null!==$canon_m) update_option("wpcc_anthropic_model",$canon_m);
		if (null!==$leg_m)   update_option("wpcc_alt_text_model",$leg_m);

		echo wp_json_encode($out);
	')"
	getj() { printf '%s' "$RES" | php -r '$d=json_decode(stream_get_contents(STDIN),true); $v=$d["'"$1"'"]??""; echo is_bool($v)?($v?"1":"0"):$v;' 2>/dev/null; }

	assert_eq "no-options key_source matches precedence" "1" "$(getj a_ok)"
	assert_eq "no-options is_configured matches env"      "1" "$(getj a_cfg)"
	assert_eq "legacy key honoured (back-compat)"         "1" "$(getj b_ok)"
	assert_eq "legacy key -> configured"                  "1" "$(getj b_cfg)"
	assert_eq "canonical key wins over legacy"            "1" "$(getj c_ok)"
	NC="$(getj nc)"
	if [ "$NC" = "skip" ]; then echo "  NOTE: a key constant is defined on this env — not_configured send() path skipped (covered when no key)."; else assert_eq "send() with no key -> not_configured (errors as data)" "1" "$NC"; fi
	assert_eq "model default applies"                     "def-model"   "$(getj m_def)"
	assert_eq "legacy model -> back-compat"               "legacy-model" "$(getj m_leg)"
	assert_eq "canonical model wins"                      "canon-model"  "$(getj m_canon)"

	echo
	echo "== 5. Functional: vision provider behavior preserved =="
	VR="$(wpe '
		$P = new \WPCommandCenter\AltText\AnthropicVisionProvider();
		$out = [];
		$out["impl"] = ( $P instanceof \WPCommandCenter\AltText\AltTextProvider ) ? 1 : 0;
		// Image validation returns the exact ProviderResult error (force a key so the
		// not_configured branch is bypassed; bad path fails BEFORE any network call).
		$leg = get_option("wpcc_alt_text_api_key", null); update_option("wpcc_alt_text_api_key","k");
		$r1 = $P->suggest_alt([ "path"=>"/no/such/file.jpg", "mime"=>"image/jpeg" ]);
		$out["unreadable"] = ( ! $r1->is_ok() && $r1->get_error()["code"] === "image_unreadable" ) ? 1 : 0;
		$out["prov"] = ( $r1->provider() === "anthropic" ) ? 1 : 0;
		$r2 = $P->suggest_alt([ "path"=>"/x", "mime"=>"text/plain" ]);
		$out["unsupported"] = ( ! $r2->is_ok() && $r2->get_error()["code"] === "image_unreadable" ) ? 1 : 0; // missing file caught first
		if (null!==$leg) update_option("wpcc_alt_text_api_key",$leg); else delete_option("wpcc_alt_text_api_key");
		echo wp_json_encode($out);
	')"
	getv() { printf '%s' "$VR" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["'"$1"'"] ?? "";' 2>/dev/null; }
	assert_eq "vision still implements AltTextProvider" "1" "$(getv impl)"
	assert_eq "vision image_unreadable preserved"       "1" "$(getv unreadable)"
	assert_eq "vision provenance preserved (anthropic)" "1" "$(getv prov)"
fi

echo
echo "== 6. Invariants unchanged =="
assert_eq "OPERATION_MAP == 34" "34" "$(wpe 'echo count(\WPCommandCenter\Operations\CapabilityRegistry::OPERATION_MAP);')"
assert_eq "capabilities == 23"  "23" "$(wpe 'echo count(\WPCommandCenter\Operations\CapabilityRegistry::ALL_CAPABILITIES);')"
assert_eq "catalogue == 40"     "40" "$(wpe 'echo count((new \WPCommandCenter\Operations\OperationRegistry())->get_operations());')"
assert_eq "DB_VERSION 2.5.0"    "2.5.0" "$(wpe 'echo \WPCommandCenter\Core\Schema::DB_VERSION;')"

echo ""
echo "RESULT: ${PASS} passed, ${FAIL} failed"
[ "$FAIL" -eq 0 ]
