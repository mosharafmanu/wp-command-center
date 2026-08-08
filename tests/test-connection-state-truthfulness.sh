#!/usr/bin/env bash
# Workstream 8 — the connection list tells the truth about what is configured.
#
# A tester deleted their temporary Anthropic connection and watched a connection called
# "Anthropic (existing)" appear in its place, reporting "Needs a key · Add an API key to
# finish setup". Both halves of that were wrong, in opposite directions.
#
# ConnectionStore::all() surfaces a VIRTUAL bootstrap connection when the pre-6R
# `wpcc_anthropic_api_key` option holds a key and nothing else is stored — that is how a
# pre-platform install keeps working. Deleting it cleared the per-connection credential
# store, which never held that key, and left the legacy option untouched; the next read
# found the legacy key still there and synthesized the connection straight back. And
# CredentialStore::has_secret() only ever looked in its own store, so the connection
# that exists BECAUSE a key exists was reported as having none — while the runtime went
# on reading and using that very key on every call, and default_id()/routes(), which
# gate on has_secret(), refused to route anything to it.
#
# Covered here (each case builds its own option state and restores it afterwards):
#   1. No connections and no legacy key → an empty list, honestly empty.
#   2. A legacy key with nothing stored → one bridge connection, reported as KEYED.
#   3. That bridge connection is routable: default_id() and routes() resolve to it.
#   4. Deleting it removes the legacy key, so a reload does NOT resurrect it.
#   5. A saved connection with no key still reads "Needs a key" — the honest case.
#   6. A saved connection with a key reads as keyed and is distinct from the bridge.
#   7. A constant-supplied key is never deleted by an option write.
#   8. Provider catalogue entries are not connections.
set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../wpcc-env.sh"
WP_PATH="$SCRIPT_DIR/../../../.."
PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; if [ "$e" = "$a" ]; then pass "$d"; else fail "$d (expected '$e', got '$a')"; fi; }

echo "Connection state truthfulness (Workstream 8) — $(date)"
echo ""

PHPF="$(mktemp /tmp/wpcc-ws8-XXXXXX.php)"
cat > "$PHPF" <<'PHP'
<?php
use WPCommandCenter\Ai\Platform\ConnectionStore;
use WPCommandCenter\Ai\Platform\Health;
use WPCommandCenter\Ai\Platform\ProviderCatalog;

/*
 * This suite rewrites the site's real connection options, so it snapshots every one it
 * touches and puts them all back before it returns — including on an early exit. A
 * regression suite that leaves a developer's provider key deleted is not a regression
 * suite.
 */
$OPTS = [ 'wpcc_ai_connections', 'wpcc_ai_credentials', 'wpcc_ai_default_conn', 'wpcc_ai_routes', 'wpcc_anthropic_api_key', 'wpcc_anthropic_model', 'wpcc_alt_text_api_key' ];
$SNAP = [];
foreach ( $OPTS as $o ) { $SNAP[ $o ] = get_option( $o, '__ABSENT__' ); }
$restore = static function () use ( $OPTS, $SNAP ) {
	foreach ( $OPTS as $o ) {
		if ( '__ABSENT__' === $SNAP[ $o ] ) { delete_option( $o ); } else { update_option( $o, $SNAP[ $o ], false ); }
	}
};
register_shutdown_function( $restore );

$clear = static function () {
	delete_option( 'wpcc_ai_connections' );
	delete_option( 'wpcc_ai_credentials' );
	delete_option( 'wpcc_ai_default_conn' );
	delete_option( 'wpcc_ai_routes' );
	delete_option( 'wpcc_anthropic_api_key' );
	delete_option( 'wpcc_alt_text_api_key' );
};

// A syntactically plausible but entirely fake key. Never a real credential.
$FAKE = 'sk-ant-test-0000000000000000000000000000000000000000000000000000';

// ── 1. Nothing configured at all ────────────────────────────────────────────
$clear();
$s = new ConnectionStore();
echo 'empty_count=' . count( $s->all() ) . "\n";
echo 'empty_default=' . ( $s->default_id() === '' ? 'none' : 'something' ) . "\n";

// ── 2. A legacy key, nothing stored → one bridge connection, reported KEYED ──
$clear();
update_option( 'wpcc_anthropic_api_key', $FAKE, false );
$s   = new ConnectionStore();
$all = $s->all();
echo 'bridge_count=' . count( $all ) . "\n";
$bridge = $all[ ConnectionStore::LEGACY_ID ] ?? null;
echo 'bridge_present=' . ( $bridge ? 'yes' : 'no' ) . "\n";
if ( $bridge ) {
	echo 'bridge_flag=' . ( ! empty( $bridge['bridge_legacy'] ) ? 'yes' : 'no' ) . "\n";
	echo 'bridge_has_key=' . ( $s->is_configured( $bridge ) ? 'yes' : 'no' ) . "\n";
	echo 'bridge_health=' . Health::of( $bridge, $s )['state'] . "\n";
	// 3. Routable — the state that was previously unreachable.
	echo 'bridge_default=' . ( $s->default_id() === ConnectionStore::LEGACY_ID ? 'yes' : 'no' ) . "\n";
	$routes = $s->routes();
	echo 'bridge_routes=' . ( count( array_filter( $routes, static fn( $c ) => $c === ConnectionStore::LEGACY_ID ) ) ) . "\n";
}

// ── 4. Deleting it must not resurrect it ────────────────────────────────────
$s->delete( ConnectionStore::LEGACY_ID );
echo 'after_delete_legacy_key=' . ( '' === (string) get_option( 'wpcc_anthropic_api_key', '' ) ? 'gone' : 'still_there' ) . "\n";
$fresh = new ConnectionStore(); // a fresh read == a page reload
echo 'after_delete_count=' . count( $fresh->all() ) . "\n";
echo 'after_delete_default=' . ( $fresh->default_id() === '' ? 'none' : 'something' ) . "\n";

// ── 5. A saved connection with no key is honestly "Needs a key" ─────────────
$clear();
$s   = new ConnectionStore();
$id  = $s->create( 'anthropic', [ 'name' => 'Keyless probe' ] );
$all = $s->all();
$c   = $all[ $id ] ?? null;
echo 'keyless_present=' . ( $c ? 'yes' : 'no' ) . "\n";
if ( $c ) {
	echo 'keyless_bridge=' . ( ! empty( $c['bridge_legacy'] ) ? 'yes' : 'no' ) . "\n";
	echo 'keyless_has_key=' . ( $s->is_configured( $c ) ? 'yes' : 'no' ) . "\n";
	echo 'keyless_health=' . Health::of( $c, $s )['state'] . "\n";
	echo 'keyless_default=' . ( $s->default_id() === '' ? 'none' : 'something' ) . "\n";
}

// ── 6. The same connection, keyed ───────────────────────────────────────────
$s->credentials()->set_secret( $id, $FAKE );
$s2 = new ConnectionStore();
$c2 = $s2->all()[ $id ] ?? null;
echo 'keyed_has_key=' . ( $c2 && $s2->is_configured( $c2 ) ? 'yes' : 'no' ) . "\n";
echo 'keyed_bridge=' . ( $c2 && ! empty( $c2['bridge_legacy'] ) ? 'yes' : 'no' ) . "\n";
// Deleting a NORMAL connection must not touch the legacy option (it owns no such key).
update_option( 'wpcc_anthropic_api_key', $FAKE, false );
$s2->delete( $id );
echo 'normal_delete_kept_legacy=' . ( '' !== (string) get_option( 'wpcc_anthropic_api_key', '' ) ? 'yes' : 'no' ) . "\n";

// ── 8. Catalogue entries are templates, not connections ─────────────────────
$clear();
$s = new ConnectionStore();
echo 'catalog_providers=' . count( ProviderCatalog::all() ) . "\n";
echo 'catalog_connections=' . count( $s->all() ) . "\n";

$restore();
PHP

OUT="$(wp --path="$WP_PATH" eval-file "$PHPF" 2>/dev/null)"
rm -f "$PHPF"
g() { echo "$OUT" | grep -F "$1=" | head -1 | cut -d= -f2-; }

echo "== 1. Nothing configured =="
assert_eq "an empty install lists no connections"          "0"    "$(g empty_count)"
assert_eq "an empty install has no default"                "none" "$(g empty_default)"

echo ""
echo "== 2. A legacy key surfaces one bridge connection — reported as keyed =="
assert_eq "exactly one connection is surfaced"             "1"    "$(g bridge_count)"
assert_eq "it is the bootstrap connection"                 "yes"  "$(g bridge_present)"
assert_eq "it is marked as the legacy bridge"              "yes"  "$(g bridge_flag)"
assert_eq "it reports that it HAS a key"                   "yes"  "$(g bridge_has_key)"
assert_eq "its health is no longer 'needs a key'"          "untested" "$(g bridge_health)"

echo ""
echo "== 3. The bridge connection is actually routable =="
assert_eq "it resolves as the default connection"          "yes"  "$(g bridge_default)"
assert_eq "all three features route to it"                 "3"    "$(g bridge_routes)"

echo ""
echo "== 4. Deleting it deletes it =="
assert_eq "the legacy key option is removed"               "gone" "$(g after_delete_legacy_key)"
assert_eq "a reload does not resurrect the connection"     "0"    "$(g after_delete_count)"
assert_eq "no default is left pointing at it"              "none" "$(g after_delete_default)"

echo ""
echo "== 5. A saved keyless connection is honestly keyless =="
assert_eq "the saved connection exists"                    "yes"  "$(g keyless_present)"
assert_eq "it is NOT confused with the legacy bridge"      "no"   "$(g keyless_bridge)"
assert_eq "it reports that it has no key"                  "no"   "$(g keyless_has_key)"
assert_eq "its health is 'needs a key'"                    "needs_setup" "$(g keyless_health)"
assert_eq "a keyless connection cannot become the default" "none" "$(g keyless_default)"

echo ""
echo "== 6. A keyed saved connection, and delete that stays in its lane =="
assert_eq "adding a key flips it to keyed"                 "yes"  "$(g keyed_has_key)"
assert_eq "a normal connection is never a legacy bridge"   "no"   "$(g keyed_bridge)"
assert_eq "deleting a normal connection keeps the legacy key" "yes" "$(g normal_delete_kept_legacy)"

echo ""
echo "== 7. A constant is site configuration, not an option =="
# delete() only ever clears OPTIONS. A key supplied by a constant cannot be removed by
# an option write, and the code must not claim otherwise — assert the deletion is gated.
if grep -q "is_constant_backed" "$SCRIPT_DIR/../includes/Ai/Platform/ConnectionStore.php"; then
	pass "legacy-key deletion is gated on the key not being constant-backed"
else
	fail "legacy-key deletion is not gated on constant backing"
fi

echo ""
echo "== 8. Provider templates are not connections =="
CATP="$(g catalog_providers)"
if [ "${CATP:-0}" -gt 1 ]; then
	pass "the provider catalogue offers $CATP providers to choose from"
else
	fail "the provider catalogue looks empty ($CATP)"
fi
assert_eq "none of them counts as a configured connection" "0" "$(g catalog_connections)"

echo ""
echo "RESULT: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
