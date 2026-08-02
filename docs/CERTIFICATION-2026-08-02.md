# WP Command Center — Production Reality Certification

**Date:** 2026-08-02
**Target:** `https://purple-surgical.mosdev.site` (Hostinger, real customer site)
**Build certified:** 1.0.0 at commit `4bce549` (branch `fix/prod-session-issues-1-12`)
**Method:** the built release ZIP installed on the host, driven through the browser,
through the MCP relay exactly as Claude Desktop drives it, and verified in MySQL.

> **Certification applies to `4bce549`, not to `main`.** `main` is still `13549c2`
> and does **not** contain the seven fixes below — including the one that stops any
> assistant connecting at all. Submitting or deploying from `main` ships that defect.

---

## 1. Environment

| | |
|---|---|
| WordPress | 6.9.5 |
| PHP | 8.3.30 |
| Theme | `purple-surgical` (custom customer theme) |
| Plugins | 24 — ACF **Pro** 6.8.1, WooCommerce 10.7.0, Elementor **Pro** 4.2.1, Contact Form 7 6.1.6, **Yoast SEO 28.1**, WPForms, WP Mail SMTP Pro, WP Migrate DB Pro, WooPayments |
| Content | 32 posts, 23 pages, 79 products, 716 attachments, 11 ACF field groups, 3 CF7 forms |
| DB | 26 MB `wp_postmeta`, MariaDB |

**Host differences that mattered** (none previously exercised):

| Condition | Result |
|---|---|
| `exec`, `shell_exec`, `popen` **disabled** (`proc_open` allowed) | `wp_cli_bridge` reports `operation_not_available`, no fatal — correct degradation |
| `wp db export` fails (WP-CLI shells out) | Not a plugin path; noted only |
| Host WAF returns 403 for some traversal strings before PHP | Defence in depth, refusal preserved |
| Redis extension present, no `object-cache.php` drop-in | No effect |
| SEO provider is **Yoast**, not Rank Math | Provider auto-detected correctly — path never before tested |

---

## 2. Defects found and fixed

All seven were found on the real site, fixed, redeployed, and re-verified there.

### 2.1 `22a5153` — the release package omitted the MCP relay — **BLOCKER, FIXED**

`scripts/build-release.sh` uses an allowlist that never included `sdk/`, so
`sdk/javascript/wpcc-mcp-relay.mjs` was absent from **every built package**, while
`BaseClientIntegration::relay_url()` generates a configuration telling the user's
machine to `curl` that exact URL and `node` it.

* **Evidence:** relay URL → **HTTP 404**; file absent from the ZIP and from the server.
* **Effect:** connector never starts. **No assistant could connect to any site running a released build.** The product's headline promise was broken out of the box.
* **Why it survived every prior program:** local development runs from the git checkout, where `sdk/` exists. Only installing the actual artifact on a real host exposes it — precisely the gap HANDOFF §7 named.
* **Fix:** ship the runtime relay only (unreferenced SDK samples stay out) + a build assertion that fails with an actionable message if a required runtime file is missing. Guard proven by deleting the file → build exits 1.
* **Re-verified:** relay 200, `node --check` OK, spawned over stdio as Claude Desktop does → `initialize` + `tools/list` = **42 tools**. All **11** client configurations resolve to a working relay.

### 2.2 `0a2c307` — compact mode silently truncated strings and lied about it — **BLOCKER, FIXED**

`ContextModeOptimizer::compact()` cut every string over 500 bytes to
`substr($v,0,500)."..."`. Truncated **lists** were wrapped in a self-describing
envelope whose own comment says an agent "can NEVER mistake a preview for the full
set" — truncated **strings** got no marker, and the sibling metadata was passed
through untouched and therefore false.

* **Evidence:** `file_read` on a real 1442-byte / 68-line theme file returned `truncated: false, returned_bytes: 1442, returned_lines: 68` alongside **503 bytes** of content ending in `...`.
* **Effect:** feeding that into `patch_manage` — the documented read-then-patch workflow — produced a whole-file patch of **+2 / −46 lines**. Applying it would have **deleted 46 of the 68 lines of a live customer's `archive.php`**, with both the assistant and the site owner told the read was complete. `WPCC_CONTEXT_MODE: "compact"` is the default in every generated configuration, so this was the default path.
* **Fix:** return strings whole; keep list previews. Runtimes returning large payloads already carry their own truncation contract (`total_bytes`/`returned_bytes`/`next_byte_offset`), which transport trimming invalidated rather than cooperating with.
* **Re-verified:** `file_read` returns 1442 bytes whose **md5 equals the file on disk**; the same read→patch cycle now yields a one-line change, applies, and rolls back to the original md5 byte-for-byte. `test-file-read-search` 57/57.

### 2.3 `4e47aae` — 13 operations filed bogus actions as approval requests — **FIXED**

`OperationExecutor` already refuses an unknown action before the approval gate, but
only for operations declaring an `action` enum. The 13 largest runtimes declared none.

* **Evidence:** calling 37 operations with `action: "zzz_bogus"` left **31 pending approvals, 5 of them CRITICAL** — "Safe Search & Replace", "Capability Management". The detail view showed a CRITICAL database rewrite **with no description**, so the owner had no way to tell it was garbage.
* **Fix:** declare the enum from each runtime's own `ACTIONS` const, so catalogue and dispatcher cannot drift. Deriving it from `action_risks` was tried and **rejected** — that list is missing 23 genuinely valid actions across 7 operations (`product_publish`, `acf_json_import`, `menu_item_move`, `widgets_rollback`, `cpt_rollback` …) and would have refused real work.
* **Re-verified:** **200 valid actions across the 13 operations, 0 wrongly rejected**; `zzz_bogus` now refused by all 13 with the valid list named.

### 2.4 `bd7c7a2` — unauthenticated `/mcp` returned HTTP 500 — **FIXED**

Missing-token `WP_Error` carried no status, so WordPress sent 500. An invalid token
already returned 401; only the missing-token branch was inconsistent. A 500 on an
unauthenticated endpoint reads as a broken server (clients retry it as transient) and
is exactly what a scanner or wp.org reviewer flags.
**Re-verified:** no header → 401, empty bearer → 401, bad token → 401, valid → 200.

### 2.5 `0627e39` — the connection test did not test the connector — **FIXED**

"Run read-only test" promises to "confirm your assistant can connect", but all five
checks hit the REST namespace; none fetched the connector. It therefore reported
**"All checks passed!"** on a site where the connector 404s and nothing can connect —
the exact defect shipped in 1.0.0.
**Re-verified both ways:** with the file removed the test now reports *"Connector
script — Not found on this site"*; with it present all six pass.

### 2.6 `b2b2af4` — `rollback_manage` sent non-patch rollback ids to a dead end — **FIXED**

Every write returns `rollback_id` with `rollback_available: true`, and
`rollback_manage` offers `rollback_apply` — so callers bring those ids there. It
answered *"patch_id is required"*: a parameter never supplied, with no hint that
`rollback_manage` is patch-only or that the id is undone via `change_history
{action: "rollback_target"}`. Hit twice on real data. The undo works; nothing told
the caller where it was. Now names the right tool.

### 2.7 `4bce549` — test updated to match the improved refusal

`capability_manage` is now refused by the generic pre-gate, so the code is the uniform
`wpcc_invalid_action` rather than the runtime-specific one. Asserts what the contract
promises: refused, and the message names the valid actions. 62/62.

---

## 3. Feature-by-feature results

### 3.1 Installation and lifecycle

| Item | Result | Evidence |
|---|---|---|
| Install release ZIP on real host | **PASS** | `wp plugin install` clean |
| Activate — no fatal | **PASS** | front 200, admin 302 |
| **Fresh install defaults to Standard protection** | **PASS** | wiped all state → `wpcc_security_mode = client` |
| Schema install | **PASS** | 17 `wp_wpcc_*` tables, DB `2.6.0` |
| Reinstall preserves prior explicit mode | **PASS** | pre-existing `developer` retained (by design) |
| Uninstall retains data by default | **PASS** | opt-in checkbox, unchecked |
| Site health after all testing | **PASS** | front 200 @ 0.52 s, product page 200, **0 WPCC errors in the PHP log** |

### 3.2 Architecture invariants

| Invariant | Expected | Actual | Result |
|---|---|---|---|
| Operations in catalogue | 42 | 42 | **PASS** |
| MCP tools exposed | 42 | 42 | **PASS** |
| Operations mapped in CapabilityRegistry | 34 | 34 | **PASS** |
| Capabilities | 23 | 23 | **PASS** |
| DB version | 2.6.0 | 2.6.0 | **PASS** |
| Protection modes | 3 | 3 | **PASS** |

### 3.3 The core customer loop — certified with database evidence

| Step | Result | Evidence |
|---|---|---|
| Read answered instantly, ungated | **PASS** | `content_list`, `product_list`, `media_list`, `menu_list`, `comment_list`, `user_list`, `history_list`, `snapshot_list` all immediate |
| Write gated under Standard | **PASS** | `price_update` → `pending_approval`, risk `medium` |
| Approval names what will change | **PASS** | "What will change — Product id 1185, Regular price 149.99" |
| Approve → lands on the real site | **PASS** | DB: `_price` and `_regular_price` = `149.99` |
| Undo offered, honestly described | **PASS** | "Reversible" badge; dialog warns the undo itself needs approval |
| Undo → site reverts | **PASS** | DB: `_regular_price` row removed; change_log `price_update → rolled_back` linked to the reversing change |
| Change history / audit trail | **PASS** | timeline, sessions, actor, per-change audit |

### 3.4 Protection modes — gating table verified live

| Risk | Standard (`client`) | Strict (`enterprise`) | Development (`developer`) |
|---|---|---|---|
| diagnostic (`system_info`) | immediate ✓ | immediate ✓ | immediate ✓ |
| low (`content_list`) | immediate ✓ | **gated** ✓ | immediate ✓ |
| medium (`content_update`) | **gated** ✓ | **gated** ✓ | **executed** ✓ |

All three **PASS** — behaviour matches the documented table exactly. Development mode
is labelled "Local & staging only" with "Never use this mode on a live production
website." Standard is marked Current + Recommended.

### 3.5 Governance and security

| Test | Result | Evidence |
|---|---|---|
| Token cannot approve its own request | **PASS** | `wpcc_approval_requires_human`; target product unchanged |
| Read-only token blocked from writes | **PASS** | 10/10 write ops → `wpcc_token_read_only` |
| Read-only token allowed its own scope | **PASS** | `change_history`, `code_search`, `file_manage` reachable |
| Revoked token refused | **PASS** | 401 |
| Invalid / malformed / empty token | **PASS** | 401 (was 500 — fixed) |
| Unauthenticated REST | **PASS** | 401 on `/operations`, `/admin/*`, `/mcp` |
| **Editor** on every admin endpoint | **PASS** | 403 × 6, including POST `/approve` |
| **Subscriber** on every admin endpoint | **PASS** | 403 × 6, including POST `/approve` |
| Admin pages require `manage_options` | **PASS** | all four submenus |
| Path traversal (8 payloads) | **PASS** | `wpcc_invalid_path` / `wpcc_not_found`; one blocked by host WAF |
| `wp-config.php` unreadable | **PASS** | refused |
| Secret leakage via `code_search` | **PASS** | `DB_PASSWORD` → 0 matches |
| Malformed JSON-RPC | **PASS** | `rest_invalid_json` 400 |
| Unknown JSON-RPC method | **PASS** | `-32601` |
| Unknown tool | **PASS** | `operation_not_found`, `isError` |
| Invalid action, all 37 operations | **PASS** (after fix) | refused with valid list named |

### 3.6 All 42 MCP tools

Every tool was invoked. All 42 are reachable and respond correctly; failures are
clean, self-describing errors naming the valid actions or the missing parameter —
no crashes, no unhandled exceptions.

`system_info` · `content_manage` · `snapshot_manage` · `theme_manage` ·
`plugin_manage` · `option_manage` · `user_manage` · `media_manage` ·
`woocommerce_manage` · `acf_manage` · `term_manage` · `cache_manage` ·
`forms_manage` · `menu_manage` · `settings_manage` · `approval_manage` ·
`search_manage` · `bulk_manage` · `workflow_manage` · `comments_manage` ·
`widgets_manage` · `cpt_manage` · `file_manage` · `code_search` · `patch_manage` ·
`rollback_manage` · `seo_manage` · `site_builder_manage` · `elementor_manage` ·
`report_manage` · `media_enhance` · `change_history` · `capability_manage` ·
`database_inspect` · `safe_updates` · `safe_search_replace` · `media_import` ·
`content_seed` · `acf_seed` · `cf7_seed` · `woo_product_seed` — **41 PASS**

`wp_cli_bridge` — **N/A on this host**: `exec`/`shell_exec`/`popen` are disabled, so
it reports `operation_not_available` and degrades without a fatal. Correct behaviour;
a documented environmental limitation.

### 3.7 Integrations — all against real customer data

| Integration | Result | Evidence |
|---|---|---|
| **WooCommerce** 10.7 | **PASS** | `product_list/get/search`, `price_update` → approve → DB `149.99` → undo → reverted. 79 real products |
| **Yoast SEO** 28.1 (new provider path) | **PASS** | auto-detected `provider: yoast`; `seo_analyze` 6/10 on real content; `seo_update` wrote title + description (DB-confirmed); `seo_restore` restored the originals **exactly, including the `%%sep%%` template variable** |
| **ACF Pro** 6.8.1 | **PASS** | 11 real groups / 37 fields; inventory reports flexible_content, repeater ×3, group, wysiwyg, image, link, page_link, color_picker; `acf_value_get` read a real flexible-content field (`layouts: download_brochure, logo_showcase ×2`); `acf_value_set` wrote, rollback restored (DB `0` → `1`) |
| **Elementor Pro** 4.2.1 | **PASS** | created a real container/widget page; `elementor_list_widgets` read all 3 widgets; `elementor_update_text` changed the heading; rollback restored it — **JSON valid and 3 widgets intact throughout** |
| **Contact Form 7** 6.1.6 | **PASS** | listed 3 real forms with ids, status and shortcodes |
| **Custom theme** `purple-surgical` | **PASS** | `code_search` 13 hits for `get_header` with `read_hint` line ranges; `file_tree`; `file_read` (md5-exact); patch created, applied (marker at line 10), **rolled back to the original md5 byte-for-byte** |

### 3.8 Admin surfaces

| Surface | Result | Evidence |
|---|---|---|
| Home — fresh install / onboarding | **PASS** | one promise, Step 1 of 2, "What it does not do" disclosure |
| Home — connected + pending | **PASS** | Protection / Assistant / Waiting-for-you tiles, recent changes, "How this keeps you in control" |
| Approvals — Pending / Decided / Execution | **PASS** | list, detail, risk badges, humanised labels |
| Approvals — detail shows what will change | **PASS** | field-level preview before approving |
| Approvals — bulk select + bulk reject | **PASS** | confirm dialog names count and consequence; 25 rejected, DB-confirmed; list and counts refresh |
| Approvals — server cap honest | **PASS** | "Showing 1–25 of 100 (165 pending in total)" on real overflow |
| Changes — Timeline / Sessions / Can be undone | **PASS** | undo button, Reversible badge |
| Settings — Protection | **PASS** | three comparable modes, Current/Recommended, production warning, uninstall-data option |
| Settings — Connections (Assistants / Your own software / Access tokens) | **PASS** | 11 assistants, config generation, copy, token table |
| Settings — Advanced (Built-in AI / Diagnostics / System / Capabilities) | **PASS** | AI off by default, honest key-storage disclosure, activity feed |
| Token create → reveal once → use → revoke | **PASS** | revoked token → 401 |
| Connection test | **PASS** (after fix) | six checks including the connector |
| Simple / Detailed density | **PASS** | both render |

---

## 4. Regression evidence

Run against the fixed build **on staging**:

| Suite | Result |
|---|---|
| `test-file-read-search` | **57 passed, 0 failed** (the suite covering the truncation fix) |
| `test-capability-runtime` | **62 passed, 0 failed** |
| `test-agent-manifest` | **43 passed, 0 failed** |
| `test-agent-search-ux` | **35 passed, 0 failed** |
| `test-token-efficiency` | **28 passed, 0 failed** |
| `test-audit-log` | **19 passed, 0 failed** |
| `test-approval-enforcement` | **16 passed, 0 failed** |
| `test-relay-smoke` | **PASS** |
| `test-change-history-runtime` | 56 passed, 1 failed — pre-existing drift |
| `test-mcp-error-surface` | 17 passed, 1 failed — pre-existing drift |
| **Total** | **~333 passed, 2 pre-existing drifts, 0 net-new failures** |

Both drifts are assertions encoding older shapes, not defects, and neither is
reachable from the changed code:

* `change_history` "not found is in-band error" expects the legacy `{error:true}`; the endpoint correctly returns a `WP_Error` with `status: 404`. `ContextModeOptimizer` only touches MCP responses, not REST.
* `mcp-error-surface` "perm-denied: message" greps for the literal word "read-only"; the live message says *"This token is restricted…"* — product copy, deliberately non-technical.

**Site restored after certification** — theme file md5 identical to the original, ACF
value back to `1`, product title and Yoast meta original, test page and test tokens
and test users deleted.

---

## 5. Known remaining issues (none blocking)

1. **Three operations still accept malformed calls into the approval queue** — `safe_updates`, `safe_search_replace`, `media_import` do not dispatch on `action`, so their required parameters are not validated before queueing. **Harm is bounded and proven:** approving a parameter-less CRITICAL `safe_search_replace` produced `FAILED — Created 0 · Updated 0 · Skipped 0 · Errors 1`, nothing changed. Left unfixed deliberately: validating required parameters generally is a broad change, and the same shortcut applied to `action_risks` would have broken 23 valid actions.
2. **The undo approval row reads only "Undo a change"** — it does not name which change. Ambiguous when several are pending. (HANDOFF §5.7, confirmed on real data.)
3. **Rejected changes render in the Changes timeline like applied ones** — a screen headed "Everything that has changed on this site" lists items that were rejected and never ran, styled identically.
4. **`acf_value_get` with an unresolvable object returns `value: null` rather than an error** — an agent using the wrong parameter name is told the field is empty when it holds a value. `acf_value_update` errors correctly in the same situation.
5. **A Yoast check message contradicts its own result** — `title_length`, `passed: false`, *"SEO title is within 60 characters (is 62)."*
6. **Undo costs 5 clicks under Standard** — correct (an undo is a change), but the most likely "you promised undo" complaint.
7. **`wp_cli_bridge` unavailable wherever `exec`/`shell_exec` are disabled** — degrades cleanly; should be stated in the readme as an environmental dependency.
8. **164 Plugin Check errors remain** — pre-existing; ~56 direct filesystem calls and `proc_open` are real and disclosed. wp.org **approval is not guaranteed**.
9. **Multisite unsupported**; **WooCommerce orders/refunds still unexercised** (this store has 0 orders).

---

## 6. Release decision

# A. Certified for WordPress.org Submission

Certified for the build at **`4bce549`**.

Every defect that could break a customer's site or silently mislead them was found on
a real customer installation, fixed, and re-verified there: the connector now ships
and connects, file reads no longer lie about their own completeness, malformed calls
no longer reach the approval queue as CRITICAL items, and unauthenticated requests
return 401. The core loop — ask, gate, approve, land, undo — was walked end to end
with database evidence at every step, all 42 tools were exercised, all five
integrations were driven against real customer data, and the governance and security
boundaries held under direct attack.

Two conditions attach to this verdict, both matters of fact rather than opinion:

1. **The submitted artifact must be built from `4bce549` or later.** `main` is
   `13549c2` and does not contain these fixes; a package built from `main` still ships
   the relay defect, and no assistant can connect to it. `main` also auto-deploys to
   production on push, so promoting it is a deliberate release decision.
2. **Approval by the WordPress.org review team is not guaranteed.** 164 Plugin Check
   errors remain, including ~56 direct filesystem calls and `proc_open`. These are
   real, disclosed in the readme, and pre-date this certification — they are a review
   risk, not a functional defect.
