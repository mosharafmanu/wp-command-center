#!/usr/bin/env bash
# Finding D — searching for this product's own words must reach this product.
#
# Two palettes answer ⌘K on a WordPress admin screen:
#
#   1. WordPress's own, in the admin bar, on every screen. It filters by loose
#      subsequence — a query matches if its characters appear in order ANYWHERE in
#      a label — and it does not rank: matches come back in registration order.
#      Action Steward registered nothing with it, so it had no answer of ours
#      to return and filled the list with whatever the subsequence caught:
#      "token" → Go to: Marketing · Marketing > Coupons · Rank Math SEO > …
#      "protection" → nothing at all.
#
#   2. The plugin's own, inside its screens, over AppShell::nav_map().
#
# The matcher in (1) is WordPress's and not ours to replace. What was missing was
# its input: real, exactly-named destinations. CommandPaletteIntegration registers
# one command per screen from the same nav map the plugin's own palette uses.
# Because that palette does not rank, the searchable string per destination is
# kept deliberately short — a long alias list is a longer ladder for an unrelated
# query's letters to climb, and it was measurably enough to make "protection"
# match Diagnostics.
#
# In (2), which we do own, matching is contiguous and ranked by an explicit
# ladder: exact name, exact alias, word-start, prefix, substring, then alias-only.
# No tier matches scattered characters, so the Marketing class of result cannot
# occur there at all.
#
# What is asserted:
#   1-2. The plugin's palette answers every term in the finding with the right
#        destination, ranks product-owned names above alias hits, and rejects
#        both nonsense and subsequence noise. Run against the real scorer,
#        extracted from the shipped file — not a copy of it.
#   3.   Every destination is registered with WordPress's palette, with a search
#        string short enough to stay specific, and routes to the right page+tab.
#   4.   The capability gate and the navigate-only contract.
set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../wpcc-env.sh"
WP_PATH="$SCRIPT_DIR/../../../.."
CDS="$SCRIPT_DIR/../assets/js/wpcc-cds.js"
PAL="$SCRIPT_DIR/../assets/js/wpcc-command-palette.js"
INT="$SCRIPT_DIR/../includes/Admin/CommandPaletteIntegration.php"
PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; if [ "$e" = "$a" ]; then pass "$d"; else fail "$d (expected '$e', got '$a')"; fi; }
assert_true() { local d="$1" a="$2"; if [ "$a" = "true" ]; then pass "$d"; else fail "$d"; fi; }
has()  { local d="$1" p="$2" f="$3"; if grep -qE "$p" "$f"; then pass "$d"; else fail "$d"; fi; }

command -v node >/dev/null 2>&1 || { echo "SKIP: node is required to exercise the palette scorer"; exit 0; }

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

echo "Command palette search relevance (Finding D) — $(date)"
echo ""

# ── The real destination list, from the running site ─────────────────────────
wp eval 'echo wp_json_encode( \WPCommandCenter\Admin\AppShell::nav_map() );' --path="$WP_PATH" 2>/dev/null > "$WORK/nav.json"
NAV_COUNT=$(jq 'length' "$WORK/nav.json" 2>/dev/null || echo 0)
assert_true "nav map has destinations" "$( [ "${NAV_COUNT:-0}" -gt 0 ] && echo true || echo false )"

# ── The real scorer, lifted out of the shipped file ──────────────────────────
# The function body is extracted rather than re-typed, so this suite cannot pass
# against a scorer the product does not actually use.
node -e '
const fs = require("fs");
const src = fs.readFileSync(process.argv[1], "utf8");
const start = src.indexOf("function escapeRe(");
const end   = src.indexOf("function buildPalette(");
if ( start === -1 || end === -1 || end < start ) {
	console.error("EXTRACT_FAILED");
	process.exit(3);
}
fs.writeFileSync(process.argv[2], src.slice(start, end));
' "$CDS" "$WORK/scorer.js" || { echo "  FAIL: could not extract score() from wpcc-cds.js"; exit 1; }

assert_true "score() extracted from the shipped palette" \
	"$( [ -s "$WORK/scorer.js" ] && grep -q 'function score' "$WORK/scorer.js" && echo true || echo false )"

cat > "$WORK/run.js" <<'JS'
const fs = require('fs');
eval( fs.readFileSync( process.argv[2], 'utf8' ) );

const nav = JSON.parse( fs.readFileSync( process.argv[3], 'utf8' ) );
const all = nav.map( function ( i ) {
	return { label: i.label, keywords: ( i.keywords || '' ).toLowerCase(), url: i.url };
} );

// Exactly what renderOpts() does with the scores.
function search( query ) {
	const q = String( query || '' ).toLowerCase().trim();
	if ( ! q ) { return all.slice(); }
	const words = q.split( /\s+/ );
	return all
		.map( ( o, i ) => ( { o: o, s: score( o, words, q ), i: i } ) )
		.filter( r => r.s !== -1 )
		.sort( ( a, b ) => a.s - b.s || a.i - b.i )
		.map( r => r.o );
}

const out = {};
JSON.parse( process.argv[4] ).forEach( function ( q ) {
	const hits = search( q );
	out[ q ] = { count: hits.length, first: hits.length ? hits[0].label : null, url: hits.length ? hits[0].url : null };
} );
process.stdout.write( JSON.stringify( out ) );
JS

QUERIES='["token","tokens","access token","token access","connection","assistant","protection","protection mode","security mode","approval","changes","built-in ai","built-in AI","undo","home","diagnostics","capabilities","marketing","rank math","coupons","zzqqxx","qqq"]'
node "$WORK/run.js" "$WORK/scorer.js" "$WORK/nav.json" "$QUERIES" > "$WORK/res.json" 2>"$WORK/err.txt" \
	|| { echo "  FAIL: scorer run failed: $(cat "$WORK/err.txt")"; exit 1; }

r() { jq -r ".\"$1\".first // \"\"" "$WORK/res.json"; }
n() { jq -r ".\"$1\".count" "$WORK/res.json"; }
u() { jq -r ".\"$1\".url // \"\"" "$WORK/res.json"; }

echo ""
echo "== 1. Every term in the finding reaches its destination, first =="
assert_eq "token"           "Access tokens"     "$(r token | awk -F'› ' '{print $NF}')"
assert_eq "tokens"          "Access tokens"     "$(r tokens | awk -F'› ' '{print $NF}')"
assert_eq "access token"    "Access tokens"     "$(r 'access token' | awk -F'› ' '{print $NF}')"
assert_eq "token access (word order must not matter)" "Access tokens" "$(r 'token access' | awk -F'› ' '{print $NF}')"
assert_true "connection reaches a Connections screen" \
	"$( [[ "$(r connection)" == *"Connections"* ]] && echo true || echo false )"
assert_eq "assistant"       "Assistants"        "$(r assistant | awk -F'› ' '{print $NF}')"
assert_eq "protection"      "Protection"        "$(r protection | awk -F'› ' '{print $NF}')"
assert_eq "protection mode" "Protection"        "$(r 'protection mode' | awk -F'› ' '{print $NF}')"
assert_eq "security mode"   "Protection"        "$(r 'security mode' | awk -F'› ' '{print $NF}')"
assert_eq "approval"        "Approvals"         "$(r approval)"
assert_eq "changes"         "Changes"           "$(r changes)"
assert_eq "built-in ai"     "Built-in AI"       "$(r 'built-in ai' | awk -F'› ' '{print $NF}')"
assert_eq "Built-in AI (case must not matter)" "Built-in AI" "$(r 'built-in AI' | awk -F'› ' '{print $NF}')"
assert_eq "undo — an alias, not a visible name" "Changes" "$(r undo)"

echo ""
echo "== 2. Nothing irrelevant, and nothing scattered =="
# The exact failures the finding reports, from the other palette. Whatever else
# these words do, they must not return a Action Steward screen here.
assert_eq "marketing returns nothing"  "0" "$(n marketing)"
assert_eq "rank math returns nothing"  "0" "$(n 'rank math')"
assert_eq "coupons returns nothing"    "0" "$(n coupons)"
assert_eq "nonsense returns nothing"   "0" "$(n zzqqxx)"
assert_eq "more nonsense returns nothing" "0" "$(n qqq)"

# The ranking ladder itself: an exact name beats a prefix beats an alias.
LADDER=$(node -e '
const fs=require("fs"); eval(fs.readFileSync(process.argv[1],"utf8"));
const changes  = { label: "Changes",           keywords: "history undo rollback" };
const chgArch  = { label: "Changes archive",   keywords: "" };
const archChg  = { label: "Archived changes",  keywords: "" };
const midWord  = { label: "Subchanges",        keywords: "" };
const aliasEx  = { label: "Protection",        keywords: "changes" };
const aliasWord= { label: "Protection",        keywords: "changes archive" };
const aliasSub = { label: "Protection",        keywords: "sitechanges" };
const scattered= { label: "Marketing overview", keywords: "coupons" };
console.log(JSON.stringify({
  exact:      score(changes,   ["changes"], "changes"),
  wordStart:  score(chgArch,   ["changes"], "changes"),
  innerWord:  score(archChg,   ["changes"], "changes"),
  midWordHit: score(midWord,   ["changes"], "changes"),
  aliasExact: score(aliasEx,   ["changes"], "changes"),
  aliasWord:  score(aliasWord, ["changes"], "changes"),
  aliasSub:   score(aliasSub,  ["changes"], "changes"),
  leafExact:  score({label:"Settings › Connections › Access tokens", keywords:""}, ["access","tokens"], "access tokens"),
  subseq:     score(scattered, ["token"],   "token"),
  missingWord:score(changes,   ["changes","nope"], "changes nope")
}));' "$WORK/scorer.js")

l() { echo "$LADDER" | jq -r "$1"; }

assert_eq "an exact name scores best"                  "0"  "$(l '.exact')"
assert_eq "a breadcrumb's own leaf name is also exact" "0"  "$(l '.leafExact')"
assert_true "every label hit ranks below the exact name" \
	"$(l '(.wordStart > .exact) and (.innerWord > .exact) and (.midWordHit > .exact)')"
# "Subchanges" contains the query but starts no word with it: a real hit,
# ranked below one that begins a word.
assert_true "a word-start beats a mid-word hit" "$(l '.wordStart < .midWordHit')"
assert_true "an interior word-start is as good as a leading one" "$(l '.innerWord == .wordStart')"
# An exact alias is a deliberate synonym for this screen, so it outranks a
# partial hit on some other screen's name — but never an exact name.
assert_true "an exact alias ranks below an exact name"   "$(l '.aliasExact > .exact')"
assert_true "an exact alias beats a partial name hit"    "$(l '.aliasExact < .wordStart')"
# Anything softer than an exact alias must stay below every label hit, so a
# keyword can never flood out the screen actually called that.
assert_true "a word-start alias ranks below every label hit" \
	"$(l '(.aliasWord > .wordStart) and (.aliasWord > .innerWord) and (.aliasWord > .midWordHit)')"
assert_true "a substring-only alias ranks last"          "$(l '.aliasSub > .aliasWord')"
assert_eq "scattered characters are NOT a match"       "-1" "$(l '.subseq')"
assert_eq "one unmatched word rejects the whole row"   "-1" "$(l '.missingWord')"

# And structurally: nothing in the scorer walks characters looking for a
# subsequence. Every tier is indexOf, a word-start regex, or equality.
assert_true "no character-by-character subsequence walk in the scorer" \
	"$(grep -qE 'charAt|codePointAt|\.split\( *. *\)\.every|for *\( *var *[a-z] *= *0.*query\.length' "$WORK/scorer.js" && echo false || echo true)"

echo ""
echo "== 3. WordPress's own palette is given real destinations =="
REG=$(wp eval '
$r = new ReflectionClass( \WPCommandCenter\Admin\CommandPaletteIntegration::class );
$m = $r->getMethod( "destinations" ); $m->setAccessible( true );
$d = $m->invoke( null );
$nav = \WPCommandCenter\Admin\AppShell::nav_map();
echo wp_json_encode( [
	"count"      => count( $d ),
	"nav_count"  => count( $nav ),
	"names"      => wp_list_pluck( $d, "name" ),
	"labels"     => wp_list_pluck( $d, "label" ),
	"searches"   => wp_list_pluck( $d, "search" ),
	"urls"       => wp_list_pluck( $d, "url" ),
] );
' --path="$WP_PATH" 2>/dev/null)

assert_eq "one command per navigable destination" "true" \
	"$(echo "$REG" | jq -r '.count == .nav_count and .count > 0')"
assert_eq "command names are unique"              "true" \
	"$(echo "$REG" | jq -r '(.names | unique | length) == .count')"
assert_eq "every command name is namespaced"      "true" \
	"$(echo "$REG" | jq -r 'all(.names[]; startswith("action-steward/"))')"
assert_eq "labels are the screen name, not the breadcrumb" "true" \
	"$(echo "$REG" | jq -r 'all(.labels[]; contains("›") | not)')"
assert_eq "Access tokens is registered"           "true" \
	"$(echo "$REG" | jq -r '(.labels | index("Access tokens")) != null')"
assert_eq "Protection is registered"              "true" \
	"$(echo "$REG" | jq -r '(.labels | index("Protection")) != null')"
assert_eq "Built-in AI is registered"             "true" \
	"$(echo "$REG" | jq -r '(.labels | index("Built-in AI")) != null')"

# Short search strings are the whole defence against a palette that filters by
# subsequence and does not rank. Measured: past four terms, "protection" started
# matching Diagnostics through problem/report/recommendations.
assert_eq "no search string exceeds 4 alias terms" "true" \
	"$(echo "$REG" | jq -r 'all(.searches[]; (split(" ") | length) <= 4)')"
assert_eq "search strings carry no breadcrumb punctuation" "true" \
	"$(echo "$REG" | jq -r 'all(.searches[]; contains("›") | not)')"
assert_eq "the words the finding names are searchable" "true" \
	"$(echo "$REG" | jq -r '
		( [ .labels, .searches ] | flatten | join(" ") | ascii_downcase ) as $hay
		| all( [ "token", "connections", "protection", "mode", "approv", "changes", "ai" ][];
		       ( $hay | contains(.) ) )')"

echo ""
echo "== 4. Selecting a result lands on the right page and tab =="
assert_eq "every command carries an admin URL" "true" \
	"$(echo "$REG" | jq -r 'all(.urls[]; contains("admin.php?page="))')"
assert_eq "Access tokens routes to its own pane" "true" \
	"$(echo "$REG" | jq -r '
		( [ .labels, .urls ] | transpose | map({ (.[0]): .[1] }) | add ) as $m
		| ( $m["Access tokens"] | contains("page=wpcc-settings") and contains("wpcc_tab=connections") and contains("cpane=tokens") )')"
assert_eq "Protection routes to its own tab" "true" \
	"$(echo "$REG" | jq -r '
		( [ .labels, .urls ] | transpose | map({ (.[0]): .[1] }) | add ) as $m
		| ( $m["Protection"] | contains("page=wpcc-settings") and contains("wpcc_tab=security") )')"
assert_eq "Built-in AI routes to its own pane" "true" \
	"$(echo "$REG" | jq -r '
		( [ .labels, .urls ] | transpose | map({ (.[0]): .[1] }) | add ) as $m
		| ( $m["Built-in AI"] | contains("wpcc_tab=advanced") and contains("apane=ai") )')"
# The plugin's palette must route identically — one map, two surfaces.
assert_eq "the plugin's own palette routes to the same URL" "true" \
	"$( [ -n "$(u 'access token')" ] && echo "$REG" | jq -r --arg url "$(u 'access token')" '(.urls | index($url)) != null' )"

echo ""
echo "== 5. Registration is gated, and navigate-only =="
has "same capability as the admin menu"   "CAPABILITY = 'manage_options'"          "$INT"
has "capability is checked before enqueue" "current_user_can\( self::CAPABILITY \)" "$INT"
has "skips installs without the palette"  "wp_script_is\( self::CORE_HANDLE, 'registered' \)" "$INT"
has "commands only change location"       "window.location.href = item.url"        "$PAL"
# Code only — the file's own prose explains that it cannot approve anything, and
# a check that reads the comments would be answering itself.
assert_true "no command executes an operation" \
	"$(sed -E 's#//.*##' "$PAL" | sed -E '/^[[:space:]]*\*/d; /\/\*/,/\*\//d' \
		| grep -qE 'apiFetch|fetch\(|XMLHttpRequest|\.ajax|method:|approve|reject' && echo false || echo true)"
assert_true "the retry loop is bounded"   "$(grep -q 'tries >= 10' "$PAL" && echo true || echo false)"

echo ""
echo "==================================="
echo "  PASSED: $PASS"
echo "  FAILED: $FAIL"
echo "==================================="
[ "$FAIL" -eq 0 ]
