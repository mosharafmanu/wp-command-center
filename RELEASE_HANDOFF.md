# RELEASE HANDOFF — WP Command Center 1.0.0

**This is the final V1 release handoff, not a development handoff.**
Engineering is complete. What remains is independent certification and submission.

**Date:** 2026-08-03
**Every number below was read from the running plugin or the built artifact, not recalled.**

> ### ⚠️ STATE SECTIONS SUPERSEDED — read `SESSION-HANDOFF.md` first
>
> This document is **still authoritative for the frozen decisions in §4** (branding, slug,
> identifiers that must never move, contracts, test discipline). Those stand unchanged.
>
> Its **state** sections — §1 repository, §2 package checksums, §5 certification counts —
> describe commit `b6c46ec`. Work has landed since: the branding commits, per-assistant MCP
> configuration, and a product-experience pass. As of **2026-08-04** the tree is at `f62909b`
> with 28 uncommitted files, and `build/ai-command-center-1.0.0.zip` matches neither the
> checksum in §2 nor the current tree.
>
> Do not read the numbers below as current. `SESSION-HANDOFF.md` has them.

---

## 1. Repository state

| | |
|---|---|
| Branch | `release/v1-finalization` |
| Commit | `b6c46ec49ec98f898e849bf016ff532a5bfbc3cd` (`b6c46ec`) |
| Remote | `origin/release/v1-finalization` is BEHIND — 4 commits unpushed |
| `main` | `13549c2` — **untouched, not merged** |
| Commits ahead of `main` | 57 |
| Uncommitted | none |
| Plugin directory | `wp-content/plugins/ai-command-center/` |

> **Superseded:** this section previously recorded `7a404df` / 53 commits. That commit
> shipped four defects found by the independent staging certification of 2026-08-03,
> including a **release blocker** in the rollback subsystem. See §5.5.

`main` auto-deploys to production. Nothing has been merged. The merge is the owner's
decision and should happen **after** WordPress.org approval, not before.

### The last ten commits

```
7a404df  docs: WordPress.org submission notes
8c5704c  docs(release): record the slug decision and what must never move with it
75eae45  test: derive the slug in the last three suites the rename touched
714bf40  test: stop hardcoding the plugin slug, text domain and file name
e23c39c  chore(release): rename the WordPress.org slug to ai-command-center
96bdc82  docs: V1 closeout certification — zero open items, one verdict
d01fb0a  fix(discovery): stop advertising an operation as available when its integration is missing
7dab8c8  test(health): compare non-transient options, not the raw option count
da96af2  test: empty the regression baseline — nothing is accepted any more
7b52b58  docs(compliance): account for every Plugin Check finding with evidence
```

---

## 2. Submission package

```
File    build/ai-command-center-1.0.0.zip
Size    964,440 bytes (944 KB)
Entries 318 (284 files)
SHA256  4449222140b441c2c5d2374fdeba9cc69fac04c451eb130d3c2dd6cf115e268d
MD5     a1a268dbec2abeb12ae95e58ba4ac0d2
Built   2026-08-03 from b6c46ec
```

**Content identity (build-independent):**

```
dffb14139e2768c4b40cf6b50c43ad59724b0ffcd5654b2d2fa70d8e512bbab9
```

This is the SHA256 of the sorted per-file SHA256 manifest of the extracted package. Unlike
the archive checksum it does **not** change between builds, so it is the reliable way to
prove that a rebuild produced the same package. Reproduce with:

```bash
unzip -q build/ai-command-center-1.0.0.zip -d /tmp/pkg
( cd /tmp/pkg && find . -type f -print0 | sort -z | xargs -0 shasum -a 256 ) | shasum -a 256
```

Verified at release time: a rebuild from the clean tree at `10145b4` produced a different
archive checksum (`d755467c…`) but the **same** content identity `dffb1413…`, and `diff -r`
of the two extracted trees reported no difference. The shipped file is the `b6c46ec` build;
`10145b4` adds only `tests/` and `RELEASE_HANDOFF.md`, both excluded from the package.

> **The previous artifact (`022e994a…`, built from `7a404df`) MUST NOT be uploaded.**
> It contains the rollback-corruption blocker described in §5.5.

**The checksum identifies this one built file — it is not a fingerprint of the commit.**
The build is *not* byte-reproducible: two builds from the same clean tree at the same
commit differ in ZIP metadata (per-file mtimes and entry order) while their extracted
contents are identical (verified with `diff -r`). So:

- Verify the **exact ZIP you are about to upload** against the SHA256 above.
- After **any** rebuild, the checksum changes — re-record it; a mismatch after a rebuild
  does **not** mean the tree is dirty.
- To prove two builds are equivalent, compare **extracted contents**, not archive bytes.

**Verify before uploading** — if the checksum differs, the artifact is not the certified one:

```bash
shasum -a 256 build/ai-command-center-1.0.0.zip
```

Top-level folder inside the ZIP is `ai-command-center/`. Contains no `tests/`, `docs/`,
`.git`, `node_modules`, `wpcc-env.sh`, `.DS_Store` or `*.md`. Includes the MCP relay at
`sdk/javascript/wpcc-mcp-relay.mjs`.

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
T2: 181 suites — 6,361 passed, 0 failed
```

Run **five times undisturbed** across the programme, identical every time. Baseline empty.

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
   - `bash tests/run.sh --tier T2` standalone → expect 6,361 / 0
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
git rev-parse HEAD                 # must be b6c46ec49ec98f898e849bf016ff532a5bfbc3cd
git status --porcelain             # must be empty
rm -rf build
bash scripts/build-release.sh
shasum -a 256 build/ai-command-center-1.0.0.zip
```

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

**The next Claude session is NOT allowed to perform engineering work immediately.**

Your first and only responsibility is to **independently certify WP Command Center on one
completely different production-grade website** — not this machine, not Purple Surgical, not
the isolated lifecycle install. A different real site, with its own theme, plugins, content
and hosting.

### Treat this as independent evidence

- **Do not trust any PASS result in this document.** Every number here was produced by a
  previous session. Your job is to find out whether they are true on a site nobody has
  tuned them against.
- **Re-prove everything from first principles.** Install only the ZIP. Do not test the
  source tree. Do not reuse this session's tokens, scripts or fixtures without reading them
  first.
- **A previous certification is a hypothesis, not a fact.** This programme has already
  produced one certification that was later found to have measured the wrong target
  entirely, and several "regressions" that turned out to be the harness contending with
  itself. Assume you can make the same mistakes.
- **Verify what you assert.** If you claim something passed, be able to show the command
  and its output. If you cannot reproduce it, say so plainly rather than inheriting the
  claim.

### What to certify

Install the artifact (§2, checksum-verified) on the new site and prove, independently:

1. Clean install, activation, default protection mode, schema, no fatal
2. MCP connection end to end — handshake, 42 tools, a real read
3. Governance — a write in Standard protection waits and **writes nothing** until approved
4. Approve → execute → change recorded → undo → double-undo refused
5. All three protection modes
6. Token scopes — a read-only token cannot write
7. Whichever integrations that site actually has (WooCommerce, ACF, Elementor, CF7, an SEO
   plugin) — and say honestly which it does not have rather than skipping silently
8. Upgrade, deactivate, reactivate, uninstall (retain), reinstall, uninstall (purge)
9. Plugin Check against the artifact
10. The site is left exactly as you found it

### Then

- **If you find a release-blocking issue:** fix it, re-test it, run the full regression,
  rebuild, re-certify, and continue until it is closed. Only then report.
- **If you find none:** produce a final certification verdict for WordPress.org submission —
  one verdict, with the evidence behind it.

Do not begin engineering before certification is complete. Do not modify the code to make a
certification pass.
