<?php
ini_set("zend.exception_ignore_args", "1");
use WPCommandCenter\Integration\AIClientRegistry as Registry;
use WPCommandCenter\Security\AuthTokens;
$root = $args[0];
$GLOBALS['wpcc_ux_pass'] = 0;
function contract($label, $ok) {
    global $wpcc_ux_pass;
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
    ++$wpcc_ux_pass; echo "PASS: $label\n";
}
wp_set_current_user(1);
foreach (['codex' => 'Codex CLI', 'chatgpt' => 'Codex in ChatGPT Desktop'] as $test_client => $name) {
    $wpcc_new_record = null;
    try {
        $_GET = ['client' => $test_client, 'tab' => 'configuration'];
        $_POST = ['wpcc_token_action' => 'create', '_wpnonce' => wp_create_nonce('wpcc_ai_integrations'), 'wpcc_token_label' => $name . ' UX temporary test', 'wpcc_token_scope' => 'read_only', 'wpcc_token_expires' => '30d'];
        $_REQUEST = $_POST;
        ob_start();
        require $root . '/includes/Admin/views/ai-integrations.php';
        $html = ob_get_clean();
        contract("$test_client token created", !empty($wpcc_new_token));
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xp = new DOMXPath($dom);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
        $guided = $xp->query('//*[@id="wpcc-guided-setup"]')->item(0)->textContent;
		$selected_card = $xp->query('//*[contains(concat(" ", normalize-space(@class), " "), " wpcc-ai-pick ") and contains(concat(" ", normalize-space(@class), " "), " is-selected ")]')->item(0);
		$wide_family = $xp->query('//*[contains(concat(" ", normalize-space(@class), " "), " wpcc-ai-family--wide ")]')->item(0);
		$other_family = $xp->query('//*[contains(concat(" ", normalize-space(@class), " "), " wpcc-ai-family--other ")]')->item(0);
		contract("$test_client selector has no duplicate Next CTA", !str_contains($text, 'Next: Create access token') && !$xp->query('//*[@id="wpcc-selection-next"]')->length);
		contract("$test_client selector ends without a selected-client note", !$xp->query('.//*[contains(concat(" ", normalize-space(@class), " "), " wpcc-selected-client-note ")]', $xp->query('//*[@id="wpcc-choose-app"]')->item(0))->length);
		contract("$test_client Step 2 names the selected app", str_contains($xp->query('//*[@id="wpcc-create-access-title"]')->item(0)->textContent, "Create access for $name"));
		contract("$test_client has one Create access token opener", $xp->query('//*[@id="wpcc-tokenmake-open"]')->length === 1);
		contract("$test_client selected-card styling and aria state remain", $selected_card && $selected_card->getAttribute('aria-current') === 'page' && str_contains($selected_card->textContent, $name));
		contract("$test_client editor family spans as one responsive group", $wide_family && $xp->query('.//*[contains(concat(" ", normalize-space(@class), " "), " wpcc-ai-pick ")]', $wide_family)->length === 5);
		contract("$test_client Other Experimental starts collapsed", $other_family && !$other_family->hasAttribute('open'));
		$context_notes = $xp->query('//*[contains(concat(" ", normalize-space(@class), " "), " wpcc-selected-client-note ")]');
		if ($test_client === 'chatgpt') {
			contract('chatgpt contextual warning lives in Create access', $context_notes->length === 1 && str_contains($context_notes->item(0)->textContent, 'Use Codex mode') && $xp->query('ancestor::*[@id="wpcc-create-access"]', $context_notes->item(0))->length === 1);
			contract('chatgpt contextual warning precedes the Create access CTA', $xp->query('//*[@id="wpcc-tokenmake-open"]/ancestor::form/preceding-sibling::*[contains(concat(" ", normalize-space(@class), " "), " wpcc-selected-client-note ")]')->length === 1);
			contract('chatgpt guided setup does not repeat the prominent warning', !$xp->query('.//*[contains(concat(" ", normalize-space(@class), " "), " wpcc-selected-client-note ")]', $xp->query('//*[@id="wpcc-guided-setup"]')->item(0))->length);
		} else {
			contract('codex contextual note lives in Create access', $context_notes->length === 1 && $xp->query('ancestor::*[@id="wpcc-create-access"]', $context_notes->item(0))->length === 1);
		}
		$access_panel = $xp->query('//*[@id="wpcc-create-access"]')->item(0);
		contract("$test_client Step 2 lead carries its spacing class", $xp->query('.//*[contains(concat(" ", normalize-space(@class), " "), " wpcc-create-access__lead ")]', $access_panel)->length === 1);
		contract("$test_client Step 2 form carries its spacing class", $xp->query('.//*[contains(concat(" ", normalize-space(@class), " "), " wpcc-create-access__form ")]', $access_panel)->length === 1);
        contract("$test_client CTA goes to guided steps", $xp->query('//*[@id="wpcc-token-next"]')->item(0)->getAttribute('href') === '#wpcc-guided-setup');
        contract("$test_client CTA names client", str_contains($text, "Next: set up $name"));
        contract("$test_client one-time warning", str_contains($text, 'it will not be shown again'));
        contract("$test_client screenshot warning", str_contains($text, 'screenshots and shared chats'));
        contract("$test_client no premature client-ready claim", str_contains($text, 'Token “') && !str_contains($text, 'Your configuration is ready below with this token already in it'));
        $credential = $xp->query('//*[@data-wpcc-credential-command="macos"]')->item(0);
        $expected = Registry::credential_commands_for($test_client, $wpcc_new_token)['macos'];
        contract("$test_client HTML already contains complete escaped command", $credential && $credential->textContent === $expected);
        contract("$test_client no executable placeholder", !str_contains($credential->textContent, '${WPCC_TOKEN}'));
        contract("$test_client credential excluded from browser replacement", !$credential->hasAttribute('data-wpcc-token-slot') && !$xp->query('//*[@id="wpcc-token-fill"]')->length);
		contract("$test_client Copy targets rendered command", $xp->query('//*[@data-copy-target="wpcc-credential-macos"]')->length === 1);
        $config = $xp->query('//*[@id="wpcc-config-block"]')->item(0)->textContent;
        contract("$test_client config names variable", str_contains($config, 'bearer_token_env_var = "WPCC_TOKEN"'));
        contract("$test_client config contains no raw token", !str_contains($config, $wpcc_new_token));
        contract("$test_client native command token-free", !str_contains(Registry::setup_command_for($test_client, $wpcc_new_token), $wpcc_new_token));
        contract("$test_client manual agrees with guided", str_contains($text, 'Manual setup replaces only Step 2.') && str_contains($text, 'then follow Step 3 for your selected client'));
        contract("$test_client variable is not token", str_contains($text, 'WPCC_TOKEN is the variable name, never replace it with your token'));
        contract("$test_client browser test limitation", str_contains($text, 'This does not test whether your assistant loaded the server'));
        contract("$test_client test success claim scoped", str_contains($text, 'WPCC can authenticate this token. Server checks passed; verify the connection inside your client.'));
        $cmds = Registry::credential_commands_for($test_client, 'fixture-not-a-secret');
        if ($test_client === 'codex') {
            contract('macOS export', $cmds['macos'] === "export WPCC_TOKEN='fixture-not-a-secret'");
            contract('Codex no launchctl / desktop restart', !str_contains($guided, 'launchctl') && !str_contains($guided, 'restart the app'));
			contract('same terminal explicit', str_contains($guided, 'same private terminal'));
			contract('new terminal explicit', str_contains($guided, 'a new terminal needs this action again'));
			contract('CLI launch step', str_contains($guided, 'Start Codex'));
			contract('CLI launch uses on-request approvals', str_contains($guided, 'codex --ask-for-approval on-request'));
			contract('CLI launch command is copy-ready', str_contains($guided, 'Copy start command'));
			contract('technical detail is collapsed', str_contains($guided, 'Why this step?') && str_contains($guided, 'Why this command?'));
            contract('safe verification', str_contains($guided, 'printenv WPCC_TOKEN >/dev/null'));
            contract('native registration', str_contains(Registry::setup_command_for($test_client), "--bearer-token-env-var 'WPCC_TOKEN'"));
        } else {
            contract('Desktop launchctl preserved', str_starts_with($cmds['macos'], 'launchctl setenv WPCC_TOKEN '));
			contract('Desktop GUI restart remains in Step 3', str_contains($guided, 'Fully quit and reopen the ChatGPT desktop app'));
			contract('Desktop surface distinction appears before setup', str_contains($access_panel->textContent, 'Use Codex mode in the ChatGPT desktop app') && !str_contains($guided, 'Regular ChatGPT chats do not use this local MCP connection'));
            contract('Desktop no CLI instructions', !str_contains($guided, 'same terminal') && !str_contains($guided, 'start Codex'));
        }
        contract("$test_client storage has no raw token", !str_contains(json_encode((new AuthTokens())->list()), $wpcc_new_token));
        global $wpdb;
        $stored_options = $wpdb->get_col("SELECT option_value FROM {$wpdb->options}");
        contract("$test_client options contain no raw token", !str_contains(implode("\n", $stored_options), $wpcc_new_token));
        unset($stored_options);
        $_POST = []; $_REQUEST = [];
        $reloaded = (static function ($template) {
            ob_start(); require $template; return ob_get_clean();
        })($root . '/includes/Admin/views/ai-integrations.php');
        contract("$test_client reload does not reveal token", !str_contains($reloaded, $wpcc_new_token) && !str_contains($reloaded, 'id="wpcc-new-token"'));
        $reload_dom = new DOMDocument(); @$reload_dom->loadHTML($reloaded);
        $reload_xp = new DOMXPath($reload_dom);
        contract("$test_client reload has no credential command", $reload_xp->query('//*[@data-wpcc-credential-command]')->length === 0);
        contract("$test_client reload explains missing secret", str_contains($reloaded, 'Create a new access token to get a copy-ready credential command.'));
        // Retain only redacted rendered output for visual review, never the secret.
        if (getenv('WPCC_UX_RENDER_DIR')) {
            file_put_contents(getenv('WPCC_UX_RENDER_DIR') . '/' . $test_client . '.html', str_replace([$wpcc_new_token, substr($wpcc_new_token, 0, 12)], ['REDACTED_ONE_TIME_TOKEN', 'REDACTED'], $html));
        }
    } finally {
        if (!empty($wpcc_new_record['id'])) { (new AuthTokens())->delete($wpcc_new_record['id']); }
    }
}

// Claude Desktop has no pre-access prerequisite. Its Step 2 intentionally stays quiet,
// while sharing the same spacing rhythm as clients that do need a contextual note.
$_GET = ['client' => 'claude', 'tab' => 'configuration'];
$_POST = []; $_REQUEST = [];
$claude_html = (static function ($template) {
	ob_start(); require $template; return ob_get_clean();
})($root . '/includes/Admin/views/ai-integrations.php');
$claude_dom = new DOMDocument(); @$claude_dom->loadHTML('<?xml encoding="utf-8" ?>' . $claude_html);
$claude_xp = new DOMXPath($claude_dom);
$claude_access = $claude_xp->query('//*[@id="wpcc-create-access"]')->item(0);
contract('claude Step 2 has no artificial Important note', !$claude_xp->query('.//*[contains(concat(" ", normalize-space(@class), " "), " wpcc-selected-client-note ")]', $claude_access)->length);
contract('claude Step 2 keeps the single Create access CTA', $claude_xp->query('.//*[@id="wpcc-tokenmake-open"]', $claude_access)->length === 1);
contract('claude Step 2 uses the shared form spacing', $claude_xp->query('.//*[contains(concat(" ", normalize-space(@class), " "), " wpcc-create-access__form ")]', $claude_access)->length === 1);
contract('claude empty token state keeps its deliberate spacing rule', str_contains($claude_html, '.wpcc-create-access__state { margin:var(--wpcc-space-3,12px)'));
// Synthetic adversarial input: test quoting by executing the generated shell syntax.
// Hash-only subprocess output proves exact round-trip without emitting the value.
$fixture = "quote' double\" dollar\$(printf injected) `printf injected`\nsemi; slash\\ literal\${WPCC_TOKEN}";
foreach (['codex', 'chatgpt'] as $test_client) {
    $command = Registry::credential_commands_for($test_client, $fixture)['macos'];
    $script = $test_client === 'codex'
        ? $command . "\nprintf '%s' \"\$WPCC_TOKEN\" | shasum -a 256\n"
        : "launchctl() { test \"\$1\" = setenv && test \"\$2\" = WPCC_TOKEN || return 2; printf '%s' \"\$3\" | shasum -a 256; }\n" . $command . "\n";
    $proc = proc_open(['/bin/zsh', '-f'], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['file','/dev/null','w']], $pipes);
    fwrite($pipes[0], $script); fclose($pipes[0]);
    $result = stream_get_contents($pipes[1]); fclose($pipes[1]);
    contract("$test_client shell escaping round-trips quotes, substitutions and newlines", proc_close($proc) === 0 && trim($result) === hash('sha256', $fixture) . '  -');
}
echo $GLOBALS['wpcc_ux_pass'] . " passed, 0 failed\n";
