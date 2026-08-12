# PROJECT STATUS — WP Command Center

**Read this first.** The first two sections — *At a glance* and *Current blocking work* —
tell you where the project is in about a minute. The whole page is a six-minute read and
answers everything a resuming engineer needs before touching anything.

Detail lives in [`RELEASE_HANDOFF.md`](RELEASE_HANDOFF.md); this file only summarises it.
Where the two disagree, `RELEASE_HANDOFF.md` is correct.

*Last updated 2026-08-12, at the pause before manual MCP certification.*

---

## ⏸ PAUSED — RESUME HERE

> **CURRENT PHASE** — Manual MCP certification.
>
> **CURRENT STATE** — Fresh onboarding baseline prepared. The local certification site was
> deliberately reset to a first-install state **after** all automated testing finished, and
> every old localhost assistant registration was removed from every installed AI client.
>
> **NEXT HUMAN ACTION** — Open WP Command Center as a new user and begin manual onboarding
> with **ChatGPT Desktop**, following the setup instructions the plugin itself displays.
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
> ### What "fresh" means here — verified 2026-08-12
>
> 0 access tokens · 0 AI connections · 0 built-in AI tools on · 0 pending approvals ·
> 0 change-history rows · assistant never connected · **Standard protection (client mode)** ·
> schema and `wpcc_db_version` 2.6.0 intact · WordPress content untouched
> (19,666 posts · 404 pages · 174 media · 905 users).
>
> An empty database here is **expected and correct**, not a symptom. Do not "repair" it.
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
| **Current version** | **1.0.0** (DB schema 2.6.0) |
| **Release status** | Engineering **complete**. **Not yet submitted** to WordPress.org. |
| **Current branch** | `release/v1-security-recut` — **unmerged**, 3 commits ahead of `main` |
| **Latest runtime commit** | `c733244` — the security re-cut (uploads private store + one Plugin Check error) |
| **Uncommitted runtime work** | **Yes — the MCP compatibility remediation** (see *Client compatibility remediation* below). Present in the working tree, deliberately **not** committed while manual certification is pending. |
| **`main`** | `81f7d6a` = `origin/main` = **what production runs**. Untouched on purpose. |
| **Tag** | `v1.0.0` points at `81f7d6a`. **It is superseded — do not submit it.** The re-cut is **untagged**, **unmerged** and **undeployed**: it is *not* released. |
| **Working tree** | **Dirty on purpose.** Carries the compatibility remediation (24 modified, 4 added, 1 deleted) plus this documentation checkpoint. |

### Package identity — the artifact to submit

```
build/ai-command-center-1.0.0.zip
Size        1,101,893 bytes    Entries 334 (299 files, 281 PHP)
SHA256      e9e00d9de4ac573c84f0ff55ac14407e98f86094fbecaf9ef39dad2bb6460c27
Content-ID  1010c8c78212886453541ad09ada28e9a6c6db11ec7c34bceb71f9dcd9aa362c
Plugin Check  0 errors, 824 warnings (against the extracted ZIP)
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
2. **Decide the tag** — recommended `v1.0.1`, leaving `v1.0.0` describing production.
3. **Run manual MCP certification.**
4. **Reply to the WordPress.org slug email immediately**, requesting `ai-command-center`.
   Permanent after approval.
5. **Submit** the artifact above — not the tagged one.

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
   unzip -q build/ai-command-center-1.0.0.zip -d /tmp/pkg
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
