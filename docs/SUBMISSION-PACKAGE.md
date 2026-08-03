# WordPress.org Submission Package — WP Command Center 1.0.0

**Prepared:** 2026-08-03 · **Status:** ready to upload · **Submitted by:** the owner (not automated)

---

## 1. Final artifact

| | |
|---|---|
| **Filename** | `ai-command-center-1.0.0.zip` |
| **Path** | `build/ai-command-center-1.0.0.zip` |
| **SHA256** | `4449222140b441c2c5d2374fdeba9cc69fac04c451eb130d3c2dd6cf115e268d` |
| **MD5** | `a1a268dbec2abeb12ae95e58ba4ac0d2` |
| **Size** | 964,440 bytes (944 KB) |
| **Entries** | 318 (284 files) |
| **Content identity** | `dffb14139e2768c4b40cf6b50c43ad59724b0ffcd5654b2d2fa70d8e512bbab9` |

Verify immediately before uploading:

```bash
shasum -a 256 build/ai-command-center-1.0.0.zip
# must print 4449222140b441c2c5d2374fdeba9cc69fac04c451eb130d3c2dd6cf115e268d
```

> The archive checksum is **not** reproducible across builds — only the *content identity*
> is. Do not rebuild unless you intend to re-record the checksum. See RELEASE_HANDOFF §2.

**Do not upload `022e994a3428d5…`** — the pre-certification artifact. It shipped the
rollback-corruption blocker.

---

## 2. Repository

| | |
|---|---|
| **Branch** | `release/v1-finalization` |
| **Branch head** | `06c3c8937d82d9d8f2231437766ac7594f472794` (`06c3c89`) — docs-only commits after `b6c46ec` do not change the package; content identity `dffb1413…` is the stable anchor |
| **Remote** | pushed; `origin/release/v1-finalization` in sync |
| **`main`** | `13549c2` — untouched, **not merged** (merge after approval) |
| **Ahead of `main`** | 60 commits |
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
| Plugin Check (artifact) | 0 errors · 814 warnings |
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
- [ ] Confirm branch pushed (`10145b4`), `main` not merged

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
- [ ] `git tag v1.0.0 10145b4 && git push origin v1.0.0`
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
