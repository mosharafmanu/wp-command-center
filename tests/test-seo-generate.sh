#!/usr/bin/env bash
#
# STEP 111 — GA#2 Slice 2b: SEO meta generation (governed drafts).
#
# Asserts: AnthropicSeoProvider JSON parsing, SeoMetaGenerator draft creation via
# ProposalStore (correct seo_manage/seo_update payload + prior + provenance), dedup,
# cap, per-item failure isolation, no_provider degradation, READ-ONLY w.r.t the site
# (no seo_update / no SeoProvider::write / no apply), the CREATABLE generate route,
# and frozen invariants. No real Anthropic calls (a stub provider is injected).
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

RESTAPI="$PLUGIN_DIR/includes/Admin/AdminRestApi.php"
GEN="$PLUGIN_DIR/includes/Seo/SeoMetaGenerator.php"
PROV="$PLUGIN_DIR/includes/Seo/AnthropicSeoProvider.php"
VIEW="$PLUGIN_DIR/includes/Admin/views/seo-meta.php"

echo "STEP 111 — GA#2 Slice 2b: SEO meta generation (drafts)"

echo
echo "== 1. Static: generator creates DRAFTS only; no apply / no site write =="
has  "generator creates via ProposalStore"   "ProposalStore"        "$GEN"
has  "draft payload is seo_manage"            "'seo_manage'"         "$GEN"
has  "draft action is seo_update"             "'seo_update'"         "$GEN"
has  "captures prior (current meta)"          "'prior'"              "$GEN"
lacks "generator never applies (no instantiation)" "new ProposalApplyService" "$GEN"
lacks "generator no engine dispatch (no instantiation)" "new OperationExecutor" "$GEN"
lacks "generator no SEO write call"           "SeoProvider::write("  "$GEN"
lacks "generator no post meta write"          "update_post_meta"     "$GEN"
lacks "generator no option write"             "update_option"        "$GEN"

echo
echo "== 2. Static: provider is a pure suggestion source (delegates transport) =="
has  "provider uses shared AnthropicClient"   "AnthropicClient"      "$PROV"
has  "provider returns SeoMetaResult"         "SeoMetaResult"        "$PROV"
lacks "provider no direct wp_remote_post"     "wp_remote_post"       "$PROV"
lacks "provider no ProposalStore use"         "new ProposalStore"    "$PROV"
lacks "provider no SEO write call"            "SeoProvider::write("  "$PROV"

echo
echo "== 3. Route: CREATABLE generate route, FeatureGate-gated =="
has  "route: /admin/seo/generate"             "'/admin/seo/generate'" "$RESTAPI"
has  "generate handler present"               "function seo_generate" "$RESTAPI"
has  "gated by seo permission"                "check_seo_permission"  "$RESTAPI"
# generate handler delegates to the generator, never the executor directly.
GH="$(awk '/function seo_generate/{f=1} f{print} f&&/^\t}/{exit}' "$RESTAPI")"
if printf '%s' "$GH" | grep -qE "OperationExecutor|->run\(|SeoProvider::write"; then fail "seo_generate handler does not apply"; else pass "seo_generate handler creates drafts only (no apply)"; fi

echo
echo "== 4. View: minimal generate control (drafts only) =="
has  "view has generate control"              "wpcc-seo-generate"    "$VIEW"
has  "view posts to /seo/generate"            "/seo/generate"        "$VIEW"
has  "view states drafts only"                "nothing is applied"   "$VIEW"

echo
echo "== 4b. UX polish (U1.2 handoff, U1.4 no-provider notice) =="
# U1.2 — on successful generation the view auto-switches to the Suggestions tab.
has  "Generate->Suggestions handoff"          "switchTab( 'suggestions' )" "$VIEW"
# U1.4 — no_provider results surface AI-key guidance linking to AI Integrations.
has  "no-provider notice element"             "wpcc-seo-gen-notice"  "$VIEW"
has  "detects no_provider skip reason"        "reason === 'no_provider'" "$VIEW"
has  "links to AI Integrations"               "wpcc-ai-integrations" "$VIEW"
has  "uses server-provided AI URL const"      "AI_URL"               "$VIEW"
# (Apply arrives in Slice 4a; per-item Undo in Slice 4b — both covered by
# test-seo-apply.sh / test-seo-undo.sh. The shared view now legitimately contains the
# /history/ rollback route for the Applied-tab Undo, so that absence guard is dropped.)

echo
echo "== 5. Functional: parsing + generation (stub provider, no network) =="
if ! command -v wp >/dev/null 2>&1; then
	echo "  SKIP: wp-cli not available — static checks only."
else
	BATT="$(mktemp /tmp/wpcc-seogen-XXXX.php)"
	cat > "$BATT" <<'PHP'
<?php
use WPCommandCenter\Seo\AnthropicSeoProvider;
use WPCommandCenter\Seo\SeoMetaProvider;
use WPCommandCenter\Seo\SeoMetaResult;
use WPCommandCenter\Seo\SeoMetaProviderResolver;
use WPCommandCenter\Seo\SeoMetaGenerator;
use WPCommandCenter\Proposals\ProposalStore;

$a=get_users(['role'=>'administrator','number'=>1]); wp_set_current_user($a?$a[0]->ID:1);
$out = [];

// Parsing (static, deterministic).
$out['p_plain']   = AnthropicSeoProvider::extract_meta('{"meta_title":"T","meta_description":"D"}') ? 1 : 0;
$out['p_fenced']  = AnthropicSeoProvider::extract_meta("```json\n{\"meta_title\":\"T\",\"meta_description\":\"D\"}\n```") ? 1 : 0;
$out['p_prose']   = AnthropicSeoProvider::extract_meta('x {"meta_title":"T","meta_description":"D"} y') ? 1 : 0;
$out['p_bad']     = ( null === AnthropicSeoProvider::extract_meta('not json') ) ? 1 : 0;
$out['p_missing'] = ( null === AnthropicSeoProvider::extract_meta('{"meta_title":"only"}') ) ? 1 : 0;

$store = new ProposalStore();
$okStub = new class implements SeoMetaProvider {
  public function id(): string { return 'stub'; }
  public function is_configured(): bool { return true; }
  public function suggest_meta(array $c, array $x=[]): SeoMetaResult { return SeoMetaResult::ok('Stub Title','Stub description sufficiently long for a realistic meta description test scenario.', 'stub', 'stub-model'); }
};
$errStub = new class implements SeoMetaProvider {
  public function id(): string { return 'stub'; }
  public function is_configured(): bool { return true; }
  public function suggest_meta(array $c, array $x=[]): SeoMetaResult { return SeoMetaResult::error('boom','nope','stub','stub-model'); }
};
$mkResolver = function($p){ return new class($p) extends SeoMetaProviderResolver { private $p; public function __construct($p){ $this->p=$p; } public function active(): ?SeoMetaProvider { return $this->p; } }; };

$pid = wp_insert_post(['post_title'=>'WPCC SEO gen test','post_status'=>'publish','post_type'=>'post','post_content'=>'Content about widgets.']);
update_post_meta($pid, '_yoast_wpseo_title', 'Existing Title'); // prior source (yoast on dev)

$before = $store->count([]);
$env = (new SeoMetaGenerator($store, $mkResolver($okStub)))->generate([$pid], ['actor'=>['type'=>'admin']]);
$out['created']  = count($env['created']);
$out['action']   = (string) $env['action'];
$out['provider'] = (string) $env['provider'];

$rows = $store->list(['target_id'=>(string)$pid,'operation_id'=>'seo_manage','status'=>'draft']);
$row = $rows[0] ?? null;
if ($row) {
  $pl = json_decode($row['payload_json'], true); $pr = json_decode($row['prior_json'], true);
  $out['op']        = (string) $row['operation_id'];
  $out['act']       = (string) $row['action'];
  $out['pl_action'] = (string) ($pl['action'] ?? '');
  $out['pl_cid']    = (int) ($pl['content_id'] ?? 0) === $pid ? 1 : 0;
  $out['pl_title']  = (string) ($pl['seo']['title'] ?? '');
  $out['prior_ok']  = ( ($pr['title'] ?? '') === 'Existing Title' ) ? 1 : 0;
  $out['model']     = (string) $row['model'];
}

// Read-only: post's REAL SEO meta unchanged (generator must not write seo_update).
$out['meta_intact'] = ( get_post_meta($pid, '_yoast_wpseo_title', true) === 'Existing Title' ) ? 1 : 0;

// Dedup: a second run skips (open proposal exists).
$env2 = (new SeoMetaGenerator($store, $mkResolver($okStub)))->generate([$pid], ['actor'=>[]]);
$out['dedup'] = ( 0 === count($env2['created']) && 'has_open_proposal' === ($env2['skipped'][0]['reason'] ?? '') ) ? 1 : 0;

// Per-item failure isolation: error stub -> failed[], no draft.
$pid2 = wp_insert_post(['post_title'=>'WPCC SEO gen err','post_status'=>'publish','post_type'=>'post','post_content'=>'x']);
$enve = (new SeoMetaGenerator($store, $mkResolver($errStub)))->generate([$pid2], ['actor'=>[]]);
$out['fail_isolated'] = ( 0 === count($enve['created']) && 1 === count($enve['failed']) && 'boom' === ($enve['failed'][0]['code'] ?? '') ) ? 1 : 0;

// no_provider: resolver active() null -> all skipped no_provider (no draft).
$nullResolver = new class extends SeoMetaProviderResolver { public function active(): ?SeoMetaProvider { return null; } };
$pid3 = wp_insert_post(['post_title'=>'WPCC SEO gen np','post_status'=>'publish','post_type'=>'post','post_content'=>'x']);
$envn = (new SeoMetaGenerator($store, $nullResolver))->generate([$pid3], ['actor'=>[]]);
$out['no_provider'] = ( 0 === count($envn['created']) && 'no_provider' === ($envn['skipped'][0]['reason'] ?? '') ) ? 1 : 0;

// Cap: >25 ids capped to 25 processed (use null provider so it just skips, bounded).
$many = range(900000, 900040); // 41 ids
$envc = (new SeoMetaGenerator($store, $nullResolver))->generate($many, ['actor'=>[]]);
$out['cap'] = ( 25 === count($envc['skipped']) ) ? 1 : 0;

// --- Status allow-list (generator) ---
// Allowed editable statuses (draft/pending/future/private) each generate a draft.
$mkStatusPost = function($status){
  $a = ['post_title'=>'WPCC st '.$status,'post_status'=>$status,'post_type'=>'post','post_content'=>'Content about widgets and gadgets for the meta test.'];
  if ('future' === $status){ $a['post_date'] = gmdate('Y-m-d H:i:s', time()+7*86400); $a['post_date_gmt'] = $a['post_date']; }
  return wp_insert_post($a);
};
$allowed_gen = 1; $status_ids = [];
foreach (['draft','pending','future','private'] as $st){
  $sid = $mkStatusPost($st); $status_ids[] = $sid;
  $es = (new SeoMetaGenerator($store, $mkResolver($okStub)))->generate([$sid], ['actor'=>[]]);
  if ( 1 !== count($es['created']) ) { $allowed_gen = 0; }
}
$out['allowed_status_generates'] = $allowed_gen;
// Disallowed: a trashed post is skipped with reason unsupported_status, no draft.
$trashId = $mkStatusPost('publish'); wp_trash_post($trashId);
$et = (new SeoMetaGenerator($store, $mkResolver($okStub)))->generate([$trashId], ['actor'=>[]]);
$out['trash_skipped'] = ( 0 === count($et['created']) && 'unsupported_status' === ($et['skipped'][0]['reason'] ?? '') ) ? 1 : 0;
// cleanup status-matrix proposals + posts (keep $after_drafts count stable below)
foreach ($status_ids as $sid){ foreach ($store->list(['target_id'=>(string)$sid,'operation_id'=>'seo_manage']) as $r){ $store->dismiss($r['proposal_id']); } wp_delete_post($sid, true); }
wp_delete_post($trashId, true);

$after_drafts = $store->count(['operation_id'=>'seo_manage','status'=>'draft']);

// cleanup
foreach ($store->list(['target_id'=>(string)$pid,'operation_id'=>'seo_manage']) as $r) { $store->dismiss($r['proposal_id']); }
wp_delete_post($pid, true); wp_delete_post($pid2, true); wp_delete_post($pid3, true);

echo wp_json_encode($out);
PHP
	RES="$(wp --path="$WP_ROOT" eval-file "$BATT" 2>/dev/null)"; rm -f "$BATT"
	getj() { printf '%s' "$RES" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["'"$1"'"] ?? "";' 2>/dev/null; }

	assert_eq "parse plain JSON"               "1" "$(getj p_plain)"
	assert_eq "parse fenced JSON"              "1" "$(getj p_fenced)"
	assert_eq "parse JSON in prose"            "1" "$(getj p_prose)"
	assert_eq "reject non-JSON"                "1" "$(getj p_bad)"
	assert_eq "reject missing key"             "1" "$(getj p_missing)"
	assert_eq "generate creates 1 draft"       "1" "$(getj created)"
	assert_eq "envelope action seo_meta_generate" "seo_meta_generate" "$(getj action)"
	assert_eq "draft operation_id seo_manage"  "seo_manage" "$(getj op)"
	assert_eq "draft action seo_update"        "seo_update" "$(getj act)"
	assert_eq "allowed statuses (draft/pending/future/private) generate a draft" "1" "$(getj allowed_status_generates)"
	assert_eq "trashed content skipped (unsupported_status)" "1" "$(getj trash_skipped)"
	assert_eq "payload action seo_update"      "seo_update" "$(getj pl_action)"
	assert_eq "payload content_id matches"     "1" "$(getj pl_cid)"
	assert_eq "payload carries suggested title" "Stub Title" "$(getj pl_title)"
	assert_eq "prior captures current meta"    "1" "$(getj prior_ok)"
	assert_eq "provenance model recorded"      "stub-model" "$(getj model)"
	assert_eq "READ-ONLY: site SEO meta intact" "1" "$(getj meta_intact)"
	assert_eq "dedup skips open proposal"      "1" "$(getj dedup)"
	assert_eq "per-item failure isolated"      "1" "$(getj fail_isolated)"
	assert_eq "no_provider -> all skipped"     "1" "$(getj no_provider)"
	assert_eq "cap bounds at 25"               "1" "$(getj cap)"
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
