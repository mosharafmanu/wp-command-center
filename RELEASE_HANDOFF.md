# RELEASE HANDOFF — WP Command Center 1.0.0

**This is the final V1 release handoff, not a development handoff.**
Engineering is complete. What remains is independent certification and submission.

**Date:** 2026-08-07 (v1.0.0 source freeze — supersedes the 2026-08-04 finalization pass)
**Every number below was read from the running plugin or the built artifact, not recalled.**

---

## 1. Repository state

| | |
|---|---|
| Branch | `release/v1-finalization` |
| **Source-freeze commit** | `8200074867a9d2c43a4de0e10cc46241049acb8a` (`8200074`) — *the artifact was built from this tree* |
| **Tag target** | the documentation commit that added §5.7, i.e. the commit immediately after `8200074` |
| `main` | `13549c2` — **untouched, not merged** |
| Commits ahead of `main` | 125 (124 at the source freeze + 1 documentation commit) |
| Uncommitted | none tracked. `RESUME-HANDOFF.md` is deliberately untracked and disposable. |
| Remote | **11 commits unpushed**. Push before submitting. |
| Plugin directory | `wp-content/plugins/ai-command-center/` |

**Why the tag points at the documentation commit, not the source freeze.** Artifact
checksums cannot be known until after the build, and the build requires a clean tree — so
the identity recorded here can only be written *after* the commit it describes. Root-level
`*.md` is not packaged (the builder hard-fails on any `.md` inside the archive), so the
documentation commit changes **no runtime file**: the package's Content-ID is identical at
both commits, and that equality is recorded in §5.7 as evidence rather than asserted. Tag
the documentation commit so the tag carries accurate release documentation.

`main` auto-deploys to production. Nothing has been merged. The merge is the owner's
decision and should happen **after** WordPress.org approval, not before.

### What the release-candidate finalization pass changed

Twenty-one commits. Everything below was found by using the product, not by reading it.

| Area | Change |
|---|---|
| Assistants | Eleven clients were served one identical relay config; five cannot read it. Five formats now (JSON `mcpServers`, JSON `servers`, TOML, `httpUrl`, a shell command), plus a second transport — direct HTTP, no Node.js — for six clients. `claude mcp add` emitted `--url`, which that command rejects. |
| Settings | `settings_general_update` wrote an **empty string** over `date_format` and `time_format` and 0 over `posts_per_page`, because a null key in a duplicated field map made the write read `$p[null]`. `admin_email` could never be set at all. |
| Settings | A write that recognised no field returned `updated: true`, consumed a human approval and recorded a rollback point for a change that never happened. |
| Schema | `settings_manage` declared only `action`, so a schema-following assistant could name an action but never a value. Confirmed live: Claude Code put the tagline in the free-text `reason` and produced exactly the no-op above. 30 value fields now declared. |
| Built-in AI | Turning a tool on from the admin produced a tab but no entry point — three places decided "is this on?" and two never learned about the in-admin toggle. For Content that left the feature completely unreachable. |
| Diagnostics | `autoload = 'yes'` matches almost nothing on WordPress 6.6+, so the autoloaded-options check reported 0 B and "Good" on every modern site, and told connected assistants the same. |
| Approvals | The Capabilities screen promised "in the current security mode" and answered with the catalogue's declared flag — "Development — no approval" beside "31 Need approval". |
| Setup | The access-token field on the setup screen was dead: it searched for a placeholder the configuration no longer contained. The connection test also checked the connector for clients that never fetch it, failing a correct setup. |
| Compliance | Plugin Check reported 0 errors across every file the package ships. Eight errors introduced earlier in this same pass were found and cleared before the build. |
| Tests | A macOS `mktemp` bug silently emptied sixteen suites (25 phantom failures in one). The full run now establishes its governance baseline instead of inheriting whatever mode the site happened to be in. |

## 2. Submission package

```
File    build/ai-command-center-1.0.0.zip
Size    1,076,581 bytes (1.0 MB)
Entries 333 zip entries = 298 files + 35 directory entries
SHA256  de0b8d974c655ff5352514f48980c59999bb22caac10d4fc6935d606af945175
MD5     see `md5 -q build/ai-command-center-1.0.0.zip` — build-local, like the SHA256
Runtime 8200074867a9d2c43a4de0e10cc46241049acb8a   (source freeze; clean tree)
Built   2026-08-07, from the documentation commit that follows the source freeze
Version 1.0.0   ·   DB schema 2.6.0   ·   MCP tools 42
```

`298 files = the 292 of the 2026-08-05 build + exactly the 6 new runtime files`
(`UsageLedger`, `GenerationUsage`, `ProviderProvenance`, `SourceContentSignal`,
`CommandPaletteIntegration`, `wpcc-command-palette.js`). That arithmetic is an independent
check that nothing else crept into the package.

All 298 packaged files were verified **byte-identical** to the committed source tree
(`cmp` per file: 298 identical, 0 differing, 0 not-in-source), and all 280 packaged PHP
files pass `php -l`.

**Which commit built the ZIP does not matter; which runtime it contains does.** Root-level
`*.md` is never packaged, so the source freeze and every documentation commit after it
produce a package with the same runtime. That was not assumed — the package was built at
the source freeze and again at the documentation commit, and both extract to Content-ID
`7748445f…`. Use the Content-ID to prove two artifacts are the same product; the SHA256
above identifies only the one file currently in `build/`, and **changes on every rebuild
even when nothing else has**. Re-verify the SHA256 of the exact file you upload.

**Record the commit with the checksum, always.** The artifact this section used to
describe (`06ac6188…`) matched byte for byte while being 26 commits stale — it shipped no
token-creation dialog at all. A checksum identifies a file; only the commit identifies
the product. All 298 shipped files were verified byte-identical to the tree at `8200074`.

**Content identity (build-independent):**

```
7748445f576343df1c783f1e86739cd5b6d1e72ecf7525a37b33143efb4f3f06
```

This is the SHA256 of the sorted per-file SHA256 manifest of the extracted package. Unlike
the archive checksum it does **not** change between builds, so it is the reliable way to
prove that a rebuild produced the same package. Reproduce with:

```bash
unzip -q build/ai-command-center-1.0.0.zip -d /tmp/pkg
( cd /tmp/pkg && find . -type f -print0 | sort -z | xargs -0 shasum -a 256 ) | shasum -a 256
```

> **Every artifact built before 2026-08-07 MUST NOT be uploaded** — including the
> `ad30856` build described by earlier revisions of this section, which predates the
> v1.0.0 freeze work entirely. A date guard is not enough: the superseded `06ac6188…` was
> built on 2026-08-05 and still predated 26 commits. Check the commit **and** a clean tree
> **and** the Content-ID, not just the archive hash.

**The checksum identifies this one built file — it is not a fingerprint of the commit.**
The build is *not* byte-reproducible: two builds from the same clean tree at the same
commit differ in ZIP metadata (per-file mtimes and entry order) while their extracted
contents are identical. So:

- Verify the **exact ZIP you are about to upload** against the SHA256 above.
- After **any** rebuild, the checksum changes — re-record it; a mismatch after a rebuild
  does **not** mean the tree is dirty.
- To prove two builds are equivalent, compare **extracted contents**, not archive bytes.

```bash
shasum -a 256 build/ai-command-center-1.0.0.zip   # 10e1d399…
git rev-parse HEAD                                # 8200074… (or the docs commit after it)
git status --porcelain --untracked-files=no       # empty
```

Top-level folder inside the ZIP is `ai-command-center/`. Verified to contain no `tests/`,
`docs/`, `.git`, `node_modules`, `wpcc-env.sh`, `.DS_Store`, `*.md`, `*.sh`, `composer.json`
or `openapi.json`. Includes the MCP relay at `sdk/javascript/wpcc-mcp-relay.mjs` (required —
the generated connector configuration fetches it) and excludes the unreferenced `sdk/php/`.

**This exact package was installed and exercised on both staging sites** (§5.4) before this
checksum was recorded.

---

## 3. Frozen architecture

| | Value |
|---|---|
| MCP tools | **42** |
| Catalogue operations | **42** |
| Mapped operations (`OPERATION_MAP`) | **34** |
| Capabilities (`ALL_CAPABILITIES`) | **23** |
| `Schema::DB_VERSION` | **2.6.0** |
| Schema tables | 15 created at activation + 1 lazy (`wpcc_telemetry`) |
| Protection modes | 3 |
| REST namespace | `wp-command-center/v1` |
| MCP protocol | `2024-11-05` |
| Database prefix | `wpcc_` |
| Text domain | `ai-command-center` |
| PHP namespace root | `WPCommandCenter\` |
| MCP server key (generated config) | `wp-command-center` |
| Admin menu slug (`HOME_SLUG`) | `wp-command-center` |

### Protection modes

| Constant | Label | Behaviour |
|---|---|---|
| `client` | Standard protection | **Default on a fresh install.** Diagnostic + low run immediately; medium/high/critical wait for approval. |
| `enterprise` | Strict approval | Only diagnostic runs immediately; everything else waits. |
| `developer` | Development — no approval | Nothing waits. Local and staging only; the UI badges and confirm-guards it. |

### Plugin headers

```
Plugin Name:       WP Command Center
Version:           1.0.0
Requires at least: 6.4
Requires PHP:      8.0
Text Domain:       ai-command-center
License:           GPL v2 or later
```

`readme.txt`: `Stable tag: 1.0.0` · `Tested up to: 7.0`

---

## 4. Frozen decisions — do not change without a new release cycle

### 4.1 Branding (frozen until reviewer feedback)

| | Value | Why frozen |
|---|---|---|
| Public product name | **WP Command Center** | Owner decision, 2026-08-03. Deliberately kept despite two `trademarked_term` warnings. |
| WordPress.org slug | **`ai-command-center`** | Permanent after approval. Removes the `wp-` slug flag. |
| Text domain | `ai-command-center` | Must equal the slug or language packs will not load. |

The owner's instruction: *"If WordPress.org reviewers explicitly request a naming change,
we will address it then. Until reviewer feedback arrives, consider the branding decision
frozen."* **Do not re-open this analysis.**

### 4.2 Identifiers that must never follow a slug rename

Changing any of these is a breaking change, not a rename:

- **REST namespace `wp-command-center/v1`** (35 sites) — every client configuration in use
  points at it.
- **MCP server key `wp-command-center`** — user-facing, appears in the config customers paste.
- **Admin menu slug `HOME_SLUG = wp-command-center`** — existing admin URLs.
- **PHP namespaces `WPCommandCenter\`** — internal only.
- **DB prefix `wpcc_`** — renaming forces a data migration for no gain.

### 4.3 Contracts

- **The 42-operation catalogue is closed.** No operation may be added, removed or renamed.
- **Per-runtime error codes** (`wpcc_invalid_content_action`, `wpcc_invalid_woo_action`, …
  32 of them). `InvalidActionContract` keeps the pre-approval guard speaking in the
  runtime's own voice; `tests/test-invalid-action-contract.sh` asserts the map against the
  runtime sources so they cannot drift.
- **Approval gating table** (§3). Changing which tier waits in which mode changes the
  product's core promise.
- **Uninstall retains data by default.** Purge only with `wpcc_delete_data_on_uninstall`.
- **Network activation is refused** — deliberate, see §6.1.
- **Structured failure detail** travels in the error's `data` payload
  (`restored_fields`, `skipped_fields`, `conflicts`). Additive; do not flatten it again.

### 4.4 Test discipline

- **`tests/regression-baseline.tsv` is empty and must stay empty.** A failing assertion is
  either a real regression or a stale expectation. Both get fixed; neither gets recorded.
- **Tests must derive the slug, text domain and main-file name — never hardcode them.**
  The rename broke 27 assertions across 11 suites, every one a hardcoded literal.
- **Run T2 standalone.** Several suites switch protection mode globally; a concurrent run
  against the same database produces meaningless failures. `run.sh` snapshots and restores
  governance state around every suite, but it cannot isolate two runners.

---

## 5. Certifications completed

### 5.1 Local — full regression

```
T2: 185 suites — see §5.6 for the 2026-08-05 re-certification run
```

Baseline empty — nothing is accepted as a known failure.

**The runner now establishes its own governance baseline.** It always restored the
protection mode around each suite, so every suite started where the previous one did — but
that is not the same as starting from a *known* state. Most write suites do not set a mode
themselves and assume changes apply immediately; begin a run on Standard protection and
every one of those writes is answered with `pending_approval` instead. Measured during this
pass: a T2 run begun on Standard reported 107 failures, 76 of them the ACF suites, which run
first and so had nothing before them to blame. All five pass unchanged on Development. Any
"N passed, 0 failed" from a run that did not record its starting mode is uncitable; T2 now
sets it and restores the operator's own on exit.

**A macOS `mktemp` bug had been emptying sixteen suites.** BSD `mktemp` does not accept a
suffix after the `XXXXXX` template, so `mktemp /tmp/name-XXXXXX.php` created a file called
literally `name-XXXXXX.php`; every later run failed "File exists", left the helper path
empty, and turned every assertion depending on it into a comparison against `""`.
`test-change-history` reported 25 failures from this alone, all phantom. Fixed in all
sixteen.

### 5.1b WordPress Plugin Check

```
0 errors across every file the package ships
```

Run against the shipped file set. The remaining errors in the repository are in `sdk/php/`
and development directories, none of which the build includes. Eight errors introduced
earlier in this same finalization pass — seven `UnescapedDBParameter` and one
`wp_function_not_compatible_with_requires_wp` (a WordPress 6.6 function called by a plugin
declaring 6.4) — were found here and cleared before the package was built.

### 5.2 Clean install (isolated WordPress 6.9.5, ZIP only)

**Lifecycle 24 / 24.** Clean install → activate (defaults to Standard protection, 15
tables, DB 2.6.0, no fatal) → relay shipped and reachable → deactivate (site healthy, data
retained) → reactivate → upgrade in place → uninstall (16 tables retained) → reinstall
(data adopted) → uninstall with opt-in purge (0 tables, 0 options).

Verified on that install: MCP handshake `2024-11-05` with 42 tools; all 7 discovery
resources; read-only token refused a write (`wpcc_token_read_only`); all three protection
modes gate correctly with diagnostic reads never gated; a Standard-mode write returned
`pending_approval` and **wrote nothing**; approve → worker → executed; undo restored
content; second undo refused (`wpcc_already_rolled_back`); every runtime answers in its own
error contract.

### 5.3 Purple Surgical (customer-representative staging)

32 posts · 23 pages · 79 products · 716 attachments · 5 users · 11 ACF field groups · Yoast.

| Integration | Result |
|---|---|
| WooCommerce | product list, 79 products |
| ACF | 11 field groups |
| ACF Local JSON | compared by content, **0 drift** |
| Contact Form 7 | 6 forms |
| Yoast SEO | provider correctly detected |
| Elementor | reachable (`wpcc_not_elementor_page` — no Elementor content on this site) |
| Health check | **passed**, 0 non-passing |

**Restored:** 139/139 theme files byte-identical, all content counts at baseline, mode
`client`, site HTTP 200. The old `wp-command-center` directory was removed and the site now
runs `ai-command-center`.

### 5.4 Final artifact

```
Plugin Check on build/ai-command-center-1.0.0.zip
  ERRORS   : 0
  WARNINGS : 814
  TRADEMARK: 2  (both the display name)
```

Measured against the **built artifact**, never the checkout. Every warning family is
resolved to fixed / proven false positive / accepted with evidence in
`docs/WORDPRESS-ORG-COMPLIANCE-REPORT.md`.

---

### 5.4b Release-candidate validation — 2026-08-04 (this pass)

Driven through the product as a customer, on real sites, with the exact package in §2.

**Assistant certification — Claude Code, 12/12, Officially Certified.** The full checklist
in `docs/ASSISTANT-CERTIFICATION.md` §7 executed *in the client itself*, over HTTPS, against
a live WordPress 6.9.5 / PHP 8.3.30 site: connect using the command this plugin generates
verbatim · 42 tools · 7 resources · read with no approval prompt · proposal returning
`pending_approval` · nothing applied · self-approval refused (`wpcc_approval_requires_human`)
· human approval → worker → applied · audit attributed · undo itself gated then applied and
restored exactly · second undo refused (`wpcc_already_rolled_back`) with the site untouched ·
reconnect with all 42 tools. **It is the only client marked Certified**; the other ten need
third-party accounts that were not available, and their rows are blank — meaning not yet
run, not failed.

Step 5 failed on the first attempt, and that failure is what surfaced the `settings_manage`
schema gap and the silent no-op in §1.

**Both staging sites, with the §2 package installed.**

| | webo-euro-gv | lsc-group |
|---|---|---|
| Install | fresh install + activate | update in place |
| WordPress | 6.9.5 | 7.0.2 |
| Tables / DB | 17 / 2.6.0 | 2.6.0 |
| MCP | 42 tools, 7 resources | 42 tools, 7 resources |
| Governed write | `pending_approval`, wrote nothing | `pending_approval` |
| Token self-approval | refused | — |
| Approve → cron worker | executed first attempt, no error | — |
| Audit actor | `System (Cron)` recorded correctly | — |
| Undo | gated, applied, value restored exactly | — |
| Value-less write | refused, naming the accepted fields | — |

**Built-in AI, against the real Anthropic API.** Provider connection created and tested from
the admin (`Healthy`, `claude-sonnet-4-6`, 1966 ms). SEO: 20 suggestions generated from live
content, one applied, reversible. Alt Text: real vision output describing the actual image.
Content: generated, applied and undone from the ✨ WPCC AI row action — the path that was
unreachable before this pass.

**Relay transport.** `scripts/relay-smoke.sh` SMOKE PASS through the shipped stdio relay,
with the correct per-runtime error contract for every runtime.

---

### 5.5 Independent staging certification — 2026-08-03 (found the blocker)

Two production-grade sites, artifact-only install, nothing reused from earlier sessions.

| | Site 1 `webo-euro-gv` | Site 2 `lsc-group` |
|---|---|---|
| WordPress / PHP | 6.9.5 / 8.3.30 | **7.0.2** / 8.3.30 |
| Theme | `webo` (hello-elementor child) | `lsc-group` (custom) |
| Stack | Elementor + Pro 3.35, WooCommerce 10.6.2, ACF Pro 6.4.2, YITH | ACF Pro 6.8.4, CF7 6.1.6, Yoast 28.1 |
| Installed for coverage | Rank Math, CF7 (both removed afterwards) | — |

**Five defects found. Four were code and are fixed (`5973e64`, `6c49bed`, `b6c46ec`);
the fifth is this document's own rebuild instructions, corrected in §2/§10.1.**

1. **BLOCKER — `PostMetaRollbackStore` stripped backslashes from every snapshot.**
   `add_post_meta()`/`update_post_meta()` run `wp_unslash()` recursively, so snapshots
   lost one level of backslashes *at capture time*; restore faithfully wrote back the
   damaged document. An Elementor page went `2032B/5\/valid` → patch → **rollback →
   `2027B/0\/INVALID JSON`**. Undo destroyed the page it was asked to restore. Also
   affected the ACF and Bulk runtimes. Fixed and verified byte-identical.
2. **HIGH — telemetry recorded `error_code="Array"` on successful operations** (90 of 159
   rows on a fresh install) and raised `Array to string conversion` warnings 4-5× per
   operation on the main happy path.
3. **MEDIUM — `content_list`/`content_get` were the only pure reads in the catalogue that
   waited for approval in Strict mode**, contradicting the Settings screen's own promise
   that "questions and diagnostics are never held back in any mode".
4. **MEDIUM — `acf_inventory` reported `unsynced: 0`** (false all-clear) on a site where
   `acf_json_status` correctly reported 9 of 9 groups out of sync.
5. **MEDIUM — the build is not byte-reproducible**, contradicting §10.1. See §2.

**Verified on both sites from the rebuilt artifact:** clean install → activate (16 tables,
DB 2.6.0, mode `client`, no fatal) · MCP handshake `2024-11-05` with **42 tools** and
7 resources · a Standard-mode write returned `pending_approval` and **wrote nothing**
(post count unchanged, zero rows in any status) · **the agent cannot self-approve**
(`wpcc_approval_requires_human`) · admin approval in the browser → executed, attributed
`resolved_by_type=wp_user` · undo restored content exactly, second undo refused
(`wpcc_already_rolled_back`) · a UI undo correctly reported "waiting for your approval —
nothing has changed yet" · read-only token refused every write (`wpcc_token_read_only`)
while its permitted reads worked · all three protection modes gate per §3 · WooCommerce
stock/price write + rollback restored exactly · SEO certified on **both** providers with
provider-correct storage (Rank Math single `rank_math_robots` array; Yoast three split
keys) · theme `functions.php` patch → apply → rollback byte-identical with hash
verification, and applying it demanded `APPLY_PATCH` + reason **even in developer mode** ·
`acf_json_sync` refused a blanket rewrite (`wpcc_acf_sync_scope_required`) · full
lifecycle **6/6 on both sites** (deactivate → reactivate → uninstall-retain 16 tables →
reinstall-adopt → uninstall-purge 0 tables / 0 options / 0 tokens, site HTTP 200
throughout).

**Both sites were restored to their exact pre-certification baselines** (content counts,
active-plugin lists, Elementor `_elementor_data` byte-identical, theme `acf-json`
byte-identical, product meta at baseline, plugin fully purged).

### 5.7 Source freeze and final artifact — 2026-08-07

The freeze pass. Everything below was measured at the tree that became `8200074`, not
recalled from an earlier run.

**Quality gates**

| Gate | Result |
|---|---|
| T0 (20 suites, changed-file signal) | **1165 passed · 0 failed** · net-new 0 · 262s · exit 0 |
| T1 (51 suites) | **2155 passed · 0 failed** · net-new 0 · 843s · exit 0 |
| **T2 (195 suites — every suite)** | **6990 passed · 0 failed** · net-new 0 · 3519s · exit 0 |
| PHP lint | 280/280 source files clean; 280/280 packaged files clean |
| JS syntax (`node --check`) | 6/6 clean, including the MCP relay |
| WordPress Plugin Check | **0 errors**, 824 warnings |
| MCP tool count | **42** (dev site and fresh isolated install) |

T2 sets its own governance baseline (developer + capabilities + all three Built-in AI
tools on) so the headline number does not depend on the operator's mode. T0/T1 inherit the
site's mode and were run against the same baseline.

**Isolated installation smoke test** — WordPress 7.0.3, ZIP only, no provider key present.

Install · activate · Home / Approvals / Changes / Settings / Built-in AI all render ·
defaults correct on first run (Standard protection, SEO + Alt Text + Content all **off**,
zero connections, empty usage ledger) · MCP `initialize` OK · **42 tools** · read call OK ·
Standard-mode visitor-affecting write returned **`pending_approval`** with the published
post count unchanged · **MCP self-approval refused with `wpcc_approval_requires_human`**,
request left `pending_review`, site unchanged · smoke request cancelled · deactivate →
reactivate → still initializes at 1.0.0 / DB 2.6.0 / 42 tools.

**One known, non-blocking defect found during the smoke test.** A fresh install with no
default connection emits two `Undefined array key` notices on the Built-in AI screen:
`ai-setup.php` passes `$wpcc_conns[ $wpcc_default ] ?? []` into
`ConnectionStore::is_configured()`, and `CredentialStore::has_secret()` reads `$conn['id']`
and `$conn['provider']` without guarding an empty array. It is **pre-existing** — the
identical call is in `main`, in the pre-freeze commit and in the freeze commit; only the
line number moved. The page renders fully, nothing is displayed with `WP_DEBUG` off, and no
behaviour is affected. Not fixed here because changing `CredentialStore.php` after the fact
would invalidate the T2 run that certified this exact tree. Fix for 1.0.1: guard the two
reads (`$conn['id'] ?? ''`, `$conn['provider'] ?? ''`) or return early on an empty `$conn`.

**Artifact provenance.** The package was rebuilt from scratch at `8200074` with a clean
tree; the pre-freeze ZIP was deleted rather than reused. Its Content-ID
(`7748445f…`) is **identical** to the pre-commit build, which proves the freeze commit
changed no file contents — only the archive SHA256 moved, as it does on every rebuild.

**Test-infrastructure note (does not ship).** `tests/run.sh`'s `GOV_RESTORE` passes its
state snapshot as a positional argument to `wp eval`, which this WP-CLI build rejects
(`Error: Too many positional arguments`); the call is `2>/dev/null`, so the failure is
silent and governance restore is a **no-op in this environment**. It did not affect any
result — T2 sets its baseline once, globally, at the start — but it means the operator's
protection mode is **not** restored when a run ends, despite the runner announcing that it
will be. Restore it by hand after any T0/T1/T2 run, or fix `GOV_RESTORE` to pass the
snapshot via an environment variable instead of `$argv`.

---

## 6. Known limitations

### 6.1 Intentional — by design, do not "fix"

| Limitation | Why |
|---|---|
| **Single-site only; network activation refused** | Governance is per-site: tokens, protection mode, approvals and history all assume one site's options and one set of `wpcc_` tables. Network activation would make it ambiguous which site an approval belongs to. `Activator` calls `wp_die()` with an explanation. Per-site activation inside a network works normally. |
| **Patches cannot create or delete files** | The engine modifies existing files, snapshotting each first so the change is reversible. The error says so rather than blaming a missing path. |
| **Uninstall keeps data by default** | An accidental uninstall must be recoverable. |
| **Undo skips drifted fields** | A field changed since the original write is **skipped, never clobbered**. Reported as `wpcc_rollback_partial` with `restored_fields`, `skipped_fields` and `conflicts`. |
| **AI features off until a key is added AND the tool is switched on** | No outbound call is made without a key, and adding a key alone enables nothing — each of SEO, Alt Text and Content is switched on individually under Settings → Advanced → Built-in AI. |
| **Builder/proposal dev UI ships off** | Behind `WPCC_PROPOSALS_DEV_UI`. |
| **Node.js required on the client machine — for connector clients only** | The relay is a stdio↔HTTP bridge run by the assistant, and six clients use it. The other five (GitHub Copilot / VS Code, Claude Code, Codex CLI, ChatGPT, Gemini CLI) connect over direct HTTP and install nothing. The setup screen names which applies; `readme.txt`, `INSTALLATION.md`, `QUICKSTART.md` and `AI-INTEGRATIONS.md` all state both paths. |

### 6.2 Reviewer risks — real, and accepted

| Risk | Assessment |
|---|---|
| **Two `trademarked_term` warnings on the display name "WP Command Center"** | Both are **warnings, not errors**. WordPress has stated: *"The WordPress trademark does not cover the abbreviation 'WP,' and you are free to use it in any way you see fit."* A reviewer may still ask for a rename. **Owner has frozen the name and will address it only if asked.** |
| **The `Plugin Name:` header will auto-derive the wrong slug** | See §7. This needs a manual action at submission and is the single highest-risk step. |
| **814 warnings is a large number** | Dominated by 233 template-local variables in view files, and 370 direct-query/no-caching findings on the plugin's own 16 tables. All documented with evidence. |

### 6.3 Non-bugs — things that look wrong and are not

| Observation | Explanation |
|---|---|
| `wpcc_telemetry` absent on a fresh install | Created lazily on first write, deliberately decoupled from `DB_VERSION`. Every read guards on its existence. |
| Health check warns `woocommerce_health` on a site without WooCommerce | Honest reporting that a store check was skipped. |
| `elementor_manage` returns `wpcc_not_elementor_page` on staging | Elementor is active but the site has zero Elementor-built pages. |
| REST namespace still says `wp-command-center/v1` | Deliberate — §4.2. |
| MCP server key still says `wp-command-center` | Deliberate — §4.2. |
| Historical certification reports still say "WP Command Center" and reference old artifacts | They are a record of the programme and carry SUPERSEDED banners. |
| `wp db query "SHOW TABLES"` returns nothing locally | A wp-cli quirk on this machine. Query through `$wpdb` instead. |

---

### 6.4 Deferred to 1.1 — known, decided, not blocking

Each of these was found by real use during the release-candidate pass, judged, and left.
None prevents a customer completing any workflow.

| Item | Why it is not a blocker |
|---|---|
| **`user_manage`, `menu_manage`, `woocommerce_manage` declare no value parameters** | Same schema gap that was fixed for `settings_manage`, covering 34 more write actions. They do **not** share the null-key corruption, which was specific to the settings maps — a schema-following assistant simply cannot express these writes and will be told so. Fixing them means deriving ~80 parameter names across three runtimes, two of which have no central field map; declaring one wrongly would advertise something that does not work, which is the defect being fixed. Wanted, not rushed. |
| **A malformed request still consumes a human approval** | Validation runs after the approval gate, so a doomed request costs a decision before it fails. It now fails *loudly* with a message naming what it needed, instead of reporting success — the harmful half is fixed. Moving validation ahead of the gate is engine work. |
| **84% of change-log rows carry no `target_key`/`target_summary`** | The record is accurate but coarse: the Changes screen can say "Settings · 4 changes" without naming which setting. Undo works and restores exactly, and the approval card spells out what will change *before* you decide, which is where the decision is made. `readme.txt` was corrected to claim only what is true. Populating targets touches ~20 runtimes. |
| **Continue (YAML) and OpenCode (own schema) still emit generic JSON** | Both are marked neither Recommended nor Certified. Their configuration is the standard `mcpServers` block, which may need hand-adjusting for those two clients. |
| **Cline is not in the registry** | 5M+ installs and the research stands, but adding a client at release candidate that nobody can run the checklist against would ship an untested claim. Config prepared in `docs/ASSISTANT-CERTIFICATION.md` §6.7. |
| **Ten of eleven assistants are uncertified** | Each needs a third-party account or application. The product makes no claim for them: `Certified` renders only for a client with a recorded end-to-end run, and the other ten carry no certification badge at all. |
| **No directory screenshots or banner** | A listing asset, not a functional gap. `readme.txt` has no `== Screenshots ==` section, so nothing is promised that is missing. |
| **`rollback_available: true` on changes recorded `reversible=0`** | Envelope promises what the change log denies. Engine-level, pre-existing, carried forward from the previous handoff. |
| **Qoder, Tencent CodeBuddy, Trae, Qwen Code, Kilo Code** | Largest remaining assistant gaps, China ecosystem especially. |
| **Windows relay bootstrap** | The connector configuration assumes `bash` and `curl`. Direct HTTP is the Windows-safe path today and is what the recommended clients use. |
| **`test-real-site-validation.sh` rewrites a tracked file** | It regenerates `artifacts/step-36-validation/validation-evidence.json` on every run, so `git status` is dirty after any T2 and "working tree clean" needs a `git checkout -- artifacts/` first. Harmless — `artifacts/` is excluded from the package — but confusing if you do not expect it. |

---

## 7. WordPress.org submission instructions

### 7.1 Order of operations

1. **Verify the artifact checksum** matches §2. If it differs, rebuild (§10) — do not upload.
2. Go to <https://wordpress.org/plugins/developers/add/>.
3. Upload `build/ai-command-center-1.0.0.zip`.
4. Provide a brief overview of what the plugin does.
5. **Watch for the automated email immediately.**

### 7.2 The automated email — and the one manual action

WordPress.org derives the slug from the `Plugin Name:` header, which reads
**"WP Command Center"**. The automated email will therefore propose:

```
wp-command-center          ← WRONG. This is the slug we renamed to avoid.
```

> "When you submit a plugin, you get an automated email telling you what the slug will be.
> This is populated based on the value of your Plugin Name in your main plugin file."
> — Plugin Developer FAQ

**Reply to that email immediately** and request:

```
ai-command-center
```

> "The slug can be changed while a plugin is in review but we **cannot** change it once
> your plugin is approved."
> — Make WordPress Plugins, *Reminder: We can't rename plugins post approval*

Suggested reply:

> The plugin package, directory, main file and text domain are all already
> `ai-command-center`. Could the slug be set to `ai-command-center` rather than the
> auto-derived `wp-command-center`? The display name "WP Command Center" is intentional.

**If no reply is received before approval, the slug is permanent and the package no longer
matches it.** This is the highest-risk step in the whole submission.

### 7.3 Manual actions for the owner

1. Reply to the slug email (above) — **time-critical**.
2. Decide whether to merge `release/v1-finalization` into `main`. `main` auto-deploys;
   recommend merging **after** approval.
3. Review the remaining staging token `AI Full Access 2026-08-02 02:18` (full scope,
   active). Its auto-generated label matches the admin AI-Setup flow rather than any token
   created during certification, so it was left alone.
4. **Rotate the staging SSH password.** It was exposed in an earlier session transcript
   while diagnosing an upload failure. It appears in no report, commit or file.
5. Screenshots for the directory listing — not required for approval, recommended before
   public launch.

---

## 8. Post-submission checklist

- [ ] Slug email answered and `ai-command-center` confirmed in writing
- [ ] Approval email received; note the approval date
- [ ] SVN repository provisioned; note the URL
- [ ] Tag `1.0.0` committed to SVN `/tags/1.0.0/`
- [ ] `/trunk/` matches the tag
- [ ] `readme.txt` renders correctly on the public plugin page
- [ ] Public page shows the display name **WP Command Center** at
      `wordpress.org/plugins/ai-command-center/`
- [ ] Screenshots and banner uploaded to SVN `/assets/`
- [ ] `git tag v1.0.0 7a404df` and push the tag
- [ ] Merge `release/v1-finalization` → `main` (triggers production deploy)
- [ ] Verify production after the deploy: 42 tools, invariants, health check
- [ ] Confirm generated client configs on production reference
      `plugins/ai-command-center/` for the relay

---

## 9. Reviewer-response checklist

If WordPress.org requests changes, work this order:

1. **Read the request literally.** Fix what was asked, not what you infer.
2. **Do not re-open frozen decisions** (§4) unless the reviewer explicitly requires it.
3. **If a naming change is requested** — this is the one frozen decision that unfreezes on
   reviewer request. The owner has pre-authorised addressing it *at that point*. Report the
   request and get the owner's chosen name before changing anything.
4. **If a security change is requested** — never weaken governance, approval protection,
   capability enforcement, rollback safety, security defaults or audit integrity to satisfy
   a request. If a request appears to require that, raise it rather than comply silently.
5. **After any change:**
   - `bash tests/run.sh --tier T2` standalone → expect 0 failures (see §5.6)
   - Rebuild: `bash scripts/build-release.sh`
   - Plugin Check the **artifact** → expect 0 errors
   - Re-run the clean-install lifecycle → expect 24/24
   - Verify invariants unchanged (§3)
6. **Bump the version** in all four places (`ai-command-center.php` header, `WPCC_VERSION`,
   `readme.txt` stable tag, changelog) before resubmitting.
7. Reply to the reviewer describing exactly what changed and what was verified.

---

## 10. Rollback / rebuild procedure

### 10.1 Rebuild the identical artifact

```bash
cd wp-content/plugins/ai-command-center
git checkout release/v1-finalization
git rev-parse HEAD                 # must be 8200074867a9d2c43a4de0e10cc46241049acb8a
                                   # (or the documentation commit directly after it —
                                   #  root *.md is not packaged, so the runtime is identical)
git status --porcelain --untracked-files=no       # must be empty
rm -f build/ai-command-center-1.0.0.zip
bash scripts/build-release.sh
shasum -a 256 build/ai-command-center-1.0.0.zip   # will DIFFER from §2 — expected
unzip -q build/ai-command-center-1.0.0.zip -d /tmp/pkg && \
( cd /tmp/pkg && find . -type f -print0 | sort -z | xargs -0 shasum -a 256 ) | shasum -a 256
                                   # must be 7748445f…  ← this is the real equality check
```

> `rm -f` the ZIP rather than `rm -rf build`: `build/freeze-evidence/` holds the T0/T1/T2
> logs and the pre-restore option and request backups, and is git-ignored but not
> reproducible.

**A rebuild produces a DIFFERENT checksum from §2 even when the tree and commit are
correct.** The archive is not byte-reproducible: per-file mtimes and entry order vary
between runs while the extracted contents are identical. This was verified — two builds
from the same clean tree at `b6c46ec` gave `7cc74327…` and `44492221…`, and `diff -r` of
the extracted trees showed no difference.

So a checksum mismatch after a rebuild is **expected** and does not indicate a dirty tree.
What matters is that the checksum recorded in §2 belongs to the exact file you upload:

```bash
# equivalence check between a rebuild and the recorded artifact
mkdir -p /tmp/a /tmp/b
unzip -q build/ai-command-center-1.0.0.zip -d /tmp/a
unzip -q <the-artifact-matching-§2>.zip     -d /tmp/b
diff -r /tmp/a /tmp/b && echo "contents identical"
```

If you rebuild and intend to ship the rebuild, **update §2 with the new checksum** and
upload that file.

### 10.2 Roll back an unwanted change

```bash
git reset --hard 7a404df           # discards working changes
# or, to keep history:
git revert <bad-commit>
```

### 10.3 Recover the plugin directory name

The directory must be `ai-command-center` and the main file `ai-command-center.php` — the
build reads `"$ROOT/$SLUG.php"`. If the directory was renamed:

```bash
mv wp-content/plugins/<wrong-name> wp-content/plugins/ai-command-center
wp plugin activate ai-command-center
# Renaming an ACTIVE plugin's directory leaves a stale active_plugins entry:
wp eval 'update_option("active_plugins", array_values(array_filter(get_option("active_plugins",[]), fn($p)=>is_readable(WP_PLUGIN_DIR."/".$p))));'
```

The health check's `plugin_integrity` will report the stale entry until it is cleared —
that is the product working, not a fault.

### 10.4 Data safety

Rolling back code **never** requires a data migration. The DB prefix `wpcc_` and
`DB_VERSION 2.6.0` are unchanged across the whole release branch. Tokens live in
`wp-content/uploads/wpcc-tokens/` and survive directory renames.

---

## 11. Where things are

| | |
|---|---|
| Product documentation | `docs/` — `README.md` is the index |
| Submission notes | `docs/SUBMISSION.md` |
| Compliance evidence | `docs/WORDPRESS-ORG-COMPLIANCE-REPORT.md` |
| Closeout certification | `docs/V1-CLOSEOUT-CERTIFICATION.md` |
| Release process | `docs/RELEASE.md` |
| Superseded reports | `docs/*CERTIFICATION*.md`, `V1-FINALIZATION-REPORT.md` — banner-marked |
| Staging credentials | `wpcc-env.sh` (git-ignored). **Never echo, print, log, commit or report its contents.** |
| Staging site | Purple Surgical — customer-representative, restored to baseline |

---

## Next Mission

The release-candidate finalization pass is complete (§1, §5.4b). Engineering is done and
the package in §2 was installed and exercised on two real sites before its checksum was
recorded.

**What is left is not engineering.**

### 1. Submit (owner action)

Follow §7 exactly, in order. The slug is the single highest-risk step: the `Plugin Name:`
header auto-derives the wrong one, and `docs/SLUG-REPLY.md` is time-critical — it has to go
back promptly once the review team writes.

Verify the checksum in §2 against the exact ZIP you are about to upload. If it differs, you
are not uploading the certified artifact.

### 2. Optional before submitting — a third independent site

Two real sites were certified this pass (§5.4b): a fresh install on WordPress 6.9.5 and an
update in place on 7.0.2. A third, on different hosting with a different theme and plugin
mix, would add real evidence. It is worth doing if there is time; it is not a blocker, and
the two that were done were done with the shipped ZIP rather than the source tree.

If you do it, treat this document as a hypothesis rather than a record:

- **Do not trust any PASS result here.** Every number was produced by a previous session.
- **Install only the ZIP.** Do not test the source tree. Do not reuse this session's tokens
  or fixtures without reading them first.
- **This programme has already produced one certification that measured the wrong target
  entirely**, and several "regressions" that were the harness contending with itself — a
  macOS `mktemp` bug alone accounted for 25 phantom failures in a single suite (§5.1).
  Assume you can make the same mistakes.
- **Verify what you assert.** If you claim something passed, be able to show the command
  and its output.

### 3. After approval

Merge `release/v1-finalization` into `main` — not before. `main` auto-deploys.

### 4. Then 1.1

§6.4 lists what was deliberately deferred, with the reasoning for each. The two worth
starting with, because they are the same defect class already fixed once for
`settings_manage`: declared value parameters for `user_manage`, `menu_manage` and
`woocommerce_manage`, and moving payload validation ahead of the approval gate so a
malformed request stops costing a human decision.
