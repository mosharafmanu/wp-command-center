# PROGRAM-5A — Phase 8: Validation Battery

## PHP lint (all changed/new PHP)
`AdoptionStatus.php`, `AiSetupController.php`, `views/ai-setup.php`, `AppShell.php`, `views/command-home.php`, `views/settings.php` → **all "No syntax errors detected."**

## Test suites run
| Suite | Result | Notes |
|---|---|---|
| **test-adoption-readiness.sh** (new, this program) | **44 / 0** | security contract, no-key-echo, nav wiring, first-run, security-mode UX, invariants |
| test-security-modes.sh | **28 / 0** | live `wp eval` mode switching + fallback intact |
| test-security-mode-validation.sh | **27 / 0** | mode consistency intact |
| test-admin-permissions.sh | **51 / 0** | admin access gating intact |
| test-operations-registry.sh | **18 / 0** | catalogue parity |
| test-capability-runtime.sh | **61 / 0** | capability parity |
| test-mcp-error-surface.sh | **18 / 0** | MCP parity |
| test-admin-ux.sh | 22 / 1 | the **1** failure is **pre-existing on `main`** (identical) — not attributable |
| test-ai-integration-ux.sh | 51 / 3 | the **3** failures are **pre-existing on `main`** (identical, env config: npx/site-URL) — not attributable |

### Pre-existing-failure proof
Stashed branch changes, checked out `main`, re-ran `test-admin-ux.sh` (22/1) and `test-ai-integration-ux.sh` (51/3) → **identical failures and counts**. Therefore **net-new attributable failures = 0**.

## Invariants (re-verified)
| Invariant | Required | Measured |
|---|---|---|
| OPERATION_MAP | 34 | **34** |
| capabilities (ALL_CAPABILITIES) | 23 | **23** |
| catalogue (operations) | 40 | **40** |
| MCP tools | 40 | **40** (1:1, derived; `Mcp/` untouched) |
| DB_VERSION | 2.5.0 | **2.5.0** |

`CapabilityRegistry`, `OperationRegistry`, `Schema`, and `Mcp/` were **not modified** on this branch (`git diff --name-only main...HEAD` confirms).

## Changed-file inventory (code)
- Modified: `includes/Admin/AppShell.php`, `includes/Admin/views/command-home.php`, `includes/Admin/views/settings.php`.
- New: `includes/Admin/AdoptionStatus.php`, `includes/Admin/AiSetupController.php`, `includes/Admin/views/ai-setup.php`, `tests/test-adoption-readiness.sh`.
- New docs: `docs/product/program-5a/*`.

## Verdict
All attributable checks green; invariants held; **net-new attributable failures = 0.** Phase 8 GREEN.
