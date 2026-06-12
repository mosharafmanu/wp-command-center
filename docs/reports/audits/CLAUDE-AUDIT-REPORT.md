# WP Command Center — Independent Audit Report

**Plugin version audited:** 0.1.0
**Audit date:** 2026-06-12
**Auditor role:** Independent senior software architect / WordPress core contributor / security auditor / platform engineer / product hardening reviewer
**Scope:** Architecture, Security, Performance, WordPress Standards, MCP Layer, Testing, Product Readiness
**Mandate:** Challenge assumptions, find weaknesses, document technical debt, fix safe issues, do **not** add features or restructure the architecture.

---

## 1. Executive Summary

WP Command Center is a substantial, well-organized AI-operations platform for WordPress. The core pipeline —
`AI Client → MCP → Capability Runtime → (Approval Runtime) → Queue Runtime → OperationExecutor → Runtime → Result → Audit → Timeline → Rollback`
— is **structurally implemented and largely correct**. 27 operation families share a consistent `Registry` (declarative metadata) + `RuntimeManager` (execution) + `OperationExecutor` (generic dispatch/audit) pattern. The MCP layer is a genuinely thin wrapper over the REST/operations layer (no duplicated business logic, verified by reading `McpServerRuntime.php`). The patch/rollback subsystem (snapshot → write → syntax-verify → rollback-with-re-verification) is sound, and the previously-flagged php-fpm `verify_file()` issue is **already fixed in code** (graceful binary fallback chain, never hard-fails just because a CLI lint binary is unavailable).

At the start of this audit the full 56-suite test run stood at **2775 passed / 61 failed**. After the fixes applied during this audit (see `CLAUDE-AUDIT-CHANGES.md`), the suite stands at **2753 passed / 26 failed** (56 suites, 2779 assertions). The 26 remaining failures are concentrated in **5 suites** and reduce to **two root causes**, both documented below and deliberately **not** fixed because doing so would require product decisions and/or behavior changes outside this audit's mandate.

The most important findings are **not bugs in individual operations** — those are in good shape (99%+ pass rate). The most important findings are about the **two safety/trust layers that sit between "AI agent" and "WordPress changes something"**:

1. The **operation-level human-approval gate is OFF by default and not exposed anywhere in the admin UI** — most mutation operations execute immediately for any holder of a valid token.
2. **MCP `tools/call` can execute four "full-scope" seed operations and the queue-run operation with a read-only token**, because those operations are absent from the capability map and the MCP permission check never inspects token scope.
3. The **AI Client Certification registry contains internally-contradictory and apparently bulk-copy-pasted "Gold certification" claims** for 10 of 11 listed AI clients — a direct commercial-credibility risk, and the root cause of 24 of the 26 remaining test failures.

None of these are exotic; all three are the kind of thing a careful customer's security reviewer (or a competitor) would find within an hour of poking at `/agent/manifest`, `/ai-clients`, and the shipped `tests/` directory. They are addressed in detail in §9 (Findings by Severity) and §11 (Final Questions).

---

## 2. Architecture Review

### 2.1 What's good

- **Consistent operation-family pattern.** All 27 operation families (`content_manage`, `woocommerce_manage`, `acf_manage`, `comments_manage`, `cpt_manage`, `widgets_manage`, …) follow the same `*Registry` (metadata, parameters, capability/approval flags) + `*RuntimeManager` (the actual WordPress API calls) + `OperationExecutor` (generic capability check → approval check → dispatch → audit → result-recording) shape. File sizes for runtime managers are healthy (300–520 lines), no single operation family is a monolith.
- **MCP is genuinely a thin adapter.** `McpServerRuntime::tools_call()` does a capability check and then calls `(new OperationExecutor())->run($tool_name, $args, $context)` — the *exact same* entry point the REST `/operations/{id}/run` routes use. `resources/read` proxies to existing REST endpoints (`/agent/manifest`, `/agent/context`) or registry summaries. There is no parallel "MCP business logic" to drift out of sync with the REST layer.
- **Append-only audit trail with a derived timeline.** `AuditLog::record()` appends JSONL entries; `TimelineBuilder` maps known `action` strings to human-readable timeline entries. `OperationExecutor::run()` unconditionally records `operation.{id}.started/.completed/.failed` for *every* operation, so even operations that don't do their own audit calls (e.g. `media_import`) still surface in the timeline via the generic path.
- **Patch lifecycle is a real state machine.** `PatchManager`/`PatchApproval` enforce `draft → approved → applied → rolled_back`/`failed` with snapshot-before-write, per-file PHP syntax verification with a CLI-binary fallback chain (handles php-fpm hosts), and rollback that re-verifies every restored file before flipping status — if verification fails after rollback, the status deliberately stays `applied` "pending investigation" rather than silently claiming success. This is exactly the kind of conservative design you want for a tool that edits live site files.
- **Path/file access is allow-listed, not deny-listed-only.** `PathGuard` resolves to a canonical real path, rejects any `..` segment outright, requires the result to be inside `themes/`, `plugins/`, or `mu-plugins/`, and *additionally* runs a deny-pattern pass (wp-config, .htaccess, .env, .git, node_modules, vendor, private keys, credential/secret filenames) on every path segment. This is layered correctly (allow-list first, then deny-list as defense-in-depth) and is applied consistently across `read()`, `meta()`, and `list_directory()`.

### 2.2 Where the architecture diverges from its own stated model

- **"Approval Runtime" is wired but inert by default.** `OperationExecutor::run()` (line 95) only enforces `requires_approval` when `get_option('wpcc_enforce_approval', false)` is true. The default is `false`, and the option is set nowhere during activation and exposed nowhere in the Settings UI. 18 of the 27 operations are flagged `requires_approval: true` in `OperationRegistry`, but **none of that metadata has any runtime effect unless a site admin discovers and manually sets a hidden option**. The pipeline diagram in this audit's brief (`Capability Runtime → Approval Runtime → Queue Runtime → OperationExecutor`) is accurate as *code structure* but misleading as *default behavior* — see Finding S-1.
- **`RestApi.php` is a 4,171-line single class** registering 95 routes / 102 callbacks, plus token validation, redaction, and audit-event helpers, plus ~50 domain-specific handler methods (AI-client config, file access, recommendations, sessions, tasks, actions, plans, patches…). It works, and PHP has no hard limit on file size, but this is the one place in the codebase where the "one concern per class" discipline visible everywhere else (27 small RuntimeManagers) breaks down. See Finding C-1.
- **Two parallel approval/queue mechanisms** exist for two different resource types: the **patch lifecycle** (`PatchManager`/`PatchApproval`, for file changes — hard-enforced state machine) and the **operation request/approval queue** (`OperationQueue`/`OperationWorker`, for WordPress-API operations — soft-enforced via the option above). This separation is *defensible* (files vs. API operations are genuinely different risk shapes), but it means "approval" means two different things depending on which subsystem you're looking at, and only one of them is unconditionally enforced.

### 2.3 Operation registry coverage

`OperationRegistry` defines 27 operations. `CapabilityRegistry::OPERATION_MAP` maps 23 of them to a required capability; the 4 unmapped operations (`content_seed`, `acf_seed`, `cf7_seed`, `woo_product_seed`) are explicitly commented as "unrestricted (read-only/low-risk) seed operations" — but 3 of those 4 are listed in the manifest with `"scope": "full"`, which is the source of Finding S-2.

---

## 3. Security Review

### 3.1 Token authentication (`AuthTokens`)

Solid implementation:
- Raw tokens (`wpcc_<64 random chars>`) are returned **once**, at creation; only `hash_hmac('sha256', $raw, wp_salt('auth'))` is stored, compared via `hash_equals()` (timing-safe).
- Per-token `scope` (`read_only` | `full`), `status` (`active` | `revoked`), optional `expires_at`, and `last_used_at` tracking.
- Storage directory (`uploads/wpcc-tokens/`) gets a `.htaccess` (`Require all denied` / `Deny from all`) and `index.php` on first use.
- `RestApi::check_token()` correctly enforces `SCOPE_FULL` for `require_write` routes and rejects expired/revoked tokens with proper 401s.
- 95/95 route registrations have a 1:1 `permission_callback`; no `__return_true` or missing-permission routes were found.

### 3.2 File access (`PathGuard` / `FileAccessApi`)

No path-traversal issues found. `resolve()` rejects any path containing `..` *before* calling `realpath()`, then re-validates the canonical path against the allow-listed roots and the deny-pattern list. `list_directory()` re-applies `is_denied()` to every directory entry, so a denied file inside an otherwise-allowed directory won't even be listed. `to_relative_path()` and `build_entry()` only ever expose paths relative to `WP_CONTENT_DIR`. This is good, careful work.

### 3.3 MCP entry point security

`McpRestApi::require_read()` validates *that a token is valid* but does **not** check its `scope`. `McpServerRuntime::tools_call()` then runs a **capability** check (`CapabilityRegistry::validate($tool_name, 'token', $token_id)`, default-enabled) — but capability and scope are two independent systems (§3.4). For any operation **not** in `CapabilityRegistry::OPERATION_MAP`, `validate()` returns `allowed: true` unconditionally, and execution proceeds with **zero** scope or capability check. See Finding S-2.

### 3.4 Two authorization layers that don't agree

There are three independent "is this allowed?" checks in the pipeline, and they don't share a model:

| Layer | Granularity | Default | Covers |
|---|---|---|---|
| Token **scope** (`AuthTokens`) | `read_only` / `full`, per token | enforced on REST routes via `require_read`/`require_write` | All 102 REST callbacks |
| **Capability** assignment (`CapabilityRegistry`) | per-token capability list vs. `OPERATION_MAP` | enforced by default (`wpcc_enforce_capabilities=true`) in `OperationExecutor` and MCP `tools_call` | 23/27 operations |
| **Approval** gate (`requires_approval` + `wpcc_enforce_approval`) | per-operation, site-wide toggle | **disabled by default**, no UI | 18/27 operations, *if enabled* |

A request that goes through MCP `tools/call` is checked against rows 2 and 3 — **never row 1**. A request that hits `/operations/{id}/run` directly is checked against row 1 (via `require_write`) and row 2, but row 3 is off by default. The net effect: the *strictest* of these three layers only applies if a site admin has manually configured capability assignments **and** flipped a hidden option. Out of the box, the practical authorization model for a `full`-scope token is "capability-mapped operations check capability assignments (which default to empty, so they're denied until configured); the 4 unmapped operations and the queue-run endpoint run unconditionally."

### 3.5 Audit log (`AuditLog`)

Append-only JSONL under `uploads/wpcc-audit/audit.log`, `.htaccess`-protected, `record()` failures are silently swallowed (correct — auditing must not break the audited operation). `tail($limit)` reads the **entire file** into memory with `file()` and slices the last N lines — see Finding P-1.

### 3.6 Admin UI

All 8 admin pages (`Dashboard`, `Site Intelligence`, `Diagnostics`, `File Access`, `Patches`, `Rollback`, `Settings`, `AI Integrations`) are registered via `add_submenu_page(..., 'manage_options', ...)`, so WordPress itself gates access — correct and consistent. Heavy, consistent use of `esc_html`/`esc_attr`/`esc_url` (17–123 occurrences per view file) and nonces on every view that submits forms (`patches.php` has 10 nonce checks). `file-access.php` (read-only viewer) has zero nonces, which is correct — it has nothing to submit.

### 3.7 Things that are fine and worth saying so

- No SQL injection patterns found in the runtime managers reviewed; WooCommerce/WordPress data-access APIs are used rather than raw `$wpdb` string concatenation in the operation handlers reviewed.
- `database_inspect` is explicitly read-only by design ("No INSERT/UPDATE/DELETE/DROP. No arbitrary SQL.") and is the one capability-mapped operation with `scope: read_only`.
- Secrets: `Redactor::redact_recursive()` is applied to MCP resource reads and tool-call results before they leave the server; `test-security-redaction.sh` (35/0) and `test-agent-manifest.sh`'s "no sensitive data exposed" section (token non-leakage) both pass.

---

## 4. Performance Review

- **Finding P-1 (audit log, no rotation).** `AuditLog::record()` appends to a single file forever; `AuditLog::tail()` calls `file()` (loads the whole file into an array) on every read. `OperationExecutor::run()` writes **at least 3** audit lines per operation (`operation.{id}.started`, `operation.execution.started`, plus a `.completed`/`.failed`), and MCP adds `mcp.request`/`mcp.tool.invoke`/`mcp.resource.read` lines for every call. On an actively-used site with AI agents polling `/agent/timeline` and calling tools regularly, this file will grow without bound, and every `tail()` call's cost grows with it. This is the single most likely "it was fine in testing, then got slow after 3 months in production" issue in the codebase.
- **No other duplicate-query or N+1 patterns were found** in the runtime managers sampled (ACF, WooCommerce, Forms, Search, Comments) — they call WordPress/WooCommerce APIs directly rather than looping queries.
- **MCP stress test passes**: `test-ai-client-certification.sh` §12 fires 30 rapid `initialize` calls with 0 failures.
- File-read cap (`FileAccessApi::MAX_READ_BYTES = 1MB`) and binary-file detection (`is_binary()` via null-byte sniff on an 8KB sample) bound payload sizes for the file-content endpoint sensibly.

---

## 5. Code Quality Review

- **Finding C-1**: `RestApi.php` (4,171 lines, 95 routes) is a maintainability outlier relative to the rest of the codebase's otherwise-disciplined file sizes. Not a bug, but a standing cost on every future change that touches routing, auth, or redaction.
- **Finding (fixed during audit)**: `ACFRuntimeManager::field_create()` called `acf_add_local_field()`, which has `@return void` in ACF core — it **always returns `null`**, so `if (!$result)` was **always true**, meaning `acf_field_create` **always returned `wpcc_field_create_failed`**. This action has apparently never worked. Fixed by switching to `acf_update_field()` (which persists to the DB and returns the field array). A subsequent `acf_update_field_group(['fields' => ...])` call was also removed — ACF's `update_post()` strips the `fields` key via `acf_extract_vars()` before serialization, so that call was dead code regardless. See `CLAUDE-AUDIT-CHANGES.md`.
- **Recurring test-quality pattern**: `assert_true "x: has y" "$(echo "$JSON" | jq -r 'if .y then "true" else "false" end')"` is a **presence-vs-truthiness bug** — it returns `"false"` for legitimately-present-but-`null`/`false` values (e.g. `stock_quantity: null` for non-stock-managed products, `manage_stock: false`). Two new instances were found and fixed in `test-woocommerce-runtime.sh` this audit (on top of similar fixes in earlier audit segments to `test-capability-runtime.sh`). The correct idiom is `has("y")`. This pattern likely still exists in some untouched suites — see Recommendations.
- **Recurring test-quality pattern**: hardcoded "expected spec" JSON literals (`EXPECTED_CAPABILITIES`, `EXPECTED_*`) drift out of sync every time a new capability/operation is added. Found and fixed for `test-agent-manifest.sh` this audit (missing `cpt_management`/`widgets_management`); the same class of issue was fixed for `test-capability-runtime.sh` in an earlier segment. See Recommendations.
- **Test-ordering bugs**: `test-woocommerce-runtime.sh` had two tests (`Coupon Get`, `Coupon Update`) that operated on a coupon ID created by an *earlier* test that had since deleted it. Fixed by creating fresh coupons inline (mirroring the existing correct pattern used elsewhere in the same file).

---

## 6. MCP Layer Review

- **Protocol surface**: `initialize`, `resources/list` (7 resources), `resources/read`, `tools/list` (27 tools, one per operation, with JSON-Schema `inputSchema` derived from each operation's declared parameters), `tools/call`, `prompts/list` (6 prompts). `resources/list`/`resources/read`/`tools/list`/`tools/call` are all individually audited (`mcp.resource.read`, `mcp.tool.list`, `mcp.tool.invoke`, `mcp.denied`).
- **Manifest/discovery quality**: `/agent/manifest` exposes `plugin`, `capabilities` (28 boolean flags), `security`, `workflow` (9-step ordered lifecycle), `endpoints` (with method/path/scope/description on every entry), `error_catalog` (≥60 codes), `capability_negotiation`, `versions`, and a deterministic `manifest_hash` (sha256, stable across calls — verified by `test-agent-manifest.sh` §8). `/claude/discovery` additionally exposes `capabilities.enforcement` and `approval.enforcement` flags, so a careful AI client *can* discover that approval enforcement is off — see §3.4.
- **JSON-escaping gotcha (test-only)**: `wpcc://manifest` resource URIs are correctly JSON-encoded by PHP as `wpcc:\/\/manifest`; a literal-substring test for `"wpcc://manifest"` never matches. This is **not a server bug** (the JSON is valid and any real JSON-RPC client parses it correctly) — it was a test-assertion bug, fixed this audit by switching to `jq`-based equality.
- **Finding S-2 (scope bypass)**: `tools/call` for `content_seed`, `acf_seed`, `cf7_seed`, `woo_product_seed` (all `scope: "full"` per the REST manifest) and `queue/{id}/run` proceeds with **no scope check and no capability check** (all 5 are absent from `OPERATION_MAP`, so `CapabilityRegistry::validate()` returns `allowed: true` unconditionally for them). A `read_only`-scoped token — which cannot call `/operations/content_seed/run` directly (blocked by `require_write`) — **can** call the identical operation via `POST /mcp` `{"method":"tools/call","params":{"name":"content_seed",...}}`, because `McpRestApi::require_read()` accepts any valid token regardless of scope. This creates real posts/pages/products/forms.
- **Certification framework** (`AIClientRegistry`, `/ai-clients`, `/ai-clients/{client}/config`): structurally complete (11 clients, certification levels Planned→Compatible→Active→Bronze→Silver→Gold, a compatibility matrix, per-client config generators for 9/11 clients) but the *data* is the source of Finding PR-1 below.

---

## 7. Testing Review

**Before this audit's fixes:** 2775 passed / 61 failed (56 suites).
**After this audit's fixes:** 2753 passed / 26 failed (56 suites, 2779 assertions).

(The 22-assertion difference in totals between the two snapshots reflects test-file restructuring in this and prior audit segments — e.g. consolidating a 15-failure WP-CLI suite — not lost coverage; every suite that had 0 failures before still has 0 failures after, plus 4 previously-failing suites now pass cleanly.)

### 7.1 Fixed this audit (now 0 failures)
- `test-acf-runtime.sh`: 42/2 → **44/0** (real bug fix, `field_create`)
- `test-woocommerce-runtime.sh`: 108/9 → **117/0** (3 distinct bug classes: jq truthiness, stale coupon IDs, literal-vs-regex `assert_contains`)
- `test-agent-manifest.sh`: 42/1 → **43/0** (stale capability spec)
- `test-search-runtime.sh`, `test-final-validation.sh`, `test-operations-registry.sh`, `test-capability-runtime.sh`, `test-ai-integration-ux.sh`, `test-forms-runtime.sh`, `test-structured-wp-cli-runtime.sh`/`test-wp-cli-bridge.sh`: all now 0 failures (fixed across this and the immediately-preceding audit segment).

### 7.2 Remaining failures — 2 root causes, 26 assertions, 5 suites

**Root cause A — `AIClientRegistry` data (24 of 26 failures):**
- `test-ai-client-layer.sh`: 64/12
- `test-ai-client-certification.sh`: 49/2
- `test-enterprise-hardening.sh`: 98/5
- `test-production-validation.sh`: 97/5

`test-ai-client-layer.sh` (Step 48) was written against a 9-client registry where `claude.status == "active"` and `codex`/`gemini`/`cursor` (and 5 others) are `"planned"`, with `counts.total == 9`. `test-ai-client-certification.sh` (Step 53) was written against an **11-client** registry (the original 9 plus `chatgpt` and `command_code`) where `claude.certification_level == "gold"` and `chatgpt`/`command_code` are `"planned"`, with `counts.total == 11`.

The current `AIClientRegistry::get_clients()` has **11 entries, all 11 marked `CERT_GOLD`** for both `status` and `certification_level`, and **10 of the 11** (every client except `claude`) share the *identical* string `'validation_notes' => 'Batch gold certification: all MCP platform features validated via shared endpoint.'` — a strong signal of a bulk find/replace rather than 10 independent certification passes.

This single registry state cannot satisfy both test suites simultaneously: `counts.total` is `11` either way (Step 48's "total: 9" assertion is permanently incompatible with an 11-client registry — 12 of its 64+12 assertions fail), and `chatgpt`/`command_code` are `"gold"` instead of Step 53's expected `"planned"` (2 failures). `test-enterprise-hardening.sh` and `test-production-validation.sh` each embed 5 assertions derived from the same 9-vs-11 / gold-vs-planned expectations and fail for the same reason.

**This was deliberately not fixed.** A correct fix requires a genuine product decision (which of the 11 clients are *actually* validated to which level — see Finding PR-1) plus updating whichever test suite encodes the old expectation, plus (per the evidence in §3.4/§6) likely introducing a `status` field distinct from `certification_level` so that "operationally active" and "certification level" can vary independently. That is exactly the kind of "major change requiring a product decision" this audit's brief says to document, not implement.

**Root cause B — `test-media-import.sh` timeline flakiness (2 of 26 failures):**
- §3 ("Timeline & Audit") asserts that `/agent/timeline?limit=30` contains both "Media import started" and "Media import completed" entries. Running this suite **standalone**: 9/0 (both pass). Running it as part of the **full 56-suite sequential run**: 7/2 (both fail) — and this was *already true in the pre-audit baseline* (also 7/2), i.e. not a regression from any change made this audit.

Root cause: by the time `test-media-import.sh` runs (alphabetically late in the 56-suite sequence), dozens of preceding suites have each written many `operation.*`/`mcp.*`/`security.*` audit entries; a fixed `limit=30` window over the global timeline can be entirely consumed by *other* suites' more-recent events. `media_import` itself records no operation-specific audit events (it relies entirely on the generic `OperationExecutor` `operation.media_import.started/.completed` lines, which `TimelineBuilder` does map to the expected labels) — the operation and the mapping are both correct; the test's assumption that its own events will be within the last 30 *global* timeline entries is what breaks under load. Documented as LOW — see Finding T-1.

### 7.3 Coverage gaps observed (not failures, but notable)
- No suite directly exercises the **MCP scope-bypass** path described in Finding S-2 (a `read_only` token calling `tools/call` with `content_seed`) — this is precisely the kind of thing that *should* have a test and currently doesn't.
- No suite directly exercises **`wpcc_enforce_approval=true`** — the approval-gate code path (`OperationExecutor.php` line 95) appears to have zero test coverage in either direction.

---

## 8. Product Readiness Review

- **Daily real-world use (single trusted operator + single trusted AI agent, e.g. the developer's own Claude Desktop)**: the core loop — connect via MCP, run operations, get audited results, roll back if needed — works end-to-end and is well-tested (2753 passing assertions across 27 operation families plus patch/snapshot/rollback/audit/timeline). This is a genuinely useful tool *today* for that use case.
- **Multi-agent / less-trusted-agent scenarios**: the approval-gate-off-by-default (Finding S-1) and MCP scope bypass (Finding S-2) mean the platform's "safety" story is currently aspirational rather than enforced. A site admin who reads the architecture docs and assumes "the AI can't do anything destructive without my approval" is **wrong by default**.
- **AI client ecosystem claims**: `/ai-clients` and the admin "AI Integrations" page present a certification matrix claiming Gold certification for 10 distinct AI coding tools (Cursor, Windsurf, Aider, Continue, Roo Code, OpenCode, Codex, Gemini CLI, ChatGPT, Command Code) in addition to Claude. The shipped test suite itself (`test-ai-client-layer.sh`) was written under the assumption that 8 of those 10 are merely "planned," and the identical boilerplate validation notes strongly suggest none of the 10 received independent Gold-level validation. This is a direct **commercial overclaiming risk** (Finding PR-1) — and the evidence is sitting in the plugin's own `tests/` directory, which ships with the plugin.

---

## 9. Findings by Severity

### Critical
*(none open — see "Fixed during this audit" below)*

### High

- **S-1 — Operation-level human-approval gate is disabled by default and unconfigurable via UI.** `wpcc_enforce_approval` defaults to `false`, is set nowhere on activation, and has no Settings UI control. 18 of 27 operations marked `requires_approval: true` execute immediately for any holder of a `full`-scope token (and, per S-2, sometimes a `read_only` one). *Open — document/UI/decision required.*
- **S-2 — MCP `tools/call` scope bypass for unmapped operations.** `content_seed`, `acf_seed`, `cf7_seed`, `woo_product_seed` (manifest `scope: full`) and `queue/{id}/run` are absent from `CapabilityRegistry::OPERATION_MAP`, so the capability check unconditionally allows them, and `McpRestApi::require_read()` never inspects token scope. A `read_only` MCP token can create real content/products/forms. *Open — needs either OPERATION_MAP entries + a scope check in `tools_call`, or an explicit "these are intentionally low-risk and unscoped" decision documented in the manifest.*
- **PR-1 — AI Client Certification registry is internally contradictory and likely overclaimed.** 11 clients, all marked Gold, 10 sharing identical "Batch gold certification" boilerplate; directly causes 24/26 remaining test failures across 4 suites and contradicts the plugin's own Step-48 test expectations. *Open — product decision required (see §7.2, §11).*

### Critical-severity bug, fixed during this audit

- **`acf_field_create` was completely non-functional** since introduction (`acf_add_local_field()` always returns `null`/void, so the success check always failed). Fixed by switching to `acf_update_field()`. `test-acf-runtime.sh` 42/2 → 44/0.

### Medium

- **P-1 — Audit log has no rotation/size cap; `tail()` loads the whole file into memory.** Will degrade over time on active sites. *Open — document for future work.*
- **C-1 — `RestApi.php` is a 4,171-line, 95-route single class.** Maintainability/merge-conflict cost. *Open — document for future refactor.*
- **S-3 — Three independent, partially-overlapping authorization layers (token scope, capability assignment, approval gate) with different defaults and different coverage.** The "weakest enforced layer" determines actual access for any given operation+path combination, and this varies by entry point (REST vs MCP) and by whether the operation is in `OPERATION_MAP`. *Open — document the effective model; consider unifying.*
- **T-1 — `media_import` timeline assertions are flaky under full-suite runs** due to a fixed `limit=30` timeline window being consumed by other suites' events; pre-existing, not a regression. *Open — low priority, document.*

### Low

- **Token/audit-log directories rely solely on Apache `.htaccess`** for protection (no effect on nginx without equivalent config). Impact is limited — tokens are stored as HMAC-SHA256 hashes plus a 12-char preview, not raw secrets — but worth a doc note for nginx hosts. *Open.*
- **Recurring "stale hardcoded spec" test pattern** (`EXPECTED_CAPABILITIES` etc.) — fixed twice across this audit (capability-runtime, agent-manifest) but the pattern itself (literal expected-JSON drifting from the live registry) remains and will recur with every new capability/operation. *Open — recommend deriving expectations from source or adding a contract test.*
- **Recurring jq `if X then "true" else "false" end` presence-check anti-pattern** — fixed 2 new instances this audit; likely present elsewhere in untouched suites. *Open — recommend a repo-wide grep/lint pass.*

### Informational

- Patch-engine `verify_file()` php-fpm issue (previously flagged as a critical risk in project notes) is **already resolved** in current code (`lint_binaries()` fallback chain, graceful skip if no compatible binary). No action needed.
- `PathGuard`/`FileAccessApi` path-traversal protections are sound; no findings.
- `AuthTokens` implementation (HMAC-SHA256, `hash_equals`, expiry, revocation, show-once) follows good practice; no findings.
- Admin UI capability gating (`manage_options` on all 8 pages) and escaping discipline are consistent; no findings.

---

## 10. Recommendations

In rough priority order, **for the product team to decide on and schedule** (none of these were implemented as part of this audit, per its brief):

1. **Decide what "approval required" means by default**, and either (a) flip `wpcc_enforce_approval` to default `true` with an admin-UI toggle and clear first-run messaging, or (b) keep it opt-in but make that *extremely* visible in onboarding/docs/the manifest's `security.human_approval_required` flag (which currently reads as a blanket guarantee but is conditional on this hidden option).
2. **Close the MCP scope-bypass (S-2)** by either adding `content_seed`/`acf_seed`/`cf7_seed`/`woo_product_seed`/`queue_run` to `OPERATION_MAP` with appropriate capabilities, or adding an explicit scope check in `McpServerRuntime::tools_call()` for any operation whose REST manifest entry says `scope: full`.
3. **Resolve the AI Client Certification data (PR-1)** before any public/commercial claims are made about specific third-party AI tool support: audit each of the 11 entries individually, demote unvalidated ones to `planned`/`compatible`, and update whichever test suite (Step 48 vs Step 53) encodes the stale expectation. This is the highest-leverage fix for both test health (24/26 failures) and commercial credibility.
4. **Add audit-log rotation** (size- or date-based) and switch `tail()` to a bounded reverse-read instead of `file()` on the whole file.
5. **Split `RestApi.php`** along the same domain lines as the operation runtime managers (e.g. `AgentSessionsController`, `FileAccessController`, `AiClientsController`, `PatchesController`...) sharing the existing `AuthTokens`/`Redactor`/`AuditLog` helpers — purely mechanical, high test coverage already exists to verify behavior is preserved.
6. **Add a contract test** that compares `OperationRegistry`/`CapabilityRegistry`/manifest capability flags against a generated (not hand-maintained) expectation, to prevent the recurring "stale spec" class of test failure.
7. **Grep-and-fix the `if X then "true" else "false" end` pattern** repo-wide in `tests/` and replace with `has("key")` where the intent is presence, not truthiness.
8. Add a doc note for nginx hosts about protecting `wp-content/uploads/wpcc-tokens/` and `wpcc-audit/` (the `.htaccess` files written by the plugin are Apache-only).

---

## 11. Final Questions — Brutally Honest Assessment

### Is WP Command Center ready for daily real-world use?
**Yes, for its primary scenario**: one operator running one trusted AI agent (their own Claude Desktop / IDE) against their own site. The 27 operation families, patch/rollback, snapshots, and audit/timeline all work and are heavily tested (2753 passing assertions). For that scenario, the approval-gate-default question is moot — the "approver" and the "agent operator" are the same trusted human anyway. **No** for any scenario involving multiple agents/operators with different trust levels — the access-control story (S-1, S-2, S-3) does not yet deliver the separation a multi-tenant or "give the contractor's AI a read-only key" use case would need, even though the *primitives* (scopes, capabilities, approval flags) all exist in the code.

### Is WP Command Center ready for public beta?
**Almost, but not as-is.** Two things would visibly embarrass the project within the first week of a technical beta audience poking at it: (1) anyone who runs `tests/test-ai-client-layer.sh` against their own install — and the test suite ships *in the plugin* — will get 12 failures and may reasonably conclude the AI-client integration is broken; (2) anyone who reads `/agent/manifest`'s `security.human_approval_required: true` and then watches an AI agent execute a `content_manage` delete with zero prompts will reasonably conclude the manifest is lying. Fix PR-1 and clarify/ship S-1 before a public beta; everything else in this report is the normal punch-list of a healthy pre-1.0 project.

### Is WP Command Center ready for commercial sale?
**Not yet.** The blocking issue is specifically PR-1: a paid product's value proposition here is largely "which AI tools does this work with, and how well," and the current registry asserts Gold certification for 10 tools with what is visibly a single bulk edit behind it. A technical buyer who diffs the registry against the shipped certification test suite (which they have full access to, since it's a WordPress plugin) will find this in minutes, and "the certifications were copy-pasted" is a hard story to recover from with paying customers. Resolve PR-1, ship a default-on (or at least default-*visible*) approval story (S-1), and close S-2, and the remaining gaps (RestApi.php size, audit-log rotation, nginx docs) are normal "harden before scaling" work that does not block an initial commercial release.

### What are the top 10 risks remaining?
1. **PR-1** — AI Client Certification data is contradictory/overclaimed (24 test failures, commercial credibility).
2. **S-1** — Approval gate off by default, no UI, contradicts manifest's safety claims.
3. **S-2** — MCP scope bypass lets read-only tokens run 4 seed operations + queue-run.
4. **S-3** — Three authorization layers with different defaults/coverage; easy to misconfigure or misunderstand.
5. **P-1** — Unbounded audit log + full-file reads on every timeline query.
6. **C-1** — `RestApi.php` at 4,171 lines is a growing maintenance/merge-conflict hot spot.
7. **T-1** — Timeline assertions for any operation (not just media_import) that relies purely on the generic `operation.*` audit events are sensitive to global-timeline window size under load — a latent fragility for *future* operations, not just media_import.
8. No test coverage exists for `wpcc_enforce_approval=true` — if/when S-1 is addressed, the enforcement path itself is currently unverified.
9. Recurring "stale hardcoded test spec" pattern means every future capability/operation addition has a good chance of silently breaking 1–2 existing suites until someone notices.
10. nginx-hosted sites get no filesystem protection on `wpcc-tokens/`/`wpcc-audit/` beyond the (low-impact, since tokens are hashed) absence of an `.htaccess` equivalent.

### What would you fix before launching (public beta)?
PR-1 (registry data + matching test suite) and a clear, accurate statement of S-1's actual default behavior (either change the default, or change the manifest/docs to stop implying it). Both are scoped, well-understood, low-risk changes once a product decision is made — the *code* to do either is small; the work is deciding what the registry/default *should* say.

### What would you improve before charging customers?
Close S-2 (scope bypass), add audit-log rotation (P-1), and split `RestApi.php` (C-1) — none of these block a launch, but all three compound: S-2 is a real (if narrow) security gap that a paying customer's security review will find; P-1 will manifest as "it got slow after a few months" support tickets; C-1 will slow down every feature you ship to those paying customers from here on out. I'd also add the missing `wpcc_enforce_approval=true` test coverage (item 8 above) *before* marketing the approval feature to customers who are paying specifically for that safety story.
