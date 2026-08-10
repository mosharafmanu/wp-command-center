# WP Command Center v1.0.0 — Final UX Journey Polish

**Objective:** eliminate every "Now what?" moment. Not prettier UI — fewer dead ends.

---

## 0. Starting position (important for attribution)

The working tree was **not clean** when this pass began. A previous session had already
landed a first wave of this brief — the token-reveal progress rail and its "Next" CTA,
the connection-card rewrite (what it powers / last used / More disclosure), the copy-button
confirmation fixes, and the notice envelope that lets a view know *which* connection an
action applied to. That work is present in `ai-integrations.php`, `BuiltinAiSettings.php`,
and parts of `ConnectionController.php` / `ai-setup.php`, and its suite stood at **73/0**.

This report covers the whole deliverable but marks what **this** session added.

---

## 1. UX problems discovered

### P1 — A successful connection test was a dead end *(the brief's headline)*

The journey `create → add key → test → healthy` ended with the word **"Connection succeeded."**
and nothing else. This is the worst possible place to stop talking: it is the moment the
customer reaches having done *everything right*. Three surfaces had just become live on
their site and the product mentioned none of them.

### P2 — The editor integration is undiscoverable *and conditional*

"✨ WPCC AI" is a **row action on the Posts/Pages list screens** (`AiAssistRowActions`), not
a block-editor sidebar. Nothing in the product ever announced it.

The deeper problem: it is **conditional**. `AiActionRegistry` only adds the row action for
actions whose built-in tool is switched on — Content carries Title + Excerpt, SEO carries
SEO meta, and Alt Text is a *Media Library* action that never appears on a post row. On a
stock install every tool is off, so a static "you will see WPCC AI when editing a post"
would have been **false** — and would have sent the customer hunting for a menu that
isn't there. One dead end replaced by a worse one.

*Verified live:* tools on → 20 rows carry `✨ WPCC AI` with `data-actions="title,excerpt,seo"`.
Tools off → **0**.

### P3 — External assistants confused with the provider key

Claude/ChatGPT connect over token-authenticated MCP/REST and do **not** use the provider
key. The surrounding screen is entirely about a provider key, so the customer's natural
inference is wrong, and acting on it means wiring up the wrong thing.

### P4 — Home's "Also included" cards were brochure copy

Three cards described what the plugin *can* do and never said what **this site** is doing.
Read after six months away they are unanswerable: did I switch Built-in AI on in March? Is
the key still there? The card looks identical either way — the only way to find out was to
click it, which is exactly the "now what?" this pass exists to remove.

### P5 — "0 feature routes saved."

Pressing Save with nothing changed reported a number and communicated nothing.

### Defects found while building the above

- **P6** — the new Built-in AI line advertised "image alt text" regardless of whether the
  Alt Text tool was on. Advertising a capability the site cannot perform is the exact
  defect this release already fixed once in the operation catalogue.
- **P7** — "SEO, Content **runs** through this connection" (verb agreement, and a
  comma-glued list that ignores locale conjunctions).
- **P8** — translator comments placed *inside* the `_n()` argument list, where WP's
  extractor does not pick them up. The comment would have been silently lost.
- **P9** — `ConnectionStatus::get()` (a token-manifest disk read) was resolved on **every**
  load of the AI setup screen to serve one row that only renders after an action.

---

## 2. Why they mattered

P1 is the whole thesis: the product proved it works and then said nothing about what now
works *because* it works. P2 mattered most for revenue-adjacent adoption — a paid-for
feature nobody can find is a feature nobody renews for; and because it is conditional, the
*honest* version of the message is strictly more useful than the marketing version. P3
protects the customer from a wasted afternoon. P4 is the returning-user story: a dashboard
that cannot distinguish "configured" from "never touched" is a brochure. P6–P8 are truth
and craft defects that would have shipped.

---

## 3. What was implemented

### Post-success experience (`ai-setup.php`) — this session

The outcome card gate widened from `create` only to **`create | update_key | test`**, and
the headline now reports what the customer actually did:

| Action | Headline |
|---|---|
| `create` | "*Name*" is saved — Anthropic |
| `update_key` | "*Name*" has its API key — Anthropic |
| `test` | "*Name*" **is working** — Anthropic |

When the connection is **proven** (`healthy`/`slow` — a real health state, deliberately not
"I just pressed Test"), the card opens a **"What you can do now"** section with the three
ways, each state-driven and each carrying exactly one control:

1. **Built-in AI** — names only the tools that are **on**, via `wp_sprintf_l()` + `_n()`
   ("The SEO and Content tools are on and generate through this connection"). All off →
   says so, CTA **Turn one on**.
2. **WPCC AI on your posts and pages** — derived from the same flags `AiActionRegistry`
   reads. On → names exactly what it generates and adds **Open Posts**. Off → explains
   that switching on Content or SEO is *what puts it there*. States the approval promise:
   *"Every suggestion arrives as a draft you review before anything changes."*
3. **Claude, ChatGPT and other assistants** — shown even when the runtime can't use the
   provider, because this path is independent. Explicitly *"needs its own access token,
   **not this provider key**"*, with real state (usable tokens, whether one has ever
   called) and *"changes wait for your approval"*.

### Home dashboard (`command-home.php`) — this session

`Built-in AI` card now has **four** genuinely distinct states, each with its own next action:

| State | Pill | CTA |
|---|---|---|
| tools on + key | `SEO and Content on` (green) | Open Built-in AI → |
| tools on, no key | `Needs a provider key` (amber) | Add a provider key → |
| key, no tools on | `Key ready · no tools on` | Turn on a tool → |
| neither | `Not set up` | Set up Built-in AI → |

Getting these the wrong way round wastes time in a *specific* way — the old single card
could send someone to add a key they already had. `Read-only access` states the real
usable-token count (`3 active tokens`) and asks for one when there are none.

Both reads are free: tool flags are options through the one precedence helper, and the
token count was **already computed** on that page for the status strip. No new query,
route, or option; Home's heavy data stays asynchronous.

### Smaller — this session

- `save_routes` zero-change branch: *"Routing is unchanged — every feature already points
  where you selected."*
- P6/P7/P8/P9 fixed; one dead variable (`$wpcc_tools_off`) removed.

---

## 4. Files changed

| File | This session |
|---|---|
| `includes/Admin/views/ai-setup.php` | outcome gate, 3-way section, per-tool + editor-action state, lazy assistant read, i18n fix |
| `includes/Admin/views/command-home.php` | 4-state Built-in AI card, token-count card, pill CSS |
| `includes/Admin/ConnectionController.php` | zero-change routing message + action tag |
| `tests/test-next-step-ux.sh` | 73 → **108** assertions |
| `tests/regression-map.tsv` | routing patterns for the new surfaces |
| `includes/Admin/views/ai-integrations.php` | *(prior session)* |
| `includes/Admin/BuiltinAiSettings.php` | *(prior session)* |

---

## 5. Tests run

| Suite | Result |
|---|---|
| `test-next-step-ux.sh` | **108 passed, 0 failed** (was 73) |
| `test-ai-integration-ux` | 60 / 0 |
| `test-ai-platform-6r` | 38 / 0 |
| `test-ai-platform-ux-6s` | 46 / 0 |
| `test-connection-delete-and-toggle-ux` | 45 / 0 |
| `test-connection-discovery-routing` | 29 / 0 |
| `test-connection-state-truthfulness` | 23 / 0 |
| `test-wizard-ux-cleanup` | 25 / 0 |
| `test-wizard-provider-metadata` | 29 / 0 |

**T0** — 736 passed, **0 failed**, net-new 0 (66s)
**T1** — 918 passed, **0 failed**, net-new 0 (174s)
**T2** — see §6

Per `RESUME-HANDOFF.md` §7, T0/T1 were run in `developer` mode (they inherit the site's
mode; on Standard they report ~93 false failures that are just the approval gate working).
Standard was restored immediately afterward.

---

## 6. T2

```
T2 result: 7098 passed, 0 failed  |  net-new: 0  |  3179s   (196 suites)
```

**Fully clean, and ahead of the previous best** recorded in `RESUME-HANDOFF.md` §10
(6989 passed / 1 failed). No regressions, nothing net-new, no fixes needed on re-run.

Two earlier T2 runs were **deliberately aborted**: each had been started before a
subsequent source edit, and a certification run that spans an edit to the tree it is
certifying is not a certification. T2 also applies its own governance baseline
(`developer` + tools on) and restores it at the end — so both aborts required manually
restoring the release state (`client`, tools off), which was done and verified each time.

### Finding: T2 left the site in `developer` mode

After the **successful** run (exit 0), the site was left in `developer` — the
self-approving mode with no approval gate — while `wpcc_builtin_ai_tools` *was* correctly
restored. `run.sh` snapshots all three governance options and restores them from an `EXIT`
trap (`GOV_SNAPSHOT` / `GOV_RESTORE`, lines 136–165), and `GOV_RESTORE` swallows all
output (`>/dev/null 2>&1`), so a failed or timed-out `wp eval` restores nothing and says
nothing. Given the documented WP-CLI cold-start behaviour (§8.2 of the handoff: 31.5s
first call), a silently-failed restore is the likely cause.

**Not a product defect — a test-harness hygiene issue.** But it leaves a site in the
*less safe* of the two modes, silently, after a green run. Restored manually and verified
(`mode=client`, `tools=[]`). Worth fixing before anyone runs T2 against a site that
matters; deliberately **not** fixed here, to avoid editing the harness after the
certification run.

---

## 7. Manual browser validation

| Journey | Result |
|---|---|
| AI setup screen renders | ✅ |
| Test connection → success | ✅ outcome card, correct headline |
| Post-success, tools **off** | ✅ "Turn one on", editor row explains the dependency |
| Post-success, tools **on** | ✅ names SEO + Content, "Open Posts" appears |
| Posts list, tools **on** | ✅ 20 rows carry `✨ WPCC AI` (`title,excerpt,seo`) |
| Posts list, tools **off** | ✅ 0 rows — the conditional claim is honest |
| Home, key + no tools | ✅ `Key ready · no tools on` → "Turn on a tool →" |
| Home, tools on | ✅ `SEO and Content on` → "Open Built-in AI →" |
| Home, tokens | ✅ `3 active tokens` → "Manage access tokens →" |
| Connection cards (returning user) | ✅ "Powers … · not used yet", health, **0** delete buttons outside `More` |
| Assistants pane (CTA destination) | ✅ exists, `#wpcc-config-panel` + `#wpcc-copy-config` present |
| Copy button refused-clipboard path | ✅ says "Press Ctrl/Cmd+C", restores cleanly, no stuck label |
| **Full first-run wizard** (5 steps) | ✅ provider → name → credentials → model → create |
| Create **without** a key | ✅ "It has no API key yet…" + **Add your API key** |
| "Add your API key" CTA | ✅ jumps, opens the `<details>`, focuses `wpcc_key`, spotlights the card |
| New card, keyless | ✅ "Needs a key", amber *"Add an API key to finish setup."*, **Add API key** primary |

The keyless card is the clearest demonstration of the returning-user work: beside two
cards reading a muted "Working. Nothing to do.", it reads an amber "Add an API key to
finish setup." and is the only card whose primary button is highlighted. The card tells
the story without the customer inspecting a single control.

Site state was restored to the release baseline (`client`, all tools off) after every
state-changing check, and the test connection was deleted (2 connections remain, default
unchanged).

---

## 8. Remaining non-blocking ideas

1. **The 3-way section only renders after an action.** A returning customer who merely
   *visits* a healthy setup screen doesn't see it. Surfacing it persistently was
   deliberately **not** done — it would add a permanent block to a settings screen the
   brief asks to keep uncluttered. Worth a design decision, not a patch.
2. **Home's "Undo any change" card left state-free.** A count needs a `COUNT(*)`; the
   "Recent changes" list directly above it already carries that state.
3. **The `Needs a provider key` state verified by inspection, not live** — producing it
   means removing the site's provider key.
4. **Pre-existing (RESUME-HANDOFF §8.1):** 33 suites still carry the `pipefail` + `grep -q`
   SIGPIPE race. Untouched to avoid broadening a freeze; still the most likely source of
   future phantom failures.
5. Deeper flows (diagnostics, capabilities, operation map) were **not** swept. Undo and
   Approvals *were* inspected and already end well — undo reloads to the updated list, or
   links to Approvals when the undo itself needs approval.

---

## 9. Release recommendation

### ✅ SHIP

Every quality gate is green against a frozen tree: **T0 736/0 · T1 918/0 · T2 7098/0,
net-new 0**, plus 108 targeted assertions and eight focused suites. The T2 number is the
best this project has recorded.

The change is **presentation-only by construction**. No route, capability, operation, MCP
tool, schema, option, or write path was added or altered. The one controller change adds
two *optional* keys to a notice envelope and a message for a branch that previously
returned "0"; every caller reading `type` / `message` is untouched. Nonce, capability and
audit paths are unchanged and asserted as such.

Two caveats to hold before tagging, neither blocking:

1. **The tree is still uncommitted**, and now more so than when `RESUME-HANDOFF.md` was
   written — its §2/§4 checksum and file counts describe a different product again. The
   package identity must be recomputed after committing and rebuilding. I have not
   committed, tagged, pushed, or rebuilt.
2. **The T2 harness can silently leave a site in `developer` mode** (§6). Worth fixing
   before the next run on any site that matters.

I'd also flag the §8.1 design question — the post-success panel renders only *after* an
action — as the one genuine product decision left open in this work.
