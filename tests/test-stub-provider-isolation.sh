#!/usr/bin/env bash
# Workstream 2 — placeholder output can never reach a customer as an AI suggestion.
#
# A first-time customer opened Built-in AI › Content and found suggestions reading
# "STUB TITLE" and "STUB EXCERPT", attributed to provider `stub`, model `stub-model`,
# sitting on their own posts. Nothing in the shipped plugin produced them: the Content,
# SEO and Alt Text generators all take their provider through an injectable resolver
# seam, and tests/test-ai-content.sh drives that seam with a deterministic stub so it
# can assert without a network call. Those runs wrote REAL rows through the REAL
# ProposalStore and deleted only the post afterwards, so one pair of placeholder drafts
# accumulated per run and the Content queue rendered them exactly like genuine output.
#
# Two things had to become true. The suites must clean up the drafts they create, and
# the guarantee must stop being incidental: production could not reach a stub only
# because the shipped resolvers happen to return catalogued providers. It is now stated
# — ProviderProvenance::accepts() — and every generator asks before it writes.
#
# Covered here:
#   1. ProviderProvenance itself: catalogued ids accepted, uncatalogued refused, and
#      refusal lifts only under an explicitly defined WPCC_ALLOW_TEST_AI_PROVIDER.
#   2. Nothing shipped defines that constant.
#   3. Each generator (Content / SEO / Alt Text) refuses a stub in production mode,
#      writes NO proposal row, and reports the refusal as a skip rather than a failure.
#   4. The same stub under the explicit opt-in DOES produce a draft — the seam still
#      works, so the suites keep their determinism.
#   5. The customer-facing Content draft queue holds no stub-attributed rows.
set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../wpcc-env.sh"
WP_PATH="$SCRIPT_DIR/../../../.."
SRC="$SCRIPT_DIR/../includes"
PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; if [ "$e" = "$a" ]; then pass "$d"; else fail "$d (expected '$e', got '$a')"; fi; }

echo "Stub-provider isolation (Workstream 2) — $(date)"
echo ""

# ===================================================================
echo "== 1. ProviderProvenance — the rule itself =="
# Production mode: no opt-in constant defined anywhere in this process.
PROV="$(wp --path="$WP_PATH" eval '
use WPCommandCenter\Ai\ProviderProvenance;
echo "anthropic=" . ( ProviderProvenance::accepts( "anthropic" ) ? "yes" : "no" ) . "\n";
echo "openai=" . ( ProviderProvenance::accepts( "openai" ) ? "yes" : "no" ) . "\n";
echo "stub=" . ( ProviderProvenance::accepts( "stub" ) ? "yes" : "no" ) . "\n";
echo "empty=" . ( ProviderProvenance::accepts( "" ) ? "yes" : "no" ) . "\n";
echo "allowed=" . ( ProviderProvenance::test_providers_allowed() ? "yes" : "no" ) . "\n";
' 2>/dev/null)"
getp() { echo "$PROV" | grep -F "$1=" | head -1 | cut -d= -f2-; }
assert_eq "a catalogued provider is accepted (anthropic)" "yes" "$(getp anthropic)"
assert_eq "a catalogued provider is accepted (openai)"    "yes" "$(getp openai)"
assert_eq "an uncatalogued provider is refused (stub)"    "no"  "$(getp stub)"
assert_eq "an empty provider id is refused"               "no"  "$(getp empty)"
assert_eq "test providers are NOT allowed by default"     "no"  "$(getp allowed)"

# Same call, with the opt-in defined for this process only.
PROV_ON="$(wp --path="$WP_PATH" eval '
define( "WPCC_ALLOW_TEST_AI_PROVIDER", true );
use WPCommandCenter\Ai\ProviderProvenance;
echo "stub=" . ( ProviderProvenance::accepts( "stub" ) ? "yes" : "no" ) . "\n";
' 2>/dev/null)"
assert_eq "the explicit opt-in lifts the refusal" "yes" "$(echo "$PROV_ON" | grep -F 'stub=' | cut -d= -f2-)"

# ===================================================================
echo ""
echo "== 2. Nothing shipped defines the opt-in =="
SHIPPED="$(grep -rl "define( *['\"]WPCC_ALLOW_TEST_AI_PROVIDER" "$SRC" "$SCRIPT_DIR/../action-steward.php" 2>/dev/null | wc -l | tr -d ' ')"
assert_eq "no shipped PHP file defines WPCC_ALLOW_TEST_AI_PROVIDER" "0" "$SHIPPED"
# The constant name is referenced in exactly one place: the guard that reads it.
DECL="$(grep -rl "WPCC_ALLOW_TEST_AI_PROVIDER" "$SRC" 2>/dev/null | wc -l | tr -d ' ')"
assert_eq "the constant is read in exactly one shipped file" "1" "$DECL"

# ===================================================================
echo ""
echo "== 3. Each generator refuses a stub in production mode — and writes nothing =="
# No opt-in constant here. Each generator gets a stub through its own resolver seam and
# must skip with `provider_not_shipped`, creating no proposal row at all.
GEN="$(wp --path="$WP_PATH" eval '
use WPCommandCenter\Proposals\ProposalStore;

$store = new ProposalStore();
$before = $store->count( [] );

$pid = wp_insert_post( [ "post_title" => "WPCC provenance probe", "post_content" => "Body text long enough to be a realistic source for a suggestion.", "post_status" => "publish" ], true );
if ( is_wp_error( $pid ) ) { echo "ERR=insert\n"; exit; }

// --- Content ---
$cStub = new class implements \WPCommandCenter\Content\ContentFieldProvider {
	public function id(): string { return "stub"; }
	public function is_configured(): bool { return true; }
	public function suggest( string $kind, array $c, array $x = [] ): \WPCommandCenter\Content\ContentFieldResult {
		return \WPCommandCenter\Content\ContentFieldResult::ok( "STUB " . strtoupper( $kind ), "stub", "stub-model" );
	}
};
$cRes = new class( $cStub ) extends \WPCommandCenter\Content\ContentFieldProviderResolver {
	private $p; public function __construct( $p ) { $this->p = $p; }
	public function active(): ?\WPCommandCenter\Content\ContentFieldProvider { return $this->p; }
};
$c = ( new \WPCommandCenter\Content\ContentFieldGenerator( $store, $cRes ) )->generate( $pid, "title" );
echo "content_created=" . count( $c["created"] ) . "\n";
echo "content_failed=" . count( $c["failed"] ) . "\n";
echo "content_reason=" . ( $c["skipped"][0]["reason"] ?? "" ) . "\n";

// --- SEO ---
$sStub = new class implements \WPCommandCenter\Seo\SeoMetaProvider {
	public function id(): string { return "stub"; }
	public function is_configured(): bool { return true; }
	public function suggest_meta( array $c, array $x = [] ): \WPCommandCenter\Seo\SeoMetaResult {
		return \WPCommandCenter\Seo\SeoMetaResult::ok( "STUB TITLE", "Stub description long enough to look like a real meta description here.", "stub", "stub-model" );
	}
};
$sRes = new class( $sStub ) extends \WPCommandCenter\Seo\SeoMetaProviderResolver {
	private $p; public function __construct( $p ) { $this->p = $p; }
	public function active(): ?\WPCommandCenter\Seo\SeoMetaProvider { return $this->p; }
};
$s = ( new \WPCommandCenter\Seo\SeoMetaGenerator( $store, $sRes ) )->generate( [ $pid ] );
echo "seo_created=" . count( $s["created"] ) . "\n";
// SEO checks for an SEO plugin BEFORE the provider, so on a site without one the skip
// reason is no_seo_plugin and the provenance check is never reached. Report which.
echo "seo_plugin=" . \WPCommandCenter\Operations\SeoProvider::detect() . "\n";
echo "seo_reason=" . ( $s["skipped"][0]["reason"] ?? "" ) . "\n";

// --- Alt Text ---
// Its resolver is final with a closed registry, so no stub can be injected at all —
// asserted structurally below. Here we only confirm the registry is what we think.
$aRes = new \WPCommandCenter\AltText\ProviderResolver();
echo "alt_registry=" . implode( ",", $aRes->available() ) . "\n";
echo "alt_resolver_final=" . ( ( new ReflectionClass( \WPCommandCenter\AltText\ProviderResolver::class ) )->isFinal() ? "yes" : "no" ) . "\n";

echo "rows_added=" . ( $store->count( [] ) - $before ) . "\n";
wp_delete_post( $pid, true );
' 2>/dev/null)"
getg() { echo "$GEN" | grep -F "$1=" | head -1 | cut -d= -f2-; }
assert_eq "Content generator created no draft"          "0" "$(getg content_created)"
assert_eq "Content refusal is a skip, not a failure"    "0" "$(getg content_failed)"
assert_eq "Content reports provider_not_shipped"        "provider_not_shipped" "$(getg content_reason)"
assert_eq "SEO generator created no draft"              "0" "$(getg seo_created)"
# Only meaningful when an SEO plugin is active — otherwise SEO skips earlier, for a
# different and equally correct reason, and the provenance branch is never reached.
if [ "$(getg seo_plugin)" != "none" ]; then
	assert_eq "SEO reports provider_not_shipped" "provider_not_shipped" "$(getg seo_reason)"
else
	echo "  SKIP: SEO provenance reason (no SEO plugin active on this site)"
fi
# Alt Text is immune by construction rather than by check: a final resolver over a
# closed registry means there is no seam a stub could arrive through in the first place.
assert_eq "Alt Text resolver is final (no stub subclass possible)" "yes" "$(getg alt_resolver_final)"
assert_eq "Alt Text registry holds only shipped providers"         "anthropic" "$(getg alt_registry)"
assert_eq "no proposal row was written at all"          "0" "$(getg rows_added)"

# Defence in depth: the guard is still wired into all three generators, so a future
# resolver change cannot quietly reopen the path Content and SEO had.
for g in Content/ContentFieldGenerator Seo/SeoMetaGenerator AltText/AltTextGenerator; do
	if grep -q "ProviderProvenance::accepts" "$SRC/$g.php"; then
		pass "$(basename "$g") consults the provenance guard"
	else
		fail "$(basename "$g") does not consult the provenance guard"
	fi
done

# ===================================================================
echo ""
echo "== 4. The seam still works under the explicit opt-in =="
# The suites depend on injecting a deterministic provider. Refusing a stub in production
# must not cost them that, so the same call with the constant defined creates a draft —
# and this check dismisses it again so the suite leaves nothing behind.
OPT="$(wp --path="$WP_PATH" eval '
define( "WPCC_ALLOW_TEST_AI_PROVIDER", true );
use WPCommandCenter\Proposals\ProposalStore;
$store = new ProposalStore();
$pid = wp_insert_post( [ "post_title" => "WPCC provenance opt-in probe", "post_content" => "Body text long enough to be a realistic source for a suggestion.", "post_status" => "publish" ], true );
$stub = new class implements \WPCommandCenter\Content\ContentFieldProvider {
	public function id(): string { return "stub"; }
	public function is_configured(): bool { return true; }
	public function suggest( string $k, array $c, array $x = [] ): \WPCommandCenter\Content\ContentFieldResult {
		return \WPCommandCenter\Content\ContentFieldResult::ok( "STUB " . strtoupper( $k ), "stub", "stub-model" );
	}
};
$res = new class( $stub ) extends \WPCommandCenter\Content\ContentFieldProviderResolver {
	private $p; public function __construct( $p ) { $this->p = $p; }
	public function active(): ?\WPCommandCenter\Content\ContentFieldProvider { return $this->p; }
};
$r = ( new \WPCommandCenter\Content\ContentFieldGenerator( $store, $res ) )->generate( $pid, "title" );
echo "created=" . count( $r["created"] ) . "\n";
foreach ( $r["created"] as $id ) { $store->dismiss( (string) $id ); }
wp_delete_post( $pid, true );
' 2>/dev/null)"
assert_eq "the opt-in restores deterministic stub generation" "1" "$(echo "$OPT" | grep -F 'created=' | cut -d= -f2-)"

# ===================================================================
echo ""
echo "== 5. The customer-facing draft queue holds no stub output =="
QUEUE="$(wp --path="$WP_PATH" eval '
global $wpdb; $t = $wpdb->prefix . "wpcc_proposals";
echo "stub_drafts=" . (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE provider = \"stub\" AND status IN (\"draft\",\"pending_approval\")" ) . "\n";
echo "stub_model_drafts=" . (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE model = \"stub-model\" AND status IN (\"draft\",\"pending_approval\")" ) . "\n";
' 2>/dev/null)"
getq() { echo "$QUEUE" | grep -F "$1=" | head -1 | cut -d= -f2-; }
assert_eq "no stub-attributed draft awaits review"        "0" "$(getq stub_drafts)"
assert_eq "no stub-model draft awaits review"             "0" "$(getq stub_model_drafts)"

echo ""
echo "RESULT: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
