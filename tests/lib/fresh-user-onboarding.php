<?php
/** M22/M23/M25 rendered fresh-user contract. No credential is printed or retained. */

use WPCommandCenter\Integration\AIClientRegistry as Registry;
use WPCommandCenter\Security\AuthTokens;

ini_set( 'zend.exception_ignore_args', '1' );

$root   = $args[0];
$passes = 0;
$fails  = 0;
$check  = static function ( string $label, bool $ok ) use ( &$passes, &$fails ): void {
	if ( $ok ) {
		++$passes;
		echo "PASS: {$label}\n";
	} else {
		++$fails;
		echo "FAIL: {$label}\n";
	}
};
$render = static function ( string $template, string $client, array $get = [], array $post = [] ): string {
	$_GET = array_merge( [ 'client' => $client, 'tab' => 'configuration' ], $get );
	$_POST = $post;
	$_REQUEST = array_merge( $_GET, $_POST );
	ob_start();
	require $template;
	return (string) ob_get_clean();
};
$dom_for = static function ( string $html ): DOMXPath {
	$dom = new DOMDocument();
	@$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
	return new DOMXPath( $dom );
};

wp_set_current_user( 1 );
$auth = new AuthTokens();
$reload = $auth->create( 'M25 temporary reload-state test', AuthTokens::SCOPE_READ_ONLY, time() + HOUR_IN_SECONDS, 1 );
if ( is_wp_error( $reload ) ) {
	echo "FAIL: temporary reload-state token could not be created\n";
	exit( 1 );
}

$reload_id      = (string) $reload['record']['id'];
$reload_raw     = (string) $reload['token'];
$reload_preview = (string) $reload['record']['token_preview'];
$classes = [
	'chatgpt'      => 'env_var_name',
	'codex'        => 'env_var_name',
	'claude'       => 'raw_token',
	'claude_code'  => 'raw_token',
	'antigravity'  => 'raw_token',
	'gemini'       => 'raw_token',
	'cursor'       => 'raw_token',
	'continue'     => 'raw_token',
	'vscode'       => 'client_prompt',
	'opencode'     => 'raw_token',
	'command_code' => 'raw_token',
	'muse_code'    => 'raw_token',
];

try {
	foreach ( $classes as $client => $expected_class ) {
		$check( "{$client}: credential setup classification", $expected_class === Registry::setup_credential_class_for( $client ) );
		$html = $render( $root . '/includes/Admin/views/ai-integrations.php', $client, [ 'token_id' => $reload_id ] );
		$xp   = $dom_for( $html );

		if ( 'raw_token' === $expected_class ) {
			$controls = $xp->query( '//*[@data-wpcc-requires-token-control]' );
			$check( "{$client}: reload has token-gated controls", 0 < $controls->length );
			$all_disabled = true;
			foreach ( $controls as $control ) {
				$all_disabled = $all_disabled && $control->hasAttribute( 'disabled' ) && 'true' === $control->getAttribute( 'aria-disabled' );
			}
			$check( "{$client}: reload copy/install controls are unavailable", $all_disabled );
			$check( "{$client}: reload asks for a browser-only saved token", 1 === $xp->query( '//*[@id="wpcc-token-fill" and @type="password"]' )->length );
			$check( "{$client}: reload explains token-first setup", 0 < $xp->query( '//*[@data-wpcc-token-needed and not(@hidden)]' )->length );
			$check( "{$client}: incomplete previews are hidden", 0 === $xp->query( '//*[@data-wpcc-requires-token-preview and not(@hidden)] | //*[@data-wpcc-requires-token-payload and not(@hidden)]' )->length );
		} else {
			$check( "{$client}: secret-free setup has no disabled token gate", 0 === $xp->query( '//*[@data-wpcc-requires-token-control and @disabled]' )->length );
		}
		$check( "{$client}: stored raw token is not reconstructed", ! str_contains( $html, $reload_raw ) );

		if ( 'muse_code' === $client && getenv( 'WPCC_FRESH_RENDER_DIR' ) ) {
			$safe = str_replace( [ $reload_raw, $reload_preview ], [ 'REDACTED_TOKEN', 'REDACTED_PREVIEW' ], $html );
			file_put_contents( rtrim( getenv( 'WPCC_FRESH_RENDER_DIR' ), '/' ) . '/muse-reload.html', $safe );
		}
	}

	// A real token-creation response proves server-rendered first use is complete now.
	$post = [
		'wpcc_token_action'  => 'create',
		'_wpnonce'           => wp_create_nonce( 'wpcc_ai_integrations' ),
		'wpcc_token_label'   => 'M25 temporary fresh-token test',
		'wpcc_token_scope'   => 'read_only',
		'wpcc_token_expires' => '30d',
	];
	$fresh_html = $render( $root . '/includes/Admin/views/ai-integrations.php', 'muse_code', [], $post );
	$fresh_xp   = $dom_for( $fresh_html );
	$check( 'Muse fresh-token setup controls are enabled', 0 === $fresh_xp->query( '//*[@data-wpcc-requires-token-control and @disabled]' )->length );
	$check( 'Muse fresh-token complete payload has no unresolved placeholder', ! str_contains( $fresh_xp->query( '//*[@id="wpcc-muse-complete"]' )->item( 0 )->textContent ?? '', Registry::TOKEN_PLACEHOLDER ) );
	$check( 'Muse fresh-token entry payload has no unresolved placeholder', ! str_contains( $fresh_xp->query( '//*[@id="wpcc-muse-entry"]' )->item( 0 )->textContent ?? '', Registry::TOKEN_PLACEHOLDER ) );
} finally {
	$_GET = $_POST = $_REQUEST = [];
	$check( 'reload-state token deleted', true === $auth->delete( $reload_id ) );
	foreach ( $auth->list() as $record ) {
		if ( 'M25 temporary fresh-token test' === ( $record['label'] ?? '' ) ) {
			$check( 'fresh-token record deleted', true === $auth->delete( (string) $record['id'] ) );
		}
	}
}

echo "{$passes} passed, {$fails} failed\n";
exit( 0 === $fails ? 0 : 1 );
