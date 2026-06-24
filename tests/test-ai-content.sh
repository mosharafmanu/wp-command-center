#!/usr/bin/env bash
#
# AI Content Platform — Title & Excerpt governed generation + rollback.
#
# Asserts (static): the content-field AI chain creates DRAFTS only via ProposalStore
# (content_manage/content_update payload + prior + provenance), per-kind dedup via
# target_type, the additive proposals_create generate branch (Option 1), FeatureGate
# gating, the additive ContentManager excerpt rollback snapshot, and frozen invariants
# (no new route/operation/capability/MCP tool/schema).
#
# Asserts (live, wp-cli): a real content_update → content_rollback round-trip restores
# BOTH title and excerpt (the Excerpt Rollback guarantee), an excerpt-only update never
# disturbs the title, and ContentFieldGenerator (stub provider) creates correct
# title/excerpt drafts whose per-kind dedup does not cross-block.
#
# Requires: wp-cli. No real Anthropic calls (a stub provider is injected).

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

GEN="$PLUGIN_DIR/includes/Content/ContentFieldGenerator.php"
PROV="$PLUGIN_DIR/includes/Content/AnthropicContentProvider.php"
REST="$PLUGIN_DIR/includes/Admin/AdminRestApi.php"
CM="$PLUGIN_DIR/includes/Operations/ContentManager.php"

echo "AI Content — Title/Excerpt governed generation + rollback"

echo
echo "== 1. Static: content generator creates DRAFTS only (Propose != Apply) =="
has  "creates via ProposalStore"                 "ProposalStore"            "$GEN"
has  "draft operation is content_manage"         "'content_manage'"         "$GEN"
has  "draft action is content_update"            "'content_update'"         "$GEN"
has  "per-kind target_type content_title"        "content_title"            "$GEN"
has  "per-kind target_type content_excerpt"      "content_excerpt"          "$GEN"
has  "captures prior (current value)"            "'prior'"                  "$GEN"
lacks "generator never applies (no instantiation)" "new ProposalApplyService" "$GEN"
lacks "generator never calls the executor (no instantiation)" "new OperationExecutor" "$GEN"
lacks "provider never fabricates (rejects bad JSON)" "return [ 'meta_title'" "$PROV"

echo
echo "== 2. Static: additive generate branch on the EXISTING proposals route (Option 1) =="
has  "generate branch present"                   "proposals_generate_content" "$REST"
has  "branch reads generate.kind"                "'kind'"                   "$REST"
has  "branch is FeatureGate-aware (title)"       "title_generator"          "$REST"
has  "branch is FeatureGate-aware (excerpt)"     "excerpt_generator"        "$REST"
has  "branch routes to ContentFieldGenerator"    "ContentFieldGenerator"    "$REST"
lacks "no new title generate route"              "/admin/title/generate"    "$REST"
lacks "no new excerpt generate route"            "/admin/excerpt/generate"  "$REST"
lacks "no new content generate route"            "/admin/content/generate"  "$REST"

echo
echo "== 3. Static: ContentManager excerpt rollback fix (additive, guarded) =="
# PROGRAM-4 / P4.3 replaced the full-object before-state snapshot (which carried the literal
# 'excerpt' => $post->post_excerpt) with a field-scoped RollbackDelta capture over CONTENT_FIELDS.
# Validate the delta implementation: excerpt is a tracked content field captured via the core.
has  "update delta-captures excerpt (tracked field)" "'content', 'excerpt' ]" "$CM"
has  "update captures touched fields via delta core" "RollbackDelta::capture" "$CM"
has  "rollback restores excerpt only when present" "array_key_exists( 'excerpt', \$before )" "$CM"
has  "rollback sets post_excerpt"                "'post_excerpt'"           "$CM"

echo
echo "== 4. Live: content_update → content_rollback round-trip (Rollback guarantee) =="
BATT="$(mktemp /tmp/wpcc-ai-content-XXXX.php)"
cat > "$BATT" <<'PHP'
<?php
use WPCommandCenter\Operations\ContentManager;
use WPCommandCenter\Content\ContentFieldGenerator;
use WPCommandCenter\Content\ContentFieldProvider;
use WPCommandCenter\Content\ContentFieldProviderResolver;
use WPCommandCenter\Content\ContentFieldResult;
use WPCommandCenter\Proposals\ProposalStore;

// A deterministic stub provider (no network).
class WPCC_StubContentProvider implements ContentFieldProvider {
	public function id(): string { return 'stub'; }
	public function is_configured(): bool { return true; }
	public function suggest( string $kind, array $content, array $context = [] ): ContentFieldResult {
		return ContentFieldResult::ok( 'STUB ' . strtoupper( $kind ), 'stub', 'stub-model' );
	}
}
class WPCC_StubContentResolver extends ContentFieldProviderResolver {
	public function active(): ?ContentFieldProvider { return new WPCC_StubContentProvider(); }
	public function has_active(): bool { return true; }
}

$pid = wp_insert_post( [ 'post_title' => 'ORIG TITLE', 'post_excerpt' => 'ORIG EXCERPT', 'post_content' => 'Body text for context.', 'post_status' => 'publish' ], true );
if ( is_wp_error( $pid ) ) { echo "ERR insert\n"; exit; }

$cm = new ContentManager();

// (a) Excerpt-only update then rollback restores the excerpt (the fix).
$u = $cm->run( [ 'action' => 'content_update', 'content_id' => $pid, 'excerpt' => 'NEW EXCERPT' ] );
$rb = $u['rollback_id'] ?? '';
echo 'EXCERPT_AFTER_UPDATE=' . get_post( $pid )->post_excerpt . "\n";
echo 'TITLE_UNTOUCHED_BY_EXCERPT_UPDATE=' . get_post( $pid )->post_title . "\n";
$cm->run( [ 'action' => 'content_rollback', 'rollback_id' => $rb ] );
echo 'EXCERPT_AFTER_ROLLBACK=' . get_post( $pid )->post_excerpt . "\n";

// (b) Title-only update then rollback restores the title (regression).
$u2  = $cm->run( [ 'action' => 'content_update', 'content_id' => $pid, 'title' => 'NEW TITLE' ] );
$rb2 = $u2['rollback_id'] ?? '';
echo 'TITLE_AFTER_UPDATE=' . get_post( $pid )->post_title . "\n";
$cm->run( [ 'action' => 'content_rollback', 'rollback_id' => $rb2 ] );
echo 'TITLE_AFTER_ROLLBACK=' . get_post( $pid )->post_title . "\n";

// (c) Generator (stub) creates correct title + excerpt drafts; per-kind dedup does
//     NOT cross-block (a title draft must not block an excerpt draft).
$gen = new ContentFieldGenerator( new ProposalStore(), new WPCC_StubContentResolver() );
$rt = $gen->generate( $pid, 'title' );
$re = $gen->generate( $pid, 'excerpt' );
echo 'TITLE_DRAFT_CREATED=' . ( count( $rt['created'] ) ) . "\n";
echo 'EXCERPT_DRAFT_CREATED=' . ( count( $re['created'] ) ) . "\n";
$store = new ProposalStore();
$tp = $store->get( $rt['created'][0] ?? '' );
$pl = json_decode( $tp['payload_json'] ?? '{}', true );
echo 'TITLE_PAYLOAD_OP=' . ( $tp['operation_id'] ?? '' ) . "\n";
echo 'TITLE_PAYLOAD_ACTION=' . ( $pl['action'] ?? '' ) . "\n";
echo 'TITLE_PAYLOAD_FIELD=' . ( $pl['title'] ?? '' ) . "\n";
echo 'TITLE_TARGET_TYPE=' . ( $tp['target_type'] ?? '' ) . "\n";
// Dedup within the same kind blocks a second title draft.
$rt2 = $gen->generate( $pid, 'title' );
echo 'TITLE_REDRAFT_SKIPPED=' . ( $rt2['skipped'][0]['reason'] ?? '' ) . "\n";

// cleanup
wp_delete_post( $pid, true );
PHP
RES="$(wp --path="$WP_ROOT" eval-file "$BATT" 2>/dev/null)"; rm -f "$BATT"

getv() { echo "$RES" | grep -F "$1=" | head -1 | cut -d= -f2-; }
assert_eq "excerpt updated to NEW EXCERPT"             "NEW EXCERPT"  "$(getv EXCERPT_AFTER_UPDATE)"
assert_eq "title untouched by excerpt-only update"     "ORIG TITLE"   "$(getv TITLE_UNTOUCHED_BY_EXCERPT_UPDATE)"
assert_eq "EXCERPT RESTORED by rollback (the fix)"     "ORIG EXCERPT" "$(getv EXCERPT_AFTER_ROLLBACK)"
assert_eq "title updated to NEW TITLE"                 "NEW TITLE"    "$(getv TITLE_AFTER_UPDATE)"
assert_eq "title restored by rollback (regression)"    "ORIG TITLE"   "$(getv TITLE_AFTER_ROLLBACK)"
assert_eq "title draft created"                        "1"            "$(getv TITLE_DRAFT_CREATED)"
assert_eq "excerpt draft created (kind not cross-blocked)" "1"        "$(getv EXCERPT_DRAFT_CREATED)"
assert_eq "title draft op is content_manage"           "content_manage" "$(getv TITLE_PAYLOAD_OP)"
assert_eq "title draft action is content_update"       "content_update" "$(getv TITLE_PAYLOAD_ACTION)"
assert_eq "title draft carries the suggested title"    "STUB TITLE"   "$(getv TITLE_PAYLOAD_FIELD)"
assert_eq "title draft target_type is content_title"   "content_title" "$(getv TITLE_TARGET_TYPE)"
assert_eq "second title draft deduped"                 "has_open_proposal" "$(getv TITLE_REDRAFT_SKIPPED)"

echo
echo "RESULT: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
