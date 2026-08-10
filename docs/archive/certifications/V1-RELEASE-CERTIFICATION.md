> # SUPERSEDED — HISTORICAL RECORD ONLY
>
> This document is **not current** and must not be used to verify, build, or ship
> anything. It is kept because it records what was true when it was written.
>
> **The single authoritative engineering document is [`RELEASE_HANDOFF.md`](../../../RELEASE_HANDOFF.md) at the repository root.**
>
> Superseded on 2026-08-10. Reason: certifies an artifact superseded by the 2026-08-10 security re-cut

---

# WP Command Center 1.0.0 — V1 Release Certification

> **SUPERSEDED.** This certification was accurate when written, but the closeout
> programme that followed fixed six further product defects and took the test suite
> to zero accepted failures. See
> [V1-CLOSEOUT-CERTIFICATION.md](V1-CLOSEOUT-CERTIFICATION.md) for the current state.


**Date:** 2026-08-02
**Branch:** `release/v1-finalization` (not merged; `main` remains owner-controlled)
**Artifact:** `build/wp-command-center-1.0.0.zip` — 284 files, 940 KB
**Scope:** PROGRAM-V1-FINALIZATION, Phases 0–16, run to completion as one continuous program.

This report supersedes every interim certification produced during the program. It ends
with exactly one verdict.

---

## 1. What this program was asked to do

Close every documented V1/V1.1 gap before WordPress.org submission, without weakening
governance, approval protection, capability enforcement, rollback safety, security
defaults or audit integrity, and without silently changing public contracts or expanding
the operation catalogue.

Protected invariants, verified from the **installed artifact** on a clean WordPress
(section 5), not from the development checkout:

| Invariant | Required | Measured |
|---|---|---|
| MCP tools | 42 | **42** |
| Catalogue operations | 42 | **42** |
| Mapped operations (`OPERATION_MAP`) | 34 | **34** |
| Capabilities (`ALL_CAPABILITIES`) | 23 | **23** |
| Protection modes | 3 | **3** |
| `DB_VERSION` | 2.6.0 | **2.6.0** |

---

## 2. What changed

28 commits, 67 files, +3,786 / −201 lines, from `406df6e` (program start) to
`c7c506d`.

The defects worth naming, each found by exercising the product rather than by reading
code:

| # | Defect | Consequence before the fix |
|---|---|---|
| 1 | MCP relay was never in the release ZIP | Every generated client config pointed at a 404. The product could not be connected to at all. |
| 2 | Compact mode silently truncated strings | `file_read` reported `truncated:false` while returning a fraction of the file; a read→patch cycle computed −46 lines against a live customer theme. |
| 3 | ACF fields created orphaned | On any site using ACF local JSON, `acf_get_field_group($key)` returned the stale JSON copy with `ID 0`, so new fields attached to nothing. |
| 4 | A failed, rolled-back patch reported `completed` | The owner was told a change succeeded when it had been reverted. |
| 5 | 13 operations filed bogus actions as approval requests | A typo produced a pending, often critical-risk request that could only ever fail once approved. |
| 6 | `rollback_manage` was a dead end for non-patch ids | An assistant holding a valid `rollback_id` was routed to the one entry point that only understands patches. |
| 7 | Unauthenticated `/mcp` returned HTTP 500 | An auth failure looked like a server fault. |
| 8 | Patches could not create files, but said "path does not exist" | The error blamed the caller for a capability the engine does not have. |
| 9 | ACF local-JSON lifecycle was wrong end to end | Files on disk — the artefact an agency commits — did not reflect the database. |
| 10 | A malformed actor stranded a governed change | A fatal `TypeError` left the request `approved`, never queued, with no audit entry: a silently stuck approval. |
| 11 | The pre-approval guard flattened every runtime's error code | 32 self-describing codes collapsed to a generic one; integrations branching on the specific code stopped matching. |
| 12 | The pre-approval guard rejected legitimate work | `replace` is required by `safe_search_replace`, and replacing with the empty string is how you delete text — so "delete this text site-wide" was refused outright. |
| 13 | "Delete all data on uninstall" left a table behind | `wpcc_telemetry` is created lazily and was absent from the uninstall list, so it survived on every site that had ever served a request. |
| 14 | Security-redaction tests asserted impossible key formats | Three assertions could never pass and proved nothing about whether secrets are scrubbed. |

Items 10–14 were found during Phases 15/16 — that is, by certifying the release artifact
rather than the source tree. Items 11 and 12 were **introduced earlier in this same
program** and are discussed honestly in section 6.

---

## 3. Compliance (Phase 14)

Plugin Check, measured against the **built artifact** (what wordpress.org reviews), not
the development checkout:

```
ERRORS:   0        (from 164 at program start)
WARNINGS: 817      (none actionable — see below)
```

Every one of the 164 findings was audited individually at its own line. The 41 SQL sites
and 28 filesystem/`proc_open` sites each carry a per-site justification. Suppressions use
`phpcs:disable`/`phpcs:enable` blocks rather than line-numbered `phpcs:ignore`, because an
earlier attempt at line-anchored annotation inserted comments **inside SQL string
literals** and would have shipped broken queries; that attempt was caught by reading the
result, reverted across 12 files, and redone.

The residual 817 warnings are dominated by `PrefixAllGlobals.NonPrefixedVariableFound` in
view files (template locals, not globals) and `NonPrefixedHooknameFound` where the plugin
fires **core** cache hooks by their required names.

> Measuring Plugin Check against the dev checkout instead reports 204 errors — all of them
> `build/*.zip`, `.DS_Store`, `.gitignore`, `tests/*.sh`. None of those files ship. The
> artifact is the correct measurement target and is what the number above reflects.

---

## 4. Release artifact certification (Phase 15)

- Built by `scripts/build-release.sh`, which is an **allowlist**, not a blocklist.
- The MCP relay is a hard build requirement: the build asserts
  `sdk/javascript/wpcc-mcp-relay.mjs` is present and **exits 1** if it is not. This guard
  was proven by deleting the file and confirming the build fails.
- Artifact contents audited for leakage: **no** `tests/`, `.git`, `node_modules`, `.env`,
  `wpcc-env.sh`, `.DS_Store`, or `*.md`.
- Version consistency: header `1.0.0`, `WPCC_VERSION` `1.0.0`, readme `Stable tag: 1.0.0`,
  `Requires at least: 6.4`, `Requires PHP: 8.0`, `Tested up to: 7.0`.

---

## 5. Lifecycle certification on a clean WordPress (Phase 16)

An isolated WordPress **6.9.5** install (separate table prefix, separate admin user) was
created on the staging server, certified against, and destroyed. Every assertion was made
against the **installed ZIP**, never the development checkout.

**Lifecycle: 24 passed / 0 failed**

| Stage | Result |
|---|---|
| Clean install from the ZIP | installs; present and **inactive** before activation |
| Activation | no error; defaults to **Standard (`client`)** — not developer |
| Schema | `DB_VERSION` 2.6.0; **15** schema tables (+1 lazily created) |
| No fatal | site returns 200 |
| Telemetry absence | reads survive a missing telemetry table (`total=0 recent=0`) |
| Relay | shipped in the ZIP (5,632 bytes) and reachable over HTTP (200) |
| Deactivate | clean; site healthy; data retained |
| Reactivate | clean |
| Upgrade in place | succeeds; stays **active**; schema preserved |
| Uninstall (default) | data **retained** (16 tables) |
| Reinstall + reconnect | activates; adopts existing data |
| Uninstall (opt-in purge) | **0** tables, **0** `wpcc` options remain |

### Connection and governance, end to end on that fresh install

- MCP `initialize` handshake: `WP Command Center 1.0.0`, protocol `2024-11-05`
- `tools/list`: **42** tools
- A real read (`system_info/system_overview`) returns live data, `isError: false`
- **Governance holds by default:** `content_manage/content_create` in Standard mode returned
  `pending_approval` with an `approval_url`, and **nothing was written** — the database
  still contained only WordPress's own "Hello world!" post.
- Approve → request `approved` and enqueued → cron worker (registered at activation)
  executes → queue `completed`, request `executed`, post created.
- Audit trail recorded `operation.execution.completed`, `change.recorded`, and
  `operation.worker.completed`.

A fresh install uses WordPress's default **plain permalinks**, where `/wp-json/` 404s. The
plugin advertises its endpoint through `rest_url()` (30 call sites; no hardcoded
`/wp-json/` in any user-facing surface), which correctly emits the `?rest_route=` form. A
customer on a default install therefore receives a URL that works.

---

## 6. Regression evidence — and a correction to how it was measured

`tests/regression-baseline.tsv` was last updated **2026-06-14** and predates roughly seven
weeks of deliberate contract work, including the uniform self-describing
`wpcc_invalid_*_action` errors shipped in a prior session. Measured against that file, the
suite reported **98 "net-new" failures** — a number that is simply wrong, and that would
have buried the failures which were genuinely attributable to this program.

The correct measurement is this branch against **its own starting commit**, `406df6e`.
Across all 39 affected suites:

| Measurement | Failures |
|---|---|
| At program start (`406df6e`) | 85 |
| After this program, before remediation | 98 → **13 genuinely mine** |
| After remediation | **77** |

**Net effect of this program: −8 failures. Zero suites worse. Eight suites fully repaired.**

The 13 that were mine were two real regressions, both from the Phase 2 pre-approval guard,
and both fixed rather than explained away:

1. **Flattened error contract (10 failures).** Catching a bogus action before the approval
   gate was correct; replacing all 32 runtime-specific codes with a generic
   `wpcc_invalid_action` was not. `InvalidActionContract` now maps each operation to its
   runtime's code, noun and describe-hint and reuses the runtimes' own
   `InvalidAction::message()` builder, so **both the code and the message** are identical
   to what the runtime would have emitted. `tests/test-invalid-action-contract.sh` asserts
   the map against the runtime sources so the two cannot drift.
2. **Rejected legitimate work (1 failure, and a real functional bug).** The
   required-parameter guard treated `''` and `[]` as missing, so replacing a string with
   the empty string — how you delete text site-wide — was refused. The guard now fires only
   on **absent/null** parameters; empty values keep the runtime's own specific codes.

The remaining 2 were a test asserting that a **bogus** action returns `pending_approval` —
it was encoding the very bug that commit `4e47aae` fixed. It was updated to the real action
and **extended** with an assertion that a bogus action is refused rather than queued
(27 assertions → 29; none removed).

---

## 7. Audit of every remaining failing assertion

Final full-suite run on the release commit:

```
T2: 180 suites — 6,244 passed, 100 failed, 3,579s
```

All 101 failing assertions from the preceding authoritative run were captured individually
and audited — not sampled. Three were fixed (§7.1). **Every one of the remaining 98 is
identical at `406df6e` and at HEAD**, i.e. pre-existing and untouched by this program.

The final run shows 100 rather than 98 because two suites — `test-capability-runtime` and
`test-health-verification` — fail only inside the full run and **pass cleanly standalone**
(62/0 and 22/0 respectively). They are order/state-dependent flakes in the harness, a known
characteristic of this suite, not product failures. The full run was executed standalone;
an earlier run that overlapped with other suites against the same database reported 122
failures, and that number should be disregarded — suites that switch protection mode
globally cannot run concurrently.

### 7.1 Fixed during this audit — 3

`test-security-redaction.sh` asserted that `AKIAFAKEID01234567890` (AKIA + **17** chars)
and `sk_fake_test_…` would be scrubbed. Neither is a real credential format — an AWS access
key ID is `AKIA` + exactly 16, and Stripe secret keys are `sk_live_`/`sk_test_` — so the
redactor correctly did not match them and the assertions could never pass.

**Verified directly against `Redactor::redact()` before touching anything:** real
`AKIA`+16, `sk_test_`, `sk_live_` and `sk-ant-` keys are all replaced with
`[REDACTED_SECRET]`. No redactor code was changed; only the fixtures. Redaction was never
broken — but the test proved nothing either way, which is the worst state for a security
test. Now 35 passed / 0 failed.

### 7.2 Verified-correct behaviour, stale assertion — 51

Each of these was reproduced directly to confirm the *behaviour* is right and only the
*expectation* is outdated:

- **Double-rollback refusal (1).** Verified: both the runtime and the admin REST path
  refuse a second rollback with `wpcc_already_rolled_back`. The test reads `result.code`;
  the error now sits in the normalized `errors[]` envelope. Rollback safety is intact.
- **Read-only token denial wording (5).** The companion assertion
  `denied for read_only (wpcc_token_read_only)` **passes** — enforcement is intact. Only the
  wording check fails: the message now says "restricted" and explains what a restricted
  token can do and how to change it, instead of the single word "read-only".
- **Invalid-action / not-found error shape (41).** The uniform self-describing error work
  from a prior session changed message phrasing across the comments, media, user, woo,
  menu, forms, bulk and change-history suites. Confirmed live: e.g. `media_manage` returns
  `wpcc_invalid_media_action` with the full valid-action list.
- **Version and count assertions (3).** `expected '0.1.0', got '1.0.0'` (×2) and
  `MCP tool count stays 40 … got 42`. `1.0.0` and 42 are the intended release invariants;
  the tests assert pre-release values.
- **`bulk_manage` empty-id probe (1).** The probe sends an empty id list and expects a
  success-shaped no-op. The runtime instead refuses with `wpcc_missing_bulk_ids`
  ("ids is required — provide the IDs to update"), which is the safer behaviour for a bulk
  write. Verified live.

### 7.3 Environment-dependent, not product defects — 29

- **SEO suite expects Yoast (6).** The dev site runs Rank Math (`RankMath: yes`,
  `Yoast: no`); the suite hardcodes `expected 'yoast'`. All six failures, including the two
  drift-rollback assertions, are downstream of that single mismatch. The F-1 delta-rollback
  behaviour these target was separately certified and prod-verified under PROGRAM-4.
- **AI provider deliberately off (13).** Alt-text, AI-platform, AI-content-builder,
  seo-audit and seo-generate assertions expect a configured provider. The shipped posture
  is AI **off** with no key set.
- **Client-config shape (10).** The generated config uses a `bash` + `curl` + `node` relay
  launcher with the MCP URL in `env`, not an `npx` command with the URL in `args`. The
  tests assert the old shape. The current shape was verified working end to end on the
  clean install (relay 200, handshake OK, 42 tools).

### 7.4 Repo hygiene, nothing user-facing — 18

- **Missing docs (11).** `docs/OVERVIEW.md`, `ARCHITECTURE.md`, `INSTALLATION.md`,
  `SECURITY.md`, `MCP.md`, `OPERATIONS.md`, `API.md`, `CAPABILITIES.md`,
  `AI-INTEGRATIONS.md`, `TROUBLESHOOTING.md`, `QUICKSTART.md` genuinely do not exist.
  Verified that **nothing shipped references them** and `docs/` is **not in the ZIP**, so no
  customer-facing link is broken. This is aspirational documentation the test asserts, and
  it remains an open item — see §9.
- **Admin surface assertions (7).** Builder/proposal UI tabs are behind a build flag that
  ships **off** by design; the remaining adoption/usability copy assertions are stale.

---

## 8. Staging: restored to baseline, with one honest boundary

The customer-representative staging site (32 posts, 23 pages, 79 products, 716
attachments, 5 users, 11 ACF field groups) was returned to its pre-certification state and
verified:

- Content counts: **exact match** on every type.
- Theme: **139 / 139** files byte-identical (`md5sum -c`, 0 failed).
- Customer "Page Builder" ACF group intact; no certification artefacts left behind.
- Site returns 200; protection mode `client`.
- The isolated lifecycle install and the uploaded artifact were **deleted**.

**Boundary I cannot certify:** the theme baseline covers 139 files and contains **zero**
`acf-json` entries — precisely the 18 files the ACF work rewrites. I can state that all 18
are **content-in-sync with the customer database (0 drift)** and that the customer's field
groups are intact. I **cannot** certify byte-identity to the pre-certification files,
because no hash baseline was captured for that directory. For an agency that commits
`acf-json` to git, formatting or key-order differences may appear as a diff. This is
inherent to what the feature does — ACF itself rewrites these files — but the evidence
boundary should be stated rather than glossed.

---

## 9. Open items

These are **not** blocking in my assessment, but they are real and the owner should decide
on them:

1. **`tests/regression-baseline.tsv` is seven weeks stale.** It reports 85 pre-existing
   failures as "net-new" and nearly caused a mis-certification in this very program. It
   should be refreshed to the current known-failing set so future runs surface true
   regressions. I have deliberately **not** refreshed it, because doing so is a judgement
   about which failures are accepted, and that is the owner's call.
2. **98 pre-existing failing assertions**, fully audited above. Most are stale expectations
   from deliberate contract improvements; none indicate broken product behaviour that I
   could reproduce. They should be brought current so the suite is trustworthy again.
3. **Two order-dependent flakes** (`test-capability-runtime`, `test-health-verification`)
   that pass standalone. Worth isolating so a full run is reproducible.
4. **11 referenced-but-absent docs.** Nothing shipped links to them; they do not affect a
   customer.
5. **One active full-scope API token remains on the staging site**
   (`AI Full Access 2026-08-02 02:18`). Its auto-generated label matches the admin AI-Setup
   flow rather than the tokens I created by CLI, so I could not establish it was mine and
   did not revoke someone's working credential. Revoke it if it is not in use.
6. **Credential hygiene note.** While diagnosing an upload failure I printed the contents of
   the staging SSH helper, which exposed its password in the session transcript. It appears
   in no report, commit, or file I authored. Rotate that password.

---

## 10. What I did not do

- Did **not** merge to `main` or deploy to production. `main` auto-deploys and remains
  owner-controlled.
- Did **not** submit to WordPress.org.
- Did **not** expand the operation catalogue, add features, or redesign anything.
- Did **not** weaken governance, approval protection, capability enforcement, rollback
  safety, security defaults, or audit integrity. Where a fix touched those paths, it
  strengthened them (items 10–13 in §2).

---

## 11. Verdict

Every phase is complete with evidence. The two regressions this program introduced were
found, fixed, and covered by tests that prevent their return. The release artifact was
certified on a clean WordPress from install through connection, governance, approval,
execution, audit, upgrade, uninstall and reinstall — not from the development checkout.
Plugin Check reports zero errors against the artifact. The protected invariants hold,
measured from the installed package. The staging site was restored to baseline and
verified byte-for-byte where a baseline existed, with the one gap stated plainly.

The open items in §9 are documentation and test-suite hygiene, plus two credential actions
for the owner. None of them changes what the plugin does on a customer's site.

### **READY FOR WORDPRESS.ORG SUBMISSION**
