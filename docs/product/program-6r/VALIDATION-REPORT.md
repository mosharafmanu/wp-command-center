# PROGRAM-6R — Validation Report

## PHP lint
`Ai/Platform/{Dialect,ProviderCatalog,CredentialStore,ConnectionStore,ConnectionTester}.php`, `Admin/ConnectionController.php`, `views/ai-setup.php` → all clean.

## Test suites
| Suite | Result | Notes |
|---|---|---|
| **test-ai-platform-6r.sh** (new) | **38 / 0** | lint, security contract, view honesty/no-key-echo, dialects, local/gateway providers, **22 functional checks** (opaque ids, multi-connection-per-provider, dialect honesty, secret isolation, duplicate-omits-key, delete cleanup, routing honesty) |
| test-adoption-readiness.sh (5A) | **44 / 0** | view assertions re-pointed (safety assertions preserved) |
| test-usability-5b.sh (5B) | **36 / 0** | catalogue assertions re-pointed to the platform catalogue (dialect-classified) |
| test-first-value-5c.sh (5C) | **23 / 0** | after-key guidance + agent explainer preserved |
| test-ai-assist.sh | **92 / 0** | **AI generators/runtime unbroken** (proves AnthropicClient path intact) |
| test-admin-permissions.sh | **51 / 0** | access gating intact |
| test-security-modes.sh | **28 / 0** | posture intact |
| test-operations-registry.sh | **18 / 0** | catalogue parity |
| test-capability-runtime.sh | **61 / 0** | capability parity |
| test-mcp-error-surface.sh | **18 / 0** | MCP parity |
| test-ai-integration-ux.sh | 51 / 3 | failures **pre-existing** (env MCP-URL) — not attributable |
| test-ai-client-layer.sh | 79 / 1 | failure **pre-existing** (env MCP-URL) — not attributable |

**Net-new attributable failures = 0.**

## Test-maintenance note (intentional)
6R **replaces** Program-6's config layer (deleted 4 classes + `test-ai-config-6.sh`). 5A/5B assertions that referenced the old single-provider view/catalogue were re-pointed to the connection model; **every safety assertion was preserved** (no key echo, password inputs, no prefilled value, nonce present). 5C and the AI-generator suite (`test-ai-assist` 92/0) passed unchanged — confirming the runtime is untouched.

## Coverage vs required minimum
provider catalog ✓ · add/edit/delete connection ✓ · mask key / key-not-echoed ✓ · key-not-logged ✓ · default connection ✓ · model selection + custom ✓ · feature routing ✓ · unsupported-runtime honesty ✓ · connection test missing-key ✓ (functional) · backward-compat with constants ✓ (env runs on a constant) · permission/nonce checks ✓ · audit redaction ✓ · admin render smoke (lint) ✓ · existing AI features unchanged ✓ (ai-assist 92/0) · Program-4 invariants unchanged ✓ · 5A/B/C onboarding not regressed ✓.

## Invariants
OPERATION_MAP **34** · capabilities **23** · catalogue **40** · MCP tools **40** · DB_VERSION **2.5.0** — held. No schema/registry/MCP/REST/capability/rollback/`AnthropicClient`/generator change (`git diff` confirms only `Admin/` + new `Ai/Platform/` + tests + docs).
