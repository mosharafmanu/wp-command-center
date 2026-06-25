#!/usr/bin/env bash
#
# STEP 107.1 — Token & Capability Manager (read surface) acceptance suite.
#
# Validates the read-only wp-admin visibility layer over the API token system
# (AuthTokens, STEP 10) and the per-token capability assignments
# (CapabilityRegistry, STEP 38/44/79) WITHOUT introducing any runtime/MCP/
# storage/policy changes:
#
#   - PHP lint of every new/changed admin file
#   - Admin REST read routes registered (tokens / tokens/{id} / capabilities /
#     operations-map) behind a manage_options + FeatureGate permission gate
#   - The aggregation class is read-only: it never writes the token manifest or
#     the capability-assignments option, and never re-implements policy
#   - Menu: "Tokens & Capabilities" submenu added, FeatureGate-gated
#   - View: three URL-driven tabs, token detail, NO write controls (read-only),
#     output rendered exclusively through an HTML-escaper (XSS discipline)
#   - Functional (wp-cli, real bootstrap path): capability catalogue = 23,
#     operation map = 34, a read_only token resolves to EXACTLY the 5 read-only-
#     scope operations, a full token resolves to system.admin / all 34 allowed,
#     and no token secret (token_hash) is ever surfaced
#   - STEP 107.2: the token detail carries a read-only, bounded, chronological,
#     per-token AuditLog trail (capability.bootstrap present, filtered/isolated
#     per token, never written by the query)
#   - STEP 107.3: capability assign/remove via the REAL admin routes, routed
#     THROUGH OperationExecutor::run('capability_manage') (no bypass), recording
#     audit, updating the real assignment, honouring the system.admin refusal
#     guard, and 404-ing unknown tokens; the manager view exposes confirm-modal
#     write controls disabled for system.admin tokens
#   - STEP 107.4: token create/revoke/delete via the REAL admin routes, REUSING
#     AuthTokens (capability bootstrap/deprovision automatic), returning the raw
#     secret once and never the hash, recording admin.token.* audit; the token UI
#     is fully migrated off settings.php (no AuthTokens calls remain there) with a
#     legacy redirect to the new manager
#   - Invariants: operation_map stays 34, capabilities stay 23 (this step adds
#     no runtime op, MCP tool, or capability)
#
# Requires: php, rg, wp-cli, wpcc-env.sh. (Admin routes are cookie+nonce, so the
# functional checks exercise the aggregation class directly via wp-cli.)
# Usage: bash tests/test-token-capability-admin.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_ROOT="$(cd "$PLUGIN_DIR/../../.." && pwd)"

# shellcheck source=/dev/null
[ -f "$PLUGIN_DIR/wpcc-env.sh" ] && source "$PLUGIN_DIR/wpcc-env.sh"

VIEW="$PLUGIN_DIR/includes/Admin/views/token-capability-manager.php"
RESTAPI="$PLUGIN_DIR/includes/Admin/AdminRestApi.php"
QUERY="$PLUGIN_DIR/includes/Admin/TokenCapabilityAdminQuery.php"
MENU="$PLUGIN_DIR/includes/Admin/AdminMenu.php"
SHELL="$PLUGIN_DIR/includes/Admin/AppShell.php"
SETTINGS="$PLUGIN_DIR/includes/Admin/views/settings.php"
REGISTRY="$PLUGIN_DIR/includes/Operations/CapabilityRegistry.php"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq()  { local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }
has()  { if rg -q -e "$2" "$3"; then pass "$1"; else fail "$1"; fi; }
lacks(){ if rg -q -e "$2" "$3"; then fail "$1"; else pass "$1"; fi; }
lint() { if php -l "$2" >/dev/null 2>&1; then pass "$1"; else fail "$1"; fi; }
wpe()  { wp --path="$WP_ROOT" eval "$1" 2>/dev/null; }

# Capture the security mode up front so cleanup can restore it even if the
# write-path eval is interrupted after pinning developer mode.
SAVED_MODE="$(wpe 'echo get_option("wpcc_security_mode","developer");' 2>/dev/null)"
[ -n "$SAVED_MODE" ] || SAVED_MODE="developer"

# Best-effort cleanup of any leftover test tokens + mode restore (runs on exit).
cleanup() {
	wpe '$at = new \WPCommandCenter\Security\AuthTokens();
		foreach ( $at->list() as $t ) {
			if ( str_starts_with( (string) ( $t["label"] ?? "" ), "wpcc-test-107" ) ) { $at->delete( $t["id"] ); }
		}
		update_option( "wpcc_security_mode", "'"$SAVED_MODE"'" );' >/dev/null 2>&1
}
trap cleanup EXIT

echo "== 1. PHP lint =="
lint "view lints"                     "$VIEW"
lint "AdminRestApi lints"             "$RESTAPI"
lint "TokenCapabilityAdminQuery lints" "$QUERY"
lint "AdminMenu lints"                "$MENU"
lint "settings.php lints"             "$SETTINGS"

echo
echo "== 2. Admin REST routes registered (read-only) =="
has "route: /admin/tokens"            "'/admin/tokens'"            "$RESTAPI"
has "route: /admin/tokens/{id}"       "/admin/tokens/\(\?P<id>"   "$RESTAPI"
has "route: /admin/capabilities"      "'/admin/capabilities'"     "$RESTAPI"
has "route: /admin/operations-map"    "'/admin/operations-map'"   "$RESTAPI"
has "permission gate helper present"  "function check_tokens_permission" "$RESTAPI"
has "tokens surface maps to FeatureGate key (C1 consolidated gate)" "'tokens'\s*=> 'token_capability_manager'" "$RESTAPI"
has "handlers delegate to query"      "new TokenCapabilityAdminQuery\(\)" "$RESTAPI"
# STEP 107.3 — capability write routes (assign POST / remove DELETE).
has "route: POST .../capabilities"    "36\}\)/capabilities',"      "$RESTAPI"
has "route: DELETE .../capabilities/{cap}" "capabilities/\(\?P<cap>" "$RESTAPI"
has "assign handler present"          "function token_assign_capability" "$RESTAPI"
has "remove handler present"          "function token_remove_capability" "$RESTAPI"

echo
echo "== 2b. Capability writes route THROUGH the engine (no bypass) =="
has "writes call OperationExecutor"   "OperationExecutor\(\) \)->run\( 'capability_manage'" "$RESTAPI"
has "actor is admin_ui source"        "'source'     => 'admin_ui'" "$RESTAPI"
lacks "no direct CapabilityRegistry write in REST" "->assign\(|->remove\(" "$RESTAPI"
lacks "no direct CapabilityManager call in REST"   "new CapabilityManager" "$RESTAPI"
# The admin write actor must NEVER carry token_id/token_scope (so the engine's
# token-cap gate is skipped and manage_options is the gate). Scope the check to the
# admin_actor() helper body — the audit context legitimately records token_id.
ACTOR_BLOCK="$(awk '/private function admin_actor/,/^\t}/' "$RESTAPI")"
if printf '%s' "$ACTOR_BLOCK" | rg -q -e "token_id|token_scope"; then fail "admin_actor() carries no token_id/token_scope"; else pass "admin_actor() carries no token_id/token_scope"; fi

echo
echo "== 2c. Token lifecycle routes + AuthTokens REUSE (STEP 107.4) =="
has "create handler present"          "function token_create"     "$RESTAPI"
has "revoke handler present"          "function token_revoke"     "$RESTAPI"
has "delete handler present"          "function token_delete"     "$RESTAPI"
has "revoke route registered"         "36\}\)/revoke',"           "$RESTAPI"
has "create reuses AuthTokens::create" "new AuthTokens\(\) \)->create\(" "$RESTAPI"
has "revoke reuses AuthTokens::revoke" "->revoke\( \\\$id \)"      "$RESTAPI"
has "delete reuses AuthTokens::delete" "->delete\( \\\$id \)"      "$RESTAPI"
# Reuse, not reimplementation: no token-storage internals in the REST layer.
# (safe_token_record() legitimately *strips* token_hash; the storage internals
# below would indicate a reimplementation and must be absent.)
lacks "no token storage reimpl (hash)" "hash_hmac|wp_generate_password|wpcc-tokens" "$RESTAPI"
# Lifecycle is auditable (admin.token.* mirrors admin.approval.*).
has "audit: admin.token.created"      "'admin.token.created'"     "$RESTAPI"
has "audit: admin.token.revoked"      "'admin.token.revoked'"     "$RESTAPI"
has "audit: admin.token.deleted"      "'admin.token.deleted'"     "$RESTAPI"
# Literal routes registered before the bare /tokens/{id} wildcard.
LIT=$(rg -n "'/admin/capabilities'" "$RESTAPI" | head -1 | cut -d: -f1)
WILD=$(rg -n "/admin/tokens/\(\?P<id>" "$RESTAPI" | head -1 | cut -d: -f1)
if [ -n "$LIT" ] && [ -n "$WILD" ] && [ "$LIT" -lt "$WILD" ]; then pass "literal routes before /tokens/{id} wildcard"; else fail "literal-before-wildcard ordering ($LIT vs $WILD)"; fi

echo
echo "== 3. Aggregation class is read-only (no writes, no policy) =="
has "reads token manifest"            "AuthTokens"                "$QUERY"
has "reads capability registry"       "CapabilityRegistry"        "$QUERY"
lacks "no write to assignments"       "save_assignments|update_option|->assign\(|->remove\(" "$QUERY"
lacks "no token mutation"             "->create\(|->revoke\(|->delete\(|->validate\(" "$QUERY"
lacks "no engine dispatch"            "OperationExecutor"         "$QUERY"
# Static guard: the manifest's token_hash key is never read/output by the query
# (it only appears in doc comments). The functional check below proves the
# rendered output carries no hash.
lacks "token_hash key never read/output" "\['token_hash'\]|'token_hash'\s*=>" "$QUERY"
# STEP 107.2 — the audit trail is a read-only tail; the query never WRITES audit.
has   "audit trail reads AuditLog tail"  "->tail\("                 "$QUERY"
lacks "no audit writes (record)"         "AuditLog\(\)\)?->record\(|->record\(" "$QUERY"

echo
echo "== 4. App Shell hosts Tokens & Capabilities as Access › Tokens =="
# Experience Layer: the standalone submenu became the Access › Tokens tab, routed
# by the 5-C App Shell via ?wpcc_tab=tokens; the legacy slug redirects in.
has "Access tab labeled in shell"     "__\( 'Access', 'wp-command-center' \)"   "$SHELL"
has "Tokens tab renders the manager view" "'view' => 'token-capability-manager'" "$SHELL"
has "Tokens tab gated by token_capability_manager feature" "'feature' => 'token_capability_manager'" "$SHELL"
has "FeatureGate gates the Tokens tab" "FeatureGate::allows"      "$SHELL"
has "legacy tokens slug redirects (map)" "'wpcc-tokens'             => \[ self::SETTINGS_SLUG, 'access' \]" "$SHELL"
has "Settings section registered"      "render_settings"          "$MENU"

echo
echo "== 5. View is read-only + escaped =="
has "tab: Tokens"                     "Tokens"                    "$VIEW"
has "tab: Capabilities"               "Capabilities"              "$VIEW"
has "tab: Operation Map"              "Operation Map"             "$VIEW"
has "HTML escaper present"            "function escHtml"          "$VIEW"
has "honesty note for system.admin"   "system.admin"             "$VIEW"
has "audit trail section (107.2)"     "audit_trail"              "$VIEW"
has "audit trail title rendered"      "Capability audit trail"   "$VIEW"
# STEP 107.3 — capability management controls (assign/remove) + honesty rule.
has "manage section present"          "Manage capabilities"      "$VIEW"
has "assign control"                  "wpcc-cap-assign"          "$VIEW"
has "remove control"                  "wpcc-cap-remove"          "$VIEW"
has "confirm modal (role=dialog)"     "role=\"dialog\""          "$VIEW"
has "result region (role=status)"     "role=\"status\""          "$VIEW"
has "writes go to capabilities route" "/capabilities"            "$VIEW"
has "honesty: admin editing locked"   "adminLocked|is_admin"     "$VIEW"
# STEP 107.4 — token lifecycle controls now live in the manager view.
has "create-token control"            "wpcc-create-token"        "$VIEW"
has "revoke-token control"            "wpcc-token-revoke"        "$VIEW"
has "delete-token control"            "wpcc-token-delete"        "$VIEW"
has "new-token (copy-once) region"    "wpcc-new-token"           "$VIEW"

echo
echo "== 5a2. S2.1 — server-side token pagination in the view =="
has "view sends limit"                "limit="                   "$VIEW"
has "view sends offset"               "offset="                  "$VIEW"
has "view consumes canonical items[]" "body.items"               "$VIEW"
has "view consumes total_count"       "total_count"              "$VIEW"
has "view consumes has_more"          "has_more"                 "$VIEW"
has "Prev/Next pager present"         "wpcc-tok-prev"            "$VIEW"
has "paged loader present"            "loadTokensPage"           "$VIEW"

echo
echo "== 5b. Full settings.php migration (STEP 107.4) =="
lacks "settings.php: no AuthTokens import" "use WPCommandCenter\\\\Security\\\\AuthTokens" "$SETTINGS"
lacks "settings.php: no new AuthTokens"    "new AuthTokens"        "$SETTINGS"
lacks "settings.php: no AuthTokens:: calls" "AuthTokens::"         "$SETTINGS"
lacks "settings.php: no token POST handlers" "create_token|revoke_token|delete_token" "$SETTINGS"
has   "settings.php: links to new manager"  "wpcc-tokens"          "$SETTINGS"
has   "settings.php: retains Security Mode"  "set_security_mode"    "$SETTINGS"
# Legacy redirect compatibility (Experience Layer consolidated handler).
has   "menu: consolidated redirect handler" "function redirect_legacy_slugs" "$MENU"
has   "menu: admin_init hook for redirect"  "'redirect_legacy_slugs'" "$MENU"
has   "menu: settings token section -> Settings/Access" "redirect_to\( AppShell::SETTINGS_SLUG, 'access' \)" "$MENU"

echo
echo "== 5c. STEP 107.5 — accessibility sweep =="
has "modal role=dialog"               "role=\"dialog\""           "$VIEW"
has "modal aria-modal"                "aria-modal=\"true\""        "$VIEW"
has "modal aria-describedby wired"    "aria-describedby=\"wpcc-cap-modal-msg\"" "$VIEW"
has "modal aria-labelledby wired"     "aria-labelledby=\"wpcc-cap-modal-title\"" "$VIEW"
has "focus trap (Tab) implemented"    "function trapFocus"        "$VIEW"
has "focus trap bound on Tab key"     "e.key === 'Tab'"           "$VIEW"
has "Escape closes modal"             "e.key === 'Escape'"        "$VIEW"
has "focus moves into modal on open"  "modalOk.focus\(\)"         "$VIEW"
has "focus returns to opener on close" "lastFocus.focus\(\)"      "$VIEW"
has "role=status live regions"        "role=\"status\""           "$VIEW"
has "aria-live polite on status"      "aria-live=\"polite\""       "$VIEW"

echo
echo "== 5d. STEP 107.5 — empty-state polish =="
has "tokens empty state"              "i18n.emptyTokens"          "$VIEW"
has "capabilities empty state"        "i18n.emptyCaps"            "$VIEW"
has "operations empty state"          "i18n.emptyOps"             "$VIEW"
has "caps render guards empty"        "! caps.length"            "$VIEW"
has "ops render guards empty"         "! ops.length"             "$VIEW"

echo
echo "== 5e. STEP 107.5 — i18n completeness (no raw user-facing JS strings) =="
RAW="$(grep -nE "'(>| )[A-Z][a-z]{3,}" "$VIEW" | grep -vE "i18n\.|escHtml|className|getElementById|querySelector|createElement|addEventListener|wpcc-|encodeURIComponent|Content-Type|X-WP-Nonce|JSON|Object|Array|Promise|wp-command-center|'POST'|'DELETE'|'GET'" || true)"
if [ -z "$RAW" ]; then pass "no raw user-facing strings in view JS"; else fail "raw user-facing strings found: $RAW"; fi
has "FeatureGate key (REST gate, via C1 map)" "'tokens'\s*=> 'token_capability_manager'" "$RESTAPI"
has "FeatureGate key (menu gate)"     "FeatureGate::allows" "$SHELL"

echo
echo "== 6. Functional (wp-cli, real bootstrap path) =="
RES="$(wpe '
	$q  = new \WPCommandCenter\Admin\TokenCapabilityAdminQuery();
	$at = new \WPCommandCenter\Security\AuthTokens();
	$out = [];
	$out["caps_total"] = $q->capabilities()["total"];
	$out["ops_total"]  = $q->operations_map()["total"];

	$ro = $at->create( "wpcc-test-107-ro", "read_only", null, 1 );
	$ro_id = $ro["record"]["id"];
	$dro = $q->token( $ro_id );
	$out["ro_allowed"] = count( array_filter( $dro["access_matrix"], fn( $m ) => $m["allowed"] ) );
	$out["ro_total"]   = count( $dro["access_matrix"] );
	$out["ro_admin"]   = $dro["token"]["is_admin"] ? 1 : 0;

	// STEP 107.2 — audit trail: token() now carries a bounded, read-only tail.
	$trail = $dro["audit_trail"] ?? null;
	$out["audit_is_array"]  = is_array( $trail ) ? 1 : 0;
	$out["audit_has_boot"]  = ( is_array( $trail ) && count( array_filter( $trail, fn( $e ) => "capability.bootstrap" === ( $e["action"] ?? "" ) ) ) > 0 ) ? 1 : 0;
	// Every surfaced entry must be a {timestamp,action,actor,capability} record.
	$out["audit_shaped"]    = ( is_array( $trail ) && ( empty( $trail ) || ( isset( $trail[0]["timestamp"], $trail[0]["action"] ) ) ) ) ? 1 : 0;
	$out["audit_bounded"]   = ( is_array( $trail ) && count( $trail ) <= 100 ) ? 1 : 0;
	// Chronological (oldest -> newest): timestamps non-decreasing.
	$chrono = 1;
	if ( is_array( $trail ) ) { for ( $i = 1; $i < count( $trail ); $i++ ) { if ( (int) $trail[$i]["timestamp"] < (int) $trail[$i-1]["timestamp"] ) { $chrono = 0; break; } } }
	$out["audit_chrono"]    = $chrono;

	$full = $at->create( "wpcc-test-107-full", "full", null, 1 );
	$full_id = $full["record"]["id"];
	$dfull = $q->token( $full_id );
	$out["full_allowed"] = count( array_filter( $dfull["access_matrix"], fn( $m ) => $m["allowed"] ) );
	$out["full_admin"]   = $dfull["token"]["is_admin"] ? 1 : 0;

	// STEP 107.2 — filter isolation: after a SECOND token bootstraps, the first
	// token'\''s trail must NOT pick up the second token'\''s bootstrap event.
	$dro2 = $q->token( $ro_id );
	$boot_ro2 = count( array_filter( $dro2["audit_trail"], fn( $e ) => "capability.bootstrap" === ( $e["action"] ?? "" ) ) );
	$out["audit_isolated"] = ( 1 === $boot_ro2 ) ? 1 : 0;

	$list = $q->tokens();
	$json = wp_json_encode( $list );
	$out["has_hash"]   = ( false !== strpos( (string) $json, "token_hash" ) ) ? 1 : 0;
	$out["list_total"] = $list["total"];
	$out["unknown"]    = ( null === $q->token( "00000000-0000-0000-0000-000000000000" ) ) ? 1 : 0;

	// ── S2.1 — canonical pagination envelope + limit/offset behavior ──
	$keys = ["items","total_count","returned","has_more","next_cursor","limit","offset","filters"];
	$shape_ok = 1; foreach ( $keys as $k ) { if ( ! array_key_exists( $k, $list ) ) { $shape_ok = 0; } }
	$out["env_shape"]  = $shape_ok;
	$out["total_alias"] = ( (int) $list["total"] === (int) $list["total_count"] ) ? 1 : 0; // back-compat alias
	$grand = (int) $list["total_count"]; // there are at least 2 tokens (ro + full) here
	$p1 = $q->tokens( [], 1, 0 );
	$out["page1_one"]  = ( count( $p1["items"] ) === 1 && (int) $p1["returned"] === 1 && (int) $p1["limit"] === 1 ) ? 1 : 0;
	$out["page1_more"] = ( $grand > 1 ) ? ( $p1["has_more"] ? 1 : 0 ) : 1;
	$cur = json_decode( base64_decode( (string) $p1["next_cursor"] ), true );
	$out["cursor_off"] = ( $grand > 1 ) ? ( ( (int) ( $cur["offset"] ?? -1 ) === 1 ) ? 1 : 0 ) : 1;
	// Paged walk (limit 1) visits every token exactly once (no drops/dupes).
	$seen = []; $off = 0; do { $pp = $q->tokens( [], 1, $off ); foreach ( $pp["items"] as $t ) { $seen[ $t["id"] ] = true; } $off += 1; } while ( $pp["has_more"] && $off < 500 );
	$out["walk_all"]   = ( count( $seen ) === $grand ) ? 1 : 0;
	// Matrix is computed only for the page (page of 1 still carries its matrix counts).
	$out["page_matrix"] = ( isset( $p1["items"][0]["total_operations"] ) && (int) $p1["items"][0]["total_operations"] === 34 ) ? 1 : 0;

	$at->delete( $ro_id );
	$at->delete( $full_id );

	echo wp_json_encode( $out );
')"

getj() { printf '%s' "$RES" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["'"$1"'"] ?? "";' 2>/dev/null; }

assert_eq "capability catalogue = 23"             "23" "$(getj caps_total)"
assert_eq "operation map = 34"                    "34" "$(getj ops_total)"
assert_eq "read_only token: matrix size = 34"     "34" "$(getj ro_total)"
assert_eq "read_only token: exactly 5 ops allowed" "5" "$(getj ro_allowed)"
assert_eq "read_only token: not admin"            "0"  "$(getj ro_admin)"
assert_eq "full token: all 34 ops allowed"        "34" "$(getj full_allowed)"
assert_eq "full token: is system.admin"           "1"  "$(getj full_admin)"
assert_eq "token list exposes NO token_hash"      "0"  "$(getj has_hash)"
assert_eq "unknown token id returns null (404able)" "1" "$(getj unknown)"

echo
echo "== 6a2. S2.1 — server-side token pagination (canonical envelope) =="
assert_eq "tokens() returns canonical envelope keys" "1"  "$(getj env_shape)"
assert_eq "total alias == total_count (back-compat)"  "1"  "$(getj total_alias)"
assert_eq "page of 1: items/returned/limit bounded"   "1"  "$(getj page1_one)"
assert_eq "page 1 reports has_more when more remain"  "1"  "$(getj page1_more)"
assert_eq "next_cursor encodes the next offset"       "1"  "$(getj cursor_off)"
assert_eq "paged walk visits every token once"        "1"  "$(getj walk_all)"
assert_eq "per-page matrix preserved (34 ops)"        "1"  "$(getj page_matrix)"

echo
echo "== 6b. Functional — per-token audit trail (STEP 107.2) =="
assert_eq "detail carries an audit_trail array"   "1"  "$(getj audit_is_array)"
assert_eq "trail includes capability.bootstrap"   "1"  "$(getj audit_has_boot)"
assert_eq "trail entries are shaped {ts,action,…}" "1" "$(getj audit_shaped)"
assert_eq "trail is bounded (<= 100)"             "1"  "$(getj audit_bounded)"
assert_eq "trail is chronological (oldest-first)" "1"  "$(getj audit_chrono)"
assert_eq "trail is filtered per-token (isolated)" "1" "$(getj audit_isolated)"

echo
echo "== 6c. Functional — capability writes via REAL routes (STEP 107.3) =="
# Exercises the actual admin REST routes end-to-end (rest_do_request as an admin):
# assign + remove route THROUGH OperationExecutor -> CapabilityManager, recording
# audit and updating the real assignment. Security mode is pinned to developer for
# deterministic execution, then restored.
WRES="$(wpe '
	$admin = get_users( ["role"=>"administrator","number"=>1] );
	if ( empty( $admin ) ) { echo "{}"; return; }
	wp_set_current_user( $admin[0]->ID );
	$prev_mode = get_option( "wpcc_security_mode", "developer" );
	update_option( "wpcc_security_mode", "developer" );

	$at  = new \WPCommandCenter\Security\AuthTokens();
	$q   = new \WPCommandCenter\Admin\TokenCapabilityAdminQuery();
	$reg = new \WPCommandCenter\Operations\CapabilityRegistry();
	$out = [];

	$r  = $at->create( "wpcc-test-107-w", "read_only", null, $admin[0]->ID );
	$id = $r["record"]["id"];

	// ASSIGN content.manage via the REAL POST route.
	$req = new WP_REST_Request( "POST", "/wp-command-center/v1/admin/tokens/$id/capabilities" );
	$req->set_body_params( [ "capability" => "content.manage" ] );
	$resp = rest_do_request( $req ); $b = $resp->get_data();
	$out["assign_status"]  = $resp->get_status();
	$out["assign_success"] = ! empty( $b["success"] ) ? 1 : 0;
	$out["has_content"]    = in_array( "content.manage", $reg->get_for_subject( "token", $id ), true ) ? 1 : 0;

	// Audit recorded + matrix now allows content_manage.
	$d = $q->token( $id );
	$out["audit_assigned"] = count( array_filter( $d["audit_trail"], fn( $e ) => "capability.assigned" === ( $e["action"] ?? "" ) && "content.manage" === ( $e["capability"] ?? "" ) ) ) > 0 ? 1 : 0;
	// Honesty: content_manage requires full scope, so a read_only-scope token
	// stays DENIED with reason=scope_blocked even WITH content.manage assigned —
	// scope gates before capability (mirrors RestApi::require_write()).
	$cm = null; foreach ( $d["access_matrix"] as $m ) { if ( "content_manage" === $m["operation"] ) { $cm = $m; break; } }
	$out["matrix_allow"]   = ( $cm && $cm["allowed"] ) ? 1 : 0;
	$out["matrix_reason"]  = $cm ? (string) $cm["reason"] : "";

	// REMOVE via the REAL DELETE route.
	$req2 = new WP_REST_Request( "DELETE", "/wp-command-center/v1/admin/tokens/$id/capabilities/content.manage" );
	$b2 = rest_do_request( $req2 )->get_data();
	$out["remove_success"] = ! empty( $b2["success"] ) ? 1 : 0;
	$out["removed"]        = in_array( "content.manage", $reg->get_for_subject( "token", $id ), true ) ? 0 : 1;

	// system.admin refusal (engine guard) via the REAL route.
	$req3 = new WP_REST_Request( "POST", "/wp-command-center/v1/admin/tokens/$id/capabilities" );
	$req3->set_body_params( [ "capability" => "system.admin" ] );
	$b3 = rest_do_request( $req3 )->get_data();
	$out["admin_refused"]  = empty( $b3["success"] ) ? 1 : 0;
	$out["admin_not_set"]  = in_array( "system.admin", $reg->get_for_subject( "token", $id ), true ) ? 0 : 1;

	// Unknown token id -> 404 (no stray assignment).
	$req4 = new WP_REST_Request( "POST", "/wp-command-center/v1/admin/tokens/00000000-0000-0000-0000-000000000000/capabilities" );
	$req4->set_body_params( [ "capability" => "content.manage" ] );
	$out["unknown_404"]    = ( 404 === rest_do_request( $req4 )->get_status() ) ? 1 : 0;

	$at->delete( $id );
	update_option( "wpcc_security_mode", $prev_mode );
	echo wp_json_encode( $out );
')"
getw() { printf '%s' "$WRES" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["'"$1"'"] ?? "";' 2>/dev/null; }

assert_eq "assign route returns 200"              "200" "$(getw assign_status)"
assert_eq "assign succeeded (engine-routed)"      "1"   "$(getw assign_success)"
assert_eq "capability actually assigned"          "1"   "$(getw has_content)"
assert_eq "assign recorded in audit trail"        "1"   "$(getw audit_assigned)"
# Honest scope-first behaviour: read_only scope still gates content_manage.
assert_eq "matrix honestly keeps content_manage denied" "0" "$(getw matrix_allow)"
assert_eq "denial reason = scope_blocked"         "scope_blocked" "$(getw matrix_reason)"
assert_eq "remove succeeded (engine-routed)"      "1"   "$(getw remove_success)"
assert_eq "capability actually removed"           "1"   "$(getw removed)"
assert_eq "system.admin refused by engine guard"  "1"   "$(getw admin_refused)"
assert_eq "system.admin never assigned"           "1"   "$(getw admin_not_set)"
assert_eq "unknown token id -> 404"               "1"   "$(getw unknown_404)"

echo
echo "== 6d. Functional — token lifecycle via REAL routes (STEP 107.4) =="
# create -> list -> revoke -> delete through the actual admin routes, proving
# AuthTokens reuse (capability bootstrap/deprovision automatic) + admin.token.*
# auditability + that the raw secret is returned once and the hash never is.
LRES="$(wpe '
	$admin = get_users( ["role"=>"administrator","number"=>1] );
	if ( empty( $admin ) ) { echo "{}"; return; }
	wp_set_current_user( $admin[0]->ID );
	$reg = new \WPCommandCenter\Operations\CapabilityRegistry();
	$out = [];

	// CREATE via real POST route.
	$req = new WP_REST_Request( "POST", "/wp-command-center/v1/admin/tokens" );
	$req->set_body_params( [ "label" => "wpcc-test-107-life", "scope" => "read_only", "expires" => "never" ] );
	$resp = rest_do_request( $req ); $b = $resp->get_data();
	$out["create_status"]  = $resp->get_status();
	$out["create_success"] = ! empty( $b["success"] ) ? 1 : 0;
	$out["raw_token"]      = ( ! empty( $b["token"] ) && str_starts_with( (string) $b["token"], "wpcc_" ) ) ? 1 : 0;
	$out["resp_no_hash"]   = ( false === strpos( (string) wp_json_encode( $b ), "token_hash" ) ) ? 1 : 0;
	$id = $b["record"]["id"] ?? "";

	// AuthTokens reuse: capability assignment auto-bootstrapped on create.
	$out["bootstrapped"]   = ! empty( $reg->get_for_subject( "token", $id ) ) ? 1 : 0;

	// Appears in the list read (wide page so membership is order/count independent).
	$lreq = new WP_REST_Request( "GET", "/wp-command-center/v1/admin/tokens" );
	$lreq->set_param( "limit", 100 );
	$list = rest_do_request( $lreq )->get_data();
	$out["in_list"] = count( array_filter( $list["items"] ?? [], fn( $t ) => ( $t["id"] ?? "" ) === $id ) ) > 0 ? 1 : 0;

	// REVOKE via real route.
	$rv = rest_do_request( new WP_REST_Request( "POST", "/wp-command-center/v1/admin/tokens/$id/revoke" ) )->get_data();
	$out["revoke_success"] = ! empty( $rv["success"] ) ? 1 : 0;
	$out["revoked_state"]  = ( ( $rv["status"] ?? "" ) === "revoked" ) ? 1 : 0;
	$out["deprovisioned"]  = empty( $reg->get_for_subject( "token", $id ) ) ? 1 : 0;

	// DELETE via real route.
	$del = rest_do_request( new WP_REST_Request( "DELETE", "/wp-command-center/v1/admin/tokens/$id" ) )->get_data();
	$out["delete_success"] = ! empty( $del["success"] ) ? 1 : 0;
	$lreq2 = new WP_REST_Request( "GET", "/wp-command-center/v1/admin/tokens" );
	$lreq2->set_param( "limit", 100 );
	$list2 = rest_do_request( $lreq2 )->get_data();
	$out["gone"] = count( array_filter( $list2["items"] ?? [], fn( $t ) => ( $t["id"] ?? "" ) === $id ) ) === 0 ? 1 : 0;

	// AUDIT: admin.token.created/revoked/deleted recorded for this token id.
	$tail = ( new \WPCommandCenter\Security\AuditLog() )->tail( 160 );
	$cr = $rv2 = $dl = 0;
	foreach ( $tail as $e ) { $c = $e["context"] ?? []; if ( ( $c["token_id"] ?? "" ) !== $id ) continue;
		if ( ( $e["action"] ?? "" ) === "admin.token.created" ) $cr++;
		if ( ( $e["action"] ?? "" ) === "admin.token.revoked" ) $rv2++;
		if ( ( $e["action"] ?? "" ) === "admin.token.deleted" ) $dl++;
	}
	$out["audit_created"] = $cr > 0 ? 1 : 0;
	$out["audit_revoked"] = $rv2 > 0 ? 1 : 0;
	$out["audit_deleted"] = $dl > 0 ? 1 : 0;

	echo wp_json_encode( $out );
')"
getl() { printf '%s' "$LRES" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["'"$1"'"] ?? "";' 2>/dev/null; }

assert_eq "create returns 201"                    "201" "$(getl create_status)"
assert_eq "create succeeded"                      "1"   "$(getl create_success)"
assert_eq "raw secret returned once (wpcc_ …)"    "1"   "$(getl raw_token)"
assert_eq "create response carries NO token_hash" "1"   "$(getl resp_no_hash)"
assert_eq "AuthTokens reuse: caps auto-bootstrapped" "1" "$(getl bootstrapped)"
assert_eq "new token appears in list"             "1"   "$(getl in_list)"
assert_eq "revoke succeeded"                      "1"   "$(getl revoke_success)"
assert_eq "token state = revoked"                 "1"   "$(getl revoked_state)"
assert_eq "AuthTokens reuse: caps deprovisioned on revoke" "1" "$(getl deprovisioned)"
assert_eq "delete succeeded"                      "1"   "$(getl delete_success)"
assert_eq "token gone from list after delete"     "1"   "$(getl gone)"
assert_eq "audit: admin.token.created recorded"   "1"   "$(getl audit_created)"
assert_eq "audit: admin.token.revoked recorded"   "1"   "$(getl audit_revoked)"
assert_eq "audit: admin.token.deleted recorded"   "1"   "$(getl audit_deleted)"

echo
echo "== 6e. Functional — FeatureGate gating (STEP 107.5) =="
# With the feature ungated, the manager permission gate is true for an admin;
# flipping wpcc_feature_allowed=false for this feature blocks it (menu + REST).
FRES="$(wpe '
	$admin = get_users( ["role"=>"administrator","number"=>1] );
	if ( empty( $admin ) ) { echo "{}"; return; }
	wp_set_current_user( $admin[0]->ID );
	$api = new \WPCommandCenter\Admin\AdminRestApi();
	$out = [];
	$out["ungated"] = $api->check_tokens_permission() ? 1 : 0;
	$f = function( $allowed, $feature ) { return "token_capability_manager" === $feature ? false : $allowed; };
	add_filter( "wpcc_feature_allowed", $f, 10, 2 );
	$out["gated_off"] = $api->check_tokens_permission() ? 1 : 0;
	$out["gate_allows_off"] = \WPCommandCenter\Admin\FeatureGate::allows( "token_capability_manager" ) ? 1 : 0;
	remove_filter( "wpcc_feature_allowed", $f, 10 );
	$out["restored"] = $api->check_tokens_permission() ? 1 : 0;
	echo wp_json_encode( $out );
')"
getf() { printf '%s' "$FRES" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["'"$1"'"] ?? "";' 2>/dev/null; }
assert_eq "ungated: manager permitted for admin"  "1" "$(getf ungated)"
assert_eq "gated off: manager blocked"            "0" "$(getf gated_off)"
assert_eq "gated off: FeatureGate::allows false"  "0" "$(getf gate_allows_off)"
assert_eq "filter removed: manager permitted again" "1" "$(getf restored)"

echo
echo "== 6f. Functional — legacy redirect (settings token section -> Access › Tokens) =="
# Experience Layer: the consolidated redirect_legacy_slugs() maps the old Settings
# token deep-link to Settings › Access. Plain Settings now RENDERS DIRECTLY (Security
# & Approvals is the default first tab) — it must NOT redirect, or wpcc-settings (a
# live section slug) would self-redirect and loop. This is the Phase-1 polish fix.
# Token deep-link -> Settings section + wpcc_tab=access.
POS="$(wpe '
	if ( ! defined( "WP_ADMIN" ) ) { define( "WP_ADMIN", true ); }
	$admin = get_users( ["role"=>"administrator","number"=>1] ); wp_set_current_user( $admin[0]->ID );
	add_filter( "wp_redirect", function( $loc ) { echo "REDIRECT:" . $loc; return false; }, 1 );
	$_GET = [ "page" => "wpcc-settings", "section" => "tokens" ];
	( new \WPCommandCenter\Admin\AdminMenu() )->redirect_legacy_slugs();
	echo "NO_REDIRECT";
')"
# Plain Settings -> renders directly (Security default tab); NO redirect, NO loop.
NEG="$(wpe '
	if ( ! defined( "WP_ADMIN" ) ) { define( "WP_ADMIN", true ); }
	$admin = get_users( ["role"=>"administrator","number"=>1] ); wp_set_current_user( $admin[0]->ID );
	add_filter( "wp_redirect", function( $loc ) { echo "REDIRECT:" . $loc; return false; }, 1 );
	$_GET = [ "page" => "wpcc-settings" ];
	( new \WPCommandCenter\Admin\AdminMenu() )->redirect_legacy_slugs();
	echo "NO_REDIRECT";
')"
case "$POS" in *"REDIRECT:"*"page=wpcc-settings"*"wpcc_tab=access"*) pass "legacy token deep-link redirects to Settings › Access";; *) fail "legacy redirect (positive) got: $POS";; esac
case "$NEG" in *"NO_REDIRECT"*) pass "plain Settings renders directly (Security default tab) — no redirect, no loop";; *) fail "plain Settings should NOT redirect (loop risk); got: $NEG";; esac

echo
echo "== 7. Invariants unchanged (no op_map / capability change) =="
OPN=$(awk '/const OPERATION_MAP = \[/,/^\t\];/' "$REGISTRY" | grep -cE "^\s*'[a-z_]+'\s*=>")
CAPN=$(awk '/const ALL_CAPABILITIES = \[/,/\];/' "$REGISTRY" | grep -c 'self::CAP_')
assert_eq "operation_map stays 34" "34" "$OPN"
assert_eq "capabilities stay 23"   "23" "$CAPN"

echo
echo "== SUMMARY: $PASS passed, $FAIL failed =="
[ "$FAIL" -eq 0 ]
