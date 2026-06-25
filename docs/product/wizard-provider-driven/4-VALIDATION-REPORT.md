# Validation Report

## Scope guard
| File | Change | Verified |
|---|---|---|
| `includes/Admin/views/ai-setup.php` | wizard markup + metadata-driven JS | lints; field contract preserved |
| `includes/Ai/Platform/ProviderCatalog.php` | **additive** `metadata()` / `metadata_all()` / `SEARCH_THRESHOLD` | lints; no existing line removed/changed (asserted) |
| `ConnectionController` / `ConnectionStore` / `Dialect` / `ConnectionTester` | **none** | **byte-identical to `main`** (asserted) |

No provider-execution / runtime / security / key-storage / API-contract change.

## Tests
| Suite | Result | Covers |
|---|---|---|
| **test-wizard-provider-metadata.sh** (new) | **29 / 0** (16 functional) | provider metadata, discovery seam (gated), fallback (no empty dropdown), custom-always, search, endpoint+deployment visibility, progressive enhancement |
| **test-wizard-ux-cleanup.sh** (updated) | **27 / 0** | Base URL conditional, model dropdown + custom, tags→advanced, field-name contract, execution files unchanged |
| test-ai-platform-ux-6s.sh | 44 / 0 | connections page intact |
| test-ai-platform-6r.sh | 38 / 0 | connection model/runtime seam intact |
| test-ai-activity-7.sh / test-mission-control-polish-7-5.sh | 15 / 0 · 29 / 0 | mission control intact |
| test-ai-assist.sh | 92 / 0 | AI runtime unbroken |

## Requirement coverage
discovery (gated seam) ✓ · graceful fallback / never-empty / never-fabricated ✓ · custom model always ✓ · local/gateway free text ✓ · Base URL conditional + explained ✓ · advanced (tags + real deployment) ✓ · provider metadata descriptor ✓ · future providers need no wizard change ✓ · search for large lists ✓ · progressive enhancement ✓.

## Honesty note
`supports_discovery=false` for all providers is deliberate: no browser-facing listing endpoint exists, and the brief forbids inventing one. The seam is ready; the curated/free-text fallback is the active, truthful path.
