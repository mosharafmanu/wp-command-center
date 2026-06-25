# PROGRAM-6 — Phase 9: Validation

## PHP lint
`ProviderCatalog.php`, `ProviderStore.php`, `ProviderConnectionTester.php`, `ProviderConfigController.php`, `views/ai-setup.php` → all clean.

## Test suites
| Suite | Result | Notes |
|---|---|---|
| **test-ai-config-6.sh** (new) | **28 / 0** | lint, security contract, view honesty/no-key-echo, tester, **19 functional checks** via wp eval-file (catalogue, add/edit/delete, default+feature honesty, back-compat, no-secret-in-records) |
| test-adoption-readiness.sh (5A) | **44 / 0** | view assertions re-pointed to the rebuilt multi-provider view (safety assertions preserved) |
| test-usability-5b.sh (5B) | **36 / 0** | catalogue + model-explainer assertions re-pointed to `types()` + the new explainer |
| test-first-value-5c.sh (5C) | **23 / 0** | after-key guidance + agent explainer preserved |
| test-admin-permissions.sh | **51 / 0** | access gating intact |
| test-security-modes.sh | **28 / 0** | posture intact |
| test-operations-registry.sh | **18 / 0** | catalogue parity |
| test-capability-runtime.sh | **61 / 0** | capability parity |
| test-mcp-error-surface.sh | **18 / 0** | MCP parity |
| test-ai-integration-ux.sh | 51 / 3 | failures **pre-existing on main** (env MCP-URL) — not attributable |
| test-ai-client-layer.sh | 79 / 1 | failure **pre-existing on 5B** (env MCP-URL) — not attributable |

**Net-new attributable failures = 0.**

## Test-maintenance note (intentional, not regression)
The rebuilt multi-provider AI Setup view legitimately invalidated a few 5A/5B structural assertions written against the old single-key screen (e.g. "OpenAI/Gemini shown as PLANNED" — now they are genuinely configurable, which is the point of Program-6). Those specific assertions were re-pointed to the new honest reality; **every safety assertion was preserved** (no key echo, password inputs, no prefilled value, nonce present). 5C passed unchanged.

## Coverage vs the required minimum
provider catalog ✓ · add/edit/delete provider ✓ · mask key / key-not-echoed ✓ · key-not-logged ✓ · default provider ✓ · model selection + custom validation ✓ · feature mapping ✓ · unsupported-provider honesty ✓ · connection-test missing-key ✓ (functional) · permission/nonce checks ✓ · audit redaction ✓ · admin render smoke (lint) ✓ · backward compatibility with constants ✓ (live env runs on a constant) · existing AI features unchanged ✓ (AnthropicClient untouched) · Program-4 invariants unchanged ✓ · 5A/B/C onboarding not regressed ✓.

## Invariants
OPERATION_MAP **34** · capabilities **23** · catalogue **40** · MCP tools **40** · DB_VERSION **2.5.0** — all held. No schema/registry/MCP/REST/capability/rollback/`AnthropicClient` change (`git diff` confirms).

**Phase 9: GREEN.**
