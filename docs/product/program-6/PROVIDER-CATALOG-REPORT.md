# PROGRAM-6 — Phase 2: Provider Catalog

`Admin\ProviderCatalog::types()` — read-only, 8 provider types, keyed by stable id.

| Type | Models | Custom model | Connection test | Runtime (WPCC can call) |
|---|---|---|---|---|
| **anthropic** | Sonnet 4.6 / Opus 4.8 / Haiku 4.5 | yes | **supported** | **supported** |
| **openai** | gpt-5 / gpt-5-mini | yes | **supported** | config_only |
| **gemini** | gemini-2.5-pro / -flash | yes | **supported** | config_only |
| openrouter | (custom) | yes | unsupported | config_only |
| azure-openai | (custom) | yes | unsupported | config_only |
| mistral | (custom) | yes | unsupported | config_only |
| perplexity | (custom) | yes | unsupported | config_only |
| xai | (custom) | yes | unsupported | config_only |

Each type defines: `label`, `description`, `key_help`, `key_prefix_hint`, `models`, `default_model`, `allow_custom_model`, `connection_test`, `runtime`.

## Honesty contract (no lying)
- `runtime = 'supported'` → WPCC's feature generators can actually use it (**Anthropic only**).
- `runtime = 'config_only'` → stored (and maybe testable), but WPCC does NOT route features through it → UI shows "STORED ONLY" + "Saved, but not used by WPCC runtime yet."
- `connection_test = 'supported'` → a real minimal test exists (Anthropic/OpenAI/Gemini).
- `connection_test = 'unsupported'` → "Test not available yet." Never a fake pass.

## Validation
- Functional (wp eval-file): catalogue has **8 types**, **no duplicate keys**, `runtime_usable('anthropic')=true`, `runtime_usable('openai')=false`, `test_supported('openai'/'gemini')=true`, `test_supported('openrouter')=false`, `is_valid_type()` accepts known / rejects unknown.
- Custom-model validation enforced in the controller (`^[A-Za-z0-9._-]+$`, ≤100).
- Unsupported providers are **not** falsely marked runtime-ready (asserted).

**Phase 2: GREEN.**
