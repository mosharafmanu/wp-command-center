# V1 Finalization — Report

**Branch:** `release/v1-finalization` (from certified `1e71d5e`, which contains `3b6fe49`)
**Final commit:** `984aa3c`
**Checkpoint tag:** `checkpoint/pre-v1-finalization`
**Artifact:** `build/wp-command-center-1.0.0.zip` — 279 files, 916 KB
**sha256:** `51f1d4b4a3d4ac54a8bfc5627535061c704c03d0320e146539d6541ee570d258`
**`main` untouched** at `13549c2`. Nothing pushed to it, nothing deployed, nothing submitted.

---

## 1. Program status by phase — read this first

This program defined its own completion bar: every listed phase belongs to V1 and must
be **completed** or **proven unsafe/unnecessary with evidence**. That bar is **not met**.
Six phases are complete, one is partially complete with an evidence-based decision, and
five were not reached.

| Phase | Scope | Status |
|---|---|---|
| 0 | Baseline, branch, checkpoint, artifact, invariants | **COMPLETE** |
| 1 | ACF local JSON complete/atomic/honest | **NOT DONE** |
| 2 | Pre-approval parameter validation | **COMPLETE** (bounded — §3.2) |
| 3 | Unified rollback routing contract | **NOT DONE** |
| 4 | Undo approval clarity | **COMPLETE** |
| 5 | Parameter vocabulary + aliases | **NOT DONE** |
| 6 | Describe/discovery coverage | **PARTIAL** — boundary notes only (§3.5) |
| 7 | ACF value read honesty | **COMPLETE** |
| 8 | ACF field count accuracy | **COMPLETE** |
| 9 | SEO message correctness | **COMPLETE** |
| 10 | Undo expectation / click cost | **NOT DONE** |
| 11 | File capability boundary | **COMPLETE** |
| 12 | WP-CLI environmental dependency | **COMPLETE** |
| 13 | Multisite policy | **COMPLETE** (policy A, enforced) |
| 14 | Plugin Check resolution | **PARTIAL** — 164 → 124, all classified (§4) |
| 15 | Release artifact certification | **PARTIAL** — contents + relay verified, lifecycle not |
| 16 | Full staging re-certification | **PARTIAL** — invariants + restore verified |

I did not run out of things to do; I ran out of capacity to do them at the standard the
rest of this work was held to. Reporting the remainder as done would be the one failure
mode this product exists to prevent.

---

## 2. A correction to the two previous certification reports

Both earlier reports state that regression suites were run "against staging". **They were
not.** `tests/*.sh` line 2 sources `wpcc-env.sh`, which unconditionally exported the local
base URL and token, silently overriding anything the caller set. Every "N assertions
passed against staging" figure in `CERTIFICATION-2026-08-02.md` and
`FINAL-CERTIFICATION-2026-08-02.md` was measured against the **local dev checkout**.

The assertion counts were real and they did exercise the changed code — the local checkout
*is* the working tree — but the target was wrong and the reports said otherwise.

Two further facts came out of this:

* Several suites are **inherently local-only**. `test-file-read-search.sh` builds its own
  fixture on the local filesystem (`awk` → `wp-content/plugins/wpcc-read-sandbox/big.css`)
  and then asks the API to find it. Pointed at staging it reports 43 failures that mean
  nothing. The honest methodology is: **suites → local dev site; staging → purpose-built
  probes, browser, database and filesystem evidence.**
* `wpcc-env.sh` was malformed — the staging credentials had been appended as raw prose, so
  `source wpcc-env.sh` executed them and invoked `ssh`. Repaired locally (comments, plus
  `${VAR:-default}` so a caller can target staging). The file is git-ignored and ships
  nowhere; no secret is in this report or in any commit.

---

## 3. What was completed, with evidence

### 3.1 Phase 7/8/9 — honesty in ACF and SEO (`78a89aa`)

* **`acf_value_get`** answered `value: null` whenever it could not read a field —
  indistinguishable from a field that is genuinely empty. It now separates: no object
  context (naming `object_type`/`object_id`), no such field registered, object not found,
  and field-exists-but-empty carrying `field_exists`/`field_type`/`value_state`.
  Verified: four probes, four distinct honest answers.
* **`acf_field_list`** reported `acf_get_fields()`'s raw count, so a repeater with two
  sub-fields read as three fields. Now reports `top_level_fields` / `nested_sub_fields` /
  `total_nodes`. Verified: 1 text + 1 repeater(2) → `total 2, nested 2, nodes 4`.
* **Found while fixing it:** both read paths called `acf_get_fields($group_key)`, which on
  a site with ACF local JSON resolves the JSON copy (`ID 0`) and returns **nothing** — the
  same defect fixed in `field_create` in `3b6fe49`, still live on the read side. A group
  with 2 fields reported `total 0` before this change. Fixed by a shared
  `group_with_id()` / `group_fields()` resolver.
* **SEO** — every `seo_analyze` check carried one sentence written as the *passing*
  statement and printed it whatever the verdict, so **all ten** contradicted themselves on
  failure. `check()` now takes both outcomes and carries the measured value and threshold.
  Verified against real Yoast data: 10 checks, 4 failing, **0 contradictory messages**.

### 3.2 Phase 2 — malformed requests no longer spend an approval (`984aa3c`)

Six operations do not dispatch on `action` — `content_seed`, `acf_seed`,
`woo_product_seed`, `safe_search_replace`, `media_import`, `safe_updates` — and a call
carrying none of its required parameters reached the approval queue at the operation's
worst-case risk.

Deliberately scoped to operations **without** a required `action`. Where an operation
dispatches on action, its other "required" parameters are required for only *some*
actions — `option_manage` declares `option_id`, but `option_rollback` takes `rollback_id`
instead — so a blanket check would refuse valid work. That is the mistake this codebase
already made once by deriving actions from `action_risks`, and it is not repeated.

Verified on staging: all six refused with `wpcc_missing_parameters` naming what is missing
and what is required; **pending approvals unchanged at 0** across the whole probe; valid
calls to the same operations still gate normally; and a **33-operation read sweep produced
0 false rejections**.

### 3.3 Phase 4 — an undo names the change it reverses (`7ffb2b5`)

An undo carries only `change_id`, which is neither a name nor an object id, so the
approval row read a bare "Undo a change". `describe()` now resolves the original change
(one indexed read-only lookup) and appends its description. `target_summary` stores an
*already rendered* label, so the title comes from the humanizer and the label is used
verbatim — running it back through `target()` printed `““like this””`.

Because it lives in the shared humanizer, the pending list, approval detail, bulk
selection, Changes and the audit timeline all get it at once. Verified with two different
pending undos:

```
Undo a change — Update price — #1185
Undo a change — Edit a post or page — “PH4 Undo Naming Probe”
```

### 3.4 Phase 11/12/13 — boundaries enforced and made visible (`ad07075`)

* **Multisite (policy A).** The readme recommended per-site activation; nothing enforced
  it. Network activation created one site's tables and options and left every other site
  without them, showing the Command Center where it could not work — a partial install
  presented as a whole one. Activation now **refuses** network-wide activation via
  `wp_die` (WordPress's own decline mechanism), and a network already in that state gets
  an admin notice, since the activation hook cannot reach it retroactively.
* **File boundary and WP-CLI dependency.** Both were true and documented, and invisible at
  the point of use.

The part that mattered: `tools/list` **drops the operation description in compact mode**,
and compact is the **default in every client configuration this plugin generates** — so an
agent never read any of it and could only discover a boundary by hitting it. Operations
may now declare `agent_note`, which survives compaction:

```
patch_manage : Patch Engine — Edits existing files only, it cannot create or delete a file.
file_manage  : File Access — Read-only, use patch_manage to change an existing file.
wp_cli_bridge: WP-CLI Bridge — Needs host process execution (proc_open) and a WP-CLI binary…
```

Two readme FAQs added; the multisite FAQ now describes enforcement rather than advice.

### 3.5 Phase 6 — partial

`agent_note` is a real, working discovery mechanism and it is applied to the three
operations whose limits actually bite. The full Phase 6 contract — risk tier, approval
behaviour per protection mode, aliases, examples and rollback path exposed for *every*
complex runtime — was **not** built. `acf_describe` and `woo_describe` remain the only
rich describes.

---

## 4. Plugin Check: 164 → 124, every finding classified

| Category | Baseline | Now | Classification |
|---|---:|---:|---|
| `WordPress.DB.PreparedSQL.NotPrepared` | 45 | 45 | False positive — evidence §4.1 |
| `Security.EscapeOutput.OutputNotEscaped` | 28 | 28 | False positive — evidence §4.2 |
| `AlternativeFunctions.unlink_unlink` | 22 | **0** | **Fixed** → `wp_delete_file()` |
| `PluginCheck.Security.DirectDB.UnescapedDBParameter` | 19 | 19 | False positive — evidence §4.1 |
| `file_system_operations_fclose` | 15 | 15 | Necessary exception — §4.3 |
| `Generic.PHP.ForbiddenFunctions.Found` | 10 | **2** | 8 **fixed**; 2 = `proc_open`, §4.4 |
| `file_system_operations_is_writable` | 7 | **0** | **Fixed** → `wp_is_writable()` |
| `file_system_operations_fopen` | 6 | 6 | Necessary exception — §4.3 |
| `rename_rename` | 3 | 3 | Necessary exception — §4.3 |
| `PreparedSQLPlaceholders.LikeWildcardsInQuery` | 3 | 3 | False positive — evidence §4.1 |
| `WPQueryParams.SuppressFilters` | 2 | **0** | **Fixed** — removed |
| `file_system_operations_rmdir` | 2 | 2 | Necessary exception — §4.3 |
| `wp_function_not_compatible_with_requires_wp` | 1 | **0** | **Fixed** — inlined |
| `file_system_operations_fread` | 1 | 1 | Necessary exception — §4.3 |
| **Total errors** | **164** | **124** | |

### 4.1 SQL (67) — false positive, four patterns verified in source

* **Prepared into a variable, then used.** `HealthVerificationEngine.php:85-87` builds
  `$sql` with `%s` placeholders and applies `$wpdb->prepare( $sql, ...$params )`;
  LIMIT/OFFSET are prepared separately and bounded with `max()/min()` casts. The sniffer
  cannot follow prepare-into-variable.
* **Allowlisted identifier interpolation.** `PatchManager.php:464` — `$field` is validated
  against `['session_id','task_id','plan_id']` on the line above; `$table` is
  `$wpdb->prefix . 'wpcc_patches'`; the value is bound with `%s`. Identifiers cannot be
  placeholders in SQL.
* **Class-constant interpolation.** `TimelineBuilder.php:842` — `{$limit}` is
  `self::BASELINE_LIMIT`.
* **Placeholders assembled by a helper.** `MediaUsageResolver.php:92` —
  `content_match_clauses()` returns `$where` containing **only** `'p.post_content LIKE %s'`
  strings and `$params` carrying the values (`$wpdb->esc_like()` applied to filenames);
  `$wpdb->prepare( $sql, $params )` binds them. The 3 `LikeWildcards` findings are
  **literal** patterns in static SQL (`LIKE 'field\_%'`, `LIKE 'options\_%'`) with correct
  `_` escaping and no variable part.

### 4.2 Output escaping (28) — false positive, verified in source

All are `echo $page_url( … )` and its siblings in admin views. `file-access.php:10`:

```php
$page_url = static function ( array $args = [] ): string {
    return esc_url( add_query_arg( …, admin_url( 'admin.php' ) ) );
};
```

The output **is** escaped; the sniffer cannot follow a closure's return value.

### 4.3 Filesystem (27) — necessary exception, not silently swapped

29 of the 56 were genuinely replaceable and were replaced. The remaining 27 cannot move to
`WP_Filesystem` without weakening a guarantee this release depends on:

* **`fclose()`/`fread()` on `proc_open` pipes** — process streams, not files. There is no
  `WP_Filesystem` equivalent; the sniffer matches on the function name alone.
* **`rename()` in `SnapshotManager` / `AuditLog`** — the temp-write-then-atomic-replace
  that snapshot and audit integrity rest on. `WP_Filesystem::move()` offers no atomicity
  guarantee and is not atomic at all over its FTP/SSH transports.
* **`fopen($file,'c')` + `flock`** in `AuditLog` — append-only logging under concurrency.
  `WP_Filesystem` has no locking primitive.

### 4.4 `proc_open` (2) — necessary exception, already degrades

`PhpBinary.php:200` runs `php -l` to verify a patched file before accepting it;
`WpCliBridge.php:229` runs WP-CLI. Both already degrade honestly where a host forbids
process execution — the certified staging host disables `exec`/`shell_exec`/`popen`, and
`wp_cli_bridge` reports `operation_not_available` with no fatal while everything else
works. Removing the syntax verification would make patching less safe, not more
compliant, so it stays and is disclosed.

---

## 5. Invariants — all preserved

| Invariant | Required | Actual (staging, live) |
|---|---|---|
| MCP tools | 42 | **42** (compact and standard) |
| Catalogue operations | 42 | **42** |
| Mapped operations | 34 | **34** |
| Capabilities | 23 | **23** |
| Protection modes | 3 | **3** |
| DB version | 2.6.0 | **2.6.0** |

Governance, approval protection, capability enforcement, rollback safety, security
defaults and audit integrity were not weakened. Every guard added in this program only
ever **rejects**; none admits anything a runtime would have refused.

---

## 6. Regression and net-new failures

Suites run against their designed target (local dev checkout):

| Suite | Result |
|---|---|
| `test-capability-runtime` | 62 / 0 |
| `test-file-read-search` | 57 / 0 |
| `test-acf-runtime` | 44 / 0 |
| `test-agent-manifest` | 43 / 0 |
| `test-token-efficiency` | 28 / 0 |
| `test-acf-value-context-and-layout-usage` | 29 / 0 |
| `test-audit-log` | 19 / 0 |
| `test-approval-enforcement` | 16 / 0 |
| `test-mcp-error-surface` | 17 / **1** — pre-existing |
| `test-media-runtime` | 70 / **10** — pre-existing |
| `test-snapshot-runtime` | 57 / **1** — pre-existing |

**Net-new attributable failures: 0.** The media and snapshot failures were measured
identically with this program's filesystem changes stashed (70/10 and 57/1 both ways). The
`mcp-error-surface` failure greps for the literal words "read-only" while the live message
says *"This token is restricted…"* — product copy, not a defect, and failing before this
program began.

Four test assertions were updated because they encoded contracts that this program
deliberately changed (unknown ACF field now refused rather than answered `null`; unknown
action refused by the generic pre-gate; REST `WP_Error` shape rather than the legacy
`{error:true}` envelope). Each carries a comment saying why, and the `val: get` assertion
was made fixture-independent so it holds on any site — verified 44/0 on both local and
staging.

---

## 7. Staging: exercised, then restored exactly

| | Baseline | After |
|---|---:|---:|
| Posts / pages / products / attachments | 32 / 23 / 79 / 716 | 32 / 23 / 79 / 716 |
| Users | 5 | 5 |
| ACF field groups (published) | 11 | 11 |
| Orphaned ACF fields | 0 | 0 |
| `acf-json` files | 18 | 18 |
| Custom theme (139 files) | baseline md5s | **byte-identical** |
| Protection mode / active theme | client / purple-surgical | client / purple-surgical |

Two residues found and corrected during restoration: page 8's title (revisions showed the
original was **"Products"**, not the "Home" I first restored) and price meta on product
1185 that had no such rows originally. 14 ACF objects created by suites I had pointed at
staging were removed.

Live at close: front **200**, admin **302**, product page **200**, relay **200**, and the
Claude Desktop handshake through the generated configuration returns
`serverInfo {WP Command Center 1.0.0}` and **42 tools**.

---

## 8. Commits

| Commit | Phase | Subject |
|---|---|---|
| `78a89aa` | 7/8/9 | honest reads, honest field counts, honest analysis messages |
| `80bd2db` | 14 | remove 11 real Plugin Check errors (164 → 153) |
| `9192d51` | 14 | route file deletion and writability through core wrappers (153 → 124) |
| `7ffb2b5` | 4 | an undo now names the change it reverses |
| `ad07075` | 11/12/13 | enforce the multisite policy and make capability limits visible |
| `984aa3c` | 2 | a request that cannot execute no longer spends an approval |

---

## 9. Verdict

# B. NOT READY — OBJECTIVE BLOCKERS REMAIN

This is a verdict on **this program's completion bar**, not a reversal of the product
certification. The plugin is in better shape than the build certified in
`FINAL-CERTIFICATION-2026-08-02.md`; nothing here regressed it.

The objective blockers are that the following V1-scoped work is **not done**, and this
program's own rule is that it must be completed or proven unnecessary — neither has
happened:

1. **Phase 1 — ACF local JSON.** Field changes still do not update the group's `acf-json`,
   and `acf_json_sync` still returns `synced_count: 0`. An agency can still commit a group
   whose fields are missing. A naive auto-sync was attempted in the previous program and
   reverted with evidence (ACF cannot see a field created in the same request, so the file
   was written short) — the deliberate design this phase asked for was not built.
2. **Phase 3 — unified rollback routing contract.** Rollback is still reached four
   different ways. `rollback_manage` now redirects instead of dead-ending, but the single
   authoritative discovery contract was not built.
3. **Phase 5 — parameter vocabulary and aliases.** `content_id` / `object_id` / `media_id`
   / `post_id`, `type` / `update_type`, `alt` / `alt_text` are unchanged. No canonical
   names, no alias normalization, no ambiguity refusal.
4. **Phase 6 — describe/discovery coverage.** Only the three boundary notes were added.
5. **Phase 10 — undo click cost.** Not measured, not reduced.
6. **Phase 14 — 124 findings remain.** Every one is classified and the largest categories
   are verified false positives with named source evidence, but the classification was
   established from representative instances per pattern, not proven line-by-line for all
   124.
7. **Phase 15/16 — lifecycle certification not run.** ZIP contents, the relay handshake,
   invariants and staging restoration are verified; **clean-install on a fresh WordPress,
   deactivate/reactivate, upgrade from a previous build, uninstall retain-vs-purge,
   reinstall and reconnect are not.**

None of these is a newly-introduced defect and none is a security or data-integrity risk.
They are the difference between "certified good" and "this program finished".
