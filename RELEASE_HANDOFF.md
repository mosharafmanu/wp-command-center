# RELEASE HANDOFF — WP Command Center

> **Final fresh-user onboarding closeout M22/M23/M25 — 2026-09-09:** Continue's first-MCP
> path now supplies the missing top-level `mcpServers:` structure or a separate correctly
> indented entry-only merge. Muse explicitly prepares `~/.config/muse/settings.json`
> without overwriting an existing file and offers new-file versus existing-file payloads.
> All raw-token-dependent setup actions are now gated until a one-time or manually supplied
> saved token exists in the browser; no executable-looking `${WPCC_TOKEN}` setup can be
> copied early. Env-var and VS Code prompt contracts remain available because their setup
> payloads are secret-free. Fresh owner evidence promotes Command Code 1.51.0 and Muse Code
> 1.0.3 to **CERT_PASS** after actual read-only `system_info`; neither is Gold. Gemini CLI
> remains **Account unavailable** due Google's individual-account/client eligibility response.
> No runtime/auth/governance change, commit or package.

> **Final MCP client matrix closeout — 2026-09-09:** Fresh traced VS Code 1.136.2 /
> built-in Copilot 0.64.1 testing supersedes the incomplete prior M16 explanation. A
> missing/invalid secure-input value makes VS Code send an unusable Bearer header; WPCC's
> normal JSON 401 has no `WWW-Authenticate`, but VS Code automatically performs OAuth
> discovery and reaches DCR, while cached 42-tool/6-prompt counts remain visible. With a
> correct fresh Read-only token, the unchanged documented `headers.Authorization` config
> authenticated, exposed **42 tools / 6 prompts**, completed `system_info` exactly once,
> and reconnected without OAuth/DCR. The narrow UX fix copies the one-time token again in
> the final start action after the JSON copy has overwritten the clipboard. Gemini Spark
> is a real hosted MCP host but is excluded from v1 because its current eligible/account-
> linked custom-app contract does not document arbitrary static Bearer headers and cannot
> reach localhost; no OAuth/public-host runtime was added. Gemini CLI stays in Google.
> The earlier Command Code and Muse account limitations in this checkpoint were superseded
> by the later owner-driven passes above. Temporary credentials/config/install state were removed and the prior
> VS Code config restored. No runtime/auth/governance change, T2, commit or package.

> **Final onboarding retest remediation M13–M16 — 2026-09-08:** The Connections flow now
> visibly and accessibly continues from app selection to Create access exactly once. The
> Codex CLI primary flow is four concise actions; its verified
> `codex --ask-for-approval on-request` launch remains copy-ready because the installed
> CLI cannot set/merge a server-specific approval default through `codex mcp add`.
> Technical rationale and the supported annotation-aware TOML alternative are collapsed.
> VS Code/Copilot's OAuth/DCR detour was not caused by WPCC OAuth metadata: logs show the
> valid Authorization-header config sent a stale value from the fixed secure-input id,
> received `wpcc_invalid_token`/401, then VS Code applied its generic OAuth fallback.
> WPCC now emits a token-record-scoped password-input id so each new token triggers a
> fresh secure prompt. Actual VS Code 1.136.1 Copilot Agent verification passed with no
> OAuth/DCR: **42 tools / 6 prompts**, one `system_info`, credible WP/PHP/MySQL data, and
> successful restart/reconnect. Posts, operation requests, queue and change history did
> not change. The temporary read-only token was revoked and the clipboard cleared.
> Claude Desktop remains CERT_PASS; entry-only merge is now primary and full-file JSON is
> Advanced. No shared MCP auth/runtime or governance code changed, so T2 was not required.
> Focused onboarding/client suites are green. The broad local T0/T1 gates are non-green
> because the retained test credential is invalid: all 82 targeted-T1 failures are the
> three shared token-dependent core suites returning `wpcc_invalid_token`; all five
> onboarding suites selected by that gate pass. Fresh process-only coverage also passes
> Claude **104/0** and the client layer **145/0**, and the token was revoked.
> No commit, package, tag or deployment. **READY FOR FINAL OWNER ACCEPTANCE.**

> **Connections / Assistants onboarding remediation — 2026-09-08:** The customer flow is
> now app-first and uses one Recommended path followed by an actual in-client
> `system_info` check; manual JSON/YAML and transport detail are collapsed under
> Advanced. Product families and surface names are explicit, Cursor uses its supported
> reviewed install link, Continue for VS Code gets an exact one-entry YAML merge flow,
> OpenCode keeps its native command, and Muse is retained only under Other / Experimental
> as Not tested. Codex M2 is addressed with standard tool annotations plus the official
> `codex --ask-for-approval on-request` invocation; no WPCC scope, capability, risk or
> human-approval policy was weakened. Focused final-source coverage passed **1,062/0**;
> actual logged-in browser QA passed at desktop and a narrow effective viewport. Serial
> T2 covered 210 suites (**10,237/15**). Three failures were test-contract/harness issues
> corrected and rerun **88/0**; 12 independently reproducible ACF/option/Site Builder
> assertions are outside this Connections change and keep the general release gate
> non-green. Owner retests remain for Codex CLI permission behavior and Copilot Agent
> mode. No commit, package, tag or deployment was performed. See
> [the focused report](docs/reports/validation/WPCC-CONNECTIONS-UX-REMEDIATION.md).
> **READY FOR FINAL OWNER ONBOARDING RETEST.**

> **Final certification metadata reconciliation — 2026-09-06:** The final release blocker
> was stale certification metadata, not runtime behavior. The registry, current integration
> guide and authoritative certification summary now agree: Claude Code **CERT_GOLD**;
> ChatGPT Desktop, Codex CLI, Antigravity CLI, Cursor, Continue and OpenCode **CERT_PASS**;
> Gemini CLI and Command Code **BLOCKED — EXTERNAL ACCOUNT/PROVIDER**; Claude Desktop
> **NOT TESTABLE — COMPUTER-USE RESTRICTION**; Muse Code **NOT TESTABLE — NOT INSTALLED**;
> GitHub Copilot / VS Code **FAIL** until a post-fix actual-client `system_info` call is
> retained. Windsurf remains absent. The backward-compatible registry enum is preserved:
> `active` represents a certification pass and the narrower `compatible` value carries
> the exact blocked/not-testable/fail verdict in validation notes. No runtime, security,
> transport, setup, credential or WordPress state changed. Remaining non-blockers are the
> external/provider and test-availability limits above, the Copilot checkpoint,
> intentionally unmigrated historical reversal metadata, test-runner accounting, and the
> deferred term/cache REST compatibility finding. Focused metadata/docs checks replace no
> prior regression evidence; T2 was deliberately not rerun for a metadata-only correction.
> **READY FOR RELEASE FREEZE.** The next authorized phase is commit/build/package
> verification; no client re-certification is implied.

> **Claude discovery governance metadata remediation — 2026-09-06:** A final customer-visible contradiction is closed: `/claude/discovery` previously sourced `approval.enforcement` from the retired `wpcc_enforce_approval` option and could report `false` in Standard mode while execution, MCP manifest and security reporting required approval for medium/high/critical risk. All reporting surfaces now share `SecurityModeManager::approval_policy()`; the legacy boolean means “one or more recognized risk tiers require approval,” with additive mode, tier matrix, required-tier and human-approver detail. Focused/relevant suites passed **1,102/0**. A temporary read-only credential proved HTTP 200 and matching Standard policy across Claude discovery, MCP manifest and security report with no content, approval, queue or change-history mutation, then was revoked. T0 **3,457/1**, T1 **7,922/1**, full T2 **10,058/1**: each sole unrelated, environment-sensitive failure passed immediately alone, so T2 is honestly not green but no Claude-attributable regression was observed. See [the focused report](docs/reports/validation/WPCC-CLAUDE-DISCOVERY-GOVERNANCE-REMEDIATION.md). The owner has classified currently local credentials as temporary and their rotation/removal as final-release cleanup; this supersedes the older credential-blocker wording below for this remediation. The working candidate and audit history remain preserved; no commit, package, tag or deployment was performed. **Ready for final client-certification closeout, not release packaging.**

> **Read-only + Antigravity CLI D/E/F remediation — 2026-09-06:** Read-only authorization now checks audited actions across all 42 operations; native `agy` 1.1.27 Read-only certification passed (42 tools, 7 resources, system_info, site-health read, denied write, reconnect). Antigravity CLI is classified as CLI and uses native setup; Gemini CLI remains separate. Focused D 2,259/0 and E/F 24/0. Full T2 **11,462 passed / 55 failed** over 205 suites, **not green**; every failure reproduces on the preserved starting candidate (9,178/56, 203 suites), zero attributable net-new failures. Six attribution reruns pass 232/0 on each candidate. Source and prior certification history preserved; no commit/package. **Do not resume certification until the owner rotates credentials exposed by the environment-file tool-transcript inspection.** See [the full D/E/F report](docs/reports/validation/WPCC-READONLY-ANTIGRAVITY-REMEDIATION.md). This checkpoint supersedes older readiness statements below without deleting their history.

> **Codex onboarding remediation — 2026-09-06:** macOS Codex CLI now uses shell-local export and same-terminal startup; ChatGPT Desktop retains independent launchctl bootstrap. Token reveal/navigation and browser-test claims corrected. Focused + relevant regressions: **756 passed / 0 failed**. Actual codex-cli 0.153.4 MCP engine verified native registration → authentication/initialize → **42 tools / 7 resources** → system_info; fresh shell without export loads zero tools. Temporary credentials revoked; existing candidate/history preserved. No T2 rerun, commit or packaging. See [the remediation report](docs/reports/validation/WPCC-CODEX-ONBOARDING-REMEDIATION.md), including interactive/visual verification limits. **Ready to resume client certification.**


> **Current checkpoint — 2026-09-06:** The preserved real-client candidate now includes verified fixes for manifest approval semantics, customer approval copy and reversal result references. Full T2 completed on unchanged source: 202 suites, audited 9,200 passed/37 failed, zero attributable net-new regressions after baseline/environment investigation. See [the consolidated A/B/C report](docs/reports/validation/WPCC-CERTIFICATION-ABC-REMEDIATION.md) for evidence, legacy-record treatment and cleanup. Resume manual MCP certification; preserve its existing audit history. No commit or release packaging was performed. This checkpoint supersedes older current-state claims below.

**The single authoritative engineering document for this repository.**
Every other handoff, roadmap, submission note and certification has been superseded and
moved under [`docs/archive/`](docs/archive/). If something here disagrees with a document
there, this file is correct.

> **Want the three-minute version first?** Read [`PROJECT_STATUS.md`](PROJECT_STATUS.md).
> It summarises this document — current release, blocking work, what not to change, and a
> resume checklist — and never restates detail. If the two ever disagree, **this file is
> correct** and the status page needs updating.

**Written:** 2026-08-10, at the close of engineering.
**Status:** engineering complete. The next phase is **manual MCP certification**, performed
by the owner. No further code, UX or release-engineering work is planned before it.
**Every number in §2 and §3 was read from the built artifact or the running plugin.**

---

## Table of contents

| § | |
|---|---|
| 1 | [Release history](#1-release-history) |
| 2 | [Package identity](#2-package-identity) |
| 3 | [Runtime identity](#3-runtime-identity) |
| 4 | [Architecture overview](#4-architecture-overview) |
| 5 | [Security decisions that must not be undone](#5-security-decisions-that-must-not-be-undone) |
| 6 | [Repository structure](#6-repository-structure) |
| 7 | [How to build](#7-how-to-build) |
| 8 | [How to test](#8-how-to-test) |
| 9 | [How to release](#9-how-to-release) |
| 10 | [Deployment](#10-deployment) |
| 11 | [Migration and upgrade notes](#11-migration-and-upgrade-notes) |
| 12 | [Known limitations](#12-known-limitations) |
| 13 | [Future roadmap (v1.1 and beyond)](#13-future-roadmap-v11-and-beyond) |
| 14 | [Remaining owner actions](#14-remaining-owner-actions) |
| 15 | [Superseded documents](#15-superseded-documents) |

---

## 1. Release history

| Version | Date | What it was |
|---|---|---|
| `v0.104.0` – `v0.109.0` | Jun 2026 | Step-numbered development series: media, SEO, ACF, WooCommerce, Elementor, workflow and reporting runtimes. |
| `checkpoint/pre-v1-finalization` | Jul 2026 | Marker taken before the v1 finalization arc. |
| **`v1.0.0`** | **2026-08-08** | First public release. Tagged at `81f7d6a`, merged to `main`, and **deployed to production**. |
| **`v1.0.1`** | **2026-09-10** | Certified security, compatibility, rollback, and release-gate hardening candidate. Tagged from `release/v1-security-recut`; deliberately not merged to deployment-triggering `main`. |
| *(security re-cut)* | 2026-08-10 | Not tagged. Fixes an uploads exposure and one Plugin Check error found in the pre-submission audit. **This is the code to submit.** See §2. |
| *(client compatibility remediation)* | 2026-08-11/12 | **Uncommitted working-tree change.** Ten findings from real manual client testing, incl. two functional defects (a Codex/ChatGPT credential key that could never authenticate; eight tool schemas that failed GitHub Copilot's validator and blocked all 42 tools). Windsurf removed; Antigravity and Muse Code added. Held uncommitted while manual certification is pending. |
| *(branding regression fix)* | 2026-08-12 | **Uncommitted working-tree change.** Presentation only. `Brand::picture()` selected artwork by `prefers-color-scheme` — the viewer's OS theme — on two permanently light admin surfaces, so dark-OS admins were served near-white artwork at 1.09:1 contrast. `AppShell.php` and `views/command-home.php` now load the light-surface artwork unconditionally; `tests/test-branding-assets.sh` asserts that invariant instead of the old mechanism. Held uncommitted alongside the remediation. See `PROJECT_STATUS.md` § *PAUSED — RESUME HERE*. |

> **`v1.0.0` was never published to WordPress.org.** It is what production runs, which is
> why the tag has deliberately not been moved — see §9 and §14.

> ### Release-state truth — do not blur these
>
> | | |
> |---|---|
> | On `main` / `origin/main` / **running in production** | `81f7d6a`, tagged `v1.0.0` |
> | The **security re-cut and compatibility remediation** | branch `release/v1-security-recut`, committed and tagged as `v1.0.1`; unmerged and undeployed. |
> | The **submission candidate** | the verified v1.0.1 package in §2 — never the historical `v1.0.0` tag. |
>
> Whether the package in §2 still represents the code to submit depends on whether the
> compatibility remediation is intended to ship with it. It was built **before** that work
> and therefore does **not** contain it. Decide that before rebuilding or submitting.

---

## 2. Package identity

The artifact prepared for submission. Built and independently verified 2026-09-10 from the complete v1.0.1 candidate.

```
File        ai-command-center-1.0.1.zip
Size        1,151,368 bytes
Files       302   (284 PHP, all passing php -l)
SHA256      4b39c78aa5e042351b59c3468c93c7412e198f75a8e3dc9e0591106bdad20c2e
Content-ID  41c15676a416495e4f907b9add7a9e3ea8ecde6e825dc6635b0d2c455c08a26e
Version     1.0.1  ·  DB schema 2.6.0
```

**Content-ID** is the SHA256 of the sorted per-file SHA256 manifest of the extracted
package. Unlike the archive checksum it does not change between builds, so it is how you
prove two builds are the same product:

```bash
unzip -q ai-command-center-1.0.1.zip -d /tmp/pkg
( cd /tmp/pkg && find . -type f -print0 | sort -z | xargs -0 shasum -a 256 ) | shasum -a 256
```

**The build is not byte-reproducible.** Two builds from the same tree differ in ZIP
metadata (mtimes, entry order) while extracting to identical contents. So: verify the
**exact ZIP you upload** against the SHA256; after any rebuild the SHA256 changes and must
be re-recorded; to compare two builds, compare Content-IDs, not archive bytes.

**Root-level `*.md` is never packaged** (the builder hard-fails on any `.md` inside the
archive). A documentation-only commit therefore changes no packaged file and leaves the
Content-ID identical — which is what lets this document record the commit that produced
the artifact it describes.

### Verification performed on this artifact

| Check | Result |
|---|---|
| Packaged vs working tree | 302 identical, 0 differing, 0 not-in-source |
| `php -l` across the package | 284 files, 0 failures |
| JavaScript/MJS syntax | 6 files, 0 failures |
| **Plugin Check** (extracted ZIP, isolated WordPress 7.1) | **0 errors**, 828 deliberately classified warnings |
| Credential patterns in the package | 0 matches across six patterns |
| `.md` / `tests/` / `scripts/` / `sdk/php/` in the package | 0 |
| MCP `tools/list` against the patched build | 42 tools |

### Superseded artifacts — do not submit

| Identity | Why it must not be used |
|---|---|
| SHA256 `6b56aef0…` · Content-ID `cfb4a491…` · 298 files · tag **`v1.0.0`** | Ships the `.htaccess`-only uploads exposure (§5.1) **and** one Plugin Check error. This is what production runs. |
| SHA256 `de0b8d97…` · Content-ID `7748445f…` | Pre-UX-pass runtime. |
| SHA256 `dfbd435c…` | Described an uncommitted tree that no longer exists. |
| SHA256 `06ac6188…` | 2026-08-05 build, 26 commits stale. |
| SHA256 `c6e8a138…` | The first build of this same re-cut. Identical **contents** (same Content-ID) — superseded only because a verification rebuild produced new archive bytes. |

---

## 3. Runtime identity

Read from the running plugin on 2026-08-10, not recalled.

| | |
|---|---|
| Plugin version | 1.0.1 |
| DB schema version | 2.6.0 |
| REST namespace | `wp-command-center/v1` — **a public contract; do not rename** |
| MCP tools exposed | **42** |
| Operations in catalogue | **42** (all reachable) |
| Capabilities | **23** |
| Protection modes | **3** |
| Token scopes | `read_only`, `full` |
| Private stores | 6 |
| AI providers in catalogue | 16 |
| Custom tables | 15 installed by `Schema` + `wpcc_telemetry` created lazily |
| Requires | WordPress 6.4+, PHP 8.0+ (tested to WP 7.1) |

### Protection modes

| Key | Label in the UI | Behaviour |
|---|---|---|
| `client` | **Standard protection** | **Default.** Anything that could affect visitors waits for human approval; low-risk edits pass through. |
| `enterprise` | **Strict approval** | Every change waits, including low-risk. |
| `developer` | **Development — no approval** | No approval step. Never the default; warns before switching. |

Fail-safe: a missing or corrupt `wpcc_security_mode` resolves to `client`, never
`developer`. `Activator` seeds `client` on fresh installs. Reads and diagnostics are never
gated in any mode.

### Capabilities

23 capability constants (`CapabilityRegistry::CAP_*`) gate the 42 operations; 34 operations
carry an explicit capability mapping. Token scope is enforced independently of, and in
addition to, WordPress roles: a token authenticates as a user but is further restricted by
its scope and by the read-only allowlist.

### Built-in AI architecture

Optional and **off until configured**. Connecting an assistant over MCP uses *that
assistant's* model and needs no provider key; Built-in AI is the separate path where the
plugin itself generates content.

```
includes/Ai/
  Platform/      ConnectionStore, CredentialStore, ProviderCatalog, Capabilities,
                 Dialect, ConnectionTester, AiActivity, UsageLedger
  Http/          AiHttpClient, AiEndpointGuard, AiHttpRequest/Response
  Contract/      GenerationRequest/Result/Message/Usage, text + image parts
  AiRuntime, AnthropicClient, CapabilityGate, JsonObjectExtractor
```

- **Connection-centric.** A *connection* pairs a provider with credentials and an
  environment; a *dialect* adapts the wire format. Adding a provider means adding a
  catalogue entry and, if its wire format is novel, a dialect — not touching callers.
- **Credentials** live in `CredentialStore`: never in the connection record, never echoed
  back, never logged, `autoload=no`. A key may instead be defined as a `wp-config.php`
  constant, in which case it is not stored in the database at all.
- **Consumers** are the SEO, Alt Text and Content workflows, each gated by
  `CapabilityGate` and surfaced through the shared Governed Action Panel.
- 16 providers catalogued, including OpenAI-compatible and local endpoints (Ollama,
  LM Studio, vLLM). Every one is disclosed in `readme.txt` under *External services*.

---

## 4. Architecture overview

```
MCP client  ──JSON-RPC/HTTP──▶  McpRestApi ─┐
Admin UI    ──REST──────────▶  AdminRestApi ├─▶ OperationExecutor ─▶ runtime managers
Your code   ──REST──────────▶  RestApi     ─┘         │
                                                      ├─▶ SecurityModeManager  (approve?)
                                                      ├─▶ CapabilityRegistry   (allowed?)
                                                      ├─▶ ChangeRecorder       (audit)
                                                      └─▶ RollbackDelta        (undo)
```

**The load-bearing ideas, in the order they matter:**

1. **One catalogue, three front doors.** `OperationRegistry` is the single source of truth
   for what exists. MCP, the admin REST API and the public REST API are transports over
   the same catalogue, so a capability added once is reachable from all three.
2. **Governance is central, not per-feature.** `SecurityModeManager::requires_approval()`
   holds the mode × risk table. No runtime manager decides for itself whether it needs
   approval.
3. **A token cannot approve its own request.** `ApprovalRuntimeManager` refuses with
   `wpcc_approval_requires_human` in `client` and `enterprise` modes.
4. **Undo is a first-class record, not a diff replay.** `RollbackDelta` stores a
   field-scoped, drift-aware delta: it restores the fields it changed and refuses when the
   current value is not what it left behind. An undo is itself a change and goes through
   approval.
5. **Files are the content store; tables are the index.** `wpcc_patches` and
   `wpcc_snapshots` are queryable mirrors; the payloads live in the private stores (§5.1).
6. **Observation never alters execution.** `Telemetry` and the `EventBus` subscribe to
   `wpcc_audit_recorded` and are behaviour-neutral by construction.

---

## 5. Security decisions that must not be undone

Reversing any of these silently removes a guarantee the product makes to its users.

### 5.1 Private stores — the uploads directories carry a per-install secret

`includes/Security/PrivateStore.php`. Each store under `wp-content/uploads` is named
`wpcc-<name>-<32 hex>`, where the suffix is a 128-bit value generated once per install.

**Why.** Protection used to be a `.htaccess` carrying `Require all denied` plus an
`index.php`. Both are Apache-only: nginx, Caddy and LiteSpeed in non-htaccess mode never
read `.htaccess`, and nginx resolves a directory request against `index.html`, not
`index.php`. Verified against a real nginx before the fix: `wpcc-tokens/manifest.json`,
`wpcc-patches/<uuid>.json`, `wpcc-snapshots/<uuid>.snapshot` and `wpcc-audit/audit.log`
all returned **HTTP 200**, and the fixed manifest filenames handed over every UUID, so no
directory listing was even required. `wpcc-media-snapshots` had no `.htaccess` at all.

**The protection is the unguessable name**, which does not depend on the web server
honouring anything. The `.htaccess`, `index.php`, `index.html` and `web.config` markers are
defence in depth for the servers that do read them. Be honest about the limit: with the
secret, the files still serve — the security is 2^128, not an access rule.

Three properties exist to stop this hardening becoming an outage, and each has a test:

- **Fail safe, not fail secure.** If the host refuses the move, `path()` keeps returning
  the legacy directory with its data intact rather than handing back an empty store, which
  would invalidate every access token on the site.
- **The drain never deletes what it could not move.** The legacy directory is removed only
  once it is genuinely empty.
- **Self-healing.** If `wpcc_storage_suffix` is lost, the suffix is recovered from the
  directory names themselves, so a restored database cannot orphan the tokens and
  snapshots.

### 5.2 Tokens

`wpcc_` + `wp_generate_password(64)`, stored as `hash_hmac('sha256', …, wp_salt('auth'))`,
compared with `hash_equals`. The raw token is shown once, at creation. Revocation takes
effect on the next request.

### 5.3 The approval model

The mode × risk gating table, the human-approver requirement, the risk tiers, the
`CapabilityRegistry`, the read-only scope allowlist and the REST permission callbacks. 158
registered routes, **zero** using `__return_true`. The MCP endpoint returns a real 401 on a
missing token rather than letting WordPress emit a 500.

### 5.4 Shell execution is confined and disclosed

Only two paths run a command: `PhpBinary` (`php -l` when verifying a patch) and
`WpCliBridge`. Both build from a fixed list and pass every argument through
`escapeshellarg()`; neither ever executes assistant-supplied text. Where a host disables
`proc_open`/`shell_exec`, both report themselves unavailable and the rest of the plugin is
unaffected. Disclosed in `readme.txt`.

### 5.5 Uninstall retains by default

Deleting the plugin keeps the audit trail. Full erasure happens only when the owner has
ticked the option first. `uninstall.php` then removes the tables (including the lazily
created `wpcc_telemetry`), prefix-matched options, user meta, and **both** the legacy and
the suffixed store directories.

### 5.6 Network activation is refused

`Activator::activate()` calls `wp_die()` rather than half-installing across a network.

---

## 6. Repository structure

```
ai-command-center.php        Plugin header, constants, array_is_list polyfill, bootstrap
uninstall.php                Retain-by-default deletion policy
readme.txt                   WordPress.org readme (stable tag must equal the header version)
RELEASE_HANDOFF.md           ← this file, the only authoritative handoff

includes/                    Runtime. 281 PHP files, PSR-4-ish under \WPCommandCenter
  Core/                      Bootstrap, Autoloader, Activator/Deactivator, Schema
  Security/                  AuthTokens, PrivateStore, AuditLog, Redactor, PathGuard
  Operations/          (87)  Operation catalogue, executor, runtime managers, governance
  Rollback/            (20)  RollbackDelta core, SnapshotManager, per-domain rollbacks
  Admin/               (58)  Screens, admin REST API, AppShell, row actions
  Ai/                  (29)  Built-in AI platform (§3)
  Mcp/                       MCP JSON-RPC endpoint and server runtime
  Integration/               Per-assistant config generation (11 clients, 5 formats)
  PatchSystem/               File patches with php -l verification
  Telemetry/ Events/         Observe-only layers
  AiAgent/ Seo/ AltText/ Content/ Proposals/ Health/ Diagnostics/ SiteIntelligence/ System/

assets/                      Admin CSS/JS and brand SVGs (packaged)
sdk/javascript/              wpcc-mcp-relay.mjs — the stdio↔HTTP relay (packaged)
sdk/php/                     Example client — NOT packaged
tests/                       197 shell suites + tiered runner (§8)
scripts/build-release.sh     Allowlist packager (§7)
docs/                        Current product/engineering documentation
docs/archive/                Superseded handoffs, roadmaps, reports, certifications
.ai/                         Development-process notes, deploy runbook, audits
artifacts/                   Historical validation evidence
build/                       Build output — gitignored
```

---

## 7. How to build

```bash
bash scripts/build-release.sh          # → build/ai-command-center-1.0.1.zip
```

The script is an **allowlist**, not an exclude list: it copies `ai-command-center.php`,
`uninstall.php`, `readme.txt`, `LICENSE`, `includes/`, `assets/`, `languages/` (if present)
and the MCP relay. Nothing else can reach the package by accident — which is why `tests/`,
`docs/`, `scripts/`, `sdk/php/` and every root `*.md` are absent from it.

It refuses to build when the plugin header version and the readme `Stable tag` disagree.

After building, always re-record the SHA256 (§2) — it changes on every rebuild even when
nothing else has.

---

## 8. How to test

```bash
bash tests/run.sh --tier T0 --changed     # lint + primary suites for what changed
bash tests/run.sh --tier T1 --changed     # all suites for the affected runtimes + core
bash tests/run.sh --tier T2                # every suite (~50 min)
bash tests/<name>.sh                       # one suite
```

197 suites. They are **integration** tests: they need a running WordPress with the plugin
active, `wp-cli`, and `wpcc-env.sh` (git-ignored) providing `WPCC_BASE`, `WPCC_TOKEN` and
`WP_ROOT`.

**Two things that will otherwise cost you an afternoon:**

- **T0 and T1 deliberately do not set the protection mode**, because surprising the
  operator's site is worse than a mode-sensitive result. Roughly twenty suites assume
  changes apply immediately, so they fail on a site in `client` mode. **T2 establishes the
  developer governance baseline and restores it afterwards** — T2 is the tier that
  produces an authoritative number.
- **If a stale `WPCC_TOKEN` in `wpcc-env.sh` no longer authenticates**, REST suites return
  empty results and report hundreds of confusing assertion failures rather than an auth
  error. Check `tools/list` returns 42 before believing a mass failure.

Suite selection is driven by `tests/regression-map.tsv` (62 groups: trigger regex →
primary suite → suite list). Add a row when you add a subsystem.
`tests/regression-quarantine.txt` holds 16 excluded from T0/T1.

Never hardcode a store path in a test — the directories carry a random suffix. Use
`tests/lib/private-store.sh`:

```bash
source "$SCRIPT_DIR/lib/private-store.sh"
AUDIT_DIR="$(wpcc_store_dir wpcc-audit)"
```

### ⛔ Do not run the suites at the current resume point (2026-08-12)

The local certification site was **deliberately reset to a fresh onboarding state after**
the last full run, so that manual MCP certification exercises the real first-install
journey. Every tier creates tokens, approvals, operation history and snapshots, and T2
sets a developer governance baseline — **running any of them destroys that state.**

The gate below is already green and needs no re-proving. See `PROJECT_STATUS.md`
§ *PAUSED — RESUME HERE*.

If you do run them later, `wpcc-env.sh` needs a newly minted full-scope token: the reset
removed every token on purpose, and a stale `WPCC_TOKEN` produces hundreds of empty-result
failures rather than an honest auth error.

### Last full run (2026-08-12, after the client compatibility remediation)

**T2: 7,318 passed, 0 failed, net-new 0** — 199 suites, developer governance baseline,
restored at end. Supporting gates from the same pass:

| Gate | Result |
|---|---|
| Focused — MCP client onboarding contract (new suite) | 141 / 0 |
| Focused — governed rollback routing (new suite) | 19 / 0 |
| MCP regression (29 suites) | 1,365 / 0 |
| T0 | 848 / 0 |
| T1 (56 suites) | 2,431 / 0 |

An earlier T2 in the same pass reported 9 failures. All nine were **test-side expectation
drift**, not product regressions: six hardcoded a roster of 11 clients (Windsurf was
removed, Antigravity and Muse Code added) and three asserted Cursor's old relay field
layout after it moved to native direct HTTP. They were rewritten as structural assertions —
counts compared against the roster itself rather than a literal — because a pinned number
fails as loudly for a correct change as for a defect. One had been passing *falsely*,
matching an explanatory comment rather than a rendered string; it is now scoped to
translatable strings.

### Previous full run (2026-08-10, security re-cut)

**T2: 7113 passed, 36 failed.** Every failure was attributed, none was a regression:

- **18** were test-side hardcoded store paths, exposed by the private-store move. Fixed.
- **18** across six suites (`media-replace-step100-2` 9, `php-verification` 2,
  `real-site-validation` 2, `token-efficiency` 3, `site-builder-step95` 1,
  `woocommerce-product-step93` 1) reproduce **identically at the `v1.0.0` tag** with the
  legacy layout restored, so they are environment-dependent, not caused by the change.
  They were run on nginx + php-fpm rather than the usual Apache stack; re-baseline them on
  the owner's environment before treating any as real.

---

## 9. How to release

1. Land the change; make the tree clean.
2. `bash scripts/build-release.sh`.
3. Extract the ZIP and run Plugin Check **against the extracted package**, never against
   the repository — the repo contains `tests/` and `sdk/php/`, which is where every
   inflated historical error count came from.
4. Record in §2: size, entries, file count, SHA256, MD5, Content-ID, runtime commit.
5. Verify the package matches the tree (`cmp` per file) and that no credential pattern,
   `.md`, `tests/`, `scripts/` or `sdk/php/` is present.
6. Commit the documentation. Because `*.md` is not packaged, this does not move the
   Content-ID — that equality is the evidence the tag describes the artifact.
7. Tag the documentation commit.
8. **Merging to `main` deploys to production within a minute** (§10). Treat it as a
   separate decision from tagging.

> **Record the commit with the checksum, always.** A checksum identifies a file; only the
> commit identifies the product. A previous artifact matched its recorded checksum byte for
> byte while being 26 commits stale.

---

## 10. Deployment

**Production is `mosharafmanu.com`. Deployment is pull-based and automatic.**

```
git push origin main
   → server cron (every minute) runs ~/wpcc-deploy.sh
   → git fetch; if origin/main advanced: git reset --hard origin/main
   → reactivate the plugin if it was active; wp cache flush
   → live in ~1 minute; logged to ~/wpcc-deploy.log
```

**Therefore: pushing to `main` is a production deploy.** Nothing else is required, and
nothing else can stop it. The GitHub Actions workflow is a green no-op — the host blocks
inbound SSH from runner IPs, which is why the model is pull-based. Full runbook, including
the manual fallback: [`.ai/DEPLOY.md`](.ai/DEPLOY.md).

Production currently runs the plugin from `wp-content/plugins/**wp-command-center**/`
containing `ai-command-center.php`. The WordPress.org package installs as
`wp-content/plugins/**ai-command-center**/`. WordPress identifies a plugin by
`<directory>/<main-file>`, so **those are two different plugins** — reproduced locally,
both appear in `wp plugin list`. Installing from WordPress.org onto production without
reconciling would produce two copies; activating both would double-register the REST
routes, the admin menu and the cron handler. Reconciliation is optional and is not required
before submission — see §14.

---

## 11. Migration and upgrade notes

### Schema

`Schema::DB_VERSION = 2.6.0`. `maybe_upgrade()` runs on every request but only calls
`install()` when the stored version differs, so the common path is one option read.
`dbDelta` handles the 15 tables; `wpcc_telemetry` is created lazily on first write and is
deliberately decoupled from `DB_VERSION` (and is therefore listed explicitly in
`uninstall.php`).

### One-time migrations, and how to add another

Two flag-guarded backfills run outside the version gate, because they must reach sites
whose schema version did not change:

| Flag option | What it does |
|---|---|
| `wpcc_migrated_v1` | Backfills the legacy JSON manifests into the index tables. |
| `wpcc_changelog_backfilled` | Seeds the change log from existing history. |
| `wpcc_stores_relocated` | Moves the six uploads stores behind the per-install suffix (§5.1). |

`wpcc_stores_relocated` is the pattern to copy. It matters that it flips **only when every
store actually landed on the suffixed path** — if the host refused a move, the flag stays
unset and the work retries on the next request rather than recording a migration that did
not happen.

### Upgrading a 1.0.0 site to the security re-cut

Nothing is required of the operator. On the first request after the update the six stores
are moved and the flag is set. Verified on a real upgrade: all six moved in a single page
load, 49 tokens and the audit log preserved, no legacy directory left behind. Activation
performs the same relocation, so a fresh install never creates the guessable paths at all.

Rollback to the pre-fix code is possible but leaves the stores suffixed; the old code
would create fresh empty ones and the tokens would appear to vanish. If you must roll back,
rename the directories back first.

---

## 12. Known limitations

Carried into v1 deliberately. None is a defect to go hunting for.

1. **WordPress.org approval is not guaranteed.** The package is at 0 Plugin Check errors,
   but 824 warnings remain — mostly custom-table queries the sniffer cannot follow — and
   ~56 direct filesystem calls plus `proc_open` are legitimate reviewer questions. All are
   disclosed in the readme.
2. **The slug is the highest-variance step in submission.** The auto-derived slug will be
   `wp-command-center`; the package, directory and text domain are all `ai-command-center`.
   It cannot be changed after approval. It is not functionally breaking — every path is
   built from `WPCC_PLUGIN_URL`/`_DIR` — but it is permanent branding.
3. **WooCommerce orders and refunds are untested.** No order flow exists on the test store.
   Products, prices, stock, categories and coupons are tested.
4. **Elementor Pro widgets are untested.** Free version only.
5. **Multisite is unsupported** and now refused at activation rather than half-installed.
6. **The approvals list caps at 100 server-side.** The UI is honest about it
   ("Showing 1–25 of 100 (210 pending in total)") and bulk reject clears the queue, but
   items beyond 100 are unreachable until it is worked down.
7. **Undo costs five clicks under Standard protection**, because an undo is itself a change
   and goes through approval. Correct behaviour, and the most likely source of a
   "but you promised undo" complaint.
8. **The undo approval row reads only "Undo a change"** — it does not name which change.
   Fine with one pending, ambiguous with several.
9. **Double-approve returns success rather than "already approved."** Measured: no second
   queue item and no second change. An API-semantics wart, not a data-integrity bug.
10. **No screenshots or banner** for the directory listing.
11. **No `languages/*.pot` is shipped.** i18n itself is clean — 3,482 gettext calls, all on
    `ai-command-center`, zero mismatches — and WordPress.org generates language packs.
12. **On nginx, private-store security is the unguessable directory name** (§5.1). Storing
    outside the webroot is not portable on shared hosting; this is the trade-off taken.

---

## 13. Future roadmap (v1.1 and beyond)

Nothing here is started. Ordered by value per unit of risk.

**Tier 1 — finish what v1 deliberately left**

1. **WooCommerce orders and refunds**: build an order flow on the test store and exercise
   the untested operations (§12.3).
2. **Name the change in the undo approval row** (§12.8) — the single highest-value UX fix
   outstanding, and small.
3. **Paginate the approvals queue past 100** (§12.6).
4. **Return "already approved" on double-approve** (§12.9).
5. **Screenshots and banner** for the directory listing.

**Tier 2 — platform work the architecture is already shaped for**

6. **Subscribers on the EventBus.** `Events/EventBridge` publishes a typed `RuntimeEvent`
   per audit record with no consumers. Notifications, webhooks and a live dashboard attach
   here with zero runtime change — this is the cheapest large feature in the codebase.
7. **Operations Center.** `program-10-operations-center` holds a read-only live operations
   surface built on existing data.
8. **Multisite** (§12.5) — currently refused, which is the honest position; supporting it
   is a genuine project, not a flag.

**Tier 3 — hardening**

9. **Reduce the 824 Plugin Check warnings**, chiefly by routing custom-table reads through
   a small query layer the sniffer can follow.
10. **Ship a `.pot` file** (§12.11).
11. **Re-baseline `tests/regression-baseline.tsv`** on the owner's environment so the 18
    environment-dependent failures in §8 stop looking like regressions.

**Unmerged branches worth reading before starting anything:** 33 branches remain, most of
them the `program-*` series that is already merged in substance. `program-10-operations-center`
and `feat/ai-content-platform` are the two carrying unlanded work.

---

## 14. Remaining owner actions

Engineering cannot do these. Ordered by urgency.

1. **Rotate the exposed production credentials — do this first.**
   `github.com/mosharafmanu/wp-command-center` is a **public** repository and contains, on
   `main`: the production SSH password (`.ai/handoffs/resume.md`), the host/port/user
   (`.ai/DEPLOY.md`, `docs/product/SESSION-HANDOFF-2026-06-18.md`), and two production WP
   Command Center access tokens (`.ai/audits/CAPABILITY-LOCKOUT-FINDING-F-REPORT.md`,
   `.ai/audits/CLAUDE-DESKTOP-INTEGRATION-FIX.md`, `.ai/handoffs/resume.md`). Both tokens
   were probed and already return `401`, but the SSH password is live, and that host
   auto-deploys this plugin.
   **(a)** rotate the SSH password, the Anthropic key in `wpcc-env.sh`, and both staging
   admin passwords; **(b)** remove the plaintext and push; **(c)** with the credentials
   rotated, history is inert — with 0 forks a rewrite would work, but **making the
   repository private is the single effective action** and is one click. A full
   `git filter-repo` would invalidate `v1.0.0` and every branch SHA, and production deploys
   by `git reset --hard origin/main`, so it would need a forced re-pull.
   **Nothing in this list ships** — the package was re-scanned and is clean.
2. **Submit the verified v1.0.1 ZIP to WordPress.org** only after explicit owner authorization.
3. **Reply to the WordPress.org slug email immediately** when it arrives, requesting
   `ai-command-center` (§12.2). Permanent after approval.
4. **After approval, publish the verified tree to WordPress.org SVN trunk/tag/assets** under separate authorization.
5. **Optional: reconcile the production plugin directory** (§10). Not required before
   submission; the safe sequence is in `docs/archive/RELEASE_HANDOFF-v1.0.0-evidence.md` §7.3.

---

## 15. Superseded documents

Everything below was moved to `docs/archive/` on 2026-08-10 and carries a banner. Nothing
was deleted.

| Archive path | Contents |
|---|---|
| `docs/archive/RELEASE_HANDOFF-v1.0.0-evidence.md` | The previous release handoff, kept unedited as the detailed evidence trail. |
| `docs/archive/handoffs/` | `HANDOFF.md`, `RESUME-HANDOFF.md`, `PROJECT-HANDOFF-WPCC.md`, `HANDOFF-STEP-104/105/107/108/109.md` |
| `docs/archive/roadmaps/` | `WPCC-POST-STEP-101-ROADMAP.md`, `WPCC-RUNTIME-ROADMAP.md` |
| `docs/archive/reports/` | `HOME-POLISH-REPORT.md`, `UX-JOURNEY-REPORT.md`, `RESET-RECORD.md` |
| `docs/archive/submission/` | `SUBMISSION.md`, `SUBMISSION-PACKAGE.md`, `REBUILD-PREP.md` |
| `docs/archive/certifications/` | Six V1/RC certifications and `WORDPRESS-ORG-COMPLIANCE-REPORT.md` |

**Still current** in `docs/`: `OVERVIEW`, `ARCHITECTURE`, `API`, `MCP`, `CAPABILITIES`,
`OPERATIONS`, `SECURITY`, `INSTALLATION`, `QUICKSTART`, `TROUBLESHOOTING`,
`AI-INTEGRATIONS`, `ASSISTANT-CERTIFICATION`, `WORDPRESS-ORG-REVIEWER-NOTES`, `SLUG-REPLY`.
`docs/RELEASE.md` now points here for release process.
