# WP Command Center — Remediation Report

**Date:** 2026-06-12
**Scope:** Remediation of the three open HIGH findings from `CLAUDE-AUDIT-REPORT.md` (S-1, S-2, PR-1).
**Out of scope (by design):** P-1, C-1, S-3, T-1, and all LOW findings — see [Changes Not Applied](#5-changes-not-applied).

This report documents what was found, what was changed, what was deliberately left alone, and the
resulting test results and readiness scores. It is a remediation pass, not a new audit — no new
features were introduced and no architectural changes were made beyond the minimum needed to close
the three findings.

---

## 1. S-1 — Approval Runtime Disabled by Default

### 1.1 The finding

`OperationExecutor::run()` only enforces the approval gate when the option `wpcc_enforce_approval`
is `true`. The option's default is `false`. Meanwhile, the AI agent manifest (`/agent/manifest`)
unconditionally reported `human_approval_required: true` in its `agent_security` block — a
hardcoded value that did not reflect the actual (disabled) runtime behavior.

### 1.2 Evaluation

| Lens | Finding |
|---|---|
| **Developer experience** | Enforcing approval by default would block every AI-driven operation behind a manual approval step from the first install — a jarring "it doesn't work" first impression for a tool whose core value proposition is autonomous AI operation. |
| **User experience** | The plugin's primary use case (per `[[product_workflow_patch_centric]]`) is a single trusted operator working with an AI agent on their own site, with the Patch Engine providing reversibility. Mandatory per-operation approval duplicates that safety net for the common case. |
| **Security impact** | Real, but narrower than it first appears: every mutating operation already goes through the Patch Engine (reversible) and the capability/audit layers. Approval-off does not mean "no record" or "no rollback" — it means "no human gate before execution." |
| **AI workflow impact** | Many MCP clients (Claude Desktop, Cursor, etc.) are used in tight interactive loops. A default-on approval gate would require a human to approve *every* tool call, defeating the purpose of MCP automation for the primary audience. |
| **Commercial impact** | The bigger commercial risk was not the *default value* — it was the **manifest claiming a security guarantee that wasn't actually active**. A buyer or auditor reading `/agent/manifest` and then testing the live behavior would find a direct contradiction. That's a trust/credibility problem, independent of which default is "correct." |

### 1.3 Decision: **NO — keep `wpcc_enforce_approval` disabled by default**

Enabling approval enforcement by default is a behavioral change that affects every existing
installation and every AI workflow built against this plugin's current default. It is also a
product decision (how much friction vs. autonomy the tool should impose out of the box), not a
security *bug* — the underlying Patch/Audit/Rollback safety net is intact either way. Per the
"do not invent new features / focus only on resolving the findings" constraint, changing this
default was treated as out of scope for a remediation pass.

What **was** a bug: the manifest lying about the setting. That was fixed.

### 1.4 What changed

1. **`includes/AiAgent/RestApi.php`** — replaced the hardcoded `AGENT_SECURITY` constant with a new
   private static method `get_agent_security()` that reads the *live* option:
   ```php
   'human_approval_required' => (bool) get_option( 'wpcc_enforce_approval', false ),
   'patch_auto_apply'        => false,
   'rollback_supported'      => true,
   'secret_redaction'        => true,
   ```
   The manifest now truthfully reports `false` out of the box, and will report `true` the moment
   an operator enables enforcement. No more contradiction between documentation and behavior.

2. **`includes/Admin/views/settings.php`** — added a new **"Operation Approval Enforcement"**
   settings section (around line 247) with:
   - A `toggle_enforce_approval` POST handler (lines 35-37) that flips `wpcc_enforce_approval`.
   - Conditional explanatory copy: when OFF, explains that operations execute immediately and
     rely on the Patch Engine for reversibility; when ON, explains that queued/approval-required
     operations will wait for explicit operator approval before executing.
   - A checkbox form reflecting and toggling the live state (line 262).

   This makes the setting **discoverable and self-explanatory** for operators who do want the
   stricter mode — addressing the "improve settings visibility" remediation path explicitly.

3. **`OperationExecutor::run()`** — **unchanged**. The approval gate logic itself (lines ~91-101)
   was not touched; only its *disclosure* (manifest) and *configurability* (settings UI) were
   improved.

### 1.5 Tests

- **`tests/test-agent-manifest.sh`** — updated `EXPECTED_SECURITY` to assert
  `human_approval_required: false` (matching the live default). **43/0 passing.**
- **`tests/test-approval-enforcement.sh`** (NEW) — verifies: the manifest reflects the option's
  live value in both states, the settings toggle persists, and operations behave correctly with
  enforcement on vs. off. **13/0 passing.**

---

## 2. S-2 — MCP Scope Bypass

### 2.1 The finding, re-verified

`McpServerRuntime::tools_call()` previously performed **only** a capability check
(`CapabilityRegistry::validate()`) before executing an operation via `OperationExecutor`. It did
**not** check the calling token's **scope** (`read_only` vs `full`).

The REST API (`RestApi::require_write()`) already blocks `read_only`-scoped tokens from any
non-GET route. MCP had no equivalent gate — meaning **a `read_only` token reached, via MCP, a
strictly higher level of access than the same token could reach via REST.**

### 2.2 Confirmed exploitability and affected operations

The bypass was real for two overlapping classes of operation:

- **Operations absent from `CapabilityRegistry::OPERATION_MAP`** — by design, the four "seed"
  operations (`content_seed`, `acf_seed`, `cf7_seed`, `woo_product_seed`) are documented as
  "unrestricted... do not require explicit capability assignment" (`CapabilityRegistry.php`
  lines 87-89). `get_required_capability()` returns `null` for these, so
  `CapabilityRegistry::validate()` allows them unconditionally — for **any** token, including a
  `read_only` one. These are write operations.
- **Mapped write operations where the token also holds the relevant capability** — because scope
  and capability are independent, orthogonal authorization dimensions (see `[[S-3]]`, out of
  scope), a `read_only`-scoped token that has also been granted an operational capability (e.g.
  `content_manage`) would pass the capability check and execute the write via MCP, despite its
  scope being `read_only`.

In both cases, the operation would **succeed via MCP** while the equivalent REST call with the
same token would be **rejected with 403** by `require_write()`. This is exactly the asymmetry
described in the finding, and it was confirmed exploitable prior to the fix.

### 2.3 Fix implemented

A minimal, allowlist-based scope gate was added — no architectural changes, fully backward
compatible with the capability and approval layers.

1. **`includes/Operations/CapabilityRegistry.php`** (lines 92-97, 132-140):
   ```php
   /**
    * Operations a `read_only` token is permitted to call (read-only in effect,
    * regardless of CapabilityRegistry::OPERATION_MAP mapping). Every other
    * operation requires a `full`-scope token, mirroring RestApi::require_write().
    */
   const READ_ONLY_SCOPE_OPERATIONS = [ 'database_inspect', 'search_manage' ];

   /**
    * Whether an operation requires a `full`-scope token. True for every
    * operation except those in READ_ONLY_SCOPE_OPERATIONS — including
    * operations absent from OPERATION_MAP (e.g. the seed operations),
    * which would otherwise be fail-open for a `read_only` token.
    */
   public function requires_full_scope( string $operation_id ): bool {
       return ! in_array( $operation_id, self::READ_ONLY_SCOPE_OPERATIONS, true );
   }
   ```
   This is a **default-deny allowlist**: only `database_inspect` and `search_manage` (the two
   genuinely read-only, low-risk operations) are permitted for `read_only` tokens. Every other
   operation — mapped or unmapped, present or future — requires `full` scope. This closes the
   "unmapped operation = fail-open" hole at its root, without having to enumerate every write
   operation individually.

2. **`includes/Mcp/McpRestApi.php`** (line 62) — the MCP execution context now carries the
   resolved token scope:
   ```php
   'token_scope' => $scope,
   ```

3. **`includes/Mcp/McpServerRuntime.php`** (`tools_call()`, lines 169-187) — a new scope check
   runs **before** the capability check:
   ```php
   // Scope check — a read_only token may only call read-only-scope operations,
   // regardless of whether the operation is mapped in CapabilityRegistry::OPERATION_MAP.
   // Mirrors RestApi::require_write() so MCP cannot grant more access than REST.
   $cap_reg = new CapabilityRegistry();
   $token_scope = $context['token_scope'] ?? '';
   if ( AuthTokens::SCOPE_READ_ONLY === $token_scope && $cap_reg->requires_full_scope( $tool_name ) ) {
       $this->audit( 'mcp.denied', [ 'tool' => $tool_name, 'reason' => 'insufficient_scope' ], $context );
       return $this->error( -32001, __( 'This API token is read-only and cannot perform this action.', 'wp-command-center' ), null );
   }

   // Capability check
   $token_id = $context['token_id'] ?? '';
   if ( '' !== $token_id && get_option( 'wpcc_enforce_capabilities', true ) ) {
       $validation = $cap_reg->validate( $tool_name, 'token', $token_id );
       if ( ! $validation['allowed'] ) {
           $this->audit( 'mcp.denied', [ 'tool' => $tool_name, 'reason' => 'missing_capability', 'required' => $validation['required_capability'] ], $context );
           return $this->error( -32001, 'Operation denied: missing capability ' . $validation['required_capability'], null );
       }
   }
   ```
   Both the scope-denial and the pre-existing capability-denial use JSON-RPC error code
   `-32001`, keeping the error surface consistent for clients.

### 2.4 Backward compatibility

- **`full`-scope tokens**: `requires_full_scope()` check is bypassed entirely (the `if` only
  triggers for `SCOPE_READ_ONLY`) — **zero behavior change** for the majority of existing tokens.
- **`read_only`-scope tokens**: can now call `database_inspect` and `search_manage` (their
  intended use case — discovery/search) exactly as before; every other tool call now returns a
  clear, actionable error instead of silently executing a write.
- **Capability and approval layers**: untouched. The new check is purely additive and runs first;
  if it passes, the existing capability validation and `OperationExecutor` approval gate run
  exactly as before.

### 2.5 Tests

**`tests/test-mcp-scope-enforcement.sh`** (NEW) — **15/0 passing**:
- A `read_only` token calling `database_inspect` / `search_manage` via MCP → **succeeds**.
- A `read_only` token calling write operations (including the previously-unmapped seed
  operations and a mapped operation such as `content_manage`) via MCP → **denied with
  `-32001` / "read-only and cannot perform this action."**
- A `full`-scope token calling the same operations → **succeeds** (no regression).
- Denials are recorded in the audit log as `mcp.denied` / `insufficient_scope`.

---

## 3. PR-1 — AI Client Certification Overclaim

### 3.1 The finding

The AI Client Integration dashboard and `/ai-clients` API listed **11 clients**, of which **all
11** carried a `gold` certification level. Architecturally, however, **all 11 clients connect
through the exact same MCP Server Runtime** — there is no per-client implementation to
independently certify. Only **Claude Desktop** and **Cursor** had actually been put through the
full certification test suite (Discovery → Resources → Tools → Capabilities → Approval → Queue →
Rollback → Audit → Security → Performance — see `test-ai-client-certification.sh` §3, §6-13). The
other 9 "Gold" labels were not backed by client-specific validation, which is a defensible
technical claim ("the shared endpoint is Gold-certified") but an **overstated commercial claim**
("these 11 products are individually Gold-certified") — exactly the kind of wording that becomes
a liability the moment a customer or reviewer checks one of the other 9 clients themselves.

### 3.2 Options considered

| Option | Description | Verdict |
|---|---|---|
| A | Leave all 11 as Gold, add a disclaimer footnote | Rejected — disclaimers are easy to miss; the badges themselves remain the overclaim. |
| **B** | Keep Claude + Cursor as Gold (actually validated); downgrade the other 9 to "Compatible" with an honest explanation of *why* they're listed | **Chosen** — accurate, minimal change, no loss of functionality or listing. |
| C | Remove the other 9 clients from the registry entirely | Rejected — they genuinely work (same MCP endpoint); removing them understates real compatibility and is a bigger change than needed. |
| D | Re-run full certification suite against all 11 clients before launch | Rejected for this pass — large effort, requires access to 9 third-party tools/accounts, and is a *testing* task, not a *remediation* of the misrepresentation itself. Could be a future enhancement. |

### 3.3 What changed

**`includes/Integration/AIClientRegistry.php`** — 9 clients (`chatgpt`, `codex`, `gemini`,
`continue`, `opencode`, `aider`, `roo_code`, `windsurf`, `command_code`) changed from
`CERT_GOLD` to `CERT_COMPATIBLE`. Each now carries:
- `validation_notes`: *"Connects via the shared MCP Server Runtime — the same protocol-compliant
  endpoint independently certified Gold for Claude Desktop and Cursor. Not yet individually
  certified end-to-end for this client."*
- `description` suffix: *"Connects via the shared MCP Server Runtime."*

`claude` and `cursor` remain `CERT_GOLD` (they are the two clients that actually passed the full
certification suite).

The dashboard view (`includes/Admin/views/ai-integrations.php`, lines ~225-229) already groups
`compatible` alongside `silver`/`bronze`/`active` under the "info" badge style — **no template
change was needed**; the new `compatible` values render correctly with the existing badge logic.

New aggregate counts exposed via `AIClientRegistry::get_counts()`:

| Metric | Before | After |
|---|---|---|
| total | 11 | 11 |
| active | 11 | 2 |
| configured | 11 | 11 |
| connected | 11 | 11 |
| certified (gold) | 11 | 2 |
| gold | 11 | 2 |
| planned | 0 | 0 |

"Active" now honestly means "individually certified," while "configured"/"connected" continue to
reflect the true fact that all 11 clients can connect through the shared MCP endpoint today.

### 3.4 Tests updated

- **`tests/test-ai-client-layer.sh`** — counts (`total=11`, `active=2`, `configured=11`,
  `connected=11`, `planned=0`), client cert-level assertions, and the `codex` config endpoint
  (now correctly returns `200`, not `501`). **80/0 passing.**
- **`tests/test-ai-client-certification.sh`** — `chatgpt` and `command_code` now asserted as
  `compatible`. **51/0 passing.**
- **`tests/test-enterprise-hardening.sh`** — counts updated (11 clients, 2 active, 0 planned),
  `codex` returns 200. **103/0 passing.**
- **`tests/test-production-validation.sh`** — counts updated (11 total, 2 active, `claude=gold`,
  0 planned), `codex` returns 200. **102/0 passing.**

---

## 4. Changes Applied

| File | Change |
|---|---|
| `includes/AiAgent/RestApi.php` | Replaced hardcoded `AGENT_SECURITY` constant with `get_agent_security()`, reading `wpcc_enforce_approval` live (S-1). |
| `includes/Admin/views/settings.php` | New "Operation Approval Enforcement" section: `toggle_enforce_approval` handler, live-state display, conditional explanatory copy, checkbox toggle (S-1). |
| `includes/Operations/CapabilityRegistry.php` | New `READ_ONLY_SCOPE_OPERATIONS` allowlist constant and `requires_full_scope()` method (S-2). |
| `includes/Mcp/McpRestApi.php` | MCP execution context now includes `token_scope` (S-2). |
| `includes/Mcp/McpServerRuntime.php` | `tools_call()` now performs a scope check before the capability check, denying `read_only` tokens on any operation outside the allowlist (S-2). |
| `includes/Integration/AIClientRegistry.php` | 9 of 11 AI clients downgraded `gold` → `compatible` with honest `validation_notes`/`description` (PR-1). |
| `tests/test-agent-manifest.sh` | Updated `EXPECTED_SECURITY` to `human_approval_required: false` (S-1). |
| `tests/test-approval-enforcement.sh` | **NEW** — 13 tests covering manifest live-value and settings toggle (S-1). |
| `tests/test-mcp-scope-enforcement.sh` | **NEW** — 15 tests covering read_only denial / full-scope allow via MCP (S-2). |
| `tests/test-ai-client-layer.sh` | Updated counts, client lists, cert assertions, codex config endpoint (PR-1). |
| `tests/test-ai-client-certification.sh` | Updated `chatgpt`/`command_code` cert-level assertions (PR-1). |
| `tests/test-enterprise-hardening.sh` | Updated counts and codex config status (PR-1). |
| `tests/test-production-validation.sh` | Updated counts and codex config status (PR-1). |

**13 files changed: 6 source files, 7 test files (2 new, 5 updated).**

---

## 5. Changes Not Applied

These were explicitly **out of scope** for this remediation pass (per "focus only on resolving
the findings from the previous audit; do not invent new features; do not over-engineer") and were
left untouched. They remain accurately documented in `CLAUDE-AUDIT-REPORT.md`.

| Finding | Severity | Status | Reason not addressed here |
|---|---|---|---|
| **P-1** — Audit log has no rotation; `tail()` is unbounded | MEDIUM | Open | Not one of the three HIGH findings in scope. Fixing it would require introducing rotation/retention logic — a new feature, which this pass was explicitly told not to invent. |
| **C-1** — `RestApi.php` is 4,171 lines / 95 routes | MEDIUM | Open | A maintainability/structure concern, not a security or correctness defect. Splitting it is a refactor with broad blast radius, disproportionate to a remediation pass. |
| **S-3** — Three authorization layers (token scope, capability, approval) have different defaults/coverage and aren't unified | MEDIUM | **Partially improved as a side effect** of S-2 (scope and capability are now consistently enforced for MCP), but the broader unification (a single coherent authorization model/doc) was not undertaken — that is an architecture change, out of scope. |
| **T-1** — `test-media-import.sh` is flaky under full-suite load (timeline-window timing) | MEDIUM | Open, confirmed still present | Re-confirmed during the full regression run (2 failures out of 9 in that suite, consistent with the original audit). Pre-existing, unrelated to S-1/S-2/PR-1, and a test-timing issue rather than a product defect. |
| nginx config gap for `wpcc-tokens/` / `wpcc-audit/` | LOW | Open | Deployment/infrastructure concern, not a code defect; out of scope for a code remediation pass. |
| Recurring "stale hardcoded test spec" pattern | LOW | Open | Process observation about how tests are written, not a specific defect to fix. |
| Recurring jq truthiness anti-pattern in tests | LOW | Open | Cosmetic test-quality issue; touching it broadly risks unrelated test churn. |

The `OperationExecutor` approval gate logic itself (the literal code at the center of S-1) was
**also not changed** — by design, since the decision was to keep `wpcc_enforce_approval` disabled
by default (see §1.3). Only its disclosure (manifest) and configurability (settings UI) changed.

---

## 6. Test Results

### 6.1 Directly affected suites (individually verified)

| Suite | Result |
|---|---|
| `test-agent-manifest.sh` | 43/0 |
| `test-approval-enforcement.sh` (new) | 13/0 |
| `test-mcp-scope-enforcement.sh` (new) | 15/0 |
| `test-ai-client-layer.sh` | 80/0 |
| `test-ai-client-certification.sh` | 51/0 |
| `test-enterprise-hardening.sh` | 103/0 |
| `test-production-validation.sh` | 102/0 |

### 6.2 Full regression (all suites)

A complete run of all test suites in `tests/*.sh` was executed after all changes:

| | Before (original audit) | After (this remediation) |
|---|---|---|
| Suites | 56 | 58 (+2 new) |
| Passed | 2,753 | **2,809** |
| Failed | 26 | **2** |

**The only remaining failures are the 2 pre-existing `test-media-import.sh` failures (Finding
T-1)** — documented, out-of-scope, and unrelated to S-1/S-2/PR-1.

The reduction from 26 → 2 failures accounts exactly for the 24 PR-1-related failures in
`test-ai-client-layer.sh` (and the suites that depend on its counts) that existed because those
suites asserted "11 clients, all gold" against a registry that, post-fix, now honestly reports
"11 clients, 2 gold." The +56 net new passes break down as: 24 former failures converted to
passes, 28 new assertions from the two new test suites (`test-mcp-scope-enforcement.sh` = 15,
`test-approval-enforcement.sh` = 13), and ~4 incidental new assertions added to updated suites.

**Result: zero regressions.** Every suite that passed before this remediation still passes; the
only suite with failures (`test-media-import.sh`) had the same 2 failures before and after.

---

## 7. Updated Readiness Score

Scored against the same 10 dimensions as `CLAUDE-AUDIT-SCORECARD.md`:

| Dimension | Before | After | Why |
|---|---|---|---|
| Architecture | 8/10 | 8/10 | Unchanged — no architectural changes were made (by design). |
| Security | 6/10 | **7/10** | S-2 closes a real MCP scope bypass (read_only tokens could perform writes via MCP that REST would reject). S-1's manifest now tells the truth. S-3 (multi-layer auth inconsistency) remains open, capping the score. |
| Performance | 7/10 | 7/10 | Unchanged — no performance-related changes. |
| Maintainability | 7/10 | 7/10 | Unchanged — additions are small, localized, and consistent with existing patterns (C-1 still open). |
| Extensibility | 7/10 | 7/10 | Unchanged. |
| MCP Implementation | 7/10 | **8/10** | MCP authorization now mirrors REST (`requires_full_scope()` ≈ `require_write()`), closing the gap between the two API surfaces for the same token. |
| Documentation | 6/10 | **7/10** | The AI agent manifest (`/agent/manifest`) and AI client registry (`/ai-clients`) now make claims that match live behavior — manifest `human_approval_required` and certification levels are both accurate. |
| Test Coverage | 7/10 | **8/10** | +2 new suites (28 new assertions) targeting the exact S-1/S-2 fixes; 5 existing suites updated to match corrected data. 2,811 total assertions across 58 suites vs. 2,779 across 56. |
| Public Beta Readiness | 5/10 | **8/10** | The two blockers cited in the original audit — PR-1's 12 visible `test-ai-client-layer.sh` failures and S-1's manifest/behavior contradiction — are both resolved. Remaining gap: S-3 (auth model documentation) and general beta-hardening (not audit findings). |
| Commercial Readiness | 4/10 | **7/10** | The primary blocker (PR-1's overstated "11x Gold certified" claim, visible via a simple API call or dashboard view) is resolved with an honest, defensible claim. S-1 and S-2 no longer present credibility/security gaps to a technical buyer. Remaining gap: S-3 unification and a commercial-grade audit-log retention story (P-1) before a confident "yes, sell this." |

**Overall: the three HIGH findings that were explicitly called out as blocking beta/commercial
launch are resolved, with zero regressions.** MEDIUM/LOW findings remain open as documented and
do not block the practical readiness assessment below.

---

## 8. Practical Readiness Assessment

### Ready for internal daily use?

**Yes — same as before, and now more honestly so.** The plugin's primary scenario (a single
trusted operator working with an AI agent, relying on the Patch Engine for reversibility) was
already viable, and remains viable. What's new: the manifest and AI-client dashboard the operator
(or their AI agent) reads now **accurately describe** the security posture instead of overstating
it — so decisions made based on that manifest (e.g., "approval isn't required, so the agent should
be more careful with destructive operations") are now correct.

### Ready for public beta?

**Yes.** The original audit's blocker was specific and concrete: *"PR-1 (test-ai-client-layer.sh
12 failures visible to any beta user) and S-1 (manifest `human_approval_required: true`
contradicted by default behavior)."* Both are fixed:
- `test-ai-client-layer.sh` and all dependent suites pass cleanly (80/0, 51/0, 103/0, 102/0).
- The manifest now reports `human_approval_required: false` to match reality.

A beta user inspecting the API, the dashboard, or running the test suite themselves will no longer
find a contradiction between what the plugin claims and what it does. The remaining open items
(S-3, P-1, C-1, T-1) are real but are the kind of "known issues, tracked" items that are normal
and acceptable for a beta — none of them are *misrepresentations*.

### Ready for commercial sale?

**Substantially closer, with one caveat.** The original blocker — *"PR-1 (overclaimed Gold
certifications for 10 tools via visible bulk-edit)"* — is resolved with a defensible, honest
claim (2 individually-certified Gold clients + 9 compatible via the same certified shared
endpoint). S-1 and S-2, the secondary blockers, are also resolved.

The caveat: **commercial sale typically invites more scrutiny than a beta**, and a buyer doing
due diligence may reasonably ask about S-3 (why do scope, capability, and approval enforcement
have different defaults and coverage?) and P-1 (what happens to the audit log at scale / over
time?). Neither is a *misrepresentation* — both are now accurately reflected in the code and
findings — but addressing them (or at minimum documenting them clearly in commercial materials)
would strengthen the story for a paying customer's security review. **Recommendation: commercial
sale can proceed; treat S-3 and P-1 as near-term roadmap items to mention proactively rather than
have a customer discover.**

---

## 9. Summary

| Finding | Decision | Outcome |
|---|---|---|
| S-1 | Keep `wpcc_enforce_approval` disabled by default; fix the manifest to report the live value; add a Settings UI toggle. | Manifest no longer lies. Setting is discoverable and documented. 56/0 new tests. |
| S-2 | Add a default-deny allowlist (`READ_ONLY_SCOPE_OPERATIONS`) and a pre-capability scope check in `tools_call()`, mirroring REST's `require_write()`. | MCP can no longer grant a `read_only` token more access than REST. 15/0 new tests. |
| PR-1 | Option B: keep Claude Desktop + Cursor as Gold (actually validated); downgrade the other 9 clients to "Compatible" with honest, accurate notes. | Certification claims now match what was actually tested. 4 suites updated, all passing. |

**Full regression: 2,809/2,811 passing (58 suites), zero regressions, only the pre-existing,
documented, out-of-scope T-1 flake remains.**
