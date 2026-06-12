# WP Command Center - Codex Targeted Release Audit

**Audit date:** June 12, 2026  
**Scope:** Second-opinion verification of Claude remediation plus release-blocking security and safety paths.

## Executive Verdict

- **Ready for internal use:** Yes.
- **Ready for public beta:** Yes, with deployment documentation restricting or configuring nginx access to WPCC upload storage.
- **Ready for commercial sale:** No, not yet.

The three HIGH remediation claims are verified. The final regression run passed **58/58 suites and 2,811/2,811 assertions**. The previous media-import failures were test-window flakiness, not a product defect.

Commercial release remains blocked by two known operational-security gaps: server-agnostic protection for audit/token storage and an audit-log retention/rotation strategy. These were not changed because they require deployment/product decisions beyond this targeted pass.

## Remediation Verification

### S-1 - Approval behavior and disclosure

**Verified.** Approval enforcement remains opt-in by design. The manifest reads the live `wpcc_enforce_approval` option, the Settings page exposes the toggle with nonce protection and accurate ON/OFF wording, and current security documentation states the default is disabled.

Evidence:

- `tests/test-approval-enforcement.sh`: **13/13**.
- Direct REST and MCP calls to approval-marked operations are blocked when enforcement is ON.
- Request -> approve -> execute remains functional when enforcement is ON.
- `/agent/manifest.security.human_approval_required` tracks the live setting.

### S-2 - MCP token-scope bypass

**Verified fixed.** MCP passes validated token scope into its execution context. `tools/call` denies read-only tokens for every operation except the explicit read-only allowlist (`database_inspect`, `search_manage`) before capability dispatch. This covers previously unmapped seed operations and fails closed for future operations.

Evidence:

- `tests/test-mcp-scope-enforcement.sh`: **15/15**.
- Read-only MCP token denied for all four seed operations.
- Read-only token allowed for the two read-only operations.
- Full token succeeds and REST/MCP behavior is symmetric.

### PR-1 - AI client certification claims

**Verified after additional documentation correction.** The registry reports Claude Desktop and Cursor as Gold and the other nine clients as Compatible, explicitly noting that those nine are not individually certified end-to-end. Current docs now match the live registry. The historical Step 55 handoff claim is marked superseded.

Evidence:

- `tests/test-ai-client-layer.sh`: **80/80**.
- `tests/test-ai-client-certification.sh`: **51/51**.
- `tests/test-documentation-consistency.sh`: **84/84**.
- Live counts: 11 total, 2 Gold/active, 9 Compatible, 0 planned.

## Media Import Failure Investigation

The original two failures checked for `Media import started/completed` inside only the newest 30 entries of a global timeline. Under the full sequential suite, queue and audit events with second-resolution timestamps can displace those entries or be ordered unstably among timestamp ties.

No product defect was found:

- The attachment was created and its title, parent, and alt text were correct.
- `OperationExecutor` unconditionally records both media-import lifecycle events.
- `TimelineBuilder` maps both events correctly.
- The suite passed immediately in isolation.
- After widening only the test query window, it passed inside the full 58-suite run.

## Release-Blocking Area Audit

### Capability enforcement

Pass. Capability enforcement defaults ON, privileged capability management is mapped, and dedicated capability, enterprise-hardening, and final-validation suites pass.

### Approval enforcement

Pass with disclosed opt-in semantics. Enforcement works when enabled; the manifest and admin UI no longer imply it is always active.

### MCP tool scope and token behavior

Pass. Read-only tokens cannot reach mutating tools through MCP. Full-token and REST behavior remains intact.

### Rollback safety

Pass. Patch apply requires approval, snapshots precede writes, restored files are re-verified, and failure does not falsely report rollback success. Patch lifecycle: **116/116**; snapshot runtime: **58/58**.

### Audit and timeline integrity

Pass for correctness. Lifecycle events are emitted and normalized correctly. Remaining operational risk: one append-only log has no rotation and timeline reads scan the file.

### Admin settings security

Pass. Admin pages are `manage_options` gated; mutation forms use nonces; inputs are sanitized and displayed values are escaped. The approval toggle accurately explains its effect and default.

## Remaining Blockers

1. **Commercial blocker:** WPCC token and audit directories rely on `.htaccess`. nginx ignores it; production nginx deployments require explicit deny rules or storage outside the public uploads tree.
2. **Commercial blocker:** Audit logging has no retention/rotation and `tail()` loads the whole file. Long-lived paid deployments need bounded storage and predictable timeline performance.
3. **Public-beta condition:** State supported web-server requirements clearly until server-agnostic storage protection is implemented.

No remaining blocker was found for controlled internal use.
