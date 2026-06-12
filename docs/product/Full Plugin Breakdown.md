# WP Command Center — Complete Plugin Breakdown

**As of:** 2026-06-11 — V1 Beta complete (Phase 5, Steps 1–36)
**Plugin version:** 0.1.0 · **REST namespace:** `wp-command-center/v1` · **DB schema version:** `2.2.0` · **REST endpoints:** 58
**Validation:** 718 automated assertions passing across 25 test suites; real-site validation on a live WordPress + WooCommerce + ACF + Contact Form 7 stack — **PASS**

This document explains, top to bottom: what problem this plugin solves, how it's built, every feature it has, why each feature exists, and how it all works together.

---

## 1. The Problem This Plugin Solves

WordPress agencies and developers almost always have **wp-admin access** to client sites — and almost never SSH, WP-CLI, or root access. Modern AI coding agents (Claude, Codex, etc.) are extremely good at the kind of work that *requires* that lower-level access: reading files, searching code, running diagnostics, making changes.

**WP Command Center closes that gap from inside wp-admin.** It gives an AI agent "SSH-like" operating capability — without ever opening SSH — by exposing a secure, token-authenticated REST API that an agent can call to understand a site, investigate problems, and propose changes.

It is deliberately **not**:
- A backup plugin (not MainWP/ManageWP/UpdraftPlus-style)
- A monitoring/uptime tool
- "Another maintenance plugin"

It's a new category: **the infrastructure layer between WordPress and AI agents.**

### The two pillars

1. **The AI Agent Gateway** — how an agent gets context and access (Site Intelligence, Diagnostics, File Access, Code Search, Recommendations, a self-describing API manifest).
2. **The Patch Engine** — how an agent makes changes safely (every change is a reviewable, approvable, snapshot-backed, rollback-able "patch" — **the AI never edits files directly**).

Everything else in the plugin (Operations, Health Verification, Recommendations, the Agent Runtime hierarchy, the Admin Dashboard) exists to feed information into, or act as a controlled extension of, those two pillars.

---

## 2. Core Design Principles (Why It Works The Way It Does)

These principles show up *everywhere* in the codebase and explain almost every "why" question you might have:

1. **AI never writes directly to files or the database.** Every mutation goes through a request → review → approval → execution pipeline. A human (or an explicitly-approved plan) is always in the loop before anything changes on disk or in the DB.

2. **Backups are scoped to the change, not the whole site.** A file edit snapshots only that file. A search-and-replace doesn't snapshot the whole DB. This keeps the system fast and cheap while still making rollback fast and reliable. Rollback is **a consequence of the Patch Engine**, not a separate "snapshot everything" feature.

3. **Dry-run by default for anything that mutates data.** Search & replace, plugin/theme updates — all default to "show me what *would* happen" with zero risk. Doing it for real requires an explicit flag *and* approval.

4. **Strict state machines, no silent no-ops.** Sessions, tasks, actions, plans, patches, recommendations, queue items — every one of these has an explicit set of valid states and transitions. An invalid transition (e.g. approving an already-rejected plan) is a hard, auditable error — never a silent success.

5. **Token-based authentication, not cookies.** API tokens (format `wpcc_` + 64 random chars) let an external AI agent act as its own auditable principal, with `read_only` or `full` scope, expiry, and revocation — without ever needing a logged-in WordPress session.

6. **Everything is audited.** Every meaningful state change is written to an append-only audit log with an "actor" (which token, or which admin user, did this). The audit log is the backbone of the unified Agent Timeline.

7. **Secrets are redacted automatically.** Anywhere file content, search results, logs, or context bundles could leak an API key, password, or private key, a `Redactor` scans and replaces it with `[REDACTED_SECRET]` before it ever leaves the server.

8. **The API describes itself.** `GET /agent/manifest` lets any agent discover, on first connection, exactly what this install supports — capabilities, security posture, the full route list, error codes, and server capabilities — with zero pre-configuration.

9. **Recommendations are deterministic, not AI-generated (yet).** The Recommendation Engine is a hardcoded rule set over diagnostics data — not an LLM call. This keeps the "what should I fix?" layer trustworthy and explainable while leaving room for an LLM-powered version later.

10. **Every new capability follows the same pipeline shape**: *Request → Validation → (Approval if risky) → Queue/Execute → Audit + Timeline → Agent Context exposure → Manifest registration → Tests.* Once an agent (or a developer) learns this pattern, it applies everywhere.

---

## 3. How The Plugin Is Organized

### 3.1 Bootstrap

- **`wp-command-center.php`** — the plugin file WordPress loads. Registers activation/deactivation hooks and boots `Plugin::instance()->run()`.
- **`Plugin.php`** — singleton orchestrator. On `run()`:
  1. Calls `Schema::maybe_upgrade()` so the database is always current, even after a plugin update without reactivation.
  2. Registers a custom 5-minute WP-Cron schedule and schedules the background **Operation Worker**.
  3. Always initializes the REST API (it must work outside `/wp-admin`, since AI agents call it directly).
  4. If running inside `/wp-admin`, also initializes the Admin Menu and enqueues admin CSS/JS.
- **`Autoloader.php`** — PSR-4-style autoloader: maps the `WPCommandCenter\` namespace to files under `includes/`.
- **`Activator.php`** — on activation, runs `Schema::install()` (creates all DB tables, runs one-time legacy migration) and schedules the cron hook.
- **`Deactivator.php`** — on deactivation, only unschedules the cron hook. **No data is deleted** — everything persists so re-activating doesn't lose history.

### 3.2 Module Map

| Folder | What lives here |
|---|---|
| `includes/Core/` | Bootstrap, autoloading, activation, database schema |
| `includes/Security/` | API tokens, path allow/deny rules, audit log, secret redaction, capability checks |
| `includes/AiAgent/` | The REST API gateway itself: routing, file access, code search, context bundling, timeline |
| `includes/SiteIntelligence/` | The site scanner (Layer 1 — raw facts about the install) |
| `includes/Diagnostics/` | Performance / Security / WooCommerce checks built on top of the scanner (Layer 2 — verdicts) |
| `includes/Recommendations/` | The deterministic recommendation engine (Layer 3 — "here's what to do") |
| `includes/PatchSystem/` | The patch state machine, diff generation, approve/apply/rollback logic |
| `includes/Rollback/` | Snapshot creation and verified restore |
| `includes/Operations/` | The 8 supported "do something on this site" operations, plus the request/queue/worker/results pipeline |
| `includes/Health/` | The Health Verification Engine (7 read-only checks) |
| `includes/System/` | Environment mode (dev/staging/production) and guarded data cleanup |
| `includes/Admin/` | The wp-admin UI: menu, assets, and 7 view pages |

### 3.3 Storage Architecture

The plugin deliberately separates **queryable indexes** from **bulk content**:

| What | Where | Why |
|---|---|---|
| Patch & snapshot **metadata** (status, timestamps, relationships) | MySQL tables (`wpcc_patches`, `wpcc_snapshots`, etc.) | Fast filtering/listing, joins across the runtime hierarchy |
| Patch **content** (diffs, file contents, status history, verification results) | JSON files in `wp-content/uploads/wpcc-patches/` | Large/variable-size data doesn't bloat the DB |
| File **snapshots** (pre-change backups) | Raw `.snapshot` files in `wp-content/uploads/wpcc-snapshots/` | Byte-for-byte restore source |
| API **tokens** | `wp-content/uploads/wpcc-tokens/manifest.json` | Outside the DB, protected directory |
| **Audit log** | Append-only JSONL at `wp-content/uploads/wpcc-audit/audit.log` | Tamper-evident, simple, greppable |

All three `wpcc-*` upload directories are protected the same way: an `.htaccess` with `Require all denied` / `Deny from all`, plus an `index.php` that exits immediately — so even if someone guesses the URL, the web server refuses to serve the files.

---

## 4. Security & Trust Layer (`includes/Security/`)

This is the layer that makes it *safe* to let an external AI agent talk to a production WordPress site.

### 4.1 API Tokens (`AuthTokens.php`)

- **Format:** `wpcc_` + 64 random alphanumeric characters. Shown to the user **once**, at creation time.
- **Storage:** only a salted hash is stored — `hash_hmac('sha256', $raw_token, wp_salt('auth'))` — in `wpcc-tokens/manifest.json`. The UI shows only the first 12 characters as a "preview" so admins can recognize a token later without it ever being recoverable.
- **Scopes:**
  - `read_only` — can call any `GET` endpoint (inspection only).
  - `full` — can also call every `POST` endpoint (create patches, approve, apply, rollback, run operations, etc.).
- **Lifecycle fields:** label, scope, status (`active`/`revoked`), `created_at`, `expires_at` (optional), `last_used_at` (updated on every successful use).
- **Validation (`validate()`)** on every request:
  1. Hash the incoming token the same way and compare with `hash_equals()` (constant-time, avoids timing attacks).
  2. Reject if `revoked` → `wpcc_token_revoked`.
  3. Reject if past `expires_at` → `wpcc_token_expired`.
  4. Reject if no match at all → `wpcc_invalid_token`.
  5. Reject if no `Authorization: Bearer ...` header at all → `wpcc_missing_token`.
  6. On success, update `last_used_at`.

All of these are 401 errors — a failed/expired/revoked token simply can't get in the door, no matter what endpoint it tries.

### 4.2 PathGuard (`PathGuard.php`)

Every file path the API ever touches — for reading, searching, or patching — passes through `PathGuard::resolve()`.

- **Allowed roots:** `wp-content/themes/`, `wp-content/plugins/`, `wp-content/mu-plugins/`. Nothing outside these three directories is ever reachable.
- **Always denied**, even inside allowed roots:
  - `wp-config.php`, `wp-config-sample.php`, `.htaccess`, any `.env*` file
  - `.git/`, `.svn/`, `node_modules/`, `vendor/`
  - Private key/cert files: `*.pem`, `*.key`, `*.p12`, `*.pfx`, `*.crt`, `*.cer`, `id_rsa`, `id_ed25519`
  - Anything with `credentials`, `secrets`, `auth.json`, or `service-account.json` in the path
- **Path traversal** (`..`) is rejected outright.
- A blocked path returns `wpcc_file_blocked` (HTTP 403); a path that simply doesn't exist returns `wpcc_not_found`.

### 4.3 Redactor (`Redactor.php`)

Even inside the *allowed* directories, plugins/themes routinely contain `.env` files, license keys, and hardcoded credentials sitting in otherwise-readable PHP files. `PathGuard` can't catch those — so the **Redactor** scans the actual *content* of anything returned to an agent and replaces matches with `[REDACTED_SECRET]`.

It detects:
- PEM private key blocks
- JWTs
- AWS access keys (`AKIA...`)
- Anthropic keys (`sk-ant-...`)
- OpenAI keys (`sk-...`)
- Stripe keys (`sk_live_`, `pk_live_`, etc.)
- `Authorization:` headers and `Bearer ...` tokens
- `user:password@host` basic-auth URLs
- Generic `password=`, `secret=`, `api_key=`, `access_token=`, `client_secret=` style assignments

**Wired into:** `/files/content`, `/search`, `/diagnostics/debug-log`, `/context`, `/agent/context`, and `/agent/timeline`. Every redaction also fires a `security.content_redacted` audit event, so even the *act of hiding a secret* is logged.

### 4.4 Capabilities (`Capabilities.php`)

A small wrapper requiring WordPress's `manage_options` capability — i.e. only Administrators can use the wp-admin UI. (The REST API uses tokens instead, see above.)

### 4.5 Audit Log (`AuditLog.php`)

- Append-only **JSONL** file at `wp-content/uploads/wpcc-audit/audit.log` (same protected-directory pattern as tokens/snapshots).
- Every entry: `{ timestamp, action, context }`, where `context.actor` identifies *who* did it:
  - `{ type: 'token', id, label }` — an API token
  - `{ type: 'admin', user_id }` — a logged-in WordPress admin
  - `{ type: 'unknown' }` — fallback
- Logged actions span the entire system: session/task/action/plan/patch lifecycle, recommendation transitions, every operation's started/completed/failed events, queue and worker events, health verification runs, security blocks/redactions, and system cleanup/environment changes.
- `tail(limit)` returns the most recent entries (default 200), newest first — this is what powers the Agent Timeline.

---

## 5. The AI Agent Gateway (REST API)

**Base URL:** `https://yoursite.com/wp-json/wp-command-center/v1/...`
**Auth:** every request needs `Authorization: Bearer <token>`. The route's required scope (`read_only` or `full`) is enforced before anything else runs, and the validated token becomes the "actor" for audit logging.

Below is the full route catalog (58 registered routes), grouped by purpose.

### 5.1 Health, Discovery & System

| Method | Path | Scope | What it does |
|---|---|---|---|
| GET | `/health` | read | Quick API gateway health check (status, plugin/api version, timestamp). |
| GET | `/capabilities` | read | What this API and *this token* can do (file access, patching, rollback, server execution features). |
| GET | `/manifest` | read | Machine-readable description of the API for agent discovery. |
| GET | `/agent/manifest` | read | Full self-describing manifest: capabilities, security posture, workflow sequence, every route, ~75 error codes, server capability negotiation, version/hash. Never exposes file contents, secrets, or tokens. |
| POST | `/health/verify` | full | Run all 7 health checks (frontend, admin, REST, WPCC API, WooCommerce, plugin/theme integrity) and persist the result. |
| GET | `/health/results` | read | List past health verification results (filter by status, paginated). |
| GET | `/system/environment` | read | Get the current environment mode (development/staging/production). |
| POST | `/system/environment` | full | Set the environment mode. |
| POST | `/system/cleanup` | full | Dry-run or actually delete old terminal-state runtime records, with environment-aware safeguards. |

### 5.2 Context & Site Understanding

| Method | Path | Scope | What it does |
|---|---|---|---|
| GET | `/context` | read | One-call composite bundle: site info, diagnostics, server capabilities, file access map. Secrets redacted. |
| GET | `/agent/context` | read | Metadata-only runtime context (optionally scoped to a session). Never includes file contents. Secrets redacted. |
| GET | `/agent/timeline` | read | Unified, filterable timeline of everything that's happened (by session/task/action/plan/patch). |
| GET | `/agent/tree` | read | The hierarchical runtime tree: Session → Task → Action → Plan → Patch. |
| GET | `/site-intelligence` | read | Full Site Intelligence snapshot (see §6). |

### 5.3 Diagnostics & Recommendations

| Method | Path | Scope | What it does |
|---|---|---|---|
| GET | `/diagnostics?type=performance\|security\|woocommerce` | read | Run one diagnostics category and return its findings. |
| GET | `/diagnostics/debug-log?lines=N` | read | Tail of `wp-content/debug.log`, secrets redacted. |
| GET | `/recommendations` | read | List recommendations (filters: type, severity, status, source). |
| GET | `/recommendations/{id}` | read | Get one recommendation. |
| POST | `/recommendations/scan` | full | Run the deterministic recommendation scan (read-only analysis; creates/updates recommendation records only). |
| POST | `/recommendations/{id}/dismiss` | full | Dismiss an open recommendation. |
| POST | `/recommendations/{id}/resolve` | full | Mark a recommendation resolved. |
| POST | `/recommendations/{id}/convert-to-action` | full | Turn a recommendation into a proposed Action. |
| POST | `/recommendations/{id}/create-plan` | full | Create a pending-review Plan from a converted recommendation. |

### 5.4 File Access & Code Search

| Method | Path | Scope | What it does |
|---|---|---|---|
| GET | `/files?path=` | read | List files/folders under themes, plugins, or mu-plugins. |
| GET | `/files/meta?path=` | read | Size, modified time, SHA-1 hash, writability for one file. |
| GET | `/files/content?path=` | read | File contents (capped at 1MB), secrets redacted. |
| GET | `/search?q=&path=&type=text\|function\|class\|hook` | read | Search code by text, function name, class name, or WordPress hook usage. |

### 5.5 Agent Runtime — Sessions, Tasks, Actions, Plans

| Method | Path | Scope | What it does |
|---|---|---|---|
| POST / GET `/agent/sessions`, GET `/agent/sessions/{id}`, POST `/agent/sessions/{id}/close` | mixed | Open, list, view, and close agent sessions (24h default expiry). |
| POST / GET `/agent/tasks`, GET `/agent/tasks/{id}`, POST `/agent/tasks/{id}/status` | mixed | Create, list, view, and update the status of tasks within a session. |
| POST / GET `/agent/actions`, GET `/agent/actions/{id}`, POST `/agent/actions/{id}/accept\|reject\|cancel\|complete` | mixed | Record, list, view, and transition lightweight "I investigated / recommend / will change X" actions. |
| POST / GET `/agent/plans`, GET `/agent/plans/{id}`, POST `/agent/plans/{id}/approve\|reject\|cancel` | mixed | Create, list, view, and approve/reject multi-step plans. |

(Full per-route detail is in §9.)

### 5.6 Patches (Core Feature)

| Method | Path | Scope | What it does |
|---|---|---|---|
| GET | `/patches` | read | List all patches (summary). |
| POST | `/patches` | full | Propose a patch (auto-generates a diff). |
| GET | `/patches/{id}` | read | Full patch record: diffs, status history, verification results. |
| POST | `/patches/{id}/approve` | full | Approve a draft/pending patch. |
| POST | `/patches/{id}/reject` | full | Reject a draft/pending/approved patch. |
| POST | `/patches/{id}/apply` | full | Apply an approved patch (auto-snapshot → write → syntax-check → auto-revert on failure). |
| POST | `/patches/{id}/rollback` | full | Restore the pre-patch file(s) from snapshot, with hash verification. |

### 5.7 Operations, Requests, Queue & Results

| Method | Path | Scope | What it does |
|---|---|---|---|
| GET | `/operations`, `/operations/{id}` | read | List/describe the 8 supported operation types. |
| POST | `/operations/{operation_id}/run` (8 operations) | full | Run an operation directly (still subject to its own approval/risk model). |
| POST / GET `/operations/requests`, GET `/operations/requests/{id}` | mixed | Create/list/view operation requests awaiting human review. |
| POST | `/operations/requests/{id}/approve\|reject\|execute\|queue` | full | Approve, reject, directly execute, or queue an approved request. |
| GET | `/operations/queue`, `/operations/queue/{id}` | read | List/inspect the background job queue. |
| POST | `/operations/queue/{id}/run\|cancel\|retry` | full | Manually run, cancel, or retry a queued job. |
| POST | `/operations/queue/process` | full | Manually trigger the background worker to process a batch. |
| GET | `/operations/results`, `/operations/results/{id}` | read | List/inspect execution results. |

(Full per-operation detail is in §11.)

---

## 6. Site Intelligence (`SiteScanner.php`) — "Layer 1: The Facts"

A single cached scan (1-hour transient, force-refreshable) that gives a complete, read-only snapshot of the install. Everything else in the plugin — diagnostics, recommendations, the dashboard, the agent context bundle — builds on this.

It collects:

- **WordPress:** version, site/home URLs, multisite status, locale, timezone, permalink structure, SSL status
- **PHP:** version, memory limit, max execution time, upload/post size limits, loaded vs. missing extensions (curl, mbstring, gd, imagick, zip, xml, json, mysqli, opcache, intl)
- **Theme:** name, version, author, template/stylesheet, child-theme + parent-theme info
- **Plugins:** every active plugin (name, version, author, file path), including network-activated
- **WooCommerce:** active?, version, currency, base location
- **Cache:** persistent object cache?, `object-cache.php`/`advanced-cache.php` drop-ins, OPcache status, detected caching plugins (WP Rocket, W3TC, WP Super Cache, LiteSpeed, Autoptimize, SG Optimizer, WP Fastest Cache)
- **Server:** software string, OS, whether `shell_exec`/`proc_open` are available, whether WP-CLI is reachable, list of disabled PHP functions
- **Debug:** `WP_DEBUG`/`WP_DEBUG_LOG`/`WP_DEBUG_DISPLAY`/`SCRIPT_DEBUG` flags, debug.log existence + size
- **File permissions:** exists / octal permissions / writable for `wp-config.php`, `.htaccess`, `wp-content`, `uploads`, `plugins`, `themes`

---

## 7. Diagnostics (`includes/Diagnostics/`) — "Layer 2: The Verdicts"

Diagnostics take the SiteScanner snapshot and turn raw facts into **verdicts** (`good` / `recommended` / `critical` / `info`) with human-readable explanations. They never change anything.

### 7.1 Performance Diagnostics (8 checks)

| Check | What it looks at | Verdict logic |
|---|---|---|
| Memory limit | `memory_limit` PHP setting | Good ≥128MB, Recommended 64–128MB, Critical <64MB |
| Current memory usage | `memory_get_usage()` / peak | Informational |
| External object cache | Redis/Memcached active? | Good if active, else Recommended |
| OPcache | `opcache_get_status()` / ini setting | Good if enabled, else Recommended |
| Page cache | drop-in or known caching plugin detected | Good if detected, else Recommended |
| Autoloaded options size | sum of `autoload='yes'` option sizes | Good <800KB, Recommended 800KB–2MB, Critical >2MB |
| Active plugin count | count of active plugins | Info, or Recommended if >50 |
| WP-Cron status | `DISABLE_WP_CRON` constant | Informational |

### 7.2 Security Diagnostics (7 checks)

| Check | What it looks at | Verdict logic |
|---|---|---|
| Debug display | `WP_DEBUG_DISPLAY` | Critical if on (leaks paths/code to visitors), Good if off |
| File editor | `DISALLOW_FILE_EDIT` | Good if disabled, Recommended if enabled |
| `wp-config.php` permissions | file perms, world-writable check | Critical if world-writable, else Good |
| SSL/HTTPS | `is_ssl()` | Good if on, Recommended if off |
| Default "admin" account | user with login `admin` | Good if none, Recommended if it's an administrator |
| Directory listing protection | `index.php`/`index.html` in uploads | Good if present, Recommended if missing |
| WordPress core updates | `get_core_updates()` | Good if up to date, Recommended if an update is available |

### 7.3 WooCommerce Diagnostics (4 checks, only if WooCommerce is active)

| Check | What it looks at | Verdict logic |
|---|---|---|
| DB version | `woocommerce_db_version` vs `WC_VERSION` | Good if matched, Recommended if mismatched (pending DB update) |
| Payment gateways | enabled gateways | Critical if **none** (customers can't check out), Good if any enabled |
| Scheduled actions | Action Scheduler failed/pending counts | Good if no failures, Recommended if failures exist |
| Template overrides | theme's `woocommerce/` template files vs core versions | Good if none, Recommended if outdated overrides found |

### 7.4 Debug Log Viewer (`DebugLogViewer.php`)

Read-only tail of `wp-content/debug.log` (up to 5MB from the end, classified per line as fatal/warning/deprecated/notice/other), plus a "clear log" action. Feeds both the `/diagnostics/debug-log` endpoint and the Recommendation Engine's "recent errors" rule.

---

## 8. The Recommendation Engine (`RecommendationEngine.php`) — "Layer 3: What To Do About It"

This is the first piece of the spec's "AI Diagnostics" vision — **but built as a deterministic rule engine, not an LLM call.** It takes everything from Diagnostics (and a bit more) and turns "critical/recommended" findings into persisted, trackable **recommendations**.

### 8.1 What a scan does

`POST /recommendations/scan`:
1. Runs Security, Performance, and WooCommerce diagnostics.
2. Checks the operation queue/results for failures.
3. Checks server capabilities (WP-CLI, shell_exec/proc_open).
4. Checks `debug.log` for recent fatal/error/warning lines.
5. Applies the rule set below to each finding.
6. **Upserts** each candidate: creates new recommendations, updates existing *open* ones if details changed, leaves alone any that are already past `open` (so a scan never "downgrades" something a human is actively working on), and ignores resolved/dismissed ones.
7. Returns counts: generated / created / updated / unchanged.

### 8.2 The rule set (deterministic, idempotent)

- **Security:** `WP_DEBUG_DISPLAY` on → high; writable `wp-config.php` → critical; no SSL → medium; file editor enabled → medium; default admin account → medium
- **Performance:** no page cache → medium; no persistent object cache → low; OPcache off → medium; >50 active plugins → low; autoloaded options >800KB → medium, >2MB → high
- **WooCommerce** (if active): no payment gateways → critical; failing scheduled actions → high; DB version mismatch → high; outdated template overrides → medium/info
- **Operations:** failed queue items exist → medium (high if ≥5); retryable failed items → medium
- **Developer experience:** WP-CLI unavailable → info; shell_exec/proc_open disabled → info; recent debug.log errors → medium (high if ≥10)

### 8.3 Recommendation lifecycle (7 states)

```
open ──► converted_to_action ──► plan_created ──► approved ──► executing ──► resolved
  │                                                                              ▲
  └──────────────────────────► dismissed                resolved ◄─────────────┘
```

- **`open`** — newly found or updated, awaiting a decision.
- **`converted_to_action`** (`POST /recommendations/{id}/convert-to-action`) — turned into an Agent Action (status `proposed`).
- **`plan_created`** (`POST /recommendations/{id}/create-plan`) — a Plan was created from that action (requires the recommendation to already be `converted_to_action`).
- **`approved`** / **`executing`** / **`resolved`** — these **sync automatically** as the linked plan/operation/patch progresses through its own lifecycle. This sync is one-directional and passive: nothing here auto-approves anything, and a *failed* execution does **not** mark a recommendation resolved (so a failed attempt never falsely says "fixed").
- **`dismissed`** — a human decided this doesn't need fixing (only from `open`).

This is the on-ramp from "the system noticed something" to "a human approved a fix" to "it's done" — without ever skipping the approval gate.

---

## 9. The Agent Runtime Hierarchy

Everything an AI agent does is organized into a strict hierarchy, so a human can audit "what has this agent been doing?" at any level of granularity:

```
Session ──► Task ──► Action (lightweight findings/recommendations — metadata only)
                 └──► Plan ──► Plan Steps
                            └──► Patch (the actual file change)
```

### 9.1 Sessions (`wpcc_agent_sessions`)

A session represents one block of agent work. `source` is `claude` / `codex` / `gpt` / `api` / `manual`. Status: `active` → `closed` or `expired` (lazy 24-hour expiry, checked whenever sessions are read/listed/closed). **Why:** every other record can hang off a session, giving a "who/when" anchor for a whole conversation's worth of work.

### 9.2 Tasks (`wpcc_agent_tasks`)

A task belongs to a session and records the actual `user_prompt` — what was asked. Status: `draft → analyzing → patch_proposed → completed | failed | cancelled`. **Why:** sessions don't capture *intent*; tasks do, and give plans/patches something meaningful to attach to.

### 9.3 Actions (`wpcc_agent_actions`)

A lightweight, **metadata-only** record of something the agent investigated, found, or proposed — `type` is one of `investigate`, `recommendation`, `diagnosis`, `code_change`, `configuration_change`, `maintenance`. Status: `proposed → accepted | rejected | cancelled | completed`. **Why:** not every finding deserves a full multi-step Plan — Actions let an agent externalize "I looked at X and found Y" for human review without committing to executing anything.

### 9.4 Plans (`wpcc_agent_plans` + `wpcc_agent_plan_steps`)

A plan is a titled objective with an ordered list of steps, optionally linked to an Action. Status: `draft → pending_review → approved | rejected | cancelled` (`superseded` is defined but currently unused — reserved for future use). Creation requires the task to belong to the session, and is transactional (plan + all steps, or nothing). **Why:** this is the human approval gate *one level above* individual patches — a human reviews the overall *approach* before any diffs exist.

### 9.5 Connection to Patches

`POST /patches` accepts an optional `plan_id`. If supplied, **the plan must already be `approved`** — any other status (including `pending_review`) is rejected. `session_id`/`task_id` are inherited from the plan automatically. This is the moment plan-approval becomes load-bearing: a patch can only be tied to a plan a human has already signed off on.

---

## 10. The Patch Engine (`includes/PatchSystem/`) — The Core Feature

### 10.1 State machine

```
draft / pending_approval ──► approved ──► applied ──► rolled_back
        │                        │
        └──► rejected            └──► failed (auto-revert, never reaches "applied")
```

- **`draft` / `pending_approval`** — proposed, awaiting human review (a patch is created in `pending_approval`).
- **`approved`** — a human signed off; ready to apply.
- **`applied`** — live on disk.
- **`rolled_back`** — was applied, then reverted.
- **`rejected`** — declined, never applied.
- **`failed`** — an `apply` attempt failed its safety checks; the file was **never left in a broken state**.

### 10.2 What's in a patch record

- Per-file: original content, modified content, and a unified diff (for human review — not used to apply the change)
- `explanation` (sanitized free text)
- `source`: `claude` / `codex` / `manual` / `api`
- `risk_level`: `low` / `medium` / `high` (supplied by whoever proposes the patch)
- Optional `session_id` / `task_id` / `plan_id` for traceability
- `snapshot_ids` — file path → snapshot, recorded automatically at apply time
- `verification` — syntax-check results per file
- `status_history` — every status change with a timestamp

Stored as JSON files in `wp-content/uploads/wpcc-patches/` (the source of truth) with a lightweight index row in `wpcc_patches` for fast listing/filtering.

### 10.3 The full lifecycle

**Create** (`POST /patches`):
1. `PathGuard` validates every target file path.
2. Reads each file's *current* content as the "original."
3. Generates a unified diff per file (display only).
4. Validates: file size ≤2MB, at least one file actually changes.
5. If `plan_id` is given, validates the plan is `approved` and inherits session/task from it.
6. Writes the JSON record + DB index row, logs `patch.created`. Status starts at `pending_approval`.

**Approve / Reject** (`POST /patches/{id}/approve|reject`):
- Simple status transition + audit log entry. Only valid from `draft`/`pending_approval` (and `approved` for reject).

**Apply** (`POST /patches/{id}/apply`) — the safety-critical step:
1. Re-validate status is `approved`.
2. **Drift check:** re-read each target file from disk and compare to the "original" stored when the patch was created. If the file changed since then, **reject** — the patch must be regenerated against current content.
3. **Snapshot phase:** before writing anything, snapshot every affected file (see §11) and record the snapshot IDs on the patch.
4. **Write phase:** write new content to each file (atomic, `LOCK_EX`).
5. **Verification phase:** for each PHP file, run `php -l` (syntax check) via `shell_exec` (skipped gracefully if `shell_exec` is unavailable).
6. **If verification fails:** immediately restore the pre-write content from memory, mark the patch `failed` with the verification details, log `patch.failed`. **The file is never left broken.**
7. **If verification passes:** mark `applied`, record `applied_at` and the snapshot IDs, log `patch.applied`.

**Rollback** (`POST /patches/{id}/rollback`):
- Iterates the patch's snapshots and restores each one through the verified Rollback process (§11). **All** files must verify successfully for the patch to be marked `rolled_back` — if even one fails verification, the patch stays `applied` and returns `wpcc_rollback_verification_failed` (even though the files that *could* be restored, were — see §11 for why this is still safe).

---

## 11. Rollback & Snapshot Engine (`includes/Rollback/`)

### 11.1 Snapshots (`SnapshotManager.php`)

Creating a snapshot: read the file → write it verbatim to `wp-content/uploads/wpcc-snapshots/{uuid}.snapshot` → compute an MD5 hash → record `{snapshot_id, file_path, hash, size, label, patch_id?}` in `wpcc_snapshots`. No caching — always fresh, always immediate.

### 11.2 Verified rollback (`RollbackManager.php`) — three stages

1. **Pre-check (snapshot integrity):** read the stored snapshot file, hash it, compare to the hash recorded when it was created. If it doesn't match — the snapshot itself is corrupt — **stop, don't touch the live file**, return an error.
2. **Safety backup:** before restoring, take a **new snapshot of the file's current (post-patch) contents**, labeled "Automatic backup before restoring snapshot from [date]." This means the rollback itself can be undone.
3. **Post-check (restore verification):** write the snapshot's content to the live file (atomic, clears PHP's stat cache), read it back, hash it, and confirm it matches the original snapshot's hash.

The result record includes `verified` (true only if **both** checks pass), the individual `checks`, and the `safety_snapshot` that was taken. This three-stage design means rollback is never a "fingers crossed" operation — every step is independently checkable, and you can always roll back the rollback.

---

## 12. Operations Framework (`includes/Operations/`)

While the **Patch Engine** handles arbitrary file edits, **Operations** are pre-built, narrowly-scoped "do a specific WordPress thing" actions — content seeding, WooCommerce products, media imports, search & replace, plugin updates, and a tightly whitelisted WP-CLI bridge. Every operation goes through the **same approval pipeline** as everything else.

### 12.1 The 8 registered operations

| Operation | Risk | Approval | What it does | Notable constraints |
|---|---|---|---|---|
| `content_seed` | medium | yes | Creates draft/published posts or pages from a title pattern + content template | post/page only, max 100 per request |
| `acf_seed` | medium | yes | Populates Advanced Custom Fields on an existing post | only if ACF active; only text/textarea/wysiwyg/url/number/select/true_false field types |
| `cf7_seed` | low | yes | Generates a Contact Form 7 form from a template (`contact_basic`, `newsletter`, `quote_request`) | only if CF7 active |
| `woo_product_seed` | medium | yes | Creates a simple WooCommerce product (name, SKU, price, stock, categories) | only if WooCommerce active; simple products only |
| `media_import` | medium | yes | Downloads an image/PDF from a URL into the Media Library | HTTPS only, ≤10MB, jpg/png/gif/webp/pdf only, MIME-validated after download |
| `safe_search_replace` | high | yes | Database search & replace across chosen tables, dry-run by default | tables must use the WP DB prefix, serialized-data-safe, no regex, no cross-prefix/multisite |
| `safe_updates` | high | yes | Updates one plugin or theme, dry-run by default, with a post-update health check | one plugin/theme at a time; no WP core updates, no bulk, no DB migration rollback |
| `wp_cli_bridge` | high | yes | Runs one of 6 **whitelisted** WP-CLI commands (`plugin_list`, `theme_list`, `cache_flush`, `cron_event_list`, `option_get_siteurl`, `db_size_check`) | only available if `shell_exec`+`proc_open`+WP-CLI all present; **no arbitrary commands ever** |

`includes/Operations/DatabaseExport.php` exists as a placeholder (`not_implemented`) for a future DB export operation.

### 12.2 The pipeline: Request → Approve → Queue → Execute → Result

```
POST /operations/requests          (status: pending_review)
        │
        ▼ approve / reject
   approved ──► auto-enqueued ──► OperationQueue (status: queued)
        │                              │
        │                       run manually, or
        │                       OperationWorker (WP-Cron, every ~5 min)
        │                              │
        │                              ▼
        │                          running ──► completed / failed
        │                                          │
        └──────────► OperationResults ◄────────────┘
```

- **`OperationManager`** owns *requests* — the approval layer. `pending_review → approved/rejected → executed/failed/cancelled`. Approving a request automatically enqueues it.
- **`OperationQueue`** owns the job queue: `queued → running → completed/failed/cancelled`, with `attempts`/`max_attempts`. Enqueuing the same request twice returns the existing item (no duplicates).
- **`OperationExecutor`** is the dispatcher: resolves the right handler class (e.g. `safe_search_replace` → `SearchReplace`), runs it, normalizes the result (`created_ids`/`updated`/`skipped`/errors), and audits before/after.
- **`OperationWorker`** runs via WP-Cron roughly every 5 minutes, claims up to a batch limit (default 5, max 20) of queued items using a 5-minute lock (so overlapping cron runs can't double-execute a job — critical for things like search & replace), and processes them.
- **`OperationResults`** persists every execution's outcome: status, timing, created/updated/skipped/error counts, and full result/error JSON.

A **retry engine** (`POST /operations/queue/{id}/retry`) lets a `failed` item be retried (and only `failed` items — not running/completed/cancelled), respecting `max_attempts` and preserving the previous error for context.

Every request/queue/result record can carry `session_id`/`task_id`/`action_id`/`plan_id`, so an operation triggered by an approved Plan shows up in that session's timeline end-to-end.

### 12.3 What each operation actually does

- **ContentSeed** — creates `post`/`page` entries with `{n}`-templated titles and `wp_kses_post()`-sanitized content, status `draft` or `publish`.
- **AcfSeed** — writes ACF field values to an existing post, with type-aware sanitization (numbers coerced, URLs escaped, WYSIWYG through `wp_kses_post`), respecting `edit_post` capability.
- **Cf7Seed** — creates a fully-functional Contact Form 7 form (fields + mail config) from one of three templates.
- **WooProductSeed** — creates a simple WooCommerce product via the native `WC_Product_Simple` API, validating SKU uniqueness and auto-creating any new categories.
- **MediaImport** — downloads via `download_url()`/`media_handle_sideload()`, validates MIME type post-download, sets title/alt/caption, optionally attaches to a post.
- **SafeUpdates** — fetches available plugin/theme updates; if `dry_run` (default), reports before/after versions only; if live, runs the WordPress Upgrader API then a loopback HTTP health check on the homepage — if that returns 500+, the result flags "rollback recommended."
- **SearchReplace** — finds matching rows in chosen tables (LIKE-based), correctly handles PHP-serialized values (unserialize → replace → re-serialize), and either reports counts (`dry_run`) or performs the replacement.
- **WpCliBridge** — runs one of the 6 whitelisted commands with a 30-second timeout and 1MB output cap, decoding JSON output where applicable.

---

## 13. Health Verification Engine (`includes/Health/HealthVerificationEngine.php`)

A standalone, **read-only**, persisted-history health check — the generalized version of the ad-hoc check originally built for `safe_updates`.

### 13.1 The 7 checks

| Check | Verifies |
|---|---|
| Frontend health | Loopback request to the homepage returns HTTP 200–399 |
| wp-admin health | Loopback request to `/wp-admin/` returns HTTP 200–399 |
| REST API health | Loopback request to the WordPress REST root returns 200–399 |
| WPCC API health | Loopback request to `/wp-json/wp-command-center/v1/health` returns 200–399 (401/403 also count as "pass" — the gateway itself is alive) |
| WooCommerce health | If active, DB schema version matches code version |
| Plugin integrity | Every active plugin's main file is readable |
| Theme integrity | Active (and parent, if child theme) `style.css` exists and is readable |

### 13.2 Persistence & Timeline

Each run is stored in `wpcc_health_verifications` with overall `status` (`passed`/`warning`/`failed`), the full per-check results, and a summary count — accessible via `GET /health/results`. `health.verification.started/completed/failed` audit events fire around each run. **Why this matters:** a human reviewing a patch can see "health was green before, green after" — turning "did that change break anything?" into a checkable fact, not a guess.

---

## 14. System Management (`includes/System/`)

### 14.1 Environment Manager

Three modes — **development / staging / production** — read from a stored option (falling back to WordPress's own `wp_get_environment_type()`, defaulting to `production` if undetectable). The mode gates how cautious the **Cleanup Manager** is, and is shown as a banner on the dashboard so an admin always knows which "mode" the site is in.

### 14.2 Cleanup Manager (`POST /system/cleanup`)

Lets an operator prune **terminal-state** rows that accumulate over time:

| Resource | Terminal statuses |
|---|---|
| Sessions | `closed`, `expired` |
| Tasks | `completed`, `failed`, `cancelled` |
| Actions | `rejected`, `completed`, `cancelled` |
| Plans | `rejected`, `superseded`, `cancelled` |
| Queue items | `completed`, `failed`, `cancelled` |
| Recommendations | `resolved`, `dismissed` |

Safety model — **guardrails scale with risk**:
- `dry_run=true` (default) — counts what *would* be deleted, deletes nothing, no confirmation needed.
- Live cleanup in development/staging requires `confirm: "CLEANUP"`.
- Live cleanup in **production** additionally requires `allow_production: true` **and** `confirm: "DELETE PRODUCTION DATA"`.

Age threshold (`older_than_days`, 1–3650, default 30) and which resource types to target are both configurable.

---

## 15. Admin Dashboard (`includes/Admin/`)

A "Command Center" top-level menu (Administrators only) with 7 pages. The UI is **strictly a control surface for the approval gates that already exist via the API** — there's no direct file editing, AI chat, or automatic execution anywhere in it.

### 15.1 Dashboard

The mission control screen:
- **Environment banner** (development/staging/production, color-coded)
- **14 overview cards**: active sessions, open tasks, proposed actions, pending plans, pending operation requests, queued operations, applied patches, failed queue items, plus 5 recommendation-status cards (open/critical/awaiting plan/awaiting approval/in progress/resolved)
- **Runtime hierarchy visualization**: a left-to-right flow diagram — Sessions → Tasks → Actions → Plans → Requests → Queue → Results — with live counts at each stage
- **Pending-review panels** with inline Approve/Reject for plans and operation requests, and "Run Manually" for queued items
- **Safe Search & Replace panel**: pick tables (with presets and a risk badge that updates live), run a dry preview, and — for live runs — a confirmation modal showing exactly what will be affected before the request is created
- **Timeline panel**: filterable by type/status, paginated (10 at a time)

### 15.2 Site Intelligence

A read-only, nicely tabulated view of everything `SiteScanner` collects (§6) — WordPress/PHP/theme/plugins/WooCommerce/cache/server/debug/file-permissions — with a "Refresh Scan" button and Yes/No badges for booleans.

### 15.3 Diagnostics

Tabbed view: **Performance / Security / WooCommerce / Debug Log**. Each diagnostics tab is a table of check name, status badge (Good/Recommended/Critical/Info), and details. The Debug Log tab lets you choose how many lines to view and includes a "Clear Log" action.

### 15.4 File Access

A breadcrumb-navigable file browser scoped to themes/plugins/mu-plugins, with a code search box. Viewing a file shows syntax-highlighted content (truncated at 1MB) and a **"Create Patch for This File"** shortcut straight into the Patches page.

### 15.5 Patches

The patch management center: a list of all patches with status badges, and a detail view showing metadata, verification results (✅/❌ per file), color-coded unified diffs (green additions / red deletions), and context-appropriate action buttons (Approve / Reject / Apply / Roll Back / Delete). Includes a manual "Create Patch" form (load a file, edit it, write an explanation, pick a risk level).

### 15.6 Rollback

A history view of every patch that's been **applied, rolled back, or failed**, with a one-click **Restore** (with confirmation) that runs the verified rollback process from §11.

### 15.7 Settings

- **API token management**: create tokens (label, scope, expiry), see masked previews, revoke/delete — with the full raw token shown exactly once at creation.
- **AI Agent Connections reference**: base URL, a ready-to-use `curl` example, and a full endpoint reference table.
- **Access control summary**: the rules from §4 spelled out for a human reader (admin-only UI, token-only API, scope differences, "revoke immediately if compromised").

---

## 16. Database Schema Reference (v2.2.0)

| Table | Purpose | Key fields |
|---|---|---|
| `wpcc_patches` | Patch index | `patch_id`, `session_id`/`task_id`/`plan_id`, `source`, `risk_level`, `status`, `target_files`, timestamps |
| `wpcc_snapshots` | File backup index | `snapshot_id`, `patch_id`, `file_path`, `backup_path`, `hash`, `size` |
| `wpcc_agent_sessions` | Agent sessions | `session_id`, `source`, `label`, `status`, `expires_at` |
| `wpcc_agent_tasks` | Agent tasks | `task_id`, `session_id`, `user_prompt`, `status` |
| `wpcc_agent_actions` | Agent actions | `action_id`, `session_id`/`task_id`, `type`, `status` |
| `wpcc_agent_plans` | Agent plans | `plan_id`, `session_id`/`task_id`/`action_id`, `title`, `objective`, `status` |
| `wpcc_agent_plan_steps` | Plan steps | `plan_id`, `step_order`, `title`, `description`, `status` |
| `wpcc_operation_requests` | Operation approval requests | `request_id`, `operation_id`, relationship IDs, `payload`, `risk_level`, `status` |
| `wpcc_operation_queue` | Background job queue | `queue_id`, `request_id`, `operation_id`, `status`, `priority`, `attempts`/`max_attempts`, `payload`/`result` |
| `wpcc_operation_results` | Execution results | `result_id`, `queue_id`/`request_id`, `operation_id`, `status`, counts, `result_json`/`error_json` |
| `wpcc_recommendations` | Recommendations | `recommendation_id`, `type`, `severity`, `status`, `action_id`/`plan_id`, `context_json` |
| `wpcc_health_verifications` | Health check history | `verification_id`, `status`, `checks_json`, `summary_json` |

All bulk content (diffs, file contents, snapshots, status histories) lives in JSON/snapshot files on disk, as described in §3.3.

---

## 17. Testing & Validation

25 test suites, 718 assertions, all passing as of the Step 36 real-site validation:

| Suite | Validates |
|---|---|
| `test-patch-lifecycle.sh` | Full patch create → approve → apply → rollback cycle + audit/timeline |
| `test-e2e-runtime.sh` | Session → Task → Plan → Approval → Patch → Apply → Rollback, end to end |
| `test-security-redaction.sh` | File blocking, secret redaction, audit logging |
| `test-agent-manifest.sh` | `/agent/manifest` capabilities, workflow, error catalog |
| `test-agent-actions.sh` | Action types, status transitions, audit |
| `test-agent-review.sh` | Session→Task→Action→Plan→Patch human-review visibility |
| `test-agent-timeline.sh` | Unified timeline correctness |
| `test-recommendations.sh` / `test-recommendation-workflow.sh` | Recommendation engine + full lifecycle/sync |
| `test-operations-registry.sh` | Operation discovery & availability |
| `test-operation-requests.sh` | Request create/approve/reject/execute |
| `test-operation-worker.sh` | WP-Cron worker, locking, batch limits |
| `test-operation-retry.sh` | Retry engine guards & limits |
| `test-content-seed.sh`, `test-acf-seed.sh`, `test-cf7-seed.sh`, `test-woo-product-seed.sh`, `test-media-import.sh` | Each seed/import operation |
| `test-safe-search-replace.sh` | Dry-run/live search & replace, serialization safety |
| `test-safe-updates.sh` | Plugin/theme update dry-run + health check |
| `test-wp-cli-bridge.sh` | WP-CLI command whitelist, timeout, redaction |
| `test-health-verification.sh` | All 7 health checks + persisted history |
| `test-cleanup.sh` | Environment modes + guarded cleanup |
| `test-admin-ux.sh` | Dashboard structure, filters, pagination, empty states |
| `test-real-site-validation.sh` | The full Step 36 end-to-end run on a live WP+WooCommerce+ACF+CF7 site |

**Step 36 real-site result:** diagnostics returned 8 performance / 7 security / 4 WooCommerce findings; a recommendation scan evaluated 14 findings; health verification returned 7/7 passed with 0 warnings/failures; the full regression suite passed 718/0.

---

## 18. The Build Journey — What Was Added, Step by Step, and Why

> Steps 13–23 aren't individually documented in the project's handoff notes — by Step 24 the **Operations framework** (request → approval → queue → manual run → results) already existed, so that core pipeline was built during this gap.

| Step | What was added | Why |
|---|---|---|
| **1** | Re-verified the existing foundation (REST health/capabilities/context/manifest, full patch lifecycle, snapshot hashes, audit log) | Before building the agent runtime on top, the team needed certainty the Patch Engine — the product's central safety mechanism — was solid |
| **2** | Agent Sessions (`wpcc_agent_sessions`) | The first "who/when" anchor for everything an agent does |
| **3** | Agent Tasks (`wpcc_agent_tasks`) | Captures *what was asked*, not just *who's working* |
| **4** | Linked patches to sessions/tasks (`session_id`/`task_id` on `wpcc_patches`) | Connects the new runtime hierarchy to the existing Patch Engine without breaking old patches (nullable, backward-compatible) |
| **5** | `GET /agent/context` | One call returning "everything the agent needs to know" — metadata only, never file contents |
| **6** | Agent Plans (`wpcc_agent_plans` + steps) | Lets a human review an agent's *overall approach* before any patch exists |
| **7A** | Plan approval state machine + `/agent/plans/{id}/approve\|reject\|cancel` | The human-in-the-loop gate one level above patch approval |
| **8** | `plan_id` on patches, requiring the plan be `approved` | Closes the loop: a patch can only execute under a plan a human already approved |
| **9** | `test-e2e-runtime.sh` (49 assertions) | Proved the *whole chain* (session→task→plan→patch→apply→rollback) works together, especially that rollback doesn't sever relationship tracking |
| **10** | Extended `PathGuard` deny rules + new `Redactor` | Closed the gap where "allowed" directories (plugins/mu-plugins) commonly contain `.env` files and credentials |
| **11** | `GET /agent/manifest` (self-describing API) | Lets any agent discover capabilities/security posture/routes/error codes with zero pre-configuration |
| **12** | Agent Actions layer (`wpcc_agent_actions`) | A lighter-weight unit than a Plan — "I investigated X and found Y" without committing to execution |
| **13–23** | *(Operations framework built — not individually documented)* | Established Request → Approval → Queue → Manual Run → Results |
| **24** | Operation retry engine (`/operations/queue/{id}/retry`) | Failed operations were dead ends; this lets transient failures be retried within `max_attempts`, with strict state guards |
| **25** | Background worker via WP-Cron (`OperationWorker`) | Automates Queue → Execute using only WordPress-native cron, with locking to prevent double-execution |
| **26** | `safe_search_replace` operation | First high-risk DB-mutation operation: dry-run by default, table-prefix-restricted, serialization-safe |
| **27** | `media_import` operation | A lower-risk operation to exercise the full pipeline end-to-end; strict allow-list (HTTPS, size, MIME type) |
| **28** | `safe_updates` operation | Plugin/theme update with dry-run + post-update loopback health check |
| **29** | `wp_cli_bridge` operation | WP-CLI access via a 6-command whitelist only — never arbitrary shell |
| **30** | First Agent Runtime Dashboard | Made all the approval gates (sessions/tasks/actions/plans/operations) usable by a non-technical admin, not just via raw API calls |
| **30A** | Search & Replace review UI | Made the Step 26 operation usable from the dashboard — dry runs are instant, live runs still go through approval |
| **31** | Recommendation Engine (deterministic rules) | First step toward "AI Diagnostics" — but rule-based, not LLM-based, keeping it explainable and trustworthy |
| **32** | Recommendation Workflow Engine (full lifecycle + auto-sync to plan/operation/patch status) | A recommendation now tracks all the way to resolution automatically, without manual ID-correlation |
| **33** | Health Verification Engine (7 checks, persisted history) | Generalized the ad-hoc Step 28 health check into a standalone, reusable, historical service |
| **34** | Environment Manager + Cleanup Manager | Lets operators prune accumulated terminal-state records, with guardrails that scale with environment risk |
| **35** | Admin UX Polish | Consolidated the dashboard (grown organically since Step 30) into a coherent, navigable whole — no new scope |
| **36** | Real Site Validation | Proved all 35 prior steps compose correctly on a live WordPress + WooCommerce + ACF + CF7 site; found and fixed a bug where queue/result audit events were missing relationship IDs, which made a session's timeline silently skip the execution phase |

### Cross-cutting patterns worth noticing

- **Every capability follows the same shape**: Request → Validation → (Approval if risky) → Queue/Execute → Audit + Timeline → Agent Context → Manifest → Tests.
- **Dry-run-by-default** for anything that mutates data.
- **Strict, no-silent-no-op state machines** everywhere.
- **Each step explicitly states what it does *not* do** — a disciplined incremental process that kept the "no AI chat / no MCP / no automatic execution" boundaries intact across 36 steps, so that when AI integration *does* arrive (Phase 6), it plugs into an already-safe, already-audited foundation rather than needing its own bespoke safety layer.

---

## 19. What You Can Do With This Plugin Today (V1 Beta)

- Connect an AI agent (or any script) to a WordPress site via a scoped, revocable API token.
- Give that agent a complete, redacted picture of the site: WordPress/PHP/server config, active plugins/theme, WooCommerce status, performance/security/WooCommerce diagnostics, and a deterministic list of recommended fixes.
- Let the agent search and read code (themes/plugins/mu-plugins only, secrets redacted) without shell access.
- Have the agent propose file changes as **patches** — diffed, explained, risk-rated — that a human reviews and approves before anything touches disk, with automatic per-file snapshots and verified rollback.
- Have the agent (or an admin) run pre-built operations — seed content/products/forms/media, safely search & replace in the database, update a plugin/theme with a health check, or run a whitelisted WP-CLI command — all behind the same request/approve/queue/execute pipeline.
- Track all of the above through a Session → Task → Action → Plan → Patch hierarchy, with a unified, filterable timeline and full audit trail.
- Run on-demand health verification (7 checks) with persisted history, and clean up old runtime data with environment-aware safeguards.
- See and control all of it from a single "Command Center" dashboard in wp-admin.

## 20. What's Not Built Yet (Phase 6)

Per the original roadmap, deliberately **not yet** part of this plugin:

- Direct Claude / Codex / GPT integration (this plugin is the *gateway* an agent connects *to* — it doesn't run an agent itself)
- MCP (Model Context Protocol) support
- Fully autonomous agents (no approval step)
- Multi-site management
- A SaaS/agency layer

`resume.md` (the "Claude Handoff" doc used to onboard a new session) is currently stale — last verified at Step 12 — and should be refreshed before being relied on for onboarding into Phase 6 work.
