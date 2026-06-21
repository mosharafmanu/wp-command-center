#!/usr/bin/env bash
#
# STEP 110 (Task 8.1) — Builder AI Alt Text surface (scaffold + Review tab).
#
# Asserts the build-flag-gated submenu + the read-only, thin-REST-client Review
# view. No generation/apply/edit/dismiss/rollback; no backend/schema/invariant
# changes.
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
wpe() { wp --path="$WP_ROOT" eval "$1" 2>/dev/null; }

echo "STEP 110 Task 8.1 — AI Alt Text surface (Review tab)"

BATT="$(mktemp /tmp/wpcc-atui-XXXXXX.php)"
cat > "$BATT" <<'PHP'
<?php
$a=get_users(['role'=>'administrator','number'=>1]); wp_set_current_user($a?$a[0]->ID:1);
$out=[]; $emit=function($d,$ok,$x='')use(&$out){ $out[]=$d."\t".($ok?'PASS':'FAIL')."\t".$x; };
// Experience Layer: AI Alt Text is the Operate › alt_text tab in the 5-C App Shell
// (added only when the build flag is on AND the FeatureGate allows ai_alt_text).
$reg=function(){ $s=\WPCommandCenter\Admin\AppShell::sections(); return $s['wpcc-operate']['tabs']['alt_text'] ?? null; };

// 1. hidden by default
remove_all_filters('wpcc_alt_text_ui');
$emit('tab hidden by default', $reg()===null);

// 2. visible when build flag on AND FeatureGate allows ai_alt_text
add_filter('wpcc_alt_text_ui','__return_true');
$item=$reg();
$emit('tab visible when flag on + FeatureGate allows', $item!==null && ($item['view']??'')==='ai-alt-text');
$emit('tab title is "AI Alt Text"', $item!==null && wp_strip_all_tags($item['label']??'')==='AI Alt Text');

// 2b. AND-gating: build flag on but FeatureGate('ai_alt_text') OFF -> hidden
$deny=function($allow,$feature){ return $feature==='ai_alt_text' ? false : $allow; };
add_filter('wpcc_feature_allowed',$deny,10,2);
$emit('tab hidden when FeatureGate denies ai_alt_text', $reg()===null);
remove_filter('wpcc_feature_allowed',$deny,10);

// 3. view renders with required markers
ob_start(); require WPCC_PLUGIN_DIR.'includes/Admin/views/ai-alt-text.php'; $html=ob_get_clean();
$emit('view renders', strlen($html)>500);
$emit('readiness header present', strpos($html,'Media described')!==false && strpos($html,'Missing alt text')!==false && strpos($html,'Weak alt text')!==false);
$emit('Review tab present', strpos($html,'Review')!==false);
$emit('filter present (missing/weak/all)', strpos($html,'wpcc-at-filter')!==false);
$emit('state badge logic present', strpos($html,'function badge')!==false);
$emit('suggestion-pending indicator present', strpos($html,'has_open_proposal')!==false);
$emit('loading/empty/error states present', strpos($html,'Loading')!==false && strpos($html,'No images')!==false && strpos($html,'Could not load')!==false);
$emit('uses scan endpoint + nonce', strpos($html,'/alt-text/scan')!==false && strpos($html,'X-WP-Nonce')!==false);

// 4. Task 8.2 — Review tab selection + chunked generate
$emit('Review tab has selection checkboxes', strpos($html,'wpcc-at-cb')!==false);
$emit('"Select all this page" present', strpos($html,'wpcc-at-selectall')!==false);
$emit('Generate CTA present', strpos($html,'wpcc-at-generate')!==false);
$emit('pending rows are NOT selectable (conditional on has_open_proposal)', strpos($html,'has_open_proposal')!==false && strpos($html,'pending')!==false);
$emit('MAX_BATCH=25 communicated', strpos($html,'25')!==false);
$emit('generate uses /alt-text/generate', strpos($html,'/alt-text/generate')!==false);
$emit('chunked generation logic exists', strpos($html,'CHUNK')!==false && strpos($html,'runChunk')!==false);
$emit('generate shows progress (created/skipped/failed)', strpos($html,'created')!==false && strpos($html,'skipped')!==false && strpos($html,'failed')!==false);

// 5. Task 8.2 — Suggestions tab
$emit('Suggestions tab present', strpos($html,'wpcc-at-tab-suggestions')!==false && strpos($html,'wpcc-at-panel-suggestions')!==false);
$emit('Suggestions uses drafts (status=draft & operation_id=media_manage)', strpos($html,'status=draft&operation_id=media_manage')!==false);
$emit('thumbnails via WP core /wp/v2/media', strpos($html,'/media?include=')!==false);
$emit('edit uses proposals/{id} with final_payload', strpos($html,'final_payload')!==false && strpos($html,'/proposals/')!==false);
$emit('dismiss uses /proposals/{id}/dismiss', strpos($html,'/dismiss')!==false);
$emit('provider attribution subtle (Suggested by AI)', strpos($html,'Suggested by AI')!==false);
$emit('edited indicator present', strpos($html,'Edited')!==false);

// 6. Task 8.3 — Approve & Apply + Undo (apply/undo now in scope)
$emit('mode-aware apply button + MODE const', strpos($html,'Approve & Apply')!==false && strpos($html,'Submit for approval')!==false && strpos($html,'const MODE')!==false);
$emit('apply uses /proposals/{id}/apply', strpos($html,'/apply')!==false && strpos($html,'wpcc-at-apply')!==false);
$emit('Applied tab present', strpos($html,'wpcc-at-tab-applied')!==false && strpos($html,'wpcc-at-panel-applied')!==false);
$emit('Applied tab uses status=applied + pending_approval', strpos($html,'status=applied&operation_id=media_manage')!==false && strpos($html,'status=pending_approval&operation_id=media_manage')!==false);
$emit('pending shows Awaiting approval + Approval Center link', strpos($html,'Awaiting approval')!==false && strpos($html,'wpcc-approval-center')!==false);
$emit('Undo uses existing rollback route', strpos($html,'/history/')!==false && strpos($html,'/rollback')!==false && strpos($html,'wpcc-at-undo')!==false);
$emit('rollback-aware Reverted state', strpos($html,'Reverted')!==false && strpos($html,'rolled_back')!==false);
$emit('gated undo handled (sent for approval)', strpos($html,'Undo sent for approval')!==false);

// 6b. No second-system boundary (still forbidden)
$emit('no standalone approve/reject CONTROLS', stripos($html,'>Approve<')===false && stripos($html,'>Reject<')===false && strpos($html,'wpcc-at-approve')===false && strpos($html,'wpcc-at-reject')===false);
$emit('no Change History timeline/diff duplication (link only)', stripos($html,'timeline')===false && strpos($html,'wpcc-diff')===false);
// Tier-2 still deferred: no BATCH-level apply/undo/approval/rollback primitive.
$emit('no batch-level apply/undo/approval/rollback primitive', strpos($html,'batch_apply')===false && strpos($html,'batchUndo')===false && strpos($html,'batch-undo')===false && strpos($html,'batch_rollback')===false && strpos($html,'batch_approval')===false);

// 8. Task 8.4 — Tier-1 bulk workflows (Suggestions tab; UI-only, reuses endpoints)
$emit('bulk action bar present (apply/dismiss selected)', strpos($html,'wpcc-at-sg-apply')!==false && strpos($html,'wpcc-at-sg-dismiss')!==false);
$emit('per-row bulk select checkboxes', strpos($html,'wpcc-at-sg-cb')!==false);
$emit('select-all on Suggestions page', strpos($html,'wpcc-at-sg-selectall')!==false);
$emit('bulk Apply reuses existing /proposals/{id}/apply', strpos($html,'bulkApply')!==false && strpos($html,'/apply')!==false);
$emit('bulk Dismiss reuses existing /proposals/{id}/dismiss', strpos($html,'bulkDismiss')!==false && strpos($html,'/dismiss')!==false);
$emit('sequential (one-at-a-time) processing', strpos($html,'runSequential')!==false);
$emit('progress region role=status', strpos($html,'wpcc-at-sg-progress')!==false && strpos($html,'role="status"')!==false);
$emit('progress reports applied/submitted/dismissed/failed', strpos($html,'lblApplied')!==false && strpos($html,'lblPending')!==false && strpos($html,'lblDismissed')!==false && strpos($html,'lblFailed')!==false);
$emit('processed/total counter present', strpos($html,'bulkProcessing')!==false);
$emit('per-item failure isolation (failed row keeps a message)', strpos($html,'cantApply')!==false);
$emit('mode-aware bulk confirm (dev vs gate)', strpos($html,'confirmApplyDev')!==false && strpos($html,'confirmApplyGate')!==false);
$emit('batch-scoped review via batch_id grouping key', strpos($html,'wpcc-at-sg-scope')!==false && strpos($html,'lastRunBatchIds')!==false);

// 8a. S2.2.1 — cross-page "select all matching" (server-resolved, bounded)
$emit('select-all-matching control present', strpos($html,'wpcc-at-sg-matchall')!==false);
$emit('resolves server-side via /alt-text/selection', strpos($html,'/alt-text/selection')!==false);
$emit('re-resolves at action time', strpos($html,'resolveMatching')!==false);
$emit('over-cap refusal surfaced (not truncated)', strpos($html,'matchOverCap')!==false);
$emit('feeds existing per-item loops (runApply/runDismiss)', strpos($html,'runApply')!==false && strpos($html,'runDismiss')!==false);
$emit('match-all is stateless (no persisted selection id)', strpos($html,'selection_id')===false && strpos($html,'saved_selection')===false);
$emit('no batch endpoint / batch primitive in view', strpos($html,'/batch')===false && strpos($html,'batch_apply')===false);

// 7. No proposal internals DISPLAYED (ids only as opaque data-* handles)
$emit('no payload_json shown', strpos($html,'payload_json')===false);
$emit('no request_id referenced', strpos($html,'request_id')===false);
// Task 8.4: batch_id is used ONLY as an internal grouping key (like proposal_id as
// data-id) — never escaped into a displayed cell.
$emit('batch_id used only as opaque grouping key (never displayed)', strpos($html,'lastRunBatchIds')!==false && strpos($html,'esc( p.batch_id')===false && strpos($html,'esc(p.batch_id')===false);
$emit('proposal_id only as opaque data-id handle', strpos($html,'data-id')!==false);
$emit('change_id only as opaque data-cid handle', strpos($html,'data-cid')!==false);
$leak=false; foreach(['ProposalStore','AltTextGenerator','OperationExecutor','ProposalApplyService'] as $t){ if(strpos($html,$t)!==false){$leak=true;} }
$emit('no ProposalStore/Generator/Executor refs in output', !$leak);

remove_all_filters('wpcc_alt_text_ui');
echo implode("\n",$out);
PHP
RES="$(wp --path="$WP_ROOT" eval-file "$BATT" 2>/dev/null)"
rm -f "$BATT"
while IFS=$'\t' read -r DESC STATUS DETAIL; do
  [ -z "$DESC" ] && continue
  if [ "$STATUS" = "PASS" ]; then pass "$DESC"; else fail "$DESC ($DETAIL)"; fi
done <<< "$RES"

# ── Static: the view PHP is a thin client (no backend service calls) ─────────
VIEW_CODE="$(grep -vE '^[[:space:]]*(\*|/\*|//)' includes/Admin/views/ai-alt-text.php)"
VIEW_TMP="$(mktemp /tmp/wpcc-atview-XXXXXX)"; printf '%s' "$VIEW_CODE" > "$VIEW_TMP"
for forbidden in "new ProposalStore" "new AltTextGenerator" "OperationExecutor" "ProposalApplyService" "AltTextScanQuery" "->generate(" "->apply(" "update_post_meta" "wp_update_post"; do
  grep -qF -- "$forbidden" "$VIEW_TMP" && fail "view PHP references $forbidden" || pass "view PHP has no $forbidden"
done
rm -f "$VIEW_TMP"

# ── Invariants ───────────────────────────────────────────────────────────────
assert_eq "invariant: OPERATION_MAP == 34" "34" "$(wpe 'echo count(\WPCommandCenter\Operations\CapabilityRegistry::OPERATION_MAP);')"
assert_eq "invariant: capabilities == 23"  "23" "$(wpe 'echo count(\WPCommandCenter\Operations\CapabilityRegistry::ALL_CAPABILITIES);')"
assert_eq "invariant: catalogue == 40"     "40" "$(wpe 'echo count((new \WPCommandCenter\Operations\OperationRegistry())->get_operations());')"
assert_eq "invariant: DB_VERSION 2.5.0"    "2.5.0" "$(wpe 'echo \WPCommandCenter\Core\Schema::DB_VERSION;')"

echo ""
echo "RESULT: ${PASS} passed, ${FAIL} failed"
[ "$FAIL" -eq 0 ]
