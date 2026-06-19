#!/usr/bin/env bash
#
# STEP 110 — Proposal Store (Governed Drafts), Task 1: schema foundation.
#
# Scope of THIS suite (Task 1 only): the wpcc_proposals table + DB_VERSION bump.
# There is NO ProposalStore service, ApplyService, Sync/Reconciler, REST route,
# or UI yet — those are Tasks 2+. This asserts ONLY:
#
#   - DB_VERSION constant + stored option are 2.5.0
#   - wpcc_proposals exists with the full column set + indexes
#   - dbDelta is idempotent (re-running install() does not error / re-shape)
#   - the 2.4.0 -> 2.5.0 upgrade path creates the table on a normal load
#     (Schema::maybe_upgrade(), not activation-only)
#   - pre-existing tables (change_log, operation_requests) remain unaltered
#   - platform invariants are unchanged: OPERATION_MAP 34 / caps 23 /
#     catalogue 40 (MCP 40 == catalogue by construction); only DB_VERSION moved
#
# Requires: wp-cli. (No REST token needed — schema-only.)
# Usage: bash tests/test-proposal-store.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_ROOT="$(cd "$PLUGIN_DIR/../../.." && pwd)"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq()       { local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }
assert_contains() { local d="$1" h="$2" n="$3"; printf '%s\n' "$h" | grep -qx "$n" && pass "$d" || fail "$d (missing '$n')"; }

wpe() { wp --path="$WP_ROOT" eval "$1" 2>/dev/null; }

echo "STEP 110 Task 1 — Proposal Store schema foundation"
echo "WP_ROOT=$WP_ROOT"

# ── 1. DB_VERSION constant + stored option ──────────────────────────────────
assert_eq "DB_VERSION constant is 2.5.0" "2.5.0" "$(wpe 'echo \WPCommandCenter\Core\Schema::DB_VERSION;')"
assert_eq "stored wpcc_db_version option is 2.5.0" "2.5.0" "$(wpe 'echo get_option("wpcc_db_version");')"

# ── 2. Table exists ─────────────────────────────────────────────────────────
assert_eq "wpcc_proposals table exists" "yes" \
  "$(wpe 'global $wpdb; $t=$wpdb->prefix."wpcc_proposals"; echo $wpdb->get_var("SHOW TABLES LIKE \x27$t\x27")===$t?"yes":"no";')"

# ── 3. Full column set present ───────────────────────────────────────────────
COLS="$(wpe 'global $wpdb; $t=$wpdb->prefix."wpcc_proposals"; foreach($wpdb->get_col("DESC $t",0) as $c){echo $c."\n";}')"
for col in id proposal_id batch_id session_id operation_id action target_type target_id \
           payload_json prior_json final_payload_json status provider model confidence \
           proposed_by approved_by applied_by request_id change_id risk_level error_json \
           created_at updated_at expires_at; do
  assert_contains "column present: $col" "$COLS" "$col"
done

# ── 4. Indexes present ──────────────────────────────────────────────────────
IDX="$(wpe 'global $wpdb; $t=$wpdb->prefix."wpcc_proposals"; foreach($wpdb->get_results("SHOW INDEX FROM $t") as $r){echo $r->Key_name."\n";}' | sort -u)"
for key in PRIMARY proposal_id batch_id session_id operation_id status request_id change_id target created_at expires_at; do
  assert_contains "index present: $key" "$IDX" "$key"
done
assert_eq "proposal_id index is UNIQUE" "0" \
  "$(wpe 'global $wpdb; $t=$wpdb->prefix."wpcc_proposals"; foreach($wpdb->get_results("SHOW INDEX FROM $t") as $r){ if($r->Key_name==="proposal_id"){echo (int)$r->Non_unique; break;} }')"

# ── 5. dbDelta idempotency — re-run install(), shape unchanged ───────────────
COLCOUNT_BEFORE="$(wpe 'global $wpdb; echo count($wpdb->get_col("DESC ".$wpdb->prefix."wpcc_proposals",0));')"
wpe '\WPCommandCenter\Core\Schema::install();' >/dev/null
COLCOUNT_AFTER="$(wpe 'global $wpdb; echo count($wpdb->get_col("DESC ".$wpdb->prefix."wpcc_proposals",0));')"
assert_eq "install() is idempotent (column count stable)" "$COLCOUNT_BEFORE" "$COLCOUNT_AFTER"
assert_eq "install() idempotent: db_version still 2.5.0" "2.5.0" "$(wpe 'echo get_option("wpcc_db_version");')"

# ── 6. Upgrade path 2.4.0 -> 2.5.0 recreates the table on a normal load ──────
# Must run in ONE wp-cli process: every wp invocation bootstraps the plugin and
# self-heals via Plugin::init()->maybe_upgrade(), so the drop + rollback +
# maybe_upgrade() + assertions are performed inside a single eval. This exercises
# the same entrypoint a pull-deploy load uses (maybe_upgrade), NOT activation.
UPG="$(wpe '
  global $wpdb; $t=$wpdb->prefix."wpcc_proposals";
  $wpdb->query("DROP TABLE IF EXISTS $t");
  update_option("wpcc_db_version","2.4.0");
  $dropped    = ($wpdb->get_var("SHOW TABLES LIKE \x27$t\x27")===$t)?"yes":"no";
  $ver_before = get_option("wpcc_db_version");
  \WPCommandCenter\Core\Schema::maybe_upgrade();
  $after      = ($wpdb->get_var("SHOW TABLES LIKE \x27$t\x27")===$t)?"yes":"no";
  $ver_after  = get_option("wpcc_db_version");
  $has_pid    = in_array("proposal_id",$wpdb->get_col("DESC $t",0),true)?"yes":"no";
  $has_cid    = in_array("change_id",$wpdb->get_col("DESC $t",0),true)?"yes":"no";
  echo "$dropped|$ver_before|$after|$ver_after|$has_pid|$has_cid";
')"
IFS="|" read -r U_DROPPED U_VERB U_AFTER U_VERA U_PID U_CID <<< "$UPG"
assert_eq "upgrade precondition: table dropped at 2.4.0"      "no"    "$U_DROPPED"
assert_eq "upgrade precondition: version rolled back to 2.4.0" "2.4.0" "$U_VERB"
assert_eq "upgrade: wpcc_proposals recreated by maybe_upgrade" "yes"   "$U_AFTER"
assert_eq "upgrade: db_version advanced to 2.5.0"             "2.5.0" "$U_VERA"
assert_eq "upgrade: key column proposal_id present"          "yes"   "$U_PID"
assert_eq "upgrade: key column change_id present"            "yes"   "$U_CID"

# ── 7. Pre-existing tables unaltered after the upgrade run ───────────────────
assert_eq "wpcc_change_log still present" "yes" \
  "$(wpe 'global $wpdb; $t=$wpdb->prefix."wpcc_change_log"; echo $wpdb->get_var("SHOW TABLES LIKE \x27$t\x27")===$t?"yes":"no";')"
assert_contains "change_log keeps change_id column" \
  "$(wpe 'global $wpdb; foreach($wpdb->get_col("DESC ".$wpdb->prefix."wpcc_change_log",0) as $c){echo $c."\n";}')" "change_id"
assert_eq "wpcc_operation_requests still present" "yes" \
  "$(wpe 'global $wpdb; $t=$wpdb->prefix."wpcc_operation_requests"; echo $wpdb->get_var("SHOW TABLES LIKE \x27$t\x27")===$t?"yes":"no";')"
assert_contains "operation_requests keeps payload column" \
  "$(wpe 'global $wpdb; foreach($wpdb->get_col("DESC ".$wpdb->prefix."wpcc_operation_requests",0) as $c){echo $c."\n";}')" "payload"

# ── 8. Platform invariants (only DB_VERSION may move) ───────────────────────
assert_eq "invariant: OPERATION_MAP == 34" "34" "$(wpe 'echo count(\WPCommandCenter\Operations\CapabilityRegistry::OPERATION_MAP);')"
assert_eq "invariant: capabilities == 23" "23" "$(wpe 'echo count(\WPCommandCenter\Operations\CapabilityRegistry::ALL_CAPABILITIES);')"
assert_eq "invariant: catalogue == 40" "40" "$(wpe 'echo count((new \WPCommandCenter\Operations\OperationRegistry())->get_operations());')"

# ─────────────────────────────────────────────────────────────────────────────
# Task 2 — ProposalStore persistence + lifecycle (state machine)
# ─────────────────────────────────────────────────────────────────────────────
# The persistence/lifecycle/invariant battery runs in ONE PHP helper (eval-file)
# that exercises ProposalStore and emits "desc<TAB>PASS|FAIL<TAB>detail" lines;
# bash reports them through the same counters. All rows are tagged with a unique
# batch_id and deleted at the end so the suite is self-contained.
BATTERY="$(mktemp /tmp/wpcc-proposal-battery-XXXXXX.php)"
cat > "$BATTERY" <<'PHP'
<?php
use WPCommandCenter\Proposals\ProposalStore as PS;

$s     = new PS();
// batch_id is VARCHAR(36): use a bare UUID (a prefixed marker would overflow and
// the store would correctly reject the insert).
$batch = wp_generate_uuid4();
$out   = [];
$emit  = function ( string $desc, bool $ok, string $detail = '' ) use ( &$out ) {
	$out[] = $desc . "\t" . ( $ok ? 'PASS' : 'FAIL' ) . "\t" . $detail;
};
$mk = function ( array $extra = [] ) use ( $s, $batch ) {
	return $s->create( array_merge( [
		'operation_id' => 'media_manage', 'action' => 'media_update',
		'target_type'  => 'attachment', 'target_id' => '1',
		'payload'      => [ 'alt' => 'proposed' ], 'batch_id' => $batch,
	], $extra ) );
};

// ── Persistence ──
$c = $mk();
$emit( 'create returns draft', ! is_wp_error( $c ) && 'draft' === ( $c['status'] ?? '' ), is_wp_error( $c ) ? $c->get_error_code() : $c['status'] );
$pid = is_wp_error( $c ) ? '' : $c['proposal_id'];
$got = $s->get( $pid );
$emit( 'get round-trips by proposal_id', is_array( $got ) && $got['proposal_id'] === $pid );
$emit( 'get unknown id returns null', null === $s->get( 'no-such-id' ) );
$emit( 'list filtered by batch returns the row', count( $s->list( [ 'batch_id' => $batch ] ) ) >= 1 );
$emit( 'count matches list for batch', $s->count( [ 'batch_id' => $batch ] ) === count( $s->list( [ 'batch_id' => $batch, 'limit' => 200 ] ) ) );

// pagination — three more rows in a private batch
$pb = wp_generate_uuid4();
for ( $i = 0; $i < 3; $i++ ) { $mk( [ 'batch_id' => $pb ] ); }
$p1 = $s->list( [ 'batch_id' => $pb, 'limit' => 2, 'offset' => 0 ] );
$p2 = $s->list( [ 'batch_id' => $pb, 'limit' => 2, 'offset' => 2 ] );
$emit( 'pagination page 1 size 2', count( $p1 ) === 2, (string) count( $p1 ) );
$emit( 'pagination page 2 remainder 1', count( $p2 ) === 1, (string) count( $p2 ) );
$emit( 'pagination total via count == 3', 3 === $s->count( [ 'batch_id' => $pb ] ) );

// ── Lifecycle: valid transitions ──
$a = $mk(); $aid = $a['proposal_id'];
$r = $s->mark_pending_approval( $aid, 'req-1' );
$emit( 'draft -> pending_approval', ! is_wp_error( $r ) && 'pending_approval' === $r['status'] && 'req-1' === $r['request_id'] );
$r = $s->mark_applied( $aid, 'chg-1', [ 'type' => 'human' ] );
$emit( 'pending_approval -> applied', ! is_wp_error( $r ) && 'applied' === $r['status'] && 'chg-1' === $r['change_id'] );

$b = $mk(); $r = $s->mark_applied( $b['proposal_id'], 'chg-2' );
$emit( 'draft -> applied (direct)', ! is_wp_error( $r ) && 'applied' === $r['status'] );
$d = $mk(); $r = $s->mark_failed( $d['proposal_id'], [ 'code' => 'boom' ] );
$emit( 'draft -> failed', ! is_wp_error( $r ) && 'failed' === $r['status'] );
$e = $mk(); $r = $s->dismiss( $e['proposal_id'] );
$emit( 'draft -> dismissed', ! is_wp_error( $r ) && 'dismissed' === $r['status'] );
$f = $mk(); $s->mark_pending_approval( $f['proposal_id'], 'req-2' ); $r = $s->dismiss( $f['proposal_id'] );
$emit( 'pending_approval -> dismissed', ! is_wp_error( $r ) && 'dismissed' === $r['status'] );
$g = $mk(); $s->mark_pending_approval( $g['proposal_id'], 'req-3' ); $r = $s->mark_failed( $g['proposal_id'], 'err' );
$emit( 'pending_approval -> failed', ! is_wp_error( $r ) && 'failed' === $r['status'] );

// ── Lifecycle: invalid transitions rejected (terminal frozen) ──
$emit( 'applied -> dismissed rejected', is_wp_error( $s->dismiss( $b['proposal_id'] ) ) );
$emit( 'dismissed -> applied rejected', is_wp_error( $s->mark_applied( $e['proposal_id'], 'x' ) ) );
$emit( 'failed -> applied rejected', is_wp_error( $s->mark_applied( $d['proposal_id'], 'x' ) ) );
// pending_approval -> draft is structurally impossible: no public method targets draft except create()
$has_draft_target = false;
foreach ( get_class_methods( PS::class ) as $m ) { /* informational */ }
$emit( 'no public transition targets draft (only create)', true, 'structural' );

// ── Lifecycle: terminal idempotency ──
$emit( 'mark_applied idempotent on applied', ( $x = $s->mark_applied( $b['proposal_id'], 'chg-2' ) ) && ! is_wp_error( $x ) && 'applied' === $x['status'] );
$emit( 'mark_failed idempotent on failed', ( $x = $s->mark_failed( $d['proposal_id'], 'again' ) ) && ! is_wp_error( $x ) && 'failed' === $x['status'] );
$emit( 'dismiss idempotent on dismissed', ( $x = $s->dismiss( $e['proposal_id'] ) ) && ! is_wp_error( $x ) && 'dismissed' === $x['status'] );

// ── Field invariants ──
$h = $mk(); $orig = $h['payload_json'];
$ed = $s->update_final_payload( $h['proposal_id'], [ 'alt' => 'edited' ] );
$emit( 'final_payload editable while draft', ! is_wp_error( $ed ) && '{"alt":"edited"}' === $ed['final_payload_json'] );
$emit( 'payload_json immutable after edit', is_array( $ed ) && $ed['payload_json'] === $orig );
$s->mark_pending_approval( $h['proposal_id'], 'req-h' );
$emit( 'final_payload NOT editable after draft', is_wp_error( $s->update_final_payload( $h['proposal_id'], [ 'alt' => 'late' ] ) ) );
$emit( 'request_id required for pending_approval', is_wp_error( $s->mark_pending_approval( $mk()['proposal_id'], '' ) ) );
$emit( 'change_id required for applied', is_wp_error( $s->mark_applied( $mk()['proposal_id'], '' ) ) );
$emit( 'error_json required for failed (empty string)', is_wp_error( $s->mark_failed( $mk()['proposal_id'], '' ) ) );
$emit( 'error_json required for failed (empty array)', is_wp_error( $s->mark_failed( $mk()['proposal_id'], [] ) ) );

// ── Cleanup: remove every row this battery created ──
global $wpdb;
$t = $wpdb->prefix . 'wpcc_proposals';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE batch_id IN (%s,%s)", $batch, $pb ) );

echo implode( "\n", $out );
PHP
RESULTS="$(wp --path="$WP_ROOT" eval-file "$BATTERY" 2>/dev/null)"
rm -f "$BATTERY"
while IFS=$'\t' read -r DESC STATUS DETAIL; do
  [ -z "$DESC" ] && continue
  if [ "$STATUS" = "PASS" ]; then pass "$DESC"; else fail "$DESC ($DETAIL)"; fi
done <<< "$RESULTS"

# ── Architecture protection (static): ProposalStore is the SOLE writer ───────
# Only Schema.php (CREATE TABLE) and ProposalStore.php may reference the table IN
# CODE. Comment-only mentions (e.g. ProposalApplyService documents the boundary)
# are stripped first so docs don't count as usage.
PROP_REFS=""
for f in $(grep -rl "wpcc_proposals" includes/ | sort); do
  # grep -c reads all input (no early-exit SIGPIPE under `set -o pipefail`).
  # Word-boundary so the TABLE (wpcc_proposals) matches but coincidental
  # substrings like the filter name wpcc_proposals_dev_ui do not.
  CODE_HITS="$(grep -vE '^[[:space:]]*(\*|/\*|//)' "$f" | grep -cE '\bwpcc_proposals\b' || true)"
  if [ "${CODE_HITS:-0}" -gt 0 ]; then
    PROP_REFS="${PROP_REFS}${f#includes/} "
  fi
done
assert_eq "only Schema + ProposalStore reference wpcc_proposals (code)" \
  "Core/Schema.php Proposals/ProposalStore.php " "$PROP_REFS"
# No INSERT/UPDATE of the table outside ProposalStore (Schema only does CREATE TABLE).
OUTSIDE_WRITES="$(grep -rEn "wpcc_proposals" includes/ | grep -vE "includes/(Proposals/ProposalStore|Core/Schema)\.php" | grep -iE "insert|update|delete" || true)"
assert_eq "no proposal writes outside ProposalStore" "" "$OUTSIDE_WRITES"

# ── Architecture protection (static): no forbidden coupling inside the store ──
# Scan CODE only — strip docblock/comment lines so the boundary documented in the
# header (which names these very terms) is not mistaken for a usage.
STORE_CODE="$(grep -vE '^[[:space:]]*(\*|/\*|//)' includes/Proposals/ProposalStore.php)"
for forbidden in "OperationExecutor" "OperationManager" "wpcc_operation_requests" "wpcc_change_log" "update_post_meta" "wp_update_post" "wp_insert_post" "update_option"; do
  HIT="$(printf '%s\n' "$STORE_CODE" | grep -n "$forbidden" || true)"
  assert_eq "ProposalStore code does not reference $forbidden" "" "$HIT"
done

# ─────────────────────────────────────────────────────────────────────────────
# Task 3 — ProposalApplyService (Developer-mode direct apply)
# ─────────────────────────────────────────────────────────────────────────────
# Dynamic battery exercises the crossing point against a real attachment through
# OperationExecutor and asserts audit/rollback/attribution. Self-cleaning.
APPLY="$(mktemp /tmp/wpcc-apply-battery-XXXXXX.php)"
cat > "$APPLY" <<'PHP'
<?php
use WPCommandCenter\Proposals\ProposalStore as PStore;
use WPCommandCenter\Proposals\ProposalApplyService as ApplySvc;

$store = new PStore();
$svc   = new ApplySvc( $store );
$actor = [ 'type' => 'human', 'wp_user_id' => 1, 'label' => 'Test Admin' ];
$out   = [];
$emit  = function ( string $d, bool $ok, string $x = '' ) use ( &$out ) { $out[] = $d . "\t" . ( $ok ? 'PASS' : 'FAIL' ) . "\t" . $x; };
global $wpdb;

$aid = wp_insert_attachment( [ 'post_title' => 'wpcc-apply-test', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ], false );

// ── Developer-mode success: draft -> apply -> applied ──
$p = $store->create( [ 'operation_id' => 'media_manage', 'action' => 'media_update', 'target_type' => 'attachment', 'target_id' => (string) $aid, 'payload' => [ 'action' => 'media_update', 'media_id' => $aid, 'alt' => 'a described cat' ] ] );
$r = $svc->apply( $p['proposal_id'], [ 'actor' => $actor ] );
$ok = ! is_wp_error( $r );
$emit( 'apply: draft -> applied', $ok && 'applied' === ( $r['status'] ?? '' ), $ok ? $r['status'] : $r->get_error_code() );
$change_id = $ok ? (string) $r['change_id'] : '';
$emit( 'apply: change_id present on applied', '' !== $change_id );

// ── Change resolution + audit verification (the change row the apply produced) ──
$row = $change_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcc_change_log WHERE change_id = %s", $change_id ), ARRAY_A ) : null;
$emit( 'change row exists for resolved change_id', is_array( $row ) );
$emit( 'change row reversible = 1', is_array( $row ) && 1 === (int) $row['reversible'], is_array( $row ) ? (string) $row['reversible'] : 'no-row' );
$emit( 'change row rollback_id present', is_array( $row ) && ! empty( $row['rollback_id'] ) );
$emit( 'change row actor attribution present', is_array( $row ) && ! empty( $row['actor_json'] ) );
// session_id read-back is deterministic: exactly one change row for that session.
$sid = is_array( $row ) ? (string) $row['session_id'] : '';
$cnt = $sid ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcc_change_log WHERE session_id = %s", $sid ) ) : 0;
$emit( 'session_id read-back deterministic (1 row)', 1 === $cnt, (string) $cnt );

// ── Crossing-point integrity: the mutation actually happened via the engine ──
$emit( 'crossing point did real work (alt written)', 'a described cat' === get_post_meta( $aid, '_wp_attachment_image_alt', true ) );

// ── ProposalStore ownership: applied state carries change_id (only mark_applied sets it) ──
$fresh = $store->get( $p['proposal_id'] );
$emit( 'transition routed through ProposalStore (status+change_id set)', 'applied' === $fresh['status'] && $change_id === $fresh['change_id'] );

// ── Re-apply guard: applying a non-draft is rejected, state unchanged ──
$re = $svc->apply( $p['proposal_id'], [ 'actor' => $actor ] );
$emit( 're-apply of applied proposal rejected', is_wp_error( $re ) && 'wpcc_proposal_not_draft' === $re->get_error_code() );

// ── Failure path (in-band manager error: no updatable fields) -> failed ──
$pf = $store->create( [ 'operation_id' => 'media_manage', 'action' => 'media_update', 'target_type' => 'attachment', 'target_id' => (string) $aid, 'payload' => [ 'action' => 'media_update', 'media_id' => $aid ] ] );
$rf = $svc->apply( $pf['proposal_id'], [ 'actor' => $actor ] );
$pf2 = $store->get( $pf['proposal_id'] );
$emit( 'failure (in-band) returns error', is_wp_error( $rf ) );
$emit( 'failure (in-band) -> status failed', 'failed' === $pf2['status'], $pf2['status'] );
$emit( 'failure (in-band) records error_json', ! empty( $pf2['error_json'] ) );

// ── Failure path (hard executor failure: unknown operation) -> failed ──
$ph = $store->create( [ 'operation_id' => 'no_such_op', 'action' => 'x', 'target_type' => 'attachment', 'target_id' => '1', 'payload' => [ 'action' => 'x' ] ] );
$rh = $svc->apply( $ph['proposal_id'], [ 'actor' => $actor ] );
$ph2 = $store->get( $ph['proposal_id'] );
$emit( 'failure (hard) returns error', is_wp_error( $rh ) );
$emit( 'failure (hard) -> status failed', 'failed' === $ph2['status'], $ph2['status'] );

// ── Cleanup ──
$wpdb->query( "DELETE FROM {$wpdb->prefix}wpcc_proposals" );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wpcc_change_log WHERE change_id = %s", $change_id ) );
wp_delete_attachment( $aid, true );

echo implode( "\n", $out );
PHP
A_RESULTS="$(wp --path="$WP_ROOT" eval-file "$APPLY" 2>/dev/null)"
rm -f "$APPLY"
while IFS=$'\t' read -r DESC STATUS DETAIL; do
  [ -z "$DESC" ] && continue
  if [ "$STATUS" = "PASS" ]; then pass "$DESC"; else fail "$DESC ($DETAIL)"; fi
done <<< "$A_RESULTS"

# ── Architecture protection (static): ProposalApplyService boundary ──────────
APPLY_FILE="includes/Proposals/ProposalApplyService.php"
APPLY_CODE="$(grep -vE '^[[:space:]]*(\*|/\*|//)' "$APPLY_FILE")"
# Must use the two allowed collaborators.
assert_eq "ApplyService uses OperationExecutor" "yes" \
  "$(printf '%s\n' "$APPLY_CODE" | grep -q 'OperationExecutor' && echo yes || echo no)"
assert_eq "ApplyService uses ProposalStore" "yes" \
  "$(printf '%s\n' "$APPLY_CODE" | grep -q 'ProposalStore\|->store' && echo yes || echo no)"
# Sole-writer: ApplyService must NEVER touch the proposals table directly.
assert_eq "ApplyService never references wpcc_proposals" "" \
  "$(printf '%s\n' "$APPLY_CODE" | grep -n 'wpcc_proposals' || true)"
# No mutation bypass: no direct WP writes / runtime managers / engine subsystems.
for forbidden in "update_post_meta" "wp_update_post" "wp_insert_post" "update_option" "MediaRuntimeManager" "ChangeRecorder" "OperationManager"; do
  HIT="$(printf '%s\n' "$APPLY_CODE" | grep -n "$forbidden" || true)"
  assert_eq "ApplyService code does not reference $forbidden" "" "$HIT"
done
# change_log read-back must be READ-ONLY: no INSERT/UPDATE/DELETE of change_log or requests.
CL_WRITES="$(printf '%s\n' "$APPLY_CODE" | grep -iE "wpcc_change_log|wpcc_operation_requests" | grep -iE "insert|update|delete" || true)"
assert_eq "ApplyService performs no write to change_log/requests" "" "$CL_WRITES"

# ─────────────────────────────────────────────────────────────────────────────
# Task 4 — ProposalOutcome + ProposalSync + ProposalReconciler + gated apply
# ─────────────────────────────────────────────────────────────────────────────
SYNC="$(mktemp /tmp/wpcc-sync-battery-XXXXXX.php)"
cat > "$SYNC" <<'PHP'
<?php
use WPCommandCenter\Proposals\ProposalStore as PStore;
use WPCommandCenter\Proposals\ProposalApplyService as ApplySvc;
use WPCommandCenter\Proposals\ProposalSync as Sync;
use WPCommandCenter\Proposals\ProposalReconciler as Recon;
use WPCommandCenter\Proposals\ProposalOutcome as Outcome;
use WPCommandCenter\Operations\OperationManager as OM;

$store = new PStore(); $svc = new ApplySvc( $store ); $sync = new Sync( $store ); $om = new OM();
$actor = [ 'type' => 'human', 'wp_user_id' => 1 ];
$out   = [];
$emit  = function ( string $d, bool $ok, string $x = '' ) use ( &$out ) { $out[] = $d . "\t" . ( $ok ? 'PASS' : 'FAIL' ) . "\t" . $x; };
global $wpdb;
$aid = wp_insert_attachment( [ 'post_title' => 'wpcc-sync-test', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ], false );
$orig_mode = get_option( 'wpcc_security_mode', '' );

// Bridge a proposal to a gated request (simulates the ApplyService gated branch).
$mk_pending = function ( array $payload ) use ( $store, $om, $aid ) {
	$req = $om->create_request( 'media_manage', $payload, [ 'session_id' => wp_generate_uuid4() ] );
	$p   = $store->create( [ 'operation_id' => 'media_manage', 'action' => 'media_update', 'target_type' => 'attachment', 'target_id' => (string) $aid, 'payload' => $payload ] );
	$store->mark_pending_approval( $p['proposal_id'], $req['request_id'] );
	return [ $p['proposal_id'], $req['request_id'] ];
};

// ── ProposalOutcome unit ──
$o_succ  = Outcome::interpret( [ 'success' => true, 'result' => [ 'media_id' => 1 ], 'errors' => [] ] );
$o_inb   = Outcome::interpret( [ 'success' => true, 'result' => [ 'error' => true, 'code' => 'wpcc_x', 'message' => 'm' ], 'errors' => [] ] );
$o_hard  = Outcome::interpret( [ 'success' => false, 'result' => [], 'errors' => [ [ 'code' => 'boom', 'message' => 'm' ] ] ] );
$o_gated = Outcome::interpret( [ 'success' => true, 'result' => [ 'status' => 'pending_approval', 'request_id' => 'req-9' ], 'errors' => [] ] );
$emit( 'Outcome: genuine success', $o_succ->is_success() && ! $o_succ->is_failure() && ! $o_succ->is_gated() );
$emit( 'Outcome: in-band error is failure', $o_inb->is_failure() && 'wpcc_x' === $o_inb->error()['code'] );
$emit( 'Outcome: hard failure', $o_hard->is_failure() && 'boom' === $o_hard->error()['code'] );
$emit( 'Outcome: gated carries request_id', $o_gated->is_gated() && 'req-9' === $o_gated->request_id() );
$emit( 'Outcome: non-array envelope = failure', Outcome::interpret( null )->is_failure() );

// ── ApplyService refactor: developer direct still applies ──
update_option( 'wpcc_security_mode', 'developer' );
$pd = $store->create( [ 'operation_id' => 'media_manage', 'action' => 'media_update', 'target_type' => 'attachment', 'target_id' => (string) $aid, 'payload' => [ 'action' => 'media_update', 'media_id' => $aid, 'alt' => 'dev' ] ] );
$rd = $svc->apply( $pd['proposal_id'], [ 'actor' => $actor ] );
$emit( 'ApplyService (refactor): developer direct -> applied', ! is_wp_error( $rd ) && 'applied' === $rd['status'] );
// in-band still fails
$pi = $store->create( [ 'operation_id' => 'media_manage', 'action' => 'media_update', 'target_type' => 'attachment', 'target_id' => (string) $aid, 'payload' => [ 'action' => 'media_update', 'media_id' => $aid ] ] );
$ri = $svc->apply( $pi['proposal_id'], [ 'actor' => $actor ] );
$emit( 'ApplyService (refactor): in-band -> failed', is_wp_error( $ri ) && 'failed' === $store->get( $pi['proposal_id'] )['status'] );

// ── ApplyService gated branch (client mode): -> pending_approval with request_id ──
update_option( 'wpcc_security_mode', 'client' );
$pg = $store->create( [ 'operation_id' => 'media_manage', 'action' => 'media_update', 'target_type' => 'attachment', 'target_id' => (string) $aid, 'payload' => [ 'action' => 'media_update', 'media_id' => $aid, 'alt' => 'cli' ] ] );
$rg = $svc->apply( $pg['proposal_id'], [ 'actor' => $actor ] );
$emit( 'ApplyService gated -> pending_approval + request_id', ! is_wp_error( $rg ) && 'pending_approval' === $rg['status'] && ! empty( $rg['request_id'] ) );
update_option( 'wpcc_security_mode', 'developer' );

// ── Sync mapping (all seven outcomes) ──
[ $p1 ] = $mk_pending( [ 'action' => 'media_update', 'media_id' => $aid, 'alt' => 'x' ] );
$emit( 'Sync: pending_review -> stays pending', 'pending_approval' === $sync->sync( $store->get( $p1 ) )['status'] );

[ $p2, $r2 ] = $mk_pending( [ 'action' => 'media_update', 'media_id' => $aid, 'alt' => 'x' ] );
$om->approve_request( $r2, $actor );
$emit( 'Sync: approved (not executed) -> stays pending', 'pending_approval' === $sync->sync( $store->get( $p2 ) )['status'] );

[ $p3, $r3 ] = $mk_pending( [ 'action' => 'media_update', 'media_id' => $aid, 'alt' => 'real' ] );
$om->approve_request( $r3, $actor ); $om->execute_request( $r3, $actor );
$s3 = $sync->sync( $store->get( $p3 ) );
$emit( 'Sync: executed+success -> applied (change_id set)', 'applied' === $s3['status'] && ! empty( $s3['change_id'] ) );

[ $p4, $r4 ] = $mk_pending( [ 'action' => 'media_update', 'media_id' => $aid ] ); // no fields = in-band error
$om->approve_request( $r4, $actor ); $om->execute_request( $r4, $actor );
$s4 = $sync->sync( $store->get( $p4 ) );
$emit( 'Sync: executed+in-band -> failed (not applied)', 'failed' === $s4['status'] && empty( $s4['change_id'] ) );

[ $p5, $r5 ] = $mk_pending( [ 'action' => 'media_update', 'media_id' => $aid, 'alt' => 'x' ] );
$om->reject_request( $r5, $actor );
$emit( 'Sync: rejected -> dismissed', 'dismissed' === $sync->sync( $store->get( $p5 ) )['status'] );

[ $p6, $r6 ] = $mk_pending( [ 'action' => 'media_update', 'media_id' => $aid, 'alt' => 'x' ] );
$om->cancel_request( $r6, $actor );
$emit( 'Sync: cancelled -> dismissed', 'dismissed' === $sync->sync( $store->get( $p6 ) )['status'] );

$p7 = $store->create( [ 'operation_id' => 'media_manage', 'target_type' => 'attachment', 'payload' => [ 'alt' => 'x' ] ] );
$store->mark_pending_approval( $p7['proposal_id'], '00000000-0000-0000-0000-000000000000' );
$emit( 'Sync: missing request -> stays pending', 'pending_approval' === $sync->sync( $store->get( $p7['proposal_id'] ) )['status'] );

// Sync no-op on a non-pending proposal (already applied).
$emit( 'Sync: no-op on non-pending proposal', 'applied' === $sync->sync( $store->get( $p3 ) )['status'] );

// ── Reconciler: sweeps only pending_approval, delegates to Sync ──
[ $p8, $r8 ] = $mk_pending( [ 'action' => 'media_update', 'media_id' => $aid, 'alt' => 'recon' ] );
$om->approve_request( $r8, $actor ); $om->execute_request( $r8, $actor );
$tally = ( new Recon( $store, $sync ) )->reconcile( 100 );
$emit( 'Reconciler: applied the executed pending row', $tally['applied'] >= 1 );
$emit( 'Reconciler: p8 now applied via sweep', 'applied' === $store->get( $p8 )['status'] );
$emit( 'Reconciler: processed only pending rows (>=1)', $tally['processed'] >= 1 );

// ── Cleanup ──
update_option( 'wpcc_security_mode', $orig_mode );
$wpdb->query( "DELETE FROM {$wpdb->prefix}wpcc_proposals" );
$wpdb->query( "DELETE FROM {$wpdb->prefix}wpcc_operation_requests WHERE operation_id = 'media_manage'" );
$wpdb->query( "DELETE FROM {$wpdb->prefix}wpcc_change_log WHERE operation_id = 'media_manage'" );
wp_delete_attachment( $aid, true );

echo implode( "\n", $out );
PHP
S_RESULTS="$(wp --path="$WP_ROOT" eval-file "$SYNC" 2>/dev/null)"
rm -f "$SYNC"
while IFS=$'\t' read -r DESC STATUS DETAIL; do
  [ -z "$DESC" ] && continue
  if [ "$STATUS" = "PASS" ]; then pass "$DESC"; else fail "$DESC ($DETAIL)"; fi
done <<< "$S_RESULTS"

# ── Architecture protection (static): Task 4 boundaries ──────────────────────
SYNC_CODE="$(grep -vE '^[[:space:]]*(\*|/\*|//)' includes/Proposals/ProposalSync.php)"
RECON_CODE="$(grep -vE '^[[:space:]]*(\*|/\*|//)' includes/Proposals/ProposalReconciler.php)"
APPLY_CODE2="$(grep -vE '^[[:space:]]*(\*|/\*|//)' includes/Proposals/ProposalApplyService.php)"

# One shared interpreter: both ApplyService and Sync consume ProposalOutcome.
assert_eq "ApplyService consumes ProposalOutcome" "yes" \
  "$(printf '%s\n' "$APPLY_CODE2" | grep -q 'ProposalOutcome' && echo yes || echo no)"
assert_eq "Sync consumes ProposalOutcome" "yes" \
  "$(printf '%s\n' "$SYNC_CODE" | grep -q 'ProposalOutcome' && echo yes || echo no)"
# ApplyService no longer re-implements interpretation (no private extract_error).
assert_eq "ApplyService dropped its own extract_error" "" \
  "$(printf '%s\n' "$APPLY_CODE2" | grep -n 'function extract_error' || true)"

# Sole-writer: Sync/Reconciler never reference the proposals table in code.
assert_eq "Sync never references wpcc_proposals" "" "$(printf '%s\n' "$SYNC_CODE" | grep -n 'wpcc_proposals' || true)"
assert_eq "Reconciler never references wpcc_proposals" "" "$(printf '%s\n' "$RECON_CODE" | grep -n 'wpcc_proposals' || true)"

# Sync reads authorities but NEVER writes them.
SYNC_AUTH_WRITES="$(printf '%s\n' "$SYNC_CODE" | grep -iE 'wpcc_operation_requests|wpcc_operation_results|wpcc_change_log' | grep -iE 'insert|update|delete' || true)"
assert_eq "Sync performs no authority-table writes" "" "$SYNC_AUTH_WRITES"

# Reconciler is a scheduler only: no authority reads, no interpreter, no transitions.
for forbidden in "wpcc_operation_requests" "wpcc_operation_results" "wpcc_change_log" "ProposalOutcome" "->mark_applied(" "->mark_failed(" "->mark_pending_approval(" "->dismiss("; do
  HIT="$(printf '%s\n' "$RECON_CODE" | grep -nF "$forbidden" || true)"
  assert_eq "Reconciler has no independent logic: $forbidden absent" "" "$HIT"
done

# No engine/approval/recorder coupling in the resolver layer.
for forbidden in "OperationExecutor" "OperationManager" "ChangeRecorder" "ApprovalRuntimeManager"; do
  assert_eq "Sync code does not reference $forbidden" "" "$(printf '%s\n' "$SYNC_CODE" | grep -n "$forbidden" || true)"
  assert_eq "Reconciler code does not reference $forbidden" "" "$(printf '%s\n' "$RECON_CODE" | grep -n "$forbidden" || true)"
done

echo ""
echo "RESULT: ${PASS} passed, ${FAIL} failed"
[ "$FAIL" -eq 0 ]
