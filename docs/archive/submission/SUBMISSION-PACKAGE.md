> # SUPERSEDED — HISTORICAL RECORD ONLY
>
> This document is **not current** and must not be used to verify, build, or ship
> anything. It is kept because it records what was true when it was written.
>
> **The single authoritative engineering document is [`RELEASE_HANDOFF.md`](../../../RELEASE_HANDOFF.md) at the repository root.**
>
> **Still useful for:** the WordPress.org submission walkthrough and reviewer-facing notes. Every checksum in it is superseded.
>
> Superseded on 2026-08-10. Reason: records the 2026-08-03/05 artifact; both its checksum and its file count are superseded

---

# WordPress.org Submission Package — WP Command Center 1.0.0

**Prepared:** 2026-08-03 · **Re-certified:** 2026-08-05 · **Status:** ready to upload · **Submitted by:** the owner (not automated)

---

## 1. Final artifact

| | |
|---|---|
| **Filename** | `ai-command-center-1.0.0.zip` |
| **Path** | `build/ai-command-center-1.0.0.zip` |
| **SHA256** | `db1db34be5a5320c8cde5cfc326b4ee173c5956ed55d08ea65ac1243e19800ed` |
| **MD5** | `a11d23603d0b2c532f2d5d8849b9afbd` |
| **Size** | 1,040,509 bytes (1.0 MB) |
| **Entries** | 327 zip entries = **292 files** + 35 directory entries |
| **Content identity** | `000599e096e028d1e96263ca98a762c03916b4d39b2fc98c02e9d0464337c3ae` |
| **Built from commit** | `ad308566aefaafe70ae30bd9755d975db09d7e06` — working tree clean |
| **Built** | 2026-08-05 |

Verify immediately before uploading:

```bash
# 1. The tree must be clean. A dirty tree means the artifact is not any commit.
git status --porcelain          # must print nothing

# 2. The CONTENT IDENTITY is the stable identifier — it does not change between
#    builds, and it is what proves the shipped files are the certified ones.
unzip -q build/ai-command-center-1.0.0.zip -d /tmp/verify
( cd /tmp/verify && find . -type f -print0 | sort -z | xargs -0 shasum -a 256 ) | shasum -a 256
# must print 000599e096e028d1e96263ca98a762c03916b4d39b2fc98c02e9d0464337c3ae

# 3. The archive checksum identifies the exact FILE you are about to upload.
#    It changes on every rebuild (ZIP mtimes/order are not reproducible), so
#    record whatever this prints and upload that same file.
shasum -a 256 build/ai-command-center-1.0.0.zip
```

> **Why the content identity and not just the archive hash?** A checksum recorded in
> a document can never cover the commit that records it, and the archive hash moves
> on every rebuild. The content identity does neither: it is the hash of the sorted
> per-file hashes, so it is identical for any build of the same shipped files, and it
> is what actually distinguishes the certified product from a stale one.

> The archive checksum is **not** reproducible across builds — only the *content identity*
> is. Do not rebuild unless you intend to re-record the checksum. See RELEASE_HANDOFF §2.

**Do not upload any artifact built before 2026-08-05 from commit `ad30856`.**

A previous artifact carried the checksum `06ac6188…` and matched this document exactly —
while being 26 commits stale. It shipped **no token-creation dialog at all**: no required
name, no read-only default, no expiry choice, no long-lived-credential warning, just a
single `generate_full` button. Verifying it as documented confirmed the wrong file,
because a checksum without a commit is not a release identity.

That is why this table now records the commit and the clean-tree assertion beside the
hash. Check all three, or you are not checking anything.

---

## 2. Repository

| | |
|---|---|
| **Branch** | `release/v1-finalization` |
| **Branch head** | `7ae4a32def1adb702881a8d9f0aa377b32a1f427` (`7ae4a32`) |
| **Remote** | **23 commits unpushed** — the release-candidate finalization work is local. Push before submitting so the tag and the artifact agree. |
| **`main`** | `13549c2` — untouched, **not merged** (merge after approval) |
| **Ahead of `main`** | 87 commits |
| **Working tree** | clean |

Release commits:

```
10145b4  test(rollback): assert the slashed persist/mark_applied form
107d5ba  docs(release): record the independent staging certification and correct the rebuild claim
b6c46ec  fix(governance,acf): unblock content reads in Strict mode; make acf_inventory tell the truth
6c49bed  fix(telemetry): stop casting an array result to string on every completed operation
5973e64  fix(rollback): slash rollback snapshots so undo stops corrupting the data it protects
```

---

## 3. Plugin Check — measured against the artifact, not the checkout

```
ERRORS    : 0
WARNINGS  : 814
TRADEMARK : 2   (both the display name "WP Command Center")
```

Identical to the documented baseline — **no release regression**.

Dominant warning families, all accounted for in `docs/WORDPRESS-ORG-COMPLIANCE-REPORT.md`:

| Count | Family |
|---|---|
| 196 | direct database call (the plugin's own 16 `wpcc_` tables) |
| 174 | direct database call without caching (same tables) |
| 27 | form data without nonce verification (token-authenticated REST, not form posts) |
| ~233 | template-local variables in view files |

The 2 trademark findings are **warnings, not errors**, and concern the display name only.
WordPress has stated the WP trademark does not cover the abbreviation "WP".

---

## 4. Release verification

| Check | Result |
|---|---|
| Full T2 regression | **6,361 passed · 0 failed · net-new 0** (run 3 of 3) |
| Regression baseline | 0 entries (unchanged — comments only) |
| Plugin Check (artifact) | **0 errors** · 832 warnings · 2 trademark warnings (display name only) |
| ZIP contains only distributables | verified — see §5 |
| Clean-install lifecycle | 6/6 on two sites |
| Independent staging certification | passed on WP 6.9.5 and WP 7.0.2 |

### Release invariants — verified live from the shipped artifact

| Invariant | Expected | Measured |
|---|---|---|
| MCP tools | 42 | **42** |
| Catalogue entries | 42 | **42** (42 unique ids) |
| `OPERATION_MAP` | 34 | **34** |
| `ALL_CAPABILITIES` | 23 | **23** |
| `Schema::DB_VERSION` | 2.6.0 | **2.6.0** |
| Default protection mode | `client` | **`client`** |
| Protection modes | 3 | **3** |
| Schema tables | 16 | **16** |
| MCP protocol | 2024-11-05 | **2024-11-05** |
| Discovery resources | 7 | **7** |
| REST namespace | `wp-command-center/v1` | unchanged |
| Text domain | `ai-command-center` | unchanged |

Internally coherent: 42 catalogue = 34 mapped + 8 deliberately unmapped
(`system_info`, `content_seed`, `acf_seed`, `cf7_seed`, `woo_product_seed`, `term_manage`,
`cache_manage`, `report_manage` — read-only/low-risk, unrestricted by design).

### T2 was run three times on this identical commit — full disclosure

```
run 1   6361 passed   0 failed   net-new 0   2905s
run 2   6360 passed   1 failed   net-new 1   2917s   <- test-proposal-rest.sh
run 3   6361 passed   0 failed   net-new 0   3127s
```

Run 2's single failure is a **flaky test, not a product regression**:

- The plugin code is byte-identical across all three runs (content identity `dffb1413…`).
- `test-proposal-rest.sh` passed **4 out of 4** isolated runs (24/0 each).
- The suite pipes its assertion battery through `wp eval-file … 2>/dev/null`, discarding
  stderr — any transient hiccup silently truncates its parsed output.
- It also mutates the global `wpcc_security_mode` option, as 13 other suites do. This is
  the contention hazard RELEASE_HANDOFF §4.4 already documents.

**Nothing was added to `tests/regression-baseline.tsv`** — it still has 0 entries, per §4.4.

This was deliberately **not** "fixed" at the release gate: the failure is unreproducible, so
a change could not be verified to fix it, and editing test code at the final gate on an
unverifiable hypothesis adds risk. Logged for V1.1 — stop discarding stderr in that suite,
and add setup/teardown guards to the suites that mutate the global protection mode.

---

## 5. Package contents audit

- Single top-level directory: `ai-command-center/`
- 284 files: 273 `.php`, 4 `.js`, 4 `.css`, `readme.txt`, `sdk/javascript/wpcc-mcp-relay.mjs`, `LICENSE`
- **Absent:** `tests/`, `docs/`, `artifacts/`, `examples/`, `scripts/`, `.git*`,
  `node_modules`, `wpcc-env.sh`, `.DS_Store`, `composer.*`, `openapi.json`, `*.md`
- Built from an **allowlist**, so a new repo directory is excluded by default

---

## 6. Submission checklist

**Before upload**
- [ ] `shasum -a 256 build/ai-command-center-1.0.0.zip` → `4449222140b4…`
- [ ] Confirm the file is **not** `022e994a…`
- [ ] Confirm branch pushed and `main` not merged:
      `git status -sb` shows no divergence, and `git rev-parse main` is still `13549c2`

**Upload**
- [ ] Go to <https://wordpress.org/plugins/developers/add/>
- [ ] Upload `ai-command-center-1.0.0.zip`
- [ ] Provide a short description of what the plugin does
- [ ] **Watch for the automated email immediately** — it is time-critical

**Immediately after the automated email (highest-risk step)**
- [ ] Reply requesting slug `ai-command-center` (draft in §7 of this document)
- [ ] Get the slug confirmed **in writing before approval** — it cannot change after

**After approval**
- [ ] Record the approval date
- [ ] Note the SVN URL
- [ ] Commit to SVN `/tags/1.0.0/`; make `/trunk/` match
- [ ] Confirm `readme.txt` renders on the public page
- [ ] Confirm the page shows **WP Command Center** at `wordpress.org/plugins/ai-command-center/`
- [ ] Upload screenshots + banner to SVN `/assets/`
- [ ] Tag the head of the release branch (never a hardcoded hash — docs commits move it):
      `git tag v1.0.0 release/v1-finalization && git push origin v1.0.0`
- [ ] Merge `release/v1-finalization` → `main` (triggers production deploy)
- [ ] Verify production: 42 tools, invariants, health check
- [ ] Confirm generated client configs reference `plugins/ai-command-center/` for the relay

**Owner actions still outstanding**
- [ ] **Rotate the staging SSH password** (exposed in an earlier session transcript)
- [ ] Review the staging token `AI Full Access 2026-08-02 02:18` (full scope, active)
- [ ] Screenshots for the directory listing (not required for approval)

---

## 7. Reply to the automated slug email

See `docs/SLUG-REPLY.md` — paste-ready.
