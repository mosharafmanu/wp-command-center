> # SUPERSEDED — HISTORICAL RECORD ONLY
>
> This document is **not current** and must not be used to verify, build, or ship
> anything. It is kept because it records what was true when it was written.
>
> **The single authoritative engineering document is [`RELEASE_HANDOFF.md`](../../../RELEASE_HANDOFF.md) at the repository root.**
>
> Superseded on 2026-08-10. Reason: point-in-time UX report for work that has shipped

---

# Home Dashboard — Final UX Polish (pre-WordPress.org)

No new features. No redesign. Three cards, one status strip, and the CTA that connects
them — made to state what **this site** is doing rather than what the plugin can do.

---

## 1. The finding that mattered most

> The "Read-only access" card said **"3 active tokens"**. All three were **full access**.

The card is titled *Read-only access* and its body promises a token that "can never change
anything". A bare count under that heading reads as *three read-only assistants*. On the
site this was written against, the truth was the exact opposite.

This is not a wording nit. It is the one direction a safety-adjacent card must never be
wrong in: it invites a customer to believe their site is **safer than it is**. `scope` has
always been stored on every token record — it had simply never been read.

**After:** `3 active tokens · all full access`, and the next action becomes
**"Add a read-only token →"** — the thing the card is actually about — instead of
"Manage access tokens", a filing verb offered to someone with nothing to file.

Deliberately *not* coloured amber. Full access is a supported, deliberate choice the
product offers; stating it is honest, painting it as a fault is editorialising.

---

## 2. Before / After

| Element | Before | After |
|---|---|---|
| **Assistant** (status strip) | `Assistant connected` · "Last request 33 minutes ago." | `Assistant connected` · **"Claude Desktop · Last request 33 minutes ago."** |
| **Built-in AI** pill (nothing on) | `Key ready · no tools on` | **`Provider ready · 0 of 3 tools on`** |
| **Built-in AI** pill (some on) | `SEO and Content on` | **`2 of 3 tools on`** |
| **Built-in AI** CTA target | top of a long screen | **`#wpcc-bai-tools-h`** — the switches, focused + ringed |
| **Undo any change** | *(no state at all)* | **`11,066 changes recorded`** / `Nothing to undo yet` |
| **Read-only access** pill | `3 active tokens` | **`3 active tokens · all full access`** |
| **Read-only access** CTA | `Manage access tokens →` | **`Add a read-only token →`** (when none are read-only) |

---

## 3. Each improvement, and why it helps

### Task 3 — "Which assistant?"

`ConnectionStatus::get()` already scanned every usable token to find the newest
`last_used_at`. It now keeps the **label of that token** at the same time — one extra
assignment inside a loop that was already running.

Because the token-creation form pre-fills the label with the assistant the customer
picked, in practice this reads **"Claude Desktop"**. It is reported as *the name they
gave*, never as a detected client — nothing sniffs a user agent, and a token named
"staging laptop" shows "staging laptop", which is the truthful answer to *which one of
mine is that?*

I did **not** add a "Healthy" badge. The strip already carries a status dot and the words
"Assistant connected"; a third element saying the same thing is the duplicated information
Task 8 rules out.

### Task 4 — "0 of 3 tools on"

`no tools on` said nothing was running. `0 of 3` says nothing is running **and that there
are three to choose from** — the second half is the invitation, and the old wording threw
it away. `Provider ready · 0 of 3 tools on` states both halves, so a customer is never
left wondering why a "ready" provider produces no output.

**A considered trade:** the on-state loses the tool *names* (`SEO and Content on` →
`2 of 3 tools on`). Home answers "is it working, is there more available?"; "which ones"
is answered on the screen where you act on them, one click away. Repeating the names here
would be duplication.

### Task 5 — the counted noun stays in

`3 active` drops the thing being counted, and under a heading reading "Read-only access"
the missing noun is precisely the word that stops the number reading as "3 read-only
assistants". Rejected `3 assistants connected` outright: tokens are not connections — a
token that has never been used would be counted as a live assistant.

Kept the product's own vocabulary ("access token"). Switching to "key" would collide with
**AI provider key**, a genuinely different thing on the adjacent card.

### Task 6 — CTAs that land *and say so*

"Turn on a tool" now points at `#wpcc-bai-tools-h` — the switches, which sit below a hero,
a two-path explainer and the provider cards. The receiving screen honours an incoming hash
on load: it moves keyboard focus to the target (adding `tabindex="-1"` so a heading can
take it) and spotlights it with the **existing** helper.

The ring is drawn on the **containing card**, not the heading: a box-shadow around a single
line of text reads as a rendering fault, not as "here". No scrolling of our own — the
browser has already done it, and doing it twice makes the page lurch.

### Task 7 — consistency

"Undo any change" was the only card with no state — a feature description in a row of two
status cards. The honest count sits behind a `COUNT(*)`, and adding one to every Home
render to fill a pill is the wrong trade.

It doesn't need one: **`change_history.changes` is already in the `/admin/dashboard`
response** this page fetches for the activity list. The number was on the wire and never
read out. Filled by script, hidden until it has a real answer, and left hidden on a gated
response — a pill reading "0" because a permission check failed is a fact about the
*reader*, not about the site.

All three cards now follow: **state → one-line explanation → one next action.**

### A bug I introduced and caught

The pill uses `display:inline-block !important` (needed to beat the generic
`.wpcc-home__also-card span` block rule). That **also beats the UA stylesheet's
`[hidden] { display:none }`**, which is not `!important` — so the script-filled pill would
have rendered as an empty chip before its data arrived. Fixed with a matching-weight
`[hidden]` rule and verified in the browser (`display when hidden = none`).

### Considered and deliberately NOT changed

`wpcc-bai-noticeslot` leaves a 46px gap above the tool switches when empty. It looks like
a defect and is not: the slot is **reserved on purpose** so nothing shifts when a toggle
notice appears. Its own comment says so. Left alone.

---

## 4. Files changed

| File | Change |
|---|---|
| `includes/Admin/ConnectionStatus.php` | `last_label` + `read_only_tokens`, both derived from records already read |
| `includes/Admin/views/command-home.php` | assistant name in strip; `X of 3` pill + hash target; undo pill (server slot + JS + strings); read-only composition + CTA; `[hidden]` fix |
| `includes/Admin/views/ai-setup.php` | honours an incoming `#hash`: focus + spotlight the card |
| `tests/test-next-step-ux.sh` | 108 → **130** assertions |
| `tests/regression-map.tsv` | routes the new surfaces to that suite |

No route, capability, operation, MCP tool, schema, option, or write path added or altered.
`ConnectionStatus::get()` gained two **additive** keys; both existing consumers are
unaffected.

---

## 5. Manual validation

| State | Result |
|---|---|
| Returning user (3 tokens, last used hours ago) | ✅ names the token that called |
| AI configured, no tools on | ✅ `Provider ready · 0 of 3 tools on` → "Turn on a tool →" |
| AI enabled (SEO + Content on) | ✅ `2 of 3 tools on` → "Open Built-in AI →" |
| Multiple assistant tokens (3) | ✅ `3 active tokens · all full access` → "Add a read-only token →" |
| Undo state | ✅ `11,066 changes recorded`, hidden until data arrives |
| CTA → Built-in AI switches | ✅ lands on `#wpcc-bai-tools-h`, focus on heading, ring on section |
| Pill hidden-state | ✅ `display:none` confirmed |

### The two harder states — reproduced safely after T2

Rather than leave these on inspection, both were produced live by a **reversible option
round-trip**: the provider key was copied to a temporary option, blanked, observed, then
written back and the temporary option deleted. The secret never left the database and was
never printed.

| State | Result |
|---|---|
| No AI provider configured | ✅ `Not set up` → "Set up Built-in AI →", and **no** `#hash` on the link (correct — the deep link is only offered when a key exists) |
| Tool on, no provider key | ✅ `Needs a provider key` (amber, `rgb(252,243,227)`) → "Add a provider key →" |

**Restore verified by evidence, not assumption:** after writing the key back, a real
connection test through the UI returned **"'Anthropic API' is working"** — a live provider
call, which only succeeds if the credential came back byte-exact. Final state confirmed:
`mode=client`, `tools=[]`, `key_source=anthropic_option`, temporary option absent,
2 connections, default unchanged.

**Still not reproduced live: first-time install.** It requires no usable token, and tokens
can be revoked but never un-revoked — the raw secret is shown once. Producing that state
on a real site destroys credentials. The setup branch is also **unchanged by this pass**,
so there is nothing new in it to regress. I would rather say so than claim the coverage.

---

## 6. Regression results

| Tier | Result |
|---|---|
| **T0** | **788 passed, 0 failed** · net-new 0 · 98s (13 suites) |
| **T1** | **1228 passed, 0 failed** · net-new 0 · 426s (28 suites) |
| **T2** | **7120 passed, 0 failed** · net-new 0 · 3264s (196 suites) |
| `test-next-step-ux.sh` | **130 passed, 0 failed** (108 → 130) |
| Focused suites | `ai-integration-ux` 60/0 · `ai-platform-ux-6s` 46/0 · `connection-state-truthfulness` 23/0 · `connection-delete-and-toggle-ux` 45/0 |

T0/T1 were run in `developer` mode per `RESUME-HANDOFF.md` §7; Standard restored after.

### The harness finding is now reproducible

The previous pass noted that T2 left the site in `developer` mode after a green run. **It
did so again** — 2 of 2 successful runs. That makes it a systematic defect in
`tests/run.sh`, not a fluke: `GOV_RESTORE` swallows all output (`>/dev/null 2>&1`), so a
failed or slow `wp eval` restores nothing and reports nothing, and the site is left in the
**self-approving** mode silently.

Not a product defect, and deliberately still not fixed here — fixing the harness after the
certification run would invalidate it. But it should be fixed before anyone runs T2
against a site that matters.

---

## 7. Final recommendation

### ✅ SHIP

All gates green against a frozen tree, and the highest T2 this project has recorded
(7120, up from 7098 last pass and 6989 before it), with zero net-new failures.

The change remains **presentation-only**: no route, capability, operation, MCP tool,
schema, option, or write path added or altered. `ConnectionStatus::get()` gained two
additive keys; both existing consumers are unaffected. Every number on the dashboard is
read from state the product already had — the recurring theme of this pass is that the
data was always there and simply was not being shown.

**One item is worth treating as more than polish:** the read-only/full-access correction.
Everything else here reduces friction; that one corrected a card that told customers their
site was safer than it was.

Unchanged from the previous pass: the tree is still uncommitted, so `RESUME-HANDOFF.md`
§2/§4 package identity is stale again. I have not committed, tagged, pushed, or rebuilt.
