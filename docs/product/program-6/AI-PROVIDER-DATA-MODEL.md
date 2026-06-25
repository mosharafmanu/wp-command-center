# PROGRAM-6 — Phase 1: AI Provider Data Model

## Storage (WordPress options only — no schema, no DB_VERSION bump)
| Option | Autoload | Holds | Secrets? |
|---|---|---|---|
| `wpcc_ai_providers` | **no** | `type => { name, model, enabled, last_test:{ok,code,time} }` (one record per provider type) | **No** |
| `wpcc_ai_provider_secrets` | **no** | `type => key` for **non-Anthropic** providers (used only by the tester) | Yes (masked, never echoed) |
| `wpcc_anthropic_api_key` (existing) | **no** | the **Anthropic** key — unchanged legacy option `AnthropicClient` already reads | Yes |
| `wpcc_anthropic_model` (existing) | **no** | the Anthropic model — mirrored from the record | No |
| `wpcc_ai_default_provider` | **no** | the default provider `type` (runtime-usable only) | No |
| `wpcc_ai_feature_map` | **no** | `feature => type` (runtime-usable only) | No |

**One record per provider type** (id == type, e.g. `anthropic`, `openai`). This gives "multiple providers" (Anthropic + OpenAI + Gemini …) without secret-collision risk and matches the real mental model ("configure my OpenAI"). Multiple keys per type (environments) is a documented future extension.

## Field coverage (per the requirements)
multiple records ✓ · stable ids (=type) ✓ · provider type ✓ · API key secret ✓ (separate, masked) · masked display ✓ (boolean "Key configured", never chars) · default model ✓ (record.model) · status ✓ (has_secret + last_test + runtime label) · last tested time ✓ (last_test.time) · last error ✓ (last_test.code) · enabled/disabled ✓ · default flag ✓ (resolved type) · feature mapping ✓.

## Backward compatibility (the spine)
- **Anthropic key/model stay in the existing options** (`wpcc_anthropic_api_key`/`wpcc_anthropic_model`); the constant `WPCC_ANTHROPIC_API_KEY` still wins. `AnthropicClient` and every generator are **unmodified** → production behavior preserved.
- `ProviderStore::set_secret('anthropic', …)` writes that legacy option; `set_secret(other, …)` writes the new secrets map. The Anthropic key is **never duplicated**.
- A pre-6 install with only the legacy Anthropic key (or constant) **automatically shows an implicit Anthropic provider** (records() synthesizes it when `has_secret('anthropic')`), so nothing is "lost" by the new UI.

## Secret safety
- All secret-bearing options are **autoload=no** (`update_option(…, false)`).
- Secrets are **never** placed in `wpcc_ai_providers` (verified by test: "no secret stored in records option").
- The UI shows only a boolean configured state; the Anthropic key is never even extracted (the tester calls `AnthropicClient` which reads its own key).

**No DB schema, no DB_VERSION, no destructive migration.** Phase 1 complete.
