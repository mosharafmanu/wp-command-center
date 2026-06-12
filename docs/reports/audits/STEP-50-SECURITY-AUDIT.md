# Step 50 — Security Audit

Referenced from: `STEP-50-ENTERPRISE-HARDENING.md`

## Executive Summary

WP Command Center's security architecture has been verified against 7 gates. Two critical findings were discovered and resolved. No remaining bypass paths exist.

## Gate Verification

| # | Gate | Mechanism | Default | Status |
|---|---|---|---|---|
| 1 | Token Authentication | `require_read/require_write` on every REST route; `McpRestApi::require_read` on MCP | Required | PASS |
| 2 | Capability Enforcement | `CapabilityRegistry::validate()` in `OperationExecutor` and `McpServerRuntime` | ON (`true`) | PASS — unified default fixed |
| 3 | Approval Enforcement | `OperationExecutor` lines 78-88, checks `wpcc_enforce_approval` | OFF (`false`, opt-in) | PASS — by design |
| 4 | Queue Enforcement | `OperationQueue` status transitions, `OperationWorker` transient locking | Required | PASS |
| 5 | Audit Logging | `AuditLog::record()` called in every execution path | Always | PASS |
| 6 | Rollback | Snapshot before patch apply; option-based per-manager rollback | Required | PASS |
| 7 | Secret Redaction | `Redactor` applied on all content endpoints | Always | PASS |

## Resolved Findings

### Finding 1: MCP Capability Default Mismatch (RESOLVED)
- **File:** `McpServerRuntime.php:171`
- **Issue:** `wpcc_enforce_capabilities` defaulted to `false` on MCP path vs `true` on REST path
- **Fix:** Changed default to `true`
- **Impact:** MCP clients now subject to capability enforcement by default

### Finding 2: capability_manage Unguarded (RESOLVED)
- **File:** `CapabilityRegistry.php`
- **Issue:** `capability_manage` was missing from `OPERATION_MAP`, allowing any token to assign/remove platform capabilities
- **Fix:** Added `capability.admin` constant and mapped `capability_manage` to it
- **Impact:** Capability management requires explicit capability assignment

## Accepted Design Decisions

| Finding | Rationale |
|---|---|
| Handlers public `run()` methods | PHP limitation — no `internal` keyword. Access gated by WP context. |
| Approval OFF by default | Flexibility for development. Production should enable. |
| system.admin unlimited passthrough | Assignment restricted to config only. Cannot self-assign. |
| SearchReplace no snapshot | `dry_run=true` default; live execution explicit and documented. |

## Conclusion: PASS — No critical security findings remain.
