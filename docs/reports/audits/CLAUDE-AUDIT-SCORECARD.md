# WP Command Center — Audit Scorecard

Companion to `CLAUDE-AUDIT-REPORT.md` and `CLAUDE-AUDIT-CHANGES.md`. Scores are 1–10, where 10 means "no notes, ship it as-is" and 1 means "fundamentally broken." Each score is independent — a low Public Beta Readiness score does not imply low Architecture quality, and vice versa. References to "Finding X-N" point to the severity-classified findings in `CLAUDE-AUDIT-REPORT.md` §9.

| Dimension | Score |
|---|---|
| Architecture | 8 / 10 |
| Security | 6 / 10 |
| Performance | 7 / 10 |
| Maintainability | 7 / 10 |
| Extensibility | 7 / 10 |
| MCP Implementation | 7 / 10 |
| Documentation | 6 / 10 |
| Test Coverage | 7 / 10 |
| Public Beta Readiness | 5 / 10 |
| Commercial Readiness | 4 / 10 |

---

## Architecture — 8 / 10

All 27 operation families follow the same `Registry` (declarative metadata) + `RuntimeManager` (WordPress/WooCommerce/ACF API calls) + `OperationExecutor` (generic capability check → approval check → dispatch → audit → result) shape, with no exceptions found. The MCP layer is a genuine thin adapter over this same pipeline — `tools/call` dispatches to the identical `OperationExecutor::run()` used by REST, so there is no parallel business logic to drift. The patch/rollback subsystem is a real, conservative state machine (snapshot → write → verify → rollback-with-re-verification, refusing to claim success if post-rollback verification fails).

Points withheld for: the gap between the architecture's *stated* model (Capability Runtime → Approval Runtime → Queue Runtime) and its *default* behavior (the Approval Runtime stage is a no-op unless a hidden option is set — Finding S-1), and the existence of three independent authorization layers (token scope, capability map, approval gate) that don't share a model and have different default coverage (Finding S-3). The structure is sound; its documented guarantees and its default configuration have drifted apart.

---

## Security — 6 / 10

The individual security primitives are well-built: `AuthTokens` uses HMAC-SHA256 + `hash_equals()`, show-once raw tokens, scope/expiry/revocation; `PathGuard` does allow-list-then-deny-list path resolution via `realpath()` with no traversal issues found; `Redactor::redact_recursive()` is applied to MCP outputs; admin pages are uniformly `manage_options`-gated with consistent escaping and nonces; the audit log is append-only and `.htaccess`-protected. In isolation, several of these components would score 8–9.

The score is 6, not 8–9, because of how these primitives compose: (1) **Finding S-1** — `wpcc_enforce_approval` defaults to `false`, has no admin UI, and isn't set on activation, so 18/27 operations marked `requires_approval: true` execute immediately by default; (2) **Finding S-2** — MCP `tools/call` for `content_seed`/`acf_seed`/`cf7_seed`/`woo_product_seed`/`queue-run` performs no scope check and (being absent from `OPERATION_MAP`) no capability check either, so a `read_only` token can create real content via MCP despite being blocked from the equivalent REST route; (3) **Finding S-3** — the three authorization layers (scope, capability, approval) have different defaults and different coverage depending on entry point, making the *effective* security posture hard to reason about even for someone who has read all the code. None of these are "found a SQL injection" severity, but they are exactly the kind of composition gaps that a security review of a tool whose entire purpose is "let an AI agent change my WordPress site" should not have.

---

## Performance — 7 / 10

No duplicate-query, N+1, or unbounded-scan patterns were found in the runtime managers sampled (ACF, WooCommerce, Forms, Search, Comments) — they call WordPress/WooCommerce APIs directly. Payload sizes are bounded sensibly (`FileAccessApi::MAX_READ_BYTES = 1MB`, binary-file sniffing). The MCP server handled a 30-call rapid-fire `initialize` stress test with 0 failures.

The deduction is entirely **Finding P-1**: `AuditLog::record()` appends at least 3 lines per operation (often more via MCP), forever, with no rotation; `AuditLog::tail()` loads the *entire file* via `file()` on every `/agent/timeline` read. This is invisible in testing (small log files) and will become a real "why did this get slow" issue on an actively-used production site within months, not years.

---

## Maintainability — 7 / 10

27 operation families average a healthy 300–520 lines per runtime manager, PSR-4 autoloading is clean, and the Registry/RuntimeManager/Executor separation makes "where does X live" predictable across the entire codebase — this audit's own registry-completion work (§5–7 of `CLAUDE-AUDIT-CHANGES.md`) was straightforward precisely because the pattern is so consistent.

Two things hold this back from 8+: **Finding C-1** — `RestApi.php` at 4,171 lines / 95 routes is a single-class outlier that doesn't follow the discipline visible everywhere else, and will become a merge-conflict and onboarding hot spot as the team grows. And the **recurring stale-hardcoded-test-spec pattern** — found and fixed twice in this audit alone (`test-capability-runtime.sh` in an earlier segment, `test-agent-manifest.sh` this segment) — means every future operation/capability addition has a good chance of silently breaking 1–2 unrelated test suites until someone notices and manually updates a literal JSON blob.

---

## Extensibility — 7 / 10

The "add a new operation" path is well-trodden and was exercised (in completed form) during this audit for `comments_manage`/`widgets_manage`/`cpt_manage`: write a `RuntimeManager` + companion `*Registry` for per-action metadata, add an entry to `OperationRegistry::get_operations()`, and the operation is automatically picked up by `/agent/manifest`'s operations array, MCP `tools/list` (schema derived from declared parameters), and the REST `/operations/{id}/run` route. This is good — there's one real "spine" to extend.

It's not 8+ because that spine has **multiple semi-independent attachment points that must be kept in sync by hand**: `OperationRegistry` (operation definition), `CapabilityRegistry::OPERATION_MAP` (capability requirement — and remember, *absence* here means "unrestricted," a fail-open default that's easy to forget for a new operation, directly causing Finding S-2 for 5 operations), `RestApi.php`'s manifest `capabilities` dict (the human-readable capability flag), and whichever `EXPECTED_*` test literals exist. Nothing *enforces* that these stay consistent — they're kept in sync by developer diligence, and this audit found two instances where they hadn't been.

---

## MCP Implementation — 7 / 10

The protocol surface is complete and correct: `initialize`, `resources/list` (7 resources)/`resources/read`, `tools/list` (27 tools with JSON-Schema `inputSchema` derived from operation parameter declarations)/`tools/call`, `prompts/list` (6 prompts). `manifest_hash` is a stable sha256 across repeated calls (verified). Redaction is applied to resource reads and tool-call results. Critically, `tools/call` is a thin pass-through to the same `OperationExecutor` the REST layer uses — there is no separate MCP business logic to audit for drift.

The deduction is **Finding S-2**, and it's MCP-*specific*: `McpRestApi::require_read()` validates token *validity* but never checks `scope`, which means MCP is the only entry point where the REST layer's `read_only`/`full` scope distinction is silently dropped for the 5 unmapped operations. An MCP client presenting a `read_only` token gets *more* effective access than the same token would get via REST. There is also currently zero test coverage exercising this specific scenario — the gap wasn't just present, it was untested.

---

## Documentation — 6 / 10

Self-documentation is genuinely strong: `/agent/manifest` exposes a 60+-code error catalog, a full endpoint catalog with method/path/scope/description for all 102 callbacks, an ordered 9-step workflow model, and a deterministic manifest hash for change detection — this is the kind of thing that makes a platform actually usable by an AI agent without a human reading prose docs first. There's also substantial human-facing documentation: 30+ `STEP-*.md` validation reports, `AGENTS.md`, `CONNECTING.md`, a canonical spec document.

The score is held to 6 by an **accuracy** problem, not a *volume* problem: (1) `/agent/manifest`'s `security.human_approval_required: true` reads as an unconditional guarantee but is actually gated by the hidden, default-off `wpcc_enforce_approval` option (Finding S-1) — a careful reader of `/claude/discovery`'s `approval.enforcement` field *can* discover the truth, but the headline manifest claim is misleading on its own; (2) the AI Client Certification documentation/registry (Finding PR-1) claims Gold-level certification for 10 third-party AI tools with what is visibly a single bulk edit behind it, directly contradicted by the plugin's own `tests/test-ai-client-layer.sh`. Documentation that contradicts the product's own shipped test suite is a worse problem than missing documentation.

---

## Test Coverage — 7 / 10

2,779 assertions across 56 suites, with dedicated or end-to-end coverage for all 27 operations, 116 assertions on the patch lifecycle alone, 35 on security/redaction, and a 263-assertion final-validation suite that exercises every operation end-to-end. The suite caught the `acf_field_create` bug that this audit fixed (a function that has likely never worked, caught by a 2-assertion failure in a 44-assertion suite — exactly what good test coverage should do).

It's not higher than 7 because of: (1) **26 remaining failures**, both root causes documented but neither trivial (PR-1 requires a product decision; T-1 is a test-architecture issue with shared global timeline state); (2) **zero coverage for `wpcc_enforce_approval=true`** — the one site-wide safety switch this audit flagged as off-by-default-and-undocumented (S-1) has no test verifying it works correctly *when* enabled; (3) **zero coverage for the MCP scope-bypass scenario** (S-2) — the gap this audit found by reading code, not by a failing test; (4) the **recurring jq-truthiness and stale-fixture-ID anti-patterns** found and fixed in `test-woocommerce-runtime.sh` this audit suggest other untouched suites likely contain the same latent issues, masking either false failures or false passes.

---

## Public Beta Readiness — 5 / 10

The core single-operator/single-agent loop (connect via MCP or REST, run any of 27 operations, get audited results, roll back via the patch lifecycle) works end-to-end today and is the kind of thing a technical beta audience would find genuinely useful within minutes of connecting Claude Desktop or another MCP client.

It's a 5, not a 7+, because of **two specific, easily-triggered first-impression problems**: (1) the plugin ships its own test suite, and running `tests/test-ai-client-layer.sh` against a fresh install produces 12 failures with messages like "expected 'planned', got 'gold'" for half the listed AI clients — a beta user who runs the tests (a very likely thing for a technical beta user to do) gets an immediate signal that the AI-client integration claims don't match reality (Finding PR-1); (2) `/agent/manifest` asserts `human_approval_required: true` while, by default, an AI agent can execute most mutating operations with zero approval step (Finding S-1) — a beta user who *read the manifest and configured their trust accordingly* would be operating under a false assumption. Both are fixable without architectural change; neither is currently fixed.

---

## Commercial Readiness — 4 / 10

The underlying engineering — 27 working operation families, a real patch/rollback state machine, sound token auth and path security, comprehensive self-documenting manifest — represents a genuinely sellable amount of real functionality. This is not a "needs a rewrite" situation.

It's a 4 because the gaps that block public beta (PR-1, S-1) are **more damaging in a paid context**, plus one additional gap that specifically matters once money is involved: (1) **PR-1** — if a customer is paying partly for "works great with Cursor/Windsurf/Aider/Codex/etc.," and the certification data for those integrations is a visibly bulk-copy-pasted "Gold," that's not a bug, it's a representation the customer paid for that isn't true — this is the single highest-priority item before any commercial claims are made; (2) **S-1** — "human approval required" is exactly the kind of safety feature a customer evaluating whether to let an AI agent touch their production site would pay a premium for, and it currently isn't true by default; (3) **S-2** — a paying customer's security review (which becomes much more likely once money changes hands) will find the MCP scope bypass within an hour of testing token scopes, the same way this audit did. None of these require new architecture — they require a product decision (PR-1), a default/UI/doc change (S-1), and a small, well-scoped fix (S-2) — but none of them are done yet, and all three are the type of finding that erodes trust disproportionately once a customer has paid for the product.
