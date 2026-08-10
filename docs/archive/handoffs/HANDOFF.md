# HANDOFF — WP Command Center

For the next Claude session. Written 2026-08-02 at the close of the V1 development arc.

---

## 1. Current release status

| | |
|---|---|
| Version | **1.0.0** |
| Branch | `fix/prod-session-issues-1-12` |
| Commit | **`392f82a`** — pushed, `origin` matches local |
| `main` | `13549c2` — **deliberately untouched** |
| Release stage | V1 release-qualified; feature-frozen |
| WordPress.org | **Submit-ready, not approval-guaranteed** (see §5) |
| Commercial | **Ready for direct distribution to customers** |

> **Deploy warning.** Production deploy is *pull-based*: an hPanel cron runs
> `~/wpcc-deploy.sh`, which pulls `origin/main`. **Pushing to `main` puts code live
> in about a minute.** This arc was intentionally pushed to the working branch only.
> Merging to `main` is a release decision for the human, not a routine step.

---

## 2. Architecture snapshot (verified live, not assumed)

| | |
|---|---|
| MCP tools exposed | **42** |
| Operations in catalogue | **42** (all reachable — none report "not available") |
| Operations mapped in CapabilityRegistry | **34** |
| Capabilities | **23** |
| Protection modes | **3** — `client` (Standard, default), `enterprise` (Strict), `developer` |
| DB version | **2.6.0** |
| Action labels | 315, **0 un-humanised** |
| Package | 278 files, ~904 KB |

Fail-safe: a missing/corrupt `wpcc_security_mode` resolves to `client`, never `developer`.

---

## 3. Completed during this session

**Integrations — the headline.** WooCommerce 10.9.4, Elementor 4.2.1, Contact Form 7
6.1.6, Rank Math 1.0.275 and Classic Editor were installed for the first time
(ACF 6.8.6 was already present). Every integration operation was exercised against
real data. This had been deferred by every previous program and it is where the
worst defect of the arc was hiding.

**Product experience.** Onboarding rebuilt around one promise and one action;
information architecture consolidated to Home / Approvals / Changes / Settings;
Protection presented as three comparable modes; connection flow stripped to one
decision and one action before a token exists; dashboard shows real recent changes;
approvals show what will change *before* you approve it; language made
non-English-first throughout.

**Engine correctness.** Malformed calls rejected before they can reach the approval
queue; several operations that wrote on missing input now require it; dry runs no
longer recorded as changes; read-only actions no longer gated or logged.

**WordPress.org.** 131 translator comments, 7 numbered placeholders, GPL LICENSE,
accurate disclosure of local command execution, multisite position documented,
plugin header claim corrected. Plugin Check errors **302 → 164**, i18n errors **0**.

---

## 4. Verified working (personally exercised, with database evidence)

**Screens** — ✓ Home (setup + connected) ✓ Approvals (Pending / Decided / Execution,
detail, bulk select, bulk reject) ✓ Changes (Timeline / Sessions / Can be undone,
filters, undo) ✓ Settings → Protection ✓ Connections (Assistants / Your own software
/ Access tokens) ✓ Advanced (Built-in AI, Diagnostics, System, Capabilities, File
access, Search & replace) ✓ Simple and Detailed density ✓ empty / loading / success /
error states.

**Core loop** — ✓ token create + reveal + revoke ✓ MCP `tools/list` (42) ✓ reads
instant ✓ writes gated ✓ approve → change lands on the site ✓ undo → site reverts
✓ rollback ✓ change history ✓ audit trail.

**Governance** — ✓ a token **cannot** approve its own request
(`wpcc_approval_requires_human`) ✓ restricted tokens blocked from writes and deletes
✓ editor and subscriber blocked at the REST permission callback ✓ unauthenticated
REST → 401 ✓ path traversal refused ✓ `wp-config.php` unreadable ✓ revoked token
refused ✓ duplicate rollback refused.

**Integrations** — ✓ WooCommerce (list/get/search/create/update/price/stock/
categories/orders/coupons **+ rollback**) ✓ Elementor (read widget tree, write text,
**rollback restored the original heading with valid JSON**) ✓ Rank Math (provider
auto-detected; `rank_math_title` written and read back) ✓ CF7 (form list/get)
✓ ACF (13 field types + repeater, group, flexible content, clone; values across
post / user / term / options).

**Production** — ✓ activate / deactivate ×3 cycles ✓ 2.4.0 → 2.6.0 upgrade
✓ uninstall retains data by default ✓ missing tables degrade without a fatal
✓ 500 changes / 203 pending / 44 tokens: every admin read < 3 ms except the
operation catalogue (~190 ms, built once per request and cached).

---

## 5. Remaining known limitations

1. **WordPress.org approval is not guaranteed.** 164 Plugin Check errors remain.
   The two largest categories were traced and are sniffer false positives (SQL
   prepared into a variable then used; output escaped inside closures). What a
   reviewer may still query: ~56 direct filesystem calls (`unlink`, `fopen`,
   `rename` — real, used by patches/snapshots/backups) and `proc_open` (now
   disclosed in the readme).
2. **WooCommerce orders and refunds are untested** — no order flow exists on the
   test store. Products, prices, stock, categories and coupons are tested.
3. **Elementor Pro widgets untested** — free version only.
4. **Multisite unsupported in V1** — documented; a network-activated sub-site shows
   an empty Command Center rather than failing.
5. **Approvals list caps at 100 server-side.** With a larger backlog the UI is
   honest ("Showing 1–25 of 100 (210 pending in total)") and bulk reject clears it,
   but items beyond 100 are unreachable until the queue is worked down.
6. **Undo costs 5 clicks** under Standard protection, because an undo is itself a
   change and goes through approval. Correct behaviour; the most likely source of a
   "but you promised undo" complaint.
7. **The undo approval row reads only "Undo a change"** — it does not name which
   change. Fine with one pending, ambiguous with several.
8. **Two pre-existing test failures**: `test-acf-runtime` (`val: get`, `val: nf`) and
   `test-user-runtime` (validation assertions). Both reproduce on the parent commit
   `65a8ee4` — not caused by this arc. `test-user-runtime`'s failure count varies
   with site state; those tests are order-dependent.
9. **Double-approve returns success instead of "already approved."** Measured: it
   creates **no** second queue item and **no** second change — side effects are
   idempotent. API-semantics wart, not a data-integrity bug.

---

## 6. Hard rules — frozen, do not touch

Do **not** redesign, refactor, or "improve" any of the following unless you have a
**reproducible bug** in hand:

- **Governance** — the approval model, the human-approver requirement, the risk
  tiers and the mode/risk gating table in `SecurityModeManager::requires_approval()`.
- **Security model** — token scopes, `CapabilityRegistry`, the read-only scope
  allowlist, REST permission callbacks.
- **Runtime** — `OperationExecutor` dispatch order, the rollback engine,
  `ChangeRecorder`.
- **Schema** — `Schema::DB_VERSION` and every `wpcc_*` table.
- **Architecture** — the registry pattern, the MCP↔REST bridge, provider layer.

Also frozen by decision, not by risk:

- No new features, no new dashboards, no onboarding tours, no animations.
- Purely cosmetic changes were explicitly reverted this arc. If a change cannot be
  justified as *easier to understand, easier to trust, or easier to use*, do not
  make it.

---

## 7. Next session objective — the only one

**Reality-check the plugin on the real staging server.**

Reproduce genuine customer behaviour on a live site. Nothing else.

- No redesign. No feature work. No architecture work. No speculative refactoring.
- Install the built package on staging and walk the real journey: connect an actual
  assistant (Claude Desktop / Codex / ChatGPT) using the generated configuration,
  ask for a change in natural language, review it, approve it, undo it.
- The single most valuable thing to confirm is the part **no local environment could
  test**: that a real MCP client accepts the configuration this plugin generates and
  the relay connects. Everything upstream of that is verified; that handshake is not.
- Watch for host differences: `proc_open`/`shell_exec` disabled, object caching,
  different PHP version, real SEO/Woo data volume.
- If you find a bug: reproduce it, fix the smallest thing that fixes it, re-test,
  regression-test the surrounding feature. Then stop.

**Useful context**

- Local test site: `/Applications/AMPPS/www/wpcc-clean` (WP 7.0.2, PHP 8.2.27),
  admin `owner`. All five integrations installed with seeded data.
- Build: `bash scripts/build-release.sh` → `build/wp-command-center-1.0.0.zip`
  (`build/` is gitignored).
- Tests: `tests/*.sh`, run individually with `bash tests/<name>.sh`. Prefer the
  targeted suite for whatever you touch; the full sweep takes ~20 minutes.
- Prod REST namespace is `wp-command-center/v1` (**not** `wpcc/v1`).
