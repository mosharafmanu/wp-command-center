<?php
/** Behavioral C regression; use the isolated test site, never certification history. */
use WPCommandCenter\Operations\OperationExecutor;
use WPCommandCenter\Operations\OperationManager;
use WPCommandCenter\Operations\OperationResults;
use WPCommandCenter\Operations\ChangeHistoryRuntimeManager;
use WPCommandCenter\Operations\CapabilityRegistry;
use WPCommandCenter\Security\AuthTokens;

global $wpdb;
$passed = 0; $failed = 0; $began = microtime( true );
$check = static function ( $condition, string $label ) use ( &$passed, &$failed ): void {
    if ( $condition ) { ++$passed; echo "  PASS: {$label}\n"; }
    else { ++$failed; echo "  FAIL: {$label}\n"; }
};
$need = static function ( $value, string $label ) use ( $check ) {
    $ok = ! is_wp_error( $value ) && ! empty( $value );
    $check( $ok, $label );
    if ( ! $ok ) { throw new RuntimeException( $label ); }
    return $value;
};
$mode = get_option( 'wpcc_security_mode', null );
$tagline = get_option( 'blogdescription' );
$run = wp_generate_uuid4();
$base = rtrim( (string) ( $args[0] ?? rest_url( 'wp-command-center/v1' ) ), '/' );
$token_id = ''; $raw = ''; $post_id = 0; $patch_id = '';
$dir = WP_PLUGIN_DIR . '/' . $run;
$relative = 'plugins/' . $run . '/fixture.php';
$results = []; $requests = []; $changes = [];
// Existing history is immutable, including the real certification write/undo pair.
$historical_limit = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$wpdb->prefix}wpcc_change_log" );
$history_fingerprint = static fn() => hash( 'sha256', wp_json_encode( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcc_change_log WHERE id <= %d ORDER BY id", $historical_limit ), ARRAY_A ) ) );
$historical_before = $history_fingerprint();
$rollback_options = [];
foreach ( [ 'wpcc_option_rollbacks', 'wpcc_content_rollbacks', 'wpcc_settings_rollbacks' ] as $option ) {
    $rollback_options[$option] = get_option( $option, null );
}
$actor = []; $ctx = [ 'session_id' => $run ];
$clear = static function (): void { wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'blogdescription', 'options' ); };
// Track exact result IDs produced by our sequential calls, including diagnostic results.
$invoke = static function ( callable $call ) use ( $wpdb, &$results ) {
    $before = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$wpdb->prefix}wpcc_operation_results" );
    try { return $call(); }
    finally {
        foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT result_id FROM {$wpdb->prefix}wpcc_operation_results WHERE id > %d", $before ) ) as $id ) { $results[$id] = true; }
    }
};
$http = static function ( array $body, ?string $credential = null ) use ( $base, &$raw, $invoke ) {
    return $invoke( static function () use ( $base, $body, $credential, &$raw ) {
        $headers = [ 'Content-Type' => 'application/json' ];
        if ( null !== $credential ) { $headers['Authorization'] = 'Bearer ' . $credential; }
        $response = wp_remote_post( $base . '/mcp', [ 'headers' => $headers, 'body' => wp_json_encode( $body ), 'timeout' => 45 ] );
        if ( is_wp_error( $response ) ) { throw new RuntimeException( 'MCP transport unavailable: ' . $response->get_error_code() ); }
        $text = wp_remote_retrieve_body( $response );
        if ( '' !== $raw && str_contains( $text, $raw ) ) { throw new RuntimeException( 'Credential leaked in MCP response' ); }
        return [ 'status' => wp_remote_retrieve_response_code( $response ), 'body' => json_decode( $text, true ) ?: [] ];
    } );
};
$mcp = static function ( string $operation, array $payload ) use ( $http, &$raw ) {
    $r = $http( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => $operation, 'arguments' => $payload ] ], $raw );
    return json_decode( $r['body']['result']['content'][0]['text'] ?? '{}', true ) ?: $r['body'];
};
$exec = static function ( string $operation, array $payload, array $extra = [] ) use ( $invoke, &$ctx ) {
    return $invoke( static fn() => ( new OperationExecutor() )->run( $operation, $payload, array_merge( $ctx, $extra ) ) );
};
$row = static function ( string $request_id = '', string $operation = '' ) use ( $wpdb, $run, &$changes ): array {
    if ( '' !== $request_id ) { $sql = $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcc_change_log WHERE request_id=%s ORDER BY id DESC LIMIT 1", $request_id ); }
    else { $sql = $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcc_change_log WHERE session_id=%s AND operation_id=%s ORDER BY id DESC LIMIT 1", $run, $operation ); }
    $r = $wpdb->get_row( $sql, ARRAY_A ) ?: [];
    if ( ! empty( $r['change_id'] ) ) { $changes[$r['change_id']] = true; }
    return $r;
};
$verify_pair = static function ( array $original, array $undo, string $label ) use ( $check, $need, $wpdb, &$changes, $actor ) {
    $reverse_id = $undo['rolled_back_by'] ?? $undo['result']['rolled_back_by'] ?? '';
    $reverse = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcc_change_log WHERE change_id=%s", $reverse_id ), ARRAY_A ) ?: [];
    $need( $reverse, "$label: persisted reversal exists" ); $changes[$reverse_id] = true;
    $original_now = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcc_change_log WHERE change_id=%s", $original['change_id'] ), ARRAY_A );
    $actual = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcc_operation_results WHERE operation_id='change_history' AND result_json LIKE %s ORDER BY id DESC LIMIT 1", '%' . $wpdb->esc_like( $reverse_id ) . '%' ), ARRAY_A ) ?: [];
    $need( $actual, "$label: actual undo execution result exists" );
    $check( ! empty( $original['result_ref'] ), "$label: original has own result" );
    $check( $reverse['result_ref'] === $actual['result_id'], "$label: reversal references actual undo execution" );
    $check( $reverse['result_ref'] !== $original['result_ref'], "$label: original result is never reused" );
    $check( $original_now['result_ref'] === $original['result_ref'], "$label: original result reference preserved" );
    $check( 'rolled_back' === $original_now['status'] && (int) $original_now['rolled_back_at'] > 0 && $original_now['rolled_back_by_change_id'] === $reverse_id, "$label: original rollback linkage and timestamp" );
    $summary = json_decode( $reverse['target_summary'], true );
    $check( ( $summary['reverts_change_id'] ?? '' ) === $original['change_id'], "$label: reversal links back to original" );
    $check( 0 === (int) $reverse['reversible'] && 'none' === $reverse['rollback_kind'], "$label: reversal is not itself reversible" );
    $detail = ( new ChangeHistoryRuntimeManager() )->run( [ 'action' => 'history_get', 'change_id' => $reverse_id ] );
    $meta = $detail['change']['result'] ?? [];
    $check( ( $meta['result_id'] ?? '' ) === $actual['result_id'], "$label: detail resolves actual undo result" );
    $check( (int) ( $meta['created_at'] ?? -1 ) === (int) $actual['created_at'], "$label: detail uses undo timestamp" );
    $check( (int) ( $meta['execution_time_ms'] ?? -1 ) === (int) $actual['execution_time_ms'], "$label: detail uses undo duration" );
    echo '  EVIDENCE: ' . wp_json_encode( [ 'kind' => $label, 'original_change' => $original['change_id'], 'original_result' => $original['result_ref'], 'reversal_change' => $reverse_id, 'reversal_result' => $reverse['result_ref'], 'actual_undo_result' => $actual['result_id'] ] ) . "\n";
    return $reverse;
};
try {
    $admins = get_users( [ 'role' => 'administrator', 'number' => 1 ] );
    $need( $admins, 'human administrator fixture available' );
    $uid = (int) $admins[0]->ID; wp_set_current_user( $uid );
    $actor = [ 'type' => 'admin', 'wp_user_id' => $uid, 'user_id' => $uid, 'label' => 'ABC regression human' ];
    $ctx['actor'] = $actor; $ctx['source'] = 'admin_ui';
    update_option( 'wpcc_security_mode', 'client' );
    $minted = $need( ( new AuthTokens() )->create( $run, 'full', null, $uid ), 'temporary full token created' );
    $raw = $minted['token']; $token_id = $minted['record']['id'];
    $check( in_array( 'system.admin', ( new CapabilityRegistry() )->get_for_subject( 'token', $token_id ), true ), 'full token has system.admin; human boundary is exercised' );
    $list_request = [ 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list' ];
    $noauth = $http( $list_request );
    $check( in_array( $noauth['status'], [ 401, 403 ], true ), 'MCP rejects unauthenticated access' );
    $invalid = $http( $list_request, 'invalid-certification-fixture' );
    $check( in_array( $invalid['status'], [ 401, 403 ], true ), 'MCP rejects invalid token' );
    $tools = $http( $list_request, $raw );
    $check( 42 === count( $tools['body']['result']['tools'] ?? [] ), 'MCP tools remain exactly 42' );
    $pending = $mcp( 'settings_manage', [ 'action' => 'settings_general_update', 'tagline' => $run, 'session_id' => $run ] );
    if ( empty( $pending['request_id'] ) ) { echo '  DIAGNOSTIC: ' . wp_json_encode( $pending ) . "\n"; }
    $rid = $need( $pending['request_id'] ?? '', 'settings write creates governed request' ); $requests[$rid] = true;
    $check( 'pending_approval' === ( $pending['status'] ?? '' ), 'system.admin settings write waits for approval' );
    $clear(); $check( get_option( 'blogdescription' ) === $tagline, 'tagline unchanged before human approval' );
    $check( [] === $row( $rid ), 'no pre-approval change record' );
    $deny = $mcp( 'approval_manage', [ 'action' => 'request_approve', 'request_id' => $rid ] );
    $check( 'wpcc_approval_requires_human' === ( $deny['code'] ?? '' ), 'MCP cannot self-approve original write' );
    $manager = new OperationManager();
    $before = $manager->execute_request( $rid, $actor );
    $check( is_wp_error( $before ) && 'wpcc_request_not_approved' === $before->get_error_code(), 'execution API rejects unapproved request' );
    $need( $manager->approve_request( $rid, [ 'actor' => $actor ] ), 'human approves original write' );
    $done = $invoke( static fn() => $manager->execute_request( $rid, $actor ) );
    $check( ! is_wp_error( $done ) && ! empty( $done['success'] ), 'human approval executes original write' );
    $clear(); $check( get_option( 'blogdescription' ) === $run, 'approved settings value applied' );
    $original = $need( $row( $rid ), 'original settings change persisted' );
    $check( ( json_decode( $original['actor_json'], true )['wp_user_id'] ?? 0 ) === $uid, 'original change attributed to human administrator' );
    // Distinct fixture metadata makes an accidental join to the original fail deterministically.
    $wpdb->update( $wpdb->prefix . 'wpcc_operation_results', [ 'created_at' => 946684800, 'execution_time_ms' => 987654 ], [ 'result_id' => $original['result_ref'] ] );
    $duplicate = $manager->execute_request( $rid, $actor );
    $check( is_wp_error( $duplicate ), 'human execution cannot apply approved write twice' );
    $check( 1 === count( ( new OperationResults() )->list_results( [ 'request_id' => $rid ] ) ), 'original request has exactly one execution result' );
    $pending_undo = $mcp( 'change_history', [ 'action' => 'rollback_target', 'change_id' => $original['change_id'], 'session_id' => $run ] );
    $undo_rid = $need( $pending_undo['request_id'] ?? '', 'undo creates separate governed request' ); $requests[$undo_rid] = true;
    $check( $undo_rid !== $rid && 'pending_approval' === ( $pending_undo['status'] ?? '' ), 'undo approval request is independent' );
    $clear(); $check( get_option( 'blogdescription' ) === $run && [] === $row( $undo_rid ), 'undo changes nothing before approval' );
    $deny = $mcp( 'approval_manage', [ 'action' => 'request_approve', 'request_id' => $undo_rid ] );
    $check( 'wpcc_approval_requires_human' === ( $deny['code'] ?? '' ), 'MCP cannot self-approve undo' );
    $need( $manager->approve_request( $undo_rid, [ 'actor' => $actor ] ), 'human approves undo' );
    $undo = $invoke( static fn() => $manager->execute_request( $undo_rid, $actor ) );
    $need( ! is_wp_error( $undo ) && ! empty( $undo['success'] ), 'human executes undo successfully' );
    $reverse = $verify_pair( $original, $undo, 'settings' );
    $check( $reverse['request_id'] === $undo_rid, 'reversal links to undo approval request' );
    $check( ( json_decode( $reverse['actor_json'], true )['wp_user_id'] ?? 0 ) === $uid, 'reversal attributed to human administrator' );
    $audit_entries = ( new \WPCommandCenter\Security\AuditLog() )->tail( 200 );
    foreach ( [ $rid => 'original', $undo_rid => 'undo' ] as $audit_rid => $label ) {
        $matching = array_filter( $audit_entries, static fn( $entry ) => 'operation.execution.completed' === ( $entry['action'] ?? '' ) && $audit_rid === ( $entry['context']['request_id'] ?? '' ) && $uid === ( $entry['context']['actor']['wp_user_id'] ?? 0 ) );
        $check( 1 === count( $matching ), "$label: durable execution audit attributes the human actor" );
    }
    $rollback_audit = array_filter( $audit_entries, static fn( $entry ) => 'change.rolled_back' === ( $entry['action'] ?? '' ) && $original['change_id'] === ( $entry['context']['change_id'] ?? '' ) && $uid === ( $entry['context']['actor']['wp_user_id'] ?? 0 ) );
    $check( 1 === count( $rollback_audit ), 'durable rollback audit preserves human actor and original linkage' );
    $check( ! str_contains( wp_json_encode( $audit_entries ), $raw ), 'durable audit does not expose fixture credential' );
    $clear(); $check( get_option( 'blogdescription' ) === $tagline, 'undo restores exact original tagline' );
    $check( is_wp_error( $manager->execute_request( $undo_rid, $actor ) ), 'human undo executes exactly once' );
    $check( 1 === count( ( new OperationResults() )->list_results( [ 'request_id' => $undo_rid ] ) ), 'undo has exactly one execution result' );

    update_option( 'wpcc_security_mode', 'developer' );
    // The action-dispatched OptionManager route is separate from SettingsRuntimeManager::rollback().
    $exec( 'option_manage', [ 'action' => 'option_update', 'option_id' => 'tagline', 'value' => $run ] );
    $option_original = $need( $row( '', 'option_manage' ), 'option original recorded' );
    $option_undo = $exec( 'change_history', [ 'action' => 'rollback_target', 'change_id' => $option_original['change_id'] ] );
    $verify_pair( $option_original, $option_undo, 'option action dispatcher' );
    $clear(); $check( get_option( 'blogdescription' ) === $tagline, 'option rollback restores original tagline' );
    $post_id = wp_insert_post( [ 'post_title' => $run, 'post_content' => 'Original fixture content', 'post_status' => 'draft' ] );
    $need( $post_id, 'temporary content fixture created' );
    $exec( 'content_manage', [ 'action' => 'content_update', 'content_id' => $post_id, 'title' => $run . '-edited' ] );
    $content_original = $need( $row( '', 'content_manage' ), 'content original recorded' );
    $forged = $content_original['result_ref'];
    $content_undo = $exec( 'change_history', [ 'action' => 'rollback_target', 'change_id' => $content_original['change_id'] ], [ 'execution_result_id' => $forged ] );
    $content_reverse = $verify_pair( $content_original, $content_undo, 'content' );
    $check( $content_reverse['result_ref'] !== $forged, 'caller supplied execution result ID cannot replace actual result' );
    $check( get_post( $post_id )->post_title === $run, 'content rollback restores original title' );

    wp_mkdir_p( $dir ); file_put_contents( $dir . '/fixture.php', "<?php\n// before\n" );
    $patch = $exec( 'patch_manage', [ 'action' => 'patch_create', 'files' => [ [ 'path' => $relative, 'mode' => 'replace_text', 'find' => '// before', 'replace' => '// after' ] ], 'explanation' => $run ], [ 'session_id' => null ] );
    $patch_id = $need( $patch['change_set_id'] ?? $patch['result']['change_set_id'] ?? '', 'patch fixture created' );
    $exec( 'patch_manage', [ 'action' => 'patch_apply', 'patch_id' => $patch_id ] );
    $patch_original = $need( $row( '', 'patch_manage' ), 'patch original recorded' );
    $patch_undo = $exec( 'change_history', [ 'action' => 'rollback_target', 'change_id' => $patch_original['change_id'] ] );
    if ( empty( $patch_undo['rolled_back_by'] ) && empty( $patch_undo['result']['rolled_back_by'] ) ) { echo '  DIAGNOSTIC: ' . wp_json_encode( $patch_undo ) . "\n"; }
    $verify_pair( $patch_original, $patch_undo, 'patch snapshots' );
    $check( "<?php\n// before\n" === file_get_contents( $dir . '/fixture.php' ), 'patch rollback restores snapshot bytes' );

    $snapshot = $exec( 'snapshot_manage', [ 'action' => 'snapshot_create', 'path' => $relative, 'label' => $run ] );
    $sid = $need( $snapshot['snapshot_id'] ?? $snapshot['result']['snapshot_id'] ?? '', 'standalone snapshot created' );
    $snapshot_original = $row( '', 'snapshot_manage' );
    file_put_contents( $dir . '/fixture.php', "<?php\n// standalone changed\n" );
    $restored = $exec( 'snapshot_manage', [ 'action' => 'snapshot_restore', 'snapshot_id' => $sid ] );
    $restore_row = $need( $row( '', 'snapshot_manage' ), 'standalone restore change recorded' );
    $actual_restore = ( new OperationResults() )->get_result( $restore_row['result_ref'] );
    $check( 'snapshot_restore' === ( $actual_restore['result_json']['result']['action'] ?? $actual_restore['result_json']['action'] ?? '' ), 'standalone snapshot result describes restore execution' );
    $check( $restore_row['result_ref'] !== ( $snapshot_original['result_ref'] ?? '' ), 'standalone restore has its own distinct result' );
    $check( "<?php\n// before\n" === file_get_contents( $dir . '/fixture.php' ), 'standalone snapshot restoration succeeds' );
    $check( true, 'no raw credential appeared in any tested MCP response' );
} catch ( Throwable $e ) {
    $check( false, 'suite completed: ' . $e->getMessage() );
} finally {
    $clear(); update_option( 'blogdescription', $tagline );
    if ( null === $mode ) { delete_option( 'wpcc_security_mode' ); } else { update_option( 'wpcc_security_mode', $mode ); }
    if ( $post_id ) { wp_delete_post( $post_id, true ); }
    foreach ( ( new \WPCommandCenter\Rollback\SnapshotManager() )->list( $relative ) as $s ) { ( new \WPCommandCenter\Rollback\SnapshotManager() )->delete( $s['id'] ); }
    if ( $patch_id ) { ( new \WPCommandCenter\PatchSystem\PatchManager() )->delete( $patch_id ); }
    if ( is_file( $dir . '/fixture.php' ) ) { unlink( $dir . '/fixture.php' ); }
    if ( is_dir( $dir ) ) { rmdir( $dir ); }
    // Restore only fixture-owned rollback records; keep every pre-existing record.
    foreach ( $rollback_options as $option => $before ) {
        wp_cache_delete( $option, 'options' ); wp_cache_delete( 'alloptions', 'options' );
        $current = get_option( $option, [] );
        if ( 'wpcc_settings_rollbacks' === $option ) {
            $current = array_values( array_filter( $current, static fn( $record ) => ( $record['session_id'] ?? '' ) !== $run ) );
            // A capped list may evict an old record when our fixture is appended.
            // Restore those exact original records too; retain any unrelated additions.
            $old_ids = array_column( (array) $before, 'id' );
            $current = array_merge( (array) $before, array_values( array_filter( $current, static fn( $record ) => ! in_array( $record['id'] ?? '', $old_ids, true ) ) ) );
            if ( null === $before && [] === $current ) { delete_option( $option ); } else { update_option( $option, $current ); }
            continue;
        }
        foreach ( $current as $key => $record ) {
            if ( ! array_key_exists( $key, (array) $before ) && ( 'wpcc_option_rollbacks' === $option ? ( $record['new_value'] ?? '' ) === $run : ( (string) $key === (string) $post_id || (int) ( $record['content_id'] ?? 0 ) === $post_id || ( $record['session_id'] ?? '' ) === $run ) ) ) { unset( $current[$key] ); }
        }
        if ( null === $before && [] === $current ) { delete_option( $option ); } else { update_option( $option, $current ); }
    }
    foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT change_id FROM {$wpdb->prefix}wpcc_change_log WHERE session_id=%s", $run ) ) as $id ) { $changes[$id] = true; }
    foreach ( array_keys( $requests ) as $id ) {
        foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT change_id FROM {$wpdb->prefix}wpcc_change_log WHERE request_id=%s", $id ) ) as $cid ) { $changes[$cid] = true; }
        $wpdb->delete( $wpdb->prefix . 'wpcc_operation_queue', [ 'request_id' => $id ] );
        $wpdb->delete( $wpdb->prefix . 'wpcc_operation_requests', [ 'request_id' => $id ] );
    }
    if ( $patch_id ) { foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT change_id FROM {$wpdb->prefix}wpcc_change_log WHERE change_set_id=%s", $patch_id ) ) as $id ) { $changes[$id] = true; } }
    foreach ( array_keys( $changes ) as $id ) { $wpdb->delete( $wpdb->prefix . 'wpcc_change_log', [ 'change_id' => $id ] ); }
    foreach ( array_keys( $results ) as $id ) { $wpdb->delete( $wpdb->prefix . 'wpcc_operation_results', [ 'result_id' => $id ] ); }
    if ( $token_id ) { ( new AuthTokens() )->revoke( $token_id ); ( new AuthTokens() )->delete( $token_id ); }
    $check( $history_fingerprint() === $historical_before, 'all pre-existing history including certification evidence remains byte-for-byte unchanged' );
    $check( get_option( 'wpcc_security_mode', null ) === $mode, 'original protection mode restored' );
    $check( get_option( 'blogdescription' ) === $tagline, 'exact original tagline preserved after cleanup' );
}
echo "\nResults: {$passed} passed, {$failed} failed; duration " . round( microtime( true ) - $began, 2 ) . "s\n";
if ( $failed ) { WP_CLI::halt( 1 ); }
