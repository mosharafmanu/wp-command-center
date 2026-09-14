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
	'chatgpt'      => 'f9b266ebe1772c34b56bcb818a55a20b5aba71c00922f4850f5fb8840c192a98',
	'codex'        => 'f9b266ebe1772c34b56bcb818a55a20b5aba71c00922f4850f5fb8840c192a98',
	'claude'       => '74395699bec3427ea92da993f5b6ac9d5a15cfb794aeb55810103f2ed2777f40',
	'claude_code'  => '78f11906850ef94d4b9e61aefe299c988713589217961482b24fc9815d9f0a6c',
	'antigravity'  => '8eba910bb3895a0ea7f970c6339a2cc45711663d71bde2e1c8e534e0594aa1cd',
	'gemini'       => 'a0a3b92f0e079d3dec5a389a2e97e49ffb9eb5dbb9b1ae69e8f32e3b4b2f1abc',
	'cursor'       => '6a74153956c8b935076cb4fe8429a01b12dbb71cb5c2c0e8291fb583876fee06',
	'continue'     => '9e11a79c5f9695b5db12200cf51fc1956f3f5c4e25d220734e0face3415ef916',
	'vscode'       => '1114ed0fcb9b950e80b0fa17eb2cdfe805006e94be926c908014fe69a0832450',
	'opencode'     => 'a4b9718d98e968f4a80f25b81a0465f35c269d474ea3758e8e7d421532ad1acd',
	'command_code' => '0fc877e70c8db265e8aff01845fded43ee7baf10734816eff388d82ed1a42949',
	'muse_code'    => 'da6b12562db3f6fbb55009cd9bf8360400d5bb4dca694372367f917658ea6bac',
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

$fixture_token = 'siteradian_TEST_ONLY_M21';
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
