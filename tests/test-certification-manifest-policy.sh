#!/usr/bin/env bash
# Certification finding A: the default MCP manifest describes effective approval
# policy, independently of the obsolete binary option. Runs the shipped resource
# serializer, REST manifest and report implementations in WordPress. Option
# filters are process-local: no protection option or operational row is changed.
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WP_ROOT="${WPCC_TEST_WP_PATH:-$(cd "$SCRIPT_DIR/../../../.." && pwd)}"
CASE_FILE="$(mktemp "${TMPDIR:-/tmp}/wpcc-manifest-policy.XXXXXX")"
trap 'rm -f "$CASE_FILE"' EXIT
cat > "$CASE_FILE" <<'PHP'
<?php
use WPCommandCenter\AiAgent\ContextSummaryBuilder;
use WPCommandCenter\AiAgent\RestApi;
use WPCommandCenter\Mcp\McpServerRuntime;
use WPCommandCenter\Operations\ReportingRuntimeManager;
use WPCommandCenter\Operations\SecurityModeManager;

$passed = 0;
$failed = 0;
$check = static function ( bool $ok, string $label ) use ( &$passed, &$failed ): void {
    if ( $ok ) { ++$passed; echo "  PASS: $label\n"; }
    else { ++$failed; echo "  FAIL: $label\n"; }
};
$mode = 'client';
$legacy = '0';
// A string '0' short-circuits get_option while preserving boolean false.
add_filter( 'pre_option_wpcc_security_mode', static function () use ( &$mode ) { return $mode; } );
add_filter( 'pre_option_wpcc_enforce_approval', static function () use ( &$legacy ) { return $legacy; } );
$matrix = [
    'client' => [ 'diagnostic' => false, 'low' => false, 'medium' => true, 'high' => true, 'critical' => true ],
    'enterprise' => [ 'diagnostic' => false, 'low' => true, 'medium' => true, 'high' => true, 'critical' => true ],
    'developer' => [ 'diagnostic' => false, 'low' => false, 'medium' => false, 'high' => false, 'critical' => false ],
];
foreach ( $matrix as $mode => $expected_risks ) {
    foreach ( [ '0', '1' ] as $legacy ) {
        $label = "$mode / legacy=$legacy";
        $response = ( new McpServerRuntime() )->handle( [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/read',
            'params' => [ 'uri' => 'wpcc://manifest' ],
        ], [ 'client' => 'wpcc-certification-manifest-policy-test' ] );
        $manifest = json_decode( $response['result']['contents'][0]['text'] ?? '', true );
        $security = $manifest['security'] ?? [];
        $expected_enforced = 'developer' !== $mode;
        $check( ! isset( $response['error'] ) && is_array( $manifest ), "$label: MCP resource returns JSON" );
        $check( is_bool( $security['approval_enforcement'] ?? null ), "$label: approval_enforcement remains a boolean" );
        $check( ( $security['approval_enforcement'] ?? null ) === $expected_enforced, "$label: effective gate replaces obsolete option" );
        $check( ( $security['security_mode'] ?? null ) === $mode, "$label: mode is explicit" );
        $check( ( $security['approval_policy']['requires_approval_by_risk'] ?? null ) === $expected_risks, "$label: complete action-risk approval policy" );
        foreach ( $expected_risks as $risk => $requires_approval ) {
            $check( ( $security['approval_policy']['requires_approval_by_risk'][$risk] ?? null ) === SecurityModeManager::requires_approval( $risk ), "$label: $risk agrees with execution gate" );
        }
        $check( ( $security['approval_policy']['requires_human_approver'] ?? null ) === $expected_enforced, "$label: human approval requirement" );
        $report = ( new ReportingRuntimeManager() )->run( [ 'action' => 'report_security' ] );
        $check( $security['approval_enforcement'] === $report['security']['approval_enforced'] && $security['security_mode'] === $report['security']['security_mode'], "$label: security report agrees" );
        $full_manifest = ( new RestApi() )->get_agent_manifest( new WP_REST_Request( 'GET', '/wp-command-center/v1/agent/manifest' ) )->get_data();
        $check( $security['approval_policy']['requires_human_approver'] === $full_manifest['security']['human_approval_required'], "$label: full manifest agrees" );
        $check( ( $manifest['context_mode'] ?? null ) === 'compact'
            && ( $manifest['operations']['total'] ?? null ) === 42
            && ( $manifest['mcp']['resource_count'] ?? null ) === 7
            && is_bool( $security['capability_enforcement'] ?? null )
            && true === ( $security['rollback_supported'] ?? null ), "$label: existing compact contract preserved" );
    }
}
// Missing/corrupt stored mode still describes Standard, the fail-safe policy.
foreach ( [ '', 'invalid-mode' ] as $mode ) {
    $security = ( new ContextSummaryBuilder() )->manifest_summary()['security'];
    $check( 'client' === $security['security_mode'] && true === $security['approval_enforcement']
        && $matrix['client'] === $security['approval_policy']['requires_approval_by_risk'], 'invalid/missing mode describes fail-safe Standard policy' );
}
echo "Manifest policy certification: $passed passed, $failed failed\n";
exit( $failed ? 1 : 0 );
PHP
wp --path="$WP_ROOT" eval-file "$CASE_FILE"
