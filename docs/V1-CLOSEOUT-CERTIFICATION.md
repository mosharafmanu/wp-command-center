# WP Command Center 1.0.0 — V1 Closeout Certification

**Date:** 2026-08-03
**Branch:** `release/v1-finalization` (not merged; `main` remains owner-controlled)
**Artifact:** `build/ai-command-center-1.0.0.zip` — 284 files, 944 KB
**Programme:** PROGRAM-V1-CLOSEOUT — zero open items before WordPress.org.

This supersedes `V1-RELEASE-CERTIFICATION.md`. One verdict, at the end.

---

## 1. Every issue fixed

Eleven commits. Six changed the product; five changed tests or documentation. Every test
change was verified against live behaviour **first** — no product behaviour was altered to
satisfy an incorrect test.

### Product defects

| # | Defect | Consequence before the fix |
|---|---|---|
| 1 | **Search & Replace sent slashed strings** | WordPress slashes `$_POST`; the values were cast straight to string and passed on. Searching for `O'Brien` queried for `O\'Brien` and matched nothing; a replacement containing a quote or backslash wrote the escaped form into the customer's content. On a critical-risk operation that rewrites the database. The same file's form *redisplay* already unslashed, so the two paths disagreed about what the operator typed. |
| 2 | **Structured failure detail was flattened away** | A partial undo knows which fields it restored, which it skipped as drifted, and the expected vs current value of each. `OperationExecutor::fail()` reduced it to code + message, and `with_status()` then *replaced* whatever data survived. An assistant had to parse English prose to discover that `title` had been skipped. |
| 3 | **The read-only scope had two names** | The MCP denial said "This token is restricted" and pointed at a "standard access token". Neither exists in the UI — the scopes are rendered "Read-only" and "Full access". An assistant relaying the message named a scope the customer could not find. |
| 4 | **Discovery advertised unavailable operations** | `elementor_manage` and `forms_manage` hardcoded `available: true`, so one response said `available: true` and `requirements.satisfied: false` about the same operation. |
| 5 | **The availability gate said nothing useful** | `operation_not_available` — "Operation is not available in the current environment" named neither the operation nor the missing dependency, while the *ungated* operations were answering with "Elementor is not active." It now says `elementor_manage requires Elementor, which is not active on this site.` |
| 6 | **`table_stats` escaped a value instead of binding it** | `esc_sql()` into a quoted string was adequate; `$wpdb->prepare()` removes the question. |

### Test and infrastructure defects

| # | Defect | Consequence |
|---|---|---|
| 7 | **Suites leaked governance state** | ~20 suites change the protection mode without a trap, so an early exit left the wrong mode for the next suite. Proven: `test-capability-runtime` drops 11 assertions when it inherits `enterprise`. Fixed at the runner — it snapshots and restores around every suite. |
| 8 | **`test-alt-text` deleted the site's API key and never restored it** | It cleared `wpcc_alt_text_api_key` to test the no-key path, silently reconfiguring the site for every suite that ran afterwards. |
| 9 | **Security-redaction fixtures could never match** | The AWS fixture was `AKIA` + **17** characters (a real key ID is `AKIA` + 16) and the Stripe fixture used a `sk_fake_test_` prefix that does not exist. Three assertions had been failing long enough to be *recorded in the baseline as expected* — the worst state for a security test. Redaction was never broken; the test simply proved nothing. |
| 10 | **The regression baseline hid real regressions** | 24 accepted failures, seven weeks stale. A full run reported 85 pre-existing failures as "net-new" and nearly buried the 13 the previous programme had genuinely introduced. |
| 11 | **~100 assertions described a product that no longer existed** | Detailed in §6. |

---

## 2. Every issue intentionally left unresolved

**One**, and it needs a decision rather than a fix.

### The plugin name and slug contain "WP"

```
WARNING trademarked_term
  "WP Command Center" contains the restricted term "wp"
  slug "wp-command-center" contains the restricted term "wp"
```

**Evidence this is real:** WordPress.org restricts "WP" in plugin names and slugs. Plugin
Check reports it as a *warning* rather than an error because a human reviewer makes the
call, and many published plugins contain "WP" — but a submission under this name may be
asked to rename.

**Why it is not fixed here:** changing the slug changes the installation directory, the
update path and the text domain — a breaking change for every existing install — and
choosing a product name is a branding decision with trademark implications. That belongs
to the owner, not to a release process. It is the one case in this programme that meets
the "deliberate product redesign or breaking change" bar for deferral.

**What a rename would touch, if chosen:** the slug, the main plugin file name, the text
domain, `readme.txt`, and the `wp-command-center` references in every generated client
configuration. The `wpcc_` database prefix and the `wp-command-center/v1` REST namespace
would need a migration path.

---

## 3. Regression status

```
T2: 181 suites — 6,361 passed, 0 failed
```

Run **four times undisturbed**, identical every time:

| Run | Result | Duration |
|---|---|---|
| A | 6,361 passed / 0 failed | 3,857s |
| B | 6,361 passed / 0 failed | 3,729s |
| C | 6,361 passed / 0 failed | 3,745s |
| D (final, on the shipped code) | 6,361 passed / 0 failed | 3,768s |

`tests/regression-baseline.tsv` is **empty**, and documents why it must stay empty.
Nothing is accepted; a failing assertion is now either a real regression or a stale
expectation, and both get fixed.

> Two intermediate runs showed 1–2 failures. Both were contaminated by suites I was
> running concurrently against the same database — the very contention the runner
> isolation addresses at the suite level but cannot prevent between two runners. They are
> recorded here rather than omitted; the four clean runs above were run with nothing else
> touching the site.

---

## 4. Plugin Check status

Measured against the **built artifact**, not the checkout:

```
ERRORS:   0
WARNINGS: 815
```

Every one of the 17 rule families is resolved to fixed, proven-false-positive, or
accepted-with-a-reason in
[WORDPRESS-ORG-COMPLIANCE-REPORT.md](WORDPRESS-ORG-COMPLIANCE-REPORT.md). Nothing is
"unknown". Highlights of the evidence:

- **233** `NonPrefixedVariableFound` — 233 of 233 are in `includes/Admin/views/`; template
  locals, not globals.
- **27** nonce findings — private helpers behind a single entry point that checks
  `current_user_can` **and** `check_admin_referer` before dispatch.
- **145** SQL findings across **108 sites** — 97 are prepared or interpolate only a
  code-derived identifier; the remaining 11 were read individually and each traced to a
  class constant, a `$wpdb->prefix`-derived name, or a validated allow-list.
- **4** `NonPrefixedHooknameFound` — LiteSpeed and Cache Enabler hooks, whose names are
  fixed by those plugins. Unfixable by definition.
- **370** direct-query / no-caching — the plugin owns 16 tables, and caching an approval
  queue would show a customer a decision they have already made.

> One correction: mid-audit I believed `db_index_analysis` carried a SQL injection,
> because `esc_sql()` does not escape backticks. It does not.
> `DatabaseRegistry::sanitize_table()` resolves every caller-supplied table to a fixed
> allow-list first — verified directly, `sanitize_table("wp_posts\` WHERE 1=1 -- ")`
> returns `NULL`. The probe that looked like a successful injection was the null-table
> path enumerating all core tables, which is documented behaviour. No vulnerability, and
> the speculative guard I had added was reverted.

---

## 5. Test status

| | |
|---|---|
| Suites | 181 |
| Assertions | 6,361 passed, 0 failed |
| Accepted failures | **0** |
| Stability | 4 consecutive identical undisturbed runs |
| Order-dependence | Fixed at the runner; governance state snapshotted and restored around every suite |

Three suites were **strengthened** while being corrected, not weakened:
`test-security-mode-validation` 27 → 29 assertions (added: a bogus action must be refused,
not queued); `test-acf-runtime` 44 → 45 (asserts the code *and* the message);
`test-seo-runtime-step91` 21 → 30.

New suites: `test-invalid-action-contract`, `test-uninstall-completeness`,
`test-audit-actor-robustness`, `test-admin-input-unslash`.

---

## 6. Documentation status

**Twelve documents written**, every number read from the running plugin rather than
recalled: OVERVIEW, QUICKSTART, INSTALLATION, SECURITY, ARCHITECTURE, MCP, API,
OPERATIONS, CAPABILITIES, AI-INTEGRATIONS, TROUBLESHOOTING, RELEASE.

**Ten removed** because they contradicted the product: `docs/product/CAPABILITIES.md`
described **9** capabilities when there are **23**; `docs/architecture/OPERATIONS.md` was
missing `term_manage`, `cache_manage`, `change_history` and `media_enhance` entirely. Both
were last touched 2026-06-13, before those operations existed. Git retains them.

**Four earlier certification reports** are kept as a record of the programme and now carry
a SUPERSEDED banner pointing at what is current.

### Documentation vs reality

Checked against the running product, not against itself:

- `readme.txt`'s gating claims — "low-risk edits go straight through" on Standard, "every
  single change waits" on Strict, "reading and diagnostics are never gated in any mode" —
  match the measured table exactly.
- **Fixed:** `readme.txt` never mentioned that the generated setup runs the relay with
  **Node.js on the machine running the assistant**. That is a real prerequisite and the
  most common reason a client connects but shows no tools.
- **Fixed:** the read-only denial message (§1, defect 3).
- Tool descriptions, `wpcc://operations`, and the REST catalogue all derive from
  `OperationRegistry`, so they cannot drift from each other.

---

## 7. Release artifact verification

| Check | Result |
|---|---|
| Build | allowlist, not blocklist |
| Relay assertion | build **exits 1** without `sdk/javascript/wpcc-mcp-relay.mjs` |
| Contents | 284 files, 944 KB |
| Leakage | no `tests/`, `.git`, `node_modules`, `wpcc-env.sh`, `.DS_Store`, `*.md`, `docs/` |
| Version consistency | header, `WPCC_VERSION`, `readme.txt` stable tag all `1.0.0` |
| Plugin Check | 0 errors |

The version assertions now derive the expected value from the plugin header, so a bump
cannot make them stale again.

---

## 8. Final certification

Everything below was measured against a **clean WordPress 6.9.5 with only the ZIP
installed**, then destroyed.

### Lifecycle — 24 passed / 0 failed

clean install → activate (defaults to **Standard protection**, 15 schema tables,
`DB_VERSION` 2.6.0, no fatal) → relay shipped **and** reachable → deactivate (site healthy,
data retained) → reactivate → upgrade in place (stays active, schema preserved) →
uninstall (**16 tables retained** by default) → reinstall (adopts existing data) →
uninstall with opt-in purge (**0 tables, 0 options**).

### Invariants, read from the installed artifact

| Invariant | Required | Measured |
|---|---|---|
| MCP tools | 42 | **42** |
| Catalogue operations | 42 | **42** |
| Mapped operations | 34 | **34** |
| Capabilities | 23 | **23** |
| Protection modes | 3 | **3** |
| `DB_VERSION` | 2.6.0 | **2.6.0** |

### MCP

Handshake `WP Command Center 1.0.0`, protocol `2024-11-05`; `tools/list` returns **42**;
all seven discovery resources (`wpcc://manifest`, `context`, `capabilities`, `operations`,
`queue`, `results`, `recommendations`) read successfully.

### Protection modes — all three

| Mode | Medium-risk write | Diagnostic read |
|---|---|---|
| Standard protection | `pending_approval` | immediate |
| Strict approval | `pending_approval` | immediate |
| Development | executes | immediate |

### Governance, approvals, undo

- A gated write returned `pending_approval` with an approval URL and **wrote nothing** —
  post count unchanged at 2.
- Approve → request `approved` → queued → cron worker → queue `completed`, post created,
  change log written.
- Undo restored content `CHANGED` → `ORIGINAL`; a second undo was refused with
  `wpcc_already_rolled_back`.
- A **read-only token** was refused a write with `wpcc_token_read_only`, message naming
  the scope the customer sees.

### Integrations — on the real customer site

Certified against the customer-representative staging site (32 posts, 23 pages, 79
products, 716 attachments, 11 ACF field groups), which runs **Yoast** — while the
development site runs **Rank Math**, so the provider-agnostic SEO work is exercised on
both:

| Integration | Result |
|---|---|
| WooCommerce | product list, 79 products |
| ACF | 11 field groups |
| ACF Local JSON | compared by **content**, **0** out of sync |
| Contact Form 7 | forms listed |
| SEO | provider correctly detected as `yoast` |
| Elementor | **not certifiable on staging** — Elementor is active but the site has **zero** Elementor-built pages (`_elementor_data` count 0). Covered on the development site instead, where `test-elementor-step96` passes 26/0 against real widget data. |

### Staging restored

| | |
|---|---|
| Content | posts 32, pages 23, products 79, attachments 716, users 5, ACF groups 11 — exact |
| Theme | **139 / 139** files byte-identical, 0 failed |
| acf-json | 18 files, 0 drift |
| Site | HTTP 200, protection mode `client` |
| Test artefacts | isolated lifecycle install and uploaded ZIP deleted |

---

## 9. What the owner must do manually

1. **Decide on the plugin name and slug** (§2). This is the only thing standing between
   this artifact and submission, and it is a decision, not a defect.
2. **Review the one remaining staging token** — `AI Full Access 2026-08-02 02:18`, full
   scope, active. Its auto-generated label matches the admin AI-Setup flow rather than the
   tokens I created by CLI, so I could not establish it was mine and did not revoke
   someone's working credential. The token I created for this certification
   (`closeout-cert`) **was** revoked.
3. **Rotate the staging SSH password.** During the previous programme I printed the SSH
   helper's contents while diagnosing an upload failure, exposing it in that session
   transcript. It appears in no report, commit, or file.
4. **Merge and deploy when ready.** `main` auto-deploys and remains owner-controlled;
   nothing here has been merged.

---

## 10. Verdict

Every item in the closeout programme is complete. The regression baseline is empty and the
suite is green four runs running. Plugin Check reports zero errors against the artifact and
every warning is accounted for with evidence. Twelve documents describe the shipped
product, and the ten that contradicted it are gone. The artifact was certified on a clean
WordPress from install through connection, governance, approval, execution, audit, undo,
upgrade, uninstall and reinstall — never from the source tree. The customer site is
byte-identical to its baseline.

Six product defects were found and fixed during this closeout, including one that silently
corrupted content in a critical-risk operation. The single unresolved item is the plugin
name, which is the owner's decision and cannot be made by a release process.

### **READY FOR WORDPRESS.ORG SUBMISSION**

Subject to the owner's decision on the plugin name (§2), which may prompt a rename request
during review.
