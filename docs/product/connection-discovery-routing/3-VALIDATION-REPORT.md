# Implementation & Validation Report

## What changed (data + UI only)
| File | Change |
|---|---|
| `ConnectionTester.php` | capture real model IDs from the `/models` response it already fetches (was count-only) — returns `models_list` |
| `ConnectionController.php` | pass `models_list` to `record_test`; `model_value()` now accepts a **validated** selected model id (recommended or discovered), rejecting malformed/`..` ids → falls back to default |
| `ConnectionStore.php` | persist `models_list` on `last_test` — sanitised (`^[A-Za-z0-9._:/-]+$`, no `..`), bounded to 250, deduped, non-secret |
| `ai-setup.php` | Edit model **selector** (Recommended + Discovered(N) + Custom); routing intro explains Anthropic-only runtime; healthy-but-ineligible connections shown **disabled with a reason** + note + clearer empty state |

## Boundaries honored
- **Generation / security / runtime byte-identical to `main`:** `AnthropicClient`, `Dialect`, `CredentialStore` (asserted).
- No provider-execution, API-execution-contract, or key-storage change. OpenAI is still not runtime-executed — only its real model list is surfaced and its routing absence explained.
- No fabricated models, no faked provider support.

## Tests
| Suite | Result |
|---|---|
| **test-connection-discovery-routing.sh** (new) | **25 / 0** (7 functional: capture→persist→sanitise→select→accept; `..` rejected; routing eligibility) |
| test-wizard-ux-cleanup.sh (updated) | 26 / 0 |
| test-wizard-provider-metadata.sh | 29 / 0 |
| test-ai-platform-ux-6s.sh / -6r.sh | 44 / 0 · 38 / 0 |
| test-ai-activity-7.sh / -polish-7-5.sh | 15 / 0 · 29 / 0 |
| **test-ai-assist.sh** | **92 / 0** (AI runtime unbroken) |

## Functional evidence
- A simulated successful test with `["gpt-4o","gpt-4o-mini","models/x","../evil","gpt-4o"]` persists `gpt-4o, gpt-4o-mini, models/x` (deduped; `../evil` and `..` rejected).
- `model_value('openai')` with a discovered id `gpt-4o-mini` → accepted; with `../evil` → rejected (falls back to default `gpt-5-mini`).
- `runtime_usable`: Anthropic = true, OpenAI = false (intentional) — drives the now-explained routing.

## Result
Issue 1: discovered models are real, persisted, and selectable (recommended-first, custom preserved). Issue 2: a healthy OpenAI connection is now visibly present in routing with an explicit "not usable by the runtime yet" reason. No runtime capability changed; nothing faked.
