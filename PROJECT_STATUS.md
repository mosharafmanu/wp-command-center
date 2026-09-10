# PROJECT STATUS — WP Command Center

> **Final fresh-user onboarding closeout M22/M23/M25 — 2026-09-09:** Continue now offers
> a complete top-level `mcpServers:` block when the key is missing and a correctly
> indented entry-only choice when the list already exists. Muse Code now names and safely
> prepares `~/.config/muse/settings.json` without overwriting it, then distinguishes a
> complete new-file structure from an entry-only merge. A shared credential gate prevents
> every inline-token integration from copying an executable-looking placeholder: controls
> unlock only for the one-time token or a saved token supplied browser-side. Env-var and
> VS Code secure-prompt configurations remain copyable because they are complete without
> an embedded secret. Owner manual evidence promotes Command Code 1.51.0 and Muse Code
> 1.0.3 to **CERT_PASS** after successful actual-client read-only `system_info`; neither is
> Gold. Gemini CLI alone remains **Account unavailable** because Google rejects this
> individual account/client and directs it to Antigravity. No runtime/auth/governance
> behavior changed. No commit/package.

> **Final MCP client matrix closeout — 2026-09-09:** Reopened Copilot M16 was
> reproduced and closed with fresh actual-client evidence. VS Code 1.136.2 / built-in
> Copilot 0.64.1 sends the configured Bearer header; when the secure input is missing or
> invalid, WPCC returns an ordinary JSON 401 with no OAuth challenge and VS Code itself
> starts OAuth discovery/DCR while retaining stale discovery counts. With a correct fresh
> Read-only token, the same documented config exposed **42 tools / 6 prompts**, executed
> `system_info` exactly once without OAuth/DCR, and reconnected. The customer flow now
> restores the token to the clipboard immediately before server start. Gemini Spark is a
> real hosted custom-MCP surface but is not v1-compatible with WPCC's local/static-token
> flow; no card was added. Gemini CLI remains. This checkpoint's earlier Command Code and
> Muse account limitations were superseded by the later owner-driven passes recorded above. Temporary
> tokens/config/install state were removed; original VS Code config restored. Presentation
> and metadata only; no MCP runtime/auth/governance change and no T2. No commit/package.

> **Final onboarding retest remediation M13–M16 — 2026-09-08:** App selection now
> advances once to the focused Create access step and keeps the selected app visible.
> Codex CLI retains the owner-tested approval-aware launch, with its primary setup cut to
> four copy/run/verify actions and the terminal/config rationale collapsed. The current
> Codex CLI has no native `mcp add` flag that can merge a server-specific approval mode,
> so WPCC does not rewrite global settings; an annotation-aware persistent TOML option is
> Advanced only. VS Code's DCR prompt was traced to its reuse of the fixed `wpcc-token`
> secure-input id: a revoked token produced 401, then VS Code fell back to OAuth/DCR even
> though WPCC advertised no OAuth. Generated input ids are now token-record-scoped. An
> actual VS Code 1.136.1 + Copilot Agent retest prompted securely, showed no OAuth/DCR,
> exposed **42 tools / 6 prompts**, completed `system_info` exactly once, and reconnected;
> posts, requests, queue and change history were unchanged. The temporary token was
> revoked and clipboard cleared. Claude Desktop's actual Pass is preserved and its
> recommended copy action is now the one server entry, with whole-file JSON Advanced.
> No shared MCP authentication/runtime or governance behavior changed, so full T2 is not
> required. Focused onboarding/client suites are green. The broad local T0/T1 gates are
> non-green because the retained test credential is invalid: all 82 targeted-T1 failures
> are the three shared token-dependent core suites returning `wpcc_invalid_token`; the
> five onboarding suites in that gate are green. A fresh process-only token also passes
> Claude **104/0** and the client layer **145/0**, then was revoked. No commit or package.
> **READY FOR FINAL OWNER ACCEPTANCE.**

> **Connections / Assistants onboarding remediation — 2026-09-08:** Settings →
> Connections → Assistants is now an app-first, four-stage flow: choose the app, create
> access, follow one Recommended setup, then verify in the actual client with
> `system_info`. The selector groups OpenAI, Anthropic, Google, editor/coding clients and
> a collapsed Other / Experimental area; current names distinguish Codex in ChatGPT
> Desktop, Codex CLI, Continue for VS Code and GitHub Copilot in VS Code. Cursor uses its
> reviewed install link, Continue gets a one-entry YAML merge flow, and raw/manual config
> is collapsed. Codex M2 is narrowly remediated with truthful MCP tool annotations and
> the official `codex --ask-for-approval on-request` launch; WPCC governance is unchanged.
> Focused final-source coverage passed **1,062/0** and real-browser desktop/narrow QA
> passed. Serial T2 ran all 210 suites (**10,237/15**): one stale app-selector assertion
> was corrected (26/0), and two token-harness path failures were corrected and rerun
> (62/0). The remaining 12 independently reproducible ACF/option/Site Builder assertions
> belong to pre-existing runtime/reversal work outside this remediation and keep the
> broader release gate non-green; they do not block the owner onboarding retest. Codex
> CLI and Copilot Agent mode still require actual-client `system_info` checkpoints. No
> commit/package/tag/deployment was performed. See
> [the focused report](docs/reports/validation/WPCC-CONNECTIONS-UX-REMEDIATION.md).
> **READY FOR FINAL OWNER ONBOARDING RETEST.**

> **Final certification metadata reconciliation — 2026-09-06:** The release-blocking
> registry/documentation contradiction is closed. The current v1 matrix is now explicit:
> Claude Code **CERT_GOLD**; ChatGPT Desktop, Codex CLI, Antigravity CLI, Cursor,
> Continue and OpenCode **CERT_PASS**; Gemini CLI and Command Code **BLOCKED — EXTERNAL
> ACCOUNT/PROVIDER**; Claude Desktop **NOT TESTABLE — COMPUTER-USE RESTRICTION**;
> Muse Code **NOT TESTABLE — NOT INSTALLED**; GitHub Copilot / VS Code **FAIL** pending
> one post-fix actual-client `system_info` call. Windsurf remains removed. Registry
> `active` is retained as the backward-compatible value for CERT_PASS, while exact
> closeout verdicts and evidence limits are recorded in validation notes. No runtime,
> security, setup-command or credential behavior changed. Remaining items are
> non-blocking: the two external account/provider limitations, Muse/Claude Desktop test
> availability, the Copilot checkpoint, intentionally unmigrated historical reversal
> metadata, test-runner accounting, and the deferred term/cache REST compatibility
> finding. Focused metadata/documentation verification is recorded with this candidate;
> T2 was intentionally not rerun because this correction is metadata/docs only.
> **READY FOR RELEASE FREEZE** — proceed next to commit/build/package verification, not
> additional client certification.

> **Claude discovery governance metadata remediation — 2026-09-06:** The customer-accessible `/claude/discovery` endpoint no longer reads the retired `wpcc_enforce_approval` option. Claude discovery, compact MCP manifest, full agent manifest and the security report now derive approval meaning from `SecurityModeManager::approval_policy()`, the same mode/risk policy used by execution. Standard reports approval for medium/high/critical, Strict for low/medium/high/critical, and Development for none. Focused/relevant verification passed **1,102/0**; authenticated Standard verification returned HTTP 200 with matching policy and no site, approval, queue or history mutation. T0/T1/T2 each recorded one unrelated environment-sensitive failure that passed immediately in isolation; full T2 is therefore **not green** (**10,058/1**) but has no observed Claude-attributable failure. See [the focused report](docs/reports/validation/WPCC-CLAUDE-DISCOVERY-GOVERNANCE-REMEDIATION.md). The owner has classified currently local credentials as temporary and their rotation/removal as final-release cleanup; this supersedes the older credential-blocker wording below for this remediation. No secret-bearing file was inspected, no commit/package was made, and the candidate is ready for final client-certification closeout.

> **Read-only + Antigravity CLI D/E/F remediation — 2026-09-06:** Read-only authorization now checks audited actions across all 42 operations; native `agy` 1.1.27 Read-only certification passed (42 tools, 7 resources, system_info, site-health read, denied write, reconnect). Antigravity CLI is classified as CLI and uses native setup; Gemini CLI remains separate. Focused D 2,259/0 and E/F 24/0. Full T2 **11,462 passed / 55 failed** over 205 suites, **not green**; every failure reproduces on the preserved starting candidate (9,178/56, 203 suites), zero attributable net-new failures. Six attribution reruns pass 232/0 on each candidate. Source and prior certification history preserved; no commit/package. **Do not resume certification until the owner rotates credentials exposed by the environment-file tool-transcript inspection.** See [the full D/E/F report](docs/reports/validation/WPCC-READONLY-ANTIGRAVITY-REMEDIATION.md). This checkpoint supersedes older readiness statements below without deleting their history.

> **Codex onboarding remediation — 2026-09-06:** macOS Codex CLI now uses shell-local export and same-terminal startup; ChatGPT Desktop retains independent launchctl bootstrap. Token reveal/navigation and browser-test claims corrected. Focused + relevant regressions: **756 passed / 0 failed**. Actual codex-cli 0.153.4 MCP engine verified native registration → authentication/initialize → **42 tools / 7 resources** → system_info; fresh shell without export loads zero tools. Temporary credentials revoked; existing candidate/history preserved. No T2 rerun, commit or packaging. See [the remediation report](docs/reports/validation/WPCC-CODEX-ONBOARDING-REMEDIATION.md), including interactive/visual verification limits. **Ready to resume client certification.**


> **Current checkpoint — 2026-09-06:** Post-certification findings A/B/C are remediated and verified; zero attributable net-new regressions. Full T2 completed: 202 suites, independently audited 9,200 passed/37 failed (baseline/environment attribution documented). Resume manual MCP certification on the preserved, uncommitted candidate. The original certification history and Standard-mode site state remain intact; do not reset the site to look fresh. See [the consolidated remediation report](docs/reports/validation/WPCC-CERTIFICATION-ABC-REMEDIATION.md). This checkpoint supersedes the older pause/counts below; their historical evidence is retained.

**Read this first.** The first two sections — *At a glance* and *Current blocking work* —
tell you where the project is in about a minute. The whole page is a six-minute read and
answers everything a resuming engineer needs before touching anything.

Detail lives in [`RELEASE_HANDOFF.md`](RELEASE_HANDOFF.md); this file only summarises it.
Where the two disagree, `RELEASE_HANDOFF.md` is correct.

*Last updated 2026-08-12, at the pause before manual MCP certification — re-verified after
an unplanned machine shutdown.*

---

## ⏸ PAUSED — RESUME HERE

> ### NEXT PHASE — Manual MCP certification
>
> **FIRST CLIENT** — **ChatGPT Desktop.**
>
> **FIRST HUMAN ACTION** — Open WP Command Center as a genuine new user and follow the
> plugin's **own displayed onboarding instructions**, exactly as written. No shortcut
> configuration, no old token, no manual preconfiguration. We are certifying the **customer
> journey**, not merely MCP protocol connectivity.
>
> **Where the code is** — branch `release/v1-security-recut`, HEAD `477df1f`
> (*docs: checkpoint fresh onboarding certification state*), **unmerged**, 3 commits ahead
> of `main`. The working tree is **dirty on purpose**: **31 modified · 5 untracked ·
> 1 deleted**, carrying two complete, verified, deliberately **uncommitted** bodies of work.
>
> | Uncommitted work | State |
> |---|---|
> | **A. MCP client compatibility remediation** | Complete, verified, uncommitted |
> | **B. Branding regression fix** | Complete, verified, uncommitted |
>
> Both were held back so that manual certification exercises the exact combined candidate,
> and so that anything it uncovers lands in the same commit rather than after it.
>
> ### The suspected AI-connection drift — checked against the database, and clear
>
> The audit log records **`ai.connection.created` (provider `anthropic`) at 10:57:06 on
> 2026-08-12**, after the 08:41 reset and with no matching `ai.connection.deleted`. On
> file evidence alone that read as a stray connection sitting in the fresh baseline.
>
> **The database says otherwise.** With the site's own MySQL back up (2026-08-12, after the
> shutdown), checked both at the storage layer and through `ConnectionStore::all()`:
> `wpcc_ai_connections` is `a:0:{}`, `wpcc_ai_credentials` is `a:0:{}`, `wpcc_ai_routes` is
> `a:0:{}`, `wpcc_ai_default_conn` is absent and no legacy `wpcc_anthropic_api_key` exists.
> **0 connections. Nothing needed deleting, and nothing was deleted.**
>
> The connection was therefore created and removed again before the shutdown, by a path
> that does not write an audit entry — an option-level or WP-CLI cleanup rather than
> `ConnectionController::delete()`, which does audit. Worth knowing for the future: **the
> audit log records connection creation but can miss its removal**, so it is evidence of
> what happened, not of what currently *is*. Read the store for state.
>
> ### A. Client compatibility remediation — verified present after the shutdown
>
> Re-inspected in the working tree, not taken on trust: Windsurf integration deleted and
> its registry row gone; `AntigravityIntegration` and `MuseCodeIntegration` added and
> registered; Codex/ChatGPT now emit `bearer_token_env_var` (the un-interpolated
> `bearer_token = "${WPCC_TOKEN}"` is gone); `McpServerRuntime` answers
> `resources/templates/list` and `ping`, and guarantees `items` on every array schema;
> `RollbackOperation::preflight()` plus the `OperationExecutor` preflight hook refuse an
> impossible rollback **before** a human is asked to approve it; `tests/test-mcp-client-onboarding.sh`
> and `tests/test-rollback-routing.sh` added; `tests/regression-map.tsv` updated. Every
> modified and added PHP file passes `php -l`.
>
> ### B. Branding regression — root cause, fix, verification
>
> **Root cause.** `Brand::picture()` chose artwork with `(prefers-color-scheme: dark)`,
> which reports the **viewer's OS/browser theme** — not the surface the artwork lands on.
> The affected WordPress admin surfaces are **permanently light** (core colour schemes
> restyle the sidebar and accents, never the content area), so any admin on a dark OS
> theme — Firefox users noticed first — was served the near-white `#F8FAFC` dark-surface
> artwork onto a white card: **1.09:1 contrast, i.e. invisible**, on the one screen that
> introduces the product.
>
> **Files changed** (all verified present in the working tree):
>
> | File | Change |
> |---|---|
> | `includes/Admin/AppShell.php` | Page-title mark → unconditional `Brand::mark()` |
> | `includes/Admin/views/command-home.php` | Home hero lockup → unconditional `Brand::logo()` |
> | `tests/test-branding-assets.sh` | Asserts the surface invariant, not the old media-query mechanism |
>
> No live `Brand::picture(` call remains anywhere in `includes/` — only the explanatory
> comments. `picture()` and the `*-dark` assets stay available for genuinely dark surfaces.
>
> **Verification — as reported by the session that made the fix, not re-run here:**
> branding assets **33 / 0** · first-value/onboarding focused **30 / 0** · scoped static T0
> **130 / 0** · rendered light-surface pixels checked · sidebar and admin-bar branding
> unchanged · fresh onboarding baseline preserved. This session re-verified the *code and
> test changes* by inspection and lint; it deliberately re-ran **nothing**.
>
> **Owner's remaining check:** during the resumed onboarding, glance at the branding in
> **Firefox with a dark system preference**. The implementation is correct by construction —
> there is no longer any scheme switch to get wrong — but this regression was found by eye,
> so confirm it by eye.
>
> ### Why the wider suites must still wait
>
> `T0 --changed`, T1 and T2 all create tokens, approvals, history and snapshots and flip
> protection mode — **running one destroys the fresh onboarding state this pause exists to
> create.** T2 is not required for a presentation-only branding change, and the automated
> gate is already green and recorded: **T2 7,318 / 0, net-new 0** (2026-08-12, after the
> compatibility remediation, before the branding fix). Nothing there is waiting to be
> re-proved.
>
> ### Do not do any of these first
>
> | Don't | Why |
> |---|---|
> | Run T0 / T1 / T2 or any MCP/REST suite | They create tokens, approvals, history, snapshots and flip protection mode. **Running one destroys the clean onboarding state this pause exists to create.** |
> | Restore an old access token | There are none, on purpose. The first token must be created through the normal UI so the real onboarding journey is what gets certified. |
> | Restore an old localhost client config | Removed on purpose, for the same reason. |
> | Reconnect an assistant by hand-editing a config | The point is to prove WPCC's *displayed* instructions work. A shortcut certifies nothing. |
>
> ### What "fresh" means here — established 2026-08-12 08:39–08:41
>
> 0 access tokens · 0 AI connections · 0 built-in AI tools on · 0 pending approvals ·
> 0 change-history rows · assistant never connected · **Standard protection (client mode)** ·
> schema and `wpcc_db_version` 2.6.0 intact · WordPress content untouched
> (19,666 posts · 404 pages · 174 media · 905 users).
>
> An empty database here is **expected and correct**, not a symptom. Do not "repair" it.
>
> ### What survived the shutdown — re-checked 2026-08-12, read-only
>
> The machine was shut down before this checkpoint could be written. What could be
> re-confirmed **without touching anything**:
>
> | Claim | Evidence |
> |---|---|
> | 0 access tokens | The private token store `uploads/wpcc-tokens-…` holds only its guard files (`.htaccess`, `index.php`, `index.html`, `web.config`) — no token file |
> | No history / approvals / snapshots written since the reset | Every `wp_wpcc_*` table file is untouched since **08:39**; only `wp_wpcc_telemetry` moved (cron heartbeats) |
> | Audit trail clean | 61 entries, of which **60** are `operation.worker.started/completed` no-ops; the 61st is the AI connection discussed above, which no longer exists |
> | No localhost WPCC registration in any client | Codex `~/.codex/config.toml` has only `node_repl` + `computer-use`; Claude Desktop, Cursor, Gemini, VS Code and Continue all have empty MCP server maps; Antigravity / OpenCode / Command Code / Muse Code have no config at all |
> | Unrelated `purple-live` preserved | Still the only MCP server in `~/.claude.json`, scoped to the old `wp-command-center` directory |
> | `WPCC_TOKEN` unset | Not in the environment. `wpcc-env.sh` still holds the token minted on 08-11, which the purge **destroyed** — it is dead; do not re-export it |
>
> ### Full baseline — re-verified against the live database, 2026-08-12 after the shutdown
>
> Site identity confirmed first: database `plugins-dev`, `siteurl`
> `http://localhost/ClientProjects/WordPress/2026/plugins-dev`. Read through the plugin's
> own API (`AuthTokens::list()`, `ConnectionStore`) and by direct row counts — **nothing was
> created to prove any of it**:
>
> | | |
> |---|---|
> | Access tokens | **0** |
> | AI connections · credentials · routes · default | **0 · 0 · 0 · none** |
> | Built-in AI — SEO meta · Alt text · AI content | **Off · Off · Off** |
> | Pending approvals (`operation_requests`, `proposals`) | **0 · 0** |
> | Change history (`change_log`) | **0** |
> | Queue · results · patches · snapshots · recommendations · agent tables · idempotency · health | **all 0** |
> | Assistant connected | **never** — no token exists, so none has a `last_used_at` |
> | Protection mode | **`client`** (Standard) |
> | `wpcc_db_version` | **2.6.0** |
> | WordPress content | **intact** — 19,665 posts (528 published), 404 pages, 174 media, 905 users |
>
> The only non-zero WPCC table is `wpcc_telemetry` (**30 rows**), all of them cron worker
> heartbeats written between the reset and the shutdown. That is runtime noise, not
> onboarding state, and it does not affect the first-run journey.
>
> **Environment note.** This site's database is AMPPS MySQL
> (`/Applications/AMPPS/apps/mysql/data`, socket `…/var/mysql.sock`, which is what
> `php.ini` points at). It was restarted on **port 3307** because Homebrew's unrelated
> MySQL now occupies 3306 — the site connects over the socket, so the port makes no
> difference to it, and the Homebrew/nginx:8080 stack was left alone. **Apache is still
> down**: it binds port 80 and needs root, so start it yourself with
> `sudo /Applications/AMPPS/apps/apache/bin/apachectl -f /Applications/AMPPS/apps/apache/etc/httpd.conf -k start`
> (or from the AMPPS app — if its MySQL start reports a failure, that is only the
> already-running instance holding the data directory lock, which is harmless).
>
> ### If onboarding fails
>
> Stop the certification and investigate before continuing. A failure at this stage is the
> single most valuable signal available — it is exactly what a real first-time customer
> would hit, and the automated suites cannot see it.

---

## At a glance

| | |
|---|---|
| **Project** | WP Command Center — a governed AI control plane for WordPress. An assistant connects over MCP or REST with a scoped token; changes that matter wait for human approval; everything is recorded; supported changes can be undone. |
| **Current version** | **1.0.1** (DB schema 2.6.0) |
| **Release status** | Engineering **complete**. **Not yet submitted** to WordPress.org. |
| **Current branch** | `release/v1-security-recut` — release source, deliberately not merged to deployment-triggering `main` |
| **Latest release source** | The combined security re-cut, client compatibility, release-gate, rollback, branding, and packaging remediation certified for v1.0.1 |
| **Uncommitted runtime work** | **No after the v1.0.1 release commit.** The complete certified candidate is represented by the `v1.0.1` tag. |
| **`main`** | `81f7d6a` = `origin/main` = **what production runs**. Untouched on purpose. |
| **Tags** | `v1.0.0` remains the historical production marker at `81f7d6a`; `v1.0.1` identifies the certified WordPress.org submission candidate. |
| **Working tree** | The intentional candidate is committed by the v1.0.1 release task; ignored local environment files remain outside Git. |

### Package identity — the artifact to submit

```
ai-command-center-1.0.1.zip
Size        1,151,368 bytes    Files 302 (284 PHP)
SHA256      4b39c78aa5e042351b59c3468c93c7412e198f75a8e3dc9e0591106bdad20c2e
Content-ID  41c15676a416495e4f907b9add7a9e3ea8ecde6e825dc6635b0d2c455c08a26e
Plugin Check  0 errors, 828 classified warnings (against the extracted ZIP)
```

`SHA256` changes on every rebuild; `Content-ID` does not. Verify the **exact ZIP you
upload**. Full method in `RELEASE_HANDOFF.md` §2.

---

## Architecture in six lines

```
MCP client ─┐
Admin UI   ─┼─▶ one operation catalogue ─▶ OperationExecutor ─┬─▶ SecurityModeManager (approve?)
Your code  ─┘                                                 ├─▶ CapabilityRegistry (allowed?)
                                                              ├─▶ ChangeRecorder (audit)
                                                              └─▶ RollbackDelta (undo)
```

Three transports, **one** catalogue and **one** governance layer. Runtime managers never
decide for themselves whether a change needs approval. Full picture: `RELEASE_HANDOFF.md` §4.

## Core features

Scoped revocable tokens · approval workflow · audit trail · field-scoped drift-aware undo ·
42 operations (content, media, SEO, users, comments, settings, plugins, themes, WooCommerce,
ACF, Elementor) · verified file patches · optional Built-in AI.

| Subsystem | Status |
|---|---|
| **MCP** | **42 tools**, verified live against the patched build. Namespace `wp-command-center/v1` — a public contract, do not rename. |
| **Built-in AI** | Optional, **off until configured**. 16 providers catalogued. Connecting an assistant over MCP needs no provider key. |
| **Governance** | 3 protection modes; default `client` (Standard). A token **cannot** approve its own request. Fail-safe: corrupt setting resolves to `client`, never `developer`. |
| **Manual MCP certification** | ❌ **NOT STARTED** — this is the next phase, and it is the owner's. |

---

## Current blocking work

> ### The only remaining engineering work before WordPress.org submission is **manual MCP certification**.
>
> Everything else is complete:
>
> | | |
> |---|---|
> | Feature work | ✅ Complete — feature-frozen |
> | UX work | ✅ Complete |
> | Security fixes | ✅ Complete (uploads private store; 0 Plugin Check errors) |
> | Release candidate | ✅ Complete — package built and verified |
> | Regression testing | ✅ Complete — **T2: 7,318 passed, 0 failed, net-new 0** (2026-08-12, after the compatibility remediation) |
| Client compatibility remediation | ✅ Complete — ten findings from real-client testing, see below |
> | Documentation | ✅ Complete — consolidated into one authoritative handoff |
> | **Manual MCP certification** | ❌ **BLOCKING — not started** |
>
> **Why it cannot be automated.** Everything upstream of the handshake is verified. The one
> thing no local environment can prove is that a real MCP client — Claude Desktop, Cursor,
> Codex, ChatGPT — accepts the configuration this plugin generates and that the relay
> actually connects. That requires a human driving a real client against a live HTTPS site.
>
> The checklist is [`docs/ASSISTANT-CERTIFICATION.md`](docs/ASSISTANT-CERTIFICATION.md) §7
> (twelve steps: connect → 42 tools → read → propose → self-approval refused → human
> approval → audit attribution → undo → double-undo refused → reconnect).

---

## Client compatibility remediation — completed 2026-08-11/12

Ten findings from **real manual testing against real clients** were resolved. Two were
functional defects, not documentation problems: the generated Codex/ChatGPT configuration
used a key that cannot authenticate (`bearer_token = "${WPCC_TOKEN}"` — TOML does not
interpolate, so a healthy server returned 401), and **eight** tool schemas declared an
array without `items`, which GitHub Copilot rejects — and because it validates every tool
before using any, one bad schema blocked all 42. Full record in `REAL_TEST_FINDINGS.md`.

### Supported client matrix

| Transport | Clients |
|---|---|
| **Direct HTTP** (nothing installed) | ChatGPT Desktop · Codex CLI · Gemini CLI · Antigravity · Cursor · OpenCode · Command Code · GitHub Copilot / VS Code · Claude Code · Muse Code |
| **Relay** (needs Node.js) | Claude Desktop · Continue |
| **Removed from v1** | **Windsurf** — deferred, never validated against its current real client |

Notes that matter when reading the selector:

- **Claude Code is the only `CERT_GOLD` client.** Everything else still needs the
  certification level the plan requires; several moved to `CERT_ACTIVE` on the strength of
  a live connection + tool execution, which is *not* the twelve-step checklist.
- **Muse Code is documentation-verified only.** Meta's docs confirm MCP support and the
  config shape, but the client was not installed here, so nothing was run against it. It is
  deliberately `experimental`, and a test asserts that claim cannot quietly get stronger.
- Six clients now offer a **native one-command setup** (Codex, ChatGPT Desktop, Gemini CLI,
  OpenCode, Command Code, Claude Code) — each command was executed verbatim before shipping.

## Remaining work (owner, not engineering)

Full sequences in `RELEASE_HANDOFF.md` §14. In order:

1. **🔴 Rotate the exposed production credentials — first.** The public repo carries a live
   production SSH password on `main`, plus two production tokens (both already dead —
   probed, they return `401`). That host auto-deploys this plugin. **Nothing exposed ships;**
   the package is clean.
2. **Submit the verified v1.0.1 ZIP to WordPress.org** after explicit owner authorization.
3. **Reply to the WordPress.org slug email immediately**, requesting `ai-command-center`.
   Permanent after approval.
4. **After approval, publish the verified package to SVN trunk/tag/assets** under separate authorization.

## Known limitations

Twelve, all deliberate, all in `RELEASE_HANDOFF.md` §12. The ones you will hit first:
WooCommerce **orders/refunds untested** · **multisite unsupported** (activation refused) ·
**undo costs five clicks** under Standard protection, because an undo is itself a change
that needs approval · approvals list **caps at 100**.

---

## What NOT to change

Reversing any of these silently removes a guarantee the product makes. Do not touch without
a **reproducible bug** in hand.

| Area | Why |
|---|---|
| `SecurityModeManager::requires_approval()` — the mode × risk table | The governance model itself |
| The human-approver requirement (`wpcc_approval_requires_human`) | A token must never approve its own request |
| `Security/PrivateStore.php` and the per-install suffix | The only thing keeping tokens, snapshots and patches off the open web on nginx |
| Token hashing, scopes, `CapabilityRegistry`, the read-only allowlist, REST permission callbacks | The security model |
| `OperationExecutor` dispatch order, `RollbackDelta`, `ChangeRecorder` | Undo and audit integrity |
| `Schema::DB_VERSION` and the `wpcc_*` tables | Migration safety |
| REST namespace `wp-command-center/v1` | A public contract — every existing assistant config depends on it |
| Uninstall's retain-by-default policy | Deleting a plugin is not consent to erase an audit trail |

Also frozen by decision: no new features, dashboards, onboarding tours or animations before
submission. If a change cannot be justified as *easier to understand, trust, or use*, don't.

---

## Next milestone — v1.1

Nothing started. Full list with reasoning in `RELEASE_HANDOFF.md` §13.

**Finish what v1 left:** WooCommerce orders/refunds coverage · name the change in the undo
approval row (best value per effort) · paginate approvals past 100 · screenshots and banner.

**Platform work the architecture is already shaped for:** subscribers on the EventBus —
`EventBridge` already publishes a typed event per audit record with **no consumers**, so
notifications, webhooks and a live dashboard attach with zero runtime change. Cheapest large
feature in the codebase.

**Hardening:** reduce the 824 Plugin Check warnings · ship a `.pot` · re-baseline
`tests/regression-baseline.tsv` on your own environment.

---

## Resume checklist

Follow in order. Steps 1–5 take about fifteen minutes and tell you whether anything drifted.

1. **Read this file**, then [`RELEASE_HANDOFF.md`](RELEASE_HANDOFF.md) — especially §5
   (security decisions), §8 (how to test) and §14 (owner actions).
2. **Verify the branch:** `git branch --show-current` → `release/v1-security-recut`.
   `main` must still be `81f7d6a`; it is production and pushing to it deploys within a minute.
3. **Verify the package identity** against §2 — Content-ID, not just the archive hash:
   ```bash
   unzip -q build/ai-command-center-1.0.1.zip -d /tmp/pkg
   ( cd /tmp/pkg && find . -type f -print0 | sort -z | xargs -0 shasum -a 256 ) | shasum -a 256
   # expect 1010c8c78212886453541ad09ada28e9a6c6db11ec7c34bceb71f9dcd9aa362c
   ```
   If `build/` is empty, that is normal — it is gitignored. Rebuild with
   `bash scripts/build-release.sh` and expect a **new** SHA256 but the **same** Content-ID.
4. **Bring the environment up** — MySQL and a web server. Log into wp-admin and open
   WP Command Center. It must show **Step 1 of 2 · Connect your assistant**.
5. > ### ⛔ DO NOT RUN THE TEST SUITES AT THIS RESUME POINT
   >
   > This step used to say "run T2", and following it now would **undo the pause**. The
   > local site was reset to a fresh onboarding state *after* the last full run, and every
   > tier creates tokens, approvals, history and snapshots and flips protection mode.
   >
   > The automated gate is already green and recorded: **T2 7,318 / 0, net-new 0**
   > (2026-08-12). There is nothing to re-prove.
   >
   > Run the suites again only *after* manual certification, or on a site you are willing
   > to dirty. If you do, `wpcc-env.sh` needs a fresh full-scope token — the reset removed
   > the old one, and a stale `WPCC_TOKEN` makes hundreds of suites fail with empty results
   > rather than an auth error.
6. **Begin manual MCP certification** — `docs/ASSISTANT-CERTIFICATION.md` §7, starting with
   **ChatGPT Desktop**. Follow the instructions WPCC itself displays; do not shortcut the
   configuration. Stop and investigate on the first failure.
7. **Fix only real defects.** Reproduce first, fix the smallest thing that fixes it,
   re-test, regression-test the surrounding feature, stop.
8. **Do not add features before WordPress.org submission.**

### If you are an AI assistant resuming this project

Read this file and `RELEASE_HANDOFF.md`. **Ignore `.ai/handoffs/resume.md`** — it says
"START HERE" but was last verified 2026-06-15, is roughly two months stale, and contains a
credential that should have been rotated. Everything under `docs/product/` and
`docs/governance/` is a historical program record, correct on its date and superseded since.

---

## Documentation map

| Order | Read | For |
|---|---|---|
| 1 | **`PROJECT_STATUS.md`** (this file) | Where the project is |
| 2 | [`RELEASE_HANDOFF.md`](RELEASE_HANDOFF.md) | The authoritative engineering detail |
| 3 | [`docs/`](docs/README.md) — `ARCHITECTURE`, `SECURITY`, `MCP`, `API`, `OPERATIONS`, `CAPABILITIES` | How it works |
| 4 | [`docs/archive/`](docs/archive/README.md) | Why it works that way — superseded, kept as evidence |
