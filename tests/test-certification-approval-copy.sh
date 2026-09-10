#!/usr/bin/env bash
# Finding B: customer-facing approval promises must match the three real modes.
# Pure PHP rendering + copy-contract checks; no WordPress DB, tokens or history writes.
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WPCC_COPY_PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)" php <<'PHP'
<?php
use WPCommandCenter\Operations\SecurityModeManager as Mode;

$root = getenv('WPCC_COPY_PLUGIN_DIR');
define('ABSPATH', $root . '/');
// Only the WordPress presentation/option primitives are stubbed. Production mode
// policy and wording execute unchanged against each selected protection mode.
$mode = 'client';
function get_option($key, $default = false) { return $key === 'wpcc_security_mode' ? $GLOBALS['mode'] : $default; }
function __($text, $domain = '') { return $text; }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_attr($text) { return esc_html($text); }
function esc_url($text) { return esc_html($text); }
function esc_html__($text, $domain = '') { return esc_html($text); }
function esc_html_e($text, $domain = '') { echo esc_html($text); }
function admin_url($path = '') { return 'https://example.invalid/wp-admin/' . $path; }
function wp_json_encode($value) { return json_encode($value); }
function wp_nonce_field($action) { echo '<input type="hidden" name="_wpnonce" value="test">'; }
function checked($actual, $expected = true) { if ($actual === $expected) echo 'checked="checked"'; }
function submit_button($text) { echo '<button>' . esc_html($text) . '</button>'; }
require $root . '/includes/Operations/SecurityModeManager.php';
require $root . '/includes/Admin/AgentExplainer.php';

$pass = 0; $fail = 0;
function check($name, $ok) {
    global $pass, $fail;
    $ok ? ++$pass : ++$fail;
    echo ($ok ? '  PASS: ' : '  FAIL: ') . $name . PHP_EOL;
}
function absolute_promise($text) {
    return (bool) preg_match('/(?:every|all|any) (?:single )?changes? (?:[^.!?]{0,65})(?:waits?|approved)|anything that (?:would )?changes? (?:the )?site (?:[^.!?]{0,40})(?:waits?|approval)|nothing is actually changed until|never change anything on its own|nothing it asks for is applied on its own/i', $text);
}
function immediate($text) { return (bool) preg_match('/straight (?:away|through)|immediat|instantly/i', $text); }

foreach (['client', 'enterprise', 'developer'] as $mode) {
    $copy = [Mode::describe($mode), Mode::promise(), Mode::approval_step()];
    foreach ($copy as $i => $text) {
        if ($mode === 'client') {
            check("Standard wording $i names higher-impact approval", stripos($text, 'higher-impact') !== false && stripos($text, 'approval') !== false);
            check("Standard wording $i explains immediate low-risk work", stripos($text, 'low-risk') !== false && immediate($text));
            check("Standard wording $i has no absolute approval promise", !absolute_promise($text));
        } elseif ($mode === 'enterprise') {
            check("Strict wording $i still gates every change", stripos($text, 'every change') !== false && stripos($text, 'waits') !== false);
        } else {
            check("Development wording $i explicitly describes immediate work", immediate($text));
            check("Development wording $i does not promise approval", !absolute_promise($text));
        }
    }
    check("$mode copy matches actual low-risk gate", Mode::requires_approval('low') === ($mode === 'enterprise'));
    check("$mode copy matches actual higher-impact gate", Mode::requires_approval('high') === ($mode !== 'developer'));
    check("$mode preserves instant diagnostic reads", !Mode::requires_approval('diagnostic'));
    if ($mode !== 'developer') {
        check("$mode approval empty state only promises review of requests that need it", str_contains(Mode::approvals_empty_detail(), 'Requests that need approval'));
        check("$mode queue subtitle scopes its promise to these requests", str_contains(Mode::approvals_desc(), 'These requests'));
    } else {
        check('Development warning remains explicit', str_contains(Mode::dev_warning(), 'no approval step') && str_contains(Mode::dev_warning(), 'live site'));
        check('Development keeps previously queued requests visible', str_contains(Mode::approvals_desc(), 'queued before you switched'));
    }
}

// Render the actual three-card comparison. Assertions are scoped to the card a
// customer reads, so truthful Strict wording cannot mask a false Standard claim.
$mode = 'client'; $_POST = [];
ob_start(); require $root . '/includes/Admin/views/settings.php'; $html = ob_get_clean();
preg_match_all('/<label class="wpcc-prot__card[^>]*>(.*?)<\/label>/s', $html, $cards);
$by_mode = [];
foreach ($cards[1] as $card) {
    preg_match('/name="wpcc_security_mode" value="([^"]+)"/', $card, $key);
    $by_mode[$key[1] ?? ''] = html_entity_decode(strip_tags($card), ENT_QUOTES, 'UTF-8');
}
check('Protection comparison renders all three modes', count($by_mode) === 3);
check('Standard card says low-risk work runs instantly', str_contains($by_mode['client'] ?? '', 'low-risk') && immediate($by_mode['client'] ?? ''));
check('Standard card has no absolute approval promise', !absolute_promise($by_mode['client'] ?? ''));
check('Strict card retains every-change approval', str_contains($by_mode['enterprise'] ?? '', 'Every change waits for approval'));
check('Development card retains production warning', str_contains($by_mode['developer'] ?? '', 'Never use this mode on a live production website'));
check('Development switch confirmation explicitly removes review', str_contains($html, 'This turns off the approval step') && str_contains($html, 'with no review'));

// Scan actual PHP string tokens across shipped runtime (comments cannot satisfy
// or fail the assertions). Mode-specific helpers and comparison cards were
// executed above; they legitimately contain Strict-only every-change wording.
$bad = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/includes'));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $relative = substr($file->getPathname(), strlen($root) + 1);
    if (in_array($relative, ['includes/Operations/SecurityModeManager.php', 'includes/Admin/views/settings.php'], true)) continue;
    foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
        if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) continue;
        // PHP's tokenizer guarantees this is one quoted constant, not code.
        $text = eval('return ' . $token[1] . ';');
        if (absolute_promise($text)) $bad[] = $relative . ':' . $token[2];
    }
}
check('Shipped runtime/onboarding has no unqualified every-change approval promise', !$bad);
foreach ($bad as $location) echo "    Contradictory copy at $location\n";

$readme = file_get_contents($root . '/readme.txt');
preg_match('/= Can the AI change my site without asking\? =\s*(.*?)(?=\n=)/s', $readme, $faq);
check('Published FAQ does not deny Standard low-risk execution', !str_contains($faq[1] ?? '', 'Not in the default setting') && str_contains($faq[1] ?? '', 'low-risk'));
check('Published install step states the low-risk exception', (bool) preg_match('/1\. Install and activate\.[^\n]*low-risk[^\n]*immediately/', $readme));
check('Published FAQ preserves Strict and Development distinction', str_contains($faq[1] ?? '', 'Strict approval gates every change') && str_contains($faq[1] ?? '', 'Development setting that removes the approval step'));
$explainer = WPCommandCenter\Admin\AgentExplainer::faq();
check('General assistant explanation makes approval conditional on policy', str_contains($explainer[3]['a'], 'when your protection mode requires it'));
check('General assistant flow makes approval conditional', str_contains(WPCommandCenter\Admin\AgentExplainer::flow_line(), 'approval when required'));
echo "Certification approval copy: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
PHP
