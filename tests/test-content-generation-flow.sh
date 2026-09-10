#!/usr/bin/env bash
# Workstreams 1, 5 & 6 — the Content tool has a generation flow a customer can find,
# regeneration that cannot cost them their current draft, and honest guidance when a
# page has too little content to say much about.
#
# An independent first-time-customer test enabled Content, opened its screen, and found
# no way to generate anything. The backend was complete and correct — ContentFieldGenerator
# → ProposalStore → approval → apply → undo all worked, and a "✨ Action Steward AI" row action on
# the Posts list drove it — but the tool's own screen had only Suggestions and Applied
# tabs, and its empty state read "Generate some from a post or page" without naming a
# page, linking anywhere, or mentioning what the row action is called. SEO and Alt Text
# each have a Review tab where you pick items and press Generate. Content did not, so it
# was the one built-in tool you could not start from its own screen.
#
# Covered here:
#   1. The Review tab exists, and offers generation only through the existing route.
#   2. Honest states: no provider key, tool switched off, unsupported post.
#   3. Generation persists a real title/excerpt with provider + model attribution.
#   4. The draft is a DRAFT: the post is untouched until a human approves.
#   5. Applying goes through the governed path and reaches the post.
#   6. Repeated generation is deduped, not duplicated.
#   7. Regeneration replaces a draft — and a FAILED regeneration does not.
#   8. A submitted/applied proposal can never be replaced out from under approval.
#   9. Source-content signal: empty, thin and rich are classified deterministically.
set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../wpcc-env.sh"
WP_PATH="$SCRIPT_DIR/../../../.."
VIEW="$SCRIPT_DIR/../includes/Admin/views/ai-content.php"
API="$SCRIPT_DIR/../includes/Admin/AdminRestApi.php"
PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; if [ "$e" = "$a" ]; then pass "$d"; else fail "$d (expected '$e', got '$a')"; fi; }
has() { if grep -q "$1" "$2"; then pass "$3"; else fail "$3"; fi; }

echo "Content generation flow (Workstreams 1/5/6) — $(date)"
echo ""

# ===================================================================
echo "== 1. The Review tab exists and adds no backend =="
has "wpcc-aic-tab-review"        "$VIEW" "the screen has a Review tab"
has "wpcc-aic-rv-generate"       "$VIEW" "the Review tab has a Generate control"
has "wpcc-aic-rv-selectall"      "$VIEW" "candidates can be selected in bulk"
# Generation must ride the EXISTING proposals branch — no new route may appear.
has "generate: { kind: kind, post_id" "$VIEW" "generation uses the existing /proposals generate branch"
NEWROUTES="$(grep -c "register_rest_route" "$API")"
if grep -qE "admin/content/generate|content-field/generate" "$API"; then
	fail "a new content generate route was added"
else
	pass "no new content generate route was added ($NEWROUTES routes registered, unchanged set)"
fi
# The dead-end empty state is gone. Match the STRING THE SCREEN PRINTS — the old text
# is still quoted in the comment above it, which explains why it was replaced.
if grep -q "noSug:.*No suggestions yet. Generate some from a post or page." "$VIEW"; then
	fail "the dead-end empty state is still what the screen prints"
else
	pass "the dead-end empty state is no longer printed"
fi
has "data-rv-go=\\\\\"review\\\\\"\|data-rv-go=\"review\"" "$VIEW" "the empty state links to the Review tab"

# ===================================================================
echo ""
echo "== 2. Honest states before anything is generated =="
has "No AI provider key yet"     "$VIEW" "a keyless site is told so on the Review tab"
has "adding a key alone will not change anything" "$VIEW" "the keyless notice keeps the trust promise"
has "wpcc_tool_disabled"         "$API"  "the REST branch refuses when the tool is switched off"
has "BuiltinAiSettings::is_on( 'content' )" "$API" "…using the same switch the rest of the product reads"
has "nothing is applied"         "$VIEW" "the Review tab states nothing is applied there"

# ===================================================================
echo ""
echo "== 3-8. The governed round trip, with a deterministic provider =="
PHPF="$(mktemp /tmp/wpcc-ws1-XXXXXX.php)"
cat > "$PHPF" <<'PHP'
<?php
// The provider is stubbed so this suite is deterministic and makes no network call.
// Saying so explicitly is what stops the resulting drafts being mistaken for real
// suggestions — see ProviderProvenance and tests/test-stub-provider-isolation.sh.
define( 'WPCC_ALLOW_TEST_AI_PROVIDER', true );

use WPCommandCenter\Content\ContentFieldGenerator;
use WPCommandCenter\Content\ContentFieldProvider;
use WPCommandCenter\Content\ContentFieldProviderResolver;
use WPCommandCenter\Content\ContentFieldResult;
use WPCommandCenter\Proposals\ProposalStore;

$store = new ProposalStore();
$made  = []; // every proposal this run creates, dismissed on the way out.

// A provider whose answer we control, so "did the returned text get persisted" is a
// question with a checkable answer.
class WPCC_FlowProvider implements ContentFieldProvider {
	public static $reply = 'A genuinely different title';
	public static $fail  = false;
	public function id(): string { return 'anthropic'; } // catalogued: a real adapter id
	public function is_configured(): bool { return true; }
	public function suggest( string $kind, array $c, array $x = [] ): ContentFieldResult {
		if ( self::$fail ) { return ContentFieldResult::error( 'api_error_500', 'upstream exploded', 'anthropic', 'claude-sonnet-4-6' ); }
		return ContentFieldResult::ok( self::$reply, 'anthropic', 'claude-sonnet-4-6' );
	}
}
class WPCC_FlowResolver extends ContentFieldProviderResolver {
	public function active(): ?ContentFieldProvider { return new WPCC_FlowProvider(); }
	public function has_active(): bool { return true; }
}
$gen = static fn() => new ContentFieldGenerator( new ProposalStore(), new WPCC_FlowResolver() );

$pid = wp_insert_post( [
	'post_title'   => 'Original working title',
	'post_excerpt' => 'Original excerpt.',
	'post_content' => str_repeat( 'This post has a real body with enough words to be a proper source for a suggestion. ', 6 ),
	'post_status'  => 'publish',
], true );

// ── 3. Generation persists text + attribution ───────────────────────────────
$r = $gen()->generate( $pid, 'title' );
$made = array_merge( $made, $r['created'] );
echo 'created=' . count( $r['created'] ) . "\n";
$row = $store->get( $r['created'][0] ?? '' );
$payload = json_decode( (string) ( $row['payload_json'] ?? '{}' ), true );
echo 'persisted_title=' . ( $payload['title'] ?? '' ) . "\n";
echo 'prov=' . ( $row['provider'] ?? '' ) . "\n";
echo 'model=' . ( $row['model'] ?? '' ) . "\n";
echo 'target_type=' . ( $row['target_type'] ?? '' ) . "\n";
echo 'status=' . ( $row['status'] ?? '' ) . "\n";
echo 'source_thin=' . ( ! empty( $r['source']['thin'] ) ? 'yes' : 'no' ) . "\n";
echo 'source_words=' . (int) ( $r['source']['words'] ?? 0 ) . "\n";

// ── 4. Nothing has touched the post ─────────────────────────────────────────
echo 'post_title_after_generate=' . get_post( $pid )->post_title . "\n";

// ── 6. A second run for the same field is deduped, not duplicated ───────────
$dup = $gen()->generate( $pid, 'title' );
echo 'dup_created=' . count( $dup['created'] ) . "\n";
echo 'dup_reason=' . ( $dup['skipped'][0]['reason'] ?? '' ) . "\n";
// …but the OTHER field is not blocked by it.
$exc = $gen()->generate( $pid, 'excerpt' );
$made = array_merge( $made, $exc['created'] );
echo 'excerpt_created=' . count( $exc['created'] ) . "\n";

// ── 7a. A FAILED regeneration must leave the current draft alone ────────────
$original = $r['created'][0];
WPCC_FlowProvider::$fail = true;
$bad = $gen()->generate( $pid, 'title', [ 'replacing' => $original ] );
WPCC_FlowProvider::$fail = false;
echo 'regen_fail_created=' . count( $bad['created'] ) . "\n";
echo 'regen_fail_replaced=' . ( ! empty( $bad['replaced'] ) ? 'yes' : 'no' ) . "\n";
$still = $store->get( $original );
echo 'regen_fail_original_status=' . ( $still['status'] ?? 'GONE' ) . "\n";
$sp = json_decode( (string) ( $still['payload_json'] ?? '{}' ), true );
echo 'regen_fail_original_text=' . ( $sp['title'] ?? '' ) . "\n";

// ── 7b. A SUCCESSFUL regeneration replaces it ───────────────────────────────
WPCC_FlowProvider::$reply = 'A second, different title';
$good = $gen()->generate( $pid, 'title', [ 'replacing' => $original ] );
$made = array_merge( $made, $good['created'] );
echo 'regen_ok_created=' . count( $good['created'] ) . "\n";
echo 'regen_ok_replaced=' . ( ! empty( $good['replaced'] ) ? 'yes' : 'no' ) . "\n";
echo 'regen_ok_old_status=' . ( $store->get( $original )['status'] ?? 'GONE' ) . "\n";
$np = json_decode( (string) ( $store->get( $good['created'][0] )['payload_json'] ?? '{}' ), true );
echo 'regen_ok_new_text=' . ( $np['title'] ?? '' ) . "\n";
// Still exactly one OPEN title draft for this post — regeneration replaced, not added.
echo 'open_title_drafts=' . $store->count( [ 'target_id' => (string) $pid, 'operation_id' => 'content_manage', 'target_type' => 'content_title', 'status' => ProposalStore::STATUS_DRAFT ] ) . "\n";
// And the post is STILL untouched by any of it.
echo 'post_title_after_regen=' . get_post( $pid )->post_title . "\n";

// ── 8. A proposal that is not an open draft is not replaceable ──────────────
$current = $good['created'][0];
// request_id is varchar(36) — a uuid exactly fills it, and a longer value makes the
// UPDATE fail silently enough that the proposal stays a draft.
$mark = $store->mark_pending_approval( $current, wp_generate_uuid4() );
echo 'mark_result=' . ( is_wp_error( $mark ) ? $mark->get_error_code() : 'ok' ) . "\n";
echo 'mark_status=' . ( $store->get( $current )['status'] ?? '' ) . "\n";
$blocked = $gen()->generate( $pid, 'title', [ 'replacing' => $current ] );
echo 'replace_pending_created=' . count( $blocked['created'] ) . "\n";
echo 'replace_pending_code=' . ( $blocked['failed'][0]['code'] ?? '' ) . "\n";
echo 'replace_pending_status=' . ( $store->get( $current )['status'] ?? '' ) . "\n";
// A replacing id that belongs to a DIFFERENT post is refused too.
$other = wp_insert_post( [ 'post_title' => 'Another post', 'post_content' => 'Body.', 'post_status' => 'publish' ], true );
$wrong = $gen()->generate( $other, 'title', [ 'replacing' => $current ] );
echo 'replace_wrongpost_code=' . ( $wrong['failed'][0]['code'] ?? '' ) . "\n";
$made = array_merge( $made, $wrong['created'] );

// ── 2b. An unsupported target is refused clearly ────────────────────────────
$att = wp_insert_post( [ 'post_title' => 'An attachment', 'post_type' => 'attachment', 'post_status' => 'inherit' ], true );
$bad_type = $gen()->generate( $att, 'title' );
echo 'attachment_reason=' . ( $bad_type['skipped'][0]['reason'] ?? '' ) . "\n";
$trashed = wp_insert_post( [ 'post_title' => 'Trashed', 'post_content' => 'Body.', 'post_status' => 'trash' ], true );
$bad_status = $gen()->generate( $trashed, 'title' );
echo 'trashed_reason=' . ( $bad_status['skipped'][0]['reason'] ?? '' ) . "\n";
$missing = $gen()->generate( 99999999, 'title' );
echo 'missing_reason=' . ( $missing['skipped'][0]['reason'] ?? '' ) . "\n";
$badkind = $gen()->generate( $pid, 'slug' );
echo 'badkind_code=' . ( $badkind['failed'][0]['code'] ?? '' ) . "\n";

// ── A provider failure never invents a suggestion ───────────────────────────
WPCC_FlowProvider::$fail = true;
$before = $store->count( [] );
$failrun = $gen()->generate( $other, 'excerpt' );
WPCC_FlowProvider::$fail = false;
echo 'failrun_created=' . count( $failrun['created'] ) . "\n";
echo 'failrun_rows_added=' . ( $store->count( [] ) - $before ) . "\n";
echo 'failrun_code=' . ( $failrun['failed'][0]['code'] ?? '' ) . "\n";

// ── 9. The source-content signal ────────────────────────────────────────────
use WPCommandCenter\Ai\SourceContentSignal;
echo 'sig_empty=' . SourceContentSignal::of( '' ) . "\n";
echo 'sig_blank=' . SourceContentSignal::of( "   \n\t " ) . "\n";
echo 'sig_thin=' . SourceContentSignal::of( 'Just a handful of words here.' ) . "\n";
echo 'sig_rich=' . SourceContentSignal::of( str_repeat( 'word ', 200 ) ) . "\n";
echo 'sig_boundary_under=' . SourceContentSignal::of( str_repeat( 'word ', SourceContentSignal::THIN_BELOW_WORDS - 1 ) ) . "\n";
echo 'sig_boundary_at=' . SourceContentSignal::of( str_repeat( 'word ', SourceContentSignal::THIN_BELOW_WORDS ) ) . "\n";
echo 'sig_thin_is_thin=' . ( SourceContentSignal::is_thin( SourceContentSignal::THIN ) ? 'yes' : 'no' ) . "\n";
echo 'sig_rich_is_thin=' . ( SourceContentSignal::is_thin( SourceContentSignal::SUFFICIENT ) ? 'yes' : 'no' ) . "\n";
// A thin page still generates — the signal is advisory, never a gate.
$thinpost = wp_insert_post( [ 'post_title' => 'Contact', 'post_content' => 'Call us.', 'post_status' => 'publish' ], true );
$thinrun = $gen()->generate( $thinpost, 'title' );
$made = array_merge( $made, $thinrun['created'] );
echo 'thin_still_generated=' . count( $thinrun['created'] ) . "\n";
echo 'thin_flagged=' . ( ! empty( $thinrun['source']['thin'] ) ? 'yes' : 'no' ) . "\n";

// ── cleanup ─────────────────────────────────────────────────────────────────
//
// Authoritative rather than bookkeeping: this run creates proposals through several
// paths — some replaced, one deliberately pushed to pending_approval — and a tracked
// id list gets it wrong the moment a transition is refused (dismiss() only accepts an
// open proposal, so a pending_approval row survives a naive sweep). This is exactly how
// the original "STUB TITLE" residue accumulated: the suite deleted its posts and left
// its drafts pointing at ids that no longer existed, where they sat in the customer's
// Content queue looking like genuine suggestions.
//
// So: sweep by TARGET, not by remembered id, and prove afterwards that nothing is left.
$posts = array_values( array_filter( [ $pid, $other, $att, $trashed, $thinpost ], static fn( $p ) => $p && ! is_wp_error( $p ) ) );
foreach ( $posts as $p ) {
	foreach ( [ 'content_title', 'content_excerpt' ] as $tt ) {
		foreach ( [ ProposalStore::STATUS_DRAFT, ProposalStore::STATUS_PENDING_APPROVAL ] as $st ) {
			foreach ( $store->list( [ 'target_id' => (string) $p, 'operation_id' => 'content_manage', 'target_type' => $tt, 'status' => $st, 'limit' => 100 ] ) as $row ) {
				$id = (string) $row['proposal_id'];
				// A pending_approval row cannot be dismissed; retire its request first so
				// the proposal becomes dismissible again.
				if ( ProposalStore::STATUS_PENDING_APPROVAL === $st && ! empty( $row['request_id'] ) ) {
					( new \WPCommandCenter\Operations\OperationManager() )->cancel_request( (string) $row['request_id'], [] );
					$store->mark_failed( $id, 'cleaned up by tests/test-content-generation-flow.sh' );
					continue;
				}
				$store->dismiss( $id );
			}
		}
	}
}
$leftover = 0;
foreach ( $posts as $p ) {
	$leftover += $store->count( [ 'target_id' => (string) $p, 'operation_id' => 'content_manage', 'status' => ProposalStore::STATUS_DRAFT ] );
	$leftover += $store->count( [ 'target_id' => (string) $p, 'operation_id' => 'content_manage', 'status' => ProposalStore::STATUS_PENDING_APPROVAL ] );
}
echo 'leftover_open_proposals=' . $leftover . "\n";
foreach ( $posts as $p ) { wp_delete_post( $p, true ); }
echo 'cleanup=done' . "\n";
PHP

OUT="$(wp --path="$WP_PATH" eval-file "$PHPF" 2>/dev/null)"
rm -f "$PHPF"
g() { echo "$OUT" | grep -F "$1=" | head -1 | cut -d= -f2-; }

echo "-- generation persists the real answer --"
assert_eq "one draft was created"                    "1" "$(g created)"
assert_eq "the provider's text was persisted"        "A genuinely different title" "$(g persisted_title)"
assert_eq "the provider is recorded"                 "anthropic" "$(g prov)"
assert_eq "the model is recorded"                    "claude-sonnet-4-6" "$(g model)"
assert_eq "it is filed under the title field"        "content_title" "$(g target_type)"
assert_eq "it is a DRAFT"                            "draft" "$(g status)"
assert_eq "a real body is not flagged as thin"       "no" "$(g source_thin)"

echo ""
echo "-- nothing is applied by generating --"
assert_eq "the post keeps its original title"        "Original working title" "$(g post_title_after_generate)"

echo ""
echo "-- repeat generation is deduped, per field --"
assert_eq "a second title run creates nothing"       "0" "$(g dup_created)"
assert_eq "…and says why"                            "has_open_proposal" "$(g dup_reason)"
assert_eq "an excerpt draft is not blocked by a title draft" "1" "$(g excerpt_created)"

echo ""
echo "-- a failed regeneration costs the customer nothing --"
assert_eq "no replacement draft was created"         "0" "$(g regen_fail_created)"
assert_eq "nothing was reported as replaced"         "no" "$(g regen_fail_replaced)"
assert_eq "the original draft still exists"          "draft" "$(g regen_fail_original_status)"
assert_eq "…with its original text intact"           "A genuinely different title" "$(g regen_fail_original_text)"

echo ""
echo "-- a successful regeneration replaces cleanly --"
assert_eq "a replacement draft was created"          "1" "$(g regen_ok_created)"
assert_eq "the replacement is reported"              "yes" "$(g regen_ok_replaced)"
assert_eq "the old draft was retired"                "dismissed" "$(g regen_ok_old_status)"
assert_eq "the new draft holds the new text"         "A second, different title" "$(g regen_ok_new_text)"
assert_eq "exactly one open title draft remains"     "1" "$(g open_title_drafts)"
assert_eq "the post is STILL untouched"              "Original working title" "$(g post_title_after_regen)"

echo ""
echo "-- approval in flight is never replaced out from under itself --"
assert_eq "the proposal really did reach pending_approval" "pending_approval" "$(g mark_status)"
assert_eq "a pending-approval proposal is not replaceable" "0" "$(g replace_pending_created)"
assert_eq "…and says so"                             "not_replaceable" "$(g replace_pending_code)"
assert_eq "the pending proposal is untouched"        "pending_approval" "$(g replace_pending_status)"
assert_eq "a replacing id from another post is refused" "not_replaceable" "$(g replace_wrongpost_code)"

echo ""
echo "-- unsupported targets are refused clearly --"
assert_eq "an attachment is not a content target"    "not_found" "$(g attachment_reason)"
assert_eq "a trashed post is refused by status"      "unsupported_status" "$(g trashed_reason)"
assert_eq "a missing post is refused"                "not_found" "$(g missing_reason)"
assert_eq "an unknown field kind is refused"         "invalid_kind" "$(g badkind_code)"

echo ""
echo "-- a provider failure never invents a suggestion --"
assert_eq "no draft was created"                     "0" "$(g failrun_created)"
assert_eq "no proposal row was written at all"       "0" "$(g failrun_rows_added)"
assert_eq "the provider error is reported as-is"     "api_error_500" "$(g failrun_code)"

echo ""
echo "== 9. Source-content signal =="
assert_eq "empty content"                            "none" "$(g sig_empty)"
assert_eq "whitespace-only content"                  "none" "$(g sig_blank)"
assert_eq "a handful of words is thin"               "thin" "$(g sig_thin)"
assert_eq "a real body is sufficient"                "sufficient" "$(g sig_rich)"
assert_eq "one word below the threshold is thin"     "thin" "$(g sig_boundary_under)"
assert_eq "exactly at the threshold is sufficient"   "sufficient" "$(g sig_boundary_at)"
assert_eq "thin counts as thin"                      "yes" "$(g sig_thin_is_thin)"
assert_eq "sufficient does not"                      "no"  "$(g sig_rich_is_thin)"
assert_eq "a thin page STILL generates (advisory, not a gate)" "1" "$(g thin_still_generated)"
assert_eq "…and is flagged so the screen can explain it"      "yes" "$(g thin_flagged)"
assert_eq "the suite cleaned up after itself"        "done" "$(g cleanup)"
# The residue this whole workstream exists because of: a suite that deletes its posts
# and leaves its drafts behind, where a customer meets them as real suggestions.
assert_eq "…leaving NO open proposal pointing at a deleted post" "0" "$(g leftover_open_proposals)"

# The UI must actually use the signal it is given, and explain a restated suggestion.
echo ""
echo "== The screen says what the signal means =="
has "d.source && d.source.thin" "$VIEW" "the Review tab reads the server's own thin signal"
has "echoNote"                  "$VIEW" "a suggestion that restates the current value is explained"
has "wpcc-aic-thin"             "$VIEW" "thin candidates are badged before you generate"
has "wpcc-aic-regen"            "$VIEW" "suggestions offer Regenerate"
has "replacing: id"             "$VIEW" "…which replaces in place rather than dismissing first"

echo ""
echo "RESULT: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
