#!/usr/bin/env bash
#
# CDS Phase 0 — Command Design System foundation freeze (UI-only).
#
# Validates the additive token/component/runtime foundation that Phase 1 adoption
# (Operations Explorer pilot) consumes, WITHOUT any route/op/cap/MCP/schema change:
#
#   - Token foundation (wpcc-tokens.css): button / field / table / badge-tag /
#     focus-ring / state-info tokens added; existing tiers preserved (additive).
#   - Component layer (wpcc-cds.css): button / field / tag / light-table / error
#     classes added; existing components preserved.
#   - Runtime (wpcc-cds.js): WPCC.cds gains button / statusPill / tag / error and
#     pill/riskPill accept an optional aria-label; existing helpers preserved.
#   - Backward-compatibility: nothing removed from the public WPCC.cds surface.
#   - Foundation is asset-only: no REST route / operation / capability / MCP tool /
#     schema is introduced by these files.
#   - Invariants unchanged: 34 / 23 / 40 / 40 / 2.5.0.
#
# Requires: node (JS syntax), rg, wp-cli (invariants). Usage: bash tests/test-cds-foundation.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_ROOT="$(cd "$PLUGIN_DIR/../../.." && pwd)"

# shellcheck source=/dev/null
[ -f "$PLUGIN_DIR/wpcc-env.sh" ] && source "$PLUGIN_DIR/wpcc-env.sh"

TOKENS="$PLUGIN_DIR/assets/css/wpcc-tokens.css"
CDS_CSS="$PLUGIN_DIR/assets/css/wpcc-cds.css"
CDS_JS="$PLUGIN_DIR/assets/js/wpcc-cds.js"
RUNTIME_JS="$PLUGIN_DIR/assets/js/wpcc-admin-runtime.js"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }
has()  { if rg -q -e "$2" "$3"; then pass "$1"; else fail "$1"; fi; }
lacks(){ if rg -q -e "$2" "$3"; then fail "$1"; else pass "$1"; fi; }
wpe()  { wp --path="$WP_ROOT" eval "$1" 2>/dev/null; }

echo "== 1. Asset syntax =="
if command -v node >/dev/null 2>&1; then
	node --check "$CDS_JS"     >/dev/null 2>&1 && pass "wpcc-cds.js parses"          || fail "wpcc-cds.js parses"
	node --check "$RUNTIME_JS" >/dev/null 2>&1 && pass "wpcc-admin-runtime.js parses" || fail "wpcc-admin-runtime.js parses"
else
	echo "  SKIP: node not available — JS syntax check skipped."
fi
# CSS brace balance (cheap structural lint).
for f in "$TOKENS" "$CDS_CSS"; do
	o="$(grep -o '{' "$f" | wc -l | tr -d ' ')"; c="$(grep -o '}' "$f" | wc -l | tr -d ' ')"
	assert_eq "$(basename "$f") braces balanced" "$o" "$c"
done

echo
echo "== 2. Token foundation (additive) =="
has "button tokens present"               "--wpcc-btn-primary-bg"        "$TOKENS"
has "field tokens present"                "--wpcc-field-focus-border"    "$TOKENS"
has "table/data-grid prep tokens present" "--wpcc-table-header-bg"       "$TOKENS"
has "tag tokens present"                  "--wpcc-tag-bg"                "$TOKENS"
has "focus-ring token present"            "--wpcc-focus-ring"            "$TOKENS"
has "state-info token present"            "--wpcc-state-info-bg"         "$TOKENS"
# Existing tiers preserved (additive, not a rewrite).
has "primitives preserved"                "--wpcc-blue-600"              "$TOKENS"
has "semantic risk tiers preserved"       "--wpcc-risk-critical-bg"      "$TOKENS"
has "actor identity tokens preserved"     "--wpcc-actor-agent-fg"        "$TOKENS"
has "single brand-accent override point"  "--wpcc-brand-accent"          "$TOKENS"
# Density binding present for the new table cell token.
has "compact density still defined"       "data-wpcc-density=\"compact\"" "$TOKENS"

echo
echo "== 3. Component layer (additive) =="
has "button component"                    "\.wpcc-cds-btn"               "$CDS_CSS"
has "button primary variant"              "\.wpcc-cds-btn--primary"     "$CDS_CSS"
has "button danger variant"               "\.wpcc-cds-btn--danger"      "$CDS_CSS"
has "field focus-ring component"          "\.wpcc-cds-field"            "$CDS_CSS"
has "metadata tag component"              "\.wpcc-cds-tag"             "$CDS_CSS"
has "light table treatment"               "\.wpcc-cds-table"           "$CDS_CSS"
has "error state component"               "\.wpcc-cds-error"           "$CDS_CSS"
# Existing components preserved.
has "trust chips preserved"               "\.wpcc-cds-chip--reversible" "$CDS_CSS"
has "risk pills preserved"                "\.wpcc-cds-pill--critical"   "$CDS_CSS"
has "actor chips preserved"               "\.wpcc-cds-actor--agent"     "$CDS_CSS"
has "empty/loading preserved"             "\.wpcc-cds-empty"            "$CDS_CSS"
# Token discipline: the new components bind to tokens, not hardcoded color.
NEWBLOCK="$(awk '/Phase 0 — Button \/ Field \/ Table/,0' "$CDS_CSS")"
if printf '%s' "$NEWBLOCK" | rg -q -e "#[0-9a-fA-F]{6}"; then fail "new CDS components avoid hardcoded hex"; else pass "new CDS components avoid hardcoded hex"; fi

echo
echo "== 4. Runtime helpers (additive, backward-compatible) =="
has "WPCC.cds.button helper"              "button: function"            "$CDS_JS"
has "WPCC.cds.statusPill helper"          "statusPill: function"        "$CDS_JS"
has "WPCC.cds.tag helper"                 "tag: function"               "$CDS_JS"
has "WPCC.cds.error helper"               "error: function"             "$CDS_JS"
has "pill accepts optional aria-label"    "pill: function \( variant, label, ariaLabel \)" "$CDS_JS"
has "riskPill accepts optional aria-label" "riskPill: function \( tier, label, ariaLabel \)" "$CDS_JS"
# Existing helpers preserved (no breaking removal).
has "chip helper preserved"               "chip: function"              "$CDS_JS"
has "actorChip helper preserved"          "actorChip: function"         "$CDS_JS"
has "kpi helper preserved"                "kpi: function"               "$CDS_JS"
has "empty helper preserved"              "empty: function"             "$CDS_JS"
has "loading helper preserved"            "loading: function"           "$CDS_JS"
# Shared runtime escape/api still the single source.
has "runtime exposes escHtml"             "WPCC.escHtml = function"     "$RUNTIME_JS"
has "runtime exposes nonce'd api"         "WPCC.api = function"         "$RUNTIME_JS"

echo
echo "== 5. Foundation is asset-only (no route/op/cap/MCP/schema in these files) =="
for f in "$TOKENS" "$CDS_CSS" "$CDS_JS" "$RUNTIME_JS"; do
	lacks "no REST route in $(basename "$f")"   "register_rest_route"                 "$f"
	lacks "no capability map in $(basename "$f")" "OPERATION_MAP|ALL_CAPABILITIES"   "$f"
done

echo
echo "== 6. Invariants unchanged (34 / 23 / 40 / 40 / 2.5.0) =="
if ! command -v wp >/dev/null 2>&1; then
	echo "  SKIP: wp-cli not available — invariant checks skipped."
else
	assert_eq "OPERATION_MAP stays 34" "34" "$(wpe 'echo count( \WPCommandCenter\Operations\CapabilityRegistry::OPERATION_MAP );')"
	assert_eq "ALL_CAPABILITIES stays 23" "23" "$(wpe 'echo count( \WPCommandCenter\Operations\CapabilityRegistry::ALL_CAPABILITIES );')"
	assert_eq "catalogue stays 40" "40" "$(wpe '$r = new \WPCommandCenter\Operations\OperationRegistry(); echo count( $r->get_operations() );')"
	assert_eq "MCP tools stay 40" "40" "$(wpe '$r = ( new \WPCommandCenter\Mcp\McpServerRuntime() )->handle( [ "jsonrpc" => "2.0", "id" => 1, "method" => "tools/list" ], [] ); echo isset( $r["result"]["tools"] ) ? count( $r["result"]["tools"] ) : -1;')"
	assert_eq "DB_VERSION stays 2.5.0" "2.5.0" "$(wpe 'echo get_option("wpcc_db_version");')"
fi

echo
echo "== RESULT =="
echo "  PASS=$PASS FAIL=$FAIL"
[ "$FAIL" -eq 0 ]
