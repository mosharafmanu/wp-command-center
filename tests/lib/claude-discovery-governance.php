<?php
/**
 * Focused regression coverage for Claude discovery governance metadata.
 *
 * Loaded through `wp eval-file`; option filters are process-local, and the test
 * never creates a token or invokes an operation.
 */

use WPCommandCenter\AiAgent\RestApi;
use WPCommandCenter\Integration\ClaudeIntegration;
use WPCommandCenter\Mcp\McpServerRuntime;
use WPCommandCenter\Operations\ReportingRuntimeManager;
use WPCommandCenter\Operations\SecurityModeManager;

$passed = 0;
$failed = 0;
$check = static function ( bool $ok, string $label ) use ( &$passed, &$failed ): void {
	if ( $ok ) {
		++$passed;
		echo "  PASS: $label\n";
		return;
	}

	++$failed;
	echo "  FAIL: $label\n";
};

$mode   = SecurityModeManager::MODE_CLIENT;
$legacy = '0';
add_filter( 'pre_option_wpcc_security_mode', static function () use ( &$mode ) {
	return $mode;
} );
add_filter( 'pre_option_wpcc_enforce_approval', static function () use ( &$legacy ) {
	return $legacy;
} );

$matrix = [
	SecurityModeManager::MODE_CLIENT => [
		SecurityModeManager::RISK_DIAGNOSTIC => false,
		SecurityModeManager::RISK_LOW        => false,
		SecurityModeManager::RISK_MEDIUM     => true,
		SecurityModeManager::RISK_HIGH       => true,
		SecurityModeManager::RISK_CRITICAL   => true,
	],
	SecurityModeManager::MODE_ENTERPRISE => [
		SecurityModeManager::RISK_DIAGNOSTIC => false,
		SecurityModeManager::RISK_LOW        => true,
		SecurityModeManager::RISK_MEDIUM     => true,
		SecurityModeManager::RISK_HIGH       => true,
		SecurityModeManager::RISK_CRITICAL   => true,
	],
	SecurityModeManager::MODE_DEVELOPER => [
		SecurityModeManager::RISK_DIAGNOSTIC => false,
		SecurityModeManager::RISK_LOW        => false,
		SecurityModeManager::RISK_MEDIUM     => false,
		SecurityModeManager::RISK_HIGH       => false,
		SecurityModeManager::RISK_CRITICAL   => false,
	],
];

$database_state = static function (): array {
	global $wpdb;

	$options = $wpdb->get_results( "SELECT option_id, option_name, option_value FROM {$wpdb->options} ORDER BY option_id", ARRAY_A );

	return [
		'options_hash' => hash( 'sha256', wp_json_encode( $options ) ),
		'posts'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ),
		'requests'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcc_operation_requests" ),
		'queue'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcc_operation_queue" ),
		'history'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcc_change_log" ),
		'results'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcc_operation_results" ),
	];
};

$security_report = static function (): array {
	$method = new ReflectionMethod( ReportingRuntimeManager::class, 'security' );
	$method->setAccessible( true );

	return $method->invoke( new ReportingRuntimeManager() );
};

$before = $database_state();

foreach ( $matrix as $mode => $expected_by_risk ) {
	foreach ( [ '0', '1' ] as $legacy ) {
		$label       = "$mode / legacy=$legacy";
		$policy      = SecurityModeManager::approval_policy();
		$discovery   = ClaudeIntegration::get_discovery_metadata();
		$approval    = $discovery['approval'] ?? [];
		$mcp         = ( new McpServerRuntime() )->handle( [
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'resources/read',
			'params'  => [ 'uri' => 'wpcc://manifest' ],
		], [ 'client' => 'wpcc-claude-discovery-governance-test' ] );
		$mcp_manifest  = json_decode( $mcp['result']['contents'][0]['text'] ?? '', true );
		$mcp_security  = $mcp_manifest['security'] ?? [];
		$report        = $security_report()['security'] ?? [];
		$rest_manifest = ( new RestApi() )->get_agent_manifest(
			new WP_REST_Request( 'GET', '/wp-command-center/v1/agent/manifest' )
		)->get_data();
		$expected_enforcement = in_array( true, $expected_by_risk, true );
		$expected_tiers       = array_keys( array_filter( $expected_by_risk ) );

		$check( $policy['mode'] === $mode, "$label: canonical policy mode" );
		$check( $policy['requires_approval_by_risk'] === $expected_by_risk, "$label: execution risk matrix" );
		$check( $policy['required_risk_tiers'] === $expected_tiers, "$label: canonical required tiers" );
		$check( $policy['enforcement'] === $expected_enforcement, "$label: canonical enforcement" );
		$check( $approval['enforcement'] === $expected_enforcement, "$label: Claude enforcement" );
		$check( $approval['security_mode'] === $mode, "$label: Claude mode" );
		$check( $approval['requires_approval_by_risk'] === $expected_by_risk, "$label: Claude risk matrix" );
		$check( $approval['required_risk_tiers'] === $expected_tiers, "$label: Claude required tiers" );
		$check( $approval['requires_human_approver'] === $policy['requires_human_approver'], "$label: Claude human boundary" );

		$required = $approval['required_for'] ?? null;
		$free     = $approval['not_required_for'] ?? null;
		$check( is_array( $required ) && is_array( $free ), "$label: legacy operation lists preserved" );
		$check( count( $required ) + count( $free ) === count( $discovery['tools'] ?? [] ), "$label: operation lists partition tools" );
		$check( ! array_filter( $required, static fn( $op ) => empty( $expected_by_risk[ $op['risk_level'] ?? '' ] ) ), "$label: required list contains only gated tiers" );
		$check( ! array_filter( $free, static function ( string $id ) use ( $discovery, $expected_by_risk ): bool {
			foreach ( $discovery['tools'] as $tool ) {
				if ( $tool['name'] === $id ) {
					return ! empty( $expected_by_risk[ $tool['risk_level'] ?? '' ] );
				}
			}
			return true;
		} ), "$label: free list contains only ungated tiers" );
		$check( ! array_filter( $discovery['tools'] ?? [], static fn( $tool ) => ( $tool['requires_approval'] ?? null ) !== ( $expected_by_risk[ $tool['risk_level'] ?? '' ] ?? null ) ), "$label: tool flags use effective policy" );
		$group_tools = array_merge( ...array_map( static fn( $group ) => $group['tools'] ?? [], $discovery['tool_groups'] ?? [] ) );
		$check( ! array_filter( $group_tools, static fn( $tool ) => ( $tool['requires_approval'] ?? null ) !== ( $expected_by_risk[ $tool['risk_level'] ?? '' ] ?? null ) ), "$label: grouped-tool flags use effective policy" );

		$check( $mcp_security['approval_enforcement'] === $approval['enforcement'], "$label: MCP enforcement agrees" );
		$check( $mcp_security['security_mode'] === $approval['security_mode'], "$label: MCP mode agrees" );
		$check( $mcp_security['approval_policy']['requires_approval_by_risk'] === $approval['requires_approval_by_risk'], "$label: MCP risk matrix agrees" );
		$check( $mcp_security['approval_policy']['required_risk_tiers'] === $approval['required_risk_tiers'], "$label: MCP required tiers agree" );
		$check( ( $mcp_manifest['operations']['requires_approval'] ?? null ) === count( $required ), "$label: MCP gated-operation count agrees" );
		$check( $report['approval_enforced'] === $approval['enforcement'], "$label: security report enforcement agrees" );
		$check( $report['security_mode'] === $approval['security_mode'], "$label: security report mode agrees" );
		$check( $report['approval_policy']['requires_approval_by_risk'] === $approval['requires_approval_by_risk'], "$label: security report risk matrix agrees" );
		$check( $report['approval_policy']['required_risk_tiers'] === $approval['required_risk_tiers'], "$label: security report required tiers agree" );
		$check( $rest_manifest['security']['human_approval_required'] === $approval['requires_human_approver'], "$label: full manifest human boundary agrees" );

		$encoded = wp_json_encode( $discovery );
		$check( isset( $discovery['server'], $discovery['resources'], $discovery['tools'], $discovery['tool_groups'], $discovery['capabilities'], $discovery['approval'], $discovery['wp_cli'], $discovery['pricing'], $discovery['compatibility'] ), "$label: existing top-level fields preserved" );
		$check( is_bool( $approval['enforcement'] ?? null ) && is_array( $required ) && is_array( $free ), "$label: existing approval field types preserved" );
		$check( 0 === preg_match( '/wpcc_[A-Za-z0-9]{20,}/', $encoded ) && false === stripos( $encoded, 'Authorization: Bearer ' ), "$label: no secret-bearing value" );
	}
}

foreach ( [ null, '', 'invalid-mode' ] as $mode ) {
	foreach ( [ '0', '1' ] as $legacy ) {
		$approval = ClaudeIntegration::get_discovery_metadata()['approval'];
		$stored = null === $mode ? 'missing' : "'$mode'";
		$check( SecurityModeManager::MODE_CLIENT === $approval['security_mode'], "fallback / stored=$stored / legacy=$legacy: Standard mode" );
		$check( true === $approval['enforcement'], "fallback / stored=$stored / legacy=$legacy: enforcement stays on" );
		$check( $matrix[ SecurityModeManager::MODE_CLIENT ] === $approval['requires_approval_by_risk'], "fallback / stored=$stored / legacy=$legacy: Standard risk matrix" );
	}
}

$after = $database_state();
$check( $before === $after, 'Claude discovery creates no WordPress, approval, queue, result, or change-history write' );

echo "Claude discovery governance: $passed passed, $failed failed\n";
exit( $failed > 0 ? 1 : 0 );
