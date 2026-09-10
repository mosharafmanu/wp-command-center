<?php
/**
 * Rendered contract for the shared Connections → Assistants onboarding system.
 *
 * Uses one short-lived synthetic token and deletes it in a finally block. No raw
 * credential or rendered HTML is written to output or retained on disk.
 */

use WPCommandCenter\Integration\AIClientRegistry as Registry;
use WPCommandCenter\Security\AuthTokens;

ini_set( 'zend.exception_ignore_args', '1' );

$root   = $args[0];
$passes = 0;
$fails  = 0;

$check = static function ( string $label, bool $condition ) use ( &$passes, &$fails ): void {
	if ( $condition ) {
		++$passes;
		echo "PASS: {$label}\n";
		return;
	}

	++$fails;
	echo "FAIL: {$label}\n";
};

$has_class = static function ( DOMXPath $xpath, DOMNode $context, string $class ): bool {
	return 0 < $xpath->query( './/*[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")]', $context )->length;
};

$expected_payload_hashes = [
	'chatgpt'      => '1e854d9aebdecb51dad1745a5ca5cf9955237f2eb87d27d33daf729458de5461',
	'codex'        => '1e854d9aebdecb51dad1745a5ca5cf9955237f2eb87d27d33daf729458de5461',
	'claude'       => '0939dd42306a22c51615b9c7cb862ada31c23f0e5aa781c222cf5547b0e3fe5c',
	'claude_code'  => 'f6a0a7e09d91dbe974a9893a652f77de76e811d2929bd6326a0f92489763a273',
	'antigravity'  => 'ab52ef44ad5c733b937fb09d89d18e6718e733ebad374b42724c0d1daabccc98',
	'gemini'       => '97c715dea6157c6398791381bac39a948378c16790930efe7f2639f0d20d0117',
	'cursor'       => '854963c5bebd6f8a646117dfa51c0545ba8c882779fd7f000ec719688997746d',
	'continue'     => '6d09c6c7a3d9dae4a60516a6824c09f9a82dc3c22305d17dfadbfd2cc44eab22',
	'vscode'       => '357bdfd660916cc8af5c64f81fb33ccffbb175f306a43567ecb0714143966bd0',
	'opencode'     => '88d6d8dd915ee667ba0eea8c0a938d57b342a530215c4c880834febfdd531bad',
	'command_code' => 'd0f1bf76b40a3b7bbb117b471cdaa0023b94ab996ddc9c25cf0e958f023fdea1',
	'muse_code'    => '708f238ad69ccae297856dbb2e957a8f049b6badaa4a35138be0b741686f5d1b',
];

$expected_badges = [
	'chatgpt'      => 'Connection tested',
	'codex'        => 'Connection tested',
	'claude'       => 'Connection tested',
	'claude_code'  => 'Certified',
	'antigravity'  => 'Connection tested',
	'gemini'       => 'Account unavailable',
	'cursor'       => 'Connection tested',
	'continue'     => 'Connection tested',
	'vscode'       => 'Connection tested',
	'opencode'     => 'Connection tested',
	'command_code' => 'Connection tested',
	'muse_code'    => 'Connection tested',
];

$fixture_token = 'wpcc_TEST_ONLY_M21';
foreach ( Registry::get_clients() as $client_id => $client ) {
	$parts = [
		Registry::render_config( Registry::generate_config( $client_id ), $fixture_token ),
		Registry::primary_config_for( $client_id, $fixture_token, 'fixture-id' ),
		Registry::setup_command_for( $client_id, $fixture_token ),
		Registry::render_entry_config( $client_id ),
	];
	$check( "{$client_id}: technical setup payload unchanged", hash( 'sha256', implode( "\n--M21--\n", $parts ) ) === $expected_payload_hashes[ $client_id ] );
	$check( "{$client_id}: certification badge unchanged", ( Registry::selector_badge_for( $client_id )['label'] ?? '' ) === $expected_badges[ $client_id ] );
}

wp_set_current_user( 1 );
$auth    = new AuthTokens();
$created = $auth->create( 'M21 shared onboarding temporary test', AuthTokens::SCOPE_READ_ONLY, time() + HOUR_IN_SECONDS, 1 );
if ( is_wp_error( $created ) ) {
	echo "FAIL: could not create isolated synthetic token\n";
	exit( 1 );
}

$record_id = (string) $created['record']['id'];
$raw_token = (string) $created['token'];

try {
	foreach ( Registry::get_clients() as $client_id => $client ) {
		$_GET     = [
			'client'   => $client_id,
			'tab'      => 'configuration',
			'token_id' => $record_id,
		];
		$_POST    = [];
		$_REQUEST = $_GET;

		$html = ( static function ( string $template ): string {
			ob_start();
			require $template;
			return (string) ob_get_clean();
		} )( $root . '/includes/Admin/views/ai-integrations.php' );

		$dom = new DOMDocument();
		@$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
		$xpath   = new DOMXPath( $dom );
		$guided  = $xpath->query( '//*[@id="wpcc-guided-setup"]' )->item( 0 );
		$verify  = $xpath->query( '//*[@id="wpcc-verify-client"]' )->item( 0 );
		$system  = $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " wpcc-connect-system ")]' )->item( 0 );
		$steps   = $xpath->query( './/*[contains(concat(" ", normalize-space(@class), " "), " wpcc-connect-step ")]', $guided );
		$details = $xpath->query( './/details', $guided );
		$kind    = (string) ( $client['setup_kind'] ?? '' );

		$check( "{$client_id}: shared Step 3 system rendered", $guided && $system && $system->getAttribute( 'data-client' ) === $client_id );
		$check( "{$client_id}: uses shared numbered action rows", $steps->length >= 2 );
		$check( "{$client_id}: action rows expose semantic list structure", 1 === $xpath->query( './/*[@role="list"]', $guided )->length && $steps->length === $xpath->query( './/*[@role="listitem"]', $guided )->length );
		foreach ( $steps as $index => $step ) {
			$number = (string) ( $index + 1 );
			$check( "{$client_id}: action {$number} is sequential", $step->getAttribute( 'data-step' ) === $number );
			$check( "{$client_id}: action {$number} has shared title and body", $has_class( $xpath, $step, 'wpcc-connect-step__number' ) && $has_class( $xpath, $step, 'wpcc-connect-step__title' ) && $has_class( $xpath, $step, 'wpcc-connect-step__body' ) );
			$check( "{$client_id}: action {$number} has at most one primary CTA", $xpath->query( './/*[contains(concat(" ", normalize-space(@class), " "), " button-primary ")]', $step )->length <= 1 );
		}

		$all_collapsed = true;
		foreach ( $details as $disclosure ) {
			if ( $disclosure->hasAttribute( 'open' ) ) {
				$all_collapsed = false;
				break;
			}
		}
		$check( "{$client_id}: Step 3 disclosures start collapsed", $all_collapsed );

		$config_blocks_hidden = true;
		foreach ( $xpath->query( './/pre[contains(concat(" ", normalize-space(@class), " "), " wpcc-ai-config ")]', $guided ) as $config_block ) {
			if ( 0 === $xpath->query( 'ancestor::details', $config_block )->length ) {
				$config_blocks_hidden = false;
				break;
			}
		}
		$check( "{$client_id}: commands and configuration are progressively disclosed", $config_blocks_hidden );
		$check( "{$client_id}: guided CTA avoids vague configuration wording", ! str_contains( $guided->textContent, 'Copy configuration' ) );

		$credential_mode = Registry::credential_mode_for( $client_id );
		if ( 'env_var' === $credential_mode ) {
			$check( "{$client_id}: environment credential is not duplicated as a token field", 0 === $xpath->query( './/*[contains(concat(" ", normalize-space(@class), " "), " wpcc-connect-credential ")]', $guided )->length );
		} elseif ( 'prompt' === $credential_mode ) {
			$check( "{$client_id}: secure client prompt uses the shared credential component", 1 === $xpath->query( './/*[contains(concat(" ", normalize-space(@class), " "), " wpcc-connect-credential ") and contains(., "Your token stays in VS Code")]', $guided )->length );
		} else {
			$check( "{$client_id}: saved secret uses the shared browser-only credential component", 1 === $xpath->query( './/*[contains(concat(" ", normalize-space(@class), " "), " wpcc-connect-credential ")]//input[@id="wpcc-token-fill" and @type="password"]', $guided )->length );
		}
		if ( 'vscode_config' === $kind ) {
			$check( "{$client_id}: setup CTA names its destination", str_contains( $guided->textContent, 'Copy VS Code setup' ) );
		}

		$check( "{$client_id}: universal Step 4 rendered", $verify && $has_class( $xpath, $verify, 'wpcc-verify-system' ) );
		$check( "{$client_id}: one universal test prompt", 1 === $xpath->query( './/*[@id="wpcc-verification-prompt"]', $verify )->length );
		$check( "{$client_id}: one clear verification CTA", 1 === $xpath->query( './/button[@data-copy-target="wpcc-verification-prompt" and contains(concat(" ", normalize-space(@class), " "), " button-primary ")]', $verify )->length );
		$check( "{$client_id}: shared success checklist has three outcomes", 3 === $xpath->query( './/*[contains(concat(" ", normalize-space(@class), " "), " wpcc-verify-success ")]//li', $verify )->length );
		$check( "{$client_id}: advanced manual setup starts collapsed", 1 === $xpath->query( '//details[@id="wpcc-config-panel" and not(@open)]' )->length );
		$check( "{$client_id}: selected token is never reconstructed into rendered output", ! str_contains( $html, $raw_token ) );
	}
} finally {
	$_GET = $_POST = $_REQUEST = [];
	$deleted = $auth->delete( $record_id );
	$check( 'temporary synthetic token deleted', true === $deleted );
}

echo "{$passes} passed, {$fails} failed\n";
exit( 0 === $fails ? 0 : 1 );
