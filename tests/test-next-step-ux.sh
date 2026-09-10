#!/usr/bin/env bash
# Next-step UX — "what do I do now?" must never be a question this product leaves open.
#
# Three moments used to end in a dead stop, and each is guarded here:
#
#   1. A token is created. The finished configuration that contains it renders two
#      panels below the fold (measured: 1679px), and nothing pointed at it.
#   2. A connection is saved. The screen said "Connection created." and the
#      connection itself rendered below the tool switches and the activity feed.
#   3. A customer returns months later. The card said "Healthy" and offered five
#      identically-weighted buttons, answering neither "what is this for?" nor
#      "is anything still using it?".
#
# Plus two real defects found while building the above:
#   4. Every copy button reported success into ONE shared element that lives in the
#      configuration header — so the Copy beside a freshly minted token flashed
#      "Copied!" two panels off screen. And the clipboard promise can settle
#      NEITHER way, so a press could produce no answer at all.
#   5. A smooth scroll is an animation and animations do not always run.
#
# Static verification (same approach as the sibling UX suites) plus a functional
# check that the controller now reports WHICH connection an action applied to.
set -uo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$DIR/.." && pwd)"

ASSIST="$ROOT/includes/Admin/views/ai-integrations.php"
SETUP="$ROOT/includes/Admin/views/ai-setup.php"
CTRL="$ROOT/includes/Admin/ConnectionController.php"
BAI="$ROOT/includes/Admin/BuiltinAiSettings.php"

P=0; F=0
pass(){ P=$((P+1)); echo "  PASS: $1"; }
fail(){ F=$((F+1)); echo "  FAIL: $1"; }
# grep -q on a here-string: under `set -o pipefail` a piped `grep -q` exits on its
# first match, SIGPIPEs the writer, and the pipeline reports failure for a pattern
# it already matched. See RESUME-HANDOFF §8.1.
has(){ if grep -qE -- "$2" "$3"; then pass "$1"; else fail "$1"; fi; }
hasnt(){ if grep -qE -- "$2" "$3"; then fail "$1"; else pass "$1"; fi; }

echo "== 0. Lint =="
for f in "$ASSIST" "$SETUP" "$CTRL" "$BAI"; do
	if php -l "$f" >/dev/null 2>&1; then pass "lint $(basename "$f")"; else fail "lint $(basename "$f")"; fi
done

echo "== 1. Token reveal leads somewhere (ISSUE 1) =="
has "reveal card carries a next-step control"      'id="wpcc-token-next"'                  "$ASSIST"
has "…that points at the configuration section"    '#wpcc-guided-setup'             "$ASSIST"
has "configuration panel is a real anchor target"  'id="wpcc-config-panel"'                "$ASSIST"
has "manual-config fallback carries it too"        'id="wpcc-config-panel"'                "$ASSIST"
has "CTA names the assistant, not 'your assistant'" 'Next: set up %s'      "$ASSIST"
has "progress rail says which step this is"        'wpcc-token-reveal__steps'              "$ASSIST"
has "rail marks the two completed steps"           'Token created'                          "$ASSIST"
has "rail names the remaining step by assistant"   'Set up %s'                          "$ASSIST"
has "jump moves focus, not just the viewport"      "copyBtn.focus"                          "$ASSIST"
has "…and the copy target has an id to focus"      'id="wpcc-copy-config"'                 "$ASSIST"
has "spotlight marks the destination"              'wpcc-spotlight'                         "$ASSIST"
# The anchor must survive JS being off: it is an <a href="#...">, never a bare button.
has "degrades to a plain in-page link"             '<a class="button button-primary" href=' "$ASSIST"
# The hint under the test field is prefilled after creation; it must not tell that
# customer to go and do the step they just finished.
has "test-panel hint is state-aware"               'Your new token is already filled in'    "$ASSIST"

echo "== 2. Connection save reports an outcome (ISSUE 2) =="
has "controller reports which connection acted on" "private function n\( string \\\$type, string \\\$message, string \\\$action = '', string \\\$id = '' \)" "$CTRL"
has "create tags its notice"                       "'create', \\\$id"                       "$CTRL"
has "test tags its notice"                         "'test', \\\$id"                          "$CTRL"
has "view renders an outcome card"                 'wpcc-aip-outcome'                       "$SETUP"
has "outcome names the connection and provider"    'is saved — %2\$s'                       "$SETUP"
has "outcome branch: no key yet"                   'It has no API key yet'                  "$SETUP"
has "outcome branch: untested"                     'Test it once to confirm the key works'  "$SETUP"
has "outcome branch: tools all off"                'are still switched off for this site'   "$SETUP"
has "outcome branch: nothing left to do"           'Nothing else to set up'                 "$SETUP"
has "test is one click from the outcome card"      'name="wpcc_conn_action" value="test" class="button button-primary"' "$SETUP"
has "outcome links to the connection itself"       'Show the connection'                    "$SETUP"
# The outcome card must not be shown for a FAILED create, or for someone else's action.
has "outcome gated on success"                     "'success' === \\\$wpcc_notice\['type'\]" "$SETUP"
# The gate covers the WHOLE journey, not just its first step. Creating, keying and
# testing each used to end in a bare notice; `test` is the one that matters most,
# because it is the step the customer reaches having done everything right.
has "outcome covers create, key and test"          "in_array\( \\\$wpcc_outcome_action, \[ 'create', 'update_key', 'test' \], true \)" "$SETUP"
has "…and the headline reports which of them"      "'“%1\\\$s” is working — %2\\\$s'"        "$SETUP"
has "…including the key-saved wording"             "has its API key"                        "$SETUP"

echo "== 2b. A working connection says what it unlocks (ISSUE 2b — the dead end) =="
# create → key → test → healthy → *nothing*. This is the moment the brief calls
# out: the customer has done everything right and the product stops talking.
has "post-success section exists"                  'wpcc-aip-uses'                          "$SETUP"
has "…and is gated on a PROVEN connection"         'wpcc_o_proven'                          "$SETUP"
has "…proven means a real health state, not 'I just pressed test'" \
                                                   "\\\$wpcc_o_proven = in_array\( \\\$wpcc_o_health\['state'\], \[ 'healthy', 'slow' \], true \)" "$SETUP"
has "way 1: built-in AI"                           "'Built-in AI'"                          "$SETUP"
has "way 2: the WPCC AI row action"                'WPCC AI on your posts and pages'        "$SETUP"
has "way 3: external assistants"                   'Claude, ChatGPT and other assistants'   "$SETUP"
# Way 2 is the undiscoverable one, and it is CONDITIONAL: AiActionRegistry only
# adds the row action when the tool behind it is on. Promising the menu on a site
# where it is switched off would replace one dead end with a worse one.
has "row action derived from the real flags"       'wpcc_editor_actions'                    "$SETUP"
has "…from Content (title + excerpt)"              "isset\( \\\$wpcc_tools_on\['content'\] \)" "$SETUP"
has "…and from SEO"                                "isset\( \\\$wpcc_tools_on\['seo'\] \)"  "$SETUP"
has "…and says how to make it appear when it is off" 'that switch is what puts it there'    "$SETUP"
# Alt Text is a MEDIA row action; it never appears on a post or page.
hasnt "alt text is not promised on posts/pages"    'Alt text.*hover any row'                "$SETUP"
has "approval promise stated where it is needed"   'a draft you review before anything changes' "$SETUP"
# Way 3 does NOT run on the provider key. Saying otherwise is the exact false
# mental model the 'what happens next' block already exists to correct.
has "assistants marked as not using this key"      'not this provider key'                  "$SETUP"
has "assistant state is real, not assumed"         'wpcc_assistant'                         "$SETUP"
has "…read from the product's own answer"          'ConnectionStatus::get\(\)'              "$SETUP"
# Never advertise a tool that is switched off (the operation-catalogue defect).
has "built-in AI line names only the tools that are ON" 'tool is on and generates through'  "$SETUP"
hasnt "…and never hardcodes all three as running"  'runs through this connection — generating' "$SETUP"
has "tool list is locale-aware, not comma-glued"   "wp_sprintf_l\( '%l', array_values\( \\\$wpcc_tools_on \) \)" "$SETUP"

echo "== 2c. Home tells a returning customer what THIS site is doing (PART 3) =="
HOME="$ROOT/includes/Admin/views/command-home.php"
if php -l "$HOME" >/dev/null 2>&1; then pass "lint command-home.php"; else fail "lint command-home.php"; fi
has "cards carry a state pill"                     'wpcc-home__also-state'                  "$HOME"
has "built-in AI state resolved from the real flags" 'wpcc_home_tools_on'                   "$HOME"
has "…via the one precedence helper"               'BuiltinAiSettings::is_on'               "$HOME"
has "state: how many of how many are on"           "'%1\\\$s of %2\\\$s tools on'"          "$HOME"
has "state: key present but nothing switched on"   "'Provider ready · %s'"                  "$HOME"
has "state: switched on but cannot generate"       'Needs a provider key'                   "$HOME"
has "state: nothing set up"                        "'Not set up'"                           "$HOME"
has "each state carries its OWN next action"       'Turn on a tool →'                       "$HOME"
has "…counted as usable, not merely 'active'"      "wpcc_conn\['active_tokens'\]"           "$HOME"
has "…and asks for one when there are none"        'Create an access token →'               "$HOME"
# Real state only — no invented metrics anywhere on Home.
hasnt "no fabricated percentages on the cards"     'wpcc-home__also-state[^>]*>[^<]*%'      "$HOME"

echo "== 2d. Home: which assistant, and how much can it do? =="
CSTAT="$ROOT/includes/Admin/ConnectionStatus.php"
if php -l "$CSTAT" >/dev/null 2>&1; then pass "lint ConnectionStatus.php"; else fail "lint ConnectionStatus.php"; fi
# "Assistant connected" does not say connected to WHAT. The label of the token that
# last authenticated does — from records get() already reads.
has "status reports the last-used token's name"    'last_label'                             "$CSTAT"
has "…captured at the same time as the timestamp"  "\\\$last_label = \(string\) \( \\\$token\['label'\] \?\? '' \)" "$CSTAT"
has "…and the strip shows it"                      "wpcc_conn\['last_label'\]"              "$HOME"
# It is the customer's own label, never a sniffed client.
hasnt "no user-agent sniffing introduced"          'user_agent|HTTP_USER_AGENT'             "$CSTAT"
# THE SAFETY ONE: "3 active tokens" under a heading reading "Read-only access", on a
# site where every token is full access, tells the customer their site is safer than
# it is. Scope has always been stored; it is now counted and stated.
has "read-only tokens counted separately"          'read_only_tokens'                       "$CSTAT"
has "…using the scope that is actually stored"     'AuthTokens::SCOPE_READ_ONLY'            "$CSTAT"
has "card states the real composition"             'active tokens · %2\$s read-only'        "$HOME"
has "…and says so when NONE are read-only"         'all full access'                        "$HOME"
# The counted noun must survive: under a "Read-only access" heading, "3 active" with
# no noun is what lets the number read as "3 read-only assistants".
has "…and always names what is being counted"      'active token'                           "$HOME"
has "…and then offers the thing the card is about" 'Add a read-only token →'                "$HOME"
hasnt "never a bare count under that heading"      "_n\( '%s active token', '%s active tokens'" "$HOME"

echo "== 2e. Home: every card states, explains, then acts (consistency) =="
# The third card described a feature and stopped — the odd one in a row of three.
has "undo card has a state slot"                   'wpcc-home-undo-state'                   "$HOME"
has "…filled from the dashboard read already in flight" 'renderUndoState'                   "$HOME"
has "…from a real recorded count"                  'hist.changes'                           "$HOME"
has "…hidden until it has an answer"               'el.hidden = false'                      "$HOME"
has "…and left hidden on a gated response"         "typeof hist.changes !== 'number'"       "$HOME"
# The pill's own !important would otherwise beat the UA [hidden] rule.
has "hidden beats the pill's !important display"   'wpcc-home__also-state\[hidden\]'        "$HOME"

echo "== 2f. Cross-screen CTAs land, and say they landed =="
has "Home points at the switches, not the page top" '#wpcc-bai-tools-h'                     "$HOME"
has "receiving screen honours an incoming hash"    'window.location.hash.slice\( 1 \)'      "$SETUP"
has "…moves keyboard focus to the target"          "hashed.focus\( \{ preventScroll: true \} \)" "$SETUP"
has "…makes a heading focusable to do it"          "setAttribute\( 'tabindex', '-1' \)"     "$SETUP"
has "…and rings the card, not the heading text"    "hashed.closest\( '.wpcc-cds-card, .wpcc-aip-card' \)" "$SETUP"

echo "== 3. Returning-user connection cards (ISSUE 3) =="
has "cards are addressable"                        'id="wpcc-conn-<\?php echo esc_attr\( \$cid \)'  "$SETUP"
has "card says what it powers"                     "__\( 'Powers %s'"                       "$SETUP"
has "…and says so when it powers nothing"          'Not powering any tool'                  "$SETUP"
has "card says when it was last used"              'last used %1\$s ago'                    "$SETUP"
has "…and says so when it never has"               'not used yet'                           "$SETUP"
# "never used to generate" parses in English as "it used to generate, and stopped".
hasnt "no ambiguous 'never used to' phrasing"      'never used to generate'                 "$SETUP"
has "usage read from the existing ledger"          'UsageLedger::read\(\)'                  "$SETUP"
has "…folded by connection id"                     'wpcc_conn_usage'                        "$SETUP"
has "unattributable usage is skipped, not guessed" "if \( '' === \\\$wpcc_bcid \)"          "$SETUP"
has "the action to take is emphasised"             'wpcc-aip-todo'                          "$SETUP"
has "one recommended control per card"             'wpcc_needs_test'                        "$SETUP"
has "…including 'add a key' when that is the gap"  'wpcc_needs_key'                         "$SETUP"
has "destructive actions behind a disclosure"      'wpcc-aip-more'                          "$SETUP"
# Demoted, never removed — and the delete confirmation keeps everything it had.
has "Duplicate still reachable"                    'value="duplicate"'                      "$SETUP"
has "Delete still reachable"                       'value="delete"'                         "$SETUP"
has "delete still names the routed tools"          'data-routed'                            "$SETUP"
has "delete still uses the product's own dialog"   'wpcc-conn-confirm'                      "$SETUP"

echo "== 4. Copy buttons always answer (DEFECT) =="
has "confirmation happens on the pressed button"   'wpccRestore'                            "$ASSIST"
has "shared feedback span only when it is a sibling" 'btn.parentNode.querySelector'         "$ASSIST"
has "a refused clipboard write is reported"        'FAILED'                                 "$ASSIST"
# The clipboard promise can settle neither way (measured). A press must still answer.
has "a promise that never settles still answers"   'if \(!answered\)'                       "$ASSIST"
has "…via the synchronous fallback"                'function legacyCopy'                    "$ASSIST"
has "double-press cannot capture 'Copied' as the label" 'btn.dataset.wpccRestore === undefined' "$ASSIST"
hasnt "no lone global feedback lookup remains"     "getElementById\('wpcc-copy-feedback'\)" "$ASSIST"

echo "== 5. Scrolling cannot fail silently (DEFECT) =="
has "assistants: smooth scroll arrival is verified"  'r.top > vh \|\| r.bottom < 0'         "$ASSIST"
has "built-in AI: smooth scroll arrival is verified" 'r.top > vh \|\| r.bottom < 0'        "$SETUP"
has "assistants: reduced motion respected"         'prefers-reduced-motion'                 "$ASSIST"
has "built-in AI: reduced motion respected"        'prefers-reduced-motion'                 "$SETUP"
has "a plain visit is never scrolled"              'if \( acted \)'                         "$SETUP"

echo "== 5b. Saving with nothing changed still says something =="
# "0 feature routes saved." reports a number and communicates nothing.
has "zero-change routing has its own message"      'Routing is unchanged'                   "$CTRL"
hasnt "…and never announces a count of zero"       "_n\( '%d feature route saved.*\\\$changed\b.*0" "$CTRL"

echo "== 6. Enabling a tool names where it went =="
has "tool-on message points at the new tab"        'open the %2\$s tab above to use it'     "$BAI"
has "provider-less branch still names its step"    'Connect an AI provider to start generating' "$BAI"

echo "== 7. Nothing behavioural was traded for this =="
# The notice envelope grew two keys; the two every caller reads are untouched.
has "notice keeps type"                            "'type'       => \\\$type"               "$CTRL"
has "notice keeps message"                         "'message'    => \\\$message"            "$CTRL"
has "controller still nonce-checks"                "check_admin_referer\( self::NONCE \)"   "$CTRL"
has "controller still capability-checks"           "current_user_can\( 'manage_options' \)" "$CTRL"
has "create still audits"                          'ai.connection.created'                  "$CTRL"
# No key may reach the page through any of the new reporting.
hasnt "outcome card never echoes a secret"         'echo .*(wpcc_key|->secret\()'          "$SETUP"

echo "== 8. Functional: the controller reports the connection it acted on =="
WP_PATH="${WP_PATH:-$ROOT/../../..}"
if command -v wp >/dev/null 2>&1 && wp --path="$WP_PATH" eval 'echo 1;' >/dev/null 2>&1; then
	OUT="$( wp --path="$WP_PATH" eval '
		wp_set_current_user( 1 );
		$store  = new \WPCommandCenter\Ai\Platform\ConnectionStore();
		$before = array_keys( $store->all() );
		$def0   = $store->default_id();
		$_POST = [
			"wpcc_conn_action" => "create",
			"wpcc_provider"    => "anthropic",
			"wpcc_name"        => "ZZ NextStep Suite",
			"wpcc_model"       => "custom",
			"wpcc_model_custom"=> "claude-sonnet-4-20250514",
			"_wpnonce"         => wp_create_nonce( \WPCommandCenter\Admin\ConnectionController::NONCE ),
		];
		$_REQUEST = $_POST;
		$n = ( new \WPCommandCenter\Admin\ConnectionController() )->handle_post();
		echo "type="   . ( $n["type"] ?? "" ) . "\n";
		echo "action=" . ( $n["action"] ?? "" ) . "\n";
		echo "hasid="  . ( ! empty( $n["connection"] ) ? "yes" : "no" ) . "\n";
		echo "resolves=" . ( isset( ( new \WPCommandCenter\Ai\Platform\ConnectionStore() )->all()[ $n["connection"] ?? "" ] ) ? "yes" : "no" ) . "\n";
		echo "msgclean=" . ( false === strpos( wp_json_encode( $n ), "sk-" ) ? "yes" : "no" ) . "\n";
		// leave the site exactly as it was found
		$s2 = new \WPCommandCenter\Ai\Platform\ConnectionStore();
		foreach ( $s2->all() as $cid => $c ) { if ( ! in_array( $cid, $before, true ) ) { $s2->delete( $cid ); } }
		$s3 = new \WPCommandCenter\Ai\Platform\ConnectionStore();
		if ( "" !== $def0 && $s3->default_id() !== $def0 ) { $s3->set_default( $def0 ); }
		echo "restored=" . ( count( ( new \WPCommandCenter\Ai\Platform\ConnectionStore() )->all() ) === count( $before ) ? "yes" : "no" ) . "\n";
	' 2>/dev/null )"
	g(){ grep -E "^$1=" <<<"$OUT" | cut -d= -f2; }
	[ "$(g type)"     = "success" ] && pass "create returns success"            || fail "create returns success (got '$(g type)')"
	[ "$(g action)"   = "create"  ] && pass "create tags the action"            || fail "create tags the action (got '$(g action)')"
	[ "$(g hasid)"    = "yes"     ] && pass "create returns a connection id"    || fail "create returns a connection id"
	[ "$(g resolves)" = "yes"     ] && pass "…and it resolves to a real connection" || fail "id does not resolve"
	[ "$(g msgclean)" = "yes"     ] && pass "notice envelope carries no key material" || fail "notice envelope leaked key material"
	[ "$(g restored)" = "yes"     ] && pass "site state restored by the suite"  || fail "site state NOT restored"
else
	echo "  SKIP: wp-cli unavailable — static checks only"
fi

echo
# Wording is load-bearing. tests/run.sh tallies a suite by grepping its output for
# "<n> passed" / "<n> failed" and IGNORES the exit code entirely — so a summary in
# any other shape reports zero of both, and a suite that fails is counted as a
# suite with nothing in it. This line is the only thing that puts these checks on
# the tier scoreboard, and the only thing that lets them fail a tier.
echo "== Summary =="
echo "  $P passed, $F failed"
[ "$F" -eq 0 ] || exit 1
