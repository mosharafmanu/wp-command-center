#!/usr/bin/env bash
#
# STEP 110 (Proposal Store) — Task 5: REST layer contract suite.
#
# Exercises wp-command-center/v1/admin/proposals via the live REST server
# (rest_get_server()->dispatch) as an authenticated administrator, asserting:
#   - gate/auth (401 without manage_options)
#   - thin-controller delegation (create/get/edit/apply/dismiss)
#   - read-through on GET (pending_approval materialized via ProposalSync)
#   - rollback-aware presentation (applied + change_status reflects change_log)
#   - error contracts (apply non-draft -> 400, unknown id -> 404)
#   - static boundaries (ProposalAdminQuery read-only; REST stays thin)
#
# Requires: wp-cli. Self-cleaning.

set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_ROOT="$(cd "$PLUGIN_DIR/../../.." && pwd)"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }

echo "STEP 110 Task 5 — Proposal REST contract"

# ── Dynamic contract battery (dispatched through the REST server) ────────────
BATT="$(mktemp /tmp/wpcc-rest-batt-XXXXXX.php)"
cat > "$BATT" <<'PHP'
<?php
$admin = get_users( [ 'role' => 'administrator', 'number' => 1 ] );
$uid   = $admin ? $admin[0]->ID : 1;
$NS    = '/wp-command-center/v1/admin/proposals';
$out   = [];
$emit  = function ( string $d, bool $ok, string $x = '' ) use ( &$out ) { $out[] = $d . "\t" . ( $ok ? 'PASS' : 'FAIL' ) . "\t" . $x; };
$call  = function ( string $m, string $route, ?array $body = null ) {
	$r = new WP_REST_Request( $m, $route );
	if ( null !== $body ) { $r->set_body_params( $body ); }
	$res = rest_get_server()->dispatch( $r );
	return [ $res->get_status(), $res->get_data() ];
};
global $wpdb;
$orig_mode = get_option( 'wpcc_security_mode', '' );
$aid = wp_insert_attachment( [ 'post_title' => 'wpcc-rest', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ], false );

// ── gate/auth ──
wp_set_current_user( 0 );
[ $st_noauth ] = $call( 'GET', $NS );
$emit( 'GET list denied without auth (401)', 401 === $st_noauth, (string) $st_noauth );
wp_set_current_user( $uid );
update_option( 'wpcc_security_mode', 'developer' );

// ── create -> draft ──
[ $st_c, $d_c ] = $call( 'POST', $NS, [ 'operation_id' => 'media_manage', 'action' => 'media_update', 'target_type' => 'attachment', 'target_id' => (string) $aid, 'payload' => [ 'action' => 'media_update', 'media_id' => $aid, 'alt' => 'rest cat' ] ] );
$pid = (string) ( $d_c['proposal_id'] ?? '' );
$emit( 'POST create -> 201 draft', 201 === $st_c && 'draft' === ( $d_c['status'] ?? '' ), (string) $st_c );
$emit( 'create response is shaped (payload decoded)', 'rest cat' === ( $d_c['payload']['alt'] ?? '' ) );

// ── get -> shaped, change_status null for draft ──
[ $st_g, $d_g ] = $call( 'GET', "$NS/$pid" );
$emit( 'GET detail -> 200 shaped', 200 === $st_g && $pid === ( $d_g['proposal_id'] ?? '' ) );
$emit( 'draft has null change_status', array_key_exists( 'change_status', $d_g ) && null === $d_g['change_status'] );

// ── edit final_payload (draft) ──
[ $st_e, $d_e ] = $call( 'PUT', "$NS/$pid", [ 'final_payload' => [ 'action' => 'media_update', 'media_id' => $aid, 'alt' => 'edited rest cat' ] ] );
$emit( 'PATCH final_payload (draft) -> 200', 200 === $st_e && 'edited rest cat' === ( $d_e['final_payload']['alt'] ?? '' ) );

// ── apply (developer) -> applied; final_payload wins ──
[ $st_a, $d_a ] = $call( 'POST', "$NS/$pid/apply" );
$emit( 'POST apply -> 200 applied', 200 === $st_a && 'applied' === ( $d_a['status'] ?? '' ) );
$emit( 'applied carries change_id', ! empty( $d_a['change_id'] ) );
$emit( 'applied change_status = applied', 'applied' === ( $d_a['change_status'] ?? '' ) );
$emit( 'crossing point used final_payload (edited alt written)', 'edited rest cat' === get_post_meta( $aid, '_wp_attachment_image_alt', true ) );

// ── apply non-draft -> 400 error envelope ──
[ $st_re, $d_re ] = $call( 'POST', "$NS/$pid/apply" );
$emit( 're-apply non-draft -> 400 error', 400 === $st_re && ! empty( $d_re['error'] ) && 'wpcc_proposal_not_draft' === ( $d_re['code'] ?? '' ), (string) $st_re );

// ── list envelope ──
[ $st_l, $d_l ] = $call( 'GET', $NS );
$emit( 'GET list -> 200 envelope', 200 === $st_l && 'proposals_list' === ( $d_l['action'] ?? '' ) && isset( $d_l['total_count'], $d_l['has_more'], $d_l['limit'] ) );

// ── unknown id -> 404 ──
[ $st_nf ] = $call( 'GET', "$NS/00000000-0000-0000-0000-000000000000" );
$emit( 'GET unknown id -> 404', 404 === $st_nf, (string) $st_nf );

// ── dismiss ──
[ , $d_dr ] = $call( 'POST', $NS, [ 'operation_id' => 'media_manage', 'action' => 'media_update', 'target_type' => 'attachment', 'target_id' => (string) $aid, 'payload' => [ 'alt' => 'x' ] ] );
[ $st_d, $d_d ] = $call( 'POST', "$NS/{$d_dr['proposal_id']}/dismiss" );
$emit( 'POST dismiss -> 200 dismissed', 200 === $st_d && 'dismissed' === ( $d_d['status'] ?? '' ) );

// ── read-through on GET: gated proposal executed externally -> GET materializes applied ──
update_option( 'wpcc_security_mode', 'client' );
[ , $d_gp ] = $call( 'POST', $NS, [ 'operation_id' => 'media_manage', 'action' => 'media_update', 'target_type' => 'attachment', 'target_id' => (string) $aid, 'payload' => [ 'action' => 'media_update', 'media_id' => $aid, 'alt' => 'gated rt' ] ] );
$gpid = (string) $d_gp['proposal_id'];
[ , $d_ap ] = $call( 'POST', "$NS/$gpid/apply" ); // gated -> pending_approval
$pending_ok = 'pending_approval' === ( $d_ap['status'] ?? '' );
$rid = (string) ( $d_ap['request_id'] ?? '' );
// Approve + execute the underlying request OUT OF BAND (queue/cron would do this).
$om = new WPCommandCenter\Operations\OperationManager();
$om->approve_request( $rid, [ 'type' => 'admin', 'wp_user_id' => $uid ] );
$om->execute_request( $rid, [ 'type' => 'admin', 'wp_user_id' => $uid ] );
[ , $d_rt ] = $call( 'GET', "$NS/$gpid" ); // read-through must materialize applied
$emit( 'gated apply -> pending_approval (+request_id)', $pending_ok && '' !== $rid );
$emit( 'read-through on GET materializes applied', 'applied' === ( $d_rt['status'] ?? '' ), (string) ( $d_rt['status'] ?? '' ) );
update_option( 'wpcc_security_mode', 'developer' );

// ── rollback-aware presentation: reverse the applied change -> change_status rolled_back ──
( new WPCommandCenter\Operations\OperationExecutor() )->run( 'change_history', [ 'action' => 'rollback_target', 'change_id' => $d_a['change_id'] ], [ 'actor' => [ 'type' => 'admin', 'wp_user_id' => $uid ], 'source' => 'admin_ui' ] );
[ , $d_rb ] = $call( 'GET', "$NS/$pid" );
$emit( 'rollback-aware: status stays applied', 'applied' === ( $d_rb['status'] ?? '' ) );
$emit( 'rollback-aware: change_status = rolled_back', 'rolled_back' === ( $d_rb['change_status'] ?? '' ), (string) ( $d_rb['change_status'] ?? '' ) );

// ── cleanup ──
update_option( 'wpcc_security_mode', $orig_mode );
$wpdb->query( "DELETE FROM {$wpdb->prefix}wpcc_proposals" );
$wpdb->query( "DELETE FROM {$wpdb->prefix}wpcc_operation_requests WHERE operation_id = 'media_manage'" );
$wpdb->query( "DELETE FROM {$wpdb->prefix}wpcc_change_log WHERE operation_id IN ('media_manage','change_history')" );
wp_delete_attachment( $aid, true );

echo implode( "\n", $out );
PHP
RESULTS="$(wp --path="$WP_ROOT" eval-file "$BATT" 2>/dev/null)"
rm -f "$BATT"
while IFS=$'\t' read -r DESC STATUS DETAIL; do
  [ -z "$DESC" ] && continue
  if [ "$STATUS" = "PASS" ]; then pass "$DESC"; else fail "$DESC ($DETAIL)"; fi
done <<< "$RESULTS"

# ── Static architecture protection ──────────────────────────────────────────
cd "$PLUGIN_DIR"
AQ="includes/Admin/ProposalAdminQuery.php"
AQ_CODE="$(grep -vE '^[[:space:]]*(\*|/\*|//)' "$AQ")"
# AdminQuery is read-only: no writes to proposals or authority tables, no OperationExecutor.
AQ_WRITES="$(printf '%s\n' "$AQ_CODE" | grep -iE 'wpcc_proposals|wpcc_operation_requests|wpcc_operation_results|wpcc_change_log' | grep -iE 'insert|update|delete' || true)"
assert_eq "ProposalAdminQuery performs no table writes" "" "$AQ_WRITES"
assert_eq "ProposalAdminQuery does not call OperationExecutor" "" "$(printf '%s\n' "$AQ_CODE" | grep -n 'OperationExecutor' || true)"
assert_eq "ProposalAdminQuery does not call ProposalApplyService/Sync (read-only shaper)" "" \
  "$(printf '%s\n' "$AQ_CODE" | grep -nE 'ProposalApplyService|ProposalSync' || true)"

# Sole-writer preserved across includes/ (code-only): only Schema + ProposalStore.
PROP_REFS=""
for f in $(grep -rl "wpcc_proposals" includes/ | sort); do
  # Word-boundary so the TABLE matches but the filter name wpcc_proposals_dev_ui does not.
  HITS="$(grep -vE '^[[:space:]]*(\*|/\*|//)' "$f" | grep -cE '\bwpcc_proposals\b' || true)"
  if [ "${HITS:-0}" -gt 0 ]; then PROP_REFS="${PROP_REFS}${f#includes/} "; fi
done
assert_eq "only Schema + ProposalStore write-reference wpcc_proposals (code)" \
  "Core/Schema.php Proposals/ProposalStore.php " "$PROP_REFS"

# REST proposal handlers delegate (ProposalApplyService present for apply path).
RA_CODE="$(grep -vE '^[[:space:]]*(\*|/\*|//)' includes/Admin/AdminRestApi.php)"
assert_eq "REST apply path delegates to ProposalApplyService" "yes" \
  "$(printf '%s\n' "$RA_CODE" | grep -q 'ProposalApplyService' && echo yes || echo no)"

echo ""
echo "RESULT: ${PASS} passed, ${FAIL} failed"
[ "$FAIL" -eq 0 ]
